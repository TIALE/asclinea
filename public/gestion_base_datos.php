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

// Bloqueo estricto para roles no autorizados (Administrador, Ingeniero, Supervisor)
if (!in_array($userRole, ['Administrador', 'Ingeniero', 'Supervisor'], true)) {
    header('Location: dashboard.php');
    exit;
}

$userName = (string)SessionManager::get('user_name', 'Usuario');
$err = '';
$msg = '';

try {
    $pdo = DatabaseConnection::getConnection();

    // --- PROCESAR EDICIÓN DE REPORTE (POST) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_report') {
        $idFalla           = (int)filter_input(INPUT_POST, 'id_falla', FILTER_VALIDATE_INT);
        $modelo            = trim((string)filter_input(INPUT_POST, 'modelo', FILTER_DEFAULT));
        $matricula         = trim((string)filter_input(INPUT_POST, 'matricula', FILTER_DEFAULT));
        $ata               = trim((string)filter_input(INPUT_POST, 'ata', FILTER_DEFAULT));
        $condicion         = trim((string)filter_input(INPUT_POST, 'condicion', FILTER_DEFAULT));
        $folio             = trim((string)filter_input(INPUT_POST, 'folio', FILTER_DEFAULT));
        $fecha             = trim((string)filter_input(INPUT_POST, 'fecha', FILTER_DEFAULT));
        $mel               = trim((string)filter_input(INPUT_POST, 'mel', FILTER_DEFAULT));
        $categoria_mel     = trim((string)filter_input(INPUT_POST, 'categoria_mel', FILTER_DEFAULT));
        $restricciones_mel = trim((string)filter_input(INPUT_POST, 'restricciones_mel', FILTER_DEFAULT));
        $base              = trim((string)filter_input(INPUT_POST, 'base', FILTER_DEFAULT));
        $referencia        = trim((string)filter_input(INPUT_POST, 'referencia', FILTER_DEFAULT));
        $descripcion       = trim((string)filter_input(INPUT_POST, 'descripcion', FILTER_DEFAULT));
        $accion_correctiva = trim((string)filter_input(INPUT_POST, 'accion_correctiva', FILTER_DEFAULT));
        $tips              = trim((string)filter_input(INPUT_POST, 'tips', FILTER_DEFAULT));

        // Nuevos campos MTBF/MTTR
        $horas = filter_input(INPUT_POST, 'horas', FILTER_VALIDATE_FLOAT);
        $horas = ($horas !== false && $horas !== null) ? $horas : null;

        $ciclos = filter_input(INPUT_POST, 'ciclos', FILTER_VALIDATE_INT);
        $ciclos = ($ciclos !== false && $ciclos !== null) ? $ciclos : null;

        $tiempo_atencion = filter_input(INPUT_POST, 'tiempo_atencion', FILTER_VALIDATE_FLOAT);
        $tiempo_atencion = ($tiempo_atencion !== false && $tiempo_atencion !== null) ? $tiempo_atencion : null;

        $componente_cambiado = trim((string)filter_input(INPUT_POST, 'componente_cambiado', FILTER_DEFAULT)) === 'Sí' ? 'Sí' : 'No';

        $comp_removido_np = trim((string)filter_input(INPUT_POST, 'comp_removido_np', FILTER_DEFAULT));
        $comp_removido_ns = trim((string)filter_input(INPUT_POST, 'comp_removido_ns', FILTER_DEFAULT));
        $comp_instalado_np = trim((string)filter_input(INPUT_POST, 'comp_instalado_np', FILTER_DEFAULT));
        $comp_instalado_ns = trim((string)filter_input(INPUT_POST, 'comp_instalado_ns', FILTER_DEFAULT));

        if ($componente_cambiado === 'No') {
            $comp_removido_np = 'N/A';
            $comp_removido_ns = 'N/A';
            $comp_instalado_np = 'N/A';
            $comp_instalado_ns = 'N/A';
        }

        // Consolidar Categoría y Restricciones de manera elegante
        $categoria_consolidada = '';
        if (!empty($categoria_mel) && !empty($restricciones_mel)) {
            $categoria_consolidada = $categoria_mel . ' / ' . $restricciones_mel;
        } elseif (!empty($categoria_mel)) {
            $categoria_consolidada = $categoria_mel;
        } elseif (!empty($restricciones_mel)) {
            $categoria_consolidada = $restricciones_mel;
        }

        if ($idFalla <= 0 || empty($modelo) || empty($matricula) || empty($ata) || empty($descripcion) || empty($accion_correctiva)) {
            $err = "Por favor, complete todos los campos obligatorios (*) y proporcione un reporte válido.";
        } else {
            $sqlUp = "
                UPDATE tbo_Falla
                SET modelo = :modelo,
                    matricula = :matricula,
                    ata = :ata,
                    condicion = :condicion,
                    folio = :folio,
                    fecha = :fecha,
                    mel = :mel,
                    categoria_mel = :categoria,
                    descripcion = :descripcion,
                    accion_correctiva = :accion,
                    referencia = :referencia,
                    tips = :tips,
                    base = :base,
                    horas = :horas,
                    ciclos = :ciclos,
                    tiempo_atencion = :tiempo_atencion,
                    componente_cambiado = :componente_cambiado,
                    comp_removido_np = :comp_removido_np,
                    comp_removido_ns = :comp_removido_ns,
                    comp_instalado_np = :comp_instalado_np,
                    comp_instalado_ns = :comp_instalado_ns
                WHERE id_falla = :id
            ";
            $stmtUp = $pdo->prepare($sqlUp);
            $stmtUp->execute([
                ':modelo'             => $modelo,
                ':matricula'          => $matricula,
                ':ata'                => $ata,
                ':condicion'          => !empty($condicion) ? $condicion : null,
                ':folio'              => !empty($folio) ? $folio : null,
                ':fecha'              => !empty($fecha) ? $fecha : date('Y-m-d'),
                ':mel'                => !empty($mel) ? $mel : null,
                ':categoria'          => !empty($categoria_consolidada) ? $categoria_consolidada : null,
                ':descripcion'        => $descripcion,
                ':accion'             => $accion_correctiva,
                ':referencia'         => !empty($referencia) ? $referencia : null,
                ':tips'               => !empty($tips) ? $tips : null,
                ':base'               => $base,
                ':horas'              => $horas,
                ':ciclos'             => $ciclos,
                ':tiempo_atencion'    => $tiempo_atencion,
                ':componente_cambiado' => $componente_cambiado,
                ':comp_removido_np'   => $comp_removido_np,
                ':comp_removido_ns'   => $comp_removido_ns,
                ':comp_instalado_np'  => $comp_instalado_np,
                ':comp_instalado_ns'  => $comp_instalado_ns,
                ':id'                 => $idFalla
            ]);
            $msg = "El reporte #{$idFalla} ha sido corregido y guardado exitosamente en la base de datos.";
        }
    }

    // --- CAPTURAR PARÁMETROS DE BÚSQUEDA Y FILTRADO POR FECHAS ---
    $fecha_inicio = trim((string)filter_input(INPUT_GET, 'fecha_inicio', FILTER_DEFAULT));
    $fecha_fin    = trim((string)filter_input(INPUT_GET, 'fecha_fin', FILTER_DEFAULT));
    $q            = trim((string)filter_input(INPUT_GET, 'q', FILTER_DEFAULT));

    // Construcción de Consulta Dinámica
    $sqlSelect = "SELECT * FROM tbo_Falla WHERE 1=1";
    $params = [];

    if (!empty($fecha_inicio)) {
        $sqlSelect .= " AND fecha >= :fecha_inicio";
        $params[':fecha_inicio'] = $fecha_inicio;
    }
    if (!empty($fecha_fin)) {
        $sqlSelect .= " AND fecha <= :fecha_fin";
        $params[':fecha_fin'] = $fecha_fin;
    }
    if (!empty($q)) {
        $sqlSelect .= " AND (descripcion LIKE :q1 OR accion_correctiva LIKE :q2 OR folio LIKE :q3 OR referencia LIKE :q4 OR tips LIKE :q5 OR modelo LIKE :q6 OR matricula LIKE :q7)";
        $likeQ = "%{$q}%";
        $params[':q1'] = $likeQ;
        $params[':q2'] = $likeQ;
        $params[':q3'] = $likeQ;
        $params[':q4'] = $likeQ;
        $params[':q5'] = $likeQ;
        $params[':q6'] = $likeQ;
        $params[':q7'] = $likeQ;
    }

    $sqlSelect .= " ORDER BY id_falla DESC";
    $stmtSelect = $pdo->prepare($sqlSelect);
    $stmtSelect->execute($params);
    $reportes = $stmtSelect->fetchAll(PDO::FETCH_ASSOC);

} catch (\Exception $e) {
    error_log("Error gestion_base_datos: " . $e->getMessage());
    $err = "Error en base de datos: " . $e->getMessage();
    $reportes = [];
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Base de Datos de Fallas (Admin) - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Gestión y corrección de reportes de fallas.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <style>
        .btn-edit {
            background-color: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #1a419c;
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12.5px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-edit:hover {
            background-color: #1a419c;
            color: #ffffff;
            border-color: #1a419c;
            transform: translateY(-1px);
        }
        .filter-panel {
            background: #ffffff;
            padding: 24px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }
        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.5fr auto;
            gap: 20px;
            align-items: end;
        }
        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .filter-field label {
            font-weight: 700;
            color: #475569;
            font-size: 13.5px;
        }
        .filter-field input {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 13.5px;
            outline: none;
            box-sizing: border-box;
            background-color: #f8fafc;
            transition: border-color 0.2s;
        }
        .filter-field input:focus {
            border-color: #1a419c;
            background-color: #ffffff;
        }
        .btn-filter-submit {
            background-color: #1a419c;
            color: #ffffff;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            height: 40px;
            box-sizing: border-box;
        }
        .btn-filter-submit:hover {
            background-color: #0f2d72;
        }
        .btn-filter-reset {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 11px 16px;
            border-radius: 8px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            height: 40px;
            box-sizing: border-box;
        }
        .btn-filter-reset:hover {
            background-color: #e2e8f0;
            color: #1e293b;
        }

        /* Modal del Editor */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            padding: 20px;
            box-sizing: border-box;
        }
        .modal-content {
            background: #ffffff;
            padding: 30px;
            border-radius: 16px;
            width: 800px;
            max-width: 100%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border: 1px solid #cbd5e1;
            box-sizing: border-box;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        .modal-header h3 {
            margin: 0;
            color: #1a419c;
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            font-weight: 800;
        }
        .modal-close-btn {
            background: none;
            border: none;
            color: #64748b;
            font-size: 22px;
            cursor: pointer;
            transition: color 0.2s;
        }
        .modal-close-btn:hover {
            color: #dc2626;
        }
        .editor-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 15px;
        }
        .editor-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .editor-field.span-full {
            grid-column: span 3;
        }
        .editor-field.span-half {
            grid-column: span 2;
        }
        .editor-field label {
            font-weight: bold;
            color: #475569;
            font-size: 13px;
        }
        .editor-field input, .editor-field textarea, .editor-field select {
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 13.5px;
            outline: none;
            background-color: #ffffff;
            box-sizing: border-box;
        }
        .editor-field input:focus, .editor-field textarea:focus, .editor-field select:focus {
            border-color: #1a419c;
            box-shadow: 0 0 0 3px rgba(26, 65, 156, 0.15);
        }
        .editor-field textarea {
            resize: vertical;
            min-height: 70px;
        }
        .required-star {
            color: #dc2626;
            margin-left: 3px;
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

        <!-- Header superior con Logo -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Base de Datos de Fallas</h1>
                <a href="administracion.php" style="color: #1a419c; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fas fa-arrow-left"></i> Volver a Administración
                </a>
            </div>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <!-- Mensajes de Estado (Success/Error) -->
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

        <!-- Panel de Filtro de Fechas y Búsqueda -->
        <div class="filter-panel">
            <form method="GET" action="gestion_base_datos.php">
                <div class="filter-grid">
                    
                    <div class="filter-field">
                        <label><i class="far fa-calendar-plus"></i> Fecha de Inicio</label>
                        <input type="date" name="fecha_inicio" value="<?php echo htmlspecialchars($fecha_inicio, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="filter-field">
                        <label><i class="far fa-calendar-minus"></i> Fecha de Fin</label>
                        <input type="date" name="fecha_fin" value="<?php echo htmlspecialchars($fecha_fin, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="filter-field">
                        <label><i class="fas fa-keyboard"></i> Palabra clave / Folio</label>
                        <input type="text" name="q" placeholder="ej. Presurización, XA-..., 21..." value="<?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn-filter-submit">
                            <i class="fas fa-filter"></i> Filtrar
                        </button>
                        <?php if (!empty($fecha_inicio) || !empty($fecha_fin) || !empty($q)): ?>
                            <a href="gestion_base_datos.php" class="btn-filter-reset">
                                <i class="fas fa-arrow-rotate-left"></i> Limpiar
                            </a>
                        <?php endif; ?>
                    </div>

                </div>
            </form>
        </div>

        <!-- Listado Principal de Reportes -->
        <div class="table-container" style="overflow-x: auto; max-width: 100%;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
                <h2 style="margin: 0; font-family: 'Outfit', sans-serif;"><i class="fas fa-list-check"></i> Listado de Reportes Históricos (<?php echo count($reportes); ?>)</h2>
                <button onclick="exportarExcel()" class="btn-export-excel" style="background: linear-gradient(135deg, #16a34a, #22c55e); color: #ffffff; padding: 10px 20px; border-radius: 8px; border: none; font-family: 'Outfit', sans-serif; font-weight: 700; font-size: 14px; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s ease-in-out; box-shadow: 0 3px 6px rgba(22, 163, 74, 0.2);" onmouseover="this.style.background='linear-gradient(135deg, #15803d, #16a34a)'; this.style.transform='translateY(-1px)';" onmouseout="this.style.background='linear-gradient(135deg, #16a34a, #22c55e)'; this.style.transform='translateY(0)';">
                    <i class="fas fa-file-excel"></i> Exportar Seleccionados a Excel
                </button>
            </div>
            
            <?php if (empty($reportes)): ?>
                <div style="text-align: center; padding: 40px; background-color: #ffffff; border-radius: 8px;">
                    <i class="fas fa-triangle-exclamation" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px;"></i>
                    <p style="margin: 0; color: #64748b; font-size: 15px; font-weight: 600;">No se encontraron reportes con los criterios de filtración seleccionados.</p>
                </div>
            <?php else: ?>
                <table class="fallas-table" style="min-width: 2800px; width: 100%; border-collapse: collapse;">
                    <thead>
                        <tr>
                            <th style="width: 45px; text-align: center;"><input type="checkbox" id="selectAll"></th>
                            <th style="width: 70px; text-align: center;">ID</th>
                            <th style="width: 110px;">Fecha</th>
                            <th style="width: 100px;">Modelo</th>
                            <th style="width: 110px;">Matrícula</th>
                            <th style="width: 90px; text-align: center;">ATA</th>
                            <th style="width: 110px;">Folio</th>
                            <th style="width: 90px; text-align: center;">Condición</th>
                            <th style="width: 100px;">Horas Vuelo</th>
                            <th style="width: 90px;">Ciclos</th>
                            <th style="width: 100px;">T.Atención (h)</th>
                            <th style="width: 350px;">Descripción del Reporte</th>
                            <th style="width: 350px;">Acción Correctiva</th>
                            <th style="width: 160px;">Referencia</th>
                            <th style="width: 220px;">Tips / Troubleshooting</th>
                            <th style="width: 90px;">Base</th>
                            <th style="width: 140px;">Registrado Por</th>
                            <th style="width: 110px;">MEL Ref</th>
                            <th style="width: 100px; text-align: center;">Cat MEL</th>
                            <th style="width: 110px; text-align: center;">Comp. Cambiado</th>
                            <th style="width: 130px;">Removido N/P</th>
                            <th style="width: 130px;">Removido N/S</th>
                            <th style="width: 130px;">Instalado N/P</th>
                            <th style="width: 130px;">Instalado N/S</th>
                            <th style="width: 100px; text-align: center; position: sticky; right: 0; background: #fafbfc; z-index: 10; border-left: 2px solid #e2e8f0; box-shadow: -4px 0 8px rgba(0,0,0,0.03);">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reportes as $item): ?>
                            <tr>
                                <td style="text-align: center;">
                                    <input type="checkbox" class="row-checkbox" value="<?php echo $item['id_falla']; ?>">
                                </td>
                                <td style="text-align: center; font-weight: bold; color: #64748b;">#<?php echo $item['id_falla']; ?></td>
                                <td style="font-weight: 600; white-space: nowrap;"><?php echo date('d/m/Y', strtotime((string)$item['fecha'])); ?></td>
                                <td style="font-weight: 700; color: #1a419c;"><?php echo htmlspecialchars((string)$item['modelo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-weight: 700; color: #475569;"><?php echo htmlspecialchars((string)$item['matricula'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center; font-weight: bold;"><span style="background-color: #f1f5f9; padding: 3px 8px; border-radius: 4px; font-size: 11.5px; border: 1px solid #e2e8f0; white-space: nowrap;">ATA <?php echo htmlspecialchars((string)$item['ata'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td style="font-weight: 600; color: #475569;"><?php echo !empty($item['folio']) ? htmlspecialchars((string)$item['folio'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td style="text-align: center; font-weight: bold;"><?php echo !empty($item['condicion']) ? htmlspecialchars((string)$item['condicion'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td><?php echo $item['horas'] !== null ? htmlspecialchars((string)$item['horas'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td><?php echo $item['ciclos'] !== null ? htmlspecialchars((string)$item['ciclos'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td><?php echo $item['tiempo_atencion'] !== null ? htmlspecialchars((string)$item['tiempo_atencion'] . ' h', ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td style="text-align: left; max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="text-align: left; max-width: 350px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars((string)$item['accion_correctiva'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars((string)$item['accion_correctiva'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="white-space: nowrap;"><?php echo !empty($item['referencia']) ? htmlspecialchars((string)$item['referencia'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td style="text-align: left; max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?php echo htmlspecialchars((string)$item['tips'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo !empty($item['tips']) ? htmlspecialchars((string)$item['tips'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?>
                                </td>
                                <td><span style="font-weight: 700; color: #475569;"><?php echo !empty($item['base']) ? htmlspecialchars((string)$item['base'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></span></td>
                                <td style="white-space: nowrap;"><?php echo htmlspecialchars((string)$item['registrado_por'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($item['mel']) ? htmlspecialchars((string)$item['mel'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td style="text-align: center; font-weight: 700; color: #1a419c;"><?php echo !empty($item['categoria_mel']) ? htmlspecialchars((string)$item['categoria_mel'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td style="text-align: center; font-weight: 600; color: <?php echo $item['componente_cambiado']==='Sí'?'#16a34a':'#64748b'; ?>;"><?php echo htmlspecialchars((string)($item['componente_cambiado'] ?? 'No'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo !empty($item['comp_removido_np']) ? htmlspecialchars((string)$item['comp_removido_np'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td><?php echo !empty($item['comp_removido_ns']) ? htmlspecialchars((string)$item['comp_removido_ns'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td><?php echo !empty($item['comp_instalado_np']) ? htmlspecialchars((string)$item['comp_instalado_np'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td><?php echo !empty($item['comp_instalado_ns']) ? htmlspecialchars((string)$item['comp_instalado_ns'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></td>
                                <td style="text-align: center; position: sticky; right: 0; background: #ffffff; z-index: 9; border-left: 2px solid #e2e8f0; box-shadow: -4px 0 8px rgba(0,0,0,0.03);">
                                    <button class="btn-edit" onclick='abrirEditor(<?php echo json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                                        <i class="fas fa-edit"></i> Editar
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="footer-mantenimiento" style="margin-top: 40px;">
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

    <!-- MODAL EDITOR DE REPORTES (FORMULARIO ADMNISTRADOR) -->
    <div class="modal-overlay" id="editorModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="editorModalTitle"><i class="fas fa-pen-to-square"></i> Corregir Reporte Técnico</h3>
                <button class="modal-close-btn" onclick="cerrarEditor()"><i class="fas fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="gestion_base_datos.php">
                <input type="hidden" name="action" value="edit_report">
                <input type="hidden" name="id_falla" id="edit_id_falla">
                
                <div class="editor-grid">
                    
                    <!-- Modelo -->
                    <div class="editor-field">
                        <label>Modelo Aeronave<span class="required-star">*</span></label>
                        <input type="text" name="modelo" id="edit_modelo" required>
                    </div>

                    <!-- Matrícula -->
                    <div class="editor-field">
                        <label>Matrícula (Tail Number)<span class="required-star">*</span></label>
                        <input type="text" name="matricula" id="edit_matricula" required>
                    </div>

                    <!-- Fecha -->
                    <div class="editor-field">
                        <label>Fecha de Reporte<span class="required-star">*</span></label>
                        <input type="date" name="fecha" id="edit_fecha" required>
                    </div>

                    <!-- ATA -->
                    <div class="editor-field">
                        <label>Código ATA<span class="required-star">*</span></label>
                        <input type="text" name="ata" id="edit_ata" required>
                    </div>

                    <!-- Condición -->
                    <div class="editor-field">
                        <label>Condición</label>
                        <input type="text" name="condicion" id="edit_condicion">
                    </div>

                    <!-- Folio -->
                    <div class="editor-field">
                        <label>Folio de Reporte</label>
                        <input type="text" name="folio" id="edit_folio">
                    </div>

                    <!-- Capítulo MEL -->
                    <div class="editor-field">
                        <label>Capítulo MEL</label>
                        <input type="text" name="mel" id="edit_mel" placeholder="ej. Capítulo 21">
                    </div>

                    <!-- Categoría MEL -->
                    <div class="editor-field">
                        <label>Categoría MEL</label>
                        <select name="categoria_mel" id="edit_categoria_mel">
                            <option value="">Ninguna</option>
                            <option value="A">A</option>
                            <option value="B">B</option>
                            <option value="C">C</option>
                            <option value="D">D</option>
                        </select>
                    </div>

                    <!-- Restricciones MEL -->
                    <div class="editor-field">
                        <label>Restricciones MEL</label>
                        <input type="text" name="restricciones_mel" id="edit_restricciones_mel" placeholder="ej. Solo de día, no presurizar...">
                    </div>

                    <!-- Referencia -->
                    <div class="editor-field span-half">
                        <label>Referencia (Manual / AMM)</label>
                        <input type="text" name="referencia" id="edit_referencia" placeholder="ej. AMM 24-00-00">
                    </div>

                    <!-- Base -->
                    <div class="editor-field">
                        <label>Base de Mantenimiento</label>
                        <input type="text" name="base" id="edit_base">
                    </div>

                    <!-- Horas de Vuelo -->
                    <div class="editor-field">
                        <label>Horas de Vuelo</label>
                        <input type="number" step="any" min="0" name="horas" id="edit_horas">
                    </div>

                    <!-- Ciclos -->
                    <div class="editor-field">
                        <label>Ciclos</label>
                        <input type="number" step="1" min="0" name="ciclos" id="edit_ciclos">
                    </div>

                    <!-- Tiempo de Atención -->
                    <div class="editor-field">
                        <label>Tiempo de Atención (Horas)</label>
                        <input type="number" step="any" min="0" name="tiempo_atencion" id="edit_tiempo_atencion">
                    </div>

                    <!-- Componente Cambiado (Select) -->
                    <div class="editor-field">
                        <label>¿Se cambió componente?</label>
                        <select name="componente_cambiado" id="edit_componente_cambiado">
                            <option value="No">No</option>
                            <option value="Sí">Sí</option>
                        </select>
                    </div>

                    <!-- Detalles del Cambio de Componentes (Ancho Completo) -->
                    <div class="editor-field span-full" id="edit_seccion_componentes" style="display: none; background-color: #f8fafc; border: 1px solid #cbd5e1; padding: 15px; border-radius: 8px; margin-top: 10px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <!-- Removido -->
                            <div>
                                <h4 style="margin: 0 0 10px 0; color: #ef4444;"><i class="fas fa-arrow-right-from-bracket"></i> Componente Removido</h4>
                                <div style="margin-bottom: 10px;">
                                    <label style="font-size: 12px; font-weight: bold; color: #475569;">N/P (Número de Parte)</label>
                                    <input type="text" name="comp_removido_np" id="edit_comp_removido_np" style="width: 100%; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #475569;">N/S (Número de Serie)</label>
                                    <input type="text" name="comp_removido_ns" id="edit_comp_removido_ns" style="width: 100%; box-sizing: border-box;">
                                </div>
                            </div>
                            <!-- Instalado -->
                            <div>
                                <h4 style="margin: 0 0 10px 0; color: #22c55e;"><i class="fas fa-arrow-right-to-bracket"></i> Componente Instalado</h4>
                                <div style="margin-bottom: 10px;">
                                    <label style="font-size: 12px; font-weight: bold; color: #475569;">N/P (Número de Parte)</label>
                                    <input type="text" name="comp_instalado_np" id="edit_comp_instalado_np" style="width: 100%; box-sizing: border-box;">
                                </div>
                                <div>
                                    <label style="font-size: 12px; font-weight: bold; color: #475569;">N/S (Número de Serie)</label>
                                    <input type="text" name="comp_instalado_ns" id="edit_comp_instalado_ns" style="width: 100%; box-sizing: border-box;">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Descripción -->
                    <div class="editor-field span-full">
                        <label>Descripción del Reporte (Discrepancia)<span class="required-star">*</span></label>
                        <textarea name="descripcion" id="edit_descripcion" required></textarea>
                    </div>

                    <!-- Acción Correctiva -->
                    <div class="editor-field span-full">
                        <label>Acción Correctiva realizada<span class="required-star">*</span></label>
                        <textarea name="accion_correctiva" id="edit_accion_correctiva" required></textarea>
                    </div>

                    <!-- Consejos Técnicos & Tips -->
                    <div class="editor-field span-full">
                        <label>Consejos Técnicos / Tips para solución</label>
                        <textarea name="tips" id="edit_tips"></textarea>
                    </div>

                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 20px;">
                    <button type="button" style="background-color: #cbd5e1; color: #334155; border: none; padding: 11px 22px; border-radius: 8px; font-weight: bold; cursor: pointer;" onclick="cerrarEditor()">Cancelar</button>
                    <button type="submit" style="background-color: #1a419c; color: white; border: none; padding: 11px 25px; border-radius: 8px; font-weight: bold; cursor: pointer;"><i class="fas fa-floppy-disk"></i> Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const selectComp = document.getElementById('edit_componente_cambiado');
            const panelComp = document.getElementById('edit_seccion_componentes');
            
            function togglePanel() {
                if (selectComp.value === 'Sí') {
                    panelComp.style.display = 'block';
                } else {
                    panelComp.style.display = 'none';
                }
            }
            
            selectComp.addEventListener('change', togglePanel);
            window.toggleEditPanelComponents = togglePanel; // Hacerla accesible globalmente

            // Lógica para seleccionar/deseleccionar todas las filas (checkboxes)
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
        });

        // ─── EXPORTAR A EXCEL (solo Administradores en esta pantalla) ──────────
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

        function abrirEditor(reporte) {
            document.getElementById('edit_id_falla').value = reporte.id_falla || '';
            document.getElementById('edit_modelo').value = reporte.modelo || '';
            document.getElementById('edit_matricula').value = reporte.matricula || '';
            document.getElementById('edit_fecha').value = reporte.fecha || '';
            document.getElementById('edit_ata').value = reporte.ata || '';
            document.getElementById('edit_condicion').value = reporte.condicion || '';
            document.getElementById('edit_folio').value = reporte.folio || '';
            document.getElementById('edit_mel').value = reporte.mel || '';
            document.getElementById('edit_referencia').value = reporte.referencia || '';
            document.getElementById('edit_base').value = reporte.base || '';
            document.getElementById('edit_descripcion').value = reporte.descripcion || '';
            document.getElementById('edit_accion_correctiva').value = reporte.accion_correctiva || '';
            document.getElementById('edit_tips').value = reporte.tips || '';
            
            // Cargar nuevos campos de optimización
            document.getElementById('edit_horas').value = reporte.horas || '';
            document.getElementById('edit_ciclos').value = reporte.ciclos || '';
            document.getElementById('edit_tiempo_atencion').value = reporte.tiempo_atencion || '';
            
            const compCambiado = reporte.componente_cambiado || 'No';
            document.getElementById('edit_componente_cambiado').value = compCambiado;
            document.getElementById('edit_comp_removido_np').value = reporte.comp_removido_np || '';
            document.getElementById('edit_comp_removido_ns').value = reporte.comp_removido_ns || '';
            document.getElementById('edit_comp_instalado_np').value = reporte.comp_instalado_np || '';
            document.getElementById('edit_comp_instalado_ns').value = reporte.comp_instalado_ns || '';
            
            if (window.toggleEditPanelComponents) {
                window.toggleEditPanelComponents();
            }

            // Decodificar categoría consolidada (por ejemplo, "C / Solo de día")
            const catComp = reporte.categoria_mel || '';
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
            
            document.getElementById('edit_categoria_mel').value = catMel;
            document.getElementById('edit_restricciones_mel').value = resMel;

            document.getElementById('editorModalTitle').innerHTML = '<i class="fas fa-pen-to-square"></i> Corregir Reporte Técnico #' + reporte.id_falla;
            document.getElementById('editorModal').style.display = 'flex';
        }

        function cerrarEditor() {
            document.getElementById('editorModal').style.display = 'none';
        }
    </script>
</body>

</html>
