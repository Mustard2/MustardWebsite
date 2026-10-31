<?php
// Admin → Assets → Link check: every download link with the result of its last check (the checks are in app/links.php). Nothing runs by itself:
// "Check all links" / "Check again" (admin.js, [data-lc]) post {h: md5 of the address} here, one address per request (the automatic check,
// when switched on in the "Automatic Check" box, is lc_auto_tick() in app/links.php). Only MEGA and transfer.it links by default: other
// websites are added in the "Other Websites" box (settings.json → linkcheck.sites; its "+ website" buttons post {add: website}); patrons' render
// links with the "Patron Renders" switch (linkcheck.renders), whose dead rows have Remove Link ({unfull: render id}).
require __DIR__ . '/_admin.php';

// ── The table's rows ──
/** "Today 20:15 CEST", "Yesterday 09:02 CEST", "Mon 5 Oct, 09:02 CEST" (site time), or "Never". */
function lc_when(?int $t): string
{
    if (!$t) return 'Never';
    $tz = m3d_tz();
    $d = (new DateTimeImmutable('@' . $t))->setTimezone($tz);
    $today = new DateTimeImmutable('today', $tz);
    if ($d >= $today) return 'Today ' . $d->format('H:i T');
    if ($d >= $today->modify('-1 day')) return 'Yesterday ' . $d->format('H:i T');
    return $d->format('D j M, H:i T');
}

/** One row of the Link check table. */
function lc_row(array $l, ?array $r): string
{
    $st = in_array($r['st'] ?? '', LC_STATES, true) ? $r['st'] : 'new';
    $badge = match ($st) {
        'ok'      => '<span class="badge tone-green">✓ Works</span>',
        'dead'    => '<span class="badge tone-red">✗ Dead</span>',
        'unknown' => '<span class="badge tone-amber">? Couldn’t Check</span>',
        default   => '<span class="badge tone-grey">Not Checked</span>',
    };
    $why = (string) ($r['why'] ?? '');
    $search = admin_search_text([$l['name'], $l['sub'], $l['label'], $l['host'], $l['url'], $why]);
    $render = $l['kind'] === 'render' ? (string) $l['render'] : '';
    return '<tr data-lc-row="' . e($l['h']) . '" data-url="' . e($l['url']) . '" data-st="' . $st . '" data-group="' . lc_group($l['host']) . '"' . (isset($l['render']) && ($render !== '' || $l['url'] === $l['full']) ? ' data-lc-render="' . e((string) $l['render']) . '"' : '') . ' data-search="' . e($search) . '"' . ($st === 'dead' ? ' class="is-dead"' : '') . '>'
        . '<td><a class="m-name" href="' . e($l['edit']) . '">' . e($l['name']) . '</a><div class="muted small">' . e($l['sub']) . '</div></td>'
        . '<td>' . e($l['label']) . '<div class="muted small">' . e($l['access']) . '</div></td>'
        . '<td class="lc-host"><a href="' . e((m3d_s4_on() ? m3d_s4_presign($l['url'], 'GET', 3600) : null) ?? (m3d_s4_resolve($l['url']) ?: '#')) . '" target="_blank" rel="noopener noreferrer" title="' . e($l['url']) . '">' . e($l['host']) . ' ↗</a></td>'
        . '<td class="lc-st">' . $badge . ($why !== '' ? '<div class="lc-why">' . e($why) . '</div>' : '') . '</td>'
        . '<td class="small muted lc-at">' . e(lc_when(isset($r['at']) ? (int) $r['at'] : null)) . '</td>'
        . '<td class="c-actions">' . ($st === 'dead' && $render !== '' ? '<button type="button" class="btn btn-ghost btn-sm" data-lc-unfull="' . e($render) . '">Remove Link</button>' : '')
        . ($st === 'dead' ? '<button type="button" class="btn btn-ghost btn-sm" data-lc-replace>Replace…</button>' : '')
        . '<button type="button" class="btn btn-ghost btn-sm" data-lc-one>Check Again</button>'
        . '<a class="btn btn-ghost btn-sm" href="' . e($l['edit']) . '">Edit</a></td></tr>';
}

