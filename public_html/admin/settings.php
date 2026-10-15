<?php
// Settings, in tabs (?tab=, SETTINGS_TABS in _admin.php): Site (identity, social links, homepage section, notice, legal), Patreon (keys, currency,
// how long logins last), Features (optional pages and tools), Maintenance (maintenance mode, tools: caches, sessions, log, backups, unused images,
// temporary files; system info). Every form posts to the page's own address, so a save comes back on the same tab.
require __DIR__ . '/_admin.php';
require_once dirname(__DIR__) . '/app/version.php';
admin_require($M3D);

// Features that are only switched on or off (settings.json → <key>.on): title, its help, switch label, link shown while on, messages (on, off)
const FEATURE_SWITCHES = [
    'mustardui' => ['MustardUI', 'Ready-made page about MustardUI, the free Blender add-on: features, download, guides and bug reports. Turn on if your models use it.',
        'Enable MustardUI Page',
        ['/mustardui/', 'View the Page ↗', true],
        'The MustardUI page is on: it is in the menu and the footer.', 'The MustardUI page is off: no menu item, and /mustardui/ shows “not found”.'],
    'smutbase'  => ['SmutBase Import', 'Imports the models of a SmutBase profile as draft models (texts, changelog, pictures). Only useful if you publish on SmutBase.',
        'Enable SmutBase Import',
        ['/admin/import.php', 'Open the Import', false],
        'SmutBase import is on: the button is on Admin → Models.', 'SmutBase import is off.'],
];

/** Files matching a glob pattern: [count, bytes]. */
function files_stats(array $files): array
{
    $b = 0;
    foreach ($files as $f) $b += (int) @filesize($f);
    return [count($files), $b];
}


