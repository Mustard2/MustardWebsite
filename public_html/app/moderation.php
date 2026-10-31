<?php
// Moderators (Admin → Moderation → Moderators): people the creator trusts to check what visitors post. A moderator logs in with their own Patreon
// account (no second password) and works on the Moderation page (admin/moderation.php: the only admin page a moderator opens, under a "Mustard3D Mod" header) and on the pages themselves (the ⋯ menus of comments,
// bug report comments and ideas); every change goes to auth/moderate.php. They can approve what waits, hide (never delete) and ban from what
// they check; hidden posts stay, greyed, for moderators and the creator, and only the creator can delete them for good or undo what a
// moderator did (the log). A moderator is never an admin: m3d_is_admin() doesn't know them, so the admin, drafts, password items, Patreon IDs
// and downloads stay closed to them.
//
// settings.json → moderation: {mods: [{id (Patreon id), name, on, added}], can: {comments, bugs, ideas, ban}, bell: {Patreon id: [unix, 1 | 0]}} (kept as it is now by both
// restores, like owners: an old backup never brings back a moderator who was removed).
// A hidden post (comment, bug report, bug report comment, idea) carries `hid` {at, by, name, why} (a bug report also its old `hidden`).
// data/modlog.json: {next, log: [{id, at, by, name, act (approve | hide | show | ban | delete), type (MOD_TYPES or person), ref, about (the author's
// Patreon id), aname (their name), what (a short label), why, kinds [ban kinds turned on], was {pending?, hid?}, undone?}]}, oldest first, the newest
// MODLOG_KEEP kept; written only through m3d_modlog_update(); restored with the counters; in privacy.php and Personal Data. A moderator's steps go to
// the creator's bell (event `mods`, m3d_modlog_summary()) and, when switched on, their private Discord channel.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const MOD_KINDS = ['comments' => 'Comments', 'bugs' => 'Bug Reports', 'ideas' => 'Ideas'];   // what moderators can check, and ban from
// type => [label, what it belongs to]
const MOD_TYPES = ['comment' => ['Comment', 'comments'], 'bug' => ['Bug Report', 'bugs'], 'bugc' => ['Bug Comment', 'bugs'], 'idea' => ['Idea', 'ideas']];
const MOD_WHY_MAX = 200;
const MODLOG_KEEP = 2000;
const MOD_PER_PAGE = 30;
const MOD_MAX = 20;   // moderators at most

function m3d_mod_settings(): array
{
    $s = m3d_settings()['moderation'] ?? [];
    $s = is_array($s) ? $s : [];
    $mods = [];
    foreach ((array) ($s['mods'] ?? []) as $m) {
        if (!is_array($m) || !preg_match('/^\d{1,20}$/D', (string) ($m['id'] ?? ''))) continue;
        $mods[(string) $m['id']] = ['id' => (string) $m['id'], 'name' => (string) ($m['name'] ?? ''), 'on' => !empty($m['on']), 'added' => (string) ($m['added'] ?? '')];
    }
    $can = is_array($s['can'] ?? null) ? $s['can'] : [];
    $out = [];
    foreach ([...array_keys(MOD_KINDS), 'ban'] as $k) $out[$k] = (bool) ($can[$k] ?? true);
    return ['mods' => array_values($mods), 'can' => $out];
}

function m3d_mod_settings_save(array $changes): bool
{
    return m3d_save_setting_keys('moderation', $changes);
}

/** The Patreon ids of the moderators switched on (never an admin). */
function m3d_mod_ids(): array
{
    $owners = m3d_owner_ids($GLOBALS['M3D']);
    return array_values(array_filter(array_map(fn($m) => $m['on'] ? $m['id'] : '', m3d_mod_settings()['mods']), fn($id) => $id !== '' && !in_array($id, $owners, true)));
}

/** Is this Patreon id a moderator (switched on)? */
function m3d_is_mod_id(string $id): bool
{
    return $id !== '' && in_array($id, m3d_mod_ids(), true);
}