// ── JSON: check one link ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    admin_post_guard($M3D);
    $in = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($in) && isset($in['add'])) {
        $d = lc_clean_domain(is_string($in['add']) ? $in['add'] : '');
        if ($d === '') admin_json(['ok' => false, 'error' => 'Not a website address.'], 400);
        $list = array_values(array_unique([...lc_sites(), $d]));
        if (count($list) > LC_SITES_MAX) admin_json(['ok' => false, 'error' => 'At most ' . LC_SITES_MAX . ' websites.'], 400);
        if (!lc_settings_save(['sites' => $list])) admin_json(['ok' => false, 'error' => m3d_write_error('settings')], 500);
        admin_json(['ok' => true]);
    }
    if (is_array($in) && isset($in['replace'])) {   // Replace a Link Everywhere: {replace: md5 of the old address, to: the new one}
        $from = array_values(array_filter(lc_all_links(), fn($l) => $l['h'] === $in['replace']));
        if (!$from) admin_json(['ok' => false, 'error' => 'This link isn’t on the site any more. Reload the page.'], 404);
        try { $to = clean_dl_url(is_string($in['to'] ?? null) ? $in['to'] : ''); }
        catch (InvalidArgumentException) { $to = ''; }
        if ($to === '') admin_json(['ok' => false, 'error' => 'Write the new address, starting with https://'], 400);
        if ($to === $from[0]['url']) admin_json(['ok' => false, 'error' => 'That is the same address.'], 400);
        $n = lc_replace_everywhere($from[0]['url'], $to);
        if ($n === null) admin_json(['ok' => false, 'error' => 'Not saved: the data files couldn’t be written. Nothing else was changed after that.'], 500);
        if ($n === 0) admin_json(['ok' => false, 'error' => 'A patron render’s link must be a web address starting with https://' . (m3d_s4_on() ? ' or a file in your S4 bucket' : '')], 400);   // only renders use it: "s4:" only while S4 downloads are on
        admin_json(['ok' => true, 'n' => $n, 'h' => md5($to), 'listed' => lc_group(lc_host($to)) !== 'other' || lc_site_added($to) || (lc_renders_on() && isset($from[0]['render']))]);
    }
    if (is_array($in) && isset($in['unfull'])) {   // Remove Link on a patron render's dead row: the render and its Gallery copy lose that link (the render stays)
        $id = is_string($in['unfull']) && preg_match('/^[0-9a-f]{8}$/D', $in['unfull']) ? $in['unfull'] : '';
        $r = $id !== '' ? m3d_render($id) : null;
        $url = trim((string) ($r['full'] ?? ''));
        if ($url === '') admin_json(['ok' => false, 'error' => 'This render has no full-quality link any more. Reload the page.'], 404);
        $res = m3d_renders_update(function (array $rs) use ($id) {
            foreach ($rs as &$x) if (($x['id'] ?? '') === $id) unset($x['full']);
            unset($x);
            return [$rs, true];
        });
        if ($res === null) admin_json(['ok' => false, 'error' => m3d_write_error('data')], 500);
        $g = m3d_gallery(true);
        $hit = 0;
        foreach ($g['stills'] as &$st) if (($st['from'] ?? '') === $id && trim((string) ($st['link']['url'] ?? '')) === $url) { unset($st['link']); $hit++; }
        unset($st);
        if ($hit && !m3d_save_gallery($g)) admin_json(['ok' => false, 'error' => 'The render’s link is gone, but its Gallery copy couldn’t be saved: remove its link in Gallery.'], 500);
        admin_json(['ok' => true, 'gallery' => $hit > 0]);
    }
    $h = is_array($in) && is_string($in['h'] ?? null) ? $in['h'] : '';
    $same = array_values(array_filter(lc_links(), fn($l) => $l['h'] === $h));   // the same address can be on several items: one check, every row
    if (!$same) admin_json(['ok' => false, 'error' => 'This link isn’t on the site any more. Reload the page.'], 404);
    @set_time_limit(60);
    $res = lc_check($same[0]['url']);
    lc_save($h, $res);
    $saved = lc_results()[$h] ?? ($res + ['at' => time()]);
    admin_json(['ok' => true, 'st' => $res['st'], 'slow' => !empty($res['slow']), 'rows' => array_map(fn($l) => lc_row($l, $saved), $same)]);
}

