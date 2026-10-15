<?php
// Bug reports (/bugs/, Admin → Bugs, Settings → Features → Bug reports). Off until turned on in the settings.
//
// data/bugs.json: {next, bugs: [bug]} written only through m3d_bugs_update() (locked read-modify-write, like the likes: no
// copy per save; the full backups carry it; a restore puts it back only with its own "Bug reports" box). A bug:
//   n (number), title, about ('models:<slug>' | 'outfits:<slug>' | 'mustardui' | 'site' | 'other'), about_name (the item's name
//   when reported, kept if it is removed), gone ('models' | 'outfits': the item was deleted, about is then 'other'), ver (version label), blender, mui (MustardUI version), body, steps, links [https…],
//   pics [/media/bugs/…webp] (only while uploads are on), by (Patreon id), name, at / act (reported / last activity, unix),
//   status (BUG_STATUSES), fixed (version label), reason (BUG_REASONS) + dup (bug number), tags [tag ids], metoo [like codes],
//   pin, hidden, pending (waits for the admin when "check new reports" is on), locked (no new comments), seen (admin opened it),
//   fol {like code: time last opened} (who follows it, see "Following"),
//   log [{t: 'c', by, name, at, text, admin} comment | {t: 's', at, status, fixed?, reason?, dup?} status | {t: 'tag', at, add []}]
// settings.json → bugs: {on, who: any|paying, host (where to upload screenshots), uploads, moderate, notify, webhook, per_day,
//   blocked [Patreon ids], tags [{id, name, color}]}
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const BUG_STATUSES = [
    'new'       => ['New', 'sky', '#7cc4ff'],
    'confirmed' => ['Confirmed', 'amber', '#e8b931'],
    'progress'  => ['In Progress', 'violet', '#b79cff'],
    'fixed'     => ['Fixed', 'green', '#6fcf8e'],
    'closed'    => ['Closed', 'grey', '#8d887f'],
];
const BUG_OPEN = ['new', 'confirmed', 'progress'];
const BUG_REASONS = ['wontfix' => 'Won’t Fix', 'duplicate' => 'Duplicate', 'cantrepro' => 'Can’t Reproduce', 'notabug' => 'Not a Bug'];
const BUG_TAG_COLORS = ['#7cb8ff', '#ff9466', '#f0c46a', '#6fcf8e', '#b79cff', '#5fd4d0', '#ff8fa3', '#e8b931', '#ff6b5b', '#a39d92'];
const BUG_LINKS = 3;          // screenshot / video links per report
const BUG_PICS = 3;           // uploaded pictures per report (uploads on)
const BUG_PIC_BYTES = 8 * 1024 * 1024;
const BUG_PIC_SIZE = 1600;    // longest side of an uploaded picture (and 600 for its small copy)
const BUG_PER_PAGE = 25;
const BUG_MAX = ['title' => 140, 'body' => 5000, 'steps' => 3000, 'comment' => 3000, 'short' => 40];

function m3d_bug_settings(): array
{
    $s = m3d_settings()['bugs'] ?? [];
    $s = is_array($s) ? $s : [];
    return [
        'on'       => !empty($s['on']),
        'who'      => ($s['who'] ?? '') === 'paying' ? 'paying' : 'any',
        'host'     => array_key_exists('host', $s) ? (string) $s['host'] : 'https://catbox.moe',
        'uploads'  => !empty($s['uploads']),
        'moderate' => !empty($s['moderate']),
        'notify'   => !empty($s['notify']),
        'webhook'  => (string) ($s['webhook'] ?? ''),
        'per_day'  => max(1, min(50, (int) ($s['per_day'] ?? 5))),
        'blocked'  => array_values(array_filter(array_map('strval', (array) ($s['blocked'] ?? [])))),
        'tags'     => array_values(array_filter((array) ($s['tags'] ?? []), fn($t) => is_array($t) && ($t['id'] ?? '') !== '' && ($t['name'] ?? '') !== '')),
    ];
}

/** Saves some keys of settings.json → bugs, keeping the others (tags, blocked people…). */
function m3d_bug_settings_save(array $changes): bool
{
    return m3d_save_setting_keys('bugs', $changes);
}

function m3d_bugs_on(): bool { return m3d_bug_settings()['on']; }

/** A report about this (`about` value) must give the Blender version, and the MustardUI one while the MustardUI page is on: every model and accessory report. */
function m3d_bug_needs_versions(string $about): bool
{
    return str_starts_with($about, 'models:') || str_starts_with($about, 'outfits:');
}

/** The server's form limit in bytes (post_max_size, e.g. "8M"), less a little for the text fields; 0 = no limit. */
function bug_post_max(): int
{
    $v = trim((string) ini_get('post_max_size'));
    $n = (int) $v;
    $n *= match (strtoupper(substr($v, -1))) { 'G' => 1 << 30, 'M' => 1 << 20, 'K' => 1 << 10, default => 1 };
    return $n > 0 ? max(0, $n - 64 * 1024) : 0;
}

