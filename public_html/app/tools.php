<?php
// Pages: tool pages, a page per tool or add-on the creator makes (/tools/<slug>/), and simple pages (/pages/<slug>/: a title and a text), both with
// tutorials as pages of their own (/tools/<slug>/<tutorial>/, /pages/<slug>/<tutorial>/). Edited in Admin → Assets → Pages (admin/tools.php, admin/tool.php
// = the page, its type switched at the top, admin/tool-tutorials.php = its tutorials); shown once listed (no switch, like scenes).
// Data: data/tools.json {tools: [tool]}, tool =
//   id (8 hex, never changes), type ('page' = a simple page; absent = a tool), slug (unique among pages of its type), name, intro (md_html: a tool's
//   intro, a simple page's text); a tool only: logo, shot (the screenshot),
//   buttons [{label, url}] (the first one yellow), features [{title, text}], help [{title, text, url, cta}],
//   groups [{title, text, tuts: [tut]}], tut = {id, slug (unique in the tool), title, summary, level (TOOL_LEVELS), cover, video (/media/… .mp4 | https),
//   body (md_html), start (the "Start Here" one), draft},
//   draft; a tool only: menu (in the header menu), opts {bugver (its version asked in bug reports), badge ("New <Name>" in changelogs), welcome (linked on the
//   welcome page), contacts (a card on Contacts)}, issues (where its bugs go: the bug form's line and the Contacts card).
// Every page reads only the summary (m3d_tools_summary()): the menu, the bug form, the changelog badge.
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

const TOOL_LEVELS = ['beginner' => 'Beginner', 'intermediate' => 'Intermediate', 'advanced' => 'Advanced'];
const TOOL_OPTS = ['bugver', 'badge', 'welcome', 'contacts'];
const PAGE_TYPES = ['page' => 'Simple Page', 'tool' => 'Tool'];

/** 'page' (a simple page) or 'tool'. */
function m3d_page_type(array $t): string
{
    return ($t['type'] ?? '') === 'page' ? 'page' : 'tool';
}

function m3d_tools(bool $fresh = false): array
{
    static $tools = null;
    if ($tools !== null && !$fresh) return $tools;
    $d = m3d_read_json('tools');
    if ($d === null && !is_file(M3D_PRIVATE . '/data/tools.json')) $d = ['tools' => [m3d_tool_default()]];   // never saved: MustardUI, in draft
    return $tools = ['tools' => array_values(array_filter((array) ($d['tools'] ?? []), 'is_array'))];
}

function m3d_save_tools(array $tools): bool
{
    $ok = m3d_write_json('tools', ['version' => 1, 'updated' => gmdate('c'), 'tools' => array_values($tools)]);
    m3d_tools(true);
    m3d_tools_summary(true);
    return $ok;
}

/** The tool (or, with $type 'page', the simple page) at that address. */
function m3d_tool(string $slug, string $type = 'tool'): ?array
{
    foreach (m3d_tools()['tools'] as $t) if (($t['slug'] ?? '') === $slug && m3d_page_type($t) === $type) return $t;
    return null;
}

function m3d_tool_by_id(string $id): ?array
{
    foreach (m3d_tools()['tools'] as $t) if (($t['id'] ?? '') === $id) return $t;
    return null;
}

/** Visitors see listed tools only; admins see drafts too (with a banner). */
function m3d_tool_visible(array $t): bool
{
    return empty($t['draft']) || m3d_is_admin($GLOBALS['M3D']);
}

/** A page's address (a tool's when given only its slug), or one of its tutorials'. */
function m3d_tool_url(array|string $t, string $tut = ''): string
{
    $base = is_array($t) && m3d_page_type($t) === 'page' ? '/pages/' : '/tools/';
    return $base . rawurlencode(is_array($t) ? (string) $t['slug'] : $t) . '/' . ($tut !== '' ? rawurlencode($tut) . '/' : '');
}

/**
 * What every page needs, without reading the tools' texts: {tools: [{id, type, slug, name, menu, opts, issues}]} of the listed (not draft) pages,
 * in their order. A summary of data/tools.json (m3d_data_summary()).
 */
function m3d_tools_summary(bool $remake = false): array
{
    return m3d_data_summary('tools', function () {
        $out = [];
        foreach (m3d_tools(true)['tools'] as $t) {
            if (!empty($t['draft'])) continue;
            $out[] = ['id' => (string) $t['id'], 'type' => m3d_page_type($t), 'slug' => (string) $t['slug'], 'name' => (string) $t['name'], 'menu' => !empty($t['menu']),
                'opts' => array_values(array_intersect(TOOL_OPTS, (array) ($t['opts'] ?? []))), 'issues' => (string) ($t['issues'] ?? '')];
        }
        return ['tools' => $out];
    }, $remake) + ['tools' => []];
}

