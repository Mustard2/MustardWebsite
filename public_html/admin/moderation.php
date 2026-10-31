<?php
// Admin → Moderation (app/moderation.php): the creator's admin page, and the only admin page moderators can open (admin_start() gives them the
// "Mustard3D Mod" header: this page only). Tabs Messages (posts waiting for a check; the Show Hidden switch, ?hidden=1, adds the hidden ones, kept,
// greyed like closed bug reports) · Log (the creator only: every action, with Undo) · Moderators (the creator only: admin/_mods.php); a What row
// (Comments / Bug Reports / Ideas, only the kinds this person checks).
// ?hide=<type>:<ref> and ?ban=<type>:<ref> open the Hide and Ban windows (no script needed), also from the ⋯ menus on the pages (&back=<page>).
// Approve / Hide / Ban / Undo post to auth/moderate.php, which comes back with ?mod= / ?moderr= (mod_result_flash(), view.php).
require __DIR__ . '/_admin.php';
require __DIR__ . '/_mods.php';

$role = m3d_mod_role();
if ($role !== 'mod') admin_require($M3D);   // the creator, or the admin's own refusal (login, 403) for everyone else
$creator = $role === 'creator';
[$done, $error] = [null, null];
if ($creator && admin_form_post() && str_starts_with((string) ($_POST['do'] ?? ''), 'mod-')) [$done, $error] = mods_post((string) $_POST['do']);
$kinds = m3d_mod_kinds();
$tab = in_array($_GET['tab'] ?? '', ['log', 'mods'], true) && $creator ? (string) $_GET['tab'] : 'messages';
$showHid = m3d_get_text('hidden') === '1' || m3d_get_text('tab') === 'hidden';   // an old ?tab=hidden address: Messages with the hidden ones
$what = in_array($_GET['what'] ?? '', $kinds, true) ? (string) $_GET['what'] : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$url = function (array $q) use ($tab, $what, $showHid): string {
    $q += ['tab' => $tab === 'messages' ? '' : $tab, 'what' => $what, 'hidden' => $showHid && $tab === 'messages' ? '1' : ''];
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return '/admin/moderation.php' . ($q ? '?' . http_build_query($q) : '');
};
$here = $url([]);
$csrf = '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" />';

// the Hide / Ban window: the post it is about (?hide= / ?ban=), checked like auth/moderate.php will
$dlg = null;
foreach (['hide', 'ban'] as $k) {
    $v = m3d_get_text($k);
    if ($v === '' || !preg_match('/^([a-z]+):([0-9:]+)$/D', $v, $m) || !isset(MOD_TYPES[$m[1]])) continue;
    $t = m3d_mod_target($m[1], $m[2]);
    if ($t && m3d_mod_may($t) && !$t['admin'] && ($k === 'hide' ? !$t['hid'] : m3d_mod_can('ban') && mod_can_ban($t['by']))) $dlg = [$k, $t];
}
$back = m3d_safe_return(m3d_get_text('back'), $here);

$waiting = m3d_mod_list('waiting');
$hidden = m3d_mod_list('hidden');
$msgs = $showHid ? array_merge($waiting, $hidden) : $waiting;   // the waiting ones first, then the hidden ones
$byKind = [];
foreach ($msgs as $t) $byKind[$t['kind']] = ($byKind[$t['kind']] ?? 0) + 1;
$list = $tab === 'mods' ? [] : ($tab === 'log' ? m3d_modlog() : array_values(array_filter($msgs, fn($t) => $what === '' || $t['kind'] === $what)));
$pages = max(1, (int) ceil(count($list) / MOD_PER_PAGE));
$page = min($page, $pages);
$shown = array_slice($list, ($page - 1) * MOD_PER_PAGE, MOD_PER_PAGE);

