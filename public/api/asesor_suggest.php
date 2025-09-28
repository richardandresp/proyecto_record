<?php declare(strict_types=1);
session_start();

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = getDB();
  $q = trim((string)($_GET['q'] ?? ''));
  if ($q === '') { echo json_encode([]); exit; }

  $sql = "
    SELECT a.cedula, a.nombre
    FROM asesor a
    WHERE a.cedula LIKE ? OR a.nombre LIKE ?
    ORDER BY (a.cedula = ?) DESC, a.nombre ASC
    LIMIT 10
  ";
  $st = $pdo->prepare($sql);
  $st->execute(["%$q%", "%$q%", $q]);

  echo json_encode($st->fetchAll(PDO::FETCH_ASSOC) ?: [], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
  echo json_encode([]);
}
