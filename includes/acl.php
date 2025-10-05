<?php
// includes/acl.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

if (!function_exists('acl_pdo')) {
  function acl_pdo(): PDO { return get_pdo(); }
}

if (!function_exists('acl_uid')) {
  function acl_uid(): int { return (int)($_SESSION['usuario_id'] ?? 0); }
}

if (!function_exists('acl_is_admin_global')) {
  function acl_is_admin_global(): bool {
    $rol = (string)($_SESSION['rol'] ?? 'lectura');
    return $rol === 'admin';
  }
}

if (!function_exists('has_perm')) {
  /**
   * has_perm('auditoria.hallazgo.crear')
   * - modulo.clavePermiso (prefijo antes del primer punto = módulo)
   */
  function has_perm(string $permClaveCompleta): bool {
    $uid = acl_uid();
    if ($uid <= 0) return false;
    if (acl_is_admin_global()) return true;

    $dot = strpos($permClaveCompleta, '.');
    if ($dot === false) return false;
    $modClave = substr($permClaveCompleta, 0, $dot);
    $permClave = substr($permClaveCompleta, $dot + 1);

    static $CACHE = [];
    $cacheKey = $uid . '|' . $permClaveCompleta;
    if (array_key_exists($cacheKey, $CACHE)) return $CACHE[$cacheKey];

    $pdo = acl_pdo();

    // 1) acceso al módulo
    $sqlAccesoModulo = "
      SELECT 1
      FROM usuario_modulo um
      JOIN modulo m ON m.id = um.modulo_id
      WHERE um.usuario_id = ? AND um.activo = 1 AND m.clave = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sqlAccesoModulo);
    $st->execute([$uid, $modClave]);
    if (!$st->fetchColumn()) { $CACHE[$cacheKey] = false; return false; }

    // 2) rol del usuario para ese módulo
    $sqlRolModulo = "
      SELECT r.id
      FROM usuario_rol_modulo urm
      JOIN modulo m ON m.id = urm.modulo_id
      JOIN rol r    ON r.id = urm.rol_id
      WHERE urm.usuario_id = ? AND m.clave = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sqlRolModulo);
    $st->execute([$uid, $modClave]);
    $rolId = (int)($st->fetchColumn() ?: 0);
    if ($rolId <= 0) { $CACHE[$cacheKey] = false; return false; }

    // 3) rol -> permiso
    $sqlPerm = "
      SELECT 1
      FROM permiso p
      JOIN modulo m ON m.id = p.modulo_id
      JOIN rol_permiso rp ON rp.permiso_id = p.id AND rp.rol_id = ?
      WHERE m.clave = ? AND p.clave = ?
      LIMIT 1
    ";
    $st = $pdo->prepare($sqlPerm);
    $st->execute([$rolId, $modClave, $permClave]);
    $ok = (bool)$st->fetchColumn();

    $CACHE[$cacheKey] = $ok;
    return $ok;
  }
}

if (!function_exists('require_perm')) {
  function require_perm(string $permClaveCompleta): void {
    if (has_perm($permClaveCompleta)) return;
    if (function_exists('set_flash')) {
      set_flash('warning', 'No tienes permiso para esta acción.');
    }
    header('Location: ' . rtrim(BASE_URL, '/') . '/dashboard.php');
    exit;
  }
}
