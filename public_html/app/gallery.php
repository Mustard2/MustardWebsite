<?php
// Gallery: stills / renders shown on /gallery/, searchable by character.
// data/gallery.json (Admin → Gallery; missing = empty):
//   stills: [{id, src, caption, date (Y-m-d), chars: [model slug | free name], visibility, link: {url, access, size}}], in the page's order
//   (dragged in the admin; `ordered` stays in the file so code from before 2026-10-15, restored from a backup, keeps that order)
// A still follows the models it shows when they are stricter (m3d_still_view()): a draft model or a password model still
// locked hides it, a blurred model blurs it (blurred-patrons: clear for paying patrons), like the model's own pictures.
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

/** Visibility choices of a still (no password: a still follows a password model instead). */
const GALLERY_VISIBILITY = ['public' => 'Public', 'blurred' => 'Teaser', 'blurred-patrons' => 'Clear for Patrons', 'draft' => 'Draft'];

function m3d_gallery(bool $fresh = false): array
{
    static $g = null;
    if ($g !== null && !$fresh) return $g;
    $d = m3d_read_json('gallery') ?? [];
    $stills = array_values(array_filter((array) ($d['stills'] ?? []), fn($s) => is_array($s) && !empty($s['id']) && !empty($s['src'])));
    return $g = ['stills' => $stills];
}

function m3d_save_gallery(array $g): bool
{
    $ok = m3d_write_json('gallery', ['version' => 1, 'updated' => gmdate('c'), 'ordered' => true, 'stills' => array_values($g['stills'] ?? [])]);
    m3d_gallery(true);
    return $ok;
}

/** The menus (and the sitemap) link the gallery once this visitor can see a still: not a draft, and not of a draft or locked password model. */
function m3d_has_gallery(): bool
{
    static $has = null;
    if ($has !== null) return $has;
    foreach (m3d_gallery()['stills'] as $s) if (m3d_still_view($s) !== 'hidden') return $has = true;
    return $has = false;
}

function m3d_still(string $id): ?array
{
    foreach (m3d_gallery()['stills'] as $s) if ($s['id'] === $id) return $s;
    return null;
}

/** Address part for a free character name ("Lyra Moon" → "lyra-moon"); a name without Latin letters or digits (Japanese…) gets
 *  "c-" and a short hash of the name, so two such names stay two characters. */
function m3d_char_slug(string $name): string
{
    $s = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name), '-'));
    return substr($s, 0, 64) ?: 'c-' . substr(md5(mb_strtolower(trim($name))), 0, 8);
}

/** A still's name in lists: its caption, else its characters' names, else $none. */
function m3d_still_name(array $s, string $none = 'Picture'): string
{
    return trim((string) ($s['caption'] ?? '')) ?: (implode(', ', array_column(m3d_still_chars($s), 'name')) ?: $none);
}

/** The characters of a still: [{key, name, franchise, model (array|null)}]; a model slug that no longer exists counts as a name. */
function m3d_still_chars(array $s): array
{
    static $models = null;
    if ($models === null) { $models = []; foreach (m3d_items('models') as $m) $models[$m['slug']] = $m; }
    $out = [];
    foreach ((array) ($s['chars'] ?? []) as $c) {
        $c = (string) $c;
        if ($c === '') continue;
        $m = $models[$c] ?? null;
        $key = $m ? $c : m3d_char_slug($c);
        $out[$key] = ['key' => $key, 'name' => $m ? m3d_model_name($m) : $c, 'franchise' => $m ? (string) ($m['franchise'] ?? '') : '', 'model' => $m];
    }
    return array_values($out);
}

/**
 * What this visitor gets of a still: 'hidden' (left out), 'blur' (blurred copy, no viewer, no download) or 'clear'.
 * The strictest of the still's own visibility and the visibility of every model it shows.
 */
