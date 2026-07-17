<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

if (!SessionManager::has('user_id')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$userName = (string)SessionManager::get('user_name', '');
if (empty($userName)) {
    echo json_encode(['success' => false, 'message' => 'Usuario no identificado']);
    exit;
}

// Obtener cuerpo de la petición POST (JSON)
$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!isset($data['ids']) || !is_array($data['ids'])) {
    echo json_encode(['success' => false, 'message' => 'Parámetros inválidos o vacíos']);
    exit;
}

$idFallas = array_filter(array_map('intval', $data['ids']), function ($id) {
    return $id > 0;
});

if (empty($idFallas)) {
    echo json_encode(['success' => false, 'message' => 'No se seleccionaron reportes válidos']);
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();
    $pdo->beginTransaction();

    $savedCount = 0;
    
    // Preparar sentencias para verificar e insertar
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM tbo_CarpetaUsuario WHERE usuario_nombre = :user AND id_falla = :falla");
    $stmtInsert = $pdo->prepare("INSERT INTO tbo_CarpetaUsuario (usuario_nombre, id_falla) VALUES (:user, :falla)");

    foreach ($idFallas as $idFalla) {
        $stmtCheck->execute([
            ':user'  => $userName,
            ':falla' => $idFalla
        ]);
        $exists = (int)$stmtCheck->fetchColumn() > 0;

        if (!$exists) {
            $stmtInsert->execute([
                ':user'  => $userName,
                ':falla' => $idFalla
            ]);
            $savedCount++;
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'saved_count' => $savedCount, 
        'message' => "Se guardaron {$savedCount} reportes en tu carpeta con éxito"
    ]);
} catch (\Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Error in guardar_favoritos_lote: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de servidor: ' . $e->getMessage()]);
}
