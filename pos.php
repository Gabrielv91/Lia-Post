<?php
// 1. SEGURIDAD 
session_start();

if (!isset($_SESSION['usuario_id']) || ($_SESSION['usuario_rol'] != 'admin' && $_SESSION['usuario_rol'] != 'vendedor') || !isset($_SESSION['id_negocio'])) {
    header("Location: index.php");
    exit;
}

require_once 'conexion.php';

$id_negocio = $_SESSION['id_negocio']; // <- VARIABLE MULTI-TENANT

// =========================================================================
// ENDPOINT AJAX: CREACIÓN RÁPIDA DE CLIENTE DESDE EL POS
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_cliente_rapido') {
    header('Content-Type: application/json; charset=utf-8');
    $cedula = trim($_POST['cedula'] ?? '');
    $nombre = trim($_POST['nombre'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');

    if ($cedula === '' || $nombre === '') {
        echo json_encode(['success' => false, 'mensaje' => 'La cédula y el nombre son obligatorios.']);
        exit;
    }

    // Verificar si ya existe esa cédula en este negocio
    $stmt_chk = $conexion->prepare("SELECT id, nombre, telefono FROM clientes WHERE cedula = ? AND id_negocio = ?");
    $stmt_chk->bind_param("si", $cedula, $id_negocio);
    $stmt_chk->execute();
    $res_chk = $stmt_chk->get_result();

    if ($res_chk && $res_chk->num_rows > 0) {
        $existente = $res_chk->fetch_assoc();
        $stmt_chk->close();
        echo json_encode([
            'success' => false,
            'mensaje' => 'Ya existe un cliente con esa cédula (' . $existente['nombre'] . ').'
        ]);
        exit;
    }
    $stmt_chk->close();

    $stmt_ins = $conexion->prepare("INSERT INTO clientes (id_negocio, cedula, nombre, telefono) VALUES (?, ?, ?, ?)");
    $stmt_ins->bind_param("isss", $id_negocio, $cedula, $nombre, $telefono);

    if ($stmt_ins->execute()) {
        $nuevo_id = $stmt_ins->insert_id;
        $stmt_ins->close();
        echo json_encode([
            'success' => true,
            'cliente' => [
                'id' => $nuevo_id,
                'cedula' => $cedula,
                'nombre' => $nombre,
                'telefono' => $telefono
            ]
        ]);
    } else {
        $error = $stmt_ins->error;
        $stmt_ins->close();
        echo json_encode(['success' => false, 'mensaje' => 'Error al guardar: ' . $error]);
    }
    exit;
}

// Asegurar que la columna imagen exista por si entran primero a pos.php
$check_img_col = $conexion->query("SHOW COLUMNS FROM productos LIKE 'imagen'");
if ($check_img_col && $check_img_col->num_rows == 0) {
    $conexion->query("ALTER TABLE productos ADD imagen VARCHAR(255) NULL");
}

// 3. Consultar las tasas del día (AISLADO POR NEGOCIO)
$tasa = 1000.00; // Valor por defecto
$tasa_euro = 1.08; // Valor por defecto

$stmt_tasa = $conexion->prepare("SELECT tasa_dia, tasa_euro FROM negocios WHERE id = ?");
$stmt_tasa->bind_param("i", $id_negocio);
$stmt_tasa->execute();
$res_tasa = $stmt_tasa->get_result();

if ($res_tasa && $res_tasa->num_rows > 0) {
    $fila_tasa = $res_tasa->fetch_assoc();
    $tasa = floatval($fila_tasa['tasa_dia']);
    $tasa_euro = isset($fila_tasa['tasa_euro']) ? floatval($fila_tasa['tasa_euro']) : 1.08;
}
$stmt_tasa->close();

// 4. Consultar todos los productos (AISLADO POR NEGOCIO)
$query_productos = "SELECT id, nombre, codigo_barras, precio_usd, precio_mayor_bcv, precio_efectivo_detal, precio_efectivo_mayor, tipo_unidad, stock, imagen FROM productos WHERE id_negocio = $id_negocio ORDER BY nombre ASC";
$resultado_productos = $conexion->query($query_productos);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Punto de Venta</title>
    <link rel="stylesheet" href="css/style.css?v=1.2">
    <style>
        #panel-camara { background: #f8fafc; padding: 1rem; border-radius: 8px; border: 2px dashed #cbd5e1; margin-top: 1rem; text-align: center; }
        #video-camara { width: 100%; border-radius: 6px; background: #000; display: block; }
        #foto-preview { width: 100%; border-radius: 6px; display: none; border: 2px solid #10b981; }

        .selector-tarifa { margin-bottom: 1rem; padding: 0.8rem; background: #f8fafc; border-radius: 8px; border: 1px solid #cbd5e1; }
        .selector-tarifa select { width: 100%; padding: 0.6rem; border-radius: 5px; border: 1px solid #cbd5e1; font-weight: bold; font-size: 1rem; color: #1e3a8a; background: white; }

        .btn-toggle-pos {
            background: #e2e8f0;
            color: #334155;
            border: 1px solid #cbd5e1;
            padding: 0.45rem 0.85rem;
            border-radius: 6px;
            font-weight: bold;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        .btn-toggle-pos.activo {
            background: #10b981;
            color: white;
            border-color: #059669;
        }

        /* ========================================================= */
        /* TARJETAS COMPACTAS PARA QUE QUEPAN MÁS PRODUCTOS          */
        /* ========================================================= */
        .cuadricula-productos {
            display: grid !important;
            grid-template-columns: repeat(auto-fill, minmax(135px, 1fr)) !important;
            gap: 0.55rem !important;
        }
        .tarjeta-producto {
            padding: 0.5rem !important;
            border-radius: 8px !important;
            box-sizing: border-box !important;
        }
        .tarjeta-producto h3 {
            font-size: 0.8rem !important;
            line-height: 1.15 !important;
            margin: 0 0 0.2rem 0 !important;
            min-height: 1.9rem;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
        .tarjeta-producto h3 small {
            font-size: 0.68rem !important;
            font-weight: normal !important;
            display: block;
            margin-top: 1px;
        }
        .tarjeta-producto .precio {
            font-size: 0.78rem !important;
            margin: 0 !important;
            line-height: 1.2 !important;
        }
        .tarjeta-producto .precio-bs {
            font-size: 0.82rem !important;
            margin: 1px 0 3px 0 !important;
            line-height: 1.2 !important;
        }
        .tarjeta-producto .stock-info {
            font-size: 0.72rem !important;
            color: #f59e0b;
            margin: 0 0 0.35rem 0 !important;
            line-height: 1.1 !important;
        }
        .img-producto-pos {
            width: 100%;
            height: 62px;
            object-fit: cover;
            border-radius: 5px;
            margin-bottom: 0.35rem;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .placeholder-producto-pos {
            width: 100%;
            height: 62px;
            border-radius: 5px;
            margin-bottom: 0.35rem;
            background: #f1f5f9;
            border: 1px dashed #cbd5e1;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: #94a3b8;
        }
        .control-clasico input {
            padding: 0.25rem 0.35rem !important;
            font-size: 0.85rem !important;
            min-width: 0;
            width: 45%;
        }
        .control-clasico .btn-agregar {
            padding: 0.3rem 0.45rem !important;
            font-size: 0.8rem !important;
            flex: 1;
        }

        .control-rapido {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 3px;
            width: 100%;
        }
        .btn-stepper {
            width: 28px;
            height: 28px;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .btn-stepper-menos { background: #ef4444; }
        .btn-stepper-menos:active { background: #dc2626; }
        .btn-stepper-mas { background: #10b981; }
        .btn-stepper-mas:active { background: #059669; }
        .input-rapido {
            width: 100%;
            min-width: 30px;
            height: 28px;
            box-sizing: border-box;
            text-align: center;
            font-weight: bold;
            font-size: 0.88rem;
            padding: 0.15rem;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            background: #f8fafc;
            color: #1e3a8a;
        }

        .modal-cliente-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            box-sizing: border-box;
        }
        .modal-cliente-caja {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            width: 100%;
            max-width: 380px;
            box-shadow: 0 15px 30px rgba(0,0,0,0.25);
        }

        .tabs-container { 
            display: flex; 
            background: #1e293b; 
            border-radius: 8px 8px 0 0; 
            padding: 10px 10px 0 10px; 
            overflow-x: auto; 
            align-items: flex-end; 
            margin-bottom: -1px; 
            flex-shrink: 0; 
            min-height: 55px; 
        }
        .tab-wrapper { position: relative; display: flex; align-items: flex-end; }
        .tab-venta { 
            padding: 12px 35px 12px 15px; 
            background: #475569; 
            border: none; 
            border-radius: 6px 6px 0 0; 
            cursor: pointer; 
            color: #f8fafc; 
            font-weight: bold; 
            margin-right: 4px; 
            font-size: 1rem; 
            transition: all 0.2s; 
            white-space: nowrap; 
        }
        .tab-venta:hover { background: #64748b; }
        .tab-venta.activa { 
            background: white; 
            color: #1e3a8a; 
            box-shadow: 0 -4px 6px rgba(0,0,0,0.1); 
            padding-top: 14px; 
        }
        .tab-close {
            position: absolute;
            right: 12px;
            top: 12px;
            cursor: pointer;
            color: #f8fafc;
            font-size: 0.8rem;
            opacity: 0.7;
            background: rgba(0,0,0,0.3);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .tab-close:hover { opacity: 1; background: #ef4444; transform: scale(1.1); }
        .tab-venta.activa + .tab-close { color: #ef4444; background: #fee2e2; top: 14px; }
        .tab-venta.activa + .tab-close:hover { background: #fca5a5; color: #b91c1c; }

        .tab-add { 
            padding: 10px 15px; 
            background: #10b981; 
            border: none; 
            border-radius: 6px 6px 0 0; 
            cursor: pointer; 
            color: white; 
            font-weight: bold; 
            font-size: 1rem; 
            margin-left: auto; 
            transition: transform 0.2s; 
            white-space: nowrap; 
        }
        .tab-add:hover { background: #059669; transform: scale(1.02); }
        .tab-content { 
            background: white; 
            border: 3px solid #1e293b; 
            border-top: none; 
            padding: 1.5rem; 
            border-radius: 0 0 8px 8px; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
            flex-grow: 1; 
            display: flex;
            flex-direction: column;
        }

        @media (max-width: 768px) {
            .top-bar { flex-direction: column; align-items: flex-start !important; padding: 1rem; gap: 1rem; }
            .top-bar > div { width: 100%; justify-content: space-between; }
            .contenedor-principal { display: flex; flex-direction: column; padding: 0.5rem; gap: 1.5rem; }
            .panel-productos, .panel-factura { width: 100% !important; box-sizing: border-box; margin: 0; background: transparent; padding: 0; box-shadow: none; border: none; }
            .tab-content { padding: 1rem; border: 2px solid #1e293b; border-radius: 0 0 8px 8px; }
            .cuadricula-productos { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)) !important; gap: 0.45rem !important; }
            .accion-rapida { display: flex; flex-direction: column; gap: 0.35rem; }
            .control-clasico { display: flex; flex-direction: column; gap: 0.35rem; width: 100%; }
            .control-clasico input, .control-clasico button { width: 100%; box-sizing: border-box; margin: 0; font-size: 14px; }
            .cuadricula-pagos { grid-template-columns: repeat(2, 1fr); gap: 0.5rem; }
            .opciones-pago, .totales { padding: 1rem 0; }
        }
    </style>
</head>
<body>
    <header class="top-bar">
        <div style="display: flex; align-items: center; gap: 0.8rem; flex-wrap: wrap;">
            <a href="menu.php" style="text-decoration: none; font-size: 1.5rem; color: var(--texto-principal);" title="Volver al Menú Principal">⬅️</a>
            <h2 style="margin: 0;">Lia POS</h2>
            <button type="button" id="btn-toggle-mostrar" class="btn-toggle-pos" onclick="toggleMostrarTodos()" title="Mostrar todos los productos o solo al buscar">🔍 Solo Buscar</button>
            <button type="button" id="btn-toggle-rapido" class="btn-toggle-pos" onclick="toggleModoRapido()" title="Cambiar entre botón Añadir o botones rápidos +/-">🔢 Modo Clásico</button>
        </div>

        <div style="display: flex; align-items: center; flex-wrap: wrap;">
            <div style="font-size: 0.95rem; color: var(--texto-secundario); margin-right: 2rem;">
                Cajero: <strong style="color: var(--primario);"><?php echo htmlspecialchars($_SESSION['usuario_nombre']); ?></strong>
            </div>
            
            <div class="tasa-indicador" style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                <span>Tasa Dólar: <strong id="tasa-actual" style="color: #10b981;"><?php echo number_format($tasa, 2, '.', ''); ?> Bs</strong></span>
                <span style="color: #64748b; margin-left: 5px;">| Euro: <strong id="tasa-euro-actual">$<?php echo number_format($tasa_euro, 2, '.', ''); ?></strong></span>
                <?php if($_SESSION['usuario_rol'] == 'admin' || $_SESSION['usuario_rol'] == 'superadmin'): ?>
                    <button onclick="cambiarTasaManual()" style="background: none; border: none; cursor: pointer; font-size: 1.2rem; margin-left: 5px;" title="Actualizar Tasas">✏️</button>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <main class="contenedor-principal">
        <section class="panel-productos">
            <div class="buscador">
                <input type="text" id="buscar-producto" placeholder="Escanear código o buscar nombre..." style="font-size: 16px; width: 100%; box-sizing: border-box;">
            </div>
            
            <div class="cuadricula-productos">
                <?php 
                if ($resultado_productos && $resultado_productos->num_rows > 0):
                    while ($producto = $resultado_productos->fetch_assoc()): 
                        $id = $producto['id'];
                        $nombre = htmlspecialchars($producto['nombre']);
                        $codigo = htmlspecialchars($producto['codigo_barras']);
                        $tipo_unidad = $producto['tipo_unidad'];
                        $stock = floatval($producto['stock']);
                        $imagen = !empty($producto['imagen']) ? htmlspecialchars($producto['imagen']) : '';
                        
                        $p_bcv_detal = floatval($producto['precio_usd']);
                        $p_bcv_mayor = floatval(!empty($producto['precio_mayor_bcv']) ? $producto['precio_mayor_bcv'] : $p_bcv_detal);
                        $p_efec_detal = floatval(!empty($producto['precio_efectivo_detal']) ? $producto['precio_efectivo_detal'] : $p_bcv_detal);
                        $p_efec_mayor = floatval(!empty($producto['precio_efectivo_mayor']) ? $producto['precio_efectivo_mayor'] : $p_bcv_detal);

                        $precio_bs_defecto = $p_bcv_detal * $tasa;
                        $unidad_texto = ($tipo_unidad == 'kg') ? 'Kg' : (($tipo_unidad == 'm') ? 'm' : 'Und');
                        $stock_mostrar = ($tipo_unidad == 'kg' || $tipo_unidad == 'm') ? number_format($stock, 2, ',', '.') : number_format($stock, 0, ',', '.');
                ?>
                        <div class="tarjeta-producto" style="display: none;" 
                             data-id="<?php echo $id; ?>" 
                             data-nombre="<?php echo strtolower($nombre); ?>" 
                             data-codigo="<?php echo strtolower($codigo); ?>"
                             data-precio="<?php echo $p_bcv_detal; ?>" 
                             data-precio-bcv-detal="<?php echo $p_bcv_detal; ?>"
                             data-precio-bcv-mayor="<?php echo $p_bcv_mayor; ?>"
                             data-precio-efectivo-detal="<?php echo $p_efec_detal; ?>"
                             data-precio-efectivo-mayor="<?php echo $p_efec_mayor; ?>"
                             data-unidad="<?php echo $tipo_unidad; ?>"
                             data-stock="<?php echo $stock; ?>"
                             data-imagen="<?php echo $imagen; ?>">
                            
                            <?php if ($imagen !== '' && file_exists($producto['imagen'])): ?>
                                <img src="<?php echo $imagen; ?>" alt="<?php echo $nombre; ?>" class="img-producto-pos" loading="lazy">
                            <?php else: ?>
                                <div class="placeholder-producto-pos">📦</div>
                            <?php endif; ?>

                            <h3 title="<?php echo $nombre; ?>"><?php echo $nombre; ?> <small style="color: #64748b;">(<?php echo $codigo; ?>)</small></h3>
                            <p class="precio" id="render-precio-usd-<?php echo $id; ?>">$<?php echo number_format($p_bcv_detal, 2); ?> / <?php echo $unidad_texto; ?></p>
                            <p class="precio-bs" id="render-precio-bs-<?php echo $id; ?>" style="font-weight: bold; color: #10b981;">
                                <?php echo number_format($precio_bs_defecto, 2, ',', '.'); ?> Bs
                            </p>
                            
                            <p class="stock-info">
                                📦 Disp: <strong><?php echo $stock_mostrar; ?></strong> <?php echo $unidad_texto; ?>
                            </p>
                            
                            <div class="accion-rapida">
                                <!-- Modo Clásico (por defecto) -->
                                <div class="control-clasico" style="display: flex; gap: 0.3rem; width: 100%;">
                                    <?php if ($tipo_unidad == 'kg'): ?>
                                        <input type="number" step="0.001" min="0.001" max="<?php echo $stock; ?>" placeholder="0.400" class="input-cantidad" name="cantidad_<?php echo $id; ?>">
                                    <?php else: ?>
                                        <input type="number" step="1" min="1" max="<?php echo $stock; ?>" value="1" class="input-cantidad" name="cantidad_<?php echo $id; ?>">
                                    <?php endif; ?>
                                    <button type="button" class="btn-agregar">Añadir</button>
                                </div>

                                <!-- Modo Rápido (+/- directo al carrito) -->
                                <div class="control-rapido" data-prod-id="<?php echo $id; ?>">
                                    <button type="button" class="btn-stepper btn-stepper-menos" onclick="ajustarCantidadRapida(<?php echo $id; ?>, -1)">−</button>
                                    <input type="number" min="0" max="<?php echo $stock; ?>" step="<?php echo ($tipo_unidad == 'kg') ? '0.1' : '1'; ?>" value="0" class="input-rapido" id="input-rapido-<?php echo $id; ?>" onchange="establecerCantidadRapida(<?php echo $id; ?>, this.value)">
                                    <button type="button" class="btn-stepper btn-stepper-mas" onclick="ajustarCantidadRapida(<?php echo $id; ?>, 1)">+</button>
                                </div>
                            </div>
                        </div>
                <?php 
                    endwhile; 
                else: 
                ?>
                    <p>No se encontraron productos en la base de datos.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- Contenedor del Ticket Flex -->
        <aside class="panel-factura" style="background: transparent; border: none; padding: 0; box-shadow: none; display: flex; flex-direction: column;">
            
            <div class="tabs-container" id="tabs-ventas">
                <div class="tab-wrapper" data-id="0">
                    <button type="button" class="tab-venta activa" data-id="0" onclick="cambiarPestana(0)">🛒 Venta 1</button>
                    <span class="tab-close" title="Cerrar venta" onclick="cerrarPestana(0, event)">✖</span>
                </div>
                <button type="button" class="tab-add" onclick="agregarPestana()" title="Abrir nueva pestaña de venta">+ Nueva Venta</button>
            </div>
            
            <div class="tab-content">
                <div class="panel-cliente" style="background: white; padding: 1rem; border-radius: 8px; border: 1px solid var(--borde); margin-bottom: 1rem; box-shadow: 0 2px 4px rgba(0,0,0,0.02);">
                    <div id="contenedor-buscador-cliente" style="position: relative;">
                        <label style="font-size: 0.85rem; font-weight: bold; color: var(--texto-secundario); display: block; margin-bottom: 0.5rem;">👤 Asociar Cliente al Ticket:</label>
                        <div style="display: flex; gap: 0.5rem; align-items: stretch;">
                            <input type="text" id="buscador-cliente-pos" placeholder="🔍 Buscar por Cédula o Nombre..." style="flex: 1; min-width: 0; box-sizing: border-box; padding: 0.8rem 1rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 16px; background-color: #f8fafc;" autocomplete="off">
                            <button type="button" onclick="abrirModalNuevoCliente()" style="background: #10b981; color: white; border: none; padding: 0 1rem; border-radius: 6px; font-weight: bold; cursor: pointer; white-space: nowrap; font-size: 0.9rem;" title="Registrar nuevo cliente">➕ Nuevo</button>
                        </div>
                        <div id="sugerencias-clientes" style="position: absolute; top: 100%; left: 0; right: 0; background: white; border: 1px solid var(--borde); border-radius: 4px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); z-index: 100; display: none; max-height: 200px; overflow-y: auto;"></div>
                    </div>

                    <div id="tarjeta-cliente-seleccionado" style="display: none; background: #eff6ff; border: 1px solid #bfdbfe; border-left: 4px solid #3b82f6; padding: 0.8rem; border-radius: 6px; align-items: center; justify-content: space-between;">
                        <div>
                            <div style="font-size: 0.75rem; color: #3b82f6; font-weight: bold; text-transform: uppercase; margin-bottom: 2px;">Cliente Seleccionado</div>
                            <div id="display-cliente-nombre" style="font-weight: bold; color: #1e3a8a; font-size: 1rem;"></div>
                            <div id="display-cliente-cedula" style="font-size: 0.85rem; color: #475569;"></div>
                        </div>
                        <button type="button" onclick="quitarCliente()" style="background: #fee2e2; color: #ef4444; border: none; width: 32px; height: 32px; border-radius: 50%; cursor: pointer; font-weight: bold; display: flex; align-items: center; justify-content: center;" title="Remover cliente">✖</button>
                    </div>
                    <input type="hidden" id="cliente-cedula"><input type="hidden" id="cliente-nombre"><input type="hidden" id="cliente-telefono">
                </div>

                <div class="lista-ticket" id="lista-ticket">
                    <p style="color: #64748b; text-align: center; margin-top: 2rem;">Ningún producto seleccionado</p>
                </div>

                <div class="opciones-pago">
                    <div class="selector-tarifa">
                        <label style="font-size: 0.85rem; font-weight: bold; color: #475569; display:block; margin-bottom:0.3rem;">🎯 Tipo de Tarifa / Precio:</label>
                        <select id="modalidad-tarifa">
                            <option value="bcv_detal">🛒 Precio BCV al Detal</option>
                            <option value="bcv_mayor">📦 Precio BCV al Mayor</option>
                            <option value="efectivo_detal">💵 Precio Efectivo $ Detal</option>
                            <option value="efectivo_mayor">💰 Precio Efectivo $ Mayor</option>
                        </select>
                    </div>

                    <label>Método de Pago:</label>
                    <div class="cuadricula-pagos">
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="efectivo_usd"><div class="contenido-caja">💵 Efectivo $</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="efectivo_bs"><div class="contenido-caja">💵 Efectivo Bs</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="punto_venta"><div class="contenido-caja">💳 Punto Venta</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="pago_movil"><div class="contenido-caja">📱 Pago Móvil</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="biopago"><div class="contenido-caja">👆 Biopago</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="mixto"><div class="contenido-caja">🔄 Pago Mixto</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="otro" data-id="radio-otro"><div class="contenido-caja">💳 Otro</div></label>
                        <label class="caja-pago"><input type="radio" name="metodo-pago" value="credito"><div class="contenido-caja">📒 A Crédito</div></label>
                    </div>

                    <div id="panel-efectivo-usd" style="display: none; margin-top: 1rem; background: #f0fdf4; padding: 1rem; border-radius: 6px; border: 1px solid #bbf7d0;">
                        <h4 style="color: #166534; margin-top:0; margin-bottom: 0.5rem; text-align: center;">💵 Cobro en Dólar Efectivo</h4>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                            <label style="font-weight: bold; font-size: 0.85rem;">Billete Recibido ($):</label>
                            <input type="number" step="0.01" id="recibido-usd" placeholder="Ej: 10" style="padding: 0.4rem; text-align: right; font-weight: bold; width: 100%; box-sizing: border-box; background: white; border: 1px solid #86efac;">
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                            <label style="font-weight: bold; font-size: 0.85rem;">Vuelto Total a dar:</label>
                            <div style="font-weight: bold; color: #ef4444; text-align: right; font-size: 1.1rem;" id="vuelto-total-usd-txt">$0.00</div>
                        </div>

                        <div style="background: #e2e8f0; padding: 0.8rem; border-radius: 6px; margin-bottom: 0.5rem; border: 1px solid #cbd5e1;">
                            <h5 style="margin: 0 0 0.5rem 0; color: #334155; font-size: 0.85rem; text-transform: uppercase;">Desglose del Vuelto</h5>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                                <label style="font-size: 0.8rem; font-weight: bold;">Dólares a entregar ($):</label>
                                <input type="number" step="0.01" id="vuelto-dado-usd" placeholder="Ej: 1" style="padding: 0.4rem; text-align: right; width: 100%; box-sizing: border-box;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                                <label style="font-size: 0.8rem; font-weight: bold;">Falta por entregar:</label>
                                <div style="text-align: right; font-weight: bold; color: #b91c1c;">
                                    <span id="vuelto-falta-usd">$0.00</span> <br>
                                    <span id="vuelto-falta-bs" style="font-size: 0.8rem;">(0.00 Bs)</span>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                                <label style="font-size: 0.8rem; font-weight: bold;">Bolívares a entregar:</label>
                                <input type="number" step="0.01" id="vuelto-dado-bs" placeholder="Ej: 800" style="padding: 0.4rem; text-align: right; width: 100%; box-sizing: border-box;">
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; align-items: center;">
                                <label style="font-size: 0.8rem; font-weight: bold;">Método del vuelto en Bs:</label>
                                <select id="metodo-vuelto-bs" style="padding: 0.4rem; width: 100%; box-sizing: border-box; font-size: 0.85rem;">
                                    <option value="efectivo_bs">💵 Efectivo Bs</option>
                                    <option value="pago_movil">📱 Pago Móvil</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: none; grid-template-columns: 1fr 1fr; gap: 0.5rem; align-items: center;">
                            <label style="font-weight: bold; font-size: 0.85rem; color: #1e3a8a;">Abonar a la cuenta ($):</label>
                            <input type="number" step="0.01" id="abono-usd" placeholder="0.00" style="padding: 0.4rem; text-align: right; width: 100%; box-sizing: border-box; font-weight: bold; border: 1px solid #bfdbfe;">
                        </div>

                        <div style="display: none; text-align: right; font-size: 0.8rem; color: #15803d; font-weight: bold; margin-top: 4px;">
                            Equivalente abono: <span id="abono-usd-en-bs">0.00</span> Bs
                        </div>
                    </div>

                    <div id="panel-efectivo-bs" class="panel-pago" style="display: none;">
                        <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 15px; margin-top: 15px;">
                            <h4 style="text-align: center; color: #166534; margin-top: 0; margin-bottom: 15px;">💵 Cobro en Bolívar Efectivo</h4>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                <label style="font-weight: bold; color: #374151;">Bs Recibidos:</label>
                                <input type="number" id="recibido-bs" class="form-control" style="width: 50%; text-align: right;" placeholder="Ej: 2000">
                            </div>
                            
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; border-bottom: 1px dashed #cbd5e1; padding-bottom: 10px;">
                                <label style="font-weight: bold; color: #374151;">Vuelto Total a dar:</label>
                                <span id="vuelto-total-bs-display" style="font-weight: bold; font-size: 1.1rem; color: #ef4444;">0,00 Bs</span>
                            </div>

                            <div style="background-color: #e2e8f0; border-radius: 6px; padding: 12px;">
                                <h5 style="margin-top: 0; margin-bottom: 10px; font-size: 0.85rem; color: #475569; text-transform: uppercase;">Desglose del Vuelto</h5>
                                
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <label style="font-size: 0.9rem; color: #1e293b;">Dólares a entregar ($):</label>
                                    <input type="number" id="vuelto-dado-usd-desde-bs" class="form-control calc-vuelto-bs" style="width: 50%; text-align: right;" placeholder="Ej: 1">
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <label style="font-size: 0.9rem; color: #1e293b;">Falta por entregar:</label>
                                    <div style="text-align: right;">
                                        <span id="falta-entregar-bs" style="font-weight: bold; color: #ef4444; display: block;">0,00 Bs</span>
                                    </div>
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <label style="font-size: 0.9rem; color: #1e293b;">Bolívares a entregar:</label>
                                    <input type="number" id="vuelto-dado-bs-desde-bs" class="form-control calc-vuelto-bs" style="width: 50%; text-align: right;" placeholder="Ej: 500">
                                </div>

                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <label style="font-size: 0.9rem; color: #1e293b;">Método del vuelto en Bs:</label>
                                    <select id="metodo-vuelto-bs-desde-bs" class="form-control" style="width: 50%;">
                                        <option value="efectivo_bs">💵 Efectivo Bs</option>
                                        <option value="pago_movil">📱 Pago Móvil</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div id="panel-pago-mixto" style="display: none; margin-top: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid #cbd5e1;">
                        <h4 style="color: #1e3a8a; margin-top:0; margin-bottom: 1rem; text-align: center;">🧮 Distribución del Pago Mixto</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💵 Efectivo ($):</label>
                            <input type="number" step="0.01" id="mix-usd" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💵 Efectivo (Bs):</label>
                            <input type="number" step="0.01" id="mix-efec-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">📱 Pago Móvil (Bs):</label>
                            <input type="number" step="0.01" id="mix-pm-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💳 Punto Venta (Bs):</label>
                            <input type="number" step="0.01" id="mix-pv-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                            <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">👆 Biopago (Bs):</label>
                            <input type="number" step="0.01" id="mix-bp-bs" class="input-cliente input-mixto" placeholder="0.00" style="padding: 0.4rem;">
                        </div>
                        <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                            <label style="font-weight: bold; font-size: 0.85rem; width: 40%;">💳 Otro ($):</label>
                            <input type="number" step="0.01" id="mix-otro-usd" class="input-cliente input-mixto" placeholder="0.00" style="width: 25%; padding: 0.4rem;">
                            <input type="text" id="mix-otro-detalle" class="input-cliente" placeholder="Ej: Zelle" style="width: 35%; padding: 0.4rem; font-size: 0.85rem;">
                        </div>
                        <div style="margin-top: 1rem; padding-top: 1rem; border-top: 1px dashed #cbd5e1;">
                            <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 0.9rem;">
                                <span>Total a Pagar:</span>
                                <span id="mix-total-pagar" style="color: #1e3a8a;">0.00 Bs</span>
                            </div>
                            <div style="display: flex; justify-content: space-between; font-weight: bold; font-size: 1rem; margin-top: 0.5rem;">
                                <span>Resta por cobrar:</span>
                                <span id="mix-falta" style="color: #ef4444;">0.00 Bs</span>
                            </div>
                        </div>
                    </div>

                    <div id="panel-otro-detalle" style="display: none; margin-top: 1rem; background: #fffbeb; padding: 1rem; border-radius: 6px; border: 1px solid #fde68a;">
                        <label style="font-weight: bold; color: #d97706; font-size: 0.9rem;">Especifique el método (Zelle, Bofa, etc):</label>
                        <input type="text" id="detalle-otro" placeholder="Zelle, Binance, Trueque..." class="input-cliente" style="margin-top: 0.5rem; background: white; border-color: #fcd34d; font-size: 16px;">
                    </div>

                    <div id="panel-referencia-principal" style="display: none; margin-top: 1rem; background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid var(--borde);">
                        <label style="font-weight: bold; color: var(--texto-secundario); font-size: 0.9rem;">N° de Referencia de Pago Móvil:</label>
                        <input type="text" id="referencia-principal" placeholder="Ingrese los últimos dígitos" class="input-cliente" style="margin-top: 0.5rem; background: white; font-size: 16px;">
                    </div>

                    <div id="panel-credito" class="panel-credito" style="display: none; margin-top: 1rem;">
                        <div id="panel-camara">
                            <label style="font-weight: bold; color: #ef4444; font-size: 0.9rem; margin-bottom: 0.5rem; display: block;">📸 Foto de Evidencia</label>
                            <video id="video-camara" autoplay playsinline></video>
                            <canvas id="canvas-camara" style="display:none;"></canvas>
                            <img id="foto-preview" />
                            <button type="button" id="btn-tomar-foto" class="btn-login" style="background: #3b82f6; margin-top: 0.8rem; width: 100%;">📸 Capturar Foto</button>
                            <button type="button" id="btn-retomar-foto" class="btn-login" style="background: #f59e0b; margin-top: 0.8rem; width: 100%; display:none;">🔄 Volver a Tomar</button>
                            <input type="hidden" id="foto_base64" name="foto_base64">
                        </div>
                        <div style="margin-top: 1.5rem; background: #fffbeb; padding: 1rem; border-radius: 8px; border: 1px solid #fde68a;">
                            <h4 style="color: #d97706; margin-top:0; margin-bottom: 1rem; text-align: center;">📒 Adelanto del Crédito</h4>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💵 Efectivo ($):</label>
                                <input type="number" step="0.01" id="cred-mix-usd" class="input-cliente input-credito-mixto" placeholder="0.00" style="padding: 0.4rem;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💵 Efectivo (Bs):</label>
                                <input type="number" step="0.01" id="cred-mix-efec-bs" class="input-cliente input-credito-mixto" placeholder="0.00" style="padding: 0.4rem;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">📱 Pago Móvil (Bs):</label>
                                <input type="number" step="0.01" id="cred-mix-pm-bs" class="input-cliente input-credito-mixto" placeholder="0.00" style="padding: 0.4rem;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">💳 Punto Venta (Bs):</label>
                                <input type="number" step="0.01" id="cred-mix-pv-bs" class="input-cliente input-credito-mixto" placeholder="0.00" style="padding: 0.4rem;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <label style="font-weight: bold; font-size: 0.85rem; display: flex; align-items: center;">👆 Biopago (Bs):</label>
                                <input type="number" step="0.01" id="cred-mix-bp-bs" class="input-cliente input-credito-mixto" placeholder="0.00" style="padding: 0.4rem;">
                            </div>
                            <div style="display: flex; gap: 0.5rem; margin-bottom: 0.5rem; align-items: center;">
                                <label style="font-weight: bold; font-size: 0.85rem; width: 40%;">💳 Otro ($):</label>
                                <input type="number" step="0.01" id="cred-mix-otro-usd" class="input-cliente input-credito-mixto" placeholder="0.00" style="width: 25%; padding: 0.4rem;">
                                <input type="text" id="cred-mix-otro-detalle" class="input-cliente" placeholder="Ej: Zelle" style="width: 35%; padding: 0.4rem; font-size: 0.85rem;">
                            </div>

                            <input type="text" id="referencia-abono" placeholder="N° Ref (Obligatorio si usó Pago Móvil)" class="input-cliente" style="display: none; margin-top: 0.5rem; margin-bottom: 1rem; width: 100%; box-sizing: border-box; font-size: 15px;">
                            <input type="text" id="observaciones-credito" placeholder="Ej: Crédito a 15 días." class="input-cliente" style="width: 100%; box-sizing: border-box; font-size: 15px; margin-top: 0.5rem;">
                            
                            <div class="deuda-info" style="margin-top: 1rem; text-align: right; border-top: 1px dashed #fcd34d; padding-top: 0.5rem;">
                                Resta por cobrar: <strong id="monto-deuda" style="color: #ef4444; font-size: 1.1rem;">$0.00 / 0,00 Bs</strong>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="totales" style="margin-top: auto;">
                    <div class="total-fila subtotal"><span>Subtotal USD:</span><span id="total-usd">$0.00</span></div>
                    <div class="total-fila gran-total"><span>Total a Pagar:</span><span id="total-bs">0.00 Bs</span></div>
                    <button class="btn-facturar btn-login" style="width: 100%; margin-top: 1rem; font-size: 1.2rem; padding: 1rem;">Procesar Venta</button>
                </div>
            </div>
        </aside>
    </main>

    <!-- MODAL: REGISTRO RÁPIDO DE CLIENTE -->
    <div id="modal-nuevo-cliente" class="modal-cliente-overlay">
        <div class="modal-cliente-caja">
            <h3 style="margin-top: 0; margin-bottom: 1rem; color: #1e3a8a; border-bottom: 2px solid #f1f5f9; padding-bottom: 0.5rem;">👤 Registrar Nuevo Cliente</h3>
            <div style="margin-bottom: 0.8rem;">
                <label style="font-size: 0.85rem; font-weight: bold; color: #334155; display: block; margin-bottom: 0.3rem;">Cédula o RIF *</label>
                <input type="text" id="nuevo-cli-cedula" placeholder="Ej: V-12345678" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 16px;">
            </div>
            <div style="margin-bottom: 0.8rem;">
                <label style="font-size: 0.85rem; font-weight: bold; color: #334155; display: block; margin-bottom: 0.3rem;">Nombre Completo *</label>
                <input type="text" id="nuevo-cli-nombre" placeholder="Ej: Juan Pérez" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 16px;">
            </div>
            <div style="margin-bottom: 1.2rem;">
                <label style="font-size: 0.85rem; font-weight: bold; color: #334155; display: block; margin-bottom: 0.3rem;">WhatsApp / Celular</label>
                <input type="text" id="nuevo-cli-telefono" placeholder="Ej: 04141234567" style="width: 100%; box-sizing: border-box; padding: 0.7rem; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 16px;">
            </div>
            <div id="nuevo-cli-error" style="display: none; color: #b91c1c; background: #fee2e2; padding: 0.6rem; border-radius: 6px; font-size: 0.85rem; margin-bottom: 1rem; font-weight: bold;"></div>
            <div style="display: flex; gap: 0.8rem;">
                <button type="button" id="btn-guardar-cli-rapido" onclick="guardarClienteRapido()" class="btn-login" style="flex: 1; background: #10b981; margin: 0; padding: 0.75rem;">Guardar Cliente</button>
                <button type="button" onclick="cerrarModalNuevoCliente()" class="btn-login" style="flex: 1; background: #94a3b8; margin: 0; padding: 0.75rem;">Cancelar</button>
            </div>
        </div>
    </div>

    <script src="js/main.js?v=1.2"></script>
    <script>
        'use strict';
        
        let streamCamara = null;
        let modoCamara = 'environment'; 
        const tasaDelDia = <?php echo floatval($tasa); ?>;
        const idNegocioActual = <?php echo intval($id_negocio); ?>;

        // =========================================================================
        // PREFERENCIAS GUARDADAS (VER TODOS + MODO RÁPIDO +/-)
        // =========================================================================
        const KEY_MOSTRAR_TODOS = 'pos_mostrar_todos_' + idNegocioActual;
        const KEY_MODO_RAPIDO   = 'pos_modo_rapido_' + idNegocioActual;

        let mostrarTodosActivo = localStorage.getItem(KEY_MOSTRAR_TODOS) === 'true';
        let modoRapidoActivo   = localStorage.getItem(KEY_MODO_RAPIDO) === 'true';

        function capturarScrollPos() {
            const panel = document.querySelector('.panel-productos');
            const grid  = document.querySelector('.cuadricula-productos');
            return {
                winY: window.scrollY || document.documentElement.scrollTop || 0,
                panelY: panel ? panel.scrollTop : 0,
                gridY: grid ? grid.scrollTop : 0
            };
        }

        function restaurarScrollPos(pos) {
            if (!pos) return;
            const panel = document.querySelector('.panel-productos');
            const grid  = document.querySelector('.cuadricula-productos');
            const buscador = document.getElementById('buscar-producto');

            if (mostrarTodosActivo && buscador && document.activeElement === buscador) {
                buscador.blur();
            }
            window.scrollTo(0, pos.winY);
            if (panel) panel.scrollTop = pos.panelY;
            if (grid)  grid.scrollTop  = pos.gridY;
        }

        function aplicarPreferenciaMostrar() {
            const btn = document.getElementById('btn-toggle-mostrar');
            const buscador = document.getElementById('buscar-producto');
            const textoBusqueda = buscador ? buscador.value.trim() : '';

            if (btn) {
                if (mostrarTodosActivo) {
                    btn.classList.add('activo');
                    btn.innerText = '👁️ Ver Todos';
                } else {
                    btn.classList.remove('activo');
                    btn.innerText = '🔍 Solo Buscar';
                }
            }

            if (textoBusqueda === '') {
                document.querySelectorAll('.tarjeta-producto').forEach(t => {
                    t.style.display = mostrarTodosActivo ? '' : 'none';
                });
            }
        }

        function toggleMostrarTodos() {
            mostrarTodosActivo = !mostrarTodosActivo;
            localStorage.setItem(KEY_MOSTRAR_TODOS, mostrarTodosActivo ? 'true' : 'false');
            aplicarPreferenciaMostrar();
        }

        function aplicarPreferenciaModoRapido() {
            const btn = document.getElementById('btn-toggle-rapido');
            if (btn) {
                if (modoRapidoActivo) {
                    btn.classList.add('activo');
                    btn.innerText = '⚡ Modo +/-';
                } else {
                    btn.classList.remove('activo');
                    btn.innerText = '🔢 Modo Clásico';
                }
            }

            document.querySelectorAll('.control-clasico').forEach(el => {
                el.style.display = modoRapidoActivo ? 'none' : 'flex';
            });
            document.querySelectorAll('.control-rapido').forEach(el => {
                el.style.display = modoRapidoActivo ? 'flex' : 'none';
            });

            sincronizarContadoresRapidos();
        }

        function toggleModoRapido() {
            modoRapidoActivo = !modoRapidoActivo;
            localStorage.setItem(KEY_MODO_RAPIDO, modoRapidoActivo ? 'true' : 'false');
            aplicarPreferenciaModoRapido();
        }

        function sincronizarContadoresRapidos() {
            document.querySelectorAll('.input-rapido').forEach(inp => inp.value = 0);
            if (typeof window.carrito !== 'undefined' && Array.isArray(window.carrito)) {
                window.carrito.forEach(item => {
                    const inp = document.getElementById('input-rapido-' + item.id);
                    if (inp) {
                        inp.value = parseFloat(item.cantidad) || 0;
                    }
                });
            }
        }

        function ajustarCantidadRapida(idProd, delta) {
            const inp = document.getElementById('input-rapido-' + idProd);
            const actual = parseFloat(inp ? inp.value : 0) || 0;
            const nueva = Math.max(0, Math.round((actual + delta) * 1000) / 1000);
            establecerCantidadRapida(idProd, nueva);
        }

        function establecerCantidadRapida(idProd, nuevaCantidad) {
            const tarjeta = document.querySelector(`.tarjeta-producto[data-id="${idProd}"]`);
            if (!tarjeta) return;

            const stockDisp = parseFloat(tarjeta.getAttribute('data-stock')) || 0;
            let cant = parseFloat(nuevaCantidad);
            if (isNaN(cant) || cant < 0) cant = 0;

            if (cant > stockDisp) {
                alert(`⚠️ Stock insuficiente. Solo hay ${stockDisp} disponible(s) en inventario.`);
                cant = stockDisp;
            }

            const inp = document.getElementById('input-rapido-' + idProd);
            if (inp) inp.value = cant;

            if (typeof window.carrito === 'undefined' || !Array.isArray(window.carrito)) return;

            const scrollGuardado = capturarScrollPos();
            const idx = window.carrito.findIndex(item => String(item.id) === String(idProd));

            if (cant === 0) {
                if (idx !== -1) {
                    window.carrito.splice(idx, 1);
                }
            } else if (idx !== -1) {
                const item = window.carrito[idx];
                item.cantidad = cant;
                const precioUnit = parseFloat(tarjeta.getAttribute('data-precio')) || 0;
                if (item.subtotal !== undefined) item.subtotal = precioUnit * cant;
            } else {
                const inputClasico = tarjeta.querySelector('.input-cantidad');
                const btnClasico = tarjeta.querySelector('.btn-agregar');
                if (inputClasico && btnClasico) {
                    inputClasico.value = cant;
                    btnClasico.click();
                    inputClasico.value = (tarjeta.getAttribute('data-unidad') === 'kg') ? '' : '1';
                }
            }

            aplicarPreferenciaMostrar();
            if (typeof window.actualizarTotalesCarrito === 'function') window.actualizarTotalesCarrito();
            if (typeof window.actualizarTicket === 'function') window.actualizarTicket();
            restaurarScrollPos(scrollGuardado);

            setTimeout(() => {
                corregirRedondeoGlobal();
                sincronizarContadoresRapidos();
                aplicarPreferenciaMostrar();
                restaurarScrollPos(scrollGuardado);
            }, 40);
        }

        // =========================================================================
        // MODAL Y REGISTRO RÁPIDO DE CLIENTE
        // =========================================================================
        function abrirModalNuevoCliente(textoInicial = '') {
            const buscadorVal = textoInicial || (document.getElementById('buscador-cliente-pos')?.value.trim() || '');
            const inpCedula = document.getElementById('nuevo-cli-cedula');
            const inpNombre = document.getElementById('nuevo-cli-nombre');
            const inpTel    = document.getElementById('nuevo-cli-telefono');
            const divErr    = document.getElementById('nuevo-cli-error');

            inpCedula.value = '';
            inpNombre.value = '';
            inpTel.value    = '';
            divErr.style.display = 'none';

            if (buscadorVal !== '') {
                if (/^[vjeVJE]?-?\d+$/.test(buscadorVal)) {
                    inpCedula.value = buscadorVal;
                } else {
                    inpNombre.value = buscadorVal;
                }
            }

            document.getElementById('modal-nuevo-cliente').style.display = 'flex';
            setTimeout(() => {
                if (inpCedula.value === '') inpCedula.focus();
                else inpNombre.focus();
            }, 80);
        }

        function cerrarModalNuevoCliente() {
            document.getElementById('modal-nuevo-cliente').style.display = 'none';
        }

        function guardarClienteRapido() {
            const cedula   = document.getElementById('nuevo-cli-cedula').value.trim();
            const nombre   = document.getElementById('nuevo-cli-nombre').value.trim();
            const telefono = document.getElementById('nuevo-cli-telefono').value.trim();
            const divErr   = document.getElementById('nuevo-cli-error');
            const btn      = document.getElementById('btn-guardar-cli-rapido');

            if (cedula === '' || nombre === '') {
                divErr.innerText = '⚠️ Por favor ingresa Cédula y Nombre Completo.';
                divErr.style.display = 'block';
                return;
            }

            divErr.style.display = 'none';
            btn.disabled = true;
            btn.innerText = 'Guardando...';

            const formData = new URLSearchParams();
            formData.append('accion', 'crear_cliente_rapido');
            formData.append('cedula', cedula);
            formData.append('nombre', nombre);
            formData.append('telefono', telefono);

            fetch('pos.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerText = 'Guardar Cliente';

                if (data.success && data.cliente) {
                    document.getElementById('cliente-cedula').value = data.cliente.cedula;
                    document.getElementById('cliente-nombre').value = data.cliente.nombre;
                    document.getElementById('cliente-telefono').value = data.cliente.telefono;
                    document.getElementById('display-cliente-nombre').innerText = data.cliente.nombre;
                    document.getElementById('display-cliente-cedula').innerText = data.cliente.cedula;
                    document.getElementById('tarjeta-cliente-seleccionado').style.display = 'flex';
                    document.getElementById('contenedor-buscador-cliente').style.display = 'none';
                    const sug = document.getElementById('sugerencias-clientes');
                    if (sug) sug.style.display = 'none';

                    cerrarModalNuevoCliente();
                } else {
                    divErr.innerText = '❌ ' + (data.mensaje || 'No se pudo guardar el cliente.');
                    divErr.style.display = 'block';
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerText = 'Guardar Cliente';
                divErr.innerText = '❌ Error de conexión al guardar el cliente.';
                divErr.style.display = 'block';
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            const sugBox = document.getElementById('sugerencias-clientes');
            const inpBusqCli = document.getElementById('buscador-cliente-pos');
            if (sugBox && inpBusqCli) {
                const obsCli = new MutationObserver(() => {
                    const txt = inpBusqCli.value.trim();
                    const contenido = sugBox.innerText.toLowerCase();
                    if (txt.length >= 2 && (contenido.includes('no se encontr') || contenido.includes('sin resultados') || sugBox.children.length === 0)) {
                        if (!sugBox.querySelector('.btn-crear-desde-sug')) {
                            const divCrear = document.createElement('div');
                            divCrear.className = 'btn-crear-desde-sug';
                            divCrear.style.cssText = 'padding: 0.7rem; background: #f0fdf4; border-top: 1px solid #bbf7d0; text-align: center; cursor: pointer; color: #166534; font-weight: bold; font-size: 0.9rem;';
                            divCrear.innerHTML = `➕ ¿No existe? Crear cliente "${txt}"`;
                            divCrear.onclick = () => abrirModalNuevoCliente(txt);
                            sugBox.appendChild(divCrear);
                            sugBox.style.display = 'block';
                        }
                    }
                });
                obsCli.observe(sugBox, { childList: true, subtree: true });
            }

            const buscadorProd = document.getElementById('buscar-producto');
            if (buscadorProd) {
                buscadorProd.addEventListener('input', () => {
                    setTimeout(aplicarPreferenciaMostrar, 30);
                });
            }

            document.querySelectorAll('.btn-agregar').forEach(btn => {
                btn.addEventListener('click', () => {
                    const scrollGuardado = capturarScrollPos();
                    setTimeout(() => {
                        aplicarPreferenciaMostrar();
                        if (modoRapidoActivo) sincronizarContadoresRapidos();
                        restaurarScrollPos(scrollGuardado);
                    }, 40);
                });
            });

            const listaTicket = document.getElementById('lista-ticket');
            if (listaTicket) {
                const obsTicket = new MutationObserver(() => {
                    if (modoRapidoActivo) sincronizarContadoresRapidos();
                });
                obsTicket.observe(listaTicket, { childList: true, subtree: true });
            }

            aplicarPreferenciaMostrar();
            aplicarPreferenciaModoRapido();
        });

        function limpiarInputsPagoYCamara(fotoGuardada = '') {
            ['recibido-usd', 'vuelto-dado-usd', 'vuelto-dado-bs', 'abono-usd',
             'recibido-bs', 'vuelto-dado-usd-desde-bs', 'vuelto-dado-bs-desde-bs',
             'detalle-otro', 'referencia-principal',
             'mix-usd', 'mix-efec-bs', 'mix-pm-bs', 'mix-pv-bs', 'mix-bp-bs', 'mix-otro-usd', 'mix-otro-detalle',
             'cred-mix-usd', 'cred-mix-efec-bs', 'cred-mix-pm-bs', 'cred-mix-pv-bs', 'cred-mix-bp-bs', 'cred-mix-otro-usd', 'cred-mix-otro-detalle',
             'referencia-abono', 'observaciones-credito'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });

            const selVueltoUsd = document.getElementById('metodo-vuelto-bs');
            if (selVueltoUsd) selVueltoUsd.value = 'efectivo_bs';
            const selVueltoBs = document.getElementById('metodo-vuelto-bs-desde-bs');
            if (selVueltoBs) selVueltoBs.value = 'efectivo_bs';

            if (document.getElementById('vuelto-total-usd-txt')) document.getElementById('vuelto-total-usd-txt').innerText = '$0.00';
            if (document.getElementById('vuelto-falta-usd')) document.getElementById('vuelto-falta-usd').innerText = '$0.00';
            if (document.getElementById('vuelto-falta-bs')) document.getElementById('vuelto-falta-bs').innerText = '(0.00 Bs)';
            if (document.getElementById('abono-usd-en-bs')) document.getElementById('abono-usd-en-bs').innerText = '0.00';
            if (document.getElementById('vuelto-total-bs-display')) document.getElementById('vuelto-total-bs-display').innerText = '0,00 Bs';
            if (document.getElementById('falta-entregar-bs')) document.getElementById('falta-entregar-bs').innerText = '0,00 Bs';
            if (document.getElementById('mix-total-pagar')) document.getElementById('mix-total-pagar').innerText = '0.00 Bs';
            if (document.getElementById('mix-falta')) document.getElementById('mix-falta').innerText = '0.00 Bs';
            if (document.getElementById('monto-deuda')) document.getElementById('monto-deuda').innerText = '$0.00 / 0,00 Bs';
            if (document.getElementById('referencia-abono')) document.getElementById('referencia-abono').style.display = 'none';

            const video = document.getElementById('video-camara');
            const preview = document.getElementById('foto-preview');
            const inputBase64 = document.getElementById('foto_base64');
            const btnTomar = document.getElementById('btn-tomar-foto');
            const btnRetomar = document.getElementById('btn-retomar-foto');

            if (fotoGuardada && fotoGuardada !== '') {
                if (preview) { preview.src = fotoGuardada; preview.style.display = 'block'; }
                if (video) video.style.display = 'none';
                if (inputBase64) inputBase64.value = fotoGuardada;
                if (btnTomar) btnTomar.style.display = 'none';
                if (btnRetomar) btnRetomar.style.display = 'block';
            } else {
                if (preview) { preview.src = ''; preview.style.display = 'none'; }
                if (video) video.style.display = 'block';
                if (inputBase64) inputBase64.value = '';
                if (btnTomar) btnTomar.style.display = 'block';
                if (btnRetomar) btnRetomar.style.display = 'none';
            }
        }

        let pestañas = {
            0: { carrito: [], htmlTicket: '', clienteCedula: '', clienteNombre: '', clienteTelefono: '', metodoPago: '', tarifa: 'bcv_detal', fotoBase64: '', subtotal: '$0.00', total: '0.00 Bs' }
        };
        let pestanaActual = 0;
        let contadorPestanas = 0;

        function guardarEstadoActual() {
            if (pestañas[pestanaActual]) {
                pestañas[pestanaActual].carrito = typeof window.carrito !== 'undefined' ? JSON.parse(JSON.stringify(window.carrito)) : [];
                
                let divTicket = document.getElementById('lista-ticket');
                pestañas[pestanaActual].htmlTicket = divTicket ? divTicket.innerHTML : '';
                pestañas[pestanaActual].subtotal = document.getElementById('total-usd').innerText;
                pestañas[pestanaActual].total = document.getElementById('total-bs').innerText;

                pestañas[pestanaActual].clienteCedula = document.getElementById('cliente-cedula').value;
                pestañas[pestanaActual].clienteNombre = document.getElementById('cliente-nombre').value;
                pestañas[pestanaActual].clienteTelefono = document.getElementById('cliente-telefono').value;
                
                const selectTarifa = document.getElementById('modalidad-tarifa');
                pestañas[pestanaActual].tarifa = selectTarifa ? selectTarifa.value : 'bcv_detal';

                const inputFoto = document.getElementById('foto_base64');
                pestañas[pestanaActual].fotoBase64 = inputFoto ? inputFoto.value : '';

                const radioP = document.querySelector('input[name="metodo-pago"]:checked');
                pestañas[pestanaActual].metodoPago = radioP ? radioP.value : '';
            }
        }

        function cargarEstado(id) {
            let estado = pestañas[id];
            if (!estado) return;
            
            if (typeof window.carrito !== 'undefined') {
                window.carrito.length = 0;
                if(estado.carrito) estado.carrito.forEach(item => window.carrito.push(item));
            }

            let divTicket = document.getElementById('lista-ticket');
            if (divTicket) {
                divTicket.innerHTML = estado.htmlTicket !== '' ? estado.htmlTicket : '<p style="color: #64748b; text-align: center; margin-top: 2rem;">Ningún producto seleccionado</p>';
            }
            document.getElementById('total-usd').innerText = estado.subtotal || '$0.00';
            document.getElementById('total-bs').innerText = estado.total || '0.00 Bs';

            document.getElementById('cliente-cedula').value = estado.clienteCedula || '';
            document.getElementById('cliente-nombre').value = estado.clienteNombre || '';
            document.getElementById('cliente-telefono').value = estado.clienteTelefono || '';
            if (estado.clienteNombre && estado.clienteNombre !== '') {
                document.getElementById('display-cliente-nombre').innerText = estado.clienteNombre;
                document.getElementById('display-cliente-cedula').innerText = estado.clienteCedula;
                document.getElementById('tarjeta-cliente-seleccionado').style.display = 'flex';
                document.getElementById('contenedor-buscador-cliente').style.display = 'none';
            } else {
                document.getElementById('display-cliente-nombre').innerText = '';
                document.getElementById('display-cliente-cedula').innerText = '';
                document.getElementById('tarjeta-cliente-seleccionado').style.display = 'none';
                document.getElementById('contenedor-buscador-cliente').style.display = 'block';
                document.getElementById('buscador-cliente-pos').value = '';
                const sug = document.getElementById('sugerencias-clientes');
                if (sug) sug.style.display = 'none';
            }

            const selectTarifa = document.getElementById('modalidad-tarifa');
            if (selectTarifa) {
                selectTarifa.value = estado.tarifa || 'bcv_detal';
                recalcularPreciosPorTarifa();
            }

            limpiarInputsPagoYCamara(estado.fotoBase64 || '');

            document.querySelectorAll('input[name="metodo-pago"]').forEach(r => r.checked = false);
            if (estado.metodoPago && estado.metodoPago !== '') {
                let rd = document.querySelector(`input[name="metodo-pago"][value="${estado.metodoPago}"]`);
                if(rd) rd.checked = true;
            }
            alternarPanelesPago();

            if (typeof window.actualizarTicket === 'function') window.actualizarTicket();
            setTimeout(() => {
                corregirRedondeoGlobal();
                sincronizarContadoresRapidos();
            }, 50);

            let buscador = document.getElementById('buscar-producto');
            if (buscador) {
                aplicarPreferenciaMostrar();
                if (!mostrarTodosActivo) buscador.focus();
            }
        }

        function cambiarPestana(id) {
            guardarEstadoActual();
            document.querySelectorAll('.tab-venta').forEach(t => t.classList.remove('activa'));
            let btn = document.querySelector(`.tab-venta[data-id="${id}"]`);
            if(btn) btn.classList.add('activa');
            pestanaActual = id;
            cargarEstado(id);
        }

        function agregarPestana() {
            guardarEstadoActual();
            contadorPestanas++;
            let nuevoId = contadorPestanas;
            
            pestañas[nuevoId] = { carrito: [], htmlTicket: '', clienteCedula: '', clienteNombre: '', clienteTelefono: '', metodoPago: '', tarifa: 'bcv_detal', fotoBase64: '', subtotal: '$0.00', total: '0.00 Bs' };
            
            let wrapper = document.createElement('div');
            wrapper.className = 'tab-wrapper';
            wrapper.setAttribute('data-id', nuevoId);

            let btn = document.createElement('button');
            btn.type = 'button'; 
            btn.className = 'tab-venta';
            btn.setAttribute('data-id', nuevoId);
            btn.innerHTML = '🛒 Venta ' + (nuevoId + 1);
            btn.onclick = () => cambiarPestana(nuevoId);

            let closeBtn = document.createElement('span');
            closeBtn.className = 'tab-close';
            closeBtn.innerHTML = '✖';
            closeBtn.title = 'Cerrar venta';
            closeBtn.onclick = (e) => {
                e.stopPropagation();
                cerrarPestana(nuevoId);
            };

            wrapper.appendChild(btn);
            wrapper.appendChild(closeBtn);

            let container = document.getElementById('tabs-ventas');
            container.insertBefore(wrapper, document.querySelector('.tab-add'));
            
            cambiarPestana(nuevoId);
        }

        function cerrarPestana(id, evento) {
            if(evento) evento.stopPropagation();
            
            let numPestanas = Object.keys(pestañas).length;
            if (numPestanas <= 1) {
                alert("⚠️ Debe haber al menos una pestaña de venta abierta.");
                return;
            }

            if(confirm("¿Seguro que deseas eliminar y vaciar esta venta?")) {
                let wrapper = document.querySelector(`.tab-wrapper[data-id="${id}"]`);
                if(wrapper) wrapper.remove();
                delete pestañas[id];

                if (pestanaActual === id) {
                    let keys = Object.keys(pestañas);
                    let lastKey = keys[keys.length - 1];
                    cambiarPestana(parseInt(lastKey));
                }
            }
        }

        document.querySelector('.tab-venta[data-id="0"]').onclick = () => cambiarPestana(0);

        window.ventaCompletada = function() {
            let numPestanas = Object.keys(pestañas).length;
            if (numPestanas > 1) {
                let id = pestanaActual;
                let wrapper = document.querySelector(`.tab-wrapper[data-id="${id}"]`);
                if(wrapper) wrapper.remove();
                delete pestañas[id];

                let keys = Object.keys(pestañas);
                let lastKey = keys[keys.length - 1];
                cambiarPestana(parseInt(lastKey));
            } else {
                pestañas[pestanaActual] = { 
                    carrito: [], 
                    htmlTicket: '', 
                    clienteCedula: '', 
                    clienteNombre: '', 
                    clienteTelefono: '', 
                    metodoPago: '', 
                    tarifa: 'bcv_detal', 
                    fotoBase64: '', 
                    subtotal: '$0.00', 
                    total: '0.00 Bs' 
                };
                if (typeof window.quitarCliente === 'function') {
                    try { window.quitarCliente(); } catch(e) {}
                }
                cargarEstado(pestanaActual);
            }
            
            setTimeout(() => {
                let buscador = document.getElementById('buscar-producto');
                if(buscador) {
                    buscador.value = '';
                    aplicarPreferenciaMostrar();
                    if (!mostrarTodosActivo) buscador.focus();
                }
                sincronizarContadoresRapidos();
            }, 300);
        };

        window.addEventListener('focus', function() {
            setTimeout(() => {
                let buscador = document.getElementById('buscar-producto');
                const modalCli = document.getElementById('modal-nuevo-cliente');
                if(buscador && !mostrarTodosActivo && !document.querySelector('.modal-overlay[style*="display: flex"]') && (!modalCli || modalCli.style.display !== 'flex')) {
                    buscador.focus();
                }
            }, 150);
        });

        function alternarPanelesPago() {
            const radioSeleccionado = document.querySelector('input[name="metodo-pago"]:checked');
            
            document.getElementById('panel-efectivo-usd').style.display = 'none';
            document.getElementById('panel-efectivo-bs').style.display = 'none';
            document.getElementById('panel-pago-mixto').style.display = 'none';
            document.getElementById('panel-otro-detalle').style.display = 'none';
            document.getElementById('panel-referencia-principal').style.display = 'none';
            document.getElementById('panel-credito').style.display = 'none';
            
            detenerCamara();

            if (!radioSeleccionado) return; 
            const metodo = radioSeleccionado.value;

            if (metodo === 'efectivo_usd') {
                document.getElementById('panel-efectivo-usd').style.display = 'block';
                calcularEfectivoUSD();
            } else if (metodo === 'efectivo_bs') {
                document.getElementById('panel-efectivo-bs').style.display = 'block';
                calcularEfectivoBS();
            } else if (metodo === 'pago_movil') {
                document.getElementById('panel-referencia-principal').style.display = 'block';
            } else if (metodo === 'mixto') {
                document.getElementById('panel-pago-mixto').style.display = 'block';
            } else if (metodo === 'credito') {
                document.getElementById('panel-credito').style.display = 'block';
                const inputBase64 = document.getElementById('foto_base64');
                if (!inputBase64 || inputBase64.value === '') {
                    iniciarCamara();
                }
            } else if (metodo.startsWith('otro')) {
                document.getElementById('panel-otro-detalle').style.display = 'block';
            }
        }

        document.querySelectorAll('input[name="metodo-pago"]').forEach(radio => {
            radio.addEventListener('change', alternarPanelesPago);
        });

        function calcularEfectivoUSD() {
            const elemUsd = document.getElementById('total-usd');
            const inputRecibido = document.getElementById('recibido-usd');
            
            const displayVueltoTotal = document.getElementById('vuelto-total-usd-txt');
            const inputVueltoDadoUsd = document.getElementById('vuelto-dado-usd');
            const displayFaltaUsd = document.getElementById('vuelto-falta-usd');
            const displayFaltaBs = document.getElementById('vuelto-falta-bs');
            const inputAbono = document.getElementById('abono-usd');
            const displayAbonoBs = document.getElementById('abono-usd-en-bs');

            if (!elemUsd) return;

            const totalFacturaUsd = parseFloat(elemUsd.innerText.replace('$', '').trim()) || 0;
            const recibidoUsd = parseFloat(inputRecibido.value) || 0;
            
            const vueltoTotalUsd = recibidoUsd - totalFacturaUsd;
            
            if (vueltoTotalUsd > 0 && recibidoUsd > 0) {
                displayVueltoTotal.innerText = '$' + vueltoTotalUsd.toFixed(2);
                
                const vueltoDadoUsd = parseFloat(inputVueltoDadoUsd.value) || 0;
                const abonoUsd = parseFloat(inputAbono.value) || 0;
                
                let faltaEntregarUsd = vueltoTotalUsd - vueltoDadoUsd - abonoUsd;
                if (faltaEntregarUsd < 0) faltaEntregarUsd = 0; 
                
                const faltaEntregarBs = faltaEntregarUsd * tasaDelDia;

                displayFaltaUsd.innerText = '$' + faltaEntregarUsd.toFixed(2);
                displayFaltaBs.innerText = '(' + faltaEntregarBs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs)';
                
            } else {
                displayVueltoTotal.innerText = '$0.00';
                displayFaltaUsd.innerText = '$0.00';
                displayFaltaBs.innerText = '(0.00 Bs)';
            }

            const abonoUsd = parseFloat(inputAbono.value) || 0;
            displayAbonoBs.innerText = (abonoUsd * tasaDelDia).toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function calcularEfectivoBS() {
            const elemUsd = document.getElementById('total-usd');
            const inputRecibido = document.getElementById('recibido-bs');
            const displayVueltoBs = document.getElementById('vuelto-total-bs-display');

            if (!elemUsd) return;

            const totalFacturaUsd = parseFloat(elemUsd.innerText.replace('$', '').trim()) || 0;
            const totalFacturaBs = totalFacturaUsd * tasaDelDia;
            const recibidoBs = parseFloat(inputRecibido.value) || 0;

            const vueltoBs = recibidoBs - totalFacturaBs;
            if (vueltoBs > 0 && recibidoBs > 0) {
                displayVueltoBs.innerText = vueltoBs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs';
                
                const vueltoUsdDado = parseFloat(document.getElementById('vuelto-dado-usd-desde-bs').value) || 0;
                let faltaBs = vueltoBs - (vueltoUsdDado * tasaDelDia);
                if(faltaBs < 0) faltaBs = 0;
                
                document.getElementById('falta-entregar-bs').innerText = faltaBs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs';
            } else {
                displayVueltoBs.innerText = '0,00 Bs';
                document.getElementById('falta-entregar-bs').innerText = '0,00 Bs';
            }

            const recUsdEquivalent = recibidoBs > 0 ? (recibidoBs / tasaDelDia).toFixed(2) : '';
            document.getElementById('recibido-usd').value = recUsdEquivalent;
            document.getElementById('vuelto-dado-usd').value = document.getElementById('vuelto-dado-usd-desde-bs').value;
            document.getElementById('vuelto-dado-bs').value = document.getElementById('vuelto-dado-bs-desde-bs').value;
            document.getElementById('metodo-vuelto-bs').value = document.getElementById('metodo-vuelto-bs-desde-bs').value;
        }

        document.getElementById('recibido-usd').addEventListener('input', calcularEfectivoUSD);
        document.getElementById('vuelto-dado-usd').addEventListener('input', calcularEfectivoUSD);
        document.getElementById('abono-usd').addEventListener('input', calcularEfectivoUSD);
        
        document.getElementById('recibido-bs').addEventListener('input', calcularEfectivoBS);
        document.getElementById('vuelto-dado-usd-desde-bs').addEventListener('input', calcularEfectivoBS);
        document.getElementById('vuelto-dado-bs-desde-bs').addEventListener('input', calcularEfectivoBS);
        document.getElementById('metodo-vuelto-bs-desde-bs').addEventListener('change', calcularEfectivoBS);

        function corregirRedondeoGlobal() {
            let elemUsd = document.getElementById('total-usd');
            let elemBs = document.getElementById('total-bs');
            if (elemUsd && elemBs) {
                let txtUsd = elemUsd.innerText.replace('$', '').trim();
                let valUsd = parseFloat(txtUsd) || 0;
                if (valUsd > 0) {
                    let exactoBs = Math.round((valUsd * tasaDelDia) * 100) / 100;
                    elemBs.innerText = exactoBs.toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' Bs';
                } else {
                    elemBs.innerText = '0.00 Bs';
                }
            }
            calcularEfectivoUSD();
            calcularEfectivoBS();
        }

        const observadorTotal = new MutationObserver(() => corregirRedondeoGlobal());
        document.addEventListener("DOMContentLoaded", () => {
            let elemUsd = document.getElementById('total-usd');
            if(elemUsd) observadorTotal.observe(elemUsd, { childList: true, characterData: true, subtree: true });
            alternarPanelesPago(); 
        });

        function recalcularPreciosPorTarifa() {
            const selectTarifa = document.getElementById('modalidad-tarifa');
            const tarifa = selectTarifa ? selectTarifa.value : 'bcv_detal';
            const tarjetas = document.querySelectorAll('.tarjeta-producto');
            const mapaPrecios = {};
            
            tarjetas.forEach(tarjeta => {
                let id = tarjeta.getAttribute('data-id');
                let nuevoPrecioUsd = 0;
                if (tarifa === 'bcv_detal') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-bcv-detal'));
                else if (tarifa === 'bcv_mayor') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-bcv-mayor'));
                else if (tarifa === 'efectivo_detal') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-efectivo-detal'));
                else if (tarifa === 'efectivo_mayor') nuevoPrecioUsd = parseFloat(tarjeta.getAttribute('data-precio-efectivo-mayor'));
                
                if (isNaN(nuevoPrecioUsd)) nuevoPrecioUsd = 0;
                tarjeta.setAttribute('data-precio', nuevoPrecioUsd);
                mapaPrecios[id] = nuevoPrecioUsd;
                
                let txtUsd = document.getElementById(`render-precio-usd-${id}`);
                let txtBs = document.getElementById(`render-precio-bs-${id}`);
                let unidadTxt = tarjeta.getAttribute('data-unidad') === 'kg' ? 'Kg' : (tarjeta.getAttribute('data-unidad') === 'm' ? 'm' : 'Und');
                
                if(txtUsd) txtUsd.innerText = `$${nuevoPrecioUsd.toFixed(2)} / ${unidadTxt}`;
                if(txtBs) txtBs.innerText = `${(nuevoPrecioUsd * tasaDelDia).toLocaleString('es-VE', {minimumFractionDigits: 2, maximumFractionDigits: 2})} Bs`;
            });

            if (typeof window.carrito !== 'undefined' && Array.isArray(window.carrito)) {
                window.carrito.forEach(item => {
                    let prodId = item.id !== undefined ? String(item.id) : null;
                    if (prodId && mapaPrecios[prodId] !== undefined) {
                        let pNuevo = mapaPrecios[prodId];
                        if (item.precioUsd !== undefined) item.precioUsd = pNuevo;
                        if (item.precio !== undefined) item.precio = pNuevo;
                        if (item.precio_usd !== undefined) item.precio_usd = pNuevo;
                        if (item.precioUnitario !== undefined) item.precioUnitario = pNuevo;
                        if (item.subtotal !== undefined && item.cantidad !== undefined) {
                            item.subtotal = pNuevo * parseFloat(item.cantidad);
                        }
                    }
                });
            }

            if (typeof window.actualizarTotalesCarrito === 'function') window.actualizarTotalesCarrito();
            if (typeof window.actualizarTicket === 'function') window.actualizarTicket();
            setTimeout(corregirRedondeoGlobal, 50);
        }

        document.getElementById('modalidad-tarifa').addEventListener('change', recalcularPreciosPorTarifa);

        function iniciarCamara() {
            const video = document.getElementById('video-camara');
            video.setAttribute('playsinline', ''); 
            if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
                let opcionesCamara = { video: { facingMode: { ideal: modoCamara } } };
                navigator.mediaDevices.getUserMedia(opcionesCamara)
                .then(function(stream) { streamCamara = stream; video.srcObject = stream; })
                .catch(function(err) { 
                    navigator.mediaDevices.getUserMedia({ video: true })
                    .then(function(streamFallback) { streamCamara = streamFallback; video.srcObject = streamFallback; })
                    .catch(function(errFallback) { console.warn("La cámara fue bloqueada."); });
                });
            }
        }

        function detenerCamara() {
            if (streamCamara) { streamCamara.getTracks().forEach(track => track.stop()); streamCamara = null; }
        }

        function cambiarTasaManual() {
            let tasaActual = document.getElementById('tasa-actual').innerText.replace(' Bs', '');
            let tasaEuroActual = document.getElementById('tasa-euro-actual').innerText.replace('$', '');
            let nuevaTasa = prompt("1. Ingresa la nueva tasa del DÓLAR:", tasaActual);
            if (nuevaTasa !== null && nuevaTasa.trim() !== '') {
                let nuevaTasaEuro = prompt("2. Ingresa la cotización del EURO:", tasaEuroActual);
                if (nuevaTasaEuro !== null && nuevaTasaEuro.trim() !== '') {
                    nuevaTasa = parseFloat(nuevaTasa.replace(',', '.'));
                    nuevaTasaEuro = parseFloat(nuevaTasaEuro.replace(',', '.'));
                    if (!isNaN(nuevaTasa) && nuevaTasa > 0 && !isNaN(nuevaTasaEuro) && nuevaTasaEuro > 0) {
                        let formData = new URLSearchParams();
                        formData.append('tasa', nuevaTasa); formData.append('tasa_euro', nuevaTasaEuro);
                        fetch('actualizar_tasa.php', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: formData })
                        .then(() => window.location.reload());
                    }
                }
            }
        }
        
        document.querySelector('.btn-facturar').addEventListener('click', function(e) {
            const radioSeleccionado = document.querySelector('input[name="metodo-pago"]:checked');
            if (!radioSeleccionado) {
                e.preventDefault();
                e.stopPropagation();
                alert("⚠️ Por favor, selecciona un método de pago antes de procesar la venta.");
                return false;
            }
        }, true);

        document.getElementById('btn-tomar-foto').addEventListener('click', function() {
            const video = document.getElementById('video-camara');
            const canvas = document.getElementById('canvas-camara');
            const preview = document.getElementById('foto-preview');
            const inputBase64 = document.getElementById('foto_base64');
            const btnTomar = document.getElementById('btn-tomar-foto');
            const btnRetomar = document.getElementById('btn-retomar-foto');

            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                const contexto = canvas.getContext('2d');
                contexto.drawImage(video, 0, 0, canvas.width, canvas.height);
                const imagenBase64 = canvas.toDataURL('image/jpeg', 0.8);

                preview.src = imagenBase64;
                preview.style.display = 'block';
                video.style.display = 'none';
                inputBase64.value = imagenBase64;

                btnTomar.style.display = 'none';
                btnRetomar.style.display = 'block';
            } else {
                alert("La cámara aún no está lista. Por favor, espera un segundo.");
            }
        });

        document.getElementById('btn-retomar-foto').addEventListener('click', function() {
            const video = document.getElementById('video-camara');
            const preview = document.getElementById('foto-preview');
            const inputBase64 = document.getElementById('foto_base64');
            const btnTomar = document.getElementById('btn-tomar-foto');
            const btnRetomar = document.getElementById('btn-retomar-foto');

            preview.src = '';
            preview.style.display = 'none';
            video.style.display = 'block';
            inputBase64.value = '';

            btnTomar.style.display = 'block';
            btnRetomar.style.display = 'none';
            if (!streamCamara) iniciarCamara();
        });
    </script> 
</body>
</html>