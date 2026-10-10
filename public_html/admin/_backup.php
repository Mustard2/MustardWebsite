<?php
// Backups: shared by admin/update.php (code backup before an update) and admin/backups.php (the Backups tab).
// Everything lives in private/backups/:
//   full-<date>-<kind>[-note].zip         full backup (manual, "auto" weekly from admin_auto_backup(), or "before-restore"):
//                                          manifest.json, code/…, data/…, logs/downloads.csv, media/… (optional)
//   site-code-<date>-v<version>.zip       code before an update (last 5, made by the updater)
//   <name>-<date>-<rand>.json             one data file before each save (last 50 per file, m3d_write_json)
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;
require_once dirname(__DIR__) . '/app/version.php';

const ALLOWED_EXT = ['php', 'js', 'css', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ico', 'txt', 'md', 'json', 'woff2'];
const MEDIA_EXT = ['webp', 'png', 'jpg', 'jpeg', 'gif'];
/** Data files that are the site's content (restored together); the rest are counters (stats, likes). */
const DATA_COUNTERS = ['stats', 'likes', '.likes-key'];

function bk_dir(): string
{
    $dir = M3D_PRIVATE . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** A backup file name from a request → its full path, only if it really is a file in the backups folder. */
function bk_file(string $name): ?string
{
    if (!preg_match('/^[A-Za-z0-9._-]{1,160}$/', $name) || str_starts_with($name, '.')) return null;
    $p = bk_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

// ── Code ─────────────────────────────────────────────────────────────────────
/** Paths from a code zip that may be written. Returns null for anything that must be skipped. */
function update_path(string $name): ?string
{
    $name = preg_replace('#^(\./)+#', '', str_replace('\\', '/', $name));
    if ($name === '' || str_ends_with($name, '/')) return null;                       // folders are created as needed
    if (str_contains($name, '../') || str_starts_with($name, '/') || str_contains($name, "\0")) throw new RuntimeException("Unsafe path in zip: $name");
    if (str_starts_with($name, 'media/') && $name !== 'media/.htaccess') return null; // never overwrite uploads
    if (str_starts_with($name, 'private/')) return null;
    $base = basename($name);
    if ($base === '.htaccess') return $name;
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT, true)) throw new RuntimeException("File type not allowed in an update: $name");
    return $name;
}

/** Adds the site code (no uploads, no private data, no old WordPress) to a zip, under $prefix. */
function zip_add_code(ZipArchive $zip, string $prefix = ''): int
{
    $root = M3D_SITE;
    $n = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        // only the site's own files: top-level files and the code folders (not a leftover v2 copy, old WordPress, uploads…)
        $top = str_contains($rel, '/') ? strstr($rel, '/', true) : '';
        if ($top !== '' && !in_array($top, ['admin', 'app', 'assets', 'auth'], true) && $rel !== 'media/.htaccess') continue;
        if (str_starts_with(basename($rel), 'zz_') || str_starts_with($rel, 'wp-')) continue;
        if (str_contains($rel, '.upd-')) continue;
        try { if (update_path($rel) === null) continue; } catch (RuntimeException) { continue; }
        $zip->addFile($f->getPathname(), $prefix . $rel);
        $n++;
    }
    return $n;
}

/** Code before an update: site-code-<date>-v<version>.zip (the newest 5 are kept). */
function backup_code(): string
{
    $dir = bk_dir();
    $file = "$dir/site-code-" . gmdate('Ymd-His') . '-v' . preg_replace('/[^0-9a-z.-]/i', '', M3D_VERSION) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the backup zip.');
    zip_add_code($zip);
    $zip->close();
    $old = glob("$dir/site-code-*.zip") ?: [];
    sort($old);
    foreach (array_slice($old, 0, max(0, count($old) - 5)) as $o) @unlink($o);
    return basename($file);
}

/** Only one code install at a time (update, code restore). Keep the returned handle until the end; throws when busy. */
function code_lock()
{
    $h = @fopen(bk_dir() . '/.code-install.lock', 'c');
    if (!$h) throw new RuntimeException('Could not create the update lock file in ' . basename(M3D_PRIVATE) . '/backups.');
    if (!flock($h, LOCK_EX | LOCK_NB)) {
        fclose($h);
        throw new RuntimeException('Another update or restore is running right now. Wait a minute, then reload this page to see the installed version.');
    }
    return $h;
}

