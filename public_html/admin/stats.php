<?php
// Stats: what happened on the whole site, on all models, all accessories or one model / accessory (?of=models | outfits | models:<slug> |
// outfits:<slug>), over a period (?p=30 | 90 | 365 | all). Read only, from data the site already keeps: the download log (patron downloads,
// with the membership at each one), stats.json (all-time counters, free links too), likes and logins per day, likes per item, bug reports,
// comments, ignored updates and the Gallery. Nothing new is stored. The list of models and accessories is the table that Downloads had.
require __DIR__ . '/_admin.php';
admin_require($M3D);

// period => [label, days (0 = everything in the log), one bar a …]
const STATS_PERIODS = ['30' => ['Last 30 Days', 30, 'day'], '90' => ['Last 90 Days', 90, 'day'], '365' => ['Last 12 Months', 365, 'week'], 'all' => ['All Time', 0, 'month']];
const STATS_LIST = 5;   // rows of the bug report and comment lists of one item
// line icons of the tiles and the picker
const STATS_ICONS = [
    'dl'    => '<path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14"/>',
    'pat'   => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 19.5c.6-3.4 3.2-5.4 6.5-5.4s5.9 2 6.5 5.4M16 4.8a3.5 3.5 0 0 1 0 6.4M18 14.4c2 .7 3.2 2.4 3.6 5.1"/>',
    'new'   => '<circle cx="10" cy="8.2" r="3.8"/><path d="M3.5 19.5c.7-3.6 3.4-5.6 6.5-5.6 1.4 0 2.6.3 3.7 1M18 13.5v6M15 16.5h6"/>',
    'all'   => '<path d="M4 19.5h16M7 16V11M12 16V6M17 16v-3"/>',
    'like'  => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.2 4.2 0 0 1 12 7.3a4.2 4.2 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
    'login' => '<path d="M10 4.5H6.5a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2H10M14 8l4 4-4 4M18 12H9"/>',
    'bug'   => '<rect x="7.5" y="8" width="9" height="12" rx="4.5"/><path d="M9.5 8a2.5 2.5 0 0 1 5 0M12 12v8M7.5 13H4M20 13h-3.5M5 8.5l2.8 1.6M19 8.5l-2.8 1.6M5 18.5l2.8-1.6M19 18.5l-2.8-1.6"/>',
    'cm'    => '<path d="M5 5.5h14a1.5 1.5 0 0 1 1.5 1.5v8.5A1.5 1.5 0 0 1 19 17h-8l-4.5 3.5V17H5a1.5 1.5 0 0 1-1.5-1.5V7A1.5 1.5 0 0 1 5 5.5z"/>',
    'site'  => '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.6 3.6 5.4 3.6 8.5s-1.1 5.9-3.6 8.5c-2.5-2.6-3.6-5.4-3.6-8.5S9.5 6.1 12 3.5z"/>',
    'model' => '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>',
];
const STATS_MONTHS = [[0, 1, 'Under a month'], [1, 3, '1–2 months'], [3, 12, '3–11 months'], [12, PHP_INT_MAX, 'A year or more']];   // months as a patron: >= from, < to

$tz = m3d_tz();
$now = time();
$today = new DateTimeImmutable('today', $tz);

// ── What and when ──
$per = isset(STATS_PERIODS[$p = m3d_get_text('p')]) ? $p : '30';
[$perLabel, $perDays, $unit] = STATS_PERIODS[$per];
$of = m3d_get_text('of');
$kind = $slug = '';
$item = null;
if (preg_match('/^(models|outfits)(?::([a-z0-9-]{1,64}))?$/', $of, $m)) {
    $kind = $m[1];
    if (($m[2] ?? '') !== '') {
        $item = m3d_item($kind, $m[2]);
        if ($item) $slug = $m[2];
        else { admin_flash('That model or accessory no longer exists: here is the whole site.', 'info'); $kind = ''; }
    }
}
$scope = $item ? 'item' : ($kind !== '' ? 'kind' : 'site');
$of = $item ? "$kind:$slug" : $kind;
$logKey = $item ? m3d_log_item($kind, $slug) : '';
// this page's address with another scope or period (the 30 days are the default)
$url = fn(array $q = []): string => '/admin/stats.php' . (($q = array_filter(['of' => $q['of'] ?? $of, 'p' => $q['p'] ?? $per], fn($v, $k) => $v !== '' && !($k === 'p' && $v === '30'), ARRAY_FILTER_USE_BOTH)) ? '?' . http_build_query($q) : '');
$inScope = fn(string $k, string $s): bool => $scope === 'site' || ($k === $kind && ($scope === 'kind' || $s === $slug));

// the first log line (the log is in time order): where "All Time" starts; one item: also its own first line
$firstLog = $firstItem = 0;
if (($fh = @fopen(m3d_log_file(), 'rb'))) {
    while (($line = fgets($fh)) !== false) {
        if (!isset(($r = m3d_log_cols($line))[3]) || !($t = strtotime((string) $r[0]))) continue;
        $firstLog = $firstLog ?: $t;
        if (!$item || $r[3] === $logKey) { $firstItem = $t; break; }
    }
    fclose($fh);
}
if ($perDays) {
    $from = $today->modify('-' . ($perDays - 1) . ' days');   // N calendar days with today, like Home and Downloads
    $prevFrom = $today->modify('-' . (2 * $perDays - 1) . ' days')->getTimestamp();
} else {
    // everything in the log; one item: from its first version when that came later
    $start = $firstLog ?: $now;
    $oldest = null;
    if ($item) foreach (m3d_released_versions($item) as $en) if (($t = m3d_changelog_ts((string) ($en['date'] ?? '')))) $oldest = min($oldest ?? $t, $t);
    if ($oldest !== null && $firstItem) $oldest = min($oldest, $firstItem);   // downloaded before its oldest version in the changelog: every download still counts
    $from = (new DateTimeImmutable('@' . max($start, $oldest ?? $start)))->setTimezone($tz)->setTime(0, 0);
    $preWhat = ($oldest ?? 0) > $start ? 'its first version' : 'the oldest line of the log';
    $prevFrom = null;
}
$preWhat ??= '';
$fromTs = $from->getTimestamp();
// All Time: a bar a day while that's up to 90 days, a week up to two years, else a month; at least 30 days are drawn (grey before the start)
$chartFrom = $from;
if (!$perDays) {
    $span = (int) $from->diff($today)->days + 1;
    $unit = $span <= 90 ? 'day' : ($span <= 730 ? 'week' : 'month');
    if ($span < 30) $chartFrom = $today->modify('-29 days');
}

