<?php
require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Add new columns for component 2 if they don't exist
    $columns = [
        'comp2_removido_np',
        'comp2_removido_ns',
        'comp2_instalado_np',
        'comp2_instalado_ns'
    ];
    
    foreach ($columns as $col) {
        try {
            $pdo->exec("ALTER TABLE tbo_Falla ADD COLUMN $col VARCHAR(100) DEFAULT NULL");
            echo "Added $col\n";
        } catch (Exception $e) {
            echo "Column $col already exists or error: " . $e->getMessage() . "\n";
        }
    }
    
    echo "DB Update Complete.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
