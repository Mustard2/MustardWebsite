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
// A post may carry a poll (`poll`, see "Polls" below): paying patrons vote under it, votes in data/polls.json.
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
        return ['n' => count($posts), 'live' => $first(fn($p) => true), 'due' => $first(fn($p) => !empty($p['discord']) && empty($p['announced'])), 'cards' => m3d_blog_cards($posts)];
    }, $remake) + ['n' => 0, 'live' => 0, 'due' => 0];
}

const BLOG_HOME_MAX = 4;   // posts in the homepage's "From the Blog" row

/** The summary's `cards` (the homepage's "From the Blog", without reading the posts): what a card shows of every post not yet out (scheduled) and of
 *  the BLOG_HOME_MAX newest ones out when the summary was made, newest first, so a scheduled post takes its place on its day. Drafts never. */
function m3d_blog_cards(array $posts): array
{
    $out = array_values(array_filter($posts, fn($p) => ($p['visibility'] ?? '') !== 'draft' && m3d_post_ts($p) > 0));
    usort($out, fn($a, $b) => m3d_post_ts($b) <=> m3d_post_ts($a));
    $now = time();
    $live = 0;
    $out = array_filter($out, function ($p) use ($now, &$live) { return m3d_post_ts($p) > $now || $live++ < BLOG_HOME_MAX; });
    return array_values(array_map(fn($p) => ['id' => (string) $p['id'], 'slug' => (string) $p['slug'], 'title' => (string) $p['title'], 'date' => (string) $p['date'],
        'cover' => (string) ($p['cover'] ?? ''), 'topic' => (string) (((array) ($p['topics'] ?? []))[0] ?? ''), 'visibility' => (string) ($p['visibility'] ?? ''),
        'min' => m3d_post_minutes($p)], $out));
}

/** The homepage's "From the Blog": the newest posts out (Patrons Only ones too: their card is a teaser for everyone), the same for every visitor,
 *  admins included; none while the blog is off. */
function m3d_blog_latest(int $max = BLOG_HOME_MAX): array
{
    if (!m3d_blog_on()) return [];
    $s = m3d_blog_summary();
    if (!isset($s['cards'])) $s = m3d_blog_summary(true);   // a summary made before the cards were added to it
    $now = time();
    return array_slice(array_values(array_filter((array) ($s['cards'] ?? []), fn($c) => m3d_post_ts($c) <= $now)), 0, $max);
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

/** The text above the post's first "--- Patreon ---" line (MD_PAYWALL), or null when it has none. Fails closed: the first such line
 *  anywhere counts, inside a code block or a tab too (tabs as 4 spaces, as md_html() reads them), so a line the page may leave out for
 *  readers always hides what is under it (code blocks and tabs written oddly around the line once showed the patrons' part to everyone).
 *  A post that shows "--- Patreon ---" as an example inside code is cut there for readers as well. */
function m3d_post_free(array $p): ?string
{
    $body = str_replace(["\r\n", "\t"], ["\n", '    '], (string) ($p['body'] ?? ''));
    if (stripos($body, 'patreon') === false) return null;
    if (!preg_match('/^' . MD_PAYWALL . '$/mi', $body, $m, PREG_OFFSET_CAPTURE)) return null;
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
        if (!in_array($kind, M3D_ITEM_KINDS, true) || !($it = m3d_item($kind, $slug)) || !m3d_listed($it)) continue;
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
        foreach ((array) ($p['poll']['opts'] ?? []) as $i => $o) if (isset($map[$o['pic'] ?? ''])) $p['poll']['opts'][$i]['pic'] = $map[$o['pic']];
        if (isset($p['body'])) $p['body'] = strtr((string) $p['body'], $inText);
        return $p;
    }, $posts);
    return $new === $posts || m3d_save_blog($new);
}

/** Models or accessories deleted (admin_forget_items()): the posts showing them leave them out (a new item at that address isn't
 *  shown by old posts); a poll choice linked to one keeps its text, without the link. */
