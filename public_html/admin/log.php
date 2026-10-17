<?php
// Downloads: all-time totals (counters + likes), then the patron download log with filters, search and CSV export.
require __DIR__ . '/_admin.php';
admin_require($M3D);

// remove one patron's downloads and unlocks (button shown when the log is filtered by that patron; asks first)
$removed = null;
if (admin_form_post('remove-all')) {
    // every patron at once: the whole log goes (like Settings → Clear download log); the all-time counters stay
    m3d_redirect('/admin/log.php?removed=' . m3d_log_clear() . '&all=1#log');
}
if (admin_form_post('remove-patron')) {
    $who = (string) ($_POST['patron'] ?? '');
    if (preg_match('/^[0-9A-Za-z_-]{1,40}$/', $who)) {
        $n = m3d_log_remove_patron($who);
        m3d_redirect('/admin/log.php?removed=' . $n . '#log');
    }
}
if (admin_form_post('remove-rows')) {
    // the ticked lines only (codes from m3d_log_row_key()); back to the same filtered view
    $n = m3d_log_remove_rows(array_slice((array) ($_POST['rows'] ?? []), 0, 500));
    $back = (string) ($_POST['back'] ?? '');
    $back = preg_match('~^/admin/log\.php(\?[^#\s]*)?$~', $back) ? $back : '/admin/log.php';
    m3d_redirect($back . (str_contains($back, '?') ? '&' : '?') . 'removed=' . $n . '&rows=1#log');
}
if (ctype_digit(m3d_get_text('removed'))) $removed = (int) m3d_get_text('removed');
$file = m3d_log_file();
$rows = [];
if (is_file($file) && ($fh = fopen($file, 'rb'))) {
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) if (count($r) >= 4) $rows[] = array_pad($r, 6, '');   // 6th column (version) only on newer lines
    fclose($fh);
}
// generation tag per model; two models of one character with the same generation also get "· v2" (major version, else their place), like the Images folder picker
$genTag = $sameGen = [];
foreach (m3d_models() as $m) $sameGen[$m['name'] . '|' . ($m['franchise'] ?? '') . '|' . ($m['generation'] ?? '')][] = $m;
foreach ($sameGen as $same) {
    usort($same, fn($a, $b) => (int) ($a['majorVersion'] ?? 0) <=> (int) ($b['majorVersion'] ?? 0));
    foreach ($same as $i => $m) $genTag[$m['slug']] = implode(' · ', array_filter([
        ($m['generation'] ?? '') !== '' ? 'Gen ' . $m['generation'] : '',
        count($same) > 1 ? 'v' . (!empty($m['majorVersion']) ? (int) $m['majorVersion'] : $i + 1) : '',
    ]));
}
$names = [];   // log item => its name in the menus and the search ("Alice (Gen 1)", "Dress · Outfit")
$tags = [];   // log item => [name, tag, kind, item] for the table (the name, then the generation / type as a small tag)
foreach (m3d_models() as $m) {
    $names[$m['slug']] = $m['name'] . ($genTag[$m['slug']] !== '' ? ' (' . $genTag[$m['slug']] . ')' : '');
    $tags[$m['slug']] = [$m['name'], $genTag[$m['slug']], 'models', $m];
}
foreach (m3d_outfits() as $o) {
    $key = m3d_log_item('outfits', $o['slug']);
    $names[$key] = $o['name'] . ' · ' . m3d_accessory_type($o);
    $tags[$key] = [$o['name'], m3d_accessory_type($o), 'outfits', $o];
}
// A small picture next to each name (the "-xs" thumb, lazy): admins see every picture, so never blurred; the kind's icon without one.
$pic = function (?array $it, string $kind, string $icon): string {
    static $done = [];
    $key = $kind . ':' . ($it['slug'] ?? '');
    if (isset($done[$key])) return $done[$key];
    $src = !$it ? '' : item_pic($kind, $it);
    return $done[$key] = '<span class="tot-pic" aria-hidden="true">' . ($src !== ''
        ? '<img src="' . e(img_thumb($src)) . '" alt="" loading="lazy" decoding="async" />'
        : '<svg viewBox="0 0 24 24">' . $icon . '</svg>') . '</span>';
};
$patrons = [];
foreach ($rows as [, $id]) $patrons[$id] = true;

