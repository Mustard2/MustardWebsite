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

// the model/outfit the refusal is about (requirements, limit)
$kind = ($_GET['type'] ?? '') === 'outfit' ? 'outfits' : 'models';
$slug = m3d_get_text('item');
$item = in_array($reason, ['requirements', 'limit'], true) ? m3d_item($kind, $slug) : null;
if ($item && !m3d_can_view($kind, $item)) $item = null;   // drafts, locked password items

// Extra requirements of one model/outfit
$check = null;
$reqMet = false;   // opened again later with the requirements met since (a new tier, Check Again)
if ($reason === 'requirements') {
    if ($item) {
        $check = m3d_check_requirements($item, m3d_patron($M3D));
        $title = 'This Download Needs a Little More';
        $text = 'Thanks for being a patron! ' . $item['name'] . ' has extra requirements your membership doesn’t meet yet' . ($check['mode'] === 'or' ? (m3d_requirements($item)['tier_cents'] ? ' (for time and total support, one of the two is enough):' : ' (one of the two is enough):') : ':');
        $reqMet = $check['ok'] && $check['rows'] && !empty(m3d_patron($M3D)['access']);
        if ($reqMet) {
            $title = 'You Can Download This Now';
            $text = $item['name'] . ' can be downloaded: your membership meets its requirements.';
        }
    }
}

// Download limit reached (Admin → Access → Download rules)
$limits = null;
$when = fn(int $t) => when_mark($t, 'long');   // in the visitor's own time (when_text() below)
if ($reason === 'limit') {
    if ($item) {
        $limits = m3d_check_limits(m3d_patron($M3D), $kind, $slug);
        $o = m3d_blocking_row($limits['rows']);   // it says when the next unlock comes
        $title = 'You’ve Used All Your ' . ucfirst($o ? m3d_rule_noun($o['rule']) : 'model ') . 'Unlocks';
        $text = $o && $o['next'] ? 'You can download ' . $item['name'] . ' from ' . $when((int) $o['next']) . '.' : 'Try again later.';
        if ($limits['ok']) {   // opened again later: an unlock freed up, or the rules changed
            $title = 'You Can Download This Now';
            $text = $item['name'] . ' can be downloaded again.';
        }
    }
}

// Daily download cap reached (Admin → Access → Daily Download Cap)
$cap = null;
if ($reason === 'cap') {
    $p = m3d_patron($M3D);
    $until = m3d_dl_capped($p);
    $cap = ['until' => $until];
    if ($until !== null) {
        $title = 'You’ve Reached the Daily Download Limit';
        $text = 'Each patron can download ' . m3d_plural(m3d_dl_settings()['cap'], 'time') . ' in 24 hours. You can download again from ' . $when($until) . '.';
    } else {
        $title = 'You Can Download Again';
        $text = 'Your downloads are open again.';
    }
}

layout_start($title, 'Downloads are available to Patreon members on any tier.', 'page-gate');   // the tab says what the page says (limits, the cap…)
?>
  <section class="wrap gate">
    <p class="eyebrow">Downloads</p>
    <h1><?= e($title) ?></h1>
    <p class="lead"><?= when_text($text) ?></p>
    <?php if ($cap): ?>
      <div class="cta">
        <?php if ($cap['until'] === null): ?><a class="btn btn-primary" href="<?= e($return) ?>">Back to the Download</a><?php endif ?>
        <a class="btn <?= $cap['until'] === null ? 'btn-ghost' : 'btn-primary' ?>" href="/account/">See My Account</a>
        <a class="btn btn-ghost" href="/#catalog">Browse Models</a>
      </div>
    <?php elseif ($limits): ?>
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
        <?php if ($reqMet): ?><a class="btn btn-primary" href="<?= e(m3d_item_url($kind, $slug)) ?>">Go to <?= e($item['name']) ?></a><?php endif ?>
        <?php if (!$check['tier_ok'] && social_url('patreon') !== ''): ?><a class="btn btn-patreon" href="<?= e(social_url('patreon')) ?>" rel="noopener" target="_blank">Upgrade on Patreon ↗</a><?php endif ?>
        <a class="btn <?= $reqMet ? 'btn-ghost' : 'btn-primary' ?>" href="/account/">See My Membership</a>
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
