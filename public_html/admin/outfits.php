<?php
require __DIR__ . '/_admin.php';
admin_require($M3D);

// Page text of the public Accessories page (under its title)
$done = $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'intro') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $text = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['intro'] ?? ''))), 0, 5000);
    if (m3d_save_settings(['outfits_intro' => $text])) $done = $text === '' ? 'Page text removed from the Accessories page.' : 'Page text saved.';
    else $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
}
[$startDone, $startError] = admin_start_post('outfits');
$done ??= $startDone; $error ??= $startError;

$outfits = m3d_outfits();
$stats = m3d_stats();
$names = [];
foreach (m3d_models() as $m) $names[$m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' G' . $m['generation'] : '');

admin_start('Accessories', 'outfits');
?>
    <div class="admin-head">
      <div>
        <h1>Accessories</h1>
      </div>
      <a class="btn btn-primary" href="/admin/edit.php?kind=outfits">+ Add accessory</a>
    </div>

    <?php if ($done): ?><p class="notice"><b class="res-ok">✓</b> <?= e($done) ?></p><?php endif ?>
    <?php if ($error): ?><p class="notice"><b class="res-err">Not saved:</b> <?= e($error) ?></p><?php endif ?>

    <details class="sec intro-sec"<?= ($done || $error) && !$startDone && !$startError ? ' open' : '' ?>>
      <summary><b>Page text</b> <span class="muted small">under the title of the <a href="/accessories/" target="_blank">Accessories page</a></span></summary>
      <form method="post" class="intro-form">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="intro" />
        <textarea class="input" name="intro" rows="5" maxlength="5000" placeholder="Empty = no text"><?= e(outfits_intro()) ?></textarea>
        <?= format_help('How to format the text', ['para', 'inline', 'small-h', 'list', 'steps', 'box', 'table', 'code', 'image']) ?>
        <div><button class="btn btn-primary btn-sm">Save text</button></div>
      </form>
    </details>
    <?= admin_start_box('outfits', (bool) ($startDone || $startError)) ?>

    <?php if (!$outfits): ?>
      <p class="muted">No accessories yet.</p>
    <?php else: ?>
      <?= admin_batch_bar('outfits') ?>
      <div class="table-wrap">
        <table class="models-table">
          <thead><tr><th class="c-order"></th><th class="c-check"><input type="checkbox" data-batch-all aria-label="Select all" /></th><th></th><th>Accessory</th><th>Models</th><th>Version</th><th>Links</th><th class="c-num">Downloads</th><th class="c-num">Likes</th><th></th></tr></thead>
          <tbody data-sortable data-kind="outfits">
            <?php foreach ($outfits as $o): $prev = outfit_preview($o); ?>
              <tr data-slug="<?= e($o['slug']) ?>" data-vis="<?= e(m3d_visibility($o)) ?>" data-search="<?= e(admin_search_text([$o['name'], m3d_accessory_type($o), $o['slug'], m3d_visibility($o), implode(' ', array_map(fn($s) => $names[$s] ?? $s, $o['models'] ?? []))])) ?>">
                <td class="c-order"><button class="drag-handle" type="button" title="Drag to reorder (or focus and use ↑ ↓)" aria-label="Reorder <?= e($o['name']) ?>">⠿</button></td>
                <td class="c-check"><input type="checkbox" data-batch-row aria-label="Select <?= e($o['name']) ?>" /></td>
                <td class="c-thumb"><?php if ($prev): ?><img src="<?= e(img_small($prev)) ?>" alt="" loading="lazy" /><?php else: ?><span class="no-img"></span><?php endif ?></td>
                <td><a class="m-name" href="/admin/edit.php?kind=outfits&slug=<?= e($o['slug']) ?>"><?= e($o['name']) ?></a><?= admin_visibility_badge($o) ?><?php $rq = m3d_requirements($o); if ($rq['months'] || $rq['total_cents']): ?> <span class="badge tone-sky" title="Extra download requirements on top of a paid tier">🔒 <?= e(implode($rq['mode'] === 'or' ? ' or ' : ' · ', array_filter([$rq['months'] ? m3d_plural($rq['months'], 'month') : '', $rq['total_cents'] ? m3d_money($rq['total_cents']) : '']))) ?></span><?php endif ?><div class="muted small"><?= e(m3d_accessory_type($o)) ?></div></td>
                <td class="small"><?= $o['models'] ?? [] ? e(implode(', ', array_map(fn($s) => $names[$s] ?? $s, $o['models']))) : '<span class="warn-dot">None</span>' ?></td>
                <td class="small"><?php $lv = m3d_latest_version($o); ?><?= $lv['version'] !== '' ? e($lv['version']) : '<span class="muted" title="Add a version in the changelog">—</span>' ?><?php if ($lv['date'] !== ''): ?><div class="muted"><?= e($lv['date']) ?></div><?php endif ?></td>
                <td class="small"><?= admin_links_summary($o['links'] ?? []) ?></td>
                <td class="small c-num"><?= admin_dl_count('outfits', $o['slug'], $stats) ?></td>
                <td class="small c-num"><?php $lk = m3d_like_count($o['slug'], 'outfits'); ?><?= $lk ? '<span class="num likes-num">♥ ' . $lk . '</span>' : '<span class="muted">0</span>' ?></td>
                <td class="c-actions">
                  <a class="btn btn-ghost btn-sm" href="/admin/edit.php?kind=outfits&slug=<?= e($o['slug']) ?>">Edit</a>
                  <a class="btn btn-ghost btn-sm" href="/accessories/<?= e($o['slug']) ?>/" target="_blank" title="Open the public page">↗</a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
<?php admin_end();
