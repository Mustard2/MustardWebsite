<?php
// Docs editor: sections and pages on the left, the selected page on the right. The form is built by admin.js.
require __DIR__ . '/_admin.php';
admin_require($M3D);

// Links under the page list of /docs/ (settings.json → docs_links): their own small form, above the editor
[$linksDone, $linksError] = [null, null];
if (admin_form_post('docs_links')) {
    try {
        $links = admin_posted_links('dl');
        $linksDone = m3d_save_settings(['docs_links' => $links]) ? 'Links saved.' : null;
        if (!$linksDone) $linksError = m3d_write_error();
    } catch (InvalidArgumentException $ex) {
        $linksError = $ex->getMessage();
    }
}

$docs = m3d_docs();
$payload = ['kind' => 'docs', 'docs' => ['sections' => $docs['sections'] ?? []], 'page' => m3d_get_text('page'), 'rev' => admin_text_rev('docs')];

admin_start('Docs', 'docs');
?>
    <div class="admin-head sticky-head">
      <div>
        <h1>Docs</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="/docs/" data-view-page>View Page</a>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
    </div>

    <?php admin_result($linksDone, $linksError) ?>
    <details class="sec intro-sec" id="docs-links"<?= $linksDone || $linksError ? ' open' : '' ?>>
      <summary><b>Links Under the Page List</b> <span class="muted small">on the docs pages, below the list of pages</span></summary>
      <form method="post" class="intro-form start-col" data-rows-form>
        <?= form_keys('docs_links') ?>
        <?= admin_site_pages_datalist() ?>
        <div class="start-rows" data-rows><?php foreach (docs_links() as [$l, $u]) echo admin_link_row('dl', $l, $u); ?></div>
        <template data-row-template><?= admin_link_row('dl') ?></template>
        <div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add Link</button></div>
        <div class="form-actions"><button class="btn btn-primary btn-sm">Save Links</button></div>
      </form>
    </details>

    <?= format_help('How to Format Pages', ['para', 'inline', 'heading', 'list', 'steps', 'box', 'table', 'code', 'image', 'tabs'],
        'Headings appear in <i>On this page</i> and get their own link (not those inside tabs). Tabs show two or more ways to do the same thing: <code>=== Name</code> starts a tab, a line with only <code>===</code> ends them, and tabs with the same names on a page switch together. Site pages can be linked as <code>[Requirements](/docs/requirements/)</code>. The toolbar above the text inserts most of these for you.', true) ?>

    <form class="docs-editor" data-editor novalidate></form>
    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
