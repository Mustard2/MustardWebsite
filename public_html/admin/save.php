<?php
// POST JSON { kind: "models" | "outfits" | "scenes", action: save | delete | reorder | batch, … }, { kind: "faq", faq }, { kind: "docs", docs },
// { kind: "gallery", action: add | save | delete | order | visibility | link | chars, … } (answers with the whole gallery: admin_gallery_payload()),
// { kind: "blog", action: save | delete, id ('' = a new post), rev, post } (answers with every post: admin_blog_posts()),
// { kind: "tool-tuts", id (the tool), rev, docs: {sections: [{title, text, pages: [tutorial]}]} } (a tool's tutorials, admin/tool-tutorials.php)
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) admin_json(['ok' => false, 'error' => 'Invalid request.'], 400);
// every answer comes from admin_json(); a bad input is a 422 with its message, anything else a 500
try {
    if (in_array($in['kind'] ?? '', ['docs', 'faq'], true)) {
        $k = $in['kind'];
        // an editor left open must not put old texts back (another tab, an Images job that changed picture addresses)
        if (isset($in['rev']) && !hash_equals(admin_text_rev($k), (string) $in['rev'])) {
            admin_json(['ok' => false, 'error' => 'The ' . ($k === 'docs' ? 'docs were' : 'FAQ was') . ' changed in another tab or by an Images job: reload this page (your changes here are not saved).'], 409);
        }
        $ok = $k === 'docs' ? m3d_save_docs(clean_docs((array) ($in['docs'] ?? []))) : m3d_save_faq(clean_faq((array) ($in['faq'] ?? [])));
        if (!$ok) throw new RuntimeException(m3d_write_error('data'));
        admin_json(['ok' => true, 'rev' => admin_text_rev($k)]);
    }
    if (($in['kind'] ?? '') === 'tool-tuts') {
        $list = m3d_tools(true)['tools'];
        $at = array_search((string) ($in['id'] ?? ''), array_column($list, 'id'), true);
        if ($at === false) throw new InvalidArgumentException('This tool no longer exists (deleted in another tab?). Reload the page.');
        if (isset($in['rev']) && !hash_equals(admin_tuts_rev($list[$at]), (string) $in['rev'])) {
            admin_json(['ok' => false, 'error' => 'These tutorials were changed in another tab or by an Images job: reload this page (your changes here are not saved).'], 409);
        }
        $list[$at]['groups'] = clean_tool_tuts((array) ($in['docs'] ?? []));
        if (!m3d_save_tools($list)) throw new RuntimeException(m3d_write_error('data'));
        admin_json(['ok' => true, 'rev' => admin_tuts_rev(m3d_tool_by_id((string) $in['id']) ?? [])]);
    }
    if (($in['kind'] ?? '') === 'blog') {
        $posts = m3d_blog(true)['posts'];
        $id = (string) ($in['id'] ?? '');
        $at = $id !== '' ? array_search($id, array_column($posts, 'id'), true) : false;
        if ($id !== '' && $at === false) throw new InvalidArgumentException('This post no longer exists (deleted in another tab?). Reload the page.');
        $old = $at === false ? null : $posts[$at];
        // an editor left open must not put an old version back (another tab, an Images job that changed picture addresses)
        if ($old && isset($in['rev']) && !hash_equals(admin_post_rev($old), (string) $in['rev'])) {
            admin_json(['ok' => false, 'error' => 'This post was changed in another tab or by an Images job: reload this page (your changes here are not saved).'], 409);
        }
        if (($in['action'] ?? 'save') === 'delete') {
            if (!$old) throw new InvalidArgumentException('Not found.');
            array_splice($posts, $at, 1);
            if (!m3d_save_blog($posts)) throw new RuntimeException(m3d_write_error('data'));
            m3d_blog_forget_likes([$old['id']]);
            m3d_polls_forget([$old['id']]);
            m3d_comments_forget('posts', [$old['id']]);
            admin_json(['ok' => true, 'posts' => admin_blog_posts()]);
        }
        $post = clean_post((array) ($in['post'] ?? []), $old, $posts);
        if ($old) $posts[$at] = $post; else $posts[] = $post;
        if (!m3d_save_blog($posts)) throw new RuntimeException(m3d_write_error('data'));
        m3d_blog_discord_tick();   // published now (or a date already past): its Discord post after this answer
        admin_json(['ok' => true, 'id' => $post['id'], 'posts' => admin_blog_posts()]);
    }
    if (($in['kind'] ?? '') === 'gallery') {
        $g = m3d_gallery(true);
        $oldStills = $g['stills'];
        $action = (string) ($in['action'] ?? '');
        $ids = array_values(array_unique(array_map('strval', (array) ($in['ids'] ?? []))));
        $res = [];
        if ($action === 'add') {
            // pictures from the Media library or just uploaded: newest first, tagged with the model whose folder they are in,
            // or linked to the scene whose folder they are in (its "Download Scene") and tagged with its models
            $have = array_column($g['stills'], 'src');
            $taken = array_column($g['stills'], 'id');
            $slugs = array_flip(array_column(m3d_models(), 'slug'));
            $today = m3d_today();
            $sceneUse = null;   // picture address => the first scene using it (read once, only when needed)
            $usedBy = function (string $src) use (&$sceneUse): ?array {
                if ($sceneUse === null) {
                    $sceneUse = [];
                    foreach (m3d_items('scenes') as $sc) array_walk_recursive($sc, function ($v) use (&$sceneUse, $sc) { foreach (media_in($v) as $u) $sceneUse[img_large($u)] ??= $sc; });
                }
                return $sceneUse[$src] ?? null;
            };
            $new = [];
            foreach (array_slice((array) ($in['srcs'] ?? []), 0, 200) as $src) {
                $src = img_large(clean_url($src));
                $p = media_path($src);
                if (!$p || !is_file($p)) throw new InvalidArgumentException('Only pictures stored on this site can be added: ' . $src);
                if (in_array($src, $have, true)) continue;
                do { $id = bin2hex(random_bytes(4)); } while (in_array($id, $taken, true));
                $taken[] = $id;
                $have[] = $src;
                $folder = basename(dirname($src));
                $scene = str_starts_with($folder, 'scene-') ? m3d_item('scenes', substr($folder, 6)) : null;
                if (!$scene && !isset($slugs[$folder])) $scene = $usedBy($src);   // stored in another item's folder: the scene showing it
                $new[] = ['id' => $id, 'src' => $src, 'date' => $today, 'chars' => isset($slugs[$folder]) ? [$folder] : ($scene ? array_values(array_filter((array) ($scene['models'] ?? []), fn($m) => isset($slugs[$m]))) : [])]
                    + ($scene ? ['scene' => $scene['slug']] : []);
            }
            $g['stills'] = array_merge($new, $g['stills']);
            $res = ['added' => array_column($new, 'id'), 'skipped' => count((array) ($in['srcs'] ?? [])) - count($new)];
        } elseif ($action === 'save') {
            $id = (string) ($in['id'] ?? '');
            $k = array_search($id, array_column($g['stills'], 'id'), true);
            if ($k === false) throw new InvalidArgumentException('This picture is no longer in the gallery (removed in another tab?). Reload the page.');
            $g['stills'][$k] = clean_still((array) ($in['still'] ?? []), $g['stills'][$k]);
        } elseif ($action === 'delete') {
            // only out of the gallery: the picture stays in the Media library
            $g['stills'] = array_values(array_filter($g['stills'], fn($s) => !in_array($s['id'], $ids, true)));
            m3d_gallery_forget_likes($ids);
        } elseif ($action === 'order') {
            // the cards in their new order; pictures the list didn't name (added in another tab) keep their place after them
            $by = array_column($g['stills'], null, 'id');
            $new = [];
            foreach ($ids as $id) if (isset($by[$id])) { $new[] = $by[$id]; unset($by[$id]); }
            $g['stills'] = array_merge($new, array_values($by));
        } elseif ($action === 'visibility') {
            $v = (string) ($in['value'] ?? '');
            if (!isset(GALLERY_VISIBILITY[$v])) throw new InvalidArgumentException('Unknown visibility.');
            foreach ($g['stills'] as &$s) {
                if (!in_array($s['id'], $ids, true)) continue;
                if ($v === 'public') unset($s['visibility']); else $s['visibility'] = $v;
            }
            unset($s);
        } elseif ($action === 'link') {
            // one full size link for several pictures (often one file per character); an empty address removes theirs
            $link = clean_still_link($in['link'] ?? null);
            foreach ($g['stills'] as &$s) {
                if (!in_array($s['id'], $ids, true)) continue;
                if ($link) $s['link'] = $link; else unset($s['link']);
            }
            unset($s);
        } elseif ($action === 'chars') {
            // characters for several pictures at once: the ones named in remove go (any case), the ones in add join (20 at most, like clean_still())
            $add = clean_list($in['add'] ?? [], 80, 20);
            $drop = array_map('mb_strtolower', clean_list($in['remove'] ?? [], 80, 200));
            foreach ($g['stills'] as &$s) {
                if (!in_array($s['id'], $ids, true)) continue;
                $chars = array_values(array_filter((array) ($s['chars'] ?? []), fn($c) => !in_array(mb_strtolower((string) $c), $drop, true)));
                foreach ($add as $c) if (count($chars) < 20 && !in_array(mb_strtolower($c), array_map(fn($x) => mb_strtolower((string) $x), $chars), true)) $chars[] = $c;
                $s['chars'] = $chars;
            }
            unset($s);
        } else {
            throw new InvalidArgumentException('Unknown change.');
        }
        if (!m3d_save_gallery($g)) throw new RuntimeException(m3d_write_error('data'));
        m3d_discord_stills_after_save($oldStills, $g['stills']);   // pictures that became visible to everyone: a "New renders" post a little later
        admin_json(['ok' => true, 'gallery' => admin_gallery_payload()] + $res);
    }

    $kind = in_array($in['kind'] ?? 'models', M3D_ITEM_KINDS, true) ? (string) $in['kind'] : 'models';
    $action = $in['action'] ?? 'save';
    $items = m3d_items($kind);
    $index = [];
    foreach ($items as $i => $m) $index[$m['slug']] = $i;
    // the editor's Delete button: the same as ticking that one row
    if ($action === 'delete') {
        if (!isset($index[(string) ($in['slug'] ?? '')])) throw new InvalidArgumentException('Not found.');
        [$action, $in['slugs'], $in['op']] = ['batch', [(string) $in['slug']], 'delete'];
    }

    if ($action === 'reorder') {
        $slugs = array_values(array_map('strval', (array) ($in['slugs'] ?? [])));
        $a = $slugs; $b = array_map('strval', array_keys($index)); sort($a); sort($b);   // strval: an address of digits only ("2077") is an int key
        if ($a !== $b) throw new InvalidArgumentException('The list changed in the meantime. Reload the page and try again.');
        $sorted = [];
        foreach ($slugs as $k => $slug) { $m = $items[$index[$slug]]; $m['order'] = $k + 1; $sorted[] = $m; }
        if (!m3d_save_items($kind, $sorted)) throw new RuntimeException(m3d_write_error('data'));
        admin_json(['ok' => true]);
    }

    // ticked rows of the Models / Accessories list: { slugs: [], op: delete | visibility | status, value }
    if ($action === 'batch') {
        $slugs = array_values(array_unique(array_map('strval', (array) ($in['slugs'] ?? []))));
        $slugs = array_values(array_filter($slugs, fn($s) => isset($index[$s])));
        if (!$slugs) throw new InvalidArgumentException('Nothing selected (or already deleted in another tab). Reload the page.');
        $op = (string) ($in['op'] ?? '');
        $value = (string) ($in['value'] ?? '');
        $skipped = [];
        if ($op === 'delete') {
            $gone = array_column(array_filter($items, fn($m) => in_array($m['slug'], $slugs, true)), 'name', 'slug');
            $goneVis = [];
            $gone3d = [];
            foreach ($items as $m) if (isset($gone[$m['slug']])) {
                $goneVis[$m['slug']] = m3d_release_hidden($m) ? 'draft' : m3d_visibility($m);
                $gone3d[] = (string) ($m['model3d'] ?? '');
            }
            $items = array_values(array_filter($items, fn($m) => !in_array($m['slug'], $slugs, true)));
        } elseif ($op === 'visibility') {
            if (!isset(M3D_VISIBILITY[$value])) throw new InvalidArgumentException('Unknown visibility.');
            foreach ($slugs as $s) {
                $m = &$items[$index[$s]];
                // a password needs one: items that never had a password keep their visibility
                if ($value === 'password' && empty($m['passwordHash'])) { $skipped[] = $m['name']; unset($m); continue; }
                if ($value === 'public') unset($m['visibility']); else $m['visibility'] = $value;
                if ($value !== 'password') unset($m['unlisted']);
                unset($m);
            }
        } elseif ($op === 'status' && $kind === 'models') {
            if (!isset(M3D_STATUSES[$value])) throw new InvalidArgumentException('Unknown status.');
            foreach ($slugs as $s) $items[$index[$s]]['status'] = $value;
        } else {
            throw new InvalidArgumentException('Unknown change.');
        }
        if (!m3d_save_items($kind, $items)) throw new RuntimeException(m3d_write_error('data'));
        if ($op === 'delete') m3d_glb_forget($gone3d);
        if ($op === 'delete') admin_forget_items($kind, $gone, $goneVis);   // counters, likes, unlocks, bug reports, Discord: a new item at the same address starts from zero
        if ($op === 'delete' && $kind === 'models') {
            // forget the models in the outfits made for them
            $outfits = m3d_outfits();
            $changed = false;
            foreach ($outfits as &$o) {
                $before = $o['models'] ?? [];
                $o['models'] = array_values(array_diff($before, $slugs));
                $changed = $changed || $before !== $o['models'];
            }
            unset($o);
            if ($changed) m3d_save_items('outfits', $outfits);
            // and in the scenes made with them (their versions too)
            $scenes = m3d_items('scenes', true);
            $changed = false;
            foreach ($scenes as &$o) {
                $before = $o['models'] ?? [];
                $o['models'] = array_values(array_diff($before, $slugs));
                if (isset($o['modelVers'])) { $o['modelVers'] = array_diff_key((array) $o['modelVers'], array_flip($slugs)); if (!$o['modelVers']) unset($o['modelVers']); }
                $changed = $changed || $before !== $o['models'];
            }
            unset($o);
            if ($changed) m3d_save_items('scenes', $scenes);
            m3d_gallery_forget_models($gone, $goneVis);   // their pictures in the gallery keep the name, and stay as hidden as the model made them
        }
        if ($op === 'delete' && $kind === 'scenes') m3d_gallery_forget_scenes($slugs);   // the Gallery pictures linked to them lose their Download Scene button
        admin_json(['ok' => true, 'changed' => count($slugs) - count($skipped), 'skipped' => $skipped]);
    }

    // save
    $original = (string) ($in['originalSlug'] ?? '');
    $existing = $original !== '' ? ($items[$index[$original] ?? -1] ?? null) : null;
    if ($original !== '' && !$existing) throw new InvalidArgumentException('This entry no longer exists (deleted in another tab?).');
    // an editor left open must not put old data back: the revision it was opened with must still be the stored one
    if ($existing && isset($in['rev']) && !hash_equals(admin_item_rev($existing), (string) $in['rev'])) {
        admin_json(['ok' => false, 'error' => 'This was changed in another tab or by an Images job: reload the editor (your changes here are not saved).'], 409);
    }
    $data = (array) ($in['item'] ?? []);
    $item = match ($kind) { 'outfits' => clean_outfit($data, $existing), 'scenes' => clean_scene($data, $existing), default => clean_model($data, $existing) };
    if (!$existing && isset($index[$item['slug']])) throw new InvalidArgumentException('The address "' . $item['slug'] . '" is already used. Pick another one.');

    if ($existing) $items[$index[$original]] = $item;
    else {
        // new entries go first: everything else moves down one place (the order is the list order, 1 = top)
        foreach ($items as &$x) $x['order'] = (int) ($x['order'] ?? 999) + 1;
        unset($x);
        $item['order'] = 1;
        array_unshift($items, $item);
    }
    $dcBefore = m3d_discord()['last'];   // a post made by this save changes it (admin_discord_result())
    if (!m3d_save_items($kind, $items)) throw new RuntimeException(m3d_write_error('data'));
    if ($existing && ($existing['model3d'] ?? '') !== ($item['model3d'] ?? '')) m3d_glb_forget([(string) ($existing['model3d'] ?? '')]);   // replaced or removed
    $stored = m3d_item($kind, $item['slug']) ?? $item;
    admin_json(['ok' => true, 'slug' => $item['slug'], 'created' => !$existing, 'rev' => admin_item_rev($stored),
        'discord' => admin_discord_result($dcBefore), 'discordState' => admin_discord_state($kind, $stored)]);
} catch (InvalidArgumentException $ex) {
    admin_json(['ok' => false, 'error' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    m3d_problem('Saving', $ex->getMessage());
    admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
}
