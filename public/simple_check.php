<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('log_errors', '0');
error_reporting(E_ALL);

echo "<h2>Verificación Directa de PDO</h2>";

require_once __DIR__ . '/../src/autoload.php';
\App\Shared\Env\EnvLoader::load(__DIR__ . '/../.env');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';

echo "Intentando conectar a: $host:$port, DB: $db, User: $user<br>";

try {
    $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5
    ]);
    echo "<b style='color:green;'>✓ Conexión exitosa!</b><br>";

    // Ver las tablas
    $stmt = $pdo->query("SHOW TABLES");
    echo "<h3>Tablas en la base de datos:</h3>";
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        echo "- {$row[0]}<br>";
    }
    
} catch (\Exception $e) {
    echo "<b style='color:red;'>Error de conexión:</b> " . htmlspecialchars($e->getMessage()) . "<br>";
}
