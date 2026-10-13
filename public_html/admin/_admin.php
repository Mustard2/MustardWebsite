<?php
// Shared by every admin page: access control, JSON helpers, validation, admin layout.
declare(strict_types=1);
require dirname(__DIR__) . '/app/_init.php';
require_once __DIR__ . '/_links.php';

header('X-Frame-Options: DENY');
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
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** POST endpoints: admin + same CSRF token as the page that sent the request. */
function admin_post_guard(array $cfg): void
{
    admin_require($cfg, true);
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') admin_json(['ok' => false, 'error' => 'POST only'], 405);
    // A file bigger than the server's limit arrives as an empty request: say so instead of "session expired"
    if (str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/') && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        admin_json(['ok' => false, 'error' => 'This file is larger than the server accepts (' . ini_get('post_max_size') . '). Raise "post_max_size" and "upload_max_filesize" in hPanel → PHP Configuration → PHP options, or upload a smaller file.'], 413);
    }
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? null);
    if (!m3d_check_csrf($token)) admin_json(['ok' => false, 'error' => 'Session expired. Reload the page and try again.'], 403);
    m3d_session_release();   // these requests never change the login: other pages stay usable while one works (uploads, imports, Discord)
}

// ── Validation: turns whatever the editor sends into a clean model ───────────
/** A file size for people: "840 KB", "12.4 MB", "1.2 GB". */
function human_size(int $b): string
{
    if ($b <= 0) return '0 KB';
    return $b >= 1073741824 ? sprintf('%.1f GB', $b / 1073741824) : ($b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', max(1, (int) ceil($b / 1024))));
}

/** One line of text from a form: spaces and line breaks squeezed to one space, cut to $max characters. */
function clean_line(mixed $v, int $max): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', is_scalar($v) ? (string) $v : '') ?? ''), 0, $max);
}

/** An address starting with https:// that PHP accepts as an URL (links to other websites in the settings). */
function is_https_url(string $url): bool
{
    return preg_match('#^https://[^\s<>"]+$#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
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
    // drop control characters, keep newlines and the joiners of emoji sequences (👨‍👩‍👧) and scripts that need them
    $s = preg_replace('/[^\P{C}\n\t\x{200C}\x{200D}]/u', '', $s) ?? '';
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
    if ($allowLocal && preg_match('#^/[A-Za-z0-9/_\-.%]+$#', $s) && !str_contains($s, '..')) return $s;
    if (preg_match('#^https?://[^\s"<>]+$#i', $s) && filter_var($s, FILTER_VALIDATE_URL)) return $s;
    throw new InvalidArgumentException("Not a valid link or image address: $s");
}

function check_slug(string $slug): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) throw new InvalidArgumentException('The address (slug) may only contain lowercase letters, numbers and dashes.');
    if ($slug === 'outfit') throw new InvalidArgumentException('"outfit" is reserved, pick another address.');
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

