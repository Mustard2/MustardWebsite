<?php
// Ideas boards on model and accessory pages ("Ideas for Tifa", Settings → Features → Ideas, off by default): patrons suggest outfits,
// features, improvements and fixes for THAT model or accessory (one board per item, so nobody reads it as a place to ask for new
// characters), vote on them, and the creator gives each one a status. Posted to auth/idea.php.
//
// data/ideas.json: {next, models: {slug: [i]}, outfits: {slug: [i]}}, oldest first, written only through m3d_ideas_update()
// (m3d_json_update: locked, no copy per save; restored with the counters). An idea i:
//   id (int, never reused), by (Patreon id), name (Patreon name then), at (unix), type (IDEA_TYPES key), title, text (optional details),
//   status (IDEA_STATUSES key; absent = new), ver (the version it was done in, as typed, with status done), admin (the creator wrote it),
//   pending (waits for a check while "Check Before Showing" is on: only its author, the moderators and the creator see it), mod (a moderator
//   wrote it), hid (hidden by a moderator or the creator, app/moderation.php: kept, seen only by them),
//   votes [like codes, m3d_like_code(): no raw ids; the author's own vote is there from the start]
// settings.json → ideas: {on, models, outfits (where), who: any|paying (suggest and vote; never saved = paying), moderate (never saved = on), per_day}.
// Bans: Admin → Access → Ideas (BAN_KINDS 'ideas'). The creator's bell (event 'ideas') lists new and waiting ones; Discord too.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const IDEA_KINDS = ['models' => 'Models', 'outfits' => 'Accessories', 'scenes' => 'Scenes'];
const IDEA_TYPES = ['outfit' => 'Outfit', 'feature' => 'Feature', 'improvement' => 'Improvement', 'fix' => 'Fix'];
const IDEA_STATUSES = ['new' => 'New', 'planned' => 'Planned', 'done' => 'Done', 'no' => 'Not Planned'];
const IDEA_TITLE_MAX = 60;
const IDEA_TEXT_MAX = 500;
const IDEA_VER_MAX = 20;
const IDEA_SHOWN = 5;        // rows shown before "Show All N Ideas"
// why auth/idea.php refused (?ideaerr=): the page says it ('limit' is worked out with the setting)
const IDEA_ERRORS = [
    'session' => 'your session expired: please send it again.', 'login' => 'please log in with Patreon first.', 'short' => 'give your idea a title.',
    'off' => 'ideas are off here.', 'gone' => 'that idea is gone.', 'save' => 'it could not be saved. Please try again.', 'limit' => 'limit',
    'paying' => 'only patrons on a paid tier can suggest and vote.', 'preview' => 'not while previewing the site as a patron.', 'closed' => 'votes are closed on that idea.',
];

/** The idea types offered for this kind of item (accessories and scenes: "Variant" instead of "Outfit"). */
function m3d_idea_types(string $kind): array
{
    return $kind !== 'models' ? ['outfit' => 'Variant'] + IDEA_TYPES : IDEA_TYPES;
}

function m3d_idea_settings(): array
{
    $s = m3d_settings()['ideas'] ?? [];
    $s = is_array($s) ? $s : [];
    return [
        'on'       => !empty($s['on']),
        'models'   => (bool) ($s['models'] ?? true),
        'outfits'  => (bool) ($s['outfits'] ?? true),
        'scenes'   => (bool) ($s['scenes'] ?? true),
        'who'      => ($s['who'] ?? 'paying') === 'any' ? 'any' : 'paying',
        'moderate' => (bool) ($s['moderate'] ?? true),
        'per_day'  => max(1, min(50, (int) ($s['per_day'] ?? 5))),
        'blocked'  => m3d_ban_ids('ideas'),
    ];
}

function m3d_idea_settings_save(array $changes): bool
{
    return m3d_save_setting_keys('ideas', $changes);
}

/** Ideas are on for this kind of item (models | outfits), site-wide. */
function m3d_ideas_on(string $kind = ''): bool
{
    $s = m3d_idea_settings();
    return $s['on'] && ($kind === '' || !empty($s[$kind]));
}