/** Files under $dir that $keep accepts; folders $skipDir accepts (a SplFileInfo) are not walked into, nor unreadable ones. */
function rglob(string $dir, callable $keep, ?callable $skipDir = null): array
{
    if (!is_dir($dir)) return [];
    $out = [];
    $inner = new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
    if ($skipDir) $inner = new RecursiveCallbackFilterIterator($inner, fn($f) => !($f->isDir() && $skipDir($f)));
    $it = new RecursiveIteratorIterator($inner, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    foreach ($it as $f) {
        if ($f->isFile() && $keep($f)) $out[] = $f->getPathname();
        if (defined('STATS_UNTIL') && microtime(true) > STATS_UNTIL) throw new RuntimeException('too many files to count in time');
    }
    return $out;
}

/** Every file in media/ (one walk, shared by the counts below). */
function media_all(): array
{
    return rglob(M3D_SITE . '/media', fn($f) => $f->getFilename() !== '.htaccess');
}

function session_files(): array
{
    $mine = 'sess_' . session_id();
    return array_values(array_filter(glob(M3D_PRIVATE . '/sessions/sess_*') ?: [], fn($f) => basename($f) !== $mine));
}

function temp_files(?array $media = null): array
{
    $isTmp = fn($n) => (bool) preg_match('/(\.tmp-|\.upd-|\.part$|^\.copy-|^\.import-|^\.crop-)/', $n);
    // the code folders: media/ is checked above, and a folder holding another site (a subdomain's copy) is not this site's
    $other = fn($f) => $f->getPathname() === M3D_SITE . DIRECTORY_SEPARATOR . 'media' || is_file($f->getPathname() . '/app/version.php');
    return array_merge(
        array_values(array_filter($media ?? media_all(), fn($f) => $isTmp(basename($f)))),
        rglob(M3D_PRIVATE . '/data', fn($f) => $isTmp($f->getFilename())),
        rglob(M3D_SITE, fn($f) => str_contains($f->getFilename(), '.upd-'), $other),
    );
}

// ── Actions ──────────────────────────────────────────────────────────────────
$done = null;
$error = null;
$s4Test = null;   // "Save and Test" of MEGA S4: m3d_s4_probe()'s answer
if (admin_form_post()) {
    $del = function (array $files): int { $n = 0; foreach ($files as $f) if (@unlink($f)) $n++; return $n; };
    // saves some settings: $msg when it worked, else the "could not write" error; true when saved
    $save = function (array $changes, string $msg) use (&$done, &$error): bool {
        if (m3d_save_settings($changes)) { $done = $msg; return true; }
        $error = m3d_write_error();
        return false;
    };
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
            if (!$save(['login_days' => $d], 'Logins now last ' . m3d_plural($d, 'day') . ($d < $was ? '. Logins older than that have ended.' : '.'))) break;
            $_SESSION['started'] = time();   // your own login doesn't end because of the change
            m3d_session_cleanup(true);
            break;
        case 'log':
            $done = m3d_log_clear() ? 'Download log cleared.' : 'The download log was already empty.';
            break;
        case 'unused':   // the same as Images → "Delete all unused"
            [$n, , $n3] = media_purge_unused();
            $done = $n || $n3 ? unused_words($n, $n3) . ' that weren’t used deleted.' : 'There were no unused images to delete.';
            break;
        case 'temp':
            $done = $del(temp_files()) . ' temporary files deleted.';
            break;
        case 'problems-clear':
            $done = !is_file(m3d_problems_file()) || @unlink(m3d_problems_file()) ? 'The list of site problems is empty again.' : '';
            if ($done === '') $error = m3d_write_error();
            break;
        case 'identity':
            try {
                $old = m3d_identity();
                $one = fn(string $k, int $max) => clean_line($_POST[$k] ?? '', $max);
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
                if (!m3d_save_settings(['identity' => $store])) throw new RuntimeException(m3d_write_error());
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
                    } elseif (!is_https_url($url)) {
                        throw new InvalidArgumentException('The ' . SOCIAL_TYPES[$type][0] . ' link must be a full address starting with https://');
                    }
                    $links[] = ['type' => $type, 'url' => mb_substr($url, 0, 500)];
                }
                if (count($links) > 16) throw new InvalidArgumentException('16 links at most.');
                $save(['social' => $links], 'Links saved: ' . count($links) . ' shown in the footer.');
            } catch (InvalidArgumentException $ex) {
                $error = $ex->getMessage();
            }
            break;
        case 'maintenance':
            $on = ($_POST['state'] ?? '') === 'on';
            $msg = clean_text($_POST['message'] ?? '', 1000);
            $old = m3d_settings()['maintenance'] ?? [];
            $since = $on ? (!empty($old['on']) ? (string) ($old['since'] ?? gmdate('c')) : gmdate('c')) : '';
            $save(['maintenance' => ['on' => $on, 'message' => $msg, 'since' => $since]],
                $on ? 'Maintenance mode is on: visitors now see the “Back Soon” page. You can still use the site.' : 'Maintenance mode is off: the site is open again.');
            break;
        case 'notice':
            $until = is_string($_POST['notice_until'] ?? null) ? trim($_POST['notice_until']) : '';
            $type = is_string($_POST['notice_type'] ?? null) ? $_POST['notice_type'] : '';
            $n = [
                'on'       => !empty($_POST['notice_on']),
                'type'     => isset(NOTICE_TYPES[$type]) ? $type : 'info',
                'text'     => clean_text($_POST['notice_text'] ?? '', 600),
                'where'    => ($_POST['notice_where'] ?? '') === 'home' ? 'home' : 'all',
                'until'    => $until,
                'closable' => !empty($_POST['notice_closable']),
            ];
            if ($until !== '' && !(preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $until, $dm) && checkdate((int) $dm[2], (int) $dm[3], (int) $dm[1]))) {
                $error = 'The last day isn’t a real date: pick it again, or leave it empty.'; break;
            }
            if ($n['on'] && $n['text'] === '') { $error = 'Write the message, or untick “Show Notice”.'; break; }
            $save(['notice' => $n], $n['on'] ? 'Notice saved: it is shown on ' . ($n['where'] === 'home' ? 'the homepage' : 'every page') . ($n['until'] !== '' ? ' until ' . date_create_immutable($n['until'])->format('j F Y') : '') . '.' : 'Notice saved and hidden.');
            break;
        case 'patreon':
            try {
                $saved = m3d_patreon_saved();
                $in = fn(string $k) => trim((string) ($_POST['pt_' . $k] ?? ''));
                // an empty field keeps the saved value: secrets are never sent back to the page, and a login with an empty
                // Client ID, campaign or redirect address fails for everyone, the owner too (nobody could log in to fix it)
                $new = [];
                foreach (['client_id', 'redirect_uri', 'campaign_id', 'client_secret', 'creator_token', 'creator_refresh'] as $k) $new[$k] = $in($k) !== '' ? $in($k) : (string) ($saved[$k] ?? '');
                if ($new['redirect_uri'] === '') $new['redirect_uri'] = abs_url('/auth/callback.php');   // the address the field suggests
                foreach (['client_id' => 'Client ID', 'client_secret' => 'Client secret'] as $k => $label) {
                    if ($new[$k] !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $new[$k])) throw new InvalidArgumentException("The $label doesn’t look right: copy it again from your Patreon client page (letters, numbers, - and _ only).");
                }
                foreach (['creator_token' => 'Creator’s Access Token', 'creator_refresh' => 'Creator’s Refresh Token'] as $k => $label) {
                    if ($new[$k] !== '' && !preg_match('/^[A-Za-z0-9_\-.]{10,400}$/', $new[$k])) throw new InvalidArgumentException("The $label doesn’t look right: copy it again from your Patreon client page.");
                }
                if ($new['redirect_uri'] !== '' && (!preg_match('#^https?://[^\s<>"]+/auth/callback\.php$#i', $new['redirect_uri']) || !filter_var($new['redirect_uri'], FILTER_VALIDATE_URL))) {
                    throw new InvalidArgumentException('The redirect address must be this site’s address followed by /auth/callback.php, e.g. ' . abs_url('/auth/callback.php'));
                }
                if ($new['campaign_id'] !== '' && !ctype_digit($new['campaign_id'])) throw new InvalidArgumentException('The campaign ID is a number (digits only).');
                if (!m3d_patreon_save($new)) throw new RuntimeException('Could not write ' . basename(M3D_PRIVATE) . '/patreon.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.');
                $done = 'Patreon keys saved. Test the login in a private window before you log out here: if it fails, fix the keys.';
            } catch (InvalidArgumentException | RuntimeException $ex) {
                $error = $ex->getMessage();
                break;
            }
            // a creator token just pasted: try it on the campaign's first members (free followers too, who have no pledge history)
            if ($in('creator_token') === '' && $in('creator_refresh') === '') break;
            $cid = $new['campaign_id'] !== '' ? $new['campaign_id'] : (string) ($M3D['campaign_id'] ?? '');
            $r = m3d_session_pause(fn() => m3d_creator_get(M3D_PATREON . '/api/oauth2/v2/campaigns/' . rawurlencode($cid) . '/members?' . http_build_query([
                'include' => 'pledge_history', 'fields[member]' => 'patron_status', 'fields[pledge-event]' => 'type,date', 'page[count]' => 100,
            ]), 20));
            $failed = fn(string $msg) => admin_flash('<b>Creator token test failed:</b> ' . e($msg), 'err');   // the keys are saved either way
            if ($r['status'] === 200 && is_array($r['json'])) {
                $paying = $withHistory = 0;
                foreach ($r['json']['data'] ?? [] as $m) {
                    if (($m['attributes']['patron_status'] ?? '') !== 'active_patron') continue;
                    $paying++;
                    if (!empty($m['relationships']['pledge_history']['data'])) $withHistory++;
                }
                if ($withHistory > 0) $done = 'Patreon keys saved. Creator token tested: Patreon sent the pledge history of ' . $withHistory . ' of the ' . m3d_plural($paying, 'paying patron') . ' checked, so a patron who came back counts from their new pledge.';
                elseif ($paying > 0) $failed('Patreon sent no pledge history for the ' . m3d_plural($paying, 'paying patron') . ' checked. Months as a patron keep counting from Patreon’s start date.');
                else admin_flash('Patreon accepted the creator token, but none of the first ' . m3d_plural(count($r['json']['data'] ?? []), 'member') . ' is a paying patron, so the pledge history couldn’t be checked.', 'info');
            } else {
                $failed('Patreon answered HTTP ' . $r['status'] . ($r['status'] === 401 ? ' (it refused the token: copy both creator tokens again)' : ' ' . ($r['json']['errors'][0]['detail'] ?? $r['error'])) . '.');
            }
            break;
        case 'plans':
            $one = fn(string $k, int $max) => clean_line($_POST[$k] ?? '', $max);
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
            $save(['plans' => $plans], $plans['on'] ? 'Homepage section saved: visitors who can’t download yet see it.' : 'Homepage section saved and hidden.');
            break;
        case 'legal':
            $one = fn(string $k) => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, 120);
            $lg = ['name' => $one('legal_name'), 'address' => clean_text($_POST['legal_address'] ?? '', 300), 'email' => $one('legal_email')];
            $sup = $one('support_email');
            if (($lg['email'] !== '' && !filter_var($lg['email'], FILTER_VALIDATE_EMAIL)) || ($sup !== '' && !filter_var($sup, FILTER_VALIDATE_EMAIL))) { $error = 'Check the email addresses.'; break; }
            $save(['legal' => $lg, 'support_email' => $sup], 'Legal details and support email saved.');
            break;
        case 'discord':
        case 'discord-test':
            $dc = m3d_discord();
            $hook = trim((string) ($_POST['discord_webhook'] ?? ''));
            $msg = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['discord_message'] ?? ''))), 0, 300);
            $on = !empty($_POST['discord_on']);
            if ($hook !== '' && !preg_match(DISCORD_WEBHOOK_RE, $hook)) { $error = 'That isn’t a Discord webhook address: in Discord, open the channel’s settings → Integrations → Webhooks, then “Copy Webhook URL”.'; break; }
            if ($on && $hook === '') { $error = 'Paste the webhook address first.'; break; }
            $stored = m3d_settings()['discord'] ?? [];
            $new = ['on' => $on, 'webhook' => $hook, 'message' => $msg, 'stills' => !empty($_POST['discord_stills'])] + $stored;
            if (empty($new['stills'])) unset($new['stills_queue'], $new['stills_due'], $new['stills_tries']);   // off: pictures waiting aren't posted
            // turning it on: every version already out counts as posted, so only new ones are announced
            if ($on && (!$dc['on'] || $hook !== $dc['webhook'])) $new['sent'] = array_slice(array_values(array_unique(array_merge($dc['sent'], m3d_discord_current_codes()))), -DISCORD_SENT_MAX);
            if (!$save(['discord' => $new], $on ? 'Discord announcements are on: the next new version you save goes to the channel.' : 'Discord announcements are off.')) break;
            if ($_POST['do'] === 'discord-test') {
                $done = null;
                if ($hook === '') { $error = 'Paste the webhook address first.'; break; }
                // the newest version on the site as an example, else a plain line
                $embed = discord_sample_embed();
                $embed['author'] = ['name' => 'Test message: this is how new versions will look'];
                $res = m3d_session_pause(fn() => m3d_discord_post($hook, $msg, [$embed]));
                if ($res['ok']) $done = 'Test message sent: check the Discord channel.' . ($on ? '' : ' Turn on “Announce New Versions” to turn the announcements on.');
                else $error = $res['error'];
            }
            break;
        case 's4':
        case 's4-test':
            // MEGA S4 downloads (app/s4.php): keys in private/s4.php; the secret is never sent back to the page (empty field = keep it)
            $cur = m3d_s4();
            $s4 = [
                'on' => !empty($_POST['s4_on']),
                'key' => trim((string) ($_POST['s4_key'] ?? '')),
                'secret' => trim((string) ($_POST['s4_secret'] ?? '')) ?: $cur['secret'],
                'minutes' => (int) ($_POST['s4_life'] ?? 0) * (($_POST['s4_unit'] ?? '') === 'hours' ? 60 : 1),
                'region' => $cur['region'],
                'bucket' => m3d_s4_bucket_clean((string) ($_POST['s4_bucket'] ?? '')),
                'test' => trim((string) ($_POST['s4_test'] ?? '')),
            ];
            // the test file: a file name in the bucket ("model.zip"), a short s4: link or a full address
            if ($s4['test'] !== '' && !preg_match('#^(https?:|s4:)#i', $s4['test'])) $s4['test'] = 's4:' . ltrim($s4['test'], '/');
            if ($s4['key'] === '' && trim((string) ($_POST['s4_secret'] ?? '')) === '' && $cur['key'] === '') { $error = 'Paste the access key ID and the secret key.'; break; }
            if (!preg_match('/^[A-Za-z0-9]{8,128}$/', $s4['key'])) { $error = 'The access key ID doesn’t look right: copy it again from MEGA (letters and numbers only).'; break; }
            if ($s4['secret'] === '') { $error = 'Paste the secret key.'; break; }
            if ($s4['minutes'] < S4_MINUTES_MIN || $s4['minutes'] > S4_MINUTES_MAX) { $error = 'Link lifetime: from ' . S4_MINUTES_MIN . ' minutes to ' . intdiv(S4_MINUTES_MAX, 60) . ' hours (7 days).'; break; }
            if (!preg_match('#^[\x21-\x7e]{16,200}$#', $s4['secret'])) { $error = 'The secret key doesn’t look right: copy it again from MEGA (no spaces).'; break; }
            if (trim((string) ($_POST['s4_bucket'] ?? '')) !== '' && $s4['bucket'] === '') { $error = 'The bucket address isn’t an S4 address. It looks like https://s3.g.megas4.com/your-bucket/'; break; }
            if ($s4['test'] !== '' && !m3d_s4_parse($s4['test'], $s4['bucket'])) {
                $error = m3d_s4_short($s4['test']) ? 'Fill in the Bucket Address, or write the test file’s full address.' : 'The test file isn’t an S4 file address. It looks like https://s3.g.megas4.com/your-bucket/file.zip'; break;
            }
            if ($_POST['do'] === 's4-test') {
                if ($s4['test'] === '' && $s4['bucket'] === '') { $error = 'Fill in the Bucket Address, then test again.'; break; }
                // the region the signature names: S4 doesn't tie a bucket to one, so the saved one first, then the others;
                // any answer but a refused signature means the keys and the region work (a missing file too).
                // No test file: the bucket's list, then the first byte of its first file (reading and listing both checked)
                $regions = array_values(array_unique(array_merge([$s4['region']], S4_REGIONS)));
                $refused = ['SignatureDoesNotMatch', 'AuthorizationHeaderMalformed', 'AuthorizationQueryParametersError', 'InvalidRequest'];
                $s4Test = m3d_session_pause(function () use ($regions, $refused, &$s4) {
                    foreach ($regions as $rg) {
                        if ($s4['test'] !== '') {
                            $r = m3d_s4_probe($s4['test'], $rg, $s4);
                            if ($r['code'] !== 0 && in_array($r['err'], $refused, true)) continue;
                            $s4['region'] = $rg;
                            return ['file' => $s4['test'], 'err' => $r['ok'] ? '' : ($r['code'] === 0 ? 'S4 didn’t answer (' . $r['err'] . ')' : m3d_s4_error_text($r['err']))] + $r;
                        }
                        $l = m3d_s4_list('', $s4, $rg, 1);
                        if ($l['code'] !== '' && in_array($l['code'], $refused, true)) continue;
                        $s4['region'] = $rg;
                        if (!$l['ok']) return ['ok' => false, 'err' => $l['err']];
                        $first = $l['files'][0]['key'] ?? '';
                        $t = ['ok' => true, 'size' => 0, 'err' => '', 'file' => $first !== '' ? 's4:' . $first : '',
                              'list' => count($l['files']) . ($l['more'] ? '+' : '') . ' ' . (count($l['files']) === 1 ? 'file' : 'files') . ($l['folders'] ? ', ' . m3d_plural(count($l['folders']), 'folder') : '')];
                        if ($first === '') return $t;
                        $r = m3d_s4_probe($t['file'], $rg, $s4);
                        return $r['ok'] ? ['size' => $r['size']] + $t : ['ok' => false, 'err' => 'The list works, but reading ' . $first . ' failed: ' . ($r['code'] === 0 ? 'S4 didn’t answer (' . $r['err'] . ')' : m3d_s4_error_text($r['err']))];
                    }
                    return ['ok' => false, 'err' => m3d_s4_error_text('SignatureDoesNotMatch')];
                });
            }
            if (!m3d_s4_save($s4)) { $error = 'Could not write ' . basename(M3D_PRIVATE) . '/s4.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.'; $s4Test = null; break; }
            $done = $_POST['do'] === 's4-test' ? null : ($s4['on'] ? 'S4 downloads on: download links to S4 files get a temporary address.' : 'S4 downloads off: S4 is hidden everywhere else and "s4:" links don’t download; the keys and bucket stay saved here.');
            break;
        case 's4-remove':
            $done = m3d_s4_save([]) ? 'S4 keys removed: S4 links are no longer signed (they won’t download while the bucket is private).' : null;
            if (!$done) $error = 'Could not delete ' . basename(M3D_PRIVATE) . '/s4.php.';
            break;
        case 'bugs':
            $hook = trim((string) ($_POST['bugs_webhook'] ?? ''));
            $host = trim((string) ($_POST['bugs_host'] ?? ''));
            $notify = !empty($_POST['bugs_notify']);
            if ($hook !== '' && !preg_match(DISCORD_WEBHOOK_RE, $hook)) { $error = 'The webhook address doesn’t look right: copy it again from Discord (https://discord.com/api/webhooks/…).'; break; }
            if ($notify && $hook === '') { $error = 'Paste a webhook address to be told on Discord about new reports.'; break; }
            if ($host !== '' && !is_https_url($host)) { $error = 'The upload website must be an address starting with https://'; break; }
            $on = !empty($_POST['bugs_on']);
            $ok = m3d_bug_settings_save(['on' => $on, 'who' => ($_POST['bugs_who'] ?? '') === 'paying' ? 'paying' : 'any', 'host' => mb_substr($host, 0, 300),
                'uploads' => !empty($_POST['bugs_uploads']), 'moderate' => !empty($_POST['bugs_moderate']), 'notify' => $notify, 'webhook' => $hook,
                'per_day' => max(1, min(50, (int) ($_POST['bugs_per_day'] ?? 5)))]);
            if ($ok) $done = $on ? 'Bug reports are on: /bugs/ is in the footer and on model and accessory pages.' : 'Bug reports are off: /bugs/ shows “not found” to visitors.';
            else $error = m3d_write_error();
            break;
        case 'bugs-unblock':
            $id = (string) ($_POST['who'] ?? '');
            $blocked = m3d_bug_settings()['blocked'];
            if (!in_array($id, $blocked, true)) break;
            if (m3d_bug_settings_save(['blocked' => array_values(array_diff($blocked, [$id]))])) $done = 'Unblocked: they can post bug reports again.';
            else $error = m3d_write_error();
            break;
        case 'welcome':
            $old = m3d_welcome();
            $on = !empty($_POST['welcome_on']);
            $txt = fn(string $k) => is_string($_POST[$k] ?? null) ? str_replace("\r", '', $_POST[$k]) : '';
            $save(['welcome' => ['on' => $on, 'skip_old' => !empty($_POST['welcome_skip_old']),
                'text' => trim(mb_substr($txt('welcome_text'), 0, WELCOME_TEXT_MAX)),
                'start' => trim(mb_substr(preg_replace('/\s+/u', ' ', $txt('welcome_start')), 0, WELCOME_START_MAX)),
                'since' => $on && !$old['on'] ? time() : $old['since']]],   // switched on now: patrons who downloaded before count as old
                $on ? 'Welcome page is on: new patrons see it after their first login.' : 'Welcome page is off.');
            break;
        case 'welcome-reset':
            if (m3d_welcome_reset()) $done = 'Everyone will see the welcome page again after their next login.';
            else $error = m3d_write_error();
            break;
        case 'smutbase':
        case 'mustardui':
            $k = $_POST['do'];
            $on = !empty($_POST[$k . '_on']);
            $save([$k => ['on' => $on]], FEATURE_SWITCHES[$k][$on ? 4 : 5]);
            break;
        case 'currency':
            $c = strtoupper((string) ($_POST['currency'] ?? ''));
            if (!preg_match('/^[A-Z]{3}$/', $c)) { $error = 'Choose a currency.'; break; }
            $save(['currency' => $c], "Currency set to $c.");
            break;
        case 'identity-reset':
            $old = m3d_identity();
            if ($save(['identity' => null], 'Site identity reset to the default.')) img_site_logo_delete($old['logo'], $old['icon']);
            break;
    }
}

