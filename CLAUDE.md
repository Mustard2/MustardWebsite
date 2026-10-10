# CLAUDE.md — Mustard3D website

Guidance for AI coding sessions working on this repository. Read this first.

## What this is

The website of Mustard3D (mustard3d.eu): a catalog of Blender character **models** and **outfits**,
with Patreon-gated downloads and an owner-only **admin panel**. Plain PHP 8.1+, no framework,
no build step, no database (JSON files). Hosted on **Hostinger** shared hosting (LiteSpeed,
`.htaccess`), behind **Cloudflare** (proxied DNS). A test copy lives at `v2.mustard3d.eu`.

The owner is not a web developer: changes must stay simple to deploy (see "Shipping changes").

## Layout

```
public_html/                 → the site folder on the server (deployed as a whole)
  index.php                  homepage: featured slideshow (glow `.hero-glow`: soft box-shadows in the colours around the picture's edges and corners, `glow_colors()` in images.php; no blur filter or mask, which some browsers draw blocky), catalog "Character Catalogue" (search + Filters button opening button rows `.chip[data-pick]`: franchise, status, sort one at a time, generation several at once (All = none);
                             generation "Other" = models without one; 25 cards per page, `catalogPager()` in site.js: ‹ 1 2 … 9 › under the grid `[data-pager]`, `?page=`), "Recently Updated" (same title style as the catalogue, `.cat-title`) between the slideshow and the catalog (`recent_items()` / `recent_card()`:
                             latest changelog date in the last 90 days, 4 at most, New = one version only), how to get the models (`#access`, hidden for patrons who can download; texts, buttons and on/off from Admin → Settings → Homepage section,
                             `home_plans()` / settings.json → plans; hidden until set); the hero line of a featured model without a description = the tagline
  model.php                  /models/<slug>/
  outfits.php, outfit.php    /accessories/ and /accessories/<slug>/ ("Accessories" on the site: outfits, hair styles, necklaces…; the kind stays
                             `outfits` in the code, data files, log, likes, counters and settings; old /outfits/ addresses 301 to /accessories/,
                             `m3d_item_url($kind, $slug)` builds page addresses;
                             catalog filter rows: sort, Type (only with two types or more, `?type=`), unlocked, model; 25 per page like the homepage, `[data-opager]`)
  privacy.php                /privacy/ (GDPR policy, reads config 'legal')
  mustardui.php              /mustardui/ (the add-on: features, wiki User + Developer guide, GitHub issues),
                             only when Admin → Settings → MustardUI is on (`m3d_mustardui_on()`, settings.json → mustardui.on; off by default): else 404,
                             and no menu / footer item, sitemap entry or address suggestion
  contacts.php               /contacts/ (Discord, Patreon, X cards when set in Social links; the MustardUI bugs card only while the MustardUI page is on;
                             website support email from config 'support_email', else settings, else no email box)
  faq.php                    /faq/ (topics → questions; answers in the simple text format, see md_html())
  docs.php                   /docs/ and /docs/<slug>/ (page list left, page, "On this page" right; first non-draft page = /docs/); the links under the
                             page list are `docs_links()` (Admin → Docs → Links under the page list, settings.json → docs_links; default FAQ · Contacts),
                             printed by `link_html()`: links to other websites get rel=noopener and a ↗ (`.ext`) by themselves
  patreon-required.php       explains why a download was refused (reason=requirements: needed vs. the patron's values)
  account.php                /account/ "My account": one header card (eyebrow "My account", the patron's name as the h1, status badge + Patreon ID, Refresh / Log out), downloads row (access, models/outfits left via m3d_downloads_left(),
                             "Unlimited" without rules), then the Unlock limits card (`.acc-limits`, right under the downloads row: one row per rule that is on, status column ("0/1 used", "Doesn’t apply", "No limit") + the rule in short (`m3d_rule_text($r, true)`: "1 model / week · under 3 months"), "Next: …" under it), membership data, then the collapsible My Models (open by default) (every item
                             downloaded, from `m3d_patron_log()`, last downloaded first; items removed or that this visitor can't open (draft, password not entered: `m3d_can_view()`) keep only their name (`.acc-gone`): no chip, version, update or button; blurred items are shown in full with their picture from `card_pic()` (blurred unless this visitor may see it); name with a small picture
                             `$cell()` / `.acc-item` and under it a Model chip, or the accessory's type ("Outfit", "Hair"…), `.acc-kind` (the header's
                             person / hanger icon) + the version of the last download; Unlocked (first download) column, then an untitled column with the update tag ("↑ Update · V4", `.upd-tag`, right-aligned next to the Download button, `.c-upd`; phones: the column hides and "↑ V4" replaces the version under the name; a button `[data-upd-toggle]` that opens the row below `.upd-row` with the versions since the download, `m3d_newer_versions()` rendered by `changelog_items()` (view.php, shared with `changelog_block()`)), every row the same height; filter chips All / Models / Accessories (`data-models` / `data-accessories`, only when there are both) / Available (has a Download button, `data-avail`) / Updates (`data-upd`), `[data-acc-filters]` in site.js; a chip only when its list differs from All and is not empty, no chips without one; Download button via /get/, or "Not eligible");
                             clicking the "left" boxes scrolls to and lights up #limits (arriving on the page never animates)), likes
  age.php, 404.php
  favicon.ico/.svg, apple-touch-icon.png   NOT shipped: linked (root_file(), ?v=) only when present at the site root and no logo was uploaded
  robots.php                 /robots.txt (rewritten): crawlers may read public pages; admin, auth, get, account kept out; points to the sitemap of this host
  sitemap.php                /sitemap.xml: fixed pages, public/blurred models and outfits (lastmod = latest changelog date), non-draft docs
  auth/                      Patreon OAuth: login.php, callback.php, logout.php, status.php, get.php (download gate + counter),
                             like.php (model and outfit likes: GET state + csrf, POST toggle, kind=models|outfits; paying patrons only)
  admin/                     admin panel (owner only); files starting with "_" are includes (denied by .htaccess)
  app/                       shared code, never web-reachable (.htaccess deny + defined('M3D_PRIVATE') guard)
    _init.php                finds private/, loads everything (every entry point requires this)
    bootstrap.php            config, session, Patreon rule, data storage, CSRF, download log
    view.php                 layout, SITE links, cards, downloads box, changelog, helpers
    images.php               upload processing (WebP large + "-sm" small)
    social.php               SOCIAL_TYPES: footer link types (label, brand color, SVG path; icons from Simple Icons, CC0)
    version.php              const M3D_VERSION: bumped by make_update.py; marks a valid update zip
  assets/                    site.css, site.js, admin.css, admin.js, fonts/ (self-hosted)
  media/                     uploaded images (USER CONTENT — never shipped, never overwritten)
