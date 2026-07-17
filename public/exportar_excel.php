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

// Control de acceso: Ingeniero, Supervisor o Administrador
if (!SessionManager::has('user_id')) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'No autenticado']);
    exit;
}

$userRole = (string)SessionManager::get('user_role', '');
if (!in_array($userRole, ['Ingeniero', 'Supervisor', 'Administrador'], true)) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Acceso restringido a los roles Ingeniero, Supervisor y Administrador.']);
    exit;
}

// Capturar IDs de fallas seleccionadas por el checkbox
$idsStr = trim((string)filter_input(INPUT_GET, 'ids', FILTER_DEFAULT));
if (empty($idsStr)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Error: No se proporcionaron registros seleccionados.";
    exit;
}

// Sanitizar a un array de enteros
$ids = array_filter(array_map('intval', explode(',', $idsStr)), function($id) {
    return $id > 0;
});

if (empty($ids)) {
    header('HTTP/1.1 400 Bad Request');
    echo "Error: IDs de registros inválidos.";
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();

    // Generar marcadores dinámicos de parámetros para la consulta IN segura
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id_falla, modelo, matricula, ata, condicion, folio, fecha, mel, categoria_mel,
                   descripcion, accion_correctiva, referencia, tips, base, registrado_por,
                   horas, ciclos, tiempo_atencion, componente_cambiado,
                   comp_removido_np, comp_removido_ns, comp_instalado_np, comp_instalado_ns
            FROM tbo_Falla 
            WHERE id_falla IN ($placeholders) 
            ORDER BY id_falla DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_values($ids));
    $fallas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ---------------------------------------------------------------
    // Generar Excel en formato XML SpreadsheetML (compatible con .xlsx)
    // ---------------------------------------------------------------
    $filename = 'Reporte_Fallas_AleSearchTool_' . date('Ymd_His') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // BOM UTF-8 para correcta interpretación de acentos en Excel
    echo "\xEF\xBB\xBF";

    // Inicio del documento XML SpreadsheetML
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
        xmlns:o="urn:schemas-microsoft-com:office:office"
        xmlns:x="urn:schemas-microsoft-com:office:excel"
        xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
        xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";

    // Estilos
    echo '<Styles>
        <Style ss:ID="sHeader">
            <Font ss:Bold="1" ss:Color="#FFFFFF" ss:Size="11" ss:FontName="Calibri"/>
            <Interior ss:Color="#1a419c" ss:Pattern="Solid"/>
            <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
                <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#FFFFFF"/>
            </Borders>
        </Style>
        <Style ss:ID="sData">
            <Font ss:Size="10" ss:FontName="Calibri"/>
            <Alignment ss:Vertical="Top" ss:WrapText="1"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E0"/>
                <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E0"/>
            </Borders>
        </Style>
        <Style ss:ID="sDataAlt">
            <Font ss:Size="10" ss:FontName="Calibri"/>
            <Interior ss:Color="#F3F6FD" ss:Pattern="Solid"/>
            <Alignment ss:Vertical="Top" ss:WrapText="1"/>
            <Borders>
                <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E0"/>
                <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#CBD5E0"/>
            </Borders>
        </Style>
    </Styles>' . "\n";

    echo '<Worksheet ss:Name="Reporte de Fallas">' . "\n";
    echo '<Table>' . "\n";

    // Anchos de columna
    $colWidths = [50, 70, 80, 60, 70, 80, 85, 80, 80, 280, 280, 150, 200, 60, 120, 70, 60, 80, 90, 100, 100, 100, 100];
    foreach ($colWidths as $w) {
        echo '<Column ss:Width="' . $w . '"/>' . "\n";
    }

    // Fila de encabezados
    $headers = [
        'ID', 'Modelo', 'Matrícula', 'ATA', 'Condición', 'Folio', 'Fecha',
        'MEL Ref', 'Categoría MEL', 'Descripción de Falla', 'Acción Correctiva',
        'Referencia', 'Tips', 'Base', 'Registrado Por',
        'Horas', 'Ciclos', 'T.Atención(h)', 'Comp.Cambiado',
        'Comp.Removido NP', 'Comp.Removido NS', 'Comp.Instalado NP', 'Comp.Instalado NS'
    ];
    echo '<Row ss:Height="28">' . "\n";
    foreach ($headers as $h) {
        echo '<Cell ss:StyleID="sHeader"><Data ss:Type="String">' . htmlspecialchars($h, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
    }
    echo '</Row>' . "\n";

    // Filas de datos
    $rowIndex = 0;
    foreach ($fallas as $fa) {
        $styleId = ($rowIndex % 2 === 0) ? 'sData' : 'sDataAlt';
        $rowIndex++;

        // Formatear fecha
        $fechaStr = '';
        if (!empty($fa['fecha'])) {
            $ts = strtotime((string)$fa['fecha']);
            $fechaStr = $ts !== false ? date('d/m/Y', $ts) : (string)$fa['fecha'];
        }

        $cells = [
            ['Number', (int)$fa['id_falla']],
            ['String', (string)($fa['modelo'] ?? '')],
            ['String', (string)($fa['matricula'] ?? '')],
            ['String', (string)($fa['ata'] ?? '')],
            ['String', (string)($fa['condicion'] ?? '')],
            ['String', (string)($fa['folio'] ?? '')],
            ['String', $fechaStr],
            ['String', (string)($fa['mel'] ?? '')],
            ['String', (string)($fa['categoria_mel'] ?? '')],
            ['String', (string)($fa['descripcion'] ?? '')],
            ['String', (string)($fa['accion_correctiva'] ?? '')],
            ['String', (string)($fa['referencia'] ?? '')],
            ['String', (string)($fa['tips'] ?? '')],
            ['String', (string)($fa['base'] ?? '')],
            ['String', (string)($fa['registrado_por'] ?? '')],
            ['Number', $fa['horas'] !== null ? (float)$fa['horas'] : ''],
            ['Number', $fa['ciclos'] !== null ? (int)$fa['ciclos'] : ''],
            ['Number', $fa['tiempo_atencion'] !== null ? (float)$fa['tiempo_atencion'] : ''],
            ['String', (string)($fa['componente_cambiado'] ?? 'No')],
            ['String', (string)($fa['comp_removido_np'] ?? 'N/A')],
            ['String', (string)($fa['comp_removido_ns'] ?? 'N/A')],
            ['String', (string)($fa['comp_instalado_np'] ?? 'N/A')],
            ['String', (string)($fa['comp_instalado_ns'] ?? 'N/A')],
        ];

        echo '<Row>' . "\n";
        foreach ($cells as $cell) {
            [$type, $val] = $cell;
            if ($val === '' || $val === null) {
                echo '<Cell ss:StyleID="' . $styleId . '"><Data ss:Type="String"></Data></Cell>' . "\n";
            } elseif ($type === 'Number') {
                echo '<Cell ss:StyleID="' . $styleId . '"><Data ss:Type="Number">' . htmlspecialchars((string)$val, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            } else {
                echo '<Cell ss:StyleID="' . $styleId . '"><Data ss:Type="String">' . htmlspecialchars((string)$val, ENT_XML1, 'UTF-8') . '</Data></Cell>' . "\n";
            }
        }
        echo '</Row>' . "\n";
    }

    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>' . "\n";
    exit;

} catch (\Exception $e) {
    error_log("Error exportando Excel: " . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo "Error interno al generar el Excel.";
    exit;
}