/** The listed tools (summary rows; not simple pages), optionally only those with an option on (TOOL_OPTS). */
function m3d_tools_listed(string $opt = ''): array
{
    return array_values(array_filter(m3d_tools_summary()['tools'], fn($t) => ($t['type'] ?? 'tool') === 'tool' && ($opt === '' || in_array($opt, $t['opts'], true))));
}

/** The header menu's Tools item: [label, address] (one tool in the menu = its own name and page), or null when none is in the menu. */
function m3d_tools_menu(): ?array
{
    $in = array_values(array_filter(m3d_tools_listed(), fn($t) => $t['menu']));
    if (!$in) return null;
    return count($in) === 1 ? [$in[0]['name'], m3d_tool_url($in[0])] : ['Tools', '/tools/'];
}

/** The tool whose version bug reports ask for (the first listed one with that option), or null: its name labels the field (stored as `mui`). */
function m3d_bug_tool(): ?array
{
    return m3d_tools_listed('bugver')[0] ?? null;
}

/** The tutorials of a tool in reading order, each with `group` (its group's title); drafts only when $drafts. */
function m3d_tool_tuts(array $tool, bool $drafts = false): array
{
    $out = [];
    foreach ((array) ($tool['groups'] ?? []) as $g) foreach ((array) ($g['tuts'] ?? []) as $t) {
        if (!$drafts && !empty($t['draft'])) continue;
        $out[] = $t + ['group' => (string) ($g['title'] ?? '')];
    }
    return $out;
}

/** Minutes to read a tutorial's text (200 words a minute, at least 1). */
function m3d_tut_minutes(array $t): int
{
    $words = str_word_count(strip_tags((string) preg_replace('/[#>*_`|\[\]()!=+:-]+/', ' ', (string) ($t['body'] ?? ''))));
    return max(1, (int) round($words / 200));
}

/** A tutorial's video: ['url', 'local' (a file of this site, played on the page), 'dur' (seconds, local files only)], or null without one. */
function m3d_tut_video(array $t): ?array
{
    $url = trim((string) ($t['video'] ?? ''));
    if ($url === '') return null;
    if (str_starts_with($url, '/media/')) {
        $file = m3d_video_path($url);
        $info = $file ? m3d_video_info($file) : [];
        return ['url' => $url, 'local' => true, 'dur' => (float) ($info['dur'] ?? 0)];
    }
    return preg_match('#^https://#i', $url) ? ['url' => $url, 'local' => false, 'dur' => 0.0] : null;
}

/** "Video · 9:05" / "Video" / "Guide · 3 min read". */
function m3d_tut_kind(array $t): string
{
    $v = m3d_tut_video($t);
    if ($v) return 'Video' . ($v['dur'] > 0 ? ' · ' . m3d_duration($v['dur']) : '');
    return 'Guide · ' . m3d_tut_minutes($t) . ' min read';
}

/** A tutorial's picture: its cover, else its video's cover, else '' (the card then shows the tool's logo). */
function m3d_tut_pic(array $t): string
{
    $c = trim((string) ($t['cover'] ?? ''));
    if ($c !== '') return $c;
    $v = m3d_tut_video($t);
    return $v && $v['local'] ? m3d_video_cover($v['url']) : '';
}

/**
 * The tool every site starts with (Pi): MustardUI, the free Blender add-on, in draft until the creator publishes it, with its texts and the
 * pictures shipped in assets/. Only while data/tools.json was never saved; deleting it in the admin keeps it gone (the file then exists).
 */