admin_require($M3D);
$done = $error = $open = '';   // $open: the box to show unfolded after its save
if (admin_form_post('auto-check')) {
    $open = 'auto';
    $on = !empty($_POST['auto']);
    $every = (int) ($_POST['every'] ?? 0);
    if ($every < 1 || $every > LC_EVERY_MAX) $error = 'Days: a number from 1 to ' . LC_EVERY_MAX . '.';
    elseif (lc_settings_save(['auto' => $on, 'every' => $every])) {
        if ($on) lc_set_next(0);   // start at the next page
        $done = 'Automatic check saved.';
    } else $error = m3d_write_error('settings');
}
if (admin_form_post('renders-check')) {
    $open = 'renders';
    $on = !empty($_POST['renders']);
    if (lc_settings_save(['renders' => $on])) $done = 'Patron renders saved.';
    else $error = m3d_write_error('settings');
}
if (admin_form_post('other-sites')) {
    $open = 'sites';
    $list = $bad = [];
    foreach (preg_split('/[\r\n,]+/', (string) ($_POST['sites'] ?? '')) ?: [] as $w) {
        if (trim($w) === '') continue;
        ($d = lc_clean_domain($w)) !== '' ? $list[$d] = true : $bad[] = $w;
    }
    if ($bad) $error = 'Not a website: ' . implode(', ', array_slice($bad, 0, 5)) . '. Write one per line, like patreon.com.';
    elseif (count($list) > LC_SITES_MAX) $error = 'At most ' . LC_SITES_MAX . ' websites.';
    elseif (lc_settings_save(['sites' => array_keys($list)])) $done = 'Other websites saved.';
    else $error = m3d_write_error('settings');
}

$links = lc_links();
$res = lc_results();
$count = ['dead' => 0, 'unknown' => 0, 'ok' => 0, 'new' => 0];
$groups = ['mega' => 0, 'transfer' => 0, 'other' => 0];
$nRenders = 0;
foreach ($links as $l) {
    $st = $res[$l['h']]['st'] ?? 'new';
    $count[isset($count[$st]) ? $st : 'new']++;
    $groups[lc_group($l['host'])]++;
    if (isset($l['render']) && ($l['kind'] === 'render' || $l['url'] === $l['full'])) $nRenders++;
}
$problems = $count['dead'] + $count['unknown'];
$sites = lc_sites();
$others = lc_other_sites();
$auto = lc_auto();
$rendersOn = lc_renders_on();
$rendersBox = $rendersOn || m3d_showcase_on() || in_array('render', array_column(lc_all_links(), 'kind'), true);   // only on sites that use patron renders

