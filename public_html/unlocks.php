<?php
// /unlocks/  "How Unlocks Work" (rewritten here by .htaccess): the unlock limits of Admin → Access → Downloads explained to anyone,
// above all visitors who aren't patrons yet (the guests' Download box links here, "How It Works"; My account's Unlock Limits too).
// A few facts from the live settings (periods in use, "Updates Use an Unlock", the daily cap, how long the log remembers a
// download), then the grid of m3d_unlock_grid(): the paid tiers as columns, months as a patron as rows. A logged-in patron's tier
// column is lit, and the cell of their months marked. Only while a rule is on (m3d_unlocks_on()): else 404, except for admins.
require __DIR__ . '/app/_init.php';

$admin = m3d_is_admin($M3D);
$grid = m3d_unlock_grid();
if (!$grid && !$admin) not_found();

$cfg = m3d_dl_settings();
$p = m3d_patron($M3D);
$canDl = $p && !empty($p['access']) && !m3d_banned($p, 'download');
$owner = ($p['reason'] ?? '') === 'owner';
$limitsMode = (string) ($p['exception']['limits'] ?? '');   // Admin → Access: bypass = no limits, custom = their own rules
$exempt = $owner || $limitsMode === 'bypass';
$counts = $canDl && !$exempt && $limitsMode !== 'custom' ? m3d_patron_counts($p) : null;
$cols = $grid['cols'] ?? [];
$myCol = null;   // the column to light: their tier (one column = everyone's)
if ($counts) $myCol = count($cols) === 1 ? array_key_first($cols) : (isset($cols[(int) ($counts['tier'][1] ?? 0)]) ? (int) $counts['tier'][1] : null);
$myMonths = (int) ($counts['months'] ?? 0);
$tierCols = count($cols) > 1;
$monthRows = count($grid['rows'] ?? []) > 1;

// the facts: only the ones the settings make true
$pers = [];
foreach ($cfg['rules'] as $r) if (!empty($r['on'])) $pers[m3d_rule_period($r)[0]] = m3d_rule_period($r)[1];
ksort($pers);
$perText = count($pers) > 1 ? implode(', ', array_slice($pers, 0, -1)) . ' or ' . end($pers) : (string) reset($pers);
$keep = max(1, (int) ($M3D['log_retention_days'] ?? 365));
$keepText = $keep % 365 === 0 ? m3d_plural(intdiv($keep, 365) * 12, 'month') : m3d_plural($keep, 'day');
$hasReq = false;
foreach (['models', 'outfits'] as $k) foreach (m3d_listed_items($k) as $it) if (m3d_has_requirements($it) && m3d_release_phase($it) !== 'free') { $hasReq = true; break 2; }
$facts = [
    ['Used unlocks return after ' . $perText . '.', (count($pers) > 1 ? 'Each limit has its own time (see the table), counted from the day you used the unlock, not by the calendar. '
        : 'Counted from the day you used the unlock, not by the calendar. ') . match (array_key_last($pers)) {
        2592000 => 'One used on 5 October is back on 4 November.',
        604800  => 'One used on a Monday is back the next Monday.',
        default => 'One used at 9:00 is back at 9:00 the next day.',
    }],
    $cfg['updates']
        ? ['New versions use an unlock.', 'Downloading a newer version of a model you have uses one unlock. The same version again is free.']
        : ['Models you have are free.', 'Downloading one again, or a new version of it, uses no unlock. If your last download of it is over ' . $keepText . ' old, it counts as new.'],
];
// six boxes (Pi): the ones the settings make true first, then ones that always hold
$public = false;   // a free download somewhere: no login, no unlock
foreach (['models', 'outfits'] as $k) foreach (m3d_listed_items($k) as $it) foreach ((array) ($it['links'] ?? []) as $l)
    if (is_array($l) && empty($l['off']) && m3d_link_access($it, $l) === 'public') { $public = true; break 3; }
if ($monthRows) $facts[] = ['Months count from your pledge.', 'From the day your Patreon membership started. Changing tier keeps them; cancelling or dropping to free starts over.'];
$facts[] = ['Every status counts the same.', 'Preview on Patreon or Patreon Only: each new model you download as a patron uses one unlock.'];
if ($public) $facts[] = ['Free downloads use no unlock.', 'A model with a free download needs no login and no unlock.'];
if ($cfg['cap']) $facts[] = ['At most ' . m3d_plural($cfg['cap'], 'download') . ' in 24 hours.', 'Downloading again counts too.'];
if ($hasReq) $facts[] = ['Some models ask for more.', 'A higher tier or more months: their Download box says so.'];
if (m3d_free_items()) $facts[] = ['Some downloads are free.', 'A few models and accessories never use an unlock, even when you have none left.'];
$facts[] = ['See what you have left.', 'Logged in, the number next to your name shows your unlocks left. My Account lists each limit.'];
$facts[] = ['A paid tier is needed.', 'Free members and custom amounts without a tier can’t download. Any paid tier can.'];
$facts[] = ['The Download box tells you.', 'Before a download, it says whether it uses an unlock and how many you have left.'];
$facts = array_slice($facts, 0, 6);
$lead = 'Every paid tier on Patreon downloads the models. The first download of a model or accessory uses one unlock'
    . match (true) {
        $tierCols && $monthRows => '; how many you get depends on your tier and how long you’ve been a patron.',
        $tierCols               => '; how many you get depends on your tier.',
        $monthRows              => '; how many you get depends on how long you’ve been a patron.',
        default                 => '.',
    };

