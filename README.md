# Model catalog website

A small, self-hosted website for 3D creators: a catalogue of **models** and **accessories** (outfits, hair, props…)
with pictures, changelogs and documentation, where downloads are unlocked by a **Patreon** membership.
Everything is edited from a built-in **admin panel**.

Plain PHP 8.1+, no framework, no build step and no database: the content lives in JSON files. It runs on ordinary
shared hosting (made on Hostinger, works on any Apache / LiteSpeed host that reads `.htaccess`).

For AI coding sessions working on the code: read `CLAUDE.md`.

## What it does

- **Catalogue**: homepage with a featured slideshow, "Recently updated", search and filters (franchise, status,
  generation), pages of 25; accessory catalogue with types (outfit, hair, necklace…) linked to the models they fit.
- **Model and accessory pages**: gallery with full-screen viewer, facts, features, notes, changelog with versions
  (alpha / beta), add-ons required, likes, download counts, major versions of the same model linked together.
- **Patreon downloads**: patrons log in with Patreon; any paid tier (or the extra rules you set) unlocks the downloads.
  The real download addresses are never shown in the page: buttons go through `/get/…`, which checks the patron first.
  Optional: minimum months or total support per item, a waiting time before each download, limits like
  "1 new model per week", exceptions for testers, "My account" page with each patron's downloads and updates.
- **Visibility**: draft, password-protected, blurred pictures (for everyone, or only for non-patrons).
- **Docs and FAQ** with a simple text format (headings, lists, boxes, tables, code, pictures).
- **Admin panel**: editors for everything, image library (uploads become WebP in two sizes, cropping), download log
  and statistics, backups and restore, updates, maintenance mode, site notice, preview as a patron.
- **Your identity**: site name, tagline, logo (also the browser icon) and accent colour, social links, homepage texts.
- **Privacy**: no third-party requests from the pages (fonts are self-hosted), only strictly necessary cookies,
  a ready-made privacy policy page, an 18+ confirmation on every page.
- Optional extras, off by default: a page about the MustardUI Blender add-on, an importer for SmutBase projects.

## Folders

```
public_html/        the website (upload its contents to your site folder)
  index.php …       public pages
  admin/            the admin panel (owner only)
  auth/             Patreon login, download gate, likes
  app/              shared code (never reachable from the web)
  assets/           styles, scripts, fonts
  media/            uploaded images (created by the admin panel)
private/            settings, data, logs and backups: put it NEXT TO the site folder, not inside it
  config.example.php  copy to config.php and fill it in
  data/             models.json, outfits.json, settings.json, faq.json, docs.json… (written by the admin)
local-test.php      router for testing on your computer (never upload it)
make_update.py      builds an update zip for the admin's Update page
```

## Installing on a host

1. **Requirements**: PHP 8.1 or newer with the `gd`, `curl`, `zip` and `mbstring` extensions (usually on by default),
   HTTPS, and `.htaccess` support (Apache or LiteSpeed).
2. **Upload** the contents of `public_html/` into your site folder (on Hostinger: `public_html`), and the `private/`
   folder **next to** it, e.g. `domains/example.com/public_html` and `domains/example.com/private`. (`private/` also
   works one level higher, or inside the site folder, where its own `.htaccess` blocks web access.)
3. **Open your site.** It shows **Set up your site** and guides you through four steps:
   1. *Your server*: open `private/setup-code.txt` with your host's file manager and paste the code (so nobody else can take
      over a freshly installed site).
   2. *Patreon*: create a client on <https://www.patreon.com/portal/registration/register-clients>, enter the redirect address
      the page shows (`https://<your site>/auth/callback.php`), and paste its **Client ID** and **Client Secret**.
   3. *Log in*: log in with your creator account. It becomes the owner (admin) and your campaign is read from it.
   4. *The basics*: site name, tagline, colour, who runs the site (privacy page) and the support email.

   The page creates `private/config.php` from the example by itself; afterwards everything is editable in Admin → Settings.