function m3d_still_view(array $s): string
{
    $admin = m3d_is_admin($GLOBALS['M3D']);
    $own = (string) ($s['visibility'] ?? 'public');
    if ($own === 'draft' && !$admin) return 'hidden';
    $blur = $own === 'blurred' || ($own === 'blurred-patrons' && !m3d_sees_patron_pics());
    foreach (m3d_still_chars($s) as $c) {
        if (!$c['model']) continue;
        if (!m3d_can_view('models', $c['model'])) return 'hidden';   // draft model, or password model still locked
        if (m3d_pics_hidden('models', $c['model'])) $blur = true;
    }
    return $blur ? 'blur' : 'clear';
}

/** What everyone gets of a still (a visitor not logged in, no password typed): like m3d_still_view() for them. Discord posts use it. */
function m3d_still_public_view(array $s): string
{
    $own = (string) ($s['visibility'] ?? 'public');
    if ($own === 'draft') return 'hidden';
    $blur = $own === 'blurred' || $own === 'blurred-patrons';
    foreach (m3d_still_chars($s) as $c) {
        if (!$c['model']) continue;
        $v = m3d_visibility($c['model']);
        if ($v === 'draft' || $v === 'password' || m3d_release_hidden($c['model'])) return 'hidden';
        if ($v === 'blurred' || $v === 'blurred-patrons') $blur = true;
    }
    return $blur ? 'blur' : 'clear';
}

/** The stills this visitor may see, in the gallery's order (Admin → Gallery; new pictures come in at the top). */
function m3d_gallery_visible(): array
{
    $out = [];
    foreach (m3d_gallery()['stills'] as $s) {
        $v = m3d_still_view($s);
        if ($v !== 'hidden') $out[] = $s + ['view' => $v];
    }
    return $out;
}

/** The Gallery page's Sort choices; the first is the default. */
const GALLERY_SORTS = ['random', 'new', 'old', 'liked'];

/**
 * The visible stills in the order the page arrives in, so the first row (fetched at once) is the one shown and nothing moves when site.js
 * runs: random = a new order on every visit (the page is never cached), kept while the visitor filters and scrolls; new = the gallery's
 * order; old = reversed; liked = most likes first. `n` = the place in the gallery's order (data-n: site.js sorts Newest / Oldest by it).
 */
function m3d_gallery_sorted(array $stills, string $sort): array
{
    foreach ($stills as $i => &$s) $s['n'] = $i;
    unset($s);
    if ($sort === 'random') shuffle($stills);
    elseif ($sort === 'old') $stills = array_reverse($stills);
    elseif ($sort === 'liked') usort($stills, fn($a, $b) => m3d_like_count((string) $b['id'], 'stills') <=> m3d_like_count((string) $a['id'], 'stills') ?: $a['n'] <=> $b['n']);
    return $stills;
}

/** Characters found in a list of stills: key => {key, name, franchise, model, count, latest}, the most recent first. */
function m3d_gallery_chars(array $stills): array
{
    $out = [];
    foreach ($stills as $s) foreach (m3d_still_chars($s) as $c) {
        $k = $c['key'];
        $out[$k] ??= $c + ['count' => 0, 'latest' => ''];
        $out[$k]['count']++;
        $out[$k]['latest'] = max($out[$k]['latest'], (string) ($s['date'] ?? ''));
    }
    uasort($out, fn($a, $b) => strcmp($b['latest'], $a['latest']) ?: strcasecmp($a['name'], $b['name']));
    return $out;
}

/** Pictures removed from the gallery: their likes go too. */
function m3d_gallery_forget_likes(array $ids): void
{
    if (!$ids) return;
    m3d_json_update('likes', function ($d) use ($ids) {
        if (!is_array($d) || !is_array($d['stills'] ?? null)) return [$d, null];
        foreach ($ids as $id) unset($d['stills'][$id]);
        return [$d, null];
    });
}

/** Replaces picture addresses (old => new) in the stills (crop "use everywhere", copied outside images…). */
function m3d_gallery_replace(array $map): bool
{
    $g = m3d_gallery(true);   // fresh: called after long jobs
    $new = $g;
    foreach ($new['stills'] as &$s) if (isset($map[$s['src']])) $s['src'] = $map[$s['src']];
    unset($s);
    return $new === $g || m3d_save_gallery($new);
}

