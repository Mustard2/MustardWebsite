<?php
// SmutBase import (Admin → Models → Import from SmutBase, admin/import.php): reads a SmutBase profile (the list of
// projects) and project pages, and turns a project into a draft model: name, franchise and major version from the
// title ("[Franchise] Name - Version 2"), the description split by its headings (features, requirements, credits,
// changelog, instructions as notes, everything else as one note each), pictures copied into media/, a "SmutBase" download link.
declare(strict_types=1);
defined('M3D_PRIVATE') or exit;

const SB_BASE = 'https://smutba.se';
const SB_PAGE_HOSTS = ['smutba.se', 'www.smutba.se'];
// pictures come from the SmutBase CDN; anything else is skipped
const SB_IMAGE_HOSTS = '/(^|\.)(open3dlab\.com|smutba\.se)$/i';
const SB_MAX_PIXELS = 100_000_000;   // 8K renders (33 MP) fit; bigger ones (or a server with too little memory) use the preview instead

/** The page SmutBase sent isn't the project page (bot check…): the admin can paste the page instead (admin.js asks for it). */
final class SbPageMissing extends InvalidArgumentException {}

/** A SmutBase page (profile or project), as HTML. */
function sb_fetch(string $url): string
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if (!preg_match('#^https://#i', $url) || !in_array($host, SB_PAGE_HOSTS, true)) throw new InvalidArgumentException('Only smutba.se addresses can be read.');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', 'Accept-Language: en-US,en;q=0.9'],
        CURLOPT_ENCODING => '',   // gzip/brotli, whatever curl supports
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $code !== 200 || stripos($body, '<html') === false) {
        $what = is_string($body) && stripos($body, '<html') !== false ? ': ' . sb_describe($body) : '';
        throw new SbPageMissing('SmutBase did not send the page (' . ($code ? "HTTP $code" : ($err ?: 'no answer')) . $what . ').');
    }
    return $body;
}

/** What a page that isn't the expected one is, in a few words (for error messages): a bot check, or its title. */
function sb_describe(string $html): string
{
    if (preg_match('/challenge-platform|cf-chl|cf_chl|Just a moment\.\.\.|Attention Required/i', $html)) return 'an “are you human?” check (only a browser gets through)';
    if (preg_match('/not a bot|anubis|techaro/i', $html)) return 'its “Making sure you’re not a bot!” check (only a browser gets through)';
    $x = sb_dom($html);
    $title = trim(preg_replace('/\s+/', ' ', (string) ($x->query('//title')->item(0)?->textContent ?? '')));
    $h1 = trim(preg_replace('/\s+/', ' ', (string) ($x->query('//h1')->item(0)?->textContent ?? '')));
    $bits = array_filter([$title !== '' ? '“' . mb_substr($title, 0, 80) . '”' : '', $h1 !== '' && $h1 !== $title ? 'heading “' . mb_substr($h1, 0, 80) . '”' : '']);
    return $bits ? 'a page titled ' . implode(', ', $bits) : 'a page without a title (' . strlen($html) . ' bytes)';
}

function sb_dom(string $html): DOMXPath
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
    libxml_clear_errors();
    return new DOMXPath($doc);
}

/** XPath test for "has this CSS class". */
function sb_class(string $c): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' $c ')";
}

/** Absolute address; SmutBase's /out/?url=… redirects become the real address. */
function sb_abs(string $href): string
{
    $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_starts_with($href, '/out/') || str_contains($href, 'smutba.se/out/')) {
        parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
        if (!empty($q['url']) && preg_match('#^https?://#i', (string) $q['url'])) return (string) $q['url'];
    }
    if (str_starts_with($href, '//')) return 'https:' . $href;
    if (str_starts_with($href, '/')) return SB_BASE . $href;
    return $href;
}

/** The project id (a UUID) of a /project/<id>/ address, or ''. */
function sb_project_id(string $url): string
{
    return preg_match('#/project/([0-9a-f-]{36})#i', $url, $m) ? strtolower($m[1]) : '';
}

