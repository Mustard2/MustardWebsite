<?php
// Backups tab: one list of backups (full ones: manual / weekly / before a restore / uploaded; code before updates), then the
// change history of the data files.
// Create, download, restore, delete. Every restore first saves the current state, so it can be undone.
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_backup.php';
admin_require($M3D);

const DATA_LABELS = ['models' => 'Models', 'outfits' => 'Accessories', 'faq' => 'FAQ', 'docs' => 'Docs', 'settings' => 'Settings', 'links' => 'Old Mega links'];
const PART_LABELS = ['code' => 'Code', 'content' => 'Content', 'counters' => 'Download counts & likes', 'log' => 'Download log', 'media' => 'Images'];

// ── Download ─────────────────────────────────────────────────────────────────
if (isset($_GET['download'])) {
    $p = bk_file((string) $_GET['download']);
    if (!$p) { http_response_code(404); exit('Backup not found.'); }
    header('Content-Type: ' . (str_ends_with($p, '.json') ? 'application/json' : 'application/zip'));
    header('Content-Disposition: attachment; filename="site-' . basename($p) . '"');
    header('Content-Length: ' . filesize($p));
    header('X-Robots-Tag: noindex');
    readfile($p);
    exit;
}

// ── Actions ──────────────────────────────────────────────────────────────────
$done = $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!m3d_check_csrf($_POST['csrf'] ?? null)) throw new RuntimeException('Session expired, reload the page and try again.');
        $file = (string) ($_POST['file'] ?? '');
        switch ($_POST['do'] ?? '') {
            case 'create':
                if (!class_exists('ZipArchive')) throw new RuntimeException('Zip support is missing on this server.');
                $name = backup_full('manual', !empty($_POST['media']), clean_text($_POST['note'] ?? '', 60));
                $done = 'Backup created: ' . $name . ' (' . human_size((int) filesize(bk_dir() . "/$name")) . ').';
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
                $lock = code_lock();
                keep_running(900);
                $safety = backup_full('before-restore');
                $what = restore_full($p, $parts);
                $done = 'Restored ' . implode(', ', $what) . ' from ' . $file . '. The previous state was saved first as ' . $safety . '.';
                break;
            case 'restore-code':
                $p = bk_file($file);
                if (!$p || !str_starts_with($file, 'site-code-')) throw new RuntimeException('Backup not found.');
                $zip = new ZipArchive();
                if ($zip->open($p) !== true) throw new RuntimeException('This backup can’t be opened.');
                $lock = code_lock();
                keep_running();
                $safety = backup_code();
                try {
                    [$n, $v] = install_code($zip);
                } catch (Throwable $ex) {
                    @unlink(bk_dir() . '/' . $safety);   // nothing changed: no need for this copy
                    throw $ex;
                }
                $zip->close();
                $done = "Code restored to version $v ($n files). The code you had before was saved as $safety.";
                break;
            case 'restore-data':
                $name = restore_data_version($file);
                $done = (DATA_LABELS[$name] ?? $name) . ' restored to the version of ' . $file . '. The version you had before is now at the top of its history.';
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
        'parts' => $m['parts'] ?? [], 'uploaded' => str_ends_with(basename($p), '-uploaded.zip'), 'ok' => (bool) $m];
}
usort($full, fn($a, $b) => $b['time'] <=> $a['time']);
$code = [];
foreach (glob(bk_dir() . '/site-code-*.zip') ?: [] as $p) {
    preg_match('/-v([0-9a-z.-]+)\.zip$/i', basename($p), $mm);
    $code[] = ['file' => basename($p), 'time' => filemtime($p), 'bytes' => (int) filesize($p), 'version' => $mm[1] ?? '?'];
}
usort($code, fn($a, $b) => $b['time'] <=> $a['time']);
// one list, newest first: full backups and the code saved before each update, with tags for where they come from and what's inside
const BK_KINDS = ['manual' => ['green', 'Manual'], 'auto' => ['sky', 'Weekly (automatic)'], 'before-restore' => ['grey', 'Before a restore'],
                  'uploaded' => ['grey', 'Uploaded'], 'update' => ['violet', 'Before an update']];
$all = [];
foreach ($full as $b) $all[] = $b + ['type' => 'full', 'kind' => $b['uploaded'] ? 'uploaded' : $b['kind'],
    'has' => array_values(array_filter(array_map(fn($p) => ['code' => 'Code', 'data' => 'Content, counts & likes', 'log' => 'Download log', 'media' => 'Images'][$p] ?? null, $b['parts'])))];
foreach ($code as $b) $all[] = $b + ['type' => 'code', 'kind' => 'update', 'note' => '', 'parts' => ['code'], 'ok' => true, 'has' => ['Code']];
usort($all, fn($a, $b) => $b['time'] <=> $a['time']);
$history = data_history();
$mediaBytes = array_sum(array_map('filesize', media_files()));
$when = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d/m/Y H:i');

