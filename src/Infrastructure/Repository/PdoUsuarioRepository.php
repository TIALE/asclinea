<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Usuario;
use App\Domain\Repository\UsuarioRepositoryInterface;
use PDO;
use Exception;

/**
 * Implementación de acceso a base de datos de usuarios vía PDO MySQL.
 * Utiliza SQL parametrizado rigurosamente para anular vulnerabilidades de inyección.
 */
class PdoUsuarioRepository implements UsuarioRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca un usuario por su correo electrónico de forma parametrizada.
     */
    public function findByCorreo(string $correo): ?Usuario
    {
        // Auto-Sanación de la Base de Datos antes de realizar la consulta (Gatillo de Resiliencia)
        try {
            $checkCol = $this->pdo->query("SHOW COLUMNS FROM tbc_Usuario LIKE 'rol'")->fetch();
            if (!$checkCol) {
                // Agregar la columna 'rol'
                $this->pdo->exec("ALTER TABLE tbc_Usuario ADD COLUMN rol VARCHAR(50) NOT NULL DEFAULT 'Administrador'");
                // Asegurar privilegios de administrador para las cuentas principales
                $this->pdo->exec("UPDATE tbc_Usuario SET rol = 'Administrador' WHERE correo IN ('l.rodriguez@aleservicecenter.com', 'admin@aleservicecenter.com')");
            }
        } catch (\Throwable $colEx) {
            error_log("Error de auto-sanación en PdoUsuarioRepository (rol col): " . $colEx->getMessage());
        }

        $sql = "SELECT id_usuario, nombre, correo, google_sub, foto_url, es_activo, rol 
                FROM tbc_Usuario 
                WHERE correo = :correo 
                LIMIT 1";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':correo' => $correo]);
            $row = $stmt->fetch();

            if (!$row) {
                return null;
            }

            return new Usuario(
                (int)$row['id_usuario'],
                $row['nombre'],
                $row['correo'],
                $row['google_sub'],
                $row['foto_url'],
                (int)$row['es_activo'] === 1,
                (string)($row['rol'] ?? 'Administrador')
            );
        } catch (Exception $e) {
            error_log("Error en PdoUsuarioRepository::findByCorreo: " . $e->getMessage());
            throw new Exception("Error al consultar el usuario.");
        }
    }

    /**
     * Guarda o actualiza un usuario mediante sentencias SQL preparadas nativas.
     */
    public function save(Usuario $usuario): void
    {
        try {
            if ($usuario->getId() === null) {
                // Registro nuevo
                $sql = "INSERT INTO tbc_Usuario (nombre, correo, google_sub, foto_url, es_activo, rol) 
                        VALUES (:nombre, :correo, :google_sub, :foto_url, :es_activo, :rol)";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':nombre'     => $usuario->getNombre(),
                    ':correo'     => $usuario->getCorreo(),
                    ':google_sub' => $usuario->getGoogleSub(),
                    ':foto_url'   => $usuario->getFotoUrl(),
                    ':es_activo'  => $usuario->esActivo() ? 1 : 0,
                    ':rol'        => $usuario->getRol(),
                ]);
            } else {
                // Actualización de registro existente (ej: enlazar Google sub o actualizar foto)
                $sql = "UPDATE tbc_Usuario 
                        SET nombre = :nombre, 
                            google_sub = :google_sub, 
                            foto_url = :foto_url, 
                            es_activo = :es_activo,
                            rol = :rol 
                        WHERE id_usuario = :id";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([
                    ':id'         => $usuario->getId(),
                    ':nombre'     => $usuario->getNombre(),
                    ':google_sub' => $usuario->getGoogleSub(),
                    ':foto_url'   => $usuario->getFotoUrl(),
                    ':es_activo'  => $usuario->esActivo() ? 1 : 0,
                    ':rol'        => $usuario->getRol(),
                ]);
            }
        } catch (Exception $e) {
            error_log("Error en PdoUsuarioRepository::save: " . $e->getMessage());
            throw new Exception("Error al guardar los datos del usuario.");
        }
    }
}
