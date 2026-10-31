<?php
// /scenes/  Scenes catalog (Blender scenes made with the models; kind 'scenes'), filterable by model.
require __DIR__ . '/app/_init.php';

if (!m3d_scenes_on() && !m3d_is_admin($M3D)) not_found();   // no scene listed yet: only the creator sees the (empty) page
$scenes = m3d_listed_items('scenes');
// the Filters panel's rows (chip_row()): the address can pick a model (?model=) and an order (?sort=)
$modelChips = ['' => 'All'];
$perModel = [];
foreach ($scenes as $sc) foreach (scene_models($sc) as $m) $perModel[$m['slug']] = ($perModel[$m['slug']] ?? 0) + 1;
$fits = array_filter(m3d_listed_items('models'), fn($m) => isset($perModel[$m['slug']]));
$names = array_count_values(array_map('m3d_model_name', $fits));   // the generation only tells apart models with the same name
foreach ($fits as $m) {
    $name = m3d_model_name($m);
    $modelChips[$m['slug']] = ['label' => $name . ($names[$name] > 1 && ($m['generation'] ?? '') !== '' ? ' · Gen ' . $m['generation'] : ''), 'count' => $perModel[$m['slug']],
        'icon' => char_avatar(['model' => $m, 'name' => $name], 22), 'class' => 'chip-av'];   // the model's face, like the Accessories page
}
$pre = ['model' => array_values(array_filter(explode(',', m3d_get_text('model')), 'strlen')),   // several models at once (?model=a,b): the items with every one of them
        'sort' => m3d_get_text('sort')];

layout_start('Scenes', 'Blender scenes made with the ' . site_name() . ' models.', 'page-outfits page-scenes');
?>
  <section class="wrap section outfits-head" data-scenes>
    <?php if (!m3d_scenes_on()): ?><p class="vis-banner"><?= LOCK_ICON ?><span>No scene is listed yet, so visitors don’t see this page or the menu item. <a href="/admin/scenes.php">Add a scene</a></span></p><?php endif ?>
    <div class="section-head">
      <div>
        <h1 class="h2">Scenes</h1>
        <?php if (trim(scenes_intro()) !== ''): ?><div class="lead-sm md outfits-intro"><?= md_html(scenes_intro()) ?></div><?php endif ?>
      </div>
    </div>

    <?php if (!$scenes): ?>
      <p class="muted">No scenes yet. Come back soon!</p>
    <?php else: ?>
      <div class="filters hfilters" role="search">
        <?= tag_search('Search a scene or model…', 'Search scenes', count($scenes), count($scenes) === 1 ? 'scene' : 'scenes') ?>
        <?= filter_toggle('scene-filters') ?>
      </div>
      <!-- sort, liked, unlocked and model: always closed at first (what is picked shows as tags in the search field), the Filters button opens them -->
      <div class="filter-panel" id="scene-filters" hidden>
        <?= chip_row('Sort', 'sort', ['updated' => 'Recently Updated', 'order' => 'Featured', 'name' => 'A–Z', 'likes' => 'Most Liked', 'downloads' => 'Most Downloaded'], $pre['sort']) ?>
        <?= liked_row('scenes', $scenes) ?><?= bookmark_row('scenes', $scenes) ?>
        <?= chip_row('Unlocked', 'owned', ['' => 'All', 'yes' => 'Unlocked', 'no' => 'Not Yet'], '', ' data-owned-row hidden') /* shown by site.js when download limits apply */ ?>
        <?= count($modelChips) > 1 ? chip_row('Model', 'model', $modelChips, $pre['model']) : '' ?>
      </div>
      <div class="ogrid sgrid" data-sgrid>
        <?php foreach (cards_by_updated($scenes) as $sc) echo scene_card($sc) ?>
      </div>
      <nav class="pager" data-pager aria-label="Scene pages" hidden></nav>   <!-- site.js: 25 per page -->
      <div class="empty" data-cat-empty hidden>
        <h3>No Scenes Found</h3>
        <p class="muted">Try another name, or clear the filters.</p>
        <button type="button" class="btn btn-ghost" data-cat-reset>Reset Filters</button>
      </div>
    <?php endif ?>
  </section>
<?php layout_end();
