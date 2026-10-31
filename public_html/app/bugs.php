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
//   seen_at (it was opened, then got a visitor's comment: when; unset when opened again, see app/notify.php),
//   fol {like code: time last opened} (who follows it, see "Following"),
//   log [{t: 'c', by, name, at, text, admin} comment | {t: 's', at, status, fixed?, reason?, dup?} status | {t: 'tag', at, add []}],
//   rx (reactions {emoji: [like codes]}, on the report and on each comment, while settings → bugs.reactions is on: reactions_html() in app/comments.php)
// settings.json → bugs: {on, who: any|paying, host (where to upload screenshots), uploads, moderate, paid_skip (as comments'), per_day, blocked [Patreon ids], emoji, codes, reactions (as comments'),
//   tags [{id, name, color}], notify (old: read by m3d_notify_settings() until Settings → Notifications is saved, with the webhook of
//   private/webhooks.php → bugs)}
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
/** Comments one person may write on bug reports in 24 hours (admins: no limit). A fixed number, not the reports' per_day (a few
 *  reports a day, too few for a conversation with the creator) nor the comments' one (another feature, which may be off). */
const BUG_COMMENTS_PER_DAY = 30;
/** Me Too and Follow presses one visitor may make in an hour, counted in their session (nothing stored about them). */
const BUG_TOGGLES_PER_HOUR = 30;

function m3d_bug_settings(): array
{
    $s = m3d_settings()['bugs'] ?? [];
    $s = is_array($s) ? $s : [];
    if (!empty($s['blocked'])) m3d_bans_move_blocked('bugs', 'bugs', array_map('strval', (array) $s['blocked']));
    $who = ($s['who'] ?? '') === 'paying' ? 'paying' : 'any';
    return [
        'on'       => !empty($s['on']),
        'who'      => $who,
        'host'     => array_key_exists('host', $s) ? (string) $s['host'] : 'https://catbox.moe',
        'uploads'  => !empty($s['uploads']),
        // never saved: on while anyone may post (free members too), so a brand-new account isn't published at once
        'moderate' => array_key_exists('moderate', $s) ? !empty($s['moderate']) : $who === 'any',
        'paid_skip' => !empty($s['paid_skip']),   // "Skip for Paying Patrons": their reports show at once, others still wait (as comments')
        'notify'   => !empty($s['notify']),
        'webhook'  => m3d_webhook('bugs'),   // private/webhooks.php
        'per_day'  => max(1, min(50, (int) ($s['per_day'] ?? 5))),
        'emoji'    => !empty($s['emoji']),       // the ☺ picker button in the report and comment boxes, big emoji-only comments (as comments')
        'codes'    => !empty($s['codes']),       // ":heart:" codes while typing
        'reactions' => !empty($s['reactions']),  // COMMENT_REACTIONS under the report and each comment (`rx`)
        'blocked'  => m3d_ban_ids('bugs'),   // Admin → Access bans (the old list here moves there once)
        'tags'     => array_values(array_filter((array) ($s['tags'] ?? []), fn($t) => is_array($t) && ($t['id'] ?? '') !== '' && ($t['name'] ?? '') !== '')),
    ];
}

/** A new report by $p waits for the creator: "Check Before Showing" is on, unless "Skip for Paying Patrons" is too and $p can download
 *  (as m3d_comment_needs_check()). The creator's and moderators' never wait. */
function m3d_bug_needs_check(array $p, array $s): bool
{
    if (!$s['moderate'] || m3d_is_admin($GLOBALS['M3D']) || m3d_is_mod($GLOBALS['M3D'])) return false;
    return !($s['paid_skip'] && !empty($p['access']));
}

/** Saves some keys of settings.json → bugs, keeping the others (tags, blocked people…). */
function m3d_bug_settings_save(array $changes): bool
{
    return m3d_save_setting_keys('bugs', $changes);
}

function m3d_bugs_on(): bool { return m3d_bug_settings()['on']; }

