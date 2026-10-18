<?php
// Page layout and small building blocks shared by the public pages.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const SITE = [
    'mustarduiExt'    => 'https://extensions.blender.org/add-ons/mustardui/',
    'mustardui'       => 'https://github.com/Mustard2/MustardUI',
    'mustarduiIssues' => 'https://github.com/Mustard2/MustardUI/issues',
    'mustarduiWiki'   => 'https://github.com/Mustard2/MustardUI/wiki',
    'mustarduiPage'   => '/mustardui/',
    'docs'            => '/docs/',
    'faqs'            => '/faq/',
    'privacy'         => '/privacy/',
    'contacts'        => '/contacts/',
];

/** /assets/x.css?v=<mtime> so browsers and CDNs pick up new versions after an update. */
function asset(string $path): string
{
    $f = M3D_SITE . '/assets/' . $path;
    return '/assets/' . $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}

/** A file at the site root (icons) with ?v=<modified time>: .htaccess caches images for a year, so a new icon needs a new address. */
function root_file(string $path): string
{
    $f = M3D_SITE . '/' . $path;
    return '/' . $path . (is_file($f) ? '?v=' . filemtime($f) : '');
}

function status_info(string $status): array
{
    [$label, $tone] = M3D_STATUSES[$status] ?? [$status, 'grey'];
    return ['label' => $label, 'tone' => $tone];
}

/** A small "?" next to a label: its text shows in a floating box on hover, focus or tap (site.js, `.help-tip` / `.help-pop`). */
function help_tip(string $text): string
{
    return '<button type="button" class="help-tip" data-help="' . e($text) . '" aria-label="' . e('Help: ' . $text) . '">?</button>';
}

/** The chain-link button that copies a link to a FAQ question or a docs heading (site.js `[data-copy-link]`: copies, shows "Link copied"). */
function copy_link(string $id, string $class, string $label): string
{
    return '<a class="copy-link ' . e($class) . '" href="#' . e($id) . '" data-copy-link title="Copy link" aria-label="' . e($label) . '">'
        . '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg></a>';
}

function status_badge(string $status): string
{
    $s = status_info($status);
    return '<span class="badge tone-' . e($s['tone']) . '">' . e($s['label']) . '</span>';
}

/** Site identity (Admin → Settings → Site identity, settings.json → identity): what is missing keeps these. */
const IDENTITY_DEFAULTS = [
    'name'      => 'My catalog',
    'highlight' => '',                                // the part of the name in the accent colour ('' = none)
    'tagline'   => '3D character models',             // homepage title, footer line, default page description
    'color'     => '#e8b931',                         // accent colour (buttons, links, highlights): CSS --mustard
    'logo'      => '',                                // '' = none; else /media/site/logo-<hex>.png (or a data: picture)
    'icon'      => '',                                // '' = favicon.ico/svg; else <hex> of /media/site/icon-<hex>-{32,180,192}.png
];

function m3d_identity(): array
{
    $saved = m3d_settings()['identity'] ?? [];
    $id = array_merge(IDENTITY_DEFAULTS, array_intersect_key(is_array($saved) ? $saved : [], IDENTITY_DEFAULTS));
    foreach (['name', 'tagline'] as $k) if (trim((string) $id[$k]) === '') $id[$k] = IDENTITY_DEFAULTS[$k];
    if (!preg_match('/^#[0-9a-f]{6}$/i', (string) $id['color'])) $id['color'] = IDENTITY_DEFAULTS['color'];
    return $id;
}

function site_name(): string
{
    return (string) m3d_identity()['name'];
}

function site_tagline(): string
{
    return (string) m3d_identity()['tagline'];
}

/** Text colour on the accent colour (buttons, active chips): dark on light accents, white on dark ones. */
function accent_ink(string $hex): string
{
    [$r, $g, $b] = array_map(fn($c) => hexdec($c) / 255, str_split(ltrim($hex, '#'), 2));
    $lin = fn($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b) > 0.3 ? '#1b1607' : '#ffffff';
}

/** <head> lines every page shares: theme colour and icons (the uploaded logo's, else favicon files at the site root if present). */
function head_identity(): string
{
    $id = m3d_identity();
    $h = '  <meta name="theme-color" content="' . e($id['color']) . '" />' . "\n";
    if ($id['icon'] !== '' && is_file(M3D_SITE . '/media/site/icon-' . $id['icon'] . '-32.png')) {
        $u = '/media/site/icon-' . $id['icon'];
        $h .= '  <link rel="icon" href="' . e($u) . '-32.png" sizes="32x32" type="image/png" />' . "\n"
            . '  <link rel="icon" href="' . e($u) . '-192.png" sizes="192x192" type="image/png" />' . "\n"
            . '  <link rel="apple-touch-icon" href="' . e($u) . '-180.png" />' . "\n";
    } else {   // icon files placed at the site root (not part of the package)
        foreach (['favicon.ico' => ['icon', ' sizes="48x48"'], 'favicon.svg' => ['icon', ' type="image/svg+xml"'], 'apple-touch-icon.png' => ['apple-touch-icon', '']] as $f => [$rel, $attr]) {
            if (is_file(M3D_SITE . '/' . $f)) $h .= '  <link rel="' . $rel . '" href="' . e(root_file($f)) . '"' . $attr . ' />' . "\n";
        }
    }
    return $h;
}

/** The accent colour when changed in Site identity: printed AFTER the stylesheets, so it wins over their :root. */
function accent_style(): string
{
    $c = (string) m3d_identity()['color'];
    return strtolower($c) === IDENTITY_DEFAULTS['color'] ? '' : '  <style>:root { --mustard: ' . e($c) . '; --mustard-ink: ' . accent_ink($c) . '; }</style>' . "\n";
}

/** The logo + site name of the header, footer and gates; the highlighted part (its last occurrence) in the accent colour. */
function brand(): string
{
    $id = m3d_identity();
    // a 32 px drawing kept inside the settings (data: SVG) is laid out like an icon with its own padding (.brand-svg)
    $logo = $id['logo'] !== '' ? '<img class="brand-logo' . (str_starts_with($id['logo'], 'data:image/svg+xml') ? ' brand-svg' : '') . '" src="' . e($id['logo']) . '" alt="" />' : '';
    $name = (string) $id['name'];
    $hl = (string) $id['highlight'];
    $at = $hl !== '' ? mb_strrpos($name, $hl) : false;
    $text = $at === false ? e($name)
        : e(mb_substr($name, 0, $at)) . '<span class="brand-3d">' . e($hl) . '</span>' . e(mb_substr($name, $at + mb_strlen($hl)));
    return $logo . '<span>' . $text . '</span>';
}

const AGE_COOKIE = 'm3d_age_ok';
const AGE_DAYS = 30;   // how long the "I am 18+" answer is remembered

function age_confirmed(): bool
{
    return ($_COOKIE[AGE_COOKIE] ?? '') === '1';
}

/** Full-screen 18+ check shown until the visitor confirms (plain links: works without JavaScript). $return: where "Enter" goes
 *  back to (default: this page). */
function age_gate(string $path, ?string $return = null): string
{
    // the address as typed (REQUEST_URI): after a rewrite QUERY_STRING holds the internal ?slug=… too
    $q = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
    $return = rawurlencode($return ?? $path . ($q !== '' ? '?' . $q : ''));
    return '<div class="age-gate" role="dialog" aria-modal="true" aria-labelledby="age-title">'
        . '<div class="age-box">'
        . '<a class="brand" href="/">' . brand() . '</a>'
        . '<p class="age-pill">18+ Only</p>'
        . '<h1 id="age-title">Before You Enter</h1>'
        . '<p class="muted">This website contains content intended for adults only. By entering, you confirm that you are at least 18 years old, or the age of majority where you live.</p>'
        . '<div class="age-cta">'
        . '<a class="btn btn-primary" href="/age.php?return=' . e($return) . '">I Am 18 or Older · Enter</a>'
        . '<a class="btn btn-ghost" href="https://www.google.com" rel="noreferrer">I Am Under 18 · Leave</a>'
        . '</div>'
        . '<p class="fine">Your answer is remembered on this device for ' . AGE_DAYS . ' days.</p>'
        . '</div></div>';
}

/**
 * Download links (auth/get.php, get-still.php) for a visitor who hasn't confirmed 18+ yet (a link shared on Discord): only the age
 * gate, nothing behind it (no waiting page, no file); "Enter" comes back to $return, the same /get/ address.
 */
function age_gate_page(string $return): void
{
    if (age_confirmed()) return;
    header('Cache-Control: no-store');
    $GLOBALS['m3d_age_return'] = $return;
    layout_start('Download', 'Adults only.', 'page-gate');
    echo '<section class="wrap gate"><p class="eyebrow">Download</p><h1>18+ Only</h1><p class="lead">Confirm your age to continue to the download.</p></section>';
    layout_end();
    exit;
}

/** Pages that stay open during maintenance: the legal ones (privacy policy with the imprint, contacts). */
const MAINTENANCE_OPEN = ['/privacy/', '/contacts/'];

/**
 * Maintenance mode: everyone except admins gets this page (HTTP 503, so search engines come back later).
 * The "Log in with Patreon" button stays, so the owner can log in and go through.
 */
function maintenance_gate(): void
{
    if (!m3d_maintenance_blocks()) return;
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    if (in_array(rtrim($path, '/') . '/', MAINTENANCE_OPEN, true)) return;
    $m = m3d_maintenance();
    $p = m3d_patron($GLOBALS['M3D']);
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en" data-tz="<?= e(m3d_tz_id()) ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Back Soon · <?= e(site_name()) ?></title>
<?= head_identity() ?>  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
<?= accent_style() ?></head>
<body class="page-gate page-maintenance">
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/" aria-label="<?= e(site_name()) ?> home"><?= brand() ?></a>
<?= account_box($path) ?>    </div>
  </header>
  <main id="main">
    <section class="wrap gate">
      <p class="eyebrow">Maintenance</p>
      <h1>Back Soon</h1>
      <p class="lead pre"><?= e(trim($m['message']) !== '' ? $m['message'] : 'The website is being updated. Please come back in a little while.') ?></p>
      <?php if ($p): ?><p class="note muted">You are logged in as <?= e($p['name'] ?? '') ?>. During maintenance only the site owner can enter. <a href="/auth/logout.php?return=%2F">Log out</a></p><?php endif ?>
      <div class="cta"><?php if (social_url('discord') !== ''): ?><a class="btn btn-ghost" href="<?= e(social_url('discord')) ?>" rel="noopener">News on Discord</a><?php endif ?><?php if (social_url('patreon') !== ''): ?><a class="btn btn-ghost" href="<?= e(social_url('patreon')) ?>" rel="noopener">Patreon</a><?php endif ?></div>
    </section>
  </main>
  <footer class="site-footer">
    <div class="wrap footer-inner">
      <a class="brand" href="/"><?= brand() ?></a>
<?= legal_links() ?>    </div>
  </footer>
  <script src="<?= e(asset('site.js')) ?>" defer></script>
</body>
</html>
<?php
    exit;
}

/** Absolute address of a site path (link previews need one): this host, https behind a proxy too. */
function abs_url(string $url): string
{
    if (preg_match('#^https?://#i', $url)) return $url;
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[a-z0-9.-]+(:\d+)?$/i', $host)) $host = 'localhost';
    return (m3d_is_https() ? 'https' : 'http') . '://' . $host . '/' . ltrim($url, '/');
}

/** Picture for link previews (Discord, X, Patreon…) of the page about to be printed: public items only, never blurred ones. */
function page_image(string $url): void
{
    $GLOBALS['m3d_page_image'] = $url;
}

function layout_start(string $title = '', string $description = '', string $bodyClass = ''): void
{
    if ($description === '') $description = site_tagline() . '.';
    if (m3d_setup_needed()) m3d_redirect('/setup/');   // a fresh install: the first-use setup comes first
    maintenance_gate();
    m3d_release_tick();   // a release date has passed: its Discord post goes out after this page
    m3d_discord_stills_tick();   // new Gallery pictures waiting for their Discord post
    m3d_blog_discord_tick();   // a blog post went out (published, or its date reached): its Discord post after this page
    lc_auto_tick();   // Link check's automatic check (only when switched on): a few due links after this page
    $locked = !age_confirmed();
    if ($locked) $bodyClass = trim($bodyClass . ' age-locked');
    $full = $title !== '' ? $title . ' · ' . site_name() : site_name() . ' · ' . site_tagline();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    header('Content-Type: text/html; charset=utf-8');
    // never stored by CDNs or other shared caches: the page differs with the 18+ cookie and the login
    // (a page that asked for no-store itself, like the download pages, keeps it)
    $noStore = (bool) preg_grep('/^Cache-Control:.*no-store/i', headers_list());
    if (session_status() !== PHP_SESSION_ACTIVE && !$noStore) header('Cache-Control: private, no-cache');
    ?>
<!doctype html>
<html lang="en" data-tz="<?= e(m3d_tz_id()) ?>">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($full) ?></title>
  <meta name="description" content="<?= e($description) ?>" />
  <meta property="og:site_name" content="<?= e(site_name()) ?>" />   <!-- link previews: the small site-name line above the title -->
  <meta property="og:title" content="<?= e($title !== '' ? $title : $full) ?>" />
  <meta property="og:description" content="<?= e($description) ?>" />
<?php if (!empty($GLOBALS['m3d_page_image'])): ?>
  <meta property="og:image" content="<?= e(abs_url((string) $GLOBALS['m3d_page_image'])) ?>" />
  <meta name="twitter:card" content="summary_large_image" />
<?php endif ?>
<?= head_identity() ?>  <link rel="preload" href="/assets/fonts/inter-latin-400.woff2" as="font" type="font/woff2" crossorigin />   <!-- all four fonts start with the CSS, not after it (every page uses them) -->
  <link rel="preload" href="/assets/fonts/inter-latin-500.woff2" as="font" type="font/woff2" crossorigin />
  <link rel="preload" href="/assets/fonts/inter-latin-600.woff2" as="font" type="font/woff2" crossorigin />
  <link rel="preload" href="/assets/fonts/bricolage-grotesque-latin-var.woff2" as="font" type="font/woff2" crossorigin />
  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
<?= accent_style() ?></head>
<body class="<?= e($bodyClass) ?>">
<?php if ($locked) echo age_gate($path, $GLOBALS['m3d_age_return'] ?? null); ?>
  <a class="skip" href="#main">Skip to content</a>
<?php if (m3d_maintenance() && m3d_is_admin($GLOBALS['M3D'])): ?>
  <p class="maint-bar">Maintenance mode is on: only admins can see the site. <a href="/admin/settings.php?tab=maintenance#maintenance">Turn it off</a></p>
<?php endif ?>
<?= preview_bar($_SERVER['REQUEST_URI'] ?? $path) ?>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/" aria-label="<?= e(site_name()) ?> home"><?= brand() ?></a>
      <nav class="nav" aria-label="Main">
<?php foreach (site_menu() as [$label, $href, $cur]): ?>        <a href="<?= e($href) ?>"<?= $cur !== '' && str_starts_with($path, $cur) ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?><?php
    // the creator (not while previewing as a patron): the bell instead of numbers in the menu (app/notify.php)
    $creatorBell = m3d_is_admin($GLOBALS['M3D']) && empty(m3d_patron($GLOBALS['M3D'])['preview']);
    if (m3d_bugs_on()):
    // the number: news on the reports a patron follows
    $bugNews = $creatorBell ? 0 : m3d_bug_news_count(m3d_patron($GLOBALS['M3D']));
    $bugTip = m3d_plural($bugNews, 'report') . ' you follow ' . ($bugNews === 1 ? 'has' : 'have') . ' news'; ?>        <a href="/bugs/<?= $bugNews ? '?status=following' : '' ?>"<?= str_starts_with($path, '/bugs') ? ' aria-current="page"' : '' ?>>Bugs<?= $bugNews ? '<span class="nav-new" title="' . e($bugTip) . '">' . $bugNews . '</span>' : '' ?></a>
<?php endif ?>
      </nav>
<?= header_search() ?><?= $creatorBell ? quick_add() . notify_bell() : '' ?><?= account_box($path) ?>    </div>
  </header>
<?= site_notice($path) ?>
  <main id="main">
<?php
}

/** Notice types: label (for screen readers and the admin), colour and icon (the box icons of the text format). */
const NOTICE_TYPES = [
    'info'    => ['Info', 'note'],
    'update'  => ['Update', 'important'],
    'success' => ['Good News', 'tip'],
    'warning' => ['Warning', 'warning'],
    'alert'   => ['Important', 'caution'],
];

/** The site notice bar for this page (see m3d_notice()), or ''. Closed notices stay closed in this browser (localStorage). */
function site_notice(string $path): string
{
    $n = m3d_notice();
    if (!$n || (($n['where'] ?? 'all') === 'home' && $path !== '/')) return '';
    return notice_html($n);
}

