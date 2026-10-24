<?php
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

// Videos in texts (blog posts, docs pages): an .mp4 / .webm file in media/ written like a picture, "![caption](/media/blog/name.mp4)" alone on a line
// (md_html() → md_video()). Uploaded by the blog and docs editors' "Add a Video" window (admin/upload-video.php), which checks the file in the
// browser before sending it (admin.js videoInfo(): the same rules as m3d_video_info() here). The cover is a picture next to the video
// with the same name (name.mp4 → name.webp + its smaller versions, m3d_video_cover()): it belongs to the video (listed, deleted and
// backed up with it, never on its own in the picture library). Nothing of a video loads until the visitor presses Play (preload="none").
// Media → Videos lists them (media_videos() in _admin.php). Cloudflare caches .mp4 / .webm by itself (default cached extensions).

const M3D_VIDEO_MAX = 50 * 1024 * 1024;   // Pi's limit (2026-10-22): small clips; Cloudflare's terms frown on sites serving mostly video
const VIDEO_EXT = ['mp4', 'webm'];
const VIDEO_CODECS = [   // video codec (MP4 sample entry / WebM CodecID) => [name, plays in every current browser]
    'avc1' => ['H.264', true], 'avc3' => ['H.264', true],
    'hvc1' => ['H.265 (HEVC)', false], 'hev1' => ['H.265 (HEVC)', false],
    'av01' => ['AV1', false], 'vp09' => ['VP9', true], 'mp4v' => ['MPEG-4 Part 2', false],
    'V_VP8' => ['VP8', true], 'V_VP9' => ['VP9', true], 'V_AV1' => ['AV1', false],
];

/** '/media/<folder>/<name>.mp4|webm' → the file's path when it exists, else null. */
function m3d_video_path(string $url): ?string
{
    if (!preg_match('#^/media/[a-z0-9-]+/[a-z0-9-]+\.(mp4|webm)$#D', $url)) return null;
    $file = M3D_SITE . $url;
    return is_file($file) ? $file : null;
}

/** True for an address of a video in media/ (whether or not the file is there). */
function m3d_is_video_url(string $url): bool
{
    return (bool) preg_match('#^/media/[A-Za-z0-9/_.-]+\.(mp4|webm)$#iD', $url);
}

/** The video's cover picture (its large file, name.webp or .jpg next to it), '' when it has none. */
function m3d_video_cover(string $url): string
{
    if (!m3d_is_video_url($url)) return '';
    $base = substr($url, 0, strrpos($url, '.'));
    foreach (['webp', 'jpg'] as $ext) if (($p = media_path("$base.$ext")) && is_file($p)) return "$base.$ext";
    return '';
}

/** Every file of a video's cover (large + smaller versions). */
function m3d_video_cover_files(string $url): array
{
    $c = m3d_video_cover($url);
    return $c === '' ? [] : [$c, ...img_versions($c)];
}

/** Bytes of the server's upload limits for one video: the smallest of M3D_VIDEO_MAX, upload_max_filesize and post_max_size (less room for the cover). */
function m3d_video_max(): array
{
    $bytes = function (string $v): int {
        $v = trim($v);
        $n = (int) $v;
        return $n * match (strtoupper(substr($v, -1))) { 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1 };
    };
    $max = M3D_VIDEO_MAX;
    $why = '';
    $up = $bytes((string) ini_get('upload_max_filesize'));
    if ($up > 0 && $up < $max) { $max = $up; $why = 'the server’s upload limit (upload_max_filesize)'; }
    $post = $bytes((string) ini_get('post_max_size'));
    if ($post > 0 && $post - 2 * 1048576 < $max) { $max = max(0, $post - 2 * 1048576); $why = 'the server’s form limit (post_max_size)'; }
    return ['bytes' => $max, 'why' => $why];
}

/**
 * Reads a video file's header (MP4 boxes or WebM elements; nothing else is loaded): ['type' => mp4|webm, 'w', 'h' (as shown: an upright
 * phone video is taller than wide), 'dur' (seconds), 'codec' (name), 'plays' (plays in every current browser), 'audio' (codec name, '' = no
 * sound), 'fast' (MP4: the index comes before the picture data, so it starts playing at once)]. Throws RuntimeException when it isn't an
 * MP4 / WebM video. Kept in the private cache by path + time (pages read it to size the player and show its length).
 */
