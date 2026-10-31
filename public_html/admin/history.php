<?php
// GET JSON for the editor's "History" window (admin.js initEditor): the earlier versions of one model or accessory, newest first, each
// with what differs from it now (history_rows() in _history.php). Loading one is a link to edit.php?…&from=<file>.
require __DIR__ . '/_admin.php';
require __DIR__ . '/_history.php';
admin_require($M3D, true);
m3d_session_release();

$kind = in_array($_GET['kind'] ?? '', M3D_ITEM_KINDS, true) ? (string) $_GET['kind'] : 'models';
admin_json(['ok' => true, 'rows' => history_rows($kind, m3d_get_text('slug'))]);
