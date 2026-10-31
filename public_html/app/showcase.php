<?php
// Patron renders (the "Made a Render?" box, approved ones in the item's gallery): a paying patron shares a render they made with a model or accessory, from its page (auth/render.php);
// the creator approves or rejects it in Media → From Patrons (admin/images.php?tab=patrons, admin/_showcase.php). Approved renders show in the item's gallery
// row after the creator's pictures (showcase_tiles(), and in its viewer); "Also in Gallery" adds one to the Gallery as a still with `from` = the render's id (the still is the only record of that, so
// removing it in Admin → Gallery turns the switch off). Settings → Features → Patron Renders (settings.json → showcase.on, off by default).
// The creator can also add one by hand (+ Add Render in From Patrons, showcase_add() in admin/_showcase.php: a render sent another way, e.g. on Discord):
// shown at once, `added` set; `by` is the Patreon id typed there, or '' (nobody's: no My Renders, no ban), `name` the name typed (or their known name, with `named` off).
// data/showcase.json: {renders: [{id (8 hex), kind (models | outfits), slug, src (/media/showcase/<ymd>-<hex>.webp, + -md/-sm/-xs), by (Patreon id, '' for one the creator added for nobody),
//   name (Patreon name when sent), named (show the name), added? (added by the creator, already approved), link? {t: x | patreon, h: handle} (their profile, only kept with `named`: RENDER_LINKS),
//   full? (an https address of the picture in full quality on another website, m3d_render_full_clean(): only a link, never loaded by the site;
//   the creator may also set a file in the MEGA S4 bucket, "s4:<file>" or its full S4 address: sent through /get/still/r/<id>/, render_full_dl()),
//   caption, at (unix), ok? (approved), ok_at?, hidden? (approved, then hidden by the creator in From Patrons: kept, shown nowhere but the
//   author's My Renders, its Gallery copy hidden too: m3d_render_hidden()), pos? (its place among the item's shown renders, set by dragging in
//   From Patrons: m3d_renders_order())}]}, newest first.
// Personal data (privacy.php, Personal Data): the picture, the Patreon id and name, the caption, the profile link, the full-quality link. A rejected or removed render is deleted
// with its files at once. Written only through m3d_renders_update() (m3d_json_update: no per-save copies); restored with the counters.
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

const SHOWCASE_PER_DAY = 3;          // renders one patron may send in 24 hours, until Settings → Patron Renders sets another number (0 = no limit)
const SHOWCASE_PER_DAY_MAX = 50;
const SHOWCASE_WAITING = 5;          // renders of one patron waiting for approval at once
const SHOWCASE_BYTES = 25 << 20;     // the picture as sent
const SHOWCASE_CAPTION = 120;
const SHOWCASE_FULL_MAX = 500;     // the full-quality link's length
const SHOWCASE_DIR = '/media/showcase';
const SHOWCASE_ICON = '<svg class="bi" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m4 18 5-5 4 4 3-3 4 4"/></svg>';
const SHOWCASE_ERRORS = [
    'session' => 'Your session expired. Please try again.',
    'link' => 'That link doesn’t look like an X or Patreon profile. Type your username or paste your profile’s address.',
    'login' => 'Log in with Patreon to share a render.',
    'patron' => 'Sharing renders is for patrons on a paid tier.',
    'preview' => 'Previewing as a patron: nothing was sent.',
    'off' => 'Renders can’t be shared here right now.',
    'consent' => 'Tick the box to confirm you made the render and it may be shown.',
    'pic' => 'Choose a picture (JPG, PNG or WebP).',
    'full' => 'The full-quality link must be a web address starting with https://.',
    'big' => 'The picture is too big: at most {mb} MB.',
    'large' => 'This picture is too large for the server. Try a smaller copy (4K is plenty).',
    'day' => 'You shared {day} in the last 24 hours. Please try again tomorrow.',
    'waiting' => 'You have ' . SHOWCASE_WAITING . ' renders waiting for approval. Please wait until the creator has looked at them.',
    'save' => 'The render couldn’t be saved. Please try again.',
    'gone' => 'This render is no longer here.',
];

/** The largest picture a patron can send: SHOWCASE_BYTES, or less when the host's PHP accepts less (upload_max_filesize, post_max_size). */
function m3d_showcase_max(): int
{
    $ini = function (string $k): int {
        $v = trim((string) ini_get($k));
        $n = (int) $v;
        $u = strtolower(substr($v, -1));
        return $n <= 0 ? PHP_INT_MAX : $n * ($u === 'g' ? 1 << 30 : ($u === 'm' ? 1 << 20 : ($u === 'k' ? 1 << 10 : 1)));
    };
    return max(1 << 20, min(SHOWCASE_BYTES, $ini('upload_max_filesize'), $ini('post_max_size') - (64 << 10)));   // the form's other fields need a little room
}

/** settings.json → showcase: {on, per_day (0 = no limit)}. */
function m3d_showcase_settings(): array
{
    $s = m3d_settings()['showcase'] ?? [];
    $s = is_array($s) ? $s : [];
    return [
        'on' => !empty($s['on']),
        'per_day' => isset($s['per_day']) ? max(0, min(SHOWCASE_PER_DAY_MAX, (int) $s['per_day'])) : SHOWCASE_PER_DAY,
    ];
}

/** Saves some keys of the settings above (the others are kept). */
function m3d_showcase_settings_save(array $changes): bool
{
    return m3d_save_setting_keys('showcase', $changes);
}

