-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 25-03-2026 a las 01:12:49
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `panaderia_bd`
--

DELIMITER $$
--
-- Procedimientos
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_actualizar_consumo_promedio` (IN `p_id_insumo` INT)   BEGIN
  DECLARE v_promedio DECIMAL(12,4);

  SELECT COALESCE(SUM(cl.cantidad_con_merma) / 7, 0)
  INTO v_promedio
  FROM consumo_lote cl
  INNER JOIN produccion pr ON pr.id_produccion = cl.id_produccion
  WHERE cl.id_lote IN (SELECT id_lote FROM lote WHERE id_insumo = p_id_insumo)
    AND pr.fecha_produccion >= DATE_SUB(NOW(), INTERVAL 7 DAY);

  UPDATE insumo
  SET consumo_promedio_diario = v_promedio
  WHERE id_insumo = p_id_insumo;

END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_descontar_fifo` (IN `p_id_insumo` INT, IN `p_cantidad_total` DECIMAL(12,4), IN `p_id_produccion` INT)   BEGIN
  DECLARE v_lote_id         INT;
  DECLARE v_lote_disponible DECIMAL(12,4);
  DECLARE v_lote_precio     DECIMAL(12,4);
  DECLARE v_pendiente       DECIMAL(12,4);
  DECLARE v_a_descontar     DECIMAL(12,4);
  DECLARE v_fin             INT DEFAULT 0;

  DECLARE cur_lotes CURSOR FOR
    SELECT id_lote, cantidad_disponible, precio_unitario
    FROM lote
    WHERE id_insumo = p_id_insumo
      AND estado = 'activo'
      AND cantidad_disponible > 0
    ORDER BY fecha_ingreso ASC;

  DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_fin = 1;

  SET v_pendiente = p_cantidad_total;

  OPEN cur_lotes;

  loop_fifo: LOOP
    FETCH cur_lotes INTO v_lote_id, v_lote_disponible, v_lote_precio;

    IF v_fin = 1 OR v_pendiente <= 0 THEN
      LEAVE loop_fifo;
    END IF;

    IF v_lote_disponible >= v_pendiente THEN
      SET v_a_descontar = v_pendiente;
    ELSE
      SET v_a_descontar = v_lote_disponible;
    END IF;

    -- Registrar consumo del lote
    INSERT INTO consumo_lote (id_lote, id_produccion, cantidad_consumida, cantidad_con_merma, costo_consumo)
    VALUES (v_lote_id, p_id_produccion, v_a_descontar, v_a_descontar, ROUND(v_a_descontar * v_lote_precio, 2));

    -- Actualizar cantidad disponible del lote
    UPDATE lote
    SET cantidad_disponible = cantidad_disponible - v_a_descontar,
        estado = IF(cantidad_disponible - v_a_descontar <= 0, 'agotado', 'activo')
    WHERE id_lote = v_lote_id;

    SET v_pendiente = v_pendiente - v_a_descontar;

  END LOOP;

  CLOSE cur_lotes;

  -- Actualizar stock total del insumo
  UPDATE insumo
  SET stock_actual = (
    SELECT COALESCE(SUM(cantidad_disponible), 0)
    FROM lote
    WHERE id_insumo = p_id_insumo AND estado = 'activo'
  )
  WHERE id_insumo = p_id_insumo;

END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_revision_stock_diaria` (IN `p_id_usuario` INT)   BEGIN
  DECLARE v_dia_semana INT;
  SET v_dia_semana = DAYOFWEEK(CURDATE()); -- 1=Domingo, 7=Sábado

  -- Insertar alertas para insumos bajo punto de reposición
  INSERT INTO alerta (id_usuario, tipo, modulo_origen, mensaje)
  SELECT
    p_id_usuario,
    'stock_bajo',
    'inventario',
    CONCAT('Stock bajo: ', i.nombre, ' — quedan ', ROUND(i.stock_actual, 2), ' ', i.unidad_medida,
           ' (', COALESCE(ROUND(i.stock_actual / NULLIF(i.consumo_promedio_diario, 0), 1), '?'), ' días)')
  FROM insumo i
  WHERE i.activo = 1
    AND i.stock_actual <= i.punto_reposicion
    AND v_dia_semana != 1  -- No generar alertas los domingos
    -- No duplicar alertas activas del mismo día
    AND NOT EXISTS (
      SELECT 1 FROM alerta a
      WHERE a.tipo = 'stock_bajo'
        AND a.estado = 'activa'
        AND a.mensaje LIKE CONCAT('%', i.nombre, '%')
        AND DATE(a.fecha_generacion) = CURDATE()
    );

END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ajuste_inventario`
--