function m3d_blog_forget_items(string $kind, array $slugs): void
{
    if (!$slugs || !is_file(M3D_PRIVATE . '/data/blog.json')) return;
    $gone = array_flip(array_map(fn($s) => $kind . ':' . $s, $slugs));
    $posts = m3d_blog(true)['posts'];
    $changed = false;
    foreach ($posts as &$p) {
        foreach ((array) ($p['poll']['opts'] ?? []) as $k => $o) if (isset($gone[$o['item'] ?? ''])) {
            unset($p['poll']['opts'][$k]['item']);
            $changed = true;
        }
        $items = array_values(array_filter((array) ($p['items'] ?? []), fn($i) => !isset($gone[$i])));
        if ($items === (array) ($p['items'] ?? [])) continue;
        if ($items) $p['items'] = $items; else unset($p['items']);
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
    // the card (m3d_discord_card()): the blog's title over the post's, topics · date under it, the site's icon, a button
    $e['sub'] = discord_md_text((string) m3d_blog_settings()['title']);
    $e['below'] = implode(' · ', array_merge(array_map('discord_md_text', (array) ($p['topics'] ?? [])), ['<t:' . m3d_post_ts($p) . ':d>']));
    if (($logo = preview_logo()) !== '') $e['thumb'] = abs_url($logo);
    $e['buttons'] = [['Read the Post', $e['url']]];
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
    if (($poll = m3d_poll($p)) && !m3d_poll_closed($poll)) $out .= '<span class="bl-poll">Poll</span>';   // an open poll: patrons can vote
    return $out;
}

/** A post's title for the page: a dash between spaces stays at the end of its line ("MustardUI 2026.11 -" / "New effects!"), never starting the next one. */
function blog_title(array $p): string
{
    return e(preg_replace('/ (?=[-–—](?:\s|$))/u', "\u{A0}", (string) $p['title']));
}

/** The cover of a card or the post: its versions with their real widths ($sizes = how wide it shows), or a plain block without a picture. */
function blog_cover(array $p, string $sizes, bool $eager = false): string
{
    $src = (string) ($p['cover'] ?? '');
    if ($src === '') return '<span class="bl-ph" aria-hidden="true">' . e(mb_substr((string) $p['title'], 0, 1)) . '</span>';
    $set = img_srcset($src, ['xs', 'sm', 'md', '']);
    return '<img src="' . e(img_small($src)) . '"' . ($set !== '' ? ' srcset="' . e($set) . '" sizes="' . e($sizes) . '"' : '') . ' alt="" loading="' . ($eager ? 'eager' : 'lazy') . '"' . ($eager ? ' fetchpriority="high"' : '') . ' decoding="async" />';
}

/** The post page's hero keeps the cover's own shape: --ar = its width / height (wide screens: the picture is that wide at the hero's height, its sides fading),
 *  --ar-s = the phones' frame (at most 4:3, so a wide render isn't zoomed in much). Unknown size = 16:9. */
function blog_hero_ratio(string $src): string
{
    $d = img_dims($src);
    $ar = $d && $d[0] > 0 && $d[1] > 0 ? $d[0] / $d[1] : 16 / 9;
    $ar = max(1.2, min(3.0, $ar));
    return '--ar: ' . round($ar, 4) . '; --ar-s: ' . round(min($ar, 4 / 3), 4);
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
            . '<h2>' . blog_title($p) . '</h2>'
            . ($sum !== '' ? '<p>' . e($sum) . '</p>' : '')
            . '<span class="bl-meta">' . blog_meta($p) . $likesHtml . '</span>'
            . '<span class="more">Read the Post →</span>'
            . '</span></a>';
    }
    return '<a class="bl-card" href="' . e(m3d_post_url($p)) . '">'
        . '<span class="pic">' . blog_cover($p, '(max-width: 600px) 92vw, (max-width: 900px) 46vw, 390px') . ($pills !== '' ? '<span class="cover-tags">' . $pills . '</span>' : '') . '</span>'
        . '<span class="body">'
        . ($topics !== '' ? '<span class="bl-meta">' . $topics . '</span>' : '')
        . '<h3>' . blog_title($p) . '</h3>'
        . ($sum !== '' ? '<p>' . e($sum) . '</p>' : '')
        . '<span class="bl-meta">' . blog_meta($p) . $likesHtml . '</span>'
        . '</span></a>';
}

/** A small card of the homepage's "From the Blog" (the look of recent_card()): cover, topic (+ Patrons), title on up to 2 lines, date · N min read. $c = a summary card. */
function blog_home_card(array $c): string
{
    $tag = ($c['topic'] !== '' ? blog_topic($c['topic']) : '<span class="recent-tag is-upd">Post</span>')
        . ($c['visibility'] === 'patrons' ? '<span class="lock-pill">' . BLOG_LOCK_ICON . 'Patrons</span>' : '');
    return '<a class="recent is-post" href="' . e(m3d_post_url($c)) . '">'
        . '<span class="recent-img">' . card_img($c['cover'] !== '' ? img_thumb($c['cover']) : '', $c['title']) . '</span>'
        . '<span class="recent-body"><span class="recent-tags">' . $tag . '</span>'
        . '<b class="recent-name">' . blog_title($c) . '</b>'
        . '<span class="recent-meta">' . when_html(m3d_post_ts($c), 'date') . '<span class="dot">·</span>' . $c['min'] . ' min read</span>'
        . '</span></a>';
}

