<?php
// MEGA S4 downloads (Admin → Settings → Features → MEGA S4): a download link whose address is a file in a MEGA S4 bucket
// (S3-compatible object storage: https://s3.<region>.megas4.com/<bucket>/<file>) is never sent as it is. auth/get.php and
// auth/get-still.php send the visitor to a pre-signed copy instead (m3d_download_url()): valid for a set time (`minutes`), so an
// address copied from the browser or shared stops working by itself. The bucket stays private; the keys never leave the server.
// A link can also be written short, `s4:<file>` (e.g. s4:model.zip or s4:folder/file.zip): the file in the bucket of the
// "Bucket Address" setting (m3d_s4_resolve()). Settings: private/s4.php {on, key, secret, minutes, region, bucket, domain}: a PHP file like
// patreon.php (never readable over the web, never in backups). Signing is AWS Signature Version 4 with the query string (the only version S4 accepts: github.com/meganz/s4-specs).
// Download Domain (`domain`, optional): the site's own address for downloads (files.example.com), a proxy (e.g. a CDN worker) that passes the
// signed address on to the bucket's host unchanged. The address is still signed for the bucket's host (the Worker's request to S4 carries
// that Host), only the host the visitor sees changes: m3d_s4_domain_url(), for files in the Bucket Address's bucket only.
declare(strict_types=1);

const S4_MINUTES = 360;        // default link lifetime (6 hours)
const S4_MINUTES_MIN = 5;      // shorter, and a server clock a little off from S4's could make links dead on arrival
const S4_MINUTES_MAX = 10080;  // S4 refuses pre-signed links valid for more than 7 days
const S4_LIST_PAGES = 5;     // "Choose from S4…": at most 5 × 1000 entries of one folder
const S4_REGIONS = ['us-east-1', 'host'];   // what the signature names as region: S4 doesn't tie buckets to one; 'host' = the region in the address
// S4 addresses, path style (s3.eu-amsterdam-1.megas4.com/bucket/file, s3.g.megas4.com/bucket/file, MEGA's Object URL with its id first) or virtual-host style (bucket.s3.eu-amsterdam-1.megas4.com/file);
// the older s3.eu-central-1.s4.mega.io endpoints too
const S4_HOST_RE = '/^(?:([a-z0-9][a-z0-9.-]{1,61}[a-z0-9])\.)?(s3\.([a-z0-9-]+)\.(?:megas4\.com|s4\.mega\.io))$/';

/** The saved S4 settings: {on, key, secret, minutes, region, bucket (https://<host>/<bucket>/ or ''), domain (host or '')}; key / secret empty when not set; on = the main switch (absent = on). */
function m3d_s4(bool $fresh = false): array
{
    static $cache = null;   // read once per request (Link Check asks for every link); m3d_s4_save() refreshes it
    if ($cache !== null && !$fresh) return $cache;
    $d = m3d_key_file('s4');
    $str = fn(string $k) => is_string($d[$k] ?? null) ? $d[$k] : '';
    return $cache = [
        'on' => (bool) ($d['on'] ?? true),
        'key' => $str('key'), 'secret' => $str('secret'),
        'minutes' => max(S4_MINUTES_MIN, min(S4_MINUTES_MAX, (int) ($d['minutes'] ?? (isset($d['hours']) ? (int) $d['hours'] * 60 : S4_MINUTES)))),   // 'hours': saved before minutes
        'region' => in_array($d['region'] ?? '', S4_REGIONS, true) ? $d['region'] : S4_REGIONS[0],
        'bucket' => $str('bucket'),
        'domain' => $str('domain'),
    ];
}

/** S4 keys saved (the admin can list and test the bucket, even with the switch off). */
function m3d_s4_keys(): bool
{
    $s = m3d_s4();
    return $s['key'] !== '' && $s['secret'] !== '';
}

/** S4 downloads on: keys saved and the main switch on, so S4 links are signed. Off: the keys and bucket stay saved, but S4 is gone from the rest of the site (S4… picker, Link Check) and "s4:" links don't download. */
function m3d_s4_on(): bool
{
    return m3d_s4_keys() && m3d_s4()['on'];
}

/** A link lifetime for people: "45 minutes", "6 hours", "1 hour 30 minutes". */
function m3d_s4_lifetime(int $minutes): string
{
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return trim(($h ? m3d_plural($h, 'hour') : '') . ' ' . ($m || !$h ? m3d_plural($m, 'minute') : ''));
}

