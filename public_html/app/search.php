<?php
// The site search (search.php: /search/ and the header box's list, ?index=1) and its guesses: a search that finds nothing is
// corrected word by word to the closest words of the index (typos: "tiffa" → Tifa, search_fix(), the same as typoFix() in site.js),
// and the 404 page guesses the page meant from the words of the address (search_guess_path()). Loaded only by search.php and not_found().
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

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
        $for = m3d_fits_all($o) ? ['All Models'] : array_values(array_filter(array_map(fn($s) => $names[$s] ?? '', (array) ($o['models'] ?? []))));
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

/** Edit distance where two swapped letters count as one typo ("aeirth" → "aerith" = 1). Same as typoDist() in site.js. */
function search_dist(array $a, array $b): int
{
    $n = count($a);
    $m = count($b);
    $d = [];
    for ($i = 0; $i <= $n; $i++) $d[$i] = [$i];
    for ($j = 0; $j <= $m; $j++) $d[0][$j] = $j;
    for ($i = 1; $i <= $n; $i++) for ($j = 1; $j <= $m; $j++) {
        $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + ($a[$i - 1] === $b[$j - 1] ? 0 : 1));
        if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
    }
    return $d[$n][$m];
}

/** How close a typed word is to a word of the index (lower = closer), null when too far: a whole word, else the start of a longer one
 *  (a word still being typed, half a point worse). Typos allowed: 1 from 4 letters, 2 from 8; shorter words are never corrected. */
function search_near(array $w, array $v): ?float
{
    $n = count($w);
    if ($n < 4) return null;
    $max = $n >= 8 ? 2 : 1;
    $best = abs($n - count($v)) <= $max ? search_dist($w, $v) : 9;
    for ($l = $n - 1; $l <= $n + 1; $l++) if ($l < count($v)) $best = min($best, search_dist($w, array_slice($v, 0, $l)) + .5);
    return $best <= $max + .5 ? (float) $best : null;
}

/**
 * A search that found nothing, corrected: each word found nowhere in the index becomes its closest word there (title words first, then the
 * most used), the words found stay as typed. Returns the corrected search, spelled as in the titles ("Tifa"), or '' when no word could
 * be corrected or the corrected search finds nothing either.
 */
function search_fix(array $index, string $q): string
{
    $typed = array_values(array_filter(preg_split('/\s+/u', trim($q)) ?: [], 'strlen'));
    if (!$typed || count($typed) > 6) return '';
    $hays = [];
    $vocab = [];   // folded word => [spelling, in a title, entries using it]
    foreach ($index as $en) {
        $hay = m3d_fold($en['t'] . ' ' . $en['s'] . ' ' . $en['k'] . ' ' . $en['x']);
        $hays[] = $hay;
        $title = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', (string) $en['t']) ?: [] as $t) $title[m3d_fold($t)] = $t;
        foreach (array_unique(preg_split('/[^\p{L}\p{N}]+/u', $hay) ?: []) as $v) {
            if (mb_strlen($v) < 3 || ctype_digit($v)) continue;
            $vocab[$v] ??= [$v, false, 0];
            $vocab[$v][2]++;
            if (isset($title[$v]) && !$vocab[$v][1]) $vocab[$v] = [$title[$v], true, $vocab[$v][2]];
        }
    }
    $out = [];
    $fixed = false;
    foreach ($typed as $word) {
        $w = m3d_fold($word);
        foreach ($hays as $hay) if (str_contains($hay, $w)) { $out[] = $word; continue 2; }
        $wc = mb_str_split($w);
        $best = null;
        foreach ($vocab as $v => [$spell, $inTitle, $uses]) {
            $sc = search_near($wc, mb_str_split((string) $v));
            if ($sc === null) continue;
            $key = [$sc, $inTitle ? 0 : 1, -$uses];
            if (!$best || $key < $best[0]) $best = [$key, $spell];
        }
        if (!$best) return '';
        $out[] = $best[1];
        $fixed = true;
    }
    $fix = implode(' ', $out);
    return $fixed && search_run($index, $fix) ? $fix : '';
}

/**
 * The 404 page's guesses: up to $max entries of the search index (no FAQ questions) matching the words of the address that wasn't found
 * ("/models/tifaa/" → Tifa), those of the address's own kind first (/models/, /accessories/, /docs/, /blog/). The words are searched as
 * they are, else corrected (search_fix()), else one at a time, longest first. Nothing for addresses with a file name (scanners asking for
 * /wp-login.php and such), nor for long ones.
 */
function search_guess_path(string $uri, int $max = 3): array
{
    $path = trim(rawurldecode((string) parse_url($uri, PHP_URL_PATH)), '/');
    if ($path === '' || str_contains($path, '.') || mb_strlen($path) > 120) return [];
    $parts = explode('/', strtolower($path));
    if (count($parts) > 4) return [];
    $groups = ['models' => 'models', 'accessories' => 'outfits', 'docs' => 'help', 'blog' => 'blog'];
    $group = $groups[$parts[0]] ?? '';
    if ($group !== '' || in_array($parts[0], ['gallery', 'bugs', 'search'], true)) array_shift($parts);
    $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', implode(' ', $parts)) ?: [], fn($w) => mb_strlen($w) >= 2 && !ctype_digit($w))));
    if (!$words || count($words) > 6) return [];
    $index = array_values(array_filter(search_index(), fn($en) => $en['k'] !== 'FAQ'));
    $tries = [implode(' ', $words)];
    $hits = search_run($index, $tries[0]);
    if (!$hits && ($fix = search_fix($index, $tries[0])) !== '') $hits = search_run($index, $fix);
    if (!$hits && count($words) > 1) {
        usort($words, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($words as $w) {
            $hits = search_run($index, $w);
            if (!$hits && ($fix = search_fix($index, $w)) !== '') $hits = search_run($index, $fix);
            if ($hits) break;
        }
    }
    if ($group !== '') usort($hits, fn($a, $b) => ($a['g'] === $group ? 0 : 1) <=> ($b['g'] === $group ? 0 : 1));   // stable: keeps the rank inside
    return array_slice($hits, 0, $max);
}
