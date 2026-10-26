<?php
// Temporary links: an address the creator gives someone (a tester, a collaborator) to download every file of one model or accessory
// for a while, without Patreon: /share/<code>/ (share.php). Made in the editor's Downloads box ("Temporary Link…") or in Admin →
// Access → Temporary Links (a tab of admin/users.php, admin/_shares.php), which lists them with their downloads, Cancel and Delete.
// The page shows the item's picture and one Download button per link that is on (patron and public links alike); each press posts
// back and is sent on like /get/ does (m3d_download_url(): an S4 file gets a fresh pre-signed address valid the S4 setting's
// lifetime), so the temporary address itself never expires early and can be cancelled. A download manager or a chat app's link
// preview opening the page uses nothing: only the button's POST counts.
// Not a patron download: no login, limits, daily cap, patron log or item counters; the share counts its own downloads.
// data/shares.json: {shares: [{code, kind, slug, name (at creation: kept for a removed item), note, made, until, max (each file;
// 0 = no limit), used: {link key: n}, cancelled?}]}, newest first; link key = m3d_share_key() of the link's address.
// No visitor data is stored (counts only), so nothing for privacy.php.
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

/** How long a link works: the editor window's choices (seconds). */
const SHARE_TIMES = ['1h' => [3600, '1 Hour'], '6h' => [21600, '6 Hours'], '1d' => [86400, '1 Day'], '3d' => [259200, '3 Days'], '7d' => [604800, '7 Days'], '30d' => [2592000, '30 Days']];
/** Downloads allowed of each file (0 = no limit). */
const SHARE_MAXES = [1, 3, 5, 10, 0];
const SHARE_NOTE_MAX = 120;
const SHARE_CODE_RE = '/^[A-Za-z0-9]{12}$/D';
const SHARE_KEEP = 300;   // past this many, ended links are dropped, oldest first, when a new one is made

/** Every temporary link, newest first. */
function m3d_shares(): array
{
    $d = m3d_read_json('shares');
    return array_values(array_filter((array) ($d['shares'] ?? []), fn($s) => is_array($s) && is_string($s['code'] ?? null)));
}

function m3d_share(string $code): ?array
{
    if (!preg_match(SHARE_CODE_RE, $code)) return null;
    foreach (m3d_shares() as $s) if (hash_equals((string) $s['code'], $code)) return $s;
    return null;
}

/** The key a link's downloads are counted under: its address, so they follow the link when links are reordered. */
function m3d_share_key(string $url): string
{
    return substr(md5($url), 0, 12);
}

/**
 * The links a temporary link offers: every download link of the item that is on, in the editor's order:
 * [['n' => its number in /get/ (auth/get.php), 'label', 'url', 'key']].
 */
function m3d_share_links(array $item): array
{
    $out = [];
    foreach (array_values(array_filter((array) ($item['links'] ?? []), fn($l) => is_array($l) && !empty($l['url']))) as $n => $l) {
        if (!empty($l['off'])) continue;
        $url = (string) $l['url'];
        $out[] = ['n' => $n, 'label' => trim((string) ($l['label'] ?? '')) ?: 'Download', 'url' => $url, 'key' => m3d_share_key($url)];
    }
    return $out;
}

/** Downloads left of one file (null = no limit). */
function m3d_share_left(array $s, string $key): ?int
{
    $max = (int) ($s['max'] ?? 0);
    return $max > 0 ? max(0, $max - (int) ($s['used'][$key] ?? 0)) : null;
}

/** Downloads made through a temporary link, every file together. */
function m3d_share_total(array $s): int
{
    return array_sum(array_map('intval', (array) ($s['used'] ?? [])));
}

/**
 * Where a temporary link stands: 'active', 'expired', 'used' (every file downloaded as many times as allowed),
 * 'cancelled', or 'gone' (its model / accessory was deleted, or has no link on). $item: the item now (null = deleted).
 */
function m3d_share_state(array $s, ?array $item, ?int $now = null): string
{
    if (!empty($s['cancelled'])) return 'cancelled';
    if ((int) ($s['until'] ?? 0) <= ($now ?? time())) return 'expired';
    if (!$item || !empty($s['gone']) || !($links = m3d_share_links($item))) return 'gone';
    if ((int) ($s['max'] ?? 0) > 0 && !array_filter($links, fn($l) => m3d_share_left($s, $l['key']) > 0)) return 'used';
    return 'active';
}

