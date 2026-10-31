<?php
// Comments under model, accessory and blog post pages (Settings → Features → Comments, off by default). Posted to auth/comment.php.
//
// data/comments.json: {next, models: {slug: [c]}, outfits: {slug: [c]}, posts: {post id: [c]}}, oldest first, written only through
// m3d_comments_update() (m3d_json_update: locked, no copy per save; full backups carry it, restored with the counters). A comment c:
//   id (int, never reused), by (Patreon id), name (Patreon name then), at (unix), text, admin (the creator wrote it), mod (a moderator wrote it),
//   pending (waits for a check while "Check Before Showing" is on: only its author, the moderators and the creator see it), hid (hidden by a
//   moderator or the creator, app/moderation.php: kept, seen only by them), edited (unix, the creator edited it),
//   re (a reply: the id of the comment it is under; replies are one level deep) + to (the reply it answers, when it isn't that comment),
//   rx (reactions: {emoji of COMMENT_REACTIONS: [like codes]}, m3d_like_code(): no raw ids, like likes) + rxat (the newest one by someone else, for the author's bell)
// settings.json → comments: {on, models, outfits, posts (where they are on), who: any|paying, moderate, paid_skip (patrons on a paid tier skip the check), per_day, blocked [Patreon ids],
//   emoji (the 😊 picker button in the comment boxes + comments of 1–3 emoji only shown big), codes (":heart:" while typing), reactions (COMMENT_REACTIONS under each comment),
//   notify (old: read by m3d_notify_settings() until Settings → Notifications is saved, with the webhook of private/webhooks.php → comments)}. One item or post turns them off with `noComments` (its editor's "Allow Comments" switch): kept, not shown.
// Who sees them: whoever sees the page (item_gate(), m3d_post_view() = full); who writes: m3d_comment_can_post().
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const COMMENT_KINDS = ['models' => 'Models', 'outfits' => 'Accessories', 'scenes' => 'Scenes', 'posts' => 'Blog Posts'];
const COMMENT_MAX = 2000;
// the reactions under a comment (settings.json → comments.reactions), in this order
const COMMENT_REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🔥', '🙏'];
const COMMENT_RX_PER_HOUR = 120;   // reaction presses per session an hour (m3d_bug_toggle_ok())
// why auth/comment.php refused a comment (?cmerr=): the page says it ('limit' is worked out with the setting)
const COMMENT_ERRORS = [
    'session' => 'your session expired: please send it again.', 'login' => 'please log in with Patreon first.', 'short' => 'write a comment first.',
    'off' => 'comments are off here.', 'gone' => 'the comment you replied to is gone.', 'save' => 'your comment could not be saved. Please try again.', 'limit' => 'limit',
    'blocked' => 'you can’t comment any more.', 'wait' => 'too many reactions in a short time: please wait a little.', 'paying' => 'only patrons on a paid tier can comment.', 'preview' => 'not while previewing the site as a patron.',
];

function m3d_comment_settings(): array
{
    $s = m3d_settings()['comments'] ?? [];
    $s = is_array($s) ? $s : [];
    if (!empty($s['blocked'])) m3d_bans_move_blocked('comments', 'comments', array_map('strval', (array) $s['blocked']));
    $who = ($s['who'] ?? '') === 'paying' ? 'paying' : 'any';
    return [
        'on'       => !empty($s['on']),
        'models'   => (bool) ($s['models'] ?? true),
        'outfits'  => (bool) ($s['outfits'] ?? true),
        'scenes'   => (bool) ($s['scenes'] ?? true),
        'posts'    => (bool) ($s['posts'] ?? true),
        'who'      => $who,
        // never saved: on while anyone may comment (free members too), so a brand-new account isn't published at once
        'moderate' => array_key_exists('moderate', $s) ? !empty($s['moderate']) : $who === 'any',
        'paid_skip' => !empty($s['paid_skip']),   // "Skip for Paying Patrons": their comments show at once, others still wait
        'notify'   => !empty($s['notify']),
        'webhook'  => m3d_webhook('comments'),   // private/webhooks.php
        'per_day'  => max(1, min(100, (int) ($s['per_day'] ?? 10))),
        'emoji'    => !empty($s['emoji']),       // the picker button (site.js [data-emoji]) and big emoji-only comments
        'codes'    => !empty($s['codes']),       // ":heart:" codes while typing
        'reactions' => !empty($s['reactions']),
        'blocked'  => m3d_ban_ids('comments'),   // Admin → Access bans (the old list here moves there once)
    ];
}

