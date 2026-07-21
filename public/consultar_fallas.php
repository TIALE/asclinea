<?php

declare(strict_types=1);

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

// Cargar flota para el modo edición (Ingeniero)
$flotaMap = [];
try {
    $pdoFlota = DatabaseConnection::getConnection();
    $stmtFlota = $pdoFlota->query("SELECT modelo, matricula FROM tbc_Flota WHERE es_activo = 1 ORDER BY modelo, matricula");
    while ($rowFlota = $stmtFlota->fetch(PDO::FETCH_ASSOC)) {
        $flotaMap[$rowFlota['modelo']][] = $rowFlota['matricula'];
    }
} catch (\Exception $e) {
    error_log("Error cargando flota en consultar_fallas: " . $e->getMessage());
}

// Capturar parámetros de búsqueda y favoritos
$q           = trim((string)filter_input(INPUT_GET, 'q',           FILTER_DEFAULT));
$filterMat   = trim((string)filter_input(INPUT_GET, 'matricula',   FILTER_DEFAULT));
$filterAta   = trim((string)filter_input(INPUT_GET, 'ata',         FILTER_DEFAULT));
$filterMod   = trim((string)filter_input(INPUT_GET, 'modelo',      FILTER_DEFAULT));
$filterRange = trim((string)filter_input(INPUT_GET, 'range',       FILTER_DEFAULT));
$fechaDesde  = trim((string)filter_input(INPUT_GET, 'fecha_desde', FILTER_DEFAULT));
$fechaHasta  = trim((string)filter_input(INPUT_GET, 'fecha_hasta', FILTER_DEFAULT));
$filterId    = trim((string)filter_input(INPUT_GET, 'id',          FILTER_DEFAULT));
if (empty($filterId)) {
    $filterId = trim((string)filter_input(INPUT_GET, 'id_falla',   FILTER_DEFAULT));
}

$sortDate = trim((string)filter_input(INPUT_GET, 'sort_date', FILTER_DEFAULT));
if ($sortDate !== 'ASC') {
    $sortDate = 'DESC';
}

$fallas = [];
$misBookmarks = [];

