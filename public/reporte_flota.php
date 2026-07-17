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

// Bloqueo estricto para Técnicos y Otro (sin acceso a reporte de flota)
if (in_array($userRole, ['Técnico', 'Tecnico', 'Otro'])) {
    header('Location: dashboard.php');
    exit;
}

$isReadOnly = ($userRole === 'Supervisor');
$userName = (string)SessionManager::get('user_name', 'Usuario');

$flota = [];

try {
    $pdo = DatabaseConnection::getConnection();
    // Obtener la flota activa registrada en el sistema
    $stmt = $pdo->query("SELECT id_flota, modelo, matricula, numero_serie, estatus, taller, comentarios_relevantes, estatus_motores, fecha_ingreso, fecha_liberacion FROM tbc_Flota WHERE es_activo = 1 ORDER BY modelo, matricula");
    $flota = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {
    error_log("Error en reporte_flota: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Reporte de Flota - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Visualización y estado actual de las aeronaves activas en el Reporte de Flota.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool con Cache Busting -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <!-- Tipografía Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --font-titles: 'Outfit', sans-serif;
            --font-texts: 'Plus Jakarta Sans', sans-serif;
            --bg-app: #f3f6fd;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #1c3d5a;
            --text-secondary: #5f6368;
            --primary-blue: #1a419c;
            --blue-hover: #0d2b6d;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.05);
            --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.08), 0 2px 4px -1px rgba(0,0,0,0.04);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -2px rgba(0,0,0,0.04);
        }

        body {
            background-color: var(--bg-app);
            font-family: var(--font-texts);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
        }

        /* Badges de Identificación de Modelo Premium */
        .badge-modelo {
            display: inline-block !important;
            padding: 5px 14px !important;
            border-radius: 12px !important;
            font-size: 13px !important;
            font-weight: 800 !important;
            text-align: center !important;
            min-width: 75px !important;
            font-family: var(--font-titles) !important;
            letter-spacing: 0.5px !important;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04) !important;
            border: 1px solid transparent !important;
        }

        /* 525B: Azul marino claro */
        .badge-525b {
            background-color: #e0f2fe !important;
            color: #0369a1 !important;
            border-color: #bae6fd !important;
        }

        /* 680A: Gris claro */
        .badge-680a {
            background-color: #f1f5f9 !important;
            color: #475569 !important;
            border-color: #e2e8f0 !important;
        }

        /* CL605: Rojo claro */
        .badge-cl605 {
            background-color: #ffe4e6 !important;
            color: #b91c1c !important;
            border-color: #fecdd3 !important;
        }

        /* LJ75/45: Blanco brilloso */
        .badge-lj {
            background-color: #ffffff !important;
            color: #0f172a !important;
            border-color: #e2e8f0 !important;
            box-shadow: 0 0 10px rgba(255, 255, 255, 1), 0 2px 6px rgba(0,0,0,0.08) !important;
            text-shadow: 0 0 1px rgba(0, 0, 0, 0.05) !important;
        }

        /* Estilo Dinámico de Selectores de Estado de Flota */
        .select-status {
            font-weight: 800 !important;
            font-size: 11px !important;
            border-radius: 6px !important;
            padding: 6px 12px !important;
            border: 1px solid var(--border-color) !important;
            cursor: pointer;
            outline: none;
            width: 100px;
            text-align: center;
            transition: all 0.3s ease;
        }

        /* Colores de Estatus de Flota Símil Imagen */
        .status-OP {
            background-color: #d1fae5 !important;
            color: #065f46 !important;
            border-color: #a7f3d0 !important;
        }
        .status-MC {
            background-color: #fef3c7 !important;
            color: #92400e !important;
            border-color: #fde68a !important;
        }
        .status-GND {
            background-color: #f3f4f6 !important;
            color: #374151 !important;
            border-color: #e5e7eb !important;
        }
        .status-MP {
            background-color: #ffedd5 !important;
            color: #9a3412 !important;
            border-color: #fed7aa !important;
        }
        .status-AOG {
            background-color: #fee2e2 !important;
            color: #991b1b !important;
            border-color: #fca5a5 !important;
        }
        .status-PINTURA {
            background-color: #ffffff !important;
            color: #1e293b !important;
            border: 1px solid #475569 !important;
        }

        /* Estilos de Tabla de Reporte de Flota */
        .reporte-table-card {
            background-color: var(--bg-card);
            border-radius: 18px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-lg);
            padding: 25px;
            margin-top: 25px;
            overflow-x: auto;
        }

        .reporte-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            text-align: left;
        }

        .reporte-table th {
            background-color: #5b9bd5; /* Azul Celeste de la segunda imagen */
            color: #ffffff;
            font-family: var(--font-titles);
            font-weight: 700;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            text-transform: none;
            letter-spacing: 0.5px;
            font-size: 13px;
        }

        .reporte-table td {
            padding: 10px 12px;
            border: 1px solid #e2e8f0;
            vertical-align: middle;
            font-weight: 600;
            color: #334155;
        }

        .reporte-table tbody tr:nth-child(even) {
            background-color: #f8fafc;
        }

        .reporte-table tbody tr:hover {
            background-color: #f1f5f9;
        }

        /* Inputs y Campos de Tabla */
        .table-input {
            width: 100%;
            box-sizing: border-box;
            padding: 7px 10px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-family: var(--font-texts);
            font-size: 12px;
            color: #334155;
            outline: none;
            transition: all 0.2s;
            font-weight: 500;
        }

        .table-input:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 2px rgba(26, 65, 156, 0.15);
        }

        /* Taller Select e Input Opcional */
        .taller-container {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 90px;
        }

        .select-taller {
            padding: 6px 8px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 12px;
            font-weight: bold;
            color: #334155;
            outline: none;
            cursor: pointer;
        }

        .input-taller-libre {
            padding: 5px 8px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            font-size: 11px;
            width: 100%;
            box-sizing: border-box;
            display: none; /* Se muestra dinámicamente si selecciona "OTRO" */
        }

        /* Botón de Historial Símil Imagen */
        .btn-historial {
            background-color: #1a419c;
            color: #ffffff;
            border: none;
            padding: 7px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-historial:hover {
            background-color: #0d2b6d;
            transform: translateY(-1px);
            box-shadow: var(--shadow-sm);
        }

        /* Indicador Flotante de Guardado Automático */
        .save-indicator {
            position: fixed;
            bottom: 25px;
            right: 25px;
            background-color: #1e293b;
            color: #ffffff;
            padding: 10px 18px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: bold;
            display: none;
            align-items: center;
            gap: 10px;
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            font-family: var(--font-texts);
            letter-spacing: 0.5px;
        }

        .save-spinner {
            width: 14px;
            height: 14px;
            border: 2.5px solid #a5b4fc;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
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
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <div>
                <!-- Botón Volver al Dashboard sutil -->
                <a href="dashboard.php" style="color: var(--brand-blue); font-weight: bold; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 10px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                    <i class="fas fa-arrow-left"></i> Volver al Dashboard
                </a>
                <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Reporte de Flota</h1>
                <p style="margin: 5px 0 0 0; color: var(--text-secondary); font-size: 14px; font-weight: 500;">Visualización y estado actual de las aeronaves activas.</p>
            </div>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <!-- Tabla del Reporte de Flota -->
        <div class="reporte-table-card">
            <?php if (empty($flota)): ?>
                <div style="text-align: center; padding: 40px; color: var(--text-secondary);">
                    <i class="fas fa-plane-slash" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px;"></i>
                    <p style="font-size: 16px; font-weight: bold; margin: 0;">No hay aeronaves registradas en la flota activa.</p>
                    <p style="font-size: 13px; margin: 5px 0 0 0;">Puedes agregar aeronaves en el panel de Administración.</p>
                </div>
            <?php else: ?>
                <table class="reporte-table">
                    <thead>
                        <tr>
                            <th style="width: 10%;">Modelo</th>
                            <th style="width: 11%;">Matrícula</th>
                            <th style="width: 10%;">No. Serie</th>
                            <th style="width: 13%;">Estatus</th>
                            <th style="width: 13%;">Taller</th>
                            <th style="width: 18%;">Comentarios relevantes</th>
                            <th style="width: 13%;">Estatus Motores</th>
                            <th style="width: 11%;">Fecha de Ingreso</th>
                            <th style="width: 11%;">Fecha de Liberación (ETR)</th>
                            <th style="width: 10%;">Historial</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flota as $row): 
                            $id = (int)$row['id_flota'];
                            $estatusActual = !empty($row['estatus']) ? $row['estatus'] : 'OP';
                            $tallerActual = (string)$row['taller'];

                            // Verificar si es uno preestablecido o libre (TLC, ADN)
                            $esPreestablecido = ($tallerActual === 'TLC' || $tallerActual === 'ADN' || $tallerActual === '');
                            $selectVal = $esPreestablecido ? $tallerActual : 'OTRO';
                            $inputLibreVal = $esPreestablecido ? '' : $tallerActual;
                        ?>
                            <tr data-id="<?php echo $id; ?>">
                                <?php
                                $modeloClass = 'badge-680a'; // fallback por defecto (Gris)
                                $modeloClean = strtoupper(trim((string)$row['modelo']));
                                if (strpos($modeloClean, '525B') !== false) {
                                    $modeloClass = 'badge-525b';
                                } elseif (strpos($modeloClean, '680A') !== false) {
                                    $modeloClass = 'badge-680a';
                                } elseif (strpos($modeloClean, 'CL605') !== false) {
                                    $modeloClass = 'badge-cl605';
                                } elseif (strpos($modeloClean, 'LJ') !== false || strpos($modeloClean, '45') !== false || strpos($modeloClean, '75') !== false) {
                                    $modeloClass = 'badge-lj';
                                }
                                ?>
                                <td style="text-align: center; vertical-align: middle;">
                                    <span class="badge-modelo <?php echo $modeloClass; ?>">
                                        <?php echo htmlspecialchars($row['modelo'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="text-align: center; font-size: 14px; font-family: var(--font-titles); font-weight: 800; color: #1a419c;"><?php echo htmlspecialchars($row['matricula'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center; font-size: 13px; font-weight: 700; color: #475569;"><?php echo htmlspecialchars((string)$row['numero_serie'], ENT_QUOTES, 'UTF-8'); ?></td>
                                
                                <!-- ESTATUS SELECT -->
                                <td style="text-align: center;">
                                    <select class="select-status status-<?php echo $estatusActual; ?>" onchange="updateRowEstatus(this)" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                        <option value="OP" <?php echo $estatusActual === 'OP' ? 'selected' : ''; ?>>OP</option>
                                        <option value="MC" <?php echo $estatusActual === 'MC' ? 'selected' : ''; ?>>MC</option>
                                        <option value="GND" <?php echo $estatusActual === 'GND' ? 'selected' : ''; ?>>GND</option>
                                        <option value="MP" <?php echo $estatusActual === 'MP' ? 'selected' : ''; ?>>MP</option>
                                        <option value="AOG" <?php echo $estatusActual === 'AOG' ? 'selected' : ''; ?>>AOG</option>
                                        <option value="PINTURA" <?php echo $estatusActual === 'PINTURA' ? 'selected' : ''; ?>>PINTURA</option>
                                    </select>
                                </td>

                                <!-- TALLER (BASE) SELECT Y TEXTO OPCIONAL -->
                                <td>
                                    <div class="taller-container">
                                        <select class="select-taller" onchange="toggleTallerLibre(this)" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                            <option value="" <?php echo $selectVal === '' ? 'selected' : ''; ?>></option>
                                            <option value="TLC" <?php echo $selectVal === 'TLC' ? 'selected' : ''; ?>>TLC</option>
                                            <option value="ADN" <?php echo $selectVal === 'ADN' ? 'selected' : ''; ?>>ADN</option>
                                            <option value="OTRO" <?php echo $selectVal === 'OTRO' ? 'selected' : ''; ?>>OTRO</option>
                                        </select>
                                        <input type="text" class="table-input input-taller-libre" placeholder="Escribe base..." value="<?php echo htmlspecialchars($inputLibreVal, ENT_QUOTES, 'UTF-8'); ?>" onblur="saveRowData(this)" style="<?php echo $selectVal === 'OTRO' ? 'display: block;' : ''; ?>" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                    </div>
                                </td>

                                <!-- COMENTARIOS RELEVANTES -->
                                <td>
                                    <input type="text" class="table-input col-comentarios" value="<?php echo htmlspecialchars((string)$row['comentarios_relevantes'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Escribe comentarios..." onblur="saveRowData(this)" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                </td>

                                <!-- ESTATUS MOTORES -->
                                <td>
                                    <input type="text" class="table-input col-motores" value="<?php echo htmlspecialchars((string)$row['estatus_motores'], ENT_QUOTES, 'UTF-8'); ?>" placeholder="Estatus motores..." onblur="saveRowData(this)" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                </td>

                                <!-- FECHA INGRESO -->
                                <td>
                                    <input type="date" class="table-input col-fecha-ingreso" value="<?php echo !empty($row['fecha_ingreso']) ? $row['fecha_ingreso'] : ''; ?>" onchange="saveRowData(this)" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                </td>

                                <!-- FECHA LIBERACION -->
                                <td>
                                    <input type="date" class="table-input col-fecha-liberacion" value="<?php echo !empty($row['fecha_liberacion']) ? $row['fecha_liberacion'] : ''; ?>" onchange="saveRowData(this)" <?php echo $isReadOnly ? 'disabled' : ''; ?>>
                                </td>

                                <!-- HISTORIAL ACCIÓN -->
                                <td style="text-align: center;">
                                    <a href="consultar_fallas.php?modelo=<?php echo urlencode($row['modelo']); ?>&matricula=<?php echo urlencode($row['matricula']); ?>" class="btn-historial" title="Ver historial de fallas en consultar_fallas.php">
                                        <i class="fas fa-history"></i> Ver historial
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

    </div>

    <!-- Indicador Flotante de Guardado Asíncrono de Fondo -->
    <div id="save-indicator" class="save-indicator">
        <div class="save-spinner"></div>
        <span>Guardando cambios en vivo...</span>
    </div>

    <!-- Lógica de Guardado AJAX Inteligente en Caliente -->
    <script>
        // Función para cambiar de forma dinámica el color del selector de Estatus para calzar con la imagen
        function updateRowEstatus(selectElement) {
            const classList = selectElement.classList;
            
            // Limpiar clases de estados previas
            classList.remove('status-OP', 'status-MC', 'status-GND', 'status-MP', 'status-AOG', 'status-PINTURA');
            
            // Agregar la clase de estado actual
            classList.add('status-' + selectElement.value);

            // Guardar cambios inmediatamente de forma silenciosa de fondo
            saveRowData(selectElement);
        }

        // Función para habilitar o desvelar el input de texto libre al seleccionar "OTRO"
        function toggleTallerLibre(selectElement) {
            const container = selectElement.closest('.taller-container');
            const inputLibre = container.querySelector('.input-taller-libre');
            
            if (selectElement.value === 'OTRO') {
                inputLibre.style.display = 'block';
                inputLibre.focus();
            } else {
                inputLibre.style.display = 'none';
                inputLibre.value = ''; // Limpiar campo
                // Guardar cambio inmediatamente
                saveRowData(selectElement);
            }
        }

        // Función unificada asíncrona para guardar datos de la fila con AJAX
        async function saveRowData(element) {
            const tr = element.closest('tr');
            const idFlota = tr.getAttribute('data-id');
            const indicator = document.getElementById('save-indicator');

            // Capturar estatus
            const selectStatus = tr.querySelector('.select-status');
            const estatusValue = selectStatus ? selectStatus.value : 'OP';

            // Capturar taller (base)
            const selectTaller = tr.querySelector('.select-taller');
            const inputTallerLibre = tr.querySelector('.input-taller-libre');
            let tallerValue = '';
            if (selectTaller) {
                if (selectTaller.value === 'OTRO') {
                    tallerValue = inputTallerLibre ? inputTallerLibre.value.trim() : '';
                } else {
                    tallerValue = selectTaller.value;
                }
            }

            // Capturar comentarios
            const inputComentarios = tr.querySelector('.col-comentarios');
            const comentariosValue = inputComentarios ? inputComentarios.value.trim() : '';

            // Capturar motores
            const inputMotores = tr.querySelector('.col-motores');
            const motoresValue = inputMotores ? inputMotores.value.trim() : '';

            // Capturar fechas
            const inputFechaIngreso = tr.querySelector('.col-fecha-ingreso');
            const fechaIngresoValue = inputFechaIngreso ? inputFechaIngreso.value : '';

            const inputFechaLiberacion = tr.querySelector('.col-fecha-liberacion');
            const fechaLiberacionValue = inputFechaLiberacion ? inputFechaLiberacion.value : '';

            // Mostrar indicador visual de guardado sutil
            indicator.style.display = 'inline-flex';
            indicator.style.opacity = '1';

            try {
                const formData = new FormData();
                formData.append('id_flota', idFlota);
                formData.append('estatus', estatusValue);
                formData.append('taller', tallerValue);
                formData.append('comentarios_relevantes', comentariosValue);
                formData.append('estatus_motores', motoresValue);
                formData.append('fecha_ingreso', fechaIngresoValue);
                formData.append('fecha_liberacion', fechaLiberacionValue);

                const response = await fetch('api_reporte_flota.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();
                
                if (data.success) {
                    // Esperar 400ms para darle un feedback visual de guardado satisfactorio
                    setTimeout(() => {
                        indicator.style.opacity = '0';
                        setTimeout(() => { indicator.style.display = 'none'; }, 200);
                    }, 400);
                } else {
                    console.error("Error al guardar: ", data.error);
                }
            } catch (err) {
                console.error("Error en conexión AJAX: ", err);
            }
        }
    </script>
</body>

</html>
