<?php
// Media → Find Duplicates: pictures stored twice (the same file, or the same picture resized or saved again) and Gallery pictures
// added twice, in groups; keep one, and every place using the others shows it instead (their files are deleted). Rules: _dupes.php.
// The first visit reads every picture once (a share at a time, admin.js posts "scan" until done); later ones only new pictures.
require __DIR__ . '/_admin.php';
require __DIR__ . '/_dupes.php';
$json = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
admin_require($M3D, $json);

if ($json) {
    admin_post_guard($M3D);
    @set_time_limit(120);
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $do = (string) ($in['action'] ?? '');
    if ($do === 'scan') {
        [$done, $total] = dupes_scan();
        admin_json(['ok' => true, 'done' => $done, 'total' => $total]);
    }
    // the group as the page showed it must still be one now (another tab may have merged it): found again by one of its pictures
    $find = function (string $kind, array $keys): ?array {
        foreach (dupes_groups() as $g) {
            if ($g['kind'] !== $kind) continue;
            $have = $kind === 'gallery' ? array_column($g['stills'], 'id') : array_column($g['pics'], 'url');
            if (!array_diff($keys, $have)) return $g;
        }
        return null;
    };
    $kind = in_array($in['kind'] ?? '', ['same', 'look', 'gallery'], true) ? (string) $in['kind'] : '';
    $keys = array_values(array_unique(array_filter(array_map('strval', is_array($in['items'] ?? null) ? $in['items'] : []))));
    if (!$kind || count($keys) < 2 || count($keys) > 50) admin_json(['ok' => false, 'error' => 'Nothing to change.'], 400);
    if (!$find($kind, $keys)) admin_json(['ok' => false, 'error' => 'These pictures changed meanwhile. Reload the page.'], 409);
    if ($do === 'ok') {   // "Not Duplicates": every pair of the group is left out from now on
        $ids = $kind === 'gallery' ? array_map(fn($k) => 'still:' . $k, $keys) : $keys;
        $codes = (array) (m3d_settings(true)['dupes_ok'] ?? []);
        foreach ($ids as $i => $a) foreach (array_slice($ids, $i + 1) as $b) $codes[] = dupes_pair($a, $b);
        $codes = array_slice(array_values(array_unique($codes)), -DUPES_OK_MAX);
        if (!m3d_save_settings(['dupes_ok' => $codes])) admin_json(['ok' => false, 'error' => m3d_write_error('settings')], 500);
        admin_json(['ok' => true]);
    }
    if ($do === 'merge') {
        $keep = (string) ($in['keep'] ?? '');
        if (!in_array($keep, $keys, true)) admin_json(['ok' => false, 'error' => 'Pick the one to keep.'], 400);
        if ($kind === 'gallery') {
            $n = dupes_merge_stills($keys, $keep);
            if ($n === null) admin_json(['ok' => false, 'error' => m3d_write_error('data')], 500);
            admin_json(['ok' => true, 'msg' => '✓ ' . m3d_plural($n, 'Gallery picture') . ' removed. The one kept has their characters and likes.']);
        }
        $r = dupes_merge($keep, array_values(array_diff($keys, [$keep])));
        if (!$r[0]) admin_json(['ok' => false, 'error' => $r[1]], 500);
        [, $places, $deleted, $stills] = $r;
        admin_json(['ok' => true, 'msg' => '✓ ' . ($places ? m3d_plural($places, 'place') . ' now ' . ($places === 1 ? 'shows' : 'show') . ' the kept picture, ' : '')
            . m3d_plural($deleted, 'file') . ' deleted' . ($stills ? ', ' . m3d_plural($stills, 'Gallery picture') . ' merged into it' : '') . '.']);
    }
    admin_json(['ok' => false, 'error' => 'Unknown action.'], 400);
}

