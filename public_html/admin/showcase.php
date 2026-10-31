<?php
// Patron renders are managed in Media → From Patrons (admin/images.php?tab=patrons, admin/_showcase.php). This address takes the ⠿ order of
// Shown (JSON {order: [ids], item: "kind:slug"}, admin.js) and sends old links (Gallery → From Patrons, #r-<id>) there.
require __DIR__ . '/_admin.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    admin_post_guard($M3D);
    $in = json_decode((string) file_get_contents('php://input'), true);
    [$kind, $slug] = array_pad(explode(':', is_array($in) && is_string($in['item'] ?? null) ? $in['item'] : '', 2), 2, '');
    $ids = is_array($in['order'] ?? null) ? array_values(array_filter($in['order'], fn($i) => is_string($i) && preg_match('/^[0-9a-f]{8}$/D', $i))) : [];
    if (!in_array($kind, M3D_ITEM_KINDS, true) || $slug === '' || !$ids) admin_json(['ok' => false, 'error' => 'Nothing to save. Reload the page.'], 400);
    if (!m3d_renders_order($kind, $slug, $ids)) admin_json(['ok' => false, 'error' => m3d_write_error('data')], 500);
    admin_json(['ok' => true]);
}
admin_require($M3D);
header('Location: ' . m3d_showcase_admin_url(), true, 302);   // the browser keeps the #r-<id> of the old link
