<?php
// Link check (Admin → Assets → Link check, admin/links.php, which also makes the page's rows): is every download link still alive?
// Only MEGA and transfer.it links by default; links on other websites only once the admin adds the website (settings.json → linkcheck.sites).
// The admin presses "Check all links" (or "Check again" on one row) and admin.js asks links.php to check the links one by one; with the
// automatic check on (settings.json → linkcheck.auto / every), lc_auto_tick() also checks a few due links after a page now and then.
// MEGA (file / folder links) and transfer.it (MEGA's transfer service) are asked to MEGA's own API, without logging in and without
// downloading: one small request per link. Any other address must answer (HEAD, else the first bytes of a GET).
// The results are kept in data/linkcheck.json: {links: {md5(url): {st: ok|dead|unknown, why, at}}}; a changed address starts unchecked.
declare(strict_types=1);

const LC_STATES = ['ok', 'dead', 'unknown', 'new'];
const LC_SITES_MAX = 50;   // other websites the admin adds
const LC_MEGA_API = 'https://g.api.mega.co.nz/cs';
const LC_TRANSFER_API = 'https://bt7.api.mega.co.nz/cs';
const LC_AUTO_BATCH = 5;      // automatic check: links per run (after a page, one at a time)
const LC_AUTO_GAP = 600;      // seconds between two runs while links are due
const LC_EVERY_MAX = 90;      // days

/** The links Link check looks at: MEGA and transfer.it links, and links on the websites the admin added (lc_sites()). */
function lc_links(): array
{
    static $out = null;
    return $out ??= array_values(array_filter(lc_all_links(), fn($l) => lc_group($l['host']) !== 'other' || lc_site_added($l['url'])));
}

/**
 * Replace a Link Everywhere (Link Check → "Replace…"): every model and accessory download link and add-on, and every Gallery full size link,
 * whose address is $old gets $new (already checked by the caller). Reads the data fresh and saves each file it changes once.
 * Returns how many places changed, or null when a save failed.
 */
function lc_replace_everywhere(string $old, string $new): ?int
{
    $n = 0;
    foreach (['models', 'outfits'] as $kind) {
        $items = m3d_items($kind, true);
        $hit = 0;
        foreach ($items as &$it) {
            foreach (['links', 'addons'] as $k) {
                if (!is_array($it[$k] ?? null)) continue;
                foreach ($it[$k] as &$l) if (is_array($l) && trim((string) ($l['url'] ?? '')) === $old) { $l['url'] = $new; $hit++; }
                unset($l);
            }
        }
        unset($it);
        if ($hit && !m3d_save_items($kind, $items)) return null;
        $n += $hit;
    }
    $g = m3d_gallery(true);
    $hit = 0;
    foreach ($g['stills'] as &$st) if (is_array($st['link'] ?? null) && trim((string) ($st['link']['url'] ?? '')) === $old) { $st['link']['url'] = $new; $hit++; }
    unset($st);
    if ($hit && !m3d_save_gallery($g)) return null;
    return $n + $hit;
}

/** Every full (https) address among models' and accessories' download links and add-ons and Gallery full size links:
 *  [{h, url, kind, slug, name, sub, label, access, edit, host}] in the admin lists' order. */
