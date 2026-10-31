<?php
// /tools/, /tools/<slug>/ and /tools/<slug>/<tutorial>/  Tool pages (app/tools.php): the list of tools, a tool's page (header, tutorials by
// group with the "Start Here" one, features, help links) and a tutorial (its video, text, the tool's tutorials on the left, Previous / Next).
// /pages/<slug>/ and /pages/<slug>/<tutorial>/  Simple pages: a title, a text, the same tutorials (no /pages/ list).
// Drafts (pages and tutorials) only for admins, with a banner; for everyone else they don't exist.
require __DIR__ . '/app/_init.php';

$admin = m3d_is_admin($M3D);
$type = isset($_GET['page']) ? 'page' : 'tool';
$tools = array_values(array_filter(m3d_tools()['tools'], fn($t) => m3d_tool_visible($t) && m3d_page_type($t) === $type));
$slug = m3d_get_text($type === 'page' ? 'page' : 'tool');
$tutSlug = m3d_get_text('tut');
$draftBanner = fn(string $what, string $href) => '<p class="vis-banner">' . LOCK_ICON . '<span>Draft: only admins can see this ' . $what . '. <a href="' . e($href) . '">Change</a></span></p>';

// ── /tools/: every tool ──
if ($slug === '') {
    if (!$tools || $type === 'page') not_found();
    layout_start('Tools', 'Tools and add-ons by ' . site_name() . ', with tutorials.', 'page-tools');
    ?>
  <div class="wrap tools-page">
    <div class="section-head"><div><h1 class="h2">Tools</h1></div></div>
    <div class="tl-list">
      <?php foreach ($tools as $t): $n = count(m3d_tool_tuts($t, $admin)); ?>
        <a class="tl-card" href="<?= e(m3d_tool_url($t)) ?>">
          <?php if (($t['logo'] ?? '') !== ''): ?><img class="tl-card-logo" src="<?= e(img_small($t['logo'])) ?>" alt="" loading="lazy" /><?php endif ?>
          <span>
            <b><?= e($t['name']) ?><?= !empty($t['draft']) ? ' <span class="draft-pill">Draft</span>' : '' ?></b>
            <?php if (($t['intro'] ?? '') !== ''): ?><span class="muted"><?= e(meta_text((string) $t['intro'], '', 140)) ?></span><?php endif ?>
            <?php if ($n): ?><span class="small muted"><?= m3d_plural($n, 'tutorial') ?></span><?php endif ?>
          </span>
        </a>
      <?php endforeach ?>
    </div>
  </div>
<?php
    layout_end();
    exit;
}

$tool = null;
foreach ($tools as $t) if ($t['slug'] === $slug) $tool = $t;
if (!$tool) not_found();
if (!empty($tool['draft'])) header('X-Robots-Tag: noindex');
$tuts = m3d_tool_tuts($tool, $admin);
$editTool = '/admin/tool.php?id=' . rawurlencode((string) $tool['id']);
$logo = (string) ($tool['logo'] ?? '');