CREATE TABLE `ajuste_inventario` (
  `id_ajuste` int(11) NOT NULL,
  `id_insumo` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `cantidad_antes` decimal(12,3) NOT NULL,
  `cantidad_despues` decimal(12,3) NOT NULL,
  `diferencia` decimal(12,3) NOT NULL COMMENT 'Calculado: despues - antes',
  `motivo` varchar(255) NOT NULL,
  `fecha_ajuste` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de correcciones manuales de stock';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `alerta`
--

CREATE TABLE `alerta` (
  `id_alerta` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `tipo` enum('stock_bajo','margen_riesgo','precio_subio','caja_baja') NOT NULL,
  `modulo_origen` varchar(50) NOT NULL COMMENT 'Ej: inventario, finanzas, compras',
  `mensaje` text NOT NULL,
  `estado` enum('activa','atendida','archivada') NOT NULL DEFAULT 'activa',
  `fecha_generacion` datetime NOT NULL DEFAULT current_timestamp(),
  `fecha_atencion` datetime DEFAULT NULL,
  `accion_tomada` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Centro de alertas del sistema — todas las notificaciones';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cierre_dia`
--

CREATE TABLE `cierre_dia` (
  `id_cierre` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha` date NOT NULL,
  `total_ingresos` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_gastos` decimal(12,2) NOT NULL DEFAULT 0.00,
  `costo_produccion` decimal(12,2) NOT NULL DEFAULT 0.00,
  `utilidad_bruta` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'total_ingresos - costo_produccion',
  `utilidad_neta` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'utilidad_bruta - total_gastos',
  `sugerencia_produccion` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Resumen financiero de cada día de operación';

--
-- Volcado de datos para la tabla `cierre_dia`
--

INSERT INTO `cierre_dia` (`id_cierre`, `id_usuario`, `fecha`, `total_ingresos`, `total_gastos`, `costo_produccion`, `utilidad_bruta`, `utilidad_neta`, `sugerencia_produccion`) VALUES
(1, 1, '2026-03-24', 43500.00, 100000.00, 31144.32, 12355.68, -87644.32, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cliente`
--

CREATE TABLE `cliente` (
  `id_cliente` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('tienda','mostrador') NOT NULL DEFAULT 'tienda',
  `telefono` varchar(20) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Clientes fijos (tiendas) y mostrador';

--
-- Volcado de datos para la tabla `cliente`
--

INSERT INTO `cliente` (`id_cliente`, `nombre`, `tipo`, `telefono`, `activo`, `fecha_creacion`) VALUES
(1, 'Tienda Sebastopol', 'tienda', '3209891830', 1, '2026-03-23 19:41:21');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compra`
--

CREATE TABLE `compra` (
  `id_compra` int(11) NOT NULL,
  `id_insumo` int(11) NOT NULL,
  `id_proveedor` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `cantidad` decimal(12,3) NOT NULL,
  `precio_unitario` decimal(12,4) NOT NULL,
  `total_pagado` decimal(12,2) NOT NULL COMMENT 'cantidad * precio_unitario',
  `fecha_compra` datetime NOT NULL DEFAULT current_timestamp(),
  `variacion_precio_pct` decimal(7,2) NOT NULL DEFAULT 0.00 COMMENT '% de variación respecto a la compra anterior'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro histórico de todas las compras de insumos';

--
-- Volcado de datos para la tabla `compra`
--

INSERT INTO `compra` (`id_compra`, `id_insumo`, `id_proveedor`, `id_usuario`, `cantidad`, `precio_unitario`, `total_pagado`, `fecha_compra`, `variacion_precio_pct`) VALUES
(1, 8, 1, 1, 5000.000, 9.0000, 45000.00, '2026-03-23 00:00:00', 0.00),
(2, 10, 1, 1, 3000.000, 13.0000, 39000.00, '2026-03-23 00:00:00', 0.00),
(3, 19, 2, 1, 2500.000, 9.2000, 23000.00, '2026-03-24 00:00:00', 0.00),
(4, 17, 3, 1, 1200.000, 21.6667, 26000.00, '2026-03-24 00:00:00', 0.00),
(5, 14, 3, 1, 1200.000, 21.6667, 26000.00, '2026-03-24 00:00:00', 0.00),
(6, 16, 3, 1, 1200.000, 21.6667, 26000.00, '2026-03-24 00:00:00', 0.00),
(7, 15, 3, 1, 1200.000, 21.6667, 26000.00, '2026-03-24 00:00:00', 0.00),
(8, 6, 1, 1, 12500.000, 3.6000, 45000.00, '2026-03-24 00:00:00', 0.00),
(9, 1, 2, 1, 50.000, 2600.0000, 130000.00, '2026-03-24 00:00:00', 0.00),
(10, 3, 2, 1, 1000.000, 2.5000, 2500.00, '2026-03-24 00:00:00', 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

CREATE TABLE `configuracion` (
  `id_config` int(11) NOT NULL,
  `margen_minimo_pct` decimal(5,2) NOT NULL DEFAULT 30.00 COMMENT 'Porcentaje mínimo de ganancia aceptable',
  `dias_stock_seguridad` int(11) NOT NULL DEFAULT 3 COMMENT 'Días mínimos de stock antes de alertar',
  `pct_merma_harina` decimal(5,2) NOT NULL DEFAULT 6.00 COMMENT 'Porcentaje de merma aplicado a la harina',
  `alerta_variacion_precio` decimal(5,2) NOT NULL DEFAULT 5.00 COMMENT 'Variación % de precio que dispara alerta',
  `base_minima_caja` decimal(12,2) NOT NULL DEFAULT 0.00 COMMENT 'Saldo mínimo de caja recomendado',
  `fecha_actualizacion` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Parámetros globales del negocio — solo una fila';

--
-- Volcado de datos para la tabla `configuracion`
--

INSERT INTO `configuracion` (`id_config`, `margen_minimo_pct`, `dias_stock_seguridad`, `pct_merma_harina`, `alerta_variacion_precio`, `base_minima_caja`, `fecha_actualizacion`) VALUES
(1, 30.00, 3, 6.00, 5.00, 0.00, '2026-02-25 22:06:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `consumo_lote`
--

CREATE TABLE `consumo_lote` (
  `id_consumo` int(11) NOT NULL,
  `id_lote` int(11) NOT NULL,
  `id_produccion` int(11) NOT NULL,
  `cantidad_consumida` decimal(12,4) NOT NULL COMMENT 'Cantidad real sin merma',
  `cantidad_con_merma` decimal(12,4) NOT NULL COMMENT 'Cantidad descontada incluyendo merma',
  `costo_consumo` decimal(12,2) NOT NULL COMMENT 'cantidad_con_merma * precio_unitario_lote',
  `fecha_consumo` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Trazabilidad FIFO: lotes consumidos por producción';

--
-- Volcado de datos para la tabla `consumo_lote`
--

INSERT INTO `consumo_lote` (`id_consumo`, `id_lote`, `id_produccion`, `cantidad_consumida`, `cantidad_con_merma`, `costo_consumo`, `fecha_consumo`) VALUES
(1, 2, 1, 900.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(2, 7, 1, 80.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(3, 8, 1, 30.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(4, 6, 1, 120.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(5, 1, 1, 7.5000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(6, 5, 1, 20.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(7, 4, 1, 1900.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(8, 3, 1, 150.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(9, 10, 1, 80.0000, 0.0000, 0.00, '2026-03-21 05:59:03'),
(10, 2, 2, 900.0000, 900.0000, 0.00, '2026-03-23 19:40:35'),
(11, 7, 2, 80.0000, 80.0000, 0.00, '2026-03-23 19:40:35'),
(12, 8, 2, 30.0000, 30.0000, 0.00, '2026-03-23 19:40:35'),
(13, 6, 2, 120.0000, 120.0000, 0.00, '2026-03-23 19:40:35'),
(14, 1, 2, 7.5000, 7.5000, 0.00, '2026-03-23 19:40:35'),
(15, 5, 2, 20.0000, 20.0000, 0.00, '2026-03-23 19:40:35'),
(16, 11, 2, 80.0000, 80.0000, 720.00, '2026-03-23 19:40:35'),
(17, 10, 2, 80.0000, 80.0000, 0.00, '2026-03-23 19:40:35'),
(18, 4, 2, 1900.0000, 1900.0000, 0.00, '2026-03-23 19:40:35'),
(19, 3, 2, 150.0000, 150.0000, 0.00, '2026-03-23 19:40:35'),
(20, 9, 2, 122.0000, 122.0000, 0.00, '2026-03-23 19:40:35'),
(21, 12, 2, 120.0000, 120.0000, 1560.00, '2026-03-23 19:40:35'),
(22, 2, 3, 950.0000, 950.0000, 0.00, '2026-03-23 20:10:57'),
(23, 7, 3, 40.0000, 40.0000, 0.00, '2026-03-23 20:10:57'),
(24, 8, 3, 30.0000, 30.0000, 0.00, '2026-03-23 20:10:57'),
(25, 6, 3, 250.0000, 250.0000, 0.00, '2026-03-23 20:10:57'),
(26, 1, 3, 8.0000, 8.0000, 0.00, '2026-03-23 20:10:57'),
(27, 5, 3, 20.0000, 20.0000, 0.00, '2026-03-23 20:10:57'),
(28, 11, 3, 150.0000, 150.0000, 1350.00, '2026-03-23 20:10:57'),
(29, 10, 3, 130.0000, 130.0000, 0.00, '2026-03-23 20:10:57'),
(30, 4, 3, 1900.0000, 1900.0000, 0.00, '2026-03-23 20:10:57'),
(31, 3, 3, 160.0000, 160.0000, 0.00, '2026-03-23 20:10:57'),
(32, 2, 4, 624.0000, 624.0000, 0.00, '2026-03-24 12:50:05'),
(33, 1, 4, 5.0000, 5.0000, 0.00, '2026-03-24 12:50:05'),
(34, 10, 4, 70.0000, 70.0000, 0.00, '2026-03-24 12:50:05'),
(35, 4, 4, 750.0000, 750.0000, 0.00, '2026-03-24 12:50:05'),
(36, 3, 4, 150.0000, 150.0000, 0.00, '2026-03-24 12:50:05'),
(37, 2, 5, 900.0000, 900.0000, 0.00, '2026-03-24 12:50:57'),
(38, 8, 5, 30.0000, 30.0000, 0.00, '2026-03-24 12:50:57'),
(39, 6, 5, 120.0000, 120.0000, 0.00, '2026-03-24 12:50:57'),
(40, 1, 5, 7.5000, 7.5000, 0.00, '2026-03-24 12:50:57'),
(41, 11, 5, 80.0000, 80.0000, 720.00, '2026-03-24 12:50:57'),
(42, 10, 5, 80.0000, 80.0000, 0.00, '2026-03-24 12:50:57'),
(43, 4, 5, 1900.0000, 1900.0000, 0.00, '2026-03-24 12:50:57'),
(44, 3, 5, 150.0000, 150.0000, 0.00, '2026-03-24 12:50:57'),
(45, 9, 5, 122.0000, 122.0000, 0.00, '2026-03-24 12:50:57'),
(46, 12, 5, 120.0000, 120.0000, 1560.00, '2026-03-24 12:50:57'),
(47, 2, 6, 350.0000, 350.0000, 0.00, '2026-03-24 12:55:53'),
(48, 6, 6, 120.0000, 120.0000, 0.00, '2026-03-24 12:55:53'),
(49, 1, 6, 1.5000, 1.5000, 0.00, '2026-03-24 12:55:53'),
(50, 4, 6, 350.0000, 350.0000, 0.00, '2026-03-24 12:55:53'),
(51, 13, 6, 80.0000, 80.0000, 736.00, '2026-03-24 12:55:53'),
(52, 14, 6, 14.0000, 14.0000, 303.33, '2026-03-24 12:55:53'),
(53, 2, 7, 376.0000, 376.0000, 0.00, '2026-03-24 12:56:00'),
(54, 8, 7, 30.0000, 30.0000, 0.00, '2026-03-24 12:56:00'),
(55, 6, 7, 120.0000, 120.0000, 0.00, '2026-03-24 12:56:00'),
(56, 1, 7, 7.5000, 7.5000, 0.00, '2026-03-24 12:56:00'),
(57, 11, 7, 80.0000, 80.0000, 720.00, '2026-03-24 12:56:00'),
(58, 10, 7, 80.0000, 80.0000, 0.00, '2026-03-24 12:56:00'),
(59, 4, 7, 1900.0000, 1900.0000, 0.00, '2026-03-24 12:56:00'),
(60, 3, 7, 150.0000, 150.0000, 0.00, '2026-03-24 12:56:00'),
(61, 9, 7, 122.0000, 122.0000, 0.00, '2026-03-24 12:56:00'),
(62, 12, 7, 120.0000, 120.0000, 1560.00, '2026-03-24 12:56:00'),
(63, 1, 8, 4.0000, 4.0000, 0.00, '2026-03-24 12:57:14'),
(64, 10, 8, 50.0000, 50.0000, 0.00, '2026-03-24 12:57:14'),
(65, 4, 8, 800.0000, 800.0000, 0.00, '2026-03-24 12:57:14'),
(66, 16, 8, 14.0000, 14.0000, 303.33, '2026-03-24 12:57:14'),
(67, 15, 8, 14.0000, 14.0000, 303.33, '2026-03-24 12:57:14'),
(68, 17, 8, 14.0000, 14.0000, 303.33, '2026-03-24 12:57:14'),
(69, 8, 9, 30.0000, 30.0000, 0.00, '2026-03-24 12:59:34'),
(70, 6, 9, 150.0000, 150.0000, 0.00, '2026-03-24 12:59:34'),
(71, 18, 9, 100.0000, 100.0000, 360.00, '2026-03-24 12:59:34'),
(72, 1, 9, 1.5000, 1.5000, 0.00, '2026-03-24 12:59:34'),
(73, 19, 9, 6.5000, 6.5000, 16900.00, '2026-03-24 12:59:34'),
(74, 11, 9, 150.0000, 150.0000, 1350.00, '2026-03-24 12:59:34'),
(75, 10, 9, 130.0000, 130.0000, 0.00, '2026-03-24 12:59:34'),
(76, 4, 9, 1900.0000, 1900.0000, 0.00, '2026-03-24 12:59:34'),
(77, 3, 9, 90.0000, 90.0000, 0.00, '2026-03-24 12:59:34'),
(78, 20, 9, 70.0000, 70.0000, 175.00, '2026-03-24 12:59:34'),
(79, 9, 9, 850.0000, 850.0000, 0.00, '2026-03-24 12:59:34'),
(80, 12, 9, 450.0000, 450.0000, 5850.00, '2026-03-24 12:59:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `gasto`
--

CREATE TABLE `gasto` (
  `id_gasto` int(11) NOT NULL,
  `id_cierre_dia` int(11) DEFAULT NULL,
  `id_usuario` int(11) NOT NULL,
  `categoria` enum('compra','servicio','otro') NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `valor` decimal(12,2) NOT NULL,
  `fecha_gasto` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de todos los gastos del negocio por día';

--
-- Volcado de datos para la tabla `gasto`
--

INSERT INTO `gasto` (`id_gasto`, `id_cierre_dia`, `id_usuario`, `categoria`, `descripcion`, `valor`, `fecha_gasto`) VALUES
(1, NULL, 1, 'otro', 'Gasolina', 25000.00, '2026-03-24 13:01:04'),
(2, NULL, 1, 'compra', 'Aseo', 15000.00, '2026-03-24 13:01:38'),
(3, NULL, 1, 'servicio', 'Recibo Agua', 60000.00, '2026-03-24 13:02:24');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historial_precio`
--

CREATE TABLE `historial_precio` (
  `id_historial` int(11) NOT NULL,
  `id_insumo` int(11) NOT NULL,
  `id_proveedor` int(11) NOT NULL,
  `id_compra` int(11) NOT NULL,
  `precio` decimal(12,4) NOT NULL,
  `variacion_pct` decimal(7,2) NOT NULL DEFAULT 0.00,
  `fecha_registro` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Historial de precios para análisis de variación';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `insumo`
--

CREATE TABLE `insumo` (
  `id_insumo` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `unidad_medida` enum('kg','g','L','ml','unidad') NOT NULL,
  `es_harina` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = se aplica merma del 6%',
  `stock_actual` decimal(12,3) NOT NULL DEFAULT 0.000,
  `punto_reposicion` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'Stock mínimo antes de generar alerta',
  `consumo_promedio_diario` decimal(12,3) NOT NULL DEFAULT 0.000 COMMENT 'Se actualiza automáticamente',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Materias primas e insumos de la panadería';

--
-- Volcado de datos para la tabla `insumo`
--

INSERT INTO `insumo` (`id_insumo`, `nombre`, `unidad_medida`, `es_harina`, `stock_actual`, `punto_reposicion`, `consumo_promedio_diario`, `activo`, `fecha_creacion`) VALUES
(1, 'Harina de trigo', 'kg', 1, 40.500, 10.000, 0.000, 1, '2026-03-20 22:11:46'),
(2, 'Azúcar', 'g', 0, 6100.000, 1000.000, 0.000, 1, '2026-03-20 22:12:36'),
(3, 'Sal', 'g', 0, 930.000, 1000.000, 0.000, 1, '2026-03-20 22:12:59'),
(4, 'Mantequilla', 'g', 0, 1700.000, 5.000, 0.000, 1, '2026-03-20 22:13:19'),
(5, 'Huevos', 'unidad', 0, 5.000, 45.000, 0.000, 1, '2026-03-20 22:14:07'),
(6, 'Fecula de Maiz', 'g', 0, 12400.000, 2.500, 0.000, 1, '2026-03-20 22:14:26'),
(7, 'Esencia Mantequilla', 'ml', 0, 500.000, 1500.000, 0.000, 1, '2026-03-20 22:14:51'),
(8, 'Leche en polvo', 'g', 0, 5460.000, 2000.000, 0.000, 1, '2026-03-20 22:14:59'),
(9, 'Esencia Vainilla Caramelo', 'ml', 0, 380.000, 500.000, 0.000, 1, '2026-03-20 22:15:31'),
(10, 'Manjar Blanco', 'g', 0, 2190.000, 0.000, 0.000, 1, '2026-03-20 22:15:57'),
(11, 'Quesillo', 'g', 0, 784.000, 2.000, 0.000, 1, '2026-03-20 22:16:25'),
(12, 'Polvo de Hornear', 'g', 0, 988.000, 500.000, 0.000, 1, '2026-03-20 22:16:47'),
(13, 'Mantequilla de Vaca', 'g', 0, 500.000, 1.000, 0.000, 1, '2026-03-20 22:17:09'),
(14, 'Esencia Arequipe', 'ml', 0, 1186.000, 500.000, 0.000, 1, '2026-03-20 22:17:23'),
(15, 'Esencia Piña', 'ml', 0, 1186.000, 500.000, 0.000, 1, '2026-03-20 22:17:38'),
(16, 'Esencia Banano', 'ml', 0, 1186.000, 500.000, 0.000, 1, '2026-03-20 22:17:45'),
(17, 'Esencia Coco', 'ml', 0, 1186.000, 500.000, 0.000, 1, '2026-03-20 22:17:51'),
(18, 'Levadura', 'g', 0, 800.000, 500.000, 0.000, 1, '2026-03-20 22:25:09'),
(19, 'Coco', 'g', 0, 2420.000, 1000.000, 0.000, 1, '2026-03-20 22:28:00'),
(21, 'Hojaldre', 'g', 0, 500.000, 2500.000, 0.000, 1, '2026-03-23 11:19:18');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `lote`
--

CREATE TABLE `lote` (
  `id_lote` int(11) NOT NULL,
  `id_insumo` int(11) NOT NULL,
  `id_compra` int(11) DEFAULT NULL COMMENT 'NULL si es lote de apertura inicial',
  `numero_lote` varchar(30) NOT NULL COMMENT 'Ej: HAR-2026-02-25-001',
  `cantidad_inicial` decimal(12,3) NOT NULL,
  `cantidad_disponible` decimal(12,3) NOT NULL,
  `precio_unitario` decimal(12,4) NOT NULL COMMENT 'Precio por unidad de medida al momento de la compra',
  `fecha_ingreso` datetime NOT NULL DEFAULT current_timestamp(),
  `estado` enum('activo','agotado') NOT NULL DEFAULT 'activo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Lotes de insumos ordenados por fecha para FIFO';

--
-- Volcado de datos para la tabla `lote`
--

INSERT INTO `lote` (`id_lote`, `id_insumo`, `id_compra`, `numero_lote`, `cantidad_inicial`, `cantidad_disponible`, `precio_unitario`, `fecha_ingreso`, `estado`) VALUES
(1, 1, NULL, 'INI-2026-03-21-001', 50.000, 0.000, 0.0000, '2026-03-21 05:58:27', 'agotado'),
(2, 2, NULL, 'INI-2026-03-21-002', 5000.000, 0.000, 0.0000, '2026-03-21 05:58:27', 'agotado'),
(3, 3, NULL, 'INI-2026-03-21-003', 1000.000, 0.000, 0.0000, '2026-03-21 05:58:27', 'agotado'),
(4, 4, NULL, 'INI-2026-03-21-004', 15000.000, 1700.000, 0.0000, '2026-03-21 05:58:27', 'activo'),
(5, 5, NULL, 'INI-2026-03-21-005', 60.000, 0.000, 0.0000, '2026-03-21 05:58:27', 'agotado'),
(6, 6, NULL, 'INI-2026-03-21-006', 1000.000, 0.000, 0.0000, '2026-03-21 05:58:27', 'agotado'),
(7, 7, NULL, 'INI-2026-03-21-007', 200.000, 0.000, 0.0000, '2026-03-21 05:58:27', 'agotado'),
(8, 9, NULL, 'INI-2026-03-21-008', 300.000, 120.000, 0.0000, '2026-03-21 05:58:27', 'activo'),
(9, 11, NULL, 'INI-2026-03-21-009', 2000.000, 784.000, 0.0000, '2026-03-21 05:58:27', 'activo'),
(10, 18, NULL, 'INI-2026-03-21-010', 1500.000, 800.000, 0.0000, '2026-03-21 05:58:27', 'activo'),
(11, 8, 1, 'LEC-2026-03-23-001', 5000.000, 4460.000, 9.0000, '2026-03-23 00:00:00', 'activo'),
(12, 10, 2, 'MAN-2026-03-23-002', 3000.000, 2190.000, 13.0000, '2026-03-23 00:00:00', 'activo'),
(13, 19, 3, 'COC-2026-03-24-001', 2500.000, 2420.000, 9.2000, '2026-03-24 00:00:00', 'activo'),
(14, 17, 4, 'ESE-2026-03-24-002', 1200.000, 1186.000, 21.6667, '2026-03-24 00:00:00', 'activo'),
(15, 14, 5, 'ESE-2026-03-24-003', 1200.000, 1186.000, 21.6667, '2026-03-24 00:00:00', 'activo'),
(16, 16, 6, 'ESE-2026-03-24-004', 1200.000, 1186.000, 21.6667, '2026-03-24 00:00:00', 'activo'),
(17, 15, 7, 'ESE-2026-03-24-005', 1200.000, 1186.000, 21.6667, '2026-03-24 00:00:00', 'activo'),
(18, 6, 8, 'FEC-2026-03-24-006', 12500.000, 12400.000, 3.6000, '2026-03-24 00:00:00', 'activo'),
(19, 1, 9, 'HAR-2026-03-24-007', 50.000, 40.500, 2600.0000, '2026-03-24 00:00:00', 'activo'),
(20, 3, 10, 'SAL-2026-03-24-008', 1000.000, 930.000, 2.5000, '2026-03-24 00:00:00', 'activo');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `produccion`
--

CREATE TABLE `produccion` (
  `id_produccion` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `id_receta` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `cantidad_tandas` decimal(5,1) NOT NULL DEFAULT 1.0,
  `observaciones` varchar(255) DEFAULT NULL,
  `unidades_producidas` int(11) NOT NULL,
  `costo_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `costo_unitario` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `fecha_produccion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de cada corrida de producción';

--
-- Volcado de datos para la tabla `produccion`
--

INSERT INTO `produccion` (`id_produccion`, `id_producto`, `id_receta`, `id_usuario`, `cantidad_tandas`, `observaciones`, `unidades_producidas`, `costo_total`, `costo_unitario`, `fecha_produccion`) VALUES
(1, 1, 1, 1, 1.0, '⚠ Registrado con stock insuficiente', 350, 0.00, 0.0000, '2026-03-21 05:59:03'),
(2, 1, 1, 1, 1.0, '', 350, 2280.00, 6.5143, '2026-03-23 19:40:35'),
(3, 2, 2, 1, 1.0, '', 82, 1350.00, 16.4634, '2026-03-23 20:10:57'),
(4, 3, 3, 1, 1.0, '', 288, 0.00, 0.0000, '2026-03-24 12:50:05'),
(5, 1, 1, 1, 1.0, '', 350, 2280.00, 6.5143, '2026-03-24 12:50:57'),
(6, 5, 5, 1, 1.0, '', 84, 1039.33, 12.3730, '2026-03-24 12:55:53'),
(7, 1, 1, 1, 1.0, '', 350, 2280.00, 6.5143, '2026-03-24 12:56:00'),
(8, 4, 4, 1, 1.0, '', 182, 909.99, 4.9999, '2026-03-24 12:57:14'),
(9, 2, 2, 1, 1.0, '', 82, 24635.00, 300.4268, '2026-03-24 12:59:34');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `producto`
--

CREATE TABLE `producto` (
  `id_producto` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `categoria` enum('sal','dulce','especial') NOT NULL DEFAULT 'sal',
  `precio_venta` decimal(12,2) NOT NULL DEFAULT 0.00,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `unidad_produccion` varchar(20) NOT NULL DEFAULT 'carro',
  `cantidad_por_tanda` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Productos terminados que se venden en la panadería';

--
-- Volcado de datos para la tabla `producto`
--

INSERT INTO `producto` (`id_producto`, `nombre`, `categoria`, `precio_venta`, `activo`, `fecha_creacion`, `unidad_produccion`, `cantidad_por_tanda`) VALUES
(1, 'Pan de Sal', 'sal', 500.00, 1, '2026-03-20 22:18:55', 'unidad', 350.00),
(2, 'Pan Grande', 'sal', 0.00, 1, '2026-03-20 22:21:38', 'unidad', 82.00),
(3, 'Croissant', 'especial', 500.00, 1, '2026-03-20 22:23:57', 'unidad', 288.00),
(4, 'Pan Dulce', 'dulce', 500.00, 1, '2026-03-20 22:26:18', 'unidad', 182.00),
(5, 'Pan Coco', 'especial', 500.00, 1, '2026-03-20 22:27:04', 'unidad', 84.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proveedor`
--

CREATE TABLE `proveedor` (
  `id_proveedor` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `tipo_entrega` enum('domicilio','recogida','visita') NOT NULL DEFAULT 'domicilio',
  `dias_visita` varchar(100) DEFAULT NULL,
  `dias_entrega_promedio` decimal(4,1) NOT NULL DEFAULT 1.0,
  `activo` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Proveedores de materias primas';

--
-- Volcado de datos para la tabla `proveedor`
--

INSERT INTO `proveedor` (`id_proveedor`, `nombre`, `telefono`, `tipo_entrega`, `dias_visita`, `dias_entrega_promedio`, `activo`) VALUES
(1, 'La Queserita', '3051236598', 'domicilio', NULL, 0.5, 1),
(2, 'Otros', '', 'recogida', NULL, 0.0, 1),
(3, 'Levapan', '3216549871', 'visita', 'miercoles,sabado', 0.0, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proyeccion_caja`
--

CREATE TABLE `proyeccion_caja` (
  `id_proyeccion` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL,
  `fecha_generacion` datetime NOT NULL DEFAULT current_timestamp(),
  `semana_proyectada` date NOT NULL COMMENT 'Lunes de la semana proyectada',
  `ingreso_proyectado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gasto_proyectado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `saldo_proyectado` decimal(12,2) NOT NULL DEFAULT 0.00,
  `alerta_caja_baja` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Proyecciones semanales de flujo de caja';

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `receta`
--

CREATE TABLE `receta` (
  `id_receta` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `id_usuario` int(11) NOT NULL COMMENT 'Propietario que la creó',
  `version` int(11) NOT NULL DEFAULT 1,
  `es_vigente` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Solo una receta vigente por producto',
  `es_ajuste_temporal` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_ajuste_temporal` date DEFAULT NULL COMMENT 'Fecha en que aplica el ajuste temporal',
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp(),
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Versiones de recetas — permite historial y ajustes temporales';

--
-- Volcado de datos para la tabla `receta`
--

INSERT INTO `receta` (`id_receta`, `id_producto`, `id_usuario`, `version`, `es_vigente`, `es_ajuste_temporal`, `fecha_ajuste_temporal`, `fecha_creacion`, `descripcion`) VALUES
(1, 1, 1, 1, 1, 0, NULL, '2026-03-20 22:19:11', NULL),
(2, 2, 1, 1, 1, 0, NULL, '2026-03-20 22:23:17', NULL),
(3, 3, 1, 1, 1, 0, NULL, '2026-03-20 22:24:03', NULL),
(4, 4, 1, 1, 1, 0, NULL, '2026-03-20 22:26:47', NULL),
(5, 5, 1, 1, 1, 0, NULL, '2026-03-20 22:27:42', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `receta_ingrediente`
--

CREATE TABLE `receta_ingrediente` (
  `id_receta_ing` int(11) NOT NULL,
  `id_receta` int(11) NOT NULL,
  `id_insumo` int(11) NOT NULL,
  `cantidad` decimal(12,4) NOT NULL COMMENT 'Cantidad por unidad de producto',
  `unidad` varchar(20) NOT NULL,
  `aplica_merma` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = se suma el % de merma al descontar',
  `notas` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ingredientes y cantidades de cada versión de receta';

--
-- Volcado de datos para la tabla `receta_ingrediente`
--

INSERT INTO `receta_ingrediente` (`id_receta_ing`, `id_receta`, `id_insumo`, `cantidad`, `unidad`, `aplica_merma`, `notas`) VALUES
(81, 5, 2, 350.0000, '', 0, ''),
(82, 5, 6, 120.0000, '', 0, ''),
(83, 5, 1, 1.5000, '', 0, ''),
(84, 5, 4, 350.0000, '', 0, ''),
(85, 5, 12, 12.0000, '', 0, ''),
(86, 5, 19, 80.0000, '', 0, ''),
(87, 5, 17, 14.0000, '', 0, ''),
(88, 5, 5, 5.0000, '', 0, ''),
(89, 4, 2, 800.0000, '', 0, ''),
(90, 4, 1, 4.0000, '', 0, ''),
(91, 4, 18, 50.0000, '', 0, ''),
(92, 4, 4, 800.0000, '', 0, ''),
(93, 4, 16, 14.0000, '', 0, ''),
(94, 4, 14, 14.0000, '', 0, ''),
(95, 4, 15, 14.0000, '', 0, ''),
(96, 1, 2, 900.0000, '', 0, ''),
(97, 1, 7, 80.0000, '', 0, ''),
(98, 1, 9, 30.0000, '', 0, ''),
(99, 1, 6, 120.0000, '', 0, ''),
(100, 1, 1, 7.5000, '', 0, ''),
(101, 1, 5, 20.0000, '', 0, ''),
(102, 1, 8, 80.0000, '', 0, ''),
(103, 1, 18, 80.0000, '', 0, 'Variable según la hora y el clima'),
(104, 1, 4, 1900.0000, '', 0, ''),
(105, 1, 3, 150.0000, '', 0, ''),
(106, 1, 11, 122.0000, '', 0, ''),
(107, 1, 10, 120.0000, '', 0, ''),
(130, 3, 2, 624.0000, '', 0, ''),
(131, 3, 7, 120.0000, '', 0, ''),
(132, 3, 1, 5.0000, '', 0, ''),
(133, 3, 18, 70.0000, '', 0, 'Variable según la hora y el clima'),
(134, 3, 4, 750.0000, '', 0, ''),
(135, 3, 3, 150.0000, '', 0, ''),
(136, 3, 21, 2000.0000, '', 0, ''),
(137, 2, 2, 950.0000, '', 0, ''),
(138, 2, 7, 110.0000, '', 0, ''),
(139, 2, 9, 30.0000, '', 0, ''),
(140, 2, 6, 250.0000, '', 0, ''),
(141, 2, 1, 8.0000, '', 0, ''),
(142, 2, 5, 25.0000, '', 0, ''),
(143, 2, 8, 150.0000, '', 0, ''),
(144, 2, 18, 130.0000, '', 0, 'Variable según la hora y el clima'),
(145, 2, 4, 1900.0000, '', 0, ''),
(146, 2, 13, 500.0000, '', 0, ''),
(147, 2, 3, 160.0000, '', 0, ''),
(148, 2, 11, 850.0000, '', 0, ''),
(149, 2, 10, 450.0000, '', 0, '');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario`
--

CREATE TABLE `usuario` (
  `id_usuario` int(11) NOT NULL,
  `nombre_usuario` varchar(50) NOT NULL,
  `nombre_completo` varchar(100) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL,
  `rol` enum('propietario','empleado') NOT NULL DEFAULT 'empleado',
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `fecha_creacion` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Usuarios con acceso al sistema';

--
-- Volcado de datos para la tabla `usuario`
--

INSERT INTO `usuario` (`id_usuario`, `nombre_usuario`, `nombre_completo`, `contrasena_hash`, `rol`, `activo`, `fecha_creacion`) VALUES
(1, 'propietario', 'Andres', '$2y$10$l84njpk3R83VoAMK/PvZZOZSUqQGrdMk3tSv39biBujvMqNmJ3Xvm', 'propietario', 1, '2026-02-25 22:06:40');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `venta`
--

CREATE TABLE `venta` (
  `id_venta` int(11) NOT NULL,
  `id_producto` int(11) NOT NULL,
  `id_cierre_dia` int(11) DEFAULT NULL,
  `id_cliente` int(11) DEFAULT NULL,
  `id_usuario` int(11) DEFAULT NULL,
  `fecha_hora` datetime NOT NULL DEFAULT current_timestamp(),
  `unidades_vendidas` int(11) NOT NULL DEFAULT 0,
  `precio_unitario` decimal(12,2) NOT NULL,
  `total_venta` decimal(12,2) NOT NULL COMMENT 'unidades_vendidas * precio_unitario',
  `unidades_sobrantes` int(11) NOT NULL DEFAULT 0,
  `unidades_bonificacion` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Ventas diarias por producto';

--
-- Volcado de datos para la tabla `venta`
--

INSERT INTO `venta` (`id_venta`, `id_producto`, `id_cierre_dia`, `id_cliente`, `id_usuario`, `fecha_hora`, `unidades_vendidas`, `precio_unitario`, `total_venta`, `unidades_sobrantes`, `unidades_bonificacion`) VALUES
(1, 1, NULL, NULL, NULL, '2026-03-23 19:40:59', 10, 500.00, 5000.00, 0, 0),
(2, 1, NULL, 1, NULL, '2026-03-23 19:41:31', 24, 500.00, 10000.00, 0, 4),
(3, 3, NULL, NULL, NULL, '2026-03-24 13:00:01', 10, 500.00, 5000.00, 0, 0),
(4, 1, NULL, NULL, NULL, '2026-03-24 13:00:11', 52, 500.00, 25000.00, 0, 2),
(5, 2, NULL, NULL, NULL, '2026-03-24 13:00:25', 5, 2000.00, 10000.00, 0, 0),
(6, 5, NULL, NULL, NULL, '2026-03-24 13:00:35', 7, 500.00, 3500.00, 0, 0),
(7, 5, NULL, NULL, NULL, '2026-03-24 15:32:46', 20, 500.00, 10000.00, 0, 0);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_inventario_actual`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_inventario_actual` (
`id_insumo` int(11)
,`nombre` varchar(100)
,`unidad_medida` enum('kg','g','L','ml','unidad')
,`es_harina` tinyint(1)
,`stock_actual` decimal(12,3)
,`punto_reposicion` decimal(12,3)
,`consumo_promedio_diario` decimal(12,3)
,`dias_restantes` decimal(14,1)
,`semaforo` varchar(7)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_lotes_fifo`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_lotes_fifo` (
`id_lote` int(11)
,`id_insumo` int(11)
,`nombre_insumo` varchar(100)
,`numero_lote` varchar(30)
,`cantidad_disponible` decimal(12,3)
,`precio_unitario` decimal(12,4)
,`fecha_ingreso` datetime
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_margen_productos`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_margen_productos` (
`id_producto` int(11)
,`nombre` varchar(100)
,`categoria` enum('sal','dulce','especial')
,`precio_venta` decimal(12,2)
,`costo_unitario` decimal(12,4)
,`margen_pct` decimal(19,2)
);

-- --------------------------------------------------------

--
-- Estructura Stand-in para la vista `v_resumen_financiero_30d`
-- (Véase abajo para la vista actual)
--
CREATE TABLE `v_resumen_financiero_30d` (
`fecha` date
,`total_ingresos` decimal(12,2)
,`costo_produccion` decimal(12,2)
,`total_gastos` decimal(12,2)
,`utilidad_bruta` decimal(12,2)
,`utilidad_neta` decimal(12,2)
);

-- --------------------------------------------------------

--
-- Estructura para la vista `v_inventario_actual`
--
DROP TABLE IF EXISTS `v_inventario_actual`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_inventario_actual`  AS SELECT `i`.`id_insumo` AS `id_insumo`, `i`.`nombre` AS `nombre`, `i`.`unidad_medida` AS `unidad_medida`, `i`.`es_harina` AS `es_harina`, `i`.`stock_actual` AS `stock_actual`, `i`.`punto_reposicion` AS `punto_reposicion`, `i`.`consumo_promedio_diario` AS `consumo_promedio_diario`, CASE WHEN `i`.`consumo_promedio_diario` > 0 THEN round(`i`.`stock_actual` / `i`.`consumo_promedio_diario`,1) ELSE NULL END AS `dias_restantes`, CASE WHEN `i`.`stock_actual` <= `i`.`punto_reposicion` THEN 'critico' WHEN `i`.`stock_actual` <= `i`.`punto_reposicion` * 1.5 THEN 'alerta' ELSE 'normal' END AS `semaforo` FROM `insumo` AS `i` WHERE `i`.`activo` = 1 ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_lotes_fifo`
--
DROP TABLE IF EXISTS `v_lotes_fifo`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_lotes_fifo`  AS SELECT `l`.`id_lote` AS `id_lote`, `l`.`id_insumo` AS `id_insumo`, `i`.`nombre` AS `nombre_insumo`, `l`.`numero_lote` AS `numero_lote`, `l`.`cantidad_disponible` AS `cantidad_disponible`, `l`.`precio_unitario` AS `precio_unitario`, `l`.`fecha_ingreso` AS `fecha_ingreso` FROM (`lote` `l` join `insumo` `i` on(`i`.`id_insumo` = `l`.`id_insumo`)) WHERE `l`.`estado` = 'activo' AND `l`.`cantidad_disponible` > 0 ORDER BY `l`.`id_insumo` ASC, `l`.`fecha_ingreso` ASC ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_margen_productos`
--
DROP TABLE IF EXISTS `v_margen_productos`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_margen_productos`  AS SELECT `p`.`id_producto` AS `id_producto`, `p`.`nombre` AS `nombre`, `p`.`categoria` AS `categoria`, `p`.`precio_venta` AS `precio_venta`, coalesce((select `pr2`.`costo_unitario` from `produccion` `pr2` where `pr2`.`id_producto` = `p`.`id_producto` order by `pr2`.`fecha_produccion` desc limit 1),0) AS `costo_unitario`, CASE WHEN `p`.`precio_venta` > 0 AND coalesce((select `pr2`.`costo_unitario` from `produccion` `pr2` where `pr2`.`id_producto` = `p`.`id_producto` order by `pr2`.`fecha_produccion` desc limit 1),0) > 0 THEN round((`p`.`precio_venta` - (select `pr2`.`costo_unitario` from `produccion` `pr2` where `pr2`.`id_producto` = `p`.`id_producto` order by `pr2`.`fecha_produccion` desc limit 1)) / `p`.`precio_venta` * 100,2) ELSE NULL END AS `margen_pct` FROM `producto` AS `p` WHERE `p`.`activo` = 1 ;

-- --------------------------------------------------------

--
-- Estructura para la vista `v_resumen_financiero_30d`
--
DROP TABLE IF EXISTS `v_resumen_financiero_30d`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_resumen_financiero_30d`  AS SELECT `cd`.`fecha` AS `fecha`, `cd`.`total_ingresos` AS `total_ingresos`, `cd`.`costo_produccion` AS `costo_produccion`, `cd`.`total_gastos` AS `total_gastos`, `cd`.`utilidad_bruta` AS `utilidad_bruta`, `cd`.`utilidad_neta` AS `utilidad_neta` FROM `cierre_dia` AS `cd` WHERE `cd`.`fecha` >= curdate() - interval 30 day ORDER BY `cd`.`fecha` DESC ;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `ajuste_inventario`
--
ALTER TABLE `ajuste_inventario`
  ADD PRIMARY KEY (`id_ajuste`),
  ADD KEY `fk_ajuste_insumo` (`id_insumo`),
  ADD KEY `fk_ajuste_usuario` (`id_usuario`);

--
-- Indices de la tabla `alerta`
--
ALTER TABLE `alerta`
  ADD PRIMARY KEY (`id_alerta`),
  ADD KEY `idx_alerta_estado` (`estado`,`fecha_generacion`),
  ADD KEY `idx_alerta_usuario` (`id_usuario`,`estado`);

--
-- Indices de la tabla `cierre_dia`
--
ALTER TABLE `cierre_dia`
  ADD PRIMARY KEY (`id_cierre`),
  ADD UNIQUE KEY `fecha` (`fecha`),
  ADD KEY `fk_cierre_usuario` (`id_usuario`),
  ADD KEY `idx_cierre_fecha` (`fecha`);

--
-- Indices de la tabla `cliente`
--
ALTER TABLE `cliente`
  ADD PRIMARY KEY (`id_cliente`);

--
-- Indices de la tabla `compra`
--
ALTER TABLE `compra`
  ADD PRIMARY KEY (`id_compra`),
  ADD KEY `fk_compra_proveedor` (`id_proveedor`),
  ADD KEY `fk_compra_usuario` (`id_usuario`),
  ADD KEY `idx_compra_insumo` (`id_insumo`,`fecha_compra`);

--
-- Indices de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`id_config`);

--
-- Indices de la tabla `consumo_lote`
--
ALTER TABLE `consumo_lote`
  ADD PRIMARY KEY (`id_consumo`),
  ADD KEY `fk_cl_lote` (`id_lote`),
  ADD KEY `fk_cl_produccion` (`id_produccion`);

--
-- Indices de la tabla `gasto`
--
ALTER TABLE `gasto`
  ADD PRIMARY KEY (`id_gasto`),
  ADD KEY `fk_gasto_cierre_dia` (`id_cierre_dia`),
  ADD KEY `fk_gasto_usuario` (`id_usuario`);

--
-- Indices de la tabla `historial_precio`
--
ALTER TABLE `historial_precio`
  ADD PRIMARY KEY (`id_historial`),
  ADD KEY `fk_hp_proveedor` (`id_proveedor`),
  ADD KEY `fk_hp_compra` (`id_compra`),
  ADD KEY `idx_historial_insumo` (`id_insumo`,`id_proveedor`,`fecha_registro`);

--
-- Indices de la tabla `insumo`
--
ALTER TABLE `insumo`
  ADD PRIMARY KEY (`id_insumo`),
  ADD UNIQUE KEY `nombre` (`nombre`),
  ADD KEY `idx_insumo_activo` (`activo`,`stock_actual`);

--
-- Indices de la tabla `lote`
--
ALTER TABLE `lote`
  ADD PRIMARY KEY (`id_lote`),
  ADD UNIQUE KEY `numero_lote` (`numero_lote`),
  ADD KEY `idx_lote_insumo_estado` (`id_insumo`,`estado`,`fecha_ingreso`) COMMENT 'Clave para algoritmo FIFO';

--
-- Indices de la tabla `produccion`
--
ALTER TABLE `produccion`
  ADD PRIMARY KEY (`id_produccion`),
  ADD KEY `fk_prod_producto` (`id_producto`),
  ADD KEY `fk_prod_receta` (`id_receta`),
  ADD KEY `fk_prod_usuario` (`id_usuario`),
  ADD KEY `idx_produccion_fecha` (`fecha_produccion`);

--
-- Indices de la tabla `producto`
--
ALTER TABLE `producto`
  ADD PRIMARY KEY (`id_producto`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `proveedor`
--
ALTER TABLE `proveedor`
  ADD PRIMARY KEY (`id_proveedor`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `proyeccion_caja`
--
ALTER TABLE `proyeccion_caja`
  ADD PRIMARY KEY (`id_proyeccion`),
  ADD KEY `fk_proy_usuario` (`id_usuario`);

--
-- Indices de la tabla `receta`
--
ALTER TABLE `receta`
  ADD PRIMARY KEY (`id_receta`),
  ADD KEY `fk_receta_producto` (`id_producto`),
  ADD KEY `fk_receta_usuario` (`id_usuario`);

--
-- Indices de la tabla `receta_ingrediente`
--
ALTER TABLE `receta_ingrediente`
  ADD PRIMARY KEY (`id_receta_ing`),
  ADD KEY `fk_ri_receta` (`id_receta`),
  ADD KEY `fk_ri_insumo` (`id_insumo`);

--
-- Indices de la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id_usuario`),
  ADD UNIQUE KEY `nombre_usuario` (`nombre_usuario`);

--
-- Indices de la tabla `venta`
--
ALTER TABLE `venta`
  ADD PRIMARY KEY (`id_venta`),
  ADD KEY `fk_venta_producto` (`id_producto`),
  ADD KEY `idx_venta_cierre` (`id_cierre_dia`),
  ADD KEY `fk_venta_cliente` (`id_cliente`),
  ADD KEY `fk_venta_usuario` (`id_usuario`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `ajuste_inventario`
--
ALTER TABLE `ajuste_inventario`
  MODIFY `id_ajuste` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `alerta`
--
ALTER TABLE `alerta`
  MODIFY `id_alerta` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `cierre_dia`
--
ALTER TABLE `cierre_dia`
  MODIFY `id_cierre` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `cliente`
--
ALTER TABLE `cliente`
  MODIFY `id_cliente` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `compra`
--
ALTER TABLE `compra`
  MODIFY `id_compra` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  MODIFY `id_config` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `consumo_lote`
--
ALTER TABLE `consumo_lote`
  MODIFY `id_consumo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT de la tabla `gasto`
--
ALTER TABLE `gasto`
  MODIFY `id_gasto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `historial_precio`
--
ALTER TABLE `historial_precio`
  MODIFY `id_historial` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `insumo`
--
ALTER TABLE `insumo`
  MODIFY `id_insumo` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT de la tabla `lote`
--
ALTER TABLE `lote`
  MODIFY `id_lote` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT de la tabla `produccion`
--
ALTER TABLE `produccion`
  MODIFY `id_produccion` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT de la tabla `producto`
--
ALTER TABLE `producto`
  MODIFY `id_producto` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `proveedor`
--
ALTER TABLE `proveedor`
  MODIFY `id_proveedor` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `proyeccion_caja`
--
ALTER TABLE `proyeccion_caja`
  MODIFY `id_proyeccion` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `receta`
--
ALTER TABLE `receta`
  MODIFY `id_receta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `receta_ingrediente`
--
ALTER TABLE `receta_ingrediente`
  MODIFY `id_receta_ing` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=150;

--
-- AUTO_INCREMENT de la tabla `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id_usuario` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `venta`
--
ALTER TABLE `venta`
  MODIFY `id_venta` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `ajuste_inventario`
--
ALTER TABLE `ajuste_inventario`
  ADD CONSTRAINT `fk_ajuste_insumo` FOREIGN KEY (`id_insumo`) REFERENCES `insumo` (`id_insumo`),
  ADD CONSTRAINT `fk_ajuste_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `alerta`
--
ALTER TABLE `alerta`
  ADD CONSTRAINT `fk_alerta_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `cierre_dia`
--
ALTER TABLE `cierre_dia`
  ADD CONSTRAINT `fk_cierre_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `compra`
--
ALTER TABLE `compra`
  ADD CONSTRAINT `fk_compra_insumo` FOREIGN KEY (`id_insumo`) REFERENCES `insumo` (`id_insumo`),
  ADD CONSTRAINT `fk_compra_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedor` (`id_proveedor`),
  ADD CONSTRAINT `fk_compra_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `consumo_lote`
--
ALTER TABLE `consumo_lote`
  ADD CONSTRAINT `fk_cl_lote` FOREIGN KEY (`id_lote`) REFERENCES `lote` (`id_lote`),
  ADD CONSTRAINT `fk_cl_produccion` FOREIGN KEY (`id_produccion`) REFERENCES `produccion` (`id_produccion`);

--
-- Filtros para la tabla `gasto`
--
ALTER TABLE `gasto`
  ADD CONSTRAINT `fk_gasto_cierre_dia` FOREIGN KEY (`id_cierre_dia`) REFERENCES `cierre_dia` (`id_cierre`),
  ADD CONSTRAINT `fk_gasto_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `historial_precio`
--
ALTER TABLE `historial_precio`
  ADD CONSTRAINT `fk_hp_compra` FOREIGN KEY (`id_compra`) REFERENCES `compra` (`id_compra`),
  ADD CONSTRAINT `fk_hp_insumo` FOREIGN KEY (`id_insumo`) REFERENCES `insumo` (`id_insumo`),
  ADD CONSTRAINT `fk_hp_proveedor` FOREIGN KEY (`id_proveedor`) REFERENCES `proveedor` (`id_proveedor`);

--
-- Filtros para la tabla `lote`
--
ALTER TABLE `lote`
  ADD CONSTRAINT `fk_lote_insumo` FOREIGN KEY (`id_insumo`) REFERENCES `insumo` (`id_insumo`);

--
-- Filtros para la tabla `produccion`
--
ALTER TABLE `produccion`
  ADD CONSTRAINT `fk_prod_producto` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`),
  ADD CONSTRAINT `fk_prod_receta` FOREIGN KEY (`id_receta`) REFERENCES `receta` (`id_receta`),
  ADD CONSTRAINT `fk_prod_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `proyeccion_caja`
--
ALTER TABLE `proyeccion_caja`
  ADD CONSTRAINT `fk_proy_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `receta`
--
ALTER TABLE `receta`
  ADD CONSTRAINT `fk_receta_producto` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`),
  ADD CONSTRAINT `fk_receta_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`);

--
-- Filtros para la tabla `receta_ingrediente`
--
ALTER TABLE `receta_ingrediente`
  ADD CONSTRAINT `fk_ri_insumo` FOREIGN KEY (`id_insumo`) REFERENCES `insumo` (`id_insumo`),
  ADD CONSTRAINT `fk_ri_receta` FOREIGN KEY (`id_receta`) REFERENCES `receta` (`id_receta`);

--
-- Filtros para la tabla `venta`
--
ALTER TABLE `venta`
  ADD CONSTRAINT `fk_venta_cierre_dia` FOREIGN KEY (`id_cierre_dia`) REFERENCES `cierre_dia` (`id_cierre`),
  ADD CONSTRAINT `fk_venta_producto` FOREIGN KEY (`id_producto`) REFERENCES `producto` (`id_producto`),
  ADD CONSTRAINT `fk_venta_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuario` (`id_usuario`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
