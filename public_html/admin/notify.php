<?php
// The bell's list (app/notify.php, assets/bell.js):
//   GET ?list=1         {ok, html, count}: the panel's content
//   GET ?go=c<id>       a comment opened from the bell: marked read, then its page (#cm-<id>)
//   POST do=read-all    Mark All as Read (comments until now, every bug report not opened yet) → {ok, count}
//   POST do=publish&id  publishes a comment waiting for approval → {ok, count}
require __DIR__ . '/_admin.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_post_guard($M3D);
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'read-all') {
        if (!m3d_notify_read_all()) admin_json(['ok' => false, 'error' => m3d_write_error('notifications')], 500);
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
    $found = preg_match('/^c(\d{1,9})$/', $code, $m) ? m3d_comment_find((int) $m[1]) : null;
    if (!$found) m3d_redirect('/admin/');   // deleted meanwhile
    [$kind, $key] = $found;
    m3d_notify_read($code);
    [, $url] = m3d_comment_page($kind, $key);
    m3d_redirect(($url !== '' ? $url : '/admin/') . '#cm-' . (int) $m[1]);
}

admin_require($M3D, true);
m3d_session_release();
admin_json(['ok' => true, 'html' => notify_list_html(), 'count' => m3d_notify_count()]);
