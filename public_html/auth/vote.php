<?php
// Polls under blog posts (app/blog.php → "Polls"). POST form of blog_poll(): {csrf, post (id), opt[] (option ids)}. A paying patron (`access`,
// like likes), not previewing, on a live post they can read in full, while the poll is open; a second vote only when the poll allows changes.
// Back to the post's #poll with ?poll=voted, or ?pollerr=<code> (POLL_ERRORS).
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') m3d_redirect('/blog/');
maintenance_gate();
$id = is_string($_POST['post'] ?? null) ? $_POST['post'] : '';
$post = preg_match('/^[0-9a-f]{8}$/D', $id) && (m3d_blog_on() || m3d_is_admin($M3D)) ? m3d_post($id) : null;
if (!$post || m3d_post_view($post) !== 'full' || !($poll = m3d_poll($post))) not_found();
$go = fn(string $q): never => m3d_redirect(m3d_post_url($post) . '?' . $q . '#poll');
if (!m3d_check_csrf($_POST['csrf'] ?? null)) $go('pollerr=session');

$p = m3d_patron($M3D);
if (!$p) $go('pollerr=login');
if (!empty($p['preview'])) $go('pollerr=preview');
if (empty($p['access'])) $go('pollerr=patron');
if (m3d_post_state($post) !== 'live' || m3d_poll_closed($poll)) $go('pollerr=closed');

$ids = array_column($poll['opts'], 'id');
$picked = array_values(array_unique(array_filter(array_map(fn($v) => is_string($v) ? $v : '', (array) ($_POST['opt'] ?? [])), fn($v) => in_array($v, $ids, true))));
if (!$picked) $go('pollerr=pick');
if (count($picked) > max(1, (int) ($poll['max'] ?? 1))) $go('pollerr=many');
$r = m3d_poll_vote($post, (string) $p['id'], $picked);
$go($r === 'ok' ? 'poll=voted' : 'pollerr=' . $r);
