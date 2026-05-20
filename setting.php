<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('dashboard.php');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$tasa_dolar = null;
$tasa_error = '';

// Obtener tasa de cambio actual - CORREGIDO
function getDolarRate() {
    $url = 'https://ve.dolarapi.com/v1/dolares/oficial';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'User-Agent: SistemaVentas/1.0',
        'Accept: application/json'
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $data = json_decode($response, true);
        // La API devuelve 'promedio', no 'precio'
        if (isset($data['promedio'])) {
            return [
                'success' => true, 
                'rate' => $data['promedio'], 
                'data' => $data
            ];
        } else {
            return ['success' => false, 'rate' => null, 'error' => 'Formato de respuesta inválido'];
        }
    } else {
        $errorMsg = 'No se pudo obtener la tasa de cambio';
        if ($curlError) {
            $errorMsg .= ': ' . $curlError;
        }
        return ['success' => false, 'rate' => null, 'error' => $errorMsg];
    }
}

// Obtener configuración actual
$stmt = $db->query("SELECT * FROM settings WHERE id = 1");
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$settings) {
    // Insertar configuración por defecto
    $stmt = $db->prepare("INSERT INTO settings (id, nombre_comercio, rif, direccion, logo, iva) VALUES (1, '', 'J-', 'Dirección del comercio', NULL, 16)");
    $stmt->execute();
    $stmt = $db->query("SELECT * FROM settings WHERE id = 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        $nombre_comercio = trim($_POST['nombre_comercio'] ?? '');
        $rif = trim($_POST['rif'] ?? '');
        $direccion = trim($_POST['direccion'] ?? '');
        $iva = floatval($_POST['iva'] ?? 16);
        
        // Procesar logo
        $logo_path = $settings['logo'];
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $filename = $_FILES['logo']['name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            if (in_array($ext, $allowed)) {
                $new_filename = 'logo_' . time() . '.' . $ext;
                $upload_path = 'assets/img/' . $new_filename;
                
                // Crear directorio si no existe
                if (!file_exists('assets/img')) {
                    mkdir('assets/img', 0777, true);
                }
                
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                    // Eliminar logo anterior si existe
                    if ($settings['logo'] && file_exists($settings['logo'])) {
                        unlink($settings['logo']);
                    }
                    $logo_path = $upload_path;
                } else {
                    $error = 'Error al subir el logo';
                }
            } else {
                $error = 'Formato de imagen no permitido. Use: JPG, PNG, GIF, WEBP';
            }
        }
        
        if (empty($error)) {
            $stmt = $db->prepare("
                UPDATE settings 
                SET nombre_comercio = ?, rif = ?, direccion = ?, logo = ?, iva = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = 1
            ");
            if ($stmt->execute([$nombre_comercio, $rif, $direccion, $logo_path, $iva])) {
                $success = 'Configuración actualizada exitosamente';
                // Actualizar variables
                $settings['nombre_comercio'] = $nombre_comercio;
                $settings['rif'] = $rif;
                $settings['direccion'] = $direccion;
                $settings['logo'] = $logo_path;
                $settings['iva'] = $iva;
            } else {
                $error = 'Error al guardar la configuración';
            }
        }
    }
}

// Obtener tasa de cambio (con caché para no llamar a la API en cada carga)
$tasa_info = getDolarRate();
if ($tasa_info['success']) {
    $tasa_dolar = $tasa_info['rate'];
} else {
    $tasa_error = $tasa_info['error'];
}

