<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sesion.php';
require_once __DIR__ . '/../../includes/funciones.php';

requerirPropietario();
$pdo  = getConexion();
$user = usuarioActual();

$msg_ok  = '';
$msg_err = '';

// ── Registrar compra ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_compra'])) {
    if (esHoyDomingo()) {
        $msg_err = 'No se pueden registrar compras los domingos.';
    } else {
        $id_insumo    = (int)   ($_POST['id_insumo']       ?? 0);
        $id_proveedor = (int)   ($_POST['id_proveedor']    ?? 0);
        $fecha        =         ($_POST['fecha_compra']    ?? date('Y-m-d'));
        $cantidad     = (float) ($_POST['cantidad']        ?? 0);
        $num_bultos   = max(1, (int)($_POST['num_bultos']  ?? 1));
        $precio_bulto = (float) ($_POST['precio_bulto']    ?? 0);

        // precio_unitario = precio por unidad de medida (kg, l, unidad…)
        $precio_unit  = $cantidad > 0 ? round($precio_bulto / ($cantidad / $num_bultos), 4) : 0;
        $total        = round($precio_bulto * $num_bultos, 2);

        if (!$id_insumo || !$id_proveedor || $cantidad <= 0 || $precio_bulto <= 0) {
            $msg_err = 'Todos los campos son obligatorios y deben ser mayores a 0.';
        } else {
            $stmt_prev = $pdo->prepare("SELECT precio_unitario FROM compra WHERE id_insumo=? ORDER BY fecha_compra DESC LIMIT 1");
            $stmt_prev->execute([$id_insumo]);
            $precio_anterior = (float)($stmt_prev->fetchColumn() ?: 0);
            $variacion = calcularVariacion($precio_anterior, $precio_unit);

            $stmt_ins = $pdo->prepare("SELECT nombre, unidad_medida, es_harina FROM insumo WHERE id_insumo=?");
            $stmt_ins->execute([$id_insumo]);
            $insumo_data = $stmt_ins->fetch();
            $prefijo_lote = strtoupper(substr($insumo_data['nombre'], 0, 3));
            $numero_lote  = generarNumeroLote($prefijo_lote);

            // Merma 6% en harina: el lote disponible es el 94% de lo comprado
            $es_harina = (bool)$insumo_data['es_harina'];
            $cantidad_disponible = $es_harina ? round($cantidad * 0.94, 3) : $cantidad;

            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO compra (id_insumo, id_proveedor, fecha_compra, cantidad,
                        precio_unitario, total_pagado, variacion_precio_pct, id_usuario)
                    VALUES (?,?,?,?,?,?,?,?)
                ")->execute([$id_insumo, $id_proveedor, $fecha, $cantidad, $precio_unit, $total, $variacion, $user['id_usuario']]);
                $id_compra_nueva = (int)$pdo->lastInsertId();

                $pdo->prepare("
                    INSERT INTO lote (id_insumo, id_compra, numero_lote, cantidad_inicial, cantidad_disponible,
                        precio_unitario, fecha_ingreso, estado)
                    VALUES (?,?,?,?,?,?,?,'activo')
                ")->execute([$id_insumo, $id_compra_nueva, $numero_lote, $cantidad, $cantidad_disponible, $precio_unit, $fecha]);

                $pdo->prepare("UPDATE insumo SET stock_actual = stock_actual + ? WHERE id_insumo=?")
                    ->execute([$cantidad_disponible, $id_insumo]);

                if ($variacion != 0) {
                    $pdo->prepare("
                        INSERT INTO historial_precio (id_insumo, id_proveedor, id_compra, precio, variacion_pct)
                        VALUES (?,?,?,?,?)
                    ")->execute([$id_insumo, $id_proveedor, $id_compra_nueva, $precio_unit, $variacion]);
                }
                $pdo->commit();
                $merma_aviso = $es_harina ? " (merma aplicada: disponible <strong>".number_format($cantidad_disponible,3)." kg</strong> de {$cantidad} kg comprados)" : '';
                $alerta_precio = abs($variacion) >= 5 ? " ⚠️ Variación de precio: {$variacion}%" : '';
                $msg_ok = "Compra registrada. Lote <strong>$numero_lote</strong> creado.$merma_aviso$alerta_precio";
            } catch (Exception $e) {
                $pdo->rollBack();
                $msg_err = 'Error al guardar la compra. Intenta de nuevo.';
            }
        }
    }
}

// ── Filtros ────────────────────────────────────────────────────────────────
$busca         = trim($_GET['q'] ?? '');
$filtro_alerta = !empty($_GET['alerta']);
$mes_filtro    = preg_match('/^\d{4}-\d{2}$/', $_GET['mes'] ?? '') ? $_GET['mes'] : date('Y-m');

$where  = "WHERE 1=1";
$params = [];
if ($busca)         { $where .= " AND (i.nombre LIKE ? OR p.nombre LIKE ?)"; $params[] = "%$busca%"; $params[] = "%$busca%"; }
if ($filtro_alerta) { $where .= " AND ABS(c.variacion_precio_pct) >= 5"; }
if ($mes_filtro)    { $where .= " AND DATE_FORMAT(c.fecha_compra,'%Y-%m') = ?"; $params[] = $mes_filtro; }

// ── Listado compras ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*, i.nombre AS insumo, i.unidad_medida, p.nombre AS proveedor
    FROM compra c
    INNER JOIN insumo i    ON i.id_insumo    = c.id_insumo
    INNER JOIN proveedor p ON p.id_proveedor = c.id_proveedor
    $where ORDER BY c.fecha_compra DESC LIMIT 50
");
$stmt->execute($params);
$compras = $stmt->fetchAll();

// ── KPIs ───────────────────────────────────────────────────────────────────
$total_mes      = (float)$pdo->query("SELECT COALESCE(SUM(total_pagado),0) FROM compra WHERE DATE_FORMAT(fecha_compra,'%Y-%m')='".date('Y-m')."'")->fetchColumn();
$compras_mes    = (int)  $pdo->query("SELECT COUNT(*) FROM compra WHERE DATE_FORMAT(fecha_compra,'%Y-%m')='".date('Y-m')."'")->fetchColumn();
$alertas_precio = (int)  $pdo->query("SELECT COUNT(*) FROM compra WHERE ABS(variacion_precio_pct)>=5 AND DATE_FORMAT(fecha_compra,'%Y-%m')='".date('Y-m')."'")->fetchColumn();
$proveedores_n  = (int)  $pdo->query("SELECT COUNT(*) FROM proveedor WHERE activo=1")->fetchColumn();

