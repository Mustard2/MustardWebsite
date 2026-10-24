<?php
// Admin → Gallery → From Patrons: the renders patrons shared from model / accessory pages (app/showcase.php). Waiting ones get Approve /
// Reject (rejecting deletes the picture); approved ones show on their item's page, with "Also in Gallery" (a still with `from`) and Remove.
// Ban / Unban on each card: the Renders ban of Admin → Access (m3d_ban_set()). The X / Patreon link the patron gave on their name and the
// full-quality link: Remove Link each.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$done = $error = null;
$id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
$r = preg_match('/^[0-9a-f]{8}$/D', $id) ? m3d_render($id) : null;
if (admin_form_post()) {
    $do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
    if (!$r) {
        $error = 'This render is no longer here (removed meanwhile?).';
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
        if (m3d_ban_set((string) ($r['by'] ?? ''), 'renders', $do === 'ban', (string) ($r['name'] ?? ''))) $done = $do === 'ban' ? 'Banned: they can’t share renders any more. Manage bans in Access.' : 'Unbanned: they can share renders again.';
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
    } elseif ($do === 'gallery' && !empty($r['ok'])) {
        $on = !empty($_POST['on']);
        if (m3d_render_gallery($r, $on)) $done = $on ? 'Added to the Gallery.' : 'Taken out of the Gallery.'; else $error = m3d_write_error('data');
    }
    admin_result($done, $error);
}

$renders = m3d_renders(true);
$inGallery = array_flip(m3d_render_stills());
$waiting = array_values(array_filter($renders, fn($x) => empty($x['ok'])));
$shown = array_values(array_filter($renders, fn($x) => !empty($x['ok'])));
usort($shown, fn($a, $b) => (int) ($b['ok_at'] ?? 0) <=> (int) ($a['ok_at'] ?? 0));

