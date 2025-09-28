<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/notify.php';

login_required();
header('Content-Type: application/json; charset=utf-8');

$uid = (int)($_SESSION['usuario_id'] ?? 0);
$action = $_POST['action'] ?? '';
$id     = (int)($_POST['id'] ?? 0);

try {
  if ($action === 'one' && $id > 0) {
    notif_mark_read($id, $uid);
    echo json_encode(['ok'=>true]); exit;
  }
  if ($action === 'all') {
    notif_mark_all_read($uid);
    echo json_encode(['ok'=>true]); exit;
  }
  echo json_encode(['ok'=>false, 'error'=>'Acción inválida']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'Error interno']);
}
