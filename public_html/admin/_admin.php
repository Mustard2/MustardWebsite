<?php
// Shared by every admin page: access control, JSON helpers, validation, admin layout.
declare(strict_types=1);
require dirname(__DIR__) . '/app/_init.php';
require_once __DIR__ . '/_tools.php';   // Admin → Assets → Tools: the editors' checks

header('X-Frame-Options: DENY');
header("Content-Security-Policy: frame-ancestors 'none'");   // replaces bootstrap's 'self' (browsers read this one before X-Frame-Options)
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// ── Access: only Patreon ids listed in owner_user_ids ────────────────────────
function admin_require(array $cfg, bool $json = false): void
{
    if (m3d_is_admin($cfg)) return;
    if (m3d_setup_needed() && !$json) m3d_redirect('/setup/');
    if ($json) admin_json(['ok' => false, 'error' => 'You are logged out. Log in again in another tab, then come back here and try again: reloading this page would lose your changes.'], 403);
    if (!m3d_patron($cfg)) {
        m3d_redirect('/auth/login.php?return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    }
    http_response_code(403);
    layout_start('Admin', 'Admin', 'page-gate');
    $p = m3d_patron($cfg);
    echo '<section class="wrap gate"><p class="eyebrow">Admin</p><h1>No Access</h1><p class="lead">You are logged in as '
        . e($p['name'] ?? '') . ' (Patreon id ' . e($p['id'] ?? '') . '), which is not an admin of this site.</p>'
        . '<div class="cta"><a class="btn btn-ghost" href="/auth/logout.php?return=%2Fadmin%2F">Log In with Another Account</a></div></section>';
    layout_end();
    exit;
}

function admin_json(array $data, int $code = 200): never
{
    m3d_json_out($data, $code);
}

/** upload.php / upload-3d.php: the file sent as "file", or the JSON error. */
function admin_uploaded_file(): array
{
    $f = $_FILES['file'] ?? null;
    if ($f && is_uploaded_file($f['tmp_name'] ?? '')) return $f;
    $msg = in_array($f['error'] ?? UPLOAD_ERR_NO_FILE, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'The file is larger than the server allows (now ' . ini_get('upload_max_filesize') . '; raise upload_max_filesize and post_max_size in your host’s PHP settings).'
        : 'No file received.';
    admin_json(['ok' => false, 'error' => $msg], 400);
}

/** The media folder named by POST "model" (made when missing) and a new file name from the uploaded one: [folder, dir, base]. */
function admin_upload_target(string $name, string $fallback): array
{
    $folder = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['model'] ?? ''))) ?: 'misc';
    $dir = M3D_SITE . '/media/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) admin_json(['ok' => false, 'error' => 'Could not create the media folder.'], 500);
    $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($name, PATHINFO_FILENAME))) ?? '', '-') ?: $fallback;
    return [$folder, $dir, substr($base, 0, 60) . '-' . bin2hex(random_bytes(3))];
}

/** POST endpoints: admin + same CSRF token as the page that sent the request. */
function admin_post_guard(array $cfg): void
{
    admin_require($cfg, true);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') admin_json(['ok' => false, 'error' => 'POST only'], 405);
    // A file bigger than the server's limit arrives as an empty request: say so instead of "session expired"
    if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/') && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        admin_json(['ok' => false, 'error' => 'This file is larger than the server accepts (' . ini_get('post_max_size') . '). Raise "post_max_size" and "upload_max_filesize" in your host’s PHP settings, or upload a smaller file.'], 413);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
    if (!m3d_check_csrf($token)) admin_json(['ok' => false, 'error' => 'Session expired. Reload the page and try again.'], 403);
    m3d_session_release();   // these requests never change the login: other pages stay usable while one works (uploads, imports, Discord)
}

// ── Validation: turns whatever the editor sends into a clean model ───────────
/** One line of text from a form: spaces and line breaks squeezed to one space, cut to $max characters. */
function clean_line(mixed $v, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '') ?? ''), 0, $max);
}

/** An address starting with https:// that PHP accepts as an URL (links to other websites in the settings). */
function is_https_url(string $url): bool
{
    return preg_match('#^https://[^\s<>"]+$#iD', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/** True for a POST of an admin form (with that hidden "do" value, when given); a stale CSRF token stops the request. */
function admin_form_post(?string $do = null): bool
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($do !== null && ($_POST['do'] ?? '') !== $do)) return false;
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    return true;
}

/** The hidden fields of an admin form: the CSRF token, and the action ("do") when given. */
function form_keys(string $do = ''): string
{
    return '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" />' . ($do !== '' ? '<input type="hidden" name="do" value="' . e($do) . '" />' : '');
}

function clean_text(mixed $v, int $max = 2000): string
{
    $s = is_scalar($v) ? (string) $v : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    // drop control characters, keep newlines and the joiners of emoji sequences (👨‍👩‍👧) and scripts that need them, the tags of
    // flag emoji (🏴 + tags) and characters newer than the server's Unicode tables (Cn: recent emoji would be lost otherwise)
    $s = preg_replace('/[^\P{C}\p{Cn}\n\t\x{200C}\x{200D}\x{E0020}-\x{E007F}]/u', '', $s) ?? '';
    $s = trim($s);
    return mb_substr($s, 0, $max);
}

function clean_list(mixed $v, int $max = 2000, int $items = 300): array
{
    if (!is_array($v)) return [];
    $out = [];
    foreach ($v as $x) {
        $t = clean_text($x, $max);
        if ($t !== '') $out[] = $t;
        if (count($out) >= $items) break;
    }
    return $out;
}

/** Image/link addresses: site paths (/media/…) or http(s) URLs only. */
function clean_url(mixed $v, bool $allowLocal = true): string
{
    $s = clean_text($v, 1000);
    if ($s === '') return '';
    if (str_starts_with($s, '//')) $s = 'https:' . $s;   // "//host/…" is another website, not a page of this one
    if ($allowLocal && preg_match('#^/[A-Za-z0-9/_\-.%]+$#D', $s) && !str_contains($s, '..')) return $s;
    if (preg_match('#^https?://[^\s"<>]+$#iD', $s) && filter_var($s, FILTER_VALIDATE_URL)) return $s;
    throw new InvalidArgumentException("Not a valid link or image address: $s");
}

/** A download link's address: https://…, or a short link to a file in the MEGA S4 bucket, "s4:<file>" (app/s4.php). */
function clean_dl_url(mixed $v): string
{
    $s = clean_text($v, 1000);
    if (preg_match('#^s4:/*([^"<>\x00-\x1f]+)$#i', $s, $m)) return 's4:' . trim($m[1]);
    return clean_url($s, false);
}

function check_slug(string $slug): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)) throw new InvalidArgumentException('The address (slug) may only contain lowercase letters, numbers and dashes.');
    if ($slug === 'outfit' || $slug === 'scene') throw new InvalidArgumentException('"' . $slug . '" is reserved, pick another address.');   // /get/outfit/…, /get/scene/…
}

/** The release badge of the admin lists: "⏱ Patrons 12 Oct" before the patrons date, "⏱ Free 20 Oct" until the public date. */
function admin_release_badge(array $item): string
{
    $r = m3d_release($item);
    return match (m3d_release_phase($item)) {
        'soon'  => ' <span class="badge tone-violet" title="' . e((m3d_release($item)['before'] === 'soon' ? 'Shown as “Coming Soon”' : 'Hidden') . ' until ' . m3d_release_when($r['patrons'])) . '">⏱ Patrons ' . e(m3d_release_day($r['patrons'])) . '</span>',
        'early' => ' <span class="badge tone-mustard" title="Patrons only until ' . e(m3d_release_when($r['public'])) . '">⏱ Free ' . e(m3d_release_day($r['public'])) . '</span>',
        default => '',
    };
}

/** A number column's title in the Models / Accessories lists as an icon (the name on hover and for screen readers), so the numbers take less room. */
const ADMIN_COL_ICONS = [
    'Downloads' => '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
    'Likes'     => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.2 4.2 0 0 1 12 7.3a4.2 4.2 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
    'Comments'  => '<path d="M5 5h14a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H10l-4.5 3.5V16H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/>',
];
function admin_icon_th(string $label): string
{
    return '<th class="c-num c-ico" title="' . e($label) . '"><svg viewBox="0 0 24 24" aria-hidden="true">' . ADMIN_COL_ICONS[$label] . '</svg><span class="sr-only">' . e($label) . '</span></th>';
}

/** The 🔒 badge of the admin lists: an item's extra download requirements in short ("€10+ tier · 6 months"), '' when none. */
function admin_req_badge(array $item): string
{
    $rq = m3d_requirements($item);
    $mt = implode($rq['mode'] === 'or' ? ' or ' : ' · ', array_filter([$rq['months'] ? m3d_plural($rq['months'], 'month') : '', $rq['total_cents'] ? m3d_money($rq['total_cents']) : '']));
    $txt = implode(' · ', array_filter([$rq['tier_cents'] ? m3d_money($rq['tier_cents']) . '+ tier' : '', $rq['tier_cents'] && $rq['mode'] === 'or' && $mt !== '' ? "($mt)" : $mt]));
    return $txt === '' ? '' : ' <span class="badge tone-sky" title="' . e('Extra download requirements on top of a paid tier: ' . $txt) . '">🔒 ' . e($txt) . '</span>';
}

/** Extra download requirements (on top of the paid tier): minimum tier, months as a patron, total support (amounts typed in currency units). */
function clean_requirements(array $in, array &$out): void
{
    $months = trim((string) ($in['requireMonths'] ?? ''));
    $total = str_replace(',', '.', trim((string) ($in['requireTotal'] ?? '')));
    $tier = str_replace(',', '.', trim((string) ($in['requireTier'] ?? '')));
    if ($months !== '' && (!ctype_digit($months) || (int) $months > 240)) throw new InvalidArgumentException('Months as a patron must be a whole number (0 = no requirement).');
    if ($tier !== '' && (!is_numeric($tier) || (float) $tier < 0 || (float) $tier > 10000)) throw new InvalidArgumentException('Minimum tier must be a price like 10 or 7.50 (0 = any paid tier).');
    if ($total !== '' && (!is_numeric($total) || (float) $total < 0 || (float) $total > 100000)) throw new InvalidArgumentException('Total support must be an amount like 20 or 20.50 (0 = no requirement).');
    if ((int) $months > 0) $out['requireMonths'] = (int) $months;
    if ((float) $total > 0) $out['requireTotalCents'] = (int) round((float) $total * 100);
    if ((float) $tier > 0) $out['requireTierCents'] = (int) round((float) $tier * 100);   // minimum tier: its price a month
    if (!empty($out['requireMonths']) && !empty($out['requireTotalCents']) && ($in['requireMode'] ?? '') === 'or') $out['requireMode'] = 'or';   // default: and
    if (!empty($in['noLimits'])) $out['noLimits'] = true;   // not counted in the download limits (Admin → Access)
}

/**
 * Release dates (editor → Access → Release dates): typed in the site's time zone ("2026-10-20T18:00", m3d_tz()), stored as UTC ISO dates
 * (patronsFrom, publicFrom) + releaseBefore (soon; absent = hidden before the patrons date). Empty = none.
 */
function clean_release(array $in, array &$out): void
{
    $tz = m3d_tz();
    $read = function (string $key, string $what) use ($in, $tz): ?int {
        $v = trim((string) ($in[$key] ?? ''));
        if ($v === '') return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr($v, 0, 16), $tz);
        if (!$d) throw new InvalidArgumentException("$what: pick a date and time.");
        return $d->getTimestamp();
    };
    $p = $read('releasePatrons', 'Patrons From');
    $e = $read('releasePublic', 'Everyone From');
    if ($p !== null && $e !== null && $e <= $p) throw new InvalidArgumentException('“Everyone From” must come after “Patrons From”.');
    if ($p !== null) $out['patronsFrom'] = gmdate('Y-m-d\TH:i:s\Z', $p);
    if ($e !== null) $out['publicFrom'] = gmdate('Y-m-d\TH:i:s\Z', $e);
    if ($p !== null && ($in['releaseBefore'] ?? '') === 'soon') $out['releaseBefore'] = 'soon';
}

/** The release dates as the editor's fields want them: site time "2026-10-20T18:00", '' when not set; tz = the site's time zone. */
function admin_release_local(array $item): array
{
    $r = m3d_release($item);
    $f = fn(?int $t) => $t === null ? '' : m3d_site_time($t, 'Y-m-d\TH:i');
    return ['patrons' => $f($r['patrons']), 'public' => $f($r['public']), 'before' => $r['before'], 'tz' => m3d_tz_id()];
}

/** Visibility (public | blurred | password | draft). The password is stored only as a hash; empty = keep the current one. */
function clean_visibility(array $in, ?array $existing, array &$out): void
{
    $v = (string) ($in['visibility'] ?? 'public');
    if (!isset(M3D_VISIBILITY[$v])) throw new InvalidArgumentException('Unknown visibility.');
    if ($v !== 'public') $out['visibility'] = $v;
    $pw = clean_text($in['password'] ?? '', 200);
    if ($pw !== '' && mb_strlen($pw) < 4) throw new InvalidArgumentException('The password needs at least 4 characters.');
    // kept when switching to another visibility, so switching back doesn't need a new password
    // the same password typed again keeps the old hash, so visitors who already entered it stay in
    $old = (string) ($existing['passwordHash'] ?? '');
    $hash = $pw !== '' && !($old !== '' && password_verify($pw, $old)) ? password_hash($pw, PASSWORD_DEFAULT) : $old;
    if ($v === 'password' && $hash === '') throw new InvalidArgumentException('Type a password, or pick another visibility.');
    if ($hash !== '') $out['passwordHash'] = $hash;
    if ($v === 'password' && !empty($in['unlisted'])) $out['unlisted'] = true;
}