function m3d_showcase_on(): bool
{
    return m3d_showcase_settings()['on'];
}

/** Whether a patron who sent these renders (theirs, any time) reached the day's limit. */
function m3d_showcase_day_full(array $mine): bool
{
    $max = m3d_showcase_settings()['per_day'];
    return $max > 0 && count(array_filter($mine, fn($r) => (int) ($r['at'] ?? 0) > time() - 86400)) >= $max;
}

/** Every render, newest first (only well-formed ones). */
function m3d_renders(bool $fresh = false): array
{
    static $memo = null;
    if ($memo !== null && !$fresh) return $memo;
    $d = m3d_read_json('showcase') ?? [];
    return $memo = array_values(array_filter((array) ($d['renders'] ?? []), fn($r) => is_array($r) && preg_match('/^[0-9a-f]{8}$/D', (string) ($r['id'] ?? ''))
        && isset(M3D_KINDS[$r['kind'] ?? '']) && is_string($r['slug'] ?? null) && is_string($r['src'] ?? null)));
}

/** Locked change: $fn(array $renders) returns [$renders, $result]; null = not saved (damaged file, write failed). */
function m3d_renders_update(callable $fn): mixed
{
    $out = m3d_json_update('showcase', function (?array $d) use ($fn) {
        [$renders, $res] = $fn(array_values(array_filter((array) ($d['renders'] ?? []), 'is_array')));
        return [['renders' => array_values($renders)], $res];
    });
    m3d_renders(true);
    return $out;
}

/** What the bells and the admin menu need, without reading the renders: ['waiting' => [[id, at]…], 'ok' => {author's like code: [[id, ok_at]…]}]
 *  (waiting = the creator's bell; ok = the approved ones, for their author's bell, app/bell.php). */
function m3d_showcase_summary(): array
{
    $make = function () {
        $ok = [];
        foreach (m3d_renders() as $r) if (!empty($r['ok']) && empty($r['hidden']) && ($by = (string) ($r['by'] ?? '')) !== '') $ok[m3d_like_code($by)][] = [$r['id'], (int) ($r['ok_at'] ?? $r['at'] ?? 0)];
        return ['waiting' => array_map(fn($r) => [$r['id'], (int) ($r['at'] ?? 0)], array_values(array_filter(m3d_renders(), fn($r) => empty($r['ok'])))), 'ok' => $ok];
    };
    $s = m3d_data_summary('showcase', $make);
    if (!array_key_exists('ok', $s)) $s = m3d_data_summary('showcase', $make, true);   // saved before `ok` existed
    return $s + ['waiting' => [], 'ok' => []];
}

/** Privacy page / Personal Data: renders are stored (the feature may be off by now). */
function m3d_showcase_stored(): bool
{
    return m3d_renders() !== [];
}

function m3d_render(string $id): ?array
{
    foreach (m3d_renders() as $r) if ($r['id'] === $id) return $r;
    return null;
}

/** Where the creator reviews patron renders: Media → From Patrons (admin/images.php), at one render's card when given. */
function m3d_showcase_admin_url(string $id = ''): string
{
    return '/admin/images.php?tab=patrons' . ($id !== '' ? '#r-' . $id : '');
}

/** The creator hid this render (Media → From Patrons → Hide): off its item's page and, through m3d_still_view(), the Gallery. */
function m3d_render_hidden(string $id): bool
{
    return !empty(m3d_render($id)['hidden']);
}

/** The credit under a render: "Emily R.", or "A patron" when they chose not to show their name. */
function m3d_render_by(array $r): string
{
    return !empty($r['named']) && trim((string) ($r['name'] ?? '')) !== '' ? (string) $r['name'] : 'A patron';
}

// The profile a patron may link from their name (the Share window's Link menu): label, address before the handle, SOCIAL_TYPES icon
const RENDER_LINKS = ['x' => ['X (Twitter)', 'https://x.com/'], 'patreon' => ['Patreon', 'https://www.patreon.com/']];

/**
 * The Share window's link: the network picked and what was typed ("@emily", "emily", or a pasted address, whose website wins over the menu).
 * [] = no link, null = not a profile address of X or Patreon. Only the handle is kept, so the address is always built by the site.
 */
function m3d_render_link_clean(string $t, string $v): ?array
{
    $v = trim($v);
    if ($v === '' || !isset(RENDER_LINKS[$t])) return $v === '' || $t === '' ? [] : null;
    if (preg_match('#^(https?://)?([\w.-]+\.[a-z]{2,})(/[^\s]*)?$#iD', $v, $m)) {   // a pasted address
        $host = preg_replace('/^(www|mobile|m)\./', '', strtolower($m[2]));
        $t = in_array($host, ['x.com', 'twitter.com'], true) ? 'x' : ($host === 'patreon.com' ? 'patreon' : '');
        if ($t === '') return null;
        $u = parse_url('https://' . $host . ($m[3] ?? ''));
        $seg = array_values(array_filter(explode('/', (string) ($u['path'] ?? ''))));
        if ($t === 'patreon' && ($seg[0] ?? '') === 'user') {   // patreon.com/user?u=123
            parse_str((string) ($u['query'] ?? ''), $q);
            $v = is_string($q['u'] ?? null) && preg_match('/^\d{1,20}$/D', $q['u']) ? 'u:' . $q['u'] : '';
        } else {
            $v = $t === 'patreon' && ($seg[0] ?? '') === 'c' ? ($seg[1] ?? '') : ($seg[0] ?? '');
        }
    }
    $v = ltrim($v, '@');
    $ok = $t === 'x' ? preg_match('/^[A-Za-z0-9_]{1,15}$/D', $v) && !in_array(strtolower($v), ['home', 'i', 'intent', 'search', 'explore', 'settings', 'share'], true)
        : preg_match('/^(u:\d{1,20}|[A-Za-z0-9_-]{1,64})$/D', $v) && !in_array(strtolower($v), ['home', 'login', 'signup', 'search', 'c', 'user', 'messages', 'settings'], true);
    return $ok ? ['t' => $t, 'h' => $v] : null;
}

