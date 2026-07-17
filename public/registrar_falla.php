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

// Bloqueo estricto para rol "Otro" y "Técnico"
if ($userRole === 'Otro' || $userRole === 'Técnico') {
    header('Location: dashboard.php');
    exit;
}

$userName = (string)SessionManager::get('user_name', 'Usuario');

// Cargar la flota activa para el dropdown dinámico dependiente
$flotaMap = [];
try {
    $pdo = DatabaseConnection::getConnection();
    $stmtFlota = $pdo->query("SELECT modelo, matricula FROM tbc_Flota WHERE es_activo = 1 ORDER BY modelo, matricula");
    while ($row = $stmtFlota->fetch(PDO::FETCH_ASSOC)) {
        $flotaMap[$row['modelo']][] = $row['matricula'];
    }
} catch (\Exception $e) {
    error_log("Error cargando flota en registrar_falla: " . $e->getMessage());
}

// Mensaje de éxito/error de guardado si existe
$msg = (string)SessionManager::get('falla_success_msg', '');
$err = (string)SessionManager::get('falla_error_msg', '');
SessionManager::remove('falla_success_msg');
SessionManager::remove('falla_error_msg');

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Registrar Falla - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Registro oficial de fallas técnicas de aeronaves.">
    <meta name="robots" content="noindex, nofollow">

    <!-- Font Awesome 6 CDN para Iconos -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">

    <!-- Estilo Original de AleSearchTool -->
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo time(); ?>">

    <style>
        /* Switch Toggle Estilo iOS Premium */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 28px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 34px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 20px;
            width: 20px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        input:checked + .slider {
            background-color: #1a419c;
        }
        input:checked + .slider:before {
            transform: translateX(22px);
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
            <i class="fas fa-house"></i>
            Dashboard
        </a>

        <?php if ($userRole !== 'Técnico'): ?>
        <a href="registrar_falla.php" class="active">
            <i class="fas fa-pen-to-square"></i>
            Registrar Falla
        </a>
        <?php endif; ?>

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
        
        <a href="index.php?action=logout" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
        </a>

        <div class="sidebar-footer">
            POWERED BY LEONARDO MIREL
        </div>

    </div>

    <!-- CONTENIDO -->
    <div class="main-content">

        <!-- Header superior con Logo y Botón IA alineado -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px;">
            <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Registrar Falla</h1>
            <div style="display: flex; align-items: center; gap: 15px;">
                <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                    <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
                </div>
            </div>
        </div>

        <!-- Mensajes de Operación -->
        <?php if (!empty($msg)): ?>
            <div class="alert-success" style="background-color: #d1e7dd; color: #0f5132; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; border: 1px solid #badbcc;">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($err)): ?>
            <div class="alert-danger" style="background-color: #f8d7da; color: #842029; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: bold; border: 1px solid #f5c2c7;">
                <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form action="guardar_falla.php" method="POST" class="form-falla">

            <!-- Grid de Dos Columnas para campos de datos cortos -->
            <div class="grid-form">

                <div class="campo">
                    <label>Modelo</label>
                    <select id="modelo" name="modelo" required>
                        <option value="">Seleccionar</option>
                        <?php foreach (array_keys($flotaMap) as $mod): ?>
                            <option value="<?php echo htmlspecialchars((string)$mod, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)$mod, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="campo">
                    <label>Matrícula</label>
                    <select id="matricula" name="matricula" required disabled>
                        <option value="">Seleccione modelo</option>
                    </select>
                </div>

                <div class="campo">
                    <label>ATA</label>
                    <select name="ata" required>
                        <option value="">Seleccionar</option>
                        <option>12 - Servicios</option>
                        <option>21 - Air Conditioning</option>
                        <option>22 - Auto Flight</option>
                        <option>23 - Communications</option>
                        <option>24 - Electrical Power</option>
                        <option>25 - Equipo abordo</option>
                        <option>26 - Fire Protection</option>
                        <option>27 - Flight Controls</option>
                        <option>28 - Fuel</option>
                        <option>29 - Hydraulic Power</option>
                        <option>30 - Ice and Rain Protection</option>
                        <option>31 - Indicating/Recording Systems</option>
                        <option>32 - Landing Gear</option>
                        <option>33 - Lights</option>
                        <option>34 - Navigation</option>
                        <option>35 - Oxygen</option>
                        <option>36 - Pneumatic</option>
                        <option>37 - Vacuum</option>
                        <option>38 - Water/Waste</option>
                        <option>49 - Airborne Auxiliary Power (APU)</option>
                        <option>50 - Cargo and Accessory Compartments</option>
                        <option>51 - Standard Practices - Structures</option>
                        <option>52 - Doors</option>
                        <option>53 - Fuselage</option>
                        <option>54 - Nacelles/Pylons</option>
                        <option>55 - Stabilizers</option>
                        <option>56 - Windows</option>
                        <option>57 - Wings</option>
                        <option>70 - Standard Practices - Engine</option>
                        <option>71 - Powerplant</option>
                        <option>72 - Engine</option>
                        <option>73 - Engine Fuel and Control</option>
                        <option>74 - Ignition</option>
                        <option>75 - Air</option>
                        <option>76 - Engine Controls</option>
                        <option>77 - Engine Indicating</option>
                        <option>78 - Exhaust</option>
                        <option>79 - Oil</option>
                        <option>80 - Starting</option>
                    </select>
                </div>

                <div class="campo">
                    <label>Condición</label>
                    <select name="condicion">
                        <option value="1">1</option>
                        <option value="2">2</option>
                        <option value="3">3</option>
                    </select>
                </div>

                <div class="campo campo-corto">
                    <label>Folio Bitácora</label>
                    <input type="text" id="folio" name="folio" placeholder="" oninput="this.value = this.value.replace(/[^0-9-]/g, '')">
                </div>

                <div class="campo">
                    <label>Fecha</label>
                    <input type="date" id="fecha" name="fecha" value="<?php echo date('Y-m-d'); ?>" required>
                </div>

                <div class="campo">
                    <label>Base</label>
                    <select name="base" required>
                        <option value="TLC">TLC</option>
                        <option value="ADN">ADN</option>
                    </select>
                </div>

                <div class="campo">
                    <label>Horas de Vuelo</label>
                    <input type="number" step="any" min="0" name="horas" placeholder="Ej. 1200.5" required>
                </div>

                <div class="campo">
                    <label>Ciclos</label>
                    <input type="number" step="1" min="0" name="ciclos" placeholder="Ej. 650" required>
                </div>

                <div class="campo">
                    <label>Tiempo de Atención (Horas)</label>
                    <input type="number" step="any" min="0" name="tiempo_atencion" placeholder="Ej. 1.5" required>
                </div>

            </div>

            <!-- Campos de Ancho Completo (Textareas) -->
            <div class="campo" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <label style="margin: 0;">Descripción del Reporte <span style="color: #ef4444;">*</span></label>
                    <button type="button" class="btn-voice" onclick="toggleSpeechRecognition('descripcion', this)" style="background: #f3f4f6; border: 1px solid #d1d5db; color: #1a419c; padding: 4px 10px; border-radius: 6px; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#e5e7eb';" onmouseout="this.style.backgroundColor='#f3f4f6';">
                        <i class="fas fa-microphone"></i> <span>Dictar</span>
                    </button>
                </div>
                <textarea name="descripcion" required></textarea>
            </div>

            <div class="campo" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <label style="margin: 0;">Acción Correctiva <span style="color: #ef4444;">*</span></label>
                    <button type="button" class="btn-voice" onclick="toggleSpeechRecognition('accion_correctiva', this)" style="background: #f3f4f6; border: 1px solid #d1d5db; color: #1a419c; padding: 4px 10px; border-radius: 6px; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#e5e7eb';" onmouseout="this.style.backgroundColor='#f3f4f6';">
                        <i class="fas fa-microphone"></i> <span>Dictar</span>
                    </button>
                </div>
                <textarea name="accion_correctiva" required></textarea>
            </div>

            <div class="campo" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <label style="margin: 0;">Referencia</label>
                    <button type="button" id="btn_add_ref" style="background: none; border: none; color: #1a419c; font-family: 'Outfit', sans-serif; font-weight: 700; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                        <i class="fas fa-plus-circle"></i> Agregar segunda referencia
                    </button>
                </div>
                
                <!-- Contenedor Referencia 1 -->
                <div id="ref_container_1" style="display: grid; grid-template-columns: 1fr 2fr; gap: 15px; margin-bottom: 10px;">
                    <select id="ref_manual_1" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                        <option value="">Manual (Seleccionar)</option>
                        <option value="A.M.M">A.M.M</option>
                        <option value="C.M.M">C.M.M</option>
                        <option value="L.M.M">L.M.M</option>
                        <option value="I.P.C">I.P.C</option>
                        <option value="W.M.M">W.M.M</option>
                        <option value="S.M.M">S.M.M</option>
                    </select>
                    <input type="text" id="ref_seccion_1" placeholder="Sección / Número (Ej: 21-50-00)" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                </div>

                <!-- Contenedor Referencia 2 (Oculto por defecto) -->
                <div id="ref_container_2" style="display: none; grid-template-columns: 1fr 2fr auto; gap: 15px; margin-bottom: 10px; align-items: center;">
                    <select id="ref_manual_2" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                        <option value="">Manual (Seleccionar)</option>
                        <option value="A.M.M">A.M.M</option>
                        <option value="C.M.M">C.M.M</option>
                        <option value="L.M.M">L.M.M</option>
                        <option value="I.P.C">I.P.C</option>
                        <option value="W.M.M">W.M.M</option>
                        <option value="S.M.M">S.M.M</option>
                    </select>
                    <input type="text" id="ref_seccion_2" placeholder="Sección / Número" style="padding: 10px; border-radius: 8px; border: 1px solid #cbd5e1; font-family: 'Plus Jakarta Sans', sans-serif; font-size: 13px;">
                    <button type="button" id="btn_remove_ref" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px; padding: 5px; display: inline-flex; align-items: center; justify-content: center;" title="Quitar segunda referencia">
                        <i class="fas fa-trash-can"></i>
                    </button>
                </div>

                <!-- Input real oculto que enviará el texto combinado al servidor -->
                <input type="hidden" name="referencia" id="referencia_real">
            </div>

            <div class="campo" style="margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">
                    <label style="margin: 0;">Tips</label>
                    <button type="button" class="btn-voice" onclick="toggleSpeechRecognition('tips', this)" style="background: #f3f4f6; border: 1px solid #d1d5db; color: #1a419c; padding: 4px 10px; border-radius: 6px; font-family: 'Plus Jakarta Sans', sans-serif; font-weight: 700; cursor: pointer; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s;" onmouseover="this.style.backgroundColor='#e5e7eb';" onmouseout="this.style.backgroundColor='#f3f4f6';">
                        <i class="fas fa-microphone"></i> <span>Dictar</span>
                    </button>
                </div>
                <textarea name="tips"></textarea>
            </div>

            <!-- Pregunta Cambio de Componente (Switch/Toggle) -->
            <div class="campo" style="margin-top: 25px; margin-bottom: 15px; display: flex; align-items: center; gap: 15px;">
                <span style="font-weight: 700; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 15px;">¿Se cambió algún componente?</span>
                <label class="switch">
                    <input type="checkbox" id="cambio_componente" name="componente_cambiado" value="Sí">
                    <span class="slider"></span>
                </label>
                <span id="switch_label" style="font-weight: 700; color: #5f6368; font-family: 'Plus Jakarta Sans', sans-serif;">No</span>
            </div>

            <!-- Panel de Componentes (Removido e Instalado) -->
            <div id="seccion_componentes" style="display: none; margin-top: 15px; margin-bottom: 25px; border: 1px solid #e2e8f0; padding: 20px; border-radius: 12px; background-color: #ffffff; box-shadow: var(--shadow-sm);">
                <div class="grid-form" style="margin: 0; gap: 20px;">
                    <!-- Componente Removido -->
                    <div style="border-right: 1px solid #e2e8f0; padding-right: 20px;">
                        <h3 style="margin-top: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 700; margin-bottom: 15px;">
                            <i class="fas fa-arrow-right-from-bracket" style="color: #ef4444; margin-right: 6px;"></i> Componente Removido
                        </h3>
                        <div class="campo" style="margin-bottom: 15px;">
                            <label>Número de Parte (N/P)</label>
                            <input type="text" id="comp_removido_np" name="comp_removido_np" placeholder="Ej. 822-0238-002">
                        </div>
                        <div class="campo">
                            <label>Número de Serie (N/S)</label>
                            <input type="text" id="comp_removido_ns" name="comp_removido_ns" placeholder="Ej. 12938A">
                        </div>
                    </div>
                    
                    <!-- Componente Instalado -->
                    <div style="padding-left: 10px;">
                        <h3 style="margin-top: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 700; margin-bottom: 15px;">
                            <i class="fas fa-arrow-right-to-bracket" style="color: #22c55e; margin-right: 6px;"></i> Componente Instalado
                        </h3>
                        <div class="campo" style="margin-bottom: 15px;">
                            <label>Número de Parte (N/P)</label>
                            <input type="text" id="comp_instalado_np" name="comp_instalado_np" placeholder="Ej. 822-0238-002">
                        </div>
                        <div class="campo">
                            <label>Número de Serie (N/S)</label>
                            <input type="text" id="comp_instalado_ns" name="comp_instalado_ns" placeholder="Ej. 99283B">
                        </div>
                    </div>
                </div>

                <div style="text-align: right; margin-top: 15px; margin-bottom: 5px;">
                    <button type="button" id="btn_add_comp2" style="background: none; border: none; color: #1a419c; font-family: 'Outfit', sans-serif; font-weight: 700; cursor: pointer; font-size: 13px; display: inline-flex; align-items: center; gap: 5px;" onmouseover="this.style.textDecoration='underline';" onmouseout="this.style.textDecoration='none';">
                        <i class="fas fa-plus-circle"></i> Agregar segundo componente
                    </button>
                </div>

                <!-- Segundo Componente (Oculto por defecto) -->
                <div id="seccion_componente_2" style="display: none; margin-top: 20px; padding-top: 20px; border-top: 1px dashed #cbd5e1;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <h4 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 15px; font-weight: 700;">Segundo Componente</h4>
                        <button type="button" id="btn_remove_comp2" style="background: none; border: none; color: #ef4444; cursor: pointer; font-size: 16px; padding: 5px; display: inline-flex; align-items: center; justify-content: center;" title="Quitar segundo componente">
                            <i class="fas fa-trash-can"></i>
                        </button>
                    </div>
                    <div class="grid-form" style="margin: 0; gap: 20px;">
                        <!-- Componente Removido 2 -->
                        <div style="border-right: 1px solid #e2e8f0; padding-right: 20px;">
                            <div class="campo" style="margin-bottom: 15px;">
                                <label>Número de Parte Removido 2</label>
                                <input type="text" id="comp2_removido_np" name="comp2_removido_np" placeholder="Ej. 822-0238-002">
                            </div>
                            <div class="campo">
                                <label>Número de Serie Removido 2</label>
                                <input type="text" id="comp2_removido_ns" name="comp2_removido_ns" placeholder="Ej. 12938A">
                            </div>
                        </div>
                        
                        <!-- Componente Instalado 2 -->
                        <div style="padding-left: 10px;">
                            <div class="campo" style="margin-bottom: 15px;">
                                <label>Número de Parte Instalado 2</label>
                                <input type="text" id="comp2_instalado_np" name="comp2_instalado_np" placeholder="Ej. 822-0238-002">
                            </div>
                            <div class="campo">
                                <label>Número de Serie Instalado 2</label>
                                <input type="text" id="comp2_instalado_ns" name="comp2_instalado_ns" placeholder="Ej. 99283B">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 25px;">
                <button type="submit" class="btn-guardar">
                    <i class="fas fa-lock"></i>
                    Guardar Reporte
                </button>
            </div>

        </form>

    </div>

    <!-- Script de Dropdown Dependiente Dinámico y Validaciones -->
    <script>
        const flotaMap = <?php echo json_encode($flotaMap); ?>;
        const modeloSelect = document.getElementById('modelo');
        const matriculaSelect = document.getElementById('matricula');
        const folioInput = document.getElementById('folio');
        const fechaInput = document.getElementById('fecha');

        // Función para validar duplicados
        function checkDuplicateFalla() {
            const mat = matriculaSelect.value;
            const fol = folioInput ? folioInput.value.trim() : '';
            const fec = fechaInput ? fechaInput.value : '';

            if (mat && fol && fec) {
                fetch(`check_duplicate_falla.php?matricula=${encodeURIComponent(mat)}&folio=${encodeURIComponent(fol)}&fecha=${encodeURIComponent(fec)}`)
                    .then(res => res.json())
                    .then(data => {
                        if (data.duplicate) {
                            alert("⚠️ ADVERTENCIA: Ya existe un reporte en la base de datos con esta misma Matrícula, Folio y Fecha.\n\nSi se trata de una falla diferente registrada bajo el mismo folio, puede ignorar este aviso y guardar el reporte sin problema. De lo contrario, verifique la información para evitar registros duplicados.");
                        }
                    })
                    .catch(err => console.error("Error validando duplicado:", err));
            }
        }

        modeloSelect.addEventListener('change', function () {
            const selectedModel = this.value;
            matriculaSelect.innerHTML = '';

            if (selectedModel && flotaMap[selectedModel]) {
                matriculaSelect.disabled = false;
                
                const defOpt = document.createElement('option');
                defOpt.value = '';
                defOpt.textContent = 'Seleccionar Matrícula';
                matriculaSelect.appendChild(defOpt);

                flotaMap[selectedModel].forEach(mat => {
                    const opt = document.createElement('option');
                    opt.value = mat;
                    opt.textContent = mat;
                    matriculaSelect.appendChild(opt);
                });
            } else {
                matriculaSelect.disabled = true;
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = 'Primero seleccione modelo';
                matriculaSelect.appendChild(opt);
            }
            checkDuplicateFalla(); // Validar si ya había datos
        });

        matriculaSelect.addEventListener('change', checkDuplicateFalla);
        if (folioInput) folioInput.addEventListener('blur', checkDuplicateFalla);
        if (fechaInput) fechaInput.addEventListener('change', checkDuplicateFalla);


        // Lógica interactiva para Cambio de Componentes (Toggle Switch)
        document.addEventListener('DOMContentLoaded', function () {
            const switchComponente = document.getElementById('cambio_componente');
            const seccionComponentes = document.getElementById('seccion_componentes');
            const labelSwitch = document.getElementById('switch_label');

            const inputsComp = [
                document.getElementById('comp_removido_np'),
                document.getElementById('comp_removido_ns'),
                document.getElementById('comp_instalado_np'),
                document.getElementById('comp_instalado_ns')
            ];

            const btnAddComp2 = document.getElementById('btn_add_comp2');
            const btnRemoveComp2 = document.getElementById('btn_remove_comp2');
            const seccionComponente2 = document.getElementById('seccion_componente_2');
            const inputsComp2 = [
                document.getElementById('comp2_removido_np'),
                document.getElementById('comp2_removido_ns'),
                document.getElementById('comp2_instalado_np'),
                document.getElementById('comp2_instalado_ns')
            ];

            if (btnAddComp2 && seccionComponente2) {
                btnAddComp2.addEventListener('click', function () {
                    seccionComponente2.style.display = 'block';
                    btnAddComp2.style.display = 'none';
                });
            }

            if (btnRemoveComp2 && seccionComponente2) {
                btnRemoveComp2.addEventListener('click', function () {
                    seccionComponente2.style.display = 'none';
                    inputsComp2.forEach(input => input.value = '');
                    btnAddComp2.style.display = 'inline-flex';
                });
            }

            function actualizarComponentes() {
                if (switchComponente.checked) {
                    seccionComponentes.style.display = 'block';
                    labelSwitch.textContent = 'Sí';
                    labelSwitch.style.color = '#1a419c';
                    inputsComp.forEach(input => {
                        if (input.value === 'N/A') {
                            input.value = '';
                        }
                        input.required = true;
                    });
                } else {
                    seccionComponentes.style.display = 'none';
                    labelSwitch.textContent = 'No';
                    labelSwitch.style.color = '#5f6368';
                    inputsComp.forEach(input => {
                        input.value = 'N/A';
                        input.required = false;
                    });
                    
                    // Resetear componente 2
                    if (seccionComponente2) {
                        seccionComponente2.style.display = 'none';
                        inputsComp2.forEach(input => input.value = '');
                        if (btnAddComp2) btnAddComp2.style.display = 'inline-flex';
                    }
                }
            }

            switchComponente.addEventListener('change', actualizarComponentes);
            
            // Inicialización al cargar la página
            actualizarComponentes();
        });

        // Lógica interactiva para la Referencia dividida (Grupo 5)
        document.addEventListener('DOMContentLoaded', function () {
            const btnAddRef = document.getElementById('btn_add_ref');
            const btnRemoveRef = document.getElementById('btn_remove_ref');
            const refContainer2 = document.getElementById('ref_container_2');
            const refManual2 = document.getElementById('ref_manual_2');
            const refSeccion2 = document.getElementById('ref_seccion_2');

            if (btnAddRef && refContainer2) {
                btnAddRef.addEventListener('click', function () {
                    refContainer2.style.display = 'grid';
                    btnAddRef.style.display = 'none';
                });
            }

            if (btnRemoveRef && refContainer2) {
                btnRemoveRef.addEventListener('click', function () {
                    refContainer2.style.display = 'none';
                    refManual2.value = '';
                    refSeccion2.value = '';
                    btnAddRef.style.display = 'inline-flex';
                });
            }
        });

        // Lógica para Grabación por Voz (Grupo 6 - Web Speech API)
        let recognition = null;
        let activeRecognitionBtn = null;
        let activeTextarea = null;

        function toggleSpeechRecognition(textareaName, btn) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                alert("La grabación por voz no está soportada en este navegador. Intente con Google Chrome o Microsoft Edge.");
                return;
            }

            const textarea = document.getElementsByName(textareaName)[0];
            if (!textarea) return;

            // Si ya hay una grabación activa y es este mismo botón, detenerla
            if (recognition && activeRecognitionBtn === btn) {
                recognition.stop();
                return;
            }

            // Si hay otra activa, detenerla primero
            if (recognition) {
                recognition.stop();
            }

            // Iniciar nueva
            recognition = new SpeechRecognition();
            recognition.lang = 'es-MX'; // Español de México / Latinoamérica
            recognition.continuous = false; // Detener al pausar la voz
            recognition.interimResults = false;

            activeRecognitionBtn = btn;
            activeTextarea = textarea;

            recognition.onstart = function() {
                btn.style.backgroundColor = '#fee2e2';
                btn.style.borderColor = '#fca5a5';
                btn.style.color = '#ef4444';
                btn.querySelector('span').textContent = 'Grabando...';
                btn.querySelector('i').className = 'fas fa-microphone fa-beat'; // Animación de latido
            };

            recognition.onend = function() {
                btn.style.backgroundColor = '#f3f4f6';
                btn.style.borderColor = '#d1d5db';
                btn.style.color = '#1a419c';
                btn.querySelector('span').textContent = 'Dictar';
                btn.querySelector('i').className = 'fas fa-microphone';
                recognition = null;
                activeRecognitionBtn = null;
                activeTextarea = null;
            };

            recognition.onerror = function(event) {
                console.error("Error en reconocimiento de voz:", event.error);
                if (event.error === 'not-allowed') {
                    alert("Permiso denegado para usar el micrófono. Por favor habilítelo en la configuración de su navegador.");
                }
            };

            recognition.onresult = function(event) {
                const transcript = event.results[0][0].transcript;
                // Concatenar el texto al textarea en mayúsculas
                const currentText = textarea.value.trim();
                textarea.value = (currentText ? currentText + ' ' : '') + transcript.toUpperCase();
                textarea.dispatchEvent(new Event('input'));
            };

            recognition.start();
        }

        document.querySelector('.form-falla').addEventListener('submit', function (e) {
            // Combinar referencias del UI dividido en el input real oculto (Grupo 5)
            const man1 = document.getElementById('ref_manual_1').value;
            const sec1 = document.getElementById('ref_seccion_1').value.trim();
            const man2 = document.getElementById('ref_manual_2').value;
            const sec2 = document.getElementById('ref_seccion_2').value.trim();

            let refFinal = '';
            if (man1 && sec1) {
                refFinal = man1 + ' ' + sec1;
            } else if (sec1) {
                refFinal = sec1;
            }

            const refContainer2 = document.getElementById('ref_container_2');
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
            document.getElementById('referencia_real').value = refFinal;

        });

    </script>
</body>

</html>
