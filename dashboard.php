<?php
require_once 'config/database.php';
require_once 'includes/functions.php';
require_once 'verificar_licencia.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// VERIFICAR LICENCIA ANTES DE MOSTRAR EL DASHBOARD
$estado_licencia = verificarEstadoLicencia();
if (!$estado_licencia['valid']) {
    redirect('licencia.php');
}

// Resto del código del dashboard...
$db = Database::getInstance()->getConnection();


// Obtener estadísticas
$stats = [];

// Total de ventas hoy
$stmt = $db->query("SELECT COUNT(*) as total_ventas, SUM(total_bs) as total_bs FROM ventas WHERE DATE(fecha) = DATE('now')");
$stats['ventas_hoy'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Total de productos
$stmt = $db->query("SELECT COUNT(*) as total FROM productos");
$stats['total_productos'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Productos con bajo stock
$stmt = $db->query("SELECT COUNT(*) as total FROM productos WHERE stock < 10");
$stats['bajo_stock'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Últimas ventas
$stmt = $db->query("
    SELECT v.*, u.username 
    FROM ventas v 
    JOIN usuarios u ON v.usuario_id = u.id 
    ORDER BY v.fecha DESC 
    LIMIT 5
");
$ultimas_ventas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Verificar si el archivo CSS existe
$css_path = 'assets/css/styles.css';
$css_exists = file_exists($css_path);

// Obtener configuración del comercio
$settings = getSettings();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema de Ventas</title>
    
    <!-- Importación del CSS principal -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Estilos adicionales para la tarjeta del dólar -->
    <style>
        /* Tus estilos existentes... */
        .dolar-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .dolar-card::before {
            content: '$';
            position: absolute;
            font-size: 100px;
            opacity: 0.1;
            right: 10px;
            bottom: -20px;
            font-weight: bold;
        }
        
        .dolar-card h3 {
            color: white;
        }
        
        .dolar-card .stat-number {
            color: white;
            font-size: 28px;
        }
        
        .dolar-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 10px;
            font-size: 12px;
            opacity: 0.9;
        }
        
        .refresh-dolar {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }
        
        .refresh-dolar:hover {
            background: rgba(255,255,255,0.3);
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .rate-updated {
            animation: pulse 0.5s ease-in-out;
        }
    </style>
</head>
<body>
    <!-- Resto del contenido del dashboard... -->
    <div class="dashboard-container">
        <nav class="sidebar">
            <h3><?php echo htmlspecialchars($settings['nombre_comercio'] ?? 'Sistema de Ventas'); ?></h3>
            <ul>
                <li><a href="dashboard.php">📊 Dashboard</a></li>
                <li><a href="ventas.php">💰 Registrar Venta</a></li>
                <li><a href="productos.php">📦 Productos</a></li>
                <li><a href="setting.php">⚙️ Configuración</a></li>
                <?php if (isAdmin()): ?>
                    <li><a href="usuarios.php">👥 Usuarios</a></li>
                <?php endif; ?>
                <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header>
                <h1>Bienvenido, <?php echo htmlspecialchars($_SESSION['username']); ?></h1>
                <p>Rol: <?php echo htmlspecialchars($_SESSION['rol']); ?> | Fecha: <?php echo date('d/m/Y'); ?></p>
                <?php if (isset($_SESSION['license_date'])): ?>
                    <p style="font-size: 12px; color: #48bb78; margin-top: 5px;">
                        <i class="fas fa-check-circle"></i> Licencia validada: <?php echo $_SESSION['license_date']; ?>
                    </p>
                <?php endif; ?>
            </header>
            
            <!-- Resto del dashboard igual que antes... -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>📈 Ventas Hoy</h3>
                    <p class="stat-number"><?php echo $stats['ventas_hoy']['total_ventas'] ?? 0; ?></p>
                    <p><?php echo formatMoney($stats['ventas_hoy']['total_bs'] ?? 0); ?></p>
                </div>
                
                <!-- Tarjeta del Dólar -->
                <div class="stat-card dolar-card">
                    <h3><i class="fas fa-dollar-sign"></i> Tasa de Cambio</h3>
                    <p class="stat-number" id="tasa-dolar">
                        <i class="fas fa-spinner fa-spin"></i> Cargando...
                    </p>
                    <p>Dólar Oficial (BCV)</p>
                    <div class="dolar-info">
                        <span><i class="fas fa-database"></i> DolarAPI.com</span>
                        <button onclick="obtenerTasaCambio()" class="refresh-dolar">
                            <i class="fas fa-sync-alt"></i> Actualizar
                        </button>
                    </div>
                </div>
                
                <div class="stat-card">
                    <h3>📦 Total Productos</h3>
                    <p class="stat-number"><?php echo $stats['total_productos']; ?></p>
                    <p>Productos registrados</p>
                </div>
                
                <div class="stat-card">
                    <h3>⚠️ Productos con Bajo Stock</h3>
                    <p class="stat-number"><?php echo $stats['bajo_stock']; ?></p>
                    <p>Stock menor a 10 unidades</p>
                </div>
            </div>
            
            <div class="recent-sales">
                <h2>🕒 Últimas Ventas</h2>
                <?php if (empty($ultimas_ventas)): ?>
                    <p style="text-align: center; padding: 20px;">No hay ventas registradas</p>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Usuario</th>
                                <th>Total Bs</th>
                                <th>Moneda</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ultimas_ventas as $venta): ?>
                            <tr>
                                <td>#<?php echo $venta['id']; ?></td>
                                <td><?php echo htmlspecialchars($venta['username']); ?></td>
                                <td><?php echo formatMoney($venta['total_bs']); ?></td>
                                <td>
                                    <span style="display: inline-block; padding: 3px 8px; border-radius: 4px; background-color: <?php echo $venta['moneda'] == 'Bs' ? '#48bb78' : '#4299e1'; ?>; color: white;">
                                        <?php echo $venta['moneda']; ?>
                                    </span>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Función para obtener la tasa de cambio
        async function obtenerTasaCambio() {
            const rateElement = document.getElementById('tasa-dolar');
            
            try {
                const response = await fetch('https://ve.dolarapi.com/v1/dolares/oficial');
                const data = await response.json();
                
                if (data && data.promedio) {
                    rateElement.innerHTML = `Bs. ${data.promedio.toFixed(2)}`;
                    rateElement.classList.add('rate-updated');
                    setTimeout(() => rateElement.classList.remove('rate-updated'), 500);
                }
            } catch (error) {
                console.error('Error:', error);
                rateElement.innerHTML = 'No disponible';
            }
        }
        
        // Cargar tasa al iniciar
        obtenerTasaCambio();
        
        // Actualizar cada 5 minutos
        setInterval(obtenerTasaCambio, 300000);
    </script>
</body>
</html>