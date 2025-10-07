<?php
// public/admin/api/role_perms_save.php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/session_boot.php';
require_once __DIR__ . '/../../../includes/env.php';
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

login_required();
require_roles(['admin']);

$pdo = function_exists('get_pdo') ? get_pdo() : getDB();

$rol_id    = (int)($_POST['rol_id'] ?? 0);
$modulo_id = (int)($_POST['modulo_id'] ?? 0);
$perms     = $_POST['perms'] ?? []; // array de IDs de permiso

if ($rol_id <= 0 || $modulo_id <= 0 || !is_array($perms)) {
  echo json_encode(['ok'=>false,'error'=>'bad_params']); exit;
}

// Sanitiza a enteros únicos
$permIds = array_values(array_unique(array_map('intval', $perms)));

try {
  $pdo->beginTransaction();

  // Borra asignaciones actuales del rol para ese módulo
  $del = $pdo->prepare("
    DELETE rp FROM rol_permiso rp
    JOIN permiso p ON p.id = rp.permiso_id
    WHERE rp.rol_id = ? AND p.modulo_id = ?
  ");
  $del->execute([$rol_id, $modulo_id]);

  // Inserta nuevas (solo las que realmente pertenecen al módulo)
  if ($permIds) {
    // Validar que esos IDs pertenecen al módulo
    $in = implode(',', array_fill(0, count($permIds), '?'));
    $st = $pdo->prepare("SELECT id FROM permiso WHERE modulo_id = ? AND id IN ($in)");
    $st->execute(array_merge([$modulo_id], $permIds));
    $validIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if ($validIds) {
      $ins = $pdo->prepare("INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) VALUES (?, ?)");
      foreach ($validIds as $pid) {
        $ins->execute([$rol_id, $pid]);
      }
    }
  }

  $pdo->commit();
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  echo json_encode(['ok'=>false,'error'=>'tx_failed']);
}
