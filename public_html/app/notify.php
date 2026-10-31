<?php
// Admin notifications: the bell in the admin header and, for the creator, in the public header (notify_bell(), its list from
// /admin/notify.php → notify_list_html(), assets/bell.js), and Settings → Notifications (what shows in the bell, what goes to Discord).
//
// settings.json → notify: {bell: {event: bool}, discord: {event: bool}}, events = NOTIFY_EVENTS; the webhook in private/webhooks.php → notify
//   (m3d_webhook()). Never saved: every bell switch on, Discord as the old switches of the Bug Reports / Comments boxes were, with their
//   webhook (m3d_notify_settings()).
// data/notify.json: {seen (unix: Mark All as Read), read ["c<id>": comments opened from the bell since], likes {"kind:key": unix: an item's
//   likes opened from the bell}, cleared (unix: Clear All), gone {row code: the row's time when removed with its ✕ | "s:links": [md5 of the
//   dead addresses] | "s:updates": version | "s:problems" / "s:discord": unix}, posted {update, problem, likes}}: the creator's own read
//   marks, comment ids and item keys only (no visitor data); restored with the counters (DATA_PARTS).
//
// Clear All = Mark All as Read, and the list empties: read rows from before it are hidden (waiting comments stay until published), and so
// are the dead links and the update shown then. A row's ✕ hides it until something newer happens on it (a new like, a new comment on that
// report: a later time than the one stored); a Needs a Look row until it changes (a new dead link, a newer version, a new problem).
//
// New ("New" part of the list): bug reports the creator hasn't opened (the report's own `seen`, the Bugs list's Not Seen; a report
// opened before that got new comments has `seen_at` = when, so it reads as "commented on"), visitors' comments after `seen` not
// opened from the bell, comments waiting for approval (until published or deleted), and items that got likes (one row per item, how
// many since it was last read; never who: likes.json → fresh holds only times, m3d_likes_fresh()). Needs a Look (m3d_notify_site()): dead
// links and an update while they last, a failed Discord post and site problems since the last Mark All as Read (7 days at most).
// The number on the bell comes from small files only (the bug and comment summaries, m3d_data_summary()), so every page stays fast.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

/** event => label */
const NOTIFY_EVENTS = [
    'bugs'     => 'New Bug Reports',
    'bugc'     => 'Comments on Bug Reports',
    'comments' => 'New Comments',
    'ideas'    => 'New Ideas',
    'renders'  => 'Patron Renders',
    'mods'     => 'Moderator Actions',
    'likes'    => 'New Likes',
    'links'    => 'Dead Links',
    'problems' => 'Site Problems',
    'updates'  => 'Updates',
];
const NOTIFY_RECENT = 3 * 86400;      // read items stay in the list, greyed, this long
const NOTIFY_LIST_MAX = 30;           // rows in the New part
const NOTIFY_PROBLEM_GAP = 3600;      // one Discord post about site problems an hour at most
const NOTIFY_READ_MAX = 300;          // comment marks kept in data/notify.json
const NOTIFY_LIKES_GAP = 3600;        // one Discord post about new likes an hour at most
const NOTIFY_GONE_DAYS = 30;          // removed rows' marks kept this long (their rows aren't shown after NOTIFY_RECENT anyway)
const NOTIFY_CODE_RE = '/^(?:c\d{1,9}|i\d{1,9}|b\d{1,9}|r[0-9a-f]{8}|m\d{1,9}|l(?:models|outfits|scenes|posts|stills):[a-z0-9-]{1,100}|s:(?:links|updates|problems|discord))$/D';

/** A Settings box's address from public pages too (settings_url() lives in the admin's _admin.php). */
function notify_settings_url(string $id): string
{
    if (function_exists('settings_url')) return settings_url($id);
    return '/admin/settings.php?tab=' . (['discord' => 'features', 'problems' => 'maintenance'][$id] ?? $id) . '#' . $id;
}

function m3d_notify_settings(bool $fresh = false): array
{
    static $memo = null;
    if ($memo !== null && !$fresh) return $memo;
    $s = m3d_settings()['notify'] ?? null;
    if (!is_array($s)) {   // never saved: Discord as the switches of the Bug Reports and Comments boxes were
        $b = m3d_bug_settings();
        $c = m3d_comment_settings();
        $ok = fn(string $h) => preg_match(DISCORD_WEBHOOK_RE, $h) === 1;
        $s = ['discord' => ['bugs' => $b['notify'] && $ok($b['webhook']), 'comments' => $c['notify'] && $ok($c['webhook'])],
            'webhook' => $b['notify'] && $ok($b['webhook']) ? $b['webhook'] : ($ok($c['webhook']) ? $c['webhook'] : ($ok($b['webhook']) ? $b['webhook'] : ''))];
    }
    $out = ['bell' => [], 'discord' => [], 'webhook' => (string) ($s['webhook'] ?? m3d_webhook('notify'))];
    foreach (NOTIFY_EVENTS as $k => $_) {
        $out['bell'][$k] = (bool) ($s['bell'][$k] ?? true);
        $out['discord'][$k] = !empty($s['discord'][$k]);
    }
    return $memo = $out;
}

/** The feature an event belongs to is on (bug reports, comments); the others always are. */
function m3d_notify_feature(string $ev): bool
{
    return match ($ev) { 'bugs', 'bugc' => m3d_bugs_on(), 'comments' => m3d_comments_on(), 'ideas' => m3d_ideas_on(), 'renders' => m3d_showcase_on(), 'mods' => (bool) m3d_mod_ids(), default => true };
}

function m3d_notify_bell_on(string $ev): bool
{
    return !empty(m3d_notify_settings()['bell'][$ev]) && m3d_notify_feature($ev);
}

