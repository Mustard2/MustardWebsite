<?php
// Step 1: send the visitor to Patreon to log in.
require dirname(__DIR__) . '/app/_init.php';

m3d_start_session($M3D);   // the session (and its cookie) starts here, when someone chooses to log in
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));
$_SESSION['return_to'] = m3d_safe_return($_GET['return'] ?? null);

m3d_redirect(M3D_PATREON . '/oauth2/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id'     => $M3D['client_id'],
    'redirect_uri'  => $M3D['redirect_uri'],
    'scope'         => m3d_setup_needed() && !empty($_SESSION['setup_ok']) ? 'identity campaigns' : $M3D['scope'],   // first-use setup: also the own campaign
    'state'         => $_SESSION['oauth_state'],
]));
