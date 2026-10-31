<?php
// Ideas boards on model and accessory pages (app/ideas.php). POST forms from ideas_block(), CSRF; back to the item's page (#idea-<id>,
// else #ideas) with ?idea=<done> or ?ideaerr=<code> (IDEA_ERRORS).
//   do=add {kind, slug, type, title, text}: a visitor who may suggest (m3d_idea_can_post()) on an item page they can see
//   do=vote {id}: their vote on or off (site.js sends it with Accept: application/json and gets {ok, n, on} back instead of the page)
//   do=delete {id}: their own idea, or any for the creator; do=approve / status {status, ver} / block / unblock: the creator only
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') m3d_redirect('/');
maintenance_gate();
$json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
$back = '/';
$go = function (string $q, int $id = 0) use (&$back, $json): never {
    if ($json) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $q]); exit; }
    m3d_redirect($back . '?' . $q . ($id ? '#idea-' . $id : '#ideas'));
};
$p = m3d_patron($M3D);
$do = bug_str($_POST['do'] ?? '');

if ($do === 'add') {
    $kind = bug_str($_POST['kind'] ?? '');
    $slug = bug_str($_POST['slug'] ?? '');
    $item = isset(IDEA_KINDS[$kind]) ? m3d_item($kind, $slug) : null;
    if (!$item || !m3d_can_view($kind, $item)) not_found();
    $back = m3d_item_url($kind, $slug);
} else {
    $found = ($id = (int) ($_POST['id'] ?? 0)) > 0 ? m3d_idea_find($id) : null;
    if ($found) $back = m3d_item_url($found[0], $found[1]);
    else $back = m3d_safe_return(strtok((string) ($_SERVER['HTTP_REFERER'] ?? ''), '?#') ?: '/', '/');
}
if (!m3d_check_csrf($_POST['csrf'] ?? null)) $go('ideaerr=session');
if (!$p) $go('ideaerr=login');
$creator = m3d_is_admin($M3D) && empty($p['preview']);
$can = m3d_idea_can_post($p);
$refuse = function () use ($can, $go): never {
    if ($can['why'] === 'banned') m3d_redirect(m3d_ban_url('ideas'));
    $go('ideaerr=' . ($can['why'] === 'preview' ? 'preview' : 'paying'));
};

if ($do === 'add') {
    $title = trim(preg_replace('/\s+/u', ' ', bug_str($_POST['title'] ?? '')) ?? '');
    $title = mb_substr($title, 0, IDEA_TITLE_MAX);
    $text = bug_text($_POST['text'] ?? '', IDEA_TEXT_MAX);
    $type = bug_str($_POST['type'] ?? '');
    if (!isset(m3d_idea_types($kind)[$type])) $type = 'feature';
    if (!m3d_ideas_on($kind)) $go('ideaerr=off');
    if (!$can['ok']) $refuse();
    if (mb_strlen($title) < 3) {
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['idea_draft'][$kind . ':' . $slug] = ['type' => $type, 'title' => $title, 'text' => $text];
        $go('ideaerr=short');
    }
    $S = m3d_idea_settings();
    $admin = m3d_is_admin($M3D);
    $i = m3d_ideas_update(function (array $all) use ($kind, $slug, $title, $text, $type, $p, $admin, $S) {
        if (!$admin && m3d_ideas_sent_today((string) $p['id'], $all) >= $S['per_day']) return [$all, 'limit'];
        $i = ['id' => $all['next'], 'by' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''), 'at' => time(), 'type' => $type, 'title' => $title];
        if ($text !== '') $i['text'] = $text;
        if ($admin) $i['admin'] = true;
        elseif (m3d_is_mod($GLOBALS['M3D'])) $i['mod'] = true;   // a moderator's never wait; "Moderator" after the name
        elseif ($S['moderate']) $i['pending'] = true;
        $i['votes'] = [m3d_like_code((string) $p['id'])];   // the author's own vote
        $all['next']++;
        $all[$kind][$slug] = [...(array) ($all[$kind][$slug] ?? []), $i];
        return [$all, $i];
    });
    if (!is_array($i)) {
        if (session_status() === PHP_SESSION_ACTIVE) $_SESSION['idea_draft'][$kind . ':' . $slug] = ['type' => $type, 'title' => $title, 'text' => $text];
        $go('ideaerr=' . ($i === 'limit' ? 'limit' : 'save'));
    }
    if (!$admin) m3d_idea_notify($kind, $slug, $i);
    $go(!empty($i['pending']) ? 'idea=pending' : 'idea=sent', (int) $i['id']);
}

