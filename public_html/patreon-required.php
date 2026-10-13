<?php
// /patreon-required/?reason=…  (the download gate sends people here when they can't download)
//   reason=requirements&type=model|outfit&item=<slug>: a paying patron who doesn't meet the item's extra requirements
require __DIR__ . '/app/_init.php';

$texts = [
    'no-tier'    => ['Your Support Has No Tier', 'Thanks for supporting! Custom amounts without a tier don’t unlock downloads: pick any tier on Patreon, then log in again.'],
    'not-patron' => ['A Patreon Tier Is Needed', 'This Patreon account isn’t a member yet. Join any tier, then log in again.'],
    'declined'   => ['Your Last Patreon Payment Didn’t Go Through', 'Downloads are paused. Update your payment method on Patreon, then log in again.'],
    'error'      => ['Something Went Wrong', 'We couldn’t check your membership with Patreon right now. Please try again in a minute.'],
];
$reason = m3d_get_text('reason');
[$title, $text] = $texts[$reason] ?? ['A Patreon Tier Is Needed', 'Join any tier on Patreon, then log in again.'];
$return = m3d_safe_return($_GET['return'] ?? null, '/#catalog');

// Extra requirements of one model/outfit
$item = $check = null;
if ($reason === 'requirements') {
    $kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
    $slug = m3d_get_text('item');
    $item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
    if ($item && m3d_can_view($kind, $item)) {
        $check = m3d_check_requirements($item, m3d_patron($M3D));
        $title = 'This Download Needs a Little More';
        $text = 'Thanks for being a patron! ' . $item['name'] . ' has extra requirements your membership doesn’t meet yet' . ($check['mode'] === 'or' ? (m3d_requirements($item)['tier_cents'] ? ' (for time and total support, one of the two is enough):' : ' (one of the two is enough):') : ':');
    }
}

// Download limit reached (Admin → Access → Download rules)
$limits = null;
$when = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('l j F, H:i T');
if ($reason === 'limit') {
    $kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
    $slug = m3d_get_text('item');
    $item = preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? m3d_item($kind, $slug) : null;
    if ($item && m3d_can_view($kind, $item)) {
        $limits = m3d_check_limits(m3d_patron($M3D), $kind, $slug);
        // the rule that frees up last decides when the next unlock comes (every full rule must free up)
        $blocking = array_values(array_filter($limits['rows'], fn($r) => !$r['ok']));
        usort($blocking, fn($a, $b) => ($b['next'] ?? 0) <=> ($a['next'] ?? 0));
        $o = $blocking[0] ?? null;
        $what = $o['rule']['what'] ?? 'models';
        $title = 'You’ve Used All Your ' . ($what === 'all' ? '' : ucfirst(DL_WHAT[$what][0] ?? 'model') . ' ') . 'Unlocks';
        $text = $o && $o['next'] ? 'You can download ' . $item['name'] . ' from ' . $when((int) $o['next']) . '.' : 'Try again later.';
        if ($limits['ok']) {   // opened again later: an unlock freed up, or the rules changed
            $title = 'You Can Download This Now';
            $text = $item['name'] . ' can be downloaded again.';
        }
    }
}

layout_start('Patreon Membership Needed', 'Downloads are available to Patreon members on any tier.', 'page-gate');
?>
  <section class="wrap gate">
    <p class="eyebrow">Downloads</p>
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= e($text) ?></p>
    <?php if ($limits): ?>
      <div class="cta">
        <?php if ($limits['ok']): ?><a class="btn btn-primary" href="<?= e(m3d_item_url($kind, $slug)) ?>">Go to <?= e($item['name']) ?></a><?php endif ?>
        <a class="btn <?= $limits['ok'] ? 'btn-ghost' : 'btn-primary' ?>" href="/account/">See My Account</a>
        <a class="btn btn-ghost" href="/#catalog">Browse Models</a>
      </div>
    <?php elseif ($check): ?>
      <div class="req-table">
        <table>
          <thead><tr><th></th><th>Needed</th><th>You Have</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($check['rows'] as [$label, $need, $have, $ok]): ?>
              <tr class="<?= $ok ? 'ok' : 'no' ?>"><th><?= e($label) ?></th><td><?= e($need) ?></td><td><?= e($have) ?></td><td><?= $ok ? '✓' : '✗' ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
      <p class="small muted">Values from Patreon at login: log in again if they look out of date.</p>
      <div class="cta">
        <?php if (!$check['tier_ok'] && social_url('patreon') !== ''): ?><a class="btn btn-patreon" href="<?= e(social_url('patreon')) ?>" rel="noopener" target="_blank">Upgrade on Patreon ↗</a><?php endif ?>
        <a class="btn btn-primary" href="/account/">See My Membership</a>
        <a class="btn btn-ghost" rel="nofollow" href="/auth/login.php?return=<?= e(rawurlencode($return)) ?>">Check Again with Patreon</a>
      </div>
    <?php else: ?>
      <div class="cta">
        <?php if (social_url('patreon') !== ''): ?><a class="btn btn-patreon" href="<?= e(social_url('patreon')) ?>">See the Tiers on Patreon</a><?php endif ?>
        <a class="btn btn-ghost" rel="nofollow" href="/auth/login.php?return=<?= e(rawurlencode($return)) ?>">I've Joined, Log In Again</a>
      </div>
    <?php endif ?>
    <?php if (social_url('discord') !== ''): ?><p class="small muted note">Still stuck? Ask in the <a href="<?= e(social_url('discord')) ?>">Discord</a> and include the name of your Patreon account.</p><?php endif ?>
  </section>
<?php layout_end();