/** A render's profile link for the page: {name, url, net (RENDER_LINKS key), label}, or null (none, or the name isn't shown). */
function m3d_render_link(array $r): ?array
{
    $l = $r['link'] ?? null;
    if (empty($r['named']) || m3d_render_by($r) === 'A patron' || !is_array($l) || !isset(RENDER_LINKS[$l['t'] ?? '']) || !is_string($l['h'] ?? null)) return null;
    $h = $l['h'];
    $url = str_starts_with($h, 'u:') ? RENDER_LINKS['patreon'][1] . 'user?u=' . rawurlencode(substr($h, 2)) : RENDER_LINKS[$l['t']][1] . rawurlencode($h);
    return ['name' => m3d_render_by($r), 'url' => $url, 'net' => $l['t'], 'label' => ($l['t'] === 'x' ? 'X' : 'Patreon') . (str_starts_with($h, 'u:') ? '' : ': @' . $h)];
}

/** The network's icon (Simple Icons path from app/social.php) as a small inline SVG. */
function render_link_icon(string $net): string
{
    $d = SOCIAL_TYPES[$net][2] ?? '';
    return $d === '' ? '' : '<svg class="sc-net" viewBox="0 0 24 24" aria-hidden="true"><path d="' . e($d) . '"/></svg>';
}

/** The ids of the renders that are in the Gallery: still id => render id. */
function m3d_render_stills(): array
{
    $out = [];
    foreach (m3d_gallery()['stills'] as $s) if (!empty($s['from'])) $out[$s['id']] = (string) $s['from'];
    return $out;
}

/** The row shows on an item's page: the feature is on and its pictures are shown in full to this visitor. */
function m3d_showcase_here(string $kind, array $item): bool
{
    return m3d_showcase_on() && m3d_can_view($kind, $item) && !m3d_pics_hidden($kind, $item);
}

/** '' = this visitor may share a render of the item now, else a SHOWCASE_ERRORS code, or 'banned' (Admin → Access: m3d_ban_url()). */
function m3d_render_can_share(string $kind, array $item): string
{
    if (!m3d_showcase_here($kind, $item) || !m3d_listed($item) || m3d_release_phase($item) === 'soon') return 'off';
    $p = m3d_patron($GLOBALS['M3D']);
    if (!$p) return 'login';
    if (!empty($p['preview'])) return 'preview';
    if (m3d_banned($p, 'renders')) return 'banned';   // Admin → Access
    if (empty($p['access'])) return 'patron';
    return '';
}

/**
 * Saves the sent picture ($_FILES['pic']) in media/showcase/ as WebP with its smaller versions (img_make_versions(): at most
 * IMG_LARGE wide, so a 4K render stays sharp in the viewer). Returns [address, ''] or ['', error code].
 */
function m3d_showcase_save_pic(mixed $f): array
{
    if (!is_array($f) || is_array($f['tmp_name'] ?? null)) return ['', 'pic'];
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return ['', 'big'];
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) return ['', 'pic'];
    $tmp = (string) $f['tmp_name'];
    if ((int) ($f['size'] ?? 0) > m3d_showcase_max()) return ['', 'big'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $info = @getimagesize($tmp);
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$info) return ['', 'pic'];
    if (!img_supported() || !img_fits_memory((int) $info[0], (int) $info[1])) return ['', 'large'];
    $dir = M3D_SITE . SHOWCASE_DIR;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['', 'save'];
    try {
        $name = img_make_versions($tmp, $dir, gmdate('ymd') . '-' . bin2hex(random_bytes(4)));
    } catch (Throwable $ex) {
        m3d_problem('Patron renders', 'A render could not be saved: ' . $ex->getMessage());
        return ['', 'save'];
    }
    return [SHOWCASE_DIR . '/' . $name, ''];
}

/** A caption as typed: one line, no control or direction characters (like names), at most SHOWCASE_CAPTION characters. */
function m3d_render_caption(mixed $v): string
{
    $cap = mb_scrub(is_string($v) ? $v : '', 'UTF-8');
    $cap = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}]/u', '', $cap);
    return rtrim(mb_substr(trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $cap)), 0, SHOWCASE_CAPTION));
}

/** The "Full Quality Link" as typed: '' = none, null = not an https web address (no user:password@, a host with a dot).
 *  $s4 (the creator's forms in From Patrons, Link Check's Replace…): a short link to a file in the MEGA S4 bucket, "s4:<file>", too. */
function m3d_render_full_clean(string $u, bool $s4 = false): ?string
{
    $u = trim($u);
    if ($u === '') return '';
    if ($s4 && preg_match('#^s4:/*([^"<>\x00-\x1f]+)$#iD', $u, $m)) return strlen($u) > SHOWCASE_FULL_MAX ? null : 's4:' . trim($m[1]);   // like clean_dl_url()
    if (strlen($u) > SHOWCASE_FULL_MAX || preg_match('/[\s<>"]/', $u) || !filter_var($u, FILTER_VALIDATE_URL)) return null;
    $p = parse_url($u);
    if (strtolower($p['scheme'] ?? '') !== 'https' || isset($p['user']) || isset($p['pass']) || !str_contains((string) ($p['host'] ?? ''), '.')) return null;
    return $u;
}

