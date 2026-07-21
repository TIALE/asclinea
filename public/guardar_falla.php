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

// Lógica de Componentes Ilimitados (Arrays)
$componente_cambiado = trim((string)filter_input(INPUT_POST, 'componente_cambiado', FILTER_DEFAULT)) === 'Sí' ? 'Sí' : 'No';

$comp_removido_np = $_POST['comp_removido_np'] ?? [];
$comp_removido_ns = $_POST['comp_removido_ns'] ?? [];
$comp_instalado_np = $_POST['comp_instalado_np'] ?? [];
$comp_instalado_ns = $_POST['comp_instalado_ns'] ?? [];

// Extraer el componente 1 (Principal)
$c1_rem_np = 'N/A'; $c1_rem_ns = 'N/A';
$c1_inst_np = 'N/A'; $c1_inst_ns = 'N/A';

// Extraer el componente 2 (Opcional - Compatibilidad)
$c2_rem_np = null; $c2_rem_ns = null;
$c2_inst_np = null; $c2_inst_ns = null;

// Extraer componentes adicionales (3 en adelante)
$componentes_adicionales = [];

if ($componente_cambiado === 'Sí' && is_array($comp_removido_np)) {
    foreach ($comp_removido_np as $index => $np_rem) {
        $rem_np = trim((string)$np_rem);
        $rem_ns = trim((string)($comp_removido_ns[$index] ?? 'N/A'));
        $inst_np = trim((string)($comp_instalado_np[$index] ?? 'N/A'));
        $inst_ns = trim((string)($comp_instalado_ns[$index] ?? 'N/A'));
        
        if ($rem_np === '') $rem_np = 'N/A';

        if ($index === 0) {
            $c1_rem_np = $rem_np;
            $c1_rem_ns = $rem_ns;
            $c1_inst_np = $inst_np;
            $c1_inst_ns = $inst_ns;
        } elseif ($index === 1) {
            $c2_rem_np = $rem_np;
            $c2_rem_ns = $rem_ns;
            $c2_inst_np = $inst_np;
            $c2_inst_ns = $inst_ns;
        } else {
            $componentes_adicionales[] = [
                'removido_np' => $rem_np,
                'removido_ns' => $rem_ns,
                'instalado_np' => $inst_np,
                'instalado_ns' => $inst_ns
            ];
        }
    }
}

$json_adicionales = !empty($componentes_adicionales) ? json_encode($componentes_adicionales) : null;

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
    
    // Auto-crear la columna componentes_adicionales si no existe
    $checkCol = $pdo->query("SHOW COLUMNS FROM tbo_Falla LIKE 'componentes_adicionales'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE tbo_Falla ADD COLUMN componentes_adicionales TEXT NULL");
    }
    
    // Consulta Parametrizada de Alta Seguridad
    $sql = "INSERT INTO tbo_Falla (
                modelo, matricula, ata, condicion, folio, fecha, mel, 
                categoria_mel, descripcion, accion_correctiva, referencia, tips, base, registrado_por,
                horas, ciclos, tiempo_atencion, componente_cambiado, 
                comp_removido_np, comp_removido_ns, comp_instalado_np, comp_instalado_ns,
                comp2_removido_np, comp2_removido_ns, comp2_instalado_np, comp2_instalado_ns,
                componentes_adicionales
            ) VALUES (
                :modelo, :matricula, :ata, :condicion, :folio, :fecha, null, 
                null, :descripcion, :accion_correctiva, :referencia, :tips, :base, :registrado_por,
                :horas, :ciclos, :tiempo_atencion, :componente_cambiado,
                :comp_removido_np, :comp_removido_ns, :comp_instalado_np, :comp_instalado_ns,
                :comp2_removido_np, :comp2_removido_ns, :comp2_instalado_np, :comp2_instalado_ns,
                :componentes_adicionales
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
        ':comp_removido_np'   => $c1_rem_np,
        ':comp_removido_ns'   => $c1_rem_ns,
        ':comp_instalado_np'  => $c1_inst_np,
        ':comp_instalado_ns'  => $c1_inst_ns,
        ':comp2_removido_np'  => !empty($c2_rem_np) ? $c2_rem_np : null,
        ':comp2_removido_ns'  => !empty($c2_rem_ns) ? $c2_rem_ns : null,
        ':comp2_instalado_np' => !empty($c2_inst_np) ? $c2_inst_np : null,
        ':comp2_instalado_ns' => !empty($c2_inst_ns) ? $c2_inst_ns : null,
        ':componentes_adicionales' => $json_adicionales
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
