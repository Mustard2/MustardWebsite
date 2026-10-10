<?php
require __DIR__ . '/app/_init.php';

$models = m3d_listed_items('models');   // drafts and unlisted password models stay out of the catalog
if (!$models) {
    layout_start();
    echo '<section class="wrap gate"><h1>No models yet</h1><p class="lead">Add the first one from the <a href="/admin/">admin panel</a>.</p></section>';
    layout_end();
    exit;
}
// Featured models (catalog order) → homepage slideshow; falls back to the first model. Password models never go in it.
$heroable = array_values(array_filter($models, fn($m) => m3d_visibility($m) !== 'password')) ?: $models;
$featured = array_values(array_filter($heroable, fn($m) => !empty($m['featured']))) ?: [$heroable[0]];

$statuses = array_count_values(array_map(fn($m) => (string) ($m['status'] ?? ''), $models));
$franchises = [];
foreach ($models as $m) $franchises[$m['franchise'] ?? ''] = ($franchises[$m['franchise'] ?? ''] ?? 0) + 1;
// generation buttons, like the franchises: All, then each generation (newest first), then Other (models without one)
$generations = [];
$noGen = 0;
foreach ($models as $m) {
    $g = trim((string) ($m['generation'] ?? ''));
    if ($g !== '') $generations[$g] = ($generations[$g] ?? 0) + 1; else $noGen++;
}
uksort($generations, fn($a, $b) => (float) $b <=> (float) $a ?: strcmp((string) $a, (string) $b));

