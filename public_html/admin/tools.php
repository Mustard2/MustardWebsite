<?php
// Admin → Assets → Pages: the tool and simple pages (app/tools.php), each with its type pill, opening its editor (tool.php) and its tutorials
// (tool-tutorials.php). + Add Page opens a new simple page (its editor switches it to a tool).
require __DIR__ . '/_admin.php';
admin_require($M3D);

$tools = m3d_tools()['tools'];
admin_start('Pages', 'tools');
?>
    <div class="admin-head">
      <div>
        <h1>Pages</h1>
      </div>
      <a class="btn btn-primary" href="/admin/tool.php">+ Add Page</a>
    </div>
    <?= assets_menu('tools') ?>

    <?php if (!$tools): ?>
      <p class="muted">No pages yet.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table class="models-table tools-table">
          <thead><tr><th></th><th>Page</th><th>Tutorials</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($tools as $t): $n = count(m3d_tool_tuts($t, true)); $edit = '/admin/tool.php?id=' . rawurlencode((string) $t['id']); ?>
              <tr>
                <td class="c-thumb"><?php if (($t['logo'] ?? '') !== ''): ?><img src="<?= e(img_small($t['logo'])) ?>" alt="" loading="lazy" style="object-fit:contain" /><?php else: ?><span class="no-img pg-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><?= page_type_icon(m3d_page_type($t)) ?></svg></span><?php endif ?></td>
                <td><div class="m-line"><a class="m-name" href="<?= e($edit) ?>"><?= e($t['name']) ?></a><?= page_type_pill($t) ?><?= !empty($t['draft']) ? ' <span class="badge tone-grey" title="Only admins can see it">Draft</span>' : '' ?></div></td>
                <td class="small"><a href="/admin/tool-tutorials.php?id=<?= e(rawurlencode((string) $t['id'])) ?>"><?= $n ? m3d_plural($n, 'tutorial') : 'None yet' ?></a></td>
                <td class="c-actions"><a class="btn btn-ghost btn-sm" href="<?= e($edit) ?>">Edit</a> <a class="btn btn-ghost btn-sm" href="<?= e(m3d_tool_url($t)) ?>" target="_blank">Go to Page ↗</a></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      </div>
    <?php endif ?>
<?php admin_end();
