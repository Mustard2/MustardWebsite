<?php
// Admin → Settings → Privacy → Personal Data: everything the site stores about one Patreon user, for a GDPR access request
// (Art. 15) or a copy to take elsewhere (Art. 20). Read only: nothing is changed or logged. Included only by settings.php.
//   By Patreon ID: the download log, an access exception, bans (with their reason; the bug-report and comment blocks are bans),
//   bug reports and comments, comments on pages (models, accessories, scenes, blog posts), ideas on model / accessory / scene pages, the moderator role and the moderation log.
//   By the scrambled code (m3d_like_code() of the ID; the site holds the key, so it finds them from the ID): likes, ignored
//   updates, the welcome page, the notifications bell (data/bell/<code>.json), poll votes, idea votes, Me Too and following bug reports,
//   reactions on comments and bug reports (`rx`).
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
          'likes' => ['models' => [], 'outfits' => [], 'scenes' => [], 'stills' => [], 'posts' => []], 'ignored' => [], 'welcome' => null, 'bell' => null, 'votes' => [], 'renders' => [],
          'reports' => [], 'comments' => [], 'metoo' => [], 'following' => [], 'blocked' => false, 'page_comments' => [], 'cm_blocked' => false, 'ideas' => [], 'idea_votes' => [], 'reactions' => [], 'modlog' => [], 'moderator' => false, 'bans' => [], 'ban_reason' => '',
          'avatar' => m3d_avatar_stored($id), 'avatar_off' => (int) (m3d_avatars_data()['off'][$id] ?? 0)];   // the Patreon profile picture (app/avatars.php)
    $names = [];

    // download log: every column of their lines (patron links only: public links are only counted)
    foreach (m3d_log_rows($id) as $r) {
        if (($r[1] ?? '') !== $id || !isset($r[3]) || ($t = strtotime((string) $r[0])) === false) continue;
        [$k, $s] = m3d_log_item_parts((string) $r[3]);
        $names[] = (string) ($r[2] ?? '');
        $d['downloads'][] = ['at' => $t, 'name' => (string) ($r[2] ?? ''), 'kind' => $k, 'item' => pd_item_name($k, $s), 'link' => (string) ($r[4] ?? ''),
            'version' => (string) ($r[5] ?? ''), 'note' => in_array($r[6] ?? '', ['free', 'update'], true) ? (string) $r[6] : '', 'membership' => m3d_log_membership($r)];
    }

    if ($x = m3d_exception($id)) {
        // the exception, unless the record holds only bans (made by a Block button): those are below
        $only = m3d_ex_mode($x['tier'] ?? null) === '' && m3d_ex_mode($x['extra'] ?? null) === '' && m3d_ex_mode($x['limits'] ?? null) === '' && trim((string) ($x['note'] ?? '')) === '' && trim((string) ($x['name'] ?? '')) === '';
        if (!$only || !m3d_ex_bans($x)) $d['exception'] = array_intersect_key($x, array_flip(['name', 'note', 'tier', 'extra', 'limits', 'tierCents', 'months', 'totalCents', 'rules', 'paused', 'added']));
        $d['bans'] = m3d_bans($id);
        $d['ban_reason'] = $d['bans'] ? trim((string) ($x['reason'] ?? '')) : '';
    }

    foreach (M3D_ITEM_KINDS as $k) foreach (m3d_likes($k) as $slug => $codes) if (in_array($code, (array) $codes, true)) $d['likes'][$k][] = pd_item_name($k, (string) $slug);
    foreach (m3d_likes('stills') as $sid => $codes) if (in_array($code, (array) $codes, true)) {
        $s = m3d_still((string) $sid);
        $d['likes']['stills'][] = $s ? m3d_still_name($s) . ' (' . $s['src'] . ')' : 'Picture ' . $sid;
    }
    foreach (m3d_likes('posts') as $pid => $codes) if (in_array($code, (array) $codes, true)) {
        $p = m3d_post((string) $pid);
        $d['likes']['posts'][] = $p ? $p['title'] . ' (' . m3d_post_url($p) . ')' : 'Post ' . $pid;
    }

    foreach ((array) ((m3d_read_json('ignored') ?? [])['ignored'][$code] ?? []) as $key => $ver) {
        [$k, $s] = explode(':', (string) $key, 2) + [1 => ''];
        $d['ignored'][] = ['kind' => $k, 'item' => isset(M3D_KINDS[$k]) ? pd_item_name($k, $s) : (string) $key, 'version' => (string) $ver];
    }

    // poll votes on blog posts: the post and the choices picked (an option removed since shows by its id)
    foreach ((array) ((m3d_read_json('polls') ?? [])['votes'] ?? []) as $pid => $codes) {
        if (!isset(((array) $codes)[$code])) continue;
        $p = m3d_post((string) $pid);
        $names = array_column((array) ($p['poll']['opts'] ?? []), 'text', 'id');
        $d['votes'][] = ['post' => $p ? $p['title'] . ' (' . m3d_post_url($p) . ')' : 'Post ' . $pid, 'question' => (string) ($p['poll']['q'] ?? ''),
            'choices' => array_map(fn($o) => (string) ($names[$o] ?? 'Choice ' . $o), (array) $codes[$code])];
    }

    // renders shared from model / accessory pages (app/showcase.php): the picture, caption, name, and where it shows
    $inGallery = array_flip(m3d_render_stills());
    foreach (m3d_renders(true) as $r) if ((string) ($r['by'] ?? '') === $id) {
        $names[] = (string) ($r['name'] ?? '');
        $d['renders'][] = ['at' => (int) ($r['at'] ?? 0), 'kind' => $r['kind'], 'item' => pd_item_name($r['kind'], $r['slug']), 'picture' => abs_url($r['src']),
            'caption' => (string) ($r['caption'] ?? ''), 'shown_as' => m3d_render_by($r), 'link' => m3d_render_link($r)['url'] ?? '', 'full' => (string) ($r['full'] ?? ''), 'approved' => !empty($r['ok']), 'in_gallery' => isset($inGallery[$r['id']])];
    }

    $seen = (m3d_read_json('welcomed') ?? [])['seen'][$code] ?? null;
    if ($seen !== null) $d['welcome'] = (int) $seen;
    $d['bell'] = m3d_ubell_person($id);   // the visitors' bell: how many rows they opened and removed

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
        foreach ((array) ($b['rx'] ?? []) as $r => $codes) if (in_array($code, (array) $codes, true)) $d['reactions'][] = ['on' => "Bug report #$n $title", 'emoji' => (string) $r];
        foreach ((array) ($b['log'] ?? []) as $x) foreach ((array) ($x['rx'] ?? []) as $r => $codes) {
            if (in_array($code, (array) $codes, true)) $d['reactions'][] = ['on' => ($x['name'] ?? '') . "’s comment on bug report #$n $title", 'emoji' => (string) $r];
        }
        if (isset(((array) ($b['fol'] ?? []))[$code])) $d['following'][] = ['n' => $n, 'title' => $title, 'opened' => (int) $b['fol'][$code]];
    }
    $d['blocked'] = in_array($id, array_map('strval', m3d_bug_settings()['blocked']), true);

    // comments under model, accessory and blog post pages (app/comments.php), oldest first; the creator's own aren't personal data asked for
    foreach (array_reverse(m3d_comments_flat()) as [$k, $key, $c]) if ((string) ($c['by'] ?? '') === $id && empty($c['admin'])) {
        $names[] = (string) ($c['name'] ?? '');
        [$title, $url] = m3d_comment_page($k, $key);
        $d['page_comments'][] = ['kind' => $k, 'page' => $title, 'url' => $url !== '' ? abs_url($url) : '', 'at' => (int) ($c['at'] ?? 0),
            'text' => (string) ($c['text'] ?? ''), 'shown_as' => (string) ($c['name'] ?? ''), 'pending' => !empty($c['pending'])];
    }
    $d['cm_blocked'] = in_array($id, m3d_comment_settings()['blocked'], true);
    foreach (array_reverse(m3d_comments_flat()) as [$k, $key, $c]) foreach ((array) ($c['rx'] ?? []) as $r => $codes) if (in_array($code, (array) $codes, true)) {
        $d['reactions'][] = ['on' => (($c['name'] ?? '') ?: 'Someone') . '’s comment on ' . m3d_comment_page($k, $key)[0], 'emoji' => (string) $r];
    }

    // ideas on model and accessory pages (app/ideas.php): the ones they suggested, and their votes (stored under the scrambled code)
    foreach (array_reverse(m3d_ideas_flat()) as [$k, $slug, $i]) {
        [$title, $url] = m3d_idea_page($k, $slug);
        if ((string) ($i['by'] ?? '') === $id && empty($i['admin'])) {
            $names[] = (string) ($i['name'] ?? '');
            $d['ideas'][] = ['kind' => $k, 'item' => $title, 'url' => $url !== '' ? abs_url($url) : '', 'at' => (int) ($i['at'] ?? 0), 'title' => (string) ($i['title'] ?? ''),
                'text' => (string) ($i['text'] ?? ''), 'shown_as' => (string) ($i['name'] ?? ''), 'status' => IDEA_STATUSES[m3d_idea_status($i)], 'pending' => !empty($i['pending'])];
        } elseif (in_array($code, (array) ($i['votes'] ?? []), true)) {
            $d['idea_votes'][] = ['item' => $title, 'title' => (string) ($i['title'] ?? '')];
        }
    }

    // moderation (app/moderation.php): whether they are a moderator, and the log's steps about their posts or bans, or taken by them
    $d['moderator'] = in_array($id, array_column(m3d_mod_settings()['mods'], 'id'), true);
    foreach (array_reverse(m3d_modlog_of($id)) as $e) {
        $d['modlog'][] = ['at' => (int) ($e['at'] ?? 0), 'by_them' => (string) ($e['by'] ?? '') === $id, 'what' => mod_act_words($e) . ((string) ($e['what'] ?? '') !== '' ? ': ' . $e['what'] : ''),
            'by' => (string) ($e['name'] ?? ''), 'why' => (string) ($e['why'] ?? '')];
    }

    $names = array_values(array_unique(array_filter(array_map('trim', $names), fn($s) => $s !== '' && $s !== $id)));
    $d['names'] = $names;   // as Patreon sent them (the exception's name is the site's own, in its section)
    $d['name'] = $d['downloads'] ? (string) end($d['downloads'])['name'] : ($names[0] ?? (string) ($d['exception']['name'] ?? ''));
    return $d;
}