/** The image host suggested under the Share window's Full Quality Link: the one of Settings → Bug Reports (https only), else none. */
function m3d_showcase_host(): string
{
    $h = trim((string) (m3d_bug_settings()['host'] ?? ''));
    return preg_match('#^https://[^\s"<>]+$#D', $h) ? $h : '';
}

/** The viewer's "Download Full Size" for a render with a full-quality link: it opens the patron's own address in a new tab. A file in the MEGA
 *  S4 bucket (m3d_render_full_s4()) goes through /get/still/r/<id>/ instead, which sends a fresh signed address (auth/get-still.php). */
function render_full_dl(array $r): ?array
{
    $u = (string) ($r['full'] ?? '');
    if ($u === '') return null;
    if (m3d_render_full_s4($u)) return m3d_download_url($u) === null ? null : ['href' => '/get/still/r/' . $r['id'] . '/', 'size' => '', 'locked' => false, 'note' => 'Full quality'];
    return m3d_s4_short($u) ? null : ['href' => $u, 'size' => '', 'locked' => false, 'ext' => true, 'note' => 'Full quality on ' . preg_replace('/^www\./', '', (string) parse_url($u, PHP_URL_HOST))];
}

/** A render's full-quality link is a file in a MEGA S4 bucket that the site signs (S4 downloads on): "s4:<file>" or a full S4 address. */
function m3d_render_full_s4(string $u): bool
{
    return m3d_s4_on() && m3d_s4_parse($u) !== null;
}

/** Deletes a render's picture and its versions (only files in media/showcase/: a crop made in Admin → Gallery is there too). */
function m3d_showcase_delete_files(array $srcs): void
{
    foreach (array_unique($srcs) as $src) {
        if (!str_starts_with((string) $src, SHOWCASE_DIR . '/') || !($p = media_path((string) $src))) continue;
        foreach (img_versions((string) $src) as $v) if ($vp = media_path($v)) @unlink($vp);
        @unlink($p);
    }
}

/**
 * Removes the renders $match(render) picks: their records, their stills in the Gallery (with those stills' likes) and their files.
 * Returns how many went, or null when the renders file couldn't be saved.
 */
function m3d_renders_delete(callable $match): ?int
{
    $gone = m3d_renders_update(function (array $rs) use ($match) {
        $out = $keep = [];
        foreach ($rs as $r) if ($match($r)) $out[] = $r; else $keep[] = $r;
        return [$keep, $out];
    });
    if ($gone === null) return null;
    if (!$gone) return 0;
    $ids = array_column($gone, 'id');
    $srcs = array_column($gone, 'src');
    $g = m3d_gallery(true);
    $drop = array_values(array_filter($g['stills'], fn($s) => in_array((string) ($s['from'] ?? ''), $ids, true)));
    if ($drop) {
        $g['stills'] = array_values(array_filter($g['stills'], fn($s) => !in_array((string) ($s['from'] ?? ''), $ids, true)));
        if (m3d_save_gallery($g)) m3d_gallery_forget_likes(array_column($drop, 'id'));
        $srcs = array_merge($srcs, array_column($drop, 'src'));
    }
    m3d_showcase_delete_files($srcs);
    return count($gone);
}

/** Media → From Patrons' Add to / Take Out of the Gallery (a card's ⋯ menu; Also in Gallery of + Add Render): adds the approved render to the Gallery as a still (on top, dated today), or takes it out. */
function m3d_render_gallery(array $r, bool $on): bool
{
    $g = m3d_gallery(true);
    $old = $g['stills'];
    $has = array_values(array_filter($g['stills'], fn($s) => (string) ($s['from'] ?? '') === $r['id']));
    if ($on === (bool) $has) return true;
    if ($on) {
        $taken = array_column($g['stills'], 'id');
        do { $id = bin2hex(random_bytes(4)); } while (in_array($id, $taken, true));
        array_unshift($g['stills'], ['id' => $id, 'src' => $r['src'], 'caption' => m3d_render_still_caption($r), 'date' => m3d_today(),
            'chars' => $r['kind'] === 'models' ? [$r['slug']] : [], 'from' => $r['id']] + (!empty($r['full']) ? ['link' => ['url' => (string) $r['full'], 'access' => 'public']] : []));
    } else {
        $g['stills'] = array_values(array_filter($g['stills'], fn($s) => (string) ($s['from'] ?? '') !== $r['id']));
    }
    if (!m3d_save_gallery($g)) return false;
    if ($on) m3d_discord_stills_after_save($old, $g['stills']);   // like any new public picture: the Gallery's Discord post, when that is on
    else m3d_gallery_forget_likes(array_column($has, 'id'));
    return true;
}

/** The caption of a render's Gallery copy: "Its caption · By Name". */
function m3d_render_still_caption(array $r): string
{
    $by = 'By ' . (m3d_render_by($r) === 'A patron' ? 'a patron' : m3d_render_by($r));
    $cap = trim((string) ($r['caption'] ?? ''));
    return mb_substr($cap !== '' ? "$cap · $by" : $by, 0, 200);
}

/**
 * A render edited in From Patrons' window ($old → $new): its Gallery copy follows what it took from the render, unless that was changed in
 * Admin → Gallery since: the caption, the model among its characters, the full size link. Nothing to do = true.
 */
