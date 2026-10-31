<?php
// Patreon profile pictures (Settings → Patreon → Profile Pictures, on until switched off there). At login (auth/callback.php) the picture
// Patreon gives for the account (`thumb_url` of the identity, m3d_identity_url()) is downloaded once, after the page, cut to a
// square of AVATAR_SIZE px (and AVATAR_SIZE_LG px, <id>.lg.webp) and kept as private/avatars/<Patreon id>.webp: outside the website and outside the backups (it comes
// back at the next login). Pages never load anything from Patreon (no third-party requests): m3d_avatar() gives the file as a
// small data: address. Shown in place of the letter circle (bug_avatar(): comments, bug reports, the creator's lists), the header's
// account button and menu (account_box()), My account and the creator's patron cards. Never on likes (they store no names).
//
// data/avatars.json: {off: {Patreon id: time}} people who turned theirs off on My account ("Show My Patreon Picture"; restored with
// the counters, `DATA_PARTS`), seen: {md5 of a downloaded picture: [Patreon ids]} to spot Patreon's default picture: the same file
// for AVATAR_DEFAULT_SHARED accounts or more is a default one, never kept (`defaults`: those md5s). In privacy.php and Personal Data.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const AVATAR_SIZE = 64;              // px, a square: the small copy, shown at 22–30 px (comments, lists, the header), sharp on 2× screens
const AVATAR_SIZE_LG = 160;          // px: the big copy (<id>.lg.webp) for the account menu, My account and Settings (44–84 px), sharp on 2× screens
const AVATAR_MAX_BYTES = 3 * 1024 * 1024;
const AVATAR_DEFAULT_SHARED = 3;     // the same picture for this many accounts = Patreon's default one

/** Whether profile pictures are on (Settings → Patreon → Profile Pictures, settings.json → avatars.on; never saved = on). */
function m3d_avatars_on(): bool
{
    return (bool) ((m3d_settings()['avatars'] ?? [])['on'] ?? true);
}

function m3d_avatar_dir(): string
{
    return M3D_PRIVATE . '/avatars';
}

/** The picture file of a Patreon account (the big copy with $lg), '' for an id that can't be a file name. */
function m3d_avatar_file(string $id, bool $lg = false): string
{
    return preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $id) ? m3d_avatar_dir() . "/$id" . ($lg ? '.lg' : '') . '.webp' : '';
}

/** Deletes both copies of a person's picture. */
function m3d_avatar_unlink(string $id): void
{
    foreach ([false, true] as $lg) if (($f = m3d_avatar_file($id, $lg)) !== '') @unlink($f);
}

/** A picture address from Patreon's answer: https on Patreon's own hosts only, else ''. */
function m3d_avatar_src_clean(mixed $url): string
{
    if (!is_string($url) || strlen($url) > 2000 || !preg_match('#^https://([a-z0-9.-]+)/#iD', $url . '/', $m)) return '';
    $host = strtolower($m[1]);
    return preg_match('/(^|\.)(patreon\.com|patreonusercontent\.com)$/D', $host) ? $url : '';
}

function m3d_avatars_data(): array
{
    $d = m3d_read_json('avatars') ?? [];
    return ['off' => is_array($d['off'] ?? null) ? $d['off'] : [], 'seen' => is_array($d['seen'] ?? null) ? $d['seen'] : [],
            'defaults' => is_array($d['defaults'] ?? null) ? $d['defaults'] : []];
}

/** Whether this person turned their picture off on My account. */
function m3d_avatar_off(string $id): bool
{
    return $id !== '' && isset(m3d_avatars_data()['off'][$id]);
}

/** My account's "Show My Patreon Picture": off deletes the picture and keeps it from coming back; on fetches it again from the
 *  address of this login ($src, '' = at the next login). False when it couldn't be saved. */
function m3d_avatar_choose(string $id, bool $show, string $src = ''): bool
{
    if (m3d_avatar_file($id) === '') return false;
    $ok = m3d_json_update('avatars', function (?array $d) use ($id, $show) {
        $d = is_array($d) ? $d : [];
        $off = is_array($d['off'] ?? null) ? $d['off'] : [];
        if ($show) unset($off[$id]); else $off[$id] = time();
        $d['off'] = $off;
        return [$d, true];
    });
    if (!$ok) return false;
    if (!$show) m3d_avatar_delete($id);
    elseif ($src !== '' && m3d_avatars_on()) m3d_session_pause(fn() => m3d_avatar_fetch($id, $src));
    return true;
}