/** Whether anything was found ($block false: anything Delete removes; bans stay). */
function pd_found(array $d, bool $block = true): bool
{
    return $d['downloads'] || $d['exception'] || array_filter($d['likes']) || $d['ignored'] || $d['welcome'] !== null || $d['bell'] !== null || $d['votes'] || $d['renders']
        || $d['reports'] || $d['comments'] || $d['metoo'] || $d['following'] || $d['page_comments'] || $d['ideas'] || $d['idea_votes'] || $d['reactions'] || $d['modlog'] || $d['moderator'] || ($block && ($d['bans'] || $d['blocked'] || $d['cm_blocked']));
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
    if (!empty($x['paused'])) $out['Switched Off'] = 'Yes: kept, but not applied';
    if (($x['added'] ?? '') !== '') $out['Added'] = m3d_site_time((string) $x['added'], 'j M Y');
    return array_filter($out, fn($v) => $v !== '');
}

/** The membership saved with a download, in words ("19 months as a patron (since 3 Mar 2025) · €10 tier · €190 total support"). */
function pd_membership(?array $m): string
{
    if (!$m) return '';
    $p = [];
    if ($m['months'] !== null) $p[] = m3d_plural($m['months'], 'month') . ' as a patron' . ($m['since'] !== '' ? ' (since ' . m3d_day_text($m['since']) . ')' : '');
    elseif ($m['since'] !== '') $p[] = 'patron since ' . m3d_day_text($m['since']);
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
    $likes = array_filter([count($l['models']) ? m3d_plural(count($l['models']), 'model') : '', count($l['outfits']) ? m3d_plural(count($l['outfits']), 'accessory', 'accessories') : '', count($l['scenes']) ? m3d_plural(count($l['scenes']), 'scene') : '',
        count($l['stills']) ? m3d_plural(count($l['stills']), 'gallery picture') : '', count($l['posts']) ? m3d_plural(count($l['posts']), 'blog post') : '']);
    $bugs = array_filter([$d['reports'] ? m3d_plural(count($d['reports']), 'report') : '', $d['comments'] ? m3d_plural(count($d['comments']), 'comment') : '',
        $d['metoo'] ? 'Me Too on ' . count($d['metoo']) : '', $d['following'] ? 'following ' . count($d['following']) : '']);
    return [
        'Download Log' => $dl ? m3d_plural(count($dl), 'download') . ' of ' . $items . ' ' . ($items === 1 ? 'item' : 'items')
            . ' (' . m3d_site_time($dl[0]['at'], 'j M Y') . ' – ' . m3d_site_time(end($dl)['at'], 'j M Y') . ')' : '',
        'Profile Picture' => $d['avatar'] !== '' ? 'Stored' : ($d['avatar_off'] ? 'Turned off by them on ' . m3d_site_time($d['avatar_off'], 'j M Y') : ''),
        'Likes' => implode(', ', $likes),
        'Ignored Updates' => $d['ignored'] ? (string) count($d['ignored']) : '',
        'Welcome Page' => $d['welcome'] !== null ? 'Seen ' . m3d_site_time($d['welcome'], 'j M Y') : '',
        'Notifications' => $d['bell'] !== null ? m3d_plural($d['bell']['read'], 'notification') . ' opened, ' . $d['bell']['removed'] . ' removed'
            . ($d['bell']['off'] ? ', ' . count($d['bell']['off']) . ' switched off' : '') : '',
        'Bookmarks' => $d['bell'] !== null && ($d['bell']['bookmarks'] || $d['bell']['unbookmarked']) ? m3d_plural(count($d['bell']['bookmarks']), 'bookmark')
            . ($d['bell']['unbookmarked'] ? ', ' . count($d['bell']['unbookmarked']) . ' download' . (count($d['bell']['unbookmarked']) === 1 ? '' : 's') . ' un-bookmarked' : '') : '',
        'Render Defaults' => $d['bell'] !== null && $d['bell']['render'] !== null ? ($d['bell']['render']['named'] ? 'Name shown' . ($d['bell']['render']['link'] !== '' ? ' · ' . $d['bell']['render']['link'] : '') : 'Name hidden') : '',
        'Poll Votes' => $d['votes'] ? m3d_plural(count($d['votes']), 'poll') : '',
        'Renders Shared' => $d['renders'] ? m3d_plural(count($d['renders']), 'render') . (($w = count(array_filter($d['renders'], fn($r) => !$r['approved']))) ? " ($w waiting)" : '') : '',
        'Bug Reports' => implode(', ', $bugs),
        'Comments' => $d['page_comments'] ? m3d_plural(count($d['page_comments']), 'comment') . ' on models, accessories, scenes and blog posts' : '',
        'Ideas' => implode(', ', array_filter([$d['ideas'] ? m3d_plural(count($d['ideas']), 'idea') . ' suggested' : '', $d['idea_votes'] ? m3d_plural(count($d['idea_votes']), 'vote') . ' on other ideas' : ''])),
        'Reactions' => $d['reactions'] ? m3d_plural(count($d['reactions']), 'reaction') . ' on comments and bug reports' : '',
        'Moderation' => implode(', ', array_filter([$d['moderator'] ? 'A moderator' : '', $d['modlog'] ? m3d_plural(count($d['modlog']), 'step') . ' in the moderation log' : ''])),
        'Access Exception' => $d['exception'] ? 'Yes' : '',
        'Banned From' => implode(', ', array_map(fn($k) => BAN_KINDS[$k][0], $d['bans'])) . ($d['ban_reason'] !== '' ? ' (reason: ' . $d['ban_reason'] . ')' : ''),
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
        'profile_picture' => ['stored' => $d['avatar'] !== '', 'picture' => $d['avatar'] ?: null, 'turned_off' => $iso($d['avatar_off'] ?: null)],
        'download_log' => array_map(fn($r) => ['date' => $iso($r['at']), 'name' => $r['name'], 'kind' => m3d_kind_word($r['kind']), 'item' => $r['item'],
            'version' => $r['version'], 'link' => $r['link'],
            'note' => ['free' => 'not counted in the download limits', 'update' => 'an update unlocked again'][$r['note']] ?? '',
            'membership' => $r['membership'] ? ['months_as_patron' => $r['membership']['months'], 'patron_since' => $r['membership']['since'] ?: null,
                'tier_cents' => $r['membership']['tier_cents'], 'total_support_cents' => $r['membership']['total_cents'], 'currency' => m3d_currency()] : null], $d['downloads']),
        'access_exception' => $d['exception'] ? pd_exception_lines($d['exception']) : null,
        'likes' => ['models' => $d['likes']['models'], 'accessories' => $d['likes']['outfits'], 'scenes' => $d['likes']['scenes'], 'gallery_pictures' => $d['likes']['stills'], 'blog_posts' => $d['likes']['posts']],
        'ignored_updates' => array_map(fn($r) => ['item' => $r['item'], 'version' => $r['version']], $d['ignored']),
        'welcome_page_seen' => $iso($d['welcome']),
        'notifications_bell' => $d['bell'] !== null ? ['notifications_opened' => $d['bell']['read'], 'notifications_removed' => $d['bell']['removed'], 'switched_off' => $d['bell']['off'],
            'bookmarks' => $d['bell']['bookmarks'], 'downloads_unbookmarked' => $d['bell']['unbookmarked']] : null,
        'render_share_defaults' => $d['bell']['render'] ?? null,
        'poll_votes' => array_map(fn($r) => ['post' => $r['post'], 'question' => $r['question'], 'choices' => $r['choices']], $d['votes']),
        'renders_shared' => array_map(fn($r) => ['date' => $iso($r['at']), 'on' => m3d_kind_word($r['kind']), 'item' => $r['item'], 'picture' => $r['picture'],
            'caption' => $r['caption'], 'shown_as' => $r['shown_as'], 'profile_link' => $r['link'], 'full_quality_link' => $r['full'], 'approved' => $r['approved'], 'in_gallery' => $r['in_gallery']], $d['renders']),
        'bug_reports' => array_map(fn($r) => ['number' => $r['n'], 'date' => $iso($r['at'])] + array_diff_key($r, ['n' => 1, 'at' => 1]), $d['reports']),
        'bug_comments' => array_map(fn($r) => ['report' => $r['n'], 'report_title' => $r['title'], 'date' => $iso($r['at']), 'text' => $r['text'], 'shown_as' => $r['shown_as']], $d['comments']),
        'bug_me_too' => array_map(fn($r) => ['report' => $r['n'], 'report_title' => $r['title']], $d['metoo']),
        'bug_following' => array_map(fn($r) => ['report' => $r['n'], 'report_title' => $r['title'], 'last_opened' => $iso($r['opened'])], $d['following']),
        'banned_from' => $d['bans'] ? ['what' => array_map(fn($k) => BAN_KINDS[$k][0], $d['bans']), 'reason' => $d['ban_reason']] : null,
        'comments' => array_map(fn($r) => ['date' => $iso($r['at']), 'on' => ['models' => 'model', 'outfits' => 'accessory', 'scenes' => 'scene', 'posts' => 'blog post'][$r['kind']] ?? $r['kind'],
            'page' => $r['page'], 'address' => $r['url'], 'text' => $r['text'], 'shown_as' => $r['shown_as'], 'waiting_for_approval' => $r['pending']], $d['page_comments']),
        'ideas' => array_map(fn($r) => ['date' => $iso($r['at']), 'on' => m3d_kind_word($r['kind']), 'item' => $r['item'], 'address' => $r['url'],
            'idea' => $r['title'], 'details' => $r['text'], 'shown_as' => $r['shown_as'], 'status' => $r['status'], 'waiting_for_approval' => $r['pending']], $d['ideas']),
        'idea_votes' => array_map(fn($r) => ['item' => $r['item'], 'idea' => $r['title']], $d['idea_votes']),
        'reactions' => $d['reactions'],
        'moderator' => $d['moderator'],
        'moderation_log' => array_map(fn($r) => ['date' => $iso($r['at']), 'step' => $r['what'], 'by' => $r['by_them'] ? 'you, as a moderator' : $r['by'], 'reason' => $r['why']], $d['modlog']),
        'not_included' => pd_not_included(),
    ];
}

