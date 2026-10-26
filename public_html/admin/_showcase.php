<?php
// Media → From Patrons (admin/images.php?tab=patrons; included by images.php only): the renders patrons shared from model / accessory pages
// (app/showcase.php). Waiting ones get Approve / Reject (rejecting deletes the picture); approved ones show on their item's page, with "Also in
// Gallery" (a still with `from`), Hide (kept, shown nowhere: the Hidden section, Show puts it back; its Gallery copy follows, m3d_render_hidden())
// and Remove. Ban / Unban on each card: the Renders ban of Admin → Access (m3d_ban_set()). The X / Patreon link the patron gave on their name and
// the full-quality link: a pill each, its ✕ = Remove Link.
// + Add Render (the page's header): a render the creator adds by hand (showcase_add_dialog(), do=add → showcase_add()), shown at once.
// Clicking a card's picture opens its window (showcase_edit_dialog(), [data-sc-edit] in admin.js, filled from the card's data-sc): the picture and its
// settings (item, name, Patreon account, links, caption, shown, Gallery), saved by do=edit → showcase_edit(); Approve there saves and approves.
// One grid of Media cards: waiting, then shown per item in their order on the item's page, then hidden. With one item picked in the menu, dragging a
// shown card's ⠿ saves the order at once (JSON {order: [ids], item: "kind:slug"} to admin/showcase.php), Reset Order (do=reset) goes back to newest first.
defined('M3D_PRIVATE') || exit;

/** The `do` values of the tab's forms (Media's own forms post to the same address). */
const SHOWCASE_DOS = ['add', 'edit', 'reset', 'approve', 'reject', 'remove', 'ban', 'unban', 'unlink', 'unfull', 'hide', 'show', 'gallery'];

