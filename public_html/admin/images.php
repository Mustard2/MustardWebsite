<?php
// Image library: every image in <site>/media/, where it is used, upload, copy address, delete.
// Also converts leftover non-WebP images in media/ (banner shown only when there are some).
require __DIR__ . '/_admin.php';
admin_require($M3D);

const BATCH_OPT = 10;

/** All images in media/ (large versions only; "-sm" files are their small companions). */
function media_library(): array
{
    $root = M3D_SITE . '/media';
    if (!is_dir($root)) return [];
    $items = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile() || !preg_match('/\.(webp|jpe?g|png|gif)$/i', $f->getFilename()) || preg_match('/-sm\.\w+$/', $f->getFilename())) continue;
        $url = '/media/' . str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
        if (str_starts_with($url, '/media/site/')) continue;   // the site logo and icons (Settings → Site identity)
        $small = img_small($url);
        $dim = @getimagesize($f->getPathname()) ?: [0, 0];
        $items[] = [
            'url'    => $url,
            'small'  => $small,
            'folder' => basename(dirname($url)),
            'name'   => $f->getFilename(),
            'bytes'  => $f->getSize() + ($small !== $url ? (int) @filesize(media_path($small)) : 0),
            'w'      => $dim[0], 'h' => $dim[1],
            'time'   => $f->getMTime(),
        ];
    }
    usort($items, fn($a, $b) => [$a['folder'], $b['time']] <=> [$b['folder'], $a['time']]);
    return $items;
}

/** url => [ editKey => name, ... ] for every image address used by a model or an outfit. */
function usage_map(array $models, ?array $outfits = null): array
{
    $use = [];
    foreach ($models as $m) {
        array_walk_recursive($m, function ($v) use (&$use, $m) {
            if (is_string($v) && str_starts_with($v, '/media/')) $use[$v]['models:' . $m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' (Gen ' . $m['generation'] . ')' : '');
        });
    }
    foreach (m3d_texts() as $t) {   // FAQ answers and docs pages
        if (preg_match_all('/!\[[^\]]*\]\((\/media\/[^\s)]+)\)/', $t['text'], $mm)) foreach ($mm[1] as $u) $use[$u][$t['key']] = $t['name'];
    }
    foreach ($outfits ?? m3d_outfits() as $o) {
        array_walk_recursive($o, function ($v) use (&$use, $o) {
            if (is_string($v) && str_starts_with($v, '/media/')) $use[$v]['outfits:' . $o['slug']] = $o['name'] . ' (' . mb_strtolower(m3d_accessory_type($o)) . ')';
        });
    }
    return $use;
}

function unoptimized(array $models, ?array $outfits = null): array
{
    $out = [];
    foreach (array_keys(usage_map($models, $outfits)) as $url) {
        $p = media_path($url);
        if (!$p || !is_file($p) || preg_match('/-sm\.\w+$/', $url)) continue;
        if (str_ends_with(strtolower($p), '.gif') && img_is_animated_gif($p)) continue;
        if (img_small($url) === $url) $out[] = $url;
    }
    return $out;
}

/** Images still loaded from other websites: url => media folder to copy them into. */
function external_images(): array
{
    $found = [];
    $isImg = fn($k) => in_array((string) $k, ['thumb', 'cover', 'src', 'image'], true) || is_int($k);
    foreach (['models', 'outfits'] as $kind) foreach (m3d_items($kind) as $m) {
        $folder = ($kind === 'outfits' ? 'outfit-' : '') . $m['slug'];
        foreach (['thumb', 'cover', 'gallery', 'images', 'notes', 'changelog'] as $field) {
            if (!isset($m[$field])) continue;
            $v = [$field => $m[$field]];
            array_walk_recursive($v, function ($x, $k) use (&$found, $folder, $isImg) {
                if (is_string($x) && $isImg($k) && preg_match('#^https?://#i', $x) && preg_match('/\.(jpe?g|png|webp|gif)(\?|$)/i', $x)) $found[$x] ??= $folder;
            });
        }
    }
    foreach (m3d_texts() as $t) {
        if (preg_match_all('/!\[[^\]]*\]\((https?:\/\/[^\s)]+)\)/i', $t['text'], $mm)) foreach ($mm[1] as $u) $found[$u] ??= $t['folder'];
    }
    return $found;
}

