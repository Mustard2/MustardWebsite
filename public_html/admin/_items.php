<?php
// Admin → Assets → Accessories (outfits.php) and → Scenes (scenes.php): the list, the page text under the public page's title and the Getting
// started box; both pages set $kind and include this (.htaccess denies the file itself, like every "_" file).
require __DIR__ . '/_admin.php';
if (!in_array($kind ?? '', ['outfits', 'scenes'], true)) exit;
admin_require($M3D);
$scenes = $kind === 'scenes';
$word = m3d_kind_word($kind, false, true);   // "Accessory" / "Scene"
$title = m3d_kind_word($kind, true, true);   // "Accessories" / "Scenes"
$intro = $scenes ? 'scenes_intro' : 'outfits_intro';   // settings.json key and its getter in view.php

// Page text of the public page (under its title)
$done = $error = null;
if (admin_form_post('intro')) {
    $text = clean_text($_POST['intro'] ?? '', 5000);
    if (m3d_save_settings([$intro => $text])) $done = $text === '' ? 'Page text removed.' : 'Page text saved.';
    else $error = m3d_write_error();
}
[$startDone, $startError] = admin_start_post($kind);
$done ??= $startDone; $error ??= $startError;

$items = m3d_items($kind);
$stats = m3d_stats();
$lc = lc_results();   // Link check: rows with a dead link turn red
$names = [];
foreach (m3d_models() as $m) $names[$m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' G' . $m['generation'] : '');
$modelNames = fn(array $o): string => implode(' ', array_map(fn($s) => $names[$s] ?? $s, $o['models'] ?? []));   // what the search finds

admin_start($title, $kind);
$cmOn = m3d_comments_on();   // the Comments column (app/comments.php)
?>
    <div class="admin-head">
      <div>
        <h1><?= e($title) ?></h1>
      </div>
      <a class="btn btn-primary" href="/admin/edit.php?kind=<?= $kind ?>">+ Add <?= e($word) ?></a>
    </div>
    <?= assets_menu($kind) ?>

    <?php admin_result($done, $error) ?>

    <details class="sec intro-sec"<?= ($done || $error) && !$startDone && !$startError ? ' open' : '' ?>>
      <summary><b>Page Text</b> <span class="muted small">under the title of the <a href="<?= $scenes ? '/scenes/' : '/accessories/' ?>" target="_blank"><?= e($title) ?> page</a></span></summary>
      <form method="post" class="intro-form">
        <?= form_keys('intro') ?>
        <textarea class="input" name="intro" rows="5" maxlength="5000" placeholder="Empty = no text" data-img-drop="misc"><?= e($intro()) ?></textarea>
        <?= format_help('How to Format the Text', ['box', 'fold', 'image', 'carousel', 'card']) ?>
        <div class="form-actions"><button class="btn btn-primary btn-sm">Save Text</button></div>
      </form>
    </details>
    <?= admin_start_box($kind, (bool) ($startDone || $startError)) ?>

    <?php if (!$items): ?>
      <p class="muted">No <?= e(m3d_kind_word($kind, true)) ?> yet.</p>
    <?php else: ?>
      <?= admin_batch_bar($kind) ?>
      <div class="table-wrap">
        <table class="models-table">
          <thead><tr><th class="c-order"></th><th class="c-check"><input type="checkbox" data-batch-all aria-label="Select all" /></th><th></th><th><?= e($word) ?></th><th>Models</th><th>Version</th><th>Links</th><?= admin_icon_th('Downloads') ?><?= admin_icon_th('Likes') ?><?= $cmOn ? admin_icon_th('Comments') : '' ?><th></th></tr></thead>
          <tbody data-sortable data-kind="<?= $kind ?>">
            <?php foreach ($items as $o): $prev = outfit_preview($o); $dead = lc_dead_for($o, $lc); ?>
              <tr<?= $dead ? ' class="has-dead-link"' : '' ?> data-slug="<?= e($o['slug']) ?>" data-vis="<?= e(m3d_visibility($o)) ?>" data-search="<?= e(admin_search_text($scenes ? [$o['name'], $o['slug'], m3d_visibility($o), $modelNames($o)]
                : [$o['name'], m3d_accessory_type($o), $o['slug'], m3d_visibility($o), m3d_fits_blender($o) ? 'blender' : (m3d_fits_all($o) ? 'all models' : $modelNames($o))])) ?>">
                <td class="c-order"><button class="drag-handle" type="button" title="Drag to reorder (or focus and use ↑ ↓)" aria-label="Reorder <?= e($o['name']) ?>">⠿</button></td>
                <td class="c-check"><input type="checkbox" data-batch-row aria-label="Select <?= e($o['name']) ?>" /></td>
                <td class="c-thumb"><?php if ($prev): ?><img src="<?= e(img_small($prev)) ?>" alt="" loading="lazy" /><?php else: ?><span class="no-img"></span><?php endif ?></td>
                <td><div class="m-line"><a class="m-name" href="/admin/edit.php?kind=<?= $kind ?>&slug=<?= e($o['slug']) ?>"><?= e($o['name']) ?></a><?= admin_visibility_badge($o) ?><?= admin_req_badge($o) ?><?= admin_release_badge($o) ?></div><?php if (!$scenes): ?><div class="muted small m-sub"><?= e(m3d_accessory_type($o)) ?></div><?php endif ?></td>
                <td class="small"><?= !$scenes && m3d_fits_blender($o) ? 'Blender' : (!$scenes && m3d_fits_all($o) ? 'All Models' : ($o['models'] ?? [] ? e(implode(', ', array_map(fn($s) => ($names[$s] ?? $s) . ($scenes && ($v = m3d_scene_ver($o, (string) $s)) !== '' ? ' · ' . bug_version_words($v) : ''), $o['models']))) : '<span class="warn-dot">None</span>')) ?></td>
                <td class="small"><?php $lv = m3d_latest_version($o); ?><?= $lv['version'] !== '' ? e(m3d_version_label($lv)) : '<span class="muted" title="Add a version in the changelog">—</span>' ?><?php if ($lv['date'] !== ''): ?><div class="muted"><?= e($lv['date']) ?></div><?php endif ?></td>
                <td class="small c-links"><?= admin_links_summary($o['links'] ?? [], $dead) ?></td>
                <td class="small c-num"><?= admin_dl_count($kind, $o['slug'], $stats) ?></td>
                <td class="small c-num"><?php $lk = m3d_like_count($o['slug'], $kind); ?><?= $lk ? '<span class="num likes-num">♥ ' . $lk . '</span>' : '<span class="muted">0</span>' ?></td>
                <?php if ($cmOn): ?><td class="small c-num"><?= admin_comments_cell($kind, $o) ?></td><?php endif ?>
                <td class="c-actions">
                  <a class="btn btn-ghost btn-sm" href="/admin/edit.php?kind=<?= $kind ?>&slug=<?= e($o['slug']) ?>">Edit</a>
                  <a class="btn btn-ghost btn-sm btn-stats" href="/admin/stats.php?of=<?= $kind ?>:<?= e($o['slug']) ?>" title="Stats" aria-label="Stats of <?= e($o['name']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19.5h16M7 16V11M12 16V6M17 16v-3"/></svg></a>
                  <a class="btn btn-ghost btn-sm" href="<?= e(m3d_item_url($kind, (string) $o['slug'])) ?>" target="_blank" title="Open the public page">↗</a>
                </td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
<?php admin_end();
