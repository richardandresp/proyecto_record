<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/env_mod.php';
$db = function_exists('getDB') ? getDB() : (function(){
  $dsn='mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
  return new PDO($dsn, DB_USER, DB_PASS ?? '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
})();

// Trae UNA persona con joins mínimos
$sql = "
SELECT p.id, p.doc_tipo, p.doc_numero, p.nombre_completo, p.perfil, p.estado,
       z.nombre AS zona_nombre, c.nombre AS cc_nombre,
       COALESCE(p.fecha_estado, p.creado_en) AS fecha_estado,
       p.creado_en
FROM tyt_cv_persona p
LEFT JOIN zona z ON z.id = p.zona_id
LEFT JOIN centro_costo c ON c.id = p.cc_id
ORDER BY p.id DESC
LIMIT 1";
$row = $db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];

$COLS = 11; // Documento, Nombre, Perfil, Estado, Zona, CC, F.registro, Días, Faltan, Sem., Acciones
?><!doctype html><meta charset="utf-8">
<style>
  table{border-collapse:collapse;width:100%} th,td{border:1px solid #ddd;padding:6px}
  .mark{background:#fff3cd}
</style>
<h2>Probe de 1 fila (<?=(int)($row['id']??0)?>)</h2>
<table id="probe">
  <thead><tr>
    <th>#1 Doc</th><th>#2 Nombre</th><th>#3 Perfil</th><th>#4 Estado</th><th>#5 Zona</th>
    <th>#6 CC</th><th>#7 F.reg</th><th>#8 Días</th><th>#9 Faltan</th><th>#10 Sem</th><th>#11 Acc.</th>
  </tr></thead>
  <tbody>
    <tr>
      <td><?=htmlspecialchars(($row['doc_tipo']??'CC').' '.($row['doc_numero']??''))?></td>
      <td><?=htmlspecialchars($row['nombre_completo']??'')?></td>
      <td><?=htmlspecialchars($row['perfil']??'')?></td>
      <td><?=htmlspecialchars($row['estado']??'')?></td>
      <td><?=htmlspecialchars($row['zona_nombre']??'-')?></td>
      <td><?=htmlspecialchars($row['cc_nombre']??'-')?></td>
      <td><?=htmlspecialchars(isset($row['creado_en'])?date('Y-m-d',strtotime($row['creado_en'])):'')?></td>
      <td class="text-center"><?=(int)( (time()-strtotime($row['fecha_estado']??$row['creado_en']??'now')) / 86400 )?></td>
      <td class="text-center"><?=0?></td>
      <td class="text-center">●</td>
      <td class="text-center">[+][det]</td>
    </tr>
    <tr><td colspan="<?=$COLS?>">Detalle (colspan fijo = <?=$COLS?>)</td></tr>
  </tbody>
</table>
<script>
  // Marca si la primera fila NO tiene 11 celdas
  const tr = document.querySelector('#probe tbody tr');
  const c = tr ? tr.querySelectorAll('td').length : 0;
  if (c !== <?=$COLS?>) tr.classList.add('mark');
  document.body.insertAdjacentHTML('beforeend',
    `<p><b>TDs contados en la 1ra fila:</b> ${c} (esperado <?=$COLS?>)</p>`);
</script>
