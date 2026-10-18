<?php
// Blog: /blog/ (newest or pinned post big, the rest as cards) and /blog/<slug>/. Off until Admin → Settings →
// Features → Blog is on (settings.json → blog: {on, title, intro}); while off every blog address is a 404 except for admins.
// data/blog.json (Admin → Blog; missing = no posts): {posts: [post]}, newest first. A post:
//   id (8 hex, never changes: likes and the Discord mark follow it), slug, title, date (UTC 'Y-m-d\TH:i:s\Z': a future date = scheduled),
//   summary, cover (picture address), body (md_html() text), topics [names], items ["models:<slug>" | "outfits:<slug>"],
//   visibility (absent = public | patrons | draft), pinned, discord (announce it on Discord when it goes out), announced (posted
//   there, or too old to post: written by m3d_blog_discord_run(), kept by every save)
// Patrons Only posts: anyone sees the title, cover, summary and the first paragraph; the rest only who sees patron pictures
// (m3d_sees_patron_pics(): paying patrons, exceptions, admins not previewing). A "--- Patreon ---" line in the text (MD_PAYWALL, any
// visibility) does the same from that line on: everyone reads what is above it (m3d_post_free()), patrons see the bar and the rest. Likes: likes.json → posts {post id: [codes]}.
declare(strict_types=1);
defined('M3D_PRIVATE') || exit;

const BLOG_VISIBILITY = ['public' => 'Public', 'patrons' => 'Patrons Only', 'draft' => 'Draft'];
const BLOG_PER_PAGE = 12;          // cards per page of /blog/ (page 1 also has the big post on top)
const BLOG_DISCORD_TRIES = 3;      // failed Discord posts of one blog post before it is given up
const BLOG_DISCORD_MAX_AGE = 3 * 86400;   // a post older than this when the site gets to it is marked, not posted (Discord turned on later)
/** Colours of the topics: picked from the topic's name, so a topic keeps its colour everywhere without a setting. */
const BLOG_TOPIC_COLORS = ['#e8b931', '#b79cff', '#6fcf8e', '#7cc4ff', '#f59f7a', '#f27fa5', '#5fd3c7'];

/** settings.json → blog: on, the list page's title and the line under it. */
function m3d_blog_settings(): array
{
    $b = m3d_settings()['blog'] ?? [];
    return ['on' => !empty($b['on']), 'title' => trim((string) ($b['title'] ?? '')) ?: 'Blog', 'intro' => (string) ($b['intro'] ?? '')];
}

function m3d_blog_on(): bool { return m3d_blog_settings()['on']; }

function m3d_blog(bool $fresh = false): array
{
    static $b = null;
    if ($b !== null && !$fresh) return $b;
    $d = m3d_read_json('blog') ?? [];
    $posts = array_values(array_filter((array) ($d['posts'] ?? []), fn($p) => is_array($p) && !empty($p['id']) && !empty($p['slug'])));
    return $b = ['posts' => $posts];
}

/** Saves every post, newest first (pinned or not: the pages put pinned ones first). */
function m3d_save_blog(array $posts): bool
{
    usort($posts, fn($a, $b) => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));
    $ok = m3d_write_json('blog', ['version' => 1, 'updated' => gmdate('c'), 'posts' => array_values($posts)]);
    m3d_blog(true);
    m3d_blog_summary(true);
    return $ok;
}

/** What every page needs to know about the blog without reading the posts (the menu, the Discord check), see m3d_data_summary():
 *  {n: posts, live: the first date a post is out (0 = none), due: the first date a post waits for its Discord post (0 = none)}. */
function m3d_blog_summary(bool $remake = false): array
{
    return m3d_data_summary('blog', function () {
        $posts = m3d_blog(true)['posts'];
        $first = fn(callable $f) => min(array_map('m3d_post_ts', array_filter($posts, fn($p) => ($p['visibility'] ?? '') !== 'draft' && $f($p))) ?: [0]);
        return ['n' => count($posts), 'live' => $first(fn($p) => true), 'due' => $first(fn($p) => !empty($p['discord']) && empty($p['announced']))];
    }, $remake) + ['n' => 0, 'live' => 0, 'due' => 0];
}

