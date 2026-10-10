<?php
// /faq/  Frequently asked questions (content from Admin → FAQ).
require __DIR__ . '/app/_init.php';

$faq = m3d_faq();
$sections = array_values(array_filter($faq['sections'] ?? [], fn($s) => !empty($s['items'])));
$total = array_sum(array_map(fn($s) => count($s['items']), $sections));

layout_start('FAQ', 'Answers to common questions about ' . site_name() . ': downloads, membership and using the models.', 'page-faq');
?>
  <div class="wrap faq">
    <header class="faq-head">
      <p class="eyebrow">Help</p>
      <h1>Frequently asked questions</h1>
      <?= admin_edit_button('/admin/faq.php', 'Edit FAQ') ?>
      <?php if (!empty($faq['intro'])): ?><div class="lead md"><?= md_html($faq['intro']) ?></div><?php endif ?>
      <div class="faq-tools">
        <input class="input" type="search" placeholder="Search the <?= $total ?> questions…" aria-label="Search the FAQ" data-faq-search />
      </div>
      <nav class="faq-toc" aria-label="Topics">
        <?php foreach ($sections as $s): ?><a class="chip" href="#<?= e(faq_id($s['title'])) ?>"><?= e($s['title']) ?> <span><?= count($s['items']) ?></span></a><?php endforeach ?>
      </nav>
    </header>

    <?php foreach ($sections as $s): $sid = faq_id($s['title']); ?>
      <section class="faq-sec" id="<?= e($sid) ?>" data-faq-sec>
        <h2><?= e($s['title']) ?></h2>
        <?php foreach ($s['items'] as $it): $id = $sid . '--' . faq_id($it['q']); ?>
          <details class="faq-item" id="<?= e($id) ?>" data-faq-item>
            <summary><span><?= e($it['q']) ?></span><a class="faq-link" href="#<?= e($id) ?>" title="Link to this question" aria-label="Link to this question">#</a></summary>
            <div class="faq-a md"><?= md_html((string) $it['a']) ?></div>
          </details>
        <?php endforeach ?>
      </section>
    <?php endforeach ?>
    <p class="muted faq-empty" data-faq-empty hidden>No questions match. Try other words<?php if (social_url('discord') !== ''): ?>, or ask on <a href="<?= e(social_url('discord')) ?>">Discord</a><?php endif ?>.</p>
  </div>
<?php layout_end();