/** Writes private/s4.php; without keys the file is deleted. */
function m3d_s4_save(array $s): bool
{
    $keep = ($s['key'] ?? '') === '' || ($s['secret'] ?? '') === '' ? [] : [
        'on' => (bool) ($s['on'] ?? true), 'key' => (string) $s['key'], 'secret' => (string) $s['secret'], 'minutes' => (int) ($s['minutes'] ?? S4_MINUTES),
        'region' => (string) ($s['region'] ?? S4_REGIONS[0]), 'bucket' => (string) ($s['bucket'] ?? ''), 'domain' => (string) ($s['domain'] ?? '')];
    $ok = m3d_key_file_save('s4', $keep, 'MEGA S4 keys saved in Admin → Settings → Features → MEGA S4. Delete this file to stop signing S4 links.');
    m3d_s4(true);
    return $ok;
}

/** A short link "s4:<file>"? */
function m3d_s4_short(string $url): bool
{
    return strncasecmp(trim($url), 's4:', 3) === 0;
}

/** A short link "s4:<file>" → the file's full address in the bucket of $bucket (default: the saved Bucket Address), '' without one; other addresses as they are. */
function m3d_s4_resolve(string $url, ?string $bucket = null): string
{
    $url = trim($url);
    if (!m3d_s4_short($url)) return $url;
    $bucket ??= m3d_s4()['bucket'];
    $path = ltrim(substr($url, 3), '/');
    if ($bucket === '' || $path === '') return '';
    return $bucket . implode('/', array_map(fn($p) => rawurlencode(rawurldecode($p)), explode('/', $path)));
}

/** A Bucket Address typed in Settings (the bucket, or any file address in it, MEGA's Object URL too) → "https://<host>/<bucket>/", or '' when it isn't one. */
function m3d_s4_bucket_clean(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $p = m3d_s4_parse(rtrim($url, '/') . '/x', '');   // a file in it, to read host and bucket the same way as a link
    return $p ? 'https://' . $p[0] . '/' . $p[1] . '/' : '';
}

/** A Download Domain typed in Settings ("files.example.com", with or without https:// and a final /) → the host, lowercase; '' when it isn't one (an S4 host isn't). */
function m3d_s4_domain_clean(string $d): string
{
    $d = strtolower(trim($d));
    if (preg_match('#^https://([^/?\#]+)/?$#', $d, $m)) $d = $m[1];
    if (!preg_match('/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $d)) return '';
    return preg_match(S4_HOST_RE, $d) ? '' : $d;
}

/**
 * A pre-signed S4 address as the visitor gets it: on the Download Domain when one is saved and the file is in the Bucket Address's
 * bucket (the Worker passes /<bucket>/… on to the bucket's host), else unchanged. $s: the settings (default: the saved ones).
 */
function m3d_s4_domain_url(string $signed, ?array $s = null): string
{
    $s ??= m3d_s4();
    $domain = (string) ($s['domain'] ?? '');
    $b = $domain !== '' && ($s['bucket'] ?? '') !== '' ? m3d_s4_parse($s['bucket'] . 'x', $s['bucket']) : null;
    if (!$b) return $signed;
    $host = 'https://' . $b[0];
    return str_starts_with($signed, $host . '/' . rawurlencode($b[1]) . '/') ? 'https://' . $domain . substr($signed, strlen($host)) : $signed;
}

/**
 * An S4 file address (or a short "s4:<file>" link) → [host (path style), bucket, file key (decoded), region in the address],
 * or null when it isn't one. $bucket: the Bucket Address for short links (default: the saved one).
 */
function m3d_s4_parse(string $url, ?string $bucket = null): ?array
{
    $p = parse_url(m3d_s4_resolve($url, $bucket));
    if (!$p || strtolower((string) ($p['scheme'] ?? '')) !== 'https' || isset($p['user']) || isset($p['port'])) return null;
    if (!preg_match(S4_HOST_RE, strtolower((string) ($p['host'] ?? '')), $m)) return null;
    $path = rawurldecode(ltrim((string) ($p['path'] ?? ''), '/'));
    if (($m[1] ?? '') === '') {   // path style: the bucket is the first part of the path
        // MEGA's "Object URL" (s3.g.megas4.com/<public id>/<bucket>/<file>) starts with a long id: the bucket comes after it
        if (preg_match('#^[a-z2-7]{32,40}/[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]/.#', $path)) $path = substr($path, strpos($path, '/') + 1);
        [$bucket, $key] = array_pad(explode('/', $path, 2), 2, '');
    } else {
        [$bucket, $key] = [$m[1], $path];
    }
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket) || $key === '' || str_ends_with($key, '/')) return null;   // a bucket or folder isn't a file
    return [$m[2], $bucket, $key, $m[3]];
}

/**
 * A pre-signed address (AWS Signature Version 4, query string) for $method on $host + $uri ($uri already encoded), valid $seconds.
 * Only the host header is signed, so the browser may add anything (Range for resumed downloads).
 */
