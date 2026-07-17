<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

if (!SessionManager::has('user_id')) {
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$matricula = trim((string)filter_input(INPUT_GET, 'matricula', FILTER_DEFAULT));
$folio     = trim((string)filter_input(INPUT_GET, 'folio', FILTER_DEFAULT));
$fecha     = trim((string)filter_input(INPUT_GET, 'fecha', FILTER_DEFAULT));

if (empty($matricula) || empty($folio) || empty($fecha)) {
    echo json_encode(['duplicate' => false]);
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbo_Falla WHERE matricula = :matricula AND folio = :folio AND fecha = :fecha");
    $stmt->execute([
        ':matricula' => $matricula,
        ':folio'     => $folio,
        ':fecha'     => $fecha
    ]);
    
    $count = (int)$stmt->fetchColumn();
    
    echo json_encode(['duplicate' => $count > 0]);
} catch (\Exception $e) {
    echo json_encode(['error' => 'Error en base de datos: ' . $e->getMessage()]);
}