function m3d_render_gallery_sync(array $old, array $new): bool
{
    $g = m3d_gallery(true);
    $changed = false;
    foreach ($g['stills'] as &$s) {
        if ((string) ($s['from'] ?? '') !== $new['id']) continue;
        $was = $s;
        if ((string) ($s['caption'] ?? '') === m3d_render_still_caption($old)) $s['caption'] = m3d_render_still_caption($new);
        $chars = array_values(array_filter((array) ($s['chars'] ?? []), fn($c) => !($old['kind'] === 'models' && $c === $old['slug'])));
        if ($new['kind'] === 'models' && !in_array($new['slug'], $chars, true)) array_unshift($chars, $new['slug']);
        $s['chars'] = $chars;
        $oldFull = (string) ($old['full'] ?? ''); $newFull = (string) ($new['full'] ?? '');
        $url = (string) ($s['link']['url'] ?? '');
        if ($url === $oldFull && $newFull !== $oldFull) {
            if ($newFull === '') unset($s['link']); else $s['link'] = ['url' => $newFull] + (array) ($s['link'] ?? []) + ['access' => 'public'];
        }
        $changed = $changed || $s !== $was;
    }
    unset($s);
    return !$changed || m3d_save_gallery($g);
}

/** Items deleted in the admin: their renders go with them (and their stills in the Gallery). */
function m3d_showcase_forget(string $kind, array $slugs): void
{
    if ($slugs && array_filter(m3d_renders(), fn($r) => $r['kind'] === $kind && in_array($r['slug'], $slugs, true))) {
        m3d_renders_delete(fn($r) => ($r['kind'] ?? '') === $kind && in_array($r['slug'] ?? '', $slugs, true));
    }
}

/** Short Discord post in the creator's channel (Settings → Notifications → Patron Renders): who, which item, no picture. */
function m3d_render_notify(array $r, array $item): void
{
    m3d_notify_post('renders', [['title' => mb_substr('New render of ' . $item['name'], 0, 250), 'url' => abs_url(m3d_showcase_admin_url($r['id'])),
        'description' => m3d_notify_plain((string) ($r['caption'] ?? ''), 200), 'color' => hexdec('e8b931'),
        'footer' => ['text' => 'From ' . mb_substr((string) ($r['name'] ?? ''), 0, 60) . (empty($r['named']) ? ' (name hidden on the site)' : '') . ' · waiting for you'],
        'sub' => '🖼️ New Patron Render · ⏳ Waiting for You',
        'facts' => [['From', '**' . m3d_notify_plain((string) ($r['name'] ?? ''), 60) . '**' . (empty($r['named']) ? ' (name hidden on the site)' : '')]]]]);
}

// ── Page pieces ───────────────────────────────────────────────────────────────
/**
 * Approved renders of one item in their order on its page: the ones not placed by the creator first (approved since the last drag, or all of
 * them before any), newest approved first, then the placed ones by `pos`.
 */
function m3d_renders_sorted(array $rs): array
{
    $rs = array_values($rs);
    usort($rs, fn($a, $b) => [isset($a['pos']) ? 1 : 0, (int) ($a['pos'] ?? 0), (int) ($b['ok_at'] ?? $b['at'] ?? 0)]
        <=> [isset($b['pos']) ? 1 : 0, (int) ($b['pos'] ?? 0), (int) ($a['ok_at'] ?? $a['at'] ?? 0)]);
    return $rs;
}

/**
 * From Patrons: puts one item's shown renders in the order of `$ids` (pos 1, 2…); shown renders of the item missing from `$ids`
 * (approved meanwhile) lose their place and go first; hidden ones keep theirs. `$ids` = [] takes every place away (back to newest first). False = not saved.
 */
function m3d_renders_order(string $kind, string $slug, array $ids): bool
{
    $at = array_flip(array_values(array_filter($ids, 'is_string')));
    return m3d_renders_update(function (array $rs) use ($kind, $slug, $at) {
        foreach ($rs as &$x) {
            if (($x['kind'] ?? '') !== $kind || ($x['slug'] ?? '') !== $slug || empty($x['ok'])) continue;
            if ($at && !empty($x['hidden'])) continue;   // a hidden one keeps its place for when it is shown again
            if (isset($at[$x['id'] ?? ''])) $x['pos'] = $at[$x['id']] + 1; else unset($x['pos']);
        }
        unset($x);
        return [$rs, true];
    }) !== null;
}

/**
 * This item's renders this visitor may see: the approved ones not hidden, in their order (m3d_renders_sorted()), then the waiting ones of `$mine` (the visitor's id,
 * '' = none), or every waiting one with `$allWaiting` (the creator), newest first.
 */