function lc_all_links(): array
{
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    $add = function (array $l) use (&$out) {
        $url = trim((string) $l['url']);
        if (!preg_match('#^https?://#i', $url) && !m3d_s4_short($url)) return;   // pages of this site (add-ons like /mustardui/) aren't checked; "s4:" links are
        $out[] = ['h' => md5($url), 'url' => $url, 'host' => lc_host($url)] + $l;
    };
    foreach (['models', 'outfits'] as $kind) {
        foreach (m3d_items($kind) as $it) {
            $slug = (string) ($it['slug'] ?? '');
            $sub = $kind === 'models'
                ? trim(implode(' · ', array_filter([(string) ($it['franchise'] ?? ''), ($it['generation'] ?? '') !== '' ? 'Gen ' . $it['generation'] : ''])))
                : 'Accessory · ' . m3d_accessory_type($it);
            $base = ['kind' => $kind, 'slug' => $slug, 'name' => (string) ($it['name'] ?? $slug), 'sub' => $sub,
                     'edit' => '/admin/edit.php?' . ($kind === 'outfits' ? 'kind=outfits&slug=' : 'slug=') . rawurlencode($slug)];
            foreach ((array) ($it['links'] ?? []) as $l) {
                if (!is_array($l)) continue;
                $add($base + ['url' => (string) ($l['url'] ?? ''), 'label' => (string) ($l['label'] ?? 'Download'), 'access' => (($l['access'] ?? '') === 'public' ? 'Public' : 'Patrons') . (!empty($l['off']) ? ' · Hidden' : '')]);
            }
            foreach ((array) ($it['addons'] ?? []) as $a) {
                if (!is_array($a)) continue;
                $add($base + ['url' => (string) ($a['url'] ?? ''), 'label' => (string) ($a['label'] ?? 'Add-on'), 'access' => 'Add-on']);
            }
        }
    }
    foreach (m3d_gallery()['stills'] as $s) {
        if (!is_array($s['link'] ?? null) || ($s['link']['url'] ?? '') === '') continue;
        $name = m3d_still_name($s, 'Untitled picture');
        $add(['kind' => 'gallery', 'slug' => (string) $s['id'], 'name' => $name, 'sub' => 'Gallery' . (($s['date'] ?? '') !== '' ? ' · ' . $s['date'] : ''),
              'edit' => '/admin/gallery.php?still=' . rawurlencode((string) $s['id']), 'url' => (string) $s['link']['url'], 'label' => 'Full size', 'access' => ($s['link']['access'] ?? '') === 'public' ? 'Public' : 'Patrons']);
    }
    return $out;
}

/** What an address is: ['mega-file', handle, key] | ['mega-folder', handle, key] | ['transfer', handle, ''] | ['s4' (a MEGA S4 file), '', ''] | ['mega-other' | 'transfer-other' | 'web', '', '']. */
function lc_parse(string $url): array
{
    if (m3d_s4_short($url)) return ['s4', '', ''];
    $p = parse_url($url);
    $host = strtolower(preg_replace('/^www\./i', '', (string) ($p['host'] ?? '')));
    $path = (string) ($p['path'] ?? '');
    $frag = (string) ($p['fragment'] ?? '');
    if ($host === 'mega.nz' || $host === 'mega.co.nz' || $host === 'mega.io') {
        // new style: /file/<h>#<key>, /folder/<h>#<key>[/file/<sub>] (a file inside a folder: the folder is what is shared), /embed/<h>#<key>
        if (preg_match('#^/(file|folder|embed)/([A-Za-z0-9_-]{8})/?$#', $path, $m)) {
            $key = preg_replace('#/.*$#', '', $frag);
            return [$m[1] === 'folder' ? 'mega-folder' : 'mega-file', $m[2], $key];
        }
        // old style: /#!<h>!<key>, /#F!<h>!<key>, /embed#!<h>!<key>
        if (preg_match('#^(F?)!([A-Za-z0-9_-]{8})(?:!([A-Za-z0-9_-]*))?#', $frag, $m)) return [$m[1] === 'F' ? 'mega-folder' : 'mega-file', $m[2], $m[3] ?? ''];
        return ['mega-other', '', ''];   // password-protected (#P!…), albums, the home page…: nothing to ask MEGA without more
    }
    if ($host === 'transfer.it') {
        if (preg_match('#^/t/([A-Za-z0-9_-]{8,20})/?$#', $path, $m)) return ['transfer', $m[1], ''];
        return ['transfer-other', '', ''];
    }
    if (m3d_s4_on() && m3d_s4_parse($url)) return ['s4', '', ''];   // switched off: a full S4 address is sent as it is, so it is checked like any other
    return ['web', '', ''];
}

/** The host column: "MEGA file", "MEGA folder", "transfer.it", or the site's name. */
function lc_host(string $url): string
{
    return match (lc_parse($url)[0]) {
        'mega-file' => 'MEGA file', 'mega-folder' => 'MEGA folder', 'mega-other' => 'MEGA',
        'transfer', 'transfer-other' => 'transfer.it',
        's4' => 'MEGA S4',
        default => strtolower(preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST))),
    };
}

