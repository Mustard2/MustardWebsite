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

/** /assets/x.css?v=<mtime> so browsers and Cloudflare pick up new versions after an update. */
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

/** Full-screen 18+ check shown until the visitor confirms (plain links: works without JavaScript). */
function age_gate(string $path): string
{
    $q = $_SERVER['QUERY_STRING'] ?? '';
    $return = rawurlencode($path . ($q !== '' ? '?' . $q : ''));
    return '<div class="age-gate" role="dialog" aria-modal="true" aria-labelledby="age-title">'
        . '<div class="age-box">'
        . '<a class="brand" href="/">' . brand() . '</a>'
        . '<p class="age-pill">18+ only</p>'
        . '<h1 id="age-title">Before you enter</h1>'
        . '<p class="muted">This website contains content intended for adults only. By entering, you confirm that you are at least 18 years old, or the age of majority where you live.</p>'
        . '<div class="age-cta">'
        . '<a class="btn btn-primary" href="/age.php?return=' . e($return) . '">I am 18 or older · Enter</a>'
        . '<a class="btn btn-ghost" href="https://www.google.com" rel="noreferrer">I am under 18 · Leave</a>'
        . '</div>'
        . '<p class="fine">Your answer is remembered on this device for ' . AGE_DAYS . ' days.</p>'
        . '</div></div>';
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
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Back soon · <?= e(site_name()) ?></title>
<?= head_identity() ?>  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
<?= accent_style() ?></head>
<body class="page-gate page-maintenance">
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/" aria-label="<?= e(site_name()) ?> home"><?= brand() ?></a>
      <div class="account" data-account>
        <a class="btn btn-patreon btn-sm" href="/auth/login.php?return=<?= e(rawurlencode($path)) ?>" data-login>Log in with Patreon</a>
      </div>
    </div>
  </header>
  <main id="main">
    <section class="wrap gate">
      <p class="eyebrow">Maintenance</p>
      <h1>Back soon</h1>
      <p class="lead pre"><?= e(trim($m['message']) !== '' ? $m['message'] : 'The website is being updated. Please come back in a little while.') ?></p>
      <?php if ($p): ?><p class="note muted">You are logged in as <?= e($p['name'] ?? '') ?>. During maintenance only the site owner can enter. <a href="/auth/logout.php?return=%2F">Log out</a></p><?php endif ?>
      <div class="cta"><?php if (social_url('discord') !== ''): ?><a class="btn btn-ghost" href="<?= e(social_url('discord')) ?>" rel="noopener">News on Discord</a><?php endif ?><?php if (social_url('patreon') !== ''): ?><a class="btn btn-ghost" href="<?= e(social_url('patreon')) ?>" rel="noopener">Patreon</a><?php endif ?></div>
    </section>
  </main>
  <footer class="site-footer">
    <div class="wrap footer-inner">
      <a class="brand" href="/"><?= brand() ?></a>
      <nav class="footer-links" aria-label="Legal">
        <a href="<?= e(SITE['privacy']) ?>">Privacy policy</a>
        <a href="<?= e(SITE['contacts']) ?>">Contacts</a>
      </nav>
    </div>
  </footer>
  <script src="<?= e(asset('site.js')) ?>" defer></script>
</body>
</html>
<?php
    exit;
}

/** Absolute address of a site path (link previews need one): this host, https behind Cloudflare. */
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
    $locked = !age_confirmed();
    if ($locked) $bodyClass = trim($bodyClass . ' age-locked');
    $full = $title !== '' ? $title . ' · ' . site_name() : site_name() . ' · ' . site_tagline();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    header('Content-Type: text/html; charset=utf-8');
    // never stored by Cloudflare or other shared caches: the page differs with the 18+ cookie and the login
    if (session_status() !== PHP_SESSION_ACTIVE) header('Cache-Control: private, no-cache');
    ?>
<!doctype html>
<html lang="en">
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
<?php if ($locked) echo age_gate($path); ?>
  <a class="skip" href="#main">Skip to content</a>
<?php if (m3d_maintenance() && m3d_is_admin($GLOBALS['M3D'])): ?>
  <p class="maint-bar">Maintenance mode is on: only admins can see the site. <a href="/admin/settings.php#maintenance">Turn it off</a></p>
<?php endif ?>
<?= preview_bar($_SERVER['REQUEST_URI'] ?? $path) ?>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="brand" href="/" aria-label="<?= e(site_name()) ?> home"><?= brand() ?></a>
      <nav class="nav" aria-label="Main">
        <a href="/#catalog">Characters</a>
        <a href="/accessories/"<?= str_starts_with($path, '/accessories/') ? ' aria-current="page"' : '' ?>>Accessories</a>
<?php if (m3d_mustardui_on()): ?>        <a href="<?= e(SITE['mustarduiPage']) ?>"<?= str_starts_with($path, '/mustardui') ? ' aria-current="page"' : '' ?>>MustardUI</a>
<?php endif ?><?php if (m3d_has_docs()): ?>        <a href="<?= e(SITE['docs']) ?>"<?= str_starts_with($path, '/docs/') ? ' aria-current="page"' : '' ?>>Docs</a>
<?php endif ?><?php if (m3d_has_faq()): ?>        <a href="<?= e(SITE['faqs']) ?>"<?= str_starts_with($path, '/faq') ? ' aria-current="page"' : '' ?>>FAQ</a>
<?php endif ?>
      </nav>
      <div class="account" data-account>
        <a class="btn btn-patreon btn-sm" href="/auth/login.php?return=<?= e(rawurlencode($path)) ?>" data-login>Log in with Patreon</a>
      </div>
    </div>
  </header>
<?= site_notice($path) ?>
  <main id="main">
