<?php
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

// Discord announcements (Admin → Settings → Discord announcements; settings.json → discord: {on, message, sent, last}; the webhook in
// private/webhooks.php, m3d_webhook('discord')).
// When a save makes a new version visible, the site posts it to a Discord channel through a webhook: no bot, no polling.
// m3d_save_items() calls m3d_discord_after_save() with the items before and after the save. A version is posted once:
// its code "kind:slug@label" goes into `sent`. Posted: the top changelog version of a public or blurred item (never draft or
// password ones) whose code isn't in `sent`, when the save added a version or made the item visible (published, new item),
// or when its post failed before (`retry`);
// other saves (a renamed version, a typo fixed, pictures replaced) only mark it as sent. Turning the feature on marks every
// current version as sent, so nothing old is posted. Pictures only for fully public items (like link previews). The post
// carries only the item's own texts: no visitor data.
// New Gallery pictures (switch `stills`): a picture that becomes visible to everyone in Admin → Gallery waits in `stills_queue`;
// DISCORD_STILLS_DELAY after the last gallery change (time to add captions and characters) the next page view posts the waiting
// ones together ("New renders"), the picture itself only for public ones. Only gallery changes queue pictures: publishing a model
// doesn't post its older pictures, and turning the switch on posts nothing already there.

const DISCORD_WEBHOOK_RE = '#^https://(?:(?:ptb|canary)\.)?discord(?:app)?\.com/api/webhooks/\d{5,30}/[A-Za-z0-9_-]{20,200}$#D';
const DISCORD_SENT_MAX = 1000;
const DISCORD_EMBEDS = 10;   // per message (Discord's limit)
const DISCORD_MESSAGES = 3;  // per save at most: a bigger batch (publishing many items at once) posts the 30 newest
const DISCORD_STILLS_DELAY = 600;   // seconds after the last change in Admin → Gallery before new pictures are posted
const DISCORD_STILLS_TRIES = 3;     // failed posts of the waiting pictures before they are given up (tried every DISCORD_STILLS_DELAY)

function m3d_discord(): array
{
    $d = m3d_settings()['discord'] ?? [];
    return [
        'on'      => !empty($d['on']),
        'webhook' => m3d_webhook('discord'),   // private/webhooks.php (m3d_webhooks())
        'message' => (string) ($d['message'] ?? ''),
        'sent'    => array_values(array_filter((array) ($d['sent'] ?? []), 'is_string')),
        'retry'   => array_values(array_filter((array) ($d['retry'] ?? []), 'is_string')),   // codes whose post failed: posted on the next save
        'last'    => is_array($d['last'] ?? null) ? $d['last'] : null,
        // new Gallery pictures ("New renders"): on/off, ids already posted, ids waiting and when they go out
        'stills'       => !empty($d['stills']),
        'stills_sent'  => array_values(array_filter((array) ($d['stills_sent'] ?? []), 'is_string')),
        'stills_queue' => array_values(array_filter((array) ($d['stills_queue'] ?? []), 'is_string')),
        'stills_due'   => is_int($d['stills_due'] ?? null) ? $d['stills_due'] : null,
        'stills_tries' => (int) ($d['stills_tries'] ?? 0),
    ];
}

function m3d_discord_on(): bool
{
    $d = m3d_discord();
    return $d['on'] && preg_match(DISCORD_WEBHOOK_RE, $d['webhook']) === 1;
}

/** "models:tifa@V4" for the newest version out of an item (one dated in the future posts on its day), '' when it has none.
 *  An In Development model's ends in "~dev" (posted with the note that it may not download yet), so leaving development posts the version again. */
function m3d_discord_code(string $kind, array $item): string
{
    $label = m3d_version_label(m3d_current_version($item));
    return $label === '' ? '' : $kind . ':' . ($item['slug'] ?? '') . '@' . $label . (m3d_discord_dev($kind, $item) ? '~dev' : '');
}

