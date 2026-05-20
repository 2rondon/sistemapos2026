<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('dashboard.php');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Procesar acciones de administración
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'cambiar_rol') {
                $usuario_id = intval($_POST['usuario_id']);
                $nuevo_rol = $_POST['nuevo_rol'];
                
                if ($usuario_id != $_SESSION['user_id']) {
                    $stmt = $db->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
                    if ($stmt->execute([$nuevo_rol, $usuario_id])) {
                        $success = "Rol actualizado exitosamente";
                    } else {
                        $error = "Error al actualizar el rol";
                    }
                } else {
                    $error = "No puedes cambiar tu propio rol";
                }
            } elseif ($_POST['action'] === 'eliminar') {
                $usuario_id = intval($_POST['usuario_id']);
                
                if ($usuario_id != $_SESSION['user_id']) {
                    $stmt = $db->prepare("DELETE FROM usuarios WHERE id = ?");
                    if ($stmt->execute([$usuario_id])) {
                        $success = "Usuario eliminado exitosamente";
                    } else {
                        $error = "Error al eliminar el usuario";
                    }
                } else {
                    $error = "No puedes eliminar tu propio usuario";
                }
            }
        }
    }
}

// Obtener usuarios
$stmt = $db->query("SELECT id, username, email, rol, created_at FROM usuarios ORDER BY id");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Sistema de Ventas</title>
    
    <!-- Importación del CSS externo -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
                <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header>
                <h1>👥 Gestión de Usuarios</h1>
            </header>
            
            <?php if ($error): ?>
                <div class="alert error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="usuarios-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuario</th>
                            <th>Email</th>
                            <th>Rol</th>
                            <th>Fecha Registro</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $usuario): ?>
                        <tr>
                            <td><?php echo $usuario['id']; ?></td>
                            <td><?php echo htmlspecialchars($usuario['username']); ?></td>
                            <td><?php echo htmlspecialchars($usuario['email']); ?></td>
                            <td>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="cambiar_rol">
                                    <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
                                    <select name="nuevo_rol" onchange="this.form.submit()" <?php echo $usuario['id'] == $_SESSION['user_id'] ? 'disabled' : ''; ?>>
                                        <option value="admin" <?php echo $usuario['rol'] == 'admin' ? 'selected' : ''; ?>>👑 Administrador</option>
                                        <option value="vendedor" <?php echo $usuario['rol'] == 'vendedor' ? 'selected' : ''; ?>>💼 Vendedor</option>
                                    </select>
                                </form>
                            </td>
                            <td><?php echo $usuario['created_at']; ?></td>
                            <td>
                                <?php if ($usuario['id'] != $_SESSION['user_id']): ?>
                                <form method="POST" action="" style="display: inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="action" value="eliminar">
                                    <input type="hidden" name="usuario_id" value="<?php echo $usuario['id']; ?>">
                                    <button type="submit" class="btn-small btn-danger" onclick="return confirm('¿Estás seguro de eliminar este usuario?')">🗑️ Eliminar</button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted">✅ Usuario actual</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>