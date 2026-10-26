<?php
// Media → Find Duplicates (admin/duplicates.php): pictures stored twice. "Same file" = the same bytes (the large file's md5);
// "looks the same" = the same picture resized or saved again (a 32 × 32 colour copy of each picture, compared pixel by pixel);
// and Gallery pictures added twice (two stills with the same file). Each picture's print is worked out once (img_cache(), 'dupe'),
// keyed by its file and modified time, so only new pictures are read again. Pi's "Not Duplicates" marks are kept in
// settings.json → dupes_ok (codes of picture pairs, dupes_pair()).
declare(strict_types=1);
if (!defined('M3D_PRIVATE')) { http_response_code(404); exit; }

const DUPES_SIDE = 32;        // the colour copy compared: 32 × 32 pixels
const DUPES_RATIO = 0.02;     // shapes further apart than 2 % are never the same picture
const DUPES_MEAN = 6;         // average colour further apart than this (0–255, per channel): not the same picture
const DUPES_MAD = 2.5;        // average difference per channel allowed (resizing and JPEG saving give about 0.5–1.5)
const DUPES_BIG = 2;          // pixels allowed to differ a lot (> DUPES_BIG_DIFF): a moved shape or a changed colour gives many more
const DUPES_BIG_DIFF = 24;
const DUPES_OK_MAX = 2000;    // "Not Duplicates" marks kept

/** The print of a picture (its large file's address): [md5, w, h, mean [r, g, b], px (base64 of 32 × 32 RGB)], null when it can't be read. */
function dupes_print(string $url): ?array
{
    $p = media_path($url);
    if (!$p || !is_file($p)) return null;
    return img_cache('dupe', $p, function () use ($url, $p) {
        $src = $p;   // the lightest version is enough for a 32 px copy
        foreach (['xs', 'sm'] as $suf) if (($v = img_variant($url, $suf)) !== '' && ($vp = media_path($v)) && is_file($vp)) { $src = $vp; break; }
        @ini_set('memory_limit', '512M');
        $im = img_load($src);
        if (!$im) return null;
        $n = DUPES_SIDE;
        $s = imagecreatetruecolor($n, $n);
        imagecopyresampled($s, $im, 0, 0, 0, 0, $n, $n, imagesx($im), imagesy($im));
        $dim = img_dims($url) ?: [imagesx($im), imagesy($im)];
        unset($im);
        $px = '';
        $sum = [0, 0, 0];
        for ($y = 0; $y < $n; $y++) for ($x = 0; $x < $n; $x++) {
            $c = imagecolorat($s, $x, $y);
            $rgb = [($c >> 16) & 255, ($c >> 8) & 255, $c & 255];
            $px .= chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]);
            foreach ($rgb as $k => $v) $sum[$k] += $v;
        }
        return ['md5' => (string) md5_file($p), 'w' => (int) $dim[0], 'h' => (int) $dim[1],
                'mean' => array_map(fn($v) => round($v / ($n * $n), 1), $sum), 'px' => base64_encode($px)];
    });
}

/** The pictures of the Media library (media_library(): not the site's logo, bug report or patron render files) whose print isn't worked out yet. */
function dupes_todo(array $lib): array
{
    return array_values(array_filter(array_column($lib, 'url'), fn($u) => !is_file(img_cache_file('dupe', (string) media_path($u)))));
}

/** Works out prints for up to $secs seconds (a share at a time: shared hosting stops long requests): [done, total]. */
function dupes_scan(int $secs = 8): array
{
    $lib = media_library();
    $todo = dupes_todo($lib);
    $start = microtime(true);
    foreach ($todo as $k => $u) {
        if (microtime(true) - $start > $secs) break;
        try { dupes_print($u); } catch (Throwable $ex) {
            m3d_problem('Find duplicates', basename($u) . ': ' . $ex->getMessage());
            img_cache('dupe', (string) media_path($u), fn() => null);   // unreadable: counted as read (left out of the groups), never retried in a loop
        }
        unset($todo[$k]);
        if (!is_file(img_cache_file('dupe', (string) media_path($u)))) $todo[$k] = $u;   // couldn't be stored: counted as left, the loop goes on
    }
    $left = count(array_filter($todo, fn($u) => !is_file(img_cache_file('dupe', (string) media_path($u)))));
    return [count($lib) - $left, count($lib)];
}

