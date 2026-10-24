<?php
// /bugs/ (list), /bugs/report/ (form), /bugs/<n>/ (one report): rewritten here by .htaccess (?report=1, ?n=N).
// /bugs/?index=1 = the JSON the report form uses for "Is it one of these?". POST (do = report | comment | metoo, CSRF)
// from logged-in visitors (m3d_bug_can_post()); do = follow (the Follow button) from anyone who can follow (m3d_bug_can_follow()). Off (Settings → Features → Bug reports): 404, except for admins.
// Data and rules: app/bugs.php.
require __DIR__ . '/app/_init.php';

$admin = m3d_is_admin($M3D);
if (!m3d_bugs_on() && !$admin) not_found();
$S = m3d_bug_settings();
$p = m3d_patron($M3D);
$creator = $admin && empty($p['preview']);   // the creator's tools (one report's page, the list's extra chips and tick boxes); not while previewing as a patron
$can = m3d_bug_can_post($p);
$tags = m3d_bug_tags();
$n = (int) ($_GET['n'] ?? 0);

// ── JSON for the report form: titles of the reports this visitor sees ──
if (!empty($_GET['index'])) {
    maintenance_gate();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-cache');
    $out = [];
    foreach (m3d_bugs() as $b) {
        if (!m3d_bug_visible($b, $p) || !empty($b['hidden']) || !empty($b['pending'])) continue;
        $a = m3d_bug_about($b);
        $out[] = ['n' => (int) $b['n'], 't' => (string) $b['title'], 's' => bug_status_label($b), 'k' => (string) ($b['status'] ?? 'new'),
            'a' => $a['name'] . (($b['ver'] ?? '') !== '' ? ' ' . $b['ver'] : ''), 'm' => count((array) ($b['metoo'] ?? []))];
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Sending: a report, a comment, Me too ──
$error = '';
$form = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    maintenance_gate();
    $do = bug_str($_POST['do'] ?? '');
    $readForm = fn() => [
        'about' => bug_str($_POST['about'] ?? ''), 'ver' => bug_line($_POST['ver'] ?? '', BUG_MAX['short']),
        'title' => bug_line($_POST['title'] ?? '', BUG_MAX['title']), 'body' => bug_text($_POST['body'] ?? '', BUG_MAX['body']),
        'steps' => bug_text($_POST['steps'] ?? '', BUG_MAX['steps']), 'blender' => bug_line($_POST['blender'] ?? '', BUG_MAX['short']),
        'mui' => bug_line($_POST['mui'] ?? '', BUG_MAX['short']), 'links' => bug_links($_POST['links'] ?? []),
    ];
    // pictures bigger than the server's form limit (post_max_size): PHP drops every field, so say that instead of "session expired"
    if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) $error = 'The pictures are too large together (the limit is ' . ini_get('post_max_size') . 'B): please write the report again with fewer or smaller pictures.';
    elseif (!m3d_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Your session expired: please send it again.';
        // keep what was typed, so pressing Send again is enough
        if ($do === 'report') { $form = $readForm(); $_GET['report'] = 1; }
        elseif ($do === 'comment') $form['text'] = bug_text($_POST['text'] ?? '', BUG_MAX['comment']);
    }
    elseif ($do === 'follow' && m3d_bug_can_follow($p)) {
        $b = m3d_bug((int) ($_POST['n'] ?? 0));
        if (!$b || !m3d_bug_visible($b, $p)) not_found();
        if (!m3d_bug_toggle_ok()) m3d_redirect('/bugs/' . (int) $b['n'] . '/?wait=1#metoo');
        $code = m3d_like_code((string) $p['id']);
        m3d_bug_change((int) $b['n'], function (array $b) use ($p, $code) {
            if (isset(m3d_bug_followers($b)[$code])) { $b['fol'] = m3d_bug_followers($b); unset($b['fol'][$code]); return $b; }
            return m3d_bug_follow($b, $p, time());
        });
        m3d_redirect('/bugs/' . (int) $b['n'] . '/#metoo');
    }
    elseif ($can['why'] === 'banned') m3d_redirect(m3d_ban_url('bugs'));   // banned in Admin → Access: the page with the reason
    elseif (!$can['ok']) $error = $can['why'] === 'login' ? 'Please log in with Patreon first.' : $can['why'];
    elseif ($do === 'report') {
        $abouts = m3d_bug_abouts();
        $form = $readForm();
        $item = $abouts[$form['about']][2] ?? null;
        $versions = m3d_bug_versions($item);
        $limitMsg = 'You sent ' . m3d_plural($S['per_day'], 'report') . ' in the last 24 hours: please wait a little before sending more.';
        if ($form['ver'] !== '' && $versions && !in_array($form['ver'], $versions, true)) $form['ver'] = '';
        if (!isset($abouts[$form['about']])) $error = 'Pick what the bug is about.';
        elseif (mb_strlen($form['title']) < 5) $error = 'Write a short title (5 characters at least).';
        elseif (mb_strlen($form['body']) < 10) $error = 'Tell us what happened (10 characters at least).';
        // model and accessory reports: the Blender version, and MustardUI's while its page is on
        elseif (m3d_bug_needs_versions($form['about']) && $form['blender'] === '') $error = 'Write the Blender version you use.';
        elseif (m3d_bug_needs_versions($form['about']) && m3d_mustardui_on() && $form['mui'] === '') $error = 'Write the MustardUI version you use.';
        // the daily limit is for visitors: admins enter reports for others ("+ New report")
        elseif (!$admin && m3d_bug_sent_today((string) $p['id']) >= $S['per_day']) $error = $limitMsg;   // checked again under the lock below
        else {
            $pics = [];
            if ($S['uploads'] && !empty($_FILES['pics'])) {
                [$pics, $picErr] = bug_save_pics($_FILES['pics'], BUG_PICS);
                if ($picErr !== '') { bug_delete_pics($pics); $error = $picErr; }
            }
            if ($error === '') {
                $now = time();
                $bug = m3d_bugs_update(function (array $bugs, int $next) use ($form, $pics, $p, $abouts, $S, $now, $admin) {
                    if (!$admin && m3d_bug_sent_today((string) $p['id'], $bugs) >= $S['per_day']) return [$bugs, $next, 'limit'];   // another session sent one meanwhile
                    $b = ['n' => $next, 'title' => $form['title'], 'about' => $form['about'], 'about_name' => bug_about_name($abouts[$form['about']][0]),
                        'ver' => $form['ver'], 'blender' => $form['blender'], 'mui' => $form['mui'], 'body' => $form['body'], 'steps' => $form['steps'],
                        'links' => $form['links'], 'pics' => $pics, 'by' => (string) $p['id'], 'name' => (string) ($p['name'] ?? ''),
                        'at' => $now, 'act' => $now, 'status' => 'new', 'tags' => [], 'metoo' => [], 'log' => []];
                    if ($S['moderate'] && !$admin) $b['pending'] = true;
                    if ($admin) $b['seen'] = true;
                    $bugs[] = m3d_bug_follow($b, $p, $now);
                    return [$bugs, $next + 1, $b];
                });
                if (!is_array($bug)) { bug_delete_pics($pics); $error = $bug === 'limit' ? $limitMsg : 'The report could not be saved. Please try again.'; }
                else {
                    if (!$admin) m3d_bug_notify($bug);
                    // the creator: on the new report, with "added"
                    if ($admin) m3d_redirect('/bugs/' . (int) $bug['n'] . '/?done=added-' . (int) $bug['n']);
                    m3d_redirect('/bugs/' . (int) $bug['n'] . '/?sent=1');
                }
            }
        }
        $_GET['report'] = 1;
    } elseif ($do === 'comment' || $do === 'metoo') {
        $b = m3d_bug((int) ($_POST['n'] ?? 0));
        if (!$b || !m3d_bug_visible($b, $p)) not_found();
        $n = (int) $b['n'];
        if ($do === 'metoo') {
            // Fixed / Closed: no more Me Too; never on one's own report or by an admin (the page shows no button then either)
            if (!in_array($b['status'] ?? 'new', BUG_OPEN, true) || $admin || (string) ($b['by'] ?? '') === (string) ($p['id'] ?? '')) m3d_redirect("/bugs/$n/#metoo");
            if (!m3d_bug_toggle_ok()) m3d_redirect("/bugs/$n/?wait=1#metoo");
            $code = m3d_like_code((string) $p['id']);
            m3d_bug_change($n, function (array $b) use ($code, $p) {
                $list = (array) ($b['metoo'] ?? []);
                if (in_array($code, $list, true)) { $b['metoo'] = array_values(array_diff($list, [$code])); return $b; }   // taken back: still followed
                $b['metoo'] = [...$list, $code];
                return m3d_bug_follow($b, $p, time());
            });
            m3d_redirect("/bugs/$n/#metoo");
        }
        $text = bug_text($_POST['text'] ?? '', BUG_MAX['comment']);
        $form['text'] = $text;
        if (!empty($b['locked']) && !$admin) $error = 'Comments are closed on this report.';
        elseif (mb_strlen($text) < 2) $error = 'Write a comment first.';
        else {
            $now = time();
            // the daily limit, checked under the same lock as the save (two sessions at once can't both pass); admins have none
            $saved = m3d_bugs_update(function (array $bugs, int $next) use ($n, $p, $text, $now, $admin) {
                if (!$admin && m3d_bug_comments_today((string) $p['id'], $bugs) >= BUG_COMMENTS_PER_DAY) return [$bugs, $next, 'limit'];
                $i = array_search($n, array_map(fn($b) => (int) ($b['n'] ?? 0), $bugs), true);
                if ($i === false) return [$bugs, $next, null];   // deleted meanwhile
                if (!empty($bugs[$i]['locked']) && !$admin) return [$bugs, $next, 'locked'];   // locked meanwhile
                $bugs[$i] = m3d_bug_add_comment($bugs[$i], $p, $text, $now, $admin);
                return [$bugs, $next, true];
            });
            if ($saved === true) {
                if (!$admin) m3d_bug_comment_notify($b, (string) ($p['name'] ?? ''), $text);
                m3d_redirect("/bugs/$n/#comments");
            }
            $error = match ($saved) {
                'limit'  => 'You wrote ' . m3d_plural(BUG_COMMENTS_PER_DAY, 'comment') . ' on bug reports in the last 24 hours: please wait a little before writing more.',
                'locked' => 'Comments are closed on this report.',
                default  => 'Your comment could not be saved. Please try again.',
            };
        }
    }
}

