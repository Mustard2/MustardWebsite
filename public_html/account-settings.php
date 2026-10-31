<?php
// /account/settings/  The logged-in visitor's own settings (Pi picked sketch A: one column of boxes; since 2026-10-31 each box under its icon + title, the profile a banner): Profile (the Patreon picture),
// Notifications (which rows their bell shows), Ignored Updates (Stop Ignoring), Followed Bug Reports (Unfollow), Patron Renders (what
// the Share window starts with). Each box shows only while its feature is on and applies to this visitor. Forms post here, then back
// with ?done= / ?err= (a floating message). Choices live in the person's bell file (app/bell.php: `off`, `render`), avatars.json (the
// picture), ignored.json and the reports' `fol`: all in privacy.php and Personal Data.
require __DIR__ . '/app/_init.php';
header('X-Robots-Tag: noindex');

$p = m3d_patron($M3D);
$id = (string) ($p['id'] ?? '');
$preview = !empty($p['preview']);
$creator = m3d_is_admin($M3D) && !$preview;   // the creator's bell is set in Admin → Settings → Notifications; they can't follow reports
$post = fn(string $k) => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
$back = fn(string $q, string $sec) => m3d_redirect('/account/settings/?' . $q . '#' . $sec);

// ── Saving ──
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    maintenance_gate();
    $do = $post('do');
    if (!$p || $preview) m3d_redirect('/account/settings/');
    if (!m3d_check_csrf($_POST['csrf'] ?? null)) $back('err=csrf', 'profile');
    switch ($do) {
        case 'picture':   // Show My Patreon Picture: off deletes the copy and keeps it from coming back at the next login
            if (!m3d_avatars_on()) $back('', 'profile');
            $show = !empty($_POST['show']);
            $back(m3d_avatar_choose($id, $show, (string) ($p['pic'] ?? '')) ? 'done=' . ($show ? 'pic-on' : 'pic-off') : 'err=save', 'profile');
        case 'bell':   // the switches shown (ev[]) that aren't ticked (on[]) are off; events of features that are off keep their choice
            if ($creator) $back('', 'bell');
            $shown = array_intersect(array_keys(UBELL_EVENTS), (array) ($_POST['ev'] ?? []));
            $on = array_map('strval', (array) ($_POST['on'] ?? []));
            $keep = array_diff(array_keys(m3d_ubell_state($p)['off']), $shown);
            $back(m3d_ubell_set_off($p, [...$keep, ...array_diff($shown, $on)]) ? 'done=bell' : 'err=save', 'bell');
        case 'unignore':
            [$kind, $slug] = [$post('kind'), $post('slug')];
            $r = in_array($kind, M3D_ITEM_KINDS, true) ? m3d_ignore_update($p, $kind, $slug, false) : false;
            $back($r === null ? 'err=save' : 'done=unignore', 'ignored');
        case 'unfollow':
            if (!m3d_bug_can_follow($p) || !($b = m3d_bug((int) ($_POST['n'] ?? 0))) || !m3d_bug_visible($b, $p)) $back('', 'follows');
            if (!m3d_bug_toggle_ok()) $back('err=wait', 'follows');
            $code = m3d_like_code($id);
            $r = m3d_bug_change((int) $b['n'], function (array $b) use ($code) { $b['fol'] = m3d_bug_followers($b); unset($b['fol'][$code]); return $b; });
            $back($r ? 'done=unfollow' : 'err=save', 'follows');
        case 'render':
            $named = !empty($_POST['named']);
            $link = $named ? m3d_render_link_clean($post('link_t'), $post('link_h')) : [];
            if ($link === null) $back('err=link', 'renders');
            $back(m3d_render_prefs_save($p, $named, $link) ? 'done=render' : 'err=save', 'renders');
    }
    $back('', 'profile');
}

$done = [
    'pic-on' => '✓ Picture shown.', 'pic-off' => '✓ Picture hidden.',
    'bell' => '✓ Saved.', 'unignore' => '✓ Update shown.', 'unfollow' => '✓ Unfollowed.', 'render' => '✓ Saved.',
];
$errs = ['csrf' => 'Your session expired: please try again.', 'save' => 'Not saved. Please try again.', 'wait' => 'Too many changes in a short time: please wait a few minutes.',
    'link' => 'That link isn’t an X or Patreon profile. Type your username, or paste your profile address.'];
if (isset($done[$d = m3d_get_text('done')])) site_flash(e($done[$d]));
if (isset($errs[$d = m3d_get_text('err')])) site_flash(e($errs[$d]), 'err');