private/                     → secrets + data, outside public_html (or inside, protected by its .htaccess)
  config.php                 NOT in git. Patreon client, owner_user_ids (= admins), legal, support_email, retention…
  patreon.php                NOT in git. Admin → Settings → Patreon: client_id / client_secret / redirect_uri / campaign_id saved there
                             (`m3d_patreon_saved()` merged over config.php in bootstrap.php, `m3d_patreon_save()`; only values that differ
                             from config.php; empty = file deleted). A PHP file outside data/ on purpose: never readable over the web and never in
                             backups or the data-file history (backups leave config.php out too). The secret is never printed back into the form.
  config.example.php         template for config.php: add new config keys here with a default
  data/models.json           models (edited via admin)
  data/outfits.json          outfits (edited via admin)
  data/faq.json              FAQ (Admin → FAQ; none until written: the menus then leave the FAQ out, `m3d_has_faq()`)
  data/docs.json             docs (Admin → Docs; same idea, `m3d_has_docs()`)
  data/settings.json         Admin → Settings values (old `redirects` / `adopted` entries are dropped by `m3d_save_settings()`; `social`: footer links, `currency`: campaign currency, also learned at login,
                             `maintenance`: {on, message, since}, `notice`: {on, type, text, where, until, closable},
                             `start`: {models|outfits: {links: [{label, url}], note?}}: the "Getting started" box, see start_box(),
                             `outfits_intro`: text under the Accessories page title (full text format via `md_html()`, 5000 chars, with the format help), edited in Admin → Accessories; see outfits_intro(),
                             `downloads`: {wait, rules}: download waiting page + limit rules, edited in Admin → Access; see "Rules",
                             `identity`: {name, highlight, tagline, color, logo, icon}: Admin → Settings → Site identity, only the keys that differ from `IDENTITY_DEFAULTS`; see "Site identity")
  data/stats.json            all-time download counters per model/outfit (patrons/public), seeded once from the log
  data/exceptions.json       Admin → Access: {people: [{id (Patreon user id), name, note, tier, extra, limits, added}]} (missing = nobody)
  data/likes.json            likes: {models: {slug: [code…]}, outfits: {…}}, code = HMAC(Patreon id, data/.likes-key), no raw ids
  backups/                   managed in Admin → Backups (admin/backups.php, logic in admin/_backup.php):
                             full-<date>-<kind>[-note].zip  full backup: manifest.json, code/, data/, logs/, media/ (optional);
                                                           kind = manual | before-restore | uploaded | auto; no config.php; only "auto" ones are pruned (newest 8):
                                                           `admin_auto_backup()` (admin_start) makes one without images when the newest full backup is > 7 days old
                             site-code-<date>-v<ver>.zip    code before an update or a code restore (last 5)
                             <name>-<date>-<rand>.json      a data file before each save (m3d_write_json, last 50 each)
  logs/downloads.csv         date, patreon id, name, item (slug or "outfit:<slug>"), link label, version downloaded (`m3d_version_label()`: "Beta 2", "V4"; missing on old lines)
  sessions/                  PHP session files; a login lasts `m3d_session_lifetime()` from `started` (set in auth/callback.php): Admin → Settings →
                             Stay logged in (`settings.json` → `login_days`), else config `session_lifetime`; bootstrap ends older sessions on their next
                             request, and `m3d_session_cleanup()` deletes expired files itself (once a day, from `m3d_start_session()`; forced on the
                             Settings page), so it doesn't depend on the host's PHP clean-up
  cache/                     `img_cache()` (images.php): glow colours and blurred copies worked out once per picture file (key = path + modified time); safe to delete
