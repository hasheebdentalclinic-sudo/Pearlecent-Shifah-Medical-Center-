<?php
// Pearlecent: parts of the public pages that come from the database.
declare(strict_types=1);

const STAR_SVG = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 1.5l2.6 5.6 6.1.7-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6L1.3 7.8l6.1-.7z"/></svg>';

function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function stars_html(): string { return '<span class="stars" aria-label="5 out of 5 stars">' . str_repeat(STAR_SVG, 5) . '</span>'; }

/** Site-relative path ("assets/..." or "uploads/...") to a URL from the current page. */
function asset_url(?string $path, string $up = ''): string
{
    $path = (string) $path;
    if ($path === '') return '';
    if (preg_match('~^https?://~', $path)) return $path;
    return $up . ltrim($path, '/');
}

function dentists_visible(): array
{
    static $rows = null;
    if ($rows === null) $rows = db()->query("SELECT * FROM dentists WHERE visible = 1 ORDER BY sort, id")->fetchAll();
    return $rows;
}

function dentist_by_slug(string $slug): ?array
{
    foreach (dentists_visible() as $d) if ($d['slug'] === $slug) return $d;
    return null;
}

function dentist_count(): int { return count(dentists_visible()); }

/** "Dr. Ankur Agarawal" -> "Ankur" */
function first_name(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    if ($parts && preg_match('/^dr\.?$/i', $parts[0])) array_shift($parts);
    return $parts[0] ?? $name;
}

function lines(?string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $s) ?: []), 'strlen'));
}

function paragraphs(?string $s): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', (string) $s) ?: []), 'strlen'));
}

function render_doc_cards(string $up = '', ?string $skip = null): string
{
    $out = '';
    $i = 0;
    foreach (dentists_visible() as $d) {
        if ($d['slug'] === $skip) continue;
        $w = (int) ($d['photo_w'] ?: 900); $hh = (int) ($d['photo_h'] ?: 1200);
        $out .= '
      <a class="dcard" href="' . h($up . 'dentists/' . $d['slug'] . '.html') . '" data-reveal style="--n:' . ($i % 5) . '">
        <span class="dcard__pic"><img src="' . h(asset_url($d['photo'], $up)) . '" alt="Portrait of ' . h($d['name']) . '" width="' . $w . '" height="' . $hh . '" loading="lazy" style="object-position:' . h($d['pos']) . '"></span>
        <span class="dcard__name">' . h($d['name']) . '</span>
        <span class="dcard__meta">' . h($d['title']) . '</span>
        <span class="dcard__yrs">' . (int) $d['years'] . ' years of experience</span>
      </a>';
        $i++;
    }
    return $out;
}

function render_dentist_options(): string
{
    $o = '<option value="">Any dentist</option>';
    foreach (dentists_visible() as $d) $o .= '<option value="' . h($d['name']) . '">' . h($d['name']) . '</option>';
    return $o;
}

function reviews_all(): array
{
    static $r = null;
    if ($r === null) {
        $seed = json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true);
        $r = $seed['reviews'] ?? [];
    }
    return $r;
}

function reviews_for(string $slug): array
{
    return array_values(array_filter(reviews_all(), fn($r) => ($r['doctor'] ?? null) === $slug));
}

function render_review_slides(string $up = ''): string
{
    $out = '';
    $all = reviews_all();
    $n = count($all);
    foreach ($all as $i => $r) {
        $d = $r['doctor'] ? dentist_by_slug($r['doctor']) : null;
        if ($d) {
            $img = '<img src="' . h(asset_url($d['photo'], $up)) . '" alt="' . h($d['name']) . '" width="' . (int) $d['photo_w'] . '" height="' . (int) $d['photo_h'] . '" loading="lazy" style="object-position:' . h($d['pos']) . '">';
            $about = $d['name'];
        } else {
            $img = '<img src="' . h($up) . 'assets/img/clinic/reception.jpg" alt="The Pearlecent reception" width="1600" height="800" loading="lazy" style="object-position:30% 50%">';
            $about = 'The Pearlecent team';
        }
        $out .= '
        <li class="slide' . ($i === 0 ? ' is-on' : '') . '" data-slide aria-roledescription="slide" aria-label="' . ($i + 1) . ' of ' . $n . '">
          <figure class="slide__pic">' . $img . '<figcaption>' . h($about) . '</figcaption></figure>
          <blockquote class="slide__quote">' . stars_html() . '<p>“' . h($r['text']) . '”</p><footer><b>' . h($r['who']) . '</b> · Google review</footer></blockquote>
        </li>';
    }
    return $out;
}

function gallery_visible(): array
{
    return db()->query("SELECT * FROM gallery WHERE visible = 1 ORDER BY sort, id")->fetchAll();
}

function render_gallery(string $up = ''): string
{
    $rows = gallery_visible();
    if (!$rows) return '';
    $cards = '';
    foreach ($rows as $i => $g) {
        $title = h($g['title']);
        $treat = $g['treatment'] ? '<span>' . h($g['treatment']) . '</span>' : '';
        if ($g['before_img']) {
            $cards .= '
      <figure class="bacase" data-ba data-reveal style="--n:' . ($i % 3) . '">
        <div class="bacase__frame">
          <img class="bacase__img" src="' . h(asset_url($g['after_img'], $up)) . '" alt="After treatment: ' . $title . '" loading="lazy" width="640" height="640">
          <img class="bacase__img bacase__img--before" src="' . h(asset_url($g['before_img'], $up)) . '" alt="Before treatment: ' . $title . '" loading="lazy" width="640" height="640">
          <span class="bacase__tag bacase__tag--b">Before</span><span class="bacase__tag bacase__tag--a">After</span>
          <span class="bacase__handle" aria-hidden="true"><span class="bacase__knob"><svg viewBox="0 0 24 24"><path d="M9 6l-5 6 5 6M15 6l5 6-5 6"/></svg></span></span>
          <input class="bacase__range" type="range" min="0" max="100" value="50" aria-label="Compare before and after: ' . $title . '">
        </div>
        <figcaption><b>' . $title . '</b>' . $treat . '</figcaption>
      </figure>';
        } else {
            $cards .= '
      <figure class="bacase bacase--single" data-reveal style="--n:' . ($i % 3) . '">
        <div class="bacase__frame"><img class="bacase__img" src="' . h(asset_url($g['after_img'], $up)) . '" alt="' . $title . '" loading="lazy" width="640" height="640"></div>
        <figcaption><b>' . $title . '</b>' . $treat . '</figcaption>
      </figure>';
        }
    }
    return '<section class="ba" id="results" aria-labelledby="ba-h">
  <div class="wrap">
    <div class="sec-head">
      <h2 id="ba-h" data-reveal class="cats__h">Smile <em>Transformations</em></h2>
      <p data-reveal>Drag the slider to compare before and after.</p>
    </div>
    <div class="ba__track">' . $cards . '
    </div>
    <p class="ba__note" data-reveal>Real Pearlecent patients, shown with their written consent. Results vary from person to person; your dentist explains the benefits, risks and alternatives before treatment.</p>
  </div>
</section>';
}