/** The whole file ({next, models, outfits}); $fresh: read it again. */
function m3d_ideas_all(bool $fresh = false): array
{
    static $all = null;
    if ($all === null || $fresh) {
        $d = m3d_read_json('ideas');
        $all = m3d_ideas_shape(is_array($d) ? $d : []);
    }
    return $all;
}

function m3d_ideas_shape(array $d): array
{
    $all = ['next' => max(1, (int) ($d['next'] ?? 1))];
    foreach (array_keys(IDEA_KINDS) as $k) $all[$k] = is_array($d[$k] ?? null) ? $d[$k] : [];
    return $all;
}

/** The ideas of one item, oldest first (pending ones too, see m3d_idea_shown()). */
function m3d_ideas(string $kind, string $slug): array
{
    return array_values(array_filter((array) (m3d_ideas_all()[$kind][$slug] ?? []), 'is_array'));
}

/** Every idea as [kind, slug, idea], newest first. */
function m3d_ideas_flat(): array
{
    $out = [];
    $all = m3d_ideas_all();
    foreach (array_keys(IDEA_KINDS) as $k) foreach ($all[$k] as $slug => $list) foreach ((array) $list as $i) if (is_array($i)) $out[] = [$k, (string) $slug, $i];
    usort($out, fn($a, $b) => (int) ($b[2]['at'] ?? 0) <=> (int) ($a[2]['at'] ?? 0));
    return $out;
}

/** Locked read-modify-write: $fn(array $all) returns [$all, $result]. Returns $result, or null when nothing could be saved. */
function m3d_ideas_update(callable $fn): mixed
{
    $out = m3d_json_update('ideas', function ($d) use ($fn) {
        [$all, $res] = $fn(m3d_ideas_shape(is_array($d) ? $d : []));
        foreach (array_keys(IDEA_KINDS) as $k) {
            $all[$k] = array_filter($all[$k], fn($l) => is_array($l) && $l);
            $all[$k] = (object) array_map('array_values', $all[$k]);   // {} when empty, never []
        }
        return [$all, $res];
    });
    m3d_ideas_all(true);
    return $out;
}

/** The idea with this id: [kind, slug, idea] or null. */
function m3d_idea_find(int $id): ?array
{
    foreach (m3d_ideas_flat() as $r) if ((int) ($r[2]['id'] ?? 0) === $id) return $r;
    return null;
}

/** Changes one idea ($fn gets it and returns it, or null to delete it). False when it is gone or nothing could be saved. */
function m3d_idea_change(int $id, callable $fn): bool
{
    return (bool) m3d_ideas_update(function (array $all) use ($id, $fn) {
        foreach (array_keys(IDEA_KINDS) as $k) foreach ($all[$k] as $slug => $list) foreach ((array) $list as $n => $i) {
            if (!is_array($i) || (int) ($i['id'] ?? 0) !== $id) continue;
            $new = $fn($i);
            if ($new === null) unset($all[$k][$slug][$n]); else $all[$k][$slug][$n] = $new;
            $all[$k][$slug] = array_values($all[$k][$slug]);
            return [$all, true];
        }
        return [$all, false];
    });
}

/** The item an idea is about: [name, address] ('' address when it is gone). */
function m3d_idea_page(string $kind, string $slug): array
{
    $it = m3d_item($kind, $slug);
    return $it ? [$kind === 'models' ? m3d_model_name($it) : (string) $it['name'], m3d_item_url($kind, $slug)] : [$slug, ''];
}

function m3d_idea_status(array $i): string
{
    return isset(IDEA_STATUSES[$i['status'] ?? '']) ? $i['status'] : 'new';
}

/** Done or Not Planned: votes are closed. */
function m3d_idea_closed(array $i): bool
{
    return in_array(m3d_idea_status($i), ['done', 'no'], true);
}

/** Whether this visitor sees this idea: pending ones only for their author and whoever checks ideas ($staff: the creator or a moderator,
 *  m3d_mod_can('ideas')); hidden ones (`hid`, app/moderation.php) only for those. */
