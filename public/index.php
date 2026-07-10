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
    
    <!-- Estilos Premium -->
    <link rel="stylesheet" href="assets/css/style.css">
    
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
                <!-- Icono SVG Premium Personalizado de Turbina y Herramientas (AleSearchTool) -->
                <div class="brand-logo">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" class="fleet-icon" style="width: 80px; height: 80px;">
                        <!-- Defs para Gradientes Metálicos y de Herramientas -->
                        <defs>
                            <radialGradient id="metal-grad" cx="50%" cy="50%" r="50%">
                                <stop offset="0%" stop-color="#f0f3f8"/>
                                <stop offset="50%" stop-color="#b8c4d9"/>
                                <stop offset="100%" stop-color="#7a8da5"/>
                            </radialGradient>
                            <linearGradient id="wrench-grad" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#ffffff"/>
                                <stop offset="100%" stop-color="#808e9b"/>
                            </linearGradient>
                            <linearGradient id="handle-grad" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%" stop-color="#e74c3c"/>
                                <stop offset="50%" stop-color="#c0392b"/>
                                <stop offset="100%" stop-color="#962d22"/>
                            </linearGradient>
                        </defs>
                        
                        <!-- Círculo Exterior / Carcasa de la Turbina -->
                        <circle cx="50" cy="50" r="42" fill="none" stroke="url(#metal-grad)" stroke-width="5" />
                        <circle cx="50" cy="50" r="38" fill="#101e3d" stroke="#2c3e50" stroke-width="1" />
                        
                        <!-- Aspas de la Turbina de Turborreactor (Giradas radialmente) -->
                        <g stroke="#2c3e50" stroke-width="0.5" fill="url(#metal-grad)">
                            <path d="M50,50 L50,15 C54,15 56,25 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L75,25 C78,28 72,36 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L85,50 C85,54 75,56 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L75,75 C72,78 64,72 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L50,85 C46,85 44,75 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L25,75 C22,72 28,64 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L15,50 C15,46 25,44 50,50 Z" opacity="0.9" />
                            <path d="M50,50 L25,25 C28,22 36,28 50,50 Z" opacity="0.9" />
                        </g>
                        
                        <!-- Núcleo de la Turbina -->
                        <circle cx="50" cy="50" r="10" fill="url(#metal-grad)" stroke="#1a252f" stroke-width="1.5" />
                        <circle cx="50" cy="50" r="4" fill="#1a252f" />
                        
                        <!-- Herramientas Cruzadas en Frente -->
                        <g>
                            <!-- Llave Inglesa Cruzada (De abajo-izquierda a arriba-derecha) -->
                            <g transform="translate(50,50) rotate(-45) translate(-50,-50)">
                                <!-- Mango de la llave -->
                                <rect x="47" y="20" width="6" height="60" rx="2" fill="url(#wrench-grad)" stroke="#57606f" stroke-width="1"/>
                                <!-- Cabeza abierta superior -->
                                <circle cx="50" cy="20" r="9" fill="url(#wrench-grad)" stroke="#57606f" stroke-width="1"/>
                                <polygon points="45,11 55,11 55,21 45,21" fill="#101e3d"/>
                                <!-- Cabeza cerrada inferior -->
                                <circle cx="50" cy="80" r="7" fill="url(#wrench-grad)" stroke="#57606f" stroke-width="1"/>
                                <circle cx="50" cy="80" r="3.5" fill="#101e3d"/>
                            </g>
                            
                            <!-- Destornillador Cruzado (De abajo-derecha a arriba-izquierda) -->
                            <g transform="translate(50,50) rotate(45) translate(-50,-50)">
                                <!-- Barra metálica -->
                                <rect x="48.5" y="15" width="3" height="45" fill="url(#wrench-grad)" stroke="#57606f" stroke-width="0.5"/>
                                <!-- Punta plana -->
                                <polygon points="47,15 53,15 51,11 49,11" fill="url(#wrench-grad)"/>
                                <!-- Mango del destornillador -->
                                <rect x="45" y="55" width="10" height="30" rx="3" fill="url(#handle-grad)" stroke="#78281f" stroke-width="1"/>
                                <rect x="48" y="55" width="4" height="30" fill="#2c3e50" opacity="0.3"/> <!-- Agarre de goma -->
                            </g>
                        </g>
                    </svg>
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