$loginDays = intdiv(m3d_session_lifetime(), 86400);
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

/*
 * The numbers of Tools and System walk the media folder, the site's code and every session file: on a big site or a slow
 * disk that takes seconds, so the page opens without them and asks for them right after, in two parts that run side by side:
 * ?tools=quick (sessions, log, backups, disk) and ?tools=media (unused images, temporary files, media), filled in by admin.js.
 * Not "stats": ad blockers (EasyPrivacy) block addresses with ?stats= as if they were tracking.
 */
if (isset($_GET['tools'])) {
    session_write_close();   // don't keep the login locked while counting: other admin pages stay usable
    @set_time_limit(60);
    define('STATS_UNTIL', microtime(true) + 25);   // stop well before the server or Cloudflare gives up, and say so
    ob_start();   // a PHP warning printed by the server would break the JSON answer
    $part = m3d_get_text('tools');
    try {
        $out = [];
        if ($part === 'quick') {
            m3d_session_cleanup(true);   // expired logins are deleted, so the count is only the live ones
            [$sessN, $sessB] = files_stats(session_files());
            $logFile = m3d_log_file();
            $logLines = 0;
            if (is_file($logFile) && ($fh = @fopen($logFile, 'r'))) { while (($l = fgets($fh)) !== false) if (trim($l) !== '') $logLines++; fclose($fh); }
            [, $bkB] = files_stats(glob(M3D_PRIVATE . '/backups/*') ?: []);
            $free = function_exists('disk_free_space') ? @disk_free_space(M3D_SITE) : false;
            $out = [
                'sessions' => ['text' => $sessN . ' other active ' . ($sessN === 1 ? 'session' : 'sessions') . ' (' . m3d_size($sessB) . ').', 'n' => $sessN],
                'log' => ['text' => $logLines . ' entries, kept ' . (int) ($M3D['log_retention_days'] ?? 365) . ' days.', 'n' => $logLines],
                'backups' => ['text' => m3d_size($bkB) . ' of backups.'],
                'disk' => ['text' => $free ? m3d_size((int) $free) : 'unknown'],
            ];
        } elseif ($part === 'media') {
            $media = media_all();
            $use = usage_map(m3d_models());   // the same count as the Images page
            $unImg = unused_images($use);
            $un3d = unused_3d($use);
            [$unN, $un3, $unB] = [count($unImg), count($un3d), array_sum(array_column($unImg, 'bytes')) + array_sum(array_column($un3d, 'bytes'))];
            [$tmpN, $tmpB] = files_stats(array_unique(temp_files($media)));
            [$medN, $medB] = files_stats($media);
            $out = [
                'unused' => ['text' => unused_words($unN, $un3) . ' not used anywhere (' . m3d_size($unB) . ').', 'n' => $unN + $un3],
                'temp' => ['text' => $tmpN . ' leftovers from interrupted uploads (' . m3d_size($tmpB) . ').', 'n' => $tmpN],
                'media' => ['text' => $medN . ' files, ' . m3d_size($medB)],
            ];
        }
        ob_end_clean();
        admin_json(['ok' => true, 'stats' => $out]);
    } catch (Throwable $ex) {
        ob_end_clean();
        m3d_problem('Settings', 'Counting ' . $part . ': ' . $ex->getMessage());
        admin_json(['ok' => false, 'error' => $ex->getMessage()]);
    }
}

