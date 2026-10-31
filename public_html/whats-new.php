<?php
// /whats-new/  (rewritten here by .htaccess): every version of every listed model and accessory, newest first, grouped by
// month (site time); linked from the homepage's "Recently Updated" title. One entry per dated changelog version (not
// future dates) of the items the catalogs list (m3d_listed_items(): no drafts, no unlisted password items). Pictures through
// card_pic() (blurred copies for hidden ones); a password item still locked for this visitor shows no changes, like its page.
// Filters in the address: ?kind=models|outfits|scenes, ?mine=1 (the items this visitor downloaded or bookmarked), ?page=N (PER_PAGE entries).
// ?view=cal = the calendar instead of the list: one month (?month=YYYY-MM, between the oldest and newest month shown; default the
// newest), Monday first, each day with up to 3 pictures; the days' entries are all in the page as the list's cards, the picked day
// (?day=N, default the newest of the month) shown under the grid; site.js `[data-wn-cal]` switches days without a reload.
require __DIR__ . '/app/_init.php';

const WN_PER_PAGE = 30;
$tz = m3d_tz();
$at = fn(int $t, string $f) => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format($f);

$p = m3d_patron($M3D);
$mine = [];   // "kind:slug" this visitor downloaded or bookmarked (app/bell.php → Bookmarks)
if ($p) foreach (m3d_patron_history($p) as [, $k, $slug]) $mine["$k:$slug"] = true;
$mine += m3d_bookmarked($p);

$entries = [];
$now = time();
foreach (M3D_ITEM_KINDS as $kind) {
    foreach (m3d_listed_items($kind) as $it) {
        $log = m3d_versions($it);
        $soon = m3d_release_phase($it) === 'soon';   // "Coming soon": its newest version isn't out until the patrons date
        foreach ($log as $i => $en) {
            $ts = m3d_changelog_ts((string) ($en['date'] ?? ''));
            if ($ts === null || $ts > $now || ($soon && $i === 0)) continue;
            $entries[] = ['kind' => $kind, 'item' => $it, 'en' => $en, 'ts' => $ts, 'order' => $i, 'new' => $i === count($log) - 1];
        }
    }
}
// newest first; versions of one item on the same day keep the changelog order (top = newest)
usort($entries, fn($a, $b) => $b['ts'] <=> $a['ts'] ?: $a['order'] <=> $b['order']);

