<?php
session_start();
require_once 'conexion.php';

// 1. Verificamos que tenga sesión activa y un negocio asignado
if (!isset($_SESSION['usuario_id']) || !isset($_SESSION['id_negocio'])) {
    exit;
}

// 2. Verificamos que solo Administradores o Superadministradores puedan hacer esto
if ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'superadmin') {
    exit;
}

// 3. Capturamos ambas tasas que vienen del POS
if (isset($_POST['tasa']) && isset($_POST['tasa_euro'])) {
    $tasa_dia = floatval($_POST['tasa']);
    $tasa_euro = floatval($_POST['tasa_euro']);
    $id_negocio = intval($_SESSION['id_negocio']);

    // 4. Actualizamos AMBAS tasas en la tabla negocios (Aislamiento Multi-Tenant)
    $stmt = $conexion->prepare("UPDATE negocios SET tasa_dia = ?, tasa_euro = ? WHERE id = ?");
    $stmt->bind_param("ddi", $tasa_dia, $tasa_euro, $id_negocio);
    $stmt->execute();
    $stmt->close();
}
?>