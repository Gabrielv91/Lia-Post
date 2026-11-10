<?php
session_start();
if (!isset($_SESSION['usuario_id']) || $_SESSION['usuario_rol'] != 'superadmin') {
    header("Location: index.php");
    exit;
}
require_once 'conexion.php';
$mensaje = "";
$tipo_alerta = "exito";

// =========================================================================
// AUTO-PARCHE DE LA TABLA NEGOCIOS
// =========================================================================
$check_tel = $conexion->query("SHOW COLUMNS FROM negocios LIKE 'telefono'");
if ($check_tel && $check_tel->num_rows == 0) {
    $conexion->query("ALTER TABLE negocios ADD COLUMN telefono VARCHAR(20) NULL");
}
$check_costo = $conexion->query("SHOW COLUMNS FROM negocios LIKE 'costo_mensual'");
if ($check_costo && $check_costo->num_rows == 0) {
    $conexion->query("ALTER TABLE negocios ADD COLUMN costo_mensual DECIMAL(10,2) DEFAULT 0.00");
}

// =========================================================================
// 1. CREAR NUEVO NEGOCIO (TENANT)
// =========================================================================
if (isset($_POST['crear_negocio'])) {
    $nombre_neg = $conexion->real_escape_string($_POST['nombre_negocio']);
    $usuario = $conexion->real_escape_string($_POST['usuario_admin']);
    $clave = $conexion->real_escape_string($_POST['clave_admin']);
    $telefono = $conexion->real_escape_string($_POST['telefono_contacto']);
    $fecha_vence = $conexion->real_escape_string($_POST['fecha_vencimiento']);
    $mensualidad = floatval($_POST['costo_mensual']);
    
    $check_user = $conexion->query("SELECT id FROM usuarios WHERE usuario = '$usuario'");
    
    if ($check_user && $check_user->num_rows > 0) {
        $mensaje = "❌ Error: El usuario '$usuario' ya está en uso. Elige otro.";
        $tipo_alerta = "error";
    } else {
        $conexion->begin_transaction();
        try {
            $conexion->query("INSERT INTO negocios (nombre, estatus_pago, fecha_vencimiento, telefono, costo_mensual) VALUES ('$nombre_neg', 'activo', '$fecha_vence', '$telefono', $mensualidad)");
            $id_nuevo = $conexion->insert_id;
            
            // Creamos una sede por defecto en BD, aunque no se gestione visualmente
            $conexion->query("INSERT INTO sucursales (id_negocio, nombre) VALUES ($id_nuevo, 'Sede Principal')");
            $conexion->query("INSERT INTO usuarios (id_negocio, nombre, usuario, clave, rol) VALUES ($id_nuevo, 'Admin $nombre_neg', '$usuario', '$clave', 'admin')");
            
            $conexion->commit();
            $mensaje = "✅ Negocio '$nombre_neg' creado exitosamente con su administrador.";
        } catch (Exception $e) {
            $conexion->rollback();
            $mensaje = "❌ Error al crear: " . $e->getMessage();
            $tipo_alerta = "error";
        }
    }
}

// =========================================================================
// 2. ACTUALIZAR ESTATUS DE PAGO Y VENCIMIENTO
// =========================================================================
if (isset($_POST['actualizar_suscripcion'])) {
    $id_neg = intval($_POST['id_negocio']);
    $nuevo_estatus = $conexion->real_escape_string($_POST['estatus']);
    $nueva_fecha = $conexion->real_escape_string($_POST['fecha_vencimiento']);
    
    $conexion->query("UPDATE negocios SET estatus_pago = '$nuevo_estatus', fecha_vencimiento = '$nueva_fecha' WHERE id = $id_neg");
    $mensaje = "✅ Suscripción actualizada correctamente.";
}

