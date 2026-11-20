#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ایستگاه چاپ فروشگاه
=================

این برنامه روی کامپیوتری اجرا می‌شود که پرینترها به آن وصل‌اند (دفتر یا انبار).
هر چند ثانیه از سایت می‌پرسد کار چاپی هست یا نه، و اگر بود صفحه را می‌گیرد،
به PDF تبدیل می‌کند و به پرینتر می‌فرستد.

چرا این‌طوری؟ چون سایت روی اینترنت است و پرینترها پشت مودم دفتر. اگر می‌خواستیم
سایت مستقیم به پرینتر وصل شود، باید روی مودم پورت باز می‌کردیم که هم دردسر است
و هم ناامن. این‌طوری هیچ پورتی باز نمی‌شود.

نصب روی ویندوز (بی‌پایتون، بی‌هیچ کارِ دستی):
    از «پنل ← ایستگاه‌های چاپ» بستهٔ ZIP همان ایستگاه را بگیرید، باز کنید و روی
    WebyPrint.exe دوبار کلیک کنید (نشانی سایت و توکن داخل بسته است). برنامه خودش را در
    C:\\ProgramData\\WebyPrint نصب می‌کند، با ورود به ویندوز خودش اجرا می‌شود و
    صفحهٔ تنظیمات را باز می‌کند. WebyPrint.exe را GitHub Actions از همین پرونده می‌سازد.
    فرمان‌ها:  WebyPrint.exe install | uninstall | run | selftest --out نتیجه.json

اجرا با پایتون (لینوکس، مک یا ویندوز):
    pip install -r requirements.txt
    python agent.py

بعد در مرورگر همان کامپیوتر (یا هر کامپیوتری در همان شبکه) باز کنید:
    http://IP-این-کامپیوتر:8765
