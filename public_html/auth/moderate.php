<?php
// What moderators and the creator do to visitors' posts (app/moderation.php): POST forms of Admin → Moderation (admin/moderation.php) and of the ⋯ menus
// on the pages, CSRF; back to `return` (a page of this site) with ?mod=<done> or ?moderr=<code> (mod_result_flash() in view.php).
//   do=approve | hide {why} | show {type, ref}: a moderator who checks that kind (and sees its page), or the creator
//   do=ban {type, ref, kinds[], why, also}: bans the post's author from kinds[] (comments | bugs | ideas), `also` hides their waiting posts there too
//   do=delete {type, ref}: the creator only, a hidden post for good; do=undo {log}: the creator only, puts back what a log entry changed
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') m3d_redirect('/admin/moderation.php');
maintenance_gate();
$back = m3d_safe_return($_POST['return'] ?? '', '/admin/moderation.php');
$hash = '';
$go = function (string $q) use (&$back, &$hash): never {
    $path = strtok($back, '#');
    m3d_redirect($path . (str_contains($path, '?') ? '&' : '?') . $q . $hash);
};
if (!m3d_check_csrf($_POST['csrf'] ?? null)) $go('moderr=session');
$role = m3d_mod_role();
if ($role === '') $go('moderr=role');
$creator = $role === 'creator';
$do = bug_str($_POST['do'] ?? '');

if ($do === 'undo') {
    if (!$creator) $go('moderr=role');
    $id = (int) ($_POST['log'] ?? 0);
    $e = null;
    foreach (m3d_modlog() as $x) if ((int) ($x['id'] ?? 0) === $id) $e = $x;
    if (!$e || !empty($e['undone'])) $go('moderr=gone');
    $ok = false;
    if (($e['act'] ?? '') === 'ban') {
        $ok = true;
        foreach ((array) ($e['added'] ?? []) as $k) if (isset(MOD_KINDS[$k])) $ok = m3d_ban_set((string) ($e['about'] ?? ''), $k, false) && $ok;
    } elseif (in_array($e['act'] ?? '', ['approve', 'hide', 'show'], true) && ($t = m3d_mod_target((string) ($e['type'] ?? ''), (string) ($e['ref'] ?? '')))) {
        $ok = m3d_mod_apply($t, 'restore', '', (array) ($e['was'] ?? [])) !== null;
    }
    if (!$ok) $go('moderr=save');
    m3d_modlog_undone($id);
    $go('mod=undone');
}

$type = bug_str($_POST['type'] ?? '');
$t = isset(MOD_TYPES[$type]) ? m3d_mod_target($type, bug_str($_POST['ref'] ?? '')) : null;
if (!$t) $go('moderr=gone');
if (!m3d_mod_may($t)) $go('moderr=role');
if (!str_starts_with($back, '/admin/moderation.php')) $hash = (string) strstr($t['url'], '#');   // back on the post's own page, at the post
$why = mb_substr(trim(preg_replace('/\s+/u', ' ', bug_str($_POST['why'] ?? '')) ?? ''), 0, MOD_WHY_MAX);

if ($do === 'approve' || $do === 'hide' || $do === 'show') {
    if ($do === 'approve' && (!$t['pending'] || $t['hid'])) $go('mod=approved');   // done already (another tab, another moderator)
    if ($do === 'show' && !$t['hid']) $go('mod=shown');
    if ($do === 'show' && !$creator && m3d_mod_hid_by_creator($t['hid'])) $go('moderr=role');   // what the creator hid, only the creator shows again
    if ($do === 'hide' && $t['hid']) $go('mod=hidden');
    if ($t['admin']) $go('moderr=role');   // the creator's own posts
    $was = m3d_mod_apply($t, $do, $why);
    if ($was === null) $go('moderr=save');
    m3d_modlog_add($do, $t, ['why' => $do === 'hide' ? $why : '', 'was' => $was]);
    $go('mod=' . ['approve' => 'approved', 'hide' => 'hidden', 'show' => 'shown'][$do]);
}

if ($do === 'delete') {
    if (!$creator) $go('moderr=role');
    if (m3d_mod_apply($t, 'delete') === null) $go('moderr=save');
    m3d_modlog_add('delete', $t);
    $go('mod=deleted');
}

if ($do === 'ban') {
    if (!m3d_mod_can('ban') || !mod_can_ban($t['by'])) $go('moderr=ban');
    $kinds = array_values(array_filter(array_map('strval', (array) ($_POST['kinds'] ?? [])), fn($k) => isset(MOD_KINDS[$k]) && m3d_mod_can($k)));
    if (!$kinds) $go('moderr=kinds');
    $had = m3d_bans($t['by']);
    $new = array_values(array_diff($kinds, $had, ['login']));
    $ok = true;
    foreach ($kinds as $k) $ok = m3d_ban_set($t['by'], $k, true, $t['name'], $why) && $ok;
    if (!$ok) $go('moderr=save');
    m3d_modlog_add('ban', $t, ['kinds' => $kinds, 'added' => $new, 'why' => $why]);   // Undo lifts only the bans this one added
    $hid = 0;
    if (!empty($_POST['also'])) foreach (m3d_mod_waiting_of($t['by'], $kinds) as $w) {
        if (($was = m3d_mod_apply($w, 'hide', $why)) !== null) { m3d_modlog_add('hide', $w, ['why' => $why, 'was' => $was]); $hid++; }
    }
    $go('mod=banned' . ($hid ? '&n=' . $hid : ''));
}
$go('moderr=role');
