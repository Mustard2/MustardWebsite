<?php
// Image processing for uploads: every image is saved as WebP versions
//   <name>.webp      large   (longest side ≤ IMG_LARGE)   → full-screen gallery, big screens
//   <name>-md.webp   medium  (longest side ≤ IMG_MEDIUM)  → slideshow and main picture on phones and laptops (srcset)
//   <name>-sm.webp   small   (longest side ≤ IMG_SMALL)   → catalog cards, thumbnails
//   <name>-xs.webp   thumb   (longest side ≤ IMG_THUMB)   → catalog cards on most screens (srcset), compact cards
// -md / -xs only when the picture is bigger than them; pictures from before they existed get them from the Images page.
// Animated GIFs are kept as they are.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const IMG_LARGE = 2560;
const IMG_SMALL = 800;
const IMG_MEDIUM = 1800;
const IMG_THUMB = 560;
const IMG_QUALITY = 82;

function img_supported(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
}

function img_webp(): bool
{
    return function_exists('imagewebp') && !empty(gd_info()['WebP Support']);
}

/** Absolute file path for a /media/... address, or null if it isn't one of ours (never a hidden file like media/.htaccess). */
function media_path(string $url): ?string
{
    if (!preg_match('#^/media/[A-Za-z0-9/_.-]+$#D', $url) || str_contains($url, '..') || str_contains($url, '/.')) return null;
    return M3D_SITE . $url;
}

/** Small version of an image address when it exists (falls back to the address itself). */
function img_small(?string $url): string
{
    $url = (string) $url;
    $small = preg_replace('/\.(webp|jpe?g|png)$/i', '-sm.$1', $url);
    if ($small !== $url && ($p = media_path((string) $small)) && is_file($p)) return (string) $small;
    return $url;
}

/**
 * The large address of any version ("-sm", "-md", "-xs" file) of a picture, else the address itself. "-md" / "-xs"
 * count only when the large file is there, so an uploaded picture that happens to be called "…-md" stays a picture.
 */
function img_large(string $url): string
{
    if (!preg_match('/^(.*)-(sm|md|xs)(\.\w+)$/', $url, $m)) return $url;
    $large = $m[1] . $m[3];
    return $m[2] === 'sm' || (($p = media_path($large)) && is_file($p)) ? $large : $url;
}

/** The address of one version of a picture ('sm' | 'md' | 'xs'), '' when that file doesn't exist. */
function img_variant(string $url, string $suffix): string
{
    $large = img_large($url);
    $v = (string) preg_replace('/\.(webp|jpe?g|png)$/i', "-$suffix.\$1", $large);
    return $v !== $large && ($p = media_path($v)) && is_file($p) ? $v : '';
}

/** Every other version of a picture that exists (small, medium, thumb): deleted, counted and replaced together with it. */
function img_versions(string $url): array
{
    return array_values(array_filter(array_map(fn($s) => img_variant($url, $s), ['sm', 'md', 'xs'])));
}

/** Thumb version for compact cards when it exists, else the small one. */
function img_thumb(?string $url): string
{
    return img_variant((string) $url, 'xs') ?: img_small($url);
}

/**
 * Width and height of a picture in media/ ([w, h], null when unknown), kept in one file of the private cache (key: path +
 * modified time) so pages listing many pictures don't open each file on every visit.
 */
function img_dims(string $url): ?array
{
    static $cache = null, $dirty = false;
    $p = media_path($url);
    if (!$p || !is_file($p)) return null;
    $file = M3D_PRIVATE . '/cache/dims.json';
    if ($cache === null) {
        $cache = is_file($file) ? (json_decode((string) @file_get_contents($file), true) ?: []) : [];
        register_shutdown_function(function () use (&$cache, &$dirty, $file) {
            if (!$dirty || (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true))) return;
            m3d_atomic_write($file, (string) json_encode($cache, JSON_UNESCAPED_SLASHES));
        });
    }
    $t = (int) @filemtime($p);
    if (($c = $cache[$url] ?? null) && $c[0] === $t) return [$c[1], $c[2]];
    $sz = @getimagesize($p);
    if (!$sz) return null;
    $cache[$url] = [$t, (int) $sz[0], (int) $sz[1]];
    $dirty = true;
    return [(int) $sz[0], (int) $sz[1]];
}

