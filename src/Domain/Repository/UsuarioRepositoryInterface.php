<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Usuario;

/**
 * Interfaz de Repositorio para Usuario
 * Define el contrato abstracto para interactuar con los datos de usuario.
 */
interface UsuarioRepositoryInterface
{
    /**
     * Busca un usuario registrado por su correo electrónico.
     */
    public function findByCorreo(string $correo): ?Usuario;

    /**
     * Guarda o actualiza los datos del usuario en la base de datos.
     */
    public function save(Usuario $usuario): void;
}