$post = function (string $do, string $file, string $label, string $confirm, string $cls = 'btn btn-ghost btn-sm', array $extra = []): string {
    $h = '<form method="post" class="inline" onsubmit="return confirm(' . e(json_encode($confirm)) . ')">'
        . '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="do" value="' . e($do) . '" />'
        . '<input type="hidden" name="file" value="' . e($file) . '" />';
    foreach ($extra as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '" />';
    return $h . '<button class="' . e($cls) . '">' . e($label) . '</button></form>';
};
$dl = fn(string $file) => '<a class="btn btn-ghost btn-sm" href="/admin/backups.php?download=' . e(rawurlencode($file)) . '">Download</a>';
$del = fn(string $file) => '<form method="post" class="inline" onsubmit="return confirm(\'Delete this backup for good?\')"><input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="do" value="delete" /><input type="hidden" name="file" value="' . e($file) . '" /><button class="icon-btn del" title="Delete" aria-label="Delete">✕</button></form>';

admin_start('Backups', 'backups');
?>
    <div class="admin-head">
      <div>
        <h1>Backups</h1>
      </div>
    </div>

    <?php if ($done): ?><p class="notice"><b class="res-ok">✓</b> <?= e($done) ?></p><?php endif ?>
    <?php if ($error): ?><p class="notice"><b class="res-err">Nothing was changed:</b> <?= e($error) ?></p><?php endif ?>

    <section class="sec">
      <h2 class="small-h">Create a backup<?= tip('Code, content, counts, likes and the download log. Your Patreon keys (config.php) are never included.') ?></h2>
      <form method="post" class="bk-create">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="create" />
        <input class="input" type="text" name="note" maxlength="60" placeholder="Note (optional), e.g. before moving to the new host" aria-label="Note" />
        <label class="field check"><input type="checkbox" name="media" value="1" /><span class="lbl">Include images (<?= count(media_files()) ?> files, <?= human_size((int) $mediaBytes) ?>)</span></label>
        <button class="btn btn-primary btn-sm" onclick="this.textContent='Creating…'">Create backup</button>
      </form>
      <details class="bk-upload">
        <summary class="small muted">Or upload a backup from your computer</summary>
        <form method="post" enctype="multipart/form-data" class="bk-create">
          <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="upload" />
          <input class="input" type="file" name="zip" accept=".zip,application/zip" required />
          <button class="btn btn-ghost btn-sm">Upload</button><?= tip('A full backup from this page (max ' . ini_get('upload_max_filesize') . '). Nothing is restored until you choose to.') ?>
        </form>
      </details>
    </section>

    <section class="sec bk-sec" id="all">
      <h2 class="small-h">All backups <span class="muted small">(<?= count($all) ?>)</span><?= tip('Newest first. Manual and uploaded backups are never deleted automatically; the newest 8 weekly ones and the code from before the last 5 updates are kept.') ?></h2>
      <?php if (!$all): ?><p class="muted">None yet. Create one above.</p><?php endif ?>
      <?php foreach ($all as $b): ?>
        <div class="bk-row">
          <div class="bk-main">
            <b><?= e($when($b['time'])) ?></b>
            <?php [$tone, $label] = BK_KINDS[$b['kind']] ?? ['grey', ucfirst($b['kind'])]; ?>
            <span class="badge tone-<?= $tone ?>"><?= e($label) ?></span>
            <span class="bk-tags">
              <?php foreach ($b['has'] as $h): ?><span class="bk-tag<?= $h === 'Images' ? ' bk-tag-img' : '' ?>"><?= e($h) ?></span><?php endforeach ?>
            </span>
            <span class="muted small"><?= $b['version'] !== '' ? 'v' . e($b['version']) . ' · ' : '' ?><?= human_size($b['bytes']) ?></span>
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
                  <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="restore-full" /><input type="hidden" name="file" value="<?= e($b['file']) ?>" />
                  <p class="small">Put back from this backup:<?= tip('Counts, likes and the log are unticked so you keep the newer ones. Images are never deleted.') ?></p>
                  <?php $has = ['code' => in_array('code', $b['parts'], true), 'content' => in_array('data', $b['parts'], true), 'counters' => in_array('data', $b['parts'], true), 'log' => in_array('log', $b['parts'], true), 'media' => in_array('media', $b['parts'], true)]; ?>
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
    </section>

    <section class="sec bk-sec" id="history">
      <h2 class="small-h">Change history<?= tip('The previous version of each save (last 50 of each). Restoring one only changes that part.') ?></h2>
      <?php if (!$history): ?><p class="muted">Nothing saved yet.</p><?php endif ?>
      <?php foreach ($history as $name => $list): ?>
        <details class="bk-group">
          <summary><b><?= e(DATA_LABELS[$name] ?? ucfirst($name)) ?></b> <span class="muted small"><?= count($list) ?> <?= count($list) === 1 ? 'version' : 'versions' ?> · latest saved <?= e($when($list[0]['time'])) ?></span></summary>
          <?php foreach ($list as $v): ?>
            <div class="bk-row bk-row-sm">
              <div class="bk-main"><span><?= e($when($v['time'])) ?></span><span class="muted small"><?= e(data_summary($name, bk_dir() . '/' . $v['file'])) ?> · <?= human_size($v['bytes']) ?></span></div>
              <div class="row-btns">
                <?= $dl($v['file']) ?>
                <?= $post('restore-data', $v['file'], 'Restore', 'Put ' . (DATA_LABELS[$name] ?? $name) . ' back as it was on ' . $when($v['time']) . '? The current version is kept in this history.') ?>
                <?= $del($v['file']) ?>
              </div>
            </div>
          <?php endforeach ?>
        </details>
      <?php endforeach ?>
      <?php if (array_filter($history, fn($l) => count($l) > 5)): ?>
        <?= $post('prune-history', '', 'Keep only the newest 5 of each', 'Delete all but the newest 5 versions of each file?') ?>
      <?php endif ?>
    </section>
<?php admin_end();
