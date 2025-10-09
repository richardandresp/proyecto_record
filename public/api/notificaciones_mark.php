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

$uid    = (int)($_SESSION['usuario_id'] ?? 0);
$action = $_POST['action'] ?? '';

try {
  $pdo = get_pdo();

  if ($action === 'one') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
      $st = $pdo->prepare("UPDATE notificacion SET leido_en=NOW() WHERE id=? AND usuario_id=? AND leido_en IS NULL");
      $st->execute([$id, $uid]);
    }
    echo json_encode(['ok'=>true]); exit;
  }

  if ($action === 'all') {
    $st = $pdo->prepare("UPDATE notificacion SET leido_en=NOW() WHERE usuario_id=? AND leido_en IS NULL");
    $st->execute([$uid]);
    echo json_encode(['ok'=>true]); exit;
  }

  echo json_encode(['ok'=>false, 'error'=>'bad_action']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