// =========================================================================
// 3. EDITAR DATOS DEL NEGOCIO (NUEVO)
// =========================================================================
if (isset($_POST['editar_negocio'])) {
    $id_neg = intval($_POST['id_negocio_edit']);
    $nombre = $conexion->real_escape_string($_POST['edit_nombre_negocio']);
    $telefono = $conexion->real_escape_string($_POST['edit_telefono']);
    $costo = floatval($_POST['edit_costo']);

    $conexion->query("UPDATE negocios SET nombre = '$nombre', telefono = '$telefono', costo_mensual = $costo WHERE id = $id_neg");
    $mensaje = "✅ Perfil del negocio actualizado correctamente.";
}

// =========================================================================
// 4. ACTUALIZAR TASA BCV GLOBAL
// =========================================================================
if (isset($_POST['actualizar_tasa_global'])) {
    $nueva_tasa = floatval($_POST['tasa_bcv']);
    if ($nueva_tasa > 0) {
        $conexion->query("UPDATE configuracion SET tasa_dia = $nueva_tasa, ultima_actualizacion = NOW()");
        $mensaje = "📈 Tasa BCV Global actualizada a " . number_format($nueva_tasa, 2, ',', '.') . " Bs.";
    }
}

// =========================================================================
// 5. RESETEAR CREDENCIALES DE CLIENTE
// =========================================================================
if (isset($_POST['resetear_clave'])) {
    $id_usuario = intval($_POST['id_usuario_admin']);
    $nuevo_usuario = $conexion->real_escape_string($_POST['nuevo_usuario']);
    $nueva_clave = $conexion->real_escape_string($_POST['nueva_clave']);
    
    $conexion->query("UPDATE usuarios SET usuario = '$nuevo_usuario', clave = '$nueva_clave' WHERE id = $id_usuario");
    $mensaje = "🔑 Credenciales actualizadas correctamente.";
}

// --- OBTENER TASA ACTUAL ---
$q_tasa = $conexion->query("SELECT tasa_dia, ultima_actualizacion FROM configuracion ORDER BY id DESC LIMIT 1");
$tasa_actual = 36.50;
$fecha_tasa = date('Y-m-d H:i:s');
if ($q_tasa && $q_tasa->num_rows > 0) {
    $data_tasa = $q_tasa->fetch_assoc();
    $tasa_actual = floatval($data_tasa['tasa_dia'] ?? 36.50);
    $fecha_tasa = $data_tasa['ultima_actualizacion'] ?? '';
}

// --- OBTENER MÉTRICAS FINANCIERAS ---
$q_rentabilidad = "SELECT SUM(costo_mensual) as ingreso_mensual, COUNT(id) as total_activos FROM negocios WHERE estatus_pago = 'activo' AND id != 1";
$res_rentabilidad = $conexion->query($q_rentabilidad);
if ($res_rentabilidad) {
    $data_rent = $res_rentabilidad->fetch_assoc();
    $ingreso_mensual_usd = floatval($data_rent['ingreso_mensual'] ?? 0);
    $clientes_activos = intval($data_rent['total_activos'] ?? 0);
} else {
    $ingreso_mensual_usd = 0;
    $clientes_activos = 0;
}

$q_morosos = "SELECT COUNT(id) as total_morosos FROM negocios WHERE estatus_pago = 'bloqueado' AND id != 1";
$res_morosos = $conexion->query($q_morosos);
$clientes_morosos = $res_morosos ? ($res_morosos->fetch_assoc()['total_morosos'] ?? 0) : 0;