// ── Datos para los modales ─────────────────────────────────────────────────
$insumos = $pdo->query("
    SELECT id_insumo, nombre, unidad_medida, stock_actual, punto_reposicion, es_harina
    FROM insumo WHERE activo=1 ORDER BY nombre
")->fetchAll();

$proveedores = $pdo->query("
    SELECT id_proveedor, nombre, telefono, tipo_entrega, dias_visita, dias_entrega_promedio
    FROM proveedor WHERE activo=1 ORDER BY nombre
")->fetchAll();

$page_title = 'Compras';
require_once __DIR__ . '/../../views/layouts/header.php';
?>
<style>
  :root{--c1:#945b35;--c2:#c8956e;--c3:#c67124;--c4:#e4a565;--c5:#ecc198;--cbg:#faf3ea;--ccard:#fff;--clight:#fdf6ee;--ink:#281508;--ink2:#6b3d1e;--ink3:#b87a4a;--border:rgba(148,91,53,.12);--shadow:0 1px 8px rgba(148,91,53,.09);--shadow2:0 4px 20px rgba(148,91,53,.15);--nav-h:64px;}
  @keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
  @keyframes gradAnim{0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
  @keyframes modalIn{from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}

  .page{margin-top:var(--nav-h);height:calc(100vh - var(--nav-h));overflow:hidden;display:grid;grid-template-rows:auto auto 1fr;gap:.7rem;padding:.75rem;}

  /* ── BANNER ── */
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
  .wc-pill.alert{background:rgba(255,205,210,.25);border-color:rgba(255,205,210,.4);}
  .wc-pill.alert .wc-pill-num{color:#ffcdd2;}
  .wc-pill.ok{background:rgba(200,255,220,.2);border-color:rgba(200,255,220,.35);}
  .wc-pill.ok .wc-pill-num{color:#c8ffd8;}

  /* ── TOPBAR ── */
  .topbar{display:flex;align-items:center;justify-content:space-between;gap:.75rem;flex-wrap:wrap;}
  .mod-titulo{font-family:'Fraunces',serif;font-size:1.45rem;font-weight:800;color:var(--ink);display:flex;align-items:center;gap:.5rem;}
  .mod-titulo i{color:var(--c3);}
  .top-actions{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;}
  .btn-sec{background:var(--ccard);color:var(--ink2);border:1px solid var(--border);border-radius:10px;padding:.5rem 1rem;font-size:.82rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:.4rem;text-decoration:none;transition:all .2s;}
  .btn-sec:hover{background:var(--clight);border-color:var(--c3);color:var(--ink);}
  .btn-sec.active{background:rgba(198,113,36,.1);border-color:var(--c3);color:var(--c3);}
  .inp-search{border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.82rem;font-family:inherit;color:var(--ink);background:var(--ccard);width:180px;}
  .inp-search:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}

  /* ── CUERPO ── */
  .g-body{display:grid;grid-template-columns:310px 1fr;gap:.7rem;min-height:0;}
  .card{background:var(--ccard);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;min-height:0;animation:fadeUp .45s ease both;}
  .card:nth-child(1){animation-delay:.25s}.card:nth-child(2){animation-delay:.3s}
  .ch{display:flex;align-items:center;justify-content:space-between;padding:.8rem 1.1rem;flex-shrink:0;border-bottom:1px solid var(--border);}
  .ch-left{display:flex;align-items:center;gap:.5rem;}
  .ch-ico{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;}
  .ico-nar{background:rgba(198,113,36,.1);color:var(--c3);}
  .ch-title{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.17em;color:var(--ink3);}
  .badge-n{display:inline-flex;align-items:center;font-size:.62rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;background:var(--clight);color:var(--c1);border:1px solid var(--border);}

  /* ── FORMULARIO ── */
  .form-body{padding:.9rem 1.1rem;overflow-y:auto;flex:1;}
  .fl{margin-bottom:.65rem;}
  .fl label{font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--ink3);display:block;margin-bottom:.3rem;}
  .fl input{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);transition:border-color .2s,box-shadow .2s;}
  .fl input:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .fl-row{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;}

  /* Picker — campo que abre modal */
  .picker-field{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--clight);cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:.4rem;transition:border-color .2s,box-shadow .2s;user-select:none;}
  .picker-field:hover{border-color:var(--c3);}
  .picker-field.filled{background:var(--ccard);border-color:var(--c3);font-weight:600;}
  .picker-field i{color:var(--ink3);font-size:.8rem;flex-shrink:0;}
  .picker-field.filled i{color:var(--c3);}

  /* Campos precio bulto */
  /* ── Resumen de compra (preview automático) ── */
  .compra-resumen{background:rgba(198,113,36,.06);border:1px solid rgba(198,113,36,.2);border-radius:11px;padding:.7rem .9rem;margin-bottom:.7rem;display:none;flex-direction:column;gap:.3rem;}
  .cr-row{display:flex;align-items:center;justify-content:space-between;font-size:.78rem;}
  .cr-lbl{color:var(--ink3);font-weight:600;}
  .cr-val{font-family:'Fraunces',serif;font-weight:800;color:var(--c1);}
  .cr-val.grande{font-size:1.05rem;color:var(--ink);}
  .cr-sep{height:1px;background:rgba(198,113,36,.12);margin:.15rem 0;}
  /* Badge total calculado */
  .total-badge{display:flex;align-items:center;gap:.45rem;background:rgba(198,113,36,.08);border:1px solid rgba(198,113,36,.2);border-radius:8px;padding:.32rem .7rem;font-size:.78rem;font-weight:700;color:var(--c3);margin-top:.2rem;}
  .total-badge i{font-size:.85rem;}
  /* Separador de sección */
  .sec-sep{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.18em;color:var(--ink3);margin:.6rem 0 .35rem;display:flex;align-items:center;gap:.45rem;}
  .sec-sep::after{content:'';flex:1;height:1px;background:var(--border);}
  /* Campo con unidad inline */
  .inp-unidad-wrap{display:flex;align-items:center;gap:.4rem;}
  .inp-unidad-wrap input{flex:1;}
  .inp-unidad-tag{background:rgba(198,113,36,.1);border:1px solid rgba(198,113,36,.2);border-radius:7px;padding:.3rem .6rem;font-size:.78rem;font-weight:700;color:var(--c3);white-space:nowrap;flex-shrink:0;}
  .btn-guardar{width:100%;background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:10px;padding:.65rem;font-size:.88rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 4px 14px rgba(198,113,36,.3);display:flex;align-items:center;justify-content:center;gap:.4rem;transition:all .2s;margin-top:.2rem;}
  .btn-guardar:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(198,113,36,.4);}
  .btn-guardar:disabled{opacity:.45;cursor:not-allowed;transform:none;}
  /* Selección múltiple etiquetas */
  .gt td.td-chk,.gt th.th-chk{width:36px;padding:.5rem .5rem .5rem .85rem;text-align:center;}
  .row-chk{width:16px;height:16px;accent-color:var(--c3);cursor:pointer;}
  .chk-all{width:16px;height:16px;accent-color:var(--c3);cursor:pointer;}
  .sel-bar{display:none;align-items:center;gap:.65rem;background:linear-gradient(135deg,var(--c3),var(--c1));border-radius:10px;padding:.55rem .9rem;margin-bottom:.55rem;flex-shrink:0;}
  .sel-bar.visible{display:flex;}
  .sel-bar-txt{font-size:.82rem;font-weight:700;color:#fff;flex:1;}
  .btn-print-sel{background:rgba(255,255,255,.22);border:1px solid rgba(255,255,255,.35);color:#fff;border-radius:8px;padding:.4rem .85rem;font-size:.8rem;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:.4rem;transition:all .18s;}
  .btn-print-sel:hover{background:rgba(255,255,255,.35);}
  .btn-cancel-sel{background:transparent;border:1px solid rgba(255,255,255,.25);color:rgba(255,255,255,.75);border-radius:8px;padding:.4rem .7rem;font-size:.78rem;cursor:pointer;font-family:inherit;transition:all .18s;}
  .btn-cancel-sel:hover{border-color:rgba(255,255,255,.5);color:#fff;}

  /* ── TABLA ── */
  .tbl-wrap{overflow-y:auto;overflow-x:auto;flex:1;min-height:0;}
  .gt{width:100%;border-collapse:collapse;}
  .gt th{font-size:.61rem;text-transform:uppercase;letter-spacing:.1em;color:var(--ink3);font-weight:700;padding:.5rem .85rem;background:var(--clight);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:1;white-space:nowrap;}
  .gt td{font-size:.81rem;color:var(--ink);padding:.5rem .85rem;border-bottom:1px solid rgba(148,91,53,.05);vertical-align:middle;}
  .gt tr:last-child td{border-bottom:none;}
  .gt tr:hover td{background:rgba(250,243,234,.5);}
  .tag-alerta{font-size:.58rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;background:rgba(229,57,53,.1);color:#e53935;border:1px solid rgba(229,57,53,.2);}
  .tag-baja{font-size:.58rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;background:rgba(67,160,71,.1);color:#2e7d32;border:1px solid rgba(67,160,71,.2);}
  .tag-neu{font-size:.58rem;font-weight:700;padding:.15rem .45rem;border-radius:20px;background:var(--clight);color:var(--ink3);border:1px solid var(--border);}

  /* Mensajes */
  .btn-etq{border-color:rgba(198,113,36,.2);color:var(--c3);background:rgba(198,113,36,.06);}
  .btn-etq:hover{background:rgba(198,113,36,.15);transform:scale(1.08);}
  .msg-ok{background:#e8f5e9;border:1px solid #a5d6a7;border-left:3px solid #2e7d32;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#1b5e20;font-weight:600;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .msg-err{background:#ffebee;border:1px solid #ef9a9a;border-left:3px solid #c62828;border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#c62828;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .domingo-aviso{background:rgba(255,167,38,.1);border:1px solid rgba(255,167,38,.35);border-radius:10px;padding:.6rem .9rem;font-size:.82rem;color:#e65100;font-weight:600;margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
  .empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;padding:2.5rem 1rem;color:var(--ink3);font-size:.82rem;text-align:center;flex:1;}
  .empty i{font-size:2.2rem;opacity:.3;}

  /* ══════════════════════════════════
     MODALES
  ══════════════════════════════════ */
  .modal-overlay{position:fixed;inset:0;background:rgba(20,10,3,.52);backdrop-filter:blur(3px);z-index:1000;display:none;align-items:center;justify-content:center;padding:1rem;}
  .modal-overlay.open{display:flex;}
  .modal{background:var(--ccard);border-radius:18px;box-shadow:0 8px 40px rgba(40,10,0,.25);width:100%;max-width:520px;max-height:88vh;display:flex;flex-direction:column;animation:modalIn .28s cubic-bezier(.34,1.36,.64,1) both;}
  .modal-head{padding:1rem 1.2rem .75rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.75rem;flex-shrink:0;}
  .modal-head-ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;}
  .modal-head-title{font-family:'Fraunces',serif;font-size:1.1rem;font-weight:800;color:var(--ink);flex:1;}
  .modal-head-sub{font-size:.7rem;color:var(--ink3);}
  .modal-close{width:32px;height:32px;border-radius:8px;background:var(--clight);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1rem;color:var(--ink3);flex-shrink:0;transition:all .15s;}
  .modal-close:hover{background:#ffebee;color:#c62828;border-color:#ef9a9a;}

  /* Buscador dentro del modal */
  .modal-search{padding:.65rem 1.2rem;border-bottom:1px solid var(--border);flex-shrink:0;}
  .modal-search input{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .8rem .45rem 2rem;font-size:.84rem;font-family:inherit;color:var(--ink);background:var(--clight);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%23b87a4a' viewBox='0 0 16 16'%3E%3Cpath d='M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.415l-3.868-3.833zm-5.242 1.156a5 5 0 1 1 0-10 5 5 0 0 1 0 10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:.6rem center;}
  .modal-search input:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}

  /* Grid de tarjetas */
  .modal-grid{padding:.75rem 1rem;overflow-y:auto;flex:1;display:grid;gap:.5rem;}
  .modal-grid.cols2{grid-template-columns:1fr 1fr;}
  .modal-grid.cols1{grid-template-columns:1fr;}

  /* Tarjeta insumo */
  .mcard{border:1.5px solid var(--border);border-radius:12px;padding:.7rem .9rem;cursor:pointer;transition:all .18s;background:var(--clight);display:flex;flex-direction:column;gap:.25rem;position:relative;}
  .mcard:hover{border-color:var(--c3);background:rgba(198,113,36,.05);transform:translateY(-1px);box-shadow:var(--shadow);}
  .mcard.selected{border-color:var(--c3);background:rgba(198,113,36,.08);box-shadow:0 0 0 2px rgba(198,113,36,.2);}
  .mcard-name{font-size:.85rem;font-weight:700;color:var(--ink);line-height:1.2;}
  .mcard-unit{font-size:.7rem;color:var(--ink3);}
  .mcard-stock{font-size:.72rem;font-weight:600;display:flex;align-items:center;gap:.3rem;margin-top:.15rem;}
  .mcard-stock.ok{color:#2e7d32;}.mcard-stock.low{color:#e65100;}.mcard-stock.crit{color:#c62828;}
  .mcard-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
  .dot-ok{background:#4caf50;}.dot-low{background:#ff9800;}.dot-crit{background:#c62828;}
  .mcard-check{position:absolute;top:.5rem;right:.5rem;width:20px;height:20px;border-radius:50%;background:var(--c3);display:none;align-items:center;justify-content:center;color:#fff;font-size:.7rem;}
  .mcard.selected .mcard-check{display:flex;}

  /* Tarjeta proveedor */
  .pcard{border:1.5px solid var(--border);border-radius:12px;padding:.75rem 1rem;cursor:pointer;transition:all .18s;background:var(--clight);display:flex;align-items:center;gap:.75rem;}
  .pcard:hover{border-color:var(--c3);background:rgba(198,113,36,.05);transform:translateY(-1px);box-shadow:var(--shadow);}
  .pcard.selected{border-color:var(--c3);background:rgba(198,113,36,.08);box-shadow:0 0 0 2px rgba(198,113,36,.2);}
  .pcard-ico{width:38px;height:38px;border-radius:10px;background:rgba(198,113,36,.1);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
  .pcard.selected .pcard-ico{background:var(--c3);color:#fff;}
  .pcard-info{flex:1;min-width:0;}
  .pcard-name{font-size:.87rem;font-weight:700;color:var(--ink);}
  .pcard-det{font-size:.7rem;color:var(--ink3);margin-top:.1rem;}
  .pcard-check{width:22px;height:22px;border-radius:50%;border:1.5px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:all .18s;}
  .pcard.selected .pcard-check{background:var(--c3);border-color:var(--c3);color:#fff;}

  /* Sin resultados en búsqueda */
  .modal-empty{text-align:center;padding:2rem 1rem;color:var(--ink3);font-size:.82rem;}
  .modal-empty i{font-size:2rem;display:block;opacity:.3;margin-bottom:.4rem;}

  /* Footer del modal */
  .modal-foot{padding:.75rem 1.2rem;border-top:1px solid var(--border);flex-shrink:0;display:flex;gap:.5rem;justify-content:flex-end;}
  .btn-modal-cancel{background:var(--clight);border:1px solid var(--border);color:var(--ink2);border-radius:9px;padding:.5rem 1rem;font-size:.83rem;font-weight:600;cursor:pointer;font-family:inherit;transition:all .15s;}
  .btn-modal-cancel:hover{border-color:var(--c3);}
  .btn-modal-ok{background:linear-gradient(135deg,var(--c3),var(--c1));color:#fff;border:none;border-radius:9px;padding:.5rem 1.2rem;font-size:.83rem;font-weight:700;cursor:pointer;font-family:inherit;box-shadow:0 3px 10px rgba(198,113,36,.3);transition:all .2s;display:flex;align-items:center;gap:.4rem;}
  .btn-modal-ok:hover{transform:translateY(-1px);box-shadow:0 5px 16px rgba(198,113,36,.4);}
  .btn-modal-ok:disabled{opacity:.4;cursor:not-allowed;transform:none;}
  /* Tarjetas de modo cantidad */
  .qcard{border:2px solid var(--border);border-radius:14px;padding:1.1rem 1rem;cursor:pointer;transition:all .2s;background:var(--clight);display:flex;flex-direction:column;gap:.45rem;align-items:center;text-align:center;position:relative;}
  .qcard:hover{border-color:var(--c3);background:rgba(198,113,36,.05);transform:translateY(-2px);box-shadow:var(--shadow2);}
  .qcard.selected{border-color:var(--c3);background:rgba(198,113,36,.09);box-shadow:0 0 0 3px rgba(198,113,36,.18);}
  .qcard-ico{width:44px;height:44px;border-radius:12px;background:rgba(198,113,36,.1);display:flex;align-items:center;justify-content:center;font-size:1.4rem;transition:background .2s;}
  .qcard.selected .qcard-ico{background:var(--c3);color:#fff;}
  .qcard-title{font-size:.88rem;font-weight:700;color:var(--ink);line-height:1.2;}
  .qcard-desc{font-size:.72rem;color:var(--ink3);line-height:1.4;}
  .qcard-check{position:absolute;top:.55rem;right:.55rem;width:20px;height:20px;border-radius:50%;background:var(--c3);display:none;align-items:center;justify-content:center;color:#fff;font-size:.7rem;}
  .qcard.selected .qcard-check{display:flex;}
  /* Inputs dentro del modal cantidad */
  .q-inputs{padding:.75rem 1.2rem;display:flex;flex-direction:column;gap:.55rem;border-top:1px solid var(--border);background:var(--clight);}
  .q-inputs .q-row{display:grid;grid-template-columns:1fr 1fr;gap:.5rem;align-items:end;}
  .q-inputs label{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.13em;color:var(--ink3);display:block;margin-bottom:.28rem;}
  .q-inputs input{width:100%;border:1px solid var(--border);border-radius:9px;padding:.45rem .75rem;font-size:.84rem;color:var(--ink);font-family:inherit;background:var(--ccard);transition:border-color .2s,box-shadow .2s;}
  .q-inputs input:focus{outline:none;border-color:var(--c3);box-shadow:0 0 0 3px rgba(198,113,36,.1);}
  .q-result{background:rgba(198,113,36,.07);border:1px solid rgba(198,113,36,.18);border-radius:9px;padding:.55rem .85rem;font-size:.82rem;color:var(--ink2);display:flex;align-items:center;justify-content:space-between;}
  .q-result strong{font-family:'Fraunces',serif;font-size:1.05rem;color:var(--c3);}

  /* ── RESPONSIVE ── */
  @media(max-width:900px){
    .g-body{grid-template-columns:1fr;}
    .page{height:auto;overflow:visible;}
    .card{max-height:none;}
    .tbl-wrap{max-height:400px;}
  }
  @media(max-width:600px){
    .page{margin-top:60px;padding:.5rem;gap:.5rem;}
    .wc-banner{padding:.75rem 1rem;}
    .wc-name{font-size:1.1rem;}
    .wc-pills{gap:.4rem;}
    .wc-pill{min-width:58px;padding:.4rem .55rem;}
    .wc-pill-num{font-size:1.1rem;}
    .top-actions{width:100%;}
    .inp-search{width:100%!important;flex:1;}
    .fl-row{grid-template-columns:1fr;}
    .modal{max-height:92vh;border-radius:16px 16px 0 0;margin-top:auto;max-width:100%;}
    .modal-overlay{align-items:flex-end;padding:0;}
    .modal-grid.cols2{grid-template-columns:1fr;}
  }
</style>

<div class="page">

  <!-- ══ BANNER ══ -->
  <div class="wc-banner">
    <div class="wc-left">
      <div>
        <div class="wc-greeting">Panadería BreakControl</div>
        <div class="wc-name">Registro de <em>Compras</em></div>
        <div class="wc-sub">Control de insumos y proveedores — <?= date('F Y') ?></div>
      </div>
    </div>
    <div class="wc-pills">
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $compras_mes ?></div>
        <div class="wc-pill-lbl">Compras mes</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num">$<?= number_format($total_mes / 1000, 1) ?>k</div>
        <div class="wc-pill-lbl">Gasto mes</div>
      </div>
      <div class="wc-pill <?= $alertas_precio > 0 ? 'alert' : 'ok' ?>">
        <div class="wc-pill-num"><?= $alertas_precio ?></div>
        <div class="wc-pill-lbl">Alertas precio</div>
      </div>
      <div class="wc-pill">
        <div class="wc-pill-num"><?= $proveedores_n ?></div>
        <div class="wc-pill-lbl">Proveedores</div>
      </div>
    </div>
  </div>

  <!-- ══ TOPBAR ══ -->
  <div class="topbar">
    <div class="mod-titulo"><i class="bi bi-cart3"></i> Compras</div>
    <div class="top-actions">
      <form method="get" style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;">
        <input type="text" name="q" class="inp-search" placeholder="Buscar insumo o proveedor…" value="<?= htmlspecialchars($busca) ?>">
        <input type="month" name="mes" class="inp-search" style="width:145px;" value="<?= htmlspecialchars($mes_filtro) ?>">
        <a href="index.php?alerta=1<?= $busca ? '&q='.urlencode($busca) : '' ?>" class="btn-sec <?= $filtro_alerta ? 'active' : '' ?>">
          <i class="bi bi-exclamation-triangle<?= $filtro_alerta ? '-fill' : '' ?>"></i> Alertas
        </a>
      </form>
      <a href="<?= APP_URL ?>/modules/compras/proveedores.php" class="btn-sec">
        <i class="bi bi-people"></i> Proveedores
      </a>
    </div>
  </div>

  <!-- ══ CUERPO ══ -->
  <div class="g-body">

    <!-- FORMULARIO NUEVA COMPRA -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-plus-lg"></i></div>
          <span class="ch-title">Nueva compra</span>
        </div>
      </div>
      <div class="form-body">

        <?php if ($msg_ok): ?>
        <div class="msg-ok"><i class="bi bi-check-circle-fill"></i><?= $msg_ok ?></div>
        <?php
        // Obtener id de la compra recién registrada para el botón de etiqueta
        $last_id = (int)$pdo->lastInsertId();
        if (!$last_id) {
            $stmt_lid = $pdo->query("SELECT id_compra FROM compra ORDER BY id_compra DESC LIMIT 1");
            $last_id = (int)$stmt_lid->fetchColumn();
        }
        if ($last_id): ?>
        <a href="etiqueta_lote.php?id_compra=<?= $last_id ?>" target="_blank"
           style="display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:700;color:var(--c3);text-decoration:none;background:rgba(198,113,36,.07);border:1px solid rgba(198,113,36,.2);border-radius:9px;padding:.45rem .8rem;margin-bottom:.6rem;transition:all .2s"
           onmouseover="this.style.background='rgba(198,113,36,.13)'"
           onmouseout="this.style.background='rgba(198,113,36,.07)'"
        ><i class="bi bi-tag-fill"></i> Imprimir etiquetas de este lote</a>
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($msg_err): ?>
        <div class="msg-err"><i class="bi bi-exclamation-circle-fill"></i><?= $msg_err ?></div>
        <?php endif; ?>
        <?php if (esHoyDomingo()): ?>
        <div class="domingo-aviso"><i class="bi bi-moon-stars-fill"></i> Los domingos no se registran compras.</div>
        <?php endif; ?>

        <form method="POST" id="form-compra" onsubmit="prepararEnvio()">
          <input type="hidden" name="id_insumo"    id="inp-id-insumo">
          <input type="hidden" name="id_proveedor" id="inp-id-proveedor">
          <input type="hidden" name="cantidad"     id="inp-cantidad">
          <input type="hidden" name="num_bultos"   id="inp-num-bultos" value="1">
          <span id="lbl-unidad" style="display:none"></span>

          <!-- ── PASO 1: ¿Qué compraste? ── -->
          <div class="sec-sep">¿Qué compraste?</div>

          <div class="fl">
            <label>Insumo</label>
            <div class="picker-field" id="picker-insumo" onclick="abrirModal('insumos')" <?= esHoyDomingo() ? 'style="pointer-events:none;opacity:.5"' : '' ?>>
              <span id="lbl-insumo" style="color:var(--ink3)">Seleccionar insumo…</span>
              <i class="bi bi-grid-3x3-gap"></i>
            </div>
          </div>

          <div class="fl">
            <label>Proveedor</label>
            <div class="picker-field" id="picker-prov" onclick="abrirModal('proveedores')" <?= esHoyDomingo() ? 'style="pointer-events:none;opacity:.5"' : '' ?>>
              <span id="lbl-prov" style="color:var(--ink3)">Seleccionar proveedor…</span>
              <i class="bi bi-grid-3x3-gap"></i>
            </div>
          </div>

          <!-- ── PASO 2: ¿Cuánto compraste? ── -->
          <div class="sec-sep">¿Cuánto compraste?</div>

          <div class="fl-row">
            <div class="fl">
              <label>N° de bolsas / empaques</label>
              <input type="number" id="vis-bultos"
                     value="<?= htmlspecialchars($_POST['num_bultos'] ?? '1', ENT_QUOTES) ?? '1' ?>"
                     min="1" step="1" placeholder="1"
                     oninput="recalcular()"
                     <?= esHoyDomingo() ? 'disabled' : '' ?>>
            </div>
            <div class="fl">
              <label>Cantidad por bolsa <span id="tag-unidad" class="inp-unidad-tag" style="display:none"></span></label>
              <input type="number" id="vis-cant-bolsa"
                     value="<?= htmlspecialchars($_POST['vis_cant_bolsa'] ?? '', ENT_QUOTES) ?? '' ?>"
                     min="0.001" step="0.001" placeholder="Ej: 2.5"
                     oninput="recalcular()"
                     <?= esHoyDomingo() ? 'disabled' : '' ?>>
            </div>
          </div>

          <!-- Total cantidad calculado -->
          <div class="total-badge" id="badge-cant" style="display:none">
            <i class="bi bi-check-circle-fill"></i>
            <span>Total: <strong id="badge-cant-val">—</strong></span>
          </div>

          <!-- ── PASO 3: ¿Cuánto pagaste? ── -->
          <div class="sec-sep">¿Cuánto pagaste?</div>

          <div class="fl">
            <label>Precio por bolsa / empaque ($)</label>
            <input type="number" name="precio_bulto" id="inp-precio"
                   value="<?= htmlspecialchars($_POST['precio_bulto'] ?? '', ENT_QUOTES) ?? '' ?>"
                   min="1" placeholder="Ej: 9.800"
                   oninput="recalcular()"
                   <?= esHoyDomingo() ? 'disabled' : '' ?>>
          </div>

          <!-- Resumen automático -->
          <div class="compra-resumen" id="compra-resumen">
            <div class="cr-row">
              <span class="cr-lbl" id="cr-lbl-unit">Precio por kg</span>
              <span class="cr-val" id="cr-val-unit">—</span>
            </div>
            <div class="cr-row" id="cr-row-gramo" style="display:none">
              <span class="cr-lbl" id="cr-lbl-gramo">Precio por gramo</span>
              <span class="cr-val" id="cr-val-gramo" style="font-size:.78rem;font-family:inherit;font-weight:700;color:var(--ink3)">—</span>
            </div>
            <div class="cr-sep"></div>
            <div class="cr-row">
              <span class="cr-lbl" style="font-weight:700;color:var(--ink2)">Total a pagar</span>
              <span class="cr-val grande" id="cr-val-total">—</span>
            </div>
          </div>

          <!-- ── Fecha (secundaria) ── -->
          <div class="fl" style="margin-top:.3rem">
            <label style="color:var(--ink3)">Fecha de compra</label>
            <input type="date" name="fecha_compra"
                   value="<?= htmlspecialchars($_POST['fecha_compra'] ?? date('Y-m-d'), ENT_QUOTES) ?? date('Y-m-d') ?>"
                   max="<?= date('Y-m-d') ?>"
                   <?= esHoyDomingo() ? 'disabled' : '' ?>>
          </div>

          <button type="submit" name="guardar_compra" id="btn-guardar" class="btn-guardar"
                  <?= esHoyDomingo() ? 'disabled' : '' ?>>
            <i class="bi bi-cart-check-fill"></i> Registrar compra
          </button>
        </form>
      </div>
    </div>

    <!-- TABLA HISTORIAL -->
    <div class="card">
      <div class="ch">
        <div class="ch-left">
          <div class="ch-ico ico-nar"><i class="bi bi-table"></i></div>
          <span class="ch-title">Historial de compras</span>
        </div>
        <span class="badge-n"><?= count($compras) ?> registros</span>
      </div>
      <div class="tbl-wrap">
        <?php if (empty($compras)): ?>
        <div class="empty">
          <i class="bi bi-cart3"></i>
          <strong>Sin compras</strong>
          <span>Registra la primera compra usando el formulario</span>
        </div>
        <?php else: ?>
        <!-- Barra de selección múltiple -->
        <div class="sel-bar" id="sel-bar">
          <span class="sel-bar-txt"><span id="sel-count">0</span> compras seleccionadas</span>
          <button class="btn-print-sel" onclick="imprimirSeleccionadas()">
            <i class="bi bi-printer-fill"></i> Imprimir etiquetas
          </button>
          <button class="btn-cancel-sel" onclick="limpiarSeleccion()">Cancelar</button>
        </div>

        <table class="gt">
          <thead>
            <tr>
              <th class="th-chk"><input type="checkbox" class="chk-all" id="chk-all" onclick="toggleTodos(this)" title="Seleccionar todas"></th>
              <th>Fecha</th>
              <th>Insumo</th>
              <th>Proveedor</th>
              <th style="text-align:right">Cantidad</th>
              <th style="text-align:right">Precio/u</th>
              <th style="text-align:right">Total</th>
              <th style="text-align:center">Variación</th>
              <th style="text-align:center">Etiqueta</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($compras as $c):
            $var = (float)$c['variacion_precio_pct']; ?>
          <tr>
            <td class="td-chk"><input type="checkbox" class="row-chk" value="<?= $c['id_compra'] ?>" onchange="actualizarSeleccion()"></td>
            <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($c['fecha_compra'])) ?></td>
            <td><strong><?= htmlspecialchars($c['insumo']) ?></strong></td>
            <td><?= htmlspecialchars($c['proveedor']) ?></td>
            <td style="text-align:right">
              <span style="font-family:'Fraunces',serif;font-weight:700"><?= formatoInteligente($c['cantidad']) ?></span>
              <span style="font-size:.72rem;color:var(--ink3)"> <?= $c['unidad_medida'] ?></span>
            </td>
            <td style="text-align:right"><?= formatoPeso($c['precio_unitario']) ?></td>
            <td style="text-align:right;font-family:'Fraunces',serif;font-weight:700"><?= formatoPeso($c['total_pagado']) ?></td>
            <td style="text-align:center">
              <?php if ($var == 0): ?>
                <span class="tag-neu">Sin cambio</span>
              <?php elseif ($var > 0): ?>
                <span class="tag-alerta">▲ <?= $var ?>%</span>
              <?php else: ?>
                <span class="tag-baja">▼ <?= abs($var) ?>%</span>
              <?php endif; ?>
            </td>
            <td style="text-align:center">
              <a href="etiqueta_lote.php?id_compra=<?= $c['id_compra'] ?>"
                 target="_blank"
                 class="btn-act btn-etq"
                 title="Imprimir etiqueta de lote">
                <i class="bi bi-tag-fill"></i>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /g-body -->
</div><!-- /page -->


<!-- ══════════════════════════════════════════════════════
     MODAL INSUMOS
══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-insumos" onclick="cerrarAlClick(event,'modal-insumos')">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-head-ico ico-nar"><i class="bi bi-box-seam-fill"></i></div>
      <div>
        <div class="modal-head-title">Seleccionar insumo</div>
        <div class="modal-head-sub">Elige el insumo que vas a comprar</div>
      </div>
      <button class="modal-close" onclick="cerrarModal('modal-insumos')"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-search">
      <input type="text" id="busca-insumo" placeholder="Buscar insumo…" oninput="filtrarInsumos()" autocomplete="off">
    </div>
    <div class="modal-grid cols2" id="grid-insumos">
      <?php foreach ($insumos as $ins):
        $stock   = (float)$ins['stock_actual'];
        $punto   = (float)$ins['punto_reposicion'];
        if ($stock <= $punto)              { $semaforo = 'crit'; $dot = 'dot-crit'; $lbl = 'Stock crítico'; }
        elseif ($stock <= $punto * 1.5)    { $semaforo = 'low';  $dot = 'dot-low';  $lbl = 'Stock bajo'; }
        else                               { $semaforo = 'ok';   $dot = 'dot-ok';   $lbl = 'En stock'; }
      ?>
      <div class="mcard"
           data-id="<?= $ins['id_insumo'] ?>"
           data-nombre="<?= htmlspecialchars($ins['nombre']) ?>"
           data-unidad="<?= $ins['unidad_medida'] ?>"
           data-search="<?= strtolower($ins['nombre']) ?>"
           onclick="seleccionarInsumo(this)">
        <div class="mcard-check"><i class="bi bi-check2"></i></div>
        <div class="mcard-name"><?= htmlspecialchars($ins['nombre']) ?></div>
        <div class="mcard-unit"><?= $ins['unidad_medida'] ?></div>
        <div class="mcard-stock <?= $semaforo ?>">
          <span class="mcard-dot <?= $dot ?>"></span>
          <?= number_format($stock, 1) ?> <?= $ins['unidad_medida'] ?> · <?= $lbl ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="modal-empty" id="sin-insumos" style="display:none">
      <i class="bi bi-search"></i>Sin resultados para tu búsqueda
    </div>
    <div class="modal-foot">
      <button class="btn-modal-cancel" onclick="cerrarModal('modal-insumos')">Cancelar</button>
      <button class="btn-modal-ok" id="btn-ok-insumo" onclick="confirmarInsumo()" disabled>
        <i class="bi bi-check2-circle"></i> Confirmar
      </button>
    </div>
  </div>
</div>


<!-- ══════════════════════════════════════════════════════
     MODAL PROVEEDORES
══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modal-proveedores" onclick="cerrarAlClick(event,'modal-proveedores')">
  <div class="modal">
    <div class="modal-head">
      <div class="modal-head-ico ico-nar"><i class="bi bi-truck"></i></div>
      <div>
        <div class="modal-head-title">Seleccionar proveedor</div>
        <div class="modal-head-sub">Elige quién suministra este insumo</div>
      </div>
      <button class="modal-close" onclick="cerrarModal('modal-proveedores')"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="modal-search">
      <input type="text" id="busca-prov" placeholder="Buscar proveedor…" oninput="filtrarProveedores()" autocomplete="off">
    </div>
    <div class="modal-grid cols1" id="grid-proveedores">
      <?php
      $entrega_labels = ['domicilio' => '🚚 Domicilio', 'recogida' => '🏪 Recogida', 'visita' => '🤝 Visita'];
      foreach ($proveedores as $prov):
        $entrega_lbl = $entrega_labels[$prov['tipo_entrega']] ?? $prov['tipo_entrega'];
        $det_parts = [$entrega_lbl];
        if ($prov['telefono'])              $det_parts[] = '📞 ' . $prov['telefono'];
        if ($prov['dias_entrega_promedio']) $det_parts[] = $prov['dias_entrega_promedio'] . ' días entrega';
        if ($prov['dias_visita'])           $det_parts[] = 'Visitas: ' . $prov['dias_visita'];
      ?>
      <div class="pcard"
           data-id="<?= $prov['id_proveedor'] ?>"
           data-nombre="<?= htmlspecialchars($prov['nombre']) ?>"
           data-search="<?= strtolower($prov['nombre']) ?>"
           onclick="seleccionarProveedor(this)">
        <div class="pcard-ico"><i class="bi bi-truck"></i></div>
        <div class="pcard-info">
          <div class="pcard-name"><?= htmlspecialchars($prov['nombre']) ?></div>
          <div class="pcard-det"><?= implode(' · ', $det_parts) ?></div>
        </div>
        <div class="pcard-check"><i class="bi bi-check2"></i></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="modal-empty" id="sin-proveedores" style="display:none">
      <i class="bi bi-search"></i>Sin resultados para tu búsqueda
    </div>
    <div class="modal-foot">
      <button class="btn-modal-cancel" onclick="cerrarModal('modal-proveedores')">Cancelar</button>
      <button class="btn-modal-ok" id="btn-ok-prov" onclick="confirmarProveedor()" disabled>
        <i class="bi bi-check2-circle"></i> Confirmar
      </button>
    </div>
  </div>
</div>




<script>
// ══ Estado temporal de selección ══
let tmpInsumo    = null; // {id, nombre, unidad}
let tmpProveedor = null; // {id, nombre}

// ── Abrir / cerrar modales ─────────────────────────────────────
function abrirModal(cual) {
  document.getElementById('modal-' + cual).classList.add('open');
  document.body.style.overflow = 'hidden';
  const input = cual === 'insumos' ? 'busca-insumo' : 'busca-prov';
  setTimeout(() => document.getElementById(input)?.focus(), 80);
}
function cerrarModal(id) {
  document.getElementById(id).classList.remove('open');
  document.body.style.overflow = '';
  tmpInsumo    = cual === 'modal-insumos'     ? null : tmpInsumo;
  tmpProveedor = cual === 'modal-proveedores' ? null : tmpProveedor;
}
function cerrarAlClick(e, id) {
  if (e.target === e.currentTarget) {
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow = '';
  }
}

// ── Cerrar con Escape ──────────────────────────────────────────
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open')
      .forEach(m => { m.classList.remove('open'); document.body.style.overflow = ''; });
  }
});

// ══ INSUMOS ══════════════════════════════════════════════════
function filtrarInsumos() {
  const q    = document.getElementById('busca-insumo').value.toLowerCase().trim();
  const cards = document.querySelectorAll('#grid-insumos .mcard');
  let vis = 0;
  cards.forEach(c => {
    const match = c.dataset.search.includes(q);
    c.style.display = match ? '' : 'none';
    if (match) vis++;
  });
  document.getElementById('sin-insumos').style.display = vis === 0 ? 'block' : 'none';
}

function seleccionarInsumo(card) {
  document.querySelectorAll('#grid-insumos .mcard').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  tmpInsumo = { id: card.dataset.id, nombre: card.dataset.nombre, unidad: card.dataset.unidad };
  document.getElementById('btn-ok-insumo').disabled = false;
}

function confirmarInsumo() {
  if (!tmpInsumo) return;
  document.getElementById('inp-id-insumo').value = tmpInsumo.id;
  const picker = document.getElementById('picker-insumo');
  picker.querySelector('span').textContent = tmpInsumo.nombre;
  picker.querySelector('span').style.color = '';
  picker.querySelector('i').className = 'bi bi-check-circle-fill';
  picker.classList.add('filled');
  // Set unit label (hidden) and visible tag
  document.getElementById('lbl-unidad').textContent = tmpInsumo.unidad;
  const tagEl = document.getElementById('tag-unidad');
  if (tagEl) { tagEl.textContent = tmpInsumo.unidad; tagEl.style.display = ''; }
  recalcular();
  cerrarModal('modal-insumos');
  document.getElementById('modal-insumos').classList.remove('open');
  document.body.style.overflow = '';
  actualizarTotal();
}

// ══ PROVEEDORES ═══════════════════════════════════════════════
function filtrarProveedores() {
  const q     = document.getElementById('busca-prov').value.toLowerCase().trim();
  const cards = document.querySelectorAll('#grid-proveedores .pcard');
  let vis = 0;
  cards.forEach(c => {
    const match = c.dataset.search.includes(q);
    c.style.display = match ? '' : 'none';
    if (match) vis++;
  });
  document.getElementById('sin-proveedores').style.display = vis === 0 ? 'block' : 'none';
}

function seleccionarProveedor(card) {
  document.querySelectorAll('#grid-proveedores .pcard').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  tmpProveedor = { id: card.dataset.id, nombre: card.dataset.nombre };
  document.getElementById('btn-ok-prov').disabled = false;
}

function confirmarProveedor() {
  if (!tmpProveedor) return;
  document.getElementById('inp-id-proveedor').value = tmpProveedor.id;
  const picker = document.getElementById('picker-prov');
  picker.querySelector('span').textContent = tmpProveedor.nombre;
  picker.querySelector('span').style.color = '';
  picker.querySelector('i').className = 'bi bi-check-circle-fill';
  picker.classList.add('filled');
  document.getElementById('modal-proveedores').classList.remove('open');
  document.body.style.overflow = '';
}

// ══ Recalcular en tiempo real ══════════════════════════════════
const UNIDADES_KG = ['kg','g','L','ml','unidad'];
const UK = ['kg'];
const UG = ['g'];
const UL = ['l','L'];
const UML = ['ml'];

function fmt(n)    { return '$' + Math.round(n).toLocaleString('es-CO'); }
function fmtD(n)   { return '$' + n.toLocaleString('es-CO',{minimumFractionDigits:2,maximumFractionDigits:4}); }
function fmtN(n,u) { return n.toLocaleString('es-CO',{maximumFractionDigits:3}) + ' ' + u; }

function recalcular() {
  const numBolsas   = Math.max(1, parseInt(document.getElementById('vis-bultos')?.value)     || 1);
  const cantBolsa   = parseFloat(document.getElementById('vis-cant-bolsa')?.value)           || 0;
  const precioBolsa = parseFloat(document.getElementById('inp-precio')?.value)               || 0;
  const unidad      = document.getElementById('lbl-unidad')?.textContent.trim().toLowerCase() || '';

  const totalCant   = cantBolsa * numBolsas;
  const totalPagar  = precioBolsa * numBolsas;

  // Badge cantidad
  const badgeCant = document.getElementById('badge-cant');
  const badgeVal  = document.getElementById('badge-cant-val');
  if (cantBolsa > 0) {
    badgeVal.textContent = fmtN(totalCant, unidad);
    badgeCant.style.display = 'flex';
  } else {
    badgeCant.style.display = 'none';
  }

  // Resumen precio
  const resumen = document.getElementById('compra-resumen');
  if (cantBolsa > 0 && precioBolsa > 0) {
    resumen.style.display = 'flex';
    const precioXunit = totalCant > 0 ? precioBolsa / cantBolsa : 0;

    // Precio por unidad principal
    document.getElementById('cr-lbl-unit').textContent = 'Precio por ' + (unidad || 'unidad');
    document.getElementById('cr-val-unit').textContent = fmtD(precioXunit) + (unidad ? ' / ' + unidad : '');

    // Fila gramo (solo si kg o g)
    const rowGramo = document.getElementById('cr-row-gramo');
    if (UK.includes(unidad)) {
      document.getElementById('cr-lbl-gramo').textContent = 'Precio por gramo';
      document.getElementById('cr-val-gramo').textContent = fmtD(precioXunit / 1000) + ' / g';
      rowGramo.style.display = 'flex';
    } else if (UG.includes(unidad)) {
      document.getElementById('cr-lbl-gramo').textContent = 'Precio por kg (ref.)';
      document.getElementById('cr-val-gramo').textContent = fmtD(precioXunit * 1000) + ' / kg';
      rowGramo.style.display = 'flex';
    } else {
      rowGramo.style.display = 'none';
    }

    document.getElementById('cr-val-total').textContent = fmt(totalPagar);
  } else {
    resumen.style.display = 'none';
  }

  // Actualizar hiddens para PHP
  document.getElementById('inp-cantidad').value   = totalCant;
  document.getElementById('inp-num-bultos').value = numBolsas;
}

function prepararEnvio() {
  // Asegurar que los campos hidden estén al día antes de enviar
  recalcular();
}

// Escuchar todos los inputs del formulario
['vis-bultos','vis-cant-bolsa','inp-precio'].forEach(id => {
  document.getElementById(id)?.addEventListener('input', recalcular);
});
document.getElementById('inp-bultos')?.addEventListener('input', actualizarTotal);

// ══ Selección múltiple para etiquetas ════════════════════════
function actualizarSeleccion() {
  const checks = document.querySelectorAll('.row-chk:checked');
  const bar    = document.getElementById('sel-bar');
  const count  = document.getElementById('sel-count');
  const chkAll = document.getElementById('chk-all');
  const total  = document.querySelectorAll('.row-chk').length;

  count.textContent = checks.length;
  bar.classList.toggle('visible', checks.length > 0);
  chkAll.indeterminate = checks.length > 0 && checks.length < total;
  chkAll.checked = checks.length === total;
}

function toggleTodos(master) {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = master.checked);
  actualizarSeleccion();
}

function limpiarSeleccion() {
  document.querySelectorAll('.row-chk').forEach(c => c.checked = false);
  document.getElementById('chk-all').checked = false;
  actualizarSeleccion();
}

function imprimirSeleccionadas() {
  const ids = [...document.querySelectorAll('.row-chk:checked')].map(c => c.value);
  if (!ids.length) return;
  // Si es solo una, abrir directamente; si son varias, abrir con parámetro múltiple
  if (ids.length === 1) {
    window.open('etiqueta_lote.php?id_compra=' + ids[0], '_blank');
  } else {
    window.open('etiqueta_lote.php?ids=' + ids.join(','), '_blank');
  }
}
</script>

<?php require_once __DIR__ . '/../../views/layouts/footer.php'; ?>
