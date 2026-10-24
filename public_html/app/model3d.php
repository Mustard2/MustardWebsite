<?php
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

// 3D preview of a model or accessory: `model3d` = the address of a .glb file in /media/ (uploaded with admin/upload-3d.php).
// The page shows a "View in 3D" button on the main picture (item_media() in view.php); the viewer (assets/vendor/model-viewer.min.js,
// Google's <model-viewer>, self-hosted) and the file load only when it is pressed (site.js). Only items whose pictures are public
// show it (admins also on drafts and password items; blurred pictures never), because anyone who sees the viewer can save the file.

const M3D_GLB_MAX = 100 * 1024 * 1024;   // common CDN proxies (free plans) refuse requests over 100 MB
const M3D_GLB_LIGHT = ['bytes' => 25 * 1024 * 1024, 'tris' => 150000, 'tex' => 2048];   // above any of these: "may be slow on phones"
// glTF extensions the viewer can't read without files from another website (or at all): refused at upload
const M3D_GLB_REFUSED = [
    'KHR_draco_mesh_compression' => 'Draco compression',
    'KHR_texture_basisu'         => 'KTX2 (Basis) textures',
    'EXT_meshopt_compression'    => 'meshopt compression',
];

const M3D_GLB_DAMAGED = 'The file is damaged (its glTF description is not valid).';

// The viewer's light: `model3dLight` = percent of M3D_EXPOSURE (editor: 3D Preview → Brightness; absent = 100), the same in admin.js and site.js
const M3D_EXPOSURE = 1.1;
const M3D_LIGHT_RANGE = [20, 200];

/** '/media/<folder>/<name>.glb' → the file's path when it exists, else null. */
function m3d_glb_path(string $url): ?string
{
    if (!preg_match('#^/media/([a-z0-9-]+/)*[a-z0-9-]+\.glb$#D', $url)) return null;
    $file = M3D_SITE . $url;
    return is_file($file) ? $file : null;
}

/**
 * Deletes the 3D files at these addresses that no model or accessory uses any more (after a save replaced or removed one, or a delete):
 * a .glb can be 100 MB, and nothing else ever removes it (the image clean-ups leave .glb files alone).
 */
function m3d_glb_forget(array $urls): void
{
    $urls = array_filter(array_map('strval', $urls), fn($u) => $u !== '');
    if (!$urls) return;
    $used = [];
    foreach (['models', 'outfits'] as $k) foreach (m3d_items($k, true) as $it) $used[(string) ($it['model3d'] ?? '')] = true;
    foreach (array_unique($urls) as $u) if (!isset($used[$u]) && ($f = m3d_glb_path($u))) @unlink($f);
}

/** The item's 3D file: ['src' => address, 'bytes' => size], or null (none set, or the file is gone). */
function m3d_model3d(array $item): ?array
{
    $src = (string) ($item['model3d'] ?? '');
    $file = $src !== '' ? m3d_glb_path($src) : null;
    return $file ? ['src' => $src, 'bytes' => (int) filesize($file)] : null;
}

/** The viewer's exposure for this item ("0.88"): its Brightness setting times M3D_EXPOSURE. */
function m3d_exposure(array $item): string
{
    $pct = max(M3D_LIGHT_RANGE[0], min(M3D_LIGHT_RANGE[1], (int) ($item['model3dLight'] ?? 100)));
    return (string) round(M3D_EXPOSURE * $pct / 100, 3);
}

/** "3.1 MB" */
function m3d_mb(int $bytes): string
{
    return number_format(max(0.1, $bytes / 1048576), 1) . ' MB';
}

/**
 * Reads a .glb file's header and description (nothing else is loaded): size, triangles, textures and their largest side, shape keys,
 * rig, animations, and `light` (fits M3D_GLB_LIGHT) with `heavy` = what doesn't. Throws RuntimeException when the file isn't a
 * glTF 2.0 binary the viewer can show; the message says what to change in Blender's export.
 */
