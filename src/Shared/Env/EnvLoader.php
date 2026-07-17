<?php

declare(strict_types=1);

namespace App\Shared\Env;

/**
 * Utilidad para cargar variables de entorno desde un archivo .env
 * de forma nativa, segura y sin dependencias externas.
 */
class EnvLoader
{
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            
            // Ignorar líneas vacías o comentarios
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Separar por el primer signo de igualdad '='
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);

                // Remover comillas dobles o simples si existen
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                // Inyectar en el entorno de PHP
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        // Cargar variables encriptadas/seguras si existen
        try {
            $secureVars = \App\Shared\Config\SecureConfig::decrypt();
            foreach ($secureVars as $key => $value) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        } catch (\Exception $e) {}
    }
}
