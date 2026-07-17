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

// Control de acceso OWASP
if (!SessionManager::has('user_id')) {
    header('HTTP/1.1 403 Forbidden');
    echo "No autorizado";
    exit;
}

$q         = trim((string)filter_input(INPUT_GET, 'q', FILTER_DEFAULT));
$filterMat = trim((string)filter_input(INPUT_GET, 'matricula', FILTER_DEFAULT));
$filterAta = trim((string)filter_input(INPUT_GET, 'ata', FILTER_DEFAULT));

try {
    $pdo = DatabaseConnection::getConnection();

    // Re-crear consulta filtrada
    $sql = "SELECT id_falla, modelo, matricula, ata, condicion, folio, fecha, mel, categoria_mel, descripcion, accion_correctiva, referencia, tips, base, registrado_por FROM tbo_Falla WHERE 1=1";
    $params = [];

    if (!empty($q)) {
        $sql .= " AND (descripcion LIKE :q1 OR accion_correctiva LIKE :q2 OR folio LIKE :q3 OR referencia LIKE :q4 OR tips LIKE :q5)";
        $likeQ = "%{$q}%";
        $params[':q1'] = $likeQ;
        $params[':q2'] = $likeQ;
        $params[':q3'] = $likeQ;
        $params[':q4'] = $likeQ;
        $params[':q5'] = $likeQ;
    }

    if (!empty($filterMat)) {
        $sql .= " AND matricula = :matricula";
        $params[':matricula'] = $filterMat;
    }

    if (!empty($filterAta)) {
        $sql .= " AND ata LIKE :ata";
        $params[':ata'] = "{$filterAta}%";
    }

    $sql .= " ORDER BY id_falla DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fallas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Configurar cabeceras de descarga de CSV para el navegador
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Reporte_Fallas_AleSearchTool_' . date('Ymd_His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        throw new Exception("No se pudo iniciar la exportación");
    }

    // Inyectar el BOM de UTF-8 de Microsoft Excel para evitar caracteres rotos (acentos, símbolos técnicos)
    fwrite($output, "\xEF\xBB\xBF");

    // Escribir fila de encabezados
    fputcsv($output, [
        'ID Falla',
        'Modelo',
        'Matrícula',
        'ATA',
        'Condición (Gravedad)',
        'Folio Bitácora',
        'Fecha',
        'Capítulo MEL',
        'Categoría MEL',
        'Descripción de la Falla',
        'Acción Correctiva',
        'Referencia AMM',
        'Tips / Troubleshooting',
        'Base',
        'Registrado Por'
    ]);

    // Escribir datos
    foreach ($fallas as $fa) {
        fputcsv($output, [
            $fa['id_falla'],
            $fa['modelo'],
            $fa['matricula'],
            $fa['ata'],
            $fa['condicion'],
            $fa['folio'],
            $fa['fecha'],
            $fa['mel'],
            $fa['categoria_mel'],
            $fa['descripcion'],
            $fa['accion_correctiva'],
            $fa['referencia'],
            $fa['tips'],
            $fa['base'],
            $fa['registrado_por']
        ]);
    }

    fclose($output);
    exit;

} catch (\Exception $e) {
    error_log("Error exportando CSV: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Error interno de exportación: " . $e->getMessage();
}