/** $preview (Admin → Settings): no "already closed" check, so the preview always shows. */
function notice_html(array $n, bool $preview = false): string
{
    $type = isset(NOTICE_TYPES[$n['type'] ?? '']) ? $n['type'] : 'info';
    [$label, $icon] = NOTICE_TYPES[$type];
    // a new text (or type) gets a new code, so a changed notice shows again to people who closed the old one
    $id = substr(md5($type . '|' . $n['text']), 0, 10);
    $close = !empty($n['closable'])
        ? '<button type="button" class="notice-close"' . ($preview ? '' : ' data-notice-close') . ' aria-label="Close this message">✕</button>' : '';
    return '<div class="site-notice notice-' . $type . '" role="status" data-notice="' . $id . '"><div class="wrap notice-inner">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true">' . CALLOUTS[$icon][1] . '</svg>'
        . '<p><span class="sr-only">' . e($label) . ': </span>' . md_inline((string) $n['text']) . '</p>' . $close . '</div></div>'
        . ($close && !$preview ? '<script>try{if(localStorage.getItem("m3d_notice")==="' . $id . '")document.currentScript.previousElementSibling.hidden=true}catch(e){}</script>' : '');
}

function layout_end(): void
{
    ?>
  </main>
  <footer class="site-footer">
    <div class="wrap footer-inner">
      <div>
        <a class="brand" href="/"><?= brand() ?></a>
        <p class="muted small"><?= e(site_tagline()) ?>.</p>
        <?= social_buttons() ?>
      </div>
      <nav class="footer-links" aria-label="Explore">
<?php foreach (site_menu() as [$label, $href]): ?>        <a href="<?= e($href) ?>"><?= e($label) ?></a>
<?php endforeach ?><?php if (m3d_bugs_on()): ?>        <a href="/bugs/">Bug Reports</a>
<?php endif ?>
      </nav>
<?= legal_links() ?>    </div>
  </footer>
  <dialog class="lightbox" data-lightbox aria-label="Image viewer">
    <figure>
      <img alt="" data-lightbox-img />
      <figcaption><span data-lightbox-cap></span><span class="lb-count" data-lightbox-count></span></figcaption>
      <p class="lb-dl" data-lightbox-dl hidden><span class="lb-dl-note" data-lightbox-dl-note></span><button type="button" class="btn btn-ghost btn-sm lb-like" aria-pressed="false" data-lightbox-like hidden></button><a class="btn btn-primary btn-sm" href="#" data-lightbox-dl-btn></a></p>
    </figure>
    <button class="lb-nav lb-prev" data-lb-prev aria-label="Previous image">‹</button>
    <button class="lb-nav lb-next" data-lb-next aria-label="Next image">›</button>
    <button class="lb-zoom" data-lightbox-zoom aria-label="Zoom in" title="Zoom (or click the picture; double-tap or pinch on phones)"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m15.5 15.5 5 5M8 10.5h5"/><path class="lb-zoom-plus" d="M10.5 8v5"/></svg></button>
    <button class="lb-close" data-lightbox-close aria-label="Close">✕</button>
  </dialog>
<?= flash_stack() ?>  <script src="<?= e(asset('site.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** The menu's links, in the header and the footer: [label, address, the start of the paths it is current on ('' = none)]; Bugs is added by each. */
function site_menu(): array
{
    $m = [['Characters', '/#catalog', ''], ['Accessories', '/accessories/', '/accessories/']];
    if (m3d_has_gallery()) $m[] = ['Gallery', '/gallery/', '/gallery/'];
    if (m3d_has_blog()) $m[] = ['Blog', '/blog/', '/blog/'];
    if (m3d_mustardui_on()) $m[] = ['MustardUI', SITE['mustarduiPage'], '/mustardui'];
    if (m3d_has_docs()) $m[] = ['Docs', SITE['docs'], '/docs/'];
    if (m3d_has_faq()) $m[] = ['FAQ', SITE['faqs'], '/faq'];
    return $m;
}

/** The footer's Privacy Policy and Contacts links (every page, the maintenance page too). */
function legal_links(): string
{
    return '      <nav class="footer-links" aria-label="Legal">' . "\n"
        . '        <a href="' . e(SITE['privacy']) . '">Privacy Policy</a>' . "\n"
        . '        <a href="' . e(SITE['contacts']) . '">Contacts</a>' . "\n"
        . "      </nav>\n";
}

/**
 * Search in the header: the magnifier opens a search bar that grows over the menu (✕ on the right closes it) and
 * searches models, accessories, docs and FAQ as you type (site.js [data-hsearch], list from /search/?index=1); without
 * JavaScript the magnifier opens the /search/ page.
 */
function header_search(): string
{
    $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';
    return '      <div class="hsearch" data-hsearch>'
        . '<a class="hsearch-btn" href="/search/" aria-label="Search" title="Search" aria-expanded="false" data-hsearch-open>' . $icon . '</a>'
        . '<div class="hsearch-panel" data-hsearch-panel hidden>'
        . '<form class="hs-bar" action="/search/" method="get" role="search"><label class="hs-field">' . $icon
        . '<input type="search" name="q" placeholder="Search models, accessories, help…" aria-label="Search the site" autocomplete="off" spellcheck="false" enterkeyhint="search" data-hsearch-input /></label>'
        . '<button type="button" class="hsearch-btn hs-close" aria-label="Close the search" title="Close (Esc)" data-hsearch-close><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button></form>'
        . '</div><ul class="hsearch-list" data-hsearch-list></ul>'
        . "</div>\n";
}

/**
 * Quick Add (the creator's header, admin and public, between the search and the bell): a "+" opening a short menu of new
 * things: a model, an accessory, a blog post (while the blog is on) and a new changelog version of an existing model or
 * accessory (a list to pick it from, /admin/jump.php?items=1, then its editor with the version added: edit.php ?add=version).
 * Its script is in bell.js, which the bell next to it loads.
 */
function quick_add(): string
{
    $ico = fn(string $paths) => '<svg viewBox="0 0 24 24" aria-hidden="true">' . $paths . '</svg>';
    $row = fn(string $href, string $icon, string $label) => '<a class="qa-row" role="menuitem" href="' . e($href) . '">' . $ico($icon) . '<span>' . e($label) . '</span></a>';
    $rows = $row('/admin/edit.php', '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>', 'New Model')
        . $row('/admin/edit.php?kind=outfits', ACCESSORY_ICON_GENERIC, 'New Accessory')
        . (m3d_blog_on() ? $row('/admin/blog.php?new=1', '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>', 'New Blog Post') : '');
    return '<div class="qa" data-qa>'
        . '<button type="button" class="hsearch-btn qa-btn" title="Quick Add" aria-label="Quick Add" aria-haspopup="menu" aria-expanded="false" aria-controls="qa-panel" data-qa-btn>' . $ico('<path d="M12 5v14M5 12h14"/>') . '</button>'
        . '<div class="qa-panel" id="qa-panel" data-qa-panel hidden>'
        . '<div class="qa-menu" role="menu" aria-label="Quick Add" data-qa-menu>' . $rows
        . '<button type="button" class="qa-row" role="menuitem" data-qa-pick>' . $ico('<circle cx="12" cy="12" r="3"/><path d="M12 3v6M12 15v6"/>') . '<span>Add Changelog Version</span>'
        . '<svg class="qa-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></div>'
        . '<div class="qa-pickview" data-qa-pickview hidden>'
        . '<div class="qa-head"><button type="button" class="qa-back" aria-label="Back" title="Back" data-qa-back><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button><b>Add a Version To</b></div>'
        . '<input type="search" class="input qa-find" placeholder="Find a model or accessory…" aria-label="Find a model or accessory" autocomplete="off" spellcheck="false" data-qa-find />'
        . '<ul class="qa-list" data-qa-list></ul></div>'
        . "</div></div>\n";
}

/**
 * What the header shows a logged-in visitor: name, Admin, the unlocks left (with what they unlocked and the free items,
 * for the catalogs' check marks) and the codes of their updates (the account button's dot). Printed into the page by
 * account_box(), so a page view is one request; /auth/status.php answers the same for anything else that asks.
 */
function m3d_status_data(?array $p): array
{
    if (!$p) return ['loggedIn' => false];
    // header counter: downloads left now, per kind (the tightest rule that counts it); only when rules apply to this patron
    $left = null;
    if (!empty($p['access']) && ($l = m3d_downloads_left($p)) !== null) {
        $next = array_filter($l['next']);
        $left = ['models' => $l['models'], 'outfits' => $l['outfits'],
                 'next' => $next ? m3d_site_time(min($next), 'D j M, H:i T') : null, 'nextTs' => $next ? min($next) : null];
        // only items this visitor may know of (in the catalogs, or a page they can open): never a draft's or hidden password item's
        // address in the page; slugs always as strings ("2077" would be a number in the JSON)
        $seen = fn(string $k, string $slug) => ($it = m3d_item($k, $slug)) && (m3d_listed($it) || m3d_can_view($k, $it));
        // what this patron already downloaded = unlocked, free to download again (catalog check marks); with "Updates Use an
        // Unlock" an item with a newer version isn't free any more: no check, only its "↑ Update" tag
        $left['owned'] = ['models' => [], 'outfits' => []];
        foreach (m3d_last_downloads(m3d_patron_history($p)) as $key => [$t, $got]) {
            [$k, $slug] = explode(':', $key, 2);
            // (only for a kind some rule counts: an accessory's new version is free while the rules count only models)
            if (!$seen($k, $slug) || ($l[$k] !== null && m3d_update_needs_unlock($k, m3d_item($k, $slug), $got, $t))) continue;
            $left['owned'][$k][$slug] = true;
        }
        $left['owned'] = array_map(fn($l) => array_map('strval', array_keys($l)), $left['owned']);
        // and the ones that never count (editor: "Exclude from Unlock Limits"): always free too
        $left['free'] = ['models' => [], 'outfits' => []];
        foreach (array_keys(m3d_free_items()) as $key) { [$k, $slug] = explode(':', $key, 2); if ($seen($k, $slug)) $left['free'][$k][] = $slug; }
    }
    return ['loggedIn' => true, 'name' => $p['name'], 'access' => (bool) $p['access'], 'admin' => m3d_is_admin($GLOBALS['M3D']),
            'owner' => ($p['reason'] ?? '') === 'owner',
            'left' => $left,
            // newer versions of what they downloaded, as codes "kind:slug@version", without the ones they ignored: the "↑ Update"
            // tags on catalog cards and the dot on the account button (until downloaded or ignored)
            'updates' => m3d_update_codes(m3d_updates_shown($p))];
}

/**
 * Catalogs (homepage, Accessories): the "Liked" filter row for a logged-in visitor who liked at least one of these items
 * ('' otherwise); the liked addresses ride on the row (data-liked) for site.js. Only for that visitor: pages are never cached.
 */
function liked_row(string $kind, array $items): string
{
    $p = m3d_patron($GLOBALS['M3D']);
    if (!$p || ($p['id'] ?? '') === '') return '';   // a patron who stopped paying keeps seeing what they liked
    $code = m3d_like_code((string) $p['id']);
    $likes = m3d_likes($kind);
    $slugs = [];
    foreach ($items as $it) if (in_array($code, (array) ($likes[$it['slug']] ?? []), true)) $slugs[] = (string) $it['slug'];
    if (!$slugs) return '';
    return chip_row('Liked', 'liked', ['' => 'All', 'yes' => ['label' => 'Liked', 'count' => count($slugs), 'icon' => HEART_ICON, 'class' => 'chip-liked']],
        '', ' data-liked-row data-liked="' . e(json_encode($slugs)) . '"');
}

/** The visitor's side of the likes: {can, reason, csrf?} (the like state printed in the page). */
function m3d_like_viewer(?array $p): array
{
    $can = $p !== null && !empty($p['access']);
    return ['can' => $can, 'reason' => $p === null ? 'login' : ($can ? null : 'patron')] + ($p ? ['csrf' => m3d_csrf()] : []);
}

/**
 * The header's account corner. Logged in: already the name button, Admin and Log out, with data-me (m3d_status_data())
 * from which site.js adds the unlocks counter, the update dot and the admin shortcuts, so the page never flashes
 * "Log in with Patreon" first and needs no second request. Only for the visitor themselves: these pages are never cached.
 */
function account_box(string $path): string
{
    $p = m3d_patron($GLOBALS['M3D']);
    // where "See how to join" (likes refused) leads: the homepage's section when it is on, else the Patreon page, else nowhere
    $join = home_plans()['on'] ? '/#access' : social_url('patreon');
    $join = $join !== '' ? ' data-join="' . e($join) . '"' : '';
    if (!$p) return '      <div class="account" data-account' . $join . '>' . "\n"
        . '        <a class="btn btn-patreon btn-sm" rel="nofollow" href="/auth/login.php?return=' . e(rawurlencode($path)) . '" data-login>Log In with Patreon</a>' . "\n"
        . "      </div>\n";
    $ok = !empty($p['access']);
    $me = m3d_status_data($p);
    if (!empty($p['access'])) $me['csrf'] = m3d_csrf();   // "Ignore this update" (cards, My account → /auth/ignore-update.php) and the My Models hearts; only this visitor gets the page
    return '      <div class="account" data-account' . $join . ' data-session data-me="' . e(json_encode($me, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '">'
        . '<a class="btn btn-sm btn-who' . ($ok ? ' ok' : '') . '" href="/account/" title="My account' . ($ok ? ' · Patron' : '') . '"><span class="who-name">' . e((string) ($p['name'] ?? '')) . '</span></a>'
        . (m3d_is_admin($GLOBALS['M3D']) ? '<a class="btn btn-primary btn-sm" href="/admin/">Admin</a>' : '')
        . '<a class="btn btn-ghost btn-sm" href="/auth/logout.php?return=' . e(rawurlencode($path)) . '">Log Out</a>'
        . "</div>\n";
}

/** How wide catalog cards show (the sizes of their srcset): two columns on phones, about 260 px from there. */
const CARD_SIZES = '(max-width: 520px) 46vw, 260px';

/** Model render, or a generated placeholder (colour from the franchise, initials from the name).
 *  $blur: the picture is swapped for its tiny blurred copy (blur_src), never the real file. */
function cover(array $m, ?string $src, string $size = 'card', bool $eager = false, bool $blur = false, string $tags = '', string $sizes = ''): string
{
    if ($blur && $src) $src = blur_src($src) ?: null;
    $h = 0;
    foreach (str_split((string) ($m['franchise'] ?? '')) as $c) $h = ($h * 31 + ord($c)) % 360;
    $words = preg_split('/\s+(?:and\s+)?/', trim(preg_replace('/\(.*?\)/', '', (string) ($m['name'] ?? ''))));
    $initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(array_filter($words), 0, 2)));
    $gen = isset($m['generation']) && $m['generation'] !== '' ? '<span class="gen">Gen ' . e($m['generation']) . '</span>' : '';
    // every version of the picture with its real width (srcset): the browser takes the one that fits the screen. Big pictures
    // (homepage slideshow, model page: $sizes = how wide they show) mostly get the medium one, cards the thumb; the first loads first
    $srcset = '';
    if ($src && !$blur && ($set = $size === 'hero' ? img_srcset($src, ['sm', 'md', '']) : img_srcset($src, ['xs', 'sm'])) !== '') {
        $srcset = ' srcset="' . e($set) . '" sizes="' . e($sizes ?: ($size === 'hero' ? '(max-width: 860px) 92vw, 580px' : CARD_SIZES)) . '"';
    }
    $inner = $src
        ? '<img src="' . e($src) . '"' . $srcset . ' alt="' . e(($m['name'] ?? '') . ($blur ? ' (picture hidden)' : ' render')) . '" loading="' . ($eager ? 'eager' : 'lazy') . '"' . ($eager ? ' fetchpriority="high"' : '') . ' decoding="async" />'
        : '<div class="ph" aria-hidden="true"><span class="ph-initials">' . e($initials) . '</span></div>';
    // tags over the picture, top left: Gen, then the card's extra ones ($tags: v2, α/β, Password / Teaser; already escaped)
    $tagRow = $gen . $tags !== '' ? '<span class="cover-tags">' . $gen . $tags . '</span>' : '';
    return '<div class="cover cover-' . e($size) . ($blur ? ' is-blurred' : '') . '" style="--h:' . $h . '">' . $inner . $tagRow . '</div>';
}

/** Small "Password" / "Teaser" pill on cards of items that aren't fully public, then the release date one ("Patrons 12 Oct", "Free 20 Oct"). */
function visibility_pill(string $kind, array $item): string
{
    return visibility_pill_only($kind, $item) . release_pill($item);
}

// Moments visitors read (release dates, the next unlock, the daily cap): printed in site time inside a <time>, which site.js
// (localTime()) shows again in the visitor's own time zone and clock (12 / 24 hours as their browser uses). Calendar days the owner types
// (changelog dates) and the admin stay in site time.
const WHEN_FORMATS = ['full' => 'D j M, H:i T', 'at' => 'D j M \\a\\t H:i T', 'day' => 'D j M', 'short' => 'j M', 'date' => 'j M Y', 'long' => 'l j F, H:i T'];

/** A moment for visitors: "Tue 20 Oct, 18:00 CEST" in a <time> that site.js turns into their own time ($style: WHEN_FORMATS key). */
function when_html(int $ts, string $style = 'full'): string
{
    return '<time datetime="' . gmdate('Y-m-d\TH:i:s\Z', $ts) . '" data-lt="' . e($style) . '">' . e(m3d_site_time($ts, WHEN_FORMATS[$style] ?? WHEN_FORMATS['full'])) . '</time>';
}

/** A moment inside a sentence built as plain text: a mark that when_text() turns into when_html() (and when_plain() into site time). */
function when_mark(int $ts, string $style = 'full'): string
{
    return "\x01$ts|$style\x02";
}

/** Plain text with when_mark()s → HTML: escaped, each mark a when_html(). */
function when_text(string $text): string
{
    return preg_replace_callback('/\x01(\d+)\|(\w+)\x02/', fn($m) => when_html((int) $m[1], $m[2]), e($text));
}

/** Plain text with when_mark()s → plain text (attributes): each mark in site time. */
function when_plain(string $text): string
{
    return preg_replace_callback('/\x01(\d+)\|(\w+)\x02/', fn($m) => m3d_site_time((int) $m[1], WHEN_FORMATS[$m[2]] ?? WHEN_FORMATS['full']), $text);
}

/** A title attribute holding a moment ("Coming for patrons on {t}"): in site time, and again in the visitor's own time by site.js. */
function when_title(string $tpl, int $ts, string $style = 'full'): string
{
    return ' title="' . e(str_replace('{t}', m3d_site_time($ts, WHEN_FORMATS[$style] ?? WHEN_FORMATS['full']), $tpl)) . '" data-lt-title="' . e("$ts|$style|$tpl") . '"';
}

const CLOCK_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>';

/** Before its patrons date: "Patrons 12 Oct"; patrons only until its public date: "Free 20 Oct". */
function release_pill(array $item): string
{
    $r = m3d_release($item);
    return match (m3d_release_phase($item)) {
        'soon'  => '<span class="lock-pill rel-pill soon"' . when_title('Coming for patrons on {t}', $r['patrons']) . '>' . CLOCK_ICON . 'Patrons ' . when_html($r['patrons'], 'short') . '</span>',
        'early' => '<span class="lock-pill rel-pill"' . when_title('Patrons only until {t}, then free for everyone', $r['public']) . '>' . CLOCK_ICON . 'Free ' . when_html($r['public'], 'short') . '</span>',
        default => '',
    };
}

function visibility_pill_only(string $kind, array $item): string
{
    return match (m3d_visibility($item)) {
        'password' => m3d_is_unlocked($kind, $item) && !m3d_is_admin($GLOBALS['M3D']) ? '' : '<span class="lock-pill" title="Opens with a password">' . LOCK_ICON . 'Password</span>',
        'blurred'  => '<span class="lock-pill" title="Pictures coming soon">Teaser</span>',
        'blurred-patrons' => m3d_pics_hidden($kind, $item) || m3d_is_admin($GLOBALS['M3D']) ? '<span class="lock-pill" title="Patrons on any tier see the pictures">' . LOCK_ICON . 'Patrons</span>' : '',
        default    => '',
    };
}

const LOCK_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
const CHECK_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>';

/** The Filters button (catalogs, Gallery, bug reports): opens the panel with the id $panel (site.js); the badge counts the filters in use. */
function filter_toggle(string $panel, bool $open = false, int $active = 0): string
{
    return '<button type="button" class="btn btn-ghost filter-toggle" aria-expanded="' . ($open ? 'true' : 'false') . '" aria-controls="' . e($panel)
        . '" aria-label="Filters" title="Filters" data-filter-toggle><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round"/></svg>'
        . '<span class="filter-count" data-filter-count' . ($active ? '' : ' hidden') . '>' . ($active ?: '') . '</span></button>';
}

/**
 * One row of chips in a catalog's Filters panel (homepage, Accessories, Gallery; catalogFilters() in site.js).
 * $chips: value => label, or value => ['label' => …, 'count' => n, 'icon' => html before the label, 'attrs' => extra attributes, 'class' => …].
 * The first chip is the default (no filter). $current = the value picked; an array makes a row where several can be on (none = the first chip).
 */
function chip_row(string $label, string $pick, array $chips, string|array $current = '', string $attrs = ''): string
{
    $multi = is_array($current);
    $values = array_map('strval', array_keys($chips));
    $picked = array_values(array_intersect($multi ? array_map('strval', $current) : [$current], $values));
    if (!$picked || (!$multi && $picked[0] === $values[0])) $picked = [$values[0]];
    $out = '<div class="chips chips-gen" role="group" aria-label="' . e($label) . '"' . ($multi ? ' data-multi' : '') . $attrs . '><span class="chips-lbl">' . e($label) . '</span>';
    foreach ($chips as $value => $c) {
        $value = (string) $value;
        $c = is_array($c) ? $c : ['label' => $c];
        $out .= '<button type="button" class="chip' . (isset($c['class']) ? ' ' . e($c['class']) : '') . '" aria-pressed="' . (in_array($value, $picked, true) ? 'true' : 'false')
            . '" data-pick="' . e($pick) . '" data-value="' . e($value) . '"' . ($value === $values[0] ? ' data-default' : '') . ($c['attrs'] ?? '') . '>'
            . ($c['icon'] ?? '') . e((string) $c['label']) . (isset($c['count']) ? ' <span>' . (int) $c['count'] . '</span>' : '') . '</button>';
    }
    return $out . '</div>';
}

function model_card(array $m): string
{
    $s = status_info(m3d_status($m));
    return '<a class="card" href="/models/' . e($m['slug']) . '/" data-card data-slug="' . e($m['slug']) . '"'
        . ' data-name="' . e(mb_strtolower((string) $m['name'])) . '"'
        . ' data-franchise="' . e($m['franchise'] ?? '') . '"'
        . ' data-generation="' . e($m['generation'] ?? '') . '"'
        . ' data-status="' . e(m3d_status($m)) . '"'
        . ' data-order="' . e($m['order'] ?? 999) . '"'
        . ' data-downloads="' . m3d_downloads('models', (string) $m['slug'])['total'] . '"'
        . ' data-updated="' . m3d_updated_ts($m) . '"'   // "Recently updated" sort
        . ' data-likes="' . ($likes = m3d_like_count((string) $m['slug'])) . '">'
        // v2, α/β (latest version) and Password / Teaser sit on the picture next to Gen, so every card body has the same rows:
        // franchise, name, status with the likes on the right
        . cover($m, img_small(item_pic('models', $m)) ?: null, 'card', false, m3d_pics_hidden('models', $m),
            (m3d_model_versions($m) ? '<span class="ver-tag" title="Major version">v' . (int) $m['majorVersion'] . '</span>' : '')
            . stage_badge(m3d_current_version($m)['stage'], true) . visibility_pill('models', $m))
        . '<div class="card-body"><p class="franchise">' . e($m['franchise'] ?? '') . '</p>'
        . '<h3>' . e($m['name']) . '</h3>'
        . '<span class="card-meta"><span class="badge tone-' . e($s['tone']) . '">' . e($s['label']) . '</span>'
        . comments_pill('models', $m, (string) $m['slug']) . likes_pill($likes)
        . '</span></div></a>';
}

/** Changelog highlights become short badges; the original sentence stays as a tooltip. */
function highlight_badge(?string $h): string
{
    if (!$h) return '';
    $same = preg_match('/textur/i', $h) && preg_match('/\b(not|no|un|without)\b.{0,20}(chang|new|updat)|unchanged|same textur/i', $h);
    if ($same) [$label, $tone] = ['Same Textures', 'grey'];                                  // "textures are not changed": keep yours
    elseif (preg_match('/textur/i', $h)) [$label, $tone] = ['New Textures', 'sky'];          // means: download the textures again
    elseif (m3d_mustardui_on() && preg_match('/mustardui/i', $h)) [$label, $tone] = ['New MustardUI', 'violet'];   // only with the MustardUI page on
    elseif (preg_match('/(first|initial) release/i', $h)) [$label, $tone] = ['First Release', 'green'];
    elseif (preg_match('/last blender ([\d.]+)/i', $h, $mm)) [$label, $tone] = ['Last for Blender ' . $mm[1], 'grey'];
    elseif (preg_match('/in develop/i', $h)) [$label, $tone] = ['In Development', 'grey'];
    else [$label, $tone] = [rtrim($h, '!.'), 'grey'];
    return '<span class="badge tone-' . $tone . '" title="' . e($h) . '">' . e($label) . '</span>';
}

/**
 * "Fix: ..." / "New: ..." prefixes on changelog lines become small coloured tags; report numbers before the colon ("Fix #6: ...",
 * "Fix #6, #7: ...") start the text as chips linking to the reports, and any other "#6" in the line becomes one too (bug_refs_html()).
 * The tags make a column (site.css: the version's lines share a grid), so every text starts at the same place.
 */
function change_line(string $line): string
{
    $tones = ['new' => 'new', 'feature' => 'new', 'fix' => 'fix', 'bug' => 'fix', 'enhancement' => 'enh', 'improvement' => 'enh',
              'optimization' => 'opt', 'removal' => 'rem', 'ui' => 'ui', 'outfit' => '', 'outfits' => '', 'physics' => '', 'note' => ''];
    if (preg_match('/^(New|Feature|Fix|Bug|Enhancement|Improvement|Optimization|Removal|UI|Outfits?|Physics|Note)(\s*\([^)]*\))?((?:\s*,?\s*#\d{1,6})*):\s+(.*)$/s', $line, $m)) {
        preg_match_all('/#(\d{1,6})/', $m[3], $refs);
        $chips = implode('', array_map(fn($n) => bug_ref_link((int) $n, true), $refs[1]));
        return '<li><span class="tag tag-' . $tones[strtolower($m[1])] . '">' . e($m[1] . ($m[2] ?? '')) . '</span><span class="chg md">' . $chips . bug_refs_html(md_inline($m[4])) . '</span></li>';
    }
    return '<li class="no-tag"><span class="chg md">' . bug_refs_html(md_inline($line)) . '</span></li>';
}

/** Gallery / notes entries can be a plain string or an object. */
function as_image(mixed $g): array
{
    return is_array($g) ? ['src' => (string) ($g['src'] ?? ''), 'caption' => (string) ($g['caption'] ?? '')] : ['src' => (string) $g, 'caption' => ''];
}

function as_note(mixed $n): array
{
    return is_array($n) ? ['text' => (string) ($n['text'] ?? ''), 'image' => (string) ($n['image'] ?? '')] : ['text' => (string) $n, 'image' => ''];
}

/** Buttons a homepage box can show (Admin → Settings → Homepage section): key => label. */
const PLAN_BUTTONS = ['discord' => 'Join the Discord', 'patreon' => 'Join on Patreon', 'login' => 'Already a Patron? Log In'];

/**
 * The "How to get the models" section of the homepage (Admin → Settings → Homepage section, settings.json → plans):
 * {on, eyebrow, title, boxes: [{tag, title, text, items [], buttons [discord|patreon|login], fine}]}; the second box is highlighted.
 * Shown only to visitors who can't download yet.
 */
function home_plans(): array
{
    $p = m3d_settings()['plans'] ?? [];   // not set: hidden
    $box = fn($b) => ['tag' => (string) ($b['tag'] ?? ''), 'title' => (string) ($b['title'] ?? ''), 'text' => (string) ($b['text'] ?? ''),
        'items' => array_values(array_filter(array_map('strval', (array) ($b['items'] ?? [])), fn($x) => trim($x) !== '')),
        'buttons' => array_values(array_intersect((array) ($b['buttons'] ?? []), array_keys(PLAN_BUTTONS))), 'fine' => (string) ($b['fine'] ?? '')];
    $boxes = array_map($box, array_slice((array) ($p['boxes'] ?? []), 0, 2));
    while (count($boxes) < 2) $boxes[] = $box([]);
    return ['on' => !empty($p['on']), 'eyebrow' => (string) ($p['eyebrow'] ?? ''), 'title' => (string) ($p['title'] ?? ''), 'boxes' => $boxes];
}

/** Import from SmutBase (Admin → Models button, admin/import.php): Admin → Settings → SmutBase import, off until turned on. */
function m3d_smutbase_on(): bool
{
    return !empty(m3d_settings()['smutbase']['on']);
}

/** The MustardUI page (/mustardui/, menu and footer item): Admin → Settings → MustardUI, off until turned on. */
function m3d_mustardui_on(): bool
{
    return !empty(m3d_settings()['mustardui']['on']);
}

/** Links under the docs page list (Admin → Docs → Links under the page list, settings.json → docs_links): [[label, address]].
 *  Until set: FAQ (when it has questions) and Contacts. */
function docs_links(): array
{
    $saved = m3d_settings()['docs_links'] ?? null;
    if (is_array($saved)) return array_values(array_filter(array_map(fn($l) => [trim((string) ($l['label'] ?? '')), trim((string) ($l['url'] ?? ''))], $saved), fn($l) => $l[0] !== '' && $l[1] !== ''));
    return array_merge(m3d_has_faq() ? [['FAQ', SITE['faqs']]] : [], [['Contacts', SITE['contacts']]]);
}

/** A link of a links list: other websites open with rel=noopener and get the ↗ after the text. */
function link_html(string $label, string $url): string
{
    $ext = (bool) preg_match('#^https?://#i', $url);
    return '<a href="' . e($url) . '"' . ($ext ? ' rel="noopener"' : '') . '>' . e($label) . ($ext ? ' <span class="ext" aria-hidden="true">↗</span>' : '') . '</a>';
}

function not_found(): never
{
    http_response_code(404);
    layout_start('Page Not Found', 'This page does not exist.', 'page-gate');
    echo '<section class="wrap gate"><p class="eyebrow">404</p><h1>Page Not Found</h1><p class="lead">This page doesn’t exist, or the model was renamed.</p><div class="cta"><a class="btn btn-primary" href="/#catalog">Browse All Models</a></div></section>';
    layout_end();
    exit;
}

/**
 * Visibility check at the top of a model/outfit page: drafts are a 404 except for admins; password items show the
 * password form (and check it when sent) until the visitor entered it. Returns only when the page may be shown.
 */
function item_gate(string $kind, array $item): void
{
    $v = m3d_visibility($item);
    if (m3d_release_hidden($item) && !m3d_is_admin($GLOBALS['M3D'])) not_found();   // before its patrons date, hidden like a draft
    if (!in_array($v, ['public', 'blurred', 'blurred-patrons'], true) || m3d_release_hidden($item)) header('X-Robots-Tag: noindex');   // password and draft pages; teasers (blurred) and "Coming Soon" pages may be listed, like in the sitemap
    if (!m3d_can_view($kind, $item) && $v !== 'password') not_found();
    if (m3d_can_view($kind, $item)) return;
    $wrong = false;
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        if (m3d_unlock($kind, $item, (string) ($_POST['password'] ?? ''))) {
            m3d_redirect(m3d_item_url($kind, $item['slug']), 303);
        }
        $wrong = true;
    }
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    $src = $kind === 'outfits' ? outfit_preview($item) : img_small(item_pic($kind, $item));
    http_response_code($wrong ? 403 : 200);
    layout_start($item['name'], 'This ' . $what . ' is password protected.', 'page-gate');
    ?>
  <section class="wrap gate lock-gate">
    <div class="lock-art"><?= cover($kind === 'outfits' ? ['name' => $item['name'], 'franchise' => m3d_accessory_type($item)] : $item, $src ?: null, 'card', true, true) ?></div>
    <p class="eyebrow"><?= LOCK_ICON ?> Password Protected</p>
    <h1><?= e($item['name']) ?></h1>
    <p class="lead">This <?= $what ?> is only open to people who have the password.</p>
    <form class="lock-form" method="post" action="">
      <label class="sr-only" for="lock-pw">Password</label>
      <input class="input" id="lock-pw" type="password" name="password" placeholder="Password" required autofocus autocomplete="current-password" />
      <button class="btn btn-primary" type="submit">Open</button>
    </form>
    <?php if ($wrong): ?><p class="lock-error" role="alert">That password isn’t right. Try again.</p><?php endif ?>
    <p class="fine">Entering the password stores a small cookie so this <?= $what ?> stays open on this device for <?= M3D_UNLOCK_DAYS ?> days (<a href="<?= e(SITE['privacy']) ?>">privacy policy</a>).</p>
  </section>
<?php
    layout_end();
    exit;
}

