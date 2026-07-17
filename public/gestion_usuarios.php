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

// Bloqueo estricto a no-administradores (Gatillo de seguridad OWASP)
if ($userRole !== 'Administrador') {
    header('Location: dashboard.php');
    exit;
}

$userName = (string)SessionManager::get('user_name', 'Usuario');
$err = '';
$msg = '';
$usuarios = [];

try {
    $pdo = DatabaseConnection::getConnection();

    // Auto-Sanación de la Base de Datos: Agregar columna de rol si no existe
    $checkCol = $pdo->query("SHOW COLUMNS FROM tbc_Usuario LIKE 'rol'")->fetch();
    if (!$checkCol) {
        $pdo->exec("ALTER TABLE tbc_Usuario ADD COLUMN rol VARCHAR(50) NOT NULL DEFAULT 'Administrador'");
        // Forzar a los usuarios iniciales como Administradores
        $pdo->exec("UPDATE tbc_Usuario SET rol = 'Administrador' WHERE correo IN ('l.rodriguez@aleservicecenter.com', 'admin@aleservicecenter.com')");
    }

    // --- PROCESAR SOLICITUDES POST (ALTA / EDICIÓN) ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];
        $nombre = trim((string)filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT));
        $correo = strtolower(trim((string)filter_input(INPUT_POST, 'correo', FILTER_VALIDATE_EMAIL)));
        $rol    = trim((string)filter_input(INPUT_POST, 'rol', FILTER_DEFAULT));
        $activo = (int)filter_input(INPUT_POST, 'es_activo', FILTER_VALIDATE_INT);

        if ($action === 'add_user') {
            if (empty($nombre) || empty($correo) || empty($rol)) {
                $err = "Por favor, rellene todos los campos requeridos para el alta.";
            } else {
                // Verificar si ya existe
                $stmtCheck = $pdo->prepare("SELECT id_usuario FROM tbc_Usuario WHERE correo = :correo");
                $stmtCheck->execute([':correo' => $correo]);
                if ($stmtCheck->fetchColumn() !== false) {
                    $err = "El correo institucional '{$correo}' ya se encuentra registrado en el sistema.";
                } else {
                    $stmtAdd = $pdo->prepare("INSERT INTO tbc_Usuario (nombre, correo, rol, es_activo) VALUES (:nom, :corr, :rol, 1)");
                    $stmtAdd->execute([
                        ':nom'  => $nombre,
                        ':corr' => $correo,
                        ':rol'  => $rol
                    ]);
                    $msg = "Usuario '{$nombre}' registrado y dado de alta exitosamente con el rol '{$rol}'.";
                }
            }
        } elseif ($action === 'edit_user') {
            $idUser = (int)filter_input(INPUT_POST, 'id_usuario', FILTER_VALIDATE_INT);
            if ($idUser <= 0 || empty($nombre) || empty($correo) || empty($rol)) {
                $err = "Datos de edición de usuario inválidos o incompletos.";
            } else {
                $stmtUp = $pdo->prepare("UPDATE tbc_Usuario SET nombre = :nom, correo = :corr, rol = :rol, es_activo = :act WHERE id_usuario = :id");
                $stmtUp->execute([
                    ':nom'  => $nombre,
                    ':corr' => $correo,
                    ':rol'  => $rol,
                    ':act'  => $activo,
                    ':id'   => $idUser
                ]);
                $msg = "Los datos del usuario '{$nombre}' han sido actualizados de forma exitosa.";
            }
        }
    }

    // --- PROCESAR SOLICITUDES GET (BAJA / ELIMINAR) ---
    if (isset($_GET['delete_id'])) {
        $delId = (int)$_GET['delete_id'];
        if ($delId === (int)SessionManager::get('user_id')) {
            $err = "Por razones de seguridad, no puedes dar de baja tu propio usuario administrador activo.";
        } elseif ($delId > 0) {
            $stmtDel = $pdo->prepare("DELETE FROM tbc_Usuario WHERE id_usuario = :id");
            $stmtDel->execute([':id' => $delId]);
            $msg = "El usuario ha sido dado de baja (eliminado) del catálogo del sistema.";
        }
    }

    // Consultar todos los usuarios activos
    $stmtQuery = $pdo->query("SELECT id_usuario, nombre, correo, rol, es_activo, foto_url FROM tbc_Usuario ORDER BY rol, nombre");
    $usuarios = $stmtQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (\Exception $e) {
    error_log("Error gestion_usuarios: " . $e->getMessage());
    $err = "Error en base de datos: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Gestión de Usuarios - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Módulo de control de acceso institucional y asignación de roles.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <style>
        .btn-add-user {
            background-color: #1a419c;
            color: #ffffff;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 700;
            font-family: 'Plus Jakarta Sans', sans-serif;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: background-color 0.2s;
        }
        .btn-add-user:hover {
            background-color: #0f2d72;
        }
        .badge-role {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
        }
        .badge-admin {
            background-color: #fef3c7;
            color: #d97706;
            border: 1px solid #fcd34d;
        }
        .badge-ingeniero {
            background-color: #e0f2fe;
            color: #0369a1;
            border: 1px solid #bae6fd;
        }
        .badge-supervisor {
            background-color: #f3e8ff;
            color: #6b21a8;
            border: 1px solid #e9d5ff;
        }
        .badge-tecnico {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .badge-otro {
            background-color: #f1f5f9;
            color: #475569;
            border: 1px solid #cbd5e1;
        }
        .badge-status-on {
            background-color: #d1fae5;
            color: #065f46;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
        }
        .badge-status-off {
            background-color: #fee2e2;
            color: #991b1b;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
        }
        .btn-table-edit {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-table-edit:hover {
            background-color: #eef2ff;
            color: #1a419c;
            border-color: #c7d2fe;
        }
        .btn-table-delete {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-table-delete:hover {
            background-color: #fef2f2;
            color: #dc2626;
            border-color: #fca5a5;
        }

        /* Modales */
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
        }
        .modal-content {
            background: #ffffff;
            padding: 30px;
            border-radius: 16px;
            width: 500px;
            max-width: 90%;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
            border: 1px solid #cbd5e1;
            box-sizing: border-box;
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
            font-size: 20px;
            font-weight: 800;
        }
        .modal-close-btn {
            background: none;
            border: none;
            color: #64748b;
            font-size: 22px;
            cursor: pointer;
        }
        .modal-close-btn:hover {
            color: #dc2626;
        }
        .editor-field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 15px;
        }
        .editor-field label {
            font-weight: 700;
            color: #475569;
            font-size: 13.5px;
        }
        .editor-field input, .editor-field select {
            padding: 10px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            outline: none;
            box-sizing: border-box;
        }
        .editor-field input:focus, .editor-field select:focus {
            border-color: #1a419c;
            box-shadow: 0 0 0 3px rgba(26, 65, 156, 0.15);
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
                <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Gestión de Usuarios</h1>
                <a href="administracion.php" style="color: #1a419c; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fas fa-arrow-left"></i> Volver a Administración
                </a>
            </div>
            
            <div style="display: flex; gap: 15px; align-items: center;">
                <button onclick="abrirAltaUsuario()" class="btn-add-user">
                    <i class="fas fa-user-plus"></i> Registrar Nuevo Correo
                </button>
                <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm); height: 42px; box-sizing: border-box;">
                    <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
                </div>
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

        <!-- Tabla de Usuarios Registrados -->
        <div class="table-container">
            <h2><i class="fas fa-users-gear"></i> Catálogo de Personal Autorizado (<?php echo count($usuarios); ?>)</h2>
            
            <?php if (empty($usuarios)): ?>
                <div style="text-align: center; padding: 40px; background-color: #ffffff; border-radius: 8px;">
                    <i class="fas fa-user-slash" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px;"></i>
                    <p style="margin: 0; color: #64748b; font-size: 15px; font-weight: 600;">No hay usuarios registrados en el catálogo.</p>
                </div>
            <?php else: ?>
                <table class="fallas-table">
                    <thead>
                        <tr>
                            <th style="width: 10%; text-align: center;">Avatar</th>
                            <th style="width: 25%; text-align: left !important;">Nombre Técnico</th>
                            <th style="width: 25%; text-align: left !important;">Correo Institucional</th>
                            <th style="width: 15%; text-align: center;">Rol de Acceso</th>
                            <th style="width: 13%; text-align: center;">Estatus</th>
                            <th style="width: 12%; text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $item): ?>
                            <tr>
                                <td style="text-align: center;">
                                    <?php if (!empty($item['foto_url'])): ?>
                                        <img src="<?php echo htmlspecialchars($item['foto_url'], ENT_QUOTES, 'UTF-8'); ?>" style="width: 36px; height: 36px; border-radius: 50%; border: 2px solid #e2e8f0; object-fit: cover;">
                                    <?php else: ?>
                                        <div style="width: 36px; height: 36px; border-radius: 50%; background-color: #e2e8f0; display: inline-flex; align-items: center; justify-content: center; color: #64748b; font-weight: 800; font-size: 14px;">
                                            <?php echo strtoupper(substr((string)$item['nombre'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-weight: bold; text-align: left !important;"><?php echo htmlspecialchars((string)$item['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="font-weight: 600; color: #1a419c; text-align: left !important;"><?php echo htmlspecialchars((string)$item['correo'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td style="text-align: center;">
                                    <?php 
                                    $rolClave = strtolower(str_replace(['é', 'á'], ['e', 'a'], $item['rol']));
                                    $badgeClass = "badge-" . $rolClave;
                                    ?>
                                    <span class="badge-role <?php echo $badgeClass; ?>">
                                        <?php echo htmlspecialchars($item['rol'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ((int)$item['es_activo'] === 1): ?>
                                        <span class="badge-status-on"><i class="fas fa-circle-check"></i> Activo</span>
                                    <?php else: ?>
                                        <span class="badge-status-off"><i class="fas fa-circle-xmark"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <div style="display: flex; gap: 6px; justify-content: center;">
                                        <button class="btn-table-edit" onclick='abrirEdicionUsuario(<?php echo json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Editar datos y rol">
                                            <i class="fas fa-user-pen"></i>
                                        </button>
                                        <button class="btn-table-delete" onclick="eliminarUsuario(<?php echo $item['id_usuario']; ?>, '<?php echo htmlspecialchars((string)$item['nombre'], ENT_QUOTES, 'UTF-8'); ?>')" title="Dar de baja usuario">
                                            <i class="fas fa-trash-can"></i>
                                        </button>
                                    </div>
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

    <!-- MODAL PARA ALTA / EDICIÓN DE USUARIOS -->
    <div class="modal-overlay" id="userModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-user-plus"></i> Dar de Alta Colaborador</h3>
                <button class="modal-close-btn" onclick="cerrarModalUsuario()"><i class="fas fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="gestion_usuarios.php">
                <input type="hidden" name="action" id="modal_action" value="add_user">
                <input type="hidden" name="id_usuario" id="modal_id_usuario" value="">
                
                <!-- Nombre Técnico -->
                <div class="editor-field">
                    <label>Nombre Técnico Completo<span style="color:#dc2626;">*</span></label>
                    <input type="text" name="nombre" id="modal_nombre" placeholder="ej. Leonardo Rodriguez..." required style="width: 100%;">
                </div>

                <!-- Correo Institucional -->
                <div class="editor-field">
                    <label>Correo Electrónico Institucional<span style="color:#dc2626;">*</span></label>
                    <input type="email" name="correo" id="modal_correo" placeholder="ej. l.rodriguez@aleservicecenter.com" required style="width: 100%;">
                </div>

                <!-- Rol de Acceso -->
                <div class="editor-field">
                    <label>Rol de Acceso Asignado<span style="color:#dc2626;">*</span></label>
                    <select name="rol" id="modal_rol" required style="width: 100%;">
                        <option value="Administrador">Administrador</option>
                        <option value="Ingeniero">Ingeniero</option>
                        <option value="Supervisor">Supervisor</option>
                        <option value="Técnico">Técnico</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>

                <!-- Estatus (Solo Edición) -->
                <div class="editor-field" id="modal_status_field" style="display: none;">
                    <label>Estatus de la Cuenta<span style="color:#dc2626;">*</span></label>
                    <select name="es_activo" id="modal_activo" style="width: 100%;">
                        <option value="1">Activo (Acceso Autorizado)</option>
                        <option value="0">Inactivo (Acceso Suspendido)</option>
                    </select>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    <button type="button" class="btn-filter-reset" onclick="cerrarModalUsuario()" style="height: auto;">Cancelar</button>
                    <button type="submit" class="btn-primary-action" id="modalSubmitBtn"><i class="fas fa-save"></i> Registrar Correo</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts de interactividad -->
    <script>
        function abrirAltaUsuario() {
            document.getElementById('modal_action').value = 'add_user';
            document.getElementById('modal_id_usuario').value = '';
            document.getElementById('modal_nombre').value = '';
            document.getElementById('modal_correo').value = '';
            document.getElementById('modal_rol').value = 'Técnico';
            document.getElementById('modal_status_field').style.display = 'none';
            
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Dar de Alta Colaborador';
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Registrar Correo';
            
            document.getElementById('userModal').style.display = 'flex';
        }

        function abrirEdicionUsuario(user) {
            document.getElementById('modal_action').value = 'edit_user';
            document.getElementById('modal_id_usuario').value = user.id_usuario;
            document.getElementById('modal_nombre').value = user.nombre;
            document.getElementById('modal_correo').value = user.correo;
            document.getElementById('modal_rol').value = user.rol;
            document.getElementById('modal_activo').value = user.es_activo;
            document.getElementById('modal_status_field').style.display = 'flex';
            
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-pen"></i> Editar Perfil de Acceso';
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-floppy-disk"></i> Guardar Cambios';
            
            document.getElementById('userModal').style.display = 'flex';
        }

        function cerrarModalUsuario() {
            document.getElementById('userModal').style.display = 'none';
        }

        function eliminarUsuario(id, nombre) {
            if (confirm("⚠️ ¿Estás completamente seguro de que deseas dar de BAJA (eliminar) permanentemente al usuario '" + nombre + "'?")) {
                window.location.href = 'gestion_usuarios.php?delete_id=' + id;
            }
        }
    </script>
</body>

</html>
