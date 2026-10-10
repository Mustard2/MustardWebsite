<?php
// FAQ editor. The form is built by admin.js from the JSON below.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$faq = m3d_faq();
$payload = ['kind' => 'faq', 'faq' => ['intro' => $faq['intro'] ?? '', 'sections' => $faq['sections'] ?? []]];

admin_start('FAQ', 'faq');
?>
    <div class="admin-head sticky-head">
      <div>
        <h1>FAQ</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="/faq/" target="_blank">View page ↗</a>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
    </div>

    <?= format_help('How to format answers', ['para', 'inline', 'small-h', 'list', 'steps', 'box', 'table', 'image'],
        'Pictures and boxes can also be added with <i>Add image</i> and <i>Box…</i> under each answer.') ?>

    <form class="editor" data-editor novalidate></form>
    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