function m3d_idea_shown(array $i, ?array $p, bool $staff): bool
{
    if ($staff) return true;
    if (!empty($i['hid'])) return false;
    if (empty($i['pending'])) return true;
    return $p && empty($p['preview']) && (string) ($i['by'] ?? '') === (string) ($p['id'] ?? '');
}

/** ['ok' => bool, 'why' => login | preview | banned | paying | '']: may this visitor suggest and vote? (The page is checked by the caller.) */
function m3d_idea_can_post(?array $p): array
{
    if (!$p) return ['ok' => false, 'why' => 'login'];
    if (!empty($p['preview'])) return ['ok' => false, 'why' => 'preview'];
    if (m3d_is_admin($GLOBALS['M3D'])) return ['ok' => true, 'why' => ''];
    if (m3d_banned($p, 'ideas')) return ['ok' => false, 'why' => 'banned'];
    if (m3d_idea_settings()['who'] === 'paying' && empty($p['access'])) return ['ok' => false, 'why' => 'paying'];
    return ['ok' => true, 'why' => ''];
}

/** Ideas this person sent in the last 24 hours. $all: the file as m3d_ideas_update() hands it over, so the check and the save share a lock. */
function m3d_ideas_sent_today(string $patronId, ?array $all = null): int
{
    $all ??= m3d_ideas_all();
    $from = time() - 86400;
    $n = 0;
    foreach (array_keys(IDEA_KINDS) as $k) foreach ((array) ($all[$k] ?? []) as $list) foreach ((array) $list as $i) {
        if (is_array($i) && (string) ($i['by'] ?? '') === $patronId && (int) ($i['at'] ?? 0) > $from) $n++;
    }
    return $n;
}

/** Ideas waiting for the creator, as [kind, slug, idea], newest first. */
function m3d_ideas_pending(): array
{
    return array_values(array_filter(m3d_ideas_flat(), fn($r) => !empty($r[2]['pending'])));
}

/** Short Discord post about a new idea (Settings → Notifications → New Ideas). A failure is only logged. */
function m3d_idea_notify(string $kind, string $slug, array $i): void
{
    [$title, $url] = m3d_idea_page($kind, $slug);
    $text = trim((string) ($i['text'] ?? ''));
    m3d_notify_post('ideas', [['title' => mb_substr('Idea for ' . $title . ': ' . (string) $i['title'], 0, 250), 'url' => abs_url($url . '#idea-' . (int) $i['id']),
        'description' => mb_substr(m3d_notify_plain((string) ($i['name'] ?? ''), 80) . ' · ' . (m3d_idea_types($kind)[$i['type'] ?? ''] ?? 'Idea') . ($text !== '' ? "\n" . m3d_notify_quote('', $text, 400) : ''), 0, 800),
        'color' => hexdec('e8b931'), 'footer' => ['text' => 'New idea' . (!empty($i['pending']) ? ' · waiting for you' : '')],
        'sub' => '💡 New Idea' . (!empty($i['pending']) ? ' · ⏳ Waiting for You' : '')]]);
}

/** The file in short (m3d_data_summary()): recent = visitors' newest ideas [[id, at, waiting]] for the creator's bell's number. */
function m3d_idea_summary(): array
{
    $make = function () {
        $recent = $wait = [];
        foreach (m3d_ideas_flat() as [$k, $slug, $i]) {
            if (empty($i['admin']) && (count($recent) < 200 || !empty($i['pending']))) $recent[] = [(int) ($i['id'] ?? 0), (int) ($i['at'] ?? 0), !empty($i['pending']) ? 1 : 0];
            if (!empty($i['pending']) && empty($i['hid'])) $wait[] = "$k:$slug";   // the Moderation number
        }
        return ['recent' => $recent, 'wait' => $wait];
    };
    $s = m3d_data_summary('ideas', $make);
    if (!array_key_exists('wait', $s)) $s = m3d_data_summary('ideas', $make, true);   // saved before `wait` existed
    return $s + ['recent' => [], 'wait' => []];
}

