<?php
// Admin → Settings → Privacy → Personal Data: everything the site stores about one Patreon user, for a GDPR access request
// (Art. 15) or a copy to take elsewhere (Art. 20). Read only: nothing is changed or logged. Included only by settings.php.
//   By Patreon ID: the download log, an access exception, bug reports and comments, the bug-report block list.
//   By the scrambled code (m3d_like_code() of the ID; the site holds the key, so it finds them from the ID): likes, ignored
//   updates, the welcome page, Me Too and following bug reports.
// pd_collect() gathers it; pd_html() is the readable file (one page, opens in any browser, prints); the JSON is the same data.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

/** A model's or accessory's name from its kind + slug ($gone: the address itself, e.g. a removed item's log text). */
function pd_item_name(string $kind, string $slug): string
{
    $it = m3d_item($kind, $slug);
    return $it ? ($kind === 'models' ? m3d_model_name($it) : (string) $it['name']) : $slug;
}

/** Everything stored about one Patreon user (see the header). Dates are unix times. */
function pd_collect(string $id): array
{
    $code = m3d_like_code($id);
    $d = ['id' => $id, 'name' => '', 'names' => [], 'made' => time(), 'downloads' => [], 'exception' => null,
          'likes' => ['models' => [], 'outfits' => [], 'stills' => []], 'ignored' => [], 'welcome' => null,
          'reports' => [], 'comments' => [], 'metoo' => [], 'following' => [], 'blocked' => false];
    $names = [];

    // download log: every column of their lines (patron links only: public links are only counted)
    if ($fh = @fopen(m3d_log_file(), 'rb')) {
        while (($line = fgets($fh)) !== false) {
            if (!str_contains($line, $id)) continue;
            $r = m3d_log_cols($line);
            if (($r[1] ?? '') !== $id || !isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
            [$k, $s] = m3d_log_item_parts((string) $r[3]);
            $names[] = (string) ($r[2] ?? '');
            $d['downloads'][] = ['at' => $t, 'name' => (string) ($r[2] ?? ''), 'kind' => $k, 'item' => pd_item_name($k, $s), 'link' => (string) ($r[4] ?? ''),
                'version' => (string) ($r[5] ?? ''), 'note' => in_array($r[6] ?? '', ['free', 'update'], true) ? (string) $r[6] : '', 'membership' => m3d_log_membership($r)];
        }
        fclose($fh);
    }

    if ($x = m3d_exception($id)) {
        $d['exception'] = array_intersect_key($x, array_flip(['name', 'note', 'tier', 'extra', 'limits', 'tierCents', 'months', 'totalCents', 'rules', 'added']));
    }

    foreach (['models', 'outfits'] as $k) foreach (m3d_likes($k) as $slug => $codes) if (in_array($code, (array) $codes, true)) $d['likes'][$k][] = pd_item_name($k, (string) $slug);
    foreach (m3d_likes('stills') as $sid => $codes) if (in_array($code, (array) $codes, true)) {
        $s = m3d_still((string) $sid);
        $d['likes']['stills'][] = $s ? m3d_still_name($s) . ' (' . $s['src'] . ')' : 'Picture ' . $sid;
    }

    foreach ((array) ((m3d_read_json('ignored') ?? [])['ignored'][$code] ?? []) as $key => $ver) {
        [$k, $s] = explode(':', (string) $key, 2) + [1 => ''];
        $d['ignored'][] = ['kind' => $k, 'item' => isset(M3D_KINDS[$k]) ? pd_item_name($k, $s) : (string) $key, 'version' => (string) $ver];
    }

    $seen = (m3d_read_json('welcomed') ?? [])['seen'][$code] ?? null;
    if ($seen !== null) $d['welcome'] = (int) $seen;

    foreach (m3d_bugs() as $b) {
        $n = (int) ($b['n'] ?? 0);
        $title = (string) ($b['title'] ?? '');
        if ((string) ($b['by'] ?? '') === $id) {
            $names[] = (string) ($b['name'] ?? '');
            $d['reports'][] = ['n' => $n, 'title' => $title, 'at' => (int) ($b['at'] ?? 0), 'about' => (string) ($b['about_name'] ?? $b['about'] ?? ''),
                'version' => (string) ($b['ver'] ?? ''), 'blender' => (string) ($b['blender'] ?? ''), 'mustardui' => (string) ($b['mui'] ?? ''),
                'text' => (string) ($b['body'] ?? ''), 'steps' => (string) ($b['steps'] ?? ''), 'links' => array_values((array) ($b['links'] ?? [])),
                'pictures' => array_values((array) ($b['pics'] ?? [])), 'shown_as' => (string) ($b['name'] ?? '')];
        }
        foreach ((array) ($b['log'] ?? []) as $x) if (($x['t'] ?? '') === 'c' && (string) ($x['by'] ?? '') === $id && empty($x['admin'])) {
            $names[] = (string) ($x['name'] ?? '');
            $d['comments'][] = ['n' => $n, 'title' => $title, 'at' => (int) ($x['at'] ?? 0), 'text' => (string) ($x['text'] ?? ''), 'shown_as' => (string) ($x['name'] ?? '')];
        }
        if (in_array($code, (array) ($b['metoo'] ?? []), true)) $d['metoo'][] = ['n' => $n, 'title' => $title];
        if (isset(((array) ($b['fol'] ?? []))[$code])) $d['following'][] = ['n' => $n, 'title' => $title, 'opened' => (int) $b['fol'][$code]];
    }
    $d['blocked'] = in_array($id, array_map('strval', m3d_bug_settings()['blocked']), true);

    $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($s) => $s !== '' && $s !== $id)));
    $d['names'] = $names;   // as Patreon sent them (the exception's name is the site's own, in its section)
    $d['name'] = $d['downloads'] ? (string) end($d['downloads'])['name'] : ($names[0] ?? (string) ($d['exception']['name'] ?? ''));
    return $d;
}

