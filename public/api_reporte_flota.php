<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

header('Content-Type: application/json; charset=utf-8');

if (!SessionManager::has('user_id')) {
    echo json_encode(['success' => false, 'error' => 'No autorizado. Acceso denegado.']);
    exit;
}

$userRole = (string)SessionManager::get('user_role', '');

// Autodetectar rol si falta en sesión activa
if (empty($userRole)) {
    try {
        $pdo = DatabaseConnection::getConnection();
        $stmtRole = $pdo->prepare("SELECT rol FROM tbc_Usuario WHERE id_usuario = :id");
        $stmtRole->execute([':id' => SessionManager::get('user_id')]);
        $userRole = (string)$stmtRole->fetchColumn();
        if (empty($userRole)) {
            $userRole = 'Técnico';
        }
        SessionManager::set('user_role', $userRole);
    } catch (\Exception $e) {
        $userRole = 'Técnico';
    }
}

// Bloqueo estricto para cualquier rol no modificador (Supervisor, Técnico, Otro)
if (!in_array($userRole, ['Administrador', 'Ingeniero'])) {
    echo json_encode(['success' => false, 'error' => 'Acceso denegado: Su rol no cuenta con permisos para modificar el estatus de la flota.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Método no soportado.']);
    exit;
}

$idFlota              = filter_input(INPUT_POST, 'id_flota', FILTER_VALIDATE_INT);
$estatus              = trim((string)filter_input(INPUT_POST, 'estatus', FILTER_DEFAULT));
$taller               = trim((string)filter_input(INPUT_POST, 'taller', FILTER_DEFAULT));
$comentarios          = trim((string)filter_input(INPUT_POST, 'comentarios_relevantes', FILTER_DEFAULT));
$estatusMotores       = trim((string)filter_input(INPUT_POST, 'estatus_motores', FILTER_DEFAULT));
$fechaIngreso         = trim((string)filter_input(INPUT_POST, 'fecha_ingreso', FILTER_DEFAULT));
$fechaLiberacion      = trim((string)filter_input(INPUT_POST, 'fecha_liberacion', FILTER_DEFAULT));

if ($idFlota === null || $idFlota === false) {
    echo json_encode(['success' => false, 'error' => 'ID de aeronave no válido.']);
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();

    // Actualizar dinámicamente los campos en tbc_Flota de forma parametrizada y segura
    $sql = "UPDATE tbc_Flota SET 
                estatus = :estatus,
                taller = :taller,
                comentarios_relevantes = :comentarios,
                estatus_motores = :estatus_motores,
                fecha_ingreso = :fecha_ingreso,
                fecha_liberacion = :fecha_liberacion
            WHERE id_flota = :id";

    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        ':estatus'          => !empty($estatus) ? $estatus : 'OP',
        ':taller'           => $taller,
        ':comentarios'      => $comentarios,
        ':estatus_motores'  => $estatusMotores,
        ':fecha_ingreso'    => !empty($fechaIngreso) ? $fechaIngreso : null,
        ':fecha_liberacion' => !empty($fechaLiberacion) ? $fechaLiberacion : null,
        ':id'               => $idFlota
    ]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Estatus de aeronave actualizado correctamente.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'No se realizaron cambios.']);
    }

} catch (\Exception $e) {
    error_log("Error en api_reporte_flota: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error interno del servidor.']);
}