/** ' style="aspect-ratio: w / h"' of a picture of media/ ('' when unknown): the gallery row gives it its width before it loads. */
function img_ratio_style(string $url): string
{
    $d = img_dims($url);
    return $d && $d[0] > 0 && $d[1] > 0 ? ' style="aspect-ratio: ' . $d[0] . ' / ' . $d[1] . '"' : '';
}

/** srcset value with the real widths of the given versions of a picture ('' = the large file); '' when fewer than two exist. */
function img_srcset(string $url, array $suffixes): string
{
    $parts = [];
    foreach ($suffixes as $s) {
        $u = $s === '' ? img_large($url) : img_variant($url, $s);
        if ($u !== '' && ($d = img_dims($u))) $parts[$d[0]] = $u . ' ' . $d[0] . 'w';
    }
    ksort($parts);
    return count($parts) > 1 ? implode(', ', $parts) : '';
}

function img_load(string $path)
{
    $info = @getimagesize($path);
    if (!$info) return null;
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        default        => false,
    };
    if (!$im) return null;
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    // Photos from phones/cameras can be stored sideways with a rotation flag
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int) (@exif_read_data($path)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    return $im;
}

function img_resized($im, int $max)
{
    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, $max / max($w, $h));
    if ($scale >= 1) return $im;
    $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
    $out = imagecreatetruecolor($nw, $nh);
    imagealphablending($out, false);
    imagesavealpha($out, true);
    imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return $out;
}

function img_save($im, string $pathNoExt): string
{
    imagesavealpha($im, true);
    if (img_webp()) { $p = "$pathNoExt.webp"; $ok = imagewebp($im, $p, IMG_QUALITY); }
    else { $p = "$pathNoExt.jpg"; $ok = imagejpeg($im, $p, IMG_QUALITY); }   // emergency fallback only
    if (!$ok) throw new RuntimeException('Could not save the image.');
    @chmod($p, 0644);
    return $p;
}

/** True for GIFs with more than one frame (kept untouched so they still move). */
function img_is_animated_gif(string $path): bool
{
    $d = @file_get_contents($path, false, null, 0, 2 * 1024 * 1024);
    return $d !== false && str_starts_with($d, 'GIF') && substr_count($d, "\x21\xF9\x04") > 1;
}

/** Whether the server can open a picture this size (GD keeps ~5 bytes a pixel, plus the resized copies); raises the memory limit when it may. */
function img_fits_memory(int $w, int $h): bool
{
    $need = (int) ($w * $h * 5) + 96 * 1024 * 1024;
    $bytes = function (): int {
        $v = trim((string) ini_get('memory_limit'));
        if ($v === '-1' || $v === '') return PHP_INT_MAX;
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    };
    if ($bytes() >= $need) return true;
    @ini_set('memory_limit', (string) (int) ceil($need / 1048576 + 64) . 'M');
    return $bytes() >= $need;
}

/**
 * Turns an image file into the large + small WebP versions in $dir, named after $base.
 * Returns the file name of the large version (the small one is <name>-sm.webp next to it).
 */
function img_make_versions(string $src, string $dir, string $base): string
{
    if (!img_supported()) throw new RuntimeException('Image processing is not available on this server.');
    @ini_set('memory_limit', '1024M');   // big renders need room while resizing
    $im = img_load($src);
    if (!$im) throw new RuntimeException('Could not read this image.');
    try {
        $large = img_resized($im, IMG_LARGE);
        $largePath = img_save($large, "$dir/$base");
        img_save_versions($large, "$dir/$base", ['md', 'sm', 'xs']);
        unset($large);
    } finally {
        unset($im);
    }
    return basename($largePath);
}

/**
 * Saves the medium / small / thumb versions of a large picture ($im) next to it ($pathNoExt = the large file without
 * extension). Small is always made (cards expect it); medium and thumb only when the picture is bigger than them.
 */
function img_save_versions($im, string $pathNoExt, array $which): void
{
    $long = max(imagesx($im), imagesy($im));
    $from = $im;   // each one from the one before (bigger first): quicker, same look
    foreach (['md' => IMG_MEDIUM, 'sm' => IMG_SMALL, 'xs' => IMG_THUMB] as $suf => $max) {
        if ($suf !== 'sm' && $long <= $max) continue;
        $v = img_resized($from, $max);
        if (in_array($suf, $which, true)) img_save($v, "$pathNoExt-$suf");
        $from = $v;
    }
}