/** Download links: [{label, url, access: patrons|public, off?, pw?}] (pw = the link's own password, the lock on its row: kept as typed, so the editor shows it again) */
function clean_links(mixed $v): array
{
    $out = [];
    foreach ((array) $v as $l) {
        if (!is_array($l)) continue;
        $url = clean_dl_url($l['url'] ?? '');
        $label = clean_text($l['label'] ?? '', 60);
        if ($url === '' && $label === '') continue;
        if ($url === '') throw new InvalidArgumentException('The download "' . $label . '" has no address.');
        $pw = clean_line($l['pw'] ?? '', 64);
        if ($pw !== '' && mb_strlen($pw) < 4) throw new InvalidArgumentException('The password of "' . ($label !== '' ? $label : 'Download') . '" needs at least 4 characters.');
        $out[] = ['label' => $label !== '' ? $label : 'Download', 'url' => $url, 'access' => ($l['access'] ?? '') === 'public' ? 'public' : 'patrons']
            + (!empty($l['off']) ? ['off' => true] : [])   // switched off in the editor: kept, but not on the site and /get/ refuses it
            + ($pw !== '' ? ['pw' => $pw] : []);
        if (count($out) >= 20) break;
    }
    return $out;
}

/** Add-ons needed to use a model/outfit: [{label, url, min?}] (a site page like /mustardui/ or a full https address; min = the
 *  oldest version of the add-on that works, as typed: "0.32", "2025.1"). */
function clean_addons(mixed $v): array
{
    $out = [];
    foreach ((array) $v as $a) {
        if (!is_array($a)) continue;
        $url = clean_url($a['url'] ?? '');
        $label = clean_text($a['label'] ?? '', 60);
        if ($url === '' && $label === '') continue;
        if ($url === '') throw new InvalidArgumentException('The add-on "' . $label . '" has no download address.');
        if ($label === '') throw new InvalidArgumentException('Give the add-on at ' . $url . ' a name.');
        $x = ['label' => $label, 'url' => $url];
        if (($min = clean_text($a['min'] ?? '', 20)) !== '') $x['min'] = $min;
        $out[] = $x;
        if (count($out) >= 10) break;
    }
    return $out;
}

/** The add-ons already used by models and accessories, for the editor's Add-Ons Required (addonList() in admin.js):
 *  one row per address [{label (the name most items give it), url, min (the highest minimum version set), n (items using it)}], most used first. */
function admin_addon_choices(): array
{
    $by = [];
    foreach ([...m3d_items('models'), ...m3d_items('outfits'), ...m3d_items('scenes')] as $it) {
        foreach (m3d_addons($it) as $a) {
            $url = (string) ($a['url'] ?? '');
            if ($url === '' || ($a['label'] ?? '') === '') continue;
            $e = &$by[$url];
            $e['n'] = ($e['n'] ?? 0) + 1;
            $e['l'][$a['label']] = ($e['l'][$a['label']] ?? 0) + 1;
            $min = (string) ($a['min'] ?? '');
            if ($min !== '' && strnatcasecmp($min, $e['min'] ?? '') > 0) $e['min'] = $min;
            unset($e);
        }
    }
    $out = [];
    foreach ($by as $url => $e) {
        arsort($e['l']);
        $out[] = ['label' => (string) array_key_first($e['l']), 'url' => (string) $url, 'min' => $e['min'] ?? '', 'n' => $e['n']];
    }
    usort($out, fn($a, $b) => $b['n'] <=> $a['n'] ?: strnatcasecmp($a['label'], $b['label']));
    return $out;
}

function clean_changelog(mixed $v): array
{
    $log = [];
    foreach ((array) $v as $en) {
        if (!is_array($en)) continue;
        $ver = clean_text($en['version'] ?? '', 60);
        if ($ver === '') throw new InvalidArgumentException('Every changelog entry needs a version.');
        $x = ['version' => $ver];
        foreach (['date' => 40, 'highlight' => 120] as $k => $max) { $t = clean_text($en[$k] ?? '', $max); if ($t !== '') $x[$k] = $t; }
        $x['changes'] = clean_list($en['changes'] ?? [], 3000, 500);
        $n = clean_list($en['notes'] ?? [], 5000, 50);
        if ($n) $x['notes'] = $n;
        $img = clean_url($en['image'] ?? '');
        if ($img !== '') $x['image'] = $img;
        if (in_array($en['stage'] ?? '', ['alpha', 'beta'], true)) {   // absent = standard
            $x['stage'] = $en['stage'];
            // "Beta 2" typed with Beta picked: the site adds the word itself, so keep "2" (never "Beta Beta 2")
            $bare = preg_replace('/^' . $en['stage'] . '[\s.:-]*(?=\S)/i', '', $ver);
            if (is_string($bare) && $bare !== '') $x['version'] = $bare;
        }
        $log[] = $x;
        if (count($log) >= 500) break;
    }
    return $log;
}

function clean_gallery(mixed $v): array
{
    $out = [];
    foreach ((array) $v as $g) {
        $src = clean_url(is_array($g) ? ($g['src'] ?? '') : $g);
        if ($src === '') continue;
        $cap = is_array($g) ? clean_text($g['caption'] ?? '', 200) : '';
        $link = is_array($g) ? clean_still_link($g['link'] ?? null) : [];   // a full size download, like a Gallery picture's
        $out[] = $cap !== '' || $link ? array_filter(['src' => $src, 'caption' => $cap, 'link' => $link]) : $src;
    }
    return $out;
}

function clean_outfit(array $in, ?array $existing): array
{
    $o = clean_item_base($in, $existing);
    $type = clean_text($in['type'] ?? '', 40);
    if ($type !== '' && $type !== 'Outfit') $o['type'] = mb_strtoupper(mb_substr($type, 0, 1)) . mb_substr($type, 1);   // missing = Outfit (m3d_accessory_type)
    $valid = array_column(m3d_models(), 'slug');
    $o['models'] = array_values(array_unique(array_filter(array_map('strval', (array) ($in['models'] ?? [])), fn($s) => in_array($s, $valid, true))));
    if (!empty($in['allModels'])) $o['allModels'] = true;   // made for every model, future ones too (m3d_fits_all()); the ticks above are kept for when it is turned off
    if (($in['fits'] ?? '') === 'blender') { $o['fits'] = 'blender'; $o['models'] = []; unset($o['allModels']); }   // made for Blender, no model (m3d_fits_blender()): it fits none
    $bl = clean_text($in['blender'] ?? '', 60);   // Blender Supported, as a model's ("4.5 or above")
    if ($bl !== '') $o['blender'] = $bl;
    foreach (['thumb', 'cover'] as $k) { $u = clean_url($in[$k] ?? ''); if ($u !== '') $o[$k] = $u; }   // catalog / main image, like a model's (outfit_preview(), outfit_main())
    $o['images'] = clean_gallery($in['images'] ?? []);
    return $o;
}

/** A scene: an accessory's fields without a type or "All Models"; `models` = the models in it, `modelVers` = the version of each
 *  it was made with (a label of that model's changelog as picked in the editor, or typed; none = not said). */
function clean_scene(array $in, ?array $existing): array
{
    $o = clean_item_base($in, $existing);
    $valid = array_column(m3d_models(), 'slug');
    $o['models'] = array_values(array_unique(array_filter(array_map('strval', (array) ($in['models'] ?? [])), fn($s) => in_array($s, $valid, true))));
    $vers = [];
    foreach ((array) ($in['modelVers'] ?? []) as $s => $v) {
        $v = clean_text($v, 30);
        if ($v !== '' && in_array((string) $s, $o['models'], true)) $vers[(string) $s] = $v;
    }
    if ($vers) $o['modelVers'] = $vers;
    $bl = clean_text($in['blender'] ?? '', 60);   // the Blender version the scene was made with, as typed ("4.5 or above")
    if ($bl !== '') $o['blender'] = $bl;
    $o['images'] = clean_gallery($in['images'] ?? []);
    return $o;
}

/** Docs: sections → pages. Addresses must be unique; the first page is the docs home (/docs/). */
function clean_docs(array $in): array
{
    $sections = [];
    $seen = [];
    foreach ((array) ($in['sections'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $title = clean_text($sec['title'] ?? '', 80);
        $pages = [];
        foreach ((array) ($sec['pages'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $pt = clean_text($p['title'] ?? '', 120);
            if ($pt === '') throw new InvalidArgumentException('Every page needs a title' . ($title !== '' ? ' (one in "' . $title . '" has none).' : '.'));
            $slug = strtolower(clean_text($p['slug'] ?? '', 64));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)) throw new InvalidArgumentException('The address of "' . $pt . '" may only contain lowercase letters, numbers and dashes.');
            if (isset($seen[$slug])) throw new InvalidArgumentException('Two pages use the address "' . $slug . '": "' . $seen[$slug] . '" and "' . $pt . '". Change one of them.');
            $seen[$slug] = $pt;
            $page = ['slug' => $slug, 'title' => $pt];
            $sum = clean_text($p['summary'] ?? '', 300);
            if ($sum !== '') $page['summary'] = $sum;
            if (!empty($p['draft'])) $page['draft'] = true;   // hidden from visitors (docs.php)
            $page['body'] = clean_text($p['body'] ?? '', 200000);
            $pages[] = $page;
        }
        if ($title === '' && !$pages) continue;
        if ($title === '') throw new InvalidArgumentException('Every section needs a title.');
        $sections[] = ['title' => $title, 'pages' => $pages];
    }
    if (!$seen) throw new InvalidArgumentException('The documentation needs at least one page.');
    return ['sections' => $sections];
}

// ── Blog (admin/blog.php, save.php kind "blog") ──────────────────────────────
/** One post from the editor ($old = the stored post, null for a new one; $posts = every stored post, for the unique address). */
function clean_post(array $in, ?array $old, array $posts): array
{
    $title = clean_text($in['title'] ?? '', 160);
    if ($title === '') throw new InvalidArgumentException('The post needs a title.');
    $slug = strtolower(clean_text($in['slug'] ?? '', 80));
    if ($slug === '') $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', m3d_fold($title)), '-'), 0, 70) ?: 'post';   // like slugify() in admin.js
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug)) throw new InvalidArgumentException('The address may only contain lowercase letters, numbers and dashes.');
    foreach ($posts as $p) if ($p['slug'] === $slug && $p['id'] !== ($old['id'] ?? '')) throw new InvalidArgumentException('Another post uses the address "' . $slug . '": “' . $p['title'] . '”. Change one of them.');
    $id = $old['id'] ?? '';   // never changes: likes are kept under it
    while ($id === '') { $id = bin2hex(random_bytes(4)); if (in_array($id, array_column($posts, 'id'), true)) $id = ''; }
    // the date as typed in the editor (site time, "2026-10-20T18:00"); empty = now
    $when = clean_text($in['date'] ?? '', 16);
    if ($when === '') $ts = time();
    else {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $when, m3d_tz());
        if (!$d || $d->format('Y-m-d\TH:i') !== $when) throw new InvalidArgumentException('The date isn’t a real day and time.');
        $ts = $d->getTimestamp();
    }
    $post = ['id' => $id, 'slug' => $slug, 'title' => $title, 'date' => gmdate('Y-m-d\TH:i:s\Z', $ts)];
    $sum = clean_text(preg_replace('/\s+/u', ' ', (string) ($in['summary'] ?? '')), 300);
    if ($sum !== '') $post['summary'] = $sum;
    $cover = clean_url($in['cover'] ?? '');
    if ($cover !== '') $post['cover'] = $cover;
    $post['body'] = clean_text($in['body'] ?? '', 200000);
    $topics = [];
    foreach (clean_list($in['topics'] ?? [], 40, 20) as $t) {
        $t = preg_replace('/\s+/u', ' ', $t);
        if (!in_array(m3d_fold($t), array_map('m3d_fold', $topics), true)) $topics[] = $t;
    }
    if (count($topics) > 6) throw new InvalidArgumentException('6 topics at most.');
    if ($topics) $post['topics'] = $topics;
    $items = [];
    foreach (clean_list($in['items'] ?? [], 120, 30) as $ref) {
        [$kind, $s] = explode(':', $ref, 2) + [1 => ''];
        if (in_array($kind, M3D_ITEM_KINDS, true) && m3d_item($kind, $s) && !in_array($ref, $items, true)) $items[] = $ref;
    }
    if (count($items) > 12) throw new InvalidArgumentException('12 models and accessories at most.');
    if ($items) $post['items'] = $items;
    $v = (string) ($in['visibility'] ?? 'public');
    if (!isset(BLOG_VISIBILITY[$v])) throw new InvalidArgumentException('Unknown visibility.');
    if ($v !== 'public') $post['visibility'] = $v;
    if (!empty($in['pinned'])) $post['pinned'] = true;
    if (!empty($in['discord'])) $post['discord'] = true;
    if (array_key_exists('comments', $in) ? empty($in['comments']) : !empty($old['noComments'])) $post['noComments'] = true;   // "Allow Comments" off (app/comments.php)
    foreach (['announced', 'tries'] as $k) if (isset($old[$k])) $post[$k] = $old[$k];   // Discord's own marks (m3d_blog_discord_run())
    if (($poll = clean_poll($in['poll'] ?? null)) !== null) $post['poll'] = $poll;
    return $post;
}

/** The post's poll from the editor (app/blog.php → "Polls"), or null for none (switched off, or nothing written). Options keep their ids
 *  (votes are kept under them); a new one gets a fresh id. Closes: as typed in the editor (site time), empty = open until removed. */