function m3d_s4_presign_raw(string $method, string $host, string $uri, string $region, string $key, string $secret, int $seconds, ?int $now = null, array $extra = []): string
{
    $now ??= time();
    $amzDate = gmdate('Ymd\THis\Z', $now);
    $day = gmdate('Ymd', $now);
    $scope = "$day/$region/s3/aws4_request";
    $q = ['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => "$key/$scope", 'X-Amz-Date' => $amzDate,
          'X-Amz-Expires' => (string) $seconds, 'X-Amz-SignedHeaders' => 'host'] + $extra;   // $extra: the request's own query (a bucket listing)
    ksort($q, SORT_STRING);
    $query = implode('&', array_map(fn($k, $v) => rawurlencode($k) . '=' . rawurlencode($v), array_keys($q), $q));
    $canonical = "$method\n$uri\n$query\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
    $toSign = "AWS4-HMAC-SHA256\n$amzDate\n$scope\n" . hash('sha256', $canonical);
    $k = hash_hmac('sha256', $day, 'AWS4' . $secret, true);
    foreach ([$region, 's3', 'aws4_request'] as $part) $k = hash_hmac('sha256', $part, $k, true);
    return "https://$host$uri?$query&X-Amz-Signature=" . hash_hmac('sha256', $toSign, $k);
}

/** A pre-signed address of an S4 file address, or null when it isn't one or no keys are saved. $region: one of S4_REGIONS (default: the saved one). */
function m3d_s4_presign(string $url, string $method = 'GET', ?int $seconds = null, ?string $region = null, ?array $keys = null): ?string
{
    $s = $keys ?? m3d_s4();
    $p = m3d_s4_parse($url, $s['bucket'] ?? null);
    if (!$p || ($s['key'] ?? '') === '' || ($s['secret'] ?? '') === '') return null;
    [$host, $bucket, $file, $hostRegion] = $p;
    $region ??= $s['region'] ?? S4_REGIONS[0];
    $uri = '/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $file)));
    return m3d_s4_presign_raw($method, $host, $uri, $region === 'host' ? $hostRegion : $region, (string) $s['key'], (string) $s['secret'],
        $seconds ?? (int) ($s['minutes'] ?? S4_MINUTES) * 60);
}

/**
 * Where a download link sends the visitor: a fresh pre-signed address for an S4 file (when keys are saved), else the link itself;
 * null for a short "s4:" link that leads nowhere (no Bucket Address saved).
 */
function m3d_download_url(string $url): ?string
{
    if (!m3d_s4()['on']) return m3d_s4_short($url) ? null : $url;   // switched off: "s4:" links have nowhere to go, full addresses go as they are
    $signed = m3d_s4_presign($url);
    if ($signed !== null) return m3d_s4_domain_url($signed);
    return m3d_s4_short($url) ? (m3d_s4_resolve($url) ?: null) : $url;
}

/**
 * Asks S4 for the first byte of a file (one signed GET with Range: nothing else is downloaded):
 * ['ok' => bool, 'code' => HTTP code (0 = no answer), 'size' => bytes, 'err' => S4's error code or curl's message].
 * $viaDomain: through the Download Domain (the Worker) instead; 'err' => 'not via domain' when the file isn't served there.
 */
function m3d_s4_probe(string $url, ?string $region = null, ?array $keys = null, bool $viaDomain = false): array
{
    $signed = m3d_s4_presign($url, 'GET', 300, $region, $keys);
    if ($signed === null) return ['ok' => false, 'code' => 0, 'size' => 0, 'err' => m3d_s4_parse($url, ($keys ?? m3d_s4())['bucket'] ?? null) ? 'no keys' : (m3d_s4_short($url) ? 'no bucket' : 'not an S4 address')];
    if ($viaDomain) {
        $via = m3d_s4_domain_url($signed, $keys);
        if ($via === $signed) return ['ok' => false, 'code' => 0, 'size' => 0, 'err' => 'not via domain'];
        $signed = $via;
    }
    $ch = curl_init($signed);
    $body = '';
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Range: bytes=0-0'],
        CURLOPT_HEADER         => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_WRITEFUNCTION  => function ($ch, string $d) use (&$body) { $body .= $d; return strlen($body) > 16384 ? 0 : strlen($d); },
    ]);
    $size = 0;
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $h) use (&$size) {
        if (preg_match('#^content-range:\s*bytes\s+\d+-\d+/(\d+)#i', $h, $m)) $size = (int) $m[1];
        return strlen($h);
    });
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_errno($ch) && curl_errno($ch) !== CURLE_WRITE_ERROR ? curl_error($ch) : '';
    unset($ch);
    if ($cerr !== '' || $code === 0) return ['ok' => false, 'code' => 0, 'size' => 0, 'err' => $cerr ?: 'no answer'];
    if ($code === 200 || $code === 206) return ['ok' => true, 'code' => $code, 'size' => $size, 'err' => ''];
    $err = preg_match('#<Code>([^<]{1,80})</Code>#', $body, $m) ? $m[1] : "HTTP $code";
    return ['ok' => false, 'code' => $code, 'size' => 0, 'err' => $err];
}