$kindF = in_array($_GET['kind'] ?? '', M3D_ITEM_KINDS, true) ? $_GET['kind'] : '';
$mineF = $mine && !empty($_GET['mine']);
$both = count(array_unique(array_column($entries, 'kind'))) > 1;   // the kind chips: only when there are both (whatever "mine" leaves)
$counts = ['' => 0, 'models' => 0, 'outfits' => 0, 'scenes' => 0];   // what each chip shows, with "Downloaded / Bookmarked" when it is on
foreach ($entries as $x) if (!$mineF || isset($mine[$x['kind'] . ':' . $x['item']['slug']])) { $counts[$x['kind']]++; $counts['']++; }
$shown = array_values(array_filter($entries, fn($x) => ($kindF === '' || $x['kind'] === $kindF) && (!$mineF || isset($mine[$x['kind'] . ':' . $x['item']['slug']]))));
$pages = max(1, (int) ceil(count($shown) / WN_PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$list = array_slice($shown, ($page - 1) * WN_PER_PAGE, WN_PER_PAGE);
$url = fn(array $q) => '/whats-new/' . (($q = http_build_query(array_filter($q))) !== '' ? '?' . $q : '');
$cal = $entries && m3d_get_text('view') === 'cal';
$base = ['kind' => $kindF, 'mine' => $mineF ? 1 : 0, 'view' => $cal ? 'cal' : ''];

if ($cal) {
    $days = [];   // "Y-m-d" (site time) => entries of that day, newest first
    foreach ($shown as $x) $days[$at($x['ts'], 'Y-m-d')][] = $x;
    $newest = $shown ? $at($shown[0]['ts'], 'Y-m') : $at($now, 'Y-m');
    $oldest = $shown ? $at($shown[count($shown) - 1]['ts'], 'Y-m') : $newest;
    $month = m3d_get_text('month');
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month)) $month = $newest;
    $month = max($oldest, min($newest, $month));   // "Y-m" texts compare in order
    $first = new DateTimeImmutable($month . '-01', $tz);
    $inMonth = array_filter($days, fn($k) => str_starts_with($k, $month . '-'), ARRAY_FILTER_USE_KEY);
    $pick = (int) m3d_get_text('day');
    $pick = isset($inMonth[sprintf('%s-%02d', $month, $pick)]) ? $pick : ($inMonth ? (int) substr((string) array_key_first($inMonth), 8) : 0);
    $today = $at($now, 'Y-m-d');
    $monthUrl = fn(DateTimeImmutable $d) => $url(['month' => $d->format('Y-m')] + $base);
}
$card = function (array $x) use ($at): string {
    [$kind, $it, $en] = [$x['kind'], $x['item'], $x['en']];
    [$src, $hidden] = card_pic($kind, $it);
    $open = m3d_can_view($kind, $it);   // a locked password item: name and version only
    // the whole entry is one link: links inside the changes become plain text (a link inside a link breaks the page)
    $changes = $open ? array_values(array_filter(array_map(fn($c) => trim((string) $c), (array) ($en['changes'] ?? [])), fn($c) => $c !== '')) : [];
    $what = $kind === 'models' ? trim('Model · ' . ($it['franchise'] ?? ''), ' ·') : m3d_item_type($kind, $it);
    ob_start(); ?>
      <a class="wn-item" href="<?= e(m3d_version_url($kind, $it, m3d_version_label($en))) ?>">
        <span class="wn-pic<?= $hidden ? ' is-blurred' : '' ?>"><?= card_img($src, (string) $it['name']) ?></span>
        <span class="wn-body">
          <span class="wn-top"><span class="recent-tag <?= $x['new'] ? 'is-new' : 'is-upd' ?>"><?= $x['new'] ? 'New' : 'Updated' ?></span><span><?= e($what) ?> · <?= e($at($x['ts'], 'j M')) ?></span></span>
          <span class="wn-name"><b><?= e(m3d_model_name($it)) ?></b> <span class="wn-ver"><?= e(m3d_version_label($en)) ?></span><?= $open ? highlight_badge($en['highlight'] ?? null) : '' ?></span>
          <?php if ($changes): ?><ul class="wn-changes"><?php foreach (array_slice($changes, 0, 3) as $c): ?><li><?= preg_replace('#</?a\b[^>]*>#i', '', md_inline($c)) ?></li><?php endforeach ?></ul><?php endif ?>
          <?php if (count($changes) > 3): ?><span class="wn-more">+<?= count($changes) - 3 ?> more <?= count($changes) - 3 === 1 ? 'change' : 'changes' ?></span><?php endif ?>
        </span>
      </a>
<?php return (string) ob_get_clean();
};
$views = ['' => ['List', '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>'],
          'cal' => ['Calendar', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>']];

layout_start("What's New", 'New models, accessories and versions on ' . site_name() . ', newest first.', 'page-new');
?>
  <div class="wrap wn">
    <header class="wn-head">
      <h1>What’s New</h1>
      <p class="lead">Every new model, accessory and version, newest first.</p>
    </header>
    <div class="wn-bar">
    <?php if ($both || $mine): ?>
      <nav class="chips wn-filters" aria-label="Show">
        <?php if ($both): foreach (['' => 'All', 'models' => 'Models', 'outfits' => 'Accessories', 'scenes' => 'Scenes'] as $k => $label): if ($k !== '' && !$counts[$k] && $kindF !== $k) continue; ?>
          <a class="chip" href="<?= e($url(['kind' => $k] + $base)) ?>" aria-pressed="<?= $kindF === $k ? 'true' : 'false' ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a>
        <?php endforeach; endif ?>
        <?php if ($mine): ?><a class="chip" href="<?= e($url(['mine' => $mineF ? 0 : 1] + $base)) ?>" aria-pressed="<?= $mineF ? 'true' : 'false' ?>">Downloaded / Bookmarked</a><?php endif ?>
      </nav>
    <?php endif ?>
    <?php if ($entries): ?>
      <nav class="wn-views" aria-label="View">
        <?php foreach ($views as $k => [$label, $icon]): ?>
          <a href="<?= e($url(['view' => $k] + $base)) ?>"<?= ($k === 'cal') === $cal ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><?= $icon ?></svg><?= $label ?></a>
        <?php endforeach ?>
      </nav>
    <?php endif ?>
    </div>

    <?php if (!$cal && !$list): ?>
      <p class="muted wn-empty"><?= $entries ? 'Nothing here yet.' : 'No updates yet.' ?></p>
    <?php endif ?>
    <?php if ($cal): ?>
      <?php $n = array_sum(array_map('count', $inMonth)); $prev = $first->modify('-1 month'); $next = $first->modify('+1 month'); ?>
      <div class="wn-cal-head">
        <h2 class="wn-month"><?= e($first->format('F Y')) ?></h2>
        <span class="wn-cal-n"><?= $n ?> <?= $n === 1 ? 'update' : 'updates' ?></span>
        <nav class="wn-cal-nav" aria-label="Months">
          <?php if ($month > $oldest): ?><a href="<?= e($monthUrl($prev)) ?>" aria-label="<?= e($prev->format('F Y')) ?>">‹</a><?php else: ?><span class="is-off" aria-hidden="true">‹</span><?php endif ?>
          <?php if ($month < $newest): ?><a href="<?= e($monthUrl($next)) ?>" aria-label="<?= e($next->format('F Y')) ?>">›</a><?php else: ?><span class="is-off" aria-hidden="true">›</span><?php endif ?>
        </nav>
      </div>
      <div class="wn-cal" data-wn-cal>
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $w): ?><span class="wn-cal-wd" aria-hidden="true"><?= $w ?></span><?php endforeach ?>
        <?= str_repeat('<span class="wn-cal-d is-out"></span>', (int) $first->format('N') - 1) ?>
        <?php for ($d = 1, $len = (int) $first->format('t'); $d <= $len; $d++):
          $key = sprintf('%s-%02d', $month, $d);
          $xs = $inMonth[$key] ?? [];
          $cls = ($xs ? ' has' : '') . ($key === $today ? ' is-today' : '') . ($d === $pick ? ' is-sel' : '');
          if (!$xs): ?><span class="wn-cal-d<?= $cls ?>"><span class="wn-cal-num"><?= $d ?></span></span><?php continue; endif;
          $label = $first->format('F') . " $d: " . count($xs) . (count($xs) === 1 ? ' update' : ' updates'); ?>
          <a class="wn-cal-d<?= $cls ?>" href="<?= e($url(['month' => $month, 'day' => $d] + $base)) ?>" data-day="<?= $d ?>" aria-label="<?= e($label) ?>"<?= $d === $pick ? ' aria-current="date"' : '' ?>>
            <span class="wn-cal-num"><?= $d ?></span>
            <?php if (count($xs) > 1): ?><span class="wn-cal-c"><?= count($xs) ?></span><?php endif ?>
            <span class="wn-cal-pics"><?php foreach (array_slice($xs, 0, 3) as $i => $x): [$src, $hidden] = card_pic($x['kind'], $x['item']); ?>
              <span class="wn-cal-pic<?= $x['new'] ? ' is-new' : '' ?><?= $hidden ? ' is-blurred' : '' ?>"><?= card_img($src, (string) $x['item']['name']) ?><?php if ($i === 2 && count($xs) > 3): ?><span class="wn-cal-more">+<?= count($xs) - 2 ?></span><?php endif ?></span>
            <?php endforeach ?></span>
          </a>
        <?php endfor ?>
      </div>
      <p class="wn-cal-key"><span><i class="is-new"></i>New</span><span><i></i>Updated</span></p>
      <?php if (!$inMonth): ?><p class="muted wn-empty"><?= $shown ? 'No updates this month.' : 'Nothing here yet.' ?></p><?php endif ?>
      <?php foreach ($inMonth as $key => $xs): $d = (int) substr($key, 8); ?>
        <section class="wn-cal-day" data-day="<?= $d ?>"<?= $d === $pick ? '' : ' hidden' ?>>
          <h3><?= e($at($xs[0]['ts'], 'l j F')) ?> <span><?= count($xs) ?> <?= count($xs) === 1 ? 'update' : 'updates' ?></span></h3>
          <div class="wn-list"><?php foreach ($xs as $x) echo $card($x) ?></div>
        </section>
      <?php endforeach ?>
    <?php else: ?>
    <?php $month = ''; foreach ($list as $x):
      if (($m = $at($x['ts'], 'F Y')) !== $month): ?>
        <?= $month !== '' ? '</div>' : '' ?><h2 class="wn-month"><?= e($m) ?></h2><div class="wn-list">
      <?php $month = $m; endif;
      echo $card($x);
    endforeach; echo $month !== '' ? '</div>' : '' ?>

    <?php if ($pages > 1): ?>
      <nav class="wn-pager" aria-label="More updates">
        <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e($url(['page' => $page > 2 ? $page - 1 : 0] + $base)) ?>">← Newer Updates</a><?php endif ?>
        <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e($url(['page' => $page + 1] + $base)) ?>">Older Updates →</a><?php endif ?>
      </nav>
    <?php endif ?>
    <?php endif ?>
  </div>
<?php layout_end();
