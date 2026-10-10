<?php
// Likes on model and outfit pages. Paying patrons only (same rule as downloads), one like per patron and item.
//   GET  ?slug=<slug>[&kind=outfits]          → {count, liked, can, reason, csrf?}
//   POST {slug, kind?, like: bool} + X-CSRF-Token (the csrf from the GET)  → {count, liked}
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

$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $post ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_GET;
$slug = (string) ($in['slug'] ?? '');
$kind = ($in['kind'] ?? '') === 'outfits' ? 'outfits' : 'models';
$item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
if (!$item || !m3d_can_view($kind, $item)) like_out(['error' => 'Unknown ' . ($kind === 'outfits' ? 'accessory' : 'model') . '.'], 404);   // drafts, locked password items

$p = m3d_patron($M3D);
$can = $p !== null && !empty($p['access']);
$liked = $p !== null && in_array(m3d_like_code((string) $p['id']), m3d_likes($kind)[$slug] ?? [], true);

if (!$post) {
    like_out(['count' => m3d_like_count($slug, $kind), 'liked' => $liked, 'can' => $can,
        'reason' => $p === null ? 'login' : ($can ? null : 'patron')] + ($p ? ['csrf' => m3d_csrf()] : []));
}

if (!$p) like_out(['error' => 'Log in with Patreon to like ' . $kind . '.', 'reason' => 'login'], 401);
if (!empty($p['preview'])) like_out(['error' => 'Likes are off while previewing as a patron.'], 403);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) like_out(['error' => 'Session expired, reload the page.'], 403);
if (!$can) like_out(['error' => 'Likes are for patrons on any paid tier.', 'reason' => 'patron'], 403);
[$count, $liked] = m3d_set_like($slug, (string) $p['id'], !empty($in['like']), $kind);
like_out(['count' => $count, 'liked' => $liked]);
