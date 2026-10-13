<?php
// Download gate of a gallery picture's full size file: /get/still/<id>/ (rewritten here by .htaccess).
// Like the model links (auth/get.php): the real address is never in a page; the waiting page first; patron links need a fresh
// paid tier check. Pictures are not part of the download limits or the extra requirements, and not in the patron download log:
// only the all-time counter (stats.json → downloads.stills). Hidden or blurred pictures (m3d_still_view()) have no download.
// Not confirmed 18+ yet: the age gate first (age_gate_page()).
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();

$id = m3d_get_text('id');
$still = preg_match('/^[a-f0-9]{8}$/', $id) ? m3d_still($id) : null;
$link = $still['link'] ?? null;
if (!$still || !is_array($link) || empty($link['url']) || m3d_still_view($still) !== 'clear') {
    http_response_code(404);
    exit('This download is not available.');
}
$here = "/get/still/$id/";
age_gate_page($here);   // not confirmed 18+ yet: the age gate first, then back here
$item = ['slug' => $id, 'name' => trim((string) ($still['caption'] ?? '')) ?: (implode(', ', array_column(m3d_still_chars($still), 'name')) ?: 'Picture')];
$label = trim('Full size ' . (string) ($link['size'] ?? ''));

$wait = m3d_dl_settings()['wait'];
$waitGate = function () use ($wait, $id, $item, $label, $here): void {
    $code = m3d_dl_code('stills', $id, 0);
    $token = m3d_get_text('t');
    $left = 0;
    if ($wait > 0) {
        $left = m3d_dl_token_wait($token, 'stills', $id, 0, $wait);
        if ($left === null) {   // a new wait: not while another download of this visitor counts down (one at a time)
            if ($busy = m3d_dl_busy($code)) download_busy_page('stills', $item, $busy, '/gallery/');
            $token = m3d_dl_token('stills', $id, 0);
            $left = $wait;
        }
    }
    if ($left === 0 && $wait > 0 && ($busy = m3d_dl_busy($code))) download_busy_page('stills', $item, $busy, '/gallery/');   // a finished countdown kept from earlier doesn't jump the queue
    if ($left === 0) { m3d_dl_free($code); return; }
    m3d_dl_hold($code, $item['name'], $left);
    download_wait_page('stills', $item, $label, $left, $here . '?' . http_build_query(['t' => $token]), null, '/gallery/');
};

if (($link['access'] ?? 'patrons') === 'public') {
    $waitGate();
    if (m3d_preview()) preview_download_page('stills', $item, $label, '/gallery/');
    m3d_count_download('stills', $id, true);
    m3d_redirect((string) $link['url']);
}

$patron = m3d_patron($M3D, requireFresh: true);
if (!$patron) m3d_redirect('/auth/login.php?return=' . rawurlencode($here));
if (empty($patron['access'])) {
    m3d_redirect('/patreon-required/?reason=' . rawurlencode((string) $patron['reason']) . '&return=' . rawurlencode($here));
}
$waitGate();
if (!empty($patron['preview'])) preview_download_page('stills', $item, $label, '/gallery/');
m3d_count_download('stills', $id, false);
m3d_redirect((string) $link['url']);