/** Tag id => [name, colour]. An old digit-only id ("2024") comes back as an int key: compare ids as strings ((string) $id). */
function m3d_bug_tags(): array
{
    $out = [];
    foreach (m3d_bug_settings()['tags'] as $t) $out[(string) $t['id']] = [(string) $t['name'], preg_match('/^#[0-9a-f]{6}$/i', (string) ($t['color'] ?? '')) ? (string) $t['color'] : '#a39d92'];
    return $out;
}

/** Every bug, newest first. */
function m3d_bugs(bool $fresh = false): array
{
    static $list = null;
    if ($list !== null && !$fresh) return $list;
    $list = array_values(array_filter((array) (m3d_read_json('bugs')['bugs'] ?? []), fn($b) => is_array($b) && (int) ($b['n'] ?? 0) > 0));
    foreach ($list as &$b) if (isset($b['tags'])) $b['tags'] = array_map('strval', (array) $b['tags']);   // tag ids are strings, also digit-only ones
    unset($b);
    usort($list, fn($a, $b) => (int) $b['n'] <=> (int) $a['n']);
    return $list;
}

function m3d_bug(int $n): ?array
{
    foreach (m3d_bugs() as $b) if ((int) $b['n'] === $n) return $b;
    return null;
}

/**
 * Locked change of data/bugs.json: $fn(array $bugs, int $next) returns [$bugs, $next, $result]. Returns the result (null when
 * the file couldn't be locked).
 */
function m3d_bugs_update(callable $fn): mixed
{
    $out = m3d_json_update('bugs', function ($d) use ($fn) {
        $d = is_array($d) ? $d : [];
        $bugs = array_values(array_filter((array) ($d['bugs'] ?? []), 'is_array'));
        $next = max((int) ($d['next'] ?? 1), 1 + max([0, ...array_map(fn($b) => (int) ($b['n'] ?? 0), $bugs)]));
        [$bugs, $next, $res] = $fn($bugs, $next);
        return [['version' => 1, 'next' => $next, 'bugs' => array_values($bugs)], $res];
    });
    m3d_bugs(true);
    return $out;
}

/** A report with a new status (and its "fixed in" version, closed reason, duplicate number); logged when anything changed. */
function m3d_bug_set_status(array $b, string $st, int $now, string $fixed = '', string $reason = '', int $dup = 0): array
{
    $was = [$b['status'] ?? 'new', $b['fixed'] ?? '', $b['reason'] ?? '', (int) ($b['dup'] ?? 0)];
    $b['status'] = $st;
    unset($b['fixed'], $b['reason'], $b['dup']);
    if ($fixed !== '') $b['fixed'] = $fixed;
    if ($reason !== '') $b['reason'] = $reason;
    if ($dup > 0) $b['dup'] = $dup;
    if ($was !== [$st, $fixed, $reason, $dup]) {
        $b['log'][] = array_filter(['t' => 's', 'at' => $now, 'status' => $st, 'fixed' => $fixed, 'reason' => $reason, 'dup' => $dup]);
        $b['act'] = $now;
    }
    // Fixed / Closed lock the comments; opening it again unlocks them (the Lock Comments switch can still change it afterwards)
    $done = fn(string $s) => !in_array($s, BUG_OPEN, true);
    if ($was[0] !== $st) {
        if ($done($st)) $b['locked'] = true;
        elseif ($done((string) $was[0])) unset($b['locked']);
    }
    return $b;
}

/** An item's address changed (a draft replaced by an import): its reports follow it, so they keep its visibility and its page box. */
function m3d_bugs_rename_item(string $kind, string $old, string $new): void
{
    if ($old === $new || !is_file(M3D_PRIVATE . '/data/bugs.json')) return;
    m3d_bugs_update(function (array $bugs, int $next) use ($kind, $old, $new) {
        foreach ($bugs as &$b) if (($b['about'] ?? '') === "$kind:$old") $b['about'] = "$kind:$new";
        unset($b);
        return [$bugs, $next, null];
    });
}

/** Changes one bug ($fn gets it and returns it, or null to delete it). Returns the bug after the change, or null. */
function m3d_bug_change(int $n, callable $fn): ?array
{
    return m3d_bugs_update(function (array $bugs, int $next) use ($n, $fn) {
        $res = null;
        foreach ($bugs as $i => $b) {
            if ((int) ($b['n'] ?? 0) !== $n) continue;
            $new = $fn($b);
            if ($new === null) unset($bugs[$i]); else $bugs[$i] = $res = $new;
            break;
        }
        return [$bugs, $next, $res];
    });
}

// ── What a bug is about ──────────────────────────────────────────────────────
/** [kind, slug] for 'models:x' / 'outfits:x', else [key, '']. */
function m3d_bug_about_parts(string $about): array
{
    if (preg_match('/^(models|outfits):([a-z0-9-]{1,64})$/', $about, $m)) return [$m[1], $m[2]];
    return [in_array($about, ['mustardui', 'site'], true) ? $about : 'other', ''];
}