// --- LISTADO DE NEGOCIOS ---
$negocios = $conexion->query("SELECT * FROM negocios ORDER BY id ASC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Lia POS - Superadmin Dashboard</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .panel { padding: 2rem; max-width: 1300px; margin: 0 auto; }
        .grid-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .kpi-card { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .kpi-valor { font-size: 2rem; font-weight: bold; margin-top: 0.5rem; }
        
        .grid-formularios { display: grid; grid-template-columns: 2fr 1fr; gap: 2rem; margin-bottom: 2rem; }
        .card { background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th { background: #f8fafc; padding: 12px 10px; color: #475569; font-size: 0.85rem; text-transform: uppercase; border-bottom: 2px solid #cbd5e1; text-align: left; }
        td { padding: 12px 10px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        
        .badge { padding: 4px 8px; border-radius: 12px; font-weight: bold; font-size: 0.8rem; }
        .activo { background: #d1fae5; color: #065f46; }
        .bloqueado { background: #fee2e2; color: #b91c1c; }
        .por-vencer { background: #fef3c7; color: #d97706; }
        
        .btn-super { background: #3b82f6; color: white; border: none; padding: 0.6rem 1rem; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 0.9rem; transition: 0.2s;}
        .btn-super:hover { background: #2563eb; }
        .btn-wa { background: #25D366; color: white; text-decoration: none; padding: 0.4rem 0.8rem; border-radius: 5px; font-weight: bold; font-size: 0.85rem; }
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); align-items: center; justify-content: center; z-index: 1000; }
        .modal-caja { background: white; padding: 2rem; border-radius: 8px; width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .input-form { width: 100%; padding: 0.8rem; border: 1px solid #cbd5e1; border-radius: 4px; box-sizing: border-box; margin-bottom: 1rem; }
        label { display: block; font-weight: bold; font-size: 0.85rem; color: #475569; margin-bottom: 0.3rem; }
    </style>
</head>
<body style="background: #f1f5f9; font-family: sans-serif;">
    <header class="top-bar" style="display: flex; justify-content: space-between; align-items: center; padding: 1rem 2rem; background: #1e293b; color: white;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span style="font-size: 1.5rem;">👑</span>
            <h2 style="margin: 0; color: white;">Lia POS - SaaS Dashboard</h2>
        </div>
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span style="font-size: 0.9rem;">Bienvenido, <strong style="color: #60a5fa;">Super Administrador</strong></span>
            <a href="logout.php" style="background: #ef4444; color: white; padding: 0.5rem 1rem; border-radius: 5px; text-decoration: none; font-weight: bold;">Salir</a>
        </div>
    </header>

    <div class="panel">
        <?php if($mensaje): ?>
            <div style="background: <?php echo $tipo_alerta=='exito'?'#d1fae5':'#fee2e2'; ?>; color: <?php echo $tipo_alerta=='exito'?'#065f46':'#991b1b'; ?>; padding: 1rem; margin-bottom: 1.5rem; border-radius: 5px; font-weight: bold; border-left: 4px solid <?php echo $tipo_alerta=='exito'?'#10b981':'#ef4444'; ?>;">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>

        <!-- MÉTRICAS FINANCIERAS -->
        <div class="grid-kpi">
            <div class="kpi-card" style="border-left: 5px solid #10b981;">
                <div style="color: #64748b; font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Ingreso Recurrente Mensual (MRR)</div>
                <div class="kpi-valor" style="color: #065f46;">$<?php echo number_format($ingreso_mensual_usd, 2); ?></div>
            </div>
            <div class="kpi-card" style="border-left: 5px solid #3b82f6;">
                <div style="color: #64748b; font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Negocios Activos</div>
                <div class="kpi-valor" style="color: #1e3a8a;"><?php echo $clientes_activos; ?></div>
            </div>
            <div class="kpi-card" style="border-left: 5px solid #ef4444;">
                <div style="color: #64748b; font-size: 0.85rem; font-weight: bold; text-transform: uppercase;">Negocios Suspendidos</div>
                <div class="kpi-valor" style="color: #b91c1c;"><?php echo $clientes_morosos; ?></div>
            </div>
        </div>

        <div class="grid-formularios">
            <!-- CREAR NEGOCIO -->
            <div class="card" style="border-top: 4px solid #3b82f6;">
                <h3 style="margin-top: 0; color: #1e3a8a; margin-bottom: 0.5rem;">🏢 Registrar Nuevo Cliente (Tenant)</h3>
                <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 1.5rem;">Despliega un nuevo entorno aislado de base de datos para un cliente nuevo.</p>
                
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1rem;">
                        <div>
                            <label>Nombre del Negocio</label>
                            <input type="text" name="nombre_negocio" class="input-form" placeholder="Ej: Inversiones El Toro C.A" required>
                        </div>
                        <div>
                            <label>Mensualidad ($ USD)</label>
                            <input type="number" step="0.01" name="costo_mensual" class="input-form" placeholder="Ej: 20.00" required>
                        </div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label>Teléfono (WhatsApp P/Cobros)</label>
                            <input type="text" name="telefono_contacto" class="input-form" placeholder="Ej: 04141234567" required>
                        </div>
                        <div>
                            <label>Fecha del Próximo Pago</label>
                            <input type="date" name="fecha_vencimiento" class="input-form" required value="<?php echo date('Y-m-d', strtotime('+1 month')); ?>">
                        </div>
                    </div>

                    <div style="background: #f8fafc; padding: 1rem; border-radius: 6px; border: 1px solid #e2e8f0; margin-bottom: 1rem;">
                        <h4 style="margin-top: 0; color: #334155; font-size: 0.9rem;">Credenciales del Administrador del Cliente</h4>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-top: 0.5rem;">
                            <div>
                                <label>Usuario (Login)</label>
                                <input type="text" name="usuario_admin" class="input-form" placeholder="Ej: admin_eltoro" required style="margin-bottom: 0;">
                            </div>
                            <div>
                                <label>Contraseña</label>
                                <input type="text" name="clave_admin" class="input-form" placeholder="Clave de acceso" required style="margin-bottom: 0;">
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="crear_negocio" class="btn-super" style="width: 100%;">🚀 Desplegar Sistema para el Cliente</button>
                </form>
            </div>

            <!-- ACTUALIZAR TASA GLOBAL -->
            <div class="card" style="border-top: 4px solid #10b981; align-self: start;">
                <h3 style="margin-top: 0; color: #065f46; margin-bottom: 0.5rem;">🏦 Tasa BCV Global</h3>
                <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 1.5rem;">Al actualizar aquí, se cambia en <b>todos los negocios</b> automáticamente.</p>
                
                <div style="text-align: center; margin-bottom: 1.5rem;">
                    <span style="font-size: 2.5rem; font-weight: bold; color: #10b981;"><?php echo number_format($tasa_actual, 2, ',', '.'); ?> Bs</span><br>
                    <span style="font-size: 0.8rem; color: #94a3b8;">Última act: <?php echo date('d/m/Y h:i A', strtotime($fecha_tasa)); ?></span>
                </div>

                <form method="POST">
                    <label>Nueva Tasa del Día (Bs):</label>
                    <input type="number" step="0.0001" name="tasa_bcv" class="input-form" value="<?php echo $tasa_actual; ?>" required>
                    <button type="submit" name="actualizar_tasa_global" class="btn-super" style="background: #10b981; width: 100%;">Actualizar Sistema Global</button>
                </form>
            </div>
        </div>

        <!-- LISTADO DE NEGOCIOS -->
        <div class="card">
            <h3 style="margin-top: 0; color: #1e293b; margin-bottom: 1rem;">📊 Monitor de Clientes y Suscripciones</h3>
            
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Negocio</th>
                            <th>Admin / Clave</th>
                            <th>Vencimiento</th>
                            <th>Estatus Mensualidad</th>
                            <th>Control / Cobranza</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($negocios && $negocios->num_rows > 0): while($n = $negocios->fetch_assoc()): 
                            $id_negocio_fila = $n['id'];
                            
                            $q_admin = $conexion->query("SELECT id, usuario, clave FROM usuarios WHERE id_negocio = $id_negocio_fila AND rol = 'admin' LIMIT 1");
                            $admin_data = $q_admin ? $q_admin->fetch_assoc() : null;

                            $hoy = new DateTime();
                            $fecha_vence_str = $n['fecha_vencimiento'] ?: date('Y-m-d');
                            $vence = new DateTime($fecha_vence_str);
                            $diferencia = $hoy->diff($vence);
                            $dias_restantes = (int)$diferencia->format('%R%a');

                            $badge_clase = 'activo';
                            $badge_texto = 'Al Día';
                            
                            if ($n['estatus_pago'] == 'bloqueado') {
                                $badge_clase = 'bloqueado';
                                $badge_texto = 'BLOQUEADO';
                            } elseif ($dias_restantes < 0) {
                                $badge_clase = 'bloqueado';
                                $badge_texto = 'VENCIDO (' . abs($dias_restantes) . ' días)';
                            } elseif ($dias_restantes <= 5) {
                                $badge_clase = 'por-vencer';
                                $badge_texto = 'Vence en ' . $dias_restantes . ' días';
                            }

                            $tlf_ws = preg_replace('/[^0-9]/', '', $n['telefono'] ?? '');
                            if (strpos($tlf_ws, '0') === 0) $tlf_ws = '58' . substr($tlf_ws, 1);
                            
                            $msg_ws = urlencode("Hola, le escribimos del Soporte Técnico de Lia POS. El pago de su suscripción al sistema por $" . number_format($n['costo_mensual'] ?? 0, 2) . " vence el " . date('d/m/Y', strtotime($fecha_vence_str)) . ". Por favor envíe el soporte de pago para mantener su servicio activo.");
                        ?>
                        <tr>
                            <td style="color: #94a3b8; font-weight: bold;">#<?php echo $id_negocio_fila; ?></td>
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <b style="color: #1e3a8a; font-size: 1.05rem;"><?php echo htmlspecialchars($n['nombre']); ?></b>
                                    <button onclick="abrirModalEditarNegocio(<?php echo $id_negocio_fila; ?>, '<?php echo addslashes($n['nombre']); ?>', '<?php echo addslashes($n['telefono'] ?? ''); ?>', <?php echo $n['costo_mensual'] ?? 0; ?>)" style="background: none; border: none; cursor: pointer; font-size: 0.9rem;" title="Editar Perfil del Negocio">✏️</button>
                                </div>
                                <small style="color: #64748b;">📞 <?php echo htmlspecialchars($n['telefono'] ?? 'N/A'); ?> | Renta: $<?php echo number_format($n['costo_mensual'] ?? 0, 2); ?></small>
                            </td>
                            <td>
                                <?php if($admin_data): ?>
                                    <div style="font-family: monospace; background: #f8fafc; padding: 4px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 0.8rem;">
                                        U: <b><?php echo htmlspecialchars($admin_data['usuario']); ?></b><br>
                                        P: <?php echo htmlspecialchars($admin_data['clave']); ?>
                                    </div>
                                    <?php if($id_negocio_fila != 1): ?>
                                        <button type="button" onclick="abrirModalClave(<?php echo $admin_data['id']; ?>, '<?php echo htmlspecialchars($admin_data['usuario']); ?>')" style="background: none; border: none; color: #3b82f6; font-size: 0.75rem; cursor: pointer; text-decoration: underline; padding: 0; margin-top: 4px;">Modificar credenciales</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #ef4444; font-size: 0.8rem;">Sin Admin</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: bold; color: #475569;"><?php echo date('d/m/Y', strtotime($fecha_vence_str)); ?></td>
                            <td>
                                <span class="badge <?php echo $badge_clase; ?>"><?php echo $badge_texto; ?></span>
                            </td>
                            <td>
                                <?php if($id_negocio_fila != 1): ?>
                                    <div style="display: flex; gap: 5px; flex-direction: column;">
                                        <button onclick="abrirModalSuscripcion(<?php echo $id_negocio_fila; ?>, '<?php echo $n['estatus_pago']; ?>', '<?php echo $fecha_vence_str; ?>')" class="btn-super" style="padding: 0.3rem 0.5rem; font-size: 0.8rem;">⚙️ Gestionar Estatus</button>
                                        
                                        <?php if ($dias_restantes <= 5 || $n['estatus_pago'] == 'bloqueado'): ?>
                                            <a href="https://wa.me/<?php echo $tlf_ws; ?>?text=<?php echo $msg_ws; ?>" target="_blank" class="btn-wa" style="text-align: center;">💬 Cobrar WhatsApp</a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #10b981; font-weight: bold; font-size: 0.8rem;">Negocio Raíz (No Bloqueable)</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- MODAL GESTIONAR SUSCRIPCIÓN -->
    <div id="modal-suscripcion" class="modal">
        <div class="modal-caja">
            <h3 style="margin-top: 0; color: #1e3a8a; border-bottom: 1px solid #cbd5e1; padding-bottom: 0.5rem;">⚙️ Gestionar Suscripción</h3>
            <form method="POST">
                <input type="hidden" name="id_negocio" id="sub_id_negocio">
                
                <label>Estatus del Sistema (Corte de Servicio)</label>
                <select name="estatus" id="sub_estatus" class="input-form">
                    <option value="activo">🟢 Activo (Puede ingresar)</option>
                    <option value="bloqueado">🔴 Bloqueado (Suspendido por falta de pago)</option>
                </select>

                <label>Renovar Fecha de Vencimiento</label>
                <input type="date" name="fecha_vencimiento" id="sub_fecha" class="input-form" required>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="actualizar_suscripcion" class="btn-super" style="flex: 1;">Guardar Cambios</button>
                    <button type="button" onclick="cerrarModal('modal-suscripcion')" class="btn-super" style="flex: 1; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR NEGOCIO -->
    <div id="modal-editar-negocio" class="modal">
        <div class="modal-caja" style="border-top: 5px solid #f59e0b;">
            <h3 style="margin-top: 0; color: #d97706;">✏️ Editar Datos del Cliente</h3>
            <form method="POST">
                <input type="hidden" name="id_negocio_edit" id="edit_neg_id">
                
                <label>Nombre del Negocio</label>
                <input type="text" name="edit_nombre_negocio" id="edit_neg_nombre" class="input-form" required>

                <label>Teléfono (WhatsApp)</label>
                <input type="text" name="edit_telefono" id="edit_neg_telefono" class="input-form" required>

                <label>Mensualidad ($ USD)</label>
                <input type="number" step="0.01" name="edit_costo" id="edit_neg_costo" class="input-form" required>

                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="editar_negocio" class="btn-super" style="flex: 1; background: #f59e0b;">Guardar Cambios</button>
                    <button type="button" onclick="cerrarModal('modal-editar-negocio')" class="btn-super" style="flex: 1; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL EDITAR CLAVE -->
    <div id="modal-clave" class="modal">
        <div class="modal-caja" style="border-top: 5px solid #10b981;">
            <h3 style="margin-top: 0; color: #065f46;">🔑 Modificar Accesos del Cliente</h3>
            <p style="font-size: 0.85rem; color: #64748b; margin-bottom: 1rem;">Usa esto si el cliente olvidó su usuario o contraseña y necesita que se los restaures.</p>
            <form method="POST">
                <input type="hidden" name="id_usuario_admin" id="res_id_usuario">
                
                <label>Nombre de Usuario</label>
                <input type="text" name="nuevo_usuario" id="res_usuario" class="input-form" required>

                <label>Nueva Contraseña</label>
                <input type="text" name="nueva_clave" class="input-form" required placeholder="Escribe la nueva clave">

                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="resetear_clave" class="btn-super" style="flex: 1; background: #10b981;">Resetear Accesos</button>
                    <button type="button" onclick="cerrarModal('modal-clave')" class="btn-super" style="flex: 1; background: #94a3b8;">Cancelar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function cerrarModal(id) {
            document.getElementById(id).style.display = 'none';
        }

        function abrirModalSuscripcion(id, estatus, fecha) {
            document.getElementById('sub_id_negocio').value = id;
            document.getElementById('sub_estatus').value = estatus;
            document.getElementById('sub_fecha').value = fecha;
            document.getElementById('modal-suscripcion').style.display = 'flex';
        }

        function abrirModalClave(id_usuario, nombre_usuario) {
            document.getElementById('res_id_usuario').value = id_usuario;
            document.getElementById('res_usuario').value = nombre_usuario;
            document.getElementById('modal-clave').style.display = 'flex';
        }

        function abrirModalEditarNegocio(id, nombre, telefono, costo) {
            document.getElementById('edit_neg_id').value = id;
            document.getElementById('edit_neg_nombre').value = nombre;
            document.getElementById('edit_neg_telefono').value = telefono;
            document.getElementById('edit_neg_costo').value = costo;
            document.getElementById('modal-editar-negocio').style.display = 'flex';
        }
    </script>
</body>
</html>