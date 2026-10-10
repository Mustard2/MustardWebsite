<?php
// /patreon-required/?reason=…  (the download gate sends people here when they can't download)
//   reason=requirements&type=model|outfit&item=<slug>: a paying patron who doesn't meet the item's extra requirements
require __DIR__ . '/app/_init.php';

$texts = [
    'no-tier'    => ['Your support has no tier', 'Thanks for supporting! Custom amounts without a tier don’t unlock downloads: pick any tier on Patreon, then log in again.'],
    'not-patron' => ['A Patreon tier is needed', 'This Patreon account isn’t a member yet. Join any tier, then log in again.'],
    'declined'   => ['Your last Patreon payment didn’t go through', 'Downloads are paused. Update your payment method on Patreon, then log in again.'],
    'error'      => ['Something went wrong', 'We couldn’t check your membership with Patreon right now. Please try again in a minute.'],
];
$reason = (string) ($_GET['reason'] ?? '');
[$title, $text] = $texts[$reason] ?? ['A Patreon tier is needed', 'Join any tier on Patreon, then log in again.'];
$return = m3d_safe_return($_GET['return'] ?? null, '/#catalog');

// Extra requirements of one model/outfit
$item = $check = null;
if ($reason === 'requirements') {
    $kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
    $slug = (string) ($_GET['item'] ?? '');
    $item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
    if ($item && m3d_can_view($kind, $item)) {
        $check = m3d_check_requirements($item, m3d_patron($M3D));
        $title = 'This download needs a little more';
        $text = 'Thanks for being a patron! ' . $item['name'] . ' has extra requirements your membership doesn’t meet yet' . ($check['mode'] === 'or' ? ' (one of the two is enough):' : ':');
    }
}

// Download limit reached (Admin → Access → Download rules)
$limits = null;
$when = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('l j F, H:i');
if ($reason === 'limit') {
    $kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
    $slug = (string) ($_GET['item'] ?? '');
    $item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
    if ($item && m3d_can_view($kind, $item)) {
        $limits = m3d_check_limits(m3d_patron($M3D), $kind, $slug);
        $blocking = array_values(array_filter($limits['rows'], fn($r) => !$r['ok']));
        $next = $blocking ? max(array_map(fn($r) => (int) $r['next'], $blocking)) : null;
        $title = 'You’ve reached your unlock limit';
        $text = ($next ? 'You can download ' . $item['name'] . ' from ' . $when($next) . ' (Berlin time).' : 'Try again later.');
    }
}

layout_start('Patreon membership needed', 'Downloads are available to Patreon members on any tier.', 'page-gate');
?>
  <section class="wrap gate">
    <p class="eyebrow">Downloads</p>
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e($text) ?></p>
    <?php if ($limits): ?>
      <div class="req-table">
        <table>
          <thead><tr><th>Limit</th><th>Used</th><th>Next free slot</th></tr></thead>
          <tbody>
            <?php foreach ($limits['rows'] as $r): ?>
              <tr class="<?= $r['ok'] ? 'ok' : 'no' ?>"><th><?= e(ucfirst($r['text'])) ?></th><td class="req-used"><?= (int) min($r['used'], $r['max']) ?> of <?= (int) $r['max'] ?></td><td><?= $r['next'] && !$r['ok'] ? e($when((int) $r['next'])) : '—' ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="small muted">Models and accessories you downloaded before are always free to download again.</p>
      <div class="cta">
        <a class="btn btn-primary" href="/account/">See my account</a>
        <a class="btn btn-ghost" href="/#catalog">Browse models</a>
      </div>
    <?php elseif ($check): ?>
      <div class="req-table">
        <table>
          <thead><tr><th></th><th>Needed</th><th>You have</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($check['rows'] as [$label, $need, $have, $ok]): ?>
              <tr class="<?= $ok ? 'ok' : 'no' ?>"><th><?= e($label) ?></th><td><?= e($need) ?></td><td><?= e($have) ?></td><td><?= $ok ? '✓' : '✗' ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="small muted">Values from Patreon at login: log in again if they look out of date.</p>
      <div class="cta">
        <a class="btn btn-primary" href="/account/">See my membership</a>
        <a class="btn btn-ghost" href="/auth/login.php?return=<?= e(rawurlencode($return)) ?>">Check again with Patreon</a>
      </div>
    <?php else: ?>
      <div class="cta">
        <?php if (social_url('patreon') !== ''): ?><a class="btn btn-patreon" href="<?= e(social_url('patreon')) ?>">See the tiers on Patreon</a><?php endif ?>
        <a class="btn btn-ghost" href="/auth/login.php?return=<?= e(rawurlencode($return)) ?>">I've joined, log in again</a>
      </div>
    <?php endif ?>
    <?php if (social_url('discord') !== ''): ?><p class="small muted note">Still stuck? Ask in the <a href="<?= e(social_url('discord')) ?>">Discord</a> and include the name of your Patreon account.</p><?php endif ?>
  </section>
<?php layout_end();