function m3d_item_renders(string $kind, array $item, string $mine, bool $allWaiting = false): array
{
    $here = array_filter(m3d_renders(), fn($r) => $r['kind'] === $kind && $r['slug'] === $item['slug']);
    $ok = m3d_renders_sorted(array_filter($here, fn($r) => !empty($r['ok']) && empty($r['hidden'])));
    $wait = array_values(array_filter($here, fn($r) => empty($r['ok']) && ($allWaiting || ($mine !== '' && (string) ($r['by'] ?? '') === $mine))));
    usort($wait, fn($a, $b) => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
    return array_merge($ok, $wait);
}

/** The logged-in visitor's own Patreon id, '' for guests and previews (their renders get Withdraw / Remove). */
function showcase_me(): string
{
    $p = m3d_patron($GLOBALS['M3D']);
    return $p && empty($p['preview']) ? (string) ($p['id'] ?? '') : '';
}

/** The creator looking at the site (not previewing as a patron): sees every waiting render on its item's page. */
function showcase_creator(): bool
{
    return showcase_me() !== '' && m3d_is_admin($GLOBALS['M3D']);
}

/** ' is-tall' for an upright picture: its tile is narrow, so "Waiting" shows as the clock alone beside the ✕ (site.css). */
function showcase_tall(string $src): string
{
    $d = img_dims(img_small($src));
    return $d && $d[1] > $d[0] * 1.2 ? ' is-tall' : '';
}

const SHOWCASE_EYE_OFF = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 5.1A9.8 9.8 0 0 1 12 5c5 0 9 4.5 10 7a13 13 0 0 1-3 4.2M6.6 6.6A13 13 0 0 0 2 12c1 2.5 5 7 10 7a9.6 9.6 0 0 0 4.4-1.1M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
const SHOWCASE_EYE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12c1-2.5 5-7 10-7s9 4.5 10 7c-1 2.5-5 7-10 7S3 14.5 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
const SHOWCASE_PAGE_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg>';
const SHOWCASE_CLOCK = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

/** The ✕ of a patron's own render (gallery tile, My Account): do=remove; `$back` = 'account' comes back to My Account. */
function showcase_remove_form(array $r, string $back = ''): string
{
    $wait = empty($r['ok']);
    return '<form class="sc-act" method="post" action="/auth/render.php" onsubmit="return confirm(' . e(json_encode($wait ? 'Withdraw this render?' : 'Remove your render from the site?')) . ')">'
        . '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '"><input type="hidden" name="do" value="remove"><input type="hidden" name="id" value="' . e($r['id']) . '">'
        . ($back !== '' ? '<input type="hidden" name="back" value="' . e($back) . '">' : '')
        . '<button class="sc-del" type="submit" title="' . ($wait ? 'Withdraw' : 'Remove') . '" aria-label="' . ($wait ? 'Withdraw your render' : 'Remove your render') . '">✕</button></form>';
}

/**
 * The renders as tiles of the item's gallery row (gallery_strip()'s $extra): the approved ones after the creator's pictures, with the patron's
 * name pill; the waiting ones at the start of the row, greyed with a dashed edge and "Waiting", only for their author and the creator. The
 * author's tiles have a ✕ (Withdraw / Remove), the creator's waiting ones a → to Media → From Patrons. Each opens the viewer at slide
 * $first + its place (approved first, then waiting). Returns [approved tiles, slides, waiting tiles]; nothing while renders are off here.
 */
function showcase_tiles(string $kind, array $item, int $first): array
{
    if (!m3d_showcase_here($kind, $item)) return ['', [], ''];
    $me = showcase_me();
    $creator = showcase_creator();
    $tiles = $waiting = '';
    $slides = [];
    foreach (m3d_item_renders($kind, $item, $me, $creator) as $r) {
        $wait = empty($r['ok']);
        $by = m3d_render_by($r);
        $cap = trim((string) ($r['caption'] ?? ''));
        $img = strip_img($r['src'], ($cap !== '' ? $cap . ', ' : '') . 'render of ' . $item['name'] . ' by ' . $by);
        $ini = $by === 'A patron' ? '<b class="anon" aria-hidden="true">✦</b>' : '<b aria-hidden="true">' . e(mb_strtoupper(mb_substr($by, 0, 1))) . '</b>';
        $link = m3d_render_link($r);
        $credit = $link ? '<a class="sc-by is-link" href="' . e($link['url']) . '" target="_blank" rel="nofollow ugc noopener" title="' . e($link['label']) . '">' . $ini . '<span>' . e($by) . '</span>' . render_link_icon($link['net']) . '</a>'
            : '<span class="sc-by">' . $ini . '<span>' . e($by) . '</span></span>';
        $tool = $me !== '' && (string) ($r['by'] ?? '') === $me ? showcase_remove_form($r)
            : ($wait && $creator ? '<a class="sc-act sc-del" href="' . e(m3d_showcase_admin_url($r['id'])) . '" title="Review in Media → From Patrons" aria-label="Review this render">→</a>' : '');
        $tile = '<div class="thumb sc-tile' . ($wait ? ' is-waiting' : '') . showcase_tall($r['src']) . '" id="r-' . e($r['id']) . '"><button type="button" class="sc-open" data-slide="' . ($first + count($slides)) . '" aria-label="' . e('Open the render by ' . $by) . '">' . $img . '</button>'
            . ($wait ? '<span class="sc-wait">' . SHOWCASE_CLOCK . '<span>Waiting</span></span>' : '') . $tool . $credit . '</div>';
        if ($wait) $waiting .= $tile; else $tiles .= $tile;
        $pre = $wait ? 'Waiting for approval · ' : '';
        $slides[] = ($link ? ['src' => $r['src'], 'caption' => $pre . $cap, 'by' => render_slide_by($link)]
            : ['src' => $r['src'], 'caption' => $pre . ($cap !== '' ? $cap . ' · ' : '') . 'By ' . ($by === 'A patron' ? 'a patron' : $by)]) + (($dl = render_full_dl($r)) ? ['dl' => $dl] : []);
    }
    return [$tiles, $slides, $waiting];
}

/**
 * "Made a Render?" on a model / accessory page (#patron-renders), under "Found a Bug?": the Share button with its window (or Log In /
 * Why Can't I Share?), then text lines only: "N patron renders in the gallery →" and the waiting ones ("Yours is
 * waiting for approval →", the creator's "N renders waiting for your approval →"), each to its first tile in the gallery row. The visitor's
 * own renders are managed on the tiles (✕) and in My Account (showcase_account()). Nothing when there is nothing to show and nothing to do.
 */
function showcase_box(string $kind, array $item): string
{
    if (!m3d_showcase_here($kind, $item)) return '';
    $me = showcase_me();
    $creator = showcase_creator();
    $list = m3d_item_renders($kind, $item, $me, $creator);
    $shown = array_values(array_filter($list, fn($r) => !empty($r['ok'])));
    $wait = array_values(array_filter($list, fn($r) => empty($r['ok'])));
    $why = m3d_render_can_share($kind, $item);
    $btn = '';
    if ($why === '') $btn = '<button class="btn btn-ghost btn-sm" type="button" data-sc-open>Share Yours</button>';
    elseif ($why === 'banned') $btn = '<a class="btn btn-ghost btn-sm" href="' . e(m3d_ban_url('renders')) . '">Why Can’t I Share?</a>';
    elseif ($why === 'login') $btn = '<a class="btn btn-ghost btn-sm" rel="nofollow" href="/auth/login.php?return=' . e(rawurlencode(m3d_item_url($kind, $item['slug']))) . '">Log In to Share</a>';
    if ($btn === '' && !$shown && !$wait && $why !== 'patron') return '';   // previews, items not out yet: nothing to show and nothing to do
    $h = '<section class="bug-box sc-box" id="patron-renders"><div class="bug-box-head"><h3>' . SHOWCASE_ICON . 'Made a Render?</h3>' . $btn . '</div>';
    if ($shown) $h .= '<a class="bug-box-more" href="#r-' . e($shown[0]['id']) . '">' . SHOWCASE_ICON . '<span>' . e(m3d_plural(count($shown), 'patron render')) . ' in the gallery →</span></a>';
    if ($wait) {
        $n = count($wait);
        $text = $creator ? m3d_plural($n, 'render') . ' waiting for your approval →' : ($n === 1 ? 'Yours is waiting for approval →' : "$n of yours waiting for approval →");
        $h .= '<a class="bug-box-more sc-box-wait" href="#r-' . e($wait[0]['id']) . '">' . str_replace('<svg ', '<svg class="bi" ', SHOWCASE_CLOCK) . '<span>' . e($text) . '</span></a>';
    }
    if ($why === 'patron') $h .= '<p class="sc-box-note">Patrons on any paid tier can share theirs.</p>';
    if ($why === '') $h .= showcase_dialog($kind, $item, m3d_patron($GLOBALS['M3D']));
    return $h . '</section>';
}

/**
 * My Account → My Renders (#my-renders): every render the visitor shared, waiting ones first (greyed, dashed, "Waiting"), each with the
 * item's name (a link to it on the item's page) and a ✕; one row with ‹ › that turns by itself (`data-strip-auto` in site.js). '' when
 * renders are off, while previewing, or when they have none.
 */
function showcase_account(): string
{
    $me = showcase_me();
    if ($me === '' || !m3d_showcase_on()) return '';
    $mine = array_values(array_filter(m3d_renders(), fn($r) => (string) ($r['by'] ?? '') === $me));
    if (!$mine) return '';
    usort($mine, fn($a, $b) => [!empty($a['ok']), !empty($a['hidden']), -(int) ($a['ok_at'] ?? $a['at'] ?? 0)] <=> [!empty($b['ok']), !empty($b['hidden']), -(int) ($b['ok_at'] ?? $b['at'] ?? 0)]);
    $tiles = '';
    foreach ($mine as $r) {
        $wait = empty($r['ok']);
        $hid = !$wait && !empty($r['hidden']);   // hidden by the creator: greyed with "Hidden", no link (it isn't on the item's page)
        $item = m3d_item($r['kind'], $r['slug']);
        $name = $item ? ($r['kind'] === 'models' ? m3d_model_name($item) : (string) $item['name']) : '';
        $url = $item && !$hid && m3d_showcase_here($r['kind'], $item) ? m3d_item_url($r['kind'], $r['slug']) . '#r-' . $r['id'] : '';
        $img = '<img src="' . e(img_small($r['src'])) . '" alt="' . e('Your render' . ($name !== '' ? ' of ' . $name : '')) . '" loading="lazy"' . img_ratio_style(img_small($r['src'])) . '>';
        $tiles .= '<div class="sc-acc-t' . ($wait ? ' is-waiting' : '') . ($hid ? ' is-hidden' : '') . showcase_tall($r['src']) . '">' . ($url !== '' ? '<a href="' . e($url) . '">' . $img . '</a>' : $img)
            . ($wait ? '<span class="sc-wait">' . SHOWCASE_CLOCK . '<span>Waiting</span></span>' : '')
            . ($hid ? '<span class="sc-wait sc-hid" title="The creator hid it from the site">' . SHOWCASE_EYE_OFF . '<span>Hidden</span></span>' : '') . showcase_remove_form($r, 'account')
            . ($name !== '' ? '<span class="sc-by"><span>' . e($name) . '</span></span>' : '') . '</div>';
    }
    $nWait = count(array_filter($mine, fn($r) => empty($r['ok'])));
    $nHid = count(array_filter($mine, fn($r) => !empty($r['ok']) && !empty($r['hidden'])));
    $nOk = count($mine) - $nWait - $nHid;
    $sum = implode(' · ', array_filter([$nWait ? $nWait . ' waiting for approval' : '', $nOk ? $nOk . ' shown' : '', $nHid ? $nHid . ' hidden' : '']));
    return '<section class="acc-sec" id="my-renders">' . section_head('renders', 'My Renders', $sum)
        . strip_html('like-strip sc-acc-strip', 'sc-acc-row', $tiles, 'renders', 'div', ' data-strip-auto') . '</section>';
}

/** The viewer's "By <name>" link (site.js): the name, the address, the icon's path. */
function render_slide_by(array $link): array
{
    return ['name' => $link['name'], 'url' => $link['url'], 'icon' => SOCIAL_TYPES[$link['net']][2] ?? '', 'title' => $link['label']];
}

/** The Share window: picture (preview in site.js), the optional full-quality link, caption, Show My Name, the consent box; posts to auth/render.php. */
function showcase_dialog(string $kind, array $item, array $p): string
{
    $name = trim((string) ($p['name'] ?? ''));
    $max = m3d_showcase_max();
    $pref = m3d_render_prefs($p);   // what the person set on their Settings page (Patron Renders)
    $lt = $pref['named'] ? (string) ($pref['link']['t'] ?? '') : '';
    return '<dialog class="sc-dlg" data-sc-dlg data-max="' . $max . '" aria-labelledby="sc-dlg-t"><form method="post" action="/auth/render.php" enctype="multipart/form-data">'
        . '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '"><input type="hidden" name="do" value="share">'
        . '<input type="hidden" name="kind" value="' . e($kind) . '"><input type="hidden" name="slug" value="' . e($item['slug']) . '">'
        . '<h2 id="sc-dlg-t">Share Your Render of ' . e($item['name']) . '</h2>'
        . '<label class="sc-drop" data-sc-drop><input type="file" name="pic" accept="image/jpeg,image/png,image/webp" required data-sc-file>'
        . '<img alt="" hidden data-sc-img><span class="sc-drop-t"><b data-sc-name>Choose a Picture…</b><span data-sc-meta>JPG, PNG or WebP, up to ' . showcase_mb($max) . ' MB. Drop it here or click.</span></span></label>'
        . '<label class="field"><span class="lbl">Full Quality Link <span class="muted small">(optional)</span></span>'
        . '<input class="input" type="url" name="full" maxlength="' . SHOWCASE_FULL_MAX . '" placeholder="https://…" autocomplete="off" inputmode="url"></label>'
        . '<p class="sc-hint">The page shows a smaller copy. For a full-quality download, upload your render to an image host'
        . (($host = m3d_showcase_host()) !== '' ? ' such as <a href="' . e($host) . '" target="_blank" rel="noopener">' . e(preg_replace('/^www\./', '', (string) parse_url($host, PHP_URL_HOST))) . ' ↗</a>' : '') . ' and paste its link here.</p>'
        . '<label class="field"><span class="lbl">Caption <span class="muted small">(optional)</span></span><input class="input" name="caption" maxlength="' . SHOWCASE_CAPTION . '" autocomplete="off"></label>'
        . '<label class="toggle"><input type="checkbox" class="switch" name="named" value="1"' . ($pref['named'] ? ' checked' : '') . ' data-sc-named><span>' . e($name !== '' ? "Show My Name ($name)" : 'Show My Name') . '</span></label>'
        . '<div class="field sc-link" data-sc-link><span class="lbl">Link on My Name <span class="muted small">(optional)</span></span><div class="sc-link-row">'
        . '<select class="input" name="link_t" aria-label="Network" data-sc-link-t><option value="">No Link</option>'
        . implode('', array_map(fn($k, $v) => '<option value="' . $k . '"' . ($k === $lt ? ' selected' : '') . '>' . e($v[0]) . '</option>', array_keys(RENDER_LINKS), RENDER_LINKS)) . '</select>'
        . '<input class="input" name="link_h" maxlength="200" autocomplete="off" placeholder="Your username or profile address" aria-label="Username or profile address"'
        . ($lt !== '' ? ' value="' . e(m3d_render_link_text($pref['link'])) . '"' : ' disabled') . ' data-sc-link-h></div></div>'
        . '<label class="sc-ok"><input type="checkbox" name="ok" value="1" required><span>I made this render with this ' . (m3d_kind_word($kind)) . ', and the creator may show it on this site.</span></label>'
        . '<p class="sc-note">The creator checks every render before it shows. You can remove yours from this page any time.</p>'
        . '<div class="sc-actions"><button class="btn btn-ghost" type="button" data-sc-close>Cancel</button><button class="btn btn-primary" type="submit">Send for Approval</button></div>'
        . '</form></dialog>';
}

/** The message after auth/render.php sent the visitor back (?render=sent|removed, ?rendererr=code). */
function showcase_flash(): void
{
    $ok = ['sent' => '✓ Render sent for review.', 'removed' => '✓ Render removed.'];
    if (isset($ok[$m = m3d_get_text('render')])) site_flash(e($ok[$m]));
    if (isset(SHOWCASE_ERRORS[$m = m3d_get_text('rendererr')])) site_flash(e(strtr(SHOWCASE_ERRORS[$m], [
        '{mb}' => showcase_mb(m3d_showcase_max()), '{day}' => m3d_plural(max(1, m3d_showcase_settings()['per_day']), 'render')])), 'err');
}

/** 25 → "25", 7.94 MB → "7.9". */
function showcase_mb(int $bytes): string
{
    return rtrim(rtrim(number_format(floor($bytes / 104857.6) / 10, 1, '.', ''), '0'), '.');
}