/** "[Final Fantasy VII Remake] Tifa Lockhart - Version 5" → [name, franchise, major version or 0]. */
function sb_parse_title(string $title): array
{
    $t = trim(preg_replace('/\s+/u', ' ', $title));
    $franchise = '';
    if (preg_match('/^\[([^\]]+)\]\s*(.*)$/u', $t, $m)) { $franchise = trim($m[1]); $t = trim($m[2]); }
    $major = 0;
    if (preg_match('/^(.*?)\s*[-–—]?\s*\b(?:version|ver\.?|v)\s*(\d{1,2})$/iu', $t, $m) && trim($m[1]) !== '') { $t = trim($m[1], " -–—"); $major = (int) $m[2]; }
    return [$t, $franchise, $major];
}

/** One profile page: [projects [{url, id, title, thumb}], next page address or ''] */
function sb_profile_page(string $html): array
{
    $x = sb_dom($html);
    $out = [];
    foreach ($x->query('//div[' . sb_class('entry') . ']') as $entry) {
        $a = $x->query('.//h3[' . sb_class('entry__title') . ']//a', $entry)->item(0) ?? $x->query('.//a[contains(@href, "/project/")]', $entry)->item(0);
        if (!$a) continue;
        $url = sb_abs($a->getAttribute('href'));
        $id = sb_project_id($url);
        if ($id === '' || isset($out[$id])) continue;
        $img = $x->query('.//a[' . sb_class('entry__image-link') . ']//img', $entry)->item(0) ?? $x->query('.//img', $entry)->item(0);
        $out[$id] = ['url' => SB_BASE . '/project/' . $id . '/', 'id' => $id, 'title' => trim($a->textContent), 'thumb' => $img ? sb_abs($img->getAttribute('src')) : ''];
    }
    $next = $x->query('//ul[' . sb_class('pagination') . ']//li[' . sb_class('next') . ']/a[@href]')->item(0);
    return [array_values($out), $next ? sb_abs($next->getAttribute('href')) : ''];
}

/** Every project of a profile (follows "Next Page", 30 pages at most). */
function sb_profile(string $url): array
{
    if (!preg_match('#^https://(www\.)?smutba\.se/user/(\d+)#i', trim($url), $m)) throw new InvalidArgumentException('Paste the address of a SmutBase profile, like https://smutba.se/user/10157/');
    $next = SB_BASE . '/user/' . $m[2] . '/';
    $all = [];
    for ($i = 0; $next !== '' && $i < 30; $i++) {
        $html = sb_fetch($next);
        [$items, $next] = sb_profile_page($html);
        // the first page has no projects: SmutBase answered with something else (a check, a notice) → say what it was
        if (!$items && $i === 0) throw new InvalidArgumentException('SmutBase sent ' . sb_describe($html) . ' instead of the profile, so no projects were found. Copy the profile pages from your browser and paste them below instead.');
        foreach ($items as $p) $all[$p['id']] ??= $p;
    }
    return array_values($all);
}

// ── HTML → the site's text format (md_html) ───────────────────────────────────
/** Inline content: **bold**, *italic*, `code`, [links](…); <br> = line break. */
function sb_inline(DOMNode $n): string
{
    $out = '';
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMText) { $out .= preg_replace('/\s+/u', ' ', $c->textContent); continue; }
        if (!$c instanceof DOMElement) continue;
        $tag = strtolower($c->tagName);
        if (in_array($tag, ['ul', 'ol', 'script', 'style', 'img', 'video', 'picture'], true)) continue;   // lists are handled by the caller
        $in = sb_inline($c);
        $out .= match ($tag) {
            'br' => "\n",
            'strong', 'b' => trim($in) !== '' ? sb_wrap($in, '**') : $in,
            'em', 'i' => trim($in) !== '' && !str_contains($c->getAttribute('class'), 'fa') ? sb_wrap($in, '*') : '',
            'code' => trim($in) !== '' ? '`' . trim($in) . '`' : '',
            'a' => ($h = sb_abs($c->getAttribute('href'))) !== '' && preg_match('#^https?://#i', $h) && trim($in) !== '' ? '[' . trim($in) . '](' . str_replace([' ', ')'], ['%20', '%29'], $h) . ')' : $in,
            default => $in,
        };
    }
    return $out;
}

