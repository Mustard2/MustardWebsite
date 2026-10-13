<?php
// POST multipart: file (.glb) + model (folder). Checks it with m3d_glb_info() (app/model3d.php), saves it in <site>/media/<folder>/ under
// a new name (media is cached: never the same address twice) and returns its address with the facts the editor shows.
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

$f = $_FILES['file'] ?? null;
if (!$f || !is_uploaded_file($f['tmp_name'] ?? '')) {
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    $msg = in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
        ? 'The file is larger than the server allows (now ' . ini_get('upload_max_filesize') . '; upload_max_filesize and post_max_size in hPanel → PHP Configuration).'
        : 'No file received.';
    admin_json(['ok' => false, 'error' => $msg], 400);
}
if ($f['size'] > M3D_GLB_MAX) admin_json(['ok' => false, 'error' => 'File too large (max ' . m3d_mb(M3D_GLB_MAX) . '). Export a lighter copy for the preview.'], 400);
if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'glb') admin_json(['ok' => false, 'error' => 'Only .glb files can be used. In Blender: File → Export → glTF 2.0, Format “glTF Binary (.glb)”.'], 400);
try {
    $info = m3d_glb_info($f['tmp_name']);
} catch (RuntimeException $ex) {
    admin_json(['ok' => false, 'error' => $ex->getMessage()], 400);
} catch (Throwable) {   // a description of unexpected shape (lists where numbers belong…)
    admin_json(['ok' => false, 'error' => M3D_GLB_DAMAGED], 400);
}

$folder = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_POST['model'] ?? ''))) ?: 'misc';
$dir = M3D_SITE . '/media/' . $folder;
if (!is_dir($dir) && !@mkdir($dir, 0755, true)) admin_json(['ok' => false, 'error' => 'Could not create the media folder.'], 500);

$base = strtolower(pathinfo((string) $f['name'], PATHINFO_FILENAME));
$base = substr(trim(preg_replace('/[^a-z0-9]+/', '-', $base) ?? '', '-') ?: 'model', 0, 60);
$name = $base . '-' . bin2hex(random_bytes(3)) . '.glb';
if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) admin_json(['ok' => false, 'error' => 'Could not save the file.'], 500);
@chmod("$dir/$name", 0644);
admin_json(['ok' => true, 'url' => "/media/$folder/$name", 'info' => $info]);
