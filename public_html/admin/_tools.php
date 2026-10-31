<?php
// Admin → Assets → Tools (app/tools.php): the checks of the tool editor (admin/tool.php) and of its tutorials (admin/tool-tutorials.php, save.php
// kind "tool-tuts"). The editor saves everything but the tutorials, the tutorials editor only `groups`: each with its own revision.
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

/** A picture of a tool or tutorial: a file of this site (/media/… or /assets/…) or https; '' = none. */
function clean_tool_pic(mixed $v, string $what): string
{
    $u = clean_line($v, 1000);
    if ($u === '' || preg_match('#^/(media|assets)/[A-Za-z0-9/_.-]+$#D', $u) || preg_match('#^https://\S+$#iD', $u)) return $u;
    throw new InvalidArgumentException("The $what must be a picture of this site (/media/…) or an https address.");
}

/** The md5 of the stored tool without its tutorials: the editor's revision ('' for a new one). */
function admin_tool_rev(?array $t): string
{
    if (!$t) return '';
    unset($t['groups']);
    return md5((string) json_encode($t, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/** The md5 of a tool's tutorials (the tutorials editor's revision). */
function admin_tuts_rev(array $t): string
{
    return md5((string) json_encode($t['groups'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * The page from the editor's form ($_POST), keeping $old's id and tutorials; $all = every stored page (unique addresses per type). A simple page
 * (`type` page) keeps only its name, address, text (`intro`), draft and tutorials: a tool's header, features, help links and options are dropped.
 */
function clean_tool(array $in, ?array $old, array $all): array
{
    $type = ($in['type'] ?? '') === 'page' ? 'page' : 'tool';
    $name = clean_line($in['name'] ?? '', 60);
    if ($name === '') throw new InvalidArgumentException($type === 'page' ? 'The page needs a title.' : 'The tool needs a name.');
    $slug = strtolower(clean_line($in['slug'] ?? '', 40));
    if ($slug === '') $slug = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', m3d_fold($name)), '-'), 0, 40) ?: 'tool';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/D', $slug)) throw new InvalidArgumentException('The address may only contain lowercase letters, numbers and dashes.');
    foreach ($all as $t) if (($t['slug'] ?? '') === $slug && m3d_page_type($t) === $type && ($t['id'] ?? '') !== ($old['id'] ?? ''))
        throw new InvalidArgumentException('Another page uses the address "' . substr(m3d_tool_url(['type' => $type, 'slug' => $slug]), 0, -1) . '/": ' . $t['name'] . '.');
    $id = (string) ($old['id'] ?? bin2hex(random_bytes(4)));
    if ($type === 'page') {
        $page = ['id' => $id, 'type' => 'page', 'slug' => $slug, 'name' => $name, 'intro' => clean_text($in['intro'] ?? '', 100000), 'groups' => (array) ($old['groups'] ?? [])];
        if (($in['vis'] ?? '') === 'draft') $page['draft'] = true;
        return $page;
    }
    $rows = function (string $p, array $fields, int $max) use ($in): array {
        $out = [];
        foreach ((array) ($in[$p . '_' . array_key_first($fields)] ?? []) as $i => $_) {
            $row = [];
            foreach ($fields as $f => $len) $row[$f] = clean_line($in[$p . '_' . $f][$i] ?? '', $len);
            if (implode('', $row) === '') continue;
            $out[] = $row;
        }
        if (count($out) > $max) throw new InvalidArgumentException("$max rows at most.");
        return $out;
    };
    $buttons = [];
    foreach ($rows('bt', ['label' => 40, 'url' => 500], 4) as $b) {
        if ($b['label'] === '') throw new InvalidArgumentException('Every button needs a text.');
        $buttons[] = ['label' => $b['label'], 'url' => admin_link_url($b['label'], $b['url'])];
    }
    $features = [];
    foreach ($rows('ft', ['title' => 40, 'text' => 200], 12) as $f) {
        if ($f['title'] === '') throw new InvalidArgumentException('Every feature needs a title.');
        $features[] = $f;
    }
    $help = [];
    foreach ($rows('hp', ['title' => 60, 'text' => 300, 'url' => 500, 'cta' => 30], 8) as $h) {
        if ($h['title'] === '') throw new InvalidArgumentException('Every help link needs a title.');
        $help[] = ['title' => $h['title'], 'text' => $h['text'], 'url' => admin_link_url($h['title'], $h['url']), 'cta' => $h['cta']];
    }
    $issues = clean_line($in['issues'] ?? '', 500);
    if ($issues !== '') $issues = admin_link_url('Where its bugs go', $issues);
    $tool = [
        'id' => $id, 'slug' => $slug, 'name' => $name,
        'logo' => clean_tool_pic($in['logo'] ?? '', 'logo'), 'shot' => clean_tool_pic($in['shot'] ?? '', 'screenshot'), 'intro' => clean_text($in['intro'] ?? '', 3000),
        'buttons' => $buttons, 'features' => $features, 'help' => $help, 'groups' => (array) ($old['groups'] ?? []),
        'menu' => !empty($in['menu']), 'opts' => array_values(array_intersect(TOOL_OPTS, array_keys(array_filter((array) ($in['opts'] ?? []))))), 'issues' => $issues,
    ];
    if (($in['vis'] ?? '') === 'draft') $tool['draft'] = true;
    return $tool;
}

/** A tool's tutorials from the tutorials editor ({sections: [{title, text, pages: [tut]}]}) as `groups`; ids kept, one "Start Here" at most. */
function clean_tool_tuts(array $in): array
{
    $groups = [];
    $seen = [];
    $start = false;
    foreach ((array) ($in['sections'] ?? []) as $g) {
        if (!is_array($g)) continue;
        $title = clean_line($g['title'] ?? '', 80);
        $tuts = [];
        foreach ((array) ($g['pages'] ?? []) as $p) {
            if (!is_array($p)) continue;
            $pt = clean_line($p['title'] ?? '', 120);
            if ($pt === '') throw new InvalidArgumentException('Every tutorial needs a title' . ($title !== '' ? ' (one in "' . $title . '" has none).' : '.'));
            $slug = strtolower(clean_line($p['slug'] ?? '', 64));
            if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D', $slug)) throw new InvalidArgumentException('The address of "' . $pt . '" may only contain lowercase letters, numbers and dashes.');
            if (isset($seen[$slug])) throw new InvalidArgumentException('Two tutorials use the address "' . $slug . '": "' . $seen[$slug] . '" and "' . $pt . '". Change one of them.');
            $seen[$slug] = $pt;
            $video = clean_line($p['video'] ?? '', 1000);
            if ($video !== '' && !preg_match('#^/media/[A-Za-z0-9/_.-]+\.(mp4|webm)$#iD', $video) && !preg_match('#^https://\S+$#iD', $video))
                throw new InvalidArgumentException('The video of "' . $pt . '" must be an MP4 / WebM file of this site or an https address.');
            $t = ['id' => preg_match('/^[a-z0-9]{8}$/D', (string) ($p['id'] ?? '')) ? (string) $p['id'] : bin2hex(random_bytes(4)), 'slug' => $slug, 'title' => $pt];
            if (($s = clean_line($p['summary'] ?? '', 300)) !== '') $t['summary'] = $s;
            if (isset(TOOL_LEVELS[$p['level'] ?? ''])) $t['level'] = (string) $p['level'];
            if (($c = clean_tool_pic($p['cover'] ?? '', 'cover of "' . $pt . '"')) !== '') $t['cover'] = $c;
            if ($video !== '') $t['video'] = $video;
            if (!empty($p['start']) && !$start) $t['start'] = $start = true;
            if (!empty($p['draft'])) $t['draft'] = true;
            $t['body'] = clean_text($p['body'] ?? '', 200000);
            $tuts[] = $t;
        }
        if ($title === '' && !$tuts) continue;
        $groups[] = ['title' => $title, 'text' => clean_line($g['text'] ?? '', 160), 'tuts' => $tuts];
    }
    return $groups;
}