/** Keeps the spaces outside the markers: "**bold** text", not "**bold **text". */
function sb_wrap(string $s, string $m): string
{
    preg_match('/^(\s*)(.*?)(\s*)$/us', $s, $p);
    return $p[1] . $m . $p[2] . $m . $p[3];
}

function sb_clean(string $s): string
{
    $s = preg_replace('/[ \t]+\n/', "\n", preg_replace('/\n[ \t]+/', "\n", $s));
    return trim(preg_replace("/\n{3,}/", "\n\n", $s));
}

/** List items (text of each <li>, with its sub-list as indented "- " lines). */
function sb_items(DOMElement $list): array
{
    $items = [];
    foreach ($list->childNodes as $li) {
        if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') continue;
        $t = sb_clean(sb_inline($li));
        foreach ($li->childNodes as $sub) {
            if ($sub instanceof DOMElement && in_array(strtolower($sub->tagName), ['ul', 'ol'], true)) {
                foreach (sb_items($sub) as $s) $t .= "\n  - " . str_replace("\n", ' ', $s);
            }
        }
        if ($t !== '') $items[] = $t;
    }
    return $items;
}

/** The top-level blocks of an element; wrappers that only hold blocks (SmutBase nests <p> in <p>) are opened. */
function sb_blocks(DOMNode $n): array
{
    $blocks = [];
    $text = '';
    foreach ($n->childNodes as $c) {
        if ($c instanceof DOMText) { $text .= $c->textContent; continue; }
        if (!$c instanceof DOMElement) continue;
        $tag = strtolower($c->tagName);
        $isBlock = in_array($tag, ['p', 'div', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'blockquote', 'pre', 'table', 'section'], true);
        if (!$isBlock) { $text .= $c->ownerDocument->saveHTML($c); continue; }
        if (trim($text) !== '') { $blocks[] = ['p', sb_frag($text)]; }
        $text = '';
        if (in_array($tag, ['p', 'div', 'section'], true) && $c->getElementsByTagName('*')->length
            && ($x = new DOMXPath($c->ownerDocument)) && $x->query('./p|./ul|./ol|./h1|./h2|./h3|./h4|./hr|./div', $c)->length) {
            array_push($blocks, ...sb_blocks($c));
            continue;
        }
        $blocks[] = [$tag, $c];
    }
    if (trim($text) !== '') $blocks[] = ['p', sb_frag($text)];
    return $blocks;
}

/** Loose inline HTML (text between blocks) as an element, for sb_inline(). */
function sb_frag(string $html): DOMElement
{
    $d = new DOMDocument();
    libxml_use_internal_errors(true);
    $d->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $d->getElementsByTagName('div')->item(0);
}

/** Blocks → text-format paragraphs and lists. */
function sb_md(array $blocks): string
{
    $out = [];
    foreach ($blocks as [$tag, $el]) {
        if ($tag === 'hr') continue;
        if ($tag === 'ul') { $out[] = implode("\n", array_map(fn($i) => '- ' . str_replace("\n", "\n  ", $i), sb_items($el))); continue; }
        if ($tag === 'ol') { $n = 0; $out[] = implode("\n", array_map(function ($i) use (&$n) { return ++$n . '. ' . str_replace("\n", "\n  ", $i); }, sb_items($el))); continue; }
        if (preg_match('/^h[1-6]$/', $tag)) { $out[] = '### ' . trim(preg_replace('/\s+/u', ' ', $el->textContent)); continue; }
        if ($tag === 'pre') { $out[] = "```\n" . rtrim($el->textContent) . "\n```"; continue; }
        if ($tag === 'blockquote') { $out[] = '> ' . str_replace("\n", "\n> ", sb_md(sb_blocks($el))); continue; }
        $t = sb_clean(sb_inline($el));
        if ($t !== '') $out[] = $t;
    }
    return sb_clean(implode("\n\n", array_filter($out, fn($s) => trim($s) !== '')));
}

