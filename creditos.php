<?php
session_start();
if (!isset($_SESSION['usuario_id']) || !in_array($_SESSION['usuario_rol'], ['admin', 'vendedor', 'superadmin']) || !isset($_SESSION['id_negocio'])) {
    header("Location: index.php");
    exit;
}

require_once 'conexion.php';

$mensaje = '';
if (isset($_SESSION['flash_creditos'])) {
    $mensaje = $_SESSION['flash_creditos'];
    unset($_SESSION['flash_creditos']);
}

$id_negocio = intval($_SESSION['id_negocio']);
$es_admin = ($_SESSION['usuario_rol'] == 'admin' || $_SESSION['usuario_rol'] == 'superadmin');

// --- OBTENER NOMBRE Y TASA DEL NEGOCIO ACTUAL ---
$nombre_mi_negocio = 'Nuestro Negocio';
$tasa = 36.50;

$stmt_neg = $conexion->prepare("SELECT nombre, tasa_dia FROM negocios WHERE id = ? LIMIT 1");
$stmt_neg->bind_param("i", $id_negocio);
$stmt_neg->execute();
$res_neg = $stmt_neg->get_result();
if ($res_neg && $res_neg->num_rows > 0) {
    $fila_neg = $res_neg->fetch_assoc();
    if (!empty($fila_neg['nombre'])) $nombre_mi_negocio = $fila_neg['nombre'];
    if (isset($fila_neg['tasa_dia']) && floatval($fila_neg['tasa_dia']) > 0) {
        $tasa = floatval($fila_neg['tasa_dia']);
    }
}
$stmt_neg->close();

$check_tasa = $conexion->query("SHOW COLUMNS FROM historico_abonos LIKE 'tasa_pago'");
if ($check_tasa && $check_tasa->num_rows == 0) {
    $conexion->query("ALTER TABLE historico_abonos ADD tasa_pago DECIMAL(10,2) NULL");
    $conexion->query("UPDATE historico_abonos ha JOIN ventas v ON ha.venta_id = v.id SET ha.tasa_pago = v.tasa_aplicada");
}
$check_foto = $conexion->query("SHOW COLUMNS FROM ventas LIKE 'foto_evidencia'");
if ($check_foto && $check_foto->num_rows == 0) {
    $conexion->query("ALTER TABLE ventas ADD foto_evidencia VARCHAR(255) NULL");
}
$check_mbs = $conexion->query("SHOW COLUMNS FROM historico_abonos LIKE 'monto_bs'");
if ($check_mbs && $check_mbs->num_rows == 0) {
    $conexion->query("ALTER TABLE historico_abonos ADD monto_bs DECIMAL(10,2) NULL AFTER monto_usd");
    $conexion->query("UPDATE historico_abonos ha JOIN ventas v ON ha.venta_id = v.id SET ha.monto_bs = ha.monto_usd * IFNULL(ha.tasa_pago, v.tasa_aplicada)");
}

// ========================================================================
// 0. REGISTRAR, EDITAR Y ELIMINAR CRÉDITO MANUAL / PRÉSTAMO (SOLO ADMIN)
// ========================================================================
if ($es_admin && isset($_POST['crear_credito_manual'])) {
    $cliente_id = intval($_POST['manual_cliente_id'] ?? 0);
    $tipo_manual = trim($_POST['manual_tipo'] ?? 'Préstamo de dinero');
    $moneda_manual = $_POST['manual_moneda'] ?? 'usd';
    $monto_ingresado = floatval($_POST['manual_monto'] ?? 0);
    $detalle_manual = trim($_POST['manual_detalle'] ?? '');
    $fecha_raw = trim($_POST['manual_fecha'] ?? '');
    $usuario_id = intval($_SESSION['usuario_id']);

    if ($cliente_id > 0 && $monto_ingresado > 0) {
        if ($moneda_manual === 'bs' && $tasa > 0) {
            $monto_usd = round($monto_ingresado / $tasa, 2);
            $monto_bs  = round($monto_ingresado, 2);
        } else {
            $monto_usd = round($monto_ingresado, 2);
            $monto_bs  = round($monto_ingresado * $tasa, 2);
        }

        $fecha_sql = ($fecha_raw !== '') ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $fecha_raw))) : date('Y-m-d H:i:s');

        $obs_final = "[$tipo_manual]";
        if ($detalle_manual !== '') $obs_final .= " " . $detalle_manual;
        $obs_final .= " (Tasa ref: " . number_format($tasa, 2, '.', '') . " Bs)";

        $stmt_man = $conexion->prepare("INSERT INTO ventas (id_negocio, cliente_id, usuario_id, fecha, total_usd, total_bs, metodo_pago, abono_usd, deuda_usd, tasa_aplicada, observaciones) VALUES (?, ?, ?, ?, ?, ?, 'credito', 0, ?, ?, ?)");
        $stmt_man->bind_param("iiisdddds", $id_negocio, $cliente_id, $usuario_id, $fecha_sql, $monto_usd, $monto_bs, $monto_usd, $tasa, $obs_final);
        if ($stmt_man->execute()) {
            $_SESSION['flash_creditos'] = "Crédito manual / préstamo por $" . number_format($monto_usd, 2) . " registrado correctamente.";
        }
        $stmt_man->close();
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if ($es_admin && isset($_POST['editar_credito_manual'])) {
    $venta_id = intval($_POST['edit_manual_id'] ?? 0);
    $cliente_id = intval($_POST['edit_manual_cliente_id'] ?? 0);
    $moneda_manual = $_POST['edit_manual_moneda'] ?? 'usd';
    $monto_ingresado = floatval($_POST['edit_manual_monto'] ?? 0);
    $obs_nueva = trim($_POST['edit_manual_observaciones'] ?? '');
    $fecha_raw = trim($_POST['edit_manual_fecha'] ?? '');

    if ($venta_id > 0 && $cliente_id > 0 && $monto_ingresado > 0) {
        $chk_det = $conexion->query("SELECT COUNT(*) as c FROM detalle_ventas WHERE venta_id = $venta_id AND id_negocio = $id_negocio");
        $es_manual_real = ($chk_det && intval($chk_det->fetch_assoc()['c']) === 0);

        if ($es_manual_real) {
            if ($moneda_manual === 'bs' && $tasa > 0) {
                $nuevo_total_usd = round($monto_ingresado / $tasa, 2);
                $nuevo_total_bs  = round($monto_ingresado, 2);
            } else {
                $nuevo_total_usd = round($monto_ingresado, 2);
                $nuevo_total_bs  = round($monto_ingresado * $tasa, 2);
            }

            $q_ab = $conexion->query("SELECT IFNULL(SUM(monto_usd), 0) as abonado FROM historico_abonos WHERE venta_id = $venta_id AND id_negocio = $id_negocio");
            $abonado_usd = ($q_ab && $q_ab->num_rows > 0) ? floatval($q_ab->fetch_assoc()['abonado']) : 0;
            $nueva_deuda_usd = max(0, round($nuevo_total_usd - $abonado_usd, 2));

            $fecha_sql = ($fecha_raw !== '') ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $fecha_raw))) : date('Y-m-d H:i:s');

            $stmt_upd = $conexion->prepare("UPDATE ventas SET cliente_id = ?, fecha = ?, total_usd = ?, total_bs = ?, deuda_usd = ?, observaciones = ? WHERE id = ? AND id_negocio = ?");
            $stmt_upd->bind_param("isdddsii", $cliente_id, $fecha_sql, $nuevo_total_usd, $nuevo_total_bs, $nueva_deuda_usd, $obs_nueva, $venta_id, $id_negocio);
            if ($stmt_upd->execute()) {
                $_SESSION['flash_creditos'] = "Crédito manual actualizado correctamente.";
            }
            $stmt_upd->close();
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

