<?php
// Shared by every admin page: access control, JSON helpers, validation, admin layout.
declare(strict_types=1);
require dirname(__DIR__) . '/app/_init.php';

header('X-Frame-Options: DENY');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

// ── Access: only Patreon ids listed in owner_user_ids ────────────────────────
function admin_require(array $cfg, bool $json = false): void
{
    if (m3d_is_admin($cfg)) return;
    if (m3d_setup_needed() && !$json) m3d_redirect('/setup/');
    if ($json) admin_json(['ok' => false, 'error' => 'Not logged in as admin. Reload the page and log in again.'], 403);
    if (!m3d_patron($cfg)) {
        m3d_redirect('/auth/login.php?return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    }
    http_response_code(403);
    layout_start('Admin', 'Admin', 'page-gate');
    $p = m3d_patron($cfg);
    echo '<section class="wrap gate"><p class="eyebrow">Admin</p><h1>No access</h1><p class="lead">You are logged in as '
        . e($p['name'] ?? '') . ' (Patreon id ' . e($p['id'] ?? '') . '), which is not an admin of this site.</p>'
        . '<div class="cta"><a class="btn btn-ghost" href="/auth/logout.php?return=%2Fadmin%2F">Log in with another account</a></div></section>';
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
}

// ── Validation: turns whatever the editor sends into a clean model ───────────
function clean_text(mixed $v, int $max = 2000): string
{
    $s = is_scalar($v) ? (string) $v : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[^\P{C}\n\t]/u', '', $s) ?? '';   // drop control characters, keep newlines
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
    if ($allowLocal && preg_match('#^/[A-Za-z0-9/_\-.%]+$#', $s) && !str_contains($s, '..')) return $s;
    if (preg_match('#^https?://[^\s"<>]+$#i', $s) && filter_var($s, FILTER_VALIDATE_URL)) return $s;
    throw new InvalidArgumentException("Not a valid link or image address: $s");
}

function check_slug(string $slug): void
{
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug)) throw new InvalidArgumentException('The address (slug) may only contain lowercase letters, numbers and dashes.');
    if ($slug === 'outfit') throw new InvalidArgumentException('"outfit" is reserved, pick another address.');
}