/** The choices of "What is it about?": key => [name, group, item or null]. Only items this visitor can see (a password item hidden from the catalog too, once unlocked: its page links here). */
function m3d_bug_abouts(): array
{
    $out = [];
    foreach (m3d_items('models') as $m) {
        if (!m3d_can_view('models', $m)) continue;
        $out['models:' . $m['slug']] = [m3d_model_name($m), 'Models', $m];
    }
    foreach (m3d_items('outfits') as $o) {
        if (!m3d_can_view('outfits', $o)) continue;
        $out['outfits:' . $o['slug']] = [$o['name'], 'Accessories', $o];
    }
    // MustardUI itself is not here: its bugs go to its GitHub issues (the form links there)
    $out['site'] = ['The Website', 'Other', null];
    $out['other'] = ['Something Else', 'Other', null];
    return $out;
}

/** What a report can be about, for the admin (editing a report): every model and accessory (drafts too), then the rest. key => [name, group]. */
function m3d_bug_abouts_all(): array
{
    $out = [];
    foreach (['models' => 'Models', 'outfits' => 'Accessories'] as $kind => $group) foreach (m3d_items($kind) as $it) {
        $out["$kind:" . $it['slug']] = [m3d_model_name($it) . (m3d_visibility($it) === 'draft' ? ' (draft)' : ''), $group];
    }
    return $out + ['site' => ['The Website', 'Other'], 'other' => ['Something Else', 'Other']];
}

/** The name stored with a report (`about_name`) from a menu label: without the " (draft)" the admin's menu adds. */
function bug_about_name(string $label): string
{
    return (string) preg_replace('/ \(draft\)$/', '', $label);
}

/**
 * How the bug's subject shows: ['name', 'kind' (Model, Hair, Website…), 'url' (item page or ''), 'item' (or null), 'kindKey'].
 * A removed item keeps the name it had.
 */
function m3d_bug_about(array $b): array
{
    [$kind, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
    if ($slug !== '') {
        $it = m3d_item($kind, $slug);
        $name = $it ? m3d_model_name($it) : (string) ($b['about_name'] ?? $slug);
        return ['name' => $name, 'kind' => $kind === 'models' ? 'Model' : ($it ? m3d_accessory_type($it) : 'Accessory'),
            'url' => $it && m3d_can_view($kind, $it) ? m3d_item_url($kind, $slug) : '', 'item' => $it, 'kindKey' => $kind];
    }
    if (in_array($b['gone'] ?? '', ['models', 'outfits'], true)) {   // about an item since deleted (admin_forget_items())
        return ['name' => (string) (($b['about_name'] ?? '') ?: 'Removed item') . ' (removed)', 'kind' => $b['gone'] === 'models' ? 'Model' : 'Accessory',
            'url' => '', 'item' => null, 'kindKey' => 'other'];
    }
    return ['name' => ['mustardui' => 'MustardUI', 'site' => 'Website'][$kind] ?? 'Other', 'kind' => $kind === 'mustardui' ? 'Add-on' : 'Other',
        'url' => $kind === 'mustardui' && m3d_mustardui_on() ? SITE['mustarduiPage'] : '', 'item' => null, 'kindKey' => $kind];
}

/** Version labels of an item's changelog that are out, newest first ("V4", "Beta 2"): the report form's choices. */
function m3d_bug_versions(?array $item): array
{
    if (!$item) return [];
    return array_values(array_unique(array_map('m3d_version_label', m3d_released_versions($item))));
}

// ── Who sees and who may write ───────────────────────────────────────────────
/** Whether this visitor sees the bug: admins all; others not hidden ones, not pending ones (except their own), not ones about an item they can't open. */
function m3d_bug_visible(array $b, ?array $patron = null): bool
{
    if (m3d_is_admin($GLOBALS['M3D'])) return true;
    if (!empty($b['hidden'])) return false;
    if (!empty($b['pending']) && (!$patron || (string) ($b['by'] ?? '') !== (string) ($patron['id'] ?? ''))) return false;
    [$kind, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
    if ($slug !== '' && ($it = m3d_item($kind, $slug)) && !m3d_can_view($kind, $it)) return false;
    return true;
}

/** ['ok' => bool, 'why' => text for the visitor]: may this visitor report, comment and press Me Too? */
function m3d_bug_can_post(?array $p): array
{
    $s = m3d_bug_settings();
    if (!$p) return ['ok' => false, 'why' => 'login'];
    if (!empty($p['preview'])) return ['ok' => false, 'why' => 'Not while previewing the site as a patron.'];
    if (in_array((string) ($p['id'] ?? ''), $s['blocked'], true)) return ['ok' => false, 'why' => 'You can’t post bug reports any more.'];
    if ($s['who'] === 'paying' && empty($p['access'])) return ['ok' => false, 'why' => 'Only patrons on a paid tier can post bug reports.'];
    return ['ok' => true, 'why' => ''];
}

/** Reports this person sent in the last 24 hours (for the daily limit). */
function m3d_bug_sent_today(string $patronId): int
{
    $from = time() - 86400;
    return count(array_filter(m3d_bugs(), fn($b) => (string) ($b['by'] ?? '') === $patronId && (int) ($b['at'] ?? 0) > $from));
}

/** Reports the admin hasn't opened yet (the count in the admin menu). */
function m3d_bugs_unseen(): int
{
    return count(array_filter(m3d_bugs(), fn($b) => empty($b['seen'])));
}

/** Pinned and open reports about one item that this visitor sees: ['pinned' => [bugs], 'open' => count]. */
function m3d_bugs_for(string $about): array
{
    $p = m3d_patron($GLOBALS['M3D']);
    $mine = array_filter(m3d_bugs(), fn($b) => ($b['about'] ?? '') === $about && empty($b['pending']) && empty($b['hidden']) && m3d_bug_visible($b, $p));
    $open = array_filter($mine, fn($b) => in_array($b['status'] ?? 'new', BUG_OPEN, true));
    return ['pinned' => array_values(array_filter($open, fn($b) => !empty($b['pin']))), 'open' => count($open)];
}

// ── Fixed in a version ───────────────────────────────────────────────────────
// A fixed report's `fixed` is a version label as typed ("V3", "3", "Beta 2"); the item's changelog shows it under that version.
// "Next Version" is stored as 'next:<label of the top changelog entry when it was picked>' and becomes the version added above that one.
const BUG_NEXT = 'next:';

/** A version label made comparable: lower case, single spaces, no "V" before the number ("V 3" = "3"). */
function m3d_ver_key(string $v): string
{
    $v = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $v)));
    return (string) preg_replace('/^v ?(?=\d)/', '', $v);
}

