<?php
// /accessories/<slug>/  (rewritten here by .htaccess): an accessory (outfit, hair, necklace… kind 'outfits' in the data)
require __DIR__ . '/app/_init.php';

$slug = m3d_get_text('slug');
$o = m3d_outfit($slug);
if (!$o) not_found();
item_gate('outfits', $o);
$blur = m3d_pics_hidden('outfits', $o);   // blurred pictures: tiny copies only, no slideshow
$type = m3d_accessory_type($o);

$images = array_values(array_filter(array_map('as_image', $o['images'] ?? []), fn($g) => $g['src'] !== ''));
$preview = $images[0]['src'] ?? '';
$models = array_values(array_filter(array_map('m3d_model', $o['models'] ?? []), fn($m) => $m && m3d_listed($m)));
$others = array_slice(array_values(array_filter(m3d_listed_items('outfits'), fn($x) => $x['slug'] !== $o['slug']
    && array_intersect($x['models'] ?? [], $o['models'] ?? []))), 0, 4);

if ($preview !== '' && m3d_visibility($o) === 'public') page_image($preview);   // link previews: public accessories only
layout_start($o['name'] . ' (' . mb_strtolower($type) . ')', $o['name'] . ', ' . (preg_match('/^[aeiou]/i', $type) ? 'an ' : 'a ') . mb_strtolower($type) . ' for the ' . site_name() . ' models.', 'page-model page-outfit');
?>
  <div class="wrap">
    <a class="back" href="/accessories/" data-back>← All Accessories</a>
    <?= visibility_banner('outfits', $o) ?>
    <div class="model">
      <?= item_media('outfits', $o, ['name' => $o['name'], 'franchise' => $type], $preview, $images, $blur) ?>

      <div class="info">
        <div class="title-row">
          <p class="eyebrow eyebrow-type"><?= accessory_icon($type) ?><?= e($type) ?></p>
          <h1><?= e($o['name']) ?></h1>
          <?= like_button($o['slug'], 'outfits') ?>
        </div>
        <?= admin_edit_button('/admin/edit.php?kind=outfits&slug=' . rawurlencode($o['slug']), 'Edit Accessory') ?>
        <dl class="facts">
          <?= fact_latest($o) ?>
          <?php if ($models): ?>
            <dt>Optimized For</dt>
            <dd class="fit-dd"><?= model_strip($models) ?></dd>
          <?php endif ?>
          <?= fact_downloads('outfits', $o['slug']) ?>
        </dl>
        <?php if (!empty($o['description'])): ?><div class="desc md"><?= md_html((string) $o['description'], ['noImages' => $blur]) ?></div><?php endif ?>

        <?= downloads_box('outfits', $o) ?>

        <?= start_box_html('outfits') ?>

        <?= bug_item_box('outfits', $o) ?>
      </div>
    </div>

    <?= gallery_strip($o['name'], array_slice($images, 1), 1, $images, $blur) ?>

    <?php if (!empty($o['changelog'])): ?>
      <div class="details details-single"><div class="main-col">
        <?= changelog_block($o['changelog'], $o['changelogNote'] ?? null, $blur) ?>
      </div></div>
    <?php endif ?>

    <?php if ($others): ?>
      <section class="section">
        <h2 class="h2 rel-title">More Accessories for the Same Models</h2>
        <div class="ogrid"><?php foreach ($others as $x) echo outfit_card($x) ?></div>
      </section>
    <?php endif ?>
  </div>
<?php layout_end();
