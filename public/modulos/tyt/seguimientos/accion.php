<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";
if (!tyt_can('tyt.cv.review')) { http_response_code(403); exit('Acceso denegado'); }

$pdo = getDB();

$accion   = $_POST['accion'] ?? '';
$pid      = (int)($_POST['persona_id'] ?? 0);
$rid      = (int)($_POST['requisito_id'] ?? 0);
$respUser = isset($_POST['responsable_id']) && $_POST['responsable_id'] !== '' ? (int)$_POST['responsable_id'] : null;
$respArea = isset($_POST['responsable_area_id']) && $_POST['responsable_area_id'] !== '' ? (int)$_POST['responsable_area_id'] : null;
$motivo   = trim($_POST['motivo'] ?? '');
$back_area = isset($_POST['back_area']) ? (int)$_POST['back_area'] : 0;

if ($pid<=0 || $rid<=0 || $accion==='') {
  header("Location: ". tyt_url('seguimientos/listar.php') . "?e=Parámetros inválidos"); exit;
}

/* Helpers */
function cfg_get(PDO $pdo, string $k, $def) {
  $s=$pdo->prepare("SELECT valor FROM tyt_config WHERE clave=:k");
  $s->execute([':k'=>$k]);
  $v=$s->fetchColumn();
  return $v!==false ? $v : $def;
}
function obs(PDO $pdo, int $personaId, string $comentario, ?string $toEstado=null) {
  $st = $pdo->prepare("SELECT estado FROM tyt_cv_persona WHERE id=:id");
  $st->execute([':id'=>$personaId]);
  $est = $st->fetchColumn() ?: 'revision';
  $ins = $pdo->prepare("INSERT INTO tyt_cv_observacion (persona_id, autor_id, estado_origen, estado_destino, comentario)
                        VALUES (:p,:u,:from,:to,:c)");
  $ins->execute([
    ':p'=>$personaId,
    ':u'=>$_SESSION['usuario_id'] ?? 0,
    ':from'=>$est,
    ':to'=>$toEstado ?: $est,
    ':c'=>$comentario
  ]);
}
function notify_user(PDO $pdo, int $userId, string $asunto, string $cuerpo): void {
  $q = $pdo->prepare("SELECT email FROM usuario WHERE id=:id");
  $q->execute([':id'=>$userId]);
  $email = $q->fetchColumn();
  if (!$email) return;
  $from = (string)cfg_get($pdo,'tyt.mail_from','no-reply@localhost');
  @mail($email, $asunto, $cuerpo, "From: $from\r\nContent-Type: text/plain; charset=UTF-8");
}
function maybe_notify_on_request(PDO $pdo, int $personaId, ?int $respUserId, string $requisitoNombre, string $motivo): void {
  $sub = "T&T: solicitud de documento - $requisitoNombre";
  $body = "Se solicitó el documento: $requisitoNombre.\nMotivo: " . ($motivo?:'N/D');

  if ((int)cfg_get($pdo,'tyt.notif_registrador_on_request','0') === 1) {
    $q = $pdo->prepare("SELECT u.id FROM tyt_cv_persona p LEFT JOIN usuario u ON u.id=p.creado_por WHERE p.id=:id");
    $q->execute([':id'=>$personaId]);
    $uid = (int)$q->fetchColumn();
    if ($uid>0) notify_user($pdo, $uid, $sub, $body);
  }
  if ($respUserId && (int)cfg_get($pdo,'tyt.notif_responsable_on_request','0') === 1) {
    notify_user($pdo, $respUserId, $sub, $body);
  }
}

try {
  $pdo->beginTransaction();

  // Garantiza fila del check
  $pdo->prepare("INSERT IGNORE INTO tyt_cv_requisito_check (persona_id,requisito_id,cumple)
                 VALUES (:p,:r,0)")
      ->execute([':p'=>$pid, ':r'=>$rid]);

  if ($accion==='completar') {
    $pdo->prepare("UPDATE tyt_cv_requisito_check
                   SET cumple=1, verificado_por=:u, verificado_en=NOW()
                   WHERE persona_id=:p AND requisito_id=:r")
        ->execute([':u'=>$_SESSION['usuario_id']??0, ':p'=>$pid, ':r'=>$rid]);

    $nr = $pdo->prepare("SELECT nombre FROM tyt_cv_requisito WHERE id=:r");
    $nr->execute([':r'=>$rid]);
    $rname = $nr->fetchColumn() ?: ('Requisito #'.$rid);

    obs($pdo, $pid, "Requisito completado: ".$rname);
    $pdo->commit();

    $qs=['ok'=>'Requisito marcado como completo.']; if($back_area>0){ $qs['area_id']=$back_area; }
    header("Location: ". tyt_url('seguimientos/listar.php') . '?' . http_build_query($qs)); exit;

  } elseif ($accion==='asignar') {
    if ($respUser!==null) {
      $pdo->prepare("UPDATE tyt_cv_requisito_check
                     SET responsable_id=:u, responsable_area_id=NULL
                     WHERE persona_id=:p AND requisito_id=:r")
          ->execute([':u'=>$respUser, ':p'=>$pid, ':r'=>$rid]);
      $nu = $pdo->prepare("SELECT nombre FROM usuario WHERE id=:id"); $nu->execute([':id'=>$respUser]);
      obs($pdo, $pid, "Responsable asignado (usuario): ".$nu->fetchColumn());
      $msg = "Responsable (usuario) asignado.";
    } elseif ($respArea!==null) {
      $pdo->prepare("UPDATE tyt_cv_requisito_check
                     SET responsable_area_id=:a, responsable_id=NULL
                     WHERE persona_id=:p AND requisito_id=:r")
          ->execute([':a'=>$respArea, ':p'=>$pid, ':r'=>$rid]);
      $na = $pdo->prepare("SELECT nombre FROM tyt_area WHERE id=:id"); $na->execute([':id'=>$respArea]);
      obs($pdo, $pid, "Responsable asignado (área): ".$na->fetchColumn());
      $msg = "Responsable (área) asignado.";
    } else {
      throw new RuntimeException('Debes seleccionar Usuario o Área');
    }
    $pdo->commit();
    $qs=['ok'=>$msg]; if($back_area>0){ $qs['area_id']=$back_area; }
    header("Location: ". tyt_url('seguimientos/listar.php') . '?' . http_build_query($qs)); exit;

  } elseif ($accion==='solicitar') {
    // Solicitud con motivo; si estaba completo y se venció/malo, reabre (cumple=0)
    $pdo->prepare("UPDATE tyt_cv_requisito_check
                   SET solicitado_en=NOW(),
                       solicitado_por=:u,
                       solicitado_cont=COALESCE(solicitado_cont,0)+1,
                       solicitado_motivo=:m,
                       cumple=0
                   WHERE persona_id=:p AND requisito_id=:r")
        ->execute([':u'=>$_SESSION['usuario_id']??0, ':m'=>$motivo, ':p'=>$pid, ':r'=>$rid]);

    $nr = $pdo->prepare("SELECT nombre FROM tyt_cv_requisito WHERE id=:r");
    $nr->execute([':r'=>$rid]);
    $rname = $nr->fetchColumn() ?: ('Requisito #'.$rid);

    obs($pdo, $pid, "Solicitud de documento: $rname. Motivo: ".($motivo?:'N/D'));

    $rc = $pdo->prepare("SELECT responsable_id FROM tyt_cv_requisito_check WHERE persona_id=:p AND requisito_id=:r");
    $rc->execute([':p'=>$pid, ':r'=>$rid]);
    $respUserId = (int)$rc->fetchColumn();
    $pdo->commit();

    maybe_notify_on_request($pdo, $pid, $respUserId ?: null, $rname, $motivo);

    $qs=['ok'=>'Solicitud registrada.']; if($back_area>0){ $qs['area_id']=$back_area; }
    header("Location: ". tyt_url('seguimientos/listar.php') . '?' . http_build_query($qs)); exit;

  } else {
    throw new RuntimeException('Acción no reconocida');
  }

} catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  $qs=['e'=>$e->getMessage()]; if($back_area>0){ $qs['area_id']=$back_area; }
  header("Location: ". tyt_url('seguimientos/listar.php') . '?' . http_build_query($qs)); exit;
}
