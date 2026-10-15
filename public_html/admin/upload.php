<?php
// POST multipart: file + model (slug). Saves the image in <site>/media/<model>/ as WebP in its sizes
// (img_make_versions() in app/images.php) and returns the large version's address. Animated GIFs are kept as they are.
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

const MAX_BYTES = 80 * 1024 * 1024;
const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

$f = admin_uploaded_file();
if ($f['size'] > MAX_BYTES) admin_json(['ok' => false, 'error' => 'Image too large (max 80 MB).'], 400);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!isset(TYPES[$mime]) || !@getimagesize($f['tmp_name'])) {
    admin_json(['ok' => false, 'error' => 'Only JPG, PNG, WebP and GIF images can be uploaded.'], 400);
}

[$folder, $dir, $base] = admin_upload_target((string) $f['name'], 'image');

if ($mime === 'image/gif' && img_is_animated_gif($f['tmp_name'])) {
    $name = "$base.gif";
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) admin_json(['ok' => false, 'error' => 'Could not save the file.'], 500);
    @chmod("$dir/$name", 0644);
    admin_json(['ok' => true, 'url' => "/media/$folder/$name"]);
}

try {
    $name = img_make_versions($f['tmp_name'], $dir, $base);
} catch (Throwable $ex) {
    admin_json(['ok' => false, 'error' => 'Could not process this image: ' . $ex->getMessage()], 500);
}
admin_json(['ok' => true, 'url' => "/media/$folder/$name"]);
