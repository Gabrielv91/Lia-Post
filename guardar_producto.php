<?php
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'almacenista') || !isset($_SESSION['id_negocio'])) {
    die("Error de seguridad: Acceso denegado.");
}

require_once 'conexion.php';
$id_negocio = $_SESSION['id_negocio']; // <- INYECCIÓN MULTI-TENANT

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $codigo_barras = trim($_POST['codigo_barras']);
    $nombre = strtoupper(trim($_POST['nombre']));
    $precio_usd = floatval($_POST['precio_usd']);
    $tipo_unidad = $_POST['tipo_unidad'];

    $stmt = $conexion->prepare("INSERT INTO productos (id_negocio, codigo_barras, nombre, precio_usd, tipo_unidad, stock) VALUES (?, ?, ?, ?, ?, 0)");
    $stmt->bind_param("issds", $id_negocio, $codigo_barras, $nombre, $precio_usd, $tipo_unidad);
    
    if ($stmt->execute()) {
        $stmt->close();
        header("Location: inventario.php");
        exit;
    } else {
        die("Error al guardar: " . $conexion->error);
    }
}
?>