/** The address of the list with these filters ($q merged over the current ones; '' drops one). */
function bugs_url(array $q, array $cur): string
{
    $q = array_filter(array_merge($cur, $q), fn($v) => $v !== '' && $v !== null && $v !== 0);
    unset($q['page']);
    if (isset($q['_page'])) { $q['page'] = $q['_page']; unset($q['_page']); }
    return '/bugs/' . ($q ? '?' . http_build_query($q) : '');
}

$loginUrl = '/auth/login.php?return=' . rawurlencode($_SERVER['REQUEST_URI'] ?? '/bugs/');
$offNote = !m3d_bugs_on() ? '<p class="bugs-off">Bug reports are off: only admins see this page. <a href="/admin/settings.php?tab=features#bugs">Turn them on</a></p>' : '';

// ════ One report ════
if ($n > 0) {
    $b = m3d_bug($n);
    if (!$b || !m3d_bug_visible($b, $p)) not_found();
    if (!empty($b['hidden']) || !empty($b['pending'])) header('X-Robots-Tag: noindex');
    $a = m3d_bug_about($b);
    $metoo = (array) ($b['metoo'] ?? []);
    $mine = $p ? in_array(m3d_like_code((string) $p['id']), $metoo, true) : false;
    $own = $p && (string) ($b['by'] ?? '') === (string) ($p['id'] ?? '');
    $log = (array) ($b['log'] ?? []);
    $comments = count(array_filter($log, fn($x) => ($x['t'] ?? '') === 'c'));
    $more = $a['kindKey'] !== 'other' ? count(array_filter(m3d_bugs(), fn($x) => (int) $x['n'] !== $n && ($x['about'] ?? '') === ($b['about'] ?? '') && in_array($x['status'] ?? 'new', BUG_OPEN, true) && empty($x['hidden']) && empty($x['pending']) && m3d_bug_visible($x, $p))) : 0;
    $status = (string) ($b['status'] ?? 'new');
    // following: what is new since this visitor last opened it (the line "New since your last visit"), then mark it seen
    $canFollow = m3d_bug_can_follow($p);
    $seenAt = $canFollow ? (m3d_bug_followers($b)[m3d_like_code((string) $p['id'])] ?? null) : null;
    $newFrom = null;   // the first new log entry
    if ($seenAt !== null) {
        $fresh = m3d_bug_new_entries($b, $seenAt, (string) $p['id']);
        if ($fresh) $newFrom = array_key_first($fresh);
        // seen up to the newest entry shown here (not "now": one saved meanwhile stays new); only once the page really shows
        // (not behind the 18+ check or the maintenance page)
        $last = max([0, ...array_map(fn($x) => (int) ($x['at'] ?? 0), $log)]);
        maintenance_gate();
        if ($last > $seenAt && age_confirmed()) {
            $code = m3d_like_code((string) $p['id']);
            m3d_bug_change($n, function (array $x) use ($code, $last) {
                $x['fol'] = m3d_bug_followers($x);
                if (isset($x['fol'][$code])) $x['fol'][$code] = max($x['fol'][$code], $last);   // unless unfollowed meanwhile (another tab)
                return $x;
            });
        }
    }
    // the creator manages the report here (Creator Tools, Reported By, the ⋯ menus; the changes go to admin/bug.php and come back
    // with ?done= / ?err=); ?edit=report | c<i> = that post as a form. Opening it = seen (off the creator's Not Seen list).
    if ($creator && empty($b['seen'])) m3d_bug_change($n, function (array $x) { $x['seen'] = true; return $x; });
    $edit = $creator ? bug_str($_GET['edit'] ?? '') : '';
    $act = '/admin/bug.php?n=' . $n;
    $keys = '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" />';
    $byId = (string) ($b['by'] ?? '');
    $owners = m3d_owner_ids($M3D);
    $blocked = $S['blocked'];
    // Block / Unblock someone from bug reports (the reporter, a commenter); never the site's admins
    $blockBtn = function (string $id, string $name, string $class) use ($act, $keys, $owners, $blocked): string {
        if ($id === '' || in_array($id, $owners, true)) return '';
        $on = in_array($id, $blocked, true);
        $ask = 'Block ' . ($name ?: 'this person') . ' from bug reports? They can still read them, but not report, comment or press Me Too.';
        return '<form method="post" action="' . e($act) . '">' . $keys . '<input type="hidden" name="who" value="' . e($id) . '" />'
            . '<button class="' . $class . '" name="do" value="' . ($on ? 'unblock' : 'block') . '"' . ($on ? '' : ' onclick="return confirm(' . e(json_encode($ask)) . ')"') . '>'
            . ($on ? 'Unblock' : 'Block') . ($class === 'pm-item' ? ' ' . e($name ?: 'Them') : '') . '</button></form>';
    };
    layout_start('#' . $n . ' ' . $b['title'], mb_substr('Bug report about ' . $a['name'] . ': ' . preg_replace('/\s+/', ' ', (string) $b['body']), 0, 160), 'page-bugs');
    ?>
  <div class="wrap bug-page">
    <?= $offNote ?>
    <a class="back" href="/bugs/" data-back>← All Bug Reports</a>
    <?php if (!empty($_GET['sent'])) site_flash('✓ <b>Thanks, your report was sent.</b> ' . (!empty($b['pending']) ? 'The creator checks new reports before they show on the site: until then only you can see it.' : 'You’ll find the answers here.')) ?>
    <?php if (!empty($b['pending'])): ?>
      <div class="bug-notice"><span>Waiting for the creator to check it: only you<?= $admin ? ' (and admins)' : '' ?> can see this report.</span><?php if ($creator): ?><form method="post" action="<?= e($act) ?>"><?= $keys ?><button class="btn btn-primary btn-sm" name="do" value="publish">Publish</button></form><?php endif ?></div>
    <?php endif ?>
    <?php bug_result_flash($creator) // ?done= / ?err= codes of the creator's changes, ?wait=1 ?>
    <?php if (!empty($b['hidden'])): ?><p class="bug-notice"><span>Hidden from the site: only admins see this report.</span></p><?php endif ?>
    <header class="bug-top">
      <div class="bug-top-row"><?= bug_status_badge($b) ?><?php if (!empty($b['pin']) && in_array($status, BUG_OPEN, true)): ?><span class="pin-tag"><?= bug_icon('pin') ?>Known Issue</span><?php endif ?></div>
      <?php if ($creator): $editTitle = $edit === 'title'; ?>
        <h1 data-title-show<?= $editTitle ? ' hidden' : '' ?>><?= e($b['title']) ?> <span class="bug-num">#<?= $n ?></span><a class="title-pen" href="/bugs/<?= $n ?>/?edit=title" data-title-edit aria-label="Edit Title" title="Edit title"><?= bug_icon('pen') ?></a></h1>
        <form class="title-form" method="post" action="<?= e($act) ?>" data-title-form data-dirty<?= $editTitle ? '' : ' hidden' ?>><?= $keys ?><input type="hidden" name="do" value="title" />
          <input class="input" name="title" value="<?= e((string) $b['title']) ?>" maxlength="<?= BUG_MAX['title'] ?>" required aria-label="Title" />
          <button class="btn btn-primary btn-sm">Save</button><a class="btn btn-ghost btn-sm" href="/bugs/<?= $n ?>/" data-title-cancel>Cancel</a></form>
      <?php else: ?><h1><?= e($b['title']) ?> <span class="bug-num">#<?= $n ?></span></h1><?php endif ?>
      <span class="bug-sub">Reported <?= e(bug_ago((int) $b['at'])) ?> by <?= e($b['name'] ?: 'someone') ?><?= (int) ($b['act'] ?? 0) > (int) $b['at'] + 60 ? ' · last activity ' . e(bug_ago((int) $b['act'])) : '' ?></span>
    </header>

    <div class="bug-grid">
      <div class="bug-body">
        <?php if ($status === 'fixed'): ?>
          <?php // the version it was fixed in, from the item's changelog: out already, or dated later ("coming 18 Oct", no button yet)
            $fixLog = $a['item'] ? (array) ($a['item']['changelog'] ?? []) : [];
            $fixIn = m3d_bug_fixed_in($b, $a['item']);
            $fixAt = $fixIn !== '' ? m3d_changelog_find($fixLog, $fixIn) : null;
            $fixTs = $fixAt !== null ? m3d_changelog_ts((string) (array_values($fixLog)[$fixAt]['date'] ?? '')) : null;
            $fixSoon = $fixTs !== null && $fixTs > time(); ?>
          <div class="fixed-bar"><?= bug_icon('check') ?><span>Fixed<?= $fixIn !== '' ? ' in <b>' . e($a['name'] . ' ' . bug_version_words($fixIn)) . '</b>' : '' ?><?= $fixSoon ? ', coming ' . e(m3d_release_day($fixTs)) : ($fixAt !== null ? ', out now' : '') ?>.</span><?php if ($a['url'] !== '' && $a['item'] && !$fixSoon && ($fixIn === '' || $fixAt !== null)): ?><a class="btn btn-sm btn-ghost" href="<?= e($fixIn !== '' ? m3d_version_url($a['kindKey'], $a['item'], $fixIn) : $a['url'] . '#changelog') ?>">Get the Update →</a><?php endif ?></div>
        <?php elseif ($status === 'closed' && ($b['reason'] ?? '') === 'duplicate' && (int) ($b['dup'] ?? 0) > 0): ?>
          <?php $dupB = m3d_bug((int) $b['dup']); /* a hidden, waiting or deleted report would be a dead link */ ?>
          <?php if ($dupB && m3d_bug_visible($dupB, $p)): ?><div class="fixed-bar is-dup"><span>Same bug as <a href="/bugs/<?= (int) $b['dup'] ?>/"><b>#<?= (int) $b['dup'] ?></b></a>: follow that report instead.</span></div><?php else: ?><div class="fixed-bar is-closed"><?= bug_icon('closed') ?><span>Closed: <b>duplicate</b>.</span></div><?php endif ?>
        <?php elseif ($status === 'closed'): ?>
          <div class="fixed-bar is-closed"><?= bug_icon('closed') ?><span>Closed<?= isset(BUG_REASONS[$b['reason'] ?? '']) ? ': <b>' . e(mb_strtolower(BUG_REASONS[$b['reason']])) . '</b>' : '' ?>.</span></div>
        <?php endif ?>
        <article class="post" id="report">
          <div class="post-head"><?= bug_avatar((string) $b['name']) ?><b><?= e($b['name'] ?: 'Someone') ?></b> reported this · <?= e(bug_ago((int) $b['at'])) ?>
            <?php if ($creator && $edit !== 'report'): ?><?= bug_post_menu(['<a class="pm-item" href="/bugs/' . $n . '/?edit=report#report">Edit Report</a>', $blockBtn($byId, (string) ($b['name'] ?? ''), 'pm-item')]) ?><?php endif ?></div>
          <?php if ($edit === 'report'): $abAll = m3d_bug_abouts_all(); $vAll = $a['item'] ? array_values(array_unique(array_map('m3d_version_label', m3d_versions($a['item'])))) : []; ?>
          <form class="post-body bug-edit-form" method="post" action="<?= e($act) ?>" data-dirty><?= $keys ?><input type="hidden" name="do" value="edit" />
            <label class="rfield"><span class="rlabel">What Is It About?</span>
              <select class="input" name="about">
                <?php if (!isset($abAll[$b['about'] ?? ''])): ?><option value="<?= e((string) ($b['about'] ?? '')) ?>" selected><?= e($a['name']) ?></option><?php endif ?>
                <?php $grp = ''; foreach ($abAll as $k => [$name, $g]): if ($g !== $grp): ?><?= $grp !== '' ? '</optgroup>' : '' ?><optgroup label="<?= e($g) ?>"><?php $grp = $g; endif ?><option value="<?= e((string) $k) ?>"<?= (string) $k === ($b['about'] ?? '') ? ' selected' : '' ?>><?= e($name) ?></option><?php endforeach ?></optgroup>
              </select></label>
            <div class="r2">
              <label class="rfield"><span class="rlabel">Version</span><input class="input" name="ver" value="<?= e((string) ($b['ver'] ?? '')) ?>" list="bug-ver-all" maxlength="<?= BUG_MAX['short'] ?>" /></label>
              <label class="rfield"><span class="rlabel">Blender</span><input class="input" name="blender" value="<?= e((string) ($b['blender'] ?? '')) ?>" maxlength="<?= BUG_MAX['short'] ?>" /></label>
              <?php if (m3d_mustardui_on()): ?><label class="rfield"><span class="rlabel">MustardUI</span><input class="input" name="mui" value="<?= e((string) ($b['mui'] ?? '')) ?>" maxlength="<?= BUG_MAX['short'] ?>" /></label><?php endif ?>
            </div>
            <datalist id="bug-ver-all"><?php foreach ($vAll as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach ?></datalist>
            <label class="rfield"><span class="rlabel">What Happened</span><textarea class="input" name="body" rows="6" maxlength="<?= BUG_MAX['body'] ?>"><?= e((string) ($b['body'] ?? '')) ?></textarea></label>
            <label class="rfield"><span class="rlabel">How to See It</span><textarea class="input" name="steps" rows="4" maxlength="<?= BUG_MAX['steps'] ?>"><?= e((string) ($b['steps'] ?? '')) ?></textarea></label>
            <div class="rfield"><span class="rlabel">Screenshot and Video Links</span>
              <?php $links = (array) ($b['links'] ?? []); for ($li = 0; $li < BUG_LINKS; $li++): ?><input class="input" type="url" name="links[]" value="<?= e((string) ($links[$li] ?? '')) ?>" placeholder="https://…" spellcheck="false" aria-label="Link <?= $li + 1 ?>" /><?php endfor ?></div>
            <?php if (!empty($b['pics'])): ?>
              <div class="rfield"><span class="rlabel">Uploaded Pictures</span>
                <div class="bug-shots"><?php foreach ((array) $b['pics'] as $u): ?><label class="bug-shot pic-del"><img src="<?= e(bug_pic_small((string) $u)) ?>" alt="" loading="lazy" /><span><input type="checkbox" name="delpic[]" value="<?= e((string) $u) ?>" /> Delete</span></label><?php endforeach ?></div></div>
            <?php endif ?>
            <div class="edit-acts"><a class="btn btn-ghost btn-sm" href="/bugs/<?= $n ?>/#report">Cancel</a><button class="btn btn-primary btn-sm">Save</button></div>
          </form>
          <?php else: ?>
          <div class="post-body">
            <?= bug_env_html($b, $a) ?>
            <h4>What Happened</h4>
            <div class="md"><?= md_html((string) $b['body'], ['picLinks' => true]) ?></div>
            <?php if (trim((string) ($b['steps'] ?? '')) !== ''): ?><h4>How to See It</h4><div class="md"><?= md_html((string) $b['steps'], ['picLinks' => true]) ?></div><?php endif ?>
            <?php if (!empty($b['pics'])): ?>
              <h4>Pictures</h4>
              <div class="bug-shots"><?php foreach ((array) $b['pics'] as $i => $u): ?><a class="bug-shot" href="<?= e($u) ?>" target="_blank" rel="noopener"><img src="<?= e(bug_pic_small((string) $u)) ?>" alt="Picture <?= $i + 1 ?>" loading="lazy" /></a><?php endforeach ?></div>
            <?php endif ?>
            <?php if (!empty($b['links'])): ?>
              <h4>Screenshots and Videos</h4>
              <div class="ext-links"><?php foreach ((array) $b['links'] as $u): $host = (string) parse_url((string) $u, PHP_URL_HOST); ?><a href="<?= e($u) ?>" target="_blank" rel="noopener nofollow ugc"><?= bug_icon('link') ?><span><?= e(preg_replace('#^https?://(www\.)?#', '', (string) $u)) ?></span><i>↗</i></a><?php endforeach ?></div>
              <p class="muted small ext-note">These links open on another website.</p>
            <?php endif ?>
          </div>
          <?php endif ?>
        </article>

        <div class="timeline" id="comments">
          <?php $itemRep = m3d_bug_about_parts((string) ($b['about'] ?? ''))[1] !== '';   // its version comes from the changelog: its "Fixed in" line says it
          foreach ($log as $i => $x): $t = (string) ($x['t'] ?? ''); $when = bug_ago((int) ($x['at'] ?? 0)); ?>
            <?php if ($i === $newFrom): ?><p class="new-line" id="new">New since your last visit</p><?php endif ?>
            <?php if ($t === 'c'): $cr = !empty($x['admin']); ?>
              <article class="post<?= $cr ? ' by-creator' : '' ?>" id="c<?= (int) $i ?>">
                <div class="post-head"><?= bug_avatar((string) ($x['name'] ?? ''), $cr) ?><b><?= e(($x['name'] ?? '') ?: 'Someone') ?></b><?= $cr ? '<span class="creator-tag">Creator</span>' : '' ?><?= !$cr && (string) ($x['by'] ?? '') === (string) ($b['by'] ?? '') ? '<span class="op-tag">Reporter</span>' : '' ?> · <?= e($when) ?>
                  <?php if ($creator && $edit !== "c$i"): ?><?= bug_post_menu([
                      '<a class="pm-item" href="/bugs/' . $n . '/?edit=c' . (int) $i . '#c' . (int) $i . '">Edit</a>',
                      $cr ? '' : $blockBtn((string) ($x['by'] ?? ''), (string) ($x['name'] ?? ''), 'pm-item'),
                      '<form method="post" action="' . e($act) . '">' . $keys . '<input type="hidden" name="i" value="' . (int) $i . '" /><button class="pm-item is-red" name="do" value="delcomment" onclick="return confirm(\'Delete this comment?\')">Delete</button></form>',
                  ]) ?><?php endif ?></div>
                <?php if ($edit === "c$i"): ?>
                  <form class="post-body bug-edit-form" method="post" action="<?= e($act) ?>" data-dirty><?= $keys ?><input type="hidden" name="do" value="editcomment" /><input type="hidden" name="i" value="<?= (int) $i ?>" />
                    <label class="sr-only" for="edit-c<?= (int) $i ?>">Comment</label>
                    <textarea class="input" id="edit-c<?= (int) $i ?>" name="text" rows="4" maxlength="<?= BUG_MAX['comment'] ?>" required><?= e((string) ($x['text'] ?? '')) ?></textarea>
                    <div class="edit-acts"><a class="btn btn-ghost btn-sm" href="/bugs/<?= $n ?>/#c<?= (int) $i ?>">Cancel</a><button class="btn btn-primary btn-sm">Save</button></div>
                  </form>
                <?php else: ?>
                  <div class="post-body md"><?= md_html((string) ($x['text'] ?? ''), ['picLinks' => true]) ?></div>
                <?php endif ?>
              </article>
            <?php elseif ($t === 's'): $st = (string) ($x['status'] ?? 'new'); ?>
              <p class="ev" style="--c:<?= e((BUG_STATUSES[$st] ?? BUG_STATUSES['new'])[2]) ?>"><span>Status set to <?= bug_status_badge($itemRep ? array_diff_key($x, ['fixed' => 1]) : $x) ?> · <?= e($when) ?></span></p>
            <?php elseif ($t === 'ref' && ($ref = bug_ref_event($b, $x)) !== ''): ?>
              <p class="ev" style="--c:<?= e(BUG_STATUSES['fixed'][2]) ?>"><span><?= $ref ?> · <?= e($when) ?></span></p>
            <?php elseif ($t === 'tag' && ($pills = implode(' ', array_filter(array_map(fn($id) => bug_tag_html((string) $id, $tags), (array) ($x['add'] ?? []))))) !== ''): ?>
              <p class="ev" style="--c:var(--muted)"><span>Tagged <?= $pills ?> · <?= e($when) ?></span></p>
            <?php endif ?>
          <?php endforeach ?>
        </div>

        <?php if ($error !== ''): ?><p class="bug-notice err" id="comment-error"><span><?= e($error) ?></span></p><?php endif ?>
        <?php if (!empty($b['locked']) && !$admin): ?>
          <p class="reply-off muted small"><?= bug_icon('lock') ?>Comments are closed on this report.</p>
        <?php elseif ($can['ok']): ?>
          <form class="reply" method="post" action="/bugs/<?= $n ?>/#comment-error">
            <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="comment" /><input type="hidden" name="n" value="<?= $n ?>" />
            <label class="sr-only" for="bug-comment">Comment</label>
            <textarea class="input" id="bug-comment" name="text" maxlength="<?= BUG_MAX['comment'] ?>" placeholder="Add a comment: more details, a workaround…" required><?= e($form['text'] ?? '') ?></textarea>
            <div class="reply-foot"><span class="muted">Commenting as <b><?= e($p['name'] ?? '') ?></b><?= $admin ? ' <span class="creator-tag">Creator</span>' : '' ?></span><button class="btn btn-primary btn-sm">Comment</button></div>
          </form>
        <?php elseif ($can['why'] === 'login'): ?>
          <div class="reply reply-login"><span class="muted">Log in to comment or press Me Too.</span><a class="btn btn-patreon btn-sm" rel="nofollow" href="<?= e($loginUrl) ?>">Log In with Patreon</a></div>
        <?php else: ?>
          <?= $can['why'] === 'banned' ? ban_note('bugs') : '<p class="reply-off muted small">' . e($can['why']) . '</p>' ?>
        <?php endif ?>
      </div>

      <aside class="bside">
        <?php if ($creator): ?>
          <form class="ctools" id="tools" method="post" action="<?= e($act) ?>" data-dirty><?= $keys ?><input type="hidden" name="do" value="state" /><input type="hidden" name="lock_set" value="" />
            <h3>Creator Tools</h3>
            <div class="ct-sec"><span class="ct-lbl">Status</span>
              <div class="ct-chips">
                <?php foreach (BUG_STATUSES as $k => [$l, , $c]): ?><label class="ct-chip"><input type="radio" name="status" value="<?= e($k) ?>" <?= $k === $status ? 'checked' : '' ?> /><i class="sdot" style="--c:<?= e($c) ?>"></i><?= e($l) ?></label><?php endforeach ?>
              </div></div>
            <?php if ($a['item']): /* an item's report: the version comes from its changelog ("Fix #6: …" in a version's changes) */
              $fixIn = m3d_bug_fixed_in($b, $a['item']); ?>
              <div class="ct-sec st-fixed"><span class="ct-lbl">Fixed In<?= help_tip('Write #' . $n . ' in a version’s changes, in the ' . ($a['kindKey'] === 'outfits' ? 'accessory' : 'model') . '’s changelog: saving it marks this report fixed in that version.') ?></span>
                <p class="ct-fixin"><?= $fixIn !== '' ? '<b>' . e(bug_version_words($fixIn)) . '</b>' : '<span class="muted">Not in the changelog</span>' ?></p></div>
            <?php else: ?>
              <label class="ct-sec st-fixed"><span class="ct-lbl">Fixed In</span>
                <input class="input" name="fixed" value="<?= e(m3d_bug_fixed_in($b)) ?>" maxlength="<?= BUG_MAX['short'] ?>" placeholder="V2" /></label>
            <?php endif ?>
            <div class="ct-sec st-closed">
              <label class="ct-sec"><span class="ct-lbl">Why It’s Closed</span>
                <select class="input" name="reason"><option value="">No Reason</option><?php foreach (BUG_REASONS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($b['reason'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
              <label class="ct-sec ct-dup"><span class="ct-lbl">Duplicate of #</span>
                <input class="input" type="number" name="dup" min="1" value="<?= (int) ($b['dup'] ?? 0) ?: '' ?>" placeholder="12" /></label>
            </div>
            <div class="ct-sec"><span class="ct-lbl">Tags</span>
              <div class="tag-pick">
                <?php foreach ($tags as $id => [$l, $c]): ?><label style="--c:<?= e($c) ?>"><input type="checkbox" name="tags[]" value="<?= e((string) $id) ?>" <?= in_array((string) $id, (array) ($b['tags'] ?? []), true) ? 'checked' : '' ?> /><i class="sdot" style="--c:<?= e($c) ?>"></i><?= e($l) ?></label><?php endforeach ?>
                <button type="button" class="add-tag" data-tags-open><?= $tags ? 'Edit Tags' : '+ Make Tags' ?></button>
              </div></div>
            <div class="ct-sws">
              <label class="toggle"><input type="checkbox" class="switch" name="pin" value="1" <?= !empty($b['pin']) ? 'checked' : '' ?> /><span>Pin as Known Issue</span></label>
              <label class="toggle"><input type="checkbox" class="switch" name="hidden" value="1" <?= !empty($b['hidden']) ? 'checked' : '' ?> /><span>Hide from Site</span></label>
              <label class="toggle"><input type="checkbox" class="switch" name="locked" value="1" <?= !empty($b['locked']) ? 'checked' : '' ?> /><span>Lock Comments</span></label>
            </div>
            <div class="ct-foot"><button class="btn btn-primary btn-sm">Save</button></div>
          </form>
        <?php endif ?>
        <div class="metoo" id="metoo">
          <?php $metooOff = !in_array($status, BUG_OPEN, true); /* Fixed / Closed: the count stays, the button goes */ ?>
          <?php if ($can['ok'] && !$own && !$admin && !$metooOff): ?>
            <form method="post" action="/bugs/<?= $n ?>/"><input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="metoo" /><input type="hidden" name="n" value="<?= $n ?>" />
              <button class="metoo-btn<?= $mine ? ' on' : '' ?>" aria-pressed="<?= $mine ? 'true' : 'false' ?>"><?= bug_icon('metoo') ?><?= $mine ? 'You Have It Too' : 'Me Too' ?> <span class="n"><?= count($metoo) ?></span></button></form>
            <p><?= $mine ? 'Press again to take it back.' : 'Has it happened to you too? It helps decide what gets fixed first.' ?></p>
          <?php else: ?>
            <span class="metoo-btn is-static<?= $metooOff ? ' is-off' : '' ?>"<?= $metooOff ? ' title="Fixed and closed reports take no more Me Too"' : '' ?>><?= bug_icon('metoo') ?><?= count($metoo) === 1 ? '1 person has it too' : count($metoo) . ' people have it too' ?></span>
            <p><?= $metooOff ? '' : ($own ? 'You reported this one.' : ($can['why'] === 'login' ? '<a rel="nofollow" href="' . e($loginUrl) . '">Log in</a> to press Me Too.' : '')) ?></p>
          <?php endif ?>
          <?php if ($canFollow): ?>
            <form method="post" action="/bugs/<?= $n ?>/"><input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="follow" /><input type="hidden" name="n" value="<?= $n ?>" />
              <button class="follow-btn<?= $seenAt !== null ? ' on' : '' ?>" aria-pressed="<?= $seenAt !== null ? 'true' : 'false' ?>"><?= bug_icon('bell') ?><?= $seenAt !== null ? 'Following' : 'Follow' ?></button></form>
            <p><?= $seenAt !== null ? 'You follow reports you write, comment on or press Me Too on.' : 'See a number next to Bugs in the menu when this report changes.' ?></p>
          <?php endif ?>
        </div>
        <?php if ($creator): $others = $byId !== '' ? count(array_filter(m3d_bugs(), fn($x) => (string) ($x['by'] ?? '') === $byId && (int) $x['n'] !== $n)) : 0; ?>
          <div class="rep-box">
            <h3>Reported by</h3>
            <div class="rep-who"><?= bug_avatar((string) ($b['name'] ?? '')) ?><span><b><?= e(($b['name'] ?? '') ?: 'Someone') ?></b><small><?= $byId !== '' ? 'Patreon ID ' . e($byId) : '' ?><?= $others ? ' · ' . m3d_plural($others, 'other report') : '' ?></small></span></div>
            <?php if ($byId !== '' && in_array($byId, $blocked, true)): ?><p class="rep-blocked">Blocked from posting bug reports.</p><?php endif ?>
            <?php if ($byId !== ''): ?>
              <div class="rep-acts">
                <a class="btn btn-ghost btn-sm" href="/admin/log.php?patron=<?= e(rawurlencode($byId)) ?>">Their Downloads</a>
                <?php if ($others): ?><a class="btn btn-ghost btn-sm" href="/bugs/?status=all&amp;by=<?= e(rawurlencode($byId)) ?>">Their Reports</a><?php endif ?>
                <?= $blockBtn($byId, (string) ($b['name'] ?? ''), 'btn btn-ghost btn-sm') ?>
              </div>
            <?php endif ?>
          </div>
          <form method="post" action="<?= e($act) ?>" class="del-report"><?= $keys ?><button class="btn btn-sm btn-del" name="do" value="delete" onclick="return confirm(<?= e(json_encode('Delete report #' . $n . ' with its comments' . (!empty($b['pics']) ? ' and pictures' : '') . '? This can’t be undone.')) ?>)">Delete This Report</button></form>
        <?php endif ?>
        <div class="facts-box">
          <div class="fact"><span>About</span><?= $a['url'] !== '' ? '<a class="fact-item" href="' . e($a['url']) . '">' : '<span class="fact-item">' ?><?= bug_about_pic($a) ?><span><b><?= e($a['name']) ?></b><small><?= e($a['kind']) ?><?= ($b['ver'] ?? '') !== '' ? ' · ' . e($b['ver']) : '' ?></small></span><?= $a['url'] !== '' ? '</a>' : '</span>' ?></div>
          <div class="fact"><span>Status</span><span><?= bug_status_badge($b) ?></span></div>
          <?php $tg = array_filter(array_map(fn($id) => bug_tag_html((string) $id, $tags), (array) ($b['tags'] ?? []))); if ($tg): ?><div class="fact"><span>Tags</span><span class="tags"><?= implode('', $tg) ?></span></div><?php endif ?>
          <?php if ($more): ?><div class="fact"><span>Same <?= $a['item'] ? ($a['kindKey'] === 'models' ? 'Model' : 'Accessory') : 'Topic' ?></span><a class="lnk" href="/bugs/?about=<?= e(rawurlencode((string) $b['about'])) ?>"><?= m3d_plural($more, 'more open report') ?> →</a></div><?php endif ?>
        </div>
      </aside>
    </div>
  </div>
<?php
    if ($creator) echo bug_tags_dialog("/bugs/$n/");
    layout_end();
    exit;
}

// ════ Report form ════
if (!empty($_GET['report'])) {
    $abouts = m3d_bug_abouts();
    $sel = (string) ($form['about'] ?? bug_str($_GET['about'] ?? ''));
    if (!isset($abouts[$sel])) $sel = '';
    $vers = [];
    foreach ($abouts as $k => [, , $it]) if ($it) $vers[$k] = m3d_bug_versions($it);
    $selVers = $vers[$sel] ?? [];
    layout_start('Report a Bug', 'Report a bug in a model, an accessory or the website.', 'page-bugs');
    ?>
  <div class="wrap report">
    <?= $offNote ?>
    <a class="back" href="/bugs/" data-back>← All Bug Reports</a>
    <header class="report-head">
      <h1>Report a Bug</h1>
      <p class="lead lead-sm">Tell us what went wrong. The more precise, the faster it gets fixed.</p>
    </header>

    <div class="report-grid">
      <?php if (!$can['ok']): ?>
        <div class="rform r-login">
          <?= bug_icon('bug') ?>
          <?php if ($can['why'] === 'login'): ?>
            <h2>Log In to Report a Bug</h2>
            <p><?= $S['who'] === 'paying' ? 'Patrons on a paid tier can report bugs.' : 'Anyone with a Patreon account can report bugs, free members too.' ?> Reading the reports needs no login.</p>
            <a class="btn btn-patreon" rel="nofollow" href="<?= e($loginUrl) ?>">Log In with Patreon</a>
          <?php else: ?>
            <h2>You Can’t Report Bugs</h2>
            <?= $can['why'] === 'banned' ? ban_note('bugs', '') : '<p>' . e($can['why']) . '</p>' ?>
          <?php endif ?>
        </div>
      <?php else: ?>
      <form class="rform" method="post" action="/bugs/report/"<?= $S['uploads'] ? ' enctype="multipart/form-data"' : '' ?> data-bug-form data-versions="<?= e(json_encode($vers, JSON_UNESCAPED_UNICODE)) ?>">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="report" />
        <?php if ($error !== ''): ?><p class="bug-notice err"><span><?= e($error) ?></span></p><?php endif ?>
        <label class="rfield"><span class="rlabel">What Is It About?</span>
          <select class="input" name="about" required data-bug-about data-about-picker aria-label="What is it about?">
            <option value="">Pick a model, an accessory…</option>
            <?php $group = ''; foreach ($abouts as $k => [$label, $g]): if ($g !== $group): ?><?= $group !== '' ? '</optgroup>' : '' ?><optgroup label="<?= e($g) ?>"><?php $group = $g; endif ?>
              <?= bug_about_option((string) $k, $label, $k === $sel) ?>
            <?php endforeach; echo $group !== '' ? '</optgroup>' : '' ?>
          </select>
          <?php if (m3d_mustardui_on()): ?><span class="rhelp">A bug in MustardUI itself? Please report it on <a href="<?= e(SITE['mustarduiIssues']) ?>" target="_blank" rel="noopener">MustardUI’s GitHub issues ↗</a></span><?php endif ?></label>
        <div class="r2">
          <label class="rfield" data-bug-ver<?= $selVers ? '' : ' hidden' ?>><span class="rlabel">Version</span>
            <select class="input" name="ver"><?php foreach ($selVers as $i => $v): ?><option value="<?= e($v) ?>"<?= ($form['ver'] ?? '') === $v ? ' selected' : '' ?>><?= e($v . ($i === 0 ? ' (latest)' : '')) ?></option><?php endforeach ?><option value="">Not Sure</option></select></label>
          <?php $needVer = m3d_bug_needs_versions($sel); /* site.js switches "optional" and required when the picked item changes */ ?>
          <label class="rfield"><span class="rlabel">Blender <span class="opt" data-ver-opt<?= $needVer ? ' hidden' : '' ?>>optional</span></span>
            <input class="input" name="blender" maxlength="<?= BUG_MAX['short'] ?>" placeholder="e.g. 4.2" value="<?= e($form['blender'] ?? '') ?>" data-ver-in<?= $needVer ? ' required' : '' ?> /></label>
          <?php if (m3d_mustardui_on()): ?><label class="rfield"><span class="rlabel">MustardUI <span class="opt" data-ver-opt<?= $needVer ? ' hidden' : '' ?>>optional</span></span>
            <input class="input" name="mui" maxlength="<?= BUG_MAX['short'] ?>" placeholder="its version" value="<?= e($form['mui'] ?? '') ?>" data-ver-in<?= $needVer ? ' required' : '' ?> /></label><?php endif ?>
        </div>
        <label class="rfield"><span class="rlabel">Short Title</span>
          <input class="input" name="title" required minlength="5" maxlength="<?= BUG_MAX['title'] ?>" autocomplete="off" placeholder="e.g. Hair goes through the jacket when she bends" value="<?= e($form['title'] ?? '') ?>" data-bug-title /></label>
        <div class="similar" data-bug-similar hidden>
          <h3>Is it one of these? Press “Me Too” on an open one instead of sending a new report.</h3>
          <div data-bug-similar-list></div>
        </div>
        <label class="rfield"><span class="rlabel">What Happened?</span>
          <textarea class="input" name="body" rows="5" required minlength="10" maxlength="<?= BUG_MAX['body'] ?>" placeholder="What did you do, what did you expect, what happened instead?"><?= e($form['body'] ?? '') ?></textarea></label>
        <label class="rfield"><span class="rlabel">How Can We See It? <span class="opt">optional</span></span>
          <textarea class="input" name="steps" rows="3" maxlength="<?= BUG_MAX['steps'] ?>" placeholder="1. Open the file&#10;2. …"><?= e($form['steps'] ?? '') ?></textarea></label>
        <div class="rfield"><span class="rlabel">Screenshots or a Video <span class="opt">optional · links</span></span>
          <div class="links-in"><?php for ($i = 0; $i < BUG_LINKS; $i++): ?><input class="input" type="url" name="links[]" maxlength="500" placeholder="<?= $i ? 'Another link…' : 'https://…' ?>" aria-label="Link <?= $i + 1 ?>" value="<?= e($form['links'][$i] ?? '') ?>" /><?php endfor ?></div>
          <p class="rhelp"><?php if ($S['host'] !== ''): ?>Upload them to <a href="<?= e($S['host']) ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://(www\.)?#', '', rtrim($S['host'], '/'))) ?></a> (or any other site) and paste the links here.<?php else: ?>Upload them to any image or video site and paste the links here.<?php endif ?></p>
        </div>
        <?php if ($S['uploads']): ?>
          <label class="rfield"><span class="rlabel">Or Upload Pictures <span class="opt">optional · up to <?= BUG_PICS ?>, <?= BUG_PIC_BYTES >> 20 ?> MB each</span></span>
            <input class="input" type="file" name="pics[]" multiple accept="image/jpeg,image/png,image/webp" data-bug-pics="<?= BUG_PICS ?>" data-each="<?= BUG_PIC_BYTES ?>" data-total="<?= bug_post_max() ?>" /></label>
        <?php endif ?>
        <div class="r-actions">
          <span class="muted">Shown publicly with your Patreon name: <b><?= e($p['name'] ?? '') ?></b></span>
          <a class="btn btn-ghost" href="/bugs/">Cancel</a>
          <button class="btn btn-primary">Send Report</button>
        </div>
      </form>
      <?php endif ?>

      <aside class="rside">
        <div class="card-block">
          <h3>Before You Report</h3>
          <ol>
            <li>Update to the latest version: it may be fixed already.</li>
            <li>Search the <a href="/bugs/">bug reports</a>.</li>
            <li>One problem per report.</li>
          </ol>
        </div>
        <div class="card-block">
          <h3>Not a Bug?</h3>
          <p>Questions about downloads or your membership: see <?= m3d_has_faq() ? 'the <a href="' . e(SITE['faqs']) . '">FAQ</a> or ' : '' ?><a href="<?= e(SITE['contacts']) ?>">Contacts</a>.</p>
        </div>
      </aside>
    </div>
  </div>
<?php
    layout_end();
    exit;
}

// ════ The list ════
$f = [
    // '' = Open, the default (like GitHub); 'all' = every status
    'status' => in_array($_GET['status'] ?? '', ['all', 'fixed', 'closed', 'following', ...($creator ? ['unseen', 'pending', 'hidden'] : [])], true) ? (string) $_GET['status'] : '',
    'by'     => $creator && preg_match('/^[0-9A-Za-z_-]{1,40}$/D', bug_str($_GET['by'] ?? '')) ? (string) $_GET['by'] : '',   // one reporter's reports (the creator)
    'kind'   => in_array($_GET['kind'] ?? '', ['models', 'outfits', 'other'], true) ? (string) $_GET['kind'] : '',
    'about'  => preg_match('/^(models|outfits):[a-z0-9-]{1,64}$|^(mustardui|site|other)$/D', bug_str($_GET['about'] ?? '')) ? (string) $_GET['about'] : '',
    'tag'    => is_string($_GET['tag'] ?? null) && isset($tags[$_GET['tag']]) ? $_GET['tag'] : '',
    'mine'   => $p && in_array($_GET['mine'] ?? '', ['sent', 'metoo'], true) ? (string) $_GET['mine'] : '',
    'sort'   => in_array($_GET['sort'] ?? '', ['metoo', 'active'], true) ? (string) $_GET['sort'] : '',
    'q'      => bug_line($_GET['q'] ?? '', 80),
];
$myCode = $p ? m3d_like_code((string) $p['id']) : '';
$all = array_values(array_filter(m3d_bugs(), fn($b) => m3d_bug_visible($b, $p)));
$kindOf = fn($b) => in_array($k = m3d_bug_about_parts((string) ($b['about'] ?? ''))[0], ['models', 'outfits'], true) ? $k : 'other';
$statusOf = fn($b) => in_array($b['status'] ?? 'new', BUG_OPEN, true) ? 'open' : (string) $b['status'];
$fold = 'm3d_fold';
// everything but the status: the status chips count inside the other filters
$base = array_values(array_filter($all, function ($b) use ($f, $kindOf, $myCode, $p, $fold, $creator) {
    if ($f['by'] !== '' && (string) ($b['by'] ?? '') !== $f['by']) return false;
    if ($f['kind'] !== '' && $kindOf($b) !== $f['kind']) return false;
    if ($f['about'] !== '' && ($b['about'] ?? '') !== $f['about']) return false;
    if ($f['tag'] !== '' && !in_array($f['tag'], (array) ($b['tags'] ?? []), true)) return false;
    if ($f['mine'] === 'sent' && (string) ($b['by'] ?? '') !== (string) $p['id']) return false;
    if ($f['mine'] === 'metoo' && !in_array($myCode, (array) ($b['metoo'] ?? []), true)) return false;
    if ($f['q'] !== '') {
        $hay = $fold('#' . $b['n'] . ' ' . $b['title'] . ' ' . m3d_bug_about($b)['name'] . ' ' . ($b['body'] ?? '') . ($creator ? ' ' . ($b['name'] ?? '') . ' ' . ($b['by'] ?? '') : ''));   // the creator also finds people
        foreach (preg_split('/\s+/', $fold($f['q'])) as $w) if ($w !== '' && !str_contains($hay, $w)) return false;
    }
    return true;
}));
$following = m3d_bug_following($p);   // n => new entries since this visitor last opened it
$news = fn($b) => count($following[(int) $b['n']] ?? []);
// the creator's own views: reports with something not seen yet, waiting to be published, hidden from the site
$extra = ['unseen' => fn($b) => empty($b['seen']), 'pending' => fn($b) => !empty($b['pending']), 'hidden' => fn($b) => !empty($b['hidden'])];
$counts = ['all' => count($base), 'open' => 0, 'fixed' => 0, 'closed' => 0, 'following' => 0, 'unseen' => 0, 'pending' => 0, 'hidden' => 0];
foreach ($base as $b) {
    $counts[$statusOf($b)]++;
    if (isset($following[(int) $b['n']])) $counts['following']++;
    if ($creator) foreach ($extra as $k => $fn) if ($fn($b)) $counts[$k]++;
}
$shown = match ($f['status']) {
    'all' => $base,
    '' => array_values(array_filter($base, fn($b) => $statusOf($b) === 'open')),
    'following' => array_values(array_filter($base, fn($b) => isset($following[(int) $b['n']]))),
    'unseen', 'pending', 'hidden' => array_values(array_filter($base, $extra[$f['status']])),
    default => array_values(array_filter($base, fn($b) => $statusOf($b) === $f['status'])),
};
$pin = fn($b) => !empty($b['pin']) && in_array($b['status'] ?? 'new', BUG_OPEN, true) ? 1 : 0;
$first = $f['status'] === 'following' ? fn($b) => $news($b) > 0 ? 1 : 0 : $pin;   // Following: the ones with news on top
usort($shown, fn($a, $b) => $first($b) <=> $first($a) ?: match ($f['sort']) {
    'metoo' => count((array) ($b['metoo'] ?? [])) <=> count((array) ($a['metoo'] ?? [])) ?: (int) $b['n'] <=> (int) $a['n'],
    'active' => (int) ($b['act'] ?? 0) <=> (int) ($a['act'] ?? 0),
    default => (int) $b['n'] <=> (int) $a['n'],
});
$pages = max(1, (int) ceil(count($shown) / BUG_PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$list = array_slice($shown, ($page - 1) * BUG_PER_PAGE, BUG_PER_PAGE);
$cur = array_filter($f, fn($v) => $v !== '');
$active = count(array_filter([$f['kind'], $f['about'], $f['tag'], $f['mine'], $f['sort']], fn($v) => $v !== ''));
// "About" menu: what the reports are about (with their count)
$aboutCount = [];
foreach ($all as $b) $aboutCount[(string) ($b['about'] ?? 'other')] = ($aboutCount[(string) ($b['about'] ?? 'other')] ?? 0) + 1;
$aboutNames = [];
foreach ($all as $b) { $k = (string) ($b['about'] ?? 'other'); $aboutNames[$k] ??= $k === 'other' ? 'Other' : m3d_bug_about($b)['name']; }   // "other" also holds deleted items' reports: never one of their names
asort($aboutNames, SORT_NATURAL | SORT_FLAG_CASE);
$usedTags = array_filter($tags, fn($id) => array_filter($all, fn($b) => in_array((string) $id, (array) ($b['tags'] ?? []), true)), ARRAY_FILTER_USE_KEY);
$chip = fn(string $label, array $q, bool $on, string $extra = '') => '<a class="chip" href="' . e(bugs_url($q, $cur)) . '" aria-pressed="' . ($on ? 'true' : 'false') . '">' . $extra . $label . '</a>';

$byName = '';
if ($f['by'] !== '') foreach ($all as $b) if ((string) ($b['by'] ?? '') === $f['by'] && ($b['name'] ?? '') !== '') { $byName = (string) $b['name']; break; }

layout_start('Bug Reports', 'Known bugs in the models, accessories and website of ' . site_name() . ', and a page to report new ones.', 'page-bugs');
?>
  <div class="wrap bugs">
    <?= $offNote ?>
    <header class="bugs-head">
      <div>
        <p class="eyebrow">Help</p>
        <h1>Bug Reports</h1>
        <p class="lead lead-sm">Something broken? Look here first: if it’s already reported, press <b>Me Too</b> so it gets fixed sooner.</p>
      </div>
      <div class="bugs-acts">
        <?php if ($creator): ?><button type="button" class="btn btn-ghost" data-tags-open>Edit Tags</button><a class="btn btn-ghost" href="/admin/settings.php?tab=features#bugs">Settings</a><?php endif ?>
        <a class="btn btn-primary" href="/bugs/report/<?= $f['about'] !== '' ? '?about=' . e(rawurlencode($f['about'])) : '' ?>"><?= bug_icon('bug') ?>Report a Bug</a>
      </div>
    </header>
    <?php bug_result_flash($creator) // back from a batch change (admin/bugs.php) or a deleted report ?>

    <nav class="chips bugs-status" aria-label="Status">
      <?= $chip('Open <span>' . $counts['open'] . '</span>', ['status' => ''], $f['status'] === '', '<i class="sdot" style="--c:#7cc4ff"></i>') ?>
      <?= $chip('Fixed <span>' . $counts['fixed'] . '</span>', ['status' => 'fixed'], $f['status'] === 'fixed', '<i class="sdot" style="--c:var(--green)"></i>') ?>
      <?= $chip('Closed <span>' . $counts['closed'] . '</span>', ['status' => 'closed'], $f['status'] === 'closed', '<i class="sdot" style="--c:var(--grey)"></i>') ?>
      <?= $chip('All <span>' . $counts['all'] . '</span>', ['status' => 'all'], $f['status'] === 'all') ?>
      <?php if ($following): ?><?= $chip('Following <span>' . $counts['following'] . '</span>', ['status' => 'following'], $f['status'] === 'following', '<i class="sdot" style="--c:var(--patreon)"></i>') ?><?php endif ?>
      <?php if ($creator): $ownChips = array_filter(['unseen' => 'Not Seen', 'pending' => 'Waiting', 'hidden' => 'Hidden'], fn($k) => $counts[$k] || $f['status'] === $k, ARRAY_FILTER_USE_KEY); ?>
        <?php if ($ownChips): ?><span class="chips-sep" aria-hidden="true"></span><?php endif ?>
        <?php foreach ($ownChips as $k => $l) echo $chip($l . ' <span>' . $counts[$k] . '</span>', ['status' => $k], $f['status'] === $k, $k === 'unseen' ? '<i class="sdot" style="--c:var(--mustard)"></i>' : '') ?>
      <?php endif ?>
    </nav>

    <form class="filters hfilters bugs-filters" role="search" action="/bugs/" method="get">
      <?php foreach ($cur as $k => $v) if ($k !== 'q') echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '" />' ?>
      <input class="input search" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search bug reports…" aria-label="Search bug reports" />
      <?= filter_toggle('bug-filters', $active > 0, $active) ?>
    </form>
    <div class="filter-panel" id="bug-filters"<?= $active ? '' : ' hidden' ?>>
      <div class="chips chips-gen" role="group" aria-label="Sort"><span class="chips-lbl">Sort</span>
        <?= $chip('Newest', ['sort' => ''], $f['sort'] === '') ?><?= $chip('Most “Me Too”', ['sort' => 'metoo'], $f['sort'] === 'metoo') ?><?= $chip('Recently Active', ['sort' => 'active'], $f['sort'] === 'active') ?>
      </div>
      <div class="chips chips-gen" role="group" aria-label="About"><span class="chips-lbl">About</span>
        <?= $chip('All', ['kind' => '', 'about' => ''], $f['kind'] === '' && $f['about'] === '') ?><?= $chip('Models', ['kind' => 'models', 'about' => ''], $f['kind'] === 'models') ?><?= $chip('Accessories', ['kind' => 'outfits', 'about' => ''], $f['kind'] === 'outfits') ?><?= $chip('Other', ['kind' => 'other', 'about' => ''], $f['kind'] === 'other') ?>
        <?php if ($aboutNames): ?>
          <form class="bug-about-pick" action="/bugs/" method="get" data-bug-about-pick>
            <?php foreach ($cur as $k => $v) if (!in_array($k, ['about', 'kind'], true)) echo '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '" />' ?>
            <select class="input input-sm" name="about" aria-label="Pick a model or accessory" data-about-picker><option value="">Pick a model or accessory…</option><?php foreach ($aboutNames as $k => $name) echo bug_about_option((string) $k, $name, $k === $f['about'], (string) $aboutCount[$k]) ?></select>
            <noscript><button class="btn btn-ghost btn-sm">Show</button></noscript>
          </form>
        <?php endif ?>
      </div>
      <?php if ($usedTags): ?>
        <div class="chips chips-gen" role="group" aria-label="Tags"><span class="chips-lbl">Tags</span>
          <?= $chip('All', ['tag' => ''], $f['tag'] === '') ?>
          <?php foreach ($usedTags as $id => [$name, $c]) echo $chip(e($name), ['tag' => (string) $id], $f['tag'] === (string) $id, '<i class="sdot" style="--c:' . e($c) . '"></i>') ?>
        </div>
      <?php endif ?>
      <?php if ($p): ?>
        <div class="chips chips-gen" role="group" aria-label="Mine"><span class="chips-lbl">Mine</span>
          <?= $chip('All', ['mine' => ''], $f['mine'] === '') ?><?= $chip('Reported by Me', ['mine' => 'sent'], $f['mine'] === 'sent') ?><?= $chip('With My “Me Too”', ['mine' => 'metoo'], $f['mine'] === 'metoo') ?>
        </div>
      <?php endif ?>
    </div>

    <?php if ($f['by'] !== ''): ?><p class="bug-notice"><span>Reports by <b><?= e($byName ?: 'someone') ?></b> (Patreon ID <?= e($f['by']) ?>). <a href="<?= e(bugs_url(['by' => ''], $cur)) ?>">Show Everyone’s</a></span></p><?php endif ?>

    <div class="bugs-count" id="list">
      <p class="muted small"><?= m3d_plural(count($shown), 'report') ?><?= $f['q'] !== '' ? ' for “' . e($f['q']) . '”' : '' ?><?= $active || $f['q'] !== '' || $f['by'] !== '' ? ' · <a href="' . e(bugs_url([], array_intersect_key($cur, ['status' => 1]))) . '">Clear Filters</a>' : '' ?></p>
      <?php if ($creator && $list): ?><label class="bug-tick-all"><input type="checkbox" data-bug-all /> Select All</label><?php endif ?>
    </div>

    <?php if (!$list): ?>
      <?php $noOpen = $all && $f['status'] === '' && !$active && $f['q'] === '' && $f['by'] === ''; ?>
      <div class="empty"><h3><?= $noOpen ? 'No Open Reports' : ($all ? 'Nothing Matches' : 'No Bug Reports Yet') ?></h3><p class="muted"><?= $noOpen ? 'Every report is fixed or closed. <a href="/bugs/?status=all">See them all</a>, or report a new one.' : ($all ? 'Try another filter, or report it.' : 'Found one? Be the first to report it.') ?></p></div>
    <?php else: ?>
      <?php if ($creator): ?><form method="post" action="/admin/bugs.php" data-bug-batch><input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="batch" /><input type="hidden" name="back" value="<?= e(bugs_url(['_page' => $page > 1 ? $page : ''], $cur)) ?>" /><?php endif ?>
      <ol class="bug-list<?= $creator ? ' can-tick' : '' ?>">
        <?php foreach ($list as $b): $a = m3d_bug_about($b); $st = (string) ($b['status'] ?? 'new'); $done = !in_array($st, BUG_OPEN, true);
          $me = count((array) ($b['metoo'] ?? [])); $com = count(array_filter((array) ($b['log'] ?? []), fn($x) => ($x['t'] ?? '') === 'c'));
          $unseen = $creator && empty($b['seen']); ?>
          <li><?php if ($creator): ?><label class="bug-tick"><input type="checkbox" name="ns[]" value="<?= (int) $b['n'] ?>" aria-label="Select #<?= (int) $b['n'] ?>" /></label><?php endif ?><a class="bug-row<?= $done ? ' is-done' : '' ?><?= $st === 'fixed' ? ' is-fixed' : ($st === 'closed' ? ' is-closed' : '') ?><?= $pin($b) ? ' is-pinned' : '' ?><?= $news($b) || $unseen ? ' has-new' : '' ?>" href="/bugs/<?= (int) $b['n'] ?>/<?= $news($b) ? '#new' : '' ?>">
            <?= bug_about_pic($a, 'bpic bug-pic') ?>
            <span class="bug-main">
              <span class="bug-title"><?php if ($pin($b)): ?><span class="pin-tag"><?= bug_icon('pin') ?>Known Issue</span><?php endif ?><span class="bug-title-t"><?= e($b['title']) ?></span><?php foreach ((array) ($b['tags'] ?? []) as $t) echo bug_tag_html((string) $t, $tags) ?></span>
              <span class="bug-meta">
                <?php if ($news($b)): ?><span class="bug-new"><?= e(bug_news_label($following[(int) $b['n']])) ?></span><?php elseif ($unseen): ?><span class="bug-new">Not Seen</span><?php endif ?>
                <?= bug_status_badge($b) ?>
                <?php if (!empty($b['pending'])): ?><span class="badge tone-mustard">Waiting for a Check</span><?php endif ?>
                <?php if (!empty($b['hidden'])): ?><span class="badge tone-grey">Hidden</span><?php endif ?>
                <span class="bug-item"><b><?= e($a['name']) ?></b><?= ($b['ver'] ?? '') !== '' ? ' ' . e($b['ver']) : '' ?></span>
                <span class="bug-sub">#<?= (int) $b['n'] ?> · reported <?= e(bug_ago((int) $b['at'])) ?> by <?= e($b['name'] ?: 'someone') ?></span>
              </span>
            </span>
            <span class="bug-nums">
              <span class="bnum<?= $me ? '' : ' zero' ?>" title="<?= $me === 1 ? '1 person has it too' : $me . ' people have it too' ?>"><?= bug_icon('metoo') ?><?= $me ?></span>
              <span class="bnum<?= $com ? '' : ' zero' ?>" title="<?= m3d_plural($com, 'comment') ?>"><?= bug_icon('comment') ?><?= $com ?></span>
            </span>
          </a></li>
        <?php endforeach ?>
      </ol>
      <?php if ($creator): ?>
        <div class="bug-batch" data-bug-bar hidden>
          <b><span data-bug-count>0</span> selected</b>
          <select class="input" name="op" aria-label="What to do with them" required>
            <option value="">Do with Them…</option>
            <optgroup label="Set Status"><?php foreach (BUG_STATUSES as $k => [$l]): ?><option value="status:<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></optgroup>
            <?php if ($tags): ?><optgroup label="Add Tag"><?php foreach ($tags as $id => [$l]): ?><option value="tag:<?= e((string) $id) ?>"><?= e($l) ?></option><?php endforeach ?></optgroup><?php endif ?>
            <optgroup label="Other"><option value="seen">Mark as Seen</option><?php if ($counts['pending']): ?><option value="publish">Publish</option><?php endif ?><option value="hide">Hide from the Site</option><option value="show">Show on the Site Again</option><option value="delete">Delete Them</option></optgroup>
          </select>
          <button class="btn btn-primary btn-sm">Apply</button>
          <button type="button" class="btn btn-ghost btn-sm" data-bug-none>Clear</button>
        </div>
      </form><?php endif ?>
    <?php endif ?>

    <?php if ($pages > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="chip" href="<?= e(bugs_url(['_page' => $page - 1 > 1 ? $page - 1 : ''], $cur)) ?>" aria-label="Previous page">‹</a><?php endif ?>
        <?php $gap = false; for ($i = 1; $i <= $pages; $i++): if ($pages > 7 && $i > 2 && $i < $pages - 1 && abs($i - $page) > 1) { if (!$gap) echo '<span class="pager-gap">…</span>'; $gap = true; continue; } $gap = false; ?>
          <a class="chip" href="<?= e(bugs_url(['_page' => $i > 1 ? $i : ''], $cur)) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
        <?php endfor ?>
        <?php if ($page < $pages): ?><a class="chip" href="<?= e(bugs_url(['_page' => $page + 1], $cur)) ?>" aria-label="Next page">›</a><?php endif ?>
      </nav>
    <?php endif ?>
  </div>
<?php if ($creator) echo bug_tags_dialog(bugs_url(['_page' => $page > 1 ? $page : ''], $cur));
layout_end();
