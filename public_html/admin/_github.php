<?php
// Update from Remote (Admin → Settings → Update): the site lists the releases of its update channel and installs one
// when the admin presses Install. Nothing is ever installed by itself.
//   Stable       public repository, no key: the versions published for everyone (workflow "Publish to Stable")
//   Development  private repository, needs a read-only key: every change merged (workflow "Development release")
// Each release is tagged v<version> and carries site-update-<version>.zip + its .sha256 file. The channel and the key are
// kept in private/github.php (a PHP file: never readable over the web, never in backups, so a copy of the site restored
// from another one's backup keeps its own channel). The last check is cached in private/cache/ (CHECK_TTL).
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const GH_API = 'https://api.github.com';
/** channel => [name, repository (config.php → update_repos can change it), needs a key] */
const UPDATE_CHANNELS = [
    'stable' => ['Stable', 'Mustard2/MustardWebsite', false],
    'dev'    => ['Development', 'Mustard2/Website', true],
];
const CHECK_TTL = 900;                 // seconds a check is reused when the page is opened again
const UPDATE_MAX = 25 * 1024 * 1024;   // largest update zip (the same limit as an uploaded one)

/** private/github.php: ['channel' => stable|dev, 'token' => key or '']. */
function gh_saved(): array
{
    $f = M3D_PRIVATE . '/github.php';
    $d = is_file($f) ? (include $f) : [];
    $d = is_array($d) ? $d : [];
    $ch = (string) ($d['channel'] ?? '');
    return ['channel' => isset(UPDATE_CHANNELS[$ch]) ? $ch : 'stable', 'token' => is_string($d['token'] ?? null) ? $d['token'] : ''];
}

function gh_save(string $channel, string $token): bool
{
    $f = M3D_PRIVATE . '/github.php';
    $php = "<?php\n// Admin → Settings → Update: the update channel and the GitHub key of the Development channel. Delete this file to go back to Stable.\nreturn "
        . var_export(['channel' => $channel, 'token' => $token], true) . ";\n";
    $tmp = $f . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $php, LOCK_EX) === false) return false;
    @chmod($tmp, 0600);
    if (!@rename($tmp, $f)) { @unlink($tmp); return false; }
    if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true);
    foreach (array_keys(UPDATE_CHANNELS) as $c) @unlink(gh_cache_file($c));   // a new channel or key: check again
    return true;
}

function gh_repo(string $channel): string
{
    $r = (string) ($GLOBALS['M3D']['update_repos'][$channel] ?? '');
    return preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $r) ? $r : UPDATE_CHANNELS[$channel][1];
}

/** A key is only sent to the Development repository. */
function gh_token(string $channel): string
{
    return UPDATE_CHANNELS[$channel][2] ? gh_saved()['token'] : '';
}

/**
 * One GitHub request. $saveTo: write the body to that file (downloads, at most UPDATE_MAX bytes) instead of returning it.
 * Returns [status, body (null when saved to a file), response headers (lowercase names), curl error].
 */
function gh_request(string $url, string $token = '', string $accept = 'application/vnd.github+json', ?string $saveTo = null): array
{
    $headers = [];
    $ch = curl_init($url);
    $h = ['Accept: ' . $accept, 'User-Agent: CatalogSite-Updater', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($token !== '') $h[] = 'Authorization: Bearer ' . $token;   // curl never sends it on to the download host GitHub redirects to
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $saveTo ? 180 : 20,
        CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) {
            if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $headers[strtolower(trim($k))] = trim($v); }
            return strlen($line);
        },
    ]);
    $fh = null;
    if ($saveTo !== null) {
        $fh = fopen($saveTo, 'wb');
        if (!$fh) throw new RuntimeException('Could not write a temporary file in ' . basename(M3D_PRIVATE) . '/cache.');
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_MAXFILESIZE => UPDATE_MAX, CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => fn($c, $total, $got) => $got > UPDATE_MAX ? 1 : 0]);
    } else {
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    }
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    return [$status, is_string($body) ? $body : null, $headers, $err];
}