<?php
}

/** Notice types: label (for screen readers and the admin), colour and icon (the box icons of the text format). */
const NOTICE_TYPES = [
    'info'    => ['Info', 'note'],
    'update'  => ['Update', 'important'],
    'success' => ['Good news', 'tip'],
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
        <a href="/#catalog">Characters</a>
        <a href="/accessories/">Accessories</a>
<?php if (m3d_mustardui_on()): ?>        <a href="<?= e(SITE['mustarduiPage']) ?>">MustardUI</a>
<?php endif ?><?php if (m3d_has_docs()): ?>        <a href="<?= e(SITE['docs']) ?>">Docs</a>
<?php endif ?><?php if (m3d_has_faq()): ?>        <a href="<?= e(SITE['faqs']) ?>">FAQ</a>
<?php endif ?>
      </nav>
      <nav class="footer-links" aria-label="Legal">
        <a href="<?= e(SITE['privacy']) ?>">Privacy policy</a>
        <a href="<?= e(SITE['contacts']) ?>">Contacts</a>
      </nav>
    </div>
  </footer>
  <dialog class="lightbox" data-lightbox aria-label="Image viewer">
    <figure>
      <img alt="" data-lightbox-img />
      <figcaption><span data-lightbox-cap></span><span class="lb-count" data-lightbox-count></span></figcaption>
    </figure>
    <button class="lb-nav lb-prev" data-lb-prev aria-label="Previous image">‹</button>
    <button class="lb-nav lb-next" data-lb-next aria-label="Next image">›</button>
    <button class="lb-close" data-lightbox-close aria-label="Close">✕</button>
  </dialog>
  <script src="<?= e(asset('site.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Model render, or a generated placeholder (colour from the franchise, initials from the name).
 *  $blur: the picture is swapped for its tiny blurred copy (blur_src), never the real file. */
function cover(array $m, ?string $src, string $size = 'card', bool $eager = false, bool $blur = false, string $tags = ''): string
{
    if ($blur && $src) $src = blur_src($src) ?: null;
    $h = 0;
    foreach (str_split((string) ($m['franchise'] ?? '')) as $c) $h = ($h * 31 + ord($c)) % 360;
    $words = preg_split('/\s+(?:and\s+)?/', trim(preg_replace('/\(.*?\)/', '', (string) ($m['name'] ?? ''))));
    $initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(array_filter($words), 0, 2)));
    $gen = isset($m['generation']) && $m['generation'] !== '' ? '<span class="gen">Gen ' . e($m['generation']) . '</span>' : '';
    // big pictures (homepage slideshow, model page): phones get the small version (srcset), the first one loads first
    $srcset = '';
    if ($size === 'hero' && $src && !$blur && ($sm = img_small($src)) !== $src && ($a = @getimagesize((string) media_path($sm))) && ($b = @getimagesize((string) media_path($src)))) {
        $srcset = ' srcset="' . e($sm) . ' ' . $a[0] . 'w, ' . e($src) . ' ' . $b[0] . 'w" sizes="(max-width: 760px) 92vw, 560px"';
    }
    $inner = $src
        ? '<img src="' . e($src) . '"' . $srcset . ' alt="' . e(($m['name'] ?? '') . ($blur ? ' (picture hidden)' : ' render')) . '" loading="' . ($eager ? 'eager' : 'lazy') . '"' . ($eager ? ' fetchpriority="high"' : '') . ' decoding="async" />'
        : '<div class="ph" aria-hidden="true"><span class="ph-initials">' . e($initials) . '</span></div>';
    // tags over the picture, top left: Gen, then the card's extra ones ($tags: v2, α/β, Password / Teaser; already escaped)
    $tagRow = $gen . $tags !== '' ? '<span class="cover-tags">' . $gen . $tags . '</span>' : '';
    return '<div class="cover cover-' . e($size) . ($blur ? ' is-blurred' : '') . '" style="--h:' . $h . '">' . $inner . $tagRow . '</div>';
}

/** Small "Password" / "Teaser" pill on cards of items that aren't fully public. */
function visibility_pill(string $kind, array $item): string
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

function model_card(array $m): string
{
    $s = status_info($m['status'] ?? '');
    return '<a class="card" href="/models/' . e($m['slug']) . '/" data-card data-slug="' . e($m['slug']) . '"'
        . ' data-name="' . e(mb_strtolower((string) $m['name'])) . '"'
        . ' data-franchise="' . e($m['franchise'] ?? '') . '"'
        . ' data-generation="' . e($m['generation'] ?? '') . '"'
        . ' data-status="' . e($m['status'] ?? '') . '"'
        . ' data-order="' . e($m['order'] ?? 999) . '"'
        . ' data-downloads="' . m3d_downloads('models', (string) $m['slug'])['total'] . '"'
        . ' data-likes="' . ($likes = m3d_like_count((string) $m['slug'])) . '">'
        // v2, α/β (latest version) and Password / Teaser sit on the picture next to Gen, so every card body has the same rows:
        // franchise, name, status with the likes on the right
        . cover($m, img_small(($m['thumb'] ?? '') ?: ($m['cover'] ?? '')) ?: null, 'card', false, m3d_pics_hidden('models', $m),
            (m3d_model_versions($m) ? '<span class="ver-tag" title="Major version">v' . (int) $m['majorVersion'] . '</span>' : '')
            . stage_badge(m3d_latest_version($m)['stage'], true) . visibility_pill('models', $m))
        . '<div class="card-body"><p class="franchise">' . e($m['franchise'] ?? '') . '</p>'
        . '<h3>' . e($m['name']) . '</h3>'
        . '<span class="card-meta"><span class="badge tone-' . e($s['tone']) . '">' . e($s['label']) . '</span>'
        . ($likes ? '<span class="likes-pill" title="' . $likes . ' ' . ($likes === 1 ? 'like' : 'likes') . ' from patrons">' . HEART_ICON . $likes . '</span>' : '')
        . '</span></div></a>';
}