/** The 🔒 badge of the admin lists: an item's extra download requirements in short ("€10+ tier · 6 months"), '' when none. */
function admin_req_badge(array $item): string
{
    $rq = m3d_requirements($item);
    $mt = implode($rq['mode'] === 'or' ? ' or ' : ' · ', array_filter([$rq['months'] ? m3d_plural($rq['months'], 'month') : '', $rq['total_cents'] ? m3d_money($rq['total_cents']) : '']));
    $txt = implode(' · ', array_filter([$rq['tier_cents'] ? m3d_money($rq['tier_cents']) . '+ tier' : '', $rq['tier_cents'] && $rq['mode'] === 'or' && $mt !== '' ? "($mt)" : $mt]));
    return $txt === '' ? '' : ' <span class="badge tone-sky" title="Extra download requirements on top of a paid tier">🔒 ' . e($txt) . '</span>';
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
 * Release dates (editor → Access → Release dates): typed in Berlin time ("2026-10-20T18:00"), stored as UTC ISO dates
 * (patronsFrom, publicFrom) + releaseBefore (soon; absent = hidden before the patrons date). Empty = none.
 */
function clean_release(array $in, array &$out): void
{
    $tz = new DateTimeZone('Europe/Berlin');
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

/** The release dates as the editor's fields want them: Berlin time "2026-10-20T18:00", '' when not set. */
function admin_release_local(array $item): array
{
    $r = m3d_release($item);
    $f = fn(?int $t) => $t === null ? '' : (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d\TH:i');
    return ['patrons' => $f($r['patrons']), 'public' => $f($r['public']), 'before' => $r['before']];
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

/** Download links: [{label, url, access: patrons|public}] */
function clean_links(mixed $v): array
{
    $out = [];
    foreach ((array) $v as $l) {
        if (!is_array($l)) continue;
        $url = clean_url($l['url'] ?? '', false);
        $label = clean_text($l['label'] ?? '', 60);
        if ($url === '' && $label === '') continue;
        if ($url === '') throw new InvalidArgumentException('The download "' . $label . '" has no address.');
        $out[] = ['label' => $label !== '' ? $label : 'Download', 'url' => $url, 'access' => ($l['access'] ?? '') === 'public' ? 'public' : 'patrons'];
        if (count($out) >= 20) break;
    }
    return $out;
}

/** Add-ons needed to use a model/outfit: [{label, url}] (a site page like /mustardui/ or a full https address). */
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
        $out[] = ['label' => $label, 'url' => $url];
        if (count($out) >= 10) break;
    }
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
        $out[] = $cap !== '' ? ['src' => $src, 'caption' => $cap] : $src;
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
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) throw new InvalidArgumentException('The address of "' . $pt . '" may only contain lowercase letters, numbers and dashes.');
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
        if (!preg_match('#^/media/([a-z0-9-]+/)*[a-z0-9-]+\.glb$#', $m3d)) throw new InvalidArgumentException('The 3D preview must be a .glb file uploaded here.');
        $it['model3d'] = $m3d;
        $light = (int) round((float) ($in['model3dLight'] ?? 100));   // the viewer's light, % (app/model3d.php)
        if ($light !== 100) $it['model3dLight'] = max(M3D_LIGHT_RANGE[0], min(M3D_LIGHT_RANGE[1], $light));
    }
    if ($addons = clean_addons($in['addons'] ?? [])) $it['addons'] = $addons;
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
    foreach (['game' => 120, 'note' => 120, 'blender' => 60] as $k => $max) {
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
    $m['status'] = m3d_status($in);
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

// ── "How to format" help: what you write → what you get (rendered by md_html itself, so it never goes stale) ──
const FORMAT_EXAMPLES = [
    'para'    => ['Paragraphs', "First paragraph.\n\nAn empty line starts a new one.\nA single line break stays a line break."],
    'inline'  => ['Bold, Italic, Code, Links', "**bold**, *italic*, `code` and [a link](/docs/)"],
    'heading' => ['Headings', "## Heading\n\n### Smaller heading"],
    'small-h' => ['Small Heading', "### Small heading"],
    'list'    => ['Bullet List', "- First item\n- Second item\n  - indented: a sub-item"],
    'steps'   => ['Numbered Steps', "1. Open Blender\n2. Press N\n  Two spaces continue the step."],
    'box'     => ['Box', "> [!TIP] Faster posing\n> Use the pose library.\n\n> [!WARNING]\n> Save your file first."],
    'table'   => ['Table', "| Generation | Blender |\n|---|---|\n| 4 | 4.2 or newer |"],
    'code'    => ['Code', "```python\nimport bpy\nprint(bpy.app.version)\n```"],
    'image'   => ['Picture', "![Caption](/media/…)"],
    'tabs'    => ['Tabs', "=== Manual\n1. Open the Outliner\n=== With MustardUI\n1. Press N, open MustardUI\n==="],
];

/** Collapsible help: one row per example (label · what you type · how it looks). $docs: headings as on docs pages. */
function format_help(string $title, array $keys, string $intro = '', bool $docs = false): string
{
    $rows = '';
    foreach ($keys as $k) {
        [$label, $src] = FORMAT_EXAMPLES[$k];
        $out = $k === 'image'
            ? '<p class="muted small">On its own line. Several lines together make a gallery.</p>'
            : md_html($src, ['docs' => $docs]);
        $rows .= '<div class="fmt-row"><div class="fmt-label">' . e($label) . '</div><pre class="fmt-src"><code>' . e($src) . '</code></pre><div class="fmt-out md">' . $out . '</div></div>';
    }
    return '<details class="sec format-help"><summary><b>' . e($title) . '</b></summary>'
        . ($intro !== '' ? '<p class="muted small fmt-intro">' . $intro . '</p>' : '')
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
        if ($label === '' || $url === '') throw new InvalidArgumentException('Every link needs a text and an address.');
        $local = preg_match('#^/[A-Za-z0-9/_\-.%?=&\#]*$#', $url) && !str_starts_with($url, '//');
        $web = is_https_url($url);
        if (!$local && !$web) throw new InvalidArgumentException("“{$label}”: the address must be a page of this site (like /faq/) or start with https://");
        $links[] = ['label' => $label, 'url' => mb_substr($url, 0, 500)];
    }
    if (count($links) > $max) throw new InvalidArgumentException("$max links at most.");
    return $links;
}

/** The pages of this site, for the address suggestions of the links editors (<datalist id="site-pages">). */
function admin_site_pages_datalist(): string
{
    $pages = '';
    foreach (m3d_doc_pages(true) as $pg) $pages .= '<option value="' . e($pg['slug'] === m3d_docs_home() ? '/docs/' : '/docs/' . $pg['slug'] . '/') . '">' . e('Docs: ' . ($pg['title'] ?? $pg['slug'])) . '</option>';
    foreach (['/faq/' => 'FAQ', '/accessories/' => 'Accessories'] + (m3d_mustardui_on() ? ['/mustardui/' => 'MustardUI'] : []) + ['/contacts/' => 'Contacts', '/account/' => 'My Account'] as $u => $l) $pages .= '<option value="' . $u . '">' . $l . '</option>';
    return '<datalist id="site-pages">' . $pages . '</datalist>';
}

/** The collapsible editor of the box: links (text + address, drag to reorder) and the line under them. */
function admin_start_box(string $kind, bool $open = false): string
{
    $box = start_box($kind);
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    $row = fn(string $label = '', string $url = '') => admin_link_row('start', $label, $url);
    return '<details class="sec intro-sec" id="start"' . ($open ? ' open' : '') . '>'
        . '<summary><b>Getting Started</b> <span class="muted small">the box under the download button on ' . $what . ' pages</span></summary>'
        . '<form method="post" class="intro-form start-col" data-rows-form>'
        . form_keys('start') . '<input type="hidden" name="kind" value="' . e($kind) . '" />'
        . admin_site_pages_datalist()
        . '<div class="start-rows" data-rows>' . implode('', array_map(fn($l) => $row($l[0], $l[1]), $box['links'])) . '</div>'
        . '<template data-row-template>' . $row() . '</template>'
        . '<div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add Link</button></div>'
        . '<label class="field"><span class="lbl">Note' . tip('Line under the links. Supports **bold**, *italic* and [links](https://…).') . '</span><textarea class="input" name="start_note" rows="2" maxlength="400" placeholder="Empty = no line">' . e($box['note']) . '</textarea></label>'
        . '<label class="toggle"><input type="checkbox" class="switch" name="start_note_on" value="1"' . ($box['note_on'] ? ' checked' : '') . ' /><span>Show Note</span></label>'
        . '<div class="form-actions"><button class="btn btn-primary btn-sm">Save Links</button></div></form>'
        . '</details>';
}

/** Help text behind a "?" (shown on hover, focus or tap by admin.js, like tip() there): keeps explanations out of the way. */
function tip(string $text): string
{
    return '<button type="button" class="tip" data-tip="' . e($text) . '" aria-label="' . e('Help: ' . $text) . '">?</button>';
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

/** What a post looks like in Discord (Settings → Discord announcements): the message line (admin.js keeps it in step with the field) and the embed of the newest version. */
function discord_preview_html(string $message): string
{
    $em = discord_sample_embed();
    $color = isset($em['color']) ? sprintf('#%06x', $em['color']) : '#1e1f22';
    $name = site_name();
    $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('H:i');
    $foot = (string) ($em['footer']['text'] ?? '');
    if (isset($em['timestamp'])) $foot .= ($foot !== '' ? ' • ' : '') . (new DateTimeImmutable($em['timestamp']))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d/m/Y');
    return '<div class="dc-prev" aria-hidden="true"><div class="dc-msg">'
        . '<span class="dc-ava">' . e(mb_strtoupper(mb_substr($name, 0, 1))) . '</span>'
        . '<div class="dc-body"><div class="dc-head"><b class="dc-name">' . e($name) . '</b><span class="dc-app">APP</span><span class="dc-time">Today at ' . e($now) . '</span></div>'
        . '<div class="dc-content" data-dc-content' . (trim($message) === '' ? ' hidden' : '') . '>' . discord_md($message) . '</div>'
        . '<div class="dc-embed" style="--dc-color: ' . e($color) . '">'
        . '<a class="dc-title" href="' . e((string) ($em['url'] ?? '')) . '" target="_blank" rel="noopener" tabindex="-1">' . e((string) ($em['title'] ?? '')) . '</a>'
        . (isset($em['description']) ? '<div class="dc-desc">' . discord_md((string) $em['description']) . '</div>' : '')
        . (isset($em['image']['url']) ? '<img class="dc-img" src="' . e((string) $em['image']['url']) . '" alt="" loading="lazy" />' : '')
        . ($foot !== '' ? '<div class="dc-foot">' . e($foot) . '</div>' : '')
        . '</div></div></div></div>';
}

/** Links column of the Models / Accessories lists: "2 patron / 1 public"; hovering (or focusing) it lists the link names (admin.js, data-tip-links). */
function admin_links_summary(array $links, array $dead = []): string
{
    // $dead = lc_dead_for() [url => …]: those names are red in the hover list ("Mega · dead link"), the text is red and opens Link Check
    // (the row turns red too, .has-dead-link). Dead add-ons get their own group, so an item whose only dead address is an add-on shows it
    $names = ['patrons' => [], 'public' => [], 'addons' => []];
    $seen = [];
    foreach ($links as $l) {
        $url = trim((string) ($l['url'] ?? ''));
        $n = trim((string) ($l['label'] ?? '')) ?: 'Download';
        $names[($l['access'] ?? 'patrons') === 'public' ? 'public' : 'patrons'][] = isset($dead[$url]) ? ['n' => $n, 'dead' => true] : $n;
        $seen[$url] = true;
    }
    foreach ($dead as $url => $d) if (!isset($seen[$url])) $names['addons'][] = ['n' => $d['label'], 'dead' => true];
    [$p, $f] = [count($names['patrons']), count($names['public'])];
    if (!$p && !$f && !$dead) return '<span class="warn-dot" title="No download links">—</span>';
    $txt = ($p ? '<span class="ok-dot">' . $p . ' patron</span>' : '') . ($p && $f ? '<br>' : '') . ($f ? '<span class="muted">' . $f . ' public</span>' : '') . ($p || $f ? '' : '—');
    $label = fn(array $list) => implode(', ', array_map(fn($x) => is_array($x) ? $x['n'] . ' (dead link)' : $x, $list));
    $aria = implode('; ', array_filter([$p ? 'Patrons: ' . $label($names['patrons']) : '', $f ? 'Public: ' . $label($names['public']) : '', $names['addons'] ? 'Add-ons: ' . $label($names['addons']) : '']));
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
        'blurred-patrons' => ' <span class="badge tone-sky" title="Pictures are blurred except for paying patrons">Blurred · Clear for Patrons</span>',
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
        . '<input class="input" type="search" data-batch-search placeholder="Search ' . ($kind === 'models' ? 'models' : 'accessories') . '…" aria-label="Search ' . ($kind === 'models' ? 'models' : 'accessories') . '" autocomplete="off" spellcheck="false" /></label></div>'
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
    if (function_exists('m3d_bugs_update') && is_file(M3D_PRIVATE . '/data/bugs.json')) {
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
        return [$d, null];
    });
    m3d_json_update('likes', function ($d) use ($kind, $slugs) {
        if (!is_array($d)) return [$d, null];
        foreach ($slugs as $s) unset($d[$kind][$s]);
        return [$d, null];
    });
    if (!is_file(m3d_log_file())) return;
    $pre = $kind === 'outfits' ? 'outfit:' : '';
    $date = (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
    $clean = fn($n) => trim((string) preg_replace('/\s+/u', ' ', (string) $n));
    $same = array_count_values(array_map($clean, $gone));
    // items already in the log: a model of the same name deleted earlier today (major versions share a name) keeps its own history
    $taken = [];
    if ($fh = @fopen(m3d_log_file(), 'rb')) {
        while (($line = fgets($fh)) !== false) if (str_contains($line, '(removed ')) $taken[str_getcsv(rtrim($line, "\r\n"), ',', '"', '')[3] ?? ''] = true;
        fclose($fh);
    }
    $map = [];   // log item => new log item
    foreach ($gone as $slug => $name) {
        $name = $clean($name) ?: (string) $slug;
        $new = $pre . (($same[$name] ?? 0) > 1 ? "$name · $slug" : $name) . " (removed $date)";
        if (isset($taken[$new])) $new = $pre . "$name · $slug (removed $date)";
        if ($pre === '' && str_starts_with($new, 'outfit:')) $new = 'Model ' . $new;   // would read as an accessory
        $map[$pre . $slug] = $new;
    }
    m3d_log_rename_items($map);
}

// ── Folder pickers (Images page, Admin → Gallery → Add pictures) ─────────────
/** All images in media/ (large versions only; the "-sm", "-md", "-xs" files are their other versions, img_versions()). */
function media_library(): array
{
    $root = M3D_SITE . '/media';
    if (!is_dir($root)) return [];
    $items = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !preg_match('/\.(webp|jpe?g|png|gif)$/i', $f->getFilename())) continue;
        $url = '/media/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (img_large($url) !== $url) continue;   // another version of a picture: listed with it
        if (str_starts_with($url, '/media/site/')) continue;   // the site logo and icons (Settings → Site identity)
        if (str_starts_with($url, '/media/bugs/')) continue;   // pictures of bug reports (Admin → Bugs)
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
    $root = M3D_SITE . '/media';
    if (!is_dir($root)) return [];
    $items = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'glb') continue;
        $url = '/media/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (!m3d_glb_path($url) || preg_match('#^/media/(site|bugs)/#', $url)) continue;
        $items[] = ['url' => $url, 'folder' => basename(dirname($url)), 'name' => $f->getFilename(), 'bytes' => $f->getSize(), 'time' => $f->getMTime()];
    }
    return $items;
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
        $names[$m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' (Gen ' . $m['generation'] . ')' : '');
        $pick[$m['slug']] = ['kind' => 'model', 'name' => $m['name'], 'gen' => (string) ($m['generation'] ?? ''), 'ver' => (string) ($m['majorVersion'] ?? ''), 'sub' => (string) ($m['franchise'] ?? ''), 'thumb' => $picUrl(item_pic('models', $m))];
    }
    foreach (m3d_outfits() as $o) {
        $names['outfit-' . $o['slug']] = $o['name'] . ' (' . mb_strtolower(m3d_accessory_type($o)) . ')';
        $pick['outfit-' . $o['slug']] = ['kind' => 'outfit', 'name' => $o['name'], 'sub' => m3d_accessory_type($o), 'thumb' => $picUrl(outfit_preview($o)), 'icon' => accessory_icon_paths(m3d_accessory_type($o))];
    }
    $names += ['gallery' => 'Gallery', 'docs' => 'Docs', 'faq' => 'FAQ', 'misc' => 'Other (misc)'];
    foreach (m3d_texts() as $t) {   // docs pages and FAQ questions, listed under their docs section / FAQ topic
        $names[$t['key']] = $t['name'];
        $pick[$t['key']] = ['kind' => $t['folder'] === 'docs' ? 'doc' : 'faq', 'name' => $t['title'], 'sub' => $t['section']];
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
    return $s;
}

