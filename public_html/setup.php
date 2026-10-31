<?php
// /setup/  First-use setup, shown instead of every page while the site has no admin (m3d_setup_needed()):
//   1. prove it's your server: the code in private/setup-code.txt (read it with the host's file manager)
//   2. the Patreon client (Client ID, Client secret; the redirect address to register is shown)
//   3. log in with Patreon: that account becomes the owner (settings.json → owners; auth/callback.php), the campaign id is read too
//   4. the basics: name, tagline, colour, who runs the site (privacy page), support email
//   5. features: the on/off switches of Settings → Features (all off on a fresh site; Discord and S4 need keys, so only named there)
// Afterwards (settings.json → setup_done) this address leads to the admin; everything stays editable in Admin → Settings.
require __DIR__ . '/admin/_admin.php';   // the core, plus tip() and no-store / noindex headers

// Step 5: settings.json key => [title, what it does]; the same switches as Settings → Features
const SETUP_FEATURES = [
    'blog'      => ['Blog', 'News, works in progress and tutorials at /blog/, written in Admin → Blog.'],
    'bugs'      => ['Bug Reports', 'Visitors report problems with your models at /bugs/, and you mark them fixed.'],
    'comments'  => ['Comments', 'Logged-in patrons comment under model, accessory and blog post pages.'],
    'ideas'     => ['Ideas', 'Patrons suggest outfits, features and fixes for each model and accessory, and vote on them.'],
    'welcome'   => ['Welcome Page', 'A short tour shown once to new patrons, right after their first login.'],
    'showcase'  => ['Patron Renders', 'Patrons share renders made with your models; you approve each one before it shows.'],
];

$needed = m3d_setup_needed();
if (!$needed && (!m3d_is_admin($M3D) || !empty(m3d_settings()['setup_done']))) m3d_redirect(m3d_is_admin($M3D) ? '/admin/' : '/');

m3d_start_session();
$codeFile = M3D_PRIVATE . '/setup-code.txt';
$error = null;
$keys = m3d_patreon_saved();
$real = fn(string $k) => m3d_cfg_real($keys[$k] ?? '');
$callback = abs_url('/auth/callback.php', true);   // the address this setup is opened on

// ── step 1: the code from the server, so nobody but the site's owner can finish the setup ──
if ($needed && !is_file($codeFile)) {
    $code = implode('-', str_split(strtoupper(substr(bin2hex(random_bytes(8)), 0, 12)), 4));
    @file_put_contents($codeFile, "First-use setup code for this website (delete this file when the setup is done):\n\n$code\n", LOCK_EX);
    @chmod($codeFile, 0600);
}
$codeOk = !$needed || !empty($_SESSION['setup_ok']);
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($post && !m3d_check_csrf($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired, reload the page.'); }

if ($post && ($_POST['do'] ?? '') === 'code' && $needed) {
    preg_match('/\b([0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4})\b/', (string) @file_get_contents($codeFile), $m);
    $typed = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) ($_POST['code'] ?? '')));
    if (!empty($m[1]) && hash_equals(str_replace('-', '', $m[1]), $typed)) {
        session_regenerate_id(true);
        unset($_SESSION['csrf']);   // a new form token too
        $_SESSION['setup_ok'] = true;
        m3d_redirect('/setup/');
    }
    $error = 'That code doesn’t match the one in setup-code.txt. Copy it again (letters and numbers, the dashes don’t matter).';
}

// ── step 2: the Patreon client ──
if ($post && ($_POST['do'] ?? '') === 'patreon' && $codeOk && $needed) {
    $in = fn(string $k) => trim((string) ($_POST[$k] ?? ''));
    $new = ['client_id' => $in('client_id'), 'client_secret' => $in('client_secret') ?: (string) (m3d_patreon_saved()['client_secret'] ?? ''),
            'redirect_uri' => $in('redirect_uri') ?: $callback, 'campaign_id' => $in('campaign_id')];
    if (!preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $new['client_id'])) $error = 'The Client ID doesn’t look right: copy it again from your Patreon client page.';
    elseif (!preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $new['client_secret'])) $error = 'The Client secret doesn’t look right: copy it again from your Patreon client page.';
    elseif (!preg_match('#^https?://[^\s<>"]+/auth/callback\.php$#i', $new['redirect_uri'])) $error = 'The redirect address must end with /auth/callback.php, e.g. ' . $callback;
    elseif ($new['campaign_id'] !== '' && !ctype_digit($new['campaign_id'])) $error = 'The campaign ID is a number (or leave it empty: it is read when you log in).';
    elseif (!m3d_patreon_save(array_filter($new, fn($v) => $v !== ''))) $error = 'Could not write ' . basename(M3D_PRIVATE) . '/patreon.php. Check that the folder is writable.';
    else m3d_redirect('/setup/');
}

