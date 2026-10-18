<?php
// GET JSON for "Jump to…" (Ctrl+K / the magnifier in the admin header, admin.js initJump()): every admin page, Settings box,
// model, accessory, docs page, blog post and bug report, as [{t: title, k: kind label, s: line under the title, u: address, p: small picture,
// x: extra words to match}]; models and accessories also v (latest version, "Version 4") and d (its date, a timestamp, 0 = none).
// ?items=1: only the models and accessories (Quick Add → Add Changelog Version, bell.js).
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

// the models and accessories (with their latest version), first: Quick Add asks for these alone
$items = [];
$ver = function (array $it): array {
    $v = m3d_latest_version($it);
    return ['v' => bug_version_words(m3d_version_label($v)), 'd' => m3d_changelog_ts($v['date']) ?? 0];
};
foreach (m3d_models() as $m) {
    $gen = ($m['generation'] ?? '') !== '' ? 'Gen ' . $m['generation'] : '';
    $add(m3d_model_name($m), 'Model', '/admin/edit.php?slug=' . rawurlencode($m['slug']), '',
        implode(' · ', array_filter([(string) ($m['franchise'] ?? ''), $gen, $vis($m)])), item_pic('models', $m));
    $items[] = array_pop($out) + $ver($m);
}
$names = array_column(m3d_models(), 'name', 'slug');
foreach (m3d_outfits() as $o) {
    $for = array_values(array_filter(array_map(fn($s) => (string) ($names[$s] ?? ''), (array) ($o['models'] ?? []))));
    $add($o['name'], m3d_accessory_type($o), '/admin/edit.php?kind=outfits&slug=' . rawurlencode($o['slug']), 'accessory',
        implode(' · ', array_filter([$for ? 'For ' . implode(', ', array_slice($for, 0, 3)) . (count($for) > 3 ? '…' : '') : '', $vis($o)])), item_pic('outfits', $o));
    $items[] = array_pop($out) + $ver($o);
}
if (isset($_GET['items'])) admin_json(['ok' => true, 'items' => $items]);

$add('Home', 'Page', '/admin/', 'dashboard overview downloads today week needs a look');
$add('Models', 'Page', '/admin/models.php', 'assets characters list add model');
$add('Accessories', 'Page', '/admin/outfits.php', 'assets outfits hair list add accessory');
$add('Link Check', 'Page', '/admin/links.php', 'broken dead links mega transfer.it downloads check');
$add('Gallery', 'Page', '/admin/gallery.php', 'renders stills pictures');
if (m3d_blog_on() || m3d_blog()['posts']) $add('Blog', 'Page', '/admin/blog.php', 'posts news articles write');
$add('Docs', 'Page', '/admin/docs.php', 'documentation pages');
$add('FAQ', 'Page', '/admin/faq.php', 'questions answers');
if (m3d_bugs_on()) {
    $add('Bug Reports', 'Page', '/bugs/', 'bugs list not seen waiting hidden tags');
}
$add('Downloads', 'Page', '/admin/log.php', 'log patrons csv export reset unlocks');
$add('Stats', 'Page', '/admin/stats.php', 'statistics totals downloads likes logins patrons tiers versions uptake chart');
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
    'notify'      => ['Notifications', 'bell alerts discord webhook private channel new comments bug reports dead links problems updates'],
    'timezone'    => ['Time Zone', 'clock timezone utc cet cest berlin hours'],
    'blog'        => ['Blog (Settings)', 'posts news turn on off title'],
    'welcome'     => ['Welcome Page', 'first login tour new patrons onboarding landing intro'],
    'bugs'        => ['Bug Reports (Settings)', 'bugs uploads moderation blender mustardui versions'],
    'comments'    => ['Comments (Settings)', 'comments comment moderation approve block delete all'],
    'discord'     => ['Discord Announcements', 'webhook post new versions renders gallery message'],
    's4'          => ['MEGA S4 Downloads', 'object storage bucket signed temporary download links keys download domain worker proxy cdn'],
    'mustardui'   => ['MustardUI Page', 'add-on addon'],
    'smutbase'    => ['SmutBase Import (Setting)', 'import'],
    'patreon'     => ['Patreon', 'client id secret campaign currency keys creator access refresh token pledge history months as a patron'],
    'logins'      => ['Logins', 'session days log out'],
    'maintenance' => ['Maintenance Mode', 'back soon offline'],
    'problems'    => ['Site Problems', 'errors log failed broken'],
    'tools'       => ['Tools', 'php cache sessions clear log unused images temporary files'],
    'system'      => ['System', 'php version disk space'],
    'personal-data' => ['Personal Data', 'gdpr export user data access request dsgvo patron download copy'],
];
foreach ($boxes as $id => [$t, $s]) $add($t, 'Settings', settings_url($id), $s);

array_push($out, ...$items);
foreach (m3d_doc_pages() as $p) {
    $add((string) ($p['title'] ?? $p['slug']), 'Docs Page', '/admin/docs.php?page=' . rawurlencode((string) $p['slug']), '',
        implode(' · ', array_filter([(string) ($p['section'] ?? ''), !empty($p['draft']) ? 'Draft' : ''])));
}
foreach (m3d_blog()['posts'] as $p) {
    $state = ['draft' => 'Draft', 'scheduled' => 'Scheduled'][m3d_post_state($p)] ?? '';
    $add((string) $p['title'], 'Blog Post', '/admin/blog.php?post=' . rawurlencode($p['id']), implode(' ', (array) ($p['topics'] ?? [])),
        implode(' · ', array_filter([m3d_site_time(m3d_post_ts($p), 'j M Y'), $state, ($p['visibility'] ?? '') === 'patrons' ? 'Patrons Only' : ''])), (string) ($p['cover'] ?? ''));
}
if (m3d_bugs_on()) {
    foreach (array_slice(m3d_bugs(), 0, 300) as $b) $add('#' . (int) $b['n'] . ' ' . (string) ($b['title'] ?? ''), 'Bug Report', '/bugs/' . (int) $b['n'] . '/', '', (string) ($b['about_name'] ?? ''));
}
admin_json(['ok' => true, 'items' => $out]);
