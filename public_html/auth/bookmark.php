<?php
// Bookmarks on model, accessory and scene pages and My Account's Bookmarked cards (bookmark_button(), card_like(), app/bell.php → Bookmarks): anyone logged in.
//   POST {kind: models|outfits|scenes, slug, on: bool} + X-CSRF-Token (the csrf in the button's data-bm)  → {on, bell (rows show in their bell), count (its number)}
// Previewing as a patron, the bookmark stays in the preview (like the bell's marks).
require dirname(__DIR__) . '/app/_init.php';
$in = m3d_json_post();
$slug = is_string($in['slug'] ?? null) ? $in['slug'] : '';
$kind = in_array($in['kind'] ?? '', M3D_ITEM_KINDS, true) ? (string) $in['kind'] : 'models';
$item = m3d_item($kind, $slug);
if (!$item || !m3d_can_view($kind, $item)) m3d_json_out(['error' => 'Unknown ' . m3d_kind_word($kind) . '.'], 404);   // drafts, locked password items
$p = m3d_patron($M3D);
if (!$p) m3d_json_out(['error' => 'Log in with Patreon to bookmark this ' . m3d_kind_word($kind) . '.'], 401);
if (!m3d_check_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) m3d_json_out(['error' => 'Session expired, reload the page.'], 403);
if (empty($p['preview'])) m3d_session_release();   // previewing, the bookmark goes in the session
$on = !empty($in['on']);
if (!m3d_bookmark_set($p, $kind, (string) $item['slug'], $on)) m3d_json_out(['error' => 'Couldn’t save, try again in a moment.'], 500);
$bell = m3d_ubell_for($p);   // the creator (not previewing) has no visitors' bell: the bookmark only lists the item on My Account
m3d_json_out(['on' => $on, 'bell' => $bell] + ($bell ? ['count' => m3d_ubell_count($p)] : []));