function m3d_glb_info(string $file): array
{
    $bytes = (int) @filesize($file);
    $fh = @fopen($file, 'rb');
    if (!$fh) throw new RuntimeException('The file could not be read.');
    try {
        $head = (string) fread($fh, 20);
        if (strlen($head) < 20 || substr($head, 0, 4) !== 'glTF') throw new RuntimeException('This is not a .glb file. In Blender: File → Export → glTF 2.0, Format “glTF Binary (.glb)”.');
        ['v' => $ver, 'jl' => $jsonLen, 'jt' => $jsonType] = unpack('x4/Vv/x4/Vjl/Vjt', $head);
        if ($ver !== 2) throw new RuntimeException('Only glTF 2.0 files are supported: export again from a recent Blender.');
        if ($jsonType !== 0x4E4F534A || $jsonLen < 2 || $jsonLen > 64 * 1024 * 1024 || 20 + $jsonLen > $bytes) throw new RuntimeException('The file is damaged (no glTF description found).');
        $gl = json_decode((string) fread($fh, $jsonLen), true);
        if (!is_array($gl)) throw new RuntimeException(M3D_GLB_DAMAGED);
        foreach (['buffers', 'bufferViews', 'accessors', 'meshes', 'nodes', 'images', 'skins', 'animations', 'extensionsRequired'] as $k) {
            if (isset($gl[$k]) && !is_array($gl[$k])) throw new RuntimeException(M3D_GLB_DAMAGED);   // lists only (the rest: Throwable in the callers)
        }
        // the viewer would fetch a buffer stored outside the file from wherever it points (another website = a third-party request)
        foreach ((array) ($gl['buffers'] ?? []) as $b) {
            if (is_array($b) && isset($b['uri']) && !str_starts_with((string) $b['uri'], 'data:')) throw new RuntimeException('Part of the model is a separate file. Export as “glTF Binary (.glb)” so everything is inside the file.');
        }

        foreach ($gl['extensionsRequired'] ?? [] as $ext) {
            if (isset(M3D_GLB_REFUSED[$ext])) throw new RuntimeException('This file uses ' . M3D_GLB_REFUSED[$ext] . ', which the viewer would have to download from another website. Export again with Compression turned off.');
        }
        $acc = $gl['accessors'] ?? [];
        $views = $gl['bufferViews'] ?? [];

        // triangles: per mesh, counted once per node that shows it (no nodes = once each)
        $meshTris = [];
        $morphs = 0;
        foreach ($gl['meshes'] ?? [] as $i => $mesh) {
            $t = 0;
            foreach ($mesh['primitives'] ?? [] as $p) {
                $n = (int) ($acc[$p['indices'] ?? -1]['count'] ?? $acc[$p['attributes']['POSITION'] ?? -1]['count'] ?? 0);
                $mode = (int) ($p['mode'] ?? 4);
                $t += $mode === 4 ? intdiv($n, 3) : (in_array($mode, [5, 6], true) ? max(0, $n - 2) : 0);
                $morphs = max($morphs, count($p['targets'] ?? []));
            }
            $meshTris[$i] = $t;
        }
        $uses = array_filter(array_map(fn($n) => $n['mesh'] ?? null, $gl['nodes'] ?? []), 'is_int');
        $tris = $uses ? array_sum(array_map(fn($i) => $meshTris[$i] ?? 0, $uses)) : array_sum($meshTris);

        // textures: their size from the first bytes of each picture stored in the file
        $binStart = 20 + $jsonLen + 8;
        $texMax = 0;
        foreach ($gl['images'] ?? [] as $img) {
            if (isset($img['uri']) && !str_starts_with((string) $img['uri'], 'data:')) throw new RuntimeException('The textures are separate files. Export as “glTF Binary (.glb)” so they are inside the file.');
            $bv = $views[$img['bufferView'] ?? -1] ?? null;
            if (!$bv) continue;
            fseek($fh, $binStart + (int) ($bv['byteOffset'] ?? 0));
            $size = @getimagesizefromstring((string) fread($fh, min((int) ($bv['byteLength'] ?? 0), 512 * 1024)));
            if ($size) $texMax = max($texMax, (int) $size[0], (int) $size[1]);
        }
    } finally {
        fclose($fh);
    }

    $heavy = [];
    if ($bytes > M3D_GLB_LIGHT['bytes']) $heavy[] = 'over ' . m3d_mb(M3D_GLB_LIGHT['bytes']);
    if ($tris > M3D_GLB_LIGHT['tris']) $heavy[] = 'over ' . number_format(M3D_GLB_LIGHT['tris'] / 1000) . 'k triangles';
    if ($texMax > M3D_GLB_LIGHT['tex']) $heavy[] = 'textures over ' . M3D_GLB_LIGHT['tex'] . ' px';
    return [
        'bytes'    => $bytes,
        'tris'     => $tris,
        'textures' => count($gl['images'] ?? []),
        'texMax'   => $texMax,
        'morphs'   => $morphs,
        'rig'      => !empty($gl['skins']),
        'anims'    => count($gl['animations'] ?? []),
        'light'    => !$heavy,
        'heavy'    => $heavy,
    ];
}

/** The editor's facts about the item's 3D file (edit.php payload), or null; a file that can't be read says why. */
function m3d_model3d_facts(array $item): ?array
{
    $m = m3d_model3d($item);
    if (!$m) return ($item['model3d'] ?? '') !== '' ? ['error' => 'The file is missing from the server.'] : null;
    try { return m3d_glb_info(M3D_SITE . $m['src']); }
    catch (RuntimeException $ex) { return ['error' => $ex->getMessage(), 'bytes' => $m['bytes']]; }
    catch (Throwable) { return ['error' => M3D_GLB_DAMAGED, 'bytes' => $m['bytes']]; }
}
