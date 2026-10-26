<?php
// Users: people with special access, who skip or change some download rules (data/exceptions.json, see m3d_apply_exception()).
// Each of the three is Normal, Bypass or Custom:
//   Tier: Bypass = download with a free Patreon account (or a pledge without a tier, a declined payment); Custom = the same, counting as at least one tier
//   Requirements: Bypass = minimum tier, months as a patron and total support of models and outfits aren't checked; Custom = they count as at least N months / a total
//   Unlock Limits: Bypass ("No Limits") = the unlock limit rules below don't apply to them; Custom = their own rules replace them
// A person's switch (`paused`) turns their Tier / Requirements / Unlock Limits off without deleting them (bans still apply),
// and bans (BAN_KINDS: Log In, Downloads, Bug Reports, Comments, Renders) with a reason shown to them on /banned/.
// The list: one collapsed row per person (name, ID, short tags, date), search `q`, `f` = all | ex | banned, `sort` = name | new | old,
// EX_PER_PAGE a page (`page`); `ex` = the row opened (also "Edit Exception" on the Patron Log), `new` = the Add User box open.
// Three tabs (`tab`, ACCESS_TABS; access_menu()): Downloads = the download settings for everyone (settings.json → downloads, see m3d_dl_settings()): the waiting
// time before a download, the daily cap, the grace period (m3d_pledge_since()) and the download limit rules (several, each can be turned off without deleting it); Exceptions = the list above
// (an address with `ex`, `add`, `new`, `f`, `q`, `sort` or `page` and no tab opens it); Temporary Links (admin/_shares.php).
require __DIR__ . '/_admin.php';
require __DIR__ . '/_shares.php';   // the Temporary Links tab
admin_require($M3D);

/** Rules in reading order: "Any Tier" first, then by tier price, each by months as a patron (from, then to; empty = 0 / no end), so the list goes from new patrons to old ones. */
function dl_rules_sorted(array $rules): array
{
    $key = fn(array $r) => [(int) ($r['tier'] ?? 0), (int) ($r['from'] ?? 0), ($r['to'] ?? '') === '' ? PHP_INT_MAX : (int) $r['to']];
    usort($rules, fn($a, $b) => $key($a) <=> $key($b));
    return $rules;
}

/** A download limit rule from the posted fields (throws on bad values). */
function dl_rule_post(string $id, bool $on): array
{
    $max = (int) ($_POST['max'] ?? 0);
    $what = (string) ($_POST['what'] ?? '');
    $per = (string) ($_POST['per'] ?? '');
    $month = function (string $f): ?int {
        $v = trim((string) ($_POST[$f] ?? ''));
        if ($v === '') return null;
        if (!preg_match('/^\d{1,3}$/', $v)) throw new InvalidArgumentException('Months are whole numbers (or empty for no limit).');
        return (int) $v;
    };
    $from = $month('from') ?: null;   // "at least 0 months" = everyone: saved as empty (else the rule reads "0 months or more")
    $to = $month('to');
    $tier = trim((string) ($_POST['tier'] ?? ''));
    if ($tier !== '' && (!preg_match('/^\d{1,7}$/', $tier) || (int) $tier < 1)) throw new InvalidArgumentException('Pick a tier from the list.');
    if ($max < 1 || $max > 999) throw new InvalidArgumentException('The number of downloads is between 1 and 999.');
    if (!isset(DL_WHAT[$what]) || !isset(DL_PERIODS[$per])) throw new InvalidArgumentException('Pick what the rule counts and the period.');
    if ($from !== null && $to !== null && $to <= $from) throw new InvalidArgumentException('“Less than” months must be more than “at least” months.');
    if ($to === 0) throw new InvalidArgumentException('“Less than 0 months” matches nobody: leave it empty for no upper limit.');
    return ['id' => $id, 'on' => $on, 'max' => $max, 'what' => $what, 'per' => $per, 'from' => $from ?? '', 'to' => $to ?? '', 'tier' => $tier === '' ? '' : (int) $tier];
}

