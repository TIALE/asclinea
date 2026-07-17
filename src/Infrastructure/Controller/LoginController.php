<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Application\UseCase\VerifyGoogleLogin;
use App\Shared\Session\SessionManager;
use Exception;

/**
 * Controlador de Autenticación
 * Procesa la redirección POST enviada de manera segura por Google Identity Services (GSI).
 */
class LoginController
{
    private VerifyGoogleLogin $verifyGoogleLogin;

    public function __construct(VerifyGoogleLogin $verifyGoogleLogin)
    {
        $this->verifyGoogleLogin = $verifyGoogleLogin;
    }

    /**
     * Procesa la solicitud POST y gestiona la autenticación.
     */
    public function handle(): void
    {
        // 1. Validar estrictamente el método de petición (OWASP: Restringir Verbo HTTP)
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            header('Location: index.php');
            exit;
        }

        // Obtener la credencial JWT enviada por Google
        $idToken = $_POST['credential'] ?? '';

        try {
            if (empty($idToken)) {
                throw new Exception("Credencial de Google ausente o vacía.");
            }

            // 2. Ejecutar caso de uso de Dominio y Aplicación
            $usuario = $this->verifyGoogleLogin->execute($idToken);

            // 3. Inicio de Sesión Seguro (Mitigación de Session Fixation)
            SessionManager::regenerate(); // Regeneración inmediata del identificador
            
            // Inyectar datos autorizados en la sesión segura del servidor
            SessionManager::set('user_id', $usuario->getId());
            SessionManager::set('user_name', $usuario->getNombre());
            SessionManager::set('user_email', $usuario->getCorreo());
            SessionManager::set('user_foto', $usuario->getFotoUrl());
            SessionManager::set('user_role', $usuario->getRol());
            
            // Limpiar errores previos si los hubiera
            if (SessionManager::has('login_error')) {
                unset($_SESSION['login_error']);
            }

            // Redirección segura interna
            header('Location: dashboard.php');
            exit;

        } catch (Exception $e) {
            // OWASP: Registrar el mensaje de depuración técnica internamente
            error_log("FALLO DE LOGIN (Google OAuth): " . $e->getMessage());

            // Proveer mensaje controlado a la vista
            SessionManager::start();
            SessionManager::set('login_error', $e->getMessage());

            // Redirección segura interna de vuelta al index de login
            header('Location: index.php');
            exit;
        }
    }
}
