<?php
// POST multipart: a video for a text (app/video.php), checked again here after the editor's own check (admin.js videoInfo()):
// file (.mp4 / .webm, at most M3D_VIDEO_MAX) + model (the media folder) + optional cover (a picture: the frame the editor drew) → the
// video's address. do=cover changes a saved video's cover: video (its address) + cover (a picture file) or from (a library picture's
// address), or remove=1. The cover is saved next to the video with its name (name.mp4 → name.webp and its smaller versions).
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

/** Saves $src (a picture file) as the cover of the video at $url, replacing the old one: the cover's address. */
function video_cover_save(string $url, string $src): string
{
    $p = (string) m3d_video_path($url);
    foreach (m3d_video_cover_files($url) as $c) if ($f = media_path($c)) @unlink($f);
    $name = img_make_versions($src, dirname($p), pathinfo($p, PATHINFO_FILENAME));
    return dirname($url) . '/' . $name;
}

/** The uploaded cover picture's temporary file, '' when none came. */
function video_cover_upload(): string
{
    $c = $_FILES['cover'] ?? null;
    if (!$c || !is_uploaded_file($c['tmp_name'] ?? '')) return '';
    if ($c['size'] > 20 * 1024 * 1024 || !@getimagesize($c['tmp_name'])) admin_json(['ok' => false, 'error' => 'The cover isn’t a picture.'], 400);
    return $c['tmp_name'];
}

if (($_POST['do'] ?? '') === 'cover') {
    $url = is_string($_POST['video'] ?? null) ? $_POST['video'] : '';
    if (!m3d_video_path($url)) admin_json(['ok' => false, 'error' => 'Video not found.'], 404);
    if (!empty($_POST['remove'])) {
        foreach (m3d_video_cover_files($url) as $c) if ($f = media_path($c)) @unlink($f);
        admin_json(['ok' => true, 'cover' => '']);
    }
    $src = video_cover_upload();
    if ($src === '') {
        $from = media_path(img_large(is_string($_POST['from'] ?? null) ? $_POST['from'] : ''));
        if (!$from || !is_file($from) || !preg_match('/\.(webp|jpe?g|png|gif)$/i', $from) || !@getimagesize($from)) admin_json(['ok' => false, 'error' => 'Pick a picture stored on this site.'], 400);
        $src = $from;
    }
    try { $cover = video_cover_save($url, $src); }
    catch (Throwable $ex) { admin_json(['ok' => false, 'error' => 'Could not save the cover: ' . $ex->getMessage()], 500); }
    admin_json(['ok' => true, 'cover' => $cover]);
}

$f = admin_uploaded_file();
if ($f['size'] > M3D_VIDEO_MAX) admin_json(['ok' => false, 'error' => 'The video is ' . m3d_mb((int) $f['size']) . ': the limit is ' . m3d_mb(M3D_VIDEO_MAX) . '. Export a shorter or lighter copy.'], 400);   // (the editor checked it first: only a changed or crafted request gets here)
try { $info = m3d_video_read($f['tmp_name']); }
catch (RuntimeException $ex) { admin_json(['ok' => false, 'error' => $ex->getMessage()], 400); }

[$folder, $dir, $base] = admin_upload_target((string) $f['name'], 'video');
$name = "$base.{$info['type']}";
if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) admin_json(['ok' => false, 'error' => 'Could not save the file.'], 500);
@chmod("$dir/$name", 0644);
$url = "/media/$folder/$name";
$cover = '';
$warn = '';
if (($src = video_cover_upload()) !== '') {
    try { $cover = video_cover_save($url, $src); }
    catch (Throwable $ex) { $warn = 'The video was saved, but its cover couldn’t be: ' . $ex->getMessage(); }
}
admin_json(['ok' => true, 'url' => $url, 'cover' => $cover, 'warn' => $warn, 'info' => $info]);
