<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
// --- Shim para linters / proyectos sin helper ---
if (!function_exists('is_logged_in')) {
  function is_logged_in(): bool {
    return !empty($_SESSION['usuario_id']);
  }
}


header('Content-Type: application/json; charset=utf-8');

if (!is_logged_in()) {
  http_response_code(401);
  echo json_encode(['ok'=>false, 'error'=>'not_logged']);
  exit;
}

$uid = (int)($_SESSION['usuario_id'] ?? 0);
if ($uid <= 0) {
  echo json_encode(['ok'=>true, 'unread'=>0]);
  exit;
}

try {
  $pdo = get_pdo();
  $st = $pdo->prepare("SELECT COUNT(*) FROM notificacion WHERE usuario_id=? AND leido_en IS NULL");
  $st->execute([$uid]);
  $count = (int)$st->fetchColumn();
  echo json_encode(['ok'=>true, 'unread'=>$count]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