/** Tier / Requirements / Unlock Limits of an exception from the posted fields, with the Custom values of the ones set to Custom (throws on bad values). */
function ex_post(array $old): array
{
    $mode = fn(string $f) => in_array($_POST[$f] ?? '', ['bypass', 'custom'], true) ? (string) $_POST[$f] : '';
    $x = ['tier' => $mode('tier'), 'extra' => $mode('extra'), 'limits' => $mode('limits')];
    $ban = array_values(array_intersect(array_keys(BAN_KINDS), array_map('strval', (array) ($_POST['ban'] ?? []))));
    if ($x['tier'] === '' && $x['extra'] === '' && $x['limits'] === '' && !$ban) throw new InvalidArgumentException('Pick Bypass or Custom for at least one of Tier, Requirements and Unlock Limits, or tick a ban' . ($old ? ', or remove the user.' : '.'));
    $reason = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', mb_scrub(is_string($_POST['ban_reason'] ?? null) ? $_POST['ban_reason'] : '', 'UTF-8')));
    if ($ban) $x['ban'] = $ban;
    if ($ban && $reason !== '') $x['reason'] = mb_substr($reason, 0, BAN_REASON_MAX);
    if ($x['tier'] === 'custom') {
        $c = trim((string) ($_POST['tier_cents'] ?? ''));
        // a known tier, or the one saved before (a tier whose price changed on Patreon stays until another is picked)
        if (!preg_match('/^\d{1,7}$/', $c) || (int) $c < 1 || (!isset(m3d_known_tiers()[(int) $c]) && (int) $c !== (int) ($old['tierCents'] ?? 0))) throw new InvalidArgumentException('Pick the tier they count as.');
        $x['tierCents'] = (int) $c;
    }
    if ($x['extra'] === 'custom') {
        $m = trim((string) ($_POST['months'] ?? ''));
        $t = str_replace(',', '.', trim((string) ($_POST['total'] ?? '')));
        if ($m !== '' && !preg_match('/^\d{1,3}$/', $m)) throw new InvalidArgumentException('Months are a whole number from 0 to 999.');
        if ($t !== '' && !preg_match('/^\d{1,6}(\.\d{1,2})?$/', $t)) throw new InvalidArgumentException('The total support is an amount, e.g. 120 or 49.50.');
        if ($m === '' && $t === '') throw new InvalidArgumentException('Set the months or the total support they count as.');
        if ($m !== '') $x['months'] = (int) $m;
        if ($t !== '') $x['totalCents'] = (int) round((float) $t * 100);
    }
    if ($x['limits'] === 'custom') {
        $rules = [];
        foreach (array_values((array) ($_POST['rmax'] ?? [])) as $i => $max) {
            $what = ((array) ($_POST['rwhat'] ?? []))[$i] ?? '';
            $per = ((array) ($_POST['rper'] ?? []))[$i] ?? '';
            if (!is_string($max) || trim($max) === '') continue;   // an empty new row
            if (!preg_match('/^\d{1,3}$/', trim($max)) || (int) $max < 1) throw new InvalidArgumentException('The number of unlocks is between 1 and 999.');
            if (!is_string($what) || !is_string($per) || !isset(DL_WHAT[$what]) || !isset(DL_PERIODS[$per])) throw new InvalidArgumentException('Pick what each rule counts and the period.');
            $rules[] = ['max' => (int) $max, 'what' => $what, 'per' => $per];
        }
        if (!$rules) throw new InvalidArgumentException('Add at least one unlock rule, or pick No Limits.');
        if (count($rules) > 10) throw new InvalidArgumentException('Up to 10 rules per person.');
        $x['rules'] = $rules;
    }
    // the row's switch (saved people only): off = kept but not applied; nothing to pause without a Bypass / Custom
    if (isset($_POST['has_on']) && empty($_POST['on']) && ($x['tier'] !== '' || $x['extra'] !== '' || $x['limits'] !== '')) $x['paused'] = true;
    return array_filter($x, fn($v) => $v !== '' && $v !== []);
}

const EX_PER_PAGE = 25;
const ACCESS_TABS = ['downloads' => 'Downloads', 'exceptions' => 'Exceptions', 'links' => 'Temporary Links'];