function replace_everywhere(array $models, array $map): array
{
    array_walk_recursive($models, function (&$v) use ($map) { if (is_string($v) && isset($map[$v])) $v = $map[$v]; });
    return $models;
}

// ── Actions ──────────────────────────────────────────────────────────────────
$results = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    admin_post_guard($M3D);
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    if (($in['action'] ?? '') === 'delete') {
        $url = (string) ($in['url'] ?? '');
        $p = media_path($url);
        if (!$p || !is_file($p)) admin_json(['ok' => false, 'error' => 'Image not found.'], 404);
        $used = usage_map(m3d_models())[$url] ?? [];   // models and outfits
        if ($used) admin_json(['ok' => false, 'error' => 'Still used by ' . implode(', ', $used) . '. Remove it from the model first.'], 409);
        $small = img_small($url);
        @unlink($p);
        if ($small !== $url) @unlink((string) media_path($small));
        admin_json(['ok' => true]);
    }
    if (($in['action'] ?? '') === 'crop') {
        // Crops the large version into a NEW file (same folder, name + "-crop-<code>"): images are cached for a year
        // (Cloudflare too), so the same address would keep showing the old picture. replace = use it everywhere.
        $url = (string) preg_replace('/-sm(\.\w+)$/', '$1', (string) ($in['url'] ?? ''));
        $p = media_path($url);
        if (!$p || !is_file($p)) admin_json(['ok' => false, 'error' => 'Only images stored on this site can be cropped. Copy outside images here first (Images page).'], 404);
        if (!img_supported()) admin_json(['ok' => false, 'error' => 'Image processing is not available on this server.'], 500);
        if (img_is_animated_gif($p)) admin_json(['ok' => false, 'error' => 'Animated GIFs can’t be cropped (they would stop moving).'], 422);
        @ini_set('memory_limit', '1024M');
        $im = img_load($p);
        if (!$im) admin_json(['ok' => false, 'error' => 'Could not read this image.'], 422);
        $W = imagesx($im); $H = imagesy($im);
        $x = max(0, min($W - 1, (int) round((float) ($in['x'] ?? 0))));
        $y = max(0, min($H - 1, (int) round((float) ($in['y'] ?? 0))));
        $w = max(1, min($W - $x, (int) round((float) ($in['w'] ?? $W))));
        $h = max(1, min($H - $y, (int) round((float) ($in['h'] ?? $H))));
        if ($w < 16 || $h < 16) admin_json(['ok' => false, 'error' => 'The selected area is too small.'], 422);
        $crop = imagecrop($im, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        imagedestroy($im);
        if (!$crop) admin_json(['ok' => false, 'error' => 'Could not crop this image.'], 500);
        $dir = dirname($p);
        $base = preg_replace('/-crop-[0-9a-f]{6}$/', '', pathinfo($p, PATHINFO_FILENAME)) . '-crop-' . bin2hex(random_bytes(3));
        $tmp = "$dir/.crop-" . bin2hex(random_bytes(4)) . '.png';   // lossless in-between file, removed right after
        imagealphablending($crop, false);
        imagesavealpha($crop, true);
        $saved = imagepng($crop, $tmp, 1);
        imagedestroy($crop);
        try {
            if (!$saved) throw new RuntimeException('Could not save the cropped image.');
            $name = img_make_versions($tmp, $dir, $base);
        } catch (Throwable $ex) {
            admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
        } finally {
            @unlink($tmp);
        }
        $new = dirname($url) . '/' . $name;
        $replaced = 0;
        if (!empty($in['replace'])) {
            $map = [$url => $new];
            if (($small = img_small($url)) !== $url) $map[$small] = img_small($new);
            $models = m3d_models(); $outfits = m3d_outfits();
            $newModels = replace_everywhere($models, $map); $newOutfits = replace_everywhere($outfits, $map);
            $replaced = count(usage_map($models, $outfits)[$url] ?? []);
            $ok = ($newModels === $models || m3d_save_models($newModels)) && ($newOutfits === $outfits || m3d_save_items('outfits', $newOutfits));
            $ok = m3d_texts_replace($map) && $ok;   // FAQ answers and docs pages
            if (!$ok) admin_json(['ok' => false, 'error' => "The cropped image was saved as $name, but the places using the old one couldn’t be updated."], 500);
        }
        admin_json(['ok' => true, 'url' => $new, 'small' => img_small($new), 'w' => $w, 'h' => $h, 'replaced' => $replaced]);
    }
    admin_json(['ok' => false, 'error' => 'Unknown action.'], 400);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'external') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    @set_time_limit(600);
    $map = [];
    if (!empty($_POST['retry'])) $_SESSION['ext_failed'] = [];
    $failed = $_SESSION['ext_failed'] ?? [];
    // images that failed before are skipped, so one unreachable image doesn't block the others
    $todo = array_diff_key(external_images(), array_flip($failed));
    foreach (array_slice($todo, 0, 15, true) as $url => $folder) {
        try { $map[$url] = copy_external($url, $folder); $results[] = [true, $url, $map[$url]]; }
        catch (Throwable $ex) { $results[] = [false, $url, $ex->getMessage()]; $failed[] = $url; }
    }
    $_SESSION['ext_failed'] = array_values(array_unique($failed));
    if ($map) {
        $okSave = m3d_save_models(replace_everywhere(m3d_models(), $map)) && m3d_save_items('outfits', replace_everywhere(m3d_outfits(), $map));
        $okSave = m3d_texts_replace($map) && $okSave;   // FAQ answers and docs pages
        if (!$okSave) $results[] = [false, '', 'Could not save the data files'];
    }
    $ran = 'external';
}
/** Images (large + small files) that nothing uses: no model, outfit, FAQ answer or docs page points to either version. */
function unused_images(array $use): array
{
    return array_values(array_filter(media_library(), fn($i) => empty($use[$i['url']]) && empty($use[$i['small']])));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'purge') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $n = 0; $freed = 0;
    foreach (unused_images(usage_map(m3d_models())) as $i) {
        $gone = false;   // counted per image: its large and small files together
        foreach (array_unique([$i['url'], $i['small']]) as $u) {
            $f = media_path($u);
            if ($f && is_file($f)) { $size = (int) filesize($f); if (@unlink($f)) { $freed += $size; $gone = true; } }
        }
        if ($gone) $n++;
    }
    $purged = [$n, $freed];
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'optimize') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    @set_time_limit(600);
    $models = m3d_models();
    $outfits = m3d_outfits();
    $map = [];
    foreach (array_slice(unoptimized($models, $outfits), 0, BATCH_OPT) as $url) {
        try {
            $path = media_path($url);
            $before = filesize($path);
            $new = dirname($url) . '/' . img_make_versions($path, dirname($path), pathinfo($path, PATHINFO_FILENAME));
            $map[$url] = $new;
            $results[] = [true, $url, sprintf('%.1f MB → %.1f MB', $before / 1048576, filesize(media_path($new)) / 1048576)];
        } catch (Throwable $ex) { $results[] = [false, $url, $ex->getMessage()]; }
    }
    $map = array_filter($map, fn($new, $old) => $new !== $old, ARRAY_FILTER_USE_BOTH);
    if ($map) {
        $updated = replace_everywhere($models, $map);
        $updatedO = replace_everywhere($outfits, $map);
        if (m3d_save_models($updated) && m3d_save_items('outfits', $updatedO)) {
            $stillUsed = usage_map($updated, $updatedO);
            foreach (array_keys($map) as $old) if (!isset($stillUsed[$old]) && ($p = media_path($old)) && is_file($p)) @unlink($p);
        } else $results[] = [false, '', 'Could not save the data file'];
    }
}