/** The versions ('md', 'xs') a picture in media/ should have but doesn't (pictures from before they existed). */
function img_missing_versions(string $url, ?array $dims = null): array
{
    $p = media_path($url);
    if (!$p || !preg_match('/\.(webp|jpe?g|png)$/i', $url) || img_large($url) !== $url || img_variant($url, 'sm') === '') return [];
    $d = $dims ?? img_dims($url);
    if (!$d) return [];
    $long = max($d[0], $d[1]);
    $out = [];
    if ($long > IMG_MEDIUM && img_variant($url, 'md') === '') $out[] = 'md';
    if ($long > IMG_THUMB && img_variant($url, 'xs') === '') $out[] = 'xs';
    return $out;
}

/** Makes the missing versions of a picture from its large file (the large and small files stay as they are). */
function img_make_missing(string $url): void
{
    $missing = img_missing_versions($url);
    if (!$missing) return;
    if (!img_supported()) throw new RuntimeException('Image processing is not available on this server.');
    @ini_set('memory_limit', '1024M');
    $p = (string) media_path($url);
    $im = img_load($p);
    if (!$im) throw new RuntimeException('Could not read this image.');
    try { img_save_versions($im, dirname($p) . '/' . pathinfo($p, PATHINFO_FILENAME), $missing); }
    finally { unset($im); }
}

const IMG_BLUR = 24;   // longest side of the blurred copies (blurred / locked items)
const IMG_AVATAR = 96;   // longest side of the avatar copies (avatar_src(): sharp up to ~44 px circles on 2× screens)

/**
 * Results worked out from an image (glow colours, blurred copies) kept in the private folder's cache/, so pages don't decode
 * pictures on every visit. The key includes the file's modified time: a replaced picture gets a new entry.
 */
function img_cache(string $what, string $path, callable $make): mixed
{
    $dir = M3D_PRIVATE . '/cache';
    img_cache_cleanup($dir);
    $file = $dir . '/' . $what . '-' . md5($path . '|' . (int) @filemtime($path)) . '.json';
    if (is_file($file) && is_array($hit = json_decode((string) @file_get_contents($file), true)) && array_key_exists('v', $hit)) {
        if ((int) @filemtime($file) < time() - 7 * 86400) @touch($file);   // still in use: the clean-up keeps it (once a week at most)
        return $hit['v'];
    }
    $v = $make();
    if (is_dir($dir) || @mkdir($dir, 0700, true)) m3d_atomic_write($file, (string) json_encode(['v' => $v]));
    return $v;
}

/**
 * Once a day: deletes cache entries not used for 60 days (img_cache() refreshes the date of the ones it reads, weekly):
 * those of replaced or deleted pictures, which are never read again.
 */
function img_cache_cleanup(string $dir): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $mark = $dir . '/.cleaned';
    if (!is_dir($dir) || (is_file($mark) && filemtime($mark) > time() - 86400)) return;
    @touch($mark);
    $old = time() - 60 * 86400;
    foreach (array_merge(glob($dir . '/*-*.json') ?: [], glob($dir . '/og-*.jpg') ?: []) as $f) if ((int) @filemtime($f) < $old) @unlink($f);   // og-: social_image_file()
}

/**
 * The average colour around the edges of a picture, for the glow behind the featured picture on the homepage:
 * ['t' => 'rgb(…)', 'tr' => …, 'r', 'br', 'b', 'bl', 'l', 'tl'] (top, top-right, … clockwise), [] when it can't be read.
 * Only averages of large areas: nothing of the picture itself shows, also for hidden (blurred) pictures.
 */
function glow_colors(string $url): array
{
    static $memo = [];
    if ($url === '') return [];
    if (isset($memo[$url])) return $memo[$url];
    $path = media_path(img_small($url));
    if (!$path || !is_file($path)) return $memo[$url] = [];
    return $memo[$url] = (array) img_cache('glow', $path, fn() => glow_colors_of($path));
}

