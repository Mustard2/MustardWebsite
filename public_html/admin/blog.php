<?php
// Blog editor: the posts on the left, the open post on the right (like Docs); built by admin.js initBlogEditor. Each post is
// saved on its own (save.php kind "blog", with the post's revision). Rules and data in app/blog.php.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$items = [];   // what "Models and Accessories in This Post" can pick (drafts too: they show on the post once listed)
foreach (['models', 'outfits'] as $kind) foreach (m3d_items($kind) as $it) {
    $pic = item_pic($kind, $it);
    $items[] = ['ref' => $kind . ':' . $it['slug'], 'name' => $kind === 'models' ? m3d_model_name($it) : (string) $it['name'],
        'sub' => $kind === 'outfits' ? m3d_accessory_type($it) : trim('Model · ' . ($it['franchise'] ?? ''), ' ·'), 'pic' => str_starts_with($pic, '/media/') ? img_thumb($pic) : ''];
}
$payload = ['kind' => 'blog', 'posts' => admin_blog_posts(), 'post' => m3d_get_text('post'), 'items' => $items,
    'discord' => m3d_discord_on(), 'discordUrl' => settings_url('discord'), 'comments' => m3d_comments_on('posts'), 'commentsUrl' => settings_url('comments'), 'now' => m3d_site_time(time(), 'Y-m-d\TH:i'), 'tz' => m3d_site_time(time(), 'T')];

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

    <?= format_help('How to Format Posts', ['para', 'inline', 'heading', 'list', 'steps', 'box', 'table', 'code', 'image', 'tabs', 'paywall'],
        'The summary is shown on the cards and in link previews. Site pages can be linked as <code>[Lena](/models/lena/)</code>. A <code>--- Patreon ---</code> line keeps the rest of the post for patrons, in any post. The toolbar above the text inserts most of these for you.', true) ?>

    <form class="docs-editor blog-editor" data-editor novalidate></form>
    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
