<?php
// Tiny JSON endpoint with what the header shows a logged-in visitor (m3d_status_data() in view.php: name, Admin, unlocks
// left, unlocked items, updates). Public pages carry the same data themselves (account_box()); this answers anything else
// that asks. The download box works out its own verdict when the page is made (dl_verdict() in view.php). Never cached.
require dirname(__DIR__) . '/app/_init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$p = m3d_patron($M3D);
$data = m3d_status_data($p);
if (!empty($data['admin'])) $data['csrf'] = m3d_csrf();   // the admin editors take a fresh token after a new login in another tab (admin.js post())
session_write_close();   // read only: the login isn't kept locked while this runs, so the page's other requests don't wait
echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