/** A new temporary link for an item; null = not saved. */
function m3d_share_add(string $kind, array $item, int $secs, int $max, string $note): ?array
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';   // no 0 / O, 1 / l / I: easy to read out
    $code = '';
    for ($i = 0; $i < 12; $i++) $code .= $abc[random_int(0, strlen($abc) - 1)];
    $now = time();
    $s = ['code' => $code, 'kind' => $kind, 'slug' => (string) $item['slug'], 'name' => $kind === 'models' ? m3d_model_name($item) : (string) $item['name'], 'note' => $note,
          'made' => $now, 'until' => $now + $secs, 'max' => $max, 'used' => []];
    $ok = m3d_json_update('shares', function ($d) use ($s, $now) {
        $list = array_values(array_filter((array) ($d['shares'] ?? []), 'is_array'));
        array_unshift($list, $s);
        // too many: the ended ones go, oldest first
        for ($i = count($list) - 1; $i > 0 && count($list) > SHARE_KEEP; $i--) {
            $e = $list[$i];
            if (!empty($e['cancelled']) || (int) ($e['until'] ?? 0) <= $now) array_splice($list, $i, 1);
        }
        return [['shares' => $list], true];
    });
    return $ok ? $s : null;
}

/** Changes the temporary links: $fn gets the list and returns it changed. False = not saved. */
function m3d_shares_change(callable $fn): bool
{
    return (bool) m3d_json_update('shares', function ($d) use ($fn) {
        if (!is_array($d)) return [$d, true];   // no file: nothing to change
        return [['shares' => array_values($fn(array_values(array_filter((array) ($d['shares'] ?? []), 'is_array'))))] + $d, true];
    });
}

function m3d_share_cancel(string $code): bool
{
    return m3d_shares_change(fn($list) => array_map(fn($s) => ($s['code'] ?? '') === $code && empty($s['cancelled']) ? $s + ['cancelled' => time()] : $s, $list));
}

/** Deletes temporary links: the given codes, or every ended one ($ended). */
function m3d_shares_delete(array $codes, bool $ended = false): bool
{
    return m3d_shares_change(fn($list) => array_filter($list, function ($s) use ($codes, $ended) {
        if (in_array($s['code'] ?? '', $codes, true)) return false;
        return !$ended || m3d_share_state($s, m3d_item((string) ($s['kind'] ?? ''), (string) ($s['slug'] ?? ''))) === 'active';
    }));
}

/**
 * One download through a temporary link: counted when the link is still active and the file has downloads left (under the
 * file's lock, so two presses can't both take the last one). Returns the link to send the visitor to, or the reason it can't.
 * @return array{ok: bool, link?: array, why?: string}
 */
function m3d_share_use(string $code, string $key): array
{
    $out = m3d_json_update('shares', function ($d) use ($code, $key) {
        $list = array_values(array_filter((array) ($d['shares'] ?? []), 'is_array'));
        foreach ($list as $i => $s) {
            if (!hash_equals((string) ($s['code'] ?? ''), $code)) continue;
            $item = m3d_item((string) ($s['kind'] ?? ''), (string) ($s['slug'] ?? ''));
            $state = m3d_share_state($s, $item);
            if ($state !== 'active') return [$d, ['ok' => false, 'why' => $state]];
            $link = array_values(array_filter(m3d_share_links($item), fn($l) => $l['key'] === $key))[0] ?? null;
            if (!$link) return [$d, ['ok' => false, 'why' => 'link']];
            if (m3d_share_left($s, $key) === 0) return [$d, ['ok' => false, 'why' => 'file']];
            $list[$i]['used'] = (array) ($s['used'] ?? []);
            $list[$i]['used'][$key] = (int) ($s['used'][$key] ?? 0) + 1;
            $list[$i]['last'] = time();
            return [['shares' => $list] + $d, ['ok' => true, 'link' => $link]];
        }
        return [$d, ['ok' => false, 'why' => 'unknown']];
    });
    return is_array($out) ? $out : ['ok' => false, 'why' => 'save'];
}

/**
 * Items deleted in the admin (admin_forget_items()): their links end for good, so a new item made later at the same address
 * doesn't inherit them (the link was given for the deleted one's files).
 */
function m3d_shares_forget(string $kind, array $slugs): void
{
    if (!$slugs || !is_file(M3D_PRIVATE . '/data/shares.json')) return;
    $now = time();
    m3d_shares_change(fn($list) => array_map(fn($s) => ($s['kind'] ?? '') === $kind && in_array((string) ($s['slug'] ?? ''), array_map('strval', $slugs), true) && empty($s['gone'])
        ? $s + ['gone' => $now] : $s, $list));
}

/** "4 Hours", "2 Days", "12 Minutes": the time left of a link, for the admin list. */
function m3d_share_left_text(int $secs): string
{
    if ($secs >= 172800) return m3d_plural(intdiv($secs, 86400), 'day');
    if ($secs >= 7200) return m3d_plural(intdiv($secs, 3600), 'hour');
    return m3d_plural(max(1, intdiv($secs, 60)), 'minute');
}
