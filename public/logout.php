<?php
// public/logout.php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/flash.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $params["path"], $params["domain"],
    $params["secure"], $params["httponly"]
  );
}
session_destroy();

set_flash('success', 'Sesión cerrada correctamente.');
header('Location: ' . BASE_URL . '/login.php');
exit;