function m3d_notify_discord_on(string $ev): bool
{
    $s = m3d_notify_settings();
    return !empty($s['discord'][$ev]) && preg_match(DISCORD_WEBHOOK_RE, $s['webhook']) === 1 && m3d_notify_feature($ev);
}

/** ['seen' => unix, 'read' => [code => true], 'posted' => [...]]. The first time: everything until now counts as read. */
function m3d_notify_state(): array
{
    $d = m3d_read_json('notify');
    if ($d === null && !is_file(M3D_PRIVATE . '/data/notify.json')) {
        m3d_json_update('notify', fn(?array $x) => [is_array($x) ? $x : ['seen' => time(), 'read' => []], null]);
        $d = m3d_read_json('notify') ?? ['seen' => time()];
    }
    $d ??= [];
    return ['seen' => (int) ($d['seen'] ?? 0), 'read' => array_fill_keys(array_map('strval', (array) ($d['read'] ?? [])), true),
        'likes' => array_map('intval', is_array($d['likes'] ?? null) ? $d['likes'] : []),
        'cleared' => (int) ($d['cleared'] ?? 0), 'gone' => is_array($d['gone'] ?? null) ? $d['gone'] : [],
        'posted' => is_array($d['posted'] ?? null) ? $d['posted'] : []];
}

/** Locked change of data/notify.json: $fn(array $d) returns the new $d. False when it couldn't be saved. */
function m3d_notify_store(callable $fn): bool
{
    return m3d_json_update('notify', function (?array $d) use ($fn) {
        $d = $fn(is_array($d) ? $d : ['seen' => time(), 'read' => []]);
        $d['read'] = array_slice(array_values(array_unique(array_map('strval', (array) ($d['read'] ?? [])))), -NOTIFY_READ_MAX);
        $old = time() - LIKES_FRESH_DAYS * 86400;   // likes older than that aren't in likes.json → fresh any more
        $d['likes'] = array_filter(array_map('intval', is_array($d['likes'] ?? null) ? $d['likes'] : []), fn($t) => $t > $old);
        if (!$d['likes']) unset($d['likes']);
        $old = time() - NOTIFY_GONE_DAYS * 86400;   // rows' marks: times; the Needs a Look ones ("s:…") stay
        $d['gone'] = array_slice(array_filter(is_array($d['gone'] ?? null) ? $d['gone'] : [], fn($v, $k) => str_starts_with((string) $k, 's:') || (int) $v > $old, ARRAY_FILTER_USE_BOTH), -NOTIFY_READ_MAX, null, true);
        if (!$d['gone']) unset($d['gone']);
        return [$d, true];
    }) === true;
}

/** Whether a visitor's comment is new for the bell: waiting for approval, or written after the last Mark All as Read and not opened. */
function m3d_notify_comment_new(int $id, int $at, bool $pending, array $state): bool
{
    return ($pending || ($at > $state['seen'] && !isset($state['read']['c' . $id]))) && !m3d_notify_gone('c' . $id, $at, true, $state);
}

/** Whether a visitor's idea (app/ideas.php) is new for the bell: as a comment, with "i<id>" codes. */
function m3d_notify_idea_new(int $id, int $at, bool $pending, array $state): bool
{
    return ($pending || ($at > $state['seen'] && !isset($state['read']['i' . $id]))) && !m3d_notify_gone('i' . $id, $at, true, $state);
}

/** Whether a moderator's step (the moderation log, "m<id>") is new for the bell: after the last Mark All as Read, not opened. */
function m3d_notify_mod_new(int $id, int $at, array $state): bool
{
    return $at > $state['seen'] && !isset($state['read']['m' . $id]) && !m3d_notify_gone('m' . $id, $at, true, $state);
}

/** A row of the New part left out: removed with its ✕ (until something newer happens on it), or read and older than Clear All. */
function m3d_notify_gone(string $code, int $at, bool $unread, array $state): bool
{
    return (isset($state['gone'][$code]) && !str_starts_with($code, 's:') && $at <= (int) $state['gone'][$code]) || (!$unread && $at <= $state['cleared']);
}

/** Items with likes the bell shows: [key "kind:key" => [new (unread), likes in the last NOTIFY_RECENT seconds, newest time, likes in all]],
 *  from the likes summary only (no item data read). Read: given before Mark All as Read or before the item was opened from the bell. */
function m3d_notify_like_counts(array $state): array
{
    $l = m3d_likes_fresh();
    $recent = time() - NOTIFY_RECENT;
    $out = [];
    foreach ($l['fresh'] as $k => $ts) {
        $all = (int) ($l['n'][$k] ?? 0);
        if (!$all) continue;   // the item or its likes are gone
        $read = max($state['seen'], (int) ($state['likes'][$k] ?? 0));
        $new = count(array_filter($ts, fn($t) => $t > $read));
        $near = count(array_filter($ts, fn($t) => $t > $recent));
        if ($new || $near) $out[$k] = [min($new, $all), min($new ? $new : $near, $all), max($ts), $all];
    }
    return $out;
}

/** An item that can be liked: [name, what it is, public address], null when it is gone. */
function m3d_notify_like_item(string $k): ?array
{
    [$kind, $key] = explode(':', $k, 2) + ['', ''];
    if (in_array($kind, M3D_ITEM_KINDS, true)) {
        $it = m3d_item($kind, $key);
        return $it ? [$kind === 'models' ? m3d_model_name($it) : (string) $it['name'], m3d_item_type($kind, $it), m3d_item_url($kind, $key)] : null;
    }
    if ($kind === 'posts') {
        $p = m3d_post($key);
        return $p ? [(string) ($p['title'] ?? '') ?: 'A blog post', 'Blog Post', m3d_post_url($p)] : null;
    }
    if ($kind === 'stills') {
        $st = m3d_still($key);
        if (!$st) return null;
        $cap = trim((string) ($st['caption'] ?? ''));
        return [$cap !== '' ? mb_strimwidth($cap, 0, 60, '…') : 'A gallery picture', 'Gallery Picture', '/gallery/'];
    }
    return null;
}

