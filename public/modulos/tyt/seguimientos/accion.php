<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";
if (!tyt_can('tyt.cv.review')) { http_response_code(403); exit('Acceso denegado'); }

$pdo = getDB();
$accion = $_POST['accion'] ?? '';
$pid    = (int)($_POST['persona_id'] ?? 0);
$rid    = (int)($_POST['requisito_id'] ?? 0);
$area   = isset($_POST['responsable_area_id']) && $_POST['responsable_area_id']!=='' ? (int)$_POST['responsable_area_id'] : null;
$back_area = isset($_POST['back_area']) ? (int)$_POST['back_area'] : 0;

if ($pid<=0 || $rid<=0 || $accion==='') {
  header("Location: ". tyt_url('seguimientos/listar.php') . "?e=Parametros invalidos"); exit;
}

try{
  $pdo->beginTransaction();
  $pdo->prepare("INSERT IGNORE INTO tyt_cv_requisito_check (persona_id,requisito_id,cumple) VALUES (:p,:r,0)")
      ->execute([':p'=>$pid, ':r'=>$rid]);

  if ($accion==='completar') {
    $pdo->prepare("UPDATE tyt_cv_requisito_check SET cumple=1, verificado_por=:u, verificado_en=NOW() WHERE persona_id=:p AND requisito_id=:r")
        ->execute([':u'=>$_SESSION['usuario_id']??0, ':p'=>$pid, ':r'=>$rid]);
    $msg="Requisito marcado como completo.";
  } elseif ($accion==='asignar_area') {
    $pdo->prepare("UPDATE tyt_cv_requisito_check SET responsable_area_id=:a WHERE persona_id=:p AND requisito_id=:r")
        ->execute([':a'=>$area, ':p'=>$pid, ':r'=>$rid]);
    $msg="Área asignada.";
  } else {
    throw new RuntimeException('Acción no reconocida');
  }

  $pdo->commit();
  $qs=['ok'=>$msg]; if($back_area>0){ $qs['area_id']=$back_area; }
  header("Location: ". tyt_url('seguimientos/listar.php') . '?' . http_build_query($qs)); exit;

} catch(Throwable $e){
  if($pdo->inTransaction()) $pdo->rollBack();
  $qs=['e'=>$e->getMessage()]; if($back_area>0){ $qs['area_id']=$back_area; }
  header("Location: ". tyt_url('seguimientos/listar.php') . '?' . http_build_query($qs)); exit;
}