<?php
// The creator's changes to one bug report, made on the report's own page (/bugs/<n>/: Creator Tools, Reported By, the ⋯ menus).
// POST do = state (status with "fixed in" / the reason it was closed, tags, pin / hide / lock) | title (edited in place) | edit (what the person wrote) |
// publish | editcomment | delcomment | block | unblock | delete (CSRF), each coming back to the report with ?done= or ?err= (a code:
// bug_result_text() has the messages, so the address never carries a text).
// Opening this address shows the report.
require __DIR__ . '/_admin.php';
admin_require($M3D);

$n = (int) ($_GET['n'] ?? 0);
$b = m3d_bug($n);
if (!$b) { http_response_code(404); admin_start('Bug Not Found', 'bugs'); echo '<div class="admin-head"><div><a class="back" href="/bugs/">← All Bug Reports</a><h1>Bug Not Found</h1></div></div><p class="muted">This report doesn’t exist (any more).</p>'; admin_end(); exit; }
$page = "/bugs/$n/";
if (!admin_form_post()) m3d_redirect($page);

$do = bug_str($_POST['do'] ?? '');
$now = time();
$msg = '';
$error = '';
$anchor = '';
switch ($do) {
    case 'state':
        $st = bug_str($_POST['status'] ?? '');
        if (!isset(BUG_STATUSES[$st])) { $error = 'status'; break; }
        // a typed version (website / other reports); an item's report has no field (its version comes from the changelog): what's stored stays, so no new log line
        $fixed = $st !== 'fixed' ? '' : (array_key_exists('fixed', $_POST) ? bug_line($_POST['fixed'], 80) : (string) ($b['fixed'] ?? ''));
        $reason = $st === 'closed' && isset(BUG_REASONS[bug_str($_POST['reason'] ?? '')]) ? (string) $_POST['reason'] : '';
        $dup = $reason === 'duplicate' ? (int) bug_str($_POST['dup'] ?? '') : 0;
        // (a number saved before may since have been deleted: it doesn't block the other changes)
        if ($dup === $n || ($dup > 0 && $dup !== (int) ($b['dup'] ?? 0) && !m3d_bug($dup))) { $error = 'dup'; break; }
        $tags = array_values(array_intersect(array_map('strval', array_keys(m3d_bug_tags())), array_map('bug_str', (array) ($_POST['tags'] ?? []))));   // ids as strings, also digit-only ones
        $res = m3d_bug_change($n, function (array $b) use ($st, $fixed, $reason, $dup, $tags, $now) {
            $was = (string) ($b['status'] ?? 'new');
            $b = m3d_bug_set_status($b, $st, $now, $fixed, $reason, $dup);
            $added = array_values(array_diff($tags, (array) ($b['tags'] ?? [])));
            if ($added) $b['log'][] = ['t' => 'tag', 'at' => $now, 'add' => $added];
            $b['tags'] = $tags;
            // into or out of Fixed / Closed: the new status set the lock itself (m3d_bug_set_status())
            $auto = $st !== $was && (!in_array($st, BUG_OPEN, true) || !in_array($was, BUG_OPEN, true));
            foreach (['pin', 'hidden', 'locked'] as $k) {
                if ($k === 'locked' && $auto && empty($_POST['lock_set'])) continue;   // lock_set: the switch was flipped by hand after picking the status (site.js)
                if (!empty($_POST[$k])) $b[$k] = true; else unset($b[$k]);
            }
            $b['seen'] = true;
            return $b;
        });
        $msg = $res ? 'saved' : '';
        $anchor = '#tools';
        break;
    case 'title':   // edited in place, next to the title (like GitHub)
        $title = bug_line($_POST['title'] ?? '', BUG_MAX['title']);
        if ($title === '') { $error = 'title'; break; }
        if ($title === (string) ($b['title'] ?? '')) m3d_redirect($page);   // nothing changed: nothing to say
        $res = m3d_bug_change($n, function (array $b) use ($title) { $b['title'] = $title; $b['seen'] = true; return $b; });
        $msg = $res ? 'title' : '';
        break;
    case 'edit':
        $abouts = m3d_bug_abouts_all();
        $about = bug_str($_POST['about'] ?? '');
        if (!isset($abouts[$about]) && $about !== ($b['about'] ?? '')) { $error = 'about'; break; }
        $del = array_map('strval', (array) ($_POST['delpic'] ?? []));
        $gone = [];
        $res = m3d_bug_change($n, function (array $b) use ($about, $abouts, $del, &$gone) {
            if ($about !== ($b['about'] ?? '')) { $b['about'] = $about; $b['about_name'] = bug_about_name($abouts[$about][0]); unset($b['gone']); }
            $b['ver'] = bug_line($_POST['ver'] ?? '', BUG_MAX['short']);
            $b['blender'] = bug_line($_POST['blender'] ?? '', BUG_MAX['short']);
            if (isset($_POST['mui'])) $b['mui'] = bug_line($_POST['mui'], BUG_MAX['short']);   // no field while the MustardUI page is off: kept
            $b['body'] = bug_text($_POST['body'] ?? '', BUG_MAX['body']);
            $b['steps'] = bug_text($_POST['steps'] ?? '', BUG_MAX['steps']);
            $b['links'] = bug_links($_POST['links'] ?? []);
            $pics = (array) ($b['pics'] ?? []);
            $gone = array_values(array_intersect($pics, $del));
            $b['pics'] = array_values(array_diff($pics, $del));
            $b['seen'] = true;
            return $b;
        });
        if (!$res) break;
        bug_delete_pics($gone);
        $msg = 'edited';
        break;
    case 'publish':
        $res = m3d_bug_change($n, function (array $b) { unset($b['pending']); $b['seen'] = true; return $b; });
        $msg = $res ? 'published' : '';
        break;
    case 'editcomment':
    case 'delcomment':
        $i = (int) ($_POST['i'] ?? -1);
        $text = bug_text($_POST['text'] ?? '', BUG_MAX['comment']);
        if ($do === 'editcomment' && mb_strlen($text) < 2) { $error = 'empty'; break; }
        $res = m3d_bug_change($n, function (array $b) use ($i, $do, $text) {
            if (($b['log'][$i]['t'] ?? '') !== 'c') return $b;
            if ($do === 'delcomment') { unset($b['log'][$i]); $b['log'] = array_values($b['log']); }
            else $b['log'][$i]['text'] = $text;
            return $b;
        });
        $msg = $res ? ($do === 'delcomment' ? 'cdeleted' : 'cchanged') : '';
        $anchor = $do === 'editcomment' ? "#c$i" : '#comments';
        break;
    case 'block':
    case 'unblock':
        // the reporter, or someone who commented on this report (`who`); unblocking: anyone on the list
        $id = bug_str($_POST['who'] ?? ($b['by'] ?? ''));
        $here = array_merge([(string) ($b['by'] ?? '')], array_map(fn($x) => (string) ($x['by'] ?? ''), array_filter((array) ($b['log'] ?? []), fn($x) => ($x['t'] ?? '') === 'c' && empty($x['admin']))));
        if ($id === '' || !in_array($id, $do === 'block' ? $here : m3d_bug_settings()['blocked'], true)) { $error = $do; break; }
        $name = '';   // the name they posted with, for the record in Admin → Access
        foreach (array_merge([$b], (array) ($b['log'] ?? [])) as $x) if ((string) ($x['by'] ?? '') === $id && ($x['name'] ?? '') !== '') $name = (string) $x['name'];
        $msg = m3d_ban_set($id, 'bugs', $do === 'block', $name) ? $do . 'ed' : '';   // a ban in Admin → Access
        break;
    case 'delete':
        $pics = (array) ($b['pics'] ?? []);
        m3d_bug_change($n, fn() => null);
        if (!m3d_bug($n)) {
            bug_delete_pics($pics);
            m3d_redirect("/bugs/?done=deleted-$n#list");
        }
        break;
    default:
        $error = 'action';
}
if (!$error && $msg === '') $error = 'write';
m3d_redirect($page . '?' . ($error ? 'err=' . $error : 'done=' . $msg) . $anchor);   // codes of bug_result_text()
