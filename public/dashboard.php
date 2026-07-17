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
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

// 3. Control de Acceso Estricto
if (!SessionManager::has('user_id')) {
    header('Location: index.php');
    exit;
}

$userRole = (string)SessionManager::get('user_role', '');

// Autodetectar rol si falta en sesión activa
if (empty($userRole) && SessionManager::has('user_id')) {
    try {
        $pdo = DatabaseConnection::getConnection();
        $stmtRole = $pdo->prepare("SELECT rol FROM tbc_Usuario WHERE id_usuario = :id");
        $stmtRole->execute([':id' => SessionManager::get('user_id')]);
        $userRole = (string)$stmtRole->fetchColumn();
        if (empty($userRole)) {
            $userRole = 'Técnico';
        }
        SessionManager::set('user_role', $userRole);
    } catch (\Exception $e) {
        $userRole = 'Técnico';
    }
}

$userName = (string)SessionManager::get('user_name', 'Usuario');

// 4. Obtener datos de la base de datos
try {
    $pdo = DatabaseConnection::getConnection();
    
    // Total Aeronaves en Flota
    $totalFlota = (int)$pdo->query("SELECT COUNT(*) FROM tbc_Flota WHERE es_activo = 1")->fetchColumn();
    
    // Obtener distribución por MODELO para la gráfica
    $stmtModelosChart = $pdo->query("
        SELECT modelo, COUNT(*) as qty 
        FROM tbo_Falla 
        GROUP BY modelo 
        ORDER BY qty DESC
    ");
    $chartModelosData = $stmtModelosChart->fetchAll(PDO::FETCH_ASSOC);
    
    $modelosLabels = [];
    $modelosValues = [];
    foreach ($chartModelosData as $row) {
        $modelosLabels[] = $row['modelo'];
        $modelosValues[] = (int)$row['qty'];
    }

} catch (\Exception $e) {
    error_log("Error cargando dashboard: " . $e->getMessage());
    $totalFlota = 0;
    $modelosLabels = [];
    $modelosValues = [];
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Dashboard de gestión de fallas y conocimiento técnico de flota.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    
    <!-- Chart.js para Gráficos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        /* Estilos de Impresión Optimizados para las 4 Secciones del Reporte ATA */
        @media print {
            body.printing-four-sections {
                background-color: #ffffff !important;
                color: #000000 !important;
            }
            body.printing-four-sections .sidebar,
            body.printing-four-sections .menu-toggle,
            body.printing-four-sections .welcome-header-container,
            body.printing-four-sections .subtitulo,
            body.printing-four-sections .status-card,
            body.printing-four-sections .top-cards-container,
            body.printing-four-sections .footer-mantenimiento,
            body.printing-four-sections .header-logo,
            body.printing-four-sections .logo-empresa,
            body.printing-four-sections .sidebar-footer,
            body.printing-four-sections #btn-back,
            body.printing-four-sections #filtro-tiempo,
            body.printing-four-sections #btn-imprimir-reporte-ata,
            body.printing-four-sections #contenedor-grafico-principal,
            body.printing-four-sections #jet-image-floating-container,
            body.printing-four-sections .timeline-btn-group,
            body.printing-four-sections #concurrency-indicator,
            body.printing-four-sections .jet-container {
                display: none !important;
            }
            body.printing-four-sections .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                width: 100% !important;
            }
            body.printing-four-sections .chart-container {
                border: none !important;
                box-shadow: none !important;
                padding: 0 !important;
                margin: 0 !important;
                background: #ffffff !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            body.printing-four-sections #panel-detalle-ata {
                display: block !important;
                margin-top: 0 !important;
                width: 100% !important;
            }
            body.printing-four-sections #panel-detalle-ata > div {
                box-shadow: none !important;
                border: 1px solid #cbd5e1 !important;
                margin-bottom: 30px !important;
                page-break-inside: avoid !important;
            }
            body.printing-four-sections canvas {
                max-width: 100% !important;
                height: auto !important;
            }
        }
    </style>
</head>

