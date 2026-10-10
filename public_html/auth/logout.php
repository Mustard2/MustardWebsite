<?php
// Log out: end the session and remove its cookie.
require dirname(__DIR__) . '/app/_init.php';
$return = m3d_safe_return($_GET['return'] ?? null);
if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}
setcookie('m3d_session', '', ['expires' => time() - 3600, 'path' => '/', 'secure' => m3d_is_https(), 'httponly' => true, 'samesite' => 'Lax']);
m3d_redirect($return);
