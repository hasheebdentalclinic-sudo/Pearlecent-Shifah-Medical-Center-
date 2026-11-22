<?php
// Saves a booking request (or a chat-button click) before the visitor is sent on to WhatsApp.
declare(strict_types=1);
require __DIR__ . '/../inc/db.php';
require __DIR__ . '/../inc/push.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(405); header('Allow: POST'); exit; }

// Only accept requests sent from this website.
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$src = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$srcHost = strtolower((string) parse_url($src, PHP_URL_HOST));
$port = parse_url($src, PHP_URL_PORT);
if ($port) $srcHost .= ':' . $port;
if ($host === '' || $srcHost !== $host) { http_response_code(403); exit; }

// Bots fill hidden fields; people don't.
if (trim((string) ($_POST['website'] ?? '')) !== '') { http_response_code(204); exit; }

function field(string $k, int $max): string
{
    $v = trim((string) ($_POST[$k] ?? ''));
    $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v) ?? '';
    return mb_substr($v, 0, $max);
}

$types = ['Appointment request', 'Chat button', 'Treatment enquiry', 'Dentist question', 'Phone call'];
$type = field('type', 40);
if (!in_array($type, $types, true)) $type = 'Appointment request';

$lead = [
    'type' => $type,
    'name' => field('name', 80),
    'mobile' => preg_replace('/[^0-9+ ()-]/', '', field('mobile', 30)) ?? '',
    'treatment' => field('treatment', 120),
    'dentist' => field('dentist', 80),
    'pref_day' => field('day', 60),
    'pref_time' => field('time', 60),
    'page' => field('page', 200),
];

// Where the visitor came from (sent by site.js; every value is checked and shortened).
function clean_url(string $k, int $max): string
{
    $v = field($k, $max);
    if ($v === '') return '';
    if ($k === 'landing_page') return preg_match('#^/[A-Za-z0-9/._~%-]*$#', $v) ? $v : '';
    $p = parse_url($v);
    return ($p && in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) && !empty($p['host'])) ? $v : '';
}
function token(string $k, int $max): string
{
    return preg_replace('/[^A-Za-z0-9._~:+\-\/ |%()=,&@!]/', '', field($k, $max)) ?? '';
}
$att = [
    'gclid' => token('gclid', 200),
    'utm_source' => token('utm_source', 100),
    'utm_medium' => token('utm_medium', 100),
    'utm_campaign' => token('utm_campaign', 150),
    'utm_term' => token('utm_term', 150),
    'referrer' => clean_url('referrer', 300),
    'landing_page' => clean_url('landing_page', 200),
];
$SOURCES = ['Google Ads', 'Facebook/Instagram Ads', 'Google Search (organic)', 'Other website', 'Direct'];
$source = field('source', 40);
if (!in_array($source, $SOURCES, true)) {
    // Old cached script or a bad value: work it out here with the same rules.
    $us = strtolower($att['utm_source']); $um = strtolower($att['utm_medium']);
    $rh = strtolower((string) parse_url($att['referrer'], PHP_URL_HOST));
    if ($att['gclid'] !== '' || ($us === 'google' && in_array($um, ['cpc', 'ppc'], true))) $source = 'Google Ads';
    elseif (in_array($us, ['facebook', 'instagram', 'fb', 'ig'], true)) $source = 'Facebook/Instagram Ads';
    elseif ($rh !== '' && preg_match('/(^|\.)google\.[a-z.]{2,8}$/', $rh)) $source = 'Google Search (organic)';
    elseif ($rh !== '' || $us !== '') $source = 'Other website';
    elseif (isset($_POST['source'])) $source = 'Direct';
    else $source = 'Unknown';
}

$db = db();
$iph = ip_hash();
// At most 15 saved leads per visitor per hour.
$recent = $db->prepare("SELECT COUNT(*) FROM leads WHERE ip_hash = ? AND created_at > ?");
$recent->execute([$iph, gmdate('Y-m-d H:i:s', time() - 3600)]);
if ((int) $recent->fetchColumn() >= 15) { http_response_code(429); exit; }

$db->prepare("INSERT INTO leads (created_at,type,name,mobile,treatment,dentist,pref_day,pref_time,page,ip_hash,user_agent,status,
                                  source,gclid,utm_source,utm_medium,utm_campaign,utm_term,referrer,landing_page)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,'new',?,?,?,?,?,?,?,?)")
   ->execute([now_utc(), $lead['type'], $lead['name'], $lead['mobile'], $lead['treatment'], $lead['dentist'],
              $lead['pref_day'], $lead['pref_time'], $lead['page'], $iph, mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
              $source, $att['gclid'], $att['utm_source'], $att['utm_medium'], $att['utm_campaign'], $att['utm_term'], $att['referrer'], $att['landing_page']]);

$lead['id'] = (int) $db->lastInsertId();

http_response_code(204);
// Answer the visitor first, then tell the clinic's phones.
if ($type === 'Appointment request') {
    ignore_user_abort(true);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    try { push_new_lead($lead); } catch (Throwable $e) { error_log('Pearlecent push: ' . $e->getMessage()); }
}
