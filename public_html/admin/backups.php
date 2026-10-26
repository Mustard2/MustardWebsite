<?php
// Backups tab: one list of backups (full ones: manual / weekly / before a restore / uploaded; code before updates), then the
// change history of the data files.
// Create, download, restore, delete. Every restore first saves the current state, so it can be undone.
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_backup.php';
admin_require($M3D);

const DATA_LABELS = ['models' => 'Models', 'outfits' => 'Accessories', 'faq' => 'FAQ', 'docs' => 'Docs', 'settings' => 'Settings', 'blog' => 'Blog', 'gallery' => 'Gallery', 'exceptions' => 'Access Exceptions'];
const PART_LABELS = ['code' => 'Code', 'content' => 'Content', 'counters' => 'Download Counts, Likes & Comments', 'bugs' => 'Bug Reports', 'log' => 'Download Log', 'media' => 'Media'];

// ── Download ─────────────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $p = bk_file(m3d_get_text('download'));
    if (!$p) { http_response_code(404); exit('Backup not found.'); }
    m3d_session_release();   // a big zip takes a while: the admin's other pages stay usable meanwhile
    @set_time_limit(0);
    header('Content-Type: ' . (str_ends_with($p, '.json') ? 'application/json' : 'application/zip'));
    header('Content-Disposition: attachment; filename="site-' . basename($p) . '"');
    header('Content-Length: ' . filesize($p));
    header('X-Robots-Tag: noindex');
    readfile($p);
    exit;
}

// ── Actions ──────────────────────────────────────────────────────────────────
$done = $error = null;
$partial = false;   // a restore that failed halfway: the error says so instead of "Nothing was changed"
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!m3d_check_csrf($_POST['csrf'] ?? null)) throw new RuntimeException('Session expired, reload the page and try again.');
        m3d_session_release();   // backups and restores take a while: the other pages stay usable (nothing here changes the login)
        $file = (string) ($_POST['file'] ?? '');
        switch ($_POST['do'] ?? '') {
            case 'create':
                if (!class_exists('ZipArchive')) throw new RuntimeException('Zip support is missing on this server.');
                $name = backup_full('manual', !empty($_POST['media']), clean_text($_POST['note'] ?? '', 60));
                $done = 'Backup created: ' . $name . ' (' . m3d_size((int) filesize(bk_dir() . "/$name")) . ').';
                break;
            case 'upload':
                $f = $_FILES['zip'] ?? null;
                if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) throw new RuntimeException('No file received (it may be larger than the server allows: ' . ini_get('upload_max_filesize') . ').');
                $m = backup_manifest($f['tmp_name']);
                if (!$m) throw new RuntimeException('This is not a backup of this site (a full backup zip made on this page).');
                $created = strtotime((string) ($m['created'] ?? '')) ?: time();
                $name = 'full-' . gmdate('Ymd-His', $created) . '-uploaded.zip';
                if (!move_uploaded_file($f['tmp_name'], bk_dir() . "/$name")) throw new RuntimeException('Could not save the uploaded backup.');
                $done = 'Backup uploaded. You can restore it below.';
                break;
            case 'restore-full':
                $p = bk_file($file);
                if (!$p || !str_starts_with($file, 'full-')) throw new RuntimeException('Backup not found.');
                $parts = array_values(array_intersect((array) ($_POST['parts'] ?? []), array_keys(PART_LABELS)));
                if (!$parts) throw new RuntimeException('Choose at least one thing to restore.');
                // program files only from backups this site made: an uploaded zip could hold any PHP (install versions from Update)
                if (bk_uploaded($file) && in_array('code', $parts, true)) throw new RuntimeException('The code of an uploaded backup isn’t installed: untick Code, and install a version from Settings → Update instead.');
                $lock = code_lock();
                keep_running(900);
                $safety = backup_full('before-restore');
                try {
                    $what = restore_full($p, $parts);
                } catch (RestorePartial $ex) {
                    $partial = true;
                    throw new RuntimeException($ex->getMessage() . ' To undo it, restore ' . $safety . ' (the state saved just before), with the same parts ticked.');
                } catch (Throwable $ex) {
                    @unlink(bk_dir() . '/' . $safety);   // nothing changed: this copy isn't needed
                    throw $ex;
                }
                $done = 'Restored ' . implode(', ', $what) . ' from ' . $file . '. The previous state was saved first as ' . $safety . '.';
                break;
            case 'restore-code':
                $p = bk_file($file);
                if (!$p || !str_starts_with($file, 'site-code-')) throw new RuntimeException('Backup not found.');
                $zip = new ZipArchive();
                if ($zip->open($p) !== true) throw new RuntimeException('This backup can’t be opened.');
                $lock = code_lock();
                keep_running();
                [$safety, $n, $v] = install_with_backup($zip);
                $zip->close();
                $done = $safety === '' ? "Code restored to version $v: it was already the same, nothing changed."
                    : "Code restored to version $v ($n files changed). The code you had before was saved as $safety.";
                break;
            case 'restore-data':
                $name = restore_data_version($file);
                $done = (DATA_LABELS[$name] ?? ucfirst($name)) . ' restored to the version of ' . $file . '. The version you had before is now at the top of its history.';
                break;
            case 'delete':
                $p = bk_file($file);
                if (!$p) throw new RuntimeException('Backup not found.');
                if (!@unlink($p)) throw new RuntimeException('Could not delete the file.');
                $done = 'Deleted ' . $file . '.';
                break;
            case 'prune-history':
                $n = 0;
                foreach (data_history() as $list) foreach (array_slice($list, 5) as $v) if (@unlink(bk_dir() . '/' . $v['file'])) $n++;
                $done = "$n older versions deleted (the newest 5 of each are kept).";
                break;
        }
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
}