/** The chip a host belongs to: mega | transfer | other. */
function lc_group(string $host): string
{
    return str_starts_with($host, 'MEGA') ? 'mega' : ($host === 'transfer.it' ? 'transfer' : 'other');
}

/** settings.json → linkcheck: {sites, auto, every}. $changes: a key set to null, false or empty is removed. */
function lc_settings_save(array $changes): bool
{
    $cur = m3d_settings(true)['linkcheck'] ?? [];
    $cur = is_array($cur) ? $cur : [];
    $new = array_filter(array_merge($cur, $changes), fn($v) => $v !== null && $v !== '' && $v !== [] && $v !== false);
    return m3d_save_settings(['linkcheck' => $new ?: null]);
}

/** An address's website without "www.", lowercase ("mega.nz", "drive.google.com"). */
function lc_domain(string $url): string
{
    return strtolower(preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST)));
}

/** A website typed by the admin ("Patreon.com", "https://www.patreon.com/x") → "patreon.com", or '' when it isn't one. */
function lc_clean_domain(string $s): string
{
    $s = strtolower(trim($s));
    if ($s === '') return '';
    $d = str_contains($s, '://') ? lc_domain($s) : lc_domain('https://' . $s);
    return preg_match('/^(?=.{3,100}$)([a-z0-9-]+\.)+[a-z0-9-]{2,}$/', $d) ? $d : '';
}

/** The other websites the admin wants checked too (settings.json → linkcheck.sites): a website covers its parts (www., drive.google.com for google.com). */
function lc_sites(): array
{
    $l = m3d_settings()['linkcheck']['sites'] ?? [];
    return array_values(array_filter(array_map(fn($d) => lc_clean_domain((string) $d), is_array($l) ? $l : [])));
}

function lc_site_added(string $url): bool
{
    $d = lc_domain($url);
    foreach (lc_sites() as $s) if ($d === $s || str_ends_with($d, '.' . $s)) return true;
    return false;
}

/** The other websites found in the links and not added yet: [website => number of links], most links first. */
function lc_other_sites(): array
{
    $n = [];
    foreach (lc_all_links() as $l) {
        if (lc_group($l['host']) !== 'other' || lc_site_added($l['url']) || ($d = lc_domain($l['url'])) === '') continue;
        $n[$d] = ($n[$d] ?? 0) + 1;
    }
    arsort($n);
    return $n;
}

/** One request to MEGA's API: the answer to the first command (an array, or a negative error number), or null when MEGA didn't answer. */
function lc_mega_call(string $api, array $query, array $cmd, string &$err = ''): array|int|null
{
    $query = ['id' => random_int(1, 2147483647)] + $query;
    $ch = curl_init($api . '?' . http_build_query($query));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([$cmd]),
        CURLOPT_HTTPHEADER     => ['Content-Type: text/plain;charset=UTF-8', 'User-Agent: Mozilla/5.0 (compatible; site link check)'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch) ?: ($code !== 200 ? "HTTP $code" : '');
    curl_close($ch);
    if (!is_string($body) || $code !== 200) return null;
    $d = json_decode($body, true);
    if (is_int($d)) return $d;                          // the whole request refused (e.g. -3: try again)
    if (is_array($d) && array_key_exists(0, $d)) return is_int($d[0]) || is_array($d[0]) ? $d[0] : null;
    if ($err === '') $err = 'an answer the site doesn’t understand';
    return null;
}

/** A MEGA error number → [state, reason]. */
function lc_mega_error(int $e, bool $transfer): array
{
    return match ($e) {
        -9      => ['dead', $transfer ? 'Transfer not found: deleted or expired' : 'Not found: deleted, or the link is wrong'],
        -16     => ['dead', $transfer ? 'Transfer expired or blocked' : 'Taken down by MEGA (blocked)'],
        -8      => ['dead', 'Expired'],
        -2      => ['dead', 'MEGA doesn’t recognise this link (incomplete?)'],
        -11     => ['unknown', $transfer ? 'MEGA refused (password-protected transfer?)' : 'MEGA refused access'],
        -3, -4, -6, -18 => ['unknown', 'MEGA asked to slow down: try again later'],
        default => ['unknown', "MEGA answered error $e"],
    };
}

