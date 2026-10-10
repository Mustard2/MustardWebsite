<?php
// /accessories/<slug>/  (rewritten here by .htaccess): an accessory (outfit, hair, necklace… kind 'outfits' in the data)
require __DIR__ . '/app/_init.php';

$slug = (string) ($_GET['slug'] ?? '');
$o = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_outfit($slug) : null;
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
    <a class="back" href="/accessories/">← All accessories</a>
    <?= visibility_banner('outfits', $o) ?>
    <div class="model">
      <div class="media" data-slides="<?= $blur ? '[]' : slides_attr($images) ?>">
        <?php if ($blur): ?>
          <?= cover(['name' => $o['name'], 'franchise' => $type], $preview ?: null, 'hero', true, true) ?>
          <?= patron_pics_note('outfits', $o) ?>
          <?php if (count($images) > 1): ?>
            <div class="gallery">
              <?php foreach (array_slice($images, 1) as $g): if (($b = blur_src($g['src'])) === '') continue; ?>
                <span class="thumb is-blurred"><img src="<?= e($b) ?>" alt="" /></span>
              <?php endforeach ?>
            </div>
          <?php endif ?>
        <?php elseif ($preview): ?>
          <button class="cover-btn" data-slide="0" aria-label="Open images">
            <div class="cover cover-hero"><img src="<?= e($preview) ?>" alt="<?= e($o['name']) ?>" decoding="async" /></div>
            <?php if (count($images) > 1): ?><span class="count-pill"><?= count($images) ?> images</span><?php endif ?>
          </button>
        <?php else: ?>
          <?= cover(['name' => $o['name'], 'franchise' => $type], null, 'hero', true) ?>
        <?php endif ?>
        <?php if (count($images) > 1 && !$blur): ?>
          <div class="gallery">
            <?php foreach (array_slice($images, 1) as $i => $g): ?>
              <button class="thumb" data-slide="<?= $i + 1 ?>" aria-label="Open image <?= $i + 2 ?>">
                <img src="<?= e(img_small($g['src'])) ?>" alt="<?= e($g['caption'] ?: $o['name']) ?>" loading="lazy" decoding="async" />
                <?php if ($g['caption']): ?><span class="cap"><?= e($g['caption']) ?></span><?php endif ?>
              </button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>

      <div class="info">
        <p class="eyebrow eyebrow-type"><?= accessory_icon($type) ?><?= e($type) ?></p>
        <div class="title-row">
          <h1><?= e($o['name']) ?></h1>
          <?= like_button($o['slug'], 'outfits') ?>
        </div>
        <?= admin_edit_button('/admin/edit.php?kind=outfits&slug=' . rawurlencode($o['slug']), 'Edit accessory') ?>
        <dl class="facts">
          <?php $latest = m3d_latest_version($o); if ($latest['version'] !== ''): ?><dt>Latest version</dt><dd><?= stage_badge($latest['stage']) ?> <a class="ver-link" href="#changelog"><?= e($latest['version']) ?></a><?php if ($latest['date'] !== ''): ?><span class="muted"> · <?= e($latest['date']) ?></span><?php endif ?></dd><?php endif ?>
          <?php if ($models): ?>
            <dt>Optimized for</dt>
            <dd class="fit-links"><?php foreach ($models as $m): ?><a href="/models/<?= e($m['slug']) ?>/#accessories"><?= e($m['name']) ?><?= ($m['generation'] ?? '') !== '' ? ' <span class="muted">G' . e($m['generation']) . '</span>' : '' ?></a><?php endforeach ?></dd>
          <?php endif ?>
          <?php $dl = m3d_downloads('outfits', $o['slug']); if ($dl['total']): ?><dt>Downloads</dt><dd class="dl-count"><span><?= number_format($dl['total']) ?></span></dd><?php endif ?>
        </dl>
        <?php if (!empty($o['description'])): ?><div class="desc md"><?= md_html((string) $o['description'], ['noImages' => $blur]) ?></div><?php endif ?>

        <?= downloads_box('outfits', $o) ?>

        <?= start_box_html('outfits') ?>
      </div>
    </div>

    <?php if ($models || !empty($o['changelog'])): ?>
      <div class="details details-single"><div class="main-col">
        <?php if ($models): ?>
          <section class="block" id="models">
            <h2 class="block-title">Optimized for <span class="count"><?= count($models) ?></span></h2>
            <div class="ogrid"><?php foreach ($models as $m) echo model_fit_card($m) ?></div>
          </section>
        <?php endif ?>
        <?php if (!empty($o['changelog'])) echo changelog_block($o['changelog'], $o['changelogNote'] ?? null, $blur) ?>
      </div></div>
    <?php endif ?>

    <?php if ($others): ?>
      <section class="section">
        <h2 class="h2 rel-title">More accessories for the same models</h2>
        <div class="ogrid"><?php foreach ($others as $x) echo outfit_card($x) ?></div>
      </section>
    <?php endif ?>
  </div>
<?php layout_end();
