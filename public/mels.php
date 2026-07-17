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

if (!SessionManager::has('user_id')) {
    header('Location: index.php');
    exit;
}

$userRole = (string)SessionManager::get('user_role', '');

// Autodetectar rol si falta en sesión activa
if (empty($userRole)) {
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

$uploadDir = __DIR__ . '/uploads/mels';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
    // Asegurar la carpeta con un archivo .htaccess para máxima seguridad DevSecOps (Prevenir RCE)
    $htaccessContent = "# Disable PHP execution inside uploads directory\n"
        . "<Files *.php>\n"
        . "    Order Deny,Allow\n"
        . "    Deny from all\n"
        . "</Files>\n"
        . "RemoveHandler .php .phtml .php3 .php4 .php5 .php6 .php7 .php8\n"
        . "RemoveType .php .phtml .php3 .php4 .php5 .php6 .php7 .php8\n"
        . "php_flag engine off\n";
    file_put_contents($uploadDir . '/.htaccess', $htaccessContent);
}

$msg = '';
$err = '';

// Procesar Subidas (Administrador, Ingeniero, Supervisor)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!in_array($userRole, ['Administrador', 'Ingeniero', 'Supervisor'], true)) {
        $err = "Acceso denegado: No tiene permisos para gestionar manuales MEL.";
    } else {
        if ($_POST['action'] === 'upload_mel') {
            $modelo = isset($_POST['modelo']) ? trim((string)$_POST['modelo']) : '';
            if (empty($modelo) || !preg_match('/^[a-zA-Z0-9\/+-]+$/', $modelo)) {
                $err = "Nombre de modelo no válido.";
            } else {
                $file = isset($_FILES['pdf']) ? $_FILES['pdf'] : null;
                if ($file && $file['error'] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    if ($ext !== 'pdf') {
                        $err = "Error: Únicamente se permiten archivos en formato PDF (.pdf).";
                    } else {
                        // Verificación estricta del tipo MIME real usando finfo (Evita bypasses)
                        $finfo = finfo_open(FILEINFO_MIME_TYPE);
                        $mime = finfo_file($finfo, $file['tmp_name']);
                        finfo_close($finfo);
                        if ($mime !== 'application/pdf') {
                            $err = "Error: El archivo cargado no es un documento PDF válido.";
                        } else {
                            $safeModel = str_replace('/', '_', $modelo);
                            $destPath = $uploadDir . '/' . $safeModel . '.pdf';
                            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                                $msg = "Manual MEL de " . htmlspecialchars($modelo) . " cargado y publicado con éxito.";
                            } else {
                                $err = "Error al intentar guardar el archivo en el servidor.";
                            }
                        }
                    }
                } else {
                    $err = "Error al cargar el archivo. Asegúrese de elegir un archivo PDF válido.";
                }
            }
        } elseif ($_POST['action'] === 'delete_mel') {
            $modelo = isset($_POST['modelo']) ? trim((string)$_POST['modelo']) : '';
            $safeModel = str_replace('/', '_', $modelo);
            $destPath = $uploadDir . '/' . $safeModel . '.pdf';
            if (file_exists($destPath)) {
                if (unlink($destPath)) {
                    $msg = "Manual MEL de " . htmlspecialchars($modelo) . " eliminado del servidor.";
                } else {
                    $err = "No se pudo eliminar el archivo físico.";
                }
            } else {
                $err = "El archivo no existe en el servidor.";
            }
        }
    }
}