/** The code of a pair of pictures (or of Gallery pictures: "still:<id>") marked "Not Duplicates". */
function dupes_pair(string $a, string $b): string
{
    $x = [$a, $b];
    sort($x);
    return substr(md5(implode('|', $x)), 0, 12);
}

function dupes_ok(): array
{
    return array_flip(array_map('strval', (array) (m3d_settings()['dupes_ok'] ?? [])));
}

/**
 * What the comparison reads of a print, worked out once per picture: the shape, the average colour, the brightness of a 4 × 4 grid
 * (a quick test that drops nearly every pair) and the pixels.
 */
function dupes_prep(array $f): array
{
    $raw = (string) base64_decode((string) $f['px']);
    $grid = array_fill(0, 16, 0.0);
    $n = DUPES_SIDE;
    $len = strlen($raw);
    for ($i = 0, $p = 0; $i < $len; $i += 3, $p++) {
        $x = $p % $n; $y = intdiv($p, $n);
        $grid[intdiv($y * 4, $n) * 4 + intdiv($x * 4, $n)] += ord($raw[$i]) + ord($raw[$i + 1]) + ord($raw[$i + 2]);
    }
    $cell = 3 * ($n / 4) ** 2;
    return ['md5' => $f['md5'], 'ratio' => $f['h'] ? $f['w'] / $f['h'] : 0, 'mean' => $f['mean'], 'grid' => array_map(fn($v) => $v / $cell, $grid), 'raw' => $raw];
}

/** Two prepared prints (dupes_prep()) show the same picture (see the constants). */
function dupes_alike(array $a, array $b): bool
{
    if ($a['md5'] === $b['md5']) return true;
    if (!$a['ratio'] || !$b['ratio'] || abs($a['ratio'] - $b['ratio']) / max($a['ratio'], $b['ratio']) > DUPES_RATIO) return false;
    for ($k = 0; $k < 3; $k++) if (abs($a['mean'][$k] - $b['mean'][$k]) > DUPES_MEAN) return false;
    for ($k = 0; $k < 16; $k++) if (abs($a['grid'][$k] - $b['grid'][$k]) > DUPES_MEAN) return false;
    $pa = $a['raw']; $pb = $b['raw'];
    $len = strlen($pa);
    if ($len !== strlen($pb) || !$len) return false;
    $sum = 0; $big = 0;
    for ($i = 0; $i < $len; $i += 3) {
        $r = abs(ord($pa[$i]) - ord($pb[$i])); $g = abs(ord($pa[$i + 1]) - ord($pb[$i + 1])); $b2 = abs(ord($pa[$i + 2]) - ord($pb[$i + 2]));
        if (($r > DUPES_BIG_DIFF || $g > DUPES_BIG_DIFF || $b2 > DUPES_BIG_DIFF) && ++$big > DUPES_BIG) return false;
        $sum += $r + $g + $b2;
    }
    return $sum / $len <= DUPES_MAD;
}

/**
 * Every pair of pictures (addresses) that look the same but aren't the same file. Worked out again only when a picture changed
 * (cache/dupes-pairs.json, stamped with every picture's address and print), as it compares many pairs: sorted by brightness, each
 * picture is compared only with the ones close enough in colour (DUPES_MEAN).
 */
