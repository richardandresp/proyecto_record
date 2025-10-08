<?php
// public/export_csv.php
require_once __DIR__ . '/../includes/env.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/acl_suite.php';  // <- NUEVO
require_once __DIR__ . '/../includes/acl.php';        // permisos finos internos

$uid = (int)($_SESSION['usuario_id'] ?? 0);
if (!module_enabled_for_user($uid, 'auditoria')) {
    render_403_and_exit();
}

// Además, exige permiso del módulo (ejemplo):
require_perm('auditoria.access');
login_required();

$pdo  = getDB();
$rol  = $_SESSION['rol'] ?? 'lectura';
$uid  = (int)($_SESSION['usuario_id'] ?? 0);

// -------- Parámetros de filtro --------
$hoy    = (new DateTime())->format('Y-m-d');
$hace30 = (new DateTime('-30 days'))->format('Y-m-d');

$desde    = $_GET['desde']    ?? $hace30;
$hasta    = $_GET['hasta']    ?? $hoy;
$f_zona   = (int)($_GET['zona_id']   ?? 0);
$f_centro = (int)($_GET['centro_id'] ?? 0);
$f_estado = $_GET['estado']   ?? '';   // opcional

// -------- WHERE base --------
$where  = [];
$params = [];

$where[] = "h.fecha BETWEEN ? AND ?";
$params[] = $desde;
$params[] = $hasta;

if ($f_zona)   { $where[] = "h.zona_id = ?";   $params[] = $f_zona; }
if ($f_centro) { $where[] = "h.centro_id = ?"; $params[] = $f_centro; }
if ($f_estado) { $where[] = "h.estado = ?";    $params[] = $f_estado; }

// -------- Visibilidad por rol (con vigencias) --------
$joinVis = '';
if ($rol === 'lider') {
  $joinVis = "JOIN lider_centro lc ON lc.centro_id = h.centro_id
              AND h.fecha >= lc.desde
              AND (lc.hasta IS NULL OR h.fecha <= lc.hasta)
              AND lc.usuario_id = ?";
  array_unshift($params, $uid);
} elseif ($rol === 'supervisor') {
  $joinVis = "JOIN supervisor_zona sz ON sz.zona_id = h.zona_id
              AND h.fecha >= sz.desde
              AND (sz.hasta IS NULL OR h.fecha <= sz.hasta)
              AND sz.usuario_id = ?";
  array_unshift($params, $uid);
} elseif ($rol === 'auxiliar') {
  $joinVis = "JOIN auxiliar_centro ax ON ax.centro_id = h.centro_id
              AND h.fecha >= ax.desde
              AND (ax.hasta IS NULL OR h.fecha <= ax.hasta)
              AND ax.usuario_id = ?";
  array_unshift($params, $uid);
}

// -------- Subconsulta: última respuesta (Gestión Comercial) por MAX(id) --------
$subUltimaResp = "
  SELECT hr1.hallazgo_id, hr1.respuesta
  FROM hallazgo_respuesta hr1
  INNER JOIN (
    SELECT hallazgo_id, MAX(id) AS max_id
    FROM hallazgo_respuesta
    GROUP BY hallazgo_id
  ) x ON x.hallazgo_id = hr1.hallazgo_id AND x.max_id = hr1.id
";

// -------- Consulta principal --------
$sql = "
  SELECT
    h.fecha                                                  AS FECHA,
    COALESCE(a.nombre, '')                                   AS ASESOR,
    h.cedula                                                 AS CEDULA,
    h.nombre_pdv                                             AS `NOMBRE DE PDV`,
    c.nombre                                                 AS `CENTRO DE COSTOS`,
    h.raspas_faltantes                                       AS `FALTANTE RASPA CANTIDAD`,
    h.faltante_dinero                                        AS `FALTANTE DINERO`,
    h.sobrante_dinero                                        AS `SOBRANTE DINERO`,
    h.observaciones                                          AS `OBSERVACIONES AUDITORIA`,
    COALESCE(ur.respuesta, '')                               AS `GESTIÓN COMERCIAL`
  FROM hallazgo h
  JOIN centro_costo c ON c.id = h.centro_id
  LEFT JOIN asesor a ON a.cedula = h.cedula AND a.activo = 1
  LEFT JOIN ($subUltimaResp) ur ON ur.hallazgo_id = h.id
  $joinVis
  WHERE ".implode(' AND ', $where)."
  ORDER BY h.fecha ASC, h.id ASC
";

$st = $pdo->prepare($sql);
$st->execute($params);

// -------- Preparar descarga CSV --------
$filename = 'reporte_hallazgos_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

// Excel-friendly: BOM UTF-8
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

$counter = 1; // consecutivo de filas exportadas

// Formateo de moneda COP para CSV (como texto legible en Excel)
function fmt_cop($n) {
  $n = (float)$n;
  return '$ ' . number_format($n, 0, ',', '.'); // sin decimales, separador de miles con punto
}

// Encabezados solicitados (en el orden requerido)
$headers = [
  'ITEM',
  'FECHA',
  'ASESOR',
  'CEDULA',
  'NOMBRE DE PDV',
  'CENTRO DE COSTOS',
  'FALTANTE RASPA CANTIDAD',
  'FALTANTE DINERO',
  'SOBRANTE DINERO',
  'OBSERVACIONES AUDITORIA',
  'GESTIÓN COMERCIAL'
];
fputcsv($out, $headers);

// Volcado de filas
while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
  $line = [
    $counter, // ITEM
    $row['FECHA'],
    $row['ASESOR'],
    $row['CEDULA'],
    $row['NOMBRE DE PDV'],
    $row['CENTRO DE COSTOS'],
    $row['FALTANTE RASPA CANTIDAD'],
    fmt_cop($row['FALTANTE DINERO']), // ← formateado
    fmt_cop($row['SOBRANTE DINERO']), // ← formateado
    $row['OBSERVACIONES AUDITORIA'],
    $row['GESTIÓN COMERCIAL'],
  ];
  fputcsv($out, $line);
  $counter++;
}
fclose($out);
exit;
