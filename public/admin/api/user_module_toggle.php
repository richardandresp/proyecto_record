<?php
// public/admin/api/user_module_toggle.php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/session_boot.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

login_required();
require_roles(['admin']); // solo admin

$pdo = function_exists('get_pdo') ? get_pdo() : getDB();

$userId   = (int)($_POST['user_id'] ?? 0);
$modId    = (int)($_POST['modulo_id'] ?? 0);
$enable   = isset($_POST['enable']) ? (int)!!$_POST['enable'] : null;

if ($userId <= 0 || $modId <= 0 || $enable === null) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'params']);
  exit;
}

try {
  // INSERT … ON DUPLICATE KEY UPDATE
  $sql = "INSERT INTO usuario_modulo (usuario_id, modulo_id, activo)
          VALUES (?, ?, ?)
          ON DUPLICATE KEY UPDATE activo = VALUES(activo)";
  $st = $pdo->prepare($sql);
  $st->execute([$userId, $modId, $enable]);

  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false, 'error'=>'db']);
}
