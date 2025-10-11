<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";
if (!tyt_can('tyt.cv.export')) { http_response_code(403); exit('Acceso denegado'); }
function hx($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
tyt_header('T&T · Exportar');
tyt_nav();
$q = $_GET['q']??''; $estado=$_GET['estado']??''; $perfil=$_GET['perfil']??''; $desde=$_GET['desde']??''; $hasta=$_GET['hasta']??'';
$venc=$_GET['venc']??'';
?>
<div class="container py-3">
  <h1 class="h4 mb-3">Exportar (CSV)</h1>
  <form class="row g-2 mb-3" method="get" action="<?= tyt_url('reportes/export.php') ?>">
    <input type="hidden" name="venc" value="<?= hx($venc) ?>">
    <div class="col-12 col-md-3"><input class="form-control" name="q" value="<?= hx($q) ?>" placeholder="Nombre o documento"></div>
    <div class="col-6 col-md-2">
      <select class="form-select" name="estado">
        <?php foreach(['','recibido','revision','contacto_inicial','en_capacitacion','en_activacion','entregado_comercial','rechazado'] as $e){
          $sel=$estado===$e?'selected':''; $label=$e===''?'Estado':$e; echo "<option $sel>".hx($label)."</option>";
        } ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <select class="form-select" name="perfil">
        <?php foreach(['','TAT','EMP','ASESORA'] as $p){
          $sel=$perfil===$p?'selected':''; $label=$p===''?'Perfil':$p; echo "<option $sel>".hx($label)."</option>";
        } ?>
      </select>
    </div>
    <div class="col-6 col-md-2"><input type="date" class="form-control" name="desde" value="<?= hx($desde) ?>"></div>
    <div class="col-6 col-md-2"><input type="date" class="form-control" name="hasta" value="<?= hx($hasta) ?>"></div>
    <div class="col-12 col-md-1 d-grid">
      <button class="btn btn-primary">CSV</button>
    </div>
  </form>
  <div class="alert alert-secondary">El CSV abre perfecto en Excel.</div>
</div>
<?php tyt_footer(); ?>