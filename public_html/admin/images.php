<?php
// Image library: every image in <site>/media/, where it is used, upload, copy address, delete.
// Also converts leftover non-WebP images in media/ (banner shown only when there are some).
require __DIR__ . '/_admin.php';
// the JSON requests (crop, delete…, ?info=) get the JSON "logged out" error, not a redirect to the login a fetch can't follow
admin_require($M3D, isset($_GET['info']) || (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')));

const BATCH_OPT = 10;

function unoptimized(array $models, ?array $outfits = null): array
{
    $out = [];
    foreach (array_keys(usage_map($models, $outfits)) as $url) {
        if (!preg_match('/\.(webp|jpe?g|png|gif)$/i', $url)) continue;   // pictures only (a 3D preview's .glb is in the map too)
        $p = media_path($url);
        if (!$p || !is_file($p) || img_large($url) !== $url) continue;
        if (str_ends_with(strtolower($p), '.gif') && img_is_animated_gif($p)) continue;
        if (img_small($url) === $url) $out[] = $url;
    }
    return $out;
}

/** Images still loaded from other websites: url => media folder to copy them into. */
function external_images(): array
{
    $found = [];
    $isImg = fn($k) => in_array((string) $k, ['thumb', 'cover', 'src', 'image'], true) || is_int($k);
    // pictures written inside a text: ![caption](https://…)
    $inText = function (string $text, string $folder) use (&$found) {
        if (str_contains($text, '](') && preg_match_all('/!\[[^\]]*\]\((https?:\/\/[^\s)]+)\)/i', $text, $mm)) foreach ($mm[1] as $u) if (!preg_match('/\.(mp4|webm)(?:[?#]|$)/i', $u)) $found[$u] ??= $folder;   // a video on another website stays a link (md_media())
    };
    foreach (['models', 'outfits'] as $kind) foreach (m3d_items($kind) as $m) {
        $folder = ($kind === 'outfits' ? 'outfit-' : '') . $m['slug'];
        foreach (['thumb', 'cover', 'gallery', 'images', 'notes', 'changelog'] as $field) {
            if (!isset($m[$field])) continue;
            $v = [$field => $m[$field]];
            array_walk_recursive($v, function ($x, $k) use (&$found, $folder, $isImg) {
                if (is_string($x) && $isImg($k) && preg_match('#^https?://#i', $x) && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $x)) $found[$x] ??= $folder;
            });
        }
        // every text of the item (description, notes, changelog notes…)
        array_walk_recursive($m, function ($x) use ($inText, $folder) { if (is_string($x)) $inText($x, $folder); });
    }
    foreach (m3d_texts() as $t) $inText($t['text'], $t['folder']);   // FAQ answers, docs pages, blog posts
    foreach (m3d_blog()['posts'] as $p) {   // blog covers
        $c = (string) ($p['cover'] ?? '');
        if (preg_match('#^https?://#i', $c) && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $c)) $found[$c] ??= 'blog';
    }
    $inText(outfits_intro(), 'misc');   // Admin → Accessories → Page text
    $inText(m3d_welcome()['text'], 'misc');   // Settings → Features → Welcome Page
    return $found;
}

function replace_everywhere(array $models, array $map): array
{
    $inText = [];   // pictures written in texts (descriptions, notes…): ![caption](old) → ![caption](new), old = /media/… or another website's address
    foreach ($map as $old => $new) $inText['](' . $old . ')'] = '](' . $new . ')';
    array_walk_recursive($models, function (&$v) use ($map, $inText) {
        if (!is_string($v)) return;
        if (isset($map[$v])) $v = $map[$v];
        elseif (str_contains($v, '](')) $v = strtr($v, $inText);
    });
    return $models;
}

/** Points every use of the pictures in $map (old address => new) to the new one: models, accessories, FAQ / docs texts, blog posts, gallery. */
function media_replace(array $map): bool
{
    $ok = true;
    foreach (['models', 'outfits'] as $kind) {
        $items = m3d_items($kind, true);   // fresh: optimize / outside images ran for a while, edits saved meanwhile stay
        $new = replace_everywhere($items, $map);
        if ($new !== $items) $ok = m3d_save_items($kind, $new) && $ok;
    }
    $ok = m3d_texts_replace($map) && $ok;
    $intro = (string) (m3d_settings(true)['outfits_intro'] ?? '');   // Admin → Accessories → Page text
    if (($new = replace_everywhere([$intro], $map)[0]) !== $intro) $ok = m3d_save_settings(['outfits_intro' => $new]) && $ok;
    $welcome = (array) (m3d_settings(true)['welcome'] ?? []);   // Settings → Features → Welcome Page
    $wt = (string) ($welcome['text'] ?? '');
    if (($new = replace_everywhere([$wt], $map)[0]) !== $wt) $ok = m3d_save_settings(['welcome' => ['text' => $new] + $welcome]) && $ok;
    return m3d_gallery_replace($map) && $ok;
}