/** Whether anything is stored (privacy.php keeps its section while ideas are kept, even with the feature off). */
function m3d_ideas_stored(): bool
{
    return (bool) m3d_ideas_flat();
}

// ── Upkeep ───────────────────────────────────────────────────────────────────
/** Items deleted: their ideas go too. */
function m3d_ideas_forget(string $kind, array $slugs): void
{
    if (!$slugs || !is_file(M3D_PRIVATE . '/data/ideas.json')) return;
    m3d_ideas_update(function (array $all) use ($kind, $slugs) {
        foreach ($slugs as $s) unset($all[$kind][(string) $s]);
        return [$all, null];
    });
}

/** Settings → Ideas → Delete All Ideas. Returns how many went, or null when nothing could be saved. */
function m3d_ideas_clear(): ?int
{
    return m3d_ideas_update(function (array $all) {
        $n = 0;
        foreach (array_keys(IDEA_KINDS) as $k) { foreach ($all[$k] as $list) $n += count((array) $list); $all[$k] = []; }
        return [$all, $n];
    });
}

/** Personal Data → delete: the person's ideas go, and their votes on other ideas. Returns [ideas, votes] removed, or null when not saved. */
function m3d_ideas_forget_person(string $id): ?array
{
    if (!is_file(M3D_PRIVATE . '/data/ideas.json')) return [0, 0];
    $code = m3d_like_code($id);
    return m3d_ideas_update(function (array $all) use ($id, $code) {
        $n = $v = 0;
        foreach (array_keys(IDEA_KINDS) as $k) foreach ($all[$k] as $slug => $list) {
            $keep = [];
            foreach ((array) $list as $i) {
                if (!is_array($i)) continue;
                if ((string) ($i['by'] ?? '') === $id && empty($i['admin'])) { $n++; continue; }
                $votes = array_values(array_diff((array) ($i['votes'] ?? []), [$code]));
                if (count($votes) !== count((array) ($i['votes'] ?? []))) { $v++; $i['votes'] = $votes; }
                $keep[] = $i;
            }
            $all[$k][$slug] = $keep;
        }
        return [$all, [$n, $v]];
    });
}

// ── The board on an item's page ──────────────────────────────────────────────
const IDEA_UP = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 15l6-6 6 6"/></svg>';

/** The status pill: "Planned", "Done in V4" (a bare number reads "V4"). */
function idea_status_pill(array $i): string
{
    return '<span class="idea-st st-' . m3d_idea_status($i) . '">' . e(idea_status_label($i)) . '</span>';
}

/** "Planned", "Done in V4"… */
function idea_status_label(array $i): string
{
    $st = m3d_idea_status($i);
    $ver = trim((string) ($i['ver'] ?? ''));
    return $st === 'done' && $ver !== '' ? 'Done in ' . (preg_match('/^\d/', $ver) ? 'V' . $ver : $ver) : IDEA_STATUSES[$st];
}

/**
 * "Ideas for <Name>" on a model or accessory page (#ideas), above the comments: the ideas this visitor sees (most votes first; done and
 * not planned ones after the open ones; their own waiting ones on top), the vote buttons, "+ Suggest an Idea" with its window, and for the
 * creator a ⋯ menu per idea (Publish, Status, Block, Delete). '' while ideas are off for this kind of item.
 */
