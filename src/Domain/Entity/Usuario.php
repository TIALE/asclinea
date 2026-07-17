<?php

declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * Entidad de Dominio: Usuario
 * Representa a un usuario registrado en el catálogo del sistema.
 */
class Usuario
{
    private ?int $id;
    private string $nombre;
    private string $correo;
    private ?string $googleSub;
    private ?string $fotoUrl;
    private bool $esActivo;
    private string $rol;

    public function __construct(
        ?int $id,
        string $nombre,
        string $correo,
        ?string $googleSub = null,
        ?string $fotoUrl = null,
        bool $esActivo = true,
        string $rol = 'Administrador'
    ) {
        $this->id = $id;
        $this->nombre = $nombre;
        $this->correo = $correo;
        $this->googleSub = $googleSub;
        $this->fotoUrl = $fotoUrl;
        $this->esActivo = $esActivo;
        $this->rol = $rol;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNombre(): string
    {
        return $this->nombre;
    }

    public function getCorreo(): string
    {
        return $this->correo;
    }

    public function getGoogleSub(): ?string
    {
        return $this->googleSub;
    }

    public function getFotoUrl(): ?string
    {
        return $this->fotoUrl;
    }

    public function esActivo(): bool
    {
        return $this->esActivo;
    }

    public function getRol(): string
    {
        return $this->rol;
    }

    public function asignarRol(string $rol): void
    {
        $this->rol = $rol;
    }

    /**
     * Permite enlazar de forma tardía el Google Sub ID único.
     */
    public function enlazarGoogleSub(string $googleSub): void
    {
        $this->googleSub = $googleSub;
    }

    /**
     * Permite actualizar la foto de perfil proveniente de Google.
     */
    public function actualizarFotoUrl(?string $fotoUrl): void
    {
        $this->fotoUrl = $fotoUrl;
    }
}
