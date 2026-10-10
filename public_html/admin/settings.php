<?php
// Settings: site identity (name, tagline, logo, accent colour), social links (footer), maintenance tools (caches, sessions, logs, backups, unused images, temporary files) + system info.
require __DIR__ . '/_admin.php';
require_once dirname(__DIR__) . '/app/version.php';
admin_require($M3D);

/** Files matching a glob pattern: [count, bytes]. */
function files_stats(array $files): array
{
    $b = 0;
    foreach ($files as $f) $b += (int) @filesize($f);
    return [count($files), $b];
}

function human(int $b): string
{
    return $b >= 1073741824 ? sprintf('%.1f GB', $b / 1073741824) : ($b >= 1048576 ? sprintf('%.1f MB', $b / 1048576) : sprintf('%d KB', (int) ceil($b / 1024)));
}

function rglob(string $dir, callable $keep): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) if ($f->isFile() && $keep($f)) $out[] = $f->getPathname();
    return $out;
}

function session_files(): array
{
    $mine = 'sess_' . session_id();
    return array_values(array_filter(glob(M3D_PRIVATE . '/sessions/sess_*') ?: [], fn($f) => basename($f) !== $mine));
}

function temp_files(): array
{
    $isTmp = fn($f) => (bool) preg_match('/(\.tmp-|\.upd-|\.part$|^\.copy-|^\.import-|^\.crop-)/', $f->getFilename());
    return array_merge(rglob(M3D_SITE . '/media', $isTmp), rglob(M3D_PRIVATE . '/data', $isTmp), rglob(M3D_SITE, fn($f) => str_contains($f->getFilename(), '.upd-')));
}

/** Images in media/ not used by any model, outfit, FAQ or docs page (large + small files). */
function unused_images(): array
{
    $used = [];
    $all = json_encode([m3d_models(), m3d_outfits(), m3d_faq(), m3d_docs()], JSON_UNESCAPED_SLASHES);
    preg_match_all('#/media/[A-Za-z0-9/_.-]+#', (string) $all, $mm);
    foreach ($mm[0] as $u) { $used[$u] = true; $used[img_small($u)] = true; }
    return rglob(M3D_SITE . '/media', function ($f) use ($used) {
        if (!preg_match('/\.(webp|jpe?g|png|gif)$/i', $f->getFilename())) return false;
        $url = '/media/' . str_replace('\\', '/', substr($f->getPathname(), strlen(M3D_SITE . '/media') + 1));
        $large = preg_replace('/-sm(\.\w+)$/', '$1', $url);
        return !str_starts_with($url, '/media/site/') && !isset($used[$url]) && !isset($used[$large]);   // media/site: the logo (Site identity)
    });
}

/** Number of images in a list of media files: an image's large and "-sm" small files count once. */
function image_count(array $files): int
{
    return count(array_unique(array_map(fn($f) => preg_replace('/-sm(\.\w+)$/', '$1', (string) $f), $files)));
}