/** First letter in capitals, after any formatting marks ("**[lognos](…)** for…" → "**[Lognos](…)** for…"). */
function sb_ucfirst(string $s): string
{
    return preg_replace_callback('/^([\s*_\[]*)(\p{Ll})/u', fn($m) => $m[1] . mb_strtoupper($m[2]), $s, 1) ?? $s;
}

/** sb_ucfirst() on every paragraph, heading and list line of a text in the site's format (lines that continue an item, and code, stay as they are). */
function sb_ucfirst_text(string $s): string
{
    $blocks = explode("\n\n", $s);
    foreach ($blocks as &$b) {
        if (str_starts_with(ltrim($b), '```')) continue;
        $lines = explode("\n", $b);
        foreach ($lines as $i => &$l) {
            if (preg_match('/^(\s*(?:[-*] |\d+\. |#{1,4} |> ?)+)(.*)$/s', $l, $mm)) $l = $mm[1] . sb_ucfirst($mm[2]);
            elseif ($i === 0) $l = sb_ucfirst($l);
        }
        unset($l);
        $b = implode("\n", $lines);
    }
    unset($b);
    return implode("\n\n", $blocks);
}

/** Plain text of one block (for matching titles, changelog lines). */
function sb_text(DOMNode $el): string
{
    return trim(preg_replace('/\s+/u', ' ', $el->textContent));
}

/** "03/07/2022", "2022-07-03", "July 2, 2022" → "03/07/2022" (the changelog's day/month/year), or ''. */
function sb_date(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $s, $m)) return sprintf('%02d/%02d/%04d', $m[1], $m[2], $m[3]);
    if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{2})$#', $s, $m)) return sprintf('%02d/%02d/20%02d', $m[1], $m[2], $m[3]);   // day first, like the 4-digit form
    if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $s, $m)) return "$m[3]/$m[2]/$m[1]";
    if (preg_match('/^(19|20)\d\d$/', $s)) return "01/01/$s";   // a year alone (strtotime reads "2022" as a time of today)
    if (!preg_match('/(?<!\d)\d{4}(?!\d)/', $s)) return '';   // no year: words like "today" would give the import's day
    $t = strtotime(str_replace(['a.m.', 'p.m.', '.'], ['am', 'pm', ''], $s));
    return $t ? date('d/m/Y', $t) : '';
}

/** The Changelog section: "Version 3.1 (03/07/2022) - note" paragraphs start an entry, lists are its changes, other paragraphs its notes. */
function sb_changelog(array $blocks): array
{
    $log = [];
    $intro = [];
    $cur = null;
    foreach ($blocks as [$tag, $el]) {
        if ($tag === 'hr') continue;
        $text = sb_text($el);
        if (in_array($tag, ['p', 'h3', 'h4', 'h5', 'h6', 'div'], true)
            && preg_match('/^(?:version|ver\.?|v)\s*([0-9][\w.\-]*)\s*(?:\(([^)]*)\))?\s*(?:[-–—:]\s*)?(.*)$/iu', $text, $m)) {
            if ($cur) $log[] = $cur;
            $date = sb_date($m[2] ?? '');
            $rest = trim($m[3] ?? '');
            if ($date === '' && preg_match('#^\(?(\d{1,2}/\d{1,2}/\d{4})\)?\s*(?:[-–—:]\s*)?(.*)$#', $rest, $d)) { $date = sb_date($d[1]); $rest = trim($d[2]); }
            $cur = ['version' => $m[1], 'date' => $date, 'highlight' => mb_substr(rtrim($rest, '.'), 0, 120), 'changes' => [], 'notes' => []];
            // the stage: in the version ("3.2-beta") or the note's first word ("Beta - …"); not any "alpha" in the note (alpha maps)
            if (preg_match('/(alpha|beta)/i', $m[1], $st)) {
                $cur['stage'] = strtolower($st[1]);
                $cur['version'] = preg_replace('/[._-]*(?:alpha|beta)$/i', '', $m[1]) ?: $m[1];   // "3.2-beta" → 3.2 (the site adds "Beta" itself)
            }
            elseif (preg_match('/^(alpha|beta)\b[\s.:\-–—]*(.*)$/isu', $rest, $st)) {
                $cur['stage'] = strtolower($st[1]);
                $cur['highlight'] = mb_substr(rtrim($st[2], '.'), 0, 120);   // "Version 1.0 Beta": not "Beta" as the highlight too
            }
            continue;
        }
        if ($tag === 'ul' || $tag === 'ol') {
            $items = sb_items($el);
            if ($cur) array_push($cur['changes'], ...$items); else $intro[] = sb_md([[$tag, $el]]);
            continue;
        }
        $md = sb_md([[$tag, $el]]);
        if ($md === '') continue;
        if ($cur) $cur['notes'][] = $md; else $intro[] = $md;
    }
    if ($cur) $log[] = $cur;
    return [$log, implode("\n\n", $intro)];
}