// ── step 4: the basics ──
if ($post && ($_POST['do'] ?? '') === 'basics' && !$needed && m3d_is_admin($M3D)) {
    $one = fn(string $k, int $max) => mb_substr(trim(preg_replace('/[ \t]+/u', ' ', (string) ($_POST[$k] ?? ''))), 0, $max);
    $color = strtolower($one('color', 7));
    $email = $one('legal_email', 120);
    $support = $one('support_email', 120);
    $campaign = $one('campaign_id', 20);
    if ($one('name', 40) === '') $error = 'Write the name of the site.';
    elseif (!preg_match('/^#[0-9a-f]{6}$/', $color)) $error = 'Choose the accent colour.';
    elseif (($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || ($support !== '' && !filter_var($support, FILTER_VALIDATE_EMAIL))) $error = 'Check the email addresses.';
    elseif ($campaign !== '' && !ctype_digit($campaign)) $error = 'The campaign ID is a number.';
    else {
        $id = m3d_identity();
        $id['name'] = $one('name', 40);
        $id['highlight'] = mb_strpos($id['name'], $one('highlight', 20)) !== false ? $one('highlight', 20) : '';
        $id['tagline'] = $one('tagline', 90) ?: IDENTITY_DEFAULTS['tagline'];
        $id['color'] = $color;
        $address = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['legal_address'] ?? ''))), 0, 300);
        if ($campaign !== '') m3d_patreon_save(array_merge(m3d_patreon_saved(), ['campaign_id' => $campaign]));
        $ok = m3d_save_settings(['identity' => array_intersect_key($id, IDENTITY_DEFAULTS),
            'legal' => ['name' => $one('legal_name', 120), 'address' => $address, 'email' => $email], 'support_email' => $support]);
        if ($ok) m3d_redirect('/setup/?step=features');
        $error = m3d_write_error();
    }
}

// ── step 5: features ──
if ($post && ($_POST['do'] ?? '') === 'features' && !$needed && m3d_is_admin($M3D)) {
    $all = m3d_settings(true);
    $changes = ['setup_done' => gmdate('c')];
    foreach (array_keys(SETUP_FEATURES) as $k) {
        $on = !empty($_POST['f_' . $k]);
        $cur = is_array($all[$k] ?? null) ? $all[$k] : [];
        if ($on === !empty($cur['on'])) continue;
        $changes[$k] = array_merge($cur, ['on' => $on]);
        if ($k === 'welcome' && $on) $changes[$k]['since'] = time();   // as in Settings: patrons who downloaded before count as old
    }
    if (m3d_save_settings($changes)) m3d_redirect('/admin/');
    $error = m3d_write_error();
}
if (!$post && ($_GET['skip'] ?? '') === '1' && !$needed && m3d_is_admin($M3D) && !m3d_cross_site()) {   // a link on another website skips nothing
    m3d_save_settings(['setup_done' => gmdate('c')]);
    m3d_redirect('/admin/');
}

$step = !$codeOk ? 1 : ($needed ? ($real('client_id') && $real('client_secret') && ($_GET['edit'] ?? '') !== 'keys' ? 3 : 2)
    : (($_GET['step'] ?? '') === 'features' || ($_POST['do'] ?? '') === 'features' ? 5 : 4));
$csrf = e(m3d_csrf());
$steps = [1 => 'Your Server', 2 => 'Patreon', 3 => 'Log In', 4 => 'The Basics', 5 => 'Features'];
$id = m3d_identity();
$legal = m3d_legal();
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex" />
  <title>Set Up Your Site</title>
