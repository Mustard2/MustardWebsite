<?php
// Core of the site: config, session, Patreon gate, data storage. Included by every entry point.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

// A fresh install has only config.example.php: it becomes config.php, and the first-use setup (/setup/) asks for the rest
if (!is_file(M3D_PRIVATE . '/config.php') && !(is_file(M3D_PRIVATE . '/config.example.php') && @copy(M3D_PRIVATE . '/config.example.php', M3D_PRIVATE . '/config.php'))) {
    http_response_code(500);
    exit('Setup incomplete: copy config.example.php to config.php in the private folder.');
}
$M3D = require M3D_PRIVATE . '/config.php';
// The Patreon keys come only from private/patreon.php (first-use setup, Admin → Settings → Patreon): any left in an old config.php don't count
$M3D = array_merge($M3D, ['client_id' => '', 'client_secret' => '', 'redirect_uri' => '', 'campaign_id' => ''], m3d_patreon_saved());
$GLOBALS['M3D'] = $M3D;   // also reachable from functions

// PHP warnings never go into pages (they show server paths): the host's error log and Site problems get them instead
if (PHP_SAPI !== 'cli-server') ini_set('display_errors', '0');

// a PHP crash (fatal error, uncaught exception) goes into Settings → Maintenance → Site problems too, not only the host's error log
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR))) return;
    $where = str_replace(M3D_SITE . '/', '', (string) $e['file']) . ':' . $e['line'];
    $msg = preg_replace('# in /\S+:\d+$#', '', (string) strtok((string) $e['message'], "\n"));   // the first line, without the server path: the stack trace has more of them
    m3d_problem('PHP', "$msg ($where)", false);
});

/** A config value that is really filled in (not empty, not one of config.example.php's placeholders). */
function m3d_cfg_real(mixed $v): bool
{
    $v = trim((string) $v);
    return $v !== '' && !str_starts_with($v, 'PASTE_') && $v !== '0000000' && !str_contains($v, 'xyz.xy')
        && !str_contains($v, 'example.com') && $v !== 'Your full name' && !str_contains($v, '12345 City');
}

/** The admins: owner_user_ids of config.php, plus the owner the first-use setup saved (settings.json → owners). */
function m3d_owner_ids(array $cfg): array
{
    $ids = array_merge((array) ($cfg['owner_user_ids'] ?? []), (array) (m3d_settings()['owners'] ?? []));
    return array_values(array_unique(array_filter(array_map(fn($i) => trim((string) $i), $ids), fn($i) => preg_match('/^\d{1,20}$/', $i))));
}

/** No admin yet: every page sends to the first-use setup (/setup/, setup.php). */
function m3d_setup_needed(): bool
{
    return m3d_owner_ids($GLOBALS['M3D']) === [];
}

/** Who runs the site, for the privacy page: Admin → Settings → Privacy → Legal & Contact (settings.json → legal), else config.php → legal. */
function m3d_legal(): array
{
    $saved = (array) (m3d_settings()['legal'] ?? []);
    $cfg = (array) ($GLOBALS['M3D']['legal'] ?? []);
    $out = [];
    foreach (['name', 'address', 'email'] as $k) {
        $v = trim((string) ($saved[$k] ?? ''));
        $out[$k] = $v !== '' ? $v : (m3d_cfg_real($cfg[$k] ?? '') ? trim((string) $cfg[$k]) : '');
    }
    return $out;
}

/** The website support address (Contacts): Admin → Settings → Privacy → Legal & Contact, else config.php → support_email. */
function m3d_support_email(): string
{
    $v = trim((string) (m3d_settings()['support_email'] ?? ''));
    if ($v !== '') return $v;
    $c = trim((string) ($GLOBALS['M3D']['support_email'] ?? ''));
    return m3d_cfg_real($c) ? $c : '';
}

/**
 * The Patreon keys of Admin → Settings → Patreon: private/patreon.php (a PHP file, so its secret is never readable over the web,
 * outside data/ so it is never in backups or the change history, like config.php). client_id, client_secret, redirect_uri, campaign_id,
 * and creator_token / creator_refresh: the creator's access and refresh tokens of the same client, renewed by m3d_creator_get().
 * Runs before the rest of this file is read (bootstrap's first lines), so the key list is written out here and in m3d_patreon_save().
 */
function m3d_patreon_saved(): array
{
    $f = M3D_PRIVATE . '/patreon.php';
    $d = is_file($f) ? (include $f) : [];
    return is_array($d) ? array_filter(array_intersect_key($d, array_flip(['client_id', 'client_secret', 'redirect_uri', 'campaign_id', 'creator_token', 'creator_refresh'])), fn($v) => is_string($v) && $v !== '') : [];
}

/** Writes private/patreon.php (only the non-empty keys); an empty list deletes it. */
function m3d_patreon_save(array $keys): bool
{
    $f = M3D_PRIVATE . '/patreon.php';
    $keys = array_filter(array_intersect_key($keys, array_flip(['client_id', 'client_secret', 'redirect_uri', 'campaign_id', 'creator_token', 'creator_refresh'])), fn($v) => is_string($v) && $v !== '');
    if (!$keys) return !is_file($f) || @unlink($f);
    $php = "<?php\n// Patreon keys of Admin → Settings → Patreon.\nreturn " . var_export($keys, true) . ";\n";
    $tmp = $f . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    return true;
}

const M3D_PATREON = 'https://www.patreon.com';
const M3D_STATUSES = [
    'released'         => ['Public', 'green'],   // free for everyone (the key stays 'released')
    'preview'          => ['Preview on Patreon', 'mustard'],
    'patreon-only'     => ['Patreon Only', 'violet'],
    'in-development'   => ['In Development', 'grey'],
];

/** A model's status key. */
function m3d_status(array $m): string
{
    $s = (string) ($m['status'] ?? '');
    if (m3d_release_phase($m) === 'free' && $s !== 'in-development') return 'released';   // past its public date: free for everyone
    return $s;
}

function m3d_is_https(): bool
{
    // a proxy / CDN may talk to the server over HTTP while the visitor uses HTTPS
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// ── Session ──────────────────────────────────────────────────────────────────
/** How long a login lasts, in seconds: Admin → Settings → Login Duration (`login_days`), else config 'session_lifetime' (30 days). */
function m3d_session_lifetime(): int
{
    $days = (int) (m3d_settings()['login_days'] ?? 0);
    return $days > 0 ? min(365, $days) * 86400 : max(3600, (int) ($GLOBALS['M3D']['session_lifetime'] ?? 30 * 86400));
}

function m3d_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = M3D_PRIVATE . '/sessions';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    $life = m3d_session_lifetime();
    ini_set('session.gc_maxlifetime', (string) $life);
    ini_set('session.use_strict_mode', '1');
    session_name('m3d_session');
    session_set_cookie_params([
        'lifetime' => $life,
        'path'     => '/',
        'secure'   => m3d_is_https(),
        'httponly' => true,
        'samesite' => 'Lax', // Lax so the cookie survives the redirect back from Patreon
    ]);
    session_start();
    m3d_session_cleanup();
}

/**
 * Deletes the session files of expired logins (PHP's own clean-up depends on the host's settings and may never run):
 * logged in (`started`) longer ago than the login lasts, or unused that long, and logins never finished (no patron: someone,
 * often a crawler following "Log In with Patreon", left at Patreon) after a day. At most once a day unless $force.
 */
function m3d_session_cleanup(bool $force = false): int
{
    $dir = M3D_PRIVATE . '/sessions';
    $mark = $dir . '/.cleaned';
    if (!is_dir($dir) || (!$force && is_file($mark) && filemtime($mark) > time() - 86400)) return 0;
    @touch($mark);
    $cut = time() - m3d_session_lifetime();
    $mine = session_id() !== '' ? 'sess_' . session_id() : '';
    $n = 0;
    foreach (glob($dir . '/sess_*') ?: [] as $f) {
        if (basename($f) === $mine) continue;
        $t = (int) @filemtime($f);
        $body = (string) @file_get_contents($f);
        $old = $t < $cut
            || (preg_match('/(?:^|;|\})started\|i:(\d+);/', $body, $m) && (int) $m[1] < $cut)
            || ($t < time() - 86400 && !preg_match('/(?:^|;|\})patron\|/', $body));
        if ($old && @unlink($f)) $n++;
    }
    return $n;
}

/**
 * Saves and closes the login (session) for the rest of this request: PHP keeps it locked until the request ends, so while
 * a slow job runs (a backup, an update, pictures, a call to Patreon or Discord) every other page this person opens waits,
 * and each waiting page holds one of the host's few PHP processes: with a few of them the whole site stalls for everyone,
 * with no CPU used. $_SESSION stays readable; later writes are not saved (use m3d_session_pause() when some follow).
 */
function m3d_session_release(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));   // forms printed later still need it (m3d_csrf())
    session_write_close();
}

/** Runs a slow job with the login unlocked (m3d_session_release()), then opens it again when nothing was printed yet. */
function m3d_session_pause(callable $fn): mixed
{
    if (session_status() !== PHP_SESSION_ACTIVE) return $fn();
    m3d_session_release();
    try {
        return $fn();
    } finally {
        if (!headers_sent()) m3d_start_session();
    }
}

/** The session cookie only exists for people who log in (privacy policy, § 25 TDDDG): visitors get no cookie at all. */
if (isset($_COOKIE['m3d_session'])) {
    m3d_start_session();
    // a session that isn't a login yet (on its way to Patreon from auth/login.php, the first-use setup, a form's CSRF token)
    // has no `started`: it counts from now, or the callback would find it ended and the login would be lost
    if ($_SESSION && empty($_SESSION['started'])) $_SESSION['started'] = time();
    if (!$_SESSION || time() - (int) $_SESSION['started'] > m3d_session_lifetime()) {
        // expired or ended session: forget the cookie (and the file) instead of keeping an empty one
        $p = session_get_cookie_params();
        setcookie('m3d_session', '', ['expires' => time() - 3600] + array_intersect_key($p, array_flip(['path', 'domain', 'secure', 'httponly', 'samesite'])));
        $_SESSION = [];
        session_destroy();
    }
}

// ── Small helpers ────────────────────────────────────────────────────────────
function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A query value as text: '' when missing or not text (?q[]=… in a crafted address gives an array). */
function m3d_get_text(string $key): string
{
    $v = $_GET[$key] ?? '';
    return is_string($v) ? $v : '';
}

function m3d_redirect(string $to, int $code = 302): never
{
    header('Cache-Control: no-store');
    header('Location: ' . $to, true, $code);
    exit;
}

/** Only allow returning to a path on this site (blocks open redirects). */
function m3d_safe_return(mixed $path, string $fallback = '/'): string   // mixed: ?return[]= from a crafted address is an array
{
    if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
        return $fallback;
    }
    return preg_match('#^/[A-Za-z0-9/_\-.%?=&\#+,~:@!;\[\]()*$|]*$#', $path) ? $path : $fallback;   // + , [ ] ( ) …: ordinary in queries (/search/?q=a+b)
}

function m3d_http(string $method, string $url, array $headers = [], ?array $form = null, int $timeout = 15): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: CatalogSite/1.0'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $status, 'json' => is_array($json) ? $json : null, 'error' => $err];
}

// ── Patreon ──────────────────────────────────────────────────────────────────
/** $ownCampaign (first-use setup only): also the creator's own campaign, to fill in the campaign id (needs the "campaigns" scope). */
function m3d_identity_url(bool $ownCampaign = false): string
{
    return M3D_PATREON . '/api/oauth2/v2/identity?' . http_build_query([
        'include'        => 'memberships,memberships.campaign,memberships.currently_entitled_tiers' . ($ownCampaign ? ',campaign' : ''),
        'fields[user]'   => 'full_name,vanity',
        'fields[member]' => 'patron_status,last_charge_status,last_charge_date,pledge_relationship_start,campaign_lifetime_support_cents,currently_entitled_amount_cents,pledge_cadence',
        'fields[tier]'   => 'title,amount_cents',
        'fields[campaign]' => 'currency',
    ]);
}

/**
 * GET a Patreon API address with the creator's token (Settings → Patreon). An expired token is refreshed once with
 * the refresh token and the client keys, and the new pair saved (Patreon's refresh tokens work once: a lock keeps two logins from
 * spending the same one). ['status' => 0, 'error' => 'no creator token'] when none is saved.
 */
function m3d_creator_get(string $url, int $timeout = 10): array
{
    $tok = (string) (m3d_patreon_saved()['creator_token'] ?? '');
    if ($tok === '') return ['status' => 0, 'json' => null, 'error' => 'no creator token'];
    $r = m3d_http('GET', $url, ['Authorization: Bearer ' . $tok], null, $timeout);
    if ($r['status'] !== 401) return $r;
    $fresh = m3d_creator_refresh($tok);
    return $fresh === null ? $r : m3d_http('GET', $url, ['Authorization: Bearer ' . $fresh], null, $timeout);
}

