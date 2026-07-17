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

// Cargar y descifrar la configuración segura para poblar las variables de entorno (API Key, JSON de agentes, Agente IT)
$secureConfig = \App\Shared\Config\SecureConfig::decrypt();
foreach ($secureConfig as $k => $v) {
    if (!empty($v)) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
        $_SERVER[$k] = $v;
    }
}

SessionManager::start();

// Control de acceso OWASP
if (!SessionManager::has('user_id')) {
    echo json_encode(['reply' => 'Sesión no autorizada. Por favor, vuelva a iniciar sesión.']);
    exit;
}

$timeStart = microtime(true);
$fechaInicio = date('Y-m-d H:i:s');

// Obtener datos del cuerpo JSON
$inputData = json_decode(file_get_contents('php://input'), true);
$mensaje   = isset($inputData['message']) ? trim((string)$inputData['message']) : '';
$historial = isset($inputData['history']) ? $inputData['history'] : [];

if (empty($mensaje)) {
    echo json_encode(['reply' => 'Por favor, ingrese un mensaje o pregunta técnica.']);
    exit;
}

// 1. Obtener la clave API de Gemini del archivo de configuración segura o variables de entorno
$apiKey = getenv('GEMINI_API_KEY');

if (empty($apiKey)) {
    echo json_encode(['reply' => '⚠ Error de Configuración: No se encontró una clave API de Gemini válida (GEMINI_API_KEY). Por favor, configure su API Key en la sección de Administración > Configuración para habilitar el Asistente de IA.']);
    exit;
}

// 2. Motor RAG (Retrieval-Augmented Generation) & Rastreador de Estados Conversacionales
$totalFallas = 0;
$totalConsumibles = 0;
$modeloSeleccionado = '';
$matriculaIngresada = '';
$fallaReportada = '';
$quiereConsultaAdicional = false;