/** Changelog highlights become short badges; the original sentence stays as a tooltip. */
function highlight_badge(?string $h): string
{
    if (!$h) return '';
    $same = preg_match('/textur/i', $h) && preg_match('/\b(not|no|un|without)\b.{0,20}(chang|new|updat)|unchanged|same textur/i', $h);
    if ($same) [$label, $tone] = ['Same textures', 'grey'];                                  // "textures are not changed": keep yours
    elseif (preg_match('/textur/i', $h)) [$label, $tone] = ['New textures', 'sky'];          // means: download the textures again
    elseif (m3d_mustardui_on() && preg_match('/mustardui/i', $h)) [$label, $tone] = ['New MustardUI', 'violet'];   // only with the MustardUI page on
    elseif (preg_match('/(first|initial) release/i', $h)) [$label, $tone] = ['First release', 'green'];
    elseif (preg_match('/last blender ([\d.]+)/i', $h, $mm)) [$label, $tone] = ['Last for Blender ' . $mm[1], 'grey'];
    elseif (preg_match('/in develop/i', $h)) [$label, $tone] = ['In development', 'grey'];
    else [$label, $tone] = [rtrim($h, '!.'), 'grey'];
    return '<span class="badge tone-' . $tone . '" title="' . e($h) . '">' . e($label) . '</span>';
}

/** "Fix: ..." / "New: ..." prefixes on changelog lines become small coloured tags. */
function change_line(string $line): string
{
    $tones = ['new' => 'new', 'feature' => 'new', 'fix' => 'fix', 'bug' => 'fix', 'enhancement' => 'enh', 'improvement' => 'enh',
              'optimization' => 'opt', 'removal' => 'rem', 'ui' => 'ui', 'outfit' => '', 'outfits' => '', 'physics' => '', 'note' => ''];
    if (preg_match('/^(New|Feature|Fix|Bug|Enhancement|Improvement|Optimization|Removal|UI|Outfits?|Physics|Note)(\s*\([^)]*\))?:\s+(.*)$/s', $line, $m)) {
        return '<li><span class="tag tag-' . $tones[strtolower($m[1])] . '">' . e($m[1] . ($m[2] ?? '')) . '</span><span class="chg md">' . md_inline($m[3]) . '</span></li>';
    }
    return '<li><span class="chg md">' . md_inline($line) . '</span></li>';
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
const PLAN_BUTTONS = ['discord' => 'Join the Discord', 'patreon' => 'Join on Patreon', 'login' => 'Already a patron? Log in'];

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
    layout_start('Page not found', 'This page does not exist.', 'page-gate');
    echo '<section class="wrap gate"><p class="eyebrow">404</p><h1>Page not found</h1><p class="lead">This page doesn’t exist, or the model was renamed.</p><div class="cta"><a class="btn btn-primary" href="/#catalog">Browse all models</a></div></section>';
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
    if ($v !== 'public') header('X-Robots-Tag: noindex');
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
    $src = $kind === 'outfits' ? outfit_preview($item) : img_small(($item['thumb'] ?? '') ?: ($item['cover'] ?? ''));
    http_response_code($wrong ? 403 : 200);
    layout_start($item['name'], 'This ' . $what . ' is password protected.', 'page-gate');
    ?>
  <section class="wrap gate lock-gate">
    <div class="lock-art"><?= cover($kind === 'outfits' ? ['name' => $item['name'], 'franchise' => m3d_accessory_type($item)] : $item, $src ?: null, 'card', true, true) ?></div>
    <p class="eyebrow"><?= LOCK_ICON ?> Password protected</p>
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
    return '<form class="preview-bar" method="post" action="/admin/patron-preview.php">'
        . '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="return" value="' . e(m3d_safe_return($return, '/')) . '" />'
        . '<b class="pv-title">Preview as a patron</b>'
        . '<label><span>Membership</span><select name="tier">' . $opts . '</select></label>'
        . '<label><span>Months as a patron</span><input type="number" name="months" min="0" max="999" value="' . (int) ($pv['months'] ?? 0) . '" /></label>'
        . '<label><span>Total support (' . e(m3d_currency()) . ')</span><input type="number" name="total" min="0" step="0.01" value="' . e($total % 100 ? number_format($total / 100, 2, '.', '') : (string) intdiv($total, 100)) . '" /></label>'
        . '<label title="Counted as downloaded a minute ago (other models than the one you look at), so they count in every limit period."><span>Models downloaded</span><input type="number" name="dl_models" min="0" max="99" value="' . (int) ($pv['dl_models'] ?? 0) . '" /></label>'
        . '<label title="Counted as downloaded a minute ago, so they count in every limit period."><span>Accessories downloaded</span><input type="number" name="dl_outfits" min="0" max="99" value="' . (int) ($pv['dl_outfits'] ?? 0) . '" /></label>'
        . '<label title="This many models/accessories (catalog order, with a dated latest version) count as downloaded before their latest version: the account button gets the dot and My Models shows them as updates."><span>Simulated updates</span><input type="number" name="updates" min="0" max="20" value="' . (int) ($pv['updates'] ?? 0) . '" /></label>'
        . '<label title="Optional: a real Patreon user ID (a long number, see Admin → Downloads). Adds that patron’s real downloads (limits, Unlocked) and their special access (Admin → Access)."><span>Copy from patron ID</span><input name="as" inputmode="numeric" pattern="\\d{1,20}" placeholder="optional" value="' . e($pv['as'] ?? '') . '" /></label>'
        . '<span class="pv-btns"><button class="btn btn-primary btn-sm" name="do" value="apply">Apply</button><button class="btn btn-ghost btn-sm" name="do" value="stop">Stop preview</button></span>'
        . '</form>';
}

