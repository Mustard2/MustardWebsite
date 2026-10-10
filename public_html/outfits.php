<?php
// /accessories/  Accessories catalog (outfits, hair styles, necklaces…; kind 'outfits' in the data), filterable by type and model.
require __DIR__ . '/app/_init.php';

$outfits = m3d_listed_items('outfits');
$forModels = [];
foreach ($outfits as $o) foreach (array_unique($o['models'] ?? []) as $s) $forModels[$s] = ($forModels[$s] ?? 0) + 1;   // outfits per model
$modelOptions = array_values(array_filter(m3d_listed_items('models'), fn($m) => isset($forModels[$m['slug']])));
$pre = (string) ($_GET['model'] ?? '');
$preOk = $pre !== '' && in_array($pre, array_column($modelOptions, 'slug'), true);
$types = [];   // type => how many; the Type row only when there are at least two
foreach ($outfits as $o) { $t = m3d_accessory_type($o); $types[$t] = ($types[$t] ?? 0) + 1; }
uksort($types, fn($a, $b) => ($b === 'Outfit') <=> ($a === 'Outfit') ?: strnatcasecmp($a, $b));   // Outfit first
$preType = mb_strtolower((string) ($_GET['type'] ?? ''));
$preTypeOk = $preType !== '' && count($types) > 1 && in_array($preType, array_map('mb_strtolower', array_keys($types)), true);

layout_start('Accessories', 'Outfits, hair styles and other accessories made for the ' . site_name() . ' models.', 'page-outfits');
?>
  <section class="wrap section outfits-head" data-outfits>
    <div class="section-head">
      <div>
        <h1 class="h2">Accessories</h1>
        <?php if (trim(outfits_intro()) !== ''): ?><div class="lead-sm md outfits-intro"><?= md_html(outfits_intro()) ?></div><?php endif ?>
      </div>
      <p class="muted" data-ocount aria-live="polite"><?= count($outfits) ?> <?= count($outfits) === 1 ? 'accessory' : 'accessories' ?></p>
    </div>

    <?php if (!$outfits): ?>
      <p class="muted">No accessories yet. Come back soon!</p>
    <?php else: ?>
      <div class="filters hfilters" role="search">
        <input class="input search" type="search" placeholder="Search an accessory…" aria-label="Search accessories" data-osearch />
        <button type="button" class="btn btn-ghost filter-toggle" aria-expanded="<?= $preOk || $preTypeOk ? 'true' : 'false' ?>" aria-controls="outfit-filters" aria-label="Filters" title="Filters" data-filter-toggle><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round"/></svg><span class="filter-count" data-filter-count hidden></span></button>
      </div>
      <!-- sort, type, unlocked and model: closed by default (open when the address asks for a type or a model), the Filters button opens them -->
      <div class="filter-panel" id="outfit-filters" data-filter-panel<?= $preOk || $preTypeOk ? '' : ' hidden' ?>>
        <div class="chips chips-gen" role="group" aria-label="Sort">
          <span class="chips-lbl">Sort</span>
          <?php foreach (['order' => 'Featured', 'name' => 'A–Z', 'likes' => 'Most liked', 'downloads' => 'Most downloaded'] as $k => $label): ?>
            <button class="chip" aria-pressed="<?= $k === 'order' ? 'true' : 'false' ?>" data-pick="sort" data-value="<?= $k ?>"<?= $k === 'order' ? ' data-default' : '' ?>><?= e($label) ?></button>
          <?php endforeach ?>
        </div>
        <?php if (count($types) > 1): ?>
          <div class="chips chips-gen" role="group" aria-label="Type">
            <span class="chips-lbl">Type</span>
            <button class="chip" aria-pressed="<?= $preTypeOk ? 'false' : 'true' ?>" data-pick="type" data-value="" data-default>All</button>
            <?php foreach ($types as $t => $n): $tv = mb_strtolower($t); ?>
              <button class="chip" aria-pressed="<?= $preTypeOk && $preType === $tv ? 'true' : 'false' ?>" data-pick="type" data-value="<?= e($tv) ?>"><?= accessory_icon($t) ?><?= e($t) ?> <span><?= $n ?></span></button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
        <div class="chips chips-gen" role="group" aria-label="Unlocked" data-owned-row hidden>   <!-- shown by site.js when download limits apply -->
          <span class="chips-lbl">Unlocked</span>
          <button class="chip" aria-pressed="true" data-pick="owned" data-value="" data-default>All</button>
          <button class="chip" aria-pressed="false" data-pick="owned" data-value="yes">Unlocked</button>
          <button class="chip" aria-pressed="false" data-pick="owned" data-value="no">Not yet</button>
        </div>
        <?php if ($modelOptions): ?>
          <div class="chips chips-gen" role="group" aria-label="Model">
            <span class="chips-lbl">Model</span>
            <button class="chip" aria-pressed="<?= $preOk ? 'false' : 'true' ?>" data-pick="model" data-value="" data-default>All</button>
            <?php foreach ($modelOptions as $m): ?>
              <button class="chip" aria-pressed="<?= $pre === $m['slug'] ? 'true' : 'false' ?>" data-pick="model" data-value="<?= e($m['slug']) ?>"><?= e($m['name']) ?><?= ($m['generation'] ?? '') !== '' ? ' G' . e($m['generation']) : '' ?> <span><?= $forModels[$m['slug']] ?></span></button>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
      <div class="ogrid ogrid-lg" data-ogrid>
        <?php foreach ($outfits as $o) echo outfit_card($o) ?>
      </div>
      <nav class="pager" data-opager aria-label="Accessory pages" hidden></nav>   <!-- site.js: 25 per page -->
      <div class="empty" data-oempty hidden>
        <h3>No accessories found</h3>
        <p class="muted">Try another name, or clear the filters.</p>
        <button class="btn btn-ghost" data-oreset>Reset filters</button>
      </div>
    <?php endif ?>
  </section>
<?php layout_end();
