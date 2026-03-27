-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 26-03-2026 a las 22:58:07
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
-- Base de datos: `dbasescon`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `kardex`
--

CREATE TABLE `kardex` (
  `id` int(11) NOT NULL,
  `codigo` varchar(50) DEFAULT NULL,
  `descripcion` varchar(255) DEFAULT NULL,
  `fecha` date DEFAULT NULL,
  `comprobante_tipo` varchar(20) DEFAULT NULL,
  `comprobante_serie` varchar(20) DEFAULT NULL,
  `comprobante_numero` varchar(50) DEFAULT NULL,
  `tipo_operacion` varchar(100) DEFAULT NULL,
  `e_cantidad` decimal(15,4) DEFAULT 0.0000,
  `e_costo_u` decimal(15,4) DEFAULT 0.0000,
  `e_total` decimal(15,4) DEFAULT 0.0000,
  `s_cantidad` decimal(15,4) DEFAULT 0.0000,
  `s_costo_u` decimal(15,4) DEFAULT 0.0000,
  `s_total` decimal(15,4) DEFAULT 0.0000,
  `saldo_cantidad` decimal(15,4) DEFAULT 0.0000,
  `saldo_costo_u` decimal(15,4) DEFAULT 0.0000,
  `saldo_total` decimal(15,4) DEFAULT 0.0000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `kardex_log`
--

CREATE TABLE `kardex_log` (
  `id` int(11) NOT NULL,
  `accion` varchar(20) NOT NULL,
  `descripcion` varchar(500) NOT NULL,
  `detalle` varchar(200) DEFAULT NULL,
  `registros` int(11) DEFAULT 0,
  `fecha` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `kardex`
--
ALTER TABLE `kardex`
  ADD PRIMARY KEY (`id`),
  ADD KEY `codigo` (`codigo`),
  ADD KEY `fecha` (`fecha`);

--
-- Indices de la tabla `kardex_log`
--
ALTER TABLE `kardex_log`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `kardex`
--
ALTER TABLE `kardex`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `kardex_log`
--
ALTER TABLE `kardex_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
