<?php

declare(strict_types=1);

$filesToDelete = [
    __DIR__ . '/create_tables.php',
    __DIR__ . '/test_db_conn.php',
    __DIR__ . '/migrate_data.php',
    __DIR__ . '/../database/flota.db',
];

echo "<h2><b>Iniciando limpieza segura post-migración...</b></h2>";

foreach ($filesToDelete as $file) {
    if (file_exists($file)) {
        if (unlink($file)) {
            echo "Eliminado con éxito: " . htmlspecialchars(basename($file)) . "<br>";
        } else {
            echo "<span style='color:red;'>No se pudo eliminar:</span> " . htmlspecialchars(basename($file)) . "<br>";
        }
    } else {
        echo "No encontrado (ya limpio): " . htmlspecialchars(basename($file)) . "<br>";
    }
}

// Eliminar este propio archivo de limpieza al final
$self = __FILE__;
echo "Eliminando script de limpieza...<br>";
if (unlink($self)) {
    echo "<b>Limpieza finalizada con éxito. Servidor libre de scripts temporales.</b>";
} else {
    echo "<span style='color:red;'>Error al eliminar el script de limpieza. Por favor elimínelo manualmente.</span>";
}