/** Handles a From Patrons form post: the result goes in the floating messages. */
function showcase_post(): void
{
    $get = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET';   // a form posted from this address (it keeps ?added= / ?edited=) doesn't say it again
    if ($get && ($a = m3d_get_text('added')) !== '' && ($r = m3d_render($a))) admin_flash('✓ Added: it shows on the ' . ($r['kind'] === 'outfits' ? 'accessory' : 'model') . '’s page.');
    if ($get && ($a = m3d_get_text('edited')) !== '' && ($r = m3d_render($a))) admin_flash(m3d_get_text('approved') !== '' ? '✓ Saved and approved: it shows on the ' . ($r['kind'] === 'outfits' ? 'accessory' : 'model') . '’s page.' : '✓ Saved.');
    $do = $_POST['do'] ?? null;
    if (!in_array($do, SHOWCASE_DOS, true) || !admin_form_post()) return;   // Media's own forms (Delete All Unused…) post to the same address too
    if ($do === 'add') {
        [$id, $error] = showcase_add();
        if ($id !== '') m3d_redirect('/admin/images.php?tab=patrons&added=' . $id . '#r-' . $id);   // a reload doesn't add it twice
        admin_result(null, $error, 'Not added:');
        $GLOBALS['showcase_add_failed'] = true;   // the window opens again with what was typed (the picture must be picked again)
        return;
    }
    $done = $error = null;
    $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
    $r = preg_match('/^[0-9a-f]{8}$/D', $id) ? m3d_render($id) : null;
    if ($do === 'reset') {   // an item's renders back to newest first
        [$kind, $slug] = array_pad(explode(':', is_string($_POST['item'] ?? null) ? $_POST['item'] : '', 2), 2, '');
        if (in_array($kind, ['models', 'outfits'], true) && $slug !== '' && m3d_renders_order($kind, $slug, [])) $done = 'Order reset: newest first.';
        else $error = m3d_write_error('data');
    } elseif (!$r) {
        $error = 'This render is no longer here (removed meanwhile?).';
    } elseif ($do === 'edit') {
        [$approved, $error] = showcase_edit($r);
        if ($error === '') m3d_redirect('/admin/images.php?tab=patrons&edited=' . $id . ($approved ? '&approved=1' : '') . '#r-' . $id);
        if ($approved === null) {
            admin_result(null, $error, 'Not saved:');
            $GLOBALS['showcase_edit_failed'] = ['id' => $id];
            foreach (['item', 'name', 'named', 'link_t', 'link_h', 'full', 'caption', 'who', 'shown', 'gallery'] as $k) $GLOBALS['showcase_edit_failed'][$k] = showcase_str($k, 1000);
            return;
        }
        admin_result(null, $error);   // saved, but the Gallery copy wasn't
        return;
    } elseif ($do === 'approve') {
        $res = m3d_renders_update(function (array $rs) use ($id) {
            foreach ($rs as &$x) if (($x['id'] ?? '') === $id && empty($x['ok'])) { $x['ok'] = true; $x['ok_at'] = time(); }
            unset($x);
            return [$rs, true];
        });
        if ($res === null) $error = m3d_write_error('data');
        else $done = 'Approved: it shows on the ' . ($r['kind'] === 'outfits' ? 'accessory' : 'model') . '’s page.';
    } elseif ($do === 'reject' || $do === 'remove') {
        $n = m3d_renders_delete(fn($x) => ($x['id'] ?? '') === $id);
        if ($n === null) $error = m3d_write_error('data'); else $done = $do === 'reject' ? 'Rejected: the picture and the request were deleted.' : 'Removed: the picture was deleted.';
    } elseif ($do === 'ban' || $do === 'unban') {   // a ban in Admin → Access: they can't share renders any more (their renders stay until removed)
        if (in_array((string) ($r['by'] ?? ''), m3d_owner_ids($GLOBALS['M3D']), true)) $error = 'This render is an admin’s: admins can’t be banned.';
        elseif (m3d_ban_set((string) ($r['by'] ?? ''), 'renders', $do === 'ban', (string) ($r['name'] ?? ''))) $done = $do === 'ban' ? 'Banned: they can’t share renders any more. Manage bans in Access.' : 'Unbanned: they can share renders again.';
        else $error = m3d_write_error('data');
    } elseif ($do === 'unlink') {   // the profile link on their name goes, the render stays
        $res = m3d_renders_update(function (array $rs) use ($id) {
            foreach ($rs as &$x) if (($x['id'] ?? '') === $id) unset($x['link']);
            unset($x);
            return [$rs, true];
        });
        if ($res === null) $error = m3d_write_error('data'); else $done = 'Link removed: the name shows without it.';
    } elseif ($do === 'unfull') {   // the full-quality link goes, the render stays
        $res = m3d_renders_update(function (array $rs) use ($id) {
            foreach ($rs as &$x) if (($x['id'] ?? '') === $id) unset($x['full']);
            unset($x);
            return [$rs, true];
        });
        if ($res === null) $error = m3d_write_error('data'); else $done = 'Link removed: no full-quality download any more.';
    } elseif (($do === 'hide' || $do === 'show') && !empty($r['ok'])) {   // kept, but off the item's page and the Gallery
        $res = m3d_renders_update(function (array $rs) use ($id, $do) {
            foreach ($rs as &$x) if (($x['id'] ?? '') === $id) { if ($do === 'hide') $x['hidden'] = true; else unset($x['hidden']); }
            unset($x);
            return [$rs, true];
        });
        if ($res === null) $error = m3d_write_error('data');
        else $done = $do === 'hide' ? 'Hidden: it no longer shows on the ' . ($r['kind'] === 'outfits' ? 'accessory' : 'model') . '’s page' . (isset(array_flip(m3d_render_stills())[$id]) ? ' or in the Gallery' : '') . '.' : 'Shown again.';
    } elseif ($do === 'gallery' && !empty($r['ok'])) {
        $on = !empty($_POST['on']);
        if (m3d_render_gallery($r, $on)) $done = $on ? 'Added to the Gallery.' : 'Taken out of the Gallery.'; else $error = m3d_write_error('data');
    }
    admin_result($done, $error);
}

/**
 * do=add: a render the creator adds by hand (one a patron sent another way): the picture, the item (a folder of admin_folder_picks()), the name shown
 * (empty = "A patron"), the Patreon account (optional, ID or name, m3d_find_patrons(): it then shows in their My Renders, Personal Data finds it),
 * the link on the name, the full-quality link, the caption, Also in Gallery. Approved at once. Returns [render id, ''] or ['', error].
 */
/** A posted text field of the From Patrons windows, at most $max characters ('' when missing or not a text). */
function showcase_str(string $k, int $max = 200): string
{
    return is_string($_POST[$k] ?? null) ? mb_substr($_POST[$k], 0, $max) : '';
}

/** The posted item (a folder of admin_folder_picks(): "slug" or "outfit-slug"): [kind, slug], or null when it isn't a model or accessory. */
function showcase_item_posted(): ?array
{
    $f = showcase_str('item');
    [$kind, $slug] = str_starts_with($f, 'outfit-') && m3d_item('outfits', substr($f, 7)) ? ['outfits', substr($f, 7)] : ['models', $f];
    return preg_match('/^[a-z0-9-]{1,100}$/D', $slug) && m3d_item($kind, $slug) ? [$kind, $slug] : null;
}

