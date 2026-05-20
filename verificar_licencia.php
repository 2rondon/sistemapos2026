<?php
// verificar_licencia.php - Versión SIMPLIFICADA y CORREGIDA
require_once 'config/database.php';

function getDeviceId() {
    $data = $_SERVER['HTTP_USER_AGENT'] . $_SERVER['REMOTE_ADDR'] . $_SERVER['HTTP_HOST'];
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $data .= $_SERVER['HTTP_ACCEPT_LANGUAGE'];
    }
    return hash('sha256', $data);
}

function verificarEstadoLicencia() {
    $db = Database::getInstance()->getConnection();
    $dispositivo_id = getDeviceId();
    
    // Verificar si este dispositivo ya tiene una licencia activada
    $stmt = $db->prepare("SELECT * FROM licencias_activadas WHERE dispositivo_id = ?");
    $stmt->execute([$dispositivo_id]);
    $activada = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($activada) {
        return ['valid' => true, 'message' => 'Licencia activa'];
    }
    
    return ['valid' => false, 'message' => 'Licencia no activada'];
}

function activarLicencia($codigo_ingresado) {
    $db = Database::getInstance()->getConnection();
    $dispositivo_id = getDeviceId();
    
    // Código válido (hardcoded como respaldo)
    $CODIGO_VALIDO = "A7F3E9C2B8D1F4E6A9C3B7D8E2F1A4C5";
    
    // Limpiar el código
    $codigo_ingresado = trim(strtoupper($codigo_ingresado));
    
    // Verificar si el código es válido (comparación directa)
    if ($codigo_ingresado === $CODIGO_VALIDO) {
        // Verificar si este dispositivo ya tiene licencia
        $stmt = $db->prepare("SELECT * FROM licencias_activadas WHERE dispositivo_id = ?");
        $stmt->execute([$dispositivo_id]);
        
        if (!$stmt->fetch()) {
            // Activar licencia
            $stmt = $db->prepare("INSERT INTO licencias_activadas (dispositivo_id, codigo_licencia, ip_address, user_agent) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $dispositivo_id, 
                $CODIGO_VALIDO,
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
        }
        
        return ['success' => true, 'message' => 'Licencia activada correctamente'];
    }
    
    // También verificar en la base de datos por si hay más licencias
    $stmt = $db->prepare("SELECT * FROM licencias WHERE codigo_licencia = ? AND estado = 'activa' AND (fecha_expiracion IS NULL OR fecha_expiracion > datetime('now'))");
    $stmt->execute([$codigo_ingresado]);
    $licencia = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($licencia) {
        $stmt = $db->prepare("INSERT INTO licencias_activadas (dispositivo_id, codigo_licencia, ip_address, user_agent) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $dispositivo_id, 
            $codigo_ingresado,
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_USER_AGENT']
        ]);
        return ['success' => true, 'message' => 'Licencia activada correctamente'];
    }
    
    return ['success' => false, 'message' => 'Código de licencia inválido o expirado'];
}
?>