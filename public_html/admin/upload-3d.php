<?php
// POST multipart: file (.glb) + model (folder). Checks it with m3d_glb_info() (app/model3d.php), saves it in <site>/media/<folder>/ under
// a new name (media is cached: never the same address twice) and returns its address with the facts the editor shows.
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

$f = admin_uploaded_file();
if ($f['size'] > M3D_GLB_MAX) admin_json(['ok' => false, 'error' => 'File too large (max ' . m3d_mb(M3D_GLB_MAX) . '). Export a lighter copy for the preview.'], 400);
if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'glb') admin_json(['ok' => false, 'error' => 'Only .glb files can be used. In Blender: File → Export → glTF 2.0, Format “glTF Binary (.glb)”.'], 400);
try {
    $info = m3d_glb_info($f['tmp_name']);
} catch (RuntimeException $ex) {
    admin_json(['ok' => false, 'error' => $ex->getMessage()], 400);
} catch (Throwable) {   // a description of unexpected shape (lists where numbers belong…)
    admin_json(['ok' => false, 'error' => M3D_GLB_DAMAGED], 400);
}

[$folder, $dir, $base] = admin_upload_target((string) $f['name'], 'model');
$name = $base . '.glb';
if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) admin_json(['ok' => false, 'error' => 'Could not save the file.'], 500);
@chmod("$dir/$name", 0644);
admin_json(['ok' => true, 'url' => "/media/$folder/$name", 'info' => $info]);