**Without the setup page** (or to change what it can't): in `private/` copy `config.example.php` to `config.php` and fill it
in. To **find your campaign id and your Patreon user id** by hand, set `'debug' => true` in `config.php`, log in with Patreon:
the page shows *Your Patreon user id* and the *Campaign ids*; put them into `campaign_id` and `owner_user_ids` (the admins),
then set `'debug' => false` again.

### First steps in the admin

- **Settings → Site identity**: name, tagline, logo and colour. **Social links**: the footer buttons (Patreon, Discord…).
- **Settings → Homepage section**: the "how to get the downloads" boxes, if you want them.
- **Models / Accessories**: add your items, their pictures and download links (each link is *Patrons only* or *Public*).
- **Docs** and **FAQ**: they appear in the menu as soon as they have content.
- **Access**: download waiting time, unlock limits and exceptions.

## Updating

Changes reach the server through the admin, without FTP: **Admin → Settings → Update** lists the new versions of the
chosen update channel with what changed, and installs one when you press **Install**. Nothing installs by itself.

- **Stable** (default): the versions published for everyone, from the public repository
  [Mustard2/MustardWebsite](https://github.com/Mustard2/MustardWebsite). No key needed.
- **Development**: every change as soon as it is merged, from the private repository. Needs a GitHub key: a
  fine-grained token with access to that repository only and *Contents: Read-only*, pasted in the Update Channel box.

How the versions are made (GitHub Actions, in the private repository):

- **Development release** runs on every merge to `main` that changes `public_html/`: it builds the update zip
  (`make_update.py`, version `YYYY.MM.DD-N`) and publishes it as a release, with the `## Changelog` bullets of the
  merged pull requests as its notes.
- **Publish to Stable** runs only when started by hand (Actions → Publish to Stable → Run workflow, optionally with a
  version): it copies that exact zip to the public repository with the code of that version, `CHANGELOG.md` and one
  list of everything that changed since the last Stable version. It needs the secret `STABLE_REPO_TOKEN`: a
  fine-grained token with *Contents: Read and write* on the public repository only.

A zip can still be installed by hand (**Update from File** on the same page), e.g. one built on your computer:
```bash
python3 make_update.py      # bumps the version, writes dist/site-update-<version>.zip
```

The updater only writes code. It never touches `media/` (your images) or `private/` (data, settings, logs), it saves the
current code first (**Admin → Backups**, to roll back), and it never deletes files. **Admin → Backups** also makes full
backups (content, counts, log, optionally the images) that can be downloaded and restored.

## Testing on your computer

You need PHP 8.1+ with the extensions above (check with `php -v` and `php -m`). No web server or database:
PHP's built-in server is enough.

1. Get the code:
   ```bash
   git clone <this repository> site && cd site
   ```
2. Start the site from the project folder (the first request creates `private/config.php` from the example):
   ```bash
   php -S localhost:8000 -t public_html local-test.php
   ```
   and open <http://localhost:8000>. `local-test.php` does what `.htaccess` does on the server (pretty addresses,
   blocked folders). Stop it with Ctrl+C. A fresh copy opens on the **Set up your site** page.
3. The Patreon login can't work on your computer, so `local-test.php` adds a **local login** (it refuses to run anywhere
   else than PHP's test server):

   | Address | You are |
   |---|---|
   | <http://localhost:8000/__local/login?as=owner> | the owner: the admin opens (on a fresh copy you become the owner and the setup's last step opens) |
   | <http://localhost:8000/__local/login?as=patron> | a paying patron (6 months, 30 in total support) |
   | <http://localhost:8000/__local/login?as=free> | logged in without a paid tier (downloads refused) |
   | <http://localhost:8000/__local/login?as=logout> | a visitor again |

   It also accepts the 18+ question for you. As the owner you can also try every patron situation with **Preview** in the
   admin header (tiers, months, downloads, updates).
4. Everything else works locally: adding models, uploading and cropping images, docs, FAQ, settings, backups and the
   download pages (download links redirect to whatever address you entered). Your test data stays in `private/data/`
   and `public_html/media/`, which are not part of updates.

Before shipping a change, check:
- `python3 make_update.py` runs `php -l` on every PHP file and stops on an error;
- pages at 390 px wide (phone) have no sideways scrolling;
- the page source has no external addresses (fonts, scripts) and no patron download address;
- the PHP server's output in the terminal shows no warnings or errors while you click around.

## Privacy (GDPR)

`/privacy/` describes the Patreon login, the cookies, the download log and the hosting; fill in `legal` in `config.php`
(the page shows a placeholder until you do). Download log lines older than `log_retention_days` (365 by default) are
deleted automatically. If you add a service, a cookie or other processing of personal data, update `privacy.php` too.

## License

Copyright (C) 2026 Mustard3D

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later
version (**GPL-3.0-or-later**).

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program (the `LICENSE` file).
If not, see <https://www.gnu.org/licenses/>.

The fonts in `public_html/assets/fonts/` (Inter, Bricolage Grotesque) are under the SIL Open Font License; the social
network icons come from Simple Icons (CC0).
