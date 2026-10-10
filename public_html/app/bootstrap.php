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
$M3D = array_merge($M3D, m3d_patreon_saved());   // Admin → Settings → Patreon overrides the keys of config.php
$GLOBALS['M3D'] = $M3D;   // also reachable from functions

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

/** Who runs the site, for the privacy page: Admin → Settings → Legal & contact (settings.json → legal), else config.php → legal. */
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

/** The website support address (Contacts): Admin → Settings → Legal & contact, else config.php → support_email. */
function m3d_support_email(): string
{
    $v = trim((string) (m3d_settings()['support_email'] ?? ''));
    if ($v !== '') return $v;
    $c = trim((string) ($GLOBALS['M3D']['support_email'] ?? ''));
    return m3d_cfg_real($c) ? $c : '';
}

/** Patreon keys that Admin → Settings → Patreon may set (saved in private/patreon.php, else config.php). */
const M3D_PATREON_KEYS = ['client_id', 'client_secret', 'redirect_uri', 'campaign_id'];

/**
 * The Patreon keys saved in Admin → Settings → Patreon: private/patreon.php (a PHP file, so its secret is never
 * readable over the web, outside data/ so it is never in backups or the change history, like config.php). Empty = not saved.
 */
function m3d_patreon_saved(): array
{
    $f = M3D_PRIVATE . '/patreon.php';
    $d = is_file($f) ? (include $f) : [];
    return is_array($d) ? array_filter(array_intersect_key($d, array_flip(['client_id', 'client_secret', 'redirect_uri', 'campaign_id'])), fn($v) => is_string($v) && $v !== '') : [];
}

/** Writes private/patreon.php (only the non-empty keys); an empty list deletes it, so config.php counts again. */
function m3d_patreon_save(array $keys): bool
{
    $f = M3D_PRIVATE . '/patreon.php';
    $keys = array_filter(array_intersect_key($keys, array_flip(M3D_PATREON_KEYS)), fn($v) => is_string($v) && $v !== '');
    if (!$keys) return !is_file($f) || @unlink($f);
    $php = "<?php\n// Patreon keys saved in Admin → Settings → Patreon (they override config.php). Delete this file to use config.php again.\nreturn " . var_export($keys, true) . ";\n";
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
    'released-patreon' => ['Released on Patreon', 'mustard'],
    'preview'          => ['Preview on Patreon', 'mustard'],
    'patreon-only'     => ['Patreon only', 'violet'],
    'in-development'   => ['In development', 'grey'],
];

function m3d_is_https(): bool
{
    // Cloudflare may talk to the server over HTTP while the visitor uses HTTPS
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

// ── Session ──────────────────────────────────────────────────────────────────
/** How long a login lasts, in seconds: Admin → Settings → Stay logged in (`login_days`), else config 'session_lifetime' (30 days). */
function m3d_session_lifetime(): int
{
    $days = (int) (m3d_settings()['login_days'] ?? 0);
    return $days > 0 ? min(365, $days) * 86400 : max(3600, (int) ($GLOBALS['M3D']['session_lifetime'] ?? 30 * 86400));
}

function m3d_start_session(array $cfg): void
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
 * logged in (`started`) longer ago than the login lasts, or unused that long. At most once a day unless $force.
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
        $old = (int) @filemtime($f) < $cut
            || (preg_match('/(?:^|;|\})started\|i:(\d+);/', (string) @file_get_contents($f), $m) && (int) $m[1] < $cut);
        if ($old && @unlink($f)) $n++;
    }
    return $n;
}

