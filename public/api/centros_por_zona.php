<?php
// C:\xampp\htdocs\auditoria_app\public\api\centros_por_zona.php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

// Debe haber sesión
if (empty($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

try {
  $pdo = getDB();

  $zona_id = (int)($_GET['zona_id'] ?? 0);
  if ($zona_id <= 0) {
    echo json_encode(['ok'=>true,'centros'=>[]]); exit;
  }

  // Si quieres filtrar por rol/asignaciones, aquí iría el JOIN como en otros endpoints.
  $st = $pdo->prepare("
    SELECT id, nombre
    FROM centro_costo
    WHERE activo = 1 AND zona_id = ?
    ORDER BY nombre ASC
  ");
  $st->execute([$zona_id]);

  echo json_encode(['ok'=>true,'centros'=>$st->fetchAll(PDO::FETCH_ASSOC)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server']);
}
