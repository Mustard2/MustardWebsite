<?php
// /search/?q=…  (rewritten here by .htaccess): one search over models, accessories, blog posts, docs pages and FAQ questions.
//   ?index=1  the list the header search box (site.js, [data-hsearch]) searches as you type: the same for every visitor
//             (cacheable), so only what any visitor may see: listed items (no drafts, no unlisted password items), docs pages
//             that aren't drafts, FAQ questions, blog posts that are out (Patrons Only ones too: their title and summary are public);
//             pictures only for fully public items and Public posts (like link previews).
//   ?q=       the full results page (also what the box opens on Enter, and the search without JavaScript).
// Matching (same in site.js): lower case, accents ignored, every word must be found in the title or the extra words.
require __DIR__ . '/app/_init.php';

/** Everything searchable: [{k: kind label, t: title, s: line under it, u: address, p: picture, x: extra words, g: group, ref: kind:slug}]. */
function search_index(): array
{
    $out = [];
    foreach (m3d_listed_items('models') as $m) {
        $pic = m3d_visibility($m) === 'public' ? item_pic('models', $m) : '';
        $out[] = ['g' => 'models', 'k' => 'Model', 't' => (string) $m['name'], 's' => trim(($m['franchise'] ?? '') . (($m['generation'] ?? '') !== '' ? ' · Gen ' . $m['generation'] : '') . (!empty($m['majorVersion']) && m3d_model_versions($m) ? ' · v' . (int) $m['majorVersion'] : ''), ' ·'),
                  'u' => m3d_item_url('models', (string) $m['slug']), 'p' => $pic !== '' ? img_thumb($pic) : '',
                  'x' => implode(' ', [(string) ($m['game'] ?? ''), (string) ($m['slug'] ?? '')]), 'ref' => 'models:' . $m['slug']];
    }
    $names = [];
    foreach (m3d_listed_items('models') as $m) $names[$m['slug']] = (string) $m['name'];   // listed only: no draft or hidden password model names
    foreach (m3d_listed_items('outfits') as $o) {
        $pic = m3d_visibility($o) === 'public' ? outfit_preview($o) : '';
        $for = array_values(array_filter(array_map(fn($s) => $names[$s] ?? '', (array) ($o['models'] ?? []))));
        $out[] = ['g' => 'outfits', 'k' => m3d_accessory_type($o), 't' => (string) $o['name'], 's' => $for ? 'For ' . implode(', ', array_slice($for, 0, 3)) . (count($for) > 3 ? '…' : '') : 'Accessory',
                  'u' => m3d_item_url('outfits', (string) $o['slug']), 'p' => $pic !== '' ? img_thumb($pic) : '',
                  'x' => implode(' ', array_merge(['accessory', (string) ($o['slug'] ?? '')], $for)), 'ref' => 'outfits:' . $o['slug']];
    }
    if (m3d_blog_on()) {
        foreach (m3d_blog()['posts'] as $p) {
            if (m3d_post_state($p) !== 'live') continue;
            $pic = ($p['visibility'] ?? 'public') === 'public' ? (string) ($p['cover'] ?? '') : '';
            $out[] = ['g' => 'blog', 'k' => 'Blog', 't' => (string) $p['title'], 's' => m3d_site_time(m3d_post_ts($p), 'j M Y') . (!empty($p['topics']) ? ' · ' . implode(', ', $p['topics']) : ''),
                      'u' => m3d_post_url($p), 'p' => str_starts_with($pic, '/media/') ? img_thumb($pic) : '', 'x' => (string) ($p['summary'] ?? ''), 'ref' => 'blog:' . $p['id']];
        }
    }
    if (m3d_has_docs()) {
        $home = m3d_docs_home();
        foreach (m3d_doc_pages(true) as $p) {
            $out[] = ['g' => 'help', 'k' => 'Docs', 't' => (string) ($p['title'] ?? $p['slug']), 's' => (string) ($p['section'] ?? ''),
                      'u' => $p['slug'] === $home ? '/docs/' : '/docs/' . $p['slug'] . '/', 'p' => '', 'x' => (string) ($p['summary'] ?? ''), 'ref' => ''];
        }
    }
    if (m3d_has_faq()) {
        foreach (m3d_faq()['sections'] ?? [] as $sec) foreach ($sec['items'] ?? [] as $it) {
            if (trim((string) ($it['q'] ?? '')) === '') continue;
            $out[] = ['g' => 'help', 'k' => 'FAQ', 't' => (string) $it['q'], 's' => (string) ($sec['title'] ?? ''),
                      'u' => '/faq/#' . faq_id((string) ($sec['title'] ?? '')) . '--' . faq_id((string) $it['q']), 'p' => '', 'x' => '', 'ref' => ''];
        }
    }
    return $out;
}

/** The entries every word of $q is found in, best first: title starting with the search, a title word starting with its first word, another word
 *  starting with it (franchise, type…), the title containing it, the rest. */
function search_run(array $index, string $q): array
{
    $words = array_values(array_filter(preg_split('/\s+/', m3d_fold(trim($q))) ?: []));
    if (!$words) return [];
    $full = implode(' ', $words);
    $hits = [];
    foreach ($index as $i => $en) {
        $title = m3d_fold($en['t']);
        $hay = $title . ' ' . m3d_fold($en['s'] . ' ' . $en['k'] . ' ' . $en['x']);
        foreach ($words as $w) if (!str_contains($hay, $w)) continue 2;
        $start = '/(^|\W)' . preg_quote($words[0], '/') . '/u';
        $rank = str_starts_with($title, $full) ? 0 : (preg_match($start, $title) ? 1 : (preg_match($start, $hay) ? 2 : (str_contains($title, $words[0]) ? 3 : 4)));
        $hits[] = [$rank, $i, $en];
    }
    usort($hits, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    return array_column($hits, 2);
}

if (isset($_GET['index'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, max-age=300');
    header('X-Robots-Tag: noindex');
    $list = m3d_maintenance_blocks() ? [] : array_map(fn($en) => array_diff_key($en, ['ref' => 1, 'g' => 1]), search_index());
    echo json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$q = mb_substr(trim(m3d_get_text('q')), 0, 100);
$hits = $q !== '' ? search_run(search_index(), $q) : [];
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
      <p class="muted search-count"><?= count($hits) ? count($hits) . ' ' . (count($hits) === 1 ? 'result' : 'results') . ' for “' . e($q) . '”' : 'Nothing found for “' . e($q) . '”. Try fewer or shorter words.' ?></p>
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