// the bars: one a day, a week (from Monday) or a month, in site time; key => [first downloads, again]
$bucketOf = function (string $day) use ($unit): string {   // a day (Y-m-d) => its bar's key
    static $memo = [];
    if (isset($memo[$day])) return $memo[$day];
    if ($unit === 'day') return $memo[$day] = $day;
    $d = new DateTimeImmutable($day);
    return $memo[$day] = $unit === 'week' ? $d->modify('-' . ($d->format('N') - 1) . ' days')->format('Y-m-d') : $d->format('Y-m');
};
$bars = [];
for ($d = $chartFrom; $d <= $today; $d = $d->modify($unit === 'month' ? 'first day of next month' : '+1 ' . $unit)) $bars[$bucketOf($d->format('Y-m-d'))] = [0, 0];
$bars[$bucketOf($today->format('Y-m-d'))] ??= [0, 0];
$preKey = $chartFrom < $from ? $bucketOf($from->format('Y-m-d')) : '';   // bars before it: before the log (or the item) starts
$barText = function (string $key) use ($unit, $tz): string {
    $d = new DateTimeImmutable($unit === 'month' ? "$key-01" : $key, $tz);
    return $unit === 'day' ? $d->format('D j M') : ($unit === 'week' ? 'Week of ' . $d->format('j M') : $d->format('F Y'));
};
$localDay = function (int $t) use ($tz): string {   // a time's day in site time; the offset is worked out once per hour
    static $off = [];
    $h = intdiv($t, 3600);
    $off[$h] ??= $tz->getOffset(new DateTimeImmutable('@' . $t));
    return gmdate('Y-m-d', $t + $off[$h]);
};
$localWh = function (int $t) use ($tz): array {   // [weekday 0 (Monday)–6, hour] in site time
    $l = $t + $tz->getOffset(new DateTimeImmutable('@' . $t));
    return [(int) gmdate('N', $l) - 1, (int) gmdate('G', $l)];
};