// ── Lists ────────────────────────────────────────────────────────────────────
$full = [];
foreach (glob(bk_dir() . '/full-*.zip') ?: [] as $p) {
    $m = backup_manifest($p) ?? [];
    preg_match('/^full-(\d{8}-\d{6})-([a-z]+(?:-restore)?)(?:-(.*))?\.zip$/', basename($p), $mm);
    $full[] = ['file' => basename($p), 'time' => strtotime((string) ($m['created'] ?? '')) ?: filemtime($p), 'bytes' => (int) filesize($p),
        'kind' => $m['kind'] ?? ($mm[2] ?? 'manual'), 'note' => (string) ($m['note'] ?? ''), 'version' => (string) ($m['version'] ?? ''),
        'parts' => $m['parts'] ?? [], 'uploaded' => bk_uploaded(basename($p)), 'ok' => (bool) $m,
        'bugs' => $m && in_array('data', $m['parts'] ?? [], true) && backup_has($p, 'data/bugs.json')];
}
usort($full, fn($a, $b) => $b['time'] <=> $a['time']);
$code = [];
foreach (glob(bk_dir() . '/site-code-*.zip') ?: [] as $p) {
    preg_match('/-v([0-9a-z.-]+)\.zip$/i', basename($p), $mm);
    $code[] = ['file' => basename($p), 'time' => filemtime($p), 'bytes' => (int) filesize($p), 'version' => $mm[1] ?? '?'];
}
usort($code, fn($a, $b) => $b['time'] <=> $a['time']);
// one list, newest first: full backups and the code saved before each update, with tags for where they come from and what's inside
const BK_KINDS = ['manual' => ['green', 'Manual'], 'auto' => ['sky', 'Weekly (Automatic)'], 'before-restore' => ['grey', 'Before a Restore'],
                  'uploaded' => ['grey', 'Uploaded'], 'update' => ['violet', 'Before an Update']];
$all = [];
foreach ($full as $b) $all[] = ['kind' => $b['uploaded'] ? 'uploaded' : $b['kind']] + $b + ['type' => 'full',
    'has' => array_values(array_filter(array_map(fn($p) => ['code' => 'Code', 'data' => 'Content, Counts & Likes', 'log' => 'Download Log', 'media' => 'Media'][$p] ?? null, $b['parts'])))];
