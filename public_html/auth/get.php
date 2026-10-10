<?php
// Download gate.
//   /get/<model>/<n>/          → link n of a model
//   /get/outfit/<outfit>/<n>/  → link n of an outfit
//   /get/<model>/              → first patron link of a model (old address)
// Public links redirect straight away; patron links need a fresh Patreon tier check first, then the item's
// extra requirements if it has any (months as a patron, total support: m3d_check_requirements).
// Every download is counted (m3d_count_download: totals only, no personal data).
// Patron links also respect the download limits (m3d_check_limits). Before any download, a waiting page counts down
// (Admin → Access → Downloads → wait): it links back here with a signed, time-stamped ?t= that the server checks.
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();   // no downloads during maintenance (admins pass)

$kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
$slug = (string) ($_GET['model'] ?? '');
$n = isset($_GET['n']) && ctype_digit((string) $_GET['n']) ? (int) $_GET['n'] : null;
$item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
if (!$item || (m3d_visibility($item) === 'draft' && !m3d_can_view($kind, $item))) {
    http_response_code(404);
    exit('Unknown ' . ($kind === 'outfits' ? 'accessory' : 'model') . '.');
}
// password items: public and patron links alike only after the password was entered on the page
if (!m3d_can_view($kind, $item)) m3d_redirect(m3d_item_url($kind, $slug));
$links = array_values(array_filter($item['links'] ?? [], fn($l) => !empty($l['url'])));
if ($n === null) {   // old /get/<model>/ address: first patron link
    foreach ($links as $i => $l) if (($l['access'] ?? 'patrons') === 'patrons') { $n = $i; break; }
}
$link = $n !== null ? ($links[$n] ?? null) : null;
if (!$link) {
    http_response_code(404);
    exit('This download is not available.');
}
$here = ($kind === 'outfits' ? "/get/outfit/$slug/" : "/get/$slug/") . "$n/";

// The waiting page: shown until the signed link in ?t= is old enough (a new one when missing, wrong or expired).
// $confirm (limit rows): the download will use one of the patron's downloads, so the page first asks "Use one of your
// downloads?" and the countdown starts after Yes (which adds c=1 to the link).
$wait = m3d_dl_settings()['wait'];
$waitGate = function (?array $confirm = null) use ($wait, $kind, $slug, $n, $item, $link, $here): void {
    $confirmed = $confirm === null || ($_GET['c'] ?? '') === '1';
    $token = (string) ($_GET['t'] ?? '');
    $left = 0;
    if ($wait > 0) {
        $left = m3d_dl_token_wait($token, $kind, $slug, (int) $n, $wait);
        if ($left === null) { $token = m3d_dl_token($kind, $slug, (int) $n); $left = $wait; }
    }
    if ($left === 0 && $confirmed) return;
    $go = $here . '?' . http_build_query(array_filter(['c' => $confirm !== null ? '1' : null, 't' => $wait > 0 ? $token : null]));
    download_wait_page($kind, $item, (string) ($link['label'] ?? ''), $left, $go, $confirmed ? null : $confirm);
};

if (($link['access'] ?? 'patrons') === 'public') {
    $waitGate();
    if (m3d_preview()) preview_download_page($kind, $item, (string) ($link['label'] ?? ''));   // admin previewing as a patron
    m3d_count_download($kind, $slug, true);
    m3d_redirect($link['url']);
}

$patron = m3d_patron($M3D, requireFresh: true);
if (!$patron) {
    // Not logged in, or last check too old: (re)check with Patreon. Seamless for patrons.
    m3d_redirect('/auth/login.php?return=' . rawurlencode($here));
}
if (empty($patron['access'])) {
    m3d_redirect('/patreon-required/?reason=' . rawurlencode((string) $patron['reason']) . '&return=' . rawurlencode($here));
}

// extra requirements of this model/outfit (time as a patron, total support): only on top of the paid tier
$req = m3d_check_requirements($item, $patron);
if ($req['unknown']) m3d_redirect('/auth/login.php?return=' . rawurlencode($here));   // session from before these checks: fetch the data
if (!$req['ok']) {
    m3d_redirect('/patreon-required/?reason=requirements&type=' . ($kind === 'outfits' ? 'outfit' : 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
}

// download limits (Admin → Access): checked before the waiting page and again when it sends the patron back
$limits = m3d_check_limits($patron, $kind, $slug);
if (!$limits['ok']) {
    m3d_redirect('/patreon-required/?reason=limit&type=' . ($kind === 'outfits' ? 'outfit' : 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
}
// a download that uses one of the patron's downloads (rules apply, not downloaded before, not a "not counted" item): ask first
$waitGate($limits['rows'] && !$limits['exempt'] && !$limits['free'] && !$limits['owned'] ? $limits['rows'] : null);
if (!empty($patron['preview'])) preview_download_page($kind, $item, (string) ($link['label'] ?? ''));   // admin previewing as a patron

m3d_log_download($M3D, $patron, ($kind === 'outfits' ? 'outfit:' : '') . $slug, (string) ($link['label'] ?? ''), m3d_version_label(m3d_latest_version($item)));
m3d_count_download($kind, $slug, false);
m3d_redirect($link['url']);