/**
 * The role's row in the person's bell (app/bell.php): settings.json → moderation.bell {Patreon id: [unix, 1 = made a moderator (added or switched on) |
 * 0 = no longer (removed or switched off)]}, the newest change each, kept UBELL_DAYS. m3d_mod_bell_with(): the map with this change, to save with the mods.
 */
function m3d_mod_bell_with(string $id, bool $on): array
{
    $old = time() - UBELL_DAYS * 86400;
    $bell = array_filter((array) (m3d_settings()['moderation']['bell'] ?? []), fn($v) => is_array($v) && (int) ($v[0] ?? 0) > $old);
    $bell[$id] = [time(), $on ? 1 : 0];
    return $bell;
}

/** This person's role change for the bell: [unix, made a moderator?], while it still holds (a moderator now / not one now), else null. */
function m3d_mod_bell_ref(string $id): ?array
{
    $v = m3d_settings()['moderation']['bell'][$id] ?? null;
    if (!is_array($v) || (int) ($v[0] ?? 0) <= time() - UBELL_DAYS * 86400) return null;
    $on = !empty($v[1]);
    return $on === m3d_is_mod_id($id) ? [(int) $v[0], $on] : null;
}

/** The login is a moderator's (checked against the CURRENT settings on every request, so removing one works on their next click). */
function m3d_is_mod(array $cfg): bool
{
    $p = $_SESSION['patron'] ?? null;
    if (!is_array($p) || m3d_is_admin($cfg)) return false;
    $id = (string) ($p['id'] ?? '');
    return m3d_is_mod_id($id) && !in_array('login', m3d_bans($id), true);   // banned from logging in: no longer moderates (m3d_patron() ends the login)
}

/** Who is moderating now: 'creator' (an admin, not previewing as a patron), 'mod', or ''. */
function m3d_mod_role(): string
{
    $M3D = $GLOBALS['M3D'];
    if (m3d_is_admin($M3D)) return empty(m3d_patron($M3D)['preview']) ? 'creator' : '';
    return m3d_is_mod($M3D) ? 'mod' : '';
}

/** Is $kind (comments | bugs | ideas) on for the site? */
function m3d_mod_kind_on(string $kind): bool
{
    return match ($kind) { 'comments' => m3d_comments_on(), 'bugs' => m3d_bugs_on(), 'ideas' => m3d_ideas_on(), default => false };
}

/** May the person moderating now check $what (comments | bugs | ideas) or ban ('ban')? The creator always; a moderator as
 *  "What Moderators Can Do" says, and only while that feature is on. */
function m3d_mod_can(string $what): bool
{
    $role = m3d_mod_role();
    if ($role === 'creator') return true;
    if ($role !== 'mod') return false;
    $can = m3d_mod_settings()['can'];
    if ($what === 'ban') return !empty($can['ban']);
    return !empty($can[$what]) && m3d_mod_kind_on($what);
}

/** The kinds the person moderating now checks (the Moderation page's filter, its link in the account menu). */
function m3d_mod_kinds(): array
{
    return array_values(array_filter(array_keys(MOD_KINDS), fn($k) => m3d_mod_can($k) && (m3d_mod_role() === 'mod' || m3d_mod_kind_on($k))));
}

/** The mark a hidden post carries. */
function m3d_mod_mark(string $why): array
{
    $p = (array) ($_SESSION['patron'] ?? []);
    return ['at' => time(), 'by' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? ''), 'why' => mb_substr(trim($why), 0, MOD_WHY_MAX)];
}

/** Whether the creator hid it (their own Hide, or a bug report hidden in Creator Tools: a mark without a name): then only the creator shows it again. */
function m3d_mod_hid_by_creator(?array $hid): bool
{
    $by = (string) ($hid['by'] ?? '');
    return $hid !== null && ($by === '' || in_array($by, m3d_owner_ids($GLOBALS['M3D']), true));
}

