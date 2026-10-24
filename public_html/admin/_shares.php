<?php
// Admin → Access → Temporary Links (a tab of admin/users.php): the links made for someone to download one model's
// or accessory's files without Patreon (app/shares.php; the page they open is share.php). The list: picture, item, note, made, expiry,
// downloads, status; Active / All chips; Copy, Cancel and ✕ Delete; "Delete All Ended" and "+ New Temporary Link" on the title line (the latter and the editor's
// Downloads box ("Temporary Link…") open the same window, shareWindow() in admin.js, which posts {add} to admin/shares.php).
defined('M3D_PRIVATE') || exit;

/** What the window needs to offer an item: kind, slug, name, line under it, picture, its files' names. */
function share_item_row(string $kind, array $it): array
{
    $sub = $kind === 'outfits' ? m3d_accessory_type($it) : trim((string) ($it['franchise'] ?? ''));
    return ['k' => $kind, 's' => (string) $it['slug'], 't' => $kind === 'models' ? m3d_model_name($it) : (string) ($it['name'] ?? ''), 'u' => $sub,
            'p' => ($p = item_pic($kind, $it)) !== '' ? img_thumb($p) : '', 'f' => array_column(m3d_share_links($it), 'label')];
}

/** The section's forms (do = share-cancel | share-delete | share-delete-ended, already CSRF-checked): [done, error]. */
function share_post(string $do): array
{
    if ($do === 'share-delete-ended') return m3d_shares_delete([], true) ? ['Ended temporary links deleted.', null] : [null, m3d_write_error('temporary links')];
    $s = m3d_share((string) ($_POST['code'] ?? ''));
    if (!$s) return [null, 'This temporary link isn’t in the list any more.'];
    if ($do === 'share-cancel') return m3d_share_cancel($s['code']) ? ['Temporary link cancelled: it no longer works.', null] : [null, m3d_write_error('temporary links')];
    if ($do === 'share-delete') return m3d_shares_delete([$s['code']]) ? ['Temporary link deleted.', null] : [null, m3d_write_error('temporary links')];
    return [null, null];
}

