<?php
declare(strict_types=1);

// Requisitos para esta página:
$REQUIRED_MODULE = 'auditoria';
$REQUIRED_PERMS  = ['auditoria.access','auditoria.hallazgo.list'];

// Boot común
require_once __DIR__ . '/../../includes/page_boot.php';

// (desde aquí continúa tu código actual de listado… ya tienes $pdo, $uid, $rol)

$pdo = getDB();

function money_to_float(?string $s): float {
  if ($s === null) return 0.0;
  $s = trim($s);
  if ($s === '') return 0.0;
  $s = str_replace(['$', ' '], '', $s);
  if (preg_match('/^\d{1,3}(\.\d{3})+\,\d{1,2}$/', $s)) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace(',', '', $s);
  }
  return (float)$s;
}

$id          = (int)($_POST['id'] ?? 0);
$fecha       = $_POST['fecha'] ?? '';
$zona_id     = (int)($_POST['zona_id'] ?? 0);
$centro_id   = (int)($_POST['centro_id'] ?? 0);
$pdv_codigo  = trim($_POST['pdv_codigo'] ?? '');
$nombre_pdv  = trim($_POST['nombre_pdv'] ?? '');
$cedula      = trim($_POST['cedula'] ?? '');
$asesor_nom  = trim($_POST['asesor_nombre'] ?? '');
$raspas      = (int)($_POST['raspas_faltantes'] ?? 0);
$faltante    = money_to_float($_POST['faltante_dinero'] ?? null);
$sobrante    = money_to_float($_POST['sobrante_dinero'] ?? null);
$obs         = trim($_POST['observaciones'] ?? '');
$ahora       = (new DateTime('now'))->format('Y-m-d H:i:s');

if ($id<=0) { http_response_code(400); exit('ID inválido'); }

$errors = [];
if (!$fecha)               $errors[] = 'Fecha es obligatoria';
if ($zona_id<=0)           $errors[] = 'Zona es obligatoria';
if ($centro_id<=0)         $errors[] = 'Centro es obligatorio';
if ($pdv_codigo==='')      $errors[] = 'Código PDV es obligatorio';
if ($nombre_pdv==='')      $errors[] = 'Nombre PDV es obligatorio';
if ($cedula==='')          $errors[] = 'Cédula es obligatoria';
if ($obs==='')             $errors[] = 'Observaciones son obligatorias';
if ($fecha) {
  $hoy = (new DateTime('now'))->format('Y-m-d');
  if ($fecha > $hoy) $errors[] = 'La fecha no puede ser mayor a hoy.';
}
if ($errors) { http_response_code(400); echo implode('. ', $errors); exit; }

// Trae el hallazgo existente
$st = $pdo->prepare("SELECT * FROM hallazgo WHERE id=? LIMIT 1");
$st->execute([$id]);
$prev = $st->fetch(PDO::FETCH_ASSOC);
if (!$prev) { http_response_code(404); exit('Hallazgo no existe'); }

// Para recálculo de SLA y responsables si cambian fecha/zona/centro
$fecha_cambio  = substr($prev['fecha'],0,10) !== $fecha;
$zona_cambio   = ((int)$prev['zona_id'] !== $zona_id);
$centro_cambio = ((int)$prev['centro_id'] !== $centro_id);

