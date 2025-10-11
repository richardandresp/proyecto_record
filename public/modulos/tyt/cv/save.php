<?php
require_once __DIR__ . "/../includes/env_mod.php";
if (function_exists("user_has_perm") && !user_has_perm("tyt.cv.submit")) {
  http_response_code(403); echo "Acceso denegado (tyt.cv.submit)"; exit;
}

$req = fn($k) => trim($_POST[$k] ?? '');
$doc_tipo       = $req('doc_tipo') ?: 'CC';
$doc_numero     = $req('doc_numero');
$nombre         = $req('nombre_completo');
$perfil         = $req('perfil'); // TAT/EMP/ASESORA
$zona_id        = (int)($req('zona_id') ?: 0);
$cc_id          = (int)($req('cc_id') ?: 0);
$cargo_id       = (int)($req('cargo_id') ?: 0);
$email          = $req('email');
$telefono       = $req('telefono');
$direccion      = $req('direccion');
$comentarios    = $req('comentarios');

if ($doc_numero === '' || $nombre === '' || $perfil === '') {
  header("Location: ./editar.php?e=Campos+obligatorios"); exit;
}

try {
  $pdo = getDB();
  $pdo->beginTransaction();

  // Si viene id, intentamos update directo
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $sql = "UPDATE tyt_cv_persona
            SET doc_tipo = :doc_tipo,
                doc_numero = :doc_numero,
                nombre_completo = :nombre,
                email = :email,
                telefono = :telefono,
                direccion = :direccion,
                zona_id = :zona,
                cc_id = :cc,
                cargo_id = :cargo,
                actualizado_en = NOW()
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':doc_tipo'   => $doc_tipo,
      ':doc_numero' => $doc_numero,
      ':nombre'     => $nombre,
      ':email'      => $email ?: null,
      ':telefono'   => $telefono ?: null,
      ':direccion'  => $direccion ?: null,
      ':zona'       => $zona_id ?: null,
      ':cc'         => $cc_id ?: null,
      ':cargo'      => $cargo_id ?: null,
      ':id'         => $id,
    ]);
    $personaId = $id;
  } else {
    // INSERT con captura de id (igual a lo que ya tenías)
    $sql = "INSERT INTO tyt_cv_persona
      (tipo, perfil, doc_tipo, doc_numero, nombre_completo, email, telefono, direccion, zona_id, cc_id, cargo_id, estado, fecha_estado, creado_por)
      VALUES
      (:tipo, :perfil, :doc_tipo, :doc_numero, :nombre, :email, :telefono, :direccion, :zona, :cc, :cargo, 'recibido', NOW(), :creado_por)
      ON DUPLICATE KEY UPDATE
        nombre_completo = VALUES(nombre_completo),
        email = VALUES(email),
        telefono = VALUES(telefono),
        direccion = VALUES(direccion),
        zona_id = VALUES(zona_id),
        cc_id = VALUES(cc_id),
        cargo_id = VALUES(cargo_id),
        actualizado_en = NOW(),
        id = LAST_INSERT_ID(id)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
      ':tipo'       => ($perfil === 'ASESORA') ? 'asesora' : 'aspirante',
      ':perfil'     => $perfil,
      ':doc_tipo'   => $doc_tipo,
      ':doc_numero' => $doc_numero,
      ':nombre'     => $nombre,
      ':email'      => $email ?: null,
      ':telefono'   => $telefono ?: null,
      ':direccion'  => $direccion ?: null,
      ':zona'       => $zona_id ?: null,
      ':cc'         => $cc_id ?: null,
      ':cargo'      => $cargo_id ?: null,
      ':creado_por' => $_SESSION['usuario_id'] ?? null,
    ]);
    $personaId = (int)$pdo->lastInsertId();
  }

  // Si vino archivo de Hoja de Vida, lo guardamos
  if (!empty($_FILES['hoja_vida']) && $_FILES['hoja_vida']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['hoja_vida'];

    // Validar tamaño (5 MB máx) y extensión
    $maxBytes = 5 * 1024 * 1024;
    if ($file['size'] > $maxBytes) { throw new RuntimeException('Archivo demasiado grande (máx 5MB)'); }

    $origName = $file['name'];
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed = ['pdf','jpg','jpeg','png'];
    if (!in_array($ext, $allowed, true)) { throw new RuntimeException('Formato no permitido'); }

    // Validar MIME real
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $okMimes = ['application/pdf','image/jpeg','image/png'];
    if (!in_array($mime, $okMimes, true)) { throw new RuntimeException('Contenido de archivo no permitido'); }

    // Asegurar carpeta
    $dir = tyt_path('uploads/hv');
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }

    // Nombre único
    $stamp = date('Ymd_His');
    $rand  = bin2hex(random_bytes(3));
    $safe  = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($origName, PATHINFO_FILENAME));
    $fileName = "hv_{$personaId}_{$stamp}_{$rand}.{$ext}";
    $destFs   = $dir . '/' . $fileName;           // ruta en disco
    $destRel  = 'uploads/hv/' . $fileName;        // ruta web relativa al módulo

    if (!move_uploaded_file($file['tmp_name'], $destFs)) {
      throw new RuntimeException('No se pudo guardar el archivo');
    }

    // Hash y peso
    $hash = hash_file('sha256', $destFs);
    $peso = filesize($destFs);

    // Registrar anexo
    $ins = $pdo->prepare("INSERT INTO tyt_cv_anexo
      (persona_id, tipo_anexo_id, nombre_archivo, ruta, hash, peso_bytes, valido, cargado_por)
      VALUES (:p, NULL, :n, :r, :h, :b, 1, :u)");
    $ins->execute([
      ':p' => $personaId,
      ':n' => $origName,
      ':r' => $destRel,
      ':h' => $hash,
      ':b' => (int)$peso,
      ':u' => $_SESSION['usuario_id'] ?? 0,
    ]);
  }

  // Si vino comentario inicial, lo dejamos en observaciones
  if ($comentarios !== '') {
    $obs = $pdo->prepare("INSERT INTO tyt_cv_observacion
      (persona_id, autor_id, estado_origen, estado_destino, comentario)
      VALUES (:p, :u, NULL, 'recibido', :c)");
    $obs->execute([
      ':p' => $personaId,
      ':u' => $_SESSION['usuario_id'] ?? 0,
      ':c' => $comentarios,
    ]);
  }

  // Guardar checklist (si envían)
  $req_chk  = $_POST['req_chk'] ?? [];     // req_chk[req_id] = "1"
  $req_resp = $_POST['req_resp'] ?? [];    // req_resp[req_id] = usuario_id

  if (is_array($req_chk) || is_array($req_resp)) {
    // Traer requisitos aplicables (por seguridad)
    $tipoPersona = ($perfil === 'ASESORA') ? 'asesora' : 'aspirante';
    $stRq = $pdo->prepare("SELECT id FROM tyt_cv_requisito WHERE activo=1 AND (aplica_a=:t OR aplica_a='ambos')");
    $stRq->execute([':t'=>$tipoPersona]);
    $validReqIds = array_map(fn($r)=>(int)$r['id'], $stRq->fetchAll(PDO::FETCH_ASSOC));

    $up = $pdo->prepare("
      INSERT INTO tyt_cv_requisito_check (persona_id, requisito_id, cumple, responsable_id, verificado_por, verificado_en)
      VALUES (:p, :r, :c, :resp, :user, NOW())
      ON DUPLICATE KEY UPDATE
        cumple=VALUES(cumple),
        responsable_id=VALUES(responsable_id),
        verificado_por=VALUES(verificado_por),
        verificado_en=VALUES(verificado_en)
    ");

    $userId = (int)($_SESSION['usuario_id'] ?? 0);
    foreach ($validReqIds as $rid) {
      $cumple = isset($req_chk[$rid]) ? 1 : 0;
      $respId = isset($req_resp[$rid]) && $req_resp[$rid]!=='' ? (int)$req_resp[$rid] : null;
      $up->execute([
        ':p'=>$personaId, ':r'=>$rid, ':c'=>$cumple,
        ':resp'=>$respId, ':user'=>$userId
      ]);
    }
  }

  $pdo->commit();
  header("Location: ./listar.php?ok=1"); exit;

} catch (Throwable $ex) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  header("Location: ./editar.php?e=" . urlencode($ex->getMessage())); exit;
}