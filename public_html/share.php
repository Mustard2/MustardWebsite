<?php
// /share/<code>/: a temporary link the creator gave someone (app/shares.php). The item's picture and name, then one Download button per
// link: each posts here (f = the link's key) and is sent on like /get/ (m3d_download_url()), counted on the temporary link only.
// A link that ended (expired, cancelled, used up, its item deleted) or an unknown code: a short page saying so.
require __DIR__ . '/app/_init.php';
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');   // the address is the key: never passed on to the download host

$code = m3d_get_text('code');
$here = '/share/' . rawurlencode($code) . '/';
$share = m3d_share($code);
$item = $share ? m3d_item((string) ($share['kind'] ?? ''), (string) ($share['slug'] ?? '')) : null;
$state = $share ? m3d_share_state($share, $item) : 'unknown';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    maintenance_gate();
    age_gate_page($here);   // not confirmed 18+ yet: the age gate first, then back to this page
    $key = is_string($_POST['f'] ?? null) ? $_POST['f'] : '';
    $link = $state === 'active' ? (array_values(array_filter(m3d_share_links($item), fn($l) => $l['key'] === $key))[0] ?? null) : null;
    if ($link && m3d_download_url($link['url']) === null) m3d_redirect($here . '?err=ready');   // an "s4:" link without a bucket: nowhere to send anyone, nothing used
    $use = m3d_share_use($code, $key);
    if (!$use['ok']) m3d_redirect($here . ($use['why'] === 'save' ? '?err=save' : ''));   // the page says why (ended, used up)
    m3d_redirect((string) m3d_download_url($use['link']['url']));
}

$err = ['ready' => 'This download isn’t ready yet. Please try again later.', 'save' => 'Something went wrong. Please try again.'][m3d_get_text('err')] ?? '';
if ($err !== '') site_flash(e($err), 'err');
$ended = [
    'unknown'   => ['This Link Doesn’t Work', 'The address may be cut short, or the link was deleted. Ask the person who sent it for a new one.'],
    'expired'   => ['This Link Has Expired', 'Temporary links stop working after a while. Ask the person who sent it for a new one.'],
    'cancelled' => ['This Link No Longer Works', 'It was turned off. Ask the person who sent it for a new one.'],
    'used'      => ['All Downloads Used', 'This link has been used as many times as it allows. Ask the person who sent it if you need it again.'],
    'gone'      => ['Nothing to Download', 'The files of this link aren’t available any more. Ask the person who sent it for a new one.'],
][$state] ?? null;

if ($ended) {
    http_response_code($state === 'unknown' ? 404 : 410);
    layout_start($ended[0], '', 'page-gate');
    echo '<section class="wrap gate"><p class="eyebrow">Temporary Link</p><h1>' . e($ended[0]) . '</h1><p class="lead">' . e($ended[1]) . '</p>'
        . '<div class="cta"><a class="btn btn-ghost" href="/">Visit ' . e(site_name()) . '</a></div></section>';
    layout_end();
    exit;
}

$kind = (string) $share['kind'];
$name = $kind === 'models' ? m3d_model_name($item) : (string) $item['name'];
$pic = item_pic($kind, $item);   // the creator shared the files themselves: the picture is shown as it is, even for a draft or blurred item
$ver = m3d_released_label($item);
$sub = array_filter([$kind === 'outfits' ? m3d_accessory_type($item) : (string) ($item['franchise'] ?? ''), $ver !== '' ? 'Version ' . $ver : '']);
$page = m3d_listed($item) && m3d_can_view($kind, $item) && !m3d_release_hidden($item) ? m3d_item_url($kind, (string) $item['slug']) : '';
// link previews (Discord, chat apps): the item's main picture, like its own page's, and what the link gives; the creator chose to share it,
// so a draft or blurred item's picture shows too (the page shows it as well); a GET uses nothing, so a preview never uses a download;
// then a short list, one line each (Discord keeps the line breaks)
$og = $kind === 'outfits' ? $pic : ((string) ($item['cover'] ?? '') ?: $pic);
if ($og !== '') page_image($og);
$files = count(m3d_share_links($item));
$max = (int) ($share['max'] ?? 0);
layout_start($name, implode("\n", [
    'A download shared with you' . ($sub ? ': ' . implode(' · ', $sub) : '') . '.',
    'Works until: ' . m3d_site_time((int) $share['until'], 'D j M, H:i T'),
    'Downloads per file: ' . ($max ?: 'No limit'),
    'Files: ' . $files,
]), 'page-gate');
?>
  <section class="wrap gate sh-page">
    <?php if ($pic !== ''): ?><div class="sh-pic"><img src="<?= e(img_small($pic)) ?>" alt="" /></div><?php endif ?>
    <p class="eyebrow">Shared with You</p>
    <h1><?= e($name) ?></h1>
    <?php if ($sub): ?><p class="sh-sub"><?= e(implode(' · ', $sub)) ?></p><?php endif ?>
    <div class="sh-files">
      <?php foreach (m3d_share_links($item) as $l): $left = m3d_share_left($share, $l['key']); ?>
        <form method="post" class="sh-file">
          <input type="hidden" name="f" value="<?= e($l['key']) ?>" />
          <button class="btn <?= $left === 0 ? 'btn-ghost' : 'btn-primary' ?>" type="submit"<?= $left === 0 ? ' disabled' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 20h14"/></svg><?= e($l['label']) ?></button>
          <?php if ($left !== null): ?><span class="sh-left"><?= $left === 0 ? 'No downloads left' : e(m3d_plural($left, 'download') . ' left') ?></span><?php endif ?>
        </form>
      <?php endforeach ?>
    </div>
    <p class="lead sh-until">This link works until <?= when_html((int) $share['until']) ?>. Please don’t pass it on.</p>
    <?php if ($page !== ''): ?><p class="note small"><a href="<?= e($page) ?>">See the <?= $kind === 'outfits' ? 'Accessory' : 'Model' ?> Page →</a></p><?php endif ?>
  </section>
<?php layout_end();