admin_start('Link Check', 'models');
?>
    <div class="admin-head">
      <div>
        <h1>Link Check</h1>
      </div>
      <div class="row-btns"><?php if ($links): ?><span class="muted small lc-progress" data-lc-progress hidden></span><button type="button" class="btn btn-primary" data-lc-all>Check All Links</button><?php endif ?></div>
    </div>
    <?= assets_menu('links') ?>

    <?php $rep = (int) m3d_get_text('replaced'); if ($rep > 0 && !$done && !$error) $done = 'Link replaced.'; ?>
    <?php admin_result($done, $error) ?>

    <?php if (!$links): ?>
      <p class="muted"><?= $rendersOn && !$others ? 'No download links yet, and no patron render has a full-quality link.' : ($others ? 'No MEGA or transfer.it links yet. Links on other websites are checked once you add the website below.' : 'No download links yet: they appear here once models, accessories or Gallery pictures have some.') ?></p>
    <?php else: ?>
    <div class="stats">
      <button type="button" class="stat" data-lc-tile="dead"><b data-lc-n="dead"><?= $count['dead'] ?></b><span>Dead</span></button>
      <button type="button" class="stat" data-lc-tile="unknown"><b data-lc-n="unknown"><?= $count['unknown'] ?></b><span>Couldn’t Check</span></button>
      <button type="button" class="stat" data-lc-tile="ok"><b data-lc-n="ok"><?= $count['ok'] ?></b><span>Working</span></button>
      <button type="button" class="stat" data-lc-tile="new"><b data-lc-n="new"><?= $count['new'] ?></b><span>Not Checked Yet</span></button>
    </div>

    <div class="tot-bar">
      <span class="tot-kinds" role="group" aria-label="Show">
        <button type="button" class="chip" data-lc-chip="" aria-pressed="<?= $problems ? 'false' : 'true' ?>">All <span><?= count($links) ?></span></button>
        <button type="button" class="chip" data-lc-chip="problems" aria-pressed="<?= $problems ? 'true' : 'false' ?>">Problems <span data-lc-n="problems"><?= $problems ?></span></button>
        <?php foreach (['mega' => 'MEGA', 'transfer' => 'transfer.it', 'other' => 'Other'] as $g => $label): if (!$groups[$g]) continue; ?>
          <button type="button" class="chip" data-lc-chip="<?= $g ?>" aria-pressed="false"><?= $label ?> <span><?= $groups[$g] ?></span></button>
        <?php endforeach ?>
        <?php if ($nRenders): ?><button type="button" class="chip" data-lc-chip="renders" aria-pressed="false">Patron Renders <span><?= $nRenders ?></span></button><?php endif ?>
      </span>
      <label class="batch-search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><input class="input" type="search" data-lc-search placeholder="Search name, link, host…" aria-label="Search the links" autocomplete="off" spellcheck="false" /></label>
    </div>

    <div class="table-wrap">
      <table class="models-table lc-table" data-lc>
        <thead><tr><th>Item</th><th>Link</th><th>Host</th><th>Status</th><th>Checked</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($links as $l) echo lc_row($l, $res[$l['h']] ?? null); ?>
          <tr data-lc-empty hidden><td colspan="6" class="muted">Nothing to show.</td></tr>
        </tbody>
      </table>
    </div>
    <?php endif ?>

    <details class="sec fold lc-box"<?= $open === 'auto' ? ' open' : '' ?>>
      <summary><h2>Automatic Check <?= $auto['on'] ? '<span class="badge tone-green">On · every ' . e(m3d_plural($auto['every'], 'day')) . '</span>' : '<span class="badge tone-grey">Off</span>' ?></h2></summary>
      <form method="post" class="fold-body">
        <?= form_keys('auto-check') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="auto" value="1" <?= $auto['on'] ? 'checked' : '' ?> /><span>Check Links Automatically</span></label>
        <label class="field lc-every"><span class="lbl">Every (Days)<?= tip('Each link is checked again once its last check is this old. The site checks a few links at a time, after its pages are visited.') ?></span><input class="input" type="number" name="every" min="1" max="<?= LC_EVERY_MAX ?>" value="<?= $auto['every'] ?>" required /></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </details>

    <?php if ($rendersBox): ?>
    <details class="sec fold lc-box"<?= $open === 'renders' ? ' open' : '' ?>>
      <summary><h2>Patron Renders <?= $rendersOn ? '<span class="badge tone-green">Checked</span>' : '<span class="badge tone-grey">Not Checked</span>' ?></h2></summary>
      <form method="post" class="fold-body">
        <?= form_keys('renders-check') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="renders" value="1" <?= $rendersOn ? 'checked' : '' ?> /><span>Check Full Quality Links of Patron Renders<?= tip('The links patrons add when they share a render (Media → From Patrons), on any website, waiting ones too. A dead one can be removed from its row: the render stays.') ?></span></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </details>
    <?php endif ?>

    <details class="sec fold lc-box"<?= $open === 'sites' ? ' open' : '' ?>>
      <summary><h2>Other Websites <span class="badge tone-grey"><?= $sites ? count($sites) . ' added' : 'Only MEGA and transfer.it' ?></span></h2></summary>
      <form method="post" class="fold-body">
        <?= form_keys('other-sites') ?>
        <?php if ($others): ?>
          <div class="lc-found"><span class="muted small">In your links:</span>
            <?php foreach (array_slice($others, 0, 12, true) as $d => $n): ?><button type="button" class="chip" data-lc-add="<?= e($d) ?>" title="Check the <?= e(m3d_plural($n, 'link')) ?> on <?= e($d) ?> too">+ <?= e($d) ?> <span><?= $n ?></span></button><?php endforeach ?>
          </div>
        <?php endif ?>
        <label class="field"><span class="lbl">Websites</span><textarea class="input" name="sites" rows="4" spellcheck="false" placeholder="drive.google.com"><?= e(implode("\n", $sites)) ?></textarea></label>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
      </form>
    </details>
<?php admin_end();
