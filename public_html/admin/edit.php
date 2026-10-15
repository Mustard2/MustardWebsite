<?php
// Editor for a model (default) or an outfit (?kind=outfits). New entry when there is no ?slug=.
// The form is built by admin.js from the JSON below.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$kind = ($_GET['kind'] ?? '') === 'outfits' ? 'outfits' : 'models';
$what = $kind === 'outfits' ? 'accessory' : 'model';
$list = $kind === 'outfits' ? '/admin/outfits.php' : '/admin/models.php';
$slug = m3d_get_text('slug');
$item = $slug !== '' ? m3d_item($kind, $slug) : null;
if ($slug !== '' && !$item) m3d_redirect($list);
$isNew = $item === null;
$rev = $isNew ? '' : admin_item_rev($item);   // save.php refuses the save when the stored item changed since (another tab, an Images job)
$models = m3d_models();
/** Values in use, most used first: [{v, n, f?}]. $groups (same keys) = the franchise of each game, the one most of its models share;
 *  $extra = values offered even when unused (n 0). Same value with other case / spacing = one entry, in its most used spelling. */
function admin_value_counts(array $vals, array $groups = [], array $extra = []): array {
    $out = [];
    foreach ($vals as $i => $v) {
        $v = trim((string) $v);
        if ($v === '') continue;
        $k = mb_strtolower(preg_replace('/\s+/u', ' ', $v));
        $out[$k]['s'][$v] = ($out[$k]['s'][$v] ?? 0) + 1;
        $g = trim((string) ($groups[$i] ?? ''));
        if ($g !== '') $out[$k]['g'][$g] = ($out[$k]['g'][$g] ?? 0) + 1;
    }
    foreach ($extra as $v) $out[mb_strtolower($v)]['s'][$v] ??= 0;
    $rows = [];
    foreach ($out as $e) {
        arsort($e['s']);
        $row = ['v' => (string) array_key_first($e['s']), 'n' => array_sum($e['s'])];
        if (!empty($e['g'])) { arsort($e['g']); $row['f'] = (string) array_key_first($e['g']); }
        $rows[] = $row;
    }
    usort($rows, fn($a, $b) => $b['n'] <=> $a['n'] ?: strnatcasecmp($a['v'], $b['v']));
    return $rows;
}
if ($isNew) {
    $item = $kind === 'outfits'
        ? ['slug' => '', 'name' => '', 'models' => [], 'images' => [], 'links' => []]
        : ['slug' => '', 'name' => '', 'franchise' => '', 'status' => 'in-development', 'links' => []];
}
$item['addons'] = m3d_addons($item);
if ($kind === 'models') $item['status'] = (string) ($item['status'] ?? '');   // stored status (m3d_status() reads Public past a public date)
// the password hash never goes to the browser: the editor only learns whether one is set
$item['hasPassword'] = !empty($item['passwordHash']);
unset($item['passwordHash']);
$payload = [
    'kind'       => $kind,
    'isNew'      => $isNew,
    'rev'        => $rev,
    'item'       => $item,
    // the Franchise / Game / Type fields' suggestions: every value in use with how many items use it (suggestField() in admin.js)
    'franchises' => admin_value_counts(array_map(fn($m) => $m['franchise'] ?? '', $models)),
    'games'      => admin_value_counts(array_map(fn($m) => $m['game'] ?? '', $models), array_map(fn($m) => $m['franchise'] ?? '', $models)),
    'statuses'   => array_map(fn($s) => $s[0], M3D_STATUSES),
    'currency'   => m3d_currency(),
    'release'    => admin_release_local($item),   // the release dates in Berlin time for the date fields
    'tiers'      => array_map(fn($c, $t) => [$c, $t . ' · ' . m3d_money($c)], array_keys(m3d_known_tiers()), m3d_known_tiers()),   // [cents, "Fan · €10"]: the Access section's tier buttons
    'types'      => admin_value_counts(array_map('m3d_accessory_type', m3d_outfits()), [], M3D_ACCESSORY_TYPES),
    'visibility' => M3D_VISIBILITY,
    'model3d'    => m3d_model3d_facts($item),   // the 3D preview's facts (size, triangles, textures) or why it can't be shown
    's4'         => m3d_s4()['bucket'] !== '' && m3d_s4_on(),   // MEGA S4 on with a bucket: download links may be "s4:<file>" (the S4… picker)
    'deadLinks'  => array_map(fn() => true, lc_dead_for($item)),   // Link Check: these addresses get red fields + a broken chain (markDead() in admin.js)
    'models'     => array_map(fn($m) => ['slug' => $m['slug'], 'name' => admin_item_label('models', $m), 'thumb' => img_small(item_pic('models', $m))], $models),
];
$public = m3d_item_url($kind, $item['slug']);

admin_start($isNew ? 'New ' . ucfirst($what) : 'Edit ' . $item['name'], $kind);
?>
    <div class="admin-head sticky-head">
      <div>
        <a class="back" href="<?= e($list) ?>">← All <?= $kind === 'outfits' ? 'Accessories' : 'Models' ?></a>
        <h1><?= $isNew ? 'New ' . ucfirst($what) : e($item['name']) ?></h1>
      </div>
      <div class="head-actions">
        <?php if (!$isNew): ?><a class="btn btn-ghost" href="<?= e($public) ?>" target="_blank">View Page ↗</a><?php endif ?>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
      <nav class="set-menu ed-menu" aria-label="Sections" data-ed-menu hidden></nav>   <!-- admin.js: one button per section of the form -->
    </div>

    <form class="editor" data-editor novalidate></form>

    <?php if (!$isNew): ?>
      <section class="danger">
        <h2>Delete This <?= ucfirst($what) ?></h2>
        <p class="muted small">Uploaded images stay; a backup is kept.</p>
        <button class="btn btn-danger" data-delete>Delete <?= e($item['name']) ?></button>
      </section>
    <?php endif ?>

    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