/** The posted Patreon account (ID or name, m3d_find_patrons()): [id, known name], ['', ''] for none, or an error text. */
function showcase_who_posted(): array|string
{
    if (($who = trim(showcase_str('who'))) === '') return ['', ''];
    $hits = m3d_find_patrons($who, 2);
    if (count($hits) > 1) return 'Several people match “' . $who . '”: pick one from the list, or type their Patreon user ID.';
    if (!$hits || !preg_match('/^\d{1,20}$/D', (string) $hits[0][0])) return 'Nobody called “' . $who . '” is known here: type their Patreon user ID (on their My Account page), or leave the field empty.';
    return [(string) $hits[0][0], (string) $hits[0][1]];
}

function showcase_add(): array
{
    $str = 'showcase_str';
    if (!($it = showcase_item_posted())) return ['', 'Pick the model or accessory it was made with.'];
    [$kind, $slug] = $it;
    $name = trim($str('name')) === '' ? '' : m3d_clean_name($str('name'));
    $who = showcase_who_posted();
    if (is_string($who)) return ['', $who];
    [$by, $known] = $who;
    $named = $name !== '';
    $link = $named ? m3d_render_link_clean($str('link_t'), $str('link_h')) : [];
    if ($link === null) return ['', SHOWCASE_ERRORS['link']];
    $full = m3d_render_full_clean($str('full', SHOWCASE_FULL_MAX + 1));
    if ($full === null) return ['', SHOWCASE_ERRORS['full']];
    [$src, $err] = m3d_session_pause(fn() => m3d_showcase_save_pic($_FILES['pic'] ?? null));   // resizing a 4K render takes a moment
    if ($err !== '') return ['', strtr(SHOWCASE_ERRORS[$err] ?? SHOWCASE_ERRORS['save'], ['{mb}' => showcase_mb(m3d_showcase_max())])];
    $now = time();
    $r = ['id' => '', 'kind' => $kind, 'slug' => $slug, 'src' => $src, 'by' => $by, 'name' => $named ? $name : $known, 'named' => $named] + ($link ? ['link' => $link] : [])
        + ($full !== '' ? ['full' => $full] : []) + ['caption' => m3d_render_caption($_POST['caption'] ?? ''), 'at' => $now, 'ok' => true, 'ok_at' => $now, 'added' => true];
    $res = m3d_renders_update(function (array $rs) use (&$r) {
        $taken = array_column($rs, 'id');
        do { $r['id'] = bin2hex(random_bytes(4)); } while (in_array($r['id'], $taken, true));
        array_unshift($rs, $r);
        return [$rs, true];
    });
    if ($res === null) {
        m3d_showcase_delete_files([$src]);
        return ['', m3d_write_error('data')];
    }
    if (!empty($_POST['gallery']) && !m3d_render_gallery($r, true)) admin_flash('<b>Not in the Gallery:</b> ' . e(m3d_write_error('data')) . ' Use Add to the Gallery in its ⋯ menu to try again.', 'err');
    return [$r['id'], ''];
}

/**
 * do=edit (the card's window): the item it was made with (moving it starts its place on the new item's page afresh), the name and Show Name,
 * the Patreon account ('' = nobody), the link on the name (only while the name shows), the full-quality link, the caption; for approved ones
 * Show on the Page (off = Hidden) and Also in Gallery (its copy follows the edit: m3d_render_gallery_sync()); approve=1 (a waiting one's Approve)
 * approves it too. Returns [approved, ''], [approved, error] when the render was saved but its Gallery copy wasn't, or [null, error].
 */
function showcase_edit(array $r): array
{
    $str = 'showcase_str';
    if (!($it = showcase_item_posted())) return [null, 'Pick the model or accessory it was made with.'];
    [$kind, $slug] = $it;
    $name = trim($str('name')) === '' ? '' : m3d_clean_name($str('name'));
    $named = !empty($_POST['named']);
    $who = showcase_who_posted();
    if (is_string($who)) return [null, $who];
    [$by, $known] = $who;
    if ($name === '') $name = $known;
    if ($named && $name === '') return [null, 'Type the name to show, or turn off Show Name.'];
    $link = $named ? m3d_render_link_clean($str('link_t'), $str('link_h')) : [];
    if ($link === null) return [null, SHOWCASE_ERRORS['link']];
    $full = m3d_render_full_clean($str('full', SHOWCASE_FULL_MAX + 1));
    if ($full === null) return [null, SHOWCASE_ERRORS['full']];
    $caption = m3d_render_caption($_POST['caption'] ?? '');
    $approve = empty($r['ok']) && !empty($_POST['approve']);
    $new = null;
    $res = m3d_renders_update(function (array $rs) use (&$new, $r, $kind, $slug, $name, $named, $by, $link, $full, $caption, $approve) {
        foreach ($rs as &$x) {
            if (($x['id'] ?? '') !== $r['id']) continue;
            if ($x['kind'] !== $kind || $x['slug'] !== $slug) { unset($x['pos']); [$x['kind'], $x['slug']] = [$kind, $slug]; }
            [$x['name'], $x['named'], $x['by'], $x['caption']] = [$name, $named, $by, $caption];
            if ($link) $x['link'] = $link; else unset($x['link']);
            if ($full !== '') $x['full'] = $full; else unset($x['full']);
            if ($approve) { $x['ok'] = true; $x['ok_at'] = time(); }
            if (!empty($x['ok'])) { if (empty($_POST['shown'])) $x['hidden'] = true; else unset($x['hidden']); }
            $new = $x;
        }
        unset($x);
        return [$rs, true];
    });
    if ($res === null) return [null, m3d_write_error('data')];
    if (!$new) return [null, 'This render is no longer here (removed meanwhile?).'];
    if (!empty($new['ok'])) {
        $want = !empty($_POST['gallery']);
        if (!m3d_render_gallery_sync($r, $new) || !m3d_render_gallery($new, $want)) return [$approve, 'Saved, but the Gallery copy wasn’t: ' . m3d_write_error('data')];
    }
    return [$approve, ''];
}