/** Saves some keys of settings.json → comments, keeping the others (the blocked people…). */
function m3d_comment_settings_save(array $changes): bool
{
    return m3d_save_setting_keys('comments', $changes);
}

/** Comments are on for this kind of page (models | outfits | scenes | posts), site-wide. */
function m3d_comments_on(string $kind = ''): bool
{
    $s = m3d_comment_settings();
    return $s['on'] && ($kind === '' || !empty($s[$kind]));
}

/** Comments are shown under this item or post: on for its kind, and not turned off on it. */
function m3d_comments_open(string $kind, array $item): bool
{
    return m3d_comments_on($kind) && empty($item['noComments']);
}

/** The whole file ({next, models, outfits, posts}); $fresh: read it again (after a change in this request). */
function m3d_comments_all(bool $fresh = false): array
{
    static $all = null;
    if ($all === null || $fresh) {
        $d = m3d_read_json('comments');
        $d = is_array($d) ? $d : [];
        $all = ['next' => max(1, (int) ($d['next'] ?? 1))];
        foreach (array_keys(COMMENT_KINDS) as $k) $all[$k] = is_array($d[$k] ?? null) ? $d[$k] : [];
    }
    return $all;
}

/** The comments of one page, oldest first (every one: pending ones too, see m3d_comment_shown()). */
function m3d_comments(string $kind, string $key): array
{
    return array_values(array_filter((array) (m3d_comments_all()[$kind][$key] ?? []), 'is_array'));
}

/** Every comment as [kind, key, comment], newest first. */
function m3d_comments_flat(): array
{
    $out = [];
    $all = m3d_comments_all();
    foreach (array_keys(COMMENT_KINDS) as $k) foreach ($all[$k] as $key => $list) foreach ((array) $list as $c) if (is_array($c)) $out[] = [$k, (string) $key, $c];
    usort($out, fn($a, $b) => (int) ($b[2]['at'] ?? 0) <=> (int) ($a[2]['at'] ?? 0));
    return $out;
}

/**
 * Locked read-modify-write of the file: $fn(array $all) returns [$all, $result]. Returns $result, or null when nothing could be
 * saved (damaged or not writable file, see m3d_json_update()). Empty lists are dropped.
 */
function m3d_comments_update(callable $fn): mixed
{
    $out = m3d_json_update('comments', function ($d) use ($fn) {
        $d = is_array($d) ? $d : [];
        $all = ['next' => max(1, (int) ($d['next'] ?? 1))];
        foreach (array_keys(COMMENT_KINDS) as $k) $all[$k] = is_array($d[$k] ?? null) ? $d[$k] : [];
        [$all, $res] = $fn($all);
        foreach (array_keys(COMMENT_KINDS) as $k) {
            $all[$k] = array_filter($all[$k], fn($l) => is_array($l) && $l);
            $all[$k] = (object) array_map('array_values', $all[$k]);   // {} when empty, never []
        }
        return [$all, $res];
    });
    m3d_comments_all(true);
    return $out;
}

/** The comment with this id: [kind, key, index, comment] or null. */
function m3d_comment_find(int $id): ?array
{
    $all = m3d_comments_all();
    foreach (array_keys(COMMENT_KINDS) as $k) foreach ($all[$k] as $key => $list) foreach ((array) $list as $i => $c) {
        if (is_array($c) && (int) ($c['id'] ?? 0) === $id) return [$k, (string) $key, (int) $i, $c];
    }
    return null;
}

/** Changes one comment ($fn gets it and returns it, or null to delete it). False when it is gone or nothing could be saved. */
function m3d_comment_change(int $id, callable $fn): bool
{
    return (bool) m3d_comments_update(function (array $all) use ($id, $fn) {
        foreach (array_keys(COMMENT_KINDS) as $k) foreach ($all[$k] as $key => $list) foreach ((array) $list as $i => $c) {
            if (!is_array($c) || (int) ($c['id'] ?? 0) !== $id) continue;
            $new = $fn($c);
            if ($new === null) unset($all[$k][$key][$i]); else $all[$k][$key][$i] = $new;
            $all[$k][$key] = array_values($all[$k][$key]);
            return [$all, true];
        }
        return [$all, false];
    });
}

/** The page a comment is on: [title, address, item or post] ('' address when it is gone). */
function m3d_comment_page(string $kind, string $key): array
{
    if ($kind === 'posts') {
        $p = m3d_post($key);
        return $p ? [(string) $p['title'], m3d_post_url($p), $p] : ['Blog post ' . $key, '', null];
    }
    $it = m3d_item($kind, $key);
    return $it ? [$kind === 'models' ? m3d_model_name($it) : (string) $it['name'], m3d_item_url($kind, $key), $it] : [$key, '', null];
}

