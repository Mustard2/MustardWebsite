<?php
// Comments under model, accessory and blog post pages (app/comments.php). POST forms from comments_block(), CSRF; back to the page
// (`return`, its #cm-<id>) with ?cm=<done> or ?cmerr=<code> (COMMENT_ERRORS).
//   do=add {kind, key, text, re?}: a logged-in visitor who may comment (m3d_comment_can_post()) on a page they can see, comments on
//   do=delete {id}: their own comment, or any for the creator; do=edit {id, text} / approve / block / unblock: the creator only
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') m3d_redirect('/');
maintenance_gate();
$back = m3d_safe_return($_POST['return'] ?? '', '/');
$back = strtok($back, '?#') ?: '/';
$go = function (string $q, int $id = 0) use (&$back): never { m3d_redirect($back . '?' . $q . ($id ? '#cm-' . $id : '#comments')); };
if (!m3d_check_csrf($_POST['csrf'] ?? null)) $go('cmerr=session');

$p = m3d_patron($M3D);
if (!$p) $go('cmerr=login');
$admin = m3d_is_admin($M3D);
$creator = $admin && empty($p['preview']);
$do = bug_str($_POST['do'] ?? '');

if ($do === 'add') {
    $kind = bug_str($_POST['kind'] ?? '');
    $key = bug_str($_POST['key'] ?? '');
    if (!isset(COMMENT_KINDS[$kind])) $go('cmerr=off');
    // the page must be one this visitor sees in full, with comments open
    if ($kind === 'posts') {
        $item = preg_match('/^[0-9a-f]{8}$/D', $key) && (m3d_blog_on() || $admin) ? m3d_post($key) : null;
        if (!$item || m3d_post_view($item) !== 'full') not_found();
        $back = m3d_post_url($item);
    } else {
        $item = m3d_item($kind, $key);
        if (!$item || !m3d_can_view($kind, $item)) not_found();
        $back = m3d_item_url($kind, $key);
    }
    $text = bug_text($_POST['text'] ?? '', COMMENT_MAX);
    // a reply: under the comment it answers, or under that one's own comment when it answers a reply (two levels, `re` + `to`)
    $target = (int) ($_POST['re'] ?? 0);
    $re = $to = 0;
    if ($target > 0) {
        $parent = null;
        $ids = array_map(fn($x) => (int) ($x['id'] ?? 0), m3d_comments($kind, $key));
        foreach (m3d_comments($kind, $key) as $x) if ((int) ($x['id'] ?? 0) === $target) $parent = $x;
        if (!$parent || !m3d_comment_shown($parent, $p, $creator) || !empty($parent['pending'])) $go('cmerr=gone');
        $re = (int) ($parent['re'] ?? 0);
        if (!in_array($re, $ids, true)) $re = $target;   // a reply whose comment was deleted shows as a comment of its own: answers go under it
        if ($re !== $target) $to = $target;
    }
    $keep = function () use ($kind, $key, $text, $target) { if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['cm_draft'][$kind . ':' . $key . ($target > 0 ? ':' . $target : '')] = $text; };
    $can = m3d_comment_can_post($p);
    $S = m3d_comment_settings();
    $why = !m3d_comments_open($kind, $item) ? 'off'
        : (!$can['ok'] ? (!empty($p['preview']) ? 'preview' : ($can['why'] === 'banned' ? 'blocked' : 'paying'))
        : (mb_strlen($text) < 2 ? 'short' : ''));
    if ($why === 'blocked') m3d_redirect(m3d_ban_url('comments'));   // banned in Admin → Access: the page with the reason
    if ($why !== '') { $keep(); $go('cmerr=' . $why); }
    $c = m3d_comments_update(function (array $all) use ($kind, $key, $text, $p, $admin, $S, $re, $to) {
        // the daily limit, checked under the same lock as the save (two sessions at once can't both pass); admins have none
        if (!$admin && m3d_comments_sent_today((string) $p['id'], $all) >= $S['per_day']) return [$all, 'limit'];
        $c = ['id' => $all['next'], 'by' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'at' => time(), 'text' => $text];
        if ($re) $c['re'] = $re;
        if ($to) $c['to'] = $to;
        if ($admin) $c['admin'] = true;
        elseif (m3d_comment_needs_check($p, $S)) $c['pending'] = true;
        $all['next']++;
        $all[$kind][$key] = [...(array) ($all[$kind][$key] ?? []), $c];
        return [$all, $c];
    });
    if (!is_array($c)) { $keep(); $go('cmerr=' . ($c === 'limit' ? 'limit' : 'save')); }
    if (!$admin) m3d_comment_notify($kind, $key, $c);
    $go(!empty($c['pending']) ? 'cm=pending' : 'cm=sent', (int) $c['id']);
}

$id = (int) ($_POST['id'] ?? 0);
$found = $id > 0 ? m3d_comment_find($id) : null;
if (!$found) $go('cm=deleted');   // gone already (deleted in another tab)
[$kind, $key, , $c] = $found;
$mine = empty($p['preview']) && (string) ($c['by'] ?? '') === (string) $p['id'];

if ($do === 'delete' && ($creator || $mine)) {
    $go(m3d_comment_change($id, fn() => null) ? 'cm=deleted' : 'cmerr=save');
}
if (!$creator) $go('cmerr=off', $id);

if ($do === 'edit') {
    $text = bug_text($_POST['text'] ?? '', COMMENT_MAX);
    if (mb_strlen($text) < 2) $go('cmerr=short', $id);
    $ok = m3d_comment_change($id, fn($c) => ($c['text'] ?? '') === $text ? $c : ['text' => $text, 'edited' => time()] + $c);
    $go($ok ? 'cm=saved' : 'cmerr=save', $id);
}
if ($do === 'approve') {
    $ok = m3d_comment_change($id, function ($c) { unset($c['pending']); return $c; });
    if ($ok) m3d_notify_read('c' . $id);   // published here: read in the bell too, as Publish there does
    $go($ok ? 'cm=approved' : 'cmerr=save', $id);
}
if ($do === 'block' || $do === 'unblock') {
    $by = (string) ($c['by'] ?? '');
    if ($by === '' || in_array($by, array_map('strval', m3d_owner_ids($M3D)), true)) $go('cmerr=save', $id);
    $go(m3d_ban_set($by, 'comments', $do === 'block', (string) ($c['name'] ?? '')) ? 'cm=' . $do . 'ed' : 'cmerr=save', $id);   // a ban in Admin → Access
}
$go('cmerr=off', $id);