/** An In Development model: its posts carry the note that it might not be downloadable yet. */
function m3d_discord_dev(string $kind, array $item): bool
{
    return $kind === 'models' && m3d_status($item) === 'in-development';
}

/** Codes of every version that could be posted now: turning the feature on marks them as sent. */
function m3d_discord_current_codes(): array
{
    $out = [];
    foreach (M3D_ITEM_KINDS as $kind) foreach (m3d_items($kind) as $it) {
        if (m3d_is_open($it) && ($c = m3d_discord_code($kind, $it)) !== '') $out[] = $c;
    }
    return $out;
}

/** The newest version on the site that could be posted: [kind, item], null when there is none (test message, preview). */
function m3d_discord_sample(): ?array
{
    $best = null;
    foreach (M3D_ITEM_KINDS as $k) foreach (m3d_listed_items($k) as $it) {
        if (!m3d_is_open($it) || m3d_current_version($it)['version'] === '') continue;
        $ts = m3d_changelog_ts(m3d_current_version($it)['date']) ?? 0;
        if (!$best || $ts > $best[0]) $best = [$ts, $k, $it];
    }
    return $best ? [$best[1], $best[2]] : null;
}

/** After models/outfits were saved: post the versions that became visible. Never throws (a failed post never stops a save). */
function m3d_discord_after_save(string $kind, array $old, array $new): void
{
    try {
        if (!m3d_discord_on()) return;
        $d = m3d_discord();
        $sent = array_flip($d['sent']);
        $retry = array_flip($d['retry']);
        $before = [];
        foreach ($old as $o) if (isset($o['slug'])) $before[$o['slug']] = $o;
        $post = $mark = [];
        foreach ($new as $it) {
            if (!m3d_is_open($it) || ($code = m3d_discord_code($kind, $it)) === '' || isset($sent[$code])) continue;
            $o = $before[$it['slug'] ?? ''] ?? null;
            // already visible: a release when the version that was current is now below the new one (also when the same save
            // removed an older entry); otherwise an edit (renamed version…), only marked as sent
            $was = $o ? m3d_version_label(m3d_current_version($o)) : '';
            $release = $was === '' || in_array($was, array_slice(array_map('m3d_version_label', m3d_released_versions($it)), 1), true)
                || ($kind === 'models' && $o && m3d_status($o) === 'in-development');   // out of development: its version is news now
            if ($o && m3d_is_open($o) && !$release && !isset($retry[$code])) $mark[] = $code;
            else $post[] = [$code, $it];
        }
        if (!$post && !$mark) return;
        $last = $d['last'];
        $posted = $failed = [];
        if ($post) {
            // newest versions first; past DISCORD_MESSAGES messages the older ones are only marked as sent (no flood)
            usort($post, fn($a, $b) => (m3d_changelog_ts(m3d_current_version($b[1])['date']) ?? 0) <=> (m3d_changelog_ts(m3d_current_version($a[1])['date']) ?? 0));
            $mark = array_merge($mark, array_column(array_slice($post, DISCORD_EMBEDS * DISCORD_MESSAGES), 0));
            $post = array_slice($post, 0, DISCORD_EMBEDS * DISCORD_MESSAGES);
            $res = ['ok' => true, 'error' => ''];   // the first failure, for the result line
            $stop = false;
            foreach (m3d_discord_chunks(array_map(fn($p) => [$p[0], m3d_discord_embed($kind, $p[1])], $post)) as $i => $chunk) {   // each message on its own: a failed one doesn't post the earlier ones again
                if ($i >= DISCORD_MESSAGES) { $mark = array_merge($mark, array_column($chunk, 0)); continue; }   // long texts made more messages: no flood
                if ($stop) { $failed = array_merge($failed, array_column($chunk, 0)); continue; }   // not tried after a failure: tried again on the next save
                $r = m3d_discord_post($d['webhook'], $d['message'], array_column($chunk, 1));   // each message decided by its own answer
                if ($r['ok']) { $posted = array_merge($posted, array_column($chunk, 0)); continue; }
                if ($res['ok']) $res = $r;
                if (($r['status'] ?? 0) === 400) $mark = array_merge($mark, array_column($chunk, 0));   // refused as it is: trying again would fail again and hold up later posts
                else { $failed = array_merge($failed, array_column($chunk, 0)); $stop = true; }   // not posted (Discord down, slow): tried again on the next save of the item
            }
            $last = ['at' => gmdate('c'), 'ok' => $res['ok'], 'error' => $res['error'], 'what' => implode(', ', array_map(fn($p) => $p[1]['name'] . ' ' . bug_version_words(m3d_version_label(m3d_current_version($p[1]))), $post))];
            if (!$res['ok']) m3d_problem('Discord', 'Post about ' . $last['what'] . ' failed: ' . $res['error']);
        }
        $done = array_flip(array_merge($mark, $posted));
        m3d_save_setting_keys('discord', [
            'sent'  => array_slice(array_merge($d['sent'], $mark, $posted), -DISCORD_SENT_MAX),
            'retry' => array_values(array_unique(array_merge(array_filter($d['retry'], fn($c) => !isset($done[$c])), $failed))),
            'last'  => $last,
        ]);
    } catch (Throwable $ex) {
        m3d_problem('Discord', $ex->getMessage());
    }
}

