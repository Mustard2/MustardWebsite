<?php
// "Choose from S4…" in the editors' download links (admin.js pickS4()): the files and folders of the MEGA S4 bucket set in
// Settings → Features → MEGA S4 Downloads, one folder at a time (?prefix=folder/). JSON, read only (m3d_s4_list() in app/s4.php).
require __DIR__ . '/_admin.php';
admin_require($M3D, true);
if (!m3d_s4_on()) admin_json(['ok' => false, 'error' => 'MEGA S4 downloads are off (Settings → Features).'], 404);
m3d_session_release();   // S4 may take a moment: other admin pages aren't held up
$prefix = m3d_get_text('prefix');
if ($prefix !== '' && (!str_ends_with($prefix, '/') || str_starts_with($prefix, '/') || strlen($prefix) > 1000)) admin_json(['ok' => false, 'error' => 'Not a folder.'], 400);
$r = m3d_s4_list($prefix);
if (!$r['ok']) admin_json(['ok' => false, 'error' => $r['err']], 502);
admin_json($r);
