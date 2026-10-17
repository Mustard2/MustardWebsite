<?php
// Users: people with special access, who skip or change some download rules (data/exceptions.json, see m3d_apply_exception()).
// Each of the three is Normal, Bypass or Custom:
//   Tier: Bypass = download with a free Patreon account (or a pledge without a tier, a declined payment); Custom = the same, counting as at least one tier
//   Requirements: Bypass = minimum tier, months as a patron and total support of models and outfits aren't checked; Custom = they count as at least N months / a total
//   Unlock Limits: Bypass ("No Limits") = the unlock limit rules below don't apply to them; Custom = their own rules replace them
// Also the download settings for everyone (settings.json → downloads, see m3d_dl_settings()): the waiting time before a
// download and the download limit rules (several, each can be turned off without deleting it).
require __DIR__ . '/_admin.php';
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
    if ($x['tier'] === '' && $x['extra'] === '' && $x['limits'] === '') throw new InvalidArgumentException('Pick Bypass or Custom for at least one of Tier, Requirements and Unlock Limits' . ($old ? ', or remove the user.' : '.'));
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
    return array_filter($x, fn($v) => $v !== '');
}

$done = $error = null;
$posted = admin_form_post();
if ($posted && str_starts_with((string) ($_POST['do'] ?? ''), 'dl-')) {
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
        if (!m3d_save_settings(['downloads' => ['wait' => $dl['wait'], 'rules' => dl_rules_sorted($rules)] + ($dl['updates'] ? ['updates' => true] : []) + ($dl['cap'] ? ['cap' => $dl['cap']] : [])])) throw new RuntimeException(m3d_write_error());
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
            if ($index !== null) $people[$index] = $x; else $people[] = $x;
            if (!m3d_save_exceptions($people)) throw new RuntimeException(m3d_write_error('data'));
            $who = $x['name'] !== '' ? $x['name'] : "ID $id";
            $done = $do === 'add' && $index === null ? "$who added. It counts right away." : "$who saved.";
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$people = m3d_exceptions(true);
usort($people, fn($a, $b) => strcasecmp((string) (($a['name'] ?? '') ?: $a['id']), (string) (($b['name'] ?? '') ?: $b['id'])));
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

    <?php admin_result($done, $error) ?>

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

    <section class="sec" id="downloads">
      <h2 class="small-h">Unlock Limits <span class="muted small">(<?= count(array_filter($dl['rules'], fn($r) => !empty($r['on']))) ?> on<?= count($dl['rules']) ? ' of ' . count($dl['rules']) : '' ?>)</span><?= tip('How many different models or accessories a patron can unlock (first download) per period, by tier and months as a patron. Every matching rule must allow it; a rule for a patron’s own tier replaces the “Any Tier” rules that count the same things (models, accessories). Downloading again is free (new versions too, unless “Updates Use an Unlock” is on); items set to “Exclude from Unlock Limits” never count.') ?></h2>
      <?php if (empty($M3D['log_downloads'])): ?><p class="notice"><b class="res-err">Limits are not counted:</b> the download log is off (<code>log_downloads</code> in config.php), and limits are counted from it.</p><?php elseif ((int) ($M3D['log_retention_days'] ?? 365) < 30): ?><p class="notice">The download log keeps only <?= (int) $M3D['log_retention_days'] ?> days (<code>log_retention_days</code>): 30-day limits can't see older downloads.</p><?php endif ?>
      <?php
        // one rule as a table row: [Any Tier] patrons with [1] – [3] months · can unlock [2] [models] · every [30 days]
        // tiers: the ones seen at patron logins (m3d_known_tiers()), plus a rule's own when it's no longer among them
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

    <section class="sec" id="users">
      <?php
        $exHelp = ['tier' => 'Bypass: can download with a free Patreon account. Custom: the same, and counts as at least the tier you pick.',
                   'extra' => 'Bypass: minimum tier, months and total support aren’t checked. Custom: counts as at least the months and total support you set (the months also pick the unlock rules by months).',
                   'limits' => 'No Limits: the unlock limits don’t apply. Custom: their own rules replace the general ones.'];
        // tiers to count as: the ones seen at patron logins, plus a person's own when it's no longer among them
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
        <?php }; ?>
      <h2 class="small-h">Access Exceptions <span class="muted small">(<?= count($people) ?>)</span></h2>
      <?php if (!$people): ?>
        <p class="muted small ex-empty">Nobody yet: add someone below.</p>
      <?php else: ?>
        <div class="ex-list">
          <?php foreach ($people as $x): ?>
            <form method="post" class="ex-row<?= m3d_get_text('ex') === (string) $x['id'] ? ' is-picked' : '' ?>" id="ex-<?= e((string) $x['id']) ?>" data-ex>
              <?= form_keys() ?><input type="hidden" name="id" value="<?= e($x['id']) ?>" />
              <div class="ex-top">
                <input class="input input-sm ex-name" name="name" value="<?= e($x['name'] ?? '') ?>" maxlength="80" placeholder="Name" aria-label="Name" />
                <span><code><?= e($x['id']) ?></code><?php if (isset($known[$x['id']])): ?> <a class="muted small" href="/admin/log.php?patron=<?= e(rawurlencode((string) $x['id'])) ?>#log" title="Their downloads in the log">log</a><?php endif ?></span>
                <span class="small muted ex-added">Added <?= e($date((string) ($x['added'] ?? ''))) ?></span>
                <span class="row-btns">
                  <button class="btn btn-primary btn-sm" name="do" value="save">Save</button>
                  <button class="btn btn-ghost btn-sm del-btn" name="do" value="remove" onclick="return confirm(<?= e(json_encode('Remove ' . (($x['name'] ?? '') ?: $x['id']) . ' from the users list? They go back to the normal rules.')) ?>)">Remove</button>
                </span>
              </div>
              <?php $exFields($x, true) ?>
            </form>
          <?php endforeach ?>
        </div>
      <?php endif ?>
      <template data-ex-rule-tpl><?php $ruleRow(['max' => 1, 'what' => 'models', 'per' => 'week']) ?></template>
      <?php $pre = ['id' => preg_match('/^\d{1,20}$/', $a = m3d_get_text('add')) ? $a : '', 'name' => mb_substr(m3d_get_text('name'), 0, 80)];   // "Add Exception" on the download log ?>
      <h3 class="small-h ex-add-h" id="add-user">Add User</h3>
      <form method="post" class="ex-form" data-ex>
        <?= form_keys('add') ?>
        <div class="ex-ids">
          <label class="field"><span class="lbl">Patreon User ID or Name<?= tip('The ID is shown to each patron on My account (/account/). A name finds people who downloaded something or wrote a bug report.') ?></span><input class="input" name="id" required maxlength="80" placeholder="e.g. 12345678 or Anna" list="known-patrons" data-ex-id autocomplete="off" value="<?= e($pre['id']) ?>" /></label>
          <label class="field"><span class="lbl">Name<?= tip('Only for you.') ?></span><input class="input" name="name" maxlength="80" placeholder="e.g. Anna (tester)" data-ex-name value="<?= e($pre['name']) ?>" /></label>
          <label class="field ex-note"><span class="lbl">Note</span><input class="input" name="note" maxlength="200" placeholder="Why, until when…" /></label>
        </div>
        <?php $exFields(['tier' => 'bypass', 'extra' => 'bypass'], false) ?>
        <button class="btn btn-primary btn-sm">Add User</button>
        <datalist id="known-patrons"><?= m3d_patron_options() ?></datalist>
      </form>
    </section>
<?php admin_end();