/** Deletes one person's picture (and forgets its md5); also their "off" mark with $all (Personal Data → Delete). */
function m3d_avatar_delete(string $id, bool $all = false): void
{
    if (m3d_avatar_file($id) === '') return;
    m3d_avatar_unlink($id);
    m3d_json_update('avatars', function (?array $d) use ($id, $all) {
        $d = is_array($d) ? $d : [];
        foreach ((array) ($d['seen'] ?? []) as $md5 => $ids) {
            $ids = array_values(array_diff((array) $ids, [$id]));
            if ($ids) $d['seen'][$md5] = $ids; else unset($d['seen'][$md5]);
        }
        if ($all) unset($d['off'][$id]);
        return [$d, true];
    });
}

/** Settings → Profile Pictures turned off: every picture deleted (the "off" marks stay). The number deleted. */
function m3d_avatars_delete_all(): int
{
    $n = 0;
    foreach (glob(m3d_avatar_dir() . '/*.webp') ?: [] as $f) if (@unlink($f) && !str_ends_with($f, '.lg.webp')) $n++;   // people, not files
    m3d_json_update('avatars', function (?array $d) {
        $d = is_array($d) ? $d : [];
        $d['seen'] = [];
        return [$d, true];
    });
    return $n;
}

/** How many pictures are kept now (Settings box). */
function m3d_avatars_count(): int
{
    return count(array_filter(glob(m3d_avatar_dir() . '/*.webp') ?: [], fn($f) => !str_ends_with($f, '.lg.webp')));
}

/**
 * At login (auth/callback.php): after the page is sent, the account's picture is fetched again, or deleted when Patreon has none
 * any more. Nothing while profile pictures are off or the person turned theirs off.
 */
function m3d_avatar_login(array $patron): void
{
    $id = (string) ($patron['id'] ?? '');
    if (!m3d_avatars_on() || m3d_avatar_file($id) === '' || m3d_avatar_off($id)) return;
    $src = (string) ($patron['pic'] ?? '');
    m3d_after_response(fn() => $src === '' ? m3d_avatar_delete($id) : m3d_avatar_fetch($id, $src));
}

/**
 * Downloads a Patreon picture and keeps it as a square AVATAR_SIZE px WebP. Patreon's default picture (an address with "default"
 * in it, or a file many accounts share) is never kept: the letter circle shows instead. Failures only go to Site problems.
 */
function m3d_avatar_fetch(string $id, string $src): bool
{
    $file = m3d_avatar_file($id);
    if ($file === '' || ($src = m3d_avatar_src_clean($src)) === '' || !img_supported()) return false;
    $dir = m3d_avatar_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) { m3d_problem('Profile pictures', 'Could not create the private/avatars folder.'); return false; }
    $r = m3d_fetch($src, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (MustardWebsite)', CURLOPT_MAXFILESIZE => AVATAR_MAX_BYTES], 3);
    $body = is_string($r['body']) ? $r['body'] : '';
    if ($r['code'] !== 200 || $body === '' || strlen($body) > AVATAR_MAX_BYTES) {
        m3d_problem('Profile pictures', 'A Patreon picture could not be downloaded: ' . ($r['code'] ? 'HTTP ' . $r['code'] : $r['err']));
        return false;
    }
    return m3d_avatar_save($id, $body, (string) $r['url']);
}

