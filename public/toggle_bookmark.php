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
$idFalla  = (int)filter_input(INPUT_GET, 'id_falla', FILTER_VALIDATE_INT);

if (empty($userName) || $idFalla <= 0) {
    echo json_encode(['success' => false, 'message' => 'Parámetros inválidos']);
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Verificar si el marcador ya existe
    $stmtCheck = $pdo->prepare("SELECT id_carpeta FROM tbo_CarpetaUsuario WHERE usuario_nombre = :user AND id_falla = :falla");
    $stmtCheck->execute([
        ':user'  => $userName,
        ':falla' => $idFalla
    ]);
    $carpetaId = $stmtCheck->fetchColumn();

    if ($carpetaId !== false) {
        // Ya existe, procedemos a desmarcarlo (eliminar)
        $stmtDelete = $pdo->prepare("DELETE FROM tbo_CarpetaUsuario WHERE id_carpeta = :id");
        $stmtDelete->execute([':id' => (int)$carpetaId]);
        echo json_encode(['success' => true, 'status' => 'unbookmarked']);
    } else {
        // No existe, procedemos a agregarlo
        $stmtInsert = $pdo->prepare("INSERT INTO tbo_CarpetaUsuario (usuario_nombre, id_falla) VALUES (:user, :falla)");
        $stmtInsert->execute([
            ':user'  => $userName,
            ':falla' => $idFalla
        ]);
        echo json_encode(['success' => true, 'status' => 'bookmarked']);
    }
} catch (\Exception $e) {
    error_log("Error in toggle_bookmark: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error de servidor: ' . $e->getMessage()]);
}
