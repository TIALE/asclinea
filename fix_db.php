<?php
require_once __DIR__ . '/src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/.env');

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Check current column type
    $stmt = $pdo->query("SHOW COLUMNS FROM tbo_Falla LIKE 'categoria_mel'");
    $col = $stmt->fetch();
    echo "Current column: ";
    print_r($col);
    
    // Alter table to increase size
    $pdo->exec("ALTER TABLE tbo_Falla MODIFY categoria_mel VARCHAR(255)");
    echo "Column categoria_mel altered to VARCHAR(255) successfully!\n";
    
    // Check new column type
    $stmt = $pdo->query("SHOW COLUMNS FROM tbo_Falla LIKE 'categoria_mel'");
    $col = $stmt->fetch();
    echo "New column: ";
    print_r($col);
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