<?= head_identity() ?>  <link rel="stylesheet" href="<?= e(asset('site.css')) ?>" />
  <link rel="stylesheet" href="<?= e(asset('admin.css')) ?>" />
<?= accent_style() ?></head>
<body class="page-setup">
  <main id="main" class="wrap setup">
    <h1>Set Up Your Site</h1>
    <ol class="setup-steps">
      <?php foreach ($steps as $n => $label): ?><li class="<?= $n < $step ? 'is-done' : ($n === $step ? 'is-now' : '') ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>><span><?= $n < $step ? '✓' : $n ?></span><?= e($label) ?></li><?php endforeach ?>
    </ol>
    <?php if ($error): ?><p class="notice"><b class="res-err">Not saved:</b> <?= e($error) ?></p><?php endif ?>
    <?php if (($_GET['login'] ?? '') === 'denied'): ?><p class="notice"><b class="res-err">Not logged in:</b> Patreon didn’t complete the login. Check the keys (and that the redirect address is registered on your Patreon client), then try again.</p><?php endif ?>

    <section class="sec setup-card">
<?php if ($step === 1): ?>
      <h2 class="small-h">Show That This Is Your Server</h2>
      <p>To make sure nobody else can take over a freshly installed site, a code has been written into a file on your server. Open it with your host’s
        file manager (in your hosting control panel):</p>
      <p class="setup-path"><code><?= e(basename(M3D_PRIVATE)) ?>/setup-code.txt</code> <span class="muted small">in the private folder, next to the site folder</span></p>
      <form method="post" class="setup-form">
        <input type="hidden" name="csrf" value="<?= $csrf ?>" /><input type="hidden" name="do" value="code" />
        <label class="field"><span class="lbl">Setup Code</span><input class="input mono" name="code" placeholder="XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" required autofocus /></label>
        <div class="row-btns"><button class="btn btn-primary">Continue</button></div>
      </form>
<?php elseif ($step === 2): ?>
      <h2 class="small-h">Connect Your Patreon</h2>
      <ol class="setup-howto">
        <li>Open <a href="https://www.patreon.com/portal/registration/register-clients" target="_blank" rel="noopener">patreon.com/portal/registration/register-clients ↗</a> and create a client (any name and description).</li>
        <li>As <b>Redirect URIs</b> enter exactly: <code class="setup-copy"><?= e($callback) ?></code></li>
        <li>Copy its <b>Client ID</b> and <b>Client Secret</b> here.</li>
      </ol>
      <form method="post" class="setup-form" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $csrf ?>" /><input type="hidden" name="do" value="patreon" />
        <label class="field"><span class="lbl">Client ID</span><input class="input" name="client_id" value="<?= e($real('client_id') ? (string) $keys['client_id'] : '') ?>" spellcheck="false" required /></label>
        <label class="field"><span class="lbl">Client Secret</span><?= key_field('Client Secret', $real('client_secret'), null, 'type="password" name="client_secret" autocomplete="new-password" spellcheck="false"' . ($real('client_secret') ? '' : ' required')) ?></label>
        <label class="field"><span class="lbl">Redirect URI</span><input class="input" name="redirect_uri" value="<?= e($real('redirect_uri') ? (string) $keys['redirect_uri'] : $callback) ?>" spellcheck="false" /></label>
        <label class="field"><span class="lbl">Campaign ID<?= tip('Optional: read from your Patreon account when you log in at the next step.') ?></span><input class="input" name="campaign_id" value="<?= e($real('campaign_id') ? (string) $keys['campaign_id'] : '') ?>" inputmode="numeric" /></label>
        <div class="row-btns"><button class="btn btn-primary">Continue</button></div>
      </form>
<?php elseif ($step === 3): ?>
      <h2 class="small-h">Log In with Your Patreon Account</h2>
      <p>The account you log in with now becomes the <b>owner</b> of this site: only it can open the admin panel. Use the account of your Patreon page
        (the creator account), so the campaign is found too.</p>
      <p class="muted small">Client ID: <code><?= e((string) $keys['client_id']) ?></code> · <a href="/setup/?edit=keys">Change the keys</a></p>
      <div class="row-btns"><a class="btn btn-patreon" href="/auth/login.php?return=%2Fsetup%2F">Log In with Patreon</a></div>