/** The changelog entry (its index in $log) a "fixed in" label names: its full label ("Beta 2") first, else its bare number. */
function m3d_changelog_find(array $log, string $label): ?int
{
    $k = m3d_ver_key($label);
    if ($k === '') return null;
    foreach (array_values($log) as $i => $en) if (is_array($en) && m3d_ver_key(m3d_version_label($en)) === $k) return $i;
    foreach (array_values($log) as $i => $en) if (is_array($en) && m3d_ver_key((string) ($en['version'] ?? '')) === $k) return $i;
    return null;
}

/** The version a fixed report names: ['label' => version label ('' while it waits for the next version), 'next' => still waiting]. */
function m3d_bug_fixed_in(array $b, ?array $item = null): array
{
    $f = (string) ($b['fixed'] ?? '');
    if (!str_starts_with($f, BUG_NEXT)) return ['label' => $f, 'next' => false];
    if ($item === null) {
        [$kind, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
        $item = $slug !== '' ? m3d_item($kind, $slug) : null;
    }
    $log = $item && is_array($item['changelog'] ?? null) ? array_values(array_filter($item['changelog'], 'is_array')) : [];
    $base = substr($f, strlen(BUG_NEXT));
    $at = $base === '' ? count($log) : m3d_changelog_find($log, $base);   // no version when it was picked: the first one added
    return $at !== null && $at > 0 ? ['label' => m3d_version_label($log[$at - 1]), 'next' => false] : ['label' => '', 'next' => true];
}

/** The item's fixed reports this visitor may see, by changelog index: [i => [[n, title]…]]; none while bug reports are off. */
function m3d_bug_fixes_for(string $kind, array $item, array $log): array
{
    if (!m3d_bugs_on() || !$log) return [];
    $p = m3d_patron($GLOBALS['M3D']);
    $out = [];
    foreach (m3d_bugs() as $b) {
        if (($b['about'] ?? '') !== "$kind:" . $item['slug'] || ($b['status'] ?? '') !== 'fixed' || ($b['fixed'] ?? '') === '') continue;
        if (!empty($b['pending']) || !empty($b['hidden']) || !m3d_bug_visible($b, $p)) continue;
        $fx = m3d_bug_fixed_in($b, $item)['label'];
        $i = $fx !== '' ? m3d_changelog_find($log, $fx) : null;
        if ($i !== null) $out[$i][] = [(int) $b['n'], (string) $b['title']];
    }
    foreach ($out as &$list) sort($list);
    return $out;
}

// ── Following ────────────────────────────────────────────────────────────────
// A bug's `fol` = {code: time}: who follows it (m3d_like_code() of the Patreon id, no raw ids) and when they last opened it.
// Writing, commenting or pressing Me Too follows it; the Follow button turns it off or on. Admins don't follow (Admin → Bugs
// has its own "not seen"). What is new for a follower: comments by others and status changes after that time.

/** code => last opened. */
function m3d_bug_followers(array $b): array
{
    $out = [];
    foreach ((array) ($b['fol'] ?? []) as $c => $t) $out[(string) $c] = (int) $t;
    return $out;
}

/** Whether this visitor can follow reports: logged in, not an admin, not previewing the site as a patron. */
function m3d_bug_can_follow(?array $p): bool
{
    return $p !== null && empty($p['preview']) && (string) ($p['id'] ?? '') !== '' && !m3d_is_admin($GLOBALS['M3D']);
}

/** The bug with this patron following it, as seen now (to call inside m3d_bug_change() / m3d_bugs_update()). */
function m3d_bug_follow(array $b, array $p, int $now): array
{
    if (!m3d_bug_can_follow($p)) return $b;
    $b['fol'] = m3d_bug_followers($b);
    $b['fol'][m3d_like_code((string) $p['id'])] = $now;
    return $b;
}

/** Log entries of a bug that are new for someone who last opened it at $seen: status changes and other people's comments. */
function m3d_bug_new_entries(array $b, int $seen, string $patronId): array
{
    return array_filter((array) ($b['log'] ?? []), fn($x) => (int) ($x['at'] ?? 0) > $seen
        && (($x['t'] ?? '') === 's' || (($x['t'] ?? '') === 'c' && (!empty($x['admin']) || (string) ($x['by'] ?? '') !== $patronId))));
}

/**
 * The reports this visitor follows and can see: n => their new log entries ([] = nothing new). Empty for visitors who can't follow
 * and while bug reports are off.
 */
function m3d_bug_following(?array $p): array
{
    static $memo = [];
    if (!m3d_bugs_on() || !m3d_bug_can_follow($p)) return [];
    $id = (string) $p['id'];
    if (isset($memo[$id])) return $memo[$id];
    $code = m3d_like_code($id);
    $out = [];
    foreach (m3d_bugs() as $b) {
        $seen = m3d_bug_followers($b)[$code] ?? null;
        if ($seen === null || !empty($b['hidden']) || !m3d_bug_visible($b, $p)) continue;
        $out[(int) $b['n']] = array_values(m3d_bug_new_entries($b, $seen, $id));
    }
    return $memo[$id] = $out;
}

/** The tag of a followed report with news in the list: "1 new comment", "New status", "3 new". */
function bug_news_label(array $entries): string
{
    $k = count($entries);
    $types = array_unique(array_map(fn($x) => (string) ($x['t'] ?? ''), $entries));
    if ($types === ['c']) return m3d_plural($k, 'new comment');
    return $types === ['s'] && $k === 1 ? 'New status' : $k . ' new';
}

/** How many followed reports have something new (the number next to "Bugs" in the menu). */
function m3d_bug_news_count(?array $p): int
{
    return count(array_filter(m3d_bug_following($p)));
}

// ── Input ────────────────────────────────────────────────────────────────────
/** One line of text: trimmed, no control characters, at most $max characters. */
function bug_line(mixed $v, int $max): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_scalar($v) ? (string) $v : '') ?? '';   // a crafted list (?q[]=) reads as empty
    return mb_substr(trim($s), 0, $max);
}