/** A file's name from its encrypted attributes, with the key from the link (empty when it can't be read). */
function lc_mega_name(string $at, string $key): string
{
    $b64 = fn(string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    $k = $b64($key);
    $a = $b64($at);
    if (!is_string($k) || strlen($k) !== 32 || !is_string($a) || $a === '' || strlen($a) % 16) return '';
    $aes = substr($k, 0, 16) ^ substr($k, 16, 16);   // a file key = the AES key XOR the nonce + MAC half
    $plain = openssl_decrypt($a, 'aes-128-cbc', $aes, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
    if (!is_string($plain) || !str_starts_with($plain, 'MEGA{')) return '';
    $j = json_decode(rtrim(substr($plain, 4), "\0"), true);
    return is_array($j) && is_string($j['n'] ?? null) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $j['n'])), 0, 120) : '';
}

/** Checks one address: ['st' => ok|dead|unknown, 'why' => text for the admin, 'slow' => true when MEGA asked to wait]. */
function lc_check(string $url): array
{
    [$type, $h, $key] = lc_parse($url);
    $err = '';
    if ($type === 'mega-other') return ['st' => 'unknown', 'why' => str_starts_with((string) parse_url($url, PHP_URL_FRAGMENT), 'P!') ? 'Password-protected MEGA link: it can’t be checked' : 'Not a MEGA file or folder link: it can’t be checked'];
    if ($type === 'transfer-other') return ['st' => 'unknown', 'why' => 'Not a transfer link (transfer.it/t/…): it can’t be checked'];
    if ($type === 's4') return lc_check_s4($url);
    if ($type === 'mega-file' || $type === 'mega-folder' || $type === 'transfer') {
        $r = match ($type) {
            'mega-file'   => lc_mega_call(LC_MEGA_API, [], ['a' => 'g', 'p' => $h], $err),
            'mega-folder' => lc_mega_call(LC_MEGA_API, ['n' => $h], ['a' => 'f', 'c' => 1, 'r' => 1, 'ca' => 1], $err),
            'transfer'    => lc_mega_call(LC_TRANSFER_API, ['v' => 3, 'domain' => 'transferit', 'bc' => 1], ['a' => 'xi', 'xh' => $h], $err),
        };
        if ($r === null) return ['st' => 'unknown', 'why' => 'MEGA didn’t answer' . ($err !== '' ? " ($err)" : ''), 'slow' => true];
        return lc_mega_result($type, $r, $key);
    }
    return lc_check_web($url);
}

/** What MEGA's answer ($r: the first command's result) says about a link of this type (mega-file | mega-folder | transfer). */
function lc_mega_result(string $type, array|int $r, string $key): array
{
    if (is_int($r)) {
        [$st, $why] = $r < 0 ? lc_mega_error($r, $type === 'transfer') : ['unknown', "MEGA answered $r"];
        return ['st' => $st, 'why' => $why, 'slow' => in_array($r, [-3, -4, -6, -18], true)];
    }
    $noKey = $type !== 'transfer' && $key === '' ? ' · no key in the link: people will be asked for it' : '';
    if ($type === 'mega-file') {
        $name = lc_mega_name((string) ($r['at'] ?? ''), $key);
        $size = (int) ($r['s'] ?? 0);
        $what = implode(' · ', array_filter([$name, $size > 0 ? m3d_size($size) : '']));
        return ['st' => 'ok', 'why' => ($what !== '' ? $what : 'File found') . $noKey];
    }
    if ($type === 'mega-folder') {
        $files = 0;
        $bytes = 0;
        foreach ((array) ($r['f'] ?? []) as $n) if (is_array($n) && (int) ($n['t'] ?? -1) === 0) { $files++; $bytes += (int) ($n['s'] ?? 0); }
        return ['st' => 'ok', 'why' => ($files ? m3d_plural($files, 'file') . ($bytes > 0 ? ' · ' . m3d_size($bytes) : '') : 'Folder found (empty?)') . $noKey];
    }
    return ['st' => 'ok', 'why' => 'Transfer found'];
}

