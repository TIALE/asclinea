<?php

declare(strict_types=1);

namespace App\Application\UseCase;

use App\Domain\Entity\Usuario;
use App\Domain\Repository\UsuarioRepositoryInterface;
use App\Infrastructure\Google\GoogleTokenVerifier;
use Exception;

/**
 * Caso de Uso: Verificar Login con Google
 * Contiene la lógica de negocio para autorizar el acceso de un usuario
 * basándose en su autenticación con Google y su estado en el catálogo local.
 */
class VerifyGoogleLogin
{
    private UsuarioRepositoryInterface $usuarioRepository;
    private GoogleTokenVerifier $tokenVerifier;

    public function __construct(
        UsuarioRepositoryInterface $usuarioRepository,
        GoogleTokenVerifier $tokenVerifier
    ) {
        $this->usuarioRepository = $usuarioRepository;
        $this->tokenVerifier = $tokenVerifier;
    }

    /**
     * Ejecuta el proceso de autenticación y autorización.
     * 
     * @param string $idToken Token JWT proporcionado por Google Identity Services.
     * @return Usuario La entidad del usuario autorizado para inicializar sesión.
     * @throws Exception Si el token es inválido, el correo no está registrado o el usuario está inactivo.
     */
    public function execute(string $idToken): Usuario
    {
        // 1. Validar técnicamente el token contra Google
        $googleData = $this->tokenVerifier->verify($idToken);
        $correo = strtolower(trim($googleData['correo']));

        // 2. Buscar si el correo se encuentra registrado en el catálogo oficial (tbc_Usuario)
        $usuario = $this->usuarioRepository->findByCorreo($correo);

        if ($usuario === null) {
            error_log("ACCESO DENEGADO (No registrado): El correo '{$correo}' intentó acceder.");
            throw new Exception("La cuenta '{$correo}' no está registrada en el sistema de flota.");
        }

        // 3. Verificar si el usuario tiene permiso de acceso activo
        if (!$usuario->esActivo()) {
            error_log("ACCESO DENEGADO (Inactivo): El usuario '{$correo}' está registrado pero se encuentra INACTIVO.");
            throw new Exception("Su acceso al sistema ha sido suspendido. Por favor, contacte a soporte técnico.");
        }

        // 4. Sincronización automática de Google Sub ID y Foto de Perfil
        $actualizarDb = false;

        // Si es el primer login del usuario, enlazamos su ID de Google permanente de forma segura
        if ($usuario->getGoogleSub() === null || $usuario->getGoogleSub() !== $googleData['google_sub']) {
            $usuario->enlazarGoogleSub($googleData['google_sub']);
            $actualizarDb = true;
        }

        // Sincronizar foto de perfil si ha cambiado en Google
        if ($usuario->getFotoUrl() !== $googleData['foto']) {
            $usuario->actualizarFotoUrl($googleData['foto']);
            $actualizarDb = true;
        }

        // Guardar cambios en el repositorio si hubo sincronización
        if ($actualizarDb) {
            $this->usuarioRepository->save($usuario);
        }

        return $usuario;
    }
}
