<?php
// Download gate.
//   /get/<model>/<n>/          → link n of a model
//   /get/outfit/<outfit>/<n>/  → link n of an outfit
//   /get/scene/<scene>/<n>/    → link n of a scene
// Public links redirect straight away; patron links need a fresh Patreon tier check first, then the item's
// extra requirements if it has any (minimum tier, months as a patron, total support: m3d_check_requirements).
// Every download is counted (m3d_count_download: totals only, no personal data).
// Patron links also respect the download limits (m3d_check_limits). Before a patron download, a waiting page counts down
// (Admin → Access → Download Countdown): it links back here with a signed, time-stamped ?t= that the server checks. Public links start at once.
// A visitor who hasn't confirmed 18+ gets only the age gate (age_gate_page()), which comes back here. A link with its own password
// (the lock on its row in the editor) then asks for it (dl_link_pw_page()), before the Patreon checks.
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();   // no downloads during maintenance (admins pass)

$kind = ['outfit' => 'outfits', 'scene' => 'scenes'][m3d_get_text('type')] ?? 'models';
$slug = m3d_get_text('model');
$n = ctype_digit(m3d_get_text('n')) ? (int) $_GET['n'] : null;
$item = m3d_item($kind, $slug);
// drafts and items hidden until their release date answer like an unknown address
if (!$item || ((m3d_visibility($item) === 'draft' || m3d_release_hidden($item)) && !m3d_can_view($kind, $item))) {
    dl_unavailable(404, 'Unknown ' . (m3d_kind_word($kind)) . '.');
}
// password items: public and patron links alike only after the password was entered on the page
if (!m3d_can_view($kind, $item)) m3d_redirect(m3d_item_url($kind, $slug));
$staff = m3d_is_admin($M3D) && !m3d_preview();   // previewing as a patron: refused like that patron
// before its patrons date ("Coming soon"): no download yet, the page says when; admins pass
if (m3d_release_phase($item) === 'soon' && !$staff) m3d_redirect(m3d_item_url($kind, $slug));
$links = array_values(array_filter($item['links'] ?? [], fn($l) => !empty($l['url'])));
$link = $n !== null ? ($links[$n] ?? null) : null;
// "In development": the page shows no download, and old links (Discord, My Models) don't download either; admins pass, and so do
// links with their own password (the download box shows those, for the people given it: dl_link_pw_page() below asks for it)
if ($kind === 'models' && m3d_status($item) === 'in-development' && !$staff && !($link && m3d_link_pw($link) !== '')) m3d_redirect(m3d_item_url($kind, $slug));
if (!$link || !empty($link['off'])) dl_unavailable(404, 'This download is not available.');   // switched off in the editor: like a removed link
$here = m3d_get_url($kind, $slug, (int) $n);
$label = (string) ($link['label'] ?? '');
// a short "s4:" link while no bucket is set in Settings: nowhere to send anyone
if (m3d_download_url((string) $link['url']) === null) dl_unavailable(503, 'This download isn’t ready yet.');
age_gate_page($here);   // not confirmed 18+ yet (a shared link): the age gate first, then back here
dl_link_pw_page($kind, $item, $link, (int) $n, $here);   // a link with its own password: the password form until this visitor typed it (admins pass)

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
        if ($p && !empty($p['access'])) {
            // the cap again, with the log line, under the patron's lock (m3d_dl_locked()): parallel downloads can't pass it together
            $ok = m3d_dl_locked((string) $p['id'], function () use ($M3D, $p, $kind, $slug, $label, $item) {
                if (m3d_dl_capped($p) !== null) return false;
                m3d_count_download($kind, $slug, false);   // before the log line, like below
                m3d_log_download($M3D, $p, m3d_log_item($kind, $slug), $label, m3d_released_label($item), true);
                return true;
            });
            if (!$ok) $capped();
        } else {
            m3d_count_download($kind, $slug, true);
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
if (m3d_banned($patron, 'download')) m3d_redirect(m3d_ban_url('download'));   // banned in Admin → Access: the page with the reason
if (empty($patron['access'])) {
    m3d_redirect('/patreon-required/?reason=' . rawurlencode((string) $patron['reason']) . '&return=' . rawurlencode($here));
}

// extra requirements of this model/outfit (minimum tier, time as a patron, total support): only on top of the paid tier
$req = m3d_check_requirements($item, $patron);
if ($req['unknown']) m3d_redirect('/auth/login.php?return=' . rawurlencode($here));   // session from before these checks: fetch the data
if (!$req['ok']) {
    m3d_redirect('/patreon-required/?reason=requirements&type=' . (['outfits' => 'outfit', 'scenes' => 'scene'][$kind] ?? 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
}

if (!$again && m3d_dl_capped($patron) !== null) $capped();

// download limits (Admin → Access): checked before the waiting page and again when it sends the patron back
$limits = m3d_check_limits($patron, $kind, $slug);
if (!$limits['ok']) {
    m3d_redirect('/patreon-required/?reason=limit&type=' . (['outfits' => 'outfit', 'scenes' => 'scene'][$kind] ?? 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
}
// a download that uses one of the patron's downloads (rules apply, not downloaded before or a newer version with "Updates Use an
// Unlock", not a "not counted" item): ask first
$unlocks = $limits['rows'] && !$limits['exempt'] && !$limits['free'] && !$limits['owned'];
dl_wait_gate($kind, $slug, $n, $item, $label, $here, $unlocks ? $limits['rows'] : null, $unlocks && $limits['update']);
if (!empty($patron['preview'])) preview_download_page($kind, $item, $label);   // admin previewing as a patron

// the version logged = the newest one out (one dated in the future isn't yet); "free" = not counted in the limits, even if that changes later
// counted before the log line: with no stats.json yet the counters are first worked out from the log, which would then hold this one too
// The cap and the limits are checked again together with the log line, under the patron's lock (m3d_dl_locked()) and on the log read
// fresh: the checks above ran before the countdown, so two downloads of the same patron at once (two browsers) could both have passed them
if (!$again) {
    $refused = m3d_dl_locked((string) $patron['id'], function () use ($M3D, $patron, $kind, $slug, $label, $item) {
        if (m3d_dl_capped($patron) !== null) return 'cap';
        $limits = m3d_check_limits($patron, $kind, $slug);
        if (!$limits['ok']) return 'limit';
        $unlocks = $limits['rows'] && !$limits['exempt'] && !$limits['free'] && !$limits['owned'];
        m3d_count_download($kind, $slug, false);
        m3d_log_download($M3D, $patron, m3d_log_item($kind, $slug), $label, m3d_released_label($item), !empty($item['noLimits']), $unlocks && $limits['update']);
        return null;
    });
    if ($refused === 'cap') $capped();
    if ($refused === 'limit') m3d_redirect('/patreon-required/?reason=limit&type=' . (['outfits' => 'outfit', 'scenes' => 'scene'][$kind] ?? 'model') . '&item=' . rawurlencode($slug) . '&return=' . rawurlencode($here));
    m3d_dl_done($code);
}
m3d_redirect(m3d_download_url((string) $link['url']));   // an S4 file: a fresh pre-signed address (app/s4.php)
