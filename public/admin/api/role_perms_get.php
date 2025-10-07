<?php
// public/admin/api/role_perms_get.php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/session_boot.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

login_required();
require_roles(['admin']);

$pdo = function_exists('get_pdo') ? get_pdo() : getDB();

$rol_id    = (int)($_GET['rol_id'] ?? 0);
$modulo_id = (int)($_GET['modulo_id'] ?? 0);

if ($rol_id <= 0 || $modulo_id <= 0) {
  echo json_encode(['ok'=>false,'error'=>'bad_params']); exit;
}

// catálogo de permisos del módulo
$st = $pdo->prepare("SELECT id, clave, nombre FROM permiso WHERE modulo_id = ? ORDER BY clave ASC");
$st->execute([$modulo_id]);
$perms = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// permisos ya asignados al rol en este módulo
$st2 = $pdo->prepare("
  SELECT p.id
  FROM rol_permiso rp
  JOIN permiso p ON p.id = rp.permiso_id
  WHERE rp.rol_id = ? AND p.modulo_id = ?
");
$st2->execute([$rol_id, $modulo_id]);
$assignedIds = array_map('intval', $st2->fetchAll(PDO::FETCH_COLUMN));

$items = [];
foreach ($perms as $p) {
  $items[] = [
    'id'      => (int)$p['id'],
    'clave'   => (string)$p['clave'],
    'nombre'  => (string)$p['nombre'],
    'checked' => in_array((int)$p['id'], $assignedIds, true),
  ];
}

echo json_encode(['ok'=>true, 'items'=>$items], JSON_UNESCAPED_UNICODE);