/** In preview, a download stops here: what would happen, without downloading, logging or counting anything. */
function preview_download_page(string $kind, array $item, string $label): never
{
    $page = m3d_item_url($kind, $item['slug']);
    header('Cache-Control: no-store');
    layout_start('Download · ' . $item['name'], 'Preview of a download.', 'page-gate');
    ?>
  <section class="wrap gate">
    <p class="eyebrow">Preview</p>
    <h1>The download would start now</h1>
    <p class="lead"><?= e($item['name']) ?><?= $label !== '' ? ' · ' . e($label) : '' ?>: allowed. Nothing is downloaded or logged in the preview.</p>
    <div class="cta"><a class="btn btn-ghost" href="<?= e($page) ?>">Back to <?= e($item['name']) ?></a></div>
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
        : '<a href="/auth/login.php?return=' . e(rawurlencode($path)) . '">Log in with Patreon</a>';
    return '<p class="pics-note">' . LOCK_ICON . '<span>Patrons on any tier see the full pictures. ' . $cta . '</span></p>';
}

/** Text under the title of the Accessories page (Admin → Accessories → Page text, settings.json → outfits_intro; '' = none). */
const OUTFITS_INTRO_DEFAULT = 'Outfits, hair styles and other accessories made for the models. Pick a model to see what fits it.';

function outfits_intro(): string
{
    $t = m3d_settings()['outfits_intro'] ?? null;
    return is_string($t) ? $t : OUTFITS_INTRO_DEFAULT;
}

/** "Your download starts in…" page (auth/get.php). $go = the same /get/ address with the signed ?t=: never the real link. */
function download_wait_page(string $kind, array $item, string $label, int $seconds, string $go, ?array $confirm = null): never
{
    $page = m3d_item_url($kind, $item['slug']);
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    header('Cache-Control: no-store');
    layout_start('Download · ' . $item['name'], 'Your download is starting.', 'page-gate');
    ?>
  <section class="wrap gate dl-wait" data-dl-wait="<?= (int) $seconds ?>" data-dl-go="<?= e($go) ?>">
    <p class="eyebrow">Download</p>
    <h1><?= e($item['name']) ?></h1>
    <?php if ($confirm): ?>
      <div data-dl-ask>
        <p class="lead">This uses one of your unlocks<?= $label !== '' ? ' (' . e($label) . ')' : '' ?>. Unlock this <?= $what ?>?</p>
        <div class="req-table">
          <table>
            <thead><tr><th>Limit</th><th>Left now</th><th>After this</th></tr></thead>
            <tbody>
              <?php foreach ($confirm as $r): $left = max(0, $r['max'] - $r['used']); ?>
                <tr><th><?= e(ucfirst($r['text'])) ?></th><td class="req-used"><?= $left ?> of <?= (int) $r['max'] ?></td><td class="req-used"><?= max(0, $left - 1) ?> of <?= (int) $r['max'] ?></td></tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <p class="small muted">After this, you can download this <?= $what ?> again freely.</p>
        <div class="cta">
          <a class="btn btn-primary" href="<?= e($go) ?>" data-dl-yes>Yes, unlock</a>
          <a class="btn btn-ghost" href="<?= e($page) ?>">Not now</a>
        </div>
      </div>
    <?php endif ?>
    <div data-dl-count<?= $confirm ? ' hidden' : '' ?>>
      <p class="lead"><?= $label !== '' ? e($label) . ': y' : 'Y' ?>our download starts in <b data-dl-left><?= (int) $seconds ?></b> <span data-dl-unit><?= $seconds === 1 ? 'second' : 'seconds' ?></span>.</p>
      <div class="dl-bar" aria-hidden="true"><span data-dl-bar></span></div>
      <div class="cta">
        <a class="btn btn-primary" href="<?= e($go) ?>" data-dl-now aria-disabled="true">Download</a>
        <a class="btn btn-ghost" href="<?= e($page) ?>">Back to <?= e($item['name']) ?></a>
      </div>
      <p class="fine">Didn’t start? Use the Download button once the countdown ends.</p>
    </div>
  </section>
<?php
    layout_end();
    exit;
}

/** Links and note of the "Getting started" box for 'models' or 'outfits' (settings.json → start.<kind>; empty until set). */
function start_box(string $kind): array
{
    $s = m3d_settings()['start'][$kind] ?? [];
    if (!is_array($s)) $s = [];
    return ['links' => array_map(fn($l) => [(string) ($l['label'] ?? ''), (string) ($l['url'] ?? '')], (array) ($s['links'] ?? [])), 'note' => (string) ($s['note'] ?? '')];
}

/** The "Getting started" box (nothing when it has no links and no note). */
function start_box_html(string $kind): string
{
    $b = start_box($kind);
    if (!$b['links'] && trim($b['note']) === '') return '';
    $links = '';
    foreach ($b['links'] as [$label, $url]) {
        $ext = preg_match('#^https?://#', $url) ? ' rel="noopener"' : '';
        $links .= '<a href="' . e($url) . '"' . $ext . '>' . e($label) . '</a>';
    }
    return '<div class="start"><p class="start-title">Getting started</p>'
        . ($links !== '' ? '<div class="start-links">' . $links . '</div>' : '')
        . (trim($b['note']) !== '' ? '<p class="small muted md">' . md_inline($b['note']) . '</p>' : '') . '</div>';
}

