<?php
// Finds the "private" folder (config, data, logs, backups) and loads the site core.
// Looked for, in this order:
//   1. next to the site folder        (e.g. domains/example.com/private)
//   2. one level higher               (when the site is in a subdomain folder)
//   3. inside the site folder         (public_html/private, protected by its own .htaccess)
if (!defined('M3D_PRIVATE')) {
    $site = dirname(__DIR__);
    foreach ([dirname($site), dirname($site, 2), $site] as $base) {
        if (is_file($base . '/private/config.php') || is_file($base . '/private/config.example.php')) {   // a fresh install has only the example
            define('M3D_PRIVATE', $base . '/private');
            break;
        }
    }
    if (!defined('M3D_PRIVATE')) {
        http_response_code(500);
        exit('Setup incomplete: the private folder (with config.php or config.example.php) was not found next to the site folder.');
    }
    define('M3D_SITE', $site);
}
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/social.php';
require_once __DIR__ . '/view.php';
require_once __DIR__ . '/images.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/bugs.php';
require_once __DIR__ . '/gallery.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/s4.php';
require_once __DIR__ . '/model3d.php';
require_once __DIR__ . '/welcome.php';
require_once __DIR__ . '/blog.php';
require_once __DIR__ . '/comments.php';
require_once __DIR__ . '/notify.php';
