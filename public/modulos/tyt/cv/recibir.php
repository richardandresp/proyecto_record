<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

if (!tyt_can('tyt.cv.review')) { http_response_code(403); exit('Acceso denegado'); }

$pdo = getDB();
$pid = (int)($_POST['persona_id'] ?? 0);
if ($pid <= 0) { header("Location: ".tyt_url('cv/listar.php')."?e=ID inválido"); exit; }

try {
  // Trae estado actual
  $st = $pdo->prepare("SELECT estado FROM tyt_cv_persona WHERE id=:id");
  $st->execute([':id'=>$pid]);
  $estadoActual = $st->fetchColumn();

  if (!$estadoActual) {
    header("Location: ".tyt_url('cv/listar.php')."?e=Registro no encontrado"); exit;
  }
  if ($estadoActual !== 'pendiente_recepcion') {
    header("Location: ".tyt_url('cv/detalle.php?id='.$pid.'&e=Solo se puede recibir cuando está en pendiente_recepcion')); exit;
  }

  // Cambia estado → recibido + traza
  $pdo->beginTransaction();

  $up = $pdo->prepare("UPDATE tyt_cv_persona
                       SET estado='recibido', fecha_estado=NOW()
                       WHERE id=:id");
  $up->execute([':id'=>$pid]);

  $obs = $pdo->prepare("INSERT INTO tyt_cv_observacion (persona_id, autor_id, estado_origen, estado_destino, comentario)
                        VALUES (:p,:u,:from,:to,:c)");
  $obs->execute([
    ':p'=>$pid,
    ':u'=>($_SESSION['usuario_id'] ?? 0),
    ':from'=>$estadoActual,
    ':to'=>'recibido',
    ':c'=>'Recepción física confirmada por Gestión Humana'
  ]);

  $pdo->commit();

  header("Location: ".tyt_url('cv/detalle.php?id='.$pid.'&ok=Recepción física confirmada')); exit;

} catch(Throwable $e){
  if ($pdo->inTransaction()) $pdo->rollBack();
  header("Location: ".tyt_url('cv/detalle.php?id='.$pid.'&e='.urlencode($e->getMessage()))); exit;
}