// ── /tools/<slug>/<tutorial>/ ──
if ($tutSlug !== '') {
    $i = null;
    foreach ($tuts as $k => $t) if ($t['slug'] === $tutSlug) $i = $k;
    if ($i === null) not_found();
    $tut = $tuts[$i];
    if (!empty($tut['draft'])) header('X-Robots-Tag: noindex');
    $prev = $tuts[$i - 1] ?? null;
    $next = $tuts[$i + 1] ?? null;
    $video = m3d_tut_video($tut);
    $pic = m3d_tut_pic($tut);
    if ($pic !== '' && empty($tool['draft']) && empty($tut['draft'])) page_image($pic);
    $body = md_html((string) ($tut['body'] ?? ''), ['docs' => true], $toc);
    $GLOBALS['m3d_page_card'] = preview_box((string) $tut['title'], abs_url(m3d_tool_url($tool, $tut['slug'])), discord_md_text($tool['name'] . ' · Tutorial'),
        discord_md_text((string) ($tut['summary'] ?? '')), '', 'Read the Tutorial');
    layout_start($tut['title'] . ' · ' . $tool['name'], (string) ($tut['summary'] ?? '') ?: $tool['name'] . ' tutorial: ' . $tut['title'] . '.', 'page-tools');
    ?>
  <div class="wrap tut">
    <aside class="tut-nav">
      <a class="tut-tool" href="<?= e(m3d_tool_url($tool)) ?>">
        <?php if ($logo !== ''): ?><img src="<?= e(img_small($logo)) ?>" alt="" /><?php endif ?>
        <span><b><?= e($tool['name']) ?></b><span class="small muted"><?= m3d_plural(count($tuts), 'tutorial') ?></span></span>
      </a>
      <?php $n = 0; $group = null; foreach ($tuts as $k => $t): ?>
        <?php if ($t['group'] !== $group): ?><?= $group !== null ? '</ol>' : '' ?><?php $group = $t['group']; ?><?php if ($group !== ''): ?><p class="tut-group"><?= e($group) ?></p><?php endif ?><ol><?php endif ?>
        <li><a href="<?= e(m3d_tool_url($tool, $t['slug'])) ?>"<?= $k === $i ? ' aria-current="page"' : '' ?>><span class="tut-n"><?= ++$n ?></span><span><?= e($t['title']) ?><?= !empty($t['draft']) ? ' <span class="draft-pill">Draft</span>' : '' ?></span></a></li>
      <?php endforeach ?>
      <?= $group !== null ? '</ol>' : '' ?>
    </aside>
    <article class="tut-main">
      <nav class="crumbs small" aria-label="Breadcrumb"><a href="<?= e(m3d_tool_url($tool)) ?>"><?= e($tool['name']) ?></a><span aria-hidden="true">/</span><span><?= e($tut['group'] !== '' ? $tut['group'] : 'Tutorials') ?></span></nav>
      <h1 class="tut-title"><?= e($tut['title']) ?></h1>
      <p class="tut-meta"><?= tool_level_pill((string) ($tut['level'] ?? '')) ?><span class="muted"><?= e(m3d_tut_kind($tut)) ?></span></p>
      <?php if (!empty($tut['draft'])): ?><?= $draftBanner('tutorial', '/admin/tool-tutorials.php?id=' . rawurlencode((string) $tool['id']) . '&tut=' . rawurlencode((string) $tut['id'])) ?><?php elseif (!empty($tool['draft'])): ?><?= $draftBanner('tool', $editTool) ?><?php endif ?>
      <?php if (($tut['summary'] ?? '') !== ''): ?><p class="lead-sm muted tut-lead"><?= e($tut['summary']) ?></p><?php endif ?>
      <?php if ($video && $video['local']): ?>
        <div class="md tut-video"><?= md_video($video['url'], '') ?></div>
      <?php elseif ($video): $host = preg_replace('/^www\./', '', (string) parse_url($video['url'], PHP_URL_HOST)); ?>
        <a class="tut-ext" href="<?= e($video['url']) ?>" target="_blank" rel="noopener"><?= tool_tut_cover($tool, $tut, '(max-width: 960px) 100vw, 860px') ?><span class="tut-ext-go">Watch on <?= e($host) ?> ↗</span></a>
      <?php endif ?>
      <div class="md docs-body tut-body"><?= $body ?></div>
      <nav class="docs-pager" aria-label="Previous and next tutorial">
        <?php if ($prev): ?><a class="prev" href="<?= e(m3d_tool_url($tool, $prev['slug'])) ?>"><span class="muted small">← Previous</span><b><?= e($prev['title']) ?></b></a><?php else: ?><span></span><?php endif ?>
        <?php if ($next): ?><a class="next" href="<?= e(m3d_tool_url($tool, $next['slug'])) ?>"><span class="muted small">Next →</span><b><?= e($next['title']) ?></b></a><?php endif ?>
      </nav>
    </article>
  </div>
<?php
    echo admin_edit_button('/admin/tool-tutorials.php?id=' . rawurlencode((string) $tool['id']) . '&tut=' . rawurlencode((string) $tut['id']), 'Edit Tutorial');
    layout_end();
    exit;
}

// ── /tools/<slug>/: the tool's page; /pages/<slug>/: a simple page (its title, its text, then the same tutorials) ──
$isTool = $type === 'tool';
$shot = $isTool ? (string) ($tool['shot'] ?? '') : '';
if ($shot !== '' && empty($tool['draft'])) page_image($shot);
$buttons = $isTool ? (array) ($tool['buttons'] ?? []) : [];
$features = $isTool ? (array) ($tool['features'] ?? []) : [];
$help = $isTool ? (array) ($tool['help'] ?? []) : [];
$start = null;
foreach ($tuts as $t) if (!empty($t['start'])) { $start = $t; break; }
$groups = [];
foreach ((array) ($tool['groups'] ?? []) as $g) {
    $in = array_values(array_filter((array) ($g['tuts'] ?? []), fn($t) => $admin || empty($t['draft'])));
    if ($in) $groups[] = ['title' => (string) ($g['title'] ?? ''), 'text' => (string) ($g['text'] ?? ''), 'tuts' => $in];
}
$link = fn(string $url) => preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener"' : '';
$GLOBALS['m3d_page_card'] = preview_box((string) $tool['name'], abs_url(m3d_tool_url($tool)), '',
    discord_md_text(meta_text((string) ($tool['intro'] ?? ''), '', 300)), '', 'View');