// Obtener modelos activos de la flota
try {
    $pdo = DatabaseConnection::getConnection();
    $stmtModels = $pdo->query("SELECT DISTINCT modelo FROM tbc_Flota WHERE es_activo = 1 ORDER BY modelo");
    $modelos = $stmtModels->fetchAll(PDO::FETCH_COLUMN);
    
    // Si no hay modelos activos cargados, usamos los preestablecidos como fallback
    if (empty($modelos)) {
        $modelos = ['525B', '680A', 'CL605', 'LJ75/45'];
    }
} catch (\Exception $e) {
    $modelos = ['525B', '680A', 'CL605', 'LJ75/45'];
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manuales MEL - AleSearchTool</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;800&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        :root {
            --font-titles: 'Outfit', sans-serif;
            --font-texts: 'Plus Jakarta Sans', sans-serif;
            --bg-app: #f3f6fd;
            --bg-card: #ffffff;
            --border-color: #e2e8f0;
            --text-primary: #1c3d5a;
            --text-secondary: #5f6368;
            --brand-blue: #1a419c;
        }

        .mels-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .mel-card {
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.02);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }

        .mel-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 24px rgba(26, 65, 156, 0.08);
            border-color: #cbd5e1;
        }

        .mel-card-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 20px;
        }

        .mel-icon-box {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .mel-icon-active {
            background-color: #fef2f2;
            color: #ef4444;
            border: 1px solid #fee2e2;
        }

        .mel-icon-empty {
            background-color: #f8fafc;
            color: #94a3b8;
            border: 1px solid #f1f5f9;
        }

        .mel-model-title {
            font-family: var(--font-titles);
            font-size: 22px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
        }

        .mel-status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
            margin-top: 4px;
            text-transform: uppercase;
        }

        .status-badge-active {
            background-color: #d1fae5;
            color: #065f46;
        }

        .status-badge-empty {
            background-color: #f1f5f9;
            color: #475569;
        }

        .mel-info {
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 20px;
            line-height: 1.5;
        }

        .mel-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .btn-view-pdf {
            background-color: var(--brand-blue);
            color: #ffffff;
            border: none;
            border-radius: 10px;
            padding: 12px;
            font-weight: 700;
            font-size: 14px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.2s;
            text-decoration: none;
        }

        .btn-view-pdf:hover {
            background-color: #112d6c;
        }

        .btn-download-mel {
            background-color: #f1f5f9;
            color: #334155;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 10px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-download-mel:hover {
            background-color: #e2e8f0;
            color: #0f172a;
        }

        .btn-delete-mel {
            background-color: transparent;
            color: #ef4444;
            border: 1px solid #fee2e2;
            border-radius: 10px;
            padding: 8px;
            font-weight: 600;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 5px;
        }

        .btn-delete-mel:hover {
            background-color: #fef2f2;
            border-color: #fca5a5;
        }

        /* Formulario de Carga Estilizado */
        .upload-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
            border: 1px dashed #cbd5e1;
            padding: 16px;
            border-radius: 12px;
            background-color: #f8fafc;
        }

        .upload-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .upload-input-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }

        .btn-submit-upload {
            background-color: #10b981;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 10px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-submit-upload:hover {
            background-color: #059669;
        }

        /* Estilo del Visualizador PDF Integrado (Modal) */
        .pdf-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .pdf-modal-content {
            background-color: #ffffff;
            border-radius: 18px;
            width: 100%;
            max-width: 1000px;
            height: 85vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.15);
            overflow: hidden;
            animation: modalSlideIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalSlideIn {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .pdf-modal-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #f8fafc;
        }

        .pdf-modal-title {
            font-family: var(--font-titles);
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
        }

        .btn-close-modal {
            background: none;
            border: none;
            font-size: 22px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: color 0.2s;
        }

        .btn-close-modal:hover {
            color: #ef4444;
        }

        .pdf-iframe-container {
            flex-grow: 1;
            background-color: #475569;
            position: relative;
        }

        .pdf-iframe-container iframe {
            width: 100%;
            height: 100%;
            border: none;
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
                <!-- Botón Volver a Administración sutil -->
                <a href="administracion.php" style="color: var(--brand-blue); font-weight: bold; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; margin-bottom: 10px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                    <i class="fas fa-arrow-left"></i> Volver a Administración
                </a>
                <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Manuales MEL</h1>
                <p style="margin: 5px 0 0 0; color: var(--text-secondary); font-size: 14px; font-weight: 500;">Visualizar, descargar y gestionar las Listas de Equipamiento Mínimo de la flota.</p>
            </div>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <!-- Mensajes de Operación -->
        <?php if (!empty($msg)): ?>
            <div class="alert-success" style="background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 600;">
                <i class="fas fa-circle-check" style="font-size: 18px;"></i>
                <span><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>
        <?php if (!empty($err)): ?>
            <div class="alert-danger" style="background-color: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 15px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-weight: 600;">
                <i class="fas fa-circle-exclamation" style="font-size: 18px;"></i>
                <span><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <!-- Grid de Manuales por Modelo -->
        <div class="mels-grid">
            <?php foreach ($modelos as $modelo): 
                $safeModel = str_replace('/', '_', $modelo);
                $filePath = $uploadDir . '/' . $safeModel . '.pdf';
                $fileUrl = 'uploads/mels/' . rawurlencode($safeModel) . '.pdf';
                $exists = file_exists($filePath);
                $fileSizeStr = '';
                $fileDateStr = '';
                if ($exists) {
                    $bytes = filesize($filePath);
                    if ($bytes >= 1048576) {
                        $fileSizeStr = number_format($bytes / 1048576, 2) . ' MB';
                    } else {
                        $fileSizeStr = number_format($bytes / 1024, 2) . ' KB';
                    }
                    $fileDateStr = date('d/m/Y H:i', filemtime($filePath));
                }
            ?>
                <div class="mel-card">
                    <div>
                        <div class="mel-card-header">
                            <div class="mel-icon-box <?php echo $exists ? 'mel-icon-active' : 'mel-icon-empty'; ?>">
                                <i class="fas <?php echo $exists ? 'fa-file-pdf' : 'fa-file-circle-question'; ?>"></i>
                            </div>
                            <div>
                                <h3 class="mel-model-title"><?php echo htmlspecialchars($modelo); ?></h3>
                                <span class="mel-status-badge <?php echo $exists ? 'status-badge-active' : 'status-badge-empty'; ?>">
                                    <?php echo $exists ? 'Disponible' : 'Sin Archivo'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="mel-info">
                            <?php if ($exists): ?>
                                <p style="margin: 0 0 6px 0;"><i class="fas fa-calendar-alt" style="margin-right: 6px;"></i><strong>Actualizado:</strong> <?php echo $fileDateStr; ?></p>
                                <p style="margin: 0;"><i class="fas fa-weight-hanging" style="margin-right: 6px;"></i><strong>Tamaño:</strong> <?php echo $fileSizeStr; ?></p>
                            <?php else: ?>
                                <p style="margin: 0; color: #94a3b8;"><i class="fas fa-triangle-exclamation" style="margin-right: 6px;"></i>Manual de Lista de Equipamiento Mínimo (MEL) no cargado para esta aeronave.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mel-actions" style="margin-top: 15px;">
                        <?php if ($exists): ?>
                            <button class="btn-view-pdf" onclick="openPdfViewer('<?php echo htmlspecialchars($modelo, ENT_QUOTES, 'UTF-8'); ?>', '<?php echo $fileUrl; ?>')">
                                <i class="fas fa-eye"></i> Visualizar Manual
                            </button>
                            
                            <?php if (in_array($userRole, ['Administrador', 'Ingeniero', 'Supervisor'], true)): ?>
                                <form method="POST" enctype="multipart/form-data" class="upload-form" style="border: 1px dashed #cbd5e1; padding: 10px; box-shadow: none; background-color: #f8fafc; margin-top: 5px;">
                                    <input type="hidden" name="action" value="upload_mel">
                                    <input type="hidden" name="modelo" value="<?php echo htmlspecialchars($modelo, ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="upload-input-wrapper" style="padding: 6px;">
                                        <i class="fas fa-cloud-arrow-up" style="margin-right: 6px;"></i>
                                        <span class="file-label-text">Seleccionar PDF</span>
                                        <input type="file" name="pdf" accept="application/pdf" required onchange="updateFileName(this)">
                                    </div>
                                    <button type="submit" class="btn-submit-upload" style="background-color: #3b82f6; padding: 8px;">
                                        <i class="fas fa-arrows-rotate"></i> Subir Actualizado
                                    </button>
                                </form>

                                <form method="POST" onsubmit="return confirm('¿Está seguro de que desea eliminar permanentemente el manual MEL para <?php echo htmlspecialchars($modelo, ENT_QUOTES, 'UTF-8'); ?>?');" style="border: none; padding: 0; margin: 0; box-shadow: none; background: none;">
                                    <input type="hidden" name="action" value="delete_mel">
                                    <input type="hidden" name="modelo" value="<?php echo htmlspecialchars($modelo, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn-delete-mel" style="width: 100%;">
                                        <i class="fas fa-trash-can"></i> Eliminar Manual
                                    </button>
                                </form>
                            <?php endif; ?>

                        <?php else: ?>
                            <?php if (in_array($userRole, ['Administrador', 'Ingeniero', 'Supervisor'], true)): ?>
                                <form method="POST" enctype="multipart/form-data" class="upload-form" style="border: 1px dashed #cbd5e1; padding: 14px; box-shadow: none; background-color: #f8fafc;">
                                    <input type="hidden" name="action" value="upload_mel">
                                    <input type="hidden" name="modelo" value="<?php echo htmlspecialchars($modelo, ENT_QUOTES, 'UTF-8'); ?>">
                                    
                                    <div class="upload-input-wrapper">
                                        <i class="fas fa-cloud-arrow-up" style="margin-right: 8px;"></i>
                                        <span class="file-label-text">Seleccionar PDF</span>
                                        <input type="file" name="pdf" accept="application/pdf" required onchange="updateFileName(this)">
                                    </div>
                                    
                                    <button type="submit" class="btn-submit-upload">
                                        <i class="fas fa-upload"></i> Subir Manual
                                    </button>
                                </form>
                            <?php else: ?>
                                <div style="text-align: center; color: #94a3b8; font-size: 13px; font-weight: 600; padding: 12px; border: 1px dashed #e2e8f0; border-radius: 10px; background-color: #f8fafc;">
                                    <i class="fas fa-lock" style="margin-right: 6px;"></i> Solo lectura disponible
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- MODAL VISUALIZADOR PDF -->
    <div id="pdfViewerModal" class="pdf-modal" onclick="closeOnBackdrop(event)" oncontextmenu="return false;">
        <div class="pdf-modal-content">
            <div class="pdf-modal-header">
                <h3 id="modalPdfTitle" class="pdf-modal-title">Visualizador de Manual MEL</h3>
                <button class="btn-close-modal" onclick="closePdfViewer()">
                    <i class="fas fa-xmark"></i>
                </button>
            </div>
            <div class="pdf-iframe-container">
                <iframe id="pdfIframe" src="" oncontextmenu="return false;"></iframe>
            </div>
        </div>
    </div>

    <script>
        function updateFileName(input) {
            const fileName = input.files[0] ? input.files[0].name : "Seleccionar PDF";
            const labelText = input.parentElement.querySelector('.file-label-text');
            if (labelText) {
                labelText.textContent = fileName.length > 20 ? fileName.substring(0, 17) + "..." : fileName;
            }
        }

        function openPdfViewer(modelo, fileUrl) {
            // Detectar dispositivos iOS (iPhone, iPad, iPod)
            const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            
            if (isIOS) {
                // Los iframes con PDF nativo tienen problemas en Safari/iOS.
                // Además, window.open suele ser bloqueado por el anti-popup estricto de Safari móvil.
                // La navegación directa garantiza que el visor de PDF nativo de iOS se abra sin bloqueos.
                window.location.href = fileUrl;
                return;
            }

            document.getElementById('modalPdfTitle').textContent = "Manual MEL - " + modelo;
            // Deshabilitar la barra de herramientas del visualizador nativo del navegador (#toolbar=0)
            document.getElementById('pdfIframe').src = fileUrl + "?t=" + new Date().getTime() + "#toolbar=0&navpanes=0&scrollbar=1";
            document.getElementById('pdfViewerModal').style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }

        function closePdfViewer() {
            document.getElementById('pdfViewerModal').style.display = 'none';
            document.getElementById('pdfIframe').src = '';
            document.body.style.overflow = '';
        }

        function closeOnBackdrop(event) {
            const modal = document.getElementById('pdfViewerModal');
            if (event.target === modal) {
                closePdfViewer();
            }
        }

        // Bloquear atajos de teclado para guardar, imprimir o inspeccionar (Ctrl+P, Ctrl+S, F12, Ctrl+Shift+I)
        window.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'p' || e.key === 's' || e.key === 'P' || e.key === 'S')) {
                e.preventDefault();
                alert('⚠️ Acción Protegida: No está permitido descargar ni imprimir este manual de seguridad.');
            }
            if (e.key === 'F12' || ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'i' || e.key === 'I'))) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>
