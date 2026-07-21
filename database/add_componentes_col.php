<?php
require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Check if column exists
    $checkCol = $pdo->query("SHOW COLUMNS FROM tbo_Falla LIKE 'componentes_adicionales'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE tbo_Falla ADD COLUMN componentes_adicionales TEXT NULL");
        echo "Column 'componentes_adicionales' added successfully.\n";
    } else {
        echo "Column already exists.\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