/** Whether anything was found. */
function pd_found(array $d): bool
{
    return $d['downloads'] || $d['exception'] || array_filter($d['likes']) || $d['ignored'] || $d['welcome'] !== null
        || $d['reports'] || $d['comments'] || $d['metoo'] || $d['following'] || $d['blocked'];
}

/** One "Tier: Custom (at least …)" line per part of an exception. */
function pd_exception_lines(array $x): array
{
    $mode = fn($v) => ['bypass' => 'Bypass', 'custom' => 'Custom'][m3d_ex_mode($v)] ?? 'Normal';
    $tier = $mode($x['tier'] ?? null);
    if (m3d_ex_mode($x['tier'] ?? null) === 'custom' && (int) ($x['tierCents'] ?? 0) > 0) $tier .= ': counts as at least the ' . m3d_tier_label((int) $x['tierCents']);
    $req = $mode($x['extra'] ?? null);
    if (m3d_ex_mode($x['extra'] ?? null) === 'custom') {
        $p = [];
        if ((int) ($x['months'] ?? 0) > 0) $p[] = m3d_plural((int) $x['months'], 'month') . ' as a patron';
        if ((int) ($x['totalCents'] ?? 0) > 0) $p[] = m3d_money((int) $x['totalCents']) . ' total support';
        if ($p) $req .= ': counts as at least ' . implode(' and ', $p);
    }
    $lim = $mode($x['limits'] ?? null);
    if (m3d_ex_mode($x['limits'] ?? null) === 'custom' && ($rules = m3d_ex_rules($x))) $lim .= ': ' . implode('; ', array_map(fn($r) => m3d_rule_text($r, true), $rules));
    $out = ['Name Written by the Site' => (string) ($x['name'] ?? ''), 'Note' => (string) ($x['note'] ?? ''), 'Tier' => $tier, 'Requirements' => $req, 'Unlock Limits' => $lim];
    if (($x['added'] ?? '') !== '') $out['Added'] = m3d_site_time((string) $x['added'], 'j M Y');
    return array_filter($out, fn($v) => $v !== '');
}

/** The membership saved with a download, in words ("19 months as a patron (since 3 Mar 2025) · €10 tier · €190 total support"). */
function pd_membership(?array $m): string
{
    if (!$m) return '';
    $p = [];
    if ($m['months'] !== null) $p[] = m3d_plural($m['months'], 'month') . ' as a patron' . ($m['since'] !== '' ? ' (since ' . m3d_site_time($m['since'], 'j M Y') . ')' : '');
    elseif ($m['since'] !== '') $p[] = 'patron since ' . m3d_site_time($m['since'], 'j M Y');
    if ($m['tier_cents'] !== null) $p[] = m3d_money($m['tier_cents']) . ' tier';
    if ($m['total_cents'] !== null) $p[] = m3d_money($m['total_cents']) . ' total support';
    return implode(' · ', $p);
}

