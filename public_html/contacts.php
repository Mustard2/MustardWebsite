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
        'title' => 'X',
        'text'  => 'News, previews and new releases.',
        'href'  => social_url('x'),
        'cta'   => 'Follow on X',
    ],
];

// a card per listed tool with "Card on the Contacts Page" and a place for its bugs (Admin → Tools → Options), after Patreon
$toolCards = array_map(fn($t) => ['title' => $t['name'] . ' Bugs', 'text' => 'Found a bug in ' . $t['name'] . ' or have a feature idea? Report it there so it can be tracked and fixed.',
    'href' => $t['issues'], 'cta' => 'Report It'], array_filter(m3d_tools_listed('contacts'), fn($t) => $t['issues'] !== ''));
array_splice($channels, 2, 0, $toolCards);
$channels = array_values(array_filter($channels, fn($c) => $c['href'] !== ''));   // networks not set in Settings → Social links are left out

layout_start('Contacts', 'How to get in touch with ' . site_name() . '.', 'page-contacts');
?>
  <div class="wrap contacts">
    <header>
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

    <?php if (m3d_bugs_on()): ?>
    <section class="contact-mail">
      <h2>Website Problems</h2>
      <p>Technical problems with this website, such as logging in with Patreon or a download link that doesn’t work: <a href="/bugs/">report them in Bug Reports</a>.</p>
    </section>
    <?php endif ?>

    <?php if ($support !== ''): ?>
    <section class="contact-mail">
      <h2>Legal Matters</h2>
      <p>Legal matters about the content of this website only, such as copyright or privacy requests: <a href="mailto:<?= e($support) ?>"><?= e($support) ?></a></p>
      <p class="muted small">Messages about other topics sent to this address won’t be answered: please use the channels above instead.</p>
    </section>
    <?php endif ?>
  </div>
<?php layout_end();
