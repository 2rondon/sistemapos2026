<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        if (isset($_POST['action'])) {
            if ($_POST['action'] === 'crear' && (isAdmin() || $_SESSION['rol'] === 'vendedor')) {
                $nombre = trim($_POST['nombre']);
                $descripcion = trim($_POST['descripcion']);
                $precio_bs = floatval($_POST['precio_bs']);
                $precio_usd = floatval($_POST['precio_usd']);
                $stock = intval($_POST['stock']);
                
                $stmt = $db->prepare("INSERT INTO productos (nombre, descripcion, precio_bs, precio_usd, stock) VALUES (?, ?, ?, ?, ?)");
                if ($stmt->execute([$nombre, $descripcion, $precio_bs, $precio_usd, $stock])) {
                    $success = "Producto creado exitosamente";
                } else {
                    $error = "Error al crear el producto";
                }
            } elseif ($_POST['action'] === 'editar' && isAdmin()) {
                $id = intval($_POST['id']);
                $nombre = trim($_POST['nombre']);
                $descripcion = trim($_POST['descripcion']);
                $precio_bs = floatval($_POST['precio_bs']);
                $precio_usd = floatval($_POST['precio_usd']);
                $stock = intval($_POST['stock']);
                
                $stmt = $db->prepare("UPDATE productos SET nombre = ?, descripcion = ?, precio_bs = ?, precio_usd = ?, stock = ? WHERE id = ?");
                if ($stmt->execute([$nombre, $descripcion, $precio_bs, $precio_usd, $stock, $id])) {
                    $success = "Producto actualizado exitosamente";
                } else {
                    $error = "Error al actualizar el producto";
                }
            } elseif ($_POST['action'] === 'eliminar' && isAdmin()) {
                $id = intval($_POST['id']);
                $stmt = $db->prepare("DELETE FROM productos WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $success = "Producto eliminado exitosamente";
                } else {
                    $error = "Error al eliminar el producto";
                }
            }
        }
    }
}

// Obtener productos
$stmt = $db->query("SELECT * FROM productos ORDER BY id DESC");
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - Sistema de Ventas</title>
    
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
                <?php if (isAdmin()): ?>
                    <li><a href="usuarios.php">👥 Usuarios</a></li>
                <?php endif; ?>
                <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header>
                <h1>Productos</h1>
                <?php if (isAdmin() || $_SESSION['rol'] === 'vendedor'): ?>
                    <button onclick="mostrarFormulario()" class="btn-primary">➕ Nuevo Producto</button>
                <?php endif; ?>
            </header>
            
            <?php if ($error): ?>
                <div class="alert error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="productos-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nombre</th>
                            <th>Descripción</th>
                            <th>Precio Bs</th>
                            <th>Precio USD</th>
                            <th>Stock</th>
                            <?php if (isAdmin()): ?>
                                <th>Acciones</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($productos as $producto): ?>
                        <tr>
                            <td><?php echo $producto['id']; ?></td>
                            <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                            <td><?php echo htmlspecialchars($producto['descripcion']); ?></td>
                            <td><?php echo formatMoney($producto['precio_bs']); ?></td>
                            <td><?php echo formatMoney($producto['precio_usd'], 'USD'); ?></td>
                            <td><?php echo $producto['stock']; ?></td>
                            <?php if (isAdmin()): ?>
                                <td>
                                    <button onclick="editarProducto(<?php echo $producto['id']; ?>)" class="btn-small">✏️ Editar</button>
                                    <button onclick="eliminarProducto(<?php echo $producto['id']; ?>)" class="btn-small btn-danger">🗑️ Eliminar</button>
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <?php if (isAdmin() || $_SESSION['rol'] === 'vendedor'): ?>
    <div id="productoModal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close" onclick="cerrarModal()">&times;</span>
            <h2 id="modalTitle">📝 Nuevo Producto</h2>
            <form method="POST" action="" id="productoForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" id="formAction" value="crear">
                <input type="hidden" name="id" id="productoId">
                
                <div class="form-group">
                    <label for="nombre">📛 Nombre:</label>
                    <input type="text" id="nombre" name="nombre" required>
                </div>
                <div class="form-group">
                    <label for="descripcion">📝 Descripción:</label>
                    <textarea id="descripcion" name="descripcion" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label for="precio_bs">🇻🇪 Precio en Bolívares:</label>
                    <input type="number" step="0.01" id="precio_bs" name="precio_bs" required>
                </div>
                <div class="form-group">
                    <label for="precio_usd">🇺🇸 Precio en Dólares:</label>
                    <input type="number" step="0.01" id="precio_usd" name="precio_usd" required>
                </div>
                <div class="form-group">
                    <label for="stock">📦 Stock:</label>
                    <input type="number" id="stock" name="stock" required>
                </div>
                <button type="submit" class="btn-primary">💾 Guardar</button>
            </form>
        </div>
    </div>
    
    <script>
    const modal = document.getElementById('productoModal');
    
    function mostrarFormulario() {
        document.getElementById('modalTitle').innerHTML = '📝 Nuevo Producto';
        document.getElementById('formAction').value = 'crear';
        document.getElementById('productoId').value = '';
        document.getElementById('nombre').value = '';
        document.getElementById('descripcion').value = '';
        document.getElementById('precio_bs').value = '';
        document.getElementById('precio_usd').value = '';
        document.getElementById('stock').value = '';
        modal.style.display = 'block';
    }
    
    function editarProducto(id) {
        window.location.href = `editar_producto.php?id=${id}`;
    }
    
    function eliminarProducto(id) {
        if (confirm('¿Estás seguro de eliminar este producto?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="action" value="eliminar">
                <input type="hidden" name="id" value="${id}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    function cerrarModal() {
        modal.style.display = 'none';
    }
    
    window.onclick = function(event) {
        if (event.target === modal) {
            modal.style.display = 'none';
        }
    }
    </script>
    <?php endif; ?>
</body>
</html>