// ── Actions ──────────────────────────────────────────────────────────────────
$done = null;
$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $del = function (array $files): int { $n = 0; foreach ($files as $f) if (@unlink($f)) $n++; return $n; };
    switch ($_POST['do'] ?? '') {
        case 'opcache':
            $ok = function_exists('opcache_reset') && @opcache_reset();
            $done = $ok ? 'PHP cache cleared.' : 'PHP cache is not active on this server: nothing to clear.';
            break;
        case 'sessions':
            $done = $del(session_files()) . ' sessions ended. Everyone except you has to log in again.';
            break;
        case 'login-days':
            $d = (int) ($_POST['login_days'] ?? 0);
            if ($d < 1 || $d > 365) { $error = 'Choose between 1 and 365 days.'; break; }
            $was = intdiv(m3d_session_lifetime(), 86400);
            $done = m3d_save_settings(['login_days' => $d]) ? 'Logins now last ' . m3d_plural($d, 'day') . ($d < $was ? '. Logins older than that have ended.' : '.') : null;
            if (!$done) { $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.'; break; }
            $_SESSION['started'] = time();   // your own login doesn't end because of the change
            m3d_session_cleanup(true);
            break;
        case 'log':
            $f = M3D_PRIVATE . '/logs/downloads.csv';
            $done = is_file($f) && @unlink($f) ? 'Download log cleared.' : 'The download log was already empty.';
            break;
        case 'unused':
            $files = unused_images();
            $n = image_count(array_filter($files, 'is_file'));
            $del($files);
            $done = $n ? m3d_plural($n, 'unused image') . ' deleted.' : 'There were no unused images to delete.';
            break;
        case 'temp':
            $done = $del(temp_files()) . ' temporary files deleted.';
            break;
        case 'identity':
            try {
                $old = m3d_identity();
                $one = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
                $id = ['name' => $one('id_name', 40), 'highlight' => $one('id_highlight', 20), 'tagline' => $one('id_tagline', 90)];
                if ($id['name'] === '') throw new InvalidArgumentException('Write the name of the site.');
                if ($id['tagline'] === '') throw new InvalidArgumentException('Write a short line about the site (the tagline).');
                if ($id['highlight'] !== '' && mb_strpos($id['name'], $id['highlight']) === false) throw new InvalidArgumentException('The coloured part must be written exactly as in the name (“' . $id['name'] . '”), or left empty.');
                $color = strtolower(trim((string) ($_POST['id_color'] ?? '')));
                if (!preg_match('/^#[0-9a-f]{6}$/', $color)) throw new InvalidArgumentException('Choose the accent colour.');
                $id['color'] = $color;
                [$id['logo'], $id['icon']] = [$old['logo'], $old['icon']];
                $f = $_FILES['id_logo'] ?? null;
                if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'] ?? '')) throw new InvalidArgumentException('The logo did not arrive (it may be larger than the server allows: ' . ini_get('upload_max_filesize') . ').');
                    [$id['logo'], $id['icon']] = img_site_logo($f['tmp_name']);
                } elseif (!empty($_POST['id_nologo'])) {
                    [$id['logo'], $id['icon']] = ['', ''];
                }
                $store = $id;   // every value, so a later change of the built-in defaults never changes this site
                if (!m3d_save_settings(['identity' => $store])) throw new RuntimeException('Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.');
                if ($old['logo'] !== $id['logo']) img_site_logo_delete($old['logo'], $old['icon']);
                $done = 'Site identity saved. Open the site to see it (the browser may keep the old tab icon for a little while).';
            } catch (InvalidArgumentException | RuntimeException $ex) {
                $error = $ex->getMessage();
            }
            break;
        case 'social':
            try {
                $links = [];
                foreach ((array) ($_POST['social_type'] ?? []) as $i => $type) {
                    $url = trim((string) ($_POST['social_url'][$i] ?? ''));
                    if ($url === '') continue;
                    if (!isset(SOCIAL_TYPES[$type])) throw new InvalidArgumentException('Unknown link type.');
                    if ($type === 'email') {
                        $url = preg_replace('/^mailto:/i', '', $url);
                        if (!filter_var($url, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('"' . $url . '" is not an email address.');
                    } elseif (!preg_match('#^https://[^\s<>"]+$#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                        throw new InvalidArgumentException('The ' . SOCIAL_TYPES[$type][0] . ' link must be a full address starting with https://');
                    }
                    $links[] = ['type' => $type, 'url' => mb_substr($url, 0, 500)];
                }
                if (count($links) > 16) throw new InvalidArgumentException('16 links at most.');
                $done = m3d_save_settings(['social' => $links]) ? 'Links saved: ' . count($links) . ' shown in the footer.' : null;
                if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            } catch (InvalidArgumentException $ex) {
                $error = $ex->getMessage();
            }
            break;
        case 'maintenance':
            $on = ($_POST['state'] ?? '') === 'on';
            $msg = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['message'] ?? ''))), 0, 1000);
            $old = m3d_settings()['maintenance'] ?? [];
            $since = $on ? (!empty($old['on']) ? (string) ($old['since'] ?? gmdate('c')) : gmdate('c')) : '';
            $done = m3d_save_settings(['maintenance' => ['on' => $on, 'message' => $msg, 'since' => $since]])
                ? ($on ? 'Maintenance mode is on: visitors now see the “Back soon” page. You can still use the site.' : 'Maintenance mode is off: the site is open again.')
                : null;
            if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            break;
        case 'notice':
            $n = [
                'on'       => !empty($_POST['notice_on']),
                'type'     => isset(NOTICE_TYPES[$_POST['notice_type'] ?? '']) ? (string) $_POST['notice_type'] : 'info',
                'text'     => mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['notice_text'] ?? ''))), 0, 600),
                'where'    => ($_POST['notice_where'] ?? '') === 'home' ? 'home' : 'all',
                'until'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['notice_until'] ?? '')) ? (string) $_POST['notice_until'] : '',
                'closable' => !empty($_POST['notice_closable']),
            ];
            if ($n['on'] && $n['text'] === '') { $error = 'Write the message, or untick “Show the notice”.'; break; }
            $done = m3d_save_settings(['notice' => $n]) ? ($n['on'] ? 'Notice saved: it is shown on ' . ($n['where'] === 'home' ? 'the homepage' : 'every page') . ($n['until'] !== '' ? ' until ' . date_create_immutable($n['until'])->format('j F Y') : '') . '.' : 'Notice saved and hidden.') : null;
            if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            break;
        case 'patreon':
            try {
                $saved = m3d_patreon_saved();
                $in = fn(string $k) => trim((string) ($_POST['pt_' . $k] ?? ''));
                $new = ['client_id' => $in('client_id'), 'redirect_uri' => $in('redirect_uri'), 'campaign_id' => $in('campaign_id')];
                // the secret is never sent back to the page: an empty field keeps the saved one
                $new['client_secret'] = $in('client_secret') !== '' ? $in('client_secret') : (string) ($saved['client_secret'] ?? '');
                foreach (['client_id' => 'Client ID', 'client_secret' => 'Client secret'] as $k => $label) {
                    if ($new[$k] !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $new[$k])) throw new InvalidArgumentException("The $label doesn’t look right: copy it again from your Patreon client page (letters, numbers, - and _ only).");
                }
                if ($new['redirect_uri'] !== '' && (!preg_match('#^https?://[^\s<>"]+/auth/callback\.php$#i', $new['redirect_uri']) || !filter_var($new['redirect_uri'], FILTER_VALIDATE_URL))) {
                    throw new InvalidArgumentException('The redirect address must be this site’s address followed by /auth/callback.php, e.g. ' . abs_url('/auth/callback.php'));
                }
                if ($new['campaign_id'] !== '' && !ctype_digit($new['campaign_id'])) throw new InvalidArgumentException('The campaign ID is a number (digits only).');
                // what equals config.php isn't stored, so config.php keeps counting for it
                $cfg = require M3D_PRIVATE . '/config.php';
                $store = array_filter($new, fn($v, $k) => $v !== '' && $v !== (string) ($cfg[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
                if (!m3d_patreon_save($store)) throw new RuntimeException('Could not write ' . basename(M3D_PRIVATE) . '/patreon.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.');
                $done = 'Patreon keys saved. Test the login in a private window before you log out here: if it fails, fix the keys or use “Go back to config.php”.';
            } catch (InvalidArgumentException | RuntimeException $ex) {
                $error = $ex->getMessage();
            }
            break;
        case 'patreon-reset':
            $done = m3d_patreon_save([]) ? 'The Patreon keys saved here are deleted: the values of config.php count again.' : null;
            if (!$done) $error = 'Could not delete ' . basename(M3D_PRIVATE) . '/patreon.php.';
            break;
        case 'plans':
            $one = fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
            $boxes = [];
            foreach ([0, 1] as $i) {
                $boxes[] = [
                    'tag' => $one("pl_tag_$i", 40), 'title' => $one("pl_title_$i", 80), 'text' => $one("pl_text_$i", 600),
                    'items' => array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim($l), 0, 160), preg_split('/\R/', (string) ($_POST["pl_items_$i"] ?? ''))), fn($l) => $l !== '')), 0, 10),
                    'buttons' => array_values(array_intersect(array_keys(PLAN_BUTTONS), (array) ($_POST["pl_btn_$i"] ?? []))),
                    'fine' => $one("pl_fine_$i", 300),
                ];
            }
            $plans = ['on' => !empty($_POST['pl_on']), 'eyebrow' => $one('pl_eyebrow', 40), 'title' => $one('pl_head', 80), 'boxes' => $boxes];
            $done = m3d_save_settings(['plans' => $plans]) ? ($plans['on'] ? 'Homepage section saved: visitors who can’t download yet see it.' : 'Homepage section saved and hidden.') : null;
            if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            break;
        case 'legal':
            $one = fn(string $k) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 120);
            $lg = ['name' => $one('legal_name'), 'address' => mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['legal_address'] ?? ''))), 0, 300), 'email' => $one('legal_email')];
            $sup = $one('support_email');
            if (($lg['email'] !== '' && !filter_var($lg['email'], FILTER_VALIDATE_EMAIL)) || ($sup !== '' && !filter_var($sup, FILTER_VALIDATE_EMAIL))) { $error = 'Check the email addresses.'; break; }
            $done = m3d_save_settings(['legal' => $lg, 'support_email' => $sup]) ? 'Legal details and support email saved.' : null;
            if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            break;
        case 'smutbase':
            $on = !empty($_POST['smutbase_on']);
            $done = m3d_save_settings(['smutbase' => ['on' => $on]]) ? ($on ? 'SmutBase import is on: the button is on Admin → Models.' : 'SmutBase import is off.') : null;
            if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            break;
        case 'mustardui':
            $on = !empty($_POST['mustardui_on']);
            $done = m3d_save_settings(['mustardui' => ['on' => $on]]) ? ($on ? 'The MustardUI page is on: it is in the menu and the footer.' : 'The MustardUI page is off: no menu item, and /mustardui/ shows “not found”.') : null;
            if (!$done) $error = 'Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.';
            break;
        case 'currency':
            $c = strtoupper((string) ($_POST['currency'] ?? ''));
            if (!preg_match('/^[A-Z]{3}$/', $c)) { $error = 'Choose a currency.'; break; }
            $done = m3d_save_settings(['currency' => $c]) ? "Currency set to $c." : null;
            break;
        case 'identity-reset':
            $old = m3d_identity();
            $set = m3d_settings(true);
            unset($set['identity']);
            $done = m3d_write_json('settings', ['version' => 1, 'updated' => gmdate('c')] + array_diff_key($set, ['version' => 1, 'updated' => 1])) ? 'Site identity reset to the default.' : null;
            m3d_settings(true);
            if ($done) img_site_logo_delete($old['logo'], $old['icon']);
            break;
    }
}