function clean_poll(mixed $in): ?array
{
    if (!is_array($in) || empty($in['on'])) return null;
    $q = clean_text(preg_replace('/\s+/u', ' ', (string) ($in['q'] ?? '')), 200);
    $taken = [];
    $opts = [];
    foreach (array_slice((array) ($in['opts'] ?? []), 0, POLL_OPTS_MAX + 1) as $o) {
        if (!is_array($o)) continue;
        $item = clean_text($o['item'] ?? '', 120);
        [$kind, $slug] = explode(':', $item, 2) + [1 => ''];
        $it = in_array($kind, M3D_ITEM_KINDS, true) ? m3d_item($kind, $slug) : null;
        $text = clean_text(preg_replace('/\s+/u', ' ', (string) ($o['text'] ?? '')), 80);
        if ($text === '' && $it) $text = $kind === 'models' ? m3d_model_name($it) : (string) $it['name'];
        if ($text === '') continue;   // an empty row
        $id = strtolower(clean_text($o['id'] ?? '', 8));
        if (!preg_match('/^[0-9a-f]{4}$/D', $id) || isset($taken[$id])) $id = '';
        while ($id === '' || isset($taken[$id])) $id = bin2hex(random_bytes(2));
        $taken[$id] = true;
        $opt = ['id' => $id, 'text' => $text];
        $pic = clean_url($o['pic'] ?? '');
        if ($pic !== '') $opt['pic'] = $pic;
        if ($it) $opt['item'] = $kind . ':' . $slug;
        $opts[] = $opt;
    }
    if ($q === '' && !$opts) return null;
    if ($q === '') throw new InvalidArgumentException('The poll needs a question (or switch the poll off).');
    if (count($opts) < 2) throw new InvalidArgumentException('The poll needs two choices at least.');
    if (count($opts) > POLL_OPTS_MAX) throw new InvalidArgumentException(POLL_OPTS_MAX . ' poll choices at most.');
    $poll = ['q' => $q, 'opts' => $opts, 'max' => max(1, min(count($opts), (int) ($in['max'] ?? 1)))];
    $when = clean_text($in['closes'] ?? '', 16);
    if ($when !== '') {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $when, m3d_tz());
        if (!$d || $d->format('Y-m-d\TH:i') !== $when) throw new InvalidArgumentException('The poll’s closing date isn’t a real day and time.');
        $poll['closes'] = gmdate('Y-m-d\TH:i:s\Z', $d->getTimestamp());
    }
    $res = (string) ($in['results'] ?? 'after');
    $poll['results'] = isset(POLL_RESULTS[$res]) ? $res : 'after';
    if (!empty($in['change'])) $poll['change'] = true;
    return $poll;
}

