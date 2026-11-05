<?php
// Pearlecent: Web Push to the clinic's phones (Android and iPhone home-screen app).
// Standards: VAPID (RFC 8292) and encrypted payloads, aes128gcm (RFC 8291 / 8188). No outside libraries.
declare(strict_types=1);

const PUSH_CONTACT = 'mailto:pearlescentadmin@dentalclinicuae.com';

function b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

function push_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS push_subs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        endpoint TEXT NOT NULL UNIQUE, p256dh TEXT NOT NULL, auth TEXT NOT NULL,
        label TEXT, admin_id INTEGER, created_at TEXT NOT NULL, last_ok TEXT, fails INTEGER NOT NULL DEFAULT 0)");
}

/** Server key pair, created once and kept in the private data folder. Returns [private PEM, public raw 65 bytes]. */
function vapid_keys(): array
{
    $f = data_dir() . '/vapid.json';
    if (is_file($f)) {
        $j = json_decode((string) file_get_contents($f), true);
        if (!empty($j['private']) && !empty($j['public'])) return [$j['private'], b64u_dec($j['public'])];
    }
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) throw new RuntimeException('This server cannot create notification keys (OpenSSL).');
    openssl_pkey_export($k, $pem);
    $pub = ec_raw_public($k);
    file_put_contents($f, json_encode(['private' => $pem, 'public' => b64u($pub)]), LOCK_EX);
    @chmod($f, 0600);
    return [$pem, $pub];
}

function vapid_public_b64u(): string { return b64u(vapid_keys()[1]); }

function ec_raw_public($key): string
{
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** Wraps a raw uncompressed P-256 point as a PEM public key so OpenSSL can use it. */
function ec_pem_from_raw(string $raw): string
{
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/** ECDSA DER signature -> 64-byte r||s used by JWT ES256. */
function der_to_raw(string $der): string
{
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f;
    $parts = [];
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$o + 1]);
        $v = ltrim(substr($der, $o + 2, $len), "\0");
        $parts[] = str_pad($v, 32, "\0", STR_PAD_LEFT);
        $o += 2 + $len;
    }
    return $parts[0] . $parts[1];
}

function vapid_header(string $endpoint): string
{
    [$pem, $pub] = vapid_keys();
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $in = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => PUSH_CONTACT]));
    if (!openssl_sign($in, $sig, $pem, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Could not sign the notification.');
    return 'vapid t=' . $in . '.' . b64u(der_to_raw($sig)) . ', k=' . b64u($pub);
}

/** Encrypts the message so only the phone that subscribed can read it (RFC 8291, aes128gcm). */
function push_encrypt(string $plain, string $uaPublicB64, string $authB64): string
{
    $uaPub = b64u_dec($uaPublicB64);
    $auth = b64u_dec($authB64);
    if (strlen($uaPub) !== 65 || strlen($auth) !== 16) throw new RuntimeException('Bad subscription keys.');
    $eph = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $asPub = ec_raw_public($eph);
    $shared = openssl_pkey_derive(openssl_pkey_get_public(ec_pem_from_raw($uaPub)), $eph, 32);
    if ($shared === false) throw new RuntimeException('Key agreement failed.');
    $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $auth);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
    $ct = openssl_encrypt($plain . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ct === false) throw new RuntimeException('Encryption failed.');
    return $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;
}

/** Sends one message to every subscribed device. Returns [sent, failed]. */
function push_send_all(array $msg): array
{
    push_table();
    $subs = db()->query('SELECT * FROM push_subs')->fetchAll();
    if (!$subs || !function_exists('curl_multi_init')) return [0, count($subs)];
    $body = json_encode($msg, JSON_UNESCAPED_UNICODE);
    $mh = curl_multi_init();
    $hs = [];
    foreach ($subs as $s) {
        try {
            $payload = push_encrypt($body, $s['p256dh'], $s['auth']);
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_HTTPHEADER => ['Authorization: ' . vapid_header($s['endpoint']), 'Content-Encoding: aes128gcm',
                    'Content-Type: application/octet-stream', 'TTL: 86400', 'Urgency: high', 'Topic: ' . substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($msg['tag'] ?? 'lead')), 0, 32)],
            ]);
            curl_multi_add_handle($mh, $ch);
            $hs[] = [$ch, $s];
        } catch (Throwable $e) { /* skip a broken subscription */ }
    }
    do { $st = curl_multi_exec($mh, $run); if ($run) curl_multi_select($mh, 1.0); } while ($run && $st === CURLM_OK);
    $ok = 0; $bad = 0;
    $db = db();
    foreach ($hs as [$ch, $s]) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code >= 200 && $code < 300) { $ok++; $db->prepare('UPDATE push_subs SET last_ok = ?, fails = 0 WHERE id = ?')->execute([now_utc(), $s['id']]); }
        elseif ($code === 404 || $code === 410) { $bad++; $db->prepare('DELETE FROM push_subs WHERE id = ?')->execute([$s['id']]); }   // phone unsubscribed
        else { $bad++; $db->prepare('UPDATE push_subs SET fails = fails + 1 WHERE id = ?')->execute([$s['id']]); }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return [$ok, $bad];
}

function push_new_lead(array $lead): void
{
    $who = $lead['name'] !== '' ? $lead['name'] : 'Someone';
    $bits = array_filter([$lead['treatment'], $lead['dentist'] ? 'with ' . $lead['dentist'] : '', trim(($lead['pref_day'] ?: '') . ' ' . ($lead['pref_time'] ?: ''))]);
    push_send_all([
        'title' => 'New appointment request',
        'body' => $who . ($bits ? ' · ' . implode(' · ', $bits) : ' sent a booking request.'),
        'tag' => 'lead-' . ($lead['id'] ?? time()),
        'url' => './?p=leads',
    ]);
}
