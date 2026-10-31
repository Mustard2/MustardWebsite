<?php
// /account/  The logged-in visitor's own Patreon membership as the site sees it: status, tiers, time as a patron,
// total support, which downloads are open to them, what they downloaded (My Models) and bookmarked. Nothing here is stored
// beyond the session (see privacy.php); My Models comes from the download log, bookmarks from their bell file (app/bell.php).
require __DIR__ . '/app/_init.php';

$p = m3d_patron($M3D);
$known = $p !== null && array_key_exists('total_cents', $p);
showcase_flash();   // a render removed with its ✕ in My Renders (auth/render.php, back=account)

layout_start('My Account', 'Your Patreon membership on ' . site_name() . '.', 'page-account');
?>
  <div class="wrap account-page">
  <?php if (!$p): ?>
    <h1>My Account</h1>
    <section class="acc-card">
      <p class="lead-sm">Log in with Patreon to see your membership and downloads.</p>
      <p><a class="btn btn-patreon" rel="nofollow" href="/auth/login.php?return=%2Faccount%2F">Log In with Patreon</a></p>
    </section>
  <?php else:
    $owner = ($p['reason'] ?? '') === 'owner';
    $months = m3d_months_since($p['since'] ?? null);
    $cur = $p['currency'] ?? null;
    [$statusLabel, $tone] = match (true) {
        $owner                             => ['Owner (Admin)', 'violet'],
        ($p['reason'] ?? '') === 'exception' => ['Downloads Unlocked by ' . site_name(), 'violet'],
        !empty($p['access'])               => ['Active Patron', 'green'],
        ($p['reason'] ?? '') === 'declined' => ['Payment Declined', 'red'],
        ($p['reason'] ?? '') === 'no-tier' => ['Patron Without a Tier', 'amber'],
        !empty($p['member'])               => [($p['status'] ?? '') === 'former_patron' ? 'Former Patron' : 'Not a Paying Member', 'grey'],
        default                            => ['Not a Member', 'grey'],
    };
    $date = fn(?string $iso) => m3d_site_time((string) $iso, 'j F Y') ?: '—';
    $day = fn(?int $t) => $t ? m3d_site_time($t, 'j M Y') : '—';   // short, for tables
    $cadence = ['1' => 'every month', '12' => 'every year'][(string) ($p['cadence'] ?? '')] ?? (!empty($p['cadence']) ? 'every ' . m3d_plural((int) $p['cadence'], 'month') : '');

    // Download button for a model/outfit and the link label used: /get/<item>/<n>/ for the patron link with that label
    // today (else the first patron link), so a link replaced in the admin gives the new file; an item with several links on
    // gets its page's download box (#download) instead. null = removed, or a page this
    // visitor can't open (draft, password not entered): those keep only their name. Blurred items are shown in full, their
    // picture blurred unless this visitor may see it (card_pic(), like on the page)
    $dlBan = m3d_banned($p, 'download');   // a Downloads ban (Access): the buttons go, the downloads row says so
    $target = function (string $kind, string $slug, string $label) use ($p, $owner, $dlBan): ?array {
        $it = m3d_item($kind, $slug);
        if (!$it || !m3d_can_view($kind, $it)) return null;
        $links = array_values(array_filter($it['links'] ?? [], fn($l) => !empty($l['url'])));
        $n = null;
        foreach ($links as $i => $l) {
            if (($l['access'] ?? 'patrons') !== 'patrons' || !empty($l['off'])) continue;
            $n ??= $i;
            if (trim((string) ($l['label'] ?? '')) === trim($label)) { $n = $i; break; }
        }
        // several files (download buttons) on the page: the button opens its download box, where each file has its own button
        $several = count(array_filter($links, fn($l) => empty($l['off']))) > 1;
        $check = m3d_check_requirements($it, $p);
        // a login from before the membership details: unknown, not refused (the download asks to log in again, like auth/get.php)
        // what blocks it: their value ("2 months"), or for the minimum tier what is needed ("needs the €10 tier or higher")
        $why = $check['ok'] || $check['unknown'] ? [] : array_map(fn($r) => $r[0] === 'Patreon Tier' ? 'needs the ' . $r[1] : $r[2], $check['missing']);
        if (empty($p['access']) && !$owner) $why[] = 'paid tier needed';
        elseif ($dlBan) $why[] = 'downloads are off for you';
        if (m3d_release_phase($it) === 'free') $why = [];   // past its public date: free for everyone (auth/get.php lets public links pass a ban too)
        // no download while in development or before the patrons date (auth/get.php): a short label instead of the button
        $dev = $kind === 'models' && m3d_status($it) === 'in-development' ? 'In Development'
            : (m3d_release_phase($it) === 'soon' ? 'Coming ' . when_mark((int) m3d_release($it)['patrons'], 'short') : '');
        return ['item' => $it, 'kind' => $kind, 'ok' => !$why, 'why' => $why, 'dev' => $dev,
                'href' => $n === null || $dev ? null : ($several ? m3d_item_url($kind, $it['slug']) . '#download' : m3d_get_url($kind, (string) $it['slug'], $n))];
    };
    // the name with a small picture (like the admin lists), and under it a Model / accessory-type chip (the person / hanger icons of the
    // unlock counters in the header, site.js) + the version they downloaded last
    $kindIcons = ['models' => '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>',
                  'outfits' => '<path d="M9.8 6.3a2.2 2.2 0 1 1 3 2.05c-.5.2-.8.6-.8 1.1v.55"/><path d="M12 10 3.6 16.1c-.7.5-.35 1.6.5 1.6h15.8c.85 0 1.2-1.1.5-1.6L12 10z"/>',
                  'scenes' => SCENE_ICON];
    // the like heart before the picture (site.js [data-acc-like] → auth/like.php): only for those who can like (paying, not previewing)
    $canLike = !empty(m3d_like_viewer($p)['can']) && empty($p['preview']);
    $code = m3d_like_code((string) $p['id']);
    $likes = ['models' => m3d_likes('models'), 'outfits' => m3d_likes('outfits'), 'scenes' => m3d_likes('scenes')];
    // and the bookmark left of it (Pi; site.js [data-bm] → auth/bookmark.php): downloads are bookmarked by themselves, removing one hides its updates
    $canBm = m3d_bookmark_can($p);
    $bmOn = $canBm ? m3d_bookmarked($p) : [];
    $acts = (int) $canBm + (int) $canLike;   // buttons before the picture: the update rows' text starts under the name
    $heart = function (string $kind, ?array $it) use ($canLike, $canBm, $bmOn, $code, $likes): string {
        if (!$canLike && !$canBm) return '';
        if (!$it) return '<span class="acc-acts" aria-hidden="true">' . str_repeat('<span class="acc-like"></span>', (int) $canBm + (int) $canLike) . '</span>';   // keeps the names in line
        $out = $canBm ? bookmark_toggle('acc-like acc-bmk', $kind, (string) $it['slug'], isset($bmOn[$kind . ':' . $it['slug']]), BOOKMARK_ICON) : '';
        if ($canLike) {
            $on = in_array($code, (array) ($likes[$kind][$it['slug']] ?? []), true);
            $what = m3d_kind_word($kind);
            $out .= '<button type="button" class="acc-like" data-acc-like="' . e($it['slug']) . '" data-like-kind="' . $kind . '" aria-pressed="' . ($on ? 'true' : 'false') . '"'
                . ' title="' . ($on ? 'You like this ' . $what . ' (click to remove)' : 'Like this ' . $what) . '"><span class="sr-only">Like</span>' . HEART_ICON . '</button>';
        }
        return '<span class="acc-acts">' . $out . '</span>';
    };
    $cell = function (array $c) use ($kindIcons, $heart): string {
        [$it, $kind] = [$c['item'], $c['kind']];
        if (!$it) {   // removed, draft or password not entered: the name only (an empty square with the kind's icon keeps the rows alike)
            return '<span class="acc-item acc-gone">' . $heart($kind, null) . '<span class="acc-pic acc-pic-ph" aria-hidden="true"><svg viewBox="0 0 24 24">' . $kindIcons[$kind] . '</svg></span><span class="acc-n">' . e($c['name']) . '</span></span>';
        }
        [$src, $hidden] = card_pic($kind, $it);   // blurred copy when this visitor may not see the pictures
        $url = e(m3d_item_url($kind, $it['slug']));
        $pic = '<a class="acc-pic' . ($src !== '' ? ($hidden ? ' is-blurred' : '') : ' acc-pic-ph') . '" href="' . $url . '" tabindex="-1" aria-hidden="true">'
             . ($src !== '' ? '<img src="' . e($src) . '" alt="" loading="lazy" decoding="async" />' : e(mb_substr((string) $it['name'], 0, 1))) . '</a>';
        $sub = '<span class="acc-sub"><span class="acc-kind acc-kind-' . $kind . '"><svg viewBox="0 0 24 24" aria-hidden="true">' . ($kind === 'outfits' ? accessory_icon_paths(m3d_accessory_type($it)) : $kindIcons[$kind]) . '</svg>'
             . e(m3d_item_type($kind, $it)) . '</span>' . ($c['got'] !== '' ? '<span class="acc-ver">' . e($c['got']) . '</span>' : '') . $c['updTag'] . '</span>';   // phones only: the version (its own column on wider screens) and updTag, in place of the Update column
        // not one big link: the update tag in the line under the name (phones) is a button
        return '<div class="acc-item">' . $heart($kind, $it) . $pic . '<span><a class="acc-n" href="' . $url . '">' . e($kind === 'models' ? m3d_model_name($it) : (string) $it['name']) . '</a>' . $sub . '</span></div>';
    };
    // the Download button, or "✗ Not eligible (2 months)": what the patron has where they fall short
    $action = fn(array $t) => !$t['item'] ? '' : (!empty($t['dev']) ? '<span class="muted small">' . when_text($t['dev']) . '</span>' : ($t['ok']
        ? ($t['href'] ? '<a class="btn btn-primary btn-sm" href="' . e($t['href']) . '" rel="nofollow">Download</a>' : '')
        : '<span class="warn-text">✗ Not Eligible' . ($t['why'] ? ' (' . e(implode(', ', $t['why'])) . ')' : '') . '</span>'));
    // My Models: every model/outfit this patron has downloaded (once each, last downloaded first), with the link, version
    // and time of the last download, and the date of the first one (when it was unlocked). Items removed, drafts and password
    // pages not opened stay with their name only: no picture, chip, version, update or button
    $claimed = [];
    foreach (array_reverse(m3d_patron_log((string) $p['id'])) as [$t, $kind, $slug, $ver, $label]) {
        $key = "$kind:$slug";
        if (isset($claimed[$key])) { $claimed[$key]['first'] = $t; continue; }
        $it = m3d_item($kind, $slug);
        $claimed[$key] = ($target($kind, $slug, $label) ?? ['item' => null, 'kind' => $kind, 'name' => $it['name'] ?? $slug])
                       + ['slug' => $slug, 'first' => $t, 'lastT' => $t, 'got' => $ver];
    }
    // newer versions of what they downloaded (changelog date after their last download): tagged and listed first
    $updates = m3d_updates_for($p);
    // preview: the simulated updates aren't in the log, so they join My Models here (no download date)
    if (!empty($p['preview_updates'])) foreach ($updates as $kind => $list) foreach ($list as $slug => $v) {
        if (!isset($claimed["$kind:$slug"]) && ($c = $target($kind, $slug, '')) !== null) $claimed["$kind:$slug"] = $c + ['slug' => $slug, 'first' => null, 'lastT' => 0, 'got' => m3d_preview_got($c['item']), 'sim' => true];
    }
    $ignored = m3d_ignored_updates($p);
    $bmOff = m3d_bookmarks_off($p);   // ignored updates: still listed here, greyed, after the others; no catalog tag or bell row
    foreach ($claimed as &$c) {
        $c['update'] = $updates[$c['kind']][$c['slug']] ?? null;
        $c['ign'] = $c['update'] !== null && ($ignored[$c['kind'] . ':' . $c['slug']] ?? null) === $c['update'];
        $c['off'] = $c['update'] !== null && isset($bmOff[$c['kind'] . ':' . $c['slug']]);   // bookmark removed: its updates hidden like an ignored one (bell, catalogs)
        // the update tag opens the versions released since their download under the row (site.js, [data-upd-toggle]); its ✕
        // ignores the update like the bell's ✕ ([data-upd-x], hidden while ignored; previewing: only in the preview)
        $c['updTag'] = $c['update'] === null ? '' : '<span class="upd-pill has-x"><button type="button" class="upd-tag" data-upd-toggle aria-expanded="false" title="What\'s new since your download"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg><span class="upd-word">Update · </span>' . e($c['update']) . '<svg class="upd-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>'
            . '<button type="button" class="upd-x" data-upd-x title="Ignore this update" aria-label="Ignore the update to ' . e($c['update']) . '">✕</button></span>';
    }
    unset($c);
    $rank = fn($c) => $c['update'] === null ? 2 : ($c['ign'] || $c['off'] ? 1 : 0);   // updates first, the ignored / un-bookmarked ones after them
    uasort($claimed, fn($a, $b) => $rank($a) <=> $rank($b));
    $updCount = count(array_filter(array_column($claimed, 'update'), fn($v) => $v !== null));
    $newCount = count(array_filter($claimed, fn($c) => $c['update'] !== null && !$c['ign'] && !$c['off']));   // the summary's count: not the ignored / un-bookmarked ones
    // "Updates Use an Unlock": a newer version needs an unlock again, so with none left it can't be downloaded now
    if (m3d_dl_settings()['updates']) foreach ($claimed as &$c) {
        if (!$c['item'] || !$c['ok'] || !$c['href']) continue;
        $l = m3d_check_limits($p, $c['kind'], (string) $c['slug']);
        if ($l['update'] && !$l['ok']) { $c['ok'] = false; $c['why'][] = 'no unlocks left'; }
    }
    unset($c);
    foreach ($claimed as &$c) $c['avail'] = $c['item'] && $c['ok'] && $c['href'];   // has a Download button
    unset($c);
    $availCount = count(array_filter(array_column($claimed, 'avail')));
    // download limits (Admin → Access): only the rules that are on AND apply to this patron, with their count (none = no card)
    $limits = m3d_check_limits($p);
    $mine = [];
    foreach ($limits['rows'] as $row) $mine[(string) ($row['rule']['id'] ?? '')] = $row;
    // without a paid tier nothing downloads anyway: no card (like the "left" boxes and the header counter)
    // (a "Custom" limits exception: their own rules, Admin → Access)
    $allRules = !$owner && empty($p['access']) ? [] : (($p['exception']['limits'] ?? '') === 'custom' ? array_column($limits['rows'], 'rule')
        : array_values(array_filter(m3d_dl_settings()['rules'], fn($r) => !empty($r['on']) && isset($mine[(string) ($r['id'] ?? '')]))));
    $allRules = m3d_rules_shown($allRules);   // not the rules of a kind the site has nothing of (Pi)
    // Models / Accessories / Scenes Bookmarked (Pi: in place of the Liked rows; downloads are bookmarked by themselves): newest bookmark or download first
    $bmAt = [];
    foreach (m3d_bookmarks($p) as $key => [$t]) $bmAt[$key] = $t;
    foreach (m3d_last_downloads(m3d_patron_history($p)) as $key => [$t]) $bmAt[$key] = max($t, $bmAt[$key] ?? 0);
    $bmItems = function (string $kind) use ($p, $bmAt): array {
        $bm = m3d_bookmarked($p);
        $out = array_values(array_filter(m3d_items($kind), fn($it) => isset($bm[$kind . ':' . $it['slug']]) && m3d_can_view($kind, $it)));
        usort($out, fn($a, $b) => ($bmAt[$kind . ':' . $b['slug']] ?? 0) <=> ($bmAt[$kind . ':' . $a['slug']] ?? 0));
        return $out;
    };
    $bmRows = ['models' => [$bmItems('models'), 'model_card', 'Models Bookmarked', 'models'], 'outfits' => [$bmItems('outfits'), 'outfit_card', 'Accessories Bookmarked', 'accessories'],
        'scenes' => [$bmItems('scenes'), 'scene_card', 'Scenes Bookmarked', 'scenes']];
  ?>
    <section class="acc-card acc-head">   <!-- the page title is the patron's name: everything about the login in one card -->
      <div class="acc-id"><?= avatar_html((string) ($p['id'] ?? ''), (string) $p['name'], false, 'ava-xl') ?><div>
        <h1 class="acc-name"><?= e($p['name']) ?></h1>
        <p class="acc-meta"><span class="badge tone-<?= e($tone) ?>"><?= e($statusLabel) ?></span><span class="muted small">Patreon User ID: <code><?= e($p['id'] ?? '') ?></code></span></p>
      </div></div>
      <div class="acc-actions">
        <?php if (m3d_welcome_on() && !empty($p['access']) && !m3d_banned($p, 'download')): ?><a class="btn btn-ghost btn-sm" href="/welcome/">Welcome Page</a><?php endif ?>
        <a class="btn btn-ghost btn-sm" rel="nofollow" href="/auth/login.php?return=%2Faccount%2F" title="Fetch your latest membership data from Patreon">Refresh from Patreon</a>
        <span class="acc-sep" aria-hidden="true"></span>
        <a class="btn btn-ghost btn-sm acc-ico" href="/account/settings/" title="Settings" aria-label="Settings"><?= ACCT_ICONS['settings'] ?></a>
        <a class="btn btn-ghost btn-sm acc-ico" href="/auth/logout.php?return=%2F" title="Log Out" aria-label="Log Out"><?= ACCT_ICONS['logout'] ?></a>
      </div>
    </section>

    <?php if (!$owner && !$known): ?>
      <p class="notice">Your login is from before these details were available. <a rel="nofollow" href="/auth/login.php?return=%2Faccount%2F">Refresh from Patreon</a> to see them.</p>
    <?php endif ?>

    <section class="us-sec acc-member">   <!-- Membership: downloads first (access, then what the limits leave), the limits, then the pledge -->
    <?= section_head('member', 'Membership', 'Your access and your pledge, as Patreon tells us.') ?>
    <div class="acc-grid acc-dl">
      <?php if ($dlBan): ?>
      <div class="acc-stat"><span class="muted small">Downloads</span><b class="warn-text">Off</b><span class="muted small">turned off for you · <a href="<?= e(m3d_ban_url('download')) ?>">Why?</a></span></div>
      <?php else: ?>
      <div class="acc-stat"><span class="muted small">Downloads</span><b class="<?= $owner || !empty($p['access']) ? 'ok-text' : 'warn-text' ?>"><?= $owner || !empty($p['access']) ? 'Unlocked' : 'Locked' ?></b><span class="muted small"><?= ($p['reason'] ?? '') === 'exception' ? 'by exception ✓' : ($owner || !empty($p['access']) ? 'paid tier ✓' : 'a paid tier is needed') ?></span></div>
      <?php endif ?>
      <?php if (($owner || !empty($p['access'])) && !$dlBan): $dlLeft = m3d_downloads_left($p);   // downloads left now, per kind (null = no rule: unlimited)
        foreach (array_intersect_key(['models' => 'Model Unlocks Left', 'outfits' => 'Accessory Unlocks Left', 'scenes' => 'Scene Unlocks Left'], array_flip(m3d_shown_kinds())) as $k => $lbl): $n = $dlLeft[$k] ?? null;
          if (!empty($dlLeft['none'][$k])): ?><a class="acc-stat acc-link" href="#limits"><span class="muted small"><?= $lbl ?></span><b class="muted">Not Included</b><span class="muted small acc-link-hint">see the limits →</span></a><?php continue; endif ?>
        <?php $tag = $n === null ? 'div' : 'a';   // with rules the whole box links to (and highlights) the Unlock limits ?>
        <<?= $tag ?> class="acc-stat<?= $n === null ? '' : ' acc-link' ?>"<?= $n === null ? '' : ' href="#limits"' ?>><span class="muted small"><?= $lbl ?></span><b class="<?= $n === 0 ? 'warn-text' : 'ok-text' ?>"><?= $n === null ? 'Unlimited' : $n ?></b><?php if ($n === 0 && !empty($dlLeft['next'][$k])): ?><span class="muted small">next: <?= when_html((int) $dlLeft['next'][$k]) ?></span><?php elseif ($n !== null): ?><span class="muted small acc-link-hint">see the limits →</span><?php endif ?></<?= $tag ?>>
      <?php endforeach; endif ?>
    </div>
    <?php // a card right under the downloads row: the "left" boxes scroll to it and light it up (site.js) ?>
    <?php if ($allRules && !$dlBan): ?>
      <section class="acc-card acc-limits" id="limits">
        <div class="acc-limits-head"><h2>Unlock Limits</h2><?= m3d_unlocks_on() ? how_pill() : '' ?></div>
        <?php if ($limits['exempt']): ?>
          <p class="ok-text">✓ These limits don't apply to you<?= $owner ? ' (admin)' : '' ?>.</p>
        <?php endif ?>
        <ul class="acc-limit-list">   <!-- every row alike: status ("0/1 used", "No limit") | the rule, with when the next unlock frees up under it -->
          <?php foreach ($allRules as $r): $row = $mine[(string) ($r['id'] ?? '')] ?? null;
            [$status, $cls] = $limits['exempt'] ? ['No Limit', 'muted'] : ($row['max'] === 0 ? ['Not Included', 'warn-text'] : [(int) $row['used'] . '/' . (int) $row['max'] . ' used', $row['used'] < $row['max'] ? 'ok-text' : 'warn-text']); ?>
            <li<?= $cls === 'muted' ? ' class="is-off"' : '' ?>>
              <b class="<?= $cls ?>"><?= e($status) ?></b>
              <span><?= e(m3d_rule_text($r, true)) ?><?php if (!$limits['exempt'] && $row['used'] >= $row['max'] && $row['next']): ?><span class="muted small acc-limit-next">Next: <?= when_html((int) $row['next']) ?></span><?php endif ?></span>
            </li>
          <?php endforeach ?>
        </ul>
      </section>
    <?php endif ?>

    <div class="acc-grid">
      <div class="acc-stat"><span class="muted small">Tier</span><b><?= $owner ? 'All Access' : e(implode(', ', array_map(fn($t) => $t[0] . ($t[1] ? ' (' . m3d_money((int) $t[1], $cur) . ')' : ''), $p['tier_list'] ?? [])) ?: 'None') ?></b></div>
      <div class="acc-stat"><span class="muted small">Consecutive Months as a Patron</span><b><?= $months !== null ? (int) $months : '—' ?></b><?php if (!empty($p['since'])): ?><span class="muted small">since <?= e($date($p['since'])) ?></span><?php endif ?></div>
      <div class="acc-stat"><span class="muted small">Total Support</span><b><?= $known ? e(m3d_money((int) $p['total_cents'], $cur)) : '—' ?></b></div>
      <div class="acc-stat"><span class="muted small">Current Pledge</span><b><?= !empty($p['entitled_cents']) ? e(m3d_money((int) $p['entitled_cents'], $cur)) : '—' ?></b><?php if ($cadence): ?><span class="muted small"><?= e($cadence) ?></span><?php endif ?></div>
    </div>
    </section>

    <details class="acc-sec acc-fold" id="my-models" open>
        <summary><?= section_head('models', 'My Models', 'Everything you’ve unlocked. Download it again whenever you like.', '<span class="upd-count" data-upd-count' . ($newCount ? '' : ' hidden') . '>' . $newCount . ' update' . ($newCount === 1 ? '' : 's') . '</span>') ?></summary>
        <?php if (!$claimed): ?>
          <p class="muted">Nothing yet: the models and accessories you download appear here.</p>
        <?php else: ?>
          <?php // filters (site.js): a chip only when it gives a different, non-empty list than All; none at all without one
            $total = count($claimed);
            $kinds = array_count_values(array_column($claimed, 'kind'));
            $showKinds = count($kinds) > 1;   // Models / Accessories only when there are both
            $showAvail = $availCount > 0 && $availCount < $total;
            $showUpd = $updCount > 0 && $updCount < $total;
            if ($showKinds || $showAvail || $showUpd): ?>
            <div class="chips acc-chips" data-acc-filters>
              <button class="chip" aria-pressed="true" data-acc-show="">All <span><?= $total ?></span></button>
              <?php if ($showKinds): ?><?php if (!empty($kinds['models'])): ?><button class="chip" aria-pressed="false" data-acc-show="models">Models <span><?= $kinds['models'] ?></span></button><?php endif ?><?php if (!empty($kinds['outfits'])): ?><button class="chip" aria-pressed="false" data-acc-show="accessories">Accessories <span><?= $kinds['outfits'] ?></span></button><?php endif ?><?php if (!empty($kinds['scenes'])): ?><button class="chip" aria-pressed="false" data-acc-show="scenes">Scenes <span><?= $kinds['scenes'] ?></span></button><?php endif ?><?php endif ?>
              <?php if ($showAvail): ?><button class="chip" aria-pressed="false" data-acc-show="avail" title="Only what you can download now">Available <span><?= $availCount ?></span></button><?php endif ?>
              <?php if ($showUpd): ?><button class="chip" aria-pressed="false" data-acc-show="upd">Updates <span><?= $updCount ?></span></button><?php endif ?>
            </div>
          <?php endif ?>
          <p class="muted" data-acc-empty hidden>Nothing to show here.</p>
          <div class="table-wrap">
            <table class="acc-table<?= $acts ? ' has-acts-' . $acts : '' ?>">
              <thead><tr><th><?= !empty($kinds['scenes']) ? 'Item' : 'Model / Accessory' ?></th><th class="hide-sm c-mid">Version</th><th class="hide-sm c-mid">Unlocked</th><th class="hide-sm"></th><th></th></tr></thead>
              <tbody>
                <?php foreach ($claimed as $c): ?>
                  <tr data-<?= m3d_kind_word($c['kind'], true) ?><?= $c['avail'] ? ' data-avail' : '' ?><?= $c['update'] !== null ? ' data-upd="' . e($c['kind'] . ':' . $c['slug'] . '@' . $c['update']) . '"' : '' ?><?= $c['ign'] ? ' data-ign' : '' ?><?= $c['off'] ? ' data-off' : '' ?><?= $c['ign'] || $c['off'] ? ' class="is-upd-ignored"' : '' ?>>
                    <td><?= $cell($c) ?></td>
                    <td class="muted nowrap hide-sm c-mid acc-ver"><?= $c['item'] ? e($c['got']) : '' ?></td><?php // their last download's version (phones: under the name; none for items gone) ?>
                    <td class="muted nowrap hide-sm c-mid"><?= e($day($c['first'])) ?></td>
                    <td class="hide-sm c-upd"><?= $c['updTag'] ?></td>
                    <td class="c-actions"><?= $action($c) ?></td>
                  </tr>
                  <?php if ($c['update'] !== null):
                    $newer = m3d_newer_versions($c['item'], $c['got'], (int) $c['lastT']); ?>
                    <tr class="upd-row" hidden><td colspan="5">
                      <div class="upd-head">
                        <p class="upd-since"><?= count($newer) === 1 ? 'What’s new' : count($newer) . ' new versions' ?> since <?= $c['got'] !== '' ? e($c['got']) : 'your download' ?></p>
                        <button type="button" class="btn btn-ghost btn-sm" data-upd-ignore title="Show the update tag again"<?= $c['ign'] && !$c['off'] ? '' : ' hidden' ?>>Stop Ignoring</button>
                      </div>
                      <ol class="log"><?= changelog_items($newer, m3d_pics_hidden($c['kind'], $c['item'])) ?></ol>
                    </td></tr>
                  <?php endif ?>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </details>

    <?= showcase_account() ?>

    <?php foreach ($bmRows as [$its, $card, $title, $what]): if (!$its) continue ?>
      <section class="acc-sec" id="bookmarks-<?= e($what) ?>">
        <?= section_head('bookmark', $title, $what === 'models' ? 'You’re notified when they update, and when new Gallery pictures, accessories or scenes come out for them.' : 'You’re notified when they update.') ?>
        <?= like_strip(array_map(fn($it) => $card($it, true), $its), $what) ?>
      </section>
    <?php endforeach ?>
  <?php endif ?>
  </div>
<?php layout_end();
