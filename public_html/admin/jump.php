<?php
// GET JSON for "Jump to…" (Ctrl+K / the magnifier in the admin header, admin.js initJump()): every admin page, Settings box,
// model, accessory, docs page and bug report, as [{t: title, k: kind label, s: line under the title, u: address, p: small picture,
// x: extra words to match}].
// Only addresses and names the admin already sees; loaded once, when the box is first opened.
require __DIR__ . '/_admin.php';
admin_require($M3D, true);
m3d_session_release();

$out = [];
$add = function (string $t, string $k, string $u, string $x = '', string $s = '', string $p = '') use (&$out) {
    $out[] = ['t' => $t, 'k' => $k, 'u' => $u, 's' => $s, 'p' => $p !== '' ? img_thumb($p) : '', 'x' => $x];
};
// the visibility, as the admin lists' badges say it ('' = public)
$vis = fn(array $it) => match (m3d_visibility($it)) {
    'draft' => 'Draft', 'password' => 'Password', 'blurred' => 'Blurred', 'blurred-patrons' => 'Blurred · Clear for Patrons', default => '',
};

$add('Home', 'Page', '/admin/', 'dashboard overview downloads today week needs a look');
$add('Models', 'Page', '/admin/models.php', 'assets characters list add model');
$add('Accessories', 'Page', '/admin/outfits.php', 'assets outfits hair list add accessory');
$add('Link Check', 'Page', '/admin/links.php', 'broken dead links mega transfer.it downloads check');
$add('Gallery', 'Page', '/admin/gallery.php', 'renders stills pictures');
$add('Docs', 'Page', '/admin/docs.php', 'documentation pages');
$add('FAQ', 'Page', '/admin/faq.php', 'questions answers');
if (m3d_bugs_on()) {
    $add('Bug Reports', 'Page', '/bugs/', 'bugs list not seen waiting hidden tags');
}
$add('Downloads', 'Page', '/admin/log.php', 'log patrons totals stats likes csv export');
$add('Access', 'Page', '/admin/users.php', 'users exceptions unlock limits rules download wait');
$add('Images', 'Page', '/admin/images.php', 'library pictures upload crop unused media');
$add('Backups', 'Page', '/admin/backups.php', 'restore history');
$add('Update', 'Page', '/admin/update.php', 'install site update remote zip version github channel stable development key');
if (m3d_smutbase_on()) $add('Import from SmutBase', 'Page', '/admin/import.php', 'smutbase import models');
$add('+ Add Model', 'Page', '/admin/edit.php', 'new model create');
$add('+ Add Accessory', 'Page', '/admin/edit.php?kind=outfits', 'new accessory outfit create');

// the Settings boxes, with words people may look for
$boxes = [
    'identity'    => ['Site Identity', 'name logo icon colour color tagline brand'],
    'social'      => ['Social Links', 'discord patreon x twitter footer'],
    'homepage'    => ['Homepage Section', 'plans how to get tiers'],
    'notice'      => ['Site Notice', 'banner announcement message'],
    'legal'       => ['Legal & Contact', 'privacy imprint address support email'],
    'welcome'     => ['Welcome Page', 'first login tour new patrons onboarding landing intro'],
    'bugs'        => ['Bug Reports (Settings)', 'bugs webhook uploads moderation blender mustardui versions'],
    'discord'     => ['Discord Announcements', 'webhook post new versions renders gallery message'],
    's4'          => ['MEGA S4 Downloads', 'object storage bucket signed temporary download links keys'],
    'mustardui'   => ['MustardUI Page', 'add-on addon'],
    'smutbase'    => ['SmutBase Import (Setting)', 'import'],
    'patreon'     => ['Patreon', 'client id secret campaign currency keys creator access refresh token pledge history months as a patron'],
    'logins'      => ['Logins', 'session days log out'],
    'maintenance' => ['Maintenance Mode', 'back soon offline'],
    'problems'    => ['Site Problems', 'errors log failed broken'],
    'tools'       => ['Tools', 'php cache sessions clear log unused images temporary files cloudflare'],
    'system'      => ['System', 'php version disk space'],
];
foreach ($boxes as $id => [$t, $s]) $add($t, 'Settings', settings_url($id), $s);

foreach (m3d_models() as $m) {
    $gen = ($m['generation'] ?? '') !== '' ? 'Gen ' . $m['generation'] : '';
    $add(m3d_model_name($m), 'Model', '/admin/edit.php?slug=' . rawurlencode($m['slug']), '',
        implode(' · ', array_filter([(string) ($m['franchise'] ?? ''), $gen, $vis($m)])), item_pic('models', $m));
}
$names = array_column(m3d_models(), 'name', 'slug');
foreach (m3d_outfits() as $o) {
    $for = array_values(array_filter(array_map(fn($s) => (string) ($names[$s] ?? ''), (array) ($o['models'] ?? []))));
    $add($o['name'], m3d_accessory_type($o), '/admin/edit.php?kind=outfits&slug=' . rawurlencode($o['slug']), 'accessory',
        implode(' · ', array_filter([$for ? 'For ' . implode(', ', array_slice($for, 0, 3)) . (count($for) > 3 ? '…' : '') : '', $vis($o)])), item_pic('outfits', $o));
}
foreach (m3d_doc_pages() as $p) {
    $add((string) ($p['title'] ?? $p['slug']), 'Docs Page', '/admin/docs.php?page=' . rawurlencode((string) $p['slug']), '',
        implode(' · ', array_filter([(string) ($p['section'] ?? ''), !empty($p['draft']) ? 'Draft' : ''])));
}
if (m3d_bugs_on()) {
    foreach (array_slice(m3d_bugs(), 0, 300) as $b) $add('#' . (int) $b['n'] . ' ' . (string) ($b['title'] ?? ''), 'Bug Report', '/bugs/' . (int) $b['n'] . '/', '', (string) ($b['about_name'] ?? ''));
}
admin_json(['ok' => true, 'items' => $out]);
