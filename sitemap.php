<?php
// XML sitemap, built from the live list of dentists so new profiles appear automatically.
require __DIR__ . '/inc/boot.php';
header('Content-Type: application/xml; charset=utf-8');
$base = 'https://pearlecentdental.com';
$ts = static function (string $f): string { return gmdate('Y-m-d', (int) @filemtime(__DIR__ . '/' . $f)); };
$urls = [[$base . '/', $ts('index.php'), '1.0'], [$base . '/treatments.html', $ts('treatments.php'), '0.9'], [$base . '/about.html', $ts('about.php'), '0.7']];
foreach (dentists_visible() as $d) $urls[] = [$base . '/dentists/' . rawurlencode($d['slug']) . '.html', substr((string) $d['updated_at'], 0, 10), '0.8'];
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', "\n";
foreach ($urls as [$loc, $mod, $pri]) echo '  <url><loc>', h($loc), '</loc><lastmod>', h($mod), '</lastmod><priority>', $pri, '</priority></url>', "\n";
echo '</urlset>', "\n";
