<?php
// licencia.php - Página de verificación de licencia
// NO iniciar sesión aquí porque ya se inicia en functions.php
require_once 'config/database.php';
require_once 'includes/functions.php'; // Este ya tiene session_start()
require_once 'verificar_licencia.php';

// Si no está logueado, redirigir al login
if (!isLoggedIn()) {
    redirect('login.php');
}

// Si ya tiene licencia activa, ir al dashboard
$estado = verificarEstadoLicencia();
if ($estado['valid']) {
    redirect('dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo = trim($_POST['codigo_licencia'] ?? '');
    
    if (empty($codigo)) {
        $error = 'Por favor ingrese el código de licencia';
    } else {
        $resultado = activarLicencia($codigo);
        if ($resultado['success']) {
            $success = $resultado['message'];
            // Redirigir al dashboard después de 2 segundos
            echo '<meta http-equiv="refresh" content="2;url=dashboard.php">';
        } else {
            $error = $resultado['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Licencia - Sistema de Ventas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .modal-licencia {
            background: white;
            border-radius: 20px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.3);
            width: 90%;
            max-width: 500px;
            overflow: hidden;
            animation: modalFadeIn 0.4s ease-out;
        }
        
        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        
        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .modal-header i {
            font-size: 60px;
            margin-bottom: 15px;
        }
        
        .modal-header h2 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .modal-header p {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .info-box {
            background: #f0f4f8;
            border-left: 4px solid #667eea;
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 8px;
        }
        
        .info-box i {
            color: #667eea;
            margin-right: 10px;
        }
        
        .info-box p {
            color: #4a5568;
            font-size: 13px;
            margin: 0;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            color: #2d3748;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn-activar {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-activar:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-activar:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
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
        
        .demo-info {
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            text-align: center;
        }
        
        .demo-info p {
            font-size: 12px;
            color: #718096;
            margin-bottom: 10px;
        }
        
        .demo-code {
            background: #f7fafc;
            padding: 10px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 11px;
            word-break: break-all;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-block;
            max-width: 100%;
        }
        
        .demo-code:hover {
            background: #edf2f7;
            transform: scale(1.02);
        }
        
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
            margin-right: 8px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .text-muted {
            color: #a0aec0;
            font-size: 12px;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="modal-licencia">
        <div class="modal-header">
            <i class="fas fa-key"></i>
            <h2>Verificación de Licencia</h2>
            <p>Active su licencia para continuar</p>
        </div>
        
        <div class="modal-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <p>Este sistema requiere una licencia válida para funcionar. Ingrese el código de activación proporcionado por el administrador.</p>
            </div>
            
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
            
            <form method="POST" action="" id="licenciaForm">
                <div class="form-group">
                    <label for="codigo_licencia">
                        <i class="fas fa-qrcode"></i> Código de Licencia
                    </label>
                    <input type="text" id="codigo_licencia" name="codigo_licencia" 
                           placeholder="Ingrese el código de licencia" 
                           autocomplete="off"
                           required>
                </div>
                
                <button type="submit" class="btn-activar" id="btnActivar">
                    <i class="fas fa-unlock-alt"></i> Activar Licencia
                </button>
            </form>
            
            <div class="demo-info">
                <p><i class="fas fa-shield-alt"></i> La licencia se activará permanentemente en este equipo</p>
                
            </div>
        </div>
    </div>
    
    <script>
        // Auto mayúsculas
        const input = document.getElementById('codigo_licencia');
        if (input) {
            input.addEventListener('input', function() {
                this.value = this.value.toUpperCase();
            });
        }
        
        // Mostrar loading al enviar
        const form = document.getElementById('licenciaForm');
        const btn = document.getElementById('btnActivar');
        
        if (form && btn) {
            form.addEventListener('submit', function() {
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<span class="spinner"></span> Verificando licencia...';
                btn.disabled = true;
            });
        }
        
        // Función para copiar código
        function copiarCodigo() {
            const codigo = 'A7F3E9C2B8D1F4E6A9C3B7D8E2F1A4C5';
            navigator.clipboard.writeText(codigo).then(() => {
                const demoCode = document.querySelector('.demo-code');
                const originalHtml = demoCode.innerHTML;
                demoCode.innerHTML = '<i class="fas fa-check"></i> ¡Código copiado!';
                setTimeout(() => {
                    demoCode.innerHTML = originalHtml;
                }, 2000);
            }).catch(() => {
                alert('No se pudo copiar el código');
            });
        }
    </script>
</body>
</html>