/** A MEGA S4 file (signed with the keys of Settings → Features → MEGA S4): asks for its first byte. */
function lc_check_s4(string $url): array
{
    if (!m3d_s4_on()) return ['st' => 'unknown', 'why' => 'MEGA S4 downloads are off (Settings → Features): this link doesn’t download'];
    if (!m3d_s4_parse($url)) return ['st' => 'unknown', 'why' => m3d_s4_error_text('no bucket')];
    $r = m3d_s4_probe($url);
    if ($r['ok']) {
        $name = basename((string) m3d_s4_parse($url)[2]);
        return ['st' => 'ok', 'why' => $name . ($r['size'] > 0 ? ' · ' . m3d_size($r['size']) : '')];
    }
    if ($r['code'] === 0) return ['st' => 'unknown', 'why' => 'S4 didn’t answer (' . $r['err'] . ')'];
    $dead = in_array($r['err'], ['NoSuchKey', 'NoSuchBucket'], true) || $r['code'] === 404;
    return ['st' => $dead ? 'dead' : 'unknown', 'why' => m3d_s4_error_text($r['err'])];
}

/** Any other address: it must answer. 2xx / 3xx = works, 404 / 410 or an address that doesn't exist = dead, anything else = couldn't check. */
function lc_check_web(string $url): array
{
    $try = function (bool $head) use ($url): array {
        $ch = curl_init($url);
        $got = 0;
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => $head,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => ['User-Agent: Mozilla/5.0 (compatible; site link check)', 'Range: bytes=0-1023'],
            // a GET stops after the first bytes: a big file is never downloaded
            CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$got) { $got += strlen($data); return $got > 65536 ? 0 : strlen($data); },
        ]);
        curl_exec($ch);
        $out = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), curl_errno($ch), curl_error($ch)];
        curl_close($ch);
        return $out;
    };
    [$code, $errno, $err] = $try(true);
    if ($errno === 0 && ($code === 405 || $code === 403 || $code === 400 || $code === 501 || $code === 404)) [$code, $errno, $err] = $try(false);   // some sites refuse HEAD
    if ($errno === CURLE_WRITE_ERROR) $errno = 0;   // stopped on purpose after the first bytes
    if ($errno === CURLE_COULDNT_RESOLVE_HOST) return ['st' => 'dead', 'why' => 'This website’s address doesn’t exist'];
    if ($errno !== 0) return ['st' => 'unknown', 'why' => 'No answer (' . ($err ?: "error $errno") . ')'];
    if ($code >= 200 && $code < 400) return ['st' => 'ok', 'why' => "Page answers (HTTP $code)"];
    if ($code === 404 || $code === 410) return ['st' => 'dead', 'why' => "Page not found (HTTP $code)"];
    return ['st' => 'unknown', 'why' => "The website answered HTTP $code"];
}

/** The saved results, keyed by md5(url). */
function lc_results(): array
{
    $d = m3d_read_json('linkcheck') ?? [];
    return is_array($d['links'] ?? null) ? $d['links'] : [];
}

/** Saves one result; results of addresses no longer used anywhere are dropped at the same time. */
function lc_save(string $h, array $res): void
{
    $keep = array_flip(array_column(lc_links(), 'h'));
    m3d_json_update('linkcheck', function (?array $d) use ($h, $res, $keep) {
        $links = array_intersect_key(is_array($d['links'] ?? null) ? $d['links'] : [], $keep);
        $links[$h] = ['st' => $res['st'], 'why' => (string) $res['why'], 'at' => time()];
        return [['links' => $links] + (is_array($d) ? $d : []) + ['version' => 1], null];   // keeps `next` (automatic check)
    });
}

/** The item's links (download links and add-ons) whose last check said dead: [url => {why, at, label}], for the editor's red
 *  fields and the red rows of the Models / Accessories lists ($res: lc_results(), passed in when called for many items). */
function lc_dead_for(array $item, ?array $res = null): array
{
    $res ??= lc_results();
    $out = [];
    foreach ([...(array) ($item['links'] ?? []), ...(array) ($item['addons'] ?? [])] as $l) {
        $url = is_array($l) ? trim((string) ($l['url'] ?? '')) : '';
        $r = $url !== '' ? ($res[md5($url)] ?? null) : null;
        if (($r['st'] ?? '') !== 'dead' || (lc_group(lc_host($url)) === 'other' && !lc_site_added($url))) continue;
        $out[$url] = ['why' => (string) ($r['why'] ?? ''), 'at' => (int) ($r['at'] ?? 0), 'label' => trim((string) ($l['label'] ?? '')) ?: 'Download'];
    }
    return $out;
}

