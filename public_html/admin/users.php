<?php
// Users: people with special access, who skip some download rules (data/exceptions.json, see m3d_apply_exception()).
//   Bypass Tier: download with a free Patreon account (or a pledge without a tier, a declined payment)
//   Bypass Requirements: minimum tier, months as a patron and total support of models and outfits aren't checked
//   Bypass Unlock Limits: the unlock limit rules below don't apply to them
// Also the download settings for everyone (settings.json → downloads, see m3d_dl_settings()): the waiting time before a
// download and the download limit rules (several, each can be turned off without deleting it).
require __DIR__ . '/_admin.php';
admin_require($M3D);

/** Rules in reading order: by months as a patron (from, then to; empty = 0 / no end), so the list goes from new patrons to old ones. */
function dl_rules_sorted(array $rules): array
{
    $key = fn(array $r) => [(int) ($r['from'] ?? 0), ($r['to'] ?? '') === '' ? PHP_INT_MAX : (int) $r['to']];
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
    if ($max < 1 || $max > 999) throw new InvalidArgumentException('The number of downloads is between 1 and 999.');
    if (!isset(DL_WHAT[$what]) || !isset(DL_PERIODS[$per])) throw new InvalidArgumentException('Pick what the rule counts and the period.');
    if ($from !== null && $to !== null && $to <= $from) throw new InvalidArgumentException('“Less than” months must be more than “at least” months.');
    if ($to === 0) throw new InvalidArgumentException('“Less than 0 months” matches nobody: leave it empty for no upper limit.');
    return ['id' => $id, 'on' => $on, 'max' => $max, 'what' => $what, 'per' => $per, 'from' => $from ?? '', 'to' => $to ?? ''];
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
            $done = $dl['wait'] ? "Downloads now start after {$dl['wait']} seconds." : 'Downloads now start right away (no waiting page).';
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
        if (!m3d_save_settings(['downloads' => ['wait' => $dl['wait'], 'rules' => dl_rules_sorted($rules)]])) throw new RuntimeException(m3d_write_error());
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
} elseif ($posted) {
    $people = m3d_exceptions(true);
    $id = trim((string) ($_POST['id'] ?? ''));
    $index = null;
    foreach ($people as $i => $x) if ((string) $x['id'] === $id) $index = $i;
    try {
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
            $x = [
                'id'    => $id,
                'name'  => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80),
                'note'  => mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 200),
                'tier'  => !empty($_POST['tier']),
                'extra' => !empty($_POST['extra']),
                'limits' => !empty($_POST['limits']),
                'added' => $index !== null ? ($people[$index]['added'] ?? gmdate('c')) : gmdate('c'),
            ];
            if (!$x['tier'] && !$x['extra'] && !$x['limits']) throw new InvalidArgumentException('Tick at least one of the options' . ($index !== null ? ', or remove the user.' : '.'));
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
// patrons seen in the download log: pick them by name in the "Patreon user ID" field
$known = [];
if (($fh = @fopen(m3d_log_file(), 'rb'))) {
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) if (($r[1] ?? '') !== '') $known[(string) $r[1]] = (string) ($r[2] ?? '');
    fclose($fh);
}
$dl = m3d_dl_settings();
$dl['rules'] = dl_rules_sorted($dl['rules']);
$date = fn(string $iso) => ($d = date_create_immutable($iso)) ? $d->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d/m/Y') : '';