/** Whether this visitor sees this comment: pending ones only for their author and whoever checks comments ($staff: the creator or a
 *  moderator, m3d_mod_can('comments')); hidden ones (a moderator's Hide, `hid`) only for those. */
function m3d_comment_shown(array $c, ?array $p, bool $staff): bool
{
    if ($staff) return true;
    if (!empty($c['hid'])) return false;
    if (empty($c['pending'])) return true;
    return $p && empty($p['preview']) && (string) ($c['by'] ?? '') === (string) ($p['id'] ?? '');
}

/** ['ok' => bool, 'why' => text for the visitor]: may this visitor comment? (The page itself is checked by the caller.) */
function m3d_comment_can_post(?array $p): array
{
    $s = m3d_comment_settings();
    if (!$p) return ['ok' => false, 'why' => 'login'];
    if (!empty($p['preview'])) return ['ok' => false, 'why' => 'Not while previewing the site as a patron.'];
    if (m3d_is_admin($GLOBALS['M3D'])) return ['ok' => true, 'why' => ''];
    if (m3d_banned($p, 'comments')) return ['ok' => false, 'why' => 'banned'];   // Admin → Access: the page says so with ban_note()
    if ($s['who'] === 'paying' && empty($p['access'])) return ['ok' => false, 'why' => 'Only patrons on a paid tier can comment.'];
    return ['ok' => true, 'why' => ''];
}

/** A new comment by $p waits for the creator: "Check Before Showing" is on, unless "Skip for Paying Patrons" is too and $p can download
 *  (a paid tier, or an Access exception that gives the tier, like "Paying Patrons" in Who Can Comment). The creator's never wait. */
function m3d_comment_needs_check(array $p, array $s): bool
{
    if (!$s['moderate'] || m3d_is_admin($GLOBALS['M3D']) || m3d_is_mod($GLOBALS['M3D'])) return false;   // moderators' never wait either
    return !($s['paid_skip'] && !empty($p['access']));
}

/** Comments this person wrote in the last 24 hours (for the daily limit). $all: the file as m3d_comments_update() hands it over, so the
 *  check and the save happen under one lock (two sessions at once can't both pass); else the one read. */
function m3d_comments_sent_today(string $patronId, ?array $all = null): int
{
    $all ??= m3d_comments_all();
    $from = time() - 86400;
    $n = 0;
    foreach (array_keys(COMMENT_KINDS) as $k) foreach ((array) ($all[$k] ?? []) as $list) foreach ((array) $list as $c) {
        if (is_array($c) && (string) ($c['by'] ?? '') === $patronId && (int) ($c['at'] ?? 0) > $from) $n++;
    }
    return $n;
}

/** Comments waiting for the creator, as [kind, key, comment], newest first. */
function m3d_comments_pending(): array
{
    return array_values(array_filter(m3d_comments_flat(), fn($r) => !empty($r[2]['pending'])));
}

/** How many comments there are (site-wide, every page). */
function m3d_comments_count(): int
{
    return count(m3d_comments_flat());
}

/** Short Discord post about a new comment (Settings → Notifications → New Comments, the creator's channel). A failure is only logged. */
function m3d_comment_notify(string $kind, string $key, array $c): void
{
    [$title, $url] = m3d_comment_page($kind, $key);
    m3d_notify_post('comments', [['title' => mb_substr((!empty($c['re']) ? 'Reply on ' : 'Comment on ') . $title, 0, 250), 'url' => abs_url($url . '#cm-' . (int) $c['id']),
        'description' => mb_substr(m3d_notify_quote((string) ($c['name'] ?? ''), (string) $c['text'], 400), 0, 800),
        'color' => hexdec('e8b931'), 'footer' => ['text' => 'New comment' . (!empty($c['pending']) ? ' · waiting for you' : '')],
        'sub' => '💬 New Comment' . (!empty($c['pending']) ? ' · ⏳ Waiting for You' : '')]]);
}

/**
 * Comments shown to everyone per page ("kind:key" => n; waiting ones left out), for the cards: kept with m3d_data_summary(), so the
 * catalogs don't read every comment.
 */
function m3d_comment_counts(): array
{
    return m3d_comment_summary()['n'];
}