/** Admins previewing the site as a patron: the bar on top to change the made-up membership or stop (see m3d_preview()). */
function preview_bar(string $return): string
{
    $pv = m3d_preview();
    if ($pv === null) return '';
    $opts = '';
    foreach (PREVIEW_TIERS as $k => $label) $opts .= '<option value="' . e($k) . '"' . (($pv['tier'] ?? 'ok') === $k ? ' selected' : '') . '>' . e($label) . '</option>';
    $total = (int) ($pv['total_cents'] ?? 0);
    $price = (int) ($pv['tier_cents'] ?? 500);
    $amt = fn(int $c) => $c % 100 ? number_format($c / 100, 2, '.', '') : (string) intdiv($c, 100);
    // the real patron: shown as "Name · ID" when the site knows the name; a name that found nobody (or several) stays, with a note
    [$miss, $many] = explode("\x1f", (string) ($pv['as_miss'] ?? '')) + [1 => ''];
    $asId = (string) ($pv['as'] ?? '');
    $asVal = $miss !== '' ? $miss : ($asId !== '' ? m3d_patron_pick($asId, m3d_known_patrons()[$asId] ?? '') : '');
    return '<form class="preview-bar" method="post" action="/admin/patron-preview.php">'
        . '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="return" value="' . e(m3d_safe_return($return, '/')) . '" />'
        . '<b class="pv-title">Preview</b>'
        . '<label><span>Membership</span><select name="tier">' . $opts . '</select></label>'
        . '<label title="The price of the paid tier you preview with, for models with a minimum tier (Access in the editor)."><span>Tier (' . e(m3d_currency()) . ')</span><input type="number" name="price" min="0.01" step="any" value="' . e($amt($price)) . '" /></label>'
        . '<label title="Months as a patron."><span>Months</span><input type="number" name="months" min="0" max="999" value="' . (int) ($pv['months'] ?? 0) . '" /></label>'
        . '<label title="Total support given over all the months."><span>Total (' . e(m3d_currency()) . ')</span><input type="number" name="total" min="0" step="any" value="' . e($amt($total)) . '" /></label>'
        . '<label title="Models downloaded: counted as downloaded a minute ago (other models than the one you look at), so they count in every limit period."><span>Models</span><input type="number" name="dl_models" min="0" max="99" value="' . (int) ($pv['dl_models'] ?? 0) . '" /></label>'
        . '<label title="Accessories downloaded: counted as downloaded a minute ago, so they count in every limit period."><span>Accessories</span><input type="number" name="dl_outfits" min="0" max="99" value="' . (int) ($pv['dl_outfits'] ?? 0) . '" /></label>'
        . '<label title="This many of your latest updated models/accessories count as downloaded before their latest version: the account button gets the dot and My Models shows them as updates."><span>Updates</span><input type="number" name="updates" min="0" max="20" value="' . (int) ($pv['updates'] ?? 0) . '" /></label>'
        . '<label class="pv-who" title="Optional: a real patron, by Patreon user ID or name (names of people who downloaded or wrote a bug report). Adds that patron’s real downloads (limits, Unlocked) and their special access (Admin → Access)."><span>Patreon ID or Name</span><input name="as" maxlength="80" placeholder="optional" list="pv-known" autocomplete="off" value="' . e($asVal) . '"' . ($miss !== '' ? ' class="is-miss" aria-describedby="pv-miss"' : '') . ' /></label>'
        . ($miss !== '' ? '<span class="pv-miss" id="pv-miss">' . ($many ? 'Several match: pick one' : 'Not found: type the ID') . '</span>' : '')
        . '<datalist id="pv-known">' . m3d_patron_options() . '</datalist>'
        . '<span class="pv-btns"><button class="btn btn-primary btn-sm" name="do" value="apply">Apply</button><button class="btn btn-ghost btn-sm" name="do" value="stop">Stop</button></span>'
        . '</form>';
}

