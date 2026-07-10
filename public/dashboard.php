<?php

declare(strict_types=1);

// 1. Forzar directivas de seguridad para evitar trazas
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// 2. Inicializar componentes
require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

// 3. Control de Acceso Estricto (OWASP Access Control)
if (!SessionManager::has('user_id')) {
    header('Location: index.php');
    exit;
}

// 4. Obtener variables de sesión previamente sanitizadas
$userName  = (string)SessionManager::get('user_name', 'Usuario');
$userEmail = (string)SessionManager::get('user_email', '');
$userFoto  = (string)SessionManager::get('user_foto', '');

// Si no hay foto, usar un avatar por defecto basado en iniciales
if (empty($userFoto)) {
    $userFoto = "https://ui-avatars.com/api/?name=" . urlencode($userName) . "&background=00f2fe&color=0d1117&bold=true";
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | AleSearchTool</title>
    <!-- Metas de seguridad y SEO -->
    <meta name="description" content="Dashboard de gestión de fallas y conocimiento técnico de flota.">
    <meta name="robots" content="noindex, nofollow"> <!-- No indexar páginas protegidas -->
    
    <!-- Fuentes modernas -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Estilos unificados -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-body">
    <!-- Contenedor del Layout Principal -->
    <div class="app-layout">
        <!-- BARRA LATERAL (Sidebar Navigation) -->
        <aside class="app-sidebar">
            <div class="sidebar-header">
                <!-- Logo SVG Premium de Turbina y Herramientas (AleSearchTool) -->
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" class="sidebar-logo-icon" style="width: 24px; height: 24px; fill: none;">
                    <defs>
                        <radialGradient id="side-metal-grad" cx="50%" cy="50%" r="50%">
                            <stop offset="0%" stop-color="#f0f3f8"/>
                            <stop offset="100%" stop-color="#7a8da5"/>
                        </radialGradient>
                    </defs>
                    <circle cx="50" cy="50" r="42" fill="none" stroke="currentColor" stroke-width="6" />
                    <circle cx="50" cy="50" r="38" fill="#101e3d" />
                    <!-- Aspas de la Turbina -->
                    <g stroke="currentColor" stroke-width="1" fill="url(#side-metal-grad)">
                        <path d="M50,50 L50,15 C54,15 56,25 50,50 Z" />
                        <path d="M50,50 L75,25 C78,28 72,36 50,50 Z" />
                        <path d="M50,50 L85,50 C85,54 75,56 50,50 Z" />
                        <path d="M50,50 L75,75 C72,78 64,72 50,50 Z" />
                        <path d="M50,50 L50,85 C46,85 44,75 50,50 Z" />
                        <path d="M50,50 L25,75 C22,72 28,64 50,50 Z" />
                        <path d="M50,50 L15,50 C15,46 25,44 50,50 Z" />
                        <path d="M50,50 L25,25 C28,22 36,28 50,50 Z" />
                    </g>
                    <circle cx="50" cy="50" r="10" fill="url(#side-metal-grad)" stroke="currentColor" stroke-width="1.5" />
                </svg>
                <span class="sidebar-logo-text">AleSearchTool</span>
            </div>
            
            <nav class="sidebar-nav">
                <ul>
                    <li class="nav-item active">
                        <a href="dashboard.php" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="nav-icon"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                            <span>Panel de Control</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="nav-icon"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                            <span>Gestión de Fallas</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="nav-icon"><path d="M4 19.5v-15A2.5 2.15 0 0 1 6.5 2H20v20H6.5a2.5 2.15 0 0 1-2.5-2.5Z"/><path d="M6 6h10M6 10h10"/></svg>
                            <span>Conocimiento Técnico</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="#" class="nav-link">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="nav-icon"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-1.1 0-2 .9-2 2v7c0 .6.4 1 1 1h3m12 0a3 3 0 1 1-6 0m6 0a3 3 0 1 0-6 0m-4 0h4m-4 0a3 3 0 1 1-6 0m6 0a3 3 0 1 0-6 0m-1 0H3"/></svg>
                            <span>Flota de Vehículos</span>
                        </a>
                    </li>
                </ul>
            </nav>

            <!-- PERFIL DE USUARIO EN SIDEBAR -->
            <div class="sidebar-user">
                <img src="<?php echo htmlspecialchars($userFoto, ENT_QUOTES, 'UTF-8'); ?>" alt="Avatar" class="user-avatar" referrerpolicy="no-referrer">
                <div class="user-info">
                    <span class="user-name"><?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="user-role">Personal Técnico</span>
                </div>
                <a href="index.php?action=logout" class="btn-logout-icon" title="Cerrar sesión">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="logout-icon">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                        <polyline points="16 17 21 12 16 7"/>
                        <line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                </a>
            </div>
        </aside>

        <!-- CONTENIDO PRINCIPAL -->
        <main class="app-content-wrapper">
            <!-- Barra Superior (Header) -->
            <header class="app-header">
                <div class="header-search">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="search-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" placeholder="Buscar fallas, manuales, vehículos..." class="search-input">
                </div>
                <div class="header-actions">
                    <div class="notification-trigger">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="header-action-icon"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                        <span class="badge">3</span>
                    </div>
                </div>
            </header>

            <!-- Panel de Contenido -->
            <div class="content-body">
                <!-- Banner de Bienvenida -->
                <section class="welcome-banner">
                    <div class="welcome-text">
                        <h2>¡Hola de nuevo, <?php echo htmlspecialchars(explode(' ', $userName)[0], ENT_QUOTES, 'UTF-8'); ?>! 👋</h2>
                        <p>Bienvenido al Panel de Control de <strong>AleSearchTool</strong>. Revisa las últimas fallas mecánicas reportadas y la base de conocimiento técnico.</p>
                    </div>
                    <div class="welcome-badge">
                        <span>Sesión Segura Activa</span>
                    </div>
                </section>

                <!-- Tarjetas de Estadísticas (KPIs) -->
                <section class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon-wrapper color-orange">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="stat-card-icon"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value">12</span>
                            <span class="stat-label">Fallas Pendientes</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-wrapper color-cyan">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="stat-card-icon"><path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-1.1 0-2 .9-2 2v7c0 .6.4 1 1 1h3m12 0a3 3 0 1 1-6 0m6 0a3 3 0 1 0-6 0m-4 0h4m-4 0a3 3 0 1 1-6 0m6 0a3 3 0 1 0-6 0m-1 0H3"/></svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value">45</span>
                            <span class="stat-label">Vehículos en Flota</span>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon-wrapper color-green">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="stat-card-icon"><path d="M4 19.5v-15A2.5 2.15 0 0 1 6.5 2H20v20H6.5a2.5 2.15 0 0 1-2.5-2.5Z"/></svg>
                        </div>
                        <div class="stat-info">
                            <span class="stat-value">128</span>
                            <span class="stat-label">Manuales Técnicos</span>
                        </div>
                    </div>
                </section>

                <!-- Secciones Auxiliares de Vista Rápida -->
                <div class="dashboard-details-grid">
                    <!-- Últimas Fallas Reportadas -->
                    <div class="details-card">
                        <div class="card-header">
                            <h3>Últimas Fallas de Flota</h3>
                            <a href="#" class="view-all-link">Ver todas</a>
                        </div>
                        <div class="card-content">
                            <ul class="activity-list">
                                <li class="activity-item">
                                    <span class="badge-status priority-high">Alta</span>
                                    <div class="activity-text">
                                        <strong>Falla de Frenos - Camión #04</strong>
                                        <p>Reportado por Juan Pérez • Hace 2 horas</p>
                                    </div>
                                </li>
                                <li class="activity-item">
                                    <span class="badge-status priority-medium">Media</span>
                                    <div class="activity-text">
                                        <strong>Fuga de Refrigerante - Pickup #12</strong>
                                        <p>Reportado por Carlos Gómez • Hace 5 horas</p>
                                    </div>
                                </li>
                                <li class="activity-item">
                                    <span class="badge-status priority-low">Baja</span>
                                    <div class="activity-text">
                                        <strong>Falla de Luces Traseras - Camión #09</strong>
                                        <p>Reportado por Luis Torres • Ayer</p>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Conocimiento Destacado -->
                    <div class="details-card">
                        <div class="card-header">
                            <h3>Conocimiento Técnico Reciente</h3>
                            <a href="#" class="view-all-link">Ir a Wiki</a>
                        </div>
                        <div class="card-content">
                            <ul class="knowledge-list">
                                <li class="knowledge-item">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="doc-icon"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <div class="knowledge-text">
                                        <strong>Manual de Diagnóstico OBD-II Escáner Pro</strong>
                                        <p>Actualizado por Ing. Jetzrael López • Hace 1 día</p>
                                    </div>
                                </li>
                                <li class="knowledge-item">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="doc-icon"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                    <div class="knowledge-text">
                                        <strong>Protocolo de Mantenimiento Preventivo de Transmisiones Allison</strong>
                                        <p>Creado por Leonardo Rodriguez • Hace 3 días</p>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
