<?php
// /whats-new/  (rewritten here by .htaccess): every version of every listed model and accessory, newest first, grouped by
// month (site time); linked from the homepage's "Recently Updated" title. One entry per dated changelog version (not
// future dates) of the items the catalogs list (m3d_listed_items(): no drafts, no unlisted password items). Pictures through
// card_pic() (blurred copies for hidden ones); a password item still locked for this visitor shows no changes, like its page.
// Filters in the address: ?kind=models|outfits, ?mine=1 (the items this patron downloaded), ?page=N (PER_PAGE entries).
require __DIR__ . '/app/_init.php';

const WN_PER_PAGE = 30;
$tz = m3d_tz();
$at = fn(int $t, string $f) => (new DateTimeImmutable('@' . $t))->setTimezone($tz)->format($f);

$p = m3d_patron($M3D);
$mine = [];   // "kind:slug" this patron downloaded
if ($p) foreach (m3d_patron_history($p) as [, $k, $slug]) $mine["$k:$slug"] = true;

$entries = [];
$now = time();
foreach (['models', 'outfits'] as $kind) {
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

$kindF = in_array($_GET['kind'] ?? '', ['models', 'outfits'], true) ? $_GET['kind'] : '';
$mineF = $mine && !empty($_GET['mine']);
$both = count(array_unique(array_column($entries, 'kind'))) > 1;   // the kind chips: only when there are both (whatever "mine" leaves)
$counts = ['' => 0, 'models' => 0, 'outfits' => 0];   // what each chip shows, with "Only what I downloaded" when it is on
foreach ($entries as $x) if (!$mineF || isset($mine[$x['kind'] . ':' . $x['item']['slug']])) { $counts[$x['kind']]++; $counts['']++; }
$shown = array_values(array_filter($entries, fn($x) => ($kindF === '' || $x['kind'] === $kindF) && (!$mineF || isset($mine[$x['kind'] . ':' . $x['item']['slug']]))));
$pages = max(1, (int) ceil(count($shown) / WN_PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$list = array_slice($shown, ($page - 1) * WN_PER_PAGE, WN_PER_PAGE);
$url = fn(array $q) => '/whats-new/' . (($q = http_build_query(array_filter($q))) !== '' ? '?' . $q : '');
$base = ['kind' => $kindF, 'mine' => $mineF ? 1 : 0];

layout_start("What's New", 'New models, accessories and versions on ' . site_name() . ', newest first.', 'page-new');
?>
  <div class="wrap wn">
    <header class="wn-head">
      <p class="eyebrow">Updates</p>
      <h1>What’s New</h1>
      <p class="lead">Every new model, accessory and version, newest first.</p>
    </header>
    <?php if ($both || $mine): ?>
      <nav class="chips wn-filters" aria-label="Show">
        <?php if ($both): foreach (['' => 'All', 'models' => 'Models', 'outfits' => 'Accessories'] as $k => $label): ?>
          <a class="chip" href="<?= e($url(['kind' => $k] + $base)) ?>" aria-pressed="<?= $kindF === $k ? 'true' : 'false' ?>"><?= e($label) ?> <span><?= $counts[$k] ?></span></a>
        <?php endforeach; endif ?>
        <?php if ($mine): ?><a class="chip" href="<?= e($url(['mine' => $mineF ? 0 : 1] + $base)) ?>" aria-pressed="<?= $mineF ? 'true' : 'false' ?>">Only What I Downloaded</a><?php endif ?>
      </nav>
    <?php endif ?>

    <?php if (!$list): ?>
      <p class="muted wn-empty"><?= $entries ? 'Nothing here yet.' : 'No updates yet.' ?></p>
    <?php endif ?>
    <?php $month = ''; foreach ($list as $x):
      [$kind, $it, $en] = [$x['kind'], $x['item'], $x['en']];
      if (($m = $at($x['ts'], 'F Y')) !== $month): ?>
        <?= $month !== '' ? '</div>' : '' ?><h2 class="wn-month"><?= e($m) ?></h2><div class="wn-list">
      <?php $month = $m; endif;
      [$src, $hidden] = card_pic($kind, $it);
      $open = m3d_can_view($kind, $it);   // a locked password item: name and version only
      // the whole entry is one link: links inside the changes become plain text (a link inside a link breaks the page)
      $changes = $open ? array_values(array_filter(array_map(fn($c) => trim((string) $c), (array) ($en['changes'] ?? [])), fn($c) => $c !== '')) : [];
      $what = $kind === 'outfits' ? m3d_accessory_type($it) : trim('Model · ' . ($it['franchise'] ?? ''), ' ·'); ?>
      <a class="wn-item" href="<?= e(m3d_version_url($kind, $it, m3d_version_label($en))) ?>">
        <span class="wn-pic<?= $hidden ? ' is-blurred' : '' ?>"><?= card_img($src, (string) $it['name']) ?></span>
        <span class="wn-body">
          <span class="wn-top"><span class="recent-tag <?= $x['new'] ? 'is-new' : 'is-upd' ?>"><?= $x['new'] ? 'New' : 'Updated' ?></span><span><?= e($what) ?> · <?= e($at($x['ts'], 'j M')) ?></span></span>
          <span class="wn-name"><b><?= e(m3d_model_name($it)) ?></b> <span class="wn-ver"><?= e(m3d_version_label($en)) ?></span><?= $open ? highlight_badge($en['highlight'] ?? null) : '' ?></span>
          <?php if ($changes): ?><ul class="wn-changes"><?php foreach (array_slice($changes, 0, 3) as $c): ?><li><?= preg_replace('#</?a\b[^>]*>#i', '', md_inline($c)) ?></li><?php endforeach ?></ul><?php endif ?>
          <?php if (count($changes) > 3): ?><span class="wn-more">+<?= count($changes) - 3 ?> more <?= count($changes) - 3 === 1 ? 'change' : 'changes' ?></span><?php endif ?>
        </span>
      </a>
    <?php endforeach; echo $month !== '' ? '</div>' : '' ?>

    <?php if ($pages > 1): ?>
      <nav class="wn-pager" aria-label="More updates">
        <?php if ($page > 1): ?><a class="btn btn-ghost" href="<?= e($url(['page' => $page > 2 ? $page - 1 : 0] + $base)) ?>">← Newer Updates</a><?php endif ?>
        <?php if ($page < $pages): ?><a class="btn btn-ghost" href="<?= e($url(['page' => $page + 1] + $base)) ?>">Older Updates →</a><?php endif ?>
      </nav>
    <?php endif ?>
  </div>
<?php layout_end();