if (!$found) $go($do === 'delete' ? 'idea=deleted' : 'ideaerr=gone');
[$kind, $slug, $i] = $found;
$item = m3d_item($kind, $slug);
if (!$item || !m3d_can_view($kind, $item) || !m3d_idea_shown($i, $p, m3d_mod_can('ideas'))) not_found();
$mine = empty($p['preview']) && (string) ($i['by'] ?? '') === (string) $p['id'];

if ($do === 'vote') {
    if (!m3d_ideas_on($kind)) $go('ideaerr=off', $id);
    if (!$can['ok']) $refuse();
    if (!empty($i['pending']) || !empty($i['hid']) || m3d_idea_closed($i)) $go('ideaerr=closed', $id);
    $code = m3d_like_code((string) $p['id']);
    $res = null;
    $ok = m3d_idea_change($id, function ($i) use ($code, &$res) {
        $v = (array) ($i['votes'] ?? []);
        $on = !in_array($code, $v, true);
        $i['votes'] = $on ? [...$v, $code] : array_values(array_diff($v, [$code]));
        $res = ['n' => count($i['votes']), 'on' => $on];
        return $i;
    });
    if (!$ok) $go('ideaerr=save', $id);
    if ($json) { header('Content-Type: application/json'); echo json_encode(['ok' => true] + $res); exit; }
    m3d_redirect($back . '#idea-' . $id);
}
if ($do === 'delete' && ($creator || $mine)) {
    $go(m3d_idea_change($id, fn() => null) ? ($creator && !empty($i['pending']) ? 'idea=rejected' : 'idea=deleted') : 'ideaerr=save');
}
if (!$creator) $go('ideaerr=off', $id);

if ($do === 'approve') {
    $ok = m3d_idea_change($id, function ($i) { unset($i['pending']); return $i; });
    if ($ok) m3d_notify_read('i' . $id);   // published here: read in the bell too
    $go($ok ? 'idea=approved' : 'ideaerr=save', $id);
}
if ($do === 'status') {
    $st = bug_str($_POST['status'] ?? '');
    $ver = mb_substr(trim(bug_str($_POST['ver'] ?? '')), 0, IDEA_VER_MAX);
    if (!isset(IDEA_STATUSES[$st])) $go('ideaerr=save', $id);
    $ok = m3d_idea_change($id, function ($i) use ($st, $ver) {
        unset($i['status'], $i['ver'], $i['pending']);   // a status set by the creator publishes it too
        if ($st !== 'new') $i['status'] = $st;
        if ($st === 'done' && $ver !== '') $i['ver'] = $ver;
        return $i;
    });
    if ($ok) m3d_notify_read('i' . $id);
    $go($ok ? 'idea=saved' : 'ideaerr=save', $id);
}
if ($do === 'block' || $do === 'unblock') {
    $by = (string) ($i['by'] ?? '');
    if ($by === '' || !empty($i['admin']) || in_array($by, array_map('strval', m3d_owner_ids($M3D)), true)) $go('ideaerr=save', $id);
    $go(m3d_ban_set($by, 'ideas', $do === 'block', (string) ($i['name'] ?? '')) ? 'idea=' . $do . 'ed' : 'ideaerr=save', $id);
}
$go('ideaerr=off', $id);
