<?php
require_once __DIR__.'/../../includes/session_boot.php';
require_once __DIR__.'/../../includes/env.php';
require_once __DIR__.'/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
if (empty($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode(['ok'=>false]); exit; }

$pdo = getDB();

$centro_id = (int)($_GET['centro_id'] ?? 0);
$pdv_codigo = trim($_GET['pdv_codigo'] ?? '');
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-30 days'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');

$where = ["h.fecha BETWEEN ? AND ?"];
$params = [$desde, $hasta];

if ($centro_id > 0) { $where[] = "h.centro_id = ?"; $params[] = $centro_id; }
if ($pdv_codigo !== '') { $where[] = "h.pdv_codigo = ?"; $params[] = $pdv_codigo; }

$sql = "
  SELECT h.cedula,
         COALESCE(a.nombre, '') AS nombre,   -- ← nombre de la asesora
         COUNT(*) AS conteo
  FROM hallazgo h
  LEFT JOIN asesor a ON a.cedula = h.cedula   -- ← asegúrate que esta tabla/campo existen
  WHERE ".implode(' AND ', $where)."
  GROUP BY h.cedula, a.nombre
  ORDER BY conteo DESC, a.nombre ASC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['ok'=>true,'asesoras'=>$rows], JSON_UNESCAPED_UNICODE);
