<?php
// Log out: end the session and remove its cookie. The site's own "Log Out" links do it at once (GET); a link on another website
// (m3d_cross_site()) only shows a "Log Out" button, which posts here with the form token, so no other site can log anyone out.
require dirname(__DIR__) . '/app/_init.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$return = m3d_safe_return($post ? ($_POST['return'] ?? null) : ($_GET['return'] ?? null));
if (session_status() === PHP_SESSION_ACTIVE && ($post ? !m3d_check_csrf($_POST['csrf'] ?? null) : m3d_cross_site())) {
    header('Cache-Control: no-store');
    layout_start('Log Out', 'Log out of ' . site_name() . '.', 'page-gate');
    ?>
  <section class="wrap gate">
    <h1>Log Out?</h1>
    <p class="lead">Press the button to log out on this device.</p>
    <form class="cta" method="post" action="/auth/logout.php">
      <input type="hidden" name="csrf" value="<?= e(m3d_csrf()) ?>" />
      <input type="hidden" name="return" value="<?= e($return) ?>" />
      <button class="btn btn-primary" type="submit">Log Out</button>
      <a class="btn btn-ghost" href="<?= e($return) ?>">Stay Logged In</a>
    </form>
  </section>
<?php
    layout_end();
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}
setcookie('m3d_session', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => m3d_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
m3d_redirect($return);
