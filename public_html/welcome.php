<?php
// /welcome/  The tour shown once to a paying patron after their first login (app/welcome.php; Admin → Settings → Features →
// Welcome Page). ?return= is where they were going: the main button continues there. Every other link opens a new tab, so the
// page stays open. Admins can always open it (the Settings box's Preview); patrons can open it again from My account.
require __DIR__ . '/app/_init.php';

$admin = m3d_is_admin($M3D);
if (!m3d_welcome_on() && !$admin) not_found();
$p = m3d_patron($M3D);
$return = m3d_safe_return($_GET['return'] ?? null, '/');
if (str_starts_with($return, '/welcome')) $return = '/';
if (!$p) m3d_redirect('/auth/login.php?return=' . rawurlencode('/welcome/' . ($return !== '/' ? '?return=' . rawurlencode($return) : '')));
$owner = ($p['reason'] ?? '') === 'owner';
if (empty($p['access']) && !$owner) m3d_redirect('/account/');   // no paid tier: My account says why
header('X-Robots-Tag: noindex');

$new = ' target="_blank" rel="noopener"';   // every link but the main button: the tour stays open
$first = trim(explode(' ', trim((string) ($p['name'] ?? '')))[0] ?? '');
$cfg = m3d_dl_settings();
$wait = $cfg['wait'];
$updUnlock = $cfg['updates'];

// the membership card: status, tier, unlocks left per kind (the tightest rule that counts it, like the header)
[$statusLabel, $tone] = match (true) {
    $owner                               => ['Owner (Admin)', 'violet'],
    ($p['reason'] ?? '') === 'exception' => ['Downloads Unlocked by ' . site_name(), 'violet'],
    default                              => ['Active Patron', 'green'],
};
$counts = m3d_patron_counts($p);   // with a "Custom" exception's tier / months
$tier = $counts['tier'];
$unlocks = [];   // kind => [label, value, note]
$ownRules = !$owner && ($p['exception']['limits'] ?? '') === 'custom';   // their own unlock rules (Admin → Access) replace the general ones
$ruleRows = $ownRules ? m3d_welcome_own_rows((array) ($p['exception']['rules'] ?? [])) : m3d_welcome_rule_rows();
if ($ruleRows) {
    foreach (['models' => 'Model Unlocks', 'outfits' => 'Accessory Unlocks'] as $kind => $lbl) {
        $c = m3d_check_limits($p, $kind, '');
        if ($c['exempt']) { $unlocks[$kind] = [$lbl, 'Unlimited', '']; continue; }
        $tight = m3d_tightest_row($c['rows']);
        $unlocks[$kind] = $tight ? [$lbl, max(0, $tight['max'] - $tight['used']) . ' of ' . $tight['max'] . ' left', 'per ' . m3d_rule_period($tight['rule'])[1]]
                                 : [$lbl, 'Unlimited', ''];
    }
    if (($unlocks['outfits'][1] ?? '') === 'Unlimited' && ($unlocks['models'][1] ?? '') === 'Unlimited') unset($unlocks['outfits']);
}
$mine = m3d_check_limits($p);   // every rule that applies to this patron, whatever it counts
$exempt = $owner || $mine['exempt'];
// what a first download uses an unlock for: "model", "model or accessory", or nothing (no rule counts this patron)
$limitedKinds = array_keys(array_filter($unlocks, fn($u) => $u[1] !== 'Unlimited'));
$unlockWhat = $exempt ? '' : implode(' or ', array_map(fn($k) => DL_WHAT[$k][0], $limitedKinds));
$months = (int) ($counts['months'] ?? 0);
// the patron's rows: their tier's when it has rules of its own, else the "Any Tier" ones (no tier found too), like m3d_rules_for();
// the owner sees every tier's (the Tier column, only when a rule is for one tier). No rows when nothing limits this patron.
$tierRules = in_array(true, array_map(fn($r) => $r['tier'] !== null, $ruleRows), true);
$myTierRow = $tier && in_array($tier[1], array_column($ruleRows, 'tier'), true) ? $tier[1] : null;
if (!$owner) {
    $ruleRows = $exempt ? [] : array_values(array_filter($ruleRows, fn($r) => $r['tier'] === $myTierRow));
    if (!array_filter($ruleRows, fn($r) => $r['models'] || $r['outfits'])) $ruleRows = [];
}
$tierCol = $owner && $tierRules;