/** The links whose last check said dead (only addresses still in use). */
function lc_dead(): array
{
    $res = lc_results();
    return array_values(array_filter(lc_links(), fn($l) => ($res[$l['h']]['st'] ?? '') === 'dead'));
}

// ── Automatic check (Assets → Link check → Automatic Check; off unless the owner switches it on) ──
/** ['on' => bool, 'every' => days]: each link is checked again once its last check is older than that. */
function lc_auto(): array
{
    $c = m3d_settings()['linkcheck'] ?? [];
    $every = (int) ($c['every'] ?? 7);
    return ['on' => !empty($c['auto']), 'every' => max(1, min(LC_EVERY_MAX, $every))];
}

/** Called by layout_start() and admin_start(): when a run is due (data/linkcheck.json → `next`), checks a few links after the page. */
function lc_auto_tick(): void
{
    if (!lc_auto()['on']) return;
    $next = (m3d_read_json('linkcheck') ?? [])['next'] ?? 0;
    if (is_int($next) && $next > time()) return;
    m3d_after_response(fn() => lc_auto_run());
}

/** One automatic run (under a lock): the LC_AUTO_BATCH links checked longest ago (never checked first), one at a time; then sets `next`. */
function lc_auto_run(): void
{
    m3d_with_lock('.linkcheck-run.lock', function () {   // another request on it: nothing to do
        try {
            // read again under the lock: a run that ended since this page looked has already moved `next`
            $next = (m3d_read_json('linkcheck') ?? [])['next'] ?? 0;
            if (is_int($next) && $next > time()) return;
            ignore_user_abort(true);
            @set_time_limit(180);
            $every = lc_auto()['every'] * 86400;
            $due = lc_auto_due($every);
            $slow = false;
            $was = lc_results();
            $dead = [];   // found dead now, and not before: one Discord post (Settings → Notifications → Dead Links)
            foreach (array_slice($due, 0, LC_AUTO_BATCH) as $i => $url) {
                if ($i) sleep(1);
                $res = lc_check($url);
                // MEGA didn't answer or asked to slow down: nothing saved, so the last real result (a dead link stays red) is kept
                // and the link stays due; the rest waits for the next run
                if (!empty($res['slow'])) { $slow = true; break; }
                lc_save(md5($url), $res);
                if ($res['st'] === 'dead' && ($was[md5($url)]['st'] ?? '') !== 'dead') $dead[md5($url)] = true;
            }
            m3d_notify_dead_links(array_values(array_filter(lc_links(), fn($l) => isset($dead[$l['h']]))));
            // more due: again in a while; else when the oldest result gets old enough
            $left = lc_auto_due($every);
            if ($left) $next = time() + ($slow ? 3 * LC_AUTO_GAP : LC_AUTO_GAP);
            else {
                $res = lc_results();
                $ats = [];
                foreach (lc_links() as $l) if (isset($res[$l['h']]['at'])) $ats[] = (int) $res[$l['h']]['at'];
                $next = max(time() + LC_AUTO_GAP, ($ats ? min($ats) : time()) + $every);
            }
            lc_set_next($next);
        } catch (Throwable $ex) {
            m3d_problem('link check', $ex->getMessage());
            lc_set_next(time() + 6 * LC_AUTO_GAP);
        }
    });
}

/** The addresses an automatic run should check, oldest result first (never checked first). */
function lc_auto_due(int $every): array
{
    $res = lc_results();
    $due = [];
    foreach (lc_links() as $l) {
        if (isset($due[$l['url']])) continue;
        $at = (int) ($res[$l['h']]['at'] ?? 0);
        if ($at <= time() - $every) $due[$l['url']] = $at;
    }
    asort($due);
    return array_keys($due);
}

/** When the next automatic run may start (0 = at the next page). */
function lc_set_next(int $ts): void
{
    m3d_json_update('linkcheck', function (?array $d) use ($ts) {
        $d = is_array($d) ? $d : ['version' => 1, 'links' => []];
        $d['next'] = $ts;
        return [$d, null];
    });
}
