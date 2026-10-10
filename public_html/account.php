<?php
// /account/  The logged-in visitor's own Patreon membership as the site sees it: status, tiers, time as a patron,
// total support, which downloads are open to them, what they downloaded (My Models) and likes. Nothing here is stored
// beyond the session (see privacy.php); My Models comes from the download log, likes from likes.json.
require __DIR__ . '/app/_init.php';

$p = m3d_patron($M3D);
$known = $p !== null && array_key_exists('total_cents', $p);

layout_start('My account', 'Your Patreon membership on ' . site_name() . '.', 'page-account');
?>
  <div class="wrap account-page">
  <?php if (!$p): ?>
    <p class="eyebrow">Account</p>
    <h1>My account</h1>
    <section class="acc-card">
      <p class="lead-sm">Log in with Patreon to see your membership and downloads.</p>
      <p><a class="btn btn-patreon" href="/auth/login.php?return=%2Faccount%2F">Log in with Patreon</a></p>
    </section>
  <?php else:
    $owner = ($p['reason'] ?? '') === 'owner';
    $months = m3d_months_since($p['since'] ?? null);
    $cur = $p['currency'] ?? null;
    [$statusLabel, $tone] = match (true) {
        $owner                             => ['Owner (admin)', 'violet'],
        ($p['reason'] ?? '') === 'exception' => ['Downloads unlocked by ' . site_name(), 'violet'],
        !empty($p['access'])               => ['Active patron', 'green'],
        ($p['reason'] ?? '') === 'declined' => ['Payment declined', 'red'],
        ($p['reason'] ?? '') === 'no-tier' => ['Patron without a tier', 'amber'],
        !empty($p['member'])               => [($p['status'] ?? '') === 'former_patron' ? 'Former patron' : 'Not a paying member', 'grey'],
        default                            => ['Not a member', 'grey'],
    };
    $date = fn(?string $iso) => $iso && ($d = date_create_immutable($iso)) ? $d->setTimezone(new DateTimeZone('Europe/Berlin'))->format('j F Y') : '—';
    $day = fn(?int $t) => $t ? (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('j M Y') : '—';   // short, for tables
    $cadence = ['1' => 'every month', '12' => 'every year'][(string) ($p['cadence'] ?? '')] ?? (!empty($p['cadence']) ? 'every ' . m3d_plural((int) $p['cadence'], 'month') : '');

    // Download button for a model/outfit and the link label used: /get/<item>/<n>/ for the patron link with that label
    // today (else the first patron link), so a link replaced in the admin gives the new file. null = removed, or a page this
    // visitor can't open (draft, password not entered): those keep only their name. Blurred items are shown in full, their
    // picture blurred unless this visitor may see it (card_pic(), like on the page)
    $target = function (string $kind, string $slug, string $label) use ($p, $owner): ?array {
        $it = m3d_item($kind, $slug);
        if (!$it || !m3d_can_view($kind, $it)) return null;
        $links = array_values(array_filter($it['links'] ?? [], fn($l) => !empty($l['url'])));
        $n = null;
        foreach ($links as $i => $l) {
            if (($l['access'] ?? 'patrons') !== 'patrons') continue;
            $n ??= $i;
            if (trim((string) ($l['label'] ?? '')) === trim($label)) { $n = $i; break; }
        }
        $check = m3d_check_requirements($it, $p);
        $why = $check['ok'] ? [] : array_map(fn($r) => $r[2], array_filter($check['rows'], fn($r) => !$r[3]));
        if (empty($p['access']) && !$owner) $why[] = 'paid tier needed';
        return ['item' => $it, 'kind' => $kind, 'ok' => !$why, 'why' => $why,
                'href' => $n === null ? null : ($kind === 'outfits' ? '/get/outfit/' : '/get/') . $it['slug'] . '/' . $n . '/'];
    };
    // the name with a small picture (like the admin lists), and under it a Model / accessory-type chip (the person / hanger icons of the
    // unlock counters in the header, site.js) + the version they downloaded last
    $kindIcons = ['models' => '<circle cx="12" cy="8.2" r="4.2"/><path d="M4.5 20.5c.8-4 3.8-6.3 7.5-6.3s6.7 2.3 7.5 6.3"/>',
                  'outfits' => '<path d="M9.8 6.3a2.2 2.2 0 1 1 3 2.05c-.5.2-.8.6-.8 1.1v.55"/><path d="M12 10 3.6 16.1c-.7.5-.35 1.6.5 1.6h15.8c.85 0 1.2-1.1.5-1.6L12 10z"/>'];
    $cell = function (array $c) use ($kindIcons): string {
        [$it, $kind] = [$c['item'], $c['kind']];
        if (!$it) {   // removed, draft or password not entered: the name only (an empty square with the kind's icon keeps the rows alike)
            return '<span class="acc-item acc-gone"><span class="acc-pic acc-pic-ph" aria-hidden="true"><svg viewBox="0 0 24 24">' . $kindIcons[$kind] . '</svg></span><span class="acc-n">' . e($c['name']) . '</span></span>';
        }
        [$src, $hidden] = card_pic($kind, $it);   // blurred copy when this visitor may not see the pictures
        $url = e(m3d_item_url($kind, $it['slug']));
        $pic = '<a class="acc-pic' . ($src !== '' ? ($hidden ? ' is-blurred' : '') : ' acc-pic-ph') . '" href="' . $url . '" tabindex="-1" aria-hidden="true">'
             . ($src !== '' ? '<img src="' . e($src) . '" alt="" loading="lazy" decoding="async" />' : e(mb_substr((string) $it['name'], 0, 1))) . '</a>';
        $sub = '<span class="acc-sub"><span class="acc-kind acc-kind-' . $kind . '"><svg viewBox="0 0 24 24" aria-hidden="true">' . ($kind === 'outfits' ? accessory_icon_paths(m3d_accessory_type($it)) : $kindIcons[$kind]) . '</svg>'
             . ($kind === 'outfits' ? e(m3d_accessory_type($it)) : 'Model') . '</span>' . ($c['got'] !== '' ? '<span class="acc-ver">' . e($c['got']) . '</span>' : '') . $c['updTag'] . '</span>';   // updTag: on phones, in place of the Update column
        // not one big link: the update tag in the line under the name (phones) is a button
        return '<div class="acc-item">' . $pic . '<span><a class="acc-n" href="' . $url . '">' . e($it['name']) . '</a>' . $sub . '</span></div>';
    };
    // the Download button, or "✗ Not eligible (2 months)": what the patron has where they fall short
    $action = fn(array $t) => !$t['item'] ? '' : ($t['ok']
        ? ($t['href'] ? '<a class="btn btn-primary btn-sm" href="' . e($t['href']) . '" rel="nofollow">Download</a>' : '')
        : '<span class="warn-text">✗ Not eligible' . ($t['why'] ? ' (' . e(implode(', ', $t['why'])) . ')' : '') . '</span>');
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
    foreach ($claimed as $key => &$c) {
        $c['update'] = $updates[$c['kind']][$c['slug']] ?? null;
        // the update tag opens the versions released since their download under the row (site.js, [data-upd-toggle])
        $c['updTag'] = $c['update'] === null ? '' : '<button type="button" class="upd-tag" data-upd-toggle aria-expanded="false" title="What\'s new since your download"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/></svg><span class="upd-word">Update · </span>' . e($c['update']) . '<svg class="upd-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></button>';
    }
    unset($c);
    uasort($claimed, fn($a, $b) => ($b['update'] !== null) <=> ($a['update'] !== null));
    $updCount = count(array_filter(array_column($claimed, 'update'), fn($v) => $v !== null));
    foreach ($claimed as &$c) $c['avail'] = $c['item'] && $c['ok'] && $c['href'];   // has a Download button
    unset($c);
    $availCount = count(array_filter(array_column($claimed, 'avail')));
    // download limits (Admin → Access): every rule that is on, with this patron's count for the ones that apply to them
    $limits = m3d_check_limits($p);
    $mine = [];
    foreach ($limits['rows'] as $row) $mine[(string) ($row['rule']['id'] ?? '')] = $row;
    $allRules = array_values(array_filter(m3d_dl_settings()['rules'], fn($r) => !empty($r['on'])));
    $when = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('D j M, H:i');
    $code = m3d_like_code((string) $p['id']);
    $liked = array_values(array_filter(m3d_models(), fn($m) => m3d_can_view('models', $m) && in_array($code, m3d_likes('models')[$m['slug']] ?? [], true)));
    $likedOutfits = array_values(array_filter(m3d_outfits(), fn($o) => m3d_can_view('outfits', $o) && in_array($code, m3d_likes('outfits')[$o['slug']] ?? [], true)));
  ?>
    <section class="acc-card acc-head">   <!-- the page title is the patron's name: everything about the login in one card -->
      <div>
        <p class="eyebrow">My account</p>
        <h1 class="acc-name"><?= e($p['name']) ?></h1>
        <p class="acc-meta"><span class="badge tone-<?= e($tone) ?>"><?= e($statusLabel) ?></span><span class="muted small">Patreon user ID: <code><?= e($p['id'] ?? '') ?></code></span></p>
      </div>
      <div class="acc-actions">
        <a class="btn btn-ghost btn-sm" href="/auth/login.php?return=%2Faccount%2F" title="Fetch your latest membership data from Patreon">Refresh from Patreon</a>
        <a class="btn btn-ghost btn-sm" href="/auth/logout.php?return=%2F">Log out</a>
      </div>
    </section>

    <?php if (!$owner && !$known): ?>
      <p class="notice">Your login is from before these details were available. <a href="/auth/login.php?return=%2Faccount%2F">Refresh from Patreon</a> to see them.</p>
    <?php endif ?>

    <section class="acc-grid acc-dl">   <!-- downloads first: access, then what the limits leave -->
      <div class="acc-stat"><span class="muted small">Downloads</span><b class="<?= $owner || !empty($p['access']) ? 'ok-text' : 'warn-text' ?>"><?= $owner || !empty($p['access']) ? 'Unlocked' : 'Locked' ?></b><span class="muted small"><?= ($p['reason'] ?? '') === 'exception' ? 'by exception ✓' : ($owner || !empty($p['access']) ? 'paid tier ✓' : 'a paid tier is needed') ?></span></div>
      <?php if ($owner || !empty($p['access'])): $dlLeft = m3d_downloads_left($p);   // downloads left now, per kind (null = no rule: unlimited)
        foreach (['models' => 'Model unlocks left', 'outfits' => 'Accessory unlocks left'] as $k => $lbl): $n = $dlLeft[$k] ?? null; ?>
        <?php $tag = $n === null ? 'div' : 'a';   // with rules the whole box links to (and highlights) the Unlock limits ?>
        <<?= $tag ?> class="acc-stat<?= $n === null ? '' : ' acc-link' ?>"<?= $n === null ? '' : ' href="#limits"' ?>><span class="muted small"><?= $lbl ?></span><b class="<?= $n === 0 ? 'warn-text' : 'ok-text' ?>"><?= $n === null ? 'Unlimited' : $n ?></b><?php if ($n === 0 && !empty($dlLeft['next'][$k])): ?><span class="muted small">next: <?= e((new DateTimeImmutable('@' . $dlLeft['next'][$k]))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('D j M, H:i')) ?></span><?php elseif ($n !== null): ?><span class="muted small acc-link-hint">see the limits →</span><?php endif ?></<?= $tag ?>>
      <?php endforeach; endif ?>
    </section>
    <?php // a card right under the downloads row: the "left" boxes scroll to it and light it up (site.js) ?>
    <?php if ($allRules): ?>
      <section class="acc-card acc-limits" id="limits">
        <h2>Unlock limits</h2>
        <?php if ($limits['exempt']): ?>
          <p class="ok-text">✓ These limits don't apply to you<?= $owner ? ' (admin)' : '' ?>.</p>
        <?php endif ?>
        <ul class="acc-limit-list">   <!-- every row alike: status ("0/1 used", "Doesn’t apply", "No limit") | the rule, with when the next unlock frees up under it -->
          <?php foreach ($allRules as $r): $row = $mine[(string) ($r['id'] ?? '')] ?? null;
            [$status, $cls] = !$row ? ['Doesn’t apply', 'muted'] : ($limits['exempt'] ? ['No limit', 'muted'] : [(int) $row['used'] . '/' . (int) $row['max'] . ' used', $row['used'] < $row['max'] ? 'ok-text' : 'warn-text']); ?>
            <li<?= $cls === 'muted' ? ' class="is-off"' : '' ?>>
              <b class="<?= $cls ?>"><?= e($status) ?></b>
              <span><?= e(m3d_rule_text($r, true)) ?><?php if ($row && !$limits['exempt'] && $row['used'] >= $row['max'] && $row['next']): ?><span class="muted small acc-limit-next">Next: <?= e($when($row['next'])) ?> (Berlin)</span><?php endif ?></span>
            </li>
          <?php endforeach ?>
        </ul>
      </section>
    <?php endif ?>

    <section class="acc-grid">
      <div class="acc-stat"><span class="muted small">Tier</span><b><?= $owner ? 'All access' : e(implode(', ', array_map(fn($t) => $t[0] . ($t[1] ? ' (' . m3d_money((int) $t[1], $cur) . ')' : ''), $p['tier_list'] ?? [])) ?: 'None') ?></b></div>
      <div class="acc-stat"><span class="muted small">Consecutive months as a patron</span><b><?= $months !== null ? (int) $months : '—' ?></b><?php if (!empty($p['since'])): ?><span class="muted small">since <?= e($date($p['since'])) ?></span><?php endif ?></div>
      <div class="acc-stat"><span class="muted small">Total support</span><b><?= $known ? e(m3d_money((int) $p['total_cents'], $cur)) : '—' ?></b></div>
      <div class="acc-stat"><span class="muted small">Current pledge</span><b><?= !empty($p['entitled_cents']) ? e(m3d_money((int) $p['entitled_cents'], $cur)) : '—' ?></b><?php if ($cadence): ?><span class="muted small"><?= e($cadence) ?></span><?php endif ?></div>
    </section>

    <details class="acc-sec acc-fold" id="my-models" open data-updates-seen="<?= e(json_encode(m3d_update_codes($updates))) ?>">   <!-- site.js: opening My account marks these updates as seen (the dot goes) -->
        <summary><h2>My Models</h2><?php if ($updCount): ?><span class="upd-count"><?= $updCount ?> update<?= $updCount === 1 ? '' : 's' ?></span><?php endif ?></summary>
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
              <?php if ($showKinds): ?><button class="chip" aria-pressed="false" data-acc-show="models">Models <span><?= $kinds['models'] ?></span></button><button class="chip" aria-pressed="false" data-acc-show="accessories">Accessories <span><?= $kinds['outfits'] ?></span></button><?php endif ?>
              <?php if ($showAvail): ?><button class="chip" aria-pressed="false" data-acc-show="avail" title="Only what you can download now">Available <span><?= $availCount ?></span></button><?php endif ?>
              <?php if ($showUpd): ?><button class="chip" aria-pressed="false" data-acc-show="upd">Updates <span><?= $updCount ?></span></button><?php endif ?>
            </div>
          <?php endif ?>
          <p class="muted" data-acc-empty hidden>Nothing to show here.</p>
          <div class="table-wrap">
            <table class="acc-table">
              <thead><tr><th>Model / accessory</th><th class="hide-sm">Unlocked</th><th class="hide-sm"></th><th></th></tr></thead>
              <tbody>
                <?php foreach ($claimed as $c): ?>
                  <tr data-<?= $c['kind'] === 'outfits' ? 'accessories' : 'models' ?><?= $c['avail'] ? ' data-avail' : '' ?><?= $c['update'] !== null ? ' data-upd' : '' ?>>
                    <td><?= $cell($c) ?></td>
                    <td class="muted nowrap hide-sm"><?= e($day($c['first'])) ?></td>
                    <td class="hide-sm c-upd"><?= $c['updTag'] ?></td>
                    <td class="c-actions"><?= $action($c) ?></td>
                  </tr>
                  <?php if ($c['update'] !== null):
                    $newer = m3d_newer_versions($c['item'], $c['got'], (int) $c['lastT']);
                    if (!empty($c['sim'])) $newer = m3d_preview_versions($c['item']);   // preview: two versions, examples where the changelog has fewer ?>
                    <tr class="upd-row" hidden><td colspan="4">
                      <p class="upd-since"><?= count($newer) === 1 ? 'What’s new' : count($newer) . ' new versions' ?> since <?= $c['got'] !== '' ? e($c['got']) : 'your download' ?></p>
                      <ol class="log"><?= changelog_items($newer) ?></ol>
                    </td></tr>
                  <?php endif ?>
                <?php endforeach ?>
              </tbody>
            </table>
          </div>
        <?php endif ?>
      </details>

    <?php if ($liked): ?>
      <section class="acc-sec">
        <h2 class="acc-liked"><?= HEART_ICON ?>Models liked</h2>
        <div class="grid acc-likes"><?php foreach ($liked as $m) echo model_card($m); ?></div>
      </section>
    <?php endif ?>

    <?php if ($likedOutfits): ?>
      <section class="acc-sec">
        <h2 class="acc-liked"><?= HEART_ICON ?>Accessories liked</h2>
        <div class="ogrid acc-likes"><?php foreach ($likedOutfits as $o) echo outfit_card($o); ?></div>
      </section>
    <?php endif ?>
  <?php endif ?>
  </div>
<?php layout_end();