/** Its public date has come (release dates): "Name is now free" for everyone. Never throws. */
function m3d_discord_free(string $kind, array $item): void
{
    try {
        $d = m3d_discord();
        $r = m3d_release($item);
        $e = m3d_discord_embed($kind, $item);
        $e['title'] = mb_substr($item['name'] . ' is now free', 0, 250);
        $e['description'] = ($r['patrons'] !== null ? 'Patrons have had it since ' . m3d_release_when($r['patrons'], false) . '. ' : '')
            . 'From today anyone can download it, no login needed.';
        unset($e['timestamp'], $e['head']);
        $e = m3d_discord_item_card($e, $kind, $item, null);
        $e['buttons'] = array_slice($e['buttons'], 0, 1);   // View: no changelog news in this one
        $res = m3d_discord_post($d['webhook'], $d['message'], [$e]);
        if (!$res['ok']) m3d_problem('Discord', 'Post “' . $item['name'] . ' is now free” failed: ' . $res['error']);
        m3d_save_setting_keys('discord', ['last' => ['at' => gmdate('c'), 'ok' => $res['ok'], 'error' => $res['error'], 'what' => $item['name'] . ' (now free)']]);
    } catch (Throwable $ex) {
        m3d_problem('Discord', $ex->getMessage());
    }
}

/** One version as a Discord embed: name + version, highlight and changes, the picture of public items, the type in the footer. */
function m3d_discord_embed(string $kind, array $item): array
{
    $en = m3d_released_versions($item)[0] ?? [];
    $abs = fn(string $t) => preg_replace_callback('#\]\((/[^)\s]*)\)#', fn($m) => '](' . abs_url($m[1]) . ')', $t);   // relative links in the texts
    $lines = [];
    if (($h = trim((string) ($en['highlight'] ?? ''))) !== '') $lines[] = '**' . $h . '**';
    foreach ((array) ($en['changes'] ?? []) as $c) if (($c = trim((string) $c)) !== '') $lines[] = '• ' . $c;
    $text = m3d_bug_refs_discord((string) $abs(implode("\n", $lines)));   // "#6": a link to the report
    if (mb_strlen($text) > 1800) $text = mb_substr($text, 0, 1800) . '…';
    $color = ltrim((string) (m3d_identity()['color'] ?? ''), '#');
    $what = m3d_item_type($kind, $item) . (($item['franchise'] ?? '') !== '' ? ' · ' . $item['franchise'] : '');
    $e = [
        'title'  => mb_substr(($kind === 'models' ? m3d_model_name($item) : $item['name']) . ' · ' . m3d_version_label(m3d_current_version($item)), 0, 250),
        'url'    => abs_url(m3d_version_url($kind, $item, m3d_version_label($en))),   // straight to that version in the changelog
        'footer' => ['text' => mb_substr($what . ' · ' . site_name(), 0, 200)],
    ];
    if ($text !== '') $e['description'] = $text;
    if (preg_match('/^[0-9a-f]{6}$/i', $color)) $e['color'] = hexdec($color);
    $ts = m3d_changelog_ts((string) ($en['date'] ?? ''));
    if ($ts !== null) $e['timestamp'] = gmdate('c', $ts + 12 * 3600);   // noon of that day in site time: the same date for readers in the Americas
    if (m3d_visibility($item) === 'public') {
        $pic = trim((string) ($en['image'] ?? '')) ?: item_pic($kind, $item);
        if ($pic !== '') $e['image'] = ['url' => abs_url(img_small($pic))];
    }
    if (m3d_discord_dev($kind, $item)) $e['note'] = "🛠️ **In development:** may not be downloadable yet.";   // short: one line in the card
    return m3d_discord_item_card($e, $kind, $item, $ts !== null ? $ts + 12 * 3600 : null) + ['head' => "What's New"];
}