// ── Actions ──────────────────────────────────────────────────────────────────
$results = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    admin_post_guard($M3D);
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (($in['action'] ?? '') === 'delete') {
        $url = (string) ($in['url'] ?? '');
        $p = media_deletable($url);
        if (!$p) admin_json(['ok' => false, 'error' => 'Image not found.'], 404);
        $versions = img_versions($url);
        $use = usage_map(m3d_models());   // everything that can use a picture; a text may use one of its smaller versions
        $used = [];
        foreach ([$url, img_large($url), ...$versions] as $v) $used += $use[$v] ?? [];   // (the address may be one of the smaller files)
        if ($used) admin_json(['ok' => false, 'error' => 'Still used by ' . implode(', ', $used) . '. Remove it there first.'], 409);
        if (m3d_is_video_url($url)) { media_video_delete($url); admin_json(['ok' => true]); }   // with its cover
        @unlink($p);
        foreach ($versions as $v) if ($f = media_deletable($v)) @unlink($f);
        admin_json(['ok' => true]);
    }
    if (($in['action'] ?? '') === 'crop') {
        // Crops (and turns / mirrors) the large version into a NEW file (same folder, name + "-crop-<code>"): images are cached for a year
        // (by CDNs too), so the same address would keep showing the old picture. replace = use it everywhere.
        $url = img_large((string) ($in['url'] ?? ''));
        $p = media_path($url);
        if (!$p || !is_file($p)) admin_json(['ok' => false, 'error' => 'Only images stored on this site can be cropped. Copy outside images here first (Media page).'], 404);
        if (!img_supported()) admin_json(['ok' => false, 'error' => 'Image processing is not available on this server.'], 500);
        if (img_is_animated_gif($p)) admin_json(['ok' => false, 'error' => 'Animated GIFs can’t be cropped (they would stop moving).'], 422);
        @ini_set('memory_limit', '1024M');
        $im = img_load($p);
        if (!$im) admin_json(['ok' => false, 'error' => 'Could not read this image.'], 422);
        // turned / mirrored first (the window shows the picture that way, and the frame is drawn on it): quarter turns clockwise, then a left-right mirror
        $turns = ((int) ($in['rotate'] ?? 0) % 4 + 4) % 4;
        if ($turns) {
            $bg = imagecolorallocatealpha($im, 0, 0, 0, 127);
            $turned = imagerotate($im, 360 - 90 * $turns, $bg);   // GD turns counterclockwise
            unset($im);
            if (!$turned) admin_json(['ok' => false, 'error' => 'Could not turn this image.'], 500);
            $im = $turned;
        }
        if (!empty($in['flip'])) imageflip($im, IMG_FLIP_HORIZONTAL);
        $W = imagesx($im); $H = imagesy($im);
        $x = max(0, min($W - 1, (int) round((float) ($in['x'] ?? 0))));
        $y = max(0, min($H - 1, (int) round((float) ($in['y'] ?? 0))));
        $w = max(1, min($W - $x, (int) round((float) ($in['w'] ?? $W))));
        $h = max(1, min($H - $y, (int) round((float) ($in['h'] ?? $H))));
        if ($w < 16 || $h < 16) admin_json(['ok' => false, 'error' => 'The selected area is too small.'], 422);
        $crop = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        unset($im);
        if (!$crop) admin_json(['ok' => false, 'error' => 'Could not crop this image.'], 500);
        $dir = dirname($p);
        $base = preg_replace('/-crop-[0-9a-f]{6}$/', '', pathinfo($p, PATHINFO_FILENAME)) . '-crop-' . bin2hex(random_bytes(3));
        $tmp = "$dir/.crop-" . bin2hex(random_bytes(4)) . '.png';   // lossless in-between file, removed right after
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        $saved = imagepng($crop, $tmp, 1);
        unset($crop);
        try {
            if (!$saved) throw new RuntimeException('Could not save the cropped image.');
            $name = img_make_versions($tmp, $dir, $base);
        } catch (Throwable $ex) {
            admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
        } finally {
            @unlink($tmp);
        }
        $new = dirname($url) . '/' . $name;
        $replaced = 0;
        if (!empty($in['replace'])) {
            $map = [$url => $new];
            foreach (['sm', 'md', 'xs'] as $suf) if (($old = img_variant($url, $suf)) !== '') $map[$old] = img_variant($new, $suf) ?: ($suf === 'md' ? $new : img_small($new));   // no new -md: the large one is the closest size
            $replaced = count(usage_map(m3d_models())[$url] ?? []);
            if (!media_replace($map)) admin_json(['ok' => false, 'error' => "The cropped image was saved as $name, but the places using the old one couldn’t be updated."], 500);
        }
        admin_json(['ok' => true, 'url' => $new, 'small' => img_small($new), 'w' => $w, 'h' => $h, 'replaced' => $replaced]);
    }
    if (($in['action'] ?? '') === 'fullsize') {
        // the picture window's Full Size Download box: one link for every place showing the picture (empty address = remove it)
        $url = img_large((string) ($in['url'] ?? ''));
        if (!full_size_state($url)) admin_json(['ok' => false, 'error' => 'This picture isn’t in the Gallery, a model’s gallery or an accessory’s pictures.'], 404);
        try { $link = clean_still_link($in['link'] ?? null); }
        catch (InvalidArgumentException $ex) { admin_json(['ok' => false, 'error' => $ex->getMessage()], 422); }
        $n = full_size_save($url, $link);
        if ($n === null) admin_json(['ok' => false, 'error' => m3d_write_error('data')], 500);
        admin_json(['ok' => true, 'n' => $n, 'full' => full_size_state($url)]);
    }
    admin_json(['ok' => false, 'error' => 'Unknown action.'], 400);
}
if (admin_form_post('external')) {
    @set_time_limit(600);
    $map = [];
    if (!empty($_POST['retry'])) $_SESSION['ext_failed'] = [];
    $failed = $_SESSION['ext_failed'] ?? [];
    // images that failed before are skipped, so one unreachable image doesn't block the others
    $todo = array_diff_key(external_images(), array_flip($failed));
    m3d_session_pause(function () use ($todo, &$map, &$results, &$failed) {   // downloads: the other pages stay usable meanwhile
        foreach (array_slice($todo, 0, 15, true) as $url => $folder) {
            try { $map[$url] = copy_external($url, $folder); $results[] = [true, $url, $map[$url]]; }
            catch (Throwable $ex) { $results[] = [false, $url, $ex->getMessage()]; $failed[] = $url; }
        }
    });
    $_SESSION['ext_failed'] = array_values(array_unique($failed));
    if ($map) {
        if (!media_replace($map)) $results[] = [false, '', 'Could not save the data files'];
    }
    $ran = 'external';
}
if (admin_form_post('purge')) $purged = media_purge_unused();
if (admin_form_post('optimize')) {
    @set_time_limit(600);
    m3d_session_release();   // converting pictures takes a while: the other pages stay usable meanwhile
    $models = m3d_models();
    $outfits = m3d_outfits();
    $map = [];
    foreach (array_slice(unoptimized($models, $outfits), 0, BATCH_OPT) as $url) {
        try {
            $path = media_path($url);
            $before = filesize($path);
            $base = pathinfo($path, PATHINFO_FILENAME);
            // another picture with the same name ("photo.jpg" next to "photo.png") already has that address: never overwrite it
            if (is_file(dirname($path) . "/$base.webp") && realpath(dirname($path) . "/$base.webp") !== realpath($path)) $base .= '-' . bin2hex(random_bytes(3));
            $new = dirname($url) . '/' . img_make_versions($path, dirname($path), $base);
            $map[$url] = $new;
            $results[] = [true, $url, sprintf('%.1f MB → %.1f MB', $before / 1048576, filesize(media_path($new)) / 1048576)];
        } catch (Throwable $ex) { $results[] = [false, $url, $ex->getMessage()]; }
    }
    $map = array_filter($map, fn($new, $old) => $new !== $old, ARRAY_FILTER_USE_BOTH);
    if ($map) {
        if (media_replace($map)) {
            $stillUsed = usage_map(replace_everywhere($models, $map), replace_everywhere($outfits, $map));
            foreach (array_keys($map) as $old) if (!isset($stillUsed[$old]) && ($p = media_deletable($old))) @unlink($p);
        } else $results[] = [false, '', 'Could not save the data file'];
    }
}

