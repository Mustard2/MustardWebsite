<?php
require __DIR__ . '/_admin.php';
admin_require($M3D);

// Page text of the public Accessories page (under its title)
$done = $error = null;
if (admin_form_post('intro')) {
    $text = clean_text($_POST['intro'] ?? '', 5000);
    if (m3d_save_settings(['outfits_intro' => $text])) $done = $text === '' ? 'Page text removed from the Accessories page.' : 'Page text saved.';
    else $error = m3d_write_error();
}
[$startDone, $startError] = admin_start_post('outfits');
$done ??= $startDone; $error ??= $startError;

$outfits = m3d_outfits();
$stats = m3d_stats();
$lc = lc_results();   // Link check: rows with a dead link turn red
$names = [];
foreach (m3d_models() as $m) $names[$m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' G' . $m['generation'] : '');

admin_start('Accessories', 'outfits');
$cmOn = m3d_comments_on();   // the Comments column (app/comments.php)
?>
    <div class="admin-head">
      <div>
        <h1>Accessories</h1>
      </div>
      <a class="btn btn-primary" href="/admin/edit.php?kind=outfits">+ Add Accessory</a>
    </div>
    <?= assets_menu('outfits') ?>

    <?php admin_result($done, $error) ?>

    <details class="sec intro-sec"<?= ($done || $error) && !$startDone && !$startError ? ' open' : '' ?>>
      <summary><b>Page Text</b> <span class="muted small">under the title of the <a href="/accessories/" target="_blank">Accessories page</a></span></summary>
      <form method="post" class="intro-form">
        <?= form_keys('intro') ?>
        <textarea class="input" name="intro" rows="5" maxlength="5000" placeholder="Empty = no text" data-img-drop="misc"><?= e(outfits_intro()) ?></textarea>
        <?= format_help('How to Format the Text', ['box', 'fold', 'image', 'carousel', 'card']) ?>
        <div class="form-actions"><button class="btn btn-primary btn-sm">Save Text</button></div>
      </form>
    </details>
    <?= admin_start_box('outfits', (bool) ($startDone || $startError)) ?>

    <?php if (!$outfits): ?>
      <p class="muted">No accessories yet.</p>
    <?php else: ?>
      <?= admin_batch_bar('outfits') ?>
      <div class="table-wrap">
        <table class="models-table">
          <thead><tr><th class="c-order"></th><th class="c-check"><input type="checkbox" data-batch-all aria-label="Select all" /></th><th></th><th>Accessory</th><th>Models</th><th>Version</th><th>Links</th><?= admin_icon_th('Downloads') ?><?= admin_icon_th('Likes') ?><?= $cmOn ? admin_icon_th('Comments') : '' ?><th></th></tr></thead>
          <tbody data-sortable data-kind="outfits">
            <?php foreach ($outfits as $o): $prev = outfit_preview($o); $dead = lc_dead_for($o, $lc); ?>
              <tr<?= $dead ? ' class="has-dead-link"' : '' ?> data-slug="<?= e($o['slug']) ?>" data-vis="<?= e(m3d_visibility($o)) ?>" data-search="<?= e(admin_search_text([$o['name'], m3d_accessory_type($o), $o['slug'], m3d_visibility($o), m3d_fits_all($o) ? 'all models' : implode(' ', array_map(fn($s) => $names[$s] ?? $s, $o['models'] ?? []))])) ?>">
                <td class="c-order"><button class="drag-handle" type="button" title="Drag to reorder (or focus and use ↑ ↓)" aria-label="Reorder <?= e($o['name']) ?>">⠿</button></td>
                <td class="c-check"><input type="checkbox" data-batch-row aria-label="Select <?= e($o['name']) ?>" /></td>
                <td class="c-thumb"><?php if ($prev): ?><img src="<?= e(img_small($prev)) ?>" alt="" loading="lazy" /><?php else: ?><span class="no-img"></span><?php endif ?></td>
                <td><div class="m-line"><a class="m-name" href="/admin/edit.php?kind=outfits&slug=<?= e($o['slug']) ?>"><?= e($o['name']) ?></a><?= admin_visibility_badge($o) ?><?= admin_req_badge($o) ?><?= admin_release_badge($o) ?></div><div class="muted small m-sub"><?= e(m3d_accessory_type($o)) ?></div></td>
                <td class="small"><?= m3d_fits_all($o) ? 'All Models' : ($o['models'] ?? [] ? e(implode(', ', array_map(fn($s) => $names[$s] ?? $s, $o['models']))) : '<span class="warn-dot">None</span>') ?></td>
                <td class="small"><?php $lv = m3d_latest_version($o); ?><?= $lv['version'] !== '' ? e(m3d_version_label($lv)) : '<span class="muted" title="Add a version in the changelog">—</span>' ?><?php if ($lv['date'] !== ''): ?><div class="muted"><?= e($lv['date']) ?></div><?php endif ?></td>
                <td class="small c-links"><?= admin_links_summary($o['links'] ?? [], $dead) ?></td>
                <td class="small c-num"><?= admin_dl_count('outfits', $o['slug'], $stats) ?></td>
                <td class="small c-num"><?php $lk = m3d_like_count($o['slug'], 'outfits'); ?><?= $lk ? '<span class="num likes-num">♥ ' . $lk . '</span>' : '<span class="muted">0</span>' ?></td>
                <?php if ($cmOn): ?><td class="small c-num"><?= admin_comments_cell('outfits', $o) ?></td><?php endif ?>
                <td class="c-actions">
                  <a class="btn btn-ghost btn-sm" href="/admin/edit.php?kind=outfits&slug=<?= e($o['slug']) ?>">Edit</a>
                  <a class="btn btn-ghost btn-sm btn-stats" href="/admin/stats.php?of=outfits:<?= e($o['slug']) ?>" title="Stats" aria-label="Stats of <?= e($o['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5h16M7 16V11M12 16V6M17 16v-3"/></svg></a>
                  <a class="btn btn-ghost btn-sm" href="/accessories/<?= e($o['slug']) ?>/" target="_blank" title="Open the public page">↗</a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
<?php admin_end();
