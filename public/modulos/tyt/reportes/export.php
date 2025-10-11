<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

if (!tyt_can('tyt.cv.export')) { http_response_code(403); exit('Acceso denegado'); }

$pdo = getDB();
$q      = trim($_GET['q']      ?? '');
$estado = trim($_GET['estado'] ?? '');
$perfil = trim($_GET['perfil'] ?? '');
$desde  = trim($_GET['desde']  ?? '');
$hasta  = trim($_GET['hasta']  ?? '');
$venc   = trim($_GET['venc']   ?? '');

function cfg_get($pdo,$k,$def){ $s=$pdo->prepare("SELECT valor FROM tyt_config WHERE clave=:k"); $s->execute([':k'=>$k]); $v=$s->fetchColumn(); return $v!==false?(int)$v:$def; }
$sla  = cfg_get($pdo,'tyt.sla_dias',12);
$vmin = cfg_get($pdo,'tyt.semaforo.verde_min',6);
$nmin = cfg_get($pdo,'tyt.semaforo.naranja_min',1);

// Ámbito por zona/CC si no-admin
$scope=[];$sparams=[];
if (!tyt_can('tyt.admin')) {
  if (!empty($_SESSION['zona_id'])) { $scope[]="zona_id=:sz"; $sparams[':sz']=(int)$_SESSION['zona_id']; }
}
$where = $scope ? 'WHERE '.implode(' AND ',$scope) : '';
$conds=[];$params=$sparams;

if ($q!==''){ $conds[]="(doc_numero LIKE :q OR nombre_completo LIKE :q2)"; $params[':q']="%$q%"; $params[':q2']="%$q%"; }
if ($estado!==''){ $conds[]="estado=:e"; $params[':e']=$estado; }
if ($perfil!==''){ $conds[]="perfil=:p"; $params[':p']=$perfil; }
if ($desde!==''){ $conds[]="DATE(fecha_estado)>=:d1"; $params[':d1']=$desde; }
if ($hasta!==''){ $conds[]="DATE(fecha_estado)<=:d2"; $params[':d2']=$hasta; }

// filtro de semáforo si aplica
if ($venc==='verde'){
  $conds[]="(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) >= :vmin_v";
  $conds[]="estado NOT IN ('entregado_comercial','rechazado')";
  $params[':sla']=$sla; $params[':vmin_v']=$vmin;
} elseif ($venc==='naranja'){
  $conds[]="(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) >= :nmin_v";
  $conds[]="(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) < :vmin_v";
  $conds[]="estado NOT IN ('entregado_comercial','rechazado')";
  $params[':sla']=$sla; $params[':nmin_v']=$nmin; $params[':vmin_v']=$vmin;
} elseif ($venc==='rojo'){
  $conds[]="(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) < :nmin_v";
  $conds[]="estado NOT IN ('entregado_comercial','rechazado')";
  $params[':sla']=$sla; $params[':nmin_v']=$nmin;
}

$wx = $conds ? (($where ? "$where AND " : "WHERE ").implode(' AND ',$conds)) : $where;

$sql = "SELECT id,doc_tipo,doc_numero,nombre_completo,perfil,estado,zona_id,cc_id,cargo_id,fecha_estado,
        DATEDIFF(CURDATE(), DATE(fecha_estado)) AS dias,
        (:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) AS faltan
        FROM tyt_cv_persona $wx ORDER BY fecha_estado DESC";
$st=$pdo->prepare($sql);
foreach($params as $k=>$v){ $st->bindValue($k,$v); }
$st->bindValue(':sla',$sla,PDO::PARAM_INT);
$st->execute();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="tyt_export_'.date('Ymd_His').'.csv"');
$out = fopen('php://output','w');
fputcsv($out, ['ID','Doc','Número','Nombre','Perfil','Estado','Zona','CC','Cargo','Fecha Estado','Días','Faltan']);
while($r=$st->fetch(PDO::FETCH_ASSOC)){
  fputcsv($out, [
    $r['id'],$r['doc_tipo'],$r['doc_numero'],$r['nombre_completo'],$r['perfil'],$r['estado'],
    $r['zona_id'],$r['cc_id'],$r['cargo_id'],$r['fecha_estado'],$r['dias'],$r['faltan']
  ]);
}
fclose($out);