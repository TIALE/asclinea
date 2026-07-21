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

// --- Helper function to extract and match Sub-ATA consistently ---
function rowMatchesSubAta(array $row, string $targetSubAta, string $ataName): bool
{
    if ($targetSubAta === '') {
        return true;
    }
    
    // Obtener el número de ATA principal (ej: 21 de "21 - Air Conditioning")
    $ataNum = '';
    if (preg_match('/^(\d+)/', $ataName, $m)) {
        $ataNum = $m[1];
    }
    
    $ref = (string)($row['referencia'] ?? '');
    // Separar si hay múltiples referencias
    $parts = explode('|', $ref);
    $foundSub = '';
    
    foreach ($parts as $part) {
        $part = trim($part);
        if ($ataNum !== '') {
            $pos = strpos($part, $ataNum);
            if ($pos !== false) {
                $extracted = substr($part, $pos, 5);
                if (preg_match('/^\d{2}-\d{2}$/', $extracted)) {
                    $foundSub = $extracted;
                    break;
                }
            }
        }
    }
    
    if (empty($foundSub)) {
        $foundSub = $ataNum !== '' ? "{$ataNum}-00" : "Otros";
    }
    
    return $foundSub === $targetSubAta;
}

// Validar que el usuario tenga sesión activa para evitar fugas de información (OWASP)
if (!SessionManager::has('user_id')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'No autorizado. Acceso denegado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_GET['action'] ?? '');
$range = (string)($_GET['range'] ?? 'todo');
$modelo = (string)($_GET['modelo'] ?? '');
$ata = (string)($_GET['ata'] ?? '');
$sub_ata = (string)($_GET['sub_ata'] ?? '');
$np = (string)($_GET['np'] ?? '');

try {
    $pdo = DatabaseConnection::getConnection();

    // 1. Construir la cláusula de fecha según el rango seleccionado
    $dateClause = "";
    $params = [];
    if ($range === '3_months') {
        $dateClause = " AND fecha >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) ";
    } elseif ($range === '6_months') {
        $dateClause = " AND fecha >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) ";
    } elseif ($range === '1_year') {
        $dateClause = " AND fecha >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR) ";
    } elseif ($range === 'mensual') {
        $dateClause = " AND fecha >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND fecha <= LAST_DAY(CURDATE()) ";
    }

    switch ($action) {
        case 'getModelos':
            $query = "
                SELECT modelo, COUNT(*) as qty 
                FROM tbo_Falla 
                WHERE 1=1 {$dateClause}
                GROUP BY modelo 
                ORDER BY qty DESC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $data
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getAtas':
            if ($modelo === '') {
                throw new Exception("El modelo es requerido para esta acción.");
            }
            $query = "
                SELECT ata, COUNT(*) as qty 
                FROM tbo_Falla 
                WHERE modelo = :modelo {$dateClause}
                GROUP BY ata 
                ORDER BY qty DESC
            ";
            $params[':modelo'] = $modelo;
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'modelo' => $modelo,
                'data' => $data
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getMatriculas':
            if ($modelo === '' || $ata === '') {
                throw new Exception("El modelo y el ATA son requeridos para esta acción.");
            }
            
            // Gráfico por matrícula
            $queryMat = "
                SELECT matricula, COUNT(*) as qty 
                FROM tbo_Falla 
                WHERE modelo = :modelo AND ata = :ata {$dateClause}
                GROUP BY matricula 
                ORDER BY qty DESC
            ";
            $paramsMat = [':modelo' => $modelo, ':ata' => $ata];
            $stmtMat = $pdo->prepare($queryMat);
            $stmtMat->execute($paramsMat);
            $dataMat = $stmtMat->fetchAll(PDO::FETCH_ASSOC);

            // N/P más frecuente de la columna referencia
            $queryPart = "
                SELECT referencia, COUNT(*) as qty
                FROM tbo_Falla
                WHERE modelo = :modelo 
                  AND ata = :ata 
                  AND referencia IS NOT NULL 
                  AND TRIM(referencia) != '' 
                  {$dateClause}
                GROUP BY referencia
                ORDER BY qty DESC, referencia ASC
                LIMIT 1
            ";
            $stmtPart = $pdo->prepare($queryPart);
            $stmtPart->execute($paramsMat);
            $partData = $stmtPart->fetch(PDO::FETCH_ASSOC);

            $frequentPart = "N/A";
            $frequentPartCount = 0;
            if ($partData) {
                $frequentPart = $partData['referencia'];
                $frequentPartCount = (int)$partData['qty'];
            }

            echo json_encode([
                'success' => true,
                'modelo' => $modelo,
                'ata' => $ata,
                'data' => $dataMat,
                'frequentPart' => $frequentPart,
                'frequentPartCount' => $frequentPartCount
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getSubAtas':
            if ($modelo === '' || $ata === '') {
                throw new Exception("El modelo y el ATA son requeridos.");
            }
            
            // Consultar referencias y mel para extraer Sub-ATAs en PHP
            $query = "
                SELECT referencia, mel 
                FROM tbo_Falla 
                WHERE modelo = :modelo AND ata = :ata {$dateClause}
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':modelo' => $modelo, ':ata' => $ata]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Obtener el número de ATA principal (ej: 21 de "21 - Air Conditioning")
            $ataNum = '';
            if (preg_match('/^(\d+)/', $ata, $m)) {
                $ataNum = $m[1];
            }

            $subAtas = [];
            foreach ($rows as $row) {
                $ref = (string)($row['referencia'] ?? '');
                $parts = explode('|', $ref);
                $foundSubForThisRow = false;
                
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($ataNum !== '') {
                        $pos = strpos($part, $ataNum);
                        if ($pos !== false) {
                            $extracted = substr($part, $pos, 5);
                            if (preg_match('/^\d{2}-\d{2}$/', $extracted)) {
                                $subAtas[$extracted] = ($subAtas[$extracted] ?? 0) + 1;
                                $foundSubForThisRow = true;
                                break; // Contamos una sub_ata por fila
                            }
                        }
                    }
                }
                
                if (!$foundSubForThisRow) {
                    $sub = $ataNum !== '' ? "{$ataNum}-00" : "Otros";
                    $subAtas[$sub] = ($subAtas[$sub] ?? 0) + 1;
                }
            }

            // Formatear y ordenar
            $data = [];
            foreach ($subAtas as $sub => $qty) {
                $data[] = ['sub_ata' => $sub, 'qty' => $qty];
            }
            usort($data, fn($a, $b) => $b['qty'] <=> $a['qty']);

            echo json_encode([
                'success' => true,
                'data' => $data
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getComponentesSubAta':
            if ($modelo === '' || $ata === '') {
                throw new Exception("El modelo y el ATA son requeridos.");
            }

            // Consultar registros (incluyendo comp_removido_ns y matricula)
            $query = "
                SELECT referencia, mel, comp_removido_np, comp_removido_ns, comp2_removido_np, comp2_removido_ns, componentes_adicionales, matricula
                FROM tbo_Falla
                WHERE modelo = :modelo AND ata = :ata {$dateClause}
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':modelo' => $modelo, ':ata' => $ata]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $componentes = [];
            foreach ($rows as $row) {
                // Verificar si pertenece al sub_ata utilizando la función consistente
                if (!rowMatchesSubAta($row, $sub_ata, $ata)) {
                    continue;
                }

                // Extraer todos los componentes removidos de este reporte
                $comps = [];
                $comps[] = ['np' => trim((string)($row['comp_removido_np'] ?? '')), 'ns' => trim((string)($row['comp_removido_ns'] ?? ''))];
                $comps[] = ['np' => trim((string)($row['comp2_removido_np'] ?? '')), 'ns' => trim((string)($row['comp2_removido_ns'] ?? ''))];
                
                $adicionales = trim((string)($row['componentes_adicionales'] ?? ''));
                if (!empty($adicionales)) {
                    $jsonAdic = json_decode($adicionales, true);
                    if (is_array($jsonAdic)) {
                        foreach ($jsonAdic as $comp) {
                            $comps[] = [
                                'np' => trim((string)($comp['removido_np'] ?? '')),
                                'ns' => trim((string)($comp['removido_ns'] ?? ''))
                            ];
                        }
                    }
                }

                $mat = trim((string)($row['matricula'] ?? ''));

                foreach ($comps as $c) {
                    $npVal = $c['np'];
                    $nsVal = $c['ns'];

                    if ($npVal === '' || $npVal === 'N/A') {
                        continue; // Saltar si no hay un N/P de removido válido
                    }

                    // Formatear etiqueta con N/P y S/N
                    $label = $npVal;
                    if ($nsVal !== '' && $nsVal !== 'N/A') {
                        $label .= " (S/N: {$nsVal})";
                    }

                    if (!isset($componentes[$label])) {
                        $componentes[$label] = [
                            'np' => $npVal,
                            'label' => $label,
                            'qty' => 0,
                            'matriculas' => []
                        ];
                    }
                    $componentes[$label]['qty']++;
                    
                    if ($mat !== '' && !in_array($mat, $componentes[$label]['matriculas'])) {
                        $componentes[$label]['matriculas'][] = $mat;
                    }
                }
            }

            // Formatear y ordenar
            $data = [];
            foreach ($componentes as $label => $info) {
                $data[] = [
                    'np' => $info['np'],
                    'label' => $info['label'],
                    'qty' => $info['qty'],
                    'matriculas' => implode(', ', $info['matriculas'])
                ];
            }
            usort($data, fn($a, $b) => $b['qty'] <=> $a['qty']);

            echo json_encode([
                'success' => true,
                'data' => $data
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getKpisComponente':
            if ($modelo === '' || $ata === '') {
                throw new Exception("El modelo y el ATA son requeridos.");
            }

            // Construir consulta filtrada
            $sql = "
                SELECT horas, tiempo_atencion, fecha
                FROM tbo_Falla
                WHERE modelo = :modelo AND ata = :ata {$dateClause}
            ";
            
            $bindParams = [':modelo' => $modelo, ':ata' => $ata];

            // Si se pasa un N/P, filtrar por él
            if ($np !== '') {
                $sql .= " AND (comp_instalado_np = :np OR comp_removido_np = :np OR comp2_instalado_np = :np OR comp2_removido_np = :np OR componentes_adicionales LIKE :np_like)";
                $bindParams[':np'] = $np;
                $bindParams[':np_like'] = '%' . $np . '%';
            } elseif ($sub_ata !== '') {
                // Si se pasa sub_ata, podemos pre-filtrar las filas en PHP
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindParams);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Si se especificó sub_ata y no np, filtramos en PHP
            if ($sub_ata !== '' && $np === '') {
                $filteredRows = [];
                // Para filtrar por sub_ata, necesitamos traer referencia/mel de nuevo
                $sqlSub = "
                    SELECT horas, tiempo_atencion, fecha, referencia, mel
                    FROM tbo_Falla
                    WHERE modelo = :modelo AND ata = :ata {$dateClause}
                ";
                $stmtSub = $pdo->prepare($sqlSub);
                $stmtSub->execute([':modelo' => $modelo, ':ata' => $ata]);
                $rowsSub = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rowsSub as $row) {
                    $texto = ($row['referencia'] ?? '') . ' ' . ($row['mel'] ?? '');
                    if (str_contains($texto, $sub_ata)) {
                        $filteredRows[] = $row;
                    }
                }
                $rows = $filteredRows;
            }

            $cantFallas = count($rows);

            // Calcular MTTR
            $sumaTiempoAtencion = 0;
            $cantTiempos = 0;
            foreach ($rows as $row) {
                if (!empty($row['tiempo_atencion'])) {
                    $sumaTiempoAtencion += (float)$row['tiempo_atencion'];
                    $cantTiempos++;
                }
            }
            $mttr = $cantTiempos > 0 ? round($sumaTiempoAtencion / $cantTiempos, 2) : 2.0;

            // Calcular MTBF
            $horasValores = [];
            $fechasValores = [];
            foreach ($rows as $row) {
                if (!empty($row['horas'])) {
                    $horasValores[] = (float)$row['horas'];
                }
                if (!empty($row['fecha'])) {
                    $fechasValores[] = strtotime($row['fecha']);
                }
            }

            $mtbf = 0;
            if ($cantFallas > 0) {
                if (count($horasValores) >= 2) {
                    $maxH = max($horasValores);
                    $minH = min($horasValores);
                    $mtbf = ($maxH > $minH) ? round(($maxH - $minH) / ($cantFallas - 1), 1) : round($maxH / $cantFallas, 1);
                } else {
                    // Estimación basada en tiempo transcurrido (Días * tasa utilización 1.8h / fallas)
                    if (count($fechasValores) >= 2) {
                        $maxF = max($fechasValores);
                        $minF = min($fechasValores);
                        $dias = ($maxF - $minF) / 86400;
                        if ($dias <= 0) $dias = 30; // Fallback mínimo un mes
                        $horasEstimadas = $dias * 1.8;
                        $mtbf = round($horasEstimadas / $cantFallas, 1);
                    } else {
                        // Una sola falla o ninguna
                        $mtbf = ($cantFallas === 1 && !empty($horasValores)) ? round($horasValores[0], 1) : 1200.0;
                    }
                }
            }

            // --- CÁLCULO DE TENDENCIA DE 6 MESES PARA SPARKLINES ---
            $trendMonths = [];
            for ($i = 5; $i >= 0; $i--) {
                $trendMonths[] = date('Y-m', strtotime("-$i months"));
            }

            // Consultar datos de los últimos 6 meses completos
            $sqlTrend = "
                SELECT horas, tiempo_atencion, fecha
                FROM tbo_Falla
                WHERE modelo = :modelo AND ata = :ata
                  AND fecha >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
            ";
            $trendParams = [':modelo' => $modelo, ':ata' => $ata];
            if ($np !== '') {
                $sqlTrend .= " AND (comp_instalado_np = :np OR comp_removido_np = :np OR comp2_instalado_np = :np OR comp2_removido_np = :np OR componentes_adicionales LIKE :np_like)";
                $trendParams[':np'] = $np;
                $trendParams[':np_like'] = '%' . $np . '%';
            }
            $stmtTrend = $pdo->prepare($sqlTrend);
            $stmtTrend->execute($trendParams);
            $trendRows = $stmtTrend->fetchAll(PDO::FETCH_ASSOC);

            // Si se especificó sub_ata y no np, filtrar en PHP
            if ($sub_ata !== '' && $np === '') {
                $filteredTrend = [];
                // Consultar con referencia/mel para poder filtrar por sub_ata
                $sqlTrendSub = "
                    SELECT horas, tiempo_atencion, fecha, referencia, mel
                    FROM tbo_Falla
                    WHERE modelo = :modelo AND ata = :ata
                      AND fecha >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 5 MONTH)
                ";
                $stmtTrendSub = $pdo->prepare($sqlTrendSub);
                $stmtTrendSub->execute([':modelo' => $modelo, ':ata' => $ata]);
                $trendSubRows = $stmtTrendSub->fetchAll(PDO::FETCH_ASSOC);

                foreach ($trendSubRows as $row) {
                    $texto = ($row['referencia'] ?? '') . ' ' . ($row['mel'] ?? '');
                    if (str_contains($texto, $sub_ata)) {
                        $filteredTrend[] = $row;
                    }
                }
                $trendRows = $filteredTrend;
            }

            // Agrupar fallas por mes
            $monthlyData = [];
            foreach ($trendMonths as $m) {
                $monthlyData[$m] = [];
            }
            foreach ($trendRows as $row) {
                if (!empty($row['fecha'])) {
                    $mKey = date('Y-m', strtotime($row['fecha']));
                    if (isset($monthlyData[$mKey])) {
                        $monthlyData[$mKey][] = $row;
                    }
                }
            }

            // Calcular MTBF/MTTR mensual para la tendencia
            $mtbfTrend = [];
            $mttrTrend = [];
            foreach ($trendMonths as $m) {
                $mRows = $monthlyData[$m];
                $mCant = count($mRows);
                
                // MTTR mensual
                $mSumaAtencion = 0;
                $mCantAtencion = 0;
                foreach ($mRows as $r) {
                    if (!empty($r['tiempo_atencion'])) {
                        $mSumaAtencion += (float)$r['tiempo_atencion'];
                        $mCantAtencion++;
                    }
                }
                $mttrTrend[] = $mCantAtencion > 0 ? round($mSumaAtencion / $mCantAtencion, 2) : 2.0;

                // MTBF mensual
                $mHoras = [];
                $mFechas = [];
                foreach ($mRows as $r) {
                    if (!empty($r['horas'])) {
                        $mHoras[] = (float)$r['horas'];
                    }
                    if (!empty($r['fecha'])) {
                        $mFechas[] = strtotime($r['fecha']);
                    }
                }

                $mMtbf = 1200.0; // Valor base por defecto
                if ($mCant > 0) {
                    if (count($mHoras) >= 2) {
                        $maxH = max($mHoras);
                        $minH = min($mHoras);
                        $mMtbf = ($maxH > $minH) ? round(($maxH - $minH) / ($mCant - 1), 1) : round($maxH / $mCant, 1);
                    } else {
                        if (count($mFechas) >= 2) {
                            $dias = (max($mFechas) - min($mFechas)) / 86400;
                            if ($dias <= 0) $dias = 30;
                            $mMtbf = round(($dias * 1.8) / $mCant, 1);
                        } else {
                            $mMtbf = ($mCant === 1 && !empty($mHoras)) ? round($mHoras[0], 1) : 1200.0;
                        }
                    }
                }
                $mtbfTrend[] = $mMtbf;
            }

            echo json_encode([
                'success' => true,
                'cant_fallas' => $cantFallas,
                'mtbf' => $mtbf,
                'mttr' => $mttr,
                'mtbf_trend' => $mtbfTrend,
                'mttr_trend' => $mttrTrend
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'getParetoConfiabilidad':
            if ($modelo === '' || $ata === '' || $sub_ata === '') {
                throw new Exception("El modelo, el ATA y el Sub-ATA son requeridos.");
            }

            // Consultar todos los campos para el Pareto
            $query = "
                SELECT referencia, mel, tiempo_atencion, fecha, horas
                FROM tbo_Falla
                WHERE modelo = :modelo AND ata = :ata {$dateClause}
                ORDER BY fecha ASC
            ";
            $stmt = $pdo->prepare($query);
            $stmt->execute([':modelo' => $modelo, ':ata' => $ata]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $paretoData = [];
            $allDates = [];

            foreach ($rows as $row) {
                if (rowMatchesSubAta($row, $sub_ata, $ata)) {
                    if (!empty($row['fecha'])) {
                        $allDates[] = $row['fecha'];
                    }

                    // Obtener la referencia
                    $ref = trim((string)($row['referencia'] ?? ''));
                    if ($ref === '') {
                        $ref = 'Sin Referencia';
                    }

                    if (!isset($paretoData[$ref])) {
                        $paretoData[$ref] = [
                            'reference' => $ref,
                            'fail_count' => 0,
                            'total_attention' => 0.0,
                            'attention_entries' => 0
                        ];
                    }

                    $paretoData[$ref]['fail_count']++;
                    if (!empty($row['tiempo_atencion'])) {
                        $paretoData[$ref]['total_attention'] += (float)$row['tiempo_atencion'];
                        $paretoData[$ref]['attention_entries']++;
                    }
                }
            }

            // Procesar y ordenar por fallas desc (Pareto)
            $processed = [];
            foreach ($paretoData as $ref => $data) {
                $avgMttr = $data['attention_entries'] > 0 ? round($data['total_attention'] / $data['attention_entries'], 2) : 2.0;
                
                $processed[] = [
                    'reference' => $ref,
                    'fail_count' => $data['fail_count'],
                    'total_attention' => round($data['total_attention'], 1),
                    'mttr' => $avgMttr
                ];
            }

            // Ordenar por fallas desc
            usort($processed, fn($a, $b) => $b['fail_count'] <=> $a['fail_count']);

            echo json_encode([
                'success' => true,
                'data' => $processed,
                'dates' => $allDates
            ], JSON_UNESCAPED_UNICODE);
            break;

        default:
            throw new Exception("Acción inválida o no soportada.");
    }

} catch (\Throwable $e) {
    error_log("Error en api_dashboard.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error interno procesando la solicitud de gráfica.'
    ], JSON_UNESCAPED_UNICODE);
}
