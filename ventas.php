<?php
require_once 'config/database.php';
require_once 'includes/functions.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$db = Database::getInstance()->getConnection();
$tasa_cambio = getTasaCambio();
$error = '';
$success = '';
$ultima_venta_id = null;
$ultima_venta_detalles = null;

// Obtener productos
$stmt = $db->query("SELECT * FROM productos WHERE stock > 0 ORDER BY nombre");
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verificarToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token de seguridad inválido';
    } else {
        $productos_json = $_POST['productos'] ?? '';
        $productos_venta = json_decode($productos_json, true);
        $moneda = $_POST['moneda'] ?? 'Bs';
        
        if (empty($productos_venta)) {
            $error = 'Debe agregar al menos un producto';
        } else {
            try {
                $db->beginTransaction();
                
                $total_bs = 0;
                $total_usd = 0;
                
                foreach ($productos_venta as $item) {
                    $stmt = $db->prepare("SELECT * FROM productos WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$producto || $producto['stock'] < $item['cantidad']) {
                        throw new Exception("Stock insuficiente para {$producto['nombre']}");
                    }
                    
                    $subtotal_bs = $producto['precio_bs'] * $item['cantidad'];
                    $subtotal_usd = $producto['precio_usd'] * $item['cantidad'];
                    $total_bs += $subtotal_bs;
                    $total_usd += $subtotal_usd;
                    
                    // Actualizar stock
                    $stmt = $db->prepare("UPDATE productos SET stock = stock - ? WHERE id = ?");
                    $stmt->execute([$item['cantidad'], $item['id']]);
                }
                
                // Registrar venta
                $stmt = $db->prepare("
                    INSERT INTO ventas (usuario_id, total_bs, total_usd, moneda, tasa_cambio) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $total_bs, $total_usd, $moneda, $tasa_cambio]);
                $venta_id = $db->lastInsertId();
                $ultima_venta_id = $venta_id;
                
                // Registrar detalles
                $detalles = [];
                foreach ($productos_venta as $item) {
                    $stmt = $db->prepare("SELECT * FROM productos WHERE id = ?");
                    $stmt->execute([$item['id']]);
                    $producto = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $subtotal_bs = $producto['precio_bs'] * $item['cantidad'];
                    $subtotal_usd = $producto['precio_usd'] * $item['cantidad'];
                    
                    $stmt = $db->prepare("
                        INSERT INTO ventas_detalles (venta_id, producto_id, cantidad, precio_bs, precio_usd, subtotal_bs, subtotal_usd) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$venta_id, $item['id'], $item['cantidad'], $producto['precio_bs'], $producto['precio_usd'], $subtotal_bs, $subtotal_usd]);
                    
                    $detalles[] = [
                        'nombre' => $producto['nombre'],
                        'cantidad' => $item['cantidad'],
                        'precio_bs' => $producto['precio_bs'],
                        'precio_usd' => $producto['precio_usd'],
                        'subtotal_bs' => $subtotal_bs,
                        'subtotal_usd' => $subtotal_usd
                    ];
                }
                
                $db->commit();
                
                // Guardar detalles de la venta para la factura
                $ultima_venta_detalles = [
                    'id' => $venta_id,
                    'total_bs' => $total_bs,
                    'total_usd' => $total_usd,
                    'moneda' => $moneda,
                    'fecha' => date('Y-m-d H:i:s'),
                    'productos' => $detalles
                ];
                
                $success = "Venta registrada exitosamente. Total: " . formatMoney($moneda == 'Bs' ? $total_bs : $total_usd, $moneda);
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        }
    }
}