// ── Polls ────────────────────────────────────────────────────────────────────
// A post's `poll`: {q (question), opts [{id (4 hex, never changes: votes are kept under it), text, pic? (picture address), item? ("models:<slug>" |
// "outfits:<slug>": its card picture and a link)}], max (choices each patron picks, 1–opts), closes? (UTC 'Y-m-d\TH:i:s\Z'; absent = open until
// removed), results (POLL_RESULTS: who sees the counts before it closes), change? (a patron may change their vote while it is open)}; clean_poll() in _admin.php.
// Paying patrons vote (the same rule as likes and downloads: `access`), on a live post they can read in full; auth/vote.php.
// data/polls.json: {votes: {post id: {patron code (m3d_like_code()): [option ids]}}}: no raw ids or names (privacy.php, Personal Data);
// restored with the counters (DATA_PARTS). An option removed in the editor drops out of the counts (its votes are ignored).

const POLL_RESULTS = ['after' => 'After Voting', 'always' => 'Always', 'closed' => 'When Closed'];
const POLL_OPTS_MAX = 8;
// why auth/vote.php refused a vote (?pollerr=)
const POLL_ERRORS = ['session' => 'Your session expired. Reload the page and vote again.', 'login' => 'Log in with Patreon to vote.',
    'patron' => 'Polls are for patrons on any paid tier.', 'preview' => 'Voting is off while previewing as a patron.', 'closed' => 'This poll is closed.',
    'voted' => 'You have already voted in this poll.', 'pick' => 'Pick at least one choice.', 'many' => 'Too many choices picked.', 'save' => 'Your vote couldn’t be saved. Try again in a moment.'];

/** The post's poll when it has one worth showing (a question and two choices or more), else null. */
function m3d_poll(array $p): ?array
{
    $poll = $p['poll'] ?? null;
    if (!is_array($poll) || trim((string) ($poll['q'] ?? '')) === '' || count((array) ($poll['opts'] ?? [])) < 2) return null;
    return $poll;
}

function m3d_poll_closes(array $poll): int { return (int) (strtotime((string) ($poll['closes'] ?? '')) ?: 0); }

function m3d_poll_closed(array $poll): bool { return ($t = m3d_poll_closes($poll)) > 0 && $t <= time(); }

/** Every vote of one post: [patron code => [option ids]] (read once per request). */
function m3d_poll_votes(string $postId, bool $fresh = false): array
{
    static $all = null;
    if ($all === null || $fresh) $all = (array) ((m3d_read_json('polls') ?? [])['votes'] ?? []);
    return array_map(fn($v) => array_values(array_map('strval', (array) $v)), (array) ($all[$postId] ?? []));
}

/** The counts: [voters, [option id => votes]] (only the options the poll still has). */
function m3d_poll_tally(array $p): array
{
    $ids = array_column((array) ($p['poll']['opts'] ?? []), 'id');
    $c = array_fill_keys($ids, 0);
    $n = 0;
    foreach (m3d_poll_votes((string) $p['id']) as $picked) {
        $picked = array_intersect($picked, $ids);
        if (!$picked) continue;
        $n++;
        foreach ($picked as $id) $c[$id]++;
    }
    return [$n, $c];
}

/** The options this patron picked (still in the poll), [] = not voted. */
function m3d_poll_mine(array $p, ?array $patron): array
{
    if (!$patron || empty($patron['id']) || !empty($patron['preview'])) return [];
    $ids = array_column((array) ($p['poll']['opts'] ?? []), 'id');
    return array_values(array_intersect(m3d_poll_votes((string) $p['id'])[m3d_like_code((string) $patron['id'])] ?? [], $ids));
}

/** Saves a vote: 'ok', 'voted' (already voted and the poll doesn't allow changes) or 'save' (not written). */
function m3d_poll_vote(array $p, string $patronId, array $picked): string
{
    $code = m3d_like_code($patronId);
    $ids = array_column((array) ($p['poll']['opts'] ?? []), 'id');
    $change = !empty($p['poll']['change']);
    $r = m3d_json_update('polls', function ($d) use ($p, $code, $ids, $picked, $change) {
        $d = is_array($d) ? $d : [];
        $had = array_intersect((array) ($d['votes'][$p['id']][$code] ?? []), $ids);
        if ($had && !$change) return [$d, 'voted'];
        $d['votes'][$p['id']][$code] = array_values($picked);
        return [$d, 'ok'];
    });
    m3d_poll_votes('', true);
    return is_string($r) ? $r : 'save';
}