/** The rows of the summary on the Settings page: [label => text]. */
function pd_summary(array $d): array
{
    $dl = $d['downloads'];
    $items = count(array_unique(array_map(fn($r) => $r['kind'] . ':' . $r['item'], $dl)));
    $l = $d['likes'];
    $likes = array_filter([count($l['models']) ? m3d_plural(count($l['models']), 'model') : '', count($l['outfits']) ? m3d_plural(count($l['outfits']), 'accessory', 'accessories') : '',
        count($l['stills']) ? m3d_plural(count($l['stills']), 'gallery picture') : '']);
    $bugs = array_filter([$d['reports'] ? m3d_plural(count($d['reports']), 'report') : '', $d['comments'] ? m3d_plural(count($d['comments']), 'comment') : '',
        $d['metoo'] ? 'Me Too on ' . count($d['metoo']) : '', $d['following'] ? 'following ' . count($d['following']) : '']);
    return [
        'Download Log' => $dl ? m3d_plural(count($dl), 'download') . ' of ' . $items . ' ' . ($items === 1 ? 'model or accessory' : 'models and accessories')
            . ' (' . m3d_site_time($dl[0]['at'], 'j M Y') . ' – ' . m3d_site_time(end($dl)['at'], 'j M Y') . ')' : '',
        'Likes' => implode(', ', $likes),
        'Ignored Updates' => $d['ignored'] ? (string) count($d['ignored']) : '',
        'Welcome Page' => $d['welcome'] !== null ? 'Seen ' . m3d_site_time($d['welcome'], 'j M Y') : '',
        'Bug Reports' => implode(', ', $bugs),
        'Access Exception' => $d['exception'] ? 'Yes' : '',
        'Blocked from Bug Reports' => $d['blocked'] ? 'Yes' : '',
    ];
}

/** The same data for the JSON file: dates as ISO 8601 (UTC), labels in plain words. */
function pd_json(array $d): array
{
    $iso = fn(?int $t) => $t ? gmdate('c', $t) : null;
    return [
        'about' => 'Personal data stored by ' . site_name() . ' (' . abs_url('/') . ') about one Patreon user, made ' . gmdate('c', $d['made']) . '.',
        'patreon_user_id' => $d['id'],
        'names' => $d['names'],
        'download_log' => array_map(fn($r) => ['date' => $iso($r['at']), 'name' => $r['name'], 'kind' => $r['kind'] === 'outfits' ? 'accessory' : 'model', 'item' => $r['item'],
            'version' => $r['version'], 'link' => $r['link'],
            'note' => ['free' => 'not counted in the download limits', 'update' => 'an update unlocked again'][$r['note']] ?? '',
            'membership' => $r['membership'] ? ['months_as_patron' => $r['membership']['months'], 'patron_since' => $r['membership']['since'] ?: null,
                'tier_cents' => $r['membership']['tier_cents'], 'total_support_cents' => $r['membership']['total_cents'], 'currency' => m3d_currency()] : null], $d['downloads']),
        'access_exception' => $d['exception'] ? pd_exception_lines($d['exception']) : null,
        'likes' => ['models' => $d['likes']['models'], 'accessories' => $d['likes']['outfits'], 'gallery_pictures' => $d['likes']['stills']],
        'ignored_updates' => array_map(fn($r) => ['item' => $r['item'], 'version' => $r['version']], $d['ignored']),
        'welcome_page_seen' => $iso($d['welcome']),
        'bug_reports' => array_map(fn($r) => ['number' => $r['n'], 'date' => $iso($r['at'])] + array_diff_key($r, ['n' => 1, 'at' => 1]), $d['reports']),
        'bug_comments' => array_map(fn($r) => ['report' => $r['n'], 'report_title' => $r['title'], 'date' => $iso($r['at']), 'text' => $r['text'], 'shown_as' => $r['shown_as']], $d['comments']),
        'bug_me_too' => array_map(fn($r) => ['report' => $r['n'], 'report_title' => $r['title']], $d['metoo']),
        'bug_following' => array_map(fn($r) => ['report' => $r['n'], 'report_title' => $r['title'], 'last_opened' => $iso($r['opened'])], $d['following']),
        'blocked_from_bug_reports' => $d['blocked'],
        'not_included' => pd_not_included(),
    ];
}

