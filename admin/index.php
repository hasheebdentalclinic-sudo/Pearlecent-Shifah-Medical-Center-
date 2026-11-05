<?php
// Pearlecent admin panel: leads, before/after gallery, dentists, account.
declare(strict_types=1);
require __DIR__ . '/../inc/admin.php';

admin_headers();
admin_session();

$page = (string) ($_GET['p'] ?? 'leads');
$me = current_admin();
$db = db();

function go(string $to): void { header('Location: ' . $to, true, 303); exit; }

$STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'booked' => 'Booked', 'closed' => 'Closed'];
$TREATMENTS = ['General Dentistry', 'Restorative Dentistry', 'Cosmetic Dentistry', 'Orthodontics', 'Braces', 'Clear Aligners / Invisalign',
               'Teeth Whitening', 'Veneers', 'Crowns & Bridges', 'Root Canal Treatment', 'Dentures & Retainers', 'Smile Makeover'];
$TITLES = ['General Dentist', 'Orthodontic Specialist', 'Medical Director & General Dentist'];
$POSITIONS = ['50% 15%' => 'Top (face near the top)', '50% 25%' => 'Upper middle', '50% 40%' => 'Middle'];

// ------------------------------------------------------------ sign in / out
if (!$me) {
    $error = '';
    $username = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $username = mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 60);
        $password = (string) ($_POST['password'] ?? '');
        if (!csrf_ok()) {
            $error = 'The form expired. Please try again.';
        } elseif (login_locked($username)) {
            $error = 'Too many wrong attempts. Please wait 15 minutes and try again.';
        } elseif ($username === '' || $password === '' || !try_login($username, $password)) {
            usleep(random_int(200000, 500000));
            $error = 'Username or password is not correct.';
        } else {
            go('./');
        }
    }
    login_view($error, $username);
    exit;
}

if ($page === 'logout' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (csrf_ok()) admin_logout();
    go('./');
}

if ((int) $me['must_change'] === 1 && $page !== 'account') {
    flash('Please choose your own password before you continue.', 'info');
    go('?p=account');
}

// ------------------------------------------------------------ CSV export
if ($page === 'leads' && isset($_GET['export'])) {
    [$where, $args] = lead_filters();
    $s = $db->prepare("SELECT * FROM leads $where ORDER BY id DESC");
    $s->execute($args);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pearlecent-leads-' . gmdate('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    fputcsv($out, ['Date (Dubai)', 'Type', 'Name', 'Mobile', 'Treatment', 'Dentist', 'Preferred day', 'Preferred time', 'Status', 'Note', 'Page']);
    while ($r = $s->fetch()) {
        fputcsv($out, array_map($safe, [dubai_time($r['created_at'], 'Y-m-d H:i'), $r['type'], $r['name'], $r['mobile'], $r['treatment'], $r['dentist'], $r['pref_day'], $r['pref_time'], $STATUSES[$r['status']] ?? $r['status'], $r['note'], $r['page']]));
    }
    exit;
}