layout_start($tool['name'], meta_text((string) ($tool['intro'] ?? ''), $tool['name'] . ' by ' . site_name() . '.'), 'page-tools');
?>
  <div class="wrap tool-page">
    <?php if (!empty($tool['draft'])): ?><?= $draftBanner('page', $editTool) ?><?php endif ?>
    <?php if (!$isTool): ?>
      <h1 class="pg-title"><?= e($tool['name']) ?></h1>
      <?php if (($tool['intro'] ?? '') !== ''): ?><div class="md docs-body pg-text"><?= md_html((string) $tool['intro'], ['docs' => true]) ?></div><?php endif ?>
    <?php else: ?>
    <section class="tool-hero<?= $shot === '' ? ' no-shot' : '' ?>">
      <div>
        <div class="tool-title">
          <?php if ($logo !== ''): ?><img class="tool-logo" src="<?= e($logo) ?>" alt="" decoding="async" /><?php endif ?>
          <div>
            <h1 class="h2"><?= e($tool['name']) ?></h1>
          </div>
        </div>
        <?php if (($tool['intro'] ?? '') !== ''): ?><div class="md tool-intro"><?= md_html((string) $tool['intro'], ['noImages' => true]) ?></div><?php endif ?>
        <?php if ($buttons): ?>
          <div class="hero-cta">
            <?php foreach ($buttons as $k => $b): ?><a class="btn <?= $k ? 'btn-ghost' : 'btn-primary' ?>" href="<?= e($b['url']) ?>"<?= $link((string) $b['url']) ?>><?= e($b['label']) ?></a><?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
      <?php if ($shot !== ''): ?>
        <div class="tool-shot" data-slides="<?= slides_attr([['src' => $shot, 'caption' => $tool['name']]]) ?>">
          <button type="button" data-slide="0" aria-label="Enlarge the screenshot"><img src="<?= e($shot) ?>" alt="<?= e($tool['name']) ?>" decoding="async" /></button>
        </div>
      <?php endif ?>
    </section>
    <?php endif ?>

    <?php if ($groups): $n = 0; ?>
      <section class="section" id="tutorials">
        <div class="section-head"><h2 class="h2">Tutorials</h2><span class="muted small"><?= m3d_plural(count($tuts), 'tutorial') ?></span></div>
        <?php if ($start): ?>
          <a class="tut-start" href="<?= e(m3d_tool_url($tool, $start['slug'])) ?>">
            <?= tool_tut_cover($tool, $start, '(max-width: 860px) 100vw, 640px') ?>
            <span><span class="eyebrow">Start Here</span><b><?= e($start['title']) ?></b><?php if (($start['summary'] ?? '') !== ''): ?><span class="muted"><?= e($start['summary']) ?></span><?php endif ?>
            <span class="tut-row-meta"><?= tool_level_pill((string) ($start['level'] ?? '')) ?><span class="muted small"><?= e(m3d_tut_kind($start)) ?></span></span></span>
          </a>
        <?php endif ?>
        <div class="tut-groups">
          <?php foreach ($groups as $g): ?>
            <div class="tut-box">
              <?php if ($g['title'] !== ''): ?><h3><?= e($g['title']) ?></h3><?php endif ?>
              <?php if ($g['text'] !== ''): ?><p class="muted"><?= e($g['text']) ?></p><?php endif ?>
              <?php foreach ($g['tuts'] as $t): ?>
                <a class="tut-row" href="<?= e(m3d_tool_url($tool, $t['slug'])) ?>">
                  <span class="tut-n"><?= ++$n ?></span><?= tool_tut_cover($tool, $t, '96px') ?>
                  <span><b><?= e($t['title']) ?><?= !empty($t['draft']) ? ' <span class="draft-pill">Draft</span>' : '' ?></b><span class="tut-row-meta"><?= tool_level_pill((string) ($t['level'] ?? '')) ?><span class="muted small"><?= e(explode(' · ', m3d_tut_kind($t))[0]) ?></span></span></span>
                </a>
              <?php endforeach ?>
            </div>
          <?php endforeach ?>
        </div>
      </section>
    <?php endif ?>

    <?php if ($features): ?>
      <section class="section">
        <div class="section-head"><h2 class="h2">Features</h2></div>
        <ul class="tool-features">
          <?php foreach ($features as $f): ?><li><b><?= e($f['title']) ?></b><?php if (($f['text'] ?? '') !== ''): ?><span class="muted"><?= e($f['text']) ?></span><?php endif ?></li><?php endforeach ?>
        </ul>
      </section>
    <?php endif ?>

    <?php if ($help): ?>
      <section class="section" id="help">
        <div class="section-head"><h2 class="h2">Guides and Help</h2></div>
        <div class="contact-grid">
          <?php foreach ($help as $h): ?>
            <a class="contact-card" href="<?= e($h['url']) ?>"<?= $link((string) $h['url']) ?>>
              <h3><?= e($h['title']) ?></h3>
              <?php if (($h['text'] ?? '') !== ''): ?><p><?= e($h['text']) ?></p><?php endif ?>
              <span class="contact-cta"><?= tool_help_cta($h) ?></span>
            </a>
          <?php endforeach ?>
        </div>
      </section>
    <?php endif ?>
  </div>
<?php
echo admin_edit_button($editTool, 'Edit Page');
layout_end();
