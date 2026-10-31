<?php
// Admin → Assets → Pages → a page (app/tools.php): its type at the top (Simple Page | Tool, `[data-page-type]` in admin.js: switching warns what
// goes), name and address; a simple page: its text; a tool: the header (logo, screenshot, intro, buttons), features, help links and options; both:
// its tutorials (a list: they are written in tool-tutorials.php). ?id=<page id>; without one, a new page (a simple page unless ?type=tool; saved as a draft).
require __DIR__ . '/_admin.php';
admin_require($M3D);

$all = m3d_tools()['tools'];
$id = m3d_get_text('id');
$tool = $id !== '' ? m3d_tool_by_id($id) : null;
if ($id !== '' && !$tool) m3d_redirect('/admin/tools.php');
$done = $error = null;
$form = null;   // what was typed, when a save is refused

if (admin_form_post('save')) {
    try {
        if (($_POST['rev'] ?? '') !== admin_tool_rev($tool)) throw new InvalidArgumentException('This page was changed in another tab: reload the page (your changes here are not saved).');
        $new = clean_tool($_POST, $tool, $all);
        if (!$tool && !isset($_POST['vis'])) $new['draft'] = true;
        $list = m3d_tools(true)['tools'];
        $at = null;
        foreach ($list as $i => $t) if (($t['id'] ?? '') === ($tool['id'] ?? '-')) $at = $i;
        if ($at === null) $list[] = $new; else { $new['groups'] = $list[$at]['groups'] ?? []; $list[$at] = $new; }
        if (!m3d_save_tools($list)) throw new InvalidArgumentException(m3d_write_error('tools'));
        if (!$tool) m3d_redirect('/admin/tool.php?id=' . rawurlencode($new['id']) . '&saved=1');
        $tool = m3d_tool_by_id($new['id']);
        $done = 'Page saved.';
    } catch (InvalidArgumentException $ex) {
        $error = $ex->getMessage();
        $form = $_POST;
    }
}
if (admin_form_post('delete') && $tool) {
    $list = array_values(array_filter(m3d_tools(true)['tools'], fn($t) => ($t['id'] ?? '') !== $tool['id']));
    if (m3d_save_tools($list)) m3d_redirect('/admin/tools.php?deleted=1');
    $error = m3d_write_error('tools');
}
if (isset($_GET['saved'])) $done = 'Page saved.';

// the values shown: what was typed after a refused save, else the stored tool, else a new one
$blank = ['type' => m3d_get_text('type') === 'tool' ? 'tool' : 'page', 'name' => '', 'slug' => '', 'logo' => '', 'shot' => '', 'intro' => '', 'buttons' => [], 'features' => [], 'help' => [], 'groups' => [], 'draft' => true, 'menu' => true, 'opts' => [], 'issues' => ''];
$v = ($tool ?? []) + $blank;   // a simple page has no tool fields: empty ones
if ($tool) $v['type'] = m3d_page_type($tool);   // a stored tool has no `type`
if ($form) {
    $rows = function (string $p, array $fields) use ($form): array {
        $out = [];
        foreach ((array) ($form[$p . '_' . $fields[0]] ?? []) as $i => $_) { $r = []; foreach ($fields as $f) $r[$f] = (string) ($form[$p . '_' . $f][$i] ?? ''); $out[] = $r; }
        return $out;
    };
    $v = ['type' => ($form['type'] ?? '') === 'page' ? 'page' : 'tool', 'name' => (string) ($form['name'] ?? ''), 'slug' => (string) ($form['slug'] ?? ''), 'logo' => (string) ($form['logo'] ?? ''),
        'shot' => (string) ($form['shot'] ?? ''), 'intro' => (string) ($form['intro'] ?? ''), 'buttons' => $rows('bt', ['label', 'url']), 'features' => $rows('ft', ['title', 'text']),
        'help' => $rows('hp', ['title', 'text', 'url', 'cta']), 'groups' => $tool['groups'] ?? [], 'draft' => ($form['vis'] ?? '') === 'draft', 'menu' => !empty($form['menu']),
        'opts' => array_keys(array_filter((array) ($form['opts'] ?? []))), 'issues' => (string) ($form['issues'] ?? '')];
}
$type = m3d_page_type($v);
$isPage = $type === 'page';
$folder = 'tool-' . ($tool['slug'] ?? 'new');
$name = $tool['name'] ?? '';