/** Why a GitHub answer isn't usable, in words for the admin ('' = fine). */
function gh_problem(string $channel, int $status, array $headers, string $err): string
{
    $repo = gh_repo($channel);
    if ($status === 0) return 'Couldn’t reach GitHub' . ($err !== '' ? " ($err)" : '') . '. Try again in a moment.';
    if ($status === 401) return 'GitHub refused the key: it is wrong or has expired. Make a new one and save it below.';
    if (($status === 403 || $status === 429) && (($headers['x-ratelimit-remaining'] ?? '') === '0')) {
        $at = (int) ($headers['x-ratelimit-reset'] ?? 0);
        return 'GitHub asks this server to wait (too many checks from it)' . ($at ? ', until ' . m3d_site_time($at, 'H:i T') : '') . '.';
    }
    if ($status === 403 || $status === 404) {
        return UPDATE_CHANNELS[$channel][2]
            ? ($channel === 'dev' && gh_token($channel) === '' ? "The Development channel needs a GitHub key that can read $repo." : "The key can’t read $repo: give it access to that repository (Contents: read-only).")
            : "The repository $repo wasn’t found.";
    }
    return $status >= 200 && $status < 300 ? '' : "GitHub answered with an error ($status).";
}

function gh_cache_file(string $channel): string
{
    return M3D_PRIVATE . '/cache/update-' . $channel . '.json';
}

/**
 * The channel's releases, newest first: ['at' => checked, 'error' => '', 'expires' => key expiry ('' = none or unknown),
 * 'releases' => [['v', 'at' (ts), 'notes', 'zip' => asset id, 'size', 'sum' => asset id]]]. Reuses the last check for
 * CHECK_TTL seconds unless $fresh; a failed check only for a minute, so reopening the page soon tries again.
 */
