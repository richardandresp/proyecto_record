<?php
// public/dev/inspect_pdv.php
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
header('Content-Type: text/html; charset=utf-8');

$pdo = getDB();
$db  = $pdo->query("SELECT DATABASE()")->fetchColumn();

// Candidatos de tabla PDV
$candidates = ['pdv','punto_venta','pdv_catalogo'];

echo "<h2>Inspección de tabla PDV</h2>";
echo "<p><b>Base de datos:</b> ".htmlspecialchars($db)."</p>";

$foundTable = null;
foreach ($candidates as $t) {
  $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=? LIMIT 1");
  $st->execute([$db, $t]);
  if ($st->fetchColumn()) { $foundTable = $t; break; }
}

if (!$foundTable) {
  echo "<p style='color:#b00'>No se encontró ninguna de estas tablas: <code>".implode(', ',$candidates)."</code></p>";
  exit;
}

echo "<p><b>Tabla detectada:</b> <code>{$foundTable}</code></p>";

// Columnas
$cols = $pdo->prepare("
  SELECT column_name, data_type, is_nullable, column_key
  FROM information_schema.columns
  WHERE table_schema=? AND table_name=?
  ORDER BY ordinal_position
");
$cols->execute([$db, $foundTable]);
$columns = $cols->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Columnas</h3>";
echo "<table border='1' cellpadding='6' cellspacing='0'>
        <tr><th>column_name</th><th>data_type</th><th>is_nullable</th><th>column_key</th></tr>";
foreach ($columns as $c) {
  echo "<tr>
          <td><code>".htmlspecialchars($c['column_name'])."</code></td>
          <td>".htmlspecialchars($c['data_type'])."</td>
          <td>".htmlspecialchars($c['is_nullable'])."</td>
          <td>".htmlspecialchars($c['column_key'])."</td>
        </tr>";
}
echo "</table>";

// 10 filas de ejemplo
$sql = "SELECT * FROM `{$foundTable}` LIMIT 10";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Muestras (10 filas)</h3>";
if (!$rows) {
  echo "<p>(Sin datos)</p>";
} else {
  // encabezados
  echo "<table border='1' cellpadding='6' cellspacing='0'><tr>";
  foreach (array_keys($rows[0]) as $h) echo "<th>".htmlspecialchars($h)."</th>";
  echo "</tr>";
  // filas
  foreach ($rows as $r) {
    echo "<tr>";
    foreach ($r as $v) {
      $txt = is_null($v) ? '<i>null</i>' : htmlspecialchars((string)$v);
      echo "<td>{$txt}</td>";
    }
    echo "</tr>";
  }
  echo "</table>";
}

echo "<hr><p>Usa esta info para ajustar <code>pdv_suggest.php</code> (ej. nombre de tabla y columnas como <code>codigo</code>, <code>nombre</code>, FK a centro, etc.).</p>";
