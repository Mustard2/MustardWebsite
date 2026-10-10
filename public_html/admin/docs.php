<?php
// Docs editor: sections and pages on the left, the selected page on the right. The form is built by admin.js.
require __DIR__ . '/_admin.php';
admin_require($M3D);

// Links under the page list of /docs/ (settings.json → docs_links): their own small form, above the editor
[$linksDone, $linksError] = [null, null];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'docs_links') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    try {
        $links = admin_posted_links('dl');
        $linksDone = m3d_save_settings(['docs_links' => $links]) ? 'Links saved.' : null;
        if (!$linksDone) $linksError = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
    } catch (InvalidArgumentException $ex) {
        $linksError = $ex->getMessage();
    }
}

$docs = m3d_docs();
$payload = ['kind' => 'docs', 'docs' => ['sections' => $docs['sections'] ?? []], 'page' => (string) ($_GET['page'] ?? '')];

admin_start('Docs', 'docs');
?>
    <div class="admin-head sticky-head">
      <div>
        <h1>Docs</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="/docs/" target="_blank" data-view-page>View page ↗</a>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
    </div>

    <?php if ($linksDone): ?><p class="notice"><b class="res-ok">✓</b> <?= e($linksDone) ?></p><?php endif ?>
    <?php if ($linksError): ?><p class="notice"><b class="res-err">Not saved:</b> <?= e($linksError) ?></p><?php endif ?>
    <details class="sec intro-sec" id="docs-links"<?= $linksDone || $linksError ? ' open' : '' ?>>
      <summary><b>Links under the page list</b> <span class="muted small">on the docs pages, below the list of pages</span></summary>
      <form method="post" class="intro-form start-col" data-rows-form>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="docs_links" />
        <?= admin_site_pages_datalist() ?>
        <div class="start-rows" data-rows><?php foreach (docs_links() as [$l, $u]) echo admin_link_row('dl', $l, $u); ?></div>
        <template data-row-template><?= admin_link_row('dl') ?></template>
        <div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add link</button><?= tip('Links to other websites (https://…) get a ↗ after their text by themselves.') ?></div>
        <div class="row-btns"><button class="btn btn-primary btn-sm">Save links</button></div>
      </form>
    </details>

    <?= format_help('How to format pages', ['para', 'inline', 'heading', 'list', 'steps', 'box', 'table', 'code', 'image'],
        'Headings appear in <i>On this page</i> and get their own link. Site pages can be linked as <code>[Requirements](/docs/requirements/)</code>. The toolbar above the text inserts most of these for you.', true) ?>

    <form class="docs-editor" data-editor novalidate></form>
    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
