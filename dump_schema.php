<?php
require_once __DIR__ . '/src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/.env');

try {
    $pdo = DatabaseConnection::getConnection();
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach($tables as $table) {
        echo "Table: $table\n";
        $stmt = $pdo->query("SHOW COLUMNS FROM $table");
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach($cols as $col) {
            echo " - {$col['Field']} ({$col['Type']})\n";
        }
        echo "\n";
    }
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
