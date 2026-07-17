<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('log_errors', '0');
error_reporting(E_ALL);

use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

echo "<h2>Iniciando Autodiagnóstico de Errores PHP</h2>";

try {
    require_once __DIR__ . '/../src/autoload.php';
    echo "✓ Autoload cargado correctamente.<br>";
    
    EnvLoader::load(__DIR__ . '/../.env');
    echo "✓ Variables de entorno cargadas.<br>";
    
    $pdo = DatabaseConnection::getConnection();
    echo "✓ Conexión a base de datos establecida exitosamente.<br>";
    
    // Verificar si la tabla tbc_Flota existe y sus columnas
    $stmt = $pdo->query("DESCRIBE tbc_Flota");
    echo "<h3>Columnas de tbc_Flota:</h3>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$row['Field']} ({$row['Type']})<br>";
    }
    
    // Verificar si tbo_Falla existe
    $stmt2 = $pdo->query("DESCRIBE tbo_Falla");
    echo "<h3>Columnas de tbo_Falla:</h3>";
    while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        echo "- {$row['Field']} ({$row['Type']})<br>";
    }
    
} catch (\Throwable $e) {
    echo "<b style='color:red;'>Excepción capturada:</b> " . htmlspecialchars($e->getMessage()) . "<br>";
    echo "<b>Línea:</b> " . $e->getLine() . " en " . $e->getFile() . "<br>";
}