/** Note for the admin on pages that visitors see differently (or not at all). */
function visibility_banner(string $kind, array $item): string
{
    $v = m3d_visibility($item);
    if (!m3d_is_admin($GLOBALS['M3D'])) return '';
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    if ($v === 'public') {   // just published from this banner
        return isset($_GET['published']) ? '<p class="vis-banner vis-done">' . CHECK_ICON . '<span>Published: everyone can see this ' . $what . ' now.</span></p>' : '';
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
        . '<button class="btn btn-primary btn-sm">' . ($v === 'draft' ? 'Publish' : 'Make public') . '</button></form></div>';
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
    foreach ($links as $i => $l) {
        $label = trim((string) ($l['label'] ?? '')) ?: 'Download';
        if (($l['access'] ?? 'patrons') === 'public') {
            // through /get/ too, so free downloads are counted (it redirects straight away, no login)
            $public .= '<a class="btn btn-ghost btn-sm" href="' . e($base . $i . '/') . '" rel="nofollow noopener">' . e($label) . ' ↗</a>';
        } else {
            $patron .= '<a class="btn btn-primary dl" href="' . e($base . $i . '/') . '" rel="nofollow">'
                . '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M12 3v12m0 0-5-5m5 5 5-5M4 21h16" stroke-linecap="round" stroke-linejoin="round"/></svg>'
                . e($label) . '</a>';
        }
    }
    $what = $kind === 'outfits' ? 'accessory' : 'model';
    $h = '<div class="box"><h2>Download</h2>';
    if ($comingSoon) {
        $h .= '<p class="muted">This ' . $what . ' is still in development. Follow the progress on Patreon.</p>';
    } elseif ($patron) {
        $r = m3d_requirements($item);
        $reqs = '';
        if ($r['months'] || $r['total_cents']) {
            // one short line per requirement: "4+ Consecutive months as a patron", which site.js turns into "1/4" (and ticks) for the logged-in
            // patron; "or" between them when one is enough
            $or = $r['mode'] === 'or';
            $reqs = '<ul class="req-list" data-reqs data-req-mode="' . $r['mode'] . '" data-req-months="' . $r['months'] . '" data-req-total="' . $r['total_cents'] . '" aria-label="Extra requirements">'
                . ($r['months'] ? '<li data-req="months" data-need="' . $r['months'] . '"><b class="req-val">' . $r['months'] . '+</b> Consecutive months as a patron</li>' : '')
                . ($or ? '<li class="req-sep" aria-hidden="true">or</li>' : '')
                . ($r['total_cents'] ? '<li data-req="total" data-need="' . e(m3d_money($r['total_cents'])) . '"><b class="req-val">' . e(m3d_money($r['total_cents'])) . '+</b> Total support</li>' : '')
                . '</ul>';
        }
        // download limits: filled in by site.js from /auth/status.php for the logged-in patron (only when rules apply to them)
        $quota = array_filter(m3d_dl_settings()['rules'], fn($r) => !empty($r['on']))
            ? '<div class="dl-quota" data-dl-quota="' . e($kind . ':' . $item['slug']) . '" data-what="' . $what . '" hidden></div>' : '';
        // "Check your status" next to the title: shown by site.js to logged-in people (not for items that never count)
        if (empty($item['noLimits'])) $h = str_replace('<h2>Download</h2>', '<div class="box-head"><h2>Download</h2><a class="btn btn-ghost btn-sm box-status" href="/account/" data-dl-status hidden>Check your status</a></div>', $h);
        $h .= '<div class="dl-list">' . $patron . '</div>' . $quota . $reqs;
    } elseif (!$public) {
        $h .= '<p class="muted">No download for this ' . $what . ' yet.</p>';
    }
    if ($public && !$comingSoon) {
        $h .= '<div class="free">' . ($patron ? '' : '<p class="muted">Free download, no login needed.</p>')
            . '<div class="free-links">' . $public . '</div></div>';
    }
    // add-ons needed to use it (editor → Downloads → Add-ons required): public links, a site page or another website
    $addons = '';
    foreach (m3d_addons($kind, $item) as $a) {
        if (empty($a['url']) || empty($a['label'])) continue;
        $ext = !str_starts_with((string) $a['url'], '/');
        $addons .= '<a class="btn btn-ghost btn-sm" href="' . e($a['url']) . '"' . ($ext ? ' target="_blank" rel="noopener"' : '') . '>' . e($a['label']) . ($ext ? ' ↗' : ' →') . '</a>';
    }
    if ($addons !== '') $h .= '<div class="free dl-addons"><p class="small muted">Add-ons required</p><div class="free-links">' . $addons . '</div></div>';
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
        $h .= '<div class="log-controls"><button class="log-btn" data-log-toggle aria-expanded="false"><span data-when="closed">Show ' . $older . ' older ' . ($older === 1 ? 'version' : 'versions') . '</span><span data-when="open" hidden>Hide older versions</span></button>'
            . '<button class="log-btn ghost" data-log-order aria-label="Change changelog order"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3" stroke-linecap="round" stroke-linejoin="round"/></svg><span data-order-label>Oldest first</span></button></div>';
    }
    $h .= '<ol class="log" data-log>' . changelog_items($log, $noImages, $SHOW) . '</ol>';
    if ($older) $h .= '<button class="log-btn ghost log-bottom" data-log-toggle hidden>↑ Hide older versions</button>';
    return $h . '</section>';
}

