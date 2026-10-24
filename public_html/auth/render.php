<?php
// Patron renders (app/showcase.php). POST forms of showcase_box():
//   do=share  {csrf, kind, slug, pic (file), full (optional https link to the picture in full quality, m3d_render_full_clean()), caption, named,
//             link_t + link_h (their X / Patreon profile, m3d_render_link_clean()), ok}: a paying patron (`access`), not previewing, on an item whose row shows;
//             per_day (Settings → Patron Renders) a day and SHOWCASE_WAITING waiting at most; saved as waiting for the creator's approval
//   do=remove {csrf, id, back?}: the patron's own render (waiting or approved) goes, with its files and its still in the Gallery (the ✕ on its gallery
//             tile, or in My Account → My Renders: back=account)
// Back to the item's #patron-renders (back=account: /account/#my-renders) with ?render=sent|removed, or ?rendererr=<code> (SHOWCASE_ERRORS).
require dirname(__DIR__) . '/app/_init.php';
header('X-Robots-Tag: noindex');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') m3d_redirect('/');
maintenance_gate();
// a file over PHP's post_max_size arrives as an empty POST (no kind, no token): the page it came from, if it was one of ours
if (!$_POST && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $ref = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH);
    m3d_redirect((is_string($ref) && preg_match('#^/(models|accessories)/[a-z0-9-]+/$#D', $ref) ? $ref : '/') . '?rendererr=big#patron-renders');
}
$do = is_string($_POST['do'] ?? null) ? $_POST['do'] : '';
$back = function (string $kind, string $slug, string $q): never {
    if (($_POST['back'] ?? '') === 'account') m3d_redirect('/account/?' . $q . '#my-renders');   // the ✕ of My Account → My Renders
    m3d_redirect(m3d_item_url($kind, $slug) . '?' . $q . '#patron-renders');
};

if ($do === 'remove') {
    $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
    $r = preg_match('/^[0-9a-f]{8}$/D', $id) ? m3d_render($id) : null;
    if (!$r) m3d_redirect('/');
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) $back($r['kind'], $r['slug'], 'rendererr=session');
    $p = m3d_patron($M3D);
    if (!$p || !empty($p['preview']) || (string) ($p['id'] ?? '') !== (string) ($r['by'] ?? '')) $back($r['kind'], $r['slug'], 'rendererr=gone');
    $n = m3d_renders_delete(fn($x) => ($x['id'] ?? '') === $id && (string) ($x['by'] ?? '') === (string) $p['id']);
    $back($r['kind'], $r['slug'], $n === null ? 'rendererr=save' : ($n ? 'render=removed' : 'rendererr=gone'));
}

if ($do !== 'share') m3d_redirect('/');
$kind = ($_POST['kind'] ?? '') === 'outfits' ? 'outfits' : 'models';
$slug = is_string($_POST['slug'] ?? null) ? $_POST['slug'] : '';
$item = preg_match('/^[a-z0-9-]{1,100}$/D', $slug) ? m3d_item($kind, $slug) : null;
if (!$item || !m3d_can_view($kind, $item)) not_found();
if (!m3d_check_csrf($_POST['csrf'] ?? null)) $back($kind, $slug, 'rendererr=session');
if (($why = m3d_render_can_share($kind, $item)) === 'banned') m3d_redirect(m3d_ban_url('renders'));   // Admin → Access: the page with the reason
if ($why !== '') $back($kind, $slug, 'rendererr=' . $why);
if (empty($_POST['ok'])) $back($kind, $slug, 'rendererr=consent');
$named = !empty($_POST['named']);
$link = $named ? m3d_render_link_clean(is_string($_POST['link_t'] ?? null) ? $_POST['link_t'] : '', is_string($_POST['link_h'] ?? null) ? mb_substr($_POST['link_h'], 0, 200) : '') : [];
if ($link === null) $back($kind, $slug, 'rendererr=link');   // checked before the picture is saved
$full = m3d_render_full_clean(is_string($_POST['full'] ?? null) ? $_POST['full'] : '');
if ($full === null) $back($kind, $slug, 'rendererr=full');

$p = m3d_patron($M3D);
$me = (string) $p['id'];
$mine = array_filter(m3d_renders(true), fn($r) => (string) ($r['by'] ?? '') === $me);
if (m3d_showcase_day_full($mine)) $back($kind, $slug, 'rendererr=day');
if (count(array_filter($mine, fn($r) => empty($r['ok']))) >= SHOWCASE_WAITING) $back($kind, $slug, 'rendererr=waiting');

[$src, $err] = m3d_session_pause(fn() => m3d_showcase_save_pic($_FILES['pic'] ?? null));   // resizing a 4K render takes a moment
if ($err !== '') $back($kind, $slug, 'rendererr=' . $err);
$cap = mb_scrub(is_string($_POST['caption'] ?? null) ? $_POST['caption'] : '', 'UTF-8');   // one line: no control or direction characters, like names
$cap = (string) preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{061C}]/u', '', $cap);
$cap = rtrim(mb_substr(trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $cap)), 0, SHOWCASE_CAPTION));
$r = ['id' => '', 'kind' => $kind, 'slug' => $slug, 'src' => $src, 'by' => $me, 'name' => (string) ($p['name'] ?? ''), 'named' => $named] + ($link ? ['link' => $link] : []) + ($full !== '' ? ['full' => $full] : []) + ['caption' => $cap, 'at' => time()];
// the limits again inside the lock: two sends at once must not both pass
$res = m3d_renders_update(function (array $rs) use (&$r, $me) {
    $mine = array_filter($rs, fn($x) => (string) ($x['by'] ?? '') === $me);
    if (m3d_showcase_day_full($mine)) return [$rs, 'day'];
    if (count(array_filter($mine, fn($x) => empty($x['ok']))) >= SHOWCASE_WAITING) return [$rs, 'waiting'];
    $taken = array_column($rs, 'id');
    do { $r['id'] = bin2hex(random_bytes(4)); } while (in_array($r['id'], $taken, true));
    array_unshift($rs, $r);
    return [$rs, 'ok'];
});
if ($res !== 'ok') {
    m3d_showcase_delete_files([$src]);
    $back($kind, $slug, 'rendererr=' . ($res ?? 'save'));
}
m3d_after_response(fn() => m3d_render_notify($r, $item));
$back($kind, $slug, 'render=sent');