// ── Filters (all in the address, so a filtered view can be bookmarked and exported) ──
$date = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
// the log stores UTC (ISO, +00:00); shown and filtered in site time (CET/CEST), like the rest of the admin
$berlin = function (string $d, string $fmt = 'Y-m-d H:i:s'): string {
    try { return (new DateTimeImmutable($d, new DateTimeZone('UTC')))->setTimezone(m3d_tz())->format($fmt); }
    catch (Exception) { return $d; }
};
// the membership saved with a line (m3d_log_membership()): "4 mo" in the Patron column, the rest in its hover text
$memText = function (array $m) use ($berlin): string {
    return implode(' · ', array_filter([
        $m['months'] !== null ? m3d_plural($m['months'], 'month') . ' as a patron' . ($m['since'] !== '' ? ' (since ' . $berlin($m['since'] . 'T12:00:00Z', 'j M Y') . ')' : '') : '',
        $m['tier_cents'] !== null ? m3d_tier_label($m['tier_cents']) : '',
        $m['total_cents'] !== null ? m3d_money($m['total_cents']) . ' total support' : '',
    ]));
};
$f = [
    'q'      => trim(mb_substr(m3d_get_text('q'), 0, 100)),
    'item'   => mb_substr(m3d_get_text('item'), 0, 200),   // an address, or "<name> (removed <date>)" for a deleted item (admin_forget_items())
    'patron' => preg_match('/^[0-9A-Za-z_-]{1,40}$/', m3d_get_text('patron')) ? m3d_get_text('patron') : '',
    'link'   => trim(mb_substr(m3d_get_text('link'), 0, 60)),
    'from'   => $date($_GET['from'] ?? ''),
    'to'     => $date($_GET['to'] ?? ''),
    'sort'   => ($_GET['sort'] ?? '') === 'old' ? 'old' : 'new',
];
$words = array_filter(preg_split('/\s+/', mb_strtolower($f['q'])));
$match = function (array $r) use ($f, $words, $names, $berlin): bool {
    [$d, $id, $name, $item, $link, $ver] = $r;
    if ($f['item'] !== '' && $item !== $f['item']) return false;
    if ($f['patron'] !== '' && $id !== $f['patron']) return false;
    if ($f['link'] !== '' && $link !== $f['link']) return false;
    if ($f['from'] !== '' && $berlin($d, 'Y-m-d') < $f['from']) return false;
    if ($f['to'] !== '' && $berlin($d, 'Y-m-d') > $f['to']) return false;
    if ($words) {
        $hay = mb_strtolower("$name $id $item " . ($names[$item] ?? '') . " $link $ver");
        foreach ($words as $w) if (!str_contains($hay, $w)) return false;
    }
    return true;
};
$found = array_values(array_filter($rows, $match));
if ($f['sort'] === 'new') $found = array_reverse($found);
$filtered = array_filter($f, fn($v, $k) => $v !== '' && $k !== 'sort', ARRAY_FILTER_USE_BOTH) !== [];
$url = function (array $change = []) use ($f): string {
    $q = array_filter(array_merge($f, ['page' => null], $change), fn($v, $k) => $v !== '' && $v !== null && !($k === 'sort' && $v === 'new') && !($k === 'page' && (int) $v <= 1), ARRAY_FILTER_USE_BOTH);
    return '/admin/log.php' . ($q ? '?' . http_build_query($q) : '') . '#log';
};

if (isset($_GET['csv'])) {   // the rows of the current view
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="downloads-' . gmdate('Y-m-d') . ($filtered ? '-filtered' : '') . '.csv"');
    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");   // so Excel reads it as UTF-8 (names with accents)
    fputcsv($out, ['date_utc', 'patreon_id', 'name', 'item', 'link', 'version', 'note', 'months_as_patron', 'patron_since', 'tier_cents', 'total_support_cents'], ',', '"', '');
    // a cell starting with = + - @ would run as a formula in a spreadsheet (patrons choose their own Patreon name): a ' in front keeps it text
    $cell = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v;
    foreach ($found as $r) fputcsv($out, array_map($cell, array_slice(array_pad($r, 11, ''), 0, 11)), ',', '"', '');
    exit;
}