foreach ($code as $b) $all[] = $b + ['type' => 'code', 'kind' => 'update', 'note' => '', 'parts' => ['code'], 'ok' => true, 'bugs' => false, 'has' => ['Code']];
usort($all, fn($a, $b) => $b['time'] <=> $a['time']);
$history = data_history();
$media = media_files();   // walked once: the count and size of "Include Media"
$mediaBytes = array_sum(array_map('filesize', $media));
$when = fn(int $t) => m3d_site_time($t, 'd/m/Y H:i');
// the calendar: backups by day (site time), and every month from the newest backup back to the oldest (newest first)
$berlin = m3d_tz();
$day = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone($berlin)->format('Y-m-d');
$clock = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone($berlin)->format('H:i');
$today = (new DateTimeImmutable('now', $berlin))->format('Y-m-d');
$byDay = [];
foreach ($all as $b) $byDay[$day($b['time'])][] = $b;
$months = [];
if ($all) {
    $m = new DateTimeImmutable(substr(array_key_first($byDay), 0, 7) . '-01', $berlin);
    $last = substr(array_key_last($byDay), 0, 7);
    while (true) {
        $months[] = ['key' => $m->format('Y-m'), 'label' => $m->format('F Y'), 'first' => (int) $m->format('N'), 'days' => (int) $m->format('t')];
        if ($m->format('Y-m') <= $last) break;
        $m = $m->modify('-1 month');
    }
}
$dayLabel = function (string $key) use ($today, $berlin): string {
    $d = new DateTimeImmutable($key, $berlin);
    $name = $d->format('l j F') . ($d->format('Y') !== substr($today, 0, 4) ? $d->format(' Y') : '');
    if ($key === $today) return 'Today · ' . $name;
    if ($key === (new DateTimeImmutable($today, $berlin))->modify('-1 day')->format('Y-m-d')) return 'Yesterday · ' . $name;
    return $name;
};
$dayTitle = function (array $list): string {
    $n = array_count_values(array_map(fn($b) => (BK_KINDS[$b['kind']] ?? [1 => ucfirst($b['kind'])])[1], $list));
    return count($list) . ' ' . (count($list) === 1 ? 'backup' : 'backups') . ': ' . implode(', ', array_map(fn($l, $c) => "$c $l", array_keys($n), $n));
};

$post = fn(string $do, string $file, string $label, string $confirm): string =>
    '<form method="post" class="inline" onsubmit="return confirm(' . e(json_encode($confirm)) . ')">' . form_keys($do)
    . '<input type="hidden" name="file" value="' . e($file) . '" /><button class="btn btn-ghost btn-sm">' . e($label) . '</button></form>';
$dl = fn(string $file) => '<a class="btn btn-ghost btn-sm" href="/admin/backups.php?download=' . e(rawurlencode($file)) . '">Download</a>';
$del = fn(string $file) => '<form method="post" class="inline" onsubmit="return confirm(\'Delete this backup for good?\')">' . form_keys('delete') . '<input type="hidden" name="file" value="' . e($file) . '" /><button class="icon-btn del" title="Delete" aria-label="Delete">✕</button></form>';