/**
 * Needs a Look: [[event, tone (warn | info), icon, text, address, where it leads, code (its ✕)]]. $bell: only the events switched on for
 * the bell and not removed with their ✕ (Admin → Home shows them all), and problems only since the last Mark All as Read.
 */
function m3d_notify_site(bool $bell = true): array
{
    $now = time();
    $since = $now - 7 * 86400;
    if ($bell) $since = max($since, m3d_notify_state()['seen']);
    $gone = $bell ? m3d_notify_state()['gone'] : [];   // rows removed with their ✕ (Clear All too): only from the bell
    $on = fn(string $ev) => !$bell || m3d_notify_bell_on($ev);
    $out = [];
    if ($on('problems')) {
        $disc = m3d_discord();
        if (m3d_discord_on() && $disc['last'] && empty($disc['last']['ok']) && (!$bell || (int) strtotime((string) ($disc['last']['at'] ?? '')) > max($since, (int) ($gone['s:discord'] ?? 0)))) {
            $out[] = ['problems', 'warn', 'discord', 'Last Discord post failed' . (($w = (string) ($disc['last']['what'] ?? '')) !== '' ? " ($w)" : ''), notify_settings_url('discord'), 'Settings', 's:discord'];
        }
    }
    if ($on('links') && ($dead = lc_dead())) {   // from the last check (Link Check, or its automatic check)
        if (array_diff(array_column($dead, 'h'), (array) ($gone['s:links'] ?? []))) {   // removed: until a link not dead then is
            $names = array_values(array_unique(array_map(fn($l) => $l['kind'] === 'render' ? $l['name'] . ' (' . lcfirst($l['label']) . ')' : $l['name'], $dead)));   // "Tifa (render by Anna)"
            $out[] = ['links', 'warn', 'link', m3d_plural(count($dead), 'download link is', 'download links are') . ' dead: ' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : ''), '/admin/links.php', 'Link Check', 's:links'];
        }
    }
    if ($on('problems') && ($np = count(m3d_problems(max($since, (int) ($gone['s:problems'] ?? 0)))))) {
        $out[] = ['problems', 'warn', 'warn', m3d_plural($np, 'site problem') . ($bell && $since > $now - 7 * 86400 ? ' since you last looked' : ' in the last 7 days'), notify_settings_url('problems'), 'Settings', 's:problems'];
    }
    if ($on('updates') && ($new = m3d_notify_updates()) && ($gone['s:updates'] ?? '') !== (string) $new[0]['v']) {
        $out[] = ['updates', 'info', 'up', (count($new) === 1 ? 'Update available: ' : count($new) . ' updates available, newest ') . $new[0]['v'], '/admin/update.php', 'Update', 's:updates'];
    }
    return $out;
}

/** The versions newer than this one on the update channel, from its last check (never waits for GitHub: gh_check_cached()). */
function m3d_notify_updates(): array
{
    require_once M3D_SITE . '/admin/_github.php';
    $check = gh_check_cached(gh_saved()['channel']);
    return $check ? gh_newer($check) : [];
}

/** The number on the bell: new things switched on for the bell, plus the Needs a Look rows. */
function m3d_notify_count(): int
{
    $n = 0;
    if (m3d_notify_bell_on('bugs') || m3d_notify_bell_on('bugc')) {
        $s = m3d_bug_summary();
        if (m3d_notify_bell_on('bugs')) $n += $s['unseen'] - $s['unseen_c'];
        if (m3d_notify_bell_on('bugc')) $n += $s['unseen_c'];
    }
    if (m3d_notify_bell_on('comments')) {
        $state = m3d_notify_state();
        foreach (m3d_comment_summary()['recent'] as [$id, $at, $p]) if (m3d_notify_comment_new((int) $id, (int) $at, (bool) $p, $state)) $n++;
    }
    if (m3d_notify_bell_on('ideas')) {   // like comments: waiting ones count until published or deleted
        $state = m3d_notify_state();
        foreach (m3d_idea_summary()['recent'] as [$id, $at, $p]) if (m3d_notify_idea_new((int) $id, (int) $at, (bool) $p, $state)) $n++;
    }
    if (m3d_notify_bell_on('likes')) {
        foreach (m3d_notify_like_counts(m3d_notify_state()) as [$new]) if ($new) $n++;   // one per item
    }
    if (m3d_notify_bell_on('mods')) {   // a moderator's step: new after Mark All as Read, until opened
        $state = m3d_notify_state();
        foreach (m3d_modlog_summary() as [$id, $at]) if (m3d_notify_mod_new((int) $id, (int) $at, $state)) $n++;
    }
    if (m3d_notify_bell_on('renders')) {   // waiting renders count until approved or rejected, like waiting comments
        $state = m3d_notify_state();
        foreach (m3d_showcase_summary()['waiting'] as [$id, $at]) if (!m3d_notify_gone('r' . $id, (int) $at, true, $state)) $n++;
    }
    return $n + count(m3d_notify_site());
}

/**
 * The New part of the list, newest first: [code, event, group (comments | bugs), unread, who, what, on, sub, at, address, waiting,
 * comment id (Publish)]. Read ones of the last NOTIFY_RECENT seconds stay, greyed. Reads the comments and bug reports files.
 */