/** A still's full size link {url, access: patrons|public, size?}; [] = none (no address). Also used by the gallery's batch "Set full size link…". */
function clean_still_link(mixed $l): array
{
    $l = is_array($l) ? $l : [];
    $url = clean_url($l['url'] ?? '', false);
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
    return ['stills' => $stills, 'chars' => $chars, 'models' => $models, 'visibility' => GALLERY_VISIBILITY, 'today' => (new DateTime('now', new DateTimeZone('Europe/Berlin')))->format('Y-m-d')];
}

/**
 * Settings tabs (settings.php?tab=…): key => [label, its sections' ids], in the order shown. Update (update.php) is the last tab.
 * Section ids that aren't on this site yet are harmless: they only send old #addresses to the right tab (admin.js).
 */
const SETTINGS_TABS = [
    'site' => ['Site', ['identity', 'social', 'homepage', 'notice', 'legal']],
    'features' => ['Features', ['bugs', 'discord', 'mustardui', 'smutbase']],
    'patreon' => ['Patreon', ['patreon', 'logins']],
    'maintenance' => ['Maintenance', ['maintenance', 'problems', 'tools', 'system']],
];

/** The Settings tab asked for in the address (Site when none). */
function settings_tab(): string
{
    $t = m3d_get_text('tab');
    return isset(SETTINGS_TABS[$t]) ? $t : 'site';
}