// ------------------------------------------------------------ actions (POST, then redirect)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_ok()) { flash('The form expired. Please try again.', 'err'); go('?p=' . urlencode($page)); }
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $back = (string) ($_POST['back'] ?? ('?p=' . $page));
    if (!preg_match('~^\?p=[a-z]+(&[a-z_]+=[A-Za-z0-9_%+.-]*)*$~', $back)) $back = '?p=' . $page;
    try {
        switch ($action) {
            case 'lead_status':
                $st = (string) ($_POST['status'] ?? '');
                if (isset($STATUSES[$st])) $db->prepare('UPDATE leads SET status = ? WHERE id = ?')->execute([$st, $id]);
                flash('Lead updated.');
                go($back);
            case 'lead_note':
                $db->prepare('UPDATE leads SET note = ? WHERE id = ?')->execute([mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 1000), $id]);
                flash('Note saved.');
                go($back);
            case 'lead_delete':
                $db->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
                flash('Lead deleted.');
                go($back);

            case 'gallery_save':
                $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80);
                $treat = mb_substr(trim((string) ($_POST['treatment'] ?? '')), 0, 80);
                $vis = isset($_POST['visible']) ? 1 : 0;
                if ($title === '') throw new RuntimeException('Give the case a short title, for example “Case 03”.');
                $old = null;
                if ($id) { $s = $db->prepare('SELECT * FROM gallery WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(); if (!$old) go('?p=gallery'); }
                if (!$old && !isset($_POST['consent'])) throw new RuntimeException('Please confirm the patient gave written consent before adding their photos.');
                $before = save_upload('before', 'gallery', 1400);
                $after = save_upload('after', 'gallery', 1400);
                if (!$old && !$after) throw new RuntimeException('Add the “After” photo.');
                $beforePath = $before[0] ?? ($old['before_img'] ?? null);
                if ($old && isset($_POST['remove_before'])) { delete_upload($old['before_img']); $beforePath = null; }
                if ($old) {
                    if ($before) delete_upload($old['before_img']);
                    if ($after) delete_upload($old['after_img']);
                    $db->prepare('UPDATE gallery SET title=?, treatment=?, before_img=?, after_img=?, visible=?, updated_at=? WHERE id=?')
                       ->execute([$title, $treat, $beforePath, $after[0] ?? $old['after_img'], $vis, now_utc(), $id]);
                    flash('Case updated.');
                } else {
                    $sort = (int) $db->query('SELECT COALESCE(MAX(sort),0)+10 FROM gallery')->fetchColumn();
                    $db->prepare('INSERT INTO gallery (title,treatment,before_img,after_img,sort,visible,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?)')
                       ->execute([$title, $treat, $beforePath, $after[0], $sort, $vis, now_utc(), now_utc()]);
                    flash('Case added to the website.');
                }
                go('?p=gallery');
            case 'gallery_delete':
                $s = $db->prepare('SELECT * FROM gallery WHERE id = ?'); $s->execute([$id]);
                if ($g = $s->fetch()) { delete_upload($g['before_img']); delete_upload($g['after_img']); $db->prepare('DELETE FROM gallery WHERE id = ?')->execute([$id]); }
                flash('Case removed.');
                go('?p=gallery');
            case 'gallery_toggle':
                $db->prepare('UPDATE gallery SET visible = 1 - visible, updated_at = ? WHERE id = ?')->execute([now_utc(), $id]);
                flash('Saved.');
                go('?p=gallery');
            case 'gallery_move':
                move_row('gallery', $id, (string) ($_POST['dir'] ?? ''));
                go('?p=gallery');

            case 'dentist_save':
                $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
                $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 80);
                $years = max(0, min(60, (int) ($_POST['years'] ?? 0)));
                $bio = mb_substr(trim(str_replace("\r", '', (string) ($_POST['bio'] ?? ''))), 0, 4000);
                $focus = mb_substr(trim(str_replace("\r", '', (string) ($_POST['focus'] ?? ''))), 0, 2000);
                $pos = (string) ($_POST['pos'] ?? '50% 20%');
                if (!isset($POSITIONS[$pos]) && $pos !== '50% 20%' && !preg_match('/^\d{1,3}% \d{1,3}%$/', $pos)) $pos = '50% 20%';
                $vis = isset($_POST['visible']) ? 1 : 0;
                if ($name === '' || $title === '') throw new RuntimeException('Name and title are required.');
                $old = null;
                if ($id) { $s = $db->prepare('SELECT * FROM dentists WHERE id = ?'); $s->execute([$id]); $old = $s->fetch(); if (!$old) go('?p=dentists'); }
                $photo = save_upload('photo', 'dentists', 1200);
                if (!$old && !$photo) throw new RuntimeException('Add a photo of the dentist.');
                if ($old) {
                    if ($photo) delete_upload($old['photo']);
                    $db->prepare('UPDATE dentists SET name=?, title=?, years=?, bio=?, focus=?, pos=?, visible=?, photo=?, photo_w=?, photo_h=?, updated_at=? WHERE id=?')
                       ->execute([$name, $title, $years, $bio, $focus, $pos, $vis, $photo[0] ?? $old['photo'], $photo[1] ?? $old['photo_w'], $photo[2] ?? $old['photo_h'], now_utc(), $id]);
                    flash('Dentist updated.');
                } else {
                    $base = slugify($name); $slug = $base; $n = 2;
                    $chk = $db->prepare('SELECT 1 FROM dentists WHERE slug = ?');
                    while (true) { $chk->execute([$slug]); if (!$chk->fetchColumn()) break; $slug = $base . '-' . $n++; }
                    $sort = (int) $db->query('SELECT COALESCE(MAX(sort),0)+10 FROM dentists')->fetchColumn();
                    $db->prepare('INSERT INTO dentists (slug,name,title,years,photo,photo_w,photo_h,pos,bio,focus,sort,visible,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                       ->execute([$slug, $name, $title, $years, $photo[0], $photo[1], $photo[2], $pos, $bio, $focus, $sort, $vis, now_utc(), now_utc()]);
                    flash('Dentist added to the website.');
                }
                go('?p=dentists');
            case 'dentist_delete':
                $s = $db->prepare('SELECT * FROM dentists WHERE id = ?'); $s->execute([$id]);
                if ($d = $s->fetch()) { delete_upload($d['photo']); $db->prepare('DELETE FROM dentists WHERE id = ?')->execute([$id]); }
                flash('Dentist removed from the website.');
                go('?p=dentists');
            case 'dentist_toggle':
                $db->prepare('UPDATE dentists SET visible = 1 - visible, updated_at = ? WHERE id = ?')->execute([now_utc(), $id]);
                flash('Saved.');
                go('?p=dentists');
            case 'dentist_move':
                move_row('dentists', $id, (string) ($_POST['dir'] ?? ''));
                go('?p=dentists');

            case 'password':
                $cur = (string) ($_POST['current'] ?? '');
                $new = (string) ($_POST['new'] ?? '');
                $rep = (string) ($_POST['repeat'] ?? '');
                $s = $db->prepare('SELECT pass_hash FROM admins WHERE id = ?'); $s->execute([$me['id']]);
                if (!password_verify($cur, (string) $s->fetchColumn())) throw new RuntimeException('Your current password is not correct.');
                if ($new !== $rep) throw new RuntimeException('The two new passwords do not match.');
                if ($new === $cur) throw new RuntimeException('Choose a password different from the current one.');
                if ($p = password_problem($new, $me['username'])) throw new RuntimeException($p);
                $db->prepare('UPDATE admins SET pass_hash = ?, must_change = 0 WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                session_regenerate_id(true);
                flash('Password changed.');
                go('?p=leads');
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'err');
        $_SESSION['form'] = $_POST;
        unset($_SESSION['form']['csrf'], $_SESSION['form']['current'], $_SESSION['form']['new'], $_SESSION['form']['repeat']);
        go($back);
    }
    go('?p=' . urlencode($page));
}

