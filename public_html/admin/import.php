<?php
// Import from SmutBase (Admin → Models): finds the projects of a SmutBase profile (or of saved pages) and turns the chosen
// ones into draft models, one request per project (admin.js → initImport). Parsing in _smutbase.php.
require __DIR__ . '/_admin.php';
require __DIR__ . '/_smutbase.php';
admin_require($M3D);
if (!m3d_smutbase_on()) { header('Location: /admin/settings.php#smutbase'); exit; }   // off: Settings → SmutBase import

// "What to import" (step 2): label, the model fields it fills
const IMPORT_PARTS = [
    'description' => ['Description', ['description']],
    'features'    => ['Features', ['features']],
    'notes'       => ['Notes (instructions, requirements, other sections)', ['notes']],
    'changelog'   => ['Changelog', ['changelog', 'changelogNote']],
    'pictures'    => ['Pictures', []],
];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    admin_post_guard($M3D);
    $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
    ignore_user_abort(true);
    @set_time_limit(600);   // a project's pictures: up to two tries of two minutes each
    try {
        $models = m3d_models();
        // ── the list: projects of a profile (fetched) or of uploaded pages (profile pages and/or project pages) ──
        if (($in['action'] ?? '') === 'list') {
            $found = [];
            $pages = array_values(array_filter((array) ($in['html'] ?? []), 'is_string'));
            if ($pages) {
                foreach ($pages as $i => $html) {
                    if (str_contains($html, 'id="file_title"')) {   // a project page: imported from the file itself
                        $p = sb_project($html);
                        $found[$p['id']] = ['url' => $p['url'], 'id' => $p['id'], 'title' => $p['title'], 'file' => $i];
                    } else {
                        foreach (sb_profile_page($html)[0] as $p) $found[$p['id']] ??= $p;
                    }
                }
                if (!$found) throw new InvalidArgumentException('No SmutBase projects found in these files. Save the profile or project pages with Ctrl+S (“Webpage, HTML only”).');
            } else {
                $url = trim((string) ($in['url'] ?? ''));
                foreach (sb_profile($url) as $p) $found[$p['id']] = $p;
                if (!$found) throw new InvalidArgumentException('This profile has no projects.');
                m3d_save_settings(['import_profile' => $url]);
            }
            $rows = [];
            foreach ($found as $p) {
                [$name, $franchise, $major] = sb_parse_title($p['title']);
                $done = sb_imported($p['id'], $models);
                $similar = $done ? null : sb_similar($name, $major, $models);
                $draft = $done && m3d_visibility($done) === 'draft';
                $rows[] = $p + ['name' => $name, 'franchise' => $franchise, 'major' => $major,
                    // a draft made from SmutBase's bot check (before the importer recognised it) is replaced by default
                    'imported' => $done ? ['slug' => $done['slug'], 'name' => $done['name'], 'draft' => $draft,
                        'broken' => $draft && (bool) preg_match('/not a bot/i', (string) $done['name'])] : null,
                    'similar' => $similar ? ['slug' => $similar['slug'], 'name' => $similar['name']] : null];
            }
            admin_json(['ok' => true, 'projects' => $rows]);
        }

        // ── one project → one draft model ──
        if (($in['action'] ?? '') === 'import') {
            $url = trim((string) ($in['url'] ?? ''));
            $html = is_string($in['html'] ?? null) && $in['html'] !== '' ? $in['html'] : sb_fetch($url);
            $p = sb_project($html, $url);
            // imported before: only a draft, and only when asked (ticked again in the list), is replaced; never a public model
            $old = sb_imported($p['id'], $models);
            if ($old && !(m3d_visibility($old) === 'draft' && ($in['replace'] ?? '') === $old['slug'])) throw new InvalidArgumentException('Already imported as “' . $old['name'] . '”.');
            $m = $p['model'];
            // parts left out in the "What to import" choices (name, franchise, Blender version, credits and the link always come)
            $skip = array_intersect(array_map('strval', (array) ($in['skip'] ?? [])), array_keys(IMPORT_PARTS));
            foreach ($skip as $part) foreach (IMPORT_PARTS[$part][1] as $k) unset($m[$k]);
            $slugs = array_values(array_diff(array_column($models, 'slug'), $old ? [$old['slug']] : []));
            $base = sb_slugify($m['name']) . (!empty($m['majorVersion']) ? '-v' . $m['majorVersion'] : '');
            $slug = $base;
            for ($n = 2; in_array($slug, $slugs, true) || $slug === 'outfit'; $n++) $slug = $base . '-' . $n;
            $m['slug'] = $slug;
            $m['status'] = isset(M3D_STATUSES[$in['status'] ?? '']) ? (string) $in['status'] : 'released';
            $m['links'] = [['label' => 'SmutBase', 'url' => $p['url'], 'access' => ($in['access'] ?? '') === 'patrons' ? 'patrons' : 'public']];

            // pictures into media/<slug>/: the square catalog picture, then the gallery (the first one = main picture)
            $warn = [];
            $thumbSrc = $p['thumb'] !== '' ? $p['thumb'] : (string) ($in['thumb'] ?? '');
            if (in_array('pictures', $skip, true)) $thumbSrc = '';
            if ($thumbSrc !== '' && ($t = sb_copy_image(['full' => $thumbSrc], $slug, $warn)) !== '') $m['thumb'] = $t;
            $pics = [];
            foreach (in_array('pictures', $skip, true) ? [] : array_slice($p['images'], 0, 30) as $img) if (($u = sb_copy_image($img, $slug, $warn)) !== '') $pics[] = $u;
            if ($pics) { $m['cover'] = array_shift($pics); if ($pics) $m['gallery'] = $pics; }
            if (empty($m['thumb']) && !empty($m['cover'])) $m['thumb'] = $m['cover'];

            $item = clean_model($m, null);
            $models = m3d_models();   // fresh: pictures took a while
            $at = $old ? array_search($old['slug'], array_column($models, 'slug'), true) : false;
            if ($at !== false) {
                $item['order'] = $models[$at]['order'] ?? 0;
                if ($item['slug'] !== $old['slug']) m3d_rename_counts('models', $old['slug'], $item['slug']);
                $models[$at] = $item;
            } else {
                $item['order'] = max(array_map(fn($x) => (int) ($x['order'] ?? 0), $models) ?: [0]) + 1;
                $models[] = $item;
            }
            if (!m3d_save_items('models', $models)) throw new RuntimeException('Could not write the data file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.');
            admin_json(['ok' => true, 'replaced' => $at !== false, 'slug' => $item['slug'], 'name' => $item['name'], 'pictures' => count($pics) + (isset($item['cover']) ? 1 : 0),
                'videos' => $p['videos'], 'versions' => count($item['changelog'] ?? []), 'warnings' => array_values(array_unique($warn))]);
        }
        admin_json(['ok' => false, 'error' => 'Unknown action'], 400);
    } catch (SbPageMissing $ex) {
        admin_json(['ok' => false, 'error' => $ex->getMessage(), 'needPage' => true], 422);
    } catch (InvalidArgumentException $ex) {
        admin_json(['ok' => false, 'error' => $ex->getMessage()], 422);
    } catch (Throwable $ex) {
        error_log('[site import] ' . $ex->getMessage());
        admin_json(['ok' => false, 'error' => $ex->getMessage()], 500);
    }
}

