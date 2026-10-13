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
if ($isNew) {
    $item = $kind === 'outfits'
        ? ['slug' => '', 'name' => '', 'models' => [], 'images' => [], 'links' => []]
        : ['slug' => '', 'name' => '', 'franchise' => '', 'status' => 'in-development', 'links' => []];
}
$item['addons'] = m3d_addons($item);
if ($kind === 'models') $item['status'] = ($item['status'] ?? '') === 'released-patreon' ? 'preview' : (string) ($item['status'] ?? '');   // stored status (m3d_status() reads Public past a public date)
// the password hash never goes to the browser: the editor only learns whether one is set
$item['hasPassword'] = !empty($item['passwordHash']);
unset($item['passwordHash']);
$payload = [
    'kind'       => $kind,
    'isNew'      => $isNew,
    'rev'        => $rev,
    'item'       => $item,
    'franchises' => array_values(array_unique(array_filter(array_map(fn($m) => $m['franchise'] ?? '', $models)))),
    'statuses'   => array_map(fn($s) => $s[0], M3D_STATUSES),
    'currency'   => m3d_currency(),
    'release'    => admin_release_local($item),   // the release dates in Berlin time for the date fields
    'tiers'      => array_map(fn($c, $t) => [$c, $t . ' · ' . m3d_money($c)], array_keys(m3d_known_tiers()), m3d_known_tiers()),   // [cents, "Fan · €10"]: the Access section's tier buttons
    'types'      => array_values(array_unique(array_merge(M3D_ACCESSORY_TYPES, array_map('m3d_accessory_type', m3d_outfits())))),
    'visibility' => M3D_VISIBILITY,
    'model3d'    => m3d_model3d_facts($item),   // the 3D preview's facts (size, triangles, textures) or why it can't be shown
    'deadLinks'  => array_map(fn() => true, lc_dead_for($item)),   // Link Check: these addresses get red fields + a broken chain (markDead() in admin.js)
    'models'     => array_map(fn($m) => ['slug' => $m['slug'], 'name' => $m['name'] . (($m['generation'] ?? '') !== '' ? ' (Gen ' . $m['generation'] . ')' : ''), 'thumb' => img_small(item_pic('models', $m))], $models),
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