/** The versions of a changelog as <li>s of an <ol class="log">: the first one is the latest; from $show on they start hidden (older). */
function changelog_items(array $log, bool $noImages = false, int $show = PHP_INT_MAX): string
{
    $h = '';
    foreach (array_values($log) as $i => $en) {
        $cls = trim(($i === 0 ? 'latest ' : '') . ($i >= $show ? 'old ' : '') . (m3d_stage($en) !== 'standard' ? 'log-' . m3d_stage($en) : ''));   // log-alpha / log-beta: coloured dot
        $h .= '<li class="' . $cls . '"' . ($i >= $show ? ' hidden' : '') . '><div class="log-head">' . stage_badge(m3d_stage($en)) . '<span class="ver">' . e($en['version'] ?? '') . '</span>'
            . (!empty($en['date']) ? '<time>' . e($en['date']) . '</time>' : '')
            . ($i === 0 ? '<span class="badge tone-mustard">Latest</span>' : '')
            . highlight_badge($en['highlight'] ?? null) . '</div>';
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

/** Accessory card: the whole card opens its page (catalog, model pages, related accessories). Type above the name, like a model's franchise. */
function outfit_card(array $o): string
{
    $url = m3d_item_url('outfits', $o['slug']);
    $prev = outfit_preview($o);
    $count = count(array_filter(array_map('as_image', $o['images'] ?? []), fn($g) => $g['src'] !== ''));
    $hidden = m3d_pics_hidden('outfits', $o);
    if ($hidden) $prev = blur_src($prev);   // never the real picture
    $img = $prev
        ? '<img src="' . e($hidden ? $prev : img_small($prev)) . '" alt="' . e($o['name']) . '" loading="lazy" decoding="async" />'
        : '<div class="ph" aria-hidden="true"><span class="ph-initials">' . e(mb_substr($o['name'], 0, 1)) . '</span></div>';
    $lv = m3d_latest_version($o);
    $ver = $lv['version'] !== '' ? '<span class="muted small">' . (($sb = stage_badge($lv['stage'], true)) !== '' ? $sb . ' ' : '') . e($lv['version']) . ($lv['date'] !== '' ? ' · ' . e($lv['date']) : '') . '</span>' : '';
    $models = [];
    foreach ($o['models'] ?? [] as $slug) if (($m = m3d_model($slug)) && m3d_listed($m)) $models[] = e($m['name']) . (($m['generation'] ?? '') !== '' ? ' <span class="muted">G' . e($m['generation']) . '</span>' : '');
    $fits = $models ? '<span class="fits small">' . implode(' · ', $models) . '</span>' : '';
    $type = m3d_accessory_type($o);
    // on the picture: the type's icon (top-left, like the Gen tag of model cards), then Password / Teaser / Patrons
    $pill = '<span class="cover-tags"><span class="type-tag" title="' . e($type) . '">' . accessory_icon($type) . '</span>' . visibility_pill('outfits', $o) . '</span>'
        . (!$hidden && $count > 1 ? '<span class="count-pill">' . $count . ' images</span>' : '');
    $likes = m3d_like_count((string) $o['slug'], 'outfits');
    $likesPill = $likes ? '<span class="likes-pill" title="' . $likes . ' ' . ($likes === 1 ? 'like' : 'likes') . ' from patrons">' . HEART_ICON . $likes . '</span>' : '';
    return '<a class="ocard" href="' . e($url) . '" data-ocard data-slug="' . e($o['slug']) . '" data-name="' . e(mb_strtolower($o['name'])) . '" data-order="' . e($o['order'] ?? 999) . '" data-likes="' . $likes . '" data-downloads="' . m3d_downloads('outfits', (string) $o['slug'])['total'] . '" data-type="' . e(mb_strtolower(m3d_accessory_type($o))) . '" data-models="' . e(implode(' ', array_filter($o['models'] ?? [], fn($sl) => ($mm = m3d_model($sl)) && m3d_listed($mm)))) . '">'
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
            $lv = m3d_latest_version($it);
            $ts = m3d_changelog_ts($lv['date']);
            if ($ts === null || $ts < $from) continue;
            $out[] = ['kind' => $kind, 'item' => $it, 'ts' => $ts, 'version' => $lv['version'], 'stage' => $lv['stage'], 'date' => $lv['date'], 'new' => count($it['changelog'] ?? []) === 1];
        }
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return array_slice($out, 0, $max);
}

/** Small row card for recent_items(): picture, New / Updated, name, version and date. */
function recent_card(array $r): string
{
    $it = $r['item'];
    $isOutfit = $r['kind'] === 'outfits';
    [$src, $hidden] = card_pic($r['kind'], $it);
    $img = $src !== ''
        ? '<img src="' . e($src) . '" alt="" loading="lazy" decoding="async" />'
        : '<span class="ph-initials">' . e(mb_substr((string) $it['name'], 0, 1)) . '</span>';
    $versions = $isOutfit ? [] : m3d_model_versions($it);
    return '<a class="recent" href="' . e(m3d_item_url($r['kind'], $it['slug'])) . '">'
        . '<span class="recent-img' . ($hidden ? ' is-blurred' : '') . '">' . $img . '</span>'
        . '<span class="recent-body"><span class="recent-tag ' . ($r['new'] ? 'is-new' : 'is-upd') . '">' . ($r['new'] ? 'New' : 'Updated') . '</span>'
        . '<b class="recent-name">' . e($it['name']) . ($versions ? ' v' . (int) $it['majorVersion'] : '') . '</b>'
        . '<span class="recent-meta">' . ($isOutfit ? e(m3d_accessory_type($it)) . ' · ' : '') . (($sb = stage_badge($r['stage'], true)) !== '' ? $sb . ' ' : '') . e($r['version']) . ($r['date'] !== '' ? ' · ' . e($r['date']) : '') . '</span>'
        . '</span></a>';
}

/** Square card of a model (outfit pages: "Optimized for"), same look as outfit_card(). */
/**
 * The small picture of a model (catalog image) or outfit (first image) for compact cards: [address, hidden]. Hidden
 * pictures become the tiny blurred copy (never the real address); '' when there is no picture.
 */
function card_pic(string $kind, array $it): array
{
    $src = $kind === 'outfits' ? outfit_preview($it) : (string) (($it['thumb'] ?? '') ?: ($it['cover'] ?? ''));
    $hidden = m3d_pics_hidden($kind, $it);
    return [$hidden ? blur_src($src) : ($src !== '' ? img_small($src) : ''), $hidden];
}

function model_fit_card(array $m): string
{
    [$src, $hidden] = card_pic('models', $m);
    $img = $src !== ''
        ? '<img src="' . e($src) . '" alt="' . e($m['name']) . '" loading="lazy" decoding="async" />'
        : '<div class="ph" aria-hidden="true"><span class="ph-initials">' . e(mb_substr((string) $m['name'], 0, 1)) . '</span></div>';
    $sub = implode(' · ', array_filter([e($m['franchise'] ?? ''), ($m['generation'] ?? '') !== '' ? 'Gen ' . e($m['generation']) : '', m3d_model_versions($m) ? 'v' . (int) $m['majorVersion'] : '']));
    return '<a class="ocard mcard-sq" href="/models/' . e($m['slug']) . '/" data-card data-slug="' . e($m['slug']) . '">'
        . '<span class="ocard-img' . ($hidden ? ' is-blurred' : '') . '">' . $img . visibility_pill('models', $m) . '</span>'
        . '<span class="ocard-body"><h3>' . e($m['name']) . '</h3>' . ($sub !== '' ? '<span class="muted small">' . $sub . '</span>' : '') . '</span></a>';
}

/**
 * Simple, safe text formatting used by the FAQ (and editable in the admin):
 *   blank line = new paragraph · "- " lines = list · "### " = small heading · ![alt](url) alone on a line = image
 *   [text](url) = link · **bold** · *italic* · `code` · a single line break stays a line break
 * Everything is escaped first, so no HTML can be injected.
 */
const HEART_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.7 4.6 13.5a4.9 4.9 0 0 1 0-7 5 5 0 0 1 7 0l.4.4.4-.4a5 5 0 0 1 7 0 4.9 4.9 0 0 1 0 7z"/></svg>';

/** Heart button for a model or outfit page: count rendered here, liked state and clicks handled by site.js (auth/like.php). */
function like_button(string $slug, string $kind = 'models'): string
{
    $n = m3d_like_count($slug, $kind);
    return '<button type="button" class="like-btn" data-like="' . e($slug) . '" data-like-kind="' . e($kind) . '" aria-pressed="false" title="Like this ' . ($kind === 'outfits' ? 'accessory' : 'model') . ' (patrons)">'
        . HEART_ICON . '<span data-like-count>' . $n . '</span><span class="sr-only"> likes</span></button>'
        . '<p class="like-msg small" data-like-msg hidden></p>';
}

/** Floating "Edit" button for the admin: hidden in the HTML, shown by site.js when /auth/status.php says admin. */
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
    $t = preg_replace_callback('/\[([^\]]+)\]\(((?:https?:\/\/|\/|#)[^\s)]+)\)/', function ($m) {
        $url = $m[2];
        $ext = preg_match('#^https?://#', html_entity_decode($url)) ? ' rel="noopener"' : '';
        return '<a href="' . $url . '"' . $ext . '>' . $m[1] . '</a>';
    }, $t);
    $codes = [];   // code spans are set aside so "*" inside them isn't read as bold/italic
    $t = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes) { $codes[] = '<code>' . $m[1] . '</code>'; return "\x01" . (count($codes) - 1) . "\x01"; }, $t);
    $t = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $t);
    $t = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $t);
    $t = preg_replace_callback('/\x01(\d+)\x01/', fn($m) => $codes[(int) $m[1]], $t);
    return nl2br($t, false);
}

