<?php
// Installs a site update from a zip (made by make_update.py) directly over the site folder.
// Never touches: media/ (your uploads, except its protective .htaccess) and private/.
// Before installing, the current code is saved to private/backups/site-code-*.zip (last 5),
// and any of them can be restored from Admin → Backups (admin/backups.php).
// Safety: one install at a time (code_lock), it keeps running if the page is closed (keep_running), files are swapped in
// all together with automatic rollback (install_code), and the result is shown after a redirect, so refreshing never
// installs again.
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_backup.php';
admin_require($M3D);

const MAX_ZIP = 25 * 1024 * 1024;

$result = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' && isset($_SESSION['update_outcome'])) {
    ['result' => $result, 'error' => $error] = $_SESSION['update_outcome'];   // shown once, after the redirect below
    unset($_SESSION['update_outcome']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (!m3d_check_csrf($_POST['csrf'] ?? null)) throw new RuntimeException('Session expired, reload the page and try again.');
        $f = $_FILES['zip'] ?? null;
        if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) throw new RuntimeException('No file received (it may be larger than the server allows).');
        if ($f['size'] > MAX_ZIP) throw new RuntimeException('This zip is too large for a site update.');
        $zip = new ZipArchive();
        if ($zip->open($f['tmp_name']) !== true) throw new RuntimeException('This is not a valid zip file.');

        // A site update has app/bootstrap.php and app/version.php at the top level of the zip
        for ($i = 0, $ok = false; $i < $zip->numFiles; $i++) if ($zip->getNameIndex($i) === 'app/bootstrap.php') $ok = true;
        if (!$ok) throw new RuntimeException('This zip is not a site update. Upload the update zip as you received it (don’t re-zip the folder).');
        $lock = code_lock();
        keep_running();
        $backup = backup_code();
        try {
            [$written, $newVersion] = install_code($zip);
        } catch (Throwable $ex) {
            @unlink(bk_dir() . '/' . $backup);   // nothing changed: this copy would only push an older one out of the 5 kept
            throw $ex;
        }
        $zip->close();
        $result = ['files' => $written, 'from' => M3D_VERSION, 'to' => $newVersion ?? '?', 'backup' => $backup];
    } catch (Throwable $ex) {
        $error = $ex->getMessage();
    }
    // Post/Redirect/Get: a refresh of the result page doesn't send the zip again
    $_SESSION['update_outcome'] = ['result' => $result, 'error' => $error];
    m3d_redirect('/admin/update.php', 303);
}

admin_start('Update', 'update');   // part of Settings: its menu links here
?>
    <div class="admin-head">
      <div>
        <h1>Update the site</h1>
        <p class="muted">Installed version: <b><?= e($result['to'] ?? M3D_VERSION) ?></b></p>
      </div>
    </div>
    <?= settings_menu('update') ?>

    <?php if ($result): ?>
      <section class="sec ok-box">
        <p><b class="res-ok">Update installed ✓</b> <?= (int) $result['files'] ?> files updated (<?= e($result['from']) ?> → <?= e($result['to']) ?>).</p>
        <p class="muted small">The previous code was saved as <code><?= e($result['backup']) ?></code>: you can go back to it in <a href="/admin/backups.php#code">Backups</a>.</p>
        <p><a class="btn btn-ghost btn-sm" href="/" target="_blank">Check the site ↗</a></p>
      </section>
    <?php elseif ($error): ?>
      <section class="sec err-box"><p><b class="res-err">Update not installed.</b> <?= e($error) ?></p></section>
    <?php endif ?>

    <section class="sec" style="margin-top:20px">
      <form method="post" enctype="multipart/form-data" class="update-form" data-update-form data-busy="Installing… don’t close this page">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" />
        <label class="field">
          <span class="lbl">Update file</span>
          <input class="input" type="file" name="zip" accept=".zip,application/zip" required />
        </label>
        <div><button class="btn btn-primary">Install update</button></div>
      </form>
    </section>

<?php admin_end();
