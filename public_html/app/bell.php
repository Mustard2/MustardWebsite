<?php
// The bell of logged-in visitors (patrons and free members; the creator has their own, app/notify.php): what happened to their own
// things. The same look and script as the creator's (notify_bell(), assets/bell.js), filled from /auth/bell.php.
//
// Rows (m3d_ubell_rows()), all worked out from what the site already stores:
//   updates  a newer version of an item they downloaded (m3d_updates_shown()): until downloaded; ✕ = "Ignore this update" (data/ignored.json)
//   bugs     a report they follow got a comment or a new status (m3d_bug_following()): until the report is opened; ✕ = marked opened
//   replies  someone replied to their comment (comments.json: `to`, else `re`, is one of theirs): UBELL_DAYS; ✕ = left out
//   renders  their render was approved, or added for them by the creator (showcase.json: ok_at; not while hidden): UBELL_DAYS; ✕ = left out
// data/bell/<code>.json, one small file per person (code = m3d_like_code(), no ids or names: privacy.php, Personal Data):
//   {upd ["kind:slug@version": updates read], read {"b<n>" | "c<id>" | "r<id>": unix}, gone {"c<id>" | "r<id>": unix}}. One file each, so a
//   page reads a few hundred bytes, never everyone's marks (measured: one shared file of 5000 people took ~8 ms to read on every page).
//   Read marks only: not in backups (losing them shows the number again, nothing else). Writes wait for data/bell/.lock.
// The number on the bell = unread rows: a row is read once clicked (bell.js posts do=read), removed with its ✕, or (a report) opened;
//   opening the bell changes nothing (Pi). Always on for anyone logged in (Pi): no setting; an event shows while its feature is on (m3d_ubell_event()).
// Previewing as a patron, the creator's marks go in the preview itself ($_SESSION['preview']['bell'], an update's ✕ in
//   $_SESSION['preview']['ignored'] by m3d_ignore_update()): nothing is written to files, and a new preview starts with none.
// Every page reads only small files for the number: the comments' and renders' summaries (m3d_data_summary()), and the person's own file
// only when they have a row at all.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const UBELL_DAYS = 30;          // replies and approved renders stay in the list this long
const UBELL_MAX = 40;           // rows in the list
const UBELL_CODE_RE = '/^(?:u(?:models|outfits):[a-z0-9-]{1,100}|b\d{1,9}|c\d{1,9}|r[0-9a-f]{8})$/D';

