<?php
// Tiny JSON endpoint used by the header ("Name · Patron" / Admin / Log out) and by the download boxes
// (to tick the extra requirements the visitor meets; 'left': downloads left for the header counter, with ?owned=1 also
// 'left.owned' = the models/outfits this patron already downloaded (catalog check marks); with ?item=<models|outfits>:<slug> also the download limits for that
// item: 'quota', only when some rules apply to this patron). Never cached.
require dirname(__DIR__) . '/app/_init.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
$p = m3d_patron($M3D);
$quota = null;
if ($p && !empty($p['access']) && preg_match('/^(models|outfits):([a-z0-9-]{1,64})$/', (string) ($_GET['item'] ?? ''), $m)) {
    $it = m3d_item($m[1], $m[2]);
    // an extra requirement not met blocks this download first: the box still shows the unlocks left, greyed ('blocked')
    $blocked = $it && !m3d_check_requirements($it, $p)['ok'];
    $c = m3d_check_limits($p, $m[1], $m[2]);
    $rows = $c['rows'];
    if ($rows && !$c['exempt']) {
        $when = fn(int $t) => (new DateTimeImmutable('@' . $t))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('D j M, H:i') . ' (Berlin time)';
        $next = array_filter(array_map(fn($r) => $r['used'] >= $r['max'] ? $r['next'] : null, $rows));
        $quota = ['ok' => $c['ok'], 'blocked' => $blocked, 'free' => $c['free'], 'owned' => $c['owned'], 'next' => $next ? $when(max($next)) : null,
                  'lines' => array_map(fn($r) => ['left' => max(0, $r['max'] - $r['used']), 'max' => $r['max'], 'rule' => ucfirst($r['text'])], $rows)];
    }
}
// header counter: downloads left now, per kind (the tightest rule that counts it); only when rules apply to this patron
$left = null;
if ($p && !empty($p['access']) && ($l = m3d_downloads_left($p)) !== null) {
    $next = array_filter($l['next']);
    $left = ['models' => $l['models'], 'outfits' => $l['outfits'],
             'next' => $next ? (new DateTimeImmutable('@' . min($next)))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('D j M, H:i') . ' (Berlin time)' : null];
    // ?owned=1 (catalogs): what this patron already downloaded = unlocked, free to download again; only when limits apply
    if (!empty($_GET['owned'])) {
        $left['owned'] = ['models' => [], 'outfits' => []];
        foreach (m3d_patron_history($p) as [, $k, $slug]) $left['owned'][$k][$slug] = true;
        $left['owned'] = array_map('array_keys', $left['owned']);
        // and the ones that never count (editor: "Not counted in the download limits"): always free too
        $left['free'] = ['models' => [], 'outfits' => []];
        foreach (array_keys(m3d_free_items()) as $key) { [$k, $slug] = explode(':', $key, 2); $left['free'][$k][] = $slug; }
    }
}
echo json_encode($p
    ? ['loggedIn' => true, 'name' => $p['name'], 'access' => (bool) $p['access'], 'admin' => m3d_is_admin($M3D),
       'owner' => ($p['reason'] ?? '') === 'owner', 'reqPass' => ($p['reason'] ?? '') === 'owner' || !empty($p['exception']['extra']), 'months' => m3d_months_since($p['since'] ?? null), 'totalCents' => (int) ($p['total_cents'] ?? 0), 'totalLabel' => m3d_money((int) ($p['total_cents'] ?? 0), $p['currency'] ?? null),
       'known' => array_key_exists('total_cents', $p), 'quota' => $quota, 'left' => $left,
       // newer versions of what they downloaded, as codes "kind:slug@version": the dot on the account button shows while
       // one isn't in the browser's "seen" list yet (localStorage m3d_seen_updates, filled on My account)
       'updates' => m3d_update_codes(m3d_updates_for($p))]
    : ['loggedIn' => false]);