/** A project page → the editor's model fields (clean_model() input) plus what to copy: ['model', 'images', 'thumb', 'videos', 'id']. */
function sb_project(string $html, string $url = ''): array
{
    $x = sb_dom($html);
    // anything else (SmutBase's bot check, an error page) must never become a model
    if (!$x->query('//h1[@id="file_title"]')->item(0)) throw new SbPageMissing('SmutBase sent ' . sb_describe($html) . ' instead of the project page.');
    $canon = $x->query('//link[@rel="canonical"]/@href')->item(0)?->nodeValue ?? '';
    $own = sb_project_id($canon) ?: sb_project_id((string) ($x->query('//nav[' . sb_class('panel-nav') . ']//a/@href')->item(0)?->nodeValue ?? ''));
    $id = sb_project_id($url) ?: $own;
    if ($id === '') throw new InvalidArgumentException('This is not a SmutBase project page.');
    $title = sb_text($x->query('//h1[@id="file_title"]')->item(0));
    // a page pasted into the wrong row of the list
    if ($own !== '' && $own !== $id) throw new SbPageMissing('That is the page of “' . $title . '”, another project. Paste this project’s own page.');
    if ($title === '') $title = (string) ($x->query('//meta[@property="og:title"]/@content')->item(0)?->nodeValue ?? '');
    [$name, $franchise, $major] = sb_parse_title($title);
    if ($name === '') throw new InvalidArgumentException('The project page has no title.');

    // tags: universe (franchise when the title has none), software ("blender 3.2")
    $tags = [];
    foreach ($x->query('//project-tag-element') as $t) $tags[$t->getAttribute('type')][] = $t->getAttribute('name');
    if ($franchise === '' && !empty($tags['universe'][0])) $franchise = ucwords($tags['universe'][0]);

    // pictures: the large original (link) and the 900 px preview (img) of each; videos are not imported
    $images = [];
    foreach ($x->query('//div[' . sb_class('main-upload') . ']//a[' . sb_class('badge-container') . ']') as $a) {
        $img = $x->query('.//img', $a)->item(0);
        $images[] = ['full' => sb_abs($a->getAttribute('href')), 'preview' => $img ? sb_abs($img->getAttribute('src')) : ''];
    }
    $videos = $x->query('//div[' . sb_class('main-upload') . ']//video')->length;
    // the catalog picture: og:image, or (a copied page has no <head>) the same picture in the sidebar, an "item_thumb" address
    $thumb = sb_abs((string) ($x->query('//meta[@property="og:image"]/@content')->item(0)?->nodeValue
        ?? $x->query('//img[contains(@src, "/item_thumb")]/@src')->item(0)?->nodeValue ?? ''));

    // dates from the side panel: "Updated July 2, 2022, 3:25 p.m."
    $side = sb_text($x->query('//div[' . sb_class('side-upload') . ']')->item(0) ?? new DOMText(''));
    $updated = preg_match('/Updated\s+([A-Z][a-z]{2,}\.? \d{1,2}, \d{4})/', $side, $m) ? sb_date($m[1]) : '';
    if ($updated === '' && preg_match('/Published\s+([A-Z][a-z]{2,}\.? \d{1,2}, \d{4})/', $side, $m)) $updated = sb_date($m[1]);

    // the Description card, split by its headings
    $body = null;
    foreach ($x->query('//wa-card|//div[' . sb_class('panel') . ']') as $card) {
        $h = $x->query('.//h2[@slot="header"]|.//*[' . sb_class('panel__heading') . ']', $card)->item(0);
        if ($h && stripos(sb_text($h), 'description') !== false) { $body = $x->query('.//div[' . sb_class('panel__body') . ']', $card)->item(0); break; }
    }
    $sections = [['title' => '', 'blocks' => []]];
    if ($body) foreach (sb_blocks($body) as $b) {
        if (in_array($b[0], ['h1', 'h2', 'h3'], true) && ($t = sb_text($b[1])) !== '') { $sections[] = ['title' => $t, 'blocks' => []]; continue; }
        $sections[count($sections) - 1]['blocks'][] = $b;
    }

    $m = ['name' => $name, 'franchise' => $franchise !== '' ? $franchise : 'Other', 'visibility' => 'draft',
        'features' => [], 'credits' => [], 'notes' => [], 'changelog' => []];
    if ($major > 1) $m['majorVersion'] = $major;
    $desc = [];
    foreach ($sections as $sec) {
        $t = mb_strtolower($sec['title']);
        $blocks = $sec['blocks'];
        $lists = array_merge(...array_map(fn($b) => sb_items($b[1]), array_filter($blocks, fn($b) => in_array($b[0], ['ul', 'ol'], true))) ?: [[]]);
        $others = array_values(array_filter($blocks, fn($b) => !in_array($b[0], ['ul', 'ol', 'hr'], true)));
        if ($t === '') { if (($d = sb_md($blocks)) !== '') $desc[] = $d; continue; }
        if (preg_match('/change ?log|version history|^changes$|^updates?$/', $t)) {
            [$log, $note] = sb_changelog($blocks);
            $m['changelog'] = $log;
            if ($note !== '') $m['changelogNote'] = mb_substr($note, 0, 1000);
            continue;
        }
        if (preg_match('/^credits?|thanks/', $t)) { $m['credits'] = array_merge($m['credits'], $lists, array_filter(array_map(fn($b) => sb_md([$b]), $others))); continue; }
        // known issues and future ideas go to the notes too, like every other section (below); "planned features" are not features
        $later = (bool) preg_match('/future|planned|roadmap|to ?do|known (issues|bugs)|^bugs$/', $t);
        if (!$later && preg_match('/content|features?|included|what.?s in/', $t) && $lists) {
            $m['features'] = array_merge($m['features'], $lists);
            if (!$others) continue;
            $blocks = $others;
        }
        // instructions → "Notes for this model": one note per list item, or per paragraph
        if (preg_match('/instruction|how to (use|install)|usage|installation|setup|set up|getting started/', $t)) {
            foreach ($blocks as $b) {
                if ($b[0] === 'hr') continue;
                if (in_array($b[0], ['ul', 'ol'], true)) array_push($m['notes'], ...sb_items($b[1]));
                elseif (($md = sb_md([$b])) !== '') $m['notes'][] = $md;
            }
            continue;
        }
        if (str_contains($t, 'requirement') && preg_match('/blender\s*(?:>=|≥|>|version)?\s*(\d+\.\d+)/i', sb_text($sec['blocks'][0][1] ?? new DOMText('')) . ' ' . implode(' ', $lists), $bm)) {
            $m['blender'] = $bm[1] . ' or newer';
        }
        // anything else: one note with the section's title in bold (notes hold 5000 characters: a longer section continues in the next note)
        $note = '**' . str_replace('*', '', $sec['title']) . '**';
        foreach ($blocks as $b) {
            if (($md = sb_md([$b])) === '') continue;
            if (mb_strlen($note) + mb_strlen($md) + 2 > 4900 && $note !== '') { $m['notes'][] = $note; $note = ''; }
            $note .= ($note === '' ? '' : "\n\n") . mb_substr($md, 0, 4900);
        }
        if ($note !== '' && $note !== '**' . str_replace('*', '', $sec['title']) . '**') $m['notes'][] = $note;
    }
    if ($desc) {   // the description holds 5000 characters: what doesn't fit goes on in the first notes (never cut mid-sentence)
        $keep = '';
        $more = [];
        foreach (explode("\n\n", implode("\n\n", $desc)) as $para) {
            if (!$more && mb_strlen($keep) + mb_strlen($para) + 2 <= 4900) $keep .= ($keep === '' ? '' : "\n\n") . $para;
            else $more[] = $para;
        }
        $m['description'] = $keep !== '' ? $keep : mb_substr((string) array_shift($more), 0, 4900);
        $notes = [];
        $note = '';
        foreach ($more as $para) {
            if ($note !== '' && mb_strlen($note) + mb_strlen($para) + 2 > 4900) { $notes[] = $note; $note = ''; }
            $note .= ($note === '' ? '' : "\n\n") . mb_substr($para, 0, 4900);
        }
        if ($note !== '') $notes[] = $note;
        $m['notes'] = array_merge($notes, $m['notes']);
    }
    if (empty($m['blender']) && !empty($tags['software'])) {
        foreach ($tags['software'] as $s) if (preg_match('/^blender\s+(\d+\.\d+)/i', $s, $bm)) $m['blender'] = $bm[1] . ' or newer';
    }
    // every imported text starts with a capital letter: list items, notes, each paragraph and list line of longer texts
    foreach (['features', 'credits', 'notes'] as $k) $m[$k] = array_map('sb_ucfirst_text', $m[$k]);
    if (isset($m['description'])) $m['description'] = sb_ucfirst_text($m['description']);
    if (isset($m['changelogNote'])) $m['changelogNote'] = sb_ucfirst_text($m['changelogNote']);
    foreach ($m['changelog'] as &$en) {
        foreach (['changes', 'notes'] as $k) if (!empty($en[$k])) $en[$k] = array_map('sb_ucfirst_text', $en[$k]);
        if (!empty($en['highlight'])) $en['highlight'] = sb_ucfirst($en['highlight']);
    }
    unset($en);
    if (!$m['changelog']) {   // no changelog: one version, so the page has a latest version and a date
        $m['changelog'][] = ['version' => (string) max(1, $major), 'date' => $updated, 'changes' => []];
    }
    return ['model' => $m, 'images' => $images, 'thumb' => $thumb, 'videos' => $videos, 'id' => $id, 'url' => SB_BASE . '/project/' . $id . '/', 'title' => $title];
}

