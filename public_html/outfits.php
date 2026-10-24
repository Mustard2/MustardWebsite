<?php
// /accessories/  Accessories catalog (outfits, hair styles, necklaces…; kind 'outfits' in the data), filterable by type and model.
require __DIR__ . '/app/_init.php';

$outfits = m3d_listed_items('outfits');
// the Filters panel's rows (chip_row()); the address can pick a model (?model=), a type (?type=) and an order (?sort=)
$modelChips = ['' => 'All'];
$forModels = [];
$forAll = 0;   // accessories made for every model: counted under every model's chip, but they give no model a chip of its own
foreach ($outfits as $o) if (m3d_fits_all($o)) $forAll++; else foreach (array_unique($o['models'] ?? []) as $s) $forModels[$s] = ($forModels[$s] ?? 0) + 1;   // outfits per model
foreach (m3d_listed_items('models') as $m) if (isset($forModels[$m['slug']]))
    $modelChips[$m['slug']] = ['label' => m3d_model_name($m) . (($m['generation'] ?? '') !== '' ? ' G' . $m['generation'] : ''), 'count' => $forModels[$m['slug']] + $forAll];
$types = [];   // lowercased type => [name as first written, how many] ("Hair" and "hair" are one type, like the cards' data-type); the Type row only with two or more
foreach ($outfits as $o) { $t = m3d_accessory_type($o); $k = mb_strtolower($t); $types[$k] = [$types[$k][0] ?? $t, ($types[$k][1] ?? 0) + 1]; }
uksort($types, fn($a, $b) => ($b === 'outfit') <=> ($a === 'outfit') ?: strnatcasecmp((string) $a, (string) $b));   // Outfit first
$typeChips = ['' => 'All'];
foreach ($types as $k => [$t, $n]) $typeChips[(string) $k] = ['label' => (string) $t, 'count' => $n, 'icon' => accessory_icon((string) $t)];
$pre = ['model' => m3d_get_text('model'), 'type' => count($types) > 1 ? mb_strtolower(m3d_get_text('type')) : '', 'sort' => m3d_get_text('sort')];
$open = ($pre['model'] !== '' && isset($modelChips[$pre['model']])) || ($pre['type'] !== '' && isset($typeChips[$pre['type']]));

layout_start('Accessories', 'Outfits, hair styles and other accessories made for the ' . site_name() . ' models.', 'page-outfits');
?>
  <section class="wrap section outfits-head" data-outfits>
    <div class="section-head">
      <div>
        <h1 class="h2">Accessories</h1>
        <?php if (trim(outfits_intro()) !== ''): ?><div class="lead-sm md outfits-intro"><?= md_html(outfits_intro()) ?></div><?php endif ?>
      </div>
      <p class="muted" data-cat-count aria-live="polite"><?= count($outfits) ?> <?= count($outfits) === 1 ? 'accessory' : 'accessories' ?></p>
    </div>

    <?php if (!$outfits): ?>
      <p class="muted">No accessories yet. Come back soon!</p>
    <?php else: ?>
      <div class="filters hfilters" role="search">
        <input class="input search" type="search" placeholder="Search an accessory…" aria-label="Search accessories" autocomplete="off" data-cat-search />
        <?= filter_toggle('outfit-filters', $open) ?>
      </div>
      <!-- sort, type, unlocked and model: closed by default (open when the address asks for a type or a model), the Filters button opens them -->
      <div class="filter-panel" id="outfit-filters"<?= $open ? '' : ' hidden' ?>>
        <?= chip_row('Sort', 'sort', ['updated' => 'Recently Updated', 'order' => 'Featured', 'name' => 'A–Z', 'likes' => 'Most Liked', 'downloads' => 'Most Downloaded'], $pre['sort']) ?>
        <?= count($types) > 1 ? chip_row('Type', 'type', $typeChips, $pre['type']) : '' ?>
        <?= liked_row('outfits', $outfits) ?>
        <?= chip_row('Unlocked', 'owned', ['' => 'All', 'yes' => 'Unlocked', 'no' => 'Not Yet'], '', ' data-owned-row hidden') /* shown by site.js when download limits apply */ ?>
        <?= count($modelChips) > 1 ? chip_row('Model', 'model', $modelChips, $pre['model']) : '' ?>
      </div>
      <div class="ogrid ogrid-lg" data-ogrid>
        <?php foreach (cards_by_updated($outfits) as $o) echo outfit_card($o) ?>
      </div>
      <nav class="pager" data-pager aria-label="Accessory pages" hidden></nav>   <!-- site.js: 25 per page -->
      <div class="empty" data-cat-empty hidden>
        <h3>No Accessories Found</h3>
        <p class="muted">Try another name, or clear the filters.</p>
        <button type="button" class="btn btn-ghost" data-cat-reset>Reset Filters</button>
      </div>
    <?php endif ?>
  </section>
<?php layout_end();