/** One post's card: who, what, where, the text, the buttons. */
function mod_card(array $t, bool $creator, string $csrf, string $here): string
{
    $hid = (bool) $t['hid'];
    $label = MOD_TYPES[$t['type']][0];
    $color = ['comment' => '#7cc4ff', 'bug' => '#ff8f6b', 'bugc' => '#ff8f6b', 'idea' => '#7ee0a1'][$t['type']];
    $keys = $csrf . '<input type="hidden" name="type" value="' . e($t['type']) . '" /><input type="hidden" name="ref" value="' . e($t['ref']) . '" /><input type="hidden" name="return" value="' . e($here) . '" />';
    $q = rawurlencode($t['type'] . ':' . $t['ref']);
    $sep = str_contains($here, '?') ? '&amp;' : '?';
    $title = $t['title'] !== '' ? '**' . str_replace(['*', "\n"], ['', ' '], ($t['itype'] ?? '') !== '' ? $t['itype'] . ' · ' . $t['title'] : $t['title']) . '**' . "\n\n" : '';
    $h = '<article class="post mq-card' . ($hid ? ' is-hid' : '') . '" id="mq-' . e($t['type'] . '-' . str_replace(':', '-', $t['ref'])) . '">'
        . '<div class="post-head">' . avatar_html($t['by'], $t['name'], false) . '<b>' . e($t['name'] !== '' ? $t['name'] : 'Someone') . '</b>'
        . '<span class="mq-k" style="--c:' . $color . '">' . e($label) . '</span>'
        . '<span class="mq-on">' . ($t['type'] === 'bug' ? 'about' : 'on') . ' <b>' . e($t['page']) . '</b> · ' . e(bug_ago($t['at'])) . '</span>'
        . ($hid ? '<span class="mod-hid">Hidden</span>' : '')
        . ($t['url'] !== '' ? '<a class="mq-open" href="' . e($t['url']) . '">Open in Page ↗</a>' : '') . '</div>'
        . '<div class="post-body md mq-text">' . md_html($title . $t['text'], ['picLinks' => true]) . '</div>';
    if ($hid) $h .= '<p class="mod-why">' . mod_hid_line($t['hid']) . '</p>';
    $h .= '<div class="mq-acts">';
    if (!$hid) {
        $h .= '<form method="post" action="/auth/moderate.php">' . $keys . '<button class="btn btn-primary btn-sm" name="do" value="approve">✓ Approve</button></form>'
            . '<a class="btn btn-ghost btn-sm" href="' . e($here) . $sep . 'hide=' . $q . '">Hide…</a>';
    } elseif ($creator || !m3d_mod_hid_by_creator($t['hid'])) {   // what the creator hid, only the creator shows again
        $h .= '<form method="post" action="/auth/moderate.php">' . $keys . '<button class="btn btn-ghost btn-sm" name="do" value="show">Show Again</button></form>';
    }
    if (m3d_mod_can('ban') && mod_can_ban($t['by'])) {
        $banned = array_intersect(m3d_bans($t['by']), [$t['kind'], 'login']);
        $h .= $banned ? '<span class="mq-banned">Banned from ' . e(BAN_KINDS[$t['kind']][0]) . '</span>'
            : '<a class="btn btn-ghost btn-sm mq-ban" href="' . e($here) . $sep . 'ban=' . $q . '">Ban ' . e($t['name'] !== '' ? $t['name'] : 'Them') . '…</a>';
    }
    if ($hid && $creator) {
        $h .= '<form method="post" action="/auth/moderate.php" class="mq-del">' . $keys . '<button class="btn btn-sm btn-red" name="do" value="delete" onclick="return confirm('
            . e(json_encode('Delete this ' . mb_strtolower($label) . ' for good? It can’t be brought back.', JSON_UNESCAPED_UNICODE)) . ')">Delete for Good</button></form>';
    }
    return $h . '</div></article>';
}

/** The tabs: Messages (the number waiting), and for the creator Log · Moderators. */
function mod_tabs(string $tab, int $waiting, bool $creator, callable $url): string
{
    $tabs = ['messages' => 'Messages'] + ($creator ? ['log' => 'Log', 'mods' => 'Moderators'] : []);
    $h = '<nav class="lib-tabs" aria-label="Moderation">';
    foreach ($tabs as $k => $label) {
        $n = $k === 'mods' ? count(m3d_mod_settings()['mods']) : ($k === 'messages' ? $waiting : null);
        $h .= '<a href="' . e($url(['tab' => $k === 'messages' ? '' : $k, 'page' => ''] + ($k !== 'messages' ? ['what' => '', 'hidden' => ''] : []))) . '"' . ($tab === $k ? ' aria-current="page"' : '') . '>'
            . $label . ($n !== null ? ' <span>' . $n . '</span>' : '') . '</a>';
    }
    return $h . '</nav>';
}