if ($es_admin && isset($_POST['eliminar_credito_manual'])) {
    $venta_id = intval($_POST['elim_manual_id'] ?? 0);
    if ($venta_id > 0) {
        $chk_det = $conexion->query("SELECT COUNT(*) as c FROM detalle_ventas WHERE venta_id = $venta_id AND id_negocio = $id_negocio");
        $es_manual_real = ($chk_det && intval($chk_det->fetch_assoc()['c']) === 0);

        if ($es_manual_real) {
            $conexion->query("DELETE FROM historico_abonos WHERE venta_id = $venta_id AND id_negocio = $id_negocio");
            $conexion->query("DELETE FROM ventas WHERE id = $venta_id AND id_negocio = $id_negocio");
            $_SESSION['flash_creditos'] = "Crédito manual eliminado correctamente.";
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ========================================================================
// 1. PROCESAR NUEVO ABONO (MODO MIXTO)
// ========================================================================
if (isset($_POST['procesar_abono'])) {
    $cliente_id = intval($_POST['cliente_id']);
    $usuario_id = intval($_SESSION['usuario_id']);
    $fecha_abono = date('Y-m-d H:i:s');
    $referencia_global = $conexion->real_escape_string($_POST['referencia_mixta'] ?? '');

    $pool = [];
    $efectivo_usd = floatval($_POST['monto_efectivo_usd'] ?? 0);
    if ($efectivo_usd > 0) $pool[] = ['metodo' => 'credito_efectivo_usd', 'usd' => $efectivo_usd, 'bs' => 0, 'ref' => ''];

    $efectivo_bs = floatval($_POST['monto_efectivo_bs'] ?? 0);
    if ($efectivo_bs > 0) $pool[] = ['metodo' => 'credito_efectivo_bs', 'usd' => ($tasa > 0 ? $efectivo_bs / $tasa : 0), 'bs' => $efectivo_bs, 'ref' => ''];

    $pago_movil = floatval($_POST['monto_pago_movil'] ?? 0);
    if ($pago_movil > 0) $pool[] = ['metodo' => 'credito_pago_movil', 'usd' => ($tasa > 0 ? $pago_movil / $tasa : 0), 'bs' => $pago_movil, 'ref' => $referencia_global];

    $punto_venta = floatval($_POST['monto_punto'] ?? 0);
    if ($punto_venta > 0) $pool[] = ['metodo' => 'credito_punto_venta', 'usd' => ($tasa > 0 ? $punto_venta / $tasa : 0), 'bs' => $punto_venta, 'ref' => $referencia_global];

    $biopago = floatval($_POST['monto_biopago'] ?? 0);
    if ($biopago > 0) $pool[] = ['metodo' => 'credito_biopago', 'usd' => ($tasa > 0 ? $biopago / $tasa : 0), 'bs' => $biopago, 'ref' => $referencia_global];

    $transferencia_bs = floatval($_POST['monto_transferencia'] ?? 0);
    if ($transferencia_bs > 0) $pool[] = ['metodo' => 'credito_transferencia_bs', 'usd' => ($tasa > 0 ? $transferencia_bs / $tasa : 0), 'bs' => $transferencia_bs, 'ref' => $referencia_global];

    $trueque = floatval($_POST['monto_trueque'] ?? 0);
    if ($trueque > 0) {
        $detalle_trueque = $conexion->real_escape_string($_POST['detalle_trueque'] ?? '');
        $ref_final = !empty($detalle_trueque) ? "Detalle: " . $detalle_trueque : "Trueque / Otro";
        $pool[] = ['metodo' => 'credito_trueque', 'usd' => $trueque, 'bs' => 0, 'ref' => $ref_final];
    }

    $zelle_usd = floatval($_POST['monto_zelle'] ?? 0);
    if ($zelle_usd > 0) {
        $detalle_zelle = $conexion->real_escape_string($_POST['detalle_zelle'] ?? '');
        $ref_final_z = !empty($detalle_zelle) ? $detalle_zelle : "Zelle / Transferencia Int.";
        $pool[] = ['metodo' => 'credito_zelle', 'usd' => $zelle_usd, 'bs' => 0, 'ref' => $ref_final_z];
    }

    $descuento_usd = isset($_POST['aplicar_descuento']) ? floatval($_POST['monto_descuento'] ?? 0) : 0;
    if ($descuento_usd > 0) {
        $pool[] = ['metodo' => 'descuento', 'usd' => $descuento_usd, 'bs' => 0, 'ref' => 'Descuento aplicado por pago en divisas'];
    }

    $monto_disponible_usd = 0;
    foreach ($pool as $p) $monto_disponible_usd += $p['usd'];

    if ($cliente_id > 0 && $monto_disponible_usd > 0) {
        $q_facturas = "SELECT id, deuda_usd, foto_evidencia FROM ventas WHERE cliente_id = $cliente_id AND id_negocio = $id_negocio AND deuda_usd > 0 ORDER BY fecha ASC";
        $res_facturas = $conexion->query($q_facturas);

        while ($res_facturas && $factura = $res_facturas->fetch_assoc()) {
            if ($monto_disponible_usd <= 0.001) break;

            $id_venta = $factura['id'];
            $deuda_factura = floatval($factura['deuda_usd']);
            $ruta_foto = $factura['foto_evidencia'];

            $pago_aplicado_factura_usd = min($monto_disponible_usd, $deuda_factura);
            $nueva_deuda = $deuda_factura - $pago_aplicado_factura_usd;

            $conexion->query("UPDATE ventas SET deuda_usd = $nueva_deuda WHERE id = $id_venta AND id_negocio = $id_negocio");

            if ($nueva_deuda <= 0.001 && !empty($ruta_foto) && file_exists($ruta_foto)) {
                unlink($ruta_foto);
                $conexion->query("UPDATE ventas SET foto_evidencia = NULL WHERE id = $id_venta AND id_negocio = $id_negocio");
            }

            $usd_a_cubrir = $pago_aplicado_factura_usd;
            foreach ($pool as &$p) {
                if ($usd_a_cubrir <= 0.001) break;
                if ($p['usd'] <= 0.001) continue;

                $usd_tomado = min($p['usd'], $usd_a_cubrir);
                $bs_tomado = ($p['usd'] > 0) ? ($usd_tomado / $p['usd']) * $p['bs'] : 0;

                $p['usd'] -= $usd_tomado;
                $p['bs'] -= $bs_tomado;
                $usd_a_cubrir -= $usd_tomado;

                $metodo_insert = $p['metodo'];
                $ref_insert = $conexion->real_escape_string($p['ref']);

                $conexion->query("INSERT INTO historico_abonos (id_negocio, venta_id, usuario_id, monto_usd, monto_bs, metodo_pago, referencia, tasa_pago, fecha) 
                                  VALUES ($id_negocio, $id_venta, $usuario_id, $usd_tomado, $bs_tomado, '$metodo_insert', '$ref_insert', $tasa, '$fecha_abono')");
            }
            $monto_disponible_usd -= $pago_aplicado_factura_usd;
        }
        $_SESSION['flash_creditos'] = "Abono procesado con éxito.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ========================================================================
// 2. EDITAR ABONO EXISTENTE
// ========================================================================
if (isset($_POST['editar_abono'])) {
    $abono_id = intval($_POST['abono_id']);
    $monto_ingresado = floatval($_POST['monto_abono']);
    $moneda = $_POST['moneda_abono'] ?? 'usd';
    $metodo = $conexion->real_escape_string($_POST['metodo_pago']);
    $referencia = $conexion->real_escape_string($_POST['referencia']);

    if ($moneda === 'bs' && $tasa > 0) {
        $nuevo_monto_usd = $monto_ingresado / $tasa;
        $nuevo_monto_bs = $monto_ingresado;
    } else {
        $nuevo_monto_usd = $monto_ingresado;
        $nuevo_monto_bs = $monto_ingresado * $tasa;
    }

    $q_viejo = $conexion->query("SELECT venta_id, monto_usd FROM historico_abonos WHERE id = $abono_id AND id_negocio = $id_negocio");
    if ($q_viejo && $q_viejo->num_rows > 0) {
        $viejo = $q_viejo->fetch_assoc();
        $venta_id = $viejo['venta_id'];
        $monto_viejo = floatval($viejo['monto_usd']);
        $diferencia = $nuevo_monto_usd - $monto_viejo;

        $conexion->query("UPDATE ventas SET deuda_usd = deuda_usd - ($diferencia) WHERE id = $venta_id AND id_negocio = $id_negocio");
        $conexion->query("UPDATE historico_abonos SET monto_usd = $nuevo_monto_usd, monto_bs = $nuevo_monto_bs, metodo_pago = '$metodo', referencia = '$referencia' WHERE id = $abono_id AND id_negocio = $id_negocio");
        $_SESSION['flash_creditos'] = "El pago fue corregido y la deuda actualizada.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ========================================================================
// 3. ELIMINAR / REVERSAR ABONO
// ========================================================================
if (isset($_POST['eliminar_abono'])) {
    $abono_id = intval($_POST['abono_id_eliminar'] ?? $_POST['abono_id_elim'] ?? 0);
    $q_viejo = $conexion->query("SELECT venta_id, monto_usd FROM historico_abonos WHERE id = $abono_id AND id_negocio = $id_negocio");
    if ($q_viejo && $q_viejo->num_rows > 0) {
        $viejo = $q_viejo->fetch_assoc();
        $venta_id = $viejo['venta_id'];
        $monto_viejo = floatval($viejo['monto_usd']);

        $conexion->query("UPDATE ventas SET deuda_usd = deuda_usd + $monto_viejo WHERE id = $venta_id AND id_negocio = $id_negocio");
        $conexion->query("DELETE FROM historico_abonos WHERE id = $abono_id AND id_negocio = $id_negocio");
        $_SESSION['flash_creditos'] = "Pago reversado. La deuda se ha sumado nuevamente al cliente.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ========================================================================
// 4. CONSULTAS (SOLO CLIENTES REALES Y COMPATIBLE CON MYSQL 8)
// ========================================================================
$conexion->query("SET SESSION group_concat_max_len = 10000;");

$clientes_arr = [];
if ($es_admin) {
    $q_cli = $conexion->query("SELECT id, cedula, nombre FROM clientes WHERE id_negocio = $id_negocio ORDER BY nombre ASC");
    if ($q_cli) {
        while ($c_row = $q_cli->fetch_assoc()) $clientes_arr[] = $c_row;
    }
}

$query_creditos = "
    SELECT 
        c.id,
        c.nombre,
        c.cedula,
        IFNULL(c.telefono, '') as telefono,
        SUM(v.deuda_usd) as deuda_total,
        MAX(v.fecha) as ultima_compra,
        DATEDIFF(CURDATE(), MIN(v.fecha)) as dias_mora
    FROM ventas v
    JOIN clientes c ON v.cliente_id = c.id AND c.id_negocio = $id_negocio
    WHERE v.deuda_usd > 0 AND v.id_negocio = $id_negocio
    GROUP BY c.id, c.nombre, c.cedula, c.telefono
    ORDER BY dias_mora DESC, deuda_total DESC
";
$resultado = $conexion->query($query_creditos);

$query_total_calle = "
    SELECT SUM(v.deuda_usd) as gran_total
    FROM ventas v
    JOIN clientes c ON v.cliente_id = c.id AND c.id_negocio = $id_negocio
    WHERE v.deuda_usd > 0 AND v.id_negocio = $id_negocio
";
$res_total = $conexion->query($query_total_calle);
$gran_total_usd = ($res_total && $res_total->num_rows > 0) ? floatval($res_total->fetch_assoc()['gran_total']) : 0;
$gran_total_bs = $gran_total_usd * $tasa;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lia POS - Créditos</title>
    <link rel="stylesheet" href="css/style.css?v=<?php echo time(); ?>">
    <style>
        .pantalla-completa { padding: 2rem; max-width: 1200px; margin: 0 auto; }
        .buscador-grande { width: 100%; padding: 1rem; font-size: 1.1rem; border: 2px solid var(--borde); border-radius: 8px; margin-bottom: 2rem; box-sizing: border-box; }
        .btn-cobrar { background-color: #10b981; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: bold; cursor: pointer; text-decoration: none; }
        .btn-detalles { background-color: #3b82f6; color: white; border: none; padding: 0.5rem 1rem; border-radius: 6px; font-weight: bold; cursor: pointer; margin-right: 0.5rem; text-decoration: none; }
        #modal-detalles .modal-caja { display: flex; flex-direction: column; gap: 1.2rem; }
        .modal-fondo { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; padding: 1rem; box-sizing: border-box; }
        .input-mixto { width: 100%; box-sizing: border-box; padding: 0.6rem; border: 1px solid #cbd5e1; border-radius: 4px; }

        .autocomplete-wrap { position: relative; }
        .autocomplete-lista {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid #cbd5e1;
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 190px;
            overflow-y: auto;
            z-index: 1200;
            box-shadow: 0 6px 14px rgba(0,0,0,0.12);
        }
        .autocomplete-item {
            padding: 0.6rem 0.8rem;
            cursor: pointer;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.92rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .autocomplete-item:hover { background: #eff6ff; color: #1e3a8a; }
        .autocomplete-item small { color: #64748b; font-size: 0.8rem; }

        @media print {
            body * { visibility: hidden; }
            #modal-detalles, #modal-detalles * { visibility: visible; }
            #modal-detalles { position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important; background: white !important; display: block !important; }
            #modal-detalles .modal-caja { width: 100% !important; max-width: 100% !important; box-shadow: none !important; border: none !important; padding: 0 !important; margin: 0 !important; }
            div[style*="max-height"], div[style*="overflow"], #detalles-productos, #detalles-abonos { max-height: none !important; overflow: visible !important; height: auto !important; }
            #detalles-productos > div { page-break-inside: avoid !important; }
            .btn-cerrar, .botones-accion, .btn-foto-print, .ocultar-print { display: none !important; }
        }
    </style>
</head>
<body style="background-color: #f8fafc;">
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);">⬅️</a>
            <h1>Gestión de Créditos y Cobranzas</h1>
        </div>
        <div style="display: flex; align-items: center; gap: 1.2rem; flex-wrap: wrap;">
            <span style="font-size: 0.9rem; color: var(--texto-secundario);">
                Tasa: <strong style="color: #10b981;"><?php echo number_format($tasa, 2, '.', ''); ?> Bs</strong>
            </span>
            <?php if ($es_admin): ?>
                <button type="button" onclick="abrirModalCreditoManual()" class="btn-cobrar" style="background-color: #f59e0b; padding: 0.55rem 1rem;">➕ Crédito Manual / Préstamo</button>
            <?php endif; ?>
            <div>
                Cajero: <strong style="color: var(--primario);"><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></strong>
            </div>
        </div>
    </header>

    <main class="pantalla-completa">
        <?php if ($mensaje): ?>
            <div style="background: #d1fae5; color: #065f46; padding: 1rem; border-radius: 6px; margin-bottom: 1.5rem; font-weight: bold;">✅ <?php echo $mensaje; ?></div>
        <?php endif; ?>

        <div style="background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 1.5rem 2rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); margin-bottom: 2rem; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h2 style="margin: 0; font-size: 1.2rem; font-weight: normal; opacity: 0.9;">Total de Dinero en la Calle</h2>
                <div style="font-size: 2.5rem; font-weight: bold; margin-top: 0.5rem;">$<?php echo number_format($gran_total_usd, 2); ?></div>
                <div style="font-size: 1.2rem; opacity: 0.85;"><?php echo number_format($gran_total_bs, 2, ',', '.'); ?> Bs</div>
            </div>
            <div style="font-size: 3.5rem; opacity: 0.9;">💰</div>
        </div>

        <input type="text" id="buscador-clientes" class="buscador-grande" placeholder="🔍 Buscar cliente por nombre o cédula...">

        <div style="background: white; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow-x: auto;">
            <table class="tabla-inventario" id="tabla-creditos">
                <thead>
                    <tr>
                        <th>Cédula</th>
                        <th>Nombre</th>
                        <th>Deuda Total</th>
                        <th>Último Movimiento</th>
                        <th>Mora</th>
                        <th>Estado</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($resultado && $resultado->num_rows > 0): while ($cliente = $resultado->fetch_assoc()):
                        $deuda_usd = floatval($cliente['deuda_total']);
                        $deuda_bs = $deuda_usd * $tasa;
                        $dias_mora = intval($cliente['dias_mora']);
                        $telefono = $cliente['telefono'];
                        $clase_estado = 'estado-ok'; $texto_estado = 'Al Día';
                        if ($dias_mora > 30) { $clase_estado = 'estado-critico'; $texto_estado = 'Moroso (+30 días)'; }
                        elseif ($dias_mora > 15) { $clase_estado = 'estado-advertencia'; $texto_estado = 'Atención (+15 días)'; }

                        $tlf_limpio = preg_replace('/[^0-9]/', '', $telefono);
                        if (strpos($tlf_limpio, '0') === 0) $tlf_limpio = '58' . substr($tlf_limpio, 1);

                        $cli_id = intval($cliente['id']);
                        $texto_wa = "Hola *" . trim($cliente['nombre']) . "*, te saludamos de *" . trim($nombre_mi_negocio) . "* para compartirte el balance de tu cuenta.\n\n🔹 *Cuentas Pendientes:*\n\n";
                        $desglose_arr = [];

                        $sql_tickets = "
                            SELECT v.id, v.total_usd, v.deuda_usd, v.observaciones, v.foto_evidencia,
                                   DATE_FORMAT(v.fecha, '%d/%m/%Y %h:%i %p') as fecha_fmt,
                                   DATE_FORMAT(v.fecha, '%Y-%m-%dT%H:%i') as fecha_iso,
                                   GROUP_CONCAT(CONCAT(TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM dv.cantidad)), 'x ', p.nombre) SEPARATOR ', ') as productos_llevados
                            FROM ventas v
                            LEFT JOIN detalle_ventas dv ON v.id = dv.venta_id AND dv.id_negocio = $id_negocio
                            LEFT JOIN productos p ON dv.producto_id = p.id AND p.id_negocio = $id_negocio
                            WHERE v.cliente_id = $cli_id AND v.deuda_usd > 0 AND v.id_negocio = $id_negocio
                            GROUP BY v.id, v.total_usd, v.deuda_usd, v.observaciones, v.foto_evidencia, v.fecha
                            ORDER BY v.fecha ASC
                        ";
                        $q_tickets = $conexion->query($sql_tickets);
                        if ($q_tickets) {
                            while ($t_wa = $q_tickets->fetch_assoc()) {
                                $id_tick = $t_wa['id'];
                                $es_manual = empty($t_wa['productos_llevados']) ? '1' : '0';
                                $prods_txt = ($es_manual === '0') ? $t_wa['productos_llevados'] : '💵 Préstamo / Crédito Manual';
                                $foto_txt  = $t_wa['foto_evidencia'] ?? '';
                                $obs_txt   = $t_wa['observaciones'] ?? '';

                                $desglose_arr[] = implode('☻', [
                                    $id_tick,
                                    $t_wa['fecha_fmt'],
                                    $t_wa['deuda_usd'],
                                    $prods_txt,
                                    $foto_txt,
                                    $obs_txt,
                                    $es_manual,
                                    $cli_id,
                                    $t_wa['total_usd'],
                                    $t_wa['fecha_iso']
                                ]);

                                $texto_wa .= "• Ticket #" . str_pad($id_tick, 5, '0', STR_PAD_LEFT) . " (del " . $t_wa['fecha_fmt'] . ")\n";
                                $texto_wa .= ($es_manual === '0') ? "   📦 *" . $t_wa['productos_llevados'] . "*\n" : "   💵 *Préstamo / Crédito Manual*\n";
                                if (!empty($obs_txt)) $texto_wa .= "   📝 _Detalle: " . trim($obs_txt) . "_\n";

                                $q_ab_wa = $conexion->query("SELECT monto_usd, metodo_pago, referencia, DATE_FORMAT(fecha, '%d/%m/%Y') as fecha_fmt FROM historico_abonos WHERE venta_id = $id_tick AND id_negocio = $id_negocio ORDER BY fecha ASC");
                                if ($q_ab_wa && $q_ab_wa->num_rows > 0) {
                                    while ($ab = $q_ab_wa->fetch_assoc()) {
                                        $metodo = ucwords(str_replace('_', ' ', str_replace('credito_', '', $ab['metodo_pago'])));
                                        $ref_texto = !empty($ab['referencia']) ? " - Ref: " . $ab['referencia'] : "";
                                        $texto_wa .= "  ↳ Abono: $" . number_format($ab['monto_usd'], 2) . " (" . $metodo . " - " . $ab['fecha_fmt'] . $ref_texto . ")\n";
                                    }
                                }
                                $texto_wa .= "  *-> Resta: $" . number_format($t_wa['deuda_usd'], 2) . "*\n\n";
                            }
                        }
                        $texto_wa .= "💰 *Deuda Total: $" . number_format($deuda_usd, 2) . "* (" . number_format($deuda_bs, 2, ',', '.') . " Bs)";
                        $texto_wa_encoded = rawurlencode($texto_wa);
                        $desglose_compras = implode('◘', $desglose_arr);

                        $abonos_arr = [];
                        $q_ab_hist = $conexion->query("
                            SELECT ha.id, ha.monto_usd, ha.metodo_pago, IFNULL(ha.referencia, '') as referencia,
                                   DATE_FORMAT(ha.fecha, '%d/%m/%Y %h:%i %p') as fecha_fmt
                            FROM historico_abonos ha
                            JOIN ventas v ON ha.venta_id = v.id AND v.id_negocio = $id_negocio
                            WHERE v.cliente_id = $cli_id AND v.deuda_usd > 0 AND ha.id_negocio = $id_negocio
                            ORDER BY ha.fecha DESC
                        ");
                        if ($q_ab_hist) {
                            while ($ab_h = $q_ab_hist->fetch_assoc()) {
                                $abonos_arr[] = implode('☻', [$ab_h['id'], $ab_h['monto_usd'], $ab_h['metodo_pago'], $ab_h['referencia'], $ab_h['fecha_fmt']]);
                            }
                        }
                        $historial_abonos = !empty($abonos_arr) ? implode('◘', $abonos_arr) : 'Ninguno';
                    ?>
                    <tr class="fila-cliente">
                        <td class="dato-cedula"><?php echo htmlspecialchars($cliente['cedula']); ?></td>
                        <td class="dato-nombre" style="font-weight: bold;"><?php echo htmlspecialchars($cliente['nombre']); ?></td>
                        <td>
                            <strong style="color: #ef4444; font-size: 1.1rem;">$<?php echo number_format($deuda_usd, 2); ?></strong><br>
                            <small><?php echo number_format($deuda_bs, 2, ',', '.'); ?> Bs</small>
                        </td>
                        <td><?php echo date('d/m/Y h:i A', strtotime($cliente['ultima_compra'])); ?></td>
                        <td style="font-weight: bold; text-align: center;"><?php echo $dias_mora; ?> días</td>
                        <td><span class="badge-estado <?php echo $clase_estado; ?>"><?php echo $texto_estado; ?></span></td>
                        <td style="display: flex; align-items: center; gap: 0.5rem;">
                            <button class="btn-detalles" onclick="abrirModalDetalles('<?php echo htmlspecialchars($cliente['nombre'], ENT_QUOTES); ?>', '<?php echo number_format($deuda_usd, 2); ?>', '<?php echo htmlspecialchars($desglose_compras, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($historial_abonos, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($telefono, ENT_QUOTES); ?>', '<?php echo $texto_wa_encoded; ?>')">📄 Detalles</button>
                            <button class="btn-cobrar" onclick="abrirModalCobro(<?php echo $cli_id; ?>, '<?php echo htmlspecialchars($cliente['nombre'], ENT_QUOTES); ?>', <?php echo $deuda_usd; ?>, '<?php echo number_format($deuda_bs, 2, ',', '.'); ?>')">💰 Abonar</button>
                            <a href="https://wa.me/<?php echo $tlf_limpio; ?>?text=<?php echo $texto_wa_encoded; ?>" target="_blank" class="btn-cobrar" style="background: #25D366; padding: 0.5rem 0.8rem;" title="Enviar WhatsApp">💬 WA</a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="7" style="text-align: center; padding: 3rem;">No hay deudores registrados.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php if ($es_admin): ?>
    <!-- MODAL: CRÉDITO MANUAL / PRÉSTAMO (SOLO ADMIN) -->
    <div id="modal-credito-manual" class="modal-fondo">
        <div class="modal-caja" style="max-width: 480px; width: 100%; background: white; padding: 1.5rem; border-radius: 10px; border-top: 5px solid #f59e0b;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--borde); padding-bottom: 0.6rem; margin-bottom: 1rem;">
                <h3 style="margin: 0; color: #b45309;">➕ Registrar Crédito Manual / Préstamo</h3>
                <button type="button" onclick="cerrarModal('modal-credito-manual')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">✖</button>
            </div>
            <form method="POST" id="form-credito-manual" onsubmit="return validarEnvioManual(this, 'manual-cliente-id', 'btn-guardar-manual')">
                <input type="hidden" name="crear_credito_manual" value="1">
                <div style="margin-bottom: 0.9rem;" class="autocomplete-wrap">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">👤 Cliente (Buscar por Nombre o Cédula) *</label>
                    <input type="hidden" name="manual_cliente_id" id="manual-cliente-id">
                    <input type="text" id="buscador-cli-manual" autocomplete="off" placeholder="🔍 Escribe nombre o cédula..." oninput="filtrarClientesAutocomplete('manual')" onfocus="filtrarClientesAutocomplete('manual')" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                    <div id="lista-cli-manual" class="autocomplete-lista"></div>
                </div>
                <div style="margin-bottom: 0.9rem;">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">🏷️ Tipo de Movimiento *</label>
                    <select name="manual_tipo" required style="width: 100%; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; background: white; font-size: 0.95rem;">
                        <option value="Préstamo de dinero">💵 Préstamo de dinero</option>
                        <option value="Deuda anterior">📒 Deuda anterior / Saldo viejo</option>
                        <option value="Crédito manual">🧾 Crédito manual</option>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 0.8rem; margin-bottom: 0.9rem;">
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">💰 Monto *</label>
                        <input type="number" step="0.01" min="0.01" name="manual_monto" id="manual-monto" oninput="calcularPreviewManual()" required placeholder="0.00" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 1rem; font-weight: bold;">
                    </div>
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">Moneda</label>
                        <select name="manual_moneda" id="manual-moneda" onchange="calcularPreviewManual()" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-weight: bold; font-size: 1rem;">
                            <option value="usd">USD ($)</option>
                            <option value="bs">Bs</option>
                        </select>
                    </div>
                </div>
                <div style="background: #eff6ff; border: 1px dashed #93c5fd; padding: 0.75rem; border-radius: 6px; margin-bottom: 0.9rem; text-align: center;">
                    <div style="font-size: 0.8rem; color: #1e40af; font-weight: bold;">Equivalente a la tasa actual (<?php echo number_format($tasa, 2, '.', ''); ?> Bs):</div>
                    <div style="font-size: 1.25rem; font-weight: bold; color: #1e3a8a; margin-top: 2px;">
                        <span id="preview-manual-usd">$0.00</span>
                        <span style="font-size: 0.9rem; color: #3b82f6;"> / <span id="preview-manual-bs">0,00 Bs</span></span>
                    </div>
                </div>
                <div style="margin-bottom: 0.9rem;">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">📅 Fecha y Hora</label>
                    <input type="datetime-local" name="manual_fecha" id="manual-fecha" value="<?php echo date('Y-m-d\TH:i'); ?>" required style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                </div>
                <div style="margin-bottom: 1.2rem;">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">📝 Nota / Concepto (Opcional)</label>
                    <input type="text" name="manual_detalle" placeholder="Ej: Préstamo en efectivo, saldo pendiente..." style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                </div>
                <div style="display: flex; gap: 0.8rem;">
                    <button type="submit" id="btn-guardar-manual" class="btn-cobrar" style="flex: 1; background: #f59e0b; padding: 0.75rem; font-size: 1rem;">Guardar Crédito</button>
                    <button type="button" onclick="cerrarModal('modal-credito-manual')" class="btn-detalles" style="flex: 1; background: #94a3b8; margin: 0; padding: 0.75rem; font-size: 1rem;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL: EDITAR CRÉDITO MANUAL (SOLO ADMIN) -->
    <div id="modal-editar-credito-manual" class="modal-fondo" style="z-index: 1100;">
        <div class="modal-caja" style="max-width: 480px; width: 100%; background: white; padding: 1.5rem; border-radius: 10px; border-top: 5px solid #3b82f6;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--borde); padding-bottom: 0.6rem; margin-bottom: 1rem;">
                <h3 style="margin: 0; color: #1e3a8a;">✏️ Editar Crédito Manual</h3>
                <button type="button" onclick="cerrarModal('modal-editar-credito-manual')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">✖</button>
            </div>
            <form method="POST" onsubmit="return validarEnvioManual(this, 'edit-manual-cliente', 'btn-guardar-edit-manual')">
                <input type="hidden" name="editar_credito_manual" value="1">
                <input type="hidden" name="edit_manual_id" id="edit-manual-id">
                <div style="margin-bottom: 0.9rem;" class="autocomplete-wrap">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">👤 Cliente (Buscar por Nombre o Cédula) *</label>
                    <input type="hidden" name="edit_manual_cliente_id" id="edit-manual-cliente">
                    <input type="text" id="buscador-cli-edit" autocomplete="off" placeholder="🔍 Escribe nombre o cédula..." oninput="filtrarClientesAutocomplete('edit')" onfocus="filtrarClientesAutocomplete('edit')" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                    <div id="lista-cli-edit" class="autocomplete-lista"></div>
                </div>
                <div style="display: grid; grid-template-columns: 1.6fr 1fr; gap: 0.8rem; margin-bottom: 0.9rem;">
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">💰 Monto Total *</label>
                        <input type="number" step="0.01" min="0.01" name="edit_manual_monto" id="edit-manual-monto" oninput="calcularPreviewEditManual()" required style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 1rem; font-weight: bold;">
                    </div>
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">Moneda</label>
                        <select name="edit_manual_moneda" id="edit-manual-moneda" onchange="calcularPreviewEditManual()" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; background: #f8fafc; font-weight: bold; font-size: 1rem;">
                            <option value="usd">USD ($)</option>
                            <option value="bs">Bs</option>
                        </select>
                    </div>
                </div>
                <div style="background: #eff6ff; border: 1px dashed #93c5fd; padding: 0.75rem; border-radius: 6px; margin-bottom: 0.9rem; text-align: center;">
                    <div style="font-size: 0.8rem; color: #1e40af; font-weight: bold;">Equivalente a la tasa actual (<?php echo number_format($tasa, 2, '.', ''); ?> Bs):</div>
                    <div style="font-size: 1.25rem; font-weight: bold; color: #1e3a8a; margin-top: 2px;">
                        <span id="preview-edit-manual-usd">$0.00</span>
                        <span style="font-size: 0.9rem; color: #3b82f6;"> / <span id="preview-edit-manual-bs">0,00 Bs</span></span>
                    </div>
                </div>
                <div style="margin-bottom: 0.9rem;">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">📅 Fecha y Hora</label>
                    <input type="datetime-local" name="edit_manual_fecha" id="edit-manual-fecha" required style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                </div>
                <div style="margin-bottom: 1.2rem;">
                    <label style="font-weight: bold; font-size: 0.85rem; display: block; margin-bottom: 0.3rem;">📝 Detalle / Observación</label>
                    <input type="text" name="edit_manual_observaciones" id="edit-manual-obs" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.95rem;">
                </div>
                <div style="display: flex; gap: 0.8rem;">
                    <button type="submit" id="btn-guardar-edit-manual" class="btn-cobrar" style="flex: 1; background: #3b82f6; padding: 0.75rem; font-size: 1rem;">Guardar Cambios</button>
                    <button type="button" onclick="cerrarModal('modal-editar-credito-manual')" class="btn-detalles" style="flex: 1; background: #94a3b8; margin: 0; padding: 0.75rem; font-size: 1rem;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- MODAL DETALLES -->
    <div id="modal-detalles" class="modal-fondo">
        <div class="modal-caja" style="max-width: 600px; width: 100%; background: white; padding: 1.5rem; border-radius: 8px;">
            <div class="modal-cabecera" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--borde); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                <h3 style="margin: 0;">Estado de Cuenta - Lia POS</h3>
                <button class="btn-cerrar" onclick="cerrarModal('modal-detalles')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            <div style="display: flex; flex-direction: column; gap: 1.2rem;">
                <div style="display: flex; justify-content: space-between;">
                    <p style="margin: 0; font-size: 1.1rem; color: var(--texto-secundario);">Cliente: <strong id="detalles-nombre" style="color: var(--primario);">---</strong></p>
                    <p style="margin: 0; font-size: 1.1rem; color: var(--texto-secundario);">Deuda: <strong id="detalles-deuda" style="color: #ef4444; font-size: 1.2rem;">$0.00</strong></p>
                </div>
                <div style="border-left: 4px solid #3b82f6; padding-left: 1rem; max-height: 250px; overflow-y: auto;">
                    <p style="font-weight: bold; color: #1e3a8a; margin-bottom: 1rem;">📦 Compras y Préstamos Pendientes:</p>
                    <div id="detalles-productos" style="line-height: 1.5;"></div>
                </div>
                <div style="border-left: 4px solid #10b981; padding-left: 1rem; max-height: 200px; overflow-y: auto;">
                    <p style="font-weight: bold; color: #064e3b; margin-bottom: 0.5rem;">📉 Historial Abonos:</p>
                    <div id="detalles-abonos" style="line-height: 1.5; color: #334155;"></div>
                </div>
                <p style="text-align: center; font-size: 0.8rem; color: #94a3b8; margin-top: 1rem;">Documento generado el <?php echo date('d/m/Y h:i A'); ?></p>
            </div>
            <div class="botones-accion" style="padding-top: 1rem; margin-top: 1rem; border-top: 1px solid var(--borde); display: flex; gap: 1rem; justify-content: flex-end;">
                <button onclick="window.print()" class="btn-detalles" style="background: #475569;">🖨️ Imprimir</button>
                <a id="btn-whatsapp-cobro" href="#" target="_blank" class="btn-cobrar" style="background: #25D366;">💬 WhatsApp</a>
            </div>
        </div>
    </div>

    <!-- MODAL EDITAR ABONO -->
    <div id="modal-editar-abono" class="modal-fondo">
        <div class="modal-caja" style="max-width: 450px; width: 100%; background: white; padding: 2rem; border-radius: 8px;">
            <h3 style="color: #f59e0b; margin-top: 0; margin-bottom: 1.5rem; border-bottom: 1px solid var(--borde); padding-bottom: 0.5rem;">✏️ Editar Abono</h3>
            <form method="POST">
                <input type="hidden" name="abono_id" id="edit-abono-id">
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem;">Corregir Monto</label>
                        <input type="number" step="0.01" name="monto_abono" id="edit-abono-monto" required style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px;">
                    </div>
                    <div>
                        <label style="font-weight: bold; font-size: 0.85rem;">Moneda</label>
                        <select name="moneda_abono" id="edit-abono-moneda" onchange="actualizarMetodosPagoEditar()" required style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px; font-weight: bold; background: #f8fafc;">
                            <option value="usd">USD ($)</option>
                            <option value="bs">Bs</option>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom: 1rem;">
                    <label style="font-weight: bold; font-size: 0.85rem;">Método de Pago</label>
                    <select name="metodo_pago" id="edit-abono-metodo" required style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px;"></select>
                </div>
                <div style="margin-bottom: 1.5rem;">
                    <label style="font-weight: bold; font-size: 0.85rem;">Referencia</label>
                    <input type="text" name="referencia" id="edit-abono-ref" style="width:100%; box-sizing: border-box; padding:0.8rem; border:1px solid var(--borde); border-radius:4px;">
                </div>
                <div style="display:flex; gap:1rem;">
                    <button type="submit" name="editar_abono" class="btn-cobrar" style="width:100%; background:#f59e0b;">Guardar</button>
                    <button type="button" onclick="cerrarModal('modal-editar-abono')" class="btn-detalles" style="width:100%; background:#94a3b8; margin:0;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL COBRO / ABONO -->
    <div id="modal-cobro" class="modal-fondo">
        <div id="modal-cobro-caja" class="modal-caja" data-deuda="0" style="max-width: 750px; width: 100%; max-height: 95vh; overflow-y: auto; background: white; padding: 1.2rem; border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--borde); padding-bottom: 0.5rem; margin-bottom: 1rem;">
                <h3 style="color: #1e3a8a; margin: 0; font-size: 1.2rem;">Registrar Abono</h3>
                <button type="button" onclick="cerrarModal('modal-cobro')" style="background: none; border: none; font-size: 1.2rem; cursor: pointer;">X</button>
            </div>
            <div style="display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center;">
                <div style="flex: 1;"><p style="margin: 0; color: #64748b; font-size: 0.9rem;">Cliente: <br><strong id="modal-nombre-cliente" style="color: #0f172a; font-size: 1.1rem;"></strong></p></div>
                <div style="flex: 1; background: #fef2f2; border: 1px solid #fecaca; padding: 0.5rem; border-radius: 6px; text-align: center; display: flex; justify-content: center; align-items: baseline; gap: 10px;">
                    <span style="color: #ef4444; font-size: 0.8rem; font-weight: bold;">DEUDA:</span>
                    <span style="color: #b91c1c; font-size: 1.3rem; font-weight: bold;">$<span id="modal-deuda-cliente">0.00</span></span>
                    <span style="color: #64748b; font-size: 0.9rem; font-weight: bold;"><span id="modal-deuda-bs">0,00</span> Bs</span>
                </div>
            </div>
            <form method="POST" onsubmit="document.getElementById('btn-procesar-abono').disabled = true; document.getElementById('btn-procesar-abono').innerText = 'Procesando...';">
                <input type="hidden" name="procesar_abono" value="1">
                <input type="hidden" name="cliente_id" id="modal-cliente-id">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 1rem; border-radius: 6px; margin-bottom: 1rem;">
                    <h4 style="color: #166534; margin-top: 0; margin-bottom: 0.8rem; text-align: center; font-size: 1rem;">🧮 Ingresar Montos</h4>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.6rem; margin-bottom: 0.5rem;">
                        <div><label style="font-size: 0.8rem; font-weight: bold; color: #334155; display: block;">💵 Efectivo ($):</label><input type="number" step="0.01" name="monto_efectivo_usd" class="input-mixto calc-abono" style="padding: 0.4rem;" placeholder="0.00"></div>
                        <div><label style="font-size: 0.8rem; font-weight: bold; color: #334155; display: block;">💵 Efectivo (Bs):</label><input type="number" step="0.01" name="monto_efectivo_bs" class="input-mixto calc-abono" style="padding: 0.4rem;" placeholder="0.00"></div>
                        <div><label style="font-size: 0.8rem; font-weight: bold; color: #334155; display: block;">📱 Pago Móvil (Bs):</label><input type="number" step="0.01" name="monto_pago_movil" class="input-mixto calc-abono" style="padding: 0.4rem;" placeholder="0.00"></div>
                        <div><label style="font-size: 0.8rem; font-weight: bold; color: #334155; display: block;">💳 P. Venta (Bs):</label><input type="number" step="0.01" name="monto_punto" class="input-mixto calc-abono" style="padding: 0.4rem;" placeholder="0.00"></div>
                        <div><label style="font-size: 0.8rem; font-weight: bold; color: #334155; display: block;">👆 Biopago (Bs):</label><input type="number" step="0.01" name="monto_biopago" class="input-mixto calc-abono" style="padding: 0.4rem;" placeholder="0.00"></div>
                    </div>
                    <div style="background: #fffbeb; padding: 0.8rem; border-radius: 6px; border: 1px dashed #fcd34d; margin-bottom: 0.5rem; display: flex; gap: 10px; align-items: flex-end;">
                        <div style="width: 35%;"><label style="font-size: 0.8rem; font-weight: bold; color: #92400e; display: block;">📦 Trueque ($):</label><input type="number" step="0.01" name="monto_trueque" class="input-mixto calc-abono" style="padding: 0.4rem; border: 1px solid #fde047;" placeholder="0.00"></div>
                        <div style="width: 65%;"><label style="font-size: 0.8rem; font-weight: bold; color: #92400e; display: block;">¿Qué entregó?</label><input type="text" name="detalle_trueque" style="width: 100%; box-sizing: border-box; padding: 0.4rem; border: 1px solid #fde047; border-radius: 4px;" placeholder="Ej: Queso, Huevos, Harina..."></div>
                    </div>
                    <div style="background: #e0e7ff; padding: 0.8rem; border-radius: 6px; border: 1px dashed #a5b4fc; margin-bottom: 0.5rem; display: flex; gap: 10px; align-items: flex-end;">
                        <div style="width: 35%;"><label style="font-size: 0.8rem; font-weight: bold; color: #3730a3; display: block;">💸 Zelle/BofA ($):</label><input type="number" step="0.01" name="monto_zelle" class="input-mixto calc-abono" style="padding: 0.4rem; border: 1px solid #818cf8;" placeholder="0.00"></div>
                        <div style="width: 65%;"><label style="font-size: 0.8rem; font-weight: bold; color: #3730a3; display: block;">Detalle:</label><input type="text" name="detalle_zelle" style="width: 100%; box-sizing: border-box; padding: 0.4rem; border: 1px solid #818cf8; border-radius: 4px;" placeholder="Ej: Zelle de Juan, BofA..."></div>
                    </div>
                    <div id="bloque_descuento_condicional" style="display: none;">
                        <div style="background: #e0f2fe; padding: 0.8rem; border-radius: 6px; border: 1px dashed #7dd3fc; margin-bottom: 1rem;">
                            <label style="font-size: 0.9rem; font-weight: bold; color: #0369a1; display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="aplicar_descuento" name="aplicar_descuento" onchange="toggleDescuento()"> ¿Aplicar descuento por pago en dólares/Zelle?
                            </label>
                            <div id="panel_descuento" style="display: none; margin-top: 10px;">
                                <label style="font-size: 0.8rem; font-weight: bold; color: #0369a1; display: block;">Monto a descontar ($):</label>
                                <input type="number" step="0.01" name="monto_descuento" id="monto_descuento" class="input-mixto calc-abono" style="padding: 0.4rem; border: 1px solid #38bdf8;" placeholder="Ej: 2.00">
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 0.5rem; padding-top: 0.8rem; border-top: 2px solid #bbf7d0;">
                        <div id="caja-restante" style="display: flex; justify-content: space-between; align-items: center; background: #eff6ff; border: 1px solid #bfdbfe; padding: 0.6rem 1rem; border-radius: 6px; margin-bottom: 0.8rem;">
                            <span style="color: #1e40af; font-weight: bold; font-size: 0.95rem;">Falta para saldar:</span>
                            <div style="text-align: right;">
                                <span style="color: #1d4ed8; font-weight: bold; font-size: 1.15rem;">$<span id="restante-vivo">0.00</span></span><br>
                                <span style="color: #3b82f6; font-weight: bold; font-size: 0.95rem;"><span id="restante-bs-vivo">0,00</span> Bs</span>
                            </div>
                        </div>
                        <div id="caja-saldado" style="display: none; background: #f0fdf4; border: 1px solid #bbf7d0; padding: 0.8rem; border-radius: 6px; margin-bottom: 0.8rem; color: #166534; font-weight: bold; text-align: center;">✅ ¡Monto exacto! Deuda saldada.</div>
                        <div id="aviso-sobregiro" style="display: none; background: #fef2f2; border: 1px solid #fecaca; padding: 0.8rem; border-radius: 6px; margin-bottom: 0.8rem; color: #dc2626; font-weight: bold; text-align: center;">⚠️ Sobregiro de $<span id="monto-sobregiro">0.00</span></div>
                        <div style="text-align: center;"><strong style="color: #15803d; font-size: 1.1rem;">Total Abonando: $<span id="total-abono-vivo">0.00</span></strong></div>
                    </div>
                </div>
                <div id="contenedor-referencia" style="margin-bottom: 1rem; display: none;">
                    <label style="font-size: 0.8rem; font-weight: bold; color: #334155;">Referencia de Pago Móvil</label>
                    <input type="text" name="referencia_mixta" id="ref_global" placeholder="Ej: 1234" style="width:100%; box-sizing: border-box; padding: 0.5rem; border: 1px solid var(--borde); border-radius: 4px;">
                </div>
                <button type="submit" id="btn-procesar-abono" class="btn-cobrar" style="width: 100%; background: #10b981; font-size: 1rem; padding: 0.6rem;">Procesar Abono</button>
            </form>
        </div>
    </div>

    <script>
        const TASA_GLOBAL = <?php echo json_encode($tasa); ?>;
        const ES_ADMIN = <?php echo $es_admin ? 'true' : 'false'; ?>;
        const CLIENTES_NEGOCIO = <?php echo json_encode($clientes_arr, JSON_UNESCAPED_UNICODE); ?>;

        function cerrarModal(id) { document.getElementById(id).style.display = 'none'; }

        // =========================================================================
        // BUSCADOR AUTOCOMPLETE DE CLIENTES (NOMBRE O CÉDULA)
        // =========================================================================
        function filtrarClientesAutocomplete(tipo) {
            const inpTexto = document.getElementById(tipo === 'manual' ? 'buscador-cli-manual' : 'buscador-cli-edit');
            const inpId    = document.getElementById(tipo === 'manual' ? 'manual-cliente-id' : 'edit-manual-cliente');
            const lista    = document.getElementById(tipo === 'manual' ? 'lista-cli-manual' : 'lista-cli-edit');
            if (!inpTexto || !lista) return;

            const q = inpTexto.value.toLowerCase().trim();
            // Si el usuario borra o modifica el texto, limpiamos el ID hasta que seleccione uno válido
            if (document.activeElement === inpTexto) {
                inpId.value = '';
            }

            const coincidencias = CLIENTES_NEGOCIO.filter(c =>
                (c.nombre || '').toLowerCase().includes(q) ||
                (c.cedula || '').toLowerCase().includes(q)
            ).slice(0, 30);

            lista.innerHTML = '';
            if (coincidencias.length === 0) {
                lista.innerHTML = '<div style="padding:0.7rem; color:#64748b; text-align:center; font-size:0.85rem;">No se encontraron clientes</div>';
            } else {
                coincidencias.forEach(c => {
                    const div = document.createElement('div');
                    div.className = 'autocomplete-item';
                    div.innerHTML = `<strong>${c.nombre}</strong><small>${c.cedula}</small>`;
                    div.onmousedown = function(e) {
                        e.preventDefault();
                        inpId.value = c.id;
                        inpTexto.value = `${c.nombre} (${c.cedula})`;
                        lista.style.display = 'none';
                    };
                    lista.appendChild(div);
                });
            }
            lista.style.display = 'block';
        }

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.autocomplete-wrap')) {
                document.querySelectorAll('.autocomplete-lista').forEach(l => l.style.display = 'none');
            }
        });

        function validarEnvioManual(form, idHidden, idBtn) {
            const cliId = document.getElementById(idHidden)?.value;
            if (!cliId || parseInt(cliId) <= 0) {
                alert('⚠️ Por favor selecciona un cliente válido de la lista desplegable.');
                return false;
            }
            const btn = document.getElementById(idBtn);
            if (btn) {
                if (btn.disabled) return false;
                btn.disabled = true;
                btn.innerText = 'Guardando...';
            }
            return true;
        }

        function abrirModalCreditoManual() {
            const modal = document.getElementById('modal-credito-manual');
            if (!modal) return;
            document.getElementById('manual-cliente-id').value = '';
            document.getElementById('buscador-cli-manual').value = '';
            document.getElementById('lista-cli-manual').style.display = 'none';
            document.getElementById('manual-monto').value = '';
            document.getElementById('manual-moneda').value = 'usd';
            const btn = document.getElementById('btn-guardar-manual');
            if (btn) { btn.disabled = false; btn.innerText = 'Guardar Crédito'; }
            calcularPreviewManual();
            modal.style.display = 'flex';
            setTimeout(() => document.getElementById('buscador-cli-manual').focus(), 80);
        }

        function calcularPreviewManual() {
            const monto = parseFloat(document.getElementById('manual-monto').value) || 0;
            const moneda = document.getElementById('manual-moneda').value;
            let usd = (moneda === 'bs' && TASA_GLOBAL > 0) ? (monto / TASA_GLOBAL) : monto;
            let bs  = (moneda === 'bs') ? monto : (monto * TASA_GLOBAL);
            document.getElementById('preview-manual-usd').innerText = '$' + usd.toFixed(2);
            document.getElementById('preview-manual-bs').innerText = bs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs';
        }

        function abrirModalEditarCreditoManual(id, clienteId, totalUsd, fechaIso, obs) {
            cerrarModal('modal-detalles');
            const modal = document.getElementById('modal-editar-credito-manual');
            if (!modal) return;
            document.getElementById('edit-manual-id').value = id;
            document.getElementById('edit-manual-cliente').value = clienteId;

            const cliObj = CLIENTES_NEGOCIO.find(c => String(c.id) === String(clienteId));
            document.getElementById('buscador-cli-edit').value = cliObj ? `${cliObj.nombre} (${cliObj.cedula})` : '';
            document.getElementById('lista-cli-edit').style.display = 'none';

            document.getElementById('edit-manual-monto').value = parseFloat(totalUsd).toFixed(2);
            document.getElementById('edit-manual-moneda').value = 'usd';
            document.getElementById('edit-manual-fecha').value = fechaIso;
            document.getElementById('edit-manual-obs').value = obs;
            const btn = document.getElementById('btn-guardar-edit-manual');
            if (btn) { btn.disabled = false; btn.innerText = 'Guardar Cambios'; }
            calcularPreviewEditManual();
            modal.style.display = 'flex';
        }

        function calcularPreviewEditManual() {
            const monto = parseFloat(document.getElementById('edit-manual-monto').value) || 0;
            const moneda = document.getElementById('edit-manual-moneda').value;
            let usd = (moneda === 'bs' && TASA_GLOBAL > 0) ? (monto / TASA_GLOBAL) : monto;
            let bs  = (moneda === 'bs') ? monto : (monto * TASA_GLOBAL);
            document.getElementById('preview-edit-manual-usd').innerText = '$' + usd.toFixed(2);
            document.getElementById('preview-edit-manual-bs').innerText = bs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs';
        }

        const buscadorCli = document.getElementById('buscador-clientes');
        if (buscadorCli) {
            buscadorCli.addEventListener('input', function() {
                const filtro = this.value.toLowerCase().trim();
                document.querySelectorAll('#tabla-creditos tbody tr.fila-cliente').forEach(fila => {
                    const cedula = (fila.querySelector('.dato-cedula')?.innerText || '').toLowerCase();
                    const nombre = (fila.querySelector('.dato-nombre')?.innerText || '').toLowerCase();
                    fila.style.display = (cedula.includes(filtro) || nombre.includes(filtro)) ? '' : 'none';
                });
            });
        }

        document.querySelectorAll('.calc-abono').forEach(input => input.addEventListener('input', calcularTotalAbono));

        function toggleDescuento() {
            const check = document.getElementById('aplicar_descuento');
            document.getElementById('panel_descuento').style.display = check.checked ? 'block' : 'none';
            if (!check.checked) document.getElementById('monto_descuento').value = '';
            calcularTotalAbono();
        }

        function calcularTotalAbono() {
            const leer = (n) => parseFloat(document.querySelector(`input[name="${n}"]`)?.value) || 0;
            let usdEfec = leer('monto_efectivo_usd'), usdZelle = leer('monto_zelle'), usdTrueque = leer('monto_trueque');
            let bloqueDesc = document.getElementById('bloque_descuento_condicional');
            if (usdEfec + usdZelle > 0) {
                bloqueDesc.style.display = 'block';
            } else {
                bloqueDesc.style.display = 'none';
                document.getElementById('aplicar_descuento').checked = false;
                document.getElementById('panel_descuento').style.display = 'none';
                document.getElementById('monto_descuento').value = '';
            }

            let totalBs = leer('monto_efectivo_bs') + leer('monto_pago_movil') + leer('monto_punto') + leer('monto_biopago') + leer('monto_transferencia');
            let totalUsd = usdEfec + usdTrueque + usdZelle + (TASA_GLOBAL > 0 ? totalBs / TASA_GLOBAL : 0);
            let usdDesc = document.getElementById('aplicar_descuento').checked ? leer('monto_descuento') : 0;
            let deudaActual = (parseFloat(document.getElementById('modal-cobro-caja').dataset.deuda) || 0) - usdDesc;
            let restante = deudaActual - totalUsd;

            document.getElementById('total-abono-vivo').innerText = totalUsd.toFixed(2);
            document.getElementById('modal-deuda-cliente').innerText = deudaActual.toFixed(2);
            document.getElementById('modal-deuda-bs').innerText = (deudaActual * TASA_GLOBAL).toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            document.getElementById('contenedor-referencia').style.display = leer('monto_pago_movil') > 0 ? 'block' : 'none';

            const btn = document.getElementById('btn-procesar-abono');
            if (restante < -0.001) {
                document.getElementById('caja-restante').style.display = 'none';
                document.getElementById('caja-saldado').style.display = 'none';
                document.getElementById('aviso-sobregiro').style.display = 'block';
                document.getElementById('monto-sobregiro').innerText = Math.abs(restante).toFixed(2);
                btn.style.opacity = '0.4'; btn.style.pointerEvents = 'none';
            } else if (restante > 0.001) {
                document.getElementById('caja-restante').style.display = 'flex';
                document.getElementById('caja-saldado').style.display = 'none';
                document.getElementById('aviso-sobregiro').style.display = 'none';
                document.getElementById('restante-vivo').innerText = restante.toFixed(2);
                document.getElementById('restante-bs-vivo').innerText = (restante * TASA_GLOBAL).toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                btn.style.opacity = '1'; btn.style.pointerEvents = 'auto';
            } else {
                document.getElementById('caja-restante').style.display = 'none';
                document.getElementById('caja-saldado').style.display = 'block';
                document.getElementById('aviso-sobregiro').style.display = 'none';
                btn.style.opacity = '1'; btn.style.pointerEvents = 'auto';
            }
        }

        function abrirModalCobro(clienteId, nombreCliente, deudaTotal, deudaBs) {
            document.getElementById('modal-cliente-id').value = clienteId;
            document.getElementById('modal-nombre-cliente').innerText = nombreCliente;
            document.getElementById('modal-deuda-cliente').innerText = parseFloat(deudaTotal).toFixed(2);
            document.getElementById('modal-deuda-bs').innerText = deudaBs;
            document.getElementById('modal-cobro-caja').dataset.deuda = deudaTotal;
            document.querySelectorAll('.calc-abono').forEach(i => i.value = '');
            document.querySelector('input[name="detalle_trueque"]').value = '';
            document.querySelector('input[name="detalle_zelle"]').value = '';
            document.getElementById('aplicar_descuento').checked = false;
            document.getElementById('bloque_descuento_condicional').style.display = 'none';
            document.getElementById('panel_descuento').style.display = 'none';
            document.getElementById('ref_global').value = '';
            document.getElementById('contenedor-referencia').style.display = 'none';
            const btn = document.getElementById('btn-procesar-abono');
            if (btn) { btn.disabled = false; btn.innerText = 'Procesar Abono'; }
            document.getElementById('modal-cobro').style.display = 'flex';
            calcularTotalAbono();
        }

        function actualizarMetodosPagoEditar() {
            const moneda = document.getElementById('edit-abono-moneda').value;
            const sel = document.getElementById('edit-abono-metodo');
            sel.innerHTML = moneda === 'usd'
                ? `<option value="credito_efectivo_usd">Efectivo $</option><option value="credito_trueque">Trueque / Mercancía</option><option value="credito_zelle">Zelle / Transferencia Int.</option><option value="descuento">Descuento</option><option value="credito_otro">Otro</option>`
                : `<option value="credito_pago_movil">Pago Móvil</option><option value="credito_punto_venta">Punto de Venta</option><option value="credito_biopago">Biopago</option><option value="credito_efectivo_bs">Efectivo Bs</option><option value="credito_transferencia_bs">Transferencia Bs</option><option value="credito_otro">Otro</option>`;
        }
        document.addEventListener("DOMContentLoaded", actualizarMetodosPagoEditar);

        function abrirModalEditarAbono(id, monto, metodo, ref) {
            cerrarModal('modal-detalles');
            document.getElementById('edit-abono-id').value = id;
            document.getElementById('edit-abono-monto').value = monto;
            let esUsd = (metodo.includes('usd') || metodo.includes('trueque') || metodo.includes('otro_') || metodo.includes('zelle') || metodo.includes('descuento'));
            document.getElementById('edit-abono-moneda').value = esUsd ? 'usd' : 'bs';
            actualizarMetodosPagoEditar();
            let sel = document.getElementById('edit-abono-metodo');
            sel.value = Array.from(sel.options).some(o => o.value === metodo) ? metodo : 'credito_otro';
            document.getElementById('edit-abono-ref').value = ref;
            document.getElementById('modal-editar-abono').style.display = 'flex';
        }

        function abrirModalDetalles(nombre, deudaTotal, desglose, abonos, telefono, urlWaCodificada) {
            document.getElementById('detalles-nombre').innerText = nombre;
            document.getElementById('detalles-deuda').innerText = '$' + deudaTotal;
            let htmlCompras = '';
            if (desglose && desglose.trim() !== '') {
                desglose.split('◘').forEach(compra => {
                    let partes = compra.split('☻');
                    if (partes.length >= 4) {
                        let idRaw = partes[0];
                        let id = idRaw.padStart(5, '0'), fecha = partes[1], deudaVenta = parseFloat(partes[2]).toFixed(2), prods = partes[3];
                        let fotoUrl = (partes[4] || '').trim();
                        let btnFoto = fotoUrl ? `<a href="${fotoUrl}" target="_blank" class="btn-foto-print" style="background:#fef08a; color:#854d0e; font-size:0.75rem; padding:2px 6px; border-radius:4px; text-decoration:none; border:1px solid #fde047; margin-left:8px;">📸 Ver Foto</a>` : '';
                        let obs = (partes[5] || '').trim();
                        let bloqueObs = obs ? `<div style="margin-top:5px; padding:4px 8px; background:#fffbeb; border:1px solid #fde68a; border-radius:4px; font-size:0.85rem; color:#b45309;">📝 <b>Detalle / Acuerdo:</b> ${obs}</div>` : '';

                        let esManual = (partes[6] === '1');
                        let cliId = partes[7] || '';
                        let totalUsd = partes[8] || deudaVenta;
                        let fechaIso = partes[9] || '';
                        let obsEscapada = obs.replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/"/g, '&quot;');

                        let botonesManual = '';
                        if (ES_ADMIN && esManual) {
                            botonesManual = `
                                <span class="ocultar-print" style="display:inline-flex; gap:6px; margin-left:8px; vertical-align:middle;">
                                    <button type="button" onclick="abrirModalEditarCreditoManual(${idRaw}, ${cliId}, ${totalUsd}, '${fechaIso}', '${obsEscapada}')" style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px; cursor:pointer; padding:1px 6px; font-size:0.8rem;" title="Editar crédito manual">✏️</button>
                                    <form method="POST" style="margin:0; display:inline;" onsubmit="return confirm('⚠️ ¿Seguro que deseas ELIMINAR este crédito manual #${id}? Se borrará de la deuda del cliente.');">
                                        <input type="hidden" name="elim_manual_id" value="${idRaw}">
                                        <button type="submit" name="eliminar_credito_manual" style="background:#fef2f2; border:1px solid #fecaca; border-radius:4px; cursor:pointer; padding:1px 6px; font-size:0.8rem;" title="Eliminar crédito manual">🗑️</button>
                                    </form>
                                </span>`;
                        }

                        htmlCompras += `<div style="margin-bottom:1rem; border-bottom:1px dashed #cbd5e1; padding-bottom:0.8rem;"><div style="display:flex; justify-content:space-between; align-items:center;"><div><strong style="color:#1e3a8a;">Ticket #${id} <span style="color:#64748b; font-size:0.85rem;">(${fecha})</span></strong> ${btnFoto} ${botonesManual}</div><span style="color:#ef4444; font-weight:bold;">Resta: $${deudaVenta}</span></div><div style="color:#475569;">↳ ${prods}</div>${bloqueObs}</div>`;
                    }
                });
            }
            document.getElementById('detalles-productos').innerHTML = htmlCompras;

            if (abonos === 'Ninguno' || abonos.trim() === '') {
                document.getElementById('detalles-abonos').innerHTML = '<span style="color:#94a3b8; font-style:italic;">No hay abonos previos.</span>';
            } else {
                let htmlAbonos = '';
                abonos.split('◘').forEach(a => {
                    let p = a.split('☻');
                    if (p.length >= 5) {
                        let id_ab = p[0], monto = parseFloat(p[1]).toFixed(2), metodo = p[2], ref = p[3] ? ` (Ref: ${p[3]})` : '', fecha = p[4];
                        let metLimpio = metodo.replace('credito_', '').replace('_', ' ');
                        htmlAbonos += `<div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px dashed #cbd5e1; padding:6px 0;"><span><strong style="color:#10b981;">$${monto}</strong> el ${fecha} <br><small style="color:#64748b; text-transform:capitalize;">${metLimpio}${ref}</small></span><div style="display:flex; gap:8px;" class="ocultar-print"><button onclick="abrirModalEditarAbono(${id_ab}, ${monto}, '${metodo}', '${p[3]}')" style="background:none; border:none; cursor:pointer; font-size:1rem;" title="Editar Abono">✏️</button><form method="POST" style="margin:0;" onsubmit="return confirm('⚠️ ¿Seguro que deseas REVERSAR este pago de $${monto}?');"><input type="hidden" name="abono_id_eliminar" value="${id_ab}"><button type="submit" name="eliminar_abono" style="background:none; border:none; cursor:pointer; font-size:1rem;" title="Reversar Abono">❌</button></form></div></div>`;
                    }
                });
                document.getElementById('detalles-abonos').innerHTML = htmlAbonos;
            }

            let btnWa = document.getElementById('btn-whatsapp-cobro');
            let numLimpio = (telefono || '').replace(/\D/g, '');
            if (numLimpio.startsWith('0')) numLimpio = '58' + numLimpio.substring(1);
            btnWa.href = numLimpio ? `https://wa.me/${numLimpio}?text=${urlWaCodificada}` : `https://wa.me/?text=${urlWaCodificada}`;
            btnWa.style.display = 'inline-block';
            document.getElementById('modal-detalles').style.display = 'flex';
        }
    </script>
</body>
</html>