$contextoMatricula = "No se encontraron registros previos para la matrícula.";
$contextoFallaGeneral = "No se encontraron registros históricos generales relacionados con esta falla.";
$contextoConsumibles = "No se encontraron consumibles relevantes.";

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Obtener recuentos totales reales
    $totalFallas = (int)$pdo->query("SELECT COUNT(*) FROM tbo_Falla")->fetchColumn();
    $totalConsumibles = (int)$pdo->query("SELECT COUNT(*) FROM consumibles")->fetchColumn();
    
    // Palabras vacías comunes
    $stopWords = ['de', 'la', 'el', 'en', 'para', 'un', 'una', 'con', 'y', 'o', 'el', 'los', 'las', 'que', 'en', 'del', 'al', 'un', 'falla', 'problema', 'tengo', 'hay'];
    
    // Escanear historial de mensajes para reconstruir la conversación en orden cronológico
    $userTurns = [];
    foreach ($historial as $turn) {
        if (($turn['role'] ?? '') === 'user') {
            $userTurns[] = trim((string)$turn['content']);
        }
    }
    $userTurns[] = $mensaje;

    // 2.1 Buscar/detectar Modelo de aeronave en el historial de usuario
    foreach ($userTurns as $turn) {
        if (preg_match('/(challenger\s*605|cl605)/i', $turn)) {
            $modeloSeleccionado = 'CL605';
        } elseif (preg_match('/(learjet\s*75|lj75)/i', $turn)) {
            $modeloSeleccionado = 'LJ75';
        } elseif (preg_match('/(learjet\s*45|lj45)/i', $turn)) {
            $modeloSeleccionado = 'LJ45';
        } elseif (preg_match('/(citation\s*525b|525b)/i', $turn)) {
            $modeloSeleccionado = '525B';
        } elseif (preg_match('/(citation\s*latitude|680a)/i', $turn)) {
            $modeloSeleccionado = '680A';
        }
    }

    // 2.2 Buscar/detectar Matrícula y Falla basándonos en el flujo secuencial de preguntas del asistente
    $askedForMatricula = false;
    $askedForFalla = false;
    foreach ($historial as $turn) {
        $role = $turn['role'] ?? '';
        if ($role === 'model' || $role === 'assistant') {
            $content = mb_strtolower($turn['content'] ?? '');
            if (str_contains($content, 'falla') || str_contains($content, 'problema') || str_contains($content, 'síntoma') || str_contains($content, 'sintoma') || str_contains($content, 'descríbame')) {
                $askedForFalla = true;
                $askedForMatricula = false;
            } elseif (str_contains($content, 'matrícula') || str_contains($content, 'matricula')) {
                $askedForMatricula = true;
                $askedForFalla = false;
            }
        } elseif ($role === 'user') {
            if ($askedForMatricula) {
                $matriculaIngresada = strtoupper(trim((string)$turn['content']));
                $askedForMatricula = false;
            } elseif ($askedForFalla) {
                $fallaReportada = trim((string)$turn['content']);
                $askedForFalla = false;
            }
        }
    }

    // 2.3 Determinar el último mensaje del asistente para conocer el estado actual esperado
    $lastAssistantMessage = '';
    for ($i = count($historial) - 1; $i >= 0; $i--) {
        if (($historial[$i]['role'] ?? '') === 'model' || ($historial[$i]['role'] ?? '') === 'assistant') {
            $lastAssistantMessage = mb_strtolower($historial[$i]['content'] ?? '');
            break;
        }
    }

    // 2.4 Calcular estado actual de la conversación
    $estadoConversacion = 'INICIO';
    if (empty($lastAssistantMessage)) {
        $estadoConversacion = 'INICIO';
    } elseif (str_contains($lastAssistantMessage, 'consultar otra cosa') || str_contains($lastAssistantMessage, 'otra consulta') || str_contains($lastAssistantMessage, 'historial general')) {
        if (preg_match('/\b(si|sí|yes|claro|por favor|s|deseo|quiero)\b/i', $mensaje)) {
            $quiereConsultaAdicional = true;
            $estadoConversacion = 'QUIERE_HISTORIAL_GENERAL';
        } else {
            $estadoConversacion = 'DESPEDIDA';
        }
    } elseif (str_contains($lastAssistantMessage, 'falla') || str_contains($lastAssistantMessage, 'problema') || str_contains($lastAssistantMessage, 'síntoma') || str_contains($lastAssistantMessage, 'sintoma') || str_contains($lastAssistantMessage, 'descríbame')) {
        $fallaReportada = trim($mensaje);
        $estadoConversacion = 'FALLA_RECIBIDA';
    } elseif (str_contains($lastAssistantMessage, 'matrícula') || str_contains($lastAssistantMessage, 'matricula')) {
        $matriculaIngresada = strtoupper(trim($mensaje));
        $estadoConversacion = 'MATRICULA_RECIBIDA';
    } elseif (str_contains($lastAssistantMessage, 'modelo') || str_contains($lastAssistantMessage, 'menú') || str_contains($lastAssistantMessage, 'opción') || str_contains($lastAssistantMessage, 'indíqueme')) {
        $estadoConversacion = 'MODELO_RECIBIDO';
        // Extraer modelo seleccionado en este turno
        if (preg_match('/(challenger\s*605|cl605)/i', $mensaje)) {
            $modeloSeleccionado = 'CL605';
        } elseif (preg_match('/(learjet\s*75|lj75)/i', $mensaje)) {
            $modeloSeleccionado = 'LJ75';
        } elseif (preg_match('/(learjet\s*45|lj45)/i', $mensaje)) {
            $modeloSeleccionado = 'LJ45';
        } elseif (preg_match('/(citation\s*525b|525b)/i', $mensaje)) {
            $modeloSeleccionado = '525B';
        } elseif (preg_match('/(citation\s*latitude|680a)/i', $mensaje)) {
            $modeloSeleccionado = '680A';
        }
    }

    // 2.5 Ejecutar consultas SQL seguras en función del estado de la conversación
    if (!empty($matriculaIngresada)) {
        $stmtM = $pdo->prepare("SELECT id_falla, fecha, matricula, modelo, ata, descripcion, accion_correctiva, referencia, tips 
                                FROM tbo_Falla 
                                WHERE matricula = :matricula 
                                ORDER BY id_falla DESC LIMIT 10");
        $stmtM->execute([':matricula' => $matriculaIngresada]);
        $resultadosM = $stmtM->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($resultadosM)) {
            $contextoMatricula = "Historial específico de fallas previas registradas para la matrícula {$matriculaIngresada} en ALE Service Center:\n";
            foreach ($resultadosM as $row) {
                $contextoMatricula .= "- ID Falla: #" . $row['id_falla'] . " | Fecha: " . ($row['fecha'] ?? '') . " | ATA: " . ($row['ata'] ?? '') . " | Modelo: " . ($row['modelo'] ?? '') . "\n";
                $contextoMatricula .= "  Falla Reportada: " . ($row['descripcion'] ?? '') . "\n";
                $contextoMatricula .= "  Acción Correctiva Realizada: " . ($row['accion_correctiva'] ?? '') . "\n";
                $contextoMatricula .= "  Referencia AMM: " . ($row['referencia'] ?? '') . "\n";
                $contextoMatricula .= "  Tips de Campo: " . ($row['tips'] ?? '') . "\n";
                $contextoMatricula .= "  --------------------------------------------------\n";
            }
        } else {
            $contextoMatricula = "No se registran fallas históricas previas específicas para la matrícula {$matriculaIngresada} en la base de datos de producción.";
        }
    }

    // Búsqueda general de fallas relacionadas por palabras clave
    $searchQuery = !empty($fallaReportada) ? $fallaReportada : $mensaje;
    $words = preg_split('/[\s,?.!\-\/]+/', mb_strtolower($searchQuery));
    $keywords = [];
    if ($words !== false) {
        foreach ($words as $w) {
            $w = trim($w);
            if (mb_strlen($w) > 3 && !in_array($w, $stopWords, true)) {
                $keywords[] = $w;
            }
        }
    }

    $resultadosG = [];
    if (!empty($keywords)) {
        $sql = "SELECT id_falla, fecha, matricula, modelo, ata, descripcion, accion_correctiva, referencia, tips 
                FROM tbo_Falla WHERE (1=0";
        $params = [];
        $i = 0;
        foreach ($keywords as $kw) {
            $sql .= " OR descripcion LIKE :kw{$i}_desc OR accion_correctiva LIKE :kw{$i}_acc OR tips LIKE :kw{$i}_tips";
            $params[":kw{$i}_desc"] = "%{$kw}%";
            $params[":kw{$i}_acc"] = "%{$kw}%";
            $params[":kw{$i}_tips"] = "%{$kw}%";
            $i++;
        }
        $sql .= ")";
        if (!empty($modeloSeleccionado)) {
            $sql .= " AND modelo = :modelo";
            $params[':modelo'] = $modeloSeleccionado;
        }
        $sql .= " ORDER BY id_falla DESC LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $resultadosG = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fallback: si no hay resultados directos pero hay modelo seleccionado, traer las últimas del modelo
    if (empty($resultadosG) && !empty($modeloSeleccionado)) {
        $stmtFallback = $pdo->prepare("SELECT id_falla, fecha, matricula, modelo, ata, descripcion, accion_correctiva, referencia, tips 
                                       FROM tbo_Falla WHERE modelo = :modelo ORDER BY id_falla DESC LIMIT 5");
        $stmtFallback->execute([':modelo' => $modeloSeleccionado]);
        $resultadosG = $stmtFallback->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($resultadosG)) {
        $contextoFallaGeneral = "Historial general de fallas similares relacionadas por palabras clave en la flota de ALE Service Center:\n";
        foreach ($resultadosG as $row) {
            $contextoFallaGeneral .= "- ID Falla: #" . $row['id_falla'] . " | Matrícula: " . ($row['matricula'] ?? '') . " | Modelo: " . ($row['modelo'] ?? '') . " | ATA: " . ($row['ata'] ?? '') . "\n";
            $contextoFallaGeneral .= "  Falla Reportada: " . ($row['descripcion'] ?? '') . "\n";
            $contextoFallaGeneral .= "  Acción Correctiva Realizada: " . ($row['accion_correctiva'] ?? '') . "\n";
            $contextoFallaGeneral .= "  Referencia AMM: " . ($row['referencia'] ?? '') . "\n";
            $contextoFallaGeneral .= "  Tips de Campo: " . ($row['tips'] ?? '') . "\n";
            $contextoFallaGeneral .= "  --------------------------------------------------\n";
        }
    }

    // Buscar consumibles
    $resultadosConsumibles = [];
    if (!empty($keywords)) {
        $sqlC = "SELECT nombre, numero_parte, categoria FROM consumibles WHERE (1=0";
        $paramsC = [];
        $i = 0;
        foreach ($keywords as $kw) {
            $sqlC .= " OR nombre LIKE :kw{$i}_nom OR numero_parte LIKE :kw{$i}_np OR categoria LIKE :kw{$i}_cat";
            $paramsC[":kw{$i}_nom"] = "%{$kw}%";
            $paramsC[":kw{$i}_np"] = "%{$kw}%";
            $paramsC[":kw{$i}_cat"] = "%{$kw}%";
            $i++;
        }
        $sqlC .= ")";
        $sqlC .= " ORDER BY id ASC LIMIT 8";
        $stmtC = $pdo->prepare($sqlC);
        $stmtC->execute($paramsC);
        $resultadosConsumibles = $stmtC->fetchAll(PDO::FETCH_ASSOC);
    }
    if (empty($resultadosConsumibles)) {
        $stmtCRecent = $pdo->query("SELECT nombre, numero_parte, categoria FROM consumibles ORDER BY id ASC LIMIT 5");
        $resultadosConsumibles = $stmtCRecent->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($resultadosConsumibles)) {
        $contextoConsumibles = "Consumibles y números de parte en inventario real de producción:\n";
        foreach ($resultadosConsumibles as $rowC) {
            $contextoConsumibles .= "- " . ($rowC['nombre'] ?? '') . " | P/N: " . ($rowC['numero_parte'] ?? '') . " | Categoría: " . ($rowC['categoria'] ?? '') . "\n";
        }
    }

} catch (\Exception $e) {
    error_log("Error RAG en asistente_api: " . $e->getMessage());
    $contextoMatricula = "Error al intentar consultar la base de datos de matrículas.";
    $contextoFallaGeneral = "Error al intentar consultar la base de datos histórica de fallas.";
    $contextoConsumibles = "Error al intentar consultar la base de datos de consumibles.";
}

// Cargar prompt inicial personalizado del Agente IT desde el entorno si existe, o usar el valor predeterminado
$agenteItPrompt = getenv('AGENTE_IT');
if (empty($agenteItPrompt)) {
    $agenteItPrompt = "Eres un Asistente Técnico Aeronáutico altamente capacitado de ALE Service Center para el sistema AleSearchTool.\n\nTu tarea es responder de forma profesional, clara y técnica a las consultas de mantenimiento de la flota.";
}

// Reemplazar de forma dinámica cualquier mensaje de error simulado en el prompt personalizado por los datos y contadores reales
if (!empty($agenteItPrompt)) {
    $placeholderErrorFallas = [
        "El sistema reporta actualmente un *Error al intentar consultar la base de datos histórica*.",
        "El sistema reporta actualmente un Error al intentar consultar la base de datos histórica.",
        "Error al intentar consultar la base de datos histórica"
    ];
    foreach ($placeholderErrorFallas as $ph) {
        $agenteItPrompt = str_ireplace($ph, "Conexión exitosa a la base de datos histórica. Se registran {$totalFallas} fallas reales en tbo_Falla.", $agenteItPrompt);
    }

    $placeholderErrorConsumibles = [
        "No hay consumibles ni números de parte registrados en el sistema de producción actualmente.",
        "No hay consumibles registrados en el sistema de producción actualmente."
    ];
    foreach ($placeholderErrorConsumibles as $ph) {
        $agenteItPrompt = str_ireplace($ph, "Conexión exitosa al inventario. Se registran {$totalConsumibles} consumibles y números de parte.", $agenteItPrompt);
    }
}

// 3. Formatear prompt estructurado con las directivas conversacionales estrictas
$prompt = "{$agenteItPrompt}

==================================================
ESTADO DEL DIÁLOGO (MANDATORIO)
==================================================
Modelo de aeronave detectado: " . ($modeloSeleccionado ?: 'Pendiente') . "
Matrícula detectada: " . ($matriculaIngresada ?: 'Pendiente') . "
Falla reportada: " . ($fallaReportada ?: 'Pendiente') . "
Estado conversacional actual: {$estadoConversacion}

==================================================
REGLAS DE SECUENCIA DE DIÁLOGO (ESTRICTO)
==================================================
Sigue escrupulosamente las siguientes pautas conversacionales según el estado conversacional actual detectado:

1. **ESTADO: INICIO (Paso 1)**
   - Si no hay modelo de aeronave seleccionado en el historial (Estado conversacional actual: INICIO), debes saludar al técnico bajo la identidad de MCC - ALE SERVICE CENTER (Soporte Técnico de Ingeniería Senior).
   - Indicarás de forma inmediata que, para iniciar con el diagnóstico, por favor te indique qué modelo de aeronave es.
   - DEBES presentarle los modelos mediante un menú de opción múltiple muy claro con viñetas:
     * Challenger 605
     * Learjet 75
     * Learjet 45
     * Citation Jet 525B
     * Citation Latitude 680A
   - No preguntes nada más ni muestres ningún otro paso de diagnóstico. Detente ahí y espera a que el usuario seleccione.

2. **ESTADO: MODELO_RECIBIDO (Paso 2)**
   - Si el técnico ya seleccionó el modelo (Estado conversacional actual: MODELO_RECIBIDO), agradece la confirmación del modelo y pregúntale inmediatamente cuál es la **matrícula** de la aeronave.
   - Detente y espera la respuesta del usuario.

3. **ESTADO: MATRICULA_RECIBIDA (Paso 3)**
   - Si el técnico ya indicó la matrícula (Estado conversacional actual: MATRICULA_RECIBIDA), agradece la matrícula e indícale que ha quedado registrada.
   - Pregúntale de inmediato: 'Por favor, descríbame detalladamente la falla o síntoma reportado en la aeronave.'
   - Detente y espera la respuesta del usuario.

4. **ESTADO: FALLA_RECIBIDA (Paso 4)**
   - Si el técnico ya indicó la falla (Estado conversacional actual: FALLA_RECIBIDA):
     - Muestra INMEDIATAMENTE el historial de fallas relacionadas basándote en el 'CONTEXTO DE FALLA GENERAL' y 'CONTEXTO DE MATRÍCULA' que te pasamos abajo.
     - [!] REGLA ESTRICTA: Al listar las fallas, debes mostrar ÚNICAMENTE tres campos por cada reporte: Falla Reportada, Acción Correctiva Realizada y Tips de Campo. Omite fechas, ATAs, IDs o cualquier otro dato.
     - Después de mostrar los reportes, pregunta: '¿Desea consultar otra falla diferente o damos por cerrado el soporte?'
     - Detente y espera la respuesta del usuario.

5. **ESTADO: QUIERE_HISTORIAL_GENERAL (Paso 5/6)**
   - Si el técnico responde que SÍ desea consultar otra falla (Estado conversacional actual: QUIERE_HISTORIAL_GENERAL):
     - Pídele que describa la nueva falla o síntoma.
     - Finaliza con una firma técnica simple: MCC Senior Engineering.

==================================================
CONTEXTO REAL DE LA BASE DE DATOS (MANDATORIO)
==================================================
[!] IMPORTANTE: No reportes errores ficticios de base de datos. Utiliza los siguientes datos reales del sistema para responder la consulta:

- Total de fallas reales en tbo_Falla: {$totalFallas}
- Total de consumibles y partes registradas: {$totalConsumibles}

CONTEXTO DE MATRÍCULA ({$matriculaIngresada}):
{$contextoMatricula}

CONTEXTO DE FALLA GENERAL ({$fallaReportada}):
{$contextoFallaGeneral}

CONTEXTO DE CONSUMIBLES:
{$contextoConsumibles}

Historial reciente del chat:
" . json_encode($historial, JSON_UNESCAPED_UNICODE) . "

Pregunta actual del usuario:
{$mensaje}

==================================================
REGLAS DE FORMATO Y CONCISIÓN EXTREMA (MANDATORIO)
==================================================
1. ¡RESPONDE DE FORMA DIRECTA, EXTREMADAMENTE CORTA Y CONCISA! Evita textos largos e introducciones innecesarias. Sé breve.
2. NO incluyas bajo ninguna circunstancia bloques de código JavaScript, bloques de 'SOPORTE ADMINISTRATIVO', 'SISTEMA DE MONITOREO IA', 'getAIConsultationMetrics', ni menciones sobre métricas o tiempos de ejecución en tu respuesta.
3. Queda TERMINANTEMENTE PROHIBIDO imprimir delimitadores visuales como '========================================', títulos de estado interno como 'ESTADO DEL DIÁLOGO (MANDATORIO)' o 'CONTEXTO REAL', pies de página de administración ni firmas motivacionales o cierres motivacionales excesivos.
4. Tu respuesta debe contener exclusivamente el mensaje directo de ayuda o instrucción técnica corta para el tripulante en campo, sin adornos ni bloques administrativos.
";

// 4. Llamar a la API de Google Gemini (gemini-3.5-flash)
$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent?key=" . $apiKey;

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt]
            ]
        ]
    ]
];