function gh_check(string $channel, bool $fresh = false): array
{
    $cache = gh_cache_file($channel);
    if (!$fresh && is_file($cache)) {
        $c = json_decode((string) @file_get_contents($cache), true);
        if (is_array($c) && ($c['at'] ?? 0) > time() - (($c['error'] ?? '') === '' ? CHECK_TTL : 60)) return $c;
    }
    $save = function (array $out) use ($cache): array {
        if (!is_dir(dirname($cache))) @mkdir(dirname($cache), 0700, true);
        m3d_atomic_write($cache, json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $out;
    };
    $token = gh_token($channel);
    $out = ['at' => time(), 'error' => '', 'expires' => '', 'releases' => []];
    if (UPDATE_CHANNELS[$channel][2] && $token === '') {
        $out['error'] = gh_problem($channel, 404, [], '');
        return $out;
    }
    [$status, $body, $headers, $err] = gh_request(GH_API . '/repos/' . gh_repo($channel) . '/releases?per_page=30', $token);
    $json = json_decode((string) $body, true);
    $out['expires'] = (string) ($headers['github-authentication-token-expiration'] ?? '');
    $out['error'] = gh_problem($channel, $status, $headers, $err);
    if ($out['error'] !== '' || !is_array($json)) {
        if ($out['error'] === '') $out['error'] = 'GitHub’s answer couldn’t be read. Try again in a moment.';
        return $save($out);
    }
    foreach ($json as $r) {
        $v = preg_replace('/^v/', '', (string) ($r['tag_name'] ?? ''));
        if (!empty($r['draft']) || !preg_match('/^\d{4}\.\d{2}\.\d{2}-\d+$/', $v)) continue;
        $zip = $sum = null;
        foreach ($r['assets'] ?? [] as $a) {
            if (($a['name'] ?? '') === "site-update-$v.zip") $zip = $a;
            if (($a['name'] ?? '') === "site-update-$v.zip.sha256") $sum = $a;
        }
        if (!$zip || !$sum) continue;   // a release still being made, or not a site update
        $out['releases'][] = ['v' => $v, 'at' => (int) strtotime((string) ($r['published_at'] ?? '')), 'notes' => mb_substr((string) ($r['body'] ?? ''), 0, 20000),
            'zip' => (int) $zip['id'], 'size' => (int) ($zip['size'] ?? 0), 'sum' => (int) $sum['id']];
    }
    usort($out['releases'], fn($a, $b) => version_compare($b['v'], $a['v']));
    return $save($out);
}

/** The releases newer than the installed code, newest first. */
function gh_newer(array $check): array
{
    return array_values(array_filter($check['releases'], fn($r) => version_compare($r['v'], M3D_VERSION, '>')));
}

/**
 * The last check of $channel as saved, without asking GitHub, for pages that only show it (Admin → Home): when it is missing
 * or older than CHECK_TTL, a new check runs after the page has been sent, so the page never waits for GitHub.
 */
function gh_check_cached(string $channel): ?array
{
    $c = json_decode((string) @file_get_contents(gh_cache_file($channel)), true);
    $c = is_array($c) && isset($c['releases']) ? $c : null;
    if (!$c || ($c['at'] ?? 0) <= time() - CHECK_TTL) {
        m3d_after_response(fn() => gh_check($channel, true));
    }
    return $c;
}

/**
 * Downloads release $version of the channel (asked again from GitHub, never taken from the form), checks it and installs it
 * like an uploaded zip: [backup name, files written, new version].
 */
function gh_install(string $channel, string $version): array
{
    $check = gh_check($channel, true);
    if ($check['error'] !== '') throw new RuntimeException($check['error']);
    $rel = null;
    foreach ($check['releases'] as $r) if ($r['v'] === $version) $rel = $r;
    if (!$rel) throw new RuntimeException("Version $version isn’t on the " . UPDATE_CHANNELS[$channel][0] . ' channel any more. Check again.');
    if (version_compare($version, M3D_VERSION, '<=')) throw new RuntimeException("Version $version isn’t newer than the installed one.");
    if ($rel['size'] > UPDATE_MAX) throw new RuntimeException('This update is too large.');

    $token = gh_token($channel);
    $asset = fn(int $id) => GH_API . '/repos/' . gh_repo($channel) . '/releases/assets/' . $id;
    [$status, $body, $headers, $err] = gh_request($asset($rel['sum']), $token, 'application/octet-stream');
    if ($p = gh_problem($channel, $status, $headers, $err)) throw new RuntimeException($p);
    $sum = strtolower(substr(trim((string) $body), 0, 64));
    if (!preg_match('/^[0-9a-f]{64}$/', $sum)) throw new RuntimeException('The update’s checksum file couldn’t be read. Try again.');

    $dir = M3D_PRIVATE . '/cache';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $tmp = $dir . '/update.tmp-' . bin2hex(random_bytes(4)) . '.zip';
    try {
        [$status, , $headers, $err] = gh_request($asset($rel['zip']), $token, 'application/octet-stream', $tmp);
        if ($p = gh_problem($channel, $status, $headers, $err)) throw new RuntimeException($p);
        if (!hash_equals($sum, (string) hash_file('sha256', $tmp))) throw new RuntimeException('The downloaded file doesn’t match its checksum (cut off on the way?). Nothing was changed: try again.');

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) throw new RuntimeException('The downloaded update isn’t a valid zip.');
        if (!str_contains((string) $zip->getFromName('app/version.php'), "M3D_VERSION = '$version'")) {
            $zip->close();
            throw new RuntimeException("The downloaded zip isn’t version $version.");
        }
        $lock = code_lock();
        keep_running();
        try {
            return install_with_backup($zip);
        } finally {
            $zip->close();
            flock($lock, LOCK_UN);
        }
    } finally {
        @unlink($tmp);
    }
}

/** A release's notes as a list ("- item" lines; anything else is left out). */
function gh_notes_html(string $notes): string
{
    $items = [];
    foreach (preg_split('/\R/', $notes) as $line) {
        if (preg_match('/^\s{0,3}[-*+]\s+(.+)$/', $line, $m)) $items[] = '<li>' . md_inline(trim($m[1])) . '</li>';
    }
    return $items ? '<ul>' . implode('', $items) . '</ul>' : '<p class="muted small">No notes.</p>';
}