function ideas_block(string $kind, array $item): string
{
    if (!m3d_ideas_on($kind)) return '';
    $M3D = $GLOBALS['M3D'];
    $p = m3d_patron($M3D);
    $creator = m3d_is_admin($M3D) && empty($p['preview']);
    $staff = m3d_mod_can('ideas');   // the creator or a moderator: waiting and hidden ones too
    $slug = (string) $item['slug'];
    $name = $kind === 'models' ? m3d_model_name($item) : (string) $item['name'];
    $can = m3d_idea_can_post($p);
    $me = $p && empty($p['preview']) ? (string) ($p['id'] ?? '') : '';
    $code = $me !== '' ? m3d_like_code($me) : '';
    $here = m3d_item_url($kind, $slug);
    $csrf = $p ? '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" />' : '';
    $S = m3d_idea_settings();

    $msg = ['sent' => ['ok', '✓ Idea added.'], 'pending' => ['ok', '✓ Idea sent for review.'],
        'deleted' => ['ok', '✓ Idea deleted.'], 'rejected' => ['ok', '✓ Idea rejected.'], 'saved' => ['ok', '✓ Status saved.'], 'approved' => ['ok', '✓ Idea published.'],
        'blocked' => ['ok', '✓ Blocked.'], 'unblocked' => ['ok', '✓ Unblocked.']][m3d_get_text('idea')] ?? null;
    if ($msg) site_flash($msg[1], $msg[0]);
    $err = IDEA_ERRORS[m3d_get_text('ideaerr')] ?? '';
    if ($err === 'limit') $err = 'you sent ' . m3d_plural($S['per_day'], 'idea') . ' in the last 24 hours: please wait a little before sending more.';
    if ($err !== '') site_flash('<b>Not sent:</b> ' . e($err), 'err');

    $list = array_values(array_filter(m3d_ideas($kind, $slug), fn($i) => m3d_idea_shown($i, $p, $staff)));
    // order: waiting ones first (only their author and whoever checks ideas see them), then open ones by votes, then done, then not planned,
    // then hidden ones (only those who check ideas)
    $rank = fn($i) => !empty($i['hid']) ? 4 : (!empty($i['pending']) ? 0 : (['done' => 2, 'no' => 3][m3d_idea_status($i)] ?? 1));
    usort($list, fn($a, $b) => [$rank($a), -count((array) ($a['votes'] ?? [])), -(int) ($a['at'] ?? 0)] <=> [$rank($b), -count((array) ($b['votes'] ?? [])), -(int) ($b['at'] ?? 0)]);
    $count = count(array_filter($list, fn($i) => empty($i['pending']) && empty($i['hid'])));
    $nSt = ['planned' => 0, 'done' => 0];
    foreach ($list as $i) if (empty($i['pending']) && empty($i['hid']) && isset($nSt[m3d_idea_status($i)])) $nSt[m3d_idea_status($i)]++;

    $btn = match ($can['why']) {
        '' => '<button class="btn btn-primary btn-sm" type="button" data-idea-open>+ Suggest an Idea</button>',
        'login' => '<a class="btn btn-ghost btn-sm" rel="nofollow" href="/auth/login.php?return=' . e(rawurlencode($here . '#ideas')) . '">Log In to Suggest</a>',
        'banned' => '<a class="btn btn-ghost btn-sm" href="' . e(m3d_ban_url('ideas')) . '">Why Can’t I Suggest?</a>',
        default => '',
    };
    $h = '<section class="section ideas" id="ideas" data-ideas><div class="ideas-head"><div><h2 class="h2">Ideas for ' . e($name) . ($count ? ' <span class="count">' . $count . '</span>' : '') . '</h2>'
        . '<p class="ideas-sub">' . e(['outfits' => 'Variants, features and fixes for this accessory.', 'scenes' => 'Variants, features and fixes for this scene.'][$kind] ?? 'Outfits, features and fixes for this model.') . '</p></div>' . $btn . '</div>';
    if (count($list) > 1) {
        $h .= '<div class="ideas-chips" role="group" aria-label="Show"><button type="button" class="chip" aria-pressed="true" data-idea-sort="votes">Most Votes</button>'
            . '<button type="button" class="chip" aria-pressed="false" data-idea-sort="new">Newest</button>';
        foreach ($nSt as $st => $n) if ($n) $h .= '<button type="button" class="chip" aria-pressed="false" data-idea-only="' . $st . '">' . IDEA_STATUSES[$st] . ' <span>' . $n . '</span></button>';
        $h .= '</div>';
    }
    if ($list) {
        $h .= '<div class="ideas-list">';
        foreach ($list as $n => $i) $h .= idea_row($kind, $i, $n, $can, $code, $creator, $me, $csrf, $here);
        $h .= '</div>';
        if (count($list) > IDEA_SHOWN) $h .= '<button type="button" class="ideas-more" data-idea-more hidden>Show All ' . count($list) . ' Ideas</button>';
    } else {
        $h .= '<p class="ideas-empty">No ideas yet.' . ($can['ok'] ? ' Be the first to suggest one.' : '') . '</p>';
    }
    if ($can['why'] === 'paying') $h .= '<p class="ideas-note">Patrons on any paid tier can suggest ideas and vote.</p>';
    elseif ($can['why'] === 'banned') $h .= ban_note('ideas', 'ideas-note');
    if ($can['ok']) $h .= idea_dialog($kind, $item, $name, $csrf, $S['moderate'] && !m3d_is_admin($M3D) && !m3d_is_mod($M3D));   // the creator's and moderators' ideas never wait
    return $h . '</section>';
}