function dupes_alike_pairs(array $pics): array
{
    $stamp = md5(implode('|', array_map(fn($p) => $p['url'] . ':' . $p['print']['md5'] . ':' . $p['time'], $pics)) . '|' . DUPES_SIDE . DUPES_MAD . DUPES_BIG);
    $file = M3D_PRIVATE . '/cache/dupes-pairs.json';
    $c = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($c) && ($c['stamp'] ?? '') === $stamp && is_array($c['pairs'] ?? null)) return $c['pairs'];
    @set_time_limit(120);
    $lum = fn($p) => ($p['print']['mean'][0] + $p['print']['mean'][1] + $p['print']['mean'][2]) / 3;
    usort($pics, fn($a, $b) => $lum($a) <=> $lum($b));
    $prep = array_map(fn($p) => dupes_prep($p['print']), $pics);
    $l = array_map($lum, $pics);
    $n = count($pics);
    $pairs = [];
    for ($i = 0; $i < $n; $i++) for ($j = $i + 1; $j < $n && $l[$j] - $l[$i] <= DUPES_MEAN; $j++) {
        if ($prep[$i]['md5'] !== $prep[$j]['md5'] && dupes_alike($prep[$i], $prep[$j])) $pairs[] = [$pics[$i]['url'], $pics[$j]['url']];
    }
    if (is_dir(dirname($file)) || @mkdir(dirname($file), 0700, true)) m3d_atomic_write($file, (string) json_encode(['stamp' => $stamp, 'pairs' => $pairs], JSON_UNESCAPED_SLASHES));
    return $pairs;
}

/**
 * The groups found: [{kind: same | look | gallery, pics: [media_library() rows (+ print)]} | for 'gallery': {src, stills: [still…]}],
 * pictures stored twice first, then Gallery pictures added twice. Only pictures whose print is known take part (dupes_scan() first).
 */
function dupes_groups(?array $lib = null): array
{
    $ok = dupes_ok();
    $pics = [];
    foreach ($lib ?? media_library() as $i) {
        $f = is_file(img_cache_file('dupe', (string) media_path($i['url']))) ? dupes_print($i['url']) : null;
        if ($f) $pics[] = $i + ['print' => $f];
    }
    $n = count($pics);
    $parent = range(0, max(0, $n - 1));
    $find = function (int $i) use (&$parent, &$find): int { return $parent[$i] === $i ? $i : ($parent[$i] = $find($parent[$i])); };
    $at = array_flip(array_column($pics, 'url'));
    $link = function (string $a, string $b) use (&$parent, $find, $at, $ok) {
        if (!isset($at[$a], $at[$b]) || isset($ok[dupes_pair($a, $b)])) return;
        $parent[$find($at[$a])] = $find($at[$b]);
    };
    $byMd5 = [];
    foreach ($pics as $p) $byMd5[$p['print']['md5']][] = $p['url'];
    foreach ($byMd5 as $same) for ($k = 1; $k < count($same); $k++) for ($j = 0; $j < $k; $j++) $link($same[$j], $same[$k]);   // the same bytes
    foreach (dupes_alike_pairs($pics) as [$a, $b]) $link($a, $b);
    $sets = [];
    foreach ($pics as $i => $p) $sets[$find($i)][] = $p;
    $groups = [];
    foreach ($sets as $set) {
        if (count($set) < 2) continue;
        usort($set, fn($a, $b) => [$b['w'] * $b['h'], $a['time']] <=> [$a['w'] * $a['h'], $b['time']]);   // biggest first, then the oldest
        $groups[] = ['kind' => count(array_unique(array_map(fn($p) => $p['print']['md5'], $set))) === 1 ? 'same' : 'look', 'pics' => $set];
    }
    usort($groups, fn($a, $b) => [$a['kind'] !== 'same', -max(array_column($a['pics'], 'time'))] <=> [$b['kind'] !== 'same', -max(array_column($b['pics'], 'time'))]);
    // the Gallery: the same file twice (patron renders' copies are left out: one per render)
    $bySrc = [];
    foreach (m3d_gallery()['stills'] as $s) if (empty($s['from'])) $bySrc[$s['src']][] = $s;
    foreach ($bySrc as $src => $stills) {
        if (count($stills) < 2) continue;
        $keep = [$stills[0]];   // marked "Not Duplicates" with the first: left out
        foreach (array_slice($stills, 1) as $s) if (!isset($ok[dupes_pair('still:' . $stills[0]['id'], 'still:' . $s['id'])])) $keep[] = $s;
        if (count($keep) > 1) $groups[] = ['kind' => 'gallery', 'src' => (string) $src, 'stills' => $keep];
    }
    return $groups;
}

