<?php
// public/modulos/tyt/admin/inspect_schema.php
declare(strict_types=1);

// Carga el boot del módulo (ajusta el path si tu estructura difiere)
require_once __DIR__ . '/../includes/env_mod.php';

function pdo(): PDO {
  // Usa getDB() si existe en tu boot; si no, intenta con constantes DB_*.
  if (function_exists('getDB')) return getDB();
  if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER')) {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    return new PDO($dsn, DB_USER, defined('DB_PASS') ? DB_PASS : '', [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
  }
  throw new RuntimeException('No hay conector de DB (getDB() o constantes DB_*).');
}

$db = pdo();
$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();

// Tablas por GET ?t=tabla1,tabla2  (por defecto: las 3 clave del módulo)
$tables = isset($_GET['t']) && trim($_GET['t']) !== ''
  ? array_map('trim', explode(',', $_GET['t']))
  : ['tyt_cv_persona','tyt_cv_requisito_check','tyt_cv_requisito'];

header('Content-Type: text/html; charset=utf-8');
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Helvetica,Arial,sans-serif;padding:16px;line-height:1.35} table{border-collapse:collapse;margin:8px 0;width:100%} th,td{border:1px solid #ddd;padding:6px 8px;font-size:14px} th{background:#f7f7f7;text-align:left} code,pre{font-family:ui-monospace,Menlo,Consolas,monospace} details{margin:10px 0;padding:6px 8px;background:#fafafa;border:1px solid #eee;border-radius:6px} summary{cursor:pointer;font-weight:600}</style>';

echo '<h1>Inspector de esquema</h1>';
echo '<div>Base de datos: <strong>'.htmlspecialchars($dbName).'</strong></div>';
echo '<div>Parámetro: <code>?t=tyt_cv_persona,tyt_cv_requisito_check</code></div>';

foreach ($tables as $raw) {
  $tbl = preg_replace('/[^a-zA-Z0-9_]/', '', $raw);
  if ($tbl === '') continue;

  echo '<hr><h2 style="margin:8px 0">'.htmlspecialchars($tbl).'</h2>';

  // Conteo de filas
  $count = 0;
  try { $count = (int)$db->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn(); } catch (\Throwable $e) {}

  echo '<div>Filas: <strong>'.$count.'</strong></div>';

  // Columnas (orden real)
  $cols = [];
  $stmt = $db->prepare("
    SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE,
           COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ORDER BY ORDINAL_POSITION
  ");
  $stmt->execute([$dbName, $tbl]);
  $cols = $stmt->fetchAll();

  echo '<details open><summary>Columnas (orden y tipos)</summary>';
  echo '<table><tr><th>#</th><th>Columna</th><th>Tipo</th><th>NULL</th><th>DEFAULT</th><th>EXTRA</th><th>Comentario</th></tr>';
  foreach ($cols as $c) {
    echo '<tr>'.
         '<td>'.(int)$c['ORDINAL_POSITION'].'</td>'.
         '<td><code>'.htmlspecialchars($c['COLUMN_NAME']).'</code></td>'.
         '<td>'.htmlspecialchars($c['COLUMN_TYPE']).'</td>'.
         '<td>'.htmlspecialchars($c['IS_NULLABLE']).'</td>'.
         '<td>'.htmlspecialchars((string)$c['COLUMN_DEFAULT']).'</td>'.
         '<td>'.htmlspecialchars($c['EXTRA']).'</td>'.
         '<td>'.htmlspecialchars($c['COLUMN_COMMENT']).'</td>'.
         '</tr>';
  }
  echo '</table></details>';

  // Índices
  $idx = [];
  $stmt = $db->prepare("
    SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, CARDINALITY
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
    ORDER BY INDEX_NAME, SEQ_IN_INDEX
  ");
  $stmt->execute([$dbName, $tbl]);
  $idx = $stmt->fetchAll();

  echo '<details><summary>Índices</summary>';
  echo '<table><tr><th>Índice</th><th>No único</th><th>Seq</th><th>Columna</th><th>Collation</th><th>Cardinality</th></tr>';
  foreach ($idx as $i) {
    echo '<tr>'.
         '<td>'.htmlspecialchars($i['INDEX_NAME']).'</td>'.
         '<td>'.(int)$i['NON_UNIQUE'].'</td>'.
         '<td>'.(int)$i['SEQ_IN_INDEX'].'</td>'.
         '<td>'.htmlspecialchars($i['COLUMN_NAME']).'</td>'.
         '<td>'.htmlspecialchars((string)$i['COLLATION']).'</td>'.
         '<td>'.htmlspecialchars((string)$i['CARDINALITY']).'</td>'.
         '</tr>';
  }
  echo '</table></details>';

  // Triggers (si los hay)
  $trigs = [];
  try {
    $stmt = $db->prepare("SHOW TRIGGERS FROM `{$dbName}`");
    $stmt->execute();
    $allTrigs = $stmt->fetchAll();
    foreach ($allTrigs as $t) {
      if (isset($t['Table']) && $t['Table'] === $tbl) $trigs[] = $t;
    }
  } catch (\Throwable $e) {}
  echo '<details><summary>Triggers</summary>';
  if (!$trigs) {
    echo '<div style="padding:8px">Sin triggers para esta tabla.</div>';
  } else {
    echo '<table><tr><th>Trigger</th><th>Evento</th><th>Timing</th><th>Statement</th></tr>';
    foreach ($trigs as $t) {
      echo '<tr>'.
           '<td>'.htmlspecialchars($t['Trigger'] ?? '').'</td>'.
           '<td>'.htmlspecialchars($t['Event'] ?? '').'</td>'.
           '<td>'.htmlspecialchars($t['Timing'] ?? '').'</td>'.
           '<td><code>'.htmlspecialchars($t['Statement'] ?? '').'</code></td>'.
           '</tr>';
    }
    echo '</table>';
  }
  echo '</details>';

  // DDL
  $ddl = '';
  try {
    $row = $db->query("SHOW CREATE TABLE `{$tbl}`")->fetch();
    $ddl = $row['Create Table'] ?? '';
  } catch (\Throwable $e) {}
  echo '<details><summary>SHOW CREATE TABLE</summary><pre>'.htmlspecialchars($ddl).'</pre></details>';
}