function m3d_video_info(string $file): array
{
    return img_cache('video', $file, function () use ($file) {
        try { return ['ok' => true] + m3d_video_read($file); }
        catch (RuntimeException $ex) { return ['ok' => false, 'error' => $ex->getMessage()]; }
    }) ?: ['ok' => false, 'error' => 'The file could not be read.'];
}

/** m3d_video_info() without the cache; throws. */
function m3d_video_read(string $file): array
{
    $fh = @fopen($file, 'rb');
    if (!$fh) throw new RuntimeException('The file could not be read.');
    try {
        $head = (string) fread($fh, 12);
        if (strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp') return m3d_mp4_read($fh, (int) @filesize($file));
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) return m3d_webm_read($fh);
        throw new RuntimeException('This is not an MP4 or WebM video.');
    } finally {
        fclose($fh);
    }
}

/** MP4 (ISO base media file): the top boxes in order, then moov's tracks. */
function m3d_mp4_read($fh, int $size): array
{
    $pos = 0;
    $moov = null;
    $mdatFirst = false;
    while ($pos + 8 <= $size) {
        fseek($fh, $pos);
        $h = (string) fread($fh, 16);
        if (strlen($h) < 8) break;
        $len = unpack('N', substr($h, 0, 4))[1];
        $type = substr($h, 4, 4);
        $hdr = 8;
        if ($len === 1) { $len = (int) (unpack('J', substr($h, 8, 8))[1] ?? 0); $hdr = 16; }
        elseif ($len === 0) $len = $size - $pos;
        if ($len < $hdr) throw new RuntimeException('The file is damaged.');
        if ($type === 'mdat' && $moov === null) $mdatFirst = true;
        if ($type === 'moov') {
            if ($len > 64 * 1048576) throw new RuntimeException('The file is damaged.');
            fseek($fh, $pos + $hdr);
            $moov = (string) fread($fh, $len - $hdr);
            break;
        }
        $pos += $len;
    }
    if ($moov === null) throw new RuntimeException('The video’s index (moov) is missing: the file is damaged or still being written.');
    $out = ['type' => 'mp4', 'w' => 0, 'h' => 0, 'dur' => 0.0, 'codec' => '', 'plays' => false, 'audio' => '', 'fast' => !$mdatFirst];
    $boxes = function (string $d) {   // [type, body] of the boxes in $d
        $o = [];
        for ($p = 0; $p + 8 <= strlen($d);) {
            $l = unpack('N', substr($d, $p, 4))[1];
            $hd = 8;
            if ($l === 1) { $l = (int) unpack('J', substr($d, $p + 8, 8))[1]; $hd = 16; }
            elseif ($l === 0) $l = strlen($d) - $p;
            if ($l < $hd) break;
            $o[] = [substr($d, $p + 4, 4), substr($d, $p + $hd, $l - $hd)];
            $p += $l;
        }
        return $o;
    };
    $find = function (string $d, array $path) use ($boxes, &$find) {
        foreach ($boxes($d) as [$t, $b]) if ($t === $path[0]) return count($path) === 1 ? $b : $find($b, array_slice($path, 1));
        return null;
    };
    foreach ($boxes($moov) as [$t, $b]) {
        if ($t === 'mvhd' && strlen($b) >= 32) {
            [$scale, $dur] = ord($b[0]) === 1 ? [unpack('N', substr($b, 20, 4))[1], unpack('J', substr($b, 24, 8))[1]] : [unpack('N', substr($b, 12, 4))[1], unpack('N', substr($b, 16, 4))[1]];
            if ($scale > 0) $out['dur'] = round($dur / $scale, 2);
        }
        if ($t !== 'trak') continue;
        $hdlr = $find($b, ['mdia', 'hdlr']);
        $kind = $hdlr !== null && strlen($hdlr) >= 12 ? substr($hdlr, 8, 4) : '';
        $stsd = $find($b, ['mdia', 'minf', 'stbl', 'stsd']);
        $fourcc = $stsd !== null && strlen($stsd) >= 16 ? substr($stsd, 12, 4) : '';
        if ($kind === 'vide' && $out['codec'] === '') {
            [$out['codec'], $out['plays']] = VIDEO_CODECS[$fourcc] ?? [trim($fourcc) ?: 'unknown', false];
            $tkhd = $find($b, ['tkhd']);
            if ($tkhd !== null && strlen($tkhd) >= 84) {
                $w = (int) round(unpack('N', substr($tkhd, -8, 4))[1] / 65536);
                $h = (int) round(unpack('N', substr($tkhd, -4, 4))[1] / 65536);
                $m = unpack('Na/Nb', substr($tkhd, -44, 8));   // the matrix's a, b: a phone filming upright stores a quarter turn
                $turned = $m['a'] === 0 && $m['b'] !== 0;
                [$out['w'], $out['h']] = $turned ? [$h, $w] : [$w, $h];
            }
        } elseif ($kind === 'soun' && $out['audio'] === '') {
            $out['audio'] = ['mp4a' => 'AAC', 'Opus' => 'Opus', 'ac-3' => 'AC-3', 'ec-3' => 'E-AC-3', '.mp3' => 'MP3', 'alac' => 'ALAC'][$fourcc] ?? (trim($fourcc) ?: 'unknown');
        }
    }
    if ($out['codec'] === '') throw new RuntimeException('There is no video in this file.');
    return $out;
}

