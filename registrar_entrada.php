<?php
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'almacenista') || !isset($_SESSION['id_negocio'])) {
    die("Error de seguridad: Acceso denegado.");
}

require_once 'conexion.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_negocio = $_SESSION['id_negocio']; // <- INYECCIÓN MULTI-TENANT

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $producto_id = $_POST['producto_id'];
    $cantidad = floatval($_POST['cantidad']);
    $observacion = trim($_POST['observacion']);
    $usuario_id = $_SESSION['usuario_id'];

    if (empty($producto_id) || $cantidad <= 0) {
        die("Error: Datos inválidos.");
    }

    try {
        $conexion->begin_transaction();

        $stmt_check = $conexion->prepare("SELECT stock FROM productos WHERE id = ? AND id_negocio = ? FOR UPDATE");
        $stmt_check->bind_param("ii", $producto_id, $id_negocio);
        $stmt_check->execute();
        $resultado = $stmt_check->get_result();
        $fila = $resultado->fetch_assoc();
        $stock_anterior = $fila['stock'];
        $stmt_check->close();

        $nuevo_stock = $stock_anterior + $cantidad;

        $stmt_stock = $conexion->prepare("UPDATE productos SET stock = ? WHERE id = ? AND id_negocio = ?");
        $stmt_stock->bind_param("dii", $nuevo_stock, $producto_id, $id_negocio);
        $stmt_stock->execute();
        $stmt_stock->close();

        $stmt_movimiento = $conexion->prepare("INSERT INTO movimientos_inventario (id_negocio, producto_id, usuario_id, tipo_movimiento, cantidad, observacion) VALUES (?, ?, ?, 'entrada', ?, ?)");
        $stmt_movimiento->bind_param("iiids", $id_negocio, $producto_id, $usuario_id, $cantidad, $observacion);
        $stmt_movimiento->execute();
        $stmt_movimiento->close();

        $conexion->commit();
        header("Location: productos.php");
        exit;

    } catch (Exception $e) {
        $conexion->rollback();
        die("Error crítico al procesar la entrada: " . $e->getMessage());
    }
}
?>