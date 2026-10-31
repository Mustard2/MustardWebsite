<?php
// GET JSON for the picture picker (admin.js pickImages(), "Choose…" / "Add pictures" everywhere in the admin): every picture of the
// Images library, newest first, the folder filter's options and the upload folder's options (?folder= = the one picked first).
require __DIR__ . '/_admin.php';
admin_require($M3D, true);
m3d_session_release();

$items = media_library();
usort($items, fn($a, $b) => $b['time'] <=> $a['time']);
$models = m3d_models();
[$pick, $names] = admin_folder_picks($models);
$order = array_flip(array_keys($pick));
// a picture also counts as in the folder of every model / accessory / scene using it (one uploaded for an accessory and used by a scene
// shows under the scene too, Pi): 'in' = those folders when not its own
$use = usage_map($models);
$useFolder = fn(string $k) => match (explode(':', $k)[0]) { 'models' => explode(':', $k, 2)[1], 'outfits' => 'outfit-' . explode(':', $k, 2)[1], 'scenes' => 'scene-' . explode(':', $k, 2)[1], default => '' };
foreach ($items as &$i) {
    $in = array_filter(array_map($useFolder, array_keys(($use[$i['url']] ?? []) + ($use[$i['small']] ?? []))), fn($f) => $f !== '' && $f !== $i['folder']);
    $i['in'] = array_values(array_unique($in));
}
unset($i);
$folders = array_values(array_unique(array_merge(array_column($items, 'folder'), ...array_column($items, 'in'))));
usort($folders, fn($a, $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);
$want = preg_replace('/[^a-z0-9-]/', '', strtolower(m3d_get_text('folder'))) ?: 'misc';
$opt = fn(string $f) => folder_option($f, $pick, $names, $f === $want);
$upload = '<optgroup label="Models">' . implode('', array_map(fn($m) => $opt($m['slug']), $models)) . '</optgroup>'
    . (m3d_outfits() ? '<optgroup label="Accessories">' . implode('', array_map(fn($o) => $opt('outfit-' . $o['slug']), m3d_outfits())) . '</optgroup>' : '')
    . (m3d_items('scenes') ? '<optgroup label="Scenes">' . implode('', array_map(fn($o) => $opt('scene-' . $o['slug']), m3d_items('scenes'))) . '</optgroup>' : '')
    . '<optgroup label="Other">' . implode('', array_map($opt, array_merge(['gallery'], m3d_blog_on() || m3d_blog()['posts'] || $want === 'blog' ? ['blog'] : [], ['docs', 'faq', 'misc']))) . '</optgroup>';
admin_json(['ok' => true,
    'items' => array_map(fn($i) => ['url' => $i['url'], 'small' => $i['small'], 'folder' => $i['folder'], 'name' => $i['name'], 'w' => $i['w'], 'h' => $i['h'], 'in' => $i['in'], 'label' => mb_strtolower(implode(' ', [$i['name'], ...array_map(fn($f) => $names[$f] ?? $f, [$i['folder'], ...$i['in']])]))], $items),
    'folders' => implode('', array_map(fn($f) => folder_option($f, $pick, $names), $folders)),
    'upload' => $upload]);
