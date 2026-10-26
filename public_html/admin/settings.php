<?php
// Settings, in tabs (?tab=, SETTINGS_TABS in _admin.php): Site (identity, social links, homepage section, notice, time zone), Patreon (keys, currency,
// how long logins last), Features (optional pages and tools), Maintenance (maintenance mode, tools: caches, sessions, log, backups, unused images,
// temporary files; system info), Privacy (legal & contact, one person's data as a file: _privacy.php). Every form posts to the page's own address, so a save comes back on the same tab.
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
            [$n, , $n3, $nv] = media_purge_unused();
            $done = $n || $n3 || $nv ? unused_words($n, $n3, $nv) . ' that weren’t used deleted.' : 'There were no unused files to delete.';
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
                $id = ['name' => $one('id_name', 40), 'highlight' => $one('id_highlight', 20), 'tagline' => $one('id_tagline', 90), 'description' => $one('id_description', 200)];
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
        case 'menu':
            try {
                $save(['menu' => admin_menu_posted()], 'Menu saved: the header and the footer show it in this order.');
            } catch (InvalidArgumentException $ex) {
                $error = $ex->getMessage();
            }
            break;
        case 'menu-reset':
            $save(['menu' => null], 'Menu back to the built-in items in their usual order.');
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
                if ($new['redirect_uri'] === '') $new['redirect_uri'] = abs_url('/auth/callback.php', true);   // the address the field suggests
                foreach (['client_id' => 'Client ID', 'client_secret' => 'Client secret'] as $k => $label) {
                    if ($new[$k] !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $new[$k])) throw new InvalidArgumentException("The $label doesn’t look right: copy it again from your Patreon client page (letters, numbers, - and _ only).");
                }
                foreach (['creator_token' => 'Creator’s Access Token', 'creator_refresh' => 'Creator’s Refresh Token'] as $k => $label) {
                    if ($new[$k] !== '' && !preg_match('/^[A-Za-z0-9_\-.]{10,400}$/', $new[$k])) throw new InvalidArgumentException("The $label doesn’t look right: copy it again from your Patreon client page.");
                }
                if ($new['redirect_uri'] !== '' && (!preg_match('#^https?://[^\s<>"]+/auth/callback\.php$#i', $new['redirect_uri']) || !filter_var($new['redirect_uri'], FILTER_VALIDATE_URL))) {
                    throw new InvalidArgumentException('The redirect address must be this site’s address followed by /auth/callback.php, e.g. ' . abs_url('/auth/callback.php', true));
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
                break;
            }
            m3d_session_pause(fn() => m3d_tiers_sync());   // the tier list too, now that the token works (its result shows under the form)
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
        case 'pd-delete':   // Privacy → Personal Data → Delete All Their Data (the ID typed again to confirm)
            require_once __DIR__ . '/_privacy.php';
            $pdId = trim((string) ($_POST['id'] ?? ''));
            if (!preg_match('/^[\w-]{1,40}$/', $pdId) || trim((string) ($_POST['typed'] ?? '')) !== $pdId) { $error = 'Type the Patreon user ID exactly to confirm: nothing was deleted.'; break; }
            [$pdDone, $pdFail] = pd_delete($pdId);
            if ($pdFail) $error = 'Could not delete: ' . implode(', ', $pdFail) . ($pdDone ? '. Deleted: ' . implode(', ', $pdDone) : '') . '.';
            else $done = $pdDone ? 'Deleted for Patreon ID ' . $pdId . ': ' . implode(', ', $pdDone) . '.' : 'Nothing was stored for Patreon ID ' . $pdId . '.';
            break;
        case 'timezone':
            $tzId = (string) ($_POST['timezone'] ?? '');
            if (!in_array($tzId, DateTimeZone::listIdentifiers(), true)) { $error = 'Pick a time zone from the list.'; break; }
            $tzDone = (array) (m3d_settings(true)['release']['done'] ?? []);
            if (!$save(['timezone' => $tzId === SITE_TZ_DEFAULT ? null : $tzId], 'Time zone saved: ' . str_replace('_', ' ', $tzId) . '.')) break;
            // a version day starts at another moment now: its release code changes, so the posted ones keep their mark and the next due time is worked out again
            $tzKeys = [];
            foreach (m3d_release_events() as $code => $ev) $tzKeys["$ev[0]:$ev[1]:$ev[2]"] = $code;
            m3d_release_store(array_map(fn($c) => preg_match('/^(.+:v):\d+$/', (string) $c, $mm) && isset($tzKeys[$mm[1]]) ? $tzKeys[$mm[1]] : $c, $tzDone));
            break;
        case 'discord':
        case 'discord-test':
        case 'discord-forget':
            // the webhook lives in private/webhooks.php and is never sent back to the page: an empty field keeps the saved one
            $dc = m3d_discord();
            $forget = $_POST['do'] === 'discord-forget';
            $typed = trim((string) ($_POST['discord_webhook'] ?? ''));
            $hook = $forget ? '' : ($typed !== '' ? $typed : $dc['webhook']);
            $msg = mb_substr(trim(str_replace(["\r", "\n"], ' ', (string) ($_POST['discord_message'] ?? ''))), 0, 300);
            $on = !$forget && !empty($_POST['discord_on']);
            if ($typed !== '' && !preg_match(DISCORD_WEBHOOK_RE, $typed)) { $error = 'That isn’t a Discord webhook address: in Discord, open the channel’s settings → Integrations → Webhooks, then “Copy Webhook URL”.'; break; }
            if ($on && $hook === '') { $error = 'Paste the webhook address first.'; break; }
            if ($hook !== (m3d_webhooks()['discord'] ?? '') && !m3d_webhooks_save(['discord' => $hook])) { $error = 'Could not write ' . basename(M3D_PRIVATE) . '/webhooks.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.'; break; }
            $stored = m3d_settings()['discord'] ?? [];
            $new = ['on' => $on, 'message' => $msg, 'stills' => !$forget && !empty($_POST['discord_stills'])] + $stored;
            if (empty($new['stills'])) unset($new['stills_queue'], $new['stills_due'], $new['stills_tries']);   // off: pictures waiting aren't posted
            // turning it on: every version already out counts as posted, so only new ones are announced
            if ($on && (!$dc['on'] || $hook !== $dc['webhook'])) $new['sent'] = array_slice(array_values(array_unique(array_merge($dc['sent'], m3d_discord_current_codes()))), -DISCORD_SENT_MAX);
            if (!$save(['discord' => $new], $forget ? 'Webhook removed: Discord announcements are off.' : ($on ? 'Discord announcements are on: the next new version you save goes to the channel.' : 'Discord announcements are off.'))) break;
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
        case 'notify':
        case 'notify-test':
        case 'notify-forget':
            // the webhook lives in private/webhooks.php and is never sent back to the page: an empty field keeps the saved one
            $forget = $_POST['do'] === 'notify-forget';
            $typed = trim((string) ($_POST['notify_webhook'] ?? ''));
            $hook = $forget ? '' : ($typed !== '' ? $typed : m3d_notify_settings(true)['webhook']);
            $new = ['bell' => [], 'discord' => []];
            foreach (NOTIFY_EVENTS as $k => $_) {
                $new['bell'][$k] = !empty($_POST['bell_' . $k]);
                $new['discord'][$k] = !$forget && !empty($_POST['discord_' . $k]);
            }
            if ($typed !== '' && !preg_match(DISCORD_WEBHOOK_RE, $typed)) { $error = 'That isn’t a Discord webhook address: in Discord, open the channel’s settings → Integrations → Webhooks, then “Copy Webhook URL”.'; break; }
            if ($hook === '' && (array_filter($new['discord']) || $_POST['do'] === 'notify-test')) { $error = 'Paste the webhook address of your private channel first.'; break; }
            // the old Bug Reports / Comments webhooks are read only until this is saved (m3d_notify_settings()): they go
            if (!m3d_webhooks_save(['notify' => $hook, 'bugs' => null, 'comments' => null])) { $error = 'Could not write ' . basename(M3D_PRIVATE) . '/webhooks.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.'; break; }
            $ntOk = $save(['notify' => $new], $forget ? 'Webhook removed: nothing goes to Discord.' : 'Notifications saved.');
            m3d_notify_settings(true);   // the form below shows what is saved now
            if (!$ntOk) break;
            if ($_POST['do'] === 'notify-test') {
                $done = null;
                $on = array_map(fn($k) => NOTIFY_EVENTS[$k], array_keys(array_filter($new['discord'])));
                $res = m3d_session_pause(fn() => m3d_discord_post($hook, '', [['title' => 'Test message from ' . site_name(), 'url' => abs_url('/admin/settings.php?tab=notify'),
                    'description' => $on ? "This channel gets:\n• " . implode("\n• ", $on) : 'Nothing is switched on for Discord yet.', 'color' => hexdec('e8b931'),
                    'footer' => ['text' => 'Settings → Notifications']]]));
                if ($res['ok']) $done = 'Test message sent: check the Discord channel.';
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
                'domain' => m3d_s4_domain_clean((string) ($_POST['s4_domain'] ?? '')),
            ];
            if ($s4['key'] === '' && trim((string) ($_POST['s4_secret'] ?? '')) === '' && $cur['key'] === '') { $error = 'Paste the access key ID and the secret key.'; break; }
            if (!preg_match('/^[A-Za-z0-9]{8,128}$/', $s4['key'])) { $error = 'The access key ID doesn’t look right: copy it again from MEGA (letters and numbers only).'; break; }
            if ($s4['secret'] === '') { $error = 'Paste the secret key.'; break; }
            if ($s4['minutes'] < S4_MINUTES_MIN || $s4['minutes'] > S4_MINUTES_MAX) { $error = 'Link lifetime: from ' . S4_MINUTES_MIN . ' minutes to ' . intdiv(S4_MINUTES_MAX, 60) . ' hours (7 days).'; break; }
            if (!preg_match('#^[\x21-\x7e]{16,200}$#', $s4['secret'])) { $error = 'The secret key doesn’t look right: copy it again from MEGA (no spaces).'; break; }
            if (trim((string) ($_POST['s4_bucket'] ?? '')) !== '' && $s4['bucket'] === '') { $error = 'The bucket address isn’t an S4 address. It looks like https://s3.g.megas4.com/your-bucket/'; break; }
            if (trim((string) ($_POST['s4_domain'] ?? '')) !== '' && $s4['domain'] === '') { $error = 'The download domain should be your own address, like files.example.com.'; break; }
            if ($s4['domain'] !== '' && $s4['bucket'] === '') { $error = 'The download domain needs the Bucket Address: the Worker passes downloads on to its host.'; break; }
            if ($_POST['do'] === 's4-test') {
                if ($s4['bucket'] === '') { $error = 'Fill in the Bucket Address, then test again.'; break; }
                // the region the signature names: S4 doesn't tie a bucket to one, so the saved one first, then the others;
                // any answer but a refused signature means the keys and the region work.
                // The test: the bucket's list, then the first byte of the first file found (reading and listing both checked);
                // no file at the top: the folders under it, a few lists at most
                $regions = array_values(array_unique(array_merge([$s4['region']], S4_REGIONS)));
                $refused = ['SignatureDoesNotMatch', 'AuthorizationHeaderMalformed', 'AuthorizationQueryParametersError', 'InvalidRequest'];
                $s4Test = m3d_session_pause(function () use ($regions, $refused, &$s4) {
                    foreach ($regions as $rg) {
                        $l = m3d_s4_list('', $s4, $rg, 1);
                        if ($l['code'] !== '' && in_array($l['code'], $refused, true)) continue;
                        $s4['region'] = $rg;
                        if (!$l['ok']) return ['ok' => false, 'err' => $l['err']];
                        $t = ['ok' => true, 'size' => 0, 'err' => '', 'file' => '',
                              'list' => count($l['files']) . ($l['more'] ? '+' : '') . ' ' . (count($l['files']) === 1 ? 'file' : 'files') . ($l['folders'] ? ', ' . m3d_plural(count($l['folders']), 'folder') : '')];
                        $first = $l['files'][0]['key'] ?? '';
                        for ($todo = $l['folders'], $asks = 0; $first === '' && $todo && $asks < 5; $asks++) {
                            $sub = m3d_s4_list((string) array_shift($todo), $s4, $rg, 1);
                            if (!$sub['ok']) return ['ok' => false, 'err' => 'The list works, but listing the folder ' . rtrim((string) $sub['prefix'], '/') . ' failed: ' . $sub['err']];
                            $first = $sub['files'][0]['key'] ?? '';
                            $todo = array_merge($sub['folders'], $todo);
                        }
                        if ($first === '') return ['ok' => false, 'err' => $l['files'] || $l['folders']
                            ? 'The list works, but no file was found near the top of the bucket: put a file at its top level, then test again'
                            : 'The list works, but the bucket is empty: upload a file to it, then test again'];
                        $t['file'] = 's4:' . $first;
                        $r = m3d_s4_probe($t['file'], $rg, $s4);
                        return $r['ok'] ? ['size' => $r['size']] + $t : ['ok' => false, 'err' => 'The list works, but reading ' . $first . ' failed: ' . ($r['code'] === 0 ? 'S4 didn’t answer (' . $r['err'] . ')' : m3d_s4_error_text($r['err']))];
                    }
                    return ['ok' => false, 'err' => m3d_s4_error_text('SignatureDoesNotMatch')];
                });
                // the same file through the Download Domain (the proxy in front of the bucket), when it is served there
                if ($s4Test['ok'] && ($s4Test['file'] ?? '') !== '' && $s4['domain'] !== '') {
                    $v = m3d_session_pause(fn() => m3d_s4_probe((string) $s4Test['file'], $s4['region'], $s4, true));
                    if ($v['err'] !== 'not via domain') $s4Test['via'] = $v;
                }
            }
            if (!m3d_s4_save($s4)) { $error = 'Could not write ' . basename(M3D_PRIVATE) . '/s4.php. Check that the ' . basename(M3D_PRIVATE) . ' folder is writable.'; $s4Test = null; break; }
            $done = $_POST['do'] === 's4-test' ? null : ($s4['on'] ? 'S4 downloads on: download links to S4 files get a temporary address.' : 'S4 downloads off: S4 is hidden everywhere else and "s4:" links don’t download; the keys and bucket stay saved here.');
            break;
        case 's4-remove':
            $done = m3d_s4_save([]) ? 'S4 keys removed: S4 links are no longer signed (they won’t download while the bucket is private).' : null;
            if (!$done) $error = 'Could not delete ' . basename(M3D_PRIVATE) . '/s4.php.';
            break;
        case 'bugs':
            $host = trim((string) ($_POST['bugs_host'] ?? ''));
            if ($host !== '' && !is_https_url($host)) { $error = 'The upload website must be an address starting with https://'; break; }
            $on = !empty($_POST['bugs_on']);
            $ok = m3d_bug_settings_save(['on' => $on, 'who' => ($_POST['bugs_who'] ?? '') === 'paying' ? 'paying' : 'any', 'host' => mb_substr($host, 0, 300),
                'uploads' => !empty($_POST['bugs_uploads']), 'moderate' => !empty($_POST['bugs_moderate']),
                'per_day' => max(1, min(50, (int) ($_POST['bugs_per_day'] ?? 5)))]);
            if ($ok) $done = $on ? 'Bug reports are on: /bugs/ is in the footer and on model and accessory pages.' : 'Bug reports are off: /bugs/ shows “not found” to visitors.';
            else $error = m3d_write_error();
            break;
        case 'comments':
            $on = !empty($_POST['cm_on']);
            $ok = m3d_comment_settings_save(['on' => $on, 'models' => !empty($_POST['cm_models']), 'outfits' => !empty($_POST['cm_outfits']), 'posts' => !empty($_POST['cm_posts']),
                'who' => ($_POST['cm_who'] ?? '') === 'paying' ? 'paying' : 'any', 'moderate' => !empty($_POST['cm_moderate']),
                'paid_skip' => !empty($_POST['cm_paid_skip']), 'per_day' => max(1, min(100, (int) ($_POST['cm_per_day'] ?? 10)))]);
            if ($ok) $done = $on ? 'Comments are on: they show under the pages you picked (each page can turn them off in its editor).' : 'Comments are off: none are shown, and they are kept.';
            else $error = m3d_write_error();
            break;
        case 'comments-approve':
        case 'comments-delete':
            $id = (int) ($_POST['id'] ?? 0);
            $del = $_POST['do'] === 'comments-delete';
            if (!m3d_comment_find($id)) { $done = 'That comment is gone already.'; break; }
            if (m3d_comment_change($id, function ($c) use ($del) { if ($del) return null; unset($c['pending']); return $c; })) {
                $done = $del ? 'Comment deleted.' : 'Comment published.';
                if (!$del) m3d_notify_read('c' . $id);   // read in the bell too, as Publish there does
            } else $error = m3d_write_error();
            break;
        case 'comments-clear':
            $n = m3d_comments_clear();
            if ($n === null) $error = m3d_write_error();
            else $done = $n ? 'Deleted ' . m3d_plural($n, 'comment') . ': every page starts with none.' : 'There were no comments to delete.';
            break;
        case 'blog':
            $on = !empty($_POST['blog_on']);
            $txt = fn(string $k, int $max) => trim(mb_substr(preg_replace('/\s+/u', ' ', is_string($_POST[$k] ?? null) ? $_POST[$k] : ''), 0, $max));
            $save(['blog' => ['on' => $on, 'title' => $txt('blog_title', 80), 'intro' => $txt('blog_intro', 300)]],
                $on ? 'The blog is on: it is in the menu once it has a post.' : 'The blog is off: /blog/ shows “not found” to visitors.');
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
        case 'showcase':
            $on = !empty($_POST['sc_on']);
            $max = trim((string) ($_POST['sc_per_day'] ?? ''));
            if (m3d_showcase_settings_save(['on' => $on, 'per_day' => $max === '' ? 0 : max(0, min(SHOWCASE_PER_DAY_MAX, (int) $max))]))
                $done = $on ? 'Patron renders are on: patrons can share theirs from model and accessory pages.' : 'Patron renders are off: nobody can share new ones and none show on the pages.';
            else $error = m3d_write_error();
            break;
        case 'mustardui':
            $k = $_POST['do'];
            $on = !empty($_POST[$k . '_on']);
            $save([$k => ['on' => $on]], FEATURE_SWITCHES[$k][$on ? 4 : 5]);
            break;
        case 'tiers-sync':
            $st = m3d_session_pause(fn() => m3d_tiers_sync());
            if ($st['ok']) $done = 'Tiers read from Patreon: ' . m3d_plural(count(m3d_known_tiers()), 'paid tier') . '.';
            else $error = 'Tiers not read: ' . ($st['err'] ?? 'unknown error');
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
    define('STATS_UNTIL', microtime(true) + 25);   // stop well before the server or a proxy in front of it gives up, and say so
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
            $unVid = unused_videos($use);
            [$unN, $un3, $unV, $unB] = [count($unImg), count($un3d), count($unVid), array_sum(array_column($unImg, 'bytes')) + array_sum(array_column($un3d, 'bytes')) + array_sum(array_column($unVid, 'bytes'))];
            [$tmpN, $tmpB] = files_stats(array_unique(temp_files($media)));
            [$medN, $medB] = files_stats($media);
            $out = [
                'unused' => ['text' => unused_words($unN, $un3, $unV) . ' not used anywhere (' . m3d_size($unB) . ').', 'n' => $unN + $un3 + $unV],
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

// Privacy → Personal Data: one person's data as a file (a readable page, or JSON). Read only, nothing is logged.
if (settings_tab() === 'privacy') require_once __DIR__ . '/_privacy.php';
if (settings_tab() === 'privacy' && ($exportId = m3d_get_text('export')) !== '' && preg_match('/^[\w-]{1,40}$/', $exportId)) {
    session_write_close();
    $pd = pd_collect($exportId);
    $json = m3d_get_text('as') === 'json';
    header('Content-Type: ' . ($json ? 'application/json' : 'text/html') . '; charset=utf-8');
    header('Content-Disposition: attachment; filename="patreon-' . $exportId . '-data-' . m3d_today() . ($json ? '.json' : '.html') . '"');
    header('Cache-Control: no-store');
    echo $json ? json_encode(pd_json($pd), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : pd_html($pd);
    exit;
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
          <label class="field"><span class="lbl">Highlighted Word</span><input class="input" name="id_highlight" value="<?= e($id['highlight']) ?>" maxlength="20" data-id="highlight" /></label>
          <label class="field id-wide"><span class="lbl">Tagline</span><input class="input" name="id_tagline" value="<?= e($id['tagline']) ?>" maxlength="90" required data-id="tagline" /></label>
          <label class="field id-wide"><span class="lbl">Search Description<?= tip('The text Google and other search engines show under the homepage’s title, and in link previews of the homepage. One or two sentences about the site (about 150 characters). Empty = the tagline.') ?></span><input class="input" name="id_description" value="<?= e($id['description']) ?>" maxlength="200" placeholder="<?= e($id['tagline']) ?>." /></label>
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

    <?php $menuSaved = isset(m3d_settings()['menu']); ?>
    <section style="margin-top:20px" class="sec" id="menu">
      <h2 class="small-h">Header Menu<?= tip('Built-in items show only while their page has something to show. Links to other websites open in a new tab.') ?></h2>
      <form method="post" class="menu-form" data-rows-form>
        <?= form_keys('menu') ?>
        <?= admin_site_pages_datalist() ?>
        <div class="menu-rows" data-rows><?php foreach (menu_entries() as $i => $it) echo admin_menu_row($it['key'] !== '' ? $it['key'] : 'c' . $i, $it['key'], $it['label'], $it['url'], !$it['off']); ?></div>
        <template data-row-template><?= admin_menu_row('') ?></template>
        <div class="form-actions">
          <button type="button" class="btn btn-ghost btn-sm act-left" data-row-add>+ Add Link</button>
          <?php if ($menuSaved): ?><button class="btn btn-ghost btn-sm" form="menu-reset" title="Only the built-in items, in their usual order">Reset</button><?php endif ?>
          <button class="btn btn-primary btn-sm">Save Menu</button>
        </div>
      </form>
      <?php if ($menuSaved): ?>
        <form method="post" id="menu-reset" hidden onsubmit="return confirm('Go back to the usual menu? Your added links are removed and hidden items shown again.')"><?= form_keys('menu-reset') ?></form>
      <?php endif ?>
    </section>

    <?php $pl = home_plans(); ?>
    <details style="margin-top:20px" class="sec fold" id="homepage"<?= $open('plans') ?>>
      <summary><h2 class="small-h">Homepage Section <span class="badge tone-<?= $pl['on'] ? 'green' : 'grey' ?>"><?= $pl['on'] ? 'Shown' : 'Hidden' ?></span><?= tip('Two boxes at the bottom of the homepage explaining how to get the downloads (e.g. free releases and Patreon membership). Hidden for patrons who can already download.') ?></h2></summary>
      <div class="fold-body">
      <form method="post" class="pl-form">
        <?= form_keys('plans') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="pl_on" value="1" <?= $pl['on'] ? 'checked' : '' ?> /><span>Show Section</span></label>
        <div class="id-grid">
          <label class="field"><span class="lbl">Small Heading</span><input class="input" name="pl_eyebrow" value="<?= e($pl['eyebrow']) ?>" maxlength="40" placeholder="Downloads" /></label>
          <label class="field"><span class="lbl">Title</span><input class="input" name="pl_head" value="<?= e($pl['title']) ?>" maxlength="80" placeholder="How to get the models" /></label>
        </div>
        <div class="pl-boxes">
          <?php foreach ($pl['boxes'] as $i => $b): ?>
            <fieldset class="pl-box<?= $i === 1 ? ' pl-hot' : '' ?>">
              <legend><?= $i === 0 ? 'Box 1' : 'Box 2 (Highlighted)' ?></legend>
              <label class="field"><span class="lbl">Tag</span><input class="input" name="pl_tag_<?= $i ?>" value="<?= e($b['tag']) ?>" maxlength="40" placeholder="<?= $i ? 'Membership' : 'Open access' ?>" /></label>
              <label class="field"><span class="lbl">Title</span><input class="input" name="pl_title_<?= $i ?>" value="<?= e($b['title']) ?>" maxlength="80" placeholder="<?= $i ? 'Every download' : 'Free releases' ?>" /></label>
              <label class="field"><span class="lbl">Text</span><textarea class="input" name="pl_text_<?= $i ?>" rows="3" maxlength="600"><?= e($b['text']) ?></textarea></label>
              <label class="field"><span class="lbl">Bullet Points</span><textarea class="input" name="pl_items_<?= $i ?>" rows="3"><?= e(implode("
", $b['items'])) ?></textarea></label>
              <div class="field"><span class="lbl">Buttons<?= tip('Discord and Patreon use the Social Links addresses (left out when not set). “Log In” opens the Patreon login.') ?></span>
                <span class="pl-btns"><?php foreach (PLAN_BUTTONS as $k => $label): ?><label class="check small"><input type="checkbox" name="pl_btn_<?= $i ?>[]" value="<?= e($k) ?>" <?= in_array($k, $b['buttons'], true) ? 'checked' : '' ?> /> <?= e($label) ?></label><?php endforeach ?></span>
              </div>
              <label class="field"><span class="lbl">Footnote</span><input class="input" name="pl_fine_<?= $i ?>" value="<?= e($b['fine']) ?>" maxlength="300" /></label>
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
          <label class="field"><span class="lbl">End Date<?= tip('The notice shows until the end of this day.') ?></span><input class="input" type="date" name="notice_until" value="<?= e($notice['until']) ?>" /></label>
        </div>
        <label class="field"><span class="lbl">Message</span>
          <textarea class="input" name="notice_text" rows="2" maxlength="600" placeholder="**New:** a new model is out for patrons! [See it](/models/your-model/)"><?= e($notice['text']) ?></textarea>
        </label>
        <label class="toggle"><input type="checkbox" class="switch" name="notice_closable" value="1" <?= $notice['closable'] ? 'checked' : '' ?> /><span>Allow Closing<?= tip('It shows again when the message changes.') ?></span></label>
        <div class="form-actions"><button class="btn btn-primary btn-sm">Save Notice</button></div>
      </form>
      <?php if ($notice['text'] !== ''): ?>
        <p class="lbl notice-prev-lbl">Preview</p>
        <div class="notice-prev"><?= notice_html($notice, true) ?></div>
      <?php endif ?>
      </div>
    </details>

    <section style="margin-top:20px" class="sec" id="timezone">
      <h2 class="small-h">Time Zone<?= tip('The clock the site counts in: the release dates you type in the editor, when a changelog day starts, “today” on Admin → Home, the download log and the site notice’s last day. Visitors see release dates and unlock times in their own time zone.') ?></h2>
      <form method="post" class="days-form tz-form">
        <?= form_keys('timezone') ?>
        <span class="lbl">Site Time Zone</span>
        <select class="input" name="timezone" aria-label="Site time zone">
          <?php $tzNow = m3d_tz_id(); $tzGroup = '';
          foreach (DateTimeZone::listIdentifiers() as $id):
              $g = strstr($id, '/', true) ?: 'Other';
              if ($g !== $tzGroup): ?><?= $tzGroup !== '' ? '</optgroup>' : '' ?><optgroup label="<?= e($g) ?>"><?php $tzGroup = $g; endif;
              $off = (new DateTimeImmutable('now', new DateTimeZone($id)))->format('P'); ?>
            <option value="<?= e($id) ?>"<?= $id === $tzNow ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $id)) ?> · UTC<?= e($off === '+00:00' ? '' : preg_replace(['/^([+-])0?(\d+):(\d\d)$/', '/:00$/'], ['$1$2:$3', ''], $off)) ?></option>
          <?php endforeach ?></optgroup>
        </select>
        <span class="muted small tz-now">Now <?= e(m3d_site_time(time(), 'H:i T')) ?></span>
        <button class="btn btn-primary btn-sm">Save</button>
      </form>
    </section>

    <?php endif ?>

    <?php if ($tab === 'notify'): ?>
    <?php $ns = m3d_notify_settings(); ?>
    <section class="sec" id="notify">
      <h2 class="small-h">Notifications</h2>
      <form method="post" autocomplete="off" class="set-list">
        <?= form_keys() ?>
        <button class="dc-enter" name="do" value="notify" tabindex="-1" aria-hidden="true"></button><?php /* Enter in a field = Save, not the test message */ ?>
        <div class="nt-set" role="table" aria-label="Notifications">
          <div class="nt-set-row nt-set-head" role="row"><span role="columnheader">Event</span><span role="columnheader">Bell</span><span role="columnheader">Discord</span></div>
          <?php foreach (NOTIFY_EVENTS as $k => $label):
              $off = !m3d_notify_feature($k); ?>
            <div class="nt-set-row<?= $off ? ' is-off' : '' ?>" role="row">
              <span role="cell"><?= e($label) ?><?php if ($off): ?> <span class="muted small">Off in Features</span><?php endif ?></span>
              <span role="cell"><input type="checkbox" class="switch" name="bell_<?= $k ?>" value="1" aria-label="<?= e($label) ?> in the bell" <?= $ns['bell'][$k] ? 'checked' : '' ?> /></span>
              <span role="cell"><input type="checkbox" class="switch" name="discord_<?= $k ?>" value="1" aria-label="<?= e($label) ?> on Discord" <?= $ns['discord'][$k] ? 'checked' : '' ?> /></span>
            </div>
          <?php endforeach ?>
        </div>
        <label class="field"><span class="lbl">Private Webhook URL<?= tip('A Discord channel only you can read: posts include patrons’ names and what they wrote. In Discord: channel settings → Integrations → Webhooks → New Webhook → “Copy Webhook URL”.') ?></span>
          <?php /* never printed back (private/webhooks.php): an empty field keeps the saved one */ ?>
          <?= key_field('Private Webhook URL', $ns['webhook'] !== '', null, 'class="dc-hook" type="url" name="notify_webhook" placeholder="https://discord.com/api/webhooks/…" spellcheck="false" autocomplete="off"') ?></label>
        <div class="form-actions">
          <?php if ($ns['webhook'] !== ''): ?><button class="btn btn-ghost btn-sm" name="do" value="notify-forget" onclick="return confirm('Remove the saved webhook? Nothing goes to Discord until you paste one again.')">Remove Webhook</button><?php endif ?>
          <button class="btn btn-ghost btn-sm" name="do" value="notify-test">Save and Send a Test</button><button class="btn btn-primary btn-sm" name="do" value="notify">Save</button>
        </div>
      </form>
    </section>
    <?php endif ?>

    <?php if ($tab === 'patreon'): ?>
    <?php $pt = m3d_patreon_saved();
          $ptVal = fn(string $k) => (string) ($pt[$k] ?? '');
          $ptReal = fn(string $k) => m3d_cfg_real($ptVal($k));
          $ptFrom = fn(string $k) => $ptReal($k) ? '' : '<span class="badge tone-red">Missing</span>';
          $callback = abs_url('/auth/callback.php', true); ?>
    <?php $tst = m3d_tiers_state(); $tiersKnown = m3d_known_tiers(); $hasTok = !empty($pt['creator_token']);
          $ptMiss = count(array_filter(['client_id', 'client_secret', 'redirect_uri', 'campaign_id'], fn($k) => !$ptReal($k)));
          $tokBadge = !$hasTok ? ['grey', 'Not Saved'] : (!$tst ? ['grey', 'Not Tested'] : (!empty($tst['ok']) ? ['green', 'Working'] : ['red', 'Not Working'])); ?>
    <section class="sec" id="campaign">
      <h2 class="small-h">Campaign</h2>
      <form method="post" class="cur-form pt-cur">
        <?= form_keys('currency') ?>
        <label class="field"><span class="lbl">Currency<?= tip('Used for “total support” requirements and tier prices. Set automatically at patron login.') ?></span>
          <select class="input" name="currency">
            <?php foreach (['EUR' => 'Euro (€)', 'USD' => 'US Dollar ($)', 'GBP' => 'British Pound (£)', 'CAD' => 'Canadian Dollar', 'AUD' => 'Australian Dollar', 'JPY' => 'Japanese Yen'] as $k => $l): ?><option value="<?= $k ?>" <?= m3d_currency() === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
          </select>
        </label>
        <button class="btn btn-primary btn-sm">Save</button>
      </form>
      <form method="post" class="pt-tiers" id="campaign-tiers" data-busy="Reading…">
        <?= form_keys('tiers-sync') ?>
        <span class="lbl">Paid Tiers<?= tip('The tiers offered in the unlock limits, the download requirements and on How Unlocks Work. With the creator token saved, they are read from Patreon once a day; without it, the site learns a tier when a patron on it logs in.') ?></span>
        <?php if ($tiersKnown): ?><ul class="pt-tier-list"><?php foreach ($tiersKnown as $c => $t): ?><li><span class="pt-tier-name"><?= e($t) ?></span><span class="pt-tier-price"><?= e(m3d_money($c)) ?><small> / month</small></span></li><?php endforeach ?></ul>
        <?php else: ?><p class="pt-tier-none muted small">None yet.</p><?php endif ?>
        <div class="pt-tiers-foot">
          <span class="muted small"><?php if (!$hasTok): ?>Learned from patron logins. Save the creator token in Patreon Keys to read them from Patreon.<?php elseif (!$tst): ?>Not read from Patreon yet.<?php elseif (!empty($tst['ok'])): ?>Read from Patreon <?= e(m3d_site_time((int) $tst['at'], 'j M Y, H:i T')) ?>.<?php else: ?><span class="pt-err">Last try <?= e(m3d_site_time((int) $tst['at'], 'j M Y, H:i T')) ?> failed: <?= e((string) ($tst['err'] ?? '')) ?></span><?php endif ?></span>
          <?php if ($hasTok): ?><button class="btn btn-ghost btn-sm">Read Tiers Now</button><?php endif ?>
        </div>
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

    <?php /* rarely changed: folded at the bottom, open while a key is missing or just saved */ ?>
    <details style="margin-top:20px" class="sec fold" id="patreon"<?= $ptMiss ? ' open' : $open('patreon') ?>>
      <summary><h2 class="small-h">Patreon Keys <span class="badge tone-<?= $ptMiss ? 'red' : 'green' ?>"><?= $ptMiss ? $ptMiss . ' Missing' : 'Saved' ?></span><?= tip('The keys of your Patreon client (patreon.com/portal/registration/register-clients). Change them only when you move the site or make a new client.') ?></h2></summary>
      <div class="fold-body">
      <form method="post" class="pt-form" autocomplete="off" onsubmit="return confirm(<?= e(json_encode('Save the Patreon keys? If they are wrong, nobody can log in (you stay logged in here). Test in a private window afterwards.')) ?>)">
        <?= form_keys('patreon') ?>
        <div class="id-grid">
          <label class="field"><span class="lbl">Client ID <?= $ptFrom('client_id') ?><?= tip('From patreon.com/portal/registration/register-clients → your client → “Client ID”.') ?></span>
            <?= key_field('Client ID', $ptReal('client_id'), $ptReal('client_id') ? $ptVal('client_id') : '', 'name="pt_client_id" spellcheck="false" autocomplete="off"') ?></label>
          <label class="field"><span class="lbl">Client Secret <?= $ptFrom('client_secret') ?></span>
            <?= key_field('Client Secret', $ptReal('client_secret'), null, 'type="password" name="pt_client_secret" placeholder="Paste the client secret" spellcheck="false" autocomplete="new-password"') ?></label>
          <label class="field id-wide"><span class="lbl">Redirect URI <?= $ptFrom('redirect_uri') ?><?= tip('Must match one of your Patreon client’s “Redirect URIs” exactly.') ?></span>
            <span class="pt-row"><?= key_field('Redirect URI', $ptReal('redirect_uri'), $ptReal('redirect_uri') ? $ptVal('redirect_uri') : '', 'name="pt_redirect_uri" placeholder="' . e($callback) . '" spellcheck="false" data-pt-redirect') ?>
              <?php if ($ptVal('redirect_uri') !== $callback): ?><button type="button" class="btn btn-ghost btn-sm" data-pt-use="<?= e($callback) ?>" title="<?= e($callback) ?>">Use This Site’s Address</button><?php endif ?></span></label>
          <label class="field"><span class="lbl">Campaign ID <?= $ptFrom('campaign_id') ?></span>
            <?= key_field('Campaign ID', $ptReal('campaign_id'), $ptReal('campaign_id') ? $ptVal('campaign_id') : '', 'name="pt_campaign_id" inputmode="numeric" spellcheck="false"') ?></label>
        </div>
        <div class="pt-sub">
          <h3>Creator Token <span class="badge tone-<?= $tokBadge[0] ?>"><?= e($tokBadge[1]) ?></span><?= tip('Optional. Lets the site read your campaign: patrons’ pledge history at login (months as a patron) and the paid tiers. Both are on your Patreon client’s page, under the Client Secret.') ?></h3>
          <div class="id-grid">
          <?php foreach (['creator_token' => 'Creator’s Access Token', 'creator_refresh' => 'Creator’s Refresh Token'] as $k => $label): $has = !empty($pt[$k]); ?>
            <label class="field"><span class="lbl"><?= e($label) ?></span>
              <?= key_field($label, $has, null, 'type="password" name="pt_' . $k . '" placeholder="' . e('Paste the ' . mb_strtolower($label)) . '" spellcheck="false" autocomplete="new-password"') ?></label>
          <?php endforeach ?>
          </div>
        </div>
        <div class="form-actions">
          <button class="btn btn-primary btn-sm">Save Patreon Keys</button>
        </div>
      </form>
      </div>
    </details>

    <?php endif ?>

    <?php if ($tab === 'features'): ?>
    <?php $bl = m3d_blog_settings(); $blRaw = m3d_settings()['blog'] ?? []; ?>
    <section class="sec" id="blog">
      <h2 class="small-h">Blog <span class="badge tone-<?= $bl['on'] ? 'green' : 'grey' ?>"><?= $bl['on'] ? 'On' : 'Off' ?></span></h2>
      <form method="post" class="set-list">
        <?= form_keys('blog') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="blog_on" value="1" <?= $bl['on'] ? 'checked' : '' ?> /><span>Enable Blog<?= tip('Adds “Blog” to the menu and the footer once there is a post. Off: /blog/ returns “not found” (admins still see it).') ?></span></label>
        <label class="field"><span class="lbl">Page Title</span>
          <input class="input" name="blog_title" maxlength="80" value="<?= e((string) ($blRaw['title'] ?? '')) ?>" placeholder="Blog" /></label>
        <label class="field"><span class="lbl">Line Under the Title</span>
          <input class="input" name="blog_intro" maxlength="300" value="<?= e($bl['intro']) ?>" placeholder="Release notes, works in progress and how the models are made." /></label>
        <div class="form-actions">
          <a class="btn btn-ghost btn-sm" href="/admin/blog.php">Write Posts</a>
          <button class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
    </section>

    <?php $bs = m3d_bug_settings(); ?>
    <section style="margin-top:20px" class="sec" id="bugs">
      <h2 class="small-h">Bug Reports <span class="badge tone-<?= $bs['on'] ? 'green' : 'grey' ?>"><?= $bs['on'] ? 'On' : 'Off' ?></span></h2>
      <form method="post" autocomplete="off" class="set-list">
        <?= form_keys('bugs') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="bugs_on" value="1" <?= $bs['on'] ? 'checked' : '' ?> /><span>Enable Bug Reports<?= tip('Adds “Bug Reports” to the footer and a “Found a bug?” box on model and accessory pages. Off: /bugs/ returns “not found” (admins still see it).') ?></span></label>
        <div class="set-grps">
          <div class="set-grp"><h3>Posting</h3>
            <div class="field"><span class="lbl">Who Can Post</span>
              <div class="vis-seg bugs-who">
                <label class="vis-opt"><input type="radio" name="bugs_who" value="any" <?= $bs['who'] === 'any' ? 'checked' : '' ?> />Any Account</label>
                <label class="vis-opt"><input type="radio" name="bugs_who" value="paying" <?= $bs['who'] === 'paying' ? 'checked' : '' ?> />Paying Patrons</label>
              </div></div>
            <label class="field bugs-num"><span class="lbl">Reports per Day</span>
              <input class="input" type="number" name="bugs_per_day" min="1" max="50" value="<?= (int) $bs['per_day'] ?>" /></label>
            <label class="toggle"><input type="checkbox" class="switch" name="bugs_moderate" value="1" <?= $bs['moderate'] ? 'checked' : '' ?> /><span>Review Before Publishing<?= tip('New reports stay hidden (visible only to their author) until you press Publish on the report’s page.') ?></span></label>
          </div>
          <div class="set-grp"><h3>Screenshots</h3>
            <label class="toggle"><input type="checkbox" class="switch" name="bugs_uploads" value="1" <?= $bs['uploads'] ? 'checked' : '' ?> /><span>Allow Image Uploads<?= tip('Up to ' . BUG_PICS . ' pictures per report, max ' . (BUG_PIC_BYTES >> 20) . ' MB each, stored on your host in media/bugs (resized, WebP). Off: links only.') ?></span></label>
            <label class="field"><span class="lbl">Screenshot Host<?= tip('Suggested on the report form for screenshot links. Pictures stay on that site; reports show them only as links. Empty = no suggestion.') ?></span>
              <input class="input" type="url" name="bugs_host" maxlength="300" value="<?= e($bs['host']) ?>" placeholder="https://catbox.moe" spellcheck="false" /></label>
          </div>
          <?= notify_group('bugs', 'bugc') ?>
        </div>
        <div class="form-actions"><a class="btn btn-ghost btn-sm" href="/bugs/">Open Bug Reports</a><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
      <?php if ($n = count($bs['blocked'])): ?><p class="muted small set-banned"><?= e(m3d_plural($n, 'person', 'people')) ?> can’t post bug reports. <a href="/admin/users.php?tab=exceptions&amp;f=banned">Manage bans in Access →</a></p><?php endif ?>
    </section>

    <?php $cs = m3d_comment_settings(); $cmCount = m3d_comments_count(); $cmWait = m3d_comments_pending(); ?>
    <section style="margin-top:20px" class="sec" id="comments">
      <h2 class="small-h">Comments <span class="badge tone-<?= $cs['on'] ? 'green' : 'grey' ?>"><?= $cs['on'] ? 'On' : 'Off' ?></span><?= tip('Each page can turn them off with “Allow Comments” in its editor.') ?></h2>
      <form method="post" autocomplete="off" class="set-list">
        <?= form_keys('comments') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="cm_on" value="1" <?= $cs['on'] ? 'checked' : '' ?> /><span>Enable Comments<?= tip('Off: no comments are shown anywhere and nobody can write one. They are kept, and come back when you turn this on again.') ?></span></label>
        <div class="set-grps">
          <div class="set-grp"><h3>Where</h3>
            <?php foreach (COMMENT_KINDS as $k => $label): ?>
              <label class="toggle"><input type="checkbox" class="switch" name="cm_<?= $k ?>" value="1" <?= $cs[$k] ? 'checked' : '' ?> /><span><?= e($label) ?></span></label>
            <?php endforeach ?>
          </div>
          <div class="set-grp"><h3>Posting</h3>
            <div class="field"><span class="lbl">Who Can Comment</span>
              <div class="vis-seg bugs-who">
                <label class="vis-opt"><input type="radio" name="cm_who" value="any" <?= $cs['who'] === 'any' ? 'checked' : '' ?> />Any Account</label>
                <label class="vis-opt"><input type="radio" name="cm_who" value="paying" <?= $cs['who'] === 'paying' ? 'checked' : '' ?> />Paying Patrons</label>
              </div></div>
            <label class="field bugs-num"><span class="lbl">Comments per Day</span>
              <input class="input" type="number" name="cm_per_day" min="1" max="100" value="<?= (int) $cs['per_day'] ?>" /></label>
            <label class="toggle"><input type="checkbox" class="switch" name="cm_moderate" value="1" <?= $cs['moderate'] ? 'checked' : '' ?> /><span>Check Before Showing<?= tip('New comments are visible only to their author (and you) until you publish them, here or with ⋯ → Publish on the page.') ?></span></label>
            <label class="toggle"><input type="checkbox" class="switch" name="cm_paid_skip" value="1" <?= $cs['paid_skip'] ? 'checked' : '' ?> /><span>Skip for Paying Patrons<?= tip('With Check Before Showing on: comments from patrons on a paid tier show at once, comments from other accounts (free members) still wait for you. Banned people still can’t comment.') ?></span></label>
          </div>
          <?= notify_group('comments') ?>
        </div>
        <div class="form-actions">
          <button class="btn btn-ghost btn-sm act-left btn-danger" form="comments-clear"<?= $cmCount ? '' : ' disabled' ?>>Delete All Comments<?= $cmCount ? ' (' . $cmCount . ')' : '' ?></button>
          <button class="btn btn-primary btn-sm">Save</button>
        </div>
      </form>
      <form method="post" id="comments-clear" hidden onsubmit="return confirm(<?= e(json_encode('Delete all ' . m3d_plural($cmCount, 'comment') . ' on every model, accessory and blog post? This can’t be undone (only a backup brings them back).', JSON_UNESCAPED_UNICODE)) ?>)">
        <?= form_keys('comments-clear') ?>
      </form>
      <?php if ($cmWait): ?>
        <div class="field bugs-blocked cm-waiting"><span class="lbl">Waiting for Approval <span class="badge tone-sky"><?= count($cmWait) ?></span></span>
          <?php foreach (array_slice($cmWait, 0, 20) as [$k, $key, $c]): [$title, $url] = m3d_comment_page($k, $key); ?>
            <form method="post" class="inline-row"><?= form_keys() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>" />
              <span class="cm-w-txt"><b><?= e(($c['name'] ?? '') ?: 'Someone') ?></b> <span class="muted small">on <?= $url !== '' ? '<a href="' . e($url . '#cm-' . (int) $c['id']) . '">' . e($title) . '</a>' : e($title) ?> · <?= e(bug_ago((int) ($c['at'] ?? 0))) ?></span>
                <span class="cm-w-body"><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', (string) ($c['text'] ?? '')) ?? '', 0, 160, '…')) ?></span></span>
              <span class="row-btns"><button class="btn btn-ghost btn-sm" name="do" value="comments-delete" onclick="return confirm('Delete this comment?')">Delete</button><button class="btn btn-primary btn-sm" name="do" value="comments-approve">Publish</button></span></form>
          <?php endforeach ?>
        </div>
      <?php endif ?>
      <?php if ($n = count($cs['blocked'])): ?><p class="muted small set-banned"><?= e(m3d_plural($n, 'person', 'people')) ?> can’t comment. <a href="/admin/users.php?tab=exceptions&amp;f=banned">Manage bans in Access →</a></p><?php endif ?>
    </section>

    <?php $sc = m3d_showcase_settings(); ?>
    <section style="margin-top:20px" class="sec" id="showcase">
      <h2 class="small-h">Patron Renders <span class="badge tone-<?= $sc['on'] ? 'green' : 'grey' ?>"><?= $sc['on'] ? 'On' : 'Off' ?></span><?= tip('Paying patrons share renders they made with a model or accessory from its page (the “Made a Render?” box). You approve each one in Media → From Patrons; approved ones show in the page’s gallery, after your pictures.') ?></h2>
      <form method="post" autocomplete="off" class="set-list">
        <?= form_keys('showcase') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="sc_on" value="1" <?= $sc['on'] ? 'checked' : '' ?> /><span>Enable Patron Renders<?= tip('Off: nobody can share new renders and none show on the pages. They are kept, and come back when you turn this on again.') ?></span></label>
        <label class="field bugs-num"><span class="lbl">Renders per Day<?= tip('How many renders one patron can send in 24 hours, on all pages together. 0 = no limit. A patron never has more than ' . SHOWCASE_WAITING . ' waiting for your approval at once.') ?></span>
          <input class="input" type="number" name="sc_per_day" min="0" max="<?= SHOWCASE_PER_DAY_MAX ?>" value="<?= (int) $sc['per_day'] ?>" /></label>
        <div class="form-actions"><?php if ($sc['on'] || m3d_showcase_stored()): ?><a class="btn btn-ghost btn-sm" href="<?= e(m3d_showcase_admin_url()) ?>">Open From Patrons</a><?php endif ?><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
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
            <label class="field"><span class="lbl">Webhook URL<?= tip('Channel settings (gear icon) → Integrations → Webhooks → New Webhook → “Copy Webhook URL”. Anyone with this URL can post in the channel: keep it private (never shown again once saved).') ?></span>
              <?php /* never printed back (private/webhooks.php): an empty field keeps the saved one */ ?>
              <?= key_field('Webhook URL', $dc['webhook'] !== '', null, 'class="dc-hook" type="url" name="discord_webhook" placeholder="https://discord.com/api/webhooks/…" spellcheck="false" autocomplete="off" data-dc-hook') ?>
              <span class="dc-hook-state small" data-dc-hook-state></span></label>
            <label class="field"><span class="lbl">Intro Message<?= tip('Optional line above each post, e.g. “New update!”. Use @everyone, @here or a role’s code to ping people: a role written as plain text (@Notifications) pings no one. To get the code, send \\@RoleName in any Discord channel and copy what Discord shows (<@&…>).') ?></span>
              <input class="input" name="discord_message" value="<?= e($dc['message']) ?>" maxlength="300" placeholder="New update!" data-dc-message />
              <span class="dc-hook-state small res-err" data-dc-ment-warn><?= e(discord_plain_mention_warning($dc['message'])) ?></span></label>
          </div>
          <div class="dc-side">
            <?php $dcs = m3d_discord_sample(); ?>
            <span class="lbl">Preview<?php if ($dcs): ?> <span class="dc-which">· <?= e($dcs[1]['name'] . ' ' . m3d_version_label(m3d_current_version($dcs[1]))) ?></span><?php endif ?></span>
            <?= discord_preview_html($dc['message']) ?>
          </div>
        </div>
        <div class="form-actions">
          <?php if ($l = $dc['last']): $at = m3d_site_time((string) ($l['at'] ?? ''), 'j M Y, H:i T'); ?>
            <p class="act-left dc-last small muted">Last post, <?= e($at) ?>: <?= !empty($l['ok']) ? '<b class="res-ok">✓</b> ' . e((string) ($l['what'] ?? '')) : '<b class="res-err">failed</b> (' . e((string) ($l['what'] ?? '')) . '): ' . e((string) ($l['error'] ?? '')) . (empty($l['stills']) ? ' It will be tried again the next time you save it.' : ' Pictures are tried again ' . intdiv(DISCORD_STILLS_DELAY, 60) . ' minutes later, ' . DISCORD_STILLS_TRIES . ' times at most.') ?></p>
          <?php endif ?>
          <?php if ($dc['webhook'] !== ''): ?><button class="btn btn-ghost btn-sm" name="do" value="discord-forget" onclick="return confirm('Remove the saved webhook? Discord announcements turn off until you paste one again.')">Remove Webhook</button><?php endif ?>
          <button class="btn btn-ghost btn-sm" name="do" value="discord-test">Save and Send a Test Message</button><button class="btn btn-primary btn-sm" name="do" value="discord">Save</button>
        </div>
      </form>
    </section>

    <?php $wl = m3d_welcome(); ?>
    <section style="margin-top:20px" class="sec" id="welcome">
      <h2 class="small-h">Welcome Page <span class="badge tone-<?= $wl['on'] ? 'green' : 'grey' ?>"><?= $wl['on'] ? 'On' : 'Off' ?></span></h2>
      <form method="post" class="set-list">
        <?= form_keys('welcome') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="welcome_on" value="1" <?= $wl['on'] ? 'checked' : '' ?> /><span>Show After the First Login<?= tip('Paying patrons and people with a Bypass Tier exception. Admins never get it. Patrons can open it again from My Account.') ?></span></label>
        <label class="toggle"><input type="checkbox" class="switch" name="welcome_skip_old" value="1" <?= $wl['skip_old'] ? 'checked' : '' ?> /><span>Skip Patrons Who Downloaded Before</span></label>
        <label class="field"><span class="lbl">Intro Text<?= tip('Under the title. Empty = “Thanks for supporting ' . site_name() . ' on Patreon. Here’s a quick tour, so you know where everything is.”') ?></span>
          <textarea class="input" name="welcome_text" rows="3" data-img-drop="misc" maxlength="<?= WELCOME_TEXT_MAX ?>" placeholder="<?= e(m3d_welcome_text()) ?>"><?= e($wl['text']) ?></textarea></label>
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

    <?php $s4 = m3d_s4(); $s4On = m3d_s4_on(); $s4Keys = m3d_s4_keys(); ?>
    <section style="margin-top:20px" class="sec" id="s4">
      <h2 class="small-h">MEGA S4 Downloads <span class="badge tone-<?= $s4On ? 'green' : 'grey' ?>"><?= $s4On ? 'On' : 'Off' ?></span><?= tip('Download links to files in a MEGA S4 bucket (https://s3.….megas4.com/bucket/file) send each download to a temporary address, so an address copied from the browser or shared stops working after the time below. Needs a MEGA plan with S4 (object storage) and an S4 access key. Other links don’t change.') ?></h2>
      <form method="post" autocomplete="off">
        <?= form_keys() ?>
        <button class="dc-enter" name="do" value="s4" tabindex="-1" aria-hidden="true"></button><?php /* Enter in a field = Save */ ?>
        <label class="toggle s4-switch"><input type="checkbox" class="switch" name="s4_on" value="1" <?= $s4['on'] ? 'checked' : '' ?> /><span>Use S4 Downloads<?= tip('Off: S4 is gone from the site (no S4… button in the editors, no S4 checks in Link Check) and “s4:” links don’t download. The keys and bucket stay saved here for later.') ?></span></label>
        <div class="id-grid">
          <label class="field"><span class="lbl">Access Key ID<?= tip('In MEGA, open Object Storage (S4) → Access Keys and create a key. Paste its access key ID here.') ?></span>
            <?= key_field('Access Key ID', $s4['key'] !== '', $s4['key'], 'name="s4_key" maxlength="128" spellcheck="false" autocomplete="off"') ?></label>
          <label class="field"><span class="lbl">Secret Key</span>
            <?= key_field('Secret Key', $s4['secret'] !== '', null, 'type="password" name="s4_secret" maxlength="200" placeholder="Paste the secret key" spellcheck="false" autocomplete="new-password"') ?></label>
          <?php $inHours = $s4['minutes'] % 60 === 0; ?>
          <div class="field"><label class="lbl" for="s4-life">Link Lifetime<?= tip('How long a download address works after the patron starts the download. A download already running finishes; resuming it later needs a new click. From ' . S4_MINUTES_MIN . ' minutes to 7 days.') ?></label>
            <div class="s4-life"><input class="input" id="s4-life" type="number" name="s4_life" min="1" max="<?= S4_MINUTES_MAX ?>" step="1" value="<?= $inHours ? intdiv($s4['minutes'], 60) : $s4['minutes'] ?>" />
              <select class="input" name="s4_unit" aria-label="Unit"><option value="minutes"<?= $inHours ? '' : ' selected' ?>>Minutes</option><option value="hours"<?= $inHours ? ' selected' : '' ?>>Hours</option></select></div></div>
          <label class="field"><span class="lbl">Bucket Address<?= tip('Your bucket, e.g. https://s3.g.megas4.com/your-bucket/ (MEGA’s Object URL of any file in it works too). Then a download link only needs “s4:” and the file name: s4:model.zip, or s4:folder/file.zip.') ?></span>
            <?= key_field('Bucket Address', $s4['bucket'] !== '', $s4['bucket'], 'type="url" name="s4_bucket" maxlength="1000" placeholder="https://s3.g.megas4.com/your-bucket/" spellcheck="false"') ?></label>
          <label class="field"><span class="lbl">Download Domain<?= tip('Optional. Your own address for downloads, e.g. files.example.com: a proxy on it (e.g. a CDN worker) passes each temporary address on to the bucket’s host unchanged. Downloads of files in the bucket above then show your address instead of MEGA’s. Empty: downloads go straight to MEGA.') ?></span>
            <?= key_field('Download Domain', $s4['domain'] !== '', $s4['domain'], 'name="s4_domain" maxlength="253" placeholder="files.example.com" spellcheck="false" autocomplete="off"') ?></label>
        </div>
        <div class="form-actions">
          <?php if ($s4Test !== null): ?>
            <p class="act-left dc-last small"><?php if ($s4Test['ok']): $tf = (string) ($s4Test['file'] ?? ''); $tp = $tf !== '' ? m3d_s4_parse($tf) : null; $try = $tp ? m3d_s4_presign($tf) : null; $try = $try !== null ? m3d_s4_domain_url($try) : null; ?>
              <b class="res-ok">✓ Works:</b> bucket <?= e((string) (m3d_s4_parse($s4['bucket'] . 'x')[1] ?? '')) ?>, <?= e((string) $s4Test['list']) ?><?= $tp ? ' · ' . e((string) $tp[2]) : '' ?><?= ($s4Test['size'] ?? 0) > 0 ? ' · ' . e(m3d_size((int) $s4Test['size'])) : '' ?>.
              <?php if (isset($s4Test['via'])): $via = $s4Test['via']; ?>
                <?php if ($via['ok']): ?><b class="res-ok">✓ Through <?= e($s4['domain']) ?> too.</b>
                <?php else: ?><b class="res-err">✗ Not through <?= e($s4['domain']) ?>:</b> <?= e($via['code'] === 0 ? 'no answer (' . $via['err'] . ')' : (!str_starts_with($via['err'], 'HTTP ') ? m3d_s4_error_text($via['err']) : ($via['code'] === 404 ? 'it answered “Not found” (check the bucket name in the Worker’s code)' : 'it answered ' . $via['err']))) ?>. Downloads use this address anyway: fix the Worker, or empty the Download Domain.<?php endif ?>
              <?php endif ?>
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
      <?php if ($maintOn && ($since = m3d_site_time((string) ($maint['since'] ?? ''), 'j F Y, H:i T')) !== ''): ?><p class="muted small sec-hint">On since <?= e($since) ?>.</p><?php endif ?>
      <form method="post" class="maint-form"<?= $maintOn ? '' : ' onsubmit="return confirm(' . e(json_encode('Turn on maintenance mode? Visitors will see the “Back Soon” page until you turn it off.')) . ')"' ?>>
        <?= form_keys('maintenance') ?>
        <label class="field"><span class="lbl">Visitor Message</span>
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
      $probTime = fn(int $t) => m3d_site_time($t, 'D j M, H:i T'); ?>
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
          <div><b>Unused Media</b><p class="muted small"><span data-stat="unused">Counting…</span> <a href="/admin/images.php?unused=1">Review them in Media</a>.</p></div>
          <?= $action('unused', 'Delete Unused Media', 'Delete all images, 3D files and videos that are not used anywhere? This can\'t be undone.', 'unused') ?>
        </div>
        <div class="tool">
          <div><b>Temporary Files</b><p class="muted small"><span data-stat="temp">Counting…</span></p></div>
          <?= $action('temp', 'Delete Temporary Files', '', 'temp') ?>
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

    <?php if ($tab === 'privacy'): ?>
    <?php $lg = m3d_legal(); ?>
    <section class="sec" id="legal">
      <h2 class="small-h">Legal &amp; Contact<?= tip('Site operator shown on the privacy page (required in the EU), and the support email shown on the Contacts page. Empty fields use config.php.') ?></h2>
      <form method="post">
        <?= form_keys('legal') ?>
        <div class="id-grid">
          <label class="field"><span class="lbl">Full Name</span><input class="input" name="legal_name" value="<?= e($lg['name']) ?>" maxlength="120" /></label>
          <label class="field"><span class="lbl">Privacy Email</span><input class="input" type="email" name="legal_email" value="<?= e($lg['email']) ?>" maxlength="120" /></label>
          <label class="field id-wide"><span class="lbl">Postal Address</span><textarea class="input" name="legal_address" rows="3" maxlength="300"><?= e($lg['address']) ?></textarea></label>
          <label class="field id-wide"><span class="lbl">Support Email</span><input class="input" type="email" name="support_email" value="<?= e(m3d_support_email()) ?>" maxlength="120" /></label>
        </div>
        <div class="form-actions"><a class="btn btn-ghost btn-sm" href="/privacy/" target="_blank">View the Privacy Page ↗</a><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
    </section>

    <?php $pdQ = m3d_get_text('who'); $pdHits = m3d_find_patrons($pdQ);
    $pdOne = count($pdHits) === 1 ? pd_collect($pdHits[0][0]) : null; ?>
    <section style="margin-top:20px" class="sec" id="personal-data">
      <h2 class="small-h">Personal Data<?= tip('Everything the site stores about one Patreon user (downloads, likes, bug reports, special access…), as a file to send them when they ask for their data (GDPR). Names are found among people who downloaded or wrote a bug report; anyone else by their Patreon user ID, shown on their My account page.') ?></h2>
      <form method="get" action="/admin/settings.php#personal-data" class="pd-find">
        <input type="hidden" name="tab" value="privacy" />
        <label class="field"><span class="lbl">Patreon User ID or Name</span><input class="input" name="who" value="<?= e($pdQ) ?>" maxlength="80" required list="pd-known" autocomplete="off" placeholder="e.g. 12345678 or Anna" /></label>
        <button class="btn btn-primary btn-sm">Find</button>
        <datalist id="pd-known"><?= m3d_patron_options() ?></datalist>
      </form>
      <?php if ($pdQ !== '' && !$pdHits): ?>
        <p class="muted pd-none">Nobody found with that name. Try their Patreon user ID (on their My account page).</p>
      <?php elseif (count($pdHits) > 1): ?>
        <p class="muted small pd-pick-h"><?= count($pdHits) ?> people match:</p>
        <ul class="pd-pick"><?php foreach ($pdHits as [$hid, $hname]): ?>
          <li><a href="/admin/settings.php?<?= e(http_build_query(['tab' => 'privacy', 'who' => (string) $hid])) ?>#personal-data"><?= e($hname !== '' ? $hname : (string) $hid) ?></a> <span class="muted small">Patreon ID <?= e((string) $hid) ?></span></li>
        <?php endforeach ?></ul>
      <?php elseif ($pdOne): $pdAny = pd_found($pdOne); ?>
        <div class="pd-card">
          <p class="pd-who"><b><?= e($pdOne['name'] !== '' ? $pdOne['name'] : 'Patreon User ' . $pdOne['id']) ?></b>
            <span class="muted small">Patreon ID <?= e($pdOne['id']) ?><?= count($pdOne['names']) > 1 ? ' · also seen as ' . e(implode(', ', array_diff($pdOne['names'], [$pdOne['name']]))) : '' ?></span></p>
          <?php if (!$pdAny): ?>
            <p class="muted">The site stores nothing about this Patreon ID.</p>
          <?php else: ?>
          <table class="pd-table">
            <thead><tr><th>What</th><th>Found</th></tr></thead>
            <tbody><?php foreach (pd_summary($pdOne) as $what => $found): ?>
              <tr><td><?= e($what) ?></td><td<?= $found === '' ? ' class="muted"' : '' ?>><?= e($found !== '' ? $found : (str_starts_with($what, 'Banned') ? 'No' : 'None')) ?></td></tr>
            <?php endforeach ?></tbody>
          </table>
          <?php endif ?>
          <p class="muted small pd-out">Not in the file: the login session (deleted at log out), the server logs of the web host and the delivery service, and the copies inside backups. The file says so too.</p>
          <div class="form-actions">
            <?php if (pd_found($pdOne, false)): ?><details class="pd-del act-left">
              <summary class="btn btn-danger btn-sm">Delete All Their Data…</summary>
              <form method="post" class="pd-del-box" onsubmit="return confirm(<?= e(json_encode('Delete everything about ' . ($pdOne['name'] !== '' ? $pdOne['name'] . ' (Patreon ID ' . $pdOne['id'] . ')' : 'Patreon ID ' . $pdOne['id']) . '? This cannot be undone.')) ?>)">
                <?= form_keys('pd-delete') ?><input type="hidden" name="id" value="<?= e($pdOne['id']) ?>" />
                <p><b>This cannot be undone.</b> Everything in the list above is deleted for good:</p>
                <ul>
                  <li>Their downloads leave the log, so their unlocks start from zero: they can unlock again as if new, and you lose the record of what they downloaded.</li>
                  <li>Their bug reports go with every comment on them, other people’s too; their comments elsewhere, Me Too and follows go as well.</li>
                  <li>Their comments under models, accessories and blog posts go.</li>
                  <li>Their likes, ignored updates, welcome page mark and access exception go. The like counts drop.</li>
                </ul>
                <p class="muted small">What stays: the all-time download totals (numbers only), their bans (their ID, what is banned and the reason, so the ban keeps working), and the copies inside backups until those backups are deleted. If they log in again, new data starts from zero. Download their data first if you need to send it.</p>
                <label class="field"><span class="lbl">Type <code><?= e($pdOne['id']) ?></code> to Confirm</span><input class="input" name="typed" required autocomplete="off"<?= ctype_digit($pdOne['id']) ? ' pattern="' . e($pdOne['id']) . '"' : '' ?> /></label>
                <div class="form-actions"><button class="btn btn-danger btn-sm">Delete All Their Data</button></div>
              </form>
            </details><?php endif ?>
            <?php $pdUrl = fn(string $as) => '/admin/settings.php?' . http_build_query(['tab' => 'privacy', 'export' => $pdOne['id']] + ($as !== '' ? ['as' => $as] : [])); ?>
            <a class="btn btn-ghost btn-sm" href="<?= e($pdUrl('json')) ?>" title="The same data for another program (data portability)">Download as JSON</a>
            <a class="btn btn-primary btn-sm" href="<?= e($pdUrl('')) ?>" title="One page that opens in any browser and prints">Download Readable File</a>
          </div>
        </div>
      <?php endif ?>
    </section>
    <?php endif ?>

<?php admin_end();