<?php elseif ($step === 4): ?>
      <h2 class="small-h">The Basics</h2>
      <p>You are the owner now<?= $real('campaign_id') ? '' : ' (the campaign could not be read: enter its ID below)' ?>. A few details, all editable later in Admin → Settings:</p>
      <form method="post" class="setup-form">
        <input type="hidden" name="csrf" value="<?= $csrf ?>" /><input type="hidden" name="do" value="basics" />
        <div class="id-grid">
          <label class="field"><span class="lbl">Site Name</span><input class="input" name="name" value="<?= e($id['name']) ?>" maxlength="40" required /></label>
          <label class="field"><span class="lbl">Highlighted Word<?= tip('Optional. Part of the name shown in the accent colour, written exactly as in the name (e.g. “Studio” in “PixelStudio”).') ?></span><input class="input" name="highlight" value="<?= e($id['highlight']) ?>" maxlength="20" /></label>
          <label class="field id-wide"><span class="lbl">Tagline</span><input class="input" name="tagline" value="<?= e($id['tagline']) ?>" maxlength="90" /></label>
          <div class="field"><span class="lbl">Accent Colour</span><span class="id-color"><input type="color" name="color" value="<?= e($id['color']) ?>" aria-label="Accent colour" /></span></div>
          <?php if (!$real('campaign_id')): ?><label class="field"><span class="lbl">Campaign ID<?= tip('Your Patreon campaign number. Only its paying members can download. Can also be set later in Settings → Patreon.') ?></span><input class="input" name="campaign_id" inputmode="numeric" /></label><?php endif ?>
        </div>
        <h3 class="setup-sub">Who Runs the Site <span class="muted small">shown on the privacy page, required in the EU</span></h3>
        <div class="id-grid">
          <label class="field"><span class="lbl">Full Name</span><input class="input" name="legal_name" value="<?= e($legal['name']) ?>" maxlength="120" /></label>
          <label class="field"><span class="lbl">Privacy Email</span><input class="input" type="email" name="legal_email" value="<?= e($legal['email']) ?>" maxlength="120" /></label>
          <label class="field id-wide"><span class="lbl">Postal Address</span><textarea class="input" name="legal_address" rows="3" maxlength="300"><?= e($legal['address']) ?></textarea></label>
          <label class="field id-wide"><span class="lbl">Support Email<?= tip('Optional. Shown on the Contacts page for legal matters about the website’s content (copyright, privacy).') ?></span><input class="input" type="email" name="support_email" value="<?= e(m3d_support_email()) ?>" maxlength="120" /></label>
        </div>
        <div class="row-btns"><button class="btn btn-primary">Continue</button><a class="btn btn-ghost" href="/setup/?step=features">Skip for Now</a></div>
      </form>
<?php else: ?>
      <h2 class="small-h">Features</h2>
      <p>Turn on what your site needs. Everything else stays off, and you can change it any time in Admin → Settings → Features.</p>
      <form method="post" class="setup-form">
        <input type="hidden" name="csrf" value="<?= $csrf ?>" /><input type="hidden" name="do" value="features" />
        <div class="setup-feats">
          <?php $all = m3d_settings(); foreach (SETUP_FEATURES as $k => [$title, $what]): ?>
            <label class="toggle setup-feat"><input type="checkbox" class="switch" name="f_<?= $k ?>" value="1" <?= !empty($all[$k]['on']) ? 'checked' : '' ?> /><span><?= e($title) ?><small><?= e($what) ?></small></span></label>
          <?php endforeach ?>
        </div>
        <p class="muted small">Discord announcements and MEGA S4 downloads need keys from those services: set them up in Settings → Features when you need them.</p>
        <div class="row-btns"><button class="btn btn-primary">Finish and Open the Admin</button><a class="btn btn-ghost" href="/setup/?skip=1">Skip for Now</a></div>
      </form>
<?php endif ?>
    </section>
  </main>
  <script src="<?= e(asset('admin.js')) ?>" defer></script>
</body>
</html>