/** In preview, a download stops here: what would happen, without downloading, logging or counting anything. */
function preview_download_page(string $kind, array $item, string $label, ?string $back = null): never
{
    $page = $back ?? m3d_item_url($kind, $item['slug']);
    header('Cache-Control: no-store');
    layout_start('Download · ' . $item['name'], 'Preview of a download.', 'page-gate');
    ?>
  <section class="wrap gate">
    <p class="eyebrow">Preview</p>
    <h1>The Download Would Start Now</h1>
    <p class="lead"><?= e($item['name']) ?><?= $label !== '' ? ' · ' . e($label) : '' ?>: allowed. Nothing is downloaded or logged in the preview.</p>
    <div class="cta"><a class="btn btn-ghost" href="<?= e($page) ?>">Back to <?= $back !== null ? 'the Gallery' : e($item['name']) ?></a></div>
  </section>
<?php
    layout_end();
    exit;
}

/** Under the blurred pictures of a "clear for patrons" item: how to see them. */
function patron_pics_note(string $kind, array $item): string
{
    if (m3d_visibility($item) !== 'blurred-patrons' || !m3d_pics_hidden($kind, $item)) return '';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $cta = m3d_patron($GLOBALS['M3D'])
        ? (social_url('patreon') !== '' ? '<a href="' . e(social_url('patreon')) . '" rel="noopener">Join on Patreon</a>' : '')
        : '<a rel="nofollow" href="/auth/login.php?return=' . e(rawurlencode($path)) . '">Log in with Patreon</a>';
    return '<p class="pics-note">' . LOCK_ICON . '<span>Patrons on any tier see the full pictures. ' . $cta . '</span></p>';
}

/** Text under the title of the Accessories page (Admin → Accessories → Page text, settings.json → outfits_intro; '' = none). */
const OUTFITS_INTRO_DEFAULT = 'Outfits, hair styles and other accessories made for the models. Pick a model to see what fits it.';

function outfits_intro(): string
{
    $t = m3d_settings()['outfits_intro'] ?? null;
    return is_string($t) ? $t : OUTFITS_INTRO_DEFAULT;
}

/** "Your download starts in…" page (auth/get.php). $go = the same /get/ address with the signed ?t=: never the real link.
 *  $confirm (limit rows): only the "Unlock this model?" question (one line, `dl_unlock_line()`), whose Yes ($go, c = m3d_dl_confirm_code()) comes back
 *  to the server for the countdown.
 *  $back: the page to go back to when it isn't the item's own (gallery pictures: /gallery/).
 *  $update: the question is for a newer version of an item already unlocked ("Updates Use an Unlock"): "Unlock this update?". */
function download_wait_page(string $kind, array $item, string $label, int $seconds, string $go, ?array $confirm = null, ?string $back = null, bool $update = false): never
{
    $page = $back ?? m3d_item_url($kind, $item['slug']);
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    header('Cache-Control: no-store');
    layout_start('Download · ' . $item['name'], 'Your download is starting.', 'page-gate');
    ?>
  <section class="wrap gate dl-wait" data-dl-wait="<?= (int) $seconds ?>" data-dl-go="<?= e($go) ?>">
    <p class="eyebrow">Download</p>
    <h1><?= e($item['name']) ?></h1>
<?= $back === null ? dl_wait_facts($item) : '' ?>
    <?php if ($confirm): ?>
      <div data-dl-ask>
        <p class="lead"><?= e(dl_unlock_line($confirm)) ?> Unlock this <?= $update ? 'update' : $what ?>?</p>
        <div class="cta">
          <a class="btn btn-primary" href="<?= e($go) ?>">Yes, Unlock</a>
          <a class="btn btn-ghost" href="<?= e($page) ?>">Not Now</a>
        </div>
      </div>
    <?php else: ?>
    <div data-dl-count>
      <p class="lead"><?= $label !== '' ? e($label) . ': y' : 'Y' ?>our download starts in <b data-dl-left><?= (int) $seconds ?></b> <span data-dl-unit><?= $seconds === 1 ? 'second' : 'seconds' ?></span>.</p>
      <div class="dl-bar" aria-hidden="true"><span data-dl-bar></span></div>
      <div class="cta">
        <a class="btn btn-primary" href="<?= e($go) ?>" data-dl-now aria-disabled="true">Download</a>
        <a class="btn btn-ghost" href="<?= e($page) ?>">Back to <?= $back !== null ? 'the Gallery' : e($item['name']) ?></a>
      </div>
      <p class="fine">Didn’t start? Use the Download button once the countdown ends.</p>
    </div>
    <?php endif ?>
<?= $back === null ? dl_wait_news($kind, $item) : '' ?>
  </section>
<?php
    layout_end();
    exit;
}

/**
 * The waiting page's "what's new" box, under the countdown: for a patron who downloaded an older version, the versions out since
 * ("New Since Your Download", m3d_newer_versions(), like My account's update row), else the first changes of the current version;
 * a few lines, then how many more and a link to that version in the changelog. '' when there are no changes to show.
 */
function dl_wait_news(string $kind, array $item): string
{
    $log = m3d_released_versions($item);
    if (!$log) return '';
    $p = m3d_patron($GLOBALS['M3D']);
    $last = $p && !empty($p['access']) ? (m3d_last_downloads(m3d_patron_history($p))[$kind . ':' . $item['slug']] ?? null) : null;
    $newer = $last ? m3d_newer_versions($item, $last[1], $last[0]) : [];
    $groups = $newer ?: [$log[0]];
    $budget = $newer ? 4 : 3;   // lines shown in all
    $body = '';
    $more = 0;
    foreach ($groups as $en) {
        $changes = array_values(array_filter(array_map(fn($c) => trim((string) $c), (array) ($en['changes'] ?? [])), fn($c) => $c !== ''));
        if (!$changes) continue;
        if ($budget <= 0) { $more += count($changes); continue; }
        $shown = array_slice($changes, 0, $budget);
        $budget -= count($shown);
        $more += count($changes) - count($shown);
        if ($newer) {
            $ts = m3d_changelog_ts((string) ($en['date'] ?? ''));
            $body .= '<p class="dl-new-v"><b>' . e(bug_version_words(m3d_version_label($en))) . '</b>' . ($ts ? ' · ' . e(m3d_site_time($ts, 'j M Y')) : '') . '</p>';
        }
        $body .= '<div class="log"><ul>' . implode('', array_map('change_line', $shown)) . '</ul></div>';
    }
    if ($body === '') return '';
    $title = $newer ? 'New Since Your Download' : 'What’s New in ' . bug_version_words(m3d_version_label($log[0]));
    $foot = array_filter([
        $newer && $last[1] !== '' ? 'You had ' . e(bug_version_words($last[1])) : '',
        $more ? e(m3d_plural($more, 'more change')) : '',
    ]);
    $link = '<a href="' . e(m3d_version_url($kind, $item, m3d_version_label($groups[0]))) . '">See the Changelog →</a>';
    return '    <div class="dl-new"><h2>' . $title . '</h2>' . $body . '<p class="dl-new-more">'
        . ($foot ? '<span class="muted">' . implode(' · ', $foot) . ' ·</span> ' : '') . $link . "</p></div>\n";
}

/** The waiting page's line under the name: "Version 3 · 13 Oct 2026   Blender 4.2   Needs MustardUI ↗" (each part only when known). */
function dl_wait_facts(array $item): string
{
    $parts = [];
    $v = m3d_current_version($item);
    if (($label = m3d_version_label($v)) !== '') {
        $ts = m3d_changelog_ts((string) ($v['date'] ?? ''));
        $parts[] = 'Version <b>' . e($label) . '</b>' . ($ts ? ' · ' . e(m3d_site_time($ts, 'j M Y')) : '');
    }
    if (($bl = trim((string) ($item['blender'] ?? ''))) !== '') $parts[] = 'Blender <b>' . e($bl) . '</b>';
    $addons = [];
    foreach (m3d_addons($item) as $a) {
        if (empty($a['url']) || empty($a['label'])) continue;
        $ext = !str_starts_with((string) $a['url'], '/');
        $addons[] = '<a href="' . e($a['url']) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . e($a['label']) . ($ext ? ' ↗' : '') . '</a>';
    }
    if ($addons) $parts[] = 'Needs ' . implode(', ', $addons);
    return $parts ? '    <p class="dl-facts">' . implode('', array_map(fn($x) => '<span>' . $x . '</span>', $parts)) . "</p>\n" : '';
}

/** The line above "Unlock this model?": what the tightest rule has left ($rows = m3d_check_limits() rows, at least one
 *  left), "This uses 1 of your 3 model unlocks left." / "This uses your last model unlock." */
function dl_unlock_line(array $rows): string
{
    $tight = m3d_tightest_row($rows);
    $left = max(0, $tight['max'] - $tight['used']);
    $noun = m3d_rule_noun($tight['rule']);
    return $left <= 1 ? "This uses your last {$noun}unlock." : "This uses 1 of your $left {$noun}unlocks left.";
}

/** A /get/ address with nothing to download: a plain answer (404 unknown or switched off, 503 not ready yet). */
function dl_unavailable(int $code, string $msg): never
{
    http_response_code($code);
    exit($msg);
}

/**
 * The waiting page of a /get/ address (auth/get.php, auth/get-still.php): shown until the signed link in ?t= is old enough (a new
 * one when missing, wrong or expired); returns once the download may start. $confirm (limit rows): the download will use one of the
 * patron's unlocks, so the page first asks "Unlock this model?"; its Yes comes back here with c (m3d_dl_confirm_code()), and the
 * countdown starts then. One download at a time: while another download of this visitor counts down, a new one is refused
 * (download_busy_page()). $back: where the pages' back button goes (null = the item's page). $free: a public link (anyone downloads it):
 * no countdown, so it returns at once and never holds or waits for another download.
 */
function dl_wait_gate(string $kind, string $slug, int $n, array $item, string $label, string $here, ?array $confirm = null, bool $update = false, ?string $back = null, bool $free = false): void
{
    $wait = $free ? 0 : m3d_dl_settings()['wait'];
    $code = m3d_dl_code($kind, $slug, $n);
    // the version out now is part of it: an old Yes link (browser history) doesn't skip the question for a newer version
    $cCode = $confirm !== null ? m3d_dl_confirm_code($code . '@' . m3d_released_label($item)) : null;
    $confirmed = $confirm === null || hash_equals($cCode, m3d_get_text('c'));
    $token = m3d_get_text('t');
    $left = 0;
    if ($wait > 0) {
        $left = $confirmed ? m3d_dl_token_wait($token, $kind, $slug, $n, $wait) : null;
        if ($left === null) {   // a new wait (or the question before it)
            if ($busy = m3d_dl_busy($code)) download_busy_page($kind, $item, $busy, $back);
            $token = m3d_dl_token($kind, $slug, $n);
            $left = $wait;
        }
    }
    // a finished countdown kept from earlier doesn't jump the queue either
    if ($left === 0 && $confirmed && $wait > 0 && ($busy = m3d_dl_busy($code))) download_busy_page($kind, $item, $busy, $back);
    if ($left === 0 && $confirmed) { m3d_dl_free($code); return; }
    if ($confirmed) m3d_dl_hold($code, (string) $item['name'], $left);
    $go = $here . '?' . http_build_query(array_filter(['c' => $cCode, 't' => $wait > 0 && $confirmed ? $token : null]));
    download_wait_page($kind, $item, $label, $left, $go, $confirmed ? null : $confirm, $back, $update);
}

/** One download at a time (auth/get.php, auth/get-still.php): another download of this visitor is still counting down ($busy =
 *  m3d_dl_busy()), so this one is refused; the visitor goes back and tries again once it has started. */
function download_busy_page(string $kind, array $item, array $busy, ?string $back = null): never
{
    $page = $back ?? m3d_item_url($kind, $item['slug']);
    $other = $busy['name'] !== '' ? $busy['name'] : 'another item';
    header('Cache-Control: no-store');
    layout_start('Download · ' . $item['name'], 'One download at a time.', 'page-gate');
    ?>
  <section class="wrap gate">
    <p class="eyebrow">One Download at a Time</p>
    <h1>You Can’t Download This Yet</h1>
    <p class="lead">Your download of <b><?= e($other) ?></b> is still counting down. Wait until it starts, then try again.</p>
    <div class="cta"><a class="btn btn-primary" href="<?= e($page) ?>">Back to <?= $back !== null ? 'the Gallery' : e($item['name']) ?></a></div>
  </section>
<?php
    layout_end();
    exit;
}

/** Links and note of the "Getting started" box for 'models' or 'outfits' (settings.json → start.<kind>; empty until set). note_on: false when the
 *  line is switched off (`note_off`, the text is kept). */
