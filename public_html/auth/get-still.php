<?php
// Download gate of a gallery picture's full size file: /get/still/<id>/ (rewritten here by .htaccess).
// Like the model links (auth/get.php): the real address is never in a page; patron links need a fresh paid tier check and the waiting
// page first, public links start at once. Pictures are not part of the download limits or the extra requirements, and not in the patron download log:
// only the all-time counter (stats.json → downloads.stills). Hidden or blurred pictures (m3d_still_view()) have no download.
// Not confirmed 18+ yet: the age gate first (age_gate_page()).
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();

$id = m3d_get_text('id');
$still = preg_match('/^[a-f0-9]{8}$/', $id) ? m3d_still($id) : null;
$link = $still['link'] ?? null;
if (!$still || !is_array($link) || empty($link['url']) || m3d_still_view($still) !== 'clear') dl_unavailable(404, 'This download is not available.');
$here = "/get/still/$id/";
// a short "s4:" link while no bucket is set in Settings: nowhere to send anyone
if (m3d_download_url((string) $link['url']) === null) dl_unavailable(503, 'This download isn’t ready yet.');
age_gate_page($here);   // not confirmed 18+ yet: the age gate first, then back here
$item = ['slug' => $id, 'name' => m3d_still_name($still)];
$label = trim('Full size ' . (string) ($link['size'] ?? ''));
$code = m3d_dl_code('stills', $id, 0);
$again = m3d_dl_repeat($code);   // the same picture again within 10 minutes: sent, not counted again (like auth/get.php)

if (($link['access'] ?? 'patrons') === 'public') {
    dl_wait_gate('stills', $id, 0, $item, $label, $here, back: '/gallery/', free: true);   // free downloads start at once
    if (m3d_preview()) preview_download_page('stills', $item, $label, '/gallery/');
    if (!$again) { m3d_count_download('stills', $id, true); m3d_dl_done($code); }
    m3d_redirect(m3d_download_url((string) $link['url']));
}

$patron = m3d_patron($M3D, requireFresh: true);
if (!$patron) m3d_redirect('/auth/login.php?return=' . rawurlencode($here));
if (empty($patron['access'])) {
    m3d_redirect('/patreon-required/?reason=' . rawurlencode((string) $patron['reason']) . '&return=' . rawurlencode($here));
}
dl_wait_gate('stills', $id, 0, $item, $label, $here, back: '/gallery/');
if (!empty($patron['preview'])) preview_download_page('stills', $item, $label, '/gallery/');
if (!$again) { m3d_count_download('stills', $id, false); m3d_dl_done($code); }
m3d_redirect(m3d_download_url((string) $link['url']));
