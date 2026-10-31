<?php
// "Ignore this update": hides the "↑ Update" tag of one model / accessory (catalog cards, the account button's dot) for its
// current version only (m3d_ignore_update() in bootstrap.php, data/ignored.json). Patrons who can download; previewing as a patron,
// only in the preview.
//   POST {kind: models|outfits, slug, ignore: bool} + X-CSRF-Token (the csrf in the header's data-me)  → {ignored}
require dirname(__DIR__) . '/app/_init.php';
$in = m3d_json_post();
$kind = in_array($in['kind'] ?? '', M3D_ITEM_KINDS, true) ? (string) $in['kind'] : 'models';
$slug = is_string($in['slug'] ?? null) ? $in['slug'] : '';
$p = m3d_patron($M3D);
if (!$p) m3d_json_out(['error' => 'Log in again to change this.'], 401);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) m3d_json_out(['error' => 'Session expired, reload the page.'], 403);
if (empty($p['preview'])) m3d_session_release();   // nothing more to write in the session (previewing: the ignore goes there)
$ignore = !empty($in['ignore']);
$r = m3d_ignore_update($p, $kind, $slug, $ignore);
if ($r === false) m3d_json_out(['error' => 'There is no update to ignore here any more. Reload the page.'], 409);
if ($r === null) m3d_json_out(['error' => 'Not saved, try again later.'], 500);
m3d_json_out(['ignored' => $ignore]);
