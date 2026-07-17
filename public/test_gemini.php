<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/../src/autoload.php';
\App\Shared\Env\EnvLoader::load(__DIR__ . '/../.env');

$secureConfig = \App\Shared\Config\SecureConfig::decrypt();
foreach ($secureConfig as $k => $v) {
    if (!empty($v)) {
        putenv("{$k}={$v}");
        $_ENV[$k] = $v;
    }
}

$apiKey = getenv('GEMINI_API_KEY');
if (empty($apiKey)) {
    echo "<b>Error:</b> No se encontró la API Key de Gemini.<br>";
    exit;
}

echo "<h2>Test Rápido de Generación de Contenido</h2>";

$testCases = [
    "v1beta/gemini-1.5-flash" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey,
    "v1beta/gemini-1.5-flash-latest" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=" . $apiKey,
    "v1beta/gemini-1.5-pro-latest" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro-latest:generateContent?key=" . $apiKey,
    "v1beta/gemini-2.5-flash" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=" . $apiKey,
    "v1beta/gemini-2.0-flash" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=" . $apiKey,
    "v1beta/gemini-pro" => "https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=" . $apiKey,
    "v1/gemini-1.5-flash" => "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash:generateContent?key=" . $apiKey,
    "v1/gemini-1.5-flash-latest" => "https://generativelanguage.googleapis.com/v1/models/gemini-1.5-flash-latest:generateContent?key=" . $apiKey,
    "v1/gemini-pro" => "https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent?key=" . $apiKey,
];

foreach ($testCases as $label => $url) {
    echo "<h3>Probando: $label</h3>";
    $payload = [
        'contents' => [
            [
                'parts' => [
                    ['text' => 'Hola, responde con la palabra OK']
                ]
            ]
        ]
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Timeout de 3 segundos
    curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
    
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $duration = microtime(true) - $startTime;
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    echo "Duración: " . round($duration, 4) . " s<br>";
    if ($response === false) {
        echo "<span style='color:red;'>Falló: $curlError</span><br><br>";
    } else {
        echo "HTTP Code: <b>$httpCode</b><br>";
        echo "Respuesta: <pre>" . htmlspecialchars(substr($response, 0, 400)) . "</pre><br><br>";
    }
}