/** The card keys of a post about an item (m3d_discord_card()): the site over the name, franchise / type (· the version's date) under it, the site's icon,
 *  the facts of its link preview but the version (preview_facts(): Generation, Blender, Status; Type, Optimized For), View + Full Changelog. */
function m3d_discord_item_card(array $e, string $kind, array $item, ?int $ts): array
{
    $page = abs_url(m3d_item_url($kind, (string) $item['slug']));
    $what = $kind === 'models' ? (string) ($item['franchise'] ?? '') : m3d_item_type($kind, $item);
    $e['sub'] = discord_md_text(site_name());
    $e['below'] = implode(' · ', array_filter([discord_md_text($what), $ts !== null ? '<t:' . $ts . ':d>' : '']));
    if (($logo = preview_logo()) !== '') $e['thumb'] = abs_url($logo);
    $e['facts'] = [];
    foreach (preview_facts($kind, $item) as [$label, $text, , $tone]) {
        if ($label !== 'Latest Version') $e['facts'][] = [$label, ($tone !== null ? (PREVIEW_DOTS[$tone] ?? '⚪') . ' ' : '') . '**' . discord_md_text($text) . '**'];
    }
    $e['buttons'] = [[$kind === 'models' ? 'View Model' : 'View Accessory', $page], ['Full Changelog', $page . '#changelog']];
    return $e;
}

/** The posts split into messages: at most DISCORD_EMBEDS embeds each, and under Discord's 6000 characters of embed text per message
 *  (a bigger message is refused whole). $list = [[key, embed], …] → [[[key, embed], …], …] */
function m3d_discord_chunks(array $list): array
{
    $size = fn(array $e) => mb_strlen((string) ($e['title'] ?? '')) + mb_strlen((string) ($e['description'] ?? '')) + mb_strlen((string) ($e['footer']['text'] ?? ''));
    $out = $cur = [];
    $n = 0;
    foreach ($list as $p) {
        $len = $size($p[1]);
        if ($cur && (count($cur) >= DISCORD_EMBEDS || $n + $len > 5800)) { $out[] = $cur; $cur = []; $n = 0; }
        $cur[] = $p;
        $n += $len;
    }
    if ($cur) $out[] = $cur;
    return $out;
}

/**
 * Posts are cards (Discord's components, "Components V2"; Pi picked layout A, 2026-10-30). Each post is still built as an embed (the
 * fallback, and what m3d_discord_chunks() counts) with a few keys of its own that m3d_discord_card() turns into one card: `sub` (the grey
 * line over the title, Discord markdown), `thumb` (a picture right of the title: the site's icon), `facts` [[label, value (markdown)]…]
 * shown as a grey label over its value, `head` (a heading over the text), `buttons` [[label, url]…]. m3d_discord_post() sends the cards
 * when the message fits (DISCORD_CARD_PARTS components, DISCORD_CARD_TEXT characters), else, or when Discord refuses them, the embeds.
 */