// summary of the current view: patrons, top patrons and items
$byPatron = $byItem = $pname = [];
foreach ($found as [, $id, $name, $item]) {
    $byPatron[$id] = ($byPatron[$id] ?? 0) + 1;
    $pname[$id] = $name;
    $byItem[$item] = ($byItem[$item] ?? 0) + 1;
}
arsort($byPatron); arsort($byItem);
$itemCounts = [];
foreach ($rows as [, , , $item]) $itemCounts[$item] = ($itemCounts[$item] ?? 0) + 1;
$labels = array_values(array_unique(array_filter(array_column($rows, 4))));
sort($labels);

const PER_PAGE = 50;
$pages = max(1, (int) ceil(count($found) / PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$shown = array_slice($found, ($page - 1) * PER_PAGE, PER_PAGE);
// "Last N days" = N calendar days (site time) with today: from N - 1 days ago (Admin → Home links its 7-day tile the same way)
$daysAgo = fn(int $n) => (new DateTimeImmutable('today', m3d_tz()))->modify('-' . ($n - 1) . ' days')->format('Y-m-d');

// All-time totals (counters, they don't expire like the log)
$stats = m3d_stats();
$all = [];
foreach (m3d_models() as $m) $all[] = ['name' => $m['name'], 'tag' => $genTag[$m['slug']], 'kind' => 'models', 'find' => $m['franchise'] ?? '', 'key' => $m['slug'], 'href' => '/models/' . $m['slug'] . '/', 'likes' => m3d_like_count($m['slug']), 'item' => $m] + m3d_downloads('models', $m['slug'], $stats);
foreach (m3d_outfits() as $o) $all[] = ['name' => $o['name'], 'tag' => m3d_accessory_type($o), 'kind' => 'outfits', 'find' => 'accessory', 'key' => m3d_log_item('outfits', $o['slug']), 'href' => m3d_item_url('outfits', $o['slug']), 'likes' => m3d_like_count($o['slug'], 'outfits'), 'item' => $o] + m3d_downloads('outfits', $o['slug'], $stats);
usort($all, fn($a, $b) => [$b['total'], $b['likes']] <=> [$a['total'], $a['likes']]);
$maxTotal = max(1, ...array_column($all, 'total') ?: [0]);
$kinds = array_count_values(array_column($all, 'kind'));
$sum = fn($k) => array_sum(array_column($all, $k));
$since = substr((string) ($stats['since'] ?? ''), 0, 10);

// small line icons for the tiles and the column titles of both tables
const LOG_ICONS = [
    'dl'      => '<path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
    'patrons' => '<path fill="currentColor" stroke="none" d="' . SOCIAL_TYPES['patreon'][2] . '"/>',
    'free'    => '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V7.5a4 4 0 0 1 7.6-1.7"/>',
    'likes'   => '<path d="M12 20s-7.5-4.6-7.5-10.2A4.2 4.2 0 0 1 12 7.3a4.2 4.2 0 0 1 7.5 2.5C19.5 15.4 12 20 12 20z"/>',
    'item'    => '<path d="M12 3 3 7.5l9 4.5 9-4.5z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
    'date'    => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/>',
    'patron'  => '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>',
    'version' => '<path d="M3.5 12.6V5A1.5 1.5 0 0 1 5 3.5h7.6l8 8-9.1 9.1z"/><circle cx="8.2" cy="8.2" r="1.4"/>',
    'link'    => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
];
$ico = fn(string $k): string => '<svg class="h-ico" viewBox="0 0 24 24" aria-hidden="true">' . LOG_ICONS[$k] . '</svg>';

admin_start('Downloads', 'log');
?>
    <div class="admin-head">
      <div>
        <h1>Downloads</h1>
      </div>
    </div>
    <section class="sec dl-totals">
      <h2 class="small-h">All-Time Totals<?= tip('Every download click, patron and free' . ($since ? ', since ' . $since : '') . '.') ?></h2>
      <!-- the sums, always in view (the list below scrolls); they follow the search and the Models / Accessories choice -->
      <div class="stats" data-tot-sums>
        <div class="stat stat-i stat-dl"><span class="stat-ico"><?= $ico('dl') ?></span><b data-sum="total"><?= number_format($sum('total')) ?></b><span>Downloads</span></div>
        <div class="stat stat-i stat-pat"><span class="stat-ico"><?= $ico('patrons') ?></span><b data-sum="patrons"><?= number_format($sum('patrons')) ?></b><span>By Patrons</span></div>
        <div class="stat stat-i stat-free"><span class="stat-ico"><?= $ico('free') ?></span><b data-sum="public"><?= number_format($sum('public')) ?></b><span>Free</span></div>
        <div class="stat stat-i stat-likes"><span class="stat-ico"><?= $ico('likes') ?></span><b data-sum="likes"><?= number_format($sum('likes')) ?></b><span>Likes</span></div>
      </div>
      <?php if ($all): ?>
      <div class="tot-bar">
        <?php if (count($kinds) > 1): ?>
          <span class="tot-kinds" role="group" aria-label="Show">
            <button type="button" class="chip" data-tot-kind="" aria-pressed="true">All <span><?= count($all) ?></span></button>
            <button type="button" class="chip" data-tot-kind="models" aria-pressed="false">Models <span><?= $kinds['models'] ?></span></button>
            <button type="button" class="chip" data-tot-kind="outfits" aria-pressed="false">Accessories <span><?= $kinds['outfits'] ?></span></button>
          </span>
        <?php endif ?>
        <span class="muted small tot-count" data-tot-count hidden></span>
        <label class="batch-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="input" type="search" data-tot-search placeholder="Search name, franchise, type…" aria-label="Search the totals" autocomplete="off" spellcheck="false" /></label>
      </div>
      <?php endif ?>
      <div class="table-wrap">
        <table class="log-table tot-table" data-tot-table>
          <colgroup><col /><col class="tot-w" /><col class="tot-w" /><col class="tot-w" /><col class="tot-w" /><col class="tot-log" /></colgroup>
          <thead><tr>
            <th aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="name"><?= $ico('item') ?>Model / Accessory</button></th>
            <th class="c-num" aria-sort="descending"><button type="button" class="tot-sort" data-tot-sort="total"><?= $ico('dl') ?>Downloads</button></th>
            <th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="patrons"><?= $ico('patrons') ?>By Patrons</button></th>
            <th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="public"><?= $ico('free') ?>Free</button></th>
            <th class="c-num" aria-sort="none"><button type="button" class="tot-sort" data-tot-sort="likes"><?= $ico('likes') ?>Likes</button></th>
            <th><span class="sr-only">Patron Log</span></th>
          </tr></thead>
          <tbody>
            <?php foreach ($all as $r): $icon = $r['kind'] === 'outfits' ? accessory_icon_paths($r['tag']) : LOG_ICONS['patron']; ?>
              <tr class="<?= $r['total'] ? '' : 'tot-zero' ?>" data-kind="<?= e($r['kind']) ?>" data-name="<?= e(mb_strtolower($r['name'] . ' ' . $r['tag'])) ?>" data-search="<?= e(implode(' ', [$r['name'], $r['tag'], $r['find'], $r['key']])) ?>" data-total="<?= $r['total'] ?>" data-patrons="<?= $r['patrons'] ?>" data-public="<?= $r['public'] ?>" data-likes="<?= $r['likes'] ?>">
                <td><span class="tot-item"><?= $pic($r['item'], $r['kind'], $icon) ?><span class="tot-name"><a href="<?= e($r['href']) ?>" target="_blank"><?= e($r['name']) ?></a><?php if ($r['tag'] !== ''): ?><span class="tot-tag tot-tag-<?= e($r['kind']) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= e($r['tag']) ?></span><?php endif ?></span></span></td>
                <td class="c-num"><span class="tot-dl"><span class="tot-meter" title="<?= e($r['patrons'] . ' by patrons, ' . $r['public'] . ' free') ?>" aria-hidden="true"><i style="width: <?= round($r['patrons'] / $maxTotal * 100, 1) ?>%"></i><i style="width: <?= round($r['public'] / $maxTotal * 100, 1) ?>%"></i></span><b class="num"><?= number_format($r['total']) ?></b></span></td>
                <td class="c-num"><?= number_format($r['patrons']) ?></td>
                <td class="c-num"><?= number_format($r['public']) ?></td>
                <td class="c-num"><?= $r['likes'] ? '<span class="likes-num">♥ ' . $r['likes'] . '</span>' : '<span class="muted">0</span>' ?></td>
                <td class="c-num"><?php if (!empty($itemCounts[$r['key']])): ?><a class="log-jump" href="<?= e($url(['item' => $r['key'], 'q' => '', 'patron' => '', 'link' => ''])) ?>" title="Show these downloads in the patron log" aria-label="<?= e('Patron log of ' . $r['name']) ?>">log ↓</a><?php endif ?></td>
              </tr>
            <?php endforeach ?>
            <tr class="tot-empty" data-tot-empty hidden><td colspan="6" class="muted"></td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="sec log-sec" id="log">
    <div class="dl-log-head">
      <div>
        <h2 class="small-h">Patron Download Log<?= tip('Downloads of patron links from the last ' . (int) ($M3D['log_retention_days'] ?? 365) . ' days, in the site’s time zone (' . e(str_replace('_', ' ', m3d_tz_id())) . '). It also decides what each patron has unlocked.') ?></h2>
      </div>
      <?php if ($rows): ?>
        <div class="log-actions">
          <a class="btn btn-ghost btn-sm" href="<?= e(str_replace('#log', '', $url(['csv' => 1]))) ?>">Export <?= $filtered ? 'These ' . number_format(count($found)) : 'All' ?> as CSV</a>
          <form method="post" class="log-remove" onsubmit="return confirm(<?= e(json_encode('Are you sure? This removes the downloads of EVERY patron (' . count($rows) . ' in the log): all download histories, My Models lists and unlocks. Everyone\'s limits start again from zero. The all-time download counts stay. This cannot be undone: export the log as CSV first if you want a copy (the automatic weekly backups also have it).')) ?>)">
            <?= form_keys() ?>
            <button class="btn btn-sm btn-danger" name="do" value="remove-all">Reset Everyone&rsquo;s Unlocks</button>
          </form>
        </div>
      <?php endif ?>
    </div>
    <?php if ($removed !== null && !empty($_GET['rows'])) admin_flash($removed ? '✓ ' . m3d_plural($removed, 'download') . ' removed from the log.' : 'Nothing removed: those downloads were already gone.', $removed ? 'ok' : 'info');
    elseif ($removed !== null) { $all = !empty($_GET['all']); admin_flash($removed ? '✓ ' . m3d_plural($removed, 'download') . ' removed: ' . ($all ? 'every patron starts' : 'that patron starts') . ' again with nothing unlocked.' : 'Nothing to remove: ' . ($all ? 'the log was already empty.' : 'that patron had no downloads in the log.'), $removed ? 'ok' : 'info'); } ?>
    <?php if (!$rows): ?>
      <p class="muted">No downloads logged yet.</p>
    <?php else: ?>
      <?php $period = $f['from'] === '' && $f['to'] === '' ? 'all' : 'custom';
      foreach ([7, 30, 90] as $n) if ($f['from'] === $daysAgo($n) && $f['to'] === '') $period = (string) $n; ?>
      <!-- one row: search (Enter), model, link, period; the dates show only for "Custom dates"; the order is the Date column's title -->
      <form class="log-filters" method="get" action="/admin/log.php#log" data-autosubmit>
        <label class="batch-search log-q"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search patron, ID, model, version… (Enter)" aria-label="Search the log" /></label>
        <select class="input" name="item" aria-label="Model or accessory">
          <option value="">All Models and Accessories</option>
          <?php foreach ($itemCounts as $k => $n): ?><option value="<?= e($k) ?>" <?= $f['item'] === (string) $k ? 'selected' : '' ?>><?= e($names[$k] ?? $k) ?> (<?= $n ?>)</option><?php endforeach ?>
        </select>
        <?php if (count($labels) > 1): ?>
          <select class="input" name="link" aria-label="Link">
            <option value="">All Links</option>
            <?php foreach ($labels as $l): ?><option value="<?= e($l) ?>" <?= $f['link'] === $l ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
          </select>
        <?php endif ?>
        <select class="input log-period" aria-label="When" data-log-period>
          <option value="all" data-from="" <?= $period === 'all' ? 'selected' : '' ?>>All Time</option>
          <?php foreach ([7, 30, 90] as $n): ?><option value="<?= $n ?>" data-from="<?= e($daysAgo($n)) ?>" <?= $period === (string) $n ? 'selected' : '' ?>>Last <?= $n ?> Days</option><?php endforeach ?>
          <option value="custom" <?= $period === 'custom' ? 'selected' : '' ?>>Custom Dates…</option>
        </select>
        <span class="log-dates" data-log-dates <?= $period === 'custom' ? '' : 'hidden' ?>>
          <input class="input" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From" title="From" />
          <span class="muted">–</span>
          <input class="input" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To" title="To" />
        </span>
        <?php if ($f['sort'] === 'old'): ?><input type="hidden" name="sort" value="old" /><?php endif ?>
        <?php if ($f['patron'] !== ''): ?><input type="hidden" name="patron" value="<?= e($f['patron']) ?>" /><?php endif ?>
        <noscript><button class="btn btn-ghost btn-sm">Apply</button></noscript>
      </form>

      <?php if ($f['patron'] !== ''): $who = $pname[$f['patron']] ?? $f['patron']; $mine = array_filter($rows, fn($r) => $r[1] === $f['patron']); $dates = array_column($mine, 0);
        $lastMem = null;   // the membership saved with their newest download that has one
        foreach ($mine as $r) if (($m = m3d_log_membership($r)) && ($lastMem === null || strcmp($r[0], $lastMem[0]) >= 0)) $lastMem = [$r[0], $m]; ?>
        <!-- filtered by one patron: who they are, their totals, and the reset for them only -->
        <div class="log-patron">
          <div>
            <p class="muted small">Patron</p>
            <b class="log-patron-name"><?= e($who !== '' ? $who : $f['patron']) ?></b>
            <span class="muted small">Patreon ID <?= e($f['patron']) ?> · <?= m3d_plural(count($mine), 'download') ?> · <?php $ni = count(array_unique(array_column($mine, 3))); ?><?= $ni ?> <?= $ni === 1 ? 'model/accessory' : 'models/accessories' ?><?= $dates ? ' · first ' . e($berlin(min($dates), 'j M Y')) . ', last ' . e($berlin(max($dates), 'j M Y')) : '' ?></span>
            <?php if ($lastMem): ?><span class="muted small log-patron-mem">Membership on <?= e($berlin($lastMem[0], 'j M Y')) ?>: <?= e($memText($lastMem[1])) ?></span><?php endif ?>
          </div>
          <div class="log-patron-btns">
            <?php if (preg_match('/^\d{1,20}$/', $f['patron'])): $hasEx = m3d_exception($f['patron']) !== null; ?>
            <form method="post" action="/admin/patron-preview.php">
              <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="return" value="/account/" />
              <input type="hidden" name="tier" value="ok" /><input type="hidden" name="as" value="<?= e($f['patron']) ?>" /><input type="hidden" name="dl_models" value="0" /><input type="hidden" name="dl_outfits" value="0" /><input type="hidden" name="updates" value="0" /><input type="hidden" name="real" value="1" />
              <button class="btn btn-sm btn-preview" name="do" value="apply" title="<?= e('Their My Account page, with their real downloads and their exception' . ($lastMem ? ', and the membership saved with their newest download' : '')) ?>">Preview as This Patron</button>
            </form>
            <a class="btn btn-ghost btn-sm" href="/admin/users.php?<?= e($hasEx ? 'ex=' . rawurlencode($f['patron']) . '#ex-' . $f['patron'] : http_build_query(['add' => $f['patron'], 'name' => $who !== $f['patron'] ? $who : '']) . '#add-user') ?>"><?= $hasEx ? 'Edit Exception' : 'Add Exception' ?></a>
            <?php endif ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($url(['patron' => ''])) ?>">Show All Patrons</a>
            <form method="post" class="log-remove" onsubmit="return confirm(<?= e(json_encode('Are you sure? This removes ALL downloads of ' . $who . ' (Patreon id ' . $f['patron'] . ') from the log: their download history, My Models and everything they unlocked. Their limits start again from zero. The all-time download counts stay. This cannot be undone (the automatic weekly backups still have the old log).')) ?>)">
              <?= form_keys() ?><input type="hidden" name="patron" value="<?= e($f['patron']) ?>" />
              <button class="btn btn-sm btn-danger" name="do" value="remove-patron">Reset This Patron&rsquo;s Unlocks</button>
            </form>
          </div>
        </div>
      <?php endif ?>

      <!-- what the view holds; the top patrons and items fold under it -->
      <div class="log-sumbar">
        <p class="log-summary"><b><?= number_format(count($found)) ?></b> <?= count($found) === 1 ? 'download' : 'downloads' ?> by <b><?= number_format(count($byPatron)) ?></b> <?= count($byPatron) === 1 ? 'patron' : 'patrons' ?><?= count($byItem) > 1 ? ' · <b>' . count($byItem) . '</b> models and accessories' : '' ?></p>
        <?php if ($filtered): ?><a class="btn btn-ghost btn-sm" href="/admin/log.php#log">Clear Filters</a><?php endif ?>
      </div>
      <?php if (count($byPatron) > 1 || count($byItem) > 1):
        $top = function (array $counts, callable $label, callable $href): string {   // a short ranked list: name, bar, count
            $max = max($counts) ?: 1; $h = '<ol class="log-rank">';
            foreach (array_slice($counts, 0, 6, true) as $k => $n) $h .= '<li><a href="' . e($href((string) $k)) . '"><span class="log-rank-n">' . $label((string) $k) . '</span><span class="log-rank-bar"><i style="width: ' . round($n / $max * 100) . '%"></i></span><b>' . $n . '</b></a></li>';
            return $h . '</ol>';
        }; ?>
        <details class="log-tops">
          <summary>Top Patrons and Models</summary>
          <div class="log-tops-grid">
            <?php if (count($byPatron) > 1): ?><div><p class="muted small">Most Downloads</p><?= $top($byPatron, fn($id) => e(($pname[$id] ?? '') ?: $id), fn($id) => $url(['patron' => $id])) ?></div><?php endif ?>
            <?php if (count($byItem) > 1): ?><div><p class="muted small">Most Downloaded</p><?= $top($byItem, fn($k) => e($names[$k] ?? $k), fn($k) => $url(['item' => $k])) ?></div><?php endif ?>
          </div>
        </details>
      <?php endif ?>

      <?php if (!$found): ?>
        <p class="muted">No downloads match these filters. <a href="/admin/log.php#log">Clear the filters</a>.</p>
      <?php else: ?>
        <!-- tick rows to remove them (frees the unlock when it was the patron's only download of that item) -->
        <form method="post" class="log-rows" data-log-rows>
          <?= form_keys() ?>
          <input type="hidden" name="back" value="<?= e(str_replace('#log', '', $url(['page' => $page]))) ?>" />
          <div class="batch-bar log-selbar" data-log-selbar hidden>
            <span><b data-log-selcount>0</b> selected</span>
            <button type="button" class="btn btn-ghost btn-sm" data-log-selnone>Clear</button>
            <button class="btn btn-sm btn-danger" name="do" value="remove-rows">Remove from the Log</button>
          </div>
          <div class="table-wrap">
            <table class="log-table log-list">
              <thead><tr>
                <th class="c-check"><input type="checkbox" data-log-selall aria-label="Select every row on this page" /></th>
                <th aria-sort="<?= $f['sort'] === 'old' ? 'ascending' : 'descending' ?>"><a class="tot-sort" href="<?= e($url(['sort' => $f['sort'] === 'old' ? 'new' : 'old'])) ?>" title="<?= $f['sort'] === 'old' ? 'Oldest first: show newest first' : 'Newest first: show oldest first' ?>"><?= $ico('date') ?>Date</a></th>
                <th><span class="th-i"><?= $ico('patron') ?>Patron</span></th><th><span class="th-i"><?= $ico('item') ?>Model / Accessory</span></th><th><span class="th-i"><?= $ico('version') ?>Version</span></th><th><span class="th-i"><?= $ico('link') ?>Link</span></th>
              </tr></thead>
              <tbody>
                <?php foreach ($shown as $r): [$d, $id, $name, $item, $link, $ver] = $r; [$iname, $itag, $ikind, $iitem] = $tags[$item] ?? [$names[$item] ?? $item, '', m3d_log_item_parts($item)[0], null]; ?>
                  <tr>
                    <td class="c-check"><input type="checkbox" name="rows[]" value="<?= e(m3d_log_row_key($r)) ?>" aria-label="<?= e('Select ' . $name . ', ' . $iname . ', ' . $berlin($d)) ?>" /></td>
                    <td class="log-when" title="<?= e($berlin($d, 'Y-m-d H:i:s T')) ?>"><?= e($berlin($d, 'Y-m-d')) ?> <span class="muted"><?= e($berlin($d, 'H:i')) ?></span></td>
                    <td><a class="log-who" href="<?= e($url(['patron' => $id])) ?>" title="Only this patron"><?= e($name !== '' ? $name : $id) ?></a><span class="log-id muted"><?= e($id) ?><?php if (($mem = m3d_log_membership($r)) && $mem['months'] !== null): ?> · <span class="tip log-mem" tabindex="0" data-tip="<?= e('At this download: ' . $memText($mem)) ?>"><?= $mem['months'] ?> mo</span><?php endif ?></span></td>
                    <td><span class="tot-item"><?= $pic($iitem, $ikind, $ikind === 'outfits' ? accessory_icon_paths($itag) : LOG_ICONS['patron']) ?><span class="tot-name"><a class="log-who" href="<?= e($url(['item' => $item])) ?>" title="Only this model or accessory"><?= e($iname) ?></a><?php if ($itag !== ''): ?><span class="tot-tag tot-tag-<?= e($ikind) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><?= $ikind === 'outfits' ? accessory_icon_paths($itag) : LOG_ICONS['patron'] ?></svg><?= e($itag) ?></span><?php endif ?></span></span></td>
                    <td class="log-ver"><?= $ver !== '' ? e($ver) : '<span class="muted">—</span>' ?></td>
                    <td class="muted"><?= e($link) ?></td>
                  </tr>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        </form>
        <?php if ($pages > 1): ?>
          <?php // page numbers: first, last, and two on each side of the current one; gaps become "…"
            $nums = array_values(array_unique(array_filter([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages], fn($n) => $n >= 1 && $n <= $pages)));
            sort($nums); ?>
          <nav class="log-pages" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($url(['page' => $page - 1])) ?>" aria-label="Previous page">←</a><?php endif ?>
            <span class="page-nums">
              <?php foreach ($nums as $i => $n): ?>
                <?php if ($i > 0 && $n > $nums[$i - 1] + 1): ?><span class="muted page-gap">…</span><?php endif ?>
                <?php if ($n === $page): ?><span class="page-num" aria-current="page"><?= $n ?></span>
                <?php else: ?><a class="page-num" href="<?= e($url(['page' => $n])) ?>"><?= $n ?></a><?php endif ?>
              <?php endforeach ?>
            </span>
            <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= e($url(['page' => $page + 1])) ?>" aria-label="Next page">→</a><?php endif ?>
          </nav>
          <p class="muted small log-range"><?= number_format(($page - 1) * PER_PAGE + 1) ?>–<?= number_format(min(count($found), $page * PER_PAGE)) ?> of <?= number_format(count($found)) ?></p>
        <?php endif ?>
      <?php endif ?>
    <?php endif ?>
    </section>
<?php admin_end();