function move_row(string $table, int $id, string $dir): void
{
    $db = db();
    $rows = $db->query("SELECT id FROM $table ORDER BY sort, id")->fetchAll(PDO::FETCH_COLUMN);
    $i = array_search($id, array_map('intval', $rows), true);
    if ($i === false) return;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($rows)) return;
    [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
    $u = $db->prepare("UPDATE $table SET sort = ? WHERE id = ?");
    foreach ($rows as $k => $rid) $u->execute([($k + 1) * 10, $rid]);
}

function lead_filters(): array
{
    $w = []; $a = [];
    $type = (string) ($_GET['type'] ?? 'requests');
    if ($type === 'requests') $w[] = "type = 'Appointment request'";
    elseif ($type === 'chats') $w[] = "type <> 'Appointment request'";
    $st = (string) ($_GET['status'] ?? '');
    if ($st !== '') { $w[] = 'status = ?'; $a[] = $st; }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') { $w[] = '(name LIKE ? OR mobile LIKE ? OR treatment LIKE ? OR dentist LIKE ?)'; $like = '%' . $q . '%'; array_push($a, $like, $like, $like, $like); }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $a];
}

function old(string $k, $fallback = '') { return $_SESSION['form'][$k] ?? $fallback; }

// ------------------------------------------------------------ views
function icon(string $n): string
{
    $p = [
        'leads' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.4 3.4c-.5.4-1.1 0-1.1-.6V17A2.5 2.5 0 0 1 4 14.5z"/>',
        'gallery' => '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M12 4v16"/><path d="M7 10l-2 2 2 2M17 10l2 2-2 2"/>',
        'dentists' => '<circle cx="12" cy="8" r="4"/><path d="M4 20c1.5-4 4.5-6 8-6s6.5 2 8 6"/>',
        'account' => '<rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'site' => '<path d="M14 4h6v6M20 4l-9 9M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>',
        'out' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/>',
        'up' => '<path d="M6 14l6-6 6 6"/>', 'down' => '<path d="M6 10l6 6 6-6"/>',
        'call' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'chat' => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.4 3.4c-.5.4-1.1 0-1.1-.6V17A2.5 2.5 0 0 1 4 14.5z"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>', 'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/>', 'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'trash' => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>', 'down2' => '<path d="M12 4v12M6 11l6 6 6-6M5 20h14"/>',
    ][$n] ?? '';
    return '<svg class="ic" viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}

function shell_top(string $title, string $active, array $me): void
{
    $new = (int) db()->query("SELECT COUNT(*) FROM leads WHERE status = 'new' AND type = 'Appointment request'")->fetchColumn();
    $nav = [['leads', 'Leads'], ['gallery', 'Gallery'], ['dentists', 'Dentists'], ['account', 'Account']];
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · Pearlecent admin</title>
<link rel="icon" href="../assets/img/mark.svg" type="image/svg+xml">
<link rel="stylesheet" href="admin.css?v=1">
<script src="admin.js?v=1" defer></script>
</head>
<body class="adm">
<aside class="side">
  <a class="side__brand" href="?p=leads"><img src="../assets/img/mark.svg" alt="" width="30" height="33"><span>Pearlecent<small>Admin panel</small></span></a>
  <nav class="side__nav" aria-label="Admin">
    <?php foreach ($nav as [$k, $label]): ?>
      <a class="navl<?= $active === $k ? ' is-on' : '' ?>" href="?p=<?= $k ?>"<?= $active === $k ? ' aria-current="page"' : '' ?>><?= icon($k) ?><span><?= $label ?></span><?php if ($k === 'leads' && $new): ?><b class="badge"><?= $new ?></b><?php endif; ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="side__foot">
    <a class="navl" href="../" target="_blank" rel="noopener"><?= icon('site') ?><span>View website</span></a>
    <form method="post" action="?p=logout"><?= csrf_field() ?><button class="navl" type="submit"><?= icon('out') ?><span>Sign out</span></button></form>
    <p class="side__who">Signed in as <b><?= h($me['username']) ?></b></p>
  </div>
</aside>
<main class="main">
<?php foreach (take_flash() as [$k, $m]): ?><p class="flash flash--<?= h($k) ?>" role="status"><?= h($m) ?></p><?php endforeach;
}

function shell_bottom(): void
{
    unset($_SESSION['form']);
    echo "</main>\n</body>\n</html>";
}

function login_view(string $error, string $username): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · Pearlecent admin</title>
<link rel="icon" href="../assets/img/mark.svg" type="image/svg+xml">
<link rel="stylesheet" href="admin.css?v=1">
</head>
<body class="adm adm--login">
<main class="login">
  <form class="login__card" method="post" action="./" autocomplete="on">
    <img src="../assets/img/mark.svg" alt="" width="54" height="59">
    <h1>Pearlecent admin</h1>
    <p class="muted">Sign in to manage leads, the gallery and dentists.</p>
    <?php foreach (take_flash() as [$k, $m]): ?><p class="flash flash--<?= h($k) ?>"><?= h($m) ?></p><?php endforeach; ?>
    <?php if ($error): ?><p class="flash flash--err" role="alert"><?= h($error) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <label class="fl"><span>Username</span><input name="username" value="<?= h($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
    <label class="fl"><span>Password</span><input name="password" type="password" autocomplete="current-password" required></label>
    <button class="btn btn--wide" type="submit">Sign in</button>
    <p class="login__foot muted">For clinic staff only. Sign-ins are protected and locked after repeated wrong passwords.</p>
  </form>
</main>
</body>
</html><?php
}

