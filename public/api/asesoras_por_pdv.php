<?php declare(strict_types=1);
// public/api/asesoras_por_pdv.php
session_start();

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
  login_required();
  $pdo = get_pdo();

  $centro_id  = (int)($_GET['centro_id'] ?? 0);
  $pdv_codigo = trim($_GET['pdv_codigo'] ?? '');
  if ($centro_id <= 0 || $pdv_codigo === '') {
    echo json_encode(['ok'=>false,'error'=>'centro_id y pdv_codigo requeridos']); exit;
  }

  $today = (new DateTimeImmutable('today'))->format('Y-m-d');
  $d30   = (new DateTimeImmutable('-30 days'))->format('Y-m-d');
  $desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['desde'] ?? '') ? $_GET['desde'] : $d30;
  $hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['hasta'] ?? '') ? $_GET['hasta'] : $today;

  $where  = ["h.centro_id = ?", "h.pdv_codigo = ?", "h.fecha BETWEEN ? AND ?"];
  $params = [$centro_id, $pdv_codigo, $desde, $hasta];

  // Visibilidad por rol
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
    SELECT h.cedula, COUNT(*) AS conteo
    FROM hallazgo h
    $join
    WHERE ".implode(' AND ', $where)."
    GROUP BY h.cedula
    ORDER BY conteo DESC, h.cedula ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode(['ok'=>true, 'asesoras'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>'Error cargando asesoras']);
}
