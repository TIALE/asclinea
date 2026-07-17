<?php

declare(strict_types=1);

// Evitar almacenamiento en caché del panel de administración
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;
use App\Infrastructure\Database\DatabaseConnection;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

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

// Bloqueo estricto para rol "Otro"
if ($userRole === 'Otro') {
    header('Location: dashboard.php');
    exit;
}

$userName = (string)SessionManager::get('user_name', 'Usuario');

$msg = '';
$err = '';
$section = isset($_GET['section']) ? (string)$_GET['section'] : 'menu';

// Procesar guardado de Configuración Segura (Solo l.rodriguez@aleservicecenter.com)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'configuracion' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com') {
    // Obtener la configuración guardada actual para saber qué conservar si vienen valores enmascarados
    $currentConfig = \App\Shared\Config\SecureConfig::decrypt();

    $keysToSave = [
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'FTP_HOST', 'FTP_PORT', 'FTP_USER', 'FTP_PASS', 'FTP_REMOTE_PATH',
        'GEMINI_API_KEY', 'AGENTE_IT'
    ];
    $newData = [];
    foreach ($keysToSave as $k) {
        $val = isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';

        // Si es un campo sensible y viene enmascarado o vacío (para claves), conservar el valor previo
        if (in_array($k, ['DB_PASS', 'FTP_PASS', 'GEMINI_API_KEY'], true)) {
            if ($val === '********' || empty($val)) {
                $val = !empty($currentConfig[$k]) ? $currentConfig[$k] : (string)getenv($k);
            }
        }

        $newData[$k] = $val;
    }

    if (\App\Shared\Config\SecureConfig::encrypt($newData)) {
        $msg = 'Configuración guardada y encriptada exitosamente de forma segura.';
        // Recargar en el entorno de PHP para la ejecución actual
        foreach ($newData as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
    } else {
        $err = 'Error al intentar encriptar y guardar la configuración.';
    }
}

// Cargar valores para la vista de configuración
$configValues = [];
$maskedValues = [];
if ($section === 'configuracion' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com') {
    $configValues = \App\Shared\Config\SecureConfig::decrypt();
    $keysToLoad = [
        'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS',
        'FTP_HOST', 'FTP_PORT', 'FTP_USER', 'FTP_PASS', 'FTP_REMOTE_PATH',
        'GEMINI_API_KEY', 'AGENTE_IT'
    ];
    foreach ($keysToLoad as $k) {
        if (!isset($configValues[$k])) {
            $configValues[$k] = (string)getenv($k);
        }
    }

    // Clonar para enmascarar valores sensibles en la vista
    $maskedValues = $configValues;
    if (!empty($maskedValues['DB_PASS'])) $maskedValues['DB_PASS'] = '********';
    if (!empty($maskedValues['FTP_PASS'])) $maskedValues['FTP_PASS'] = '********';
    if (!empty($maskedValues['GEMINI_API_KEY'])) $maskedValues['GEMINI_API_KEY'] = '********';
} else if ($section === 'configuracion') {
    header('Location: administracion.php');
    exit;
}

// Bloqueo estricto de Gestión de Flota para roles no autorizados
if ($section === 'flota' && !in_array($userRole, ['Administrador', 'Ingeniero'])) {
    header('Location: administracion.php');
    exit;
}