// ── Page ─────────────────────────────────────────────────────────────────────
$models = m3d_models();
$items = media_library();
$use = usage_map($models);
$todoOpt = unoptimized($models);
$todoExtAll = external_images();
$extFailed = array_intersect(array_keys($todoExtAll), $_SESSION['ext_failed'] ?? []);
$todoExt = array_diff_key($todoExtAll, array_flip($extFailed));

// Tabs: each image goes where it is used (a model, an outfit, docs, FAQ), whatever folder it is stored in;
// unused images go by their folder (media/<model>, outfit-<slug>, docs, faq, misc…). 'group' = the model/outfit/folder
// it is listed under (the filter menu of the tab).
const IMG_TABS = ['models' => 'Models', 'outfits' => 'Accessories', 'docs' => 'Docs', 'faq' => 'FAQ', 'other' => 'Other'];
$modelSlugs = array_flip(array_column($models, 'slug'));
$folderSection = fn(string $folder): string => match (true) {
    $folder === 'docs' => 'docs',
    $folder === 'faq' => 'faq',
    str_starts_with($folder, 'outfit-') => 'outfits',
    isset($modelSlugs[$folder]) => 'models',
    default => 'other',
};
foreach ($items as &$i) {
    $i['section'] = $folderSection($i['folder']);
    $i['group'] = $i['folder'];
    $uses = array_keys(($use[$i['url']] ?? []) + ($use[$i['small']] ?? []));   // 'models:<slug>', 'outfits:<slug>', 'docs:<slug>', 'faq:faq'
    $where = array_values(array_filter($uses, fn($k) => explode(':', $k)[0] === $i['section'])) ?: $uses;   // its folder's section first
    if ($where) {
        [$sec, $slug] = explode(':', $where[0], 2);
        $i['section'] = $sec;
        $i['group'] = match ($sec) { 'models' => $slug, 'outfits' => 'outfit-' . $slug, default => $sec };
    }
}
unset($i);
usort($items, fn($a, $b) => [$a['group'], $b['time']] <=> [$b['group'], $a['time']]);
$tabCount = array_fill_keys(array_keys(IMG_TABS), 0);
foreach ($items as $i) $tabCount[$i['section']]++;
$tab = isset(IMG_TABS[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'models';
$items = array_values(array_filter($items, fn($i) => $i['section'] === $tab));
$folders = array_values(array_unique(array_column($items, 'group')));
$uploadDefault = ['docs' => 'docs', 'faq' => 'faq', 'other' => 'misc'][$tab] ?? null;
$names = [];
foreach ($models as $m) $names[$m['slug']] = $m['name'] . (($m['generation'] ?? '') !== '' ? ' (Gen ' . $m['generation'] . ')' : '');
foreach (m3d_outfits() as $o) $names['outfit-' . $o['slug']] = $o['name'] . ' (' . mb_strtolower(m3d_accessory_type($o)) . ')';
$names += ['docs' => 'Docs', 'faq' => 'FAQ', 'misc' => 'Other (misc)'];
$unusedAll = unused_images($use);
$unused = count($unusedAll);
$unusedBytes = array_sum(array_column($unusedAll, 'bytes'));
$tabUnused = count(array_filter($items, fn($i) => empty($use[$i['url']])));
$fmt = fn(int $b) => $b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', max(1, round($b / 1024)));

admin_start('Images', 'images');
?>
    <div class="admin-head">
      <div>
        <h1>Images</h1>
      </div>
      <div class="head-actions">
        <select class="input" data-upload-folder aria-label="Upload into">
          <optgroup label="Models"><?php foreach ($models as $m): ?><option value="<?= e($m['slug']) ?>"><?= e($names[$m['slug']]) ?></option><?php endforeach ?></optgroup>
          <?php if (m3d_outfits()): ?><optgroup label="Accessories"><?php foreach (m3d_outfits() as $k => $o): ?><option value="outfit-<?= e($o['slug']) ?>" <?= $tab === 'outfits' && $k === 0 ? 'selected' : '' ?>><?= e($o['name']) ?></option><?php endforeach ?></optgroup><?php endif ?>
          <option value="docs" <?= $uploadDefault === 'docs' ? 'selected' : '' ?>>Docs</option>
          <option value="faq" <?= $uploadDefault === 'faq' ? 'selected' : '' ?>>FAQ</option>
          <option value="misc" <?= $uploadDefault === 'misc' ? 'selected' : '' ?>>Other (misc)</option>
        </select>
        <label class="btn btn-primary">Upload images<input type="file" accept="image/*" multiple hidden data-upload-files /></label>
      </div>
    </div>

    <?php if (isset($purged)): ?>
      <p class="notice"><b class="res-ok">✓</b> <?= $purged[0] ? 'Deleted ' . m3d_plural($purged[0], 'unused image') . ', ' . $fmt($purged[1]) . ' freed.' : 'There were no unused images to delete.' ?></p>
    <?php endif ?>
    <?php if ($unused): ?>
      <form method="post" class="notice opt-notice" onsubmit="return confirm(<?= e(json_encode('Delete all ' . $unused . ' unused images (' . $fmt($unusedBytes) . ')? They are not used by any model, accessory, FAQ answer or docs page. This can’t be undone.')) ?>)">
        <span><b><?= $unused ?></b> <?= $unused === 1 ? 'image isn’t' : 'images aren’t' ?> used anywhere (<?= $fmt($unusedBytes) ?>). Tick “Unused only” in each section to review them first.</span>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="purge" />
        <button class="btn btn-ghost btn-sm del-btn">Delete all unused</button>
      </form>
    <?php endif ?>
    <?php if ($results): $ok = count(array_filter($results, fn($r) => $r[0])); ?>
      <p class="notice"><b class="res-ok"><?= $ok ?> <?= ($ran ?? '') === 'external' ? 'copied' : 'optimized' ?></b><?= count($results) - $ok ? ' · <b class="res-err">' . (count($results) - $ok) . ' failed</b>: ' . e(implode('; ', array_map(fn($r) => basename($r[1]) . ' (' . $r[2] . ')', array_filter($results, fn($r) => !$r[0])))) : '' ?></p>
    <?php endif ?>
    <?php if ($todoExt): ?>
      <form method="post" class="notice opt-notice">
        <span><b><?= count($todoExt) ?></b> images are loaded from other websites. Copy them here to keep visitors' data private.</span>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="external" />
        <button class="btn btn-ghost btn-sm">Copy <?= min(15, count($todoExt)) ?> now</button>
      </form>
    <?php elseif ($extFailed): ?>
      <form method="post" class="notice opt-notice">
        <span><b><?= count($extFailed) ?></b> outside images couldn't be copied. Try again, or replace them in the editor.</span>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="external" /><input type="hidden" name="retry" value="1" />
        <button class="btn btn-ghost btn-sm">Try again</button>
      </form>
    <?php endif ?>
    <?php if ($todoOpt): ?>
      <form method="post" class="notice opt-notice">
        <span><b><?= count($todoOpt) ?></b> images used by models aren't WebP yet.</span>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="optimize" />
        <button class="btn btn-ghost btn-sm">Optimize <?= min(BATCH_OPT, count($todoOpt)) ?> now</button>
      </form>
    <?php endif ?>

    <nav class="lib-tabs" aria-label="Sections">
      <?php foreach (IMG_TABS as $k => $label): if ($k !== 'models' && !$tabCount[$k] && $tab !== $k) continue; ?>
        <a href="/admin/images.php?tab=<?= $k ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?>><?= e($label) ?> <span><?= $tabCount[$k] ?></span></a>
      <?php endforeach ?>
    </nav>

    <?php if (!$items): ?>
      <p class="muted">No <?= e(strtolower(IMG_TABS[$tab])) ?> images yet. Upload some with the button above<?= $tab === 'models' || $tab === 'outfits' ? ', or from the editor' : ($tab === 'docs' ? ', or with Image in the Docs editor' : ($tab === 'faq' ? ', or with Add image in the FAQ editor' : '')) ?>.</p>
    <?php else: ?>
      <div class="lib-filters">
        <input class="input" type="search" placeholder="Search file name…" data-lib-search aria-label="Search images" />
        <select class="input" data-lib-folder aria-label="Folder" <?= count($folders) < 2 ? 'hidden' : '' ?>>
          <option value=""><?= ['models' => 'All models', 'outfits' => 'All accessories'][$tab] ?? 'All folders' ?></option>
          <?php foreach ($folders as $f): ?><option value="<?= e($f) ?>"><?= e($names[$f] ?? $f) ?></option><?php endforeach ?>
        </select>
        <label class="field check lib-unused"><input type="checkbox" data-lib-unused <?= isset($_GET['unused']) ? 'checked' : '' ?> /><span class="lbl">Unused only<?= $tabUnused ? ' (' . $tabUnused . ')' : '' ?></span></label>
        <span class="muted small" data-lib-count></span>
      </div>

      <div class="lib" data-lib>
        <?php foreach ($items as $i): $u = $use[$i['url']] ?? []; ?>
          <figure class="lib-card" data-url="<?= e($i['url']) ?>" data-folder="<?= e($i['group']) ?>" data-name="<?= e(strtolower($i['name'])) ?>" data-used="<?= $u ? '1' : '0' ?>">
            <a class="lib-img" href="<?= e($i['url']) ?>" target="_blank" title="Open full size"><img src="<?= e($i['small']) ?>" alt="" loading="lazy" decoding="async" /></a>
            <figcaption>
              <span class="lib-name" title="<?= e($i['name']) ?>"><?= e($i['name']) ?></span>
              <!-- same rows on every card: name, size, where it's used (one line each, full text on hover), buttons at the bottom -->
              <span class="muted small lib-meta"><?= $i['w'] ?>×<?= $i['h'] ?> · <?= $fmt($i['bytes']) ?></span>
              <span class="small lib-use" title="<?= e($u ? 'Used by ' . implode(', ', $u) : 'Not used · folder: ' . ($names[$i['group']] ?? $i['group'])) ?>">
                <?php if ($u): ?>Used by <?= implode(', ', array_map(function ($key, $n) { [$k, $sl] = explode(':', $key, 2); $href = match ($k) { 'faq' => '/admin/faq.php', 'docs' => '/admin/docs.php?page=' . e($sl), default => '/admin/edit.php?kind=' . e($k) . '&slug=' . e($sl) }; return '<a href="' . $href . '">' . e($n) . '</a>'; }, array_keys($u), $u)) ?>
                <?php else: ?><span class="warn-dot">Not used</span> <span class="muted">· <?= e($names[$i['group']] ?? $i['group']) ?></span><?php endif ?>
              </span>
              <span class="row-btns">
                <button type="button" class="btn btn-ghost btn-sm" data-copy title="Copy the image address">Copy link</button>
                <?php if (!str_ends_with(strtolower($i['name']), '.gif')): ?><button type="button" class="btn btn-ghost btn-sm" data-crop data-used-count="<?= count($u) ?>">Crop</button><?php endif ?>
                <button type="button" class="icon-btn del" data-del title="<?= $u ? 'Used by a model: remove it there first' : 'Delete' ?>" aria-label="Delete" <?= $u ? 'disabled' : '' ?>>✕</button>
              </span>
            </figcaption>
          </figure>
        <?php endforeach ?>
      </div>
      <p class="muted small empty-lib" data-lib-empty hidden>No images match.</p>
    <?php endif ?>
<?php admin_end();
