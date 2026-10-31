<?php
// Admin → Assets → Tools → a tool's tutorials (app/tools.php): the docs editor (initDocsEditor in admin.js, kind "tools") with groups for sections and
// tutorials for pages, each with a level, Start Here, a cover and a video. ?id=<tool id>&tut=<tutorial id> opens one; &new=1 adds one. Saved by save.php
// (kind "tool-tuts"), only the tool's `groups`.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$tool = m3d_tool_by_id(m3d_get_text('id'));
if (!$tool) m3d_redirect('/admin/tools.php');
$tutId = m3d_get_text('tut');
$open = '';
$sections = [];
foreach ((array) ($tool['groups'] ?? []) as $g) {
    $pages = [];
    foreach ((array) ($g['tuts'] ?? []) as $t) {
        if (($t['id'] ?? '') === $tutId) $open = (string) $t['slug'];
        $pages[] = $t;
    }
    $sections[] = ['title' => (string) ($g['title'] ?? ''), 'text' => (string) ($g['text'] ?? ''), 'pages' => $pages];
}
$payload = ['kind' => 'tools', 'id' => (string) $tool['id'], 'base' => m3d_tool_url($tool), 'folder' => 'tool-' . $tool['slug'],
    'docs' => ['sections' => $sections], 'page' => $open, 'rev' => admin_tuts_rev($tool), 'levels' => TOOL_LEVELS, 'video' => m3d_video_max(), 'addNew' => isset($_GET['new'])];

admin_start($tool['name'] . ' Tutorials', 'tools');
?>
    <div class="admin-head sticky-head">
      <div>
        <a class="back" href="/admin/tool.php?id=<?= e(rawurlencode((string) $tool['id'])) ?>">← <?= e($tool['name']) ?></a>
        <h1><?= e($tool['name']) ?> Tutorials</h1>
      </div>
      <div class="head-actions">
        <a class="btn btn-ghost" href="<?= e(m3d_tool_url($tool)) ?>" data-view-page>View Tutorial</a>
        <button class="btn btn-primary" data-save>Save</button>
      </div>
    </div>

    <?= format_help('How to Format Tutorials', ['box', 'fold', 'image', 'carousel', 'card', 'tabs']) ?>
    <form class="docs-editor" data-editor novalidate></form>
    <script type="application/json" id="editor-data"><?= json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php admin_end();
