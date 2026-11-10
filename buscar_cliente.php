<?php
session_start();
require_once 'conexion.php';

// Validar que exista la sesión y el negocio
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['id_negocio'])) {
    echo json_encode([]);
    exit;
}

$id_negocio = $_SESSION['id_negocio']; // <- VARIABLE MULTI-TENANT

$busqueda = isset($_GET['q']) ? $conexion->real_escape_string($_GET['q']) : '';

if($busqueda === '') {
    echo json_encode([]);
    exit;
}

// Busca por cédula o por nombre AISLADO POR NEGOCIO
$query = "SELECT id, cedula, nombre, telefono FROM clientes WHERE id_negocio = $id_negocio AND (cedula LIKE '%$busqueda%' OR nombre LIKE '%$busqueda%') LIMIT 5";
$resultado = $conexion->query($query);

$clientes = [];
if ($resultado) {
    while($fila = $resultado->fetch_assoc()) {
        $clientes[] = $fila;
    }
}

echo json_encode($clientes);
?>