admin_start('Backups', 'backups');   // part of Settings: its menu links here
?>
    <div class="admin-head">
      <div>
        <h1>Backups</h1>
      </div>
    </div>
    <?= settings_menu('backups') ?>

    <?php admin_result($done, $error, $partial ? 'Partly restored:' : 'Nothing was changed:') ?>

    <section class="sec">
      <h2 class="small-h">Create a Backup<?= tip('Code, content, counts, likes and the download log. Your keys (Patreon, MEGA S4, GitHub) and Discord webhooks are never included.') ?></h2>
      <form method="post" class="bk-create">
        <?= form_keys('create') ?>
        <input class="input" type="text" name="note" maxlength="60" placeholder="Note (optional), e.g. before moving to the new host" aria-label="Note" />
        <label class="field check"><input type="checkbox" name="media" value="1" /><span class="lbl">Include Media (<?= count($media) ?> files, <?= m3d_size((int) $mediaBytes) ?>)</span></label>
        <button class="btn btn-primary btn-sm" onclick="this.textContent='Creating…'">Create Backup</button>
      </form>
      <details class="bk-upload">
        <summary class="small muted">Or Upload a Backup from Your Computer</summary>
        <form method="post" enctype="multipart/form-data" class="bk-create">
          <?= form_keys('upload') ?>
          <input class="input" type="file" name="zip" accept=".zip,application/zip" required />
          <button class="btn btn-ghost btn-sm">Upload</button>
        </form>
      </details>
    </section>

    <section class="sec bk-sec" id="all">
      <h2 class="small-h">All Backups <span class="muted small">(<?= count($all) ?>)</span><?= tip('Manual and uploaded backups are never deleted automatically; the newest 8 weekly ones and the code from before the last 5 updates are kept.') ?></h2>
      <?php if (!$all): ?><p class="muted">None yet. Create one above.</p><?php else: ?>
      <div class="bk-layout">
        <div class="bk-cal" data-bk-cal>
          <?php foreach ($months as $i => $mo): ?>
            <div class="bk-month"<?= $i ? ' hidden' : '' ?>>
              <div class="bk-cal-head">
                <button type="button" class="icon-btn" data-bk-month="<?= $i + 1 ?>" aria-label="Previous month"<?= $i === count($months) - 1 ? ' disabled' : '' ?>>‹</button>
                <b><?= e($mo['label']) ?></b>
                <button type="button" class="icon-btn" data-bk-month="<?= $i - 1 ?>" aria-label="Next month"<?= $i === 0 ? ' disabled' : '' ?>>›</button>
              </div>
              <div class="bk-grid">
                <?php foreach (['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'] as $wd): ?><span class="bk-wd"><?= $wd ?></span><?php endforeach ?>
                <?php for ($k = 1; $k < $mo['first']; $k++): ?><span></span><?php endfor ?>
                <?php for ($d = 1; $d <= $mo['days']; $d++): $key = sprintf('%s-%02d', $mo['key'], $d); $list = $byDay[$key] ?? []; ?>
                  <?php if ($list): ?>
                    <button type="button" class="bk-day has<?= $key === $today ? ' today' : '' ?>" data-bk-day="<?= $key ?>" title="<?= e($dayTitle($list)) ?>">
                      <span><?= $d ?></span>
                      <i class="bk-dots"><?php foreach (array_slice($list, 0, 3) as $b): ?><i class="tone-<?= (BK_KINDS[$b['kind']] ?? ['grey'])[0] ?>"></i><?php endforeach ?><?= count($list) > 3 ? '<b>+</b>' : '' ?></i>
                    </button>
                  <?php else: ?>
                    <span class="bk-day<?= $key === $today ? ' today' : '' ?>"><span><?= $d ?></span></span>
                  <?php endif ?>
                <?php endfor ?>
              </div>
            </div>
          <?php endforeach ?>
          <div class="bk-legend">
            <?php foreach (array_unique(array_column($all, 'kind')) as $k): [$tone, $label] = BK_KINDS[$k] ?? ['grey', ucfirst($k)]; ?>
              <span class="tone-<?= $tone ?>"><i></i><?= e($label) ?></span>
            <?php endforeach ?>
          </div>
        </div>
        <div class="bk-list">
          <p class="bk-filter" data-bk-filter hidden><span class="small">Only the day picked in the calendar.</span> <button type="button" class="btn btn-ghost btn-sm" data-bk-all>Show All</button></p>
          <?php foreach ($byDay as $key => $list): ?>
            <div class="bk-dgroup" data-bk-group="<?= $key ?>">
              <h3 class="bk-day-h"><?= e($dayLabel($key)) ?> <span class="muted small"><?= count($list) ?> <?= count($list) === 1 ? 'backup' : 'backups' ?></span></h3>
              <?php foreach ($list as $b): ?>
                <div class="bk-row bk-item">
                  <b class="bk-time"><?= e($clock($b['time'])) ?></b>
                  <div class="bk-main">
                    <?php [$tone, $label] = BK_KINDS[$b['kind']] ?? ['grey', ucfirst($b['kind'])]; ?>
                    <span class="badge tone-<?= $tone ?>"><?= e($label) ?></span>
                    <span class="bk-tags">
                      <?php foreach ($b['has'] as $h): ?><span class="bk-tag<?= $h === 'Media' ? ' bk-tag-img' : '' ?>"><?= e($h) ?></span><?php endforeach ?>
                    </span>
                    <span class="muted small"><?= $b['version'] !== '' ? 'v' . e($b['version']) . ' · ' : '' ?><?= m3d_size($b['bytes']) ?></span>
                    <?php if ($b['note'] !== ''): ?><span class="bk-note small">“<?= e($b['note']) ?>”</span><?php endif ?>
                  </div>
                  <div class="row-btns">
                    <?= $dl($b['file']) ?>
                    <?php if ($b['type'] === 'code'): ?>
                      <?= $post('restore-code', $b['file'], 'Restore…', 'Go back to the code of version ' . $b['version'] . '? Only the code changes (content, images and settings stay). The current code is saved first.') ?>
                    <?php elseif ($b['ok']): ?>
                      <details class="bk-restore">
                        <summary class="btn btn-ghost btn-sm">Restore…</summary>
                        <form method="post" class="bk-restore-form" onsubmit="return confirm('Restore the selected parts? The current state is saved first, so you can undo this.')">
                          <?= form_keys('restore-full') ?><input type="hidden" name="file" value="<?= e($b['file']) ?>" />
                          <p class="small">Put back from this backup:<?= tip('Counts, likes, bug reports and the log are unticked so you keep the newer ones. Media files are never deleted.') ?></p>
                          <?php $has = ['code' => in_array('code', $b['parts'], true) && !$b['uploaded'], 'content' => in_array('data', $b['parts'], true), 'counters' => in_array('data', $b['parts'], true), 'bugs' => $b['bugs'], 'log' => in_array('log', $b['parts'], true), 'media' => in_array('media', $b['parts'], true)]; ?>
                          <?php foreach (PART_LABELS as $k => $l): if (!$has[$k]) continue; ?>
                            <label class="field check"><input type="checkbox" name="parts[]" value="<?= $k ?>" <?= in_array($k, ['code', 'content'], true) ? 'checked' : '' ?> /><span class="lbl"><?= e($l) ?></span></label>
                          <?php endforeach ?>
                          <button class="btn btn-primary btn-sm">Restore</button>
                        </form>
                      </details>
                    <?php else: ?><span class="warn-dot small">Damaged</span><?php endif ?>
                    <?= $del($b['file']) ?>
                  </div>
                </div>
              <?php endforeach ?>
            </div>
          <?php endforeach ?>
        </div>
      </div>
      <?php endif ?>
    </section>

    <section class="sec bk-sec" id="history">
      <h2 class="small-h">Change History</h2>
      <?php if (!$history): ?><p class="muted">Nothing saved yet.</p><?php endif ?>
      <?php foreach ($history as $name => $list): ?>
        <details class="bk-group">
          <summary><b><?= e(DATA_LABELS[$name] ?? ucfirst($name)) ?></b> <span class="muted small"><?= count($list) ?> <?= count($list) === 1 ? 'version' : 'versions' ?> · latest saved <?= e($when($list[0]['time'])) ?></span></summary>
          <?php foreach ($list as $v): ?>
            <div class="bk-row bk-row-sm">
              <div class="bk-main"><span><?= e($when($v['time'])) ?></span><span class="muted small"><?= e(data_summary($name, bk_dir() . '/' . $v['file'])) ?> · <?= m3d_size($v['bytes']) ?></span></div>
              <div class="row-btns">
                <?= $dl($v['file']) ?>
                <?= $post('restore-data', $v['file'], 'Restore', 'Put ' . (DATA_LABELS[$name] ?? ucfirst($name)) . ' back as it was on ' . $when($v['time']) . '? The current version is kept in this history.') ?>
                <?= $del($v['file']) ?>
              </div>
            </div>
          <?php endforeach ?>
        </details>
      <?php endforeach ?>
      <?php if (array_filter($history, fn($l) => count($l) > 5)): ?>
        <?= $post('prune-history', '', 'Keep Only the Newest 5 of Each', 'Delete all but the newest 5 versions of each file?') ?>
      <?php endif ?>
    </section>
<?php admin_end();