/** Posts deleted in the admin: their votes go (like their likes). */
function m3d_polls_forget(array $ids): void
{
    if (!$ids || !is_file(M3D_PRIVATE . '/data/polls.json')) return;
    m3d_json_update('polls', function ($d) use ($ids) {
        $d = is_array($d) ? $d : [];
        foreach ($ids as $id) unset($d['votes'][$id]);
        $d['votes'] = (object) ($d['votes'] ?? []);
        return [$d, true];
    });
}

/** Whether data/polls.json holds any vote (privacy.php names polls while the blog is on or while it does). */
function m3d_polls_stored(): bool
{
    return (bool) array_filter((array) ((m3d_read_json('polls') ?? [])['votes'] ?? []));
}

/** One option's picture [src, blurred] and link: its own picture, else its model's / accessory's card picture (blurred when that is hidden). */
function m3d_poll_opt_view(array $o): array
{
    $link = '';
    if (($o['item'] ?? '') !== '') {
        [$kind, $slug] = explode(':', (string) $o['item'], 2) + [1 => ''];
        $it = in_array($kind, M3D_ITEM_KINDS, true) ? m3d_item($kind, $slug) : null;
        if ($it && m3d_listed($it)) {
            $link = m3d_item_url($kind, $slug);
            if (($o['pic'] ?? '') === '') return [...card_pic($kind, $it), $link];
        }
    }
    $pic = (string) ($o['pic'] ?? '');
    return [$pic !== '' ? img_small($pic) : '', false, $link];
}

