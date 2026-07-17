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
$err = '';
$favorites = [];

try {
    $pdo = DatabaseConnection::getConnection();

    // Consultar solo las fallas marcadas como favorito por este usuario
    $sql = "
        SELECT f.*, f.mel AS capitulo_mel
        FROM tbo_Falla f
        JOIN tbo_CarpetaUsuario c ON f.id_falla = c.id_falla
        WHERE c.usuario_nombre = :user
        ORDER BY f.id_falla DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':user' => $userName]);
    $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (\Exception $e) {
    error_log("Error en mis_favoritos: " . $e->getMessage());
    $err = "Error en base de datos: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Mi Carpeta de Favoritos - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Reportes guardados por el usuario.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <style>
        .favorite-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .favorite-row:hover {
            background-color: #e2e8f0 !important;
        }
        .detail-row {
            background-color: #fafbfc;
            display: none;
        }
        .detail-card-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            padding: 20px;
            background-color: #ffffff;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin: 10px 20px;
        }
        .detail-card {
            background-color: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 15px;
        }
        .detail-card h4 {
            font-family: 'Outfit', sans-serif;
            color: #1a419c;
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 15px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .detail-card p {
            margin: 0;
            font-size: 13.5px;
            line-height: 1.5;
            color: #334155;
            white-space: pre-line;
        }
        .btn-unstar {
            background: none;
            border: none;
            color: #f59e0b;
            font-size: 18px;
            cursor: pointer;
            padding: 5px;
            transition: transform 0.2s, color 0.2s;
        }
        .btn-unstar:hover {
            transform: scale(1.2);
            color: #dc2626;
        }
        .meta-tag {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            background-color: #eef2ff;
            color: #1a419c;
            border: 1px solid #c7d2fe;
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
                <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Mi Carpeta (Favoritos)</h1>
                <a href="administracion.php" style="color: #1a419c; font-weight: 700; text-decoration: none; font-size: 14px; display: inline-flex; align-items: center; gap: 5px;">
                    <i class="fas fa-arrow-left"></i> Volver a Administración
                </a>
            </div>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <!-- Mensajes de Error -->
        <?php if (!empty($err)): ?>
            <div class="alert-danger">
                <i class="fas fa-circle-exclamation alert-icon"></i>
                <span><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        <?php endif; ?>

        <!-- Tarjetas Informativas -->
        <div class="status-card" style="margin-bottom: 25px; background: #ffffff; padding: 20px; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
            <div>
                <h3 style="margin: 0 0 5px 0; color: #1a419c; font-family: 'Outfit', sans-serif;">Reportes en tu Carpeta</h3>
                <p style="margin: 0; color: #64748b; font-size: 14px;">Haz clic en cualquier reporte de la tabla para ver todos sus detalles.</p>
            </div>
            <div style="background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 50%; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;">
                <i class="fas fa-star" style="color: #f59e0b; font-size: 22px;"></i>
            </div>
        </div>

        <!-- Tabla de Favoritos -->
        <div class="table-container">
            <h2><i class="fas fa-folder-star"></i> Mis Reportes Guardados (<?php echo count($favorites); ?>)</h2>
            
            <?php if (empty($favorites)): ?>
                <div style="text-align: center; padding: 40px; background-color: #ffffff; border-radius: 8px;">
                    <i class="far fa-star" style="font-size: 40px; color: #cbd5e1; margin-bottom: 15px;"></i>
                    <p style="margin: 0; color: #64748b; font-size: 15px; font-weight: 600;">No tienes reportes guardados en favoritos.</p>
                    <a href="consultar_fallas.php" style="display: inline-block; margin-top: 15px; background-color: #1a419c; color: white; padding: 10px 18px; border-radius: 6px; font-weight: bold; text-decoration: none; font-size: 13.5px;"><i class="fas fa-magnifying-glass"></i> Buscar reportes para guardar</a>
                </div>
            <?php else: ?>
                <table class="fallas-table">
                    <thead>
                        <tr>
                            <th style="width: 8%; text-align: center;">ID</th>
                            <th style="width: 12%;">Fecha</th>
                            <th style="width: 18%;">Aeronave</th>
                            <th style="width: 12%; text-align: center;">ATA</th>
                            <th style="width: 15%;">Folio / Referencia</th>
                            <th style="width: 25%;">Descripción Corta</th>
                            <th style="width: 10%; text-align: center;">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($favorites as $item): ?>
                            <!-- Fila Principal clickable -->
                            <tr class="favorite-row" id="row-<?php echo $item['id_falla']; ?>" onclick="toggleDetails(<?php echo $item['id_falla']; ?>)">
                                <td style="text-align: center; font-weight: bold; color: #64748b;">#<?php echo $item['id_falla']; ?></td>
                                <td style="font-weight: 600;"><?php echo date('d/m/Y', strtotime((string)$item['fecha'])); ?></td>
                                <td style="font-weight: 700; color: #1a419c;">
                                    <?php echo htmlspecialchars((string)$item['modelo'], ENT_QUOTES, 'UTF-8'); ?><br>
                                    <span style="font-size: 11px; color: #64748b; font-weight: normal;"><?php echo htmlspecialchars((string)$item['matricula'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td style="text-align: center;"><span class="meta-tag">ATA <?php echo htmlspecialchars((string)$item['ata'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td style="font-weight: 600;">
                                    <?php echo !empty($item['folio']) ? htmlspecialchars((string)$item['folio'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?><br>
                                    <span style="font-size: 11px; color: #64748b; font-weight: normal;"><?php echo !empty($item['referencia']) ? htmlspecialchars((string)$item['referencia'], ENT_QUOTES, 'UTF-8') : 'Sin Ref.'; ?></span>
                                </td>
                                <td style="text-align: left; max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                    <?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td style="text-align: center;" onclick="event.stopPropagation();">
                                    <button class="btn-unstar" onclick="unfavorite(<?php echo $item['id_falla']; ?>)" title="Quitar de favoritos">
                                        <i class="fas fa-star"></i>
                                    </button>
                                </td>
                            </tr>
                            
                            <!-- Fila de Detalles Oculta -->
                            <tr class="detail-row" id="details-<?php echo $item['id_falla']; ?>" onclick="event.stopPropagation();">
                                <td colspan="7" style="padding: 0;">
                                    <div class="detail-card-grid">
                                        
                                        <!-- Tarjeta Descripción -->
                                        <div class="detail-card">
                                            <h4><i class="fas fa-triangle-exclamation" style="color: #dc2626;"></i> Descripción del Reporte</h4>
                                            <p><?php echo htmlspecialchars((string)$item['descripcion'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>
                                        
                                        <!-- Tarjeta Acción Correctiva -->
                                        <div class="detail-card">
                                            <h4><i class="fas fa-circle-check" style="color: #16a34a;"></i> Acción Correctiva</h4>
                                            <p><?php echo htmlspecialchars((string)$item['accion_correctiva'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        </div>

                                        <!-- Tarjeta MEL y Base -->
                                        <div class="detail-card">
                                            <h4><i class="fas fa-clipboard-list" style="color: #1a419c;"></i> Información MEL & Operaciones</h4>
                                            <div style="display: flex; flex-direction: column; gap: 8px; font-size: 13.5px;">
                                                <div><strong>Capítulo MEL:</strong> <?php echo !empty($item['capitulo_mel']) ? htmlspecialchars((string)$item['capitulo_mel'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></div>
                                                <div><strong>Categoría MEL:</strong> <?php echo !empty($item['categoria_mel']) ? htmlspecialchars((string)$item['categoria_mel'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></div>
                                                <div><strong>Restricciones MEL:</strong> <?php echo !empty($item['mel_restricciones']) ? htmlspecialchars((string)$item['mel_restricciones'], ENT_QUOTES, 'UTF-8') : 'Ninguna'; ?></div>
                                                <div><strong>Base de Mantenimiento:</strong> <?php echo !empty($item['base']) ? htmlspecialchars((string)$item['base'], ENT_QUOTES, 'UTF-8') : 'N/A'; ?></div>
                                            </div>
                                        </div>

                                        <!-- Tarjeta Consejos y Tips -->
                                        <div class="detail-card">
                                            <h4><i class="fas fa-lightbulb" style="color: #eab308;"></i> Consejos Técnicos & Tips</h4>
                                            <p><?php echo !empty($item['tips']) ? htmlspecialchars((string)$item['tips'], ENT_QUOTES, 'UTF-8') : 'No hay consejos registrados para este reporte.'; ?></p>
                                            <div style="margin-top: 15px; font-size: 11.5px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 5px;">
                                                <strong>Registrado por:</strong> <?php echo htmlspecialchars((string)$item['registrado_por'], ENT_QUOTES, 'UTF-8'); ?>
                                            </div>
                                        </div>

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

    <!-- Scripts de interactividad -->
    <script>
        function toggleDetails(idFalla) {
            const detailRow = document.getElementById('details-' + idFalla);
            const mainRow = document.getElementById('row-' + idFalla);
            
            if (detailRow.style.display === 'table-row') {
                detailRow.style.display = 'none';
                mainRow.style.backgroundColor = '';
            } else {
                // Cerrar todos los demás detalles abiertos antes
                document.querySelectorAll('.detail-row').forEach(row => {
                    row.style.display = 'none';
                });
                document.querySelectorAll('.favorite-row').forEach(row => {
                    row.style.backgroundColor = '';
                });
                
                detailRow.style.display = 'table-row';
                mainRow.style.backgroundColor = '#f1f5f9';
            }
        }

        function unfavorite(idFalla) {
            if (!confirm("¿Deseas quitar este reporte de tus favoritos?")) {
                return;
            }
            
            fetch('toggle_bookmark.php?id_falla=' + idFalla)
            .then(r => r.json())
            .then(data => {
                if (data.success && data.status === 'unbookmarked') {
                    // Ocultar la fila principal y la fila de detalles con animación suave
                    const mainRow = document.getElementById('row-' + idFalla);
                    const detailRow = document.getElementById('details-' + idFalla);
                    
                    mainRow.style.transition = 'all 0.5s ease-out';
                    mainRow.style.opacity = '0';
                    mainRow.style.transform = 'translateX(-20px)';
                    
                    setTimeout(() => {
                        mainRow.remove();
                        detailRow.remove();
                        
                        // Si ya no quedan favoritos, recargar para mostrar el mensaje de vacío
                        const remainingRows = document.querySelectorAll('.favorite-row');
                        if (remainingRows.length === 0) {
                            window.location.reload();
                        }
                    }, 500);
                } else {
                    alert("Error: " + (data.message || "No se pudo actualizar."));
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
