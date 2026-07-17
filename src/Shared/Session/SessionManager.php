<?php

declare(strict_types=1);

namespace App\Shared\Session;

/**
 * Gestor Seguro de Sesiones (OWASP Compliance)
 * Centraliza e implementa configuraciones de endurecimiento para evitar
 * Session Hijacking, Session Fixation y Cross-Site Request Forgery (CSRF).
 */
class SessionManager
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Hostinger / Shared Hosting Fix: Configurar ruta de guardado de sesión local y escribible
        $sessionDir = __DIR__ . '/../../../database/sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0777, true);
        }
        if (is_dir($sessionDir) && is_writable($sessionDir)) {
            ini_set('session.save_path', $sessionDir);
        }

        // Detectar dinámicamente si el protocolo es HTTPS
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                    || ($_SERVER['SERVER_PORT'] ?? '') === '443';

        // Configuración estricta de cookies de sesión
        session_set_cookie_params([
            'lifetime' => 0,                      // Expira al cerrar el navegador
            'path'     => '/',                     // Disponible para toda la aplicación
            'domain'   => '',                      // Dominio actual
            'secure'   => $isSecure,               // OBLIGATORIO en producción: Solo transmitir sobre HTTPS
            'httponly' => true,                   // OBLIGATORIO: Ocultar cookie a Javascript (Previene XSS)
            'samesite' => 'Lax'                    // OBLIGATORIO para OAuth: Permite conservar sesión tras retorno de Google
        ]);

        // Evitar el rastreo de sesión en URLs
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_start();
    }

    /**
     * Regenera el ID de sesión. Debe ejecutarse inmediatamente tras el login exitoso
     * para contrarrestar ataques de Fijación de Sesión (Session Fixation).
     */
    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true); // Elimina el archivo de sesión antiguo y crea uno nuevo con ID aleatorio
    }

    /**
     * Guarda un valor en la sesión.
     */
    public static function set(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    /**
     * Obtiene un valor de la sesión.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Comprueba la existencia de una clave en la sesión.
     */
    public static function has(string $key): bool
    {
        self::start();
        return isset($_SESSION[$key]);
    }
 
    /**
     * Elimina una clave de la sesión de forma segura.
     */
    public static function remove(string $key): void
    {
        self::start();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Cierra y destruye la sesión eliminando cookies en el cliente y archivos en servidor.
     */
    public static function destroy(): void
    {
        self::start();

        // 1. Vaciar el arreglo de sesión
        $_SESSION = [];

        // 2. Invalidar la cookie de sesión en el cliente
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        // 3. Destruir la sesión en el servidor
        session_destroy();
    }
}
