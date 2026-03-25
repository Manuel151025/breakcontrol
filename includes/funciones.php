<?php
// ============================================================
//  FUNCIONES AUXILIARES GENERALES
//  Archivo: includes/funciones.php
// ============================================================

// Formatear número como moneda colombiana
function formatoPeso(float $valor): string {
    return '$ ' . number_format($valor, 0, ',', '.');
}

// Formatear número con decimales
function formatoDecimal(float $valor, int $decimales = 2): string {
    return number_format($valor, $decimales, ',', '.');
}

// Formatear número eliminando ceros innecesarios (12.000 → 12, 2.500 → 2,5)
function formatoInteligente(float $valor): string {
    if ($valor == floor($valor)) {
        return number_format($valor, 0, ',', '.');
    }
    $texto = rtrim(number_format($valor, 3, ',', '.'), '0');
    return rtrim($texto, ',');
}

// Sanitizar entrada del usuario
function limpiar(string $dato): string {
    return htmlspecialchars(strip_tags(trim($dato)), ENT_QUOTES, 'UTF-8');
}

// Redirigir con mensaje en sesión
function redirigir(string $url, string $tipo = 'exito', string $mensaje = ''): void {
    if ($mensaje) {
        $_SESSION['mensaje_tipo']  = $tipo;   // 'exito', 'error', 'alerta'
        $_SESSION['mensaje_texto'] = $mensaje;
    }
    header("Location: $url");
    exit;
}

// Mostrar mensaje flash de sesión (llámalo en la vista)
function mostrarMensaje(): string {
    if (!isset($_SESSION['mensaje_texto'])) return '';

    $tipo    = $_SESSION['mensaje_tipo']  ?? 'exito';
    $mensaje = $_SESSION['mensaje_texto'] ?? '';
    unset($_SESSION['mensaje_tipo'], $_SESSION['mensaje_texto']);

    $clases = [
        'exito'  => 'alert-success',
        'error'  => 'alert-danger',
        'alerta' => 'alert-warning',
    ];
    $clase = $clases[$tipo] ?? 'alert-info';

    return "<div class='alert {$clase} alert-dismissible fade show' role='alert'>
                {$mensaje}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

// Verificar si hoy es domingo (no se generan órdenes de compra)
function esHoyDomingo(): bool {
    return date('w') === '0';
}

// Verificar si hoy es sábado
function esHoySabado(): bool {
    return date('w') === '6';
}

// Obtener configuración del sistema
function getConfiguracion(): array {
    static $config = null;
    if ($config === null) {
        $pdo    = getConexion();
        $stmt   = $pdo->query("SELECT * FROM configuracion LIMIT 1");
        $config = $stmt->fetch() ?? [];
    }
    return $config;
}

// Generar número de lote único
// Formato: INS-2026-02-25-001
function generarNumeroLote(string $prefijo): string {
    $fecha  = date('Y-m-d');
    $pdo    = getConexion();
    $stmt   = $pdo->prepare(
        "SELECT COUNT(*) FROM lote WHERE fecha_ingreso >= CURDATE()"
    );
    $stmt->execute();
    $count  = (int) $stmt->fetchColumn() + 1;
    return strtoupper(substr($prefijo, 0, 3)) . '-' . $fecha . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);
}

// Calcular porcentaje de variación entre dos precios
function calcularVariacion(float $precioAnterior, float $precioNuevo): float {
    if ($precioAnterior == 0) return 0;
    return round((($precioNuevo - $precioAnterior) / $precioAnterior) * 100, 2);
}

// ============================================================
//  STOCK DE PRODUCTO TERMINADO
//  El stock se calcula como:
//  total producido (todas las producciones) - total vendido (todas las ventas)
//  No existe columna stock_actual en la tabla producto; se computa en tiempo real.
// ============================================================

/**
 * Retorna las unidades disponibles HOY de un producto terminado.
 * Disponible = SUM(unidades_producidas hoy) - SUM(unidades_vendidas hoy)
 * El stock es diario: cada día se produce y se vende desde cero.
 */
function getStockProducto(int $id_producto): float {
    $pdo  = getConexion();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(unidades_producidas), 0)
         FROM produccion
         WHERE id_producto = ?
           AND DATE(fecha_produccion) = CURDATE()"
    );
    $stmt->execute([$id_producto]);
    $producido = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(unidades_vendidas), 0)
         FROM venta
         WHERE id_producto = ?
           AND DATE(fecha_hora) = CURDATE()"
    );
    $stmt->execute([$id_producto]);
    $vendido = (float) $stmt->fetchColumn();

    return max(0, $producido - $vendido);
}

/**
 * Valida si hay stock suficiente para registrar una venta.
 *
 * Retorna un array:
 *   ['ok' => true]  → hay suficiente stock
 *   ['ok' => false, 'mensaje' => '...', 'disponible' => N]  → no hay stock
 */
function validarStockVenta(int $id_producto, int $cantidad): array {
    $disponible = getStockProducto($id_producto);

    if ($cantidad <= 0) {
        return [
            'ok'         => false,
            'mensaje'    => 'La cantidad a vender debe ser mayor a 0.',
            'disponible' => $disponible,
        ];
    }

    if ($cantidad > $disponible) {
        $disp_fmt = number_format($disponible, 0, ',', '.');
        return [
            'ok'         => false,
            'mensaje'    => "No hay suficiente stock. Solicitaste {$cantidad} unidad(es), pero solo hay <strong>{$disp_fmt}</strong> disponible(s).",
            'disponible' => $disponible,
        ];
    }

    return ['ok' => true, 'disponible' => $disponible];
}