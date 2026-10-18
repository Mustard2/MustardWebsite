<?php
require __DIR__ . '/_admin.php';
admin_require($M3D);

[$done, $error] = admin_start_post('models');
$models = m3d_models();
$stats = m3d_stats();
$lc = lc_results();   // Link check: rows with a dead link turn red

admin_start('Models', 'models');
$cmOn = m3d_comments_on();   // the Comments column (app/comments.php)
?>
    <div class="admin-head">
      <div>
        <h1>Models</h1>
      </div>
      <div class="row-btns"><?php if (m3d_smutbase_on()): ?><a class="btn btn-ghost" href="/admin/import.php">Import from SmutBase</a><?php endif ?><a class="btn btn-primary" href="/admin/edit.php">+ Add Model</a></div>
    </div>
    <?= assets_menu('models') ?>

    <?php admin_result($done, $error) ?>
    <?= admin_start_box('models', (bool) ($done || $error)) ?>


    <?= admin_batch_bar('models') ?>
    <div class="table-wrap">
      <table class="models-table">
        <thead><tr><th class="c-order"></th><th class="c-check"><input type="checkbox" data-batch-all aria-label="Select all" /></th><th></th><th>Model</th><th>Status</th><th>Version</th><th>Links</th><?= admin_icon_th('Downloads') ?><?= admin_icon_th('Likes') ?><?= $cmOn ? admin_icon_th('Comments') : '' ?><th></th></tr></thead>
        <tbody data-sortable data-kind="models">
          <?php foreach ($models as $m): $s = status_info(m3d_status($m)); $dead = lc_dead_for($m, $lc); ?>
            <tr<?= $dead ? ' class="has-dead-link"' : '' ?> data-slug="<?= e($m['slug']) ?>" data-vis="<?= e(m3d_visibility($m)) ?>" data-search="<?= e(admin_search_text([$m['name'], $m['franchise'] ?? '', $m['game'] ?? '', ($m['generation'] ?? '') !== '' ? 'gen ' . $m['generation'] : '', $s['label'], $m['slug'], m3d_visibility($m)])) ?>">
              <td class="c-order"><button class="drag-handle" type="button" title="Drag to reorder (or focus and use ↑ ↓)" aria-label="Reorder <?= e($m['name']) ?>">⠿</button></td>
              <td class="c-check"><input type="checkbox" data-batch-row aria-label="Select <?= e($m['name']) ?>" /></td>
              <td class="c-thumb"><?php $t = item_pic('models', $m); ?><?php if ($t): ?><img src="<?= e(img_small($t)) ?>" alt="" loading="lazy" /><?php else: ?><span class="no-img"></span><?php endif ?></td>
              <td>
                <div class="m-line"><a class="m-name" href="/admin/edit.php?slug=<?= e($m['slug']) ?>"><?= e($m['name']) ?></a><?php if (!empty($m['featured'])): ?><span class="badge tone-mustard badge-star" title="Featured: shown in the homepage slideshow" aria-label="Featured">★</span><?php endif ?><?= admin_visibility_badge($m) ?><?= admin_req_badge($m) ?><?= admin_release_badge($m) ?></div>
                <div class="muted small m-sub"><?= e($m['franchise'] ?? '') ?><?= ($m['generation'] ?? '') !== '' ? ' · Gen ' . e($m['generation']) : '' ?></div>
              </td>
              <td><span class="badge tone-<?= e($s['tone']) ?>"><?= e($s['label']) ?></span></td>
              <td class="small"><?php $lv = m3d_latest_version($m); ?><?= $lv['version'] !== '' ? e(m3d_version_label($lv)) : '<span class="muted" title="Add a version in the changelog">—</span>' ?><?php if ($lv['date'] !== ''): ?><div class="muted"><?= e($lv['date']) ?></div><?php endif ?></td>
              <td class="small c-links"><?= admin_links_summary($m['links'] ?? [], $dead) ?></td>
              <td class="small c-num"><?= admin_dl_count('models', $m['slug'], $stats) ?></td>
              <td class="small c-num"><?php $lk = m3d_like_count($m['slug']); ?><?= $lk ? '<span class="num likes-num">♥ ' . $lk . '</span>' : '<span class="muted">0</span>' ?></td>
              <?php if ($cmOn): ?><td class="small c-num"><?= admin_comments_cell('models', $m) ?></td><?php endif ?>
              <td class="c-actions">
                <a class="btn btn-ghost btn-sm" href="/admin/edit.php?slug=<?= e($m['slug']) ?>">Edit</a>
                <a class="btn btn-ghost btn-sm btn-stats" href="/admin/stats.php?of=models:<?= e($m['slug']) ?>" title="Stats" aria-label="Stats of <?= e($m['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5h16M7 16V11M12 16V6M17 16v-3"/></svg></a>
                <a class="btn btn-ghost btn-sm" href="/models/<?= e($m['slug']) ?>/" target="_blank" title="Open the public page">↗</a>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
<?php admin_end();
