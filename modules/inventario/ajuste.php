<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirLogin();

$pdo     = getConexion();
$id      = (int) ($_GET['id'] ?? 0);
$errores = [];

// Obtener insumo
$stmt = $pdo->prepare("SELECT * FROM insumo WHERE id_insumo = ? AND activo = 1");
$stmt->execute([$id]);
$insumo = $stmt->fetch();

if (!$insumo) {
    redirigir(APP_URL . '/modules/inventario/index.php', 'error', 'Insumo no encontrado.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cantidad_real = (float) ($_POST['cantidad_real'] ?? -1);
    $motivo        = limpiar($_POST['motivo'] ?? '');

    if ($cantidad_real < 0)   $errores[] = 'La cantidad real no puede ser negativa.';
    if (empty($motivo))       $errores[] = 'El motivo del ajuste es obligatorio.';

    if (empty($errores)) {
        $diferencia     = $cantidad_real - $insumo['stock_actual'];
        $id_usuario     = usuarioActual()['id_usuario'];

        $pdo->beginTransaction();
        try {
            // 1. Registrar el ajuste
            $stmt = $pdo->prepare(
                "INSERT INTO ajuste_inventario
                 (id_insumo, id_usuario, cantidad_antes, cantidad_despues, diferencia, motivo)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $id, $id_usuario,
                $insumo['stock_actual'], $cantidad_real,
                $diferencia, $motivo
            ]);

            // 2. Actualizar stock del insumo
            $stmt = $pdo->prepare(
                "UPDATE insumo SET stock_actual = ? WHERE id_insumo = ?"
            );
            $stmt->execute([$cantidad_real, $id]);

            // 3. Sincronizar tabla lote con el nuevo stock
            // La producción lee lotes, no stock_actual directamente.
            // Si hay lotes activos ajustamos sus cantidades; si no, creamos uno de ajuste.
            $stmt_lotes = $pdo->prepare(
                "SELECT id_lote, cantidad_disponible FROM lote
                 WHERE id_insumo = ? AND estado = 'activo'
                 ORDER BY fecha_ingreso ASC"
            );
            $stmt_lotes->execute([$id]);
            $lotes_activos = $stmt_lotes->fetchAll();

            if ($diferencia > 0) {
                // Stock aumentó: sumar diferencia al lote más reciente activo
                if (!empty($lotes_activos)) {
                    $ultimo_lote = end($lotes_activos);
                    $nueva_disp  = round((float)$ultimo_lote['cantidad_disponible'] + $diferencia, 4);
                    $pdo->prepare("UPDATE lote SET cantidad_disponible = ?, estado = 'activo' WHERE id_lote = ?")
                        ->execute([$nueva_disp, $ultimo_lote['id_lote']]);
                } else {
                    // Sin lotes activos → crear lote de ajuste
                    $prefijo  = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $insumo['nombre']), 0, 3));
                    $num_lote = 'AJU-' . $prefijo . '-' . date('Y-m-d') . '-001';
                    $pdo->prepare(
                        "INSERT INTO lote (id_insumo, numero_lote, cantidad_inicial, cantidad_disponible,
                         precio_unitario, fecha_ingreso, estado)
                         VALUES (?, ?, ?, ?, 0, NOW(), 'activo')"
                    )->execute([$id, $num_lote, $cantidad_real, $cantidad_real]);
                }
            } elseif ($diferencia < 0) {
                // Stock bajó: descontar de lotes activos en orden FIFO
                $a_descontar = abs($diferencia);
                foreach ($lotes_activos as $lote) {
                    if ($a_descontar <= 0) break;
                    $consumir     = min((float)$lote['cantidad_disponible'], $a_descontar);
                    $nueva_disp   = round((float)$lote['cantidad_disponible'] - $consumir, 4);
                    $nuevo_estado = $nueva_disp <= 0 ? 'agotado' : 'activo';
                    $pdo->prepare("UPDATE lote SET cantidad_disponible = ?, estado = ? WHERE id_lote = ?")
                        ->execute([$nueva_disp, $nuevo_estado, $lote['id_lote']]);
                    $a_descontar -= $consumir;
                }
            }
            // diferencia == 0: nada que sincronizar en lotes

            // 4. Generar alerta si queda bajo el punto de reposición
            if ($cantidad_real <= $insumo['punto_reposicion']) {
                $stmt = $pdo->prepare(
                    "INSERT INTO alerta (id_usuario, tipo, modulo_origen, mensaje)
                     VALUES (?, 'stock_bajo', 'inventario', ?)"
                );
                $stmt->execute([
                    $id_usuario,
                    "Ajuste dejó stock bajo en: {$insumo['nombre']} — quedan $cantidad_real {$insumo['unidad_medida']}"
                ]);
            }

            $pdo->commit();

            redirigir(
                APP_URL . '/modules/inventario/index.php',
                'exito',
                "Ajuste registrado. Diferencia: " . ($diferencia >= 0 ? '+' : '') . formatoDecimal($diferencia, 3) . " {$insumo['unidad_medida']}"
            );

        } catch (Exception $e) {
            $pdo->rollBack();
            $errores[] = 'Error al guardar el ajuste. Intenta de nuevo.';
        }
    }
}

