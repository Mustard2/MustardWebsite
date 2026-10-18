<?php
// /blog/ and /blog/<slug>/  (rewritten here by .htaccess). Rules and data in app/blog.php.
//   list: page 1 without a search or topic starts with the first post big (a pinned one, else the newest), then cards; ?q= searches
//         (title, summary, topics, and the text of posts this visitor can read), ?topic= one topic, ?page= BLOG_PER_PAGE cards a page.
//   post: drafts and scheduled posts only for admins (banner); a Patrons Only post shows its first paragraph and a lock box to
//         anyone who can't read it (m3d_post_view() = 'teaser').
require __DIR__ . '/app/_init.php';

$admin = m3d_is_admin($M3D);
if (!m3d_blog_on() && !$admin) not_found();
$cfg = m3d_blog_settings();
$offBanner = !m3d_blog_on() ? '<p class="vis-banner">' . LOCK_ICON . '<span>The blog is off: only admins can see it. <a href="/admin/settings.php?tab=features#blog">Turn it on</a></span></p>' : '';

$slug = m3d_get_text('slug');
if ($slug !== '') {
    $p = m3d_post_by_slug($slug);
    $view = $p ? m3d_post_view($p) : 'hidden';
    if ($view === 'hidden') not_found();
    $state = m3d_post_state($p);
    if ($state !== 'live') header('X-Robots-Tag: noindex');
    elseif (($p['cover'] ?? '') !== '' && ($p['visibility'] ?? 'public') === 'public') page_image((string) $p['cover']);   // link previews: the cover of Public posts only
    $path = m3d_post_url($p);
    $sum = trim((string) ($p['summary'] ?? ''));
    // older / newer: by date among the posts this visitor sees (pinned or not)
    $all = m3d_blog_visible();
    usort($all, fn($a, $b) => m3d_post_ts($b) <=> m3d_post_ts($a));
    $at = array_search($p['id'], array_column($all, 'id'), true);
    $newer = $at > 0 ? $all[$at - 1] : null;
    $older = $all[$at + 1] ?? null;
    $items = $view === 'full' ? m3d_post_items($p) : [];
    $who = m3d_patron($M3D);
    $patreon = social_url('patreon');

    $free = m3d_post_free($p);   // null = no "--- Patreon ---" line
    $first = trim(html_entity_decode(strip_tags(md_inline(md_first_text($free ?? (string) ($p['body'] ?? '')))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));   // without the format's marks (**bold**, links…)
    layout_start($p['title'] . ' · ' . $cfg['title'], $sum !== '' ? $sum : ($first ?: $cfg['title'] . ' · ' . site_name()), 'page-blog');
    ?>
  <article class="bl-post">
    <div class="bl-wrap">
      <a class="back" href="/blog/" data-back>← All Posts</a>
      <?= $offBanner ?>
      <?php if ($state === 'draft'): ?><p class="vis-banner"><?= LOCK_ICON ?><span>Draft: only admins can see this post. <a href="/admin/blog.php?post=<?= e($p['id']) ?>">Change</a></span></p><?php endif ?>
      <?php if ($state === 'scheduled'): ?><p class="vis-banner"><?= LOCK_ICON ?><span>Scheduled for <?= when_html(m3d_post_ts($p)) ?>: only admins can see it until then. <a href="/admin/blog.php?post=<?= e($p['id']) ?>">Change</a></span></p><?php endif ?>
      <div class="title-row bl-title">
        <p class="bl-meta"><?= blog_pills($p, false) ?><?php foreach ((array) ($p['topics'] ?? []) as $t) echo blog_topic((string) $t, '/blog/?topic=' . rawurlencode((string) $t)) ?><?= blog_meta($p) ?></p>
        <?= like_button($p['id'], 'posts') ?>
        <h1><?= e($p['title']) ?></h1>
      </div>
      <?php if ($sum !== ''): ?><p class="lead bl-lead-text"><?= e($sum) ?></p><?php endif ?>
      <?= admin_edit_button('/admin/blog.php?post=' . rawurlencode($p['id']), 'Edit Post') ?>
    </div>
    <?php if (($p['cover'] ?? '') !== ''): ?><div class="bl-cover"><?= blog_cover($p, '(max-width: 1112px) 96vw, 1080px', true) ?></div><?php endif ?>
    <div class="bl-wrap">
      <?php if ($view === 'teaser' && $free !== null): // everything above the "--- Patreon ---" line, its end slowly fading ?>
        <?php if (trim($free) !== ''): ?><div class="md docs-body bl-body bl-fade-end"><?= md_html($free, ['docs' => true]) ?></div><?php endif ?>
      <?php elseif ($view === 'teaser'): $first = md_first_text((string) ($p['body'] ?? '')); ?>
        <?php if ($first !== ''): ?><div class="md docs-body bl-body bl-fade"><p><?= md_inline($first) ?></p></div><?php endif ?>
      <?php endif ?>
      <?php if ($view === 'teaser'): ?>
        <div class="bl-lock">
          <span class="bl-lock-ico"><?= BLOG_LOCK_ICON ?></span>
          <h2><?= $free !== null ? 'The Rest Is for Patrons' : 'This Post Is for Patrons' ?></h2>
          <?php if (!$who): ?>
            <p>Log in with Patreon to read the rest (any paid tier).</p>
            <a class="btn btn-primary" rel="nofollow" href="/auth/login.php?return=<?= e(rawurlencode($path)) ?>">Log In with Patreon</a>
            <?php if ($patreon !== ''): ?><p class="muted small">Not a patron yet? <a href="<?= e($patreon) ?>" rel="noopener" target="_blank">See the tiers</a></p><?php endif ?>
          <?php else: ?>
            <p>Patrons on any paid tier can read the rest.</p>
            <?php if ($patreon !== ''): ?><a class="btn btn-primary" href="<?= e($patreon) ?>" rel="noopener" target="_blank">See the Tiers</a><?php endif ?>
          <?php endif ?>
        </div>
      <?php else: ?>
        <div class="md docs-body bl-body"><?= md_html((string) ($p['body'] ?? ''), ['docs' => true, 'paywall' => $admin ? true : 'skip']) ?></div>
        <?php if ($items): ?>
          <section class="bl-items" aria-label="In this post">
            <h2>In This Post</h2>
            <div class="bl-mini">
              <?php foreach ($items as [$kind, $it]): [$src, $hidden] = card_pic($kind, $it);
                $what = $kind === 'outfits' ? m3d_accessory_type($it) : trim('Model · ' . ($it['franchise'] ?? ''), ' ·');
                $ver = m3d_released_label($it); ?>
                <a class="bl-mini-card" href="<?= e(m3d_item_url($kind, (string) $it['slug'])) ?>">
                  <span class="bl-mini-pic<?= $hidden ? ' is-blurred' : '' ?>"><?= card_img($src, (string) $it['name']) ?></span>
                  <span><?= e($what) ?><b><?= e(m3d_model_name($it)) ?></b><?= $ver !== '' ? e(is_numeric($ver) ? 'Version ' . $ver : $ver) : '' ?></span>
                </a>
              <?php endforeach ?>
            </div>
          </section>
        <?php endif ?>
      <?php endif ?>

      <?php if ($older || $newer): ?>
        <nav class="bl-nav" aria-label="Other posts">
          <?php if ($older): ?><a class="prev" href="<?= e(m3d_post_url($older)) ?>"><span class="muted small">← Older</span><b><?= e($older['title']) ?></b></a><?php else: ?><span></span><?php endif ?>
          <?php if ($newer): ?><a class="next" href="<?= e(m3d_post_url($newer)) ?>"><span class="muted small">Newer →</span><b><?= e($newer['title']) ?></b></a><?php endif ?>
        </nav>
      <?php endif ?>

      <?php if ($view === 'full') echo comments_block('posts', $p['id'], $p, 'post') ?>
    </div>
  </article>
<?php
    layout_end();
    exit;
}

// ── The list ─────────────────────────────────────────────────────────────────
$posts = m3d_blog_visible();
$topics = m3d_blog_topics($posts);
$topic = m3d_get_text('topic');
$topicKey = m3d_fold($topic);
$q = mb_substr(trim(m3d_get_text('q')), 0, 100);
$words = array_values(array_filter(preg_split('/\s+/', m3d_fold($q)) ?: []));
$shown = array_values(array_filter($posts, function ($p) use ($topicKey, $words) {
    if ($topicKey !== '' && !in_array($topicKey, array_map(fn($t) => m3d_fold((string) $t), (array) ($p['topics'] ?? [])), true)) return false;
    if (!$words) return true;
    // the text only of posts this visitor can read: a Patrons Only post isn't found by words that only its locked part has
    $hay = m3d_fold(implode(' ', [$p['title'], $p['summary'] ?? '', implode(' ', (array) ($p['topics'] ?? [])), m3d_post_view($p) === 'full' ? (string) ($p['body'] ?? '') : (m3d_post_free($p) ?? '')]));
    foreach ($words as $w) if (!str_contains($hay, $w)) return false;
    return true;
}));
$filtered = $topicKey !== '' || $words;
$lead = !$filtered && $shown ? array_shift($shown) : null;
$pages = max(1, (int) ceil(count($shown) / BLOG_PER_PAGE));
$page = min($pages, max(1, (int) m3d_get_text('page')));
if ($page > 1) $lead = null;   // the big post is on page 1 only
$list = array_slice($shown, ($page - 1) * BLOG_PER_PAGE, BLOG_PER_PAGE);
$url = fn(array $q) => '/blog/' . (($q = http_build_query(array_filter($q, fn($v) => $v !== '' && $v !== 0 && $v !== 1))) !== '' ? '?' . $q : '');
$base = ['q' => $q, 'topic' => $topic];

layout_start($cfg['title'], $cfg['intro'] !== '' ? $cfg['intro'] : $cfg['title'] . ' · ' . site_name(), 'page-blog');
?>
  <div class="wrap bl">
    <?= $offBanner ?>
    <header class="bl-head">
      <h1><?= e($cfg['title']) ?></h1>
      <?php if ($cfg['intro'] !== ''): ?><p class="lead"><?= md_inline($cfg['intro']) ?></p><?php endif ?>
      <?= admin_edit_button('/admin/blog.php', 'Edit Blog') ?>
    </header>

    <?php if ($posts): ?>
      <div class="bl-tools">
        <form class="bl-search" action="/blog/" method="get" role="search">
          <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search the blog…" aria-label="Search the blog" />
          <?php if ($topic !== ''): ?><input type="hidden" name="topic" value="<?= e($topic) ?>" /><?php endif ?>
        </form>
        <?php if (count($topics) > 1 || $topicKey !== ''): ?>
          <nav class="chips" aria-label="Topics">
            <a class="chip" href="<?= e($url(['q' => $q])) ?>" aria-pressed="<?= $topicKey === '' ? 'true' : 'false' ?>">All <span><?= count($posts) ?></span></a>
            <?php foreach ($topics as $t => $n): $on = m3d_fold((string) $t) === $topicKey; ?>
              <a class="chip" href="<?= e($url(['q' => $q, 'topic' => $on ? '' : (string) $t])) ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>"><?= e((string) $t) ?> <span><?= $n ?></span></a>
            <?php endforeach ?>
          </nav>
        <?php endif ?>
      </div>
    <?php endif ?>

    <?php if (!$posts): ?>
      <p class="muted bl-empty">No posts yet.</p>
    <?php elseif (!$lead && !$list): ?>
      <p class="muted bl-empty">No posts found. <a href="/blog/">Show all posts</a></p>
    <?php endif ?>
    <?php if ($filtered && ($lead || $list)): ?>
      <p class="muted small bl-count"><?= m3d_plural(count($shown), 'post') ?><?= $q !== '' ? ' for “' . e($q) . '”' : '' ?></p>
    <?php endif ?>
    <?= $lead ? blog_card($lead, true) : '' ?>
    <?php if ($list): ?><div class="bl-grid"><?php foreach ($list as $p) echo blog_card($p) ?></div><?php endif ?>

    <?php if ($pages > 1): ?>
      <nav class="pager bl-pager" aria-label="Pages">
        <?php $link = fn(string $label, int $n, string $aria) => $n < 1 || $n > $pages
            ? '<span class="chip" aria-disabled="true">' . $label . '</span>'
            : '<a class="chip" href="' . e($url($base + ['page' => $n])) . '" aria-label="' . e($aria) . '"' . ($label === (string) $page ? ' aria-current="page"' : '') . '>' . $label . '</a>';
        echo $link('‹', $page - 1, 'Previous page');
        $last = 0;
        for ($n = 1; $n <= $pages; $n++) {
            if ($n !== 1 && $n !== $pages && abs($n - $page) > 1) continue;
            if ($n - $last > 1) echo '<span class="pager-gap">…</span>';
            echo $link((string) $n, $n, "Page $n");
            $last = $n;
        }
        echo $link('›', $page + 1, 'Next page'); ?>
      </nav>
    <?php endif ?>
  </div>
<?php layout_end();