function m3d_post(string $id): ?array
{
    foreach (m3d_blog()['posts'] as $p) if ($p['id'] === $id) return $p;
    return null;
}

function m3d_post_by_slug(string $slug): ?array
{
    foreach (m3d_blog()['posts'] as $p) if ($p['slug'] === $slug) return $p;
    return null;
}

function m3d_post_url(array $p): string { return '/blog/' . $p['slug'] . '/'; }

function m3d_post_ts(array $p): int { return (int) (strtotime((string) ($p['date'] ?? '')) ?: 0); }

/** 'draft', 'scheduled' (its date is still to come) or 'live'. */
function m3d_post_state(array $p): string
{
    if (($p['visibility'] ?? '') === 'draft') return 'draft';
    return m3d_post_ts($p) > time() ? 'scheduled' : 'live';
}

/** What this visitor gets of a post: 'hidden' (drafts and scheduled posts, for everyone but admins), 'teaser' (a Patrons Only post
 *  for someone who can't read it) or 'full'. */
function m3d_post_view(array $p): string
{
    if (m3d_post_state($p) !== 'live' && !m3d_is_admin($GLOBALS['M3D'])) return 'hidden';
    if ((($p['visibility'] ?? '') === 'patrons' || m3d_post_free($p) !== null) && !m3d_sees_patron_pics()) return 'teaser';
    return 'full';
}

/** The text above the post's "--- Patreon ---" line (MD_PAYWALL, outside code blocks), or null when it has none. The line is found
 *  exactly as md_html() finds it (tabs as 4 spaces, code blocks by md_html()'s own pattern), so a line the page leaves out for readers
 *  always hides what is under it too (an unclosed ``` or a tab after the line once showed the patrons' part to everyone). */
function m3d_post_free(array $p): ?string
{
    $body = str_replace(["\r\n", "\t"], ["\n", '    '], (string) ($p['body'] ?? ''));
    if (stripos($body, 'patreon') === false) return null;
    // code blocks blanked out, their length kept: a line inside one isn't the paywall
    $plain = preg_replace_callback('/^```([\w+-]*)[ ]*\n(.*?)\n?```[ ]*$/ms', fn($m) => preg_replace('/[^\n]/', ' ', $m[0]), $body);
    if (!preg_match('/^' . MD_PAYWALL . '$/mi', (string) $plain, $m, PREG_OFFSET_CAPTURE)) return null;
    return rtrim(substr($body, 0, $m[0][1]));
}

/** What anyone may read of the text: above the "--- Patreon ---" line, else all of it (the teaser of a Patrons Only post is its first paragraph). */
function m3d_post_public_text(array $p): string
{
    return m3d_post_free($p) ?? (string) ($p['body'] ?? '');
}

/** The posts this visitor may see: pinned ones first (newest first), then the rest, newest first. */
function m3d_blog_visible(): array
{
    $out = array_values(array_filter(m3d_blog()['posts'], fn($p) => m3d_post_view($p) !== 'hidden'));
    usort($out, fn($a, $b) => [empty($a['pinned']), m3d_post_ts($b)] <=> [empty($b['pinned']), m3d_post_ts($a)]);
    return $out;
}

/** The menus, the footer and the sitemap link the blog while it is on and has a post this visitor can see (any post, for admins).
 *  From the summary, so the other pages never read the posts. */
function m3d_has_blog(): bool
{
    if (!m3d_blog_on()) return false;
    $s = m3d_blog_summary();
    return ($s['live'] > 0 && $s['live'] <= time()) || ($s['n'] > 0 && m3d_is_admin($GLOBALS['M3D']));
}

