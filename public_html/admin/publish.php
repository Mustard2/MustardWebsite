<?php
// The "Publish" / "Make public" button of the visibility banner on a model or accessory page (visibility_banner() in view.php):
// a plain form POST that makes the item public, then goes back to its page.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$kind = in_array($_POST['kind'] ?? '', M3D_ITEM_KINDS, true) ? (string) $_POST['kind'] : 'models';
$slug = (string) ($_POST['slug'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !m3d_check_csrf($_POST['csrf'] ?? null)) {
    http_response_code(400);
    exit('The page was open too long. Go back, reload it and try again.');
}
m3d_session_release();   // saving may post to Discord: the other pages stay usable meanwhile
$items = m3d_items($kind);
$at = array_search($slug, array_column($items, 'slug'), true);
if ($at === false) { http_response_code(404); exit('Not found (deleted in another tab?).'); }
unset($items[$at]['visibility'], $items[$at]['unlisted']);   // the password hash stays, so switching back needs no new password
if (!m3d_save_items($kind, $items)) { http_response_code(500); exit('Could not write the data file.'); }
m3d_redirect(m3d_item_url($kind, $slug) . '?published=1');