$card = function (array $x) use ($inGallery): string {
    $item = m3d_item($x['kind'], $x['slug']);
    $iname = $item ? $item['name'] : $x['slug'];
    $named = !empty($x['named']);
    $who = trim((string) ($x['name'] ?? '')) ?: 'Patron';
    $ok = !empty($x['ok']);
    $form = fn(string $do, string $inner, string $confirm = '') => '<form method="post"' . ($confirm !== '' ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '') . '>'
        . form_keys($do) . '<input type="hidden" name="id" value="' . e($x['id']) . '" />' . $inner . '</form>';
    $banned = in_array((string) ($x['by'] ?? ''), m3d_ban_ids('renders'), true);
    $ban = $form($banned ? 'unban' : 'ban', '<button class="btn btn-ghost btn-sm sc-ban">' . ($banned ? 'Unban' : 'Ban') . '</button>', $banned ? '' : 'Ban ' . $who . ' from sharing renders? Their renders stay until you remove them. You can change it in Access.');
    $h = '<article class="sc-card' . ($ok ? '' : ' is-wait') . '" id="r-' . e($x['id']) . '">'
        . '<a class="sc-card-pic" href="' . e($x['src']) . '" target="_blank" rel="noopener" title="Open the full picture"><img src="' . e(img_small($x['src'])) . '" alt="" loading="lazy" /></a>'
        . '<div class="sc-card-body">'
        . '<a class="sc-card-item" href="' . e(m3d_item_url($x['kind'], $x['slug'])) . '#patron-renders">' . e($iname) . '</a>'
        . '<div class="sc-card-by"><b>' . e($who) . '</b>' . ($named ? '' : ' <span class="muted small">(name hidden)</span>')
        . ' <a class="muted small" href="/admin/log.php?patron=' . e(rawurlencode((string) $x['by'])) . '" title="Their downloads">' . e((string) $x['by']) . '</a>'
        . ($banned ? ' <a class="badge tone-red" href="/admin/users.php?tab=exceptions&amp;ex=' . e(rawurlencode((string) $x['by'])) . '#ex-' . e((string) $x['by']) . '" title="Banned from sharing renders: see Access">Banned</a>' : '') . '</div>'
        . (($dl = render_full_dl($x)) ? '<div class="sc-card-link small"><a href="' . e($dl['href']) . '" target="_blank" rel="noopener nofollow" title="' . e($dl['href']) . '">' . e($dl['note']) . ' ↗</a>'
            . $form('unfull', '<button class="btn btn-ghost btn-sm">Remove Link</button>', 'Remove the full-quality link? The viewer’s Download Full Size goes (a Gallery copy keeps its own link: edit it in Renders).') . '</div>' : '')
        . (($l = m3d_render_link($x)) ? '<div class="sc-card-link small"><a href="' . e($l['url']) . '" target="_blank" rel="noopener nofollow" title="Shown as a link on their name">' . render_link_icon($l['net']) . e($l['label']) . ' ↗</a>'
            . $form('unlink', '<button class="btn btn-ghost btn-sm">Remove Link</button>', 'Remove this link from their name?') . '</div>' : '')
        . (($c = trim((string) ($x['caption'] ?? ''))) !== '' ? '<div class="sc-card-cap">“' . e($c) . '”</div>' : '<div class="sc-card-cap muted">No caption</div>')
        . '<div class="muted small">' . e(m3d_site_time((int) ($ok ? ($x['ok_at'] ?? $x['at']) : $x['at']), 'j M Y, H:i T')) . ($ok ? ' · approved' : '') . '</div>';
    if (!$ok) {
        $h .= '<div class="sc-card-act">'
            . $ban . $form('reject', '<button class="btn btn-ghost btn-sm">Reject</button>', 'Reject this render? The uploaded picture and the request are deleted for good. This can’t be undone.')
            . $form('approve', '<button class="btn btn-primary btn-sm">Approve</button>') . '</div>';
    } else {
        $h .= $form('gallery', '<label class="toggle sc-card-gal"><input type="checkbox" class="switch" name="on" value="1" onchange="this.form.requestSubmit()"' . (isset($inGallery[$x['id']]) ? ' checked' : '') . ' /><span>Also in Gallery</span></label>')
            . '<div class="sc-card-act">' . $ban . $form('remove', '<button class="btn btn-ghost btn-sm">Remove</button>', 'Remove this render? It leaves the page (and the Gallery) and the picture is deleted.') . '</div>';
    }
    return $h . '</div></article>';
};

admin_start('Gallery', 'gallery');
?>
    <div class="admin-head">
      <div>
        <h1>Gallery</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="/gallery/">View Page</a>
      </div>
    </div>
    <?= admin_gallery_tabs('patrons') ?>

    <?php if (!m3d_showcase_on()): ?>
      <p class="notice">Patron renders are off: nobody can share new ones and none show on the pages. <a href="<?= e(settings_url('showcase')) ?>">Turn them on in Settings →</a></p>
    <?php endif ?>

    <h2 class="sc-h">Waiting for Approval <span class="count"><?= count($waiting) ?></span><?= tip('Patrons share renders from a model’s or accessory’s page. Nothing shows until you approve it; rejecting deletes the picture.') ?></h2>
    <?php if ($waiting): ?>
      <div class="sc-grid"><?php foreach ($waiting as $x) echo $card($x) ?></div>
    <?php else: ?>
      <p class="muted">Nothing waiting.</p>
    <?php endif ?>

    <h2 class="sc-h">Shown <span class="count"><?= count($shown) ?></span><?= tip('In their item’s gallery, after your pictures. “Also in Gallery” adds one to the Gallery as well, credited to the patron; you can edit it there like any picture.') ?></h2>
    <?php if ($shown): ?>
      <div class="sc-grid"><?php foreach ($shown as $x) echo $card($x) ?></div>
    <?php else: ?>
      <p class="muted">None yet.</p>
    <?php endif ?>
<?php admin_end();
