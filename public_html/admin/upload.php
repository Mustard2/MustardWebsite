<?php
// POST multipart: file + model (slug). Saves the image in <site>/media/<model>/ as a large + small WebP
// (see app/images.php) and returns the large version's address. Animated GIFs are kept as they are.
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

const MAX_BYTES = 80 * 1024 * 1024;
const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

$f = $_FILES['file'] ?? null;
if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'The file is larger than the server allows (upload_max_filesize in hPanel → PHP Configuration).'
        : 'No file received.';
    admin_json(['ok' => false, 'error' => $msg], 400);
}
if ($f['size'] > MAX_BYTES) admin_json(['ok' => false, 'error' => 'Image too large (max 80 MB).'], 400);

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
if (!isset(TYPES[$mime]) || !@getimagesize($f['tmp_name'])) {
    admin_json(['ok' => false, 'error' => 'Only JPG, PNG, WebP and GIF images can be uploaded.'], 400);
}

$folder = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['model'] ?? ''))) ?: 'misc';
$dir = M3D_SITE . '/media/' . $folder;
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) admin_json(['ok' => false, 'error' => 'Could not create the media folder.'], 500);

$base = strtolower(pathinfo((string) $f['name'], PATHINFO_FILENAME));
$base = trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-') ?: 'image';
$base = substr($base, 0, 60);
$base .= '-' . bin2hex(random_bytes(3));

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
admin_json(['ok' => true, 'url' => "/media/$folder/$name", 'small' => "/media/$folder/" . preg_replace('/\.(\w+)$/', '-sm.$1', $name)]);