/** The + Add Render window (opened by the page header's button, [data-sc-add] in admin.js); after a refused add it opens again with what was typed. */
function showcase_add_dialog(array $pick, array $names): string
{
    $again = !empty($GLOBALS['showcase_add_failed']);
    $v = fn(string $k) => $again && is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $max = m3d_showcase_max();
    return '<dialog class="sc-dlg" data-sc-add data-max="' . $max . '"' . ($again ? ' data-open' : '') . ' aria-labelledby="sc-add-t"><form method="post" enctype="multipart/form-data">'
        . form_keys('add') . '<h2 id="sc-add-t">Add a Render</h2>'
        . '<label class="sc-drop" data-sc-drop><input type="file" name="pic" accept="image/jpeg,image/png,image/webp" required data-sc-file>'
        . '<img alt="" hidden data-sc-img><span class="sc-drop-t"><b data-sc-name>Choose a Picture…</b><span data-sc-meta>JPG, PNG or WebP, up to ' . showcase_mb($max) . ' MB. Drop it here or click.</span></span></label>'
        . showcase_item_select($pick, $names, $v('item'))
        . '<label class="field"><span class="lbl">Made By <span class="muted small">(optional)</span></span><input class="input" name="name" maxlength="80" autocomplete="off" placeholder="Name shown on the render, empty = “A patron”" value="' . e($v('name')) . '" data-sc-made></label>'
        . '<label class="field"><span class="lbl">Patreon Account <span class="muted small">(optional)</span>' . tip('Their Patreon user ID, or their name if they have downloaded here. The render then shows in their My Account, where they can remove it.') . '</span>'
        . '<input class="input" name="who" maxlength="200" autocomplete="off" list="sc-add-who" placeholder="Patreon ID or name" value="' . e($v('who')) . '"><datalist id="sc-add-who">' . m3d_patron_options() . '</datalist></label>'
        . '<div class="field sc-link" data-sc-link><span class="lbl">Link on Name <span class="muted small">(optional)</span></span><div class="sc-link-row">'
        . '<select class="input" name="link_t" aria-label="Network" data-sc-link-t><option value="">No Link</option>'
        . implode('', array_map(fn($k, $n) => '<option value="' . $k . '"' . ($v('link_t') === $k ? ' selected' : '') . '>' . e($n[0]) . '</option>', array_keys(RENDER_LINKS), RENDER_LINKS)) . '</select>'
        . '<input class="input" name="link_h" maxlength="200" autocomplete="off" placeholder="Username or profile address" aria-label="Username or profile address" value="' . e($v('link_h')) . '" data-sc-link-h></div></div>'
        . '<label class="field"><span class="lbl">Full Quality Link <span class="muted small">(optional)</span></span><input class="input" type="url" name="full" maxlength="' . SHOWCASE_FULL_MAX . '" placeholder="https://…" autocomplete="off" inputmode="url" value="' . e($v('full')) . '"></label>'
        . '<label class="field"><span class="lbl">Caption <span class="muted small">(optional)</span></span><input class="input" name="caption" maxlength="' . SHOWCASE_CAPTION . '" autocomplete="off" value="' . e($v('caption')) . '"></label>'
        . '<label class="toggle"><input type="checkbox" class="switch" name="gallery" value="1"' . ($again && !empty($_POST['gallery']) ? ' checked' : '') . '><span>Also in Gallery</span></label>'
        . '<p class="sc-note">It shows on the page at once, among the Shown renders.</p>'
        . '<div class="sc-actions"><button class="btn btn-ghost" type="button" data-sc-close>Cancel</button><button class="btn btn-primary" type="submit">Add Render</button></div>'
        . '</form></dialog>';
}