$handle = '<button type="button" class="drag-handle drag-sm" data-row-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>';
$del = '<button type="button" class="icon-btn del" data-row-del title="Remove" aria-label="Remove">✕</button>';
$in = fn(string $n, string $val, int $max, string $ph, string $extra = '') => '<input class="input" name="' . $n . '[]" value="' . e($val) . '" maxlength="' . $max . '" placeholder="' . e($ph) . '" aria-label="' . e($ph) . '"' . $extra . ' />';
$btRow = fn(array $b = []) => '<div class="start-row tool-row tool-row-2" data-row>' . $handle . $in('bt_label', (string) ($b['label'] ?? ''), 40, 'Text') . $in('bt_url', (string) ($b['url'] ?? ''), 500, 'https://… or /page/', ' spellcheck="false"') . $del . '</div>';
$ftRow = fn(array $f = []) => '<div class="start-row tool-row tool-row-ft" data-row>' . $handle . $in('ft_title', (string) ($f['title'] ?? ''), 40, 'Title') . $in('ft_text', (string) ($f['text'] ?? ''), 200, 'One line about it') . $del . '</div>';
$hpRow = fn(array $h = []) => '<div class="start-row tool-row tool-row-hp" data-row>' . $handle . $in('hp_title', (string) ($h['title'] ?? ''), 60, 'Title') . $in('hp_text', (string) ($h['text'] ?? ''), 300, 'One line about it')
    . $in('hp_url', (string) ($h['url'] ?? ''), 500, 'https://… or /page/', ' spellcheck="false"') . $in('hp_cta', (string) ($h['cta'] ?? ''), 30, 'Button text (Open)') . $del . '</div>';
$img = function (string $field, string $label, string $val, string $note) use ($folder): string {
    return '<div class="field image-field" data-img-pick="' . e($folder) . '"><span class="lbl">' . e($label) . '</span><p class="img-note">' . e($note) . '</p><div class="img-row">'
        . '<div class="img-prev">' . ($val !== '' ? '<img src="' . e($val) . '" alt="" />' : '<span class="muted small">No Image</span>') . '</div>'
        . '<div class="img-ctl"><input class="input" type="text" name="' . $field . '" value="' . e($val) . '" placeholder="/media/… or https://…" maxlength="1000" spellcheck="false" />'
        . '<div class="row-btns"><button type="button" class="btn btn-ghost btn-sm" data-img-choose>Choose…</button><button type="button" class="btn btn-ghost btn-sm" data-img-clear>Remove</button></div></div></div></div>';
};
$opt = fn(string $k) => in_array($k, (array) $v['opts'], true) ? ' checked' : '';
$tName = trim((string) $v['name']) !== '' ? $v['name'] : 'This Tool';

