<?php
// /gallery/: every still, in a new random order on each visit (Sort: Random) or the gallery's order (Admin → Gallery, new ones on top), filtered by
// character in the page; site.js shows them 40 at a time as the visitor scrolls. Rewritten here by .htaccess. Rules: app/gallery.php.
require __DIR__ . '/app/_init.php';

$all = m3d_gallery_visible();
$chars = m3d_gallery_chars($all);
$isAdmin = m3d_is_admin($M3D);

if (!$all && !$isAdmin) not_found();
$pick = isset($chars[m3d_get_text('c')]) ? m3d_get_text('c') : '';
$sort = in_array(m3d_get_text('sort'), GALLERY_SORTS, true) ? m3d_get_text('sort') : 'random';
// the Filters panel's rows (chip_row()): franchises (the most pictures first) and characters; the address can pick a character (?c=)
$perFranchise = [];
foreach ($all as $s) foreach (array_unique(array_filter(array_column(m3d_still_chars($s), 'franchise'))) as $f) $perFranchise[$f] = ($perFranchise[$f] ?? 0) + 1;
arsort($perFranchise);
$franchiseChips = ['' => 'All'];
foreach ($perFranchise as $f => $n) $franchiseChips[$f] = ['label' => (string) $f, 'count' => $n];
$charChips = ['' => 'All'];
foreach ($chars as $c) $charChips[$c['key']] = ['label' => $c['name'], 'count' => $c['count'], 'icon' => char_avatar($c, 22), 'class' => 'chip-av', 'attrs' => ' data-franchise="' . e($c['franchise']) . '"'];
// ?p=<id>: one picture's link (the viewer's Share button, the address while it is open): site.js opens it in the viewer; a picture anyone may
// see in full also names the page and is its link preview (og:image), so a pasted link shows the render
$title = 'Gallery';
$desc = 'New renders and stills, searchable by character.';
$one = m3d_get_text('p');
foreach ($one !== '' ? $all : [] as $s) {
    if ((string) $s['id'] !== $one || ($s['view'] ?? 'clear') !== 'clear') continue;
    if (m3d_still_public_view($s) === 'clear') {
        $names = implode(', ', array_column(m3d_still_chars($s), 'name'));
        $cap = trim((string) ($s['caption'] ?? ''));
        $title = ($cap !== '' ? $cap : ($names !== '' ? $names : 'A Render')) . ' · Gallery';
        if ($names !== '') $desc = ($cap !== '' ? $cap . '. ' : '') . 'A render of ' . $names . '.';
        page_image((string) $s['src']);
    }
    break;
}
layout_start($title, $desc, 'page-gallery');
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
        <?= chip_row('Sort', 'sort', ['random' => 'Random', 'new' => 'Newest', 'old' => 'Oldest', 'liked' => 'Most Liked'], $sort) ?>
        <?= count($perFranchise) > 1 ? chip_row('Franchise', 'franchise', $franchiseChips) : '' ?>
        <?= count($chars) > 1 ? chip_row('Character', 'char', $charChips, $pick) : '' ?>
      </div>

      <?= still_wall(m3d_gallery_sorted($all, $sort), $pick === '' && m3d_get_text('franchise') === '' && m3d_get_text('q') === '') ?>
      <div class="g-empty" data-cat-empty hidden>
        <p class="muted">No pictures match. Try another name.</p>
        <button type="button" class="btn btn-ghost btn-sm" data-cat-reset>Show All Pictures</button>
      </div>
      <div class="g-more" data-more hidden></div>   <!-- site.js: the next 40 pictures once this comes near the screen -->
      <button type="button" class="to-top" data-to-top hidden aria-label="Back to the top" title="Back to the top"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 19V5m0 0-6 6m6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>
    <?php endif ?>
  </div>
<?php layout_end();
