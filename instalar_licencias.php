<?php
// instalar_licencias.php - Ejecutar una sola vez para instalar la licencia
require_once 'config/database.php';

$db = Database::getInstance()->getConnection();

// Crear tablas
$db->exec("
    CREATE TABLE IF NOT EXISTS licencias (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        codigo_licencia TEXT UNIQUE NOT NULL,
        dispositivo_id TEXT,
        fecha_activacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        fecha_expiracion DATETIME,
        estado TEXT DEFAULT 'activa',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS licencias_activadas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        dispositivo_id TEXT UNIQUE NOT NULL,
        codigo_licencia TEXT NOT NULL,
        ip_address TEXT,
        user_agent TEXT,
        fecha_activacion DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (codigo_licencia) REFERENCES licencias(codigo_licencia)
    )
");

// Insertar licencia si no existe
$stmt = $db->prepare("SELECT COUNT(*) FROM licencias WHERE codigo_licencia = ?");
$stmt->execute(['A7F3E9C2B8D1F4E6A9C3B7D8E2F1A4C5']);
$existe = $stmt->fetchColumn();

if (!$existe) {
    $stmt = $db->prepare("INSERT INTO licencias (codigo_licencia, fecha_expiracion, estado) VALUES (?, ?, ?)");
    $stmt->execute(['A7F3E9C2B8D1F4E6A9C3B7D8E2F1A4C5', '2026-12-31 23:59:59', 'activa']);
    echo "✅ Licencia instalada correctamente<br>";
} else {
    echo "ℹ️ La licencia ya existe en la base de datos<br>";
}

// Mostrar todas las licencias
$stmt = $db->query("SELECT * FROM licencias");
$licencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Licencias en la base de datos:</h3>";
echo "<pre>";
print_r($licencias);
echo "</pre>";

echo "<br><a href='login.php'>Ir al login</a>";
?>