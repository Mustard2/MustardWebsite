<?php
// Installs a site update: from GitHub (the releases of the chosen update channel, admin/_github.php) or from an uploaded
// zip (made by make_update.py). Nothing is installed without the admin pressing Install.
// Never touches: media/ (your uploads, except its protective .htaccess) and private/.
// Before installing, the current code is saved to private/backups/site-code-*.zip (last 5),
// and any of them can be restored from Admin → Backups (admin/backups.php).
// Safety: one install at a time (code_lock), it keeps running if the page is closed (keep_running), files are swapped in
// all together with automatic rollback (install_code), and the result is shown after a redirect, so refreshing never
// installs again.
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_backup.php';
require_once __DIR__ . '/_github.php';
admin_require($M3D);

$result = null;
$error = null;
$fromFile = false;   // the outcome is of an uploaded zip: its box stays open
$saved = gh_saved();
$channel = $saved['channel'];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && isset($_SESSION['update_outcome'])) {
    // shown once, after the redirect below
    ['result' => $result, 'error' => $error, 'file' => $fromFile] = $_SESSION['update_outcome'] + ['result' => null, 'error' => null, 'file' => false];
    unset($_SESSION['update_outcome']);
}
if (isset($_GET['check'])) {   // "Check Again": a fresh look at GitHub, then the page's own address (a reload doesn't ask again)
    m3d_session_pause(fn() => gh_check($channel, true));
    m3d_redirect('/admin/update.php', 303);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $back = '/admin/update.php';
    try {
        if (!m3d_check_csrf($_POST['csrf'] ?? null)) throw new RuntimeException('Session expired, reload the page and try again.');
        if ($do === 'channel' || $do === 'forget-key') {
            $back = '/admin/update.php#channel';
            $ch = $do === 'channel' ? (string) ($_POST['channel'] ?? '') : $channel;
            if (!isset(UPDATE_CHANNELS[$ch])) throw new RuntimeException('Pick a channel.');
            $key = $do === 'channel' && is_string($_POST['token'] ?? null) ? trim($_POST['token']) : '';
            if ($key !== '' && !preg_match('/^[A-Za-z0-9_]{20,255}$/', $key)) throw new RuntimeException('That doesn’t look like a GitHub key: copy the whole token (it starts with github_pat_).');
            if ($do === 'channel' && $key === '') $key = $saved['token'];   // an empty field keeps the saved key
            if (!gh_save($ch, $key)) throw new RuntimeException('Could not write ' . basename(M3D_PRIVATE) . '/github.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.');
            m3d_redirect($back, 303);
        }
        if ($do === 'install') {
            $v = (string) ($_POST['version'] ?? '');
            if (!preg_match('/^\d{4}\.\d{2}\.\d{2}-\d+$/', $v)) throw new RuntimeException('No version chosen.');
            // with the login unlocked meanwhile (m3d_session_pause()), so the site doesn't stall while it runs
            [$backup, $written, $newVersion] = m3d_session_pause(fn() => gh_install($channel, $v));
        } else {
            $f = $_FILES['zip'] ?? null;
            if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) throw new RuntimeException('No file received (it may be larger than the server allows).');
            if ($f['size'] > UPDATE_MAX) throw new RuntimeException('This zip is too large for a site update.');
            $zip = new ZipArchive();
            if ($zip->open($f['tmp_name']) !== true) throw new RuntimeException('This is not a valid zip file.');

            // A site update has app/bootstrap.php and app/version.php at the top level of the zip
            for ($i = 0, $ok = false; $i < $zip->numFiles; $i++) if ($zip->getNameIndex($i) === 'app/bootstrap.php') $ok = true;
            if (!$ok) throw new RuntimeException('This zip is not a site update. Upload the update zip as you received it (don’t re-zip the folder).');
            $lock = code_lock();
            keep_running();
            [$backup, $written, $newVersion] = m3d_session_pause(fn() => install_with_backup($zip));
            $zip->close();
        }
        $result = ['files' => $written, 'from' => M3D_VERSION, 'to' => $newVersion ?? '?', 'backup' => $backup];
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
    // Post/Redirect/Get: a refresh of the result page doesn't send the zip again
    $_SESSION['update_outcome'] = ['result' => $result, 'error' => $error, 'file' => $do !== 'install' && $do !== 'channel' && $do !== 'forget-key'];
    m3d_redirect($back, 303);
}
admin_start('Update', 'update');   // part of Settings: its menu links here

