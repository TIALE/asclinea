<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('log_errors', '0');
error_reporting(E_ALL);

try {
    echo "<h2>Probando session_start con Ruta de Guardado Personalizada</h2>";
    
    // Crear un directorio para las sesiones en el espacio de trabajo
    $sessionDir = __DIR__ . '/../database/sessions';
    if (!is_dir($sessionDir)) {
        mkdir($sessionDir, 0777, true);
    }
    
    ini_set('session.save_path', $sessionDir);
    echo "✓ session.save_path configurado en: " . htmlspecialchars($sessionDir) . "<br>";
    
    $res = session_start();
    echo "✓ session_start() ejecutado. Resultado: " . ($res ? "TRUE" : "FALSE") . "<br>";
    echo "✓ ID de sesión: " . session_id() . "<br>";
    
} catch (\Throwable $e) {
    echo "<b style='color:red;'>Error native session:</b> " . htmlspecialchars($e->getMessage()) . "<br>";
}
