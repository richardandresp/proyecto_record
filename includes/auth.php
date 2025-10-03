<?php
require_once __DIR__ . '/session_boot.php';

function login_required(): void {
  if (empty($_SESSION['usuario_id'])) {
    $dest = $_SERVER['REQUEST_URI'] ?? '/auditoria_app/public/dashboard.php';
    header('Location: ' . BASE_URL . '/login.php?redirect=' . urlencode($dest));
    exit;
  }
}

function require_roles(array $allowed): void {
  $rol = $_SESSION['rol'] ?? 'lectura';
  if (!in_array($rol, $allowed, true)) {
    http_response_code(403);
    exit('No tienes permiso para acceder.');
  }
}