// the parts of one type only: hidden and left out of the form (disabled) while the other type is picked
$only = fn(string $t) => ' data-for="' . $t . '"' . ($t !== $type ? ' disabled hidden' : '');
admin_start($tool ? 'Edit ' . $name : 'New Page', 'tools');
?>
    <div class="admin-head sticky-head">
      <div>
        <a class="back" href="/admin/tools.php">← All Pages</a>
        <h1><?= $tool ? e($name) : 'New Page' ?></h1>
      </div>
      <div class="head-actions">
        <?php if ($tool): ?><a class="btn btn-ghost" href="/admin/tool-tutorials.php?id=<?= e(rawurlencode((string) $tool['id'])) ?>">Tutorials</a><a class="btn btn-ghost" href="<?= e(m3d_tool_url($tool)) ?>">View Page</a><?php endif ?>
        <button class="btn btn-primary" form="tool-form">Save</button>
      </div>
    </div>
    <?php admin_result($done, $error) ?>

    <form method="post" class="editor tool-editor" id="tool-form" data-rows-form data-page-type="<?= $type ?>" data-saved="<?= $tool ? 1 : 0 ?>">
      <?= form_keys('save') ?>
      <input type="hidden" name="rev" value="<?= e(admin_tool_rev($tool)) ?>" />
      <?= admin_site_pages_datalist() ?>

      <fieldset class="sec"><legend>Basics</legend>
        <div class="vis-seg pg-seg" role="radiogroup" aria-label="Type">
          <?php foreach (PAGE_TYPES as $k => $label): ?><label class="vis-opt"><input type="radio" name="type" value="<?= $k ?>"<?= $k === $type ? ' checked' : '' ?> /><span class="vis-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><?= page_type_icon($k) ?></svg></span><span><?= e($label) ?></span></label><?php endforeach ?>
        </div>
        <div class="grid2 tool-basics">
          <label class="field"><span class="lbl"><span data-for="page"<?= $isPage ? '' : ' hidden' ?>>Title</span><span data-for="tool"<?= $isPage ? ' hidden' : '' ?>>Name</span></span><input class="input" name="name" value="<?= e($v['name']) ?>" maxlength="60" required placeholder="<?= $isPage ? 'e.g. Commissions' : 'e.g. MustardUI' ?>" /></label>
          <label class="field"><span class="lbl">Page Address</span><span class="prefixed"><span class="muted" data-for="tool"<?= $isPage ? ' hidden' : '' ?>>/tools/</span><span class="muted" data-for="page"<?= $isPage ? '' : ' hidden' ?>>/pages/</span><input class="input" name="slug" value="<?= e($v['slug']) ?>" maxlength="40" pattern="[a-z0-9][a-z0-9\-]*" spellcheck="false" placeholder="from the name" /><span class="muted">/</span></span></label>
        </div>
        <div class="tool-vis">
          <div class="vis-seg" role="radiogroup" aria-label="Visibility">
            <label class="vis-opt"><input type="radio" name="vis" value="public"<?= empty($v['draft']) ? ' checked' : '' ?> /><span class="vis-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg></span><span>Public</span></label>
            <label class="vis-opt"><input type="radio" name="vis" value="draft"<?= !empty($v['draft']) ? ' checked' : '' ?> /><span class="vis-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4zM13.5 6.5l4 4"/></svg></span><span>Draft</span></label>
          </div>
          <fieldset class="bare"<?= $only('tool') ?>><label class="toggle"><input type="checkbox" class="switch" name="menu" value="1"<?= !empty($v['menu']) ? ' checked' : '' ?> /><span>Show in the Header Menu</span></label></fieldset>
        </div>
      </fieldset>

      <fieldset class="sec"<?= $only('page') ?>><legend>Text</legend>
        <textarea class="input pg-body" name="intro" rows="16" maxlength="100000" data-img-drop="<?= e($folder) ?>" aria-label="Text"><?= e($v['intro']) ?></textarea>
        <?= format_help('How to Format the Text', ['box', 'fold', 'image', 'carousel', 'card', 'tabs']) ?>
      </fieldset>

      <fieldset class="sec"<?= $only('tool') ?>><legend>Header</legend>
        <div class="grid2">
          <?= $img('logo', 'Logo', (string) $v['logo'], 'Left of the name. A clear background works best.') ?>
          <?= $img('shot', 'Screenshot', (string) $v['shot'], 'Right of the text; a click opens it big.') ?>
        </div>
        <label class="field"><span class="lbl">Intro</span><textarea class="input" name="intro" rows="3" maxlength="3000"><?= e($v['intro']) ?></textarea></label>
        <div class="field" data-rows-box><span class="lbl">Buttons</span>
          <div class="start-rows" data-rows><?php foreach ($v['buttons'] as $b) echo $btRow($b); ?></div>
          <template data-row-template><?= $btRow() ?></template>
          <div class="tool-adds"><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add Button</button><span class="muted small">The first one is yellow.</span></div>
        </div>
      </fieldset>

      <fieldset class="sec" id="tutorials"><legend>Tutorials</legend>
        <?php $tuts = $tool ? m3d_tool_tuts($tool, true) : []; ?>
        <?php if (!$tool): ?>
          <p class="muted small">Save the page first, then add its tutorials.</p>
        <?php elseif (!$tuts): ?>
          <p class="muted small">No tutorials yet.</p>
        <?php else: $group = null; ?>
          <div class="tool-tuts">
            <?php foreach ($tuts as $t): ?>
              <?php if ($t['group'] !== $group): $group = $t['group']; ?><p class="tool-tuts-g"><?= e($group !== '' ? $group : 'Tutorials') ?></p><?php endif ?>
              <a class="tool-tut" href="/admin/tool-tutorials.php?id=<?= e(rawurlencode((string) $tool['id'])) ?>&amp;tut=<?= e(rawurlencode((string) $t['id'])) ?>">
                <?= tool_tut_cover($tool, $t, '120px') ?>
                <span><b><?= e($t['title']) ?><?= !empty($t['draft']) ? ' <span class="badge tone-grey">Draft</span>' : '' ?><?= !empty($t['start']) ? ' <span class="badge tone-mustard">★ Start Here</span>' : '' ?></b>
                <span class="tut-row-meta"><?= tool_level_pill((string) ($t['level'] ?? '')) ?><span class="muted small"><?= e(m3d_tut_kind($t)) ?></span></span></span>
                <span class="btn btn-ghost btn-sm">Edit</span>
              </a>
            <?php endforeach ?>
          </div>
        <?php endif ?>
        <?php if ($tool): ?><div><a class="btn btn-ghost btn-sm" href="/admin/tool-tutorials.php?id=<?= e(rawurlencode((string) $tool['id'])) ?>&amp;new=1">+ Add Tutorial</a></div><?php endif ?>
      </fieldset>

      <fieldset class="sec" data-rows-box<?= $only('tool') ?>><legend>Features</legend>
        <div class="start-rows" data-rows><?php foreach ($v['features'] as $f) echo $ftRow($f); ?></div>
        <template data-row-template><?= $ftRow() ?></template>
        <div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add Feature</button></div>
      </fieldset>

      <fieldset class="sec" data-rows-box<?= $only('tool') ?>><legend>Help Links</legend>
        <div class="start-rows" data-rows><?php foreach ($v['help'] as $h) echo $hpRow($h); ?></div>
        <template data-row-template><?= $hpRow() ?></template>
        <div><button type="button" class="btn btn-ghost btn-sm" data-row-add>+ Add Help Link</button></div>
      </fieldset>

      <fieldset class="sec"<?= $only('tool') ?>><legend>Options</legend>
        <label class="field tool-issues"><span class="lbl">Where Its Bugs Go</span><input class="input" name="issues" value="<?= e($v['issues']) ?>" maxlength="500" placeholder="https://github.com/…/issues" spellcheck="false" /></label>
        <label class="toggle"><input type="checkbox" class="switch" name="opts[bugver]" value="1"<?= $opt('bugver') ?> /><span>Ask Its Version in Bug Reports</span></label>
        <label class="toggle"><input type="checkbox" class="switch" name="opts[badge]" value="1"<?= $opt('badge') ?> /><span>“New <?= e($tName) ?>” Badge in Changelogs</span></label>
        <label class="toggle"><input type="checkbox" class="switch" name="opts[welcome]" value="1"<?= $opt('welcome') ?> /><span>Link on the Welcome Page</span></label>
        <label class="toggle"><input type="checkbox" class="switch" name="opts[contacts]" value="1"<?= $opt('contacts') ?> /><span>Card on the Contacts Page</span></label>
      </fieldset>
    </form>

    <?php if ($tool): ?>
      <section class="danger">
        <h2>Delete This Page</h2>
        <p class="muted small">Its tutorials go too. Uploaded images stay; a backup is kept.</p>
        <form method="post" onsubmit="return confirm(<?= e(json_encode('Delete ' . $name . ' and its tutorials?')) ?>)"><?= form_keys('delete') ?><button class="btn btn-danger">Delete <?= e($name) ?></button></form>
      </section>
    <?php endif ?>
<?php admin_end();
