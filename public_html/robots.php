<?php
// /robots.txt: search engines and AI crawlers may read the public pages; logins, downloads, accounts and the admin are off limits.
// /share/ stays readable so chat apps can show a link preview; share.php sends noindex, so it never shows in search results.
require __DIR__ . '/app/_init.php';
header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\nDisallow: /admin/\nDisallow: /auth/\nDisallow: /get/\nDisallow: /account/\nDisallow: /patreon-required/\nDisallow: /banned/\nDisallow: /age.php\nDisallow: /setup/\nDisallow: /search/\nDisallow: /welcome/\n\n";
echo "# every public page, for search engines\nSitemap: " . abs_url('/sitemap.xml') . "\n";
