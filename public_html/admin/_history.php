<?php
// The History of one model or accessory (the editor's "History" button): its earlier versions, read from the copies m3d_write_json()
// keeps of models.json / outfits.json before each save (the last 50 of each file, Admin → Backups → Change History). Only the saves that
// changed this item are listed, each with what differs from the item as it is now. Included by admin/history.php (the list, JSON) and
// admin/edit.php (?from=<file>: that version loaded into the editor, saved only when the admin presses Save).
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;
require_once __DIR__ . '/_backup.php';

/** The editor's sections, in page order, and the stored fields each one holds (anything else = Other). */
const HISTORY_PARTS = [
    'Basics'              => ['name', 'franchise', 'game', 'generation', 'majorVersion', 'type'],
    'Visibility & Status' => ['visibility', 'unlisted', 'status', 'featured', 'noComments'],
    'Images'              => ['thumb', 'cover', 'gallery', 'images', 'model3d', 'model3dLight'],
    'Optimized For'       => ['fits', 'allModels', 'models'],
    'Description'         => ['description', 'note'],
    'Requirements'        => ['blender', 'addons'],
    'Downloads'           => ['links'],
    'Access'              => ['requireTierCents', 'requireMonths', 'requireTotalCents', 'requireMode', 'noLimits', 'patronsFrom', 'publicFrom', 'releaseBefore'],
    'Features'            => ['features'],
    'Notes'               => ['notes'],
    'Changelog'           => ['changelogNote', 'changelog'],
    'Sidebar'             => ['futureIdeas', 'knownIssues', 'extra', 'credits'],
];
/** Field names as the editor labels them. */
const HISTORY_LABELS = [
    'name' => 'Name', 'franchise' => 'Franchise', 'game' => 'Game', 'generation' => 'Generation', 'majorVersion' => 'Major Version', 'type' => 'Type',
    'visibility' => 'Visibility', 'unlisted' => 'Unlisted', 'status' => 'Status', 'featured' => 'Featured', 'noComments' => 'Comments Off',
    'thumb' => 'Catalog Image', 'cover' => 'Main Image', 'gallery' => 'Gallery', 'images' => 'Pictures', 'model3d' => '3D Preview', 'model3dLight' => '3D Brightness',
    'fits' => 'Made for Blender', 'allModels' => 'All Models', 'models' => 'Optimized For', 'description' => 'Description', 'note' => 'Rig Note', 'blender' => 'Blender Version', 'addons' => 'Add-ons',
    'links' => 'Download Links', 'requireTierCents' => 'Minimum Tier', 'requireMonths' => 'Months as a Patron', 'requireTotalCents' => 'Total Support',
    'requireMode' => 'Months or Total', 'noLimits' => 'Doesn’t Use Unlocks', 'patronsFrom' => 'Patrons From', 'publicFrom' => 'Everyone From',
    'releaseBefore' => 'Before the Patrons Date', 'features' => 'Features', 'notes' => 'Notes', 'changelogNote' => 'Changelog Note',
    'changelog' => 'Changelog', 'futureIdeas' => 'Future Ideas', 'knownIssues' => 'Known Issues', 'extra' => 'Other Sections', 'credits' => 'Credits',
];
/** Stored fields that are never compared or shown: the list's order, and the password hash (it never leaves the server). */
const HISTORY_SKIP = ['order', 'passwordHash'];

/** The saved copies of a data file, newest first: [{file, time}] (the time = when the save that replaced it happened). */
function history_files(string $kind): array
{
    return array_map(fn($v) => ['file' => $v['file'], 'time' => $v['time']], data_history()[$kind] ?? []);
}

/** One item from a saved copy (null: not a copy of this kind's file, damaged, or the item isn't in it). */
function history_item(string $kind, string $file, string $slug): ?array
{
    if (!preg_match('/^' . preg_quote($kind, '/') . '-\d{8}-\d{6}-[0-9a-f]+\.json$/D', $file) || !($path = bk_file($file))) return null;
    $data = json_decode((string) @file_get_contents($path), true);
    foreach ((array) (is_array($data) ? ($data[$kind] ?? []) : []) as $it) if (is_array($it) && ($it['slug'] ?? null) === $slug) return $it;
    return null;
}

/** A stored value with every number as text, so 2 and "2" (the same field saved by older code) read as the same. */
function history_norm(mixed $v): mixed
{
    if (is_array($v)) return array_map('history_norm', $v);
    return is_int($v) || is_float($v) ? (string) $v : $v;
}

/** A stored value as a line of text: lists of objects one line each ("Blender file · https://… · patrons"), pictures by address. */
function history_text(mixed $v): string
{
    if (is_bool($v)) return $v ? 'On' : 'Off';
    if (is_array($v)) return implode(' · ', array_filter(array_map(fn($x) => trim(history_text($x)), $v), fn($x) => $x !== ''));
    return (string) $v;
}

/** A field's value as lines to compare: a list = one line per entry, anything else = one line ('' = empty). */
function history_lines(mixed $v): array
{
    if (is_array($v) && array_is_list($v)) return array_values(array_filter(array_map('history_text', $v), fn($x) => $x !== ''));
    $t = history_text($v);
    return $t === '' ? [] : [$t];
}

