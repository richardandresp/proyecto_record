<?php
// public/tools/db_schema_dump.php
// ⚠️ Úsalo solo localmente. Borra el archivo cuando termines.

require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';

$pdo = getDB();
$db  = DB_NAME ?? $pdo->query('SELECT DATABASE()')->fetchColumn();

$OUT_DIR = __DIR__ . '/../../var';
@mkdir($OUT_DIR, 0775, true);
$OUT = $OUT_DIR . '/schema_' . $db . '.txt';

ob_start();

echo "Esquema de base de datos: {$db}\n";
echo str_repeat('=', 80) . "\n\n";

// Tablas
$tables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
$views  = $pdo->query("SHOW FULL TABLES WHERE Table_type='VIEW'")->fetchAll(PDO::FETCH_NUM);

// Función helper
function section($title){ echo "\n" . str_repeat('-',80) . "\n{$title}\n" . str_repeat('-',80) . "\n"; }

// Listado general
section('LISTADO DE TABLAS');
foreach ($tables as $t) { echo "- {$t[0]}\n"; }

if ($views) {
  section('LISTADO DE VISTAS');
  foreach ($views as $v) { echo "- {$v[0]}\n"; }
}

// Detalle por tabla
foreach ($tables as $t) {
  $tbl = $t[0];

  section("TABLA: {$tbl}");

  // Describe
  echo "\nCOLUMNAS:\n";
  $desc = $pdo->query("SHOW FULL COLUMNS FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
  printf("%-24s %-16s %-8s %-10s %-10s %-20s\n", 'Field','Type','Null','Key','Default','Extra');
  foreach ($desc as $c) {
    printf("%-24s %-16s %-8s %-10s %-10s %-20s\n",
      $c['Field'], $c['Type'], $c['Null'], $c['Key'], (string)$c['Default'], $c['Extra']);
  }

  // Índices
  echo "\nINDICES:\n";
  $idx = $pdo->query("SHOW INDEX FROM `{$tbl}`")->fetchAll(PDO::FETCH_ASSOC);
  if ($idx) {
    printf("%-20s %-10s %-10s %-10s %-10s %-10s\n", 'Key_name','Non_unique','Seq','Column','Card','Type');
    foreach ($idx as $i) {
      printf("%-20s %-10s %-10s %-10s %-10s %-10s\n",
        $i['Key_name'], $i['Non_unique'], $i['Seq_in_index'], $i['Column_name'], $i['Cardinality'] ?? '-', $i['Index_type']);
    }
  } else {
    echo "(sin índices)\n";
  }

  // FKs (si hay)
  echo "\nFOREIGN KEYS:\n";
  $fkSql = "
    SELECT
      kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
      rc.UPDATE_RULE, rc.DELETE_RULE
    FROM information_schema.KEY_COLUMN_USAGE kcu
    JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
      ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
     AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
    WHERE kcu.TABLE_SCHEMA = DATABASE()
      AND kcu.TABLE_NAME = ?
      AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
    ORDER BY kcu.CONSTRAINT_NAME, kcu.POSITION_IN_UNIQUE_CONSTRAINT
  ";
  $st = $pdo->prepare($fkSql); $st->execute([$tbl]);
  $fks = $st->fetchAll(PDO::FETCH_ASSOC);
  if ($fks) {
    foreach ($fks as $fk) {
      echo "- {$fk['CONSTRAINT_NAME']}: ({$fk['COLUMN_NAME']}) -> {$fk['REFERENCED_TABLE_NAME']}.{$fk['REFERENCED_COLUMN_NAME']}  ".
           "[ON UPDATE {$fk['UPDATE_RULE']} ON DELETE {$fk['DELETE_RULE']}]\n";
    }
  } else {
    echo "(sin claves foráneas)\n";
  }

  // Conteo y 5 muestras
  echo "\nMUESTRAS (hasta 5 filas):\n";
  try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `{$tbl}`")->fetchColumn();
    echo "Total filas: {$count}\n";
    $sample = $pdo->query("SELECT * FROM `{$tbl}` LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sample as $row) {
      echo ' - ' . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
    }
  } catch (Throwable $e) {
    echo "(no se pudo leer datos: {$e->getMessage()})\n";
  }

  // DDL
  echo "\nCREATE TABLE:\n";
  $create = $pdo->query("SHOW CREATE TABLE `{$tbl}`")->fetch(PDO::FETCH_ASSOC);
  echo ($create['Create Table'] ?? '') . "\n";
}

if ($views) {
  foreach ($views as $v) {
    $vw = $v[0];
    section("VISTA: {$vw}");
    $create = $pdo->query("SHOW CREATE VIEW `{$vw}`")->fetch(PDO::FETCH_ASSOC);
    echo ($create['Create View'] ?? '') . "\n";
  }
}

// Guardar archivo
$txt = ob_get_clean();
file_put_contents($OUT, $txt);
header('Content-Type: text/plain; charset=utf-8');
echo "OK. Archivo generado: {$OUT}\n\n";
echo "Ábrelo desde el explorador o descarga aquí:\n";
$public = rtrim(BASE_URL,'/') . '/var/' . basename($OUT);
echo $public . "\n";