/** WebM (Matroska): the header's DocType, then Segment → Info (duration) and Tracks (codecs, size); stops at the first Cluster. */
function m3d_webm_read($fh): array
{
    fseek($fh, 0);
    $d = (string) fread($fh, 4 * 1048576);   // the header, Info and Tracks come first
    $p = 0;
    $vint = function (bool $keepMarker) use ($d, &$p): ?int {   // an EBML number; null = unknown size
        if ($p >= strlen($d)) return 0;
        $b = ord($d[$p]);
        $len = 1;
        while ($len <= 8 && !($b & (0x80 >> ($len - 1)))) $len++;
        if ($len > 8) throw new RuntimeException('The file is damaged.');
        $v = $keepMarker ? $b : $b & ((0x80 >> ($len - 1)) - 1);
        $all = $v === (0x80 >> ($len - 1)) - 1;
        for ($i = 1; $i < $len; $i++) { $c = ord($d[$p + $i] ?? "\0"); $v = ($v << 8) | $c; $all = $all && $c === 0xFF; }
        $p += $len;
        return !$keepMarker && $all ? null : $v;
    };
    $out = ['type' => 'webm', 'w' => 0, 'h' => 0, 'dur' => 0.0, 'codec' => '', 'plays' => false, 'audio' => '', 'fast' => true];
    $scale = 1000000;
    $durRaw = null;
    $doc = '';
    $uint = fn(string $s) => array_reduce(str_split($s), fn($n, $c) => ($n << 8) | ord($c), 0);
    $float = fn(string $s) => strlen($s) === 4 ? unpack('G', $s)[1] : (strlen($s) === 8 ? unpack('E', $s)[1] : 0.0);
    // walks the elements between $p and $end; containers listed here are entered, the rest read or skipped
    $walk = function (int $end, ?array &$track, int $depth = 0) use (&$walk, $d, &$p, $vint, $uint, $float, &$out, &$scale, &$durRaw, &$doc) {
        if ($depth > 6) return;   // these elements nest 4 deep at most: anything deeper is a damaged file
        while ($p < $end && $p < strlen($d)) {
            $id = $vint(true);
            $size = $vint(false);
            $start = $p;
            $stop = $size === null ? $end : min($end, $p + $size);
            switch ($id) {
                case 0x1A45DFA3: case 0x18538067: case 0x1549A966: case 0x1654AE6B: case 0xE0:   // EBML header, Segment, Info, Tracks, Video
                    $walk($stop, $track, $depth + 1);
                    if ($p === PHP_INT_MAX) return;   // a Cluster inside: done
                    break;
                case 0xAE:   // TrackEntry
                    $t = ['type' => 0, 'codec' => '', 'w' => 0, 'h' => 0, 'dw' => 0, 'dh' => 0];
                    $walk($stop, $t, $depth + 1);
                    if ($t['type'] === 1 && $out['codec'] === '') {
                        [$out['codec'], $out['plays']] = VIDEO_CODECS[$t['codec']] ?? [$t['codec'] ?: 'unknown', false];
                        [$out['w'], $out['h']] = $t['dw'] && $t['dh'] ? [$t['dw'], $t['dh']] : [$t['w'], $t['h']];
                    } elseif ($t['type'] === 2 && $out['audio'] === '') {
                        $out['audio'] = ['A_OPUS' => 'Opus', 'A_VORBIS' => 'Vorbis'][$t['codec']] ?? ($t['codec'] ?: 'unknown');
                    }
                    break;
                case 0x4282: $doc = substr($d, $start, $stop - $start); break;   // DocType
                case 0x2AD7B1: $scale = $uint(substr($d, $start, $stop - $start)) ?: 1000000; break;   // TimecodeScale
                case 0x4489: $durRaw = $float(substr($d, $start, $stop - $start)); break;   // Duration
                case 0x83: if ($track !== null) $track['type'] = $uint(substr($d, $start, $stop - $start)); break;   // TrackType
                case 0x86: if ($track !== null) $track['codec'] = substr($d, $start, $stop - $start); break;   // CodecID
                case 0xB0: if ($track !== null) $track['w'] = $uint(substr($d, $start, $stop - $start)); break;   // PixelWidth
                case 0xBA: if ($track !== null) $track['h'] = $uint(substr($d, $start, $stop - $start)); break;   // PixelHeight
                case 0x54B0: if ($track !== null) $track['dw'] = $uint(substr($d, $start, $stop - $start)); break;   // DisplayWidth
                case 0x54BA: if ($track !== null) $track['dh'] = $uint(substr($d, $start, $stop - $start)); break;   // DisplayHeight
                case 0x1F43B675: $p = PHP_INT_MAX; return;   // Cluster: the pictures start, everything needed was before
            }
            if ($size === null && !in_array($id, [0x18538067], true)) return;
            $p = $stop;
        }
    };
    $none = null;
    $walk(strlen($d), $none);
    if ($doc !== 'webm') throw new RuntimeException('This is a Matroska (.mkv) file, not a WebM video: export as MP4 (H.264) or WebM.');
    if ($durRaw !== null) $out['dur'] = round($durRaw * $scale / 1e9, 2);
    if ($out['codec'] === '') throw new RuntimeException('There is no video in this file.');
    return $out;
}