/** The windows' Model or Accessory field: every model and accessory in the folder picker (folderPicker() in admin.js). */
function showcase_item_select(array $pick, array $names, string $sel = ''): string
{
    $opt = fn(string $f) => folder_option($f, $pick, $names, $sel === $f);
    return '<div class="field"><span class="lbl">Model or Accessory</span><select class="input" name="item" data-folder-pick="item" data-search-ph="Search models and accessories…" aria-label="Model or accessory" data-sc-item>'
        . '<option value="">Choose…</option>'
        . '<optgroup label="Models">' . implode('', array_map(fn($m) => $opt($m['slug']), m3d_items('models'))) . '</optgroup>'
        . (m3d_outfits() ? '<optgroup label="Accessories">' . implode('', array_map(fn($o) => $opt('outfit-' . $o['slug']), m3d_outfits())) . '</optgroup>' : '')
        . '</select></div>';
}

/** A render's window (the Media picture window's look, .iw): the picture, ‹ › through the cards shown, and its settings; admin.js fills it from the
 *  card's data-sc. Remove / Reject and Ban post from it too (their own `do`, after the form's: the last one wins). */
function showcase_edit_dialog(array $pick, array $names): string
{
    $net = implode('', array_map(fn($k, $n) => '<option value="' . $k . '">' . e($n[0]) . '</option>', array_keys(RENDER_LINKS), RENDER_LINKS));
    $again = $GLOBALS['showcase_edit_failed'] ?? null;   // a refused save: the window opens again with what was typed
    return '<dialog class="iw sc-iw" data-sc-edit aria-label="Patron render"' . ($again ? ' data-again="' . e(json_encode($again, JSON_UNESCAPED_UNICODE)) . '"' : '') . '>'
        . '<header class="iw-head"><div class="iw-hl"><span class="iw-title" data-e-title></span><span class="iw-pos" data-e-pos></span></div>'
        . '<div class="iw-hr"><a class="btn btn-ghost btn-sm iw-full" target="_blank" rel="noopener" title="Open full size in a new tab" data-e-full><span class="t">Open Full Size </span>↗</a>'
        . '<button type="button" class="iw-x" aria-label="Close" data-e-close>✕</button></div></header>'
        . '<div class="iw-stage"><button type="button" class="iw-nav iw-prev" aria-label="Previous render" data-e-go="-1">‹</button>'
        . '<div class="iw-pan"><div class="iw-view"><img alt="" data-e-img></div></div>'
        . '<button type="button" class="iw-nav iw-next" aria-label="Next render" data-e-go="1">›</button></div>'
        . '<aside class="iw-side"><form method="post" class="sc-eform" data-e-form>' . form_keys('edit') . '<input type="hidden" name="id" value="" data-e-id>'
        . '<button class="sr-only" tabindex="-1" aria-hidden="true">Save</button>'   // the form's first button: Enter in a field saves (not Remove, the footer's first)
        . '<div class="iw-scroll">'
        . '<p class="sc-e-state" data-e-state></p>'
        . '<div class="sc-e-sw" data-e-oks><label class="sc-e-tog" title="Shown on the item’s page (off = hidden, kept)"><input type="checkbox" class="sr-only" name="shown" value="1" data-e-shown>' . SHOWCASE_PAGE_ICON . '<span>On the Page</span></label>'
        . '<label class="sc-e-tog" title="A copy in the Gallery"><input type="checkbox" class="sr-only" name="gallery" value="1" data-e-gallery>' . str_replace(' class="bi"', '', SHOWCASE_ICON) . '<span>In Gallery</span></label></div>'
        . showcase_item_select($pick, $names)
        . '<div class="field"><label class="lbl" for="sc-e-name">Made By</label><div class="sc-e-in">'
        . '<input class="input" id="sc-e-name" name="name" maxlength="80" autocomplete="off" placeholder="Name shown on the render" data-e-name>'
        . '<label class="sc-e-eye" data-e-eye><input type="checkbox" class="sr-only" name="named" value="1" data-e-named>' . SHOWCASE_EYE . SHOWCASE_EYE_OFF . '</label></div></div>'
        . '<div class="field sc-link"><span class="lbl">Link on Name</span><div class="sc-link-row">'
        . '<select class="input" name="link_t" aria-label="Network" data-e-link-t><option value="">No Link</option>' . $net . '</select>'
        . '<input class="input" name="link_h" maxlength="200" autocomplete="off" placeholder="Username or profile address" aria-label="Username or profile address" data-e-link-h></div></div>'
        . '<label class="field"><span class="lbl">Full Quality Link</span><input class="input" type="url" name="full" maxlength="' . SHOWCASE_FULL_MAX . '" placeholder="https://…" autocomplete="off" inputmode="url" data-e-full-in>'
        . '<span class="sc-e-dead small" data-e-dead hidden>Dead link (Link Check).</span></label>'
        . '<label class="field"><span class="lbl">Caption</span><input class="input" name="caption" maxlength="' . SHOWCASE_CAPTION . '" autocomplete="off" data-e-caption></label>'
        . '<div class="field"><label class="lbl" for="sc-e-who">Patreon Account' . tip('Their Patreon user ID, or their name if they have downloaded here. The render shows in their My Account, where they can remove it. Empty = nobody’s.') . '</label>'
        . '<input class="input" id="sc-e-who" name="who" maxlength="200" autocomplete="off" list="sc-add-who" placeholder="Patreon ID or name" data-e-who></div>'
        . '</div>'
        . '<footer class="iw-foot"><span class="sc-e-dels"><button class="btn btn-ghost btn-sm iw-del" name="do" value="remove" formnovalidate data-e-del>Remove</button>'
        . '<button class="sc-e-ban" name="do" value="ban" formnovalidate data-e-ban>Ban</button></span>'
        . '<button class="btn btn-primary" data-e-save>Save</button><button class="btn btn-primary" name="approve" value="1" data-e-approve>Approve</button></footer>'
        . '</form></aside></dialog>';
}