/** A new creator token for the expired $old (or the one another request already got), saved in patreon.php; null when Patreon refuses. */
function m3d_creator_refresh(string $old): ?string
{
    $lock = @fopen(M3D_PRIVATE . '/data/.creator-token.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $keys = m3d_patreon_saved();
        if (($keys['creator_token'] ?? '') !== $old && ($keys['creator_token'] ?? '') !== '') return $keys['creator_token'];   // refreshed meanwhile
        $cfg = $GLOBALS['M3D'];
        $r = m3d_http('POST', M3D_PATREON . '/api/oauth2/token', [], [
            'grant_type' => 'refresh_token', 'refresh_token' => (string) ($keys['creator_refresh'] ?? ''),
            'client_id' => (string) ($cfg['client_id'] ?? ''), 'client_secret' => (string) ($cfg['client_secret'] ?? ''),
        ]);
        $new = (string) ($r['json']['access_token'] ?? '');
        if ($r['status'] !== 200 || $new === '') {
            m3d_problem('Patreon', 'The creator token expired and could not be renewed: HTTP ' . $r['status'] . ' ' . ($r['json']['error'] ?? $r['error']) . '. Paste the creator’s tokens again in Settings → Patreon.');
            return null;
        }
        m3d_patreon_save(array_merge($keys, ['creator_token' => $new, 'creator_refresh' => (string) ($r['json']['refresh_token'] ?? ($keys['creator_refresh'] ?? ''))]));
        return $new;
    } finally {
        if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

/** The id of the identity's membership of our campaign ('' = none). */
function m3d_identity_member_id(array $identity, array $cfg): string
{
    $inc = [];
    foreach ($identity['included'] ?? [] as $r) $inc[($r['type'] ?? '') . ':' . ($r['id'] ?? '')] = $r;
    foreach ($identity['data']['relationships']['memberships']['data'] ?? [] as $ref) {
        $m = $inc['member:' . ($ref['id'] ?? '')] ?? null;
        if ($m && (string) ($m['relationships']['campaign']['data']['id'] ?? '') === (string) $cfg['campaign_id']) return (string) $m['id'];
    }
    return '';
}

/**
 * A member's pledge events (Patreon resources of type pledge-event), read with the creator's token: Patreon leaves the history out
 * of a patron's own login. null when there is no creator token or Patreon refuses (logged in Site problems).
 */
function m3d_member_pledge_events(string $memberId): ?array
{
    if ($memberId === '' || empty(m3d_patreon_saved()['creator_token'])) return null;
    $r = m3d_creator_get(M3D_PATREON . '/api/oauth2/v2/members/' . rawurlencode($memberId) . '?' . http_build_query([
        'include' => 'pledge_history', 'fields[pledge-event]' => 'type,date,amount_cents',
    ]));
    if ($r['status'] !== 200 || !$r['json']) {
        m3d_problem('Login', rtrim('Pledge history not available (creator token): HTTP ' . $r['status'] . ' ' . $r['error']));
        return null;
    }
    return array_values(array_filter($r['json']['included'] ?? [], fn($x) => ($x['type'] ?? '') === 'pledge-event'));
}

/**
 * When a patron stopped paying and later pledged again, the date of that new pledge (ISO), else null. Patreon's
 * pledge_relationship_start doesn't always move when someone comes back (a patron who paid in 2024 and pledged again in 2026 kept
 * the 2024 date), so the history is read too: a removed pledge ("pledge_delete") or a move to a free membership (a pledge event of
 * 0) ends a run of paying, and the next paid pledge event starts a new one. Monthly charges ("subscription") are ignored, so months
 * without a charge (the creator paused billing, a declined payment) never restart the count.
 */
function m3d_pledge_restart(array $events): ?string
{
    $ev = [];
    foreach ($events as $e) {
        $a = $e['attributes'] ?? [];
        $t = (string) ($a['type'] ?? '');
        $ts = is_string($a['date'] ?? null) ? strtotime($a['date']) : false;
        if ($ts === false || $t === '' || $t === 'subscription') continue;
        $ev[] = [$ts, $t, isset($a['amount_cents']) ? (int) $a['amount_cents'] : null, (string) $a['date']];
    }
    usort($ev, fn($x, $y) => $x[0] <=> $y[0]);
    $ended = false;
    $restart = null;
    foreach ($ev as [, $t, $cents, $date]) {
        if ($t === 'pledge_delete' || ($cents !== null && $cents <= 0)) $ended = true;
        elseif ($ended) { $restart = $date; $ended = false; }   // pledge_start / pledge_upgrade / pledge_downgrade with a paid amount
    }
    return $restart;
}

/**
 * Decides whether a Patreon identity response may download.
 * Rule: active patron of OUR campaign holding at least one PAID tier.
 *  - custom contributions without a tier → no tiers → 'no-tier'
 *  - free members → free tier (0 cents) or no patron status → 'no-tier' / 'not-patron'
 *  - failed payment → 'declined'
 * Returns ['access' => bool, 'reason' => string, 'id' => string, 'name' => string, 'tiers' => string[], plus the membership
 * details used by per-model requirements and the My account page: 'status', 'since' (ISO, start of the current membership),
 * 'total_cents' (everything paid to the campaign), 'entitled_cents', 'cadence' (months between charges),
 * 'last_charge_date', 'last_charge_status', 'currency', 'tier_list' [[title, amount_cents]], 'member' (bool)].
 * These stay in the session only. $events: the member's pledge events (m3d_member_pledge_events()), for 'since'.
 */
function m3d_evaluate_identity(array $identity, array $cfg, ?array $events = null): array
{
    $user = $identity['data'] ?? [];
    $id = (string) ($user['id'] ?? '');
    $name = trim((string) ($user['attributes']['full_name'] ?? '')) ?: 'Patron';
    $out = ['access' => false, 'reason' => 'not-patron', 'id' => $id, 'name' => $name, 'tiers' => [], 'member' => false,
        'status' => null, 'since' => null, 'total_cents' => 0, 'entitled_cents' => 0, 'cadence' => null,
        'last_charge_date' => null, 'last_charge_status' => null, 'currency' => null, 'tier_list' => []];

    if ($id !== '' && in_array($id, m3d_owner_ids($cfg), true)) {
        return ['access' => true, 'reason' => 'owner'] + $out;
    }

    $inc = [];
    foreach ($identity['included'] ?? [] as $r) {
        $inc[($r['type'] ?? '') . ':' . ($r['id'] ?? '')] = $r;
    }

    foreach ($user['relationships']['memberships']['data'] ?? [] as $ref) {
        $member = $inc['member:' . ($ref['id'] ?? '')] ?? null;
        if (!$member) continue;
        $campaignId = (string) ($member['relationships']['campaign']['data']['id'] ?? '');
        if ($campaignId !== (string) $cfg['campaign_id']) continue; // membership to someone else

        $a = $member['attributes'] ?? [];
        $status = $a['patron_status'] ?? null;
        $paidTiers = [];
        foreach ($member['relationships']['currently_entitled_tiers']['data'] ?? [] as $tref) {
            $tier = $inc['tier:' . ($tref['id'] ?? '')] ?? null;
            $cents = (int) ($tier['attributes']['amount_cents'] ?? 0);
            $title = (string) ($tier['attributes']['title'] ?? $tref['id']);
            $out['tier_list'][] = [$title, $cents];
            if ($tier && $cents > 0) $paidTiers[] = $title;
        }
        $out['tiers'] = $paidTiers;
        // the start of the current run of paying: Patreon's date, or a later new pledge found in the history (m3d_pledge_restart())
        $since = $a['pledge_relationship_start'] ?? null;
        $restart = m3d_pledge_restart($events ?? []);   // the history read with the creator's token (m3d_member_pledge_events())
        if ($restart !== null && (!is_string($since) || strtotime($restart) > (int) strtotime($since))) $since = $restart;
        $out = array_merge($out, [
            'member' => true,
            'status' => $status,
            'since' => $since,
            'total_cents' => (int) ($a['campaign_lifetime_support_cents'] ?? 0),
            'entitled_cents' => (int) ($a['currently_entitled_amount_cents'] ?? 0),
            'cadence' => isset($a['pledge_cadence']) ? (int) $a['pledge_cadence'] : null,
            'last_charge_date' => $a['last_charge_date'] ?? null,
            'last_charge_status' => $a['last_charge_status'] ?? null,
            'currency' => $inc['campaign:' . $campaignId]['attributes']['currency'] ?? null,
        ]);

        if ($status === 'declined_patron') return ['reason' => 'declined'] + $out;
        if ($status !== 'active_patron') return ['reason' => 'not-patron'] + $out;
        if (!$paidTiers) return ['reason' => 'no-tier'] + $out;
        return ['access' => true, 'reason' => 'ok'] + $out;
    }
    return $out;
}

// ── Extra download requirements per model/outfit (on top of the paid-tier rule) ──
/** Campaign currency: learned from Patreon at login, else config 'currency', else USD. */
function m3d_currency(): string
{
    $c = strtoupper((string) (m3d_settings()['currency'] ?? ($GLOBALS['M3D']['currency'] ?? 'USD')));
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'USD';
}

function m3d_money(int $cents, ?string $currency = null): string
{
    $cur = $currency ?: m3d_currency();
    $sym = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥', 'AUD' => 'A$', 'CAD' => 'C$'][$cur] ?? '';
    $num = $cents % 100 === 0 ? number_format(intdiv($cents, 100)) : number_format($cents / 100, 2);
    return $sym !== '' ? $sym . $num : "$num $cur";
}

/**
 * The versions of a model: listed models with the same name (ignoring case) that have a major version, newest first,
 * this one included. [] when the model has no major version or is the only one.
 */
function m3d_model_versions(array $m): array
{
    static $groups = null;   // listed models with a major version, by name: built once per page (catalogs ask for every card)
    if (empty($m['majorVersion'])) return [];
    if ($groups === null) {
        $groups = [];
        foreach (m3d_listed_items('models') as $x) if (!empty($x['majorVersion'])) $groups[mb_strtolower(trim((string) $x['name']))][] = $x;
    }
    $out = $groups[mb_strtolower(trim((string) $m['name']))] ?? [];
    if (!in_array($m['slug'], array_column($out, 'slug'), true)) $out[] = $m;   // e.g. a draft or unlisted page itself
    usort($out, fn($a, $b) => (int) $b['majorVersion'] <=> (int) $a['majorVersion']);
    return count($out) > 1 ? $out : [];
}

/** A model's name as lists show it: "Alice v2" when the character has several major versions (m3d_model_versions()), else "Alice". */
function m3d_model_name(array $m): string
{
    return (string) ($m['name'] ?? '') . (m3d_model_versions($m) ? ' v' . (int) $m['majorVersion'] : '');
}

/** Whole months from an ISO date until now (null when unknown). */
function m3d_months_since(?string $iso): ?int
{
    if (!$iso || !($d = date_create_immutable($iso))) return null;
    $diff = $d->diff(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    return $diff->invert ? 0 : $diff->y * 12 + $diff->m;
}

/** Lower case without accents ("Pokémon" → "pokemon"), for the searches, like searchFold() in site.js; the short list below only without
 *  the intl extension. */
function m3d_fold(string $s): string
{
    $s = mb_strtolower($s);
    if (class_exists('Normalizer')) return (string) preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($s, Normalizer::FORM_D));
    return strtr($s, ['à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ï' => 'i', 'ó' => 'o', 'ö' => 'o', 'ô' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c',
        'ì' => 'i', 'î' => 'i', 'ò' => 'o', 'ù' => 'u', 'û' => 'u', 'ã' => 'a', 'õ' => 'o', 'å' => 'a', 'ø' => 'o']);
}

/** A file size for people: "840 KB", "12.4 MB", "1.2 GB" (0 = "0 KB"). */
function m3d_size(int $b): string
{
    if ($b <= 0) return '0 KB';
    return $b >= 1073741824 ? sprintf('%.1f GB', $b / 1073741824) : ($b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', max(1, (int) ceil($b / 1024))));
}

function m3d_plural(int $n, string $word, ?string $many = null): string
{
    return $n . ' ' . ($n === 1 ? $word : ($many ?? $word . 's'));
}

/** The extra requirements of a model/outfit: ['months' => int, 'total_cents' => int, 'tier_cents' => int (minimum tier price a month)] (0 = none). */
function m3d_requirements(array $item): array
{
    $r = ['months' => max(0, (int) ($item['requireMonths'] ?? 0)), 'total_cents' => max(0, (int) ($item['requireTotalCents'] ?? 0)),
          'tier_cents' => max(0, (int) ($item['requireTierCents'] ?? 0))];
    // months + total both set: 'and' = both needed (default), 'or' = either one is enough. The minimum tier is always needed.
    return $r + ['mode' => $r['months'] && $r['total_cents'] && ($item['requireMode'] ?? '') === 'or' ? 'or' : 'and'];
}

function m3d_has_requirements(array $item): bool
{
    $r = m3d_requirements($item);
    return $r['months'] || $r['total_cents'] || $r['tier_cents'];
}

/** The patron's tier: the most expensive paid tier they hold, [title, cents], or null. */
function m3d_patron_tier(?array $p): ?array
{
    $best = null;
    foreach ((array) ($p['tier_list'] ?? []) as $t) {
        if (is_array($t) && (int) ($t[1] ?? 0) > 0 && (!$best || (int) $t[1] > $best[1])) $best = [(string) ($t[0] ?? ''), (int) $t[1]];
    }
    return $best;
}

/**
 * The membership the download checks use (requirements, unlock rules, the download box): Patreon's, raised by a "Custom" exception
 * (Admin → Access): ['months' => ?int, 'total' => int (cents), 'tier' => ?[title, cents]]. The exception sets a floor ("at least"):
 * Patreon's own value counts when it is higher. The download log keeps Patreon's real values (m3d_log_download()).
 */
function m3d_patron_counts(?array $p): array
{
    $months = m3d_months_since($p['since'] ?? null);
    $total = (int) ($p['total_cents'] ?? 0);
    $tier = m3d_patron_tier($p);
    $x = (array) ($p['exception'] ?? []);
    if (($x['extra'] ?? '') === 'custom') {
        if (($x['months'] ?? null) !== null) $months = max($months ?? 0, (int) $x['months']);
        if (($x['total_cents'] ?? null) !== null) $total = max($total, (int) $x['total_cents']);
    }
    $tc = (int) ($x['tier_cents'] ?? 0);
    if (($x['tier'] ?? '') === 'custom' && $tc > 0 && (!$tier || $tier[1] < $tc)) $tier = [m3d_known_tiers()[$tc] ?? '', $tc];
    return ['months' => $months, 'total' => $total, 'tier' => $tier];
}

/** Tiers seen at patron logins (settings.json → tiers: {cents: title}), cheapest first: the editor offers them for the minimum tier. */
function m3d_known_tiers(): array
{
    $out = [];
    foreach ((array) (m3d_settings()['tiers'] ?? []) as $c => $t) if ((int) $c > 0) $out[(int) $c] = (string) $t;
    ksort($out);
    return $out;
}

/** Remembers the paid tiers of a patron who just logged in (titles change: the newest wins); saved only when something changed. */
function m3d_learn_tiers(array $tierList): void
{
    $known = m3d_known_tiers();
    $new = $known;
    foreach ($tierList as $t) if (is_array($t) && (int) ($t[1] ?? 0) > 0 && trim((string) ($t[0] ?? '')) !== '') $new[(int) $t[1]] = mb_substr(trim((string) $t[0]), 0, 60);
    if ($new === $known || count($new) > 30) return;
    ksort($new);
    m3d_save_settings(['tiers' => $new]);
}

/** "Fan tier (€10)" when that price is a known tier, else "€10 tier". */
function m3d_tier_label(int $cents, ?string $currency = null): string
{
    $name = m3d_known_tiers()[$cents] ?? '';
    return $name !== '' ? $name . (stripos($name, 'tier') === false ? ' tier' : '') . ' (' . m3d_money($cents, $currency) . ')' : m3d_money($cents, $currency) . ' tier';
}

/**
 * Checks a patron against an item's extra requirements. The paid-tier rule ($patron['access']) is checked separately
 * and always first: these only add conditions. Returns ['ok' => bool, 'unknown' => bool, 'rows' => [[label, need, have, ok]],
 * 'mode' => 'and'|'or' (or: one of the months / total rows is enough), 'tier_ok' => bool, 'mt_ok' => bool (months + total),
 * 'missing' => the rows that block the download].
 * The minimum tier row comes first and is always needed. 'unknown' = the session predates these checks (log in again).
 */
function m3d_check_requirements(array $item, ?array $patron): array
{
    $r = m3d_requirements($item);
    $rows = [];
    if (!$r['months'] && !$r['total_cents'] && !$r['tier_cents']) return ['ok' => true, 'unknown' => false, 'rows' => [], 'mode' => 'and', 'tier_ok' => true, 'mt_ok' => true, 'missing' => []];
    // owners and people with a "Bypass" requirements exception pass every row; a "Custom" one raises their months / total (m3d_patron_counts())
    $owner = ($patron['reason'] ?? '') === 'owner' || ($patron['exception']['extra'] ?? '') === 'bypass';
    $known = $patron !== null && array_key_exists('total_cents', $patron) && (!$r['tier_cents'] || array_key_exists('tier_list', $patron));
    $counts = m3d_patron_counts($patron);
    $months = $patron ? $counts['months'] : null;
    $total = $counts['total'];
    $cur = $patron['currency'] ?? null;   // amounts are in the campaign currency, as Patreon reports them
    $tierOk = true;
    if ($r['tier_cents']) {
        $t = $counts['tier'];
        $tierOk = $owner || ($t && $t[1] >= $r['tier_cents']);
        $rows[] = ['Patreon Tier', m3d_tier_label($r['tier_cents'], $cur) . ' or higher', $t ? ($t[0] !== '' ? $t[0] . ' (' . m3d_money($t[1], $cur) . ')' : m3d_money($t[1], $cur)) : 'none', $tierOk];
    }
    $mt = [];
    if ($r['months']) $mt[] = ['Time as a Patron', 'at least ' . m3d_plural($r['months'], 'month'), $months === null ? '—' : m3d_plural($months, 'month'), $owner || ($months !== null && $months >= $r['months'])];
    if ($r['total_cents']) $mt[] = ['Total Support', 'at least ' . m3d_money($r['total_cents'], $cur), m3d_money($total, $cur), $owner || $total >= $r['total_cents']];
    $mtOk = !$mt || ($r['mode'] === 'or' ? in_array(true, array_column($mt, 3), true) : !in_array(false, array_column($mt, 3), true));
    $rows = array_merge($rows, $mt);
    $missing = array_merge($tierOk ? [] : [$rows[0]], $mtOk ? [] : array_values(array_filter($mt, fn($x) => !$x[3])));
    return ['ok' => $tierOk && $mtOk, 'unknown' => !$owner && !$known, 'rows' => $rows, 'mode' => $r['mode'], 'tier_ok' => $tierOk, 'mt_ok' => $mtOk, 'missing' => $missing];
}

// ── Visibility of a model/outfit: public (default) | blurred | blurred-patrons | password | draft ──
//   blurred:  listed and readable, but every picture is replaced by a tiny blurred copy (blur_src(): the real file never reaches the page)
//   blurred-patrons: the same for visitors, but paying patrons (and admins) see the real pictures
//   password: the page and its downloads open after the password (cookie m3d_unlock); listed with a blurred picture unless 'unlisted'
//   draft:    only admins see it; 404 for everyone else
const M3D_VISIBILITY = [
    'public'   => 'Public',
    'blurred'  => 'Blurred Pictures (Teaser)',
    'blurred-patrons' => 'Blurred Pictures, Clear for Patrons',
    'password' => 'Password Protected',
    'draft'    => 'Draft (Hidden)',
];
const M3D_UNLOCK_COOKIE = 'm3d_unlock';
const M3D_UNLOCK_DAYS = 30;

function m3d_visibility(array $item): string
{
    $v = (string) ($item['visibility'] ?? 'public');
    return isset(M3D_VISIBILITY[$v]) ? $v : 'public';
}

/** Public or blurred: in the catalogs and the sitemap, announced on Discord, shows updates (not draft or password, not before its patrons date). */
function m3d_is_open(array $item): bool
{
    return in_array(m3d_visibility($item), ['public', 'blurred', 'blurred-patrons'], true) && m3d_release_phase($item) !== 'soon';
}

// ── Release dates (editor → Access → Release dates): patronsFrom / publicFrom (UTC, ISO), releaseBefore: hidden | soon ──
//   before patronsFrom ('soon'): no downloads (admins pass); the page is hidden like a draft, or shown as "Coming soon" (releaseBefore = soon)
//   from patronsFrom until publicFrom ('early'): a normal patron item, with "Free for everyone from …"
//   from publicFrom ('free'): every patron link works like a public one (no login, requirements or limits)
// Worked out at each request, so nothing has to run on the dates themselves, except the Discord posts (m3d_release_run()).
/** ['patrons' => ?int, 'public' => ?int (timestamps), 'before' => hidden|soon] */
function m3d_release(array $item): array
{
    $ts = function ($v): ?int { $t = is_string($v) && $v !== '' ? strtotime($v) : false; return $t === false ? null : $t; };
    return ['patrons' => $ts($item['patronsFrom'] ?? null), 'public' => $ts($item['publicFrom'] ?? null),
            'before' => ($item['releaseBefore'] ?? '') === 'soon' ? 'soon' : 'hidden'];
}

/** 'soon' (before the patrons date), 'early' (patrons only until the public date), 'free' (past the public date), '' (no dates). */
function m3d_release_phase(array $item, ?int $now = null): string
{
    $r = m3d_release($item);
    $now ??= time();
    if ($r['patrons'] !== null && $now < $r['patrons']) return 'soon';
    if ($r['public'] !== null) return $now < $r['public'] ? 'early' : 'free';
    return '';
}

/** Hidden until its patrons date (releaseBefore = hidden): treated like a draft, admins only. */
function m3d_release_hidden(array $item): bool
{
    return m3d_release_phase($item) === 'soon' && m3d_release($item)['before'] === 'hidden';
}

/** Who a link is for right now: past the item's public date every link is public. */
function m3d_link_access(array $item, array $link): string
{
    return ($link['access'] ?? 'patrons') === 'public' || m3d_release_phase($item) === 'free' ? 'public' : 'patrons';
}

const SITE_TZ_DEFAULT = 'Europe/Berlin';

/** The site's own time zone (Admin → Settings → Site → Time Zone, settings.json → timezone; default Europe/Berlin): release dates typed in
 *  the editor, changelog days, "today", daily counts, the admin's times. Visitors see moments in their own zone (when_html() in view.php). */
function m3d_tz(): DateTimeZone
{
    static $tz = [];
    $id = m3d_tz_id();
    return $tz[$id] ??= new DateTimeZone($id);
}

/** The site's time zone name ("Europe/Berlin"); a saved name PHP doesn't know falls back to the default. */
function m3d_tz_id(): string
{
    static $known = [];
    $id = m3d_settings()['timezone'] ?? '';
    if (!is_string($id) || $id === '') return SITE_TZ_DEFAULT;
    return ($known[$id] ??= in_array($id, DateTimeZone::listIdentifiers(), true)) ? $id : SITE_TZ_DEFAULT;
}

/** A moment in the site's time zone, in the date() format $fmt: $t = a timestamp, or a date text ("2026-10-14T09:00:00Z"; '' or unreadable = ''). */
function m3d_site_time(int|string $t, string $fmt): string
{
    $d = is_int($t) || ctype_digit($t) ? new DateTimeImmutable('@' . $t) : ($t === '' ? false : date_create_immutable($t));
    return $d ? $d->setTimezone(m3d_tz())->format($fmt) : '';
}

/** Today's date in the site's time zone: "2026-10-14". */
function m3d_today(): string
{
    return m3d_site_time(time(), 'Y-m-d');
}

/** A calendar day stored as "2026-04-17" in words ("17 Apr 2026"), as it is: no time zone moves it to another day. */
function m3d_day_text(string $day, string $fmt = 'j M Y'): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
    return $d ? $d->format($fmt) : $day;
}

/** "Tue 20 Oct, 18:00 CEST" (site time: Discord, admin); $time false = "Tue 20 Oct". Pages for visitors use when_html(). */
function m3d_release_when(int $ts, bool $time = true): string
{
    return m3d_site_time($ts, $time ? 'D j M, H:i T' : 'D j M');
}

// The Discord posts of the release dates: settings.json → release {done: [event codes], next: timestamp of the earliest one not done}.
// An event is "kind:slug:p|e|v:<timestamp>" (p = patrons date, e = everyone date, v = the day of the item's top changelog
// version, so a version dated in the future is posted on its day). The first page view after `next`
// (layout_start() → m3d_release_tick()) posts them once the page is sent: patrons date and version day = the usual
// new-version post, everyone date = "now free". A date typed in the past posts nothing.

/** Every release date of every item: [code => [kind, slug, 'p'|'e'|'v', ts]]. */
function m3d_release_events(?array $only = null): array
{
    $out = [];
    foreach (['models', 'outfits'] as $kind) {
        if ($only !== null && $only[0] !== $kind) continue;
        foreach ($only !== null ? $only[1] : m3d_items($kind) as $it) {
            $r = m3d_release($it);
            $v = m3d_changelog_ts((string) (m3d_versions($it)[0]['date'] ?? ''));
            foreach (['p' => $r['patrons'], 'e' => $r['public'], 'v' => $v] as $w => $ts) if ($ts !== null) $out["$kind:{$it['slug']}:$w:$ts"] = [$kind, (string) $it['slug'], $w, $ts];
        }
    }
    return $out;
}

/** Saves the release state: done codes (only those still in use) and the next due time. False when it couldn't be saved. */
function m3d_release_store(array $done): bool
{
    $events = m3d_release_events();
    $done = array_values(array_intersect(array_unique($done), array_keys($events)));
    $next = null;
    foreach ($events as $code => $e) if (!in_array($code, $done, true) && ($next === null || $e[3] < $next)) $next = $e[3];
    $old = m3d_settings(true)['release'] ?? null;
    $new = ($done || $next !== null) ? ['done' => $done, 'next' => $next] : null;
    return $old == $new || m3d_save_settings(['release' => $new]);   // compare what is stored: no write (and no settings backup) when nothing changed
}

/** After a save (m3d_save_items): a date typed in the past posts nothing; dates that passed while nobody visited still post. */
function m3d_release_after_save(string $kind, array $old, array $new): void
{
    try {
        $before = m3d_release_events([$kind, $old]);
        $done = (array) (m3d_settings(true)['release']['done'] ?? []);
        foreach (m3d_release_events([$kind, $new]) as $code => $e) if ($e[3] <= time() && !isset($before[$code])) $done[] = $code;
        m3d_release_store($done);
    } catch (Throwable $ex) {
        m3d_problem('Release dates', $ex->getMessage());
    }
}

/** Runs $job after the page has been sent (the visitor never waits for it), with the session closed so other pages aren't held up. */
function m3d_after_response(callable $job): void
{
    register_shutdown_function(function () use ($job) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        m3d_session_release();
        $job();
    });
}

/** Runs $job holding the lock file data/<name>; nothing when another request holds it (it is already doing the job). */
function m3d_with_lock(string $name, callable $job): void
{
    $lock = @fopen(M3D_PRIVATE . '/data/' . $name, 'c');
    if (!$lock) return;
    try {
        if (flock($lock, LOCK_EX | LOCK_NB)) $job();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Called on every public page: when a release date has passed, posts its Discord message after the page is sent. */
function m3d_release_tick(): void
{
    $next = m3d_settings()['release']['next'] ?? null;
    if (!is_int($next) || $next > time()) return;
    m3d_after_response(fn() => m3d_release_run());
}

/** Posts the release dates that have passed (once, under a lock). */
function m3d_release_run(): void
{
    m3d_with_lock('.release-lock', function () {   // another request on it: nothing to do
        try {
            $done = (array) (m3d_settings(true)['release']['done'] ?? []);
            $due = [];
            foreach (m3d_release_events() as $code => [$kind, $slug, $w, $ts]) {
                if ($ts > time() || in_array($code, $done, true)) continue;
                $done[] = $code;
                if ($w === 'v' && $ts < time() - 3 * 86400) continue;   // a version day long gone (first run after this was added): nothing to post
                $due[] = [$kind, $slug, $w];
            }
            // marked as done before posting: when the settings can't be written (disk full), nothing is posted, rather than the
            // same post on every page view
            if (!m3d_release_store($done)) { m3d_problem('Release dates', 'The release state could not be saved: nothing was posted.'); return; }
            foreach ($due as [$kind, $slug, $w]) {
                $item = m3d_item($kind, $slug);
                if (!$item || !m3d_discord_on() || !m3d_is_open($item)) continue;
                if ($kind === 'models' && m3d_status($item) === 'in-development') continue;   // not downloadable: nothing to announce yet
                if ($w !== 'e') m3d_discord_after_save($kind, [['visibility' => 'draft'] + $item], [$item]);   // as if just published (posts only a version not sent yet)
                elseif (m3d_release_phase($item) === 'free') m3d_discord_free($kind, $item);
            }
        } catch (Throwable $ex) {
            m3d_problem('Release dates', $ex->getMessage());
        }
    });
}

/** "12 Oct" (site time). */
function m3d_release_day(int $ts): string
{
    return m3d_site_time($ts, 'j M');
}

/** "in 2 days", "in 5 hours", "in 12 minutes" */
function m3d_release_in(int $ts): string
{
    $s = max(60, $ts - time());
    if ($s >= 2 * 86400) return 'in ' . m3d_plural((int) round($s / 86400), 'day');
    if ($s >= 3600) return 'in ' . m3d_plural(intdiv($s, 3600), 'hour');
    return 'in ' . m3d_plural(intdiv($s, 60), 'minute');
}

/** Cookie entry proving the password of one item was entered. Changing the password (new hash) invalidates it. */
function m3d_unlock_sig(string $kind, array $item): string
{
    $key = m3d_secret('unlock');
    $k = $kind === 'outfits' ? 'o' : 'm';
    return $k . '.' . $item['slug'] . '.' . substr(hash_hmac('sha256', $k . '|' . $item['slug'] . '|' . ($item['passwordHash'] ?? ''), $key), 0, 20);
}

/** Password items: admin, or the visitor entered the password. Other items: always true. */
function m3d_is_unlocked(string $kind, array $item): bool
{
    if (m3d_visibility($item) !== 'password' || m3d_is_admin($GLOBALS['M3D'])) return true;
    $have = explode(',', (string) ($_COOKIE[M3D_UNLOCK_COOKIE] ?? ''));
    return in_array(m3d_unlock_sig($kind, $item), $have, true);
}

/** Checks the password; when right, remembers it in the m3d_unlock cookie (only set at this request of the visitor). */
function m3d_unlock(string $kind, array $item, string $password): bool
{
    $hash = (string) ($item['passwordHash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        usleep(600000);   // slows down guessing
        return false;
    }
    $sig = m3d_unlock_sig($kind, $item);
    $k = strtok($sig, '.') . '.' . $item['slug'] . '.';
    // keep other items' entries (dropping an old one for this item), at most 40 so the cookie stays small
    $list = array_filter(explode(',', (string) ($_COOKIE[M3D_UNLOCK_COOKIE] ?? '')), fn($s) => preg_match('/^[mo]\.[a-z0-9-]+\.[0-9a-f]{20}$/', $s) && !str_starts_with($s, $k));
    $list = array_slice(array_values($list), -39);
    $list[] = $sig;
    $value = implode(',', $list);
    setcookie(M3D_UNLOCK_COOKIE, $value, ['expires' => time() + M3D_UNLOCK_DAYS * 86400, 'path' => '/', 'secure' => m3d_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE[M3D_UNLOCK_COOKIE] = $value;
    return true;
}

/** May the current visitor open the page / downloads? Drafts: admins only. Password: after the password. */
function m3d_can_view(string $kind, array $item): bool
{
    if (m3d_release_hidden($item) && !m3d_is_admin($GLOBALS['M3D'])) return false;
    return match (m3d_visibility($item)) {
        'draft'    => m3d_is_admin($GLOBALS['M3D']),
        'password' => m3d_is_unlocked($kind, $item),
        default    => true,
    };
}

/** Shown in catalogs and lists (homepage, outfits, model pages)? Drafts and unlisted password items are not. */
function m3d_listed(array $item): bool
{
    $v = m3d_visibility($item);
    return $v !== 'draft' && !($v === 'password' && !empty($item['unlisted'])) && !m3d_release_hidden($item);
}

/** Pictures replaced by blurred copies: blurred items, "clear for patrons" items for everyone who isn't a paying
 *  patron or admin, and password items still locked for this visitor. */
function m3d_pics_hidden(string $kind, array $item): bool
{
    return match (m3d_visibility($item)) {
        'blurred'         => true,
        'blurred-patrons' => !m3d_sees_patron_pics(),
        'password'        => !m3d_is_unlocked($kind, $item),
        default           => false,
    };
}

/** Paying patron (same rule as downloads and likes) or admin. */
function m3d_sees_patron_pics(): bool
{
    $p = m3d_patron($GLOBALS['M3D']);
    return ($p !== null && !empty($p['access'])) || (m3d_is_admin($GLOBALS['M3D']) && !m3d_preview());
}

/** The models/outfits the catalogs show. */
function m3d_listed_items(string $kind): array
{
    return array_values(array_filter(m3d_items($kind), 'm3d_listed'));
}

/** Current patron from the session, or null if not logged in / check expired. */
function m3d_patron(array $cfg, bool $requireFresh = false): ?array
{
    $p = $_SESSION['patron'] ?? null;
    if (!is_array($p)) return null;
    if ($requireFresh && (time() - (int) ($p['checked_at'] ?? 0)) > (int) ($cfg['recheck_after'] ?? 43200)) return null;
    // the admins list changed since this login (config or Settings): the owner pass follows the current list
    $isOwner = in_array((string) ($p['id'] ?? ''), m3d_owner_ids($cfg), true);
    if (($p['reason'] ?? '') === 'owner' && !$isOwner) return null;   // no longer an admin: logs in again, for a fresh check of their tier
    if ($isOwner && ($p['reason'] ?? '') !== 'owner') $p = ['access' => true, 'reason' => 'owner'] + $p;
    if (($pv = m3d_preview()) !== null) $p = m3d_preview_patron($p, $pv);   // admin previewing the site as a patron
    return m3d_apply_exception($p);
}

// ── Preview as a patron (admins only): the bar on top of the site sets a made-up membership ──
// $_SESSION['preview'] = {tier: ok|no-tier|not-patron|declined, tier_cents, months, total_cents, dl_models, dl_outfits (made-up downloads
// of other items, a minute ago, so they count in every period), updates (m3d_preview_updates()), as: Patreon id or '', since}. While it is on,
// m3d_patron() returns that patron instead of the admin (with the download history and user exception of `as`, if set),
// so every page, the download box, requirements, limits and My account behave as for them. m3d_is_admin() keeps reading
// the real login. Downloads in preview stop at a "would start now" page: nothing is downloaded, logged or counted.
const PREVIEW_TIERS = ['ok' => 'Paid Tier', 'no-tier' => 'Support Without a Tier', 'declined' => 'Payment Declined', 'not-patron' => 'Not a Member'];

function m3d_preview(): ?array
{
    $pv = $_SESSION['preview'] ?? null;
    return is_array($pv) && m3d_is_admin($GLOBALS['M3D']) ? $pv : null;
}

/** The date $n months ago, a day earlier (so m3d_months_since() reads exactly $n); the day is kept inside the month (31 March − 1 month = 28 February). */
function m3d_months_ago(int $n): string
{
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $first = $now->modify('first day of this month')->modify("-$n months");
    $d = $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min((int) $now->format('j'), (int) $first->format('t')));
    return $d->format('c');
}

function m3d_preview_patron(array $real, array $pv): array
{
    $tier = isset(PREVIEW_TIERS[$pv['tier'] ?? '']) ? $pv['tier'] : 'ok';
    $months = max(0, (int) ($pv['months'] ?? 0));
    $member = $tier !== 'not-patron';
    $price = max(1, (int) ($pv['tier_cents'] ?? 500));   // the made-up tier's price: a known tier's name when one has that price
    $title = m3d_known_tiers()[$price] ?? 'Preview tier';
    return [
        'id' => ($pv['as'] ?? '') !== '' ? (string) $pv['as'] : 'preview', 'name' => 'Preview',
        'access' => $tier === 'ok', 'reason' => $tier, 'tiers' => $tier === 'ok' ? [$title] : [],
        'member' => $member, 'status' => $member ? ($tier === 'declined' ? 'declined_patron' : 'active_patron') : null,
        'since' => !$member ? null : (is_string($pv['since'] ?? null) && m3d_months_since($pv['since']) === $months ? $pv['since'] : m3d_months_ago($months)),   // a real patron's start (patron-preview.php), else made up
        'total_cents' => max(0, (int) ($pv['total_cents'] ?? 0)), 'entitled_cents' => $tier === 'ok' ? $price : 0, 'cadence' => $tier === 'ok' ? 1 : null,
        'last_charge_date' => null, 'last_charge_status' => $tier === 'declined' ? 'Declined' : null,
        'currency' => $real['currency'] ?? null, 'tier_list' => $tier === 'ok' ? [[$title, $price]] : [],
        'checked_at' => time(), 'preview' => true,
        'preview_dl' => ['models' => max(0, (int) ($pv['dl_models'] ?? 0)), 'outfits' => max(0, (int) ($pv['dl_outfits'] ?? 0))],
        'preview_updates' => max(0, (int) ($pv['updates'] ?? 0)),
    ];
}

// ── Exceptions (Admin → Access): people with special access, who skip or change some download rules ──
// data/exceptions.json: {people: [{id, name, note, tier, extra, limits ('bypass' | 'custom' | absent; true = 'bypass', from before
// "Custom"), tierCents, months, totalCents, rules, added}]}
//   tier:   bypass = downloads (and likes, patron pictures) without a paid tier: free Patreon account, no tier, declined payment;
//           custom = the same, and they count as at least the tier of `tierCents` (requirements, tier rules)
//   extra:  bypass = the extra requirements of models/outfits (minimum tier, months as a patron, total support) aren't checked;
//           custom = they count as at least `months` months as a patron and `totalCents` of total support (empty = Patreon's own);
//           the months also pick the unlock rules by months (m3d_patron_counts())
//   limits: bypass = the unlock limits (Admin → Access → Unlock Limits) don't apply; custom = their own `rules` [{max, what, per}]
//           replace the general ones (m3d_rules_for())
// Applied every time the session is read (m3d_patron), so changes count at once, without logging in again.
function m3d_exceptions(bool $fresh = false): array
{
    static $list = null;
    if ($list !== null && !$fresh) return $list;
    return $list = array_values(array_filter((array) (m3d_read_json('exceptions')['people'] ?? []), fn($x) => is_array($x) && ($x['id'] ?? '') !== ''));
}

function m3d_exception(string $patronId): ?array
{
    if ($patronId === '') return null;
    foreach (m3d_exceptions() as $x) if ((string) $x['id'] === $patronId) return $x;
    return null;
}

function m3d_save_exceptions(array $people): bool
{
    $ok = m3d_write_json('exceptions', ['version' => 1, 'updated' => gmdate('c'), 'people' => array_values($people)]);
    m3d_exceptions(true);
    return $ok;
}

/** An exception's setting: 'bypass', 'custom' or '' (Normal); true from before "Custom" = 'bypass'. */
function m3d_ex_mode($v): string
{
    return $v === 'custom' ? 'custom' : ($v === true || $v === 'bypass' ? 'bypass' : '');
}

/** An exception's own unlock rules, in the shape of the general ones (always on, any tier and months; 'own' for the texts). */
function m3d_ex_rules(array $x): array
{
    $out = [];
    foreach (array_values((array) ($x['rules'] ?? [])) as $i => $r) {
        if (!is_array($r) || !isset(DL_WHAT[$r['what'] ?? '']) || !isset(DL_PERIODS[$r['per'] ?? ''])) continue;
        $out[] = ['id' => 'x' . $i, 'on' => true, 'max' => max(1, min(999, (int) ($r['max'] ?? 1))), 'what' => $r['what'], 'per' => $r['per'], 'from' => '', 'to' => '', 'tier' => '', 'own' => true];
    }
    return $out;
}

/**
 * Adds the exception (if any) to the patron from the session: 'exception' => [tier, extra, limits ('bypass' | 'custom' | ''),
 * tier_cents, months, total_cents (null = not set), rules]; a tier exception also opens downloads.
 */
function m3d_apply_exception(array $p): array
{
    $x = m3d_exception((string) ($p['id'] ?? ''));
    if (!$x || ($p['reason'] ?? '') === 'owner') return $p;
    $num = fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
    $p['exception'] = ['tier' => m3d_ex_mode($x['tier'] ?? null), 'extra' => m3d_ex_mode($x['extra'] ?? null), 'limits' => m3d_ex_mode($x['limits'] ?? null),
                       'tier_cents' => (int) ($x['tierCents'] ?? 0), 'months' => $num($x['months'] ?? null), 'total_cents' => $num($x['totalCents'] ?? null),
                       'rules' => m3d_ex_rules($x)];
    if ($p['exception']['tier'] !== '' && empty($p['access'])) {
        $p['access'] = true;
        $p['denied_reason'] = $p['reason'] ?? null;   // what Patreon said (shown on My account)
        $p['reason'] = 'exception';
    }
    return $p;
}

/** Admin = logged in with a Patreon id of m3d_owner_ids() (checked against the CURRENT config and settings). */
function m3d_is_admin(array $cfg): bool
{
    $p = $_SESSION['patron'] ?? null;   // the real login, also while previewing as a patron
    if (!is_array($p)) return false;
    return $p !== null && ($p['id'] ?? '') !== ''
        && in_array((string) $p['id'], m3d_owner_ids($cfg), true);
}

/**
 * Log line: date (UTC), Patreon id, name, item ("slug" / "outfit:slug"), link label, version downloaded ("Beta 2", "V4"; older
 * lines have none), and "free" when the item was marked "Exclude from Unlock Limits" then (only on those lines: such a
 * download never counts in the limits, even after the mark is removed), or "update" when it unlocked a newer version
 * ("Updates Use an Unlock"): counted in the limits like a first download, even after the switch is turned off.
 */
function m3d_log_download(array $cfg, array $patron, string $model, string $label = '', string $version = '', bool $free = false, bool $update = false): void
{
    if (empty($cfg['log_downloads'])) return;
    $dir = M3D_PRIVATE . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    // one line per download: line breaks and other control characters (a Patreon name can have them) become spaces
    $row = array_map(fn($v) => trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $v)), [gmdate('c'), $patron['id'] ?? '', $patron['name'] ?? '', $model, $label, $version]);
    $row[] = $free ? 'free' : ($update ? 'update' : '');
    // the membership as Patreon gave it at this download (columns 8–11, m3d_log_membership()): months as a patron, since (day), tier and
    // total support in cents; empty when unknown (owners). Admin → Downloads shows it, and "Preview as This Patron" starts from it.
    $since = is_string($patron['since'] ?? null) && ($d = date_create_immutable($patron['since'])) ? $d->format('Y-m-d') : '';
    $tier = m3d_patron_tier($patron);
    $mem = [$since !== '' ? (string) m3d_months_since($patron['since']) : '', $since, $tier ? (string) $tier[1] : '', array_key_exists('total_cents', $patron) && !empty($patron['member']) ? (string) (int) $patron['total_cents'] : ''];
    if (implode('', $mem) !== '') array_push($row, ...$mem);
    elseif ($row[6] === '') array_pop($row);   // old short form: nothing after the version
    m3d_log_locked(function () use ($row) {   // waits while the log is being rewritten (prune, rows removed)
        $fh = @fopen(m3d_log_file(), 'ab');
        if (!$fh) return;
        fputcsv($fh, $row, ',', '"', '');
        fclose($fh);
    });
    m3d_prune_log($cfg);
}

// ── Download wait and limits (Admin → Access → Downloads; settings.json → downloads) ──
//   wait:  seconds of the "Your download starts in…" page before every patron download (0 = off; public links never wait). Enforced by the server with a
//          signed, time-stamped link (m3d_dl_token): no cookie, no session needed, and the real link is never in the page.
//   rules: [{id, on, max, what: models|outfits|all, per: day|week|month, from, to, tier}]: at most `max` DIFFERENT items per
//          rolling period for patrons whose months as a patron are >= from (if set) and < to (if set), and whose tier
//          (m3d_patron_tier(): their most expensive paid tier) costs exactly `tier` cents (if set; '' = any tier). A patron
//          with a rule for their own tier gets only those rules: the "Any Tier" ones don't apply to them (m3d_rules_for()). Counted from the
//          download log (patron links only): an item counts once, when first downloaded; downloading it again is always
//          free (as long as the log remembers it: log_retention_days).
//   cap:   daily download cap (0 = off): a patron who downloaded this many times within 24 hours (any patron link, downloading
//          again too) can't download for 24 hours after the last of them (m3d_dl_capped()); keeps file hosting traffic in check.
//   updates: true = a newer version of an item already unlocked is unlocked again (Admin → Access → "Updates Use an Unlock"; off
//          by default): its download asks like a new item and is logged with "update" (7th column), which counts it like a first download.
const DL_PERIODS = ['day' => [86400, '24 hours'], 'week' => [604800, '7 days'], 'month' => [2592000, '30 days']];   // rolling: "per 7 days", never "this week"
const DL_WHAT = ['models' => ['model', 'models'], 'outfits' => ['accessory', 'accessories'], 'all' => ['download', 'downloads']];

/** The kinds a limit rule counts ('all' = both). */
function m3d_rule_kinds(array $r): array
{
    $w = (string) ($r['what'] ?? 'models');
    return $w === 'all' ? ['models', 'outfits'] : [$w];
}

/** A limit rule's rolling period: [seconds, "7 days"]. */
function m3d_rule_period(array $r): array
{
    return DL_PERIODS[$r['per'] ?? 'week'] ?? DL_PERIODS['week'];
}

/** The word before "unlock" in the texts: "model ", "accessory ", or '' for a rule that counts both. */
function m3d_rule_noun(array $r): string
{
    $w = (string) ($r['what'] ?? 'models');
    return $w === 'all' ? '' : (DL_WHAT[$w][0] ?? 'model') . ' ';
}

/** Of m3d_check_limits() rows: the tightest (fewest unlocks left), or null. */
function m3d_tightest_row(array $rows): ?array
{
    $t = null;
    foreach ($rows as $x) if (!$t || $x['max'] - $x['used'] < $t['max'] - $t['used']) $t = $x;
    return $t;
}

/** Of m3d_check_limits() rows: the full one that frees up last, which says when the next unlock comes (every full rule must free up). */
function m3d_blocking_row(array $rows): ?array
{
    $full = array_values(array_filter($rows, fn($x) => $x['used'] >= $x['max']));
    usort($full, fn($a, $b) => ($b['next'] ?? 0) <=> ($a['next'] ?? 0));
    return $full[0] ?? null;
}

function m3d_dl_settings(): array
{
    $d = m3d_settings()['downloads'] ?? [];
    return ['wait' => max(0, min(60, (int) ($d['wait'] ?? 10))), 'rules' => array_values(array_filter((array) ($d['rules'] ?? []), 'is_array')),
            'updates' => !empty($d['updates']), 'cap' => max(0, min(999, (int) ($d['cap'] ?? 0)))];
}

/** Daily download cap (downloads.cap): when this patron is blocked, the time downloads open again (24 hours after the download that
 *  reached the cap); null = they can download. Counts every logged download (the log has patron links only); admins are never capped. */
function m3d_dl_capped(?array $patron): ?int
{
    $cap = m3d_dl_settings()['cap'];
    if (!$cap || !$patron || ($patron['reason'] ?? '') === 'owner') return null;
    $times = array_map(fn($r) => (int) $r[0], m3d_patron_history($patron));
    if (!$times) return null;
    $last = max($times);   // once blocked there are no new downloads, so the last one is the one that reached the cap
    if ($last <= time() - 86400) return null;
    return count(array_filter($times, fn($t) => $t > $last - 86400)) >= $cap ? $last + 86400 : null;
}

/** "<time>-<signature>" for one download link, made when the waiting page is shown. */
function m3d_dl_token(string $kind, string $slug, int $n, ?int $issued = null): string
{
    $issued ??= time();
    return $issued . '-' . substr(hash_hmac('sha256', "$kind|$slug|$n|$issued", m3d_secret('download')), 0, 24);
}

/** Seconds still to wait for a token: 0 = go, >0 = too early, null = invalid or expired (show a new waiting page). */
function m3d_dl_token_wait(string $token, string $kind, string $slug, int $n, int $wait): ?int
{
    if (!preg_match('/^(\d{9,11})-[0-9a-f]{24}$/', $token, $m)) return null;
    $issued = (int) $m[1];
    if (!hash_equals(m3d_dl_token($kind, $slug, $n, $issued), $token)) return null;
    $age = time() - $issued;
    if ($age < 0 || $age > $wait + 900) return null;   // a waiting page left open for more than 15 minutes starts over
    return max(0, $wait - $age);
}

// One download at a time: while a waiting page counts down, the visitor's other downloads are refused with download_busy_page()
// (other tabs too). Kept in the login (session → dl_busy {code, name, until}), so only for logged-in visitors,
// which every patron link needs; visitors who aren't logged in have no session, and public links stay as they are for them.
function m3d_dl_code(string $kind, string $slug, int $n): string
{
    return "$kind:$slug:$n";
}

/**
 * The `c` value of the "Unlock this model?" Yes link: tied to this patron and this link, so a copied address (the one shown after
 * Yes) doesn't skip the question for someone else.
 */
function m3d_dl_confirm_code(string $code): string
{
    $id = (string) (m3d_patron($GLOBALS['M3D'])['id'] ?? '');
    return substr(hash_hmac('sha256', "confirm|$id|$code", m3d_secret('download')), 0, 16);
}

/** The other download still counting down for this visitor ({name, left} in seconds), or null. */
function m3d_dl_busy(string $code): ?array
{
    $b = $_SESSION['dl_busy'] ?? null;
    if (!is_array($b) || ($b['code'] ?? '') === $code) return null;
    $left = (int) ($b['until'] ?? 0) - time();
    return $left > 0 ? ['name' => (string) ($b['name'] ?? ''), 'left' => $left] : null;
}

/** A waiting page for $code is counting down: the other downloads wait $left seconds. */
function m3d_dl_hold(string $code, string $name, int $left): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || $left <= 0) return;
    $_SESSION['dl_busy'] = ['code' => $code, 'name' => $name, 'until' => time() + $left];
}

/** $code downloads now: the others are free again. */
function m3d_dl_free(string $code): void
{
    if (session_status() === PHP_SESSION_ACTIVE && ($_SESSION['dl_busy']['code'] ?? '') === $code) unset($_SESSION['dl_busy']);
}

// The same link again soon after (the waiting page's Download button pressed after the countdown already started the file, the Back
// button reopening the waiting page, a download manager asking twice): the file is sent again but not counted or logged a second time,
// so the counters, the log and the daily cap see one download. Kept in the login (session → dl_done {code: time}), like dl_busy.
const DL_REPEAT_SECS = 600;

/** This visitor downloaded $code less than DL_REPEAT_SECS ago. */
function m3d_dl_repeat(string $code): bool
{
    $at = (int) ($_SESSION['dl_done'][m3d_dl_done_key($code)] ?? 0);
    return $at > time() - DL_REPEAT_SECS;
}

/** $code was downloaded now (older entries are dropped). */
function m3d_dl_done(string $code): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $done = array_filter(is_array($_SESSION['dl_done'] ?? null) ? $_SESSION['dl_done'] : [], fn($t) => (int) $t > time() - DL_REPEAT_SECS);
    $done[m3d_dl_done_key($code)] = time();
    $_SESSION['dl_done'] = $done;
}