/**
 * Models deleted (slug => name): their stills keep the name as a plain character, so they stay searchable. $vis (slug => the
 * model's visibility as it was) keeps their pictures as hidden as the model made them: a draft, password or not yet released
 * model's pictures become drafts, a teaser model's are blurred (the stricter of that and the picture's own).
 */
function m3d_gallery_forget_models(array $names, array $vis = []): void
{
    $rank = ['public' => 0, 'blurred-patrons' => 1, 'blurred' => 2, 'draft' => 3];
    $g = m3d_gallery();
    $changed = false;
    foreach ($g['stills'] as &$s) {
        $before = (array) ($s['chars'] ?? []);
        foreach ($before as $c) {
            $v = $vis[$c] ?? 'public';
            $v = isset($rank[$v]) ? $v : 'draft';   // password
            if ($rank[$v] > ($rank[$s['visibility'] ?? 'public'] ?? 0)) { $s['visibility'] = $v; $changed = true; }
        }
        $chars = array_map(fn($c) => $names[$c] ?? $c, $before);
        if ($chars !== $before) { $s['chars'] = $chars; $changed = true; }
    }
    unset($s);
    if ($changed) m3d_save_gallery($g);
}

// ── Pieces of the gallery pages ──────────────────────────────────────────────

const DOWNLOAD_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg>';

/** Round face of a character (the model's catalog picture, blurred when its pictures are hidden; initials for names without a model). */
function char_avatar(array $c, int $size = 28): string
{
    if ($c['model']) {
        [$src, $hidden] = card_pic('models', $c['model']);
        if (!$hidden && $src !== '') $src = avatar_src($src) ?: $src;   // a tiny copy, not the thumb file
        if ($src !== '') return '<img class="g-av' . ($hidden ? ' g-av-blur' : '') . '" src="' . e($src) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async" />';
    }
    $ini = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($c['name'])) ?: [], 0, 2)));
    return '<span class="g-av g-av-ini" style="width:' . $size . 'px;height:' . $size . 'px" aria-hidden="true">' . e(mb_strtoupper($ini)) . '</span>';
}

/** The viewer's "Download Full Size" button (`dl` of a slide) for a full size link {url, access, size?} sent through $href (/get/still/…). */
function full_size_dl(string $href, array $link): array
{
    $locked = ($link['access'] ?? 'patrons') === 'patrons' && !m3d_sees_patron_pics();
    return ['href' => $href, 'size' => (string) ($link['size'] ?? ''), 'locked' => $locked,
            'note' => $locked ? 'Full size for patrons (any paid tier)' : (string) ($link['size'] ?? '')];
}

// ── Full size links of model and accessory pictures ─────────────────────────────────────────────────────────────────
// A picture of a model's gallery row (`gallery`) or of an accessory (`images`) may carry `link` {url, access, size} like a still:
// set in the Images page's picture window (every place using the picture at once) or in the editor's picture window (that card).
// The page's viewer gets "Download Full Size" through /get/still/<m|o>/<slug>/<code>/ (auth/get-still.php), code = m3d_pic_code().

/** The item field holding its pictures with full size links. */
const PIC_FIELDS = ['models' => 'gallery', 'outfits' => 'images'];

/** A picture's code in its download address: from the picture's address, so reordering the pictures doesn't change it. */
function m3d_pic_code(string $src): string
{
    return substr(md5(img_large($src)), 0, 8);
}

/** The picture of an item's gallery / pictures with that code, as stored ({src, caption?, link?}), or null. */
function m3d_item_pic(string $kind, array $item, string $code): ?array
{
    foreach ((array) ($item[PIC_FIELDS[$kind] ?? ''] ?? []) as $g) {
        if (is_array($g) && ($g['src'] ?? '') !== '' && m3d_pic_code((string) $g['src']) === $code) return $g;
    }
    return null;
}

