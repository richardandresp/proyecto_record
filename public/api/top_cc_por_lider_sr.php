<?php
// public/api/top_cc_por_lider_sr.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';
login_required();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$rol = $_SESSION['rol'] ?? 'lectura';
$uid = (int)($_SESSION['usuario_id'] ?? 0);

$lider_id = (int)($_GET['lider_id'] ?? 0);
$desde    = $_GET['desde'] ?? null;
$hasta    = $_GET['hasta'] ?? null;

if ($lider_id<=0 || !$desde || !$hasta) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>'Parámetros inválidos']); exit;
}

// Visibilidad por rol
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

// Por CC para ese líder (vigente en la fecha del hallazgo)
$sql = "SELECT c.id AS cc_id,
               c.nombre AS cc,
               SUM(h.estado='respondido_admin') AS total_admin,
               COUNT(*) AS total_all
        FROM hallazgo h
        JOIN centro_costo c ON c.id=h.centro_id
        JOIN lider_centro lc ON lc.centro_id=h.centro_id
             AND h.fecha >= lc.desde
             AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
        $joinVis
        WHERE h.fecha BETWEEN ? AND ?
          AND lc.usuario_id = ?
        GROUP BY c.id, c.nombre
        HAVING total_admin > 0
        ORDER BY total_admin DESC, c.nombre
        LIMIT 100";
$params[] = $desde;
$params[] = $hasta;
$params[] = $lider_id;

$st = $pdo->prepare($sql);
$st->execute($params);
$data = $st->fetchAll();

echo json_encode(['ok'=>true, 'items'=>$data], JSON_UNESCAPED_UNICODE);