/** dl_done's key: the link + who downloaded it (another Patreon account logged in the same browser, or a preview, isn't a repeat). */
function m3d_dl_done_key(string $code): string
{
    return $code . '|' . (string) (m3d_patron($GLOBALS['M3D'])['id'] ?? '');
}

/**
 * "1 model unlock per 7 days for patrons with less than 3 months" (an unlock = the first download of an item).
 * $short (My account): "1 model / 7 days · under 3 months", "5 unlocks / 30 days · everyone".
 */
function m3d_rule_text(array $r, bool $short = false): string
{
    $what = isset(DL_WHAT[$r['what'] ?? '']) ? $r['what'] : 'models';
    $max = max(1, (int) ($r['max'] ?? 1));
    $per = m3d_rule_period($r)[1];
    $tier = (int) ($r['tier'] ?? 0) > 0 ? m3d_tier_label((int) $r['tier']) : '';   // "Fan tier (€5)"
    if ($short) {
        $from = ((int) ($r['from'] ?? 0)) ?: null;   // 0 = everyone, like empty
        $to = isset($r['to']) && $r['to'] !== '' ? (int) $r['to'] : null;
        $who = match (true) {
            $from !== null && $to !== null => "{$from}–" . m3d_plural($to, 'month'),
            $to !== null                   => 'under ' . m3d_plural($to, 'month'),
            $from !== null                 => "$from+ months",
            !empty($r['own'])              => 'just for you',   // an exception's own rule (m3d_ex_rules())
            default                        => $tier !== '' ? '' : 'everyone',
        };
        $nb = fn(string $t) => str_replace(' ', "\u{a0}", $t);   // "3+ months", "30 days" never split over two lines
        $who = $tier !== '' ? $tier . ($who !== '' ? ' · ' . $nb($who) : '') : $nb($who);   // a long tier name may wrap
        return $max . "\u{a0}" . ($what === 'all' ? ($max === 1 ? 'unlock' : 'unlocks') : DL_WHAT[$what][$max === 1 ? 0 : 1]) . ' / ' . $nb($per) . ' · ' . $who;
    }
    $text = $max . ' ' . ($what === 'all' ? '' : DL_WHAT[$what][0] . ' ') . ($max === 1 ? 'unlock' : 'unlocks') . ' per ' . $per;
    $from = ((int) ($r['from'] ?? 0)) ?: null;
    $to = isset($r['to']) && $r['to'] !== '' ? (int) $r['to'] : null;
    $who = $tier !== '' ? "$tier patrons" : 'patrons';
    if ($from !== null && $to !== null) $text .= " for $who with at least $from and less than " . m3d_plural($to, 'month');
    elseif ($to !== null) $text .= " for $who with less than " . m3d_plural($to, 'month');
    elseif ($from !== null) $text .= " for $who with " . m3d_plural($from, 'month') . ' or more';
    else $text .= !empty($r['own']) ? ', set just for you' : ($tier !== '' ? " for every $tier patron" : ' for every patron');
    return $text;
}

