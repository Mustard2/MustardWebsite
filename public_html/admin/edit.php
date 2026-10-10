<?php
// Editor for a model (default) or an outfit (?kind=outfits). New entry when there is no ?slug=.
// The form is built by admin.js from the JSON below.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$kind = ($_GET['kind'] ?? '') === 'outfits' ? 'outfits' : 'models';
$what = $kind === 'outfits' ? 'accessory' : 'model';
$list = $kind === 'outfits' ? '/admin/outfits.php' : '/admin/';
$slug = (string) ($_GET['slug'] ?? '');
$item = $slug !== '' ? m3d_item($kind, $slug) : null;
if ($slug !== '' && !$item) m3d_redirect($list);
$isNew = $item === null;
$models = m3d_models();
if ($isNew) {
    $item = $kind === 'outfits'
        ? ['slug' => '', 'name' => '', 'models' => [], 'images' => [], 'links' => []]
        : ['slug' => '', 'name' => '', 'franchise' => '', 'status' => 'in-development', 'links' => []];
}
$item['addons'] = m3d_addons($kind, $item);
// the password hash never goes to the browser: the editor only learns whether one is set
$item['hasPassword'] = !empty($item['passwordHash']);
unset($item['passwordHash']);
$payload = [
    'kind'       => $kind,
    'isNew'      => $isNew,
    'item'       => $item,
    'franchises' => array_values(array_unique(array_filter(array_map(fn($m) => $m['franchise'] ?? '', $models)))),
    'statuses'   => array_map(fn($s) => $s[0], M3D_STATUSES),
    'currency'   => m3d_currency(),
    'types'      => array_values(array_unique(array_merge(M3D_ACCESSORY_TYPES, array_map('m3d_accessory_type', m3d_outfits())))),
    'visibility' => M3D_VISIBILITY,
    'models'     => array_map(fn($m) => ['slug' => $m['slug'], 'name' => $m['name'] . (($m['generation'] ?? '') !== '' ? ' (Gen ' . $m['generation'] . ')' : ''), 'thumb' => img_small(($m['thumb'] ?? '') ?: ($m['cover'] ?? ''))], $models),
];
$public = m3d_item_url($kind, $item['slug']);

admin_start($isNew ? "New $what" : 'Edit ' . $item['name'], $kind);
?>
    <div class="admin-head sticky-head">
      <div>
        <a class="back" href="<?= e($list) ?>">← All <?= $kind === 'outfits' ? 'accessories' : 'models' ?></a>
        <h1><?= $isNew ? "New $what" : e($item['name']) ?></h1>
      </div>
      <div class="head-actions">
        <?php if (!$isNew): ?><a class="btn btn-ghost" href="<?= e($public) ?>" target="_blank">View page ↗</a><?php endif ?>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
      <nav class="set-menu ed-menu" aria-label="Sections" data-ed-menu hidden></nav>   <!-- admin.js: one button per section of the form -->
    </div>

    <?= format_help('How to format texts', ['para', 'inline', 'list', 'steps', 'box'],
        'Works in the description, notes and changelog notes. Single lines (features, changes, ideas, issues, credits…) take <b>bold</b>, <i>italic</i>, <code>code</code> and links.') ?>

    <form class="editor" data-editor novalidate></form>

    <?php if (!$isNew): ?>
      <section class="danger">
        <h2>Delete this <?= $what ?></h2>
        <p class="muted small">Uploaded images stay; a backup is kept.</p>
        <button class="btn btn-danger" data-delete>Delete <?= e($item['name']) ?></button>
      </section>
    <?php endif ?>

    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
