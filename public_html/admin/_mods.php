<?php
// Admin → Moderation → Moderators (a tab of admin/moderation.php; rules in app/moderation.php): the moderators (a switch each, their actions of the last
// 7 days with a link to the Moderation page's Log, ✕ to remove), "+ Add Moderator" (Patreon ID or name, like Add User), and "What Moderators
// Can Do" (switches: comments, bug reports, ideas, ban). Saved in settings.json → moderation.
defined('M3D_PRIVATE') || exit;

/** The section's forms (do = mod-add | mod-on | mod-remove | mod-can, already CSRF-checked): [done, error]. */
function mods_post(string $do): array
{
    $S = m3d_mod_settings();
    $mods = $S['mods'];
    $save = fn(array $mods, string $id = '', bool $on = false) => m3d_mod_settings_save(['mods' => array_values($mods)] + ($id !== '' ? ['bell' => m3d_mod_bell_with($id, $on)] : []));   // + the person's bell row
    if ($do === 'mod-can') {
        $can = [];
        foreach ([...array_keys(MOD_KINDS), 'ban'] as $k) $can[$k] = !empty($_POST[$k]);
        return m3d_mod_settings_save(['can' => $can]) ? ['✓ Saved.', null] : [null, m3d_write_error()];
    }
    if ($do === 'mod-add') {
        $q = trim((string) ($_POST['id'] ?? ''));
        $name = '';
        if (!preg_match('/^\d{1,20}$/D', $q)) {   // a name, or "Name · ID" picked from the list
            $hits = m3d_find_patrons($q, 2);
            if (count($hits) !== 1) return [null, $hits ? 'Several people match “' . $q . '”: pick one from the list, or type their Patreon user ID.'
                : 'Nobody called “' . $q . '” is known to the site: type their Patreon user ID (on their My Account page).'];
            [$q, $name] = $hits[0];
        }
        $q = (string) $q;
        if (!preg_match('/^\d{1,20}$/D', $q)) return [null, 'The Patreon user ID is a number, e.g. 12345678. The person finds it on their “My Account” page of this site.'];
        if (in_array($q, m3d_owner_ids($GLOBALS['M3D']), true)) return [null, 'This is an admin: admins can already do everything.'];
        foreach ($mods as $m) if ($m['id'] === $q) return [null, ($m['name'] ?: $q) . ' is a moderator already.'];
        if (count($mods) >= MOD_MAX) return [null, 'Up to ' . MOD_MAX . ' moderators.'];
        $name = $name !== '' ? $name : (string) (m3d_known_patrons()[$q] ?? '');
        $mods[] = ['id' => $q, 'name' => mb_substr($name, 0, 80), 'on' => true, 'added' => gmdate('c')];
        return $save($mods, $q, true) ? [($name ?: $q) . ' added.', null] : [null, m3d_write_error()];
    }
    $id = (string) ($_POST['mod'] ?? '');
    $at = null;
    foreach ($mods as $i => $m) if ($m['id'] === $id) $at = $i;
    if ($at === null) return [null, 'This moderator isn’t in the list any more.'];
    $who = $mods[$at]['name'] ?: $id;
    if ($do === 'mod-on') {
        $mods[$at]['on'] = !empty($_POST['on']);
        return $save($mods, $id, $mods[$at]['on']) ? [$mods[$at]['on'] ? "$who on." : "$who off.", null] : [null, m3d_write_error()];
    }
    if ($do === 'mod-remove') {
        array_splice($mods, $at, 1);
        return $save($mods, $id, false) ? ["$who removed.", null] : [null, m3d_write_error()];
    }
    return [null, null];
}