m3d_session_cleanup(true);   // expired logins are deleted, so the count is only the live ones
[$sessN, $sessB] = files_stats(session_files());
$loginDays = intdiv(m3d_session_lifetime(), 86400);
$logFile = M3D_PRIVATE . '/logs/downloads.csv';
$logLines = is_file($logFile) ? count(file($logFile, FILE_SKIP_EMPTY_LINES)) : 0;
$unFiles = unused_images();
[, $unB] = files_stats($unFiles);
$unN = image_count($unFiles);
[, $bkB] = files_stats(glob(M3D_PRIVATE . '/backups/*') ?: []);
[$tmpN, $tmpB] = files_stats(temp_files());
[$medN, $medB] = files_stats(rglob(M3D_SITE . '/media', fn($f) => $f->getFilename() !== '.htaccess'));
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$free = @disk_free_space(M3D_SITE);
$checks = [
    ['Site version', M3D_VERSION, true],
    ['PHP version', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=')],
    ['Max upload size', ini_get('upload_max_filesize') . ' (form limit ' . ini_get('post_max_size') . ')', true],
    ['Memory limit', ini_get('memory_limit') === '-1' ? 'no limit' : ini_get('memory_limit'), true],
    ['WebP image support', img_webp() ? 'yes' : 'no (JPG fallback)', img_webp()],
    ['Zip support (updates)', class_exists('ZipArchive') ? 'yes' : 'no', class_exists('ZipArchive')],
    ['Data folder writable', is_writable(M3D_PRIVATE . '/data') ? 'yes' : 'no', is_writable(M3D_PRIVATE . '/data')],
    ['Media folder writable', is_dir(M3D_SITE . '/media') && is_writable(M3D_SITE . '/media') ? 'yes' : 'no', is_dir(M3D_SITE . '/media') && is_writable(M3D_SITE . '/media')],
    ['Images in media', $medN . ' files, ' . human($medB), true],
    ['Free disk space', $free ? human((int) $free) : 'unknown', true],
];

$action = function (string $do, string $label, string $confirm = '', bool $disabled = false): string {
    return '<form method="post"' . ($confirm ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '') . '>'
        . '<input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '" /><input type="hidden" name="do" value="' . e($do) . '" />'
        . '<button class="btn btn-ghost btn-sm"' . ($disabled ? ' disabled' : '') . '>' . e($label) . '</button></form>';
};

$social = m3d_social();

admin_start('Settings', 'settings');
?>
    <div class="admin-head">
      <div>
        <h1>Settings</h1>
      </div>
    </div>
    <?= settings_menu() ?>

    <?php if ($done): ?><p class="notice"><b class="res-ok">✓</b> <?= e($done) ?></p><?php endif ?>
    <?php if ($error): ?><p class="notice"><b class="res-err">Not saved:</b> <?= e($error) ?></p><?php endif ?>

    <h2 class="set-group">Your site</h2>
    <?php $id = m3d_identity(); $idChanged = !empty(m3d_settings()['identity']); ?>
    <section class="sec" id="identity">
      <h2 class="small-h">Site identity<?= tip('The name, logo and colour shown everywhere on the site: header, footer, browser tab, link previews and page titles.') ?></h2>
      <form method="post" enctype="multipart/form-data" class="id-form" data-identity-form>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="identity" />
        <div class="id-preview" aria-hidden="true" data-id-preview style="--mustard: <?= e($id['color']) ?>; --mustard-ink: <?= e(accent_ink($id['color'])) ?>">
          <span class="brand"><?= brand() ?></span>
          <span class="btn btn-primary btn-sm">Button</span>
          <span class="id-prev-line"><?= e($id['tagline']) ?>.</span>
        </div>
        <div class="id-grid">
          <label class="field"><span class="lbl">Site name</span><input class="input" name="id_name" value="<?= e($id['name']) ?>" maxlength="40" required data-id="name" /></label>
          <label class="field"><span class="lbl">Part in colour<?= tip('Written exactly as in the name, e.g. “Studio” in “PixelStudio”. Leave it empty for a name all in one colour.') ?></span><input class="input" name="id_highlight" value="<?= e($id['highlight']) ?>" maxlength="20" data-id="highlight" /></label>
          <label class="field id-wide"><span class="lbl">Tagline<?= tip('One short line: the homepage title (“' . $id['name'] . ' · …”), the line under the logo in the footer, and the description of pages that have none.') ?></span><input class="input" name="id_tagline" value="<?= e($id['tagline']) ?>" maxlength="90" required data-id="tagline" /></label>
          <div class="field"><span class="lbl">Accent colour<?= tip('Buttons, active filters, links and highlights. The text on buttons turns dark or white by itself, so it stays readable.') ?></span>
            <span class="id-color"><input type="color" name="id_color" value="<?= e($id['color']) ?>" data-id="color" aria-label="Accent colour" /><code data-id-hex><?= e($id['color']) ?></code>
              <?php if ($id['color'] !== IDENTITY_DEFAULTS['color']): ?><button type="button" class="linklike small muted" data-id-color-reset="<?= e(IDENTITY_DEFAULTS['color']) ?>">Default colour</button><?php endif ?></span>
          </div>
          <div class="field"><span class="lbl">Logo<?= tip('Shown left of the name and used as the browser-tab and phone icon. A square picture with a transparent background works best (PNG, at least 192 px).') ?></span>
            <span class="id-logo">
              <label class="btn btn-ghost btn-sm">Upload…<input type="file" name="id_logo" accept="image/png,image/jpeg,image/webp,image/gif" hidden data-id="logo" /></label>
              <?php if ($id['logo'] !== ''): ?><label class="check small"><input type="checkbox" name="id_nologo" value="1" data-id="nologo" /> No logo</label><?php endif ?>
            </span>
          </div>
        </div>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save identity</button></div>
      </form>
      <?php if ($idChanged): ?>
        <form method="post" onsubmit="return confirm('Go back to the default identity (a neutral name and tagline, the default colour, no logo)? Your current name, logo and colour are removed.')">
          <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="identity-reset" />
          <button class="linklike small muted">Reset to the default identity</button>
        </form>
      <?php endif ?>
    </section>

    <section style="margin-top:20px" class="sec" id="social">
      <h2 class="small-h">Social links<?= tip('The footer buttons, in this order. The Patreon, Discord and X addresses are also used by the links around the site.') ?></h2>
      <form method="post" class="social-form" data-social-form>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="social" />
        <div class="social-rows" data-social-rows>
          <?php foreach ($social as $l): ?>
            <div class="social-row" data-social-row>
              <span class="social-prev" data-social-icon><?= social_icon($l['type']) ?></span>
              <select class="input" name="social_type[]" aria-label="Network" data-social-type>
                <?php foreach (SOCIAL_TYPES as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === $l['type'] ? 'selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach ?>
              </select>
              <input class="input" type="text" name="social_url[]" value="<?= e($l['url']) ?>" placeholder="https://…" aria-label="Address" spellcheck="false" />
              <span class="row-btns">
                <button type="button" class="drag-handle drag-sm" data-social-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>
                <button type="button" class="icon-btn del" data-social-del title="Remove" aria-label="Remove">✕</button>
              </span>
            </div>
          <?php endforeach ?>
        </div>
        <template data-social-template>
          <div class="social-row" data-social-row>
            <span class="social-prev" data-social-icon><?= social_icon('website') ?></span>
            <select class="input" name="social_type[]" aria-label="Network" data-social-type>
              <?php foreach (SOCIAL_TYPES as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === 'website' ? 'selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach ?>
            </select>
            <input class="input" type="text" name="social_url[]" value="" placeholder="https://…" aria-label="Address" spellcheck="false" />
            <span class="row-btns">
              <button type="button" class="drag-handle drag-sm" data-social-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>
              <button type="button" class="icon-btn del" data-social-del title="Remove" aria-label="Remove">✕</button>
            </span>
          </div>
        </template>
        <script type="application/json" data-social-icons><?= json_encode(array_map(fn($k) => social_icon($k), array_combine(array_keys(SOCIAL_TYPES), array_keys(SOCIAL_TYPES))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
        <div class="social-actions">
          <button type="button" class="btn btn-ghost btn-sm" data-social-add>+ Add link</button>
          <button class="btn btn-primary btn-sm">Save links</button>
        </div>
      </form>
    </section>

    <?php $pl = home_plans(); ?>
    <section style="margin-top:20px" class="sec" id="homepage">
      <h2 class="small-h">Homepage section <span class="badge tone-<?= $pl['on'] ? 'green' : 'grey' ?>"><?= $pl['on'] ? 'Shown' : 'Hidden' ?></span><?= tip('The two boxes at the bottom of the homepage that explain how to get the downloads (e.g. free releases and the Patreon membership). Patrons who can already download never see them.') ?></h2>
      <form method="post" class="pl-form">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="plans" />
        <label class="field check"><input type="checkbox" name="pl_on" value="1" <?= $pl['on'] ? 'checked' : '' ?> /><span class="lbl">Show the section</span></label>
        <div class="id-grid">
          <label class="field"><span class="lbl">Small line above the title</span><input class="input" name="pl_eyebrow" value="<?= e($pl['eyebrow']) ?>" maxlength="40" placeholder="Downloads" /></label>
          <label class="field"><span class="lbl">Title</span><input class="input" name="pl_head" value="<?= e($pl['title']) ?>" maxlength="80" placeholder="How to get the models" /></label>
        </div>
        <div class="pl-boxes">
          <?php foreach ($pl['boxes'] as $i => $b): ?>
            <fieldset class="pl-box<?= $i === 1 ? ' pl-hot' : '' ?>">
              <legend><?= $i === 0 ? 'First box' : 'Second box (highlighted)' ?></legend>
              <label class="field"><span class="lbl">Label</span><input class="input" name="pl_tag_<?= $i ?>" value="<?= e($b['tag']) ?>" maxlength="40" placeholder="<?= $i ? 'Membership' : 'Open access' ?>" /></label>
              <label class="field"><span class="lbl">Title</span><input class="input" name="pl_title_<?= $i ?>" value="<?= e($b['title']) ?>" maxlength="80" placeholder="<?= $i ? 'Every download' : 'Free releases' ?>" /></label>
              <label class="field"><span class="lbl">Text<?= tip('Takes **bold**, *italic* and [links](https://…).') ?></span><textarea class="input" name="pl_text_<?= $i ?>" rows="3" maxlength="600"><?= e($b['text']) ?></textarea></label>
              <label class="field"><span class="lbl">Points (one per line)</span><textarea class="input" name="pl_items_<?= $i ?>" rows="3"><?= e(implode("
", $b['items'])) ?></textarea></label>
              <div class="field"><span class="lbl">Buttons<?= tip('Discord and Patreon use the addresses of Settings → Social links (a network that isn’t set is left out). “Log in” opens the Patreon login.') ?></span>
                <span class="pl-btns"><?php foreach (PLAN_BUTTONS as $k => $label): ?><label class="check small"><input type="checkbox" name="pl_btn_<?= $i ?>[]" value="<?= e($k) ?>" <?= in_array($k, $b['buttons'], true) ? 'checked' : '' ?> /> <?= e($label) ?></label><?php endforeach ?></span>
              </div>
              <label class="field"><span class="lbl">Small print (optional)</span><input class="input" name="pl_fine_<?= $i ?>" value="<?= e($b['fine']) ?>" maxlength="300" /></label>
            </fieldset>
          <?php endforeach ?>
        </div>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save section</button><a class="btn btn-ghost btn-sm" href="/#access" target="_blank">View on the homepage ↗</a></div>
      </form>
    </section>

    <?php $notice = (m3d_settings()['notice'] ?? []) + ['on' => false, 'type' => 'info', 'text' => '', 'where' => 'all', 'until' => '', 'closable' => true];
          $live = m3d_notice() !== null; ?>
    <section style="margin-top:20px" class="sec" id="notice">
      <h2 class="small-h">Site notice <span class="badge tone-<?= $live ? 'green' : 'grey' ?>"><?= $live ? 'Shown' : ($notice['on'] && $notice['text'] !== '' ? 'Ended' : 'Off') ?></span></h2>
      <form method="post" class="notice-form">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="notice" />
        <label class="field check"><input type="checkbox" name="notice_on" value="1" <?= $notice['on'] ? 'checked' : '' ?> /><span class="lbl">Show the notice</span></label>
        <div class="notice-grid">
          <label class="field"><span class="lbl">Type</span>
            <select class="input" name="notice_type"><?php foreach (NOTICE_TYPES as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $notice['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
          </label>
          <label class="field"><span class="lbl">Where</span>
            <select class="input" name="notice_where"><option value="all">Every page</option><option value="home" <?= $notice['where'] === 'home' ? 'selected' : '' ?>>Homepage only</option></select>
          </label>
          <label class="field"><span class="lbl">Last day (optional)</span><input class="input" type="date" name="notice_until" value="<?= e($notice['until']) ?>" /></label>
        </div>
        <label class="field"><span class="lbl">Message<?= tip('Takes **bold**, *italic* and [links](https://…).') ?></span>
          <textarea class="input" name="notice_text" rows="2" maxlength="600" placeholder="**New:** a new model is out for patrons! [See it](/models/your-model/)"><?= e($notice['text']) ?></textarea>
        </label>
        <label class="field check"><input type="checkbox" name="notice_closable" value="1" <?= $notice['closable'] ? 'checked' : '' ?> /><span class="lbl">Visitors can close it<?= tip('It comes back when you change the message.') ?></span></label>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save notice</button></div>
      </form>
      <?php if ($notice['text'] !== ''): ?>
        <p class="lbl notice-prev-lbl">Preview</p>
        <div class="notice-prev"><?= notice_html($notice, true) ?></div>
      <?php endif ?>
    </section>

    <?php $lg = m3d_legal(); ?>
    <section style="margin-top:20px" class="sec" id="legal">
      <h2 class="small-h">Legal &amp; contact<?= tip('Who runs the site, shown on the privacy page (required in the EU), and the support email shown on the Contacts page for website problems. Empty fields fall back to config.php.') ?></h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="legal" />
        <div class="id-grid">
          <label class="field"><span class="lbl">Full name</span><input class="input" name="legal_name" value="<?= e($lg['name']) ?>" maxlength="120" /></label>
          <label class="field"><span class="lbl">Email (privacy page)</span><input class="input" type="email" name="legal_email" value="<?= e($lg['email']) ?>" maxlength="120" /></label>
          <label class="field id-wide"><span class="lbl">Postal address</span><textarea class="input" name="legal_address" rows="3" maxlength="300"><?= e($lg['address']) ?></textarea></label>
          <label class="field id-wide"><span class="lbl">Support email (Contacts page)</span><input class="input" type="email" name="support_email" value="<?= e(m3d_support_email()) ?>" maxlength="120" /></label>
        </div>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save</button><a class="btn btn-ghost btn-sm" href="/privacy/" target="_blank">View the privacy page ↗</a></div>
      </form>
    </section>

    <h2 class="set-group">Patreon &amp; downloads</h2>
    <?php $cfg = require M3D_PRIVATE . '/config.php'; $ptSaved = m3d_patreon_saved(); $pt = array_merge($cfg, $ptSaved);
          $ptVal = fn(string $k) => (string) ($pt[$k] ?? '');
          $ptReal = fn(string $k) => $ptVal($k) !== '' && !str_starts_with($ptVal($k), 'PASTE_') && $ptVal($k) !== '0000000' && !str_contains($ptVal($k), 'xyz.xy');
          $ptFrom = fn(string $k) => isset($ptSaved[$k]) ? '<span class="badge tone-green">Saved here</span>' : ($ptReal($k) ? '<span class="badge tone-grey">From config.php</span>' : '<span class="badge tone-red">Missing</span>');
          $callback = abs_url('/auth/callback.php'); ?>
    <section class="sec" id="patreon">
      <h2 class="small-h">Patreon<?= tip('The Patreon client this site logs in with, your campaign, and its currency. The keys come from config.php until you save them here.') ?></h2>
      <form method="post" class="pt-form" autocomplete="off" onsubmit="return confirm(<?= e(json_encode('Save the Patreon keys? If they are wrong, nobody can log in (you stay logged in here). Test in a private window afterwards.')) ?>)">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="patreon" />
        <div class="id-grid">
          <label class="field"><span class="lbl">Client ID <?= $ptFrom('client_id') ?><?= tip('From patreon.com/portal/registration/register-clients → your client → “Client ID”.') ?></span>
            <input class="input" name="pt_client_id" value="<?= e($ptReal('client_id') ? $ptVal('client_id') : '') ?>" spellcheck="false" autocomplete="off" /></label>
          <label class="field"><span class="lbl">Client secret <?= $ptFrom('client_secret') ?><?= tip('Same page, “Client Secret”. It is never shown again here: leave the field empty to keep the current one.') ?></span>
            <input class="input" type="password" name="pt_client_secret" value="" placeholder="<?= $ptReal('client_secret') ? '•••••••• (leave empty to keep it)' : 'Paste the client secret' ?>" spellcheck="false" autocomplete="new-password" /></label>
          <label class="field id-wide"><span class="lbl">Redirect address <?= $ptFrom('redirect_uri') ?><?= tip('Must be written EXACTLY as one of the “Redirect URIs” of your Patreon client.') ?></span>
            <span class="pt-row"><input class="input" name="pt_redirect_uri" value="<?= e($ptReal('redirect_uri') ? $ptVal('redirect_uri') : '') ?>" placeholder="<?= e($callback) ?>" spellcheck="false" data-pt-redirect />
              <?php if ($ptVal('redirect_uri') !== $callback): ?><button type="button" class="btn btn-ghost btn-sm" data-pt-use="<?= e($callback) ?>" title="<?= e($callback) ?>">Use this site’s address</button><?php endif ?></span></label>
          <label class="field"><span class="lbl">Campaign ID <?= $ptFrom('campaign_id') ?><?= tip('The number of your Patreon campaign: only its paying members can download.') ?></span>
            <input class="input" name="pt_campaign_id" value="<?= e($ptReal('campaign_id') ? $ptVal('campaign_id') : '') ?>" inputmode="numeric" spellcheck="false" /></label>
        </div>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save Patreon keys</button></div>
      </form>
      <?php if ($ptSaved): ?>
        <form method="post" onsubmit="return confirm('Delete the keys saved here and use the ones in config.php again?')">
          <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="patreon-reset" />
          <button class="linklike small muted">Go back to config.php</button>
        </form>
      <?php endif ?>
      <form method="post" class="cur-form pt-cur">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="currency" />
        <label class="field"><span class="lbl">Campaign currency<?= tip('For the “total support” requirements. Set automatically when a patron logs in.') ?></span>
          <select class="input" name="currency">
            <?php foreach (['EUR' => 'Euro (€)', 'USD' => 'US dollar ($)', 'GBP' => 'British pound (£)', 'CAD' => 'Canadian dollar', 'AUD' => 'Australian dollar', 'JPY' => 'Japanese yen'] as $k => $l): ?><option value="<?= $k ?>" <?= m3d_currency() === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
          </select>
        </label>
        <button class="btn btn-ghost btn-sm">Save</button>
      </form>
    </section>

    <h2 class="set-group">Optional features</h2>
    <?php $muOn = m3d_mustardui_on(); ?>
    <section class="sec" id="mustardui">
      <h2 class="small-h">MustardUI <span class="badge tone-<?= $muOn ? 'green' : 'grey' ?>"><?= $muOn ? 'On' : 'Off' ?></span><?= tip('A ready-made page about MustardUI, the free Blender add-on: what it does, where to download it, its guides and bug reports. Turn it on if your models use it.') ?></h2>
      <form method="post" class="mu-form">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="mustardui" />
        <label class="field check"><input type="checkbox" name="mustardui_on" value="1" <?= $muOn ? 'checked' : '' ?> /><span class="lbl">Show the MustardUI page<?= tip('Adds “MustardUI” to the menu and the footer, and opens /mustardui/. Off: neither, and the address shows “not found”.') ?></span></label>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save</button><?php if ($muOn): ?><a class="btn btn-ghost btn-sm" href="/mustardui/" target="_blank">View the page ↗</a><?php endif ?></div>
      </form>
    </section>

    <?php $sbOn = m3d_smutbase_on(); ?>
    <section style="margin-top:20px" class="sec" id="smutbase">
      <h2 class="small-h">SmutBase import <span class="badge tone-<?= $sbOn ? 'green' : 'grey' ?>"><?= $sbOn ? 'On' : 'Off' ?></span><?= tip('Turns the models of a SmutBase profile into draft models here (texts, changelog, pictures). Only useful if you publish on SmutBase.') ?></h2>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="smutbase" />
        <label class="field check"><input type="checkbox" name="smutbase_on" value="1" <?= $sbOn ? 'checked' : '' ?> /><span class="lbl">Show “Import from SmutBase” on Admin → Models</span></label>
        <div class="social-actions"><button class="btn btn-primary btn-sm">Save</button><?php if ($sbOn): ?><a class="btn btn-ghost btn-sm" href="/admin/import.php">Open the import</a><?php endif ?></div>
      </form>
    </section>

    <h2 class="set-group">Maintenance</h2>
    <?php $maint = m3d_settings()['maintenance'] ?? []; $maintOn = !empty($maint['on']); ?>
    <section class="sec maint-sec<?= $maintOn ? ' is-on' : '' ?>" id="maintenance">
      <h2 class="small-h">Maintenance mode <span class="badge tone-<?= $maintOn ? 'red' : 'grey' ?>"><?= $maintOn ? 'On' : 'Off' ?></span><?= tip('Visitors see a “Back soon” page and can’t download. Admins can still log in and use the site.') ?></h2>
      <?php if ($maintOn && !empty($maint['since']) && ($d = date_create_immutable($maint['since']))): ?><p class="muted small sec-hint">On since <?= e($d->setTimezone(new DateTimeZone('Europe/Berlin'))->format('j F Y, H:i')) ?>.</p><?php endif ?>
      <form method="post" class="maint-form"<?= $maintOn ? '' : ' onsubmit="return confirm(' . e(json_encode('Turn on maintenance mode? Visitors will see the “Back soon” page until you turn it off.')) . ')"' ?>>
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="maintenance" />
        <label class="field"><span class="lbl">Message for visitors (optional)</span>
          <textarea class="input" name="message" rows="2" maxlength="1000" placeholder="The website is being updated. Please come back in a little while."><?= e($maint['message'] ?? '') ?></textarea>
        </label>
        <div class="social-actions">
          <?php if ($maintOn): ?>
            <button class="btn btn-ghost btn-sm" name="state" value="on">Save message</button>
            <button class="btn btn-primary btn-sm" name="state" value="off">Turn off maintenance mode</button>
          <?php else: ?>
            <button class="btn btn-danger btn-sm" name="state" value="on">Turn on maintenance mode</button>
          <?php endif ?>
          <a class="btn btn-ghost btn-sm" href="/" >View site</a>
        </div>
      </form>
    </section>

    <section style="margin-top:20px" class="sec" id="tools">
      <h2 class="small-h">Tools</h2>
      <div class="tools">
        <div class="tool">
          <div><b>PHP cache</b><?= tip('Clear it if a change made with File Manager doesn’t show up.') ?><?php if (!$opcache): ?><p class="muted small">Not active on this server.</p><?php endif ?></div>
          <?= $action('opcache', 'Clear PHP cache') ?>
        </div>
        <div class="tool">
          <div><b>Stay logged in</b><?= tip('How long a Patreon login lasts on the site. After that, patrons log in again (one click: Patreon sends them straight back). Ended logins are deleted from the server.') ?></div>
          <form method="post" class="days-form">
            <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="login-days" />
            <input class="input" type="number" name="login_days" min="1" max="365" step="1" value="<?= $loginDays ?>" aria-label="Days" required /><span class="muted small">days</span>
            <button class="btn btn-ghost btn-sm">Save</button>
          </form>
        </div>
        <div class="tool">
          <div><b>Logged-in visitors</b><p class="muted small"><?= $sessN ?> other active <?= $sessN === 1 ? 'session' : 'sessions' ?> (<?= human($sessB) ?>).</p></div>
          <?= $action('sessions', 'Log everyone out', 'Log out all visitors except you?', !$sessN) ?>
        </div>
        <div class="tool">
          <div><b>Download log</b><?= tip('Clearing it resets everyone’s unlocks: their limits start from zero.') ?><p class="muted small"><?= $logLines ?> entries, kept <?= (int) ($M3D['log_retention_days'] ?? 365) ?> days. <a href="/admin/log.php?csv=1">Export a copy first</a>.</p></div>
          <?= $action('log', 'Clear download log', 'Delete the whole download log? Every patron\'s download history and unlocks go with it, so their limits start from zero. Export it first if you want to keep a copy.', !$logLines) ?>
        </div>
        <div class="tool">
          <div><b>Backups</b><p class="muted small"><?= human($bkB) ?> of backups.</p></div>
          <a class="btn btn-ghost btn-sm" href="/admin/backups.php">Open Backups</a>
        </div>
        <div class="tool">
          <div><b>Unused images</b><p class="muted small"><?= m3d_plural($unN, 'image') ?> not used anywhere (<?= human($unB) ?>). <a href="/admin/images.php?unused=1">Review them in Images</a>.</p></div>
          <?= $action('unused', 'Delete unused images', 'Delete all images that are not used anywhere? This can\'t be undone.', !$unN) ?>
        </div>
        <div class="tool">
          <div><b>Temporary files</b><p class="muted small"><?= $tmpN ?> leftovers from interrupted uploads (<?= human($tmpB) ?>).</p></div>
          <?= $action('temp', 'Delete temporary files', '', !$tmpN) ?>
        </div>
        <div class="tool">
          <div><b>Cloudflare cache</b><?= tip('If a replaced image still shows the old version, purge it in Cloudflare: Caching → Configuration → Purge Everything.') ?><p class="muted small">For images that still show the old version.</p></div>
          <a class="btn btn-ghost btn-sm" href="https://dash.cloudflare.com/" target="_blank" rel="noopener">Open Cloudflare ↗</a>
        </div>
      </div>
    </section>

    <section style="margin-top:20px" class="sec" id="system">
      <h2 class="small-h">System</h2>
      <dl class="sysinfo">
        <?php foreach ($checks as [$k, $v, $ok]): ?><dt><?= e($k) ?></dt><dd class="<?= $ok ? '' : 'res-err' ?>"><?= e($v) ?><?= $ok ? '' : ' ⚠' ?></dd><?php endforeach ?>
      </dl>
    </section>

<?php admin_end();