/** The glow colours of one image file (glow_colors() caches them). */
function glow_colors_of(string $path): array
{
    if (!img_supported() || !($im = img_load($path))) return [];
    $grid = imagecreatetruecolor(3, 3);   // 3×3 averages: the 8 outer cells are the edges and corners
    imagecopyresampled($grid, $im, 0, 0, 0, 0, 3, 3, imagesx($im), imagesy($im));
    unset($im);
    $out = [];
    foreach (['tl' => [0, 0], 't' => [1, 0], 'tr' => [2, 0], 'r' => [2, 1], 'br' => [2, 2], 'b' => [1, 2], 'bl' => [0, 2], 'l' => [0, 1]] as $k => [$x, $y]) {
        $c = imagecolorat($grid, $x, $y);
        $out[$k] = sprintf('rgb(%d %d %d)', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
    }
    return $out;
}

/**
 * Tiny copy of a /media/ image as a data: address, for items whose pictures are blurred: the browser scales it up
 * (CSS adds the blur), and the real picture never appears in the page. '' when it can't be made (outside image, no GD).
 */
function blur_src(string $url): string
{
    return img_tiny_jpeg('blur', $url === '' ? '' : img_small($url), IMG_BLUR, 60);   // JPEG: no transparency needed, smallest at this size
}

/**
 * A face-sized copy (longest side IMG_AVATAR px) as a data: address, for the round avatars of the gallery (18–22 px): far lighter
 * than loading each model's thumb file for them. Worked out once per picture (img_cache); '' when GD can't (callers use the thumb then).
 */
function avatar_src(string $url): string
{
    return img_tiny_jpeg('avatar', $url === '' ? '' : img_thumb($url), IMG_AVATAR, 80);
}

/** A copy of a picture file of this site at most $max px, as a JPEG data: address (cached per file as $what); '' when it can't. */
function img_tiny_jpeg(string $what, string $url, int $max, int $quality): string
{
    static $memo = [];
    if ($url === '') return '';
    if (isset($memo["$what|$url"])) return $memo["$what|$url"];
    $path = media_path($url);
    if (!$path || !is_file($path)) return $memo["$what|$url"] = '';
    return $memo["$what|$url"] = (string) img_cache($what, $path, function () use ($path, $max, $quality): string {
        if (!img_supported() || !($im = img_load($path))) return '';
        $tiny = img_resized($im, $max);
        ob_start();
        $ok = imagejpeg($tiny, null, $quality);
        $data = (string) ob_get_clean();
        unset($tiny, $im);
        return $ok && $data !== '' ? 'data:image/jpeg;base64,' . base64_encode($data) : '';
    });
}

const IMG_SOCIAL_W = 1200;   // link-preview picture (X's summary_large_image): 1.91:1, shown whole with no cropping
const IMG_SOCIAL_H = 630;

/**
 * The address of the link-preview copy of a picture of this site (twitter:image): /og/<signature><address>.jpg, served by
 * og-image.php. X crops a large card picture to 1.91:1 (a 9:16 main picture would show only a strip of its middle) and is
 * pickier than Discord about formats, so this is a 1200 × 630 JPEG: the whole picture in the middle over a dark blurred copy of itself.
 * The signature means only addresses the site printed work (nobody can make the server draw any file). '' when it can't be made.
 */
function social_image_url(string $url): string
{
    $path = media_path($url);
    if (!$path || !is_file($path) || !img_supported()) return '';
    $b = rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    return strlen($b) <= 400 ? '/og/' . social_image_sig($url) . $b . '.jpg' : '';   // 400: social_image_src()'s limit
}

function social_image_sig(string $url): string
{
    return substr(hash_hmac('sha256', $url, m3d_secret('preview')), 0, 16);
}

/** The picture address in a code of social_image_url(), null when it isn't one the site made. */
function social_image_src(string $code): ?string
{
    if (!preg_match('/^([0-9a-f]{16})([A-Za-z0-9_-]{1,400})$/D', $code, $m)) return null;
    $url = (string) base64_decode(strtr($m[2], '-_', '+/'), true);
    if ($url === '' || !hash_equals(social_image_sig($url), $m[1])) return null;
    $path = media_path($url);
    return $path && is_file($path) ? $url : null;
}

/** The link-preview JPEG of a picture (made once, kept in private/cache/ like img_cache()), null when it can't be made. */
function social_image_file(string $url): ?string
{
    $src = img_variant($url, 'md') ?: $url;   // 1800 px is plenty for 1200 × 630 and quicker to read than the large file
    $path = (string) media_path($src);
    $dir = M3D_PRIVATE . '/cache';
    img_cache_cleanup($dir);
    $file = $dir . '/og-' . md5($path . '|' . (int) @filemtime($path)) . '.jpg';
    if (is_file($file)) {
        if ((int) @filemtime($file) < time() - 7 * 86400) @touch($file);   // still in use: the clean-up keeps it
        return $file;
    }
    $jpeg = social_image_make($path);
    if ($jpeg === '' || !(is_dir($dir) || @mkdir($dir, 0700, true))) return null;
    m3d_atomic_write($file, $jpeg);
    return is_file($file) ? $file : null;
}

/** Draws the link-preview picture of one file: the whole picture fitted in the middle, the rest a dark blurred copy of it. */
function social_image_make(string $path): string
{
    if (!img_supported() || !($im = img_load($path))) return '';
    $w = imagesx($im); $h = imagesy($im);
    $W = IMG_SOCIAL_W; $H = IMG_SOCIAL_H;
    $out = imagecreatetruecolor($W, $H);
    // background: the picture covering the frame, shrunk to a few pixels (the blur) and drawn back big, darker
    $s = max($W / $w, $H / $h);
    $cw = (int) round($W / $s); $ch = (int) round($H / $s);
    $bg = imagecreatetruecolor(40, (int) round(40 * $H / $W));
    imagecopyresampled($bg, $im, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), imagesx($bg), imagesy($bg), $cw, $ch);
    foreach ([2, 4, 4] as $k) {   // grown back in steps, softened at each one: GD's one-step enlargement shows the pixels as squares
        $big = imagecreatetruecolor(imagesx($bg) * $k, imagesy($bg) * $k);
        imagecopyresampled($big, $bg, 0, 0, 0, 0, imagesx($big), imagesy($big), imagesx($bg), imagesy($bg));
        for ($i = 0; $i < 3; $i++) imagefilter($big, IMG_FILTER_GAUSSIAN_BLUR);
        $bg = $big;
    }
    imagecopyresampled($out, $bg, 0, 0, 0, 0, $W, $H, imagesx($bg), imagesy($bg));
    imagefilter($out, IMG_FILTER_BRIGHTNESS, -70);
    // the picture itself, whole
    $f = min($W / $w, $H / $h);
    $fw = (int) round($w * $f); $fh = (int) round($h * $f);
    imagecopyresampled($out, $im, intdiv($W - $fw, 2), intdiv($H - $fh, 2), 0, 0, $fw, $fh, $w, $h);
    unset($im, $bg, $big);
    imageinterlace($out, true);
    ob_start();
    $ok = imagejpeg($out, null, 85);
    $data = (string) ob_get_clean();
    return $ok ? $data : '';
}

