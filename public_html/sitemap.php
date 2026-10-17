<?php
// /sitemap.xml  (rewritten here by .htaccess): the public pages for search engines. Models and accessories that are public or
// blurred (never password or draft ones), the docs pages that aren't drafts, and the fixed pages. The same for every visitor,
// so it never depends on the login. lastmod = the date of the latest changelog version, when it can be read.
require __DIR__ . '/app/_init.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

$urls = [];
foreach (array_merge(['/', '/accessories/', '/whats-new/'], m3d_mustardui_on() ? ['/mustardui/'] : [], m3d_has_docs() ? ['/docs/'] : [], m3d_has_faq() ? ['/faq/'] : [], m3d_bugs_on() ? ['/bugs/'] : [], ['/contacts/', '/privacy/']) as $path) $urls[$path] = null;
foreach (['models' => '/models/', 'outfits' => '/accessories/'] as $kind => $base) {
    foreach (m3d_items($kind) as $it) {
        if (!in_array(m3d_visibility($it), ['public', 'blurred', 'blurred-patrons'], true) || m3d_release_hidden($it)) continue;   // "Coming Soon" pages too, like teasers
        $ts = m3d_changelog_ts(m3d_current_version($it)['date']);
        $urls[$base . $it['slug'] . '/'] = $ts !== null && $ts <= time() ? m3d_site_time($ts, 'Y-m-d') : null;
    }
}
// the gallery, once a visitor can see one of its pictures
if (m3d_has_gallery()) $urls['/gallery/'] = null;
$home = m3d_docs_home();
foreach (m3d_doc_pages(true) as $pg) if ($pg['slug'] !== $home) $urls['/docs/' . $pg['slug'] . '/'] = null;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $path => $mod) {
    echo '  <url><loc>' . htmlspecialchars(abs_url($path), ENT_XML1) . '</loc>' . ($mod ? '<lastmod>' . $mod . '</lastmod>' : '') . "</url>\n";
}
echo "</urlset>\n";