"""

import json
import os
import platform
import base64
import re
import uuid
import shutil
import subprocess
import sys
import tempfile
import threading
import time
from datetime import datetime
from urllib.parse import urljoin, urlparse

try:
    import requests
except ImportError:
    sys.exit("کتابخانهٔ requests نصب نیست. اجرا کنید:  pip install -r requirements.txt")

from flask import Flask, jsonify, request, render_template_string

VERSION = "2.1"
# WebyPrint.exe (PyInstaller): داده‌ها کنارِ خودِ exe (پوشهٔ نصب)، ui.html داخلِ exe
FROZEN = bool(getattr(sys, "frozen", False))
HERE = os.path.dirname(os.path.abspath(sys.executable if FROZEN else __file__))
RES = getattr(sys, "_MEIPASS", HERE)
INSTALL_DIR = os.path.join(os.environ.get("PROGRAMDATA", r"C:\ProgramData"), "WebyPrint")
TASK_NAME = "Weby Print Station"
EXE_NAME = "WebyPrint.exe"
# پورتِ صفحهٔ تنظیمات همان ۸۷۶۵ ِ همیشه است (ایستگاهِ Invora روی ۸۷۶۶ است؛ هر دو کنارِ هم کار می‌کنند)
DEFAULT_PORT = 8765
CONFIG_PATH = os.path.join(HERE, "config.json")
LOG_MAX = 200

IS_WINDOWS = platform.system() == "Windows"

# ---------------------------------------------------------------- تنظیمات

DEFAULT_CONFIG = {
    "sites": [],               # چند سایت Weby/Invora با یک ایستگاه: [{id, name, api_url, token, enabled, printer}]
    "api_url": "",             # (قدیمی: اگر sites خالی باشد، با توکن به‌عنوان نخستین سایت منتقل می‌شود)
    "token": "",
    "station_name": "",
    "poll_seconds": 5,
    "web_port": DEFAULT_PORT,
    "engine": "auto",          # auto | chrome | playwright | weasyprint
    "chrome_path": "",         # اگر خالی باشد Edge/Chrome خودکار پیدا می‌شود
    "print_mode": "auto",      # auto | direct (بدون برنامهٔ جانبی) | pdf (با SumatraPDF)
    "render_dpi": 300,         # تراکم نقطهٔ عکس در مسیر چاپ مستقیم
    "printers": [],            # پر می‌شود از روی پرینترهای واقعی سیستم
}


def load_config():
    cfg = dict(DEFAULT_CONFIG)
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, encoding="utf-8") as f:
                cfg.update(json.load(f))
        except Exception as e:
            print("خواندن config.json ناموفق بود:", e)
    if not isinstance(cfg.get("printers"), list):
        cfg["printers"] = []
    return normalize_sites(cfg)


def normalize_sites(cfg):
    """کلیدهای قدیمیِ api_url/token را به فهرست sites می‌برد و به هر سایت شناسه می‌دهد."""
    sites = cfg.get("sites") if isinstance(cfg.get("sites"), list) else []
    url, tok = str(cfg.get("api_url") or "").strip(), str(cfg.get("token") or "").strip()
    if not sites and tok:
        sites = [{"name": "", "api_url": url, "token": tok, "enabled": True}]
    out, seen = [], set()
    for x in sites:
        if not isinstance(x, dict):
            continue
        u, t = str(x.get("api_url") or "").strip(), str(x.get("token") or "").strip()
        if not u and not t:
            continue
        sid = str(x.get("id") or "") or uuid.uuid4().hex[:8]
        if sid in seen:
            sid = uuid.uuid4().hex[:8]
        seen.add(sid)
        out.append({"id": sid, "name": str(x.get("name") or "").strip(), "api_url": u, "token": t,
                    "enabled": x.get("enabled", True) is not False, "printer": str(x.get("printer") or "")})
    cfg["sites"] = out
    cfg["api_url"] = cfg["token"] = ""
    return cfg


def add_site(cfg, url, token, name="", printer=""):
    """سایتِ تازه را اضافه می‌کند؛ اگر همان نشانی یا همان توکن از قبل هست، همان را به‌روز می‌کند."""
    url, token = str(url or "").strip(), str(token or "").strip()
    if not url or not token:
        return False
    for x in cfg["sites"]:
        if x["token"] == token or x["api_url"].rstrip("/") == url.rstrip("/"):
            x.update(api_url=url, token=token, enabled=True)
            if name:
                x["name"] = name
            return True
    cfg["sites"].append({"id": uuid.uuid4().hex[:8], "name": str(name or "").strip(), "api_url": url, "token": token,
                         "enabled": True, "printer": printer})
    return True


def site_label(site):
    if site.get("name"):
        return site["name"]
    host = urlparse(site.get("api_url") or "").netloc
    return host or site.get("id") or "سایت"


def save_config(cfg):
    tmp = CONFIG_PATH + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)
    os.replace(tmp, CONFIG_PATH)


CONFIG = load_config()
STATE = {
    "online": False,
    "last_error": "",
    "last_poll": None,
    "jobs_done": 0,
    "jobs_failed": 0,
    "log": [],
    "busy": False,
    "sites": {},
}
LOCK = threading.Lock()
PRINT_LOCK = threading.Lock()   # چند سایت هم‌زمان کار می‌فرستند ولی چاپگرها یکی‌یکی چاپ می‌کنند


def site_state(site):
    with LOCK:
        return STATE["sites"].setdefault(site["id"], {"online": False, "last_error": "", "last_poll": None, "station": "",
                                                       "jobs_done": 0, "jobs_failed": 0, "busy": False})


def _count(site, key):
    with LOCK:
        STATE[key] += 1
        if site:
            site_state_nolock(site)[key] += 1


def site_state_nolock(site):
    return STATE["sites"].setdefault(site["id"], {"online": False, "last_error": "", "last_poll": None, "station": "",
                                                   "jobs_done": 0, "jobs_failed": 0, "busy": False})


def log(msg, kind="info"):
    line = {
        "t": datetime.now().strftime("%H:%M:%S"),
        "kind": kind,
        "msg": str(msg),
    }
    with LOCK:
        STATE["log"].insert(0, line)
        del STATE["log"][LOG_MAX:]
    print(f"[{line['t']}] {msg}")


# ---------------------------------------------------------- پرینترهای سیستم

def system_printers():
    """فهرست پرینترهای نصب‌شده روی این کامپیوتر."""
    names = []
    if IS_WINDOWS:
        try:
            import win32print
            flags = win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
            names = [p[2] for p in win32print.EnumPrinters(flags)]
        except Exception as e:
            log(f"خواندن پرینترهای ویندوز ناموفق بود: {e}", "error")
    else:
        try:
            out = subprocess.run(["lpstat", "-a"], capture_output=True, text=True, timeout=10)
            names = [ln.split()[0] for ln in out.stdout.splitlines() if ln.strip()]
        except Exception as e:
            log(f"خواندن پرینترهای CUPS ناموفق بود: {e}", "error")
    return names


# هر پرینتر می‌تواند چند «کاغذ» (چند مجموعه تنظیم) داشته باشد. این‌ها
# فیلدهای هر کاغذ‌اند؛ بقیه (نام، فعال بودن، نوع…) مال خود پرینتر است.
PAPER_KEYS = ("paper", "paper_w", "paper_h", "tolerance", "rotate", "fit",
              "driver", "tspl_dpi", "gap_mm", "density", "speed")


def _num(v, default=0.0):
    try:
        return float(v)
    except (TypeError, ValueError):
        return float(default)


def normalize_paper(p, idx=0):
    """یک کاغذ را پاک‌سازی می‌کند (برای ذخیره و برای خواندن)."""
    gap = p.get("gap_mm")
    return {
        "id": str(p.get("id") or "").strip() or f"p{idx + 1}",
        "paper": str(p.get("paper", "")).strip(),
        "paper_w": _num(p.get("paper_w")),
        "paper_h": _num(p.get("paper_h")),
        "tolerance": _num(p.get("tolerance"), 3) or 3.0,
        # چرخش تصویر پیش از چاپ: 0 / 90 / 180 / 270 درجه.
        # بعضی پرینترهای حرارتی برچسب را ۹۰ درجه چرخیده می‌کشند.
        "rotate": int(_num(p.get("rotate"))) % 360,
        # نحوهٔ جا دادن تصویر در کاغذ: exact (اندازهٔ دقیق) یا fit (کوچک‌کردن تا جا شود)
        "fit": "fit" if p.get("fit") == "fit" else "exact",
        # gdi: از راه درایور ویندوز (با DEVMODE اجباری)
        # tspl: زبان خام پرینتر حرارتی — برای ZEC ZP400 و سازگارهای TSC
        "driver": "tspl" if p.get("driver") == "tspl" else "gdi",
        "tspl_dpi": int(_num(p.get("tspl_dpi"), 203)) or 203,
        "gap_mm": _num(gap if gap not in (None, "") else 3, 3),
        "density": min(15, max(0, int(_num(p.get("density"), 8)))),
        "speed": min(12, max(1, int(_num(p.get("speed"), 4)))),
    }


def papers_of(saved):
    """
    فهرست کاغذهای یک پرینتر ذخیره‌شده. پرینتری که با نسخهٔ قبلی ذخیره شده
    (یک کاغذ، فیلدهای تخت) خودکار یک کاغذ می‌شود؛ هیچ تنظیمی از دست نمی‌رود.
    """
    rows = saved.get("papers")
    if not isinstance(rows, list) or not rows:
        rows = [saved] if any(saved.get(k) for k in PAPER_KEYS) else [{}]
    out, seen = [], set()
    for i, r in enumerate(rows):
        n = normalize_paper(r, i)
        while n["id"] in seen:
            n["id"] += "x"
        seen.add(n["id"])
        out.append(n)
    return out


def default_paper_of(papers, wanted):
    for pp in papers:
        if pp["id"] == wanted:
            return pp
    return papers[0]


def _build_printer(name, p, connected):
    papers = papers_of(p)
    dp = default_paper_of(papers, p.get("default_paper"))
    q = {
        "name": name,
        "label": p.get("label") or name,
        # پرینتری که تیکش برداشته شود اصلاً برای چاپ استفاده نمی‌شود.
        # پیش‌فرض برای پرینتر تازه: خاموش، تا ناخواسته چیزی چاپ نشود.
        "enabled": bool(p.get("enabled", False)),
        "kind": "thermal" if p.get("kind") == "thermal" else "paper",
        "default": bool(p.get("default")),
        "connected": connected,
        # false: هر لحظه فقط یک کاغذ (همان پیش‌فرض) داخل پرینتر است.
        # true: همهٔ کاغذهای فهرست هم‌زمان در دسترس‌اند و هر کار با اندازه‌اش
        #       کاغذ مناسب را برمی‌دارد (پرینتر چندسینی).
        "multi_paper": bool(p.get("multi_paper")),
        "default_paper": dp["id"],
        "papers": papers,
    }
    # فیلدهای تختِ کاغذ پیش‌فرض — بقیهٔ برنامه و گزارش به سایت همین‌ها را می‌خوانند
    for k in PAPER_KEYS:
        q[k] = dp[k]
    return q


def merged_printers():
    """
    پرینترهای واقعی سیستم را با تنظیمات ذخیره‌شده (کاغذها) ترکیب می‌کند.
    اگر پرینتری از سیستم حذف شده باشد، در فهرست می‌ماند ولی «متصل نیست» می‌شود.
    """
    live = system_printers()
    saved = {p["name"]: p for p in CONFIG.get("printers", [])}
    out = [_build_printer(name, saved.get(name, {}), True) for name in live]

    for name, p in saved.items():
        if name not in live:
            out.append(_build_printer(name, p, False))

    # پیش‌فرض باید بین پرینترهای فعال باشد
    usable = [p for p in out if p.get("enabled") and p.get("connected")]
    if usable and not any(p.get("default") for p in usable):
        usable[0]["default"] = True
    return out


def with_paper(printer, paper):
    """نسخه‌ای از پرینتر که فیلدهای تختش از همین کاغذ آمده است."""
    q = dict(printer)
    for k in PAPER_KEYS:
        q[k] = paper[k]
    q["paper_id"] = paper["id"]
    return q


def resolve_paper(printer, w, h):
    """
    کاغذ مناسب این کار را روی این پرینتر پیدا می‌کند.
    @return (پرینتر با تنظیم همان کاغذ، پیام خطا)
    """
    papers = printer.get("papers") or []
    dp = default_paper_of(papers, printer.get("default_paper")) if papers else None
    if not dp:
        return printer, ""
    if not printer.get("multi_paper"):
        q = with_paper(printer, dp)
        ok, why = paper_matches(q, w, h)
        return (q, "") if ok else (None, why)

    # چندکاغذی: اول پیش‌فرض، بعد بقیه به ترتیب فهرست
    order = [dp] + [x for x in papers if x["id"] != dp["id"]]
    if not w or not h:
        return with_paper(printer, dp), ""
    for pp in order:
        if pp["paper_w"] > 0 and pp["paper_h"] > 0 and paper_matches(with_paper(printer, pp), w, h)[0]:
            return with_paper(printer, pp), ""
    if dp["paper_w"] <= 0 or dp["paper_h"] <= 0:
        return with_paper(printer, dp), ""       # کاغذ پیش‌فرض اندازه ندارد؛ جلویش را نمی‌گیریم
    have = "، ".join("{} ({:g}×{:g})".format(x["paper"] or "بدون نام", x["paper_w"], x["paper_h"])
                     for x in papers if x["paper_w"] > 0 and x["paper_h"] > 0)
    return None, (f"کاغذ لازم {w:g}×{h:g} میلی‌متر است و بین کاغذهای تعریف‌شده برای "
                  f"پرینتر «{printer.get('label')}» کاغذی که بخواند نیست: {have}.")


def pick_printer(job_printer):
    """
    فقط پرینترهایی که در تنظیمات «فعال» شده‌اند قابل استفاده‌اند.
    این‌طور اگر روی این کامپیوتر ده تا پرینتر نصب باشد، فقط همان یکی دو
    تایی که خودتان انتخاب کرده‌اید کار می‌کنند و چیزی اشتباهی چاپ نمی‌شود.
    """
    all_p    = merged_printers()
    online   = [p for p in all_p if p.get("connected")]
    printers = [p for p in online if p.get("enabled")]

    if not online:
        return None, "هیچ پرینتری به این کامپیوتر وصل نیست."
    if not printers:
        return None, ("هیچ پرینتری فعال نشده است. در تنظیمات ایستگاه چاپ، "
                      "تیک پرینترهایی را که می‌خواهید استفاده شوند بزنید.")

    if job_printer:
        for p in printers:
            if p["name"] == job_printer or p.get("label") == job_printer:
                return p, ""
        # روی سیستم هست ولی تیکش زده نشده
        for p in online:
            if p["name"] == job_printer or p.get("label") == job_printer:
                return None, (f"پرینتر «{job_printer}» روی این کامپیوتر هست ولی فعال نشده. "
                              f"در تنظیمات ایستگاه چاپ تیک «استفاده شود» را برایش بزنید.")
        return None, f"پرینتر «{job_printer}» روی این کامپیوتر پیدا نشد."

    for p in printers:
        if p.get("default"):
            return p, ""
    return printers[0], ""


def paper_matches(printer, w, h):
    """آیا کاغذ داخل پرینتر با کاغذ لازمِ این کار می‌خواند؟"""
    if not w or not h:
        return True, ""
    pw, ph = float(printer.get("paper_w") or 0), float(printer.get("paper_h") or 0)
    if pw <= 0 or ph <= 0:
        return True, ""      # کاغذ تعریف نشده؛ جلوی چاپ را نمی‌گیریم
    tol = float(printer.get("tolerance") or 3)
    ok = (abs(pw - w) <= tol and abs(ph - h) <= tol) or \
         (abs(pw - h) <= tol and abs(ph - w) <= tol)
    if ok:
        return True, ""
    return False, (
        f"کاغذ لازم {w:g}×{h:g} میلی‌متر است، اما در پرینتر «{printer.get('label')}» "
        f"کاغذ {pw:g}×{ph:g} میلی‌متر ({printer.get('paper') or 'بدون نام'}) قرار دارد."
    )


# ------------------------------------------------------ آماده‌سازی صفحه

_FONT_URL_RE = re.compile(r"url\(\s*(['\"]?)([^)'\"]+?)\1\s*\)", re.I)
_FONT_EXT = {"woff2": "font/woff2", "woff": "font/woff",
             "ttf": "font/ttf", "otf": "font/otf"}
_LINK_RE = re.compile(r"<link\b[^>]*>", re.I)
_FACE_RE = re.compile(r"@font-face\s*\{[^}]*\}", re.I)


def _font_data_uri(font_url, cache):
    """فایل فونت را می‌گیرد و به‌صورت data: برمی‌گرداند (ناموفق ← None)."""
    if font_url in cache:
        return cache[font_url]
    uri = None
    path = urlparse(font_url).path.lower()
    ext = path.rsplit(".", 1)[-1] if "." in path else ""
    if ext in _FONT_EXT:
        try:
            r = requests.get(font_url, timeout=30)
            r.raise_for_status()
            uri = "data:%s;base64,%s" % (_FONT_EXT[ext],
                                        base64.b64encode(r.content).decode("ascii"))
        except Exception as e:
            log(f"فونت گرفته نشد ({font_url}): {e}", "warn")
    cache[font_url] = uri
    return uri


def _inline_css_fonts(css, base_url, cache):
    """داخل هر @font-face، آدرس فایل فونت را با خودِ فایل جایگزین می‌کند."""
    def face(m):
        def one(u):
            raw = u.group(2).strip()
            if raw.startswith("data:"):
                return u.group(0)
            uri = _font_data_uri(urljoin(base_url, raw), cache)
            return "url('%s')" % uri if uri else u.group(0)
        return _FONT_URL_RE.sub(one, m.group(0))
    return _FACE_RE.sub(face, css)


def embed_fonts(html, base_url):
    """
    چرا فونت‌ها را داخل صفحه می‌گذاریم؟ صفحه از یک فایل file:// چاپ می‌شود و
    مرورگر فونتِ آدرس اینترنتی را (بدون هدر CORS، یا وقتی اینترنت کند است)
    نمی‌گیرد و متن با Tahoma چاپ می‌شود. با data: دیگر به شبکه وابسته نیست.
    """
    cache = {}

    def link(m):
        tag = m.group(0)
        if not re.search(r"rel=['\"]?stylesheet", tag, re.I):
            return tag
        h = re.search(r"href=['\"]([^'\"]+)['\"]", tag, re.I)
        if not h:
            return tag
        css_url = urljoin(base_url, h.group(1))
        try:
            r = requests.get(css_url, timeout=30)
            r.raise_for_status()
            if "@font-face" not in r.text:
                return tag
            return "<style>%s</style>" % _inline_css_fonts(r.text, css_url, cache)
        except Exception as e:
            log(f"شیوه‌نامه گرفته نشد ({css_url}): {e}", "warn")
            return tag

    html = _LINK_RE.sub(link, html)
    return re.sub(r"(<style\b[^>]*>)(.*?)(</style>)",
                  lambda m: m.group(1) + _inline_css_fonts(m.group(2), base_url, cache) + m.group(3),
                  html, flags=re.I | re.S)


def prepare_page(url, style):
    """صفحه را می‌گیرد، فونت‌ها را جاسازی و استایل/base را تزریق می‌کند."""
    r = requests.get(url, timeout=30)
    r.raise_for_status()
    html = r.text
    try:
        html = embed_fonts(html, url)
    except Exception as e:
        log(f"جاسازی فونت ناموفق بود ({e})؛ فونت از شبکه گرفته می‌شود.", "warn")
    inject = f'<base href="{url}">' + style
    if "</head>" in html:
        return html.replace("</head>", inject + "</head>", 1)
    return inject + html


# ------------------------------------------------------------ ساخت PDF

def fetch_pdf(url, pdf_path):
    """اگر نشانی خودش PDF برگرداند، همان را ذخیره می‌کند و True می‌دهد."""
    try:
        with requests.get(url, timeout=60, stream=True) as r:
            if r.status_code != 200:
                return False
            ctype = (r.headers.get("Content-Type") or "").lower()
            chunks = r.iter_content(65536)
            first = next(chunks, b"")
            if "application/pdf" not in ctype and not first.startswith(b"%PDF"):
                return False
            with open(pdf_path, "wb") as f:
                f.write(first)
                for c in chunks:
                    f.write(c)
        return os.path.getsize(pdf_path) > 100
    except Exception as e:
        log(f"پرسیدنِ نوعِ سند نشد ({e})؛ مثل صفحهٔ معمولی چاپ می‌شود.", "warn")
        return False


def html_to_pdf(url, pdf_path, w_mm, h_mm):
    """
    صفحهٔ چاپی سایت را به PDF تبدیل می‌کند.

    سه موتور، به همین ترتیب امتحان می‌شوند:

      chrome     — مرورگر Edge یا Chrome که از قبل روی ویندوز هست.
                   هیچ نصب اضافه‌ای لازم ندارد و خروجی‌اش دقیقاً همان چیزی
                   است که در مرورگر می‌بینید. برای فارسی بهترین نتیجه.
      playwright — اگر نصب و «playwright install chromium» اجرا شده باشد.
      weasyprint — سبک‌تر ولی روی ویندوز نصبش سخت است (GTK لازم دارد).
    """
    # خودِ نشانی PDF است؟ (برگهٔ پستِ پنلِ ای‌بازار) — همان فایل، بی‌مرورگر.
    # کروم PDF را «چاپ به PDF» نمی‌کند (صفحهٔ سفید می‌دهد)، پس پیش از هر
    # موتوری یک بار پرسیده می‌شود. برای صفحه‌های HTML فقط چند بایتِ اول.
    if fetch_pdf(url, pdf_path):
        return True, "PDF آماده"

    engine = CONFIG.get("engine", "auto")
    errors = []

    if engine in ("auto", "chrome"):
        exe = find_chrome()
        if exe:
            try:
                chrome_to_pdf(exe, url, pdf_path, w_mm, h_mm)
                return True, "chrome"
            except Exception as e:
                errors.append(f"chrome: {e}")
                if engine == "chrome":
                    raise
        else:
            errors.append("chrome: مرورگر Edge یا Chrome پیدا نشد")
            if engine == "chrome":
                raise RuntimeError("مرورگر Edge یا Chrome روی این کامپیوتر پیدا نشد.")

    if engine in ("auto", "playwright"):
        try:
            from playwright.sync_api import sync_playwright
            with sync_playwright() as pw:
                browser = pw.chromium.launch()
                page = browser.new_page()
                page.goto(url, wait_until="networkidle", timeout=60000)
                page.emulate_media(media="print")
                opts = {"path": pdf_path, "print_background": True, "margin":
                        {"top": "0", "right": "0", "bottom": "0", "left": "0"}}
                if w_mm and h_mm:
                    opts["width"] = f"{w_mm}mm"
                    opts["height"] = f"{h_mm}mm"
                else:
                    opts["format"] = "A4"
                page.pdf(**opts)
                browser.close()
            return True, "playwright"
        except Exception as e:
            errors.append(f"playwright: {e}")
            if engine == "playwright":
                raise

    if engine in ("auto", "weasyprint"):
        try:
            from weasyprint import HTML
            HTML(url=url).write_pdf(pdf_path)
            return True, "weasyprint"
        except Exception as e:
            errors.append(f"weasyprint: {e}")
            if engine == "weasyprint":
                raise

    raise RuntimeError("هیچ موتور تبدیل PDF در دسترس نبود — " + " | ".join(errors))


# ------------------------------------------------- موتور مرورگرِ خود سیستم

CHROME_CACHE = {"exe": None, "checked": False}


def find_chrome():
    """
    مسیر Edge یا Chrome نصب‌شده روی سیستم را پیدا می‌کند.
    این‌ها روی هر ویندوز ۱۰ و ۱۱ از قبل هستند، پس معمولاً هیچ نصبی لازم نیست.
    """
    if CHROME_CACHE["checked"]:
        return CHROME_CACHE["exe"]
    CHROME_CACHE["checked"] = True

    manual = (CONFIG.get("chrome_path") or "").strip()
    if manual and os.path.isfile(manual):
        CHROME_CACHE["exe"] = manual
        return manual

    candidates = []
    if IS_WINDOWS:
        bases = [os.environ.get("PROGRAMFILES", r"C:\Program Files"),
                 os.environ.get("PROGRAMFILES(X86)", r"C:\Program Files (x86)"),
                 os.environ.get("LOCALAPPDATA", "")]
        rels = [r"Microsoft\Edge\Application\msedge.exe",
                r"Google\Chrome\Application\chrome.exe",
                r"Chromium\Application\chrome.exe"]
        for b in bases:
            if not b:
                continue
            for r in rels:
                candidates.append(os.path.join(b, r))
    else:
        for n in ("google-chrome", "chromium", "chromium-browser", "microsoft-edge"):
            w = shutil.which(n)
            if w:
                candidates.append(w)

    for c in candidates:
        if c and os.path.isfile(c):
            CHROME_CACHE["exe"] = c
            log(f"موتور چاپ: مرورگر سیستم پیدا شد — {os.path.basename(c)}", "ok")
            return c

    return None


def chrome_to_pdf(exe, url, pdf_path, w_mm, h_mm):
    """
    با حالت headless خود مرورگر، صفحه را به PDF تبدیل می‌کند.
    اندازهٔ کاغذ با یک استایل @page به صفحه تزریق می‌شود، چون
    --print-to-pdf گزینهٔ مستقیمی برای عرض و ارتفاع دلخواه ندارد.
    """
    target = url
    tmp_html = None

    if w_mm and h_mm:
        # صفحه را می‌گیریم، @page را داخلش می‌گذاریم و از فایل موقت چاپ می‌کنیم
        try:
            style = (f'<style>@page{{size:{w_mm}mm {h_mm}mm;margin:0}}'
                     f'html,body{{margin:0;padding:0}}</style>')
            html = prepare_page(url, style)
            fd, tmp_html = tempfile.mkstemp(suffix=".html")
            with os.fdopen(fd, "w", encoding="utf-8") as f:
                f.write(html)
            target = "file:///" + tmp_html.replace("\\", "/")
        except Exception as e:
            log(f"تزریق اندازهٔ کاغذ ناموفق بود ({e})؛ صفحه مستقیم چاپ می‌شود.", "warn")
            target = url

    profile = tempfile.mkdtemp(prefix="weby-print-")
    cmd = [
        exe,
        "--headless=new",
        "--disable-gpu",
        "--no-first-run",
        "--no-default-browser-check",
        "--disable-extensions",
        f"--user-data-dir={profile}",
        "--print-to-pdf-no-header",
        f"--print-to-pdf={pdf_path}",
        "--virtual-time-budget=8000",
        target,
    ]
    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=90)
        if not os.path.isfile(pdf_path) or os.path.getsize(pdf_path) < 500:
            raise RuntimeError((res.stderr or res.stdout or "").strip()[:300]
                               or "خروجی PDF ساخته نشد")
    finally:
        if tmp_html:
            try: os.remove(tmp_html)
            except Exception: pass
        shutil.rmtree(profile, ignore_errors=True)


# ------------------------------------------------------------ چاپ

def find_sumatra():
    """
    SumatraPDF را در جاهای معمولش می‌گردد.
    اگر پیدا نشد اشکالی ندارد — مسیر چاپ مستقیم ویندوز جایگزینش است.
    """
    manual = (CONFIG.get("sumatra_path") or "").strip()
    if manual and os.path.isfile(manual):
        return manual

    found = shutil.which("SumatraPDF.exe") or shutil.which("SumatraPDF")
    if found:
        return found

    here = os.path.dirname(os.path.abspath(__file__))
    cands = [
        os.path.join(here, "SumatraPDF.exe"),              # کنار خود برنامه
        os.path.join(here, "tools", "SumatraPDF.exe"),
        r"C:\Program Files\SumatraPDF\SumatraPDF.exe",
        r"C:\Program Files (x86)\SumatraPDF\SumatraPDF.exe",
    ]
    for base in (os.environ.get("LOCALAPPDATA", ""), os.environ.get("APPDATA", "")):
        if base:
            cands.append(os.path.join(base, "SumatraPDF", "SumatraPDF.exe"))
            cands.append(os.path.join(base, "Programs", "SumatraPDF", "SumatraPDF.exe"))

    for c in cands:
        if c and os.path.isfile(c):
            return c
    return None


# ------------------------------------- چاپ مستقیم ویندوز (بدون برنامهٔ جانبی)

def chrome_to_png(exe, url, png_path, w_mm, h_mm, dpi=300):
    """
    صفحه را با مرورگر سیستم به یک عکس PNG با ابعاد دقیق تبدیل می‌کند.
    این عکس بعداً مستقیم و با اندازهٔ واقعی روی پرینتر کشیده می‌شود،
    پس دیگر به هیچ برنامهٔ چاپ PDF نیازی نیست.
    """
    px_w = max(1, int(round(w_mm / 25.4 * dpi)))
    px_h = max(1, int(round(h_mm / 25.4 * dpi)))

    # مرورگر با ۹۶ نقطه در اینچ کار می‌کند؛ با scale factor به dpi دلخواه می‌رسیم
    scale = dpi / 96.0
    css_w = max(1, int(round(w_mm / 25.4 * 96)))
    css_h = max(1, int(round(h_mm / 25.4 * 96)))

    tmp_html = None
    target = url
    try:
        style = (f"<style>@page{{size:{w_mm}mm {h_mm}mm;margin:0}}"
                 f"html,body{{margin:0;padding:0;width:{w_mm}mm;height:{h_mm}mm;"
                 f"overflow:hidden;background:#fff}}</style>")
        html = prepare_page(url, style)
        fd, tmp_html = tempfile.mkstemp(suffix=".html")
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            f.write(html)
        target = "file:///" + tmp_html.replace("\\", "/")
    except Exception as e:
        log(f"آماده‌سازی صفحه برای عکس ناموفق بود ({e})؛ آدرس مستقیم استفاده می‌شود.", "warn")

    profile = tempfile.mkdtemp(prefix="weby-shot-")
    # نکتهٔ مهم: گزینهٔ --screenshot فقط با headless قدیمی کار می‌کند.
    # با --headless=new تصویر کاملاً سفید بیرون می‌آید.
    cmd = [
        exe, "--headless=old", "--disable-gpu", "--hide-scrollbars",
        "--no-first-run", "--no-default-browser-check", "--disable-extensions",
        f"--user-data-dir={profile}",
        f"--force-device-scale-factor={scale:g}",
        f"--window-size={css_w},{css_h}",
        f"--screenshot={png_path}",
        "--virtual-time-budget=8000",
        target,
    ]
    try:
        res = subprocess.run(cmd, capture_output=True, text=True, timeout=90)
        if not os.path.isfile(png_path) or os.path.getsize(png_path) < 200:
            raise RuntimeError((res.stderr or res.stdout or "").strip()[:300]
                               or "عکس صفحه ساخته نشد")
    finally:
        if tmp_html:
            try: os.remove(tmp_html)
            except Exception: pass
        shutil.rmtree(profile, ignore_errors=True)

    return px_w, px_h


def _as_image(src):
    """ورودی می‌تواند مسیر فایل باشد یا خودِ تصویر."""
    from PIL import Image
    return src if hasattr(src, "size") and hasattr(src, "convert") else Image.open(src)


def render_label_pages(url, w_mm, h_mm, dpi):
    """
    صفحهٔ برچسب را به فهرستی از تصویرها تبدیل می‌کند — هر برچسب یک تصویر.

    چرا از راه PDF و نه عکسِ صفحه؟ چون عکس صفحه، صفحه را همان‌طور که در
    مرورگر می‌بینید می‌گیرد: نوار آبیِ بالای صفحه، پس‌زمینهٔ خاکستری و
    فاصله‌ها. آن نوار روی برچسب حرارتی یک نوار سیاهِ توپر چاپ می‌شود و
    خود برچسب را هم پایین می‌راند. قواعد @media print که این‌ها را پنهان
    می‌کنند فقط موقع چاپ اعمال می‌شوند، نه موقع عکس گرفتن.

    ساختن PDF این مشکل را ندارد: مرورگر دقیقاً حالت چاپ را رندر می‌کند،
    اندازهٔ @page را رعایت می‌کند، و اگر چند برچسب باشد هرکدام یک صفحهٔ
    جداگانه می‌شود. بعد هر صفحه را با همان تراکم نقطهٔ پرینتر به تصویر
    تبدیل می‌کنیم.
    """
    import pypdfium2 as pdfium

    fd, pdf_path = tempfile.mkstemp(suffix=".pdf")
    os.close(fd)
    try:
        html_to_pdf(url, pdf_path, w_mm or None, h_mm or None)
        doc = pdfium.PdfDocument(pdf_path)
        try:
            pages = []
            for i in range(len(doc)):
                page = doc[i]
                # scale نسبت نقطه بر اینچ به واحد PDF (۷۲ در اینچ) است
                pages.append(page.render(scale=dpi / 72.0).to_pil().convert("RGB"))
            # صفحهٔ کاملاً سفید برچسب نیست، کاغذِ هدررفته است. اگر عنصری
            # از چیدمان پایین‌تر از لبهٔ برچسب افتاده باشد، مرورگر یک صفحهٔ
            # خالی پشت برچسب می‌سازد (سایت با contain:strict جلویش را
            # گرفته؛ این سدِ دوم است برای صفحه‌های دیگر و نسخه‌های قدیمی).
            inked = [pg for pg in pages if pg.convert("L").getextrema()[0] < 250]
            if inked and len(inked) < len(pages):
                log(f"{len(pages) - len(inked)} صفحهٔ خالی کنار گذاشته شد.", "warn")
                pages = inked
            return pages
        finally:
            doc.close()
    finally:
        try: os.remove(pdf_path)
        except OSError: pass


def render_pages(url, w_mm, h_mm, dpi, chrome):
    """
    تصویرهای آمادهٔ چاپ را برمی‌گرداند. راه اصلی از طریق PDF است؛
    اگر کتابخانهٔ pypdfium2 نصب نباشد، به روش قدیمیِ عکس‌گرفتن
    برمی‌گردیم تا دست‌کم چاپ متوقف نشود.
    """
    try:
        pages = render_label_pages(url, w_mm, h_mm, dpi)
        if pages:
            return pages
        raise RuntimeError("PDF بدون صفحه بود")
    except ImportError:
        log("کتابخانهٔ pypdfium2 نصب نیست؛ به روش عکس‌گرفتن برمی‌گردم. "
            "برای نتیجهٔ درست اجرا کنید: pip install -r requirements.txt", "warn")
    except Exception as e:
        log(f"ساخت تصویر از راه PDF نشد ({e})؛ به روش عکس‌گرفتن برمی‌گردم.", "warn")

    if not chrome:
        raise RuntimeError("نه pypdfium2 در دسترس بود و نه مرورگری برای عکس‌گرفتن.")
    fd, png = tempfile.mkstemp(suffix=".png")
    os.close(fd)
    try:
        chrome_to_png(chrome, url, png, w_mm, h_mm, dpi)
        return [_as_image(png).convert("RGB")]
    finally:
        try: os.remove(png)
        except OSError: pass


# برگه‌های استاندارد: (عرض، ارتفاع به میلی‌متر، کدِ DMPAPERِ ویندوز) — همان
# فهرستِ label_papers() در includes/labels.php، به‌اضافهٔ Legal
STD_PAPERS = (
    (297.0, 420.0, 8,  "A3"),
    (210.0, 297.0, 9,  "A4"),
    (148.0, 210.0, 11, "A5"),
    (105.0, 148.0, 70, "A6"),
    (182.0, 257.0, 13, "B5"),
    (215.9, 279.4, 1,  "Letter"),
    (215.9, 355.6, 5,  "Legal"),
)


def std_paper(w_mm, h_mm, tol=2.0):
    """اگر اندازه یکی از برگه‌های استاندارد است: (کدِ DMPAPER، افقی؟)؛ وگرنه None."""
    if not w_mm or not h_mm:
        return None
    for pw, ph, code, _name in STD_PAPERS:
        if abs(w_mm - pw) <= tol and abs(h_mm - ph) <= tol:
            return code, False
        if abs(w_mm - ph) <= tol and abs(h_mm - pw) <= tol:
            return code, True
    return None


def _devmode_for(printer_name, w_mm, h_mm):
    """
    همان کاری که NiceLabel با درایور می‌کند:
    به‌جای تکیه بر «تنظیمات پیش‌فرض» درایور (که ممکن است کاغذ و جهت
    دیگری باشد و چاپ را ۹۰ درجه بچرخاند)، برای همین یک کار چاپی یک
    DEVMODE می‌سازیم که اندازهٔ کاغذ را دقیقاً برابر برچسب و جهت را
    عمودی تنظیم می‌کند. درایور مجبور است همین را اجرا کند.
    """
    import win32print, win32con
    hpr = win32print.OpenPrinter(printer_name)
    try:
        dm = win32print.GetPrinter(hpr, 2)["pDevMode"]
        if dm is None:
            raise RuntimeError("درایور DEVMODE نداد")
        std = std_paper(w_mm, h_mm)
        if std:
            # برگهٔ استاندارد (A4، A5…): کدِ خودِ کاغذ، نه «اندازهٔ دلخواه». درایورِ
            # پرینترِ کاغذی جای برگه را با همین کد می‌شناسد (بسیاری A5 را وسطِ سینی
            # می‌گیرند)؛ با اندازهٔ دلخواه، کاغذِ پیش‌فرض را نگه می‌داشت و چاپ به چپ و
            # بالا می‌افتاد — گزارشِ برچسبِ سفارشِ A5. نسخهٔ ۱٫۹
            code, landscape = std
            dm.PaperSize   = code
            dm.Orientation = 2 if landscape else 1  # DMORIENT_LANDSCAPE / PORTRAIT
            dm.Fields |= (win32con.DM_PAPERSIZE | win32con.DM_ORIENTATION)
            dm.Fields &= ~(win32con.DM_PAPERWIDTH | win32con.DM_PAPERLENGTH)
        else:
            dm.PaperSize   = 256                       # DMPAPER_USER — اندازهٔ دلخواه
            dm.PaperWidth  = int(round(w_mm * 10))     # واحد: دهم میلی‌متر
            dm.PaperLength = int(round(h_mm * 10))
            dm.Orientation = 1                         # DMORIENT_PORTRAIT
            dm.Fields |= (win32con.DM_PAPERSIZE | win32con.DM_PAPERWIDTH |
                          win32con.DM_PAPERLENGTH | win32con.DM_ORIENTATION)
        # درایور تنظیمات را می‌بیند، تأیید یا اصلاح می‌کند و برمی‌گرداند
        win32print.DocumentProperties(0, hpr, printer_name, dm, dm,
                                      win32con.DM_IN_BUFFER | win32con.DM_OUT_BUFFER)
        return dm
    finally:
        win32print.ClosePrinter(hpr)


def print_png_windows(src, printer, copies, w_mm, h_mm, center=False):
    """
    عکس را مستقیم و با اندازهٔ واقعی روی پرینتر ویندوز می‌کشد.
    از GDI خود ویندوز استفاده می‌کند (بستهٔ pywin32) و به هیچ
    برنامهٔ بیرونی نیاز ندارد.

    مهم: اندازهٔ کاغذ و جهت، برای همین کار، با DEVMODE به درایور
    تحمیل می‌شود (روش NiceLabel). پس دیگر مهم نیست در «تنظیمات
    پرینتر» ویندوز چه کاغذی انتخاب شده باشد.

    دو تنظیم مهم در هر پرینتر:
      rotate — چرخش تصویر (۰/۹۰/۱۸۰/۲۷۰). بعضی پرینترهای حرارتی
               برچسب را چرخیده می‌کشند؛ با این تنظیم درست می‌شود.
      fit    — exact: دقیقاً به اندازهٔ میلی‌متری تعریف‌شده
               fit  : تا جایی که در ناحیهٔ قابل چاپ جا شود

    center — تصویر وسطِ کاغذِ واقعی، نه گوشهٔ بالا-چپ. برای «برگهٔ پست»
             (نوعِ کار post_sheet): اگر درایور اندازهٔ دلخواه را نپذیرد
             (مثلاً «Microsoft Print to PDF» که همیشه Letter است) یا کاغذِ
             پرینتر بزرگ‌تر باشد، برگه گوشه نمی‌افتد. وقتی کاغذ همان اندازه
             است، فرقی نمی‌کند. برچسب‌ها مثل قبل از گوشه شروع می‌شوند.
    """
    import win32print, win32ui, win32con, win32gui
    from PIL import Image, ImageWin

    printer_name = printer["name"] if isinstance(printer, dict) else printer
    rotate = int((printer.get("rotate") if isinstance(printer, dict) else 0) or 0) % 360
    fit    = (printer.get("fit") if isinstance(printer, dict) else "exact") or "exact"

    img = _as_image(src).convert("RGB")

    if rotate:
        # expand=True تا بعد از چرخش چیزی بریده نشود
        img = img.rotate(-rotate, expand=True)
        if rotate in (90, 270):
            w_mm, h_mm = h_mm, w_mm      # ابعاد کاغذ هم جابه‌جا می‌شود

    dm = None
    if w_mm and h_mm:
        try:
            dm = _devmode_for(printer_name, w_mm, h_mm)
        except Exception as e:
            log(f"تنظیم اندازهٔ کاغذ در درایور نشد ({e})؛ "
                "با تنظیم فعلی درایور چاپ می‌شود.", "warn")

    for _ in range(max(1, copies)):
        if dm is not None:
            # DC با DEVMODE خودمان — نه پیش‌فرض درایور
            hdc = win32ui.CreateDCFromHandle(
                win32gui.CreateDC("WINSPOOL", printer_name, dm))
        else:
            hdc = win32ui.CreateDC()
            hdc.CreatePrinterDC(printer_name)
        try:
            dpi_x  = hdc.GetDeviceCaps(win32con.LOGPIXELSX)
            dpi_y  = hdc.GetDeviceCaps(win32con.LOGPIXELSY)
            page_w = hdc.GetDeviceCaps(win32con.HORZRES)
            page_h = hdc.GetDeviceCaps(win32con.VERTRES)
            # حاشیهٔ فیزیکی غیرقابل چاپ — برای اینکه تصویر دقیقاً از
            # گوشهٔ واقعی کاغذ شروع شود، نه گوشهٔ ناحیهٔ قابل چاپ
            off_x  = hdc.GetDeviceCaps(112)   # PHYSICALOFFSETX
            off_y  = hdc.GetDeviceCaps(113)   # PHYSICALOFFSETY
            phys_w = hdc.GetDeviceCaps(110)   # PHYSICALWIDTH
            phys_h = hdc.GetDeviceCaps(111)   # PHYSICALHEIGHT

            if w_mm and h_mm:
                want_w = int(round(w_mm / 25.4 * dpi_x))
                want_h = int(round(h_mm / 25.4 * dpi_y))
            else:
                want_w, want_h = page_w, page_h

            if fit == "fit":
                k = min(page_w / want_w, page_h / want_h)
            else:
                # اندازهٔ دقیق؛ فقط اگر از کاغذ بزرگ‌تر شد کوچکش می‌کنیم
                k = min(page_w / want_w, page_h / want_h, 1.0)

            draw_w, draw_h = max(1, int(want_w * k)), max(1, int(want_h * k))

            hdc.StartDoc("برند Label")
            hdc.StartPage()
            x0, y0 = -off_x, -off_y
            if center:
                x0 += max(0, (phys_w - draw_w) // 2)
                y0 += max(0, (phys_h - draw_h) // 2)
            elif (isinstance(printer, dict) and printer.get("kind") != "thermal"
                  and std_paper(w_mm, h_mm) and phys_w - draw_w > dpi_x * 3 / 25.4):
                # برگهٔ استاندارد روی پرینترِ کاغذی، ولی درایور کاغذِ پهن‌تری گرفت (کدِ
                # کاغذ را نپذیرفت): وسطِ عرض، بالا سرِ جایش. برگهٔ کوچک‌تر (A5) در سینی
                # معمولاً وسط می‌نشیند؛ از گوشه، چاپ به چپ می‌افتاد. نسخهٔ ۱٫۹
                x0 += (phys_w - draw_w) // 2
                log("درایورِ «{}» کاغذِ {:g}×{:g} را نپذیرفت (کاغذِ درایور {:.0f}×{:.0f} م‌م)؛ "
                    "برگه وسطِ عرض چاپ شد. در تنظیماتِ پرینتر همین کاغذ را انتخاب کنید."
                    .format(printer_name, w_mm, h_mm, phys_w / dpi_x * 25.4, phys_h / dpi_y * 25.4), "warn")
            ImageWin.Dib(img).draw(hdc.GetHandleOutput(),
                                   (x0, y0, x0 + draw_w, y0 + draw_h))
            hdc.EndPage()
            hdc.EndDoc()
        finally:
            try: hdc.DeleteDC()
            except Exception: pass


# ---------------------------------------- چاپ خامِ حرارتی (زبان TSPL)

def _send_raw(printer_name, data):
    """بایت‌ها را بدون هیچ دخالتی از درایور، مستقیم به پرینتر می‌فرستد."""
    if IS_WINDOWS:
        import win32print
        h = win32print.OpenPrinter(printer_name)
        try:
            win32print.StartDocPrinter(h, 1, ("برند Label", None, "RAW"))
            try:
                win32print.StartPagePrinter(h)
                win32print.WritePrinter(h, data)
                win32print.EndPagePrinter(h)
            finally:
                win32print.EndDocPrinter(h)
        finally:
            win32print.ClosePrinter(h)
    else:
        fd, tmp = tempfile.mkstemp(suffix=".prn")
        try:
            with os.fdopen(fd, "wb") as f:
                f.write(data)
            r = subprocess.run(["lp", "-d", printer_name, "-o", "raw", tmp],
                               capture_output=True, text=True, timeout=120)
            if r.returncode != 0:
                raise RuntimeError(r.stderr or r.stdout)
        finally:
            try: os.remove(tmp)
            except OSError: pass


def print_tspl(src, printer, copies, w_mm, h_mm):
    """
    چاپ به زبان خود پرینتر حرارتی (TSPL) — برای ZEC ZP400 و همهٔ
    پرینترهای سازگار با TSC.

    این دقیق‌ترین حالت ممکن است: خودمان به پرینتر می‌گوییم
    SIZE (اندازهٔ برچسب)، GAP (فاصلهٔ بین برچسب‌ها)، DENSITY (غلظت)
    و SPEED، بعد تصویر آماده‌شده را نقطه‌به‌نقطه می‌فرستیم.
    درایور ویندوز کاملاً دور زده می‌شود، پس هیچ تنظیم اشتباهی در
    درایور نمی‌تواند چاپ را بچرخاند یا اندازه را عوض کند.
    """
    from PIL import Image

    printer_name = printer["name"] if isinstance(printer, dict) else printer
    rotate  = int((printer.get("rotate") or 0)) % 360
    dpi     = int(printer.get("tspl_dpi") or 203)      # ZP400 = 203 (۸ نقطه بر میلی‌متر)
    gap     = printer.get("gap_mm")
    gap     = 3.0 if gap in (None, "") else float(gap)
    density = min(15, max(0, int(printer.get("density") or 8)))
    speed   = min(12, max(1, int(printer.get("speed") or 4)))

    img = _as_image(src)
    if rotate:
        img = img.rotate(-rotate, expand=True)
        if rotate in (90, 270):
            w_mm, h_mm = h_mm, w_mm

    # نگاشت یک‌به‌یکِ پیکسل به نقطهٔ پرینتر
    dots_w = max(8, int(round(w_mm / 25.4 * dpi)))
    dots_h = max(8, int(round(h_mm / 25.4 * dpi)))
    dots_w -= dots_w % 8          # عرض باید مضرب ۸ باشد (هر بایت = ۸ نقطه)

    # سیاه‌وسفید با آستانه (بدون رگه‌رگه شدن، تا بارکد تیز بماند)
    img = (img.convert("L")
              .resize((dots_w, dots_h), Image.LANCZOS)
              .point(lambda v: 255 if v >= 160 else 0, mode="1"))

    wb = dots_w // 8
    bitmap = img.tobytes()        # در TSPL: بیت ۱ = سفید، بیت ۰ = چاپ نقطه

    head = (f"SIZE {w_mm:g} mm,{h_mm:g} mm\r\n"
            f"GAP {gap:g} mm,0 mm\r\n"
            f"DIRECTION 0\r\n"
            f"REFERENCE 0,0\r\n"
            f"DENSITY {density}\r\n"
            f"SPEED {speed}\r\n"
            f"CLS\r\n").encode("ascii")
    body = b"BITMAP 0,0,%d,%d,0," % (wb, dots_h) + bitmap + b"\r\n"
    tail = ("PRINT %d,1\r\n" % max(1, int(copies or 1))).encode("ascii")

    _send_raw(printer_name, head + body + tail)


def send_to_printer(pdf_path, printer, copies):
    """PDF ساخته‌شده را به پرینتر می‌فرستد (مسیر SumatraPDF یا لینوکس)."""
    name = printer["name"]

    if IS_WINDOWS:
        sumatra = find_sumatra()
        if not sumatra:
            raise RuntimeError("SumatraPDF پیدا نشد.")
        for _ in range(copies):
            r = subprocess.run(
                # noscale: سوماترا اجازه ندارد خودش اندازه را تغییر دهد
                [sumatra, "-print-to", name, "-print-settings", "noscale",
                 "-silent", pdf_path],
                capture_output=True, text=True, timeout=180,
            )
            if r.returncode != 0:
                raise RuntimeError(f"چاپ ناموفق بود: {r.stderr or r.stdout}")
    else:
        r = subprocess.run(
            ["lp", "-d", name, "-n", str(copies), pdf_path],
            capture_output=True, text=True, timeout=180,
        )
        if r.returncode != 0:
            raise RuntimeError(f"چاپ ناموفق بود: {r.stderr or r.stdout}")


# ------------------------------------------------------------ ارتباط با سایت

def api(do, payload=None, method="POST", site=None):
    site = site or next(iter(CONFIG.get("sites") or []), None)
    if not site or not site.get("api_url") or not site.get("token"):
        raise RuntimeError("نشانی سایت یا توکن ایستگاه تنظیم نشده است.")
    url = site["api_url"].rstrip("/") + "?do=" + do
    headers = {"X-Print-Token": site.get("token", "")}
    if method == "GET":
        r = requests.get(url, headers=headers, timeout=25)
    else:
        r = requests.post(url, headers=headers, json=(payload or {}), timeout=25)
    r.raise_for_status()
    return r.json()


def report(job_id, status, error="", tries=4, site=None):
    """
    اعلام وضعیت به سایت — با تلاش دوباره.

    چرا تلاش دوباره: اگر «تمام شد» به سایت نرسد، کار همان‌جا در حالت
    «در حال چاپ» می‌ماند. یک قطعی چندثانیه‌ای اینترنت کافی بود تا
    کاری که *روی کاغذ آمده* ناتمام به نظر برسد. سمت سایت هم دیگر
    چنین کاری را دوباره چاپ نمی‌کند، ولی به خطا می‌خورد و آدم باید
    دستی تصمیم بگیرد — پس ارزش دارد چند بار تلاش کنیم.
    """
    for i in range(max(1, tries)):
        try:
            api("status", {"id": job_id, "status": status, "error": error}, site=site)
            return True
        except Exception as e:
            if i == tries - 1:
                log(f"اعلام وضعیت کار {job_id} ناموفق بود: {e}", "error")
            else:
                time.sleep(2 * (i + 1))
    return False


# کارهایی که همین اجرا چاپشان کرده‌ایم. اگر سایت به هر دلیلی همان کار
# را دوباره بدهد (مثلاً «تمام شد» نرسیده باشد)، دوباره چاپ نمی‌شود —
# آخرین سدِ کاغذِ هدررفته، همین‌جا روی خودِ دستگاه.
PRINTED = set()


def handle_job(job, site=None):
    jid = job["id"]
    title = job.get("title") or f"کار {jid}"
    if site and len(CONFIG.get("sites") or []) > 1:
        title = f"[{site_label(site)}] {title}"
    pkey = (site["id"] if site else "", jid)

    if pkey in PRINTED:
        log(f"{title} — این کار همین حالا چاپ شده بود؛ دوباره چاپ نمی‌شود.", "warn")
        report(jid, "done", site=site)
        return

    log(f"کار تازه: {title}")

    printer, err = pick_printer(job.get("printer") or (site or {}).get("printer") or "")
    if not printer:
        log(f"{title} — {err}", "error")
        report(jid, "error", err, site=site)
        _count(site, "jobs_failed")
        return

    printer, why = resolve_paper(printer, float(job.get("paper_w") or 0),
                                 float(job.get("paper_h") or 0))
    if not printer:
        msg = why + " کاغذ را عوض کنید و «تلاش دوباره» را بزنید."
        log(f"{title} — {why}", "error")
        report(jid, "error", msg, site=site)
        _count(site, "jobs_failed")
        return

    report(jid, "printing", site=site)

    w_mm = float(job.get("paper_w") or 0)
    h_mm = float(job.get("paper_h") or 0)
    copies = max(1, int(job.get("copies") or 1))
    # برگهٔ پست وسطِ کاغذ چاپ می‌شود (print_png_windows، center)
    center = (job.get("type") or "") == "post_sheet"
    mode = CONFIG.get("print_mode", "auto")     # auto | direct | pdf

    tmp = None
    try:
        chrome  = find_chrome() if IS_WINDOWS else None
        sumatra = find_sumatra() if IS_WINDOWS else None

        driver = (printer.get("driver") or "gdi") if isinstance(printer, dict) else "gdi"

        # مسیر TSPL: زبان خود پرینتر حرارتی. دقیق‌ترین حالت — درایور
        # ویندوز کلاً دور زده می‌شود. برای ZEC ZP400 این را انتخاب کنید.
        use_tspl = bool(chrome and w_mm and h_mm and driver == "tspl")

        # مسیر مستقیم: عکس صفحه را با اندازهٔ واقعی روی پرینتر می‌کشیم.
        # هیچ برنامهٔ جانبی لازم ندارد، پس پیش‌فرض همین است.
        # هر وقت اندازهٔ دقیق کاغذ معلوم است (برچسب)، مسیر مستقیم انتخاب
        # می‌شود — حتی اگر SumatraPDF هم نصب باشد. فقط این مسیر است که
        # تنظیم چرخش و اندازهٔ هر پرینتر را اعمال می‌کند.
        # SumatraPDF برای کارهای بدون اندازهٔ مشخص (فاکتور A4) می‌ماند.
        use_direct = (
            IS_WINDOWS and chrome and w_mm and h_mm and mode != "pdf"
        )

        if use_tspl:
            tspl_dpi = int(printer.get("tspl_dpi") or 203)
            pages = render_pages(job["url"], w_mm, h_mm, tspl_dpi, chrome)
            for pg in pages:
                print_tspl(pg, printer, copies, w_mm, h_mm)
            rot = int(printer.get("rotate") or 0)
            engine = ("TSPL خام، {}×{} م‌م، {} dpi".format(f"{w_mm:g}", f"{h_mm:g}", tspl_dpi)
                      + (f"، {len(pages)} برچسب" if len(pages) > 1 else "")
                      + (f"، چرخش {rot}°" if rot else ""))
        elif use_direct:
            dpi = int(CONFIG.get("render_dpi", 300) or 300)
            pages = render_pages(job["url"], w_mm, h_mm, dpi, chrome)
            for pg in pages:
                print_png_windows(pg, printer, copies, w_mm, h_mm, center=center)
            rot = int(printer.get("rotate") or 0)
            engine = ("چاپ مستقیم، {}×{} م‌م".format(f"{w_mm:g}", f"{h_mm:g}")
                      + (f"، {len(pages)} برچسب" if len(pages) > 1 else "")
                      + (f"، چرخش {rot}°" if rot else "")
                      + ("، حالت جا شود" if printer.get("fit") == "fit" else ""))
        else:
            if IS_WINDOWS and (printer.get("rotate") or printer.get("fit") == "fit"):
                log("این کار از راه PDF چاپ می‌شود و تنظیم چرخش/اندازهٔ پرینتر "
                    "روی آن اثری ندارد. برای اعمال آن‌ها روش چاپ را «چاپ مستقیم» بگذارید.",
                    "warn")
            tmp = os.path.join(tempfile.gettempdir(), f"weby-print-{(site or {}).get('id', 'x')}-{jid}.pdf")
            _, engine = html_to_pdf(job["url"], tmp, w_mm or None, h_mm or None)
            try:
                send_to_printer(tmp, printer, copies)
            except RuntimeError as e:
                # SumatraPDF نبود؟ اگر می‌شود با مسیر مستقیم ادامه بده
                if IS_WINDOWS and chrome and w_mm and h_mm and "SumatraPDF" in str(e):
                    log("SumatraPDF نبود؛ با چاپ مستقیم ادامه می‌دهم.", "warn")
                    dpi = int(CONFIG.get("render_dpi", 300) or 300)
                    for pg in render_pages(job["url"], w_mm, h_mm, dpi, chrome):
                        print_png_windows(pg, printer, copies, w_mm, h_mm, center=center)
                    engine = "چاپ مستقیم"
                else:
                    raise

        PRINTED.add(pkey)
        if len(PRINTED) > 500:
            PRINTED.clear()
        report(jid, "done", site=site)
        _count(site, "jobs_done")
        log(f"{title} — روی «{printer['label']}» چاپ شد ({engine})", "ok")

    except Exception as e:
        log(f"{title} — خطا: {e}", "error")
        report(jid, "error", str(e)[:380], site=site)
        _count(site, "jobs_failed")
    finally:
        if tmp:
            try: os.remove(tmp)
            except OSError: pass


def poll_site(site, stop):
    """برای هر سایت یک رشته: از همان سایت کار می‌گیرد و نتیجه را به همان سایت می‌گوید."""
    last_hello = 0
    while not stop.is_set():
        ss = site_state(site)
        try:
            # هر ۳۰ ثانیه فهرست پرینترها و کاغذشان را به سایت گزارش می‌کنیم
            if time.time() - last_hello > 30:
                res = api("hello", {
                    "version": VERSION,
                    # فقط پرینترهایی که در همین ایستگاه «فعال» شده‌اند به سایت
                    # گزارش می‌شوند؛ پس در پنل هم فقط همان‌ها دیده می‌شوند.
                    "printers": [
                        {**{k: p.get(k) for k in
                            ("name", "label", "kind", "paper", "paper_w", "paper_h",
                             "tolerance", "default", "rotate", "fit", "driver",
                             "multi_paper", "default_paper")},
                         "papers": [{k: x.get(k) for k in
                                     ("id", "paper", "paper_w", "paper_h", "tolerance")}
                                    for x in p.get("papers", [])]}
                        for p in merged_printers()
                        if p.get("connected") and p.get("enabled")
                    ],
                }, site=site)
                if res.get("ok"):
                    ss["station"] = res.get("station", "")
                    CONFIG["station_name"] = ss["station"]
                    last_hello = time.time()

            res = api("claim", method="GET", site=site)
            ss["online"] = True
            ss["last_error"] = ""
            ss["last_poll"] = datetime.now().strftime("%H:%M:%S")

            job = res.get("job")
            if job:
                ss["busy"] = True
                try:
                    with PRINT_LOCK:
                        handle_job(job, site)
                finally:
                    ss["busy"] = False
                continue          # بلافاصله سراغ کار بعدی

        except requests.exceptions.RequestException as e:
            ss["online"] = False
            ss["last_error"] = f"ارتباط با سایت برقرار نشد: {e}"
        except Exception as e:
            ss["online"] = False
            ss["last_error"] = str(e)

        stop.wait(max(2, int(CONFIG.get("poll_seconds", 5))))


def site_manager():
    """سایت‌های فعالِ config را راه می‌اندازد؛ سایتی که حذف، خاموش یا عوض شود، رشته‌اش بسته می‌شود."""
    running = {}   # id -> (امضا، stop-event)
    while True:
        want = {x["id"]: x for x in (CONFIG.get("sites") or []) if x.get("enabled", True) and x.get("api_url") and x.get("token")}
        for sid in list(running):
            sig, ev = running[sid]
            x = want.get(sid)
            if x is None or (x["api_url"], x["token"]) != sig:
                ev.set()
                del running[sid]
        for sid, x in want.items():
            if sid not in running:
                ev = threading.Event()
                running[sid] = ((x["api_url"], x["token"]), ev)
                threading.Thread(target=poll_site, args=(x, ev), daemon=True).start()
        with LOCK:
            STATE["online"] = any(site_state_nolock(x)["online"] for x in want.values())
            STATE["last_error"] = next((site_state_nolock(x)["last_error"] for x in want.values() if site_state_nolock(x)["last_error"]), "") \
                if want else "هیچ سایتی تنظیم نشده است."
        time.sleep(2)


# ------------------------------------------------------------ رابط کاربری وب

app = Flask(__name__)
_UI_PATH = next((os.path.join(d, "ui.html") for d in (RES, HERE) if os.path.exists(os.path.join(d, "ui.html"))), "")
UI = open(_UI_PATH, encoding="utf-8").read() if _UI_PATH else "<h1>ui.html پیدا نشد</h1>"


@app.route("/")
def index():
    return render_template_string(UI)


@app.route("/api/state")
def api_state():
    with LOCK:
        log_copy = list(STATE["log"])
        sites_view = [dict(site_state_nolock(x), id=x["id"], name=x.get("name", ""), label=site_label(x), api_url=x["api_url"],
                           token=x["token"], enabled=x.get("enabled", True), printer=x.get("printer", ""))
                      for x in CONFIG.get("sites") or []]
    return jsonify({
        "version": VERSION,
        "online": STATE["online"],
        "busy": any(x.get("busy") for x in sites_view),
        "last_error": STATE["last_error"],
        "last_poll": STATE["last_poll"],
        "jobs_done": STATE["jobs_done"],
        "jobs_failed": STATE["jobs_failed"],
        "log": log_copy,
        "sites": sites_view,
        "config": {
            "poll_seconds": CONFIG.get("poll_seconds", 5),
            "engine": CONFIG.get("engine", "auto"),
            "station_name": CONFIG.get("station_name", ""),
        },
        "printers": merged_printers(),
        "os": platform.system(),
    })


@app.route("/api/config", methods=["POST"])
def api_save_config():
    data = request.get_json(force=True) or {}
    if isinstance(data.get("sites"), list):      # همان دیکشنری‌ها به‌روز می‌شوند تا رشته‌های در حال کار مقدار تازه را ببینند
        old = {x["id"]: x for x in CONFIG.get("sites") or []}
        keep = []
        for it in data["sites"]:
            if not isinstance(it, dict):
                continue
            x = old.get(str(it.get("id") or ""))
            if x is None:
                x = {"id": uuid.uuid4().hex[:8]}
            x.update(name=str(it.get("name") or "").strip(), api_url=str(it.get("api_url") or "").strip(),
                     token=str(it.get("token") or "").strip(), printer=str(it.get("printer") or "").strip(),
                     enabled=it.get("enabled", True) is not False)
            if x["api_url"] or x["token"]:
                keep.append(x)
        CONFIG["sites"] = keep
    if str(data.get("api_url") or "").strip() and str(data.get("token") or "").strip():
        add_site(CONFIG, data["api_url"], data["token"], data.get("name", ""))
    for k in ("engine", "sumatra_path", "chrome_path", "print_mode", "render_dpi"):
        if k in data:
            CONFIG[k] = str(data[k]).strip()
    if "poll_seconds" in data:
        CONFIG["poll_seconds"] = max(2, int(data["poll_seconds"] or 5))
    save_config(CONFIG)
    log("تنظیمات ذخیره شد.", "ok")
    return jsonify({"ok": True})


@app.route("/api/printers", methods=["POST"])
def api_save_printers():
    """
    ذخیرهٔ کاغذی که الان داخل هر پرینتر است.
    همین است که باعث می‌شود اگر کاغذ اشتباه باشد، به‌جای چاپ خراب، خطا برگردد.
    """
    data = request.get_json(force=True) or {}
    rows = data.get("printers") or []
    clean = []
    for p in rows:
        name = str(p.get("name", "")).strip()
        if not name:
            continue
        papers = papers_of(p)
        clean.append({
            "name": name,
            "label": str(p.get("label") or name).strip(),
            "enabled": bool(p.get("enabled")),
            "kind": "thermal" if p.get("kind") == "thermal" else "paper",
            "default": bool(p.get("default")),
            "multi_paper": bool(p.get("multi_paper")),
            "default_paper": default_paper_of(papers, p.get("default_paper"))["id"],
            "papers": papers,
        })
    CONFIG["printers"] = clean
    save_config(CONFIG)
    on = [p["label"] for p in clean if p.get("enabled")]
    log("تنظیمات پرینترها ذخیره شد. فعال: "
        + ("، ".join(on) if on else "هیچ‌کدام"), "ok")
    return jsonify({"ok": True})


@app.route("/api/test", methods=["POST"])
def api_test():
    """یک صفحهٔ آزمایشی چاپ می‌کند تا مطمئن شوید مسیر کامل کار می‌کند."""
    data = request.get_json(force=True) or {}
    printer, err = pick_printer(data.get("printer") or "")
    if not printer:
        return jsonify({"ok": False, "error": err})
    # آزمایش روی کاغذ مشخص؛ اگر ندادند، کاغذ پیش‌فرض پرینتر
    want = str(data.get("paper_id") or "")
    for pp in printer.get("papers", []):
        if want and pp["id"] == want:
            printer = with_paper(printer, pp)
            break

    html = f"""<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="utf-8">
    <style>@page{{margin:8mm}} body{{font-family:Tahoma,sans-serif;font-size:12pt;line-height:2}}</style>
    </head><body><h2>صفحهٔ آزمایشی ایستگاه چاپ فروشگاه</h2>
    <p>پرینتر: {printer.get('label')}</p>
    <p>کاغذ تنظیم‌شده: {printer.get('paper') or '—'}
       ({printer.get('paper_w')}×{printer.get('paper_h')} میلی‌متر)</p>
    <p>زمان: {datetime.now():%Y-%m-%d %H:%M:%S}</p>
    <p>اگر این برگه را می‌بینید، همه‌چیز درست کار می‌کند.</p>
    </body></html>"""

    tmp_html = os.path.join(tempfile.gettempdir(), "weby-test.html")
    tmp_pdf = os.path.join(tempfile.gettempdir(), "weby-test.pdf")
    tmp_png = os.path.join(tempfile.gettempdir(), "weby-test.png")
    try:
        with open(tmp_html, "w", encoding="utf-8") as f:
            f.write(html)

        # تست باید از همان مسیری برود که کارهای واقعی می‌روند،
        # وگرنه نتیجه‌اش چیزی را ثابت نمی‌کند.
        w_mm = float(printer.get("paper_w") or 0)
        h_mm = float(printer.get("paper_h") or 0)
        chrome = find_chrome()
        driver = printer.get("driver") or "gdi"
        url = "file:///" + tmp_html.replace("\\", "/")

        if chrome and w_mm and h_mm and driver == "tspl":
            dpi = int(printer.get("tspl_dpi") or 203)
            for pg in render_pages(url, w_mm, h_mm, dpi, chrome):
                print_tspl(pg, printer, 1, w_mm, h_mm)
            how = "TSPL خام"
        elif IS_WINDOWS and chrome and w_mm and h_mm and CONFIG.get("print_mode", "auto") != "pdf":
            dpi = int(CONFIG.get("render_dpi", 300) or 300)
            for pg in render_pages(url, w_mm, h_mm, dpi, chrome):
                print_png_windows(pg, printer, 1, w_mm, h_mm)
            how = "چاپ مستقیم"
        else:
            html_to_pdf(url, tmp_pdf, w_mm or None, h_mm or None)
            send_to_printer(tmp_pdf, printer, 1)
            how = "PDF"

        log(f"صفحهٔ آزمایشی روی «{printer['label']}» چاپ شد ({how}).", "ok")
        return jsonify({"ok": True})
    except Exception as e:
        log(f"چاپ آزمایشی ناموفق: {e}", "error")
        return jsonify({"ok": False, "error": str(e)})
    finally:
        for t in (tmp_png,):
            try: os.remove(t)
            except OSError: pass


def run():
    threading.Thread(target=site_manager, daemon=True).start()
    port = int(CONFIG.get("web_port", DEFAULT_PORT))
    print("=" * 60)
    print("  ایستگاه چاپ فروشگاه — نسخهٔ", VERSION)
    print(f"  رابط کاربری:  http://localhost:{port}")
    print(f"  از شبکه:      http://<IP-این-کامپیوتر>:{port}")
    print("=" * 60)
    app.run(host="0.0.0.0", port=port, threaded=True)




# ------------------------------------------------------------ نصب خودکار روی ویندوز (WebyPrint.exe)
#
# دوبار کلیک روی WebyPrint.exe:
#   - اگر نصب نشده: اجازهٔ Administrator می‌گیرد، خودش را در C:\ProgramData\WebyPrint کپی می‌کند،
#     config.json ِ کنارش (نشانی سایت و توکن) را برمی‌دارد، در Task Scheduler طوری ثبت می‌شود که با ورودِ
#     هر کاربر به ویندوز (در حسابِ همان کاربر) اجرا شود و اگر بسته شد هر ۵ دقیقه دوباره بالا بیاید،
#     در فایروال ویندوز برای شبکهٔ داخلی باز می‌شود، همان لحظه روشن می‌شود و صفحهٔ تنظیمات را باز می‌کند.
#     — نه با حساب SYSTEM: Edge در حساب SYSTEM اجرا نمی‌شود و پرینترهای اشتراکیِ کاربر را هم نمی‌بیند.
#       کارهای چاپی که وقتی کسی وارد ویندوز نشده برسند در صفِ سایت می‌مانند و بعد چاپ می‌شوند. —
#   - اگر نصب شده و روشن است: صفحهٔ تنظیمات را باز می‌کند.
#   - نسخهٔ تازه: همان WebyPrint.exe ِ تازه را اجرا کنید؛ جایگزین می‌شود و تنظیمات می‌ماند.
# پورت همان DEFAULT_PORT (۸۷۶۵) یا هر چه در config.json است؛ نصب آن را عوض نمی‌کند.

import traceback
import urllib.request
from xml.sax.saxutils import escape as _xml_escape


def _port():
    try:
        return int(CONFIG.get("web_port") or DEFAULT_PORT)
    except (TypeError, ValueError):
        return DEFAULT_PORT


def _msg(text, title="Weby Print Station", err=False):
    if not IS_WINDOWS:
        print(text)
        return
    try:
        import ctypes
        ctypes.windll.user32.MessageBoxW(0, text, title, 0x10 if err else 0x40)
    except Exception:
        print(text)


def _is_admin():
    try:
        import ctypes
        return bool(ctypes.windll.shell32.IsUserAnAdmin())
    except Exception:
        return False


def _elevate(args):
    """همین برنامه را با اجازهٔ Administrator دوباره اجرا می‌کند."""
    import ctypes
    params = " ".join('"%s"' % a for a in ([] if FROZEN else [os.path.abspath(__file__)]) + args)
    return ctypes.windll.shell32.ShellExecuteW(None, "runas", sys.executable, params, HERE, 1) > 32


def _single_instance():
    """فقط یک نسخهٔ در حال اجرا (کارِ چاپی دو بار چاپ نشود)."""
    if not IS_WINDOWS:
        return True
    try:
        import ctypes
        k = ctypes.windll.kernel32
        k.CreateMutexW.restype = ctypes.c_void_p
        h = k.CreateMutexW(None, False, "Global\\WebyPrintStation")
        err = k.GetLastError()
        globals()["_MUTEX"] = h
        return bool(h) and err not in (183, 5)          # ERROR_ALREADY_EXISTS / ACCESS_DENIED
    except Exception:
        return True


def _ui_alive(timeout=1.5):
    try:
        with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(
                "http://127.0.0.1:%d/api/state" % _port(), timeout=timeout) as r:
            return r.status == 200
    except Exception:
        return False


def _open_ui():
    import webbrowser
    webbrowser.open("http://localhost:%d" % _port())


def _run_cmd(cmd, timeout=60):
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, stdin=subprocess.DEVNULL,
                           creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        return r.returncode, (r.stdout or "") + (r.stderr or "")
    except Exception as e:
        return -1, str(e)


def _task_xml(exe, args="run"):
    return """<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>Weby print station: prints labels, invoices and letters sent from the shop website.</Description></RegistrationInfo>
  <Triggers>
    <LogonTrigger><Enabled>true</Enabled><Delay>PT10S</Delay></LogonTrigger>
    <TimeTrigger><StartBoundary>2024-01-01T00:00:00</StartBoundary><Enabled>true</Enabled>
      <Repetition><Interval>PT5M</Interval><StopAtDurationEnd>false</StopAtDurationEnd></Repetition></TimeTrigger>
  </Triggers>
  <Principals><Principal id="Author"><GroupId>S-1-5-32-545</GroupId><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings>
    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>
    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>
    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>
    <AllowHardTerminate>true</AllowHardTerminate>
    <StartWhenAvailable>true</StartWhenAvailable>
    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>
    <IdleSettings><StopOnIdleEnd>false</StopOnIdleEnd><RestartOnIdle>false</RestartOnIdle></IdleSettings>
    <AllowStartOnDemand>true</AllowStartOnDemand>
    <Enabled>true</Enabled>
    <Hidden>false</Hidden>
    <RunOnlyIfIdle>false</RunOnlyIfIdle>
    <WakeToRun>false</WakeToRun>
    <ExecutionTimeLimit>PT0S</ExecutionTimeLimit>
    <Priority>6</Priority>
    <RestartOnFailure><Interval>PT1M</Interval><Count>999</Count></RestartOnFailure>
  </Settings>
  <Actions Context="Author"><Exec><Command>%s</Command><Arguments>%s</Arguments><WorkingDirectory>%s</WorkingDirectory></Exec></Actions>