const DISCORD_CARD_KEYS = ['sub', 'below', 'thumb', 'facts', 'note', 'head', 'buttons'];   // note: a line between the facts and the text (an embed puts it over its text)
const DISCORD_CARD_PARTS = 40;    // components in one message, nested ones too (Discord's limit)
const DISCORD_CARD_TEXT = 4000;   // characters of text in one message (Discord's limit)
const DISCORD_V2 = 32768;         // the message flag IS_COMPONENTS_V2

/** One post as a card: a container with the grey line and the title (a link) under it, the icon on their right, the facts, the text, the picture, the buttons. */
function m3d_discord_card(array $e): array
{
    $title = discord_md_text((string) ($e['title'] ?? ''));
    $foot = trim((string) ($e['footer']['text'] ?? ''));
    $sub = (string) ($e['sub'] ?? '');
    if ($sub === '' && $foot !== '') $sub = discord_md_text($foot) . (isset($e['timestamp']) ? ' · <t:' . strtotime((string) $e['timestamp']) . ':d>' : '');   // no line of its own: the embed's footer
    $note = trim((string) ($e['author']['name'] ?? ''));   // the test message's "Test message: …"
    $below = (string) ($e['below'] ?? '');
    // the grey line on top, the title under it in a text part of its own (a heading after a line in the same text gets a wide gap, Pi), then `below`
    $top = array_merge($sub !== '' ? [['type' => 10, 'content' => '-# ' . $sub]] : [],
        [['type' => 10, 'content' => (($e['url'] ?? '') !== '' ? '## [' . $title . '](' . $e['url'] . ')' : '## ' . $title) . ($note !== '' ? "\n-# " . discord_md_text($note) : '')]],
        $below !== '' ? [['type' => 10, 'content' => '-# ' . $below]] : []);
    $parts = ($e['thumb'] ?? '') !== '' ? [['type' => 9, 'components' => $top, 'accessory' => ['type' => 11, 'media' => ['url' => (string) $e['thumb']]]]] : $top;
    if (!empty($e['facts'])) {
        $parts[] = ['type' => 14];
        $parts[] = ['type' => 10, 'content' => implode("\n", array_map(fn($f) => '-# ' . discord_md_text((string) $f[0]) . "\n" . $f[1], $e['facts']))];
    }
    if (($e['note'] ?? '') !== '') $parts[] = ['type' => 10, 'content' => (string) $e['note']];
    $text = trim((string) ($e['description'] ?? ''));
    if ($text !== '') $parts[] = ['type' => 10, 'content' => (($e['head'] ?? '') !== '' ? '### ' . discord_md_text((string) $e['head']) . "\n" : '') . $text];
    if (!empty($e['image']['url'])) $parts[] = ['type' => 12, 'items' => [['media' => ['url' => (string) $e['image']['url']]]]];
    $buttons = [];
    foreach (array_slice((array) ($e['buttons'] ?? []), 0, 5) as [$label, $url]) {
        if (preg_match('#^https?://#i', (string) $url)) $buttons[] = ['type' => 2, 'style' => 5, 'label' => mb_substr((string) $label, 0, 80), 'url' => (string) $url];
    }
    if ($buttons) $parts[] = ['type' => 1, 'components' => $buttons];
    $card = ['type' => 17, 'components' => $parts];
    if (isset($e['color'])) $card['accent_color'] = (int) $e['color'];
    return $card;
}

