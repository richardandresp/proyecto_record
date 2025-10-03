<?php
// public/api/pdv_suggest.php
require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

if (empty($_SESSION['usuario_id'])) { http_response_code(401); echo json_encode([]); exit; }

try {
  $pdo = getDB();

  $q         = trim($_GET['q'] ?? '');
  $zona_id   = (int)($_GET['zona_id'] ?? 0);
  $centro_id = (int)($_GET['centro_id'] ?? 0);

  // Requerimos al menos 2 caracteres para no saturar
  if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

  // WHERE dinámico
  $where  = [];
  $params = [];

  // Solo PDV activos
  $where[] = "p.activo = 1";

  // match por código exacto si es numérico; siempre permitir LIKE por nombre/código
  if (ctype_digit($q)) {
    $where[]  = "(p.codigo = ? OR p.nombre LIKE ? OR p.codigo LIKE ?)";
    $params[] = $q;
    $params[] = "%$q%";
    $params[] = "%$q%";
  } else {
    $where[]  = "(p.codigo LIKE ? OR p.nombre LIKE ?)";
    $params[] = "%$q%";
    $params[] = "%$q%";
  }

  // Filtro por centro (si llega)
  if ($centro_id > 0) {
    $where[]  = "p.centro_id = ?";
    $params[] = $centro_id;
  }

  // Filtro por zona (usa p.zona_id si no es NULL; si es NULL, usa c.zona_id)
  if ($zona_id > 0) {
    $where[]  = "COALESCE(p.zona_id, c.zona_id) = ?";
    $params[] = $zona_id;
  }

  $sql = "
    SELECT
      p.codigo        AS codigo,
      p.nombre        AS nombre,
      c.nombre        AS centro,
      z.nombre        AS zona
    FROM pdv p
    LEFT JOIN centro_costo c ON c.id = p.centro_id
    LEFT JOIN zona z          ON z.id = COALESCE(p.zona_id, c.zona_id)
    WHERE " . implode(' AND ', $where) . "
    ORDER BY
      CASE WHEN p.codigo = ? THEN 0 ELSE 1 END,
      p.nombre ASC
    LIMIT 20
  ";
  // Prioriza match exacto por código
  $params[] = $q;

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([]);
}
