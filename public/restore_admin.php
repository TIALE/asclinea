<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Infrastructure\Database\DatabaseConnection;
use App\Shared\Session\SessionManager;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

try {
    $pdo = DatabaseConnection::getConnection();
    
    // 1. Asegurar que la columna 'rol' exista en la tabla tbc_Usuario
    $checkCol = $pdo->query("SHOW COLUMNS FROM tbc_Usuario LIKE 'rol'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE tbc_Usuario ADD COLUMN rol VARCHAR(50) NOT NULL DEFAULT 'Administrador'");
    }
    
    // 2. Actualizar/Forzar el rol a "Administrador" para las cuentas de administrador principales
    $pdo->exec("UPDATE tbc_Usuario SET rol = 'Administrador' WHERE correo IN ('l.rodriguez@aleservicecenter.com', 'admin@aleservicecenter.com')");
    
    // 3. Sincronizar el rol en la sesión del usuario actual inmediatamente
    $userEmail = SessionManager::get('user_email', '');
    if (!empty($userEmail)) {
        $stmt = $pdo->prepare("SELECT rol FROM tbc_Usuario WHERE correo = :correo");
        $stmt->execute([':correo' => $userEmail]);
        $newRole = $stmt->fetchColumn();
        if ($newRole) {
            SessionManager::set('user_role', $newRole);
        }
    }
    
    echo "
    <div style='font-family: sans-serif; max-width: 600px; margin: 50px auto; padding: 30px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); background-color: #ffffff; text-align: center;'>
        <div style='color: #1a419c; font-size: 48px; margin-bottom: 20px;'>
            <i class='fas fa-user-shield'>✓</i>
        </div>
        <h2 style='color: #1a419c; margin-top: 0;'>¡Acceso Restaurado con Éxito!</h2>
        <p style='color: #475569; font-size: 15px; line-height: 1.6; margin-bottom: 25px;'>
            Se ha actualizado su rol a <strong>Administrador</strong> tanto en el catálogo de base de datos como en su sesión activa actual de forma segura.
        </p>
        <a href='dashboard.php' style='display: inline-block; padding: 12px 24px; background-color: #1a419c; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 14px; transition: background-color 0.2s;'>
            Regresar al Dashboard
        </a>
    </div>
    ";
} catch (\Exception $e) {
    echo "
    <div style='font-family: sans-serif; max-width: 600px; margin: 50px auto; padding: 30px; border-radius: 12px; border: 1px solid #fee2e2; background-color: #fef2f2; text-align: center;'>
        <h2 style='color: #991b1b; margin-top: 0;'>Error de Recuperación</h2>
        <p style='color: #7f1d1d;'>" . htmlspecialchars($e->getMessage()) . "</p>
    </div>
    ";
}
