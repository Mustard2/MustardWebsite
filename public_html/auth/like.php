<?php
// Likes on model, outfit, gallery and blog pages. Paying patrons only (same rule as downloads), one like per patron and item.
//   POST {slug, kind?: outfits|stills, like: bool} + X-CSRF-Token (the csrf in the page's like state)  → {count, liked}
//   (stills: slug = the picture's id; posts: slug = the blog post's id)
require dirname(__DIR__) . '/app/_init.php';

// the pages carry the visitor's own like state (like_button(), still_wall()): only the clicks come here
$in = m3d_json_post();
$slug = is_string($in['slug'] ?? null) ? $in['slug'] : '';   // not text (slug[] in a crafted request): no such item
$kind = in_array($in['kind'] ?? '', ['outfits', 'scenes', 'stills', 'posts'], true) ? (string) $in['kind'] : 'models';
$noun = ['models' => 'model', 'outfits' => 'accessory', 'scenes' => 'scene', 'stills' => 'picture', 'posts' => 'post'][$kind];
$p = m3d_patron($M3D);
$can = $p !== null && !empty($p['access']);

if ($kind === 'stills') {
    $still = preg_match('/^[0-9a-f]{8}$/D', $slug) ? m3d_still($slug) : null;
    if (!$still || m3d_still_view($still) !== 'clear') m3d_json_out(['error' => 'Unknown picture.'], 404);   // hidden or blurred for this visitor
} elseif ($kind === 'posts') {
    $post = preg_match('/^[0-9a-f]{8}$/D', $slug) && m3d_blog_on() ? m3d_post($slug) : null;
    if (!$post || m3d_post_view($post) !== 'full') m3d_json_out(['error' => 'Unknown post.'], 404);   // drafts, scheduled posts, Patrons Only posts locked for this visitor
} else {
    $item = m3d_item($kind, $slug);
    if (!$item || !m3d_can_view($kind, $item)) m3d_json_out(['error' => 'Unknown ' . $noun . '.'], 404);   // drafts, locked password items
}

if (!$p) m3d_json_out(['error' => 'Log in with Patreon to like this ' . $noun . '.', 'reason' => 'login'], 401);
if (!empty($p['preview'])) m3d_json_out(['error' => 'Likes are off while previewing as a patron.'], 403);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) m3d_json_out(['error' => 'Session expired, reload the page.'], 403);
if (!$can) m3d_json_out(['error' => 'Likes are for patrons on any paid tier.', 'reason' => 'patron'], 403);
[$count, $liked] = m3d_set_like($slug, (string) $p['id'], !empty($in['like']), $kind);
m3d_json_out(['count' => $count, 'liked' => $liked]);
