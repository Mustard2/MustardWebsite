<?php
// GET JSON for the "Patreon ID or name" fields (assets/people.js; fields carry data-person-pick, printed with person_pick_js() in view.php):
// the people matching what was typed, best first, at most PEOPLE_MAX: {people: [{id, name, pick ("Name · ID", what the field posts), pic (their
// Patreon picture as a data: address, or ''), h (the letter circle's hue), tags [[label, class]], meta (one line: tier · months · last download,
// else what they wrote), off (why they can't be picked here, or '')}]}. ?one=1: only the person the text names exactly (a saved value shown again
// as a card), or nothing. ?ctx=mod (Add Moderator: moderators and admins greyed) | user (Access → Add User: admins greyed) | '' (anyone).
// Names come from m3d_known_patrons(), so only people the site already met; an ID nobody knows is offered as it is.
require __DIR__ . '/_admin.php';
admin_require($M3D, true);
m3d_session_release();

const PEOPLE_MAX = 8;

$q = trim(mb_substr(m3d_get_text('q'), 0, 80));
$ctx = m3d_get_text('ctx');
$one = m3d_get_text('one') === '1';
$known = m3d_known_patrons();
$owners = m3d_owner_ids($M3D);
$mods = array_column(m3d_mod_settings()['mods'], null, 'id');

// the candidates: [id => name]
$ids = [];
if ($q !== '') {
    if ($one) {
        $hits = m3d_find_patrons($q, 2);
        if (count($hits) === 1 && ($hits[0][1] !== '' || isset($known[$hits[0][0]]) || preg_match('/^\d{1,20}$/D', $q))) $ids[(string) $hits[0][0]] = (string) $hits[0][1];
    } elseif (preg_match('/^\d{1,20}$/D', $q)) {   // digits: the known IDs starting with them
        foreach ($known as $id => $name) if (str_starts_with((string) $id, $q)) $ids[(string) $id] = (string) $name;
    } else {
        foreach (m3d_find_patrons($q, 300) as [$id, $name]) $ids[(string) $id] = (string) $name;
    }
}

// one pass over the log for them: the newest download and the membership saved with it
$last = [];
if ($ids) foreach (m3d_log_rows() as $r) {
    $id = (string) ($r[1] ?? '');
    if (!isset($ids[$id]) || ($t = strtotime((string) ($r[0] ?? ''))) === false) continue;
    if (!isset($last[$id]) || $t >= $last[$id][0]) $last[$id] = [$t, m3d_log_membership($r) ?? ($last[$id][1] ?? null)];
}

// best first: a name starting with what was typed, then the newest download
if (!$one && count($ids) > 1) {
    $fq = m3d_fold($q);
    $rank = fn($id) => [preg_match('/^\d+$/D', $q) || str_starts_with(m3d_fold((string) $ids[$id]), $fq) ? 0 : 1, -($last[$id][0] ?? 0)];
    $keys = array_keys($ids);
    usort($keys, fn($a, $b) => $rank($a) <=> $rank($b));
    $ids = array_combine(array_map('strval', $keys = array_slice($keys, 0, PEOPLE_MAX)), array_map(fn($k) => $ids[$k], $keys));
}

// what they wrote, only for the people with no download (a line about them all the same)
$wrote = [];
$noDl = array_diff_key($ids, $last);
if ($noDl) {
    $count = function (string $id, string $what) use (&$wrote, $noDl) { if (isset($noDl[$id])) $wrote[$id][$what] = ($wrote[$id][$what] ?? 0) + 1; };
    foreach (m3d_bugs() as $b) $count((string) ($b['by'] ?? ''), 'bug report');
    foreach (m3d_comments_flat() as [, , $c]) $count((string) ($c['by'] ?? ''), 'comment');
    foreach (m3d_ideas_flat() as [, , $i]) $count((string) ($i['by'] ?? ''), 'idea');
    foreach (m3d_renders() as $r) $count((string) ($r['by'] ?? ''), 'render');
}

$out = [];
foreach ($ids as $id => $name) {
    $id = (string) $id;
    $name = (string) $name;
    $tags = [];
    if (in_array($id, $owners, true)) $tags[] = ['Admin', 'admin'];
    if (isset($mods[$id])) $tags[] = [$mods[$id]['on'] ? 'Moderator' : 'Moderator · Off', 'mod'];
    if (m3d_bans($id)) $tags[] = ['Banned', 'ban'];
    $x = m3d_exception($id);
    if ($x && empty($x['paused']) && array_filter([m3d_ex_mode($x['tier'] ?? null), m3d_ex_mode($x['extra'] ?? null), m3d_ex_mode($x['limits'] ?? null)])) $tags[] = ['Exception', 'ex'];
    $meta = [];
    if (isset($last[$id])) {
        [$t, $m] = $last[$id];
        if ($m && $m['tier_cents'] !== null && $m['tier_cents'] > 0) $meta[] = (m3d_known_tiers()[$m['tier_cents']] ?? '') ?: m3d_money($m['tier_cents']) . ' tier';
        if ($m && $m['months'] !== null) $meta[] = m3d_plural($m['months'], 'month');
        $meta[] = 'last download ' . bug_ago($t);
    } elseif (!empty($wrote[$id])) {
        foreach ($wrote[$id] as $what => $n) $meta[] = m3d_plural($n, $what);
        $meta[] = 'no downloads';
    } elseif (!isset($known[$id])) {
        $meta[] = 'Not seen on the site yet';
    }
    $off = '';
    if ($ctx === 'mod' && isset($mods[$id])) $off = 'Already a Moderator';
    elseif (in_array($ctx, ['mod', 'user'], true) && in_array($id, $owners, true)) $off = 'Admin';
    $h = 0;
    foreach (str_split($name) as $c) $h = ($h * 31 + ord($c)) % 360;   // the letter circle's hue, as avatar_html()
    $out[] = ['id' => $id, 'name' => $name, 'pick' => m3d_patron_pick($id, $name), 'pic' => m3d_avatar($id), 'h' => $h, 'tags' => $tags, 'meta' => implode(' · ', $meta), 'off' => $off];
}
// digits nobody known has: offered as typed
if (!$one && preg_match('/^\d{5,20}$/D', $q) && !isset($ids[$q])) $out[] = ['id' => $q, 'name' => '', 'pick' => $q, 'pic' => '', 'h' => 0, 'tags' => [], 'meta' => 'Not seen on the site yet', 'off' => ''];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(['people' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