// ------------------------------------------------------------ pages
if ($page === 'gallery') {
    $editId = (int) ($_GET['edit'] ?? 0);
    $adding = isset($_GET['new']);
    $edit = null;
    if ($editId) { $s = $db->prepare('SELECT * FROM gallery WHERE id = ?'); $s->execute([$editId]); $edit = $s->fetch() ?: null; }
    $rows = $db->query('SELECT * FROM gallery ORDER BY sort, id')->fetchAll();
    shell_top('Gallery', 'gallery', $me);
    ?>
  <header class="ph">
    <div><h1>Before &amp; after gallery</h1><p class="muted">Shown on the home page under the dentists. Change the order with the arrows.</p></div>
    <a class="btn" href="?p=gallery&amp;new=1"><?= icon('plus') ?>Add a case</a>
  </header>

  <?php if ($adding || $edit): $g = $edit ?? []; ?>
  <section class="card form-card">
    <h2><?= $edit ? 'Edit case' : 'New case' ?></h2>
    <form method="post" enctype="multipart/form-data" class="grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="gallery_save">
      <input type="hidden" name="id" value="<?= (int) ($g['id'] ?? 0) ?>">
      <input type="hidden" name="back" value="?p=gallery<?= $edit ? '&edit=' . (int) $g['id'] : '&new=1' ?>">
      <label class="fl"><span>Title</span><input name="title" maxlength="80" required value="<?= h(old('title', $g['title'] ?? '')) ?>" placeholder="Case 03"></label>
      <label class="fl"><span>Treatment <em>(optional)</em></span><input name="treatment" maxlength="80" list="treat-list" value="<?= h(old('treatment', $g['treatment'] ?? '')) ?>" placeholder="Veneers"></label>
      <datalist id="treat-list"><?php foreach ($TREATMENTS as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist>
      <div class="pick">
        <span class="pick__l">Before photo <em>(optional)</em></span>
        <label class="drop" data-drop><input type="file" name="before" accept="image/jpeg,image/png,image/webp">
          <?php if (!empty($g['before_img'])): ?><img src="../<?= h($g['before_img']) ?>" alt=""><?php else: ?><span class="drop__ph">Tap to choose a photo</span><?php endif; ?></label>
        <?php if (!empty($g['before_img'])): ?><label class="check"><input type="checkbox" name="remove_before"> Remove the before photo (show the after photo alone)</label><?php endif; ?>
      </div>
      <div class="pick">
        <span class="pick__l">After photo<?= $edit ? '' : ' <b>*</b>' ?></span>
        <label class="drop" data-drop><input type="file" name="after" accept="image/jpeg,image/png,image/webp"<?= $edit ? '' : ' required' ?>>
          <?php if (!empty($g['after_img'])): ?><img src="../<?= h($g['after_img']) ?>" alt=""><?php else: ?><span class="drop__ph">Tap to choose a photo</span><?php endif; ?></label>
      </div>
      <p class="hint full">Use two photos taken from the same angle and light. Square photos work best. JPG, PNG or WebP, up to 8 MB.</p>
      <label class="check full"><input type="checkbox" name="visible"<?= ($g['visible'] ?? 1) ? ' checked' : '' ?>> Show on the website</label>
      <?php if (!$edit): ?><label class="check full"><input type="checkbox" name="consent" required> The patient gave written consent to show these photos.</label><?php endif; ?>
      <div class="actions full"><button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Add to website' ?></button><a class="btn btn--ghost" href="?p=gallery">Cancel</a></div>
    </form>
  </section>
  <?php endif; ?>

  <?php if (!$rows): ?><p class="empty card">No cases yet. Add the first one.</p><?php endif; ?>
  <div class="glist">
    <?php foreach ($rows as $i => $g): ?>
    <article class="card gcase<?= $g['visible'] ? '' : ' is-hidden' ?>">
      <div class="gcase__pics">
        <?php if ($g['before_img']): ?><figure><img src="../<?= h($g['before_img']) ?>" alt="" loading="lazy"><figcaption>Before</figcaption></figure><?php endif; ?>
        <figure><img src="../<?= h($g['after_img']) ?>" alt="" loading="lazy"><figcaption>After</figcaption></figure>
      </div>
      <div class="gcase__body">
        <h3><?= h($g['title']) ?></h3>
        <p class="muted"><?= $g['treatment'] ? h($g['treatment']) : 'No treatment name' ?> · <?= $g['visible'] ? '<span class="ok">On website</span>' : '<span class="off">Hidden</span>' ?></p>
      </div>
      <div class="rowacts">
        <a class="ib" href="?p=gallery&amp;edit=<?= (int) $g['id'] ?>" title="Edit"><?= icon('edit') ?><span>Edit</span></a>
        <?= row_form('gallery_toggle', (int) $g['id'], icon('eye') . '<span>' . ($g['visible'] ? 'Hide' : 'Show') . '</span>') ?>
        <?= $i > 0 ? row_form('gallery_move', (int) $g['id'], icon('up') . '<span class="sr">Move up</span>', ['dir' => 'up']) : '' ?>
        <?= $i < count($rows) - 1 ? row_form('gallery_move', (int) $g['id'], icon('down') . '<span class="sr">Move down</span>', ['dir' => 'down']) : '' ?>
        <?= row_form('gallery_delete', (int) $g['id'], icon('trash') . '<span>Delete</span>', [], 'Delete this case and its photos?', 'ib--danger') ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
<?php
    shell_bottom();
    exit;
}

function row_form(string $action, int $id, string $label, array $extra = [], string $confirm = '', string $cls = ''): string
{
    $x = '';
    foreach ($extra as $k => $v) $x .= '<input type="hidden" name="' . h($k) . '" value="' . h($v) . '">';
    return '<form method="post" class="inl"' . ($confirm ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . csrf_field()
        . '<input type="hidden" name="action" value="' . h($action) . '"><input type="hidden" name="id" value="' . $id . '">' . $x
        . '<button class="ib ' . $cls . '" type="submit">' . $label . '</button></form>';
}

if ($page === 'dentists') {
    $editId = (int) ($_GET['edit'] ?? 0);
    $adding = isset($_GET['new']);
    $edit = null;
    if ($editId) { $s = $db->prepare('SELECT * FROM dentists WHERE id = ?'); $s->execute([$editId]); $edit = $s->fetch() ?: null; }
    $rows = $db->query('SELECT * FROM dentists ORDER BY sort, id')->fetchAll();
    shell_top('Dentists', 'dentists', $me);
    ?>
  <header class="ph">
    <div><h1>Dentists</h1><p class="muted">Changes show on the website straight away: home page, booking form and each profile page.</p></div>
    <a class="btn" href="?p=dentists&amp;new=1"><?= icon('plus') ?>Add a dentist</a>
  </header>

  <?php if ($adding || $edit): $d = $edit ?? []; ?>
  <section class="card form-card">
    <h2><?= $edit ? 'Edit ' . h($d['name']) : 'New dentist' ?></h2>
    <form method="post" enctype="multipart/form-data" class="grid-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="dentist_save">
      <input type="hidden" name="id" value="<?= (int) ($d['id'] ?? 0) ?>">
      <input type="hidden" name="back" value="?p=dentists<?= $edit ? '&edit=' . (int) $d['id'] : '&new=1' ?>">
      <label class="fl"><span>Full name</span><input name="name" maxlength="80" required value="<?= h(old('name', $d['name'] ?? '')) ?>" placeholder="Dr. Maria Santos"></label>
      <label class="fl"><span>Title (as on the DHA licence)</span><input name="title" maxlength="80" required list="title-list" value="<?= h(old('title', $d['title'] ?? 'General Dentist')) ?>"></label>
      <datalist id="title-list"><?php foreach ($TITLES as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist>
      <label class="fl"><span>Years of experience</span><input name="years" type="number" min="0" max="60" inputmode="numeric" required value="<?= h((string) old('years', $d['years'] ?? '')) ?>"></label>
      <label class="fl"><span>Photo position</span><select name="pos"><?php $cur = old('pos', $d['pos'] ?? '50% 15%'); foreach ($POSITIONS as $v => $l): ?><option value="<?= h($v) ?>"<?= $cur === $v ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?><?php if (!isset($POSITIONS[$cur])): ?><option value="<?= h($cur) ?>" selected>Current setting</option><?php endif; ?></select></label>
      <div class="pick full">
        <span class="pick__l">Photo<?= $edit ? '' : ' <b>*</b>' ?></span>
        <label class="drop drop--tall" data-drop><input type="file" name="photo" accept="image/jpeg,image/png,image/webp"<?= $edit ? '' : ' required' ?>>
          <?php if (!empty($d['photo'])): ?><img src="../<?= h($d['photo']) ?>" alt=""><?php else: ?><span class="drop__ph">Tap to choose a portrait photo</span><?php endif; ?></label>
        <p class="hint">A portrait (taller than wide) on a plain background looks best.</p>
      </div>
      <label class="fl full"><span>About (leave an empty line between paragraphs)</span><textarea name="bio" rows="6" maxlength="4000"><?= h(old('bio', $d['bio'] ?? '')) ?></textarea></label>
      <label class="fl full"><span>Areas of expertise (one per line)</span><textarea name="focus" rows="6" maxlength="2000"><?= h(old('focus', $d['focus'] ?? '')) ?></textarea></label>
      <label class="check full"><input type="checkbox" name="visible"<?= old('visible', ($d['visible'] ?? 1) ? 'on' : '') ? ' checked' : '' ?>> Show on the website</label>
      <p class="hint full">DHA rule: the title must match the dentist’s licence. Avoid words like “best”, “painless” or “guaranteed”.</p>
      <div class="actions full"><button class="btn" type="submit"><?= $edit ? 'Save changes' : 'Add dentist' ?></button><a class="btn btn--ghost" href="?p=dentists">Cancel</a></div>
    </form>
  </section>
  <?php endif; ?>

  <div class="dlist">
    <?php foreach ($rows as $i => $d): ?>
    <article class="card drow<?= $d['visible'] ? '' : ' is-hidden' ?>">
      <img class="drow__pic" src="../<?= h($d['photo']) ?>" alt="" loading="lazy">
      <div class="drow__body">
        <h3><?= h($d['name']) ?></h3>
        <p class="muted"><?= h($d['title']) ?> · <?= (int) $d['years'] ?> years · <?= $d['visible'] ? '<span class="ok">On website</span>' : '<span class="off">Hidden</span>' ?></p>
        <p class="tiny"><a href="../dentists/<?= h($d['slug']) ?>.html" target="_blank" rel="noopener">/dentists/<?= h($d['slug']) ?>.html</a></p>
      </div>
      <div class="rowacts">
        <a class="ib" href="?p=dentists&amp;edit=<?= (int) $d['id'] ?>"><?= icon('edit') ?><span>Edit</span></a>
        <?= row_form('dentist_toggle', (int) $d['id'], icon('eye') . '<span>' . ($d['visible'] ? 'Hide' : 'Show') . '</span>') ?>
        <?= $i > 0 ? row_form('dentist_move', (int) $d['id'], icon('up') . '<span class="sr">Move up</span>', ['dir' => 'up']) : '' ?>
        <?= $i < count($rows) - 1 ? row_form('dentist_move', (int) $d['id'], icon('down') . '<span class="sr">Move down</span>', ['dir' => 'down']) : '' ?>
        <?= row_form('dentist_delete', (int) $d['id'], icon('trash') . '<span>Remove</span>', [], 'Remove ' . $d['name'] . ' from the website? This cannot be undone. (Use Hide to take a dentist off the site for a while.)', 'ib--danger') ?>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
<?php
    shell_bottom();
    exit;
}

if ($page === 'account') {
    shell_top('Account', 'account', $me);
    $outside = strpos(data_dir(), dirname(__DIR__) . '/') !== 0;
    ?>
  <header class="ph"><div><h1>Account</h1><p class="muted">Signed in as <?= h($me['username']) ?><?= !empty($_SESSION['prev_login']) ? ' · previous sign-in ' . h(dubai_time($_SESSION['prev_login'])) : '' ?></p></div></header>
  <section class="card form-card narrow">
    <h2>Change password</h2>
    <?php if ((int) $me['must_change'] === 1): ?><p class="flash flash--info">This is a one-time password. Choose your own to continue.</p><?php endif; ?>
    <form method="post" class="grid-form one">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <input type="hidden" name="back" value="?p=account">
      <input type="text" name="username" value="<?= h($me['username']) ?>" autocomplete="username" hidden>
      <label class="fl"><span>Current password</span><input type="password" name="current" autocomplete="current-password" required></label>
      <label class="fl"><span>New password</span><input type="password" name="new" autocomplete="new-password" minlength="10" required></label>
      <label class="fl"><span>Repeat new password</span><input type="password" name="repeat" autocomplete="new-password" minlength="10" required></label>
      <p class="hint">At least 10 characters with letters and numbers.</p>
      <div class="actions"><button class="btn" type="submit">Save password</button></div>
    </form>
  </section>
  <section class="card narrow">
    <h2>Security</h2>
    <ul class="sec-list">
      <li>Data stored <?= $outside ? 'outside the public website folder' : 'in a locked folder inside the website' ?>.</li>
      <li>Signed out after 30 minutes without activity.</li>
      <li>Sign-in locked for 15 minutes after 5 wrong passwords.</li>
      <li>Uploaded photos are re-saved by the server, so no hidden code or location data is kept.</li>
    </ul>
  </section>
<?php
    shell_bottom();
    exit;
}

// ------------------------------------------------------------ leads (default)
$perPage = 30;
$pg = max(1, (int) ($_GET['page'] ?? 1));
[$where, $args] = lead_filters();
$cnt = $db->prepare("SELECT COUNT(*) FROM leads $where"); $cnt->execute($args); $total = (int) $cnt->fetchColumn();
$s = $db->prepare("SELECT * FROM leads $where ORDER BY id DESC LIMIT $perPage OFFSET " . (($pg - 1) * $perPage));
$s->execute($args);
$rows = $s->fetchAll();
$dubaiMidnight = (new DateTime('today', new DateTimeZone('Asia/Dubai')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
$weekAgo = gmdate('Y-m-d H:i:s', time() - 7 * 86400);
$stat = fn(string $sql, array $a = []) => (function () use ($db, $sql, $a) { $q = $db->prepare($sql); $q->execute($a); return (int) $q->fetchColumn(); })();
$req = "type = 'Appointment request'";
$sToday = $stat("SELECT COUNT(*) FROM leads WHERE $req AND created_at >= ?", [$dubaiMidnight]);
$sWeek = $stat("SELECT COUNT(*) FROM leads WHERE $req AND created_at >= ?", [$weekAgo]);
$sNew = $stat("SELECT COUNT(*) FROM leads WHERE $req AND status = 'new'");
$sChats = $stat("SELECT COUNT(*) FROM leads WHERE type <> 'Appointment request' AND created_at >= ?", [$weekAgo]);
$type = (string) ($_GET['type'] ?? 'requests');
$status = (string) ($_GET['status'] ?? '');
$q = (string) ($_GET['q'] ?? '');
$qs = http_build_query(array_filter(['p' => 'leads', 'type' => $type, 'status' => $status, 'q' => $q, 'page' => $pg > 1 ? $pg : null]));
shell_top('Leads', 'leads', $me);
?>
  <header class="ph">
    <div><h1>Leads</h1><p class="muted">Booking requests sent from the website before visitors continue to WhatsApp.</p></div>
    <a class="btn btn--ghost" href="?<?= h(http_build_query(['p' => 'leads', 'type' => $type, 'status' => $status, 'q' => $q, 'export' => 1])) ?>"><?= icon('down2') ?>Export CSV</a>
  </header>
  <div class="kpis">
    <div class="kpi"><b><?= $sToday ?></b><span>Requests today</span></div>
    <div class="kpi"><b><?= $sWeek ?></b><span>Last 7 days</span></div>
    <div class="kpi kpi--hot"><b><?= $sNew ?></b><span>Waiting (new)</span></div>
    <div class="kpi"><b><?= $sChats ?></b><span>Chat clicks, 7 days</span></div>
  </div>
  <form class="filters card" method="get">
    <input type="hidden" name="p" value="leads">
    <label class="fl"><span>Search</span><input type="search" name="q" value="<?= h($q) ?>" placeholder="Name, mobile, treatment"></label>
    <label class="fl"><span>Show</span><select name="type" data-autosubmit>
      <option value="requests"<?= $type === 'requests' ? ' selected' : '' ?>>Appointment requests</option>
      <option value="chats"<?= $type === 'chats' ? ' selected' : '' ?>>Chat &amp; enquiry clicks</option>
      <option value="all"<?= $type === 'all' ? ' selected' : '' ?>>Everything</option></select></label>
    <label class="fl"><span>Status</span><select name="status" data-autosubmit><option value="">Any status</option>
      <?php foreach ($STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <button class="btn" type="submit">Filter</button>
  </form>

  <?php if (!$rows): ?><p class="empty card">No leads here yet. When someone presses “Request appointment” on the website, it appears here.</p><?php endif; ?>
  <div class="llist">
  <?php foreach ($rows as $r): $wa = wa_number($r['mobile']); ?>
    <article class="card lead st-<?= h($r['status']) ?>">
      <header class="lead__top">
        <div>
          <h3><?= $r['name'] !== '' && $r['name'] !== null ? h($r['name']) : '<span class="muted">No name given</span>' ?></h3>
          <p class="muted tiny"><?= h(dubai_time($r['created_at'])) ?> · <?= h($r['type']) ?></p>
        </div>
        <span class="pill pill--<?= h($r['status']) ?>"><?= h($STATUSES[$r['status']] ?? $r['status']) ?></span>
      </header>
      <dl class="lead__dl">
        <?php if ($r['mobile']): ?><div><dt>Mobile</dt><dd><a href="tel:<?= h(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>"><?= h($r['mobile']) ?></a></dd></div><?php endif; ?>
        <?php if ($r['treatment']): ?><div><dt>Treatment</dt><dd><?= h($r['treatment']) ?></dd></div><?php endif; ?>
        <?php if ($r['dentist']): ?><div><dt>Dentist</dt><dd><?= h($r['dentist']) ?></dd></div><?php endif; ?>
        <?php if ($r['pref_day'] || $r['pref_time']): ?><div><dt>Preferred</dt><dd><?= h(trim(($r['pref_day'] ?: 'Any day') . ' · ' . ($r['pref_time'] ?: 'Any time'))) ?></dd></div><?php endif; ?>
        <?php if ($r['page']): ?><div><dt>Page</dt><dd class="tiny"><?= h($r['page']) ?></dd></div><?php endif; ?>
      </dl>
      <div class="lead__acts">
        <?php if ($r['mobile']): ?><a class="ib" href="tel:<?= h(preg_replace('/[^0-9+]/', '', $r['mobile'])) ?>"><?= icon('call') ?><span>Call</span></a><?php endif; ?>
        <?php if ($wa): ?><a class="ib ib--wa" href="https://wa.me/<?= h($wa) ?>?text=<?= rawurlencode('Hello ' . ($r['name'] ?: '') . ', this is Pearlecent Shifah Medical Center about your appointment request.') ?>" target="_blank" rel="noopener"><?= icon('chat') ?><span>WhatsApp</span></a><?php endif; ?>
        <form method="post" class="inl status-form"><?= csrf_field() ?><input type="hidden" name="action" value="lead_status"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="?<?= h($qs) ?>">
          <label class="sr" for="st<?= (int) $r['id'] ?>">Status</label>
          <select id="st<?= (int) $r['id'] ?>" name="status" data-autosubmit><?php foreach ($STATUSES as $k => $l): ?><option value="<?= $k ?>"<?= $r['status'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
          <noscript><button class="ib" type="submit">Save</button></noscript></form>
        <?= row_form('lead_delete', (int) $r['id'], icon('trash') . '<span class="sr">Delete</span>', ['back' => '?' . $qs], 'Delete this lead?', 'ib--danger') ?>
      </div>
      <form method="post" class="lead__note"><?= csrf_field() ?><input type="hidden" name="action" value="lead_note"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="back" value="?<?= h($qs) ?>">
        <label class="sr" for="n<?= (int) $r['id'] ?>">Note</label>
        <input id="n<?= (int) $r['id'] ?>" name="note" maxlength="1000" value="<?= h($r['note']) ?>" placeholder="Add a note (e.g. booked Tue 6 PM with Dr. Faseela)">
        <button class="ib" type="submit">Save note</button>
      </form>
    </article>
  <?php endforeach; ?>
  </div>
  <?php $pages = (int) ceil($total / $perPage); if ($pages > 1): ?>
  <nav class="pager" aria-label="Pages">
    <?php for ($i = 1; $i <= $pages; $i++): ?><a class="<?= $i === $pg ? 'is-on' : '' ?>" href="?<?= h(http_build_query(['p' => 'leads', 'type' => $type, 'status' => $status, 'q' => $q, 'page' => $i])) ?>"><?= $i ?></a><?php endfor; ?>
  </nav>
  <?php endif; ?>
<?php
shell_bottom();
