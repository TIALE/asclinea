<?php
require_once __DIR__ . '/src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/.env');

try {
    $pdo = DatabaseConnection::getConnection();

    // Añadir columna si no existe
    $stmt = $pdo->query("SHOW COLUMNS FROM tbc_Flota LIKE 'numero_serie'");
    if ($stmt->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbc_Flota ADD COLUMN numero_serie VARCHAR(50) DEFAULT NULL AFTER matricula");
        echo "Columna numero_serie agregada.\n";
    }

    // Datos proporcionados
    $data = [
        'XA-MLD' => '680A-0189',
        'XA-MLE' => '680A-0369',
        'XA-MLC' => '680A-0289',
        'XA-MLT' => '680A-0252',
        'XA-MLF' => '680A-0158',
        'XA-MLG' => '680A-0177',
        'XA-AGV' => '680A-0130',
        'XA-MLU' => '680A-0129',
        'XA-ALE' => '525B-0764',
        'XA-LEY' => '525B-0666',
        'XA-MCC' => '525B-0652',
        'XA-MCT' => '525B-0629',
        'XA-MCU' => '525B-0628',
        'XA-MBC' => '45-521',
        'XA-MBD' => '45-482',
        'XA-MBE' => '45-504',
        'XA-MBN' => '45-510',
        'XA-MBO' => '45-524',
        'XA-MBS' => '45-522',
        'XA-MBT' => '45-492',
        'XA-MBX' => '45-458',
        'XA-LPZ' => '5810',
        'XA-MXJ' => '5901',
        'XA-MJT' => '5853',
        'XA-NDY' => '5944'
    ];

    $updateStmt = $pdo->prepare("UPDATE tbc_Flota SET numero_serie = :ns WHERE matricula = :mat");
    foreach ($data as $mat => $ns) {
        $updateStmt->execute([':ns' => $ns, ':mat' => $mat]);
        echo "Actualizado: $mat -> $ns\n";
    }
    
    echo "Hecho.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