admin_start('Access', 'users');
?>
    <div class="admin-head">
      <div><h1>Access</h1></div>
    </div>

    <?php if ($done): ?><p class="notice"><b class="res-ok">✓</b> <?= e($done) ?></p><?php endif ?>
    <?php if ($error): ?><p class="notice"><b class="res-err">Not saved:</b> <?= e($error) ?></p><?php endif ?>

    <div class="users-grid">
    <section class="sec" id="wait">
      <h2 class="small-h">Download Countdown<?= tip('Countdown before every download, free ones included. 0 = off.') ?></h2>
      <form method="post" class="dl-wait-form">
        <?= form_keys() ?>
        <label class="dl-secs"><input class="input input-sm" type="number" name="wait" min="0" max="60" step="1" value="<?= (int) $dl['wait'] ?>" required aria-label="Seconds before each download" /> <span class="muted">seconds</span></label>
        <button class="btn btn-primary btn-sm" name="do" value="dl-wait">Save</button>
      </form>
    </section>

    <section class="sec" id="downloads">
      <h2 class="small-h">Unlock Limits <span class="muted small">(<?= count(array_filter($dl['rules'], fn($r) => !empty($r['on']))) ?> on<?= count($dl['rules']) ? ' of ' . count($dl['rules']) : '' ?>)</span><?= tip('How many different models or accessories a patron can unlock (first download) per period, by months as a patron. Every matching rule must allow it. Downloading again is always free; items set to “Exclude from Unlock Limits” never count.') ?></h2>
      <?php if (empty($M3D['log_downloads'])): ?><p class="notice"><b class="res-err">Limits are not counted:</b> the download log is off (<code>log_downloads</code> in config.php), and limits are counted from it.</p><?php elseif ((int) ($M3D['log_retention_days'] ?? 365) < 30): ?><p class="notice">The download log keeps only <?= (int) $M3D['log_retention_days'] ?> days (<code>log_retention_days</code>): 30-day limits can't see older downloads.</p><?php endif ?>
      <?php
        // one rule as a table row: Patrons with [1] – [3] months · can unlock [2] [models] · every [30 days]
        $ruleFields = function (string $f, array $r): void { ?>
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
        <div class="rule-row rule-head" aria-hidden="true"><span></span><span>Patron Months</span><span>Max Unlocks</span><span>Period</span><span></span></div>
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
    </section>
    </div>

    <section class="sec" id="users">
      <?php $exHelp = ['tier' => 'Can download with a free Patreon account.', 'extra' => 'Minimum tier, months and total support aren’t checked.', 'limits' => 'Unlock limits don’t apply.']; ?>
      <h2 class="small-h">Access Exceptions <span class="muted small">(<?= count($people) ?>)</span></h2>
      <?php if (!$people): ?>
        <p class="muted small ex-empty">Nobody yet: add someone below.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="log-table ex-table">
            <thead><tr><th>Name</th><th>Patreon ID</th><th class="c-center ex-wrap">Bypass Tier<?= tip($exHelp['tier']) ?></th><th class="c-center ex-wrap">Bypass Requirements<?= tip($exHelp['extra']) ?></th><th class="c-center ex-wrap">Bypass Limits<?= tip($exHelp['limits']) ?></th><th>Note</th><th>Added</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($people as $i => $x): $f = 'ex-' . $i; ?>
                <tr id="ex-<?= e((string) $x['id']) ?>"<?= m3d_get_text('ex') === (string) $x['id'] ? ' class="is-picked"' : '' ?>>
                  <td><input class="input input-sm" form="<?= $f ?>" name="name" value="<?= e($x['name'] ?? '') ?>" maxlength="80" aria-label="Name" /></td>
                  <td><code><?= e($x['id']) ?></code><?php if (isset($known[$x['id']])): ?> <a class="muted small" href="/admin/log.php?patron=<?= e(rawurlencode((string) $x['id'])) ?>#log" title="Their downloads in the log">log</a><?php endif ?></td>
                  <td class="c-center"><input type="checkbox" form="<?= $f ?>" name="tier" value="1" <?= !empty($x['tier']) ? 'checked' : '' ?> aria-label="Bypass Tier" /></td>
                  <td class="c-center"><input type="checkbox" form="<?= $f ?>" name="extra" value="1" <?= !empty($x['extra']) ? 'checked' : '' ?> aria-label="Bypass Requirements" /></td>
                  <td class="c-center"><input type="checkbox" form="<?= $f ?>" name="limits" value="1" <?= !empty($x['limits']) ? 'checked' : '' ?> aria-label="Bypass Unlock Limits" /></td>
                  <td><input class="input input-sm" form="<?= $f ?>" name="note" value="<?= e($x['note'] ?? '') ?>" maxlength="200" aria-label="Note" /></td>
                  <td class="small muted"><?= e($date((string) ($x['added'] ?? ''))) ?></td>
                  <td class="c-actions">
                    <form id="<?= $f ?>" method="post"><?= form_keys() ?><input type="hidden" name="id" value="<?= e($x['id']) ?>" />
                      <button class="btn btn-primary btn-sm" name="do" value="save">Save</button>
                      <button class="btn btn-ghost btn-sm del-btn" name="do" value="remove" onclick="return confirm(<?= e(json_encode('Remove ' . (($x['name'] ?? '') ?: $x['id']) . ' from the users list? They go back to the normal rules.')) ?>)">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
      <?php $pre = ['id' => preg_match('/^\d{1,20}$/', $a = m3d_get_text('add')) ? $a : '', 'name' => mb_substr(m3d_get_text('name'), 0, 80)];   // "Add Exception" on the download log ?>
      <h3 class="small-h ex-add-h" id="add-user">Add User</h3>
      <form method="post" class="ex-form">
        <?= form_keys('add') ?>
        <label class="field"><span class="lbl">Patreon User ID<?= tip('Shown to each patron on My account (/account/).') ?></span><input class="input" name="id" required inputmode="numeric" pattern="\d{1,20}" placeholder="e.g. 12345678" list="known-patrons" data-ex-id autocomplete="off" value="<?= e($pre['id']) ?>" /></label>
        <label class="field"><span class="lbl">Name<?= tip('Only for you.') ?></span><input class="input" name="name" maxlength="80" placeholder="e.g. Anna (tester)" data-ex-name value="<?= e($pre['name']) ?>" /></label>
        <label class="field ex-note"><span class="lbl">Note</span><input class="input" name="note" maxlength="200" placeholder="Why, until when…" /></label>
        <div class="ex-checks">
          <label class="field check"><input type="checkbox" name="tier" value="1" checked /><span class="lbl">Bypass Tier<?= tip($exHelp['tier']) ?></span></label>
          <label class="field check"><input type="checkbox" name="extra" value="1" checked /><span class="lbl">Bypass Requirements<?= tip($exHelp['extra']) ?></span></label>
          <label class="field check"><input type="checkbox" name="limits" value="1" /><span class="lbl">Bypass Unlock Limits<?= tip($exHelp['limits']) ?></span></label>
        </div>
        <button class="btn btn-primary btn-sm">Add User</button>
        <datalist id="known-patrons"><?php foreach ($known as $kid => $kname): ?><option value="<?= e($kid) ?>"><?= e($kname) ?></option><?php endforeach ?></datalist>
      </form>
    </section>
<?php admin_end();
