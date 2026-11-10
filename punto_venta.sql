-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 05-11-2026 a las 21:54:58
-- Versión del servidor: 10.4.28-MariaDB
-- Versión de PHP: 8.0.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `punto_venta`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `abonos_proveedores`
--

CREATE TABLE `abonos_proveedores` (
  `id` int(11) NOT NULL,
  `factura_id` int(11) NOT NULL,
  `monto_usd` decimal(10,2) NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `referencia` varchar(50) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `moneda_pago` varchar(10) DEFAULT 'usd',
  `monto_moneda` decimal(12,2) DEFAULT NULL,
  `tasa_cambio` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `clientes`
--

CREATE TABLE `clientes` (
  `id` int(11) NOT NULL,
  `cedula` varchar(20) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `telefono` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `clientes`
--

INSERT INTO `clientes` (`id`, `cedula`, `nombre`, `telefono`) VALUES
(1, '24114415', 'David Reyes', ''),
(2, '20408381', 'Gabriel Vielma', '04145021471');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `combos`
--

CREATE TABLE `combos` (
  `id` int(11) NOT NULL,
  `nombre` varchar(255) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `precio_venta` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `combo_detalles`
--

CREATE TABLE `combo_detalles` (
  `id` int(11) NOT NULL,
  `combo_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `combo_detalles`
--

INSERT INTO `combo_detalles` (`id`, `combo_id`, `producto_id`, `cantidad`) VALUES
(1, 5, 2, 2.00),
(2, 5, 3, 1.00),
(3, 5, 4, 2.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `configuracion`
--

CREATE TABLE `configuracion` (
  `id` int(11) NOT NULL,
  `tasa_dia` decimal(10,2) NOT NULL,
  `tasa_euro` decimal(10,2) NOT NULL DEFAULT 1.08,
  `ultima_actualizacion` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `configuracion`
--

INSERT INTO `configuracion` (`id`, `tasa_dia`, `tasa_euro`, `ultima_actualizacion`) VALUES
(8, 900.00, 982.00, '2026-10-19 15:21:55');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cuentas_abiertas`
--

CREATE TABLE `cuentas_abiertas` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `estado` enum('abierta','en_caja') DEFAULT 'abierta',
  `numero_mesa` varchar(20) DEFAULT NULL,
  `nombre_mesonero` varchar(100) DEFAULT NULL,
  `fecha_apertura` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `cuentas_abiertas`
--

INSERT INTO `cuentas_abiertas` (`id`, `cliente_id`, `usuario_id`, `estado`, `numero_mesa`, `nombre_mesonero`, `fecha_apertura`) VALUES
(1, 1, 7, 'en_caja', '1', 'Gabriel Vielma', '2026-10-21 16:10:29'),
(2, 1, 1, 'en_caja', '1', 'Carlos Montilla', '2026-10-21 16:24:22');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `detalle_ventas`
--

CREATE TABLE `detalle_ventas` (
  `id` int(11) NOT NULL,
  `venta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `precio_unitario` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `precio_unitario_usd` decimal(10,2) NOT NULL,
  `subtotal_usd` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `detalle_ventas`
--

INSERT INTO `detalle_ventas` (`id`, `venta_id`, `producto_id`, `cantidad`, `precio_unitario`, `subtotal`, `precio_unitario_usd`, `subtotal_usd`) VALUES
(1, 1, 2, 1.000, 2.00, 2.00, 0.00, 0.00),
(2, 1, 4, 1.000, 1.00, 1.00, 0.00, 0.00),
(3, 1, 5, 1.000, 7.00, 7.00, 0.00, 0.00),
(4, 2, 5, 1.000, 7.00, 7.00, 0.00, 0.00),
(5, 3, 2, 1.000, 2.00, 2.00, 0.00, 0.00),
(6, 4, 2, 1.000, 2.00, 2.00, 0.00, 0.00),
(7, 5, 2, 1.000, 2.00, 2.00, 0.00, 0.00),
(8, 6, 2, 1.000, 2.00, 2.00, 0.00, 0.00),
(9, 7, 2, 1.000, 2.00, 2.00, 0.00, 0.00),
(10, 8, 6, 1.000, 1.50, 1.50, 0.00, 0.00),
(11, 9, 7, 1.000, 2.00, 2.00, 0.00, 0.00),
(12, 10, 2, 1.000, 1.50, 1.50, 0.00, 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `empleados_manuales`
--

CREATE TABLE `empleados_manuales` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `cargo` varchar(50) NOT NULL,
  `salario_base` decimal(10,2) NOT NULL DEFAULT 0.00,
  `bono_manual` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `equipos_herramientas`
--

CREATE TABLE `equipos_herramientas` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `ubicacion` varchar(150) NOT NULL,
  `estado` enum('Disponible','Prestado') DEFAULT 'Disponible',
  `prestado_a` varchar(100) DEFAULT NULL,
  `fecha_prestamo` date DEFAULT NULL,
  `fecha_devolucion` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `facturas_proveedores`
--

CREATE TABLE `facturas_proveedores` (
  `id` int(11) NOT NULL,
  `proveedor_id` int(11) NOT NULL,
  `num_factura` varchar(50) NOT NULL,
  `fecha_emision` date NOT NULL,
  `fecha_vencimiento` date NOT NULL,
  `total_usd` decimal(10,2) NOT NULL,
  `deuda_usd` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `familias`
--

CREATE TABLE `familias` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `gastos`
--

CREATE TABLE `gastos` (
  `id` int(11) NOT NULL,
  `descripcion` varchar(255) NOT NULL,
  `monto` decimal(10,2) NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `fecha` datetime NOT NULL DEFAULT current_timestamp(),
  `usuario_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `historico_abonos`
--

CREATE TABLE `historico_abonos` (
  `id` int(11) NOT NULL,
  `venta_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `monto_usd` decimal(10,2) NOT NULL,
  `monto_bs` decimal(10,2) DEFAULT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `referencia` varchar(50) DEFAULT NULL,
  `tasa_pago` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ingresos_caja`
--

CREATE TABLE `ingresos_caja` (
  `id` int(11) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `usuario_id` int(11) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `metodo_pago` varchar(50) DEFAULT NULL,
  `monto` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `movimientos_inventario`
--

CREATE TABLE `movimientos_inventario` (
  `id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `tipo_movimiento` enum('entrada','salida','ajuste_manual','venta') NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `observacion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `pedidos_temporales`
--

CREATE TABLE `pedidos_temporales` (
  `id` int(11) NOT NULL,
  `cuenta_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(10,3) NOT NULL,
  `precio_usd` decimal(10,2) NOT NULL,
  `notas_cocina` varchar(255) DEFAULT NULL,
  `area_destino` enum('cocina','bar') DEFAULT 'cocina',
  `estado_impresion` enum('pendiente','impreso') DEFAULT 'pendiente',
  `fecha_pedido` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `pedidos_temporales`
--

INSERT INTO `pedidos_temporales` (`id`, `cuenta_id`, `producto_id`, `cantidad`, `precio_usd`, `notas_cocina`, `area_destino`, `estado_impresion`, `fecha_pedido`) VALUES
(1, 1, 5, 1.000, 7.00, '', 'cocina', 'pendiente', '2026-10-21 16:18:19'),
(2, 1, 4, 1.000, 1.00, '', 'cocina', 'pendiente', '2026-10-21 16:18:19'),
(3, 1, 2, 1.000, 2.00, '', 'cocina', 'pendiente', '2026-10-21 16:18:19'),
(4, 2, 5, 1.000, 7.00, '', 'cocina', 'pendiente', '2026-10-21 16:24:26'),
(5, 2, 2, 1.000, 2.00, '', 'cocina', 'pendiente', '2026-10-21 16:24:44');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `productos`
--

CREATE TABLE `productos` (
  `id` int(11) NOT NULL,
  `codigo_barras` varchar(50) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `precio_usd` decimal(10,2) NOT NULL,
  `tipo_unidad` varchar(15) DEFAULT 'und',
  `stock` decimal(10,3) NOT NULL DEFAULT 0.000,
  `stock_minimo` decimal(10,2) NOT NULL DEFAULT 5.00,
  `fecha_vencimiento` date DEFAULT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `familia_id` int(11) DEFAULT NULL,
  `precio_mayor_bcv` decimal(10,2) DEFAULT 0.00,
  `precio_efectivo_detal` decimal(10,2) DEFAULT 0.00,
  `precio_efectivo_mayor` decimal(10,2) DEFAULT 0.00,
  `precio_costo` decimal(10,2) NOT NULL DEFAULT 0.00,
  `imagen` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `productos`
--

INSERT INTO `productos` (`id`, `codigo_barras`, `nombre`, `precio_usd`, `tipo_unidad`, `stock`, `stock_minimo`, `fecha_vencimiento`, `proveedor_id`, `familia_id`, `precio_mayor_bcv`, `precio_efectivo_detal`, `precio_efectivo_mayor`, `precio_costo`, `imagen`) VALUES
(1, '001', 'Hamburguesaa', 5.00, 'und', 100.000, 5.00, NULL, NULL, NULL, 0.00, 0.00, 0.00, 1.00, NULL),
(2, '002', 'Carnes de Hambueguesas', 4.00, 'und', 89.000, 5.00, NULL, NULL, NULL, 3.00, 2.00, 1.50, 2.00, 'img/productos/prod_1793640140_8301.jpeg'),
(3, '003', 'Pan de Hamburguesa', 0.00, 'und', 98.000, 5.00, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, NULL),
(4, '004', 'Queso', 1.00, 'und', 95.000, 5.00, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, NULL),
(5, 'CMB-56011', 'COMBO: Hambueguesa Doble', 7.00, 'und', 9999.000, 0.00, NULL, NULL, NULL, 0.00, 0.00, 0.00, 0.00, NULL),
(6, '005', 'Arroz mari moñito', 2.00, 'kg', 99.000, 5.00, NULL, NULL, NULL, 1.80, 1.60, 1.50, 1.00, 'img/productos/prod_1793639987_2750.jpeg'),
(7, '006', 'Arroz Princesa', 2.00, 'und', 99.000, 5.00, NULL, NULL, NULL, 1.50, 1.20, 1.10, 1.00, 'img/productos/prod_1793640076_5392.jpeg');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proveedores`
--

CREATE TABLE `proveedores` (
  `id` int(11) NOT NULL,
  `empresa` varchar(100) NOT NULL,
  `vendedor` varchar(100) NOT NULL,
  `telefono` varchar(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuarios`
--

CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `usuario` varchar(50) NOT NULL,
  `clave` varchar(255) NOT NULL,
  `rol` enum('admin','vendedor','almacenista') NOT NULL,
  `estado` enum('activo','inactivo') DEFAULT 'activo',
  `salario_base` decimal(10,2) NOT NULL DEFAULT 0.00,
  `porcentaje_comision` decimal(5,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuarios`
--

INSERT INTO `usuarios` (`id`, `nombre`, `usuario`, `clave`, `rol`, `estado`, `salario_base`, `porcentaje_comision`) VALUES
(1, 'Carlos Montilla', 'admin', '123456', 'admin', 'activo', 250.00, 1.00),
(7, 'Gabriel Vielma', 'gabriel91', '123', 'vendedor', 'activo', 140.00, 1.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `ventas`
--

CREATE TABLE `ventas` (
  `id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `tasa_aplicada` decimal(10,2) NOT NULL,
  `total_usd` decimal(10,2) NOT NULL,
  `total_bs` decimal(10,2) NOT NULL,
  `metodo_pago` varchar(50) NOT NULL,
  `billete_recibido_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vuelto_entregado_bs` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vuelto_entregado_usd` decimal(10,2) NOT NULL DEFAULT 0.00,
  `abono_usd` decimal(10,2) DEFAULT 0.00,
  `deuda_usd` decimal(10,2) DEFAULT 0.00,
  `observaciones` varchar(255) DEFAULT NULL,
  `fecha` datetime DEFAULT current_timestamp(),
  `referencia` varchar(50) DEFAULT NULL,
  `foto_evidencia` varchar(255) DEFAULT NULL,
  `mixto_usd` decimal(10,2) DEFAULT 0.00,
  `mixto_efec_bs` decimal(10,2) DEFAULT 0.00,
  `mixto_pm_bs` decimal(10,2) DEFAULT 0.00,
  `mixto_pv_bs` decimal(10,2) DEFAULT 0.00,
  `mixto_bp_bs` decimal(10,2) DEFAULT 0.00,
  `mixto_otro_usd` decimal(10,2) DEFAULT 0.00,
  `mixto_otro_detalle` varchar(100) DEFAULT NULL,
  `metodo_vuelto` decimal(10,2) NOT NULL,
  `abono_a_cliente_usd` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `ventas`
--

INSERT INTO `ventas` (`id`, `cliente_id`, `usuario_id`, `tasa_aplicada`, `total_usd`, `total_bs`, `metodo_pago`, `billete_recibido_usd`, `vuelto_entregado_bs`, `vuelto_entregado_usd`, `abono_usd`, `deuda_usd`, `observaciones`, `fecha`, `referencia`, `foto_evidencia`, `mixto_usd`, `mixto_efec_bs`, `mixto_pm_bs`, `mixto_pv_bs`, `mixto_bp_bs`, `mixto_otro_usd`, `mixto_otro_detalle`, `metodo_vuelto`, `abono_a_cliente_usd`) VALUES
(1, 1, 1, 900.00, 10.00, 9000.00, 'punto_venta', 10.00, 0.00, 0.00, 10.00, 0.00, '', '2026-10-21 16:19:49', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(2, 1, 1, 900.00, 7.00, 6300.00, 'punto_venta', 7.00, 0.00, 0.00, 7.00, 0.00, '', '2026-10-22 22:22:11', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(3, 1, 1, 900.00, 2.00, 1800.00, 'efectivo_usd', 2.00, 0.00, 0.00, 2.00, 0.00, '', '2026-10-23 11:10:42', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(4, NULL, 1, 900.00, 2.00, 1800.00, 'efectivo_bs', 2.00, 0.00, 0.00, 2.00, 0.00, '', '2026-10-23 11:11:00', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(5, 1, 1, 900.00, 2.00, 1800.00, 'credito', 2.00, 0.00, 0.00, 0.00, 2.00, '', '2026-10-23 11:11:19', '', 'img/evidencias/evidencia_ticket_5_1792768279.jpg', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(6, 1, 1, 900.00, 2.00, 1800.00, 'credito', 2.00, 0.00, 0.00, 0.00, 2.00, '', '2026-10-26 07:54:21', '', 'img/evidencias/evidencia_ticket_6_1793015661.jpg', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(7, NULL, 1, 900.00, 2.00, 1800.00, 'punto_venta', 2.00, 0.00, 0.00, 2.00, 0.00, '', '2026-10-27 19:35:15', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(8, NULL, 1, 900.00, 1.50, 1350.00, 'punto_venta', 1.50, 0.00, 0.00, 1.50, 0.00, '', '2026-11-02 13:23:35', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(9, 2, 1, 900.00, 2.00, 1800.00, 'credito', 2.00, 0.00, 0.00, 0.00, 2.00, '', '2026-11-02 13:25:47', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00),
(10, 2, 1, 900.00, 1.50, 1350.00, 'credito', 1.50, 0.00, 0.00, 0.00, 1.50, '', '2026-11-02 13:26:50', '', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '', 0.00, 0.00);

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `abonos_proveedores`
--
ALTER TABLE `abonos_proveedores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `factura_id` (`factura_id`);

--
-- Indices de la tabla `clientes`
--
ALTER TABLE `clientes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `cedula` (`cedula`);

--
-- Indices de la tabla `combos`
--
ALTER TABLE `combos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `combo_detalles`
--
ALTER TABLE `combo_detalles`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `cuentas_abiertas`
--
ALTER TABLE `cuentas_abiertas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `detalle_ventas`
--
ALTER TABLE `detalle_ventas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `venta_id` (`venta_id`),
  ADD KEY `producto_id` (`producto_id`);

--
-- Indices de la tabla `empleados_manuales`
--
ALTER TABLE `empleados_manuales`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `equipos_herramientas`
--
ALTER TABLE `equipos_herramientas`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo` (`codigo`);

--
-- Indices de la tabla `facturas_proveedores`
--
ALTER TABLE `facturas_proveedores`
  ADD PRIMARY KEY (`id`),
  ADD KEY `proveedor_id` (`proveedor_id`);

--
-- Indices de la tabla `familias`
--
ALTER TABLE `familias`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `nombre` (`nombre`);

--
-- Indices de la tabla `gastos`
--
ALTER TABLE `gastos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `historico_abonos`
--
ALTER TABLE `historico_abonos`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `ingresos_caja`
--
ALTER TABLE `ingresos_caja`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  ADD PRIMARY KEY (`id`),
  ADD KEY `producto_id` (`producto_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `pedidos_temporales`
--
ALTER TABLE `pedidos_temporales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cuenta_id` (`cuenta_id`),
  ADD KEY `producto_id` (`producto_id`);

--
-- Indices de la tabla `productos`
--
ALTER TABLE `productos`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `codigo_barras` (`codigo_barras`);

--
-- Indices de la tabla `proveedores`
--
ALTER TABLE `proveedores`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `usuario` (`usuario`);

--
-- Indices de la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD PRIMARY KEY (`id`),
  ADD KEY `cliente_id` (`cliente_id`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `abonos_proveedores`
--
ALTER TABLE `abonos_proveedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT de la tabla `clientes`
--
ALTER TABLE `clientes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `combos`
--
ALTER TABLE `combos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `combo_detalles`
--
ALTER TABLE `combo_detalles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT de la tabla `configuracion`
--
ALTER TABLE `configuracion`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT de la tabla `cuentas_abiertas`
--
ALTER TABLE `cuentas_abiertas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `detalle_ventas`
--
ALTER TABLE `detalle_ventas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `empleados_manuales`
--
ALTER TABLE `empleados_manuales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `equipos_herramientas`
--
ALTER TABLE `equipos_herramientas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `facturas_proveedores`
--
ALTER TABLE `facturas_proveedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `familias`
--
ALTER TABLE `familias`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `gastos`
--
ALTER TABLE `gastos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `historico_abonos`
--
ALTER TABLE `historico_abonos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `ingresos_caja`
--
ALTER TABLE `ingresos_caja`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `pedidos_temporales`
--
ALTER TABLE `pedidos_temporales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT de la tabla `productos`
--
ALTER TABLE `productos`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `proveedores`
--
ALTER TABLE `proveedores`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `usuarios`
--
ALTER TABLE `usuarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT de la tabla `ventas`
--
ALTER TABLE `ventas`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `abonos_proveedores`
--
ALTER TABLE `abonos_proveedores`
  ADD CONSTRAINT `abonos_proveedores_ibfk_1` FOREIGN KEY (`factura_id`) REFERENCES `facturas_proveedores` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `cuentas_abiertas`
--
ALTER TABLE `cuentas_abiertas`
  ADD CONSTRAINT `cuentas_abiertas_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `cuentas_abiertas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `detalle_ventas`
--
ALTER TABLE `detalle_ventas`
  ADD CONSTRAINT `detalle_ventas_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  ADD CONSTRAINT `detalle_ventas_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`);

--
-- Filtros para la tabla `facturas_proveedores`
--
ALTER TABLE `facturas_proveedores`
  ADD CONSTRAINT `facturas_proveedores_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `movimientos_inventario`
--
ALTER TABLE `movimientos_inventario`
  ADD CONSTRAINT `movimientos_inventario_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`),
  ADD CONSTRAINT `movimientos_inventario_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);

--
-- Filtros para la tabla `pedidos_temporales`
--
ALTER TABLE `pedidos_temporales`
  ADD CONSTRAINT `pedidos_temporales_ibfk_1` FOREIGN KEY (`cuenta_id`) REFERENCES `cuentas_abiertas` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `pedidos_temporales_ibfk_2` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `ventas`
--
ALTER TABLE `ventas`
  ADD CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  ADD CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
