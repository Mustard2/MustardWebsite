<?php
// /contacts/  How to reach the site's owner. The support address comes from config.php → 'support_email' (or settings.json).
require __DIR__ . '/app/_init.php';

$support = m3d_support_email();

$channels = [
    [
        'title' => 'Discord',
        'text'  => 'Questions, help with the models and feedback: the community and the team are there.',
        'href'  => social_url('discord'),
        'cta'   => 'Join the Discord',
    ],
    [
        'title' => 'Patreon',
        'text'  => 'Questions about your membership or the patron downloads: send a message on Patreon.',
        'href'  => social_url('patreon'),
        'cta'   => 'Open Patreon',
    ],
    [
        'title' => 'MustardUI Bugs',
        'text'  => 'Found a bug in MustardUI or have a feature idea? Open an issue on GitHub so it can be tracked and fixed.',
        'href'  => SITE['mustarduiIssues'],
        'cta'   => 'Report on GitHub',
    ],
    [
        'title' => 'X',
        'text'  => 'News, previews and new releases.',
        'href'  => social_url('x'),
        'cta'   => 'Follow on X',
    ],
];

$channels = array_values(array_filter($channels, fn($c) => $c['href'] !== '' && ($c['title'] !== 'MustardUI Bugs' || m3d_mustardui_on())));   // networks not set in Settings → Social links are left out; the MustardUI card follows Settings → MustardUI

layout_start('Contacts', 'How to get in touch with ' . site_name() . '.', 'page-contacts');
?>
  <div class="wrap contacts">
    <header>
      <p class="eyebrow">Contacts</p>
      <h1>Get in Touch</h1>
      <?php if (m3d_has_faq()): ?><p class="lead-sm muted">Before writing, have a look at the <a href="<?= e(SITE['faqs']) ?>">FAQ</a>: the most common questions are answered there.</p><?php endif ?>
    </header>

    <div class="contact-grid">
      <?php foreach ($channels as $c): ?>
        <a class="contact-card" href="<?= e($c['href']) ?>" target="_blank" rel="noopener">
          <h2><?= e($c['title']) ?><?php if (!empty($c['badge'])): ?> <span class="tag-pref"><?= e($c['badge']) ?></span><?php endif ?></h2>
          <p><?= e($c['text']) ?></p>
          <span class="contact-cta"><?= e($c['cta']) ?> ↗</span>
        </a>
      <?php endforeach ?>
    </div>

    <?php if ($support !== ''): ?>
    <section class="contact-mail">
      <h2>Website Support</h2>
      <p>Problems with this website only, such as logging in with Patreon or a download link that doesn’t work: <a href="mailto:<?= e($support) ?>"><?= e($support) ?></a></p>
      <p class="muted small">Messages about other topics sent to this address won’t be answered: please use the channels above instead.</p>
    </section>
    <?php endif ?>
  </div>
<?php layout_end();