$lib = media_library();
$todo = count(dupes_todo($lib));
$groups = $todo ? [] : dupes_groups($lib);
$use = usage_map(m3d_models());
[, $names] = admin_folder_picks(m3d_models());
$likes = m3d_likes('stills');
$inGallery = fn(array $g) => $g['kind'] === 'gallery' || array_filter($g['pics'], fn($p) => array_filter(array_keys($use[$p['url']] ?? []), fn($k) => str_starts_with($k, 'gallery:')));
$count = ['same' => 0, 'look' => 0, 'gallery' => 0];
foreach ($groups as $g) { $count[$g['kind']]++; if ($g['kind'] !== 'gallery' && $inGallery($g)) $count['gallery']++; }
$from = m3d_get_text('in') === 'gallery' ? 'gallery' : '';
$back = $from === 'gallery' ? ['/admin/gallery.php', '← Gallery'] : ['/admin/images.php', '← Media'];
// "Used by" of a picture, its versions too (a text may use the -sm file)
$usedBy = function (string $url) use ($use): array { $u = []; foreach ([$url, ...img_versions($url)] as $v) $u += $use[$v] ?? []; return $u; };
$folderName = fn(string $f) => $names[$f] ?? $f;
$kindTitle = ['same' => 'Same File', 'look' => 'Looks the Same', 'gallery' => 'In the Gallery Twice'];
$kindTip = [
    'same' => 'The same file stored more than once (uploaded again, or into another folder).',
    'look' => 'The same picture at another size or saved again. Compare them before keeping one.',
    'gallery' => 'The same picture added to the Gallery more than once.',
];