$chName = UPDATE_CHANNELS[$channel][0];
$check = gh_check($channel);
$newer = gh_newer($check);
$newest = $check['releases'][0] ?? null;
$installed = $result['to'] ?? M3D_VERSION;
$ago = max(0, time() - (int) $check['at']);
$agoText = $ago < 60 ? 'just now' : ($ago < 3600 ? m3d_plural(intdiv($ago, 60), 'minute') . ' ago' : m3d_plural(intdiv($ago, 3600), 'hour') . ' ago');
$expires = $check['expires'] !== '' && ($t = strtotime($check['expires'])) ? (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('j M Y') : '';
const UPDATE_SHOWN = 10;   // versions listed in "What changed"
const CHANNEL_ICONS = [
    'stable' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',                             // shield with a tick
    'dev'    => '<path d="M10 2v7.3"/><path d="M14 9.3V2"/><path d="M8.5 2h7"/><path d="M14 9.3a6.5 6.5 0 1 1-4 0"/><path d="M5.5 16h13"/>',   // flask
];
// the saved key, on the Development channel: a tick (works, with its expiry) or a warning (why not) at the end of its field
$keyState = $saved['token'] !== '' && $channel === 'dev' ? ($check['error'] === '' ? 'ok' : 'bad') : '';
$keyText = $keyState === 'ok' ? 'Key works' . ($expires !== '' ? ' · expires ' . $expires : '') : $check['error'];
?>
    <div class="admin-head">
      <div>
        <h1>Update the Site</h1>
      </div>
    </div>
    <?= settings_menu('update') ?>

    <?php if ($result): ?>
      <section class="sec ok-box">
        <p><b class="res-ok">Update installed ✓</b> <?= $result['files'] ? (int) $result['files'] . ' files changed' : 'No file needed changing' ?> (<?= e($result['from']) ?> → <?= e($result['to']) ?>).</p>
        <?php if ($result['backup'] !== ''): ?><p class="muted small">The previous code was saved as <code><?= e($result['backup']) ?></code>: you can go back to it in <a href="/admin/backups.php#code">Backups</a>.</p><?php endif; ?>
        <p><a class="btn btn-ghost btn-sm" href="/" target="_blank">Check the Site ↗</a></p>
      </section>
    <?php elseif ($error): ?>
      <section class="sec err-box"><p><b class="res-err">Not done.</b> <?= e($error) ?></p></section>
    <?php endif ?>

    <section class="sec up-card" style="margin-top:20px">
      <div class="up-head">
        <h2 class="small-h">Update from Remote</h2>
        <span class="badge ic tone-grey"><svg viewBox="0 0 24 24" aria-hidden="true"><?= CHANNEL_ICONS[$channel] ?></svg><?= e($chName) ?></span>
      </div>
      <div class="up-row">
        <div><span>Installed</span><div class="up-val"><b><?= e($installed) ?></b><?php
          if ($check['error'] === '' && $newest && !$newer): ?><em class="up-ok"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>Up to Date</em><?php endif ?></div></div>
        <?php if ($newest && $newer): ?>
          <div class="up-arrow" aria-hidden="true">→</div>
          <div><span>Newest <?= e($chName) ?> Version</span><b><?= e($newest['v']) ?></b></div>
        <?php endif ?>
      </div>
      <?php if ($check['error'] !== ''): ?>
        <p class="up-msg res-err"><?= e($check['error']) ?></p>
      <?php elseif ($newer): ?>
        <p class="muted small up-msg"><?= count($newer) === 1 ? 'New Version Available' : count($newer) . ' New Versions Available' ?></p>
        <ul class="up-list">
          <?php foreach (array_slice($newer, 0, UPDATE_SHOWN) as $r): ?>
            <li><h3><?= e($r['v']) ?> <small>· <?= e((new DateTimeImmutable('@' . $r['at']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('j M, H:i T')) ?></small></h3>
              <?= gh_notes_html($r['notes']) ?></li>
          <?php endforeach ?>
        </ul>
        <?php if (count($newer) > UPDATE_SHOWN): ?><p class="muted small up-msg">…and <?= m3d_plural(count($newer) - UPDATE_SHOWN, 'older version') ?>.</p><?php endif ?>
      <?php elseif (!$newest): ?>
        <p class="muted small up-msg">No version has been published on the <?= e($chName) ?> channel yet.</p>
      <?php elseif (version_compare($installed, $newest['v'], '>')): ?>
        <p class="muted small up-msg">The installed version is newer than the newest <?= e($chName) ?> one (<?= e($newest['v']) ?>): nothing to install.</p>
      <?php endif ?>
      <form method="post" class="form-actions" data-busy="Installing… don’t close this page">
        <?= form_keys('install') ?>
        <span class="muted small act-left">Last checked <?= e($agoText) ?>.</span>
        <a class="btn btn-ghost btn-sm" href="/admin/update.php?check=1">Check Again</a>
        <?php if ($newer): ?><input type="hidden" name="version" value="<?= e($newer[0]['v']) ?>" /><button class="btn btn-primary">Install <?= e($newer[0]['v']) ?></button><?php endif ?>
      </form>
    </section>

    <section class="sec up-card" id="channel" style="margin-top:20px">
      <h2 class="small-h">Update Channel<?= tip('Stable gets the versions published for everyone. Development gets every change as soon as it is merged, and needs a GitHub key.') ?></h2>
      <form method="post" class="up-card ch-form">
        <?= form_keys('channel') ?>
        <div class="vis-seg ch-seg" role="radiogroup" aria-label="Update channel">
          <?php foreach (UPDATE_CHANNELS as $k => [$name]): ?>
            <label class="vis-opt"><input type="radio" name="channel" value="<?= e($k) ?>" <?= $k === $channel ? 'checked' : '' ?> /><span class="vis-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><?= CHANNEL_ICONS[$k] ?></svg></span><span><?= e($name) ?></span></label>
          <?php endforeach ?>
        </div>
        <div class="ch-key">
          <label class="field"><span class="lbl">GitHub Key<?= tip('A fine-grained token made on GitHub (Settings → Developer settings → Fine-grained tokens) with access to ' . gh_repo('dev') . ' only and “Contents: Read-only”. It can’t change anything on GitHub.') ?></span>
            <span class="ch-key-in"><input class="input" type="password" name="token" autocomplete="off" maxlength="255" placeholder="<?= $saved['token'] !== '' ? 'Saved: paste a new key to replace it' : 'github_pat_…' ?>" /><?php
              if ($keyState !== ''): $keyOk = $keyState === 'ok'; ?><button type="button" class="tip key-st <?= $keyOk ? 'ok' : 'bad' ?>" data-tip="<?= e($keyText) ?>" aria-label="<?= e($keyText) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $keyOk ? '<path d="M20 6 9 17l-5-5"/>' : '<path d="M12 8v5"/><path d="M12 16.5v.5"/><circle cx="12" cy="12" r="9.5"/>' ?></svg></button><?php endif ?></span></label>
        </div>
        <div class="form-actions">
          <?php if ($saved['token'] !== ''): ?><button class="btn btn-ghost btn-sm" form="forget-key">Remove Key</button><?php endif ?>
          <button class="btn btn-primary btn-sm">Save Channel</button>
        </div>
      </form>
      <form method="post" id="forget-key" hidden onsubmit="return confirm('Remove the GitHub key? The Development channel stops working until you add one again.')"><?= form_keys('forget-key') ?></form>
    </section>

    <details style="margin-top:20px" class="sec fold"<?= $fromFile && $error ? ' open' : '' ?>>
      <summary><h2 class="small-h">Update from File<?= tip('For an update zip you received by hand. It is installed the same way: the current code is saved first.') ?></h2></summary>
      <div class="fold-body">
        <form method="post" enctype="multipart/form-data" class="update-form" data-busy="Installing… don’t close this page">
          <?= form_keys('upload') ?>
          <label class="field">
            <span class="lbl">Update File</span>
            <input class="input" type="file" name="zip" accept=".zip,application/zip" required />
          </label>
          <div class="form-actions"><button class="btn btn-primary">Install Update</button></div>
        </form>
      </div>
    </details>

<?php admin_end();
