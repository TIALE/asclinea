<?php
require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

try {
    $pdo = DatabaseConnection::getConnection();
    
    $sqlFile = __DIR__ . '/../database/importar_fallas_nuevas.sql';
    if (!file_exists($sqlFile)) {
        die("El archivo SQL no existe: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Ejecutar el SQL
    $pdo->exec($sql);
    
    echo "<h1>¡Éxito!</h1>";
    echo "<p>Los reportes se han adjuntado correctamente a la base de datos (tbo_Falla).</p>";
    echo "<p>Las columnas Horas, Ciclos y Tiempo de atención total quedaron en blanco, el ATA fue formateado y se asignó un ID basado en la fecha.</p>";
    
    // Opcional: Eliminar el archivo después de importar para seguridad
    // unlink($sqlFile);
    // unlink(__FILE__);
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