/** The tab. */
function mods_section(): void
{
    $S = m3d_mod_settings();
    $week = time() - 7 * 86400;
    $acts = [];
    foreach (m3d_modlog() as $e) if ((int) ($e['at'] ?? 0) > $week) $acts[(string) ($e['by'] ?? '')] = ($acts[(string) ($e['by'] ?? '')] ?? 0) + 1;
    $off = array_filter(array_keys(MOD_KINDS), fn($k) => !m3d_mod_kind_on($k));
    // a name for a moderator added by ID before the site knew them: the one they moderate under, else the site's records
    $named = [];
    foreach (m3d_modlog() as $e) if (($e['name'] ?? '') !== '') $named[(string) ($e['by'] ?? '')] ??= (string) $e['name'];
    $known = m3d_known_patrons();
    foreach ($S['mods'] as &$m) if ($m['name'] === '') $m['name'] = $named[$m['id']] ?? (string) ($known[$m['id']] ?? '');
    unset($m);
    ?>
    <div class="mods-grid">
      <section class="sec" id="mods">
        <h2 class="small-h">Moderators <span class="muted small">(<?= count($S['mods']) ?>)</span></h2>
        <?php if ($S['mods']): ?>
          <div class="mods">
            <?php foreach ($S['mods'] as $m): $n = (int) ($acts[$m['id']] ?? 0); ?>
              <div class="mod-row<?= $m['on'] ? '' : ' is-off' ?>">
                <form method="post"><?= form_keys('mod-on') ?><input type="hidden" name="mod" value="<?= e($m['id']) ?>" /><label class="toggle" title="On/off: saved at once"><input type="checkbox" class="switch" name="on" value="1" <?= $m['on'] ? 'checked' : '' ?> onchange="this.form.requestSubmit()" aria-label="<?= e(($m['name'] ?: $m['id']) . ' can moderate') ?>" /></label></form>
                <span class="mod-n"><?= avatar_html($m['id'], $m['name'], false, 'ava-sm') ?><b><?= e($m['name'] ?: 'No Name') ?></b><code><?= e($m['id']) ?></code></span>
                <span class="mod-act muted small"><?= $n ? m3d_plural($n, 'action') . ' this week · ' : '' ?><a href="/admin/moderation.php?tab=log">See Log</a></span>
                <span class="muted small mod-since"><?= $m['added'] !== '' ? 'Since ' . e(m3d_site_time($m['added'], 'j M Y')) : '' ?></span>
                <form method="post"><?= form_keys('mod-remove') ?><input type="hidden" name="mod" value="<?= e($m['id']) ?>" /><button class="icon-btn del" title="Remove as moderator" aria-label="Remove <?= e($m['name'] ?: $m['id']) ?> as moderator" onclick="return confirm(<?= e(json_encode('Remove ' . ($m['name'] ?: $m['id']) . ' as moderator? What they did stays in the log.', JSON_UNESCAPED_UNICODE)) ?>)">✕</button></form>
              </div>
            <?php endforeach ?>
          </div>
        <?php endif ?>
        <form method="post" class="mod-add">
          <?= form_keys('mod-add') ?>
          <label class="sr-only" for="mod-id">Patreon ID or name</label>
          <input class="input" id="mod-id" name="id" required maxlength="80" placeholder="Patreon ID or name, e.g. 12345678 or Anna" data-person-pick="mod" autocomplete="off" />
          <button class="btn btn-primary btn-sm">+ Add Moderator</button>
          <?= person_pick_js() ?>
        </form>
      </section>
      <section class="sec" id="mods-can">
        <h2 class="small-h">What Moderators Can Do</h2>
        <form method="post" class="mod-can">
          <?= form_keys('mod-can') ?>
          <?php foreach (['comments' => 'Check Comments', 'bugs' => 'Check Bug Reports and Their Comments', 'ideas' => 'Check Ideas', 'ban' => 'Ban People (Comments, Bugs, Ideas)'] as $k => $l): ?>
            <label class="toggle"><input type="checkbox" class="switch" name="<?= $k ?>" value="1" <?= $S['can'][$k] ? 'checked' : '' ?> /><span><?= e($l) ?><?= in_array($k, $off, true) ? ' <span class="muted small">(off on the site)</span>' : '' ?></span></label>
          <?php endforeach ?>
          <div class="form-actions"><button class="btn btn-primary btn-sm">Save</button></div>
        </form>
      </section>
    </div>
    <?php
}
