<?php
// LOCAL TESTING ONLY (never on a real server). From this folder run:
//   php -S localhost:8000 -t public_html local-test.php
// It does what .htaccess does on the server, plus a local login (/__local/login, see README → Testing on your computer).
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }   // only PHP's built-in test server may use this file
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = __DIR__ . '/public_html';

// Local login, because the Patreon login only works on the real server:
//   /__local/login?as=owner    the admin panel (the site's owner; on a fresh copy you become it, then the setup's last step opens)
//   /__local/login?as=patron   a paying patron (6 months, 30 in total support)
//   /__local/login?as=free     logged in without a paid tier
//   /__local/login?as=mod      a paying patron with a Patreon-style ID (2000000002), to add as a moderator in Admin → Moderation → Moderators
//   /__local/login?as=logout   back to a visitor
if ($path === '/__local/login') {
    require $root . '/app/_init.php';
    m3d_start_session();
    $as = (string) ($_GET['as'] ?? 'owner');
    $owner = (string) (m3d_owner_ids($M3D)[0] ?? '');
    if ($as === 'owner' && $owner === '') {   // a fresh copy: become its owner, then the setup's last step (the basics)
        m3d_save_settings(['owners' => ['1']]);
        $owner = '1';
    }
    header('Content-Type: text/plain; charset=utf-8');
    if ($as === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: /');
        return true;
    }
    $_SESSION['patron'] = match ($as) {
        'owner'  => ['id' => $owner, 'access' => true, 'reason' => 'owner', 'name' => 'Local owner', 'checked_at' => time()],
        'free'   => ['id' => 'local-free', 'access' => false, 'reason' => 'no-tier', 'name' => 'Local member', 'checked_at' => time()],
        'mod'    => ['id' => '2000000002', 'access' => true, 'reason' => 'patron', 'name' => 'Local moderator', 'checked_at' => time(),
                     'since' => gmdate('c', strtotime('-6 months')), 'total_cents' => 3000, 'currency' => m3d_currency(),
                     'member' => true, 'status' => 'active_patron', 'tier_list' => [['Supporter', 500]]],
        default  => ['id' => 'local-patron', 'access' => true, 'reason' => 'patron', 'name' => 'Local patron', 'checked_at' => time(),
                     'since' => gmdate('c', strtotime('-6 months')), 'total_cents' => 3000, 'currency' => m3d_currency(),
                     'member' => true, 'status' => 'active_patron', 'tier_list' => [['Supporter', 500]]],
    };
    $_SESSION['started'] = time();
    setcookie('m3d_age_ok', '1', ['expires' => time() + 30 * 86400, 'path' => '/', 'samesite' => 'Lax']);   // past the 18+ check
    header('Location: ' . ($as === 'owner' ? (empty(m3d_settings()['setup_done']) ? '/setup/' : '/admin/') : '/'));
    return true;
}
$route = function (string $file, array $get = []) use ($root) {
    $_GET = $get + $_GET;
    chdir(dirname("$root/$file"));
    require "$root/$file";
    return true;
};
if (preg_match('#^/models/([a-z0-9-]+)/?$#', $path, $m)) return $route('model.php', ['slug' => $m[1]]);
if (preg_match('#^/patreon-required/?$#', $path)) return $route('patreon-required.php');
if (preg_match('#^/banned/?$#', $path)) return $route('banned.php');
if (preg_match('#^/privacy/?$#', $path)) return $route('privacy.php');
if (preg_match('#^/contacts/?$#', $path)) return $route('contacts.php');
if (preg_match('#^/account/?$#', $path)) return $route('account.php');
if (preg_match('#^/account/settings/?$#', $path)) return $route('account-settings.php');
if (preg_match('#^/mustardui/?$#', $path)) return $route('mustardui.php');
if (preg_match('#^/tools/?$#', $path)) return $route('tools.php');
if (preg_match('#^/tools/([a-z0-9-]+)/?$#', $path, $m)) return $route('tools.php', ['tool' => $m[1]]);
if (preg_match('#^/tools/([a-z0-9-]+)/([a-z0-9-]+)/?$#', $path, $m)) return $route('tools.php', ['tool' => $m[1], 'tut' => $m[2]]);
if (preg_match('#^/pages/([a-z0-9-]+)/?$#', $path, $m)) return $route('tools.php', ['page' => $m[1]]);
if (preg_match('#^/pages/([a-z0-9-]+)/([a-z0-9-]+)/?$#', $path, $m)) return $route('tools.php', ['page' => $m[1], 'tut' => $m[2]]);
if (preg_match('#^/docs/?$#', $path)) return $route('docs.php');
if (preg_match('#^/docs/([a-z0-9-]+)/?$#', $path, $m)) return $route('docs.php', ['slug' => $m[1]]);
if (preg_match('#^/faq/?$#', $path)) return $route('faq.php');
if (preg_match('#^/search/?$#', $path)) return $route('search.php');
if (preg_match('#^/whats-new/?$#', $path)) return $route('whats-new.php');
if (preg_match('#^/welcome/?$#', $path)) return $route('welcome.php');
if (preg_match('#^/unlocks/?$#', $path)) return $route('unlocks.php');
if (preg_match('#^/bugs/?$#', $path)) return $route('bugs.php');
if (preg_match('#^/bugs/report/?$#', $path)) return $route('bugs.php', ['report' => '1']);
if (preg_match('#^/bugs/([0-9]+)/?$#', $path, $m)) return $route('bugs.php', ['n' => $m[1]]);
if ($path === '/sitemap.xml') return $route('sitemap.php');
if ($path === '/robots.txt') return $route('robots.php');
if (preg_match('#^/setup/?$#', $path)) return $route('setup.php');
if (preg_match('#^/accessories/?$#', $path)) return $route('outfits.php');
if (preg_match('#^/accessories/([a-z0-9-]+)/?$#', $path, $m)) return $route('outfit.php', ['slug' => $m[1]]);
if (preg_match('#^/scenes/?$#', $path)) return $route('scenes.php');
if (preg_match('#^/scenes/([a-z0-9-]+)/?$#', $path, $m)) return $route('scene.php', ['slug' => $m[1]]);
if (preg_match('#^/gallery/?$#', $path)) return $route('gallery.php');
if (preg_match('#^/blog/?$#', $path)) return $route('blog.php');
if (preg_match('#^/blog/([a-z0-9-]+)/?$#', $path, $m)) return $route('blog.php', ['slug' => $m[1]]);
if (preg_match('#^/og/([A-Za-z0-9_-]+)\.jpg$#D', $path, $m)) return $route('og-image.php', ['c' => $m[1]]);
if (preg_match('#^/share/([A-Za-z0-9]{12})/?$#', $path, $m)) return $route('share.php', ['code' => $m[1]]);
if (preg_match('#^/get/still/([a-f0-9]{8})/?$#', $path, $m)) return $route('auth/get-still.php', ['id' => $m[1]]);
if (preg_match('#^/get/still/r/([a-f0-9]{8})/?$#', $path, $m)) return $route('auth/get-still.php', ['render' => $m[1]]);
if (preg_match('#^/get/still/([mos])/([a-z0-9-]+)/([a-f0-9]{8})/?$#', $path, $m)) return $route('auth/get-still.php', ['kind' => $m[1], 'slug' => $m[2], 'code' => $m[3]]);
if (preg_match('#^/get/outfit/([a-z0-9-]+)/([0-9]+)/?$#', $path, $m)) return $route('auth/get.php', ['type' => 'outfit', 'model' => $m[1], 'n' => $m[2]]);
if (preg_match('#^/get/scene/([a-z0-9-]+)/([0-9]+)/?$#', $path, $m)) return $route('auth/get.php', ['type' => 'scene', 'model' => $m[1], 'n' => $m[2]]);
if (preg_match('#^/get/([a-z0-9-]+)/([0-9]+)/?$#', $path, $m)) return $route('auth/get.php', ['model' => $m[1], 'n' => $m[2]]);
if (preg_match('#(^|/)\.(?!well-known/)#', $path) || preg_match('#\.(upd|upd-old|tmp)-[0-9a-f]+$#', $path) || preg_match('#(\.(bak|backup|old|orig|save|swp|swo|sql|sqlite|db|log|ini|zip|tar|gz|tgz|7z|rar)|~)$#i', $path)) { http_response_code(403); echo 'Forbidden'; return true; }   // like the scanner rules in .htaccess
if (preg_match('#^/(app|private)/#', $path) || preg_match('#^/admin/_#', $path) || basename($path) === '.htaccess') { http_response_code(403); echo 'Forbidden'; return true; }   // like app/.htaccess, admin/.htaccess (_ files) and the server's .htaccess rule
if (str_starts_with($path, '/media/') && !preg_match('#\.(webp|png|jpe?g|gif|glb|mp4|webm)$#iD', $path)) { http_response_code(403); echo 'Forbidden'; return true; }   // like media/.htaccess: only the file types stored there (never runs a .php there either)
if ($path === '/admin' ) { header('Location: /admin/'); return true; }
if (is_dir($root . $path) && is_file($root . rtrim($path, '/') . '/index.php')) return $route(ltrim(rtrim($path, '/') . '/index.php', '/'));
if (is_file($root . $path)) return false;            // existing file: serve as-is (php files run normally)
http_response_code(404);
return $route('404.php');
