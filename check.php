<?php
require_once __DIR__ . '/public/index.php'; // or whatever loads PDO
use App\Infrastructure\Database\DatabaseConnection;
try {
    $pdo = DatabaseConnection::getConnection();
    $stmt = $pdo->query("SHOW COLUMNS FROM tbo_Falla LIKE 'categoria_mel'");
    print_r($stmt->fetch());
    
    $stmt2 = $pdo->query("SELECT id_falla, categoria_mel FROM tbo_Falla ORDER BY id_falla DESC LIMIT 5");
    print_r($stmt2->fetchAll());
} catch(Exception $e) {
    echo $e->getMessage();
}
