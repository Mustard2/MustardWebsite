<?php
// Likes on model, outfit and gallery pages. Paying patrons only (same rule as downloads), one like per patron and item.
//   GET  ?slug=<slug>[&kind=outfits|stills]   → {count, liked, can, reason, csrf?}   (stills: slug = the picture's id)
//   GET  ?kind=stills&all=1                    → {counts: {id: n}, liked: [id…], can, reason, csrf?}   (the whole gallery at once)
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
if (!is_array($in)) $in = [];
$slug = is_string($in['slug'] ?? null) ? $in['slug'] : '';   // not text (slug[]= in a crafted request): no such item
$kind = in_array($in['kind'] ?? '', ['outfits', 'stills'], true) ? (string) $in['kind'] : 'models';
$noun = ['models' => 'model', 'outfits' => 'accessory', 'stills' => 'picture'][$kind];
$p = m3d_patron($M3D);
$can = $p !== null && !empty($p['access']);
$reason = $p === null ? 'login' : ($can ? null : 'patron');

if ($kind === 'stills' && !$post && !empty($in['all'])) {   // the gallery page: every clear picture's count and this visitor's likes
    $code = $p ? m3d_like_code((string) $p['id']) : '';
    $likes = m3d_likes('stills');
    $counts = [];
    $mine = [];
    foreach (m3d_gallery_visible() as $s) {
        if ($s['view'] !== 'clear') continue;   // blurred pictures can't be liked
        $counts[$s['id']] = count($likes[$s['id']] ?? []);
        if ($code !== '' && in_array($code, $likes[$s['id']] ?? [], true)) $mine[] = $s['id'];
    }
    $csrf = $p ? ['csrf' => m3d_csrf()] : [];
    session_write_close();
    like_out(['counts' => (object) $counts, 'liked' => $mine, 'can' => $can, 'reason' => $reason] + $csrf);
}

if ($kind === 'stills') {
    $still = preg_match('/^[0-9a-f]{8}$/', $slug) ? m3d_still($slug) : null;
    if (!$still || m3d_still_view($still) !== 'clear') like_out(['error' => 'Unknown picture.'], 404);   // hidden or blurred for this visitor
} else {
    $item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
    if (!$item || !m3d_can_view($kind, $item)) like_out(['error' => 'Unknown ' . $noun . '.'], 404);   // drafts, locked password items
}
$liked = $p !== null && in_array(m3d_like_code((string) $p['id']), m3d_likes($kind)[$slug] ?? [], true);

if (!$post) {
    $csrf = $p ? ['csrf' => m3d_csrf()] : [];
    session_write_close();   // read only from here: don't keep the login locked (the header's status request runs alongside)
    like_out(['count' => m3d_like_count($slug, $kind), 'liked' => $liked, 'can' => $can,
        'reason' => $reason] + $csrf);
}

if (!$p) like_out(['error' => 'Log in with Patreon to like this ' . $noun . '.', 'reason' => 'login'], 401);
if (!empty($p['preview'])) like_out(['error' => 'Likes are off while previewing as a patron.'], 403);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) like_out(['error' => 'Session expired, reload the page.'], 403);
if (!$can) like_out(['error' => 'Likes are for patrons on any paid tier.', 'reason' => 'patron'], 403);
[$count, $liked] = m3d_set_like($slug, (string) $p['id'], !empty($in['like']), $kind);
like_out(['count' => $count, 'liked' => $liked]);
