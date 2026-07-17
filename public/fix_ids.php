<?php
require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Buscar los registros con ID mayor a 1000 (los generados con fecha)
    $stmt = $pdo->query("SELECT id_falla FROM tbo_Falla WHERE id_falla > 1000 ORDER BY fecha ASC, id_falla ASC");
    $badRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($badRecords)) {
        echo "<h1>Nada que arreglar</h1>";
        exit;
    }

    $nextId = 355; // Comenzar desde 355
    $updates = 0;
    
    $pdo->beginTransaction();
    // Deshabilitar temporalmente los chequeos de foreign keys para mayor seguridad
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0;");

    foreach ($badRecords as $record) {
        $badId = $record['id_falla'];
        
        $update = $pdo->prepare("UPDATE tbo_Falla SET id_falla = :new_id WHERE id_falla = :old_id");
        $update->execute([':new_id' => $nextId, ':old_id' => $badId]);
        
        $nextId++;
        $updates++;
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS=1;");
    $pdo->commit();
    
    echo "<h1>¡Éxito!</h1>";
    echo "<p>Se actualizaron $updates reportes. Los nuevos IDs ahora llegan hasta: " . ($nextId - 1) . "</p>";
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
