<?php
// /scenes/<slug>/  (rewritten here by .htaccess): a scene (kind 'scenes'): a Blender scene made with some models. A wide picture goes across
// the page on top, an upright one on the left like a model page's (Pi, `.is-tall`); the models in it link to their pages ("Models in This Scene").
require __DIR__ . '/app/_init.php';

$slug = m3d_get_text('slug');
$sc = m3d_item('scenes', $slug);
if (!$sc) not_found();
item_gate('scenes', $sc);
$blur = m3d_pics_hidden('scenes', $sc);   // blurred pictures: tiny copies only, no slideshow

$images = item_pics('scenes', $sc);   // with the viewer's "Download Full Size" on pictures that have a full size link
$preview = $images[0]['src'] ?? '';
$models = scene_models($sc);
$others = array_values(array_filter(m3d_listed_items('scenes'), fn($x) => $x['slug'] !== $sc['slug'] && array_intersect($x['models'] ?? [], $sc['models'] ?? [])));

if ($preview !== '' && m3d_visibility($sc) === 'public') page_image($preview);   // link previews: public scenes only
page_preview('scenes', $sc, $preview !== '' && m3d_visibility($sc) === 'public' ? $preview : '');
$renders = showcase_tiles('scenes', $sc, count($images));   // patron renders, as on accessory pages
showcase_flash();
$names = array_map('m3d_model_name', $models);
layout_start($sc['name'] . ' (scene)', meta_text((string) ($sc['description'] ?? ''), $sc['name'] . ', a Blender scene' . ($names ? ' with ' . implode(', ', array_slice($names, 0, 3)) : '') . '.'), 'page-model page-outfit page-scene');
?>
  <div class="wrap">
    <a class="back" href="/scenes/" data-back>← All Scenes</a>
    <?= visibility_banner('scenes', $sc) ?>
    <div class="model scene<?= item_is_wide('scenes', $preview) ? ' is-wide' : ' is-tall' ?>">
      <?= item_media('scenes', $sc, ['name' => $sc['name'], 'franchise' => 'Scene'], $preview, array_merge($images, $renders[1]), $blur) ?>

      <div class="info item-info">
        <div class="item-main">
          <div class="title-row">
            <p class="eyebrow eyebrow-type"><svg class="type-ico" viewBox="0 0 24 24" aria-hidden="true"><?= SCENE_ICON ?></svg>Scene</p>
            <h1><?= e($sc['name']) ?></h1>
            <?= bookmark_button('scenes', $sc['slug']) ?><?= like_button($sc['slug'], 'scenes') ?>
          </div>
          <?= admin_edit_button('/admin/edit.php?kind=scenes&slug=' . rawurlencode($sc['slug']), 'Edit Scene') ?>
          <dl class="facts">
            <?= fact_latest($sc) ?>
            <?php if (!empty($sc['blender'])): ?><dt>Blender</dt><dd><?= e($sc['blender']) ?></dd><?php endif ?>
            <?php if ($models): ?>
              <dt>Models in This Scene</dt>
              <dd class="fit-dd"><?= model_strip($models, array_map('strval', (array) ($sc['modelVers'] ?? []))) ?></dd>
            <?php endif ?>
            <?= fact_downloads('scenes', $sc['slug']) ?>
          </dl>
          <?php if (!empty($sc['description'])): ?><div class="desc md"><?= md_html((string) $sc['description'], ['noImages' => $blur]) ?></div><?php endif ?>
        </div>
        <div class="item-side">
          <?= downloads_box('scenes', $sc) ?>

          <?= start_box_html('scenes') ?>

          <?= bug_item_box('scenes', $sc) ?>

          <?= showcase_box('scenes', $sc) ?>
        </div>
      </div>
    </div>

    <?= gallery_strip($sc['name'], array_slice($images, 1), 1, $images, $blur, $renders) ?>

    <?php if (!empty($sc['changelog'])): ?>
      <div class="details details-single"><div class="main-col">
        <?= changelog_block($sc['changelog'], $sc['changelogNote'] ?? null, $blur, changelog_mine('scenes', $sc)) ?>
      </div></div>
    <?php endif ?>

    <?php if ($others): ?>
      <section class="section rel-sec">
        <h2 class="h2 rel-title">More Scenes with the Same Models</h2>
        <?= like_strip(array_map('scene_card', $others), 'scenes', 'rel-strip scene-strip') ?>
      </section>
    <?php endif ?>

    <?= ideas_block('scenes', $sc) ?>

    <?= comments_block('scenes', $sc['slug'], $sc, 'scene') ?>
  </div>
<?php layout_end();
