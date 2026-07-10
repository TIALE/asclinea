<?php

declare(strict_types=1);

namespace App\Infrastructure\Controller;

use App\Shared\Session\SessionManager;

/**
 * Controlador de Cierre de Sesión
 * Limpia y destruye de manera absoluta la sesión del usuario y sus cookies.
 */
class LogoutController
{
    /**
     * Cierra la sesión activa y redirige al usuario a la página de login.
     */
    public function handle(): void
    {
        // Destrucción absoluta de la sesión en el servidor y de la cookie en el cliente (OWASP)
        SessionManager::destroy();

        // Redirigir de forma segura al index
        header('Location: index.php');
        exit;
    }
}
