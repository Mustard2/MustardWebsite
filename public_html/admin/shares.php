<?php
// Temporary links: makes one. The window (shareWindow() in admin.js: the editor's Downloads box, Access → Temporary Links) posts
// {add: {kind, slug, time, max, note}} here as JSON; the list itself is a tab of Access (admin/_shares.php, users.php?tab=links).
require __DIR__ . '/_admin.php';

// ── JSON: make a temporary link ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    admin_post_guard($M3D);
    $in = json_decode((string) file_get_contents('php://input'), true);
    $a = is_array($in['add'] ?? null) ? $in['add'] : [];
    $kind = ($a['kind'] ?? '') === 'outfits' ? 'outfits' : 'models';
    $item = m3d_item($kind, is_string($a['slug'] ?? null) ? $a['slug'] : '');
    if (!$item) admin_json(['ok' => false, 'error' => 'This ' . ($kind === 'outfits' ? 'accessory' : 'model') . ' isn’t saved yet.'], 404);
    if (!m3d_share_links($item)) admin_json(['ok' => false, 'error' => 'It has no download links switched on. Add one and save first.'], 400);
    $time = SHARE_TIMES[is_string($a['time'] ?? null) ? $a['time'] : ''] ?? null;
    $max = (int) ($a['max'] ?? -1);
    if (!$time || !in_array($max, SHARE_MAXES, true)) admin_json(['ok' => false, 'error' => 'Pick how long it works and how many downloads it allows.'], 400);
    $s = m3d_share_add($kind, $item, $time[0], $max, clean_line($a['note'] ?? '', SHARE_NOTE_MAX));
    if (!$s) admin_json(['ok' => false, 'error' => m3d_write_error('temporary links')], 500);
    admin_json(['ok' => true, 'url' => abs_url('/share/' . $s['code'] . '/'), 'until' => m3d_site_time((int) $s['until'], 'D j M, H:i T')]);
}

m3d_redirect('/admin/users.php?tab=links');   // the list is on Access