$ico = ['models' => '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>', 'outfits' => ACCESSORY_ICON_GENERIC];
// a cell: one line per rule (models, then accessories, then both), "No limit" for a kind no rule counts
$cell = function (array $rules) use ($ico): string {
    $svg = fn(string $k) => '<svg viewBox="0 0 24 24">' . $ico[$k] . '</svg>';
    $line = fn(string $icons, string $text, string $what) => '<span class="unl-k"><span class="unl-ic" aria-hidden="true">' . $icons . '</span><span>' . $text . ' <small>' . $what . '</small></span></span>';
    $h = '';
    foreach (['models' => 'models', 'outfits' => 'accessories'] as $k => $word) {
        $own = array_filter($rules, fn($r) => $r['what'] === $k);
        foreach ($own as $r) $h .= $line($svg($k), $r['max'] . ' / ' . e($r['per']), $word);
        if (!$own && !array_filter($rules, fn($r) => $r['what'] === 'all')) $h .= $line($svg($k), '<span class="unl-free">No limit</span>', $word);
    }
    foreach ($rules as $r) if ($r['what'] === 'all') $h .= $line($svg('models') . $svg('outfits'), $r['max'] . ' / ' . e($r['per']), 'models and accessories');
    return $h;
};
$colName = fn(int $c) => $c === 0 ? 'Every Paid Tier' : (($cols[$c] ?? '') !== '' ? $cols[$c] : m3d_money($c) . ' Tier');

$join = social_url('patreon');
layout_start('How Unlocks Work', 'How downloads and unlocks work for patrons of ' . site_name() . ', tier by tier.');
?>
  <div class="unl wrap">
    <p class="eyebrow">Downloads</p>
    <h1>How Unlocks Work</h1>
    <?php if (!$grid): ?>
      <p class="notice">No unlock limit is on, so visitors get the “page not found” here. Turn one on in <a href="/admin/users.php">Admin → Access</a>.</p>
    <?php else: ?>
    <p class="unl-lead"><?= e($lead) ?></p>
    <?php if ($canDl && $exempt): ?><p class="unl-you ok-text">✓ These limits don’t apply to you<?= $owner ? ' (admin)' : '' ?>.</p>
    <?php elseif ($canDl && $limitsMode === 'custom'): ?><p class="unl-you">Your own limits apply: <a href="/account/#limits">see My Account</a>.</p>
    <?php endif ?>

    <ul class="unl-facts">
      <?php foreach ($facts as [$t, $x]): ?><li><b><?= e($t) ?></b><?= e($x) ?></li><?php endforeach ?>
    </ul>

    <h2><?= $tierCols ? 'Unlocks by Tier' : 'Unlocks' ?></h2>
    <div class="unl-scroll">
      <table class="unl-table">
        <thead><tr><th><?= $monthRows ? 'Time as a Patron' : '' ?></th>
          <?php foreach ($cols as $c => $n): ?>
            <th<?= $c === $myCol ? ' class="is-mine"' : '' ?>><b><?= e($colName($c)) ?><?php if ($c === $myCol && $tierCols): ?> <span class="wl-you">Your Tier</span><?php endif ?></b><?= $c ? e(m3d_money($c)) . ' / month' : '' ?></th>
          <?php endforeach ?>
        </tr></thead>
        <tbody>
        <?php foreach ($grid['rows'] as $r): $here = $myCol !== null && $myMonths >= $r['from'] && ($r['to'] === null || $myMonths < $r['to']); ?>
          <tr><th scope="row"><?= e($r['label']) ?><?php if ($here && !$tierCols): ?> <span class="wl-you">You</span><?php endif ?></th>
          <?php foreach ($cols as $c => $n): ?>
            <td<?= $c === $myCol ? ' class="is-mine' . ($here ? ' is-you' : '') . '"' : '' ?> data-k="<?= e($colName($c) . ($c ? ' · ' . m3d_money($c) : '')) ?>"><?= $cell($r['cells'][$c]) ?></td>
          <?php endforeach ?>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <div class="unl-end">
      <?php if ($canDl): ?>
        <a class="btn btn-primary" href="/account/#limits">My Account</a>
      <?php else: ?>
        <?php if ($join !== ''): ?><a class="btn btn-primary" href="<?= e($join) ?>" target="_blank" rel="noopener">Join on Patreon ↗</a><?php endif ?>
        <?php if (!$p): ?><a class="btn btn-ghost" rel="nofollow" href="/auth/login.php?return=%2Funlocks%2F">Already a Patron? Log In</a><?php endif ?>
      <?php endif ?>
      <?php if ($grid): ?><span class="muted unl-note">Limits can change.</span><?php endif ?>
    </div>
  </div>
<?php layout_end();