/** The section. */
function share_section(): void
{
    $now = time();
    $rows = [];
    foreach (m3d_shares() as $s) {
        $item = m3d_item((string) ($s['kind'] ?? ''), (string) ($s['slug'] ?? ''));
        $rows[] = [$s, $item, m3d_share_state($s, $item, $now)];
    }
    $active = count(array_filter($rows, fn($r) => $r[2] === 'active'));
    $items = [];
    foreach (['models', 'outfits'] as $k) foreach (m3d_items($k) as $it) if (m3d_share_links($it)) $items[] = share_item_row($k, $it);

    $badge = ['active' => ['tone-green', 'Active'], 'expired' => ['tone-grey', 'Expired'], 'used' => ['tone-grey', 'Used Up'], 'cancelled' => ['tone-grey', 'Cancelled'], 'gone' => ['tone-grey', 'No Files']];
    $when = fn(int $t) => m3d_site_time($t, 'j M, H:i');
    $act = '/admin/users.php?tab=links';
?>
    <section class="sec" id="links">
      <div class="sh-head">
        <h2 class="small-h">Temporary Links <span class="muted small">(<?= $active ?> active)</span><?= tip('A link for someone (a tester, a collaborator) to download every file of one model or accessory for a while, without Patreon. Each file can be downloaded as many times as the link allows. Anyone who has the address can use it, so cancel it when it’s no longer needed.') ?></h2>
        <span class="sh-bar-end">
          <?php if (count($rows) > $active): ?><button type="submit" form="share-ended" class="btn btn-ghost btn-sm">Delete All Ended</button><?php endif ?>
          <?php if ($items): ?><button type="button" class="btn btn-primary btn-sm" data-share-new="<?= e(json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>">+ New Temporary Link</button><?php endif ?>
        </span>
      </div>
      <form method="post" action="<?= $act ?>" id="share-ended" hidden onsubmit="return confirm('Delete every temporary link that expired, was used up or was cancelled?')"><?= form_keys('share-delete-ended') ?></form>
    <?php if ($rows): ?>
      <div class="exl-bar sh-bar">
        <button type="button" class="chip" data-share-chip="active" aria-pressed="<?= $active ? 'true' : 'false' ?>">Active <span><?= $active ?></span></button>
        <button type="button" class="chip" data-share-chip="" aria-pressed="<?= $active ? 'false' : 'true' ?>">All <span><?= count($rows) ?></span></button>
      </div>
    <div class="table-wrap">
      <table class="models-table sh-table" data-share-table>
        <thead><tr><th class="c-thumb"></th><th>Item</th><th>Note</th><th>Made</th><th>Expires</th><th>Downloads</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as [$s, $item, $st]):
            $kind = (string) $s['kind'];
            $pic = $item ? item_pic($kind, $item) : '';
            $max = (int) ($s['max'] ?? 0);
            $files = $item ? count(m3d_share_links($item)) : 0;
            $until = (int) $s['until'];
            $url = abs_url('/share/' . $s['code'] . '/');
        ?>
          <tr data-st="<?= $st ?>"<?= $st !== 'active' ? ' class="sh-ended"' : '' ?><?= $active && $st !== 'active' ? ' hidden' : '' ?>>
            <td class="c-thumb"><?= $pic !== '' ? '<img src="' . e(img_thumb($pic)) . '" alt="" loading="lazy" />' : '<span class="no-img"></span>' ?></td>
            <td class="sh-it"><?= $item ? '<a class="m-name" href="/admin/edit.php?kind=' . $kind . '&amp;slug=' . e(rawurlencode((string) $s['slug'])) . '">' . e((string) $s['name']) . '</a>' : '<b>' . e((string) $s['name']) . '</b>' ?>
              <div class="muted small"><?= $item ? e(($kind === 'outfits' ? 'Accessory' : 'Model') . ' · ' . m3d_plural($files, 'file')) : 'Removed' ?></div></td>
            <td class="sh-note"><?= ($s['note'] ?? '') !== '' ? e((string) $s['note']) : '<span class="muted">—</span>' ?></td>
            <td class="small sh-made"><?= e($when((int) $s['made'])) ?></td>
            <td class="small sh-exp"><?= $st === 'active' ? 'in ' . e(m3d_share_left_text($until - $now)) . '<div class="muted">' . e($when($until)) . '</div>' : (!empty($s['cancelled']) ? e($when((int) $s['cancelled'])) . '<div class="muted">Cancelled</div>' : e($when($until))) ?></td>
            <td class="sh-dl"><?= m3d_share_total($s) ?><div class="muted small"><?= $max ? e(m3d_plural($max, 'download')) . ' a file' : 'No limit' ?></div></td>
            <td class="sh-st"><span class="badge <?= $badge[$st][0] ?>"><?= $badge[$st][1] ?></span></td>
            <td class="c-actions sh-acts">
              <?php if ($st === 'active'): ?>
                <button type="button" class="btn btn-ghost btn-sm" data-share-copy="<?= e($url) ?>">Copy</button>
                <form method="post" action="<?= $act ?>" class="inline" onsubmit="return confirm('Cancel this link? It stops working at once.')"><?= form_keys('share-cancel') ?><input type="hidden" name="code" value="<?= e((string) $s['code']) ?>" /><button class="btn btn-ghost btn-sm">Cancel</button></form>
              <?php endif ?>
              <form method="post" action="<?= $act ?>" class="inline" onsubmit="return confirm(<?= e(json_encode($st === 'active' ? 'Delete this link? It stops working at once and leaves the list.' : 'Delete this link from the list?')) ?>)"><?= form_keys('share-delete') ?><input type="hidden" name="code" value="<?= e((string) $s['code']) ?>" /><button class="icon-btn del" title="Delete" aria-label="Delete">✕</button></form>
            </td>
          </tr>
        <?php endforeach ?>
          <tr data-share-empty hidden><td colspan="8" class="muted">No active links.</td></tr>
        </tbody>
      </table>
    </div>
    <?php endif ?>
    </section>
<?php
}
