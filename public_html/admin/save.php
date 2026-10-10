<?php
// POST JSON { kind: "models" | "outfits", action: save | delete | reorder | batch, … }, { kind: "faq", faq }, { kind: "docs", docs }
require __DIR__ . '/_admin.php';
admin_post_guard($M3D);

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) admin_json(['ok' => false, 'error' => 'Invalid request.'], 400);
if (($in['kind'] ?? '') === 'docs') {
    try {
        if (!m3d_save_docs(clean_docs((array) ($in['docs'] ?? [])))) throw new RuntimeException('Could not write the data file.');
        admin_json(['ok' => true]);
    } catch (InvalidArgumentException $ex) {
        admin_json(['ok' => false, 'error' => $ex->getMessage()], 422);
    } catch (Throwable $ex) {
        admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
    }
}
if (($in['kind'] ?? '') === 'faq') {
    try {
        if (!m3d_save_faq(clean_faq((array) ($in['faq'] ?? [])))) throw new RuntimeException('Could not write the data file.');
        admin_json(['ok' => true]);
    } catch (InvalidArgumentException $ex) {
        admin_json(['ok' => false, 'error' => $ex->getMessage()], 422);
    } catch (Throwable $ex) {
        admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
    }
}
$kind = ($in['kind'] ?? 'models') === 'outfits' ? 'outfits' : 'models';
$action = $in['action'] ?? 'save';
$items = m3d_items($kind);
$index = [];
foreach ($items as $i => $m) $index[$m['slug']] = $i;

try {
    if ($action === 'reorder') {
        $slugs = array_values(array_map('strval', (array) ($in['slugs'] ?? [])));
        $a = $slugs; $b = array_keys($index); sort($a); sort($b);
        if ($a !== $b) throw new InvalidArgumentException('The list changed in the meantime. Reload the page and try again.');
        $sorted = [];
        foreach ($slugs as $k => $slug) { $m = $items[$index[$slug]]; $m['order'] = $k + 1; $sorted[] = $m; }
        if (!m3d_save_items($kind, $sorted)) throw new RuntimeException('Could not write the data file.');
        admin_json(['ok' => true]);
    }

    // ticked rows of the Models / Accessories list: { slugs: [], op: delete | visibility | status, value }
    if ($action === 'batch') {
        $slugs = array_values(array_unique(array_map('strval', (array) ($in['slugs'] ?? []))));
        $slugs = array_values(array_filter($slugs, fn($s) => isset($index[$s])));
        if (!$slugs) throw new InvalidArgumentException('Nothing selected (or already deleted in another tab). Reload the page.');
        $op = (string) ($in['op'] ?? '');
        $value = (string) ($in['value'] ?? '');
        $skipped = [];
        if ($op === 'delete') {
            $items = array_values(array_filter($items, fn($m) => !in_array($m['slug'], $slugs, true)));
        } elseif ($op === 'visibility') {
            if (!isset(M3D_VISIBILITY[$value])) throw new InvalidArgumentException('Unknown visibility.');
            foreach ($slugs as $s) {
                $m = &$items[$index[$s]];
                // a password needs one: items that never had a password keep their visibility
                if ($value === 'password' && empty($m['passwordHash'])) { $skipped[] = $m['name']; unset($m); continue; }
                if ($value === 'public') unset($m['visibility']); else $m['visibility'] = $value;
                if ($value !== 'password') unset($m['unlisted']);
                unset($m);
            }
        } elseif ($op === 'status' && $kind === 'models') {
            if (!isset(M3D_STATUSES[$value])) throw new InvalidArgumentException('Unknown status.');
            foreach ($slugs as $s) $items[$index[$s]]['status'] = $value;
        } else {
            throw new InvalidArgumentException('Unknown change.');
        }
        if (!m3d_save_items($kind, $items)) throw new RuntimeException('Could not write the data file.');
        if ($op === 'delete' && $kind === 'models') {
            // forget the models in the outfits made for them
            $outfits = m3d_outfits();
            $changed = false;
            foreach ($outfits as &$o) {
                $before = $o['models'] ?? [];
                $o['models'] = array_values(array_diff($before, $slugs));
                $changed = $changed || $before !== $o['models'];
            }
            unset($o);
            if ($changed) m3d_save_items('outfits', $outfits);
        }
        admin_json(['ok' => true, 'changed' => count($slugs) - count($skipped), 'skipped' => $skipped]);
    }

    if ($action === 'delete') {
        $slug = (string) ($in['slug'] ?? '');
        if (!isset($index[$slug])) throw new InvalidArgumentException('Not found.');
        array_splice($items, $index[$slug], 1);
        if (!m3d_save_items($kind, $items)) throw new RuntimeException('Could not write the data file.');
        if ($kind === 'models') {
            // forget the model in the outfits made for it
            $outfits = m3d_outfits();
            $changed = false;
            foreach ($outfits as &$o) {
                $before = $o['models'] ?? [];
                $o['models'] = array_values(array_diff($before, [$slug]));
                $changed = $changed || $before !== $o['models'];
            }
            unset($o);
            if ($changed) m3d_save_items('outfits', $outfits);
        }
        admin_json(['ok' => true]);
    }

    // save
    $original = (string) ($in['originalSlug'] ?? '');
    $existing = $original !== '' ? ($items[$index[$original] ?? -1] ?? null) : null;
    if ($original !== '' && !$existing) throw new InvalidArgumentException('This entry no longer exists (deleted in another tab?).');
    $data = (array) ($in['item'] ?? $in['model'] ?? []);
    $item = $kind === 'outfits' ? clean_outfit($data, $existing) : clean_model($data, $existing);
    if (!$existing && isset($index[$item['slug']])) throw new InvalidArgumentException('The address "' . $item['slug'] . '" is already used. Pick another one.');

    if ($existing) $items[$index[$original]] = $item;
    else {
        // new entries go first: everything else moves down one place (the order is the list order, 1 = top)
        foreach ($items as &$x) $x['order'] = (int) ($x['order'] ?? 999) + 1;
        unset($x);
        $item['order'] = 1;
        array_unshift($items, $item);
    }
    if (!m3d_save_items($kind, $items)) throw new RuntimeException('Could not write the data file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.');
    if ($existing && $original !== $item['slug']) m3d_rename_counts($kind, $original, $item['slug']);
    admin_json(['ok' => true, 'slug' => $item['slug'], 'created' => !$existing]);
} catch (InvalidArgumentException $ex) {
    admin_json(['ok' => false, 'error' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('[site admin] ' . $ex->getMessage());
    admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
}