/** The comments file in short (m3d_data_summary()): n (see m3d_comment_counts()), recent = visitors' newest comments [[id, at, waiting]], for the
 *  creator's bell's number; replies = {like code of a comment's author: [[reply id, at, kind, key]…]} (published replies by someone else, the
 *  newest 20 each), for the visitors' bell (app/bell.php). */
function m3d_comment_summary(): array
{
    $s = m3d_data_summary('comments', 'm3d_comment_summary_make');
    if (!array_key_exists('wait', $s)) $s = m3d_data_summary('comments', 'm3d_comment_summary_make', true);   // saved before `replies` / `wait` existed
    return $s + ['n' => [], 'recent' => [], 'replies' => [], 'wait' => [], 'rx' => []];
}

function m3d_comment_summary_make(): array
{
    $n = $recent = $replies = $by = $wait = $rx = [];
    $flat = m3d_comments_flat();
    foreach ($flat as [, , $c]) $by[(int) ($c['id'] ?? 0)] = (string) ($c['by'] ?? '');
    $codeOf = [];   // Patreon id => its like code, worked out once per author
    foreach ($flat as [$k, $key, $c]) {
        $live = empty($c['pending']) && empty($c['hid']);
        if ($live) $n["$k:$key"] = ($n["$k:$key"] ?? 0) + 1;
        if (!empty($c['pending']) && empty($c['hid'])) $wait[] = "$k:$key";   // the Moderation number
        if (empty($c['admin']) && (count($recent) < 200 || !empty($c['pending']))) $recent[] = [(int) ($c['id'] ?? 0), (int) ($c['at'] ?? 0), !empty($c['pending']) ? 1 : 0];
        $to = (int) ($c['to'] ?? 0) ?: (int) ($c['re'] ?? 0);
        $author = $by[$to] ?? '';
        if ($to && $author !== '' && $live && $author !== (string) ($c['by'] ?? '')) {
            $replies[$codeOf[$author] ??= m3d_like_code($author)][] = [(int) ($c['id'] ?? 0), (int) ($c['at'] ?? 0), $k, (string) $key];
        }
        $me = (string) ($c['by'] ?? '');   // reactions others gave to this comment (still there): its author's bell
        if ($live && !empty($c['rxat']) && $me !== '' && empty($c['admin']) && m3d_rx_others((array) ($c['rx'] ?? []), $codeOf[$me] ??= m3d_like_code($me))) {
            $rx[$codeOf[$me]][] = [(int) ($c['id'] ?? 0), (int) $c['rxat'], $k, (string) $key];
        }
    }
    foreach ([&$replies, &$rx] as &$all) foreach ($all as &$list) { usort($list, fn($a, $b) => $a[1] <=> $b[1]); $list = array_slice($list, -20); }
    unset($all, $list);
    return ['n' => $n, 'recent' => $recent, 'replies' => $replies, 'wait' => $wait, 'rx' => $rx];
}

/** The comment count on a card (bubble + number), '' when there are none or comments are off for it. */
function comments_pill(string $kind, array $item, string $key): string
{
    if (!m3d_comments_open($kind, $item) || !($n = (int) (m3d_comment_counts()["$kind:$key"] ?? 0))) return '';
    return '<span class="cm-pill" title="' . e(m3d_plural($n, 'comment')) . '">' . bug_icon('comment') . $n . '</span>';
}

/** Admin lists' Comments column: every comment per page ("kind:key" => [shown, waiting or hidden]). */
function m3d_comment_admin_counts(): array
{
    $n = [];
    foreach (m3d_comments_flat() as [$k, $key, $c]) { $w = empty($c['pending']) && empty($c['hid']) ? 0 : 1; $n["$k:$key"][$w] = ($n["$k:$key"][$w] ?? 0) + 1; }
    return $n;
}

// ── Upkeep ───────────────────────────────────────────────────────────────────
/** Items or posts deleted: their comments go too. */
function m3d_comments_forget(string $kind, array $keys): void
{
    if (!$keys || !is_file(M3D_PRIVATE . '/data/comments.json')) return;
    m3d_comments_update(function (array $all) use ($kind, $keys) {
        foreach ($keys as $k) unset($all[$kind][(string) $k]);
        return [$all, null];
    });
}

/** Settings → Comments → Delete All Comments: every comment on the site. Returns how many went, or null when nothing could be saved. */
function m3d_comments_clear(): ?int
{
    return m3d_comments_update(function (array $all) {
        $n = 0;
        foreach (array_keys(COMMENT_KINDS) as $k) { foreach ($all[$k] as $list) $n += count((array) $list); $all[$k] = []; }
        return [$all, $n];
    });
}

