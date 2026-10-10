<?php
// Users: people with special access, who skip some download rules (data/exceptions.json, see m3d_apply_exception()).
//   No tier needed: download with a free Patreon account (or a pledge without a tier, a declined payment)
//   Skip extra requirements: months as a patron / total support of models and outfits aren't checked
//   Skip download limits: the download limit rules below don't apply to them
// Also the download settings for everyone (settings.json → downloads, see m3d_dl_settings()): the waiting time before a
// download and the download limit rules (several, each can be turned off without deleting it).
require __DIR__ . '/_admin.php';
admin_require($M3D);

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
    $from = $month('from');
    $to = $month('to');
    if ($max < 1 || $max > 999) throw new InvalidArgumentException('The number of downloads is between 1 and 999.');
    if (!isset(DL_WHAT[$what]) || !isset(DL_PERIODS[$per])) throw new InvalidArgumentException('Pick what the rule counts and the period.');
    if ($from !== null && $to !== null && $to <= $from) throw new InvalidArgumentException('“Less than” months must be more than “at least” months.');
    if ($to === 0) throw new InvalidArgumentException('“Less than 0 months” matches nobody: leave it empty for no upper limit.');
    return ['id' => $id, 'on' => $on, 'max' => $max, 'what' => $what, 'per' => $per, 'from' => $from ?? '', 'to' => $to ?? ''];
}

$done = $error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_starts_with((string) ($_POST['do'] ?? ''), 'dl-')) {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
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
        if (!m3d_save_settings(['downloads' => ['wait' => $dl['wait'], 'rules' => array_values($rules)]])) throw new RuntimeException('Could not write the settings file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.');
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
} elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }
    $people = m3d_exceptions(true);
    $id = trim((string) ($_POST['id'] ?? ''));
    $index = null;
    foreach ($people as $i => $x) if ((string) $x['id'] === $id) $index = $i;
    try {
        if (!preg_match('/^\d{1,20}$/', $id)) throw new InvalidArgumentException('The Patreon user ID is a number, e.g. 12345678. The person can find it on their “My account” page of this site.');
        $do = (string) ($_POST['do'] ?? '');
        if ($do === 'remove') {
            if ($index === null) throw new InvalidArgumentException('This user is no longer in the list.');
            $name = $people[$index]['name'] ?: $id;
            array_splice($people, $index, 1);
            if (!m3d_save_exceptions($people)) throw new RuntimeException('Could not write the data file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.');
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
            if (!m3d_save_exceptions($people)) throw new RuntimeException('Could not write the data file. Check that ' . basename(M3D_PRIVATE) . '/data is writable.');
            $who = $x['name'] !== '' ? $x['name'] : "ID $id";
            $done = $do === 'add' && $index === null ? "$who added. It counts right away." : "$who saved.";
        }
    } catch (InvalidArgumentException | RuntimeException $ex) {
        $error = $ex->getMessage();
    }
}