// URL de la API para JavaScript
$api_url = 'https://ve.dolarapi.com/v1/dolares/oficial';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Sistema de Ventas</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Tus estilos existentes... */
        .settings-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }
        
        .settings-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .settings-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        .settings-card h3 {
            color: #2d3748;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .settings-card h3 i {
            color: #667eea;
        }
        
        .logo-preview {
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logo-preview img {
            max-width: 150px;
            max-height: 150px;
            border-radius: 10px;
            border: 2px solid #e2e8f0;
            padding: 5px;
        }
        
        .current-logo {
            margin-top: 10px;
            font-size: 12px;
            color: #718096;
        }
        
        .dolar-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .dolar-card h3 i, .dolar-card h3 {
            color: white;
        }
        
        .dolar-rate {
            text-align: center;
            padding: 20px;
        }
        
        .dolar-rate .rate {
            font-size: 48px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .dolar-rate .date {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .refresh-btn {
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .refresh-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .iva-info {
            background: #f7fafc;
            padding: 15px;
            border-radius: 10px;
            margin-top: 15px;
            color: #2d3748;
        }
        
        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .btn-secondary {
            background: #718096;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .rate-updated {
            animation: pulse 0.5s ease-in-out;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
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
                <?php if (isAdmin()): ?>
                    <li><a href="usuarios.php">👥 Usuarios</a></li>
                   
                    <li><a href="admin_licencias.php">🔑 Licencias</a></li>
                <?php endif; ?>
                <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header>
                <h1><i class="fas fa-cogs"></i> Configuración del Sistema</h1>
                <p>Administra la información de tu comercio y configuración general</p>
            </header>
            
            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($success); ?>
                </div>
            <?php endif; ?>
            
            <div class="settings-container">
                <div class="settings-grid">
                    <!-- Tarjeta de Información del Comercio -->
                    <div class="settings-card">
                        <h3><i class="fas fa-store"></i> Información del Comercio</h3>
                        <form method="POST" action="" enctype="multipart/form-data" id="settingsForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            
                            <div class="form-group">
                                <label for="nombre_comercio">
                                    <i class="fas fa-building"></i> Nombre del Comercio
                                </label>
                                <input type="text" id="nombre_comercio" name="nombre_comercio" 
                                       value="<?php echo htmlspecialchars($settings['nombre_comercio']); ?>" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="rif">
                                    <i class="fas fa-id-card"></i> RIF
                                </label>
                                <input type="text" id="rif" name="rif" 
                                       value="<?php echo htmlspecialchars($settings['rif']); ?>" 
                                       placeholder="J-12345678-0" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="direccion">
                                    <i class="fas fa-map-marker-alt"></i> Dirección
                                </label>
                                <textarea id="direccion" name="direccion" rows="3" required><?php echo htmlspecialchars($settings['direccion']); ?></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label for="iva">
                                    <i class="fas fa-percent"></i> IVA (%)
                                </label>
                                <input type="number" step="0.01" id="iva" name="iva" 
                                       value="<?php echo $settings['iva']; ?>" required>
                                <small>Porcentaje de IVA a aplicar en las facturas</small>
                            </div>
                            
                            <div class="form-group">
                                <label for="logo">
                                    <i class="fas fa-image"></i> Logo del Comercio
                                </label>
                                <div class="logo-preview">
                                    <?php if ($settings['logo'] && file_exists($settings['logo'])): ?>
                                        <img src="<?php echo $settings['logo']; ?>" alt="Logo" id="logoPreview">
                                        <div class="current-logo">
                                            <i class="fas fa-check-circle" style="color: #48bb78;"></i> Logo actual
                                        </div>
                                    <?php else: ?>
                                        <div style="width: 150px; height: 150px; background: #f7fafc; border-radius: 10px; display: flex; align-items: center; justify-content: center; margin: 0 auto;">
                                            <i class="fas fa-store" style="font-size: 50px; color: #cbd5e0;"></i>
                                        </div>
                                        <div class="current-logo">No hay logo configurado</div>
                                    <?php endif; ?>
                                </div>
                                <input type="file" id="logo" name="logo" accept="image/*" onchange="previewImage(this)">
                                <small>Formatos permitidos: JPG, PNG, GIF, WEBP (Máx 2MB)</small>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save"></i> Guardar Configuración
                                </button>
                                <button type="reset" class="btn-secondary" onclick="resetForm()">
                                    <i class="fas fa-undo"></i> Restablecer
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Tarjeta de Tasa de Cambio - CORREGIDA -->
                    <div class="settings-card dolar-card">
                        <h3><i class="fas fa-dollar-sign"></i> Tasa de Cambio Oficial</h3>
                        <div class="dolar-rate">
                            <i class="fas fa-chart-line" style="font-size: 30px;"></i>
                            <div class="rate" id="dolarRate">
                                <?php if ($tasa_dolar): ?>
                                    Bs. <?php echo number_format($tasa_dolar, 2, ',', '.'); ?>
                                <?php else: ?>
                                    <span style="font-size: 16px;">No disponible</span>
                                <?php endif; ?>
                            </div>
                            <div class="date" id="dolarDate">
                                <?php if (isset($tasa_info['data']['fechaActualizacion'])): ?>
                                    Actualizado: <?php echo date('d/m/Y H:i', strtotime($tasa_info['data']['fechaActualizacion'])); ?>
                                <?php else: ?>
                                    Fuente: DolarAPI.com (Tasa promedio)
                                <?php endif; ?>
                            </div>
                            <button onclick="fetchDolarRate()" class="refresh-btn" style="margin-top: 15px;">
                                <i class="fas fa-sync-alt"></i> Actualizar tasa
                            </button>
                            <?php if ($tasa_error): ?>
                                <div style="margin-top: 10px; font-size: 12px; background: rgba(0,0,0,0.3); padding: 5px; border-radius: 5px;">
                                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($tasa_error); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="iva-info">
                            <i class="fas fa-info-circle"></i>
                            <strong>IVA Configurado:</strong> <?php echo $settings['iva']; ?>%
                            <br>
                            <small>Este porcentaje se aplicará automáticamente en las facturas</small>
                        </div>
                    </div>
                </div>
                
                <!-- Vista previa de factura -->
                <div class="settings-card">
                    <h3><i class="fas fa-file-invoice"></i> Vista Previa de Factura</h3>
                    <div id="invoicePreview" style="background: white; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px;">
                        <div style="text-align: center; border-bottom: 2px solid #e2e8f0; padding-bottom: 15px; margin-bottom: 15px;">
                            <?php if ($settings['logo'] && file_exists($settings['logo'])): ?>
                                <img src="<?php echo $settings['logo']; ?>" style="max-height: 60px; margin-bottom: 10px;">
                            <?php endif; ?>
                            <h3 id="previewNombre"><?php echo htmlspecialchars($settings['nombre_comercio']); ?></h3>
                            <p id="previewRif">RIF: <?php echo htmlspecialchars($settings['rif']); ?></p>
                            <p id="previewDireccion"><?php echo htmlspecialchars($settings['direccion']); ?></p>
                        </div>
                        <div style="text-align: center; padding: 20px;">
                            <p>Vista previa del encabezado que aparecerá en tus facturas</p>
                            <p style="color: #718096; font-size: 12px;">IVA aplicado: <span id="previewIva"><?php echo $settings['iva']; ?></span>%</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Función para obtener la tasa de cambio actualizada - CORREGIDA
        async function fetchDolarRate() {
            const rateElement = document.getElementById('dolarRate');
            const dateElement = document.getElementById('dolarDate');
            const refreshBtn = event.currentTarget;
            
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Actualizando...';
            refreshBtn.disabled = true;
            
            try {
                const response = await fetch('https://ve.dolarapi.com/v1/dolares/oficial');
                if (!response.ok) throw new Error('Error en la petición');
                
                const data = await response.json();
                // La API devuelve 'promedio' como campo principal
                if (data && data.promedio) {
                    rateElement.innerHTML = `Bs. ${new Intl.NumberFormat('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(data.promedio)}`;
                    dateElement.innerHTML = `Actualizado: ${new Date(data.fechaActualizacion).toLocaleString('es-VE')}`;
                    rateElement.classList.add('rate-updated');
                    setTimeout(() => rateElement.classList.remove('rate-updated'), 500);
                    
                    showNotification('Tasa de cambio actualizada correctamente', 'success');
                } else {
                    throw new Error('Datos inválidos - no se encontró el campo promedio');
                }
            } catch (error) {
                console.error('Error:', error);
                rateElement.innerHTML = '<span style="font-size: 16px;">Error al obtener tasa</span>';
                showNotification('Error al actualizar la tasa de cambio: ' + error.message, 'error');
            } finally {
                refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Actualizar tasa';
                refreshBtn.disabled = false;
            }
        }
        
        // Función para mostrar notificaciones
        function showNotification(message, type) {
            const notification = document.createElement('div');
            notification.className = `alert ${type === 'success' ? 'success' : 'error'}`;
            notification.style.position = 'fixed';
            notification.style.top = '20px';
            notification.style.right = '20px';
            notification.style.zIndex = '9999';
            notification.style.animation = 'slideInRight 0.5s ease-out';
            notification.innerHTML = `<i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'}"></i> ${message}`;
            document.body.appendChild(notification);
            
            setTimeout(() => {
                notification.style.animation = 'slideOutRight 0.5s ease-out';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }
        
        // Vista previa de imagen
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('logoPreview');
                    if (preview) {
                        preview.src = e.target.result;
                    } else {
                        const img = document.createElement('img');
                        img.id = 'logoPreview';
                        img.src = e.target.result;
                        img.style.maxWidth = '150px';
                        img.style.maxHeight = '150px';
                        img.style.borderRadius = '10px';
                        img.style.border = '2px solid #e2e8f0';
                        img.style.padding = '5px';
                        
                        const container = document.querySelector('.logo-preview');
                        container.innerHTML = '';
                        container.appendChild(img);
                        container.innerHTML += '<div class="current-logo"><i class="fas fa-upload" style="color: #667eea;"></i> Nueva imagen seleccionada</div>';
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        // Actualizar vista previa de factura en tiempo real
        function updateInvoicePreview() {
            const nombre = document.getElementById('nombre_comercio').value;
            const rif = document.getElementById('rif').value;
            const direccion = document.getElementById('direccion').value;
            const iva = document.getElementById('iva').value;
            
            document.getElementById('previewNombre').textContent = nombre || 'Nombre del Comercio';
            document.getElementById('previewRif').innerHTML = `RIF: ${rif || 'J-12345678-0'}`;
            document.getElementById('previewDireccion').textContent = direccion || 'Dirección del comercio';
            document.getElementById('previewIva').textContent = iva || '16';
        }
        
        // Escuchar cambios en los campos
        document.getElementById('nombre_comercio').addEventListener('input', updateInvoicePreview);
        document.getElementById('rif').addEventListener('input', updateInvoicePreview);
        document.getElementById('direccion').addEventListener('input', updateInvoicePreview);
        document.getElementById('iva').addEventListener('input', updateInvoicePreview);
        
        // Resetear formulario a valores originales
        function resetForm() {
            if (confirm('¿Estás seguro de que quieres restablecer los cambios?')) {
                location.reload();
            }
        }
        
        // Actualizar tasa cada 5 minutos automáticamente
        setInterval(fetchDolarRate, 300000); // 5 minutos
    </script>
</body>
</html>