<?php
declare(strict_types=1);

// Requisitos para esta página:
$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access','auditoria.hallazgo.list'];

// Boot común
require_once __DIR__ . '/../../includes/page_boot.php';

// (desde aquí continúa tu código actual de listado… ya tienes $pdo, $uid, $rol)

$pdo = getDB();

$id  = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('ID inválido.'); }

/* ------------- Helpers ------------- */
function ext_icon(string $url): string {
  $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
  return match($ext) {
    'jpg','jpeg','png','webp','gif' => 'bi-image',
    'pdf'                           => 'bi-filetype-pdf',
    'doc','docx'                    => 'bi-filetype-doc',
    'xls','xlsx'                    => 'bi-filetype-xls',
    'ppt','pptx'                    => 'bi-filetype-ppt',
    'zip','rar','7z'                => 'bi-file-zip',
    default                         => 'bi-file-earmark'
  };
}
function pick_url(array $row): ?string {
  foreach (['archivo_url','url','ruta_relativa','ruta','path','adjunto_url'] as $k) {
    if (!empty($row[$k])) {
      $u = (string)$row[$k];
      if (str_starts_with($u, '/uploads/')) $u = rtrim(BASE_URL,'/').$u;
      return $u;
    }
  }
  return null;
}
/* Fallback: busca también archivos en /hallazgos/{hid} que empiecen por respuesta_{rid} o adjunto_{rid}. */
function scan_fs_adjuntos(int $hid, int $rid): array {
  $base = defined('UPLOADS_PATH') ? UPLOADS_PATH : realpath(__DIR__ . '/../../public/uploads');
  if (!$base) $base = __DIR__ . '/../../public/uploads';
  $out = [];

  // a) /hallazgos/{hid}/respuestas/{rid}/
  $dirA = rtrim($base,'/\\')."/hallazgos/{$hid}/respuestas/{$rid}";
  if (is_dir($dirA)) {
    foreach (glob($dirA.'/*') as $abs) {
      if (!is_file($abs)) continue;
      $name = basename($abs);
      $out[] = [
        'ruta_relativa' => "/uploads/hallazgos/{$hid}/respuestas/{$rid}/{$name}",
        'created_at'    => date('Y-m-d H:i:s', @filemtime($abs))
      ];
    }
  }
  // b) /hallazgos/{hid}/  con patrones respuesta_{rid}* y adjunto_{rid}*
  $dirB = rtrim($base,'/\\')."/hallazgos/{$hid}";
  if (is_dir($dirB)) {
    foreach (['respuesta_'.$rid.'*', 'adjunto_'.$rid.'*'] as $pat) {
      foreach (glob($dirB.'/'.$pat) as $abs) {
        if (!is_file($abs)) continue;
        $name = basename($abs);
        $out[] = [
          'ruta_relativa' => "/uploads/hallazgos/{$hid}/{$name}",
          'created_at'    => date('Y-m-d H:i:s', @filemtime($abs))
        ];
      }
    }
  }
  return $out;
}

