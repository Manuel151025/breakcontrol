<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirLogin();
$pdo     = getConexion();
$errores = [];
$editando = null;

// ── Guardar proveedor ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requerirPropietario();
    $id_edit   = (int)   ($_POST['id_proveedor']          ?? 0);
    $nombre    = limpiar($_POST['nombre']                  ?? '');
    $telefono  = limpiar($_POST['telefono']                ?? '');
    $entrega   = limpiar($_POST['tipo_entrega']            ?? 'domicilio');
    $dias      = (float) ($_POST['dias_entrega_promedio']  ?? 0);
    $dias_visita = $entrega === 'visita'
        ? implode(',', array_map('trim', $_POST['dias_visita'] ?? []))
        : null;

    if (empty($nombre))                              $errores[] = 'El nombre es obligatorio.';
    if ($entrega === 'visita' && empty($dias_visita)) $errores[] = 'Selecciona al menos un día de visita.';

    if (empty($errores)) {
        try {
            if ($id_edit > 0) {
                $pdo->prepare("
                    UPDATE proveedor
                    SET nombre=?, telefono=?, tipo_entrega=?, dias_entrega_promedio=?, dias_visita=?
                    WHERE id_proveedor=?
                ")->execute([$nombre, $telefono, $entrega, $dias, $dias_visita, $id_edit]);
                redirigir(APP_URL . '/modules/compras/proveedores.php', 'exito', "Proveedor <strong>".htmlspecialchars($nombre)."</strong> actualizado.");
            } else {
                $pdo->prepare("
                    INSERT INTO proveedor (nombre, telefono, tipo_entrega, dias_entrega_promedio, dias_visita)
                    VALUES (?,?,?,?,?)
                ")->execute([$nombre, $telefono, $entrega, $dias, $dias_visita]);
                redirigir(APP_URL . '/modules/compras/proveedores.php', 'exito', "Proveedor <strong>".htmlspecialchars($nombre)."</strong> creado.");
            }
        } catch (Exception $e) {
            $errores[] = 'Error al guardar el proveedor.';
        }
    }
}

// ── Desactivar proveedor ────────────────────────────────────────
if (isset($_GET['desactivar'])) {
    requerirPropietario();
    try {
        $pdo->prepare("UPDATE proveedor SET activo=0 WHERE id_proveedor=?")->execute([(int)$_GET['desactivar']]);
        redirigir(APP_URL . '/modules/compras/proveedores.php', 'alerta', 'Proveedor desactivado.');
    } catch (Exception $e) {
        redirigir(APP_URL . '/modules/compras/proveedores.php', 'error', 'Error al desactivar proveedor.');
    }
}

// ── Cargar para editar ──────────────────────────────────────────
if (isset($_GET['editar'])) {
    $stmt = $pdo->prepare("SELECT * FROM proveedor WHERE id_proveedor=?");
    $stmt->execute([(int)$_GET['editar']]);
    $editando = $stmt->fetch();
}

// ── Listado ─────────────────────────────────────────────────────
$proveedores   = $pdo->query("SELECT * FROM proveedor WHERE activo=1 ORDER BY nombre")->fetchAll();
$total_provs   = count($proveedores);
$con_visita    = count(array_filter($proveedores, fn($p) => $p['tipo_entrega'] === 'visita'));
$con_domicilio = count(array_filter($proveedores, fn($p) => $p['tipo_entrega'] === 'domicilio'));

$page_title = 'Proveedores';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  .page{margin-top:var(--nav-h);height:calc(100vh - var(--nav-h));overflow:hidden;display:grid;grid-template-rows:auto auto 1fr;gap:.7rem;padding:.75rem;}
  .wc-banner{background:linear-gradient(125deg,#6b3211 0%,#945b35 18%,#c67124 35%,#e4a565 50%,#c67124 65%,#945b35 80%,#6b3211 100%);background-size:300% 300%;animation:gradAnim 8s ease infinite;border-radius:14px;padding:.9rem 1.4rem;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow2);gap:1rem;flex-wrap:wrap;}
  .wc-left{display:flex;align-items:center;gap:.9rem;}
  .wc-greeting{font-size:.65rem;text-transform:uppercase;letter-spacing:.2em;color:rgba(255,255,255,.65);margin-bottom:.15rem;}
  .wc-name{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1.1;}
  .wc-name em{font-style:italic;color:var(--c5);}
  .wc-sub{font-size:.72rem;color:rgba(255,255,255,.62);margin-top:.15rem;}
  .wc-pills{display:flex;gap:.55rem;flex-wrap:wrap;}
  .wc-pill{background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.2);border-radius:10px;padding:.5rem .85rem;text-align:center;min-width:68px;}
  .wc-pill-num{font-family:'Fraunces',serif;font-size:1.35rem;font-weight:800;color:#fff;line-height:1;}
  .wc-pill-lbl{font-size:.54rem;text-transform:uppercase;letter-spacing:.12em;color:rgba(255,255,255,.58);}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .btn-sec{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.5rem 1rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-sec:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .g-body{display:grid;grid-template-columns:310px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.25s}.card:nth-child(2){animation-delay:.3s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge-n{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.65rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus,.fl select:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl-row{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;}
  .dias-grid{display:flex;flex-wrap:wrap;gap:.4rem;}
  .dia-chip{display:flex;align-items:center;gap:.3rem;background:var(--clight);border:1px solid var(--border);border-radius:8px;padding:.35rem .6rem;font-size:.78rem;cursor:pointer;transition:all .2s;}
  .dia-chip input[type=checkbox]{accent-color:var(--c3);width:14px;height:14px;}
  .dia-chip:has(input:checked){background:rgba(198,113,36,.1);border-color:var(--c3);color:var(--c3);font-weight:600;}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.2rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-cancel{width:100%;background:var(--clight);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.55rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;margin-top:.4rem;transition:all .2s;text-align:center;display:block;text-decoration:none;}
  .btn-cancel:hover{border-color:var(--c3);color:var(--c3);}
  .edit-banner{background:rgba(21,101,192,.08);border:1px solid rgba(21,101,192,.2);border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#1565c0;font-weight:600;margin-bottom:.7rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.6rem;}
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .tag-tipo{font-size:.58rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;}
  .tag-domicilio{background:rgba(21,101,192,.1);color:#1565c0;border:1px solid rgba(21,101,192,.2);}
  .tag-visita{background:rgba(198,113,36,.1);color:var(--c3);border:1px solid rgba(198,113,36,.2);}
  .tag-recogida{background:var(--clight);color:var(--ink3);border:1px solid var(--border);}
  .dia-badge{font-size:.6rem;font-weight:700;padding:.1rem .4rem;border-radius:20px;background:rgba(198,113,36,.1);color:var(--c3);border:1px solid rgba(198,113,36,.2);margin-right:.2rem;}
  .btn-act{width:28px;height:28px;border-radius:7px;border:1px solid;display:inline-flex;align-items:center;justify-content:center;font-size:.8rem;text-decoration:none;transition:all .2s;cursor:pointer;background:transparent;}
  .btn-edit{border-color:rgba(25,118,210,.25);color:#1565c0;}.btn-edit:hover{background:rgba(25,118,210,.1);}
  .btn-del{border-color:rgba(198,40,40,.2);color:#c62828;}.btn-del:hover{background:rgba(198,40,40,.1);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  @media(max-width:768px){.page{height:auto;overflow:visible;margin-top:60px;}.g-body{grid-template-columns:1fr;}}
</style>

<div class="page">
  <!-- BANNER -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Gestión de <em>Proveedores</em></div>
        <div class="wc-sub">Proveedores de insumos activos</div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $total_provs ?></div>
        <div class="wc-pill-lbl">Total</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $con_domicilio ?></div>
        <div class="wc-pill-lbl">Domicilio</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $con_visita ?></div>
        <div class="wc-pill-lbl">Visita</div>
      </div>
    </div>
  </div>

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-people-fill"></i> Proveedores</div>
    <a href="<?= APP_URL ?>/modules/compras/index.php" class="btn-sec">
      <i class="bi bi-arrow-left"></i> Volver a Compras
    </a>
  </div>

  <!-- CUERPO -->
  <div class="g-body">

    <!-- FORMULARIO -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-<?= $editando ? 'pencil-fill' : 'plus-lg' ?>"></i></div>
          <span class="ch-title"><?= $editando ? 'Editar proveedor' : 'Nuevo proveedor' ?></span>
        </div>
      </div>
      <div class="form-body">
        <?php if (!empty($errores)): ?>
        <div class="msg-err"><ul class="mb-0" style="padding-left:1rem;">
          <?php foreach ($errores as $e): ?><li><?= $e ?></li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>

        <?php if ($editando): ?>
        <div class="edit-banner"><i class="bi bi-pencil-square"></i> Editando: <strong><?= htmlspecialchars($editando['nombre']) ?></strong></div>
        <?php endif; ?>

        <form method="POST">
          <?php if ($editando): ?>
          <input type="hidden" name="id_proveedor" value="<?= $editando['id_proveedor'] ?>">
          <?php endif; ?>

          <div class="fl">
            <label>Nombre del proveedor</label>
            <input type="text" name="nombre" required autofocus
                   value="<?= htmlspecialchars($editando['nombre'] ?? $_POST['nombre'] ?? '') ?>"
                   placeholder="Ej: Harinera del Valle">
          </div>

          <div class="fl">
            <label>Teléfono</label>
            <input type="text" name="telefono"
                   value="<?= htmlspecialchars($editando['telefono'] ?? $_POST['telefono'] ?? '') ?>"
                   placeholder="Ej: 3001234567">
          </div>

          <div class="fl">
            <label>Tipo de entrega</label>
            <select name="tipo_entrega" id="tipo_entrega">
              <option value="domicilio" <?= (($editando['tipo_entrega'] ?? 'domicilio') === 'domicilio') ? 'selected' : '' ?>>A domicilio</option>
              <option value="recogida"  <?= (($editando['tipo_entrega'] ?? '') === 'recogida')           ? 'selected' : '' ?>>Recogida</option>
              <option value="visita"    <?= (($editando['tipo_entrega'] ?? '') === 'visita')             ? 'selected' : '' ?>>Visita programada</option>
            </select>
          </div>

          <!-- Días de entrega — solo para domicilio -->
          <div class="fl" id="campo-dias">
            <label>Tiempo de entrega promedio</label>
            <div style="display:flex;align-items:center;gap:.5rem;">
              <input type="number" name="dias_entrega_promedio" id="inp-dias"
                     value="<?= $editando['dias_entrega_promedio'] ?? 1 ?>"
                     min="0" max="30" step="0.5" style="flex:1;">
              <span style="font-size:.82rem;color:var(--ink3);">días</span>
            </div>
            <div style="font-size:.72rem;color:var(--ink3);margin-top:.25rem;">Usa <strong>0.5</strong> para entrega en horas.</div>
          </div>

          <!-- Días de visita — solo para visita programada -->
          <div class="fl" id="campo-visita" style="display:none">
            <label>Días de visita</label>
            <?php
            $diasSemana  = ['lunes','martes','miercoles','jueves','viernes','sabado'];
            $diasLabels  = ['Lun','Mar','Mié','Jue','Vie','Sáb'];
            $diasGuardados = isset($editando['dias_visita']) ? explode(',', $editando['dias_visita']) : [];
            ?>
            <div class="dias-grid">
              <?php foreach ($diasSemana as $i => $dia): ?>
              <label class="dia-chip">
                <input type="checkbox" name="dias_visita[]" value="<?= $dia ?>"
                       <?= in_array($dia, $diasGuardados) ? 'checked' : '' ?>>
                <?= $diasLabels[$i] ?>
              </label>
              <?php endforeach; ?>
            </div>
            <div style="font-size:.72rem;color:var(--ink3);margin-top:.3rem;">Días en que el proveedor pasa a tomar el pedido.</div>
          </div>

          <button type="submit" class="btn-guardar">
            <i class="bi bi-<?= $editando ? 'check-lg' : 'plus-lg' ?>"></i>
            <?= $editando ? 'Guardar cambios' : 'Crear proveedor' ?>
          </button>
          <?php if ($editando): ?>
          <a href="<?= APP_URL ?>/modules/compras/proveedores.php" class="btn-cancel">Cancelar</a>
          <?php endif; ?>
        </form>
      </div>
    </div>

    <!-- TABLA -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-table"></i></div>
          <span class="ch-title">Proveedores activos</span>
        </div>
        <span class="badge-n"><?= $total_provs ?> proveedores</span>
      </div>
      <div class="tbl-wrap">
        <?php if (empty($proveedores)): ?>
        <div class="empty">
          <i class="bi bi-people"></i>
          <strong>Sin proveedores</strong>
          <span>Crea el primer proveedor usando el formulario</span>
        </div>
        <?php else: ?>
        <table class="gt">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Teléfono</th>
              <th>Tipo</th>
              <th>Entrega / Visita</th>
              <?php if (esPropietario()): ?>
              <th></th>
              <?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php
          $labelsMap = ['lunes'=>'Lun','martes'=>'Mar','miercoles'=>'Mié','jueves'=>'Jue','viernes'=>'Vie','sabado'=>'Sáb'];
          foreach ($proveedores as $p): ?>
          <tr>
            <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
            <td><?= htmlspecialchars($p['telefono'] ?: '—') ?></td>
            <td>
              <?php if ($p['tipo_entrega'] === 'domicilio'): ?>
                <span class="tag-tipo tag-domicilio">🏠 Domicilio</span>
              <?php elseif ($p['tipo_entrega'] === 'recogida'): ?>
                <span class="tag-tipo tag-recogida">📦 Recogida</span>
              <?php else: ?>
                <span class="tag-tipo tag-visita">🗓 Visita</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($p['tipo_entrega'] === 'visita'): ?>
                <?php foreach (explode(',', $p['dias_visita'] ?? '') as $d): ?>
                  <?php if (isset($labelsMap[$d])): ?>
                  <span class="dia-badge"><?= $labelsMap[$d] ?></span>
                  <?php endif; ?>
                <?php endforeach; ?>
              <?php elseif ($p['tipo_entrega'] === 'domicilio'): ?>
                <?= $p['dias_entrega_promedio'] ?> día(s)
              <?php else: ?>
                <span style="color:var(--ink3);font-size:.8rem;">—</span>
              <?php endif; ?>
            </td>
            <?php if (esPropietario()): ?>
            <td style="white-space:nowrap;">
              <div style="display:flex;gap:.3rem;">
                <a href="?editar=<?= $p['id_proveedor'] ?>" class="btn-act btn-edit" title="Editar">
                  <i class="bi bi-pencil"></i>
                </a>
                <a href="?desactivar=<?= $p['id_proveedor'] ?>"
                   class="btn-act btn-del" title="Desactivar"
                   onclick="return confirm('¿Desactivar a <?= htmlspecialchars($p['nombre']) ?>?')">
                  <i class="bi bi-trash3"></i>
                </a>
              </div>
            </td>
            <?php endif; ?>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /.g-body -->
</div><!-- /.page -->

<script>
const selectEntrega = document.getElementById('tipo_entrega');
const campoDias     = document.getElementById('campo-dias');
const inpDias       = document.getElementById('inp-dias');
const campoVisita   = document.getElementById('campo-visita');

function toggleCampos() {
  const tipo = selectEntrega.value;
  if (tipo === 'visita') {
    campoDias.style.display   = 'none';
    campoVisita.style.display = 'block';
    inpDias.value = 0;
  } else if (tipo === 'recogida') {
    campoDias.style.display   = 'none';
    campoVisita.style.display = 'none';
    inpDias.value = 0;
  } else {
    campoDias.style.display   = 'block';
    campoVisita.style.display = 'none';
  }
}

selectEntrega.addEventListener('change', toggleCampos);
toggleCampos();
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