/** The poll under a post (only for readers who see it in full). Votes: a form to auth/vote.php; results: bars with the patron's own choices. */
function blog_poll(array $p): string
{
    $poll = m3d_poll($p);
    if (!$poll) return '';
    $who = m3d_patron($GLOBALS['M3D']);
    $creator = m3d_is_admin($GLOBALS['M3D']) && !m3d_preview();
    $closed = m3d_poll_closed($poll);
    $live = m3d_post_state($p) === 'live';
    $mine = m3d_poll_mine($p, $who);
    $can = $who && !empty($who['access']) && empty($who['preview']) && $live && !$closed;
    $max = max(1, min((int) ($poll['max'] ?? 1), count($poll['opts'])));
    $res = (string) ($poll['results'] ?? 'after');
    $showRes = $closed || $creator || $res === 'always' || ($res === 'after' && $mine);
    [$n, $counts] = m3d_poll_tally($p);
    $closes = m3d_poll_closes($poll);
    $icon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true"><path d="M5 20V10M12 20V4M19 20v-7" stroke-linecap="round"/></svg>';
    $check = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="m5 12 5 5 9-10" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    // a picture for every choice when some have one (the others get their first letter), none when no choice has one
    $anyPic = (bool) array_filter($poll['opts'], fn($o) => m3d_poll_opt_view($o)[0] !== '');
    $picHtml = function (array $o) use ($anyPic): string {
        if (!$anyPic) return '';
        [$src, $blur] = m3d_poll_opt_view($o);
        return $src !== '' ? '<span class="pl-pic' . ($blur ? ' is-blurred' : '') . '"><img src="' . e($src) . '" alt="" loading="lazy" decoding="async"></span>'
            : '<span class="pl-pic pl-ph" aria-hidden="true">' . e(mb_strtoupper(mb_substr((string) $o['text'], 0, 1))) . '</span>';
    };
    $when = $closed ? 'Closed ' . when_mark($closes, 'date') : ($closes ? 'Closes ' . when_mark($closes) : '');
    $meta = implode(' · ', array_filter([$when, m3d_plural($n, 'vote')]));
    $h = '<section class="pl-card" id="poll" aria-label="Poll"><div class="pl-head"><span class="pl-badge' . ($closed ? ' is-closed' : '') . '">' . $icon . ($closed ? 'Poll Closed' : 'Patron Poll') . '</span>'
        . '<span class="pl-when">' . when_text($meta) . '</span></div><h2 class="pl-q">' . e((string) $poll['q']) . '</h2>';

    // the choices to pick (a form for patrons who can vote, the same tiles greyed for everyone else)
    $choices = function (bool $form) use ($poll, $max, $mine, $check, $anyPic, $picHtml): string {
        $h = '<div class="pl-grid' . ($anyPic ? '' : ' is-text') . '">';   // no pictures at all: one row per choice
        foreach ($poll['opts'] as $o) {
            $on = in_array($o['id'], $mine, true);
            $h .= '<label class="pl-opt"><input type="' . ($max > 1 ? 'checkbox' : 'radio') . '" name="opt[]" value="' . e($o['id']) . '"'
                . ($on ? ' checked' : '') . ($form ? '' : ' disabled') . '>' . $picHtml($o)
                . '<span class="pl-tick">' . $check . '</span><span class="pl-name">' . e((string) $o['text']) . '</span></label>';
        }
        return $h . '</div>';
    };
    $form = function (string $button) use ($p, $choices, $max): string {
        return '<form class="pl-form" method="post" action="/auth/vote.php" data-poll="' . $max . '"><input type="hidden" name="csrf" value="' . e(m3d_csrf()) . '"><input type="hidden" name="post" value="' . e((string) $p['id']) . '">'
            . $choices(true) . '<div class="pl-foot"><p class="pl-note" data-poll-count>' . ($max > 1 ? 'Pick up to ' . $max . '.' : 'Pick one.') . '</p><button class="btn btn-primary" type="submit">' . $button . '</button></div></form>';
    };

    if ($can && (!$mine || (!empty($poll['change']) && !$showRes))) {   // can vote and hasn't yet (or may change a vote whose results are still hidden)
        $hint = ($max > 1 ? 'Pick up to ' . $max . '.' : 'Pick one.') . (!empty($poll['change']) ? ' You can change your vote until the poll closes.' : '')
            . ($res === 'after' && !$creator ? ' Results show once you voted.' : '') . ($res === 'closed' && !$creator ? ' Results show when the poll closes.' : '');
        return $h . '<p class="pl-hint">' . e($hint) . '</p>' . $form($mine ? 'Save My Vote' : 'Vote') . '</section>';
    }
    if ($showRes) {
        $top = $counts ? max($counts) : 0;
        $h .= '<div class="pl-res">';
        foreach ($poll['opts'] as $o) {
            $link = m3d_poll_opt_view($o)[2];
            $c = $counts[$o['id']] ?? 0;
            $pct = $n ? (int) round($c * 100 / $n) : 0;
            $win = $closed && $top > 0 && $c === $top;
            $name = e((string) $o['text']);
            if ($link !== '') $name = '<a href="' . e($link) . '">' . $name . '</a>';
            $h .= '<div class="pl-row' . ($top > 0 && $c === $top ? ' is-top' : '') . ($anyPic ? '' : ' no-pic') . '">' . $picHtml($o)
                . '<div class="pl-bar-wrap"><div class="pl-line"><span class="pl-name">' . $name
                . (in_array($o['id'], $mine, true) ? ' <span class="pl-mine">' . $check . 'Your Vote</span>' : '') . ($win ? ' <span class="pl-won">Winner</span>' : '')
                . '</span><span class="pl-pct">' . $pct . '%</span></div><div class="pl-bar"><i style="width:' . $pct . '%"></i></div><span class="pl-sub">' . m3d_plural($c, 'vote') . '</span></div></div>';
        }
        $h .= '</div>';
    } else {
        $h .= $choices(false);
    }
    // under it: what this visitor can do
    if ($can && $mine && !empty($poll['change'])) {
        $h .= '<details class="pl-change"><summary class="btn btn-ghost btn-sm">Change My Vote</summary>' . $form('Save My Vote') . '</details>';
    } elseif ($mine) {
        $h .= '<div class="pl-foot"><p class="pl-note">Thanks for voting!' . ($showRes ? '' : ' The results show when the poll closes.') . '</p></div>';
    } elseif (!$closed && $live) {
        $note = $res === 'closed' ? ' Results show when the poll closes.' : ($res === 'after' ? ' Results show once you voted.' : '');
        if (!$who) {
            $h .= '<div class="pl-foot"><p class="pl-note">Patrons on any paid tier can vote.' . $note . '</p><a class="btn btn-primary" rel="nofollow" href="/auth/login.php?return=' . e(rawurlencode(m3d_post_url($p) . '#poll')) . '">Log In with Patreon to Vote</a></div>';
        } elseif (!empty($who['preview'])) {
            $h .= '<div class="pl-foot"><p class="pl-note">Voting is off while previewing as a patron.</p></div>';
        } elseif (empty($who['access'])) {
            $join = social_url('patreon');
            $h .= '<div class="pl-foot"><p class="pl-note">Patrons on any paid tier can vote.' . $note . '</p>' . ($join !== '' ? '<a class="btn btn-primary" href="' . e($join) . '" rel="noopener" target="_blank">See the Tiers</a>' : '') . '</div>';
        }
    } elseif (!$live) {
        $h .= '<div class="pl-foot"><p class="pl-note">Voting opens when the post is published.</p></div>';
    }
    return $h . '</section>';
}