$pdo->beginTransaction();
try {
  // UPSERT asesor
  $st = $pdo->prepare("SELECT id FROM asesor WHERE cedula=? LIMIT 1");
  $st->execute([$cedula]);
  $asesor_id = $st->fetchColumn();
  if (!$asesor_id) {
    $st = $pdo->prepare("INSERT INTO asesor (cedula, nombre, activo) VALUES (?,?,1)");
    $st->execute([$cedula, ($asesor_nom ?: '')]);
    $asesor_id = (int)$pdo->lastInsertId();
  } else if ($asesor_nom) {
    $st = $pdo->prepare("UPDATE asesor SET nombre=? WHERE id=?");
    $st->execute([$asesor_nom, $asesor_id]);
  }

  // UPSERT PDV (por código+centro)
  $st = $pdo->prepare("SELECT id FROM pdv WHERE codigo=? AND centro_id=? LIMIT 1");
  $st->execute([$pdv_codigo, $centro_id]);
  $pdv_id = $st->fetchColumn();
  if (!$pdv_id) {
    $st = $pdo->prepare("INSERT INTO pdv (codigo, nombre, centro_id, activo) VALUES (?,?,?,1)");
    $st->execute([$pdv_codigo, $nombre_pdv, $centro_id]);
    $pdv_id = (int)$pdo->lastInsertId();
  } else {
    $st = $pdo->prepare("UPDATE pdv SET nombre=? WHERE id=?");
    $st->execute([$nombre_pdv, $pdv_id]);
  }

  // Recalcular responsables y SLA si corresponde (fecha/zona/centro cambiaron)
  $lider_id = $prev['lider_id'];
  $sup_id   = $prev['supervisor_id'];
  $aux_id   = $prev['auxiliar_id'];
  $fecha_limite = $prev['fecha_limite'];

  if ($fecha_cambio || $zona_cambio || $centro_cambio) {
    $fechaDT = new DateTime($fecha . ' 00:00:00');
    $fISO    = $fechaDT->format('Y-m-d');

    // responsables por vigencia
    $st = $pdo->prepare("SELECT usuario_id FROM lider_centro WHERE centro_id=? AND ? BETWEEN DATE(desde) AND COALESCE(DATE(hasta), ?) ORDER BY desde DESC LIMIT 1");
    $st->execute([$centro_id, $fISO, $fISO]);
    $lider_id = $st->fetchColumn() ?: null;

    $st = $pdo->prepare("SELECT usuario_id FROM supervisor_zona WHERE zona_id=? AND ? BETWEEN DATE(desde) AND COALESCE(DATE(hasta), ?) ORDER BY desde DESC LIMIT 1");
    $st->execute([$zona_id, $fISO, $fISO]);
    $sup_id = $st->fetchColumn() ?: null;

    $st = $pdo->prepare("SELECT usuario_id FROM auxiliar_centro WHERE centro_id=? AND ? BETWEEN DATE(desde) AND COALESCE(DATE(hasta), ?) ORDER BY desde DESC LIMIT 1");
    $st->execute([$centro_id, $fISO, $fISO]);
    $aux_id = $st->fetchColumn() ?: null;

    // SLA: +2 días fin de día
    $fecha_limite = (clone $fechaDT)->modify('+2 days')->setTime(23,59,59)->format('Y-m-d H:i:s');
  }

  // UPDATE principal
  $sql = "UPDATE hallazgo
          SET fecha=?, zona_id=?, centro_id=?, nombre_pdv=?, pdv_codigo=?, cedula=?,
              raspas_faltantes=?, faltante_dinero=?, sobrante_dinero=?,
              observaciones=?, fecha_limite=?, lider_id=?, supervisor_id=?, auxiliar_id=?,
              actualizado_en=?
          WHERE id=?";
  $st = $pdo->prepare($sql);
  $ok = $st->execute([
    $fecha, $zona_id, $centro_id, $nombre_pdv, $pdv_codigo, $cedula,
    $raspas, $faltante, $sobrante,
    $obs, $fecha_limite, $lider_id, $sup_id, $aux_id,
    $ahora, $id
  ]);
  if (!$ok) { $info=$st->errorInfo(); throw new RuntimeException('UPDATE hallazgo falló: '.implode(' | ', $info)); }

  // Evidencia (reemplazo opcional)
  if (!empty($_FILES['evidencia']['name']) && $_FILES['evidencia']['error'] === UPLOAD_ERR_OK) {
    $origName = $_FILES['evidencia']['name'];
    $tmpPath  = $_FILES['evidencia']['tmp_name'];
    $ext = pathinfo($origName, PATHINFO_EXTENSION);
    $safeBase = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $fileName = $safeBase . '_' . $id . '.' . $ext;

    $destDir = __DIR__ . '/../uploads/hallazgos/' . $id;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    $destPath = $destDir . '/' . $fileName;
    if (move_uploaded_file($tmpPath, $destPath)) {
      $evidUrl = BASE_URL . '/uploads/hallazgos/' . $id . '/' . $fileName;
      $st = $pdo->prepare("UPDATE hallazgo SET evidencia_url=?, actualizado_en=? WHERE id=?");
      $st->execute([$evidUrl, $ahora, $id]);
    }
  }

  $pdo->commit();
  header('Location: ' . BASE_URL . '/hallazgos/detalle.php?id=' . $id);
  exit;

} catch (Throwable $e) {
  $pdo->rollBack();
  http_response_code(500);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "ERROR guardando edición:\n".$e->getMessage();
  exit;
}
