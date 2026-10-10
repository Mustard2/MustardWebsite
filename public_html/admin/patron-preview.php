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
$_SESSION['preview'] = [
    'tier'        => isset(PREVIEW_TIERS[$tier]) ? $tier : 'ok',
    'months'      => max(0, min(999, (int) ($_POST['months'] ?? ($old['months'] ?? 1)))),
    'total_cents' => is_numeric($total) ? max(0, min(99999999, (int) round((float) $total * 100))) : (int) ($old['total_cents'] ?? 500),
    'dl_models'   => max(0, min(99, (int) ($_POST['dl_models'] ?? ($old['dl_models'] ?? 0)))),
    'dl_outfits'  => max(0, min(99, (int) ($_POST['dl_outfits'] ?? ($old['dl_outfits'] ?? 0)))),
    'updates'     => max(0, min(20, (int) ($_POST['updates'] ?? ($old['updates'] ?? 0)))),   // simulated updates (m3d_patron_history)
    'as'          => preg_match('/^\d{1,20}$/', $as) ? $as : '',
];
m3d_redirect($back);
