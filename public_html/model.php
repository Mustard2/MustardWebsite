<?php
// /models/<slug>/  (rewritten here by .htaccess)
require __DIR__ . '/app/_init.php';

$slug = (string) ($_GET['slug'] ?? '');
$d = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_model($slug) : null;
if (!$d) not_found();
item_gate('models', $d);
$blur = m3d_pics_hidden('models', $d);   // blurred pictures: tiny copies only, no gallery viewer

// major versions: other models with the same name and a major version (newest first); older pages point to the newest
$versions = m3d_model_versions($d);
$vSlugs = array_column($versions, 'slug');
$newest = $versions && $versions[0]['slug'] !== $d['slug'] ? $versions[0] : null;
$otherVersions = array_values(array_filter($versions, fn($m) => $m['slug'] !== $d['slug']));
$related = array_slice(array_values(array_filter(m3d_listed_items('models'), fn($m) => $m['slug'] !== $d['slug'] && !in_array($m['slug'], $vSlugs, true) && ($m['franchise'] ?? '') === ($d['franchise'] ?? ''))), 0, 4);
$comingSoon = ($d['status'] ?? '') === 'in-development';
$gallery = array_values(array_filter(array_map('as_image', $d['gallery'] ?? []), fn($g) => $g['src'] !== ''));
$notes = array_map('as_note', $d['notes'] ?? []);
$coverSrc = ($d['cover'] ?? '') ?: (($d['thumb'] ?? '') ?: '');
$slides = $blur ? [] : array_merge($coverSrc ? [['src' => $coverSrc, 'caption' => '']] : [], $gallery);
$offset = $coverSrc ? 1 : 0;
$log = $d['changelog'] ?? [];
$outfits = m3d_outfits_for($d['slug']);
$hasAside = !empty($d['futureIdeas']) || !empty($d['knownIssues']) || !empty($d['extra']) || !empty($d['credits']);
$hasDetails = !empty($d['features']) || $log || $notes || $outfits || $hasAside;
$gen = ($d['generation'] ?? '') !== '' ? $d['generation'] : null;

