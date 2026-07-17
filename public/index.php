<?php

declare(strict_types=1);

// 1. Configuración de Seguridad de Errores (OWASP: Impedir fugas de información)
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// 2. Cargar cargador y autoloader
require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;
use App\Infrastructure\Database\DatabaseConnection;
use App\Infrastructure\Repository\PdoUsuarioRepository;
use App\Infrastructure\Google\GoogleTokenVerifier;
use App\Application\UseCase\VerifyGoogleLogin;
use App\Infrastructure\Controller\LoginController;
use App\Infrastructure\Controller\LogoutController;

// 3. Cargar variables de entorno
EnvLoader::load(__DIR__ . '/../.env');

// 4. Iniciar manejo seguro de sesiones
SessionManager::start();

// 5. Construir URI de redirección dinámica de forma segura para Google OAuth
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'];
$script = $_SERVER['SCRIPT_NAME'];
$redirectUri = $protocol . $host . $script . "?action=login";

// 6. Enrutador Simple (Front Controller)
$action = $_GET['action'] ?? '';

if ($action === 'login') {
    try {
        $pdo = DatabaseConnection::getConnection();
        $repo = new PdoUsuarioRepository($pdo);
        // Client ID de Google OAuth provisto por el usuario
        $clientId = '537345327320-04e8697662hqfk48ftqpnrraus2i1v92.apps.googleusercontent.com';
        $verifier = new GoogleTokenVerifier($clientId);
        $useCase = new VerifyGoogleLogin($repo, $verifier);
        
        $controller = new LoginController($useCase);
        $controller->handle();
    } catch (\Exception $e) {
        error_log("Error ruteando login: " . $e->getMessage());
        SessionManager::set('login_error', "No se pudo completar el inicio de sesión. Inténtelo más tarde.");
        header('Location: index.php');
        exit;
    }
}

if ($action === 'logout') {
    $controller = new LogoutController();
    $controller->handle();
}

// Si el usuario ya está autenticado, redirigir directo al dashboard protegido
if (SessionManager::has('user_id')) {
    header('Location: dashboard.php');
    exit;
}

// Extraer y limpiar errores de login previos de forma segura (OWASP)
$loginError = '';
if (SessionManager::has('login_error')) {
    $loginError = (string)SessionManager::get('login_error');
    unset($_SESSION['login_error']); // Consumir el error una sola vez (flash alert)
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso | AleSearchTool</title>
    <!-- Metas de SEO y Seguridad -->
    <meta name="description" content="Acceso seguro al sistema AleSearchTool - Gestión de Fallas y Conocimiento Técnico de Flota.">
    
    <!-- Fuentes de Google Modernas -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;800&family=Plus+Jakarta+Sans:wght@300;400;500;700&display=swap" rel="stylesheet">
    
    <!-- Estilos Premium con Cache Busting Dinámico -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    
    <!-- Script Oficial de Google Identity Services (GSI) -->
    <script src="https://accounts.google.com/gsi/client" async defer></script>
</head>
<body class="login-body">
    <!-- Fondos con Orbes de Luz Animados -->
    <div class="glow-orb-container">
        <div class="glow-orb orb-primary"></div>
        <div class="glow-orb orb-secondary"></div>
    </div>

    <main class="login-wrapper">
        <div class="login-card">
            <!-- Encabezado de la Tarjeta -->
            <div class="login-header">
                <!-- Logotipo Corporativo Oficial de Alta Fidelidad -->
                <div class="brand-logo">
                    <img src="assets/images/logo_menu.png" alt="AleSearchTool Logo" style="width: 100px; height: auto; border-radius: 12px; box-shadow: var(--shadow-sm); transition: var(--transition-smooth);">
                </div>
                <h1 class="brand-title">AleSearchTool</h1>
                <p class="brand-subtitle">Gestión de Fallas y Conocimiento de Flota</p>
            </div>

            <!-- Contenido de la Tarjeta -->
            <div class="login-content">
                <p class="login-instruction">Inicia sesión con tu cuenta corporativa para acceder a la base de conocimiento técnico.</p>

                <!-- Alertas de Error Sanitizadas de forma absoluta para evitar XSS -->
                <?php if (!empty($loginError)): ?>
                    <div class="alert-danger" id="error-alert">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="alert-icon">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="8" x2="12" y2="12"/>
                            <line x1="12" y1="16" x2="12.01" y2="16"/>
                        </svg>
                        <span><?php echo htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Botón Oficial de Google Identity Services -->
                <div class="oauth-button-container">
                    <!-- Configuración Declarativa Segura (Modo Redirect/POST a index.php?action=login) -->
                    <div id="g_id_onload"
                        data-client_id="537345327320-04e8697662hqfk48ftqpnrraus2i1v92.apps.googleusercontent.com"
                        data-context="signin"
                        data-ux_mode="redirect"
                        data-login_uri="<?php echo htmlspecialchars($redirectUri, ENT_QUOTES, 'UTF-8'); ?>"
                        data-auto_prompt="false">
                    </div>

                    <!-- Renderizado de Botón Estándar Estilizado -->
                    <div class="g_id_signin"
                        data-type="standard"
                        data-shape="pill"
                        data-theme="filled_blue"
                        data-text="signin_with"
                        data-size="large"
                        data-logo_alignment="left"
                        data-width="280">
                    </div>
                </div>
            </div>

            <!-- Footer de la Tarjeta -->
            <div class="login-footer">
                <p>&copy; <?php echo date('Y'); ?> ALE Service Center. Todos los derechos reservados.</p>
                <span class="security-badge">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="shield-icon">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg> Conexión SSL Segura
                </span>
            </div>
        </div>
    </main>
</body>
</html>