/** Heading text → id ("Part 1: Daz Studio" → "part-1-daz-studio"). */
function md_id(string $text): string
{
    $t = strtolower(preg_replace('/[*`]|\[([^\]]*)\]\([^)]*\)/', '$1', $text));
    return trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-') ?: 'section';
}

const MD_IMG = '/^!\[([^\]]*)\]\(((?:https?:\/\/|\/)[^\s)]+)\)$/';

/** One list (bullets or numbered) from its lines. Indented lines continue the item above; indented "- " lines make a sub-list. */
function md_list(array $lines): string
{
    $ordered = (bool) preg_match('/^\d+\.\s/', $lines[0]);
    $items = [];
    foreach ($lines as $line) {
        if (preg_match($ordered ? '/^(\d+)\.\s+(.*)$/' : '/^-\s+(.*)$/', $line, $m)) {
            $items[] = ['n' => $ordered ? (int) $m[1] : 0, 'text' => $ordered ? $m[2] : $m[1], 'more' => []];
        } else {
            $items[count($items) - 1]['more'][] = trim($line);
        }
    }
    $start = $ordered && $items[0]['n'] > 1 ? ' start="' . $items[0]['n'] . '"' : '';
    $out = $ordered ? "<ol$start>" : '<ul>';
    foreach ($items as $it) {
        $out .= '<li>' . md_inline($it['text']);
        $text = []; $sub = [];
        $flush = function () use (&$text, &$sub, &$out) {
            if ($text) { $out .= '<span class="li-more">' . md_inline(implode("\n", $text)) . '</span>'; $text = []; }
            if ($sub) { $out .= '<ul>' . implode('', array_map(fn($l) => '<li>' . md_inline($l) . '</li>', $sub)) . '</ul>'; $sub = []; }
        };
        foreach ($it['more'] as $l) {
            if (preg_match('/^[-–]\s+(.*)$/u', $l, $m)) { if ($text) $flush(); $sub[] = $m[1]; }
            else { if ($sub) $flush(); $text[] = $l; }
        }
        $flush();
        $out .= '</li>';
    }
    return $out . ($ordered ? '</ol>' : '</ul>');
}

