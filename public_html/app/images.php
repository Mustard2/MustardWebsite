<?php
// Image processing for uploads: every image is saved as two WebP versions
//   <name>.webp      large  (longest side ≤ IMG_LARGE)  → main image, full-screen gallery
//   <name>-sm.webp   small  (longest side ≤ IMG_SMALL)  → catalog cards, thumbnails
// Animated GIFs are kept as they are.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const IMG_LARGE = 2560;
const IMG_SMALL = 800;
const IMG_QUALITY = 82;

function img_supported(): bool
{
    return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
}

function img_webp(): bool
{
    return function_exists('imagewebp') && !empty(gd_info()['WebP Support']);
}

/** Absolute file path for a /media/... address, or null if it isn't one of ours. */
function media_path(string $url): ?string
{
    if (!preg_match('#^/media/[A-Za-z0-9/_.-]+$#', $url) || str_contains($url, '..')) return null;
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
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) { imagedestroy($im); $im = $r; } }
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
        $small = img_resized($large, IMG_SMALL);
        img_save($small, "$dir/$base-sm");
        if ($small !== $large) imagedestroy($small);
        if ($large !== $im) imagedestroy($large);
    } finally {
        imagedestroy($im);
    }
    return basename($largePath);
}

const IMG_BLUR = 24;   // longest side of the blurred copies (blurred / locked items)

/**
 * The average colour around the edges of a picture, for the glow behind the featured picture on the homepage:
 * ['t' => 'rgb(…)', 'tr' => …, 'r', 'br', 'b', 'bl', 'l', 'tl'] (top, top-right, … clockwise), [] when it can't be read.
 * Only averages of large areas: nothing of the picture itself shows, also for hidden (blurred) pictures.
 */
/**
 * Results worked out from an image (glow colours, blurred copies) kept in the private folder's cache/, so pages don't decode
 * pictures on every visit. The key includes the file's modified time: a replaced picture gets a new entry.
 */
function img_cache(string $what, string $path, callable $make): mixed
{
    $dir = M3D_PRIVATE . '/cache';
    $file = $dir . '/' . $what . '-' . md5($path . '|' . (int) @filemtime($path)) . '.json';
    if (is_file($file) && is_array($hit = json_decode((string) @file_get_contents($file), true)) && array_key_exists('v', $hit)) return $hit['v'];
    $v = $make();
    if (is_dir($dir) || @mkdir($dir, 0700, true)) {
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, json_encode(['v' => $v])) !== false) @rename($tmp, $file); else @unlink($tmp);
    }
    return $v;
}

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
    imagedestroy($im);
    $out = [];
    foreach (['tl' => [0, 0], 't' => [1, 0], 'tr' => [2, 0], 'r' => [2, 1], 'br' => [2, 2], 'b' => [1, 2], 'bl' => [0, 2], 'l' => [0, 1]] as $k => [$x, $y]) {
        $c = imagecolorat($grid, $x, $y);
        $out[$k] = sprintf('rgb(%d %d %d)', ($c >> 16) & 255, ($c >> 8) & 255, $c & 255);
    }
    imagedestroy($grid);
    return $out;
}

/**
 * Tiny copy of a /media/ image as a data: address, for items whose pictures are blurred: the browser scales it up
 * (CSS adds the blur), and the real picture never appears in the page. '' when it can't be made (outside image, no GD).
 */
function blur_src(string $url): string
{
    static $memo = [];
    if ($url === '') return '';
    if (isset($memo[$url])) return $memo[$url];
    $path = media_path(img_small($url));
    if (!$path || !is_file($path)) return $memo[$url] = '';
    return $memo[$url] = (string) img_cache('blur', $path, function () use ($path): string {
        if (!img_supported() || !($im = img_load($path))) return '';
        $tiny = img_resized($im, IMG_BLUR);
        ob_start();
        $ok = imagejpeg($tiny, null, 60);   // JPEG: no transparency needed, smallest at this size
        $data = (string) ob_get_clean();
        if ($tiny !== $im) imagedestroy($tiny);
        imagedestroy($im);
        return $ok && $data !== '' ? 'data:image/jpeg;base64,' . base64_encode($data) : '';
    });
}

/**
 * Copies one outside image into media/<folder>/ as WebP; returns the new address (Images page, SmutBase import).
 * $key names the file (default: the address; signed addresses change every time, so the importer passes one without the query).
 * $maxPixels > 0 refuses bigger pictures before decoding them (GD would run out of memory), $maxBytes caps the download.
 */
function copy_external(string $url, string $folder, int $maxPixels = 0, ?string $key = null, int $maxBytes = 80 * 1024 * 1024): string
{
    $dir = M3D_SITE . '/media/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) throw new RuntimeException('Cannot create media folder');
    $key ??= $url;
    $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo((string) parse_url($key, PHP_URL_PATH), PATHINFO_FILENAME)));
    $base = substr(trim($base, '-') ?: 'image', 0, 50) . '-' . substr(md5($key), 0, 6);
    if (is_file("$dir/$base.webp")) return "/media/$folder/$base.webp";   // copied before
    $tmp = "$dir/.copy-" . bin2hex(random_bytes(4));
    $copied = false;
    // a second try when the connection breaks off halfway (big pictures on a busy server); the error says why it stopped
    for ($try = 1; !$copied; $try++) {
        $fh = fopen($tmp, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 120,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (catalog site)', CURLOPT_MAXFILESIZE => $maxBytes]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);
        if ($ok && $code === 200) break;
        @unlink($tmp);
        $why = $errno === CURLE_FILESIZE_EXCEEDED ? 'bigger than ' . max(1, round($maxBytes / 1048576)) . ' MB'
            : ($errno === CURLE_OPERATION_TIMEDOUT ? 'too slow, stopped after 2 minutes' : ($err !== '' ? $err : 'no answer'));
        $retry = $code !== 404 && $code !== 403 && $errno !== CURLE_FILESIZE_EXCEEDED;
        if ($try >= 2 || !$retry) throw new RuntimeException('Download failed (' . ($code && $code !== 200 ? "HTTP $code" : $why) . ')');
    }
    try {
        $sz = @getimagesize($tmp);
        if ($maxPixels > 0 && $sz && $sz[0] * $sz[1] > $maxPixels) throw new RuntimeException('Picture too large to convert');
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