/** The model already imported from this project (its SmutBase link), or null. */
function sb_imported(string $id, array $models): ?array
{
    foreach ($models as $mm) foreach ($mm['links'] ?? [] as $l) if (is_array($l) && $id !== '' && str_contains(strtolower((string) ($l['url'] ?? '')), $id)) return $mm;
    return null;
}

/** A model with the same name (and major version) that may be this project, entered by hand: or null. */
function sb_similar(string $name, int $major, array $models): ?array
{
    $n = mb_strtolower(trim($name));
    foreach ($models as $mm) {
        if (mb_strtolower(trim((string) ($mm['name'] ?? ''))) === $n && (int) ($mm['majorVersion'] ?? 0) === $major) return $mm;
    }
    return null;
}

function sb_slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s), '-'));
    return substr($s, 0, 56) ?: 'model';
}

/** Copies a picture: the original when it is not too big, else the preview. Returns the media address or '' (+ a warning). */
function sb_copy_image(array $img, string $folder, array &$warn): string
{
    $failed = '';
    foreach (['full', 'preview'] as $k) {
        $u = (string) ($img[$k] ?? '');
        if ($u === '' || !preg_match(SB_IMAGE_HOSTS, (string) parse_url($u, PHP_URL_HOST))) continue;
        $name = basename((string) parse_url($u, PHP_URL_PATH));
        try {
            $key = preg_replace('/\?.*$/', '', $u);   // signed addresses change: name the file without the query
            $out = copy_external($u, $folder, SB_MAX_PIXELS, $key, $k === 'full' ? 200 * 1024 * 1024 : 120 * 1024 * 1024);
            if ($failed !== '') $warn[] = $failed . ': its smaller preview was used instead.';
            return $out;
        } catch (Throwable $ex) {
            $failed = '“' . $name . '” could not be copied (' . $ex->getMessage() . ')';
        }
    }
    if ($failed !== '') $warn[] = $failed . '. Add it in the editor.';
    return '';
}