// what the site has: help links, and the points under the rules
$help = array_filter([
    'Docs' => m3d_has_docs() ? '/docs/' : '',
    'FAQ' => m3d_has_faq() ? '/faq/' : '',
    'Bug Reports' => m3d_bugs_on() ? '/bugs/' : '',
    'Discord' => social_url('discord'),
]);
$guides = array_intersect_key($help, ['Docs' => 1, 'FAQ' => 1]);
$hasFree = m3d_free_items() !== [];
$hasReq = false;
foreach (['models', 'outfits'] as $k) foreach (m3d_listed_items($k) as $it) if (m3d_has_requirements($it) && m3d_release_phase($it) !== 'free') { $hasReq = true; break 2; }
$start = array_values(array_filter((array) (m3d_settings()['start']['models']['links'] ?? []), fn($l) => is_array($l) && ($l['url'] ?? '') !== ''));
// MustardUI's page (Settings → Features → MustardUI) first, unless a Getting Started link already goes there
if (m3d_mustardui_on() && !array_filter($start, fn($l) => rtrim((string) $l['url'], '/') === '/mustardui')) array_unshift($start, ['label' => 'MustardUI', 'url' => '/mustardui/']);
$startLine = trim(m3d_welcome()['start']);

// the main button: back to where they were going (an item: by its name), else the catalog
$main = ['/', 'Start Browsing'];
$second = ['/account/', 'Go to My Account'];
if ($return !== '/') {
    $main = [$return, 'Continue'];
    $kind = $slug = null;
    if (preg_match('#^/(?:accessories|get/outfit)/([a-z0-9-]+)/#', $return, $m)) [$kind, $slug] = ['outfits', $m[1]];
    elseif (preg_match('#^/(?:models|get)/([a-z0-9-]+)/#', $return, $m)) [$kind, $slug] = ['models', $m[1]];
    if ($kind && ($it = m3d_item($kind, $slug)) && m3d_can_view($kind, $it)) {
        $main = [$return, 'Continue to ' . ($kind === 'models' ? m3d_model_name($it) : (string) $it['name'])];
        $second = ['/', 'Browse All Characters'];
    }
}

$ico = fn(string $d) => '<span class="wl-ic"><svg viewBox="0 0 24 24" aria-hidden="true">' . $d . '</svg></span>';
$links = fn(array $l) => '<div class="wl-links">' . implode('', array_map(fn($t, $u) => '<a href="' . e($u) . '"' . $new . '>' . e($t) . (str_starts_with($u, 'http') ? ' ↗' : '') . '</a>', array_keys($l), $l)) . '</div>';
$and = fn(array $w) => count($w) > 1 ? implode(', ', array_slice($w, 0, -1)) . ' and ' . end($w) : (string) ($w[0] ?? '');