/** The session cookie only exists for people who log in (privacy policy, § 25 TDDDG): visitors get no cookie at all. */
if (isset($_COOKIE['m3d_session'])) {
    m3d_start_session($M3D);
    if ($_SESSION && empty($_SESSION['started'])) $_SESSION['started'] = time();   // logins from before this was stored
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

function m3d_redirect(string $to, int $code = 302): never
{
    header('Cache-Control: no-store');
    header('Location: ' . $to, true, $code);
    exit;
}

/** Only allow returning to a path on this site (blocks open redirects). */
function m3d_safe_return(?string $path, string $fallback = '/'): string
{
    if (!is_string($path) || $path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\')) {
        return $fallback;
    }
    return preg_match('#^/[A-Za-z0-9/_\-.%?=&\#]*$#', $path) ? $path : $fallback;
}

function m3d_http(string $method, string $url, array $headers = [], ?array $form = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: CatalogSite/1.0'], $headers);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
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
 * Decides whether a Patreon identity response may download.
 * Rule: active patron of OUR campaign holding at least one PAID tier.
 *  - custom contributions without a tier → no tiers → 'no-tier'
 *  - free members → free tier (0 cents) or no patron status → 'no-tier' / 'not-patron'
 *  - failed payment → 'declined'
 * Returns ['access' => bool, 'reason' => string, 'id' => string, 'name' => string, 'tiers' => string[], plus the membership
 * details used by per-model requirements and the My account page: 'status', 'since' (ISO, start of the current membership),
 * 'total_cents' (everything paid to the campaign), 'entitled_cents', 'cadence' (months between charges),
 * 'last_charge_date', 'last_charge_status', 'currency', 'tier_list' [[title, amount_cents]], 'member' (bool)].
 * These stay in the session only.
 */
function m3d_evaluate_identity(array $identity, array $cfg): array
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
        $out = array_merge($out, [
            'member' => true,
            'status' => $status,
            'since' => $a['pledge_relationship_start'] ?? null,
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

/** Whole months from an ISO date until now (null when unknown). */
function m3d_months_since(?string $iso): ?int
{
    if (!$iso || !($d = date_create_immutable($iso))) return null;
    $diff = $d->diff(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    return $diff->invert ? 0 : $diff->y * 12 + $diff->m;
}

function m3d_plural(int $n, string $word): string
{
    return $n . ' ' . $word . ($n === 1 ? '' : 's');
}

/** The extra requirements of a model/outfit: ['months' => int, 'total_cents' => int] (0 = none). */
function m3d_requirements(array $item): array
{
    $r = ['months' => max(0, (int) ($item['requireMonths'] ?? 0)), 'total_cents' => max(0, (int) ($item['requireTotalCents'] ?? 0))];
    // with both set: 'and' = both needed (default), 'or' = either one is enough
    return $r + ['mode' => $r['months'] && $r['total_cents'] && ($item['requireMode'] ?? '') === 'or' ? 'or' : 'and'];
}

/**
 * Checks a patron against an item's extra requirements. The paid-tier rule ($patron['access']) is checked separately
 * and always first: these only add conditions. Returns ['ok' => bool, 'unknown' => bool, 'rows' => [[label, need, have, ok]],
 * 'mode' => 'and'|'or'] (or: one passing row is enough).
 * 'unknown' = the session predates these checks (log in again to fetch the data).
 */
function m3d_check_requirements(array $item, ?array $patron): array
{
    $r = m3d_requirements($item);
    $rows = [];
    if (!$r['months'] && !$r['total_cents']) return ['ok' => true, 'unknown' => false, 'rows' => []];
    // owners and people with an "extra requirements" exception pass every row
    $owner = ($patron['reason'] ?? '') === 'owner' || !empty($patron['exception']['extra']);
    $known = $patron !== null && array_key_exists('total_cents', $patron);
    $months = $patron ? m3d_months_since($patron['since'] ?? null) : null;
    $total = (int) ($patron['total_cents'] ?? 0);
    if ($r['months']) $rows[] = ['Time as a patron', 'at least ' . m3d_plural($r['months'], 'month'), $months === null ? '—' : m3d_plural($months, 'month'), $owner || ($months !== null && $months >= $r['months'])];
    $cur = $patron['currency'] ?? null;   // amounts are in the campaign currency, as Patreon reports them
    if ($r['total_cents']) $rows[] = ['Total support', 'at least ' . m3d_money($r['total_cents'], $cur), m3d_money($total, $cur), $owner || $total >= $r['total_cents']];
    $ok = $r['mode'] === 'or' ? in_array(true, array_column($rows, 3), true) : !in_array(false, array_column($rows, 3), true);
    return ['ok' => $ok, 'unknown' => !$owner && !$known, 'rows' => $rows, 'mode' => $r['mode']];
}

// ── Visibility of a model/outfit: public (default) | blurred | blurred-patrons | password | draft ──
//   blurred:  listed and readable, but every picture is replaced by a tiny blurred copy (blur_src(): the real file never reaches the page)
//   blurred-patrons: the same for visitors, but paying patrons (and admins) see the real pictures
//   password: the page and its downloads open after the password (cookie m3d_unlock); listed with a blurred picture unless 'unlisted'
//   draft:    only admins see it; 404 for everyone else
const M3D_VISIBILITY = [
    'public'   => 'Public',
    'blurred'  => 'Blurred pictures (teaser)',
    'blurred-patrons' => 'Blurred pictures, clear for patrons',
    'password' => 'Password protected',
    'draft'    => 'Draft (hidden)',
];
const M3D_UNLOCK_COOKIE = 'm3d_unlock';
const M3D_UNLOCK_DAYS = 30;

function m3d_visibility(array $item): string
{
    $v = (string) ($item['visibility'] ?? 'public');
    return isset(M3D_VISIBILITY[$v]) ? $v : 'public';
}

/** Cookie entry proving the password of one item was entered. Changing the password (new hash) invalidates it. */
function m3d_unlock_sig(string $kind, array $item): string
{
    $kf = M3D_PRIVATE . '/data/.unlock-key';
    $key = is_file($kf) ? (string) file_get_contents($kf) : '';
    if (strlen($key) < 32) { $key = bin2hex(random_bytes(32)); @file_put_contents($kf, $key); }
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
    return $v !== 'draft' && !($v === 'password' && !empty($item['unlisted']));
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
    if ($requireFresh && (time() - (int) ($p['checked_at'] ?? 0)) > (int) $cfg['recheck_after']) return null;
    if (($pv = m3d_preview()) !== null) $p = m3d_preview_patron($p, $pv);   // admin previewing the site as a patron
    return m3d_apply_exception($p);
}

// ── Preview as a patron (admins only): the bar on top of the site sets a made-up membership ──
// $_SESSION['preview'] = {tier: ok|no-tier|not-patron|declined, months, total_cents, dl_models, dl_outfits (made-up downloads
// of other items, a minute ago, so they count in every period), as: Patreon id or ''}. While it is on,
// m3d_patron() returns that patron instead of the admin (with the download history and user exception of `as`, if set),
// so every page, the download box, requirements, limits and My account behave as for them. m3d_is_admin() keeps reading
// the real login. Downloads in preview stop at a "would start now" page: nothing is downloaded, logged or counted.
const PREVIEW_TIERS = ['ok' => 'Paid tier', 'no-tier' => 'Support without a tier', 'declined' => 'Payment declined', 'not-patron' => 'Not a member'];

function m3d_preview(): ?array
{
    $pv = $_SESSION['preview'] ?? null;
    return is_array($pv) && m3d_is_admin($GLOBALS['M3D']) ? $pv : null;
}

function m3d_preview_patron(array $real, array $pv): array
{
    $tier = isset(PREVIEW_TIERS[$pv['tier'] ?? '']) ? $pv['tier'] : 'ok';
    $months = max(0, (int) ($pv['months'] ?? 0));
    $member = $tier !== 'not-patron';
    return [
        'id' => ($pv['as'] ?? '') !== '' ? (string) $pv['as'] : 'preview', 'name' => 'Preview',
        'access' => $tier === 'ok', 'reason' => $tier, 'tiers' => $tier === 'ok' ? ['Preview tier'] : [],
        'member' => $member, 'status' => $member ? ($tier === 'declined' ? 'declined_patron' : 'active_patron') : null,
        'since' => $member ? gmdate('c', strtotime("-$months months -1 day")) : null,
        'total_cents' => max(0, (int) ($pv['total_cents'] ?? 0)), 'entitled_cents' => $tier === 'ok' ? 500 : 0, 'cadence' => $tier === 'ok' ? 1 : null,
        'last_charge_date' => null, 'last_charge_status' => $tier === 'declined' ? 'Declined' : null,
        'currency' => $real['currency'] ?? null, 'tier_list' => $tier === 'ok' ? [['Preview tier', 500]] : [],
        'checked_at' => time(), 'preview' => true,
        'preview_dl' => ['models' => max(0, (int) ($pv['dl_models'] ?? 0)), 'outfits' => max(0, (int) ($pv['dl_outfits'] ?? 0))],
        'preview_updates' => max(0, (int) ($pv['updates'] ?? 0)),
    ];
}

// ── Exceptions (Admin → Access): people with special access, who skip some download rules ──
// data/exceptions.json: {people: [{id, name, note, tier: bool, extra: bool, added}]}
//   tier:  downloads (and likes, patron pictures) without a paid tier: free Patreon account, no tier, declined payment
//   extra: the extra requirements of models/outfits (months as a patron, total support) aren't checked
//   limits: the download limits (Admin → Access → Download limits) don't apply
// Applied every time the session is read (m3d_patron), so changes count at once, without logging in again.
function m3d_exceptions(bool $fresh = false): array
{
    static $list = null;
    if ($list !== null && !$fresh) return $list;
    $f = M3D_PRIVATE . '/data/exceptions.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return $list = array_values(array_filter((array) ($d['people'] ?? []), fn($x) => is_array($x) && ($x['id'] ?? '') !== ''));
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

/** Adds the exception (if any) to the patron from the session: 'exception' => [tier, extra, limits]; tier also opens downloads. */
function m3d_apply_exception(array $p): array
{
    $x = m3d_exception((string) ($p['id'] ?? ''));
    if (!$x || ($p['reason'] ?? '') === 'owner') return $p;
    $p['exception'] = ['tier' => !empty($x['tier']), 'extra' => !empty($x['extra']), 'limits' => !empty($x['limits'])];
    if (!empty($x['tier']) && empty($p['access'])) {
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

/** Log line: date (UTC), Patreon id, name, item ("slug" / "outfit:slug"), link label, version downloaded ("Beta 2", "V4"; older lines have none). */
function m3d_log_download(array $cfg, array $patron, string $model, string $label = '', string $version = ''): void
{
    if (empty($cfg['log_downloads'])) return;
    $dir = M3D_PRIVATE . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $fh = @fopen($dir . '/downloads.csv', 'ab');
    if (!$fh) return;
    fputcsv($fh, [gmdate('c'), $patron['id'] ?? '', $patron['name'] ?? '', $model, $label, $version], ',', '"', '');
    fclose($fh);
    m3d_prune_log($cfg);
}

// ── Download wait and limits (Admin → Access → Downloads; settings.json → downloads) ──
//   wait:  seconds of the "Your download starts in…" page before every download (0 = off). Enforced by the server with a
//          signed, time-stamped link (m3d_dl_token): no cookie, no session needed, and the real link is never in the page.
//   rules: [{id, on, max, what: models|outfits|all, per: day|week|month, from, to}]: at most `max` DIFFERENT items per
//          rolling period for patrons whose months as a patron are >= from (if set) and < to (if set). Counted from the
//          download log (patron links only): an item counts once, when first downloaded; downloading it again is always
//          free (as long as the log remembers it: log_retention_days).
const DL_PERIODS = ['day' => [86400, 'day'], 'week' => [604800, 'week'], 'month' => [2592000, '30 days']];
const DL_WHAT = ['models' => ['model', 'models'], 'outfits' => ['accessory', 'accessories'], 'all' => ['download', 'downloads']];

function m3d_dl_settings(): array
{
    $d = m3d_settings()['downloads'] ?? [];
    return ['wait' => max(0, min(60, (int) ($d['wait'] ?? 10))), 'rules' => array_values(array_filter((array) ($d['rules'] ?? []), 'is_array'))];
}

function m3d_dl_key(): string
{
    $kf = M3D_PRIVATE . '/data/.download-key';
    $key = is_file($kf) ? (string) file_get_contents($kf) : '';
    if (strlen($key) < 32) { $key = bin2hex(random_bytes(32)); @file_put_contents($kf, $key); }
    return $key;
}

/** "<time>-<signature>" for one download link, made when the waiting page is shown. */
function m3d_dl_token(string $kind, string $slug, int $n, ?int $issued = null): string
{
    $issued ??= time();
    return $issued . '-' . substr(hash_hmac('sha256', "$kind|$slug|$n|$issued", m3d_dl_key()), 0, 24);
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

/**
 * "1 model unlock per week for patrons with less than 3 months" (an unlock = the first download of an item).
 * $short (My account): "1 model / week · under 3 months", "5 unlocks / 30 days · everyone".
 */
function m3d_rule_text(array $r, bool $short = false): string
{
    $what = isset(DL_WHAT[$r['what'] ?? '']) ? $r['what'] : 'models';
    $max = max(1, (int) ($r['max'] ?? 1));
    $per = DL_PERIODS[$r['per'] ?? 'week'][1] ?? 'week';
    if ($short) {
        $from = isset($r['from']) && $r['from'] !== '' ? (int) $r['from'] : null;
        $to = isset($r['to']) && $r['to'] !== '' ? (int) $r['to'] : null;
        $who = match (true) {
            $from !== null && $to !== null => "{$from}–" . m3d_plural($to, 'month'),
            $to !== null                   => 'under ' . m3d_plural($to, 'month'),
            $from !== null                 => "$from+ months",
            default                        => 'everyone',
        };
        $nb = fn(string $t) => str_replace(' ', "\u{a0}", $t);   // "3+ months", "30 days" never split over two lines
        return $max . "\u{a0}" . ($what === 'all' ? ($max === 1 ? 'unlock' : 'unlocks') : DL_WHAT[$what][$max === 1 ? 0 : 1]) . ' / ' . $nb($per) . ' · ' . $nb($who);
    }
    $text = $max . ' ' . ($what === 'all' ? '' : DL_WHAT[$what][0] . ' ') . ($max === 1 ? 'unlock' : 'unlocks') . ' per ' . $per;
    $from = isset($r['from']) && $r['from'] !== '' ? (int) $r['from'] : null;
    $to = isset($r['to']) && $r['to'] !== '' ? (int) $r['to'] : null;
    if ($from !== null && $to !== null) $text .= " for patrons with at least $from and less than " . m3d_plural($to, 'month');
    elseif ($to !== null) $text .= ' for patrons with less than ' . m3d_plural($to, 'month');
    elseif ($from !== null) $text .= ' for patrons with ' . m3d_plural($from, 'month') . ' or more';
    else $text .= ' for every patron';
    return $text;
}

function m3d_rule_applies(array $r, int $months, string $kind): bool
{
    if (empty($r['on'])) return false;
    if (($r['what'] ?? 'models') !== 'all' && ($r['what'] ?? 'models') !== $kind) return false;
    if (isset($r['from']) && $r['from'] !== '' && $months < (int) $r['from']) return false;
    if (isset($r['to']) && $r['to'] !== '' && $months >= (int) $r['to']) return false;
    return true;
}

/** A patron's downloads in the log, oldest first: [[timestamp, kind, slug, version, link label], …] (patron links only: the log has nothing else; version '' on old lines). */
function m3d_patron_log(string $patronId): array
{
    // read once per page: the limits, the header counter, the unlocked checks and the updates all ask for it
    static $cache = [];
    if (isset($cache[$patronId])) return $cache[$patronId];
    $out = [];
    $fh = @fopen(M3D_PRIVATE . '/logs/downloads.csv', 'rb');
    if (!$fh || $patronId === '') return $out;
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (($r[1] ?? '') !== $patronId || !isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
        [$k, $s] = str_starts_with($r[3], 'outfit:') ? ['outfits', substr($r[3], 7)] : ['models', $r[3]];
        $out[] = [$t, $k, $s, (string) ($r[5] ?? ''), (string) ($r[4] ?? '')];
    }
    fclose($fh);
    return $cache[$patronId] = $out;
}

/**
 * A patron's downloads: the log, plus in the preview the made-up ones: preview_dl (other items, a minute ago) and
 * preview_updates (the first N listed items with a dated latest version, "downloaded" long before it, so they show an
 * update, count as unlocked and stay out of every limit period; their version is the third newest, m3d_preview_got()).
 */
function m3d_patron_history(?array $patron): array
{
    $log = m3d_patron_log((string) ($patron['id'] ?? ''));
    foreach ($patron['preview_dl'] ?? [] as $k => $count) for ($i = 0; $i < $count; $i++) $log[] = [time() - 60 - $i, $k, "~preview-$i"];
    $n = (int) ($patron['preview_updates'] ?? 0);
    foreach (['models', 'outfits'] as $k) foreach (m3d_listed_items($k) as $it) {
        if ($n <= 0) break 2;
        if (($rel = m3d_changelog_ts(m3d_latest_version($it)['date'])) === null) continue;
        $log[] = [$rel - 400 * 86400, $k, (string) $it['slug'], m3d_preview_got($it)];
        $n--;
    }
    return $log;
}

/** Preview: the version a simulated update was "downloaded" at: the third newest, so two versions are new ('' with fewer). */
function m3d_preview_got(array $item): string
{
    $log = array_values(array_filter($item['changelog'] ?? [], fn($en) => is_array($en) && trim((string) ($en['version'] ?? '')) !== ''));
    return isset($log[2]) ? m3d_version_label($log[2]) : '';
}

/** Preview: the "what's new" of a simulated update always shows two versions: the two newest of the changelog, made-up examples where it has fewer. */
function m3d_preview_versions(array $item): array
{
    $newer = array_slice(array_values(array_filter($item['changelog'] ?? [], fn($en) => is_array($en) && trim((string) ($en['version'] ?? '')) !== '')), 0, 2);
    $example = ['notes' => ['Made up for the preview: this is how the notes of a new version look.'],
                'changes' => ['Example: a new outfit option', 'Example: better shoulder weights', 'Example: fixed a material']];
    for ($i = count($newer); $i < 2; $i++) $newer[] = $example + ['version' => 'Example ' . (2 - $i), 'date' => ''];
    return $newer;
}

/** Models and outfits marked "Not counted in the download limits" in the editor: ['models:<slug>' => true, 'outfits:<slug>' => true]. */
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
 * 'free' => bool (item marked "Not counted in the download limits"), 'owned' => bool (downloaded before: free),
 * 'rows' => [['rule' => …, 'text' => …, 'used' => n, 'max' => n, 'ok' => bool, 'next' => timestamp|null]]] with the
 * rules that apply to this patron (all kinds when $kind is null, e.g. for My account).
 */
function m3d_check_limits(?array $patron, ?string $kind = null, ?string $slug = null): array
{
    $exempt = $patron === null || ($patron['reason'] ?? '') === 'owner' || !empty($patron['exception']['limits']);
    $months = (int) (m3d_months_since($patron['since'] ?? null) ?? 0);
    // for My account ($kind null) every rule matching the patron's months, whatever it counts
    $rules = array_values(array_filter(m3d_dl_settings()['rules'], fn($r) => m3d_rule_applies($r, $months, $kind ?? (($r['what'] ?? 'models') === 'all' ? 'models' : (string) $r['what']))));
    if (!$rules) return ['ok' => true, 'exempt' => $exempt, 'free' => false, 'owned' => false, 'rows' => []];
    $log = m3d_patron_history($patron);   // the log, plus the preview's made-up downloads
    // first download ever of each item: an item counts once, in the period of its first download, and never again
    $firstEver = [];
    $freeItems = m3d_free_items();   // never counted, never limited
    foreach ($log as [$t, $k, $s]) if (!isset($freeItems["$k:$s"])) $firstEver["$k:$s"] = min($firstEver["$k:$s"] ?? PHP_INT_MAX, $t);
    $free = $kind !== null && isset($freeItems["$kind:$slug"]);
    $owned = $kind !== null && isset($firstEver["$kind:$slug"]);   // downloaded before: free, whatever the rules say
    $rows = [];
    $now = time();
    foreach ($rules as $r) {
        [$secs] = DL_PERIODS[$r['per'] ?? 'week'] ?? DL_PERIODS['week'];
        $first = [];   // first downloads of different items inside the period
        foreach ($firstEver as $key => $t) {
            if ($t <= $now - $secs) continue;
            if (($r['what'] ?? 'models') !== 'all' && !str_starts_with($key, $r['what'] . ':')) continue;
            $first[] = $t;
        }
        $max = max(1, (int) ($r['max'] ?? 1));
        $ok = $exempt || $free || $owned || count($first) < $max;
        sort($first);
        // when a slot frees up: the oldest counted download leaves the period
        $next = count($first) >= $max ? $first[count($first) - $max] + $secs : null;
        $rows[] = ['rule' => $r, 'text' => m3d_rule_text($r), 'used' => count($first), 'max' => $max, 'ok' => $ok, 'next' => $next];
    }
    return ['ok' => !in_array(false, array_column($rows, 'ok'), true), 'exempt' => $exempt, 'free' => $free, 'owned' => $owned, 'rows' => $rows];
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
            if (!in_array($r['rule']['what'] ?? 'models', [$k, 'all'], true)) continue;
            $left[$k] = min($left[$k] ?? PHP_INT_MAX, $n);
            if ($n === 0 && $r['next']) $left['next'][$k] = max($left['next'][$k] ?? 0, $r['next']);   // all full rules must free up
        }
    }
    return $left;
}

// ── Counters and likes: small JSON files changed by many visitors at once ────
/** Locked read-modify-write of data/<name>.json (no automatic backups: these change all the time). */
function m3d_json_update(string $name, callable $fn): mixed
{
    $dir = M3D_PRIVATE . '/data';
    $file = "$dir/$name.json";
    $lock = @fopen("$dir/.$name.lock", 'c');
    if (!$lock || !flock($lock, LOCK_EX)) return null;
    try {
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $result = $fn($data);   // $fn gets the data by value and returns [newData, result]
        [$new, $out] = $result;
        if ($new !== $data) {
            $tmp = "$file.tmp-" . bin2hex(random_bytes(3));
            if (@file_put_contents($tmp, json_encode($new, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $file);
            else @unlink($tmp);
        }
        return $out;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Latest version of a model or outfit = the top changelog entry (newest first). Old entries without a changelog
 *  may still have the former "version"/"versionDate" fields. Returns ['version' => '', 'date' => ''] when unknown. */
function m3d_latest_version(array $item): array
{
    foreach ($item['changelog'] ?? [] as $en) {
        if (is_array($en) && trim((string) ($en['version'] ?? '')) !== '') return ['version' => trim((string) $en['version']), 'date' => trim((string) ($en['date'] ?? '')), 'stage' => m3d_stage($en)];
    }
    return ['version' => (string) ($item['version'] ?? ''), 'date' => (string) ($item['versionDate'] ?? ''), 'stage' => 'standard'];
}

/**
 * A changelog date as a timestamp (start of that day, Berlin time): "16/08/2026" (the editor's format), also "16.08.2026",
 * "2026-08-16" or anything strtotime() reads. null when empty or unreadable.
 */
function m3d_changelog_ts(string $d): ?int
{
    $d = trim($d);
    if ($d === '') return null;
    $tz = new DateTimeZone('Europe/Berlin');
    foreach (['!j/n/Y', '!j.n.Y', '!Y-m-d'] as $f) {
        $x = DateTimeImmutable::createFromFormat($f, $d, $tz);
        $err = DateTimeImmutable::getLastErrors();
        if ($x && (!$err || (!$err['warning_count'] && !$err['error_count']))) return $x->getTimestamp();
    }
    $t = strtotime($d . ' Europe/Berlin');
    return $t === false ? null : $t;
}

/** A version as people see it: "Beta 2" / "Alpha 3" when the release has a stage, else just "V4" (from m3d_latest_version() or a changelog entry). */
function m3d_version_label(array $v): string
{
    $ver = trim((string) ($v['version'] ?? ''));
    $stage = m3d_stage($v);
    return $ver === '' ? '' : ($stage !== 'standard' ? M3D_STAGES[$stage] . ' ' : '') . $ver;
}

/**
 * Updates for a patron: the models/outfits they downloaded where a newer version came out after their last download:
 * the version they got is found below the top of the changelog, or (old log lines without a version, a version renamed
 * since) the latest version is dated after the day of that download. ['models' => [slug => version], 'outfits' => [slug => version]] (public and blurred items, not drafts or password ones; the version
 * starts with Alpha / Beta when the release has that stage).
 */
function m3d_updates_for(?array $patron): array
{
    $out = ['models' => [], 'outfits' => []];
    if (!$patron) return $out;
    $last = [];   // "kind:slug" => [time, version] of the latest download
    foreach (m3d_patron_history($patron) as $row) {
        [$t, $k, $s] = $row;
        if ($t >= ($last["$k:$s"][0] ?? 0)) $last["$k:$s"] = [$t, (string) ($row[3] ?? '')];
    }
    foreach ($last as $key => [$t, $got]) {
        [$k, $s] = explode(':', $key, 2);
        $it = m3d_item($k, $s);
        if (!$it || !in_array(m3d_visibility($it), ['public', 'blurred', 'blurred-patrons'], true)) continue;   // removed, or draft / password now: no update shown
        if ($newer = m3d_newer_versions($it, $got, $t)) $out[$k][$s] = m3d_version_label($newer[0]);
    }
    return $out;
}

/**
 * The changelog versions newer than a download (newest first; empty = up to date): the ones above the version downloaded,
 * or (version unknown: old log line, renamed since) the dated ones on top released after the day of the download ($t).
 */
function m3d_newer_versions(array $item, string $got, int $t): array
{
    $log = array_values(array_filter($item['changelog'] ?? [], fn($en) => is_array($en) && trim((string) ($en['version'] ?? '')) !== ''));
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

/** Release stage of a changelog version: 'alpha' | 'beta' | 'standard' (absent = standard). */
const M3D_STAGES = ['standard' => 'Standard', 'alpha' => 'Alpha', 'beta' => 'Beta'];
function m3d_stage(array $en): string
{
    $s = (string) ($en['stage'] ?? '');
    return isset(M3D_STAGES[$s]) ? $s : 'standard';
}

/** Add-ons a model/outfit needs ("Add-ons required" in the download box): [{label, url}], none when not set. */
function m3d_addons(string $kind, array $item): array
{
    return (array) ($item['addons'] ?? []);
}

/** Download totals: {downloads: {models|outfits: {slug: {patrons, public}}}, since}. Started from the download log. */
function m3d_stats(): array
{
    static $s = null;   // read once per request (lists show a count per model/outfit)
    if ($s !== null) return $s;
    $f = M3D_PRIVATE . '/data/stats.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return $s = is_array($d) ? $d : m3d_stats_from_log();
}

function m3d_stats_from_log(): array
{
    $s = ['since' => gmdate('c'), 'downloads' => ['models' => [], 'outfits' => []]];
    $fh = @fopen(M3D_PRIVATE . '/logs/downloads.csv', 'rb');
    if ($fh) {
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if (count($r) < 4 || $r[3] === '') continue;
            $s['since'] = min($s['since'], (string) $r[0]);
            [$kind, $slug] = str_starts_with($r[3], 'outfit:') ? ['outfits', substr($r[3], 7)] : ['models', $r[3]];
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
 * Likes (paying patrons only, one per patron and model/outfit). data/likes.json: {models: {slug: [code, …]}, outfits: {…}}.
 * The code is an HMAC of the Patreon ID with a key kept in the private folder, so the file holds no Patreon IDs.
 */
function m3d_like_code(string $patronId): string
{
    $kf = M3D_PRIVATE . '/data/.likes-key';
    $key = is_file($kf) ? (string) file_get_contents($kf) : '';
    if (strlen($key) < 32) { $key = bin2hex(random_bytes(32)); @file_put_contents($kf, $key); }
    return substr(hash_hmac('sha256', $patronId, $key), 0, 20);
}

/** slug => [codes] for 'models' or 'outfits'. */
function m3d_likes(string $kind = 'models'): array
{
    static $all = null;
    if ($all === null) {
        $f = M3D_PRIVATE . '/data/likes.json';
        $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
        $all = is_array($d) ? $d : [];
    }
    return is_array($all[$kind] ?? null) ? $all[$kind] : [];
}

function m3d_like_count(string $slug, string $kind = 'models'): int
{
    return count(m3d_likes($kind)[$slug] ?? []);
}

/** Sets or removes a patron's like on a model or outfit; returns [count, liked]. */
function m3d_set_like(string $slug, string $patronId, bool $like, string $kind = 'models'): array
{
    $code = m3d_like_code($patronId);
    return m3d_json_update('likes', function ($d) use ($slug, $code, $like, $kind) {
        $d = is_array($d) ? $d : ['models' => []];
        $list = array_values(array_diff($d[$kind][$slug] ?? [], [$code]));
        if ($like) $list[] = $code;
        if ($list) $d[$kind][$slug] = $list; else unset($d[$kind][$slug]);
        return [$d, [count($list), $like]];
    }) ?? [m3d_like_count($slug, $kind), false];
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
}

/** Deletes download log lines older than log_retention_days (runs at most once a day). */
/**
 * Removes one patron's rows from the download log (Admin → Downloads): their download history, and with it what they
 * unlocked, so the limits count from zero again. The all-time counters (stats.json) stay. Returns the rows removed.
 */
function m3d_log_remove_patron(string $patronId): int
{
    $file = M3D_PRIVATE . '/logs/downloads.csv';
    if ($patronId === '' || !is_file($file)) return 0;
    $in = fopen($file, 'rb');
    if (!$in || !flock($in, LOCK_EX)) return 0;
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
    $out = fopen($tmp, 'wb');
    $n = 0;
    while (($line = fgets($in)) !== false) {
        if ((str_getcsv(rtrim($line, "\r\n"), ',', '"', '')[1] ?? null) === $patronId) { $n++; continue; }
        fwrite($out, $line);
    }
    fclose($out);
    if ($n) rename($tmp, $file); else @unlink($tmp);
    flock($in, LOCK_UN);
    fclose($in);
    return $n;
}

function m3d_prune_log(array $cfg, bool $force = false): void
{
    $file = M3D_PRIVATE . '/logs/downloads.csv';
    $mark = M3D_PRIVATE . '/logs/.pruned';
    if (!is_file($file) || (!$force && is_file($mark) && filemtime($mark) > time() - 86400)) return;
    $limit = gmdate('c', time() - max(1, (int) ($cfg['log_retention_days'] ?? 365)) * 86400);
    $in = fopen($file, 'rb');
    if (!$in || !flock($in, LOCK_EX)) return;
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(3));
    $out = fopen($tmp, 'wb');
    while (($line = fgets($in)) !== false) if (strcmp(substr($line, 0, 25), $limit) >= 0) fwrite($out, $line);
    fclose($out);
    rename($tmp, $file);
    flock($in, LOCK_UN);
    fclose($in);
    @touch($mark);
}

// ── Data: models (data/models.json), outfits (data/outfits.json) ────────────
// Both kinds share the same shape for downloads:
//   links: [ { label: "Mega", url: "https://…", access: "patrons" | "public" }, … ]
// Patron links are never printed on a page: they are reached through /get/… after the tier check.
const M3D_KINDS = ['models' => 'models', 'outfits' => 'outfits'];

function m3d_items(string $kind): array
{
    static $cache = [];
    if (!isset(M3D_KINDS[$kind])) throw new InvalidArgumentException("Unknown kind $kind");
    if (isset($cache[$kind])) return $cache[$kind];
    $file = M3D_PRIVATE . "/data/$kind.json";
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    $items = is_array($data[$kind] ?? null) ? $data[$kind] : [];
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
    $tmp = "$file.tmp-" . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $file);
}

function m3d_save_items(string $kind, array $items): bool
{
    usort($items, fn($a, $b) => ($a['order'] ?? 999) <=> ($b['order'] ?? 999));
    return m3d_write_json($kind, ['version' => 1, 'updated' => gmdate('c'), $kind => array_values($items)]);
}

function m3d_save_models(array $models): bool { return m3d_save_items('models', $models); }

/** FAQ: data/faq.json (Admin → FAQ); empty until written there. */
function m3d_faq(): array
{
    static $faq = null;
    if ($faq !== null) return $faq;
    foreach ([M3D_PRIVATE . '/data/faq.json'] as $f) {
        if (is_file($f) && is_array($d = json_decode((string) file_get_contents($f), true))) return $faq = $d + ['intro' => '', 'sections' => []];
    }
    return $faq = ['intro' => '', 'sections' => []];
}

function m3d_save_faq(array $faq): bool
{
    return m3d_write_json('faq', ['version' => 1, 'updated' => gmdate('c')] + $faq);
}

/** Documentation: {sections: [{title, pages: [{slug, title, summary, body}]}]}: data/docs.json (Admin → Docs); empty until written there. */
function m3d_docs(bool $fresh = false): array
{
    static $docs = null;
    if ($docs !== null && !$fresh) return $docs;
    foreach ([M3D_PRIVATE . '/data/docs.json'] as $f) {
        if (is_file($f) && is_array($d = json_decode((string) file_get_contents($f), true))) return $docs = $d + ['sections' => []];
    }
    return $docs = ['sections' => []];
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

/** Long texts in the simple text format (FAQ answers, docs pages): [{key, name, folder, text}] for image tools. */
function m3d_texts(): array
{
    $out = [];
    foreach (m3d_faq()['sections'] ?? [] as $sec) foreach ($sec['items'] ?? [] as $it) $out[] = ['key' => 'faq:faq', 'name' => 'FAQ', 'folder' => 'faq', 'text' => (string) ($it['a'] ?? '')];
    $out[] = ['key' => 'faq:faq', 'name' => 'FAQ', 'folder' => 'faq', 'text' => (string) (m3d_faq()['intro'] ?? '')];
    foreach (m3d_doc_pages() as $p) $out[] = ['key' => 'docs:' . $p['slug'], 'name' => 'Docs: ' . ($p['title'] ?? $p['slug']), 'folder' => 'docs', 'text' => (string) ($p['body'] ?? '')];
    return $out;
}

/** Replaces addresses (old => new) inside FAQ answers and docs pages, saving only what changed. */
function m3d_texts_replace(array $map): bool
{
    $ok = true;
    $faq = m3d_faq();
    $newFaq = $faq;
    $newFaq['intro'] = strtr((string) ($faq['intro'] ?? ''), $map);
    foreach ($newFaq['sections'] as &$sec) foreach ($sec['items'] as &$it) $it['a'] = strtr((string) $it['a'], $map);
    unset($sec, $it);
    if ($newFaq !== $faq) $ok = m3d_save_faq(['intro' => $newFaq['intro'], 'sections' => $newFaq['sections']]);
    $docs = m3d_docs();
    $newDocs = $docs;
    foreach ($newDocs['sections'] as &$sec) foreach ($sec['pages'] as &$p) $p['body'] = strtr((string) $p['body'], $map);
    unset($sec, $p);
    if ($newDocs !== $docs) $ok = m3d_save_docs($newDocs) && $ok;
    return $ok;
}

// ── Site settings (Admin → Settings), data/settings.json ─────────────────────
function m3d_settings(bool $fresh = false): array
{
    static $set = null;
    if ($set !== null && !$fresh) return $set;
    $f = M3D_PRIVATE . '/data/settings.json';
    $d = is_file($f) ? json_decode((string) file_get_contents($f), true) : null;
    return $set = is_array($d) ? $d : [];
}

function m3d_save_settings(array $changes): bool
{
    $all = array_merge(m3d_settings(true), $changes);
    unset($all['version'], $all['updated'], $all['redirects'], $all['adopted']);   // the last two: old entries, no longer used
    $ok = m3d_write_json('settings', ['version' => 1, 'updated' => gmdate('c')] + $all);
    m3d_settings(true);
    return $ok;
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
    if ($until !== '' && date_create_immutable('now', new DateTimeZone('Europe/Berlin'))->format('Y-m-d') > $until) return null;
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

/** Address of a network (the first one of that type in the settings), else the built-in default. */
function social_url(string $type): string
{
    foreach (m3d_social() as $l) if ($l['type'] === $type) return $l['url'];
    return '';   // not set: callers leave out their link or button
}

// ── CSRF (admin forms) ───────────────────────────────────────────────────────
function m3d_csrf(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) m3d_start_session($GLOBALS['M3D']);
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function m3d_check_csrf(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}