/** "4 min read": about 200 words a minute, at least 1. */
function m3d_post_minutes(array $p): int
{
    $text = preg_replace('/!\[[^\]]*\]\([^)]*\)|\]\([^)]*\)|[#>*`|=_-]+/', ' ', (string) ($p['body'] ?? ''));
    return max(1, (int) round(count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY)) / 200));
}

/** A topic's colour (BLOG_TOPIC_COLORS, from its name: the same topic always gets the same one). */
function blog_topic_color(string $topic): string
{
    return BLOG_TOPIC_COLORS[crc32(m3d_fold($topic)) % count(BLOG_TOPIC_COLORS)];
}

/** Topics of the posts this visitor sees: name => number of posts, the most used first. */
function m3d_blog_topics(array $posts): array
{
    $n = $names = [];
    foreach ($posts as $p) foreach ((array) ($p['topics'] ?? []) as $t) {
        $k = m3d_fold((string) $t);
        $names[$k] ??= (string) $t;
        $n[$k] = ($n[$k] ?? 0) + 1;
    }
    arsort($n);
    $out = [];
    foreach ($n as $k => $c) $out[$names[$k]] = $c;
    return $out;
}

/** The models and accessories a post shows under it that this visitor may open: [[kind, item], …]. */
function m3d_post_items(array $p): array
{
    $out = [];
    foreach ((array) ($p['items'] ?? []) as $ref) {
        [$kind, $slug] = explode(':', (string) $ref, 2) + [1 => ''];
        if (!in_array($kind, ['models', 'outfits'], true) || !($it = m3d_item($kind, $slug)) || !m3d_listed($it)) continue;
        $out[] = [$kind, $it];
    }
    return $out;
}

/** Points the pictures of the blog (covers and pictures in the texts) to their new addresses (old => new); saves only when something changed. */
function m3d_blog_replace(array $map): bool
{
    $posts = m3d_blog(true)['posts'];
    $inText = [];
    foreach ($map as $old => $new) $inText['](' . $old . ')'] = '](' . $new . ')';
    $new = array_map(function ($p) use ($map, $inText) {
        if (isset($map[$p['cover'] ?? ''])) $p['cover'] = $map[$p['cover']];
        if (isset($p['body'])) $p['body'] = strtr((string) $p['body'], $inText);
        return $p;
    }, $posts);
    return $new === $posts || m3d_save_blog($new);
}

/** A model or accessory got a new address ($new) or was deleted ($new = ''): the posts showing it follow, or leave it out
 *  (a new item at that address isn't shown by old posts). From m3d_rename_counts() and admin_forget_items(). */
function m3d_blog_rename_item(string $kind, string $old, string $new): void
{
    if (!is_file(M3D_PRIVATE . '/data/blog.json')) return;
    $posts = m3d_blog(true)['posts'];
    $changed = false;
    foreach ($posts as &$p) {
        $i = array_search($kind . ':' . $old, (array) ($p['items'] ?? []), true);
        if ($i === false) continue;
        if ($new !== '') $p['items'][$i] = $kind . ':' . $new;
        else { array_splice($p['items'], $i, 1); if (!$p['items']) unset($p['items']); }
        $changed = true;
    }
    unset($p);
    if ($changed) m3d_save_blog($posts);
}

/** Posts deleted in the admin: their likes go. */
function m3d_blog_forget_likes(array $ids): void
{
    if (!$ids) return;
    m3d_json_update('likes', function ($d) use ($ids) {
        $d = is_array($d) ? $d : [];
        foreach ($ids as $id) unset($d['posts'][$id]);
        return [$d, true];
    });
}

// ── Discord ──────────────────────────────────────────────────────────────────
// A post with `discord` on is announced once it is live (its date reached, not a draft), through the webhook of Admin → Settings →
// Discord announcements; then it gets `announced`. A post found live more than BLOG_DISCORD_MAX_AGE after its date (announcements
// turned on later, a draft from long ago published) is only marked. Failures are tried again on later page views, BLOG_DISCORD_TRIES
// times at most (`tries`), and logged in Site problems.

