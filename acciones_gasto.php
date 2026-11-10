<?php
session_start();
require_once 'conexion.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'admin' || !isset($_SESSION['id_negocio'])) {
    header("Location: reportes.php?error=no_autorizado");
    exit;
}

$id_negocio = $_SESSION['id_negocio']; // <- INYECCIÓN MULTI-TENANT

if (isset($_GET['eliminar']) && !empty($_GET['eliminar'])) {
    $id_gasto = intval($_GET['eliminar']);
    $sql = "DELETE FROM gastos WHERE id = $id_gasto AND id_negocio = $id_negocio";
    
    if ($conexion->query($sql)) {
        header("Location: reportes.php?gasto=eliminado");
    } else {
        header("Location: reportes.php?error=bd");
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'editar') {
    $id_gasto = intval($_POST['gasto_id']);
    $descripcion = $conexion->real_escape_string($_POST['descripcion']);
    $monto = floatval($_POST['monto']);
    $metodo_pago = $conexion->real_escape_string($_POST['metodo_pago']);

    $sql = "UPDATE gastos SET 
            descripcion = '$descripcion', 
            monto = $monto, 
            metodo_pago = '$metodo_pago' 
            WHERE id = $id_gasto AND id_negocio = $id_negocio";

    if ($conexion->query($sql)) {
        header("Location: reportes.php?gasto=actualizado");
    } else {
        header("Location: reportes.php?error=bd");
    }
    exit;
}

header("Location: reportes.php");
exit;
?>