/** An item's pictures for its page (as_image()), those with a full size link with the viewer's `dl` (never the link itself). */
function item_pics(string $kind, array $item): array
{
    $out = [];
    foreach ((array) ($item[PIC_FIELDS[$kind]] ?? []) as $g) {
        $p = as_image($g);
        if ($p['src'] === '') continue;
        if (is_array($g['link'] ?? null) && !empty($g['link']['url'])) {
            $p['dl'] = full_size_dl('/get/still/' . ($kind === 'outfits' ? 'o' : 'm') . '/' . $item['slug'] . '/' . m3d_pic_code($p['src']) . '/', $g['link']);
        }
        $out[] = $p;
    }
    return $out;
}

/** For the viewer (site.js): the picture, caption + characters (linked to their model pages), its like button (`like` = the still's id) and, when it has one, the "Download full size" button. */
function still_slide(array $s): array
{
    $cap = trim((string) ($s['caption'] ?? ''));
    $slide = ['src' => (string) $s['src'], 'caption' => $cap, 'like' => (string) $s['id']];
    // a patron render (`from`) whose author linked their profile: its "By Name" at the end of the caption becomes that link
    $r = !empty($s['from']) && function_exists('m3d_render') ? m3d_render((string) $s['from']) : null;
    if ($r && ($link = m3d_render_link($r))) {
        $tail = 'By ' . $link['name'];
        if ($cap === $tail || str_ends_with($cap, ' · ' . $tail)) $slide = ['caption' => trim(substr($cap, 0, -strlen($tail)), ' ·'), 'by' => render_slide_by($link)] + $slide;
    }
    // the characters after the caption, each a link to its model page (names without a model stay plain text)
    $slide['chars'] = array_map(fn($c) => ['name' => $c['name'], 'url' => $c['model'] && m3d_can_view('models', $c['model']) ? m3d_item_url('models', $c['model']['slug']) : ''], m3d_still_chars($s));
    $link = $s['link'] ?? null;
    if (is_array($link) && !empty($link['url'])) $slide['dl'] = full_size_dl('/get/still/' . $s['id'] . '/', $link);
    return $slide;
}