/** A report about this (`about` value) must give the Blender version, and the version of the tool that asks for it (m3d_bug_tool()): every model and accessory report. */
function m3d_bug_needs_versions(string $about): bool
{
    return (bool) preg_match('/^(models|outfits|scenes):/', $about);
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
        foreach ($bugs as &$b) if (!empty($b['seen'])) unset($b['seen_at']);   // opened again: nothing new any more (app/notify.php)
        unset($b);
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
/** [kind, slug] for 'models:x' / 'outfits:x' / 'scenes:x', else [key, '']. */
function m3d_bug_about_parts(string $about): array
{
    if (preg_match('/^(models|outfits|scenes):([a-z0-9-]{1,64})$/', $about, $m)) return [$m[1], $m[2]];
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
    foreach (m3d_items('scenes') as $o) {   // the ones this visitor can open (m3d_can_view() below)
        if (!m3d_can_view('scenes', $o)) continue;
        $out['scenes:' . $o['slug']] = [$o['name'], 'Scenes', $o];
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
    foreach (['models' => 'Models', 'outfits' => 'Accessories', 'scenes' => 'Scenes'] as $kind => $group) foreach (m3d_items($kind) as $it) {
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
        return ['name' => $name, 'kind' => $kind === 'outfits' ? ($it ? m3d_accessory_type($it) : 'Accessory') : m3d_kind_word($kind, false, true),
            'url' => $it && m3d_can_view($kind, $it) ? m3d_item_url($kind, $slug) : '', 'item' => $it, 'kindKey' => $kind];
    }
    if (in_array($b['gone'] ?? '', M3D_ITEM_KINDS, true)) {   // about an item since deleted (admin_forget_items())
        return ['name' => (string) (($b['about_name'] ?? '') ?: 'Removed item') . ' (removed)', 'kind' => m3d_kind_word((string) $b['gone'], false, true),
            'url' => '', 'item' => null, 'kindKey' => 'other'];
    }
    $tool = $kind === 'mustardui' ? m3d_tool('mustardui') : null;   // old reports about MustardUI (before tool pages): its page while it is listed
    return ['name' => ['mustardui' => 'MustardUI', 'site' => 'Website'][$kind] ?? 'Other', 'kind' => $kind === 'mustardui' ? 'Add-on' : 'Other',
        'url' => $tool && empty($tool['draft']) ? m3d_tool_url('mustardui') : '', 'item' => null, 'kindKey' => $kind];
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
    if (m3d_banned($p, 'bugs')) return ['ok' => false, 'why' => 'banned'];   // Admin → Access: the page says so with ban_note()
    if ($s['who'] === 'paying' && empty($p['access'])) return ['ok' => false, 'why' => 'Only patrons on a paid tier can post bug reports.'];
    return ['ok' => true, 'why' => ''];
}

/** Reports this person sent in the last 24 hours (for the daily limit). $bugs: the list m3d_bugs_update() hands over, so the check
 *  and the save happen under one lock (two sessions at once can't both pass); else the one read. */
function m3d_bug_sent_today(string $patronId, ?array $bugs = null): int
{
    $from = time() - 86400;
    return count(array_filter($bugs ?? m3d_bugs(), fn($b) => (string) ($b['by'] ?? '') === $patronId && (int) ($b['at'] ?? 0) > $from));
}

/** Comments this person wrote on bug reports in the last 24 hours (BUG_COMMENTS_PER_DAY); $bugs as for m3d_bug_sent_today(). */
function m3d_bug_comments_today(string $patronId, ?array $bugs = null): int
{
    $from = time() - 86400;
    $n = 0;
    foreach ($bugs ?? m3d_bugs() as $b) foreach ((array) ($b['log'] ?? []) as $x) {
        if (($x['t'] ?? '') === 'c' && (string) ($x['by'] ?? '') === $patronId && (int) ($x['at'] ?? 0) > $from) $n++;
    }
    return $n;
}

/** One more Me Too / Follow press of this visitor: false when they made BUG_TOGGLES_PER_HOUR in the last hour. Counted in their
 *  session (times only), so nothing new is stored about them; without a session (can't happen for a logged-in visitor) it says no.
 *  $key / $max: another count of its own (comment reactions). */
function m3d_bug_toggle_ok(string $key = 'bug_toggles', int $max = BUG_TOGGLES_PER_HOUR): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) return false;
    $from = time() - 3600;
    $list = array_values(array_filter((array) ($_SESSION[$key] ?? []), fn($t) => (int) $t > $from));
    $ok = count($list) < $max;
    if ($ok) $list[] = time();
    $_SESSION[$key] = $list;
    return $ok;
}

