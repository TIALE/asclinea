<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');

try {
    $pdo = DatabaseConnection::getConnection();
    echo "Conexión a MySQL exitosa.<br>";

    // 1. Crear tbc_Usuario (por si no existe)
    $sqlUsuario = "CREATE TABLE IF NOT EXISTS tbc_Usuario (
        id_usuario INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        correo VARCHAR(150) NOT NULL UNIQUE,
        google_sub VARCHAR(100) UNIQUE NULL,
        foto_url VARCHAR(255) NULL,
        es_activo TINYINT(1) DEFAULT 1,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlUsuario);
    echo "Tabla tbc_Usuario verificada/creada.<br>";

    // Semilla de usuarios iniciales
    $sqlSemilla = "INSERT INTO tbc_Usuario (nombre, correo, es_activo) VALUES 
        ('Leonardo Rodriguez', 'l.rodriguez@aleservicecenter.com', 1),
        ('Jetzrael López', 'admin@aleservicecenter.com', 1)
        ON DUPLICATE KEY UPDATE nombre=VALUES(nombre);";
    $pdo->exec($sqlSemilla);
    echo "Semilla de usuarios iniciales verificada/insertada.<br>";

    // 2. Crear tbc_Flota
    $sqlFlota = "CREATE TABLE IF NOT EXISTS tbc_Flota (
        id_flota INT AUTO_INCREMENT PRIMARY KEY,
        modelo VARCHAR(50) NOT NULL,
        matricula VARCHAR(50) NOT NULL UNIQUE,
        es_activo TINYINT(1) DEFAULT 1,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlFlota);
    echo "Tabla tbc_Flota verificada/creada.<br>";

    // Alterar tbc_Flota para añadir las columnas del reporte si no existen
    $columnasNuevas = [
        'estatus'                => "VARCHAR(50) DEFAULT 'OP'",
        'taller'                 => "VARCHAR(150) DEFAULT ''",
        'comentarios_relevantes' => "TEXT NULL",
        'estatus_motores'        => "VARCHAR(255) DEFAULT ''",
        'fecha_ingreso'          => "DATE NULL",
        'fecha_liberacion'       => "DATE NULL"
    ];

    foreach ($columnasNuevas as $col => $def) {
        $checkCol = $pdo->query("SHOW COLUMNS FROM tbc_Flota LIKE '$col'")->fetch();
        if (!$checkCol) {
            $pdo->exec("ALTER TABLE tbc_Flota ADD COLUMN $col $def");
            echo "Columna de Reporte '$col' agregada a tbc_Flota.<br>";
        }
    }

    // 3. Crear tbo_Falla
    $sqlFalla = "CREATE TABLE IF NOT EXISTS tbo_Falla (
        id_falla INT AUTO_INCREMENT PRIMARY KEY,
        modelo VARCHAR(50) NOT NULL,
        matricula VARCHAR(50) NOT NULL,
        ata VARCHAR(100) NOT NULL,
        condicion VARCHAR(10) NULL,
        folio VARCHAR(50) NULL,
        fecha DATE NOT NULL,
        mel VARCHAR(100) NULL,
        categoria_mel VARCHAR(255) NULL,
        descripcion TEXT NOT NULL,
        accion_correctiva TEXT NOT NULL,
        referencia VARCHAR(255) NULL,
        tips TEXT NULL,
        base VARCHAR(50) NULL,
        registrado_por VARCHAR(150) NOT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (matricula),
        INDEX (modelo),
        INDEX (ata)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlFalla);
    echo "Tabla tbo_Falla verificada/creada.<br>";

    // 4. Crear tbo_CarpetaUsuario
    $sqlCarpeta = "CREATE TABLE IF NOT EXISTS tbo_CarpetaUsuario (
        id_carpeta INT AUTO_INCREMENT PRIMARY KEY,
        usuario_nombre VARCHAR(150) NOT NULL,
        id_falla INT NOT NULL,
        fecha_guardado TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (id_falla) REFERENCES tbo_Falla(id_falla) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sqlCarpeta);
    echo "Tabla tbo_CarpetaUsuario verificada/creada.<br>";

    echo "<b>¡Inicialización de base de datos MySQL completada con éxito!</b>";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
