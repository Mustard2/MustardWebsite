<?php
// The bell's list (app/notify.php, assets/bell.js):
//   GET ?list=1         {ok, html, count}: the panel's content
//   GET ?go=c<id>       a comment opened from the bell: marked read, then its page (#cm-<id>)
//   GET ?go=l<kind>:<key>  an item's new likes: read, then its page
//                       (a link on another website only goes to the page: nothing is marked, m3d_cross_site())
//   POST do=read-all    Mark All as Read (comments until now, every bug report not opened yet) → {ok, count}
//   POST do=clear       Clear All: Mark All as Read and the list empties → {ok, count}
//   POST do=read&code   one row read (its ✓): "c<id>", "b<n>", "l<kind>:<key>" → {ok, count}
//   POST do=remove&code&at  one row left out (its ✕) until something newer than `at` (the row's time) happens on it; a Needs a Look
//                       row ("s:links" …) until it changes → {ok, count}
//   POST do=publish&id  publishes a comment waiting for approval → {ok, count}
require __DIR__ . '/_admin.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_guard($M3D);
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';   // (a crafted array: no PHP warning)
    if ($do === 'read-all' || $do === 'clear') {
        if (!($do === 'clear' ? m3d_notify_clear() : m3d_notify_read_all())) admin_json(['ok' => false, 'error' => m3d_write_error('notifications')], 500);
    } elseif ($do === 'read' || $do === 'remove') {
        if (!preg_match(NOTIFY_CODE_RE, $code) || ($do === 'read' && str_starts_with($code, 's:'))) admin_json(['ok' => false, 'error' => 'Unknown notification.'], 400);
        if (!($do === 'read' ? m3d_notify_read($code) : m3d_notify_remove($code, (int) ($_POST['at'] ?? 0)))) admin_json(['ok' => false, 'error' => m3d_write_error('notifications')], 500);
    } elseif ($do === 'publish') {
        $id = (int) ($_POST['id'] ?? 0);
        if (!m3d_comment_find($id)) admin_json(['ok' => false, 'error' => 'That comment is gone already.'], 404);
        if (!m3d_comment_change($id, function ($c) { unset($c['pending']); return $c; })) admin_json(['ok' => false, 'error' => m3d_write_error('comments')], 500);
        m3d_notify_read('c' . $id);
    } else {
        admin_json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    m3d_bugs(true);
    admin_json(['ok' => true, 'count' => m3d_notify_count()]);
}

if (isset($_GET['go'])) {
    admin_require($M3D);
    $code = m3d_get_text('go');
    $mark = !m3d_cross_site();
    if (preg_match('/^l((?:models|outfits|posts|stills):[a-z0-9-]{1,100})$/', $code, $m)) {   // an item's new likes
        if (!($it = m3d_notify_like_item($m[1]))) m3d_redirect('/admin/');   // deleted meanwhile
        if ($mark) m3d_notify_read($code);
        m3d_redirect($it[2]);
    }
    $found = preg_match('/^c(\d{1,9})$/', $code, $m) ? m3d_comment_find((int) $m[1]) : null;
    if (!$found) m3d_redirect('/admin/');   // deleted meanwhile
    [$kind, $key] = $found;
    if ($mark) m3d_notify_read($code);
    [, $url] = m3d_comment_page($kind, $key);
    m3d_redirect(($url !== '' ? $url : '/admin/') . '#cm-' . (int) $m[1]);
}

admin_require($M3D, true);
m3d_session_release();
admin_json(['ok' => true, 'html' => notify_list_html(), 'count' => m3d_notify_count()]);
