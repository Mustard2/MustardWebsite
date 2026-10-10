<?php
// Downloads: all-time totals (counters + likes), then the patron download log with filters, search and CSV export.
require __DIR__ . '/_admin.php';
admin_require($M3D);

// remove one patron's downloads and unlocks (button shown when the log is filtered by that patron; asks first)
$removed = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'remove-all') {
    // every patron at once: the whole log goes (like Settings → Clear download log); the all-time counters stay
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $f = M3D_PRIVATE . '/logs/downloads.csv';
    $n = 0;
    if (is_file($f) && ($fh = fopen($f, 'rb'))) { while (fgets($fh) !== false) $n++; fclose($fh); }
    if (is_file($f)) @unlink($f);
    m3d_redirect('/admin/log.php?removed=' . $n . '&all=1#log');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'remove-patron') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $who = (string) ($_POST['patron'] ?? '');
    if (preg_match('/^[0-9A-Za-z_-]{1,40}$/', $who)) {
        $n = m3d_log_remove_patron($who);
        m3d_redirect('/admin/log.php?removed=' . $n . '#log');
    }
}
if (isset($_GET['removed']) && ctype_digit((string) $_GET['removed'])) $removed = (int) $_GET['removed'];
$file = M3D_PRIVATE . '/logs/downloads.csv';
$rows = [];
if (is_file($file) && ($fh = fopen($file, 'rb'))) {
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) if (count($r) >= 4) $rows[] = array_pad($r, 6, '');   // 6th column (version) only on newer lines
    fclose($fh);
}
$names = [];
foreach (m3d_models() as $m) $names[$m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' (Gen ' . $m['generation'] . ')' : '');
foreach (m3d_outfits() as $o) $names['outfit:' . $o['slug']] = $o['name'] . ' (' . mb_strtolower(m3d_accessory_type($o)) . ')';
$patrons = [];
foreach ($rows as [, $id]) $patrons[$id] = true;

