<?php
// public/api/top_cc_por_zona.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

$zona_id = (int)($_GET['zona_id'] ?? 0);
$desde   = $_GET['desde'] ?? null;
$hasta   = $_GET['hasta'] ?? null;

if ($zona_id <= 0 || !$desde || !$hasta) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Parámetros inválidos']);
  exit;
}

// Visibilidad por rol (igual que dashboard/listado)
$joinVis = '';
$params  = [];
if ($rol === 'lider') {
  $joinVis = "JOIN lider_centro lc ON lc.centro_id = h.centro_id
              AND h.fecha >= lc.desde
              AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
              AND lc.usuario_id = ?";
  $params[] = $uid;
} elseif ($rol === 'supervisor') {
  $joinVis = "JOIN supervisor_zona sz ON sz.zona_id = h.zona_id
              AND h.fecha >= sz.desde
              AND (sz.hasta IS NULL OR h.fecha <= sz.hasta)
              AND sz.usuario_id = ?";
  $params[] = $uid;
} elseif ($rol === 'auxiliar') {
  $joinVis = "JOIN auxiliar_centro ax ON ax.centro_id = h.centro_id
              AND h.fecha >= ax.desde
              AND (ax.hasta IS NULL OR h.fecha <= ax.hasta)
              AND ax.usuario_id = ?";
  $params[] = $uid;
}

// Query: CC por zona con conteo
$sql = "SELECT
          c.id   AS cc_id,
          c.nombre AS cc,
          COUNT(*) AS total_all,
          SUM(h.estado='vencido') AS total_vencidos
        FROM hallazgo h
        JOIN centro_costo c ON c.id = h.centro_id
        $joinVis
        WHERE h.zona_id = ? AND h.fecha BETWEEN ? AND ?
        GROUP BY c.id, c.nombre
        ORDER BY total_all DESC, c.nombre
        LIMIT 100";

$params[] = $zona_id;
$params[] = $desde;
$params[] = $hasta;

$st = $pdo->prepare($sql);
$st->execute($params);
$data = $st->fetchAll();

echo json_encode(['ok'=>true, 'items'=>$data], JSON_UNESCAPED_UNICODE);
