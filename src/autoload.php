<?php

declare(strict_types=1);

/**
 * Autocargador PSR-4 Nativo
 * Mapea el espacio de nombres 'App\' hacia el directorio 'src/'.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/'; // Este archivo está en src/, por lo que __DIR__ es 'src/'

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return; // No pertenece a nuestro namespace
    }

    $relativeClass = substr($class, $len);
    
    // Reemplazar separadores de espacio de nombres con separadores de directorios
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