/** The message as cards: the intro line on top, one card per post; null when it is over Discord's limits (then the embeds go). */
function m3d_discord_cards(string $message, array $embeds): ?array
{
    $out = trim($message) !== '' ? [['type' => 10, 'content' => mb_substr(trim($message), 0, 1900)]] : [];
    // only the intro line may ping (as before, when the posts were embeds, which never ping): @everyone, @here and role codes in a card's
    // texts (changelogs, visitors' comments in the notifications) get a zero-width space
    $quiet = function (array $c) use (&$quiet): array {
        if (isset($c['content'])) $c['content'] = preg_replace(['/@(everyone|here)/i', '/<@&/'], ["@\u{200B}$1", "<@\u{200B}&"], (string) $c['content']);
        if (!empty($c['components'])) $c['components'] = array_map($quiet, $c['components']);
        return $c;
    };
    foreach ($embeds as $e) $out[] = $quiet(m3d_discord_card($e));
    $parts = $text = 0;
    $walk = function (array $list) use (&$walk, &$parts, &$text) {
        foreach ($list as $c) {
            $parts++;
            $text += mb_strlen((string) ($c['content'] ?? ''));
            if (!empty($c['components'])) $walk($c['components']);
            if (isset($c['accessory'])) $parts++;
        }
    };
    $walk($out);
    return $parts <= DISCORD_CARD_PARTS && $text <= DISCORD_CARD_TEXT ? $out : null;
}

/** Sends one message to the webhook: ['ok' => bool, 'error' => string, 'status' => HTTP status (0: not reached)]. As cards when they fit
 *  (m3d_discord_cards()); a card Discord refuses (400) goes again as the plain embed, and Site problems says why. */
function m3d_discord_post(string $webhook, string $message, array $embeds): array
{
    if (!preg_match(DISCORD_WEBHOOK_RE, $webhook)) return ['ok' => false, 'error' => 'The webhook address is not a Discord webhook.'];
    $base = ['username' => mb_substr(site_name(), 0, 80), 'allowed_mentions' => ['parse' => ['everyone', 'roles']]];
    if ($cards = m3d_discord_cards($message, $embeds)) {
        $r = m3d_discord_send($webhook . '?wait=true&with_components=true', $base + ['components' => $cards, 'flags' => DISCORD_V2]);
        if ($r['ok'] || ($r['status'] ?? 0) !== 400) return $r;
        m3d_problem('Discord', 'Discord refused the card (' . $r['error'] . '), so it was sent as a plain post.');
    }
    $body = $base + ['embeds' => array_map(fn($e) => array_replace(array_diff_key($e, array_flip(DISCORD_CARD_KEYS)),
        ($e['note'] ?? '') !== '' ? ['description' => trim($e['note'] . "\n\n" . ($e['description'] ?? ''))] : []), $embeds)];   // the note over the text
    if (trim($message) !== '') $body['content'] = mb_substr(trim($message), 0, 1900);
    return m3d_discord_send($webhook . '?wait=true', $body);
}

/** One POST to a webhook address (m3d_discord_post()): one short wait when Discord asks to slow down. */
function m3d_discord_send(string $url, array $body): array
{
    for ($try = 0; $try < 2; $try++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'User-Agent: CatalogSite/1.0'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $res = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($status >= 200 && $status < 300) return ['ok' => true, 'error' => '', 'status' => $status];
        $json = is_string($res) ? json_decode($res, true) : null;
        $wait = (float) ($json['retry_after'] ?? 0);
        if ($status === 429 && $try === 0 && $wait > 0 && $wait <= 3) { usleep((int) ($wait * 1e6)); continue; }   // rate limit: one short wait
        if ($status === 0) return ['ok' => false, 'error' => 'Discord could not be reached' . ($err !== '' ? " ($err)" : '') . '.'];
        if ($status === 404 || $status === 401) return ['ok' => false, 'error' => 'Discord doesn’t know this webhook any more: it was deleted. Make a new one and paste it here.'];
        return ['ok' => false, 'status' => $status, 'error' => "Discord answered $status" . (is_array($json) && isset($json['message']) ? ': ' . $json['message'] : '') . '.'];
    }
    return ['ok' => false, 'error' => 'Discord asked to slow down: try again in a minute.'];
}