try {
    $pdo = DatabaseConnection::getConnection();

    // Auto-migración para agregar numero_serie si no existe
    try {
        $stmtCol = $pdo->query("SHOW COLUMNS FROM tbc_Flota LIKE 'numero_serie'");
        if ($stmtCol->rowCount() == 0) {
            $pdo->exec("ALTER TABLE tbc_Flota ADD COLUMN numero_serie VARCHAR(50) DEFAULT NULL AFTER matricula");
            $dataMig = [
                'XA-MLD' => '680A-0189', 'XA-MLE' => '680A-0369', 'XA-MLC' => '680A-0289', 'XA-MLT' => '680A-0252',
                'XA-MLF' => '680A-0158', 'XA-MLG' => '680A-0177', 'XA-AGV' => '680A-0130', 'XA-MLU' => '680A-0129',
                'XA-ALE' => '525B-0764', 'XA-LEY' => '525B-0666', 'XA-MCC' => '525B-0652', 'XA-MCT' => '525B-0629',
                'XA-MCU' => '525B-0628', 'XA-MBC' => '45-521', 'XA-MBD' => '45-482', 'XA-MBE' => '45-504',
                'XA-MBN' => '45-510', 'XA-MBO' => '45-524', 'XA-MBS' => '45-522', 'XA-MBT' => '45-492',
                'XA-MBX' => '45-458', 'XA-LPZ' => '5810', 'XA-MXJ' => '5901', 'XA-MJT' => '5853', 'XA-NDY' => '5944'
            ];
            $updateStmt = $pdo->prepare("UPDATE tbc_Flota SET numero_serie = :ns WHERE matricula = :mat");
            foreach ($dataMig as $mat => $ns) {
                $updateStmt->execute([':ns' => $ns, ':mat' => $mat]);
            }
        }
    } catch (\Exception $e) {}

    // Procesar alta de nueva aeronave
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_aircraft') {
        $modelo    = trim((string)filter_input(INPUT_POST, 'modelo', FILTER_DEFAULT));
        $matricula = trim((string)filter_input(INPUT_POST, 'matricula', FILTER_DEFAULT));
        $numero_serie = trim((string)filter_input(INPUT_POST, 'numero_serie', FILTER_DEFAULT));
        
        if (empty($modelo) || empty($matricula)) {
            $err = 'Por favor, rellene todos los campos obligatorios de la aeronave.';
        } else {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM tbc_Flota WHERE matricula = :mat");
            $stmtCheck->execute([':mat' => $matricula]);
            if ((int)$stmtCheck->fetchColumn() > 0) {
                $err = "La matrícula {$matricula} ya está registrada en la flota.";
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO tbc_Flota (modelo, matricula, numero_serie, es_activo) VALUES (:modelo, :matricula, :numero_serie, 1)");
                $stmtInsert->execute([
                    ':modelo'       => $modelo,
                    ':matricula'    => $matricula,
                    ':numero_serie' => $numero_serie
                ]);
                $msg = "Aeronave {$matricula} ({$modelo}) agregada exitosamente.";
                $section = 'flota';
            }
        }
    }

    // Procesar cambio de estado de aeronave (Activo/Inactivo)
    if (isset($_GET['toggle_id'])) {
        $id = (int)$_GET['toggle_id'];
        $stmtStatus = $pdo->prepare("SELECT es_activo FROM tbc_Flota WHERE id_flota = :id");
        $stmtStatus->execute([':id' => $id]);
        $current = $stmtStatus->fetchColumn();
        
        if ($current !== false) {
            $newStatus = ((int)$current === 1) ? 0 : 1;
            $stmtUpdate = $pdo->prepare("UPDATE tbc_Flota SET es_activo = :status WHERE id_flota = :id");
            $stmtUpdate->execute([
                ':status' => $newStatus,
                ':id'     => $id
            ]);
            $msg = "Estado de la aeronave modificado correctamente.";
            $section = 'flota';
        }
    }

    // Obtener listado completo de la flota
    $stmtFlota = $pdo->query("SELECT * FROM tbc_Flota ORDER BY modelo, matricula");
    $flota = $stmtFlota->fetchAll(PDO::FETCH_ASSOC);

    // Métricas para auditoría
    $totalAeronaves = count($flota);
    $aeronavesActivas = 0;
    foreach ($flota as $plane) {
        if ((int)$plane['es_activo'] === 1) $aeronavesActivas++;
    }

    // Estadísticas de IA (Log de Uso)
    $estadisticasIA = [];
    if ($section === 'estadistica_ia' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com') {
        try {
            $stmtIA = $pdo->query("SELECT * FROM tbo_LogAsistenteIA ORDER BY fecha_inicio DESC");
            $estadisticasIA = $stmtIA->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            $estadisticasIA = [];
        }
    } else if ($section === 'estadistica_ia') {
        header('Location: administracion.php');
        exit;
    }

    // Métricas del Agente IA (Calificaciones)
    $metricasIA = [];
    if ($section === 'ia_metrics' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com') {
        try {
            // Verificar que la tabla existe primero (en caso de que nadie haya calificado aún)
            $stmtCheckTable = $pdo->query("SHOW TABLES LIKE 'ia_metrics_log'");
            if ($stmtCheckTable->rowCount() > 0) {
                $stmtMet = $pdo->query("SELECT * FROM ia_metrics_log ORDER BY tiempo_inicio DESC");
                $metricasIA = $stmtMet->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (\Exception $e) {
            $metricasIA = [];
        }
    } else if ($section === 'ia_metrics') {
        header('Location: administracion.php');
        exit;
    }

} catch (\Exception $e) {
    error_log("Error en administracion: " . $e->getMessage());
    $err = "Error en base de datos: " . $e->getMessage();
    $flota = [];
    $totalAeronaves = 0;
    $aeronavesActivas = 0;
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Administración - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Panel de administración de AleSearchTool.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
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

        <a href="dashboard.php">
            <i class="fas fa-house"></i> Dashboard
        </a>

        <a href="registrar_falla.php">
            <i class="fas fa-pen-to-square"></i> Registrar Falla
        </a>

        <a href="consultar_fallas.php">
            <i class="fas fa-magnifying-glass"></i> Consultar Fallas
        </a>

        <a href="asistente_ia.php">
            <i class="fas fa-robot"></i> Asistente IA
        </a>

        <a href="administracion.php" class="active">
            <i class="fas fa-gear"></i> Administración
        </a>

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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Administración</h1>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <!-- Mensajes de Operación -->
        <?php if (!empty($msg)): ?>
            <div class="alert-success">
                <i class="fas fa-circle-check alert-icon"></i>
                <span><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($err)): ?>
            <div class="alert-danger">
                <i class="fas fa-circle-exclamation alert-icon"></i>
                <span><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($section === 'menu'): ?>
            
            <div class="admin-grid">
                
                <div class="admin-card" onclick="window.location.href='mis_favoritos.php'">
                    <i class="fas fa-folder-open" style="color: #f59e0b;"></i>
                    <h3>Mi Carpeta (Favoritos)</h3>
                    <p>Ver los reportes que has guardado con estrella.</p>
                </div>

                <?php if (in_array($userRole, ['Administrador', 'Ingeniero'])): ?>
                <div class="admin-card" onclick="window.location.href='administracion.php?section=flota'">
                    <i class="fas fa-plane" style="color: #0d3b8f;"></i>
                    <h3>Gestión de Flota</h3>
                    <p>Administrar aeronaves, modelos y estados de la flota.</p>
                </div>
                <?php endif; ?>

                <div class="admin-card" onclick="window.location.href='consumibles.php'">
                    <i class="fas fa-vials" style="color: #4f46e5;"></i>
                    <h3>Buscar N/P Consumible</h3>
                    <p>Consultar números de parte de aceites, sellantes, lubricantes y químicos.</p>
                </div>

                <?php if (in_array($userRole, ['Administrador', 'Ingeniero', 'Supervisor'], true)): ?>
                <div class="admin-card" onclick="window.location.href='gestion_base_datos.php'">
                    <i class="fas fa-database" style="color: #10b981;"></i>
                    <h3>Base de Datos de Fallas</h3>
                    <p>Editar reportes, corregir información y filtrar registros por rangos de fecha.</p>
                </div>
                <?php endif; ?>

                <?php if ($userRole === 'Administrador'): ?>
                <div class="admin-card" onclick="window.location.href='gestion_usuarios.php'">
                    <i class="fas fa-users-gear" style="color: #1a419c;"></i>
                    <h3>Gestión de Usuarios</h3>
                    <p>Dar de alta y baja personal institucional, asignar y editar roles de acceso.</p>
                </div>
                <?php endif; ?>

                <div class="admin-card" onclick="window.location.href='mels.php'">
                    <i class="fas fa-file-pdf" style="color: #ef4444;"></i>
                    <h3>Manuales MEL</h3>
                    <p>Visualizar, descargar y consultar la Lista de Equipamiento Mínimo de la flota.</p>
                </div>

                <?php if ((string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com'): ?>
                <div class="admin-card" onclick="window.location.href='administracion.php?section=estadistica_ia'">
                    <i class="fas fa-chart-line" style="color: #8b5cf6;"></i>
                    <h3>Estadística Asistente</h3>
                    <p>Métricas de uso y tiempos de consulta del Asistente de Inteligencia Artificial.</p>
                </div>

                <div class="admin-card" onclick="window.location.href='administracion.php?section=ia_metrics'">
                    <i class="fas fa-star" style="color: #f39c12;"></i>
                    <h3>Métricas del Agente IA</h3>
                    <p>Visualizar el panel aislado de calificaciones y retroalimentación de los usuarios.</p>
                </div>

                <div class="admin-card" onclick="window.location.href='administracion.php?section=configuracion'">
                    <i class="fas fa-sliders" style="color: #6366f1;"></i>
                    <h3>Configuración</h3>
                    <p>Actualizar variables de entorno, base de datos, accesos FTP y llaves de la IA de forma cifrada.</p>
                </div>
                <?php endif; ?>

            </div>

        <?php elseif ($section === 'flota'): ?>
            
            <div style="margin-bottom: 25px;">
                <a href="administracion.php" style="color: var(--brand-blue); font-weight: 700; text-decoration: none;"><i class="fas fa-arrow-left"></i> Volver al menú de administración</a>
            </div>

            <div class="status-card" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;">
                
                <!-- Registrar Aeronave -->
                <div>
                    <h2 style="color: var(--brand-blue); margin-bottom: 15px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-plus-circle"></i> Agregar Aeronave</h2>
                    <form method="POST" action="administracion.php?section=flota" style="display: flex; flex-direction: column; gap: 15px; border: none; padding: 0; box-shadow: none;">
                        <input type="hidden" name="action" value="add_aircraft">
                        
                        <div class="campo">
                            <label style="font-weight: 700;">Modelo de Aeronave</label>
                            <input type="text" name="modelo" placeholder="ej. Learjet 45, Challenger 605" required>
                        </div>

                        <div class="campo">
                            <label style="font-weight: 700;">Matrícula (Tail Number)</label>
                            <input type="text" name="matricula" placeholder="ej. XA-AAA" required>
                        </div>

                        <div class="campo">
                            <label style="font-weight: 700;">No. Serie (Opcional)</label>
                            <input type="text" name="numero_serie" placeholder="ej. 680A-0189">
                        </div>

                        <button type="submit"><i class="fas fa-save"></i> Registrar Aeronave</button>
                    </form>
                </div>

                <!-- Resumen de Flota -->
                <div>
                    <h2 style="color: var(--brand-blue); margin-bottom: 15px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-chart-pie"></i> Resumen Operativo</h2>
                    <div style="background-color: #fafbfc; padding: 24px; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 14px;">
                        <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--text-secondary);">
                            <span>Total de Aeronaves:</span>
                            <span style="color: var(--brand-blue); font-size: 18px; font-family: var(--font-titles);"><?php echo $totalAeronaves; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--text-secondary);">
                            <span>Aeronaves Activas:</span>
                            <span style="color: var(--color-green); font-size: 18px; font-family: var(--font-titles);"><?php echo $aeronavesActivas; ?></span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--text-secondary);">
                            <span>Aeronaves Inactivas:</span>
                            <span style="color: var(--color-red); font-size: 18px; font-family: var(--font-titles);"><?php echo ($totalAeronaves - $aeronavesActivas); ?></span>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Tabla de Flota -->
            <div class="table-container">
                <h2><i class="fas fa-plane-list"></i> Catálogo de Flota</h2>
                <table class="fallas-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Modelo</th>
                            <th>Matrícula</th>
                            <th>No. Serie</th>
                            <th>Estado</th>
                            <th>Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flota as $plane): ?>
                            <tr>
                                <td>#<?php echo $plane['id_flota']; ?></td>
                                <td style="font-weight: bold;"><?php echo htmlspecialchars((string)$plane['modelo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-weight: bold; color: var(--brand-blue);"><?php echo htmlspecialchars((string)$plane['matricula'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-weight: 600; color: #475569;"><?php echo htmlspecialchars((string)$plane['numero_serie'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                    <span style="padding: 4px 8px; border-radius: 20px; font-weight: bold; font-size: 11px; background-color: <?php echo ((int)$plane['es_activo'] === 1) ? '#ecfdf5; color: #065f46' : '#fef2f2; color: #991b1b'; ?>;">
                                        <?php echo ((int)$plane['es_activo'] === 1) ? 'Activo' : 'Inactivo'; ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="administracion.php?toggle_id=<?php echo $plane['id_flota']; ?>" style="color: var(--brand-blue); text-decoration: none; font-weight: bold;" title="Haga clic para activar o desactivar la aeronave">
                                        <i class="fas fa-right-left"></i> Cambiar Estado
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($section === 'estadistica_ia' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com'): ?>
            
            <div style="margin-bottom: 25px;">
                <a href="administracion.php" style="color: var(--brand-blue); font-weight: 700; text-decoration: none;"><i class="fas fa-arrow-left"></i> Volver al menú de administración</a>
            </div>

            <div class="status-card" style="margin-bottom: 30px;">
                <h2 style="color: var(--brand-blue); margin-bottom: 15px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-robot"></i> Uso Global del Asistente IA</h2>
                <div style="background-color: #fafbfc; padding: 24px; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 14px; width: 300px;">
                    <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--text-secondary);">
                        <span>Total de Consultas:</span>
                        <span style="color: var(--brand-blue); font-size: 24px; font-family: var(--font-titles);"><?php echo count($estadisticasIA); ?></span>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <h2><i class="fas fa-list"></i> Registro de Consultas</h2>
                <table class="fallas-table">
                    <thead>
                        <tr>
                            <th style="width: 80px; text-align: center;">ID Log</th>
                            <th>Usuario</th>
                            <th style="width: 180px; text-align: center;">Inicio</th>
                            <th style="width: 180px; text-align: center;">Término</th>
                            <th style="width: 130px; text-align: center;">Duración (seg)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($estadisticasIA)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 30px; color: #64748b;">No hay registros de consultas aún.</td></tr>
                        <?php else: ?>
                            <?php foreach ($estadisticasIA as $log): ?>
                            <tr>
                                <td style="text-align: center; color: #64748b; font-weight: 600;">#<?php echo htmlspecialchars((string)$log['id_log'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-weight: 600; color: #1e293b;"><?php echo htmlspecialchars((string)$log['usuario_correo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center; font-size: 13px;"><?php echo htmlspecialchars((string)$log['fecha_inicio'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center; font-size: 13px;"><?php echo htmlspecialchars((string)$log['fecha_termino'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="color: #059669; font-weight: bold; text-align: center;"><?php echo htmlspecialchars((string)$log['duracion_segundos'], ENT_QUOTES, 'UTF-8'); ?>s</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($section === 'ia_metrics' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com'): ?>
            
            <div style="margin-bottom: 25px;">
                <a href="administracion.php" style="color: var(--brand-blue); font-weight: 700; text-decoration: none;"><i class="fas fa-arrow-left"></i> Volver al menú de administración</a>
            </div>

            <div class="status-card" style="margin-bottom: 30px;">
                <h2 style="color: var(--brand-blue); margin-bottom: 15px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px;"><i class="fas fa-star" style="color: #f39c12;"></i> Métricas del Agente IA (Calificaciones)</h2>
                <div style="background-color: #fafbfc; padding: 24px; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 14px; width: 300px;">
                    <div style="display: flex; justify-content: space-between; font-weight: 700; color: var(--text-secondary);">
                        <span>Total de Calificaciones:</span>
                        <span style="color: var(--brand-blue); font-size: 24px; font-family: var(--font-titles);"><?php echo count($metricasIA); ?></span>
                    </div>
                </div>
            </div>

            <div class="table-container">
                <h2><i class="fas fa-list"></i> Registro de Calificaciones por Webhook</h2>
                <table class="fallas-table">
                    <thead>
                        <tr>
                            <th style="width: 120px; text-align: center;">ID Registro</th>
                            <th>Usuario</th>
                            <th style="width: 180px; text-align: center;">Inicio</th>
                            <th style="width: 180px; text-align: center;">Término</th>
                            <th style="width: 130px; text-align: center;">Estrellas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($metricasIA)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 30px; color: #64748b;">No hay registros de calificaciones recibidos vía webhook aún.</td></tr>
                        <?php else: ?>
                            <?php foreach ($metricasIA as $metrica): ?>
                            <tr>
                                <td style="text-align: center; color: #64748b; font-size: 11px; font-weight: 600;"><?php echo substr(htmlspecialchars((string)$metrica['registro_id'], ENT_QUOTES, 'UTF-8'), 0, 8) . '...'; ?></td>
                                <td style="font-weight: 600; color: #1e293b;">
                                    <?php echo htmlspecialchars((string)$metrica['nombre_usuario'], ENT_QUOTES, 'UTF-8'); ?><br>
                                    <small style="color: #64748b; font-weight: normal;"><?php echo htmlspecialchars((string)$metrica['correo_usuario'], ENT_QUOTES, 'UTF-8'); ?></small>
                                </td>
                                <td style="text-align: center; font-size: 13px;"><?php echo htmlspecialchars((string)$metrica['tiempo_inicio'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center; font-size: 13px;"><?php echo htmlspecialchars((string)$metrica['tiempo_fin'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center;">
                                    <?php
                                    $estrellas = (int)$metrica['calificacion_estrellas'];
                                    for ($i = 1; $i <= 5; $i++) {
                                        echo $i <= $estrellas 
                                            ? '<i class="fas fa-star" style="color: #f39c12;"></i>' 
                                            : '<i class="fas fa-star" style="color: #ccc;"></i>';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php elseif ($section === 'configuracion' && (string)SessionManager::get('user_email') === 'l.rodriguez@aleservicecenter.com'): ?>
            
            <div style="margin-bottom: 25px;">
                <a href="administracion.php" style="color: var(--brand-blue); font-weight: 700; text-decoration: none;"><i class="fas fa-arrow-left"></i> Volver al menú de administración</a>
            </div>

            <div class="status-card" style="margin-bottom: 30px;">
                <h2 style="color: var(--brand-blue); margin-bottom: 5px; font-family: 'Outfit', sans-serif;"><i class="fas fa-shield-halved"></i> Configuración Segura de Llaves y Credenciales</h2>
                <p style="color: #64748b; margin: 0; font-size: 14px;">La información que configures a continuación se guardará cifrada con algoritmo de nivel bancario **AES-256-CBC** y nunca será expuesta de forma pública o subida a GitHub.</p>
            </div>

            <form method="POST" action="administracion.php?section=configuracion" enctype="multipart/form-data" style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:30px; box-shadow:var(--shadow-sm); display:flex; flex-direction:column; gap:25px; margin-bottom: 40px;">
                
                <!-- Sección Base de Datos -->
                <div>
                    <h3 style="color:#1a419c; font-family:'Outfit', sans-serif; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:15px; font-size: 18px; font-weight: 700;"><i class="fas fa-database"></i> Conexión a Base de Datos (MySQL)</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px;">
                        <div class="campo">
                            <label style="font-weight:700;">Host / Servidor</label>
                            <input type="text" name="DB_HOST" value="<?php echo htmlspecialchars((string)$maskedValues['DB_HOST'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Puerto</label>
                            <input type="text" name="DB_PORT" value="<?php echo htmlspecialchars((string)$maskedValues['DB_PORT'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Nombre de la BD</label>
                            <input type="text" name="DB_NAME" value="<?php echo htmlspecialchars((string)$maskedValues['DB_NAME'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Usuario</label>
                            <input type="text" name="DB_USER" value="<?php echo htmlspecialchars((string)$maskedValues['DB_USER'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Contraseña</label>
                            <input type="password" name="DB_PASS" value="<?php echo htmlspecialchars((string)$maskedValues['DB_PASS'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Sección FTP -->
                <div>
                    <h3 style="color:#1a419c; font-family:'Outfit', sans-serif; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:15px; font-size: 18px; font-weight: 700;"><i class="fas fa-server"></i> Credenciales FTP (Despliegue)</h3>
                    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:15px;">
                        <div class="campo">
                            <label style="font-weight:700;">Servidor FTP</label>
                            <input type="text" name="FTP_HOST" value="<?php echo htmlspecialchars((string)$maskedValues['FTP_HOST'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Puerto FTP</label>
                            <input type="text" name="FTP_PORT" value="<?php echo htmlspecialchars((string)$maskedValues['FTP_PORT'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Usuario FTP</label>
                            <input type="text" name="FTP_USER" value="<?php echo htmlspecialchars((string)$maskedValues['FTP_USER'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Contraseña FTP</label>
                            <input type="password" name="FTP_PASS" value="<?php echo htmlspecialchars((string)$maskedValues['FTP_PASS'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                        <div class="campo">
                            <label style="font-weight:700;">Ruta Remota</label>
                            <input type="text" name="FTP_REMOTE_PATH" value="<?php echo htmlspecialchars((string)$maskedValues['FTP_REMOTE_PATH'], ENT_QUOTES, 'UTF-8'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Sección Gemini e IA -->
                <div>
                    <h3 style="color:#1a419c; font-family:'Outfit', sans-serif; border-bottom:1px solid #e2e8f0; padding-bottom:8px; margin-bottom:15px; font-size: 18px; font-weight: 700;"><i class="fas fa-robot"></i> Inteligencia Artificial y Claves API</h3>
                    <div style="display:flex; flex-direction:column; gap:15px;">
                        <div class="campo">
                            <label style="font-weight:700; display: inline-flex; align-items: center;">
                                GEMINI_API_KEY (API Key de Google Gemini)
                                <?php if (!empty($configValues['GEMINI_API_KEY'])): ?>
                                    <span style="background: #e6f4ea; color: #137333; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; margin-left: 10px; border: 1px solid #c2e7d9; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-check-circle"></i> Activa (Cifrada)</span>
                                <?php else: ?>
                                    <span style="background: #fce8e6; color: #c5221f; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; margin-left: 10px; border: 1px solid #fad2cf; display: inline-flex; align-items: center; gap: 4px;"><i class="fas fa-exclamation-circle"></i> Pendiente</span>
                                <?php endif; ?>
                            </label>
                            <input type="password" name="GEMINI_API_KEY" value="<?php echo htmlspecialchars((string)$maskedValues['GEMINI_API_KEY'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;" placeholder="Ingresa tu API Key de Google Gemini (e.g. AIzaSy...)">
                        </div>

                        <div class="campo">
                            <label style="font-weight:700;">Agente IT (Prompt Inicial de Comportamiento del Asistente)</label>
                            <textarea name="AGENTE_IT" style="width:100%; height:120px; font-size:14px; padding:12px; border:1px solid #cbd5e1; border-radius:8px; box-sizing:border-box; resize:vertical; background-color: #fafbfc; line-height: 1.5; transition: all 0.3s ease;" placeholder="Escribe el prompt de comportamiento inicial aquí..."><?php echo htmlspecialchars((string)$configValues['AGENTE_IT'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                            <p style="font-size:12px; color:#64748b; margin-top:5px; margin-bottom:0;"><i class="fas fa-info-circle"></i> Cualquier consulta que hagan los técnicos de mantenimiento al Asistente de IA estará pre-alimentada y condicionada por estas instrucciones iniciales.</p>
                        </div>
                    </div>
                </div>

                <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:15px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <button type="button" onclick="window.location.href='administracion.php'" style="background:#64748b; color:white; border:none; padding:12px 25px; border-radius:8px; cursor:pointer; font-weight:bold;"><i class="fas fa-times"></i> Cancelar</button>
                    <button type="submit" style="background:#1a419c; color:white; border:none; padding:12px 25px; border-radius:8px; cursor:pointer; font-weight:bold;"><i class="fas fa-save"></i> Guardar Cambios Cifrados</button>
                </div>
            </form>

        <?php endif; ?>

        <div class="footer-mantenimiento">
            <div class="mantenimiento-title">
                <span class="red-dashes"><span class="red-dash"></span><span class="red-dash"></span></span>
                MANTENIMIENTO
                <span class="red-dashes"><span class="red-dash"></span><span class="red-dash"></span></span>
            </div>
            <div class="mantenimiento-subtitle">
                SEGURIDAD &bull; CONFIANZA &bull; RENDIMIENTO
            </div>
        </div>

    </div>

    <!-- Modal Cambiar Contraseña -->
    <div id="modalPassword" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; align-items:center; justify-content:center;">
        <div style="background:white; padding:30px; border-radius:15px; width:400px; max-width:90%; box-shadow: 0 10px 25px rgba(0,0,0,0.2); border: 1px solid #cbd5e1;">
            <h3 style="margin-top:0; color:#0d3b8f; font-family: 'Outfit', sans-serif;"><i class="fas fa-key"></i> Cambiar Contraseña</h3>
            
            <div style="margin-top:15px; margin-bottom:15px; display: flex; flex-direction: column; gap: 8px;">
                <label style="font-weight:bold; color:#475569; font-size: 14px;">Contraseña Actual</label>
                <input type="password" id="pass_actual" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
            </div>
            
            <div style="margin-bottom:15px; display: flex; flex-direction: column; gap: 8px;">
                <label style="font-weight:bold; color:#475569; font-size: 14px;">Nueva Contraseña</label>
                <input type="password" id="pass_nueva" style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; box-sizing:border-box;">
            </div>
            
            <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px;">
                <button onclick="cerrarModalPassword()" style="background:#64748b; color:white; border:none; padding:10px 15px; border-radius:6px; cursor:pointer; font-weight:bold;">Cancelar</button>
                <button onclick="guardarPassword()" style="background:#0d3b8f; color:white; border:none; padding:10px 15px; border-radius:6px; cursor:pointer; font-weight:bold;">Guardar</button>
            </div>
        </div>
    </div>

    <script>
        function abrirModalPassword() {
            document.getElementById('modalPassword').style.display = 'flex';
        }
        function cerrarModalPassword() {
            document.getElementById('modalPassword').style.display = 'none';
            document.getElementById('pass_actual').value = '';
            document.getElementById('pass_nueva').value = '';
        }
        function guardarPassword() {
            const actual = document.getElementById('pass_actual').value;
            const nueva = document.getElementById('pass_nueva').value;
            if(!actual || !nueva) {
                alert("Por favor llena ambos campos.");
                return;
            }
            fetch('api_cambiar_password.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({actual: actual, nueva: nueva})
            })
            .then(r => r.json())
            .then(data => {
                if(data.success) {
                    alert("Contraseña actualizada correctamente.");
                    cerrarModalPassword();
                } else {
                    alert("Error: " + (data.error || data.message || "No autorizado."));
                }
            })
            .catch(e => {
                console.error(e);
                alert("Error de red.");
            });
        }

    </script>
</body>

</html>
