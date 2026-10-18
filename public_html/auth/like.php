<?php
// Likes on model, outfit, gallery and blog pages. Paying patrons only (same rule as downloads), one like per patron and item.
//   POST {slug, kind?: outfits|stills, like: bool} + X-CSRF-Token (the csrf in the page's like state)  → {count, liked}
//   (stills: slug = the picture's id; posts: slug = the blog post's id)
require dirname(__DIR__) . '/app/_init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function like_out(array $data, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (m3d_maintenance_blocks()) like_out(['error' => 'The website is in maintenance, try again later.'], 503);

// the pages carry the visitor's own like state (like_button(), still_wall()): only the clicks come here
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') like_out(['error' => 'Send a POST.'], 405);
$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$slug = is_string($in['slug'] ?? null) ? $in['slug'] : '';   // not text (slug[] in a crafted request): no such item
$kind = in_array($in['kind'] ?? '', ['outfits', 'stills', 'posts'], true) ? (string) $in['kind'] : 'models';
$noun = ['models' => 'model', 'outfits' => 'accessory', 'stills' => 'picture', 'posts' => 'post'][$kind];
$p = m3d_patron($M3D);
$can = $p !== null && !empty($p['access']);

if ($kind === 'stills') {
    $still = preg_match('/^[0-9a-f]{8}$/', $slug) ? m3d_still($slug) : null;
    if (!$still || m3d_still_view($still) !== 'clear') like_out(['error' => 'Unknown picture.'], 404);   // hidden or blurred for this visitor
} elseif ($kind === 'posts') {
    $post = preg_match('/^[0-9a-f]{8}$/', $slug) && m3d_blog_on() ? m3d_post($slug) : null;
    if (!$post || m3d_post_view($post) !== 'full') like_out(['error' => 'Unknown post.'], 404);   // drafts, scheduled posts, Patrons Only posts locked for this visitor
} else {
    $item = m3d_item($kind, $slug);
    if (!$item || !m3d_can_view($kind, $item)) like_out(['error' => 'Unknown ' . $noun . '.'], 404);   // drafts, locked password items
}

if (!$p) like_out(['error' => 'Log in with Patreon to like this ' . $noun . '.', 'reason' => 'login'], 401);
if (!empty($p['preview'])) like_out(['error' => 'Likes are off while previewing as a patron.'], 403);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) like_out(['error' => 'Session expired, reload the page.'], 403);
if (!$can) like_out(['error' => 'Likes are for patrons on any paid tier.', 'reason' => 'patron'], 403);
[$count, $liked] = m3d_set_like($slug, (string) $p['id'], !empty($in['like']), $kind);
like_out(['count' => $count, 'liked' => $liked]);