/** After a save in Admin → Gallery: pictures that became visible to everyone wait; any change while some wait moves the post back. Never throws. */
function m3d_discord_stills_after_save(array $old, array $new): void
{
    try {
        $d = m3d_discord();
        if (!m3d_discord_on() || !$d['stills']) return;
        $before = [];
        foreach ($old as $s) $before[(string) $s['id']] = m3d_still_public_view($s) !== 'hidden';
        $sent = array_flip($d['stills_sent']);
        $queue = $d['stills_queue'];
        foreach ($new as $s) {
            $id = (string) $s['id'];
            if (!empty($before[$id]) || isset($sent[$id]) || in_array($id, $queue, true) || m3d_still_public_view($s) === 'hidden') continue;
            $queue[] = $id;
        }
        if (!$queue) return;
        m3d_save_setting_keys('discord', ['stills_queue' => $queue, 'stills_due' => time() + DISCORD_STILLS_DELAY, 'stills_tries' => 0]);
    } catch (Throwable $ex) {
        m3d_problem('Discord', 'New Gallery pictures: ' . $ex->getMessage());
    }
}

/** From layout_start() and admin_start(): the waiting Gallery pictures are due, so they are posted after this page is sent. */
function m3d_discord_stills_tick(): void
{
    $d = m3d_settings()['discord'] ?? [];
    if (empty($d['stills_queue']) || !is_int($d['stills_due'] ?? null) || $d['stills_due'] > time()) return;
    m3d_after_response(fn() => m3d_discord_stills_run());
}

/** Posts the waiting Gallery pictures (once, under a lock): those still visible to everyone, in the gallery's order. */
function m3d_discord_stills_run(): void
{
    m3d_with_lock('.stills-lock', function () {   // another request on it: nothing to do
        try {
            m3d_settings(true);   // read again under the lock: another request may have posted and emptied the queue since this page read them
            $d = m3d_discord();
            if (!$d['stills_queue'] || $d['stills_due'] === null || $d['stills_due'] > time()) return;
            $wait = array_flip($d['stills_queue']);
            $post = [];
            if (m3d_discord_on() && $d['stills']) {
                foreach (m3d_gallery(true)['stills'] as $s) if (isset($wait[$s['id']]) && m3d_still_public_view($s) !== 'hidden') $post[] = $s;
            }
            $post = array_slice($post, 0, DISCORD_EMBEDS * DISCORD_MESSAGES);   // a bigger batch: the rest is dropped, not flooded
            // taken off the queue before posting: when the settings can't be written (disk full), nothing is posted, rather than
            // the same pictures on every page view
            if (!m3d_save_setting_keys('discord', ['stills_queue' => [], 'stills_due' => null])) {
                m3d_problem('Discord', 'New Gallery pictures: the settings could not be saved, so nothing was posted.');
                return;
            }
            $posted = [];
            $res = ['ok' => true, 'error' => ''];
            foreach (m3d_discord_chunks(array_map(fn($s) => [$s['id'], m3d_discord_still_embed($s)], $post)) as $chunk) {
                if (!$res['ok']) break;
                $res = m3d_discord_post($d['webhook'], '', array_column($chunk, 1));
                if ($res['ok']) $posted = array_merge($posted, array_column($chunk, 0));
            }
            $left = array_values(array_diff(array_column($post, 'id'), $posted));
            $tries = $left ? $d['stills_tries'] + 1 : 0;
            if ($left && $tries >= DISCORD_STILLS_TRIES) $left = [];   // given up: Home → Needs a look and Site problems say so
            $what = m3d_plural(count($post), 'new Gallery picture');
            if (!$res['ok']) m3d_problem('Discord', 'Post of ' . $what . ' failed: ' . $res['error'] . ($left ? ' It will be tried again in ' . intdiv(DISCORD_STILLS_DELAY, 60) . ' minutes.' : ''));
            $stored = m3d_settings(true)['discord'] ?? [];
            $queue = array_values(array_unique(array_merge($left, (array) ($stored['stills_queue'] ?? []))));   // pictures queued while posting stay
            $new = ['stills_sent' => array_slice(array_merge($d['stills_sent'], $posted), -DISCORD_SENT_MAX), 'stills_queue' => $queue,
                'stills_due' => $queue ? ($stored['stills_due'] ?? null ?: time() + DISCORD_STILLS_DELAY) : null,   // a gallery save meanwhile set its own time
                'stills_tries' => $tries];
            if ($post) $new['last'] = ['at' => gmdate('c'), 'ok' => $res['ok'], 'error' => $res['error'], 'what' => $what, 'stills' => true];
            m3d_save_setting_keys('discord', $new);
        } catch (Throwable $ex) {
            m3d_problem('Discord', 'New Gallery pictures: ' . $ex->getMessage());
        }
    });
}

