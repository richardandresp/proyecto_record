<?php
// public/api/asesor_suggest.php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode([]); exit; }

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$pdo = getDB();
// Busca por cédula o nombre (ajusta nombres de columnas si difieren)
$sql = "
  SELECT a.cedula, a.nombre
  FROM asesor a
  WHERE (a.cedula LIKE ? OR a.nombre LIKE ?)
  ORDER BY a.nombre ASC
  LIMIT 20
";
$st = $pdo->prepare($sql);
$like = "%$q%";
$st->execute([$q.'%', $like]); // cédula: empieza por…, nombre: contiene
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo json_encode($rows, JSON_UNESCAPED_UNICODE);
