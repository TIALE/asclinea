<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

if (!isset($input['registro_id'], $input['correo_usuario'], $input['nombre_usuario'], $input['tiempo_inicio'], $input['tiempo_fin'], $input['calificacion_estrellas'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Bad Request: Missing required fields.']);
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();

    // Crear la tabla si no existe
    $pdo->exec("CREATE TABLE IF NOT EXISTS ia_metrics_log (
        registro_id VARCHAR(36) PRIMARY KEY,
        correo_usuario VARCHAR(150),
        nombre_usuario VARCHAR(100),
        tiempo_inicio DATETIME,
        tiempo_fin DATETIME,
        calificacion_estrellas INT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $stmt = $pdo->prepare("INSERT INTO ia_metrics_log (registro_id, correo_usuario, nombre_usuario, tiempo_inicio, tiempo_fin, calificacion_estrellas) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $input['registro_id'],
        $input['correo_usuario'],
        $input['nombre_usuario'],
        $input['tiempo_inicio'],
        $input['tiempo_fin'],
        (int)$input['calificacion_estrellas']
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Metrics saved successfully.']);
} catch (\Exception $e) {
    error_log("Webhook AI Metrics Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error']);
}
