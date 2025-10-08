<?php
// includes/acl.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/env.php';

function acl_get_user_role_ids(int $uid, PDO $pdo): array {
    // 1) roles vía tabla puente
    $st = $pdo->prepare("SELECT rol_id FROM usuario_rol WHERE usuario_id = ?");
    $st->execute([$uid]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    if ($ids) return $ids;

    // 2) fallback: columna legacy usuario.rol
    $st = $pdo->prepare("SELECT r.id
                         FROM usuario u
                         JOIN rol r ON r.clave = u.rol
                         WHERE u.id = ?
                         LIMIT 1");
    $st->execute([$uid]);
    $rid = (int)$st->fetchColumn();
    return $rid ? [$rid] : [];
}
if (!function_exists('require_module')) {
    function require_module(string $modClave, string $permClave = 'access'): void {
        $permCompleto = $modClave . '.' . $permClave;
        if (!has_perm($permCompleto)) {
            if (function_exists('set_flash')) {
                set_flash('warning', 'No tienes acceso a este módulo.');
            }
            header('Location: ' . rtrim(BASE_URL, '/') . '/dashboard.php');
            exit;
        }
    }
}

if (!function_exists('acl_pdo')) {
    function acl_pdo(): PDO { 
        return get_pdo(); 
    }
}

if (!function_exists('acl_uid')) {
    function acl_uid(): int { 
        return (int)($_SESSION['usuario_id'] ?? 0); 
    }
}

if (!function_exists('acl_is_admin_global')) {
    function acl_is_admin_global(): bool {
        $rol = (string)($_SESSION['rol'] ?? 'lectura');
        return $rol === 'admin';
    }
}

if (!function_exists('acl_is_admin_or_auditor')) {
    function acl_is_admin_or_auditor(): bool {
        $rol = (string)($_SESSION['rol'] ?? 'lectura');
        return in_array($rol, ['admin', 'auditor'], true);
    }
}

if (!function_exists('has_perm')) {
  /**
   * Chequea si el usuario tiene un permiso:
   * - Soporta permisos guardados como "auditoria.hallazgo.list" (con prefijo)
   *   o como "hallazgo.list" + modulo.clave = "auditoria".
   * - Respeta overrides de usuario (usuario_permiso).
   * - Usa rol(es) del usuario (usuario_rol o fallback a usuario.rol).
   * - Devuelve true para admin/auditor global.
   */
  function has_perm(string $perm): bool {
    static $cache = [];
    if (isset($cache[$perm])) return $cache[$perm];

    $uid = (int)($_SESSION['usuario_id'] ?? 0);
    if ($uid <= 0) return $cache[$perm] = false;

    // Bypass global para admin/auditor
    $rolGlobal = $_SESSION['rol'] ?? '';
    if (in_array($rolGlobal, ['admin','auditor'], true)) {
      return $cache[$perm] = true;
    }

    $pdo = function_exists('get_pdo') ? get_pdo() : getDB();

    // --- Normalización de clave ---
    // admisible: "auditoria.hallazgo.list" o "hallazgo.list"
    $modClave = null; $permClave = $perm;
    $pos = strpos($perm, '.');
    if ($pos !== false) {
      // Si viene "auditoria.xxx" partimos y probamos ambas formas
      $first = substr($perm, 0, $pos);
      $rest  = substr($perm, $pos + 1);
      // Si el prefijo coincide con un módulo válido, lo usamos
      $st = $pdo->prepare("SELECT id FROM modulo WHERE clave=? AND activo=1 LIMIT 1");
      $st->execute([$first]);
      if ($st->fetchColumn()) { $modClave = $first; $permClave = $rest; }
    }

    // --- ¿módulo activo para el usuario? (si hay módulo) ---
    if ($modClave) {
      $q = $pdo->prepare("
        SELECT 1
        FROM usuario_modulo um
        JOIN modulo m ON m.id = um.modulo_id AND m.clave=? AND m.activo=1
        WHERE um.usuario_id=? AND um.activo=1
        LIMIT 1
      ");
      $q->execute([$modClave, $uid]);
      if (!$q->fetchColumn()) {
        return $cache[$perm] = false; // módulo no habilitado => no hay permisos
      }
    }

    // --- roles del usuario (usuario_rol o fallback a usuario.rol) ---
    $roleIds = [];
    $rs = $pdo->prepare("SELECT rol_id FROM usuario_rol WHERE usuario_id=?");
    $rs->execute([$uid]);
    $roleIds = array_map('intval', $rs->fetchAll(PDO::FETCH_COLUMN));

    if (!$roleIds) {
      // Fallback a columna legacy usuario.rol -> rol.id
      $rg = (string)$rolGlobal;
      if ($rg !== '') {
        $rx = $pdo->prepare("SELECT id FROM rol WHERE clave=? LIMIT 1");
        $rx->execute([$rg]);
        if ($rid = (int)$rx->fetchColumn()) $roleIds = [$rid];
      }
    }

    // 1) Overrides por usuario (usuario_permiso)
    //    Soportamos:
    //    - permiso.clave = $perm (completo)
    //    - permiso.clave = $permClave + modulo.clave = $modClave
    $where = [];
    $args  = [];
    if ($modClave) {
      $where[] = "(p.clave = ? OR (p.clave = ? AND p.modulo_id = (SELECT id FROM modulo WHERE clave=? LIMIT 1)))";
      $args[]  = $perm;
      $args[]  = $permClave;
      $args[]  = $modClave;
    } else {
      // Sin módulo explícito: probamos match directo
      $where[] = "(p.clave = ?)";
      $args[]  = $perm;
    }

    $sqlU = "
      SELECT up.concedido
      FROM usuario_permiso up
      JOIN permiso p ON p.id = up.permiso_id
      WHERE up.usuario_id = ?
        AND (" . implode(' OR ', $where) . ")
      ORDER BY up.concedido DESC
      LIMIT 1
    ";
    $stU = $pdo->prepare($sqlU);
    $stU->execute(array_merge([$uid], $args));
    $rowU = $stU->fetch(PDO::FETCH_ASSOC);
    if ($rowU) {
      return $cache[$perm] = (bool)$rowU['concedido'];
    }

    // 2) Permisos por rol (rol_permiso)
    if ($roleIds) {
      $in = implode(',', array_fill(0, count($roleIds), '?'));
      if ($modClave) {
        $sqlR = "
          SELECT 1
          FROM rol_permiso rp
          JOIN permiso p ON p.id = rp.permiso_id
          WHERE rp.rol_id IN ($in)
            AND (p.clave = ?
                 OR (p.clave = ? AND p.modulo_id = (SELECT id FROM modulo WHERE clave=? LIMIT 1)))
          LIMIT 1
        ";
        $argsR = array_merge($roleIds, [$perm, $permClave, $modClave]);
      } else {
        $sqlR = "
          SELECT 1
          FROM rol_permiso rp
          JOIN permiso p ON p.id = rp.permiso_id
          WHERE rp.rol_id IN ($in) AND p.clave = ?
          LIMIT 1
        ";
        $argsR = array_merge($roleIds, [$perm]);
      }
      $stR = $pdo->prepare($sqlR);
      $stR->execute($argsR);
      if ($stR->fetchColumn()) {
        return $cache[$perm] = true;
      }
    }

    return $cache[$perm] = false;
  }
}

// Azúcar sintáctico
if (!function_exists('user_has_perm')) {
  function user_has_perm(string $perm): bool { return has_perm($perm); }
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

if (!function_exists('require_admin_global')) {
    function require_admin_global(): void {
        if (acl_is_admin_global()) return;
        
        if (function_exists('set_flash')) {
            set_flash('warning', 'Se requieren privilegios de administrador.');
        }
        
        header('Location: ' . rtrim(BASE_URL, '/') . '/dashboard.php');
        exit;
    }
}

if (!function_exists('require_admin_or_auditor')) {
    function require_admin_or_auditor(): void {
        if (acl_is_admin_or_auditor()) return;
        
        if (function_exists('set_flash')) {
            set_flash('warning', 'Se requieren privilegios de administrador o auditor.');
        }
        
        header('Location: ' . rtrim(BASE_URL, '/') . '/dashboard.php');
        exit;
    }
}