/** Pictures from before the medium and thumb versions existed (img_missing_versions()): url list. */
function missing_versions(array $items): array
{
    return array_values(array_map(fn($i) => $i['url'], array_filter($items, fn($i) => img_missing_versions($i['url'], [$i['w'], $i['h']]) !== [])));
}

if (admin_form_post('sizes')) {
    @set_time_limit(600);
    session_write_close();   // the other admin pages stay usable meanwhile
    $start = time();
    foreach (missing_versions(media_library()) as $url) {
        if (time() - $start > 40) break;   // a share at a time: the next click goes on (shared hosting stops long requests)
        try { img_make_missing($url); $results[] = [true, $url, '']; }
        catch (Throwable $ex) { $results[] = [false, $url, $ex->getMessage()]; }
    }
    $ran = 'sizes';
}

/** The admin page where a use (a usage_map() key: 'models:<slug>', 'docs:<slug>', 'faq:<n>'…) is changed. */
function use_href(string $key): string
{
    [$k, $sl] = explode(':', $key, 2) + [1 => ''];
    return match ($k) {
        'faq' => '/admin/faq.php', 'other' => '/admin/outfits.php', 'gallery' => '/admin/gallery.php?still=' . rawurlencode($sl),
        'docs' => '/admin/docs.php?page=' . rawurlencode($sl), 'blog' => '/admin/blog.php?post=' . rawurlencode($sl), default => '/admin/edit.php?kind=' . rawurlencode($k) . '&slug=' . rawurlencode($sl),
    };
}

/** Which part of a model / accessory uses each picture: url => [ 'models:<slug>' => ['Main image', …] ] (the image window's "Used by"). */
function use_fields(array $models, array $outfits): array
{
    $labels = ['thumb' => 'Catalog Image', 'cover' => 'Main Image', 'gallery' => 'Gallery', 'images' => 'Pictures', 'notes' => 'Notes',
               'changelog' => 'Changelog', 'description' => 'Description', 'extra' => 'Sidebar'];
    $out = [];
    foreach (['models' => $models, 'outfits' => $outfits] as $kind => $items) foreach ($items as $it) {
        foreach ($it as $field => $v) {
            $v = [$v];
            array_walk_recursive($v, function ($x) use (&$out, $kind, $it, $field, $labels) {
                foreach (media_in($x) as $u) $out[$u][$kind . ':' . $it['slug']][$labels[$field] ?? 'Text'] = true;
            });
        }
    }
    return $out;
}

/** What the picture window (admin.js) shows about a picture stored on this site: size, file, folder, date, the sizes made, and what
 *  uses it ([name, admin page, parts]). $use = usage_map(), $fields = use_fields(), $folder = the folder's name in the menus. */
function image_info(string $url, array $use, array $fields, string $folder): array
{
    $p = (string) media_path($url);
    $name = basename($url);
    $dim = img_dims($url) ?: [0, 0];
    $vers = [];
    foreach (['' => 'Large', 'md' => 'Medium', 'sm' => 'Small', 'xs' => 'Thumb'] as $suf => $label) {
        $v = $suf === '' ? $url : img_variant($url, $suf);
        if ($v !== '' && ($d = img_dims($v))) $vers[] = [$label, $v, $d[0]];
    }
    $versions = img_versions($url);
    $all = [];   // a use of a smaller version (a text can link the -sm file) counts for the picture
    foreach ([$url, ...$versions] as $v) $all += $use[$v] ?? [];
    $uses = [];
    foreach ($all as $key => $n) {
        $f = [];
        foreach ([$url, ...$versions] as $v) $f += $fields[$v][$key] ?? [];
        $uses[] = [$n, use_href($key), implode(', ', array_keys($f))];
    }
    return [
        'name' => $name, 'w' => $dim[0], 'h' => $dim[1],
        'crop' => !str_ends_with(strtolower($name), '.gif'),   // like the card's Crop button: GIFs aren't cropped
        'size' => m3d_size((int) @filesize($p)),
        'type' => strtoupper(pathinfo($name, PATHINFO_EXTENSION)),
        'folder' => $folder,
        'added' => m3d_site_time((int) @filemtime($p), 'j M Y, H:i T'),
        'vers' => $vers,
        'uses' => $uses,
        'full' => full_size_state($url),
    ];
}