/** "Hidden by Sara · Spam · 2 hours ago" (the creator: "by the creator"); '' for a post that isn't hidden. */
function mod_hid_line(?array $hid, bool $tags = true): string
{
    if (!$hid) return '';
    $who = m3d_mod_hid_by_creator($hid) ? 'the creator'
        : '<b>' . e(($hid['name'] ?? '') ?: 'a moderator') . '</b>' . ($tags ? ' <span class="mod-tag">Moderator</span>' : '');
    $why = trim((string) ($hid['why'] ?? ''));
    return 'Hidden by ' . $who . ((int) ($hid['at'] ?? 0) ? ' · ' . e(bug_ago((int) $hid['at'])) : '') . ($why !== '' ? ' · Reason: <b>' . e($why) . '</b>' : '');
}

/**
 * Is this post (a comment, idea, bug report or bug comment) a moderator's? Posted as one (`mod`, stamped when written), or written by
 * someone who moderates now, so posts from before they became one get the tag too. Never the creator's.
 */
function m3d_posted_by_mod(array $x): bool
{
    static $ids = null;
    if (!empty($x['admin'])) return false;
    if (!empty($x['mod'])) return true;
    $by = (string) ($x['by'] ?? '');
    if ($by === '') return false;
    $ids ??= array_flip(array_filter(m3d_mod_ids(), fn($id) => !in_array('login', m3d_bans($id), true)));   // like m3d_is_mod()
    return isset($ids[$by]);
}

/** The "Moderator" tag after the name of a moderator's post. */
function mod_tag(array $x): string
{
    return m3d_posted_by_mod($x) ? '<span class="mod-tag">Moderator</span>' : '';
}

// ── What can be moderated ────────────────────────────────────────────────────
/**
 * One post as the Moderation page and auth/moderate.php see it, or null when it is gone: type, ref, kind (comments | bugs | ideas), by, name,
 * at, title, text, page (its page's name), url, pending, hid (the mark, or null), view (the person moderating now sees its page).
 * Refs: a comment's or idea's id, a report's number, "n:i:at" for a report's comment (i = its place in the timeline, at = when it was written).
 */
