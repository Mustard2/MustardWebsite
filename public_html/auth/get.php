<?php
// Download gate.
//   /get/<model>/<n>/          → link n of a model
//   /get/outfit/<outfit>/<n>/  → link n of an outfit
// Public links redirect straight away; patron links need a fresh Patreon tier check first, then the item's
// extra requirements if it has any (minimum tier, months as a patron, total support: m3d_check_requirements).
// Every download is counted (m3d_count_download: totals only, no personal data).
// Patron links also respect the download limits (m3d_check_limits). Before a patron download, a waiting page counts down
// (Admin → Access → Download Countdown): it links back here with a signed, time-stamped ?t= that the server checks. Public links start at once.
// A visitor who hasn't confirmed 18+ gets only the age gate (age_gate_page()), which comes back here.
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();   // no downloads during maintenance (admins pass)

$kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
$slug = m3d_get_text('model');
$n = ctype_digit(m3d_get_text('n')) ? (int) $_GET['n'] : null;
$item = m3d_item($kind, $slug);
// drafts and items hidden until their release date answer like an unknown address
if (!$item || ((m3d_visibility($item) === 'draft' || m3d_release_hidden($item)) && !m3d_can_view($kind, $item))) {
    dl_unavailable(404, 'Unknown ' . ($kind === 'outfits' ? 'accessory' : 'model') . '.');
}
// password items: public and patron links alike only after the password was entered on the page
if (!m3d_can_view($kind, $item)) m3d_redirect(m3d_item_url($kind, $slug));
$staff = m3d_is_admin($M3D) && !m3d_preview();   // previewing as a patron: refused like that patron
// before its patrons date ("Coming soon"): no download yet, the page says when; admins pass
if (m3d_release_phase($item) === 'soon' && !$staff) m3d_redirect(m3d_item_url($kind, $slug));
// "In development": the page shows no download, and old links (Discord, My Models) don't download either; admins pass
if ($kind === 'models' && m3d_status($item) === 'in-development' && !$staff) m3d_redirect(m3d_item_url($kind, $slug));
$links = array_values(array_filter($item['links'] ?? [], fn($l) => !empty($l['url'])));
$link = $n !== null ? ($links[$n] ?? null) : null;
if (!$link || !empty($link['off'])) dl_unavailable(404, 'This download is not available.');   // switched off in the editor: like a removed link
$here = ($kind === 'outfits' ? "/get/outfit/$slug/" : "/get/$slug/") . "$n/";
$label = (string) ($link['label'] ?? '');
// a short "s4:" link while no bucket is set in Settings: nowhere to send anyone
if (m3d_download_url((string) $link['url']) === null) dl_unavailable(503, 'This download isn’t ready yet.');
age_gate_page($here);   // not confirmed 18+ yet (a shared link): the age gate first, then back here

// the same link again within 10 minutes (the Download button pressed after the file already started, Back, a download manager):
// sent again, but not counted, logged or held against the daily cap a second time (m3d_dl_repeat()); a version out meanwhile is a new download
$code = m3d_dl_code($kind, $slug, $n) . '@' . m3d_released_label($item);
$again = m3d_dl_repeat($code);
$capped = fn() => m3d_redirect('/patreon-required/?reason=cap&return=' . rawurlencode(m3d_item_url($kind, $slug)));   // daily download cap (Admin → Access)

if (m3d_link_access($item, $link) === 'public') {   // public links, and every link past the item's public date
    // a patron link that is public only because the item's public date has passed: still log a patron's download (as "free",
    // never counted in the limits), so their "↑ Update" tag and My Models follow the version they got; logged downloads count in the daily cap
    $p = ($link['access'] ?? 'patrons') === 'patrons' ? m3d_patron($M3D) : null;
    if ($p && !empty($p['access']) && !$again && m3d_dl_capped($p) !== null) $capped();
    dl_wait_gate($kind, $slug, $n, $item, $label, $here, free: true);   // free downloads start at once
    if (m3d_preview()) preview_download_page($kind, $item, $label);   // admin previewing as a patron
    if (!$again) {
        m3d_count_download($kind, $slug, !($p && !empty($p['access'])));   // before the log line, like below
        if ($p && !empty($p['access'])) {
            m3d_log_download($M3D, $p, m3d_log_item($kind, $slug), $label, m3d_released_label($item), true);
        }
        m3d_dl_done($code);
    }
    m3d_redirect(m3d_download_url((string) $link['url']));
}

$patron = m3d_patron($M3D, requireFresh: true);
if (!$patron) {
    // Not logged in, or last check too old: (re)check with Patreon. Seamless for patrons.
    m3d_redirect('/auth/login.php?return=' . rawurlencode($here));
}
if (empty($patron['access'])) {
    m3d_redirect('/patreon-required/?reason=' . rawurlencode((string) $patron['reason']) . '&return=' . rawurlencode($here));
}

// extra requirements of this model/outfit (minimum tier, time as a patron, total support): only on top of the paid tier
$req = m3d_check_requirements($item, $patron);
if ($req['unknown']) m3d_redirect('/auth/login.php?return=' . rawurlencode($here));   // session from before these checks: fetch the data
if (!$req['ok']) {
    m3d_redirect('/patreon-required/?reason=requirements&type=' . ($kind === 'outfits' ? 'outfit' : 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
}

if (!$again && m3d_dl_capped($patron) !== null) $capped();

// download limits (Admin → Access): checked before the waiting page and again when it sends the patron back
$limits = m3d_check_limits($patron, $kind, $slug);
if (!$limits['ok']) {
    m3d_redirect('/patreon-required/?reason=limit&type=' . ($kind === 'outfits' ? 'outfit' : 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
}
// a download that uses one of the patron's downloads (rules apply, not downloaded before or a newer version with "Updates Use an
// Unlock", not a "not counted" item): ask first
$unlocks = $limits['rows'] && !$limits['exempt'] && !$limits['free'] && !$limits['owned'];
dl_wait_gate($kind, $slug, $n, $item, $label, $here, $unlocks ? $limits['rows'] : null, $unlocks && $limits['update']);
if (!empty($patron['preview'])) preview_download_page($kind, $item, $label);   // admin previewing as a patron

// the version logged = the newest one out (one dated in the future isn't yet); "free" = not counted in the limits, even if that changes later
// counted before the log line: with no stats.json yet the counters are first worked out from the log, which would then hold this one too
if (!$again) {
    m3d_count_download($kind, $slug, false);
    m3d_log_download($M3D, $patron, m3d_log_item($kind, $slug), $label, m3d_released_label($item), !empty($item['noLimits']), $unlocks && $limits['update']);
    m3d_dl_done($code);
}
m3d_redirect(m3d_download_url((string) $link['url']));   // an S4 file: a fresh pre-signed address (app/s4.php)
