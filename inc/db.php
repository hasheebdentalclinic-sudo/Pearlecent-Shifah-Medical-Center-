<?php
// Pearlecent: database and storage. Nothing in here is reachable from the web (/inc is blocked in .htaccess).
declare(strict_types=1);

date_default_timezone_set('UTC');

/** Folder for the database, sessions and the secret key. Prefers a folder OUTSIDE the public web root. */
function data_dir(): string
{
    static $dir = null;
    if ($dir !== null) return $dir;
    $web = dirname(__DIR__);
    $candidates = [dirname($web) . '/pearlecent-data', $web . '/data'];
    foreach ($candidates as $c) {
        if (!is_dir($c)) @mkdir($c, 0750, true);
        if (is_dir($c) && is_writable($c)) { $dir = $c; break; }
    }
    if ($dir === null) {
        http_response_code(500);
        exit('Pearlecent: the server has no writable data folder.');
    }
    if (strpos($dir, $web . '/') === 0) {           // fallback inside the web root: lock it down
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function app_secret(): string
{
    static $s = null;
    if ($s !== null) return $s;
    $f = data_dir() . '/secret.key';
    if (!is_file($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
        @chmod($f, 0640);
    }
    return $s = trim((string) file_get_contents($f));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $file = data_dir() . '/pearlecent.sqlite';
    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    @chmod($file, 0640);
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);
    return $pdo;
}

function now_utc(): string { return gmdate('Y-m-d H:i:s'); }

function migrate(PDO $db): void
{
    $db->exec("CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        pass_hash TEXT NOT NULL,
        must_change INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL,
        last_login TEXT)");
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (ip_hash TEXT NOT NULL, username TEXT, at INTEGER NOT NULL)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_attempts ON login_attempts(ip_hash, at)");
    $db->exec("CREATE TABLE IF NOT EXISTS leads (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT 'Appointment request',
        name TEXT, mobile TEXT, treatment TEXT, dentist TEXT, pref_day TEXT, pref_time TEXT,
        page TEXT, ip_hash TEXT, user_agent TEXT,
        status TEXT NOT NULL DEFAULT 'new', note TEXT)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_leads_created ON leads(created_at)");
    $db->exec("CREATE TABLE IF NOT EXISTS dentists (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL, title TEXT NOT NULL, years INTEGER NOT NULL DEFAULT 0,
        photo TEXT, photo_w INTEGER, photo_h INTEGER, pos TEXT NOT NULL DEFAULT '50% 20%',
        bio TEXT, focus TEXT,
        sort INTEGER NOT NULL DEFAULT 0, visible INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    $db->exec("CREATE TABLE IF NOT EXISTS gallery (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL, treatment TEXT,
        before_img TEXT, after_img TEXT NOT NULL,
        sort INTEGER NOT NULL DEFAULT 0, visible INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");

    $seeded = $db->query("SELECT v FROM meta WHERE k = 'seeded'")->fetchColumn();
    if ($seeded) return;
    $seed = json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true);
    $db->beginTransaction();
    $t = now_utc();
    $ins = $db->prepare("INSERT OR IGNORE INTO dentists (slug,name,title,years,photo,photo_w,photo_h,pos,bio,focus,sort,visible,created_at,updated_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?)");
    foreach ($seed['dentists'] as $i => $d) {
        $ins->execute([$d['slug'], $d['name'], $d['title'], $d['years'], $d['photo'], $d['w'], $d['h'], $d['pos'],
            implode("\n\n", $d['bio']), implode("\n", $d['focus']), ($i + 1) * 10, $t, $t]);
    }
    $ins = $db->prepare("INSERT INTO gallery (title,treatment,before_img,after_img,sort,visible,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?)");
    foreach ($seed['gallery'] as $i => $g) {
        $ins->execute([$g['title'], $g['treatment'], $g['before'], $g['after'], ($i + 1) * 10, $t, $t]);
    }
    $a = $seed['admin'];
    $db->prepare("INSERT OR IGNORE INTO admins (username, pass_hash, must_change, created_at) VALUES (?,?,1,?)")
       ->execute([$a['username'], $a['hash'], $t]);
    $db->exec("INSERT INTO meta (k, v) VALUES ('seeded', '1')");
    $db->commit();
}

function client_ip(): string { return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }
function ip_hash(): string { return hash_hmac('sha256', client_ip(), app_secret()); }