/** $r is on and matches a patron with $months as a patron and a tier of $tierCents (null = no paid tier known); whatever it counts. */
function m3d_rule_matches(array $r, int $months, ?int $tierCents): bool
{
    if (empty($r['on'])) return false;
    if (isset($r['from']) && $r['from'] !== '' && $months < (int) $r['from']) return false;
    if (isset($r['to']) && $r['to'] !== '' && $months >= (int) $r['to']) return false;
    if ((int) ($r['tier'] ?? 0) > 0 && (int) $r['tier'] !== $tierCents) return false;
    return true;
}

/**
 * The unlock limit rules of a patron: the ones on, matching their months and their tier. A tier's rules replace the "Any Tier" ones
 * for its patrons (Admin → Access → Unlock Limits), kind by kind: an "Any Tier" rule is left out only when their own tier's rules count
 * everything it counts (models, accessories; "all" counts both), so a tier rule for models leaves the "Any Tier" accessory rules and the
 * "Any Tier" rules for both in place.
 */
function m3d_rules_for(?array $patron): array
{
    // a "Custom" limits exception: their own rules instead of all the general ones
    if (($patron['exception']['limits'] ?? '') === 'custom') return (array) ($patron['exception']['rules'] ?? []);
    $c = m3d_patron_counts($patron);
    return m3d_rules_pick(m3d_dl_settings()['rules'], (int) ($c['months'] ?? 0), $c['tier'][1] ?? null);
}