function m3d_mod_target(string $type, string $ref): ?array
{
    $base = ['type' => $type, 'ref' => $ref, 'kind' => MOD_TYPES[$type][1] ?? '', 'title' => ''];
    if ($type === 'comment') {
        $f = m3d_comment_find((int) $ref);
        if (!$f) return null;
        [$k, $key, , $c] = $f;
        [$page, $url, $it] = m3d_comment_page($k, $key);
        $view = $it && ($k === 'posts' ? m3d_post_view($it) === 'full' : m3d_can_view($k, $it));
        return $base + ['by' => (string) ($c['by'] ?? ''), 'name' => (string) ($c['name'] ?? ''), 'at' => (int) ($c['at'] ?? 0), 'text' => (string) ($c['text'] ?? ''),
            'page' => $page, 'url' => $url !== '' ? $url . '#cm-' . (int) $ref : '', 'pending' => !empty($c['pending']), 'hid' => m3d_mod_hid($c), 'view' => $view, 'admin' => !empty($c['admin'])];
    }
    if ($type === 'bug' || $type === 'bugc') {
        [$n, $i, $at] = array_map('intval', explode(':', $ref . '::'));
        $b = m3d_bug($n);
        if (!$b) return null;
        [$k, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
        $view = $slug === '' || (($it = m3d_item($k, $slug)) && m3d_can_view($k, $it));
        $page = 'Bug #' . $n;
        if ($type === 'bug') {
            return array_merge($base, ['by' => (string) ($b['by'] ?? ''), 'name' => (string) ($b['name'] ?? ''), 'at' => (int) ($b['at'] ?? 0), 'title' => (string) ($b['title'] ?? ''),
                'text' => (string) ($b['body'] ?? ''), 'page' => m3d_bug_about($b)['name'], 'url' => '/bugs/' . $n . '/', 'pending' => !empty($b['pending']),
                'hid' => !empty($b['hidden']) ? (m3d_mod_hid($b) ?? ['at' => 0, 'by' => '', 'name' => '', 'why' => '']) : null, 'view' => $view, 'admin' => false]);
        }
        $i = m3d_bug_comment_at($b, $i, $at);
        if ($i === null) return null;
        $x = $b['log'][$i];
        return array_merge($base, ['ref' => "$n:$i:" . (int) ($x['at'] ?? 0)]) + ['by' => (string) ($x['by'] ?? ''), 'name' => (string) ($x['name'] ?? ''), 'at' => (int) ($x['at'] ?? 0),
            'text' => (string) ($x['text'] ?? ''), 'page' => $page, 'url' => '/bugs/' . $n . '/#c' . $i, 'pending' => false, 'hid' => m3d_mod_hid($x), 'view' => $view, 'admin' => !empty($x['admin']), 'n' => $n, 'i' => $i];
    }
    if ($type === 'idea') {
        $f = m3d_idea_find((int) $ref);
        if (!$f) return null;
        [$k, $slug, $x] = $f;
        $it = m3d_item($k, $slug);
        [$page, $url] = m3d_idea_page($k, $slug);
        return array_merge($base, ['by' => (string) ($x['by'] ?? ''), 'name' => (string) ($x['name'] ?? ''), 'at' => (int) ($x['at'] ?? 0), 'title' => (string) ($x['title'] ?? ''),
            'text' => (string) ($x['text'] ?? ''), 'page' => $page, 'url' => $url !== '' ? $url . '#idea-' . (int) $ref : '', 'pending' => !empty($x['pending']), 'hid' => m3d_mod_hid($x),
            'view' => $it && m3d_can_view($k, $it), 'admin' => !empty($x['admin']), 'itype' => m3d_idea_types($k)[$x['type'] ?? ''] ?? '']);
    }
    return null;
}

/** A post's hidden mark, or null. */
function m3d_mod_hid(array $x): ?array
{
    return is_array($x['hid'] ?? null) ? $x['hid'] : null;
}

/** The place of a report's comment in its timeline: $i when it is still the comment written at $at, else the one written then; null when gone. */
function m3d_bug_comment_at(array $b, int $i, int $at): ?int
{
    $log = (array) ($b['log'] ?? []);
    if (($log[$i]['t'] ?? '') === 'c' && (!$at || (int) ($log[$i]['at'] ?? 0) === $at)) return $i;
    if ($at) foreach ($log as $j => $x) if (($x['t'] ?? '') === 'c' && (int) ($x['at'] ?? 0) === $at) return (int) $j;
    return null;
}

/** May the person moderating now act on this post? (Its kind is theirs, and they see its page.) */
function m3d_mod_may(array $t): bool
{
    return m3d_mod_can($t['kind']) && (m3d_mod_role() === 'creator' || $t['view']);
}

/**
 * The posts of the Moderation page: $tab waiting (waiting for a check) | hidden, of the kinds the person moderating now checks ($kind: one of
 * them, '' = all), newest first (hidden ones by when they were hidden). Each one as m3d_mod_target() gives it.
 */
function m3d_mod_list(string $tab, string $kind = ''): array
{
    $kinds = $kind !== '' ? array_intersect([$kind], m3d_mod_kinds()) : m3d_mod_kinds();
    $hidden = $tab === 'hidden';
    $refs = [];
    if (in_array('comments', $kinds, true)) foreach (m3d_comments_flat() as [, , $c]) {
        if ($hidden ? m3d_mod_hid($c) : !empty($c['pending']) && !m3d_mod_hid($c)) $refs[] = ['comment', (string) (int) ($c['id'] ?? 0)];
    }
    if (in_array('bugs', $kinds, true)) foreach (m3d_bugs() as $b) {
        $n = (int) $b['n'];
        if ($hidden ? !empty($b['hidden']) : !empty($b['pending']) && empty($b['hidden'])) $refs[] = ['bug', (string) $n];
        if ($hidden) foreach ((array) ($b['log'] ?? []) as $i => $x) if (($x['t'] ?? '') === 'c' && m3d_mod_hid($x)) $refs[] = ['bugc', "$n:$i:" . (int) ($x['at'] ?? 0)];
    }
    if (in_array('ideas', $kinds, true)) foreach (m3d_ideas_flat() as [, , $x]) {
        if ($hidden ? m3d_mod_hid($x) : !empty($x['pending']) && !m3d_mod_hid($x)) $refs[] = ['idea', (string) (int) ($x['id'] ?? 0)];
    }
    $out = [];
    foreach ($refs as [$type, $ref]) if (($t = m3d_mod_target($type, $ref)) && m3d_mod_may($t)) $out[] = $t;
    $when = fn($t) => $hidden && $t['hid'] ? max((int) ($t['hid']['at'] ?? 0), $t['at']) : $t['at'];
    usort($out, fn($a, $b) => $when($b) <=> $when($a));
    return $out;
}

/**
 * How many posts wait for a check, from the small summaries only (the account menu's number on every page): each summary lists the page
 * of every waiting post ("kind:key", a report's `about`), so a moderator's number leaves out the pages they can't open, like the list does.
 */
function m3d_mod_waiting_count(): int
{
    $all = m3d_mod_role() === 'creator';
    $ok = [];   // page => whether this person sees it, worked out once
    $sees = function (string $kind, string $key) use (&$ok, $all): bool {
        if ($all || $key === '') return true;
        return $ok["$kind:$key"] ??= (function () use ($kind, $key) {
            if ($kind === 'posts') return ($p = m3d_post($key)) && m3d_post_view($p) === 'full';
            return isset(M3D_KINDS[$kind]) && ($it = m3d_item($kind, $key)) && m3d_can_view($kind, $it);
        })();
    };
    $n = 0;
    foreach (m3d_mod_kinds() as $k) {
        $wait = $k === 'comments' ? m3d_comment_summary()['wait'] : ($k === 'ideas' ? m3d_idea_summary()['wait'] : array_map(fn($a) => implode(':', m3d_bug_about_parts((string) $a)), m3d_bug_summary()['wait']));
        foreach ($wait as $w) { [$kind, $key] = explode(':', $w . ':', 3); $n += $sees($kind, $key) ? 1 : 0; }
    }
    return $n;
}

// ── Changes ──────────────────────────────────────────────────────────────────
/**
 * Approves, hides, shows again or deletes ($do) one post (a target of m3d_mod_target()); $why: the reason of a hide. Returns what it was before
 * ({pending?, hid?}, for the log and Undo), or null when nothing could be saved. "Delete" is the creator's only (auth/moderate.php checks it).
 */
function m3d_mod_apply(array $t, string $do, string $why = '', ?array $restore = null): ?array
{
    $was = array_filter(['pending' => $t['pending'] ? 1 : null, 'hid' => $t['hid']]);
    $mark = $do === 'hide' ? m3d_mod_mark($why) : null;
    // the change to one record: approve = published; hide = the mark (no longer waiting); show = the mark goes; undo = $restore put back
    $fn = function (array $x) use ($do, $mark, $restore, $t) {
        if ($do === 'delete') return null;
        unset($x['pending'], $x['hid']);
        if ($t['type'] === 'bug') unset($x['hidden']);
        if ($do === 'hide') $x['hid'] = $mark;
        if ($do === 'restore') {
            if (!empty($restore['pending'])) $x['pending'] = true;
            if (!empty($restore['hid'])) $x['hid'] = $restore['hid'];
        }
        if ($t['type'] === 'bug' && !empty($x['hid'])) $x['hidden'] = true;
        if ($t['type'] === 'bug' && $do === 'approve') $x['seen'] = true;
        return $x;
    };
    $ok = match ($t['type']) {
        'comment' => m3d_comment_change((int) $t['ref'], $fn),
        'idea' => m3d_idea_change((int) $t['ref'], $fn),
        'bug' => $do === 'delete' ? m3d_mod_bug_delete((int) $t['ref']) : (bool) m3d_bug_change((int) $t['ref'], $fn),
        'bugc' => (bool) m3d_bug_change((int) $t['n'], function (array $b) use ($t, $fn) {
            $i = m3d_bug_comment_at($b, (int) $t['i'], (int) $t['at']);
            if ($i === null) return $b;
            $new = $fn($b['log'][$i]);
            if ($new === null) unset($b['log'][$i]); else $b['log'][$i] = $new;
            $b['log'] = array_values($b['log']);
            return $b;
        }),
        default => false,
    };
    if (!$ok) return null;
    if ($do !== 'show' && $do !== 'restore') {   // read in the creator's bell too, as Publish there does (a hidden one is no news for them)
        if ($t['type'] === 'comment') m3d_notify_read('c' . (int) $t['ref']);
        if ($t['type'] === 'idea') m3d_notify_read('i' . (int) $t['ref']);
    }
    return $was ?: [];
}

/** A report deleted for good (its pictures too). */
function m3d_mod_bug_delete(int $n): bool
{
    $b = m3d_bug($n);
    if (!$b) return false;
    $pics = (array) ($b['pics'] ?? []);
    m3d_bug_change($n, fn() => null);
    if (m3d_bug($n)) return false;
    bug_delete_pics($pics);
    return true;
}

/** The posts of $id waiting for a check in $kinds (comments | bugs | ideas): the ban window's "Also Hide Their Waiting Posts". */
function m3d_mod_waiting_of(string $id, array $kinds): array
{
    $out = [];
    foreach (m3d_mod_list('waiting') as $t) if ($t['by'] === $id && in_array($t['kind'], $kinds, true)) $out[] = $t;
    return $out;
}

// ── The log ──────────────────────────────────────────────────────────────────
function m3d_modlog_update(callable $fn): mixed
{
    return m3d_json_update('modlog', function ($d) use ($fn) {
        $d = is_array($d) ? $d : [];
        $all = ['next' => max(1, (int) ($d['next'] ?? 1)), 'log' => array_values(array_filter((array) ($d['log'] ?? []), 'is_array'))];
        [$all, $res] = $fn($all);
        $all['log'] = array_slice(array_values($all['log']), -MODLOG_KEEP);
        return [$all, $res];
    });
}

/** Every entry, newest first. */
function m3d_modlog(): array
{
    $d = m3d_read_json('modlog');
    return array_reverse(array_values(array_filter((array) ($d['log'] ?? []), 'is_array')));
}

/** Adds one entry for what the person moderating now did. $t: the post (or null for a ban alone, with $about / $what). */
function m3d_modlog_add(string $act, ?array $t, array $extra = []): void
{
    $p = (array) ($_SESSION['patron'] ?? []);
    $e = ['at' => time(), 'by' => (string) ($p['id'] ?? ''), 'name' => (string) ($p['name'] ?? ''), 'act' => $act];
    if ($t) $e += ['type' => $t['type'], 'ref' => $t['ref'], 'about' => $t['by'], 'aname' => $t['name'], 'what' => mod_target_label($t)];
    $e = array_merge($e, array_filter($extra, fn($v) => $v !== null && $v !== '' && $v !== []));
    $id = m3d_modlog_update(function (array $all) use ($e) { $id = $all['next']++; $all['log'][] = ['id' => $id] + $e; return [$all, $id]; });
    if ($id === null) { m3d_problem('Moderation', 'The moderation log could not be saved.'); return; }
    if (!in_array($e['by'], m3d_owner_ids($GLOBALS['M3D']), true) && m3d_notify_discord_on('mods')) {   // a moderator's step: the creator's private channel
        $e['id'] = $id;
        m3d_after_response(fn() => m3d_notify_post('mods', [['title' => mb_substr(implode(' ', mod_bell_words($e)), 0, 250), 'url' => abs_url('/admin/moderation.php?tab=log#ml-' . $id),
            'description' => discord_md_text(mod_bell_sub($e)), 'color' => hexdec('7cc4ff'), 'sub' => '🛡️ Moderator Action']]));
    }
}

/** A log entry in words for the creator's bell and Discord: [who, what, on]: Sara · hid a comment by · Anna on Tifa; Sara · banned · Anna (bans: mod_bell_sub()). */
function mod_bell_words(array $e): array
{
    $who = (string) ($e['name'] ?? '') ?: 'A moderator';
    $what = (string) ($e['what'] ?? '');
    if (($e['act'] ?? '') === 'ban') {   // entries from before `aname`: the name in the label ("Anna on Tifa", "#12 Title by Anna")
        $name = (string) ($e['aname'] ?? '') ?: (($e['type'] ?? '') === 'bug' ? preg_replace('/^.* by /su', '', $what) : preg_replace('/ on .*$/su', '', $what));
        return [$who, 'banned', (string) $name ?: 'someone'];
    }
    $words = mod_act_words($e);
    $words = mb_strtolower(mb_substr($words, 0, 1)) . mb_substr($words, 1);   // "hid a comment"
    if (($e['type'] ?? '') === 'bug') return [$who, $words, preg_replace('/^(#\d+) /u', '$1 · ', $what) ?? $what];
    return [$who, $what !== '' ? $words . ' by' : $words, $what];
}

/** The line under it: what a ban covers, the reason, "Undone". */
function mod_bell_sub(array $e): string
{
    $kinds = ($e['act'] ?? '') === 'ban' ? 'From ' . implode(', ', array_map(fn($k) => BAN_KINDS[$k][0] ?? $k, (array) ($e['kinds'] ?? []))) : '';
    return implode(' · ', array_filter([$kinds, ($e['why'] ?? '') !== '' ? 'Reason: ' . $e['why'] : '', !empty($e['undone']) ? 'Undone' : '']));
}

/**
 * The moderators' newest steps for the creator's bell (m3d_data_summary('modlog'): the bell's number on every page reads only this):
 * [[id, at, who, what, on, sub]] (mod_bell_words(), mod_bell_sub()), newest first, the last NOTIFY_GONE_DAYS days, the creator's own steps left out.
 */
function m3d_modlog_summary(): array
{
    if (!is_file(M3D_PRIVATE . '/data/modlog.json')) return [];
    return m3d_data_summary('modlog', function () {
        $owners = m3d_owner_ids($GLOBALS['M3D']);
        $old = time() - NOTIFY_GONE_DAYS * 86400;
        $out = [];
        foreach (m3d_modlog() as $e) {
            if ((int) ($e['at'] ?? 0) <= $old) break;
            if (in_array((string) ($e['by'] ?? ''), $owners, true)) continue;
            $out[] = [(int) ($e['id'] ?? 0), (int) ($e['at'] ?? 0), ...mod_bell_words($e), mod_bell_sub($e)];
            if (count($out) >= NOTIFY_LIST_MAX) break;
        }
        return $out;
    });
}

/** "Anna on Tifa", "Bug #12 by Marco": what an entry is about. */
function mod_target_label(array $t): string
{
    $who = $t['name'] !== '' ? $t['name'] : 'Someone';
    if ($t['type'] === 'bug') return '#' . (int) $t['ref'] . ' ' . mb_substr($t['title'], 0, 60) . ' by ' . $who;
    return $who . ' on ' . $t['page'];
}

/** The words of an entry: "Hid a comment", "Banned from Comments, Ideas". */
function mod_act_words(array $e): string
{
    $what = ['comment' => 'a comment', 'bug' => 'a bug report', 'bugc' => 'a bug comment', 'idea' => 'an idea'][$e['type'] ?? ''] ?? '';
    return match ($e['act'] ?? '') {
        'approve' => 'Approved ' . $what, 'hide' => 'Hid ' . $what, 'show' => 'Showed ' . $what . ' again', 'delete' => 'Deleted ' . $what,
        'ban' => 'Banned from ' . implode(', ', array_map(fn($k) => BAN_KINDS[$k][0] ?? $k, (array) ($e['kinds'] ?? []))),
        default => (string) ($e['act'] ?? ''),
    };
}

/** Marks an entry undone. */
function m3d_modlog_undone(int $id): void
{
    m3d_modlog_update(function (array $all) use ($id) {
        foreach ($all['log'] as &$e) if ((int) ($e['id'] ?? 0) === $id) $e['undone'] = time();
        return [$all, true];
    });
}

/** Personal Data: the entries about this person (their posts, their bans) and the ones they made as a moderator. */
function m3d_modlog_of(string $id): array
{
    return array_values(array_filter(m3d_modlog(), fn($e) => (string) ($e['about'] ?? '') === $id || (string) ($e['by'] ?? '') === $id));
}

/** Personal Data → delete: those entries go. Returns how many, or null when not saved. */
function m3d_modlog_forget(string $id): ?int
{
    if (!is_file(M3D_PRIVATE . '/data/modlog.json')) return 0;
    return m3d_modlog_update(function (array $all) use ($id) {
        $n = count($all['log']);
        $all['log'] = array_values(array_filter($all['log'], fn($e) => (string) ($e['about'] ?? '') !== $id && (string) ($e['by'] ?? '') !== $id));
        return [$all, $n - count($all['log'])];
    });
}

/** The ⋯ menu items for one post on its page, for a moderator or the creator: Approve, Hide / Show Again, Ban…; [] for anyone else. */
function mod_menu_items(string $type, string $ref, bool $pending, bool $hidden, string $by, string $name, string $back): array
{
    $kind = MOD_TYPES[$type][1] ?? '';
    if (!m3d_mod_can($kind)) return [];
    $keys = '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="type" value="' . e($type) . '" /><input type="hidden" name="ref" value="' . e($ref) . '" />'
        . '<input type="hidden" name="return" value="' . e($back) . '" />';
    $btn = fn(string $do, string $label) => '<form method="post" action="/auth/moderate.php">' . $keys . '<button class="pm-item" name="do" value="' . $do . '">' . $label . '</button></form>';
    $out = [];
    if ($pending && !$hidden) $out[] = $btn('approve', 'Approve');
    $creatorHid = $hidden && m3d_mod_role() !== 'creator' && m3d_mod_hid_by_creator(m3d_mod_target($type, $ref)['hid'] ?? null);   // theirs to show again
    if (!$creatorHid) $out[] = $hidden ? $btn('show', 'Show Again') : '<a class="pm-item" href="/admin/moderation.php?hide=' . e(rawurlencode("$type:$ref")) . '&amp;back=' . e(rawurlencode($back)) . '">Hide…</a>';
    if (m3d_mod_can('ban') && mod_can_ban($by) && !array_intersect(m3d_bans($by), [$kind, 'login'])) $out[] = '<a class="pm-item is-red" href="/admin/moderation.php?ban=' . e(rawurlencode("$type:$ref")) . '&amp;back=' . e(rawurlencode($back)) . '">Ban ' . e($name !== '' ? $name : 'Them') . '…</a>';
    return $out;
}

/** May the person moderating now ban this Patreon id? Never an admin, never themselves; a moderator never bans another moderator. */
function mod_can_ban(string $id): bool
{
    if (!preg_match('/^\d{1,20}$/D', $id) || in_array($id, m3d_owner_ids($GLOBALS['M3D']), true)) return false;
    if ($id === (string) ($_SESSION['patron']['id'] ?? '')) return false;
    return m3d_mod_role() === 'creator' || !m3d_is_mod_id($id);
}