mod_result_flash();
admin_start('Moderation', 'moderation');
admin_result($done, $error);
?>
    <div class="admin-head"><h1>Moderation</h1></div>
    <?php if ($tab === 'mods'): ?>
    <?= mod_tabs($tab, count($waiting), $creator, $url) ?>
    <?php mods_section() ?>
    <?php elseif (!$kinds): ?>
      <div class="empty"><h3>Nothing to Check</h3><p class="muted"><?= $creator ? 'Comments, bug reports and ideas are all off. Turn them on in <a href="/admin/settings.php?tab=features">Settings → Features</a>.' : 'The creator hasn’t given you anything to check right now.' ?></p></div>
    <?php else: ?>
    <?= mod_tabs($tab, count($waiting), $creator, $url) ?>
    <?php if ($tab === 'messages' && ($waiting || $hidden)): // nothing at all = no bar, so the empty box sits where the Log tab's does ?>
      <div class="mq-bar">
        <?php if (count($kinds) > 1): ?>
          <div class="chips chips-gen" role="group" aria-label="What"><span class="chips-lbl">What</span>
            <a class="chip" href="<?= e($url(['what' => '', 'page' => ''])) ?>" aria-pressed="<?= $what === '' ? 'true' : 'false' ?>">All</a>
            <?php foreach ($kinds as $k): ?><a class="chip" href="<?= e($url(['what' => $k, 'page' => ''])) ?>" aria-pressed="<?= $what === $k ? 'true' : 'false' ?>"><?= e(MOD_KINDS[$k]) ?> <span><?= (int) ($byKind[$k] ?? 0) ?></span></a><?php endforeach ?>
          </div>
        <?php endif ?>
        <form method="get" class="mq-hid-sw"><?php if ($what !== ''): ?><input type="hidden" name="what" value="<?= e($what) ?>" /><?php endif ?>
          <label class="toggle"><input type="checkbox" class="switch" name="hidden" value="1" <?= $showHid ? 'checked' : '' ?> onchange="this.form.submit()" /><span>Show Hidden <span class="muted"><?= count($hidden) ?></span></span></label>
          <noscript><button class="btn btn-ghost btn-sm">Apply</button></noscript>
        </form>
      </div>
    <?php endif ?>

    <?php if (!$shown): ?>
      <div class="empty"><h3><?= $tab === 'log' ? 'Nothing Yet' : 'All Checked' ?></h3><p class="muted"><?= $tab === 'log' ? 'What moderators do shows here.' : 'Nothing is waiting for a check.' ?></p></div>
    <?php elseif ($tab === 'log'): $owners = m3d_owner_ids($M3D); ?>
      <div class="mlog">
        <?php foreach ($shown as $e2): $isOwner = in_array((string) ($e2['by'] ?? ''), $owners, true); $act = (string) ($e2['act'] ?? '');
          $t2 = isset($e2['type']) && $act !== 'delete' ? m3d_mod_target((string) $e2['type'], (string) ($e2['ref'] ?? '')) : null; ?>
          <div class="ml-row<?= !empty($e2['undone']) ? ' is-undone' : '' ?>" id="ml-<?= (int) ($e2['id'] ?? 0) ?>">
            <span class="ml-who"><?= avatar_html((string) ($e2['by'] ?? ''), (string) ($e2['name'] ?? ''), $isOwner) ?><b><?= e(($e2['name'] ?? '') ?: 'Someone') ?></b><?= $isOwner ? '<span class="creator-tag">Creator</span>' : '' ?></span>
            <span class="ml-main"><span class="ml-act act-<?= e($act) ?>"><?= e(mod_act_words($e2)) ?></span> · <?= $t2 && $t2['url'] !== '' ? '<a href="' . e($t2['url']) . '">' . e((string) ($e2['what'] ?? '')) . '</a>' : e((string) ($e2['what'] ?? '')) ?><?= ($e2['why'] ?? '') !== '' ? ' <span class="muted">· ' . e((string) $e2['why']) . '</span>' : '' ?></span>
            <span class="ml-when muted"><?= when_html((int) ($e2['at'] ?? 0), 'short') ?></span>
            <?php if (!empty($e2['undone'])): ?><span class="ml-undone muted small">Undone</span>
            <?php elseif ($act !== 'delete'): ?><form method="post" action="/auth/moderate.php"><?= $csrf ?><input type="hidden" name="log" value="<?= (int) ($e2['id'] ?? 0) ?>" /><input type="hidden" name="return" value="<?= e($here) ?>" /><button class="btn btn-ghost btn-sm" name="do" value="undo">Undo</button></form>
            <?php else: ?><span></span><?php endif ?>
          </div>
        <?php endforeach ?>
      </div>
    <?php else: ?>
      <div class="mq"><?php foreach ($shown as $t) echo mod_card($t, $creator, $csrf, $url(['page' => $page > 1 ? $page : ''])) ?></div>
    <?php endif ?>
    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pages"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="chip" href="<?= e($url(['page' => $i > 1 ? $i : ''])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a><?php endfor ?></nav>
    <?php endif ?>
    <?php if (!$creator): ?><p class="mod-note muted small">You can approve, hide<?= m3d_mod_can('ban') ? ' and ban' : '' ?>. You can’t delete or edit anything: the creator sees everything you do and can undo it.</p><?php endif ?>
    <?php endif ?>

<?php if ($dlg): [$k, $t] = $dlg; $who = $t['name'] !== '' ? $t['name'] : 'them'; ?>
  <div class="mod-dlg-back">
    <form class="mod-dlg" method="post" action="/auth/moderate.php" role="dialog" aria-modal="true" aria-labelledby="mod-dlg-t">
      <?= $csrf ?><input type="hidden" name="type" value="<?= e($t['type']) ?>" /><input type="hidden" name="ref" value="<?= e($t['ref']) ?>" /><input type="hidden" name="return" value="<?= e($back) ?>" />
      <?php if ($k === 'hide'): ?>
        <h2 id="mod-dlg-t">Hide This <?= e(MOD_TYPES[$t['type']][0]) ?>?</h2>
        <p class="muted small">Only moderators and the creator will see it. It isn’t deleted.</p>
        <blockquote class="mod-quote"><?= e(mb_strimwidth(trim(preg_replace('/\s+/u', ' ', ($t['title'] !== '' ? $t['title'] . ': ' : '') . $t['text']) ?? ''), 0, 220, '…')) ?></blockquote>
        <label class="field"><span class="lbl">Reason <span class="muted small">(optional)</span></span><input class="input" name="why" maxlength="<?= MOD_WHY_MAX ?>" list="mod-whys" autofocus /></label>
        <div class="mod-dlg-foot"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">Cancel</a><button class="btn btn-primary btn-sm" name="do" value="hide">Hide</button></div>
      <?php else: $theirs = count(array_filter($waiting, fn($x) => $x['by'] === $t['by'])); $has = m3d_bans($t['by']); ?>
        <h2 id="mod-dlg-t">Ban <?= e($who) ?>?</h2>
        <p class="muted small">They keep their downloads and everything else. They see the reason when they try.</p>
        <div class="mod-picks">
          <?php foreach ($kinds as $kk): $on = in_array($kk, $has, true); ?><label><input type="checkbox" name="kinds[]" value="<?= $kk ?>" <?= $kk === $t['kind'] || $on ? 'checked' : '' ?><?= $on ? ' disabled' : '' ?> /><?= e(MOD_KINDS[$kk]) ?><?= $on ? ' <span class="muted small">(already)</span>' : '' ?></label><?php endforeach ?>
        </div>
        <label class="field"><span class="lbl">Reason <span class="muted small">(shown to them)</span></span><input class="input" name="why" maxlength="<?= MOD_WHY_MAX ?>" list="mod-whys" /></label>
        <?php if ($theirs): ?><label class="toggle"><input type="checkbox" class="switch" name="also" value="1" checked /><span>Also Hide Their Waiting Posts (<?= $theirs ?>)</span></label><?php endif ?>
        <div class="mod-dlg-foot"><a class="btn btn-ghost btn-sm" href="<?= e($back) ?>">Cancel</a><button class="btn btn-sm btn-red" name="do" value="ban">Ban</button></div>
      <?php endif ?>
      <datalist id="mod-whys"><option value="Spam"></option><option value="Rude or hurtful"></option><option value="Off topic"></option><option value="Not about this model"></option><option value="Personal information"></option></datalist>
    </form>
  </div>
<?php endif ?>
<?php admin_end();