layout_start('Welcome', 'Welcome to ' . site_name() . '.', 'page-welcome');
// seen (after layout_start(): the maintenance page stops before this): not shown again after the next login
if (empty($p['preview']) && !$owner) m3d_welcome_mark((string) $p['id']);
?>
  <div class="wrap welcome">
    <section class="wl-hero">
      <div>
        <p class="eyebrow">Welcome</p>
        <h1><?= $first !== '' ? 'Hi ' . e($first) . ', you’re in.' : 'You’re in.' ?></h1>
        <div class="lead wl-lead"><?= md_html(m3d_welcome_text()) ?></div>
      </div>
      <aside class="wl-me">
        <div class="wl-me-h"><b>Your Membership</b><span class="badge tone-<?= e($tone) ?>"><?= e($statusLabel) ?></span></div>
        <dl>
          <?php if ($tier): ?><dt>Tier</dt><dd><?= e(m3d_tier_label($tier[1])) ?></dd><?php endif ?>
          <dt>Downloads</dt><dd class="ok">Unlocked ✓</dd>
          <?php foreach ($unlocks as [$lbl, $val, $note]): ?>
            <dt><?= e($lbl) ?></dt><dd><?= e($val) ?><?php if ($note !== ''): ?> <span class="muted">· <?= e($note) ?></span><?php endif ?></dd>
          <?php endforeach ?>
        </dl>
        <?php if ($ruleRows): ?><a href="#rules">See the Download Rules ↓</a><?php else: ?><a href="/account/"<?= $new ?>>See My Account</a><?php endif ?>
      </aside>
    </section>

    <section class="wl-sec">
      <h2>How It Works</h2>
      <div class="wl-steps">
        <article class="wl-step">
          <div class="wl-step-top"><?= $ico('<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>') ?><span class="wl-n">01</span></div>
          <h3>Find a Character</h3>
          <p>Browse the <b>Characters</b> and <b>Accessories</b> catalogs, or <span class="wl-kb">press <kbd>/</kbd> anywhere to search.</span><span class="wl-tap">tap the magnifier to search.</span></p>
          <?= $links(array_filter(['Characters' => '/', 'Accessories' => '/accessories/', 'Gallery' => m3d_has_gallery() ? '/gallery/' : ''])) ?>
        </article>
        <article class="wl-step">
          <div class="wl-step-top"><?= $ico('<path d="M12 4v11"/><path d="m7 10.5 5 5 5-5"/><path d="M5 19.5h14"/>') ?><span class="wl-n">02</span></div>
          <h3>Download</h3>
          <p>Each page has a <b>Download</b> box. <?= $wait > 0 ? 'Wait ' . m3d_plural($wait, 'second') . ' and your file opens.' : 'Your file opens at once.' ?>
            <?php if ($unlockWhat !== ''): ?>A new <?= e($unlockWhat) ?> uses one unlock; <?= $updUnlock ? 'so does a new version of one you have. <b>The same version again is always free.</b>' : '<b>downloading it again is always free</b>, updates too.' ?><?php endif ?></p>
        </article>
        <article class="wl-step">
          <div class="wl-step-top"><?= $ico('<path d="M4 12a8 8 0 0 1 13.7-5.6L20 8.5"/><path d="M20 4v4.5h-4.5"/><path d="M20 12a8 8 0 0 1-13.7 5.6L4 15.5"/><path d="M4 20v-4.5h4.5"/>') ?><span class="wl-n">03</span></div>
          <h3>Get Updates</h3>
          <p><b>My Account</b> lists everything you downloaded. A dot on your name means a new version is out.</p>
          <?= $links(['My Account' => '/account/', 'What’s New' => '/whats-new/']) ?>
        </article>
        <article class="wl-step">
          <div class="wl-step-top"><?= $ico('<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.4a2.5 2.5 0 1 1 3.4 2.3c-.6.3-1 .8-1 1.5v.6"/><path d="M12 16.9v.1"/>') ?><span class="wl-n">04</span></div>
          <h3>Support</h3>
          <p><?php if ($guides): ?>Guides for installing and using the models are in the <?= $and(array_map(fn($t) => '<b>' . e($t) . '</b>', array_keys($guides))) ?>. <?php endif ?><?= isset($help['Bug Reports']) || isset($help['Discord']) ? 'Something broken? Tell us.' : 'Questions? Get in touch.' ?></p>
          <?= $links($help ?: ['Contacts' => '/contacts/']) ?>
        </article>
      </div>
    </section>

    <?php if ($ruleRows): ?>
    <section class="wl-sec" id="rules">
      <h2>Download Rules</h2>
      <?php if ($ownRules): ?><p class="wl-rules-for">These rules were set just for you.</p>
      <?php elseif ($owner && $tierRules): ?><p class="wl-rules-for muted">Patrons see only the rules of their own tier.</p>
      <?php elseif ($tierRules && $myTierRow !== null): ?><p class="wl-rules-for">For your tier, <b><?= e(m3d_tier_label($tier[1])) ?></b>.</p>
      <?php elseif ($tierRules && $tier): ?><p class="wl-rules-for">Your tier has no rules of its own, so the general rules apply.</p>
      <?php elseif ($tierRules && ($p['reason'] ?? '') === 'exception'): ?><p class="wl-rules-for">You have no paid tier, so the general rules apply.</p>
      <?php elseif ($tierRules): ?><p class="wl-rules-for">Your Patreon tier wasn’t found, so the general rules apply. <a rel="nofollow" href="/auth/login.php?return=%2Faccount%2F"<?= $new ?>>Refresh from Patreon</a></p>
      <?php endif ?>
      <div class="wl-rules">
        <table class="wl-table">
          <thead><tr><th><?= $ownRules ? 'Your Rules' : ($tierCol ? 'Tier · Time as a Patron' : 'Time as a Patron') ?></th><th>New Models</th><th>New Accessories</th></tr></thead>
          <tbody>
          <?php foreach ($ruleRows as $r): $me = !$exempt && $r['tier'] === $myTierRow && $months >= $r['from'] && ($r['to'] === null || $months < $r['to']); ?>
            <tr<?= $me ? ' class="is-me"' : '' ?>>
              <th scope="row"><?php if ($tierCol): ?><span class="wl-tier"><?= e($r['tier'] === null ? 'Other Tiers' : m3d_tier_label($r['tier'])) ?></span><?php endif ?><?= e($r['label']) ?><?php if ($me): ?> <span class="wl-you">You</span><?php endif ?></th>
              <?php foreach (['models' => 'New Models', 'outfits' => 'New Accessories'] as $k => $col): ?>
                <td data-k="<?= e($col) ?>"><?= $r[$k] ? e(implode(' · ', $r[$k])) : '<span class="wl-free">No limit</span>' ?></td>
              <?php endforeach ?>
            </tr>
          <?php endforeach ?>
          </tbody>
        </table>
        <ul class="wl-facts">
          <?php if ($updUnlock): ?>
            <li><b>New versions use an unlock.</b> When a model you have gets an update (the “↑ Update” tag), downloading it uses one unlock. The same version again is always free.</li>
          <?php else: ?>
            <li><b>Downloaded once, yours to keep.</b> Downloading a model again is always free, updates included.</li>
          <?php endif ?>
          <li><b>A green ✓ on a card means no unlock needed.</b> <?= $updUnlock ? 'You have its latest version' : 'You already have it' ?><?= $hasFree ? ', or it’s free for everyone.' : '.' ?></li>
          <?php if ($hasReq): ?><li><b>Some models ask for more.</b> A higher tier or more months: their Download box says so.</li><?php endif ?>
        </ul>
      </div>
    </section>
    <?php endif ?>

    <?php if ($start): ?>
    <section class="wl-sec">
      <div class="wl-start">
        <p><b>Before Your First Download</b><?= $startLine !== '' ? md_inline($startLine) : '' ?></p>
        <div class="start-links"><?php foreach ($start as $l): ?><a href="<?= e($l['url']) ?>"<?= $new ?>><?= e((string) ($l['label'] ?? $l['url'])) ?></a><?php endforeach ?></div>
      </div>
    </section>
    <?php endif ?>

    <div class="wl-end">
      <a class="btn btn-primary" href="<?= e($main[0]) ?>"><?= e($main[1]) ?></a>
      <a class="btn btn-ghost" href="<?= e($second[0]) ?>"<?= $new ?>><?= e($second[1]) ?></a>
      <span class="muted">You can open this page again from My Account.</span>
    </div>
  </div>
<?php layout_end();