/** What the file can't contain, for the last section. */
function pd_not_included(): array
{
    return [
        'While you are logged in, a session file on the server holds what Patreon sent at login (shown on My account); it is deleted when you log out or the login expires.',
        'The web host and Cloudflare keep short-term server logs with IP addresses; the website itself stores no IP addresses.',
        'Backups of the website contain copies of the data above; they are kept to restore the site after a failure.',
    ] + (m3d_bugs_on() && m3d_bug_settings()['notify'] ? [3 => 'New bug reports are also sent to a private Discord channel of ' . site_name() . ' (title, text and your Patreon name).'] : []);
}

/** The readable file: a single HTML page, light, printable, no outside files. */
function pd_html(array $d): string
{
    $t = fn(int $ts) => e(m3d_site_time($ts, 'j M Y, H:i T'));
    $none = '<p class="none">None.</p>';
    $who = $d['name'] !== '' ? $d['name'] : 'Patreon user ' . $d['id'];
    $sec = function (string $title, string $why, string $body): string {
        return '<h2>' . e($title) . '</h2>' . ($why !== '' ? '<p class="why">' . e($why) . '</p>' : '') . $body;
    };
    $list = fn(array $xs) => $xs ? '<ul>' . implode('', array_map(fn($x) => '<li>' . e($x) . '</li>', $xs)) . '</ul>' : '<p class="none">None.</p>';
    $text = fn(string $s) => '<div class="text">' . nl2br(e($s)) . '</div>';
    $h = '';

    $h .= $sec('About You', '', '<table class="kv"><tr><th>Patreon User ID</th><td>' . e($d['id']) . '</td></tr>'
        . '<tr><th>Name' . (count($d['names']) > 1 ? 's' : '') . ' (from Patreon)</th><td>' . ($d['names'] ? e(implode(', ', $d['names'])) : '<span class="none">None stored</span>') . '</td></tr></table>');

    $rows = '';
    foreach ($d['downloads'] as $r) {
        $rows .= '<tr><td class="nowrap">' . $t($r['at']) . '</td><td>' . e($r['item']) . '<div class="sub">' . ($r['kind'] === 'outfits' ? 'Accessory' : 'Model') . '</div></td><td>' . e($r['version']) . '</td><td>' . e($r['link'])
            . ($r['note'] === 'free' ? '<div class="sub">Not counted in the limits</div>' : ($r['note'] === 'update' ? '<div class="sub">Update unlocked again</div>' : '')) . '</td><td>' . e(pd_membership($r['membership'])) . '</td></tr>';
    }
    $h .= $sec('Downloads', 'Each download of a patrons-only link: kept to protect patron files, apply the download limits and check why a download was allowed. Entries are deleted after ' . (int) ($GLOBALS['M3D']['log_retention_days'] ?? 365) . ' days.',
        $rows ? '<div class="scroll"><table class="grid"><thead><tr><th>Date</th><th>Model or Accessory</th><th>Version</th><th>Link</th><th>Your Membership Then</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : $none);

    if ($d['exception']) {
        $kv = '';
        foreach (pd_exception_lines($d['exception']) as $k => $v) $kv .= '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>';
        $h .= $sec('Special Access', 'Access settings chosen for you by ' . site_name() . ', kept while they last.', '<table class="kv">' . $kv . '</table>');
    }

    $h .= $sec('Likes', 'Stored under a scrambled code instead of your Patreon ID; only the number of likes is shown on the site.',
        $d['likes']['models'] || $d['likes']['outfits'] || $d['likes']['stills']
            ? ($d['likes']['models'] ? '<h3>Models</h3>' . $list($d['likes']['models']) : '') . ($d['likes']['outfits'] ? '<h3>Accessories</h3>' . $list($d['likes']['outfits']) : '')
              . ($d['likes']['stills'] ? '<h3>Gallery Pictures</h3>' . $list($d['likes']['stills']) : '')
            : $none);

    $h .= $sec('Ignored Updates', 'Updates you chose to ignore, so the “Update” mark stays hidden (scrambled code, like likes).',
        $list(array_map(fn($r) => $r['item'] . ($r['version'] !== '' ? ' · ' . $r['version'] : ''), $d['ignored'])));

    $h .= $sec('Welcome Page', 'That you have seen it, so it is shown only once (scrambled code).',
        $d['welcome'] !== null ? '<p>Seen on ' . $t($d['welcome']) . '.</p>' : $none);

    if ($d['reports'] || $d['comments'] || $d['metoo'] || $d['following'] || $d['blocked']) {
        $b = '';
        foreach ($d['reports'] as $r) {
            $facts = array_filter(['About' => $r['about'], 'Version' => $r['version'], 'Blender' => $r['blender'], 'MustardUI' => $r['mustardui'], 'Shown as' => $r['shown_as']]);
            $b .= '<div class="card"><p class="card-h">#' . $r['n'] . ' ' . e($r['title']) . '<span class="sub"> · ' . $t($r['at']) . '</span></p>'
                . ($facts ? '<p class="sub">' . e(implode(' · ', array_map(fn($k, $v) => "$k: $v", array_keys($facts), $facts))) . '</p>' : '')
                . ($r['text'] !== '' ? $text($r['text']) : '') . ($r['steps'] !== '' ? '<p class="sub">Steps</p>' . $text($r['steps']) : '')
                . ($r['links'] ? '<p class="sub">Links</p>' . $list($r['links']) : '') . ($r['pictures'] ? '<p class="sub">Pictures Uploaded</p>' . $list(array_map(fn($p) => abs_url($p), $r['pictures'])) : '') . '</div>';
        }
        $h .= $sec('Bug Reports', 'Reports and comments you wrote, shown publicly with your Patreon name.', ($b !== '' ? '<h3>Your Reports</h3>' . $b : ''));
        if ($d['comments']) {
            $c = '';
            foreach ($d['comments'] as $r) $c .= '<div class="card"><p class="card-h">On #' . $r['n'] . ' ' . e($r['title']) . '<span class="sub"> · ' . $t($r['at']) . '</span></p>' . $text($r['text']) . '</div>';
            $h .= '<h3>Your Comments</h3>' . $c;
        }
        if ($d['metoo']) $h .= '<h3>Me Too</h3>' . $list(array_map(fn($r) => '#' . $r['n'] . ' ' . $r['title'], $d['metoo']));
        if ($d['following']) $h .= '<h3>Reports You Follow</h3>' . $list(array_map(fn($r) => '#' . $r['n'] . ' ' . $r['title'] . ' (last opened ' . m3d_site_time($r['opened'], 'j M Y') . ')', $d['following']));
        if ($d['blocked']) $h .= '<p>You can’t post bug reports any more (your Patreon ID is on the block list).</p>';
    }

    $h .= $sec('Not in This File', '', $list(pd_not_included()));

    $legal = m3d_legal();
    $contact = trim((string) ($legal['email'] ?? ''));
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e('Your Data · ' . site_name()) . '</title><style>'
        . 'body{font:15px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#1d1b18;background:#fff;max-width:900px;margin:0 auto;padding:32px 20px 60px}'
        . 'h1{font-size:26px;margin:0 0 6px}h2{font-size:19px;margin:34px 0 4px;padding-top:14px;border-top:1px solid #e3ded5}h3{font-size:15px;margin:18px 0 6px}'
        . '.lead{color:#57524a;margin:0 0 4px}.why,.sub{color:#6d675e;font-size:13px}.why{margin:0 0 12px}.none{color:#8d877d}'
        . 'table{border-collapse:collapse;width:100%;font-size:14px}th,td{text-align:left;vertical-align:top;padding:7px 10px 7px 0;border-bottom:1px solid #eee9e1}'
        . '.grid thead th{font-size:12px;color:#6d675e;text-transform:uppercase;letter-spacing:.04em}.kv th{width:34%;font-weight:600}.nowrap{white-space:nowrap}'
        . '.card{border:1px solid #e3ded5;border-radius:10px;padding:12px 14px;margin:0 0 12px}.card-h{font-weight:600;margin:0 0 4px}.text{white-space:normal;margin:6px 0}'
        . '.scroll{overflow-x:auto}.grid{min-width:640px}li,td,.text{overflow-wrap:anywhere}'
        . 'ul{margin:6px 0;padding-left:20px}@media print{body{padding:0}h2{break-after:avoid}.card,tr{break-inside:avoid}}'
        . '</style></head><body>'
        . '<h1>Your Data at ' . e(site_name()) . '</h1>'
        . '<p class="lead">Everything ' . e(site_name()) . ' (' . e(abs_url('/')) . ') stores about ' . e($who) . ', made on ' . $t($d['made']) . '. Times are in ' . e(str_replace('_', ' ', m3d_tz_id())) . ' time.</p>'
        . '<p class="lead">How and why this data is used: ' . e(abs_url('/privacy/')) . ($contact !== '' ? '. Questions, corrections or deletion: ' . e($contact) : '') . '.</p>'
        . $h . '</body></html>';
}

/**
 * Deletes everything pd_collect() finds for one Patreon user (Settings → Privacy, after a typed confirmation): their download log lines
 * (so their unlocks start from zero), their exception, likes, ignored updates, welcome-page mark, bug reports (with every comment on
 * them and their uploaded pictures), their comments, Me Too and follows. The all-time download counters stay (totals only), and so
 * does a bug-report block (only the ID, kept so the block still works). Copies inside backups stay until those backups go.
 * Returns [what was deleted (lines for the message), what failed].
 */
function pd_delete(string $id): array
{
    $code = m3d_like_code($id);
    $done = $fail = [];
    $d = pd_collect($id);

    if ($d['downloads']) {
        $n = m3d_log_remove_patron($id);
        if ($n) $done[] = m3d_plural($n, 'download log line'); else $fail[] = 'download log';
    }
    if ($d['exception']) {
        $people = array_values(array_filter(m3d_exceptions(true), fn($x) => (string) $x['id'] !== $id));
        if (m3d_save_exceptions($people)) $done[] = 'their access exception'; else $fail[] = 'access exception';
    }
    $likes = count($d['likes']['models']) + count($d['likes']['outfits']) + count($d['likes']['stills']);
    if ($likes) {
        $ok = m3d_json_update('likes', function ($l) use ($code) {
            $l = is_array($l) ? $l : [];
            foreach (['models', 'outfits', 'stills'] as $k) {
                foreach ((array) ($l[$k] ?? []) as $key => $codes) {
                    $left = array_values(array_filter((array) $codes, fn($c) => $c !== $code));
                    if ($left) $l[$k][$key] = $left; else unset($l[$k][$key]);
                }
            }
            return [$l, true];
        });
        if ($ok) $done[] = m3d_plural($likes, 'like'); else $fail[] = 'likes';
    }
    if ($d['ignored']) {
        $ok = m3d_json_update('ignored', function ($x) use ($code) {
            $x = is_array($x) ? $x : [];
            unset($x['ignored'][$code]);
            $x['ignored'] = (object) ($x['ignored'] ?? []);
            return [$x, true];
        });
        if ($ok) $done[] = m3d_plural(count($d['ignored']), 'ignored update'); else $fail[] = 'ignored updates';
    }
    if ($d['welcome'] !== null) {
        $ok = m3d_json_update('welcomed', function ($x) use ($code) {
            $x = is_array($x) ? $x : [];
            unset($x['seen'][$code]);
            return [$x, true];
        });
        if ($ok) $done[] = 'the welcome page mark'; else $fail[] = 'welcome page mark';
    }
    if ($d['reports'] || $d['comments'] || $d['metoo'] || $d['following']) {
        $pics = [];
        $ok = m3d_bugs_update(function ($bugs, $next) use ($id, $code, &$pics) {
            $out = [];
            foreach ($bugs as $b) {
                if ((string) ($b['by'] ?? '') === $id) { $pics = array_merge($pics, (array) ($b['pics'] ?? [])); continue; }
                $b['log'] = array_values(array_filter((array) ($b['log'] ?? []), fn($x) => !(($x['t'] ?? '') === 'c' && (string) ($x['by'] ?? '') === $id && empty($x['admin']))));
                if (isset($b['metoo'])) $b['metoo'] = array_values(array_filter((array) $b['metoo'], fn($c) => $c !== $code));
                if (isset($b['fol'][$code])) unset($b['fol'][$code]);
                $out[] = $b;
            }
            return [$out, $next, true];
        });
        if ($ok) {
            bug_delete_pics($pics);
            $done[] = implode(', ', array_filter([$d['reports'] ? m3d_plural(count($d['reports']), 'bug report') : '', $d['comments'] ? m3d_plural(count($d['comments']), 'comment') : '',
                $d['metoo'] ? m3d_plural(count($d['metoo']), 'Me Too', 'Me Too') : '', $d['following'] ? m3d_plural(count($d['following']), 'follow') : '']));
        } else $fail[] = 'bug reports';
    }
    return [$done, $fail];
}