/** Download links: [{label, url, access: patrons|public}] */
/** Extra download requirements (on top of the paid tier): months as a patron, total support (typed in currency units). */
function clean_requirements(array $in, array &$out): void
{
    $months = trim((string) ($in['requireMonths'] ?? ''));
    $total = str_replace(',', '.', trim((string) ($in['requireTotal'] ?? '')));
    if ($months !== '' && (!ctype_digit($months) || (int) $months > 240)) throw new InvalidArgumentException('Months as a patron must be a whole number (0 = no requirement).');
    if ($total !== '' && (!is_numeric($total) || (float) $total < 0 || (float) $total > 100000)) throw new InvalidArgumentException('Total support must be an amount like 20 or 20.50 (0 = no requirement).');
    if ((int) $months > 0) $out['requireMonths'] = (int) $months;
    if ((float) $total > 0) $out['requireTotalCents'] = (int) round((float) $total * 100);
    if (!empty($out['requireMonths']) && !empty($out['requireTotalCents']) && ($in['requireMode'] ?? '') === 'or') $out['requireMode'] = 'or';   // default: and
    if (!empty($in['noLimits'])) $out['noLimits'] = true;   // not counted in the download limits (Admin → Access)
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
        if (in_array($en['stage'] ?? '', ['alpha', 'beta'], true)) $x['stage'] = $en['stage'];   // absent = standard
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
    $o = [];
    $o['slug'] = $existing['slug'] ?? strtolower(clean_text($in['slug'] ?? '', 64));
    check_slug($o['slug']);
    $o['name'] = clean_text($in['name'] ?? '', 120);
    if ($o['name'] === '') throw new InvalidArgumentException('Name is required.');
    $o['order'] = (int) ($existing['order'] ?? 9999);
    $type = clean_text($in['type'] ?? '', 40);
    if ($type !== '' && $type !== 'Outfit') $o['type'] = mb_strtoupper(mb_substr($type, 0, 1)) . mb_substr($type, 1);   // missing = Outfit (m3d_accessory_type)
    clean_visibility($in, $existing, $o);
    $desc = clean_text($in['description'] ?? '', 5000);
    if ($desc !== '') $o['description'] = $desc;
    $valid = array_column(m3d_models(), 'slug');
    $o['models'] = array_values(array_unique(array_filter(array_map('strval', (array) ($in['models'] ?? [])), fn($s) => in_array($s, $valid, true))));
    $o['images'] = clean_gallery($in['images'] ?? []);
    $o['links'] = clean_links($in['links'] ?? []);
    if ($addons = clean_addons($in['addons'] ?? [])) $o['addons'] = $addons;
    clean_requirements($in, $o);
    $note = clean_text($in['changelogNote'] ?? '', 1000);
    if ($note !== '') $o['changelogNote'] = $note;
    $log = clean_changelog($in['changelog'] ?? []);
    if ($log) $o['changelog'] = $log;
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

function clean_model(array $in, ?array $existing): array
{
    $m = [];
    $m['slug'] = $existing['slug'] ?? strtolower(clean_text($in['slug'] ?? '', 64));
    check_slug($m['slug']);
    $m['name'] = clean_text($in['name'] ?? '', 120);
    if ($m['name'] === '') throw new InvalidArgumentException('Name is required.');
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
    $m['status'] = (string) ($in['status'] ?? '');
    if (!isset(M3D_STATUSES[$m['status']])) throw new InvalidArgumentException('Unknown status.');
    $m['order'] = (int) ($existing['order'] ?? 9999);   // order is set by dragging in the model list
    if (!empty($in['featured'])) $m['featured'] = true;
    clean_visibility($in, $existing, $m);
    $desc = clean_text($in['description'] ?? '', 5000);
    if ($desc !== '') $m['description'] = $desc;
    foreach (['thumb', 'cover'] as $k) { $u = clean_url($in[$k] ?? ''); if ($u !== '') $m[$k] = $u; }
    $m['links'] = clean_links($in['links'] ?? []);
    $m['addons'] = clean_addons($in['addons'] ?? []);
    clean_requirements($in, $m);

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

    $note = clean_text($in['changelogNote'] ?? '', 1000);
    if ($note !== '') $m['changelogNote'] = $note;
    $log = clean_changelog($in['changelog'] ?? []);
    if ($log) $m['changelog'] = $log;

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
    'inline'  => ['Bold, italic, code, links', "**bold**, *italic*, `code` and [a link](/docs/)"],
    'heading' => ['Headings', "## Heading\n\n### Smaller heading"],
    'small-h' => ['Small heading', "### Small heading"],
    'list'    => ['Bullet list', "- First item\n- Second item\n  - indented: a sub-item"],
    'steps'   => ['Numbered steps', "1. Open Blender\n2. Press N\n  Two spaces continue the step."],
    'box'     => ['Box', "> [!TIP] Faster posing\n> Use the pose library.\n\n> [!WARNING]\n> Save your file first."],
    'table'   => ['Table', "| Generation | Blender |\n|---|---|\n| 4 | 4.2 or newer |"],
    'code'    => ['Code', "```python\nimport bpy\nprint(bpy.app.version)\n```"],
    'image'   => ['Picture', "![Caption](/media/…)"],
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
        . '<div class="fmt-head" aria-hidden="true"><span></span><span>You write</span><span>You get</span></div>' . $rows . '</details>';
}

// ── "Getting started" box of model / accessory pages (Admin → Models and Admin → Accessories, settings.json → start.<kind>) ──
/** Handles the box's POST (do=start with kind); returns [done, error] or [null, null] when not ours. */
function admin_start_post(string $kind): array
{
    $do = (string) ($_POST['do'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $do !== 'start' || ($_POST['kind'] ?? '') !== $kind) return [null, null];
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $start = m3d_settings()['start'] ?? [];
    try {
        $links = admin_posted_links('start', 10);
        $note = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['start_note'] ?? ''))), 0, 400);
        $start[$kind] = ['links' => $links, 'note' => $note];
        return m3d_save_settings(['start' => $start]) ? ['Getting started links saved.', null] : [null, 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.'];
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
        . '<input class="input" name="' . $p . '_url[]" value="' . e($url) . '" placeholder="/docs/… or https://…" list="site-pages" aria-label="Address" spellcheck="false" />'
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
        $web = preg_match('#^https://[^\s<>"]+$#i', $url) && filter_var($url, FILTER_VALIDATE_URL);
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
    foreach (['/faq/' => 'FAQ', '/accessories/' => 'Accessories'] + (m3d_mustardui_on() ? ['/mustardui/' => 'MustardUI'] : []) + ['/contacts/' => 'Contacts', '/account/' => 'My account'] as $u => $l) $pages .= '<option value="' . $u . '">' . $l . '</option>';
    return '<datalist id="site-pages">' . $pages . '</datalist>';
}