function start_box(string $kind): array
{
    $s = m3d_settings()['start'][$kind] ?? [];
    if (!is_array($s)) $s = [];
    return ['links' => array_map(fn($l) => [(string) ($l['label'] ?? ''), (string) ($l['url'] ?? '')], (array) ($s['links'] ?? [])), 'note' => (string) ($s['note'] ?? ''), 'note_on' => empty($s['note_off'])];
}

/** The "Getting started" box (nothing when it has no links and no note). */
function start_box_html(string $kind): string
{
    $b = start_box($kind);
    if (!$b['note_on']) $b['note'] = '';
    if (!$b['links'] && trim($b['note']) === '') return '';
    $links = '';
    foreach ($b['links'] as [$label, $url]) {
        $ext = preg_match('#^https?://#', $url) ? ' rel="noopener"' : '';
        $links .= '<a href="' . e($url) . '"' . $ext . '>' . e($label) . '</a>';
    }
    return '<div class="start"><p class="start-title">Getting Started</p>'
        . ($links !== '' ? '<div class="start-links">' . $links . '</div>' : '')
        . (trim($b['note']) !== '' ? '<p class="small muted md">' . md_inline($b['note']) . '</p>' : '') . '</div>';
}

/** A message after an action ("Saved.", "Not saved: …"), never in the page: it floats at the bottom with a ✕ (ok / info fade out by
 *  themselves, errors stay until closed; site.js / admin.js). $html is already escaped. Printed by layout_end() and admin_end(). */
function site_flash(string $html, string $kind = 'ok'): void
{
    $GLOBALS['m3d_flash'][] = [in_array($kind, ['ok', 'info', 'err'], true) ? $kind : 'info', $html];
}

/** The floating messages of site_flash(), in the stack toast() (admin.js) adds to too. */
function flash_stack(): string
{
    $h = '';
    foreach ($GLOBALS['m3d_flash'] ?? [] as [$kind, $html]) {
        $h .= '<div class="toast ' . $kind . '" role="' . ($kind === 'err' ? 'alert' : 'status') . '"><span class="toast-msg">' . $html . '</span>'
            . '<button type="button" class="toast-x" aria-label="Close" title="Close">✕</button></div>';
    }
    return '  <div class="toasts" data-toasts>' . $h . "</div>\n";
}

/** Note for the admin on pages that visitors see differently (or not at all). */
function visibility_banner(string $kind, array $item): string
{
    $v = m3d_visibility($item);
    if (!m3d_is_admin($GLOBALS['M3D'])) return '';
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    if ($v === 'public') {   // just published from this banner
        if (isset($_GET['published'])) site_flash('✓ Published: everyone can see this ' . $what . ' now.');
        return '';
    }
    $text = match ($v) {
        'draft'    => 'Draft: only admins can see this ' . $what . '.',
        'password' => 'Password protected' . (!empty($item['unlisted']) ? ', not in the catalog.' : '.'),
        'blurred'  => 'Teaser: pictures blurred for everyone.',
        'blurred-patrons' => 'Pictures blurred except for patrons.',
    };
    // Publish straight from the page (admin/publish.php); "Change" opens the editor for the other choices
    $ask = $v === 'password' ? ' onsubmit="return confirm(\'Remove the password and make it public?\')"' : '';
    return '<div class="vis-banner">' . LOCK_ICON . '<span>' . e($text) . ' <a href="/admin/edit.php?' . ($kind === 'outfits' ? 'kind=outfits&amp;' : '') . 'slug=' . e(rawurlencode($item['slug'])) . '">Change</a></span>'
        . '<form method="post" action="/admin/publish.php" class="vis-publish"' . $ask . '><input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" />'
        . '<input type="hidden" name="kind" value="' . e($kind) . '" /><input type="hidden" name="slug" value="' . e($item['slug']) . '" />'
        . '<button class="btn btn-primary btn-sm">' . ($v === 'draft' ? 'Publish' : 'Make Public') . '</button></form></div>';
}

/**
 * Model/outfit pages: the main picture. $slides = the viewer's set, the main picture first (a click opens it); blurred pictures
 * show only a tiny copy with the patrons note, and no viewer. $card = what cover() needs for the placeholder (name, franchise).
 */
function item_media(string $kind, array $item, array $card, string $main, array $slides, bool $blur): string
{
    // 3D preview (app/model3d.php): only while the pictures are public (anyone who sees it can save the file); admins also on drafts / password items
    $m3 = !$blur && (m3d_visibility($item) === 'public' || m3d_is_admin($GLOBALS['M3D'])) ? m3d_model3d($item) : null;
    $out = '<div class="media' . ($m3 ? ' has-3d' : '') . '" data-slides="' . ($blur ? '[]' : slides_attr($slides)) . '">';
    if ($blur) $out .= cover($card, $main ?: null, 'hero', true, true) . patron_pics_note($kind, $item);
    elseif ($main !== '') $out .= '<button class="cover-btn" data-slide="0" aria-label="Open images">' . cover($card, $main, 'hero', true)
        . (count($slides) > 1 ? '<span class="count-pill">' . count($slides) . ' images</span>' : '') . '</button>';
    else $out .= cover($card, null, 'hero', true);
    if ($m3) $out .= '<button type="button" class="v3d-btn" data-v3d="' . e($m3['src']) . '" data-v3d-name="' . e($item['name'] ?? '') . '" data-v3d-exposure="' . e(m3d_exposure($item))
        . '" data-v3d-lib="' . e(asset('vendor/model-viewer.min.js')) . '">'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 7v10l9 5 9-5V7l-9-5Zm0 2.3L18.7 8 12 11.7 5.3 8 12 4.3ZM5 9.7l6 3.3v6.6l-6-3.3V9.7Zm8 9.9V13l6-3.3v6.6l-6 3.3Z"/></svg>'
        . 'View in 3D<span class="v3d-size">' . e(m3d_mb($m3['bytes'])) . '</span></button>';
    return $out . '</div>';
}

/**
 * Model/outfit pages: the pictures under the main one, in one row across the page; ‹ › scroll it (site.js, [data-strip]).
 * $pics = those pictures (as_image()), $first = the viewer's slide of the first one (1 after a main picture), $slides = the viewer's
 * set; blurred: tiny copies, no viewer. Nothing when there are no pictures.
 */
function gallery_strip(string $name, array $pics, int $first, array $slides, bool $blur): string
{
    $thumbs = '';
    foreach (array_values($pics) as $i => $g) {
        if ($blur) {
            if (($b = blur_src($g['src'])) !== '') $thumbs .= '<span class="thumb is-blurred"><img src="' . e($b) . '" alt=""' . img_ratio_style(img_small($g['src'])) . ' /></span>';
            continue;
        }
        $thumbs .= '<button class="thumb" data-slide="' . ($i + $first) . '" aria-label="Open image ' . ($i + $first + 1) . '">'
            . strip_img($g['src'], $g['caption'] ? "$name: {$g['caption']}" : "$name, picture " . ($i + $first + 1))
            . ($g['caption'] ? '<span class="cap">' . e($g['caption']) . '</span>' : '') . '</button>';
    }
    if ($thumbs === '') return '';
    return strip_html('gallery-strip', 'gallery', $thumbs, 'pictures', 'section', ' data-slides="' . ($blur ? '[]' : slides_attr($slides)) . '" aria-label="Gallery"');
}

/**
 * Every row with ‹ › (the gallery row, "Optimized for", the "More from" and liked card rows): $inner in one row that scrolls
 * sideways, the arrows over its ends while it doesn't fit, going round at the ends (the [data-strip] script in site.js; swipe on phones).
 * $what = the arrows' word ("pictures", "models"); $attrs = more attributes for the outer tag, already escaped.
 */
function strip_html(string $class, string $rowClass, string $inner, string $what, string $tag = 'div', string $attrs = ''): string
{
    return '<' . $tag . ' class="' . e($class) . '" data-strip' . $attrs . '>'
        . '<button type="button" class="strip-arrow strip-prev" data-strip-prev aria-label="Previous ' . e($what) . '" hidden>‹</button>'
        . '<div class="' . e($rowClass) . '" data-strip-row>' . $inner . '</div>'
        . '<button type="button" class="strip-arrow strip-next" data-strip-next aria-label="More ' . e($what) . '" hidden>›</button>'
        . '</' . $tag . '>';
}

/** Model/outfit pages, the facts list: "Latest version" (links to the changelog) and "Downloads" (all-time count); '' when there is none. */
function fact_latest(array $item): string
{
    $v = m3d_current_version($item);   // a version dated in the future isn't out yet (downloads still give this one)
    if ($v['version'] === '') return '';
    $id = strstr(m3d_version_url('models', $item, m3d_version_label($v)), '#');   // this page's #v4 (the kind doesn't matter for the anchor)
    return '<dt>Latest Version</dt><dd>' . stage_badge($v['stage']) . ' <a class="ver-link" href="' . e($id) . '">' . e($v['version']) . '</a>'
        . ($v['date'] !== '' ? '<span class="muted"> · ' . e($v['date']) . '</span>' : '') . '</dd>';
}

function fact_downloads(string $kind, string $slug): string
{
    $dl = m3d_downloads($kind, $slug);
    return $dl['total'] ? '<dt>Downloads</dt><dd class="dl-count"><span>' . number_format($dl['total']) . '</span></dd>' : '';
}

/**
 * A picture of the gallery row (gallery_strip()): the small file, plus the thumb in srcset so the browser takes it when it is
 * enough (the row is 195–285 px tall, `.gallery-strip .thumb` in site.css: a tall render shows only ~110–160 px wide).
 */
function strip_img(string $src, string $alt): string
{
    $small = img_small($src);
    $d = img_dims($small);
    $set = img_srcset($src, ['xs', 'sm']);
    $r = $d && $d[1] > 0 ? $d[0] / $d[1] : 0;
    $sizes = $set !== '' && $r > 0 ? ' sizes="(max-width: 812px) ' . (int) ceil(195 * $r) . 'px, ' . (int) ceil(285 * $r) . 'px"' : '';
    return '<img src="' . e($small) . '"' . ($sizes !== '' ? ' srcset="' . e($set) . '"' . $sizes : '') . ' alt="' . e($alt) . '"'
        . ($r > 0 ? ' style="aspect-ratio: ' . $d[0] . ' / ' . $d[1] . '"' : '') . ' loading="lazy" decoding="async" />';
}

