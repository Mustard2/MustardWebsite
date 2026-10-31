<?php
// The bell of logged-in visitors (patrons and free members; the creator has their own, app/notify.php): what happened to their own
// things. The same look and script as the creator's (notify_bell(), assets/bell.js), filled from /auth/bell.php.
//
// Rows (m3d_ubell_rows()), all worked out from what the site already stores:
//   updates  a newer version of an item they downloaded (m3d_updates_shown()): until downloaded; ✕ = "Ignore this update" (data/ignored.json);
//            or of an item they bookmarked (m3d_bookmark_news(), row "k<kind>:<slug>"): read ones UBELL_DAYS; ✕ = the bookmark moves to that version
//   related  for a model they bookmarked (downloaded ones too): new Gallery pictures of it (one row per model, "g<slug>"), new accessories and scenes for
//            it ("n<kind>:<slug>", from their first day out) since the bookmark: m3d_bookmark_related(); UBELL_DAYS; ✕ = left out
//   bugs     a report they follow got a comment or a new status (m3d_bug_following()): until the report is opened; ✕ = marked opened
//   replies  someone replied to their comment (comments.json: `to`, else `re`, is one of theirs): UBELL_DAYS; ✕ = left out
//   renders  their render was approved, or added for them by the creator (showcase.json: ok_at; not while hidden): UBELL_DAYS; ✕ = left out
//   reactions others reacted to their comment, bug report or bug comment (the summaries' `rx`, from each post's `rxat`; never who): UBELL_DAYS; ✕ = left out
//   mod      they were made a moderator, or are no longer one (settings.json → moderation.bell, m3d_mod_bell_ref()): UBELL_DAYS, while it holds; ✕ = left out
//   modq     moderators only: a post waiting for their check (m3d_mod_list('waiting'), the number from m3d_mod_waiting_count()): until someone approves or
//            hides it; never read, no ✕, left alone by Clear All. A moderator's panel has two tabs, User (the rows above) and Moderator (these and `mod`)
// data/bell/<code>.json, one small file per person (code = m3d_like_code(), no ids or names: privacy.php, Personal Data):
//   {upd ["kind:slug@version": updates read], read {"b<n>" | "c<id>" | "r<id>" | "x<comment id>" | "y<n>[-<comment at>]": unix}, gone {the same codes: unix},
//   off [UBELL_EVENTS switched off], render {named, link? {t, h}} (the last two: the person's Settings page, account-settings.php),
//   bm {"kind:slug": [unix, version out then | '~' (nothing to download then)]}, nobm ["kind:slug" downloaded, bookmark removed]} (see Bookmarks). One file
//   each, so a page reads a few hundred bytes, never everyone's marks (measured: one shared file of 5000 people took ~8 ms to read on every page).
//   Not in backups (losing it shows the number again and puts the person's switches back on, nothing else). Writes wait for data/bell/.lock.
// The number on the bell = unread rows: a row is read once clicked (bell.js posts do=read), removed with its ✕, or (a report) opened;
//   opening the bell changes nothing (Pi). Always on for anyone logged in (Pi); an event shows while its feature is on (m3d_ubell_event()) and
//   the person hasn't switched it off on their Settings page (`off`, m3d_ubell_state()).
// Previewing as a patron, the creator's marks go in the preview itself ($_SESSION['preview']['bell'], an update's ✕ in
//   $_SESSION['preview']['ignored'] by m3d_ignore_update()): nothing is written to files, and a new preview starts with none.
// Every page reads only small files for the number: the comments' and renders' summaries (m3d_data_summary()), and the person's own file
// (read once a page, when it exists).
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const UBELL_DAYS = 30;          // replies and approved renders stay in the list this long
const UBELL_MAX = 40;           // rows in the list
const UBELL_EVENTS = [   // the Settings page's switches, in the bell's order: their titles
    'updates' => 'Updates to What I Downloaded or Bookmarked',
    'related' => 'New Pictures, Accessories and Scenes for Characters I Bookmarked',
    'bugs' => 'News on Bug Reports I Follow',
    'replies' => 'Replies to My Comments',
    'renders' => 'My Renders Approved',
    'reactions' => 'Reactions to My Comments and Reports',
    'modq' => 'Posts Waiting for a Check',   // moderators only
];
const UBELL_MODQ_MAX = 20;      // waiting posts listed (the Moderation page has them all)
const UBELL_CODE_RE = '/^(?:[uk](?:models|outfits|scenes):[a-z0-9-]{1,100}|g[a-z0-9-]{1,100}|n(?:outfits|scenes):[a-z0-9-]{1,100}|b\d{1,9}|c\d{1,9}|r[0-9a-f]{8}|x\d{1,9}|y\d{1,9}(?:-\d{1,10})?|mod|q(?:comment|bug|idea)[0-9:]{1,30})$/D';

/** This event shows in the visitors' bell: the feature it belongs to is on (bug reports, comments, patron renders). */
function m3d_ubell_event(string $ev): bool
{
    return match ($ev) { 'bugs' => m3d_bugs_on(), 'replies' => m3d_comments_on(), 'renders' => m3d_showcase_on(), 'reactions' => m3d_reactions_on(), 'modq' => m3d_mod_role() === 'mod' && m3d_mod_kinds(), default => true };
}

/** This person moderates (their bell gets the User and Moderator tabs): a moderator's own login, not a preview. */
function m3d_ubell_is_mod(array $p): bool
{
    return empty($p['preview']) && m3d_mod_role() === 'mod';
}

/** The visitors' bell is in this visitor's header: logged in, not the creator (who has their own; previewing as a patron = this one). */
function m3d_ubell_for(?array $p): bool
{
    if (!$p || (string) ($p['id'] ?? '') === '') return false;
    return !m3d_is_admin($GLOBALS['M3D']) || !empty($p['preview']);
}

function m3d_ubell_file(string $id): string
{
    return M3D_PRIVATE . '/data/bell/' . m3d_like_code($id) . '.json';
}

/** A person's file as an array ([] when none or damaged). Read once a page ($fresh: again, m3d_ubell_store() does it under the lock). */
function m3d_ubell_read(string $id, bool $fresh = false): array
{
    static $cache = [];
    if ($id === '') return [];
    if (!$fresh && isset($cache[$id])) return $cache[$id];
    $f = m3d_ubell_file($id);
    $r = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return $cache[$id] = is_array($r) ? $r : [];
}

/** This person's marks: ['upd' => [code => true], 'read' => [code => unix], 'gone' => [code => unix], 'off' => [event => true]]. */
function m3d_ubell_state(array $p): array
{
    $r = !empty($p['preview']) ? (array) ($_SESSION['preview']['bell'] ?? []) : m3d_ubell_read((string) $p['id']);
    $set = fn($k) => array_fill_keys(array_map('strval', (array) ($r[$k] ?? [])), true);
    $map = fn($k) => array_map('intval', is_array($r[$k] ?? null) ? $r[$k] : []);
    return ['upd' => $set('upd'), 'read' => $map('read'), 'gone' => $map('gone'), 'off' => array_intersect_key($set('off'), UBELL_EVENTS)];
}