layout_start('', site_tagline() . '.', 'page-home');
?>
  <!-- Hero: featured models slideshow -->
  <section class="hero-slider wrap" data-hero aria-roledescription="carousel" aria-label="Featured characters">
    <?php foreach ($featured as $i => $f): $fUrl = '/models/' . $f['slug'] . '/'; $tag = $i === 0 ? 'h1' : 'h2'; ?>
      <article class="hero" data-hero-slide aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= count($featured) ?>"<?= $i > 0 ? ' hidden' : '' ?>>
        <div class="hero-copy">
          <p class="eyebrow">Featured character</p>
          <<?= $tag ?> class="hero-title"><?= e($f['name']) ?></<?= $tag ?>>
          <p class="hero-meta">
            <span><?= e($f['franchise'] ?? '') ?></span>
            <?php if (($f['generation'] ?? '') !== ''): ?><span>Generation <?= e($f['generation']) ?></span><?php endif ?>
            <?= status_badge($f['status'] ?? '') ?>
          </p>
          <p class="lead">
            <?php if (!empty($f['description'])): ?><?= md_inline((string) $f['description']) ?>
            <?php else: ?><?= e(site_tagline()) ?>.<?php endif ?>
          </p>
          <div class="hero-cta">
            <a class="btn btn-primary" href="<?= e($fUrl) ?>">View <?= e($f['name']) ?></a>
            <a class="btn btn-ghost" href="#catalog">Browse all <?= count($models) ?> models</a>
          </div>
        </div>
        <?php $art = ($f['cover'] ?? '') ?: (($f['thumb'] ?? '') ?: null); $hidden = m3d_pics_hidden('models', $f);
          // the glow behind the picture: soft shadows in the colours around its edges (glow_colors()), else in the placeholder's
          $gc = $art ? glow_colors($art) : [];
          if (!$gc) { $hue = array_reduce(str_split((string) ($f['franchise'] ?? '')), fn($h, $c) => ($h * 31 + ord($c)) % 360, 0); $gc = array_fill_keys(['tl', 't', 'tr', 'r', 'br', 'b', 'bl', 'l'], "hsl($hue 40% 24%)"); }
          $gStyle = implode(';', array_map(fn($k, $v) => "--g$k:$v", array_keys($gc), $gc)); ?>
        <a class="hero-art" href="<?= e($fUrl) ?>" aria-label="Open <?= e($f['name']) ?>" tabindex="-1">
          <span class="hero-glow" aria-hidden="true" style="<?= e($gStyle) ?>"></span>
          <?= cover($f, $art, 'hero', $i === 0, $hidden) ?>
        </a>
      </article>
    <?php endforeach ?>
    <?php if (count($featured) > 1): ?>
      <div class="hero-nav">
        <button class="hero-arrow" data-hero-prev aria-label="Previous featured character">‹</button>
        <div class="hero-dots" role="tablist">
          <?php foreach ($featured as $i => $f): ?>
            <button class="hero-dot" data-hero-dot="<?= $i ?>" aria-label="<?= e($f['name']) ?>"<?= $i === 0 ? ' aria-current="true"' : '' ?>></button>
          <?php endforeach ?>
        </div>
        <button class="hero-arrow" data-hero-next aria-label="Next featured character">›</button>
      </div>
    <?php endif ?>
  </section>

  <?php $recent = recent_items(); if ($recent): ?>
  <!-- Recently added / updated (latest changelog date, last 90 days) -->
  <section class="section wrap recent-sec" aria-labelledby="recent-title">
    <div class="section-head"><h2 class="h2 cat-title" id="recent-title">Recently Updated</h2></div>
    <div class="recent-grid"><?php foreach ($recent as $r) echo recent_card($r) ?></div>
  </section>
  <?php endif ?>

  <!-- Catalog -->
  <section id="catalog" class="section wrap" data-catalog>
    <div class="section-head">
      <div>
        <h2 class="h2 cat-title">Character Catalogue</h2>
      </div>
      <p class="muted" data-count aria-live="polite"><?= count($models) ?> models</p>
    </div>

    <div class="filters hfilters" role="search">
      <input class="input search" type="search" placeholder="Search a character…" aria-label="Search models" data-search />
      <button type="button" class="btn btn-ghost filter-toggle" aria-expanded="false" aria-controls="catalog-filters" aria-label="Filters" title="Filters" data-filter-toggle><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round"/></svg><span class="filter-count" data-filter-count hidden></span></button>
    </div>
    <!-- status, sort, franchise and generation: closed by default, the Filters button opens them -->
    <div class="filter-panel" id="catalog-filters" data-filter-panel hidden>
      <div class="chips chips-gen" role="group" aria-label="Sort">
        <span class="chips-lbl">Sort</span>
        <?php foreach (['order' => 'Featured', 'name' => 'A–Z', 'gen' => 'Generation', 'likes' => 'Most liked', 'downloads' => 'Most downloaded'] as $k => $label): ?>
          <button class="chip" aria-pressed="<?= $k === 'order' ? 'true' : 'false' ?>" data-pick="sort" data-value="<?= $k ?>"<?= $k === 'order' ? ' data-default' : '' ?>><?= e($label) ?></button>
        <?php endforeach ?>
      </div>
      <div class="chips chips-gen" role="group" aria-label="Unlocked" data-owned-row hidden>   <!-- shown by site.js when download limits apply -->
        <span class="chips-lbl">Unlocked</span>
        <button class="chip" aria-pressed="true" data-pick="owned" data-value="" data-default>All</button>
        <button class="chip" aria-pressed="false" data-pick="owned" data-value="yes">Unlocked</button>
        <button class="chip" aria-pressed="false" data-pick="owned" data-value="no">Not yet</button>
      </div>
      <div class="chips chips-gen" role="group" aria-label="Status">
        <span class="chips-lbl">Status</span>
        <button class="chip" aria-pressed="true" data-pick="status" data-value="" data-default>All</button>
        <?php foreach (M3D_STATUSES as $k => [$label]): if (empty($statuses[$k])) continue; ?>
          <button class="chip" aria-pressed="false" data-pick="status" data-value="<?= e($k) ?>"><?= e($label) ?> <span><?= $statuses[$k] ?></span></button>
        <?php endforeach ?>
      </div>
      <div class="chips chips-gen" role="group" aria-label="Franchise">
        <span class="chips-lbl">Franchise</span>
        <button class="chip" aria-pressed="true" data-pick="franchise" data-value="" data-default>All</button>
        <?php foreach ($franchises as $name => $count): ?>
          <button class="chip" aria-pressed="false" data-pick="franchise" data-value="<?= e($name) ?>"><?= e($name) ?> <span><?= $count ?></span></button>
        <?php endforeach ?>
      </div>
      <?php if ($generations): ?>
        <div class="chips chips-gen" role="group" aria-label="Generation">
          <span class="chips-lbl">Generation</span>
          <button class="chip" aria-pressed="true" data-pick="generation" data-value="" data-default>All</button>
          <?php foreach ($generations as $g => $count): $g = (string) $g; ?>
            <button class="chip" aria-pressed="false" data-pick="generation" data-value="<?= e($g) ?>">Gen <?= e($g) ?> <span><?= $count ?></span></button>
          <?php endforeach ?>
          <?php if ($noGen): ?><button class="chip" aria-pressed="false" data-pick="generation" data-value="~other">Other <span><?= $noGen ?></span></button><?php endif ?>
        </div>
      <?php endif ?>
    </div>

    <div class="grid" data-grid>
      <?php foreach ($models as $m) echo model_card($m) ?>
    </div>
    <nav class="pager" data-pager aria-label="Catalogue pages" hidden></nav>   <!-- site.js: 25 models per page -->

    <div class="empty" data-empty hidden>
      <h3>No models found</h3>
      <p class="muted">Try another name, or clear the filters.</p>
      <button class="btn btn-ghost" data-reset>Reset filters</button>
    </div>
  </section>