function m3d_tool_default(): array
{
    $wiki = 'https://github.com/Mustard2/MustardUI/wiki';
    $help = [
        ['title' => 'User Guide', 'text' => 'Everything the panel can do: outfits, hair, body, physics, morphs and settings, section by section.', 'url' => "$wiki/User-Guide", 'cta' => 'Read on the Wiki'],
        ['title' => 'Developer Guide', 'text' => 'For creators: configure MustardUI on your own characters and add your outfits, morphs and settings.', 'url' => "$wiki/Developer-Guide", 'cta' => 'Read on the Wiki'],
        ['title' => 'Report an Issue', 'text' => 'Bugs and feature ideas for MustardUI go to GitHub issues, so they can be tracked and fixed. Search first: someone may have reported it already. Include your Blender and MustardUI versions.', 'url' => 'https://github.com/Mustard2/MustardUI/issues', 'cta' => 'Open the Issues'],
    ];
    return [
        'id' => 'a0000001', 'slug' => 'mustardui', 'name' => 'MustardUI',
        'logo' => '/assets/mustardui-logo.webp', 'shot' => '/assets/mustardui-panel.webp',
        'intro' => 'The control panel built into the models. Change outfits, hair, skin, physics and morphs from one artist-friendly panel in Blender, without writing a line of Python.',
        'buttons' => [['label' => 'Get It on Blender Extensions', 'url' => 'https://extensions.blender.org/add-ons/mustardui/'], ['label' => 'View on GitHub', 'url' => 'https://github.com/Mustard2/MustardUI']],
        'features' => [
            ['title' => 'Outfits', 'text' => 'Switch outfits and single pieces on and off, and add your own outfits to the list.'],
            ['title' => 'Hair', 'text' => 'Pick the hairstyle and change its settings from the same panel.'],
            ['title' => 'Body & Skin', 'text' => 'Skin shader settings like subsurface scattering and translucency, no node digging needed.'],
            ['title' => 'Physics', 'text' => 'Physics for body parts, hair and clothes, turned on only when you need them.'],
            ['title' => 'Morphs', 'text' => 'Shape keys and morphs as sliders; disable them all at once to speed things up.'],
            ['title' => 'Simplify & Tools', 'text' => 'One-click Simplify for a smooth viewport, plus tools like Clean Model and breathing animation.'],
        ],
        'help' => $help, 'groups' => [], 'draft' => true, 'menu' => true, 'opts' => TOOL_OPTS, 'issues' => 'https://github.com/Mustard2/MustardUI/issues',
    ];
}

// ── Page pieces ──────────────────────────────────────────────────────────────
/** A level pill ('' = none). */
function tool_level_pill(string $level): string
{
    return isset(TOOL_LEVELS[$level]) ? '<span class="lvl lvl-' . e($level) . '">' . e(TOOL_LEVELS[$level]) . '</span>' : '';
}

/** A tutorial's 16:9 picture: its cover (or video cover), else the tool's logo on a tinted box; a play / page icon and the length. */
function tool_tut_cover(array $tool, array $t, string $sizes = '(max-width: 560px) 100vw, 400px'): string
{
    $pic = m3d_tut_pic($t);
    $v = m3d_tut_video($t);
    $icon = $v ? '<span class="tt-ic"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4.5v15l13-7.5z"/></svg></span>'
        : '<span class="tt-ic tt-doc"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 3h9l4 4v14H6z"/><path d="M9 12h7M9 16h7M9 8h4"/></svg></span>';
    $len = $v ? ($v['dur'] > 0 ? m3d_duration($v['dur']) : '') : m3d_tut_minutes($t) . ' min read';
    $img = $pic !== '' ? '<img src="' . e(img_small($pic) ?: $pic) . '" alt="" loading="lazy" decoding="async" />'
        : ((string) ($tool['logo'] ?? '') !== '' ? '<img class="tt-logo" src="' . e(img_small((string) $tool['logo']) ?: (string) $tool['logo']) . '" alt="" loading="lazy" decoding="async" />' : '');
    return '<span class="tt-cover' . ($pic === '' ? ' is-plain' : '') . '">' . $img . $icon . ($len !== '' ? '<span class="tt-len">' . e($len) . '</span>' : '') . '</span>';
}

/** The text after a help card's title: its button ("Open ↗" when no text is set; ↗ for other websites). */
function tool_help_cta(array $h): string
{
    $ext = (bool) preg_match('#^https?://#i', (string) $h['url']);
    return e(trim((string) ($h['cta'] ?? '')) ?: 'Open') . ($ext ? ' ↗' : '');
}

/** The icon of a page type: a wrench for a tool, a sheet of text for a simple page (the paths of a 24 × 24 <svg>). */
function page_type_icon(string $type): string
{
    return $type === 'page' ? '<path d="M6 3h9l4 4v14H6z"/><path d="M14 3v5h5M9 12h7M9 16h7"/>'
        : '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3.6 17.2a1.6 1.6 0 0 0 2.3 2.3l5.7-5.7a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.2-.4-.4-2.2z"/>';
}

/** The admin lists' pill of a page's type: icon + "Tool" / "Simple Page". */
function page_type_pill(array $t): string
{
    $type = m3d_page_type($t);
    return '<span class="tot-tag pg-type pg-' . $type . '"><svg viewBox="0 0 24 24" aria-hidden="true">' . page_type_icon($type) . '</svg>' . PAGE_TYPES[$type] . '</span>';
}