/** The collapsible editor of the box: links (text + address, drag to reorder) and the line under them. */
function admin_start_box(string $kind, bool $open = false): string
{
    $box = start_box($kind);
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    $csrf = e(m3d_csrf());
    $row = fn(string $label = '', string $url = '') => admin_link_row('start', $label, $url);
    return '<details class="sec intro-sec" id="start"' . ($open ? ' open' : '') . '>'
        . '<summary><b>Getting started</b> <span class="muted small">the box under the download button on ' . $what . ' pages</span></summary>'
        . '<form method="post" class="intro-form start-col" data-rows-form>'
        . '<input type="hidden" name="csrf" value="' . $csrf . '" /><input type="hidden" name="do" value="start" /><input type="hidden" name="kind" value="' . e($kind) . '" />'
        . admin_site_pages_datalist()
        . '<div class="start-rows" data-rows>' . implode('', array_map(fn($l) => $row($l[0], $l[1]), $box['links'])) . '</div>'
        . '<template data-row-template>' . $row() . '</template>'
        . '<div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add link</button></div>'
        . '<label class="field"><span class="lbl">Line under the links' . tip('Takes **bold**, *italic* and [links](https://…).') . '</span><textarea class="input" name="start_note" rows="2" maxlength="400" placeholder="Empty = no line">' . e($box['note']) . '</textarea></label>'
        . '<div class="row-btns"><button class="btn btn-primary btn-sm">Save links</button></div></form>'
        . '</details>';
}

/** Help text behind a "?" (shown on hover, focus or tap by admin.js, like tip() there): keeps explanations out of the way. */
function tip(string $text): string
{
    return '<button type="button" class="tip" data-tip="' . e($text) . '" aria-label="' . e('Help: ' . $text) . '">?</button>';
}

/** Links column of the Models / Accessories lists: "2 patron / 1 public"; hovering (or focusing) it lists the link names (admin.js, data-tip-links). */
function admin_links_summary(array $links): string
{
    $names = ['patrons' => [], 'public' => []];
    foreach ($links as $l) $names[($l['access'] ?? 'patrons') === 'public' ? 'public' : 'patrons'][] = trim((string) ($l['label'] ?? '')) ?: 'Download';
    [$p, $f] = [count($names['patrons']), count($names['public'])];
    if (!$p && !$f) return '<span class="warn-dot" title="No download links">—</span>';
    $txt = ($p ? '<span class="ok-dot">' . $p . ' patron</span>' : '') . ($p && $f ? '<br>' : '') . ($f ? '<span class="muted">' . $f . ' public</span>' : '');
    $aria = implode('; ', array_filter([$p ? 'Patrons: ' . implode(', ', $names['patrons']) : '', $f ? 'Public: ' . implode(', ', $names['public']) : '']));
    return '<span class="links-sum" tabindex="0" data-tip-links="' . e(json_encode($names, JSON_UNESCAPED_UNICODE)) . '" aria-label="' . e($aria) . '">' . $txt . '</span>';
}

