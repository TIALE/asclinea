<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;

EnvLoader::load(__DIR__ . '/../.env');

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$pass = getenv('DB_PASS') ?: '';

$combinations = [
    ['user' => '359185291_asclinea', 'db' => '359185291_asclinea'],
    ['user' => 'u359185291_asclinea', 'db' => 'u359185291_asclinea'],
    ['user' => 'u359185291_asclinea', 'db' => '359185291_asclinea'],
    ['user' => '359185291_asclinea', 'db' => 'u359185291_asclinea'],
];

foreach ($combinations as $idx => $comb) {
    $user = $comb['user'];
    $db = $comb['db'];
    $num = $idx + 1;
    echo "<h3>Combinación {$num}: User='{$user}', DB='{$db}'</h3>";
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        echo "<b style='color:green;'>¡CONEXIÓN EXITOSA!</b><br>";
        
        // Si tiene éxito, guardar estos parámetros en un archivo o mostrarlos claramente
        echo "Usa: DB_USER={$user} y DB_NAME={$db}<br>";
    } catch (\PDOException $e) {
        echo "<b style='color:red;'>Fallo:</b> " . htmlspecialchars($e->getMessage()) . "<br>";
    }
}