/** One changelog version as lines: its date, stage and highlight, then each change, note and picture. */
function history_version_lines(array $en): array
{
    $l = [];
    foreach (['date' => 'Date', 'stage' => 'Stage', 'highlight' => 'Highlight', 'image' => 'Picture'] as $k => $w) if (trim((string) ($en[$k] ?? '')) !== '') $l[] = "$w: " . trim((string) $en[$k]);
    foreach ((array) ($en['changes'] ?? []) as $c) if (trim((string) $c) !== '') $l[] = '• ' . trim((string) $c);
    foreach ((array) ($en['notes'] ?? []) as $n) if (trim(history_text($n)) !== '') $l[] = 'Note: ' . trim(history_text($n));
    return $l;
}

/** What differs between an earlier version ($then) and the item now: [{part, label, then, now, note?}] (then / now: lines of text,
 *  for a list only the lines that went or came; note: "Added since", "Removed since", "Same entries, another order"). */
function history_diff(array $then, array $now): array
{
    $part = [];
    $rank = [];   // fields in the editor's order
    foreach (array_values(HISTORY_PARTS) as $p => $fields) foreach ($fields as $f) { $part[$f] = array_keys(HISTORY_PARTS)[$p]; $rank[$f] = count($rank); }
    $out = [];
    $keys = array_values(array_diff(array_unique(array_merge(array_keys($then), array_keys($now))), HISTORY_SKIP));
    usort($keys, fn($a, $b) => ($rank[$a] ?? 999) <=> ($rank[$b] ?? 999));
    foreach ($keys as $k) {
        $a = $then[$k] ?? null;
        $b = $now[$k] ?? null;
        if (json_encode(history_norm($a)) === json_encode(history_norm($b))) continue;   // the same (2 and "2" too)
        $p = $part[$k] ?? 'Other';
        $label = HISTORY_LABELS[$k] ?? ucfirst((string) $k);
        if ($k === 'changelog') {   // version by version, by its label: a new version on top doesn't make every other one "changed"
            $by = fn($log) => array_column(array_map(fn($en) => [m3d_version_label($en), $en], array_filter((array) $log, 'is_array')), 1, 0);
            $va = $by($a);
            $vb = $by($b);
            foreach (array_unique(array_merge(array_keys($vb), array_keys($va))) as $ver) {
                if (json_encode(history_norm($va[$ver] ?? null)) === json_encode(history_norm($vb[$ver] ?? null))) continue;
                $out[] = ['part' => $p, 'label' => 'Changelog · ' . bug_version_words((string) $ver), 'then' => isset($va[$ver]) ? history_version_lines($va[$ver]) : [], 'now' => isset($vb[$ver]) ? history_version_lines($vb[$ver]) : []];
                if (isset($va[$ver], $vb[$ver])) {   // in both: only the lines that changed
                    [$x, $y] = [end($out)['then'], end($out)['now']];
                    $out[count($out) - 1]['then'] = array_values(array_diff($x, $y));
                    $out[count($out) - 1]['now'] = array_values(array_diff($y, $x));
                    if (!$out[count($out) - 1]['then'] && !$out[count($out) - 1]['now']) $out[count($out) - 1]['note'] = 'Same lines, another order';
                } elseif (!isset($va[$ver])) $out[count($out) - 1]['note'] = 'Added since';
                else $out[count($out) - 1]['note'] = 'Removed since';
            }
            continue;
        }
        $multi = fn($v) => is_string($v) && str_contains($v, "\n");   // a text of several paragraphs: compared line by line, like a list
        $la = $multi($a) ? array_values(array_filter(array_map('trim', explode("\n", $a)), fn($x) => $x !== '')) : history_lines($a);
        $lb = $multi($b) ? array_values(array_filter(array_map('trim', explode("\n", $b)), fn($x) => $x !== '')) : history_lines($b);
        if (is_array($a) && array_is_list($a) || is_array($b) && array_is_list($b) || $multi($a) || $multi($b)) {   // a list: only the lines that went or came
            $gone = array_values(array_diff($la, $lb));
            $came = array_values(array_diff($lb, $la));
            $out[] = ['part' => $p, 'label' => $label, 'then' => $gone, 'now' => $came] + (!$gone && !$came ? ['note' => 'Same entries, another order'] : []);
        } else {
            $out[] = ['part' => $p, 'label' => $label, 'then' => $la, 'now' => $lb];
        }
    }
    foreach ($out as &$d) foreach (['then', 'now'] as $w) $d[$w] = array_map(fn($l) => mb_strlen($l) > 600 ? mb_substr($l, 0, 600) . '…' : $l, array_slice($d[$w], 0, 30));
    unset($d);
    return $out;
}

/**
 * The earlier versions of one item, newest first: [{file, when, parts, diff}], one per save that changed it (a save of other items
 * only is skipped); parts / diff = what differs from the item now. Stops where the item isn't found (created, or saved under another
 * address before).
 */
function history_rows(string $kind, string $slug): array
{
    $now = m3d_item($kind, $slug);
    if (!$now) return [];
    $strip = fn(array $it) => history_norm(array_diff_key($it, array_flip(HISTORY_SKIP)));
    $newer = json_encode($strip($now));
    $rows = [];
    foreach (history_files($kind) as $f) {
        $it = history_item($kind, $f['file'], $slug);
        if (!$it) break;
        $enc = json_encode($strip($it));
        if ($enc === $newer) continue;   // this save didn't touch the item
        $newer = $enc;
        $diff = history_diff($strip($it), $strip($now));
        $rows[] = ['file' => $f['file'], 'when' => m3d_site_time($f['time'], 'D j M Y, H:i'), 'parts' => array_values(array_unique(array_column($diff, 'part'))), 'diff' => $diff];
    }
    return $rows;
}