/** m3d_rules_for() for any months and tier (null = no paid tier known): the welcome page's table asks it for each tier and stretch of months. */
function m3d_rules_pick(array $all, int $months, ?int $tier): array
{
    $rules = array_values(array_filter($all, fn($r) => is_array($r) && m3d_rule_matches($r, $months, $tier)));
    $covered = [];   // the kinds the patron's own tier rules count
    foreach ($rules as $r) if ((int) ($r['tier'] ?? 0) > 0) foreach (m3d_rule_kinds($r) as $k) $covered[$k] = true;
    return array_values(array_filter($rules, fn($r) => (int) ($r['tier'] ?? 0) > 0 || array_diff(m3d_rule_kinds($r), array_keys($covered))));
}

/** A patron's downloads in the log, oldest first: [[timestamp, kind, slug, version, link label, free, update, membership], …] (patron
 *  links only: the log has nothing else; version '' on old lines; free = the item was "Exclude from Unlock Limits" then, update = a newer
 *  version unlocked again ("Updates Use an Unlock"): the 7th column, m3d_log_download(); membership = m3d_log_membership()). */
function m3d_patron_log(string $patronId): array
{
    // read once per page: the limits, the header counter, the unlocked checks and the updates all ask for it
    static $cache = [];
    if (isset($cache[$patronId])) return $cache[$patronId];
    $out = [];
    $fh = @fopen(m3d_log_file(), 'rb');
    if (!$fh || $patronId === '') return $out;
    // plain lines first, parsed only when the patron's id is in them: much faster than parsing the whole log as CSV
    while (($line = fgets($fh)) !== false) {
        if (!str_contains($line, $patronId)) continue;
        $r = m3d_log_cols($line);
        if (($r[1] ?? '') !== $patronId || !isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
        [$k, $s] = m3d_log_item_parts($r[3]);
        $out[] = [$t, $k, $s, (string) ($r[5] ?? ''), (string) ($r[4] ?? ''), ($r[6] ?? '') === 'free', ($r[6] ?? '') === 'update', m3d_log_membership($r)];
    }
    fclose($fh);
    return $cache[$patronId] = $out;
}

/**
 * Patreon users the site has met, for finding one by name in the admin (Preview, Add Exception, Settings → Privacy): [id => name],
 * the newest name from the download log, else from their bug reports / comments, else the name of their exception (written by the admin).
 * The site learns a name only from these, so anyone else is found by their Patreon user ID.
 */
function m3d_known_patrons(bool $all = false): array
{
    static $known = null, $seen = null;   // $seen: id => every name it had (a search finds older names too)
    if ($known !== null) return $all ? $seen : $known;
    $known = $seen = [];
    $add = function (string $id, string $name, bool $newest = false) use (&$known, &$seen) {
        if ($newest) $known[$id] = $name; else $known[$id] ??= $name;
        if ($name !== '') $seen[$id][$name] = true;
    };
    if ($fh = @fopen(m3d_log_file(), 'rb')) {
        while (($line = fgets($fh)) !== false) {
            $r = m3d_log_cols($line);
            if (($r[1] ?? '') !== '') $add((string) $r[1], (string) ($r[2] ?? ''), true);
        }
        fclose($fh);
    }
    if (function_exists('m3d_bugs')) foreach (m3d_bugs() as $b) {
        if (($b['by'] ?? '') !== '') $add((string) $b['by'], (string) ($b['name'] ?? ''));
        foreach ((array) ($b['log'] ?? []) as $x) if (($x['t'] ?? '') === 'c' && ($x['by'] ?? '') !== '' && empty($x['admin'])) $add((string) $x['by'], (string) ($x['name'] ?? ''));
    }
    if (function_exists('m3d_comments_flat')) foreach (m3d_comments_flat() as [, , $c]) if (($c['by'] ?? '') !== '' && empty($c['admin'])) $add((string) $c['by'], (string) ($c['name'] ?? ''));
    foreach (m3d_exceptions() as $x) $add((string) $x['id'], (string) ($x['name'] ?? ''));
    return $all ? $seen : $known;
}

/** What goes in a "Patreon ID or name" field's suggestion list for one person: "Anna · 12345678" (m3d_find_patrons() reads it back). */
function m3d_patron_pick(string $id, string $name): string
{
    return $name !== '' && $name !== $id ? $name . ' · ' . $id : $id;
}

/**
 * The people matching what was typed in a "Patreon ID or name" field: [[id, name]…]. A known ID, a picked "Name · ID", or a
 * number (an ID the site may not know yet) give one; otherwise every known name holding all the words (accents and case ignored).
 */
function m3d_find_patrons(string $q, int $max = 30): array
{
    $q = trim($q);
    if ($q === '') return [];
    $known = m3d_known_patrons();
    if (preg_match('/·\s*([\w-]{1,40})$/u', $q, $m)) $q = $m[1];
    if (isset($known[$q])) return [[$q, $known[$q]]];
    if (preg_match('/^\d{1,20}$/', $q)) return [[$q, '']];
    $words = array_filter(preg_split('/\s+/', m3d_fold($q)) ?: []);
    $out = [];
    $seen = m3d_known_patrons(true);
    foreach ($known as $id => $name) {
        foreach (array_keys($seen[$id] ?? [$name => true]) as $any) {   // the newest name, or one they had before
            $n = m3d_fold((string) $any);
            foreach ($words as $w) if (!str_contains($n, $w)) continue 2;
            $out[] = [(string) $id, $name];
            continue 2;
        }
    }
    return array_slice($out, 0, $max);
}

/** The <option>s of a "Patreon ID or name" field's <datalist>. */
function m3d_patron_options(): string
{
    $h = '';
    foreach (m3d_known_patrons() as $id => $name) $h .= '<option value="' . e(m3d_patron_pick((string) $id, $name)) . '"></option>';
    return $h;
}

/** The membership saved with a patron's newest download that has one (m3d_log_membership()) + 'at' (timestamp of that download), or null. */
function m3d_patron_saved_membership(string $patronId): ?array
{
    $last = null;
    foreach (m3d_patron_log($patronId) as $r) if (!empty($r[7]) && ($last === null || $r[0] >= $last['at'])) $last = $r[7] + ['at' => $r[0]];
    return $last;
}

/**
 * A patron's downloads: the log, plus in the preview the made-up ones: preview_dl (other items, a minute ago) and
 * preview_updates (the N listed items updated most recently, m3d_preview_updates(), "downloaded" long before their latest
 * version, so they show an update, count as unlocked and stay out of every limit period; their version: m3d_preview_got()).
 * The made-up ones only while the previewed membership can download: a preview as a non-member shows a visitor with no downloads.
 */
function m3d_patron_history(?array $patron): array
{
    $log = m3d_patron_log((string) ($patron['id'] ?? ''));
    if (empty($patron['access'])) return $log;
    foreach ($patron['preview_dl'] ?? [] as $k => $count) for ($i = 0; $i < $count; $i++) $log[] = [time() - 60 - $i, $k, "~preview-$i"];
    foreach (m3d_preview_updates((int) ($patron['preview_updates'] ?? 0)) as [$rel, $k, $it]) $log[] = [$rel - 400 * 86400, $k, (string) $it['slug'], m3d_preview_got($it)];
    return $log;
}

/** The latest download of each item in a history (m3d_patron_history()): "kind:slug" => [time, version]. */
function m3d_last_downloads(array $history): array
{
    $last = [];
    foreach ($history as $row) {
        [$t, $k, $s] = $row;
        if ($t >= ($last["$k:$s"][0] ?? 0)) $last["$k:$s"] = [$t, (string) ($row[3] ?? '')];   // the preview's made-up downloads have no version
    }
    return $last;
}

/** Preview: the site's real latest updates: the $n listed items with two dated versions or more, newest latest version first: [[ts, kind, item], …]. */
function m3d_preview_updates(int $n): array
{
    if ($n <= 0) return [];
    $out = [];
    foreach (['models', 'outfits'] as $k) foreach (m3d_listed_items($k) as $it) {
        if (!m3d_is_open($it) || m3d_preview_got($it) === '' || ($rel = m3d_changelog_ts((string) (m3d_released_versions($it)[0]['date'] ?? ''))) === null) continue;
        $out[] = [$rel, $k, $it];
    }
    usort($out, fn($a, $b) => $b[0] <=> $a[0]);
    return array_slice($out, 0, $n);
}

/** Preview: the version a simulated update was "downloaded" at: the third newest, else the second (so its "what's new" lists the real
 *  one or two newest versions); '' with a single version (not an update). */
function m3d_preview_got(array $item): string
{
    $log = m3d_released_versions($item);   // not the ones dated in the future (m3d_newer_versions() leaves them out)
    return isset($log[2]) ? m3d_version_label($log[2]) : (isset($log[1]) ? m3d_version_label($log[1]) : '');
}

/** Models and outfits marked "Exclude from Unlock Limits" in the editor: ['models:<slug>' => true, 'outfits:<slug>' => true]. */
function m3d_free_items(): array
{
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    foreach (['models' => m3d_models(), 'outfits' => m3d_outfits()] as $kind => $list) {
        foreach ($list as $it) if (!empty($it['noLimits'])) $out[$kind . ':' . $it['slug']] = true;
    }
    return $out;
}

/**
 * Checks the download limits for a patron before downloading $kind/$slug. Returns ['ok' => bool, 'exempt' => bool,
 * 'free' => bool (item marked "Exclude from Unlock Limits"), 'owned' => bool (downloaded before: free), 'update' => bool (downloaded
 * before, but "Updates Use an Unlock" is on and a newer version came out since: not owned, the download unlocks it again),
 * 'rows' => [['rule' => …, 'text' => …, 'used' => n, 'max' => n, 'ok' => bool, 'next' => timestamp|null]]] with the
 * rules that apply to this patron (all kinds when $kind is null, e.g. for My account).
 */
function m3d_check_limits(?array $patron, ?string $kind = null, ?string $slug = null): array
{
    $exempt = $patron === null || ($patron['reason'] ?? '') === 'owner' || ($patron['exception']['limits'] ?? '') === 'bypass';
    // the rules of this patron's months and tier; those counting $kind (for My account, $kind null, all of them)
    $rules = array_values(array_filter(m3d_rules_for($patron), fn($r) => $kind === null || in_array($kind, m3d_rule_kinds($r), true)));
    if (!$rules) return ['ok' => true, 'exempt' => $exempt, 'free' => false, 'owned' => false, 'update' => false, 'rows' => []];
    $log = m3d_patron_history($patron);   // the log, plus the preview's made-up downloads
    // first download ever of each item: an item counts once, in the period of its first download, and never again
    $firstEver = [];
    $wasFree = [];   // first downloaded while "Not counted" (log column 7): never counts, even once the mark is removed
    $freeItems = m3d_free_items();   // never counted, never limited
    $last = m3d_last_downloads($log);   // the latest download of each item (updates)
    foreach ($log as $row) {
        [$t, $k, $s] = $row;
        if (isset($freeItems["$k:$s"])) continue;
        // a newer version unlocked again ("Updates Use an Unlock", logged "update"): counts like one more item, once per version
        // (two tabs finishing the same countdown at once log it twice)
        if (!empty($row[6])) {
            $key = "$k:$s@" . (string) ($row[3] ?? '');
            if ($t < ($firstEver[$key] ?? PHP_INT_MAX)) { $firstEver[$key] = $t; $wasFree[$key] = false; }
            continue;
        }
        if ($t >= ($firstEver["$k:$s"] ?? PHP_INT_MAX)) continue;
        $firstEver["$k:$s"] = $t;
        $wasFree["$k:$s"] = !empty($row[5]);
    }
    $free = $kind !== null && isset($freeItems["$kind:$slug"]);
    $owned = $kind !== null && !$free && isset($last["$kind:$slug"]);   // downloaded before: free, whatever the rules say
    // …unless "Updates Use an Unlock" is on and a newer version came out since their last download (the "↑ Update" tag's rule)
    $update = false;
    if ($owned && ($it = m3d_item($kind, (string) $slug)) && m3d_update_needs_unlock($kind, $it, $last["$kind:$slug"][1], $last["$kind:$slug"][0])) {
        $owned = false;
        $update = true;
    }
    $rows = [];
    $now = time();
    foreach ($rules as $r) {
        [$secs] = m3d_rule_period($r);
        $first = [];   // first downloads of different items inside the period
        foreach ($firstEver as $key => $t) {
            if ($t <= $now - $secs || $wasFree[$key]) continue;
            if (!in_array(explode(':', $key, 2)[0], m3d_rule_kinds($r), true)) continue;
            $first[] = $t;
        }
        $max = max(1, (int) ($r['max'] ?? 1));
        $ok = $exempt || $free || $owned || count($first) < $max;
        sort($first);
        // when a slot frees up: the oldest counted download leaves the period
        $next = count($first) >= $max ? $first[count($first) - $max] + $secs : null;
        $rows[] = ['rule' => $r, 'text' => m3d_rule_text($r), 'used' => count($first), 'max' => $max, 'ok' => $ok, 'next' => $next];
    }
    return ['ok' => !in_array(false, array_column($rows, 'ok'), true), 'exempt' => $exempt, 'free' => $free, 'owned' => $owned, 'update' => $update, 'rows' => $rows];
}

/**
 * "Updates Use an Unlock" (Admin → Access) is on and a newer version of $item came out since the patron's last download of it
 * (version $got at $t): downloading it unlocks it again. The "↑ Update" tag's rule (m3d_updates_for()): public and blurred items
 * only (a password item's new version stays free, as it shows no tag), not "In development" models (nothing to download); and not
 * past the public date (every link is free for everyone then, auth/get.php). m3d_check_limits() and the catalogs' check marks
 * (m3d_status_data()) both ask this.
 */
function m3d_update_needs_unlock(string $kind, array $item, string $got, int $t): bool
{
    return m3d_dl_settings()['updates'] && m3d_is_open($item) && m3d_release_phase($item) !== 'free'
        && !($kind === 'models' && m3d_status($item) === 'in-development') && m3d_newer_versions($item, $got, $t);
}

/**
 * Downloads left now per kind, from the tightest rule that counts it (header counter, My account): null when no rule
 * applies to this patron (or they're exempt) = unlimited; else ['models' => int|null, 'outfits' => int|null (null = no
 * rule counts that kind), 'next' => ['models' => ts|null, 'outfits' => ts|null] (when a slot frees up, if at 0)].
 */
function m3d_downloads_left(?array $patron): ?array
{
    $c = m3d_check_limits($patron);
    if (!$c['rows'] || $c['exempt']) return null;
    $left = ['models' => null, 'outfits' => null, 'next' => ['models' => null, 'outfits' => null]];
    foreach ($c['rows'] as $r) {
        $n = max(0, $r['max'] - $r['used']);
        foreach (['models', 'outfits'] as $k) {
            if (!in_array($k, m3d_rule_kinds($r['rule']), true)) continue;
            $left[$k] = min($left[$k] ?? PHP_INT_MAX, $n);
            if ($n === 0 && $r['next']) $left['next'][$k] = max($left['next'][$k] ?? 0, $r['next']);   // all full rules must free up
        }
    }
    return $left;
}

// ── Counters and likes: small JSON files changed by many visitors at once ────
/**
 * Locked read-modify-write of data/<name>.json (no automatic backups: these change all the time). Returns null, writing nothing,
 * when the file is there but damaged (so a like or a report never replaces it with an almost empty one) or can't be written.
 */
function m3d_json_update(string $name, callable $fn): mixed
{
    $dir = M3D_PRIVATE . '/data';
    $file = "$dir/$name.json";
    $lock = @fopen("$dir/.$name.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return null;
    try {
        $data = m3d_read_json($name);
        if ($data === null && is_file($file) && filesize($file) > 0) {
            m3d_problem('Data', "$name.json is damaged: nothing was saved into it (restore it from Admin → Backups)");
            return null;
        }
        $result = $fn($data);   // $fn gets the data by value and returns [newData, result]
        [$new, $out] = $result;
        if ($new !== $data) {
            $json = json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || !m3d_atomic_write($file, $json)) {
                m3d_problem('Data', "$name.json could not be saved" . ($json === false ? ' (' . json_last_error_msg() . ')' : ' (disk full or not writable?)'));
                return null;
            }
        }
        return $out;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** The changelog entries that have a version, newest first (as in the editor). */
function m3d_versions(array $item): array
{
    return array_values(array_filter((array) ($item['changelog'] ?? []), fn($en) => is_array($en) && trim((string) ($en['version'] ?? '')) !== ''));
}

/** m3d_versions() that are out: a version dated in the future (a later day in site time, like What's new) isn't, so downloads, the
 *  download log and updates leave it out until its day; undated versions are out. The item's page still lists every version. */
function m3d_released_versions(array $item): array
{
    $now = time();
    return array_values(array_filter(m3d_versions($item), fn($en) => (m3d_changelog_ts((string) ($en['date'] ?? '')) ?? 0) <= $now));
}

/** The newest version that is out, shaped like m3d_latest_version() (a version dated in the future isn't out yet): what pages,
 *  cards, downloads and Discord call the current version. Old items without a changelog: their old fields. */
function m3d_current_version(array $item): array
{
    if (!m3d_versions($item)) return m3d_latest_version($item);
    $en = m3d_released_versions($item)[0] ?? null;
    return $en ? m3d_latest_version(['changelog' => [$en]]) : ['version' => '', 'date' => '', 'stage' => 'standard'];
}

/** When the item was last updated: the date of its newest version out (a version dated in the future doesn't count yet); 0 = none.
 *  A "Coming soon" item (before its patrons date) hasn't come out at all: 0, like What's new and Recently Updated. */
function m3d_updated_ts(array $item): int
{
    if (m3d_release_phase($item) === 'soon') return 0;
    return (int) (m3d_changelog_ts(m3d_current_version($item)['date']) ?? 0);
}

/** The version a download gets now, as logged ("V4", "Beta 2"): the newest one out; '' when none is out yet. */
function m3d_released_label(array $item): string
{
    return m3d_version_label(m3d_current_version($item));
}

/** Latest version of a model or outfit = the top changelog entry (newest first). Returns ['version' => '', 'date' => ''] when there is none. */
function m3d_latest_version(array $item): array
{
    if ($en = m3d_versions($item)[0] ?? null) return ['version' => trim((string) $en['version']), 'date' => trim((string) ($en['date'] ?? '')), 'stage' => m3d_stage($en)];
    return ['version' => '', 'date' => '', 'stage' => 'standard'];
}

/**
 * A changelog date as a timestamp (start of that day, site time): "16/08/2026" (the editor's format), also "16.08.2026",
 * "2026-08-16" or anything strtotime() reads. null when empty or unreadable.
 */
function m3d_changelog_ts(string $d): ?int
{
    $d = trim($d);
    if ($d === '') return null;
    if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{2})$#', $d, $m)) return m3d_changelog_ts("$m[1]/$m[2]/20$m[3]");   // "13/10/26": 2026, not the year 26
    $tz = m3d_tz();
    foreach (['!j/n/Y', '!j.n.Y', '!Y-m-d'] as $f) {
        $x = DateTimeImmutable::createFromFormat($f, $d, $tz);
        $err = DateTimeImmutable::getLastErrors();
        if ($x && (!$err || (!$err['warning_count'] && !$err['error_count']))) return $x->getTimestamp();
    }
    if (preg_match('/^(19|20)\d\d$/', $d)) return m3d_changelog_ts("1/1/$d");   // a year alone (strtotime reads "2021" as 20:21 today)
    // anything else needs a 4-digit year: words like "today" or "next week" (or "13 Oct") would move every day
    if (!preg_match('/(?<!\d)\d{4}(?!\d)/', $d)) return null;
    $t = ($x = date_create_immutable($d, m3d_tz())) ? $x->getTimestamp() : false;
    return $t === false ? null : $t;
}

/** A version as people see it: "Beta 2" / "Alpha 3" when the release has a stage, else just "V4" (from m3d_latest_version() or a changelog entry). */
function m3d_version_label(array $v): string
{
    $ver = trim((string) ($v['version'] ?? ''));
    $stage = m3d_stage($v);
    if ($ver === '' || $stage === 'standard' || stripos($ver, M3D_STAGES[$stage]) === 0) return $ver;   // saved as "Beta 2" before: no "Beta Beta 2"
    return M3D_STAGES[$stage] . ' ' . $ver;
}

/**
 * The changelog's version anchors (the item page's #v4, #v3-1, #beta-2: changelog_items()), by position in $log; '' for an entry
 * without a version. A label seen twice gets -2, -3…, so every anchor is one place.
 */
function m3d_version_ids(array $log): array
{
    $out = [];
    $seen = [];
    foreach (array_values($log) as $i => $en) {
        $id = is_array($en) ? trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(m3d_version_label($en))), '-') : '';
        if ($id !== '' && ctype_digit($id[0])) $id = 'v' . $id;
        if ($id !== '' && isset($seen[$id])) $id .= '-' . (++$seen[$id]);
        elseif ($id !== '') $seen[$id] = 1;
        $out[$i] = $id;
    }
    return $out;
}

