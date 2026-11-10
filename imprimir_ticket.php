<?php
session_start();
require_once 'conexion.php';

if (!isset($_GET['id']) || !isset($_SESSION['id_negocio'])) { die("Acceso inválido."); }
$id_venta = intval($_GET['id']);
$id_negocio = $_SESSION['id_negocio']; // <- INYECCIÓN MULTI-TENANT

$query = "SELECT v.*, c.nombre, c.cedula, u.nombre as cajero 
          FROM ventas v 
          LEFT JOIN clientes c ON v.cliente_id = c.id AND c.id_negocio = ?
          LEFT JOIN usuarios u ON v.usuario_id = u.id AND u.id_negocio = ?
          WHERE v.id = ? AND v.id_negocio = ?";
$stmt = $conexion->prepare($query);
$stmt->bind_param("iiii", $id_negocio, $id_negocio, $id_venta, $id_negocio);
$stmt->execute();
$venta = $stmt->get_result()->fetch_assoc();

if (!$venta) { die("Venta no encontrada."); }

$query_det = "SELECT dv.*, p.nombre FROM detalle_ventas dv JOIN productos p ON dv.producto_id = p.id AND p.id_negocio = ? WHERE dv.venta_id = ? AND dv.id_negocio = ?";
$stmt_det = $conexion->prepare($query_det);
$stmt_det->bind_param("iii", $id_negocio, $id_venta, $id_negocio);
$stmt_det->execute();
$detalles = $stmt_det->get_result();

$tasa = floatval($venta['tasa_aplicada']);
if($tasa <= 0) { $tasa = 36.50; } 
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket #<?php echo str_pad($id_venta, 6, "0", STR_PAD_LEFT); ?></title>
    <style>
        @page { margin: 0; size: 80mm auto; }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { background: #fff; display: flex; justify-content: center; color: #000; padding: 0; }
        .ticket { width: 72mm; padding: 5px; font-size: 13px; line-height: 1.3; margin: 0 auto; }
        .centro { text-align: center; }
        .divisor { border-top: 1px dashed #000; margin: 5px 0; }
        .flex { display: flex; justify-content: space-between; align-items: center; }
        .bold { font-weight: bold; }
        .tabla-prods { width: 100%; margin: 5px 0; border-collapse: collapse; table-layout: fixed; }
        .tabla-prods th { text-align: left; border-bottom: 1px solid #000; font-size: 12px; padding-bottom: 3px; }
        .tabla-prods td { font-size: 13px; padding: 3px 0; vertical-align: top; }
        .total-destacado { font-size: 15px; font-weight: bold; margin: 5px 0; border-top: 1px solid #000; border-bottom: 1px solid #000; padding: 5px 0; }
        .alerta-credito { border: 1px solid #000; padding: 5px; text-align: center; margin-top: 5px; font-weight: bold; font-size: 12px; }
        @media print { body { background: white; margin: 0; padding: 0; } .ticket { width: 100%; margin: 0; } }
    </style>
</head>
<body onload="window.print(); setTimeout(() => window.close(), 1000);">
    <div class="ticket">
        <div class="centro">
            <h2 style="font-size: 13px;">Lia POS</h2>
            <p style="font-size: 10px;">J-000000</p>
            <p class="bold" style="margin-top: 2px;">NOTA DE ENTREGA</p>
        </div>
        
        <div class="divisor"></div>
        
        <div class="flex"><span><strong>Ticket:</strong> #<?php echo str_pad($id_venta, 6, "0", STR_PAD_LEFT); ?></span><span style="font-size: 11px;"><?php echo date('d/m/Y h:ia', strtotime($venta['fecha'])); ?></span></div>
        <p><strong>Cajero:</strong> <?php echo substr($venta['cajero'], 0, 20); ?></p>
        <p><strong>Cliente:</strong> <?php echo $venta['nombre'] ? substr($venta['nombre'], 0, 25) : 'Consumidor Final'; ?></p>
        <?php if($venta['cedula']) echo "<p><strong>C.I/RIF:</strong> {$venta['cedula']}</p>"; ?>
        
        <div class="divisor"></div>

        <table class="tabla-prods">
            <thead>
                <tr><th style="width: 15%;">Cant</th><th style="width: 50%;">Descripción</th><th style="width: 35%; text-align:right;">Total(Bs)</th></tr>
            </thead>
            <tbody>
                <?php while($item = $detalles->fetch_assoc()): $subtotal_bs = floatval($item['subtotal']) * $tasa; ?>
                <tr>
                    <td><?php echo floatval($item['cantidad']); ?></td>
                    <td style="padding-right: 5px; word-wrap: break-word; overflow-wrap: break-word;"><?php echo $item['nombre']; ?></td>
                    <td style="text-align:right;"><?php echo number_format($subtotal_bs, 2, ',', '.'); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>

        <div class="flex total-destacado">
            <span>TOTAL A PAGAR:</span> 
            <span><?php echo number_format($venta['total_bs'], 2, ',', '.'); ?> Bs</span>
        </div>

        <?php if($venta['deuda_usd'] > 0): 
            $abono_bs = floatval($venta['abono_usd']) * $tasa;
            $deuda_bs = floatval($venta['deuda_usd']) * $tasa;
        ?>
            <div class="alerta-credito">
                *** VENTA A CRÉDITO ***<br>
                <span style="font-size: 11px; font-weight: normal;">Abono: <?php echo number_format($abono_bs, 2, ',', '.'); ?> Bs</span><br>
                <div class="divisor"></div>
                RESTA POR PAGAR:<br>
                <span style="font-size: 15px;"><?php echo number_format($deuda_bs, 2, ',', '.'); ?> Bs</span><br>
                <span style="font-size: 11px; font-weight: normal;">(Ref: $<?php echo number_format($venta['deuda_usd'], 2); ?>)</span>
            </div>
        <?php else: ?>
            <p class="centro bold" style="margin-top: 5px; font-size: 14px;">PAGADO</p>
        <?php endif; ?>

        <div class="divisor"></div>
        <div class="centro" style="margin-top: 5px; font-size: 12px;"><p>¡Gracias por su compra!</p></div>
    </div>
</body>
</html>