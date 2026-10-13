<?php
// Admin home: what happened lately (downloads from the log), what needs a look, and shortcuts. Read only: every box links
// to the page where things are changed. The models list is admin/models.php.
require __DIR__ . '/_admin.php';
require_once __DIR__ . '/_backup.php';
require_once __DIR__ . '/_github.php';
admin_require($M3D);

const HOME_DAYS = 30;   // the chart
const HOME_TOP = 5;     // most downloaded this week
const HOME_RECENT = 6;  // latest downloads

$tz = new DateTimeZone('Europe/Berlin');
$now = time();
$today = new DateTimeImmutable('today', $tz);
$days = [];
for ($i = HOME_DAYS - 1; $i >= 0; $i--) $days[$today->modify("-$i days")->format('Y-m-d')] = 0;
// "last 7 days" = 7 calendar days (German time) with today, the same range as the tile's link and Downloads → Last 7 days
$weekFrom = $today->modify('-6 days')->getTimestamp();
$prevFrom = $today->modify('-13 days')->getTimestamp();

// one pass over the download log (patron downloads; public links are only counted all-time in stats.json)
$first = [];               // patron id => first download in the log
$week = $prevWeek = 0;
$top = [];                 // "kind:slug" => downloads in the last 7 days
$recent = [];              // the newest rows
$logOn = !empty($M3D['log_downloads']);
if (($fh = @fopen(m3d_log_file(), 'rb'))) {
    while (($line = fgets($fh)) !== false) {
        while (substr_count($line, '"') % 2 && ($more = fgets($fh)) !== false) $line .= $more;   // a quoted field with a line break
        $r = str_getcsv(rtrim($line, "\r\n"), ',', '"', '');
        if (!isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
        $id = (string) $r[1];
        if (!isset($first[$id]) || $t < $first[$id]) $first[$id] = $t;
        $day = (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
        if (isset($days[$day])) $days[$day]++;
        [$k, $s] = str_starts_with($r[3], 'outfit:') ? ['outfits', substr($r[3], 7)] : ['models', $r[3]];
        if ($t >= $weekFrom) { $week++; $top["$k:$s"] = ($top["$k:$s"] ?? 0) + 1; }
        elseif ($t >= $prevFrom) $prevWeek++;
        $recent[] = [$t, $id, (string) $r[2], $k, $s, (string) ($r[5] ?? '')];
        if (count($recent) > HOME_RECENT * 4) $recent = array_slice($recent, -HOME_RECENT);
    }
    fclose($fh);
}
usort($recent, fn($a, $b) => $b[0] <=> $a[0]);
$recent = array_slice($recent, 0, HOME_RECENT);
arsort($top);
$top = array_slice($top, 0, HOME_TOP, true);
$newPatrons = count(array_filter($first, fn($t) => $t >= $weekFrom));
$dToday = end($days);
$dYesterday = prev($days);

$item = function (string $k, string $s): array {   // [name, picture, kind label, item or null]
    $it = m3d_item($k, $s);
    if (!$it) return [$s, '', $k === 'outfits' ? 'Accessory · Removed' : 'Model · Removed', null];
    return [(string) $it['name'], item_pic($k, $it), $k === 'outfits' ? m3d_accessory_type($it) : 'Model', $it];
};

// ── Needs a look: problems (warn), things waiting (info), then the backup (ok, or warn when old) ──
$todo = [];
if (m3d_bugs_on() && ($n = m3d_bugs_unseen())) $todo[] = ['warn', m3d_plural($n, 'bug report') . ' you haven’t seen', '/bugs/?status=unseen', 'Bugs'];
$disc = m3d_discord();
if (m3d_discord_on() && $disc['last'] && empty($disc['last']['ok'])) {
    $todo[] = ['warn', 'Last Discord post failed' . (($w = (string) ($disc['last']['what'] ?? '')) !== '' ? " ($w)" : ''), settings_url('discord'), 'Settings'];
}
if ($dead = lc_dead()) {   // from the last time the admin pressed "Check all links" (never automatic)
    $names = array_values(array_unique(array_column($dead, 'name')));
    $todo[] = ['warn', m3d_plural(count($dead), 'download link is', 'download links are') . ' dead: ' . implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? '…' : ''), '/admin/links.php', 'Link Check'];
}
if ($np = count(m3d_problems($now - 7 * 86400))) $todo[] = ['warn', m3d_plural($np, 'site problem') . ' in the last 7 days', settings_url('problems'), 'Settings'];
$upCh = gh_saved()['channel'];   // the last check of the update channel: never waits for GitHub (gh_check_cached())
if (($upCheck = gh_check_cached($upCh)) && ($upNew = gh_newer($upCheck))) {
    $todo[] = ['info', (count($upNew) === 1 ? 'Update available: ' : count($upNew) . ' updates available, newest ') . $upNew[0]['v'], '/admin/update.php', 'Update'];
}
foreach (['models' => '/admin/models.php', 'outfits' => '/admin/outfits.php'] as $k => $href) {
    $drafts = array_values(array_filter(m3d_items($k), fn($it) => m3d_visibility($it) === 'draft'));
    if (!$drafts) continue;
    $names = array_map(fn($it) => (string) $it['name'], array_slice($drafts, 0, 3));
    $todo[] = ['info', m3d_plural(count($drafts), $k === 'outfits' ? 'accessory draft' : 'model draft', $k === 'outfits' ? 'accessory drafts' : 'model drafts')
        . ' waiting: ' . implode(', ', $names) . (count($drafts) > 3 ? '…' : ''), $href, 'Assets'];
}
if (($nt = m3d_notice()) && ($until = (string) ($nt['until'] ?? '')) !== '') {
    $left = (int) $today->diff(new DateTimeImmutable($until, $tz))->format('%r%a');
    if ($left <= 2) $todo[] = ['info', 'The site notice ends ' . ($left <= 0 ? 'today' : ($left === 1 ? 'tomorrow' : 'in 2 days')), settings_url('notice'), 'Settings'];
}
$newest = bk_newest_full();   // an uploaded backup counts from the day it was made
$todo[] = $newest
    ? [$newest > $now - 8 * 86400 ? 'ok' : 'warn', 'Last full backup ' . bug_ago($newest), '/admin/backups.php', 'Backups']
    : ['warn', 'No full backup yet', '/admin/backups.php', 'Backups'];

$ico = [
    'dl'   => '<path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14"/>',
    'week' => '<rect x="4" y="5.5" width="16" height="14" rx="2.5"/><path d="M4 10h16M8.5 3.5v4M15.5 3.5v4"/>',
    'new'  => '<circle cx="10" cy="8.2" r="3.8"/><path d="M3.5 19.5c.7-3.6 3.4-5.6 6.5-5.6 1.4 0 2.6.3 3.7 1M18 13.5v6M15 16.5h6"/>',
    'bug'  => '<rect x="7.5" y="8" width="9" height="12" rx="4.5"/><path d="M9.5 8a2.5 2.5 0 0 1 5 0M12 12v8M7.5 13H4M20 13h-3.5M5 8.5l2.8 1.6M19 8.5l-2.8 1.6M5 18.5l2.8-1.6M19 18.5l-2.8-1.6"/>',
    'all'  => '<path d="M4 19.5h16M7 16V11M12 16V6M17 16v-3"/>',
    'like' => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.2 4.2 0 0 1 12 7.3a4.2 4.2 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
];
$icon = fn(string $k) => '<span class="stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' . $ico[$k] . '</svg></span>';
$diff = function (int $now, int $before, string $what): string {   // the small line under a tile: the change, with the number it compares to
    if ($before === 0) return '<small class="flat">' . ($now ? 'no downloads ' . $what : 'none ' . $what . ' either') . '</small>';
    if ($now === $before) return '<small class="flat">same as ' . $what . '</small>';
    $up = $now > $before;
    $txt = $what === 'yesterday' ? abs($now - $before) . ($up ? ' more' : ' fewer') . " than yesterday ($before)"
        : round(abs($now - $before) / $before * 100) . '% ' . ($up ? 'more' : 'fewer') . " than $what ($before)";
    return '<small class="' . ($up ? 'up' : 'down') . '">' . ($up ? '↑ ' : '↓ ') . e($txt) . '</small>';
};
// likes: new ones in the same 7 days (counted per day since this was added: likes.json → daily), and every like there is now
[$newLikes] = m3d_likes_new_since($today->modify('-6 days')->format('Y-m-d'));
$allLikes = 0;
foreach (['models', 'outfits', 'stills'] as $k) foreach (m3d_likes($k) as $codes) $allLikes += count((array) $codes);
$likesNote = number_format($allLikes) . ' ' . ($allLikes === 1 ? 'like' : 'likes') . ' in all';
$maxDay = max(1, max($days));
$fmtDay = fn(string $d) => (new DateTimeImmutable($d, $tz))->format('D j M');

admin_start('Home', 'home');
?>
    <div class="admin-head">
      <div>
        <h1>Home</h1>
        <p class="muted"><?= e($today->format('l j F')) ?> · <a class="hm-ver" href="/admin/update.php" title="Install an update">site version <?= e(M3D_VERSION) ?></a></p>
      </div>
      <div class="row-btns">
        <a class="btn btn-ghost btn-sm" href="/admin/edit.php">+ New Model</a>
        <a class="btn btn-ghost btn-sm" href="/admin/edit.php?kind=outfits">+ New Accessory</a>
        <a class="btn btn-ghost btn-sm" href="/admin/gallery.php">+ Gallery Pictures</a>
      </div>
    </div>
    <?php if (!$logOn): ?><p class="notice">The download log is off (config <code>log_downloads</code>), so the download numbers below stay at zero.</p><?php endif ?>

    <div class="stats hm-stats">
      <a class="stat stat-i stat-dl" href="/admin/log.php?from=<?= e($today->format('Y-m-d')) ?>#log"><?= $icon('dl') ?><b><?= number_format($dToday) ?></b><span>Downloads Today</span><?= $diff($dToday, $dYesterday, 'yesterday') ?></a>
      <a class="stat stat-i stat-pat" href="/admin/log.php?from=<?= e($today->modify('-6 days')->format('Y-m-d')) ?>#log"><?= $icon('week') ?><b><?= number_format($week) ?></b><span>Last 7 Days</span><?= $diff($week, $prevWeek, 'the 7 days before') ?></a>
      <a class="stat stat-i stat-new" href="/admin/log.php#log" title="Patrons whose first download in the log was in the last 7 days"><?= $icon('new') ?><b><?= number_format($newPatrons) ?></b><span>New Patrons This Week</span><small class="flat">their first download</small></a>
      <?php if (m3d_bugs_on()): $unseen = m3d_bugs_unseen(); ?>
      <a class="stat stat-i stat-bug" href="/bugs/<?= $unseen ? '?status=unseen' : '' ?>"><?= $icon('bug') ?><b><?= $unseen ?></b><span>Bug Reports Not Seen</span><small class="flat"><?= e(m3d_plural(count(array_filter(m3d_bugs(), fn($b) => ($b['status'] ?? '') !== 'fixed' && ($b['status'] ?? '') !== 'closed')), 'open report')) ?></small></a>
      <?php else: $all = 0; foreach (['models', 'outfits'] as $k) foreach (m3d_items($k) as $it) $all += m3d_downloads($k, (string) $it['slug'])['total']; ?><?php /* like Admin → Downloads: the models and accessories on the site (no Gallery pictures, no removed items) */ ?>
      <a class="stat stat-i stat-free" href="/admin/log.php"><?= $icon('all') ?><b><?= number_format($all) ?></b><span>Downloads All Time</span><small class="flat">patrons and free links</small></a>
      <?php endif ?>
      <a class="stat stat-i stat-likes" href="/admin/log.php?tsort=likes-desc" title="Likes given in the last 7 days on models, accessories and gallery pictures"><?= $icon('like') ?><b><?= number_format($newLikes) ?></b><span>New Likes This Week</span><small class="flat"><?= e($likesNote) ?></small></a>
    </div>

    <div class="hm-grid">
      <div class="hm-col">
        <section class="sec hm-chart-box">
          <h2>Downloads, Last <?= HOME_DAYS ?> Days <a href="/admin/log.php">Downloads →</a></h2>
          <div class="hm-chart" role="img" aria-label="<?= e(number_format(array_sum($days)) . ' patron downloads in the last ' . HOME_DAYS . ' days') ?>">
            <?php foreach ($days as $d => $n): ?><i class="<?= $d === array_key_last($days) ? 'today' : '' ?>" style="height:<?= max(2, round($n / $maxDay * 100)) ?>%" title="<?= e($fmtDay($d) . ': ' . m3d_plural($n, 'download')) ?>"></i><?php endforeach ?>
          </div>
          <div class="hm-axis"><span><?= e($today->modify('-' . (HOME_DAYS - 1) . ' days')->format('j M')) ?></span><span><?= e($today->modify('-' . intdiv(HOME_DAYS, 2) . ' days')->format('j M')) ?></span><span>Today</span></div>
        </section>
        <section class="sec hm-recent-box">
          <h2>Latest Downloads <a href="/admin/log.php#log">Patron Log →</a></h2>
          <?php if (!$recent): ?><p class="muted">No downloads yet.</p><?php else: ?>
          <table class="hm-recent">
            <?php foreach ($recent as [$t, $id, $who, $k, $s, $ver]): $dt = (new DateTimeImmutable('@' . $t))->setTimezone($tz); [$name] = $item($k, $s); ?>
            <tr>
              <td class="t" title="<?= e($dt->format('D j M Y, H:i T')) ?>"><?= e($dt->format('Y-m-d') === $today->format('Y-m-d') ? $dt->format('H:i') : $dt->format('j M')) ?></td>
              <td class="w"><a href="/admin/log.php?patron=<?= e(rawurlencode($id)) ?>#log"><?= e($who !== '' ? $who : $id) ?></a></td>
              <td class="i"><b><?= e($name) ?></b></td>
              <td class="v"><?= e($ver) ?></td>
            </tr>
            <?php endforeach ?>
          </table>
          <?php endif ?>
        </section>
      </div>
      <div class="hm-col">
        <section class="sec hm-todo-box">
          <h2>Needs a Look</h2>
          <ul class="hm-todo">
            <?php foreach ($todo as [$tone, $text, $href, $go]): ?>
            <li class="<?= $tone ?>"><a href="<?= e($href) ?>"><span class="dot" aria-hidden="true"></span><span class="txt"><?= e($text) ?></span><span class="go"><?= e($go) ?> →</span></a></li>
            <?php endforeach ?>
          </ul>
        </section>
        <section class="sec hm-top-box">
          <h2>Most Downloaded This Week</h2>
          <?php if (!$top): ?><p class="muted">No downloads in the last 7 days.</p><?php else: $best = max($top); ?>
          <ul class="hm-top">
            <?php foreach ($top as $key => $n): [$k, $s] = explode(':', $key, 2); [$name, $pic, $kindLabel, $it] = $item($k, $s); ?>
            <li>
              <?php if ($pic !== ''): ?><img src="<?= e(img_thumb($pic)) ?>" alt="" loading="lazy" /><?php else: ?><span class="no-img"></span><?php endif ?>
              <span class="nm"><?= $it ? '<a href="/admin/edit.php?' . ($k === 'outfits' ? 'kind=outfits&amp;' : '') . 'slug=' . e(rawurlencode($s)) . '">' . e($name) . '</a>' : e($name) ?><span class="k"><?= e($kindLabel) ?></span><span class="bar" style="width:<?= round($n / $best * 100) ?>%"></span></span>
              <b class="n"><?= $n ?></b>
            </li>
            <?php endforeach ?>
          </ul>
          <?php endif ?>
        </section>
      </div>
    </div>
<?php admin_end();
