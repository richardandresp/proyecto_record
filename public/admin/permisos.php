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

// Módulos activos (catálogo para switches en pestaña Usuarios)
$modulos = $pdo->query("
  SELECT id, nombre, clave
  FROM modulo
  WHERE activo=1
  ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Usuarios con texto de módulos actuales
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

/* ===== Roles y permisos por módulo (mostrar TODOS los módulos) ===== */

// Todos los roles
$rolesAll = $pdo->query("
  SELECT id, clave AS rol_clave, nombre AS rol_nombre
  FROM rol
  ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Todos los módulos activos (catálogo a listar SIEMPRE)
$modsAll = $pdo->query("
  SELECT id, nombre
  FROM modulo
  WHERE activo = 1
  ORDER BY nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Mapa permisos existentes por (rol_id, modulo_id) -> "clave1, clave2, ..."
$permRows = $pdo->query("
  SELECT
    r.id              AS rol_id,
    p.modulo_id       AS modulo_id,
    GROUP_CONCAT(DISTINCT p.clave ORDER BY p.clave SEPARATOR ', ') AS perms
  FROM rol r
  LEFT JOIN rol_permiso rp ON rp.rol_id = r.id
  LEFT JOIN permiso     p  ON p.id = rp.permiso_id
  GROUP BY r.id, p.modulo_id
")->fetchAll(PDO::FETCH_ASSOC);

$permByRoleModule = []; // [rol_id][modulo_id] => "perms string"
foreach ($permRows as $pr) {
  if (!empty($pr['modulo_id'])) {
    $permByRoleModule[(int)$pr['rol_id']][(int)$pr['modulo_id']] = (string)$pr['perms'];
  }
}

// Estructura para pintar Roles
$rolesView = []; // [rol_id] => { rol_clave, rol_nombre, modulos: [ {modulo_id, modulo_nombre, permisos}... ] }
foreach ($rolesAll as $r) {
  $rid = (int)$r['id'];
  $rolesView[$rid] = [
    'rol_clave'  => $r['rol_clave'],
    'rol_nombre' => $r['rol_nombre'],
    'modulos'    => []
  ];
  foreach ($modsAll as $m) {
    $mid = (int)$m['id'];
    $rolesView[$rid]['modulos'][] = [
      'modulo_id'     => $mid,
      'modulo_nombre' => $m['nombre'],
      'permisos'      => $permByRoleModule[$rid][$mid] ?? ''
    ];
  }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="container py-3">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="mb-0">Permisos y Accesos</h3>
    <span class="text-muted small">Edición: activar/desactivar módulos por usuario y gestionar permisos por rol/módulo</span>
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
              // 🔧 FIX del warning: si no viene la clave, usamos '—'
              $modsTxt = ($u['modulos'] ?? '') !== '' ? $u['modulos'] : '—';
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

    <!-- ===== ROLES (gestión por módulo) ===== -->
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
                      <th style="width:120px;"></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $mods = $r['modulos'] ?: [];
                      if (!$mods) {
                        echo '<tr><td colspan="3" class="text-muted text-center">Sin módulos activos.</td></tr>';
                      } else {
                        foreach ($mods as $m) {
                          echo '<tr>';
                          echo '<td>'.htmlspecialchars($m['modulo_nombre']).'</td>';
                          echo '<td>'.htmlspecialchars($m['permisos'] ?: '—').'</td>';
                          echo '<td class="text-end">';
                          echo '<button class="btn btn-outline-primary btn-sm btn-manage-perms" ';
                          echo 'data-rol-id="'.(int)$rid.'" ';
                          echo 'data-rol-name="'.htmlspecialchars($r['rol_nombre']).'" ';
                          echo 'data-mod-id="'.(int)$m['modulo_id'].'" ';
                          echo 'data-mod-name="'.htmlspecialchars($m['modulo_nombre']).'">Gestionar</button>';
                          echo '</td>';
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

<!-- Modal Gestionar permisos (Rol + Módulo) -->
<div class="modal fade" id="modalRolePerms" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">
          Permisos —
          <span id="mrp-rol"></span>
          <small class="text-muted">/</small>
          <span id="mrp-mod"></span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div id="mrp-alert" class="alert alert-danger d-none"></div>
        <div id="mrp-list" class="row g-2">
          <div class="text-muted">Cargando permisos…</div>
        </div>
      </div>
      <div class="modal-footer">
        <input type="hidden" id="mrp-rol-id" value="">
        <input type="hidden" id="mrp-mod-id" value="">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="mrp-save">Guardar</button>
      </div>
    </div>
  </div>
</div>

<script>
(() => {
  const BASE = '<?= rtrim(BASE_URL,"/") ?>';

  // === Switches de módulos por usuario (guardado inmediato)
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

        // Actualizar etiqueta de módulos habilitados
        const row = document.querySelector(`#row-u-${uid}`);
        if (row){
          const label = row.querySelector('.mods-label');
          const checks = document.querySelectorAll(`#mods-U${uid} .mod-switch`);
          const names = [];
          checks.forEach(ci => {
            if (ci.checked) {
              const lab = row.nextElementSibling.querySelector(`label[for="${ci.id}"]`);
              if (lab) names.push(lab.firstChild.textContent.trim());
            }
          });
          names.sort((a,b)=> a.localeCompare(b));
          label.textContent = names.length ? names.join(', ') : '—';
        }
      }catch(e){
        el.checked = !el.checked;
        alert('No se pudo guardar el cambio.');
      }
    });
  });

  // === Modal Permisos por Rol/Módulo ===
  const modalEl = document.getElementById('modalRolePerms');
  const modal = new bootstrap.Modal(modalEl);
  const mrpRol = document.getElementById('mrp-rol');
  const mrpMod = document.getElementById('mrp-mod');
  const mrpRolId = document.getElementById('mrp-rol-id');
  const mrpModId = document.getElementById('mrp-mod-id');
  const mrpList = document.getElementById('mrp-list');
  const mrpAlert = document.getElementById('mrp-alert');
  const mrpSave = document.getElementById('mrp-save');

  function showErr(msg){
    mrpAlert.textContent = msg || 'Error';
    mrpAlert.classList.remove('d-none');
  }
  function hideErr(){ mrpAlert.classList.add('d-none'); }

  // Abrir modal
  document.querySelectorAll('.btn-manage-perms').forEach(btn=>{
    btn.addEventListener('click', async ()=>{
      hideErr();
      mrpRol.textContent = btn.getAttribute('data-rol-name') || '';
      mrpMod.textContent = btn.getAttribute('data-mod-name') || '';
      mrpRolId.value = btn.getAttribute('data-rol-id') || '';
      mrpModId.value = btn.getAttribute('data-mod-id') || '';
      mrpList.innerHTML = '<div class="text-muted">Cargando permisos…</div>';
      modal.show();

      try{
        const qs = new URLSearchParams({ rol_id: mrpRolId.value, modulo_id: mrpModId.value });
        const r = await fetch(`${BASE}/admin/api/role_perms_get.php?`+qs.toString(), { credentials:'same-origin' });
        const j = await r.json();
        if (!r.ok || !j || !j.ok) throw 0;

        const items = Array.isArray(j.items) ? j.items : [];
        if (!items.length){
          mrpList.innerHTML = '<div class="text-muted">Este módulo no tiene permisos definidos.</div>';
        } else {
          mrpList.innerHTML = items.map(it => `
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input mrp-chk" type="checkbox" id="perm-${it.id}" value="${it.id}" ${it.checked ? 'checked':''}>
                <label class="form-check-label" for="perm-${it.id}">
                  <b>${it.clave}</b> <small class="text-muted">— ${it.nombre||''}</small>
                </label>
              </div>
            </div>
          `).join('');
        }
      }catch(e){
        mrpList.innerHTML = '';
        showErr('No se pudieron cargar los permisos.');
      }
    });
  });

  // Guardar
  mrpSave.addEventListener('click', async ()=>{
    hideErr();
    const rol_id = mrpRolId.value;
    const modulo_id = mrpModId.value;
    const checks = Array.from(mrpList.querySelectorAll('.mrp-chk:checked')).map(c => c.value);

    try{
      const body = new URLSearchParams();
      body.set('rol_id', rol_id);
      body.set('modulo_id', modulo_id);
      checks.forEach(v => body.append('perms[]', v));

      const r = await fetch(`${BASE}/admin/api/role_perms_save.php`, {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body
      });
      const j = await r.json();
      if (!r.ok || !j || !j.ok) throw 0;

      // Refrescar la fila de ese rol/módulo en la tabla (texto permisos)
      // Simple: recargar la página para mantener consistencia
      location.reload();

    }catch(e){
      showErr('No se pudo guardar. Intenta de nuevo.');
    }
  });
})();
</script>
<?php
$__footer = __DIR__ . '/../../includes/footer.php';
if (is_file($__footer)) include $__footer;