layout_start('Settings', 'Your settings on ' . site_name() . '.', 'page-account');
$csrf = $p ? e(m3d_csrf()) : '';
$keys = fn(string $do) => '<input type="hidden" name="csrf" value="' . $csrf . '"><input type="hidden" name="do" value="' . $do . '">';
$sw = fn(string $name, string $value, bool $on, string $title, string $line = '') => '<label class="us-row"><span class="us-t"><b>' . e($title) . '</b>'
    . ($line !== '' ? '<span class="muted small">' . e($line) . '</span>' : '') . '</span>'
    . '<input type="checkbox" class="switch" name="' . $name . '" value="' . e($value) . '"' . ($on ? ' checked' : '') . '></label>';
$noscript = '<noscript><div class="form-actions"><button class="btn btn-primary btn-sm">Save</button></div></noscript>';
$head = fn(string $icon, string $title, string $line, int $n = 0) => section_head($icon, $title, $line, $n ? '<span class="us-n">' . $n . '</span>' : '');
?>
  <div class="wrap account-page us-page">
    <h1>Settings</h1>
  <?php if (!$p): ?>
    <section class="acc-card us-card">
      <p>Log in with Patreon to change your settings.</p>
      <p><a class="btn btn-patreon" rel="nofollow" href="/auth/login.php?return=%2Faccount%2Fsettings%2F">Log In with Patreon</a></p>
    </section>
  <?php elseif ($preview): ?>
    <p class="notice">Settings can’t be changed while you preview the site as a patron.</p>
  <?php else: ?>
    <?php // ── Profile ──
      $picOn = m3d_avatars_on(); ?>
    <section class="acc-card us-hero" id="profile">   <?php // the profile as a banner on top (Pi: sketch C's, 2026-10-31) ?>
      <div class="us-preview"><?= avatar_html($id, (string) $p['name'], $creator, 'ava-xl') ?><span class="us-t"><b><?= e($p['name']) ?></b><span class="muted small">Patreon ID <?= e($id) ?></span></span></div>
      <?php if ($picOn): $picOff = m3d_avatar_off($id); ?>
      <form method="post" action="/account/settings/" data-autosubmit>
        <?= $keys('picture') ?>
        <?= $sw('show', '1', !$picOff, 'Show My Patreon Picture') ?>
        <?= $noscript ?>
      </form>
      <?php endif ?>
    </section>

    <?php // ── Notifications: the bell's events that are on for the site ──
      $events = array_filter(UBELL_EVENTS, fn($k) => m3d_ubell_event($k), ARRAY_FILTER_USE_KEY);
      if (!$creator && m3d_ubell_for($p) && $events): $off = m3d_ubell_state($p)['off']; ?>
    <section class="us-sec" id="bell">
      <?= $head('bell', 'Notifications', 'What your bell tells you about.') ?>
      <div class="acc-card us-card">
        <form method="post" action="/account/settings/" data-autosubmit>
          <?= $keys('bell') ?>
          <?php foreach ($events as $k => $title): ?>
            <input type="hidden" name="ev[]" value="<?= $k ?>"><?= $sw('on[]', $k, !isset($off[$k]), $title) ?>
          <?php endforeach ?>
          <?= $noscript ?>
        </form>
      </div>
    </section>
    <?php elseif ($creator): ?>
    <section class="us-sec" id="bell">
      <?= $head('bell', 'Notifications', 'What your bell tells you about.') ?>
      <div class="acc-card us-card">
        <p class="us-empty muted">Your bell is set in <a href="/admin/settings.php?tab=notify">Admin → Settings → Notifications</a>.</p>
      </div>
    </section>
    <?php endif ?>

    <?php // ── Ignored Updates (data/ignored.json): the ones still hiding an update (a downloaded or newer version clears them) ──
      $ign = [];
      $upd = m3d_updates_for($p);
      foreach (m3d_ignored_updates($p) as $key => $label) {
          [$kind, $slug] = explode(':', (string) $key, 2) + [1 => ''];
          if (($upd[$kind][$slug] ?? null) === $label && ($it = m3d_item($kind, $slug)) && m3d_can_view($kind, $it)) $ign[] = [$kind, $it, (string) $label];
      }
      if ($ign || !empty($p['access'])): ?>
    <section class="us-sec" id="ignored">
      <?= $head('ignored', 'Ignored Updates', 'Updates you hid from your bell and the catalogs.', count($ign)) ?>
      <div class="acc-card us-card">
      <?php if (!$ign): ?><p class="us-empty muted">You aren’t ignoring any updates.</p><?php else: ?>
      <ul class="us-list">
        <?php foreach ($ign as [$kind, $it, $label]): [$src, $hidden] = card_pic($kind, $it); ?>
        <li>
          <a class="us-thumb<?= $src === '' ? ' is-empty' : '' ?>" href="<?= e(m3d_item_url($kind, $it['slug'])) ?>" tabindex="-1" aria-hidden="true"><?php if ($src !== ''): ?><img src="<?= e($src) ?>" alt="" loading="lazy"<?= $hidden ? ' class="is-blurred"' : '' ?>><?php endif ?></a>
          <span class="us-t"><a href="<?= e(m3d_item_url($kind, $it['slug'])) ?>"><b><?= e($kind === 'models' ? m3d_model_name($it) : (string) $it['name']) ?></b></a><span class="muted small">Ignoring <?= e(bug_version_words($label)) ?></span></span>
          <form method="post" action="/account/settings/"><?= $keys('unignore') ?><input type="hidden" name="kind" value="<?= $kind ?>"><input type="hidden" name="slug" value="<?= e($it['slug']) ?>"><button class="btn btn-ghost btn-sm">Stop Ignoring</button></form>
        </li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
      </div>
    </section>
    <?php endif ?>

    <?php // ── Followed Bug Reports (the reports' `fol`) ──
      if (m3d_bugs_on() && m3d_bug_can_follow($p)): $fol = m3d_bug_following($p); krsort($fol); ?>
    <section class="us-sec" id="follows">
      <?= $head('follows', 'Followed Bug Reports', 'News on these reports shows in your bell.', count($fol)) ?>
      <div class="acc-card us-card">
      <?php if (!$fol): ?><p class="us-empty muted">You don’t follow any reports.</p><?php else: ?>
      <ul class="us-list">
        <?php foreach (array_keys($fol) as $n): if (!($b = m3d_bug((int) $n))) continue; $ab = m3d_bug_about($b); ?>
        <li>
          <span class="us-bug">#<?= (int) $n ?></span>
          <span class="us-t"><a href="/bugs/<?= (int) $n ?>/"><b><?= e((string) ($b['title'] ?? '')) ?></b></a><span class="muted small"><?= e($ab['name']) ?> · <?= bug_status_badge($b) ?></span></span>
          <form method="post" action="/account/settings/"><?= $keys('unfollow') ?><input type="hidden" name="n" value="<?= (int) $n ?>"><button class="btn btn-ghost btn-sm">Unfollow</button></form>
        </li>
        <?php endforeach ?>
      </ul>
      <?php endif ?>
      </div>
    </section>
    <?php endif ?>

    <?php // ── Patron Renders: what the Share window starts with ──
      if (m3d_showcase_on() && (!empty($p['access']) || $creator) && !m3d_banned($p, 'renders')): $rp = m3d_render_prefs($p); ?>
    <section class="us-sec" id="renders">
      <?= $head('renders', 'Patron Renders', 'What the Share window starts with when you share a render.') ?>
      <div class="acc-card us-card">
      <form method="post" action="/account/settings/" data-us-render>
        <?= $keys('render') ?>
        <?= $sw('named', '1', $rp['named'], 'Show My Name') ?>
        <div class="us-row us-link" data-sc-link>
          <span class="us-t"><b>Link on My Name</b></span>
          <span class="sc-link-row">
            <span class="us-net" data-sc-link-ico="<?= e($rp['link']['t'] ?? '') ?>" aria-hidden="true"><?php   // the network's icon, like Admin → Settings → Social Links
              foreach (RENDER_LINKS as $k => $_): ?><i data-net="<?= $k ?>"><?= social_icon($k) ?></i><?php endforeach ?><i data-net=""><svg viewBox="0 0 24 24"><path d="M9 15l6-6M11 6l.5-.5a4.2 4.2 0 0 1 6 6l-.5.5M13 18l-.5.5a4.2 4.2 0 0 1-6-6l.5-.5M4 4l16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></i></span>
            <select class="input" name="link_t" aria-label="Network" data-sc-link-t><option value="">No Link</option>
              <?php foreach (RENDER_LINKS as $k => [$label]): ?><option value="<?= $k ?>"<?= ($rp['link']['t'] ?? '') === $k ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
            </select>
            <input class="input" name="link_h" maxlength="200" autocomplete="off" placeholder="Your username or profile address" aria-label="Username or profile address" value="<?= e(m3d_render_link_text($rp['link'])) ?>" data-sc-link-h>
          </span>
        </div>
        <div class="form-actions"><button class="btn btn-primary btn-sm">Save</button></div>
      </form>
      </div>
    </section>
    <?php endif ?>
  <?php endif ?>
  </div>
<?php layout_end();
