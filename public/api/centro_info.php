<?php
// public/api/centro_info.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

$centro_id = (int)($_GET['centro_id'] ?? 0);
if ($centro_id <= 0) {
  echo json_encode(['ok'=>false,'msg'=>'centro_id inválido']); exit;
}

try {
  $pdo = getDB();

  // Centro + zona
  $st = $pdo->prepare("
    SELECT c.id AS centro_id, c.nombre AS centro, z.id AS zona_id, z.nombre AS zona
    FROM centro_costo c
    JOIN zona z ON z.id=c.zona_id
    WHERE c.id=? AND c.activo=1
  ");
  $st->execute([$centro_id]);
  $cz = $st->fetch();
  if (!$cz) { echo json_encode(['ok'=>false,'msg'=>'Centro no encontrado']); exit; }

  // Supervisor vigente de la zona
  $sup = $pdo->prepare("
    SELECT u.id, u.nombre
    FROM supervisor_zona sz
    JOIN usuario u ON u.id=sz.usuario_id
    WHERE sz.zona_id=? AND (sz.hasta IS NULL OR sz.hasta >= CURDATE())
      AND u.activo=1
    ORDER BY sz.desde DESC
    LIMIT 1
  ");
  $sup->execute([$cz['zona_id']]);
  $supervisor = $sup->fetch();

  // Líder vigente del centro
  $ldr = $pdo->prepare("
    SELECT u.id, u.nombre
    FROM lider_centro lc
    JOIN usuario u ON u.id=lc.usuario_id
    WHERE lc.centro_id=? AND (lc.hasta IS NULL OR lc.hasta >= CURDATE())
      AND u.activo=1
    ORDER BY lc.desde DESC
    LIMIT 1
  ");
  $ldr->execute([$centro_id]);
  $lider = $ldr->fetch();

  // Auxiliares vigentes del centro (pueden ser varios)
  $ax = $pdo->prepare("
    SELECT u.id, u.nombre
    FROM auxiliar_centro ac
    JOIN usuario u ON u.id=ac.usuario_id
    WHERE ac.centro_id=? AND (ac.hasta IS NULL OR ac.hasta >= CURDATE())
      AND u.activo=1
    ORDER BY ac.desde DESC, u.nombre
  ");
  $ax->execute([$centro_id]);
  $auxiliares = $ax->fetchAll();

  echo json_encode([
    'ok' => true,
    'zona' => ['id'=>(int)$cz['zona_id'],'nombre'=>$cz['zona']],
    'centro' => ['id'=>(int)$cz['centro_id'],'nombre'=>$cz['centro']],
    'supervisor' => $supervisor ? ['id'=>(int)$supervisor['id'],'nombre'=>$supervisor['nombre']] : null,
    'lider'      => $lider ? ['id'=>(int)$lider['id'],'nombre'=>$lider['nombre']] : null,
    'auxiliares' => array_map(fn($r)=>['id'=>(int)$r['id'],'nombre'=>$r['nombre']], $auxiliares),
  ]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
}