/* ------------- Hallazgo ------------- */
$st = $pdo->prepare("
  SELECT h.*, z.nombre AS zona, c.nombre AS centro, u.nombre AS creador
  FROM hallazgo h
  JOIN zona z         ON z.id = h.zona_id
  JOIN centro_costo c ON c.id = h.centro_id
  JOIN usuario u      ON u.id = h.creado_por
  WHERE h.id = ?
  LIMIT 1
");
$st->execute([$id]);
$h = $st->fetch(PDO::FETCH_ASSOC);
if (!$h) { http_response_code(404); exit('Hallazgo no encontrado.'); }

$evid = $h['evidencia_url'] ?? null;
if ($evid && str_starts_with((string)$evid, '/uploads/')) $evid = rtrim(BASE_URL,'/').$evid;

/* ------------- Respuestas ------------- */
$st = $pdo->prepare("
  SELECT hr.*, u.nombre AS usuario
  FROM hallazgo_respuesta hr
  JOIN usuario u ON u.id = hr.usuario_id
  WHERE hr.hallazgo_id = ?
  ORDER BY hr.id ASC
");
$st->execute([$id]);
$respuestas = $st->fetchAll(PDO::FETCH_ASSOC);

/* Cargar adjuntos tabla secundaria si existe */
$mapAdj = [];
try {
  $stA = $pdo->prepare("
    SELECT a.*
    FROM hallazgo_respuesta_adjunto a
    JOIN hallazgo_respuesta r ON r.id = a.respuesta_id
    WHERE r.hallazgo_id = ?
    ORDER BY a.id ASC
  ");
  $stA->execute([$id]);
  foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $rid = (int)($a['respuesta_id'] ?? 0);
    if ($rid > 0) $mapAdj[$rid][] = $a;
  }
} catch (Throwable $e) { /* tabla opcional */ }

include __DIR__ . '/../../includes/header.php';
?>
<style>
  .adj-card{border:1px solid #e9ecef;border-radius:.5rem;padding:.75rem;background:#fff}
  .adj-item{display:flex;gap:.75rem;align-items:center;margin:.4rem 0}
  .thumb{width:58px;height:58px;border:1px solid #e5e7eb;border-radius:.25rem;display:flex;align-items:center;justify-content:center;overflow:hidden;background:#fff}
  .thumb img{width:100%;height:100%;object-fit:cover}
  .thumb i{font-size:28px;opacity:.75}
  .adj-meta{display:flex;align-items:center;gap:1rem;flex:1}
  .adj-meta a{font-weight:500}
  .adj-meta .dt{margin-left:auto;color:#6c757d;font-size:.9rem;white-space:nowrap}
</style>

<div class="container">
  <h3>
    Hallazgo #<?= (int)$h['id'] ?>
    <?php if (!empty($h['pdv_codigo'])): ?>
      — <small class="text-muted">Cód.PDV <?= htmlspecialchars($h['pdv_codigo']) ?></small>
    <?php endif; ?>
  </h3>

  <div class="row g-2 mb-3">
    <div class="col-md-3"><b>Fecha:</b> <?= htmlspecialchars($h['fecha']) ?></div>
    <div class="col-md-3"><b>Zona:</b> <?= htmlspecialchars($h['zona']) ?></div>
    <div class="col-md-3"><b>Centro:</b> <?= htmlspecialchars($h['centro']) ?></div>
    <div class="col-md-3"><b>Estado:</b> <?= htmlspecialchars($h['estado']) ?></div>

    <div class="col-md-3"><b>PDV:</b> <?= htmlspecialchars($h['nombre_pdv']) ?></div>
    <div class="col-md-3"><b>Cédula:</b> <?= htmlspecialchars($h['cedula']) ?></div>
    <div class="col-md-3"><b>Raspas faltantes:</b> <?= number_format((int)$h['raspas_faltantes']) ?></div>
    <div class="col-md-3"><b>F. Límite:</b> <?= htmlspecialchars($h['fecha_limite']) ?></div>

    <div class="col-12"><b>Observaciones:</b> <?= nl2br(htmlspecialchars((string)$h['observaciones'])) ?></div>

    <div class="col-md-6">
      <b>Evidencia:</b>
      <?php if (!empty($h['evidencia_url'])): ?>
        <a href="<?= htmlspecialchars($evid) ?>" target="_blank" rel="noopener">Abrir evidencia</a>
      <?php else: ?>—<?php endif; ?>
    </div>
    <div class="col-md-6"><b>Creado por:</b> <?= htmlspecialchars((string)$h['creador']) ?></div>
  </div>

  <h5>Respuestas</h5>
  <?php if (!$respuestas): ?>
    <p class="text-muted">Sin respuestas aún.</p>
  <?php else: ?>
    <ul class="list-group mb-4">
      <?php foreach ($respuestas as $r): ?>
        <?php
          $rid = (int)$r['id'];
          $fechaResp = $r['creado_en'] ?? $r['respondido_en'] ?? $r['actualizado_en'] ?? '';

          // 1) Uno legado
          $items = [];
          $idx   = 1;
          if (!empty($r['adjunto_url'])) {
            $u = (string)$r['adjunto_url'];
            if (str_starts_with($u,'/uploads/')) $u = rtrim(BASE_URL,'/').$u;
            $items[] = ['url'=>$u,'dt'=>$fechaResp ?: '','name'=>'Adjunto '.$idx++];
          }
          // 2) Tabla secundaria
          foreach (($mapAdj[$rid] ?? []) as $a) {
            $u = pick_url($a); if (!$u) continue;
            $dt = $a['creado_en'] ?? $a['created_at'] ?? $fechaResp;
            $items[] = ['url'=>$u,'dt'=>$dt,'name'=>'Adjunto '.$idx++];
          }
          // 3) Fallback FS (incluye patrones en carpeta del hallazgo)
          $seen = [];
          foreach (scan_fs_adjuntos($id, $rid) as $a) {
            $u = pick_url($a); if (!$u) continue;
            if (isset($seen[$u])) continue; $seen[$u]=1;
            $items[] = ['url'=>$u,'dt'=>$a['created_at'] ?? '','name'=>'Adjunto '.$idx++];
          }

          // Dedupe por URL
          $items = array_values(array_reduce($items, function($acc,$it){
            $acc[$it['url']] = $it; return $acc;
          }, []));
          $totalAdj = count($items);
        ?>
        <li class="list-group-item">
          <div class="d-flex justify-content-between">
            <div><b><?= htmlspecialchars($r['rol_al_responder'] ?? '') ?>:</b> <?= htmlspecialchars($r['usuario'] ?? '') ?></div>
            <small class="text-muted"><?= htmlspecialchars($fechaResp) ?></small>
          </div>

          <p class="mb-2"><?= nl2br(htmlspecialchars((string)$r['respuesta'])) ?></p>

          <?php if ($totalAdj): ?>
            <div class="adj-card">
              <?php
                // Muestra el primero y botón para ver todos
                $first = $items[0];
                $isImg = ext_icon($first['url']) === 'bi-image';
              ?>
              <div class="adj-item">
                <div class="thumb">
                  <?php if ($isImg): ?>
                    <img src="<?= htmlspecialchars($first['url']) ?>" alt="">
                  <?php else: ?>
                    <i class="bi <?= ext_icon($first['url']) ?>"></i>
                  <?php endif; ?>
                </div>
                <div class="adj-meta">
                  <a href="<?= htmlspecialchars($first['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($first['name']) ?></a>
                  <div class="dt"><?= htmlspecialchars($first['dt']) ?></div>
                </div>
              </div>

              <?php if ($totalAdj > 1): ?>
                <a href="#" class="small" data-bs-toggle="modal" data-bs-target="#gal-r<?= $rid ?>">
                  Ver todos (<?= $totalAdj ?>)
                </a>
              <?php endif; ?>
            </div>

            <?php if ($totalAdj > 1): ?>
            <!-- Modal galería por respuesta -->
            <div class="modal fade" id="gal-r<?= $rid ?>" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Adjuntos de la respuesta</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                  </div>
                  <div class="modal-body">
                    <?php foreach ($items as $i => $it):
                      $icon  = ext_icon($it['url']);
                      $img   = ($icon === 'bi-image');
                    ?>
                      <div class="adj-item">
                        <div class="thumb">
                          <?php if ($img): ?>
                            <img src="<?= htmlspecialchars($it['url']) ?>" alt="">
                          <?php else: ?>
                            <i class="bi <?= $icon ?>"></i>
                          <?php endif; ?>
                        </div>
                        <div class="adj-meta">
                          <a href="<?= htmlspecialchars($it['url']) ?>" target="_blank" rel="noopener">Adjunto <?= $i+1 ?></a>
                          <div class="dt"><?= htmlspecialchars($it['dt']) ?></div>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </div>
            <?php endif; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <a href="<?= BASE_URL ?>/hallazgos/listado.php" class="btn btn-secondary">Volver al listado</a>
  <?php if (in_array($_SESSION['rol'] ?? 'lectura', ['admin','lider'], true)): ?>
    <a href="<?= BASE_URL ?>/hallazgos/responder.php?id=<?= (int)$h['id'] ?>" class="btn btn-primary">Responder</a>
  <?php endif; ?>
  <?php if (in_array($_SESSION['rol'] ?? 'lectura', ['admin','auditor'], true)): ?>
    <a href="<?= BASE_URL ?>/hallazgos/editar.php?id=<?= (int)$h['id'] ?>" class="btn btn-warning">Editar</a>
  <?php endif; ?>
</div>