// Obtener configuración del comercio
$settings = getSettings();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Venta - Sistema de Ventas</title>
    
    <!-- Importación del CSS externo -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Estilos para el modal de factura */
        .modal-factura {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            animation: fadeIn 0.3s ease-out;
        }
        
        .modal-factura-content {
            background-color: white;
            margin: 5% auto;
            padding: 0;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            position: relative;
            animation: slideInDown 0.3s ease-out;
        }
        
        .factura-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px 10px 0 0;
            text-align: center;
        }
        
        .factura-body {
            padding: 20px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .factura-footer {
            padding: 15px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            gap: 10px;
            justify-content: center;
        }
        
        .btn-imprimir {
            background: #48bb78;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-imprimir:hover {
            background: #38a169;
            transform: scale(1.05);
        }
        
        .btn-cerrar {
            background: #718096;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        .btn-cerrar:hover {
            background: #4a5568;
        }
        
        /* Estilos para la factura de 58mm */
        .factura-58mm {
            width: 58mm;
            font-family: 'Courier New', monospace;
            font-size: 10px;
            line-height: 1.3;
            margin: 0 auto;
            padding: 5px;
        }
        
        .factura-58mm .titulo {
            text-align: center;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .factura-58mm .datos-comercio {
            text-align: center;
            font-size: 9px;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 1px dashed #000;
        }
        
        .factura-58mm .info-venta {
            font-size: 9px;
            margin-bottom: 10px;
        }
        
        .factura-58mm .productos {
            width: 100%;
            margin-bottom: 10px;
            border-collapse: collapse;
        }
        
        .factura-58mm .productos th {
            border-bottom: 1px dashed #000;
            padding: 3px 0;
            text-align: left;
            font-size: 9px;
        }
        
        .factura-58mm .productos td {
            padding: 3px 0;
            font-size: 9px;
        }
        
        .factura-58mm .totales {
            border-top: 1px dashed #000;
            padding-top: 5px;
            margin-top: 5px;
            text-align: right;
        }
        
        .factura-58mm .footer {
            text-align: center;
            font-size: 8px;
            margin-top: 10px;
            padding-top: 5px;
            border-top: 1px dashed #000;
        }
        
        .factura-58mm .gracias {
            text-align: center;
            font-size: 10px;
            font-weight: bold;
            margin-top: 10px;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            .factura-58mm, .factura-58mm * {
                visibility: visible;
            }
            .factura-58mm {
                position: absolute;
                left: 0;
                top: 0;
                width: 58mm;
                margin: 0;
                padding: 5px;
            }
            .modal-factura {
                display: none !important;
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
                <?php endif; ?>
                <li><a href="logout.php">🚪 Cerrar Sesión</a></li>
            </ul>
        </nav>
        <div class="main-content">
            <header>
                <h1>💰 Registrar Venta</h1>
            </header>
            
            <?php if ($error): ?>
                <div class="alert error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert success">✅ <?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <div class="venta-container">
                <div class="productos-disponibles">
                    <h2>📦 Productos Disponibles</h2>
                    <table id="productos-table">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Precio Bs</th>
                                <th>Precio USD</th>
                                <th>Stock</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productos as $producto): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                <td><?php echo formatMoney($producto['precio_bs']); ?></td>
                                <td><?php echo formatMoney($producto['precio_usd'], 'USD'); ?></td>
                                <td><?php echo $producto['stock']; ?></td>
                                <td>
                                    <button onclick="agregarProducto(<?php echo $producto['id']; ?>, '<?php echo htmlspecialchars($producto['nombre']); ?>', <?php echo $producto['precio_bs']; ?>, <?php echo $producto['precio_usd']; ?>, <?php echo $producto['stock']; ?>)" class="btn-small">
                                        ➕ Agregar
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="carrito">
                    <h2>🛒 Carrito de Venta</h2>
                    <form method="POST" action="" id="venta-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="productos" id="productos-json">
                        
                        <div class="form-group">
                            <label for="moneda">💵 Moneda de Pago:</label>
                            <select id="moneda" name="moneda" required>
                                <option value="Bs">🇻🇪 Bolívares (Bs)</option>
                                <option value="USD">🇺🇸 Dólares (USD)</option>
                            </select>
                        </div>
                        
                        <table id="carrito-table">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Cantidad</th>
                                    <th>Precio</th>
                                    <th>Subtotal</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody id="carrito-body">
                                <tr>
                                    <td colspan="5" style="text-align: center; padding: 40px;">
                                        🛍️ No hay productos en el carrito
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        
                        <div class="total-venta">
                            <strong>💰 Total: </strong>
                            <span id="total-carrito">Bs 0.00</span>
                        </div>
                        
                        <button type="submit" class="btn-primary">✅ Registrar Venta</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para la factura -->
    <div id="modalFactura" class="modal-factura">
        <div class="modal-factura-content">
            <div class="factura-header">
                <h3><i class="fas fa-receipt"></i> Factura de Venta</h3>
            </div>
            <div class="factura-body" id="facturaBody">
                <!-- Aquí se cargará la factura -->
            </div>
            <div class="factura-footer">
                <button class="btn-imprimir" onclick="imprimirFactura()">
                    <i class="fas fa-print"></i> Imprimir Factura
                </button>
                <button class="btn-cerrar" onclick="cerrarModalFactura()">
                    <i class="fas fa-times"></i> Cerrar
                </button>
            </div>
        </div>
    </div>
    
    <script>
    let carrito = [];
    let ultimaVenta = <?php echo json_encode($ultima_venta_detalles); ?>;
    
    function actualizarCarrito() {
        const tbody = document.getElementById('carrito-body');
        tbody.innerHTML = '';
        let total = 0;
        const moneda = document.getElementById('moneda').value;
        
        if (carrito.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 40px;">🛍️ No hay productos en el carrito</td></tr>';
            document.getElementById('total-carrito').innerHTML = moneda === 'Bs' ? 'Bs 0.00' : '$0.00';
            document.getElementById('productos-json').value = '';
            return;
        }
        
        carrito.forEach((item, index) => {
            const subtotal = moneda === 'Bs' ? item.precio_bs * item.cantidad : item.precio_usd * item.cantidad;
            total += subtotal;
            
            const row = tbody.insertRow();
            row.insertCell(0).innerHTML = item.nombre;
            row.insertCell(1).innerHTML = `
                <input type="number" value="${item.cantidad}" min="1" max="${item.stock}" 
                       onchange="actualizarCantidad(${index}, this.value)" style="width: 70px; padding: 5px;">
            `;
            row.insertCell(2).innerHTML = moneda === 'Bs' ? 
                `Bs ${item.precio_bs.toFixed(2)}` : `$${item.precio_usd.toFixed(2)}`;
            row.insertCell(3).innerHTML = moneda === 'Bs' ? 
                `Bs ${subtotal.toFixed(2)}` : `$${subtotal.toFixed(2)}`;
            row.insertCell(4).innerHTML = `<button onclick="eliminarProducto(${index})" class="btn-small btn-danger">🗑️ Eliminar</button>`;
        });
        
        const totalSpan = document.getElementById('total-carrito');
        totalSpan.innerHTML = moneda === 'Bs' ? `Bs ${total.toFixed(2)}` : `$${total.toFixed(2)}`;
        
        document.getElementById('productos-json').value = JSON.stringify(carrito.map(item => ({
            id: item.id,
            cantidad: item.cantidad
        })));
    }
    
    function agregarProducto(id, nombre, precio_bs, precio_usd, stock) {
        const existing = carrito.find(item => item.id === id);
        if (existing) {
            if (existing.cantidad < stock) {
                existing.cantidad++;
                mostrarNotificacion(`📦 ${nombre} - Cantidad aumentada a ${existing.cantidad}`, 'success');
            } else {
                mostrarNotificacion(`⚠️ No hay suficiente stock de ${nombre}`, 'error');
            }
        } else {
            carrito.push({
                id: id,
                nombre: nombre,
                precio_bs: precio_bs,
                precio_usd: precio_usd,
                cantidad: 1,
                stock: stock
            });
            mostrarNotificacion(`✅ ${nombre} agregado al carrito`, 'success');
        }
        actualizarCarrito();
    }
    
    function actualizarCantidad(index, cantidad) {
        cantidad = parseInt(cantidad);
        if (cantidad > 0 && cantidad <= carrito[index].stock) {
            carrito[index].cantidad = cantidad;
            actualizarCarrito();
        } else {
            mostrarNotificacion('⚠️ Cantidad inválida o stock insuficiente', 'error');
        }
    }
    
    function eliminarProducto(index) {
        const producto = carrito[index];
        carrito.splice(index, 1);
        mostrarNotificacion(`🗑️ ${producto.nombre} eliminado del carrito`, 'info');
        actualizarCarrito();
    }
    
    function mostrarNotificacion(mensaje, tipo) {
        const notificacion = document.createElement('div');
        notificacion.className = `alert ${tipo === 'success' ? 'success' : (tipo === 'error' ? 'error' : 'info')}`;
        notificacion.style.position = 'fixed';
        notificacion.style.top = '20px';
        notificacion.style.right = '20px';
        notificacion.style.zIndex = '9999';
        notificacion.style.animation = 'slideInRight 0.5s ease-out';
        notificacion.innerHTML = mensaje;
        
        document.body.appendChild(notificacion);
        
        setTimeout(() => {
            notificacion.style.animation = 'slideOutRight 0.5s ease-out';
            setTimeout(() => {
                document.body.removeChild(notificacion);
            }, 500);
        }, 3000);
    }
    
    // Función para generar la factura en formato 58mm
    function generarFactura(venta) {
        const settings = <?php echo json_encode($settings); ?>;
        const moneda = venta.moneda;
        const esBolivares = moneda === 'Bs';
        const total = esBolivares ? venta.total_bs : venta.total_usd;
        const tasaCambio = <?php echo $tasa_cambio; ?>;
        
        let html = `
            <div class="factura-58mm">
                <div class="titulo">
                    ${settings.logo ? `<img src="${settings.logo}" style="max-height: 40px; margin-bottom: 5px;"><br>` : ''}
                    <strong>${settings.nombre_comercio}</strong>
                </div>
                <div class="datos-comercio">
                    RIF: ${settings.rif}<br>
                    ${settings.direccion}<br>
                    Tel: ${settings.telefono || 'No disponible'}
                </div>
                <div class="info-venta">
                    FACTURA N°: ${venta.id}<br>
                    FECHA: ${new Date(venta.fecha).toLocaleString('es-VE')}<br>
                    VENDEDOR: ${<?php echo json_encode($_SESSION['username']); ?>}<br>
                    MONEDA: ${moneda} ${!esBolivares ? ` (Tasa: Bs. ${tasaCambio.toFixed(2)})` : ''}
                </div>
                <hr>
                <table class="productos">
                    <thead>
                        <tr><th>PRODUCTO</th><th>CANT</th><th>PRECIO</th><th>SUBTOTAL</th></tr>
                    </thead>
                    <tbody>
        `;
        
        venta.productos.forEach(producto => {
            const precio = esBolivares ? producto.precio_bs : producto.precio_usd;
            const subtotal = esBolivares ? producto.subtotal_bs : producto.subtotal_usd;
            html += `
                <tr>
                    <td>${producto.nombre.substring(0, 20)}</td>
                    <td style="text-align: center;">${producto.cantidad}</td>
                    <td style="text-align: right;">${esBolivares ? 'Bs.' : '$'} ${precio.toFixed(2)}</td>
                    <td style="text-align: right;">${esBolivares ? 'Bs.' : '$'} ${subtotal.toFixed(2)}</td>
                </tr>
            `;
        });
        
        const iva = settings.iva || 16;
        const subtotal = total / (1 + (iva / 100));
        const montoIva = total - subtotal;
        
        html += `
                    </tbody>
                </table>
                <hr>
                <div class="totales">
                    SUBTOTAL: ${esBolivares ? 'Bs.' : '$'} ${subtotal.toFixed(2)}<br>
                    IVA (${iva}%): ${esBolivares ? 'Bs.' : '$'} ${montoIva.toFixed(2)}<br>
                    <strong>TOTAL: ${esBolivares ? 'Bs.' : '$'} ${total.toFixed(2)}</strong>
                </div>
                <div class="footer">
                    ¡Gracias por su compra!<br>
                    Este documento es una factura de venta<br>
                    Válido como comprobante fiscal
                </div>
                <div class="gracias">
                    ¡VUELVA PRONTO!
                </div>
            </div>
        `;
        
        return html;
    }
    
    // Función para mostrar el modal con la factura
    function mostrarFactura() {
        if (ultimaVenta) {
            const facturaHtml = generarFactura(ultimaVenta);
            document.getElementById('facturaBody').innerHTML = facturaHtml;
            document.getElementById('modalFactura').style.display = 'block';
        }
    }
    
    function imprimirFactura() {
    const facturaContent = document.querySelector('.factura-58mm').cloneNode(true);
    // Cambiar la clase para 80mm
    facturaContent.classList.remove('factura-58mm');
    facturaContent.classList.add('factura-80mm');
    
    const ventana = window.open('', '_blank');
    ventana.document.write(`
        <!DOCTYPE html>
        <html>
            <head>
                <title>Factura #${ultimaVenta.id}</title>
                <meta charset="UTF-8">
                <style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    @page {
        size: 80mm auto;
        margin: 0mm;
    }
    
    body {
        font-family: 'Courier New', 'Monaco', 'Consolas', monospace;
        margin: 0;
        padding: 3mm;
        width: 80mm;
        background: white;
        font-size: 10pt;
        line-height: 1.3;
    }
    
    .factura-80mm {
        width: 100%;
        max-width: 80mm;
        margin: 0 auto;
        padding: 2mm;
        font-family: 'Courier New', 'Monaco', 'Consolas', monospace;
    }
    
    .factura-80mm .titulo {
        text-align: center;
        font-size: 14pt;
        font-weight: bold;
        margin-bottom: 4mm;
        padding-bottom: 2mm;
        border-bottom: 2px dashed #000;
    }
    
    .factura-80mm .logo {
        text-align: center;
        margin-bottom: 3mm;
    }
    
    .factura-80mm .logo img {
        max-height: 15mm;
        width: auto;
    }
    
    .factura-80mm .datos-comercio {
        text-align: center;
        font-size: 9pt;
        margin-bottom: 4mm;
        padding-bottom: 2mm;
        border-bottom: 1px dashed #000;
        line-height: 1.4;
    }
    
    .factura-80mm .info-venta {
        font-size: 9pt;
        margin-bottom: 4mm;
        padding: 3mm;
        background: #f9f9f9;
        line-height: 1.4;
        border-radius: 3px;
    }
    
    .factura-80mm .info-venta p {
        margin: 1.5mm 0;
    }
    
    .factura-80mm hr {
        border: none;
        border-top: 1px dashed #000;
        margin: 3mm 0;
    }
    
    /* Separador de líneas para mejor lectura */
    .factura-80mm .separador {
        border: none;
        border-top: 1px solid #000;
        margin: 2mm 0;
    }
    
    .factura-80mm .separador-punteado {
        border: none;
        border-top: 1px dotted #000;
        margin: 2mm 0;
    }
    
    .factura-80mm .productos {
        width: 100%;
        border-collapse: collapse;
        font-size: 9pt;
        margin-bottom: 4mm;
    }
    
    .factura-80mm .productos th {
        text-align: left;
        padding: 1.5mm 0;
        border-bottom: 1px dotted #000;
        font-weight: bold;
        font-size: 9pt;
    }
    
    .factura-80mm .productos td {
        padding: 1.5mm 0;
        vertical-align: top;
        font-size: 9pt;
        border-bottom: 1px dotted #eee;
    }
    
    /* Ajuste de anchos de columnas con mejor separación */
    .factura-80mm .productos td:first-child {
        width: 40%;
        padding-right: 2mm;
    }
    
    .factura-80mm .productos td:nth-child(2) {
        text-align: center;
        width: 12%;
        padding: 1.5mm 1mm;
    }
    
    .factura-80mm .productos td:nth-child(3) {
        text-align: right;
        width: 22%;
        padding: 1.5mm 1mm;
        letter-spacing: 0.5px;
    }
    
    .factura-80mm .productos td:nth-child(4) {
        text-align: right;
        width: 26%;
        padding: 1.5mm 0 1.5mm 2mm;
        letter-spacing: 0.5px;
        font-weight: 500;
    }
    
    /* Espaciado entre columnas */
    .factura-80mm .productos th:first-child,
    .factura-80mm .productos td:first-child {
        padding-left: 1mm;
    }
    
    .factura-80mm .productos th:last-child,
    .factura-80mm .productos td:last-child {
        padding-right: 1mm;
    }
    
    /* Formato de precios con mejor alineación */
    .factura-80mm .precio {
        text-align: right;
        font-family: 'Courier New', monospace;
        letter-spacing: 0.3px;
    }
    
    .factura-80mm .subtotal {
        text-align: right;
        font-weight: bold;
        font-family: 'Courier New', monospace;
        letter-spacing: 0.3px;
    }
    
    .factura-80mm .totales {
        text-align: right;
        border-top: 1px dashed #000;
        padding-top: 3mm;
        margin-top: 3mm;
        font-size: 10pt;
    }
    
    .factura-80mm .totales p {
        margin: 1.5mm 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .factura-80mm .totales .total-label {
        font-weight: normal;
    }
    
    .factura-80mm .totales .total-value {
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    
    .factura-80mm .totales strong {
        font-size: 11pt;
    }
    
    .factura-80mm .footer {
        text-align: center;
        font-size: 8pt;
        margin-top: 4mm;
        padding-top: 2mm;
        border-top: 1px dashed #000;
        line-height: 1.4;
    }
    
    .factura-80mm .gracias {
        text-align: center;
        font-size: 11pt;
        font-weight: bold;
        margin-top: 4mm;
        padding-top: 2mm;
    }
    
    /* Línea divisoria entre productos */
    .factura-80mm .productos tr:not(:last-child) td {
        border-bottom: 1px dotted #ccc;
    }
    
    /* Mejora visual para los totales */
    .factura-80mm .totales-line {
        display: flex;
        justify-content: space-between;
        padding: 1.5mm 0;
    }
    
    .factura-80mm .totales-line span:first-child {
        font-weight: normal;
    }
    
    .factura-80mm .totales-line span:last-child {
        font-weight: bold;
        letter-spacing: 0.5px;
    }
    
    /* Espaciador entre productos */
    .factura-80mm .espacio-productos {
        margin-bottom: 1mm;
    }
    
    @media print {
        body {
            margin: 0;
            padding: 0;
        }
        .no-print {
            display: none;
        }
    }
</style>
            </head>
            <body>
                ${facturaContent.outerHTML}
                <script>
                    window.onload = function() {
                        setTimeout(function() {
                            window.print();
                            setTimeout(function() {
                                window.close();
                            }, 1000);
                        }, 300);
                    }
                <\/script>
            </body>
        </html>
    `);
    ventana.document.close();
}

function guardarComoPDF() {
    const facturaContent = document.querySelector('.factura-58mm').cloneNode(true);
    // Cambiar la clase para 80mm
    facturaContent.classList.remove('factura-58mm');
    facturaContent.classList.add('factura-80mm');
    
    const ventana = window.open('', '_blank');
    
    ventana.document.write(`
        <!DOCTYPE html>
        <html>
            <head>
                <title>Factura #${ultimaVenta.id}</title>
                <meta charset="UTF-8">
                <style>
                    * {
                        margin: 0;
                        padding: 0;
                        box-sizing: border-box;
                    }
                    
                    @page {
                        size: 80mm auto;
                        margin: 0mm;
                    }
                    
                    body {
                        font-family: 'Courier New', 'Monaco', 'Consolas', monospace;
                        margin: 0;
                        padding: 4mm;
                        width: 80mm;
                        background: white;
                        font-size: 10pt;
                        line-height: 1.3;
                    }
                    
                    .factura-80mm {
                        width: 100%;
                        max-width: 80mm;
                        margin: 0 auto;
                        padding: 2mm;
                        font-family: 'Courier New', 'Monaco', 'Consolas', monospace;
                    }
                    
                    .factura-80mm .titulo {
                        text-align: center;
                        font-size: 14pt;
                        font-weight: bold;
                        margin-bottom: 4mm;
                        padding-bottom: 2mm;
                        border-bottom: 2px dashed #000;
                    }
                    
                    .factura-80mm .logo {
                        text-align: center;
                        margin-bottom: 3mm;
                    }
                    
                    .factura-80mm .logo img {
                        max-height: 15mm;
                        width: auto;
                    }
                    
                    .factura-80mm .datos-comercio {
                        text-align: center;
                        font-size: 9pt;
                        margin-bottom: 4mm;
                        padding-bottom: 2mm;
                        border-bottom: 1px dashed #000;
                        line-height: 1.4;
                    }
                    
                    .factura-80mm .info-venta {
                        font-size: 9pt;
                        margin-bottom: 4mm;
                        padding: 3mm;
                        background: #f9f9f9;
                        line-height: 1.4;
                        border-radius: 3px;
                    }
                    
                    .factura-80mm .info-venta p {
                        margin: 1.5mm 0;
                    }
                    
                    .factura-80mm hr {
                        border: none;
                        border-top: 1px dashed #000;
                        margin: 3mm 0;
                    }
                    
                    .factura-80mm .productos {
                        width: 100%;
                        border-collapse: collapse;
                        font-size: 9pt;
                        margin-bottom: 4mm;
                    }
                    
                    .factura-80mm .productos th {
                        text-align: left;
                        padding: 1.5mm 0;
                        border-bottom: 1px dotted #000;
                        font-weight: bold;
                        font-size: 9pt;
                    }
                    
                    .factura-80mm .productos td {
                        padding: 1.5mm 0;
                        vertical-align: top;
                        font-size: 9pt;
                    }
                    
                    .factura-80mm .productos td:first-child {
                        width: 45%;
                    }
                    
                    .factura-80mm .productos td:nth-child(2) {
                        text-align: center;
                        width: 15%;
                    }
                    
                    .factura-80mm .productos td:nth-child(3) {
                        text-align: right;
                        width: 20%;
                    }
                    
                    .factura-80mm .productos td:nth-child(4) {
                        text-align: right;
                        width: 20%;
                    }
                    
                    .factura-80mm .totales {
                        text-align: right;
                        border-top: 1px dashed #000;
                        padding-top: 3mm;
                        margin-top: 3mm;
                        font-size: 10pt;
                    }
                    
                    .factura-80mm .totales p {
                        margin: 1.5mm 0;
                    }
                    
                    .factura-80mm .totales strong {
                        font-size: 11pt;
                    }
                    
                    .factura-80mm .footer {
                        text-align: center;
                        font-size: 8pt;
                        margin-top: 4mm;
                        padding-top: 2mm;
                        border-top: 1px dashed #000;
                        line-height: 1.4;
                    }
                    
                    .factura-80mm .gracias {
                        text-align: center;
                        font-size: 11pt;
                        font-weight: bold;
                        margin-top: 4mm;
                        padding-top: 2mm;
                    }
                    
                    @media print {
                        body {
                            margin: 0;
                            padding: 0;
                        }
                        .no-print {
                            display: none;
                        }
                    }
                </style>
            </head>
            <body>
                ${facturaContent.outerHTML}
                <script>
                    window.onload = function() {
                        // Mostrar diálogo de impresión para guardar como PDF
                        window.print();
                    }
                <\/script>
            </body>
        </html>
    `);
    ventana.document.close();
}

// Función adicional para generar factura en 80mm con mejor formato
function generarFactura80mm(venta) {
    const settings = <?php echo json_encode($settings); ?>;
    const moneda = venta.moneda;
    const esBolivares = moneda === 'Bs';
    const total = esBolivares ? venta.total_bs : venta.total_usd;
    const tasaCambio = <?php echo $tasa_cambio; ?>;
    const fecha = new Date(venta.fecha);
    
    const fechaStr = fecha.toLocaleDateString('es-VE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
    
    const iva = settings.iva || 16;
    const subtotal = total / (1 + (iva / 100));
    const montoIva = total - subtotal;
    
    let productosHtml = '';
    venta.productos.forEach((producto, index) => {
        const precio = esBolivares ? producto.precio_bs : producto.precio_usd;
        const subtotalItem = esBolivares ? producto.subtotal_bs : producto.subtotal_usd;
        const simbolo = esBolivares ? 'Bs.' : '$';
        
        let nombreProducto = producto.nombre;
        if (nombreProducto.length > 22) {
            nombreProducto = nombreProducto.substring(0, 20) + '..';
        }
        
        // Formato con mejor separación
        productosHtml += `
            <tr>
                <td>${nombreProducto}</td>
                <td style="text-align: center;">${producto.cantidad}</td>
                <td class="precio">${simbolo} ${precio.toFixed(2)}</td>
                <td class="subtotal">${simbolo} ${subtotalItem.toFixed(2)}</td>
            </tr>
        `;
    });
    
    const simbolo = esBolivares ? 'Bs.' : '$';
    const simboloMoneda = esBolivares ? 'Bs' : 'USD';
    
    // Función para formatear números con separadores de miles
    function formatNumber(num) {
        return num.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }
    
    return `
        <div class="factura-80mm">
            <div class="titulo">
                ${settings.logo && settings.logo !== 'null' ? `<div class="logo"><img src="${settings.logo}" alt="Logo"></div>` : ''}
                <strong>${settings.nombre_comercio}</strong>
            </div>
            <div class="datos-comercio">
                RIF: ${settings.rif}<br>
                ${settings.direccion}<br>
                ${settings.telefono ? 'TEL: ' + settings.telefono : ''}
            </div>
            <div class="info-venta">
                <p><strong>FACTURA N°:</strong> ${venta.id}</p>
                <p><strong>FECHA:</strong> ${fechaStr}</p>
                <p><strong>VENDEDOR:</strong> ${<?php echo json_encode($_SESSION['username']); ?>}</p>
                <p><strong>MONEDA:</strong> ${simboloMoneda} ${!esBolivares ? `(Tasa: Bs. ${tasaCambio.toFixed(2)})` : ''}</p>
            </div>
            <hr class="separador">
            <table class="productos">
                <thead>
                    <tr>
                        <th>PRODUCTO</th>
                        <th style="text-align: center;">CANT</th>
                        <th style="text-align: right;">PRECIO</th>
                        <th style="text-align: right;">SUBTOTAL</th>
                    </tr>
                </thead>
                <tbody>
                    ${productosHtml}
                </tbody>
            </table>
            <hr class="separador-punteado">
            <div class="totales">
                <div class="totales-line">
                    <span>SUBTOTAL:</span>
                    <span>${simbolo} ${formatNumber(subtotal)}</span>
                </div>
                <div class="totales-line">
                    <span>IVA (${iva}%):</span>
                    <span>${simbolo} ${formatNumber(montoIva)}</span>
                </div>
                <hr class="separador-punteado">
                <div class="totales-line" style="font-size: 11pt; font-weight: bold;">
                    <span>TOTAL:</span>
                    <span>${simbolo} ${formatNumber(total)}</span>
                </div>
            </div>
            <div class="footer">
                <p>¡Gracias por su compra!</p>
                <p>Este documento es una factura de venta</p>
                <p>Válido como comprobante fiscal</p>
                <p>Sistema de Ventas Venezuela</p>
            </div>
            <div class="gracias">
                ¡VUELVA PRONTO!
            </div>
        </div>
    `;
}


    function cerrarModalFactura() {
        document.getElementById('modalFactura').style.display = 'none';
    }
    
    document.getElementById('moneda').addEventListener('change', actualizarCarrito);
    
    // Verificar si hay una venta registrada para mostrar la factura
    <?php if ($ultima_venta_id): ?>
    document.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            mostrarFactura();
        }, 500);
    });
    <?php endif; ?>
    
    const style = document.createElement('style');
    style.textContent = `
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
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideInDown {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .alert.info {
            background: #4299e1;
            color: white;
            border: 1px solid #3182ce;
        }
    `;
    document.head.appendChild(style);
    </script>
</body>
</html>