/** From layout_start() and admin_start(): a post is due (by the summary), so it is posted after this page is sent. */
function m3d_blog_discord_tick(): void
{
    if (!m3d_blog_on()) return;
    $due = m3d_blog_summary()['due'];
    if ($due === 0 || $due > time()) return;
    m3d_after_response(fn() => m3d_blog_discord_run());
}

function m3d_blog_discord_run(): void
{
    m3d_with_lock('.blog-discord-lock', function () {
        try {
            $posts = m3d_blog(true)['posts'];
            $on = m3d_discord_on();
            $d = m3d_discord();
            $changed = false;
            foreach ($posts as &$p) {
                if (empty($p['discord']) || !empty($p['announced']) || m3d_post_state($p) !== 'live') continue;
                if (!$on || time() - m3d_post_ts($p) > BLOG_DISCORD_MAX_AGE) { $p['announced'] = true; $changed = true; continue; }
                $res = m3d_discord_post($d['webhook'], $d['message'], [m3d_blog_discord_embed($p)]);
                $changed = true;
                if ($res['ok']) { $p['announced'] = true; unset($p['tries']); }
                else {
                    $p['tries'] = (int) ($p['tries'] ?? 0) + 1;
                    if ($p['tries'] >= BLOG_DISCORD_TRIES) { $p['announced'] = true; unset($p['tries']); }
                    m3d_problem('Discord', 'Post of the blog post “' . $p['title'] . '” failed: ' . $res['error'] . (empty($p['announced']) ? ' It will be tried again.' : ''));
                }
                m3d_save_setting_keys('discord', ['last' => ['at' => gmdate('c'), 'ok' => $res['ok'], 'error' => $res['error'], 'what' => 'Blog: ' . $p['title'], 'blog' => true]]);
            }
            unset($p);
            if ($changed) m3d_save_blog($posts);
        } catch (Throwable $ex) {
            m3d_problem('Discord', 'Blog: ' . $ex->getMessage());
        }
    });
}

/** A post as a Discord embed: title, summary (Patrons Only says so), the cover, the topics in the footer. */
function m3d_blog_discord_embed(array $p): array
{
    $patrons = ($p['visibility'] ?? '') === 'patrons';
    $text = trim((string) ($p['summary'] ?? '')) ?: md_first_text(m3d_post_public_text($p));
    if ($patrons) $text = trim($text . "\n\n" . 'For patrons: log in with Patreon on the site to read it.');
    elseif (m3d_post_free($p) !== null) $text = trim($text . "\n\n" . 'Part of it is for patrons: log in with Patreon on the site to read it all.');
    $e = [
        'title'  => mb_substr((string) $p['title'], 0, 250),
        'url'    => abs_url(m3d_post_url($p)),
        'footer' => ['text' => mb_substr(implode(' · ', array_merge((array) ($p['topics'] ?? []), [m3d_blog_settings()['title'], site_name()])), 0, 200)],
        'timestamp' => gmdate('c', m3d_post_ts($p)),
    ];
    if ($text !== '') $e['description'] = mb_substr($text, 0, 1800);
    $color = ltrim((string) (m3d_identity()['color'] ?? ''), '#');
    if (preg_match('/^[0-9a-f]{6}$/i', $color)) $e['color'] = hexdec($color);
    if (($c = (string) ($p['cover'] ?? '')) !== '' && !$patrons) $e['image'] = ['url' => abs_url(img_small($c))];   // like link previews: Public posts only
    return $e;
}

// ── Page pieces ──────────────────────────────────────────────────────────────
const BLOG_LOCK_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';

/** A topic label: coloured dot + name; $href makes it a link (the list filtered on it). */
function blog_topic(string $t, string $href = ''): string
{
    $in = '<i></i>' . e($t);
    $style = ' style="--c:' . blog_topic_color($t) . '"';
    return $href !== '' ? '<a class="bl-tag" href="' . e($href) . '"' . $style . '>' . $in . '</a>' : '<span class="bl-tag"' . $style . '>' . $in . '</span>';
}

