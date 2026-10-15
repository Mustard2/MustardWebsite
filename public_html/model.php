<?php
// /models/<slug>/  (rewritten here by .htaccess)
require __DIR__ . '/app/_init.php';

$slug = m3d_get_text('slug');
$d = m3d_model($slug);
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
$slides = array_merge($coverSrc ? [['src' => $coverSrc, 'caption' => '']] : [], $gallery);   // the viewer's set (none when blurred)
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
    <a class="back" href="/#catalog" data-back>← All Models</a>
    <?= visibility_banner('models', $d) ?>
    <?php if ($newest): ?>
      <p class="newer-note"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5m0 0-6 6m6-6 6 6"/></svg><span>A newer version of this model is available: <a href="/models/<?= e($newest['slug']) ?>/"><?= e($newest['name']) ?> v<?= (int) $newest['majorVersion'] ?> →</a></span></p>
    <?php endif ?>
    <div class="model">
      <?= item_media('models', $d, $d, $coverSrc, $slides, $blur) ?>

      <div class="info">
        <div class="title-row">
          <p class="eyebrow"><?= e($d['franchise'] ?? '') ?></p>
          <h1><?= e($d['name']) ?></h1>
          <?= like_button($d['slug']) ?>
        </div>
        <?= admin_edit_button('/admin/edit.php?slug=' . rawurlencode($d['slug']), 'Edit Model') ?>
        <dl class="facts">
          <?php if (!empty($d['game'])): ?><dt>Game</dt><dd><?= e($d['game']) ?></dd><?php endif ?>
          <?php if ($gen !== null): ?><dt>Generation</dt><dd><?= e($gen) ?></dd><?php endif ?>
          <?php if ($major !== null): ?><dt>Major Version<?= help_tip("The same character, remade from scratch as a completely new model. It's not an update of the previous version: each one has its own page, downloads and changelog.") ?></dt><dd class="major-dd"><?= $major ?><?php if ($newest): ?> <a class="major-newer" href="/models/<?= e($newest['slug']) ?>/" title="A newer version of this model"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg>Newer: v<?= (int) $newest['majorVersion'] ?></a><?php elseif ($versions): ?> <span class="major-newest"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>Newest</span><?php endif ?></dd><?php endif ?>
          <?php if (!empty($d['note'])): ?><dt>Rig</dt><dd><?= e($d['note']) ?></dd><?php endif ?>
          <?= fact_latest($d) ?>
          <?php if (!empty($d['blender'])): ?><dt>Blender</dt><dd><?= e($d['blender']) ?></dd><?php endif ?>
          <dt>Status</dt><dd><?= status_badge(m3d_status($d)) ?></dd>
          <?= fact_downloads('models', $d['slug']) ?>
        </dl>
        <?php if (!empty($d['description'])): ?><div class="desc md"><?= md_html((string) $d['description'], ['noImages' => $blur]) ?></div><?php endif ?>

        <?= downloads_box('models', $d, $comingSoon) ?>

        <?= start_box_html('models') ?>

        <?= bug_item_box('models', $d) ?>
      </div>
    </div>

    <?= gallery_strip($d['name'], $gallery, $coverSrc ? 1 : 0, $slides, $blur) ?>

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
              <h2 class="block-title">Notes for This Model</h2>
              <ul class="plain notes">
                <?php foreach ($notes as $n): ?>
                  <li><div class="md"><?= md_html($n['text'], ['noImages' => $blur]) ?></div><?php if ($n['image'] && !$blur): ?><img class="note-img" src="<?= e($n['image']) ?>" alt="" loading="lazy" /><?php endif ?></li>
                <?php endforeach ?>
              </ul>
            </section>
          <?php endif ?>

          <?php if ($outfits): ?>
            <section class="block" id="accessories">
              <h2 class="block-title">Accessories for This Model <span class="count"><?= count($outfits) ?></span></h2>
              <div class="ogrid"><?php foreach ($outfits as $o) echo outfit_card($o) ?></div>
            </section>
          <?php endif ?>

          <?= changelog_block($log, $d['changelogNote'] ?? null, $blur, m3d_bug_fixes_for('models', $d, $log)) ?>
        </div>

        <?php if ($hasAside): ?>
          <aside class="side-col">
            <?php if (!empty($d['futureIdeas'])): ?>
              <section class="card-block">
                <h3>Future Ideas</h3>
                <ul class="plain md"><?php foreach ($d['futureIdeas'] as $x): ?><li><?= md_inline((string) $x) ?></li><?php endforeach ?></ul>
                <?php if (social_url('discord') !== ''): ?><p class="small muted">Got an idea? Share it on <a href="<?= e(social_url('discord')) ?>">Discord</a>.</p><?php endif ?>
              </section>
            <?php endif ?>
            <?php if (!empty($d['knownIssues'])): ?>
              <section class="card-block">
                <h3>Known Issues</h3>
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
        <h2 class="h2 rel-title">Other Versions</h2>
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
