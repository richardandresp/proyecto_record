<?php
// public/api/notificaciones_list.php
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
  $uid   = (int)($_SESSION['usuario_id'] ?? 0);
  $limit = max(1, min(20, (int)($_GET['limit'] ?? 10)));

  $pdo = getDB(); // <-- usa getDB()

  // No dependemos de columna "codigo" aquí para evitar 1054 si no existe
  $sql = "SELECT id, titulo, cuerpo, url, creado_en, leido_en
          FROM notificacion
          WHERE usuario_id = ?
          ORDER BY COALESCE(leido_en, creado_en) DESC
          LIMIT ?";
  $st = $pdo->prepare($sql);
  $st->bindValue(1, $uid,   PDO::PARAM_INT);
  $st->bindValue(2, $limit, PDO::PARAM_INT);
  $st->execute();

  echo json_encode(['ok' => true, 'items' => $st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'server_error']);
}