/** old address => new for every version of $drop (as a crop's "replace" does): a missing version of $keep becomes its closest one. */
function dupes_map(string $drop, string $keep): array
{
    $map = [$drop => $keep];
    foreach (['sm', 'md', 'xs'] as $suf) if (($old = img_variant($drop, $suf)) !== '') $map[$old] = img_variant($keep, $suf) ?: ($suf === 'md' ? $keep : img_small($keep));
    return $map;
}

/**
 * Gallery pictures showing the same file (after a merge, or added twice): the first one (the gallery's order) stays, with the
 * characters of the others added, their caption / full size link when it has none, and their likes and downloads; the others go.
 * $only = these still ids only (null = every file shown twice), $keepId = the one to keep (else the first). Returns how many were removed, null when the gallery couldn't be saved.
 */
function dupes_merge_stills(?array $only = null, string $keepId = ''): ?int
{
    $g = m3d_gallery(true);
    $first = [];   // src => index of the still kept: $keepId's, else the first one
    $gone = [];    // removed id => kept id
    foreach ($g['stills'] as $k => $s) if ($s['id'] === $keepId && empty($s['from'])) $first[$s['src']] = $k;
    foreach ($g['stills'] as $k => $s) {
        if (!empty($s['from']) || ($only !== null && !in_array($s['id'], $only, true)) || ($first[$s['src']] ?? null) === $k) continue;
        if (!isset($first[$s['src']])) { $first[$s['src']] = $k; continue; }
        $keep = &$g['stills'][$first[$s['src']]];
        $keep['chars'] = array_values(array_unique([...(array) ($keep['chars'] ?? []), ...(array) ($s['chars'] ?? [])]));
        foreach (['caption', 'link'] as $f) if (empty($keep[$f]) && !empty($s[$f])) $keep[$f] = $s[$f];
        unset($keep);
        $gone[$s['id']] = $g['stills'][$first[$s['src']]]['id'];
        unset($g['stills'][$k]);
    }
    if (!$gone) return 0;
    if (!m3d_save_gallery(['stills' => array_values($g['stills'])])) return null;
    m3d_json_update('likes', function ($d) use ($gone) {   // likes: one per patron on the kept picture
        if (!is_array($d) || !is_array($d['stills'] ?? null)) return [$d, null];
        foreach ($gone as $old => $new) {
            if (isset($d['stills'][$old])) $d['stills'][$new] = array_values(array_unique([...(array) ($d['stills'][$new] ?? []), ...(array) $d['stills'][$old]]));
            unset($d['stills'][$old]);
            if (isset($d['fresh']['stills:' . $old])) {
                $t = [...(array) ($d['fresh']['stills:' . $new] ?? []), ...(array) $d['fresh']['stills:' . $old]];
                sort($t);
                $d['fresh']['stills:' . $new] = $t;
                unset($d['fresh']['stills:' . $old]);
            }
        }
        return [$d, null];
    });
    m3d_json_update('stats', function ($d) use ($gone) {   // full size downloads (downloads.stills): added to the kept picture
        if (!is_array($d) || !is_array($d['downloads']['stills'] ?? null)) return [$d, null];
        foreach ($gone as $old => $new) if (isset($d['downloads']['stills'][$old])) {
            foreach ((array) $d['downloads']['stills'][$old] as $k => $c) $d['downloads']['stills'][$new][$k] = (int) ($d['downloads']['stills'][$new][$k] ?? 0) + (int) $c;
            unset($d['downloads']['stills'][$old]);
        }
        return [$d, null];
    });
    return count($gone);
}

