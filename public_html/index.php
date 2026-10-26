<?php
require __DIR__ . '/app/_init.php';

$models = m3d_listed_items('models');   // drafts and unlisted password models stay out of the catalog
if (!$models) {
    layout_start();
    echo '<section class="wrap gate"><h1>No Models Yet</h1><p class="lead">Add the first one from the <a href="/admin/">admin panel</a>.</p></section>';
    layout_end();
    exit;
}
// Featured models (catalog order) → homepage slideshow; falls back to the first model. Password models never go in it.
// Every listed model behind a password: no slideshow.
$heroable = array_values(array_filter($models, fn($m) => m3d_visibility($m) !== 'password'));
$featured = $heroable ? (array_values(array_filter($heroable, fn($m) => !empty($m['featured']))) ?: [$heroable[0]]) : [];

// the Filters panel's rows (chip_row()): All, then each status / franchise / generation (newest first) with its count;
// generation Other = models without one
$statuses = array_count_values(array_map('m3d_status', $models));
$statusChips = ['' => 'All'];
if ($newCount = count(array_filter($models, 'm3d_is_new'))) $statusChips['new'] = ['label' => 'New', 'count' => $newCount];   // out in the last NEW_DAYS days (data-new on the cards)
if ($updCount = count(array_filter($models, 'm3d_is_updated'))) $statusChips['updated'] = ['label' => 'Updated', 'count' => $updCount];   // a new version in the last NEW_DAYS days (data-fresh)
foreach (M3D_STATUSES as $k => [$label]) if (!empty($statuses[$k])) $statusChips[$k] = ['label' => $label, 'count' => $statuses[$k]];
$franchiseChips = ['' => 'All'];
foreach (array_count_values(array_map(fn($m) => (string) ($m['franchise'] ?? ''), $models)) as $name => $n) $franchiseChips[$name] = ['label' => (string) $name, 'count' => $n];
$generations = [];
$noGen = 0;
foreach ($models as $m) {
    $g = trim((string) ($m['generation'] ?? ''));
    if ($g !== '') $generations[$g] = ($generations[$g] ?? 0) + 1; else $noGen++;
}
uksort($generations, fn($a, $b) => (float) $b <=> (float) $a ?: strcmp((string) $a, (string) $b));
$genChips = ['' => 'All'];
foreach ($generations as $g => $n) $genChips[$g] = ['label' => 'Gen ' . $g, 'count' => $n];
if ($noGen) $genChips['~other'] = ['label' => 'Other', 'count' => $noGen];