/** The small grey line: date · N min read (the date in the visitor's own time zone, site.js). */
function blog_meta(array $p): string
{
    return when_html(m3d_post_ts($p), 'date') . '<span class="dot">·</span>' . m3d_post_minutes($p) . ' min read';
}

/** Pills for admins and patron posts: Pinned, Patrons, Draft, Scheduled. */
function blog_pills(array $p, bool $pinned = true): string
{
    $out = '';
    $state = m3d_post_state($p);
    if ($state === 'draft') $out .= '<span class="draft-pill bl-draft">Draft</span>';
    if ($state === 'scheduled') $out .= '<span class="draft-pill bl-sched">Scheduled</span>';
    if ($pinned && !empty($p['pinned'])) $out .= '<span class="bl-pin">Pinned</span>';
    if (($p['visibility'] ?? '') === 'patrons') $out .= '<span class="lock-pill">' . BLOG_LOCK_ICON . 'Patrons</span>';
    return $out;
}

/** The cover of a card or the post: its versions with their real widths ($sizes = how wide it shows), or a plain block without a picture. */
function blog_cover(array $p, string $sizes, bool $eager = false): string
{
    $src = (string) ($p['cover'] ?? '');
    if ($src === '') return '<span class="bl-ph" aria-hidden="true">' . e(mb_substr((string) $p['title'], 0, 1)) . '</span>';
    $set = img_srcset($src, ['xs', 'sm', 'md', '']);
    return '<img src="' . e(img_small($src)) . '"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . e($sizes) . '"' : '') . ' alt="" loading="' . ($eager ? 'eager' : 'lazy') . '"' . ($eager ? ' fetchpriority="high"' : '') . ' decoding="async" />';
}

/** One card of /blog/ ($lead: the big one on top of the first page). */
function blog_card(array $p, bool $lead = false): string
{
    $topics = implode('', array_map(fn($t) => blog_topic((string) $t), array_slice((array) ($p['topics'] ?? []), 0, 2)));
    $sum = trim((string) ($p['summary'] ?? ''));
    $likes = m3d_like_count($p['id'], 'posts');
    $likesHtml = $likes ? '<span class="bl-likes" title="' . $likes . ' ' . ($likes === 1 ? 'like' : 'likes') . '">' . HEART_ICON . $likes . '</span>' : '';
    $likesHtml = comments_pill('posts', $p, (string) $p['id']) . $likesHtml;
    $pills = blog_pills($p, $lead);
    if ($lead) {
        return '<a class="bl-lead" href="' . e(m3d_post_url($p)) . '">'
            . '<span class="pic">' . blog_cover($p, '(max-width: 900px) 92vw, 680px', true) . '</span>'
            . '<span class="txt">'
            . ($pills . $topics !== '' ? '<span class="bl-meta">' . $pills . $topics . '</span>' : '')
            . '<h2>' . e($p['title']) . '</h2>'
            . ($sum !== '' ? '<p>' . e($sum) . '</p>' : '')
            . '<span class="bl-meta">' . blog_meta($p) . $likesHtml . '</span>'
            . '<span class="more">Read the Post →</span>'
            . '</span></a>';
    }
    return '<a class="bl-card" href="' . e(m3d_post_url($p)) . '">'
        . '<span class="pic">' . blog_cover($p, '(max-width: 600px) 92vw, (max-width: 900px) 46vw, 390px') . ($pills !== '' ? '<span class="cover-tags">' . $pills . '</span>' : '') . '</span>'
        . '<span class="body">'
        . ($topics !== '' ? '<span class="bl-meta">' . $topics . '</span>' : '')
        . '<h3>' . e($p['title']) . '</h3>'
        . ($sum !== '' ? '<p>' . e($sum) . '</p>' : '')
        . '<span class="bl-meta">' . blog_meta($p) . $likesHtml . '</span>'
        . '</span></a>';
}
