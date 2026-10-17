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
const HOME_MORE = 20;   // the most rows a list shows when its widget is made taller (Edit Layout); the rows past the usual number are class "more"
const HOME_UPTAKE = 30; // Update Uptake: versions out in the last 30 days
// the widgets: id => [title, tile | box, default width (columns of 12), what it shows (the Add Widget window)]
const HOME_WIDGETS = [
    'dl-today'    => ['Downloads Today', 'tile', 4, 'Patron downloads today, compared with yesterday.'],
    'dl-week'     => ['Downloads, Last 7 Days', 'tile', 4, 'Patron downloads in the last 7 days, compared with the 7 before.'],
    'new-patrons' => ['New Patrons This Week', 'tile', 4, 'Patrons whose first download was in the last 7 days.'],
    'bugs'        => ['Bug Reports Not Seen', 'tile', 4, 'Bug reports you haven’t opened yet, and the open ones.'],
    'dl-all'      => ['Downloads All Time', 'tile', 4, 'Every download of the models and accessories, patrons and free links.'],
    'likes-week'  => ['New Likes This Week', 'tile', 4, 'Likes given in the last 7 days, and all likes.'],
    'logins-week' => ['Logins, Last 7 Days', 'tile', 4, 'Logins with Patreon in the last 7 days, compared with the 7 before.'],
    'dl-chart'    => ['Downloads, Last 30 Days', 'box', 8, 'A bar a day of patron downloads.', 1.5],
    'login-chart' => ['Logins, Last 30 Days', 'box', 8, 'A bar a day of logins, split by whether the patron can download.', 1.5],
    'recent'      => ['Latest Downloads', 'box', 8, 'The newest lines of the patron log.', 2.5],
    'todo'        => ['Needs a Look', 'box', 4, 'Unseen bugs, dead links, site problems, updates, drafts, backups.', 1.5],
    'top'         => ['Most Downloaded This Week', 'box', 4, 'The models and accessories downloaded most in the last 7 days.', 2.5],
    'liked'       => ['Most Liked', 'box', 4, 'The models and accessories with the most likes.', 2.5],
    'coming'      => ['Coming Up', 'box', 4, 'Release dates and versions dated in the future, soonest first.', 2.5],
    'uptake'      => ['Update Uptake', 'box', 6, 'For each version out in the last 30 days: how many of the patrons who downloaded the item have it.', 2.5],
    'bugs-week'   => ['Bugs This Week', 'tile', 4, 'Bug reports opened in the last 7 days, and how many were fixed or closed.'],
    'stills'      => ['Gallery Downloads', 'tile', 4, 'Full size downloads of Gallery pictures, all time.'],
];
const HOME_SPANS = ['tile' => [3, 4, 6], 'box' => [4, 6, 8, 12]];   // ¼ ⅓ ½ ⅔ Full
const HOME_TALL = 10;      // a box's height is 1.5–10 tiles (HOME_LOW–HOME_TALL), in half steps: n tiles of HOME_UNIT px and the gaps between them, so it lines up with
const HOME_LOW = 1.5;      // tiles stacked beside it; less than 1.5 leaves no room for a list under its title. Every box has one (the default is the 5th value in HOME_WIDGETS)
const HOME_UNIT = 120;     // a tile's height, px (admin.css --hm-unit)

/** A widget's place: its width (columns of 12, one of HOME_SPANS), its first column (1–12, so it ends by column 12; 0 = wherever it fits best)
 *  and, boxes only, its height in tiles (HOME_LOW–HOME_TALL in half steps; none = its default; an older value in px becomes the nearest half tile). */
function home_place(string $id, mixed $span, mixed $col, mixed $h = 0): array
{
    $span = (int) $span;
    if (!in_array($span, HOME_SPANS[HOME_WIDGETS[$id][1]], true)) $span = HOME_WIDGETS[$id][2];
    $col = (int) $col;
    if (HOME_WIDGETS[$id][1] !== 'box') $h = 0;
    else {
        $h = is_numeric($h) ? (float) $h : 0.0;
        if ($h > HOME_TALL) $h = ($h + 16) / (HOME_UNIT + 16);
        $h = $h < 1 ? HOME_WIDGETS[$id][4] : max(HOME_LOW, min(HOME_TALL, round($h * 2) / 2));
    }
    return [$id, $span, $col < 1 ? 0 : min($col, 13 - $span), $h == (int) $h ? (int) $h : $h];
}

