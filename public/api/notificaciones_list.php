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

$uid   = (int)($_SESSION['usuario_id'] ?? 0);
$limit = max(1, min(50, (int)($_GET['limit'] ?? 10)));

try {
  $pdo = get_pdo();
  $st = $pdo->prepare("
    SELECT id, titulo, cuerpo, url, ref_type, ref_id, codigo, creado_en, leido_en
    FROM notificacion
    WHERE usuario_id=?
    ORDER BY (leido_en IS NULL) DESC, id DESC
    LIMIT {$limit}
  ");
  $st->execute([$uid]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  echo json_encode(['ok'=>true, 'items'=>$items]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
