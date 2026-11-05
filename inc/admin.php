<?php
// Pearlecent admin: sign-in, sessions, CSRF and image uploads.
declare(strict_types=1);
require __DIR__ . '/boot.php';

const IDLE_LIMIT = 1800;          // sign out after 30 minutes without activity
const ABSOLUTE_LIMIT = 43200;     // and after 12 hours in any case
const MAX_FAILS_IP = 5;           // wrong passwords per 15 minutes from one connection
const MAX_FAILS_USER = 10;        // wrong passwords per 15 minutes for one username
const LOCK_WINDOW = 900;

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function admin_headers(): void
{
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Robots-Tag: noindex, nofollow');
    header('Cache-Control: no-store, max-age=0');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    if (is_https()) header('Strict-Transport-Security: max-age=31536000');
}

function admin_session(): void
{
    $dir = data_dir() . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', (string) ABSOLUTE_LIMIT);
    session_name(is_https() ? '__Host-pcadmin' : 'pcadmin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();

    if (!empty($_SESSION['uid'])) {
        $now = time();
        $ua = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if ($now - ($_SESSION['seen'] ?? 0) > IDLE_LIMIT || $now - ($_SESSION['born'] ?? 0) > ABSOLUTE_LIMIT || ($_SESSION['ua'] ?? '') !== $ua) {
            admin_logout();
            session_start();
            flash('You were signed out. Please sign in again.', 'info');
        } else {
            $_SESSION['seen'] = $now;
        }
    }
}

function admin_logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Strict']);
    }
    session_destroy();
}

function current_admin(): ?array
{
    if (empty($_SESSION['uid'])) return null;
    $s = db()->prepare('SELECT id, username, must_change, last_login FROM admins WHERE id = ?');
    $s->execute([$_SESSION['uid']]);
    return $s->fetch() ?: null;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }

function csrf_ok(): bool
{
    $t = (string) ($_POST['csrf'] ?? '');
    return $t !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t);
}

function flash(string $msg, string $kind = 'ok'): void { $_SESSION['flash'][] = [$kind, $msg]; }

function take_flash(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function login_locked(string $username): bool
{
    $since = time() - LOCK_WINDOW;
    $db = db();
    $db->prepare('DELETE FROM login_attempts WHERE at < ?')->execute([time() - 86400]);
    $s = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip_hash = ? AND at > ?');
    $s->execute([ip_hash(), $since]);
    if ((int) $s->fetchColumn() >= MAX_FAILS_IP) return true;
    $s = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ? COLLATE NOCASE AND at > ?');
    $s->execute([$username, $since]);
    return (int) $s->fetchColumn() >= MAX_FAILS_USER;
}

function try_login(string $username, string $password): bool
{
    $s = db()->prepare('SELECT * FROM admins WHERE username = ? COLLATE NOCASE');
    $s->execute([$username]);
    $a = $s->fetch();
    // Always run a hash check so a wrong username takes as long as a wrong password.
    $hash = $a['pass_hash'] ?? '$2y$12$LvJhKWX9m9/DKrbCA/bx0OWzeGoslsaBMU4N4/CKul2zEhhyqE5rG';
    $ok = password_verify($password, $hash) && $a;
    if (!$ok) {
        db()->prepare('INSERT INTO login_attempts (ip_hash, username, at) VALUES (?,?,?)')->execute([ip_hash(), mb_substr($username, 0, 60), time()]);
        return false;
    }
    if (password_needs_rehash($a['pass_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET pass_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $a['id']]);
    }
    db()->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([ip_hash()]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $a['id'];
    $_SESSION['born'] = $_SESSION['seen'] = time();
    $_SESSION['ua'] = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['prev_login'] = $a['last_login'];
    unset($_SESSION['csrf']);
    db()->prepare('UPDATE admins SET last_login = ? WHERE id = ?')->execute([now_utc(), $a['id']]);
    return true;
}

function password_problem(string $pw, string $username): ?string
{
    if (mb_strlen($pw) < 10) return 'Use at least 10 characters.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Use both letters and numbers.';
    if (stripos($pw, $username) !== false || stripos($pw, 'pearl') !== false || stripos($pw, 'password') !== false) return 'Choose something harder to guess.';
    return null;
}

/**
 * Saves an uploaded photo as a fresh JPEG (re-drawn, so no hidden code or location data survives).
 * Returns [site-relative path, width, height] or throws with a message for the admin.
 */
function save_upload(string $field, string $folder, int $maxSide): ?array
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('The photo did not upload. Try a smaller file (under 8 MB).');
    if ($f['size'] > 8 * 1024 * 1024) throw new RuntimeException('Photo is larger than 8 MB.');
    if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Upload rejected.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Only JPG, PNG or WebP photos are allowed.');
    $info = @getimagesize($f['tmp_name']);
    if (!$info || $info[0] < 200 || $info[1] < 200 || $info[0] * $info[1] > 40000000) throw new RuntimeException('That photo is too small or too large to use.');
    $src = @imagecreatefromstring((string) file_get_contents($f['tmp_name']));
    if (!$src) throw new RuntimeException('That photo could not be read.');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {           // respect phone rotation
        $o = (int) (@exif_read_data($f['tmp_name'])['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($src, $rot, 0); if ($r) { imagedestroy($src); $src = $r; } }
    }
    $w = imagesx($src); $hh = imagesy($src);
    $scale = min(1, $maxSide / max($w, $hh));
    $nw = (int) round($w * $scale); $nh = (int) round($hh * $scale);
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $hh);
    $dir = dirname(__DIR__) . '/uploads/' . $folder;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(12)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $name, 86)) throw new RuntimeException('Could not save the photo on the server.');
    imagedestroy($src); imagedestroy($dst);
    return ['uploads/' . $folder . '/' . $name, $nw, $nh];
}

/** Deletes a previously uploaded file (never touches the built-in assets). */
function delete_upload(?string $path): void
{
    if (!$path || !preg_match('~^uploads/(gallery|dentists)/[a-f0-9]{24}\.jpg$~', $path)) return;
    @unlink(dirname(__DIR__) . '/' . $path);
}

function slugify(string $name): string
{
    $s = strtolower(trim(preg_replace('/^dr\.?\s+/i', '', $name)));
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    return $s !== '' ? substr($s, 0, 40) : 'dentist';
}

function dubai_time(?string $utc, string $fmt = 'j M Y, g:i A'): string
{
    if (!$utc) return '';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('Asia/Dubai'));
    return $d->format($fmt);
}

/** "050 123 4567" -> "971501234567" for a WhatsApp reply link. */
function wa_number(?string $m): string
{
    $d = preg_replace('/\D/', '', (string) $m) ?? '';
    if ($d === '') return '';
    if (strpos($d, '00') === 0) $d = substr($d, 2);
    if (strlen($d) === 10 && $d[0] === '0') $d = '971' . substr($d, 1);
    if (strlen($d) === 9 && $d[0] === '5') $d = '971' . $d;
    return $d;
}