function md_table(array $lines): string
{
    $rows = [];
    foreach ($lines as $l) {
        if (preg_match('/^\|?\s*:?-{2,}/', $l)) continue;   // |---|---| separator
        $rows[] = array_map('trim', explode('|', trim(trim($l), '|')));
    }
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
        $size = fn($u) => ($f = media_path($u)) && is_file($f) ? @getimagesize($f) : false;
        $big = $size($m[2]);
        $dim = $small !== $m[2] ? $size($small) : $big;
        // shown as wide as the large file (up to the text column), whichever file the browser loads: screenshots stay
        // readable on sharp screens. The w values must be the files' real widths, or the browser scales the picture down.
        $srcset = '';
        if ($small !== $m[2] && $big && $dim) {
            $w = min($big[0], 780);
            $srcset = ' srcset="' . e($small) . ' ' . $dim[0] . 'w, ' . e($m[2]) . ' ' . $big[0] . 'w" sizes="(max-width: ' . ($w + 80) . 'px) 100vw, ' . $w . 'px"';
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
    if (preg_match('/^\[!(\w+)\][ ]*(.*)$/m', $inner, $m) && str_starts_with(ltrim($inner), '[!')) {
        $t = strtolower($m[1]);
        $t = CALLOUT_ALIASES[$t] ?? $t;
        if (isset(CALLOUTS[$t])) {
            $type = $t;
            $title = trim($m[2]) !== '' ? trim($m[2]) : CALLOUTS[$t][0];
            $inner = ltrim(substr(ltrim($inner), strlen($m[0])), "\n");
        }
    } elseif (preg_match('/^\*\*(warning|caution|important|tip|note)\b/i', ltrim($inner), $m)) {
        $type = strtolower($m[1]);   // older texts: "> **Warning:** …"
    }
    $head = $title !== null
        ? '<p class="callout-title"><svg viewBox="0 0 24 24" aria-hidden="true">' . CALLOUTS[$type][1] . '</svg>' . md_inline($title) . '</p>'
        : '';
    // the content can hold several paragraphs (an empty "> " line between them), lists, code…
    $body = trim($inner) !== '' ? md_html($inner, ['noImages' => !empty($opts['noImages'])]) : '';
    return '<div class="callout callout-' . $type . ($title === null ? ' untitled' : '') . '" role="note">' . $head . $body . '</div>';
}

/**
 * The simple text format used by FAQ answers and docs pages (see CLAUDE.md). Everything is escaped first.
 * $opts['docs'] = true: "## " → h2 and "### " → h3 with ids, collected in $toc. Otherwise "## " → h3, "### " → h4 (FAQ).
 */
function md_html(string $text, array $opts = [], ?array &$toc = null): string
{
    $docs = !empty($opts['docs']);
    $toc = [];
    $ids = [];
    $code = [];
    $text = str_replace(["\r\n", "\t"], ["\n", '    '], $text);
    // fenced code blocks first: they may contain blank lines
    $text = preg_replace_callback('/^```([\w+-]*)[ ]*\n(.*?)\n?```[ ]*$/ms', function ($m) use (&$code) {
        $code[] = [$m[1], $m[2]];
        return "\n\n\x02" . (count($code) - 1) . "\x02\n\n";   // (not \x00: trim() strips it)
    }, $text);
    $out = '';
    foreach (preg_split('/\n\s*\n/', trim($text)) as $block) {
        $block = rtrim($block);
        if (trim($block) === '') continue;
        if (preg_match('/^\x02(\d+)\x02$/', trim($block), $m)) {
            [$lang, $src] = $code[(int) $m[1]];
            $lines = explode("\n", $src);
            $numbered = $lang !== '' && count($lines) > 1;
            $body = $numbered ? implode('', array_map(fn($l) => '<span class="ln">' . (e($l) ?: ' ') . '</span>', $lines)) : e($src);
            $out .= '<div class="md-code' . ($numbered ? ' numbered' : '') . '"><button type="button" class="code-copy" data-copy-code>Copy</button><pre><code' . ($lang !== '' ? ' data-lang="' . e($lang) . '"' : '') . '>' . $body . '</code></pre></div>';
            continue;
        }
        $lines = explode("\n", $block);
        $imgs = [];
        foreach ($lines as $l) { if (preg_match(MD_IMG, trim($l), $im)) $imgs[] = $im; else { $imgs = []; break; } }
        if ($imgs) { if (empty($opts['noImages'])) $out .= md_images($imgs); continue; }   // noImages: blurred items
        if (count($lines) === 1 && preg_match('/^(#{2,3})\s+(.+)$/', $block, $m)) {
            $level = strlen($m[1]);
            if ($docs) {
                $id = $base = md_id($m[2]);
                for ($n = 2; isset($ids[$id]); $n++) $id = "$base-$n";
                $ids[$id] = true;
                $toc[] = ['level' => $level, 'id' => $id, 'text' => trim(html_entity_decode(strip_tags(md_inline($m[2])), ENT_QUOTES | ENT_HTML5, 'UTF-8'))];
                $out .= "<h$level id=\"" . e($id) . '">' . md_inline($m[2]) . '<a class="h-anchor" href="#' . e($id) . '" aria-label="Link to this section">#</a>' . "</h$level>";
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

function faq_id(string $q): string
{
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($q)), '-') ?: 'q';
}