admin_require($M3D);
$profile = (string) (m3d_settings()['import_profile'] ?? '');
admin_start('Import from SmutBase', 'models');
?>
    <div class="admin-head">
      <div>
        <h1>Import from SmutBase</h1>
        <p class="muted small"><a href="/admin/">← Models</a></p>
      </div>
    </div>

    <section class="sec import-sec" data-import>
      <h2 class="small-h">1. Find your projects<?= tip('The projects become <b>draft</b> models (only admins see them) with their description, features, sidebar sections, credits, changelog, pictures (copied to this site) and a “SmutBase” download link. Check each one in the editor, then make it public. Videos are not imported.') ?></h2>
      <form class="import-find" data-import-find>
        <label class="field"><span class="lbl">SmutBase profile</span>
          <input class="input" type="url" name="url" value="<?= e($profile) ?>" placeholder="https://smutba.se/user/10157/" required spellcheck="false" />
        </label>
        <button class="btn btn-primary">Find projects</button>
      </form>
      <p class="muted small import-or">or add the pages yourself<?= tip('When SmutBase doesn’t let the server in: open your profile (each page of it) and/or your project pages in the browser, press Ctrl+A (Mac ⌘A) to select the whole page, Ctrl+C to copy it, and paste it in the box. One page at a time; each one joins the list. Saved pages (Ctrl+S, “Webpage, HTML only”) can be uploaded instead, several at once.') ?></p>
      <textarea class="input import-paste" rows="2" placeholder="Paste a page here (Ctrl+V)" aria-label="Paste a SmutBase page" data-import-paste></textarea>
      <p class="muted small import-or"><label class="linklike">or upload saved pages<input type="file" accept=".html,.htm,text/html" multiple hidden data-import-files /></label> <span class="res-ok" data-import-added></span></p>
    </section>

    <section class="sec import-sec" data-import-list hidden>
      <h2 class="small-h">2. Choose what to import</h2>
      <div class="import-opts">
        <label class="field"><span class="lbl">Status</span>
          <select class="input" data-import-status><?php foreach (M3D_STATUSES as $k => [$label]): ?><option value="<?= e($k) ?>"><?= e($label) ?></option><?php endforeach ?></select>
        </label>
        <label class="field"><span class="lbl">“SmutBase” download link for<?= tip('The link to the SmutBase project page, added as the download. Change it any time in the editor.') ?></span>
          <select class="input" data-import-access><option value="public">Everyone</option><option value="patrons">Patrons</option></select>
        </label>
      </div>
      <div class="import-parts" data-import-parts><span class="lbl">What to import<?= tip('Untick what you’d rather write yourself. The name, franchise, Blender version, credits and the SmutBase link always come along.') ?></span>
        <?php foreach (IMPORT_PARTS as $k => [$label]): ?><label class="check"><input type="checkbox" value="<?= e($k) ?>" checked /> <span><?= e($label) ?></span></label><?php endforeach ?>
      </div>
      <div class="table-wrap"><table class="models-table import-table">
        <thead><tr><th class="c-check"><input type="checkbox" data-import-all aria-label="Select all new" /></th><th>Project on SmutBase</th><th>Franchise</th><th>Model</th><th>On this site</th></tr></thead>
        <tbody data-import-rows></tbody>
      </table></div>
      <div class="import-go"><button class="btn btn-primary" data-import-go disabled>Import selected</button> <span class="muted small" data-import-count></span></div>
    </section>
<?php admin_end();