/** One idea's row: vote button, title + details, who and when, status pill, the ⋯ menu. */
function idea_row(string $kind, array $i, int $n, array $can, string $code, bool $creator, string $me, string $csrf, string $here): string
{
    $id = (int) ($i['id'] ?? 0);
    $votes = (array) ($i['votes'] ?? []);
    $on = $code !== '' && in_array($code, $votes, true);
    $st = m3d_idea_status($i);
    $hid = m3d_mod_hid($i);
    $wait = !empty($i['pending']) || $hid;
    $by = (string) ($i['by'] ?? '');
    $who = trim((string) ($i['name'] ?? '')) ?: 'Someone';
    $keys = $csrf . '<input type="hidden" name="id" value="' . $id . '" />';
    $num = '<span data-idea-n>' . count($votes) . '</span>';
    $label = m3d_plural(count($votes), 'vote');
    if ($can['ok'] && !$wait && !m3d_idea_closed($i)) {
        $vote = '<form method="post" action="/auth/idea.php" data-idea-vote>' . $keys . '<input type="hidden" name="do" value="vote" /><button class="idea-vote" aria-pressed="' . ($on ? 'true' : 'false') . '" title="' . ($on ? 'Remove your vote' : 'Vote for this idea') . '" aria-label="' . e($label . ($on ? ', voted' : '') . '. Vote') . '">' . IDEA_UP . $num . '</button></form>';
    } elseif ($can['why'] === 'login' && !$wait && !m3d_idea_closed($i)) {
        $vote = '<a class="idea-vote" rel="nofollow" href="/auth/login.php?return=' . e(rawurlencode($here . '#idea-' . $id)) . '" title="Log in with Patreon to vote" aria-label="' . e($label) . '. Log in to vote">' . IDEA_UP . $num . '</a>';
    } else {
        $vote = '<span class="idea-vote is-off' . ($on ? ' is-on' : '') . '" title="' . e($label) . '">' . IDEA_UP . $num . '</span>';
    }
    $menu = [];
    // the creator: a waiting idea gets Publish / Reject on the row; a published one's status pill opens the statuses (one click each); a hidden one reads Hidden
    $side = $hid ? '<span class="mod-hid">Hidden</span>' : ($wait ? '<span class="idea-st st-wait">Waiting</span>' : idea_status_pill($i));
    if ($creator && $wait && !$hid) {
        $side = '<div class="idea-acts">' . str_replace('pm-item', 'btn btn-primary btn-sm', idea_action_btn($keys, 'approve', 'Publish'))
            . str_replace('pm-item', 'btn btn-ghost btn-sm', idea_action_btn($keys, 'delete', 'Reject', 'Reject this idea? It is deleted.')) . '</div>';
    } elseif ($creator && !$hid) {
        $opts = '';
        foreach (['new', 'planned', 'no', 'done'] as $k) {   // Done last, with its version right under it
            $opts .= '<label class="idea-opt"><input type="radio" name="status" value="' . $k . '"' . ($k === $st ? ' checked' : '') . ' /><span>' . e(IDEA_STATUSES[$k]) . '</span></label>';
        }
        $opts .= '<label class="idea-ver"><span>Done in Version</span><input class="input" name="ver" maxlength="' . IDEA_VER_MAX . '" value="' . e((string) ($i['ver'] ?? '')) . '" /></label>'
            . '<div class="idea-st-foot"><button class="btn btn-primary btn-sm">Save</button></div>';
        $side = '<details class="pmenu idea-stm"><summary class="idea-st st-' . $st . '" title="Change the status">' . e(idea_status_label($i)) . '</summary><div class="pm-pop"><form method="post" action="/auth/idea.php">' . $keys
            . '<input type="hidden" name="do" value="status" /><span class="pm-h">Status</span>' . $opts . '</form></div></details>';
    }
    if ($creator) {
        if (empty($i['admin'])) array_push($menu, ...array_slice(mod_menu_items('idea', (string) $id, false, (bool) $hid, '', '', $here), 0, 1));   // Hide… / Show Again
        if (empty($i['admin']) && $by !== '') {
            $blocked = in_array($by, m3d_idea_settings()['blocked'], true);
            $menu[] = idea_action_btn($keys, $blocked ? 'unblock' : 'block', $blocked ? 'Unblock' : 'Block', $blocked ? '' : 'Block ' . $who . ' from suggesting and voting?');
        }
    }
    elseif (empty($i['admin'])) $menu = mod_menu_items('idea', (string) $id, !empty($i['pending']), (bool) $hid, $by, $who, $here);   // a moderator: never Status or Delete
    $mine = $me !== '' && $by === $me;
    if (($creator && (!$wait || $hid)) || ($mine && !$creator && !$hid)) $menu[] = idea_action_btn($keys, 'delete', $creator ? 'Delete' : 'Delete My Idea', 'Delete this idea?', true);
    $text = trim((string) ($i['text'] ?? ''));
    $type = m3d_idea_types($kind)[$i['type'] ?? ''] ?? '';
    return '<article class="idea' . ($hid ? ' is-hid' : ($wait ? ' is-pending' : '')) . (m3d_idea_closed($i) ? ' is-closed' : '') . ($n >= IDEA_SHOWN ? ' is-extra' : '') . '" id="idea-' . $id . '"'
        . ' data-votes="' . count($votes) . '" data-at="' . (int) ($i['at'] ?? 0) . '" data-st="' . $st . '">'
        . $vote . ($text !== '' ? '<details class="idea-main"><summary>' : '<div class="idea-main">') . '<h3 class="idea-t">' . e((string) ($i['title'] ?? ''))
        . ($text !== '' ? '<span class="idea-has">Details</span>' : '') . '</h3>'
        . '<p class="idea-meta">' . ($type !== '' ? '<span class="idea-type t-' . e((string) $i['type']) . '">' . e($type) . '</span>' : '')
        . '<span>' . (!empty($i['admin']) ? 'By the creator' : '<span class="idea-sw">Suggested </span>by ' . e($who)) . ' · ' . e(bug_ago((int) ($i['at'] ?? 0))) . '</span>' . mod_tag($i) . '</p>'
        . ($hid ? '<p class="mod-why">' . mod_hid_line($hid) . '</p>' : '')
        // the details fold under the title, so every row is two lines high until opened
        . ($text !== '' ? '</summary><div class="idea-text md">' . md_html($text, ['picLinks' => true]) . '</div></details>' : '</div>')
        . '<div class="idea-side">' . $side . bug_post_menu($menu) . '</div></article>';
}

