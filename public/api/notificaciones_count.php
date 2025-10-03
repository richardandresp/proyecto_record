<?php
// public/api/notificaciones_count.php
require_once __DIR__ . '/../../includes/session_boot.php';
header('Content-Type: application/json; charset=utf-8');

// No redirigir a login en APIs
if (empty($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'error' => 'unauthorized']);
  exit;
}

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

try {
  $uid = (int)($_SESSION['usuario_id'] ?? 0);
  $pdo = getDB(); // <-- usa getDB()

  $st = $pdo->prepare("SELECT COUNT(*) FROM notificacion WHERE usuario_id = ? AND leido_en IS NULL");
  $st->execute([$uid]);
  echo json_encode(['ok' => true, 'unread' => (int)$st->fetchColumn()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_error']);
}
