<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";
if (function_exists('user_has_perm') && !user_has_perm('tyt.admin')) { http_response_code(403); exit('Acceso denegado'); }
$pdo = getDB();

function cfg_get($pdo,$k,$def){ $s=$pdo->prepare("SELECT valor FROM config WHERE clave=:k"); $s->execute([':k'=>$k]); $v=$s->fetchColumn(); return $v!==false?$v:$def; }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $sla = max(1,(int)($_POST['sla_dias']??12));
  $vmin = (int)($_POST['verde_min']??6);
  $nmin = (int)($_POST['naranja_min']??1);

  $up = $pdo->prepare("REPLACE INTO config (clave, valor) VALUES (:k,:v)");
  foreach ([
    'tyt.sla_dias'=>$sla,
    'tyt.semaforo.verde_min'=>$vmin,
    'tyt.semaforo.naranja_min'=>$nmin
  ] as $k=>$v){ $up->execute([':k'=>$k, ':v'=>(string)$v]); }
  header("Location: ".tety_url('admin/semaforo.php')."?ok=1"); exit;
}

$sla = (int)cfg_get($pdo,'tyt.sla_dias',12);
$vmin= (int)cfg_get($pdo,'tyt.semaforo.verde_min',6);
$nmin= (int)cfg_get($pdo,'tyt.semaforo.naranja_min',1);

tyt_header('T&T · Semáforo');
tyt_nav();
?>
<div class="container">
  <h1 class="h4 mb-3">Semáforo (config)</h1>
  <form method="post" class="row g-3 col-lg-6">
    <div class="col-12">
      <label class="form-label">SLA total (días)</label>
      <input type="number" min="1" class="form-control" name="sla_dias" value="<?= $sla ?>">
      <small class="text-muted">Vence cuando los días transcurridos alcanzan el SLA.</small>
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label">Verde si faltan ≥</label>
      <input type="number" class="form-control" name="verde_min" value="<?= $vmin ?>">
    </div>
    <div class="col-12 col-md-6">
      <label class="form-label">Naranja si faltan ≥</label>
      <input type="number" class="form-control" name="naranja_min" value="<?= $nmin ?>">
      <small class="text-muted">Rojo si faltan &lt; este valor.</small>
    </div>
    <div class="col-12">
      <button class="btn btn-primary">Guardar</button>
    </div>
  </form>
</div>
<?php tyt_footer(); ?>