/** From here on, refreshing or closing the page (or a slow server) can't stop the work halfway. */
function keep_running(int $seconds = 600): void
{
    // hosts can disable these; a disabled function doesn't exist in PHP 8, so calling it would stop everything
    if (function_exists('ignore_user_abort')) @ignore_user_abort(true);
    if (function_exists('set_time_limit')) @set_time_limit($seconds);
}

/**
 * Writes the code files of a zip (those under $prefix) over the site, safely. Returns [files written, version in the zip].
 *   1. every file is unpacked next to its original as <file>.upd-<token>: if anything fails, nothing was changed;
 *   2. then all are swapped in (rename = atomic; the old file is kept as <file>.upd-old-<token>);
 *      if a swap fails, the files already replaced are put back, so the site is exactly as before;
 *   3. the old copies are deleted. Leftovers of a killed process are "temporary files" (Settings → Tools).
 */
function install_code(ZipArchive $zip, string $prefix = ''): array
{
    $plan = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);
        if ($prefix !== '') { if (!str_starts_with($name, $prefix)) continue; $name = substr($name, strlen($prefix)); }
        $path = update_path($name);
        if ($path !== null) $plan[$i] = $path;
    }
    if (!in_array('app/bootstrap.php', $plan, true) || !in_array('app/version.php', $plan, true)) {
        throw new RuntimeException('This zip doesn’t contain the site code.');
    }
    $verIndex = array_search('app/version.php', $plan, true);
    $version = preg_match("/M3D_VERSION = '([^']+)'/", (string) $zip->getFromIndex((int) $verIndex), $mm) ? $mm[1] : '?';
    $token = bin2hex(random_bytes(4));

    // 1. unpack everything next to the originals
    $staged = [];   // [path, tmp, dest]
    $tmp = null;
    try {
        foreach ($plan as $i => $path) {
            $data = $zip->getFromIndex($i);
            if ($data === false) throw new RuntimeException("Could not read $path from the zip");
            $dest = M3D_SITE . '/' . $path;
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0755, true)) throw new RuntimeException("Could not create the folder for $path");
            $tmp = "$dest.upd-$token";
            if (file_put_contents($tmp, $data) === false) throw new RuntimeException("Could not write $path (is the disk full?)");
            @chmod($tmp, 0644);
            $staged[] = [$path, $tmp, $dest];
            $tmp = null;
        }
    } catch (Throwable $ex) {
        if ($tmp !== null) @unlink($tmp);
        foreach ($staged as [, $t]) @unlink($t);
        throw new RuntimeException($ex->getMessage() . '. Nothing was changed: the site is as before.');
    }

    // 2. swap them in, keeping the old files until all are in place
    $swapped = [];  // [dest, old copy or null when the file is new]
    try {
        foreach ($staged as [$path, $t, $dest]) {
            $old = null;
            if (is_file($dest)) {
                $old = "$dest.upd-old-$token";
                // a plain copy: link() is disabled on many shared hosts (Hostinger), and a disabled function can't even be called
                if (!@copy($dest, $old)) throw new RuntimeException("Could not keep a copy of $path");
            }
            if (!@rename($t, $dest)) { if ($old !== null) @unlink($old); throw new RuntimeException("Could not replace $path"); }
            $swapped[] = [$dest, $old];
        }
    } catch (Throwable $ex) {
        foreach (array_reverse($swapped) as [$dest, $old]) { if ($old !== null) @rename($old, $dest); else @unlink($dest); }
        foreach ($staged as [, $t]) @unlink($t);
        if (function_exists('opcache_reset')) @opcache_reset();
        throw new RuntimeException($ex->getMessage() . '. The files already replaced were put back: the site is as before.');
    }

    // 3. done: drop the old copies
    foreach ($swapped as [, $old]) if ($old !== null) @unlink($old);
    if (function_exists('opcache_reset')) @opcache_reset();
    return [count($swapped), $version];
}

// ── Full backups ─────────────────────────────────────────────────────────────
function data_files(): array
{
    $dir = M3D_PRIVATE . '/data';
    $files = array_merge(glob("$dir/*.json") ?: [], is_file("$dir/.likes-key") ? ["$dir/.likes-key"] : []);
    return array_values(array_filter($files, fn($f) => !str_contains(basename($f), '.tmp-')));
}