// ── Filters (all in the address, so a filtered view can be bookmarked and exported) ──
$date = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
// the log stores UTC (ISO, +00:00); shown and filtered in Berlin time, like the rest of the admin
$berlin = function (string $d, string $fmt = 'Y-m-d H:i:s'): string {
    try { return (new DateTimeImmutable($d, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format($fmt); }
    catch (Exception) { return $d; }
};
$f = [
    'q'      => trim(mb_substr((string) ($_GET['q'] ?? ''), 0, 100)),
    'item'   => preg_match('/^(outfit:)?[a-z0-9-]{1,64}$/', (string) ($_GET['item'] ?? $_GET['model'] ?? '')) ? (string) ($_GET['item'] ?? $_GET['model']) : '',
    'patron' => preg_match('/^[0-9A-Za-z_-]{1,40}$/', (string) ($_GET['patron'] ?? '')) ? (string) $_GET['patron'] : '',
    'link'   => trim(mb_substr((string) ($_GET['link'] ?? ''), 0, 60)),
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
    fputcsv($out, ['date_utc', 'patreon_id', 'name', 'item', 'link', 'version'], ',', '"', '');
    foreach ($found as $r) fputcsv($out, array_slice($r, 0, 6), ',', '"', '');
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
$daysAgo = fn(int $n) => (new DateTimeImmutable('@' . (time() - $n * 86400)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d');

// All-time totals (counters, they don't expire like the log)
$stats = m3d_stats();
$all = [];
foreach (m3d_models() as $m) $all[] = ['name' => $names[$m['slug']], 'key' => $m['slug'], 'href' => '/models/' . $m['slug'] . '/', 'likes' => m3d_like_count($m['slug'])] + m3d_downloads('models', $m['slug'], $stats);
foreach (m3d_outfits() as $o) $all[] = ['name' => $names['outfit:' . $o['slug']], 'key' => 'outfit:' . $o['slug'], 'href' => m3d_item_url('outfits', $o['slug']), 'likes' => m3d_like_count($o['slug'], 'outfits')] + m3d_downloads('outfits', $o['slug'], $stats);
usort($all, fn($a, $b) => [$b['total'], $b['likes'] ?? 0] <=> [$a['total'], $a['likes'] ?? 0]);
$sum = fn($k) => array_sum(array_column($all, $k));
$since = substr((string) ($stats['since'] ?? ''), 0, 10);

admin_start('Downloads', 'log');
?>
    <div class="admin-head">
      <div>
        <h1>Downloads</h1>
      </div>
    </div>
    <section class="sec dl-totals">
      <h2 class="small-h">All-time totals<?= tip('Every download click, patron and free' . ($since ? ', since ' . $since : '') . '.') ?></h2>
      <!-- the sums, always in view (the list below scrolls) -->
      <div class="stats">
        <div class="stat"><b><?= number_format($sum('total')) ?></b><span>downloads</span></div>
        <div class="stat"><b><?= number_format($sum('patrons')) ?></b><span>by patrons</span></div>
        <div class="stat"><b><?= number_format($sum('public')) ?></b><span>free</span></div>
        <div class="stat"><b><?= number_format($sum('likes')) ?></b><span>likes</span></div>
      </div>
      <div class="table-wrap">
        <table class="log-table">
          <thead><tr><th>Model / accessory</th><th class="c-num">Downloads</th><th class="c-num">By patrons</th><th class="c-num">Free</th><th class="c-num">Likes</th></tr></thead>
          <tbody>
            <?php foreach ($all as $r): ?>
              <tr><td><a href="<?= e($r['href']) ?>" target="_blank"><?= e($r['name']) ?></a><?php if (!empty($itemCounts[$r['key']])): ?> <a class="muted small log-jump" href="<?= e($url(['item' => $r['key'], 'q' => '', 'patron' => '', 'link' => ''])) ?>" title="Show these downloads in the patron log">log ↓</a><?php endif ?></td><td class="c-num num"><?= number_format($r['total']) ?></td><td class="c-num"><?= number_format($r['patrons']) ?></td><td class="c-num"><?= number_format($r['public']) ?></td><td class="c-num"><?= $r['likes'] === null ? '<span class="muted">—</span>' : ($r['likes'] ? '<span class="likes-num">♥ ' . $r['likes'] . '</span>' : '0') ?></td></tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="sec log-sec" id="log">
    <div class="log-head">
      <div>
        <h2 class="small-h">Patron download log<?= tip('Downloads of patron links from the last ' . (int) ($M3D['log_retention_days'] ?? 365) . ' days, in Berlin time. It also decides what each patron has unlocked.') ?></h2>
      </div>
      <?php if ($rows): ?>
        <div class="log-actions">
          <a class="btn btn-ghost btn-sm" href="<?= e(str_replace('#log', '', $url(['csv' => 1]))) ?>">Export <?= $filtered ? 'these ' . number_format(count($found)) : 'all' ?> as CSV</a>
          <form method="post" class="log-remove" onsubmit="return confirm(<?= e(json_encode('Are you sure? This removes the downloads of EVERY patron (' . count($rows) . ' in the log): all download histories, My Models lists and unlocks. Everyone\'s limits start again from zero. The all-time download counts stay. This cannot be undone: export the log as CSV first if you want a copy (the automatic weekly backups also have it).')) ?>)">
            <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" />
            <button class="btn btn-sm btn-danger" name="do" value="remove-all">Reset everyone&rsquo;s unlocks</button>
          </form>
        </div>
      <?php endif ?>
    </div>
    <?php if ($removed !== null): $all = !empty($_GET['all']); ?><p class="notice"><?= $removed ? '✓ ' . m3d_plural($removed, 'download') . ' removed: ' . ($all ? 'every patron starts' : 'that patron starts') . ' again with nothing unlocked.' : 'Nothing to remove: ' . ($all ? 'the log was already empty.' : 'that patron had no downloads in the log.') ?></p><?php endif ?>
    <?php if (!$rows): ?>
      <p class="muted">No downloads logged yet.</p>
    <?php else: ?>
      <form class="log-filters" method="get" action="/admin/log.php#log" data-autosubmit>
        <input class="input log-q" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search patron, Patreon id, model, version, link…" aria-label="Search the log" />
        <select class="input" name="item" aria-label="Model or accessory">
          <option value="">All models and accessories</option>
          <?php foreach ($itemCounts as $k => $n): ?><option value="<?= e($k) ?>" <?= $f['item'] === $k ? 'selected' : '' ?>><?= e($names[$k] ?? $k) ?> (<?= $n ?>)</option><?php endforeach ?>
        </select>
        <?php if (count($labels) > 1): ?>
          <select class="input" name="link" aria-label="Link">
            <option value="">All links</option>
            <?php foreach ($labels as $l): ?><option value="<?= e($l) ?>" <?= $f['link'] === $l ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
          </select>
        <?php endif ?>
        <label class="log-date"><span class="muted small">From</span><input class="input" type="date" name="from" value="<?= e($f['from']) ?>" /></label>
        <label class="log-date"><span class="muted small">To</span><input class="input" type="date" name="to" value="<?= e($f['to']) ?>" /></label>
        <select class="input" name="sort" aria-label="Order">
          <option value="new">Newest first</option>
          <option value="old" <?= $f['sort'] === 'old' ? 'selected' : '' ?>>Oldest first</option>
        </select>
        <?php if ($f['patron'] !== ''): ?><input type="hidden" name="patron" value="<?= e($f['patron']) ?>" /><?php endif ?>
        <button class="btn btn-ghost btn-sm">Apply</button>
      </form>

      <div class="log-bar">
        <span class="log-presets">
          <?php foreach (['Last 7 days' => 7, 'Last 30 days' => 30, 'Last 90 days' => 90] as $lbl => $n): $on = $f['from'] === $daysAgo($n) && $f['to'] === ''; ?>
            <a class="chip <?= $on ? 'on' : '' ?>" href="<?= e($url(['from' => $daysAgo($n), 'to' => ''])) ?>"><?= e($lbl) ?></a>
          <?php endforeach ?>
          <a class="chip <?= $f['from'] === '' && $f['to'] === '' ? 'on' : '' ?>" href="<?= e($url(['from' => '', 'to' => ''])) ?>">All time</a>
        </span>
        <?php if ($filtered): ?><a class="small muted" href="/admin/log.php#log">Clear all filters</a><?php endif ?>
      </div>

      <?php if ($f['patron'] !== ''): $who = $pname[$f['patron']] ?? $f['patron']; $mine = array_filter($rows, fn($r) => $r[1] === $f['patron']); $dates = array_column($mine, 0); ?>
        <!-- filtered by one patron: who they are, their totals, and the reset for them only -->
        <div class="log-patron">
          <div>
            <p class="muted small">Patron</p>
            <b class="log-patron-name"><?= e($who !== '' ? $who : $f['patron']) ?></b>
            <span class="muted small">Patreon ID <?= e($f['patron']) ?> · <?= m3d_plural(count($mine), 'download') ?> · <?php $ni = count(array_unique(array_column($mine, 3))); ?><?= $ni ?> <?= $ni === 1 ? 'model/accessory' : 'models/accessories' ?><?= $dates ? ' · first ' . e($berlin(min($dates), 'j M Y')) . ', last ' . e($berlin(max($dates), 'j M Y')) : '' ?></span>
          </div>
          <div class="log-patron-btns">
            <a class="btn btn-ghost btn-sm" href="<?= e($url(['patron' => ''])) ?>">Show all patrons</a>
            <form method="post" class="log-remove" onsubmit="return confirm(<?= e(json_encode('Are you sure? This removes ALL downloads of ' . $who . ' (Patreon id ' . $f['patron'] . ') from the log: their download history, My Models and everything they unlocked. Their limits start again from zero. The all-time download counts stay. This cannot be undone (the automatic weekly backups still have the old log).')) ?>)">
              <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="patron" value="<?= e($f['patron']) ?>" />
              <button class="btn btn-sm btn-danger" name="do" value="remove-patron">Reset this patron&rsquo;s unlocks</button>
            </form>
          </div>
        </div>
      <?php endif ?>
      <p class="log-summary"><b><?= number_format(count($found)) ?></b> <?= count($found) === 1 ? 'download' : 'downloads' ?> by <b><?= number_format(count($byPatron)) ?></b> <?= count($byPatron) === 1 ? 'patron' : 'patrons' ?><?= count($byItem) > 1 ? ' · <b>' . count($byItem) . '</b> models and accessories' : '' ?><?= $filtered ? ' <span class="muted">(filtered)</span>' : '' ?></p>

      <?php if (count($byPatron) > 1 || count($byItem) > 1): ?>
        <div class="log-tops">
          <?php if (count($byPatron) > 1): ?>
            <div><p class="muted small">Most downloads</p>
              <?php foreach (array_slice($byPatron, 0, 6, true) as $id => $n): ?><a class="chip" href="<?= e($url(['patron' => (string) $id])) ?>"><?= e($pname[$id] ?: $id) ?> <span><?= $n ?></span></a><?php endforeach ?>
            </div>
          <?php endif ?>
          <?php if (count($byItem) > 1): ?>
            <div><p class="muted small">Most downloaded</p>
              <?php foreach (array_slice($byItem, 0, 6, true) as $k => $n): ?><a class="chip" href="<?= e($url(['item' => (string) $k])) ?>"><?= e($names[$k] ?? $k) ?> <span><?= $n ?></span></a><?php endforeach ?>
            </div>
          <?php endif ?>
        </div>
      <?php endif ?>

      <?php if (!$found): ?>
        <p class="muted">No downloads match these filters. <a href="/admin/log.php#log">Clear the filters</a>.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="log-table">
            <thead><tr><th>Date (Berlin)</th><th>Patron</th><th>Patreon id</th><th>Model / accessory</th><th>Version</th><th>Link</th></tr></thead>
            <tbody>
              <?php foreach ($shown as [$d, $id, $name, $item, $link, $ver]): ?>
                <tr>
                  <td><?= e($berlin($d)) ?></td>
                  <td><a class="log-f" href="<?= e($url(['patron' => $id])) ?>" title="Only this patron"><?= e($name) ?></a></td>
                  <td class="muted"><?= e($id) ?></td>
                  <td><a class="log-f" href="<?= e($url(['item' => $item])) ?>" title="Only this model"><?= e($names[$item] ?? $item) ?></a></td>
                  <td style="white-space: nowrap"><?= $ver !== '' ? e($ver) : '<span class="muted">—</span>' ?></td>
                  <td class="muted"><?= e($link) ?></td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
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
