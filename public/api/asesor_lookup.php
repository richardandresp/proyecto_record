<?php
// public/api/asesor_lookup.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

$ced = trim($_GET['cedula'] ?? '');
if ($ced === '') {
  echo json_encode(['ok'=>false,'error'=>'Falta cédula']); exit;
}

try {
  $pdo = getDB();

  // Detectar columna documento (cedula|documento)
  $hasCed = (bool)$pdo->query("SHOW COLUMNS FROM asesor LIKE 'cedula'")->fetch();
  $hasDoc = (bool)$pdo->query("SHOW COLUMNS FROM asesor LIKE 'documento'")->fetch();

  if (!$hasCed && !$hasDoc) {
    // Tabla asesor no tiene columna identificadora conocida
    echo json_encode(['ok'=>true,'found'=>false]); exit;
  }

  $docCol = $hasCed ? 'cedula' : 'documento';
  $st = $pdo->prepare("SELECT nombre FROM asesor WHERE {$docCol}=? AND activo=1 LIMIT 1");
  $st->execute([$ced]);
  $row = $st->fetch();

  if ($row) {
    echo json_encode(['ok'=>true,'found'=>true,'nombre'=>$row['nombre']]); 
  } else {
    echo json_encode(['ok'=>true,'found'=>false]);
  }

} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