// ── The block under a page ───────────────────────────────────────────────────
/**
 * The comments of a model, accessory or blog post page ($key: the slug, or the post's id; $noun: "model", "accessory", "post").
 * '' while comments are off for it, except for the creator, who gets one line saying so when the item turned them off.
 */
function comments_block(string $kind, string $key, array $item, string $noun): string
{
    $M3D = $GLOBALS['M3D'];
    $p = m3d_patron($M3D);
    $admin = m3d_is_admin($M3D);
    $creator = $admin && empty($p['preview']);
    if (!m3d_comments_on($kind)) return '';
    $all = m3d_comments($kind, $key);
    if (!empty($item['noComments'])) {
        if (!$creator) return '';
        return '<section class="comments" id="comments"><p class="cm-off muted small">' . bug_icon('comment') . 'Comments are off for this ' . e($noun)
            . ($all ? ' (' . e(m3d_plural(count($all), 'comment')) . ' kept, hidden)' : '') . '. Turn them on with “Allow Comments” in its editor.</p></section>';
    }
    $staff = m3d_mod_can('comments');   // the creator or a moderator: waiting and hidden ones too, with Approve / Hide / Ban in the ⋯ menu
    $list = array_values(array_filter($all, fn($c) => m3d_comment_shown($c, $p, $staff)));
    $can = m3d_comment_can_post($p);
    $here = m3d_safe_return(strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/');
    $csrf = $p ? '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" />' : '';
    $keys = $csrf . '<input type="hidden" name="return" value="' . e($here) . '" />';
    $edit = (int) m3d_get_text('cedit');
    $me = (string) ($p['id'] ?? '');

    // after a post to auth/comment.php: one floating message
    $msg = ['sent' => ['ok', '✓ Comment posted.'], 'pending' => ['ok', '✓ Comment sent for review.'],
        'deleted' => ['ok', '✓ Comment deleted.'], 'saved' => ['ok', '✓ Comment saved.'], 'approved' => ['ok', '✓ Comment published.'],
        'blocked' => ['ok', '✓ Blocked.'], 'unblocked' => ['ok', '✓ Unblocked.']][m3d_get_text('cm')] ?? null;
    if ($msg) site_flash($msg[1], $msg[0]);
    $err = COMMENT_ERRORS[m3d_get_text('cmerr')] ?? '';
    if ($err === 'limit') $err = 'You wrote ' . m3d_plural(m3d_comment_settings()['per_day'], 'comment') . ' in the last 24 hours: please wait a little before writing more.';
    if ($err !== '') site_flash('<b>Not sent:</b> ' . e($err), 'err');

    $S = m3d_comment_settings();
    $blocked = $S['blocked'];
    // the 😊 picker button and ":heart:" codes in the boxes (site.js [data-emoji]); reactions: the visitor's like code marks their own
    $emo = emoji_attrs($S);
    $rxCode = $S['reactions'] && $p && empty($p['preview']) ? m3d_like_code((string) $p['id']) : '';
    $shownN = count(array_filter($list, fn($c) => empty($c['hid'])));
    $h = '<section class="comments" id="comments"><h2 class="block-title">Comments' . ($shownN ? ' <span class="count">' . $shownN . '</span>' : '') . '</h2>';
    // threads: each comment, then its replies (two levels: a reply to a reply joins the same thread, "↪ Name" says whom it answers);
    // a reply whose comment this visitor doesn't see (deleted, or waiting for approval) shows as a comment of its own
    $byId = [];
    foreach ($list as $c) $byId[(int) ($c['id'] ?? 0)] = $c;
    $tops = $replies = [];
    foreach ($list as $c) {
        $re = (int) ($c['re'] ?? 0);
        if ($re && isset($byId[$re])) $replies[$re][] = $c; else $tops[] = $c;
    }
    $card = function (array $c, int $root) use ($keys, $creator, $edit, $here, $me, $p, $blocked, $byId, $can, $kind, $key, $staff, $S, $emo, $rxCode): string {
        $id = (int) ($c['id'] ?? 0);
        $cr = !empty($c['admin']);
        $by = (string) ($c['by'] ?? '');
        $name = (string) ($c['name'] ?? '');
        $mine = $me !== '' && $by === $me && empty($p['preview']);
        $hid = m3d_mod_hid($c);
        $menu = [];
        if ($creator) {
            if (!empty($c['pending']) && !$hid) $menu[] = comment_action_btn($keys, $id, 'approve', 'Publish');
            $menu[] = '<a class="pm-item" href="' . e($here) . '?cedit=' . $id . '#cm-' . $id . '">Edit</a>';
            if (!$cr) array_push($menu, ...array_slice(mod_menu_items('comment', (string) $id, false, (bool) $hid, '', '', $here), 0, 1));   // Hide… / Show Again
            if (!$cr && $by !== '') $menu[] = comment_action_btn($keys, $id, in_array($by, $blocked, true) ? 'unblock' : 'block', in_array($by, $blocked, true) ? 'Unblock' : 'Block',
                in_array($by, $blocked, true) ? '' : 'Block ' . ($name !== '' ? $name : 'this person') . ' from commenting?');
        }
        elseif ($staff && !$cr) $menu = mod_menu_items('comment', (string) $id, !empty($c['pending']), (bool) $hid, $by, $name, $here);   // a moderator: never Edit or Delete
        if ($creator || ($mine && !$hid)) $menu[] = comment_action_btn($keys, $id, 'delete', $mine && !$creator ? 'Delete My Comment' : 'Delete', 'Delete this comment?', true);
        $to = (int) ($c['to'] ?? 0);
        $toName = $to && $to !== $root && isset($byId[$to]) ? (string) (($byId[$to]['name'] ?? '') ?: 'Someone') : '';
        $h = '<article class="post cm' . ($root ? ' cm-sub' : '') . ($cr ? ' by-creator' : (m3d_posted_by_mod($c) ? ' by-mod' : '')) . (!empty($c['pending']) && !$hid ? ' is-pending' : '') . ($hid ? ' is-hid' : '') . '" id="cm-' . $id . '">'
            . '<div class="post-head">' . bug_avatar($name, $cr, $by) . '<b>' . e($name !== '' ? $name : 'Someone') . '</b>' . ($cr ? '<span class="creator-tag">Creator</span>' : mod_tag($c))
            . ($toName !== '' ? '<a class="cm-to" href="#cm-' . $to . '">↪ ' . e($toName) . '</a>' : '')
            . ' · <span>' . e(bug_ago((int) ($c['at'] ?? 0))) . '</span>' . (!empty($c['edited']) ? ' <span class="muted small">· edited</span>' : '')
            . ($hid ? '<span class="mod-hid">Hidden</span>' : (!empty($c['pending']) ? '<span class="cm-wait">Waiting for Approval</span>' : ''))
            . ($creator && $edit === $id ? '' : bug_post_menu($menu)) . '</div>';
        if ($creator && $edit === $id) {
            return $h . '<form class="post-body bug-edit-form" method="post" action="/auth/comment.php" data-dirty>' . $keys . '<input type="hidden" name="do" value="edit" /><input type="hidden" name="id" value="' . $id . '" />'
                . '<label class="sr-only" for="cm-edit-' . $id . '">Comment</label>'
                . '<textarea class="input" id="cm-edit-' . $id . '" name="text" rows="4" maxlength="' . COMMENT_MAX . '" required' . $emo . '>' . e((string) ($c['text'] ?? '')) . '</textarea>'
                . '<div class="edit-acts"><a class="btn btn-ghost btn-sm" href="' . e($here) . '#cm-' . $id . '">Cancel</a><button class="btn btn-primary btn-sm">Save</button></div></form></article>';
        }
        $text = (string) ($c['text'] ?? '');
        $h .= '<div class="post-body md' . ($S['emoji'] && comment_emoji_only($text) ? ' cm-big' : '') . '">' . md_html($text, ['picLinks' => true]) . '</div>';
        if ($hid) return $h . '<p class="mod-why">' . mod_hid_line($hid) . ' · only moderators and the creator see it</p></article>';
        $rx = $S['reactions'] && empty($c['pending']) ? reactions_html((array) ($c['rx'] ?? []), $keys . '<input type="hidden" name="id" value="' . $id . '" />', '/auth/comment.php', $can['ok'] ? $rxCode : '') : '';
        $h .= '<div class="cm-foot">' . $rx;
        if ($can['ok'] && empty($c['pending'])) {   // Reply: a small form under the comment (no script needed: a <details>)
            $dk = $kind . ':' . $key . ':' . $id;
            $draft = (string) ($_SESSION['cm_draft'][$dk] ?? '');
            if ($draft !== '') unset($_SESSION['cm_draft'][$dk]);
            $h .= '<details class="cm-reply"' . ($draft !== '' ? ' open' : '') . '><summary>' . bug_icon('comment') . 'Reply</summary>'
                . '<form method="post" action="/auth/comment.php">' . $keys . '<input type="hidden" name="do" value="add" />'
                . '<input type="hidden" name="kind" value="' . e($kind) . '" /><input type="hidden" name="key" value="' . e($key) . '" /><input type="hidden" name="re" value="' . $id . '" />'
                . '<label class="sr-only" for="cm-re-' . $id . '">Reply to ' . e($name !== '' ? $name : 'this comment') . '</label>'
                . '<textarea class="input" id="cm-re-' . $id . '" name="text" maxlength="' . COMMENT_MAX . '" placeholder="Reply to ' . e($name !== '' ? $name : 'this comment') . '…" required' . $emo . '>' . e($draft) . '</textarea>'
                . '<div class="edit-acts"><button class="btn btn-primary btn-sm">Reply</button></div></form></details>';
        }
        return $h . '</div></article>';
    };
    if ($tops) $h .= '<div class="cm-list">';
    foreach ($tops as $c) {
        $id = (int) ($c['id'] ?? 0);
        $h .= '<div class="cm-thread">' . $card($c, 0);
        if (!empty($replies[$id])) {
            $h .= '<div class="cm-replies">';
            foreach ($replies[$id] as $r) $h .= $card($r, $id);
            $h .= '</div>';
        }
        $h .= '</div>';
    }
    if ($tops) $h .= '</div>';

    if ($can['ok']) {
        $draft = '';
        if (isset($_SESSION['cm_draft'][$kind . ':' . $key])) {   // what was typed before a refusal (auth/comment.php)
            $draft = (string) $_SESSION['cm_draft'][$kind . ':' . $key];
            unset($_SESSION['cm_draft'][$kind . ':' . $key]);
        }
        $h .= '<form class="reply" method="post" action="/auth/comment.php">' . $keys . '<input type="hidden" name="do" value="add" />'
            . '<input type="hidden" name="kind" value="' . e($kind) . '" /><input type="hidden" name="key" value="' . e($key) . '" />'
            . '<label class="sr-only" for="cm-text">Comment</label>'
            . '<textarea class="input" id="cm-text" name="text" maxlength="' . COMMENT_MAX . '" placeholder="Write a comment…" required' . $emo . '>' . e($draft) . '</textarea>'
            . '<div class="reply-foot"><span class="muted">Commenting as <b>' . e((string) ($p['name'] ?? '')) . '</b>' . ($admin ? ' <span class="creator-tag">Creator</span>' : (m3d_is_mod($M3D) ? ' <span class="mod-tag">Moderator</span>' : ''))
            . ' · shown with your comment</span><button class="btn btn-primary btn-sm">Comment</button></div></form>';
    } elseif ($can['why'] === 'login') {
        $h .= '<div class="reply reply-login"><span class="muted">Log in with Patreon to comment.</span><a class="btn btn-patreon btn-sm" rel="nofollow" href="/auth/login.php?return='
            . e(rawurlencode($here . '#comments')) . '">Log In with Patreon</a></div>';
    } else {
        $h .= $can['why'] === 'banned' ? ban_note('comments') : '<p class="reply-off muted small">' . e($can['why']) . '</p>';
    }
    return $h . '</section>';
}

/** A comment that is only 1–3 emoji (spaces between allowed): shown big, like Discord's. */
function comment_emoji_only(string $t): bool
{
    $t = preg_replace('/\s+/u', '', $t) ?? '';
    if ($t === '' || !preg_match_all('/\X/u', $t, $m) || count($m[0]) > 3) return false;
    foreach ($m[0] as $g) if (!preg_match('/\p{Extended_Pictographic}|[\x{1F1E6}-\x{1F1FF}]|\x{20E3}/u', $g)) return false;
    return true;
}

/**
 * Reactions (comments and bug reports, with their own switches): a chip per reaction someone gave (emoji + count, lit when it is the
 * visitor's), and for a visitor who may react ($code = their like code, '' = can't) a ☺+ button opening COMMENT_REACTIONS. One form posting
 * do=react + $hidden (which post) to $action; site.js sends it without leaving the page ([data-rx]), and the ☺+ is a <details>, so it works
 * without a script too. $rx = the post's `rx` {emoji: [like codes]}.
 */
function reactions_html(array $rx, string $hidden, string $action, string $code): string
{
    $chips = '';
    foreach (COMMENT_REACTIONS as $r) {
        $n = count((array) ($rx[$r] ?? []));
        if (!$n) continue;
        $on = $code !== '' && in_array($code, (array) $rx[$r], true);
        $chips .= $code !== ''
            ? '<button class="rx-chip' . ($on ? ' is-on' : '') . '" name="r" value="' . e($r) . '" aria-pressed="' . ($on ? 'true' : 'false') . '"><span class="rx-e">' . e($r) . '</span><span>' . $n . '</span></button>'
            : '<span class="rx-chip"><span class="rx-e">' . e($r) . '</span>' . $n . '</span>';
    }
    if ($code === '') return $chips !== '' ? '<div class="rx">' . $chips . '</div>' : '';
    $pick = '';
    foreach (COMMENT_REACTIONS as $r) $pick .= '<button name="r" value="' . e($r) . '">' . e($r) . '</button>';
    return '<form class="rx" method="post" action="' . e($action) . '" data-rx>' . $hidden . '<input type="hidden" name="do" value="react" />'
        . $chips . '<details class="rx-add"><summary aria-label="Add a reaction" title="Add a reaction">' . bug_icon('react') . '</summary><div class="rx-pop">' . $pick . '</div></details></form>';
}

/** A post with reaction $r of $code turned on or off: [the post, the reactions as auth/comment.php and bugs.php answer them {emoji: [count, mine]}].
 *  A reaction given by someone else stamps `rxat` (its time): the author's bell shows the post again (app/bell.php, event "reactions"). */
function m3d_rx_toggle(array $post, string $r, string $code): array
{
    $rx = (array) ($post['rx'] ?? []);
    $v = (array) ($rx[$r] ?? []);
    $on = !in_array($code, $v, true);
    $v = $on ? [...$v, $code] : array_values(array_diff($v, [$code]));
    if ($on && m3d_like_code((string) ($post['by'] ?? '')) !== $code) $post['rxat'] = time();
    if ($v) $rx[$r] = $v; else unset($rx[$r]);
    if ($rx) $post['rx'] = $rx; else unset($post['rx']);
    $out = [];
    foreach (COMMENT_REACTIONS as $e) if (!empty($rx[$e])) $out[$e] = [count($rx[$e]), in_array($code, $rx[$e], true)];
    return [$post, $out];
}

/** The reactions on a post given by others than its author ($code = the author's like code): {emoji: count}, in COMMENT_REACTIONS' order. */
function m3d_rx_others(array $rx, string $code): array
{
    $out = [];
    foreach (COMMENT_REACTIONS as $r) if ($n = count(array_diff((array) ($rx[$r] ?? []), [$code]))) $out[$r] = $n;
    return $out;
}

/** Reactions are on for comments or bug reports (privacy.php). */
function m3d_reactions_on(): bool
{
    return (m3d_comments_on() && m3d_comment_settings()['reactions']) || (m3d_bugs_on() && m3d_bug_settings()['reactions']);
}

/** Some comment, bug report or bug comment holds reactions (privacy.php, after the switches are turned off). */
function m3d_reactions_stored(): bool
{
    foreach (m3d_comments_flat() as [, , $c]) if (!empty($c['rx'])) return true;
    foreach (m3d_bugs() as $b) {
        if (!empty($b['rx'])) return true;
        foreach ((array) ($b['log'] ?? []) as $x) if (!empty($x['rx'])) return true;
    }
    return false;
}

/** The emoji picker or codes are on somewhere: site.js may keep localStorage m3d_emoji (privacy.php). */
function m3d_emoji_storage_on(): bool
{
    $c = m3d_comment_settings();
    $b = m3d_bug_settings();
    return (m3d_comments_on() && ($c['emoji'] || $c['codes'])) || (m3d_bugs_on() && ($b['emoji'] || $b['codes']));
}

/** The emoji switches of comments or bug reports ($s: their settings) as the attributes of a textarea (site.js [data-emoji]); '' = none. */
function emoji_attrs(array $s): string
{
    $m = trim((!empty($s['emoji']) ? 'pick ' : '') . (!empty($s['codes']) ? 'codes' : ''));
    return $m !== '' ? ' data-emoji="' . $m . '" data-emoji-src="' . e(asset('vendor/emoji.json')) . '"' : '';
}

/** One button of a comment's ⋯ menu: a small form posting do=$do to auth/comment.php ($ask: a confirm question). */
function comment_action_btn(string $keys, int $id, string $do, string $label, string $ask = '', bool $red = false): string
{
    return '<form method="post" action="/auth/comment.php">' . $keys . '<input type="hidden" name="id" value="' . $id . '" />'
        . '<button class="pm-item' . ($red ? ' is-red' : '') . '" name="do" value="' . e($do) . '"' . ($ask !== '' ? ' onclick="return confirm(' . e(json_encode($ask, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . e($label) . '</button></form>';
}
