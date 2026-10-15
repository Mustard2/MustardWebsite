<?php
// Preview as a patron (admins only): turns the preview on, changes it or stops it, then goes back to the page.
// The made-up membership lives in the admin's own session (see m3d_preview() in bootstrap.php).
require __DIR__ . '/_admin.php';
admin_require($M3D);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !m3d_check_csrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Session expired, reload the page.');
}
$back = m3d_safe_return($_POST['return'] ?? null, '/');
if (($_POST['do'] ?? '') === 'stop') {
    unset($_SESSION['preview']);
    m3d_redirect($back);
}
$old = $_SESSION['preview'] ?? [];
$tier = (string) ($_POST['tier'] ?? ($old['tier'] ?? 'ok'));
$as = trim((string) ($_POST['as'] ?? ($old['as'] ?? '')));
$total = str_replace(',', '.', trim((string) ($_POST['total'] ?? '')));
$price = str_replace(',', '.', trim((string) ($_POST['price'] ?? '')));
$_SESSION['preview'] = [
    'tier'        => isset(PREVIEW_TIERS[$tier]) ? $tier : 'ok',
    'months'      => max(0, min(999, (int) ($_POST['months'] ?? ($old['months'] ?? 1)))),
    'total_cents' => is_numeric($total) ? max(0, min(99999999, (int) round((float) $total * 100))) : (int) ($old['total_cents'] ?? 500),
    'tier_cents'  => is_numeric($price) && (float) $price > 0 ? min(1000000, (int) round((float) $price * 100)) : (int) ($old['tier_cents'] ?? 500),   // price of the made-up tier
    'dl_models'   => max(0, min(99, (int) ($_POST['dl_models'] ?? ($old['dl_models'] ?? 0)))),
    'dl_outfits'  => max(0, min(99, (int) ($_POST['dl_outfits'] ?? ($old['dl_outfits'] ?? 0)))),
    'updates'     => max(0, min(20, (int) ($_POST['updates'] ?? ($old['updates'] ?? 0)))),   // simulated updates (m3d_patron_history)
    'as'          => preg_match('/^\d{1,20}$/', $as) ? $as : '',
];
// the real start date of the patron below, kept while the months stay the same (m3d_preview_patron())
if (!empty($old['since']) && $_SESSION['preview']['as'] === ($old['as'] ?? '') && $_SESSION['preview']['months'] === (int) ($old['months'] ?? -1)) $_SESSION['preview']['since'] = $old['since'];
// a real Patreon ID (newly typed in the bar, or "Preview as This Patron" on Admin → Downloads, real=1): start from the membership saved
// with their newest download, months counted to today from the saved start
$pv = &$_SESSION['preview'];
if ($pv['as'] !== '' && ($pv['as'] !== ($old['as'] ?? '') || ($_POST['real'] ?? '') === '1') && ($m = m3d_patron_saved_membership($pv['as']))) {
    if ($m['since'] !== '') { $pv['since'] = $m['since'] . 'T12:00:00Z'; $pv['months'] = min(999, (int) m3d_months_since($pv['since'])); }
    elseif ($m['months'] !== null) $pv['months'] = min(999, $m['months']);
    if ($m['tier_cents']) $pv['tier_cents'] = min(1000000, $m['tier_cents']);
    if ($m['total_cents'] !== null) $pv['total_cents'] = min(99999999, $m['total_cents']);
}
unset($pv);
m3d_redirect($back);
