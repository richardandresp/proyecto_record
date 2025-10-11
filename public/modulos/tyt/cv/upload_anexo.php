<?php
require_once __DIR__ . "/../includes/env_mod.php";
if (function_exists("user_has_perm") && !user_has_perm("tyt.cv.attach")) {
  http_response_code(403); echo "Acceso denegado (tyt.cv.attach)"; exit;
}

$persona_id = (int)($_POST['persona_id'] ?? 0);
if ($persona_id <= 0) {
  header("Location: ./listar.php?e=ID+inválido"); exit;
}

try {
  $pdo = getDB();
  $pdo->beginTransaction();

  if (!empty($_FILES['anexo']) && $_FILES['anexo']['error'] === UPLOAD_ERR_OK) {
    $file = $_FILES['anexo'];

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
    $fileName = "anx_{$persona_id}_{$stamp}_{$rand}.{$ext}";
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
      ':p' => $persona_id,
      ':n' => $origName,
      ':r' => $destRel,
      ':h' => $hash,
      ':b' => (int)$peso,
      ':u' => $_SESSION['usuario_id'] ?? 0,
    ]);
  }

  $pdo->commit();
  header("Location: ./detalle.php?id=" . $persona_id); exit;

} catch (Throwable $ex) {
  if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
  header("Location: ./detalle.php?id=" . $persona_id . "&e=" . urlencode($ex->getMessage())); exit;
}