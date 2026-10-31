<?php
// /accessories/  Accessories catalog (outfits, hair styles, necklaces…; kind 'outfits' in the data), filterable by type and model.
require __DIR__ . '/app/_init.php';

$outfits = m3d_listed_items('outfits');
// the Filters panel's rows (chip_row()); the address can pick a model (?model=), a type (?type=) and an order (?sort=)
$modelChips = ['' => 'All'];
$forModels = [];
$forAll = 0;   // accessories made for every model: counted under every model's chip; the models only they fit get a chip the search can find (data-tag-only)
foreach ($outfits as $o) if (m3d_fits_all($o)) $forAll++; else foreach (array_unique($o['models'] ?? []) as $s) $forModels[$s] = ($forModels[$s] ?? 0) + 1;   // outfits per model
$fits = array_filter(m3d_listed_items('models'), fn($m) => $forAll || isset($forModels[$m['slug']]));
$names = array_count_values(array_map('m3d_model_name', $fits));   // the generation only tells apart models with the same name
foreach ($fits as $m) {
    $name = m3d_model_name($m);
    $own = isset($forModels[$m['slug']]);
    // a model only "All Models" accessories fit: kept out of the Filters panel until picked (the row would list the whole catalogue), but typing its name
    // in the search field offers it as a tag (tagSearch() in site.js) and ?model= picks it
    $modelChips[$m['slug']] = ['label' => $name . ($names[$name] > 1 && ($m['generation'] ?? '') !== '' ? ' · Gen ' . $m['generation'] : ''), 'count' => ($forModels[$m['slug']] ?? 0) + $forAll,
        'icon' => char_avatar(['model' => $m, 'name' => $name], 22), 'class' => 'chip-av', 'attrs' => $own ? '' : ' data-tag-only'];   // the model's face, like the Gallery's Character row (and its tag in the search field)
}
$types = [];   // lowercased type => [name as first written, how many] ("Hair" and "hair" are one type, like the cards' data-type); the Type row only with two or more
foreach ($outfits as $o) { $t = m3d_accessory_type($o); $k = mb_strtolower($t); $types[$k] = [$types[$k][0] ?? $t, ($types[$k][1] ?? 0) + 1]; }
uksort($types, fn($a, $b) => ($b === 'outfit') <=> ($a === 'outfit') ?: strnatcasecmp((string) $a, (string) $b));   // Outfit first
$typeChips = ['' => 'All'];
foreach ($types as $k => [$t, $n]) $typeChips[(string) $k] = ['label' => (string) $t, 'count' => $n, 'icon' => accessory_icon((string) $t)];
$pre = ['model' => array_values(array_filter(explode(',', m3d_get_text('model')), 'strlen')),   // several models at once (?model=a,b): the items with every one of them
        'type' => count($types) > 1 ? mb_strtolower(m3d_get_text('type')) : '', 'sort' => m3d_get_text('sort')];

layout_start('Accessories', 'Outfits, hair styles and other accessories made for the ' . site_name() . ' models.', 'page-outfits');
?>
  <section class="wrap section outfits-head" data-outfits>
    <div class="section-head">
      <div>
        <h1 class="h2">Accessories</h1>
        <?php if (trim(outfits_intro()) !== ''): ?><div class="lead-sm md outfits-intro"><?= md_html(outfits_intro()) ?></div><?php endif ?>
      </div>
    </div>

    <?php if (!$outfits): ?>
      <p class="muted">No accessories yet. Come back soon!</p>
    <?php else: ?>
      <div class="filters hfilters" role="search">
        <?= tag_search('Search an accessory, type or model…', 'Search accessories', count($outfits), count($outfits) === 1 ? 'accessory' : 'accessories') ?>
        <?= filter_toggle('outfit-filters') ?>
      </div>
      <!-- sort, type, unlocked and model: always closed at first (what is picked shows as tags in the search field), the Filters button opens them -->
      <div class="filter-panel" id="outfit-filters" hidden>
        <?= chip_row('Sort', 'sort', ['updated' => 'Recently Updated', 'order' => 'Featured', 'name' => 'A–Z', 'likes' => 'Most Liked', 'downloads' => 'Most Downloaded'], $pre['sort']) ?>
        <?= count($types) > 1 ? chip_row('Type', 'type', $typeChips, $pre['type']) : '' ?>
        <?= liked_row('outfits', $outfits) ?><?= bookmark_row('outfits', $outfits) ?>
        <?= chip_row('Unlocked', 'owned', ['' => 'All', 'yes' => 'Unlocked', 'no' => 'Not Yet'], '', ' data-owned-row hidden') /* shown by site.js when download limits apply */ ?>
        <?= count($modelChips) > 1 ? chip_row('Model', 'model', $modelChips, $pre['model'], $forModels ? '' : ' data-tags-only') : '' ?>
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