/** JSON for a [data-slides] attribute: [{src, caption}] with the large versions. */
function slides_attr(array $images): string
{
    $out = [];
    foreach ($images as $g) { $g = as_image($g); if ($g['src'] !== '') $out[] = $g; }
    return e(json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** Badge for an alpha/beta changelog version (α / β drawn as icons); standard versions get none. */
const STAGE_ICONS = ['alpha' => '<path d="M18.5 6.5c-1.6 5-2.7 8.4-1.4 10.8.5.9 1.4.9 2.4.2"/><path d="M16.6 12.2C15.4 8.4 13.4 6.5 11 6.5c-3 0-5 2.6-5 5.8s1.9 5.7 4.8 5.7c2.6 0 4.6-2.2 5.8-5.8z"/>', 'beta' => '<path d="M7.5 21.5V8c0-2.7 1.9-4.5 4.5-4.5 2.3 0 4 1.5 4 3.6 0 2-1.5 3.4-3.6 3.6 2.8.2 4.8 1.9 4.8 4.4 0 2.6-2.1 4.4-4.8 4.4-2 0-3.6-.9-4.9-2.3"/>'];
function stage_badge(string $stage, bool $iconOnly = false): string
{
    if (!isset(STAGE_ICONS[$stage])) return '';
    // $iconOnly (cards): just the α / β, the word stays in the tooltip and for screen readers
    return '<span class="badge stage stage-' . $stage . ($iconOnly ? ' stage-icon' : '') . '" title="' . M3D_STAGES[$stage] . ' version"' . ($iconOnly ? ' aria-label="' . M3D_STAGES[$stage] . ' version"' : '') . '><svg viewBox="0 0 24 24" aria-hidden="true">' . STAGE_ICONS[$stage] . '</svg>' . ($iconOnly ? '' : M3D_STAGES[$stage]) . '</span>';
}

/** Download box shared by model and outfit pages. Patron links go through /get/…; public ones are shown. */
function downloads_box(string $kind, array $item, bool $comingSoon = false): string
{
    $links = array_values(array_filter($item['links'] ?? [], fn($l) => !empty($l['url'])));
    $base = $kind === 'outfits' ? "/get/outfit/{$item['slug']}/" : "/get/{$item['slug']}/";
    $patron = $public = '';
    $guest = !m3d_patron($GLOBALS['M3D']);   // not logged in: a lock on the patron buttons, a line saying a Patreon login is needed
    $outsider = $guest || empty(m3d_patron($GLOBALS['M3D'])['access']);   // no paid tier either: the release dates read as for visitors
    $phase = m3d_release_phase($item);       // release dates: 'soon' (no download yet), 'early' (patrons only until a date), 'free' (every link public)
    $admin = m3d_is_admin($GLOBALS['M3D']) && !m3d_preview();
    foreach ($links as $i => $l) {
        if (!empty($l['off'])) continue;   // switched off in the editor (the numbers of the others stay)
        $label = trim((string) ($l['label'] ?? '')) ?: 'Download';
        if (m3d_link_access($item, $l) === 'public') {
            // through /get/ too, so free downloads are counted (it redirects straight away, no login)
            $public .= '<a class="btn btn-ghost btn-sm" href="' . e($base . $i . '/') . '" rel="nofollow noopener">' . e($label) . ' ↗</a>';
        } else {
            // the same /get/ link for everyone: a visitor goes through the Patreon login and comes back to the download
            $patron .= '<a class="btn btn-primary dl" href="' . e($base . $i . '/') . '" rel="nofollow"' . ($guest ? ' title="Log in with Patreon to download"' : '') . '>'
                . ($guest ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" stroke-linecap="round"/></svg>'
                    : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 3v12m0 0-5-5m5 5 5-5M4 21h16" stroke-linecap="round" stroke-linejoin="round"/></svg>')
                . '<span class="dl-name">' . e($label) . '</span></a>';
        }
    }
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    $h = '<div class="box dl-box" id="download"><h2>Download</h2>';
    if ($comingSoon) {
        $h .= '<p class="muted">This ' . $what . ' is still in development. Follow the progress on Patreon.</p>';
    } elseif ($patron) {
        $p = m3d_patron($GLOBALS['M3D']);
        $reqs = '';
        $r = m3d_requirements($item);
        if ($guest && ($r['months'] || $r['total_cents'])) {   // (the minimum tier goes in the login line above the buttons)
            // visitors: one short line per requirement ("4+ Consecutive months as a patron"), "or" between them when one is enough;
            // a logged-in visitor gets the verdict instead, worked out for them
            $or = $r['mode'] === 'or';
            $reqs = '<ul class="req-list" aria-label="Extra requirements">'
                . ($r['months'] ? '<li><b class="req-val">' . $r['months'] . '+</b> Consecutive months as a patron</li>' : '')
                . ($or ? '<li class="req-sep" aria-hidden="true">or</li>' : '')
                . ($r['total_cents'] ? '<li><b class="req-val">' . e(m3d_money($r['total_cents'])) . '+</b> Total support</li>' : '')
                . '</ul>';
        }
        // logged in: can they download it? A green ✓ by the title when yes (why: on hover / tap), a red ✗ and one line when not
        $v = $p && ($phase !== 'soon' || $admin) ? dl_verdict($kind, $item, $p) : null;
        $mark = $v === null ? '' : ($v['ok']
            ? '<button type="button" class="help-tip dl-mark ok" data-help="' . e(when_plain($v['title'] . ' ' . $v['text'])) . '" aria-label="' . e(when_plain($v['title'] . ' ' . $v['text'])) . '">' . DL_MARK_OK . '</button>'
            : '<span class="dl-mark no" aria-hidden="true">' . DL_MARK_NO . '</span>');
        $line = $v && !$v['ok'] ? dl_verdict_html($v) : '';
        if ($line) $patron = str_replace('class="btn btn-primary dl"', 'class="btn btn-primary dl is-limited"', $patron);
        // "Check your status" (My account) next to the title, for anyone logged in; not for items that never count
        $status = $p && empty($item['noLimits']) ? '<a class="btn btn-ghost btn-sm box-status" href="/account/">Check Your Status</a>' : '';
        if ($mark || $status) $h = str_replace('<h2>Download</h2>', '<div class="box-head"><h2>Download' . $mark . '</h2>' . $status . '</div>', $h);
        $join = social_url('patreon');
        if ($phase === 'soon' && !$admin) $patron = str_replace('class="btn btn-primary dl"', 'class="btn btn-primary dl is-limited"', $patron);
        $h .= ($guest ? '<p class="dl-guest">Log in with Patreon to download (' . ($r['tier_cents'] ? e(m3d_money($r['tier_cents'])) . ' tier or higher' : 'any paid tier') . ').</p>' : '')
            . $line . '<div class="dl-list">' . $patron . '</div>' . release_note($item, $outsider, $admin) . $reqs
            . ($guest && $join !== '' ? '<p class="dl-join small muted">Not a patron yet? <a href="' . e($join) . '" rel="noopener" target="_blank">See the tiers on Patreon ↗</a></p>' : '');
    } elseif (!$public) {
        $h .= '<p class="muted">No download for this ' . $what . ' yet.</p>';
    }
    if ($public && !$comingSoon) {
        if ($phase === 'soon' && !$admin) $public = str_replace('class="btn btn-ghost btn-sm"', 'class="btn btn-ghost btn-sm is-limited"', $public);
        $h .= '<div class="free">' . ($patron ? '' : '<p class="muted">Free download, no login needed.</p>')
            . '<div class="free-links">' . $public . '</div></div>' . ($patron ? '' : release_note($item, $outsider, $admin));
    }
    // add-ons needed to use it (editor → Downloads → Add-ons required): public links, a site page or another website
    $addons = '';
    foreach (m3d_addons($item) as $a) {
        if (empty($a['url']) || empty($a['label'])) continue;
        $ext = !str_starts_with((string) $a['url'], '/');
        $addons .= '<a class="btn btn-ghost btn-sm" href="' . e($a['url']) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . e($a['label']) . ($ext ? ' ↗' : ' →') . '</a>';
    }
    if ($addons !== '') $h .= '<div class="free dl-addons"><p class="small muted">Add-Ons Required</p><div class="free-links">' . $addons . '</div></div>';
    return $h . '</div>';
}

/**
 * The release dates line of the download box (clock icon): before the patrons date when downloads open, between the dates
 * when it becomes free, after the public date since when it is free. '' for items without dates.
 */
function release_note(array $item, bool $guest, bool $admin): string
{
    $r = m3d_release($item);
    $days = $r['patrons'] !== null && $r['public'] !== null ? (int) round(($r['public'] - $r['patrons']) / 86400) : 0;
    $early = $days > 0 ? m3d_plural($days, 'day') . ' before everyone else' : 'before everyone else';
    $free = $r['public'] !== null ? ' Free for everyone from ' . when_mark($r['public'], 'day') . '.' : '';
    switch (m3d_release_phase($item)) {
        case 'soon':
            $when = when_mark($r['patrons']);
            [$b, $t] = $admin ? [$r['before'] === 'soon' ? 'Shown as “Coming Soon”.' : 'Hidden from everyone but admins.', "Patrons can download it from $when; you already can, as an admin."]
                : ($guest ? ["Coming for patrons on $when", '(' . m3d_release_in($r['patrons']) . ').' . $free]
                : ["Downloads open $when", '(' . m3d_release_in($r['patrons']) . ').' . ($r['public'] !== null ? " You get it $early." : '')]);
            break;
        case 'early':
            [$b, $t] = $guest ? ['Early access for patrons.', 'Free for everyone from ' . when_mark($r['public'], 'day') . ' (' . m3d_release_in($r['public']) . ').']
                : ['Early access:', "patrons get it $early. Free for all from " . when_mark($r['public'], 'day') . '.'];
            break;
        case 'free':
            return '<p class="small muted dl-free-since">' . when_text($days > 0 ? 'Patrons got it ' . m3d_plural($days, 'day') . ' early, on ' . when_mark($r['patrons'], 'day') . '.'
                : 'Free for everyone since ' . when_mark($r['public'], 'day') . '.') . '</p>';
        default:
            return '';
    }
    return '<p class="dl-early">' . CLOCK_ICON . '<span><b>' . when_text($b) . '</b> ' . when_text($t) . '</span></p>';
}

const DL_MARK_OK = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="currentColor"/><path d="m7 12.5 3.3 3.3L17 9" fill="none" stroke="#121110" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
const DL_MARK_NO = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="11" fill="currentColor"/><path d="m8.5 8.5 7 7m0-7-7 7" fill="none" stroke="#121110" stroke-width="2.6" stroke-linecap="round"/></svg>';

/**
 * Can this logged-in visitor download the patron links of an item? The same checks, in the same order, as auth/get.php:
 * paid tier, extra requirements, download limits. Returns ['ok' => bool, 'title' => sentence, 'text' => sentence,
 * 'list' => [] (several things missing)], or null when there is nothing to say (paid tier, no requirements, no limits).
 */
function dl_verdict(string $kind, array $item, array $p): ?array
{
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    if (empty($p['access'])) return ['ok' => false, 'list' => []] + match ($p['reason'] ?? '') {
        'declined' => ['title' => 'Your last Patreon payment was declined.', 'text' => 'Downloads open again once it goes through.'],
        'no-tier'  => ['title' => 'You need a paid tier to download.', 'text' => 'Upgraded on Patreon? Press Refresh on My account.'],
        default    => ['title' => 'You need a paid Patreon tier to download.', 'text' => 'Joined on Patreon? Press Refresh on My account.'],
    };
    $req = m3d_check_requirements($item, $p);
    if ($req['unknown']) return ['ok' => false, 'title' => 'Log in again to download this one.', 'text' => "This $what has extra requirements your login is too old to check.", 'list' => []];
    $miss = [];   // [need, have] sentences: "You need 6 months as a patron." / "You have 2 months."
    $r = m3d_requirements($item);
    $cur = $p['currency'] ?? null;
    if (!$req['tier_ok']) {
        // below the minimum tier: name it, and theirs (dl_verdict_html() adds the Upgrade link)
        $t = m3d_patron_counts($p)['tier'];
        $need = m3d_tier_label($r['tier_cents'], $cur) . ' or higher';
        $have = $t ? ($t[0] !== '' ? $t[0] . ' (' . m3d_money($t[1], $cur) . ')' : m3d_money($t[1], $cur)) : '';
        $short = $t ? ($t[0] !== '' ? $t[0] . ', ' . m3d_money($t[1], $cur) : m3d_money($t[1], $cur)) : '';   // in the list, without nested brackets
        $miss[] = ['title' => "You need the $need.", 'text' => $have !== '' ? "Yours is $have." : '', 'item' => "the $need" . ($short !== '' ? " (yours: $short)" : '')];
    }
    if (!$req['mt_ok']) {
        $counts = m3d_patron_counts($p);   // with a "Custom" exception's months / total
        $mo = (int) ($counts['months'] ?? 0);
        $tot = $counts['total'];
        $need = $have = [];
        if ($r['months'] && ($r['mode'] === 'or' || $mo < $r['months'])) { $need[] = m3d_plural($r['months'], 'month') . ' as a patron'; $have[] = m3d_plural($mo, 'month'); }
        if ($r['total_cents'] && ($r['mode'] === 'or' || $tot < $r['total_cents'])) { $need[] = m3d_money($r['total_cents'], $cur) . ' in total support'; $have[] = m3d_money($tot, $cur); }
        $mt = [];
        if ($r['mode'] === 'or') $mt[] = [implode(' or ', $need), implode(' and ', $have)];
        else foreach ($need as $i => $n) $mt[] = [$n, $have[$i]];
        foreach ($mt as $x) $miss[] = ['title' => "You need $x[0].", 'text' => "You have $x[1].", 'item' => "$x[0] (you have $x[1])"];
    }
    $c = m3d_check_limits($p, $kind, (string) $item['slug']);
    $limited = $c['rows'] && !$c['exempt'];
    // the limits are rolling: they count the last 24 hours / 7 days / 30 days, not today / this week / this month
    $period = fn(array $row) => 'the last ' . m3d_rule_period($row['rule'])[1];
    $unit = fn(array $row) => m3d_rule_noun($row['rule']);
    if ($limited && !$c['ok']) {
        $o = m3d_blocking_row($c['rows']);
        $when = $o['next'] ? when_mark($o['next'], 'at') : '';
        $miss[] = ['title' => 'You used all your ' . $unit($o) . 'unlocks of ' . $period($o) . '.',
                   'text' => trim(($c['update'] ? 'A new version uses an unlock too. ' : '') . ($when !== '' ? "Your next one is on $when." : '')),
                   'item' => 'one more ' . $unit($o) . 'unlock' . ($when !== '' ? " (next one on $when)" : '')];
    }
    if (($until = m3d_dl_capped($p)) !== null) {   // daily download cap (Admin → Access)
        $when = when_mark($until, 'at');
        $miss[] = ['title' => 'You reached the daily download limit.', 'text' => "You can download again on $when.", 'item' => "a new day of downloads (from $when)"];
    }
    $up = ['upgrade' => !$req['tier_ok']];
    if (count($miss) === 1) return ['ok' => false, 'list' => []] + $up + $miss[0];
    if ($miss) return ['ok' => false, 'title' => count($miss) . ' things are missing:', 'text' => '', 'list' => array_column($miss, 'item')] + $up;
    if ($limited && $c['free']) return ['ok' => true, 'title' => 'You can download this.', 'text' => 'It doesn’t use any of your unlocks.', 'list' => []];
    if ($limited && $c['owned']) return ['ok' => true, 'title' => 'You can download this again.', 'text' => 'You already unlocked it, so it’s free.', 'list' => []];
    if ($limited) {
        $t = m3d_tightest_row($c['rows']);   // the one that runs out first
        $left = $t['max'] - $t['used'];
        return ['ok' => true, 'title' => $c['update'] ? 'You can download the new version.' : 'You can download this.', 'list' => [], 'text' => $left === 1
            ? 'It uses your last ' . $unit($t) . 'unlock for now (the limit counts ' . $period($t) . ').'
            : 'It uses 1 of your ' . $left . ' ' . $unit($t) . 'unlocks left (the limit counts ' . $period($t) . ').'];
    }
    if (m3d_has_requirements($item)) return ['ok' => true, 'title' => 'You can download this.', 'text' => 'You meet its requirements.', 'list' => []];
    return null;
}

/** The red line of a download that is blocked (dl_verdict()): what is missing, in one sentence or a short list. */
function dl_verdict_html(array $v): string
{
    $h = '<div class="dl-verdict" role="status"><p><b>' . when_text($v['title']) . '</b>' . ($v['text'] !== '' ? ' ' . when_text($v['text']) : '') . '</p>';
    if ($v['list']) $h .= '<ul>' . implode('', array_map(fn($x) => '<li>' . when_text($x) . '</li>', $v['list'])) . '</ul>';
    // below the item's minimum tier: a way to upgrade (only with a Patreon link set)
    $join = !empty($v['upgrade']) ? social_url('patreon') : '';
    if ($join !== '') $h .= '<p class="dl-upgrade"><a href="' . e($join) . '" rel="noopener" target="_blank">Upgrade on Patreon ↗</a> <span class="muted">then press Refresh on <a href="/account/">My account</a>.</span></p>';
    return $h . '</div>';
}

/** Changelog with "show older" and "oldest first" controls (one per page). */
function changelog_block(array $log, ?string $note = null, bool $noImages = false): string
{
    if (!$log) return '';
    $SHOW = 3;
    $older = max(0, count($log) - $SHOW);
    $h = '<section class="block" id="changelog"><h2 class="block-title">Changelog <span class="count">' . count($log) . ' ' . (count($log) === 1 ? 'version' : 'versions') . '</span></h2>';
    if ($note) $h .= '<p class="small muted log-intro md">' . md_inline($note) . '</p>';
    if ($older) {
        $h .= '<div class="log-controls"><button class="log-btn" data-log-toggle aria-expanded="false"><span data-when="closed">Show ' . $older . ' Older ' . ($older === 1 ? 'Version' : 'Versions') . '</span><span data-when="open" hidden>Hide Older Versions</span></button>'
            . '<button class="log-btn ghost" data-log-order aria-label="Change changelog order"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3" stroke-linecap="round" stroke-linejoin="round"/></svg><span data-order-label>Oldest First</span></button></div>';
    }
    $h .= '<ol class="log" data-log>' . changelog_items($log, $noImages, $SHOW, true) . '</ol>';
    if ($older) $h .= '<button class="log-btn ghost log-bottom" data-log-toggle hidden>↑ Hide Older Versions</button>';
    return $h . '</section>';
}

/**
 * The versions of a changelog as <li>s of an <ol class="log">: the first one is the latest; from $show on they start hidden (older).
 * $anchors (the item page's changelog, once per page): each version gets its id (m3d_version_ids(): #v4) and a chain-link button that
 * copies its address; site.js opens a hidden one when the address names it.
 */
function changelog_items(array $log, bool $noImages = false, int $show = PHP_INT_MAX, bool $anchors = false): string
{
    $h = '';
    $ids = $anchors ? m3d_version_ids($log) : [];
    $latest = null;   // "Latest" = the newest version out; one dated in the future says when it comes instead
    foreach (array_values($log) as $i => $en) {
        $ts = m3d_changelog_ts((string) ($en['date'] ?? ''));
        $soon = $ts !== null && $ts > time();
        if (!$soon && $latest === null) $latest = $i;
        $cls = trim(($latest === $i ? 'latest ' : '') . ($i >= $show ? 'old ' : '') . (m3d_stage($en) !== 'standard' ? 'log-' . m3d_stage($en) : ''));   // log-alpha / log-beta: coloured dot
        $id = $ids[$i] ?? '';
        $h .= '<li class="' . $cls . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . ($i >= $show ? ' hidden' : '') . '><div class="log-head">' . stage_badge(m3d_stage($en)) . '<span class="ver">' . e($en['version'] ?? '') . '</span>'
            . (!empty($en['date']) ? '<time>' . e($en['date']) . '</time>' : '')
            . ($latest === $i ? '<span class="badge tone-mustard">Latest</span>' : '')
            . ($soon ? '<span class="badge">Coming ' . e(m3d_release_day($ts)) . '</span>' : '')
            . highlight_badge($en['highlight'] ?? null) . ($id !== '' ? copy_link($id, 'ver-anchor', 'Copy link to this version') : '') . '</div>';
        foreach ($en['notes'] ?? [] as $n) $h .= '<div class="log-note md">' . md_html((string) $n, ['noImages' => $noImages]) . '</div>';
        if (!empty($en['changes'])) { $h .= '<ul>'; foreach ($en['changes'] as $c) $h .= change_line((string) $c); $h .= '</ul>'; }
        if (!empty($en['image']) && !$noImages) $h .= '<img class="note-img" src="' . e($en['image']) . '" alt="" loading="lazy" />';
        $h .= '</li>';
    }
    return $h;
}

/**
 * Icon of an accessory type (stroke drawings, 24×24): T-shirt for outfits, comb for hair, pendant for necklaces…; any other type
 * gets the hanger, which also stands for all accessories together (header unlock counter, site.js). Matched on words in the type,
 * so "Hair style" or "Boots" find theirs.
 */
const ACCESSORY_ICONS = [
    'blender'  => ['/\b(blender|nodes?|node\s*groups?|compositor|compositing|shaders?|geometry\s*nodes|add-?ons?|scripts?|presets?)\b/', '<circle cx="14.5" cy="14" r="6"/><circle cx="14.5" cy="14" r="2"/><path d="M10.2 9.8 3.5 10M12.4 8.4 7 3.8"/>'],
    'outfit'   => ['/\b(outfits?|dress(es)?|clothes|clothing|suits?|tops?|skirts?|pants|lingerie|bikinis?|swimsuits?|uniforms?|costumes?)\b/', '<path d="M9 3.5 4 6.3l1.9 4.1 2.1-1V20.5h8V9.4l2.1 1L20 6.3l-5-2.8c-.4 1.4-1.6 2.3-3 2.3s-2.6-.9-3-2.3z"/>'],
    'hair'     => ['/\b(hair\w*|wigs?|ponytails?|braids?)\b/', '<path d="M3.5 7.5h17v4h-17z"/><path d="M5.5 11.5v6M8.5 11.5v6M11.5 11.5v6M14.5 11.5v6M17.5 11.5v6"/>'],
    'necklace' => ['/\b(necklaces?|chokers?|pendants?|jewel\w*|chains?|collars?)\b/', '<path d="M5 3.5c0 5.5 3 9.5 7 9.5s7-4 7-9.5"/><path d="M12 13l2.3 3.2L12 20.5l-2.3-4.3z"/>'],
    'earrings' => ['/\b(earrings?|piercings?)\b/', '<path d="M7.5 3.5v6M16.5 3.5v6"/><circle cx="7.5" cy="14" r="3.2"/><circle cx="16.5" cy="14" r="3.2"/>'],
    'glasses'  => ['/\b(glasses|sunglasses|eyewear|goggles)\b/', '<circle cx="6.8" cy="14" r="3.4"/><circle cx="17.2" cy="14" r="3.4"/><path d="M10.2 14c1.1-.9 2.5-.9 3.6 0M3.4 13.4 5 7.5M20.6 13.4 19 7.5"/>'],
    'shoes'    => ['/\b(shoes?|boots?|heels?|sneakers?|sandals?|socks?|stockings?)\b/', '<path d="M3.5 17.5v-8l3 .5c1 2 2.6 3.1 5.2 3.4l6.1.8c1.6.2 2.7 1.5 2.7 3v.3z"/><path d="M3.5 20h17"/>'],
    'hat'      => ['/\b(hats?|caps?|helmets?|crowns?|tiaras?|headwear)\b/', '<path d="M2.5 17.2c2.5 1.6 16.5 1.6 19 0"/><path d="M6.5 16.7V11c0-2.8 2.4-4.5 5.5-4.5s5.5 1.7 5.5 4.5v5.7"/>'],
    'bag'      => ['/\b(bags?|purses?|backpacks?|handbags?)\b/', '<path d="M5 8.5h14l-1.2 11.5H6.2z"/><path d="M9 8.5V7a3 3 0 0 1 6 0v1.5"/>'],
];
const ACCESSORY_ICON_GENERIC = '<path d="M9.8 6.3a2.2 2.2 0 1 1 3 2.05c-.5.2-.8.6-.8 1.1v.55"/><path d="M12 10 3.6 16.1c-.7.5-.35 1.6.5 1.6h15.8c.85 0 1.2-1.1.5-1.6L12 10z"/>';
/** The inner drawing (paths) for a type, for an <svg viewBox="0 0 24 24">. */
function accessory_icon_paths(string $type): string
{
    $t = mb_strtolower($type);
    foreach (ACCESSORY_ICONS as [$re, $paths]) if (preg_match($re, $t)) return $paths;
    return ACCESSORY_ICON_GENERIC;
}
/** The icon as a small inline <svg> (1em, the text colour). */
function accessory_icon(string $type): string
{
    return '<svg class="type-ico" viewBox="0 0 24 24" aria-hidden="true">' . accessory_icon_paths($type) . '</svg>';
}

/** First image of an outfit (its preview). */
function outfit_preview(array $o): string
{
    foreach ($o['images'] ?? [] as $g) { $g = as_image($g); if ($g['src'] !== '') return $g['src']; }
    return '';
}

/** The small picture of an item for lists: a model's catalog image (else its main picture), an accessory's first image; '' = none. */
function item_pic(string $kind, array $it): string
{
    return $kind === 'outfits' ? outfit_preview($it) : (string) (($it['thumb'] ?? '') ?: ($it['cover'] ?? ''));
}

/** Accessory card: the whole card opens its page (catalog, model pages, related accessories). Type above the name, like a model's franchise. */
function outfit_card(array $o): string
{
    $url = m3d_item_url('outfits', $o['slug']);
    $prev = outfit_preview($o);
    $count = count(array_filter(array_map('as_image', $o['images'] ?? []), fn($g) => $g['src'] !== ''));
    $hidden = m3d_pics_hidden('outfits', $o);
    if ($hidden) $prev = blur_src($prev);   // never the real picture
    $img = $prev
        ? '<img src="' . e($hidden ? $prev : img_small($prev)) . '"' . (!$hidden && ($set = img_srcset($prev, ['xs', 'sm'])) !== '' ? ' srcset="' . e($set) . '" sizes="' . CARD_SIZES . '"' : '') . ' alt="' . e($o['name']) . '" loading="lazy" decoding="async" />'
        : '<div class="ph" aria-hidden="true"><span class="ph-initials">' . e(mb_substr($o['name'], 0, 1)) . '</span></div>';
    $lv = m3d_current_version($o);
    $ver = $lv['version'] !== '' ? '<span class="muted small">' . (($sb = stage_badge($lv['stage'], true)) !== '' ? $sb . ' ' : '') . e($lv['version']) . ($lv['date'] !== '' ? ' · ' . e($lv['date']) : '') . '</span>' : '';
    $models = [];
    foreach ($o['models'] ?? [] as $slug) if (($m = m3d_model($slug)) && m3d_listed($m)) $models[] = e($m['name']) . (($m['generation'] ?? '') !== '' ? ' <span class="muted">G' . e($m['generation']) . '</span>' : '');
    $fits = $models ? '<span class="fits small">' . implode(' · ', $models) . '</span>' : '';
    $type = m3d_accessory_type($o);
    // on the picture: the type's icon (top-left, like the Gen tag of model cards), then Password / Teaser / Patrons
    $pill = '<span class="cover-tags"><span class="type-tag" title="' . e($type) . '">' . accessory_icon($type) . '</span>' . visibility_pill('outfits', $o) . '</span>'
        . (!$hidden && $count > 1 ? '<span class="count-pill">' . $count . ' images</span>' : '');
    $likes = m3d_like_count((string) $o['slug'], 'outfits');
    $likesPill = comments_pill('outfits', $o, (string) $o['slug']) . likes_pill($likes);
    return '<a class="ocard" href="' . e($url) . '" data-ocard data-slug="' . e($o['slug']) . '" data-name="' . e(mb_strtolower($o['name'])) . '" data-order="' . e($o['order'] ?? 999) . '" data-likes="' . $likes . '" data-updated="' . m3d_updated_ts($o) . '" data-downloads="' . m3d_downloads('outfits', (string) $o['slug'])['total'] . '" data-type="' . e(mb_strtolower(m3d_accessory_type($o))) . '" data-models="' . e(implode(' ', array_filter($o['models'] ?? [], fn($sl) => ($mm = m3d_model($sl)) && m3d_listed($mm)))) . '">'
        . '<span class="ocard-img' . ($hidden ? ' is-blurred' : '') . '">' . $img . $pill . '</span>'
        . '<span class="ocard-body"><span class="franchise">' . e($type) . '</span><h3>' . e($o['name']) . '</h3>' . $ver . $fits . ($likesPill ? '<span class="card-meta">' . $likesPill . '</span>' : '') . '</span></a>';
}

/**
 * Recently added or updated models and outfits (homepage): listed items whose latest changelog version is dated within
 * $days, newest first. Each: ['kind', 'item', 'ts', 'version', 'stage', 'date', 'new' (only one version yet)].
 */
function recent_items(int $max = 4, int $days = 90): array
{
    $out = [];
    $from = time() - $days * 86400;
    foreach (['models', 'outfits'] as $kind) {
        foreach (m3d_listed_items($kind) as $it) {
            // the newest version out (one dated in the future isn't yet; a "Coming soon" item has none, like What's new)
            $ts = m3d_updated_ts($it);
            if (!$ts || $ts < $from || $ts > time()) continue;
            $lv = m3d_current_version($it);
            $out[] = ['kind' => $kind, 'item' => $it, 'ts' => $ts, 'version' => $lv['version'], 'stage' => $lv['stage'], 'date' => $lv['date'], 'new' => count(m3d_released_versions($it)) <= 1];
        }
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return array_slice($out, 0, $max);
}

/** The small picture of a compact card (card_pic()'s address), or the name's first letter when there is none. */
function card_img(string $src, string $name): string
{
    return $src !== '' ? '<img src="' . e($src) . '" alt="" loading="lazy" decoding="async" />' : '<span class="ph-initials">' . e(mb_substr($name, 0, 1)) . '</span>';
}

/** Small row card for recent_items(): picture, New / Updated, name, version and date. */
function recent_card(array $r): string
{
    $it = $r['item'];
    $isOutfit = $r['kind'] === 'outfits';
    [$src, $hidden] = card_pic($r['kind'], $it);
    $img = card_img($src, (string) $it['name']);
    return '<a class="recent" href="' . e(m3d_item_url($r['kind'], $it['slug'])) . '">'
        . '<span class="recent-img' . ($hidden ? ' is-blurred' : '') . '">' . $img . '</span>'
        . '<span class="recent-body"><span class="recent-tag ' . ($r['new'] ? 'is-new' : 'is-upd') . '">' . ($r['new'] ? 'New' : 'Updated') . '</span>'
        . '<b class="recent-name">' . e(m3d_model_name($it)) . '</b>'
        . '<span class="recent-meta">' . ($isOutfit ? e(m3d_accessory_type($it)) . ' · ' : '') . (($sb = stage_badge($r['stage'], true)) !== '' ? $sb . ' ' : '') . e($r['version']) . ($r['date'] !== '' ? ' · ' . e($r['date']) : '') . '</span>'
        . '</span></a>';
}

/**
 * The small picture of a model (catalog image) or outfit (first image) for compact cards: [address, hidden]. Hidden
 * pictures become the tiny blurred copy (never the real address); '' when there is no picture.
 */
function card_pic(string $kind, array $it): array
{
    $src = item_pic($kind, $it);
    $hidden = m3d_pics_hidden($kind, $it);
    return [$hidden ? blur_src($src) : ($src !== '' ? img_thumb($src) : ''), $hidden];
}

/**
 * My account (Models / Accessories Liked), "Other Versions" and the "More from" rows of model / accessory pages: the cards in one row (`strip_html()`).
 * $cards = model_card() / outfit_card() HTML, $what = the arrows' word ("models", "accessories"),
 * $class = an extra class ('rel-strip': the page sections' spacing).
 */
function like_strip(array $cards, string $what, string $class = ''): string
{
    return strip_html(trim("like-strip $class"), 'like-row', implode('', $cards), $what);
}

/**
 * Outfit pages, "Optimized for": the models as small portrait tiles (picture + name + Gen) in one row with ‹ › (`strip_html()`).
 */
function model_strip(array $models): string
{
    $tiles = '';
    foreach ($models as $m) {
        [$src, $hidden] = card_pic('models', $m);
        $img = card_img($src, (string) $m['name']);
        $gen = array_filter([($m['generation'] ?? '') !== '' ? 'Gen ' . e($m['generation']) : '', m3d_model_versions($m) ? 'v' . (int) $m['majorVersion'] : '']);
        $title = trim($m['name'] . ' · ' . ($m['franchise'] ?? ''), ' ·');
        $tiles .= '<a class="fit-tile" href="/models/' . e($m['slug']) . '/" title="' . e($title) . '">'
            . '<span class="fit-img' . ($hidden ? ' is-blurred' : '') . '">' . $img . '</span>'
            . '<span class="fit-name">' . e($m['name']) . '</span>'
            . ($gen ? '<span class="fit-gen">' . implode(' · ', $gen) . '</span>' : '') . '</a>';
    }
    return strip_html('fit-strip', 'fit-row', $tiles, 'models');
}

/** The likes on a catalog card: heart + count, nothing at 0. */
function likes_pill(int $likes): string
{
    return $likes ? '<span class="likes-pill" title="' . e(m3d_plural($likes, 'like')) . ' from patrons">' . HEART_ICON . $likes . '</span>' : '';
}

/** The like heart (like_button(), the gallery tiles and viewer). */
const HEART_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.7 4.6 13.5a4.9 4.9 0 0 1 0-7 5 5 0 0 1 7 0l.4.4.4-.4a5 5 0 0 1 7 0 4.9 4.9 0 0 1 0 7z"/></svg>';

/** Heart button for a model or outfit page: count rendered here, liked state and clicks handled by site.js (auth/like.php). */
function like_button(string $slug, string $kind = 'models'): string
{
    $n = m3d_like_count($slug, $kind);
    // logged in: their own state in the page (site.js needs no request); visitors: the count is all there is
    $p = m3d_patron($GLOBALS['M3D']);
    $state = $p ? ' data-like-state="' . e(json_encode(['count' => $n, 'liked' => in_array(m3d_like_code((string) $p['id']), m3d_likes($kind)[$slug] ?? [], true)] + m3d_like_viewer($p))) . '"' : '';
    return '<button type="button" class="like-btn" data-like="' . e($slug) . '" data-like-kind="' . e($kind) . '"' . $state . ' aria-pressed="false" title="Like this ' . (['outfits' => 'accessory', 'posts' => 'post'][$kind] ?? 'model') . ' (patrons)">'
        . HEART_ICON . '<span data-like-count>' . $n . '</span><span class="sr-only"> likes</span></button>'
        . '<p class="like-msg small" data-like-msg hidden></p>';
}

/** Floating "Edit" button for the admin: hidden in the HTML, shown by site.js when the header's status says admin. */
function admin_edit_button(string $href, string $label): string
{
    return '<a class="admin-edit" href="' . e($href) . '" data-admin-only hidden>'
        . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM14 6l4 4"/></svg>' . e($label) . '</a>';
}

/** Round icon buttons for the social links (Admin → Settings). */
function social_icon(string $type): string
{
    $t = SOCIAL_TYPES[$type] ?? SOCIAL_TYPES['website'];
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $t[2] . '"/></svg>';
}

function social_buttons(string $class = 'social'): string
{
    $out = '';
    foreach (m3d_social() as $l) {
        [$label, $color] = SOCIAL_TYPES[$l['type']];
        $url = $l['type'] === 'email' && !str_starts_with($l['url'], 'mailto:') ? 'mailto:' . $l['url'] : $l['url'];
        // dark brand colors (X, GitHub…) would vanish on the dark page: those get a light button on hover
        [$r, $g, $b] = sscanf($color, '#%02x%02x%02x');
        $light = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) > 140;
        $dark = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) < 50;
        $style = '--brand:' . ($dark ? '#f3efe6' : $color) . ';--brand-ink:' . ($light || $dark ? '#121110' : '#fff');
        $out .= '<a class="social-btn" href="' . e($url) . '" rel="noopener me" title="' . e($label) . '" aria-label="' . e($label) . '" style="' . e($style) . '">' . social_icon($l['type']) . '</a>';
    }
    return $out !== '' ? '<nav class="' . e($class) . '" aria-label="' . e(site_name()) . ' elsewhere">' . $out . '</nav>' : '';
}

function md_inline(string $t): string
{
    $t = e($t);
    $codes = [];   // code spans are set aside first: link / picture syntax written inside one stays text, and "*" isn't read as bold/italic
    $t = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) { $codes[] = '<code>' . $m[1] . '</code>'; return "\x01" . (count($codes) - 1) . "\x01"; }, $t);
    // a picture inside a line (not alone on its own line, see md_html): its caption only, never its address (teaser items' pictures stay hidden)
    $t = preg_replace('/!\[([^\]]*)\]\((?:https?:\/\/|\/)[^\s)]+\)/', '$1', $t);
    $urls = [];   // link addresses are set aside too, so a "*" in an address isn't read as bold/italic (the link text still is)
    // "//other-site" and "/\other-site" aren't pages of this site: not links
    $t = preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/(?![\/\\\\])|#)[^\s)]+)\)/', function ($m) use (&$urls) {
        $url = $m[2];
        // visitors' texts (bug reports): their links to other websites aren't followed by search engines
        $ext = preg_match('#^https?://#', html_entity_decode($url)) ? (md_ugc() ? ' rel="nofollow ugc noopener"' : ' rel="noopener"') : '';
        $urls[] = $url;
        return '<a href="' . "\x04" . (count($urls) - 1) . "\x04" . '"' . $ext . '>' . $m[1] . '</a>';
    }, $t);
    $t = preg_replace('/\*\*\*(.+?)\*\*\*/s', '<strong><em>$1</em></strong>', $t);   // ***both***: tags nested the right way
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
    $t = preg_replace_callback('/\x01(\d+)\x01/', fn($m) => $codes[(int) $m[1]], $t);
    $t = preg_replace_callback('/\x04(\d+)\x04/', fn($m) => $urls[(int) $m[1]], $t);
    return nl2br($t, false);
}