/** The widgets shown, in order: [[id, columns, first column]…] from settings.json → home, else the default (Bug Reports while bug reports are on, else Downloads All Time). */
function home_layout(): array
{
    $saved = m3d_settings()['home'] ?? null;
    if (!is_array($saved)) {
        $ids = ['dl-today', 'dl-week', 'new-patrons', m3d_bugs_on() ? 'bugs' : 'dl-all', 'likes-week', 'logins-week', 'dl-chart', 'todo', 'login-chart', 'top', 'recent'];
        return array_map(fn($id) => home_place($id, HOME_WIDGETS[$id][2], 0), $ids);
    }
    $out = [];
    foreach ($saved as $r) {
        if (!is_array($r) || !isset(HOME_WIDGETS[$id = (string) ($r[0] ?? '')])) continue;
        $out[] = home_place($id, $r[1] ?? 0, $r[2] ?? 0, $r[3] ?? 0);
    }
    return $out;
}

// the layout of the widgets ("Edit Layout"): settings.json → home: [[widget id, columns of 12, first column, height in tiles]…] in order (no first column = where
// it fits; tiles have no height); missing = the default
if (admin_form_post('home-layout')) {
    $rows = json_decode((string) ($_POST['layout'] ?? ''), true);
    $keep = [];
    foreach (is_array($rows) ? $rows : [] as $r) {
        $id = is_array($r) && is_string($r[0] ?? null) ? $r[0] : '';
        if (!isset(HOME_WIDGETS[$id]) || isset($keep[$id])) continue;
        $keep[$id] = home_place($id, $r[1] ?? 0, $r[2] ?? 0, $r[3] ?? 0);
    }
    // a widget that can't show now (Bug Reports while bug reports are off) keeps its place for when it can
    if (!m3d_bugs_on()) foreach (home_layout() as $i => $r) if ($r[0] === 'bugs' && !isset($keep['bugs'])) $keep = array_slice($keep, 0, $i, true) + ['bugs' => $r] + $keep;
    m3d_save_settings(['home' => array_map(fn($r) => $r[3] ? $r : ($r[2] ? array_slice($r, 0, 3) : [$r[0], $r[1]]), array_values($keep))]) ? admin_flash('✓ Layout saved') : admin_flash(e(m3d_write_error()), 'err');
}
if (admin_form_post('home-reset')) {
    m3d_save_settings(['home' => null]) ? admin_flash('✓ Back to the default layout') : admin_flash(e(m3d_write_error()), 'err');
}

$tz = m3d_tz();
$now = time();
$today = new DateTimeImmutable('today', $tz);
$days = [];
for ($i = HOME_DAYS - 1; $i >= 0; $i--) $days[$today->modify("-$i days")->format('Y-m-d')] = 0;
// "last 7 days" = 7 calendar days (site time) with today, the same range as the tile's link and Downloads → Last 7 days
$weekFrom = $today->modify('-6 days')->getTimestamp();
$prevFrom = $today->modify('-13 days')->getTimestamp();