/** A text with lines: Windows line ends turned into \n, at most $max characters. */
function bug_text(mixed $v, int $max): string
{
    $s = str_replace(["\r\n", "\r"], "\n", is_scalar($v) ? (string) $v : '');
    $s = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}

/** One value of the request as a string ('' for a crafted list, like ?about[]=). */
function bug_str(mixed $v): string
{
    return is_scalar($v) ? (string) $v : '';
}

/** Up to BUG_LINKS https addresses (screenshots, videos). */
function bug_links(mixed $v): array
{
    $out = [];
    foreach ((array) $v as $u) {
        $u = is_string($u) ? trim($u) : '';
        if ($u === '') continue;
        if (!preg_match('#^https?://[^\s<>"]{3,500}$#i', $u) || !filter_var($u, FILTER_VALIDATE_URL)) continue;
        $out[] = $u;
    }
    return array_slice(array_values(array_unique($out)), 0, BUG_LINKS);
}

/**
 * Saves the pictures of a report form ($_FILES['pics']) in media/bugs/: each one re-drawn as WebP (GD), at most BUG_PIC_SIZE
 * px, plus a 600 px copy "-sm". Returns [addresses, error or ''].
 */
function bug_save_pics(array $files, int $room): array
{
    $out = [];
    $names = (array) ($files['tmp_name'] ?? []);
    $errs = (array) ($files['error'] ?? []);
    $sizes = (array) ($files['size'] ?? []);
    $dir = M3D_SITE . '/media/bugs';
    foreach ($names as $i => $tmp) {
        if ((int) ($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (count($out) >= $room) return [$out, 'At most ' . BUG_PICS . ' pictures per report.'];
        if ((int) ($errs[$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $tmp)) return [$out, 'A picture could not be received (too big?).'];
        if ((int) ($sizes[$i] ?? 0) > BUG_PIC_BYTES) return [$out, 'Pictures can be at most ' . (BUG_PIC_BYTES >> 20) . ' MB.'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string) $tmp);
        $info = @getimagesize((string) $tmp);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !$info) return [$out, 'Pictures must be JPG, PNG or WebP.'];
        if (!img_supported() || !img_fits_memory((int) $info[0], (int) $info[1])) return [$out, 'This picture is too large for the server.'];
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return [$out, 'The picture could not be saved.'];
        $im = img_load((string) $tmp);
        if (!$im) return [$out, 'This picture could not be read.'];
        try {
            $base = "$dir/" . gmdate('ymd') . '-' . bin2hex(random_bytes(4));
            $big = img_resized($im, BUG_PIC_SIZE);
            $path = img_save($big, $base);
            $sm = img_resized($big, 600);
            img_save($sm, "$base-sm");
            if ($sm !== $big) imagedestroy($sm);
            if ($big !== $im) imagedestroy($big);
            $out[] = '/media/bugs/' . basename($path);
        } catch (Throwable $ex) {
            return [$out, 'The picture could not be saved.'];
        } finally {
            imagedestroy($im);
        }
    }
    return [$out, ''];
}

/** Deletes uploaded pictures (and their small copies) of a report. */
function bug_delete_pics(array $pics): void
{
    foreach ($pics as $u) {
        if (!preg_match('#^/media/bugs/([0-9]{6}-[0-9a-f]{8})\.(webp|jpg)$#', (string) $u, $m)) continue;
        @unlink(M3D_SITE . "/media/bugs/{$m[1]}.{$m[2]}");
        @unlink(M3D_SITE . "/media/bugs/{$m[1]}-sm.{$m[2]}");
    }
}

/** Small copy of an uploaded picture. */
function bug_pic_small(string $u): string
{
    return preg_replace('/\.(webp|jpg)$/', '-sm.$1', $u) ?? $u;
}

/** Short Discord post about a new report (Settings → Bug Reports → Notify on Discord). A failure is only logged. */
function m3d_bug_notify(array $b): void
{
    $s = m3d_bug_settings();
    if (!$s['notify'] || !preg_match(DISCORD_WEBHOOK_RE, $s['webhook'])) return;
    $a = m3d_bug_about($b);
    // the visitor's text as plain text: no masked links ("[this-site/download](https://elsewhere)") or headings in the owner's channel
    $plain = fn(string $t) => preg_replace('/([\\\\`*_~|>#\[\]()<])/', '\\\\$1', $t);
    $embed = ['title' => mb_substr('#' . $b['n'] . ' ' . $b['title'], 0, 250), 'url' => abs_url('/bugs/' . $b['n'] . '/'),
        'description' => mb_substr(trim($a['name'] . ' ' . ($b['ver'] ?? '')) . "\n" . $plain(mb_substr((string) $b['body'], 0, 300)), 0, 700),
        'color' => hexdec('e8b931'), 'footer' => ['text' => 'New bug report' . (!empty($b['pending']) ? ' · waiting for you' : '')]];
    $r = m3d_session_pause(fn() => m3d_discord_post($s['webhook'], '', [$embed]));
    if (empty($r['ok'])) m3d_problem('Bug reports', 'Discord notice failed: ' . ($r['error'] ?? ''));
}

// ── Pieces of pages ──────────────────────────────────────────────────────────
function bug_status_label(array $b): string
{
    $st = (string) ($b['status'] ?? 'new');
    $label = (BUG_STATUSES[$st] ?? BUG_STATUSES['new'])[0];
    if ($st === 'fixed' && ($b['fixed'] ?? '') !== '') $label = ($fx = m3d_bug_fixed_in($b))['next'] ? 'Fixed in Next Version' : 'Fixed in ' . $fx['label'];
    if ($st === 'closed' && isset(BUG_REASONS[$b['reason'] ?? ''])) {
        $label = 'Closed · ' . ($b['reason'] === 'duplicate' && (int) ($b['dup'] ?? 0) > 0 ? 'Duplicate of #' . (int) $b['dup'] : BUG_REASONS[$b['reason']]);
    }
    return $label;
}

function bug_status_badge(array $b): string
{
    $tone = (BUG_STATUSES[$b['status'] ?? 'new'] ?? BUG_STATUSES['new'])[1];
    if (($b['status'] ?? '') === 'closed') return '<span class="badge tone-' . $tone . ' st-closed">' . bug_icon('closed') . e(bug_status_label($b)) . '</span>';   // ⊘ instead of the dot
    return '<span class="badge tone-' . $tone . '">' . e(bug_status_label($b)) . '</span>';
}

/** A tag pill ('' for a tag that was deleted). */
function bug_tag_html(string $id, ?array $tags = null): string
{
    $tags ??= m3d_bug_tags();
    if (!isset($tags[$id])) return '';
    [$name, $c] = $tags[$id];
    return '<span class="btag" style="--c:' . e($c) . '">' . e($name) . '</span>';
}

/** The picture of what a bug is about: the item's small catalog picture (blurred copy when hidden), else an icon. */
function bug_about_pic(array $a, string $class = 'bpic'): string
{
    if ($a['item']) {
        [$src, $hidden] = card_pic($a['kindKey'], $a['item']);
        if ($src !== '') return '<span class="' . $class . ($hidden ? ' is-blurred' : '') . '"><img src="' . e($src) . '" alt="" loading="lazy" decoding="async" /></span>';
        return '<span class="' . $class . ' bpic-ph"><span>' . e(mb_substr($a['name'], 0, 1)) . '</span></span>';
    }
    $icon = $a['kindKey'] === 'mustardui'
        ? '<path d="M4 6h16M4 12h10M4 18h7"/><circle cx="18" cy="16" r="3"/>'
        : '<rect x="3" y="4" width="18" height="16" rx="3"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>';
    return '<span class="' . $class . ' bpic-icon"><svg viewBox="0 0 24 24" aria-hidden="true">' . $icon . '</svg></span>';
}

/**
 * An <option> of a "What is it about?" menu, with what the searchable picker (site.js, [data-about-picker]) shows: kind
 * (model / outfit / mustardui / site / other), name, sub (franchise or accessory type), gen, thumb (blurred copy when hidden).
 */
function bug_about_option(string $key, string $label, bool $selected, string $count = ''): string
{
    [$kind, $slug] = m3d_bug_about_parts($key);
    $d = ['kind' => ['models' => 'model', 'outfits' => 'outfit'][$kind] ?? $kind, 'name' => $label];
    if ($slug !== '' && ($it = m3d_item($kind, $slug))) {
        $d['sub'] = $kind === 'models' ? (string) ($it['franchise'] ?? '') : m3d_accessory_type($it);
        if ($kind === 'models' && ($it['generation'] ?? '') !== '') $d['gen'] = (string) $it['generation'];
        if (m3d_can_view($kind, $it)) $d['thumb'] = card_pic($kind, $it)[0];
    }
    if ($count !== '') $d['count'] = $count;
    $attrs = '';
    foreach ($d as $k => $v) if ($v !== '') $attrs .= ' data-' . $k . '="' . e($v) . '"';
    return '<option value="' . e($key) . '"' . $attrs . ($selected ? ' selected' : '') . '>' . e($label . ($count !== '' ? " ($count)" : '')) . '</option>';
}

const BUG_ICONS = [
    'metoo'   => '<path d="M7 11v9H4v-9zM7 11l4-7c1.6 0 2.5 1 2.2 2.6L12.6 10H19a2 2 0 0 1 2 2.3l-1.2 6A2 2 0 0 1 17.8 20H7"/>',
    'comment' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
    'bell'    => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
    'pin'     => '<path d="M9 4h6l-1 6 3 3H7l3-3zM12 13v7"/>',
    'bug'     => '<path d="M8 8a4 4 0 0 1 8 0M7 10h10v4a5 5 0 0 1-10 0zM12 10v9M3 13h4M17 13h4M4 7l3 2M20 7l-3 2M4 19l3-2M20 19l-3-2"/>',
    'link'    => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
    'img'     => '<rect x="3" y="4" width="18" height="16" rx="3"/><circle cx="9" cy="10" r="2"/><path d="m21 16-5-5-9 9"/>',
    'check'   => '<path d="m5 12 5 5 9-10"/>',
    'closed'  => '<circle cx="12" cy="12" r="9"/><path d="m5.6 5.6 12.8 12.8"/>',
    'lock'    => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    'pen'     => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
    'ui'      => '<path d="M4 6h9M17 6h3M4 12h3M11 12h9M4 18h11M19 18h1"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
    'tag'     => '<path d="M3.5 12.2V4.5a1 1 0 0 1 1-1h7.7l8.3 8.3a1.4 1.4 0 0 1 0 2l-6.2 6.2a1.4 1.4 0 0 1-2 0z"/><circle cx="8" cy="8" r="1.6"/>',
];

/** The creator's "Edit Tags" window (a <dialog>, opened by [data-tags-open]; site.js [data-tags-dlg]): name + colour per tag, drag to reorder,
 *  saved to admin/bugs.php (do=tags), which comes back to $back. Printed outside other forms. */
function bug_tags_dialog(string $back): string
{
    $used = [];
    foreach (m3d_bugs() as $b) foreach ((array) ($b['tags'] ?? []) as $t) $used[(string) $t] = ($used[(string) $t] ?? 0) + 1;
    $tags = m3d_bug_tags();
    $row = fn(string $id = '', string $name = '', string $c = '') => '<div class="tg-row" data-tg-row>'
        . '<button type="button" class="tg-drag" data-tg-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>'
        . '<input type="hidden" name="tag_id[]" value="' . e($id) . '" />'
        . '<input class="tg-color" type="color" name="tag_color[]" value="' . e($c !== '' ? $c : BUG_TAG_COLORS[0]) . '" aria-label="Colour" title="Colour" />'
        . '<input class="input" name="tag_name[]" value="' . e($name) . '" maxlength="30" placeholder="Tag name" aria-label="Tag name" />'
        . '<span class="tg-cnt">' . ($id !== '' ? e(m3d_plural($used[$id] ?? 0, 'report')) : 'New') . '</span>'
        . '<button type="button" class="tg-del" data-tg-del title="Delete (it leaves its reports too)" aria-label="Delete">✕</button></div>';
    $rows = '';
    foreach ($tags as $id => [$name, $c]) $rows .= $row((string) $id, $name, $c);
    return '<dialog class="tags-dlg" data-tags-dlg data-colors="' . e(json_encode(BUG_TAG_COLORS)) . '" aria-labelledby="tags-dlg-h">'
        . '<form method="post" action="/admin/bugs.php"><input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="do" value="tags" />'
        . '<input type="hidden" name="back" value="' . e($back) . '" />'
        . '<header class="tg-head"><h2 id="tags-dlg-h">Bug Report Tags</h2>'
        . '<button type="button" class="tg-x" data-tags-close aria-label="Close" title="Close">✕</button></header>'
        . '<div class="tg-rows" data-tg-rows>' . $rows . '</div>'
        . '<p class="muted small tg-empty" data-tg-empty' . ($tags ? ' hidden' : '') . '>No tags yet. Add a few, like “Shape keys”, “Textures”, “Physics”.</p>'
        . '<template data-tg-template>' . $row() . '</template>'
        . '<div class="tg-foot"><button type="button" class="btn btn-ghost btn-sm tg-add" data-tg-add>+ Add Tag</button>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-tags-close>Cancel</button><button class="btn btn-primary btn-sm" data-tg-save>Save</button></div>'
        . '</form></dialog>';
}

/** The creator's ⋯ menu on a post of a report ($items: links / small forms, '' left out). A <details>: works without JavaScript. */
function bug_post_menu(array $items): string
{
    $items = array_filter($items, fn($x) => $x !== '');
    if (!$items) return '';
    return '<details class="pmenu"><summary aria-label="More actions" title="More actions">⋯</summary><div class="pm-pop">' . implode('', $items) . '</div></details>';
}

/**
 * The tiles at the top of a report: what it is about (the item's picture, linked to its page; name + version), then Blender and
 * MustardUI (only while its page is on) with their icons; one row across the post. The About tile is always there.
 */
function bug_env_html(array $b, array $a): string
{
    $ver = (string) ($b['ver'] ?? '');
    $kind = $a['kind'] === 'Other' || $a['kindKey'] === 'mustardui' ? 'About' : (string) $a['kind'];
    [$label, $value] = $ver !== '' ? [(string) $a['name'], $ver] : [$kind, (string) $a['name']];
    $tag = $a['url'] !== '' ? 'a' : 'div';
    $out = '<' . $tag . ' class="env-about"' . ($tag === 'a' ? ' href="' . e($a['url']) . '" title="' . e($kind . ': ' . $a['name']) . '"' : '') . '>'
        . bug_about_pic($a, 'bpic env-pic') . '<dl><dt>' . e($label) . '</dt><dd>' . e($value) . '</dd></dl></' . $tag . '>';
    $tiles = [
        ['Blender', (string) ($b['blender'] ?? ''), accessory_icon_paths('blender')],
        ['MustardUI', m3d_mustardui_on() ? (string) ($b['mui'] ?? '') : '', BUG_ICONS['ui']],
    ];
    foreach ($tiles as [$l, $v, $ico]) {
        if ($v === '') continue;
        $out .= '<div><svg class="env-ico" viewBox="0 0 24 24" aria-hidden="true">' . $ico . '</svg><dl><dt>' . e($l) . '</dt><dd>' . e($v) . '</dd></dl></div>';
    }
    return '<div class="bug-env">' . $out . '</div>';
}

function bug_icon(string $name): string
{
    return '<svg class="bi" viewBox="0 0 24 24" aria-hidden="true">' . (BUG_ICONS[$name] ?? '') . '</svg>';
}

/** "2 hours ago", "3 days ago", then the date (Europe/Berlin). */
function bug_ago(int $t): string
{
    $d = time() - $t;
    if ($d < 90) return 'just now';
    if ($d < 3600) return m3d_plural((int) round($d / 60), 'minute') . ' ago';
    if ($d < 86400) return m3d_plural((int) round($d / 3600), 'hour') . ' ago';
    if ($d < 86400 * 14) return m3d_plural((int) round($d / 86400), 'day') . ' ago';
    return 'on ' . m3d_berlin($t, m3d_berlin($t, 'Y') === m3d_berlin(time(), 'Y') ? 'j M' : 'j M Y');
}

/** Initial of a name for the round avatars, and a hue from the name. */
function bug_avatar(string $name, bool $creator = false): string
{
    $h = 0;
    foreach (str_split($name) as $c) $h = ($h * 31 + ord($c)) % 360;
    return '<span class="ava' . ($creator ? ' creator' : '') . '" style="--h:' . $h . '" aria-hidden="true">' . e(mb_strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1))) . '</span>';
}

/**
 * The "Found a bug?" box on model and accessory pages (under the downloads): pinned known issues, the number of open reports and
 * a Report a bug link. '' while bug reports are off.
 */
function bug_item_box(string $kind, array $item): string
{
    if (!m3d_bugs_on()) return '';
    $about = "$kind:" . $item['slug'];
    $info = m3d_bugs_for($about);
    $h = '<section class="bug-box"><div class="bug-box-head"><h3>' . bug_icon('bug') . 'Found a Bug?</h3>'
        . '<a class="btn btn-ghost btn-sm" href="/bugs/report/?about=' . e(rawurlencode($about)) . '">Report It</a></div>';
    if ($info['pinned']) {
        $h .= '<ul class="bug-box-list">';
        foreach (array_slice($info['pinned'], 0, 3) as $b) $h .= '<li><a href="/bugs/' . (int) $b['n'] . '/">' . bug_icon('pin') . '<span>' . e($b['title']) . '</span></a></li>';
        $h .= '</ul>';
    }
    if ($info['open']) $h .= '<a class="bug-box-more" href="/bugs/?about=' . e(rawurlencode($about)) . '">' . m3d_plural($info['open'], 'open report') . ' →</a>';   // none: only the title and Report It
    return $h . '</section>';
}
