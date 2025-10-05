<?php
// public/admin/api/role_perms_save.php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/session_boot.php';
header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit; }

require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

login_required();
require_roles(['admin']); // Solo admin guarda

$pdo = get_pdo();

$rol_id    = (int)($_POST['rol_id'] ?? 0);
$modulo_id = (int)($_POST['modulo_id'] ?? 0);
$perm_ids  = $_POST['permiso_ids'] ?? []; // array de IDs

if ($rol_id<=0 || $modulo_id<=0 || !is_array($perm_ids)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'parámetros inválidos']); exit;
}

// Sanitizar a enteros únicos
$perm_ids = array_values(array_unique(array_map('intval', $perm_ids)));

try {
  $pdo->beginTransaction();

  // 1) Borrar TODOS los permisos de ese rol que pertenezcan a ESTE módulo
  //    (no se tocan permisos de otros módulos)
  $stDel = $pdo->prepare("
    DELETE rp FROM rol_permiso rp
    JOIN permiso p ON p.id = rp.permiso_id
    WHERE rp.rol_id = ? AND p.modulo_id = ?
  ");
  $stDel->execute([$rol_id, $modulo_id]);

  // 2) Insertar los seleccionados (si hay)
  if ($perm_ids) {
    $stIns = $pdo->prepare("INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES (?, ?)");
    foreach ($perm_ids as $pid) {
      if ($pid > 0) $stIns->execute([$rol_id, $pid]);
    }
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'error interno']);
}