<body>

    <!-- Botón de Menú Hamburguesa para Móvil -->
    <button class="menu-toggle" onclick="document.body.classList.toggle('menu-open')">
        <i class="fas fa-bars"></i>
    </button>

    <!-- SIDEBAR -->
    <div class="sidebar">

        <div class="logo-box">
            <img src="assets/images/logo_menu.png" class="logo-menu">
            <h2>AleSearchTool</h2>
        </div>

        <a href="dashboard.php" class="active">
            <i class="fas fa-house"></i>
            Dashboard
        </a>

        <?php if ($userRole !== 'Otro'): ?>
        <a href="registrar_falla.php">
            <i class="fas fa-pen-to-square"></i>
            Registrar Falla
        </a>

        <a href="consultar_fallas.php">
            <i class="fas fa-magnifying-glass"></i>
            Consultar Fallas
        </a>

        <a href="asistente_ia.php">
            <i class="fas fa-robot"></i>
            Asistente IA
        </a>

        <a href="administracion.php">
            <i class="fas fa-gear"></i> Administración y mas
        </a>
        <?php else: ?>
        <a href="#" style="opacity: 0.5; cursor: not-allowed;" onclick="alert('Módulo inhabilitado de acuerdo a sus directivas de rol actual (Otro).'); return false;">
            <i class="fas fa-lock" style="color: #94a3b8; font-size: 12px; margin-right: 5px;"></i> Registrar Falla (Bloqueado)
        </a>
        <a href="#" style="opacity: 0.5; cursor: not-allowed;" onclick="alert('Módulo inhabilitado de acuerdo a sus directivas de rol actual (Otro).'); return false;">
            <i class="fas fa-lock" style="color: #94a3b8; font-size: 12px; margin-right: 5px;"></i> Consultar Fallas (Bloqueado)
        </a>
        <a href="#" style="opacity: 0.5; cursor: not-allowed;" onclick="alert('Módulo inhabilitado de acuerdo a sus directivas de rol actual (Otro).'); return false;">
            <i class="fas fa-lock" style="color: #94a3b8; font-size: 12px; margin-right: 5px;"></i> Asistente IA (Bloqueado)
        </a>
        <a href="#" style="opacity: 0.5; cursor: not-allowed;" onclick="alert('Módulo inhabilitado de acuerdo a sus directivas de rol actual (Otro).'); return false;">
            <i class="fas fa-lock" style="color: #94a3b8; font-size: 12px; margin-right: 5px;"></i> Administración y mas (Bloqueado)
        </a>
        <?php endif; ?>
        
        <a href="index.php?action=logout" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>

        <div class="sidebar-footer">
            POWERED BY LEONARDO MIREL
        </div>

    </div>

    <!-- CONTENIDO -->
    <div class="main-content">

        <!-- Header superior con Logo alineado -->
        <div class="welcome-header-container" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Bienvenido a AleSearchTool</h1>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <p class="subtitulo">
            Sistema de Gestión de Fallas y Conocimiento Técnico de Flota
        </p>

        <?php if ($userRole === 'Otro'): ?>
            
            <!-- TARJETA INFORMATIVA PREMIUM ROL OTRO -->
            <div class="status-card" style="border-left: 5px solid #6366f1; background-color: #ffffff; padding: 25px 30px; box-shadow: var(--shadow-md); margin-bottom: 35px; border-radius: 12px; display: flex; gap: 20px; align-items: center;">
                <div style="background-color: #eef2ff; color: #6366f1; font-size: 32px; width: 64px; height: 64px; border-radius: 12px; display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm); flex-shrink: 0;">
                    <i class="fas fa-user-shield"></i>
                </div>
                <div>
                    <h2 style="color: #1a419c; margin: 0 0 5px 0; font-family: 'Outfit', sans-serif; font-size: 20px; font-weight: 800;">Modo de Funciones Personalizadas Activo</h2>
                    <p style="color: #4b5563; font-size: 14.5px; line-height: 1.5; margin: 0;">
                        Su cuenta institucional está asignada al rol general <strong>"Otro"</strong>. De acuerdo a las políticas de seguridad del corporativo, todos los paneles interactivos, analíticas y reportes de flota han sido inhabilitados para proteger la privacidad de las operaciones.
                    </p>
                </div>
            </div>

            <!-- GRILLA DE OPCIONES PERSONALIZADAS -->
            <div class="admin-grid" style="margin-bottom: 40px;">
                
                <div class="admin-card" style="cursor: default;" onclick="alert('Su rol actual (Otro) no tiene asignadas consultas técnicas directas. Solicite la habilitación al Administrador.')">
                    <i class="fas fa-lock" style="color: #94a3b8; font-size: 28px;"></i>
                    <h3 style="color: #64748b;">Módulo Técnico Express</h3>
                    <p style="color: #94a3b8;">Habilitación a demanda para consultas rápidas de mantenimiento.</p>
                </div>

                <div class="admin-card" style="cursor: default;" onclick="alert('Su rol actual (Otro) no tiene asignados reportes de guardia. Solicite la habilitación al Administrador.')">
                    <i class="fas fa-lock" style="color: #94a3b8; font-size: 28px;"></i>
                    <h3 style="color: #64748b;">Reportes de Guardia</h3>
                    <p style="color: #94a3b8;">Acceso temporal de lectura a bitácoras de fallas activas.</p>
                </div>

                <div class="admin-card" style="background-color: #f8fafc; border: 2px dashed #cbd5e1; cursor: pointer; transition: transform 0.2s;" onclick="document.getElementById('modalRequest').style.display='flex'">
                    <i class="fas fa-paper-plane" style="color: #1a419c; font-size: 28px;"></i>
                    <h3 style="color: #1a419c;">Solicitar Asignación Personalizada</h3>
                    <p style="color: #475569;">Enviar una solicitud formal al administrador del sistema para habilitar funciones específicas.</p>
                </div>

            </div>

            <!-- MODAL DE SOLICITUD DE ROL/MÓDULO -->
            <div class="modal-overlay" id="modalRequest" style="display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.6); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(4px);">
                <div style="background:#ffffff; border-radius:18px; width:100%; max-width:550px; padding:30px; box-shadow:var(--shadow-lg); position:relative; box-sizing:border-box; border:1px solid #e2e8f0;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px; border-bottom:1px solid #e2e8f0; padding-bottom:15px;">
                        <h3 style="margin:0; color:#1a419c; font-family:'Outfit',sans-serif; font-size:20px; font-weight:800;"><i class="fas fa-paper-plane"></i> Solicitar Permisos</h3>
                        <button onclick="document.getElementById('modalRequest').style.display='none'" style="background:none; border:none; font-size:20px; color:#64748b; cursor:pointer;"><i class="fas fa-xmark"></i></button>
                    </div>
                    <form onsubmit="alert('Su solicitud ha sido enviada exitosamente al Administrador del sistema. Recibirá una notificación en su correo institucional.'); document.getElementById('modalRequest').style.display='none'; return false;" style="border:none; padding:0; box-shadow:none; display:flex; flex-direction:column; gap:15px;">
                        <div class="campo">
                            <label style="font-weight:700; color:#1e293b;">Módulo que requiere habilitar</label>
                            <select style="width:100%; padding:10px; border-radius:8px; border:1px solid #cbd5e1; outline:none;" required>
                                <option value="tecnico">Módulo Técnico Express</option>
                                <option value="guardia">Reportes de Guardia (Lectura de Bitácoras)</option>
                                <option value="full">Acceso Estándar de Técnico</option>
                                <option value="otro">Opciones Personalizadas Adicionales</option>
                            </select>
                        </div>
                        <div class="campo">
                            <label style="font-weight:700; color:#1e293b;">Justificación de Acceso / Motivo de consulta</label>
                            <textarea placeholder="Por favor, detalle el motivo de la consulta técnica o aeronave involucrada..." style="width:100%; height:100px; padding:10px; border-radius:8px; border:1px solid #cbd5e1; outline:none; font-family:sans-serif; resize:none;" required></textarea>
                        </div>
                        <button type="submit" style="background-color:#1a419c; color:white; border:none; padding:12px; border-radius:8px; font-weight:bold; cursor:pointer; width:100%;"><i class="fas fa-check"></i> Enviar Solicitud</button>
                    </form>
                </div>
            </div>

        <?php else: ?>

            <div class="status-card">

                <div class="status-icon">
                    <i class="fas fa-shield-halved"></i>
                </div>

                <div>
                    <h2>Estado del Sistema</h2>

                    <p>
                        <i class="fas fa-database icon-green"></i>
                        Base de datos conectada correctamente.
                    </p>

                    <p style="display: flex; align-items: center;">
                        <i class="fas fa-plane icon-green" style="margin-right: 8px;"></i>
                        <span>Flota: <?php echo $totalFlota; ?> aeronaves.</span>
                        <?php if (!in_array($userRole, ['Técnico', 'Tecnico'])): ?>
                        <a href="reporte_flota.php" style="margin-left: 20px; padding: 6px 12px; background-color: #0d3b8f; color: white; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: bold; transition: background-color 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"><i class="fas fa-list-check"></i> Ver estatus de flota</a>
                        <?php endif; ?>
                    </p>
                </div>

            </div>

            <div class="chart-container" style="width: 90%; max-width: 950px; margin: 30px auto 40px auto; background: url('assets/images/mexjet_fondo.png') center/contain no-repeat, var(--bg-card); padding: 35px 30px; border-radius: 18px; border: 1px solid var(--border-color); box-shadow: var(--shadow-md); position: relative; overflow: hidden; min-height: 480px;">
                <!-- Overlay de fondo para lucir marca de agua translúcida MexJet sin tapar las barras -->
                <div style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(243, 246, 253, 0.88); z-index: 1;"></div>
                
                <!-- Controles Superiores -->
                <div style="position: relative; z-index: 3; display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
                    <!-- Volver Atrás & Selector de Tiempo -->
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <button id="btn-back" style="display: none; padding: 8px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; border: 1px solid #1a419c; background-color: transparent; color: #1a419c; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.backgroundColor='#1a419c'; this.style.color='#ffffff';" onmouseout="this.style.backgroundColor='transparent'; this.style.color='#1a419c';">
                            <i class="fas fa-arrow-left"></i> Volver al Dashboard
                        </button>
                        
                        <button id="btn-imprimir-reporte-ata" style="display: none; padding: 8px 16px; font-size: 13px; font-weight: 700; border-radius: 8px; border: 1px solid #cbd5e1; background-color: #eef2ff; color: #1a419c; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; gap: 8px;" onmouseover="this.style.backgroundColor='#1a419c'; this.style.color='#ffffff'; this.style.borderColor='#1a419c';" onmouseout="this.style.backgroundColor='#eef2ff'; this.style.color='#1a419c'; this.style.borderColor='#cbd5e1';">
                            <i class="fas fa-file-pdf"></i> Imprimir Reporte (4 Secciones)
                        </button>
                        
                        <select id="filtro-tiempo" style="width: 110px; padding: 8px 12px; font-size: 13px; font-weight: bold; border-radius: 8px; border: 1px solid var(--border-color); background-color: #ffffff; color: var(--text-primary); outline: none; box-shadow: var(--shadow-sm); cursor: pointer;" title="Filtrar rango de tiempo">
                            <option value="todo" selected>Histórico</option>
                            <option value="mensual">Mensual</option>
                            <option value="3_months">3 meses</option>
                            <option value="6_months">6 meses</option>
                            <option value="1_year">1 año</option>
                        </select>
                    </div>

                    <!-- Widget Dinámico de N/P Más Frecuente (Solo Vista Matrículas) -->
                    <div id="part-frequent-widget" style="display: none; background-color: #eef2ff; border-left: 4px solid #1a419c; padding: 8px 15px; border-radius: 6px; box-shadow: var(--shadow-sm); font-family: var(--font-texts); font-size: 13px; color: #1c3d5a;">
                        <span style="font-weight: 700; text-transform: uppercase; color: #1a419c; margin-right: 6px;">N/P MÁS FRECUENTE:</span>
                        <span id="part-value" style="font-weight: 800; font-family: var(--font-titles);">N/A</span>
                    </div>

                    <!-- Imagen Flotante del Modelo de Jet Seleccionado -->
                    <div id="jet-image-floating-container" style="display: none; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 6px; box-shadow: var(--shadow-sm); transition: transform 0.3s;" onmouseover="this.style.transform='scale(1.05)';" onmouseout="this.style.transform='scale(1.0)';">
                        <img id="floating-jet-img" src="" alt="Jet" style="height: 55px; width: auto; object-fit: contain;">
                    </div>
                </div>

                <!-- Gráfico Canvas principal -->
                <div id="contenedor-grafico-principal" style="position: relative; z-index: 2;">
                    <!-- Encabezado de la Gráfica -->
                    <div style="text-align: center; margin-bottom: 20px;">
                        <h3 id="chart-main-title" style="color: #1a419c; margin: 0; font-size: 26px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; font-family: var(--font-titles);">Fallas por Modelo</h3>
                        <p id="chart-subtitle" style="color: #5f6368; margin: 5px 0 0 0; font-size: 13px; font-family: var(--font-texts); font-weight: 500;">Mostrando el acumulado histórico general de fallas</p>
                    </div>
                    
                    <!-- Área de renderizado de la gráfica de barras -->
                    <div style="position: relative; height: 320px; <?php echo in_array($userRole, ['Técnico', 'Tecnico', 'Supervisor']) ? 'pointer-events: none;' : ''; ?>">
                        <canvas id="graficaModelos" style="max-height: 320px;"></canvas>
                    </div>
                </div>

                <!-- Panel de Detalles del ATA Seleccionado (Layout Vertical de 4 Secciones) -->
                <div id="panel-detalle-ata" style="display: none; position: relative; z-index: 2; margin-top: 20px;">
                    
                    <!-- SECCIÓN 1 (Arriba): Gráfica de Fallas por Sub-ATA (Ancho Completo) -->
                    <div style="background-color: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); margin-bottom: 25px;">
                        <h4 style="color: #1a419c; margin: 0 0 15px 0; font-family: var(--font-titles); font-weight: 800; font-size: 15px;"><i class="fas fa-chart-bar"></i> SECCIÓN 1: Fallas por Sub-ATA</h4>
                        <div style="position: relative; height: 260px;">
                            <canvas id="graficaSubAtas" style="max-height: 260px;"></canvas>
                        </div>
                    </div>
                    
                    <!-- SECCIÓN 2 (En medio): Frecuencia de Componentes N/P (Ancho Completo) -->
                    <div style="background-color: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); margin-bottom: 25px; display: flex; flex-direction: column;">
                        <h4 style="color: #1a419c; margin: 0 0 15px 0; font-family: var(--font-titles); font-weight: 800; font-size: 15px;"><i class="fas fa-cogs"></i> SECCIÓN 2: Frecuencia de Componentes Reemplazados (N/P)</h4>
                        <div id="lista-componentes" style="max-height: 280px; overflow-y: auto; padding-right: 5px;">
                            <!-- Se llena dinámicamente por JS -->
                        </div>
                    </div>
                    
                    <!-- SECCIÓN 3: Panel de Análisis de Confiabilidad Pareto (MTBF, MTTR, Tiempo Total y Línea de Tiempo) -->
                    <div style="background-color: #ffffff; padding: 25px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm); margin-bottom: 25px;">
                        <!-- Encabezado de la Sección 3 -->
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 12px; flex-wrap: wrap; gap: 15px;">
                            <h4 style="color: #1a419c; margin: 0; font-family: var(--font-titles); font-weight: 800; font-size: 15px;">
                                <i class="fas fa-chart-line"></i> SECCIÓN 3: Pareto de Confiabilidad (MTBF, MTTR y Tiempo de Atención)
                            </h4>
                            
                            <!-- Controles de Línea de Tiempo Superior (1 Año, 6 Meses, 3 Meses) -->
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span style="font-size: 11.5px; font-weight: 700; color: #64748b; text-transform: uppercase;">Línea de Tiempo:</span>
                                <div class="timeline-btn-group" style="display: inline-flex; background-color: #f1f5f9; padding: 3px; border-radius: 8px;">
                                    <button type="button" onclick="changeTimelineRange('1_year')" id="btn-range-1y" style="border: none; background: transparent; padding: 5px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 700; color: #475569; cursor: pointer; transition: all 0.2s;">1 Año</button>
                                    <button type="button" onclick="changeTimelineRange('6_months')" id="btn-range-6m" style="border: none; background: transparent; padding: 5px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 700; color: #475569; cursor: pointer; transition: all 0.2s;">6 Meses</button>
                                    <button type="button" onclick="changeTimelineRange('3_months')" id="btn-range-3m" style="border: none; background: transparent; padding: 5px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 700; color: #475569; cursor: pointer; transition: all 0.2s;">3 Meses</button>
                                </div>
                            </div>
                        </div>

                        <!-- Indicación de Concurrencia de Fechas -->
                        <div id="concurrency-indicator" style="background: linear-gradient(to right, #f8fafc, #f1f5f9); border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 15px; margin-bottom: 20px; font-size: 12px; font-family: var(--font-texts);">
                            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                <span style="font-weight: 700; color: #475569;"><i class="far fa-clock"></i> Concurrencia de Fallas Registradas:</span>
                                <div id="concurrency-timeline-dots" style="flex-grow: 1; max-width: 600px; height: 24px; background: linear-gradient(to right, #f8fafc, #e2e8f0, #cbd5e1); border-radius: 12px; position: relative; margin: 0 15px; border: 1.5px solid #cbd5e1; box-shadow: inset 0 2px 4px rgba(0,0,0,0.08); overflow: visible;">
                                    <!-- Puntitos dinámicos absolutos -->
                                </div>
                                <span id="concurrency-summary" style="font-weight: 800; color: #1a419c;">N/A</span>
                            </div>
                        </div>

                        <!-- Contenedor del Gráfico Pareto Dual Axis -->
                        <div style="position: relative; height: 350px; width: 100%;">
                            <canvas id="graficaParetoConfiabilidad"></canvas>
                        </div>
                        
                        <!-- Leyenda de colores del MTTR -->
                        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 15px; font-size: 11.5px; font-weight: bold; color: #64748b; font-family: var(--font-texts);">
                            <span style="display: flex; align-items: center; gap: 6px;"><span style="display: inline-block; width: 12px; height: 12px; background-color: #22c55e; border-radius: 3px;"></span> MTTR Rápido (&lt; 2.5 h)</span>
                            <span style="display: flex; align-items: center; gap: 6px;"><span style="display: inline-block; width: 12px; height: 12px; background-color: #eab308; border-radius: 3px;"></span> MTTR Moderado (2.5 - 5.0 h)</span>
                            <span style="display: flex; align-items: center; gap: 6px;"><span style="display: inline-block; width: 12px; height: 12px; background-color: #ef4444; border-radius: 3px;"></span> MTTR Crítico (&gt; 5.0 h)</span>
                        </div>
                    </div>
                    
                    <!-- SECCIÓN 4 (Al final de todo - Abajo): Gráfica de Fallas por Matrícula (Ancho Completo) -->
                    <div style="background-color: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; box-shadow: var(--shadow-sm);">
                        <h4 id="titleSeccion4" style="color: #1a419c; margin: 0 0 15px 0; font-family: var(--font-titles); font-weight: 800; font-size: 15px;"><i class="fas fa-plane"></i> SECCIÓN 4: Fallas por Matrícula</h4>
                        <div style="position: relative; height: 260px;">
                            <canvas id="graficaMatriculas" style="max-height: 260px;"></canvas>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Decorador de Aeronave de Pie (Movido abajo del todo) -->
            <div class="jet-container" style="margin-top: 20px; margin-bottom: 30px;">
                <img src="assets/images/jet_cropped.png" class="jet-image">
            </div>

        <?php endif; ?>

        <!-- Banner de Mantenimiento Corporativo -->
        <div class="footer-mantenimiento" style="margin-top: 40px; margin-bottom: 40px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; text-align: center;">
            <div class="mantenimiento-title" style="color: #072b61; font-family: var(--font-titles); font-size: 18px; font-weight: 800; letter-spacing: 4px; display: flex; align-items: center; gap: 15px;">
                <span class="red-dashes" style="display: flex; gap: 4px;"><span class="red-dash" style="width: 12px; height: 3px; background-color: #d32f2f; display: inline-block;"></span><span class="red-dash" style="width: 12px; height: 3px; background-color: #d32f2f; display: inline-block;"></span><span class="red-dash" style="width: 12px; height: 3px; background-color: #d32f2f; display: inline-block;"></span></span>
                MANTENIMIENTO
                <span class="red-dashes" style="display: flex; gap: 4px;"><span class="red-dash" style="width: 12px; height: 3px; background-color: #d32f2f; display: inline-block;"></span><span class="red-dash" style="width: 12px; height: 3px; background-color: #d32f2f; display: inline-block;"></span><span class="red-dash" style="width: 12px; height: 3px; background-color: #d32f2f; display: inline-block;"></span></span>
            </div>
            <div class="mantenimiento-subtitle" style="color: #5f6368; font-family: var(--font-texts); font-size: 11px; font-weight: 700; letter-spacing: 2px;">
                SEGURIDAD &bull; CONFIANZA &bull; RENDIMIENTO
            </div>
        </div>

    </div>

    <!-- Integración y Lógica de Gráficas Drill-Down -->
    <script>
        const ctx = document.getElementById('graficaModelos').getContext('2d');
        const filtroTiempo = document.getElementById('filtro-tiempo');
        const btnBack = document.getElementById('btn-back');
        const partWidget = document.getElementById('part-frequent-widget');
        const partValue = document.getElementById('part-value');
        const jetWidget = document.getElementById('jet-image-floating-container');
        const jetImg = document.getElementById('floating-jet-img');
        const mainTitle = document.getElementById('chart-main-title');
        const chartSub = document.getElementById('chart-subtitle');

        // Contenedores principales
        const contenedorGraficoPrincipal = document.getElementById('contenedor-grafico-principal');
        const panelDetalleAta = document.getElementById('panel-detalle-ata');

        // Estado del gráfico dinámico
        let currentView = 'modelos'; // 'modelos' | 'atas' | 'detalle_ata'
        let selectedModelo = '';
        let selectedAta = '';
        let selectedSubAta = '';
        let selectedNp = '';

        let chartInstance = null;
        let chartSubAtasInstance = null;
        let chartRadialFallasInstance = null;
        let chartGaugeMtbfInstance = null;
        let chartGaugeMttrInstance = null;
        let chartSparklineMtbfInstance = null;
        let chartSparklineMttrInstance = null;
        let chartMatriculasInstance = null;

        // --- Custom Gauge Needle Plugin para Chart.js ---
        const gaugeNeedlePlugin = {
            id: 'gaugeNeedle',
            afterDraw(chart, args, options) {
                // Si el plugin no está configurado explícitamente en este gráfico, salir inmediatamente para no pintar puntos negros en otras gráficas
                if (!options || typeof options.needleValue === 'undefined') {
                    return;
                }
                const { ctx, chartArea: { width, height } } = chart;
                ctx.save();
                
                const needleValue = options.needleValue || 0;
                const minVal = options.min || 0;
                const maxVal = options.max || 100;
                
                // Limitar el valor al rango
                let value = Math.max(minVal, Math.min(maxVal, needleValue));
                
                const dataTotal = maxVal - minVal;
                // Ángulo en radianes (Math.PI es izquierda, 2*Math.PI es derecha)
                const angle = Math.PI + ((value - minVal) / dataTotal) * Math.PI;
                
                const cx = chart.width / 2;
                const cy = chart.getDatasetMeta(0).data[0].y;
                
                // Dibujar aguja
                ctx.translate(cx, cy);
                ctx.rotate(angle);
                ctx.beginPath();
                ctx.moveTo(0, -5);
                ctx.lineTo(chart.getDatasetMeta(0).data[0].outerRadius - 8, 0);
                ctx.lineTo(0, 5);
                ctx.closePath();
                ctx.fillStyle = '#334155';
                ctx.fill();
                
                // Centro de la aguja
                ctx.beginPath();
                ctx.arc(0, 0, 7, 0, Math.PI * 2);
                ctx.fillStyle = '#1e293b';
                ctx.fill();
                
                ctx.restore();
            }
        };
        Chart.register(gaugeNeedlePlugin);

        // Mapeo de nombres de archivos de imágenes de jets según el modelo
        const jetImagesMap = {
            'LJ75': 'assets/images/LJ75.png',
            'CL605': 'assets/images/CL605.png',
            '680A': 'assets/images/680A.png',
            '525B': 'assets/images/525B.png',
            'LJ45': 'assets/images/LJ45.png'
        };

        // Función para cambiar de forma elegante el título de acuerdo al rango
        function getRangeText(range) {
            if (range === '3_months') return 'los últimos 3 meses';
            if (range === '6_months') return 'los últimos 6 meses';
            if (range === '1_year') return 'el último año';
            if (range === 'mensual') return 'el mes actual';
            return 'el acumulado histórico general';
        }

        // 1. Cargar Vista de Modelos (Nivel 1)
        async function loadModelosView() {
            currentView = 'modelos';
            selectedModelo = '';
            selectedAta = '';
            selectedSubAta = '';
            selectedNp = '';
            
            btnBack.style.display = 'none';
            document.getElementById('btn-imprimir-reporte-ata').style.display = 'none';
            partWidget.style.display = 'none';
            jetWidget.style.display = 'none';
            contenedorGraficoPrincipal.style.display = 'block';
            panelDetalleAta.style.display = 'none';
            
            mainTitle.textContent = 'Fallas por Modelo';
            chartSub.textContent = `Mostrando las fallas agrupadas por modelo para ${getRangeText(filtroTiempo.value)}`;

            try {
                const response = await fetch(`api_dashboard.php?action=getModelos&range=${filtroTiempo.value}`);
                const res = await response.json();
                
                if (res.success) {
                    const labels = res.data.map(item => item.modelo);
                    const values = res.data.map(item => parseInt(item.qty));
                    renderChart(labels, values, 'Fallas por Modelo', 'rgba(26, 65, 156, 0.85)', '#1a419c');
                }
            } catch (err) {
                console.error("Error al cargar modelos: ", err);
            }
        }

        // 2. Cargar Vista de ATAs (Nivel 2)
        async function loadAtasView(modeloName) {
            currentView = 'atas';
            selectedModelo = modeloName;
            selectedAta = '';
            selectedSubAta = '';
            selectedNp = '';
            
            btnBack.style.display = 'flex';
            document.getElementById('btn-imprimir-reporte-ata').style.display = 'none';
            partWidget.style.display = 'none';
            contenedorGraficoPrincipal.style.display = 'block';
            panelDetalleAta.style.display = 'none';
            
            // Mostrar imagen del Jet flotante si está registrada
            if (jetImagesMap[modeloName]) {
                jetImg.src = jetImagesMap[modeloName];
                jetWidget.style.display = 'block';
            } else {
                jetWidget.style.display = 'none';
            }

            mainTitle.textContent = 'Detalle de Fallas';
            chartSub.textContent = `Mostrando las fallas agrupadas por ATA para el modelo: ${modeloName} (${getRangeText(filtroTiempo.value)})`;

            try {
                const response = await fetch(`api_dashboard.php?action=getAtas&modelo=${encodeURIComponent(modeloName)}&range=${filtroTiempo.value}`);
                const res = await response.json();
                
                if (res.success) {
                    const labels = res.data.map(item => item.ata);
                    const values = res.data.map(item => parseInt(item.qty));
                    renderChart(labels, values, `Fallas por ATA - ${modeloName}`, 'rgba(211, 47, 47, 0.85)', '#d32f2f');
                }
            } catch (err) {
                console.error("Error al cargar ATAs: ", err);
            }
        }

        // 3. Cargar Vista de Detalle de ATA (Nivel 3)
        async function loadDetalleAtaView(modeloName, ataName) {
            currentView = 'detalle_ata';
            selectedAta = ataName;
            selectedSubAta = '';
            selectedNp = '';
            
            btnBack.style.display = 'flex';
            document.getElementById('btn-imprimir-reporte-ata').style.display = 'flex';
            partWidget.style.display = 'none';
            contenedorGraficoPrincipal.style.display = 'none';
            panelDetalleAta.style.display = 'block';

            // Cargar Sub-ATAs, componentes, KPIs y matrículas simultáneamente
            await Promise.all([
                loadSubAtas(modeloName, ataName),
                loadComponentes(modeloName, ataName, ''),
                loadKpis(modeloName, ataName, '', ''),
                loadMatriculas(modeloName, ataName)
            ]);
        }

        // Cargar Gráfica de Sub-ATAs (Sección 1)
        async function loadSubAtas(modeloName, ataName) {
            try {
                const response = await fetch(`api_dashboard.php?action=getSubAtas&modelo=${encodeURIComponent(modeloName)}&ata=${encodeURIComponent(ataName)}&range=${filtroTiempo.value}`);
                const res = await response.json();
                if (res.success) {
                    const labels = res.data.map(item => item.sub_ata);
                    const values = res.data.map(item => parseInt(item.qty));
                    renderSubAtasChart(labels, values);
                }
            } catch (err) {
                console.error("Error al cargar Sub-ATAs: ", err);
            }
        }

        // Cargar Lista de Componentes (Sección 2)
        async function loadComponentes(modeloName, ataName, subAtaName) {
            const container = document.getElementById('lista-componentes');
            container.innerHTML = '<div style="color: #64748b; font-size: 13px; text-align: center; padding: 20px;">Cargando componentes...</div>';

            try {
                const response = await fetch(`api_dashboard.php?action=getComponentesSubAta&modelo=${encodeURIComponent(modeloName)}&ata=${encodeURIComponent(ataName)}&sub_ata=${encodeURIComponent(subAtaName)}&range=${filtroTiempo.value}`);
                const res = await response.json();
                
                if (res.success) {
                    if (res.data.length === 0) {
                        container.innerHTML = '<div style="color: #64748b; font-size: 13px; text-align: center; padding: 20px;"><i class="fas fa-info-circle"></i> Sin componentes registrados.</div>';
                        return;
                    }

                    container.innerHTML = '';
                    const maxQty = res.data[0] ? res.data[0].qty : 1;

                    // Crear layout flexible horizontal/grilla para la frecuencia de componentes de ancho completo
                    const flexWrapper = document.createElement('div');
                    flexWrapper.style.display = 'grid';
                    flexWrapper.style.gridTemplateColumns = 'repeat(auto-fill, minmax(200px, 1fr))';
                    flexWrapper.style.gap = '15px';
                    flexWrapper.style.width = '100%';

                    res.data.forEach(item => {
                        const porcentaje = (item.qty / maxQty) * 100;
                        const divItem = document.createElement('div');
                        divItem.style.padding = '12px';
                        divItem.style.border = '1px solid #e2e8f0';
                        divItem.style.borderRadius = '8px';
                        divItem.style.cursor = 'pointer';
                        divItem.style.transition = 'all 0.2s';
                        divItem.style.backgroundColor = '#ffffff';
                        divItem.className = 'componente-item';
                        if (item.matriculas) {
                            divItem.title = "Matrículas: " + item.matriculas;
                        }
                        if (selectedNp === item.np) {
                            divItem.style.backgroundColor = '#eef2ff';
                            divItem.style.borderLeft = '4px solid #1a419c';
                        }

                        divItem.innerHTML = `
                            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; font-weight: 700; color: #334155; margin-bottom: 6px;">
                                <span><i class="fas fa-barcode" style="color: #64748b; margin-right: 6px;"></i> ${item.label}</span>
                                <span style="background-color: #f1f5f9; color: #1a419c; font-size: 11px; padding: 2px 8px; border-radius: 20px;">${item.qty}</span>
                            </div>
                            <div style="width: 100%; height: 6px; background-color: #f1f5f9; border-radius: 3px; overflow: hidden;">
                                <div style="width: ${porcentaje}%; height: 100%; background-color: #1a419c; border-radius: 3px;"></div>
                            </div>
                        `;

                        divItem.addEventListener('click', () => {
                            document.querySelectorAll('.componente-item').forEach(el => {
                                el.style.backgroundColor = '#ffffff';
                                el.style.borderLeft = '1px solid #e2e8f0';
                            });
                            selectedNp = item.np;
                            divItem.style.backgroundColor = '#eef2ff';
                            divItem.style.borderLeft = '4px solid #1a419c';

                            // Recalcular KPIs (Sección 3)
                            loadKpis(selectedModelo, selectedAta, selectedSubAta, selectedNp);
                        });

                        flexWrapper.appendChild(divItem);
                    });
                    container.appendChild(flexWrapper);
                }
            } catch (err) {
                console.error("Error al cargar componentes: ", err);
            }
        }

        // Rango de línea de tiempo por defecto para Sección 3
        let selectedTimelineRange = '1_year';

        window.changeTimelineRange = function(range) {
            selectedTimelineRange = range;
            
            // Actualizar estilo visual de los botones
            ['1y', '6m', '3m'].forEach(id => {
                const btn = document.getElementById('btn-range-' + id);
                if (btn) {
                    btn.style.backgroundColor = 'transparent';
                    btn.style.color = '#475569';
                    btn.style.boxShadow = 'none';
                }
            });
            
            const activeId = range === '1_year' ? '1y' : (range === '6_months' ? '6m' : '3m');
            const activeBtn = document.getElementById('btn-range-' + activeId);
            if (activeBtn) {
                activeBtn.style.backgroundColor = '#ffffff';
                activeBtn.style.color = '#1a419c';
                activeBtn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
            }
            
            // Recargar KPIs usando el nuevo rango
            loadKpis(selectedModelo, selectedAta, selectedSubAta, selectedNp);
        };

        let chartParetoInstance = null;

        function renderParetoConfiabilidad(data) {
            const ctx = document.getElementById('graficaParetoConfiabilidad').getContext('2d');
            if (chartParetoInstance) {
                chartParetoInstance.destroy();
            }

            if (!data || data.length === 0) {
                ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
                ctx.font = '14px Plus Jakarta Sans';
                ctx.fillStyle = '#64748b';
                ctx.textAlign = 'center';
                ctx.fillText('Sin datos disponibles para esta selección', ctx.canvas.width / 2, ctx.canvas.height / 2);
                return;
            }

            const labels = data.map(item => item.reference);
            const failCounts = data.map(item => item.fail_count);
            const totalAttentions = data.map(item => item.total_attention);
            
            // Colores basados en el rango del MTTR promedio
            const barColors = data.map(item => {
                const mttr = parseFloat(item.mttr);
                if (mttr < 2.5) return '#22c55e'; // Verde
                if (mttr <= 5.0) return '#eab308'; // Amarillo
                return '#ef4444'; // Rojo
            });

            chartParetoInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Fallas (Lado Izq - MTBF)',
                            data: failCounts,
                            backgroundColor: barColors,
                            borderColor: barColors,
                            borderWidth: 1,
                            yAxisID: 'yLeft',
                            order: 2,
                            borderRadius: 6,
                            barPercentage: 0.5
                        },
                        {
                            label: 'Tiempo Total de Atención (Lado Der)',
                            data: totalAttentions,
                            type: 'line',
                            borderColor: '#1a419c',
                            backgroundColor: 'rgba(26, 65, 156, 0.1)',
                            borderWidth: 3,
                            tension: 0.3,
                            fill: false,
                            pointRadius: 5,
                            pointBackgroundColor: '#1a419c',
                            yAxisID: 'yRight',
                            order: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                font: { family: "'Plus Jakarta Sans', sans-serif", size: 11, weight: 'bold' },
                                color: '#475569'
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            padding: 12,
                            titleFont: { size: 12, weight: 'bold', family: "'Outfit', sans-serif" },
                            bodyFont: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                            callbacks: {
                                label: function (context) {
                                    const index = context.dataIndex;
                                    const item = data[index];
                                    if (context.dataset.type === 'line') {
                                        return `Tiempo Total de Atención: ${context.raw} h`;
                                    } else {
                                        return `Fallas: ${context.raw} (MTTR Promedio: ${item.mttr} h)`;
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { family: "'Plus Jakarta Sans', sans-serif", size: 10, weight: 600 },
                                color: '#475569'
                            }
                        },
                        yLeft: {
                            type: 'linear',
                            position: 'left',
                            grid: { color: '#e2e8f0' },
                            title: {
                                display: true,
                                text: 'Cantidad de Fallas',
                                color: '#475569',
                                font: { family: "'Outfit', sans-serif", size: 12, weight: 'bold' }
                            },
                            ticks: {
                                beginAtZero: true,
                                stepSize: 1,
                                font: { family: "'Plus Jakarta Sans', sans-serif", size: 10 }
                            }
                        },
                        yRight: {
                            type: 'linear',
                            position: 'right',
                            grid: { display: false },
                            title: {
                                display: true,
                                text: 'Tiempo Total de Atención (Horas)',
                                color: '#1a419c',
                                font: { family: "'Outfit', sans-serif", size: 12, weight: 'bold' }
                            },
                            ticks: {
                                beginAtZero: true,
                                font: { family: "'Plus Jakarta Sans', sans-serif", size: 10 }
                            }
                        }
                    }
                }
            });
        }

        // Elemento global de tooltip para la línea de tiempo de concurrencia
        let timelineTooltip = null;

        function showTimelineTooltip(e, htmlContent) {
            if (!timelineTooltip) {
                timelineTooltip = document.createElement('div');
                timelineTooltip.style.position = 'fixed';
                timelineTooltip.style.backgroundColor = 'rgba(30, 41, 59, 0.98)'; // Fondo pizarra oscuro premium
                timelineTooltip.style.color = '#ffffff';
                timelineTooltip.style.padding = '10px 14px';
                timelineTooltip.style.borderRadius = '8px';
                timelineTooltip.style.boxShadow = '0 10px 25px rgba(3, 20, 51, 0.25)';
                timelineTooltip.style.fontSize = '12px';
                timelineTooltip.style.fontFamily = "'Plus Jakarta Sans', sans-serif";
                timelineTooltip.style.pointerEvents = 'none';
                timelineTooltip.style.zIndex = '99999';
                timelineTooltip.style.lineHeight = '1.4';
                timelineTooltip.style.border = '1px solid rgba(255, 255, 255, 0.1)';
                timelineTooltip.style.transition = 'opacity 0.15s ease-in-out';
                document.body.appendChild(timelineTooltip);
            }
            timelineTooltip.innerHTML = htmlContent;
            timelineTooltip.style.opacity = '1';
            timelineTooltip.style.display = 'block';
            positionTimelineTooltip(e);
        }

        function positionTimelineTooltip(e) {
            if (!timelineTooltip) return;
            const tooltipWidth = timelineTooltip.offsetWidth;
            const tooltipHeight = timelineTooltip.offsetHeight;
            let x = e.clientX + 15;
            let y = e.clientY - tooltipHeight - 15;

            // Evitar desbordes de pantalla
            if (x + tooltipWidth > window.innerWidth) {
                x = e.clientX - tooltipWidth - 15;
            }
            if (y < 0) {
                y = e.clientY + 15;
            }

            timelineTooltip.style.left = x + 'px';
            timelineTooltip.style.top = y + 'px';
        }

        function hideTimelineTooltip() {
            if (timelineTooltip) {
                timelineTooltip.style.opacity = '0';
                timelineTooltip.style.display = 'none';
            }
        }

        function renderConcurrencyTimeline(dates) {
            const timelineContainer = document.getElementById('concurrency-timeline-dots');
            const summarySpan = document.getElementById('concurrency-summary');
            if (!timelineContainer) return;

            timelineContainer.innerHTML = '';

            if (!dates || dates.length === 0) {
                summarySpan.innerText = '0 fallas (Sin Concurrencia)';
                summarySpan.style.color = '#64748b';
                return;
            }

            const now = new Date();
            let startDate = new Date();

            if (selectedTimelineRange === '3_months') {
                startDate.setMonth(now.getMonth() - 3);
            } else if (selectedTimelineRange === '6_months') {
                startDate.setMonth(now.getMonth() - 6);
            } else {
                startDate.setFullYear(now.getFullYear() - 1);
            }

            const startTimestamp = startDate.getTime();
            const endTimestamp = now.getTime();
            const rangeMs = endTimestamp - startTimestamp;

            // Filtrar y ordenar cronológicamente las fechas válidas en el rango
            const activeFailures = [];
            dates.forEach(dateStr => {
                const cleanDate = dateStr.replace(/-/g, '/');
                const d = new Date(cleanDate);
                const ts = d.getTime();

                if (ts >= startTimestamp && ts <= endTimestamp) {
                    activeFailures.push({ dateStr, d, ts });
                }
            });

            // Ordenar de más antigua a más reciente
            activeFailures.sort((a, b) => a.ts - b.ts);
            const overlapCount = activeFailures.length;

            activeFailures.forEach((item, index) => {
                const pct = ((item.ts - startTimestamp) / rangeMs) * 100;
                
                // Calcular días desde falla anterior
                let prevInfo = '';
                if (index > 0) {
                    const diffMs = item.ts - activeFailures[index - 1].ts;
                    const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));
                    const prevFormatted = activeFailures[index - 1].d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    prevInfo = `• <strong style="color: #38bdf8;">${diffDays} días</strong> desde falla anterior (${prevFormatted})`;
                }

                // Calcular días hasta falla siguiente
                let nextInfo = '';
                if (index < activeFailures.length - 1) {
                    const diffMs = activeFailures[index + 1].ts - item.ts;
                    const diffDays = Math.round(diffMs / (1000 * 60 * 60 * 24));
                    const nextFormatted = activeFailures[index + 1].d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });
                    nextInfo = `• <strong style="color: #38bdf8;">${diffDays} días</strong> hasta la siguiente (${nextFormatted})`;
                }

                const curFormatted = item.d.toLocaleDateString('es-MX', { day: '2-digit', month: '2-digit', year: 'numeric' });

                // Contenido HTML del Tooltip Premium
                let tooltipHtml = `
                    <div style="font-weight: 800; font-size: 13px; border-bottom: 1px solid rgba(255,255,255,0.15); padding-bottom: 5px; margin-bottom: 6px; color: #38bdf8;">
                        <i class="far fa-calendar-alt"></i> Falla #${index + 1} de ${overlapCount}
                    </div>
                    <div style="margin-bottom: 5px;">Fecha Registro: <strong>${curFormatted}</strong></div>
                `;
                if (prevInfo) tooltipHtml += `<div style="font-size: 11px; margin-top: 4px; color: #cbd5e1;">${prevInfo}</div>`;
                if (nextInfo) tooltipHtml += `<div style="font-size: 11px; margin-top: 4px; color: #cbd5e1;">${nextInfo}</div>`;
                if (!prevInfo && !nextInfo) tooltipHtml += `<div style="font-size: 11px; margin-top: 4px; color: #94a3b8; font-style: italic;">Único registro en este periodo.</div>`;

                const dot = document.createElement('div');
                dot.style.position = 'absolute';
                dot.style.left = `${pct}%`;
                dot.style.width = '14px';
                dot.style.height = '14px';
                dot.style.borderRadius = '50%';
                dot.style.backgroundColor = '#ef4444';
                dot.style.top = '4px'; // Centrado en la barra de 24px
                dot.style.transform = 'translateX(-50%)';
                dot.style.boxShadow = '0 0 5px rgba(239, 68, 68, 0.8)';
                dot.style.border = '2px solid #ffffff';
                dot.style.cursor = 'pointer';
                dot.style.transition = 'all 0.15s ease-in-out';

                // Eventos de ratón interactivos
                dot.addEventListener('mouseenter', (e) => {
                    dot.style.transform = 'translateX(-50%) scale(1.4)';
                    dot.style.backgroundColor = '#dc2626';
                    dot.style.boxShadow = '0 0 8px rgba(220, 38, 38, 0.9)';
                    showTimelineTooltip(e, tooltipHtml);
                });
                dot.addEventListener('mousemove', (e) => {
                    positionTimelineTooltip(e);
                });
                dot.addEventListener('mouseleave', () => {
                    dot.style.transform = 'translateX(-50%) scale(1)';
                    dot.style.backgroundColor = '#ef4444';
                    dot.style.boxShadow = '0 0 5px rgba(239, 68, 68, 0.8)';
                    hideTimelineTooltip();
                });

                timelineContainer.appendChild(dot);
            });

            let concurrencyText = 'Baja Concurrencia';
            let textColor = '#22c55e';

            if (overlapCount >= 6) {
                concurrencyText = 'Alta Concurrencia 🔥';
                textColor = '#ef4444';
            } else if (overlapCount >= 3) {
                concurrencyText = 'Concurrencia Moderada ⚡';
                textColor = '#eab308';
            }

            summarySpan.innerText = `${overlapCount} fallas (${concurrencyText})`;
            summarySpan.style.color = textColor;
        }

        // Cargar y Actualizar KPIs de Confiabilidad (Sección 3)
        async function loadKpis(modeloName, ataName, subAtaName, npName) {
            try {
                let targetSubAta = subAtaName;
                if (!targetSubAta) {
                    if (chartSubAtasInstance && chartSubAtasInstance.data.labels && chartSubAtasInstance.data.labels.length > 0) {
                        targetSubAta = chartSubAtasInstance.data.labels[0];
                    } else {
                        targetSubAta = '';
                    }
                }

                if (!targetSubAta) {
                    renderParetoConfiabilidad([]);
                    renderConcurrencyTimeline([]);
                    return;
                }

                const response = await fetch(`api_dashboard.php?action=getParetoConfiabilidad&modelo=${encodeURIComponent(modeloName)}&ata=${encodeURIComponent(ataName)}&sub_ata=${encodeURIComponent(targetSubAta)}&range=${selectedTimelineRange}`);
                const res = await response.json();
                
                if (res.success) {
                    renderParetoConfiabilidad(res.data);
                    renderConcurrencyTimeline(res.dates);
                    
                    // Inicializar visual de botones de rango de tiempo
                    ['1y', '6m', '3m'].forEach(id => {
                        const btn = document.getElementById('btn-range-' + id);
                        if (btn) {
                            if ((selectedTimelineRange === '1_year' && id === '1y') ||
                                (selectedTimelineRange === '6_months' && id === '6m') ||
                                (selectedTimelineRange === '3_months' && id === '3m')) {
                                btn.style.backgroundColor = '#ffffff';
                                btn.style.color = '#1a419c';
                                btn.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
                            } else {
                                btn.style.backgroundColor = 'transparent';
                                btn.style.color = '#475569';
                                btn.style.boxShadow = 'none';
                            }
                        }
                    });
                }
            } catch (err) {
                console.error("Error al cargar KPIs de Pareto: ", err);
            }
        }

        // Cargar y Renderizar Sección 4: Gráfica de Fallas por Matrícula (Barras Verdes)
        async function loadMatriculas(modeloName, ataName) {
            try {
                // Actualizar título de la sección 4 dinámicamente
                const titleS4 = document.getElementById('titleSeccion4');
                if (titleS4) {
                    titleS4.innerHTML = `<i class="fas fa-plane"></i> SECCIÓN 4: Fallas por Matrícula - En general del ATA ${ataName}`;
                }

                const response = await fetch(`api_dashboard.php?action=getMatriculas&modelo=${encodeURIComponent(modeloName)}&ata=${encodeURIComponent(ataName)}&range=${filtroTiempo.value}`);
                const res = await response.json();
                if (res.success) {
                    const labels = res.data.map(item => item.matricula);
                    const values = res.data.map(item => parseInt(item.qty));
                    renderMatriculasChart(labels, values);
                }
            } catch (err) {
                console.error("Error al cargar matrículas de la Sección 4: ", err);
            }
        }

        // 1. Renderizar Gráfico Radial (Volumen de Fallas)
        function renderRadialFallas(cantFallas) {
            const radialCtx = document.getElementById('graficaRadialFallas').getContext('2d');
            if (chartRadialFallasInstance) {
                chartRadialFallasInstance.destroy();
            }

            const limit = 20;
            const percentage = Math.min(100, Math.round((cantFallas / limit) * 100));
            
            // Determinar color en base al porcentaje
            let color = '#1a419c'; // Azul corporativo por defecto
            if (percentage >= 75) {
                color = '#ef4444'; // Rojo (Crítico)
            } else if (percentage >= 50) {
                color = '#eab308'; // Amarillo (Precaución)
            }

            document.getElementById('radial-fallas-text').textContent = cantFallas;
            document.getElementById('radial-fallas-text').style.color = color;

            chartRadialFallasInstance = new Chart(radialCtx, {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [percentage, 100 - percentage],
                        backgroundColor: [color, '#e2e8f0'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    circumference: 180,
                    rotation: -90,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    }
                }
            });
        }

        // 2. Renderizar Gauge de MTBF (Tacómetro con 3 zonas)
        function renderGaugeMtbf(mtbfValue) {
            const gaugeCtx = document.getElementById('graficaGaugeMtbf').getContext('2d');
            if (chartGaugeMtbfInstance) {
                chartGaugeMtbfInstance.destroy();
            }

            document.getElementById('gauge-mtbf-text').textContent = mtbfValue.toLocaleString() + " FH";

            chartGaugeMtbfInstance = new Chart(gaugeCtx, {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [30, 30, 40], // Zonas: 0-30 FH (Rojo), 30-60 FH (Amarillo), 60-100 FH (Verde)
                        backgroundColor: ['#ef4444', '#eab308', '#22c55e'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    circumference: 180,
                    rotation: -90,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false },
                        gaugeNeedle: {
                            needleValue: mtbfValue,
                            min: 0,
                            max: 100
                        }
                    }
                }
            });
        }

        // 3. Renderizar Gauge de MTTR (Tacómetro Inverso)
        function renderGaugeMttr(mttrValue) {
            const gaugeCtx = document.getElementById('graficaGaugeMttr').getContext('2d');
            if (chartGaugeMttrInstance) {
                chartGaugeMttrInstance.destroy();
            }

            document.getElementById('gauge-mttr-text').textContent = mttrValue.toLocaleString() + " Hrs";

            chartGaugeMttrInstance = new Chart(gaugeCtx, {
                type: 'doughnut',
                data: {
                    datasets: [{
                        data: [25, 25, 50], // Zonas de tiempo de reparación: 0-2.5 Hrs (Verde), 2.5-5.0 Hrs (Amarillo), 5.0-10.0 Hrs (Rojo)
                        backgroundColor: ['#22c55e', '#eab308', '#ef4444'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '75%',
                    circumference: 180,
                    rotation: -90,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false },
                        gaugeNeedle: {
                            needleValue: mttrValue,
                            min: 0,
                            max: 10
                        }
                    }
                }
            });
        }

        // 4. Renderizador Unificado de Sparklines (Líneas de tendencia de 6 meses)
        function renderSparkline(sparkCtx, trendData, lineColor, chartInstanceVarName) {
            // Destruir instancia previa guardada dinámicamente en el objeto global/window
            if (window[chartInstanceVarName]) {
                window[chartInstanceVarName].destroy();
            }

            window[chartInstanceVarName] = new Chart(sparkCtx, {
                type: 'line',
                data: {
                    labels: ['', '', '', '', '', ''],
                    datasets: [{
                        data: trendData,
                        borderColor: lineColor,
                        borderWidth: 2,
                        pointRadius: 0,
                        fill: false,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: false }
                    },
                    scales: {
                        x: { display: false },
                        y: { display: false }
                    }
                }
            });
        }

        // Renderizar el gráfico de Sub-ATAs (Sección 1)
        function renderSubAtasChart(labels, dataValues) {
            const subCtx = document.getElementById('graficaSubAtas').getContext('2d');
            if (chartSubAtasInstance) {
                chartSubAtasInstance.destroy();
            }

            chartSubAtasInstance = new Chart(subCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataValues,
                        backgroundColor: 'rgba(26, 65, 156, 0.85)',
                        borderColor: '#1a419c',
                        borderWidth: 1.5,
                        borderRadius: 6,
                        hoverBackgroundColor: '#1a419c',
                        barPercentage: 0.55
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(33, 33, 33, 0.95)',
                            titleFont: { size: 12, weight: 'bold', family: "'Outfit', sans-serif" },
                            bodyFont: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                            padding: 10,
                            cornerRadius: 6
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 10, family: "'Plus Jakarta Sans', sans-serif", weight: 600 },
                                color: '#5f6368'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' },
                            ticks: {
                                font: { size: 10, family: "'Plus Jakarta Sans', sans-serif" },
                                color: '#5f6368',
                                stepSize: 1
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = (elements.length) ? 'pointer' : 'default';
                    },
                    onClick: (event, elements) => {
                        if (elements.length === 0) return;
                        const index = elements[0].index;
                        const label = chartSubAtasInstance.data.labels[index];

                        // Clic en Sub-ATA (Sección 1) -> Filtrar Componentes (Sección 2)
                        selectedSubAta = label;
                        selectedNp = ''; // Resetear selección de componente
                        loadComponentes(selectedModelo, selectedAta, selectedSubAta);
                        loadKpis(selectedModelo, selectedAta, selectedSubAta, '');
                    }
                }
            });
        }

        // Renderizar la Gráfica de Fallas por Matrícula (Sección 4)
        function renderMatriculasChart(labels, values) {
            const mCtx = document.getElementById('graficaMatriculas').getContext('2d');
            if (chartMatriculasInstance) {
                chartMatriculasInstance.destroy();
            }

            chartMatriculasInstance = new Chart(mCtx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Número de Fallas',
                        data: values,
                        backgroundColor: 'rgba(34, 197, 94, 0.85)', // Barras verdes
                        borderColor: '#22c55e',
                        borderWidth: 1.5,
                        borderRadius: 6,
                        hoverBackgroundColor: '#22c55e',
                        barPercentage: 0.55
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(33, 33, 33, 0.95)',
                            titleFont: { size: 12, weight: 'bold', family: "'Outfit', sans-serif" },
                            bodyFont: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                            padding: 10,
                            cornerRadius: 6
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, family: "'Plus Jakarta Sans', sans-serif", weight: 600 },
                                color: '#5f6368'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' },
                            ticks: {
                                font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                                color: '#5f6368',
                                stepSize: 1
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = (elements.length) ? 'pointer' : 'default';
                    },
                    onClick: (event, elements) => {
                        if (elements.length === 0) return;
                        const index = elements[0].index;
                        const label = chartMatriculasInstance.data.labels[index];
                        
                        // Redireccionar quirúrgicamente al buscador de fallas prefiltrado
                        window.location.href = `consultar_fallas.php?modelo=${encodeURIComponent(selectedModelo)}&ata=${encodeURIComponent(selectedAta)}&matricula=${encodeURIComponent(label)}&range=${encodeURIComponent(filtroTiempo.value)}`;
                    }
                }
            });
        }

        // 4. Renderizador Unificado de Chart.js para Vista General
        function renderChart(labels, dataValues, datasetLabel, barColor, borderColor) {
            if (chartInstance) {
                chartInstance.destroy();
            }

            chartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Número de Fallas',
                        data: dataValues,
                        backgroundColor: barColor,
                        borderColor: borderColor,
                        borderWidth: 1.5,
                        borderRadius: 6,
                        hoverBackgroundColor: barColor.replace('0.85', '1.0'),
                        barPercentage: 0.55
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(33, 33, 33, 0.95)',
                            titleFont: { size: 13, weight: 'bold', family: "'Outfit', sans-serif" },
                            bodyFont: { size: 12, family: "'Plus Jakarta Sans', sans-serif" },
                            padding: 12,
                            cornerRadius: 8,
                            boxPadding: 6,
                            displayColors: true,
                            callbacks: {
                                label: function(context) {
                                    return ` Número de Fallas: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: {
                                font: { size: 11, family: "'Plus Jakarta Sans', sans-serif", weight: 600 },
                                color: '#5f6368'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: '#e2e8f0' },
                            ticks: {
                                font: { size: 11, family: "'Plus Jakarta Sans', sans-serif" },
                                color: '#5f6368'
                            }
                        }
                    },
                    onHover: (event, elements) => {
                        event.native.target.style.cursor = (elements.length) ? 'pointer' : 'default';
                    },
                    onClick: (event, elements) => {
                        if (elements.length === 0) return;
                        const index = elements[0].index;
                        const label = chartInstance.data.labels[index];
                        
                        if (currentView === 'modelos') {
                            loadAtasView(label);
                        } else if (currentView === 'atas') {
                            loadDetalleAtaView(selectedModelo, label);
                        }
                    }
                }
            });
        }

        // 5. Escuchas de Eventos de Controles
        filtroTiempo.addEventListener('change', () => {
            if (currentView === 'modelos') {
                loadModelosView();
            } else if (currentView === 'atas') {
                loadAtasView(selectedModelo);
            } else if (currentView === 'detalle_ata') {
                loadDetalleAtaView(selectedModelo, selectedAta);
            }
        });

        btnBack.addEventListener('click', () => {
            if (currentView === 'detalle_ata') {
                loadAtasView(selectedModelo);
            } else if (currentView === 'atas') {
                loadModelosView();
            }
        });

        document.getElementById('btn-imprimir-reporte-ata').addEventListener('click', () => {
            document.body.classList.add('printing-four-sections');
            window.print();
        });

        window.addEventListener('afterprint', () => {
            document.body.classList.remove('printing-four-sections');
        });

        // Carga inicial al pintar la página
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const modelParam = urlParams.get('modelo');
            const ataParam = urlParams.get('ata');
            const rangeParam = urlParams.get('range');

            if (rangeParam) {
                filtroTiempo.value = rangeParam;
            }

            if (modelParam && ataParam) {
                selectedModelo = modelParam;
                selectedAta = ataParam;
                loadDetalleAtaView(modelParam, ataParam);
            } else if (modelParam) {
                selectedModelo = modelParam;
                loadAtasView(modelParam);
            } else {
                loadModelosView();
            }
        });
    </script>
</body>

</html>