/** Read or removed: the row's mark is at its time or later (a newer comment, status or render makes it unread again). */
function m3d_ubell_marked(array $st, string $code, int $at): array
{
    return [isset($st['read'][$code]) && $at <= $st['read'][$code], isset($st['gone'][$code]) && $at <= $st['gone'][$code]];
}

/** Locked change of this person's record: $fn(array $record) returns the new one. False when not saved. Previewing: in the preview. */
function m3d_ubell_store(array $p, callable $fn): bool
{
    $tidy = function (array $r): array {
        $old = time() - UBELL_DAYS * 86400;   // rows older than that aren't shown any more: their marks can go
        foreach (['read', 'gone'] as $k) {
            $r[$k] = array_filter(array_map('intval', is_array($r[$k] ?? null) ? $r[$k] : []), fn($t) => $t > $old);
            if (!$r[$k]) unset($r[$k]);
        }
        if (empty($r['upd'])) unset($r['upd']);
        unset($r['seen']);   // "the bell last opened", from before the rows had their own read marks
        return $r;
    };
    if (!empty($p['preview'])) {
        if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['preview'])) return false;
        $_SESSION['preview']['bell'] = $tidy($fn((array) ($_SESSION['preview']['bell'] ?? [])));
        return true;
    }
    $dir = M3D_PRIVATE . '/data/bell';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) return false;
    $lock = @fopen("$dir/.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return false;
    try {
        $r = $tidy($fn(m3d_ubell_read((string) $p['id'], true)));
        $ok = m3d_atomic_write(m3d_ubell_file((string) $p['id']), (string) json_encode($r, JSON_UNESCAPED_SLASHES));
        if (!$ok) m3d_problem('Data', 'bell/ could not be saved (disk full or not writable?)');
        m3d_ubell_read((string) $p['id'], true);   // the rest of the page sees the change
        return $ok;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** The replies to this person's comments in the comments' summary: [[reply id, at, kind, key]…], newest last. */
function m3d_ubell_reply_refs(array $p): array
{
    if (!m3d_ubell_event('replies')) return [];
    return (array) (m3d_comment_summary()['replies'][m3d_like_code((string) $p['id'])] ?? []);
}

/** This person's renders approved, from the renders' summary: [[render id, ok_at]…]. */
function m3d_ubell_render_refs(array $p): array
{
    if (!m3d_ubell_event('renders')) return [];
    return (array) (m3d_showcase_summary()['ok'][m3d_like_code((string) $p['id'])] ?? []);
}

/**
 * Reactions others gave to this person's posts, from the comments' and bug reports' summaries (only where reactions are on):
 * [[code, at, comment id | 0, kind, key] (comments: code "x<id>") | [code, at, 0, 'bugs', about] (bug reports: "y<n>", their comments "y<n>-<at>")…].
 */
function m3d_ubell_rx_refs(array $p): array
{
    if (!m3d_ubell_event('reactions')) return [];
    $code = m3d_like_code((string) $p['id']);
    $out = [];
    if (m3d_comments_on() && m3d_comment_settings()['reactions']) {
        foreach ((array) (m3d_comment_summary()['rx'][$code] ?? []) as [$id, $at, $kind, $key]) $out[] = ['x' . (int) $id, (int) $at, (int) $id, (string) $kind, (string) $key];
    }
    if (m3d_bugs_on() && m3d_bug_settings()['reactions']) {
        foreach ((array) (m3d_bug_summary()['rx'][$code] ?? []) as $r) $out[] = ['y' . $r[0], (int) $r[1], 0, 'bugs', (string) ($r[2] ?? '')];
    }
    return $out;
}

/** A reaction row's post is still one this visitor may open (as m3d_ubell_count() and the list both check it). */
function m3d_ubell_rx_open(array $r): bool
{
    if ($r[3] !== 'bugs') return (bool) m3d_ubell_comment_page($r[3], $r[4]);
    [$kind, $slug] = m3d_bug_about_parts($r[4]);
    return $slug === '' || (($it = m3d_item($kind, $slug)) && m3d_can_view($kind, $it));
}

/** An approved render and its item, while the item's page shows it to this visitor: [render, item] or null. */
function m3d_ubell_render_item(string $id): ?array
{
    if (!($r = m3d_render($id)) || !($it = m3d_item($r['kind'], $r['slug'])) || !m3d_showcase_here($r['kind'], $it)) return null;
    return [$r, $it];
}

/** The page of a comment, if this visitor may read it: [title, address] or null. */
function m3d_ubell_comment_page(string $kind, string $key): ?array
{
    [$title, $url, $page] = m3d_comment_page($kind, $key);
    if ($url === '' || !$page || !m3d_comments_open($kind, $page)) return null;
    if ($kind === 'posts' ? m3d_post_view($page) !== 'full' : !m3d_can_view($kind, $page)) return null;
    return [$title, $url];
}

/** The latest news time of each followed report with news: [n => unix]. */
function m3d_ubell_bug_news(array $p): array
{
    if (!m3d_ubell_event('bugs') || !m3d_bug_news_count($p)) return [];   // the usual case: bugs.json not read
    return array_map(fn($entries) => max(array_map(fn($x) => (int) ($x['at'] ?? 0), $entries)), array_filter(m3d_bug_following($p)));
}

/** The number on the bell: rows not read yet (not clicked, removed or opened). Small files only (see the top of this file). */
function m3d_ubell_count(?array $p): int
{
    if (!m3d_ubell_for($p)) return 0;
    $old = time() - UBELL_DAYS * 86400;
    $upd = m3d_ubell_upd_now($p);
    $related = m3d_ubell_event('related') ? m3d_bookmark_related($p) : [];
    $bugs = m3d_ubell_bug_news($p);
    $replies = array_filter(m3d_ubell_reply_refs($p), fn($r) => (int) $r[1] > $old);
    $renders = array_filter(m3d_ubell_render_refs($p), fn($r) => (int) $r[1] > $old);
    $reactions = array_filter(m3d_ubell_rx_refs($p), fn($r) => $r[1] > $old);
    $mod = empty($p['preview']) ? m3d_mod_bell_ref((string) $p['id']) : null;
    $modq = m3d_ubell_is_mod($p) && m3d_ubell_event('modq') ? m3d_mod_waiting_count() : 0;   // the small summaries only
    if (!$upd && !$related && !$bugs && !$replies && !$renders && !$reactions && !$mod && !$modq) return 0;   // the usual case
    $st = m3d_ubell_state($p);
    foreach (['updates' => 'upd', 'related' => 'related', 'bugs' => 'bugs', 'replies' => 'replies', 'renders' => 'renders', 'reactions' => 'reactions'] as $ev => $var) if (isset($st['off'][$ev])) $$var = [];   // switched off on their Settings page
    if (isset($st['off']['modq'])) $modq = 0;
    $new = fn(string $code, int $at) => !array_filter(m3d_ubell_marked($st, $code, $at));
    $n = count(array_filter($upd, fn($c) => !isset($st['upd'][$c])));
    foreach ($related as $r) if ($new($r['code'], $r['at'])) $n++;
    foreach ($bugs as $b => $at) if ($new('b' . $b, $at)) $n++;
    foreach ($replies as [$id, $at, $kind, $key]) if ($new('c' . $id, (int) $at) && m3d_ubell_comment_page((string) $kind, (string) $key)) $n++;
    foreach ($renders as [$id, $at]) if ($new('r' . $id, (int) $at) && m3d_ubell_render_item((string) $id)) $n++;   // as the list: not for an item gone or not shown now
    foreach ($reactions as $r) if ($new($r[0], $r[1]) && m3d_ubell_rx_open($r)) $n++;
    if ($mod && $new('mod', $mod[0])) $n++;
    return $n + $modq;
}

/** "Today", "Yesterday", "4 d", then the date: for a version's day (no time of day). */
function ubell_day(int $t): string
{
    $day = m3d_site_time($t, 'Y-m-d');
    if ($day === m3d_today()) return 'Today';
    if ($day === m3d_site_time(time() - 86400, 'Y-m-d')) return 'Yesterday';
    $d = time() - $t;
    return $d < 7 * 86400 ? max(2, (int) round($d / 86400)) . ' d' : m3d_site_time($t, 'j M');
}

/** A changelog line as plain text (no format marks), cut to $w characters. */
function ubell_plain(string $t, int $w = 140): string
{
    $t = preg_replace('/\s+/u', ' ', trim(html_entity_decode(strip_tags(md_inline($t)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '';
    return mb_strimwidth($t, 0, $w, '…');
}

/**
 * The rows of the list, unread first, then newest first: [code, group, at, new (not read yet), text html, sub, address, picture [src, hidden] | null,
 * badge (on the picture: up | ok) | '', when (the time column), update code ("kind:slug@version", bell.js tells the cards on the page) | ''].
 */
function m3d_ubell_rows(array $p): array
{
    $st = m3d_ubell_state($p);
    $on = fn(string $ev) => m3d_ubell_event($ev) && !isset($st['off'][$ev]);   // the feature is on, and the person didn't switch it off
    $old = time() - UBELL_DAYS * 86400;
    $read = fn(string $code, int $at) => m3d_ubell_marked($st, $code, $at)[0];
    $gone = fn(string $code, int $at) => m3d_ubell_marked($st, $code, $at)[1];
    $clip = fn(string $t, int $w = 120) => mb_strimwidth(preg_replace('/\s+/u', ' ', trim($t)) ?? '', 0, $w, '…');
    $rows = [];
    if ($on('updates')) {
        $last = m3d_last_downloads(m3d_patron_history($p));
        foreach (m3d_updates_shown($p) as $kind => $list) foreach ($list as $slug => $label) {
            $it = m3d_item($kind, (string) $slug);
            if (!$it) continue;
            [$t, $got] = $last["$kind:$slug"] ?? [0, ''];
            $newer = m3d_newer_versions($it, $got, $t);
            $at = m3d_changelog_ts((string) ($newer[0]['date'] ?? '')) ?? $t;
            $changes = array_values(array_filter(array_map(fn($c) => ubell_plain((string) $c, 80), (array) ($newer[0]['changes'] ?? [])), fn($c) => $c !== ''));
            $name = $kind === 'models' ? m3d_model_name($it) : (string) $it['name'];
            $text = '<b>' . e($name . ' ' . bug_version_words($label)) . '</b> is out' . (count($newer) > 1 ? ' (' . count($newer) . ' new versions since yours)' : '');
            $rows[] = ['u' . $kind . ':' . $slug, 'updates', $at, !isset($st['upd']["$kind:$slug@$label"]), $text,
                $changes ? $clip(implode(' · ', array_slice($changes, 0, 3))) : (m3d_item_type($kind, $it)),
                m3d_version_url($kind, $it, $label), card_pic($kind, $it), 'up', ubell_day($at), "$kind:$slug@$label"];
        }
        foreach (m3d_bookmark_news($p) as $key => [$label, $newer, $at]) {   // bookmarked, not downloaded
            $isNew = !isset($st['upd']["$key@$label"]);
            if (!$isNew && $at <= $old) continue;
            [$kind, $slug] = explode(':', $key, 2);
            $it = m3d_item($kind, $slug);
            $changes = array_values(array_filter(array_map(fn($c) => ubell_plain((string) $c, 80), (array) ($newer[0]['changes'] ?? [])), fn($c) => $c !== ''));
            $name = $kind === 'models' ? m3d_model_name($it) : (string) $it['name'];
            $text = '<b>' . e($name . ' ' . bug_version_words($label)) . '</b> is out' . (count($newer) > 1 ? ' (' . count($newer) . ' new versions since you bookmarked it)' : '');
            $rows[] = ['k' . $key, 'updates', $at, $isNew, $text, $clip('Bookmarked · ' . ($changes ? implode(' · ', array_slice($changes, 0, 3)) : m3d_item_type($kind, $it))),
                m3d_version_url($kind, $it, $label), card_pic($kind, $it), 'bm', ubell_day($at), ''];
        }
    }
    if ($on('related')) {
        $names = function (array $slugs): string {   // "Tifa", "Tifa and Aerith", "Tifa and 2 more"
            $n = array_map(fn($s) => ($m = m3d_item('models', $s)) ? m3d_model_name($m) : $s, $slugs);
            return '<b>' . e($n[0]) . '</b>' . (count($n) === 2 ? ' and <b>' . e($n[1]) . '</b>' : (count($n) > 2 ? ' and ' . (count($n) - 1) . ' more' : ''));
        };
        foreach (m3d_bookmark_related($p) as $r) {
            if ($gone($r['code'], $r['at'])) continue;
            if ($r['type'] === 'pics') {
                $m = m3d_item('models', $r['models'][0]);
                $text = ($r['n'] > 1 ? '<b>' . $r['n'] . ' new pictures</b>' : '<b>A new picture</b>') . ' of ' . $names($r['models']) . ' in the Gallery';
                $sub = trim((string) ($r['still']['caption'] ?? '')) ?: 'Gallery';
                $url = '/gallery/?c=' . rawurlencode($r['models'][0]);
                $pic = $r['clear'] ? [img_thumb((string) $r['still']['src']), false] : card_pic('models', $m);
            } else {
                $it = $r['item'];
                $for = $r['kind'] === 'outfits' && m3d_fits_all($it) ? 'all models' : $names($r['models']);
                $text = $r['kind'] === 'outfits'
                    ? 'New ' . e(mb_strtolower(m3d_accessory_type($it))) . ' for ' . $for . ': <b>' . e((string) $it['name']) . '</b>'
                    : 'New scene with ' . $for . ': <b>' . e((string) $it['name']) . '</b>';
                $sub = ubell_plain(md_first_text((string) ($it['description'] ?? ''))) ?: m3d_item_type($r['kind'], $it);
                $url = m3d_item_url($r['kind'], (string) $it['slug']);
                $pic = card_pic($r['kind'], $it);
            }
            $rows[] = [$r['code'], 'related', $r['at'], !$read($r['code'], $r['at']), $text, $clip($sub), $url, $pic, 'bm', ubell_day($r['at']), ''];
        }
    }
    if ($on('bugs')) {
        foreach (m3d_bug_following($p) as $n => $entries) {
            if (!$entries || !($b = m3d_bug((int) $n))) continue;
            $at = max(array_map(fn($x) => (int) ($x['at'] ?? 0), $entries));
            if ($gone('b' . (int) $n, $at)) continue;
            $title = '<b>#' . (int) $n . ' · ' . e((string) ($b['title'] ?? '')) . '</b>';
            $cms = array_values(array_filter($entries, fn($x) => ($x['t'] ?? '') === 'c'));
            $status = array_values(array_filter($entries, fn($x) => ($x['t'] ?? '') !== 'c'));
            if ($status) {   // a new status says the most: the newest one, the comments under it
                $x = end($status);
                if (($x['t'] ?? '') === 'ref' || ($x['status'] ?? '') === 'fixed') {
                    $fx = m3d_bug_fixed_in($b);
                    [$k, $s] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
                    $it = $s !== '' ? m3d_item($k, $s) : null;
                    $what = 'was fixed' . ($fx !== '' ? ' in <b>' . e(($it ? m3d_model_name($it) . ' ' : '') . bug_version_words($fx)) . '</b>' : '');
                } elseif (($x['status'] ?? '') === 'closed') {
                    $what = 'was closed' . (isset(BUG_REASONS[$x['reason'] ?? '']) ? ': ' . e(BUG_REASONS[$x['reason']]) : '');
                } else {
                    $what = 'is now <b>' . e((BUG_STATUSES[$x['status'] ?? ''] ?? BUG_STATUSES['new'])[0]) . '</b>';
                }
                $text = $title . ' ' . $what;
                $sub = $cms ? m3d_plural(count($cms), 'new comment') : 'A report you follow';
            } else {
                $names = array_values(array_unique(array_map(fn($y) => (string) ($y['name'] ?? '') ?: 'Someone', $cms)));
                $who = array_pop($names);
                $text = '<b>' . e($who) . '</b>' . ($names ? ' and ' . e(m3d_plural(count($names), 'other')) : '') . ' commented on ' . $title;
                $sub = '“' . ubell_plain((string) (end($cms)['text'] ?? ''), 120) . '”';
            }
            $rows[] = ['b' . (int) $n, 'bugs', $at, !$read('b' . (int) $n, $at), $text, $sub, '/bugs/' . (int) $n . '/#new', null, '', notify_ago($at), ''];
        }
    }
    if ($on('replies') && $refs = array_filter(m3d_ubell_reply_refs($p), fn($r) => (int) $r[1] > $old && !$gone('c' . $r[0], (int) $r[1]))) {
        foreach ($refs as [$id, $at, $kind, $key]) {
            if (!($page = m3d_ubell_comment_page((string) $kind, (string) $key)) || !($f = m3d_comment_find((int) $id))) continue;
            $c = $f[3];
            if (!empty($c['pending']) || !empty($c['hid'])) continue;
            $who = (string) ($c['name'] ?? '') ?: 'Someone';
            $rows[] = ['c' . (int) $id, 'replies', (int) $at, !$read('c' . (int) $id, (int) $at), '<b>' . e($who) . '</b> replied to your comment on <b>' . e($page[0]) . '</b>',
                '“' . ubell_plain((string) ($c['text'] ?? ''), 120) . '”', $page[1] . '#cm-' . (int) $id, null, '', notify_ago((int) $at), ''];
        }
    }
    if ($on('renders') && $refs = array_filter(m3d_ubell_render_refs($p), fn($r) => (int) $r[1] > $old && !$gone('r' . $r[0], (int) $r[1]))) {
        $inGallery = array_flip(m3d_render_stills());
        foreach ($refs as [$id, $at]) {
            if (!($ri = m3d_ubell_render_item((string) $id))) continue;
            [$r, $it] = $ri;
            $name = $r['kind'] === 'models' ? m3d_model_name($it) : (string) $it['name'];
            $rows[] = ['r' . $id, 'renders', (int) $at, !$read('r' . $id, (int) $at), 'Your render of <b>' . e($name) . '</b> was ' . (empty($r['added']) ? 'approved' : 'added'),   // added = the creator put it up for them (+ Add Render)
                isset($inGallery[$id]) ? 'It’s in the Gallery too' : 'It’s on the ' . $name . ' page now', m3d_item_url($r['kind'], $r['slug']) . '#r-' . $id,
                [img_thumb((string) $r['src']), false], 'ok', notify_ago((int) $at), ''];
        }
    }
    if ($on('reactions') && $refs = array_filter(m3d_ubell_rx_refs($p), fn($r) => $r[1] > $old && !$gone($r[0], $r[1]))) {
        $me = m3d_like_code((string) $p['id']);
        $sum = fn(array $rx) => implode(' · ', array_map(fn($e, $n) => "$e $n", array_keys($o = m3d_rx_others($rx, $me)), $o));   // "❤️ 2 · 👍 1": how many, never who
        foreach ($refs as $r) {
            [$code, $at] = $r;
            if (!m3d_ubell_rx_open($r)) continue;
            if ($r[3] !== 'bugs') {
                if (!($page = m3d_ubell_comment_page($r[3], $r[4])) || !($f = m3d_comment_find($r[2]))) continue;
                $post = $f[3];
                if (!empty($post['pending']) || !empty($post['hid'])) continue;
                $where = 'your comment on <b>' . e($page[0]) . '</b>';
                $url = $page[1] . '#cm-' . $r[2];
            } else {
                [$bn, $cat] = array_map('intval', explode('-', substr($code, 1)) + [1 => -1]);
                if (!($b = m3d_bug($bn)) || !empty($b['hidden']) || !empty($b['pending'])) continue;
                $title = '<b>#' . $bn . ' · ' . e((string) ($b['title'] ?? '')) . '</b>';
                if ($cat < 0) { $post = $b; $where = 'your bug report ' . $title; $url = "/bugs/$bn/#report"; }
                else {
                    $i = null;
                    foreach ((array) ($b['log'] ?? []) as $j => $x) if (($x['t'] ?? '') === 'c' && (int) ($x['at'] ?? 0) === $cat) { $i = $j; break; }
                    if ($i === null || !empty(($post = $b['log'][$i])['hid'])) continue;
                    $where = 'your comment on ' . $title;
                    $url = "/bugs/$bn/#c$i";
                }
            }
            if (($s = $sum((array) ($post['rx'] ?? []))) === '') continue;   // taken back since
            $rows[] = [$code, 'reactions', $at, !$read($code, $at), '<b>' . e($s) . '</b> on ' . $where,
                '“' . ubell_plain((string) ($post['text'] ?? $post['body'] ?? ''), 120) . '”', $url, null, '', notify_ago($at), ''];
        }
    }
    if (empty($p['preview']) && ($mod = m3d_mod_bell_ref((string) $p['id'])) && !$gone('mod', $mod[0])) {   // the moderator role: always shown (no switch on the Settings page)
        [$at, $isOn] = $mod;   // not $on: that's the event check above, still used for modq below
        $rows[] = $isOn
            ? ['mod', 'mod', $at, !$read('mod', $at), 'You’re now a <b>moderator</b> of ' . e(site_name()), 'Open the Moderation page to start', '/admin/moderation.php', null, '', notify_ago($at), '']
            : ['mod', 'mod', $at, !$read('mod', $at), 'You’re no longer a <b>moderator</b> of ' . e(site_name()), 'Thank you for your help', '/account/', null, '', notify_ago($at), ''];
    }
    usort($rows, fn($a, $b) => [$b[3], $b[2]] <=> [$a[3], $a[2]]);   // the new ones first, then by time
    $rows = array_slice($rows, 0, UBELL_MAX);
    if (m3d_ubell_is_mod($p) && $on('modq')) {   // the posts waiting for their check, after the rest (the Moderator tab): always new
        $words = ['comment' => 'commented on', 'bug' => 'reported a bug about', 'idea' => 'suggested an idea for'];
        $icons = ['comment' => 'comments', 'bug' => 'bugs', 'idea' => 'ideas'];
        foreach (array_slice(m3d_mod_list('waiting'), 0, UBELL_MODQ_MAX) as $t) {
            $quote = $t['title'] !== '' ? $t['title'] : $t['text'];
            $rows[] = ['q' . $t['type'] . $t['ref'], 'modq', $t['at'], true, '<b>' . e($t['name'] !== '' ? $t['name'] : 'Someone') . '</b> ' . ($words[$t['type']] ?? 'wrote on') . ' <b>' . e($t['page']) . '</b>',
                '“' . ubell_plain($quote, 120) . '”', '/admin/moderation.php#mq-' . $t['type'] . '-' . str_replace(':', '-', $t['ref']), null, $icons[$t['type']] ?? 'mod', notify_ago($t['at']), ''];
        }
    }
    return $rows;
}

/** The bell in the header of a logged-in visitor: the number, and an empty panel that bell.js fills from /auth/bell.php. */
function ubell_html(array $p): string
{
    $n = m3d_ubell_count($p);
    return '<div class="nt nt-user" data-bell data-src="/auth/bell.php" data-csrf="' . e(m3d_csrf()) . '">'
        . '<button type="button" class="hsearch-btn nt-bell" title="Notifications" aria-label="Notifications' . ($n ? ' (' . $n . ')' : '') . '" aria-expanded="false" aria-controls="nt-panel" data-bell-btn>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">' . NOTIFY_ICONS['bell'] . '</svg>'
        . '<span class="nt-count" data-bell-count' . ($n ? '' : ' hidden') . '>' . ($n > 99 ? '99+' : $n) . '</span></button>'
        . '<div class="nt-panel" id="nt-panel" role="dialog" aria-label="Notifications" data-bell-panel hidden></div>'
        . '<script src="' . e(asset('bell.js')) . '" defer></script></div>';
}

/**
 * The panel's content (rows from m3d_ubell_rows()): New (not read yet), Earlier, tabs when two kinds or more show. A moderator's panel has two
 * tabs on top, User and Moderator (posts waiting for their check, and their role's row), the one with something new open first.
 */
function ubell_list_html(array $p, array $rows): string
{
    $isMod = m3d_ubell_is_mod($p);
    $modRows = $isMod ? array_values(array_filter($rows, fn($r) => $r[1] === 'modq' || $r[1] === 'mod')) : [];
    $userRows = $isMod ? array_values(array_filter($rows, fn($r) => $r[1] !== 'modq' && $r[1] !== 'mod')) : $rows;
    $clear = (bool) array_filter($rows, fn($r) => $r[1] !== 'modq');
    $h = '<div class="nt-head"><h2>Notifications</h2>' . ($clear ? '<button type="button" class="nt-link" data-nt-clear>Clear All</button>' : '') . '</div>';
    if (!$isMod) return $h . ubell_rows_html($rows, true, 'Nothing new.') . ubell_foot();
    $uNew = count(array_filter($userRows, fn($r) => $r[3]));
    $waiting = m3d_ubell_event('modq') && !isset(m3d_ubell_state($p)['off']['modq']) ? m3d_mod_waiting_count() : 0;   // every one, also past UBELL_MODQ_MAX
    $mNew = $waiting + count(array_filter($modRows, fn($r) => $r[1] === 'mod' && $r[3]));
    $first = !$uNew && $mNew ? 'mod' : 'user';
    $tab = fn(string $k, string $label, int $n) => '<button type="button" role="tab" aria-selected="' . ($k === $first ? 'true' : 'false') . '" data-nt-pane-btn="' . $k . '">' . $label . ($n ? '<span>' . $n . '</span>' : '') . '</button>';
    $h .= '<div class="nt-panes" role="tablist" aria-label="Notifications for">' . $tab('user', 'User', $uNew) . $tab('mod', 'Moderator', $mNew) . '</div>';
    $more = $waiting > count(array_filter($modRows, fn($r) => $r[1] === 'modq')) ? '<a class="nt-more" href="/admin/moderation.php">See All ' . $waiting . ' on the Moderation Page →</a>' : '';
    return $h . '<div data-nt-pane="user"' . ($first === 'user' ? '' : ' hidden') . '>' . ubell_rows_html($userRows, true, 'Nothing new.') . '</div>'
        . '<div data-nt-pane="mod"' . ($first === 'mod' ? '' : ' hidden') . '>' . ubell_rows_html($modRows, false, 'Nothing waiting for a check.') . $more . '</div>' . ubell_foot();
}

/** Rows as the New / Earlier lists, with the kind tabs over them ($tabs, when two kinds or more show), or $empty when there are none. */
function ubell_rows_html(array $rows, bool $tabs, string $empty): string
{
    $groups = ['updates' => ['Updates', 0, 0], 'related' => ['Bookmarks', 0, 0], 'bugs' => ['Bug Reports', 0, 0], 'replies' => ['Comments', 0, 0], 'renders' => ['Renders', 0, 0], 'reactions' => ['Reactions', 0, 0], 'mod' => ['Moderation', 0, 0], 'modq' => ['Waiting', 0, 0]];
    foreach ($rows as $r) { $groups[$r[1]][1]++; if ($r[3]) $groups[$r[1]][2]++; }
    $shown = array_filter($groups, fn($g) => $g[1] > 0);
    $unread = count(array_filter($rows, fn($r) => $r[3]));
    $h = '';
    if ($tabs && count($shown) > 1) {
        $h .= '<div class="nt-tabs" role="group" aria-label="Show"><button type="button" class="chip" aria-pressed="true" data-nt-tab="all">All' . ($unread ? '<span>' . $unread . '</span>' : '') . '</button>';
        foreach ($shown as $k => [$label, , $u]) $h .= '<button type="button" class="chip" aria-pressed="false" data-nt-tab="' . $k . '">' . $label . ($u ? '<span>' . $u . '</span>' : '') . '</button>';
        $h .= '</div>';
    }
    if (!$rows) return $h . '<p class="nt-empty">' . notify_icon('bell') . e($empty) . '</p>';
    foreach (['New' => true, 'Earlier' => false] as $title => $new) {
        $part = array_filter($rows, fn($r) => $r[3] === $new);
        if (!$part) continue;
        $h .= '<div data-nt-g="' . e(implode(' ', array_unique(array_column($part, 1)))) . '"><p class="nt-sec">' . $title . '</p><ul class="nt-list">';
        foreach ($part as [$code, $g, $at, $isNew, $text, $sub, $url, $pic, $badge, $when, $upd]) {
            $keep = $g === 'modq';   // waiting for a check: no ✕, never read by a click
            $ico = $pic && $pic[0] !== ''
                ? '<span class="nt-pic"><img src="' . e($pic[0]) . '" alt="" loading="lazy" decoding="async"' . ($pic[1] ? ' class="is-blurred"' : '') . ' />'
                  . ($badge !== '' ? '<span class="nt-badge ' . $badge . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . (['up' => 'M12 19V5M5.5 11.5 12 5l6.5 6.5', 'bm' => BOOKMARK_PATH][$badge] ?? 'M5 12.5l4.5 4.5L19 7.5') . '"/></svg></span>' : '') . '</span>'
                : ($keep ? notify_icon($badge, 'nt-' . $badge) : notify_icon(['updates' => 'up', 'replies' => 'comments', 'reactions' => 'react'][$g] ?? $g, 'nt-' . (['updates' => 'upd', 'replies' => 'comments', 'reactions' => 'react'][$g] ?? $g)));
            $h .= '<li class="nt-row' . ($isNew ? ' is-unread' : '') . ($keep ? ' nt-keep' : '') . '" data-nt-g="' . $g . '" data-nt-code="' . e($code) . '" data-nt-at="' . $at . '"' . ($upd !== '' ? ' data-upd="' . e($upd) . '"' : '') . '>'
                . '<a class="nt-hit" href="' . e($url) . '">' . $ico . '<span class="nt-main"><span class="nt-t">' . $text . ($keep ? ' <span class="nt-wait">Waiting</span>' : '') . ($isNew ? '<span class="sr-only"> (new)</span>' : '') . '</span>'
                . ($sub !== '' ? '<span class="nt-s">' . e($sub) . '</span>' : '') . '</span>'
                . '<time class="nt-when" datetime="' . e(gmdate('c', $at)) . '">' . e($when) . '</time></a>'
                . ($keep ? '' : '<span class="nt-tools"><button type="button" class="nt-ib" title="Remove" aria-label="Remove from the list" data-nt-remove><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button></span>')
                . '</li>';
        }
        $h .= '</ul></div>';
    }
    return $h;
}

function ubell_foot(): string
{
    return '<div class="nt-foot"><a href="/account/settings/#bell">Settings</a>' . (m3d_bugs_on() ? '<a href="/bugs/?status=following">Bug Reports →</a>' : '') . '</div>';
}

/** The update code ("kind:slug@version") shown now for an item of this person, '' when none. */
function m3d_ubell_upd_code(array $p, string $kind, string $slug): string
{
    $label = m3d_ubell_event('updates') ? (m3d_updates_shown($p)[$kind][$slug] ?? null) : null;
    return $label === null ? '' : "$kind:$slug@$label";
}

/** The update codes ("kind:slug@version") of the bell's update rows now: downloads and bookmarks ([] while the person switched them off). */
function m3d_ubell_upd_now(array $p): array
{
    if (!m3d_ubell_event('updates')) return [];
    $codes = m3d_update_codes(m3d_updates_shown($p));
    foreach (m3d_bookmark_news($p) as $key => [$label]) $codes[] = "$key@$label";
    return $codes;
}

/** Marks in this person's record: $marks = [[list (upd | read | gone), code, at]…]; `upd` keeps only the updates shown now. */
function m3d_ubell_mark(array $p, array $marks): bool
{
    if (!$marks) return true;
    $now = array_flip(m3d_ubell_upd_now($p));
    return m3d_ubell_store($p, function (array $r) use ($marks, $now) {
        foreach ($marks as [$k, $code, $at]) {
            if ($k === 'upd') {
                $r[$k] = array_values(array_unique(array_filter([...array_map('strval', (array) ($r[$k] ?? [])), $code], fn($c) => isset($now[$c]))));
            } else {
                $r[$k] = is_array($r[$k] ?? null) ? $r[$k] : [];
                $r[$k][$code] = max(max(0, min($at, time())), (int) ($r[$k][$code] ?? 0));   // the row's time, from the page: never later than now
            }
        }
        return $r;
    });
}

/** A row clicked: read (an update until a newer version, the rest until something newer happens on it). */
function m3d_ubell_read_row(array $p, string $code, int $at): bool
{
    if ($code[0] === 'q') return true;   // a post waiting for a check stays until it is checked
    if (preg_match('/^u(models|outfits|scenes):(.+)$/D', $code, $m)) return m3d_ubell_mark($p, ($c = m3d_ubell_upd_code($p, $m[1], $m[2])) !== '' ? [['upd', $c, 0]] : []);
    if ($code[0] === 'k') return m3d_ubell_mark($p, ($b = m3d_bookmark_news($p)[substr($code, 1)] ?? null) ? [['upd', substr($code, 1) . '@' . $b[0], 0]] : []);
    return m3d_ubell_mark($p, [['read', $code, $at]]);
}

/**
 * A row's ✕: an update is ignored, a report marked opened, a reply or render left out. Previewing: only in the preview (the update
 * ignored there, the report removed like a reply), since the real ones belong to a patron, not to the creator.
 */
function m3d_ubell_remove(array $p, string $code, int $at): bool
{
    $pv = !empty($p['preview']);
    if ($code[0] === 'q') return true;
    if (preg_match('/^u(models|outfits|scenes):(.+)$/D', $code, $m)) return m3d_ignore_update($p, $m[1], $m[2], true) !== null;   // false = no update any more: nothing to do
    if ($code[0] === 'k') return m3d_bookmark_seen($p, [substr($code, 1)]);
    if (!$pv && preg_match('/^b(\d+)$/D', $code, $m)) return m3d_ubell_bugs_seen($p, [(int) $m[1]]);
    return m3d_ubell_mark($p, [['gone', $code, $at]]);
}

/** Followed reports marked as opened now (their news read). */
function m3d_ubell_bugs_seen(array $p, array $ns): bool
{
    if (!$ns || !m3d_bug_can_follow($p)) return true;
    $code = m3d_like_code((string) $p['id']);
    $now = time();
    return (bool) m3d_bugs_update(function (array $bugs, int $next) use ($ns, $code, $now) {
        foreach ($bugs as &$b) {
            if (!in_array((int) ($b['n'] ?? 0), $ns, true)) continue;
            $b['fol'] = m3d_bug_followers($b);
            if (isset($b['fol'][$code])) $b['fol'][$code] = max($b['fol'][$code], $now);
        }
        unset($b);
        return [$bugs, $next, true];
    });
}

/** Clear All: every row of the list goes (updates ignored, reports opened, replies, renders and reactions left out; previewing: as m3d_ubell_remove()). */
function m3d_ubell_clear(array $p): bool
{
    $pv = !empty($p['preview']);
    $ok = true;
    $bugs = $marks = $seen = [];
    foreach (m3d_ubell_rows($p) as $r) {
        if ($r[1] === 'modq') continue;   // waiting posts stay until checked
        if ($r[1] === 'updates' && $r[0][0] === 'k') $seen[] = substr($r[0], 1);
        elseif ($r[1] === 'updates') {
            [$k, $s] = explode(':', substr($r[0], 1), 2);
            $ok = m3d_ignore_update($p, $k, $s, true) !== null && $ok;
        } elseif ($r[1] === 'bugs' && !$pv) $bugs[] = (int) substr($r[0], 1);
        else $marks[] = ['gone', $r[0], $r[2]];
    }
    $ok = m3d_ubell_bugs_seen($p, $bugs) && $ok;
    $ok = m3d_bookmark_seen($p, $seen) && $ok;
    return m3d_ubell_mark($p, $marks) && $ok;
}

// ── Bookmarks (the ribbon next to the like heart on model, accessory and scene pages) ──
// An item someone downloaded is bookmarked by itself (Pi): its updates come through m3d_updates_shown(). Removing that bookmark puts it in
// `nobm`: no update for it shows any more (bell, catalog cards; My account still lists it), until it is bookmarked again. Any other item is bookmarked in `bm` with
// the version out then: each version out after it is a bell row (m3d_bookmark_news()), read like an update; its ✕ moves the bookmark there.

const BOOKMARK_MAX = 300;   // bookmarks of items not downloaded, and downloaded items un-bookmarked, each
const BOOKMARK_KEY_RE = '/^(?:models|outfits|scenes):[a-z0-9-]{1,100}$/D';
const BOOKMARK_PATH = 'M6.5 3.5h11v17l-5.5-4-5.5 4z';
/** The bookmark ribbon (bookmark_button(), the cards' bookmark, the Bookmarked chips, My Account's titles). */
const BOOKMARK_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . BOOKMARK_PATH . '"/></svg>';

/** Who can bookmark: anyone logged in. The creator too (Pi), for My Account and the Bookmarked filters only: no visitors' bell, so no rows (m3d_ubell_for()). */
function m3d_bookmark_can(?array $p): bool
{
    return $p && (string) ($p['id'] ?? '') !== '';
}

/** This person's file (or the preview's), for the bookmarks. */
function m3d_bookmark_record(array $p): array
{
    return !empty($p['preview']) ? (array) ($_SESSION['preview']['bell'] ?? []) : m3d_ubell_read((string) $p['id']);
}

/** Items bookmarked without a download: ["kind:slug" => [unix, version label out then | '' | '~' (nothing to download then)]]. */
function m3d_bookmarks(?array $p): array
{
    if (!m3d_bookmark_can($p)) return [];
    $out = [];
    $bm = m3d_bookmark_record($p)['bm'] ?? null;
    foreach (is_array($bm) ? $bm : [] as $k => $v) {
        if (is_string($k) && preg_match(BOOKMARK_KEY_RE, $k) && is_array($v)) $out[$k] = [(int) ($v[0] ?? 0), (string) ($v[1] ?? '')];
    }
    return $out;
}

/** Downloaded items whose bookmark this person removed: ["kind:slug" => true]. */
function m3d_bookmarks_off(?array $p): array
{
    if (!m3d_bookmark_can($p)) return [];
    $off = m3d_bookmark_record($p)['nobm'] ?? null;
    return array_fill_keys(array_filter(is_array($off) ? $off : [], fn($k) => is_string($k) && preg_match(BOOKMARK_KEY_RE, $k)), true);
}

/** Every item this person has bookmarked: ["kind:slug" => true] (the downloaded ones, less those un-bookmarked, and the others). */
function m3d_bookmarked(?array $p): array
{
    if (!m3d_bookmark_can($p)) return [];
    $out = array_fill_keys(array_keys(m3d_bookmarks($p)), true);
    foreach (m3d_patron_history($p) as [, $k, $s]) if (!str_starts_with((string) $s, '~preview')) $out["$k:$s"] = true;
    return array_diff_key($out, m3d_bookmarks_off($p));
}

/** There is something to download from this item now (a version and its links): what a bookmark waits for. */
function m3d_bookmark_ready(string $kind, array $it): bool
{
    return m3d_is_open($it) && !($kind === 'models' && m3d_status($it) === 'in-development') && m3d_released_versions($it);
}

/**
 * The versions out since each bookmark: ["kind:slug" => [newest version label, newer versions (newest first), when]]. Not for an item they
 * downloaded while their downloads' updates show (they get that row instead), nor one they can't open.
 */
function m3d_bookmark_news(array $p): array
{
    if (!($bm = m3d_bookmarks($p))) return [];
    $dl = !empty($p['access']) && !m3d_banned($p, 'download') ? m3d_last_downloads(m3d_patron_history($p)) : [];
    $out = [];
    foreach ($bm as $key => [$t, $label]) {
        if (isset($dl[$key])) continue;
        [$k, $s] = explode(':', $key, 2);
        $it = m3d_item($k, $s);
        if (!$it || !m3d_can_view($k, $it) || !m3d_bookmark_ready($k, $it)) continue;
        $newer = $label === '~' ? array_slice(m3d_released_versions($it), 0, 1) : m3d_newer_versions($it, $label, $t);   // '~': out at last
        if (!$newer) continue;
        $out[$key] = [m3d_version_label($newer[0]), $newer, max($t, m3d_changelog_ts((string) ($newer[0]['date'] ?? '')) ?? $t)];
    }
    return $out;
}

/**
 * What came out for the models this person bookmarked (downloaded ones too), within UBELL_DAYS and since the bookmark (a downloaded one: since
 * its first download): new Gallery pictures they can see, one entry per model ['code' => "g<slug>", 'type' => 'pics', 'at', 'models' => [slug],
 * 'n', 'still' (the newest), 'clear'], and accessories / scenes first out since then that they haven't bookmarked or downloaded
 * ['code' => "n<kind>:<slug>", 'type' => 'item', 'kind', 'item', 'at', 'models' => [the bookmarked ones it is for]]. Newest first.
 */
function m3d_bookmark_related(array $p): array
{
    $bm = m3d_bookmarked($p);
    if (!$bm) return [];
    $old = time() - UBELL_DAYS * 86400;
    $first = [];   // "kind:slug" => first download
    foreach (m3d_patron_history($p) as [$t, $k, $s]) $first["$k:$s"] = min($t, $first["$k:$s"] ?? PHP_INT_MAX);
    $mine = m3d_bookmarks($p);
    $since = [];   // model slug => from when (the start of that day: Gallery pictures carry a day)
    foreach ($bm as $key => $_) if (str_starts_with($key, 'models:')) {
        if (!($m = m3d_item('models', substr($key, 7))) || !m3d_can_view('models', $m)) continue;   // gone, a draft or locked: never named
        $t = max($old, (int) ($mine[$key][0] ?? $first[$key] ?? time()));
        $since[substr($key, 7)] = m3d_changelog_ts(m3d_site_time($t, 'Y-m-d')) ?? $t;
    }
    if (!$since) return [];
    $out = $pics = [];
    foreach (m3d_gallery()['stills'] as $st) {   // newest on top
        $ts = m3d_changelog_ts((string) ($st['date'] ?? ''));
        if ($ts === null || $ts < min($since)) continue;
        $view = null;
        foreach (array_unique(array_map('strval', (array) ($st['chars'] ?? []))) as $c) {
            if (!isset($since[$c]) || $ts < $since[$c]) continue;
            if (($view ??= m3d_still_view($st)) === 'hidden') break;
            if (!isset($pics[$c])) $pics[$c] = ['code' => 'g' . $c, 'type' => 'pics', 'at' => $ts, 'models' => [$c], 'n' => 0, 'still' => $st, 'clear' => $view === 'clear'];
            elseif ($ts > $pics[$c]['at']) $pics[$c] = ['at' => $ts, 'still' => $st, 'clear' => $view === 'clear'] + $pics[$c];
            $pics[$c]['n']++;
        }
    }
    $out = array_values($pics);
    foreach (['outfits', 'scenes'] as $kind) foreach (m3d_listed_items($kind) as $it) {
        $key = $kind . ':' . $it['slug'];
        if (isset($bm[$key]) || isset($first[$key]) || !m3d_can_view($kind, $it) || ($at = m3d_first_out_ts($it)) < min($since)) continue;
        $for = $kind === 'outfits' && m3d_fits_all($it) ? array_keys($since) : array_values(array_intersect(array_map('strval', (array) ($it['models'] ?? [])), array_keys($since)));
        $for = array_values(array_filter($for, fn($s) => $at >= $since[$s]));
        if ($for) $out[] = ['code' => 'n' . $key, 'type' => 'item', 'kind' => $kind, 'item' => $it, 'at' => $at, 'models' => $for];
    }
    usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

/** Bookmarks an item ($on) or removes the bookmark. A downloaded one is bookmarked by itself: removing it goes in `nobm`. False when not saved. */
function m3d_bookmark_set(array $p, string $kind, string $slug, bool $on): bool
{
    $key = "$kind:$slug";
    if (!preg_match(BOOKMARK_KEY_RE, $key) || !($it = m3d_item($kind, $slug))) return false;
    $got = isset(m3d_last_downloads(m3d_patron_history($p))[$key]);
    $label = m3d_bookmark_ready($kind, $it) ? m3d_released_label($it) : '~';
    return m3d_ubell_store($p, function (array $r) use ($key, $on, $got, $label) {
        $bm = is_array($r['bm'] ?? null) ? $r['bm'] : [];
        $off = array_values(array_diff(array_filter(is_array($r['nobm'] ?? null) ? $r['nobm'] : [], 'is_string'), [$key]));
        unset($bm[$key]);
        if ($on && !$got) $bm[$key] = [time(), $label];
        if (!$on && $got) $off[] = $key;
        $bm = array_slice($bm, -BOOKMARK_MAX, null, true);
        $off = array_slice($off, -BOOKMARK_MAX);
        if ($bm) $r['bm'] = $bm; else unset($r['bm']);
        if ($off) $r['nobm'] = $off; else unset($r['nobm']);
        return $r;
    });
}

/** A bookmark row's ✕ (and Clear All): the bookmarks of these items ("kind:slug") move to the version out now. */
function m3d_bookmark_seen(array $p, array $keys): bool
{
    $now = [];
    foreach (array_intersect_key(m3d_bookmark_news($p), array_flip($keys)) as $key => [$label]) $now[$key] = [time(), $label];
    if (!$now) return true;
    return m3d_ubell_store($p, function (array $r) use ($now) {
        if (!is_array($r['bm'] ?? null)) return $r;
        foreach ($now as $key => $v) if (isset($r['bm'][$key])) $r['bm'][$key] = $v;
        return $r;
    });
}

// ── The person's Settings page (account-settings.php) ──
/** Saves which bell events this person switched off ($off: UBELL_EVENTS keys). */
function m3d_ubell_set_off(array $p, array $off): bool
{
    $off = array_values(array_intersect(array_keys(UBELL_EVENTS), $off));
    return m3d_ubell_store($p, function (array $r) use ($off) {
        if ($off) $r['off'] = $off; else unset($r['off']);
        return $r;
    });
}

/** What the Share window of patron renders starts with: ['named' => bool, 'link' => {t, h} | null] (Settings → Patron Renders). */
function m3d_render_prefs(?array $p): array
{
    $r = $p && empty($p['preview']) ? (array) (m3d_ubell_read((string) ($p['id'] ?? ''))['render'] ?? []) : [];
    $l = $r['link'] ?? null;
    $l = is_array($l) && is_string($l['t'] ?? null) && is_string($l['h'] ?? null) ? m3d_render_link_clean($l['t'], $l['h']) : null;   // checked again: the file is data
    return ['named' => (bool) ($r['named'] ?? true), 'link' => $l ?: null];
}

/** Saves the Share window's defaults; $link = m3d_render_link_clean()'s answer ([] = none). */
function m3d_render_prefs_save(array $p, bool $named, array $link): bool
{
    return m3d_ubell_store($p, function (array $r) use ($named, $link) {
        $r['render'] = ['named' => $named] + ($named && $link ? ['link' => $link] : []);
        if ($named && !$link) unset($r['render']);   // the usual start: nothing to keep
        return $r;
    });
}

/** A render link's text for a field: "emily", or the Patreon address for a numeric account. */
function m3d_render_link_text(?array $l): string
{
    if (!$l) return '';
    return str_starts_with($l['h'], 'u:') ? RENDER_LINKS['patreon'][1] . 'user?u=' . substr($l['h'], 2) : $l['h'];
}

// ── Privacy (privacy.php, Settings → Privacy → Personal Data) ──
/**
 * What data/bell/ holds about a person (null when nothing): how many notifications they read and removed, the bell events they
 * switched off (titles), their bookmarks and the downloaded items they un-bookmarked (names) and their Share window defaults (null when never set: ['named' => bool, 'link' => "X: @emily" | '']).
 */
function m3d_ubell_person(string $id): ?array
{
    if (!is_file(m3d_ubell_file($id))) return null;
    $r = m3d_ubell_read($id);
    $render = null;
    if (is_array($r['render'] ?? null)) {
        $l = m3d_render_link(['named' => true, 'name' => 'x', 'by' => '1', 'link' => $r['render']['link'] ?? null]);
        $render = ['named' => (bool) ($r['render']['named'] ?? true), 'link' => $l ? $l['label'] : ''];
    }
    $names = function (array $keys): array {   // "kind:slug" => "Tifa (model)", or the address of an item removed since
        $out = [];
        foreach ($keys as $k) if (is_string($k) && preg_match(BOOKMARK_KEY_RE, $k)) {
            [$kind, $slug] = explode(':', $k, 2);
            $out[] = ($it = m3d_item($kind, $slug)) ? (string) $it['name'] . ' (' . m3d_kind_word($kind) . ')' : $k;
        }
        return $out;
    };
    return ['read' => count((array) ($r['read'] ?? [])) + count((array) ($r['upd'] ?? [])), 'removed' => count((array) ($r['gone'] ?? [])),
        'bookmarks' => $names(array_keys(is_array($r['bm'] ?? null) ? $r['bm'] : [])), 'unbookmarked' => $names((array) ($r['nobm'] ?? [])),
        'off' => array_values(array_map(fn($k) => UBELL_EVENTS[$k], array_intersect(array_keys(UBELL_EVENTS), (array) ($r['off'] ?? [])))), 'render' => $render];
}

/** Personal Data → Delete: the person's file goes. */
function m3d_ubell_forget(string $id): bool
{
    $f = m3d_ubell_file($id);
    return !is_file($f) || @unlink($f);
}
