<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/notify.php';

// 1) Hallazgos que cruzaron a vencido en los últimos 15 min (o marca / estado recién cambiado)
$pdo = get_pdo();
$st = $pdo->query("
  SELECT id FROM hallazgo 
  WHERE estado='vencido'
    AND TIMESTAMPDIFF(MINUTE, actualizado_en, NOW()) BETWEEN 0 AND 15
");
$ids = $st->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids as $hid) {
  $h = load_hallazgo_with_names((int)$hid);
  if ($h) {
    notify_hallazgo_to_responsables($h, 'H_VENCIDO',
      'Hallazgo vencido #'.(int)$h['id'],
      'SLA de 48h superado. Revisa el caso.');
  }
}

// 2) Aviso de 24h (pendientes que quedan <=24h para vencer)
$st2 = $pdo->query("
  SELECT id FROM hallazgo
  WHERE estado='pendiente'
    AND TIMESTAMPDIFF(HOUR, NOW(), fecha_limite) BETWEEN 0 AND 24
");
$ids2 = $st2->fetchAll(PDO::FETCH_COLUMN);
foreach ($ids2 as $hid) {
  $h = load_hallazgo_with_names((int)$hid);
  if ($h) {
    notify_hallazgo_to_responsables($h, 'H_SLA_24H',
      'Quedan < 24h - Hallazgo #'.(int)$h['id'],
      'Queda menos de 1 día para el vencimiento.');
  }
}

echo "OK\n";