/** The tab bar of Access (the Settings tabs' look): an old address's #users / #links goes to its tab (admin.js, [data-set-tabs]). */
function access_menu(string $current): string
{
    $map = ['wait' => 'downloads', 'cap' => 'downloads', 'grace' => 'downloads', 'downloads' => 'downloads', 'users' => 'exceptions', 'add-user' => 'exceptions', 'links' => 'links'];
    $h = '<nav class="lib-tabs set-tabs" aria-label="Access" data-set-tabs="' . e(json_encode($map)) . '" data-set-page="/admin/users.php" data-set-default="downloads">';
    foreach (ACCESS_TABS as $k => $label) $h .= '<a href="/admin/users.php' . ($k === 'downloads' ? '' : '?tab=' . $k) . '"' . ($current === $k ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    return $h . '</nav>';
}

/** The short tags of a person's row: [text, tone ('' | 'custom' | 'ban')], bans first. */
function ex_tags(array $x): array
{
    $t = [];
    if ($b = m3d_ex_bans($x)) $t[] = ['Banned: ' . (in_array('login', $b, true) ? 'Everything' : implode(', ', array_map(fn($k) => BAN_KINDS[$k][0], $b))), 'ban'];
    $tier = m3d_ex_mode($x['tier'] ?? null);
    if ($tier === 'bypass') $t[] = ['Any Tier', ''];
    if ($tier === 'custom') { $c = (int) ($x['tierCents'] ?? 0); $n = m3d_known_tiers()[$c] ?? ''; $t[] = ['As ' . ($n !== '' ? $n : m3d_money($c) . ' Tier'), 'custom']; }
    $extra = m3d_ex_mode($x['extra'] ?? null);
    if ($extra === 'bypass') $t[] = ['No Requirements', ''];
    if ($extra === 'custom') $t[] = ['As ' . implode(' · ', array_filter([isset($x['months']) ? m3d_plural((int) $x['months'], 'Month') : '', isset($x['totalCents']) ? m3d_money((int) $x['totalCents']) . ' Total' : ''])), 'custom'];
    $limits = m3d_ex_mode($x['limits'] ?? null);
    if ($limits === 'bypass') $t[] = ['No Limits', ''];
    if ($limits === 'custom') foreach (m3d_ex_rules($x) as $r) $t[] = [$r['max'] . ' ' . ucfirst(DL_WHAT[$r['what']][$r['max'] === 1 ? 0 : 1]) . ' / ' . ucwords(DL_PERIODS[$r['per']][1]), 'custom'];
    if (!empty($x['paused'])) {   // switched off: its exception tags greyed after a "Paused" one, the bans as they are
        $bans = array_values(array_filter($t, fn($g) => $g[1] === 'ban'));
        $t = array_merge($bans, [['Paused', 'paused']], array_map(fn($g) => [$g[0], 'off'], array_values(array_filter($t, fn($g) => $g[1] !== 'ban'))));
    }
    return $t;
}

$done = $error = null;
$posted = admin_form_post();
if ($posted && str_starts_with((string) ($_POST['do'] ?? ''), 'share-')) {
    [$done, $error] = share_post((string) $_POST['do']);
} elseif ($posted && str_starts_with((string) ($_POST['do'] ?? ''), 'dl-')) {
    $dl = m3d_dl_settings();
    $rules = $dl['rules'];
    $do = (string) $_POST['do'];
    $rid = (string) ($_POST['rule'] ?? '');
    $index = null;
    foreach ($rules as $i => $r) if ((string) ($r['id'] ?? '') === $rid) $index = $i;
    try {
        if ($do === 'dl-wait') {
            $wait = trim((string) ($_POST['wait'] ?? ''));
            if (!preg_match('/^\d{1,2}$/', $wait) || (int) $wait > 60) throw new InvalidArgumentException('The waiting time is between 0 and 60 seconds.');
            $dl['wait'] = (int) $wait;
            $done = $dl['wait'] ? "Patron downloads now start after {$dl['wait']} seconds." : 'Patron downloads now start right away (no waiting page).';
        } elseif ($do === 'dl-cap') {
            $cap = trim((string) ($_POST['cap'] ?? ''));
            if (!preg_match('/^\d{1,3}$/', $cap)) throw new InvalidArgumentException('The daily download cap is a number from 0 (off) to 999.');
            $dl['cap'] = (int) $cap;
            $done = $dl['cap'] ? 'Each patron can now download ' . m3d_plural($dl['cap'], 'time') . ' in 24 hours, then waits 24 hours.' : 'Daily download cap off.';
        } elseif ($do === 'dl-grace') {
            $grace = trim((string) ($_POST['grace'] ?? ''));
            if (!preg_match('/^\d{1,2}$/D', $grace) || (int) $grace > DL_GRACE_MAX) throw new InvalidArgumentException('The grace period is a number of days from 0 (off) to ' . DL_GRACE_MAX . '.');
            $dl['grace'] = (int) $grace;
            $done = $dl['grace'] ? 'A pledge removed and made again within ' . m3d_plural($dl['grace'], 'day') . ' now keeps its months (from each patron’s next login).' : 'Grace period off: a removed pledge starts the months again.';
        } elseif ($do === 'dl-updates') {
            $dl['updates'] = !empty($_POST['updates']);
            $done = $dl['updates'] ? 'A new version of an item already unlocked now uses another unlock.' : 'New versions of an item already unlocked are free again.';
        } elseif ($do === 'dl-add') {
            $rules[] = $r = dl_rule_post(bin2hex(random_bytes(4)), true);
            $done = 'Rule added: ' . m3d_rule_text($r) . '.';
        } else {
            if ($index === null) throw new InvalidArgumentException('This rule is no longer in the list.');
            if ($do === 'dl-delete') {
                $done = 'Rule deleted: ' . m3d_rule_text($rules[$index]) . '.';
                array_splice($rules, $index, 1);
            } elseif ($do === 'dl-save') {
                $rules[$index] = $r = dl_rule_post($rid, !empty($_POST['on']));
                $done = 'Rule saved' . ($r['on'] ? '' : ' (off)') . ': ' . m3d_rule_text($r) . '.';
            } else {
                throw new InvalidArgumentException('Unknown action.');
            }
        }
        if (!m3d_save_settings(['downloads' => ['wait' => $dl['wait'], 'rules' => dl_rules_sorted($rules)] + ($dl['updates'] ? ['updates' => true] : []) + ($dl['cap'] ? ['cap' => $dl['cap']] : []) + ($dl['grace'] ? ['grace' => $dl['grace']] : [])])) throw new RuntimeException(m3d_write_error());
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
} elseif ($posted) {
    $people = m3d_exceptions(true);
    $id = trim((string) ($_POST['id'] ?? ''));
    $foundName = '';
    if (($_POST['do'] ?? '') === 'add' && !preg_match('/^\d{1,20}$/', $id)) {   // a name, or "Name · ID" picked from the list
        $hits = m3d_find_patrons($id, 2);
        if (count($hits) === 1) [$id, $foundName] = $hits[0];
        elseif ($hits) $error = 'Several people match “' . $id . '”: pick one from the list, or type their Patreon user ID.';
        else $error = 'Nobody called “' . $id . '” has downloaded or written a bug report: type their Patreon user ID (on their My account page).';
    }
    $index = null;
    foreach ($people as $i => $x) if ((string) $x['id'] === $id) $index = $i;
    if ($error === null) try {
        if (!preg_match('/^\d{1,20}$/', $id)) throw new InvalidArgumentException('The Patreon user ID is a number, e.g. 12345678. The person can find it on their “My Account” page of this site.');
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'remove') {
            if ($index === null) throw new InvalidArgumentException('This user is no longer in the list.');
            $name = ($people[$index]['name'] ?? '') ?: $id;
            array_splice($people, $index, 1);
            if (!m3d_save_exceptions($people)) throw new RuntimeException(m3d_write_error('data'));
            $done = "$name removed from the users list.";
        } elseif ($do === 'add' || $do === 'save') {
            if (in_array($id, m3d_owner_ids($M3D), true)) throw new InvalidArgumentException('This ID is an admin (owner_user_ids): admins already pass every check.');
            $x = ['id' => $id, 'name' => mb_substr(trim((string) ($_POST['name'] ?? '')) ?: $foundName, 0, 80), 'note' => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200)]
                + ex_post($index !== null ? $people[$index] : [])
                + ['added' => $index !== null ? ($people[$index]['added'] ?? gmdate('c')) : gmdate('c')];
            $was = $index !== null && !empty($people[$index]['paused']);
            if ($index !== null) $people[$index] = $x; else $people[] = $x;
            if (!m3d_save_exceptions($people)) throw new RuntimeException(m3d_write_error('data'));
            $who = $x['name'] !== '' ? $x['name'] : "ID $id";
            $done = $do === 'add' && $index === null ? "$who added. It counts right away."
                : (!empty($x['paused']) && !$was ? "{$who}’s exception is off: kept, but the normal rules apply" . (m3d_ex_bans($x) ? ' (the bans still do)' : '') . '.'
                : ($was && empty($x['paused']) ? "{$who}’s exception is on again." : "$who saved."));
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$tab = m3d_get_text('tab');
if (!isset(ACCESS_TABS[$tab])) $tab = array_intersect(['ex', 'add', 'new', 'f', 'q', 'sort', 'page'], array_keys($_GET)) ? 'exceptions' : 'downloads';
$people = m3d_exceptions(true);
// the list: filter, search, sort, page (in the address, so a save comes back to the same view)
$f = in_array($_GET['f'] ?? '', ['ex', 'banned'], true) ? (string) $_GET['f'] : 'all';
$q = trim(m3d_get_text('q'));
$sort = in_array($_GET['sort'] ?? '', ['new', 'old'], true) ? (string) $_GET['sort'] : 'name';
$isEx = fn(array $x) => m3d_ex_mode($x['tier'] ?? null) !== '' || m3d_ex_mode($x['extra'] ?? null) !== '' || m3d_ex_mode($x['limits'] ?? null) !== '';
$counts = ['all' => count($people), 'ex' => count(array_filter($people, $isEx)), 'banned' => count(array_filter($people, fn($x) => (bool) m3d_ex_bans($x)))];
$shown = array_values(array_filter($people, function (array $x) use ($f, $q, $isEx) {
    if ($f === 'ex' && !$isEx($x)) return false;
    if ($f === 'banned' && !m3d_ex_bans($x)) return false;
    if ($q === '') return true;
    $hay = m3d_fold(implode(' ', [$x['id'], $x['name'] ?? '', $x['note'] ?? '', $x['reason'] ?? '']));
    foreach (preg_split('/\s+/u', m3d_fold($q)) as $w) if ($w !== '' && !str_contains($hay, $w)) return false;
    return true;
}));
$byName = fn($a, $b) => strcasecmp((string) (($a['name'] ?? '') ?: $a['id']), (string) (($b['name'] ?? '') ?: $b['id']));
usort($shown, $sort === 'name' ? $byName : fn($a, $b) => ($sort === 'old' ? 1 : -1) * (strtotime((string) ($a['added'] ?? '')) <=> strtotime((string) ($b['added'] ?? ''))) ?: $byName($a, $b));
// the row to open: picked from the Patron Log (?ex=), or the one just saved / not saved; its page comes first
$openId = $posted && isset($id) && in_array($_POST['do'] ?? '', ['add', 'save'], true) ? (string) $id : m3d_get_text('ex');
$pages = max(1, (int) ceil(count($shown) / EX_PER_PAGE));
$page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));
if ($openId !== '' && !isset($_GET['page'])) foreach ($shown as $i => $x) if ((string) $x['id'] === $openId) $page = intdiv($i, EX_PER_PAGE) + 1;
$from = ($page - 1) * EX_PER_PAGE;
$pageRows = array_slice($shown, $from, EX_PER_PAGE);
$listUrl = fn(array $set) => '/admin/users.php?' . http_build_query(array_filter(array_merge(['tab' => 'exceptions', 'f' => $f === 'all' ? '' : $f, 'q' => $q, 'sort' => $sort === 'name' ? '' : $sort], $set), fn($v) => $v !== '' && $v !== null && $v !== 1));
$addOpen = isset($_GET['new']) || m3d_get_text('add') !== '' || ($posted && ($_POST['do'] ?? '') === 'add' && $error !== null);
// patrons the site has met: picked by name in the "Patreon user ID or name" field (m3d_find_patrons())
$known = m3d_known_patrons();
$dl = m3d_dl_settings();
$dl['rules'] = dl_rules_sorted($dl['rules']);
$date = fn(string $iso) => m3d_site_time($iso, 'd/m/Y');

admin_start('Access', 'users');
?>
    <div class="admin-head">
      <div><h1>Access</h1></div>
    </div>

    <?= access_menu($tab) ?>

    <?php admin_result($done, $error) ?>

<?php if ($tab === 'downloads'): ?>
    <div class="users-grid">
    <section class="sec" id="wait">
      <h2 class="small-h">Download Countdown<?= tip('Countdown before every patron download. Free downloads (public links) start at once. 0 = off.') ?></h2>
      <form method="post" class="dl-wait-form">
        <?= form_keys() ?>
        <label class="dl-secs"><input class="input input-sm" type="number" name="wait" min="0" max="60" step="1" value="<?= (int) $dl['wait'] ?>" required aria-label="Seconds before each patron download" /> <span class="muted">seconds</span></label>
        <button class="btn btn-primary btn-sm" name="do" value="dl-wait">Save</button>
      </form>
    </section>

    <section class="sec" id="cap">
      <h2 class="small-h">Daily Download Cap<?= tip('Saves file hosting traffic: a patron who downloads this many times within 24 hours (any patron link, downloading the same file again too) can’t download for the next 24 hours. Public links aren’t counted (nobody is logged in). 0 = off.') ?></h2>
      <form method="post" class="dl-wait-form">
        <?= form_keys() ?>
        <label class="dl-secs"><input class="input input-sm" type="number" name="cap" min="0" max="999" step="1" value="<?= (int) $dl['cap'] ?>" required aria-label="Downloads per patron in 24 hours" /> <span class="muted">downloads per patron in 24 hours, then a 24-hour pause</span></label>
        <button class="btn btn-primary btn-sm" name="do" value="dl-cap">Save</button>
      </form>
    </section>

    <section class="sec" id="grace">
      <h2 class="small-h">Grace Period<?= tip('A patron who removes their pledge and pledges again within this many days keeps their months as a patron (6 months, removed 21 Oct, back 22 Oct: still 6 months). Checked at each login; needs the creator’s token (Settings → Patreon). 0 = off.') ?></h2>
      <form method="post" class="dl-wait-form">
        <?= form_keys() ?>
        <label class="dl-secs"><input class="input input-sm" type="number" name="grace" min="0" max="<?= DL_GRACE_MAX ?>" step="1" value="<?= (int) $dl['grace'] ?>" required aria-label="Days between a removed pledge and a new one" /> <span class="muted">days between a removed pledge and a new one</span></label>
        <button class="btn btn-primary btn-sm" name="do" value="dl-grace">Save</button>
      </form>
    </section>

    <section class="sec" id="downloads">
      <h2 class="small-h">Unlock Limits <span class="muted small">(<?= count(array_filter($dl['rules'], fn($r) => !empty($r['on']))) ?> on<?= count($dl['rules']) ? ' of ' . count($dl['rules']) : '' ?>)</span><?= tip('How many different models or accessories a patron can unlock (first download) per period, by tier and months as a patron. Every matching rule must allow it; a patron’s own tier rules replace the “Any Tier” rules whose kinds they all count (a tier rule for models replaces “Any Tier” model rules, not rules for models and accessories together). Downloading again is free (new versions too, unless “Updates Use an Unlock” is on); items set to “Exclude from Unlock Limits” never count.') ?></h2>
      <?php if (empty($M3D['log_downloads'])): ?><p class="notice"><b class="res-err">Limits are not counted:</b> the download log is off (<code>log_downloads</code> in config.php), and limits are counted from it.</p><?php elseif ((int) ($M3D['log_retention_days'] ?? 365) < 30): ?><p class="notice">The download log keeps only <?= (int) $M3D['log_retention_days'] ?> days (<code>log_retention_days</code>): 30-day limits can't see older downloads.</p><?php endif ?>
      <?php
        // one rule as a table row: [Any Tier] patrons with [1] – [3] months · can unlock [2] [models] · every [30 days]
        // tiers: the campaign's known ones (m3d_known_tiers()), plus a rule's own when it's no longer among them
        $tiers = m3d_known_tiers();
        foreach ($dl['rules'] as $r) if ((int) ($r['tier'] ?? 0) > 0 && !isset($tiers[(int) $r['tier']])) $tiers[(int) $r['tier']] = '';
        ksort($tiers);
        $ruleFields = function (string $f, array $r) use ($tiers): void { ?>
            <span class="rule-cell rule-tier" data-lbl="Tier">
              <select class="input input-sm" form="<?= $f ?>" name="tier" aria-label="Tier"><option value="">Any Tier</option><?php foreach ($tiers as $c => $t): ?><option value="<?= $c ?>" <?= (int) ($r['tier'] ?? 0) === $c ? 'selected' : '' ?>><?= e($t !== '' ? $t . ' (' . m3d_money($c) . ')' : m3d_money($c) . ' Tier') ?></option><?php endforeach ?></select>
            </span>
            <span class="rule-cell rule-who" data-lbl="Patron Months">
              <input class="input input-sm rule-num" form="<?= $f ?>" type="number" name="from" min="0" max="999" value="<?= e((string) ($r['from'] ?? '')) ?>" placeholder="0" aria-label="At least this many months" />
              <span class="muted">–</span>
              <input class="input input-sm rule-num" form="<?= $f ?>" type="number" name="to" min="1" max="999" value="<?= e((string) ($r['to'] ?? '')) ?>" placeholder="∞" aria-label="Less than this many months" />
              <span class="muted">months</span>
            </span>
            <span class="rule-cell rule-what" data-lbl="Max Unlocks">
              <input class="input input-sm rule-num" form="<?= $f ?>" type="number" name="max" min="1" max="999" value="<?= (int) ($r['max'] ?? 1) ?>" required aria-label="How many" />
              <select class="input input-sm" form="<?= $f ?>" name="what" aria-label="What counts"><?php foreach (DL_WHAT as $k => [$one, $many]): ?><option value="<?= e($k) ?>" <?= ($r['what'] ?? 'models') === $k ? 'selected' : '' ?>><?= e($k === 'all' ? 'Models + Accessories' : ucfirst($many)) ?></option><?php endforeach ?></select>
            </span>
            <span class="rule-cell rule-per" data-lbl="Period">
              <select class="input input-sm" form="<?= $f ?>" name="per" aria-label="Period"><?php foreach (DL_PERIODS as $k => [$secs, $label]): ?><option value="<?= e($k) ?>" <?= ($r['per'] ?? 'week') === $k ? 'selected' : '' ?>><?= e(ucwords($label)) ?></option><?php endforeach ?></select>
            </span>
      <?php }; ?>
      <div class="rules">
        <div class="rule-row rule-head" aria-hidden="true"><span></span><span>Tier</span><span>Patron Months</span><span>Max Unlocks</span><span>Period</span><span></span></div>
        <?php foreach ($dl['rules'] as $i => $r): $f = 'rule-' . $i; ?>
          <div class="rule-row<?= empty($r['on']) ? ' rule-off' : '' ?>">
            <label class="rule-on" title="On/off: saved at once"><input type="checkbox" class="switch" form="<?= $f ?>" name="on" value="1" <?= !empty($r['on']) ? 'checked' : '' ?> onchange="this.form.querySelector('[value=dl-save]').click()" aria-label="Rule on" /></label>
            <?php $ruleFields($f, $r) ?>
            <form id="<?= $f ?>" method="post" class="rule-btns"><?= form_keys() ?><input type="hidden" name="rule" value="<?= e((string) ($r['id'] ?? '')) ?>" />
              <button class="btn btn-primary btn-sm" name="do" value="dl-save">Save</button>
              <button class="icon-btn del rule-del" name="do" value="dl-delete" formnovalidate title="Delete this rule" aria-label="Delete this rule" onclick="return confirm(<?= e(json_encode('Delete the rule “' . m3d_rule_text($r) . '”? To pause it, turn it off instead.')) ?>)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></button>
            </form>
          </div>
        <?php endforeach ?>
        <?php if (!$dl['rules']): ?><p class="muted small rules-empty">No limits yet: patrons can unlock as many models and accessories as they like.</p><?php endif ?>
        <div class="rule-row rule-new">
          <span class="rule-on"><button class="rule-add" form="rule-new" name="do" value="dl-add" title="Add this rule" aria-label="Add this rule"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button></span>
          <?php $ruleFields('rule-new', ['max' => 1, 'what' => 'models', 'per' => 'week']) ?>
          <form id="rule-new" method="post" class="rule-btns"><?= form_keys() ?></form>
        </div>
      </div>
      <form method="post" class="dl-updates-form">
        <?= form_keys('dl-updates') ?>
        <label class="toggle"><input type="checkbox" class="switch" name="updates" value="1" <?= $dl['updates'] ? 'checked' : '' ?> onchange="this.form.submit()" /><span>Updates Use an Unlock<?= tip('Off: once a patron unlocked an item, every new version of it is free. On: downloading a new version uses another unlock, like a new item. Saved at once.') ?></span></label>
      </form>
    </section>
    </div>
<?php elseif ($tab === 'exceptions'): ?>
    <section class="sec" id="users">
      <?php
        $exHelp = ['tier' => 'Bypass: can download with a free Patreon account. Custom: the same, and counts as at least the tier you pick.',
                   'extra' => 'Bypass: minimum tier, months and total support aren’t checked. Custom: counts as at least the months and total support you set (the months also pick the unlock rules by months).',
                   'limits' => 'No Limits: the unlock limits don’t apply. Custom: their own rules replace the general ones.'];
        // tiers to count as: the campaign's known ones, plus a person's own when it's no longer among them
        $exTiers = m3d_known_tiers();
        foreach ($people as $x) if (($c = (int) ($x['tierCents'] ?? 0)) > 0 && !isset($exTiers[$c])) $exTiers[$c] = '';
        ksort($exTiers);
        $cur = preg_replace('/[\d.,\s]+/u', '', m3d_money(100));   // "€", or "CHF"
        $ruleRow = function (array $r): void { ?>
            <div class="ex-c-rule" data-ex-rule>
              <input class="input input-sm rule-num" type="number" name="rmax[]" min="1" max="999" value="<?= e((string) ($r['max'] ?? '')) ?>" aria-label="How many" />
              <select class="input input-sm" name="rwhat[]" aria-label="What counts"><?php foreach (DL_WHAT as $k => [$one, $many]): ?><option value="<?= e($k) ?>" <?= ($r['what'] ?? 'models') === $k ? 'selected' : '' ?>><?= e($k === 'all' ? 'Models + Accessories' : ucfirst($many)) ?></option><?php endforeach ?></select>
              <span class="muted">every</span>
              <select class="input input-sm" name="rper[]" aria-label="Period"><?php foreach (DL_PERIODS as $k => [$secs, $label]): ?><option value="<?= e($k) ?>" <?= ($r['per'] ?? 'week') === $k ? 'selected' : '' ?>><?= e(ucwords($label)) ?></option><?php endforeach ?></select>
              <button type="button" class="icon-btn del rule-del" data-ex-rule-del title="Delete this rule" aria-label="Delete this rule"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg></button>
            </div>
        <?php };
        // the three menus (+ the note on a saved person) and the Custom box under them: its lines show only for the menus set to Custom (admin.js)
        $exFields = function (array $x, bool $note) use ($exHelp, $exTiers, $cur, $ruleRow): void {
            $m = ['tier' => m3d_ex_mode($x['tier'] ?? null), 'extra' => m3d_ex_mode($x['extra'] ?? null), 'limits' => m3d_ex_mode($x['limits'] ?? null)];
            $any = in_array('custom', $m, true); ?>
          <div class="ex-opts">
            <?php foreach (['tier' => 'Tier', 'extra' => 'Requirements', 'limits' => 'Unlock Limits'] as $f => $lbl): ?>
              <label class="ex-opt"><span class="ex-lbl"><?= e($lbl) ?><?= tip($exHelp[$f]) ?></span>
                <select class="input input-sm<?= $m[$f] === 'custom' ? ' is-custom' : '' ?>" name="<?= $f ?>" data-ex-mode="<?= $f ?>"><?php foreach (['' => 'Normal', 'bypass' => $f === 'limits' ? 'No Limits' : 'Bypass', 'custom' => 'Custom'] as $v => $t): ?><option value="<?= $v ?>" <?= $m[$f] === $v ? 'selected' : '' ?>><?= $t ?></option><?php endforeach ?></select>
              </label>
            <?php endforeach ?>
            <?php if ($note): ?><label class="ex-opt ex-note"><span class="ex-lbl">Note</span><input class="input input-sm" name="note" value="<?= e($x['note'] ?? '') ?>" maxlength="200" /></label><?php endif ?>
          </div>
          <div class="ex-custom" data-ex-panel<?= $any ? '' : ' hidden' ?>>
            <div class="ex-c-line" data-ex-custom="tier"<?= $m['tier'] === 'custom' ? '' : ' hidden' ?>><span class="ex-c-lbl">Tier</span><div class="ex-c-body"><span>Counts as</span>
              <select class="input input-sm" name="tier_cents" aria-label="Tier they count as"><?php if (!$exTiers): ?><option value="">No tiers yet</option><?php endif ?><?php foreach ($exTiers as $c => $t): ?><option value="<?= $c ?>" <?= (int) ($x['tierCents'] ?? 0) === $c ? 'selected' : '' ?>><?= e($t !== '' ? $t . ' (' . m3d_money($c) . ')' : m3d_money($c) . ' Tier') ?></option><?php endforeach ?></select>
              <span class="muted">or higher</span></div></div>
            <div class="ex-c-line" data-ex-custom="extra"<?= $m['extra'] === 'custom' ? '' : ' hidden' ?>><span class="ex-c-lbl">Requirements</span><div class="ex-c-body"><span>Counts as</span>
              <input class="input input-sm rule-num" type="number" name="months" min="0" max="999" value="<?= e(isset($x['months']) ? (string) (int) $x['months'] : '') ?>" placeholder="—" aria-label="Months as a patron" /><span>months</span><span class="muted">·</span>
              <input class="input input-sm rule-num ex-money" type="number" name="total" min="0" step="0.01" value="<?= e(isset($x['totalCents']) ? rtrim(rtrim(number_format((int) $x['totalCents'] / 100, 2, '.', ''), '0'), '.') : '') ?>" placeholder="—" aria-label="Total support" /><span><?= e($cur) ?> total support</span></div></div>
            <div class="ex-c-line" data-ex-custom="limits"<?= $m['limits'] === 'custom' ? '' : ' hidden' ?>><span class="ex-c-lbl">Unlock Limits</span><div class="ex-c-rules" data-ex-rules>
              <?php foreach ((array) ($x['rules'] ?? []) ?: [['max' => 1, 'what' => 'models', 'per' => 'week']] as $r) $ruleRow((array) $r) ?>
              <button type="button" class="ex-c-add" data-ex-rule-add><span class="rule-add"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></span>Add Rule</button>
            </div></div>
          </div>
          <?php $ban = m3d_ex_bans($x); ?>
          <div class="ban-box">
            <span class="ex-c-lbl">Ban<?= tip('What they can no longer do. Trying it (or logging in, for Log In) shows them a page that says so, with the reason. A Log In ban also logs them out and counts as every other ban. What they already did stays (their downloads, comments and reports).') ?></span>
            <div class="ban-picks">
              <?php foreach (BAN_KINDS as $k => [$lbl]): ?><label><input type="checkbox" name="ban[]" value="<?= $k ?>" <?= in_array($k, $ban, true) ? 'checked' : '' ?> /><?= e($lbl) ?></label><?php endforeach ?>
            </div>
            <label class="ban-reason"><span class="ex-lbl">Reason<?= tip('Shown to them on the page they see. Leave empty to give no reason.') ?></span><input class="input input-sm" name="ban_reason" maxlength="<?= BAN_REASON_MAX ?>" value="<?= e((string) ($x['reason'] ?? '')) ?>" placeholder="e.g. Shared patron files outside Patreon" /></label>
          </div>
        <?php }; ?>
      <h2 class="small-h">Access Exceptions <span class="muted small">(<?= count($people) ?>)</span></h2>
      <form method="get" action="/admin/users.php" class="exl-bar" role="search">
        <input type="hidden" name="tab" value="exceptions" />
        <?php if ($f !== 'all'): ?><input type="hidden" name="f" value="<?= e($f) ?>" /><?php endif ?>
        <?php if ($sort !== 'name'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>" /><?php endif ?>
        <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name, Patreon ID or note" aria-label="Search" />
        <?php foreach (['all' => 'All', 'ex' => 'Exceptions', 'banned' => 'Banned'] as $k => $lbl): ?>
          <a class="chip<?= $f === $k ? ' on' : '' ?>" href="<?= e($listUrl(['f' => $k === 'all' ? '' : $k])) ?>"<?= $f === $k ? ' aria-current="true"' : '' ?>><?= $lbl ?> <span class="chip-n"><?= $counts[$k] ?></span></a>
        <?php endforeach ?>
        <a class="btn btn-primary btn-sm ex-add-btn" href="<?= e($listUrl(['new' => '1'])) ?>" data-ex-add-open>+ Add User</a>
      </form>

      <?php $pre = ['id' => preg_match('/^\d{1,20}$/', $a = m3d_get_text('add')) ? $a : '', 'name' => mb_substr(m3d_get_text('name'), 0, 80)];   // "Add Exception" on the Patron Log ?>
      <div class="ex-add" id="add-user" data-ex-add<?= $addOpen ? '' : ' hidden' ?>>
        <h3 class="small-h">Add User</h3>
        <form method="post" class="ex-form" data-ex>
          <?= form_keys('add') ?>
          <div class="ex-ids">
            <label class="field"><span class="lbl">Patreon User ID or Name<?= tip('The ID is shown to each patron on My account (/account/). A name finds people who downloaded something or wrote a bug report.') ?></span><input class="input" name="id" required maxlength="80" placeholder="e.g. 12345678 or Anna" list="known-patrons" data-ex-id autocomplete="off" value="<?= e($pre['id'] !== '' ? $pre['id'] : ($posted && ($_POST['do'] ?? '') === 'add' && $error !== null ? (string) ($_POST['id'] ?? '') : '')) ?>" /></label>
            <label class="field"><span class="lbl">Name</span><input class="input" name="name" maxlength="80" placeholder="e.g. Anna (tester)" data-ex-name value="<?= e($pre['name']) ?>" /></label>
            <label class="field ex-note"><span class="lbl">Note</span><input class="input" name="note" maxlength="200" placeholder="Why, until when…" /></label>
          </div>
          <?php $exFields([], false) ?>
          <div class="form-actions"><button type="button" class="btn btn-ghost btn-sm" data-ex-add-close>Cancel</button><button class="btn btn-primary btn-sm">Add User</button></div>
          <datalist id="known-patrons"><?= m3d_patron_options() ?></datalist>
        </form>
      </div>

      <?php if (!$people): ?>
        <p class="muted small ex-empty">Nobody yet: press “+ Add User” to give someone their own rules or ban them.</p>
      <?php elseif (!$shown): ?>
        <p class="muted small ex-empty">Nobody matches<?= $q !== '' ? ' “' . e($q) . '”' : '' ?>. <a href="<?= e($listUrl(['f' => '', 'q' => ''])) ?>">Show everyone</a></p>
      <?php else: ?>
        <div class="exl">
          <div class="exl-head">
            <span></span>
            <a class="exl-sh<?= $sort === 'name' ? ' is-on' : '' ?>" href="<?= e($listUrl(['sort' => ''])) ?>"<?= $sort === 'name' ? ' aria-sort="ascending"' : '' ?>>Name<i aria-hidden="true"><?= $sort === 'name' ? '↓' : '↕' ?></i></a>
            <span class="exl-h-tags">Exceptions and Bans</span>
            <a class="exl-sh exl-sh-date<?= $sort !== 'name' ? ' is-on' : '' ?>" href="<?= e($listUrl(['sort' => $sort === 'new' ? 'old' : 'new'])) ?>" title="<?= $sort === 'new' ? 'Newest first: show oldest first' : ($sort === 'old' ? 'Oldest first: show newest first' : 'Sort by the day they were added') ?>"<?= $sort !== 'name' ? ' aria-sort="' . ($sort === 'new' ? 'descending' : 'ascending') . '"' : '' ?>>Added<i aria-hidden="true"><?= $sort === 'new' ? '↓' : ($sort === 'old' ? '↑' : '↕') ?></i></a>
          </div>
          <?php foreach ($pageRows as $x): $xid = (string) $x['id']; $nm = ($x['name'] ?? '') !== '' ? (string) $x['name'] : 'ID ' . $xid; ?>
            <details class="exl-row<?= !empty($x['paused']) ? ' is-paused' : '' ?>" id="ex-<?= e($xid) ?>"<?= $openId === $xid ? ' open' : '' ?>>
              <summary>
                <svg class="exl-chev" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                <span class="exl-nm"><?= ($x['name'] ?? '') !== '' ? e($nm) : '<span class="muted">No Name</span>' ?> <code><?= e($xid) ?></code></span>
                <span class="exl-tags"><?php foreach (ex_tags($x) as [$t, $tone]): ?><span class="exl-tg<?= $tone !== '' ? ' is-' . $tone : '' ?>"><?= e($t) ?></span><?php endforeach ?></span>
                <span class="exl-dt"><?= e($date((string) ($x['added'] ?? ''))) ?></span>
              </summary>
              <form method="post" class="ex-row" data-ex>
                <?= form_keys() ?><input type="hidden" name="id" value="<?= e($xid) ?>" />
                <div class="ex-top">
                  <?php if ($isEx($x)): ?><input type="hidden" name="has_on" value="1" /><label class="ex-on" title="On/off: saved at once"><input type="checkbox" class="switch" name="on" value="1" <?= empty($x['paused']) ? 'checked' : '' ?> onchange="this.form.querySelector('[value=save]').click()" aria-label="Exception on" /></label><?php endif ?>
                  <input class="input input-sm ex-name" name="name" value="<?= e($x['name'] ?? '') ?>" maxlength="80" placeholder="Name" aria-label="Name" />
                  <span><code><?= e($xid) ?></code><?php if (isset($known[$xid])): ?> <a class="muted small" href="/admin/log.php?patron=<?= e(rawurlencode($xid)) ?>#log" title="Their downloads in the log">log</a><?php endif ?> <span class="small muted">· Added <?= e($date((string) ($x['added'] ?? ''))) ?></span></span>
                  <span class="row-btns">
                    <button class="btn btn-primary btn-sm" name="do" value="save">Save</button>
                    <button class="btn btn-ghost btn-sm del-btn" name="do" value="remove" onclick="return confirm(<?= e(json_encode('Remove ' . $nm . ' from the list? They go back to the normal rules, and every ban ends.')) ?>)">Remove</button>
                  </span>
                </div>
                <?php $exFields($x, true) ?>
              </form>
            </details>
          <?php endforeach ?>
        </div>
        <div class="exl-foot">
          <?php if ($pages > 1): ?>
            <nav class="pager" aria-label="Pages">
              <?php if ($page > 1): ?><a class="chip" href="<?= e($listUrl(['page' => $page - 1])) ?>" aria-label="Previous page">‹</a><?php else: ?><span class="chip is-off" aria-hidden="true">‹</span><?php endif ?>
              <?php for ($i = 1; $i <= $pages; $i++): ?><a class="chip" href="<?= e($listUrl(['page' => $i])) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a><?php endfor ?>
              <?php if ($page < $pages): ?><a class="chip" href="<?= e($listUrl(['page' => $page + 1])) ?>" aria-label="Next page">›</a><?php else: ?><span class="chip is-off" aria-hidden="true">›</span><?php endif ?>
            </nav>
          <?php endif ?>
          <span class="muted small"><?= $from + 1 ?>–<?= $from + count($pageRows) ?> of <?= count($shown) ?></span>
        </div>
      <?php endif ?>
      <template data-ex-rule-tpl><?php $ruleRow(['max' => 1, 'what' => 'models', 'per' => 'week']) ?></template>
    </section>
<?php else: share_section(); endif ?>
<?php admin_end();
