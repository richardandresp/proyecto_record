<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function login_required() {
  if (empty($_SESSION['usuario_id'])) {
    header('Location: /login.php'); exit;
  }
}
function current_role() { return $_SESSION['rol'] ?? 'lectura'; }
function require_role(array $roles) {
  if (!in_array(current_role(), $roles, true)) {
    http_response_code(403);
    echo "No tienes permiso para acceder."; exit;
  }
}
