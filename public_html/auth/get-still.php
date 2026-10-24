<?php
// Download gate of a picture's full size file: /get/still/<id>/ (a Gallery picture) or /get/still/<m|o>/<slug>/<code>/ (a picture of a
// model's gallery row or of an accessory, code = m3d_pic_code()), rewritten here by .htaccess.
// Like the model links (auth/get.php): the real address is never in a page; patron links need a fresh paid tier check and the waiting
// page first, public links start at once. Pictures are not part of the download limits or the extra requirements, and not in the patron download log:
// only the all-time counter (stats.json → downloads.stills, downloads.pics for model / accessory pictures under "kind:slug:code").
// Hidden or blurred pictures (m3d_still_view(), m3d_pics_hidden()) have no download.
// Not confirmed 18+ yet: the age gate first (age_gate_page()).
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();

$kind = ['m' => 'models', 'o' => 'outfits'][m3d_get_text('kind')] ?? '';
if ($kind !== '') {   // a model / accessory picture: the page's own rules (draft, password, blurred) decide
    $slug = m3d_get_text('slug');
    $pc = m3d_get_text('code');
    $it = preg_match('/^[a-f0-9]{8}$/', $pc) ? m3d_item($kind, $slug) : null;
    $pic = $it && m3d_can_view($kind, $it) && !m3d_pics_hidden($kind, $it) ? m3d_item_pic($kind, $it, $pc) : null;
    $link = $pic['link'] ?? null;
    if (!$pic || !is_array($link) || empty($link['url'])) dl_unavailable(404, 'This download is not available.');
    $statKind = 'pics';
    $id = "$kind:$slug:$pc";
    $here = '/get/still/' . m3d_get_text('kind') . "/$slug/$pc/";
    $back = m3d_item_url($kind, $slug);
    $item = ['slug' => $id, 'name' => $kind === 'models' ? m3d_model_name($it) : (string) $it['name']];
    $label = trim('Full size picture ' . (string) ($link['size'] ?? ''));
} else {
    $id = m3d_get_text('id');
    $still = preg_match('/^[a-f0-9]{8}$/', $id) ? m3d_still($id) : null;
    $link = $still['link'] ?? null;
    if (!$still || !is_array($link) || empty($link['url']) || m3d_still_view($still) !== 'clear') dl_unavailable(404, 'This download is not available.');
    $statKind = 'stills';
    $here = "/get/still/$id/";
    $back = '/gallery/';
    $item = ['slug' => $id, 'name' => m3d_still_name($still)];
    $label = trim('Full size ' . (string) ($link['size'] ?? ''));
}
// a short "s4:" link while no bucket is set in Settings: nowhere to send anyone
if (m3d_download_url((string) $link['url']) === null) dl_unavailable(503, 'This download isn’t ready yet.');
age_gate_page($here);   // not confirmed 18+ yet: the age gate first, then back here
$code = m3d_dl_code($statKind, $id, 0);
$again = m3d_dl_repeat($code);   // the same picture again within 10 minutes: sent, not counted again (like auth/get.php)

if (($link['access'] ?? 'patrons') === 'public') {
    dl_wait_gate($statKind, $id, 0, $item, $label, $here, back: $back, free: true);   // free downloads start at once
    if (m3d_preview()) preview_download_page($statKind, $item, $label, $back);
    if (!$again) { m3d_count_download($statKind, $id, true); m3d_dl_done($code); }
    m3d_redirect(m3d_download_url((string) $link['url']));
}

$patron = m3d_patron($M3D, requireFresh: true);
if (!$patron) m3d_redirect('/auth/login.php?return=' . rawurlencode($here));
if (m3d_banned($patron, 'download')) m3d_redirect(m3d_ban_url('download'));   // banned in Admin → Access
if (empty($patron['access'])) {
    m3d_redirect('/patreon-required/?reason=' . rawurlencode((string) $patron['reason']) . '&return=' . rawurlencode($here));
}
dl_wait_gate($statKind, $id, 0, $item, $label, $here, back: $back);
if (!empty($patron['preview'])) preview_download_page($statKind, $item, $label, $back);
if (!$again) { m3d_count_download($statKind, $id, false); m3d_dl_done($code); }
m3d_redirect(m3d_download_url((string) $link['url']));
