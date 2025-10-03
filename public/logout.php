<?php
require_once __DIR__ . '/../includes/session_boot.php';

// destruir sesión de forma segura
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"] ?? '', $params["secure"], $params["httponly"]);
}
session_destroy();

$dest = $_GET['redirect'] ?? (BASE_URL . '/login.php');
header('Location: ' . $dest);
exit;