// link previews show the main picture, only for fully public models (never blurred, password or draft ones)
if ($coverSrc !== '' && m3d_visibility($d) === 'public') page_image($coverSrc);
$major = !empty($d['majorVersion']) ? (int) $d['majorVersion'] : null;
$title = $d['name'] . ($versions ? " v$major" : '');
layout_start($gen !== null ? "$title (Gen $gen)" : $title, "{$d['name']} from {$d['franchise']}, a character model for Blender.", 'page-model');
?>
  <div class="wrap">
    <a class="back" href="/#catalog">← All models</a>
    <?= visibility_banner('models', $d) ?>
    <?php if ($newest): ?>
      <p class="newer-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5m0 0-6 6m6-6 6 6"/></svg><span>A newer version of this model is available: <a href="/models/<?= e($newest['slug']) ?>/"><?= e($newest['name']) ?> v<?= (int) $newest['majorVersion'] ?> →</a></span></p>
    <?php endif ?>
    <div class="model">
      <div class="media" data-slides="<?= slides_attr($slides) ?>">
        <?php if ($blur): ?>
          <?= cover($d, $coverSrc ?: null, 'hero', true, true) ?>
          <?= patron_pics_note('models', $d) ?>
        <?php elseif ($coverSrc): ?>
          <button class="cover-btn" data-slide="0" aria-label="Open gallery">
            <?= cover($d, $coverSrc, 'hero', true) ?>
            <?php if (count($slides) > 1): ?><span class="count-pill"><?= count($slides) ?> images</span><?php endif ?>
          </button>
        <?php else: ?>
          <?= cover($d, null, 'hero', true) ?>
        <?php endif ?>
        <?php if ($gallery && $blur): ?>
          <div class="gallery">
            <?php foreach ($gallery as $g): if (($b = blur_src($g['src'])) === '') continue; ?>
              <span class="thumb is-blurred"><img src="<?= e($b) ?>" alt="" /></span>
            <?php endforeach ?>
          </div>
        <?php elseif ($gallery): ?>
          <div class="gallery">
            <?php foreach ($gallery as $i => $g): ?>
              <button class="thumb" data-slide="<?= $i + $offset ?>" aria-label="Open image <?= $i + $offset + 1 ?>">
                <img src="<?= e(img_small($g['src'])) ?>" alt="<?= e($g['caption'] ? "{$d['name']}: {$g['caption']}" : "{$d['name']} render " . ($i + 1)) ?>" loading="lazy" decoding="async" />
                <?php if ($g['caption']): ?><span class="cap"><?= e($g['caption']) ?></span><?php endif ?>
              </button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>

      <div class="info">
        <p class="eyebrow"><?= e($d['franchise'] ?? '') ?></p>
        <div class="title-row">
          <h1><?= e($d['name']) ?></h1>
          <?= like_button($d['slug']) ?>
        </div>
        <?= admin_edit_button('/admin/edit.php?slug=' . rawurlencode($d['slug']), 'Edit model') ?>
        <dl class="facts">
          <?php if (!empty($d['game'])): ?><dt>Game</dt><dd><?= e($d['game']) ?></dd><?php endif ?>
          <?php if ($gen !== null): ?><dt>Generation</dt><dd><?= e($gen) ?></dd><?php endif ?>
          <?php if ($major !== null): ?><dt>Major version</dt><dd class="major-dd"><?= $major ?><?php if ($newest): ?> <a class="major-newer" href="/models/<?= e($newest['slug']) ?>/" title="A newer version of this model"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg>Newer: v<?= (int) $newest['majorVersion'] ?></a><?php elseif ($versions): ?> <span class="major-newest"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>Newest</span><?php endif ?></dd><?php endif ?>
          <?php if (!empty($d['note'])): ?><dt>Rig</dt><dd><?= e($d['note']) ?></dd><?php endif ?>
          <?php $latest = m3d_latest_version($d); if ($latest['version'] !== ''): ?><dt>Latest version</dt><dd><?= stage_badge($latest['stage']) ?> <a class="ver-link" href="#changelog"><?= e($latest['version']) ?></a><?php if ($latest['date'] !== ''): ?><span class="muted"> · <?= e($latest['date']) ?></span><?php endif ?></dd><?php endif ?>
          <?php if (!empty($d['blender'])): ?><dt>Blender</dt><dd><?= e($d['blender']) ?></dd><?php endif ?>
          <dt>Status</dt><dd><?= status_badge($d['status'] ?? '') ?></dd>
          <?php $dl = m3d_downloads('models', $d['slug']); if ($dl['total']): ?><dt>Downloads</dt><dd class="dl-count"><span><?= number_format($dl['total']) ?></span></dd><?php endif ?>
        </dl>
        <?php if (!empty($d['description'])): ?><div class="desc md"><?= md_html((string) $d['description'], ['noImages' => $blur]) ?></div><?php endif ?>

        <?= downloads_box('models', $d, $comingSoon) ?>

        <?= start_box_html('models') ?>
      </div>
    </div>

    <?php if ($hasDetails): ?>
      <div class="details">
        <div class="main-col">
          <?php if (!empty($d['features'])): ?>
            <section class="block">
              <h2 class="block-title">Features</h2>
              <ul class="features md"><?php foreach ($d['features'] as $f): ?><li><?= md_inline((string) $f) ?></li><?php endforeach ?></ul>
            </section>
          <?php endif ?>

          <?php if ($notes): ?>
            <section class="block">
              <h2 class="block-title">Notes for this model</h2>
              <ul class="plain notes">
                <?php foreach ($notes as $n): ?>
                  <li><div class="md"><?= md_html($n['text'], ['noImages' => $blur]) ?></div><?php if ($n['image'] && !$blur): ?><img class="note-img" src="<?= e($n['image']) ?>" alt="" loading="lazy" /><?php endif ?></li>
                <?php endforeach ?>
              </ul>
            </section>
          <?php endif ?>

          <?php if ($outfits): ?>
            <section class="block" id="accessories">
              <h2 class="block-title">Accessories for this model <span class="count"><?= count($outfits) ?></span></h2>
              <div class="ogrid"><?php foreach ($outfits as $o) echo outfit_card($o) ?></div>
            </section>
          <?php endif ?>

          <?= changelog_block($log, $d['changelogNote'] ?? null, $blur) ?>
        </div>

        <?php if ($hasAside): ?>
          <aside class="side-col">
            <?php if (!empty($d['futureIdeas'])): ?>
              <section class="card-block">
                <h3>Future ideas</h3>
                <ul class="plain md"><?php foreach ($d['futureIdeas'] as $x): ?><li><?= md_inline((string) $x) ?></li><?php endforeach ?></ul>
                <?php if (social_url('discord') !== ''): ?><p class="small muted">Got an idea? Share it on <a href="<?= e(social_url('discord')) ?>">Discord</a>.</p><?php endif ?>
              </section>
            <?php endif ?>
            <?php if (!empty($d['knownIssues'])): ?>
              <section class="card-block">
                <h3>Known issues</h3>
                <ul class="plain md"><?php foreach ($d['knownIssues'] as $x): ?><li><?= md_inline((string) $x) ?></li><?php endforeach ?></ul>
              </section>
            <?php endif ?>
            <?php foreach ($d['extra'] ?? [] as $x): ?>
              <section class="card-block">
                <h3><?= e($x['title'] ?? '') ?></h3>
                <?php foreach ($x['groups'] ?? [] as $g): ?>
                  <?php if (!empty($g['intro'])): ?><p class="md"><?= md_inline((string) $g['intro']) ?></p><?php endif ?>
                  <?php if (!empty($g['items'])): ?><ul class="plain md"><?php foreach ($g['items'] as $it): ?><li><?= md_inline((string) $it) ?></li><?php endforeach ?></ul><?php endif ?>
                <?php endforeach ?>
              </section>
            <?php endforeach ?>
            <?php if (!empty($d['credits'])): ?>
              <section class="card-block">
                <h3>Credits</h3>
                <ul class="plain md"><?php foreach ($d['credits'] as $x): ?><li><?= md_inline((string) $x) ?></li><?php endforeach ?></ul>
              </section>
            <?php endif ?>
          </aside>
        <?php endif ?>
      </div>
    <?php endif ?>

    <?php if ($otherVersions): ?>
      <section class="section" id="versions">
        <h2 class="h2 rel-title">Other versions</h2>
        <div class="grid"><?php foreach ($otherVersions as $m) echo model_card($m) ?></div>
      </section>
    <?php endif ?>

    <?php if ($related): ?>
      <section class="section">
        <h2 class="h2 rel-title">More from <?= e($d['franchise'] ?? '') ?></h2>
        <div class="grid"><?php foreach ($related as $m) echo model_card($m) ?></div>
      </section>
    <?php endif ?>
  </div>

<?php layout_end();