/** A post's revision for the editor: what the editor shows (not Discord's marks, which change without it). */
function admin_post_rev(array $p): string
{
    unset($p['announced'], $p['tries']);
    return md5((string) json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** The posts as the editor gets them: each with its revision, its date in site time ("2026-10-20T18:00"), state and list picture. */
function admin_blog_posts(): array
{
    return array_map(fn($p) => $p + ['rev' => admin_post_rev($p), 'local' => m3d_site_time(m3d_post_ts($p), 'Y-m-d\TH:i'), 'state' => m3d_post_state($p),
        'votes' => m3d_poll($p) ? m3d_poll_tally($p) : null, 'closesLocal' => ($t = m3d_poll_closes((array) ($p['poll'] ?? []))) ? m3d_site_time($t, 'Y-m-d\TH:i') : '',
        'thumb' => str_starts_with((string) ($p['cover'] ?? ''), '/media/') ? img_thumb((string) $p['cover']) : ''], m3d_blog(true)['posts']);
}

function clean_faq(array $in): array
{
    $sections = [];
    foreach ((array) ($in['sections'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $title = clean_text($sec['title'] ?? '', 120);
        $items = [];
        foreach ((array) ($sec['items'] ?? []) as $it) {
            if (!is_array($it)) continue;
            $q = clean_text($it['q'] ?? '', 300);
            $a = clean_text($it['a'] ?? '', 20000);
            if ($q === '' && $a === '') continue;
            if ($q === '') throw new InvalidArgumentException('A question in "' . ($title ?: 'a topic') . '" has an answer but no question.');
            $items[] = ['q' => $q, 'a' => $a];
        }
        if ($title === '' && !$items) continue;
        if ($title === '') throw new InvalidArgumentException('Every topic needs a title.');
        $sections[] = ['title' => $title, 'items' => $items];
    }
    return ['intro' => clean_text($in['intro'] ?? '', 2000), 'sections' => $sections];
}

/**
 * What models and accessories share: address, name, order (set by dragging in the list), visibility, description, download links,
 * add-ons, requirements, changelog.
 */
function clean_item_base(array $in, ?array $existing): array
{
    $it = [];
    $it['slug'] = $existing['slug'] ?? strtolower(clean_text($in['slug'] ?? '', 64));
    check_slug($it['slug']);
    $it['name'] = clean_text($in['name'] ?? '', 120);
    if ($it['name'] === '') throw new InvalidArgumentException('Name is required.');
    $it['order'] = (int) ($existing['order'] ?? 9999);
    clean_visibility($in, $existing, $it);
    $desc = clean_text($in['description'] ?? '', 5000);
    if ($desc !== '') $it['description'] = $desc;
    $it['links'] = clean_links($in['links'] ?? []);
    $m3d = trim((string) ($in['model3d'] ?? ''));   // 3D preview: a .glb uploaded here (app/model3d.php)
    if ($m3d !== '') {
        if (!preg_match('#^/media/([a-z0-9-]+/)*[a-z0-9-]+\.glb$#D', $m3d)) throw new InvalidArgumentException('The 3D preview must be a .glb file uploaded here.');
        $it['model3d'] = $m3d;
        $light = (int) round((float) ($in['model3dLight'] ?? 100));   // the viewer's light, % (app/model3d.php)
        if ($light !== 100) $it['model3dLight'] = max(M3D_LIGHT_RANGE[0], min(M3D_LIGHT_RANGE[1], $light));
    }
    if ($addons = clean_addons($in['addons'] ?? [])) $it['addons'] = $addons;
    if (array_key_exists('comments', $in) ? empty($in['comments']) : !empty($existing['noComments'])) $it['noComments'] = true;   // "Allow Comments" off (app/comments.php)
    clean_requirements($in, $it);
    clean_release($in, $it);
    $note = clean_text($in['changelogNote'] ?? '', 1000);
    if ($note !== '') $it['changelogNote'] = $note;
    $log = clean_changelog($in['changelog'] ?? []);
    if ($log) $it['changelog'] = $log;
    return $it;
}

function clean_model(array $in, ?array $existing): array
{
    $m = clean_item_base($in, $existing);
    $m['franchise'] = clean_text($in['franchise'] ?? '', 80);
    if ($m['franchise'] === '') throw new InvalidArgumentException('Franchise is required.');
    foreach (['game' => 120, 'note' => 120, 'blender' => 60, 'expected' => 40] as $k => $max) {   // expected: the page's Expected Release (In Development)
        $t = clean_text($in[$k] ?? '', $max);
        if ($t !== '') $m[$k] = $t;
    }
    $gen = clean_text($in['generation'] ?? '', 10);
    if ($gen !== '') {
        if (!is_numeric($gen)) throw new InvalidArgumentException('Generation must be a number, e.g. 4 or 3.5');
        $m['generation'] = $gen + 0;
    }
    // major version (1, 2…): models with the same name and one are versions of each other (m3d_model_versions)
    $major = clean_text((string) ($in['majorVersion'] ?? ''), 4);
    if ($major !== '') {
        if (!ctype_digit($major) || (int) $major < 1 || (int) $major > 99) throw new InvalidArgumentException('Major version must be a whole number from 1 to 99.');
        $m['majorVersion'] = (int) $major;
    }
    $m['status'] = clean_text((string) ($in['status'] ?? ''), 20);   // the stored status (m3d_status() would read Public past a public date)
    if (!isset(M3D_STATUSES[$m['status']])) throw new InvalidArgumentException('Unknown status.');
    if (!empty($in['featured'])) $m['featured'] = true;
    foreach (['thumb', 'cover'] as $k) { $u = clean_url($in[$k] ?? ''); if ($u !== '') $m[$k] = $u; }

    $gallery = clean_gallery($in['gallery'] ?? []);
    if ($gallery) $m['gallery'] = $gallery;

    foreach (['features', 'futureIdeas', 'knownIssues', 'credits'] as $k) {
        $l = clean_list($in[$k] ?? []);
        if ($l) $m[$k] = $l;
    }
    $notes = [];
    foreach ((array) ($in['notes'] ?? []) as $n) {
        $text = clean_text(is_array($n) ? ($n['text'] ?? '') : $n, 5000);
        $img = clean_url(is_array($n) ? ($n['image'] ?? '') : '');
        if ($text === '' && $img === '') continue;
        $notes[] = $img !== '' ? ['text' => $text, 'image' => $img] : $text;
    }
    if ($notes) $m['notes'] = $notes;

    $extra = [];
    foreach ((array) ($in['extra'] ?? []) as $sec) {
        if (!is_array($sec)) continue;
        $title = clean_text($sec['title'] ?? '', 120);
        $groups = [];
        foreach ((array) ($sec['groups'] ?? []) as $g) {
            $intro = clean_text($g['intro'] ?? '', 2000);
            $items = clean_list($g['items'] ?? []);
            if ($intro === '' && !$items) continue;
            $groups[] = ($intro !== '' ? ['intro' => $intro] : []) + ['items' => $items];
        }
        if ($title !== '' || $groups) $extra[] = ['title' => $title ?: 'More', 'groups' => $groups];
    }
    if ($extra) $m['extra'] = $extra;
    return $m;
}

// ── "How to format" help: only the site's own extras to Markdown (standard Markdown isn't explained); what you write → what you get,
//    rendered by md_html itself, so it never goes stale ──
const FORMAT_EXAMPLES = [
    'box'     => ['Box', "> [!TIP] Faster posing\n> Use the pose library.\n\n> [!WARNING]\n> Save your file first."],
    'image'   => ['Picture', "![Caption](/media/…)"],
    'carousel' => ['Carousel', "::: carousel\n![Front](/media/…)\n![Back](/media/…)\n:::"],
    'card'    => ['Card', "[Get the Pose Library](/accessories/pose-library/)"],
    'fold'    => ['Expandable Box', "+++ Texture sizes\nAll textures are 4K.\n+++\n\n> [!TIP]- Faster posing\n> Use the pose library."],
    'tabs'    => ['Tabs', "=== Manual\n1. Open the Outliner\n=== With MustardUI\n1. Press N, open MustardUI\n==="],
    'paywall' => ['Patrons Only from Here', "Everyone reads this.\n\n--- Patreon ---\n\nOnly patrons read this."],
];

/** The Card example made from the site's own content (the first listed model, else accessory, else docs page), so the help shows a real card; '' when there is none. */
function format_card_src(): string
{
    foreach (['models', 'outfits'] as $kind) {
        $it = m3d_listed_items($kind)[0] ?? null;
        if ($it) return '[' . str_replace(['[', ']'], '', (string) $it['name']) . '](' . m3d_item_url($kind, (string) $it['slug']) . ')';
    }
    $doc = m3d_doc_pages(true)[0] ?? null;
    return $doc ? '[' . str_replace(['[', ']'], '', (string) $doc['title']) . '](/docs/' . $doc['slug'] . '/)' : '';
}

/** Collapsible help: one row per example (label · what you type · how it looks). $docs: headings as on docs pages. */
function format_help(string $title, array $keys, string $intro = '', bool $docs = false): string
{
    $rows = '';
    foreach ($keys as $k) {
        [$label, $src] = FORMAT_EXAMPLES[$k];
        if ($k === 'card' && ($real = format_card_src()) !== '') $src = $real;   // a card of the site's own: the real preview
        $out = match (true) {
            $k === 'card' && $src === FORMAT_EXAMPLES['card'][1] => '<p class="muted small">A link to a model, accessory or docs page alone on its line shows as a card with its picture and description.</p>',
            $k === 'image' => '<p class="muted small">On its own line. Several lines together make a gallery. Drop or paste a picture into the text to upload it.</p>',
            $k === 'carousel' => '<p class="muted small">One picture at a time, with ‹ › and dots (swipe on phones). A click opens it big.</p>',
            default => md_html($src, ['docs' => $docs, 'paywall' => true]),
        };
        $rows .= '<div class="fmt-row"><div class="fmt-label">' . e($label) . '</div><pre class="fmt-src"><code>' . e($src) . '</code></pre><div class="fmt-out md">' . $out . '</div></div>';
    }
    return '<details class="sec format-help"><summary><b>' . e($title) . '</b></summary>'
        . '<p class="muted small fmt-intro">Standard Markdown works. Below are this site’s own additions.' . ($intro !== '' ? ' ' . $intro : '') . '</p>'
        . '<div class="fmt-head" aria-hidden="true"><span></span><span>You Write</span><span>You Get</span></div>' . $rows . '</details>';
}

// ── "Getting started" box of model / accessory pages (Admin → Models and Admin → Accessories, settings.json → start.<kind>) ──
/** Handles the box's POST (do=start with kind); returns [done, error] or [null, null] when not ours. */
function admin_start_post(string $kind): array
{
    if (($_POST['kind'] ?? '') !== $kind || !admin_form_post('start')) return [null, null];
    $start = m3d_settings()['start'] ?? [];
    try {
        $links = admin_posted_links('start', 10);
        $note = clean_text($_POST['start_note'] ?? '', 400);
        $start[$kind] = ['links' => $links, 'note' => $note] + (empty($_POST['start_note_on']) ? ['note_off' => true] : []);
        return m3d_save_settings(['start' => $start]) ? ['Getting started links saved.', null] : [null, m3d_write_error()];
    } catch (InvalidArgumentException $ex) {
        return [null, $ex->getMessage()];
    }
}

/** One row of a links editor (text + address, drag handle, remove), for forms using data-rows-form (admin.js); $p = the field names' prefix. */
function admin_link_row(string $p, string $label = '', string $url = ''): string
{
    return '<div class="start-row" data-row>'
        . '<button type="button" class="drag-handle drag-sm" data-row-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>'
        . '<input class="input" name="' . $p . '_label[]" value="' . e($label) . '" maxlength="40" placeholder="Text" aria-label="Link text" />'
        . '<input class="input" name="' . $p . '_url[]" value="' . e($url) . '" maxlength="500" placeholder="/docs/… or https://…" list="site-pages" aria-label="Address" spellcheck="false" />'
        . '<button type="button" class="icon-btn del" data-row-del title="Remove" aria-label="Remove">✕</button></div>';
}

/** Rows posted by a links editor as [{label, url}]: a page of this site (/…) or https; empty rows skipped. */
function admin_posted_links(string $p, int $max = 12): array
{
    $links = [];
    foreach ((array) ($_POST[$p . '_label'] ?? []) as $i => $label) {
        $label = mb_substr(trim((string) $label), 0, 40);
        $url = trim((string) ($_POST[$p . '_url'][$i] ?? ''));
        if ($label === '' && $url === '') continue;
        $links[] = ['label' => $label, 'url' => admin_link_url($label, $url)];
    }
    if (count($links) > $max) throw new InvalidArgumentException("$max links at most.");
    return $links;
}

/** The address of a link typed in a links editor (a page of this site, /…, or https), or an error naming the link. */
function admin_link_url(string $label, string $url): string
{
    if ($label === '' || $url === '') throw new InvalidArgumentException('Every link needs a text and an address.');
    $local = preg_match('#^/[A-Za-z0-9/_\-.%?=&\#]*$#D', $url) && !str_starts_with($url, '//');
    if (!$local && !is_https_url($url)) throw new InvalidArgumentException("“{$label}”: the address must be a page of this site (like /faq/) or start with https://");
    return mb_substr($url, 0, 500);
}

/** Admin → Settings → Header Menu: the hint after a built-in item that has nothing to show now (its feature is off or empty), else ''. */
function admin_menu_item_note(string $key): string
{
    if (menu_item_ready($key)) return '';
    return [
        'gallery' => 'Shown once the Gallery has pictures',
        'blog' => 'Shown while the blog is on and has posts',
        'tools' => 'Shown once a tool page with “Show in the Header Menu” is published',
        'docs' => 'Shown once there are docs pages',
        'faq' => 'Shown once the FAQ has questions',
        'bugs' => 'Shown while bug reports are on',
    ][$key] ?? '';
}

/** One row of the Header Menu editor: a built-in item ($key) or an added link; $t = the row's code, which its Show switch posts. */
function admin_menu_row(string $t, string $key = '', string $label = '', string $url = '', bool $on = true): string
{
    $drag = '<button type="button" class="drag-handle drag-sm" data-row-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>';
    $show = '<label class="menu-show" title="Show in the menu"><input type="checkbox" class="switch" name="m_on[]" value="' . e($t) . '"' . ($on ? ' checked' : '') . ' aria-label="Show ' . e($label !== '' ? $label : 'this link') . ' in the menu" data-row-token-on /></label>';
    $keys = '<input type="hidden" name="m_t[]" value="' . e($t) . '" data-row-token /><input type="hidden" name="m_key[]" value="' . e($key) . '" />';
    if ($key !== '') {
        $note = admin_menu_item_note($key);
        return '<div class="menu-row is-builtin" data-row>' . $drag . $keys
            . '<input type="hidden" name="m_label[]" value="" /><input type="hidden" name="m_url[]" value="" />'
            . '<span class="menu-name">' . e($label) . ' <span class="badge tone-grey">Built-In</span></span>'
            . '<span class="menu-note muted small' . ($note !== '' ? '' : ' is-url') . '">' . e($note !== '' ? $note : $url) . '</span>' . $show . '<span></span></div>';
    }
    return '<div class="menu-row" data-row>' . $drag . $keys
        . '<input class="input" name="m_label[]" value="' . e($label) . '" maxlength="' . MENU_LABEL_MAX . '" placeholder="Text" aria-label="Link text" />'
        . '<input class="input menu-url" name="m_url[]" value="' . e($url) . '" maxlength="500" placeholder="/docs/… or https://…" list="site-pages" aria-label="Address" spellcheck="false" />'
        . $show . '<button type="button" class="icon-btn del" data-row-del title="Remove" aria-label="Remove">✕</button></div>';
}

/** The posted Header Menu editor as settings.json → menu: [{key} | {label, url}, + off: true]. */
function admin_menu_posted(): array
{
    $in = fn(string $k, $i): string => is_scalar($v = ((array) ($_POST[$k] ?? []))[$i] ?? '') ? trim((string) $v) : '';
    $on = array_filter((array) ($_POST['m_on'] ?? []), 'is_string');
    $menu = [];
    $seen = [];
    $added = 0;
    foreach ((array) ($_POST['m_t'] ?? []) as $i => $t) {
        $key = $in('m_key', $i);
        $off = !is_string($t) || !in_array($t, $on, true);
        if ($key !== '') {
            if (isset(MENU_ITEMS[$key]) && !isset($seen[$key])) $menu[] = ['key' => $key] + ($off ? ['off' => true] : []);
            $seen[$key] = true;
            continue;
        }
        $label = mb_substr($in('m_label', $i), 0, MENU_LABEL_MAX);
        $url = $in('m_url', $i);
        if ($label === '' && $url === '') continue;
        $menu[] = ['label' => $label, 'url' => admin_link_url($label, $url)] + ($off ? ['off' => true] : []);
        $added++;
    }
    if ($added > MENU_LINKS_MAX) throw new InvalidArgumentException(MENU_LINKS_MAX . ' added links at most: the menu has to fit in the header.');
    return $menu;
}

/** The pages of this site, for the address suggestions of the links editors (<datalist id="site-pages">). */
function admin_site_pages_datalist(): string
{
    $pages = '';
    foreach (m3d_doc_pages(true) as $pg) $pages .= '<option value="' . e($pg['slug'] === m3d_docs_home() ? '/docs/' : '/docs/' . $pg['slug'] . '/') . '">' . e('Docs: ' . ($pg['title'] ?? $pg['slug'])) . '</option>';
    foreach (['/faq/' => 'FAQ', '/accessories/' => 'Accessories'] + array_column(array_map(fn($t) => [m3d_tool_url($t), $t['name']], m3d_tools_summary()['tools']), 1, 0) + ['/contacts/' => 'Contacts', '/account/' => 'My Account'] as $u => $l) $pages .= '<option value="' . $u . '">' . $l . '</option>';
    return '<datalist id="site-pages">' . $pages . '</datalist>';
}

/** The collapsible editor of the box: links (text + address, drag to reorder) and the line under them. */
function admin_start_box(string $kind, bool $open = false): string
{
    $box = start_box($kind);
    $what = m3d_kind_word($kind);
    $row = fn(string $label = '', string $url = '') => admin_link_row('start', $label, $url);
    return '<details class="sec intro-sec" id="start"' . ($open ? ' open' : '') . '>'
        . '<summary><b>Getting Started</b> <span class="muted small">the box under the download button on ' . $what . ' pages</span></summary>'
        . '<form method="post" class="intro-form start-col" data-rows-form>'
        . form_keys('start') . '<input type="hidden" name="kind" value="' . e($kind) . '" />'
        . admin_site_pages_datalist()
        . '<div class="start-rows" data-rows>' . implode('', array_map(fn($l) => $row($l[0], $l[1]), $box['links'])) . '</div>'
        . '<template data-row-template>' . $row() . '</template>'
        . '<div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add Link</button></div>'
        . '<label class="field"><span class="lbl">Note</span><textarea class="input" name="start_note" rows="2" maxlength="400" placeholder="Empty = no line">' . e($box['note']) . '</textarea></label>'
        . '<label class="toggle"><input type="checkbox" class="switch" name="start_note_on" value="1"' . ($box['note_on'] ? ' checked' : '') . ' /><span>Show Note</span></label>'
        . '<div class="form-actions"><button class="btn btn-primary btn-sm">Save Links</button></div></form>'
        . '</details>';
}

/** Help text behind a "?" (shown on hover, focus or tap by admin.js, like tip() there): keeps explanations out of the way. */
function tip(string $text): string
{
    return '<button type="button" class="tip" data-tip="' . e($text) . '" aria-label="' . e('Help: ' . $text) . '">?</button>';
}

/**
 * A key field (a webhook, secret, token, or a setting that breaks logins / downloads when mistyped). While a value is saved ($saved) it shows
 * locked, with Change at its right end (`[data-sf]` in admin.js, keyField() there builds the same for the editors): Change opens the field,
 * Cancel or Esc locks it again with its saved value. A secret ($value null) is never printed: the box shows dots and the field stays empty
 * (an empty field keeps the saved one on the server). A plain value shows in the box and stays in the hidden field, so it is posted as it is.
 * $attrs = the input's other attributes (name, type, maxlength…, a class to add), already escaped; $status = HTML in place of "✓ Saved" (the GitHub key's check).
 */
function key_field(string $label, bool $saved, ?string $value, string $attrs, string $status = ''): string
{
    $cls = 'input';   // a class="…" in $attrs adds to it
    if (preg_match('/(?:^|\s)class="([^"]*)"/', $attrs, $m)) { $cls .= ' ' . $m[1]; $attrs = str_replace($m[0], '', $attrs); }
    $input = '<input class="' . $cls . '" value="' . e($value ?? '') . '" ' . trim($attrs) . ' />';
    if (!$saved) return $input;
    $shown = $value === null ? '<span class="sf-dots" aria-label="Hidden">••••••••••</span>' . ($status !== '' ? $status : '<span class="sf-ok">✓ Saved</span>')
        : '<span class="sf-val">' . e($value) . '</span>' . $status;
    return '<span class="sf is-locked" data-sf><span class="sf-lock"><svg viewBox="0 0 24 24" aria-hidden="true">' . KEY_LOCK_ICON . '</svg>' . $shown . '</span>'
        . $input . '<button type="button" class="btn btn-ghost btn-sm sf-btn" data-sf-btn aria-label="' . e('Change ' . $label) . '">Change</button></span>';
}
const KEY_LOCK_ICON = '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>';

/**
 * What Discord announcements know about one item, for the editor's pill in the header of that version (discordPill() in admin.js): null while
 * they are off; else the version out as stored (`stored`), its labels already marked as sent or waiting for another try, and the settings link.
 */
function admin_discord_state(string $kind, ?array $item): ?array
{
    if (!m3d_discord_on()) return null;
    $d = m3d_discord();
    $pre = $kind . ':' . ($item['slug'] ?? '') . '@';
    $mine = fn(array $codes) => array_values(array_map(fn($c) => substr($c, strlen($pre)), array_filter($codes, fn($c) => ($item['slug'] ?? '') !== '' && str_starts_with($c, $pre))));
    return [
        'stored' => $item && ($item['slug'] ?? '') !== '' ? m3d_version_label(m3d_current_version($item)) : '',
        'open'   => $item && ($item['slug'] ?? '') !== '' && m3d_is_open($item),   // visible when stored: a new version is news; a hidden item published now posts its version
        'dev'    => $kind === 'models' && $item && m3d_status($item) === 'in-development',   // stored In Development: its codes end in "~dev" (m3d_discord_code()); leaving development posts the version again
        'now'    => m3d_site_time(time(), 'Y-m-d\TH:i'),   // site time, to compare with the release date fields
        'sent'   => $mine($d['sent']),
        'retry'  => $mine($d['retry']),
        'url'    => settings_url('discord'),
    ];
}

/** After a save: what Discord did, when the save posted something ({ok, error, what}); null when nothing was posted. */
function admin_discord_result(?array $before): ?array
{
    $last = m3d_settings(true)['discord']['last'] ?? null;
    if (!is_array($last) || ($last['at'] ?? '') === ($before['at'] ?? null) || !empty($last['stills'])) return null;
    return ['ok' => !empty($last['ok']), 'error' => (string) ($last['error'] ?? ''), 'what' => (string) ($last['what'] ?? '')];
}

/** Embed of the newest version on the site (Settings → Discord: test message and preview); a plain line when there is none. */
function discord_sample_embed(): array
{
    $s = m3d_discord_sample();
    return $s ? m3d_discord_embed($s[0], $s[1]) : ['title' => site_name(), 'url' => abs_url('/'), 'description' => 'New versions will show up here.'];
}

/** Discord's own text marks, as Discord shows them: **bold**, *italic*, `code`, [links](https://…), @everyone / @here / role mentions. Same rules in admin.js (discordMd). */
function discord_md(string $text): string
{
    $h = e($text);
    $h = preg_replace('#\[([^\]\n]+)\]\((https?://[^)\s]+)\)#', '<a href="$2" target="_blank" rel="noopener">$1</a>', $h);
    $h = preg_replace('#\*\*(.+?)\*\*#s', '<b>$1</b>', $h);
    $h = preg_replace('#(?<![*\w])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![*\w])#', '<i>$1</i>', $h);
    $h = preg_replace('#`([^`\n]+)`#', '<code>$1</code>', $h);
    $h = preg_replace(['#@(everyone|here)\b#', '#&lt;@&amp;\d+&gt;#'], ['<span class="dc-ment">@$1</span>', '<span class="dc-ment">@role</span>'], $h);
    return nl2br($h, false);
}

/** A role written as plain text ("@Notifications") pings no one in a webhook post: Discord needs <@&role id>. The warning under the
 *  Intro Message field, '' when there is none. Same rule in admin.js (plainMention). */
function discord_plain_mention_warning(string $text): string
{
    preg_match_all('#(?:^|[\s(])@([\p{L}\p{N}_.-]*[\p{L}\p{N}_])#u', $text, $m);
    $name = array_values(array_diff($m[1], ['everyone', 'here']))[0] ?? '';
    if ($name === '') return '';
    return "“@{$name}” won’t ping anyone: Discord needs the role’s code. Send \\@{$name} in any Discord channel and paste here what Discord shows (<@&…>).";
}

/** What a post looks like in Discord (Settings → Discord announcements): the message line (admin.js keeps it in step with the field) and the card
 *  of the newest version, drawn from the same keys as m3d_discord_card() (title, the line under it, icon, facts, What's New, picture, buttons). */
function discord_preview_html(string $message): string
{
    $em = discord_sample_embed();
    $color = isset($em['color']) ? sprintf('#%06x', $em['color']) : '#1e1f22';
    $name = site_name();
    $now = m3d_site_time(time(), 'H:i');
    // Discord markdown of the card's own lines: "\x" escapes and <t:…:d> dates, then discord_md()
    $md = fn(string $t) => preg_replace_callback('#&lt;t:(\d+):d&gt;#', fn($m) => '<span class="dc-date">' . e(m3d_site_time((int) $m[1], 'd/m/Y')) . '</span>',
        discord_md(preg_replace_callback('/\\\\([^\w\s])/', fn($m) => "\u{E000}" . dechex(mb_ord($m[1])) . ';', $t)));
    $md2 = fn(string $t) => preg_replace_callback('/\x{E000}([0-9a-f]+);/u', fn($m) => e(mb_chr(hexdec($m[1]))), $md($t));
    $sub = (string) ($em['sub'] ?? e((string) ($em['footer']['text'] ?? '')));
    $facts = '';
    foreach ((array) ($em['facts'] ?? []) as [$k, $v]) $facts .= '<span class="dc-k">' . e($k) . '</span><span class="dc-v">' . $md2((string) $v) . '</span>';
    $buttons = '';
    foreach ((array) ($em['buttons'] ?? []) as [$label, $url]) $buttons .= '<a class="dc-btn" href="' . e((string) $url) . '" target="_blank" rel="noopener" tabindex="-1">' . e((string) $label) . '</a>';
    $text = (string) ($em['description'] ?? '');
    return '<div class="dc-prev" aria-hidden="true"><div class="dc-msg">'
        . '<span class="dc-ava">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>'
        . '<div class="dc-body"><div class="dc-head"><b class="dc-name">' . e($name) . '</b><span class="dc-app">APP</span><span class="dc-time">Today at ' . e($now) . '</span></div>'
        . '<div class="dc-content" data-dc-content' . (trim($message) === '' ? ' hidden' : '') . '>' . discord_md($message) . '</div>'
        . '<div class="dc-card" style="--dc-color: ' . e($color) . '">'
        . '<div class="dc-top"><div>' . ($sub !== '' ? '<div class="dc-sub">' . (isset($em['sub']) ? $md2($sub) : $sub) . '</div>' : '')
        . '<a class="dc-title" href="' . e((string) ($em['url'] ?? '')) . '" target="_blank" rel="noopener" tabindex="-1">' . e((string) ($em['title'] ?? '')) . '</a>'
        . (($em['below'] ?? '') !== '' ? '<div class="dc-sub dc-below">' . $md2((string) $em['below']) . '</div>' : '') . '</div>'
        . (!empty($em['thumb']) ? '<img class="dc-thumb" src="' . e((string) $em['thumb']) . '" alt="" loading="lazy" />' : '') . '</div>'
        . ($facts !== '' ? '<div class="dc-facts">' . $facts . '</div>' : '')
        . (($em['note'] ?? '') !== '' ? '<div class="dc-desc">' . $md2((string) $em['note']) . '</div>' : '')
        . ($text !== '' ? '<div class="dc-desc">' . (($em['head'] ?? '') !== '' ? '<b class="dc-h3">' . e((string) $em['head']) . '</b>' : '') . $md2($text) . '</div>' : '')
        . (isset($em['image']['url']) ? '<img class="dc-img" src="' . e((string) $em['image']['url']) . '" alt="" loading="lazy" />' : '')
        . ($buttons !== '' ? '<div class="dc-btns">' . $buttons . '</div>' : '')
        . '</div></div></div></div>';
}

/** Links column of the Models / Accessories lists: "2 patron / 1 public"; hovering (or focusing) it lists the link names (admin.js, data-tip-links). */
function admin_links_summary(array $links, array $dead = []): string
{
    // $dead = lc_dead_for() [url => …]: those names are red in the hover list ("Mega · dead link"), the text is red and opens Link Check
    // (the row turns red too, .has-dead-link). Dead add-ons get their own group, so an item whose only dead address is an add-on shows it
    // links switched off are listed under "Hidden" and not counted; links with their own password get a lock (after the count, before the name)
    $names = ['patrons' => [], 'public' => [], 'off' => [], 'addons' => []];
    $locked = ['patrons' => 0, 'public' => 0];
    $seen = [];
    foreach ($links as $l) {
        $url = trim((string) ($l['url'] ?? ''));
        $n = trim((string) ($l['label'] ?? '')) ?: 'Download';
        $k = !empty($l['off']) ? 'off' : (($l['access'] ?? 'patrons') === 'public' ? 'public' : 'patrons');
        $pw = m3d_link_pw($l) !== '';
        if ($pw && isset($locked[$k])) $locked[$k]++;
        $names[$k][] = isset($dead[$url]) || $pw ? ['n' => $n] + (isset($dead[$url]) ? ['dead' => true] : []) + ($pw ? ['pw' => true] : []) : $n;
        $seen[$url] = true;
    }
    foreach ($dead as $url => $d) if (!isset($seen[$url])) $names['addons'][] = ['n' => $d['label'], 'dead' => true];
    [$p, $f] = [count($names['patrons']), count($names['public'])];
    if (!$p && !$f && !$dead && !$names['off']) return '<span class="warn-dot" title="No download links">—</span>';
    $lock = fn(string $k) => $locked[$k] ? '<svg class="ls-lock" viewBox="0 0 24 24" aria-hidden="true">' . KEY_LOCK_ICON . '</svg>' : '';
    $txt = ($p ? '<span class="ok-dot">' . $p . ' patron' . $lock('patrons') . '</span>' : '') . ($p && $f ? '<br>' : '') . ($f ? '<span class="muted">' . $f . ' public' . $lock('public') . '</span>' : '') . ($p || $f ? '' : '—');
    $label = fn(array $list) => implode(', ', array_map(fn($x) => is_array($x) ? $x['n'] . (!empty($x['pw']) ? ' (password)' : '') . (!empty($x['dead']) ? ' (dead link)' : '') : $x, $list));
    $aria = implode('; ', array_filter([$p ? 'Patrons: ' . $label($names['patrons']) : '', $f ? 'Public: ' . $label($names['public']) : '', $names['off'] ? 'Hidden: ' . $label($names['off']) : '', $names['addons'] ? 'Add-ons: ' . $label($names['addons']) : '']));
    $attrs = ' data-tip-links="' . e(json_encode($names, JSON_UNESCAPED_UNICODE)) . '" aria-label="' . e($aria) . '"';
    return $dead ? '<a class="links-sum is-dead" href="/admin/links.php"' . $attrs . '>' . $txt . '</a>' : '<span class="links-sum" tabindex="0"' . $attrs . '>' . $txt . '</span>';
}

// ── Admin layout ─────────────────────────────────────────────────────────────
/** Badge for the admin lists when a model/outfit isn't fully public. */
function admin_visibility_badge(array $item): string
{
    return match (m3d_visibility($item)) {
        'draft'    => ' <span class="badge tone-grey" title="Only admins can see it">Draft</span>',
        'password' => ' <span class="badge tone-violet" title="Visitors need the password' . (!empty($item['unlisted']) ? '; not listed in the catalog' : '') . '">🔑 Password' . (!empty($item['unlisted']) ? ' · Unlisted' : '') . '</span>',
        'blurred'  => ' <span class="badge tone-sky" title="Pictures are blurred for everyone">Blurred</span>',
        'blurred-patrons' => ' <span class="badge tone-sky" title="Pictures are blurred except for paying patrons">Blurred · Patrons</span>',
        'blurred-eligible' => ' <span class="badge tone-sky" title="Pictures are blurred except for paying patrons who meet its requirements">Blurred · Eligible</span>',
        default    => '',
    };
}

/** Batch changes on the Models / Accessories list (admin.js → [data-batch]): quick picks above the table, and the bar for the ticked rows. */
function admin_batch_bar(string $kind): string
{
    $opt = fn(array $list) => implode('', array_map(fn($k, $v) => '<option value="' . e($k) . '">' . e(is_array($v) ? $v[0] : $v) . '</option>', array_keys($list), $list));
    return '<div class="batch" data-batch="' . e($kind) . '">'
        . '<div class="batch-pick"><span class="batch-lbl">Select</span>'
        . '<button type="button" class="chip" data-pick="all" aria-pressed="false">All <span data-pick-n="all"></span></button>'
        . '<button type="button" class="chip" data-pick="draft" aria-pressed="false">Drafts <span data-pick-n="draft"></span></button>'
        . '<button type="button" class="chip" data-pick="none" aria-pressed="false">None</button>'
        . '<label class="batch-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
        . '<input class="input" type="search" data-batch-search placeholder="Search ' . m3d_kind_word($kind, true) . '…" aria-label="Search ' . m3d_kind_word($kind, true) . '" autocomplete="off" spellcheck="false" /></label></div>'
        . '<p class="muted small batch-empty" data-batch-empty hidden></p>'
        . '<div class="batch-bar" data-batch-bar hidden><b data-batch-count></b>'
        . ($kind === 'models' ? '<select class="input" data-batch-op="status" aria-label="Set status"><option value="">Set Status…</option>' . $opt(M3D_STATUSES) . '</select>' : '')
        . '<select class="input" data-batch-op="visibility" aria-label="Set visibility"><option value="">Set Visibility…</option>' . $opt(M3D_VISIBILITY) . '</select>'
        . '<button type="button" class="btn btn-danger btn-sm" data-batch-delete>Delete</button>'
        . '<button type="button" class="btn btn-ghost btn-sm" data-pick="none">Clear</button></div>'
        . '</div>';
}

/** Words a row of the Models / Accessories lists is found by (the search box of admin_batch_bar()), lower case. */
function admin_search_text(array $parts): string
{
    return mb_strtolower(implode(' ', array_filter(array_map(fn($p) => trim((string) $p), $parts), fn($p) => $p !== '')));
}

/** Download count cell for the admin lists: total, with patrons / free in the tooltip. */
function admin_dl_count(string $kind, string $slug, array $stats): string
{
    $d = m3d_downloads($kind, $slug, $stats);
    if (!$d['total']) return '<span class="muted">0</span>';
    return '<span class="num" title="' . $d['patrons'] . ' by patrons · ' . $d['public'] . ' free">' . number_format($d['total']) . '</span>'
        . ($d['public'] ? '<div class="muted">' . number_format($d['public']) . ' free</div>' : '');
}

/**
 * The revision of a stored model / accessory: the editor gets it with the item and sends it back with a save, which is refused when
 * the item changed meanwhile (another tab, an Images job, Publish). The list order is left out: dragging the list doesn't touch the editor's fields.
 */
function admin_item_rev(array $item): string
{
    unset($item['order']);
    return md5((string) json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** The revision of the stored docs or FAQ (m3d_docs() / m3d_faq() fresh): their editor sends it back, so a save from a tab opened
 *  before another save or an Images job (which rewrites picture addresses) is refused instead of putting old data back. */
function admin_text_rev(string $kind): string
{
    $d = $kind === 'docs' ? m3d_docs(true) : m3d_faq(true);
    return md5((string) json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Deleted models / accessories ($gone = slug => name): their all-time counters and likes go, and in the download log their
 * address becomes "<name> (removed <date>)" (never a valid address), so a new item made later at the same address starts
 * from zero and isn't "unlocked" already, while those downloads still use up unlocks in the rolling limits and My account
 * keeps the name. Their bug reports keep the name but no longer point at the address (unpinned; hidden when the item was a
 * draft, password or not yet released one: $vis = slug => visibility, 'draft' for those), and their Discord codes go, so a
 * new item there is announced.
 */
function admin_forget_items(string $kind, array $gone, array $vis = []): void
{
    if (!$gone) return;
    $slugs = array_map('strval', array_keys($gone));
    if (is_file(M3D_PRIVATE . '/data/bugs.json')) {
        m3d_bugs_update(function (array $bugs, int $next) use ($kind, $gone, $vis) {
            foreach ($bugs as &$b) {
                [$k, $slug] = m3d_bug_about_parts((string) ($b['about'] ?? ''));
                if ($k !== $kind || !isset($gone[$slug])) continue;
                $b['about'] = 'other';
                $b['gone'] = $kind;   // shows "<name> (removed)"
                $b['about_name'] = (string) (($b['about_name'] ?? '') ?: $gone[$slug]);
                unset($b['pin']);
                if (in_array($vis[$slug] ?? 'public', ['draft', 'password'], true)) $b['hidden'] = true;
            }
            unset($b);
            return [$bugs, $next, null];
        });
    }
    m3d_blog_forget_items($kind, $slugs);   // posts showing them leave them out
    m3d_comments_forget($kind, $slugs);
    m3d_ideas_forget($kind, $slugs);
    m3d_showcase_forget($kind, $slugs);   // patrons' renders of them (and their stills in the Gallery)
    m3d_shares_forget($kind, $slugs);     // their temporary links end (a new item at that address doesn't take them over)
    $dc = m3d_settings(true)['discord'] ?? null;
    if (is_array($dc)) {
        $mine = fn($c) => is_string($c) && in_array(preg_replace('/@.*$/s', '', $c), array_map(fn($s) => "$kind:$s", $slugs), true);
        $keep = fn($l) => array_values(array_filter((array) ($dc[$l] ?? []), fn($c) => !$mine($c)));
        if ($keep('sent') !== array_values((array) ($dc['sent'] ?? [])) || $keep('retry') !== array_values((array) ($dc['retry'] ?? []))) {
            m3d_save_settings(['discord' => ['sent' => $keep('sent'), 'retry' => $keep('retry')] + $dc]);
        }
    }
    m3d_json_update('stats', function ($d) use ($kind, $slugs) {
        if (!is_array($d)) return [$d, null];   // no file yet: the counters are worked out from the log, rewritten below
        foreach ($slugs as $s) unset($d['downloads'][$kind][$s]);
        foreach (array_keys($d['downloads']['pics'] ?? []) as $key) if (in_array(explode(':', (string) $key)[1] ?? '', $slugs, true) && str_starts_with((string) $key, "$kind:")) unset($d['downloads']['pics'][$key]);   // its pictures' full size downloads
        return [$d, null];
    });
    m3d_json_update('likes', function ($d) use ($kind, $slugs) {
        if (!is_array($d)) return [$d, null];
        foreach ($slugs as $s) unset($d[$kind][$s]);
        return [$d, null];
    });
    m3d_ignored_forget($kind, $slugs);
    if (!is_file(m3d_log_file())) return;
    $pre = m3d_log_item($kind, '');   // "outfit:", "scene:", '' for models
    $date = m3d_today();
    $clean = fn($n) => trim((string) preg_replace('/\s+/u', ' ', (string) $n));
    $same = array_count_values(array_map($clean, $gone));
    // items already in the log: a model of the same name deleted earlier today (major versions share a name) keeps its own history
    $taken = [];
    foreach (m3d_log_rows('(removed ') as $r) $taken[$r[3] ?? ''] = true;
    $map = [];   // log item => new log item
    foreach ($gone as $slug => $name) {
        $name = $clean($name) ?: (string) $slug;
        $new = $pre . (($same[$name] ?? 0) > 1 ? "$name · $slug" : $name) . " (removed $date)";
        if (isset($taken[$new])) $new = $pre . "$name · $slug (removed $date)";
        if ($pre === '' && preg_match('/^(outfit|scene):/', $new)) $new = 'Model ' . $new;   // would read as an accessory / a scene
        $map[m3d_log_item($kind, (string) $slug)] = $new;
    }
    m3d_log_rename_items($map);
}

// ── Folder pickers (Images page, Admin → Gallery → Add pictures) ─────────────
/** All images in media/ (large versions only; the "-sm", "-md", "-xs" files are their other versions, img_versions()). */
/** Every file in media/: address ("/media/<folder>/<name>") => its SplFileInfo. */
function media_walk(): array
{
    $root = M3D_SITE . '/media';
    if (!is_dir($root)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile()) $out['/media/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1))] = $f;
    return $out;
}

function media_library(): array
{
    $items = [];
    $walk = media_walk();
    $videos = [];   // a video's cover (name.mp4 → name.webp) belongs to the video: listed, deleted and kept with it (Media → Videos)
    foreach ($walk as $url => $f) if (m3d_is_video_url($url)) $videos[substr($url, 0, strrpos($url, '.'))] = true;
    foreach ($walk as $url => $f) {
        if (!preg_match('/\.(webp|jpe?g|png|gif)$/i', $f->getFilename())) continue;
        if (img_large($url) !== $url) continue;   // another version of a picture: listed with it
        if (isset($videos[substr($url, 0, strrpos($url, '.'))])) continue;   // a video's cover
        if (str_starts_with($url, '/media/site/')) continue;   // the site logo and icons (Settings → Site identity)
        if (str_starts_with($url, '/media/bugs/')) continue;   // pictures of bug reports (Admin → Bugs)
        if (str_starts_with($url, '/media/showcase/')) continue;   // renders patrons shared (Media → From Patrons)
        $small = img_small($url);
        $versions = img_versions($url);
        $dim = img_dims($url) ?: [0, 0];
        $items[] = [
            'url'    => $url,
            'small'  => $small,
            'folder' => basename(dirname($url)),
            'name'   => $f->getFilename(),
            'versions' => $versions,
            'bytes'  => $f->getSize() + array_sum(array_map(fn($v) => (int) @filesize((string) media_path($v)), $versions)),
            'w'      => $dim[0], 'h' => $dim[1],
            'time'   => $f->getMTime(),
        ];
    }
    usort($items, fn($a, $b) => [$a['folder'], $b['time']] <=> [$b['folder'], $a['time']]);
    return $items;
}

/** The 3D preview files in media/ (.glb, app/model3d.php): the Images page's "3D Files" tab. Kept apart from media_library(), which the picture picker uses too. */
function media_3d_files(): array
{
    $items = [];
    foreach (media_walk() as $url => $f) {
        if (strtolower($f->getExtension()) !== 'glb') continue;
        if (!m3d_glb_path($url) || preg_match('#^/media/(site|bugs|showcase)/#', $url)) continue;
        $items[] = ['url' => $url, 'folder' => basename(dirname($url)), 'name' => $f->getFilename(), 'bytes' => $f->getSize(), 'time' => $f->getMTime()];
    }
    return $items;
}

/** The videos in media/ (app/video.php): Media → Videos. Their covers are counted with them (bytes) and left out of media_library(). */
function media_videos(): array
{
    $items = [];
    foreach (media_walk() as $url => $f) {
        if (!m3d_video_path($url)) continue;
        $cover = m3d_video_cover($url);
        $coverBytes = array_sum(array_map(fn($u) => (int) @filesize((string) media_path($u)), m3d_video_cover_files($url)));
        $items[] = ['url' => $url, 'folder' => basename(dirname($url)), 'name' => $f->getFilename(), 'bytes' => $f->getSize() + $coverBytes, 'time' => $f->getMTime(),
            'cover' => $cover, 'info' => m3d_video_info($f->getPathname())];
    }
    return $items;
}

/** Deletes a video and its cover: the bytes freed, null when it isn't a video file of this site. */
function media_video_delete(string $url): ?int
{
    $p = m3d_video_path($url);
    if (!$p) return null;
    $freed = 0;
    foreach (m3d_video_cover_files($url) as $c) if (($f = media_path($c)) && is_file($f)) { $size = (int) filesize($f); if (@unlink($f)) $freed += $size; }
    $size = (int) filesize($p);
    return @unlink($p) ? $freed + $size : $freed;
}

/** An item's name in the admin's lists and menus: "Alice (Gen 4)", "Summer dress (outfit)". */
function admin_item_label(string $kind, array $it): string
{
    if ($kind === 'outfits') return $it['name'] . ' (' . mb_strtolower(m3d_accessory_type($it)) . ')';
    if ($kind === 'scenes') return $it['name'] . ' (scene)';
    return $it['name'] . (($it['generation'] ?? '') !== '' ? ' (Gen ' . $it['generation'] . ')' : '');
}

// ── Where pictures are used, and the unused ones (Admin → Images, Settings → Tools) ──
/** The pictures stored on this site that a data value uses: the value itself when it is an address, else the pictures written in
 *  it as text (![caption](/media/…)) and the links to them ([reference sheet](/media/…)). */
function media_in(mixed $v): array
{
    if (!is_string($v) || !str_contains($v, '/media/')) return [];
    $self = rtrim(abs_url('/'), '/');   // a full address of this site ("https://<site>/media/…") is a picture of this site too
    if (str_starts_with($v, $self . '/media/')) $v = substr($v, strlen($self));
    if (str_starts_with($v, '/media/')) return [$v];
    return preg_match_all('/\]\((?:' . preg_quote($self, '/') . ')?(\/media\/[^\s)]+)\)/', $v, $mm) ? $mm[1] : [];
}

/** The file of a library picture (or 3D preview file, .glb, or video) that may be deleted, or null: never the site logo / icons (media/site/), bug report
 *  pictures (media/bugs/) or patrons' renders (media/showcase/: they go with their render, app/showcase.php). */
function media_deletable(string $url): ?string
{
    if (!preg_match('/\.(webp|jpe?g|png|gif|glb|mp4|webm)$/i', $url) || preg_match('#^/media/(site|bugs|showcase)/#', $url)) return null;
    $p = media_path($url);
    return $p && is_file($p) ? $p : null;
}

/** url => [ editKey => name, ... ] for every image (and 3D file: `model3d`) address used by a model, an outfit, a FAQ answer, a docs page, a blog post or the gallery. */
function usage_map(array $models, ?array $outfits = null): array
{
    $use = [];
    foreach ($models as $m) {
        array_walk_recursive($m, function ($v) use (&$use, $m) {
            foreach (media_in($v) as $u) $use[$u]['models:' . $m['slug']] = admin_item_label('models', $m);
        });
    }
    foreach (m3d_texts() as $t) {   // FAQ answers, docs pages, blog posts
        foreach (media_in($t['text']) as $u) $use[$u][$t['key']] = $t['name'];
    }
    foreach (m3d_blog()['posts'] as $p) {   // covers and the pictures of poll choices
        foreach ([(string) ($p['cover'] ?? ''), ...array_map(fn($o) => (string) ($o['pic'] ?? ''), (array) ($p['poll']['opts'] ?? []))] as $src)
            foreach (media_in($src) as $u) $use[$u]['blog:' . $p['id']] = 'Blog: ' . $p['title'];
    }
    foreach ($outfits ?? m3d_outfits() as $o) {
        array_walk_recursive($o, function ($v) use (&$use, $o) {
            foreach (media_in($v) as $u) $use[$u]['outfits:' . $o['slug']] = admin_item_label('outfits', $o);
        });
    }
    foreach (m3d_items('scenes') as $o) {
        array_walk_recursive($o, function ($v) use (&$use, $o) {
            foreach (media_in($v) as $u) $use[$u]['scenes:' . $o['slug']] = admin_item_label('scenes', $o);
        });
    }
    if (is_file(M3D_PRIVATE . '/data/tools.json')) foreach (m3d_tools()['tools'] as $t) {   // tool pages and their tutorials (app/tools.php)
        array_walk_recursive($t, function ($v) use (&$use, $t) {
            foreach (media_in($v) as $u) $use[$u]['other:tool-' . $t['id']] = 'Tool: ' . $t['name'];
        });
    }
    foreach (media_in(outfits_intro()) as $u) $use[$u]['other:outfits-page'] = 'Accessories Page Text';
    foreach (media_in(scenes_intro()) as $u) $use[$u]['other:scenes-page'] = 'Scenes Page Text';
    foreach (media_in(m3d_welcome()['text']) as $u) $use[$u]['other:welcome'] = 'Welcome Page Text';
    foreach (m3d_gallery()['stills'] as $st) {
        $who = m3d_still_name($st, '');
        $use[$st['src']]['gallery:' . $st['id']] = 'Gallery' . ($who !== '' ? ' (' . $who . ')' : '');
    }
    return $use;
}

/** $models (any data) with the picture addresses in $map (old => new) replaced: whole values, and ![caption](old) inside texts. */
function replace_everywhere(array $models, array $map): array
{
    $inText = [];   // pictures written in texts (descriptions, notes…): ![caption](old) → ![caption](new), old = /media/… or another website's address
    foreach ($map as $old => $new) $inText['](' . $old . ')'] = '](' . $new . ')';
    array_walk_recursive($models, function (&$v) use ($map, $inText) {
        if (!is_string($v)) return;
        if (isset($map[$v])) $v = $map[$v];
        elseif (str_contains($v, '](')) $v = strtr($v, $inText);
    });
    return $models;
}

/** Points every use of the pictures in $map (old address => new) to the new one: models, accessories, FAQ / docs texts, blog posts, gallery. */
function media_replace(array $map): bool
{
    $ok = true;
    foreach (M3D_ITEM_KINDS as $kind) {
        $items = m3d_items($kind, true);   // fresh: optimize / outside images ran for a while, edits saved meanwhile stay
        $new = replace_everywhere($items, $map);
        if ($new !== $items) $ok = m3d_save_items($kind, $new) && $ok;
    }
    $ok = m3d_texts_replace($map) && $ok;
    $intro = (string) (m3d_settings(true)['outfits_intro'] ?? '');   // Admin → Accessories → Page text
    if (($new = replace_everywhere([$intro], $map)[0]) !== $intro) $ok = m3d_save_settings(['outfits_intro' => $new]) && $ok;
    $intro = (string) (m3d_settings(true)['scenes_intro'] ?? '');   // Admin → Scenes → Page text
    if (($new = replace_everywhere([$intro], $map)[0]) !== $intro) $ok = m3d_save_settings(['scenes_intro' => $new]) && $ok;
    $welcome = (array) (m3d_settings(true)['welcome'] ?? []);   // Settings → Features → Welcome Page
    $wt = (string) ($welcome['text'] ?? '');
    if (($new = replace_everywhere([$wt], $map)[0]) !== $wt) $ok = m3d_save_settings(['welcome' => ['text' => $new] + $welcome]) && $ok;
    $tools = m3d_tools(true)['tools'];   // tool pages and their tutorials
    if (is_file(M3D_PRIVATE . '/data/tools.json') && ($new = replace_everywhere($tools, $map)) !== $tools) $ok = m3d_save_tools($new) && $ok;
    return m3d_gallery_replace($map) && $ok;
}

/** The admin page where a use (a usage_map() key: 'models:<slug>', 'docs:<slug>', 'faq:<n>'…) is changed. */
function use_href(string $key): string
{
    [$k, $sl] = explode(':', $key, 2) + [1 => ''];
    return match ($k) {
        'faq' => '/admin/faq.php', 'other' => str_starts_with($sl, 'tool-') ? '/admin/tool.php?id=' . rawurlencode(substr($sl, 5)) : ($sl === 'scenes-page' ? '/admin/scenes.php' : '/admin/outfits.php'), 'gallery' => '/admin/gallery.php?still=' . rawurlencode($sl),
        'docs' => '/admin/docs.php?page=' . rawurlencode($sl), 'blog' => '/admin/blog.php?post=' . rawurlencode($sl), default => '/admin/edit.php?kind=' . rawurlencode($k) . '&slug=' . rawurlencode($sl),
    };
}

/** Which part of a model / accessory uses each picture: url => [ 'models:<slug>' => ['Main image', …] ] (the image window's "Used by"). */
function use_fields(array $models, array $outfits): array
{
    $labels = ['thumb' => 'Catalog Image', 'cover' => 'Main Image', 'gallery' => 'Gallery', 'images' => 'Pictures', 'notes' => 'Notes',
               'changelog' => 'Changelog', 'description' => 'Description', 'extra' => 'Sidebar'];
    $out = [];
    foreach (['models' => $models, 'outfits' => $outfits, 'scenes' => m3d_items('scenes')] as $kind => $items) foreach ($items as $it) {
        foreach ($it as $field => $v) {
            $v = [$v];
            array_walk_recursive($v, function ($x) use (&$out, $kind, $it, $field, $labels) {
                foreach (media_in($x) as $u) $out[$u][$kind . ':' . $it['slug']][$labels[$field] ?? 'Text'] = true;
            });
        }
    }
    return $out;
}

/** Images (large file + its versions) that nothing uses: no model, outfit, FAQ answer or docs page points to any of them. */
function unused_images(array $use): array
{
    $any = media_mentioned();
    return array_values(array_filter(media_library(), fn($i) => !array_filter([$i['url'], ...$i['versions']], fn($u) => !empty($use[$u]) || isset($any[$u]))));
}

/** Every /media/… address written anywhere in the site's data, also where usage_map() doesn't look (a bare path in a text,
 *  a Gallery full size link to a file on this site): a picture named there is never "unused", so a clean-up never deletes it. */
function media_mentioned(): array
{
    static $any = null;
    if ($any !== null) return $any;
    $all = json_encode([m3d_models(), m3d_outfits(), m3d_items('scenes'), m3d_faq(), m3d_docs(), m3d_blog(), m3d_gallery(), outfits_intro(), scenes_intro(), m3d_welcome()['text'], m3d_tools()], JSON_UNESCAPED_SLASHES);
    preg_match_all('#/media/[A-Za-z0-9/_.-]+#', (string) $all, $mm);
    $any = [];
    foreach ($mm[0] as $u) { $any[$u] = $any[img_large($u)] = true; foreach (img_versions($u) as $v) $any[$v] = true; }   // a text using only "-sm" keeps the large file too
    return $any;
}

/** 3D preview files (.glb) that no model or accessory uses (left by uploads never saved). */
function unused_3d(array $use): array
{
    return array_values(array_filter(media_3d_files(), fn($g) => empty($use[$g['url']]) && !isset(media_mentioned()[$g['url']])));
}

/** Videos that no text uses (uploaded in a post never saved, or taken out of it). */
function unused_videos(array $use): array
{
    return array_values(array_filter(media_videos(), fn($v) => empty($use[$v['url']]) && !isset(media_mentioned()[$v['url']])));
}

/** "3 images, 1 3D file and 2 videos" (the parts that aren't 0). */
function unused_words(int $images, int $files3d, int $videos = 0): string
{
    $parts = array_values(array_filter([$images ? m3d_plural($images, 'image') : '', $files3d ? m3d_plural($files3d, '3D file') : '', $videos ? m3d_plural($videos, 'video') : '']));
    if (!$parts) return '0 images';
    $last = array_pop($parts);
    return $parts ? implode(', ', $parts) . ' and ' . $last : $last;
}

/** Deletes every unused image (its large file and versions), 3D file and video (with its cover): [images deleted, bytes freed, 3D files deleted,
 *  videos deleted]. Media → "Delete all unused" and Settings → Tools → Delete Unused Media. */
function media_purge_unused(): array
{
    $n = 0; $freed = 0;
    $use = usage_map(m3d_models());
    foreach (unused_images($use) as $i) {
        $gone = false;   // counted per image: its large file and its versions together
        foreach ([$i['url'], ...$i['versions']] as $u) {
            $f = media_deletable($u);
            if ($f) { $size = (int) filesize($f); if (@unlink($f)) { $freed += $size; $gone = true; } }
        }
        if ($gone) $n++;
    }
    $n3 = 0;
    foreach (unused_3d($use) as $g) if (($f = media_deletable($g['url'])) && @unlink($f)) { $freed += $g['bytes']; $n3++; }
    $nv = 0;
    foreach (unused_videos($use) as $v) if (($b = media_video_delete($v['url'])) !== null && !m3d_video_path($v['url'])) { $freed += $b; $nv++; }
    return [$n, $freed, $n3, $nv];
}

/**
 * What the folder menus show for each media folder: [$pick, $names]. $pick: folder => data- attributes for admin.js folderPicker()
 * (kind, name, gen, ver, sub, thumb, icon), in the site's own order (models, accessories, docs pages, FAQ questions);
 * $names: folder (or docs / FAQ use key) => the name in the plain menu.
 */
function admin_folder_picks(array $models): array
{
    $names = $pick = [];
    $picUrl = fn(string $u) => str_starts_with($u, '/media/') ? img_thumb($u) : '';   // only our own pictures, never another website's
    foreach ($models as $m) {
        $names[$m['slug']] = admin_item_label('models', $m);
        $pick[$m['slug']] = ['kind' => 'model', 'name' => $m['name'], 'gen' => (string) ($m['generation'] ?? ''), 'ver' => (string) ($m['majorVersion'] ?? ''), 'sub' => (string) ($m['franchise'] ?? ''), 'thumb' => $picUrl(item_pic('models', $m))];
    }
    foreach (m3d_outfits() as $o) {
        $names['outfit-' . $o['slug']] = admin_item_label('outfits', $o);
        $pick['outfit-' . $o['slug']] = ['kind' => 'outfit', 'name' => $o['name'], 'sub' => m3d_accessory_type($o), 'thumb' => $picUrl(outfit_preview($o)), 'icon' => accessory_icon_paths(m3d_accessory_type($o))];
    }
    foreach (m3d_items('scenes') as $o) {   // scenes: media/scene-<slug>
        $names['scene-' . $o['slug']] = admin_item_label('scenes', $o);
        $pick['scene-' . $o['slug']] = ['kind' => 'scene', 'name' => $o['name'], 'sub' => 'Scene', 'thumb' => $picUrl(outfit_preview($o)), 'icon' => SCENE_ICON];
    }
    $names += ['gallery' => 'Gallery', 'docs' => 'Docs', 'faq' => 'FAQ', 'blog' => 'Blog', 'misc' => 'Other (misc)'];
    foreach (m3d_texts() as $t) {   // docs pages, FAQ questions and blog posts, listed under their docs section / FAQ topic / "Blog"
        $names[$t['key']] = $t['name'];
        $pick[$t['key']] = ['kind' => $t['folder'] === 'faq' ? 'faq' : 'doc', 'name' => $t['title'], 'sub' => $t['section']];
    }
    return [$pick, $names];
}

/** One <option> of a folder picker, with what the search picker needs in data- attributes (folders without a model or accessory: name only). */
function folder_option(string $f, array $pick, array $names, bool $selected = false): string
{
    $p = $pick[$f] ?? ['kind' => 'other', 'name' => $names[$f] ?? $f];
    $attrs = '';
    foreach ($p as $k => $v) if ($v !== '') $attrs .= ' data-' . $k . '="' . e($v) . '"';
    return '<option value="' . e($f) . '"' . $attrs . ($selected ? ' selected' : '') . '>' . e($names[$f] ?? $f) . '</option>';
}

// ── Gallery (admin/gallery.php, save.php kind "gallery") ─────────────────────
/** One still from the editor. The id never changes; src only for a picture stored on this site (a cropped copy). */
function clean_still(array $in, array $old): array
{
    $s = ['id' => $old['id'], 'src' => $old['src']];
    $src = img_large(clean_url($in['src'] ?? ''));   // a cropped copy (Crop… in the edit panel): only a picture stored on this site
    if ($src !== '' && $src !== $old['src']) {
        if (!str_starts_with($src, '/media/') || !($p = media_path($src)) || !is_file($p)) throw new InvalidArgumentException('Only pictures stored on this site can be used.');
        $s['src'] = $src;
    }
    $cap = clean_text($in['caption'] ?? '', 200);
    if ($cap !== '') $s['caption'] = $cap;
    $date = clean_text($in['date'] ?? '', 10);
    if ($date === '') $date = (string) ($old['date'] ?? '');
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])) throw new InvalidArgumentException('The date must be a real day.');
    $s['date'] = $date;
    $chars = [];
    foreach (clean_list($in['chars'] ?? [], 80, 20) as $c) if (!in_array(mb_strtolower($c), array_map('mb_strtolower', $chars), true)) $chars[] = $c;
    $s['chars'] = $chars;
    $v = (string) ($in['visibility'] ?? 'public');
    if (!isset(GALLERY_VISIBILITY[$v])) throw new InvalidArgumentException('Unknown visibility.');
    if ($v !== 'public') $s['visibility'] = $v;
    $link = clean_still_link($in['link'] ?? null);
    if ($link) $s['link'] = $link;
    $scene = clean_text($in['scene'] ?? '', 64);   // the scene it was made in: the viewer's "Download Scene" (still_slide())
    if ($scene !== '' && ($scene === ($old['scene'] ?? '') || m3d_item('scenes', $scene))) $s['scene'] = $scene;
    if (!empty($old['from'])) $s['from'] = (string) $old['from'];   // a patron's render (app/showcase.php): never set from the editor
    return $s;
}

/** A still's full size link {url, access: patrons|public, size?}; [] = none (no address). Also used by the gallery's batch "Set full size link…". */
function clean_still_link(mixed $l): array
{
    $l = is_array($l) ? $l : [];
    $url = clean_dl_url($l['url'] ?? '');
    if ($url === '') return [];
    $link = ['url' => $url, 'access' => ($l['access'] ?? '') === 'public' ? 'public' : 'patrons'];
    $size = clean_text($l['size'] ?? '', 40);
    if ($size !== '') $link['size'] = $size;
    return $link;
}

/** Everything Admin → Gallery shows: the stills (with their pictures' sizes and characters), the characters, the models to pick. */
function admin_gallery_payload(): array
{
    $g = m3d_gallery(true);
    $stats = m3d_stats();
    $stills = [];
    foreach ($g['stills'] as $s) {
        [$w, $h] = img_dims((string) $s['src']) ?: [0, 0];
        $stills[] = $s + ['small' => img_small((string) $s['src']), 'w' => $w, 'h' => $h, 'dls' => m3d_downloads('stills', $s['id'], $stats)['total'], 'likes' => m3d_like_count((string) $s['id'], 'stills'),
            'rhidden' => !empty($s['from']) && m3d_render_hidden((string) $s['from']),   // a patron render hidden in From Patrons: its copy is hidden too
            'who' => array_map(fn($c) => ['key' => (string) $c['key'], 'name' => $c['name'], 'model' => $c['model'] ? $c['model']['slug'] : null, 'vis' => $c['model'] ? (m3d_release_hidden($c['model']) && m3d_visibility($c['model']) !== 'draft' ? 'soon' : m3d_visibility($c['model'])) : ''], m3d_still_chars($s))];   // soon: hidden until its release date, like a draft
    }
    $chars = [];
    foreach (m3d_gallery_chars($g['stills']) as $k => $c) {
        $chars[] = ['key' => (string) $k, 'name' => $c['name'], 'franchise' => $c['franchise'], 'model' => $c['model'] ? $c['model']['slug'] : null,
            'gen' => $c['model'] ? (string) ($c['model']['generation'] ?? '') : '', 'thumb' => $c['model'] ? img_thumb(item_pic('models', $c['model'])) : '',
            'count' => $c['count'], 'latest' => $c['latest']];
    }
    $models = array_map(fn($m) => ['slug' => $m['slug'], 'name' => $m['name'], 'gen' => (string) ($m['generation'] ?? ''), 'franchise' => (string) ($m['franchise'] ?? ''),
        'thumb' => img_thumb(item_pic('models', $m)), 'vis' => m3d_visibility($m)], m3d_models());
    // the scenes a picture can link to; count = its pictures (the filter picker lists the ones that have some)
    $perScene = array_count_values(array_map(fn($s) => (string) ($s['scene'] ?? ''), $g['stills']));
    $scenes = array_map(fn($x) => ['slug' => (string) $x['slug'], 'name' => (string) $x['name'], 'thumb' => img_thumb(outfit_preview($x)), 'count' => $perScene[(string) $x['slug']] ?? 0,
        'models' => array_values(array_filter(array_map('strval', (array) ($x['models'] ?? [])), fn($slug) => m3d_item('models', $slug) !== null))], m3d_items('scenes'));
    return ['stills' => $stills, 'chars' => $chars, 'models' => $models, 'scenes' => $scenes, 'visibility' => GALLERY_VISIBILITY, 'today' => m3d_today(),
        's4' => m3d_s4()['bucket'] !== '' && m3d_s4_on()];   // full size links may be "s4:<file>" (the S4… picker)
}

/**
 * Settings tabs (settings.php?tab=…): key => [label, its sections' ids], in the order shown. Update (update.php) is the last tab.
 * Section ids that aren't on this site yet are harmless: they only send old #addresses to the right tab (admin.js).
 */
const SETTINGS_TABS = [
    'site' => ['Site', ['identity', 'social', 'menu', 'homepage', 'notice', 'timezone']],
    'features' => ['Features', ['features-recap', 'blog', 'bugs', 'comments', 'ideas', 'showcase', 'discord', 'welcome', 's4']],
    'patreon' => ['Patreon', ['campaign', 'logins', 'avatars', 'patreon']],
    'notify' => ['Notifications', ['notify']],
    'maintenance' => ['Maintenance', ['maintenance', 'problems', 'tools', 'system']],
    'privacy' => ['Privacy', ['legal', 'personal-data']],   // last, after Update: rarely used
];

/** The Settings tab asked for in the address (Site when none). */
function settings_tab(): string
{
    $t = m3d_get_text('tab');
    return isset(SETTINGS_TABS[$t]) ? $t : 'site';
}

/** The Posting group of the Bug Reports and Comments boxes, the same in both ($p = the fields' prefix, $s = the feature's settings):
 *  who can post, how many a day, Check Before Showing and Skip for Paying Patrons (greyed while the check is off, CSS). */
function posting_group(string $p, string $perDay, int $max, array $s): string
{
    $sw = fn(string $name, bool $on, string $label, string $cls = 'toggle') => '<label class="' . $cls . '"><input type="checkbox" class="switch" name="' . $p . '_' . $name . '" value="1"' . ($on ? ' checked' : '') . ' /><span>' . $label . '</span></label>';
    $who = '';
    foreach (['any' => 'Any Account', 'paying' => 'Paying Patrons'] as $v => $label)
        $who .= '<label class="vis-opt"><input type="radio" name="' . $p . '_who" value="' . $v . '"' . ($s['who'] === $v ? ' checked' : '') . ' />' . $label . '</label>';
    return '<div class="set-grp"><h3>Posting</h3><div class="field"><span class="lbl">Who Can Post</span><div class="vis-seg bugs-who">' . $who . '</div></div>'
        . '<label class="field bugs-num"><span class="lbl">' . e($perDay) . '</span><input class="input" type="number" name="' . $p . '_per_day" min="1" max="' . $max . '" value="' . (int) $s['per_day'] . '" /></label>'
        . $sw('moderate', $s['moderate'], 'Check Before Showing') . $sw('paid_skip', $s['paid_skip'], 'Skip for Paying Patrons', 'toggle set-sub') . '</div>';
}

/** The Emoji group of the Bug Reports and Comments boxes ($p = the fields' prefix). */
function emoji_group(string $p, array $s): string
{
    $h = '<div class="set-grp"><h3>Emoji</h3>';
    foreach (['emoji' => 'Emoji Button', 'codes' => 'Emoji Codes (:heart:)', 'reactions' => 'Reactions'] as $k => $label)
        $h .= '<label class="toggle"><input type="checkbox" class="switch" name="' . $p . '_' . $k . '" value="1"' . ($s[$k] ? ' checked' : '') . ' /><span>' . $label . '</span></label>';
    return $h . '</div>';
}

/** The Notifications group of the Bug Reports and Comments boxes: where each event goes, and a link to change it. */
function notify_group(string ...$events): string
{
    $s = m3d_notify_settings();
    $icons = ['bell' => ['Bell', '<svg viewBox="0 0 24 24" aria-hidden="true">' . NOTIFY_ICONS['bell'] . '</svg>'], 'discord' => ['Discord', social_icon('discord')]];
    $h = '<div class="set-grp nt-grp"><h3>Notifications</h3><ul class="nt-grp-list">';
    foreach ($events as $ev) {
        $h .= '<li><span>' . e(NOTIFY_EVENTS[$ev]) . '</span>';
        foreach ($icons as $to => [$label, $svg]) {
            $on = !empty($s[$to][$ev]);
            $h .= '<span class="ntg-ico ntg-' . $to . ($on ? ' is-on' : '') . '" title="' . $label . ': ' . ($on ? 'On' : 'Off') . '">' . $svg . '<span class="sr-only">' . $label . ' ' . ($on ? 'on' : 'off') . '</span></span>';
        }
        $h .= '</li>';
    }
    return $h . '</ul><a class="btn btn-ghost btn-sm" href="' . e(settings_url('notify')) . '">Change in Notifications</a></div>';
}

/** Address of a Settings section: its tab, scrolled to the section (e.g. settings_url('maintenance')). */
function settings_url(string $section): string
{
    foreach (SETTINGS_TABS as $k => [, $ids]) if (in_array($section, $ids, true)) return '/admin/settings.php?tab=' . $k . '#' . $section;
    return '/admin/settings.php';
}

/** Tabs of the Assets page (the "Assets" menu item): Models · Accessories (· Scenes), in the .lib-tabs look of Settings, each with its count. */
function assets_menu(string $current): string
{
    $tabs = ['models' => ['/admin/models.php', 'Models', count(m3d_items('models'))], 'outfits' => ['/admin/outfits.php', 'Accessories', count(m3d_items('outfits'))]];
    $tabs['scenes'] = ['/admin/scenes.php', 'Scenes', count(m3d_items('scenes'))];
    $tabs['tools'] = ['/admin/tools.php', 'Pages', count(m3d_tools()['tools'])];   // tool and simple pages (app/tools.php)
    $h = '<nav class="lib-tabs set-tabs" aria-label="Assets">';
    foreach ($tabs as $k => [$href, $label, $n]) $h .= '<a href="' . $href . '"' . ($current === $k ? ' aria-current="page"' : '') . '>' . $label . '<span>' . $n . '</span></a>';
    $dead = count(lc_dead());   // from the last check: Link check never runs by itself
    return $h . '<a href="/admin/links.php"' . ($current === 'links' ? ' aria-current="page"' : '') . '>Link Check' . ($dead ? '<span class="lc-tab-dead">' . $dead . ' dead</span>' : '') . '</a></nav>';
}

/** Tabs at the top of Settings and of the Update page ($current: a tab key, or 'update'); Update sits before Privacy, the last tab. */
function settings_menu(string $current = ''): string
{
    $map = [];
    foreach (SETTINGS_TABS as $k => [, $ids]) foreach ($ids as $id) $map[$id] = $k;
    $h = '<nav class="lib-tabs set-tabs" aria-label="Settings" data-set-tabs="' . e(json_encode($map)) . '">';
    foreach (SETTINGS_TABS as $k => [$label]) {
        if ($k === 'privacy') $h .= '<a href="/admin/backups.php"' . ($current === 'backups' ? ' aria-current="page"' : '') . '>Backups</a>'   // own pages, shown as tabs
            . '<a href="/admin/update.php"' . ($current === 'update' ? ' aria-current="page"' : '') . '>Update</a>';
        $h .= '<a href="/admin/settings.php' . ($k === 'site' ? '' : '?tab=' . $k) . '"' . ($current === $k ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return $h . '</nav>'
        . '<a class="to-top" href="#main" data-to-top hidden aria-label="Back to the top" title="Back to the top"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 19V5m0 0-6 6m6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
}

/**
 * Weekly safety net (bk_auto_run()): opening an admin page makes the automatic backup when it is due and no public page has made it
 * yet (they do after the page is sent, m3d_backup_tick()). Called once per request: Home and Backups call it before they read the
 * backups, admin_start() again for its message. Returns the new file name.
 */
function admin_auto_backup(): ?string
{
    static $done = false, $name = null;
    if ($done) return $name;
    $done = true;
    $f = m3d_backup_next_file();
    if (is_file($f) && (int) @file_get_contents($f) > time()) return null;
    require_once __DIR__ . '/_backup.php';
    return $name = bk_auto_run();
}

/** A one-time message after an action ("✓ Saved", "Not saved: …"), shown as a floating message with a ✕ by admin_end()
 *  instead of a box that pushes the page down: ok / info fade out by themselves (admin.js), err stays until closed.
 *  $html is already escaped. */
function admin_flash(string $html, string $kind = 'ok'): void
{
    site_flash($html, $kind);
}

/** The usual pair of a page's result messages: $done = what was done, $error = why not, after $label. */
function admin_result($done, $error, string $label = 'Not saved:'): void
{
    if ($done) admin_flash('✓ ' . e((string) $done));
    if ($error) admin_flash('<b>' . e($label) . '</b> ' . e((string) $error), 'err');
}

function admin_start(string $title, string $active = ''): void
{
    // a moderator (only admin/moderation.php lets one through) gets the "Mustard3D Mod" header: the Moderation page, their name, View Site
    $mod = !m3d_is_admin($GLOBALS['M3D']);
    $autoBackup = !$mod && admin_auto_backup();
    if (!$mod) {
        m3d_discord_stills_tick();   // new Gallery pictures waiting for their Discord post (also while only the admin is on the site)
        m3d_blog_discord_tick();   // a blog post went out: its Discord post
        lc_auto_tick();   // Link check's automatic check, when on
        m3d_tiers_tick();   // the campaign's tiers read from Patreon once a day
    }
    $waiting = $mod || m3d_mod_kinds() || $active === 'moderation' ? m3d_mod_waiting_count() : null;   // the Moderation menu item's number
    $nav = fn(string $label, string $href, bool $on, ?int $n = null) => '        <a href="' . $href . '"' . ($on ? ' aria-current="page"' : '') . '>' . $label . ($n ? '<span class="nav-n">' . $n . '</span>' : '') . "</a>\n";
    m3d_session_release();   // the page only reads the login from here: a slow page (big lists, the Images library) holds up nothing else
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="csrf" content="<?= e(m3d_csrf()) ?>" />
  <title><?= e($title) ?> · <?= e(site_name()) ?> <?= $mod ? 'Mod' : 'Admin' ?></title>
<?= head_identity() ?>  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset('admin.css')) ?>" />
<?= accent_style() ?></head>
<body class="admin">
  <header class="site-header">
    <div class="wrap header-inner">
<?php if ($mod): $me = m3d_patron($GLOBALS['M3D']) ?? []; ?>
      <a class="brand" href="/admin/moderation.php"><?= brand() ?><span class="admin-tag is-mod">Mod</span></a>
      <nav class="nav admin-nav" aria-label="Moderation">
<?= $nav('Moderation', '/admin/moderation.php', true, $waiting) ?>      </nav>
      <div class="account">
        <span class="mod-me"><?= avatar_html((string) ($me['id'] ?? ''), (string) ($me['name'] ?? ''), false, 'ava-sm') ?><span><?= e((string) ($me['name'] ?? '')) ?></span></span>
        <a class="btn btn-ghost btn-sm" href="/">View Site</a>
      </div>
    </div>
<?php else: ?>
      <a class="brand" href="/admin/"><?= brand() ?><span class="admin-tag">Admin</span></a>
      <nav class="nav admin-nav" aria-label="Admin">
        <a href="/admin/models.php" <?= in_array($active, [...M3D_ITEM_KINDS, 'tools'], true) ? 'aria-current="page"' : '' ?>>Assets</a>
        <a href="/admin/gallery.php" <?= $active === 'gallery' ? 'aria-current="page"' : '' ?>>Gallery</a>
<?php if (m3d_blog_on() || $active === 'blog'): ?>        <a href="/admin/blog.php" <?= $active === 'blog' ? 'aria-current="page"' : '' ?>>Blog</a>
<?php endif ?>        <a href="/admin/docs.php" <?= $active === 'docs' ? 'aria-current="page"' : '' ?>>Docs</a>
        <a href="/admin/faq.php" <?= $active === 'faq' ? 'aria-current="page"' : '' ?>>FAQ</a>
        <a href="/admin/users.php" <?= $active === 'users' ? 'aria-current="page"' : '' ?>>Access</a>
<?php if ($waiting !== null) echo $nav('Moderation', '/admin/moderation.php', $active === 'moderation', $waiting) ?>        <a href="/admin/images.php" <?= $active === 'images' ? 'aria-current="page"' : '' ?>>Media</a>
        <a href="/admin/stats.php" <?= $active === 'stats' ? 'aria-current="page"' : '' ?>>Stats</a>
        <a href="/admin/settings.php" <?= in_array($active, ['settings', 'update', 'backups'], true) ? 'aria-current="page"' : '' ?>>Settings</a>
      </nav>
<?php $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' ?>
      <div class="hsearch" data-jump><?php /* "Jump to…": the public header's search bar (header_search() in view.php), filled by admin.js */ ?>
        <button type="button" class="hsearch-btn" title="Jump to a model, accessory, page or setting (Ctrl+K)" aria-label="Jump to… (Ctrl+K)" aria-expanded="false" data-jump-open><?= $icon ?></button>
        <div class="hsearch-panel" data-jump-panel hidden><div class="hs-bar" role="search"><label class="hs-field"><?= $icon ?><input type="search" placeholder="Jump to a model, accessory, page or setting…" aria-label="Jump to" autocomplete="off" spellcheck="false" enterkeyhint="go" role="combobox" aria-expanded="false" aria-controls="jump-list" aria-autocomplete="list" data-jump-input /></label><button type="button" class="hsearch-btn hs-close" aria-label="Close" title="Close (Esc)" data-jump-close><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div></div>
        <ul class="hsearch-list" id="jump-list" role="listbox" aria-label="Results" data-jump-list></ul>
      </div>
<?= quick_add() ?><?= notify_bell() ?>      <div class="account">
        <form method="post" action="/admin/patron-preview.php" class="pv-start" title="See the site as a patron: pick the membership, months and total support in the bar on top. Nothing is downloaded or logged.">
          <?= form_keys() ?><input type="hidden" name="return" value="/" />
          <?php if (!m3d_preview()): ?><input type="hidden" name="tier" value="ok" /><input type="hidden" name="months" value="1" /><input type="hidden" name="total" value="5" /><?php endif ?>
          <button class="btn btn-sm btn-preview" name="do" value="apply"><?= m3d_preview() ? 'Back to Preview' : 'Preview' ?></button>
        </form>
        <a class="btn btn-ghost btn-sm" href="/">View Site</a>
      </div>
    </div>
<?php endif ?>
  </header>
  <main id="main" class="wrap admin-main">
<?php if ($autoBackup) admin_flash('✓ Weekly backup made. <a href="/admin/backups.php">See Backups</a>') ?>
<?php if (m3d_maintenance() && !$mod): ?>
    <p class="notice maint-notice"><b>Maintenance mode is on:</b> visitors see the “Back Soon” page; only admins can use the site. <a href="<?= settings_url('maintenance') ?>">Turn it off in Settings</a></p>
<?php endif ?>
<?php
}

function admin_end(): void
{
    ?>
  </main>
<?= flash_stack() ?>  <script src="<?= e(asset('admin.js')) ?>" data-viewer="<?= e(asset('vendor/model-viewer.min.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** The Comments column of Admin → Models / Accessories: the shown comments (a link to them on the page), the ones waiting, or Off. */
function admin_comments_cell(string $kind, array $it): string
{
    static $n = null;
    $n ??= m3d_comment_admin_counts();
    $c = $n[$kind . ':' . $it['slug']] ?? [];
    [$shown, $wait] = [(int) ($c[0] ?? 0), (int) ($c[1] ?? 0)];
    if (!m3d_comments_open($kind, $it)) {
        return '<span class="muted" title="' . e(!m3d_comments_on($kind) ? 'Comments are off for all ' . (m3d_kind_word($kind, true)) . ' (Settings → Comments)' : 'Allow Comments is off in its editor') . '">Off</span>'
            . ($shown + $wait ? '<div class="muted">' . ($shown + $wait) . ' kept</div>' : '');
    }
    $h = $shown ? '<a class="num cm-num" href="' . e(m3d_item_url($kind, (string) $it['slug'])) . '#comments" title="' . e(m3d_plural($shown, 'comment')) . '">' . bug_icon('comment') . $shown . '</a>' : '<span class="muted">0</span>';
    if ($wait) $h .= '<a class="cm-wait" href="' . e(settings_url('comments')) . '" title="' . e($wait . ' waiting for approval') . '" aria-label="' . e($wait . ' waiting for approval') . '"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></svg>' . $wait . '</a>';   // a clock + the number under the count
    return $h;
}