/** What the file can't contain, for the last section. */
function pd_not_included(): array
{
    return [
        'While you are logged in, a session file on the server holds what Patreon sent at login (shown on My account); it is deleted when you log out or the login expires.',
        'The web host and the delivery and protection service keep short-term server logs with IP addresses; the website itself stores no IP addresses.',
        'Backups of the website contain copies of the data above; they are kept to restore the site after a failure.',
    ] + (($bugDc = notify_bug_discord_words()) !== '' ? [3 => ucfirst($bugDc) . ' are also sent to a private Discord channel of ' . site_name() . ' (title, text and your Patreon name).'] : [])
      + (m3d_notify_discord_on('comments') ? [4 => 'New comments are also sent to a private Discord channel of ' . site_name() . ' (the text and your Patreon name).'] : [])
      + (m3d_notify_discord_on('ideas') ? [5 => 'New ideas are also sent to a private Discord channel of ' . site_name() . ' (the idea and your Patreon name).'] : []);
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
        . '<tr><th>Name' . (count($d['names']) > 1 ? 's' : '') . ' (from Patreon)</th><td>' . ($d['names'] ? e(implode(', ', $d['names'])) : '<span class="none">None stored</span>') . '</td></tr>'
        . '<tr><th>Profile Picture (from Patreon)</th><td>' . ($d['avatar'] !== '' ? '<img src="' . e($d['avatar']) . '" alt="Your stored profile picture" width="' . AVATAR_SIZE . '" height="' . AVATAR_SIZE . '" style="border-radius:50%"><div class="sub">A ' . AVATAR_SIZE . ' px copy, shown next to your comments and bug reports.</div>'
            : ($d['avatar_off'] ? '<span class="none">None: you turned it off on ' . $t($d['avatar_off']) . '</span>' : '<span class="none">None stored</span>')) . '</td></tr></table>');

    $rows = '';
    foreach ($d['downloads'] as $r) {
        $rows .= '<tr><td class="nowrap">' . $t($r['at']) . '</td><td>' . e($r['item']) . '<div class="sub">' . (m3d_kind_word($r['kind'], false, true)) . '</div></td><td>' . e($r['version']) . '</td><td>' . e($r['link'])
            . ($r['note'] === 'free' ? '<div class="sub">Not counted in the limits</div>' : ($r['note'] === 'update' ? '<div class="sub">Update unlocked again</div>' : '')) . '</td><td>' . e(pd_membership($r['membership'])) . '</td></tr>';
    }
    $h .= $sec('Downloads', 'Each download of a patrons-only link: kept to protect patron files, apply the download limits and check why a download was allowed. Entries are deleted after ' . (int) ($GLOBALS['M3D']['log_retention_days'] ?? 365) . ' days.',
        $rows ? '<div class="scroll"><table class="grid"><thead><tr><th>Date</th><th>Model or Accessory</th><th>Version</th><th>Link</th><th>Your Membership Then</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : $none);

    if ($d['exception']) {
        $kv = '';
        foreach (pd_exception_lines($d['exception']) as $k => $v) $kv .= '<tr><th>' . e($k) . '</th><td>' . e($v) . '</td></tr>';
        $h .= $sec('Special Access', 'Access settings chosen for you by ' . site_name() . ', kept while they last.', '<table class="kv">' . $kv . '</table>');
    }
    if ($d['bans']) {
        $h .= $sec('Bans', 'What ' . site_name() . ' turned off for your Patreon account, with the reason shown to you. Kept while the ban lasts.',
            '<table class="kv"><tr><th>Banned From</th><td>' . e(implode(', ', array_map(fn($k) => BAN_KINDS[$k][0], $d['bans']))) . '</td></tr>'
            . ($d['ban_reason'] !== '' ? '<tr><th>Reason</th><td>' . e($d['ban_reason']) . '</td></tr>' : '') . '</table>');
    }

    $h .= $sec('Likes', 'Stored under a scrambled code instead of your Patreon ID; only the number of likes is shown on the site.',
        $d['likes']['models'] || $d['likes']['outfits'] || $d['likes']['scenes'] || $d['likes']['stills'] || $d['likes']['posts']
            ? ($d['likes']['models'] ? '<h3>Models</h3>' . $list($d['likes']['models']) : '') . ($d['likes']['outfits'] ? '<h3>Accessories</h3>' . $list($d['likes']['outfits']) : '')
              . ($d['likes']['scenes'] ? '<h3>Scenes</h3>' . $list($d['likes']['scenes']) : '')
              . ($d['likes']['stills'] ? '<h3>Gallery Pictures</h3>' . $list($d['likes']['stills']) : '')
              . ($d['likes']['posts'] ? '<h3>Blog Posts</h3>' . $list($d['likes']['posts']) : '')
            : $none);

    $h .= $sec('Ignored Updates', 'Updates you chose to ignore, so the “Update” mark stays hidden (scrambled code, like likes).',
        $list(array_map(fn($r) => $r['item'] . ($r['version'] !== '' ? ' · ' . $r['version'] : ''), $d['ignored'])));

    $h .= $sec('Notifications', 'Which notifications of the bell you opened (so its number shows only the ones you haven’t), which you removed, which kinds you switched off on your Settings page, and what you bookmarked (scrambled code, like likes).',
        $d['bell'] !== null ? $list(['Notifications opened: ' . $d['bell']['read'], 'Notifications removed: ' . $d['bell']['removed'],
            'Switched off: ' . ($d['bell']['off'] ? implode(', ', $d['bell']['off']) : 'none'),
            'Bookmarked: ' . ($d['bell']['bookmarks'] ? implode(', ', $d['bell']['bookmarks']) : 'none'),
            'Downloads un-bookmarked: ' . ($d['bell']['unbookmarked'] ? implode(', ', $d['bell']['unbookmarked']) : 'none')]) : $none);
    if (($d['bell']['render'] ?? null) !== null) $h .= $sec('Render Defaults', 'What the Share window of patron renders starts with, from your Settings page.',
        $list(['Show my name: ' . ($d['bell']['render']['named'] ? 'yes' : 'no'), 'Link on my name: ' . ($d['bell']['render']['link'] !== '' ? $d['bell']['render']['link'] : 'none')]));

    $h .= $sec('Poll Votes', 'Your choices in polls under blog posts, so each patron votes once (scrambled code, like likes); only the totals are shown.',
        $list(array_map(fn($r) => $r['post'] . ($r['question'] !== '' ? ': ' . $r['question'] : '') . ' → ' . implode(', ', $r['choices']), $d['votes'])));

    $h .= $sec('Renders Shared', 'Pictures shared from model and accessory pages, with the caption and the name shown under them.',
        $list(array_map(fn($r) => m3d_site_time($r['at'], 'j M Y, H:i T') . ' · ' . $r['item'] . ' · ' . $r['picture'] . ($r['caption'] !== '' ? ' · “' . $r['caption'] . '”' : '') . ' · shown as ' . $r['shown_as'] . ($r['link'] !== '' ? ' (linked to ' . $r['link'] . ')' : '') . ($r['full'] !== '' ? ' · full quality: ' . $r['full'] : '')
            . ($r['approved'] ? ($r['in_gallery'] ? ' · on the page and in the Gallery' : ' · on the page') : ' · waiting for approval'), $d['renders'])));

    $h .= $sec('Welcome Page', 'That you have seen it, so it is shown only once (scrambled code).',
        $d['welcome'] !== null ? '<p>Seen on ' . $t($d['welcome']) . '.</p>' : $none);

    if ($d['reports'] || $d['comments'] || $d['metoo'] || $d['following'] || $d['blocked']) {
        $b = '';
        foreach ($d['reports'] as $r) {
            $facts = array_filter(['About' => $r['about'], 'Version' => $r['version'], 'Blender' => $r['blender'], (m3d_bug_tool()['name'] ?? 'Add-on') => $r['mustardui'], 'Shown as' => $r['shown_as']]);
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
        if ($d['blocked']) $h .= '<p>You can’t post bug reports any more (see Bans).</p>';
    }

    if ($d['page_comments'] || $d['cm_blocked']) {
        $c = '';
        $what = ['models' => 'Model', 'outfits' => 'Accessory', 'scenes' => 'Scene', 'posts' => 'Blog post'];
        foreach ($d['page_comments'] as $r) {
            $c .= '<div class="card"><p class="card-h">On ' . e($r['page']) . '<span class="sub"> · ' . e($what[$r['kind']] ?? '') . ' · ' . $t($r['at']) . ($r['pending'] ? ' · waiting for approval' : '') . '</span></p>'
                . ($r['url'] !== '' ? '<p class="sub">' . e($r['url']) . '</p>' : '') . $text($r['text']) . '</div>';
        }
        $h .= $sec('Comments', 'Comments you wrote under models, accessories, scenes and blog posts, shown publicly with your Patreon name.', $c !== '' ? $c : $none);
        if ($d['cm_blocked']) $h .= '<p>You can’t comment any more (see Bans).</p>';
    }

    if ($d['ideas'] || $d['idea_votes']) {
        $c = '';
        foreach ($d['ideas'] as $r) {
            $c .= '<div class="card"><p class="card-h">' . e($r['title']) . '<span class="sub"> · on ' . e($r['item']) . ' · ' . $t($r['at']) . ' · ' . e($r['status']) . ($r['pending'] ? ' · waiting for approval' : '') . '</span></p>'
                . ($r['url'] !== '' ? '<p class="sub">' . e($r['url']) . '</p>' : '') . ($r['text'] !== '' ? $text($r['text']) : '') . '</div>';
        }
        if ($d['idea_votes']) $c .= '<h3>Your Votes</h3>' . $list(array_map(fn($r) => $r['title'] . ' (' . $r['item'] . ')', $d['idea_votes']));
        $h .= $sec('Ideas', 'Ideas you suggested on model, accessory and scene pages, shown publicly with your Patreon name, and the ideas you voted for (votes are stored under a scrambled code, never with your name).', $c);
    }

    if ($d['reactions']) {
        $h .= $sec('Reactions', 'Reactions you gave to comments and bug reports, stored under a scrambled code, never with your name; only the numbers are shown.',
            $list(array_map(fn($r) => $r['emoji'] . ' on ' . $r['on'], $d['reactions'])));
    }

    if ($d['moderator'] || $d['modlog']) {
        $c = $d['moderator'] ? '<p>You are a moderator of ' . e(site_name()) . ': your Patreon user ID and name are stored for that.</p>' : '';
        if ($d['modlog']) $c .= $list(array_map(fn($r) => m3d_site_time($r['at'], 'j M Y, H:i T') . ' · ' . $r['what'] . ' · by ' . ($r['by_them'] ? 'you' : ($r['by'] ?: 'a moderator')) . ($r['why'] !== '' ? ' · reason: ' . $r['why'] : ''), $d['modlog']));
        $h .= $sec('Moderation', 'Steps moderators took about your posts or your account (publishing, hiding, banning), and the ones you took as a moderator.', $c);
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
 * (so their unlocks start from zero), their profile picture, their exception, likes, ignored updates, welcome-page mark, poll votes, shared renders (with their pictures), bug reports (with every comment on
 * them and their uploaded pictures), their comments, Me Too and follows, their comments under models, accessories, scenes and blog posts, their ideas and idea votes,
 * and their reactions on comments and bug reports.
 * The all-time download counters stay (totals only), and so do their bans (the ID, what is banned and the reason, kept so the ban still works). Copies inside backups stay until those backups go.
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
    if ($d['avatar'] !== '' || $d['avatar_off']) {
        m3d_avatar_delete($id, true);
        $done[] = 'their profile picture';
    }
    if ($d['exception']) {   // a banned person keeps a record with only the ID, the bans and their reason
        $people = [];
        foreach (m3d_exceptions(true) as $x) {
            if ((string) $x['id'] !== $id) $people[] = $x;
            elseif ($b = m3d_ex_bans($x)) $people[] = ['id' => $id, 'name' => '', 'note' => '', 'ban' => $b] + (($x['reason'] ?? '') !== '' ? ['reason' => (string) $x['reason']] : []) + ['added' => (string) ($x['added'] ?? gmdate('c'))];
        }
        if (m3d_save_exceptions($people)) $done[] = 'their access exception'; else $fail[] = 'access exception';
    }
    $likes = count($d['likes']['models']) + count($d['likes']['outfits']) + count($d['likes']['scenes']) + count($d['likes']['stills']) + count($d['likes']['posts']);
    if ($likes) {
        $ok = m3d_json_update('likes', function ($l) use ($code) {
            $l = is_array($l) ? $l : [];
            foreach (['models', 'outfits', 'scenes', 'stills', 'posts'] as $k) {
                foreach ((array) ($l[$k] ?? []) as $key => $codes) {
                    $left = array_values(array_filter((array) $codes, fn($c) => $c !== $code));
                    if ($left) $l[$k][$key] = $left; else unset($l[$k][$key]);
                }
            }
            return [$l, true];
        });
        if ($ok) { m3d_likes('models', true); $done[] = m3d_plural($likes, 'like'); } else $fail[] = 'likes';   // the page shown next reads them again
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
    if ($d['votes']) {
        $ok = m3d_json_update('polls', function ($x) use ($code) {
            $x = is_array($x) ? $x : [];
            foreach ((array) ($x['votes'] ?? []) as $pid => $codes) {
                unset($codes[$code]);
                if ($codes) $x['votes'][$pid] = $codes; else unset($x['votes'][$pid]);
            }
            $x['votes'] = (object) ($x['votes'] ?? []);
            return [$x, true];
        });
        if ($ok) $done[] = m3d_plural(count($d['votes']), 'poll vote'); else $fail[] = 'poll votes';
    }
    if ($d['renders']) {
        $n = m3d_renders_delete(fn($r) => (string) ($r['by'] ?? '') === $id);   // with their pictures and their stills in the Gallery
        if ($n !== null) $done[] = m3d_plural($n, 'shared render'); else $fail[] = 'shared renders';
    }
    if ($d['bell'] !== null) {
        if (m3d_ubell_forget($id)) $done[] = 'the notifications bell marks'; else $fail[] = 'notifications bell marks';
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
    if ($d['page_comments']) {
        $ok = m3d_comments_update(function (array $all) use ($id) {
            $n = 0;
            foreach (array_keys(COMMENT_KINDS) as $k) foreach ($all[$k] as $key => $list) {
                $left = array_values(array_filter((array) $list, fn($c) => !(is_array($c) && (string) ($c['by'] ?? '') === $id && empty($c['admin']))));
                $n += count((array) $list) - count($left);
                $all[$k][$key] = $left;
            }
            return [$all, $n];
        });
        if ($ok !== null) $done[] = m3d_plural((int) $ok, 'comment') . ' on models, accessories and blog posts'; else $fail[] = 'comments';
    }
    if ($d['ideas'] || $d['idea_votes']) {
        $r = m3d_ideas_forget_person($id);
        if ($r !== null) $done[] = implode(', ', array_filter([$r[0] ? m3d_plural($r[0], 'idea') : '', $r[1] ? m3d_plural($r[1], 'idea vote') : ''])); else $fail[] = 'ideas';
    }
    if ($d['reactions']) {   // their reactions on comments and bug reports (stored under the scrambled code)
        $strip = function (array $x) use ($code): array {
            if (empty($x['rx'])) return $x;
            $rx = array_filter(array_map(fn($v) => array_values(array_diff((array) $v, [$code])), (array) $x['rx']));
            if ($rx) $x['rx'] = $rx; else unset($x['rx']);
            return $x;
        };
        $okC = m3d_comments_update(function (array $all) use ($strip) {
            foreach (array_keys(COMMENT_KINDS) as $k) foreach ($all[$k] as $key => $list) $all[$k][$key] = array_map(fn($c) => is_array($c) ? $strip($c) : $c, (array) $list);
            return [$all, true];
        });
        $okB = m3d_bugs_update(function ($bugs, $next) use ($strip) {
            foreach ($bugs as &$b) { $b = $strip($b); $b['log'] = array_map(fn($x) => is_array($x) ? $strip($x) : $x, (array) ($b['log'] ?? [])); }
            unset($b);
            return [$bugs, $next, true];
        });
        if ($okC !== null && $okB) $done[] = m3d_plural(count($d['reactions']), 'reaction'); else $fail[] = 'reactions';
    }
    if ($d['modlog']) {
        $r = m3d_modlog_forget($id);
        if ($r !== null) $done[] = m3d_plural($r, 'moderation log step'); else $fail[] = 'moderation log';
    }
    if ($d['moderator'] || isset(m3d_settings()['moderation']['bell'][$id])) {   // the role, and its row in their bell
        $ok = m3d_mod_settings_save(['mods' => array_values(array_filter(m3d_mod_settings()['mods'], fn($m) => $m['id'] !== $id)), 'bell' => array_diff_key((array) (m3d_settings()['moderation']['bell'] ?? []), [$id => 1])]);
        if ($ok) $done[] = 'moderator role'; else $fail[] = 'moderator role';
    }
    return [$done, $fail];
}
