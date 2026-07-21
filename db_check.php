<?php
require_once __DIR__ . '/src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/.env');

try {
    $pdo = DatabaseConnection::getConnection();
    
    $stmtFind = $pdo->query("SELECT id_falla FROM tbo_Falla ORDER BY id_falla DESC LIMIT 5");
    $recentIds = $stmtFind->fetchAll(PDO::FETCH_COLUMN);
    
    echo "Recent IDs: " . implode(", ", $recentIds) . "\n";
    
    $rogueId = $recentIds[0];
    
    if ($rogueId > 1000) {
        $stmtUpdate = $pdo->prepare("UPDATE tbo_Falla SET id_falla = 481 WHERE id_falla = :rogue_id");
        $stmtUpdate->execute([':rogue_id' => $rogueId]);
        echo "Updated rogue ID $rogueId to 481\n";
    } else {
        echo "No rogue ID found.\n";
    }
    
    $pdo->exec("ALTER TABLE tbo_Falla AUTO_INCREMENT = 482");
    echo "Auto increment reset to 482\n";
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
