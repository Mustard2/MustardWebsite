<?php
// The bell of logged-in visitors (app/bell.php, assets/bell.js):
//   GET ?list=1             {ok, html, count}: the panel's content (opening it changes nothing)
//   POST do=read&code&at    a row clicked: read → {ok} (bell.js sends it as the link opens, keepalive)
//   POST do=remove&code&at  one row's ✕: an update ignored, a report marked opened, a reply / render left out → {ok}
//   POST do=clear           Clear All: every row of the list → {ok}
// csrf in the POST (the bell's data-csrf). Previewing as a patron, the marks stay in the preview (app/bell.php), never in files.
require dirname(__DIR__) . '/app/_init.php';
m3d_json_headers();
if (m3d_maintenance_blocks()) m3d_json_out(['ok' => false, 'error' => 'The website is in maintenance, try again later.'], 503);
$p = m3d_patron($M3D);
if (!m3d_ubell_for($p)) m3d_json_out(['ok' => false, 'error' => 'Log in again to see your notifications.'], 401);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) m3d_json_out(['ok' => false, 'error' => 'session expired, reload the page.'], 403);
    if (empty($p['preview'])) m3d_session_release();   // previewing, the marks go in the session
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
    $at = is_string($_POST['at'] ?? null) ? (int) $_POST['at'] : 0;
    if ($do === 'read' || $do === 'remove') {
        if (!preg_match(UBELL_CODE_RE, $code)) m3d_json_out(['ok' => false, 'error' => 'unknown notification.'], 400);
        $ok = $do === 'read' ? m3d_ubell_read_row($p, $code, $at) : m3d_ubell_remove($p, $code, $at);
    } elseif ($do === 'clear') {
        $ok = m3d_ubell_clear($p);
    } else {
        m3d_json_out(['ok' => false, 'error' => 'unknown action.'], 400);
    }
    if (!$ok) m3d_json_out(['ok' => false, 'error' => 'try again in a moment.'], 500);
    m3d_json_out(['ok' => true]);   // bell.js then asks for the list again (and the number)
}

m3d_session_release();
m3d_json_out(['ok' => true, 'html' => ubell_list_html($p, m3d_ubell_rows($p)), 'count' => m3d_ubell_count($p)]);
