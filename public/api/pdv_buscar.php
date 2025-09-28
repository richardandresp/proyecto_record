<?php
// public/api/pdv_buscar.php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
  $pdo = get_pdo();
  $centro_id = (int)($_GET['centro_id'] ?? 0);
  $q = trim($_GET['q'] ?? '');

  if ($centro_id <= 0) { echo json_encode([]); exit; }

  if ($q === '') {
    // últimos 20 PDV del centro (orden alfabético)
    $st = $pdo->prepare("SELECT p.codigo, p.nombre, c.nombre AS centro
                         FROM pdv p
                         JOIN centro_costo c ON c.id = p.centro_id
                         WHERE p.centro_id = ? AND p.activo = 1
                         ORDER BY p.nombre ASC
                         LIMIT 20");
    $st->execute([$centro_id]);
  } else {
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $st = $pdo->prepare("SELECT p.codigo, p.nombre, c.nombre AS centro
                         FROM pdv p
                         JOIN centro_costo c ON c.id = p.centro_id
                         WHERE p.centro_id = ?
                           AND p.activo = 1
                           AND (p.nombre LIKE ? OR p.codigo LIKE ?)
                         ORDER BY p.nombre ASC
                         LIMIT 50");
    $st->execute([$centro_id, $like, $like]);
  }

  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'Error interno']);
}