// one pass over the download log (patron downloads; public links are only counted all-time in stats.json)
$first = [];               // patron id => first download in the log
$week = $prevWeek = 0;
$top = [];                 // "kind:slug" => downloads in the last 7 days
$recent = [];              // the newest rows
$got = [];                 // "kind:slug" => [patron id => the version of their last download] (Update Uptake)
$logOn = !empty($M3D['log_downloads']);
if (($fh = @fopen(m3d_log_file(), 'rb'))) {
    while (($line = fgets($fh)) !== false) {
        $r = m3d_log_cols($line);
        if (!isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
        $id = (string) $r[1];
        if (!isset($first[$id]) || $t < $first[$id]) $first[$id] = $t;
        $day = (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('Y-m-d');
        if (isset($days[$day])) $days[$day]++;
        [$k, $s] = m3d_log_item_parts($r[3]);
        if ($t >= $weekFrom) { $week++; $top["$k:$s"] = ($top["$k:$s"] ?? 0) + 1; }
        elseif ($t >= $prevFrom) $prevWeek++;
        $recent[] = [$t, $id, (string) $r[2], $k, $s, (string) ($r[5] ?? '')];
        $got["$k:$s"][$id] = (string) ($r[5] ?? '');   // the log is in time order: the last line wins
        if (count($recent) > HOME_MORE * 4) $recent = array_slice($recent, -HOME_MORE);
    }
    fclose($fh);
}
usort($recent, fn($a, $b) => $b[0] <=> $a[0]);
$recent = array_slice($recent, 0, HOME_MORE);
arsort($top);
$top = array_slice($top, 0, HOME_MORE, true);
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
    'login' => '<path d="M10 4.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2H10M14 8l4 4-4 4M18 12H9"/>',
    'pic'  => '<rect x="4" y="5" width="16" height="14" rx="2.5"/><circle cx="9" cy="10" r="1.6"/><path d="m4.5 17 4.5-4.5 3 3 2.5-2.5 5 5"/>',
    'like' => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.2 4.2 0 0 1 12 7.3a4.2 4.2 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
];
$icon = fn(string $k) => '<span class="stat-ico"><svg viewBox="0 0 24 24" aria-hidden="true">' . $ico[$k] . '</svg></span>';
$diff = function (int $now, int $before, string $what, string $noun = 'downloads'): string {   // the small line under a tile: the change, with the number it compares to
    if ($before === 0) return '<small class="flat">' . ($now ? "no $noun $what" : 'none ' . $what . ' either') . '</small>';
    if ($now === $before) return '<small class="flat">same as ' . $what . '</small>';
    $up = $now > $before;
    // today: the plain difference; the 7 days: a % only against 20 or more (against a handful it says nothing: "16100% more than (1)")
    $txt = ($what === 'yesterday' || $before < 20 ? abs($now - $before) : round(abs($now - $before) / $before * 100) . '%') . ($up ? ' more' : ' fewer') . " than $what ($before)";
    return '<small class="' . ($up ? 'up' : 'down') . '">' . ($up ? '↑ ' : '↓ ') . e($txt) . '</small>';
};
// likes: new ones in the same 7 days (counted per day since this was added: likes.json → daily), and every like there is now
[$newLikes] = m3d_likes_new_since($today->modify('-6 days')->format('Y-m-d'));
// only items and pictures that still exist, like the Likes tile of Downloads (models and accessories) it links to; Gallery likes are named apart
$itemLikes = $stillLikes = 0;
foreach (['models', 'outfits'] as $k) foreach (m3d_likes($k) as $slug => $codes) if (m3d_item($k, (string) $slug)) $itemLikes += count((array) $codes);
foreach (m3d_likes('stills') as $id => $codes) if (m3d_still((string) $id)) $stillLikes += count((array) $codes);
$allLikes = $itemLikes + $stillLikes;
$likesNote = number_format($allLikes) . ' ' . ($allLikes === 1 ? 'like' : 'likes') . ' in all' . ($stillLikes ? ', ' . number_format($stillLikes) . ' on Gallery pictures' : '');
$maxDay = max(1, max($days));
// Patreon logins per day (counted since this was added: data/logins.json, numbers only; owners aren't counted): [can download, refused]
[$loginDays, $loginSince] = m3d_logins_daily();
$logins = [];
foreach ($days as $d => $_) $logins[$d] = $loginDays[$d] ?? [0, 0];
$loginWeek = $loginPrev = 0;
foreach ($loginDays as $d => [$ok, $no]) {
    if ($d >= $today->modify('-6 days')->format('Y-m-d')) $loginWeek += $ok + $no;
    elseif ($d >= $today->modify('-13 days')->format('Y-m-d')) $loginPrev += $ok + $no;
}
$loginNo = array_sum(array_column($logins, 1));
$maxLogin = max(1, ...array_map('array_sum', array_values($logins)));
$fmtDay = fn(string $d) => (new DateTimeImmutable($d, $tz))->format('D j M');

$todayLink = e($today->format('Y-m-d'));
$weekLink = e($today->modify('-6 days')->format('Y-m-d'));
// each widget's content (only the ones that can show: Bug Reports needs bug reports on)
$w = [];
$w['dl-today'] = '<a class="stat stat-i stat-dl" href="/admin/log.php?from=' . $todayLink . '#log">' . $icon('dl') . '<b>' . number_format($dToday) . '</b><span>Downloads Today</span>' . $diff($dToday, $dYesterday, 'yesterday') . '</a>';
$w['dl-week'] = '<a class="stat stat-i stat-pat" href="/admin/log.php?from=' . $weekLink . '#log">' . $icon('week') . '<b>' . number_format($week) . '</b><span>Downloads, Last 7 Days</span>' . $diff($week, $prevWeek, 'the 7 days before') . '</a>';
$w['new-patrons'] = '<a class="stat stat-i stat-new" href="/admin/log.php#log" title="Patrons whose first download in the log was in the last 7 days">' . $icon('new') . '<b>' . number_format($newPatrons) . '</b><span>New Patrons This Week</span><small class="flat">their first download</small></a>';
if (m3d_bugs_on()) {
    $unseen = m3d_bugs_unseen();
    $open = count(array_filter(m3d_bugs(), fn($b) => ($b['status'] ?? '') !== 'fixed' && ($b['status'] ?? '') !== 'closed'));
    $w['bugs'] = '<a class="stat stat-i stat-bug" href="/bugs/' . ($unseen ? '?status=unseen' : '') . '">' . $icon('bug') . '<b>' . $unseen . '</b><span>Bug Reports Not Seen</span><small class="flat">' . e(m3d_plural($open, 'open report')) . '</small></a>';
}
// like Admin → Downloads: the models and accessories on the site (no Gallery pictures, no removed items)
$all = 0;
foreach (['models', 'outfits'] as $k) foreach (m3d_items($k) as $it) $all += m3d_downloads($k, (string) $it['slug'])['total'];
$w['dl-all'] = '<a class="stat stat-i stat-free" href="/admin/log.php">' . $icon('all') . '<b>' . number_format($all) . '</b><span>Downloads All Time</span><small class="flat">patrons and free links</small></a>';
$w['likes-week'] = '<a class="stat stat-i stat-likes" href="/admin/log.php?tsort=likes-desc" title="Likes given in the last 7 days on models, accessories and gallery pictures">' . $icon('like') . '<b>' . number_format($newLikes) . '</b><span>New Likes This Week</span><small class="flat">' . e($likesNote) . '</small></a>';
$w['logins-week'] = '<div class="stat stat-i stat-login" title="Logins with Patreon (yours aren’t counted)">' . $icon('login') . '<b>' . number_format($loginWeek) . '</b><span>Logins, Last 7 Days</span>' . ($loginSince === '' ? '<small class="flat">counted from the next login</small>' : $diff($loginWeek, $loginPrev, 'the 7 days before', 'logins')) . '</div>';

ob_start(); ?>
        <section class="sec hm-chart-box">
          <h2>Downloads, Last <?= HOME_DAYS ?> Days <a href="/admin/log.php">Downloads →</a></h2>
          <div class="hm-chart" role="img" aria-label="<?= e(number_format(array_sum($days)) . ' patron downloads in the last ' . HOME_DAYS . ' days') ?>">
            <?php foreach ($days as $d => $n): ?><i class="<?= $d === array_key_last($days) ? 'today' : '' ?>" style="height:<?= max(2, round($n / $maxDay * 100)) ?>%" title="<?= e($fmtDay($d) . ': ' . m3d_plural($n, 'download')) ?>"></i><?php endforeach ?>
          </div>
          <div class="hm-axis"><span><?= e($today->modify('-' . (HOME_DAYS - 1) . ' days')->format('j M')) ?></span><span><?= e($today->modify('-' . intdiv(HOME_DAYS, 2) . ' days')->format('j M')) ?></span><span>Today</span></div>
        </section>
<?php $w['dl-chart'] = ob_get_clean(); ob_start(); ?>
        <section class="sec hm-chart-box hm-login-box">
          <h2>Logins, Last <?= HOME_DAYS ?> Days <span class="hm-legend"><i class="ok"></i>Can Download<?php if ($loginNo): ?> <i class="no"></i>No Paid Tier<?php endif ?></span></h2>
          <div class="hm-chart hm-chart-login" role="img" aria-label="<?= e(m3d_plural(array_sum(array_map('array_sum', $logins)), 'login') . ' in the last ' . HOME_DAYS . ' days') ?>">
            <?php foreach ($logins as $d => [$ok, $no]): $pre = $loginSince === '' || $d < $loginSince; ?><i class="<?= trim(($d === array_key_last($logins) ? 'today' : '') . ($pre ? ' pre' : '')) ?>" style="height:<?= $pre ? 2 : max(2, round(($ok + $no) / $maxLogin * 100)) ?>%" title="<?= e($fmtDay($d) . ': ' . ($pre ? 'not counted yet' : m3d_plural($ok + $no, 'login') . ($no ? " ($no without a paid tier)" : ''))) ?>"><?php if ($no && !$pre): ?><s style="height:<?= round($no / ($ok + $no) * 100) ?>%"></s><?php endif ?></i><?php endforeach ?>
          </div>
          <div class="hm-axis"><span><?= e($today->modify('-' . (HOME_DAYS - 1) . ' days')->format('j M')) ?></span><span><?= e($loginSince !== '' && $loginSince > array_key_first($logins) ? 'Counted since ' . (new DateTimeImmutable($loginSince, $tz))->format('j M') : $today->modify('-' . intdiv(HOME_DAYS, 2) . ' days')->format('j M')) ?></span><span>Today</span></div>
        </section>
<?php $w['login-chart'] = ob_get_clean(); ob_start(); ?>
        <section class="sec hm-recent-box">
          <h2>Latest Downloads <a href="/admin/log.php#log">Patron Log →</a></h2>
          <?php if (!$recent): ?><p class="muted">No downloads yet.</p><?php else: ?>
          <table class="hm-recent">
            <?php foreach ($recent as $i => [$t, $id, $who, $k, $s, $ver]): $dt = (new DateTimeImmutable('@' . $t))->setTimezone($tz); [$name] = $item($k, $s); ?>
            <tr<?= $i >= HOME_RECENT ? ' class="more"' : '' ?>>
              <td class="t" title="<?= e($dt->format('D j M Y, H:i T')) ?>"><?= e($dt->format('Y-m-d') === $today->format('Y-m-d') ? $dt->format('H:i') : $dt->format('j M')) ?></td>
              <td class="w"><a href="/admin/log.php?patron=<?= e(rawurlencode($id)) ?>#log"><?= e($who !== '' ? $who : $id) ?></a></td>
              <td class="i"><b><?= e($name) ?></b></td>
              <td class="v"><?= e($ver) ?></td>
            </tr>
            <?php endforeach ?>
          </table>
          <?php endif ?>
        </section>
<?php $w['recent'] = ob_get_clean(); ob_start(); ?>
        <section class="sec hm-todo-box">
          <h2>Needs a Look</h2>
          <ul class="hm-todo">
            <?php foreach ($todo as [$tone, $text, $href, $go]): ?>
            <li class="<?= $tone ?>"><a href="<?= e($href) ?>"><span class="dot" aria-hidden="true"></span><span class="txt"><?= e($text) ?></span><span class="go"><?= e($go) ?> →</span></a></li>
            <?php endforeach ?>
          </ul>
        </section>
<?php $w['todo'] = ob_get_clean(); ob_start(); ?>
        <section class="sec hm-top-box">
          <h2>Most Downloaded This Week</h2>
          <?php if (!$top): ?><p class="muted">No downloads in the last 7 days.</p><?php else: $best = max($top); ?>
          <ul class="hm-top">
            <?php $i = 0; foreach ($top as $key => $n): [$k, $s] = explode(':', $key, 2); [$name, $pic, $kindLabel, $it] = $item($k, $s); ?>
            <li<?= $i++ >= HOME_TOP ? ' class="more"' : '' ?>>
              <?php if ($pic !== ''): ?><img src="<?= e(img_thumb($pic)) ?>" alt="" loading="lazy" /><?php else: ?><span class="no-img"></span><?php endif ?>
              <span class="nm"><?= $it ? '<a href="/admin/edit.php?' . ($k === 'outfits' ? 'kind=outfits&amp;' : '') . 'slug=' . e(rawurlencode($s)) . '">' . e($name) . '</a>' : e($name) ?><span class="k"><?= e($kindLabel) ?></span><span class="bar" style="width:<?= round($n / $best * 100) ?>%"></span></span>
              <b class="n"><?= $n ?></b>
            </li>
            <?php endforeach ?>
          </ul>
          <?php endif ?>
        </section>
<?php $w['top'] = ob_get_clean();

// ── Widgets that start hidden (+ Add Widget) ──
$until = function (int $t) use ($today, $tz): string {   // "today", "tomorrow", "in 5 days" (calendar days, site time)
    $n = (int) $today->diff((new DateTimeImmutable('@' . $t))->setTimezone($tz)->setTime(0, 0))->format('%r%a');
    return $n <= 0 ? 'today' : ($n === 1 ? 'tomorrow' : "in $n days");
};
$verText = fn(string $v) => preg_match('/^\d/', $v) ? "V$v" : $v;
$itemLink = fn(string $k, array $it) => '/admin/edit.php?' . ($k === 'outfits' ? 'kind=outfits&amp;' : '') . 'slug=' . e(rawurlencode((string) $it['slug']));
$more = fn(int $i, int $usual) => $i >= $usual ? '<li class="more">' : '<li>';   // rows past the usual number show only in a taller widget
$pic = fn(string $k, array $it) => ($p = item_pic($k, $it)) !== '' ? '<img src="' . e(img_thumb($p)) . '" alt="" loading="lazy" />' : '<span class="no-img"></span>';

// Most Liked: the likes there are now (models and accessories)
$liked = [];
foreach (['models', 'outfits'] as $k) foreach (m3d_likes($k) as $slug => $codes) if (($n = count((array) $codes)) && ($it = m3d_item($k, (string) $slug))) $liked[] = [$n, $k, $it];
usort($liked, fn($a, $b) => $b[0] <=> $a[0]);
$liked = array_slice($liked, 0, HOME_MORE);
$h = '<section class="sec hm-top-box"><h2>Most Liked <a href="/admin/log.php?tsort=likes-desc">Downloads →</a></h2>';
if (!$liked) $h .= '<p class="muted">No likes yet.</p>';
else {
    $h .= '<ul class="hm-top">';
    foreach ($liked as $i => [$n, $k, $it]) {
        $h .= $more($i, HOME_TOP) . $pic($k, $it) . '<span class="nm"><a href="' . $itemLink($k, $it) . '">' . e((string) $it['name']) . '</a><span class="k">' . e($k === 'outfits' ? m3d_accessory_type($it) : 'Model')
            . '</span><span class="bar bar-like" style="width:' . round($n / $liked[0][0] * 100) . '%"></span></span><b class="n">' . $n . '</b></li>';
    }
    $h .= '</ul>';
}
$w['liked'] = $h . '</section>';

// Coming Up: the patrons date, the public date and versions dated in the future, of every item (drafts too, tagged), soonest first
$events = [];
foreach (['models', 'outfits'] as $k) foreach (m3d_items($k) as $it) {
    $rel = m3d_release($it);
    if ($rel['patrons'] && $rel['patrons'] > $now) $events[] = [$rel['patrons'], $k, $it, 'Patrons get it', true];
    if ($rel['public'] && $rel['public'] > $now) $events[] = [$rel['public'], $k, $it, 'Free for everyone', true];
    $top1 = m3d_latest_version($it);
    // a version dated in the future; not for an item still before its patrons date (it comes out with the release)
    if (!($rel['patrons'] && $rel['patrons'] > $now) && ($t = m3d_changelog_ts((string) ($top1['date'] ?? ''))) && $t > $now) $events[] = [$t, $k, $it, $verText(m3d_version_label($top1)) . ' comes out', false];
}
usort($events, fn($a, $b) => $a[0] <=> $b[0]);
$h = '<section class="sec hm-coming-box"><h2>Coming Up</h2>';
if (!$events) $h .= '<p class="muted">Nothing scheduled. Release dates are set in an item’s Access section; a version dated in the future comes out on its day.</p>';
else {
    $h .= '<ul class="hm-top hm-coming">';
    foreach (array_slice($events, 0, HOME_MORE) as $i => [$t, $k, $it, $what, $hasTime]) {
        $dt = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
        $h .= $more($i, 6) . $pic($k, $it) . '<span class="nm"><a href="' . $itemLink($k, $it) . '">' . e((string) $it['name']) . '</a>'
            . (m3d_visibility($it) === 'draft' ? ' <span class="hm-draft">Draft</span>' : '') . '<span class="k">' . e($what) . '</span></span>'
            . '<span class="when" title="' . e($dt->format('D j M Y' . ($hasTime ? ', H:i T' : ''))) . '"><b>' . e($dt->format('j M')) . '</b>' . e($until($t)) . '</span></li>';
    }
    $h .= '</ul>';
}
$w['coming'] = $h . '</section>';

// Update Uptake: versions out in the last HOME_UPTAKE days (items with an older version): of the patrons in the log who downloaded the item,
// how many have this version as their last download
$fresh = [];
foreach (['models', 'outfits'] as $k) foreach (m3d_items($k) as $it) {
    if (count(m3d_released_versions($it)) < 2) continue;
    $cur = m3d_current_version($it);
    $t = m3d_changelog_ts((string) ($cur['date'] ?? ''));
    if ($t && $t >= $today->modify('-' . HOME_UPTAKE . ' days')->getTimestamp()) $fresh[] = [$t, $k, $it, m3d_version_label($cur)];
}
usort($fresh, fn($a, $b) => $b[0] <=> $a[0]);
$h = '<section class="sec hm-uptake-box"><h2>Update Uptake <a href="/admin/log.php#log">Patron Log →</a></h2>';
if (!$fresh) $h .= '<p class="muted">No new version of a model or accessory in the last ' . HOME_UPTAKE . ' days.</p>';
else {
    $h .= '<ul class="hm-top hm-uptake">';
    foreach (array_slice($fresh, 0, HOME_MORE) as $i => [$t, $k, $it, $label]) {
        $who = $got[$k . ':' . $it['slug']] ?? [];
        $have = count(array_filter($who, fn($v) => $v !== '' && m3d_ver_key($v) === m3d_ver_key($label)));
        $all = count($who);
        $h .= $more($i, HOME_TOP) . $pic($k, $it) . '<span class="nm"><a href="' . $itemLink($k, $it) . '">' . e((string) $it['name']) . '</a><span class="k">'
            . e($verText($label) . ' · out ' . (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format('j M')) . '</span>'
            . '<span class="bar bar-up" style="width:' . ($all ? round($have / $all * 100) : 0) . '%"></span></span>'
            . '<b class="n" title="' . e("$have of the $all patrons who downloaded it have " . $verText($label)) . '">' . $have . '<small> / ' . $all . '</small></b></li>';
    }
    $h .= '</ul>';
}
$w['uptake'] = $h . '</section>';

if (m3d_bugs_on()) {   // Bugs This Week: reports opened, and status changes to fixed / closed, in the last 7 days
    $opened = $fixed = $closed = 0;
    foreach (m3d_bugs() as $b) {
        if ((int) ($b['at'] ?? 0) >= $weekFrom) $opened++;
        foreach ((array) ($b['log'] ?? []) as $x) {
            $t = (string) ($x['t'] ?? '');   // 'ref' with `was`: made Fixed by its "#6" in a changelog
            if (!($t === 's' || ($t === 'ref' && isset($x['was']))) || (int) ($x['at'] ?? 0) < $weekFrom) continue;
            if ($t === 'ref' || ($x['status'] ?? '') === 'fixed') $fixed++; elseif (($x['status'] ?? '') === 'closed') $closed++;
        }
    }
    $w['bugs-week'] = '<a class="stat stat-i stat-bug" href="/bugs/?status=all">' . $icon('bug') . '<b>' . $opened . '</b><span>Bugs This Week</span><small class="flat">'
        . e(m3d_plural($opened, 'new report') . ', ' . $fixed . ' fixed, ' . $closed . ' closed') . '</small></a>';
}
if (($stills = m3d_gallery()['stills'])) {   // Gallery Downloads: the all-time counters of the full size files (stats.json → downloads.stills)
    $sd = 0;
    foreach ((array) (m3d_stats()['downloads']['stills'] ?? []) as $c) $sd += (int) ($c['patrons'] ?? 0) + (int) ($c['public'] ?? 0);
    $withLink = count(array_filter($stills, fn($x) => !empty($x['link']['url'])));
    $w['stills'] = '<a class="stat stat-i stat-gal" href="/admin/gallery.php">' . $icon('pic') . '<b>' . number_format($sd) . '</b><span>Gallery Downloads</span><small class="flat">'
        . e('all time · ' . m3d_plural($withLink, 'picture') . ' with a full size file') . '</small></a>';
}

// shown ones in the saved order, then the others (hidden: the Add Widget window shows them)
$shown = array_values(array_filter(home_layout(), fn($r) => isset($w[$r[0]])));
$rest = array_diff(array_keys($w), array_column($shown, 0));
// where each shown widget sits on wide screens, worked out here the same way admin.js does it (`place()`), so the page arrives laid out and nothing
// moves when the script runs: in the board's rows of 2 px, a tile HOME_UNIT tall, a box its tiles (phones need nothing: the widgets simply follow
// each other there, tiles two by two)
$grid = [];
$hs = array_fill(0, 12, 0);
foreach ($shown as [$id, $span, $col, $h]) {
    $rows = (int) ceil(((HOME_WIDGETS[$id][1] === 'tile' ? HOME_UNIT : $h * HOME_UNIT + ($h - 1) * 16) + 16) / 2);   // 16 = admin.css --hm-gap
    $top = fn(int $c): int => max(array_slice($hs, $c - 1, $span));
    if ($col) $c = min($col, 13 - $span);
    else {
        $c = 1;
        for ($b = 2; $b <= 13 - $span; $b++) if ($top($b) < $top($c)) $c = $b;
    }
    $t = $top($c);
    for ($i = $c - 1; $i < $c - 1 + $span; $i++) $hs[$i] = $t + $rows;
    $grid[$id] = ";--c:$c;--r:" . ($t + 1) . ";--rs:$rows";
}
$cell = function (string $id, int $span, int $col, int|float $h, bool $hidden) use ($w, $grid): string {
    [$title, $kind, , $desc] = HOME_WIDGETS[$id];
    if ($kind === 'box' && !$h) $h = HOME_WIDGETS[$id][4];
    return '<div class="hm-w hm-' . $kind . '" data-w="' . e($id) . '" data-kind="' . $kind . '" data-title="' . e($title) . '" data-desc="' . e($desc) . '" data-span="' . $span . '" data-col="' . $col . '"'
        . ($h ? ' data-h="' . $h . '" data-def="' . HOME_WIDGETS[$id][4] . '"' : '') . ' style="--span:' . $span . ($h ? ";--tall:$h" : '') . ($grid[$id] ?? '') . '"' . ($hidden ? ' hidden' : '') . '>' . $w[$id] . '</div>';
};

admin_start('Home', 'home');
?>
    <div class="admin-head">
      <div>
        <h1>Home</h1>
        <p class="muted"><?= e($today->format('l j F')) ?> · <a class="hm-ver" href="/admin/update.php" title="Install an update">site version <?= e(M3D_VERSION) ?></a></p>
      </div>
      <div class="row-btns" data-home-view>
        <a class="btn btn-ghost btn-sm" href="/admin/edit.php">+ New Model</a>
        <a class="btn btn-ghost btn-sm" href="/admin/edit.php?kind=outfits">+ New Accessory</a>
        <a class="btn btn-ghost btn-sm" href="/admin/gallery.php">+ Gallery Pictures</a>
        <button type="button" class="btn btn-ghost btn-sm" data-home-edit title="Add, remove, resize and move the widgets">Edit Layout</button>
      </div>
      <div class="row-btns hm-tools" data-home-tools hidden>
        <button type="button" class="btn btn-ghost btn-sm" data-home-add>+ Add Widget</button>
        <?php if (isset(m3d_settings()['home'])): ?><button class="btn btn-ghost btn-sm" form="home-reset" title="Go back to the default widgets">Reset</button><?php endif ?>
        <button type="button" class="btn btn-ghost btn-sm" data-home-cancel>Cancel</button>
        <button class="btn btn-primary btn-sm" form="home-layout">Save Layout</button>
      </div>
    </div>
    <form method="post" id="home-layout" hidden><?= form_keys('home-layout') ?><input type="hidden" name="layout" value="" /></form>
    <form method="post" id="home-reset" hidden onsubmit="return confirm('Go back to the default widgets and order?')"><?= form_keys('home-reset') ?></form>
    <?php if (!$logOn): ?><p class="notice">The download log is off (config <code>log_downloads</code>), so the download numbers below stay at zero.</p><?php endif ?>

    <div class="hm-board is-masonry" data-home-board>
      <?php foreach ($shown as [$id, $span, $col, $h]) echo $cell($id, $span, $col, $h, false); ?>
      <?php foreach ($rest as $id) echo $cell($id, HOME_WIDGETS[$id][2], 0, 0, true); ?>
    </div>
<?php admin_end();