/** The address of one version on its item's page (/models/tifa/#v4), or of the changelog when that version isn't in it. */
function m3d_version_url(string $kind, array $item, string $label): string
{
    $log = (array) ($item['changelog'] ?? []);
    $i = function_exists('m3d_changelog_find') ? m3d_changelog_find($log, $label) : null;
    $id = $i !== null ? (m3d_version_ids($log)[$i] ?? '') : '';
    return m3d_item_url($kind, (string) ($item['slug'] ?? '')) . '#' . ($id !== '' ? $id : 'changelog');
}

/**
 * Updates for a patron: the models/outfits they downloaded where a newer version came out after their last download:
 * the version they got is found below the top of the changelog, or (old log lines without a version, a version renamed
 * since) the latest version is dated after the day of that download. ['models' => [slug => version], 'outfits' => [slug => version]] (public and blurred items, not drafts or password ones; the version
 * starts with Alpha / Beta when the release has that stage). None without download access (not a paying patron any more, or a
 * preview as a non-member): an update they can't download isn't news for them.
 */
function m3d_updates_for(?array $patron): array
{
    $out = ['models' => [], 'outfits' => []];
    if (!$patron || empty($patron['access'])) return $out;
    foreach (m3d_last_downloads(m3d_patron_history($patron)) as $key => [$t, $got]) {
        [$k, $s] = explode(':', $key, 2);
        $it = m3d_item($k, $s);
        if (!$it || !m3d_is_open($it)) continue;   // removed, or draft / password now: no update shown
        if ($k === 'models' && m3d_status($it) === 'in-development') continue;   // nothing to download: not an update yet
        if ($newer = m3d_newer_versions($it, $got, $t)) $out[$k][$s] = m3d_version_label($newer[0]);
    }
    return $out;
}

/**
 * The changelog versions newer than a download (newest first; empty = up to date): the ones above the version downloaded,
 * or (version unknown: old log line, renamed since) the dated ones on top released after the day of the download ($t).
 * Versions dated in the future are not out yet (m3d_released_versions()): no update before their day.
 */
function m3d_newer_versions(array $item, string $got, int $t): array
{
    $log = m3d_released_versions($item);
    if ($got !== '') foreach ($log as $i => $en) if (m3d_version_label($en) === $got) return array_slice($log, 0, $i);
    $out = [];
    foreach ($log as $en) {
        $ts = m3d_changelog_ts((string) ($en['date'] ?? ''));
        if ($ts === null || $ts <= $t) break;
        $out[] = $en;
    }
    return $out;
}

/** m3d_updates_for() as short codes "models:tifa@V4" (a newer version gets a new code, so it shows as new again). */
function m3d_update_codes(array $updates): array
{
    $out = [];
    foreach ($updates as $kind => $list) foreach ($list as $slug => $version) $out[] = "$kind:$slug@$version";
    return $out;
}

// ── Ignored updates ("Ignore this update", the ✕ on a catalog card's "↑ Update" tag) ──
// data/ignored.json: {ignored: {patron code (m3d_like_code()): {"kind:slug": version label}}}: no raw ids or names (privacy.php).
// An entry hides the tag and the account button's dot for that version only: a newer version has another label, so it shows
// again. Entries no longer matching one of the patron's updates (downloaded since, newer version) go at their next change.

/** The patron's ignored updates: ["kind:slug" => version label]. */
function m3d_ignored_updates(?array $patron): array
{
    if (!$patron || ($patron['id'] ?? '') === '') return [];
    static $all = null;
    $all ??= m3d_read_json('ignored')['ignored'] ?? [];
    $mine = $all[m3d_like_code((string) $patron['id'])] ?? null;
    return is_array($mine) ? array_filter($mine, 'is_string') : [];
}

/** m3d_updates_for() without the ones this patron ignored (catalog tags, the account button's dot). */
function m3d_updates_shown(?array $patron): array
{
    $out = m3d_updates_for($patron);
    if (!($ign = m3d_ignored_updates($patron))) return $out;
    foreach ($out as $kind => $list) foreach ($list as $slug => $version) if (($ign["$kind:$slug"] ?? null) === $version) unset($out[$kind][$slug]);
    return $out;
}

/**
 * Ignores (or shows again) the current update of one item for a patron. Returns false when that item has no update for them
 * now, null when it couldn't be saved. Drops their entries that no longer match an update.
 */
function m3d_ignore_update(array $patron, string $kind, string $slug, bool $ignore): ?bool
{
    $updates = m3d_updates_for($patron);
    $version = $updates[$kind][$slug] ?? null;
    if ($version === null) return false;
    $code = m3d_like_code((string) $patron['id']);
    return m3d_json_update('ignored', function ($d) use ($updates, $code, $kind, $slug, $version, $ignore) {
        $d = is_array($d) ? $d : [];
        $mine = is_array($d['ignored'][$code] ?? null) ? $d['ignored'][$code] : [];
        $mine = array_filter($mine, function ($v, $key) use ($updates) {
            [$k, $s] = explode(':', (string) $key, 2) + [1 => ''];
            return ($updates[$k][$s] ?? null) === $v;
        }, ARRAY_FILTER_USE_BOTH);
        if ($ignore) $mine["$kind:$slug"] = $version; else unset($mine["$kind:$slug"]);
        if ($mine) $d['ignored'][$code] = $mine; else unset($d['ignored'][$code]);
        $d['ignored'] = (object) ($d['ignored'] ?? []);   // {} in the file, also when empty
        return [$d, true];
    });
}

/** An item got a new address ($new) or was deleted (null): every patron's ignored entry for it moves or goes. */
function m3d_ignored_move(string $kind, string $old, ?string $new): void
{
    if (!is_file(M3D_PRIVATE . '/data/ignored.json')) return;
    m3d_json_update('ignored', function ($d) use ($kind, $old, $new) {
        if (!is_array($d) || !is_array($d['ignored'] ?? null)) return [$d, null];
        foreach ($d['ignored'] as $code => $mine) {
            if (!is_array($mine) || !isset($mine["$kind:$old"])) continue;
            if ($new !== null) $mine["$kind:$new"] = $mine["$kind:$old"];
            unset($mine["$kind:$old"]);
            if ($mine) $d['ignored'][$code] = $mine; else unset($d['ignored'][$code]);
        }
        $d['ignored'] = (object) $d['ignored'];
        return [$d, null];
    });
}


/** Release stage of a changelog version: 'alpha' | 'beta' | 'standard' (absent = standard). */
const M3D_STAGES = ['standard' => 'Standard', 'alpha' => 'Alpha', 'beta' => 'Beta'];
function m3d_stage(array $en): string
{
    $s = (string) ($en['stage'] ?? '');
    return isset(M3D_STAGES[$s]) ? $s : 'standard';
}

/** Add-ons a model/outfit needs ("Add-ons required" in the download box): [{label, url}], none when not set. */
function m3d_addons(array $item): array
{
    return (array) ($item['addons'] ?? []);
}

/** Download totals: {downloads: {models|outfits: {slug: {patrons, public}}}, since}. Started from the download log. */
function m3d_stats(): array
{
    static $s = null;   // read once per request (lists show a count per model/outfit)
    if ($s !== null) return $s;
    return $s = m3d_read_json('stats') ?? m3d_stats_from_log();
}

