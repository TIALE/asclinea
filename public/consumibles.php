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

// Bloqueo total para rol "Otro"
if ($userRole === 'Otro') {
    header('Location: dashboard.php');
    exit;
}

$userName = (string)SessionManager::get('user_name', 'Usuario');
$err = '';
$msg = '';
$consumiblesPorCategoria = [];

$isAdmin = ($userRole === 'Administrador');

try {
    $pdo = DatabaseConnection::getConnection();

    // 1. Crear tabla de consumibles si no existe (Sintaxis MySQL/MariaDB)
    $pdo->exec("CREATE TABLE IF NOT EXISTS consumibles (
        id INT AUTO_INCREMENT PRIMARY KEY,
        categoria VARCHAR(150) NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        numero_parte VARCHAR(150) NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // --- PROCESAR OPERACIONES DE ADMINISTRACIÓN (ALTA / BAJA / EDICIÓN) ---
    
    // Alta o Edición (POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!$isAdmin) {
            $err = "Acceso denegado: Solo los administradores autorizados pueden agregar o editar consumibles.";
        } else {
            $action    = $_POST['action'];
            $categoria = trim((string)filter_input(INPUT_POST, 'categoria', FILTER_DEFAULT));
            $nombre    = trim((string)filter_input(INPUT_POST, 'nombre', FILTER_DEFAULT));
            $np        = trim((string)filter_input(INPUT_POST, 'numero_parte', FILTER_DEFAULT));

            if (empty($categoria) || empty($nombre) || empty($np)) {
                $err = "Por favor, complete todos los campos requeridos.";
            } else {
                if ($action === 'add_consumible') {
                    $stmtAdd = $pdo->prepare("INSERT INTO consumibles (categoria, nombre, numero_parte) VALUES (:cat, :nom, :np)");
                    $stmtAdd->execute([
                        ':cat' => $categoria,
                        ':nom' => $nombre,
                        ':np'  => $np
                    ]);
                    $msg = "Se ha dado de alta el consumible '{$nombre}' en la sección de '{$categoria}' exitosamente.";
                } elseif ($action === 'edit_consumible') {
                    $id = (int)filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
                    if ($id <= 0) {
                        $err = "Identificador de consumible inválido.";
                    } else {
                        $stmtEdit = $pdo->prepare("UPDATE consumibles SET categoria = :cat, nombre = :nom, numero_parte = :np WHERE id = :id");
                        $stmtEdit->execute([
                            ':cat' => $categoria,
                            ':nom' => $nombre,
                            ':np'  => $np,
                            ':id'  => $id
                        ]);
                        $msg = "Se han guardado las correcciones del consumible '{$nombre}' con éxito.";
                    }
                }
            }
        }
    }

    // Baja / Eliminar (GET)
    if (isset($_GET['delete_id'])) {
        if (!$isAdmin) {
            $err = "Acceso denegado: Solo los administradores autorizados pueden eliminar consumibles.";
        } else {
            $deleteId = (int)$_GET['delete_id'];
            if ($deleteId > 0) {
                $stmtDel = $pdo->prepare("DELETE FROM consumibles WHERE id = :id");
                $stmtDel->execute([':id' => $deleteId]);
                $msg = "El consumible ha sido dado de baja (eliminado) correctamente de la lista.";
            }
        }
    }

    // 2. Siembra Inicial Actualizada (Fuerza de carga inicial para amoldarse al catálogo oficial del usuario)
    $stmtCount = $pdo->query("SELECT COUNT(*) FROM consumibles");
    $count = (int)$stmtCount->fetchColumn();

    // Si es una carga inicial o queremos forzar el sembrado exacto del usuario:
    if ($count === 0 || isset($_GET['force_seed'])) {
        if (isset($_GET['force_seed']) && !$isAdmin) {
            $err = "Acceso denegado: Solo los administradores autorizados pueden re-sembrar el catálogo oficial.";
        } else {
            // Limpiamos si se forzó la semilla
            if (isset($_GET['force_seed'])) {
                $pdo->exec("DELETE FROM consumibles");
            }

        $datosOficiales = [
            // Misceláneos
            ["Misceláneos", "Empaque de metal para líneas hidráulicas", "AP50N-6"],
            ["Misceláneos", "Alambre de frenar", "MS20995-C"],
            ["Misceláneos", "Cinta doble cara", "POLIKEN 108FRBLK"],
            ["Misceláneos", "Drain master (680A y SILPAK A400)", "8417"],
            ["Misceláneos", "Mula skydrol", "06-4035-0500"],

            // Aceites y Fluidos Hidráulicos
            ["Aceites y Fluidos Hidráulicos", "Aceite Turbina JET-254", "JET-254"],
            ["Aceites y Fluidos Hidráulicos", "Aceite Turbina Mobil 2380", "2380"],
            ["Aceites y Fluidos Hidráulicos", "Aceite Aeroshell #500", "Aeroshell#500"],
            ["Aceites y Fluidos Hidráulicos", "Aceite Turbina JET-11", "JET-11"],
            ["Aceites y Fluidos Hidráulicos", "Aceite Hidráulico para Cessna Latitude (Bravo Micronic 88)", "MIL-PRF-87257"],
            ["Aceites y Fluidos Hidráulicos", "Aceite Hidráulico Rojo", "MIL-PRF-5606"],
            ["Aceites y Fluidos Hidráulicos", "Fluido Hidráulico Skydrol", "500B-4"],
            ["Aceites y Fluidos Hidráulicos", "Fluido Hidráulico CJ3", "MIL-PRF-83282"],
            ["Aceites y Fluidos Hidráulicos", "Líquido para la Air cycle machine (CJ3 y Beechcraft 400)", "EMKARATE RL 100E (LR100E)"],
            ["Aceites y Fluidos Hidráulicos", "Aceite para Robinair", "EMKARATE RL 100E (LR100E)"],

            // Selladores y Prorreactivos (PRC / RTV)
            ["Selladores y Prorreactivos (PRC / RTV)", "Sellador PRC B1/2", "PR1425B1/2PT"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Sellador PRC B1/2", "PR1422B1/2PT"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Silicón RTV Blanco", "RTV102"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Silicón RTV Negro", "RTV103"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Silicón RTV Gris", "RTV 167"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Silicón RTV Sellante de alta temperatura", "RTV736"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Silicón RTV Transparente", "RTV108"],
            ["Selladores y Prorreactivos (PRC / RTV)", "Silicón RTV Naranja", "RTV106"],

            // Lubricantes y Grasas
            ["Lubricantes y Grasas", "Lubricante LPS 1 (Sin grasa)", "LPS1"],
            ["Lubricantes y Grasas", "Lubricante LPS 2", "LPS2"],
            ["Lubricantes y Grasas", "Lubricante LPS 3 (Inhibidor de óxido)", "LPS3"],
            ["Lubricantes y Grasas", "Grasa Molikote 33", "MOLIKOTE33"],
            ["Lubricantes y Grasas", "Grasa Royco 32", "Royco 32"],
            ["Lubricantes y Grasas", "Grasa Lubriplate AA", "630-AA"],
            ["Lubricantes y Grasas", "Metal free thread lubricant", "MIL-PRF-83483"],
            ["Lubricantes y Grasas", "Lubricante de película seca (Dow Corning 321)", "321"],
            ["Lubricantes y Grasas", "Grasa Aeroshell 7", "AEROSHELL 7"],

            // Químicos, Limpiadores y Adhesivos
            ["Químicos, Limpiadores y Adhesivos", "Líquido AL5 - TKS", "AL5 - TKS"],
            ["Químicos, Limpiadores y Adhesivos", "Compuesto anticorrosivo CA1000", "CA1000"],
            ["Químicos, Limpiadores y Adhesivos", "Primer tempo green (Imprimador)", "A-702"],
            ["Químicos, Limpiadores y Adhesivos", "Disolvente MEK-1", "MEK-1"],
            ["Químicos, Limpiadores y Adhesivos", "Lubricante multiusos WD-40", "WD-40"],
            ["Químicos, Limpiadores y Adhesivos", "Limpiador de contactos (Contac Cleaner)", "03116"],
            ["Químicos, Limpiadores y Adhesivos", "Sherlock detector de fugas", "MIL-PRF-25567E"],
            ["Químicos, Limpiadores y Adhesivos", "Gas Refrigerante R134A (CJ3 y Beechcraft 400)", "R134A"],
            ["Químicos, Limpiadores y Adhesivos", "Alcohol de CJ3", "TT-l-735"],
            ["Químicos, Limpiadores y Adhesivos", "Freeze Spray (Enfriador en aerosol)", "ES1551"],
            ["Químicos, Limpiadores y Adhesivos", "Adhesivo Loctite", "495-03 ó U64070"]
        ];

        $stmtInsert = $pdo->prepare("INSERT INTO consumibles (categoria, nombre, numero_parte) VALUES (:cat, :nom, :np)");
        foreach ($datosOficiales as $fila) {
            $stmtInsert->execute([
                ':cat' => $fila[0],
                ':nom' => $fila[1],
                ':np'  => $fila[2]
            ]);
        }
    }
}

    // 3. Consultar y estructurar por categoría
    $stmt = $pdo->query("SELECT * FROM consumibles ORDER BY categoria, nombre");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all as $item) {
        $consumiblesPorCategoria[$item['categoria']][] = $item;
    }

} catch (\Exception $e) {
    error_log("Error consumibles: " . $e->getMessage());
    $err = "Error en base de datos: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Buscar N/P Consumible - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Buscador y gestor de números de parte de consumibles.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <style>
        .btn-copiar {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        .btn-copiar:hover {
            background-color: #e2e8f0;
            color: #1e293b;
            border-color: #94a3b8;
        }
        .btn-action-edit {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-action-edit:hover {
            background-color: #eef2ff;
            color: #1a419c;
            border-color: #c7d2fe;
        }
        .btn-action-delete {
            background-color: #f1f5f9;
            border: 1px solid #cbd5e1;
            color: #475569;
            padding: 6px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .btn-action-delete:hover {
            background-color: #fef2f2;
            color: #dc2626;
            border-color: #fca5a5;
        }
        .seccion-categoria {
            background-color: #ffffff;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-sm);
        }
        .seccion-categoria h2 {
            font-family: 'Outfit', sans-serif;
            color: #1a419c;
            margin-top: 0;
            margin-bottom: 18px;
            font-size: 20px;
            font-weight: 800;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn-primary-action {
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
            text-decoration: none;
        }
        .btn-primary-action:hover {
            background-color: #0f2d72;
        }

        /* Modal styling */
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

        <!-- Header superior con Logo y Acción -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <div style="display: flex; flex-direction: column; gap: 5px;">
                <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Buscar N/P Consumible</h1>
                <a href="administracion.php" style="color: #1a419c; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fas fa-arrow-left"></i> Volver a Administración
                </a>
            </div>
            
            <div style="display: flex; gap: 15px; align-items: center;">
                <?php if ($isAdmin): ?>
                <button onclick="abrirAltaConsumible()" class="btn-primary-action">
                    <i class="fas fa-plus-circle"></i> Dar de Alta Consumible
                </button>
                <?php endif; ?>
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

        <!-- Buscador Interactivo -->
        <div style="background: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 30px; box-shadow: var(--shadow-sm); display: flex; justify-content: space-between; align-items: center; gap: 20px;">
            <div style="flex: 1; position: relative;">
                <i class="fas fa-magnifying-glass" style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 16px;"></i>
                <input type="text" id="buscador-consumibles" placeholder="Buscar por descripción, nombre o número de parte en el catálogo..." style="width: 100%; padding: 12px 15px 12px 45px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 14.5px; box-sizing: border-box; outline: none; transition: border-color 0.2s;" onkeyup="filtrarConsumibles()">
            </div>
            <?php if ($isAdmin): ?>
            <div>
                <a href="consumibles.php?force_seed=1" class="btn-filter-reset" title="Restaurar el catálogo a la lista oficial predefinida" style="white-space: nowrap; height: 45px;" onclick="return confirm('¿Deseas restaurar la lista al catálogo oficial original? Se perderán los consumibles agregados manualmente.')">
                    <i class="fas fa-arrow-rotate-left"></i> Re-cargar Catálogo Oficial
                </a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Listado de Secciones de Consumibles -->
        <div id="contenedor-categorias">
            <?php foreach ($consumiblesPorCategoria as $categoria => $items): ?>
                <div class="seccion-categoria" id="cat-<?php echo md5($categoria); ?>">
                    <h2>
                        <?php 
                        $iconClass = "fa-vial";
                        if ($categoria === "Misceláneos") $iconClass = "fa-cubes";
                        elseif ($categoria === "Aceites y Fluidos Hidráulicos") $iconClass = "fa-oil-can";
                        elseif ($categoria === "Selladores y Prorreactivos (PRC / RTV)") $iconClass = "fa-spray-can";
                        elseif ($categoria === "Lubricantes y Grasas") $iconClass = "fa-gears";
                        elseif ($categoria === "Químicos, Limpiadores y Adhesivos") $iconClass = "fa-prescription-bottle-droplet";
                        ?>
                        <i class="fas <?php echo $iconClass; ?>"></i>
                        <?php echo htmlspecialchars($categoria, ENT_QUOTES, 'UTF-8'); ?>
                    </h2>
                    
                    <div class="table-container" style="margin: 0; border: none; box-shadow: none;">
                        <table class="fallas-table" style="min-width: 100% !important;">
                            <thead>
                                <tr>
                                    <th style="text-align: left !important; width: 50%;">Descripción / Nombre</th>
                                    <th style="width: 25%; text-align: left !important;">Número de Parte (P/N)</th>
                                    <th style="width: 25%; text-align: center;">Acciones Administrativas</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td style="font-weight: 600; text-align: left;"><?php echo htmlspecialchars((string)$item['nombre'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="font-weight: 700; color: #1a419c; font-family: 'Outfit', sans-serif; text-align: left !important;"><?php echo htmlspecialchars((string)$item['numero_parte'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td style="text-align: center;">
                                            <div style="display: flex; gap: 8px; justify-content: center; align-items: center;">
                                                <button class="btn-copiar" onclick="copiarParte(this, '<?php echo htmlspecialchars((string)$item['numero_parte'], ENT_QUOTES, 'UTF-8'); ?>')" title="Copiar número de parte">
                                                    <i class="far fa-copy"></i> Copiar
                                                </button>
                                                <?php if ($isAdmin): ?>
                                                <button class="btn-action-edit" onclick='abrirEditarConsumible(<?php echo json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)' title="Editar consumible">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn-action-delete" onclick='eliminarConsumible(<?php echo $item['id']; ?>, "<?php echo htmlspecialchars((string)$item['nombre'], ENT_QUOTES, 'UTF-8'); ?>")' title="Dar de baja (Eliminar) consumible">
                                                    <i class="fas fa-trash-can"></i>
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endforeach; ?>
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

    <!-- MODAL PARA ALTA / EDICIÓN DE CONSUMIBLES -->
    <div class="modal-overlay" id="consumibleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-vial"></i> Dar de Alta Consumible</h3>
                <button class="modal-close-btn" onclick="cerrarModalConsumible()"><i class="fas fa-xmark"></i></button>
            </div>
            
            <form method="POST" action="consumibles.php">
                <input type="hidden" name="action" id="modal_action" value="add_consumible">
                <input type="hidden" name="id" id="modal_id" value="">
                
                <!-- Categoría -->
                <div class="editor-field">
                    <label>Sección / Categoría del Consumible<span style="color:#dc2626;">*</span></label>
                    <select name="categoria" id="modal_categoria" required style="width: 100%;">
                        <option value="Misceláneos">Misceláneos</option>
                        <option value="Aceites y Fluidos Hidráulicos">Aceites y Fluidos Hidráulicos</option>
                        <option value="Selladores y Prorreactivos (PRC / RTV)">Selladores y Prorreactivos (PRC / RTV)</option>
                        <option value="Lubricantes y Grasas">Lubricantes y Grasas</option>
                        <option value="Químicos, Limpiadores y Adhesivos">Químicos, Limpiadores y Adhesivos</option>
                    </select>
                </div>

                <!-- Nombre / Descripción -->
                <div class="editor-field">
                    <label>Nombre / Descripción del Material<span style="color:#dc2626;">*</span></label>
                    <input type="text" name="nombre" id="modal_nombre" placeholder="ej. Aceite para Robinair, Cinta doble cara..." required style="width: 100%;">
                </div>

                <!-- Número de Parte -->
                <div class="editor-field">
                    <label>Número de Parte (P/N)<span style="color:#dc2626;">*</span></label>
                    <input type="text" name="numero_parte" id="modal_np" placeholder="ej. PR1425B1/2PT, JET-254, WD-40..." required style="width: 100%;">
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 25px; border-top: 1px solid #e2e8f0; padding-top: 15px;">
                    <button type="button" class="btn-filter-reset" onclick="cerrarModalConsumible()" style="height: auto;">Cancelar</button>
                    <button type="submit" class="btn-primary-action" id="modalSubmitBtn"><i class="fas fa-floppy-disk"></i> Registrar Consumible</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts de interactividad -->
    <script>
        function copiarParte(button, texto) {
            navigator.clipboard.writeText(texto).then(() => {
                const originalHTML = button.innerHTML;
                button.innerHTML = '<i class="fas fa-check" style="color: #15803d;"></i> Copiado';
                button.style.backgroundColor = '#d1e7dd';
                button.style.color = '#0f5132';
                button.style.borderColor = '#badbcc';
                
                setTimeout(() => {
                    button.innerHTML = originalHTML;
                    button.style.backgroundColor = '';
                    button.style.color = '';
                    button.style.borderColor = '';
                }, 1500);
            }).catch(err => {
                console.error('Error al copiar texto: ', err);
            });
        }

        function filtrarConsumibles() {
            const input = document.getElementById('buscador-consumibles');
            const filter = input.value.toLowerCase().trim();
            const sections = document.querySelectorAll('.seccion-categoria');
            
            sections.forEach(section => {
                const rows = section.querySelectorAll('tbody tr');
                let visibleRows = 0;
                
                rows.forEach(row => {
                    const desc = row.cells[0].textContent.toLowerCase();
                    const np = row.cells[1].textContent.toLowerCase();
                    
                    if (desc.includes(filter) || np.includes(filter)) {
                        row.style.display = '';
                        visibleRows++;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                if (visibleRows > 0) {
                    section.style.display = '';
                } else {
                    section.style.display = 'none';
                }
            });
        }

        // --- FUNCIONES DEL MODAL DE ADMINISTRACIÓN ---

        function abrirAltaConsumible() {
            document.getElementById('modal_action').value = 'add_consumible';
            document.getElementById('modal_id').value = '';
            document.getElementById('modal_categoria').value = 'Misceláneos';
            document.getElementById('modal_nombre').value = '';
            document.getElementById('modal_np').value = '';
            
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Dar de Alta Consumible';
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Registrar Consumible';
            
            document.getElementById('consumibleModal').style.display = 'flex';
        }

        function abrirEditarConsumible(item) {
            document.getElementById('modal_action').value = 'edit_consumible';
            document.getElementById('modal_id').value = item.id;
            document.getElementById('modal_categoria').value = item.categoria;
            document.getElementById('modal_nombre').value = item.nombre;
            document.getElementById('modal_np').value = item.numero_parte;
            
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-pen-to-square"></i> Corregir Consumible';
            document.getElementById('modalSubmitBtn').innerHTML = '<i class="fas fa-floppy-disk"></i> Guardar Cambios';
            
            document.getElementById('consumibleModal').style.display = 'flex';
        }

        function cerrarModalConsumible() {
            document.getElementById('consumibleModal').style.display = 'none';
        }

        function eliminarConsumible(id, nombre) {
            if (confirm("⚠️ ¿Estás seguro de que deseas dar de BAJA (eliminar) el consumible '" + nombre + "' del catálogo?")) {
                window.location.href = 'consumibles.php?delete_id=' + id;
            }
        }
    </script>
</body>

</html>
