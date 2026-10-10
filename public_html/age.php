<?php
// "I am 18 or older": remember the answer, then go back to the page the visitor wanted.
require __DIR__ . '/app/_init.php';
setcookie(AGE_COOKIE, '1', [
    'expires'  => time() + AGE_DAYS * 86400,
    'path'     => '/',
    'secure'   => m3d_is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
m3d_redirect(m3d_safe_return($_GET['return'] ?? null));
