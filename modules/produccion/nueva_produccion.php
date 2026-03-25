<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

// ══════════════════════════════════════════════════════════════
//  ENDPOINT AJAX — devuelve ingredientes + lotes FIFO en JSON
//  Llamado: ?ajax_lotes=1&id_producto=X&unidades=Y
// ══════════════════════════════════════════════════════════════
if (isset($_GET['ajax_lotes'])) {
    header('Content-Type: application/json');
    $id_prod  = (int)($_GET['id_producto'] ?? 0);
    $unidades = max(1, (int)($_GET['unidades'] ?? 1));

    if (!$id_prod) { echo json_encode(['error' => 'Sin producto']); exit; }

    $r = $pdo->prepare("SELECT id_receta FROM receta WHERE id_producto=? AND es_vigente=1 LIMIT 1");
    $r->execute([$id_prod]);
    $id_receta = $r->fetchColumn();
    if (!$id_receta) { echo json_encode(['error' => 'sin_receta']); exit; }

    $stmt = $pdo->prepare("
        SELECT ri.id_insumo, ri.cantidad AS cant_por_unidad, ri.aplica_merma,
               i.nombre, i.unidad_medida, i.stock_actual
        FROM receta_ingrediente ri
        INNER JOIN insumo i ON i.id_insumo = ri.id_insumo
        WHERE ri.id_receta = ?
        ORDER BY i.nombre
    ");
    $stmt->execute([$id_receta]);
    $ingredientes = $stmt->fetchAll();

    $resultado    = [];
    $hay_faltante = false;

    // cantidad receta = por tanda → multiplicar por tandas (no por unidades individuales)
    foreach ($ingredientes as $ing) {
        $cant_necesaria = $ing['cant_por_unidad'] * $unidades; // $unidades aquí = tandas (enviado desde JS)

        $stmt2 = $pdo->prepare("
            SELECT id_lote, numero_lote, fecha_ingreso, cantidad_disponible, precio_unitario
            FROM lote
            WHERE id_insumo = ? AND estado = 'activo' AND cantidad_disponible > 0
            ORDER BY fecha_ingreso ASC
        ");
        $stmt2->execute([$ing['id_insumo']]);
        $lotes = $stmt2->fetchAll();

        $total_lotes = array_sum(array_column($lotes, 'cantidad_disponible'));
        $stock_actual = (float)$ing['stock_actual'];

        // ── REGLA CENTRAL ──────────────────────────────────────────────────────
        // stock_actual ES la verdad de lo que hay físicamente en bodega.
        // Puede ser mayor que total_lotes si el stock se editó manualmente desde
        // Inventario (sin pasar por Compras). Siempre usamos stock_actual.
        $total_disponible = $stock_actual;
        $alcanza = $total_disponible >= $cant_necesaria;
        if (!$alcanza) $hay_faltante = true;

        // Detectar si hay stock sin lote (editado manualmente)
        $stock_sin_lote = max(0, $stock_actual - $total_lotes); // parte del stock sin trazabilidad
        $hay_stock_manual = $stock_sin_lote > 0;

        $lotes_a_usar = [];

        // Primero consumir de lotes FIFO
        $restante = min($cant_necesaria, $stock_actual); // no pedir más de lo que hay
        foreach ($lotes as $lote) {
            if ($restante <= 0) break;
            $consumir = min((float)$lote['cantidad_disponible'], $restante);
            $lotes_a_usar[] = [
                'id_lote'         => $lote['id_lote'],
                'numero_lote'     => $lote['numero_lote'],
                'fecha_ingreso'   => date('d/m/Y', strtotime($lote['fecha_ingreso'])),
                'disponible'      => (float)$lote['cantidad_disponible'],
                'a_consumir'      => round($consumir, 4),
                'precio_unitario' => (float)$lote['precio_unitario'],
                'es_mas_antiguo'  => count($lotes_a_usar) === 0,
                'sin_lote'        => false,
            ];
            $restante -= $consumir;
        }

        // Si queda restante, es stock manual (sin lote)
        if ($restante > 0 && $hay_stock_manual) {
            $lotes_a_usar[] = [
                'id_lote'         => null,
                'numero_lote'     => 'MANUAL',
                'fecha_ingreso'   => 'Editado en Inventario',
                'disponible'      => $stock_sin_lote,
                'a_consumir'      => round($restante, 4),
                'precio_unitario' => 0,
                'es_mas_antiguo'  => count($lotes_a_usar) === 0,
                'sin_lote'        => true,
            ];
        } elseif (empty($lotes) && $stock_actual > 0) {
            // Sin lotes en absoluto — todo el stock es manual
            $lotes_a_usar[] = [
                'id_lote'         => null,
                'numero_lote'     => 'MANUAL',
                'fecha_ingreso'   => 'Editado en Inventario',
                'disponible'      => $stock_actual,
                'a_consumir'      => round(min($cant_necesaria, $stock_actual), 4),
                'precio_unitario' => 0,
                'es_mas_antiguo'  => true,
                'sin_lote'        => true,
            ];
        }

        $resultado[] = [
            'id_insumo'        => $ing['id_insumo'],
            'nombre'           => $ing['nombre'],
            'unidad_medida'    => $ing['unidad_medida'],
            'cant_necesaria'   => round($cant_necesaria, 4),
            'total_disponible' => round($total_disponible, 4),
            'alcanza'          => $alcanza,
            'aplica_merma'     => (bool)$ing['aplica_merma'],
            'lotes_a_usar'     => $lotes_a_usar,
            'hay_stock_manual' => $hay_stock_manual || empty($lotes),
        ];
    }

    echo json_encode([
        'ok'           => true,
        'hay_faltante' => $hay_faltante,
        'ingredientes' => $resultado,
        'id_receta'    => $id_receta,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  POST — Registrar produccion + consumo FIFO de lotes
// ══════════════════════════════════════════════════════════════
$msg_ok  = '';
$msg_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar'])) {
    $id_prod  = (int)($_POST['id_producto']         ?? 0);
    $tandas   = max(1, (int)($_POST['num_tandas']    ?? 1));
    $fecha    =       $_POST['fecha_produccion']     ?? date('Y-m-d');
    $obs      = trim($_POST['observaciones']         ?? '');

    // Calcular unidades desde tandas × cantidad_por_tanda
    $sp_prod = $pdo->prepare("SELECT cantidad_por_tanda FROM producto WHERE id_producto=?");
    $sp_prod->execute([$id_prod]);
    $cant_por_tanda = (float)($sp_prod->fetchColumn() ?: 1);
    $unidades = (int)round($tandas * $cant_por_tanda);

    if (!$id_prod)        $msg_err = 'Selecciona un producto.';
    elseif ($unidades<=0) $msg_err = 'Las unidades producidas deben ser mayor a 0.';
    else {
        $r = $pdo->prepare("SELECT id_receta FROM receta WHERE id_producto=? AND es_vigente=1 LIMIT 1");
        $r->execute([$id_prod]);
        $id_receta = $r->fetchColumn() ?: null;

        if (!$id_receta) {
            $msg_err = 'Este producto no tiene receta vigente. Créala primero en <a href="../recetas/index.php">Recetas</a>.';
        } else {
            $stmt = $pdo->prepare("
                SELECT ri.id_insumo, ri.cantidad AS cant_por_unidad, ri.aplica_merma,
                       i.nombre, i.unidad_medida
                FROM receta_ingrediente ri
                INNER JOIN insumo i ON i.id_insumo = ri.id_insumo
                WHERE ri.id_receta = ?
            ");
            $stmt->execute([$id_receta]);
            $ingredientes = $stmt->fetchAll();

            // Verificar stock suficiente
            // stock_actual es la fuente de verdad — incluye stock manual (editado en Inventario)
            $errores_stock = [];
            foreach ($ingredientes as $ing) {
                $cant_necesaria = $ing['cant_por_unidad'] * $tandas;

                $stmt2 = $pdo->prepare("SELECT stock_actual FROM insumo WHERE id_insumo=?");
                $stmt2->execute([$ing['id_insumo']]);
                $disponible = (float)$stmt2->fetchColumn();

                if ($disponible < $cant_necesaria) {
                    $errores_stock[] = "Falta <strong>{$ing['nombre']}</strong>: necesitas "
                        . formatoInteligente($cant_necesaria) . " {$ing['unidad_medida']}"
                        . ", solo hay " . formatoInteligente($disponible) . " {$ing['unidad_medida']}.";
                }
            }

            $forzar = !empty($_POST['forzar_produccion']);
            if (!empty($errores_stock) && !$forzar) {
                $msg_err = 'Stock insuficiente para producir:<br>' . implode('<br>', $errores_stock)
                         . '<br><br><form method="post" style="margin-top:.5rem" id="form-forzar">'
                         . '<input type="hidden" name="id_producto" value="' . $id_prod . '">'
                         . '<input type="hidden" name="num_tandas" value="' . $tandas . '">'
                         . '<input type="hidden" name="fecha_produccion" value="' . htmlspecialchars($fecha) . '">'
                         . '<input type="hidden" name="observaciones" value="' . htmlspecialchars($obs) . '">'
                         . '<input type="hidden" name="forzar_produccion" value="1">'
                         . '<input type="hidden" name="guardar" value="1">'
                         . '<button type="submit" style="background:linear-gradient(135deg,#e65100,#bf360c);color:#fff;border:none;border-radius:9px;padding:.55rem 1.1rem;font-size:.83rem;font-weight:700;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem;margin-top:.3rem;">'
                         . '<i class="bi bi-exclamation-triangle-fill"></i> Ya saqué los ingredientes — registrar con lo que hay'
                         . '</button></form>';
            } else {
                try {
                    $pdo->beginTransaction();

                    // 1. Insertar produccion
                    // Combinar la fecha elegida con la hora actual
                    $fecha_hora = $fecha . ' ' . date('H:i:s');
                    $pdo->prepare("
                        INSERT INTO produccion
                            (id_producto, id_receta, id_usuario, cantidad_tandas,
                             fecha_produccion, observaciones, unidades_producidas, costo_total, costo_unitario)
                        VALUES (?,?,?,?,?,?,?,0,0)
                    ")->execute([$id_prod,$id_receta,$user['id_usuario'],$tandas,$fecha_hora,
                            $obs . ($forzar ? ($obs ? ' | ' : '') . '⚠ Registrado con stock insuficiente' : ''),
                            $unidades]);
                    $id_produccion = (int)$pdo->lastInsertId();

                    $costo_total = 0.0;

                    // 2. Consumir lotes FIFO por cada ingrediente
                    // cantidad receta = por tanda → multiplicar por $tandas
                    foreach ($ingredientes as $ing) {
                        $cant_necesaria = $ing['cant_por_unidad'] * $tandas;
                        $restante = $cant_necesaria;

                        $lotes_stmt = $pdo->prepare("
                            SELECT id_lote, cantidad_disponible, precio_unitario
                            FROM lote
                            WHERE id_insumo=? AND estado='activo' AND cantidad_disponible>0
                            ORDER BY fecha_ingreso ASC
                        ");
                        $lotes_stmt->execute([$ing['id_insumo']]);
                        $lotes = $lotes_stmt->fetchAll();

                        // Consumir lotes FIFO primero
                        foreach ($lotes as $lote) {
                            if ($restante <= 0) break;
                            $consumir = min((float)$lote['cantidad_disponible'], $restante);
                            $costo    = round($consumir * (float)$lote['precio_unitario'], 2);
                            $costo_total += $costo;

                            $pdo->prepare("
                                INSERT INTO consumo_lote (id_produccion, id_lote, cantidad_consumida, cantidad_con_merma, costo_consumo)
                                VALUES (?,?,?,?,?)
                            ")->execute([$id_produccion, $lote['id_lote'], $consumir, $consumir, $costo]);

                            $nueva_disp   = round((float)$lote['cantidad_disponible'] - $consumir, 4);
                            $nuevo_estado = $nueva_disp <= 0 ? 'agotado' : 'activo';
                            $pdo->prepare("
                                UPDATE lote SET cantidad_disponible=?, estado=? WHERE id_lote=?
                            ")->execute([$nueva_disp, $nuevo_estado, $lote['id_lote']]);

                            $restante -= $consumir;
                        }

                        // Siempre descontar stock_actual (cubre lotes + stock manual)
                        $pdo->prepare("
                            UPDATE insumo SET stock_actual = GREATEST(0, stock_actual - ?) WHERE id_insumo=?
                        ")->execute([$ing['cant_por_unidad'] * $tandas, $ing['id_insumo']]);
                    }

                    // 3. Actualizar costos en la produccion
                    $costo_unit = $unidades > 0 ? round($costo_total / $unidades, 4) : 0;
                    $pdo->prepare("
                        UPDATE produccion SET costo_total=?, costo_unitario=? WHERE id_produccion=?
                    ")->execute([$costo_total, $costo_unit, $id_produccion]);

                    $pdo->commit();

                    $np = $pdo->prepare("SELECT nombre FROM producto WHERE id_producto=?");
                    $np->execute([$id_prod]);
                    $nombre_prod = $np->fetchColumn();

                    $msg_ok = "Producción registrada: <strong>{$tandas} tanda(s)</strong> → "
                            . "<strong>{$unidades} unidades</strong> de "
                            . "<strong>" . htmlspecialchars($nombre_prod) . "</strong>. "
                            . "Costo total: <strong>$" . number_format($costo_total,0,',','.') . "</strong>"
                            . " | Por unidad: <strong>$" . number_format($costo_unit,0,',','.') . "</strong>."
                            . " Lotes descontados correctamente.";

                } catch (Exception $e) {
                    $pdo->rollBack();
                    $msg_err = 'Error al registrar. Intenta de nuevo. (' . $e->getMessage() . ')';
                }
            }
        }
    }
}

// Productos activos con indicador de receta
$productos = $pdo->query("
    SELECT p.id_producto, p.nombre, p.cantidad_por_tanda,
           (SELECT COUNT(*) FROM receta WHERE id_producto=p.id_producto AND es_vigente=1) AS tiene_receta
    FROM producto p WHERE p.activo=1 ORDER BY p.nombre
")->fetchAll();

// Producciones de hoy
$prod_hoy = $pdo->query("
    SELECT pr.unidades_producidas, pr.fecha_produccion, pr.observaciones,
           pr.costo_total, pr.costo_unitario, p.nombre AS producto
    FROM produccion pr
    INNER JOIN producto p ON p.id_producto=pr.id_producto
    WHERE DATE(pr.fecha_produccion)=CURDATE()
    ORDER BY pr.fecha_produccion DESC
")->fetchAll();

$total_hoy = array_sum(array_column($prod_hoy,'unidades_producidas'));
$costo_hoy = array_sum(array_column($prod_hoy,'costo_total'));

$page_title = 'Nueva Producción';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  @keyframes spin{to{transform:rotate(360deg)}}
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
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .btn-back{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.45rem .9rem;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-back:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .g-body{display:grid;grid-template-columns:330px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.2s}.card:nth-child(2){animation-delay:.28s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ico-grn{background:rgba(25,135,84,.1);color:#198754;}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;}
  .b-neu{background:var(--clight);color:var(--c1);border:1px solid var(--border);}
  .b-grn{background:#e8f5e9;color:#2e7d32;}
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.75rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input,.fl select,.fl textarea{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;box-sizing:border-box;}
  .fl input:focus,.fl select:focus,.fl textarea:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl textarea{resize:vertical;min-height:55px;}
  .und-ctrl{display:flex;align-items:center;gap:.5rem;}
  .und-btn{width:40px;height:40px;border-radius:8px;border:1.5px solid var(--border);background:var(--clight);color:var(--ink2);font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0;}
  .und-btn:hover{background:var(--c3);color:#fff;border-color:var(--c3);}
  .und-inp{text-align:center!important;font-family:'Fraunces',serif!important;font-size:1.8rem!important;font-weight:800!important;}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.75rem;font-size:.93rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;}
  .btn-guardar:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-guardar:disabled{opacity:.45;cursor:not-allowed;}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#1b5e20;font-weight:600;margin-bottom:.65rem;display:flex;align-items:flex-start;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.8rem;color:#c62828;margin-bottom:.65rem;}
  /* PANEL LOTES */
  .lotes-panel{background:var(--clight);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:.75rem;}
  .lotes-hdr{background:linear-gradient(90deg,rgba(198,113,36,.12),rgba(198,113,36,.03));border-bottom:1px solid var(--border);padding:.5rem .8rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem;}
  .lotes-hdr-ttl{font-size:.63rem;font-weight:800;text-transform:uppercase;letter-spacing:.14em;color:var(--c3);display:flex;align-items:center;gap:.35rem;}
  .lotes-loading{padding:.9rem;text-align:center;font-size:.78rem;color:var(--ink3);display:flex;align-items:center;justify-content:center;gap:.4rem;}
  .ing-block{border-bottom:1px solid rgba(148,91,53,.07);padding:.52rem .75rem;}
  .ing-block:last-child{border-bottom:none;}
  .ing-nombre{font-size:.79rem;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:.38rem;margin-bottom:.28rem;flex-wrap:wrap;}
  .cant-badge{font-size:.58rem;font-weight:700;padding:.08rem .38rem;border-radius:20px;background:rgba(198,113,36,.1);color:var(--c3);border:1px solid rgba(198,113,36,.2);}
  .cant-badge.falta{background:rgba(229,57,53,.1);color:#c62828;border-color:rgba(229,57,53,.2);}
  .cant-badge.manual{background:rgba(198,113,36,.1);color:var(--c3);border-color:rgba(198,113,36,.2);}
  .lote-fila{display:flex;flex-direction:column;gap:.15rem;margin:.18rem 0;padding:.32rem .55rem;border-radius:7px;background:rgba(255,255,255,.8);border:1px solid rgba(148,91,53,.07);font-size:.72rem;}
  .lote-fila.mas-antiguo{background:rgba(198,113,36,.08);border-color:rgba(198,113,36,.22);}
  .lote-row1{display:flex;align-items:center;justify-content:space-between;gap:.4rem;}
  .lote-row2{display:flex;align-items:center;gap:.5rem;}
  .lote-num{font-family:monospace;font-weight:700;color:var(--ink2);font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;}
  .lote-fecha{color:var(--ink3);font-size:.62rem;white-space:nowrap;}
  .tag-antiguo{font-size:.52rem;font-weight:700;padding:.03rem .38rem;border-radius:20px;background:rgba(198,113,36,.18);color:var(--c1);white-space:nowrap;}
  .lote-consumir{color:#c62828;font-weight:700;white-space:nowrap;font-size:.68rem;}
  .lote-disp{color:#2e7d32;font-size:.62rem;white-space:nowrap;margin-left:auto;}
  .sin-lote{font-size:.72rem;color:#c62828;padding:.28rem .48rem;background:rgba(229,57,53,.05);border:1px dashed rgba(229,57,53,.28);border-radius:7px;margin:.1rem 0;}
  .alert-falta{background:rgba(229,57,53,.07);border:1px solid rgba(229,57,53,.18);border-radius:8px;padding:.45rem .65rem;font-size:.75rem;color:#c62828;margin-top:.4rem;display:flex;align-items:center;gap:.35rem;}
  .bloqueo-bar{background:rgba(229,57,53,.08);border-top:1px solid rgba(229,57,53,.15);padding:.5rem .75rem;font-size:.73rem;color:#c62828;font-weight:700;display:flex;align-items:center;gap:.35rem;}
  /* TABLA */
  .tbl-wrap{overflow-y:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .9rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;}
  .gt td{font-size:.82rem;color:var(--ink);padding:.5rem .9rem;border-bottom:1px solid rgba(148,91,53,.05);}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .gt tfoot td{background:var(--clight);padding:.55rem .9rem;font-weight:800;border-top:1.5px solid var(--border);}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:3rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}
  @media(max-width:900px){.page{height:auto;overflow:visible;margin-top:60px;}.g-body{grid-template-columns:1fr;}}
</style>

<div class="page">

  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Nueva <em>Producción</em></div>
        <div class="wc-sub">Consumo FIFO de lotes automático · <?= date('d/m/Y') ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill <?= $total_hoy > 0 ? 'ok' : '' ?>">
        <div class="wc-pill-num"><?= $total_hoy ?></div>
        <div class="wc-pill-lbl">Unidades hoy</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= count($prod_hoy) ?></div>
        <div class="wc-pill-lbl">Registros</div>
      </div>
      <div class="wc-pill <?= $costo_hoy > 0 ? 'ok' : '' ?>">
        <div class="wc-pill-num">$<?= number_format($costo_hoy/1000,1) ?>k</div>
        <div class="wc-pill-lbl">Costo hoy</div>
      </div>
    </div>
  </div>

  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-fire"></i> Nueva producción</div>
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Volver</a>
  </div>

  <div class="g-body">

    <!-- FORMULARIO -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-nar"><i class="bi bi-pencil-fill"></i></div><span class="ch-title">Registrar producción</span></div>
      </div>
      <div class="form-body">

        <?php if ($msg_ok): ?>
        <div class="msg-ok"><i class="bi bi-check-circle-fill"></i><span><?= $msg_ok ?></span></div>
        <?php endif; ?>
        <?php if ($msg_err): ?>
        <div class="msg-err"><i class="bi bi-exclamation-circle-fill"></i> <?= $msg_err ?></div>
        <?php endif; ?>

        <form method="post" id="form-prod">

          <div class="fl">
            <label>Producto</label>
            <select name="id_producto" id="sel-prod" required onchange="cargarLotes()">
              <option value="">— Seleccionar producto —</option>
              <?php foreach ($productos as $p): ?>
              <option value="<?= $p['id_producto'] ?>"
                data-tanda="<?= (int)$p['cantidad_por_tanda'] ?>"
                <?= (isset($_POST['id_producto']) && $_POST['id_producto']==$p['id_producto']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($p['nombre']) ?>
                (<?= (int)$p['cantidad_por_tanda'] ?> und/tanda)
                <?= !$p['tiene_receta'] ? ' ⚠ sin receta' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="fl">
            <label>N° de tandas a producir</label>
            <div class="und-ctrl">
              <button type="button" class="und-btn" onclick="changeUnd(-1)">−</button>
              <input type="number" name="num_tandas" id="inp-und" class="und-inp"
                     min="1" value="<?= (int)($_POST['num_tandas'] ?? 1) ?>"
                     required oninput="cargarLotes()">
              <button type="button" class="und-btn" onclick="changeUnd(1)">+</button>
            </div>
            <!-- Preview total unidades -->
            <div id="preview-unidades" style="margin-top:.35rem;font-size:.78rem;color:var(--ink3);display:none;">
              = <span id="preview-unidades-val" style="font-family:'Fraunces',serif;font-weight:800;color:var(--c3);font-size:.92rem;"></span>
              <span id="preview-unidades-lbl"> panes disponibles para venta</span>
            </div>
          </div>



          <!-- PANEL DE LOTES FIFO -->
          <div id="panel-lotes" class="fl" style="display:none;">
            <label><i class="bi bi-boxes" style="color:var(--c3)"></i> Guía de lotes a usar (más antiguos primero)</label>
            <div class="lotes-panel">
              <div class="lotes-hdr">
                <span class="lotes-hdr-ttl"><i class="bi bi-sort-up"></i> Orden FIFO — saca estos lotes del estante</span>
                <span id="badge-lotes" class="badge b-neu"></span>
              </div>
              <div id="lotes-contenido">
                <div class="lotes-loading"><i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite;display:inline-block"></i> Cargando…</div>
              </div>
            </div>
          </div>

          <div class="fl">
            <label>Fecha</label>
            <input type="date" name="fecha_produccion"
                   value="<?= htmlspecialchars($_POST['fecha_produccion'] ?? date('Y-m-d'), ENT_QUOTES) ?? date('Y-m-d') ?>">
          </div>

          <div class="fl">
            <label>Observaciones <span style="font-weight:400;text-transform:none;font-size:.7rem;">(opcional)</span></label>
            <textarea name="observaciones" placeholder="Ej: levadura reducida, horneado doble…"><?= htmlspecialchars($_POST['observaciones'] ?? '') ?></textarea>
          </div>

          <button type="submit" name="guardar" class="btn-guardar" id="btn-guardar">
            <i class="bi bi-check-lg"></i> Registrar y descontar lotes
          </button>

        </form>
      </div>
    </div>

    <!-- TABLA DE HOY -->
    <div class="card">
      <div class="ch">
        <div class="ch-left"><div class="ch-ico ico-grn"><i class="bi bi-clock-history"></i></div><span class="ch-title">Producción de hoy</span></div>
        <span class="badge b-grn"><?= $total_hoy ?> unidades · $<?= number_format($costo_hoy,0,',','.') ?></span>
      </div>
      <?php if (empty($prod_hoy)): ?>
      <div class="empty"><i class="bi bi-basket"></i><strong>Sin registros aún</strong><span>Los registros de hoy aparecen aquí</span></div>
      <?php else: ?>
      <div class="tbl-wrap">
        <table class="gt">
          <thead>
            <tr>
              <th>Hora</th>
              <th>Producto</th>
              <th style="text-align:center">Unids.</th>
              <th style="text-align:right">Costo total</th>
              <th style="text-align:right">C/unidad</th>
              <th>Observ.</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($prod_hoy as $pr): ?>
          <tr>
            <td style="color:var(--ink3);font-size:.75rem"><?= date('H:i', strtotime($pr['fecha_produccion'])) ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($pr['producto']) ?></td>
            <td style="text-align:center;font-weight:800;font-family:'Fraunces',serif;font-size:1.05rem;color:var(--c3)"><?= $pr['unidades_producidas'] ?></td>
            <td style="text-align:right;color:#1b5e20;font-weight:600;font-size:.8rem">$<?= number_format($pr['costo_total'],0,',','.') ?></td>
            <td style="text-align:right;color:var(--ink3);font-size:.75rem">$<?= number_format($pr['costo_unitario'],0,',','.') ?></td>
            <td style="color:var(--ink3);font-size:.76rem"><?= htmlspecialchars($pr['observaciones'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" style="text-align:right;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;color:var(--ink2)">Total hoy</td>
              <td style="text-align:center;font-family:'Fraunces',serif;color:#2e7d32"><?= $total_hoy ?></td>
              <td style="text-align:right;color:#1b5e20">$<?= number_format($costo_hoy,0,',','.') ?></td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

  </div>
</div>

<script>
function changeUnd(d) {
  const i = document.getElementById('inp-und');
  i.value = Math.max(1, (parseInt(i.value) || 1) + d);
  cargarLotes();
}

let timer = null;
function cargarLotes() {
  clearTimeout(timer);
  timer = setTimeout(_fetch, 420);
}

function _fetch() {
  const prod = document.getElementById('sel-prod').value;
  const tandas = parseInt(document.getElementById('inp-und').value) || 1;
  const selOpt = document.getElementById('sel-prod').selectedOptions[0];
  const cantXTanda = parseInt(selOpt?.dataset?.tanda || 1);
  const totalUnidades = tandas * cantXTanda;
  const panel = document.getElementById('panel-lotes');

  // Update preview
  const prev = document.getElementById('preview-unidades');
  const prevVal = document.getElementById('preview-unidades-val');
  if (selOpt && selOpt.value && cantXTanda > 0) {
    prevVal.textContent = totalUnidades.toLocaleString('es-CO');
    prev.style.display = 'block';
  } else {
    prev.style.display = 'none';
  }

  if (!prod) { panel.style.display = 'none'; return; }

  panel.style.display = 'block';
  document.getElementById('lotes-contenido').innerHTML =
    '<div class="lotes-loading"><i class="bi bi-arrow-repeat" style="animation:spin .8s linear infinite;display:inline-block"></i> Cargando lotes…</div>';
  document.getElementById('btn-guardar').disabled = false;

  fetch('nueva_produccion.php?ajax_lotes=1&id_producto=' + prod + '&unidades=' + tandas)
    .then(r => r.json())
    .then(data => {
      if (data.error === 'sin_receta') {
        document.getElementById('lotes-contenido').innerHTML =
          '<div class="lotes-loading" style="color:#c62828"><i class="bi bi-exclamation-circle"></i> Sin receta vigente — créala en <strong>Recetas</strong> primero.</div>';
        document.getElementById('badge-lotes').textContent = '';
        return;
      }
      if (!data.ok) return;

      document.getElementById('badge-lotes').textContent = data.ingredientes.length + ' ingrediente(s)';
      // No bloqueamos el botón — mostramos advertencia y dejamos que el usuario decida
      document.getElementById('btn-guardar').disabled = false;

      const fmt = n => Number(n).toLocaleString('es-CO', {maximumFractionDigits: 3});
      let html = '';

      data.ingredientes.forEach(ing => {
        html += '<div class="ing-block">';
        html += '<div class="ing-nombre">'
              + '<i class="bi bi-bag" style="color:var(--c3);font-size:.72rem"></i>'
              + '<strong>' + ing.nombre + '</strong>'
              + '<span class="cant-badge' + (!ing.alcanza ? ' falta' : (ing.hay_stock_manual ? ' manual' : '')) + '">'
              + fmt(ing.cant_necesaria) + ' ' + ing.unidad_medida
              + (ing.total_disponible > 0 ? ' · disp: ' + fmt(ing.total_disponible) : '') + '</span>'
              + (ing.aplica_merma ? '<span style="font-size:.56rem;color:var(--c3)">🌾 merma</span>' : '')
              + '</div>';

        if (ing.lotes_a_usar.length === 0) {
          html += '<div class="sin-lote"><i class="bi bi-exclamation-triangle"></i> Sin stock disponible para este insumo — registra una compra primero.</div>';
        } else {
          ing.lotes_a_usar.forEach(lote => {
            if (lote.sin_lote) {
              // Stock editado manualmente en Inventario — válido pero sin número de lote
              html += '<div class="lote-fila mas-antiguo" style="background:rgba(198,113,36,.06);border-color:rgba(198,113,36,.2);">'                    + '<div class="lote-row1">'                    + '<span class="lote-num"><i class="bi bi-pencil-square" style="font-size:.58rem;margin-right:.2rem;color:var(--c3)"></i>Stock editado en Inventario</span>'                    + '<span style="font-size:.55rem;font-weight:700;padding:.04rem .3rem;border-radius:20px;background:rgba(198,113,36,.12);color:var(--c3);border:1px solid rgba(198,113,36,.25);">sin lote</span>'                    + '</div>'                    + '<div class="lote-row2">'                    + '<span class="lote-fecha">Sin número de lote</span>'                    + '<span class="lote-consumir">−' + fmt(lote.a_consumir) + ' ' + ing.unidad_medida + '</span>'                    + '<span class="lote-disp">disp: ' + fmt(lote.disponible) + '</span>'                    + '</div>'                    + '</div>';
            } else {
              html += '<div class="lote-fila' + (lote.es_mas_antiguo ? ' mas-antiguo' : '') + '">'                    + '<div class="lote-row1">'                    + '<span class="lote-num"><i class="bi bi-tag-fill" style="color:var(--c3);font-size:.58rem;margin-right:.2rem"></i>' + lote.numero_lote + '</span>'                    + (lote.es_mas_antiguo ? '<span class="tag-antiguo">📦 más antiguo</span>' : '')                    + '</div>'                    + '<div class="lote-row2">'                    + '<span class="lote-fecha">' + lote.fecha_ingreso + '</span>'                    + '<span class="lote-consumir">−' + fmt(lote.a_consumir) + ' ' + ing.unidad_medida + '</span>'                    + '<span class="lote-disp">disp: ' + fmt(lote.disponible) + '</span>'                    + '</div>'                    + '</div>';
            }
          });
        }

        if (!ing.alcanza) {
          const falta = ing.cant_necesaria - ing.total_disponible;
          html += '<div class="alert-falta"><i class="bi bi-exclamation-triangle-fill"></i>'
                + 'Faltan <strong>' + fmt(falta) + ' ' + ing.unidad_medida + '</strong>.'
                + ' Actualiza el stock en <strong>Inventario</strong> o registra una compra.'
                + '</div>';
        }
        html += '</div>';
      });

      if (data.hay_faltante) {
        html += '<div class="bloqueo-bar" style="background:rgba(230,81,0,.1);border:1px solid rgba(230,81,0,.3);border-radius:9px;padding:.6rem .85rem;font-size:.78rem;font-weight:600;color:#e65100;display:flex;align-items:center;gap:.4rem;margin-top:.4rem;">'
              + '<i class="bi bi-exclamation-triangle-fill"></i>'
              + '⚠ Stock insuficiente. Si ya sacaste los ingredientes del estante, haz clic en <strong>Registrar</strong> de todas formas — el sistema consumirá lo que haya y registrará el faltante.'
              + '</div>';
      }

      document.getElementById('lotes-contenido').innerHTML = html;
    })
    .catch(() => {
      document.getElementById('lotes-contenido').innerHTML =
        '<div class="lotes-loading" style="color:#c62828"><i class="bi bi-wifi-off"></i> Error de conexión.</div>';
    });
}

window.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('sel-prod');
  if (sel.value) cargarLotes();
  else document.getElementById('btn-guardar').disabled = false;

  // Also update preview when product changes
  sel.addEventListener('change', () => {
    const opt = sel.selectedOptions[0];
    const cantXTanda = parseInt(opt?.dataset?.tanda || 0);
    const tandas = parseInt(document.getElementById('inp-und').value) || 1;
    const prev = document.getElementById('preview-unidades');
    const prevVal = document.getElementById('preview-unidades-val');
    if (cantXTanda > 0) {
      prevVal.textContent = (tandas * cantXTanda).toLocaleString('es-CO');
      prev.style.display = 'block';
    } else {
      prev.style.display = 'none';
    }
  });
});
</script>
</body></html>