$people = m3d_exceptions(true);
usort($people, fn($a, $b) => strcasecmp((string) ($a['name'] ?: $a['id']), (string) ($b['name'] ?: $b['id'])));
// patrons seen in the download log: pick them by name in the "Patreon user ID" field
$known = [];
if (($fh = @fopen(M3D_PRIVATE . '/logs/downloads.csv', 'rb'))) {
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) if (($r[1] ?? '') !== '') $known[(string) $r[1]] = (string) ($r[2] ?? '');
    fclose($fh);
}
$dl = m3d_dl_settings();
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
      <h2 class="small-h">Wait before each download<?= tip('A countdown before every download, free ones too. 0 = off.') ?></h2>
      <form method="post" class="dl-wait-form">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" />
        <label class="field"><span class="dl-secs"><input class="input" type="number" name="wait" min="0" max="60" step="1" value="<?= (int) $dl['wait'] ?>" required aria-label="Seconds before each download" /> seconds</span></label>
        <button class="btn btn-primary btn-sm" name="do" value="dl-wait">Save</button>
      </form>
    </section>

    <section class="sec" id="downloads">
      <h2 class="small-h">Unlock limits <span class="muted small">(<?= count(array_filter($dl['rules'], fn($r) => !empty($r['on']))) ?> on<?= count($dl['rules']) ? ' of ' . count($dl['rules']) : '' ?>)</span><?= tip('How many different models or accessories a patron can unlock (download for the first time) per period, by their months as a patron. Every rule that matches must allow it. Downloading something again is always free, and items ticked “Not counted in the download limits” never count.') ?></h2>
      <?php if (empty($M3D['log_downloads'])): ?><p class="notice"><b class="res-err">Limits are not counted:</b> the download log is off (<code>log_downloads</code> in config.php), and limits are counted from it.</p><?php elseif ((int) ($M3D['log_retention_days'] ?? 365) < 30): ?><p class="notice">The download log keeps only <?= (int) $M3D['log_retention_days'] ?> days (<code>log_retention_days</code>): 30-day limits can't see older downloads.</p><?php endif ?>
      <?php
        // one rule as a sentence: "[1] [model unlocks] [per week] for patrons with at least [ ] and less than [6] months"
        $ruleFields = function (string $f, array $r): void { ?>
          <span class="rule-sentence">
            <span class="rule-part">
            <input class="input input-sm rule-num" form="<?= $f ?>" type="number" name="max" min="1" max="999" value="<?= (int) ($r['max'] ?? 1) ?>" required aria-label="How many" />
            <select class="input input-sm" form="<?= $f ?>" name="what" aria-label="What counts"><?php foreach (DL_WHAT as $k => [$one, $many]): ?><option value="<?= e($k) ?>" <?= ($r['what'] ?? 'models') === $k ? 'selected' : '' ?>><?= e($k === 'all' ? 'unlocks (models + accessories)' : "$one unlocks") ?></option><?php endforeach ?></select>
            <select class="input input-sm" form="<?= $f ?>" name="per" aria-label="Period"><?php foreach (DL_PERIODS as $k => [$secs, $label]): ?><option value="<?= e($k) ?>" <?= ($r['per'] ?? 'week') === $k ? 'selected' : '' ?>>per <?= e($label) ?></option><?php endforeach ?></select>
            </span>
            <span class="rule-part">
            <span class="muted">for patrons with at least</span>
            <input class="input input-sm rule-num" form="<?= $f ?>" type="number" name="from" min="0" max="999" value="<?= e((string) ($r['from'] ?? '')) ?>" placeholder="any" aria-label="At least this many months" />
            <span class="muted">and less than</span>
            <input class="input input-sm rule-num" form="<?= $f ?>" type="number" name="to" min="1" max="999" value="<?= e((string) ($r['to'] ?? '')) ?>" placeholder="any" aria-label="Less than this many months" />
            <span class="muted">months</span>
            </span>
          </span>
      <?php }; ?>
      <div class="rules">
        <?php foreach ($dl['rules'] as $i => $r): $f = 'rule-' . $i; ?>
          <div class="rule-row<?= empty($r['on']) ? ' rule-off' : '' ?>">
            <label class="rule-on" title="On/off: saved at once"><input type="checkbox" form="<?= $f ?>" name="on" value="1" <?= !empty($r['on']) ? 'checked' : '' ?> onchange="this.form.querySelector('[value=dl-save]').click()" /><span><?= empty($r['on']) ? 'Off' : 'On' ?></span></label>
            <?php $ruleFields($f, $r) ?>
            <form id="<?= $f ?>" method="post" class="rule-btns"><input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="rule" value="<?= e((string) ($r['id'] ?? '')) ?>" />
              <button class="btn btn-ghost btn-sm" name="do" value="dl-save">Save</button>
              <button class="btn btn-ghost btn-sm del-btn" name="do" value="dl-delete" formnovalidate onclick="return confirm(<?= e(json_encode('Delete the rule “' . m3d_rule_text($r) . '”? To pause it, untick On instead.')) ?>)">Delete</button>
            </form>
          </div>
        <?php endforeach ?>
        <?php if (!$dl['rules']): ?><p class="muted small rules-empty">No limits yet: patrons can unlock as many models and accessories as they like.</p><?php endif ?>
        <div class="rule-row rule-new">
          <span class="rule-on muted small">New rule</span>
          <?php $ruleFields('rule-new', ['max' => 1, 'what' => 'models', 'per' => 'week']) ?>
          <form id="rule-new" method="post" class="rule-btns"><input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" />
            <button class="btn btn-primary btn-sm" name="do" value="dl-add">Add rule</button>
          </form>
        </div>
      </div>
    </section>
    </div>

    <section class="sec" id="users">
      <?php $exHelp = ['tier' => 'Can download with a free Patreon account.', 'extra' => 'Months and total support aren’t checked.', 'limits' => 'The unlock limits don’t apply.']; ?>
      <h2 class="small-h">Users with special access <span class="muted small">(<?= count($people) ?>)</span></h2>
      <?php if (!$people): ?>
        <p class="muted small ex-empty">Nobody yet: add someone below.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="log-table ex-table">
            <thead><tr><th>Name</th><th>Patreon ID</th><th class="c-center">No tier<?= tip('No tier needed: ' . $exHelp['tier']) ?></th><th class="c-center">Skip extra<?= tip('Skip extra requirements: ' . $exHelp['extra']) ?></th><th class="c-center">Skip limits<?= tip('Skip unlock limits: ' . $exHelp['limits']) ?></th><th>Note</th><th>Added</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($people as $i => $x): $f = 'ex-' . $i; ?>
                <tr>
                  <td><input class="input input-sm" form="<?= $f ?>" name="name" value="<?= e($x['name'] ?? '') ?>" maxlength="80" aria-label="Name" /></td>
                  <td><code><?= e($x['id']) ?></code><?php if (isset($known[$x['id']])): ?> <a class="muted small" href="/admin/log.php?patron=<?= e(rawurlencode((string) $x['id'])) ?>#log" title="Their downloads in the log">log</a><?php endif ?></td>
                  <td class="c-center"><input type="checkbox" form="<?= $f ?>" name="tier" value="1" <?= !empty($x['tier']) ? 'checked' : '' ?> aria-label="No tier needed" /></td>
                  <td class="c-center"><input type="checkbox" form="<?= $f ?>" name="extra" value="1" <?= !empty($x['extra']) ? 'checked' : '' ?> aria-label="Skip extra requirements" /></td>
                  <td class="c-center"><input type="checkbox" form="<?= $f ?>" name="limits" value="1" <?= !empty($x['limits']) ? 'checked' : '' ?> aria-label="Skip unlock limits" /></td>
                  <td><input class="input input-sm" form="<?= $f ?>" name="note" value="<?= e($x['note'] ?? '') ?>" maxlength="200" aria-label="Note" /></td>
                  <td class="small muted"><?= e($date((string) ($x['added'] ?? ''))) ?></td>
                  <td class="c-actions">
                    <form id="<?= $f ?>" method="post"><input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="id" value="<?= e($x['id']) ?>" />
                      <button class="btn btn-ghost btn-sm" name="do" value="save">Save</button>
                      <button class="btn btn-ghost btn-sm del-btn" name="do" value="remove" onclick="return confirm(<?= e(json_encode('Remove ' . (($x['name'] ?? '') ?: $x['id']) . ' from the users list? They go back to the normal rules.')) ?>)">Remove</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endif ?>
      <h3 class="small-h ex-add-h">Add a user</h3>
      <form method="post" class="ex-form">
        <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" /><input type="hidden" name="do" value="add" />
        <label class="field"><span class="lbl">Patreon user ID<?= tip('People find their Patreon user ID on My account (/account/).') ?></span><input class="input" name="id" required inputmode="numeric" pattern="\d{1,20}" placeholder="e.g. 12345678" list="known-patrons" data-ex-id autocomplete="off" /></label>
        <label class="field"><span class="lbl">Name (for you)</span><input class="input" name="name" maxlength="80" placeholder="e.g. Anna (tester)" data-ex-name /></label>
        <label class="field ex-note"><span class="lbl">Note (optional)</span><input class="input" name="note" maxlength="200" placeholder="Why, until when…" /></label>
        <div class="ex-checks">
          <label class="field check"><input type="checkbox" name="tier" value="1" checked /><span class="lbl">No tier needed<?= tip($exHelp['tier']) ?></span></label>
          <label class="field check"><input type="checkbox" name="extra" value="1" checked /><span class="lbl">Skip extra requirements<?= tip($exHelp['extra']) ?></span></label>
          <label class="field check"><input type="checkbox" name="limits" value="1" /><span class="lbl">Skip unlock limits<?= tip($exHelp['limits']) ?></span></label>
        </div>
        <button class="btn btn-primary btn-sm">Add user</button>
        <datalist id="known-patrons"><?php foreach ($known as $kid => $kname): ?><option value="<?= e($kid) ?>"><?= e($kname) ?></option><?php endforeach ?></datalist>
      </form>
    </section>
<?php admin_end();
