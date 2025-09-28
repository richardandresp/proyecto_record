<?php declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = getDB();

  $q        = trim((string)($_GET['q'] ?? ''));
  $zona_id  = (int)($_GET['zona_id'] ?? 0);
  $centro_id= (int)($_GET['centro_id'] ?? 0);
  if ($q === '') { echo json_encode([]); exit; }

  $sql = "
    SELECT p.codigo, p.nombre, c.nombre AS centro, z.nombre AS zona
    FROM pdv p
    JOIN centro_costo c ON c.id = p.centro_id
    JOIN zona z         ON z.id = c.zona_id
    WHERE (p.codigo LIKE ? OR p.nombre LIKE ?)
  ";
  $params = ["%$q%", "%$q%"];

  if ($centro_id > 0) { $sql .= " AND p.centro_id = ?"; $params[] = $centro_id; }
  elseif ($zona_id > 0) { $sql .= " AND c.zona_id = ?"; $params[] = $zona_id; }

  $sql .= " ORDER BY (p.codigo = ?) DESC, p.nombre ASC LIMIT 10";
  $params[] = $q;

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
  echo json_encode([]);
}