local-test.php               router for `php -S` (mirrors .htaccess)
make_update.py               builds dist/site-update-<version>.zip
.github/workflows/update-zip.yml   same thing on GitHub (manual "Run workflow")
```

`app/_init.php` locates `private` (in messages use `basename(M3D_PRIVATE)`) next to the site folder, one level up (subdomain
folder), or inside it. Don't hardcode paths: use `M3D_PRIVATE` and `M3D_SITE`.

## Data model (JSON, keys optional unless noted)

Model: `slug`*, `name`*, `franchise`*, `status`* (released (shown as “Public”) | released-patreon | preview |
patreon-only | in-development), `order`, `featured`, `generation`, `game`, `note`, `description`,
`majorVersion` (int: models with the same name and one are versions of each other, `m3d_model_versions()`: older pages show "A newer version…",
`.newer-note`, every version page lists the others under "Other versions", cards get a `v2` tag; the "Major version" fact shows the number then a green pill link "↑ Newer: v3" (`.major-newer`, only the pill is the link) or a grey "✓ Newest" (`.major-newest`)), `thumb`, `cover`, `gallery` [url | {src, caption}], `blender`,
`features` [], `notes` [text | {text, image}], `links` [link], `addons` [{label, url}] (models and outfits: "Add-ons required" at the bottom of the
download box, public links, a site page like /mustardui/ or https; `clean_addons()`; `m3d_addons()`; none when not set), `changelogNote`,
`changelog` [{version*, date, highlight, stage (alpha | beta; absent = standard, `m3d_stage()`, badge `stage_badge()` with α/β icons in the
changelog (its timeline dot too: `log-alpha` / `log-beta`), next to "Latest version" and on catalog cards (latest version)), notes [], changes [], image}], `futureIdeas` [],
`knownIssues` [], `extra` [{title, groups [{intro, items []}]}], `credits` [].

Models and outfits may also have `visibility` (absent = public | `blurred` | `blurred-patrons` | `password` | `draft`), `passwordHash`
(`password_hash()`, kept when switching visibility; never sent to the editor, which only gets `hasPassword`) and `unlisted`
(password items only: not in the catalog). See "Visibility" below.

Models and outfits may also have `requireMonths` (int) and `requireTotalCents` (int, campaign currency): extra download
requirements, see below. Absent/0 = none: any paid tier downloads, exactly as before.
`requireMode: "or"` (only stored when both are set) = either requirement is enough; absent = both needed (`m3d_requirements()['mode']`).
`noLimits: true` = not counted in the download limits (never counted, never refused; `m3d_free_items()`).

Outfit (an accessory): `slug`*, `name`*, `type` (free text: Outfit, Hair, Necklace…; absent = Outfit, `m3d_accessory_type()`; the editor suggests
`M3D_ACCESSORY_TYPES` plus every type in use; on cards its icon sits on the picture (`.type-tag`, top-left in `.cover-tags`, with Password / Teaser next to it) and the type above the name; also the page's eyebrow, in My Models and the admin list, each with its icon: `accessory_icon($type)` /
`accessory_icon_paths()` in view.php, `ACCESSORY_ICONS` matched on words (Blender-like logo = blender, nodes, compositor, shaders, add-ons, scripts, presets; T-shirt = outfit, comb = hair, pendant, earrings, glasses, shoe,
hat, bag), anything else = the hanger `ACCESSORY_ICON_GENERIC`, also used for all accessories together, e.g. the header unlock counter), `order`, `description`, `models` [model slugs],
`images` [url | {src, caption}] (first = preview), `links` [link], `changelogNote`, `changelog`.

Link: `{label, url, access: "patrons" | "public"}`.

The **latest version** of a model/outfit is not a field: it is the top changelog entry (newest first), read with
`m3d_latest_version($item)` everywhere it is shown. Old entries may still carry `version`/`versionDate`; they are
only a fallback when there is no changelog and are dropped on the next save.

FAQ: `{intro, sections: [{title, items: [{q, a}]}]}`.
Docs: `{sections: [{title, pages: [{slug, title, summary, body, draft}]}]}`; slugs unique across all pages (`clean_docs()`).
`draft: true` pages exist only for admins (docs.php: 404, not in the list/search/prev-next/home cards; "Draft" pill + banner for
admins). The docs home (/docs/) is the first non-draft page: `m3d_docs_home()`; `m3d_doc_pages(true)` = without drafts.

FAQ answers and docs bodies use the simple text format rendered by `md_html($text, ['docs' => bool], $toc)`:
blank line = block; `## `/`### ` headings (docs: h2/h3 with ids collected in `$toc`; FAQ: h3/h4); `- ` bullets and
`1. ` numbered lists (the first number is kept as `start`, so steps continue after a picture); lines indented by two
spaces continue the item above, indented `- ` lines make a sub-list; `> ` lines = box (`md_callout()`): first line
`[!NOTE|TIP|IMPORTANT|WARNING|CAUTION] optional title` picks type and title (GitHub syntax; plain `> ` = untitled note;
old `> **Warning:**` still gets the warning color), the box content is rendered again with `md_html()` (a lone `>` line = paragraph break);
`| a | b |` tables (first row = header, `|---|` ignored); fenced ``` code (with a language → numbered lines);
`![caption](url)` alone on a line = picture, several such lines together = gallery; inline `[text](url)`, `**bold**`,
`*italic*`, `` `code` ``. Everything is escaped before formatting: never output raw HTML from data.
The same format is used on model/outfit pages: multi-line texts (description, notes, changelog notes) through `md_html()`
(`'noImages' => true` for items whose pictures are hidden), single lines (features, changes, ideas, issues, credits, extra items,
changelogNote) through `md_inline()`. The "How to format…" help in the admin is `format_help()` (_admin.php): its examples
are rendered by `md_html()` itself, so add new syntax to `FORMAT_EXAMPLES` when you add it to the format.
**The package is general** (no site's own content in the code): FAQ, docs, social links, the Getting started box, add-ons and the identity all live in the site's data (`private/data/`), empty or neutral on a fresh install. Never ship content as a default in the code;
links and buttons for a network that isn't set are left out (`social_url()` returns '').

There are no redirects for old addresses (the old website is not supported): unknown addresses show the 404 page.

All writes go through `m3d_save_items($kind, $items)` / `m3d_write_json()` (backup + atomic rename).
All admin input goes through `clean_model()` / `clean_outfit()` in `admin/_admin.php`: extend these
when adding fields, or the field will be dropped on save.

## Rules that must not break

- **Patron download URLs are never printed in HTML.** Pages link to `/get/<slug>/<n>/` or
  `/get/outfit/<slug>/<n>/`; `auth/get.php` checks the patron and redirects. Public links go through `/get/`
  too (no login, redirect at once) so `m3d_count_download()` can count them.
- **Who can download** (`m3d_evaluate_identity`): active patron of `campaign_id` with at least one tier
  with `amount_cents > 0`. Custom pledges without a tier, free members, declined payments → refused.
  Ids in `owner_user_ids` always pass and are the only admins.
- **Exceptions** (Admin → Access, `admin/users.php`, data/exceptions.json): `m3d_patron()` applies them to the session patron on
  every read (`m3d_apply_exception()`), so changes count at once. `tier` = access without a paid tier (`reason` becomes
  `exception`, Patreon's answer kept in `denied_reason`); `extra` = `m3d_check_requirements()` passes every row
  (`status.php` → `reqPass`); `limits` = the download limits don't apply. Read the patron only through `m3d_patron()`, never `$_SESSION['patron']` directly.
  People find their Patreon user ID on /account/; privacy.php mentions the stored ID/name/note.
- **Extra requirements** (`m3d_check_requirements()`) are checked in `auth/get.php` only AFTER that rule passes: they can
  never grant access, only add conditions. Data comes from the Patreon member at login (`pledge_relationship_start` →
  months, `campaign_lifetime_support_cents` → total, campaign `currency`), kept in the session only. A session without
  `total_cents` (from before this feature) is sent to log in again for items that have requirements.
- **Download wait and limits** (Admin → Access → Downloads for everyone; `settings.json` → `downloads`: `{wait, rules}`, read with
  `m3d_dl_settings()`; wait defaults to 10 s). `auth/get.php` shows `download_wait_page()` (view.php, countdown in site.js) for every
  link, public too, then redirects only when `?t=` (`m3d_dl_token()`: issue time + HMAC with `data/.download-key`) is old enough
  (`m3d_dl_token_wait()`): server-enforced, no cookie, no session. Rules `{id, on, max, what: models|outfits|all, per: day|week|month,
  from, to}` (months as a patron: >= from, < to; `on` false = paused, kept) are checked by `m3d_check_limits()` for patron links
  only, after the requirements, counting the items FIRST downloaded inside the rolling period, from the download log
  (`m3d_patron_log()`); an item downloaded before is always free again (`owned`; the box still lists the limits, without red); items with
  `noLimits` are skipped when counting and always allowed (`free`). The download box shows the patron's quota in one line
  ("✓ 1/1 Unlock(s) left" from the tightest rule; limits are worded as "unlocks" (first downloads), `m3d_rule_text()`, or Unlocked / Free, styled like the `.req-list` lines; every line green when met, red when not (also with "or" and when a requirement blocks: `quota.blocked`); details in the hover title; "Check your status" → /account/ sits next to the box title, `[data-dl-status]`, shown to anyone logged in, never on `noLimits` items) (`.dl-quota[data-dl-quota]`, filled by site.js from `/auth/status.php?item=<kind>:<slug>` → `quota`, only when rules apply). Refusal → `/patreon-required/?reason=limit`. A download that will use one of the patron's downloads (rules apply, not exempt,
  not `free`, not `owned`) first asks on the waiting page ("This uses one of your unlocks… Unlock this model?", left now / after this); "Yes, unlock" adds `c=1` to the
  link and starts the countdown (works with wait 0 too). The header shows the unlocks left next to the name (person icon + number for models, shirt icon + number for outfits,
  `.dl-left` / `.dl-k`; the name is a button `.btn-who` to /account/, from `/auth/status.php` → `left`: the tightest rule per kind), only when rules apply. Catalogs (homepage, Outfits)
  ask `/auth/status.php?owned=1` → `left.owned` (what the patron downloaded) + `left.free` (noLimits items) and site.js adds a green check (`.owned-check`) to
  those cards plus the "Unlocked" filter (homepage row `[data-owned-row]`, Outfits `[data-owned-row]` too), also only when rules apply. Owners and exceptions with
  `limits` are exempt. /account/ lists every rule that is on, with the patron's count and next free slot.
- **Updates** (`m3d_updates_for()` → `m3d_newer_versions()`, dates via `m3d_changelog_ts()`): items a patron downloaded where the version of their last download
  (log column 6) is found below the top of the changelog; old log lines without a version (or a version renamed since) fall back to "the latest
  version is dated after the day of their last download". Public and blurred items only (removed, draft or password ones never show updates). /account/ shows the downloaded version under the name; Admin → Downloads has a Version column (and CSV). /account/ → My Models tags them ("↑ Update · V4", "Update · Beta 2" when the release has a stage, listed first, count in the summary); `/auth/status.php` →
  `updates` (codes "kind:slug@version", `m3d_update_codes()`) puts a dot on the header's account button (`.who-dot`) while one isn't in
  localStorage `m3d_seen_updates`; opening My account stores the current codes (`[data-updates-seen]`), so the dot goes until a newer version.
- Compact cards (`model_fit_card()`, `recent_card()`) take their picture from `card_pic()` (blurred copy when hidden).
- **Model cards** (`model_card()`): the body is always franchise / name / status (+ likes on the right); the extra tags (v2, α/β of the latest
  version as the letter only: `stage_badge(..., iconOnly: true)`, Password / Teaser) go on the picture next to Gen (`cover(..., $tags)` → `.cover-tags`), so every card has the same rows.
- **Link previews**: `og:site_name` (Mustard3D, the small line above the title), `og:title` = the page title without the site name,
  `theme-color` mustard (Discord's side bar). `page_image($url)` before `layout_start()` adds `og:image` (absolute via `abs_url()`); model/outfit pages call it only
  for `visibility` public items, so blurred, password and draft pictures never leak into previews.
- **Escape everything** printed into HTML with `e()`. Admin POSTs need the CSRF token
  (`admin_post_guard()` for JSON, `m3d_check_csrf($_POST['csrf'])` for forms).
- **No third-party requests from pages** (GDPR, Germany): no Google Fonts/CDNs/analytics/embeds.
  Fonts are in `assets/fonts/`. If you add any processing of personal data or a new cookie or service,
  update `privacy.php` in the same change.
- **Cookies**: visitors get none except `m3d_age_ok` (and `m3d_unlock`, only after typing a model/outfit password;
  plus localStorage `m3d_notice`, only after closing the site notice, and localStorage `m3d_seen_updates`, only for patrons who open
  My account while they have updates; all of them are in privacy.php, as are Cloudflare's own security cookies `__cf_bm` / `cf_clearance`; no consent banner is needed while every cookie stays strictly necessary). The session (`m3d_session`) starts only in `auth/login.php`
  (`m3d_start_session()`), or when the request already carries that cookie; never call `session_start()` elsewhere.
  Public pages send `Cache-Control: private, no-cache` so Cloudflare never stores them (the age gate changes the page).
- **18+ check**: `layout_start()` shows the age gate until cookie `m3d_age_ok` is set (via `/age.php`).
  Keep it on every public page (use `layout_start`/`layout_end`).
- **Uploads**: only through `admin/upload.php` → `img_make_versions()` (WebP `<name>.webp` ≤ 2560 px and
  `<name>-sm.webp` ≤ 800 px; animated GIFs untouched). Cropping: `cropImage()` in admin.js (editor image fields, auto-opened
  after uploading the catalog/main image at 4:5; Images library) → images.php action `crop`, which writes a NEW file
  `<name>-crop-<hex>` (never the same address: media is cached for a year) and with `replace` updates every use. Use `img_small($url)` for cards/thumbnails,
  the large URL for covers and the viewer (`cover(..., 'hero')` adds a srcset with the small version for phones and `fetchpriority=high` on the first one;
  public pages preload the four fonts); pictures inside texts (`md_images()`) list both files in `srcset` with their REAL widths and are shown as wide as the large file, up to the column: a wrong `w` value makes the browser shrink them on sharp screens). `media/.htaccess` blocks script execution there.
- **Never ship or overwrite user content**: `media/*/*`, `private/*`.
- Changelog highlights become badges (`highlight_badge()`): "textures" = New textures, unless negated ("textures are not changed" = Same textures);
  "MustardUI" = New MustardUI only while the MustardUI page is on.
- **Images from other websites** (old `wp-content/uploads`, image hosts) leak visitors' IPs and break when the
  old site goes: the Images page offers to copy them into `media/` (`external_images()` / `copy_external()`,
  which also rewrites addresses inside FAQ answers). Don't introduce new hotlinked images.

## Visibility (draft / password / blurred)

Helpers in bootstrap.php: `m3d_visibility()`, `m3d_can_view($kind, $item)` (drafts: admins only; password: admin or unlocked),
`m3d_listed()` (catalogs: no drafts, no unlisted password items), `m3d_listed_items($kind)`, `m3d_pics_hidden()` (blurred;
`blurred-patrons` unless `m3d_sees_patron_pics()`: paying patron or admin, with `patron_pics_note()` under the picture; or
password still locked). **Every public list uses `m3d_listed_items()`** (homepage, outfits, related, `m3d_outfits_for()`),
pages call `item_gate($kind, $item)` (view.php: 404 for drafts, password form + check), `auth/get.php` and `auth/like.php`
check `m3d_can_view()`. Admins see everything, with `visibility_banner()` on the page: its "Publish" (drafts) / "Make public" button (password asks first)
posts to `admin/publish.php` (CSRF; drops `visibility` + `unlisted`, keeps the password hash) and comes back with `?published=1` (green "Published" line).
In the editor, Visibility is one bar of icon buttons (`visBar()` in admin.js, radio inputs; `.vis-seg` = a segmented control: padded bar, the chosen option a rounded tile inside it, so no corner clipping; 3 columns on phones) with the hint under it.
- Password: the form posts to the page itself; `m3d_unlock()` verifies with `password_verify()` and sets cookie `m3d_unlock`
  (30 days, listed in privacy.php): comma-separated `m|o.<slug>.<hmac>`, HMAC of kind+slug+hash with `data/.unlock-key`,
  so a new password locks out old cookies. Password items are never in the homepage slideshow.
- Blurred pictures never print the real image address: `blur_src()` (images.php) makes a 24 px JPEG `data:` copy from the
  small version (`cover(..., $blur)`, `.is-blurred` CSS scales + blurs it); external images become the placeholder.
  Note/changelog images and the gallery viewer are left out for blurred items.

## Preview as a patron (admins only)

The "Preview" button in the admin header, left of "View site" (`admin/patron-preview.php`, POST + CSRF) stores a made-up membership in the admin's own session:
`$_SESSION['preview'] = {tier: ok|no-tier|declined|not-patron (PREVIEW_TIERS), months, total_cents, dl_models, dl_outfits, updates, as}`
(dl_*: made-up downloads of other items a minute ago; updates: the first N listed items with a dated latest version count as downloaded
long before it, at their third newest version `m3d_preview_got()`, so they show as updates and unlocked; both added by `m3d_patron_history()`, which limits, updates and the Unlocked checks read;
My account adds the simulated ones to My Models, their "what's new" always two versions: `m3d_preview_versions()`, made-up "Example" ones where the changelog has fewer). While it is on,
`m3d_patron()` returns `m3d_preview_patron()` instead of the admin (`preview: true`; `id` = `as`, a real Patreon ID whose download
history and Users exception then apply, or `'preview'`), so pages, the download box, requirements, limits and /account/ behave as for
that patron. `m3d_is_admin()` reads the real login (`$_SESSION['patron']`), so admin pages, drafts and edit buttons stay; patron-only
pictures follow the preview. `preview_bar()` (view.php, printed by `layout_start()`) is the bar on top to change it or stop.
Downloads stop at `preview_download_page()` (nothing downloaded, logged or counted); likes are refused.

## Maintenance mode

Admin → Settings → Maintenance mode (`settings.json` → `maintenance`). `m3d_maintenance()` / `m3d_maintenance_blocks()`
(bootstrap.php); `maintenance_gate()` (view.php) is called at the top of `layout_start()` and in `auth/get.php`, so every
public page and download shows the "Back soon" page (HTTP 503 + Retry-After, no age gate) to everyone except admins.
That page keeps the "Log in with Patreon" button so the owner can log in and pass. Open during maintenance: `auth/*`
(login, status, logout), `admin/*` (admins only anyway), `age.php`, and the legal pages in `MAINTENANCE_OPEN`
(/privacy/, /contacts/). `auth/like.php` answers 503. Admins see a red bar on public pages and a notice in the admin.
New public entry points that don't use `layout_start()` must call `maintenance_gate()` themselves.

## Site notice

Admin → Settings → Site notice (`settings.json` → `notice`). `m3d_notice()` (bootstrap.php) returns it while it is on, has
text and is not past `until` (Europe/Berlin day). `site_notice($path)` (view.php, printed by `layout_start()` under the header)
shows it on every page or only on `/` (`where`); `notice_html()` renders it (types in `NOTICE_TYPES`, colours/icons of the
text-format boxes, text through `md_inline()`). Closable notices: site.js stores the notice's code (md5 of type + text) in
localStorage `m3d_notice`, and a tiny inline script after the bar hides it before paint; a changed text shows again.

## First-use setup

`setup.php` (/setup/, routes in .htaccess + local-test.php): while the site has no admin (`m3d_setup_needed()`: `m3d_owner_ids()` = config
`owner_user_ids` + settings.json → `owners` is empty), `layout_start()` and `admin_require()` send every page there. A fresh install may
have only `private/config.example.php`: `_init.php` finds the folder by it and bootstrap.php copies it to config.php. Steps: 1. the code in
`private/setup-code.txt` (written on the first visit; proves server access; `$_SESSION['setup_ok']`), 2. Patreon Client ID / secret
(→ patreon.php; redirect address shown), 3. Log in with Patreon: during setup login.php asks for scope `identity campaigns` and callback.php
reads `data.relationships.campaign` (`m3d_identity_url(true)`), saves the account as `owners`, the campaign id when found, deletes the code
file, 4. the basics (identity, settings.json → `legal`, `support_email`; `setup_done`; "Skip for now"). Config placeholders count as empty
(`m3d_cfg_real()`). Legal details: `m3d_legal()` (settings, else config `legal`), support email `m3d_support_email()` (settings, else config);
both editable in Settings → Legal & contact.

## Site identity

Admin → Settings → Site identity (first section; `case 'identity'` in settings.php). `m3d_identity()` (view.php) = saved values over `IDENTITY_DEFAULTS`
("My catalog", no highlight, "3D character models", #e8b931, no logo). Never write a site name or tagline into pages: use `site_name()` /
`site_tagline()` (titles, og:site_name, descriptions, privacy.php, account, admin title, aria-labels). `brand()` = logo (`.brand-logo` img, none when not set; a `data:` SVG gets `.brand-svg`, laid out like a padded 32 px icon) + name
with its `highlight` (last occurrence) in `.brand-3d`. `head_identity()` prints theme-color + icons (uploaded: `/media/site/icon-<hex>-{32,192,180}.png`,
else favicon.ico/svg + apple-touch-icon.png at the site root when present) in every head (public, gates, admin); `accent_style()` prints `:root { --mustard; --mustard-ink }` AFTER the
stylesheets when the colour changed (`accent_ink()`: dark or white text by luminance, same rule in admin.js). So: **never hard-code the accent** in CSS;
use `var(--mustard)` and `color-mix(in srgb, var(--mustard) N%, transparent)` for tints (the warning yellow of callouts/notices is a separate, fixed colour).
The logo upload goes through `img_site_logo()` (images.php, GD → PNGs in media/site/, old ones deleted by `img_site_logo_delete()`); media/site/ is skipped
by the Images library and by both "unused images" clean-ups. The form has a live preview (`[data-identity-form]` in admin.js); "Reset to the original identity"
removes the key.

## Counters, likes, settings

- `m3d_json_update($name, fn($data) => [$newData, $result])` = locked read-modify-write for files many visitors
  change at once (stats, likes); it keeps no backups. Settings use `m3d_save_settings()` (normal backups).
- Likes: same rule as downloads (`$patron['access']`), for models and outfits (`m3d_likes($kind)`, `m3d_like_count($slug, $kind)`,
  `like_button($slug, $kind)`). Renaming a slug in the admin calls `m3d_rename_counts()`. The Outfits catalog sorts by
  order / name / likes / downloads (Filters button like the homepage: chip rows `data-pick` sort / owned / model, `?model=` and `?sort=`
  in the address; cards carry `data-likes` and `data-downloads`). Outfit pages list the models as square cards (`model_fit_card()`, "Optimized for").
- Admin shortcuts on public pages: `admin_edit_button($href, $label)` prints a hidden link that site.js reveals when
  `/auth/status.php` says `admin` (fetched with a `?t=` cache-buster, like `like.php`) (so cached pages never show it to others). Admin pages still check access themselves.
- Social links: `m3d_social()` (footer, `social_buttons()`), `social_url('discord')` everywhere a network link is
  needed; it returns '' for a network that isn't set, so wrap the link or button in a check.

## Admin panel map

Models (`admin/index.php`), Outfits (`outfits.php`) (both lists: row checkboxes + "Select" chips All · Drafts · None (with counts; the one matching the ticked rows is lit), and a sticky bar for the ticked rows:
delete, set visibility, set status (models); `admin_batch_bar()` in _admin.php, `[data-batch]` in admin.js, save.php action `batch`; password only for items that already have one; a search box in the Select row (`[data-batch-search]`, rows carry `data-search` from `admin_search_text()`: name, franchise / type, gen, status, address, visibility; every word must match, accents ignored; kept in `?q=`; Esc clears) hides rows, unticks the hidden ones, makes All / Drafts / the header box act on the shown rows only, and hides the drag handles while it is on (`.is-filtered`); the Links column "2 patron / 1 public" is `admin_links_summary()`: hovering, focusing or tapping it lists the link names (labels only, grouped Patrons / Public) in the same floating `.tip-pop` as the "?" help, via `data-tip-links`), editor for both (`edit.php?kind=…`, form built by
`admin.js` → `initEditor`), Import from SmutBase (`import.php`, button on Admin → Models only while Admin → Settings → SmutBase import is on, `m3d_smutbase_on()`,
settings.json → smutbase.on, off by default; import.php sends to that box when off; parsing in `_smutbase.php`: a profile address
(`sb_profile()`, follows "Next Page") or pages the admin adds (pasted: the copied page arrives as HTML on the clipboard, or uploaded saved pages; profile and/or
project pages; each paste joins the list) → a list marked New / Imported (a model whose link holds the project id, `sb_imported()`; an imported model
that is still a draft can be ticked again: the import replaces it in place (`replace`), never a public one; drafts named after SmutBase's bot check = "Broken draft",
ticked by default) / Same name (`sb_similar()`); SmutBase may answer the server with a bot check (Anubis "Making sure you're not a bot!", Cloudflare): `sb_describe()`
names it, `sb_project()` refuses any page without `h1#file_title` (`SbPageMissing` → `needPage`: the row asks for the project's page to be pasted, and a page of
another project is refused); each ticked project is one JSON request that makes a **draft** model:
title "[Franchise] Name - Version N" → franchise, name, majorVersion; the Description split by its headings: intro → description, "Content…/Features"
→ features, Instructions / How to use / Installation → notes ("Notes for this model", one per list item or paragraph), Credits, Changelog ("Version 3.1 (03/07/2022) - note" lines → entries with date, highlight, changes, notes; the version is stored as the bare number, "3.1", no V),
every other heading (Requirements too, which also gives the Blender version; Known issues and Future ideas / planned features as well) → one note each, its title in bold (split past 4900 characters); nothing goes to the sidebar (`extra`); Blender version from Requirements or the tags; og:image (a copied page has none: the sidebar `item_thumb` picture) → thumb, pictures → cover + gallery,
copied with `copy_external()` (app/images.php: two tries, 2 minutes each, the error names the cause (too big, too slow, cut off, HTTP code); originals up to 200 MB / 100 MP (previews 120 MB), `img_fits_memory()` checks (and raises) the memory limit first; originals that fail use the preview, and the row's warning names the picture); a "SmutBase" link (public or patrons); "What to import" ticks (`IMPORT_PARTS` in import.php: description, features, notes, changelog, pictures; unticked = `skip`, remembered in localStorage `m3d_import_skip`; name, franchise, Blender version, credits and the link always come; every imported text starts with a capital (list items, notes, each paragraph and list line of the description, changelog changes, notes and highlight: `sb_ucfirst()` / `sb_ucfirst_text()`, after formatting marks; code untouched));
validated by `clean_model()`; videos are not imported), FAQ (`faq.php` → `initFaqEditor`), Downloads log (`log.php`), Images library
(`images.php`), Downloads (`log.php`: all-time totals + likes (the sums as `.stats` tiles above the scrolling list), then the patron log (`.log-head`: Export CSV + reset everyone;
a patron filter shows `.log-patron` with that patron's totals and their own reset) with filters in the address: `q`, `item`,
`patron`, `link`, `from`, `to`, `sort`, `page`; times shown and filtered in Berlin time, stored in UTC; `?csv=1` exports the filtered rows, UTC; filtered by a patron, "Remove this patron's downloads & unlocks" (confirm, `m3d_log_remove_patron()`) deletes
that patron's log rows, so their unlocks start from zero; "Reset everyone's downloads & unlocks" (confirm) deletes the whole log,
like Settings → Clear download log; the all-time counters stay), Docs (`docs.php` → `initDocsEditor`: page tree, toolbar, preview via `preview.php`), Update (`update.php`: no item in the
admin menu, it's the last entry of the Settings menu `settings_menu()`, which also links the Settings sections),
Access (`users.php`, menu "Access": download wait and unlock limits as stacked cards, each rule a sentence row `.rule-row` (inputs tied to their form with `form=`),
then the exceptions table; see "Rules"), Backups (`backups.php`: create (+ upload) / download / restore / delete; ONE list "All backups" of the full backups and the code saved
before updates, newest first, tagged by origin (`BK_KINDS`) and content; every restore saves the current state first; then the change history of the data files),
Settings (`settings.php`, in four groups with `.set-group` headings, the Settings menu `settings_menu()` in the same order: **Your site** = Site identity,
Social links, Homepage section, Site notice; **Patreon & downloads** = Patreon (client keys → private/patreon.php, campaign currency); **Optional features** =
MustardUI, SmutBase import; **Maintenance** = Maintenance mode, Tools (PHP cache, how long logins last, end sessions, clear log, backups, unused images, temp
files), System; then Update). New boxes go into the group they belong to. All saves go through `admin/save.php` (`kind`: models | outfits | faq | docs). The admin menu switches
to a two-row header below 1150 px.

Help texts in the whole admin sit behind a "?" next to the label or section title: `lbl(label, hint)` / `tip(text)` in admin.js (editors),
`tip($text)` in _admin.php (PHP pages), shown in one floating `.tip-pop` on hover, focus or tap (admin.js). Use them instead of visible hint
lines, and only for things that aren't evident from the label (the Visibility hint, which follows the chosen option, stays visible next to the menu).
The model editor's Sidebar lists (future ideas, known issues, credits, other sections) show only once they have an item; until then one row
of "+" buttons (`optionalBlocks()`).
The model/outfit editor has a row of section buttons in its sticky header (`[data-ed-menu]` in edit.php, filled by `initEditor` from the
form's `fieldset.sec` legends): a click scrolls to the section below the header, the section in view is marked (`aria-current`).

Reordering: the model/outfit lists drag table rows (`[data-sortable]`, saved at once); inside the editors every repeatable
item (list lines, gallery cards, changelog versions, links, FAQ topics/questions, docs tree, social rows) gets a ⠿ handle
from `dragHandle()` / `wireDrag()` in admin.js (mouse, touch, arrow keys; listens on the window because moving the item
drops pointer capture). Use them for new repeatable fields, and never add ↑ ↓ / ← → move buttons next to a handle: the handle is the only
way to reorder (keyboard: focus it and use the arrow keys); docs pages change section with the page's Section menu.

The Images library's "Delete all unused" (`unused_images()` in images.php) removes images whose large AND small versions are
referenced nowhere (models, outfits, FAQ, docs: `usage_map()`).

The Images library has tabs (`?tab=models|outfits|docs|faq|other`, models by default) decided by where each image is used
(`usage_map()`: a model, an outfit, docs, FAQ; listed under that model/outfit), and for unused images by the media folder:
`docs`, `faq`, `outfit-<slug>`, a model slug, anything else = other. Keep uploads in those folders anyway.

Links editors (Getting started, docs links) share `admin_link_row()`, `admin_posted_links()` and `admin_site_pages_datalist()` (_admin.php) with the
`[data-rows-form]` script in admin.js.

The "Getting started" box of model and outfit pages is `start_box_html($kind)` (view.php): links and the line under them from
the Getting started box at the top of Admin → Models and Admin → Outfits (`admin_start_box()` / `admin_start_post()` in
_admin.php; `settings.json` → `start`); empty (no box) until set.

Images used inside long texts (FAQ, docs) are found through `m3d_texts()`; `m3d_texts_replace()` rewrites addresses
in them. Use these when a feature needs "every image in use" (Images library, Settings → unused images, external copy).

## Shipping changes (the update function)

The owner can only use Hostinger's File Manager, which extracts zips into a *new* folder, so code is
deployed through the admin panel instead:

1. Make the change in `public_html/` (and `private/config.example.php` for new config keys).
2. Run `python3 make_update.py` → bumps `app/version.php` (YYYY.MM.DD-N), runs `php -l` on every PHP
   file, writes `dist/site-update-<version>.zip` with the contents of `public_html/` minus uploads.
3. The owner installs it at **Admin → Settings → Update** (`admin/update.php`). The updater:
   - requires `app/bootstrap.php` and `app/version.php` at the zip root (otherwise: "not a site update");
   - rejects unsafe paths and file types outside `ALLOWED_EXT` (php, js, css, svg, images, fonts, txt, md, json, `.htaccess`);
     **if you add a new file type, add it both to `ALLOWED_EXT` in update.php and `ALLOWED` in make_update.py**;
   - skips `media/` (except `media/.htaccess`) and `private/`;
   - zips the current code to `private/backups/site-code-*.zip` first (rollback: Admin → Backups → Before updates);
   - the install logic (`update_path()`, `install_code()`, `backup_code()`) lives in `admin/_backup.php`, shared with the Backups tab;
   - safety: `code_lock()` (one install/restore at a time), `keep_running()` (ignore_user_abort + time limit, so closing or
     refreshing the page can't stop it halfway), `install_code()` unpacks everything as `<file>.upd-<token>` first (a failure
     changes nothing), then swaps all in keeping `<file>.upd-old-<token>` and rolls back on failure; the result page comes after
     a redirect (no resubmit on refresh); the code backup of a failed attempt is deleted; `form[data-busy]` disables the button;
   - never deletes files: removed/renamed files stay on the server (harmless; mention them if it matters).
4. Changes that need something outside `public_html` (new config keys, new data files) cannot be done by
   the updater: give the owner explicit File Manager steps, and make the code work with sensible defaults
   when the key/file is missing (e.g. `$M3D['x'] ?? default`, missing JSON = empty list).

Never change the update zip format (files at the zip root, as in `public_html/`), the
`M3D_VERSION` line format, or the location of `app/version.php` / `app/bootstrap.php`: older
installed updaters rely on them.

## Testing locally

```bash
cp private/config.example.php private/config.php
php -S localhost:8000 -t public_html local-test.php     # local-test.php mirrors the .htaccess routes
```
The real Patreon login only works on the server. `local-test.php` (only runs under PHP's built-in server, `PHP_SAPI === 'cli-server'`;
it is never shipped) has a local login: `/__local/login?as=owner|patron|free|logout` (owner = the first id in `owner_user_ids`; it also sets the
18+ cookie); README → "Testing on your computer" explains it for others. Throwaway `zz_*.php` helpers still work (excluded by make_update.py);
never commit them.
When adding routes, update **both** `public_html/.htaccess` and `local-test.php`.

Check before shipping: `php -l` (make_update.py does it), pages at 390 px wide have no horizontal
scroll, no `googleapis`/external asset URLs in the HTML, patron URLs absent from page source.

## License

GPL-3.0-or-later (`LICENSE` at the repository root, notice in README.md). Keep new files compatible with it.

## Conventions

- PHP: `declare(strict_types=1)` in app files, small functions, `m3d_` prefix for core helpers.
- JS: vanilla, no build; `site.js` parts are self-contained IIFEs that bail out when their elements are absent.
- CSS: tokens in `:root` (`--mustard`, `--card`, `--line`…); dark theme only; mobile-first checks at 390 px. `html { scrollbar-gutter: stable }` (site.css, also loaded by the admin) keeps the
  scrollbar's space on short pages too, so every page has the same width; keep it (Windows-style scrollbars otherwise shift the content ~7 px between pages).
- Number fields (`input[type=number]`): the browser's own ▲▼ arrows are hidden (site.css); `numStepper()` (site.js for public pages, admin.js for the admin,
  which also watches for fields the editors add later) wraps each in `.num-field` with two matching step buttons (hold = repeat; they send `input` + `change`).
  Nothing to do for new number fields: leave room on the right (the input gets `padding-right: 30px`).
- Wording on the site: English, short, friendly. The owner communicates in Italian or English.