/** One Gallery picture as a Discord embed: the caption (else "New render · names"), the characters linked to their pages,
 *  the picture only when everyone sees it clear, a link to the gallery on its first character. */
function m3d_discord_still_embed(array $s): array
{
    $chars = m3d_still_chars($s);
    $names = array_map(fn($c) => $c['model'] && m3d_visibility($c['model']) === 'public' ? '[' . $c['name'] . '](' . abs_url(m3d_item_url('models', (string) $c['model']['slug'])) . ')' : $c['name'], $chars);
    $caption = trim((string) ($s['caption'] ?? ''));
    $plain = implode(', ', array_column($chars, 'name'));
    // a patron render whose author linked their profile: "By Name" leaves the title (no links there) for a line linking it, as on the site (still_slide())
    $by = '';
    $render = !empty($s['from']) ? m3d_render((string) $s['from']) : null;
    if ($render && ($link = m3d_render_link($render))) {
        $tail = 'By ' . $link['name'];
        if ($caption === $tail || str_ends_with($caption, ' · ' . $tail)) {
            $caption = $caption === $tail ? '' : substr($caption, 0, -strlen(' · ' . $tail));
            $by = 'By [' . preg_replace('/([\\\[\]()*_~`|<>])/', '\\\\$1', $link['name']) . '](' . $link['url'] . ') on ' . ($link['net'] === 'x' ? 'X' : 'Patreon');
            if ($caption === '') $caption = 'New patron render';
        }
    }
    $e = [
        'title'  => mb_substr($caption !== '' ? $caption : 'New render' . ($plain !== '' ? ' · ' . $plain : ''), 0, 250),
        // the picture itself (?p=, opened in the viewer); a blurred one also picks its character, so visitors land on that character's pictures
        'url'    => abs_url('/gallery/?' . (m3d_still_public_view($s) !== 'clear' && $chars ? 'c=' . rawurlencode((string) $chars[0]['key']) . '&' : '') . 'p=' . rawurlencode((string) $s['id'])),
        'footer' => ['text' => mb_substr('Gallery · ' . site_name(), 0, 200)],
    ];
    $lines = $by !== '' ? [$by] : [];
    if ($caption !== '' && $names) $lines[] = implode(', ', $names);
    if (m3d_still_public_view($s) === 'clear') $e['image'] = ['url' => abs_url(img_small((string) $s['src']))];
    else {
        $patrons = ($s['visibility'] ?? '') === 'blurred-patrons';
        foreach ($chars as $c) if ($c['model'] && in_array(m3d_visibility($c['model']), ['blurred-patrons', 'blurred-eligible'], true)) $patrons = true;
        $lines[] = $patrons ? 'Patrons see it in full on the site.' : 'A teaser: shown blurred on the site for now.';
    }
    if ($lines) $e['description'] = mb_substr(implode("\n", $lines), 0, 1000);
    $color = ltrim((string) (m3d_identity()['color'] ?? ''), '#');
    if (preg_match('/^[0-9a-f]{6}$/i', $color)) $e['color'] = hexdec($color);
    $e['sub'] = discord_md_text('Gallery · ' . site_name());   // the card (m3d_discord_card())
    if (($logo = preview_logo()) !== '') $e['thumb'] = abs_url($logo);
    $e['buttons'] = [['Open in the Gallery', $e['url']]];
    return $e;
}
