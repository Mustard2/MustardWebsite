<?php
// Blog editor: the posts on the left, the open post on the right (like Docs); built by admin.js initBlogEditor. Each post is
// saved on its own (save.php kind "blog", with the post's revision). Rules and data in app/blog.php.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$items = [];   // what "Models and Accessories in This Post" can pick (drafts too: they show on the post once listed);
// x = more words to find it by: an accessory's models with their franchises ("fantasy" finds Tifa's outfit), a model's generation
$models = array_column(m3d_models(), null, 'slug');
foreach (M3D_ITEM_KINDS as $kind) foreach (m3d_items($kind) as $it) {
    $pic = item_pic($kind, $it);
    if ($kind === 'models') {
        $sub = trim('Model · ' . ($it['franchise'] ?? ''), ' ·');
        $x = ($it['generation'] ?? '') !== '' ? 'gen ' . $it['generation'] : '';
    } elseif ($kind === 'scenes') {
        $with = array_map('m3d_model_name', array_values(array_filter(array_map(fn($s) => $models[$s] ?? null, (array) ($it['models'] ?? [])))));
        $sub = implode(' · ', array_filter(['Scene', $with ? 'With ' . implode(', ', array_slice($with, 0, 2)) . (count($with) > 2 ? '…' : '') : '']));
        $x = implode(' ', $with);
    } else {
        $fits = m3d_fits_all($it) ? [] : array_values(array_filter(array_map(fn($s) => $models[$s] ?? null, (array) ($it['models'] ?? []))));
        $for = m3d_fits_blender($it) ? ['Blender'] : (m3d_fits_all($it) ? ['All Models'] : array_map('m3d_model_name', $fits));
        $sub = implode(' · ', array_filter([m3d_accessory_type($it), $for ? 'For ' . implode(', ', array_slice($for, 0, 2)) . (count($for) > 2 ? '…' : '') : '']));
        $x = implode(' ', array_unique(array_merge($for, array_map(fn($m) => (string) ($m['franchise'] ?? ''), $fits))));
    }
    $items[] = ['ref' => $kind . ':' . $it['slug'], 'name' => $kind === 'models' ? m3d_model_name($it) : (string) $it['name'],
        'sub' => $sub, 'x' => $x, 'pic' => $pic !== '' ? img_thumb($pic) : ''];
}
$payload = ['kind' => 'blog', 'posts' => admin_blog_posts(), 'post' => m3d_get_text('post'), 'items' => $items,
    'discord' => m3d_discord_on(), 'discordUrl' => settings_url('discord'), 'comments' => m3d_comments_on('posts'), 'commentsUrl' => settings_url('comments'), 'now' => m3d_site_time(time(), 'Y-m-d\TH:i'), 'tz' => m3d_site_time(time(), 'T'),
    'video' => m3d_video_max()];

admin_start('Blog', 'blog');
?>
    <div class="admin-head sticky-head">
      <div>
        <h1>Blog</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="/blog/" data-view-page>View Post</a>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
    </div>
    <?php if (!m3d_blog_on()): ?><p class="notice">The blog is off: only admins can see it. <a href="<?= e(settings_url('blog')) ?>">Turn it on in Settings</a> when the first posts are ready.</p><?php endif ?>

    <?= format_help('How to Format Posts', ['box', 'fold', 'image', 'carousel', 'card', 'tabs', 'paywall'],
        'The toolbar above the text inserts most of them for you. The summary is shown on the cards and in link previews.', true) ?>

    <form class="docs-editor blog-editor" data-editor novalidate></form>
    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
