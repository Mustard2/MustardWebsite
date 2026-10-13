<?php
// /docs/ and /docs/<slug>/  Documentation: page list on the left, the page in the middle, "On this page" on the right.
// Content from Admin → Docs. The first page that isn't a draft is the docs home (/docs/).
// Draft pages: only admins see them (marked "Draft"); for everyone else they don't exist (404, not listed, not searched).
require __DIR__ . '/app/_init.php';

$admin = m3d_is_admin($M3D);
$visible = fn(array $p) => $admin || empty($p['draft']);
$docs = m3d_docs();
$pages = array_values(array_filter(m3d_doc_pages(), $visible));
if (!$pages) not_found();

$slug = m3d_get_text('slug');
$home = m3d_docs_home();
if ($slug === $home) m3d_redirect('/docs/', 301);
if ($slug === '') $slug = $home;
$index = null;
foreach ($pages as $i => $p) if ($p['slug'] === $slug) { $index = $i; break; }
if ($index === null) not_found();

$page = $pages[$index];
$isHome = $page['slug'] === $home;
if (!empty($page['draft'])) header('X-Robots-Tag: noindex');
$url = fn(array $p) => $p['slug'] === $home ? '/docs/' : '/docs/' . $p['slug'] . '/';
$body = md_html((string) ($page['body'] ?? ''), ['docs' => true], $toc);
$prev = $pages[$index - 1] ?? null;
$next = $pages[$index + 1] ?? null;

/** Words the sidebar search matches for a page: title, summary and every word of the text (once each, without addresses). */
$keywords = function (array $p): string {
    $text = implode(' ', [$p['title'] ?? '', $p['summary'] ?? '', $p['section'] ?? '', (string) ($p['body'] ?? '')]);
    $text = preg_replace(['/\]\([^)]*\)/', '/https?:\/\/\S+/'], ' ', $text);
    preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}._-]{2,}/u', mb_strtolower($text), $m);
    return implode(' ', array_unique($m[0]));
};

layout_start($isHome ? 'Documentation' : $page['title'] . ' · Docs', (string) ($page['summary'] ?? 'Documentation for the ' . site_name() . ' models.'), 'page-docs');
?>
  <div class="wrap docs">
    <details class="docs-nav" open data-docs-nav>
      <summary><span class="muted small">Documentation</span> <b><?= e($page['title']) ?></b></summary>
      <div class="docs-nav-inner">
        <input class="input docs-search" type="search" placeholder="Search the docs…" aria-label="Search the docs" autocomplete="off" data-docs-search />
        <nav aria-label="Documentation">
          <?php foreach ($docs['sections'] as $sec): if (!array_filter($sec['pages'] ?? [], $visible)) continue; ?>
            <div class="docs-group" data-docs-group>
              <p class="docs-group-title"><?= e($sec['title']) ?></p>
              <ul>
                <?php foreach (array_filter($sec['pages'], $visible) as $p): $p['section'] = $sec['title']; ?>
                  <li data-docs-item data-k="<?= e($keywords($p)) ?>"><a href="<?= e($url($p)) ?>"<?= $p['slug'] === $slug ? ' aria-current="page"' : '' ?>><?= e($p['title']) ?><?= !empty($p['draft']) ? ' <span class="draft-pill">Draft</span>' : '' ?></a></li>
                <?php endforeach ?>
              </ul>
            </div>
          <?php endforeach ?>
          <p class="muted small docs-none" data-docs-none hidden>No page matches.<?php if (m3d_has_faq()): ?> Try the <a href="<?= e(SITE['faqs']) ?>">FAQ</a>.<?php endif ?></p>
        </nav>
        <div class="docs-nav-foot">
          <?php foreach (docs_links() as [$dlLabel, $dlUrl]): ?><?= link_html($dlLabel, $dlUrl) ?><?php endforeach ?>
        </div>
      </div>
    </details>

    <article class="docs-main">
      <nav class="crumbs small" aria-label="Breadcrumb"><a href="/docs/">Docs</a><span aria-hidden="true">/</span><span><?= e($page['section']) ?></span></nav>
      <header class="docs-head">
        <h1><?= e($isHome ? 'Documentation' : $page['title']) ?></h1>
        <?php if (!$isHome && !empty($page['summary'])): ?><p class="lead-sm muted"><?= e($page['summary']) ?></p><?php endif ?>
        <?= admin_edit_button('/admin/docs.php?page=' . rawurlencode($page['slug']), 'Edit Page') ?>
      </header>
      <?php if (!empty($page['draft'])): ?><p class="vis-banner"><?= LOCK_ICON ?><span>Draft: only admins can see this page. <a href="/admin/docs.php?page=<?= e(rawurlencode($page['slug'])) ?>">Change</a></span></p><?php endif ?>

      <div class="md docs-body"><?= $body ?></div>

      <?php if ($isHome): ?>
        <section class="docs-overview" aria-label="All pages">
          <?php foreach ($docs['sections'] as $sec): $list = array_values(array_filter($sec['pages'] ?? [], fn($p) => $p['slug'] !== $home && $visible($p))); if (!$list) continue; ?>
            <h2 class="docs-overview-title"><?= e($sec['title']) ?></h2>
            <div class="docs-cards">
              <?php foreach ($list as $p): ?>
                <a class="docs-card" href="<?= e($url($p)) ?>"><b><?= e($p['title']) ?><?= !empty($p['draft']) ? ' <span class="draft-pill">Draft</span>' : '' ?></b><?php if (!empty($p['summary'])): ?><span><?= e($p['summary']) ?></span><?php endif ?></a>
              <?php endforeach ?>
            </div>
          <?php endforeach ?>
        </section>
      <?php endif ?>

      <nav class="docs-pager" aria-label="Previous and next page">
        <?php if ($prev): ?><a class="prev" href="<?= e($url($prev)) ?>"><span class="muted small">← Previous</span><b><?= e($prev['title']) ?></b></a><?php else: ?><span></span><?php endif ?>
        <?php if ($next): ?><a class="next" href="<?= e($url($next)) ?>"><span class="muted small">Next →</span><b><?= e($next['title']) ?></b></a><?php endif ?>
      </nav>
    </article>

    <?php $toc = array_values(array_filter($toc ?? [], fn($h) => $h['level'] <= 3)); if (count($toc) > 1): ?>
      <aside class="docs-toc" aria-label="On this page">
        <p class="docs-group-title">On This Page</p>
        <ul data-docs-toc>
          <?php foreach ($toc as $h): ?><li class="lvl-<?= (int) $h['level'] ?>"><a href="#<?= e($h['id']) ?>"><?= e($h['text']) ?></a></li><?php endforeach ?>
        </ul>
      </aside>
    <?php endif ?>
  </div>
<?php layout_end();