function media_files(): array
{
    $root = M3D_SITE . '/media';
    if (!is_dir($root)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && in_array(strtolower($f->getExtension()), MEDIA_EXT, true)) $out[] = $f->getPathname();
    }
    return $out;
}

/**
 * Creates full-<date>-<kind>[-note].zip with the code, all data files and the download log (+ images if asked).
 * config.php (Patreon secret) is never included: these zips can be downloaded and stored elsewhere.
 */
function backup_full(string $kind = 'manual', bool $withMedia = false, string $note = ''): string
{
    @set_time_limit(900);
    $slug = trim(substr(preg_replace('/[^a-z0-9]+/', '-', strtolower($note)), 0, 40), '-');
    $name = 'full-' . gmdate('Ymd-His') . '-' . $kind . ($slug !== '' ? "-$slug" : '') . '.zip';
    $file = bk_dir() . "/$name";
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Could not create the backup file.');
    $parts = ['code', 'data'];
    $code = zip_add_code($zip, 'code/');
    foreach (data_files() as $f) $zip->addFile($f, 'data/' . basename($f));
    $log = M3D_PRIVATE . '/logs/downloads.csv';
    if (is_file($log)) { $zip->addFile($log, 'logs/downloads.csv'); $parts[] = 'log'; }
    $media = 0;
    if ($withMedia) {
        foreach (media_files() as $f) {
            $zip->addFile($f, 'media/' . str_replace('\\', '/', substr($f, strlen(M3D_SITE . '/media') + 1)));
            $zip->setCompressionName('media/' . str_replace('\\', '/', substr($f, strlen(M3D_SITE . '/media') + 1)), ZipArchive::CM_STORE); // already compressed
            $media++;
        }
        $parts[] = 'media';
    }
    $zip->addFromString('manifest.json', json_encode([
        'type' => 'mustard3d-backup',   // the format's name: kept as is, so every backup made before stays restorable 'kind' => $kind, 'note' => $note, 'created' => gmdate('c'),
        'version' => M3D_VERSION, 'parts' => $parts, 'files' => ['code' => $code, 'media' => $media],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if (!$zip->close()) { @unlink($file); throw new RuntimeException('Could not write the backup (is the disk full?).'); }
    return $name;
}

function backup_manifest(string $path): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return null;
    $m = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();
    return is_array($m) && ($m['type'] ?? '') === 'mustard3d-backup' ? $m : null;
}

/**
 * Restores parts of a full backup: code, content (data files except counters), counters (stats, likes),
 * log (download log), media (adds/replaces images; never deletes newer ones). Returns a list of what was done.
 */
function restore_full(string $path, array $parts): array
{
    @set_time_limit(900);
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('This backup can’t be opened.');
    $done = [];
    if (in_array('code', $parts, true)) {
        [$n, $v] = install_code($zip, 'code/');
        $done[] = "code ($n files, version $v)";
    }
    $want = ['content' => in_array('content', $parts, true), 'counters' => in_array('counters', $parts, true)];
    if ($want['content'] || $want['counters']) {
        $inZip = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = (string) $zip->getNameIndex($i);
            if (preg_match('#^data/(\.likes-key|[a-z0-9_-]+\.json)$#', $n, $m)) $inZip[$m[1]] = $i;
        }
        $dir = M3D_PRIVATE . '/data';
        $count = 0;
        foreach ($inZip as $base => $i) {
            $isCounter = in_array(preg_replace('/\.json$/', '', $base), DATA_COUNTERS, true);
            if (!($isCounter ? $want['counters'] : $want['content'])) continue;
            $bytes = (string) $zip->getFromIndex($i);
            if (str_ends_with($base, '.json') && !is_array(json_decode($bytes, true))) throw new RuntimeException("data/$base in the backup is damaged.");
            // the version being replaced also goes into the change history, like any other save
            if (str_ends_with($base, '.json') && !$isCounter && is_file("$dir/$base")) @copy("$dir/$base", bk_dir() . '/' . substr($base, 0, -5) . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2)) . '.json');
            $tmp = "$dir/$base.tmp-" . bin2hex(random_bytes(3));
            if (file_put_contents($tmp, $bytes) === false || !rename($tmp, "$dir/$base")) { @unlink($tmp); throw new RuntimeException("Could not write data/$base"); }
            $count++;
        }
        if ($want['content']) {
            // content files that didn't exist yet in the backup (e.g. the FAQ before its first save) go back to the defaults
            foreach (data_files() as $f) {
                $stem = preg_replace('/\.json$/', '', basename($f));
                if (!isset($inZip[basename($f)]) && !in_array($stem, DATA_COUNTERS, true) && in_array($stem, ['faq', 'docs', 'settings'], true)) { @unlink($f); $count++; }
            }
        }
        if ($want['content']) $done[] = 'content';
        if ($want['counters']) $done[] = 'download counts and likes';
    }
    if (in_array('log', $parts, true) && ($bytes = $zip->getFromName('logs/downloads.csv')) !== false) {
        $dir = M3D_PRIVATE . '/logs';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        file_put_contents("$dir/downloads.csv", $bytes, LOCK_EX);
        $done[] = 'download log';
    }
    if (in_array('media', $parts, true)) {
        $n = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!preg_match('#^media/([A-Za-z0-9_./-]+)$#', $name, $m) || str_contains($m[1], '..')) continue;
            if (!in_array(strtolower(pathinfo($m[1], PATHINFO_EXTENSION)), MEDIA_EXT, true)) continue;
            $dest = M3D_SITE . '/media/' . $m[1];
            if (!is_dir(dirname($dest))) @mkdir(dirname($dest), 0755, true);
            if (file_put_contents($dest, (string) $zip->getFromIndex($i)) !== false) $n++;
        }
        $done[] = "$n images";
    }
    $zip->close();
    return $done;
}

