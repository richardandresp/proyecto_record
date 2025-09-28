<?php declare(strict_types=1);
// public/api/cc_catalogo.php

session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// Helper local: no dependemos de redirecciones en APIs
if (!function_exists('api_is_logged_in')) {
  function api_is_logged_in(): bool {
    return !empty($_SESSION['usuario_id']);
  }
}

if (!api_is_logged_in()) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); 
  exit;
}

try {
  $pdo = get_pdo(); // o getDB()

  $zona_id = (int)($_GET['zona_id'] ?? 0);

  if ($zona_id > 0) {
    $st = $pdo->prepare("SELECT id, nombre FROM centro_costo WHERE activo=1 AND zona_id=? ORDER BY nombre");
    $st->execute([$zona_id]);
  } else {
    // opcional: permitir todos si zona_id=0
    $st = $pdo->query("SELECT id, nombre FROM centro_costo WHERE activo=1 ORDER BY nombre");
  }

  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode(['ok'=>true,'centros'=>$rows], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Error cargando centros']);
}
