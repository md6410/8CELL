<?php
// لایهٔ پایگاه‌داده (PDO MySQL/MariaDB) + تراکنشِ تودرتو
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = $GLOBALS['CFG']['db'] ?? null;
    if (!$c) throw new RuntimeException('پیکربندیِ پایگاه‌داده یافت نشد');
    $dsn = 'mysql:host=' . ($c['host'] ?? 'localhost') . (!empty($c['port']) ? ';port=' . $c['port'] : '') . ';dbname=' . $c['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET time_zone = '+03:30'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $st = db()->prepare($sql); $st->execute(array_values($p) === $p ? $p : $p); return $st; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function val(string $sql, array $p = []) { $r = q($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
function ins(string $t, array $d): int {
    $cols = array_keys($d);
    q("INSERT INTO `$t` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")", array_values($d));
    return (int)db()->lastInsertId();
}
function upd(string $t, array $d, string $where, array $wp = []): int {
    $set = implode(',', array_map(fn($c) => "`$c`=?", array_keys($d)));
    return q("UPDATE `$t` SET $set WHERE $where", array_merge(array_values($d), $wp))->rowCount();
}
/** تراکنش: یا همه یا هیچ؛ تودرتو با SAVEPOINT */
function tx(callable $fn) {
    static $depth = 0;
    $pdo = db();
    if ($depth == 0) $pdo->beginTransaction(); else $pdo->exec("SAVEPOINT sp$depth");
    $depth++;
    try {
        $r = $fn();
        $depth--;
        if ($depth == 0) $pdo->commit(); else $pdo->exec("RELEASE SAVEPOINT sp$depth");
        return $r;
    } catch (Throwable $e) {
        $depth--;
        if ($depth == 0) { if ($pdo->inTransaction()) $pdo->rollBack(); } else $pdo->exec("ROLLBACK TO SAVEPOINT sp$depth");
        throw $e;
    }
}
function in_list(array $ids): string { return $ids ? implode(',', array_map('intval', $ids)) : '0'; }

/** اجرای مهاجرت‌ها تا آخرین نسخه */
function db_migrate(): void {
    $have = 0;
    try { $have = (int)val("SELECT v FROM settings WHERE k='schema_version'"); } catch (Throwable $e) { $have = 0; }
    $migs = pm_schema();
    $last = count($migs);
    if ($have >= $last) return;
    for ($i = $have; $i < $last; $i++) {
        foreach ($migs[$i] as $sql) db()->exec($sql);
        db()->exec("INSERT INTO settings (k,v) VALUES ('schema_version','" . ($i + 1) . "') ON DUPLICATE KEY UPDATE v=VALUES(v)");
    }
    seed_defaults();
}