layout_start('', site_description(), 'page-home');
?>
  <?php if ($featured): ?>
  <!-- Hero: featured models slideshow -->
  <section class="hero-slider wrap" data-hero aria-roledescription="carousel" aria-label="Featured characters">
    <?php foreach ($featured as $i => $f): $fUrl = '/models/' . $f['slug'] . '/'; $tag = $i === 0 ? 'h1' : 'h2'; ?>
      <article class="hero" data-hero-slide aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= count($featured) ?>"<?= $i > 0 ? ' hidden' : '' ?>>
        <div class="hero-copy">
          <p class="eyebrow">Featured Character</p>
          <<?= $tag ?> class="hero-title"><?= e($f['name']) ?></<?= $tag ?>>
          <p class="hero-meta">
            <span><?= e($f['franchise'] ?? '') ?></span>
            <?php if (($f['generation'] ?? '') !== ''): ?><span>Generation <?= e($f['generation']) ?></span><?php endif ?>
            <?= status_badge(m3d_status($f)) ?>
          </p>
          <?php // the first plain paragraph of the description (not headings, lists, boxes…), never a locked password model's; else the tagline
            $hl = m3d_can_view('models', $f) ? md_first_text((string) ($f['description'] ?? '')) : ''; ?>
          <p class="lead">
            <?php if ($hl !== ''): ?><?= md_inline($hl) ?>
            <?php else: ?><?= e(site_tagline()) ?>.<?php endif ?>
          </p>
          <div class="hero-cta">
            <a class="btn btn-primary" href="<?= e($fUrl) ?>">View <?= e($f['name']) ?></a>
            <a class="btn btn-ghost" href="#catalog">Browse All <?= count($models) ?> <?= count($models) === 1 ? 'Model' : 'Models' ?></a>
          </div>
        </div>
        <?php $art = ($f['cover'] ?? '') ?: (($f['thumb'] ?? '') ?: null); $hidden = m3d_pics_hidden('models', $f);
          // the glow behind the picture: soft shadows in the colours around its edges (glow_colors()), else in the placeholder's
          $gc = $art ? glow_colors($art) : [];
          if (!$gc) { $hue = array_reduce(str_split((string) ($f['franchise'] ?? '')), fn($h, $c) => ($h * 31 + ord($c)) % 360, 0); $gc = array_fill_keys(['tl', 't', 'tr', 'r', 'br', 'b', 'bl', 'l'], "hsl($hue 40% 24%)"); }
          $gStyle = implode(';', array_map(fn($k, $v) => "--g$k:$v", array_keys($gc), $gc)); ?>
        <a class="hero-art" href="<?= e($fUrl) ?>" aria-label="Open <?= e($f['name']) ?>" tabindex="-1">
          <span class="hero-glow" aria-hidden="true" style="<?= e($gStyle) ?>"></span>
          <?= cover($f, $art, 'hero', $i === 0, $hidden, '', '(max-width: 860px) 300px, 400px') ?>
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
  <?php endif ?>

  <?php $recent = recent_items(); if ($recent): ?>
  <!-- Recently added / updated (latest changelog date, last 90 days) -->
  <section class="section wrap recent-sec" aria-labelledby="recent-title">
    <div class="section-head"><h2 class="h2 cat-title" id="recent-title">Recently Updated</h2><a class="recent-all" href="/whats-new/">See All Updates →</a></div>
    <div class="recent-grid"><?php foreach ($recent as $r) echo recent_card($r) ?></div>
  </section>
  <?php endif ?>

  <?php $posts = m3d_blog_latest(); if ($posts): ?>
  <!-- The newest blog posts (only while the blog is on and has a post out) -->
  <section class="section wrap recent-sec" aria-labelledby="blog-title">
    <div class="section-head"><h2 class="h2 cat-title" id="blog-title">From the Blog</h2><a class="recent-all" href="/blog/">See All Posts →</a></div>
    <div class="recent-grid"><?php foreach ($posts as $p) echo blog_home_card($p) ?></div>
  </section>
  <?php endif ?>

  <!-- Catalog -->
  <section id="catalog" class="section wrap" data-catalog>
    <div class="section-head">
      <div>
        <h2 class="h2 cat-title">Character Catalogue</h2>
      </div>
      <p class="muted" data-cat-count aria-live="polite"><?= count($models) ?> <?= count($models) === 1 ? 'model' : 'models' ?></p>
    </div>

    <div class="filters hfilters" role="search">
      <input class="input search" type="search" placeholder="Search a character…" aria-label="Search models" autocomplete="off" data-cat-search />
      <?= filter_toggle('catalog-filters') ?>
    </div>
    <!-- status, sort, franchise and generation: closed by default, the Filters button opens them -->
    <div class="filter-panel" id="catalog-filters" hidden>
      <?= chip_row('Sort', 'sort', ['updated' => 'Recently Updated', 'order' => 'Featured', 'name' => 'A–Z', 'gen' => 'Generation', 'likes' => 'Most Liked', 'downloads' => 'Most Downloaded']) ?>
      <?= liked_row('models', $models) ?>
      <?= chip_row('Unlocked', 'owned', ['' => 'All', 'yes' => 'Unlocked', 'no' => 'Not Yet'], '', ' data-owned-row hidden') /* shown by site.js when download limits apply */ ?>
      <?= chip_row('Status', 'status', $statusChips) ?>
      <?= chip_row('Franchise', 'franchise', $franchiseChips) ?>
      <?= $generations ? chip_row('Generation', 'generation', $genChips, []) : '' ?>
    </div>

    <div class="grid" data-grid>
      <?php foreach (cards_by_updated($models) as $m) echo model_card($m) ?>
    </div>
    <nav class="pager" data-pager aria-label="Catalogue pages" hidden></nav>   <!-- site.js: 25 models per page -->

    <div class="empty" data-cat-empty hidden>
      <h3>No Models Found</h3>
      <p class="muted">Try another name, or clear the filters.</p>
      <button type="button" class="btn btn-ghost" data-cat-reset>Reset Filters</button>
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
            <?php if ($k === 'login'): ?><a class="btn btn-ghost" rel="nofollow" href="/auth/login.php?return=%2F%23catalog"><?= e(PLAN_BUTTONS[$k]) ?></a>
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
