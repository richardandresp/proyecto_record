<?php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

if (empty($_SESSION['usuario_id'])) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'unauthorized']); exit;
}

try {
  $pdo = getDB();

  $centro_id = (int)($_GET['centro_id'] ?? 0);
  if ($centro_id <= 0) { echo json_encode(['ok'=>true,'pdv'=>[]]); exit; }

  $today = (new DateTimeImmutable('today'))->format('Y-m-d');
  $d30   = (new DateTimeImmutable('-30 days'))->format('Y-m-d');

  $desde = $_GET['desde'] ?? $d30;
  $hasta = $_GET['hasta'] ?? $today;
  $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde) ? $desde : $d30;
  $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta) ? $hasta : $today;

  $where  = [];
  $params = [];

  $where[] = "h.centro_id = ?";
  $params[] = $centro_id;

  $where[] = "h.fecha BETWEEN ? AND ?";
  $params[] = $desde; $params[] = $hasta;

  // scope -> estado
$scope = (string)($_GET['scope'] ?? '');
if     ($scope === 'pend') $where[] = "h.estado = 'pendiente'";
elseif ($scope === 'venc') $where[] = "h.estado = 'vencido'";
elseif ($scope === 'resp') $where[] = "h.estado IN ('respondido_lider','respondido_admin')";


  // visibilidad por rol
  $rol = $_SESSION['rol'] ?? 'lectura';
  $uid = (int)($_SESSION['usuario_id'] ?? 0);
  $join = '';

  if ($rol === 'lider') {
    $join = "JOIN lider_centro lc ON lc.centro_id = h.centro_id
             AND h.fecha >= lc.desde
             AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
             AND lc.usuario_id = ?";
    array_unshift($params, $uid);
  } elseif ($rol === 'supervisor') {
    $join = "JOIN supervisor_zona sz ON sz.zona_id = h.zona_id
             AND h.fecha >= sz.desde
             AND (sz.hasta IS NULL OR h.fecha <= sz.hasta)
             AND sz.usuario_id = ?";
    array_unshift($params, $uid);
  } elseif ($rol === 'auxiliar') {
    $join = "JOIN auxiliar_centro ax ON ax.centro_id = h.centro_id
             AND h.fecha >= ax.desde
             AND (ax.hasta IS NULL OR h.fecha <= ax.hasta)
             AND ax.usuario_id = ?";
    array_unshift($params, $uid);
  }

  $sql = "
    SELECT h.pdv_codigo, h.nombre_pdv, COUNT(*) AS conteo
    FROM hallazgo h
    $join
    WHERE ".implode(' AND ',$where)."
    GROUP BY h.pdv_codigo, h.nombre_pdv
    ORDER BY conteo DESC, h.nombre_pdv ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['ok'=>true,'pdv'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'server']);
}