/** A model's gallery / an accessory's pictures showing $src twice after a merge: the first stays (with a caption / full size link of the others when it has none). */
function dupes_merge_item_pics(string $src): bool
{
    $ok = true;
    foreach (PIC_FIELDS as $kind => $field) {
        $items = m3d_items($kind, true);
        $changed = false;
        foreach ($items as &$it) {
            $list = (array) ($it[$field] ?? []);
            $at = null; $out = []; $twice = false;
            foreach ($list as $g) {
                $s = is_array($g) ? (string) ($g['src'] ?? '') : (string) $g;
                if ($s !== $src) { $out[] = $g; continue; }
                if ($at === null) { $at = count($out); $out[] = $g; continue; }
                $kept = is_array($out[$at]) ? $out[$at] : ['src' => $out[$at]];
                foreach (['caption', 'link'] as $f) if (empty($kept[$f]) && is_array($g) && !empty($g[$f])) $kept[$f] = $g[$f];
                $out[$at] = count($kept) > 1 ? $kept : $kept['src'];
                $twice = true;
            }
            if ($twice) { $it[$field] = $out; $changed = true; }
        }
        unset($it);
        if ($changed) $ok = m3d_save_items($kind, $items) && $ok;
    }
    return $ok;
}

/** Whether an address is still used or written anywhere (read fresh: media_mentioned() keeps what it read first). */
function dupes_in_use(string $url): bool
{
    $use = usage_map(m3d_models());
    $all = (string) json_encode([m3d_models(), m3d_outfits(), m3d_faq(true), m3d_docs(true), m3d_blog(), m3d_gallery(true), m3d_settings(true)], JSON_UNESCAPED_SLASHES);
    foreach ([$url, ...img_versions($url)] as $u) if (!empty($use[$u]) || str_contains($all, $u)) return true;
    return false;
}

/**
 * Keeps $keep and points every use of $drop (and their versions) to it: models, accessories, texts, blog, gallery (media_replace()),
 * then a model's gallery / the Gallery showing it twice keep it once, and the files of $drop nothing uses any more are deleted.
 * [ok, places changed, files deleted, gallery pictures merged] or [false, error].
 */
function dupes_merge(string $keep, array $drop): array
{
    $use = usage_map(m3d_models());
    $map = [];
    $places = 0;
    foreach ($drop as $d) {
        $map += dupes_map($d, $keep);
        $u = [];
        foreach ([$d, ...img_versions($d)] as $v) $u += $use[$v] ?? [];
        $places += count($u);
    }
    if (!media_replace($map)) return [false, m3d_write_error('data')];
    $codes = [];   // full size downloads of a model's / accessory's picture are counted under its address's code: moved to the kept one
    foreach ($drop as $d) $codes[':' . m3d_pic_code($d)] = ':' . m3d_pic_code($keep);
    m3d_json_update('stats', function ($d) use ($codes) {
        if (!is_array($d) || !is_array($d['downloads']['pics'] ?? null)) return [$d, null];
        foreach ($d['downloads']['pics'] as $key => $c) {
            $tail = substr((string) $key, -9);
            if (!isset($codes[$tail])) continue;
            $to = substr((string) $key, 0, -9) . $codes[$tail];
            foreach ((array) $c as $k => $n) $d['downloads']['pics'][$to][$k] = (int) ($d['downloads']['pics'][$to][$k] ?? 0) + (int) $n;
            unset($d['downloads']['pics'][$key]);
        }
        return [$d, null];
    });
    $ok = dupes_merge_item_pics($keep);
    $stills = dupes_merge_stills(array_column(array_filter(m3d_gallery(true)['stills'], fn($s) => $s['src'] === $keep), 'id'));
    if (!$ok || $stills === null) return [false, 'The pictures were replaced, but a model’s gallery or the Gallery still shows one twice: ' . m3d_write_error('data')];
    $deleted = 0;
    foreach ($drop as $d) {
        if (dupes_in_use($d)) continue;   // written somewhere replace couldn't reach: left in place
        $gone = false;
        foreach ([$d, ...img_versions($d)] as $v) if (($f = media_deletable($v)) && @unlink($f)) $gone = true;
        if ($gone) $deleted++;
    }
    return [true, $places, $deleted, $stills];
}
