<?php
require_once __DIR__ . "/../includes/env_mod.php";
require_once __DIR__ . "/../includes/ui.php";

/* === Helpers === */
function hx(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function semaforo_class(int $dias, int $sla, int $vmin, int $nmin): string {
  if ($sla - $dias >= $vmin) return 'bg-success';
  if ($sla - $dias >= $nmin) return 'bg-warning';
  return 'bg-danger';
}
function badge_estado(string $estado): string {
  $map = [
    'recibido'              => 'secondary',
    'revision'              => 'info',
    'contacto_inicial'      => 'primary',
    'en_capacitacion'       => 'warning',
    'en_activacion'         => 'success',
    'entregado_comercial'   => 'dark',
    'rechazado'             => 'danger',
  ];
  $cls = $map[$estado] ?? 'secondary';
  return '<span class="badge text-bg-' . $cls . '">' . hx($estado) . '</span>';
}

/* === Parámetros de filtro === */
$q      = trim($_GET['q']      ?? '');
$estado = trim($_GET['estado'] ?? '');
$perfil = trim($_GET['perfil'] ?? '');
$desde  = trim($_GET['desde']  ?? '');
$hasta  = trim($_GET['hasta']  ?? '');
$venc   = trim($_GET['venc']   ?? ''); // 'verde' | 'naranja' | 'rojo'
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

/* === Ámbito por zona/CC para no-admin === */
$scopeConds  = [];
$scopeParams = [];
if (!tyt_can('tyt.admin')) {
  if (!empty($_SESSION['zona_id'])) {
    $scopeConds[] = "zona_id = :scope_zona";
    $scopeParams[':scope_zona'] = (int)$_SESSION['zona_id'];
  }
  // Si deseas acotar por centro de costo del usuario, descomenta:
  // if (!empty($_SESSION['cc_id'])) {
  //   $scopeConds[] = "cc_id = :scope_cc";
  //   $scopeParams[':scope_cc'] = (int)$_SESSION['cc_id'];
  // }
}
$whereScope = $scopeConds ? ('WHERE ' . implode(' AND ', $scopeConds)) : '';

/* === Cargar config del semáforo === */
$pdo = getDB();
function cfg_get($pdo,$k,$def){ $s=$pdo->prepare("SELECT valor FROM config WHERE clave=:k"); $s->execute([':k'=>$k]); $v=$s->fetchColumn(); return $v!==false?(int)$v:$def; }
$sla     = cfg_get($pdo, 'tyt.sla_dias', 12);
$vmin    = cfg_get($pdo, 'tyt.semaforo.verde_min', 6);
$nmin    = cfg_get($pdo, 'tyt.semaforo.naranja_min', 1);

/* === Conteos del semáforo (SIEMPRE excluye rechazado/entregado) === */
$counts = ['verde'=>0,'naranja'=>0,'rojo'=>0];
try {
  $sqlCnt = "
    SELECT
      SUM(CASE WHEN (:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) >= :vmin
                AND estado NOT IN ('entregado_comercial','rechazado') THEN 1 ELSE 0 END) AS c_verde,
      SUM(CASE WHEN (:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) >= :nmin
                AND (:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) < :vmin
                AND estado NOT IN ('entregado_comercial','rechazado') THEN 1 ELSE 0 END) AS c_naranja,
      SUM(CASE WHEN (:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) < :nmin
                AND estado NOT IN ('entregado_comercial','rechazado') THEN 1 ELSE 0 END) AS c_rojo
    FROM tyt_cv_persona
    $whereScope
  ";
  $stc = $pdo->prepare($sqlCnt);
  foreach($scopeParams as $k=>$v){ $stc->bindValue($k,$v); }
  $stc->bindValue(':sla',$sla,PDO::PARAM_INT);
  $stc->bindValue(':vmin',$vmin,PDO::PARAM_INT);
  $stc->bindValue(':nmin',$nmin,PDO::PARAM_INT);
  $stc->execute();
  $rowCnt = $stc->fetch(PDO::FETCH_ASSOC) ?: [];
  $counts['verde']   = (int)($rowCnt['c_verde']   ?? 0);
  $counts['naranja'] = (int)($rowCnt['c_naranja'] ?? 0);
  $counts['rojo']    = (int)($rowCnt['c_rojo']    ?? 0);
} catch (Throwable $e) { /* si falla, dejamos 0s */ }

/* === Construcción dinámica del WHERE para la grilla === */
$conds  = $scopeConds;   // partimos del scope
$params = $scopeParams;

if ($q !== '') {
  $conds[]          = "(doc_numero LIKE :q OR nombre_completo LIKE :q2)";
  $params[':q']     = "%$q%";
  $params[':q2']    = "%$q%";
}
if ($estado !== '') {
  $conds[]              = "estado = :estado";
  $params[':estado']    = $estado;
}
if ($perfil !== '') {
  $conds[]              = "perfil = :perfil";
  $params[':perfil']    = $perfil;
}
if ($desde !== '') {
  $conds[]              = "DATE(fecha_estado) >= :desde";
  $params[':desde']     = $desde;
}
if ($hasta !== '') {
  $conds[]              = "DATE(fecha_estado) <= :hasta";
  $params[':hasta']     = $hasta;
}

/* === Filtro por “vencimiento” del semáforo (excluye rechazado/entregado) === */
if ($venc === 'verde') {
  $conds[] = "(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) >= :vmin_v";
  $conds[] = "estado NOT IN ('entregado_comercial','rechazado')";
  $params[':sla'] = $sla; $params[':vmin_v'] = $vmin;
} elseif ($venc === 'naranja') {
  $conds[] = "(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) >= :nmin_v";
  $conds[] = "(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) < :vmin_v";
  $conds[] = "estado NOT IN ('entregado_comercial','rechazado')";
  $params[':sla'] = $sla; $params[':nmin_v'] = $nmin; $params[':vmin_v'] = $vmin;
} elseif ($venc === 'rojo') {
  $conds[] = "(:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) < :nmin_v";
  $conds[] = "estado NOT IN ('entregado_comercial','rechazado')";
  $params[':sla'] = $sla; $params[':nmin_v'] = $nmin;
}

$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

/* === Query principal === */
$total = 0;
$rows  = [];
try {
  $sqlCount = "SELECT COUNT(*) FROM tyt_cv_persona $where";
  $stc2 = $pdo->prepare($sqlCount);
  $stc2->execute($params);
  $total = (int)$stc2->fetchColumn();

  $sql = "
    SELECT id, doc_tipo, doc_numero, nombre_completo, perfil, estado,
           zona_id, cc_id, cargo_id,
           fecha_estado,
           DATEDIFF(CURDATE(), DATE(fecha_estado)) AS dias,
           (:sla - DATEDIFF(CURDATE(), DATE(fecha_estado))) AS faltan
    FROM tyt_cv_persona
    $where
    ORDER BY fecha_estado DESC
    LIMIT :limit OFFSET :offset
  ";
  $st = $pdo->prepare($sql);
  foreach ($params as $k => $v) { $st->bindValue($k, $v); }
  $st->bindValue(':limit',  $limit, PDO::PARAM_INT);
  $st->bindValue(':offset', $offset, PDO::PARAM_INT);
  $st->bindValue(':sla',$sla,PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$pages = max(1, (int)ceil($total / $limit));

/* Utilidad para armar URLs conservando filtros */
$qbase = $_GET; unset($qbase['page']);
$makeUrl = function($more = []) use ($qbase) {
  $q = array_merge($qbase, $more);
  return '?' . http_build_query($q);
};

tyt_header('T&T · Hojas de Vida');
tyt_nav();
?>
<div class="container">
  <h1 class="h4 mb-3">T&T · Hojas de Vida</h1>

  <!-- Botonera Semáforo -->
  <div class="d-flex gap-2 mb-3">
    <a class="btn rounded-pill d-inline-flex align-items-center <?= $venc==='verde'?'btn-success':'btn-outline-success' ?>"
       href="<?= hx($makeUrl(['venc'=>'verde','page'=>1])) ?>">
      <span class="me-2" style="width:10px;height:10px;border-radius:50%;display:inline-block;background:#198754"></span>
      Verde (>=<?= $vmin ?>): <strong class="ms-1"><?= $counts['verde'] ?></strong>
    </a>
    <a class="btn rounded-pill d-inline-flex align-items-center <?= $venc==='naranja'?'btn-warning':'btn-outline-warning' ?>"
       href="<?= hx($makeUrl(['venc'=>'naranja','page'=>1])) ?>">
      <span class="me-2" style="width:10px;height:10px;border-radius:50%;display:inline-block;background:#ffc107"></span>
      Naranja (>=<?= $nmin ?> y <<?= $vmin ?>): <strong class="ms-1"><?= $counts['naranja'] ?></strong>
    </a>
    <a class="btn rounded-pill d-inline-flex align-items-center <?= $venc==='rojo'?'btn-danger':'btn-outline-danger' ?>"
       href="<?= hx($makeUrl(['venc'=>'rojo','page'=>1])) ?>">
      <span class="me-2" style="width:10px;height:10px;border-radius:50%;display:inline-block;background:#dc3545"></span>
      Rojo (<<?= $nmin ?>): <strong class="ms-1"><?= $counts['rojo'] ?></strong>
    </a>
    <?php if ($venc!==''): ?>
      <a class="btn btn-outline-secondary ms-2" href="<?= hx($makeUrl(['venc'=>null,'page'=>1])) ?>">Quitar filtro</a>
    <?php endif; ?>
  </div>

  <!-- Filtros -->
  <form class="row g-2 mb-3" method="get" action="">
    <input type="hidden" name="venc" value="<?= hx($venc) ?>">
    <div class="col-12 col-md-3">
      <input class="form-control" name="q" value="<?= hx($q) ?>" placeholder="Buscar: nombre o documento">
    </div>
    <div class="col-6 col-md-2">
      <select class="form-select" name="estado">
        <?php
          $estados = ['', 'recibido','revision','contacto_inicial','en_capacitacion','en_activacion','entregado_comercial','rechazado'];
          foreach ($estados as $e) {
            $label = $e === '' ? 'Estado' : $e;
            $sel   = ($estado === $e) ? 'selected' : '';
            echo "<option $sel>".hx($label)."</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <select class="form-select" name="perfil">
        <?php
          $perfiles = ['', 'TAT','EMP','ASESORA'];
        foreach ($perfiles as $p) {
            $label = $p === '' ? 'Perfil' : $p;
            $sel   = ($perfil === $p) ? 'selected' : '';
            echo "<option $sel>".hx($label)."</option>";
          }
        ?>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <input type="date" class="form-control" name="desde" value="<?= hx($desde) ?>" placeholder="Desde">
    </div>
    <div class="col-6 col-md-2">
      <input type="date" class="form-control" name="hasta" value="<?= hx($hasta) ?>" placeholder="Hasta">
    </div>
    <div class="col-12 col-md-1 d-grid">
      <button class="btn btn-primary">Filtrar</button>
    </div>
  </form>

  <!-- Acciones -->
  <div class="mb-3 d-flex gap-2">
    <?php if (tyt_can('tyt.cv.submit')): ?>
      <a class="btn btn-success" href="<?= tyt_url('cv/editar.php') ?>">+ Nueva HV</a>
    <?php endif; ?>
    <?php if (tyt_can('tyt.cv.export')): ?>
      <a class="btn btn-outline-secondary" href="<?= tyt_url('reportes/index.php') ?>">Exportar</a>
    <?php else: ?>
      <button class="btn btn-outline-secondary" disabled>Exportar</button>
    <?php endif; ?>
  </div>

  <?php if (isset($err)): ?>
    <div class="alert alert-danger">Error: <?= hx($err) ?></div>
  <?php endif; ?>

  <?php if ($total === 0): ?>
    <div class="alert alert-info">No hay resultados con los filtros actuales.</div>
  <?php else: ?>

    <?php $stCntAnx = $pdo->prepare("SELECT COUNT(*) FROM tyt_cv_anexo WHERE persona_id = :p"); ?>

    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>Doc</th>
            <th>Nombre</th>
            <th>Perfil</th>
            <th>Estado</th>
            <th>Fecha estado</th>
            <th class="text-center">Días</th>
            <th class="text-center">Faltan</th>
            <th class="text-center">Semáforo</th>
            <th class="text-center">Docs</th>
            <th>Zona/CC</th>
            <th>Cargo</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $dias = (int)($r['dias'] ?? 0);
          $faltan = (int)($r['faltan'] ?? 0);
          $sem  = semaforo_class($dias, $sla, $vmin, $nmin);
          $stCntAnx->execute([':p' => (int)$r['id']]);
          $docs = (int)$stCntAnx->fetchColumn();
        ?>
          <tr>
            <td><?= hx($r['doc_tipo'].' '.$r['doc_numero']) ?></td>
            <td><?= hx($r['nombre_completo']) ?></td>
            <td><span class="badge text-bg-secondary"><?= hx($r['perfil']) ?></span></td>
            <td><?= badge_estado($r['estado']) ?></td>
            <td><?= hx($r['fecha_estado']) ?></td>
            <td class="text-center"><?= $dias ?></td>
            <td class="text-center"><?= $faltan ?></td>
            <td class="text-center">
              <span class="badge <?= $sem ?> rounded-circle" style="width:12px;height:12px;display:inline-block;"></span>
              <?php if ($faltan < $nmin): ?>
                <span class="badge text-bg-danger ms-1">vencido</span>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= $docs ?></td>
            <td><?= hx((string)$r['zona_id']) ?> / <?= hx((string)$r['cc_id']) ?></td>
            <td><?= hx((string)$r['cargo_id']) ?></td>
            <td class="d-flex flex-wrap gap-1">
              <a class="btn btn-sm btn-outline-secondary" href="<?= tyt_url('cv/detalle.php') . '?id=' . (int)$r['id'] ?>">Ver</a>
              <a class="btn btn-sm btn-outline-primary"   href="<?= tyt_url('cv/editar.php') . '?id=' . (int)$r['id'] ?>">Editar</a>
              <?php if (tyt_can('tyt.cv.review')): ?>
                <form method="post" action="<?= tyt_url('cv/estado.php') ?>" class="d-inline">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="to" value="revision">
                  <button class="btn btn-sm btn-outline-info">Enviar a revisión</button>
                </form>
                <form method="post" action="<?= tyt_url('cv/estado.php') ?>" class="d-inline">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="to" value="rechazado">
                  <button class="btn btn-sm btn-outline-danger">Rechazar</button>
                </form>
                <form method="post" action="<?= tyt_url('cv/estado.php') ?>" class="d-inline">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="to" value="entregado_comercial">
                  <button class="btn btn-sm btn-outline-success">Entregar a Comercial</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Paginación -->
    <nav>
      <ul class="pagination pagination-sm">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
          <a class="page-link" href="<?= hx($makeUrl(['page'=>max(1,$page-1)])) ?>">&laquo;</a>
        </li>
        <?php for($p=1; $p<=$pages; $p++):
          if ($p<=2 || $p>$pages-2 || abs($p-$page)<=2):
        ?>
          <li class="page-item <?= $p===$page?'active':'' ?>">
            <a class="page-link" href="<?= hx($makeUrl(['page'=>$p])) ?>"><?= $p ?></a>
          </li>
        <?php elseif ($p==3 && $page>5): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php elseif ($p==$pages-2 && $page<$pages-4): ?>
          <li class="page-item disabled"><span class="page-link">…</span></li>
        <?php endif; endfor; ?>
        <li class="page-item <?= $page>=$pages?'disabled':'' ?>">
          <a class="page-link" href="<?= hx($makeUrl(['page'=>min($pages,$page+1)])) ?>">&raquo;</a>
        </li>
      </ul>
    </nav>
  <?php endif; ?>
</div>
<?php tyt_footer(); ?>