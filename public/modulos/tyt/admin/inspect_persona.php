<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env_mod.php';

function pdo(): PDO {
  if (function_exists('getDB')) return getDB();
  $dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
  return new PDO($dsn, DB_USER, DB_PASS ?? '', [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
}

$db = pdo();
$dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
$tbl = 'tyt_cv_persona';

header('Content-Type: text/html; charset=utf-8');
echo '<h1>Schema: '.$tbl.'</h1>';
echo '<p>BD: <b>'.htmlspecialchars($dbName).'</b></p>';

# Columnas (orden real)
$sqlCols = "
  SELECT ORDINAL_POSITION AS pos, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
  ORDER BY ORDINAL_POSITION";
$cols = $db->prepare($sqlCols);
$cols->execute([$dbName,$tbl]);
echo '<h3>Columnas (en orden)</h3><table border=1 cellpadding=6><tr><th>#</th><th>Columna</th><th>Tipo</th><th>NULL</th><th>DEFAULT</th><th>EXTRA</th><th>Comentario</th></tr>';
foreach($cols as $c){
  echo '<tr><td>'.$c['pos'].'</td><td><code>'.$c['COLUMN_NAME'].'</code></td><td>'.$c['COLUMN_TYPE'].
       '</td><td>'.$c['IS_NULLABLE'].'</td><td>'.htmlspecialchars((string)$c['COLUMN_DEFAULT']).
       '</td><td>'.$c['EXTRA'].'</td><td>'.$c['COLUMN_COMMENT'].'</td></tr>';
}
echo '</table>';

# Índices
$idx = $db->prepare("
  SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=? AND TABLE_NAME=?
  ORDER BY INDEX_NAME, SEQ_IN_INDEX");
$idx->execute([$dbName,$tbl]);
echo '<h3>Índices</h3><table border=1 cellpadding=6><tr><th>Índice</th><th>No único</th><th>Seq</th><th>Columna</th></tr>';
foreach($idx as $i){
  echo '<tr><td>'.$i['INDEX_NAME'].'</td><td>'.$i['NON_UNIQUE'].'</td><td>'.$i['SEQ_IN_INDEX'].'</td><td>'.$i['COLUMN_NAME'].'</td></tr>';
}
echo '</table>';

# DDL
$row = $db->query("SHOW CREATE TABLE `{$tbl}`")->fetch();
echo '<h3>SHOW CREATE TABLE</h3><pre>'.htmlspecialchars($row['Create Table'] ?? '').'</pre>';
