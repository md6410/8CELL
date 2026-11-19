#!/usr/bin/env python3
"""ایستگاهِ چاپِ 8CELL — روی رایانهٔ دفتر اجرا می‌شود؛ خودش از سایت کار می‌گیرد (poll). سایت هرگز تماس نمی‌گیرد.

اجرا:  python agent.py            (پیکربندی در config.json کنارِ همین فایل)
config.json نمونه:
{
  "sites": [{"url": "https://example.ir/8cell", "token": "توکنی که سایت داد"}],
  "chrome": "C:/Program Files/Google/Chrome/Application/chrome.exe",
  "sumatra": "C:/Program Files/SumatraPDF/SumatraPDF.exe",
  "printers": [{"name": "Xprinter XP-370B", "papers": [[50, 30], [100, 150]], "driver": "gdi"},
               {"name": "HP LaserJet", "papers": [[210, 297]], "driver": "gdi"}],
  "default_printer": "HP LaserJet",
  "poll_seconds": 3
}
فقط کتابخانهٔ استانداردِ پایتون ۳ لازم است. رندر با کرومِ بی‌سر (@page دقیقِ اندازهٔ برچسب) و چاپ با SumatraPDF (ویندوز) یا lp (لینوکس/مک).
"""
import json, os, platform, subprocess, sys, tempfile, time, urllib.request, urllib.error

VERSION = "1.0.0"
HERE = os.path.dirname(os.path.abspath(__file__))
CONFIG = json.load(open(os.path.join(HERE, "config.json"), encoding="utf-8"))
STD_PAPERS = {(210, 297): "A4", (148, 210): "A5", (105, 148): "A6"}


def log(*a):
    print(time.strftime("%H:%M:%S"), *a, flush=True)


def api(site, do, payload=None, method="POST"):
    url = site["url"].rstrip("/") + "/api.php?a=" + do
    data = json.dumps(payload or {}).encode("utf-8") if method == "POST" else None
    req = urllib.request.Request(url, data=data, method=method, headers={
        "Content-Type": "application/json", "X-Station-Token": site["token"], "X-Agent-Version": VERSION})
    with urllib.request.urlopen(req, timeout=20) as r:
        j = json.loads(r.read().decode("utf-8"))
    if not j.get("ok"):
        raise RuntimeError(j.get("error", "خطای سایت"))
    return j.get("data")


def paper_matches(paper, w, h):
    return abs(paper[0] - w) < 1.5 and abs(paper[1] - h) < 1.5


def pick_printer(job):
    printers = CONFIG.get("printers", [])
    if job.get("printer"):
        for p in printers:
            if p["name"] == job["printer"]:
                return p
        raise RuntimeError("پرینترِ «%s» روی این ایستگاه نیست" % job["printer"])
    for p in printers:  # اولین پرینتری که کاغذِ همین اندازه را دارد
        if any(paper_matches(pp, job["paper_w"], job["paper_h"]) for pp in p.get("papers", [])):
            return p
    raise RuntimeError("هیچ پرینتری کاغذِ %sx%s میلی‌متر ندارد" % (job["paper_w"], job["paper_h"]))


def html_to_pdf(url, out):
    chrome = CONFIG.get("chrome") or ("chrome" if platform.system() == "Windows" else "chromium")
    cmd = [chrome, "--headless=new", "--disable-gpu", "--no-sandbox", "--no-pdf-header-footer", "--virtual-time-budget=4000",
           "--print-to-pdf=" + out, url]
    subprocess.run(cmd, check=True, timeout=90, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    if not os.path.exists(out) or os.path.getsize(out) < 500:
        raise RuntimeError("رندرِ PDF ناموفق بود")


def send_to_printer(pdf, printer, job):
    copies = max(1, int(job.get("copies", 1)))
    if platform.system() == "Windows":
        sumatra = CONFIG.get("sumatra") or "SumatraPDF.exe"
        settings = "noscale,%dx" % copies
        std = STD_PAPERS.get((round(job["paper_w"]), round(job["paper_h"])))
        if std:
            settings += ",paper=" + std
        subprocess.run([sumatra, "-print-to", printer["name"], "-print-settings", settings, "-silent", pdf], check=True, timeout=120)
    else:
        subprocess.run(["lp", "-d", printer["name"], "-n", str(copies), "-o", "media=Custom.%gx%gmm" % (job["paper_w"], job["paper_h"]),
                        "-o", "fit-to-page=false", pdf], check=True, timeout=60)


def report(site, job_id, status, error="", tries=4):
    for i in range(tries):  # وضعیتِ نهایی با تلاشِ دوباره می‌رسد
        try:
            api(site, "agent_report", {"job_id": job_id, "status": status, "error": error})
            return True
        except Exception as e:
            log("گزارش نرسید (%d): %s" % (i + 1, e))
            time.sleep(2 * (i + 1))
    return False


def hello(site):
    papers = []
    for p in CONFIG.get("printers", []):
        for pp in p.get("papers", []):
            if [pp[0], pp[1]] not in [[x["w"], x["h"]] for x in papers]:
                papers.append({"w": pp[0], "h": pp[1]})
    api(site, "agent_hello", {"printers": [{"name": p["name"]} for p in CONFIG.get("printers", [])], "papers": papers})


def handle(site, job):
    log("کار #%s: %s" % (job["id"], job.get("title") or job["doc_type"]))
    try:
        printer = pick_printer(job)
        report(site, job["id"], "printing")
        with tempfile.TemporaryDirectory() as d:
            pdf = os.path.join(d, "job.pdf")
            html_to_pdf(job["url"], pdf)
            send_to_printer(pdf, printer, job)
        report(site, job["id"], "done")
        log("چاپ شد روی", printer["name"])
    except Exception as e:
        log("خطا:", e)
        report(site, job["id"], "error", str(e)[:400])


def main():
    sites = CONFIG.get("sites", [])
    if not sites:
        sys.exit("در config.json هیچ سایتی نیست")
    for s in sites:
        try:
            hello(s)
            log("وصل شد:", s["url"])
        except Exception as e:
            log("اتصال به", s["url"], "ناموفق:", e)
    last_hello = time.time()
    while True:
        for s in sites:
            try:
                r = api(s, "agent_poll", {})
                if r and r.get("job"):
                    handle(s, r["job"])
                    continue
            except urllib.error.URLError as e:
                log("شبکه:", e)
            except Exception as e:
                log("خطا:", e)
        if time.time() - last_hello > 300:
            for s in sites:
                try: hello(s)
                except Exception: pass
            last_hello = time.time()
        time.sleep(CONFIG.get("poll_seconds", 3))


if __name__ == "__main__":
    main()
