<?php
// public/api/top_lideres_sin_respuesta.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

$desde = $_GET['desde'] ?? null;
$hasta = $_GET['hasta'] ?? null;

if (!$desde || !$hasta) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Parámetros inválidos']); exit;
}

// Visibilidad por rol (vigencia)
$joinVis = '';
$params  = [];
if ($rol === 'lider') {
  $joinVis = "JOIN lider_centro lcfilt ON lcfilt.centro_id = h.centro_id
              AND h.fecha >= lcfilt.desde
              AND (lcfilt.hasta IS NULL OR h.fecha <= lcfilt.hasta)
              AND lcfilt.usuario_id = ?";
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

// Asignación líder vigente al momento del hallazgo
// Contamos total de hallazgos y total respondido_admin
$sql = "SELECT u.id AS lider_id,
               u.nombre AS lider,
               SUM(h.estado='respondido_admin') AS total_admin,
               COUNT(*) AS total_all
        FROM hallazgo h
        JOIN lider_centro lc ON lc.centro_id = h.centro_id
             AND h.fecha >= lc.desde
             AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
        JOIN usuario u ON u.id = lc.usuario_id
        $joinVis
        WHERE h.fecha BETWEEN ? AND ?
        GROUP BY u.id, u.nombre
        HAVING total_admin > 0
        ORDER BY total_admin DESC, u.nombre
        LIMIT 50";
$params[] = $desde;
$params[] = $hasta;

$st = $pdo->prepare($sql);
$st->execute($params);
$items = $st->fetchAll();

echo json_encode(['ok'=>true, 'items'=>$items], JSON_UNESCAPED_UNICODE);
