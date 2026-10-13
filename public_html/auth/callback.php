<?php
// Step 2: Patreon sends the visitor back here. We check their membership once,
// remember the result in the session, and send them where they were going.
require dirname(__DIR__) . '/app/_init.php';

$return = m3d_safe_return($_SESSION['return_to'] ?? null);
$fail = fn(string $why) => m3d_redirect('/patreon-required/?reason=' . $why . '&return=' . rawurlencode($return));

// Visitor pressed "Deny" on Patreon, or the state doesn't match (expired / forged request, or no session cookie): never back to
// Patreon from here (it would loop). A return address that logs in by itself (a download link, the admin) becomes the page around it.
$state = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state'], $_SESSION['return_to']);
$back = match (true) {
    (bool) preg_match('#^/get/still/#', $return)                      => '/gallery/',
    (bool) preg_match('#^/get/outfit/([a-z0-9-]+)/#', $return, $m)    => m3d_item_url('outfits', $m[1]),
    (bool) preg_match('#^/get/([a-z0-9-]+)/#', $return, $m)           => m3d_item_url('models', $m[1]),
    str_starts_with($return, '/admin')                                => '/',
    default                                                           => $return,
};
if (isset($_GET['error'])) m3d_redirect(m3d_setup_needed() ? '/setup/?login=denied' : $back);
if ($state === '' || !is_string($_GET['state'] ?? null) || !hash_equals($state, $_GET['state']) || !is_string($_GET['code'] ?? null) || $_GET['code'] === '') {
    m3d_redirect(m3d_setup_needed() ? '/setup/' : $back);
}

// Exchange the one-time code for an access token
// (the calls to Patreon run with the login unlocked, m3d_session_pause(): a slow answer doesn't hold up this person's other pages)
$tok = m3d_session_pause(fn() => m3d_http('POST', M3D_PATREON . '/api/oauth2/token', ['Content-Type: application/x-www-form-urlencoded'], [
    'code'          => (string) $_GET['code'],
    'grant_type'    => 'authorization_code',
    'client_id'     => $M3D['client_id'],
    'client_secret' => $M3D['client_secret'],
    'redirect_uri'  => $M3D['redirect_uri'],
]));
$access = $tok['json']['access_token'] ?? null;
if ($tok['status'] !== 200 || !$access) {
    m3d_problem('Login', 'Patreon login failed (token): HTTP ' . $tok['status'] . ' ' . $tok['error']);
    $fail('error');
}

// Ask Patreon who this is and which tiers they hold on our campaign
$setup = m3d_setup_needed() && !empty($_SESSION['setup_ok']);   // first-use setup: this account becomes the owner
$me = m3d_session_pause(fn() => m3d_http('GET', m3d_identity_url($setup), ['Authorization: Bearer ' . $access]));
if ($me['status'] !== 200 || !$me['json']) {
    m3d_problem('Login', 'Patreon login failed (identity): HTTP ' . $me['status'] . ' ' . $me['error']);
    $fail('error');
}

$result = m3d_evaluate_identity($me['json'], $M3D);
if ($setup && $result['id'] !== '') {
    m3d_save_settings(['owners' => [$result['id']]]);
    // the creator's own campaign, when Patreon gives it (scope "campaigns"); else the setup asks for its id
    $cid = (string) ($me['json']['data']['relationships']['campaign']['data']['id'] ?? '');
    if (ctype_digit($cid) && !m3d_cfg_real($M3D['campaign_id'] ?? '')) m3d_patreon_save(array_merge(m3d_patreon_saved(), ['campaign_id' => $cid]));
    @unlink(M3D_PRIVATE . '/setup-code.txt');
    unset($_SESSION['setup_ok']);
    $result = ['access' => true, 'reason' => 'owner'] + $result;
    $return = '/setup/';
}
// remember the campaign currency (used to show the "total support" requirements); saved only when it changes
if (!empty($result['currency']) && ($result['currency'] !== (m3d_settings()['currency'] ?? null))) m3d_save_settings(['currency' => $result['currency']]);
// and its paid tiers (names + prices): the editors offer them for a model's minimum tier; saved only when one is new or renamed
m3d_learn_tiers($result['tier_list'] ?? []);
session_regenerate_id(true);
$_SESSION['patron'] = $result + ['checked_at' => time()];
$_SESSION['started'] = time();   // the login lasts m3d_session_lifetime() from here (bootstrap.php)

if (!empty($M3D['debug'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "DEBUG MODE (set 'debug' => false in config.php when done)\n\n";
    echo "Decision: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    echo "Your Patreon user id: {$result['id']}\n";
    echo "Campaign ids in this response: ";
    foreach ($me['json']['included'] ?? [] as $r) if (($r['type'] ?? '') === 'campaign') echo $r['id'] . ' ';
    echo "\n\nRaw Patreon answer:\n" . json_encode($me['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!m3d_apply_exception($result)['access']) $fail($result['reason']);   // Admin → Access exceptions ("Bypass Tier") count here too
m3d_redirect($return);