// ── Change history (one data file per save) ──────────────────────────────────
/** name => [[file, time, bytes], …] newest first. */
function data_history(): array
{
    $out = [];
    foreach (glob(bk_dir() . '/*.json') ?: [] as $f) {
        if (!preg_match('/^([a-z0-9_-]+?)-(\d{8})-(\d{6})-[0-9a-f]+\.json$/', basename($f), $m)) continue;
        $t = DateTimeImmutable::createFromFormat('Ymd His', "$m[2] $m[3]", new DateTimeZone('UTC'));
        $out[$m[1]][] = ['file' => basename($f), 'time' => $t ? $t->getTimestamp() : filemtime($f), 'bytes' => (int) filesize($f)];
    }
    foreach ($out as &$list) usort($list, fn($a, $b) => $b['time'] <=> $a['time']);
    unset($list);
    ksort($out);
    return $out;
}

/** Puts an old version of one data file back (the current one is kept in the history first). */
function restore_data_version(string $file): string
{
    if (!preg_match('/^([a-z0-9_-]+?)-\d{8}-\d{6}-[0-9a-f]+\.json$/', $file, $m) || !($path = bk_file($file))) throw new RuntimeException('Backup not found.');
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) throw new RuntimeException('This backup file is damaged.');
    if (!m3d_write_json($m[1], $data)) throw new RuntimeException('Could not write the data file.');
    return $m[1];
}

/** One-line description of a data file version, e.g. "21 models" or "15 pages". */
function data_summary(string $name, string $path): string
{
    if (filesize($path) > 4 * 1048576) return '';
    $d = json_decode((string) file_get_contents($path), true);
    if (!is_array($d)) return 'damaged';
    $plural = fn(int $n, string $w) => $n . ' ' . $w . ($n === 1 ? '' : 's');
    return match ($name) {
        'models'   => $plural(count($d['models'] ?? []), 'model'),
        'outfits'  => $plural(count($d['outfits'] ?? []), 'accessory'),
        'faq'      => $plural(array_sum(array_map(fn($s) => count($s['items'] ?? []), $d['sections'] ?? [])), 'question'),
        'docs'     => $plural(array_sum(array_map(fn($s) => count($s['pages'] ?? []), $d['sections'] ?? [])), 'page'),
        'settings' => $plural(count($d['social'] ?? []), 'social link'),
        default    => '',
    };
}

function human_size(int $b): string
{
    return $b >= 1073741824 ? sprintf('%.1f GB', $b / 1073741824) : ($b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', max(1, (int) ceil($b / 1024))));
}
