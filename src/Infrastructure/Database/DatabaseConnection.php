<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use PDOException;
use Exception;

/**
 * Conexión Segura a la Base de Datos (Patrón Singleton)
 * Implementa PDO con mitigación total de SQL Injection y ocultamiento de trazas de error.
 */
class DatabaseConnection
{
    private static ?PDO $instance = null;

    // Impedir instanciación externa
    private function __construct() {}

    /**
     * Retorna la conexión activa de PDO.
     * 
     * @throws Exception Si falla la conexión, ocultando detalles técnicos sensibles.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_NAME') ?: '';
            $user = getenv('DB_USER') ?: '';
            $pass = getenv('DB_PASS') ?: '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lanzar excepciones de PDO
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Array asociativo por defecto
                PDO::ATTR_EMULATE_PREPARES   => false,                  // Consultas preparadas NATIVAS (Previene SQLi)
            ];

            try {
                self::$instance = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // OWASP: Registrar el error técnico en el log interno del servidor
                error_log("FALLO DE CONEXIÓN BD: " . $e->getMessage());
                
                // Retornar un error genérico y seguro al usuario
                throw new Exception("Error interno del servidor. No se pudo establecer la conexión a la base de datos.");
            }
        }

        return self::$instance;
    }
}