function m3d_notify_items(): array
{
    $rows = [];
    $recent = time() - NOTIFY_RECENT;
    $state = m3d_notify_state();
    // a quote on one line, without the text format's marks ("**great**" reads "great")
    $clip = fn(string $t, int $w = 140) => mb_strimwidth(preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags(md_inline($t)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '', 0, $w, '…');
    if (m3d_notify_bell_on('comments')) {
        foreach (m3d_comments_flat() as [$k, $key, $c]) {
            if (!empty($c['admin'])) continue;
            $id = (int) ($c['id'] ?? 0);
            $at = (int) ($c['at'] ?? 0);
            $new = m3d_notify_comment_new($id, $at, !empty($c['pending']), $state);
            if (!$new && $at <= $recent) continue;
            [$title] = m3d_comment_page($k, $key);
            $rows[] = ['c' . $id, 'comments', 'comments', $new, (string) ($c['name'] ?? '') ?: 'Someone', !empty($c['re']) ? 'replied on' : 'commented on',
                $title, '“' . $clip((string) ($c['text'] ?? '')) . '”', $at, '/admin/notify.php?go=c' . $id, !empty($c['pending']), !empty($c['pending']) ? $id : 0];
        }
    }
    if (m3d_notify_bell_on('ideas')) {
        foreach (m3d_ideas_flat() as [$k, $slug, $i]) {
            if (!empty($i['admin'])) continue;
            $id = (int) ($i['id'] ?? 0);
            $at = (int) ($i['at'] ?? 0);
            $new = m3d_notify_idea_new($id, $at, !empty($i['pending']), $state);
            if (!$new && $at <= $recent) continue;
            [$title] = m3d_idea_page($k, $slug);
            $rows[] = ['i' . $id, 'ideas', 'ideas', $new, (string) ($i['name'] ?? '') ?: 'Someone', 'suggested an idea for', $title,
                '“' . $clip((string) ($i['title'] ?? '')) . '”', $at, '/admin/notify.php?go=i' . $id, !empty($i['pending']), 0];
        }
    }
    if (m3d_notify_bell_on('bugs') || m3d_notify_bell_on('bugc')) {
        $owners = m3d_owner_ids($GLOBALS['M3D']);
        foreach (m3d_bugs() as $b) {
            $n = (int) $b['n'];
            $unseen = empty($b['seen']);
            // visitors' comments: the new ones (after the creator last opened it), else the latest one for a read row
            $cms = array_values(array_filter((array) ($b['log'] ?? []), fn($x) => ($x['t'] ?? '') === 'c' && empty($x['admin'])));
            $after = isset($b['seen_at']) ? (int) $b['seen_at'] : PHP_INT_MAX;
            $fresh = array_values(array_filter($cms, fn($x) => (int) ($x['at'] ?? 0) > $after));
            $ev = $unseen && isset($b['seen_at']) ? 'bugc' : ($unseen ? 'bugs' : (($last = end($cms)) && (int) ($last['at'] ?? 0) > (int) ($b['at'] ?? 0) ? 'bugc' : 'bugs'));
            if (!m3d_notify_bell_on($ev)) continue;
            $on = '#' . $n . ' · ' . (string) ($b['title'] ?? '');
            if ($ev === 'bugs') {
                $at = (int) ($b['at'] ?? 0);
                if (!$unseen && ($at <= $recent || in_array((string) ($b['by'] ?? ''), $owners, true))) continue;   // not the creator's own reports
                $a = m3d_bug_about($b);
                $rows[] = ['b' . $n, 'bugs', 'bugs', $unseen, (string) ($b['name'] ?? '') ?: 'Someone', 'reported', $on,
                    implode(' · ', array_filter([$a['name'], bug_version_words((string) ($b['ver'] ?? '')), ($b['blender'] ?? '') !== '' ? 'Blender ' . $b['blender'] : ''])),
                    $at, '/bugs/' . $n . '/', !empty($b['pending']), 0];
            } else {
                $list = $unseen ? ($fresh ?: array_slice($cms, -1)) : array_slice($cms, -1);
                $x = end($list);
                if (!$x) continue;
                $at = (int) ($x['at'] ?? 0);
                if (!$unseen && $at <= $recent) continue;
                $names = array_values(array_unique(array_map(fn($y) => (string) ($y['name'] ?? '') ?: 'Someone', $list)));
                $who = array_pop($names);
                if ($names) $who .= ' and ' . m3d_plural(count($names), 'other');
                $rows[] = ['b' . $n, 'bugc', 'bugs', $unseen, $who, 'commented on', $on, '“' . $clip((string) ($x['text'] ?? '')) . '”', $at, '/bugs/' . $n . '/#comments', false, 0];
            }
        }
    }
    if (m3d_notify_bell_on('likes')) {
        foreach (m3d_notify_like_counts($state) as $k => [$new, $shown, $at, $all]) {
            if (!($it = m3d_notify_like_item((string) $k))) continue;
            [$name, $what] = $it;
            $rows[] = ['l' . $k, 'likes', 'likes', $new > 0, $name, 'got', $shown === 1 ? ($new ? 'a new like' : 'a like') : m3d_plural($shown, $new ? 'new like' : 'like'),
                $what . ' · ' . m3d_plural($all, 'like') . ' in all', $at, '/admin/notify.php?go=' . rawurlencode('l' . $k), false, 0];
        }
    }
    if (m3d_notify_bell_on('mods')) {
        foreach (m3d_modlog_summary() as [$id, $at, $who, $what, $on, $sub]) {
            $new = m3d_notify_mod_new((int) $id, (int) $at, $state);
            if (!$new && $at <= $recent) continue;
            $rows[] = ['m' . $id, 'mods', 'mods', $new, $who, $what, $on, $sub, (int) $at, '/admin/notify.php?go=m' . (int) $id, false, 0];
        }
    }
    if (m3d_notify_bell_on('renders')) {
        foreach (m3d_renders() as $r) {
            if (!empty($r['ok'])) continue;   // only waiting ones: approving or rejecting is what reads them
            $it = m3d_item($r['kind'], $r['slug']);
            $rows[] = ['r' . $r['id'], 'renders', 'renders', true, trim((string) ($r['name'] ?? '')) ?: 'A patron', 'shared a render of', $it ? $it['name'] : $r['slug'],
                ($c = trim((string) ($r['caption'] ?? ''))) !== '' ? '“' . $clip($c) . '”' : '', (int) ($r['at'] ?? 0), m3d_showcase_admin_url($r['id']), true, 0];
        }
    }
    $rows = array_filter($rows, fn($r) => !m3d_notify_gone($r[0], $r[8], $r[3], $state));
    usort($rows, fn($a, $b) => $b[8] <=> $a[8]);
    return array_slice($rows, 0, NOTIFY_LIST_MAX);
}

/** "12 min", "3 h", "Yesterday", "4 d", then the date (site time). */
function notify_ago(int $t): string
{
    $d = time() - $t;
    if ($d < 90) return 'Now';
    if ($d < 3600) return round($d / 60) . ' min';
    if ($d < 86400) return round($d / 3600) . ' h';
    if (m3d_site_time($t, 'Y-m-d') === m3d_site_time(time() - 86400, 'Y-m-d')) return 'Yesterday';
    if ($d < 7 * 86400) return round($d / 86400) . ' d';
    return m3d_site_time($t, 'j M');
}

const NOTIFY_ICONS = [
    'mod'      => '<path d="M12 3.5l7 2.8v5.2c0 4.3-2.9 8-7 9.5-4.1-1.5-7-5.2-7-9.5V6.3z"/><path d="M9 12l2 2 4-4"/>',
    'comments' => '<path d="M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v8a1.5 1.5 0 0 1-1.5 1.5H10l-4.5 3.5V16.5H5A1.5 1.5 0 0 1 3.5 15V7A1.5 1.5 0 0 1 5 5.5z"/>',
    'bugs'     => '<rect x="7.5" y="8" width="9" height="12" rx="4.5"/><path d="M9.5 8a2.5 2.5 0 0 1 5 0M12 12v8M7.5 13H4M20 13h-3.5M5 8.5l2.8 1.6M19 8.5l-2.8 1.6M5 18.5l2.8-1.6M19 18.5l-2.8-1.6"/>',
    'link'     => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1M4 4l16 16"/>',
    'up'       => '<path d="M12 19V6M6.5 11.5 12 6l5.5 5.5M5 20h14"/>',
    'warn'     => '<path d="M12 4 2.8 19.5h18.4zM12 10v4.5M12 17.2v.3"/>',
    'discord'  => '<path d="M7 7.5c3.3-1.3 6.7-1.3 10 0l1.8 8.5c-1.6 1.3-3.2 2-4.8 2.3l-.9-1.6M7 7.5 5.2 16c1.6 1.3 3.2 2 4.8 2.3l.9-1.6M8.5 15c2.3.9 4.7.9 7 0"/>',
    'renders'  => '<rect x="3.5" y="5" width="17" height="14" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m4 17 5-4.5 3.5 3 3-2.5 4.5 4"/>',
    'ideas'    => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.7.6 1.1 1.4 1.1 2.2h5c0-.8.4-1.6 1.1-2.2A6 6 0 0 0 12 3z"/>',
    'react'    => '<circle cx="12" cy="12" r="8.5"/><path d="M8.5 14c.9 1.3 2.1 2 3.5 2s2.6-.7 3.5-2M9 10h.01M15 10h.01"/>',
    'likes'    => '<path d="M12 19.5s-7.5-4.4-7.5-10A4.2 4.2 0 0 1 12 7a4.2 4.2 0 0 1 7.5 2.5c0 5.6-7.5 10-7.5 10z"/>',
    'bell'     => '<path d="M6.5 16.5V11a5.5 5.5 0 0 1 11 0v5.5l1.5 2h-15z"/><path d="M10 21h4"/>',
];

function notify_icon(string $k, string $cls = ''): string
{
    return '<span class="nt-ico' . ($cls !== '' ? ' ' . $cls : '') . '"><svg viewBox="0 0 24 24" aria-hidden="true">' . (NOTIFY_ICONS[$k] ?? '') . '</svg></span>';
}

/** The bell (admin header; public header for the creator): the number, and an empty panel that bell.js fills from /admin/notify.php. */
function notify_bell(): string
{
    $n = m3d_notify_count();
    $label = 'Notifications' . ($n ? ' (' . $n . ')' : '');
    return '<div class="nt" data-bell data-src="/admin/notify.php" data-csrf="' . e(m3d_csrf()) . '">'
        . '<button type="button" class="hsearch-btn nt-bell" title="Notifications" aria-label="' . e($label) . '" aria-expanded="false" aria-controls="nt-panel" data-bell-btn>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">' . NOTIFY_ICONS['bell'] . '</svg>'
        . '<span class="nt-count" data-bell-count' . ($n ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . '</span></button>'
        . '<div class="nt-panel" id="nt-panel" role="dialog" aria-label="Notifications" data-bell-panel hidden></div>'
        . '<script src="' . e(asset('bell.js')) . '" defer></script></div>';   // the script inside, so `.nt + .account` still matches
}

/** The panel's content: tabs (when two kinds or more show), New, Needs a Look, the link to the settings. */
function notify_list_html(): string
{
    $items = m3d_notify_items();
    $site = m3d_notify_site();
    $groups = ['comments' => ['Comments', 0, 0], 'ideas' => ['Ideas', 0, 0], 'bugs' => ['Bug Reports', 0, 0], 'renders' => ['Renders', 0, 0], 'mods' => ['Moderation', 0, 0], 'likes' => ['Likes', 0, 0], 'site' => ['Site', 0, count($site)]];
    foreach ($items as $r) { $groups[$r[2]][1]++; if ($r[3]) $groups[$r[2]][2]++; }
    $groups['site'][1] = count($site);
    $shown = array_filter($groups, fn($g) => $g[1] > 0);
    $unread = count(array_filter($items, fn($r) => $r[3])) + count($site);
    // Mark All as Read only when it changes something: dead links and an update stay until they are dealt with; Clear All while a row
    // would leave (waiting comments stay)
    $read = count(array_filter($items, fn($r) => $r[3] && !$r[11] && $r[1] !== 'renders' && !($r[1] === 'ideas' && $r[10]))) + count(array_filter($site, fn($r) => $r[0] === 'problems'));   // waiting renders stay until dealt with
    $clear = count(array_filter($items, fn($r) => !$r[11] && $r[1] !== 'renders' && !($r[1] === 'ideas' && $r[10]))) + count($site);
    $h = '<div class="nt-head"><h2>Notifications</h2>'
        . ($read ? '<button type="button" class="nt-link" data-nt-readall>Mark All as Read</button>' : '')
        . ($read && $clear ? '<span class="nt-sep" aria-hidden="true">·</span>' : '')
        . ($clear ? '<button type="button" class="nt-link" data-nt-clear>Clear All</button>' : '') . '</div>';
    if (count($shown) > 1) {
        $h .= '<div class="nt-tabs" role="group" aria-label="Show"><button type="button" class="chip" aria-pressed="true" data-nt-tab="all">All' . ($unread ? '<span>' . $unread . '</span>' : '') . '</button>';
        foreach ($shown as $k => [$label, , $u]) $h .= '<button type="button" class="chip" aria-pressed="false" data-nt-tab="' . $k . '">' . $label . ($u ? '<span>' . $u . '</span>' : '') . '</button>';
        $h .= '</div>';
    }
    if (!$items && !$site) {
        return $h . '<p class="nt-empty">' . notify_icon('bell') . 'Nothing new.</p>' . notify_foot();
    }
    if ($items) {
        $h .= '<div data-nt-g="' . e(implode(' ', array_unique(array_column($items, 2)))) . '"><p class="nt-sec">New</p><ul class="nt-list">';
        foreach ($items as [$code, $ev, $g, $new, $who, $what, $on, $sub, $at, $url, $wait, $cid]) {
            $h .= '<li class="nt-row' . ($new ? ' is-unread' : '') . '" data-nt-g="' . $g . '" data-nt-code="' . e($code) . '" data-nt-at="' . $at . '">'
                . '<a class="nt-hit" href="' . e($url) . '">' . notify_icon($g === 'mods' ? 'mod' : $g, 'nt-' . ($g === 'mods' ? 'mod' : $g)) . '<span class="nt-main"><span class="nt-t"><b>' . e($who) . '</b> ' . e($what) . ' <b>' . e($on) . '</b>'
                . ($wait ? ' <span class="nt-wait">Waiting</span>' : '') . ($new ? '<span class="sr-only"> (new)</span>' : '') . '</span>'
                . ($sub !== '' ? '<span class="nt-s">' . e($sub) . '</span>' : '') . '</span>'
                . '<time class="nt-when" datetime="' . e(gmdate('c', $at)) . '" title="' . e(m3d_site_time($at, 'D j M, H:i T')) . '">' . e(notify_ago($at)) . '</time></a>'
                . notify_tools($new && !$cid && $ev !== 'renders' && !($ev === 'ideas' && $wait))
                . ($cid ? '<span class="nt-acts"><button type="button" class="btn btn-primary btn-sm" data-nt-publish="' . $cid . '">Publish</button></span>' : '')
                . '</li>';
        }
        $h .= '</ul></div>';
    }
    if ($site) {
        $h .= '<div data-nt-g="site"><p class="nt-sec">Needs a Look</p><ul class="nt-list">';
        foreach ($site as [, $tone, $ico, $text, $url, $go, $code]) {
            $h .= '<li class="nt-row nt-site ' . $tone . '" data-nt-g="site" data-nt-code="' . e($code) . '"><a class="nt-hit" href="' . e($url) . '">' . notify_icon($ico)
                . '<span class="nt-main"><span class="nt-t">' . e($text) . '</span></span><span class="nt-go">' . e($go) . ' →</span></a>' . notify_tools(false) . '</li>';
        }
        $h .= '</ul></div>';
    }
    return $h . notify_foot();
}

/** A row's buttons, over its time (on hover; always on touch screens): ✓ Mark as Read (unread rows that can be read) and ✕ Remove. */
function notify_tools(bool $read): string
{
    $svg = fn(string $d) => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . $d . '"/></svg>';
    return '<span class="nt-tools">'
        . ($read ? '<button type="button" class="nt-ib" title="Mark as Read" aria-label="Mark as Read" data-nt-read>' . $svg('M5 12.5l4.5 4.5L19 7.5') . '</button>' : '')
        . '<button type="button" class="nt-ib" title="Remove" aria-label="Remove from the list" data-nt-remove>' . $svg('M6.5 6.5l11 11M17.5 6.5l-11 11') . '</button></span>';
}

function notify_foot(): string
{
    return '<div class="nt-foot"><a href="' . e(notify_settings_url('notify')) . '">Notification Settings</a>'
        . (m3d_bugs_on() ? '<a href="/bugs/">Bug Reports →</a>' : '') . '</div>';
}

/** Mark All as Read: comments until now, every bug report not opened yet that the bell shows (its switches: a new report, new comments). */
function m3d_notify_read_all(): bool
{
    $ok = m3d_notify_store(fn(array $d) => ['seen' => time(), 'read' => [], 'likes' => []] + $d);
    $shown = fn(array $b) => empty($b['seen']) && m3d_notify_bell_on(isset($b['seen_at']) ? 'bugc' : 'bugs');
    if (array_filter(m3d_bugs(), $shown)) {
        $ok = m3d_bugs_update(function (array $bugs, int $next) use ($shown) {
            foreach ($bugs as &$b) if ($shown($b)) { $b['seen'] = true; unset($b['seen_at']); }
            unset($b);
            return [$bugs, $next, true];
        }) && $ok;
    }
    return $ok;
}

/** Clear All: Mark All as Read, then the read rows until now, the dead links and the update shown now leave the list. */
function m3d_notify_clear(): bool
{
    if (!m3d_notify_read_all()) return false;
    $site = [];
    foreach (m3d_notify_site() as $r) $site[$r[6]] = m3d_notify_site_mark($r[6]);
    return m3d_notify_store(function (array $d) use ($site) {
        $d['cleared'] = time();
        foreach ($site as $k => $v) $d['gone'][$k] = $v;
        return $d;
    });
}

/** What a Needs a Look row's ✕ stores: the dead addresses or the version shown now, else the time (problems, Discord: newer ones show). */
function m3d_notify_site_mark(string $code): array|string|int
{
    return match ($code) {
        's:links' => array_values(array_unique(array_column(lc_dead(), 'h'))),
        's:updates' => (string) (m3d_notify_updates()[0]['v'] ?? ''),
        default => time(),
    };
}

/** A comment ("c<id>"), an item's likes ("l<kind>:<key>") or a bug report ("b<n>": as if opened) read from now on. False when not saved. */
function m3d_notify_read(string $code): bool
{
    if ($code[0] === 'b') {
        $n = (int) substr($code, 1);
        if (!array_filter(m3d_bugs(), fn($b) => (int) $b['n'] === $n && empty($b['seen']))) return true;   // read already, or gone
        return (bool) m3d_bugs_update(function (array $bugs, int $next) use ($n) {
            foreach ($bugs as &$b) if ((int) $b['n'] === $n) { $b['seen'] = true; unset($b['seen_at']); }
            unset($b);
            return [$bugs, $next, true];
        });
    }
    return m3d_notify_store(function (array $d) use ($code) {
        if ($code[0] === 'l') $d['likes'][substr($code, 1)] = time(); else $d['read'][] = $code;
        return $d;
    });
}

/** A row's ✕: read, and left out of the list until something happens on it after $at (the row's time); a Needs a Look row until it changes. */
function m3d_notify_remove(string $code, int $at): bool
{
    if (str_starts_with($code, 's:')) {
        $v = m3d_notify_site_mark($code);
        return m3d_notify_store(function (array $d) use ($code, $v) { $d['gone'][$code] = $v; return $d; });
    }
    if (!m3d_notify_read($code)) return false;
    $at = min(max(0, $at), time());
    return m3d_notify_store(function (array $d) use ($code, $at) {
        $d['gone'][$code] = max($at, (int) ($d['gone'][$code] ?? 0));
        return $d;
    });
}

// ── Discord: one private channel for the creator ──────────────────────────────
/** For privacy.php and Personal Data: what of bug reports goes to the creator's channel ("new reports and comments on reports"), '' = nothing. */
function notify_bug_discord_words(): string
{
    $w = array_filter([m3d_notify_discord_on('bugs') ? 'new reports' : '', m3d_notify_discord_on('bugc') ? 'comments on reports' : '']);
    return implode(' and ', $w);
}

/** Posts $embeds to the creator's channel when the event is switched on for Discord. A failure is only logged. */
/** The cards of the private posts (m3d_discord_card()): an icon before the line under the title, and the name of the button that opens it. */
const NOTIFY_CARD = [
    'bugs'     => ['🐞', 'Open Report'],
    'bugc'     => ['💬', 'Open Comment'],
    'comments' => ['💬', 'Open Comment'],
    'ideas'    => ['💡', 'Open Idea'],
    'renders'  => ['🖼️', 'Review in From Patrons'],
    'mods'     => ['🛡️', 'Open the Log'],
    'likes'    => ['❤️', 'Open Stats'],
    'links'    => ['🔗', 'Open Link Check'],
    'problems' => ['⚠️', 'Open Site Problems'],
    'updates'  => ['⬆️', 'Open Update'],
];

function m3d_notify_post(string $ev, array $embeds): void
{
    if (!m3d_notify_discord_on($ev)) return;
    // as cards: "🐞 New bug report · Name" (the embed's footer, else the event's name) under the title, a button to the page
    [$icon, $button] = NOTIFY_CARD[$ev] ?? ['🔔', 'Open'];
    foreach ($embeds as &$e) {
        $e['sub'] ??= $icon . ' ' . discord_md_text((string) (($e['footer']['text'] ?? '') ?: (NOTIFY_EVENTS[$ev] ?? '')));
        if (!isset($e['buttons']) && ($e['url'] ?? '') !== '') $e['buttons'] = [[$button, $e['url']]];
    }
    unset($e);
    $hook = m3d_notify_settings()['webhook'];
    $r = m3d_session_pause(fn() => m3d_discord_post($hook, '', $embeds));
    if (empty($r['ok'])) m3d_problem('Notifications', 'Discord post failed: ' . ($r['error'] ?? ''));
}

/** A visitor's text as a quote under their name: "**Name**" then each line after "> " (both plain, m3d_notify_plain()). */
function m3d_notify_quote(string $name, string $text, int $max): string
{
    $q = implode("\n", array_map(fn($l) => '> ' . $l, explode("\n", m3d_notify_plain(trim($text), $max))));
    return ($name !== '' ? '**' . m3d_notify_plain($name, 80) . "**\n" : '') . $q;
}

/** A visitor's text as plain text in an embed: no masked links ("[this-site](https://elsewhere)") or headings in the owner's channel. */
function m3d_notify_plain(string $t, int $max): string
{
    return (string) preg_replace('/([\\\\`*_~|>#\[\]()<])/', '\\\\$1', mb_substr($t, 0, $max));
}

/** Called by m3d_problem(): one post an hour at most, sent after the page (never for this file's own failures). */
function m3d_notify_problem(string $where, string $msg): void
{
    static $queued = false;
    if ($queued || $where === 'Notifications' || !m3d_notify_discord_on('problems')) return;
    $queued = true;
    $page = PHP_SAPI === 'cli' ? '' : (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    m3d_after_response(function () use ($where, $msg, $page) {
        $now = time();
        $due = false;
        m3d_notify_store(function (array $d) use ($now, &$due) {
            if ((int) ($d['posted']['problem'] ?? 0) <= $now - NOTIFY_PROBLEM_GAP) { $d['posted']['problem'] = $now; $due = true; }
            return $d;
        });
        if (!$due) return;
        m3d_notify_post('problems', [['title' => 'Site problem: ' . mb_substr($where, 0, 60), 'url' => abs_url(notify_settings_url('problems')),
            'description' => m3d_notify_plain($msg, 500) . ($page !== '' ? "\n" . m3d_notify_plain($page, 150) : ''),
            'color' => hexdec('f96854'), 'footer' => ['text' => 'More problems in the next hour are only listed in Settings → Site Problems'],
            'sub' => '⚠️ Site Problem · more in the next hour only go to Settings']]);
    });
}

/** Called by m3d_set_like() for a new like (not the creator's): after the page, one post an hour at most, listing every item's likes
 *  since the last post (so likes given in the hour after a post come with the next one). Says how many, never who. */
function m3d_notify_likes_discord(): void
{
    static $queued = false;
    if ($queued || !m3d_notify_discord_on('likes')) return;
    $queued = true;
    m3d_after_response(function () {
        $now = time();
        $since = 0;
        m3d_notify_store(function (array $d) use ($now, &$since) {
            $last = (int) ($d['posted']['likes'] ?? 0);
            if ($last <= $now - NOTIFY_LIKES_GAP) { $d['posted']['likes'] = $now; $since = $last ?: $now - NOTIFY_LIKES_GAP; }   // the first post: the last hour
            return $d;
        });
        if (!$since) return;
        $d = m3d_read_json('likes') ?? [];
        $rows = [];
        foreach ((array) ($d['fresh'] ?? []) as $k => $ts) {
            $new = count(array_filter((array) $ts, fn($t) => (int) $t > $since));
            [$kind, $key] = explode(':', (string) $k, 2) + ['', ''];
            $all = count((array) ($d[$kind][$key] ?? []));
            if ($new && $all && ($it = m3d_notify_like_item((string) $k))) $rows[] = [min($new, $all), $all, $it];
        }
        if (!$rows) return;
        usort($rows, fn($a, $b) => $b[0] <=> $a[0]);
        $total = array_sum(array_column($rows, 0));
        $lines = array_map(fn($r) => '• [' . m3d_notify_plain($r[2][0], 80) . '](' . abs_url($r[2][2]) . ') (' . $r[2][1] . '): '
            . m3d_plural($r[0], 'new like') . ' · ' . $r[1] . ' in all', array_slice($rows, 0, 15));
        if (count($rows) > 15) $lines[] = '…and ' . (count($rows) - 15) . ' more';
        m3d_notify_post('likes', [['title' => m3d_plural($total, 'new like'), 'description' => implode("\n", $lines), 'color' => hexdec('ff8fa3'), 'buttons' => [['Open Stats', abs_url('/admin/stats.php')]], 'sub' => '❤️ New Likes · one post an hour at most',
            'footer' => ['text' => 'One post an hour at most: likes given meanwhile come with the next one']]]);
    });
}

/** Called after an update check (gh_check_cached()): the newest version on the channel, posted once. */
function m3d_notify_update(array $check): void
{
    if (!m3d_notify_discord_on('updates') || !($new = gh_newer($check))) return;
    $v = (string) $new[0]['v'];
    $due = false;
    m3d_notify_store(function (array $d) use ($v, &$due) {
        if (($d['posted']['update'] ?? '') !== $v) { $d['posted']['update'] = $v; $due = true; }
        return $d;
    });
    if ($due) m3d_notify_post('updates', [['title' => 'Update available: ' . $v, 'url' => abs_url('/admin/update.php'),
        'description' => 'Installed: ' . M3D_VERSION . '. Install it in Settings → Update.', 'color' => hexdec('6fcf8e'), 'sub' => '⬆️ Update Available']]);
}

/** Called by the automatic link check with the links it found dead this time ([lc_links() row]). */
function m3d_notify_dead_links(array $dead): void
{
    if (!$dead) return;
    $lines = array_map(fn($l) => '• ' . m3d_notify_plain($l['name'] . ' · ' . $l['label'], 120), array_slice($dead, 0, 10));
    if (count($dead) > 10) $lines[] = '…and ' . (count($dead) - 10) . ' more';
    m3d_notify_post('links', [['title' => m3d_plural(count($dead), 'download link is', 'download links are') . ' dead', 'url' => abs_url('/admin/links.php'),
        'description' => implode("\n", $lines), 'color' => hexdec('f96854'), 'footer' => ['text' => 'Automatic link check'], 'sub' => '🔗 Dead Links · Automatic Link Check']]);
}
