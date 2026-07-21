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

header('Content-Type: application/json; charset=UTF-8');

// Control de acceso: Ingeniero, Supervisor o Administrador
if (!SessionManager::has('user_id')) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No autenticado.']);
    exit;
}

$userRole = (string)SessionManager::get('user_role', '');
if (!in_array($userRole, ['Ingeniero', 'Supervisor', 'Administrador'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso restringido a los roles Ingeniero, Supervisor y Administrador.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

// Leer JSON del body
$rawBody = file_get_contents('php://input');
if (empty($rawBody)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Cuerpo de petición vacío.']);
    exit;
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.']);
    exit;
}

// Validar ID de falla
$idFalla = isset($data['id_falla']) ? (int)$data['id_falla'] : 0;
if ($idFalla <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de falla inválido.']);
    exit;
}

// Sanitizar y capturar campos editables
$modelo            = trim((string)($data['modelo'] ?? ''));
$matricula         = trim((string)($data['matricula'] ?? ''));
$ata               = trim((string)($data['ata'] ?? ''));
$condicion         = trim((string)($data['condicion'] ?? ''));
$folio             = trim((string)($data['folio'] ?? ''));
$fecha             = trim((string)($data['fecha'] ?? ''));
$base              = trim((string)($data['base'] ?? ''));
$descripcion       = trim((string)($data['descripcion'] ?? ''));
$accion_correctiva = trim((string)($data['accion_correctiva'] ?? ''));
$referencia        = trim((string)($data['referencia'] ?? ''));
$tips              = trim((string)($data['tips'] ?? ''));
$mel               = trim((string)($data['mel'] ?? ''));
$categoria_mel     = trim((string)($data['categoria_mel'] ?? ''));
$restricciones_mel = trim((string)($data['restricciones_mel'] ?? ''));

// Consolidar Categoría y Restricciones de manera elegante
$categoria_consolidada = '';
if (!empty($categoria_mel) && !empty($restricciones_mel)) {
    $categoria_consolidada = $categoria_mel . ' / ' . $restricciones_mel;
} elseif (!empty($categoria_mel)) {
    $categoria_consolidada = $categoria_mel;
} elseif (!empty($restricciones_mel)) {
    $categoria_consolidada = $restricciones_mel;
}

// Campos numéricos
$horas           = isset($data['horas']) && $data['horas'] !== '' ? (float)$data['horas'] : null;
$ciclos          = isset($data['ciclos']) && $data['ciclos'] !== '' ? (int)$data['ciclos'] : null;
$tiempo_atencion = isset($data['tiempo_atencion']) && $data['tiempo_atencion'] !== '' ? (float)$data['tiempo_atencion'] : null;

// Componente
$componente_cambiado = (isset($data['componente_cambiado']) && $data['componente_cambiado'] === 'Sí') ? 'Sí' : 'No';

$comp_removido_np = $data['comp_removido_np'] ?? [];
$comp_removido_ns = $data['comp_removido_ns'] ?? [];
$comp_instalado_np = $data['comp_instalado_np'] ?? [];
$comp_instalado_ns = $data['comp_instalado_ns'] ?? [];

$c1_rem_np = 'N/A'; $c1_rem_ns = 'N/A';
$c1_inst_np = 'N/A'; $c1_inst_ns = 'N/A';
$c2_rem_np = null; $c2_rem_ns = null;
$c2_inst_np = null; $c2_inst_ns = null;
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

// Validación de campos mandatorios
if (empty($modelo) || empty($matricula) || empty($ata) || empty($descripcion) || empty($accion_correctiva)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan campos obligatorios: Modelo, Matrícula, ATA, Descripción y Acción Correctiva son requeridos.']);
    exit;
}

// Validar formato de fecha si se proporciona
if (!empty($fecha) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Formato de fecha inválido. Use YYYY-MM-DD.']);
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();

    // Verificar que el registro existe antes de actualizar
    $stmtCheck = $pdo->prepare("SELECT id_falla FROM tbo_Falla WHERE id_falla = :id");
    $stmtCheck->execute([':id' => $idFalla]);
    if (!$stmtCheck->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Reporte no encontrado.']);
        exit;
    }

    // UPDATE parametrizado seguro
    $sql = "UPDATE tbo_Falla SET
                modelo            = :modelo,
                matricula         = :matricula,
                ata               = :ata,
                condicion         = :condicion,
                folio             = :folio,
                fecha             = :fecha,
                mel               = :mel,
                categoria_mel     = :categoria_mel,
                base              = :base,
                descripcion       = :descripcion,
                accion_correctiva = :accion_correctiva,
                referencia        = :referencia,
                tips              = :tips,
                horas             = :horas,
                ciclos            = :ciclos,
                tiempo_atencion   = :tiempo_atencion,
                componente_cambiado = :componente_cambiado,
                comp_removido_np  = :comp_removido_np,
                comp_removido_ns  = :comp_removido_ns,
                comp_instalado_np = :comp_instalado_np,
                comp_instalado_ns = :comp_instalado_ns,
                comp2_removido_np = :comp2_removido_np,
                comp2_removido_ns = :comp2_removido_ns,
                comp2_instalado_np = :comp2_instalado_np,
                comp2_instalado_ns = :comp2_instalado_ns,
                componentes_adicionales = :componentes_adicionales
            WHERE id_falla = :id_falla";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':modelo'              => $modelo,
        ':matricula'           => $matricula,
        ':ata'                 => $ata,
        ':condicion'           => !empty($condicion) ? $condicion : null,
        ':folio'               => !empty($folio) ? $folio : null,
        ':fecha'               => !empty($fecha) ? $fecha : date('Y-m-d'),
        ':mel'                 => !empty($mel) ? $mel : null,
        ':categoria_mel'       => !empty($categoria_consolidada) ? $categoria_consolidada : null,
        ':base'                => $base,
        ':descripcion'         => $descripcion,
        ':accion_correctiva'   => $accion_correctiva,
        ':referencia'          => !empty($referencia) ? $referencia : null,
        ':tips'                => !empty($tips) ? $tips : null,
        ':horas'               => $horas,
        ':ciclos'              => $ciclos,
        ':tiempo_atencion'     => $tiempo_atencion,
        ':componente_cambiado' => $componente_cambiado,
        ':comp_removido_np'    => $c1_rem_np,
        ':comp_removido_ns'    => $c1_rem_ns,
        ':comp_instalado_np'   => $c1_inst_np,
        ':comp_instalado_ns'   => $c1_inst_ns,
        ':comp2_removido_np'   => !empty($c2_rem_np) ? $c2_rem_np : null,
        ':comp2_removido_ns'   => !empty($c2_rem_ns) ? $c2_rem_ns : null,
        ':comp2_instalado_np'  => !empty($c2_inst_np) ? $c2_inst_np : null,
        ':comp2_instalado_ns'  => !empty($c2_inst_ns) ? $c2_inst_ns : null,
        ':componentes_adicionales' => $json_adicionales,
        ':id_falla'            => $idFalla,
    ]);

    $editadoPor = (string)SessionManager::get('user_name', 'Usuario Autorizado');
    error_log("Reporte #{$idFalla} actualizado por {$editadoPor}");

    echo json_encode([
        'success' => true,
        'message' => "Reporte #{$idFalla} actualizado correctamente.",
        'id_falla' => $idFalla
    ]);

} catch (\Exception $e) {
    error_log("Error actualizando falla #{$idFalla}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno al actualizar el reporte.']);
    exit;
}