/** "0:42", "12:05", "1:02:03" */
function m3d_duration(float $s): string
{
    $s = (int) round($s);
    return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
}

/** One video in a text: its cover with a play button and the length; site.js starts it (nothing loads before). */
function md_video(string $url, string $caption): string
{
    $file = m3d_video_path($url);
    if (!$file) return '<p>' . e($caption !== '' ? $caption : basename($url)) . ' <span class="muted">(video missing)</span></p>';
    $info = m3d_video_info($file);
    $ok = !empty($info['ok']) && $info['w'] > 0 && $info['h'] > 0;
    $ar = $ok ? $info['w'] . ' / ' . $info['h'] : '16 / 9';
    $cover = m3d_video_cover($url);
    $poster = '';
    if ($cover !== '') {
        $show = img_variant($cover, 'md') ?: $cover;   // the player is at most the text column's width
        $poster = ' poster="' . e($show . '?v=' . (int) @filemtime((string) media_path($show))) . '"';   // a new cover keeps the address: the time tells caches apart
    }
    // no cover: the first frame (metadata only), so the box isn't black
    $src = $cover !== '' ? $url : $url . '#t=0.1';
    $type = str_ends_with($url, '.webm') ? 'video/webm' : 'video/mp4';
    $dur = $ok && $info['dur'] > 0 ? '<span class="mv-dur" aria-hidden="true">' . m3d_duration((float) $info['dur']) . '</span>' : '';
    $label = 'Play video' . ($caption !== '' ? ': ' . $caption : '');
    return '<figure class="md-video"><div class="mv-frame" style="--ar: ' . $ar . '" data-mv>'
        . '<video controls playsinline preload="' . ($cover !== '' ? 'none' : 'metadata') . '"' . $poster . ($ok ? ' width="' . $info['w'] . '" height="' . $info['h'] . '"' : '') . '>'
        . '<source src="' . e($src) . '" type="' . $type . '" /></video>'
        . '<button type="button" class="mv-play" data-mv-play aria-label="' . e($label) . '" hidden><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5v15l13-7.5z"/></svg></button>' . $dur
        . '</div>' . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>';
}