// ── One pass over the download log ──
$dl = $prev = 0;            // downloads in the period / in the period before it
$seen = [];                 // "patron|item|link" => true once downloaded: a patron's first download of each link of an item is a "first download", the same link again (an update, a re-download) is "again"
$firstIn = [];              // patron => their first download in scope
$who = [];                  // patron => their membership at their last download in the period (null: not recorded)
$heat = array_fill(0, 7, array_fill(0, 24, 0));
$perItem = [];              // log item => downloads in the period (every item: the ranking needs them)
$last = [];                 // log item => [patron => the version of their last download] (Have Latest, Patrons With It, uptake)
$links = [];                // link label => downloads in the period (one item)
$verDl = [];                // version key => downloads in the log (one item)
if (($fh = @fopen(m3d_log_file(), 'rb'))) {
    while (($line = fgets($fh)) !== false) {
        $r = m3d_log_cols($line);
        if (!isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
        $id = (string) $r[1];
        $ik = (string) $r[3];
        [$k, $s] = m3d_log_item_parts($ik);
        $ver = (string) ($r[5] ?? '');
        $pair = "$id|$ik|" . mb_strtolower(trim((string) ($r[4] ?? '')));   // the log keeps the link's label: a renamed link starts again
        $first = !isset($seen[$pair]);
        $seen[$pair] = true;
        $last[$ik][$id] = $ver;
        $inPer = $t >= $fromTs;
        if ($inPer) $perItem[$ik] = ($perItem[$ik] ?? 0) + 1;
        if (!$inScope($k, $s)) continue;
        if (!isset($firstIn[$id])) $firstIn[$id] = $t;
        if ($item && $ver !== '') $verDl[m3d_ver_key($ver)] = ($verDl[m3d_ver_key($ver)] ?? 0) + 1;
        if (!$inPer) { if ($prevFrom !== null && $t >= $prevFrom) $prev++; continue; }
        $dl++;
        $b = $bucketOf($localDay($t));
        if (isset($bars[$b])) $bars[$b][$first ? 0 : 1]++;
        $who[$id] = m3d_log_membership($r);
        [$wd, $hr] = $localWh($t);
        $heat[$wd][$hr]++;
        if ($item) $links[(string) ($r[4] ?? '') ?: 'Download'] = ($links[(string) ($r[4] ?? '') ?: 'Download'] ?? 0) + 1;   // very old lines have no link label
    }
    fclose($fh);
}
$newPatrons = count(array_filter($firstIn, fn($t) => $t >= $fromTs));

// ── The models and accessories in scope (the table, the ranking, the picker) ──
$stats = m3d_stats();
$bugsOn = m3d_bugs_on();
$openBugs = [];   // "kind:slug" => open reports
if ($bugsOn) foreach (m3d_bugs() as $bg) if (in_array($bg['status'] ?? 'new', BUG_OPEN, true)) $openBugs[(string) ($bg['about'] ?? '')] = ($openBugs[(string) ($bg['about'] ?? '')] ?? 0) + 1;
$rows = [];   // every model and accessory: the numbers the table and the ranking need
foreach (['models', 'outfits'] as $k) foreach (m3d_items($k) as $it) {
    $ik = m3d_log_item($k, (string) $it['slug']);
    $cur = m3d_released_label($it);
    $have = $cur === '' ? 0 : count(array_filter($last[$ik] ?? [], fn($v) => $v !== '' && m3d_ver_key($v) === m3d_ver_key($cur)));
    $all = count($last[$ik] ?? []);
    $rows[] = ['kind' => $k, 'it' => $it, 'key' => $ik, 'period' => $perItem[$ik] ?? 0, 'likes' => m3d_like_count((string) $it['slug'], $k),
        'latest' => $all ? (int) round($have / $all * 100) : -1, 'cur' => $cur, 'bugs' => $openBugs["$k:{$it['slug']}"] ?? 0] + m3d_downloads($k, (string) $it['slug'], $stats);
}
usort($rows, fn($a, $b) => [$b['period'], $b['total'], $b['likes']] <=> [$a['period'], $a['total'], $a['likes']]);
$kinds = array_count_values(array_column($rows, 'kind'));
$name = fn(string $k, array $it): string => $k === 'models' ? m3d_model_name($it) : (string) $it['name'];
$tag = function (string $k, array $it): string {   // "Gen 2" for a model, the type for an accessory
    if ($k === 'outfits') return m3d_accessory_type($it);
    return ($it['generation'] ?? '') !== '' ? 'Gen ' . $it['generation'] : '';
};
$thumb = function (string $k, array $it, string $cls = 'sp-pic'): string {
    $src = item_pic($k, $it);
    return '<span class="' . $cls . '" aria-hidden="true">' . ($src !== '' ? '<img src="' . e(img_thumb($src)) . '" alt="" loading="lazy" decoding="async" />'
        : '<svg viewBox="0 0 24 24">' . ($k === 'outfits' ? accessory_icon_paths(m3d_accessory_type($it)) : STATS_ICONS['model']) . '</svg>') . '</span>';
};

$svg = fn(string $k): string => '<svg viewBox="0 0 24 24" aria-hidden="true">' . STATS_ICONS[$k] . '</svg>';
$tile = function (string $cls, string $icon, int $n, string $label, string $small, string $href = '', string $title = '') use ($svg): string {
    $tag = $href !== '' ? 'a' : 'div';
    return "<$tag class=\"stat stat-i $cls\"" . ($href !== '' ? ' href="' . e($href) . '"' : '') . ($title !== '' ? ' title="' . e($title) . '"' : '') . '><span class="stat-ico">' . $svg($icon) . '</span><b>'
        . number_format($n) . '</b><span>' . e($label) . '</span>' . $small . "</$tag>";
};
$small = fn(string $text, string $cls = 'flat'): string => '<small class="' . $cls . '" title="' . e($text) . '">' . e($text) . '</small>';
$diff = function (int $n, ?int $before) use ($small, $per, $firstLog, $prevFrom, $tz): string {   // the line under a Downloads tile: the change against the period before
    if ($before === null) return $small('every download in the log');
    if (!$firstLog) return $small('no downloads in the log yet');
    if ($firstLog > $prevFrom) return $small('the log starts ' . (new DateTimeImmutable('@' . $firstLog))->setTimezone($tz)->format('j M Y'));   // nothing to compare with
    $what = $per === '365' ? 'the 12 months before' : "the $per days before";
    if ($before === 0) return $small($n ? "none in $what" : "none in $what either");
    if ($n === $before) return $small("same as $what");
    $txt = ($before < 20 ? abs($n - $before) . ($n > $before ? ' more' : ' fewer') : round(abs($n - $before) / $before * 100) . '%') . ' (' . number_format($before) . ' before)';
    return '<small class="' . ($n > $before ? 'up' : 'down') . '" title="' . e("Compared with $what") . '">' . e(($n > $before ? '↑ ' : '↓ ') . $txt) . '</small>';
};
$sinceNote = function (?string $since) use ($from, $tz): string {   // counted per day only from $since ('' = not yet, null = always): say so when the period starts earlier
    if ($since === null) return '';
    if ($since === '') return 'counted from now on';
    return $since > $from->format('Y-m-d') ? 'counted since ' . (new DateTimeImmutable($since, $tz))->format('j M Y') : '';
};

// ── Daily counts kept by the site (logins, new likes) and the dated comments / bug reports, in the chart's bars ──
$series = [];   // [title, total, [bar key => n], first day counted ('' = not yet, null = every one is dated), css class]
$sum = function (iterable $days, ?string $since = null) use ($bars, $bucketOf, $from): array {   // {Y-m-d: n} => [bar key => n], total in the period
    $out = array_fill_keys(array_keys($bars), 0);
    $tot = 0;
    foreach ($days as $day => $n) {
        if ((string) $day < $from->format('Y-m-d') || !isset($out[$b = $bucketOf((string) $day)])) continue;
        $out[$b] += $n;
        $tot += $n;
    }
    return [$out, max(0, $tot)];
};
[$loginDays, $loginSince] = m3d_logins_daily();
$likeDaily = (m3d_read_json('likes') ?? [])['daily'] ?? [];
$likeSince = is_string($likeDaily['since'] ?? null) ? $likeDaily['since'] : '';
$dated = function (iterable $times) use ($localDay): array {   // unix times => {Y-m-d: n}
    $out = [];
    foreach ($times as $t) if ($t > 0) $out[$localDay($t)] = ($out[$localDay($t)] ?? 0) + 1;
    return $out;
};
if ($scope === 'site') {
    [$b, $n] = $sum(array_map('array_sum', $loginDays));
    $series[] = ['Logins', $n, $b, $loginSince, 'sp-s-login'];
    [$b, $n] = $sum(array_map('intval', (array) ($likeDaily['n'] ?? [])));
    $series[] = ['New Likes', $n, $b, $likeSince, 'sp-s-like'];
}
$cmOn = m3d_comments_on($kind);   // all models / accessories, one item: comments on that kind; the whole site: on at all
if ($scope !== 'item' && $cmOn) {
    $times = [];
    foreach (m3d_comments_flat() as [$ck, $key, $c]) if (empty($c['admin']) && ($scope === 'site' || $ck === $kind)) $times[] = (int) ($c['at'] ?? 0);
    [$b, $n] = $sum($dated($times));
    $series[] = ['New Comments', $n, $b, null, 'sp-s-cm'];
}
if ($scope !== 'item' && $bugsOn) {
    $times = [];
    foreach (m3d_bugs() as $bg) if ($scope === 'site' || str_starts_with((string) ($bg['about'] ?? ''), "$kind:") || ($bg['gone'] ?? '') === $kind) $times[] = (int) ($bg['at'] ?? 0);
    [$b, $n] = $sum($dated($times));
    $series[] = ['New Bug Reports', $n, $b, null, 'sp-s-bug'];
}

// ── Tiles ──
$tiles = [$tile('stat-dl', 'dl', $dl, 'Downloads', $diff($dl, $prevFrom === null ? null : $prev), '/admin/log.php?' . http_build_query(array_filter(['item' => $logKey, 'from' => $from->format('Y-m-d')])) . '#log')];
if ($item) {
    $ik = $logKey;
    $cur = m3d_released_label($item);
    $haveCur = $cur === '' ? 0 : count(array_filter($last[$ik] ?? [], fn($v) => $v !== '' && m3d_ver_key($v) === m3d_ver_key($cur)));
    $mine = m3d_downloads($kind, $slug, $stats);
    $likes = m3d_like_count($slug, $kind);
    $same = array_values(array_filter($rows, fn($r) => $r['kind'] === $kind));
    $rank = fn(string $by) => 1 + count(array_filter($same, fn($r) => $r[$by] > (['period' => $perItem[$ik] ?? 0, 'total' => $mine['total'], 'likes' => $likes][$by])));
    $what = $kind === 'outfits' ? 'accessories' : 'models';
    $tiles[] = $tile('stat-new', 'new', $newPatrons, 'New Patrons', $small('first time downloading it'));
    $tiles[] = $tile('stat-pat', 'pat', count($last[$ik] ?? []), 'Patrons Have It', $small($cur !== '' ? number_format($haveCur) . ' have ' . (preg_match('/^\d/', $cur) ? "V$cur" : $cur) : 'no version out yet'), '', 'Patrons in the download log who downloaded it');
    $tiles[] = $tile('stat-free', 'all', $mine['total'], 'Downloads All Time', $small(number_format($mine['patrons']) . ' patrons · ' . number_format($mine['public']) . ' free links'));
    $tiles[] = $tile('stat-likes', 'like', $likes, 'Likes', $small('#' . $rank('likes') . ' of ' . count($same) . " $what"));
    if ($bugsOn) {
        $mineBugs = array_values(array_filter(m3d_bugs(), fn($bg) => ($bg['about'] ?? '') === "$kind:$slug"));
        $st = array_count_values(array_map(fn($bg) => in_array($bg['status'] ?? 'new', BUG_OPEN, true) ? 'open' : (string) $bg['status'], $mineBugs));
        $tiles[] = $tile('stat-bug', 'bug', $st['open'] ?? 0, 'Open Bug Reports', $small(($st['fixed'] ?? 0) . ' fixed · ' . ($st['closed'] ?? 0) . ' closed'), '/bugs/?about=' . rawurlencode("$kind:$slug") . '&status=all');
    } elseif ($cmOn) {
        $cms = m3d_comments($kind, $slug);
        $tiles[] = $tile('stat-cm', 'cm', count($cms), 'Comments', $small(m3d_plural(count(array_filter($cms, fn($c) => !empty($c['pending']))), 'waiting', 'waiting')));
    }
} else {
    $tiles[] = $tile('stat-pat', 'pat', count($who), 'Patrons', $small(count($who) ? 'who downloaded, ' . round($dl / count($who), 1) . ' each' : 'nobody downloaded'), '', 'Patrons who downloaded in this period');
    $tiles[] = $tile('stat-new', 'new', $newPatrons, 'New Patrons', $small($scope === 'site' ? 'their first download' : ($kind === 'outfits' ? 'their first accessory' : 'their first model')));
    $inKind = array_filter($rows, fn($r) => $scope === 'site' || $r['kind'] === $kind);
    if ($scope === 'site') {
        $ls = 0;   // the logins that could download
        foreach ($loginDays as $d => [$ok]) if ($d >= $from->format('Y-m-d')) $ls += $ok;
        $lt = $series[0][1];
        $tiles[] = $tile('stat-login', 'login', $lt, 'Logins', $small(($n = $sinceNote($loginSince)) !== '' ? $n : ($lt ? round($ls / $lt * 100) . '% can download' : 'logins with Patreon')), '', 'Logins with Patreon (yours aren’t counted)');
        $tiles[] = $tile('stat-likes', 'like', $series[1][1], 'New Likes', $small(($n = $sinceNote($likeSince)) !== '' ? $n : number_format(array_sum(array_column($rows, 'likes'))) . ' likes in all'));
    } else {
        $tiles[] = $tile('stat-free', 'all', array_sum(array_column($inKind, 'total')), 'Downloads All Time', $small(number_format(array_sum(array_column($inKind, 'public'))) . ' of them free links'));
        $tiles[] = $tile('stat-likes', 'like', array_sum(array_column($inKind, 'likes')), 'Likes', $small('on ' . m3d_plural(count($inKind), $kind === 'outfits' ? 'accessory' : 'model', $kind === 'outfits' ? 'accessories' : 'models')));
    }
    if ($bugsOn) {
        $opened = array_values(array_filter(m3d_bugs(), fn($bg) => (int) ($bg['at'] ?? 0) >= $fromTs && ($scope === 'site' || str_starts_with((string) ($bg['about'] ?? ''), "$kind:"))));
        $st = array_count_values(array_map(fn($bg) => in_array($bg['status'] ?? 'new', BUG_OPEN, true) ? 'open' : (string) $bg['status'], $opened));
        $tiles[] = $tile('stat-bug', 'bug', count($opened), 'Bug Reports', $small(($st['fixed'] ?? 0) . ' fixed · ' . ($st['open'] ?? 0) . ' still open'), '/bugs/?status=all' . ($scope === 'kind' ? '&kind=' . $kind : ''));
    } elseif ($scope === 'site') {
        $tiles[] = $tile('stat-free', 'all', array_sum(array_column($rows, 'total')), 'Downloads All Time', $small('patrons and free links'));
    }
}

// ── Who downloads: the patrons of the period, by their tier and months as a patron at their (last) download ──
$tierN = $monthN = [];
$unknown = 0;
foreach ($who as $mem) {
    if (!$mem || $mem['tier_cents'] === null) $tierN[-1] = ($tierN[-1] ?? 0) + 1; else $tierN[$mem['tier_cents']] = ($tierN[$mem['tier_cents']] ?? 0) + 1;
    if (!$mem || $mem['months'] === null) { $unknown++; continue; }
    foreach (STATS_MONTHS as $i => [$a, $b]) if ($mem['months'] >= $a && $mem['months'] < $b) $monthN[$i] = ($monthN[$i] ?? 0) + 1;
}
krsort($tierN);
if (isset($tierN[-1])) { $x = $tierN[-1]; unset($tierN[-1]); $tierN[-1] = $x; }   // "Not recorded" last
$pct = fn(int $n, int $of): int => $of ? (int) round($n / $of * 100) : 0;

// ── When: weekday × hour ──
$heatMax = max(1, ...array_map('max', $heat));
$busy = [0, 0, -1];
foreach ($heat as $wd => $hours) foreach ($hours as $hr => $n) if ($n > $busy[2]) $busy = [$wd, $hr, $n];
$wdNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

$maxBar = max(1, ...array_values(array_map('array_sum', $bars)));
$keys = array_keys($bars);
$axis = [$barText($keys[0]), $barText($keys[intdiv(count($keys) - 1, 2)]), ['day' => 'Today', 'week' => 'This Week', 'month' => 'This Month'][$unit]];
if ($unit !== 'month') foreach ([0, 1] as $i) {   // "5 Oct", not "Mon 5 Oct"; with the year when it isn't this one
    $y = substr($keys[$i ? intdiv(count($keys) - 1, 2) : 0], 0, 4);
    $axis[$i] = preg_replace('/^(Week of|\w{3}) /', '', $axis[$i]) . ($y !== $today->format('Y') ? " $y" : '');
}
if (count($keys) < 3) $axis[1] = '';   // the middle bar is the first or the last one: never the same label twice
if (count($keys) < 2) $axis[0] = '';
$chart = function (array $data, string $cls = '', ?string $since = null, string $noun = 'download') use ($barText, $bucketOf, $preKey, $preWhat): string {   // a plain bar chart (the small ones)
    $max = max(1, ...array_values($data));
    $sinceKey = $since === null ? '' : ($since === '' ? '9999' : $bucketOf($since));   // bars before it: not counted yet
    $h = '<div class="sp-chart sp-mini ' . $cls . '" aria-hidden="true">';
    foreach ($data as $key => $n) {
        $pre = ($early = $preKey !== '' && $key < $preKey) || ($sinceKey !== '' && $key < $sinceKey);
        $h .= '<i class="' . ($pre ? 'pre' : '') . '" style="height:' . ($pre ? 2 : max(2, round(max(0, $n) / $max * 100, 1))) . '%" title="' . e($barText($key) . ': ' . ($early ? 'before ' . $preWhat : ($pre ? 'not counted yet' : m3d_plural(max(0, $n), $noun)))) . '"></i>';
    }
    return $h . '</div>';
};

// the versions of one item that came out in the period, or of every item in scope: [time, kind, item, label]
$fresh = [];
foreach ($rows as $r) {
    if (!$inScope($r['kind'], (string) $r['it']['slug'])) continue;
    foreach (m3d_released_versions($r['it']) as $i => $en) {
        $t = m3d_changelog_ts((string) ($en['date'] ?? ''));
        if ($t && $t >= $fromTs && ($item || count(m3d_released_versions($r['it'])) > $i + 1)) $fresh[] = [$t, $r['kind'], $r['it'], m3d_version_label($en), $i === 0];   // not an item's first version (everyone who has it has that one)
    }
}
usort($fresh, fn($a, $b) => $b[0] <=> $a[0]);

// Gallery pictures: the most liked ones (one model: the pictures it is in)
$stillsShown = [];
if ($scope === 'site' || ($item && $kind === 'models')) {
    $sd = (array) ($stats['downloads']['stills'] ?? []);
    foreach (m3d_gallery()['stills'] as $st) {
        if ($item && !in_array($slug, (array) ($st['chars'] ?? []), true)) continue;
        $stillsShown[] = [m3d_like_count((string) $st['id'], 'stills'), (int) ($sd[$st['id']]['patrons'] ?? 0) + (int) ($sd[$st['id']]['public'] ?? 0), $st];
    }
    usort($stillsShown, fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    $stillsShown = array_slice($stillsShown, 0, 6);
}

$verLabel = fn(string $v): string => preg_match('/^\d/', $v) ? "V$v" : $v;
$itemUrl = fn(string $k, array $it): string => $url(['of' => "$k:{$it['slug']}"]);
$scopeName = $item ? $name($kind, $item) : ($scope === 'kind' ? ($kind === 'outfits' ? 'All Accessories' : 'All Models') : 'Whole Site');

admin_start($item ? $name($kind, $item) . ' · Stats' : 'Stats', 'stats');
?>
    <div class="admin-head">
      <div>
        <h1>Stats</h1>
        <p class="muted">Patron downloads, logins, likes and versions. Pick a model or accessory to see only its numbers.</p>
      </div>
      <div class="head-actions"><a class="btn btn-ghost" href="/admin/log.php<?= $logKey !== '' ? '?item=' . e(rawurlencode($logKey)) : '' ?>#log">Patron Log</a></div>
    </div>
    <?php if (empty($M3D['log_downloads'])): ?><p class="notice">The download log is off (config <code>log_downloads</code>), so the download numbers stay at zero.</p><?php endif ?>

    <div class="sp-bar">
      <details class="sp-pick" data-sp-pick>
        <summary>
          <?= $item ? $thumb($kind, $item, 'sp-ic') : '<span class="sp-ic">' . ($kind === 'outfits' ? '<svg viewBox="0 0 24 24" aria-hidden="true">' . ACCESSORY_ICON_GENERIC . '</svg>' : $svg($kind === 'models' ? 'model' : 'site')) . '</span>' ?>
          <span class="sp-what"><b><?= e($scopeName) ?></b><small><?= e($item ? ($kind === 'outfits' ? m3d_accessory_type($item) . ' · Accessory' : trim('Model · ' . ($item['franchise'] ?? ''), ' ·')) : ($scope === 'kind' ? m3d_plural($kinds[$kind] ?? 0, $kind === 'outfits' ? 'accessory' : 'model', $kind === 'outfits' ? 'accessories' : 'models') : m3d_plural($kinds['models'] ?? 0, 'model') . (!empty($kinds['outfits']) ? ' · ' . m3d_plural($kinds['outfits'], 'accessory', 'accessories') : ''))) ?></small></span>
          <svg class="sp-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </summary>
        <div class="sp-panel">
          <label class="batch-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="input" type="search" placeholder="Search models and accessories…" aria-label="Search models and accessories" autocomplete="off" spellcheck="false" data-sp-search /></label>
          <div class="sp-opts" data-sp-opts>
            <a href="<?= e($url(['of' => ''])) ?>"<?= $scope === 'site' ? ' aria-current="true"' : '' ?> data-search="whole site"><span class="sp-ic"><?= $svg('site') ?></span><span>Whole Site</span></a>
            <?php if (!empty($kinds['models'])): ?><a href="<?= e($url(['of' => 'models'])) ?>"<?= $scope === 'kind' && $kind === 'models' ? ' aria-current="true"' : '' ?> data-search="all models"><span class="sp-ic"><?= $svg('model') ?></span><span>All Models</span><span class="n"><?= $kinds['models'] ?></span></a><?php endif ?>
            <?php if (!empty($kinds['outfits'])): ?><a href="<?= e($url(['of' => 'outfits'])) ?>"<?= $scope === 'kind' && $kind === 'outfits' ? ' aria-current="true"' : '' ?> data-search="all accessories"><span class="sp-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><?= ACCESSORY_ICON_GENERIC ?></svg></span><span>All Accessories</span><span class="n"><?= $kinds['outfits'] ?></span></a><?php endif ?>
            <?php foreach (['models' => 'Models', 'outfits' => 'Accessories'] as $k => $title): if (empty($kinds[$k])) continue; ?>
            <p class="sp-grp"><?= $title ?> <span>by downloads, <?= e(mb_strtolower($perLabel)) ?></span></p>
            <?php foreach ($rows as $r): if ($r['kind'] !== $k) continue; $it = $r['it']; ?>
            <a href="<?= e($itemUrl($k, $it)) ?>"<?= $item && $kind === $k && $slug === $it['slug'] ? ' aria-current="true"' : '' ?> data-search="<?= e(admin_search_text([$it['name'], $it['franchise'] ?? '', $tag($k, $it), $k === 'outfits' ? 'accessory' : 'model', $it['slug']])) ?>"><?= $thumb($k, $it, 'sp-ic') ?><span><?= e($name($k, $it)) ?><small><?= e(implode(' · ', array_filter([$k === 'outfits' ? '' : ($it['franchise'] ?? ''), $tag($k, $it)]))) ?></small></span><span class="n"><?= number_format($r['period']) ?></span></a>
            <?php endforeach; endforeach ?>
            <p class="sp-none muted small" data-sp-none hidden>Nothing matches.</p>
          </div>
        </div>
      </details>
      <nav class="sp-per" aria-label="Period">
        <?php foreach (STATS_PERIODS as $k => [$label]): ?><a href="<?= e($url(['p' => (string) $k])) ?>"<?= (string) $k === $per ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach ?>
      </nav>
    </div>

    <?php if ($item): ?>
    <div class="sp-item">
      <?= $thumb($kind, $item, 'sp-cover') ?>
      <div class="sp-item-txt">
        <h2><?= e($name($kind, $item)) ?></h2>
        <div class="sp-sub">
          <span><?= e(implode(' · ', array_filter([$kind === 'outfits' ? m3d_accessory_type($item) . ' · Accessory' : 'Model', $item['franchise'] ?? '', $kind === 'models' ? $tag($kind, $item) : '']))) ?></span>
          <?php if ($kind === 'models'): $s = status_info(m3d_status($item)); ?><span class="badge tone-<?= e($s['tone']) ?>"><?= e($s['label']) ?></span><?php endif ?><?= admin_visibility_badge($item) ?>
          <?php if (($c = m3d_current_version($item))['version'] !== ''): ?><span><?= e($verLabel(m3d_version_label($c)) . (($t = m3d_changelog_ts($c['date'])) ? ' out ' . m3d_site_time($t, 'j M Y') : '')) ?></span><?php endif ?>
        </div>
      </div>
      <div class="row-btns">
        <a class="btn btn-ghost btn-sm" href="<?= e(m3d_item_url($kind, $slug)) ?>">View Page</a>
        <a class="btn btn-ghost btn-sm" href="/admin/edit.php?<?= $kind === 'outfits' ? 'kind=outfits&amp;' : '' ?>slug=<?= e(rawurlencode($slug)) ?>">Edit</a>
      </div>
    </div>
    <?php endif ?>

    <div class="sp-grid">
      <div class="sp-tiles"><?= implode('', $tiles) ?></div>

      <section class="sec sp-w8 sp-chart-box">
        <h2><span>Downloads, <?= e($perLabel) ?><?= tip('Patron downloads only: free links aren’t in the log, they count only in the all-time totals.') ?></span> <span class="sp-legend"><span><i class="a"></i>First Download</span><span><i class="b"></i>Again<?= tip('The same download link again: an update, or the same file downloaded again. Each link of an item (Model, Textures…) has its own first download.') ?></span><?php if ($item): ?><span><i class="v"></i>New Version</span><?php endif ?></span></h2>
        <div class="sp-chart sp-dl" role="img" aria-label="<?= e(m3d_plural($dl, 'patron download') . ', ' . mb_strtolower($perLabel)) ?>">
          <?php foreach ($bars as $key => [$a, $b]): if ($preKey !== '' && $key < $preKey): ?><i class="pre" style="height:1%" title="<?= e($barText($key) . ': before ' . $preWhat) ?>"></i><?php continue; endif ?><i style="height:<?= $a + $b ? max(2, round(($a + $b) / $maxBar * 100, 1)) : 1 ?>%" title="<?= e($barText($key) . ': ' . m3d_plural($a + $b, 'download') . ($a + $b ? ", $a first" : '')) ?>"><b style="flex:<?= $a ?>"></b><s style="flex:<?= $b ?>"></s></i><?php endforeach ?>
          <?php if ($item): $n = count($keys); foreach (m3d_released_versions($item) as $en): $t = m3d_changelog_ts((string) ($en['date'] ?? '')); if (!$t || $t < $fromTs) continue; $at = array_search($bucketOf($localDay($t)), $keys, true); if ($at === false) continue; ?>
            <em style="left:<?= round(($at + 0.5) / $n * 100, 2) ?>%" title="<?= e($verLabel(m3d_version_label($en)) . ' out ' . m3d_site_time($t, 'j M Y')) ?>"><span><?= e($verLabel(m3d_version_label($en))) ?></span></em>
          <?php endforeach; endif ?>
        </div>
        <div class="sp-axis"><span><?= e($axis[0]) ?></span><span><?= e($axis[1]) ?></span><span><?= e($axis[2]) ?></span></div>
      </section>

      <section class="sec sp-w4">
        <h2><?= $item ? 'Who Downloads It' : 'Who Downloads' ?></h2>
        <?php if (!$who): ?><p class="muted">No downloads in this period.</p><?php else: ?>
        <p class="sp-h3">Tier</p>
        <ul class="sp-bars sp-tier">
          <?php foreach ($tierN as $c => $n): ?><li><span><?= $c === -1 ? 'Not recorded' : e(m3d_tier_label($c)) ?></span><b><?= $pct($n, count($who)) ?>% <small>· <?= number_format($n) ?></small></b><span class="bar"><i style="width:<?= $pct($n, count($who)) ?>%"></i></span></li><?php endforeach ?>
        </ul>
        <p class="sp-h3">Months as a Patron</p>
        <ul class="sp-bars sp-months">
          <?php $known = count($who) - $unknown; foreach (STATS_MONTHS as $i => [, , $label]): $n = $monthN[$i] ?? 0; ?><li><span><?= e($label) ?></span><b><?= $pct($n, $known) ?>% <small>· <?= number_format($n) ?></small></b><span class="bar"><i style="width:<?= $pct($n, $known) ?>%"></i></span></li><?php endforeach ?>
        </ul>
        <p class="sp-foot"><?= m3d_plural(count($who), 'patron') ?>, as they were at their download<?= $unknown ? '; ' . $unknown . ' from before the log kept it' : '' ?>.</p>
        <?php endif ?>
      </section>

<?php if ($item): // ── one model or accessory ──
    $log = m3d_versions($item);
    $refs = [];
    if ($bugsOn) foreach (m3d_changelog_bug_refs($log) as $n => $i) $refs[$i][] = $n;
    $ign = [];
    foreach ((array) (m3d_read_json('ignored')['ignored'] ?? []) as $list) if (is_string($v = $list["$kind:$slug"] ?? null)) $ign[m3d_ver_key($v)] = ($ign[m3d_ver_key($v)] ?? 0) + 1;
    $haveAll = count($last[$logKey] ?? []);
    $lastKeys = array_count_values(array_map('m3d_ver_key', array_filter($last[$logKey] ?? [], fn($v) => $v !== '')));
    arsort($links);
?>
      <section class="sec sp-w8">
        <h2>Versions <a href="<?= e(m3d_item_url($kind, $slug)) ?>#changelog">Changelog →</a></h2>
        <?php if (!$log): ?><p class="muted">No versions in the changelog yet.</p><?php else: ?>
        <div class="table-wrap sp-scroll">
        <table class="sp-vt">
          <colgroup><col /><col class="n" /><col class="up" /><col class="n" /><?php if ($refs): ?><col class="fx" /><?php endif ?></colgroup>
          <thead><tr><th>Version</th><th class="c-num">Downloads</th><th>Patrons With It<?= tip('Patrons whose last download of it is that version.') ?></th><th class="c-num">Ignored</th><?php if ($refs): ?><th>Fixes</th><?php endif ?></tr></thead>
          <tbody>
            <?php foreach ($log as $i => $en): $vk = m3d_ver_key(m3d_version_label($en)); $t = m3d_changelog_ts((string) ($en['date'] ?? '')); $out = !$t || $t <= $now; $n = $lastKeys[$vk] ?? 0; ?>
            <tr<?= $out ? '' : ' class="is-soon"' ?>>
              <td class="v"><b><?= e($verLabel(m3d_version_label($en))) ?></b><?= m3d_stage($en) !== 'standard' ? ' ' . stage_badge(m3d_stage($en), true) : '' ?><?= $out && m3d_ver_key(m3d_released_label($item)) === $vk ? ' <span class="badge tone-green">Latest</span>' : '' ?><span class="d"><?= $t ? ($out ? 'out ' : 'coming ') . e(m3d_site_time($t, 'j M Y')) : 'no date' ?></span></td>
              <td class="c-num"><?= $out ? number_format($verDl[$vk] ?? 0) : '<span class="muted">—</span>' ?></td>
              <td><?php if ($out): ?><span class="sp-up"><span class="bar"><i style="width:<?= $pct($n, $haveAll) ?>%"></i></span><span><?= number_format($n) ?> <span class="muted">· <?= $pct($n, $haveAll) ?>%</span></span></span><?php else: ?><span class="muted">—</span><?php endif ?></td>
              <td class="c-num"><?= ($ign[$vk] ?? 0) ?: '<span class="muted">—</span>' ?></td>
              <?php if ($refs): ?><td><?php foreach ($refs[$i] ?? [] as $bn): ?><a class="sp-chip" href="/bugs/<?= (int) $bn ?>/">#<?= (int) $bn ?></a><?php endforeach ?><?= empty($refs[$i]) ? '<span class="muted">—</span>' : '' ?></td><?php endif ?>
            </tr>
            <?php endforeach ?>
          </tbody>
        </table>
        </div>
        <?php endif ?>
      </section>

      <section class="sec sp-w4">
        <h2>Download Links</h2>
        <?php if (!$links): ?><p class="muted">No downloads in this period.</p><?php else: $top = max($links); ?>
        <ul class="sp-bars sp-links">
          <?php foreach (array_slice($links, 0, 8, true) as $label => $n): ?><li><span><?= e((string) $label) ?></span><b><?= number_format($n) ?></b><span class="bar"><i style="width:<?= $pct($n, $top) ?>%"></i></span></li><?php endforeach ?>
        </ul>
        <?php endif ?>
        <p class="sp-h3">Ranking</p>
        <div class="sp-rank"><?php if ($perDays): /* All Time: the next one says it */ ?><span><b>#<?= $rank('period') ?></b>downloads, <?= e(mb_strtolower($perLabel)) ?></span><?php endif ?><span><b>#<?= $rank('total') ?></b>downloads, all time</span><span><b>#<?= $rank('likes') ?></b>likes</span></div>
        <p class="sp-foot">Among the <?= m3d_plural(count($same), $kind === 'outfits' ? 'accessory' : 'model', $kind === 'outfits' ? 'accessories' : 'models') ?>.</p>
      </section>

      <?php if ($bugsOn): $list = $mineBugs; usort($list, fn($a, $b) => [in_array($b['status'] ?? 'new', BUG_OPEN, true), (int) ($b['at'] ?? 0)] <=> [in_array($a['status'] ?? 'new', BUG_OPEN, true), (int) ($a['at'] ?? 0)]); ?>
      <section class="sec <?= $cmOn ? 'sp-w6' : '' ?>">
        <h2>Bug Reports <?php if ($list): ?><a href="/bugs/?about=<?= e(rawurlencode("$kind:$slug")) ?>&amp;status=all">All <?= count($list) ?> →</a><?php endif ?></h2>
        <?php if (!$list): ?><p class="muted">No bug reports about it.</p><?php else: ?>
        <ul class="sp-list sp-bugs">
          <?php foreach (array_slice($list, 0, STATS_LIST) as $bg): ?>
          <li><?= bug_status_badge($bg) ?><a href="/bugs/<?= (int) $bg['n'] ?>/"><b><?= e((string) $bg['title']) ?></b><span class="k">#<?= (int) $bg['n'] ?><?= ($n = count((array) ($bg['metoo'] ?? []))) ? ' · Me too ' . $n : '' ?></span></a><span class="t"><?= e(bug_ago((int) ($bg['at'] ?? 0))) ?></span></li>
          <?php endforeach ?>
        </ul>
        <?php endif ?>
      </section>
      <?php endif ?>

      <?php if ($cmOn): $list = m3d_comments($kind, $slug); usort($list, fn($a, $b) => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0)); ?>
      <section class="sec <?= $bugsOn ? 'sp-w6' : '' ?>">
        <h2>Latest Comments <?php if ($list): ?><a href="<?= e(m3d_item_url($kind, $slug)) ?>#comments"><?= count($list) ?> in All →</a><?php endif ?></h2>
        <?php if (!$list): ?><p class="muted">No comments yet.</p><?php else: ?>
        <ul class="sp-list sp-cms">
          <?php foreach (array_slice($list, 0, STATS_LIST) as $c): $at = (int) ($c['at'] ?? 0); $cname = (string) ($c['name'] ?? '') ?: 'Someone';
              $text = mb_strimwidth(trim(html_entity_decode(strip_tags(md_inline(preg_replace('/\s+/u', ' ', (string) ($c['text'] ?? '')) ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 160, '…'); ?>
          <li><?= bug_avatar($cname, !empty($c['admin'])) ?><a href="<?= e(m3d_item_url($kind, $slug)) ?>#cm-<?= (int) ($c['id'] ?? 0) ?>"><b><?= e($cname) ?></b><?= !empty($c['pending']) ? ' <span class="badge tone-mustard sp-wait">Waiting</span>' : '' ?><span class="k"><?= e($text) ?></span></a><span class="t" title="<?= e(m3d_site_time($at, 'D j M Y, H:i T')) ?>"><?= e(m3d_site_time($at, m3d_site_time($at, 'Y-m-d') === m3d_today() ? 'H:i' : 'j M')) ?></span></li>
          <?php endforeach ?>
        </ul>
        <?php endif ?>
      </section>
      <?php endif ?>

<?php else: // ── the whole site, all models or all accessories ──
    $tableRows = array_values(array_filter($rows, fn($r) => $scope === 'site' || $r['kind'] === $kind));
    $maxPer = max(1, ...array_column($tableRows, 'period') ?: [0]);
?>
      <section class="sec <?= $series ? 'sp-w8' : '' ?> sp-tot">
        <h2><?= $scope === 'site' ? 'Models &amp; Accessories' : ($kind === 'outfits' ? 'Accessories' : 'Models') ?></h2>
        <?php if (!$tableRows): ?><p class="muted">Nothing here yet.</p><?php else: ?>
        <div class="tot-bar">
          <?php if ($scope === 'site' && count($kinds) > 1): ?>
            <span class="tot-kinds" role="group" aria-label="Show">
              <button type="button" class="chip" data-tot-kind="" aria-pressed="true">All <span><?= count($tableRows) ?></span></button>
              <button type="button" class="chip" data-tot-kind="models" aria-pressed="false">Models <span><?= $kinds['models'] ?></span></button>
              <button type="button" class="chip" data-tot-kind="outfits" aria-pressed="false">Accessories <span><?= $kinds['outfits'] ?></span></button>
            </span>
          <?php endif ?>
          <span class="muted small tot-count" data-tot-count hidden></span>
          <label class="batch-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="input" type="search" data-tot-search placeholder="Search name, franchise, type…" aria-label="Search the list" autocomplete="off" spellcheck="false" /></label>
        </div>
        <div class="table-wrap sp-scroll">
          <table class="log-table tot-table sp-table" data-tot-table data-tot-default="period">
            <colgroup><col /><col class="sp-w-dl" /><col class="tot-w" /><col class="tot-w" /><col class="tot-w" /><?= $bugsOn ? '<col class="tot-w" />' : '' ?></colgroup>
            <thead><tr>
              <th aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="name">Name</button></th>
              <th aria-sort="descending"><button type="button" class="tot-sort" data-tot-sort="period">Downloads</button></th>
              <th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="total" title="Patrons and free links, all time">All Time</button></th>
              <th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="likes">Likes</button></th>
              <th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="latest" title="Of the patrons in the log who downloaded it, how many have the latest version">Latest</button></th>
              <?php if ($bugsOn): ?><th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="bugs">Open Bugs</button></th><?php endif ?>
            </tr></thead>
            <tbody>
              <?php foreach ($tableRows as $r): $it = $r['it']; $k = $r['kind']; $tg = $tag($k, $it); ?>
              <tr class="<?= $r['total'] || $r['period'] ? '' : 'tot-zero' ?>" data-kind="<?= e($k) ?>" data-name="<?= e(mb_strtolower($it['name'] . ' ' . $tg)) ?>" data-search="<?= e(implode(' ', [$it['name'], $tg, $it['franchise'] ?? '', $k === 'outfits' ? 'accessory' : 'model', $it['slug']])) ?>"
                data-period="<?= $r['period'] ?>" data-total="<?= $r['total'] ?>" data-likes="<?= $r['likes'] ?>" data-latest="<?= $r['latest'] ?>" data-bugs="<?= $r['bugs'] ?>">
                <td><a class="tot-item sp-go" href="<?= e($itemUrl($k, $it)) ?>"><?= $thumb($k, $it, 'tot-pic') ?><span class="tot-name"><b title="<?= e($name($k, $it)) ?>"><?= e($name($k, $it)) ?></b><?= $tg !== '' ? '<span class="tot-tag tot-tag-' . e($k) . '">' . e($tg) . '</span>' : '<span class="tot-tag is-none" aria-hidden="true">&nbsp;</span>' ?></span></a></td>
                <td><span class="tot-dl"><span class="tot-meter" aria-hidden="true"><i style="width:<?= round($r['period'] / $maxPer * 100, 1) ?>%"></i></span><b class="num"><?= number_format($r['period']) ?></b></span></td>
                <td class="c-num" title="<?= e(number_format($r['patrons']) . ' by patrons · ' . number_format($r['public']) . ' free') ?>"><?= number_format($r['total']) ?></td>
                <td class="c-num"><?= $r['likes'] ? '<span class="likes-num">♥ ' . $r['likes'] . '</span>' : '<span class="muted">0</span>' ?></td>
                <td class="c-num"><?= $r['latest'] < 0 ? '<span class="muted">—</span>' : $r['latest'] . '%' ?></td>
                <?php if ($bugsOn): ?><td class="c-num"><?= $r['bugs'] ?: '<span class="muted">0</span>' ?></td><?php endif ?>
              </tr>
              <?php endforeach ?>
              <tr class="tot-empty" data-tot-empty hidden><td colspan="<?= $bugsOn ? 6 : 5 ?>" class="muted"></td></tr>
            </tbody>
          </table>
        </div>
        <?php endif ?>
      </section>

      <?php if ($series): ?>
      <section class="sec sp-w4 sp-act">
        <h2>Activity</h2>
        <div class="sp-series">
          <?php foreach ($series as [$title, $total, $data, $since, $cls]): $note = $sinceNote($since); ?>
          <div class="sp-s">
            <p class="sp-s-h"><span><?= e($title) ?><?= $note !== '' ? ' <small>· ' . e($note) . '</small>' : '' ?></span><b><?= number_format($total) ?></b></p>
            <?= $chart($data, $cls, $since, ['Logins' => 'login', 'New Likes' => 'new like', 'New Comments' => 'comment', 'New Bug Reports' => 'report'][$title]) ?>
          </div>
          <?php endforeach ?>
        </div>
        <div class="sp-axis"><span><?= e($axis[0]) ?></span><span><?= e($axis[2]) ?></span></div>
      </section>
      <?php endif ?>

      <section class="sec sp-w5">
        <h2>New Versions <span class="sp-legend">Have It<?= tip('Of the patrons in the log who downloaded the item, how many have the new version as their last download.') ?></span></h2>
        <?php if (!$fresh): ?><p class="muted">No update of <?= $scope === 'site' ? 'a model or accessory' : ($kind === 'outfits' ? 'an accessory' : 'a model') ?> in this period.</p><?php else: ?>
        <ul class="sp-bars sp-fresh">
          <?php foreach (array_slice($fresh, 0, 10) as [$t, $k, $it, $label]): $ik = m3d_log_item($k, (string) $it['slug']); $all = count($last[$ik] ?? []); $n = count(array_filter($last[$ik] ?? [], fn($v) => $v !== '' && m3d_ver_key($v) === m3d_ver_key($label))); ?>
          <li><a href="<?= e($itemUrl($k, $it)) ?>"><?= e($name($k, $it) . ' ' . $verLabel($label)) ?> <small>· out <?= e(m3d_site_time($t, 'j M')) ?></small></a><b title="<?= e("$n of the $all patrons who downloaded it") ?>"><?= $pct($n, $all) ?>%</b><span class="bar"><i style="width:<?= $pct($n, $all) ?>%"></i></span></li>
          <?php endforeach ?>
        </ul>
        <?php endif ?>
      </section>
<?php endif ?>

      <section class="sec <?= $item ? '' : 'sp-w7' ?>">
        <h2>When Patrons Download <span class="sp-legend">site time (<?= e(m3d_site_time($now, 'T')) ?>)</span></h2>
        <?php if (!$dl): ?><p class="muted">No downloads in this period.</p><?php else: ?>
        <div class="sp-heat" role="img" aria-label="<?= e('Busiest: ' . $wdNames[$busy[0]] . ' ' . sprintf('%02d:00', $busy[1])) ?>">
          <span></span><?php for ($h = 0; $h < 24; $h++): ?><span class="hr<?= $h % 6 ? ' odd' : '' ?>"><?= $h % 3 ? '' : $h ?></span><?php endfor ?>
          <?php foreach ($heat as $wd => $hours): ?><span class="wd"><?= substr($wdNames[$wd], 0, 3) ?></span><?php foreach ($hours as $h => $n): ?><i style="--a:<?= $n ? round(0.15 + 0.85 * $n / $heatMax, 2) : 0 ?>" title="<?= e($wdNames[$wd] . ' ' . sprintf('%02d:00–%02d:00', $h, ($h + 1) % 24) . ': ' . m3d_plural($n, 'download')) ?>"></i><?php endforeach; endforeach ?>
        </div>
        <p class="sp-foot">Busiest: <?= e($wdNames[$busy[0]] . ' ' . sprintf('%02d:00–%02d:00', $busy[1], ($busy[1] + 1) % 24)) ?>.</p>
        <?php endif ?>
      </section>

      <?php if ($stillsShown): ?>
      <section class="sec">
        <h2><?= $item ? 'Gallery Pictures' : 'Most Liked Gallery Pictures' ?> <a href="/admin/gallery.php">Gallery →</a></h2>
        <div class="sp-pics">
          <?php foreach ($stillsShown as [$lk, $sd, $st]): ?>
          <a href="/admin/gallery.php" title="<?= e((string) ($st['caption'] ?? '')) ?>"><img src="<?= e(img_small((string) $st['src'])) ?>" alt="" loading="lazy" decoding="async" /><span class="sp-pc"><span>♥ <?= $lk ?></span><?php if ($sd): ?><span>⤓ <?= $sd ?></span><?php endif ?></span></a>
          <?php endforeach ?>
        </div>
      </section>
      <?php endif ?>
    </div>
<?php admin_end();