/** Reports the admin hasn't opened yet (the count in the admin menu). */
function m3d_bugs_unseen(): int
{
    return m3d_bug_summary()['unseen'];
}

/**
 * What the header needs on every page, without reading every report (see m3d_data_summary()): unseen = the reports the creator hasn't
 * opened, unseen_c = those of them opened before and with new comments since (`seen_at`, the bell's "commented on"); wait = what each report
 * waiting for a check is about (the account menu's Moderation number); news = the follower
 * codes with a followed report that has something new for them (then m3d_bug_news_count() looks closer).
 */
function m3d_bug_summary(): array
{
    $make = function () {
        $unseen = $unseenC = 0;
        $wait = [];
        $news = $rx = [];
        $codeOf = [];   // Patreon id => its like code, worked out once per commenter
        foreach (m3d_bugs(true) as $b) {
            if (empty($b['seen'])) { $unseen++; if (isset($b['seen_at'])) $unseenC++; }
            if (!empty($b['hidden'])) continue;
            if (!empty($b['pending'])) $wait[] = (string) ($b['about'] ?? '');
            // reactions others gave to the report or a comment (still there): their author's bell, [ref ("n" | "n-at"), time of the newest, about]
            $n = (int) ($b['n'] ?? 0);
            $about = (string) ($b['about'] ?? '');
            if (empty($b['pending']) && !empty($b['rxat']) && ($by = (string) ($b['by'] ?? '')) !== '' && m3d_rx_others((array) ($b['rx'] ?? []), $codeOf[$by] ??= m3d_like_code($by))) {
                $rx[$codeOf[$by]][] = ["$n", (int) $b['rxat'], $about];
            }
            foreach ((array) ($b['log'] ?? []) as $x) {
                if (($x['t'] ?? '') !== 'c' || empty($x['rxat']) || !empty($x['hid']) || !empty($x['admin']) || ($by = (string) ($x['by'] ?? '')) === '') continue;
                if (m3d_rx_others((array) ($x['rx'] ?? []), $codeOf[$by] ??= m3d_like_code($by))) $rx[$codeOf[$by]][] = ["$n-" . (int) ($x['at'] ?? 0), (int) $x['rxat'], $about];
            }
            foreach (m3d_bug_followers($b) as $code => $seen) {
                if (isset($news[$code])) continue;
                foreach ((array) ($b['log'] ?? []) as $x) {
                    if ((int) ($x['at'] ?? 0) <= $seen || !empty($x['hid'])) continue;   // a hidden comment is no news
                    $t = $x['t'] ?? '';
                    $by = (string) ($x['by'] ?? '');
                    if (in_array($t, ['s', 'ref'], true) || ($t === 'c' && (!empty($x['admin']) || ($codeOf[$by] ??= m3d_like_code($by)) !== $code))) { $news[$code] = 1; break; }
                }
            }
        }
        foreach ($rx as &$list) { usort($list, fn($a, $b) => $a[1] <=> $b[1]); $list = array_slice($list, -20); }
        unset($list);
        return ['unseen' => $unseen, 'unseen_c' => $unseenC, 'wait' => $wait, 'news' => array_keys($news), 'rx' => $rx];
    };
    $s = m3d_data_summary('bugs', $make);
    if (!array_key_exists('wait', $s)) $s = m3d_data_summary('bugs', $make, true);   // saved before unseen_c / wait existed
    return $s + ['unseen' => 0, 'unseen_c' => 0, 'wait' => [], 'news' => [], 'rx' => []];
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
// An item's fixed report takes its version from the changelog ("#6" in a version's changes, see "Changelog mentions"); a website /
// other report's `fixed` is a version label as typed ("V3", "3", "Beta 2").

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

/**
 * The version a fixed report names ('' = none). A report about an item: only its changelog, the oldest version whose changes write
 * "#6" (see "Changelog mentions"); a value stored by the old Fixed In menu is ignored. Website / other reports: the typed `fixed`.
 */
function m3d_bug_fixed_in(array $b, ?array $item = null): string
{
    [$kind, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
    if ($slug === '') return str_starts_with($f = (string) ($b['fixed'] ?? ''), 'next:') ? '' : $f;
    $item ??= m3d_item($kind, $slug);
    $log = $item && is_array($item['changelog'] ?? null) ? array_values(array_filter($item['changelog'], 'is_array')) : [];
    $at = m3d_changelog_bug_refs($log)[(int) ($b['n'] ?? 0)] ?? null;
    return $at !== null ? m3d_version_label($log[$at]) : '';
}

// ── Changelog mentions ───────────────────────────────────────────────────────
// "Fix #6: …" in a version's changes (the editor offers the reports as you type #, bugMention() in admin.js): on the site the #6
// is a link to the report with its title (change_line() → bug_refs_html()), in Discord a link; saving the item puts "Fixed in <item>
// Version 2" in a report about it and marks it Fixed, removing the "#6" undoes both (m3d_bugs_after_save()); its "Fixed in" is the
// oldest version naming it (m3d_bug_fixed_in()).
const BUG_REF_RE = '/(?<![\w&\/#])#(\d{1,6})\b/';   // not "&#39;", "page#6", "##6"

/** Report numbers written in a changelog's changes: n => index of the oldest version naming it. */
function m3d_changelog_bug_refs(array $log): array
{
    $out = [];
    foreach (array_values($log) as $i => $en) {
        if (!is_array($en)) continue;
        foreach ((array) ($en['changes'] ?? []) as $c) {
            $c = preg_replace('/`[^`\n]*`|\[[^\]\n]*\]\([^)\s]*\)/', '', (string) $c);   // not inside code or a link, like bug_refs_html()
            if (preg_match_all(BUG_REF_RE, (string) $c, $m)) foreach ($m[1] as $n) $out[(int) $n] = $i;   // newest first: an older version overwrites
        }
    }
    return $out;
}

/** n => [title, status] of the reports this visitor may open (none while bug reports are off): what "#6" in a text links to. */
function m3d_bug_ref_map(): array
{
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    if (!m3d_bugs_on()) return $map;
    $p = m3d_patron($GLOBALS['M3D']);
    foreach (m3d_bugs() as $b) {
        if (empty($b['hidden']) && empty($b['pending']) && m3d_bug_visible($b, $p)) $map[(int) $b['n']] = [(string) $b['title'], (string) ($b['status'] ?? 'new')];
    }
    return $map;
}

/**
 * "#6" as a link to the report, its title in a small card on hover / focus (GitHub-like); plain "#6" for a report this visitor can't
 * open. $chip: the yellow chip with a bug icon that follows a line's tag ("Fix #6: …"), a grey one without a link.
 */
function bug_ref_link(int $n, bool $chip = false): string
{
    $b = m3d_bug_ref_map()[$n] ?? null;
    if (!$b) return $chip ? '<span class="bug-chip is-off">#' . $n . '</span>' : '#' . $n;
    $c = (BUG_STATUSES[$b[1]] ?? BUG_STATUSES['new'])[2];
    return '<a class="bug-ref' . ($chip ? ' bug-chip' : '') . '" href="/bugs/' . $n . '/" aria-label="#' . $n . ': ' . e($b[0]) . '">' . ($chip ? bug_icon('bug') : '') . '#' . $n
        . '<span class="bug-ref-pop" aria-hidden="true"><i class="sdot" style="--c:' . e($c) . '"></i><span class="muted">#' . $n . '</span> ' . e($b[0]) . '</span></a>';
}

/** Links every "#6" in a piece of HTML made by md_inline(), outside links and code. */
function bug_refs_html(string $html): string
{
    if (!str_contains($html, '#') || !m3d_bug_ref_map()) return $html;
    $parts = preg_split('#(<[^>]*>)#', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $skip = 0;   // inside <a> or <code>
    foreach ($parts as $i => $part) {
        if ($i % 2) { if (preg_match('#^<(/?)(a|code)\b#i', $part, $m)) $skip = max(0, $skip + ($m[1] === '' ? 1 : -1)); continue; }
        if (!$skip) $parts[$i] = (string) preg_replace_callback(BUG_REF_RE, fn($m) => isset(m3d_bug_ref_map()[(int) $m[1]]) ? bug_ref_link((int) $m[1]) : $m[0], $part);
    }
    return implode('', $parts);
}

/** "#6" in a Discord text as a link to the report, when everyone may open it (Discord posts are public). */
function m3d_bug_refs_discord(string $text): string
{
    if (!m3d_bugs_on() || !str_contains($text, '#')) return $text;
    $open = [];
    foreach (m3d_bugs() as $b) {
        if (!empty($b['hidden']) || !empty($b['pending'])) continue;
        [$kind, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
        if ($slug !== '' && (!($it = m3d_item($kind, $slug)) || !in_array(m3d_visibility($it), ['public', ...M3D_VIS_BLURRED], true) || m3d_release_hidden($it))) continue;
        $open[(int) $b['n']] = true;
    }
    $parts = preg_split('/(`[^`\n]*`|\[[^\]\n]*\]\([^)\s]*\))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);   // not inside code or a link
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) $parts[$i] = (string) preg_replace_callback(BUG_REF_RE, fn($m) => isset($open[(int) $m[1]]) ? '[#' . $m[1] . '](' . abs_url('/bugs/' . $m[1] . '/') . ')' : $m[0], $part);
    }
    return implode('', $parts);
}

/**
 * After a save of models or outfits, for reports about the saved item: one whose "#6" a version's changes name for the first time
 * gets a "Fixed in <item> Version 2" line in its timeline (`t` = 'ref', `item` = "kind:slug", its followers see it) and becomes
 * Fixed if it wasn't (`was` = the status before, `lock` = this locked its comments). Removing the "#6" removes that line again,
 * and gives the report back its earlier status when nothing changed it since.
 */
function m3d_bugs_after_save(string $kind, array $old, array $items): void
{
    if (!m3d_bugs_on()) return;
    $refs = fn($it) => m3d_changelog_bug_refs((array) ($it['changelog'] ?? []));
    $was = $now = [];
    foreach ($old as $it) if (is_array($it)) $was[(string) ($it['slug'] ?? '')] = $refs($it);
    foreach ($items as $it) if (is_array($it)) $now[(string) ($it['slug'] ?? '')] = $refs($it);
    $add = $drop = [];   // "kind:slug" => [n => true]
    foreach ($now as $slug => $ns) foreach ($ns as $n => $i) if (!isset($was[$slug][$n])) $add["$kind:$slug"][$n] = true;
    foreach ($was as $slug => $ns) if (isset($now[$slug])) foreach ($ns as $n => $i) if (!isset($now[$slug][$n])) $drop["$kind:$slug"][$n] = true;
    $hit = fn(array $b, array $set) => isset($set[(string) ($b['about'] ?? '')][(int) ($b['n'] ?? 0)]);
    $dropped = function (array $b) use ($drop): ?string {   // the item whose changelog no longer names it, found by its "Fixed in" line (its About may have changed since)
        foreach ((array) ($b['log'] ?? []) as $x) if (($x['t'] ?? '') === 'ref' && isset($drop[(string) ($x['item'] ?? '')][(int) ($b['n'] ?? 0)])) return (string) $x['item'];
        return null;
    };
    if (!array_filter(m3d_bugs(), fn($b) => $hit($b, $add) || $dropped($b) !== null)) return;
    $at = time();
    $ok = m3d_bugs_update(function (array $bugs, int $next) use ($add, $dropped, $hit, $at) {
        foreach ($bugs as &$b) {
            $about = (string) ($b['about'] ?? '');
            if (($gone = $dropped($b)) !== null) $b = m3d_bug_unref($b, $gone);
            if ($hit($b, $add) && !array_filter((array) ($b['log'] ?? []), fn($x) => ($x['t'] ?? '') === 'ref' && ($x['item'] ?? '') === $about)) {
                $st = (string) ($b['status'] ?? 'new');
                $ref = ['t' => 'ref', 'at' => $at, 'item' => $about];
                if ($st !== 'fixed') {
                    $locked = !empty($b['locked']);
                    $log = (array) ($b['log'] ?? []);
                    $ref += ['was' => $st] + array_filter(['wr' => (string) ($b['reason'] ?? ''), 'wd' => (int) ($b['dup'] ?? 0)]);   // a closed report's reason / duplicate, given back with it
                    $b = m3d_bug_set_status($b, 'fixed', $at);
                    $b['log'] = $log;   // the "Fixed in" line says it, not a "Status set to" one
                    if (!$locked && !empty($b['locked'])) $ref['lock'] = true;
                }
                $b['log'][] = $ref;
                $b['act'] = $at;
            }
        }
        unset($b);
        return [$bugs, $next, true];
    });
    if ($ok === null) m3d_problem('Bug reports', 'Could not update the reports named in the changelog.');
}

/** A report whose "#6" left that item's changelog: its "Fixed in" line goes, and the status it had comes back if nothing changed it since. */
function m3d_bug_unref(array $b, string $about): array
{
    $log = array_values((array) ($b['log'] ?? []));
    $last = null;   // the last status change: a 'ref' that set Fixed, or an 's'
    foreach ($log as $i => $x) if (($x['t'] ?? '') === 's' || (($x['t'] ?? '') === 'ref' && isset($x['was']))) $last = $i;
    foreach ($log as $i => $x) {
        if (($x['t'] ?? '') !== 'ref' || ($x['item'] ?? '') !== $about) continue;
        if ($i === $last && ($b['status'] ?? '') === 'fixed' && isset($x['was'])) {
            $b['status'] = (string) $x['was'];
            unset($b['fixed']);
            if (!empty($x['wr'])) $b['reason'] = (string) $x['wr'];
            if (!empty($x['wd'])) $b['dup'] = (int) $x['wd'];
            if (!empty($x['lock'])) unset($b['locked']);
        }
        unset($log[$i]);
    }
    $b['log'] = array_values($log);
    return $b;
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

/** A report with this person's new comment at the end of its log (followed by them; a visitor's comment makes it "not seen" again). */
function m3d_bug_add_comment(array $b, array $p, string $text, int $now, bool $admin): array
{
    $b['log'][] = ['t' => 'c', 'by' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'at' => $now, 'text' => $text] + ($admin ? ['admin' => true] : [])
        + (!$admin && m3d_is_mod($GLOBALS['M3D']) ? ['mod' => true] : []);   // "Moderator" after the name
    $b['act'] = $now;
    if (!$admin) {   // a new comment: the report is "not seen" again, and the bell tells new comments from a new report
        if (!empty($b['seen'])) $b['seen_at'] = $now - 1;
        unset($b['seen']);
    }
    return m3d_bug_follow($b, $p, $now);
}

/** Log entries of a bug that are new for someone who last opened it at $seen: status changes ("Fixed in" lines while they show, bug_ref_event()) and other people's comments. */
function m3d_bug_new_entries(array $b, int $seen, string $patronId): array
{
    return array_filter((array) ($b['log'] ?? []), fn($x) => (int) ($x['at'] ?? 0) > $seen && empty($x['hid'])
        && (($x['t'] ?? '') === 's' || (($x['t'] ?? '') === 'ref' && ($x['item'] ?? '') === ($b['about'] ?? '')) ||
            (($x['t'] ?? '') === 'c' && (!empty($x['admin']) || (string) ($x['by'] ?? '') !== $patronId))));
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
    return count(array_diff($types, ['s', 'ref'])) === 0 && $k === 1 ? 'New status' : $k . ' new';
}

/** How many followed reports have something new (the number next to "Bugs" in the menu). */
function m3d_bug_news_count(?array $p): int
{
    if (!m3d_bug_can_follow($p) || !in_array(m3d_like_code((string) $p['id']), m3d_bug_summary()['news'], true)) return 0;   // the usual case: no report read
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

/** A text too short to send: under $min characters, unless it holds an emoji (a lone "👍" is one character, and a fine comment). */
function m3d_text_short(string $s, int $min = 2): bool
{
    return mb_strlen($s) < $min && !preg_match('/\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]/u', $s);
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
        if (!preg_match('#^https?://[^\s<>"]{3,500}$#iD', $u) || !filter_var($u, FILTER_VALIDATE_URL)) continue;
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
            unset($sm, $big);
            $out[] = '/media/bugs/' . basename($path);
        } catch (Throwable $ex) {
            return [$out, 'The picture could not be saved.'];
        } finally {
            unset($im);
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

/** Short Discord post about a new report (Settings → Notifications → New Bug Reports, the creator's channel). A failure is only logged. */
function m3d_bug_notify(array $b): void
{
    $a = m3d_bug_about($b);
    $about = trim($a['name'] . ' ' . (string) ($b['ver'] ?? ''));   // ver: free text for website / other reports
    $facts = [['About', '**' . m3d_notify_plain($about, 160) . '**']];
    $bt = m3d_bug_tool();
    foreach (['Blender' => (string) ($b['blender'] ?? ''), ($bt['name'] ?? 'Add-on') => $bt ? (string) ($b['mui'] ?? '') : ''] as $k => $v) {
        if (trim($v) !== '') $facts[] = [$k, '**' . m3d_notify_plain(trim($v), 40) . '**'];
    }
    $facts[] = ['Reported By', '**' . m3d_notify_plain((string) ($b['name'] ?? ''), 80) . '**'];
    m3d_notify_post('bugs', [['title' => mb_substr('#' . $b['n'] . ' ' . $b['title'], 0, 250), 'url' => abs_url('/bugs/' . $b['n'] . '/'),
        'description' => mb_substr(m3d_notify_quote('', (string) $b['body'], 300), 0, 700),   // what it is about: the facts (the plain post shows the title)
        'color' => hexdec('e8b931'), 'footer' => ['text' => 'New bug report · ' . mb_substr((string) ($b['name'] ?? ''), 0, 60) . (!empty($b['pending']) ? ' · waiting for you' : '')],
        'sub' => '🐞 New Bug Report' . (!empty($b['pending']) ? ' · ⏳ Waiting for You' : ''), 'facts' => $facts]]);
}

/** Short Discord post about a visitor's comment on a report (Settings → Notifications → Comments on Bug Reports). */
function m3d_bug_comment_notify(array $b, string $name, string $text): void
{
    m3d_notify_post('bugc', [['title' => mb_substr('Comment on #' . $b['n'] . ' ' . $b['title'], 0, 250), 'url' => abs_url('/bugs/' . $b['n'] . '/#comments'),
        'description' => mb_substr(m3d_notify_quote($name, $text, 400), 0, 800), 'color' => hexdec('7cb8ff'), 'footer' => ['text' => 'Bug report comment']]]);
}

// ── Pieces of pages ──────────────────────────────────────────────────────────
/** The message of a ?done= / ?err= code on the bug pages (the creator's changes: admin/bug.php, admin/bugs.php, bugs.php): fixed texts,
 *  never text from the address. A number may follow the code ("deleted-12" = report #12, "changed-3" = 3 reports); '' = unknown code. */
function bug_result_text(string $code, bool $err = false): string
{
    if (!preg_match('/^([a-z]+)(?:-(\d{1,9}))?$/', $code, $m)) return '';
    $n = (int) ($m[2] ?? 0);
    if ($err) return match ($m[1]) {
        'status'   => 'Pick a status.',
        'dup'      => 'Duplicate of: write the number of another report.',
        'title'    => 'The report needs a title.',
        'about'    => 'Pick what the report is about.',
        'empty'    => 'A comment can’t be empty: delete it instead.',
        'block'    => 'This person can’t be blocked here.',
        'unblock'  => 'This person can’t be unblocked here.',
        'action'   => 'Unknown action.',
        'write'    => 'Could not save: check that ' . basename(M3D_PRIVATE) . '/data is writable.',
        'bugsfile' => 'Could not write ' . basename(M3D_PRIVATE) . '/data/bugs.json. Check that the folder is writable.',
        'settings' => m3d_write_error(),
        'tagmax'   => '60 tags at most.',
        'none'     => 'Tick the reports to change first.',
        'op'       => 'Pick what to do with the ticked reports.',
        default    => '',
    };
    return match ($m[1]) {
        'saved'     => 'Saved.',
        'title'     => 'Title changed.',
        'edited'    => 'Report changed.',
        'published' => 'Report published.',
        'cdeleted'  => 'Comment deleted.',
        'cchanged'  => 'Comment changed.',
        'blocked'   => 'Blocked.',
        'unblocked' => 'Unblocked.',
        'added'     => "Report #$n added.",
        'deleted'   => "Report #$n deleted.",
        'tags'      => 'Tags saved.',
        'changed'   => m3d_plural($n, 'report') . ' changed.',
        'removed'   => m3d_plural($n, 'report') . ' deleted.',
        default     => '',
    };
}

/** The ✓ / "Not saved" messages of ?done= / ?err= (bug_result_text()), for the creator only; ?wait=1 (too many Me Too / Follow presses) for anyone. */
function bug_result_flash(bool $creator): void
{
    if ($creator && ($t = bug_result_text(m3d_get_text('done'))) !== '') site_flash('✓ ' . e($t));
    if ($creator && ($t = bug_result_text(m3d_get_text('err'), true)) !== '') site_flash('<b>Not saved:</b> ' . e($t), 'err');
    if (!empty($_GET['wait'])) site_flash('You pressed Me Too or Follow many times in the last hour: please wait a little before pressing again.', 'err');
}

/** A version label as words: "2" → "Version 2"; "Beta 2", "V3" stay. */
function bug_version_words(string $label): string
{
    return preg_match('/^\d/', $label) ? 'Version ' . $label : $label;
}

function bug_status_label(array $b): string
{
    $st = (string) ($b['status'] ?? 'new');
    $label = (BUG_STATUSES[$st] ?? BUG_STATUSES['new'])[0];
    if ($st === 'fixed' && ($fx = m3d_bug_fixed_in($b)) !== '') $label = 'Fixed in ' . bug_version_words($fx);
    if ($st === 'closed' && isset(BUG_REASONS[$b['reason'] ?? ''])) {
        $label = 'Closed · ' . ($b['reason'] === 'duplicate' && (int) ($b['dup'] ?? 0) > 0 ? 'Duplicate of #' . (int) $b['dup'] : BUG_REASONS[$b['reason']]);
    }
    return $label;
}

/** The timeline line of a report named in a changelog: "Fixed in <Fantasy Dress Version 2>" linked to that changelog; '' once the item or the "#6" is gone. */
function bug_ref_event(array $b, array $x): string
{
    [$kind, $slug] = m3d_bug_about_parts((string) ($x['item'] ?? ''));
    $item = $slug !== '' && ($x['item'] ?? '') === ($b['about'] ?? '') ? m3d_item($kind, $slug) : null;
    if (!$item || ($ver = m3d_bug_fixed_in($b, $item)) === '') return '';
    return 'Fixed in <a class="badge tone-' . BUG_STATUSES['fixed'][1] . ' ev-ref" href="' . e(m3d_version_url($kind, $item, $ver)) . '">' . e(m3d_model_name($item) . ' ' . bug_version_words($ver)) . '</a>';
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
    $d = ['kind' => ['models' => 'model', 'outfits' => 'outfit', 'scenes' => 'scene'][$kind] ?? $kind, 'name' => $label];
    if ($slug !== '' && ($it = m3d_item($kind, $slug))) {
        $d['sub'] = $kind === 'models' ? (string) ($it['franchise'] ?? '') : m3d_item_type($kind, $it);
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
    'react'   => '<path d="M20.5 11.5A8.5 8.5 0 1 1 12.5 3.5"/><path d="M8.5 14.5c.9 1.2 2.1 1.8 3.5 1.8s2.6-.6 3.5-1.8"/><path d="M9 10h.01M15 10h.01M19 2v6M16 5h6"/>',
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
    'open'    => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/>',
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
        [m3d_bug_tool()['name'] ?? '', m3d_bug_tool() ? (string) ($b['mui'] ?? '') : '', BUG_ICONS['ui']],
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

/** "2 hours ago", "3 days ago", then the date (site time). */
function bug_ago(int $t): string
{
    $d = time() - $t;
    if ($d < 90) return 'just now';
    if ($d < 3600) return m3d_plural((int) round($d / 60), 'minute') . ' ago';
    if ($d < 86400) return m3d_plural((int) round($d / 3600), 'hour') . ' ago';
    if ($d < 86400 * 14) return m3d_plural((int) round($d / 86400), 'day') . ' ago';
    return 'on ' . m3d_site_time($t, m3d_site_time($t, 'Y') === m3d_site_time(time(), 'Y') ? 'j M' : 'j M Y');
}

/** The round picture of a post's author: their Patreon picture, else the initial of the name on a hue from the name. */
function bug_avatar(string $name, bool $creator = false, string $id = ''): string
{
    return avatar_html($id, $name, $creator);   // the Patreon picture when there is one (app/avatars.php)
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
    if ($info['open']) $h .= '<a class="bug-box-more" href="/bugs/?about=' . e(rawurlencode($about)) . '">' . bug_icon('open') . '<span>' . m3d_plural($info['open'], 'open report') . ' →</span></a>';   // none: only the title and Report It
    return $h . '</section>';
}
