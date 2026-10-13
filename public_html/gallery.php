<?php
// /gallery/: every still in the gallery's order (Admin → Gallery, new ones on top), filtered by character in the page. Rewritten here by .htaccess. Rules: app/gallery.php.
require __DIR__ . '/app/_init.php';

$all = m3d_gallery_visible();
$chars = m3d_gallery_chars($all);
$isAdmin = m3d_is_admin($M3D);

if (!$all && !$isAdmin) not_found();
$pick = isset($chars[m3d_get_text('c')]) ? m3d_get_text('c') : '';
// the Filters panel's rows (chip_row()): franchises (the most pictures first) and characters; the address can pick a character (?c=)
$perFranchise = [];
foreach ($all as $s) foreach (array_unique(array_filter(array_column(m3d_still_chars($s), 'franchise'))) as $f) $perFranchise[$f] = ($perFranchise[$f] ?? 0) + 1;
arsort($perFranchise);
$franchiseChips = ['' => 'All'];
foreach ($perFranchise as $f => $n) $franchiseChips[$f] = ['label' => (string) $f, 'count' => $n];
$charChips = ['' => 'All'];
foreach ($chars as $c) $charChips[$c['key']] = ['label' => $c['name'], 'count' => $c['count'], 'icon' => char_avatar($c, 22), 'class' => 'chip-av', 'attrs' => ' data-franchise="' . e($c['franchise']) . '"'];
layout_start('Gallery', 'New renders and stills, searchable by character.', 'page-gallery');
?>
  <div class="wrap" data-gallery>
    <header class="g-head">
      <p class="eyebrow">Gallery</p>
      <h1>Renders &amp; Stills</h1>
      <?= admin_edit_button('/admin/gallery.php', 'Edit Gallery') ?>
    </header>
    <?php if (!$all): ?>
      <p class="lead muted">No pictures yet. Add the first ones in <a href="/admin/gallery.php">Admin → Gallery</a>.</p>
    <?php else: ?>
      <div class="filters hfilters g-filters" role="search">
        <input class="input search" type="search" placeholder="Search a character, franchise or scene…" aria-label="Search the pictures" autocomplete="off" data-cat-search />
        <?= filter_toggle('gallery-filters', $pick !== '') ?>
      </div>
      <!-- like the catalogue: sort, franchise and character rows, closed by default (open when the address picks a character) -->
      <div class="filter-panel" id="gallery-filters"<?= $pick !== '' ? '' : ' hidden' ?>>
        <?= chip_row('Sort', 'sort', ['new' => 'Newest', 'old' => 'Oldest', 'liked' => 'Most Liked']) ?>
        <?= count($perFranchise) > 1 ? chip_row('Franchise', 'franchise', $franchiseChips) : '' ?>
        <?= count($chars) > 1 ? chip_row('Character', 'char', $charChips, $pick) : '' ?>
      </div>

      <?= still_wall($all) ?>
      <div class="g-empty" data-cat-empty hidden>
        <p class="muted">No pictures match. Try another name.</p>
        <button type="button" class="btn btn-ghost btn-sm" data-cat-reset>Show All Pictures</button>
      </div>
      <nav class="pager" data-pager aria-label="Pages" hidden></nav>
    <?php endif ?>
  </div>
<?php layout_end();