function m3d_stats_from_log(): array
{
    $s = ['since' => gmdate('c'), 'downloads' => ['models' => [], 'outfits' => []]];
    $fh = @fopen(m3d_log_file(), 'rb');
    if ($fh) {
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($r) < 4 || $r[3] === '') continue;
            $s['since'] = min($s['since'], (string) $r[0]);
            [$kind, $slug] = m3d_log_item_parts($r[3]);
            $s['downloads'][$kind][$slug]['patrons'] = ($s['downloads'][$kind][$slug]['patrons'] ?? 0) + 1;
        }
        fclose($fh);
    }
    return $s;
}

function m3d_count_download(string $kind, string $slug, bool $public): void
{
    // links opened by search engines and link previews aren't downloads
    if (preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|curl|wget|python|headless/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) return;
    m3d_json_update('stats', function ($d) use ($kind, $slug, $public) {
        $d = is_array($d) ? $d : m3d_stats_from_log();
        $k = $public ? 'public' : 'patrons';
        $d['downloads'][$kind][$slug][$k] = ($d['downloads'][$kind][$slug][$k] ?? 0) + 1;
        return [$d, null];
    });
}

/** Downloads of one item: ['patrons' => n, 'public' => n, 'total' => n]. */
function m3d_downloads(string $kind, string $slug, ?array $stats = null): array
{
    $c = ($stats ?? m3d_stats())['downloads'][$kind][$slug] ?? [];
    $p = (int) ($c['patrons'] ?? 0); $f = (int) ($c['public'] ?? 0);
    return ['patrons' => $p, 'public' => $f, 'total' => $p + $f];
}

/**
 * Likes (paying patrons only, one per patron and model/outfit/gallery picture). data/likes.json: {models: {slug: [code, …]}, outfits: {…},
 * stills: {still id: [code, …]}}.
 * The code is an HMAC of the Patreon ID with a key kept in the private folder, so the file holds no Patreon IDs.
 */
function m3d_like_code(string $patronId): string
{
    return substr(hash_hmac('sha256', $patronId, m3d_secret('likes')), 0, 20);
}

/** slug => [codes] for 'models' or 'outfits'; still id => [codes] for 'stills'; post id => [codes] for 'posts' (blog). */
function m3d_likes(string $kind = 'models', bool $fresh = false): array
{
    static $all = null;
    if ($fresh) $all = null;
    $all ??= m3d_read_json('likes') ?? [];
    return is_array($all[$kind] ?? null) ? $all[$kind] : [];
}

function m3d_like_count(string $slug, string $kind = 'models'): int
{
    return count(m3d_likes($kind)[$slug] ?? []);
}

/** Sets or removes a patron's like on a model, outfit or gallery picture; returns [count, liked]. */
function m3d_set_like(string $slug, string $patronId, bool $like, string $kind = 'models'): array
{
    $code = m3d_like_code($patronId);
    return m3d_json_update('likes', function ($d) use ($slug, $code, $like, $kind) {
        $d = is_array($d) ? $d : ['models' => []];
        $had = in_array($code, $d[$kind][$slug] ?? [], true);
        $list = array_values(array_diff($d[$kind][$slug] ?? [], [$code]));
        if ($like) $list[] = $code;
        if ($list) $d[$kind][$slug] = $list; else unset($d[$kind][$slug]);
        if ($like !== $had) $d['daily'] = m3d_likes_daily_add($d['daily'] ?? null, $like ? 1 : -1);
        return [$d, [count($list), $like]];
    }) ?? [m3d_like_count($slug, $kind), false];
}

const LIKES_DAILY_KEEP = 366;   // days of new-like counts kept (Admin → Home shows the last 7, Stats up to a year)

/** likes.json → daily: {since: first day counted, n: {Y-m-d (site time): new likes that day}}; numbers only, no patron codes.
 *  A removed like takes one off today (a day may go below 0; the week's total never does), so liking, unliking and liking again counts once,
 *  also across days. */
function m3d_likes_daily_add(mixed $daily, int $by = 1): array
{
    $tz = m3d_tz();
    $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $daily = is_array($daily) ? $daily : [];
    $n = is_array($daily['n'] ?? null) ? $daily['n'] : [];
    $n[$today] = (int) ($n[$today] ?? 0) + $by;   // may go below 0: a like given on an earlier day and removed today
    $oldest = (new DateTimeImmutable('today', $tz))->modify('-' . (LIKES_DAILY_KEEP - 1) . ' days')->format('Y-m-d');
    $n = array_filter($n, fn($v, $day) => is_string($day) && $day >= $oldest, ARRAY_FILTER_USE_BOTH);
    ksort($n);
    $since = is_string($daily['since'] ?? null) ? $daily['since'] : $today;
    return ['since' => $since, 'n' => $n];
}

/** New likes (models, accessories, gallery pictures) from day $from (Y-m-d, site time) on, and the day counting started ('' = never). */
function m3d_likes_new_since(string $from): array
{
    $daily = (m3d_read_json('likes') ?? [])['daily'] ?? null;
    if (!is_array($daily)) return [0, ''];
    $sum = 0;
    foreach ((array) ($daily['n'] ?? []) as $day => $v) if ((string) $day >= $from) $sum += (int) $v;
    return [max(0, $sum), is_string($daily['since'] ?? null) ? $daily['since'] : ''];   // a like from before $from removed since: never below 0
}

const LOGINS_KEEP = 366;   // days of login counts kept (Admin → Home shows the last 30, Stats up to a year)

/** A Patreon login (auth/callback.php; owners aren't counted): data/logins.json → {since: first day counted, n: {Y-m-d (site time):
 *  [logins that can download, logins refused]}}. Numbers only: no Patreon ids, names or codes. */
function m3d_login_count(bool $ok): void
{
    m3d_json_update('logins', function ($d) use ($ok) {
        $tz = m3d_tz();
        $today = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
        $oldest = (new DateTimeImmutable('today', $tz))->modify('-' . (LOGINS_KEEP - 1) . ' days')->format('Y-m-d');
        $d = is_array($d) ? $d : [];
        $n = array_filter(is_array($d['n'] ?? null) ? $d['n'] : [], fn($v, $day) => is_string($day) && $day >= $oldest && is_array($v), ARRAY_FILTER_USE_BOTH);
        $day = array_map('intval', array_values($n[$today] ?? [0, 0])) + [0, 0];
        $day[$ok ? 0 : 1]++;
        $n[$today] = [$day[0], $day[1]];
        ksort($n);
        return [['since' => is_string($d['since'] ?? null) ? $d['since'] : $today, 'n' => $n], true];
    });
}

/** The logins counted per day: [{Y-m-d: [can download, refused]}, the day counting started ('' = never)]. */
function m3d_logins_daily(): array
{
    $d = m3d_read_json('logins') ?? [];
    $n = [];
    foreach ((array) ($d['n'] ?? []) as $day => $v) if (is_array($v)) $n[(string) $day] = [(int) ($v[0] ?? 0), (int) ($v[1] ?? 0)];
    return [$n, is_string($d['since'] ?? null) ? $d['since'] : ''];
}

/** A model or outfit got a new address: its download counts and likes move with it. */
function m3d_rename_counts(string $kind, string $old, string $new): void
{
    if ($old === $new) return;
    m3d_json_update('stats', function ($d) use ($kind, $old, $new) {
        $d = is_array($d) ? $d : m3d_stats_from_log();
        if (isset($d['downloads'][$kind][$old])) {
            foreach ($d['downloads'][$kind][$old] as $k => $n) $d['downloads'][$kind][$new][$k] = ($d['downloads'][$kind][$new][$k] ?? 0) + $n;
            unset($d['downloads'][$kind][$old]);
        }
        return [$d, null];
    });
    m3d_json_update('likes', function ($d) use ($kind, $old, $new) {
        if (!is_array($d) || !isset($d[$kind][$old])) return [$d, null];
        $d[$kind][$new] = array_values(array_unique(array_merge($d[$kind][$new] ?? [], $d[$kind][$old])));
        unset($d[$kind][$old]);
        return [$d, null];
    });
    m3d_ignored_move($kind, $old, $new);   // an ignored update stays ignored
    m3d_blog_rename_item($kind, $old, $new);   // posts showing it
    m3d_comments_rename($kind, $old, $new);   // its comments
    // the log too: what patrons unlocked, their My Models and updates are read from it by the item's address
    m3d_log_rename_items([m3d_log_item($kind, $old) => m3d_log_item($kind, $new)]);
}

/**
 * Renames items in the download log's item column (4th): $map = [old log item => new log item] ("slug", "outfit:slug").
 * Through m3d_log_rewrite(), so a download logged meanwhile waits. Returns how many lines changed.
 */
function m3d_log_rename_items(array $map): int
{
    if (!$map) return 0;
    return m3d_log_rewrite(function ($line) use ($map) {
        $r = m3d_log_cols($line);
        if (!isset($r[3], $map[$r[3]])) return false;
        $r[3] = $map[$r[3]];
        return $r;
    });
}

/** The download log: logs/downloads.csv (date, patreon id, name, item, link label, version, "free" / "update" / empty, then the membership at that download: m3d_log_download(), m3d_log_membership()). */
function m3d_log_file(): string { return M3D_PRIVATE . '/logs/downloads.csv'; }

/** The log's item column (4th) for a model or accessory: "slug" or "outfit:slug". */
function m3d_log_item(string $kind, string $slug): string
{
    return ($kind === 'outfits' ? 'outfit:' : '') . $slug;
}

/** The other way: [kind, slug] of a log item. */
function m3d_log_item_parts(string $item): array
{
    return str_starts_with($item, 'outfit:') ? ['outfits', substr($item, 7)] : ['models', $item];
}

/** A log line's columns. */
function m3d_log_cols(string $line): array
{
    return str_getcsv(rtrim($line, "\r\n"), ',', '"', '');
}

/**
 * Runs $fn while holding the download log's lock (logs/.downloads.lock, a file of its own: the log itself is replaced by
 * a rewrite, so a lock on it would not stop a line from being added to the old file). Every write of the log goes through here.
 */
function m3d_log_locked(callable $fn): mixed
{
    $dir = M3D_PRIVATE . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $lock = @fopen($dir . '/.downloads.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try { return $fn(); }
    finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
}

/**
 * Rewrites the download log (locked, through a temp file, so a download logged meanwhile waits and is never lost): $change($line)
 * returns true to drop the line, an array of columns to write instead, anything else to keep it. Returns how many lines went or changed.
 */
function m3d_log_rewrite(callable $change): int
{
    return m3d_log_locked(function () use ($change) {
        $file = m3d_log_file();
        if (!is_file($file) || !($in = fopen($file, 'rb'))) return 0;
        $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
        if (!($out = @fopen($tmp, 'wb'))) { fclose($in); m3d_problem('Download log', 'The log could not be rewritten (disk full or not writable?)'); return 0; }
        $n = 0;
        $ok = true;
        while (($line = fgets($in)) !== false) {
            $c = $change($line);
            if ($c === true) { $n++; continue; }
            if (is_array($c)) { $n++; $ok = fputcsv($out, $c, ',', '"', '') !== false; }
            else $ok = fwrite($out, $line) === strlen($line);
            if (!$ok) break;
        }
        $ok = fclose($out) && $ok;
        fclose($in);
        // a short copy (disk full) never replaces the log: it would lose the newest downloads
        if (!$ok) { @unlink($tmp); m3d_problem('Download log', 'The log could not be rewritten (disk full or not writable?)'); return 0; }
        if ($n) rename($tmp, $file); else @unlink($tmp);
        return $n;
    });
}

/** Deletes the whole download log (Settings → Tools, Admin → Downloads): the number of lines it had. */
function m3d_log_clear(): int
{
    return m3d_log_locked(function () {
        $file = m3d_log_file();
        if (!is_file($file)) return 0;
        $n = 0;
        if ($fh = fopen($file, 'rb')) { while (fgets($fh) !== false) $n++; fclose($fh); }
        @unlink($file);
        return $n;
    });
}

/**
 * Removes one patron's rows from the download log (Admin → Downloads): their download history, and with it what they
 * unlocked, so the limits count from zero again. The all-time counters (stats.json) stay. Returns the rows removed.
 */
function m3d_log_remove_patron(string $patronId): int
{
    if ($patronId === '') return 0;
    return m3d_log_rewrite(fn($line) => (m3d_log_cols($line)[1] ?? null) === $patronId);
}

/**
 * The membership saved with a log line (columns 8–11, written by m3d_log_download()): ['months' => ?int (at that download), 'since' => 'Y-m-d' | '',
 * 'tier_cents' => ?int, 'total_cents' => ?int], or null for lines from before it, owners and other lines without it.
 */
function m3d_log_membership(array $r): ?array
{
    $n = fn($v) => is_string($v) && ctype_digit($v) ? (int) $v : null;
    $m = ['months' => $n($r[7] ?? null), 'since' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($r[8] ?? '')) ? (string) $r[8] : '', 'tier_cents' => $n($r[9] ?? null), 'total_cents' => $n($r[10] ?? null)];
    return $m['months'] === null && $m['since'] === '' && $m['tier_cents'] === null && $m['total_cents'] === null ? null : $m;
}

/** A short code for one log line (its first six fields; the "free" / "update" mark is left out), so a page can name the lines to remove. Identical lines share it. */
function m3d_log_row_key(array $r): string
{
    return substr(hash('sha256', implode("\x1f", array_slice(array_pad($r, 6, ''), 0, 6))), 0, 16);
}

/** Removes single log lines (one per code given; a code given twice removes two identical lines). Returns how many went. */
function m3d_log_remove_rows(array $keys): int
{
    $want = array_count_values(array_filter($keys, fn($k) => is_string($k) && preg_match('/^[0-9a-f]{16}$/', $k)));
    if (!$want) return 0;
    return m3d_log_rewrite(function ($line) use (&$want) {
        $k = m3d_log_row_key(m3d_log_cols($line));
        if (($want[$k] ?? 0) < 1) return false;
        $want[$k]--;
        return true;
    });
}

/** Deletes download log lines older than log_retention_days (runs at most once a day). */
function m3d_prune_log(array $cfg): void
{
    $mark = M3D_PRIVATE . '/logs/.pruned';
    if (!is_file(m3d_log_file()) || (is_file($mark) && filemtime($mark) > time() - 86400)) return;
    $limit = gmdate('c', time() - max(1, (int) ($cfg['log_retention_days'] ?? 365)) * 86400);
    m3d_log_rewrite(fn($line) => strcmp(substr($line, 0, 25), $limit) < 0);
    @touch($mark);
}

// ── Data: models (data/models.json), outfits (data/outfits.json) ────────────
// Both kinds share the same shape for downloads:
//   links: [ { label: "Mega", url: "https://…", access: "patrons" | "public" }, … ]
// Patron links are never printed on a page: they are reached through /get/… after the tier check.
const M3D_KINDS = ['models' => 'models', 'outfits' => 'outfits'];