/** One picture of the wall. $n = its place in the viewer's list (null: blurred, not opened). */
function still_tile(array $s, ?int $n, bool $eager = false): string
{
    $chars = m3d_still_chars($s);
    $blur = ($s['view'] ?? 'clear') === 'blur';
    $src = $blur ? blur_src((string) $s['src']) : img_small((string) $s['src']);
    [$w, $h] = img_dims((string) $s['src']) ?: [3, 2];
    $r = $h > 0 ? round($w / $h, 4) : 1.5;
    $tags = '';
    foreach ($chars as $c) $tags .= '<span class="g-tag">' . char_avatar($c, 18) . e($c['name']) . '</span>';
    $cap = trim((string) ($s['caption'] ?? ''));
    $search = implode(' ', array_merge([$cap], array_column($chars, 'name'), array_column($chars, 'franchise')));
    $pills = '';
    if ($blur) {   // "Teaser" when nobody sees it clear yet, "Patrons" when patrons do
        $teaser = ($s['visibility'] ?? '') === 'blurred' || array_filter($chars, fn($c) => $c['model'] && m3d_visibility($c['model']) === 'blurred');
        $pills .= '<span class="lock-pill">' . LOCK_ICON . ($teaser ? 'Teaser' : 'Patrons') . '</span>';
    }
    elseif (!empty($s['link']['url'])) {   // the lock only while this visitor can't download it yet (like the viewer's button)
        $pat = ($s['link']['access'] ?? 'patrons') === 'patrons';
        $locked = $pat && !m3d_sees_patron_pics();
        $pills .= '<span class="g-full' . ($pat ? ' pat' : '') . '" title="' . ($locked ? 'Full size for patrons (any paid tier)' : 'A full size file can be downloaded') . '">' . ($locked ? LOCK_ICON : DOWNLOAD_ICON) . 'Full Size</span>';
    }
    if (($s['visibility'] ?? 'public') === 'draft') $pills .= '<span class="lock-pill">Draft</span>';   // only admins see drafts
    $attrs = ' class="g-tile' . ($blur ? ' is-blurred' : '') . '" style="--r:' . $r . '" data-tile data-chars="' . e(implode(' ', array_column($chars, 'key'))) . '"'
        . ' data-franchises="' . e('|' . implode('|', array_unique(array_filter(array_column($chars, 'franchise')))) . '|') . '"'
        . ' data-search="' . e(mb_strtolower($search)) . '" data-date="' . e((string) ($s['date'] ?? '')) . '" data-n="' . (int) ($s['n'] ?? 0) . '"';
    $alt = $cap !== '' ? $cap : implode(', ', array_column($chars, 'name'));
    // the browser picks the thumb (≤ 560 px) or the small file by the tile's width (260 px tall, 150 px on phones; rows stretch them a little)
    $set = $blur ? '' : img_srcset((string) $s['src'], ['xs', 'sm']);
    $sizes = '(max-width: 720px) ' . (int) ceil(150 * $r * 1.2) . 'px, ' . (int) ceil(260 * $r * 1.2) . 'px';
    $img = '<img src="' . e($src) . '"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . $sizes . '"' : '') . ' alt="' . e($alt) . '"' . ($eager ? ' fetchpriority="high"' : ' loading="lazy"') . ' decoding="async" />';
    $over = ($pills !== '' ? '<span class="cover-tags">' . $pills . '</span>' : '')
        . '<span class="g-cap">' . ($cap !== '' ? '<span class="g-title">' . e($cap) . '</span>' : '') . ($tags !== '' ? '<span class="g-tags">' . $tags . '</span>' : '') . '</span>';
    if ($n === null) return '<figure' . $attrs . '>' . $img . $over . '</figure>';   // blurred: no viewer, no likes
    // clear: the picture is the viewer's button, the heart its own button next to it (buttons can't hold buttons)
    $likes = m3d_like_count((string) $s['id'], 'stills');
    return '<div' . $attrs . ' data-gid="' . e((string) $s['id']) . '" data-likes="' . $likes . '" data-slide="' . $n . '" data-k="' . $n . '">'
        . '<button type="button" class="g-open" aria-label="Open ' . e($cap !== '' ? $cap : 'picture') . '">' . $img . '</button>' . $over
        . '<button type="button" class="g-like" data-glike="' . e((string) $s['id']) . '" aria-pressed="false" title="Like this picture (patrons)">'
        . HEART_ICON . '<span data-glike-count>' . ($likes ?: '') . '</span><span class="sr-only"> likes</span></button></div>';
}

/** The wall: the tiles in a [data-slides] set, the viewer's list made of the clear ones. $eager: the first row is fetched at once (not when
 *  the address filters the pictures: those tiles may be hidden). */
function still_wall(array $stills, bool $eager = true): string
{
    $slides = [];
    $tiles = '';
    $extra = '';
    foreach ($stills as $k => $s) {
        $first = $eager && $k < 4;   // the first row: fetched at once, before the rest
        if (($s['view'] ?? 'clear') === 'blur') { $tiles .= still_tile($s, null, $first); continue; }
        $tiles .= still_tile($s, count($slides), $first);
        $slides[] = still_slide($s);
    }
    // logged in: their own likes and what they may do, in the page (site.js needs no request); visitors see the counts only
    $p = m3d_patron($GLOBALS['M3D']);
    if ($p) {
        $code = m3d_like_code((string) $p['id']);
        $likes = m3d_likes('stills');
        $mine = [];
        foreach ($stills as $s) if (($s['view'] ?? 'clear') === 'clear' && in_array($code, $likes[$s['id']] ?? [], true)) $mine[] = (string) $s['id'];
        $extra .= ' data-glikes="' . e(json_encode(['liked' => $mine] + m3d_like_viewer($p))) . '"';
    }
    return '<div class="g-wall" data-gwall' . $extra . ' data-slides="' . e(json_encode($slides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">' . $tiles . '</div>';
}