/** Keeps a downloaded picture ($url = where it came from, after redirects) as the account's AVATAR_SIZE px square; false when it isn't kept. */
function m3d_avatar_save(string $id, string $body, string $url): bool
{
    $file = m3d_avatar_file($id);
    $dir = m3d_avatar_dir();
    if ($file === '' || !img_supported() || (!is_dir($dir) && !@mkdir($dir, 0700, true))) return false;
    if (m3d_avatar_src_clean($url) === '' || stripos((string) parse_url($url, PHP_URL_PATH), 'default') !== false) {
        m3d_avatar_delete($id);   // redirected off Patreon, or to its default picture
        return false;
    }
    $sz = @getimagesizefromstring($body);
    if (!$sz || !in_array($sz[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true) || !img_fits_memory((int) $sz[0], (int) $sz[1])) return false;
    $md5 = md5($body);
    $default = m3d_json_update('avatars', function (?array $d) use ($md5, $id) {
        $d = is_array($d) ? $d : [];
        $seen = is_array($d['seen'] ?? null) ? $d['seen'] : [];
        foreach ($seen as $k => $ids) { $ids = array_values(array_diff((array) $ids, [$id])); if ($ids) $seen[$k] = $ids; else unset($seen[$k]); }
        $defaults = is_array($d['defaults'] ?? null) ? $d['defaults'] : [];
        $ids = array_values(array_unique(array_merge((array) ($seen[$md5] ?? []), [$id])));
        if (count($ids) >= AVATAR_DEFAULT_SHARED && !in_array($md5, $defaults, true)) $defaults[] = $md5;
        $isDefault = in_array($md5, $defaults, true);
        if ($isDefault) unset($seen[$md5]); else $seen[$md5] = $ids;
        $d['seen'] = $seen;
        $d['defaults'] = array_slice($defaults, -20);
        return [$d, $isDefault ? ($ids ?: [$id]) : false];
    });
    if (is_array($default)) {   // Patreon's default picture: the accounts kept with it lose it too
        foreach ($default as $other) m3d_avatar_unlink((string) $other);
        return false;
    }
    $tmp = $dir . '/.dl-' . bin2hex(random_bytes(4));
    try {
        if (@file_put_contents($tmp, $body) === false) return false;
        if (!($im = img_load($tmp))) return false;
        // the middle square, then each size (never larger than the picture: a small one stays as it is)
        $w = imagesx($im); $h = imagesy($im); $s = min($w, $h);
        $ok = true;
        foreach ([AVATAR_SIZE => $file, AVATAR_SIZE_LG => m3d_avatar_file($id, true)] as $px => $to) {
            $px = min($px, max(AVATAR_SIZE, $s));
            $out = imagecreatetruecolor($px, $px);
            imagefill($out, 0, 0, imagecolorallocate($out, 38, 36, 32));   // a transparent PNG on the site's card colour
            imagecopyresampled($out, $im, 0, 0, intdiv($w - $s, 2), intdiv($h - $s, 2), $px, $px, $s, $s);
            ob_start();
            $done = img_webp() ? imagewebp($out, null, 82) : imagejpeg($out, null, 85);
            $data = (string) ob_get_clean();
            if (!$done || $data === '') return false;
            if (!is_file($to) || md5_file($to) !== md5($data)) $ok = m3d_atomic_write($to, $data) && $ok;   // the same picture as before: left alone
        }
        return $ok;
    } catch (Throwable $e) {
        m3d_problem('Profile pictures', 'A Patreon picture could not be saved: ' . $e->getMessage());
        return false;
    } finally {
        @unlink($tmp);
    }
}

/** The picture of a Patreon account as a data: address (the big copy with $lg, else the small one), '' when there is none (or profile pictures are off). */
function m3d_avatar(string $id, bool $lg = false): string
{
    static $memo = [];
    if ($id === '' || !m3d_avatars_on()) return '';
    return $memo[$id . ($lg ? '.lg' : '')] ??= ($lg ? m3d_avatar_stored($id, true) : '') ?: m3d_avatar_stored($id);   // pictures from before the big copy: the small one
}

/** The stored picture as a data: address, whether profile pictures are on or not (Personal Data); '' when none is stored. */
function m3d_avatar_stored(string $id, bool $lg = false): string
{
    $f = m3d_avatar_file($id, $lg);
    $data = $f !== '' && is_file($f) ? (string) @file_get_contents($f) : '';
    return $data !== '' ? 'data:image/' . (str_starts_with($data, 'RIFF') ? 'webp' : 'jpeg') . ';base64,' . base64_encode($data) : '';
}

/**
 * A person's round picture, or the coloured circle with their first letter when there is none; $creator = the site's creator
 * (mustard circle, or a mustard ring around the picture). $class adds a size (`ava-lg`…). Decorative: the name is always next to it.
 */
function avatar_html(string $id, string $name, bool $creator = false, string $class = ''): string
{
    $cls = 'ava' . ($creator ? ' creator' : '') . ($class !== '' ? ' ' . $class : '');
    if (($src = m3d_avatar($id, (bool) preg_match('/\bava-(lg|xl)\b/', $class))) !== '') return '<span class="' . $cls . ' has-pic" aria-hidden="true"><img src="' . e($src) . '" alt="" decoding="async" /></span>';
    $h = 0;
    foreach (str_split($name) as $c) $h = ($h * 31 + ord($c)) % 360;
    return '<span class="' . $cls . '" style="--h:' . $h . '" aria-hidden="true">' . e(mb_strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1))) . '</span>';
}