/**
 * Full size links (the viewer's "Download Full Size"): every place showing a picture in a viewer that can have one, by picture address
 * (large file): Gallery pictures and the pictures of models' gallery rows and of accessories (PIC_FIELDS).
 * [url => [['link' => {url, access, size?} | null, 'dls' => downloads], …]]
 */
function full_size_places(bool $fresh = false): array
{
    static $map = null;
    if ($map !== null && !$fresh) return $map;
    $map = [];
    $stats = m3d_stats();
    foreach (PIC_FIELDS as $kind => $field) foreach (m3d_items($kind, $fresh) as $it) {
        foreach ((array) ($it[$field] ?? []) as $g) {
            $src = img_large((string) (is_array($g) ? ($g['src'] ?? '') : $g));
            if ($src === '') continue;
            $map[$src][] = ['link' => is_array($g) && !empty($g['link']['url']) ? $g['link'] : null, 'dls' => m3d_downloads('pics', "$kind:{$it['slug']}:" . m3d_pic_code($src), $stats)['total']];
        }
    }
    foreach (m3d_gallery($fresh)['stills'] as $st) {
        $map[img_large((string) $st['src'])][] = ['link' => !empty($st['link']['url']) ? $st['link'] : null, 'dls' => m3d_downloads('stills', (string) $st['id'], $stats)['total']];
    }
    return $map;
}

/** The picture window's Full Size Download box (admin.js fullSizeBox()): null when no place can have one; else the link (the places' common
 *  one, `mixed` when they differ), how many places, the downloads, and whether the S4… picker is there. */
function full_size_state(string $url): ?array
{
    $places = full_size_places()[img_large($url)] ?? [];
    if (!$places) return null;
    $links = array_map(fn($p) => $p['link'] ? json_encode([$p['link']['url'], $p['link']['access'] ?? 'patrons', $p['link']['size'] ?? '']) : '', $places);
    $mixed = count(array_unique($links)) > 1;
    $first = null;
    foreach ($places as $p) if ($p['link']) { $first = $p['link']; break; }
    return ['link' => $first, 'mixed' => $mixed, 'n' => count($places), 'dls' => array_sum(array_column($places, 'dls')),
            's4' => m3d_s4()['bucket'] !== '' && m3d_s4_on()];
}

/** Sets (or, with no address, removes) the full size link of every place showing the picture $url (full_size_places()); the number of places, null when a save failed. */
function full_size_save(string $url, array $link): ?int
{
    $srcs = array_flip([img_large($url), ...img_versions(img_large($url))]);
    $n = 0;
    $set = function (array $entry) use ($link): array {
        unset($entry['link']);
        return $link ? $entry + ['link' => $link] : $entry;
    };
    foreach (PIC_FIELDS as $kind => $field) {
        $items = m3d_items($kind, true);   // fresh: an editor saved meanwhile stays
        $hit = 0;
        foreach ($items as &$it) {
            if (!is_array($it[$field] ?? null)) continue;
            foreach ($it[$field] as &$g) {
                $src = (string) (is_array($g) ? ($g['src'] ?? '') : $g);
                if (!isset($srcs[$src])) continue;
                $g = $set(is_array($g) ? $g : ['src' => $g]);
                if (array_keys($g) === ['src']) $g = $g['src'];   // nothing but the address: stored as a plain address, like the editor does
                $hit++;
            }
            unset($g);
        }
        unset($it);
        if ($hit && !m3d_save_items($kind, $items)) return null;
        $n += $hit;
    }
    $gal = m3d_gallery(true);
    $hit = 0;
    foreach ($gal['stills'] as &$st) if (isset($srcs[(string) $st['src']])) { $st = $set($st); $hit++; }
    unset($st);
    if ($hit && !m3d_save_gallery($gal)) return null;
    full_size_places(true);
    return $n + $hit;
}

// One picture's details for the picture window opened from the editors and Admin → Gallery (?info=/media/…)
if (isset($_GET['info'])) {
    $url = img_large(m3d_get_text('info'));
    $p = media_path($url);
    if (!$p || !is_file($p) || !preg_match('/\.(webp|jpe?g|png|gif)$/i', $url)) admin_json(['ok' => false, 'error' => 'This picture isn’t stored on this site.'], 404);
    $models = m3d_models();
    [, $names] = admin_folder_picks($models);
    $folder = basename(dirname($url));
    admin_json(['ok' => true] + image_info($url, usage_map($models), use_fields($models, m3d_outfits()), $names[$folder] ?? $folder));
}

// ── Page ─────────────────────────────────────────────────────────────────────
$models = m3d_models();
$items = media_library();
$todoSizes = missing_versions($items);
$use = usage_map($models);
foreach ($items as $i) {   // a use of a smaller version (a text can link the -sm file) counts for the picture
    $all = [];
    foreach ([$i['url'], ...$i['versions']] as $u) $all += $use[$u] ?? [];
    if ($all) $use[$i['url']] = $all;
}
$todoOpt = unoptimized($models);
$todoExtAll = external_images();
$extFailed = array_intersect(array_keys($todoExtAll), $_SESSION['ext_failed'] ?? []);
$todoExt = array_diff_key($todoExtAll, array_flip($extFailed));

