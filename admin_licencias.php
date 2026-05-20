<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('dashboard.php');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Asegurar que la tabla licencias tiene la estructura correcta
try {
    // Verificar si la columna dispositivo_id existe y eliminarla
    $stmt = $db->query("PRAGMA table_info(licencias)");
    $columnas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tieneDispositivoId = false;
    
    foreach ($columnas as $col) {
        if ($col['name'] == 'dispositivo_id') {
            $tieneDispositivoId = true;
            break;
        }
    }
    
    if ($tieneDispositivoId) {
        // Recrear tabla sin dispositivo_id
        $db->exec("
            CREATE TABLE IF NOT EXISTS licencias_temp (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                codigo_licencia TEXT UNIQUE NOT NULL,
                fecha_expiracion DATETIME,
                estado TEXT DEFAULT 'activa',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            INSERT INTO licencias_temp (id, codigo_licencia, fecha_expiracion, estado, created_at)
            SELECT id, codigo_licencia, fecha_expiracion, estado, created_at FROM licencias
        ");
        
        $db->exec("DROP TABLE licencias");
        $db->exec("ALTER TABLE licencias_temp RENAME TO licencias");
    }
} catch (Exception $e) {
    // La tabla puede no existir aún
}

// Crear tabla si no existe
$db->exec("
    CREATE TABLE IF NOT EXISTS licencias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo_licencia TEXT UNIQUE NOT NULL,
        fecha_expiracion DATETIME,
        estado TEXT DEFAULT 'activa',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// Crear tabla de activaciones
$db->exec("
    CREATE TABLE IF NOT EXISTS licencias_activadas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dispositivo_id TEXT UNIQUE NOT NULL,
        codigo_licencia TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        fecha_activacion DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

// Generar nueva licencia
function generarCodigoLicencia() {
    return strtoupper(bin2hex(random_bytes(16)));
}

// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'generar') {
            $codigo = generarCodigoLicencia();
            $fecha_expiracion = $_POST['fecha_expiracion'] ?? date('Y-m-d', strtotime('+1 year')) . ' 23:59:59';
            
            $stmt = $db->prepare("INSERT INTO licencias (codigo_licencia, fecha_expiracion, estado) VALUES (?, ?, 'activa')");
            if ($stmt->execute([$codigo, $fecha_expiracion])) {
                $success = "Licencia generada: <strong>$codigo</strong>";
            } else {
                $error = "Error al generar la licencia";
            }
        } elseif ($_POST['action'] === 'revocar') {
            $id = intval($_POST['id']);
            $stmt = $db->prepare("UPDATE licencias SET estado = 'revocada' WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success = "Licencia revocada exitosamente";
            } else {
                $error = "Error al revocar la licencia";
            }
        } elseif ($_POST['action'] === 'eliminar_activacion') {
            $id = intval($_POST['id']);
            $stmt = $db->prepare("DELETE FROM licencias_activadas WHERE id = ?");
            if ($stmt->execute([$id])) {
                $success = "Activación eliminada exitosamente";
            } else {
                $error = "Error al eliminar la activación";
            }
        }
    }
}

// Obtener licencias
$stmt = $db->query("SELECT * FROM licencias ORDER BY id DESC");
$licencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Si no hay licencias, crear una por defecto
if (empty($licencias)) {
    $codigo_default = 'A7F3E9C2B8D1F4E6A9C3B7D8E2F1A4C5';
    $stmt = $db->prepare("INSERT INTO licencias (codigo_licencia, fecha_expiracion, estado) VALUES (?, ?, 'activa')");
    $stmt->execute([$codigo_default, '2026-12-31 23:59:59']);
    $stmt = $db->query("SELECT * FROM licencias ORDER BY id DESC");
    $licencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Obtener activaciones
$stmt = $db->query("
    SELECT la.*, l.codigo_licencia 
    FROM licencias_activadas la 
    LEFT JOIN licencias l ON la.codigo_licencia = l.codigo_licencia 
    ORDER BY la.fecha_activacion DESC
");
$activaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Generar token CSRF si no existe
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generarToken();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Licencias - Sistema de Ventas</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .badge-success {
            background: #48bb78;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .badge-danger {
            background: #e53e3e;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 4px;
            cursor: pointer;
        }
        .btn-danger {
            background: #e53e3e;
            color: white;
            border: none;
        }
        .btn-warning {
            background: #ed8936;
            color: white;
            border: none;
        }
        .settings-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        th {
            background: #f7fafc;
        }
        code {
            background: #f0f0f0;
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 11px;
        }
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert.error {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fc8181;
        }
        .alert.success {
            background: #c6f6d5;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <nav class="sidebar">
            <h3>Sistema de Ventas</h3>
            <ul>
                <li><a href="dashboard.php">📊 Dashboard</a></li>
                <li><a href="ventas.php">💰 Registrar Venta</a></li>
                <li><a href="productos.php">📦 Productos</a></li>
                <li><a href="setting.php">⚙️ Configuración</a></li>
                <li><a href="usuarios.php">👥 Usuarios</a></li>
                <li><a href="admin_licencias.php">🔑 Licencias</a></li>
                <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header>
                <h1><i class="fas fa-key"></i> Administración de Licencias</h1>
            </header>
            
            <?php if ($error): ?>
                <div class="alert error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success">✅ <?php echo $success; ?></div>
            <?php endif; ?>
            
            <!-- Generar nueva licencia -->
            <div class="settings-card">
                <h3><i class="fas fa-plus-circle"></i> Generar Nueva Licencia</h3>
                <form method="POST" action="">
                    <input type="hidden" name="action" value="generar">
                    <div class="form-group">
                        <label>Fecha de Expiración:</label>
                        <input type="date" name="fecha_expiracion" value="<?php echo date('Y-m-d', strtotime('+1 year')); ?>" required>
                    </div>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-magic"></i> Generar Licencia
                    </button>
                </form>
            </div>
            
            <!-- Licencias existentes -->
            <div class="settings-card">
                <h3><i class="fas fa-list"></i> Licencias Generadas</h3>
                <?php if (empty($licencias)): ?>
                    <p>No hay licencias generadas</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Código de Licencia</th>
                                    <th>Estado</th>
                                    <th>Fecha Expiración</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($licencias as $licencia): ?>
                                <tr>
                                    <td><?php echo $licencia['id']; ?></td>
                                    <td>
                                        <code><?php echo $licencia['codigo_licencia']; ?></code>
                                        <button onclick="copiarCodigo('<?php echo $licencia['codigo_licencia']; ?>')" class="btn-small" style="margin-left: 5px;">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <span class="<?php echo $licencia['estado'] == 'activa' ? 'badge-success' : 'badge-danger'; ?>">
                                            <?php echo $licencia['estado']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo $licencia['fecha_expiracion'] ? date('d/m/Y', strtotime($licencia['fecha_expiracion'])) : 'Sin expiración'; ?></td>
                                    <td>
                                        <?php if ($licencia['estado'] == 'activa'): ?>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="revocar">
                                            <input type="hidden" name="id" value="<?php echo $licencia['id']; ?>">
                                            <button type="submit" class="btn-small btn-danger" onclick="return confirm('¿Revocar esta licencia?')">
                                                <i class="fas fa-ban"></i> Revocar
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Activaciones realizadas -->
            <div class="settings-card">
                <h3><i class="fas fa-history"></i> Activaciones Realizadas</h3>
                <?php if (empty($activaciones)): ?>
                    <p>No hay activaciones registradas</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table>
                            <thead>
                                <tr>
                                    <th>ID Dispositivo</th>
                                    <th>Código</th>
                                    <th>IP</th>
                                    <th>Fecha Activación</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($activaciones as $act): ?>
                                <tr>
                                    <td><code><?php echo substr($act['dispositivo_id'], 0, 20) . '...'; ?></code></td>
                                    <td><code><?php echo substr($act['codigo_licencia'], 0, 20) . '...'; ?></code></td>
                                    <td><?php echo $act['ip_address']; ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($act['fecha_activacion'])); ?></td>
                                    <td>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="eliminar_activacion">
                                            <input type="hidden" name="id" value="<?php echo $act['id']; ?>">
                                            <button type="submit" class="btn-small btn-warning" onclick="return confirm('¿Eliminar esta activación?')">
                                                <i class="fas fa-trash"></i> Eliminar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    function copiarCodigo(codigo) {
        navigator.clipboard.writeText(codigo).then(() => {
            mostrarNotificacion('✅ Código copiado al portapapeles', 'success');
        }).catch(() => {
            alert('No se pudo copiar el código');
        });
    }
    
    function mostrarNotificacion(mensaje, tipo) {
        const notification = document.createElement('div');
        notification.className = `alert ${tipo === 'success' ? 'success' : 'error'}`;
        notification.style.position = 'fixed';
        notification.style.top = '20px';
        notification.style.right = '20px';
        notification.style.zIndex = '9999';
        notification.innerHTML = `<i class="fas ${tipo === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${mensaje}`;
        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 3000);
    }
    </script>
</body>
</html>