$ch = curl_init($url);
if ($ch === false) {
    echo json_encode(['reply' => 'Error al inicializar el cliente de comunicaciones CURL.']);
    exit;
}

curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 45); // Tiempo límite de 45 segundos para el proceso de pensamiento de Gemini 3.5
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Forzar resolución IPv4 para máxima estabilidad en Hostinger

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    echo json_encode(['reply' => 'Error al conectar con los servidores de Google Gemini (cURL error: ' . $curlError . '). Asegúrese de que el servidor tenga salida a internet habilitada e inténtelo de nuevo.']);
    exit;
}

if ($httpCode !== 200) {
    error_log("Gemini API Error Code {$httpCode}: " . $response);
    echo json_encode(['reply' => "La API de Gemini devolvió un código de error {$httpCode}. Verifique que su GEMINI_API_KEY de .env sea válida."]);
    exit;
}

$resData = json_decode((string)$response, true);
$replyText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';

if (empty($replyText)) {
    echo json_encode(['reply' => 'La IA de Gemini procesó la solicitud pero devolvió una respuesta vacía o sin candidatos válidos.']);
    exit;
}

// 5. Retornar respuesta exitosa en JSON
echo json_encode(['reply' => $replyText], JSON_UNESCAPED_UNICODE);

$timeEnd = microtime(true);
$fechaTermino = date('Y-m-d H:i:s');
$userEmail = (string)SessionManager::get('user_email');
try {
    $pdoLog = DatabaseConnection::getConnection();
    $pdoLog->exec("CREATE TABLE IF NOT EXISTS tbo_LogAsistenteIA (
        id_log INT AUTO_INCREMENT PRIMARY KEY,
        usuario_correo VARCHAR(150),
        fecha_inicio DATETIME,
        fecha_termino DATETIME,
        duracion_segundos DECIMAL(10,4)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $stmtLog = $pdoLog->prepare("INSERT INTO tbo_LogAsistenteIA (usuario_correo, fecha_inicio, fecha_termino, duracion_segundos) VALUES (?, ?, ?, ?)");
    $stmtLog->execute([$userEmail, $fechaInicio, $fechaTermino, $timeEnd - $timeStart]);
} catch (\Exception $e) {}

