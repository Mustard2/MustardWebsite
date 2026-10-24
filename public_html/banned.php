<?php
// /banned/?what=login|download|bugs|comments|renders: where someone banned in Admin → Access lands when they log in or try what they are
// banned from (BAN_KINDS, m3d_ban_url()): what is turned off for them, the creator's reason, how to ask. A Log In ban leaves no login,
// so the id comes from $_SESSION['ban_id'] (auth/callback.php, m3d_patron()). Anyone not banned goes to the homepage.
require __DIR__ . '/app/_init.php';
header('X-Robots-Tag: noindex');

$p = m3d_patron($M3D);
$id = $p ? (string) ($p['id'] ?? '') : (string) ($_SESSION['ban_id'] ?? '');
$bans = $p ? (array) ($p['ban'] ?? []) : m3d_bans($id);
if (!$bans) m3d_redirect('/');
$x = m3d_exception($id);
$reason = trim((string) ($x['reason'] ?? ''));
$what = m3d_get_text('what');
if (!in_array($what, $bans, true)) $what = in_array('login', $bans, true) ? 'login' : $bans[0];

$titles = ['login' => 'You Can’t Log In on This Site', 'download' => 'You Can’t Download on This Site', 'bugs' => 'You Can’t Post Bug Reports',
           'comments' => 'You Can’t Comment on This Site', 'renders' => 'You Can’t Share Renders'];
$words = ['login' => 'logging in', 'download' => 'downloads', 'bugs' => 'bug reports', 'comments' => 'comments', 'renders' => 'sharing renders'];
$off = array_map(fn($k) => $words[$k], $bans);
$list = count($off) > 1 ? implode(', ', array_slice($off, 0, -1)) . ' and ' . end($off) : $off[0];
$text = in_array('login', $bans, true)
    ? 'The creator turned off logging in for your Patreon account, so you can’t download, comment or post anything here. You can still look around.'
    : 'The creator turned off ' . $list . ' for your Patreon account.';
$mail = m3d_support_email();

layout_start($titles[$what], '', 'page-gate');
?>
  <section class="wrap gate ban-gate">
    <span class="ban-ico" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg></span>
    <h1><?= e($titles[$what]) ?></h1>
    <p class="lead"><?= e($text) ?></p>
    <?php if ($reason !== ''): ?><div class="ban-why"><b>Reason</b><?= e($reason) ?></div><?php endif ?>
    <?php if ($mail !== ''): ?><p class="small muted note">If you think this is a mistake, write to <a href="mailto:<?= e($mail) ?>"><?= e($mail) ?></a>.</p>
    <?php elseif (social_url('discord') !== ''): ?><p class="small muted note">If you think this is a mistake, ask in the <a href="<?= e(social_url('discord')) ?>">Discord</a>.</p><?php endif ?>
    <div class="cta"><a class="btn btn-ghost" href="/">Back to the Site</a></div>
  </section>
<?php layout_end();
