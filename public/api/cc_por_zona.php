<?php declare(strict_types=1);
// public/api/cc_por_zona.php
session_start();

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  login_required();

  $pdo = get_pdo();

  // --- Inputs ---
  $zona_id = (int)($_GET['zona_id'] ?? 0);
  if ($zona_id <= 0) {
    echo json_encode(['ok'=>false,'error'=>'zona_id requerido']); exit;
  }

  $today  = (new DateTimeImmutable('today'))->format('Y-m-d');
  $d30    = (new DateTimeImmutable('-30 days'))->format('Y-m-d');

  $desde  = $_GET['desde'] ?? $d30;
  $hasta  = $_GET['hasta'] ?? $today;

  // Normaliza fechas (YYYY-MM-DD)
  $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) ? $desde : $d30;
  $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta) ? $hasta : $today;

  // --- WHERE base ---
  $where  = [];
  $params = [];

  $where[] = "h.zona_id = ?";
  $params[] = $zona_id;

  $where[] = "h.fecha BETWEEN ? AND ?";
  $params[] = $desde;
  $params[] = $hasta;

  // --- Visibilidad por rol con vigencia ---
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
  } // admin/auditor ven todo

  $sql = "
    SELECT c.id, c.nombre AS cc, COUNT(*) AS conteo
    FROM hallazgo h
    JOIN centro_costo c ON c.id = h.centro_id
    $join
    WHERE ".implode(' AND ', $where)."
    GROUP BY c.id, c.nombre
    ORDER BY conteo DESC, cc ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['ok'=>true, 'centros'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  // Evita filtrar detalles sensibles en producción si no quieres
  echo json_encode(['ok'=>false, 'error'=>'Error cargando datos']);
}
