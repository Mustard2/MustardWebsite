<?php
// Download gate.
//   /get/<model>/<n>/          → link n of a model
//   /get/outfit/<outfit>/<n>/  → link n of an outfit
//   /get/<model>/              → first patron link of a model (old address)
// Public links redirect straight away; patron links need a fresh Patreon tier check first, then the item's
// extra requirements if it has any (minimum tier, months as a patron, total support: m3d_check_requirements).
// Every download is counted (m3d_count_download: totals only, no personal data).
// Patron links also respect the download limits (m3d_check_limits). Before any download, a waiting page counts down
// (Admin → Access → Downloads → wait): it links back here with a signed, time-stamped ?t= that the server checks.
// A visitor who hasn't confirmed 18+ gets only the age gate (age_gate_page()), which comes back here.
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
maintenance_gate();   // no downloads during maintenance (admins pass)

$kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
$slug = m3d_get_text('model');
$n = ctype_digit(m3d_get_text('n')) ? (int) $_GET['n'] : null;
$item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
// drafts and items hidden until their release date answer like an unknown address
if (!$item || ((m3d_visibility($item) === 'draft' || m3d_release_hidden($item)) && !m3d_can_view($kind, $item))) {
    http_response_code(404);
    exit('Unknown ' . ($kind === 'outfits' ? 'accessory' : 'model') . '.');
}
// password items: public and patron links alike only after the password was entered on the page
if (!m3d_can_view($kind, $item)) m3d_redirect(m3d_item_url($kind, $slug));
$staff = m3d_is_admin($M3D) && !m3d_preview();   // previewing as a patron: refused like that patron
// before its patrons date ("Coming soon"): no download yet, the page says when; admins pass
if (m3d_release_phase($item) === 'soon' && !$staff) m3d_redirect(m3d_item_url($kind, $slug));
// "In development": the page shows no download, and old links (Discord, My Models) don't download either; admins pass
if ($kind === 'models' && m3d_status($item) === 'in-development' && !$staff) m3d_redirect(m3d_item_url($kind, $slug));
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
age_gate_page($here);   // not confirmed 18+ yet (a shared link): the age gate first, then back here

// The waiting page: shown until the signed link in ?t= is old enough (a new one when missing, wrong or expired).
// $confirm (limit rows): the download will use one of the patron's downloads, so the page first asks "Use one of your
// downloads?"; its Yes comes back here with c (m3d_dl_confirm_code()), and the countdown starts then.
// One download at a time: while another download of this visitor counts down, a new one is refused (download_busy_page()).
$wait = m3d_dl_settings()['wait'];
$waitGate = function (?array $confirm = null) use ($wait, $kind, $slug, $n, $item, $link, $here): void {
    $code = m3d_dl_code($kind, $slug, (int) $n);
    $cCode = $confirm !== null ? m3d_dl_confirm_code($code) : null;
    $confirmed = $confirm === null || hash_equals($cCode, m3d_get_text('c'));
    $token = m3d_get_text('t');
    $left = 0;
    if ($wait > 0) {
        $left = $confirmed ? m3d_dl_token_wait($token, $kind, $slug, (int) $n, $wait) : null;
        if ($left === null) {   // a new wait (or the question before it)
            if ($busy = m3d_dl_busy($code)) download_busy_page($kind, $item, $busy);
            $token = m3d_dl_token($kind, $slug, (int) $n);
            $left = $wait;
        }
    }
    // a finished countdown kept from earlier doesn't jump the queue either
    if ($left === 0 && $confirmed && $wait > 0 && ($busy = m3d_dl_busy($code))) download_busy_page($kind, $item, $busy);
    if ($left === 0 && $confirmed) { m3d_dl_free($code); return; }
    if ($confirmed) m3d_dl_hold($code, (string) $item['name'], $left);
    $go = $here . '?' . http_build_query(array_filter(['c' => $cCode, 't' => $wait > 0 && $confirmed ? $token : null]));
    download_wait_page($kind, $item, (string) ($link['label'] ?? ''), $left, $go, $confirmed ? null : $confirm);
};

if (m3d_link_access($item, $link) === 'public') {   // public links, and every link past the item's public date
    $waitGate();
    if (m3d_preview()) preview_download_page($kind, $item, (string) ($link['label'] ?? ''));   // admin previewing as a patron
    // a patron link that is public only because the item's public date has passed: still log a patron's download (as "free",
    // never counted in the limits), so their "↑ Update" tag and My Models follow the version they got
    $p = ($link['access'] ?? 'patrons') === 'patrons' ? m3d_patron($M3D) : null;
    if ($p && !empty($p['access'])) {
        m3d_log_download($M3D, $p, ($kind === 'outfits' ? 'outfit:' : '') . $slug, (string) ($link['label'] ?? ''), m3d_released_label($item), true);
    }
    m3d_count_download($kind, $slug, !($p && !empty($p['access'])));
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

// extra requirements of this model/outfit (minimum tier, time as a patron, total support): only on top of the paid tier
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

// the version logged = the newest one out (one dated in the future isn't yet); "free" = not counted in the limits, even if that changes later
// counted before the log line: with no stats.json yet the counters are first worked out from the log, which would then hold this one too
m3d_count_download($kind, $slug, false);
m3d_log_download($M3D, $patron, ($kind === 'outfits' ? 'outfit:' : '') . $slug, (string) ($link['label'] ?? ''), m3d_released_label($item), !empty($item['noLimits']));
m3d_redirect($link['url']);