/** Address of a Settings section: its tab, scrolled to the section (e.g. settings_url('maintenance')). */
function settings_url(string $section): string
{
    foreach (SETTINGS_TABS as $k => [, $ids]) if (in_array($section, $ids, true)) return '/admin/settings.php?tab=' . $k . '#' . $section;
    return '/admin/settings.php';
}

/** Tabs of the Assets page (the "Assets" menu item): Models · Accessories, in the .lib-tabs look of Settings, each with its count. */
function assets_menu(string $current): string
{
    $tabs = ['models' => ['/admin/models.php', 'Models', count(m3d_items('models'))], 'outfits' => ['/admin/outfits.php', 'Accessories', count(m3d_items('outfits'))]];
    $h = '<nav class="lib-tabs set-tabs" aria-label="Assets">';
    foreach ($tabs as $k => [$href, $label, $n]) $h .= '<a href="' . $href . '"' . ($current === $k ? ' aria-current="page"' : '') . '>' . $label . '<span>' . $n . '</span></a>';
    $dead = count(lc_dead());   // from the last check: Link check never runs by itself
    return $h . '<a href="/admin/links.php"' . ($current === 'links' ? ' aria-current="page"' : '') . '>Link Check' . ($dead ? '<span class="lc-tab-dead">' . $dead . ' dead</span>' : '') . '</a></nav>';
}