// Tabs: each image goes where it is used (a model, an outfit, the Gallery, docs, FAQ…; in each of them when used in several), whatever folder it is stored in;
// unused images go by their folder (media/<model>, outfit-<slug>, docs, faq, misc…). 'group' = the model/outfit/docs page/
// FAQ question/folder it is listed under (the filter menu of the tab); 'groups' = every one of the tab's it is used by.
// 'videos' = the videos of texts (app/video.php, with their covers), '3d' = the 3D preview files (.glb, `model3d`): listed only there, so
// each of these tabs shows only when there is one.
const IMG_TABS = ['models' => 'Models', 'outfits' => 'Accessories', 'gallery' => 'Gallery', 'blog' => 'Blog', 'docs' => 'Docs', 'faq' => 'FAQ', 'other' => 'Other', 'videos' => 'Videos', '3d' => '3D Files'];
$modelSlugs = array_flip(array_column($models, 'slug'));
$folderSection = fn(string $folder): string => match (true) {
    $folder === 'docs' => 'docs',
    $folder === 'gallery' => 'gallery',
    $folder === 'blog' => 'blog',
    $folder === 'faq' => 'faq',
    str_starts_with($folder, 'outfit-') => 'outfits',
    isset($modelSlugs[$folder]) => 'models',
    default => 'other',
};
// a use ('models:<slug>', 'outfits:<slug>', 'docs:<slug>', 'faq:<n>' / 'faq:intro', 'blog:<id>', 'other:outfits-page' / 'other:welcome') → the group it is listed under
$useGroup = fn(string $key): string => match (explode(':', $key)[0]) { 'models' => explode(':', $key, 2)[1], 'outfits' => 'outfit-' . explode(':', $key, 2)[1], 'gallery' => 'gallery', 'other' => 'misc', default => $key };
// A picture used in several kinds of places (a model's gallery and the Gallery) is listed in each of their tabs
$listed = [];
foreach ($items as $i) {
    $uses = array_keys(($use[$i['url']] ?? []) + ($use[$i['small']] ?? []));
    $bySection = [];
    foreach ($uses as $k) $bySection[explode(':', $k)[0]][] = $k;
    if (!$bySection) {   // unused: by its folder
        $listed[] = $i + ['section' => $folderSection($i['folder']), 'group' => $i['folder'], 'groups' => [$i['folder']]];
        continue;
    }
    foreach ($bySection as $section => $keys) {
        $groups = array_values(array_unique(array_map($useGroup, $keys)));
        $listed[] = $i + ['section' => $section, 'group' => $groups[0], 'groups' => $groups];
    }
}
$items = $listed;
usort($items, fn($a, $b) => [$a['group'], $b['time']] <=> [$b['group'], $a['time']]);
$tabCount = $tabUnused = array_fill_keys(array_keys(IMG_TABS), 0);   // every image of each tab, and the unused ones (what the tabs show while Unused Only is on)
foreach ($items as $i) { $tabCount[$i['section']]++; if (empty($use[$i['url']])) $tabUnused[$i['section']]++; }
$glbs = media_3d_files();
$tabCount['3d'] = count($glbs);
$tabUnused['3d'] = count(array_filter($glbs, fn($g) => empty($use[$g['url']])));
$vids = media_videos();
$tabCount['videos'] = count($vids);
$tabUnused['videos'] = count(array_filter($vids, fn($v) => empty($use[$v['url']])));
$onlyUnused = m3d_get_text('unused') !== '';
$tab = isset(IMG_TABS[m3d_get_text('tab')]) ? m3d_get_text('tab') : 'models';
if ($onlyUnused && !isset(IMG_TABS[m3d_get_text('tab')]) && !$tabUnused['models']) $tab = array_key_first(array_filter($tabUnused)) ?? 'models';   // ?unused=1 alone (Settings → Tools): the first tab with unused ones
$items = array_values(array_filter($items, fn($i) => $i['section'] === $tab));
if ($tab === '3d' || $tab === 'videos') {   // listed under the item / post using it, else its folder; a 3D file's facts read from its header (m3d_glb_info())
    $items = [];
    foreach ($tab === '3d' ? $glbs : $vids as $g) {
        $uses = array_keys($use[$g['url']] ?? []);
        $g['group'] = $uses ? $useGroup($uses[0]) : $g['folder'];
        $g['groups'] = array_values(array_unique([$g['group'], ...array_map($useGroup, $uses)]));
        if ($tab === '3d') try { $g['info'] = m3d_glb_info(M3D_SITE . $g['url']); } catch (Throwable) { $g['info'] = null; }
        $items[] = $g;
    }
    usort($items, fn($a, $b) => [$a['group'], $b['time']] <=> [$b['group'], $a['time']]);
}
$folders = array_values(array_unique(array_merge(...array_column($items, 'groups') ?: [[]])));
$uploadDefault = ['gallery' => 'gallery', 'blog' => 'blog', 'docs' => 'docs', 'faq' => 'faq', 'other' => 'misc'][$tab] ?? null;
$whatName = ['models' => 'model', 'outfits' => 'accessory', 'blog' => 'post', 'docs' => 'page', 'faq' => 'question', '3d' => 'item', 'videos' => 'post or page'][$tab] ?? 'folder';
$fileTab = $tab === '3d' || $tab === 'videos';   // files, not pictures: no upload button here, no picture window
// what the folder pickers show for each folder (admin.js turns the two menus into a search picker: one row per character, its generations as buttons)
[$pick, $names] = admin_folder_picks($models);
$order = array_flip(array_keys($pick));   // the filter menu in the site's own order (models, accessories, docs pages, FAQ questions), folders last
usort($folders, fn($a, $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
$unusedAll = unused_images($use);
$unused3d = unused_3d($use);
$unusedVid = unused_videos($use);
$unused = count($unusedAll) + count($unused3d) + count($unusedVid);
$unusedWhat = unused_words(count($unusedAll), count($unused3d), count($unusedVid));
$unusedBytes = array_sum(array_column($unusedAll, 'bytes')) + array_sum(array_column($unused3d, 'bytes')) + array_sum(array_column($unusedVid, 'bytes'));

// what the picture window (admin.js) shows for each picture of the tab: sizes made, date, which part of an item uses it
$fields = use_fields($models, m3d_outfits());
$info = [];
if (!$fileTab) foreach ($items as $i) $info[$i['url']] = image_info($i['url'], $use, $fields, $names[$i['group']] ?? $i['folder']);

admin_start('Media', 'images');
?>
    <div class="admin-head">
      <div>
        <h1>Media</h1>
      </div>
      <div class="head-actions" <?= $fileTab ? 'hidden' : '' ?>>
        <select class="input" data-upload-folder data-folder-pick="upload" aria-label="Upload into">
          <optgroup label="Models"><?php foreach ($models as $m) echo folder_option($m['slug'], $pick, $names) ?></optgroup>
          <?php if (m3d_outfits()): ?><optgroup label="Accessories"><?php foreach (m3d_outfits() as $k => $o) echo folder_option('outfit-' . $o['slug'], $pick, $names, $tab === 'outfits' && $k === 0) ?></optgroup><?php endif ?>
          <optgroup label="Other"><?php foreach (array_merge(['gallery'], m3d_blog_on() || m3d_blog()['posts'] ? ['blog'] : [], ['docs', 'faq', 'misc']) as $f) echo folder_option($f, $pick, $names, $uploadDefault === $f) ?></optgroup>
        </select>
        <label class="btn btn-primary">Upload Images<input type="file" accept="image/*" multiple hidden data-upload-files /></label>
      </div>
    </div>

    <?php if (isset($purged)) admin_flash($purged[0] || $purged[2] || $purged[3] ? '✓ Deleted ' . unused_words($purged[0], $purged[2], $purged[3]) . ' that weren’t used, ' . m3d_size($purged[1]) . ' freed.' : 'There were no unused files to delete.', $purged[0] || $purged[2] || $purged[3] ? 'ok' : 'info') ?>
    <?php if ($unused): ?>
      <form method="post" class="notice opt-notice" onsubmit="return confirm(<?= e(json_encode('Delete ' . $unusedWhat . ' that aren’t used (' . m3d_size($unusedBytes) . ')? No model, accessory, FAQ answer, docs page, blog post or gallery picture uses them. This can’t be undone.')) ?>)">
        <span><b><?= e($unusedWhat) ?></b> <?= $unused === 1 ? 'isn’t' : 'aren’t' ?> used anywhere (<?= m3d_size($unusedBytes) ?>). Turn on “Unused Only” to review them first.</span>
        <?= form_keys('purge') ?>
        <button class="btn btn-ghost btn-sm del-btn">Delete All Unused</button>
      </form>
    <?php endif ?>
    <?php if ($results) { $ok = count(array_filter($results, fn($r) => $r[0])); $bad = count($results) - $ok;   // any failure: the message stays until closed
      admin_flash('<b>' . ($bad ? '' : '✓ ') . $ok . ' ' . (['external' => 'copied', 'sizes' => 'resized'][$ran ?? ''] ?? 'optimized') . '</b>' . ($bad ? ' · <b>' . $bad . ' failed</b>: ' . e(implode('; ', array_map(fn($r) => basename($r[1]) . ' (' . $r[2] . ')', array_filter($results, fn($r) => !$r[0])))) : ''), $bad ? 'err' : 'ok'); } ?>
    <?php if ($todoExt): ?>
      <form method="post" class="notice opt-notice">
        <span><b><?= count($todoExt) ?></b> images are loaded from other websites. Copy them here to keep visitors' data private.</span>
        <?= form_keys('external') ?>
        <button class="btn btn-ghost btn-sm">Copy <?= min(15, count($todoExt)) ?> Now</button>
      </form>
    <?php elseif ($extFailed): ?>
      <form method="post" class="notice opt-notice">
        <span><b><?= count($extFailed) ?></b> outside images couldn't be copied. Try again, or replace them in the editor.</span>
        <?= form_keys('external') ?><input type="hidden" name="retry" value="1" />
        <button class="btn btn-ghost btn-sm">Try Again</button>
      </form>
    <?php endif ?>
    <?php if ($todoSizes): ?>
      <form method="post" class="notice opt-notice" data-busy="Making Them…">
        <span><b><?= count($todoSizes) ?></b> <?= count($todoSizes) === 1 ? 'picture doesn’t' : 'pictures don’t' ?> have the lighter sizes for phones and catalog cards yet.<?= tip('Made once from each picture; new uploads get them by themselves. The pictures and their addresses stay the same. Each click works for about 40 seconds; click again until this box is gone.') ?></span>
        <?= form_keys('sizes') ?>
        <button class="btn btn-ghost btn-sm">Make Them Now</button>
      </form>
    <?php endif ?>
    <?php if ($todoOpt): ?>
      <form method="post" class="notice opt-notice">
        <span><b><?= count($todoOpt) ?></b> images used by models aren't WebP yet.</span>
        <?= form_keys('optimize') ?>
        <button class="btn btn-ghost btn-sm">Optimize <?= min(BATCH_OPT, count($todoOpt)) ?> Now</button>
      </form>
    <?php endif ?>

    <nav class="lib-tabs" aria-label="Sections">
      <?php foreach (IMG_TABS as $k => $label): if ($k !== 'models' && !$tabCount[$k] && $tab !== $k) continue; ?>
        <a href="/admin/images.php?tab=<?= $k ?><?= $onlyUnused ? '&amp;unused=1' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?> data-lib-tab><?= e($label) ?> <span data-all="<?= $tabCount[$k] ?>" data-unused="<?= $tabUnused[$k] ?>"><?= $onlyUnused ? $tabUnused[$k] : $tabCount[$k] ?></span></a>
      <?php endforeach ?>
    </nav>

    <?php if (!$items): ?>
      <?php if ($tab === '3d'): ?><p class="muted">No 3D files yet. Upload one in a model or accessory editor (Images → 3D Preview).</p>
      <?php elseif ($tab === 'videos'): ?><p class="muted">No videos yet. Add one to a blog post or a docs page with the editor’s Video button.</p>
      <?php else: ?><p class="muted">No <?= e(strtolower(IMG_TABS[$tab])) ?> images yet. Upload some with the button above<?= ['models' => ', or from the editor', 'outfits' => ', or from the editor', 'gallery' => ', or with Add Pictures in Admin → Gallery', 'blog' => ', or from the Blog editor', 'docs' => ', or with Image in the Docs editor', 'faq' => ', or with Add Image in the FAQ editor'][$tab] ?? '' ?>.</p><?php endif ?>
    <?php else: ?>
      <div class="lib-filters">
        <input class="input" type="search" placeholder="Search <?= $tab === 'videos' ? 'file name, post or page' : 'file name or ' . $whatName ?>…" data-lib-search aria-label="Search <?= ['3d' => '3D files', 'videos' => 'videos'][$tab] ?? 'images' ?>" />
        <select class="input" data-lib-folder data-folder-pick="filter" data-search-ph="Search <?= ['outfits' => 'accessories', 'blog' => 'posts', 'docs' => 'pages', 'faq' => 'questions', 'models' => 'models', '3d' => 'items', 'videos' => 'posts and pages'][$tab] ?? 'folders' ?>…" aria-label="<?= e(ucfirst($whatName)) ?>" <?= count($folders) < 2 ? 'hidden' : '' ?>>
          <option value=""><?= ['models' => 'All Models', 'outfits' => 'All Accessories', 'blog' => 'All Posts', 'docs' => 'All Pages', 'faq' => 'All Questions', '3d' => 'All Items', 'videos' => 'All Posts and Pages'][$tab] ?? 'All Folders' ?></option>
          <?php foreach ($folders as $f) echo folder_option($f, $pick, $names) ?>
        </select>
        <label class="toggle lib-unused"><input type="checkbox" class="switch" data-lib-unused <?= $onlyUnused ? 'checked' : '' ?> /><span>Unused Only</span></label>
      </div>

      <div class="lib" data-lib>
        <?php foreach ($items as $i): $u = $use[$i['url']] ?? []; ?>
          <?php if ($tab === '3d'): $g = $i['info']; ?>
          <figure class="lib-card lib-3d" data-url="<?= e($i['url']) ?>" data-folder="<?= e(implode(' ', $i['groups'])) ?>" data-name="<?= e(mb_strtolower($i['name'] . ' ' . implode(' ', $u ?: [$names[$i['group']] ?? ''])) ) ?>" data-used="<?= $u ? '1' : '0' ?>">
            <button type="button" class="lib-img m3d-prev" data-v3d aria-label="Preview in 3D" title="Preview in 3D"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.3L18.7 8 12 11.7 5.3 8 12 4.3ZM5 9.7l6 3.3v6.6l-6-3.3V9.7Zm8 9.9V13l6-3.3v6.6l-6 3.3Z"/></svg></button>
            <figcaption>
              <span class="lib-name" title="<?= e($i['name']) ?>"><?= e($i['name']) ?></span>
              <span class="muted small lib-meta"><?= m3d_size($i['bytes']) ?><?php if ($g): ?> · <?= $g['tris'] >= 1000 ? number_format($g['tris'] / 1000) . 'k' : $g['tris'] ?> triangles<?php if (!$g['light']): ?> · <span class="m3d-warn" title="<?= e('May be slow on phones: ' . implode(', ', $g['heavy'])) ?>">Heavy</span><?php endif ?><?php else: ?> · <span class="m3d-bad">Can’t Be Read</span><?php endif ?></span>
              <span class="small lib-use" title="<?= e($u ? 'Used by ' . implode(', ', $u) : 'Not used · folder: ' . ($names[$i['group']] ?? $i['group'])) ?>">
                <?php if ($u): ?>Used by <?= implode(', ', array_map(fn($key, $n) => '<a href="' . e(use_href($key)) . '">' . e($n) . '</a>', array_keys($u), $u)) ?>
                <?php else: ?><span class="warn-dot">Not Used</span> <span class="muted">· <?= e($names[$i['group']] ?? $i['group']) ?></span><?php endif ?>
              </span>
              <span class="row-btns">
                <button type="button" class="btn btn-ghost btn-sm" data-v3d title="Preview in 3D">View</button>
                <button type="button" class="btn btn-ghost btn-sm" data-copy title="Copy the file address">Copy<span class="lib-t"> Link</span></button>
                <button type="button" class="icon-btn del" data-del title="<?= $u ? 'In use: remove it there first' : 'Delete' ?>" aria-label="Delete" <?= $u ? 'disabled' : '' ?>>✕</button>
              </span>
            </figcaption>
          </figure>
          <?php continue; endif ?>
          <?php if ($tab === 'videos'): $vi = $i['info']; $vok = !empty($vi['ok']); ?>
          <figure class="lib-card lib-video" data-url="<?= e($i['url']) ?>" data-cover="<?= e($i['cover']) ?>" data-folder="<?= e(implode(' ', $i['groups'])) ?>" data-name="<?= e(mb_strtolower($i['name'] . ' ' . implode(' ', $u ?: [$names[$i['group']] ?? ''])) ) ?>" data-used="<?= $u ? '1' : '0' ?>">
            <a class="lib-img" href="<?= e($i['url']) ?>" target="_blank" title="Play in a new tab">
              <?php if ($i['cover'] !== ''): $cv = img_small($i['cover']) . '?v=' . (int) @filemtime((string) media_path(img_small($i['cover']))); ?><img class="lib-bg" src="<?= e($cv) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" /><img src="<?= e($cv) ?>" alt="" loading="lazy" decoding="async" /><?php endif ?>
              <span class="lib-play" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 4.5v15l13-7.5z"/></svg></span>
            </a>
            <figcaption>
              <span class="lib-name" title="<?= e($i['name']) ?>"><?= e($i['name']) ?></span>
              <span class="muted small lib-meta"><?php if ($vok): ?><?= $vi['w'] ?>×<?= $vi['h'] ?> · <?= m3d_duration((float) $vi['dur']) ?> · <?php endif ?><?= m3d_size($i['bytes']) ?><?php if (!$vok): ?> · <span class="m3d-bad">Can’t Be Read</span><?php elseif (!$vi['plays']): ?> · <span class="m3d-warn" title="<?= e($vi['codec'] . ': doesn’t play in every browser') ?>"><?= e($vi['codec']) ?></span><?php endif ?></span>
              <span class="small lib-use" title="<?= e($u ? 'Used by ' . implode(', ', $u) : 'Not used · folder: ' . ($names[$i['group']] ?? $i['group'])) ?>">
                <?php if ($u): ?>Used by <?= implode(', ', array_map(fn($key, $n) => '<a href="' . e(use_href($key)) . '">' . e($n) . '</a>', array_keys($u), $u)) ?>
                <?php else: ?><span class="warn-dot">Not Used</span> <span class="muted">· <?= e($names[$i['group']] ?? $i['group']) ?></span><?php endif ?>
              </span>
              <span class="row-btns">
                <button type="button" class="btn btn-ghost btn-sm" data-video-cover title="Choose a frame or a picture shown before it plays">Cover</button>
                <button type="button" class="btn btn-ghost btn-sm" data-copy title="Copy the file address">Copy<span class="lib-t"> Link</span></button>
                <button type="button" class="icon-btn del" data-del title="<?= $u ? 'In use: remove it there first' : 'Delete' ?>" aria-label="Delete" <?= $u ? 'disabled' : '' ?>>✕</button>
              </span>
            </figcaption>
          </figure>
          <?php continue; endif ?>
          <figure class="lib-card" data-url="<?= e($i['url']) ?>" data-folder="<?= e(implode(' ', $i['groups'])) ?>" data-name="<?= e(mb_strtolower($i['name'] . ' ' . implode(' ', $u ?: [$names[$i['group']] ?? ''])) ) ?>" data-used="<?= $u ? '1' : '0' ?>">
            <a class="lib-img" href="<?= e($i['url']) ?>" target="_blank" data-open title="Open"><?php if (!$i['w'] || !$i['h'] || abs($i['w'] / $i['h'] - 9 / 16) > 0.02): ?><img class="lib-bg" src="<?= e($i['small']) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" /><?php endif ?><img src="<?= e($i['small']) ?>" alt="" loading="lazy" decoding="async" /></a>
            <figcaption>
              <span class="lib-name" title="<?= e($i['name']) ?>"><?= e($i['name']) ?></span>
              <!-- same rows on every card: name, size, where it's used (one line each, full text on hover), buttons at the bottom -->
              <span class="muted small lib-meta"><?= $i['w'] ?>×<?= $i['h'] ?> · <?= m3d_size($i['bytes']) ?></span>
              <span class="small lib-use" title="<?= e($u ? 'Used by ' . implode(', ', $u) : 'Not used · folder: ' . ($names[$i['group']] ?? $i['group'])) ?>">
                <?php if ($u): ?>Used by <?= implode(', ', array_map(fn($key, $n) => '<a href="' . e(use_href($key)) . '">' . e($n) . '</a>', array_keys($u), $u)) ?>
                <?php else: ?><span class="warn-dot">Not Used</span> <span class="muted">· <?= e($names[$i['group']] ?? $i['group']) ?></span><?php endif ?>
              </span>
              <span class="row-btns">
                <button type="button" class="btn btn-ghost btn-sm" data-copy title="Copy the image address">Copy<span class="lib-t"> Link</span></button>
                <?php if (!str_ends_with(strtolower($i['name']), '.gif')): ?><button type="button" class="btn btn-ghost btn-sm" data-crop data-used-count="<?= count($u) ?>">Crop</button><?php endif ?>
                <button type="button" class="icon-btn del" data-del title="<?= $u ? 'In use: remove it there first' : 'Delete' ?>" aria-label="Delete" <?= $u ? 'disabled' : '' ?>>✕</button>
              </span>
            </figcaption>
          </figure>
        <?php endforeach ?>
      </div>
      <script type="application/json" id="lib-info"><?= json_encode($info, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
      <nav class="pager" data-lib-pager aria-label="Pages" hidden></nav>
      <p class="muted small empty-lib" data-lib-empty hidden>No <?= ['3d' => 'files', 'videos' => 'videos'][$tab] ?? 'images' ?> match.</p>
    <?php endif ?>
<?php admin_end();