/** Heading text → id ("Part 1: Daz Studio" → "part-1-daz-studio"). */
function md_id(string $text): string
{
    $t = strtolower(preg_replace('/[*`]|\[([^\]]*)\]\([^)]*\)/', '$1', $text));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-') ?: 'section';
}

/** A line "--- Patreon ---" (any number of dashes, any case): in blog posts the text under it is for patrons (m3d_post_free()); md_html() with
 *  'paywall' => true draws it as the "Patreon Access from Here" bar (md_paywall(), for the post's writers), 'paywall' => 'skip' leaves it out
 *  (readers). Elsewhere it stays a plain line of text. */
const MD_PAYWALL = '[ ]{0,3}-{3,}[ ]*patreon[ ]*-{3,}[ ]*';
const MD_IMG = '/^!\[([^\]]*)\]\(((?:https?:\/\/|\/(?![\/\\\\]))[^\s)]+)\)$/';   // "//other-site/…" (or "/\other-site/…") isn't a picture of this site: never loaded

/**
 * One list (bullets or numbered) from its lines. Indented lines continue the item above; indented "- " or "1. " lines make a
 * sub-list, which can hold its own sub-lists (any depth: each level indented further than the one above).
 */
function md_list(array $lines): string
{
    $ordered = (bool) preg_match('/^\d+\.\s/', $lines[0]);
    $items = [];
    foreach ($lines as $line) {
        if (preg_match($ordered ? '/^(\d+)\.\s+(.*)$/' : '/^[-–]\s+(.*)$/u', $line, $m)) {
            $items[] = ['n' => $ordered ? (int) $m[1] : 0, 'text' => $ordered ? $m[2] : $m[1], 'more' => []];
        } else {
            $items[count($items) - 1]['more'][] = $line;   // indentation kept: it tells the sub-lists' levels apart
        }
    }
    $start = $ordered && $items[0]['n'] > 1 ? ' start="' . $items[0]['n'] . '"' : '';
    $out = $ordered ? "<ol$start>" : '<ul>';
    foreach ($items as $it) $out .= '<li>' . md_inline($it['text']) . md_list_more($it['more']) . '</li>';
    return $out . ($ordered ? '</ol>' : '</ul>');
}