/**
 * Copies one outside image into media/<folder>/ as WebP; returns the new address (Images page). The download is capped at 80 MB.
 */
function copy_external(string $url, string $folder): string
{
    $dir = M3D_SITE . '/media/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Cannot create media folder');
    $maxBytes = 80 * 1024 * 1024;
    $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME)));
    $base = substr(trim($base, '-') ?: 'image', 0, 50) . '-' . substr(md5($url), 0, 6);
    if (is_file("$dir/$base.webp")) return "/media/$folder/$base.webp";   // copied before
    $tmp = "$dir/.copy-" . bin2hex(random_bytes(4));
    $copied = false;
    // a second try when the connection breaks off halfway (big pictures on a busy server); the error says why it stopped
    for ($try = 1; !$copied; $try++) {
        $fh = fopen($tmp, 'wb');
        // m3d_fetch(): http / https only, never this server or a private network, each of the 3 redirects checked again
        ['body' => $ok, 'code' => $code, 'errno' => $errno, 'err' => $err] = m3d_fetch($url, [CURLOPT_FILE => $fh, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (catalog site)', CURLOPT_MAXFILESIZE => $maxBytes], 3);
        fclose($fh);
        if ($ok && $code === 200) break;
        @unlink($tmp);
        if ($errno === M3D_FETCH_REFUSED) throw new RuntimeException($err);   // trying again changes nothing
        $why = $errno === CURLE_FILESIZE_EXCEEDED ? 'bigger than ' . max(1, round($maxBytes / 1048576)) . ' MB'
            : ($errno === CURLE_OPERATION_TIMEDOUT ? 'too slow, stopped after 2 minutes' : ($err !== '' ? $err : 'no answer'));
        $retry = $code !== 404 && $code !== 403 && $errno !== CURLE_FILESIZE_EXCEEDED;
        if ($try >= 2 || !$retry) throw new RuntimeException('Download failed (' . ($code && $code !== 200 ? "HTTP $code" : $why) . ')');
    }
    try {
        $sz = @getimagesize($tmp);
        if ($sz && !img_fits_memory($sz[0], $sz[1])) throw new RuntimeException('Picture too large for this server’s memory (' . $sz[0] . '×' . $sz[1] . ')');
        if (img_is_animated_gif($tmp)) { rename($tmp, "$dir/$base.gif"); return "/media/$folder/$base.gif"; }
        return "/media/$folder/" . img_make_versions($tmp, $dir, $base);
    } finally {
        @unlink($tmp);
    }
}

/**
 * Site logo (Admin → Settings → Site identity): from an uploaded picture, media/site/logo-<hex>.png (128 px tall, transparency kept)
 * and the browser / phone icons icon-<hex>-{32,180,192}.png (square, the picture centred; the 180 px one, for iPhones, on the
 * site background since they show transparency as black). Returns [logo address, icon code]. media/site/ is never "unused".
 */
function img_site_logo(string $tmp): array
{
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        throw new InvalidArgumentException('Upload a PNG, JPG, WebP or GIF picture (a square one with a transparent background works best).');
    }
    [$w, $h] = $info;
    if ($w < 16 || $h < 16 || $w * $h > 40_000_000 || !img_fits_memory($w, $h)) throw new InvalidArgumentException('That picture is too small or too big for a logo.');
    $src = @imagecreatefromstring((string) file_get_contents($tmp));
    if (!$src) throw new InvalidArgumentException('That picture could not be read.');
    $dir = M3D_SITE . '/media/site';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Could not create the media/site folder.');
    $hex = bin2hex(random_bytes(4));
    $canvas = function (int $cw, int $ch, ?array $bg = null) {
        $c = imagecreatetruecolor($cw, $ch);
        imagealphablending($c, false);
        imagesavealpha($c, true);
        imagefill($c, 0, 0, $bg ? imagecolorallocate($c, ...$bg) : imagecolorallocatealpha($c, 0, 0, 0, 127));
        imagealphablending($c, true);
        return $c;
    };
    $save = function ($img, string $file): void {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        if (!imagepng($img, $file, 9)) throw new RuntimeException('Could not save the logo in media/site.');
    };
    // the header logo: 128 px tall (shown at 32 px, sharp on any screen), at most 3 times as wide
    $lh = min(128, $h);
    $lw = (int) max(1, round($w * $lh / $h));
    if ($lw > $lh * 3) { $lw = $lh * 3; $lh = (int) max(1, round($h * $lw / $w)); }
    $logo = $canvas($lw, $lh);
    imagecopyresampled($logo, $src, 0, 0, 0, 0, $lw, $lh, $w, $h);
    $save($logo, "$dir/logo-$hex.png");
    foreach ([32 => null, 192 => null, 180 => [18, 17, 16]] as $size => $bg) {
        $icon = $canvas($size, $size, $bg);
        $pad = $bg ? (int) round($size * .1) : 0;
        $fit = min(($size - 2 * $pad) / $w, ($size - 2 * $pad) / $h);
        $iw = (int) max(1, round($w * $fit)); $ih = (int) max(1, round($h * $fit));
        imagecopyresampled($icon, $src, intdiv($size - $iw, 2), intdiv($size - $ih, 2), 0, 0, $iw, $ih, $w, $h);
        $save($icon, "$dir/icon-$hex-$size.png");
    }
    return ['/media/site/logo-' . $hex . '.png', $hex];
}

/** Deletes the logo and icons of an older upload (media/site/logo-<hex>.png, icon-<hex>-*.png). */
function img_site_logo_delete(string $logo, string $icon): void
{
    $dir = M3D_SITE . '/media/site';
    if (preg_match('#^/media/site/(logo-[0-9a-f]{8}\.png)$#', $logo, $m)) @unlink("$dir/{$m[1]}");
    if (preg_match('/^[0-9a-f]{8}$/', $icon)) foreach (glob("$dir/icon-$icon-*.png") ?: [] as $f) @unlink($f);
}