/** Tabs at the top of Settings and of the Update page ($current: a tab key, or 'update'). */
function settings_menu(string $current = ''): string
{
    $map = [];
    foreach (SETTINGS_TABS as $k => [, $ids]) foreach ($ids as $id) $map[$id] = $k;
    $h = '<nav class="lib-tabs set-tabs" aria-label="Settings" data-set-tabs="' . e(json_encode($map)) . '">';
    foreach (SETTINGS_TABS as $k => [$label]) {
        $h .= '<a href="/admin/settings.php' . ($k === 'site' ? '' : '?tab=' . $k) . '"' . ($current === $k ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    return $h . '<a href="/admin/update.php"' . ($current === 'update' ? ' aria-current="page"' : '') . '>Update</a></nav>'
        . '<a class="to-top" href="#main" data-to-top hidden aria-label="Back to the top" title="Back to the top"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 19V5m0 0-6 6m6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
}

/**
 * Weekly safety net: when the newest full backup (any kind; an uploaded one by the day it was made, bk_newest_full()) is older
 * than 7 days, opening an admin page makes one (code, content, counters and the download log; no images, to stay quick):
 * full-<date>-auto.zip. The newest 8 automatic ones are kept. One try a day at most, so a failure (full disk…) doesn't slow
 * every page. Returns the new file name.
 */
function admin_auto_backup(): ?string
{
    require_once __DIR__ . '/_backup.php';
    $dir = bk_dir();
    if (bk_newest_full() > time() - 7 * 86400) return null;
    $try = "$dir/.auto-backup-try";
    if (is_file($try) && filemtime($try) > time() - 86400) return null;
    @touch($try);
    try {
        $name = m3d_session_pause(fn() => backup_full('auto'));   // the other pages stay usable meanwhile
    } catch (Throwable $ex) {
        m3d_problem('Backups', 'Automatic backup failed: ' . $ex->getMessage());
        return null;
    }
    // only real automatic ones (no note): a manual backup whose note ends in "auto" is full-<date>-manual-auto.zip
    $autos = array_filter(glob("$dir/full-*-auto.zip") ?: [], fn($f) => preg_match('/^full-\d{8}-\d{6}-auto\.zip$/', basename($f)));
    rsort($autos);   // names start with the date: newest first
    foreach (array_slice($autos, 8) as $old) @unlink($old);
    return $name;
}

function admin_start(string $title, string $active = ''): void
{
    $autoBackup = admin_auto_backup();
    m3d_discord_stills_tick();   // new Gallery pictures waiting for their Discord post (also while only the admin is on the site)
    lc_auto_tick();   // Link check's automatic check, when on
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
  <title><?= e($title) ?> · <?= e(site_name()) ?> Admin</title>
<?= head_identity() ?>  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset('admin.css')) ?>" />
<?= accent_style() ?></head>
<body class="admin">
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/admin/"><?= brand() ?><span class="admin-tag">Admin</span></a>
      <nav class="nav admin-nav" aria-label="Admin">
        <a href="/admin/models.php" <?= in_array($active, ['models', 'outfits'], true) ? 'aria-current="page"' : '' ?>>Assets</a>
        <a href="/admin/gallery.php" <?= $active === 'gallery' ? 'aria-current="page"' : '' ?>>Gallery</a>
        <a href="/admin/docs.php" <?= $active === 'docs' ? 'aria-current="page"' : '' ?>>Docs</a>
        <a href="/admin/faq.php" <?= $active === 'faq' ? 'aria-current="page"' : '' ?>>FAQ</a>
        <a href="/admin/log.php" <?= $active === 'log' ? 'aria-current="page"' : '' ?>>Downloads</a>
        <a href="/admin/users.php" <?= $active === 'users' ? 'aria-current="page"' : '' ?>>Access</a>
        <a href="/admin/images.php" <?= $active === 'images' ? 'aria-current="page"' : '' ?>>Images</a>
        <a href="/admin/backups.php" <?= $active === 'backups' ? 'aria-current="page"' : '' ?>>Backups</a>
        <a href="/admin/settings.php" <?= $active === 'settings' || $active === 'update' ? 'aria-current="page"' : '' ?>>Settings</a>
      </nav>
<?php $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>' ?>
      <div class="hsearch" data-jump><?php /* "Jump to…": the public header's search bar (header_search() in view.php), filled by admin.js */ ?>
        <button type="button" class="hsearch-btn" title="Jump to a model, accessory, page or setting (Ctrl+K)" aria-label="Jump to… (Ctrl+K)" aria-expanded="false" data-jump-open><?= $icon ?></button>
        <div class="hsearch-panel" data-jump-panel hidden><div class="hs-bar" role="search"><label class="hs-field"><?= $icon ?><input type="search" placeholder="Jump to a model, accessory, page or setting…" aria-label="Jump to" autocomplete="off" spellcheck="false" enterkeyhint="go" role="combobox" aria-expanded="false" aria-controls="jump-list" aria-autocomplete="list" data-jump-input /></label><button type="button" class="hsearch-btn hs-close" aria-label="Close" title="Close (Esc)" data-jump-close><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></div></div>
        <ul class="hsearch-list" id="jump-list" role="listbox" aria-label="Results" data-jump-list></ul>
      </div>
      <div class="account">
        <form method="post" action="/admin/patron-preview.php" class="pv-start" title="See the site as a patron: pick the membership, months and total support in the bar on top. Nothing is downloaded or logged.">
          <?= form_keys() ?><input type="hidden" name="return" value="/" />
          <?php if (!m3d_preview()): ?><input type="hidden" name="tier" value="ok" /><input type="hidden" name="months" value="1" /><input type="hidden" name="total" value="5" /><?php endif ?>
          <button class="btn btn-sm btn-preview" name="do" value="apply"><?= m3d_preview() ? 'Back to Preview' : 'Preview' ?></button>
        </form>
        <a class="btn btn-ghost btn-sm" href="/">View Site</a>
      </div>
    </div>
  </header>
  <main id="main" class="wrap admin-main">
<?php if ($autoBackup): ?>
    <p class="notice">✓ Weekly automatic backup made (content, counts, download log and code; without images). <a href="/admin/backups.php">See Backups</a></p>
<?php endif ?>
<?php if (m3d_maintenance()): ?>
    <p class="notice maint-notice"><b>Maintenance mode is on:</b> visitors see the “Back Soon” page; only admins can use the site. <a href="<?= settings_url('maintenance') ?>">Turn it off in Settings</a></p>
<?php endif ?>
<?php
}

function admin_end(): void
{
    ?>
  </main>
  <div class="toast" data-toast hidden></div>
  <script src="<?= e(asset('admin.js')) ?>" data-viewer="<?= e(asset('vendor/model-viewer.min.js')) ?>" defer></script>
</body>
</html>
<?php
}