</Task>
""" % (_xml_escape(exe), _xml_escape(args), _xml_escape(INSTALL_DIR))


def _kill_others():
    """نسخه‌های دیگرِ در حال اجرا را می‌بندد. exe ِ تک‌فایلی دو پردازش دارد (راه‌انداز و خودِ برنامه)؛
    هر دوی خودمان (این پردازش و پدرش) معاف‌اند، وگرنه راه‌اندازِ خودمان بسته می‌شود."""
    me = {os.getpid()}
    try:
        me.add(os.getppid())
    except Exception:
        pass
    cmd = ["taskkill", "/F", "/IM", EXE_NAME]
    for pid in me:
        cmd += ["/FI", "PID ne %d" % pid]
    _run_cmd(cmd)


def _task_exists():
    return _run_cmd(["schtasks", "/Query", "/TN", TASK_NAME])[0] == 0


def _merge_config(src_dir, dst_path):
    """config.json ِ کنارِ فایلِ دانلودشده (نشانی سایت، توکن) را با تنظیماتِ قبلیِ نصب‌شده یکی می‌کند.
    پرینترها، کاغذها و پورتِ قبلی می‌مانند."""
    def rd(path):
        try:
            with open(path, "r", encoding="utf-8-sig") as f:
                v = json.load(f)
                return v if isinstance(v, dict) else {}
        except Exception:
            return {}
    old, new = rd(dst_path), rd(os.path.join(src_dir, "config.json"))
    cfg = dict(DEFAULT_CONFIG)
    cfg.update(old)
    normalize_sites(cfg)
    normalize_sites(new)     # سایتِ فایلِ دانلودشده به فهرست سایت‌های نصب‌شده اضافه می‌شود (جایگزین نمی‌شود)
    for x in new["sites"]:
        add_site(cfg, x["api_url"], x["token"], x["name"], x.get("printer", ""))
    if not old and new.get("web_port"):
        cfg["web_port"] = new["web_port"]
    if not isinstance(cfg.get("printers"), list):
        cfg["printers"] = []
    with open(dst_path, "w", encoding="utf-8") as f:
        json.dump(cfg, f, ensure_ascii=False, indent=2)
    return cfg


def _ilog(msg):
    """گزارشِ نصب: کنارِ فایلِ دانلودشده و در پوشهٔ نصب (برنامه پنجرهٔ متنی ندارد)."""
    line = time.strftime("%Y-%m-%d %H:%M:%S") + "  " + str(msg) + "\n"
    for d in (HERE, INSTALL_DIR):
        try:
            os.makedirs(d, exist_ok=True)
            with open(os.path.join(d, "install.log"), "a", encoding="utf-8") as f:
                f.write(line)
        except Exception:
            pass


def _shortcut_path():
    return os.path.join(os.environ.get("PUBLIC", r"C:\Users\Public"), "Desktop", "Weby Print Station.url")


def install(quiet=False):
    if not IS_WINDOWS:
        print("نصبِ خودکار فقط روی ویندوز است. روی لینوکس/مک:  python3 agent.py")
        return 1
    if not _is_admin():
        if _elevate(["install"] + (["--quiet"] if quiet else [])):
            return 0
        _msg("برای نصب، اجازهٔ Administrator لازم است. دوباره اجرا کنید و «Yes» را بزنید.", err=True)
        return 1
    steps = []
    _ilog("install %s (frozen=%s, from %s)" % (VERSION, FROZEN, HERE))
    try:
        os.makedirs(INSTALL_DIR, exist_ok=True)
        exe_dst = os.path.join(INSTALL_DIR, EXE_NAME if FROZEN else "agent.py")
        _run_cmd(["schtasks", "/End", "/TN", TASK_NAME])
        if FROZEN:
            _kill_others()
        time.sleep(1.5)
        srcs = [sys.executable] if FROZEN else [os.path.abspath(__file__), os.path.join(HERE, "ui.html")]
        for src in srcs:
            dst = exe_dst if src in (sys.executable, os.path.abspath(__file__)) else os.path.join(INSTALL_DIR, os.path.basename(src))
            if not os.path.isfile(src) or os.path.normcase(os.path.abspath(src)) == os.path.normcase(os.path.abspath(dst)):
                continue
            for _ in range(10):
                try:
                    shutil.copy2(src, dst)
                    break
                except PermissionError:
                    time.sleep(1)
            else:
                raise RuntimeError("فایلِ قبلیِ برنامه آزاد نشد؛ رایانه را یک بار ری‌استارت کنید و دوباره اجرا کنید.")
        steps.append("copied")
        cfg = _merge_config(HERE, os.path.join(INSTALL_DIR, "config.json"))
        _run_cmd(["icacls", INSTALL_DIR, "/grant", "*S-1-5-32-545:(OI)(CI)M", "/T", "/C", "/Q"])
        steps.append("config")
        if FROZEN:
            cmd, args = exe_dst, "run"
        else:
            cmd, args = sys.executable.replace("python.exe", "pythonw.exe"), '"%s" run' % exe_dst
        xml_path = os.path.join(INSTALL_DIR, "task.xml")
        with open(xml_path, "w", encoding="utf-16") as f:
            f.write(_task_xml(cmd, args))
        rc, out = _run_cmd(["schtasks", "/Create", "/TN", TASK_NAME, "/XML", xml_path, "/F"])
        _ilog("schtasks /Create -> %s %s" % (rc, out.strip()))
        if rc != 0:
            raise RuntimeError("ثبت در Task Scheduler نشد: " + out.strip()[:300])
        steps.append("task")
        # صفحهٔ تنظیمات از کامپیوترهای دیگرِ شبکهٔ داخلی هم باز شود (بی‌پنجرهٔ فایروال برای کاربر)
        _run_cmd(["netsh", "advfirewall", "firewall", "delete", "rule", "name=" + TASK_NAME])
        _ilog("firewall -> %s" % (_run_cmd(["netsh", "advfirewall", "firewall", "add", "rule", "name=" + TASK_NAME, "dir=in", "action=allow",
                                             "protocol=TCP", "localport=%d" % int(cfg.get("web_port") or DEFAULT_PORT), "profile=private,domain"]),))
        _ilog("schtasks /Run -> %s" % (_run_cmd(["schtasks", "/Run", "/TN", TASK_NAME]),))
        try:
            with open(_shortcut_path(), "w", encoding="utf-8") as f:
                f.write("[InternetShortcut]\nURL=http://localhost:%d\n" % int(cfg.get("web_port") or DEFAULT_PORT))
        except Exception:
            pass
        ok = False
        for i in range(30):
            if _ui_alive():
                ok = True
                break
            if i == 8:   # کار زمان‌بندی‌شده بالا نیامد (مثلاً بی‌نشستِ کاربر): همین‌جا روشنش کن
                _ilog("task did not start; starting directly")
                try:
                    subprocess.Popen(([exe_dst] if FROZEN else [sys.executable, exe_dst]) + ["run"], cwd=INSTALL_DIR, close_fds=True,
                                     stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                     creationflags=getattr(subprocess, "DETACHED_PROCESS", 0) | getattr(subprocess, "CREATE_NEW_PROCESS_GROUP", 0) | getattr(subprocess, "CREATE_NO_WINDOW", 0))
                except Exception as e:
                    _ilog("direct start failed: %s" % e)
            time.sleep(1)
        steps.append("running" if ok else "not-yet")
        _ilog("done: " + ", ".join(steps))
        if not quiet:
            if ok:
                _open_ui()
                _msg("ایستگاه چاپِ فروشگاه نصب و روشن شد.\n\nاز این به بعد با ورود به ویندوز خودش اجرا می‌شود و اگر بسته شود خودش دوباره بالا می‌آید. "
                     "(کارهای چاپی که وقتی کسی وارد ویندوز نشده برسند، در صف می‌مانند و بعد چاپ می‌شوند.)\n\n"
                     "در صفحه‌ای که باز شد (http://localhost:%d) پرینترها را فعال کنید، کاغذِ هرکدام را تعیین کنید و «چاپ آزمایشی» بزنید.\n"
                     "میان‌برِ «Weby Print Station» هم روی دسکتاپ گذاشته شد." % _port())
            else:
                _msg("نصب انجام شد ولی برنامه هنوز جواب نمی‌دهد. چند ثانیهٔ دیگر میان‌برِ «Weby Print Station» روی دسکتاپ را باز کنید.\n"
                     "جزئیات: " + os.path.join(INSTALL_DIR, "agent.log"))
        return 0
    except Exception as e:
        _ilog("FAILED after %s: %s\n%s" % (steps, e, traceback.format_exc()))
        if not quiet:
            _msg("نصب کامل نشد:\n%s\n\nمراحلِ انجام‌شده: %s" % (e, ", ".join(steps) or "-"), err=True)
        print("install failed:", e, steps)
        return 1


def uninstall(quiet=False):
    if IS_WINDOWS and not _is_admin():
        return 0 if _elevate(["uninstall"] + (["--quiet"] if quiet else [])) else 1
    _run_cmd(["schtasks", "/End", "/TN", TASK_NAME])
    _run_cmd(["schtasks", "/Delete", "/TN", TASK_NAME, "/F"])
    _run_cmd(["netsh", "advfirewall", "firewall", "delete", "rule", "name=" + TASK_NAME])
    if FROZEN:
        _kill_others()
    try:
        os.remove(_shortcut_path())
    except Exception:
        pass
    if not quiet:
        _msg("ایستگاه چاپ حذف شد. (تنظیمات در %s ماند)" % INSTALL_DIR)
    return 0


def selftest(out_path):
    """برای آزمونِ خودکار در GitHub Actions و عیب‌یابی: همهٔ بخش‌های لازم را امتحان می‌کند."""
    res = {"version": VERSION, "frozen": FROZEN, "python": sys.version.split()[0], "port": _port(),
           "user": os.environ.get("USERNAME", ""), "ok": True, "checks": {}}

    def chk(name, fn):
        try:
            res["checks"][name] = {"ok": True, "info": fn()}
        except Exception as e:
            res["checks"][name] = {"ok": False, "info": "%s: %s" % (type(e).__name__, e)}
            res["ok"] = False
    chk("ui", lambda: ("%s (%d bytes)" % (_UI_PATH, len(UI))) if _UI_PATH else (_ for _ in ()).throw(RuntimeError("ui.html missing")))
    chk("requests", lambda: requests.__version__)
    chk("flask", lambda: __import__("importlib.metadata").metadata.version("flask"))
    chk("pillow", lambda: __import__("PIL").__version__)
    chk("pypdfium2", lambda: getattr(__import__("pypdfium2"), "V_PDFIUM", "ok"))
    if IS_WINDOWS:
        chk("pywin32", lambda: "%d printer(s)" % len(__import__("win32print").EnumPrinters(6)))
    chk("printers", lambda: system_printers())
    chk("browser", lambda: find_chrome() or (_ for _ in ()).throw(RuntimeError("Edge/Chrome not found")))
    chk("html_to_pdf_and_render", _selftest_render)
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(res, f, ensure_ascii=False, indent=2, default=str)
    return 0 if res["ok"] else 1


def _selftest_render():
    html = ("<!doctype html><html lang='fa' dir='rtl'><head><meta charset='utf-8'><style>@page{size:100mm 150mm;margin:0}"
            "body{margin:0;font:14pt Tahoma}</style></head><body><div style='padding:10mm'>آزمون چاپ ۱۲۳</div></body></html>")
    fd, h = tempfile.mkstemp(suffix=".html")
    with os.fdopen(fd, "w", encoding="utf-8") as f:
        f.write(html)
    fd, pdf = tempfile.mkstemp(suffix=".pdf")
    os.close(fd)
    os.remove(pdf)
    try:
        chrome_to_pdf(find_chrome(), "file:///" + h.replace("\\", "/").lstrip("/"), pdf, None, None)
        import pypdfium2 as pdfium
        doc = pdfium.PdfDocument(pdf)
        img = doc[0].render(scale=100 / 72).to_pil()
        return "pdf %d bytes, %d page(s), %dx%d px" % (os.path.getsize(pdf), len(doc), img.size[0], img.size[1])
    finally:
        for x in (h, pdf):
            try:
                os.remove(x)
            except Exception:
                pass


def _log_to_file():
    """WebyPrint.exe پنجرهٔ متنی ندارد: خروجی به agent.log ِ پوشهٔ برنامه می‌رود."""
    if not FROZEN:
        return
    try:
        path = os.path.join(HERE, "agent.log")
        if os.path.isfile(path) and os.path.getsize(path) > 2 * 1024 * 1024:
            os.replace(path, path + ".1")
        f = open(path, "a", encoding="utf-8", buffering=1)
        sys.stdout = sys.stderr = f
    except Exception:
        pass


def main():
    args = sys.argv[1:]
    quiet = "--quiet" in args
    cmd = next((a for a in args if not a.startswith("-")), "")
    if cmd == "selftest":
        out = args[args.index("--out") + 1] if "--out" in args else os.path.join(HERE, "selftest.json")
        sys.exit(selftest(out))
    if cmd == "install":
        sys.exit(install(quiet))
    if cmd == "uninstall":
        sys.exit(uninstall(quiet))
    if cmd == "" and FROZEN and IS_WINDOWS:
        # دوبار کلیک روی WebyPrint.exe
        installed = os.path.isfile(os.path.join(INSTALL_DIR, EXE_NAME)) and _task_exists()
        here_is_installed = os.path.normcase(HERE) == os.path.normcase(INSTALL_DIR)
        if installed and (here_is_installed or not os.path.isfile(os.path.join(HERE, "config.json"))):
            if not _ui_alive():
                _run_cmd(["schtasks", "/Run", "/TN", TASK_NAME])
                for _ in range(15):
                    if _ui_alive():
                        break
                    time.sleep(1)
            _open_ui()
            return
        sys.exit(install(quiet))
    # cmd == "run" (Task Scheduler) یا «python agent.py»
    if not _single_instance():
        return
    _log_to_file()
    run()


if __name__ == "__main__":
    main()
