<?php
session_start();
if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor') || !isset($_SESSION['id_negocio'])) {
    die("Error de seguridad: Acceso denegado.");
}

require_once 'conexion.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$id_negocio = $_SESSION['id_negocio']; // <- INYECCIÓN MULTI-TENANT

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cliente_id = intval($_POST['cliente_id']);
    $monto_abono = floatval($_POST['monto_abono']);
    $metodo_pago = trim($_POST['metodo_pago']);
    $referencia_bruta = trim($_POST['referencia'] ?? '');
    $usuario_id = $_SESSION['usuario_id']; 

    $referencia = ($referencia_bruta === '') ? NULL : $referencia_bruta;

    if ($cliente_id <= 0 || $monto_abono <= 0 || empty($metodo_pago)) {
        die("Error: Datos de formulario inválidos.");
    }

    if (($metodo_pago == 'pago_movil' || $metodo_pago == 'biopago') && empty($referencia)) {
        die("Error de Seguridad: Para pagos digitales es obligatoria la referencia.");
    }

    try {
        $conexion->begin_transaction();

        $query_ventas = "SELECT id, deuda_usd, foto_evidencia FROM ventas WHERE cliente_id = ? AND id_negocio = ? AND deuda_usd > 0 ORDER BY fecha ASC FOR UPDATE";
        $stmt_ventas = $conexion->prepare($query_ventas);
        $stmt_ventas->bind_param("ii", $cliente_id, $id_negocio);
        $stmt_ventas->execute();
        $resultado_ventas = $stmt_ventas->get_result();

        $dinero_restante = $monto_abono;

        while ($venta = $resultado_ventas->fetch_assoc()) {
            if ($dinero_restante <= 0) break; 

            $venta_id = $venta['id'];
            $deuda_actual = floatval($venta['deuda_usd']);

            if ($dinero_restante >= $deuda_actual) {
                $abono_a_esta_venta = $deuda_actual;
                $nueva_deuda_venta = 0;
            } else {
                $abono_a_esta_venta = $dinero_restante;
                $nueva_deuda_venta = $deuda_actual - $abono_a_esta_venta;
            }

            $dinero_restante -= $abono_a_esta_venta;

            $query_update_venta = "UPDATE ventas SET deuda_usd = ?, abono_usd = abono_usd + ? WHERE id = ? AND id_negocio = ?";
            $stmt_update = $conexion->prepare($query_update_venta);
            $stmt_update->bind_param("ddii", $nueva_deuda_venta, $abono_a_esta_venta, $venta_id, $id_negocio);
            $stmt_update->execute();
            $stmt_update->close();

            $query_historico = "INSERT INTO historico_abonos (id_negocio, venta_id, usuario_id, monto_usd, metodo_pago, referencia) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_hist = $conexion->prepare($query_historico);
            $stmt_hist->bind_param("iiidss", $id_negocio, $venta_id, $usuario_id, $abono_a_esta_venta, $metodo_pago, $referencia);
            $stmt_hist->execute();
            $stmt_hist->close();

            if ($nueva_deuda_venta <= 0.01) {
                $ruta_foto = trim($venta['foto_evidencia']);
                
                if (!empty($ruta_foto) && file_exists($ruta_foto)) {
                    unlink($ruta_foto); 
                }

                $query_clean_photo = "UPDATE ventas SET foto_evidencia = '' WHERE id = ? AND id_negocio = ?";
                $stmt_clean = $conexion->prepare($query_clean_photo);
                $stmt_clean->bind_param("ii", $venta_id, $id_negocio);
                $stmt_clean->execute();
                $stmt_clean->close();
            }
        }

        $stmt_ventas->close();
        $conexion->commit();

        header("Location: creditos.php?exito=1");
        exit;

    } catch (Exception $e) {
        $conexion->rollback();
        die("Error crítico al procesar el abono de crédito: " . $e->getMessage());
    }
} else {
    header("Location: creditos.php");
    exit;
}
?>