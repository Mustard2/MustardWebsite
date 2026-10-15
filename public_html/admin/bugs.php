<?php
// The creator's changes to several bug reports, made on the public pages (no bug pages in the admin; one list for everyone, like GitHub):
// POST do=batch from the list /bugs/ (tick reports, then the bar at the bottom: ns[] = report numbers, op = status:<s> | tag:<id> | seen |
// publish | hide | show | delete), do=tags from the "Edit Tags" window (bug_tags_dialog(): tag_id[], tag_name[], tag_color[]); back = the page
// it came from (/bugs/ or /bugs/<n>/, CSRF), which it goes back to with ?done= or ?err=. Opening this address goes to the list.
require __DIR__ . '/_admin.php';
admin_require($M3D);

if (!admin_form_post() || !in_array($_POST['do'] ?? '', ['batch', 'tags'], true)) {
    m3d_redirect('/bugs/');   // nothing to show here: the list is /bugs/
}

// back to the page as it was (the list's filters and page), without an earlier ?done= / ?err= / ?edit=
$back = m3d_safe_return($_POST['back'] ?? '', '/bugs/');
$u = parse_url($back);
$path = preg_match('#^/bugs/(\d+/)?$#', (string) ($u['path'] ?? '')) ? (string) $u['path'] : '/bugs/';
parse_str($u['query'] ?? '', $q);
unset($q['done'], $q['err'], $q['edit']);
$back = $path . ($q ? '?' . http_build_query($q) : '');
$anchor = $path === '/bugs/' ? '#list' : '#tools';
$go = fn(string $key, string $msg) => m3d_redirect($back . (str_contains($back, '?') ? '&' : '?') . $key . '=' . rawurlencode($msg) . $anchor);

if ($_POST['do'] === 'tags') {
    $old = m3d_bug_tags();
    $list = [];
    $ids = [];
    foreach ((array) ($_POST['tag_name'] ?? []) as $i => $name) {
        $name = bug_line($name, 30);
        $id = bug_str($_POST['tag_id'][$i] ?? '');
        $color = strtolower(bug_str($_POST['tag_color'][$i] ?? ''));
        if ($name === '') continue;
        if (!preg_match('/^#[0-9a-f]{6}$/', $color)) $color = BUG_TAG_COLORS[count($list) % count(BUG_TAG_COLORS)];
        if (!isset($old[$id]) || isset($ids[$id])) {   // a new tag: an id from its name
            $base = trim((string) preg_replace('/[^a-z0-9]+/', '-', m3d_fold($name)), '-') ?: 'tag';
            if (ctype_digit($base)) $base = "tag-$base";   // never digits only ("2024"): PHP would turn it into a number key
            $base = substr($base, 0, 30);
            $id = $base;
            // never an id of an existing tag (kept further down the list, or deleted now: its reports still carry it)
            for ($k = 2; isset($ids[$id]) || isset($old[$id]); $k++) $id = "$base-$k";
        }
        $ids[$id] = true;
        $list[] = ['id' => $id, 'name' => $name, 'color' => $color];
    }
    if (count($list) > 60) $go('err', '60 tags at most.');
    if (!m3d_bug_settings_save(['tags' => $list])) $go('err', m3d_write_error());
    $gone = array_diff(array_map('strval', array_keys($old)), array_keys($ids));
    if ($gone) m3d_bugs_update(function (array $bugs, int $next) use ($gone) {   // a deleted tag leaves the reports too
        foreach ($bugs as $i => $b) if (array_intersect(array_map('strval', (array) ($b['tags'] ?? [])), $gone)) $bugs[$i]['tags'] = array_values(array_diff(array_map('strval', (array) $b['tags']), $gone));
        return [$bugs, $next, true];
    });
    $go('done', 'Tags saved.');
}

$ns = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ns'] ?? [])))));
$op = bug_str($_POST['op'] ?? '');
$tags = m3d_bug_tags();
if (!$ns) $go('err', 'Tick the reports to change first.');
if (!preg_match('/^(status:(' . implode('|', array_keys(BUG_STATUSES)) . ')|tag:[a-z0-9-]{1,40}|seen|publish|hide|show|delete)$/', $op)
    || (str_starts_with($op, 'tag:') && !isset($tags[substr($op, 4)]))) $go('err', 'Pick what to do with the ticked reports.');

$now = time();
$pics = [];
$n = m3d_bugs_update(function (array $bugs, int $next) use ($ns, $op, $now, &$pics) {
    $count = 0;
    foreach ($bugs as $i => $b) {
        if (!in_array((int) ($b['n'] ?? 0), $ns, true)) continue;
        $count++;
        if ($op === 'delete') { $pics = [...$pics, ...(array) ($b['pics'] ?? [])]; unset($bugs[$i]); continue; }
        if (str_starts_with($op, 'status:')) {
            $st = substr($op, 7);
            if (($b['status'] ?? 'new') === $st) continue;
            $b = m3d_bug_set_status($b, $st, $now);
        } elseif (str_starts_with($op, 'tag:')) {
            $id = substr($op, 4);
            if (in_array($id, array_map('strval', (array) ($b['tags'] ?? [])), true)) continue;
            $b['tags'][] = $id;
            $b['log'][] = ['t' => 'tag', 'at' => $now, 'add' => [$id]];
        } elseif ($op === 'hide') $b['hidden'] = true;
        elseif ($op === 'show') unset($b['hidden']);
        elseif ($op === 'publish') unset($b['pending']);
        elseif ($op === 'seen') $b['seen'] = true;
        $bugs[$i] = $b;
    }
    return [array_values($bugs), $next, $count];
});
if ($n === null) $go('err', 'Could not write ' . basename(M3D_PRIVATE) . '/data/bugs.json. Check that the folder is writable.');
bug_delete_pics($pics);
$go('done', m3d_plural((int) $n, 'report') . ' ' . ($op === 'delete' ? 'deleted' : 'changed') . '.');