admin_start('Find Duplicates', 'images');
?>
    <div class="admin-head">
      <div>
        <a class="back" href="<?= e($back[0]) ?>"><?= e($back[1]) ?></a>
        <h1>Find Duplicates</h1>
      </div>
    </div>

    <?php if ($todo): ?>
      <div class="notice dup-scan" data-dup-scan data-total="<?= count($lib) ?>" data-done="<?= count($lib) - $todo ?>">
        <span>Reading <?= m3d_plural($todo, 'picture') ?> to compare them. This happens once; new pictures are read the next time.</span>
        <span class="up-prog busy"><span class="up-prog-tx" data-dup-tx><?= count($lib) - $todo ?> of <?= count($lib) ?></span><span class="up-prog-bar"><span class="up-prog-fill" style="width: <?= round((count($lib) - $todo) / max(1, count($lib)) * 100) ?>%"></span></span></span>
      </div>
    <?php elseif (!$groups): ?>
      <p class="muted">No duplicates found among <?= m3d_plural(count($lib), 'picture') ?> and the Gallery.</p>
    <?php else: ?>
      <div class="dup-bar" role="group" aria-label="Show" data-dup-chips>
        <button type="button" class="chip" data-dup-chip="" aria-pressed="<?= $from ? 'false' : 'true' ?>">All <span><?= count($groups) ?></span></button>
        <?php foreach (['same' => 'Same File', 'look' => 'Looks the Same', 'gallery' => 'Gallery'] as $k => $label): if (!$count[$k] || $count[$k] === count($groups)) continue; ?>
          <button type="button" class="chip" data-dup-chip="<?= $k ?>" aria-pressed="<?= $from === $k ? 'true' : 'false' ?>"><?= $label ?> <span><?= $count[$k] ?></span></button>
        <?php endforeach ?>
      </div>

      <div class="dup-list" data-dups>
        <?php foreach ($groups as $gi => $g): $isG = $g['kind'] === 'gallery'; $n = $isG ? count($g['stills']) : count($g['pics']); ?>
          <section class="sec dup-group" data-dup="<?= $g['kind'] ?>" data-items="<?= e(json_encode($isG ? array_column($g['stills'], 'id') : array_column($g['pics'], 'url'), JSON_UNESCAPED_SLASHES)) ?>" data-dup-gallery="<?= $isG || $inGallery($g) ? '1' : '0' ?>" <?= $from === 'gallery' && !($isG || $inGallery($g)) ? 'hidden' : '' ?>>
            <h2 class="dup-h"><?= $kindTitle[$g['kind']] ?> <span class="muted"><?= $n ?> <?= $isG ? 'times' : 'copies' ?></span><?= tip($kindTip[$g['kind']]) ?></h2>
            <div class="dup-cards">
              <?php if ($isG): foreach ($g['stills'] as $k => $s): $l = count($likes[$s['id']] ?? []); ?>
                <figure class="lib-card dup-card">
                  <a class="lib-img" href="<?= e(use_href('gallery:' . $s['id'])) ?>" title="Open in Admin → Gallery"><img class="lib-bg" src="<?= e(img_small($s['src'])) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" /><img src="<?= e(img_small($s['src'])) ?>" alt="" loading="lazy" decoding="async" /></a>
                  <label class="dup-keep"><input type="radio" name="keep-<?= $gi ?>" value="<?= e($s['id']) ?>" <?= $k === 0 ? 'checked' : '' ?> /><span>Keep</span></label>
                  <figcaption>
                    <span class="lib-name"><?= e(trim((string) ($s['caption'] ?? '')) ?: 'No Caption') ?></span>
                    <span class="muted small"><?= e(GALLERY_VISIBILITY[$s['visibility'] ?? 'public'] ?? 'Public') ?> · <?= e(m3d_site_time((string) ($s['date'] ?? ''), 'j M Y')) ?><?= $l ? ' · ♥ ' . $l : '' ?><?= !empty($s['link']['url']) ? ' · Full Size Link' : '' ?></span>
                    <span class="small muted"><?= e(implode(', ', array_column(m3d_still_chars($s), 'name')) ?: 'No characters') ?></span>
                  </figcaption>
                </figure>
              <?php endforeach; else:
                $sizes = array_unique(array_map(fn($p) => $p['w'] * $p['h'], $g['pics']));
                foreach ($g['pics'] as $k => $p): $u = $usedBy($p['url']); ?>
                <figure class="lib-card dup-card">
                  <a class="lib-img" href="<?= e($p['url']) ?>" target="_blank" title="Open"><?php if (!$p['w'] || !$p['h'] || abs($p['w'] / $p['h'] - 9 / 16) > 0.02): ?><img class="lib-bg" src="<?= e($p['small']) ?>" alt="" aria-hidden="true" loading="lazy" decoding="async" /><?php endif ?><img src="<?= e($p['small']) ?>" alt="" loading="lazy" decoding="async" /><?php if (count($sizes) > 1 && $k === 0): ?><span class="dup-tag">Biggest</span><?php endif ?></a>
                  <label class="dup-keep"><input type="radio" name="keep-<?= $gi ?>" value="<?= e($p['url']) ?>" <?= $k === 0 ? 'checked' : '' ?> /><span>Keep</span></label>
                  <figcaption>
                    <span class="lib-name" title="<?= e($p['name']) ?>"><?= e($p['name']) ?></span>
                    <span class="muted small"><?= $p['w'] ?>×<?= $p['h'] ?> · <?= m3d_size($p['bytes']) ?></span>
                    <span class="muted small lib-meta" title="<?= e($folderName($p['folder'])) ?>"><?= e($folderName($p['folder'])) ?></span>
                    <span class="small dup-use">
                      <?php if ($u): foreach ($u as $key => $nm): ?><a href="<?= e(use_href($key)) ?>"><?= e($nm) ?></a><?php endforeach ?>
                      <?php else: ?><span class="warn-dot">Not Used</span><?php endif ?>
                    </span>
                  </figcaption>
                </figure>
              <?php endforeach; endif ?>
            </div>
            <div class="form-actions">
              <button type="button" class="btn btn-ghost btn-sm" data-dup-ok>Not Duplicates</button>
              <button type="button" class="btn btn-primary btn-sm" data-dup-merge>Keep Selected</button>
            </div>
          </section>
        <?php endforeach ?>
      </div>
      <p class="muted small" data-dup-none hidden>No duplicates left.</p>
    <?php endif ?>
<?php admin_end();