/** This event shows in the visitors' bell: the feature it belongs to is on (bug reports, comments, patron renders). */
function m3d_ubell_event(string $ev): bool
{
    return match ($ev) { 'bugs' => m3d_bugs_on(), 'replies' => m3d_comments_on(), 'renders' => m3d_showcase_on(), default => true };
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

/** A person's file as an array ([] when none or damaged). */
function m3d_ubell_read(string $id): array
{
    if ($id === '') return [];
    $f = m3d_ubell_file($id);
    $r = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
    return is_array($r) ? $r : [];
}

/** This person's marks: ['upd' => [code => true], 'read' => [code => unix], 'gone' => [code => unix]]. */
function m3d_ubell_state(array $p): array
{
    $r = !empty($p['preview']) ? (array) ($_SESSION['preview']['bell'] ?? []) : m3d_ubell_read((string) $p['id']);
    $set = fn($k) => array_fill_keys(array_map('strval', (array) ($r[$k] ?? [])), true);
    $map = fn($k) => array_map('intval', is_array($r[$k] ?? null) ? $r[$k] : []);
    return ['upd' => $set('upd'), 'read' => $map('read'), 'gone' => $map('gone')];
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
        $r = $tidy($fn(m3d_ubell_read((string) $p['id'])));
        $ok = m3d_atomic_write(m3d_ubell_file((string) $p['id']), (string) json_encode($r, JSON_UNESCAPED_SLASHES));
        if (!$ok) m3d_problem('Data', 'bell/ could not be saved (disk full or not writable?)');
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
    $upd = m3d_ubell_event('updates') ? m3d_update_codes(m3d_updates_shown($p)) : [];
    $bugs = m3d_ubell_bug_news($p);
    $replies = array_filter(m3d_ubell_reply_refs($p), fn($r) => (int) $r[1] > $old);
    $renders = array_filter(m3d_ubell_render_refs($p), fn($r) => (int) $r[1] > $old);
    if (!$upd && !$bugs && !$replies && !$renders) return 0;   // the usual case: the person's file not read
    $st = m3d_ubell_state($p);
    $new = fn(string $code, int $at) => !array_filter(m3d_ubell_marked($st, $code, $at));
    $n = count(array_filter($upd, fn($c) => !isset($st['upd'][$c])));
    foreach ($bugs as $b => $at) if ($new('b' . $b, $at)) $n++;
    foreach ($replies as [$id, $at, $kind, $key]) if ($new('c' . $id, (int) $at) && m3d_ubell_comment_page((string) $kind, (string) $key)) $n++;
    foreach ($renders as [$id, $at]) if ($new('r' . $id, (int) $at) && m3d_ubell_render_item((string) $id)) $n++;   // as the list: not for an item gone or not shown now
    return $n;
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
    $old = time() - UBELL_DAYS * 86400;
    $read = fn(string $code, int $at) => m3d_ubell_marked($st, $code, $at)[0];
    $gone = fn(string $code, int $at) => m3d_ubell_marked($st, $code, $at)[1];
    $clip = fn(string $t, int $w = 120) => mb_strimwidth(preg_replace('/\s+/u', ' ', trim($t)) ?? '', 0, $w, '…');
    $rows = [];
    if (m3d_ubell_event('updates')) {
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
                $changes ? $clip(implode(' · ', array_slice($changes, 0, 3))) : ($kind === 'outfits' ? m3d_accessory_type($it) : 'Model'),
                m3d_version_url($kind, $it, $label), card_pic($kind, $it), 'up', ubell_day($at), "$kind:$slug@$label"];
        }
    }
    if (m3d_ubell_event('bugs')) {
        foreach (m3d_bug_following($p) as $n => $entries) {
            if (!$entries || !($b = m3d_bug((int) $n))) continue;
            $at = max(array_map(fn($x) => (int) ($x['at'] ?? 0), $entries));
            if ($gone('b' . (int) $n, $at)) continue;
            $on = '<b>#' . (int) $n . ' · ' . e((string) ($b['title'] ?? '')) . '</b>';
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
                $text = $on . ' ' . $what;
                $sub = $cms ? m3d_plural(count($cms), 'new comment') : 'A report you follow';
            } else {
                $names = array_values(array_unique(array_map(fn($y) => (string) ($y['name'] ?? '') ?: 'Someone', $cms)));
                $who = array_pop($names);
                $text = '<b>' . e($who) . '</b>' . ($names ? ' and ' . e(m3d_plural(count($names), 'other')) : '') . ' commented on ' . $on;
                $sub = '“' . ubell_plain((string) (end($cms)['text'] ?? ''), 120) . '”';
            }
            $rows[] = ['b' . (int) $n, 'bugs', $at, !$read('b' . (int) $n, $at), $text, $sub, '/bugs/' . (int) $n . '/#new', null, '', notify_ago($at), ''];
        }
    }
    if ($refs = array_filter(m3d_ubell_reply_refs($p), fn($r) => (int) $r[1] > $old && !$gone('c' . $r[0], (int) $r[1]))) {
        foreach ($refs as [$id, $at, $kind, $key]) {
            if (!($page = m3d_ubell_comment_page((string) $kind, (string) $key)) || !($f = m3d_comment_find((int) $id))) continue;
            $c = $f[3];
            if (!empty($c['pending'])) continue;
            $who = (string) ($c['name'] ?? '') ?: 'Someone';
            $rows[] = ['c' . (int) $id, 'replies', (int) $at, !$read('c' . (int) $id, (int) $at), '<b>' . e($who) . '</b> replied to your comment on <b>' . e($page[0]) . '</b>',
                '“' . ubell_plain((string) ($c['text'] ?? ''), 120) . '”', $page[1] . '#cm-' . (int) $id, null, '', notify_ago((int) $at), ''];
        }
    }
    if ($refs = array_filter(m3d_ubell_render_refs($p), fn($r) => (int) $r[1] > $old && !$gone('r' . $r[0], (int) $r[1]))) {
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
    usort($rows, fn($a, $b) => [$b[3], $b[2]] <=> [$a[3], $a[2]]);   // the new ones first, then by time
    return array_slice($rows, 0, UBELL_MAX);
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

/** The panel's content (rows from m3d_ubell_rows()): New (not read yet), Earlier, tabs when two kinds or more show. */
function ubell_list_html(array $p, array $rows): string
{
    $groups = ['updates' => ['Updates', 0, 0], 'bugs' => ['Bug Reports', 0, 0], 'replies' => ['Comments', 0, 0], 'renders' => ['Renders', 0, 0]];
    foreach ($rows as $r) { $groups[$r[1]][1]++; if ($r[3]) $groups[$r[1]][2]++; }
    $shown = array_filter($groups, fn($g) => $g[1] > 0);
    $unread = count(array_filter($rows, fn($r) => $r[3]));
    $h = '<div class="nt-head"><h2>Notifications</h2>' . ($rows ? '<button type="button" class="nt-link" data-nt-clear>Clear All</button>' : '') . '</div>';
    if (count($shown) > 1) {
        $h .= '<div class="nt-tabs" role="group" aria-label="Show"><button type="button" class="chip" aria-pressed="true" data-nt-tab="all">All' . ($unread ? '<span>' . $unread . '</span>' : '') . '</button>';
        foreach ($shown as $k => [$label, , $u]) $h .= '<button type="button" class="chip" aria-pressed="false" data-nt-tab="' . $k . '">' . $label . ($u ? '<span>' . $u . '</span>' : '') . '</button>';
        $h .= '</div>';
    }
    if (!$rows) return $h . '<p class="nt-empty">' . notify_icon('bell') . 'Nothing new.</p>' . ubell_foot();
    foreach (['New' => true, 'Earlier' => false] as $title => $new) {
        $part = array_filter($rows, fn($r) => $r[3] === $new);
        if (!$part) continue;
        $h .= '<div data-nt-g="' . e(implode(' ', array_unique(array_column($part, 1)))) . '"><p class="nt-sec">' . $title . '</p><ul class="nt-list">';
        foreach ($part as [$code, $g, $at, $isNew, $text, $sub, $url, $pic, $badge, $when, $upd]) {
            $ico = $pic && $pic[0] !== ''
                ? '<span class="nt-pic"><img src="' . e($pic[0]) . '" alt="" loading="lazy" decoding="async"' . ($pic[1] ? ' class="is-blurred"' : '') . ' />'
                  . ($badge !== '' ? '<span class="nt-badge ' . $badge . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="' . ($badge === 'up' ? 'M12 19V5M5.5 11.5 12 5l6.5 6.5' : 'M5 12.5l4.5 4.5L19 7.5') . '"/></svg></span>' : '') . '</span>'
                : notify_icon(['updates' => 'up', 'replies' => 'comments'][$g] ?? $g, 'nt-' . (['updates' => 'upd', 'replies' => 'comments'][$g] ?? $g));
            $h .= '<li class="nt-row' . ($isNew ? ' is-unread' : '') . '" data-nt-g="' . $g . '" data-nt-code="' . e($code) . '" data-nt-at="' . $at . '"' . ($upd !== '' ? ' data-upd="' . e($upd) . '"' : '') . '>'
                . '<a class="nt-hit" href="' . e($url) . '">' . $ico . '<span class="nt-main"><span class="nt-t">' . $text . ($isNew ? '<span class="sr-only"> (new)</span>' : '') . '</span>'
                . ($sub !== '' ? '<span class="nt-s">' . e($sub) . '</span>' : '') . '</span>'
                . '<time class="nt-when" datetime="' . e(gmdate('c', $at)) . '">' . e($when) . '</time></a>'
                . '<span class="nt-tools"><button type="button" class="nt-ib" title="Remove" aria-label="Remove from the list" data-nt-remove><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/></svg></button></span>'
                . '</li>';
        }
        $h .= '</ul></div>';
    }
    return $h . ubell_foot();
}

function ubell_foot(): string
{
    return '<div class="nt-foot"><a href="/account/">My Account</a>' . (m3d_bugs_on() ? '<a href="/bugs/?status=following">Bug Reports →</a>' : '') . '</div>';
}

/** The update code ("kind:slug@version") shown now for an item of this person, '' when none. */
function m3d_ubell_upd_code(array $p, string $kind, string $slug): string
{
    $label = m3d_ubell_event('updates') ? (m3d_updates_shown($p)[$kind][$slug] ?? null) : null;
    return $label === null ? '' : "$kind:$slug@$label";
}

/** Marks in this person's record: $marks = [[list (upd | read | gone), code, at]…]; `upd` keeps only the updates shown now. */
function m3d_ubell_mark(array $p, array $marks): bool
{
    if (!$marks) return true;
    $now = m3d_ubell_event('updates') ? array_flip(m3d_update_codes(m3d_updates_shown($p))) : [];
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
    if (preg_match('/^u(models|outfits):(.+)$/D', $code, $m)) return m3d_ubell_mark($p, ($c = m3d_ubell_upd_code($p, $m[1], $m[2])) !== '' ? [['upd', $c, 0]] : []);
    return m3d_ubell_mark($p, [['read', $code, $at]]);
}

/**
 * A row's ✕: an update is ignored, a report marked opened, a reply or render left out. Previewing: only in the preview (the update
 * ignored there, the report removed like a reply), since the real ones belong to a patron, not to the creator.
 */
function m3d_ubell_remove(array $p, string $code, int $at): bool
{
    $pv = !empty($p['preview']);
    if (preg_match('/^u(models|outfits):(.+)$/D', $code, $m)) return m3d_ignore_update($p, $m[1], $m[2], true) !== null;   // false = no update any more: nothing to do
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

/** Clear All: every row of the list goes (updates ignored, reports opened, replies and renders left out; previewing: as m3d_ubell_remove()). */
function m3d_ubell_clear(array $p): bool
{
    $pv = !empty($p['preview']);
    $ok = true;
    $bugs = $marks = [];
    foreach (m3d_ubell_rows($p) as $r) {
        if ($r[1] === 'updates') {
            [$k, $s] = explode(':', substr($r[0], 1), 2);
            $ok = m3d_ignore_update($p, $k, $s, true) !== null && $ok;
        } elseif ($r[1] === 'bugs' && !$pv) $bugs[] = (int) substr($r[0], 1);
        else $marks[] = ['gone', $r[0], $r[2]];
    }
    $ok = m3d_ubell_bugs_seen($p, $bugs) && $ok;
    return m3d_ubell_mark($p, $marks) && $ok;
}

// ── Privacy (privacy.php, Settings → Privacy → Personal Data) ──
/** What data/bell/ holds about a person (null when nothing): how many notifications they read and removed. */
function m3d_ubell_person(string $id): ?array
{
    if (!is_file(m3d_ubell_file($id))) return null;
    $r = m3d_ubell_read($id);
    return ['read' => count((array) ($r['read'] ?? [])) + count((array) ($r['upd'] ?? [])), 'removed' => count((array) ($r['gone'] ?? []))];
}

/** Personal Data → Delete: the person's file goes. */
function m3d_ubell_forget(string $id): bool
{
    $f = m3d_ubell_file($id);
    return !is_file($f) || @unlink($f);
}
