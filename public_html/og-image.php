<?php
// /og/<code>.jpg (rewritten here by .htaccess): the link-preview picture of a page (twitter:image), see social_image_url() in app/images.php.
// Only addresses the site printed (signed); the pictures are those of public pages, so no login, age gate or maintenance check (like /media/).
require __DIR__ . '/app/_init.php';

$url = social_image_src(m3d_get_text('c'));
$file = $url !== null ? social_image_file($url) : null;
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=31536000, immutable');   // the address holds the picture's own address, which never changes (a crop makes a new file)
readfile($file);