/**
 * The files and folders in the bucket of the Bucket Address under $prefix ("" = the top, "folder/" = inside it): ListObjectsV2, one level
 * (delimiter "/"), up to S4_LIST_PAGES pages of 1000. Needs list permission on the key (S3ObjectListAccess). $keys / $region: like m3d_s4_probe()
 * (Save and Test lists the bucket with the keys typed in the form). ['ok' => bool, 'bucket', 'prefix', 'folders' => [prefix…],
 * 'files' => [{key, size, date}], 'more' => true when cut, 'code' => S4's error code ('' = no answer), 'err' => why not].
 */
function m3d_s4_list(string $prefix = '', ?array $keys = null, ?string $region = null, int $pages = S4_LIST_PAGES): array
{
    $s = ($keys ?? []) + m3d_s4();
    $out = ['ok' => false, 'bucket' => '', 'prefix' => $prefix, 'folders' => [], 'files' => [], 'more' => false, 'code' => '', 'err' => ''];
    $p = $s['bucket'] !== '' ? m3d_s4_parse($s['bucket'] . 'x', $s['bucket']) : null;
    if (!$p) return ['err' => m3d_s4_error_text('no bucket')] + $out;
    if (($s['key'] ?? '') === '' || ($s['secret'] ?? '') === '') return ['err' => m3d_s4_error_text('no keys')] + $out;
    [$host, $bucket, , $hostRegion] = $p;
    $out['bucket'] = $bucket;
    $region ??= $s['region'] ?? S4_REGIONS[0];
    if ($region === 'host') $region = $hostRegion;
    $token = '';
    for ($page = 0; $page < $pages; $page++) {
        $q = ['list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000', 'prefix' => $prefix] + ($token !== '' ? ['continuation-token' => $token] : []);
        $url = m3d_s4_presign_raw('GET', $host, '/' . rawurlencode($bucket), $region, (string) $s['key'], (string) $s['secret'], 300, null, $q);
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 25, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $cerr = curl_error($ch);
        unset($ch);
        if (!is_string($body) || $code === 0) return ['err' => 'S4 didn’t answer (' . ($cerr ?: 'no answer') . ')'] + $out;
        if ($code !== 200) {
            $err = preg_match('#<Code>([^<]{1,80})</Code>#', $body, $m) ? $m[1] : "HTTP $code";
            return ['code' => $err, 'err' => m3d_s4_error_text($err)] + $out;
        }
        $x = @simplexml_load_string($body);
        if ($x === false) return ['err' => 'S4 answered something the site doesn’t understand'] + $out;
        foreach ($x->CommonPrefixes as $cp) $out['folders'][] = (string) $cp->Prefix;
        foreach ($x->Contents as $c) {
            $k = (string) $c->Key;
            if ($k === $prefix || str_ends_with($k, '/')) continue;   // the folder's own marker
            $out['files'][] = ['key' => $k, 'size' => (int) $c->Size, 'date' => substr((string) $c->LastModified, 0, 10)];
        }
        $token = (string) $x->NextContinuationToken;
        if ((string) $x->IsTruncated !== 'true' || $token === '') { $token = ''; break; }
    }
    $out['more'] = $token !== '';
    $out['ok'] = true;
    return $out;
}

/** S4's error code → what it means for the admin. */
function m3d_s4_error_text(string $err): string
{
    return match ($err) {
        'NoSuchKey'                         => 'No file with this name in the bucket (check the name, capitals included)',
        'NoSuchBucket'                      => 'There is no bucket with this name',
        'InvalidAccessKeyId'                => 'S4 doesn’t know this access key',
        'SignatureDoesNotMatch'             => 'S4 refused the signature: check the secret key',
        'AccessDenied'                      => 'S4 refused access: the key may not be allowed to read (or list) this bucket',
        'AccountProblem'                    => 'S4 reports a problem with the account (quota used up, or a payment?)',
        'RequestTimeTooSkewed'              => 'The server’s clock is wrong, so S4 refuses its signatures',
        'no keys'                           => 'No S4 keys saved',
        'no bucket'                         => 'Set the Bucket Address in Settings → Features → MEGA S4 for s4: links',
        'not an S4 address'                 => 'Not an S4 file address',
        default                             => 'S4 answered “' . $err . '”',
    };
}
