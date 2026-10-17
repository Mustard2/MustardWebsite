<?php
// "Ignore this update": hides the "↑ Update" tag of one model / accessory (catalog cards, the account button's dot) for its
// current version only (m3d_ignore_update() in bootstrap.php, data/ignored.json). Patrons who can download, not in a preview.
//   POST {kind: models|outfits, slug, ignore: bool} + X-CSRF-Token (the csrf in the header's data-me)  → {ignored}
require dirname(__DIR__) . '/app/_init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function ign_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (m3d_maintenance_blocks()) ign_out(['error' => 'The website is in maintenance, try again later.'], 503);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') ign_out(['error' => 'Send a POST.'], 405);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$kind = ($in['kind'] ?? '') === 'outfits' ? 'outfits' : 'models';
$slug = is_string($in['slug'] ?? null) ? $in['slug'] : '';
$p = m3d_patron($M3D);
if (!$p) ign_out(['error' => 'Log in again to change this.'], 401);
if (!empty($p['preview'])) ign_out(['error' => 'Ignoring updates is off while previewing as a patron.'], 403);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) ign_out(['error' => 'Session expired, reload the page.'], 403);
m3d_session_release();   // nothing more to write in the session: other pages of this visitor don't wait for the file below
$ignore = !empty($in['ignore']);
$r = m3d_ignore_update($p, $kind, $slug, $ignore);
if ($r === false) ign_out(['error' => 'There is no update to ignore here any more. Reload the page.'], 409);
if ($r === null) ign_out(['error' => 'Not saved, try again later.'], 500);
ign_out(['ignored' => $ignore]);