// Historial de ajustes del insumo
$historial = $pdo->prepare(
    "SELECT aj.*, u.nombre_completo
     FROM ajuste_inventario aj
     INNER JOIN usuario u ON u.id_usuario = aj.id_usuario
     WHERE aj.id_insumo = ?
     ORDER BY aj.fecha_ajuste DESC
     LIMIT 10"
);
$historial->execute([$id]);
$ajustes = $historial->fetchAll();

$titulo = 'Ajuste de inventario — ' . $insumo['nombre'];
include __DIR__ . '/../../views/layouts/header.php';
?>

<div class="row">
  <div class="col-md-5">

    <div class="d-flex align-items-center mb-4">
      <a href="<?= APP_URL ?>/modules/inventario/index.php" class="btn btn-outline-secondary btn-sm me-3">
        <i class="bi bi-arrow-left"></i> Volver
      </a>
      <h4 class="mb-0"><i class="bi bi-arrow-left-right"></i> Ajuste manual</h4>
    </div>

    <div class="card mb-3">
      <div class="card-body bg-light">
        <strong><?= htmlspecialchars($insumo['nombre']) ?></strong><br>
        <span class="text-muted">Stock actual: </span>
        <strong><?= formatoInteligente($insumo['stock_actual']) ?> <?= $insumo['unidad_medida'] ?></strong>
      </div>
    </div>

    <?php if (!empty($errores)): ?>
    <div class="alert alert-danger">
      <ul class="mb-0"><?php foreach ($errores as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-body">
        <form method="POST">

          <div class="mb-3">
            <label class="form-label fw-semibold">
              Cantidad real contada (<?= $insumo['unidad_medida'] ?>) <span class="text-danger">*</span>
            </label>
            <input type="number" name="cantidad_real" id="cantidad_real"
                   class="form-control form-control-lg input-cantidad"
                   value="<?= htmlspecialchars($_POST['cantidad_real'] ?? '', ENT_QUOTES) ?? '' ?>"
                   min="0" step="0.001" required autofocus>
          </div>

          <!-- Mostrar diferencia en tiempo real -->
          <div class="mb-3 p-3 rounded bg-light" id="preview-diferencia" style="display:none">
            <small class="text-muted">Diferencia:</small>
            <strong id="texto-diferencia" class="fs-5 ms-2"></strong>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold">Motivo del ajuste <span class="text-danger">*</span></label>
            <select name="motivo" class="form-select" required>
              <option value="">Seleccionar...</option>
              <option value="Conteo físico — sobrante"  <?= (($_POST['motivo'] ?? '') === 'Conteo físico — sobrante')  ? 'selected' : '' ?>>Conteo físico — sobrante</option>
              <option value="Conteo físico — faltante"  <?= (($_POST['motivo'] ?? '') === 'Conteo físico — faltante')  ? 'selected' : '' ?>>Conteo físico — faltante</option>
              <option value="Producto dañado o vencido" <?= (($_POST['motivo'] ?? '') === 'Producto dañado o vencido') ? 'selected' : '' ?>>Producto dañado o vencido</option>
              <option value="Corrección de error de registro" <?= (($_POST['motivo'] ?? '') === 'Corrección de error de registro') ? 'selected' : '' ?>>Corrección de error de registro</option>
              <option value="Otro">Otro</option>
            </select>
          </div>

          <div class="d-grid">
            <button type="submit" class="btn btn-warning fw-bold">
              <i class="bi bi-check-lg"></i> Confirmar ajuste
            </button>
          </div>

        </form>
      </div>
    </div>
  </div>

  <!-- Historial -->
  <div class="col-md-7">
    <h5 class="mb-3">Historial de ajustes</h5>
    <?php if (empty($ajustes)): ?>
    <p class="text-muted">No hay ajustes previos para este insumo.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table tabla-panaderia table-sm">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Usuario</th>
            <th class="text-end">Antes</th>
            <th class="text-end">Después</th>
            <th class="text-end">Diferencia</th>
            <th>Motivo</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ajustes as $aj): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($aj['fecha_ajuste'])) ?></td>
            <td><?= htmlspecialchars($aj['nombre_completo']) ?></td>
            <td class="text-end"><?= formatoInteligente($aj['cantidad_antes']) ?></td>
            <td class="text-end"><?= formatoInteligente($aj['cantidad_despues']) ?></td>
            <td class="text-end <?= $aj['diferencia'] >= 0 ? 'text-success' : 'text-danger' ?>">
              <?= ($aj['diferencia'] >= 0 ? '+' : '') . formatoInteligente($aj['diferencia']) ?>
            </td>
            <td><small><?= htmlspecialchars($aj['motivo']) ?></small></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
const stockActual = <?= $insumo['stock_actual'] ?>;
const inputReal   = document.getElementById('cantidad_real');
const preview     = document.getElementById('preview-diferencia');
const textoDiv    = document.getElementById('texto-diferencia');

inputReal.addEventListener('input', () => {
  const val = parseFloat(inputReal.value);
  if (!isNaN(val)) {
    const dif = val - stockActual;
    textoDiv.textContent = (dif >= 0 ? '+' : '') + dif.toFixed(3) + ' <?= $insumo['unidad_medida'] ?>';
    textoDiv.style.color = dif >= 0 ? '#198754' : '#dc3545';
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }
});
</script>

<?php include __DIR__ . '/../../views/layouts/footer.php'; ?>