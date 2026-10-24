<?php
// /search/?q=…  (rewritten here by .htaccess): one search over models, accessories, blog posts, docs pages and FAQ questions.
//   ?index=1  the list the header search box (site.js, [data-hsearch]) searches as you type: the same for every visitor
//             (cacheable), so only what any visitor may see: listed items (no drafts, no unlisted password items), docs pages
//             that aren't drafts, FAQ questions, blog posts that are out (Patrons Only ones too: their title and summary are public);
//             pictures only for fully public items and Public posts (like link previews).
//   ?q=       the full results page (also what the box opens on Enter, and the search without JavaScript).
// Matching (same in site.js): lower case, accents ignored, every word must be found in the title or the extra words; a search
// that finds nothing shows the results of its corrected spelling instead (search_fix() in app/search.php).
require __DIR__ . '/app/_init.php';
require_once __DIR__ . '/app/search.php';

if (isset($_GET['index'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    header('X-Robots-Tag: noindex');
    $list = m3d_maintenance_blocks() ? [] : array_map(fn($en) => array_diff_key($en, ['ref' => 1, 'g' => 1]), search_index());
    echo json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$q = mb_substr(trim(m3d_get_text('q')), 0, 100);
$index = $q !== '' ? search_index() : [];
$hits = $q !== '' ? search_run($index, $q) : [];
$fix = $q !== '' && !$hits ? search_fix($index, $q) : '';   // nothing found: the search with its typos corrected ("tiffa" → Tifa), when that finds something
if ($fix !== '') $hits = search_run($index, $fix);
$byRef = [];
foreach (['models', 'outfits'] as $kind) foreach (m3d_listed_items($kind) as $it) $byRef[$kind . ':' . $it['slug']] = $it;
$groups = ['models' => [], 'outfits' => [], 'blog' => [], 'help' => []];
foreach ($hits as $h) $groups[$h['g']][] = $h;

layout_start($q !== '' ? 'Search: ' . $q : 'Search', 'Search the models, accessories, posts and help pages of ' . site_name() . '.', 'page-search');
?>
  <div class="wrap search-page">
    <p class="eyebrow">Search</p>
    <form class="search-form" action="/search/" method="get" role="search">
      <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search models, accessories, help…" aria-label="Search" <?= $q === '' ? 'autofocus' : '' ?> />
      <button class="btn btn-primary">Search</button>
    </form>
    <?php if ($q !== ''): ?>
      <p class="muted search-count"><?php if ($fix !== ''): ?>Nothing found for “<?= e($q) ?>”. Showing <?= count($hits) === 1 ? 'the result' : count($hits) . ' results' ?> for <strong class="search-fix">“<?= e($fix) ?>”</strong>.<?php else: ?><?= count($hits) ? count($hits) . ' ' . (count($hits) === 1 ? 'result' : 'results') . ' for “' . e($q) . '”' : 'Nothing found for “' . e($q) . '”. Try fewer or shorter words.' ?><?php endif ?></p>
    <?php endif ?>

    <?php if ($groups['models']): ?>
      <section class="search-sec">
        <h2 class="block-title">Models <span class="count"><?= count($groups['models']) ?></span></h2>
        <div class="grid"><?php foreach ($groups['models'] as $h) if (isset($byRef[$h['ref']])) echo model_card($byRef[$h['ref']]) ?></div>
      </section>
    <?php endif ?>
    <?php if ($groups['outfits']): ?>
      <section class="search-sec">
        <h2 class="block-title">Accessories <span class="count"><?= count($groups['outfits']) ?></span></h2>
        <div class="ogrid ogrid-lg"><?php foreach ($groups['outfits'] as $h) if (isset($byRef[$h['ref']])) echo outfit_card($byRef[$h['ref']]) ?></div>
      </section>
    <?php endif ?>
    <?php if ($groups['blog']): ?>
      <section class="search-sec">
        <h2 class="block-title">Blog <span class="count"><?= count($groups['blog']) ?></span></h2>
        <div class="bl-grid"><?php foreach ($groups['blog'] as $h) if ($p = m3d_post(substr($h['ref'], 5))) echo blog_card($p) ?></div>
      </section>
    <?php endif ?>
    <?php if ($groups['help']): ?>
      <section class="search-sec">
        <h2 class="block-title">Help <span class="count"><?= count($groups['help']) ?></span></h2>
        <ul class="search-help">
          <?php foreach ($groups['help'] as $h): ?>
            <li><a href="<?= e($h['u']) ?>"><span class="sh-kind"><?= e($h['k']) ?></span><span class="sh-t"><?= e($h['t']) ?></span><?php if ($h['s'] !== ''): ?><span class="muted small"><?= e($h['s']) ?></span><?php endif ?></a></li>
          <?php endforeach ?>
        </ul>
      </section>
    <?php endif ?>
  </div>
<?php layout_end();