<?php $patron = m3d_patron($M3D); $plans = home_plans();
  if ($plans['on'] && (!$patron || empty($patron['access']))):   // patrons who can download don't need it; texts: Admin → Settings → Homepage section ?>
  <!-- How to get the models -->
  <section id="access" class="section wrap">
    <?php if ($plans['eyebrow'] !== '' || $plans['title'] !== ''): ?>
    <div class="section-head">
      <div>
        <?php if ($plans['eyebrow'] !== ''): ?><p class="eyebrow"><?= e($plans['eyebrow']) ?></p><?php endif ?>
        <?php if ($plans['title'] !== ''): ?><h2 class="h2"><?= e($plans['title']) ?></h2><?php endif ?>
      </div>
    </div>
    <?php endif ?>
    <div class="plans">
      <?php foreach ($plans['boxes'] as $i => $b): if ($b['title'] === '' && $b['text'] === '' && !$b['items']) continue;
        $btns = array_filter($b['buttons'], fn($k) => $k === 'login' || social_url($k) !== ''); ?>
      <article class="plan<?= $i === 1 ? ' plan-hot' : '' ?>">
        <?php if ($b['tag'] !== ''): ?><p class="plan-tag"><?= e($b['tag']) ?></p><?php endif ?>
        <?php if ($b['title'] !== ''): ?><h3><?= e($b['title']) ?></h3><?php endif ?>
        <?php if ($b['text'] !== ''): ?><p class="muted"><?= md_inline($b['text']) ?></p><?php endif ?>
        <?php if ($b['items']): ?><ul><?php foreach ($b['items'] as $it): ?><li><?= md_inline($it) ?></li><?php endforeach ?></ul><?php endif ?>
        <?php if ($btns): ?>
        <div class="plan-cta">
          <?php foreach ($btns as $k): ?>
            <?php if ($k === 'login'): ?><a class="btn btn-ghost" href="/auth/login.php?return=%2F%23catalog"><?= e(PLAN_BUTTONS[$k]) ?></a>
            <?php else: ?><a class="btn <?= $k === 'patreon' ? 'btn-patreon' : 'btn-ghost' ?>" href="<?= e(social_url($k)) ?>"><?= e(PLAN_BUTTONS[$k]) ?></a><?php endif ?>
          <?php endforeach ?>
        </div>
        <?php endif ?>
        <?php if ($b['fine'] !== ''): ?><p class="fine"><?= md_inline($b['fine']) ?></p><?php endif ?>
      </article>
      <?php endforeach ?>
    </div>
  </section>
<?php endif ?>

<?php layout_end();