/** The tab's content: the filters (search, model / accessory menu, Waiting Only: the other Media tabs' row), then every render as a Media card
 *  (one grid like the other tabs): waiting ones first (dashed, like on the item's page), then the shown ones per item in their order, then the
 *  hidden ones. With one item picked in the menu (and no search), its shown cards get the ⠿. $pick / $names = admin_folder_picks(). */
function showcase_tab_html(array $pick, array $names): string
{
    $renders = m3d_renders(true);
    $inGallery = array_flip(m3d_render_stills());
    // the menu's folders and the shown cards' order: the items in the site's own order (models, then accessories), like the other tabs' menu
    $order = array_flip(array_keys($pick));
    $fold = fn(array $x) => ($x['kind'] === 'outfits' ? 'outfit-' : '') . $x['slug'];
    $byItem = fn(string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b];
    $folders = array_values(array_unique(array_map($fold, $renders)));
    usort($folders, $byItem);
    $waiting = array_values(array_filter($renders, fn($x) => empty($x['ok'])));
    $hidden = array_values(array_filter($renders, fn($x) => !empty($x['ok']) && !empty($x['hidden'])));
    usort($hidden, fn($a, $b) => (int) ($b['ok_at'] ?? 0) <=> (int) ($a['ok_at'] ?? 0));
    $groups = [];   // folder => its shown renders
    foreach ($renders as $x) if (!empty($x['ok']) && empty($x['hidden'])) $groups[$fold($x)][] = $x;
    uksort($groups, $byItem);
    $shown = array_merge(...array_values(array_map('m3d_renders_sorted', $groups)));

    $lcRes = lc_renders_on() ? lc_results() : [];   // Link Check → Patron Renders: a dead full-quality link shows red
    $card = function (array $x) use ($inGallery, $lcRes, $fold): string {
        $item = m3d_item($x['kind'], $x['slug']);
        $iname = $item ? ($x['kind'] === 'models' ? m3d_model_name($item) : $item['name']) : $x['slug'];
        $what = $x['kind'] === 'outfits' ? 'accessory' : 'model';
        $named = !empty($x['named']);
        $who = trim((string) ($x['name'] ?? '')) ?: (empty($x['by']) ? 'A patron' : 'Patron');
        $ok = !empty($x['ok']);
        $hid = $ok && !empty($x['hidden']);
        $byId = (string) ($x['by'] ?? '');   // '' = added by the creator for nobody (showcase_add()): no account, no ban
        $banned = $byId !== '' && in_array($byId, m3d_ban_ids('renders'), true);
        $canBan = $byId !== '' && !in_array($byId, m3d_owner_ids($GLOBALS['M3D']), true);   // never the site's admins (m3d_ban_set() refuses them)
        $form = fn(string $do, string $inner, string $confirm = '', string $extra = '') => '<form method="post"' . ($confirm !== '' ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '') . '>'
            . form_keys($do) . '<input type="hidden" name="id" value="' . e($x['id']) . '" />' . $extra . $inner . '</form>';
        $dl = render_full_dl($x);
        $dead = $dl && ($lcRes[md5($dl['href'])]['st'] ?? '') === 'dead';
        $l = m3d_render_link($x);
        $gal = isset($inGallery[$x['id']]);
        $search = admin_search_text([$iname, $x['slug'], $who, $byId, (string) ($x['caption'] ?? ''), $l['label'] ?? '']);
        // the links after the name: the profile on their name and the full-quality picture, as small icons (removed in ⋯)
        $icons = ($l ? '<a class="sc-ico" href="' . e($l['url']) . '" target="_blank" rel="noopener nofollow" title="' . e('Link on the name: ' . $l['url']) . '">' . render_link_icon($l['net']) . '</a>' : '')
            . ($dl ? '<a class="sc-ico' . ($dead ? ' is-dead' : '') . '" href="' . e($dl['href']) . '" target="_blank" rel="noopener nofollow" title="' . ($dead ? 'Dead link (Link Check): ' : 'Full quality link: ') . e($dl['href']) . '"><svg class="sc-net" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m0 0-5-5m5 5 5-5M5 20h14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></a>' : '');
        $menu = bug_post_menu([
            '<a class="pm-item" href="' . e(m3d_item_url($x['kind'], $x['slug'])) . '#' . ($ok && !$hid ? 'r-' . e($x['id']) : 'patron-renders') . '" target="_blank">View on the ' . ucfirst($what) . ' Page</a>',
            $ok ? $form('gallery', '<button class="pm-item">' . ($gal ? 'Take Out of the Gallery' : 'Add to the Gallery') . '</button>', '', $gal ? '' : '<input type="hidden" name="on" value="1" />') : '',
            $l ? $form('unlink', '<button class="pm-item">Remove the Link on the Name</button>', 'Remove this link from their name?') : '',
            $dl ? $form('unfull', '<button class="pm-item">Remove the Full Quality Link</button>', 'Remove the full-quality link? The viewer’s Download Full Size goes (a Gallery copy keeps its own link: edit it in Admin → Gallery).') : '',
            $byId !== '' ? '<a class="pm-item" href="/admin/log.php?patron=' . e(rawurlencode($byId)) . '">Their Downloads · ' . e($byId) . '</a>' : '',
            $canBan ? $form($banned ? 'unban' : 'ban', '<button class="pm-item' . ($banned ? '' : ' is-red') . '">' . ($banned ? 'Unban' : 'Ban from Sharing Renders') . '</button>', $banned ? '' : 'Ban ' . $who . ' from sharing renders?' . "\n\n" . 'They won’t be able to share new renders until you unban them (here or in Access). The renders they already shared stay until you remove them.') : '',
        ]);
        $small = img_small($x['src']);
        [$w, $h] = img_dims($x['src']) ?: [0, 0];
        $lk = is_array($x['link'] ?? null) ? $x['link'] : [];
        $lh = (string) ($lk['h'] ?? '');
        $info = ['id' => $x['id'], 'src' => $x['src'], 'item' => $fold($x), 'iname' => $iname, 'url' => m3d_item_url($x['kind'], $x['slug']), 'what' => $what,
            'name' => (string) ($x['name'] ?? ''), 'named' => $named, 'by' => $byId, 'canBan' => $canBan, 'banned' => $banned, 'link_t' => (string) ($lk['t'] ?? ''),
            'link_h' => str_starts_with($lh, 'u:') ? RENDER_LINKS['patreon'][1] . 'user?u=' . substr($lh, 2) : $lh, 'full' => (string) ($x['full'] ?? ''), 'dead' => $dead,
            'caption' => (string) ($x['caption'] ?? ''), 'ok' => $ok, 'hidden' => $hid, 'gallery' => $gal, 'added' => !empty($x['added']),
            'sent' => m3d_site_time((int) $x['at'], 'j M Y'), 'okAt' => $ok ? m3d_site_time((int) ($x['ok_at'] ?? $x['at']), 'j M Y') : ''];
        return '<figure class="' . ($ok && !$hid ? 'sc-sh ' : '') . 'lib-card sc-lib' . ($ok ? '' : ' is-wait') . ($hid ? ' is-hidden' : '') . '" id="r-' . e($x['id']) . '" data-search="' . e($search) . '" data-folder="' . e($fold($x)) . '"' . (isset($x['pos']) ? ' data-pos' : '') . ' data-sc="' . e(json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '">'
            . '<a class="lib-img" href="' . e($x['src']) . '" target="_blank" rel="noopener" title="Edit this render" data-sc-open>'
            . (!$w || !$h || abs($w / $h - 9 / 16) > 0.02 ? '<img class="lib-bg" src="' . e($small) . '" alt="" aria-hidden="true" loading="lazy" decoding="async" />' : '')
            . '<img src="' . e($small) . '" alt="" loading="lazy" decoding="async" />'
            . (!$ok ? '<span class="sc-wait">' . SHOWCASE_CLOCK . '<span>Waiting</span></span>' : ($hid ? '<span class="sc-wait sc-hid">' . SHOWCASE_EYE_OFF . '<span>Hidden</span></span>' : ''))
            . ($gal && !$hid ? '<span class="sc-gal" title="Also in the Gallery">Gallery</span>' : '') . '</a>'
            . ($ok && !$hid ? '<button type="button" class="drag-handle sc-drag" title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>' : '')
            . '<figcaption>'
            . '<span class="lib-name sc-who"><span title="' . e($who . ($named ? '' : ' (name hidden on the site)') . ($byId !== '' ? ' · ' . $byId : '')) . '">' . e($who) . '</span>'
            . ($named ? '' : ' <span class="muted small">(hidden)</span>') . ($banned ? ' <a class="badge tone-red" href="/admin/users.php?tab=exceptions&amp;ex=' . e(rawurlencode($byId)) . '#ex-' . e($byId) . '" title="Banned from sharing renders: see Access">Banned</a>' : '') . $icons . '</span>'
            . '<span class="muted small lib-meta" title="' . e($iname) . '"><a href="' . e(m3d_item_url($x['kind'], $x['slug'])) . '#patron-renders">' . e($iname) . '</a> · ' . e(m3d_site_time((int) ($ok ? ($x['ok_at'] ?? $x['at']) : $x['at']), 'j M Y')) . (empty($x['added']) ? '' : ' · added by you') . '</span>'
            . (($c = trim((string) ($x['caption'] ?? ''))) !== '' ? '<span class="small lib-use" title="' . e($c) . '">“' . e($c) . '”</span>' : '<span class="small lib-use muted">No caption</span>')
            . '<span class="row-btns">'
            . (!$ok ? $form('approve', '<button class="btn btn-primary btn-sm">Approve</button>')
                : $form($hid ? 'show' : 'hide', '<button class="btn btn-sm ' . ($hid ? 'btn-primary' : 'btn-ghost') . '" title="' . ($hid ? 'Show it again on the ' . $what . '’s page' : 'Keep it, but show it nowhere on the site') . '">' . ($hid ? 'Show' : 'Hide') . '</button>'))
            . $menu
            . ($ok ? $form('remove', '<button class="icon-btn del" title="Remove" aria-label="Remove">✕</button>', 'Remove this render?' . "\n\n" . 'It leaves the ' . $what . '’s page and the Gallery, and the picture is deleted for good. This can’t be undone.')
                : $form('reject', '<button class="icon-btn del" title="Reject" aria-label="Reject">✕</button>', 'Reject this render? The uploaded picture and the request are deleted for good. This can’t be undone.'))
            . '</span></figcaption></figure>';
    };
    ob_start();
?>
    <?php if (!m3d_showcase_on()): ?>
      <p class="notice">Patron renders are off: nobody can share new ones and none show on the pages. <a href="<?= e(settings_url('showcase')) ?>">Turn them on in Settings →</a></p>
    <?php endif ?>

    <?php if ($renders): ?>
      <div class="lib-filters">
        <input class="input" type="search" placeholder="Search patron, caption or model…" data-sc-search aria-label="Search the renders" autocomplete="off" spellcheck="false" />
        <select class="input" data-sc-folder data-folder-pick="filter" data-search-ph="Search models and accessories…" aria-label="Model or accessory" <?= count($folders) < 2 ? 'hidden' : '' ?>>
          <option value="">All Items</option>
          <?php foreach ($folders as $f) echo folder_option($f, $pick, $names) ?>
        </select>
        <label class="toggle lib-unused"><input type="checkbox" class="switch" data-sc-wait /><span>Waiting Only</span></label>
        <form method="post" class="sc-reset" data-sc-reset hidden onsubmit="return confirm('Put these renders back in newest-first order?')">
          <?= form_keys('reset') ?><input type="hidden" name="item" value="" />
          <button class="btn btn-ghost btn-sm" title="Back to newest first">Reset Order</button>
        </form>
      </div>
      <div class="lib sc-lib-grid" data-sc-grid><?php foreach (array_merge($waiting, $shown, $hidden) as $x) echo $card($x) ?></div>
      <p class="muted small empty-lib" data-sc-none hidden>No renders match.</p>
    <?php else: ?>
      <p class="muted">No renders yet. Patrons share them from the model and accessory pages, or add one with + Add Render.</p>
    <?php endif ?>
    <?= showcase_add_dialog($pick, $names) ?>
    <?= $renders ? showcase_edit_dialog($pick, $names) : '' ?>
<?php
    return (string) ob_get_clean();
}