try {
    $pdo = DatabaseConnection::getConnection();
    
    // Obtener marcadores existentes del usuario
    $stmtBk = $pdo->prepare("SELECT id_falla FROM tbo_CarpetaUsuario WHERE usuario_nombre = :user");
    $stmtBk->execute([':user' => $userName]);
    $misBookmarks = $stmtBk->fetchAll(PDO::FETCH_COLUMN);

    // Construir consulta dinámica parametrizada
    $sql = "SELECT id_falla, modelo, matricula, ata, fecha, descripcion, accion_correctiva, referencia, tips, condicion, folio, mel AS capitulo_mel, categoria_mel, base, registrado_por, horas, ciclos, tiempo_atencion, componente_cambiado, comp_removido_np, comp_removido_ns, comp_instalado_np, comp_instalado_ns, comp2_removido_np, comp2_removido_ns, comp2_instalado_np, comp2_instalado_ns, componentes_adicionales FROM tbo_Falla WHERE 1=1";
    $params = [];

    if (!empty($q)) {
        $sql .= " AND (descripcion LIKE :q1 OR accion_correctiva LIKE :q2 OR folio LIKE :q3 OR referencia LIKE :q4 OR tips LIKE :q5)";
        $likeQ = "%{$q}%";
        $params[':q1'] = $likeQ;
        $params[':q2'] = $likeQ;
        $params[':q3'] = $likeQ;
        $params[':q4'] = $likeQ;
        $params[':q5'] = $likeQ;
    }

    if (!empty($filterMat)) {
        $sql .= " AND matricula LIKE :matricula";
        $params[':matricula'] = "%{$filterMat}%";
    }

    if (!empty($filterAta)) {
        // Soporta tanto códigos parciales (ej: 27) como completos (ej: 27 - Flight Controls)
        $sql .= " AND ata LIKE :ata";
        $params[':ata'] = "{$filterAta}%";
    }

    if (!empty($filterMod)) {
        $sql .= " AND modelo = :modelo";
        $params[':modelo'] = $filterMod;
    }

    if (!empty($filterId)) {
        $sql .= " AND id_falla = :id_falla";
        $params[':id_falla'] = $filterId;
    }

    // Filtrado de fecha heredado de la gráfica del dashboard
    if ($filterRange === '3_months') {
        $sql .= " AND fecha >= date('now', '-3 months')";
    } elseif ($filterRange === '6_months') {
        $sql .= " AND fecha >= date('now', '-6 months')";
    } elseif ($filterRange === '1_year') {
        $sql .= " AND fecha >= date('now', '-1 year')";
    }

    // Filtrado por rango de fecha personalizado
    if (!empty($fechaDesde)) {
        $sql .= " AND fecha >= :fecha_desde";
        $params[':fecha_desde'] = $fechaDesde;
    }
    if (!empty($fechaHasta)) {
        $sql .= " AND fecha <= :fecha_hasta";
        $params[':fecha_hasta'] = $fechaHasta;
    }

    if ($sortDate === 'ASC') {
        $sql .= " ORDER BY fecha ASC, id_falla ASC";
    } else {
        $sql .= " ORDER BY fecha DESC, id_falla DESC";
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fallas = $stmt->fetchAll(PDO::FETCH_NUM); // Usamos FETCH_NUM para coincidir con la estructura iterada de los templates originales

} catch (\Exception $e) {
    error_log("Error en consultar_fallas: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Consultar Fallas - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Buscador avanzado corporativo del historial de fallas mecánicas de AleSearchTool.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Google Fonts para tipografía premium unificada -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <!-- Estilización Premium Adicional para Consultar Fallas -->
    <style>
        .search-card {
            border: none !important; /* Quitamos el marco que rodeaba la tarjeta de búsqueda */
            box-shadow: 0 10px 30px rgba(26, 65, 156, 0.08) !important; /* Sombra premium más suave */
        }
        
        .search-input-wrapper label {
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700 !important;
            color: #1a419c !important; /* Azul corporativo unificado */
            font-size: 14px !important;
            letter-spacing: 0.2px;
            margin-bottom: 6px;
        }

        .search-input-wrapper input {
            border-radius: 8px !important;
            border: 1px solid #cbd5e1 !important;
            padding: 12px 15px !important;
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            font-size: 14px !important;
            transition: all 0.2s ease-in-out !important;
            background-color: #ffffff !important;
        }

        .search-input-wrapper input:focus {
            border-color: #1a419c !important;
            box-shadow: 0 0 0 3px rgba(26, 65, 156, 0.15) !important;
            outline: none !important;
        }

        /* Botón de Buscar centralizado */
        .btn-buscar {
            background-color: #1a419c !important;
            color: #ffffff !important;
            border-radius: 8px !important;
            padding: 12px 35px !important;
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700 !important;
            font-size: 15px !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.25s ease !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }

        .btn-buscar:hover {
            background-color: #112e75 !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 4px 10px rgba(26, 65, 156, 0.2) !important;
        }

        /* Tarjeta del total de registros */
        .total-card {
            border: none !important; /* Quitamos el marco que rodeaba la tarjeta de totales */
            box-shadow: 0 10px 30px rgba(26, 65, 156, 0.08) !important; /* Sombra premium más suave */
            position: relative;
            background: #ffffff !important;
            overflow: hidden;
            border-radius: 20px !important; /* Bordes redondeados idénticos a la búsqueda */
            
            /* Centrado vertical y horizontal del contenido */
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
            padding: 30px 20px !important;
        }

        .total-card-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('assets/images/fondo_jets.png') !important;
            background-size: cover !important; /* Estira y expande de esquina a esquina cubriendo toda la tarjeta */
            background-position: center !important;
            background-repeat: no-repeat !important;
            opacity: 0.15 !important; /* Desvanecido traslúcido suave e idéntico a la captura de pantalla */
            z-index: 1;
        }

        /* Estilo para truncar texto largo en la tabla de datos */
        .text-clamp {
            display: -webkit-box !important;
            -webkit-line-clamp: 2 !important; /* Límite de exactamente 2 líneas */
            -webkit-box-orient: vertical !important;
            overflow: hidden !important;
            text-overflow: ellipsis !important;
            line-height: 1.4 !important;
            word-break: break-word !important;
            cursor: pointer !important;
            pointer-events: none !important;
        }

        /* ==========================================================================
           DISEÑO DE MODAL DETALLE DE FALLAS - CLARO PREMIUM (ALENSEARCHTOOL THEME)
           ========================================================================== */
        .modal-overlay {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            background-color: rgba(3, 20, 51, 0.5) !important; /* Azul marino translúcido suave */
            backdrop-filter: blur(8px) !important; /* Efecto desenfoque premium */
            z-index: 1000 !important;
            display: flex !important;
            justify-content: center !important;
            align-items: center !important;
            opacity: 0 !important;
            pointer-events: none !important;
            transition: opacity 0.3s ease !important;
        }

        .modal-overlay.active {
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        .modal-container {
            background-color: #ffffff !important;
            width: 90% !important;
            max-width: 750px !important;
            max-height: 85vh !important;
            border-radius: 18px !important;
            box-shadow: 0 15px 40px rgba(3, 20, 51, 0.15) !important;
            border: 1px solid #e2e8f0 !important;
            overflow-y: auto !important;
            transform: scale(0.9) !important;
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
            z-index: 1001 !important;
            display: flex !important;
            flex-direction: column !important;
        }

        .modal-overlay.active .modal-container {
            transform: scale(1) !important;
        }

        .modal-header {
            padding: 20px 24px !important;
            border-bottom: 1px solid #e2e8f0 !important;
            display: flex !important;
            justify-content: space-between !important;
            align-items: center !important;
            background: #f8fafc !important;
            position: sticky !important;
            top: 0 !important;
            z-index: 10 !important;
        }

        .modal-header h2 {
            font-family: 'Outfit', sans-serif !important;
            font-weight: 800 !important;
            color: #1a419c !important;
            margin: 0 !important;
            font-size: 22px !important;
        }

        .btn-modal-close {
            background: none !important;
            border: none !important;
            font-size: 20px !important;
            color: #64748b !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 32px !important;
            height: 32px !important;
            border-radius: 50% !important;
        }

        .btn-modal-close:hover {
            color: #e11d48 !important;
            background-color: #ffe4e6 !important;
        }

        .modal-body {
            padding: 24px !important;
            display: flex !important;
            flex-direction: column !important;
            gap: 20px !important;
            overflow-y: auto !important;
        }

        .modal-meta-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)) !important;
            gap: 15px !important;
            background-color: #f8fafc !important;
            padding: 16px !important;
            border-radius: 12px !important;
            border: 1px solid #e2e8f0 !important;
        }

        .meta-item {
            display: flex !important;
            flex-direction: column !important;
            gap: 4px !important;
        }

        .meta-label {
            font-size: 11px !important;
            font-weight: 700 !important;
            color: #64748b !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
        }

        .meta-value {
            font-size: 14px !important;
            font-weight: 700 !important;
            color: #1e293b !important;
        }

        .modal-section {
            display: flex !important;
            flex-direction: column !important;
            gap: 8px !important;
        }

        .modal-section-title {
            font-family: 'Outfit', sans-serif !important;
            font-size: 14px !important;
            font-weight: 800 !important;
            color: #1a419c !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
        }

        .modal-section-content {
            background-color: #f8fafc !important;
            border: 1px solid #e2e8f0 !important;
            padding: 16px 20px !important;
            border-radius: 12px !important;
            font-size: 14px !important;
            line-height: 1.6 !important;
            color: #334155 !important;
            word-break: break-word !important;
            white-space: pre-wrap !important;
        }

        .total-info {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .total-info h3 {
            font-family: 'Outfit', sans-serif !important;
            font-size: 19px !important;
            font-weight: 800 !important;
            color: #031433 !important; /* Azul marino profundo e impecable */
            text-transform: uppercase !important;
            letter-spacing: 2px !important;
            margin: 0 !important;
            text-shadow: none !important; /* Limpio, sin sombras raras */
        }

        .total-info .number {
            font-family: 'Outfit', sans-serif !important;
            font-size: 82px !important;
            font-weight: 800 !important;
            color: #1a419c !important; /* Azul corporativo idéntico a la captura */
            margin: 6px 0 !important;
            line-height: 1 !important;
            text-shadow: none !important;
        }

        .total-info .label {
            font-family: 'Plus Jakarta Sans', sans-serif !important;
            color: #5c6b8c !important; /* Gris azulado acero */
            font-size: 13px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: 4px !important;
            margin: 0 !important;
            text-shadow: none !important;
        }

        /* Botón de Exportar Seleccionados a PDF */
        .btn-export-pdf {
            background-color: #cc2d4a !important; /* Rojo idéntico de la captura */
            color: #ffffff !important;
            padding: 10px 20px !important;
            border-radius: 8px !important;
            border: none !important;
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            transition: all 0.2s ease-in-out !important;
            box-shadow: 0 3px 6px rgba(204, 45, 74, 0.15) !important;
        }

        .btn-export-pdf:hover {
            background-color: #b51c37 !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 5px 12px rgba(204, 45, 74, 0.25) !important;
        }

        /* Botón de Guardar en Mi Carpeta */
        .btn-save-folder {
            background: linear-gradient(135deg, #1a419c, #3b82f6) !important;
            color: #ffffff !important;
            padding: 10px 20px !important;
            border-radius: 8px !important;
            border: none !important;
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700 !important;
            font-size: 14px !important;
            cursor: pointer !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
            transition: all 0.2s ease-in-out !important;
            box-shadow: 0 3px 6px rgba(26, 65, 156, 0.15) !important;
        }

        .btn-save-folder:hover {
            background: linear-gradient(135deg, #14327e, #2563eb) !important;
            transform: translateY(-1px) !important;
            box-shadow: 0 5px 12px rgba(26, 65, 156, 0.25) !important;
        }

        /* Contenedor de la tabla */
        .table-container {
            background: #ffffff !important;
            border-radius: 12px !important;
            border: 1px solid #e2e8f0 !important;
            box-shadow: 0 4px 15px rgba(26, 65, 156, 0.04) !important;
            padding: 0 !important;
            overflow-x: auto !important; /* Habilitar scroll horizontal */
            overflow-y: hidden !important;
            margin-top: 20px !important;
        }

        /* Estilos Premium para la barra de desplazamiento horizontal */
        .table-container::-webkit-scrollbar {
            height: 8px !important;
        }
        .table-container::-webkit-scrollbar-track {
            background: #f8fafc !important;
            border-bottom-left-radius: 12px !important;
            border-bottom-right-radius: 12px !important;
        }
        .table-container::-webkit-scrollbar-thumb {
            background: #cbd5e1 !important;
            border-radius: 4px !important;
        }
        .table-container::-webkit-scrollbar-thumb:hover {
            background: #94a3b8 !important;
        }

        /* Estilos de la tabla de fallas rediseñada */
        .fallas-table {
            width: 100% !important;
            min-width: 1400px !important; /* Asegurar ancho de columnas y desplazamiento */
            border-collapse: collapse !important;
            margin-top: 0 !important;
            font-family: 'Plus Jakarta Sans', sans-serif !important;
        }

        .fallas-table th {
            background-color: #5b9bd5 !important; /* Celeste metálico de la captura */
            color: #ffffff !important;
            font-family: 'Outfit', sans-serif !important;
            font-weight: 700 !important;
            font-size: 13px !important;
            padding: 12px 10px !important;
            border: 1px solid #4d8cc4 !important;
            text-align: center !important;
            vertical-align: middle !important;
        }

        .fallas-table td {
            padding: 12px 10px !important;
            font-size: 13px !important;
            border: 1px solid #cbd5e1 !important; /* Grid celeste claro visible */
            color: #1e293b !important;
            background-color: #ffffff !important;
            vertical-align: middle !important;
        }

        .fallas-table tr:nth-child(even) td {
            background-color: #f3f6fd !important;
        }

        .fallas-table tr:hover td {
            background-color: #e2e8f0 !important;
        }

        /* Estilo para las matrículas en negrita azul */
        .td-matricula {
            font-weight: 700 !important;
            color: #1a419c !important;
            text-align: center !important;
        }

        /* IDs centrados y limpios */
        .td-id {
            text-align: center !important;
            font-weight: 600 !important;
            color: #475569 !important;
        }

        .td-modelo, .td-fecha, .td-condicion, .td-folio, .td-cb {
            text-align: center !important;
        }

        /* Mayúsculas en descripciones y acciones */
        .td-mayuscula {
            text-transform: uppercase !important;
            line-height: 1.4 !important;
            color: #334155 !important;
            font-weight: 500 !important;
            text-align: left !important;
        }

        /* Columna de tips con texto de color azul premium */
        .td-tips {
            color: #1a419c !important;
            font-weight: 600 !important;
            text-align: left !important;
        }

        .td-mel, .td-base, .td-registrado, .td-carpeta {
            text-align: center !important;
        }

        .btn-star {
            background: none !important;
            border: none !important;
            font-size: 18px !important;
            cursor: pointer !important;
            transition: transform 0.15s ease !important;
        }

        .btn-star:hover {
            transform: scale(1.25) !important;
        }

        /* Checkbox simétrico centralizado */
        .row-checkbox, .select-all-checkbox {
            width: 16px !important;
            height: 16px !important;
            cursor: pointer !important;
            accent-color: #1a419c !important;
            vertical-align: middle !important;
        }

        /* Estilos de impresión optimizados (PDF) */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .sidebar, .top-cards-container, .btn-export-pdf, .header-logo, .btn-clear-inside, .logo-empresa, form, .sidebar-footer {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
                width: 100% !important;
            }
            .no-print-row {
                display: none !important;
            }
            .table-container {
                box-shadow: none !important;
                border: none !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: visible !important;
            }
            .fallas-table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            .fallas-table th {
                background-color: #5b9bd5 !important;
                color: #ffffff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                border: 1px solid #4a8bc2 !important;
                font-size: 11px !important;
                padding: 6px 4px !important;
            }
            .fallas-table td {
                border: 1px solid #94a3b8 !important;
                padding: 6px 4px !important;
                font-size: 11px !important;
                background-color: #ffffff !important;
            }
            /* Ocultar celdas de checkbox en la impresión */
            .col-cb, .td-cb {
                display: none !important;
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

        <a href="dashboard.php">
            <i class="fas fa-house"></i> Dashboard
        </a>

        <?php if ($userRole !== 'Técnico'): ?>
        <a href="registrar_falla.php">
            <i class="fas fa-pen-to-square"></i> Registrar Falla
        </a>
        <?php endif; ?>

        <a href="consultar_fallas.php" class="active">
            <i class="fas fa-magnifying-glass"></i> Consultar Fallas
        </a>

        <a href="asistente_ia.php">
            <i class="fas fa-robot"></i> Asistente IA
        </a>

        <a href="administracion.php">
            <i class="fas fa-gear"></i> Administración y mas
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
            <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Consultar Fallas</h1>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <div class="top-cards-container" style="display: flex; gap: 20px; margin-top: 20px; margin-bottom: 30px; align-items: stretch; flex-wrap: wrap;">
            
            <!-- SEARCH CARD (REDUCIDO HORIZONTALMENTE Y MÁS COMPACTO) -->
            <div class="search-card" style="flex: 1; width: 50%; max-width: 450px; background: white; padding: 25px 30px; border-radius: 20px; min-width: 300px; box-sizing: border-box;">
                <?php if (!empty($filterMod) || !empty($fechaDesde) || !empty($fechaHasta) || !empty($filterId)): ?>
                    <?php
                    // Construir la URL para regresar al Dashboard conservando el estado analítico exacto (modelo, ATA, rango de tiempo)
                    $backToDashboardUrl = 'dashboard.php';
                    $backParams = [];
                    if (!empty($filterMod)) {
                        $backParams['modelo'] = $filterMod;
                    }
                    if (!empty($filterAta)) {
                        $backParams['ata'] = $filterAta;
                    }
                    if (!empty($filterRange)) {
                        $backParams['range'] = $filterRange;
                    }
                    if (!empty($backParams)) {
                        $backToDashboardUrl .= '?' . http_build_query($backParams);
                    }
                    ?>
                    <div style="background-color: #eef2ff; border: 1px solid #c7d2fe; color: #1e3a8a; padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 13px; display: flex; align-items: center; justify-content: space-between; gap: 15px; flex-wrap: wrap; box-shadow: var(--shadow-sm);">
                        <span>
                            <i class="fas fa-info-circle" style="margin-right: 6px; color: #1a419c; font-size: 15px;"></i>
                            <?php if (!empty($filterId)): ?>
                                Reporte específico: <strong>#<?php echo htmlspecialchars($filterId, ENT_QUOTES, 'UTF-8'); ?></strong>
                            <?php endif; ?>
                            <?php if (!empty($filterMod)): ?>
                                <?php if (!empty($filterId)) echo ' &mdash; '; ?>
                                Resultados pre-filtrados desde el <strong>Dashboard</strong> para el modelo: <strong><?php echo htmlspecialchars($filterMod, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php if (!empty($filterRange) && $filterRange !== 'todo'): ?>
                                    (Filtro: <strong><?php echo $filterRange === '3_months' ? '3 meses' : ($filterRange === '6_months' ? '6 meses' : '1 año'); ?></strong>)
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (!empty($fechaDesde) || !empty($fechaHasta)): ?>
                                <?php if (!empty($filterMod) || !empty($filterId)) echo ' &mdash; '; ?>
                                <i class="fas fa-calendar-range" style="margin-right: 4px;"></i>
                                Rango de fecha: <strong><?php echo !empty($fechaDesde) ? htmlspecialchars(date('d/m/Y', strtotime($fechaDesde)), ENT_QUOTES, 'UTF-8') : '---'; ?></strong>
                                &nbsp;→&nbsp;
                                <strong><?php echo !empty($fechaHasta) ? htmlspecialchars(date('d/m/Y', strtotime($fechaHasta)), ENT_QUOTES, 'UTF-8') : 'Hoy'; ?></strong>
                            <?php endif; ?>
                        </span>
                        <div style="display: flex; align-items: center; gap: 15px; flex-shrink: 0;">
                            <a href="<?php echo htmlspecialchars($backToDashboardUrl, ENT_QUOTES, 'UTF-8'); ?>" style="background: #ffffff; border: 1px solid #1a419c; color: #1a419c; padding: 6px 12px; border-radius: 6px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 12.5px; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; box-shadow: var(--shadow-sm);" onmouseover="this.style.backgroundColor='#1a419c'; this.style.color='#ffffff';" onmouseout="this.style.backgroundColor='#ffffff'; this.style.color='#1a419c';">
                                <i class="fas fa-arrow-left"></i> Volver al Dashboard
                            </a>
                            <a href="consultar_fallas.php" style="color: #e11d48; font-weight: bold; text-decoration: none; font-size: 12px; display: flex; align-items: center; gap: 4px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                                <i class="fas fa-trash-can"></i> Quitar filtros
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="GET" action="consultar_fallas.php" style="margin: 0; padding: 0; background: transparent; box-shadow: none; border: none !important; border-radius: 0; max-width: none; display: flex; flex-direction: column; gap: 15px;">
                    <!-- Retener filtros avanzados de rango de fecha si vienen del Dashboard -->
                    <?php if (!empty($filterRange)): ?>
                        <input type="hidden" name="range" value="<?php echo htmlspecialchars($filterRange, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    
                    <div class="search-input-wrapper" style="display: flex; flex-direction: column;">
                        <label>Buscar por Modelo</label>
                        <select name="modelo" style="border-radius: 8px !important; border: 1px solid #cbd5e1 !important; padding: 12px 15px !important; font-family: 'Plus Jakarta Sans', sans-serif !important; font-size: 14px !important; transition: all 0.2s ease-in-out !important; background-color: #ffffff !important;">
                            <option value="">Todos los Modelos</option>
                            <?php foreach (array_keys($flotaMap) as $mod): ?>
                                <option value="<?php echo htmlspecialchars((string)$mod, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filterMod === (string)$mod ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$mod, ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="search-input-wrapper" style="display: flex; flex-direction: column;">
                        <label>Filtrar por Matrícula</label>
                        <input type="text" name="matricula" placeholder="Ej. XA-MXJ" value="<?php echo htmlspecialchars($filterMat, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="search-input-wrapper" style="display: flex; flex-direction: column;">
                        <label>Filtrar por ATA</label>
                        <input type="text" name="ata" placeholder="Ej. 23" value="<?php echo htmlspecialchars($filterAta, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <!-- Filtro por Rango de Fecha -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="search-input-wrapper" style="display: flex; flex-direction: column;">
                            <label>Fecha Desde</label>
                            <input type="date" name="fecha_desde" value="<?php echo htmlspecialchars($fechaDesde, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px !important; font-size: 13px !important;">
                        </div>
                        <div class="search-input-wrapper" style="display: flex; flex-direction: column;">
                            <label>Fecha Hasta</label>
                            <input type="date" name="fecha_hasta" value="<?php echo htmlspecialchars($fechaHasta, ENT_QUOTES, 'UTF-8'); ?>" style="padding: 10px 12px !important; font-size: 13px !important;">
                        </div>
                    </div>

                    <div class="search-input-wrapper" style="display: flex; flex-direction: column;">
                        <label>Buscar por Palabra Clave</label>
                        <div style="position: relative; display: flex; width: 100%;">
                            <input type="text" id="searchInput" name="q" placeholder="Buscar en descripciones, acciones correctivas o referencias..." value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>" style="width: 100%;">
                            <?php if (!empty($q) || !empty($filterMat) || !empty($filterAta) || !empty($filterMod) || !empty($filterRange) || !empty($fechaDesde) || !empty($fechaHasta)): ?>
                                <a href="consultar_fallas.php" id="clearSearchBtn" class="btn-clear-inside" title="Borrar búsqueda" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #e11d48; text-decoration: none; font-size: 16px; padding: 5px;"><i class="fas fa-times"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="display: flex; justify-content: center; width: 100%; margin-top: 5px;">
                        <button type="submit" class="btn-buscar"><i class="fas fa-magnifying-glass"></i> Buscar</button>
                    </div>

                </form>
            </div>

            <!-- TOTAL CARD (IGUAL DE ANCHO Y PERFECTAMENTE PROPORCIONADO) -->
            <div class="total-card" style="flex: 1; width: 50%; max-width: 450px; min-width: 300px; box-sizing: border-box;">
                <div class="total-card-bg"></div>
                <div class="total-info">
                    <h3>Total de Fallas</h3>
                    <div class="number"><?php echo count($fallas); ?></div>
                    <div class="label">Registros</div>
                </div>
            </div>

        </div>

        <!-- CONTROLES SUPERIORES DE TABLA (ORDEN Y ACCIONES) -->
        <div style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            
            <!-- BOTON ORDENAR (IZQUIERDA) -->
            <div style="display: flex; gap: 10px; align-items: center;">
                <?php 
                $nextSort = ($sortDate === 'DESC') ? 'ASC' : 'DESC';
                $sortIcon = ($sortDate === 'DESC') ? 'fa-arrow-down' : 'fa-arrow-up';
                $sortParams = $_GET;
                $sortParams['sort_date'] = $nextSort;
                $sortUrl = '?' . http_build_query($sortParams);
                ?>
                <a href="<?php echo htmlspecialchars($sortUrl, ENT_QUOTES, 'UTF-8'); ?>" style="background: #ffffff; border: 1px solid #1a419c; color: #1a419c; padding: 10px 15px; border-radius: 8px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; box-shadow: 0 2px 4px rgba(26,65,156,0.1);" onmouseover="this.style.backgroundColor='#1a419c'; this.style.color='#ffffff';" onmouseout="this.style.backgroundColor='#ffffff'; this.style.color='#1a419c';">
                    <i class="fas fa-calendar-day"></i> Fecha <i class="fas <?php echo $sortIcon; ?>" style="margin-left:4px;"></i>
                </a>
            </div>

            <!-- BOTONES DE ACCIÓN (DERECHA) -->
            <div style="display: flex; justify-content: flex-end; gap: 12px; align-items: center; flex-wrap: wrap;">
                <button onclick="guardarEnCarpeta()" class="btn-save-folder">
                    <i class="fas fa-folder-plus"></i> Guardar en Mi Carpeta
                </button>
                <button onclick="exportarSeleccionados()" class="btn-export-pdf">
                    <i class="fas fa-file-invoice"></i> Exportar Seleccionados a PDF
                </button>
                <?php if (in_array($userRole, ['Ingeniero', 'Supervisor', 'Administrador'], true)): ?>
                <button onclick="exportarExcel()" id="btnExportExcel" style="background: linear-gradient(135deg, #16a34a, #22c55e); color: #ffffff; padding: 10px 20px; border-radius: 8px; border: none; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease-in-out; box-shadow: 0 3px 6px rgba(22, 163, 74, 0.2);" onmouseover="this.style.background='linear-gradient(135deg, #15803d, #16a34a)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.background='linear-gradient(135deg, #16a34a, #22c55e)'; this.style.transform='translateY(0)';">
                    <i class="fas fa-file-excel"></i> Exportar a Excel
                </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- CONTENEDOR DE LA TABLA -->
        <div class="table-container">
            <table class="fallas-table">
                <thead>
                    <tr>
                        <th class="col-cb" style="width: 40px; text-align: center;">
                            <input type="checkbox" id="selectAll" class="select-all-checkbox" title="Seleccionar todos">
                        </th>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 80px;">Modelo</th>
                        <th style="width: 90px;">Matrícula</th>
                        <th style="width: 60px;">ATA</th> <!-- Columna ATA muy delgada -->
                        <th style="width: 100px;">Fecha</th>
                        <th style="min-width: 205px;">Descripción de Reporte</th>
                        <th style="min-width: 205px;">Acción Correctiva</th>
                        <th style="width: 280px;">Tips</th> <!-- Columna de Tips reducida 1 cm -->
                        <th style="width: 130px;">Referencia</th>
                        <th style="width: 150px;">MEL Ref / Cat</th> <!-- Columnas combinadas -->
                        <th style="width: 100px; text-align: center;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($fallas)): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px; color: #64748b; font-weight: 500;">
                                No se encontraron registros de fallas para los filtros seleccionados.
                            </td>
                        </tr>
                    <?php else: 
                        $fallasJsData = [];
                    ?>
                        <?php foreach ($fallas as $falla): 
                            $idFalla = (string)$falla[0];
                            
                            // Formatear fecha a DD/MM/YYYY
                            $fechaDb = (string)$falla[4];
                            $fechaFormateada = "";
                            if (!empty($fechaDb)) {
                                $ts = strtotime($fechaDb);
                                if ($ts !== false) {
                                    $fechaFormateada = date('d/m/Y', $ts);
                                } else {
                                    $fechaFormateada = $fechaDb;
                                }
                            }
                            
                            // Normalizar Logbook Folio (columna 10)
                            $folio = trim((string)$falla[10]);
                            if (empty($folio)) {
                                $folio = "----";
                            }

                            // Extraer solo el número de ATA para visualización delgada
                            $ataFull = (string)$falla[3];
                            $ataParts = explode('-', $ataFull);
                            $ataNum = trim($ataParts[0]);

                            // Preparar valores combinados de MEL Ref y Cat / Restricciones
                            $melVal = trim((string)$falla[11]);
                            $catVal = trim((string)$falla[12]);
                            $melCombined = 'N/A';
                            if (!empty($melVal) || !empty($catVal)) {
                                $melCombined = ($melVal ?: 'N/A') . ' / ' . ($catVal ?: 'N/A');
                            }

                            $fallaDataForJs = [
                                'id' => $idFalla,
                                'modelo' => (string)$falla[1],
                                'matricula' => (string)$falla[2],
                                'ata' => $ataFull, // Mantenemos el ATA completo para el modal
                                'fecha' => $fechaFormateada,
                                'descripcion' => (string)$falla[5],
                                'accion' => (string)$falla[6],
                                'referencia' => (string)$falla[7],
                                'tips' => (string)$falla[8],
                                'condicion' => (string)$falla[9],
                                'folio' => $folio,
                                'mel' => (string)$falla[11],
                                'categoria_mel' => (string)$falla[12],
                                'base' => (string)$falla[13],
                                'registrado_por' => (string)$falla[14],
                                'horas' => isset($falla[15]) && $falla[15] !== null ? (string)$falla[15] : '----',
                                'ciclos' => isset($falla[16]) && $falla[16] !== null ? (string)$falla[16] : '----',
                                'tiempo_atencion' => isset($falla[17]) && $falla[17] !== null ? (string)$falla[17] : '----',
                                'componente_cambiado' => isset($falla[18]) ? (string)$falla[18] : 'No',
                                'comp_removido_np' => isset($falla[19]) ? (string)$falla[19] : 'N/A',
                                'comp_removido_ns' => isset($falla[20]) ? (string)$falla[20] : 'N/A',
                                'comp_instalado_np' => isset($falla[21]) ? (string)$falla[21] : 'N/A',
                                'comp_instalado_ns' => isset($falla[22]) ? (string)$falla[22] : 'N/A',
                                'comp2_removido_np' => isset($falla[23]) ? (string)$falla[23] : null,
                                'comp2_removido_ns' => isset($falla[24]) ? (string)$falla[24] : null,
                                'comp2_instalado_np' => isset($falla[25]) ? (string)$falla[25] : null,
                                'comp2_instalado_ns' => isset($falla[26]) ? (string)$falla[26] : null,
                                'componentes_adicionales' => isset($falla[27]) ? json_decode((string)$falla[27], true) : null
                            ];
                            $fallasJsData[$idFalla] = $fallaDataForJs;
                        ?>
                            <tr class="falla-row" data-id="<?php echo $idFalla; ?>" style="cursor: pointer;" onclick='if(!event.target.closest(".td-cb") && !event.target.closest("button") && !event.target.classList.contains("row-checkbox")) { try { mostrarDetalleFalla(<?php echo json_encode($fallaDataForJs, JSON_HEX_APOS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE); ?>); } catch(e){} }'>
                                <td class="td-cb">
                                    <input type="checkbox" class="row-checkbox" value="<?php echo $idFalla; ?>" name="selected_fallas[]">
                                </td>
                                <td class="td-id"><?php echo $idFalla; ?></td>
                                <td class="td-modelo"><?php echo htmlspecialchars((string)$falla[1], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="td-matricula"><?php echo htmlspecialchars((string)$falla[2], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="td-ata" style="font-size: 13px; text-align: center; font-weight: 700; color: #475569;"><?php echo htmlspecialchars($ataNum, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="td-fecha"><?php echo htmlspecialchars($fechaFormateada, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="td-mayuscula">
                                    <div class="text-clamp" style="max-height: 4.5em; -webkit-line-clamp: 3;"><?php echo htmlspecialchars((string)$falla[5], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="td-mayuscula">
                                    <div class="text-clamp" style="max-height: 4.5em; -webkit-line-clamp: 3;"><?php echo htmlspecialchars((string)$falla[6], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="td-tips">
                                    <div class="text-clamp" style="color: #1a419c; font-weight: 600; max-height: 4.5em; -webkit-line-clamp: 3;"><?php echo htmlspecialchars((string)$falla[8], ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td style="font-size: 12.5px;"><?php echo htmlspecialchars((string)$falla[7], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="td-mel-combined" style="font-size: 12.5px; text-align: center; font-weight: 600; color: #1a419c;"><?php echo htmlspecialchars($melCombined, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center;">
                                    <button class="btn-view-direct" style="background: #eef2ff; border: 1px solid #c7d2fe; color: #1a419c; padding: 6px 12px; border-radius: 6px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 12px; cursor: pointer; display: inline-flex; align-items: center; gap: 4px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#1a419c'; this.style.color='#ffffff'; this.style.borderColor='#1a419c';" onmouseout="this.style.backgroundColor='#eef2ff'; this.style.color='#1a419c'; this.style.borderColor='#c7d2fe';" onclick='event.stopPropagation(); mostrarDetalleFalla(<?php echo json_encode($fallaDataForJs, JSON_HEX_APOS | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE); ?>)'>
                                        <i class="fas fa-eye"></i> Ver
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- Script de Checkboxes y Exportación a PDF -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const selectAll = document.getElementById('selectAll');
            const checkboxes = document.querySelectorAll('.row-checkbox');

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(cb => {
                        cb.checked = selectAll.checked;
                    });
                });
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', function () {
                    if (!this.checked && selectAll) {
                        selectAll.checked = false;
                    } else if (selectAll) {
                        const allChecked = Array.from(checkboxes).every(c => c.checked);
                        if (allChecked) selectAll.checked = true;
                    }
                });
            });
            
            // Evento para abrir modal ya manejado por el atributo onclick en el <tr>
            // Se conserva este bloque vacío por compatibilidad estructural si es necesario
            /*
            const fallaRows = document.querySelectorAll('.fallas-table tbody tr.falla-row');
            fallaRows.forEach(row => {
                row.addEventListener('click', function (e) {
                    if (e.target.closest('.td-cb') || e.target.classList.contains('row-checkbox')) { return; }
                    try {
                        const rawData = this.getAttribute('data-falla');
                        if (rawData) {
                            const data = JSON.parse(rawData);
                            if (data && typeof data === 'object') {
                                mostrarDetalleFalla(data);
                            }
                        }
                    } catch (err) { console.error(err); }
                });
            });
            */

            // Auto-abrir modal si viene pre-filtrado desde el Dashboard (ID, Modelo, ATA o Matrícula)
            <?php if ((!empty($filterId) || !empty($filterMod) || !empty($filterAta) || !empty($filterMat)) && count($fallas) > 0): ?>
            const firstRow = document.querySelector('.fallas-table tbody tr.falla-row');
            if (firstRow) {
                setTimeout(() => {
                    firstRow.click();
                }, 150);
            }
            <?php endif; ?>

            // Cerrar modal al presionar tecla Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeModal();
                }
            });

            // Cerrar modal al hacer clic en el fondo oscuro translúcido
            const fm = document.getElementById('fallaModal');
            if (fm) {
                fm.addEventListener('click', function(e) {
                    if (e.target === this) {
                        closeModal();
                    }
                });
            }
        });

        function abrirEdicionDirecta(id) {
            const data = fallasDataMap[id];
            if (data) {
                try {
                    currentFallaData = data;
                    document.getElementById('fallaModal').classList.add('active');
                    activarModoEdicion(data);
                } catch (err) {
                    console.error("Error al abrir editor directo:", err);
                }
            }
        }

        function setElementText(id, text) {
            const el = document.getElementById(id);
            if (el) {
                el.innerText = text;
            }
        }

        function mostrarDetalleFalla(data) {
            if (!data) return;
            currentFallaData = data;
            // Asegurar que abrimos en modo Vista y ocultamos el de Edición
            const editCont = document.getElementById('modalEditContainer');
            if (editCont) editCont.style.display = 'none';
            const viewMode = document.getElementById('modalViewMode');
            if (viewMode) viewMode.style.display = 'block';

            setElementText('modalTitle', 'Detalle de Reporte #' + (data.id || ''));
            setElementText('modalModelo', data.modelo || '----');
            setElementText('modalMatricula', data.matricula || '----');
            setElementText('modalAta', data.ata || '----');
            setElementText('modalFecha', data.fecha || '----');
            setElementText('modalBase', data.base || '----');
            setElementText('modalMel', data.mel || '----');
            setElementText('modalCat', data.categoria_mel || '----');
            
            // Cargar nuevos campos de optimización
            setElementText('modalHoras', data.horas || '----');
            setElementText('modalCiclos', data.ciclos || '----');
            
            let tiempoText = '----';
            if (data.tiempo_atencion && data.tiempo_atencion !== '----') {
                tiempoText = data.tiempo_atencion + ' h';
            }
            setElementText('modalTiempoAtencion', tiempoText);
            
            // Configurar condición con colores corporativos unificados
            const condVal = parseInt(data.condicion) || 0;
            const condElem = document.getElementById('modalCondicion');
            if (condElem) {
                condElem.innerText = data.condicion || '----';
                if (condVal === 1) {
                    condElem.style.color = '#b91c1c';
                } else if (condVal === 2) {
                    condElem.style.color = '#b45309';
                } else {
                    condElem.style.color = '#15803d';
                }
            }
            
            setElementText('modalFolio', data.folio || '----');
            setElementText('modalDescripcion', data.descripcion || '----');
            setElementText('modalAccion', data.accion || '----');
            
            // Agregar el autor del registro al pie del modal
            setElementText('modalRegistradoPor', data.registrado_por || 'Sistema');
            
            // Limpieza explícita de valores de componentes para evitar persistencia visual de reportes anteriores
            setElementText('modalCompRemovidoNp', '----');
            setElementText('modalCompRemovidoNs', '----');
            setElementText('modalCompInstaladoNp', '----');
            setElementText('modalCompInstaladoNs', '----');
            
            // Mostrar u ocultar sección de componentes cambiados
            const secComp = document.getElementById('sectionComponentes');
            const compContainer = document.getElementById('modalComponentesContainer');
            if (secComp && compContainer) {
                if (data.componente_cambiado === 'Sí') {
                    let html = '';
                    
                    const genBloque = (idx, rnp, rns, inp, ins) => {
                        return `
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; background: #f8fafc; padding: 15px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 10px;">
                            <div style="border-right: 1px solid #e2e8f0; padding-right: 15px;">
                                <h4 style="margin: 0 0 10px 0; color: #ef4444; font-family: 'Outfit', sans-serif; font-size: 13.5px; font-weight: 700; text-transform: uppercase;">
                                    <i class="fas fa-arrow-right-from-bracket"></i> Removido ${idx}
                                </h4>
                                <div style="font-size: 13px; color: #475569;">
                                    <strong>N/P:</strong> <span style="font-weight: 700; color: #1e293b;">${rnp || '----'}</span><br>
                                    <strong>N/S:</strong> <span style="font-weight: 700; color: #1e293b;">${rns || '----'}</span>
                                </div>
                            </div>
                            <div style="padding-left: 15px;">
                                <h4 style="margin: 0 0 10px 0; color: #22c55e; font-family: 'Outfit', sans-serif; font-size: 13.5px; font-weight: 700; text-transform: uppercase;">
                                    <i class="fas fa-arrow-right-to-bracket"></i> Instalado ${idx}
                                </h4>
                                <div style="font-size: 13px; color: #475569;">
                                    <strong>N/P:</strong> <span style="font-weight: 700; color: #1e293b;">${inp || '----'}</span><br>
                                    <strong>N/S:</strong> <span style="font-weight: 700; color: #1e293b;">${ins || '----'}</span>
                                </div>
                            </div>
                        </div>`;
                    };

                    if (data.comp_removido_np && data.comp_removido_np !== 'N/A') {
                        html += genBloque(1, data.comp_removido_np, data.comp_removido_ns, data.comp_instalado_np, data.comp_instalado_ns);
                    }
                    if (data.comp2_removido_np && data.comp2_removido_np !== 'N/A') {
                        html += genBloque(2, data.comp2_removido_np, data.comp2_removido_ns, data.comp2_instalado_np, data.comp2_instalado_ns);
                    }
                    if (data.componentes_adicionales && Array.isArray(data.componentes_adicionales)) {
                        data.componentes_adicionales.forEach((comp, i) => {
                            html += genBloque(i + 3, comp.removido_np, comp.removido_ns, comp.instalado_np, comp.instalado_ns);
                        });
                    }

                    if (html !== '') {
                        compContainer.innerHTML = html;
                        secComp.style.display = 'block';
                    } else {
                        secComp.style.display = 'none';
                    }
                } else {
                    secComp.style.display = 'none';
                }
            }
            
            // Mostrar u ocultar sección de referencia
            const secRef = document.getElementById('sectionReferencia');
            if (secRef) {
                if (data.referencia && data.referencia.trim() !== '') {
                    secRef.style.display = 'flex';
                    setElementText('modalReferencia', data.referencia);
                } else {
                    secRef.style.display = 'none';
                }
            }
            
            // Mostrar u ocultar sección de tips
            const secTips = document.getElementById('sectionTips');
            if (secTips) {
                if (data.tips && data.tips.trim() !== '') {
                    secTips.style.display = 'flex';
                    setElementText('modalTips', data.tips);
                } else {
                    secTips.style.display = 'none';
                }
            }
            
            // Activar el modal con transición suave
            const modalElem = document.getElementById('fallaModal');
            if (modalElem) {
                modalElem.classList.add('active');
            }
        }

        function closeModal() {
            document.getElementById('fallaModal').classList.remove('active');
            // Restablecer el modo vista/edición al cerrar
            setTimeout(() => {
                document.getElementById('modalEditContainer').style.display = 'none';
                document.getElementById('modalViewMode').style.display = 'block';
            }, 300);
        }

        function imprimirReporteIndividual(data) {
            if (!data) return;
            
            const printWindow = window.open('', '_blank', 'width=800,height=900');
            if (!printWindow) {
                alert('Por favor, permita las ventanas emergentes en su navegador para poder imprimir el reporte.');
                return;
            }
            
            // Construir el HTML del reporte con estética premium unificada
            const htmlContent = `
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <title>Reporte de Falla #${data.id}</title>
                <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
                <style>
                    body {
                        font-family: 'Plus Jakarta Sans', sans-serif;
                        color: #1e293b;
                        margin: 40px;
                        background: #ffffff;
                        font-size: 13px;
                        line-height: 1.5;
                    }
                    .header {
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                        border-bottom: 2px solid #1a419c;
                        padding-bottom: 15px;
                        margin-bottom: 25px;
                    }
                    .logo-container {
                        font-family: 'Outfit', sans-serif;
                        font-weight: 800;
                        font-size: 22px;
                        color: #1a419c;
                        display: flex;
                        align-items: center;
                        gap: 8px;
                    }
                    .report-title {
                        text-align: right;
                    }
                    .report-title h1 {
                        margin: 0;
                        font-family: 'Outfit', sans-serif;
                        font-size: 20px;
                        color: #1e293b;
                        font-weight: 700;
                    }
                    .report-title p {
                        margin: 5px 0 0 0;
                        font-size: 14px;
                        color: #64748b;
                        font-weight: 600;
                    }
                    .meta-grid {
                        display: grid;
                        grid-template-columns: repeat(4, 1fr);
                        gap: 12px;
                        margin-bottom: 25px;
                    }
                    .meta-item {
                        background: #f8fafc;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        padding: 10px;
                    }
                    .meta-label {
                        font-size: 11px;
                        font-weight: 700;
                        color: #64748b;
                        text-transform: uppercase;
                        margin-bottom: 4px;
                    }
                    .meta-value {
                        font-size: 13px;
                        font-weight: 600;
                        color: #1e293b;
                    }
                    .section-title {
                        font-family: 'Outfit', sans-serif;
                        font-size: 14px;
                        font-weight: 700;
                        color: #1a419c;
                        border-bottom: 1px solid #e2e8f0;
                        padding-bottom: 6px;
                        margin-top: 25px;
                        margin-bottom: 10px;
                        text-transform: uppercase;
                        display: flex;
                        align-items: center;
                        gap: 6px;
                    }
                    .section-content {
                        background: #f8fafc;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        padding: 12px 15px;
                        font-size: 13px;
                        white-space: pre-wrap;
                        word-break: break-word;
                        text-transform: uppercase;
                    }
                    .components-table {
                        width: 100%;
                        border-collapse: collapse;
                        margin-top: 10px;
                        margin-bottom: 20px;
                    }
                    .components-table th, .components-table td {
                        border: 1px solid #e2e8f0;
                        padding: 10px;
                        text-align: left;
                    }
                    .components-table th {
                        background: #f1f5f9;
                        color: #475569;
                        font-size: 11px;
                        text-transform: uppercase;
                        font-weight: 700;
                    }
                    .components-table td {
                        font-size: 12.5px;
                    }
                    .footer {
                        margin-top: 50px;
                        border-top: 1px solid #e2e8f0;
                        padding-top: 15px;
                        display: flex;
                        justify-content: space-between;
                        font-size: 11px;
                        color: #64748b;
                    }
                    @media print {
                        body {
                            margin: 20px;
                        }
                        .no-print {
                            display: none;
                        }
                    }
                    .print-btn-container {
                        text-align: center;
                        margin-bottom: 20px;
                    }
                    .btn-print {
                        background: #1a419c;
                        color: white;
                        border: none;
                        border-radius: 6px;
                        padding: 8px 16px;
                        font-family: 'Outfit', sans-serif;
                        font-weight: 700;
                        font-size: 13px;
                        cursor: pointer;
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        box-shadow: 0 4px 6px rgba(26, 65, 156, 0.15);
                    }
                </style>
            </head>
            <body>
                <div class="print-btn-container no-print">
                    <button class="btn-print" onclick="window.print()">
                        Imprimir / Guardar PDF
                    </button>
                </div>
                <div class="header">
                    <div class="logo-container">
                        <span style="color: #1a419c;">Ale</span><span style="color: #475569;">SearchTool</span>
                    </div>
                    <div class="report-title">
                        <h1>DETALLE DE REPORTE</h1>
                        <p>ID de Registro: #${data.id}</p>
                    </div>
                </div>
                
                <div class="meta-grid">
                    <div class="meta-item">
                        <div class="meta-label">Modelo</div>
                        <div class="meta-value">${data.modelo || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Matrícula</div>
                        <div class="meta-value" style="color: #1a419c;">${data.matricula || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">ATA</div>
                        <div class="meta-value">${data.ata || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Fecha</div>
                        <div class="meta-value">${data.fecha || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Base</div>
                        <div class="meta-value">${data.base || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Logbook Folio</div>
                        <div class="meta-value">${data.folio || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Horas</div>
                        <div class="meta-value">${data.horas || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Ciclos</div>
                        <div class="meta-value">${data.ciclos || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">T. Atención</div>
                        <div class="meta-value">${data.tiempo_atencion ? data.tiempo_atencion + ' h' : '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">MEL Ref / Cat</div>
                        <div class="meta-value">${data.mel && data.mel !== '----' ? data.mel + ' / ' + (data.categoria_mel || 'N/A') : 'N/A'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Condición</div>
                        <div class="meta-value" style="color: ${parseInt(data.condicion) === 1 ? '#b91c1c' : (parseInt(data.condicion) === 2 ? '#b45309' : '#15803d')}">${data.condicion || '----'}</div>
                    </div>
                    <div class="meta-item">
                        <div class="meta-label">Registrado Por</div>
                        <div class="meta-value">${data.registrado_por || 'Sistema'}</div>
                    </div>
                </div>
                
                <div class="section-title">Descripción de Reporte</div>
                <div class="section-content">${data.descripcion || '----'}</div>
                
                <div class="section-title">Acción Correctiva</div>
                <div class="section-content">${data.accion || '----'}</div>
                
                ${data.referencia && data.referencia.trim() !== '' ? `
                <div class="section-title">Referencia (A.M.M.)</div>
                <div class="section-content">${data.referencia}</div>
                ` : ''}
                
                ${data.tips && data.tips.trim() !== '' ? `
                <div class="section-title">Tips de Solución / Troubleshooting</div>
                <div class="section-content" style="color: #1a419c; font-weight: 600;">${data.tips}</div>
                ` : ''}
                
                ${(function() {
                    if (data.componente_cambiado !== 'Sí') return '';
                    
                    let html = '';
                    let idx = 1;
                    const genBloquePrint = (i, rnp, rns, inp, ins) => {
                        return `
                        <tr>
                            <td style="color: #b91c1c; font-weight: 600;">[ - ] Removido ${i}</td>
                            <td>${rnp || '----'}</td>
                            <td>${rns || '----'}</td>
                        </tr>
                        <tr>
                            <td style="color: #15803d; font-weight: 600;">[ + ] Instalado ${i}</td>
                            <td>${inp || '----'}</td>
                            <td>${ins || '----'}</td>
                        </tr>
                        `;
                    };

                    if (data.comp_removido_np && data.comp_removido_np !== 'N/A') {
                        html += genBloquePrint(idx++, data.comp_removido_np, data.comp_removido_ns, data.comp_instalado_np, data.comp_instalado_ns);
                    }
                    if (data.comp2_removido_np && data.comp2_removido_np !== 'N/A') {
                        html += genBloquePrint(idx++, data.comp2_removido_np, data.comp2_removido_ns, data.comp2_instalado_np, data.comp2_instalado_ns);
                    }
                    if (data.componentes_adicionales && Array.isArray(data.componentes_adicionales)) {
                        data.componentes_adicionales.forEach(comp => {
                            html += genBloquePrint(idx++, comp.removido_np, comp.removido_ns, comp.instalado_np, comp.instalado_ns);
                        });
                    }

                    if (html === '') return '';
                    return `
                    <div class="section-title">Componentes Reemplazados</div>
                    <table class="components-table">
                        <thead>
                            <tr>
                                <th>Acción</th>
                                <th>N/P (Número de Parte)</th>
                                <th>N/S (Número de Serie)</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${html}
                        </tbody>
                    </table>
                    `;
                })()}
                
                <div class="footer">
                    <span>Generado automáticamente por AleSearchTool</span>
                    <span>Fecha de Impresión: ${new Date().toLocaleString()}</span>
                </div>
                
                <script>
                    window.onload = function() {
                        setTimeout(() => {
                            window.print();
                        }, 300);
                    }
                <\/script>
            </body>
            </html>
            `;
            
            printWindow.document.open();
            printWindow.document.write(htmlContent);
            printWindow.document.close();
        }



        function guardarEnCarpeta() {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            const selectedIds = [];
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    selectedIds.push(cb.value);
                }
            });

            if (selectedIds.length === 0) {
                alert('Por favor, seleccione al menos un reporte de falla mediante su casilla (checkbox) para guardarlo en su carpeta.');
                return;
            }

            if (!confirm(`¿Desea guardar los ${selectedIds.length} reportes seleccionados en su carpeta de favoritos?`)) {
                return;
            }

            // Realizar petición Ajax por POST
            fetch('guardar_favoritos_lote.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ids: selectedIds })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Reportes guardados con éxito.');
                    // Desmarcar todos los checkboxes
                    checkboxes.forEach(cb => cb.checked = false);
                    const selectAllCb = document.getElementById('selectAll');
                    if (selectAllCb) {
                        selectAllCb.checked = false;
                    }
                } else {
                    alert('Error al guardar: ' + (data.message || 'Error desconocido'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Ocurrió un error al procesar la solicitud de guardado.');
            });
        }

        function exportarSeleccionados() {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            const selectedIds = [];
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    selectedIds.push(cb.value);
                }
            });

            if (selectedIds.length === 0) {
                alert('Por favor, seleccione al menos una falla mediante su casilla (checkbox) para exportar a PDF.');
                return;
            }

            // Ocultar filas no seleccionadas para la impresión
            const rows = document.querySelectorAll('.fallas-table tbody tr');
            rows.forEach(row => {
                const cb = row.querySelector('.row-checkbox');
                if (cb && !cb.checked) {
                    row.classList.add('no-print-row');
                } else {
                    row.classList.remove('no-print-row');
                }
            });

            // Forzar el renderizado de impresión del sistema
            window.print();

            // Restaurar visualización en pantalla después de la impresión
            setTimeout(() => {
                rows.forEach(row => {
                    row.classList.remove('no-print-row');
                });
            }, 1000);
        }

        // ─── EXPORTAR A EXCEL (solo Ingeniero, Supervisor, Administrador) ──────────
        function exportarExcel() {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            const selectedIds = [];
            checkboxes.forEach(cb => {
                if (cb.checked) {
                    selectedIds.push(cb.value);
                }
            });

            if (selectedIds.length === 0) {
                alert('Por favor, seleccione al menos una falla mediante su casilla (checkbox) para exportar a Excel.');
                return;
            }

            window.location.href = 'exportar_excel.php?ids=' + selectedIds.join(',');
        }

        // ─── MODAL EDICIÓN (Ingeniero, Supervisor, Administrador) ─────────────
        const isAllowedToEdit = <?php echo in_array($userRole, ['Ingeniero', 'Supervisor', 'Administrador'], true) ? 'true' : 'false'; ?>;
        const flotaMapConsulta = <?php echo json_encode($flotaMap ?? []) ?: '{}'; ?>;
        const fallasDataMap = <?php echo json_encode($fallasJsData ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) ?: '{}'; ?>;
        let currentFallaData = null;

        // ATA opciones (igual que registrar_falla.php)
        const ataOpciones = [
            '12 - Servicios','21 - Air Conditioning','22 - Auto Flight','23 - Communications',
            '24 - Electrical Power','25 - Equipo abordo','26 - Fire Protection','27 - Flight Controls',
            '28 - Fuel','29 - Hydraulic Power','30 - Ice and Rain Protection',
            '31 - Indicating/Recording Systems','32 - Landing Gear','33 - Lights','34 - Navigation',
            '35 - Oxygen','36 - Pneumatic','37 - Vacuum','38 - Water/Waste',
            '49 - Airborne Auxiliary Power (APU)','50 - Cargo and Accessory Compartments',
            '51 - Standard Practices - Structures','52 - Doors','53 - Fuselage','54 - Nacelles/Pylons',
            '55 - Stabilizers','56 - Windows','57 - Wings','70 - Standard Practices - Engine',
            '71 - Powerplant','72 - Engine','73 - Engine Fuel and Control','74 - Ignition',
            '75 - Air','76 - Engine Controls','77 - Engine Indicating','78 - Exhaust','79 - Oil','80 - Starting'
        ];

        function activarModoEdicion(data) {
            currentFallaData = data;

            // Obtener modelos disponibles para el select
            const modelosDisp = Object.keys(flotaMapConsulta);
            const modeloOpts = modelosDisp.map(m =>
                `<option value="${escHtml(m)}" ${data.modelo === m ? 'selected' : ''}>${escHtml(m)}</option>`
            ).join('');

            // Construir matrícula opciones del modelo actual
            let matOpts = buildMatOpts(data.modelo, data.matricula);

            // ATA opciones
            const ataOptsHtml = ataOpciones.map(a =>
                `<option value="${escHtml(a)}" ${data.ata === a ? 'selected' : ''}>${escHtml(a)}</option>`
            ).join('');

            // Parsear fecha para input date (de DD/MM/YYYY a YYYY-MM-DD)
            let fechaVal = '';
            if (data.fecha && data.fecha !== '----') {
                const parts = data.fecha.split('/');
                if (parts.length === 3) {
                    fechaVal = `${parts[2]}-${parts[1]}-${parts[0]}`;
                }
            }

            // Componente switch
            const compChecked = data.componente_cambiado === 'Sí' ? 'checked' : '';
            const compDisplay = data.componente_cambiado === 'Sí' ? 'block' : 'none';

            // Parsear la referencia actual en partes manual / seccion (Grupo 5)
            let ref1Manual = '';
            let ref1Seccion = '';
            let ref2Manual = '';
            let ref2Seccion = '';
            let hasSecondRef = false;

            if (data.referencia) {
                const parts = data.referencia.split('|').map(p => p.trim());
                if (parts[0]) {
                    const r1 = parts[0];
                    const manuals = ['A.M.M', 'C.M.M', 'L.M.M', 'I.P.C', 'W.M.M', 'S.M.M', 'AMM', 'CMM', 'LMM', 'IPC', 'WMM', 'SMM'];
                    let found = false;
                    for (let m of manuals) {
                        if (r1.startsWith(m + ' ') || r1.startsWith(m + '\t')) {
                            ref1Manual = m.replace(/AMM/g, 'A.M.M').replace(/CMM/g, 'C.M.M').replace(/LMM/g, 'L.M.M').replace(/IPC/g, 'I.P.C').replace(/WMM/g, 'W.M.M').replace(/SMM/g, 'S.M.M');
                            ref1Seccion = r1.substring(m.length).trim();
                            found = true;
                            break;
                        }
                    }
                    if (!found) {
                        ref1Seccion = r1;
                    }
                }
                if (parts[1]) {
                    const r2 = parts[1];
                    hasSecondRef = true;
                    const manuals = ['A.M.M', 'C.M.M', 'L.M.M', 'I.P.C', 'W.M.M', 'S.M.M', 'AMM', 'CMM', 'LMM', 'IPC', 'WMM', 'SMM'];
                    let found = false;
                    for (let m of manuals) {
                        if (r2.startsWith(m + ' ') || r2.startsWith(m + '\t')) {
                            ref2Manual = m.replace(/AMM/g, 'A.M.M').replace(/CMM/g, 'C.M.M').replace(/LMM/g, 'L.M.M').replace(/IPC/g, 'I.P.C').replace(/WMM/g, 'W.M.M').replace(/SMM/g, 'S.M.M');
                            ref2Seccion = r2.substring(m.length).trim();
                            found = true;
                            break;
                        }
                    }
                    if (!found) {
                        ref2Seccion = r2;
                    }
                }
            }

            // Decodificar categoría consolidada (por ejemplo, "C / Solo de día")
            const catComp = data.categoria_mel || '';
            let catMel = '';
            let resMel = '';
            
            if (catComp.includes('/')) {
                const parts = catComp.split('/');
                catMel = parts[0].trim();
                resMel = parts[1].trim();
            } else {
                const cleanVal = catComp.trim();
                if (['A', 'B', 'C', 'D'].includes(cleanVal.toUpperCase())) {
                    catMel = cleanVal;
                } else {
                    resMel = cleanVal;
                }
            }

            const editHtml = `
            <style>
                .edit-field { margin-bottom: 12px; }
                .edit-field label { font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px; }
                .edit-field input, .edit-field select, .edit-field textarea {
                    width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 8px;
                    padding: 9px 12px; font-size: 13px; font-family: 'Plus Jakarta Sans', sans-serif;
                    color: #1e293b; transition: border-color 0.2s, box-shadow 0.2s;
                }
                .edit-field input:focus, .edit-field select:focus, .edit-field textarea:focus {
                    border-color: #1a419c; box-shadow: 0 0 0 3px rgba(26,65,156,0.12); outline: none;
                }
                .edit-field textarea { resize: vertical; min-height: 80px; }
                .edit-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
                .edit-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; }
                .switch-edit { display: flex; align-items: center; gap: 12px; margin: 8px 0 16px; }
                .switch-edit .switch { position: relative; display: inline-block; width: 50px; height: 28px; }
                .switch-edit .switch input { opacity: 0; width: 0; height: 0; }
                .switch-edit .slider { position: absolute; cursor: pointer; top:0; left:0; right:0; bottom:0; background:#cbd5e1; transition:.3s; border-radius:34px; }
                .switch-edit .slider:before { position:absolute; content:""; height:20px; width:20px; left:4px; bottom:4px; background:#fff; transition:.3s; border-radius:50%; box-shadow:0 2px 4px rgba(0,0,0,0.2); }
                .switch-edit input:checked + .slider { background:#1a419c; }
                .switch-edit input:checked + .slider:before { transform:translateX(22px); }
                #editCompSec { border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; background: #f8fafc; margin-top: 8px; }
                .btn-guardar-edit { background: linear-gradient(135deg, #1a419c, #4f46e5); color: #fff; padding: 12px 28px; border: none; border-radius: 8px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 15px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; }
                .btn-guardar-edit:hover { background: linear-gradient(135deg, #14327e, #3730a3); transform: translateY(-2px); }
                .btn-cancelar-edit { background: #f1f5f9; color: #475569; padding: 12px 20px; border: 1px solid #e2e8f0; border-radius: 8px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 14px; cursor: pointer; transition: all 0.2s; }
                .btn-cancelar-edit:hover { background: #e2e8f0; }
            </style>
            <div class="edit-grid-2">
                <div class="edit-field">
                    <label>Modelo</label>
                    <select id="editModelo" onchange="onEditModeloChange(this.value)">
                        <option value="">Seleccionar</option>${modeloOpts}
                    </select>
                </div>
                <div class="edit-field">
                    <label>Matrícula</label>
                    <select id="editMatricula">${matOpts}</select>
                </div>
            </div>
            <div class="edit-grid-3">
                <div class="edit-field">
                    <label>ATA</label>
                    <select id="editAta">
                        <option value="">Seleccionar</option>${ataOptsHtml}
                    </select>
                </div>
                <div class="edit-field">
                    <label>Condición</label>
                    <select id="editCondicion">
                        <option value="1" ${data.condicion==='1'?'selected':''}>1</option>
                        <option value="2" ${data.condicion==='2'?'selected':''}>2</option>
                        <option value="3" ${data.condicion==='3'?'selected':''}>3</option>
                    </select>
                </div>
                <div class="edit-field">
                    <label>Base</label>
                    <select id="editBase">
                        <option value="TLC" ${data.base==='TLC'?'selected':''}>TLC</option>
                        <option value="ADN" ${data.base==='ADN'?'selected':''}>ADN</option>
                    </select>
                </div>
            </div>
            <div class="edit-grid-3">
                <div class="edit-field">
                    <label>Fecha</label>
                    <input type="date" id="editFecha" value="${escHtml(fechaVal)}">
                </div>
                <div class="edit-field">
                    <label>Folio Bitácora</label>
                    <input type="text" id="editFolio" value="${escHtml(data.folio !== '----' ? data.folio : '')}">
                </div>
                <div class="edit-field">
                    <label>Capítulo MEL</label>
                    <input type="text" id="editMel" value="${escHtml(data.mel !== '----' ? data.mel : '')}" placeholder="ej. Capítulo 21">
                </div>
            </div>
            <div class="edit-grid-2">
                <div class="edit-field">
                    <label>Categoría MEL</label>
                    <select id="editCategoriaMel">
                        <option value="">Ninguna</option>
                        <option value="A" ${catMel==='A'?'selected':''}>A</option>
                        <option value="B" ${catMel==='B'?'selected':''}>B</option>
                        <option value="C" ${catMel==='C'?'selected':''}>C</option>
                        <option value="D" ${catMel==='D'?'selected':''}>D</option>
                    </select>
                </div>
                <div class="edit-field">
                    <label>Restricciones MEL</label>
                    <input type="text" id="editRestriccionesMel" value="${escHtml(resMel)}" placeholder="ej. Solo de día...">
                </div>
            </div>
            <div class="edit-grid-3">
                <div class="edit-field">
                    <label>Horas de Vuelo</label>
                    <input type="number" step="any" min="0" id="editHoras" value="${escHtml(data.horas !== '----' ? data.horas : '')}">
                </div>
                <div class="edit-field">
                    <label>Ciclos</label>
                    <input type="number" step="1" min="0" id="editCiclos" value="${escHtml(data.ciclos !== '----' ? data.ciclos : '')}">
                </div>
                <div class="edit-field">
                    <label>T. Atención (h)</label>
                    <input type="number" step="any" min="0" id="editTiempoAtencion" value="${escHtml(data.tiempo_atencion !== '----' ? data.tiempo_atencion.replace(' h','') : '')}">
                </div>
            </div>
            <div class="edit-field">
                <label>Descripción de la Falla <span style="color:#e11d48">*</span></label>
                <textarea id="editDescripcion">${escHtml(data.descripcion)}</textarea>
            </div>
            <div class="edit-field">
                <label>Acción Correctiva <span style="color:#e11d48">*</span></label>
                <textarea id="editAccion">${escHtml(data.accion)}</textarea>
            </div>
            
            <!-- Campo Referencia Dividido en Edición -->
            <div class="edit-field" style="margin-top: 15px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="margin: 0;">Referencia</label>
                    <button type="button" id="edit_btn_add_ref" onclick="onEditAddRefClick()" style="background: none; border: none; color: #1a419c; font-family: 'Outfit', sans-serif; font-weight: 700; cursor: pointer; font-size: 13px; display: ${hasSecondRef ? 'none' : 'inline-flex'}; align-items: center; gap: 5px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                        <i class="fas fa-plus-circle"></i> Agregar segunda referencia
                    </button>
                </div>
                
                <!-- Contenedor Referencia 1 -->
                <div id="edit_ref_container_1" style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 10px;">
                    <select id="edit_ref_manual_1" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                        <option value="">Manual (Seleccionar)</option>
                        <option value="A.M.M" ${ref1Manual==='A.M.M'?'selected':''}>A.M.M</option>
                        <option value="C.M.M" ${ref1Manual==='C.M.M'?'selected':''}>C.M.M</option>
                        <option value="L.M.M" ${ref1Manual==='L.M.M'?'selected':''}>L.M.M</option>
                        <option value="I.P.C" ${ref1Manual==='I.P.C'?'selected':''}>I.P.C</option>
                        <option value="W.M.M" ${ref1Manual==='W.M.M'?'selected':''}>W.M.M</option>
                        <option value="S.M.M" ${ref1Manual==='S.M.M'?'selected':''}>S.M.M</option>
                    </select>
                    <input type="text" id="edit_ref_seccion_1" placeholder="Sección / Número (Ej: 21-50-00)" value="${escHtml(ref1Seccion)}" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                </div>

                <!-- Contenedor Referencia 2 -->
                <div id="edit_ref_container_2" style="display: ${hasSecondRef ? 'grid' : 'none'}; grid-template-columns: 1fr 2fr auto; gap: 15px; margin-bottom: 10px; align-items: center;">
                    <select id="edit_ref_manual_2" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                        <option value="">Manual (Seleccionar)</option>
                        <option value="A.M.M" ${ref2Manual==='A.M.M'?'selected':''}>A.M.M</option>
                        <option value="C.M.M" ${ref2Manual==='C.M.M'?'selected':''}>C.M.M</option>
                        <option value="L.M.M" ${ref2Manual==='L.M.M'?'selected':''}>L.M.M</option>
                        <option value="I.P.C" ${ref2Manual==='I.P.C'?'selected':''}>I.P.C</option>
                        <option value="W.M.M" ${ref2Manual==='W.M.M'?'selected':''}>W.M.M</option>
                        <option value="S.M.M" ${ref2Manual==='S.M.M'?'selected':''}>S.M.M</option>
                    </select>
                    <input type="text" id="edit_ref_seccion_2" placeholder="Sección / Número" value="${escHtml(ref2Seccion)}" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                    <button type="button" onclick="onEditRemoveRefClick()" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px; padding: 5px; display: inline-flex; align-items: center; justify-content: center;" title="Quitar segunda referencia">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>
            </div>

            <div class="edit-field">
                <label>Tips / Troubleshooting</label>
                <textarea id="editTips" style="min-height:52px;">${escHtml(data.tips)}</textarea>
            </div>
            <div class="switch-edit">
                <span style="font-weight:700; color:#1a419c; font-family:'Outfit',sans-serif; font-size:14px;">¿Se cambió algún componente?</span>
                <label class="switch">
                    <input type="checkbox" id="editCompSwitch" ${compChecked} onchange="toggleEditComp()">
                    <span class="slider"></span>
                </label>
                <span id="editCompLabel" style="font-weight:700; color:${data.componente_cambiado==='Sí'?'#1a419c':'#5f6368'}; font-family:'Plus Jakarta Sans',sans-serif;">
                    ${data.componente_cambiado === 'Sí' ? 'Sí' : 'No'}
                </span>
            </div>
            <div id="editCompSec" style="display:${compDisplay};">
                <div id="editCompContainer"></div>
                <div style="text-align: right; margin-top: 15px; margin-bottom: 5px;">
                    <button type="button" onclick="agregarComponenteEdicion()" style="background: none; border: none; color: #1a419c; font-family: 'Outfit', sans-serif; font-weight: 700; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                        <i class="fas fa-plus-circle"></i> Agregar otro componente
                    </button>
                </div>
            </div>
            <div style="display:flex; gap:12px; margin-top:20px; padding-top:15px; border-top:1px solid #e2e8f0; justify-content:flex-end;">
                <button class="btn-cancelar-edit" onclick="cancelarEdicion()"><i class="fas fa-arrow-left"></i> Cancelar</button>
                <button class="btn-guardar-edit" onclick="guardarEdicion()"><i class="fas fa-floppy-disk"></i> Guardar Cambios</button>
            </div>`;

            document.getElementById('modalEditContainer').innerHTML = editHtml;
            document.getElementById('modalViewMode').style.display = 'none';
            document.getElementById('modalEditContainer').style.display = 'block';
            document.getElementById('modalTitle').innerText = '✏️ Editar Reporte #' + data.id;

            // Inicializar componentes existentes
            window.editCompCount = 0;
            const compContainer = document.getElementById('editCompContainer');
            compContainer.innerHTML = '';
            
            if (data.componente_cambiado === 'Sí') {
                if (data.comp_removido_np && data.comp_removido_np !== 'N/A') {
                    agregarComponenteEdicion(data.comp_removido_np, data.comp_removido_ns, data.comp_instalado_np, data.comp_instalado_ns);
                }
                if (data.comp2_removido_np && data.comp2_removido_np !== 'N/A') {
                    agregarComponenteEdicion(data.comp2_removido_np, data.comp2_removido_ns, data.comp2_instalado_np, data.comp2_instalado_ns);
                }
                if (data.componentes_adicionales && Array.isArray(data.componentes_adicionales)) {
                    data.componentes_adicionales.forEach(comp => {
                        agregarComponenteEdicion(comp.removido_np, comp.removido_ns, comp.instalado_np, comp.instalado_ns);
                    });
                }
            }
            if (window.editCompCount === 0) {
                agregarComponenteEdicion();
            }
        }
        
        function agregarComponenteEdicion(rnp = '', rns = '', inp = '', ins = '') {
            window.editCompCount++;
            const idx = window.editCompCount;
            const container = document.getElementById('editCompContainer');
            const compHtml = `
            <div class="edit-comp-bloque" data-index="${idx}" style="margin-top: 15px; padding-top: 15px; border-top: 1px dashed #cbd5e1;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h4 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 14px; font-weight: 700;">Componente ${idx}</h4>
                    <button type="button" onclick="this.closest('.edit-comp-bloque').remove()" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 15px; padding: 5px; display: inline-flex; align-items: center; justify-content: center;" title="Quitar componente">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>
                <div class="edit-grid-2">
                    <div>
                        <h4 style="margin:0 0 10px; color:#ef4444; font-family:'Outfit',sans-serif; font-size:12px; font-weight:700; text-transform:uppercase;">
                            <i class="fas fa-arrow-right-from-bracket"></i> Removido
                        </h4>
                        <div class="edit-field"><label>N/P</label><input type="text" class="edit_comp_rem_np" value="${escHtml(rnp !== 'N/A' ? rnp : '')}"></div>
                        <div class="edit-field"><label>N/S</label><input type="text" class="edit_comp_rem_ns" value="${escHtml(rns !== 'N/A' ? rns : '')}"></div>
                    </div>
                    <div>
                        <h4 style="margin:0 0 10px; color:#22c55e; font-family:'Outfit',sans-serif; font-size:12px; font-weight:700; text-transform:uppercase;">
                            <i class="fas fa-arrow-right-to-bracket"></i> Instalado
                        </h4>
                        <div class="edit-field"><label>N/P</label><input type="text" class="edit_comp_inst_np" value="${escHtml(inp !== 'N/A' ? inp : '')}"></div>
                        <div class="edit-field"><label>N/S</label><input type="text" class="edit_comp_inst_ns" value="${escHtml(ins !== 'N/A' ? ins : '')}"></div>
                    </div>
                </div>
            </div>`;
            container.insertAdjacentHTML('beforeend', compHtml);
        }

        // Funciones auxiliares para referencias en modo edición (Grupo 5)
        function onEditAddRefClick() {
            document.getElementById('edit_ref_container_2').style.display = 'grid';
            document.getElementById('edit_btn_add_ref').style.display = 'none';
        }

        function onEditRemoveRefClick() {
            document.getElementById('edit_ref_container_2').style.display = 'none';
            document.getElementById('edit_ref_manual_2').value = '';
            document.getElementById('edit_ref_seccion_2').value = '';
            document.getElementById('edit_btn_add_ref').style.display = 'inline-flex';
        }

        function buildMatOpts(modelo, selectedMat) {
            if (!flotaMapConsulta[modelo]) return `<option value="">Sin matrículas</option>`;
            return flotaMapConsulta[modelo].map(m =>
                `<option value="${escHtml(m)}" ${selectedMat === m ? 'selected' : ''}>${escHtml(m)}</option>`
            ).join('');
        }

        function onEditModeloChange(modelo) {
            const matSel = document.getElementById('editMatricula');
            matSel.innerHTML = buildMatOpts(modelo, '');
        }

        function toggleEditComp() {
            const chk = document.getElementById('editCompSwitch');
            const sec = document.getElementById('editCompSec');
            const lbl = document.getElementById('editCompLabel');
            if (chk.checked) {
                sec.style.display = 'block';
                lbl.textContent = 'Sí';
                lbl.style.color = '#1a419c';
            } else {
                sec.style.display = 'none';
                lbl.textContent = 'No';
                lbl.style.color = '#5f6368';
            }
        }

        function cancelarEdicion() {
            document.getElementById('modalEditContainer').style.display = 'none';
            document.getElementById('modalViewMode').style.display = 'block';
            document.getElementById('modalTitle').innerText = 'Detalle de Reporte #' + (currentFallaData ? currentFallaData.id : '----');
        }

        async function guardarEdicion() {
            if (!currentFallaData) return;
            const compChecked = document.getElementById('editCompSwitch').checked;

            // Combinar referencias en modo edición (Grupo 5)
            const man1 = document.getElementById('edit_ref_manual_1').value;
            const sec1 = document.getElementById('edit_ref_seccion_1').value.trim();
            const man2 = document.getElementById('edit_ref_manual_2').value;
            const sec2 = document.getElementById('edit_ref_seccion_2').value.trim();

            let refFinal = '';
            if (man1 && sec1) {
                refFinal = man1 + ' ' + sec1;
            } else if (sec1) {
                refFinal = sec1;
            }

            const refContainer2 = document.getElementById('edit_ref_container_2');
            if (refContainer2 && refContainer2.style.display !== 'none') {
                let ref2 = '';
                if (man2 && sec2) {
                    ref2 = man2 + ' ' + sec2;
                } else if (sec2) {
                    ref2 = sec2;
                }
                if (ref2) {
                    refFinal = refFinal ? refFinal + ' | ' + ref2 : ref2;
                }
            }

            const compRemNps = Array.from(document.querySelectorAll('.edit_comp_rem_np')).map(i => i.value.trim());
            const compRemNss = Array.from(document.querySelectorAll('.edit_comp_rem_ns')).map(i => i.value.trim());
            const compInstNps = Array.from(document.querySelectorAll('.edit_comp_inst_np')).map(i => i.value.trim());
            const compInstNss = Array.from(document.querySelectorAll('.edit_comp_inst_ns')).map(i => i.value.trim());

            const payload = {
                id_falla: parseInt(currentFallaData.id),
                modelo:            document.getElementById('editModelo').value.trim(),
                matricula:         document.getElementById('editMatricula').value.trim(),
                ata:               document.getElementById('editAta').value.trim(),
                condicion:         document.getElementById('editCondicion').value.trim(),
                folio:             document.getElementById('editFolio').value.trim(),
                fecha:             document.getElementById('editFecha').value.trim(),
                base:              document.getElementById('editBase').value.trim(),
                mel:               document.getElementById('editMel').value.trim(),
                categoria_mel:     document.getElementById('editCategoriaMel').value.trim(),
                restricciones_mel: document.getElementById('editRestriccionesMel').value.trim(),
                descripcion:       document.getElementById('editDescripcion').value.trim(),
                accion_correctiva: document.getElementById('editAccion').value.trim(),
                referencia:        refFinal,
                tips:              document.getElementById('editTips').value.trim(),
                horas:             document.getElementById('editHoras').value.trim(),
                ciclos:            document.getElementById('editCiclos').value.trim(),
                tiempo_atencion:   document.getElementById('editTiempoAtencion').value.trim(),
                componente_cambiado: compChecked ? 'Sí' : 'No',
                comp_removido_np:  compChecked ? compRemNps : [],
                comp_removido_ns:  compChecked ? compRemNss : [],
                comp_instalado_np: compChecked ? compInstNps : [],
                comp_instalado_ns: compChecked ? compInstNss : []
            };
            if (!payload.modelo || !payload.matricula || !payload.ata || !payload.descripcion || !payload.accion_correctiva) {
                alert('⚠️ Faltan campos obligatorios: Modelo, Matrícula, ATA, Descripción y Acción Correctiva.');
                return;
            }
            const btnGuardar = document.querySelector('.btn-guardar-edit');
            btnGuardar.disabled = true;
            btnGuardar.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Guardando...';
            try {
                const resp = await fetch('actualizar_falla.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const result = await resp.json();
                if (result.success) {
                    alert('✅ ' + result.message);
                    closeModal();
                    location.reload();
                } else {
                    alert('❌ Error: ' + (result.message || 'Error desconocido.'));
                }
            } catch (err) {
                console.error(err);
                alert('❌ Error de conexión al servidor.');
            } finally {
                btnGuardar.disabled = false;
                btnGuardar.innerHTML = '<i class="fas fa-floppy-disk"></i> Guardar Cambios';
            }
        }

        function escHtml(str) {
            if (!str || str === '----') return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
    </script>


    <div id="fallaModal" class="modal-overlay">
        <div class="modal-container">
            <div class="modal-header">
                <h2 id="modalTitle">Detalle de Reporte #----</h2>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button id="btnImprimirModal" onclick="imprimirReporteIndividual(currentFallaData)" style="background: #eef2ff; color: #1a419c; border: 1px solid #c7d2fe; border-radius: 8px; padding: 8px 16px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#1a419c'; this.style.color='#ffffff'; this.style.borderColor='#1a419c';" onmouseout="this.style.backgroundColor='#eef2ff'; this.style.color='#1a419c'; this.style.borderColor='#c7d2fe';">
                        <i class="fas fa-file-pdf"></i> Imprimir PDF
                    </button>
                    <?php if (in_array($userRole, ['Ingeniero', 'Supervisor', 'Administrador'], true)): ?>
                    <button id="btnEditarModal" onclick="activarModoEdicion(currentFallaData)" style="background: linear-gradient(135deg, #1a419c, #4f46e5); color: #fff; border: none; border-radius: 8px; padding: 8px 16px; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 13px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.opacity='0.9';" onmouseout="this.style.opacity='1';">
                        <i class="fas fa-pen-to-square"></i> Editar
                    </button>
                    <?php endif; ?>
                    <button class="btn-modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
                </div>
            </div>
            <!-- MODO VISTA -->
            <div id="modalViewMode" class="modal-body">
                <div class="modal-meta-grid">
                    <div class="meta-item">
                        <span class="meta-label">Modelo</span>
                        <span class="meta-value" id="modalModelo">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Matrícula</span>
                        <span class="meta-value" id="modalMatricula" style="color: #1a419c; font-weight: 700;">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">ATA</span>
                        <span class="meta-value" id="modalAta">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Fecha</span>
                        <span class="meta-value" id="modalFecha">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Base</span>
                        <span class="meta-value" id="modalBase" style="font-weight: 700; color: #475569;">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Condición</span>
                        <span class="meta-value" id="modalCondicion" style="font-weight: bold;">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Logbook Folio</span>
                        <span class="meta-value" id="modalFolio">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Horas</span>
                        <span class="meta-value" id="modalHoras" style="color: #1a419c;">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">Ciclos</span>
                        <span class="meta-value" id="modalCiclos">----</span>
                    </div>
                    <div class="meta-item">
                        <span class="meta-label">T. Atención</span>
                        <span class="meta-value" id="modalTiempoAtencion" style="color: #475569;">----</span>
                    </div>
                </div>

                <div class="modal-section">
                    <div class="modal-section-title">
                        <i class="fas fa-triangle-exclamation"></i> Descripción de Reporte
                    </div>
                    <div class="modal-section-content" id="modalDescripcion" style="text-transform: uppercase;">
                        ----
                    </div>
                </div>

                <div class="modal-section">
                    <div class="modal-section-title">
                        <i class="fas fa-screwdriver-wrench"></i> Acción Correctiva
                    </div>
                    <div class="modal-section-content" id="modalAccion" style="text-transform: uppercase;">
                        ----
                    </div>
                </div>

                <div class="modal-section" id="sectionComponentes" style="display: none;">
                    <div class="modal-section-title">
                        <i class="fas fa-arrows-spin"></i> Componentes Reemplazados
                    </div>
                    <div class="modal-section-content" style="padding: 0; background: transparent; border: none;" id="modalComponentesContainer">
                        <!-- Dynamic components will go here -->
                    </div>
                </div>

                <div class="modal-section" id="sectionReferencia">
                    <div class="modal-section-title">
                        <i class="fas fa-bookmark"></i> Referencia
                    </div>
                    <div class="modal-section-content" id="modalReferencia">
                        ----
                    </div>
                </div>

                <div class="modal-section" id="sectionTips">
                    <div class="modal-section-title">
                        <i class="fas fa-lightbulb"></i> Tips
                    </div>
                    <div class="modal-section-content" id="modalTips" style="color: #1a419c; font-weight: 600;">
                        ----
                    </div>
                </div>

                <!-- Sección Especial MEL Ref / Cat / Restricciones (Hasta el último de las secciones) -->
                <div class="modal-section" id="sectionMelCat">
                    <div class="modal-section-title">
                        <i class="fas fa-file-shield"></i> MEL Ref / Cat / Restricciones
                    </div>
                    <div class="modal-section-content" style="display: flex; gap: 40px; align-items: center; background: #f8fafc; border-radius: 8px; padding: 12px 16px; border: 1px solid #e2e8f0;">
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 2px; letter-spacing: 0.5px;">Mel Ref</span>
                            <strong id="modalMel" style="font-size: 14px; color: #1e293b; font-family: 'Outfit', sans-serif;">----</strong>
                        </div>
                        <div style="width: 1px; height: 32px; background: #cbd5e1;"></div>
                        <div>
                            <span style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; display: block; margin-bottom: 2px; letter-spacing: 0.5px;">Categoría / Restricciones</span>
                            <strong id="modalCat" style="font-size: 14px; color: #1a419c; font-family: 'Outfit', sans-serif;">----</strong>
                        </div>
                    </div>
                </div>

                <!-- Footer del Modal - Autor del Registro -->
                <div class="modal-footer-info" style="margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; align-items: center; gap: 8px; font-size: 13px; color: #64748b;">
                    <i class="fas fa-user-pen" style="color: #1a419c;"></i>
                    <span>Registrado por: <strong id="modalRegistradoPor" style="color: #334155;">----</strong></span>
                </div>
            </div>
            <!-- MODO EDICIÓN (solo Ingeniero) -->
            <div id="modalEditContainer" class="modal-body" style="display: none;"></div>
        </div>
    </div>
</body>

</html>
