<?php
// public/api/pdv_lookup.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

$codigo    = trim($_GET['codigo'] ?? '');
$centro_id = (int)($_GET['centro_id'] ?? 0);

if ($codigo === '') {
  echo json_encode(['ok'=>false,'error'=>'Falta código de PDV']); exit;
}

try {
  $pdo = getDB();
  $sql = "SELECT p.nombre, p.centro_id, c.nombre AS centro
          FROM pdv p
          JOIN centro_costo c ON c.id=p.centro_id
          WHERE p.codigo=? AND p.activo=1
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$codigo]);
  $row = $st->fetch();

  if ($row) {
    echo json_encode([
      'ok'=>true,
      'found'=>true,
      'nombre'=>$row['nombre'],
      'centro_id'=>(int)$row['centro_id'],
      'centro_nombre'=>$row['centro']
    ]);
  } else {
    echo json_encode(['ok'=>true,'found'=>false]);
  }
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