/** One button of an idea's ⋯ menu: a small form posting do=$do to auth/idea.php ($ask: a confirm question). */
function idea_action_btn(string $keys, string $do, string $label, string $ask = '', bool $red = false): string
{
    return '<form method="post" action="/auth/idea.php">' . $keys . '<button class="pm-item' . ($red ? ' is-red' : '') . '" name="do" value="' . e($do) . '"'
        . ($ask !== '' ? ' onclick="return confirm(' . e(json_encode($ask, JSON_UNESCAPED_UNICODE)) . ')"' : '') . '>' . e($label) . '</button></form>';
}

/** "12 / 60" at the end of a field's label in the Suggest window; site.js keeps it in step while typing. */
function idea_count(string $value, int $max): string
{
    return '<span class="idea-count" data-idea-count>' . mb_strlen($value) . ' / ' . $max . '</span>';
}

/** The "Suggest an Idea" window (site.js opens it; it opens by itself with what was typed after a refusal). */
function idea_dialog(string $kind, array $item, string $name, string $csrf, bool $checked): string
{
    $dk = $kind . ':' . $item['slug'];
    $draft = is_array($_SESSION['idea_draft'][$dk] ?? null) ? $_SESSION['idea_draft'][$dk] : null;
    if ($draft) unset($_SESSION['idea_draft'][$dk]);
    $type = (string) ($draft['type'] ?? 'feature');
    $types = '';
    foreach (m3d_idea_types($kind) as $k => $l) $types .= '<label class="chip idea-chip t-' . $k . '"><input type="radio" name="type" value="' . $k . '"' . ($k === $type ? ' checked' : '') . ' />' . e($l) . '</label>';
    // with bug reports on, "Fix" swaps the fields and Send for a way to Bug Reports, in the same place so the window keeps its size
    // (CSS :has, no script needed)
    $bug = m3d_bugs_on() ? '<div class="idea-bug"><p><b>Something not working?</b> Report it as a bug: the creator tracks bugs there and you get told when it’s fixed.</p>'
        . '<div class="idea-dlg-foot"><button class="btn btn-ghost" type="button" data-idea-close>Cancel</button><a class="btn btn-primary" href="/bugs/report/?about=' . e(rawurlencode($kind . ':' . $item['slug'])) . '">Report a Bug</a></div></div>' : '';
    return '<dialog class="sc-dlg idea-dlg" data-idea-dlg' . ($draft ? ' data-open' : '') . ' aria-labelledby="idea-dlg-t"><form method="post" action="/auth/idea.php">' . $csrf
        . '<input type="hidden" name="do" value="add" /><input type="hidden" name="kind" value="' . e($kind) . '" /><input type="hidden" name="slug" value="' . e((string) $item['slug']) . '" />'
        . '<h2 id="idea-dlg-t">Suggest an Idea for ' . e($name) . '</h2>'
        . '<p class="idea-dlg-sub">' . e(['outfits' => 'Only for this accessory: a variant, a feature, an improvement or a fix.', 'scenes' => 'Only for this scene: a variant, a feature, an improvement or a fix.'][$kind] ?? 'Only for this model: an outfit, a feature, an improvement or a fix.') . '</p>'
        . '<div class="field"><span class="lbl">What Is It?</span><div class="idea-chips">' . $types . '</div></div>'
        . '<div class="idea-swap">' . $bug . '<div class="idea-fields' . ($bug !== '' ? ' has-bug' : '') . '"><label class="field"><span class="lbl">Your Idea' . idea_count((string) ($draft['title'] ?? ''), IDEA_TITLE_MAX) . '</span><input class="input" name="title" required minlength="3" maxlength="' . IDEA_TITLE_MAX . '" placeholder="In a few words" value="' . e((string) ($draft['title'] ?? '')) . '" /></label>'
        . '<label class="field"><span class="lbl">Details <span class="muted small">(optional)</span>' . idea_count((string) ($draft['text'] ?? ''), IDEA_TEXT_MAX) . '</span><textarea class="input" name="text" rows="4" maxlength="' . IDEA_TEXT_MAX . '">' . e((string) ($draft['text'] ?? '')) . '</textarea></label>'
        . '<div class="idea-dlg-foot"><span class="muted small">' . ($checked ? 'Shown to everyone once the creator checks it.' : 'Shown to everyone with your Patreon name.') . '</span>'
        . '<button class="btn btn-ghost" type="button" data-idea-close>Cancel</button><button class="btn btn-primary">Send</button></div></div></div></form></dialog>';
}
