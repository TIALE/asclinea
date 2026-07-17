<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

// Aumentar límites para el proceso de migración de datos pesados
set_time_limit(300);
ini_set('memory_limit', '256M');

try {
    $mysqlPdo = DatabaseConnection::getConnection();
    echo "Conexión a MySQL exitosa para migración.<br>";

    $sqlitePath = __DIR__ . '/../database/flota.db';
    if (!file_exists($sqlitePath)) {
        throw new Exception("El archivo SQLite flota.db no existe en la ruta: " . $sqlitePath);
    }

    $sqlitePdo = new PDO('sqlite:' . $sqlitePath);
    $sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "Conexión a SQLite flota.db exitosa.<br><br>";

    // Deshabilitar temporalmente checks de claves foráneas para importación limpia
    $mysqlPdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    
    // Limpiar tablas para evitar duplicados en migración limpia inicial
    $mysqlPdo->exec("TRUNCATE TABLE tbo_CarpetaUsuario;");
    $mysqlPdo->exec("TRUNCATE TABLE tbo_Falla;");
    $mysqlPdo->exec("TRUNCATE TABLE tbc_Flota;");
    echo "Tablas MySQL limpiadas para migración fresca.<br><br>";

    // ==========================================
    // 1. MIGRAR FLOTAS -> tbc_Flota
    // ==========================================
    echo "Iniciando migración de flotas...<br>";
    $stmtFlotas = $sqlitePdo->query("SELECT id, modelo, matricula, estado FROM flotas");
    $flotas = $stmtFlotas->fetchAll(PDO::FETCH_ASSOC);
    
    $sqlInsertFlota = "INSERT INTO tbc_Flota (id_flota, modelo, matricula, es_activo) VALUES (:id, :modelo, :matricula, :activo)";
    $stmtInsertFlota = $mysqlPdo->prepare($sqlInsertFlota);
    
    $flotasCount = 0;
    foreach ($flotas as $f) {
        $stmtInsertFlota->execute([
            ':id'        => (int)$f['id'],
            ':modelo'    => trim((string)$f['modelo']),
            ':matricula' => trim((string)$f['matricula']),
            ':activo'    => strtolower(trim((string)$f['estado'])) === 'activo' ? 1 : 0
        ]);
        $flotasCount++;
    }
    echo "Se migraron {$flotasCount} registros de flotas con éxito.<br><br>";

    // ==========================================
    // 2. MIGRAR FALLAS -> tbo_Falla
    // ==========================================
    echo "Iniciando migración de fallas (este proceso puede demorar)...<br>";
    $stmtFallas = $sqlitePdo->query("SELECT * FROM fallas");
    $fallas = $stmtFallas->fetchAll(PDO::FETCH_ASSOC);

    $sqlInsertFalla = "INSERT INTO tbo_Falla (
        id_falla, modelo, matricula, ata, condicion, folio, fecha, mel, categoria_mel, 
        descripcion, accion_correctiva, referencia, tips, base, registrado_por
    ) VALUES (
        :id, :modelo, :matricula, :ata, :condicion, :folio, :fecha, :mel, :categoria_mel, 
        :descripcion, :accion_correctiva, :referencia, :tips, :base, :registrado_por
    )";
    $stmtInsertFalla = $mysqlPdo->prepare($sqlInsertFalla);

    $fallasCount = 0;
    foreach ($fallas as $fa) {
        // Normalizar fecha a formato YYYY-MM-DD
        $fechaRaw = trim((string)$fa['fecha']);
        $fechaObj = null;
        if (!empty($fechaRaw)) {
            // Reemplazar diagonales por guiones
            $fechaRawClean = str_replace('/', '-', $fechaRaw);
            // Intentar parsear d-m-Y o Y-m-d
            $parts = explode('-', $fechaRawClean);
            if (count($parts) === 3) {
                if (strlen($parts[0]) === 4) {
                    $fechaObj = "{$parts[0]}-{$parts[1]}-{$parts[2]}";
                } else {
                    $fechaObj = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
            }
        }
        
        if (!$fechaObj) {
            $fechaObj = date('Y-m-d'); // Fallback seguro
        }

        $stmtInsertFalla->execute([
            ':id'                => (int)$fa['id'],
            ':modelo'            => trim((string)$fa['modelo']),
            ':matricula'         => trim((string)$fa['matricula']),
            ':ata'               => trim((string)$fa['ata']),
            ':condicion'         => !empty($fa['condicion']) ? trim((string)$fa['condicion']) : null,
            ':folio'             => !empty($fa['folio']) ? trim((string)$fa['folio']) : null,
            ':fecha'             => $fechaObj,
            ':mel'               => !empty($fa['mel']) ? trim((string)$fa['mel']) : null,
            ':categoria_mel'     => !empty($fa['categoria_mel']) ? trim((string)$fa['categoria_mel']) : null,
            ':descripcion'       => trim((string)$fa['descripcion']),
            ':accion_correctiva' => trim((string)$fa['accion_correctiva']),
            ':referencia'        => !empty($fa['referencia']) ? trim((string)$fa['referencia']) : null,
            ':tips'              => !empty($fa['tips']) ? trim((string)$fa['tips']) : null,
            ':base'              => !empty($fa['base']) ? trim((string)$fa['base']) : null,
            ':registrado_por'    => !empty($fa['registrado_por']) ? trim((string)$fa['registrado_por']) : 'Sistema'
        ]);
        $fallasCount++;
    }
    echo "Se migraron {$fallasCount} registros de fallas con éxito.<br><br>";

    // ==========================================
    // 3. MIGRAR CARPETAS_USUARIOS -> tbo_CarpetaUsuario
    // ==========================================
    echo "Iniciando migración de bookmarks de usuarios...<br>";
    $stmtCarpetas = $sqlitePdo->query("SELECT id, usuario_nombre, falla_id, fecha_guardado FROM carpetas_usuarios");
    $carpetas = $stmtCarpetas->fetchAll(PDO::FETCH_ASSOC);

    $sqlInsertCarpeta = "INSERT INTO tbo_CarpetaUsuario (id_carpeta, usuario_nombre, id_falla, fecha_guardado) VALUES (:id, :usuario_nombre, :id_falla, :fecha_guardado)";
    $stmtInsertCarpeta = $mysqlPdo->prepare($sqlInsertCarpeta);

    $carpetasCount = 0;
    foreach ($carpetas as $c) {
        $stmtInsertCarpeta->execute([
            ':id'             => (int)$c['id'],
            ':usuario_nombre' => trim((string)$c['usuario_nombre']),
            ':id_falla'       => (int)$c['falla_id'],
            ':fecha_guardado' => !empty($c['fecha_guardado']) ? trim((string)$c['fecha_guardado']) : date('Y-m-d H:i:s')
        ]);
        $carpetasCount++;
    }
    echo "Se migraron {$carpetasCount} registros de marcadores de carpetas con éxito.<br><br>";

    // Rehabilitar checks de claves foráneas
    $mysqlPdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    echo "<h2><b>¡MIGRACIÓN DE DATOS COMPLETADA CON ÉXITO AL 100%!</b></h2>";
    echo "Resumen:<br>";
    echo "- Flotas: {$flotasCount}<br>";
    echo "- Fallas: {$fallasCount}<br>";
    echo "- Marcadores de Carpeta: {$carpetasCount}<br>";

} catch (\Exception $e) {
    echo "<h2 style='color:red;'>Error de Migración:</h2> " . $e->getMessage();
}