/**
 * What follows an item's first line: continuation text, and sub-lists ("- " or "1. " lines). A sub-item starts or continues the
 * sub-list; lines indented further than its first item belong to it (that item's text, or a deeper level); text that isn't
 * indented further ends it and continues the item itself.
 */
function md_list_more(array $lines): string
{
    $out = ''; $text = []; $sub = []; $subType = ''; $subInd = 0;
    $flush = function () use (&$text, &$sub, &$out) {
        if ($text) { $out .= '<span class="li-more">' . md_inline(implode("\n", $text)) . '</span>'; $text = []; }
        if ($sub) { $out .= md_list($sub); $sub = []; }
    };
    foreach ($lines as $l) {
        $t = ltrim($l, ' ');
        if ($t === '') continue;
        $ind = strlen($l) - strlen($t);
        $type = preg_match('/^\d+\.\s/', $t) ? 'ol' : (preg_match('/^[-–]\s/u', $t) ? 'ul' : '');
        if ($sub && $ind > $subInd) {
            $sub[] = substr($l, $subInd);   // deeper: the sub-item's own text or sub-list
        } elseif ($type !== '') {
            if ($text || ($sub && $type !== $subType)) $flush();   // after text, or a sub-list of the other kind: a new sub-list
            if (!$sub) { $subType = $type; $subInd = $ind; }
            $sub[] = $t;
        } else {
            if ($sub) $flush();
            $text[] = $t;
        }
    }
    $flush();
    return $out;
}

function md_table(array $lines): string
{
    $rows = [];
    foreach ($lines as $l) {
        if (preg_match('/^\|?\s*:?-{2,}/', $l)) continue;   // |---|---| separator
        $rows[] = array_map('trim', explode('|', trim(trim($l), '|')));
    }
    if (!$rows) return '<p>' . md_inline(implode("\n", $lines)) . '</p>';   // only separator lines: not a table
    $head = array_shift($rows);
    $out = '<div class="md-table"><table><thead><tr>' . implode('', array_map(fn($c) => '<th>' . md_inline($c) . '</th>', $head)) . '</tr></thead><tbody>';
    foreach ($rows as $r) $out .= '<tr>' . implode('', array_map(fn($c) => '<td>' . md_inline($c) . '</td>', $r)) . '</tr>';
    return $out . '</tbody></table></div>';
}

function md_images(array $imgs): string
{
    $set = slides_attr(array_map(fn($m) => ['src' => $m[2], 'caption' => $m[1]], $imgs));
    $fig = function ($m, $i) {
        $small = img_small($m[2]);
        $size = fn($u) => img_dims($u) ?: false;   // cached: no file opened on every visit
        $big = $size($m[2]);
        $dim = $small !== $m[2] ? $size($small) : $big;
        // shown as wide as the large file (up to the text column), whichever file the browser loads: screenshots stay
        // readable on sharp screens. The w values must be the files' real widths, or the browser scales the picture down.
        $srcset = '';
        if ($small !== $m[2] && $big && $dim) {
            $w = min($big[0], 780);
            $srcset = ' srcset="' . e(img_srcset($m[2], ['sm', 'md', ''])) . '" sizes="(max-width: ' . ($w + 80) . 'px) 100vw, ' . $w . 'px"';
        }
        // known size → the page doesn't jump while pictures load (links to #headings land in the right place)
        $show = $big ?: $dim;
        $wh = $show ? ' width="' . $show[0] . '" height="' . $show[1] . '"' : '';
        return '<figure><button class="md-zoom" data-slide="' . $i . '" aria-label="Enlarge image"><img src="' . e($small) . '"' . $srcset . $wh . ' alt="' . e($m[1]) . '" loading="lazy" decoding="async" /></button>'
            . ($m[1] !== '' ? '<figcaption>' . e($m[1]) . '</figcaption>' : '') . '</figure>';
    };
    if (count($imgs) === 1) return '<div class="md-img" data-slides="' . $set . '">' . $fig($imgs[0], 0) . '</div>';
    return '<div class="md-img md-gallery" data-slides="' . $set . '">' . implode('', array_map($fig, $imgs, array_keys($imgs))) . '</div>';
}

/** Box types: "> [!TIP]" etc. (the GitHub syntax). Label shown when no title is given, and the icon. */
const CALLOUTS = [
    'note'      => ['Note', '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>'],
    'tip'       => ['Tip', '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.6.5 1.1 1.2 1.1 2V16h5v-.2c0-.8.5-1.5 1.1-2A6 6 0 0 0 12 3z"/>'],
    'important' => ['Important', '<path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v4M12 14h.01"/>'],
    'warning'   => ['Warning', '<path d="M10.3 3.9 2.4 17.5A2 2 0 0 0 4.1 20.5h15.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>'],
    'caution'   => ['Caution', '<path d="M7.9 2h8.2L22 7.9v8.2L16.1 22H7.9L2 16.1V7.9z"/><path d="M12 8v4M12 16h.01"/>'],
];
const CALLOUT_ALIASES = ['info' => 'note', 'hint' => 'tip', 'danger' => 'caution', 'attention' => 'warning'];

/** "> " block → box. "[!TYPE] Optional title" on the first line picks the type; plain "> " is an untitled note. */
function md_callout(string $inner, array $opts = []): string
{
    $type = 'note';
    $title = null;
    if (preg_match('/^\[!(\w+)\][ ]*(.*)$/', strtok(ltrim($inner), "\n"), $m)) {   // the first line only
        $t = strtolower($m[1]);
        $t = CALLOUT_ALIASES[$t] ?? $t;
        if (isset(CALLOUTS[$t])) {
            $type = $t;
            $title = trim($m[2]) !== '' ? trim($m[2]) : CALLOUTS[$t][0];
            $inner = ltrim(substr(ltrim($inner), strlen($m[0])), "\n");
        }
    } elseif (preg_match('/^\*\*(warning|caution|important|tip|note)\b/i', ltrim($inner), $m)) {
        $type = strtolower($m[1]);   // "> **Warning:** …": typed by hand, and how the SmutBase import writes a quoted warning
    }
    $head = $title !== null
        ? '<p class="callout-title"><svg viewBox="0 0 24 24" aria-hidden="true">' . CALLOUTS[$type][1] . '</svg>' . md_inline($title) . '</p>'
        : '';
    // the content can hold several paragraphs (an empty "> " line between them), lists, code…
    $body = trim($inner) !== '' ? md_html($inner, array_intersect_key($opts, ['noImages' => 1, 'picLinks' => 1])) : '';
    return '<div class="callout callout-' . $type . ($title === null ? ' untitled' : '') . '" role="note">' . $head . $body . '</div>';
}

/**
 * Tab groups: "=== Name" starts a tab, a line with only "===" (or the end of the text) closes the group.
 * Each group becomes a placeholder block; $tabs gets its HTML. The tab's content is rendered by md_html() again,
 * without heading ids (a heading inside a tab is not in "On this page": its link would point into a hidden tab).
 * Without JavaScript every tab shows, one under the other, with its name; site.js turns the group into tabs.
 */
function md_tabs(string $text, array $opts, array &$tabs): string
{
    if (!preg_match('/^===[ ]+\S/m', $text)) return $text;
    $inner = array_diff_key($opts, ['docs' => 1]);
    $out = [];
    $group = null;   // [[name, lines], …] while inside a group
    $fence = false;
    $close = function () use (&$group, &$out, &$tabs, $inner) {
        $list = $panels = '';
        foreach ($group as [$name, $lines]) {
            $key = mb_strtolower($name);
            $list .= '<button type="button" role="tab" data-tab="' . e($key) . '">' . e($name) . '</button>';
            $panels .= '<div class="md-tab-panel" role="tabpanel" data-tab-panel="' . e($key) . '"><p class="md-tab-name">' . e($name) . '</p>'
                . md_html(implode("\n", $lines), $inner) . '</div>';
        }
        $tabs[] = '<div class="md-tabs" data-md-tabs><div class="md-tab-list" role="tablist" hidden>' . $list . '</div>' . $panels . '</div>';
        $out[] = '';
        $out[] = "\x03" . (count($tabs) - 1) . "\x03";
        $out[] = '';
        $group = null;
    };
    foreach (explode("\n", $text) as $line) {
        if (!$fence && preg_match('/^===[ ]+(\S.*?)[ ]*$/', $line, $m)) {   // a new tab (and a new group when outside one)
            $group ??= [];
            $group[] = [mb_substr($m[1], 0, 60), []];
            continue;
        }
        if (str_starts_with(ltrim($line), '```')) $fence = !$fence;   // "=== …" lines inside code stay code
        if ($group !== null && !$fence && rtrim($line) === '===') { $close(); continue; }
        if ($group !== null) $group[count($group) - 1][1][] = $line;
        else $out[] = $line;
    }
    if ($group !== null) $close();
    return implode("\n", $out);
}

/**
 * The simple text format used by FAQ answers and docs pages (see CLAUDE.md). Everything is escaped first.
 * $opts['docs'] = true: "## " → h2 and "### " → h3 with ids, collected in $toc. Otherwise "## " → h3, "### " → h4 (FAQ).
 * $opts['noImages']: pictures left out (blurred items); $opts['picLinks']: pictures shown as links (texts written by visitors: bug reports).
 */
function md_html(string $text, array $opts = [], ?array &$toc = null): string
{
    $was = md_ugc();
    if (!empty($opts['picLinks'])) md_ugc(true);
    try {
        return md_blocks($text, $opts, $toc);
    } finally {
        md_ugc($was);
    }
}

/** True while md_html() renders a text written by a visitor ('picLinks'): md_inline() then marks links nofollow ugc. */
function md_ugc(?bool $set = null): bool
{
    static $on = false;
    if ($set !== null) $on = $set;
    return $on;
}

/** md_html() itself. */
function md_blocks(string $text, array $opts, ?array &$toc): string
{
    $docs = !empty($opts['docs']);
    $toc = [];
    $ids = ['main' => true];   // the layout's <main id="main">: a heading "Main" becomes main-2
    $code = [];
    $text = str_replace(["\r\n", "\t"], ["\n", '    '], $text);
    $tabs = [];
    $text = md_tabs($text, $opts, $tabs);   // "=== Name" tab groups first: a tab can hold anything, blank lines too
    // fenced code blocks first: they may contain blank lines
    $text = preg_replace_callback('/^```([\w+-]*)[ ]*\n(.*?)\n?```[ ]*$/ms', function ($m) use (&$code) {
        $code[] = [$m[1], $m[2]];
        return "\n\n\x02" . (count($code) - 1) . "\x02\n\n";   // (not \x00: trim() strips it)
    }, $text);
    // a heading line is a block of its own, also with text right under (or above) it and no blank line between
    $text = preg_replace('/^(#{2,3}[ ]+\S.*)$/m', "\n\n$1\n\n", $text);
    if (!empty($opts['paywall'])) $text = preg_replace('/^' . MD_PAYWALL . '$/mi', "\n\n$0\n\n", $text);   // the bar is a block of its own too
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($text)) as $block) {
        $block = rtrim($block);
        if (trim($block) === '') continue;
        if (preg_match('/^\x03(\d+)\x03$/', trim($block), $m)) { $out .= $tabs[(int) $m[1]]; continue; }
        if (preg_match('/^\x02(\d+)\x02$/', trim($block), $m)) {
            [$lang, $src] = $code[(int) $m[1]];
            $lines = explode("\n", $src);
            $numbered = $lang !== '' && count($lines) > 1;
            $body = $numbered ? implode('', array_map(fn($l) => '<span class="ln">' . ($l === '' ? ' ' : e($l)) . '</span>', $lines)) : e($src);
            $out .= '<div class="md-code' . ($numbered ? ' numbered' : '') . '"><button type="button" class="code-copy" data-copy-code>Copy</button><pre><code' . ($lang !== '' ? ' data-lang="' . e($lang) . '"' : '') . '>' . $body . '</code></pre></div>';
            continue;
        }
        if (!empty($opts['paywall']) && preg_match('/^' . MD_PAYWALL . '$/i', $block)) { $out .= $opts['paywall'] === 'skip' ? '' : md_paywall(); continue; }
        $lines = explode("\n", $block);
        $imgs = [];
        foreach ($lines as $l) { if (preg_match(MD_IMG, trim($l), $im)) $imgs[] = $im; else { $imgs = []; break; } }
        if ($imgs) {
            if (!empty($opts['picLinks'])) $out .= '<p>' . implode('<br>', array_map(fn($im) => '<a href="' . e($im[2]) . '" rel="nofollow ugc noopener">' . e($im[1] !== '' ? $im[1] : $im[2]) . '</a>', $imgs)) . '</p>';   // visitors' texts: a link, never a picture from another website
            elseif (empty($opts['noImages'])) $out .= md_images($imgs);   // noImages: blurred items
            continue;
        }
        if (count($lines) === 1 && preg_match('/^(#{2,3})\s+(.+)$/', $block, $m)) {
            $level = strlen($m[1]);
            if ($docs) {
                $id = $base = md_id($m[2]);
                for ($n = 2; isset($ids[$id]); $n++) $id = "$base-$n";
                $ids[$id] = true;
                $toc[] = ['level' => $level, 'id' => $id, 'text' => trim(html_entity_decode(strip_tags(md_inline($m[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
                $out .= "<h$level id=\"" . e($id) . '">' . md_inline($m[2]) . copy_link($id, 'h-anchor', 'Copy link to this section') . "</h$level>";
            } else {
                $out .= '<h' . ($level + 1) . '>' . md_inline($m[2]) . '</h' . ($level + 1) . '>';
            }
            continue;
        }
        if (str_starts_with($lines[0], '|') && count($lines) > 1 && !array_filter($lines, fn($l) => !str_starts_with(trim($l), '|'))) {
            $out .= md_table($lines);
            continue;
        }
        if (!array_filter($lines, fn($l) => !preg_match('/^>\s?/', $l))) {
            $out .= md_callout(implode("\n", array_map(fn($l) => preg_replace('/^>\s?/', '', $l), $lines)), $opts);
            continue;
        }
        // text lines followed by a list in the same block: paragraph + list
        $first = null;
        foreach ($lines as $i => $l) if (preg_match('/^(-|\d+\.)\s/', $l)) { $first = $i; break; }
        if ($first !== null) {
            if ($first > 0) $out .= '<p>' . md_inline(implode("\n", array_slice($lines, 0, $first))) . '</p>';
            $out .= md_list(array_slice($lines, $first));
            continue;
        }
        $out .= '<p>' . md_inline($block) . '</p>';
    }
    return $out;
}

/** The bar where the patrons' part of a blog post starts (MD_PAYWALL). */
function md_paywall(): string
{
    return '<div class="md-paywall" role="separator"><span>Patreon Access from Here <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M6 13l6 6 6-6"/></svg></span></div>';
}

/**
 * The first plain paragraph of a text in the md_html() format, as written (for md_inline()): headings, lists, boxes, tables,
 * code, pictures and tabs are skipped; '' when there is none. The homepage slideshow shows it on one line under the name.
 */
function md_first_text(string $text): string
{
    $text = preg_replace('/^```.*?^```[ ]*$/ms', '', str_replace("\r\n", "\n", $text));   // code blocks may hold blank lines
    foreach (preg_split('/\n\s*\n/', trim((string) $text)) as $block) {
        $lines = array_values(array_filter(explode("\n", rtrim($block)), fn($l) => !preg_match('/^===(\s|$)/', $l)));   // tab names and ends
        while ($lines && preg_match('/^#{1,6}\s/', $lines[0])) array_shift($lines);   // a heading, then text: the text
        if (!$lines || trim($lines[0]) === '' || preg_match('/^(-|\d+\.)\s|^\s*[>|]|^```|^\s/', $lines[0])) continue;
        if (!array_filter($lines, fn($l) => !preg_match(MD_IMG, trim($l)))) continue;   // only pictures
        $keep = [];
        foreach ($lines as $l) { if (preg_match('/^(-|\d+\.)\s/', $l)) break; $keep[] = $l; }   // text lines, then a list: the text
        return trim(implode("\n", $keep));
    }
    return '';
}

function faq_id(string $q): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($q)), '-') ?: 'q';
}
