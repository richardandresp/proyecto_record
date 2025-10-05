<?php
// public/admin/permisos.php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/session_boot.php';
require_once __DIR__ . '/../../includes/env.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/auth.php';

login_required();
require_roles(['admin']); // solo admin

$pdo = function_exists('get_pdo') ? get_pdo() : getDB();

/* ===== Datos base ===== */

// Módulos activos (catálogo para switches)
$modulos = $pdo->query("
  SELECT id, nombre, clave
  FROM modulo
  WHERE activo=1
  ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Usuarios con módulos actuales (texto para la columna)
$sqlUsuarios = "
  SELECT
    u.id,
    u.nombre,
    u.email,
    u.rol AS rol_global,
    COALESCE(GROUP_CONCAT(DISTINCT m.nombre ORDER BY m.nombre SEPARATOR ', '), '') AS modulos
  FROM usuario u
  LEFT JOIN usuario_modulo um
    ON um.usuario_id = u.id AND um.activo = 1
  LEFT JOIN modulo m
    ON m.id = um.modulo_id AND m.activo = 1
  GROUP BY u.id, u.nombre, u.email, u.rol
  ORDER BY u.nombre ASC
";
$usuarios = $pdo->query($sqlUsuarios)->fetchAll(PDO::FETCH_ASSOC);

// Mapa usuario→{modulo_id=>activo}
$umRows = $pdo->query("
  SELECT usuario_id, modulo_id, activo
  FROM usuario_modulo
")->fetchAll(PDO::FETCH_ASSOC);

$umap = []; // [userId][modId] => 1/0
foreach ($umRows as $r) {
  $u = (int)$r['usuario_id'];
  $m = (int)$r['modulo_id'];
  $umap[$u][$m] = (int)$r['activo'];
}

/* ===== Roles → Permisos por módulo (solo lectura por ahora) ===== */
$sqlRoles = "
  SELECT
    r.id,
    r.clave AS rol_clave,
    r.nombre AS rol_nombre,
    mo.id   AS modulo_id,
    mo.nombre AS modulo_nombre,
    COALESCE(GROUP_CONCAT(DISTINCT p.clave ORDER BY p.clave SEPARATOR ', '), '') AS permisos
  FROM rol r
  LEFT JOIN rol_permiso rp ON rp.rol_id = r.id
  LEFT JOIN permiso p      ON p.id = rp.permiso_id
  LEFT JOIN modulo  mo     ON mo.id = p.modulo_id
  GROUP BY r.id, rol_clave, rol_nombre, mo.id, modulo_nombre
  ORDER BY r.nombre ASC, mo.nombre ASC
";
$roles = $pdo->query($sqlRoles)->fetchAll(PDO::FETCH_ASSOC);

$rolesView = [];
foreach ($roles as $row) {
  $rid = (int)$row['id'];
  if (!isset($rolesView[$rid])) {
    $rolesView[$rid] = [
      'rol_clave'   => $row['rol_clave'],
      'rol_nombre'  => $row['rol_nombre'],
      'modulos'     => [],
    ];
  }
  $rolesView[$rid]['modulos'][] = [
    'modulo_id'     => $row['modulo_id'],
    'modulo_nombre' => $row['modulo_nombre'] ?: '(sin módulo)',
    'permisos'      => $row['permisos'],
  ];
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Permisos y Accesos</h3>
    <span class="text-muted small">Edición: activar/desactivar módulos por usuario</span>
  </div>

  <ul class="nav nav-tabs" id="permTabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-usuarios" data-bs-toggle="tab" data-bs-target="#pane-usuarios" type="button" role="tab">Usuarios</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-roles" data-bs-toggle="tab" data-bs-target="#pane-roles" type="button" role="tab">Roles</button>
    </li>
  </ul>

  <div class="tab-content border border-top-0 p-3 rounded-bottom shadow-sm">
    <!-- ===== USUARIOS (con switches) ===== -->
    <div class="tab-pane fade show active" id="pane-usuarios" role="tabpanel" aria-labelledby="tab-usuarios">
      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead class="table-dark">
            <tr>
              <th style="width:60px;">ID</th>
              <th>Nombre</th>
              <th>Email</th>
              <th>Rol global</th>
              <th>Módulos habilitados</th>
              <th style="width:120px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$usuarios): ?>
              <tr><td colspan="6" class="text-center text-muted">No hay usuarios.</td></tr>
            <?php else: foreach ($usuarios as $u): 
              $uid = (int)$u['id'];
              $modsTxt = $u['modulos'] ?: '—';
            ?>
              <tr id="row-u-<?= $uid ?>">
                <td><?= $uid ?></td>
                <td><?= htmlspecialchars($u['nombre']) ?></td>
                <td><a href="mailto:<?= htmlspecialchars($u['email']) ?>"><?= htmlspecialchars($u['email']) ?></a></td>
                <td><span class="badge bg-secondary"><?= htmlspecialchars($u['rol_global']) ?></span></td>
                <td class="mods-label"><?= htmlspecialchars($modsTxt) ?></td>
                <td class="text-end">
                  <button class="btn btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#mods-U<?= $uid ?>">
                    Gestionar
                  </button>
                </td>
              </tr>
              <tr class="collapse" id="mods-U<?= $uid ?>">
                <td colspan="6">
                  <div class="row g-2">
                    <?php foreach ($modulos as $m):
                      $mid = (int)$m['id'];
                      $checked = !empty($umap[$uid][$mid]);
                    ?>
                      <div class="col-md-4">
                        <div class="form-check form-switch">
                          <input class="form-check-input mod-switch" type="checkbox"
                                 data-user="<?= $uid ?>" data-mod="<?= $mid ?>"
                                 id="sw-u<?= $uid ?>-m<?= $mid ?>" <?= $checked ? 'checked' : '' ?>>
                          <label class="form-check-label" for="sw-u<?= $uid ?>-m<?= $mid ?>">
                            <?= htmlspecialchars($m['nombre']) ?>
                            <small class="text-muted"> (<?= htmlspecialchars($m['clave']) ?>)</small>
                          </label>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                  <small class="text-muted">Los cambios se guardan al mover cada switch.</small>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ===== ROLES (solo lectura por ahora) ===== -->
    <div class="tab-pane fade" id="pane-roles" role="tabpanel" aria-labelledby="tab-roles">
      <?php if (!$rolesView): ?>
        <div class="text-muted">No hay roles.</div>
      <?php else: ?>
        <?php foreach ($rolesView as $rid => $r): ?>
          <div class="card mb-3 shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div>
                <strong><?= htmlspecialchars($r['rol_nombre']) ?></strong>
                <span class="badge bg-secondary ms-2"><?= htmlspecialchars($r['rol_clave']) ?></span>
              </div>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                  <thead class="table-light">
                    <tr>
                      <th style="width:220px;">Módulo</th>
                      <th>Permisos (clave)</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $mods = $r['modulos'] ?: [];
                      if (!$mods) {
                        echo '<tr><td colspan="2" class="text-muted text-center">Sin permisos asignados.</td></tr>';
                      } else {
                        foreach ($mods as $m) {
                          echo '<tr>';
                          echo '<td>'.htmlspecialchars($m['modulo_nombre']).'</td>';
                          echo '<td>'.htmlspecialchars($m['permisos'] ?: '—').'</td>';
                          echo '</tr>';
                        }
                      }
                    ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <small class="text-muted">Fuente: <code>rol_permiso</code> ↔ <code>permiso</code> (por módulo).</small>
    </div>
  </div>
</div>

<script>
(() => {
  const BASE = '<?= rtrim(BASE_URL,"/") ?>';

  // Al mover un switch, guardar y actualizar el texto "Módulos habilitados"
  document.querySelectorAll('.mod-switch').forEach(sw => {
    sw.addEventListener('change', async (ev) => {
      const el = ev.currentTarget;
      const uid = el.getAttribute('data-user');
      const mid = el.getAttribute('data-mod');
      const enable = el.checked ? 1 : 0;

      try{
        const body = new URLSearchParams({ user_id: uid, modulo_id: mid, enable });
        const r = await fetch(`${BASE}/admin/api/user_module_toggle.php`, {
          method:'POST',
          credentials: 'same-origin',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body
        });
        const j = await r.json();
        if (!r.ok || !j || !j.ok) throw 0;

        // Recalcular la etiqueta de módulos habilitados (miramos switches checked del mismo usuario)
        const row = document.querySelector(`#row-u-${uid}`);
        if (row){
          const label = row.querySelector('.mods-label');
          const checkedLabels = Array.from(document.querySelectorAll(`#mods-U${uid} .mod-switch:checked`))
            .map(ci => {
              const lab = row.nextElementSibling.querySelector(`label[for="${ci.id}"]`);
              return lab ? lab.firstChild.textContent.trim() : '';
            })
            .filter(Boolean)
            .sort((a,b)=> a.localeCompare(b));
          label.textContent = checkedLabels.length ? checkedLabels.join(', ') : '—';
        }
      }catch(e){
        // revertir visual si falló
        el.checked = !el.checked;
        alert('No se pudo guardar el cambio.');
      }
    });
  });
})();
</script>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
