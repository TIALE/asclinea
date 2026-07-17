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

// Control de Acceso OWASP
if (!SessionManager::has('user_id')) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registrar_falla.php');
    exit;
}

// 1. Sanitizar y capturar entradas del usuario
$modelo            = trim((string)filter_input(INPUT_POST, 'modelo', FILTER_DEFAULT));
$matricula         = trim((string)filter_input(INPUT_POST, 'matricula', FILTER_DEFAULT));
$ata               = trim((string)filter_input(INPUT_POST, 'ata', FILTER_DEFAULT));
$condicion         = trim((string)filter_input(INPUT_POST, 'condicion', FILTER_DEFAULT));
$folio             = trim((string)filter_input(INPUT_POST, 'folio', FILTER_DEFAULT));
$fecha             = trim((string)filter_input(INPUT_POST, 'fecha', FILTER_DEFAULT));
$base              = trim((string)filter_input(INPUT_POST, 'base', FILTER_DEFAULT));
$referencia        = trim((string)filter_input(INPUT_POST, 'referencia', FILTER_DEFAULT));
$descripcion       = trim((string)filter_input(INPUT_POST, 'descripcion', FILTER_DEFAULT));
$accion_correctiva = trim((string)filter_input(INPUT_POST, 'accion_correctiva', FILTER_DEFAULT));
$tips              = trim((string)filter_input(INPUT_POST, 'tips', FILTER_DEFAULT));

// Campos MTBF/MTTR
$horas = filter_input(INPUT_POST, 'horas', FILTER_VALIDATE_FLOAT);
$horas = ($horas !== false && $horas !== null) ? $horas : null;

$ciclos = filter_input(INPUT_POST, 'ciclos', FILTER_VALIDATE_INT);
$ciclos = ($ciclos !== false && $ciclos !== null) ? $ciclos : null;

$tiempo_atencion = filter_input(INPUT_POST, 'tiempo_atencion', FILTER_VALIDATE_FLOAT);
$tiempo_atencion = ($tiempo_atencion !== false && $tiempo_atencion !== null) ? $tiempo_atencion : null;

// Lógica de Componentes
$componente_cambiado = trim((string)filter_input(INPUT_POST, 'componente_cambiado', FILTER_DEFAULT)) === 'Sí' ? 'Sí' : 'No';

$comp_removido_np = trim((string)filter_input(INPUT_POST, 'comp_removido_np', FILTER_DEFAULT));
$comp_removido_ns = trim((string)filter_input(INPUT_POST, 'comp_removido_ns', FILTER_DEFAULT));
$comp_instalado_np = trim((string)filter_input(INPUT_POST, 'comp_instalado_np', FILTER_DEFAULT));
$comp_instalado_ns = trim((string)filter_input(INPUT_POST, 'comp_instalado_ns', FILTER_DEFAULT));

$comp2_removido_np = trim((string)filter_input(INPUT_POST, 'comp2_removido_np', FILTER_DEFAULT));
$comp2_removido_ns = trim((string)filter_input(INPUT_POST, 'comp2_removido_ns', FILTER_DEFAULT));
$comp2_instalado_np = trim((string)filter_input(INPUT_POST, 'comp2_instalado_np', FILTER_DEFAULT));
$comp2_instalado_ns = trim((string)filter_input(INPUT_POST, 'comp2_instalado_ns', FILTER_DEFAULT));

if ($componente_cambiado === 'No') {
    $comp_removido_np = 'N/A';
    $comp_removido_ns = 'N/A';
    $comp_instalado_np = 'N/A';
    $comp_instalado_ns = 'N/A';
    
    $comp2_removido_np = '';
    $comp2_removido_ns = '';
    $comp2_instalado_np = '';
    $comp2_instalado_ns = '';
}

// Inyectar nombre del usuario en sesión activa
$registrado_por   = (string)SessionManager::get('user_name', 'Mecánico de Flota');

// 2. Validación de campos mandatorios
if (empty($modelo) || empty($matricula) || empty($ata) || empty($descripcion) || empty($accion_correctiva)) {
    SessionManager::set('falla_error_msg', 'Por favor, rellene todos los campos obligatorios (*).');
    header('Location: registrar_falla.php');
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Consulta Parametrizada de Alta Seguridad
    $sql = "INSERT INTO tbo_Falla (
                modelo, matricula, ata, condicion, folio, fecha, mel, 
                categoria_mel, descripcion, accion_correctiva, referencia, tips, base, registrado_por,
                horas, ciclos, tiempo_atencion, componente_cambiado, 
                comp_removido_np, comp_removido_ns, comp_instalado_np, comp_instalado_ns,
                comp2_removido_np, comp2_removido_ns, comp2_instalado_np, comp2_instalado_ns
            ) VALUES (
                :modelo, :matricula, :ata, :condicion, :folio, :fecha, null, 
                null, :descripcion, :accion_correctiva, :referencia, :tips, :base, :registrado_por,
                :horas, :ciclos, :tiempo_atencion, :componente_cambiado,
                :comp_removido_np, :comp_removido_ns, :comp_instalado_np, :comp_instalado_ns,
                :comp2_removido_np, :comp2_removido_ns, :comp2_instalado_np, :comp2_instalado_ns
            )";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':modelo'             => $modelo,
        ':matricula'          => $matricula,
        ':ata'                => $ata,
        ':condicion'          => !empty($condicion) ? $condicion : null,
        ':folio'              => !empty($folio) ? $folio : null,
        ':fecha'              => !empty($fecha) ? $fecha : date('Y-m-d'),
        ':descripcion'        => $descripcion,
        ':accion_correctiva'  => $accion_correctiva,
        ':referencia'         => !empty($referencia) ? $referencia : null,
        ':tips'               => !empty($tips) ? $tips : null,
        ':base'               => $base,
        ':registrado_por'     => $registrado_por,
        ':horas'              => $horas,
        ':ciclos'             => $ciclos,
        ':tiempo_atencion'    => $tiempo_atencion,
        ':componente_cambiado' => $componente_cambiado,
        ':comp_removido_np'   => $comp_removido_np,
        ':comp_removido_ns'   => $comp_removido_ns,
        ':comp_instalado_np'  => $comp_instalado_np,
        ':comp_instalado_ns'  => $comp_instalado_ns,
        ':comp2_removido_np'  => !empty($comp2_removido_np) ? $comp2_removido_np : null,
        ':comp2_removido_ns'  => !empty($comp2_removido_ns) ? $comp2_removido_ns : null,
        ':comp2_instalado_np' => !empty($comp2_instalado_np) ? $comp2_instalado_np : null,
        ':comp2_instalado_ns' => !empty($comp2_instalado_ns) ? $comp2_instalado_ns : null
    ]);
    
    SessionManager::set('falla_success_msg', "La falla técnica para la aeronave {$matricula} ha sido guardada y publicada exitosamente.");
    header('Location: registrar_falla.php');
    exit;
} catch (\Exception $e) {
    error_log("Error guardando falla: " . $e->getMessage());
    SessionManager::set('falla_error_msg', "Ocurrió un error al persistir el registro en el servidor. Detalles: " . $e->getMessage());
    header('Location: registrar_falla.php');
    exit;
}