$checks = [
    ['Site Version', M3D_VERSION, true, ''],
    ['PHP Version', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>='), ''],
    ['Max Upload Size', ini_get('upload_max_filesize') . ' (form limit ' . ini_get('post_max_size') . ')', true, ''],
    ['Memory Limit', ini_get('memory_limit') === '-1' ? 'no limit' : ini_get('memory_limit'), true, ''],
    ['WebP Image Support', img_webp() ? 'yes' : 'no (JPG fallback)', img_webp(), ''],
    ['Zip Support (Updates)', class_exists('ZipArchive') ? 'yes' : 'no', class_exists('ZipArchive'), ''],
    ['Data Folder Writable', is_writable(M3D_PRIVATE . '/data') ? 'yes' : 'no', is_writable(M3D_PRIVATE . '/data'), ''],
    ['Media Folder Writable', is_dir(M3D_SITE . '/media') && is_writable(M3D_SITE . '/media') ? 'yes' : 'no', is_dir(M3D_SITE . '/media') && is_writable(M3D_SITE . '/media'), ''],
    ['Images in Media', '', true, 'media'],
    ['Free Disk Space', '', true, 'disk'],
];

// $stat: the button waits for that count (?tools=…) and stays off when it is 0
$action = function (string $do, string $label, string $confirm = '', string $stat = ''): string {
    return '<form method="post"' . ($confirm ? ' onsubmit="return confirm(' . e(json_encode($confirm)) . ')"' : '') . '>'
        . form_keys($do)
        . '<button class="btn btn-ghost btn-sm"' . ($stat !== '' ? ' data-stat-btn="' . e($stat) . '"' : '') . ($stat !== '' ? ' disabled' : '') . '>' . e($label) . '</button></form>';
};

$social = m3d_social();

$tab = settings_tab();
// the big boxes are folded to their title; the one just saved (or refused) stays open
$open = fn(string ...$do) => in_array($_POST['do'] ?? '', $do, true) ? ' open' : '';

admin_start('Settings', 'settings');
?>
    <div class="admin-head">
      <div>
        <h1>Settings</h1>
      </div>
    </div>
    <?= settings_menu($tab) ?>

    <?php admin_result($done, $error) ?>

    <?php if ($tab === 'site'): ?>
    <?php $id = m3d_identity(); $idChanged = array_intersect_key($id, IDENTITY_DEFAULTS) != IDENTITY_DEFAULTS;   // saved values equal to the defaults: nothing to reset ?>
    <section class="sec" id="identity">
      <h2 class="small-h">Site Identity</h2>
      <form method="post" enctype="multipart/form-data" class="id-form" data-identity-form>
        <?= form_keys('identity') ?>
        <div class="id-preview" aria-hidden="true" data-id-preview style="--mustard: <?= e($id['color']) ?>; --mustard-ink: <?= e(accent_ink($id['color'])) ?>">
          <span class="brand"><?= brand() ?></span>
          <span class="btn btn-primary btn-sm">Button</span>
          <span class="id-prev-line"><?= e($id['tagline']) ?>.</span>
        </div>
        <div class="id-grid">
          <label class="field"><span class="lbl">Site Name</span><input class="input" name="id_name" value="<?= e($id['name']) ?>" maxlength="40" required data-id="name" /></label>
          <label class="field"><span class="lbl">Highlighted Word<?= tip('Part of the name shown in the accent colour, written exactly as in the name (e.g. “Studio” in “PixelStudio”). Empty = one colour.') ?></span><input class="input" name="id_highlight" value="<?= e($id['highlight']) ?>" maxlength="20" data-id="highlight" /></label>
          <label class="field id-wide"><span class="lbl">Tagline<?= tip('Homepage title (“' . $id['name'] . ' · …”), footer line under the logo, and default page description.') ?></span><input class="input" name="id_tagline" value="<?= e($id['tagline']) ?>" maxlength="90" required data-id="tagline" /></label>
          <div class="field"><span class="lbl">Accent Colour</span>
            <span class="id-color"><input type="color" name="id_color" value="<?= e($id['color']) ?>" data-id="color" aria-label="Accent colour" /><code data-id-hex><?= e($id['color']) ?></code>
              <?php if ($id['color'] !== IDENTITY_DEFAULTS['color']): ?><button type="button" class="linklike small muted" data-id-color-reset="<?= e(IDENTITY_DEFAULTS['color']) ?>">Reset Colour</button><?php endif ?></span>
          </div>
          <div class="field"><span class="lbl">Logo<?= tip('Shown next to the name; also the browser tab and phone icon. Best: square PNG, transparent background, at least 192 px.') ?></span>
            <span class="id-logo">
              <label class="btn btn-ghost btn-sm">Upload…<input type="file" name="id_logo" accept="image/png,image/jpeg,image/webp,image/gif" hidden data-id="logo" /></label>
              <?php if ($id['logo'] !== ''): ?><label class="check small"><input type="checkbox" name="id_nologo" value="1" data-id="nologo" /> Remove Logo</label><?php endif ?>
            </span>
          </div>
        </div>
        <div class="form-actions">
          <?php if ($idChanged): ?><button class="btn btn-ghost btn-sm" form="identity-reset" title="Go back to the default identity">Reset</button><?php endif ?>
          <button class="btn btn-primary btn-sm">Save Identity</button>
        </div>
      </form>
      <?php if ($idChanged): ?>
        <form method="post" id="identity-reset" hidden onsubmit="return confirm('Go back to the default identity (a neutral name and tagline, the default colour, no logo)? Your current name, logo and colour are removed.')">
          <?= form_keys('identity-reset') ?>
        </form>
      <?php endif ?>
    </section>

    <section style="margin-top:20px" class="sec" id="social">
      <h2 class="small-h">Social Links<?= tip('Footer buttons, in this order. The Patreon, Discord and X links are also used across the site.') ?></h2>
      <form method="post" class="social-form" data-social-form>
        <?= form_keys('social') ?>
        <div class="social-rows" data-social-rows>
          <?php foreach ($social as $l): ?>
            <div class="social-row" data-social-row>
              <span class="social-prev" data-social-icon><?= social_icon($l['type']) ?></span>
              <select class="input" name="social_type[]" aria-label="Network" data-social-type>
                <?php foreach (SOCIAL_TYPES as $k => $t): ?><option value="<?= e($k) ?>" <?= $k === $l['type'] ? 'selected' : '' ?>><?= e($t[0]) ?></option><?php endforeach ?>
              </select>
              <input class="input" type="text" name="social_url[]" maxlength="500" value="<?= e($l['url']) ?>" placeholder="https://…" aria-label="Address" spellcheck="false" />
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
            <input class="input" type="text" name="social_url[]" maxlength="500" value="" placeholder="https://…" aria-label="Address" spellcheck="false" />
            <span class="row-btns">
              <button type="button" class="drag-handle drag-sm" data-social-drag title="Drag to reorder (or focus and use the arrow keys)" aria-label="Drag to reorder">⠿</button>
              <button type="button" class="icon-btn del" data-social-del title="Remove" aria-label="Remove">✕</button>
            </span>
          </div>
        </template>
        <script type="application/json" data-social-icons><?= json_encode(array_map(fn($k) => social_icon($k), array_combine(array_keys(SOCIAL_TYPES), array_keys(SOCIAL_TYPES))), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
        <div class="form-actions">
          <button type="button" class="btn btn-ghost btn-sm act-left" data-social-add>+ Add Link</button>
          <button class="btn btn-primary btn-sm">Save Links</button>
        </div>
      </form>
    </section>

    <?php $pl = home_plans(); ?>
    <details style="margin-top:20px" class="sec fold" id="homepage"<?= $open('plans') ?>>
      <summary><h2 class="small-h">Homepage Section <span class="badge tone-<?= $pl['on'] ? 'green' : 'grey' ?>"><?= $pl['on'] ? 'Shown' : 'Hidden' ?></span><?= tip('Two boxes at the bottom of the homepage explaining how to get the downloads (e.g. free releases and Patreon membership). Hidden for patrons who can already download.') ?></h2></summary>
      <div class="fold-body">
      <form method="post" class="pl-form">
        <?= form_keys('plans') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="pl_on" value="1" <?= $pl['on'] ? 'checked' : '' ?> /><span>Show Section</span></label>
        <div class="id-grid">
          <label class="field"><span class="lbl">Small Heading<?= tip('Short line above the title.') ?></span><input class="input" name="pl_eyebrow" value="<?= e($pl['eyebrow']) ?>" maxlength="40" placeholder="Downloads" /></label>
          <label class="field"><span class="lbl">Title</span><input class="input" name="pl_head" value="<?= e($pl['title']) ?>" maxlength="80" placeholder="How to get the models" /></label>
        </div>
        <div class="pl-boxes">
          <?php foreach ($pl['boxes'] as $i => $b): ?>
            <fieldset class="pl-box<?= $i === 1 ? ' pl-hot' : '' ?>">
              <legend><?= $i === 0 ? 'Box 1' : 'Box 2 (Highlighted)' ?></legend>
              <label class="field"><span class="lbl">Tag</span><input class="input" name="pl_tag_<?= $i ?>" value="<?= e($b['tag']) ?>" maxlength="40" placeholder="<?= $i ? 'Membership' : 'Open access' ?>" /></label>
              <label class="field"><span class="lbl">Title</span><input class="input" name="pl_title_<?= $i ?>" value="<?= e($b['title']) ?>" maxlength="80" placeholder="<?= $i ? 'Every download' : 'Free releases' ?>" /></label>
              <label class="field"><span class="lbl">Text<?= tip('Supports **bold**, *italic* and [links](https://…).') ?></span><textarea class="input" name="pl_text_<?= $i ?>" rows="3" maxlength="600"><?= e($b['text']) ?></textarea></label>
              <label class="field"><span class="lbl">Bullet Points<?= tip('One per line.') ?></span><textarea class="input" name="pl_items_<?= $i ?>" rows="3"><?= e(implode("
", $b['items'])) ?></textarea></label>
              <div class="field"><span class="lbl">Buttons<?= tip('Discord and Patreon use the Social Links addresses (left out when not set). “Log In” opens the Patreon login.') ?></span>
                <span class="pl-btns"><?php foreach (PLAN_BUTTONS as $k => $label): ?><label class="check small"><input type="checkbox" name="pl_btn_<?= $i ?>[]" value="<?= e($k) ?>" <?= in_array($k, $b['buttons'], true) ? 'checked' : '' ?> /> <?= e($label) ?></label><?php endforeach ?></span>
              </div>
              <label class="field"><span class="lbl">Footnote<?= tip('Optional small print under the box.') ?></span><input class="input" name="pl_fine_<?= $i ?>" value="<?= e($b['fine']) ?>" maxlength="300" /></label>
            </fieldset>
          <?php endforeach ?>
        </div>
        <div class="form-actions"><a class="btn btn-ghost btn-sm" href="/#access" target="_blank">View on the Homepage ↗</a><button class="btn btn-primary btn-sm">Save Section</button></div>
      </form>
      </div>
    </details>

    <?php $notice = (m3d_settings()['notice'] ?? []) + ['on' => false, 'type' => 'info', 'text' => '', 'where' => 'all', 'until' => '', 'closable' => true];
          $live = m3d_notice() !== null; ?>
    <details style="margin-top:20px" class="sec fold" id="notice"<?= $open('notice') ?>>
      <summary><h2 class="small-h">Site Notice <span class="badge tone-<?= $live ? 'green' : 'grey' ?>"><?= $live ? 'Shown' : ($notice['on'] && $notice['text'] !== '' ? 'Ended' : 'Off') ?></span></h2></summary>
      <div class="fold-body">
      <form method="post" class="notice-form">
        <?= form_keys('notice') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="notice_on" value="1" <?= $notice['on'] ? 'checked' : '' ?> /><span>Show Notice</span></label>
        <div class="notice-grid">
          <label class="field"><span class="lbl">Type</span>
            <select class="input" name="notice_type"><?php foreach (NOTICE_TYPES as $k => [$label]): ?><option value="<?= e($k) ?>" <?= $notice['type'] === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select>
          </label>
          <label class="field"><span class="lbl">Show On</span>
            <select class="input" name="notice_where"><option value="all">Every Page</option><option value="home" <?= $notice['where'] === 'home' ? 'selected' : '' ?>>Homepage Only</option></select>
          </label>
          <label class="field"><span class="lbl">End Date<?= tip('Optional. Shown until the end of this day.') ?></span><input class="input" type="date" name="notice_until" value="<?= e($notice['until']) ?>" /></label>
        </div>
        <label class="field"><span class="lbl">Message<?= tip('Supports **bold**, *italic* and [links](https://…).') ?></span>
          <textarea class="input" name="notice_text" rows="2" maxlength="600" placeholder="**New:** a new model is out for patrons! [See it](/models/your-model/)"><?= e($notice['text']) ?></textarea>
        </label>
        <label class="toggle"><input type="checkbox" class="switch" name="notice_closable" value="1" <?= $notice['closable'] ? 'checked' : '' ?> /><span>Allow Closing<?= tip('Visitors can close it. It reappears when the message changes.') ?></span></label>
        <div class="form-actions"><button class="btn btn-primary btn-sm">Save Notice</button></div>
      </form>
      <?php if ($notice['text'] !== ''): ?>
        <p class="lbl notice-prev-lbl">Preview</p>
        <div class="notice-prev"><?= notice_html($notice, true) ?></div>
      <?php endif ?>
      </div>
    </details>

    <?php $lg = m3d_legal(); ?>
    <section style="margin-top:20px" class="sec" id="legal">
      <h2 class="small-h">Legal &amp; Contact<?= tip('Site operator shown on the privacy page (required in the EU), and the support email shown on the Contacts page. Empty fields use config.php.') ?></h2>
      <form method="post">
        <?= form_keys('legal') ?>
        <div class="id-grid">
          <label class="field"><span class="lbl">Full Name</span><input class="input" name="legal_name" value="<?= e($lg['name']) ?>" maxlength="120" /></label>
          <label class="field"><span class="lbl">Privacy Email<?= tip('Shown on the privacy page.') ?></span><input class="input" type="email" name="legal_email" value="<?= e($lg['email']) ?>" maxlength="120" /></label>
          <label class="field id-wide"><span class="lbl">Postal Address</span><textarea class="input" name="legal_address" rows="3" maxlength="300"><?= e($lg['address']) ?></textarea></label>
          <label class="field id-wide"><span class="lbl">Support Email<?= tip('Shown on the Contacts page for legal matters about the website’s content.') ?></span><input class="input" type="email" name="support_email" value="<?= e(m3d_support_email()) ?>" maxlength="120" /></label>
        </div>
        <div class="form-actions"><a class="btn btn-ghost btn-sm" href="/privacy/" target="_blank">View the Privacy Page ↗</a><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
    </section>

    <?php endif ?>

    <?php if ($tab === 'patreon'): ?>
    <?php $pt = m3d_patreon_saved();
          $ptVal = fn(string $k) => (string) ($pt[$k] ?? '');
          $ptReal = fn(string $k) => m3d_cfg_real($ptVal($k));
          $ptFrom = fn(string $k) => $ptReal($k) ? '' : '<span class="badge tone-red">Missing</span>';
          $callback = abs_url('/auth/callback.php'); ?>
    <section class="sec" id="patreon">
      <h2 class="small-h">Patreon</h2>
      <form method="post" class="pt-form" autocomplete="off" onsubmit="return confirm(<?= e(json_encode('Save the Patreon keys? If they are wrong, nobody can log in (you stay logged in here). Test in a private window afterwards.')) ?>)">
        <?= form_keys('patreon') ?>
        <div class="id-grid">
          <label class="field"><span class="lbl">Client ID <?= $ptFrom('client_id') ?><?= tip('From patreon.com/portal/registration/register-clients → your client → “Client ID”.') ?></span>
            <input class="input" name="pt_client_id" value="<?= e($ptReal('client_id') ? $ptVal('client_id') : '') ?>" spellcheck="false" autocomplete="off" /></label>
          <label class="field"><span class="lbl">Client Secret <?= $ptFrom('client_secret') ?><?= tip('Same page, “Client Secret”. Never shown again: leave empty to keep the current one.') ?></span>
            <input class="input" type="password" name="pt_client_secret" value="" placeholder="<?= $ptReal('client_secret') ? '•••••••• (leave empty to keep it)' : 'Paste the client secret' ?>" spellcheck="false" autocomplete="new-password" /></label>
          <?php foreach (['creator_token' => 'Creator’s Access Token', 'creator_refresh' => 'Creator’s Refresh Token'] as $k => $label): $has = !empty($pt[$k]); ?>
          <label class="field"><span class="lbl"><?= e($label) ?><?= tip('Same page, “' . $label . '”. Never shown again: leave empty to keep the current one.') ?></span>
            <input class="input" type="password" name="pt_<?= $k ?>" value="" placeholder="<?= $has ? '•••••••• (leave empty to keep it)' : 'Paste the ' . e(mb_strtolower($label)) ?>" spellcheck="false" autocomplete="new-password" /></label>
          <?php endforeach ?>
          <label class="field id-wide"><span class="lbl">Redirect URI <?= $ptFrom('redirect_uri') ?><?= tip('Must match one of your Patreon client’s “Redirect URIs” exactly.') ?></span>
            <span class="pt-row"><input class="input" name="pt_redirect_uri" value="<?= e($ptReal('redirect_uri') ? $ptVal('redirect_uri') : '') ?>" placeholder="<?= e($callback) ?>" spellcheck="false" data-pt-redirect />
              <?php if ($ptVal('redirect_uri') !== $callback): ?><button type="button" class="btn btn-ghost btn-sm" data-pt-use="<?= e($callback) ?>" title="<?= e($callback) ?>">Use This Site’s Address</button><?php endif ?></span></label>
          <label class="field"><span class="lbl">Campaign ID <?= $ptFrom('campaign_id') ?><?= tip('Your Patreon campaign number. Only its paying members can download.') ?></span>
            <input class="input" name="pt_campaign_id" value="<?= e($ptReal('campaign_id') ? $ptVal('campaign_id') : '') ?>" inputmode="numeric" spellcheck="false" /></label>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary btn-sm">Save Patreon Keys</button>
        </div>
      </form>
      <form method="post" class="cur-form pt-cur">
        <?= form_keys('currency') ?>
        <label class="field"><span class="lbl">Campaign Currency<?= tip('Used for “total support” requirements. Set automatically at patron login.') ?></span>
          <select class="input" name="currency">
            <?php foreach (['EUR' => 'Euro (€)', 'USD' => 'US Dollar ($)', 'GBP' => 'British Pound (£)', 'CAD' => 'Canadian Dollar', 'AUD' => 'Australian Dollar', 'JPY' => 'Japanese Yen'] as $k => $l): ?><option value="<?= $k ?>" <?= m3d_currency() === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
          </select>
        </label>
        <button class="btn btn-primary btn-sm">Save</button>
      </form>
    </section>

    <section style="margin-top:20px" class="sec" id="logins">
      <h2 class="small-h">Logins<?= tip('How long a Patreon login lasts. Afterwards patrons log in again (one click). Expired logins are deleted. To log everyone out now: Maintenance → Tools.') ?></h2>
      <form method="post" class="days-form">
        <?= form_keys('login-days') ?>
        <span class="lbl">Login Duration</span>
        <input class="input" type="number" name="login_days" min="1" max="365" step="1" value="<?= $loginDays ?>" aria-label="Days" required /><span class="muted small">days</span>
        <button class="btn btn-primary btn-sm">Save</button>
      </form>
    </section>

    <?php endif ?>

    <?php if ($tab === 'features'): ?>
    <?php $wl = m3d_welcome(); ?>
    <section class="sec" id="welcome">
      <h2 class="small-h">Welcome Page <span class="badge tone-<?= $wl['on'] ? 'green' : 'grey' ?>"><?= $wl['on'] ? 'On' : 'Off' ?></span><?= tip('A page that shows new patrons around the site, once, right after their first login: how downloads and unlocks work (from your rules), updates and help. Then it sends them where they were going.') ?></h2>
      <form method="post" class="set-list">
        <?= form_keys('welcome') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="welcome_on" value="1" <?= $wl['on'] ? 'checked' : '' ?> /><span>Show After the First Login<?= tip('Paying patrons and people with a Bypass Tier exception. Admins never get it. Patrons can open it again from My Account.') ?></span></label>
        <label class="toggle"><input type="checkbox" class="switch" name="welcome_skip_old" value="1" <?= $wl['skip_old'] ? 'checked' : '' ?> /><span>Skip Patrons Who Downloaded Before<?= tip('People with a download from before you turned the page on don’t get it.') ?></span></label>
        <label class="field"><span class="lbl">Intro Text<?= tip('Under the title. Empty = “Thanks for supporting ' . site_name() . ' on Patreon. Here’s a quick tour, so you know where everything is.”') ?></span>
          <textarea class="input" name="welcome_text" rows="3" maxlength="<?= WELCOME_TEXT_MAX ?>" placeholder="<?= e(m3d_welcome_text()) ?>"><?= e($wl['text']) ?></textarea></label>
        <label class="field"><span class="lbl">Before Your First Download<?= tip('The line next to the Getting Started links of Admin → Models. The box shows only while there are links.') ?></span>
          <input class="input" name="welcome_start" maxlength="<?= WELCOME_START_MAX ?>" value="<?= e($wl['start']) ?>" placeholder="Most models need a recent Blender. Start here:" /></label>
        <div class="form-actions">
          <button class="btn btn-ghost btn-sm act-left" form="welcome-reset">Show Again to Everyone</button>
          <a class="btn btn-ghost btn-sm" href="/welcome/" target="_blank">Preview ↗</a>
          <button class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
      <form method="post" id="welcome-reset" hidden onsubmit="return confirm('Show the welcome page again to every patron, the next time they log in? Patrons who saw it already will see it once more.')">
        <?= form_keys('welcome-reset') ?>
      </form>
    </section>

    <?php $bs = m3d_bug_settings(); ?>
    <section style="margin-top:20px" class="sec" id="bugs">
      <h2 class="small-h">Bug Reports <span class="badge tone-<?= $bs['on'] ? 'green' : 'grey' ?>"><?= $bs['on'] ? 'On' : 'Off' ?></span></h2>
      <form method="post" autocomplete="off" class="set-list" data-bugs-form>
        <?= form_keys('bugs') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="bugs_on" value="1" <?= $bs['on'] ? 'checked' : '' ?> /><span>Enable Bug Reports<?= tip('Adds “Bug Reports” to the footer and a “Found a bug?” box on model and accessory pages. Off: /bugs/ returns “not found” (admins still see it).') ?></span></label>
        <div class="set-grps">
          <div class="set-grp"><h3>Posting</h3>
            <div class="field"><span class="lbl">Who Can Post<?= tip('Reports and comments. Everyone can read reports, logged in or not.') ?></span>
              <div class="vis-seg bugs-who">
                <label class="vis-opt"><input type="radio" name="bugs_who" value="any" <?= $bs['who'] === 'any' ? 'checked' : '' ?> />Any Account</label>
                <label class="vis-opt"><input type="radio" name="bugs_who" value="paying" <?= $bs['who'] === 'paying' ? 'checked' : '' ?> />Paying Patrons</label>
              </div></div>
            <label class="field bugs-num"><span class="lbl">Reports per Day<?= tip('How many reports one person can send in a day. Comments don’t count.') ?></span>
              <input class="input" type="number" name="bugs_per_day" min="1" max="50" value="<?= (int) $bs['per_day'] ?>" /></label>
            <label class="toggle"><input type="checkbox" class="switch" name="bugs_moderate" value="1" <?= $bs['moderate'] ? 'checked' : '' ?> /><span>Review Before Publishing<?= tip('New reports stay hidden (visible only to their author) until you press Publish on the report’s page.') ?></span></label>
          </div>
          <div class="set-grp"><h3>Screenshots</h3>
            <label class="toggle"><input type="checkbox" class="switch" name="bugs_uploads" value="1" <?= $bs['uploads'] ? 'checked' : '' ?> /><span>Allow Image Uploads<?= tip('Up to ' . BUG_PICS . ' pictures per report, max ' . (BUG_PIC_BYTES >> 20) . ' MB each, stored on your host in media/bugs (resized, WebP). Off: links only.') ?></span></label>
            <label class="field"><span class="lbl">Screenshot Host<?= tip('Suggested on the report form for screenshot links. Pictures stay on that site; reports show them only as links. Empty = no suggestion.') ?></span>
              <input class="input" type="url" name="bugs_host" maxlength="300" value="<?= e($bs['host']) ?>" placeholder="https://catbox.moe" spellcheck="false" /></label>
          </div>
          <div class="set-grp"><h3>Discord</h3>
            <label class="toggle"><input type="checkbox" class="switch" name="bugs_notify" value="1" <?= $bs['notify'] ? 'checked' : '' ?> /><span>Post New Reports<?= tip('Posts each new report to a Discord channel. Use a private channel: posts include the report text.') ?></span></label>
            <label class="field bugs-hook"><span class="lbl">Webhook URL<?= tip('Channel settings → Integrations → Webhooks → New Webhook → “Copy Webhook URL”.') ?></span>
              <input class="input dc-hook" type="url" name="bugs_webhook" value="<?= e($bs['webhook']) ?>" placeholder="https://discord.com/api/webhooks/…" spellcheck="false" autocomplete="off" /></label>
          </div>
        </div>
        <div class="form-actions"><a class="btn btn-ghost btn-sm" href="/bugs/">Open Bug Reports</a><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
      <?php if ($bs['blocked']):
          $names = [];   // the name each blocked person last posted with
          foreach (m3d_bugs() as $b) foreach (array_merge([$b], array_filter((array) ($b['log'] ?? []), fn($x) => ($x['t'] ?? '') === 'c')) as $x) {
              if (($x['by'] ?? '') !== '' && ($x['name'] ?? '') !== '' && (int) ($x['at'] ?? 0) >= ($names[(string) $x['by']][1] ?? 0)) $names[(string) $x['by']] = [(string) $x['name'], (int) ($x['at'] ?? 0)];
          } ?>
        <div class="field bugs-blocked"><span class="lbl">Blocked Users</span>
          <?php foreach ($bs['blocked'] as $id): ?>
            <form method="post" class="inline-row"><?= form_keys('bugs-unblock') ?><input type="hidden" name="who" value="<?= e($id) ?>" />
              <span><b><?= e($names[$id][0] ?? 'Someone') ?></b> <span class="muted small">Patreon ID <?= e($id) ?></span></span>
              <button class="btn btn-ghost btn-sm">Unblock</button></form>
          <?php endforeach ?>
        </div>
      <?php endif ?>
    </section>

    <?php $dc = m3d_discord(); $dcOn = m3d_discord_on(); ?>
    <section style="margin-top:20px" class="sec" id="discord">
      <h2 class="small-h">Discord Announcements <span class="badge tone-<?= $dcOn ? 'green' : 'grey' ?>"><?= $dcOn ? 'On' : 'Off' ?></span><?= tip('Posts new versions of public models and accessories (and newly published ones) to a Discord channel: name, version, highlight, changes and picture. Drafts and password items are never posted; blurred ones are posted without the picture.') ?></h2>
      <form method="post" autocomplete="off" class="dc-form" data-discord-form>
        <?= form_keys() ?>
        <button class="dc-enter" name="do" value="discord" tabindex="-1" aria-hidden="true"></button><?php /* Enter in a field = Save, not the test message */ ?>
        <div class="dc-grid">
          <div class="dc-fields">
            <label class="toggle"><input type="checkbox" class="switch" name="discord_on" value="1" <?= $dc['on'] ? 'checked' : '' ?> /><span>Announce New Versions<?= tip('Only versions added from now on are posted, not the existing ones.') ?></span></label>
            <label class="toggle"><input type="checkbox" class="switch" name="discord_stills" value="1" <?= $dc['stills'] ? 'checked' : '' ?> /><span>Announce New Gallery Pictures<?= tip('Pictures added or made visible in Admin → Gallery are posted together as “New render”, ' . intdiv(DISCORD_STILLS_DELAY, 60) . ' minutes after your last change there (time for captions and characters). Drafts are never posted; teaser and patron-only pictures are posted without the image. Existing pictures are not posted.') ?></span></label>
            <label class="field"><span class="lbl">Webhook URL<?= tip('Channel settings (gear icon) → Integrations → Webhooks → New Webhook → “Copy Webhook URL”. Anyone with this URL can post in the channel: keep it private (shown only while editing).') ?></span>
              <input class="input dc-hook" type="url" name="discord_webhook" value="<?= e($dc['webhook']) ?>" placeholder="https://discord.com/api/webhooks/…" spellcheck="false" autocomplete="off" data-dc-hook />
              <span class="dc-hook-state small" data-dc-hook-state></span></label>
            <label class="field"><span class="lbl">Intro Message<?= tip('Optional line above each post, e.g. “New update!”. Use @everyone or a role mention to ping people.') ?></span>
              <input class="input" name="discord_message" value="<?= e($dc['message']) ?>" maxlength="300" placeholder="New update!" data-dc-message /></label>
          </div>
          <div class="dc-side">
            <?php $dcs = m3d_discord_sample(); ?>
            <span class="lbl">Preview<?php if ($dcs): ?> <span class="dc-which">· <?= e($dcs[1]['name'] . ' ' . m3d_version_label(m3d_current_version($dcs[1]))) ?></span><?php endif ?></span>
            <?= discord_preview_html($dc['message']) ?>
          </div>
        </div>
        <div class="form-actions">
          <?php if ($l = $dc['last']): $at = m3d_berlin((string) ($l['at'] ?? ''), 'j M Y, H:i T'); ?>
            <p class="act-left dc-last small muted">Last post, <?= e($at) ?>: <?= !empty($l['ok']) ? '<b class="res-ok">✓</b> ' . e((string) ($l['what'] ?? '')) : '<b class="res-err">failed</b> (' . e((string) ($l['what'] ?? '')) . '): ' . e((string) ($l['error'] ?? '')) . (empty($l['stills']) ? ' It will be tried again the next time you save it.' : ' Pictures are tried again ' . intdiv(DISCORD_STILLS_DELAY, 60) . ' minutes later, ' . DISCORD_STILLS_TRIES . ' times at most.') ?></p>
          <?php endif ?>
          <button class="btn btn-ghost btn-sm" name="do" value="discord-test">Save and Send a Test Message</button><button class="btn btn-primary btn-sm" name="do" value="discord">Save</button>
        </div>
      </form>
    </section>

    <?php $s4 = m3d_s4(); $s4On = m3d_s4_on(); $s4Keys = m3d_s4_keys(); ?>
    <section style="margin-top:20px" class="sec" id="s4">
      <h2 class="small-h">MEGA S4 Downloads <span class="badge tone-<?= $s4On ? 'green' : 'grey' ?>"><?= $s4On ? 'On' : 'Off' ?></span><?= tip('Download links to files in a MEGA S4 bucket (https://s3.….megas4.com/bucket/file) send each download to a temporary address, so an address copied from the browser or shared stops working after the time below. Needs a MEGA plan with S4 (object storage) and an S4 access key. Other links don’t change.') ?></h2>
      <form method="post" autocomplete="off">
        <?= form_keys() ?>
        <button class="dc-enter" name="do" value="s4" tabindex="-1" aria-hidden="true"></button><?php /* Enter in a field = Save */ ?>
        <label class="toggle s4-switch"><input type="checkbox" class="switch" name="s4_on" value="1" <?= $s4['on'] ? 'checked' : '' ?> /><span>Use S4 Downloads<?= tip('Off: S4 is gone from the site (no S4… button in the editors, no S4 checks in Link Check) and “s4:” links don’t download. The keys and bucket stay saved here for later.') ?></span></label>
        <div class="id-grid">
          <label class="field"><span class="lbl">Access Key ID<?= tip('In MEGA, open Object Storage (S4) → Access Keys and create a key. Paste its access key ID here.') ?></span>
            <input class="input" name="s4_key" value="<?= e($s4['key']) ?>" maxlength="128" spellcheck="false" autocomplete="off" /></label>
          <label class="field"><span class="lbl">Secret Key<?= tip('Shown by MEGA when you create the key. Never shown again here: leave empty to keep the current one.') ?></span>
            <input class="input" type="password" name="s4_secret" value="" maxlength="200" placeholder="<?= $s4['secret'] !== '' ? '•••••••• (leave empty to keep it)' : 'Paste the secret key' ?>" spellcheck="false" autocomplete="new-password" /></label>
          <?php $inHours = $s4['minutes'] % 60 === 0; ?>
          <div class="field"><label class="lbl" for="s4-life">Link Lifetime<?= tip('How long a download address works after the patron starts the download. A download already running finishes; resuming it later needs a new click. From ' . S4_MINUTES_MIN . ' minutes to 7 days.') ?></label>
            <div class="s4-life"><input class="input" id="s4-life" type="number" name="s4_life" min="1" max="<?= S4_MINUTES_MAX ?>" step="1" value="<?= $inHours ? intdiv($s4['minutes'], 60) : $s4['minutes'] ?>" />
              <select class="input" name="s4_unit" aria-label="Unit"><option value="minutes"<?= $inHours ? '' : ' selected' ?>>Minutes</option><option value="hours"<?= $inHours ? ' selected' : '' ?>>Hours</option></select></div></div>
          <label class="field"><span class="lbl">Bucket Address<?= tip('Your bucket, e.g. https://s3.g.megas4.com/your-bucket/ (MEGA’s Object URL of any file in it works too). Then a download link only needs “s4:” and the file name: s4:model.zip, or s4:folder/file.zip.') ?></span>
            <input class="input" type="url" name="s4_bucket" value="<?= e($s4['bucket']) ?>" maxlength="1000" placeholder="https://s3.g.megas4.com/your-bucket/" spellcheck="false" /></label>
        </div>
        <?php $s4TestVal = m3d_s4_short($s4['test']) ? ltrim(substr($s4['test'], 3), '/') : $s4['test']; ?>
        <details class="s4-more"<?= $s4TestVal !== '' ? ' open' : '' ?>>
          <summary>Test a Specific File</summary>
          <label class="field"><span class="lbl">Test File<?= tip('Optional. Without it, “Save and Test” lists the bucket and reads the first byte of its first file. With it, only this file is read: its name (model.zip) or its full address.') ?></span>
            <input class="input" name="s4_test" value="<?= e($s4TestVal) ?>" maxlength="1000" placeholder="model.zip" spellcheck="false" /></label>
        </details>
        <div class="form-actions">
          <?php if ($s4Test !== null): ?>
            <p class="act-left dc-last small"><?php if ($s4Test['ok']): $tf = (string) ($s4Test['file'] ?? ''); $tp = $tf !== '' ? m3d_s4_parse($tf) : null; $try = $tp ? m3d_s4_presign($tf) : null; ?>
              <b class="res-ok">✓ Works:</b> <?= isset($s4Test['list']) ? 'bucket ' . e((string) (m3d_s4_parse($s4['bucket'] . 'x')[1] ?? '')) . ', ' . e($s4Test['list']) . ($tp ? ' · ' : '') : '' ?><?= $tp ? e(basename((string) $tp[2])) : '' ?><?= $tp && $s4['bucket'] !== '' && !isset($s4Test['list']) ? ' · in a download link: <code>s4:' . e((string) $tp[2]) . '</code>' : '' ?><?= ($s4Test['size'] ?? 0) > 0 ? ' · ' . e(m3d_size((int) $s4Test['size'])) : '' ?>.
              <?php if ($try): ?><a href="<?= e($try) ?>" target="_blank" rel="noopener noreferrer">Try the Download ↗</a> <span class="muted">(this address stops working in <?= e(m3d_s4_lifetime($s4['minutes'])) ?>)</span><?php endif ?>
            <?php else: ?>
              <b class="res-err">✗ Test failed:</b> <?= e(rtrim((string) $s4Test['err'], '.')) ?>.
            <?php endif ?></p>
          <?php endif ?>
          <?php if ($s4Keys): ?><button class="btn btn-ghost btn-sm" form="s4-remove">Remove Keys</button><?php endif ?>
          <button class="btn btn-ghost btn-sm" name="do" value="s4-test">Save and Test</button><button class="btn btn-primary btn-sm" name="do" value="s4">Save</button>
        </div>
      </form>
      <?php if ($s4Keys): ?>
        <form method="post" id="s4-remove" hidden onsubmit="return confirm('Remove the S4 keys? Links to S4 files stop downloading while your bucket is private.')">
          <?= form_keys('s4-remove') ?>
        </form>
      <?php endif ?>
    </section>

    <?php foreach (FEATURE_SWITCHES as $k => [$title, $help, $label, [$href, $linkText, $newTab]]): $on = !empty(m3d_settings()[$k]['on']); ?>
    <section style="margin-top:20px" class="sec" id="<?= $k ?>">
      <h2 class="small-h"><?= e($title) ?> <span class="badge tone-<?= $on ? 'green' : 'grey' ?>"><?= $on ? 'On' : 'Off' ?></span><?= tip($help) ?></h2>
      <form method="post">
        <?= form_keys($k) ?>
        <label class="toggle"><input type="checkbox" class="switch" name="<?= $k ?>_on" value="1" <?= $on ? 'checked' : '' ?> /><span><?= e($label) ?></span></label>
        <div class="form-actions"><?php if ($on): ?><a class="btn btn-ghost btn-sm" href="<?= $href ?>"<?= $newTab ? ' target="_blank"' : '' ?>><?= e($linkText) ?></a><?php endif ?><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
    </section>
    <?php endforeach ?>

    <?php endif ?>

    <?php if ($tab === 'maintenance'): ?>
    <?php $maint = m3d_settings()['maintenance'] ?? []; $maintOn = !empty($maint['on']); ?>
    <section class="sec maint-sec<?= $maintOn ? ' is-on' : '' ?>" id="maintenance">
      <h2 class="small-h">Maintenance Mode <span class="badge tone-<?= $maintOn ? 'red' : 'grey' ?>"><?= $maintOn ? 'On' : 'Off' ?></span><?= tip('Visitors see a “Back Soon” page and can’t download. Admins can still log in and use the site.') ?></h2>
      <?php if ($maintOn && ($since = m3d_berlin((string) ($maint['since'] ?? ''), 'j F Y, H:i T')) !== ''): ?><p class="muted small sec-hint">On since <?= e($since) ?>.</p><?php endif ?>
      <form method="post" class="maint-form"<?= $maintOn ? '' : ' onsubmit="return confirm(' . e(json_encode('Turn on maintenance mode? Visitors will see the “Back Soon” page until you turn it off.')) . ')"' ?>>
        <?= form_keys('maintenance') ?>
        <label class="field"><span class="lbl">Visitor Message<?= tip('Optional. Shown on the “Back Soon” page.') ?></span>
          <textarea class="input" name="message" rows="2" maxlength="1000" placeholder="The website is being updated. Please come back in a little while."><?= e($maint['message'] ?? '') ?></textarea>
        </label>
        <div class="form-actions">
          <a class="btn btn-ghost btn-sm" href="/" >View Site</a>
          <?php if ($maintOn): ?>
            <button class="btn btn-primary btn-sm" name="state" value="on">Save Message</button>
            <button class="btn btn-primary btn-sm" name="state" value="off">Turn Off Maintenance Mode</button>
          <?php else: ?>
            <button class="btn btn-danger btn-sm" name="state" value="on">Turn On Maintenance Mode</button>
          <?php endif ?>
        </div>
      </form>
    </section>

    <?php // the same problem many times (a broken page visited again and again) is one row with its count
      $probs = [];
      foreach (m3d_problems() as $pr) {
          $k = $pr['where'] . "\n" . $pr['msg'];
          if (isset($probs[$k])) { $probs[$k]['n']++; continue; }
          $probs[$k] = $pr + ['n' => 1];
      }
      $probWeek = count(m3d_problems(time() - 7 * 86400));
      $probTime = fn(int $t) => m3d_berlin($t, 'D j M, H:i T'); ?>
    <section style="margin-top:20px" class="sec" id="problems">
      <h2 class="small-h">Site Problems <span class="badge tone-<?= $probWeek ? 'red' : 'green' ?>"><?= $probWeek ? m3d_plural($probWeek, 'This Week', 'This Week') : 'None This Week' ?></span><?= tip('Errors on the site: failed Discord posts, failed Patreon logins, failed saves, PHP errors. Newest ' . PROBLEMS_KEEP . ' kept. Only the page address is stored, no visitor data.') ?></h2>
      <?php if (!$probs): ?>
        <p class="muted small">Nothing went wrong lately.</p>
      <?php else: ?>
        <ul class="probs">
          <?php foreach (array_slice($probs, 0, 30) as $pr): ?>
            <li>
              <span class="prob-when"><?= e($probTime($pr['at'])) ?><?php if ($pr['n'] > 1): ?> <span class="prob-n" title="The same problem, <?= $pr['n'] ?> times (the newest is shown)">× <?= $pr['n'] ?></span><?php endif ?></span>
              <span class="badge tone-grey"><?= e($pr['where']) ?></span>
              <span class="prob-msg"><?= e($pr['msg']) ?><?php if ($pr['page'] !== ''): ?> <span class="muted">on <?= e($pr['page']) ?></span><?php endif ?></span>
            </li>
          <?php endforeach ?>
        </ul>
        <?php if (count($probs) > 30): ?><p class="muted small">And <?= m3d_plural(count($probs) - 30, 'older problem') ?>.</p><?php endif ?>
        <form method="post" class="form-actions" onsubmit="return confirm('Empty the list of site problems?')"><?= form_keys('problems-clear') ?><button class="btn btn-ghost btn-sm">Clear the List</button></form>
      <?php endif ?>
    </section>

    <section style="margin-top:20px" class="sec" id="tools">
      <h2 class="small-h">Tools</h2>
      <div class="tools">
        <div class="tool">
          <div><b>PHP Cache</b><?= tip('Clear it if a change made with File Manager doesn’t show up.') ?><?php if (!$opcache): ?><p class="muted small">Not active on this server.</p><?php endif ?></div>
          <?= $action('opcache', 'Clear PHP Cache') ?>
        </div>
        <div class="tool">
          <div><b>Active Sessions</b><p class="muted small"><span data-stat="sessions">Counting…</span></p></div>
          <?= $action('sessions', 'Log Everyone Out', 'Log out all visitors except you?', 'sessions') ?>
        </div>
        <div class="tool">
          <div><b>Download Log</b><?= tip('Clearing it resets everyone’s unlocks: limits start from zero.') ?><p class="muted small"><span data-stat="log">Counting…</span> <a href="/admin/log.php?csv=1">Export a copy first</a>.</p></div>
          <?= $action('log', 'Clear Download Log', 'Delete the whole download log? Every patron\'s download history and unlocks go with it, so their limits start from zero. Export it first if you want to keep a copy.', 'log') ?>
        </div>
        <div class="tool">
          <div><b>Backups</b><p class="muted small"><span data-stat="backups">Counting…</span></p></div>
          <a class="btn btn-ghost btn-sm" href="/admin/backups.php">Open Backups</a>
        </div>
        <div class="tool">
          <div><b>Unused Images</b><p class="muted small"><span data-stat="unused">Counting…</span> <a href="/admin/images.php?unused=1">Review them in Images</a>.</p></div>
          <?= $action('unused', 'Delete Unused Images', 'Delete all images and 3D files that are not used anywhere? This can\'t be undone.', 'unused') ?>
        </div>
        <div class="tool">
          <div><b>Temporary Files</b><p class="muted small"><span data-stat="temp">Counting…</span></p></div>
          <?= $action('temp', 'Delete Temporary Files', '', 'temp') ?>
        </div>
        <div class="tool">
          <div><b>Cloudflare Cache</b><?= tip('If a replaced image still shows the old version: Cloudflare → Caching → Configuration → Purge Everything.') ?><p class="muted small">For images that still show the old version.</p></div>
          <a class="btn btn-ghost btn-sm" href="https://dash.cloudflare.com/" target="_blank" rel="noopener">Open Cloudflare ↗</a>
        </div>
      </div>
    </section>

    <section style="margin-top:20px" class="sec" id="system">
      <h2 class="small-h">System</h2>
      <dl class="sysinfo">
        <?php foreach ($checks as [$k, $v, $ok, $stat]): ?><dt><?= e($k) ?></dt><dd class="<?= $ok ? '' : 'res-err' ?>"<?= $stat ? ' data-stat="' . e($stat) . '"' : '' ?>><?= $stat ? 'Counting…' : e($v) ?><?= $ok ? '' : ' ⚠' ?></dd><?php endforeach ?>
      </dl>
    </section>
    <?php endif ?>

<?php admin_end();
