<?php
// /accessories/<slug>/  (rewritten here by .htaccess): an accessory (outfit, hair, necklace… kind 'outfits' in the data)
require __DIR__ . '/app/_init.php';

$slug = m3d_get_text('slug');
$o = m3d_outfit($slug);
if (!$o) not_found();
item_gate('outfits', $o);
$blur = m3d_pics_hidden('outfits', $o);   // blurred pictures: tiny copies only, no slideshow
$type = m3d_accessory_type($o);

$images = item_pics('outfits', $o);   // with the viewer's "Download Full Size" on pictures that have a full size link
$preview = outfit_main($o);   // the Main Image, else the Catalog Image, else the first picture
$own = $preview !== '' && $preview !== ($images[0]['src'] ?? '');   // a picture of its own on top: every picture goes in the row under it
$slides = $own ? array_merge([['src' => $preview, 'caption' => '']], $images) : $images;   // the viewer's set, the main picture first
$all = m3d_fits_all($o);   // made for every model: "All Models" instead of the tiles
$forBlender = m3d_fits_blender($o);   // made for Blender, no model: one "Blender" pill; its related accessories are the other Blender ones
$models = $all || $forBlender ? [] : array_values(array_filter(array_map('m3d_model', $o['models'] ?? []), fn($m) => $m && m3d_listed($m)));
$others = array_values(array_filter(m3d_listed_items('outfits'), fn($x) => $x['slug'] !== $o['slug']
    && ($forBlender ? m3d_fits_blender($x) : !m3d_fits_blender($x) && ($all || m3d_fits_all($x) || array_intersect($x['models'] ?? [], $o['models'] ?? [])))));

if ($preview !== '' && m3d_visibility($o) === 'public') page_image($preview);   // link previews: public accessories only
page_preview('outfits', $o, $preview !== '' && m3d_visibility($o) === 'public' ? $preview : '');   // the facts instead of the description, and Discord's own card
$renders = showcase_tiles('outfits', $o, count($slides));   // patron renders: approved ones after the creator's pictures, waiting ones first for their author and the creator
showcase_flash();
layout_start($o['name'] . ' (' . mb_strtolower($type) . ')', meta_text((string) ($o['description'] ?? ''), $o['name'] . ', ' . (preg_match('/^[aeiou]/i', $type) ? 'an ' : 'a ') . mb_strtolower($type) . ' for the ' . site_name() . ' models.'), 'page-model page-outfit');
?>
  <div class="wrap">
    <a class="back" href="/accessories/" data-back>← All Accessories</a>
    <?= visibility_banner('outfits', $o) ?>
    <div class="model<?= item_is_wide('outfits', $preview) ? ' is-wide' : '' ?>">
      <?= item_media('outfits', $o, ['name' => $o['name'], 'franchise' => $type], $preview, array_merge($slides, $renders[1]), $blur) ?>

      <div class="info item-info">
        <div class="item-main">
        <div class="title-row">
          <p class="eyebrow eyebrow-type"><?= accessory_icon($type) ?><?= e($type) ?></p>
          <h1><?= e($o['name']) ?></h1>
          <?= bookmark_button('outfits', $o['slug']) ?><?= like_button($o['slug'], 'outfits') ?>
        </div>
        <?= admin_edit_button('/admin/edit.php?kind=outfits&slug=' . rawurlencode($o['slug']), 'Edit Accessory') ?>
        <dl class="facts">
          <?= fact_latest($o) ?>
          <?php if ($forBlender): ?>
            <dt>Optimized For</dt>
            <dd><?= fit_blender_pill($o) ?></dd>
          <?php elseif ($all): ?>
            <dt>Optimized For</dt>
            <dd><?= fit_all_pill() ?></dd>
          <?php elseif ($models): ?>
            <dt>Optimized For</dt>
            <dd class="fit-dd"><?= model_strip($models) ?></dd>
          <?php endif ?>
          <?php if (!$forBlender && trim((string) ($o['blender'] ?? '')) !== ''): ?><dt>Blender</dt><dd><?= e($o['blender']) ?></dd><?php endif ?>
          <?= fact_downloads('outfits', $o['slug']) ?>
        </dl>
        <?php if (!empty($o['description'])): ?><div class="desc md"><?= md_html((string) $o['description'], ['noImages' => $blur]) ?></div><?php endif ?>

        </div>
        <div class="item-side">
        <?= downloads_box('outfits', $o) ?>

        <?= start_box_html('outfits') ?>

        <?= bug_item_box('outfits', $o) ?>

        <?= showcase_box('outfits', $o) ?>
        </div>
      </div>
    </div>

    <?= gallery_strip($o['name'], $own ? $images : array_slice($images, 1), 1, $slides, $blur, $renders) ?>

    <?php if (!empty($o['changelog'])): ?>
      <div class="details details-single"><div class="main-col">
        <?= changelog_block($o['changelog'], $o['changelogNote'] ?? null, $blur, changelog_mine('outfits', $o)) ?>
      </div></div>
    <?php endif ?>

    <?php if ($others): ?>
      <section class="section rel-sec">
        <h2 class="h2 rel-title">More Accessories for the Same Models</h2>
        <?= like_strip(array_map('outfit_card', $others), 'accessories', 'rel-strip') ?>
      </section>
    <?php endif ?>

    <?= ideas_block('outfits', $o) ?>

    <?= comments_block('outfits', $o['slug'], $o, 'accessory') ?>
  </div>
<?php layout_end();