// ── Admin layout ─────────────────────────────────────────────────────────────
/** Badge for the admin lists when a model/outfit isn't fully public. */
function admin_visibility_badge(array $item): string
{
    return match (m3d_visibility($item)) {
        'draft'    => ' <span class="badge tone-grey" title="Only admins can see it">Draft</span>',
        'password' => ' <span class="badge tone-violet" title="Visitors need the password' . (!empty($item['unlisted']) ? '; not listed in the catalog' : '') . '">🔑 Password' . (!empty($item['unlisted']) ? ' · unlisted' : '') . '</span>',
        'blurred'  => ' <span class="badge tone-sky" title="Pictures are blurred for everyone">Blurred</span>',
        'blurred-patrons' => ' <span class="badge tone-sky" title="Pictures are blurred except for paying patrons">Blurred · clear for patrons</span>',
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
        . tip('Tick rows (or use these) to change several at once: delete them, or set their visibility' . ($kind === 'models' ? ' or status' : '') . '. With a search, they only pick the rows shown.')
        . '<label class="batch-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>'
        . '<input class="input" type="search" data-batch-search placeholder="Search ' . ($kind === 'models' ? 'models' : 'accessories') . '…" aria-label="Search ' . ($kind === 'models' ? 'models' : 'accessories') . '" autocomplete="off" spellcheck="false" /></label></div>'
        . '<p class="muted small batch-empty" data-batch-empty hidden></p>'
        . '<div class="batch-bar" data-batch-bar hidden><b data-batch-count></b>'
        . ($kind === 'models' ? '<select class="input" data-batch-op="status" aria-label="Set status"><option value="">Set status…</option>' . $opt(M3D_STATUSES) . '</select>' : '')
        . '<select class="input" data-batch-op="visibility" aria-label="Set visibility"><option value="">Set visibility…</option>' . $opt(M3D_VISIBILITY) . '</select>'
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

/** Menu at the top of Settings (its sections) and of the Update page, which is part of Settings. */
function settings_menu(string $current = ''): string
{
    $base = $current === 'update' ? '/admin/settings.php' : '';
    $h = '<nav class="set-menu" aria-label="Settings">';
    foreach (['identity' => 'Site identity', 'social' => 'Social links', 'homepage' => 'Homepage', 'notice' => 'Site notice', 'legal' => 'Legal', 'patreon' => 'Patreon', 'mustardui' => 'MustardUI', 'smutbase' => 'SmutBase', 'maintenance' => 'Maintenance', 'tools' => 'Tools', 'system' => 'System'] as $id => $label) {
        $h .= '<a href="' . $base . '#' . $id . '">' . $label . '</a>';
    }
    return $h . '<a href="/admin/update.php"' . ($current === 'update' ? ' aria-current="page"' : '') . '>Update</a></nav>'
        . '<a class="to-top" href="#main" data-to-top hidden aria-label="Back to the top" title="Back to the top"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 19V5m0 0-6 6m6-6 6 6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>';
}

/**
 * Weekly safety net: when the newest full backup (any kind) is older than 7 days, opening an admin page makes one
 * (code, content, counters and the download log; no images, to stay quick): full-<date>-auto.zip. The newest 8 automatic
 * ones are kept. One try a day at most, so a failure (full disk…) doesn't slow every page. Returns the new file name.
 */
function admin_auto_backup(): ?string
{
    require_once __DIR__ . '/_backup.php';
    $dir = bk_dir();
    $newest = max(array_map('filemtime', glob("$dir/full-*.zip") ?: []) ?: [0]);
    if ($newest > time() - 7 * 86400) return null;
    $try = "$dir/.auto-backup-try";
    if (is_file($try) && filemtime($try) > time() - 86400) return null;
    @touch($try);
    try {
        $name = backup_full('auto');
    } catch (Throwable $ex) {
        error_log('[site admin] automatic backup failed: ' . $ex->getMessage());
        return null;
    }
    $autos = glob("$dir/full-*-auto.zip") ?: [];
    rsort($autos);   // names start with the date: newest first
    foreach (array_slice($autos, 8) as $old) @unlink($old);
    return $name;
}

function admin_start(string $title, string $active = ''): void
{
    $autoBackup = admin_auto_backup();
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <meta name="csrf" content="<?= e(m3d_csrf()) ?>" />
  <title><?= e($title) ?> · <?= e(site_name()) ?> admin</title>
<?= head_identity() ?>  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset('admin.css')) ?>" />
<?= accent_style() ?></head>
<body class="admin">
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/admin/"><?= brand() ?><span class="admin-tag">Admin</span></a>
      <nav class="nav admin-nav" aria-label="Admin">
        <a href="/admin/" <?= $active === 'models' ? 'aria-current="page"' : '' ?>>Models</a>
        <a href="/admin/outfits.php" <?= $active === 'outfits' ? 'aria-current="page"' : '' ?>>Accessories</a>
        <a href="/admin/docs.php" <?= $active === 'docs' ? 'aria-current="page"' : '' ?>>Docs</a>
        <a href="/admin/faq.php" <?= $active === 'faq' ? 'aria-current="page"' : '' ?>>FAQ</a>
        <a href="/admin/log.php" <?= $active === 'log' ? 'aria-current="page"' : '' ?>>Downloads</a>
        <a href="/admin/users.php" <?= $active === 'users' ? 'aria-current="page"' : '' ?>>Access</a>
        <a href="/admin/images.php" <?= $active === 'images' ? 'aria-current="page"' : '' ?>>Images</a>
        <a href="/admin/backups.php" <?= $active === 'backups' ? 'aria-current="page"' : '' ?>>Backups</a>
        <a href="/admin/settings.php" <?= $active === 'settings' || $active === 'update' ? 'aria-current="page"' : '' ?>>Settings</a>
      </nav>
      <div class="account">
        <form method="post" action="/admin/patron-preview.php" class="pv-start" title="See the site as a patron: pick the membership, months and total support in the bar on top. Nothing is downloaded or logged.">
          <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="return" value="/" />
          <?php if (!m3d_preview()): ?><input type="hidden" name="tier" value="ok" /><input type="hidden" name="months" value="1" /><input type="hidden" name="total" value="5" /><?php endif ?>
          <button class="btn btn-sm btn-preview" name="do" value="apply"><?= m3d_preview() ? 'Back to preview' : 'Preview' ?></button>
        </form>
        <a class="btn btn-ghost btn-sm" href="/">View site</a>
      </div>
    </div>
  </header>
  <main id="main" class="wrap admin-main">
<?php if ($autoBackup): ?>
    <p class="notice">✓ Weekly automatic backup made (content, counts, download log and code; without images). <a href="/admin/backups.php">See Backups</a></p>
<?php endif ?>
<?php if (m3d_maintenance()): ?>
    <p class="notice maint-notice"><b>Maintenance mode is on:</b> visitors see the “Back soon” page; only admins can use the site. <a href="/admin/settings.php#maintenance">Turn it off in Settings</a></p>
<?php endif ?>
<?php
}

function admin_end(bool $editorScript = false): void
{
    ?>
  </main>
  <div class="toast" data-toast hidden></div>
  <script src="<?= e(asset('admin.js')) ?>" defer></script>
</body>
</html>
<?php
}