/** $fresh: read the file again (a job that worked for a while before saving must not write back an older copy). */
function m3d_items(string $kind, bool $fresh = false): array
{
    static $cache = [];
    if (!isset(M3D_KINDS[$kind])) throw new InvalidArgumentException("Unknown kind $kind");
    if (isset($cache[$kind]) && !$fresh) return $cache[$kind];
    $items = m3d_read_json($kind)[$kind] ?? null;
    $items = is_array($items) ? $items : [];
    usort($items, fn($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
    return $cache[$kind] = $items;
}

function m3d_item(string $kind, string $slug): ?array
{
    foreach (m3d_items($kind) as $m) if (($m['slug'] ?? '') === $slug) return $m;
    return null;
}

function m3d_models(): array { return m3d_items('models'); }
function m3d_model(string $slug): ?array { return m3d_item('models', $slug); }
function m3d_outfits(): array { return m3d_items('outfits'); }
// "Outfits" are shown as Accessories on the site (/accessories/): outfits, hair styles, necklaces… The kind stays 'outfits' in
// the data, the log ("outfit:<slug>"), likes, counters and settings. Each has a type (free text, the editor suggests these
// and every type in use); a missing type = Outfit (everything before accessories existed).
const M3D_ACCESSORY_TYPES = ['Outfit', 'Hair', 'Necklace', 'Earrings', 'Glasses', 'Shoes', 'Hat', 'Bag'];
function m3d_accessory_type(array $o): string { $t = trim((string) ($o['type'] ?? '')); return $t !== '' ? $t : 'Outfit'; }
/** Public page of a model or accessory. */
function m3d_item_url(string $kind, string $slug): string { return ($kind === 'outfits' ? '/accessories/' : '/models/') . $slug . '/'; }
function m3d_outfit(string $slug): ?array { return m3d_item('outfits', $slug); }

/** Outfits made for a model, in outfit catalog order (drafts and unlisted ones left out). */
function m3d_outfits_for(string $modelSlug): array
{
    return array_values(array_filter(m3d_listed_items('outfits'), fn($o) => in_array($modelSlug, $o['models'] ?? [], true)));
}

/** Writes a JSON file safely: keeps a timestamped backup, writes to a temp file, then swaps it in. */
function m3d_write_json(string $name, array $data): bool
{
    $dir = M3D_PRIVATE . '/data';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = "$dir/$name.json";
    if (is_file($file)) {
        $bdir = M3D_PRIVATE . '/backups';
        if (!is_dir($bdir)) @mkdir($bdir, 0700, true);
        @copy($file, "$bdir/$name-" . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
        $old = glob("$bdir/$name-*.json") ?: [];
        sort($old);
        foreach (array_slice($old, 0, max(0, count($old) - 50)) as $f) @unlink($f); // keep the last 50 per file
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    return m3d_atomic_write($file, $json, LOCK_EX);
}

/** Writes a file whole or not at all: a temp file next to it, then a rename (readers never see half a file). */
function m3d_atomic_write(string $file, string $data, int $flags = 0): bool
{
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $data, $flags) === false || !@rename($tmp, $file)) { @unlink($tmp); return false; }
    return true;
}

// ── Site problems (Admin → Settings → Maintenance → Site problems; Home → Needs a look): what went wrong, in the site's own short log,
// private/logs/problems.log, one JSON line each {at, where, msg, page}; only the newest PROBLEMS_KEEP lines are kept. No visitor
// data: the page is the address without its query, never an IP, name or Patreon id. ──
const PROBLEMS_KEEP = 200;

function m3d_problems_file(): string { return M3D_PRIVATE . '/logs/problems.log'; }

/** Notes a problem ("Discord", "Login"…) in the site's log and PHP's own error log ($php false: PHP logged it already). Never throws. */
function m3d_problem(string $where, string $msg, bool $php = true): void
{
    if ($php) error_log("[site $where] $msg");
    try {
        $file = m3d_problems_file();
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        $page = PHP_SAPI === 'cli' ? '' : (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
        $line = json_encode(['at' => time(), 'where' => mb_substr($where, 0, 40), 'msg' => mb_substr(trim($msg), 0, 600), 'page' => mb_substr($page, 0, 200)],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line === false || @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX) === false) return;
        clearstatcache(true, $file);
        if (@filesize($file) > 400 * 1024) {   // about 2× the lines kept: cut back to the newest ones
            $fh = @fopen($file, 'c+');
            if ($fh && flock($fh, LOCK_EX)) {
                $lines = array_slice(array_filter(explode("\n", (string) stream_get_contents($fh))), -PROBLEMS_KEEP);
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, implode("\n", $lines) . "\n");
                flock($fh, LOCK_UN);
            }
            if ($fh) fclose($fh);
        }
        if (function_exists('m3d_notify_problem')) m3d_notify_problem($where, $msg);   // the creator's Discord channel, when switched on
    } catch (Throwable) {
        // the log itself failing must never break a page
    }
}

/** The problems noted, newest first: [{at, where, msg, page}]. $since: only those from that time on. */
function m3d_problems(int $since = 0): array
{
    $out = [];
    foreach (array_reverse(@file(m3d_problems_file(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) as $l) {
        $p = json_decode($l, true);
        if (!is_array($p) || !isset($p['at'], $p['msg']) || (int) $p['at'] < $since) continue;
        $out[] = ['at' => (int) $p['at'], 'where' => (string) ($p['where'] ?? ''), 'msg' => (string) $p['msg'], 'page' => (string) ($p['page'] ?? '')];
    }
    return $out;
}

/** data/<name>.json decoded, null when it is missing or damaged. */
function m3d_read_json(string $name): ?array
{
    $f = M3D_PRIVATE . "/data/$name.json";
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return is_array($d) ? $d : null;
}

/**
 * A few facts worked out from a big data file, for what every page shows (the menu, a count in the header), so those pages don't read the
 * whole file: $make() reads the data and returns the facts, kept in private/cache/summary-<name>.json with the data file's stamp (time,
 * size, inode: every save writes a new file) and made again whenever the file changed in any way (a save, a restore, a hand edit).
 * $remake = work it out now (after a save in this request). The cache folder is safe to delete.
 */
function m3d_data_summary(string $name, callable $make, bool $remake = false): array
{
    static $memo = [];
    if (isset($memo[$name]) && !$remake) return $memo[$name];
    $src = M3D_PRIVATE . "/data/$name.json";
    clearstatcache(true, $src);
    $st = @stat($src);
    $stamp = $st ? $st['mtime'] . ':' . $st['size'] . ':' . $st['ino'] : '';
    $file = M3D_PRIVATE . "/cache/summary-$name.json";
    if (!$remake && $stamp !== '' && is_file($file)) {
        $c = json_decode((string) file_get_contents($file), true);
        if (is_array($c) && ($c['stamp'] ?? '') === $stamp && is_array($c['data'] ?? null)) return $memo[$name] = $c['data'];
    }
    $data = $make();
    if ($stamp !== '') {
        if (!is_dir(dirname($file))) @mkdir(dirname($file), 0755, true);
        m3d_atomic_write($file, (string) json_encode(['stamp' => $stamp, 'data' => $data]));
    }
    return $memo[$name] = $data;
}

/** A secret kept in data/.<name>-key, made on first use: the HMAC keys of unlock cookies ('unlock'), download links ('download') and likes ('likes'). */
function m3d_secret(string $name): string
{
    static $keys = [];
    if (isset($keys[$name])) return $keys[$name];
    $kf = M3D_PRIVATE . "/data/.$name-key";
    $key = is_file($kf) ? (string) file_get_contents($kf) : '';
    if (strlen($key) < 32) {
        // made once: two first requests at the same moment must not each write their own key (a new likes key would lose every like)
        $new = bin2hex(random_bytes(32));
        if (($fh = @fopen($kf, 'x')) !== false) { fwrite($fh, $new); fclose($fh); $key = $new; }
        else { usleep(50000); $key = (string) @file_get_contents($kf); }
        if (strlen($key) < 32) { $key = $new; m3d_atomic_write($kf, $new); }   // unreadable or damaged: replaced, as before
    }
    return $keys[$name] = $key;
}

function m3d_save_items(string $kind, array $items): bool
{
    usort($items, fn($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
    $due = m3d_settings(true)['release']['next'] ?? null;
    if (is_int($due) && $due <= time()) m3d_release_run();   // a release date that passed posts first, or this save would mark its version as sent
    $old = m3d_items($kind, true);   // before the save: Discord announcements post the versions this save makes visible
    $ok = m3d_write_json($kind, ['version' => 1, 'updated' => gmdate('c'), $kind => array_values($items)]);
    m3d_items($kind, true);   // later reads in this request see the save
    if ($ok) m3d_discord_after_save($kind, $old, $items);
    if ($ok) m3d_release_after_save($kind, $old, $items);   // release dates: what the first visit after each date posts
    if ($ok) m3d_bugs_after_save($kind, $old, $items);   // "#6" newly written in a version's changes: that report is fixed in it
    return $ok;
}


/** FAQ: data/faq.json (Admin → FAQ); empty until written there. */
function m3d_faq(bool $fresh = false): array
{
    static $faq = null;
    if ($faq !== null && !$fresh) return $faq;
    return $faq = (m3d_read_json('faq') ?? []) + ['intro' => '', 'sections' => []];
}

function m3d_save_faq(array $faq): bool
{
    $ok = m3d_write_json('faq', ['version' => 1, 'updated' => gmdate('c')] + $faq);
    m3d_faq(true);
    return $ok;
}

/** Documentation: {sections: [{title, pages: [{slug, title, summary, body}]}]}: data/docs.json (Admin → Docs); empty until written there. */
function m3d_docs(bool $fresh = false): array
{
    static $docs = null;
    if ($docs !== null && !$fresh) return $docs;
    return $docs = (m3d_read_json('docs') ?? []) + ['sections' => []];
}

function m3d_save_docs(array $docs): bool
{
    $ok = m3d_write_json('docs', ['version' => 1, 'updated' => gmdate('c'), 'sections' => array_values($docs['sections'] ?? [])]);
    m3d_docs(true);
    return $ok;
}

/** The menus link the docs and the FAQ only when they have something to show (a fresh site has neither). */
function m3d_has_docs(): bool { return m3d_doc_pages(true) !== []; }
function m3d_has_faq(): bool { foreach (m3d_faq()['sections'] ?? [] as $s) if (!empty($s['items'])) return true; return false; }

/** Every docs page in order, with its section title. $public: without the drafts (pages with 'draft' => true). */
function m3d_doc_pages(bool $public = false): array
{
    $pages = [];
    foreach (m3d_docs()['sections'] as $sec) foreach ($sec['pages'] ?? [] as $p) {
        if ($public && !empty($p['draft'])) continue;
        $pages[] = $p + ['section' => $sec['title'] ?? ''];
    }
    return $pages;
}

/** Address of the docs home (/docs/): the first page that is not a draft (or the first page, when all are drafts). */
function m3d_docs_home(): string
{
    return (m3d_doc_pages(true)[0] ?? m3d_doc_pages()[0] ?? ['slug' => ''])['slug'];
}

/** Long texts in the simple text format (FAQ answers, docs pages, blog posts): [{key, name, title, section, folder, text}] for image tools. */
function m3d_texts(): array
{
    $out = [];
    // FAQ keys: 'faq:intro', or 'faq:<n>' for the n-th question (counted across all topics); 'section' = the docs section / FAQ topic
    $out[] = ['key' => 'faq:intro', 'name' => 'FAQ: Intro', 'title' => 'Intro', 'section' => '', 'folder' => 'faq', 'text' => (string) (m3d_faq()['intro'] ?? '')];
    $n = 0;
    foreach (m3d_faq()['sections'] ?? [] as $sec) foreach ($sec['items'] ?? [] as $it) {
        $q = trim((string) ($it['q'] ?? '')) ?: 'Question ' . ($n + 1);
        $out[] = ['key' => 'faq:' . $n++, 'name' => 'FAQ: ' . $q, 'title' => $q, 'section' => (string) ($sec['title'] ?? ''), 'folder' => 'faq', 'text' => (string) ($it['a'] ?? '')];
    }
    foreach (m3d_doc_pages() as $p) $out[] = ['key' => 'docs:' . $p['slug'], 'name' => 'Docs: ' . ($p['title'] ?? $p['slug']), 'title' => (string) ($p['title'] ?? $p['slug']), 'section' => $p['section'], 'folder' => 'docs', 'text' => (string) ($p['body'] ?? '')];
    // blog posts: 'blog:<id>' (the cover is added by usage_map())
    foreach (m3d_blog()['posts'] as $p) $out[] = ['key' => 'blog:' . $p['id'], 'name' => 'Blog: ' . $p['title'], 'title' => (string) $p['title'], 'section' => 'Blog', 'folder' => 'blog', 'text' => (string) ($p['body'] ?? '')];
    return $out;
}

/** Replaces addresses (old => new) inside FAQ answers, docs pages and blog posts (their covers too), saving only what changed. */
function m3d_texts_replace(array $map): bool
{
    $ok = true;
    $faq = m3d_faq(true);   // fresh: called after long jobs (optimize, outside images), edits saved meanwhile stay
    $newFaq = $faq;
    $newFaq['intro'] = strtr((string) ($faq['intro'] ?? ''), $map);
    foreach ($newFaq['sections'] as &$sec) foreach ($sec['items'] as &$it) $it['a'] = strtr((string) $it['a'], $map);
    unset($sec, $it);
    if ($newFaq !== $faq) $ok = m3d_save_faq(['intro' => $newFaq['intro'], 'sections' => $newFaq['sections']]);
    $docs = m3d_docs(true);
    $newDocs = $docs;
    foreach ($newDocs['sections'] as &$sec) foreach ($sec['pages'] as &$p) $p['body'] = strtr((string) $p['body'], $map);
    unset($sec, $p);
    if ($newDocs !== $docs) $ok = m3d_save_docs($newDocs) && $ok;
    return m3d_blog_replace($map) && $ok;
}

// ── Site settings (Admin → Settings), data/settings.json ─────────────────────
/** The message when a data file can't be saved. */
function m3d_write_error(string $what = 'settings'): string
{
    return "Could not write the $what file. Check that " . basename(M3D_PRIVATE) . '/data is writable.';
}

function m3d_settings(bool $fresh = false): array
{
    static $set = null;
    if ($set !== null && !$fresh) return $set;
    return $set = m3d_read_json('settings') ?? [];
}

/** Changes some settings (a null value removes that key). */
function m3d_save_settings(array $changes): bool
{
    $all = array_merge(m3d_settings(true), $changes);
    foreach ($changes as $k => $v) if ($v === null) unset($all[$k]);
    unset($all['version'], $all['updated']);
    $ok = m3d_write_json('settings', ['version' => 1, 'updated' => gmdate('c')] + $all);
    m3d_settings(true);
    return $ok;
}

/** Saves some keys of one settings.json section (settings.json → $key), keeping its other keys (read fresh). */
function m3d_save_setting_keys(string $key, array $changes): bool
{
    $cur = m3d_settings(true)[$key] ?? [];
    return m3d_save_settings([$key => array_merge(is_array($cur) ? $cur : [], $changes)]);
}

/** Maintenance mode (Admin → Settings): null when off, else ['message' => string, 'since' => ISO date]. Admins pass. */
function m3d_maintenance(): ?array
{
    $m = m3d_settings()['maintenance'] ?? null;
    if (!is_array($m) || empty($m['on'])) return null;
    return ['message' => (string) ($m['message'] ?? ''), 'since' => (string) ($m['since'] ?? '')];
}

/**
 * Site notice (Admin → Settings): a coloured bar under the header. settings.json → notice:
 * {on, type: info|update|success|warning|alert, text, where: all|home, until: 'YYYY-MM-DD' or '', closable}.
 * Returns the notice when it should show now (on, has text, not past its last day), else null.
 */
function m3d_notice(): ?array
{
    $n = m3d_settings()['notice'] ?? null;
    if (!is_array($n) || empty($n['on']) || trim((string) ($n['text'] ?? '')) === '') return null;
    $until = (string) ($n['until'] ?? '');
    if ($until !== '' && m3d_today() > $until) return null;
    return $n;
}

/** True when the current visitor is stopped by maintenance mode (it is on and they are not an admin). */
function m3d_maintenance_blocks(): bool
{
    return m3d_maintenance() !== null && !m3d_is_admin($GLOBALS['M3D']);
}

/** Social links shown in the footer, in order: [{type, url}] (Admin → Settings → Social links; none until added). */
function m3d_social(): array
{
    $list = m3d_settings()['social'] ?? [];
    if (!is_array($list)) return [];
    return array_values(array_filter($list, fn($l) => is_array($l) && isset(SOCIAL_TYPES[$l['type'] ?? '']) && !empty($l['url'])));
}

/** Address of a network (the first one of that type in the settings), '' when that network isn't set. */
function social_url(string $type): string
{
    foreach (m3d_social() as $l) if ($l['type'] === $type) return $l['url'];
    return '';   // not set: callers leave out their link or button
}

// ── CSRF (admin forms) ───────────────────────────────────────────────────────
function m3d_csrf(): string
{
    if (!empty($_SESSION['csrf'])) return $_SESSION['csrf'];   // also after m3d_session_release()
    if (session_status() !== PHP_SESSION_ACTIVE) m3d_start_session();
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function m3d_check_csrf(mixed $token): bool   // mixed: a crafted csrf[]= is refused, not a 500
{
    return is_string($token) && is_string($_SESSION['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $token);
}
