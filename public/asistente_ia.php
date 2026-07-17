<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../src/autoload.php';
use App\Shared\Env\EnvLoader;
use App\Shared\Session\SessionManager;

EnvLoader::load(__DIR__ . '/../.env');
SessionManager::start();

$userRole = (string)SessionManager::get('user_role', '');

// Autodetectar rol si falta en sesión activa
if (empty($userRole) && SessionManager::has('user_id')) {
    try {
        require_once __DIR__ . '/../src/Infrastructure/Database/DatabaseConnection.php';
        $pdo = App\Infrastructure\Database\DatabaseConnection::getConnection();
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
$userEmail = (string)SessionManager::get('user_email', 'Desconocido');
$sessionStartTime = date('c');

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Asistente IA - AleSearchTool</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Asistente de inteligencia artificial y troubleshooting de aeronaves.">
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

        <a href="asistente_ia.php" class="active">
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
            <h1 style="margin: 0; color: #1a419c; font-family: 'Outfit', sans-serif; font-size: 32px; font-weight: 800;">Asistente IA</h1>
            <div style="background-color: #ffffff; padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-color); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-sm);">
                <img src="assets/images/logo_empresa.jpg" class="logo-empresa" style="width: 140px; height: auto; mix-blend-mode: multiply;">
            </div>
        </div>

        <div class="chat-container">
            <div class="chat-box" id="chat-box">
                <div class="chat-message bot">
                    Hola, <?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>. Soy el asistente técnico de AleSearchTool con Inteligencia Artificial. ¿En qué te puedo ayudar con el mantenimiento o troubleshooting de la flota hoy?
                </div>
            </div>
            
            <div class="chat-input-area">
                <input type="text" id="chat-input" placeholder="Escribe tu consulta sobre manuales, MEL, etc...">
                <button type="button" onclick="sendMessage()"><i class="fas fa-paper-plane"></i> Enviar</button>
            </div>
        </div>

        <div class="alert-danger" style="margin-top: 30px; justify-content: center; text-align: center; border-radius: var(--radius-md);">
            <i class="fas fa-triangle-exclamation"></i>
            <span><strong>ATENCIÓN:</strong> Esto es SOLO PARA REFERENCIA. No es material aprobado y no debe usarse para certificaciones o despachos oficiales.</span>
        </div>

        <!-- Botón para finalizar sesión y calificar -->
        <div style="text-align: center; margin-top: 20px;" id="rating-trigger-container">
            <button class="btn" style="background-color: var(--secondary-color); color: white;" onclick="showRatingUI()">
                <i class="fas fa-check-circle"></i> Finalizar Soporte y Calificar
            </button>
        </div>

        <!-- UI de Calificación (Oculta por defecto) -->
        <div id="rating-ui" style="display: none; text-align: center; margin-top: 20px; background: white; padding: 20px; border-radius: 12px; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
            <h3 style="margin-top: 0; color: var(--primary-color);">¿Cómo calificarías la asistencia de la IA en esta sesión?</h3>
            <div id="star-rating" style="font-size: 32px; color: #ccc; cursor: pointer;">
                <i class="fas fa-star" data-rating="1" onclick="submitRating(1)" onmouseover="hoverStars(1)" onmouseout="resetStars()"></i>
                <i class="fas fa-star" data-rating="2" onclick="submitRating(2)" onmouseover="hoverStars(2)" onmouseout="resetStars()"></i>
                <i class="fas fa-star" data-rating="3" onclick="submitRating(3)" onmouseover="hoverStars(3)" onmouseout="resetStars()"></i>
                <i class="fas fa-star" data-rating="4" onclick="submitRating(4)" onmouseover="hoverStars(4)" onmouseout="resetStars()"></i>
                <i class="fas fa-star" data-rating="5" onclick="submitRating(5)" onmouseover="hoverStars(5)" onmouseout="resetStars()"></i>
            </div>
            <p id="rating-message" style="margin-top: 15px; font-weight: bold; color: var(--primary-color); display: none;"></p>
        </div>

        <div class="footer-mantenimiento" style="margin-top: 40px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; text-align: center;">
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

    <!-- Lógica de Comunicación con el Servidor API de Gemini -->
    <script>
        const conversationHistory = [];

        function addChatMessage(text, role) {
            const chatBox = document.getElementById("chat-box");
            const msg = document.createElement("div");
            msg.className = `chat-message ${role}`;
            msg.textContent = text;
            chatBox.appendChild(msg);
            chatBox.scrollTop = chatBox.scrollHeight;
        }

        function sendMessage() {
            const input = document.getElementById("chat-input");
            const text = input.value.trim();
            if (!text) return;

            addChatMessage(text, "user");
            conversationHistory.push({ role: "user", content: text });
            input.value = "";

            const botMsg = document.createElement("div");
            botMsg.className = "chat-message bot";
            botMsg.innerHTML = "<i>Analizando base de conocimiento y redactando respuesta...</i>";
            document.getElementById("chat-box").appendChild(botMsg);
            botMsg.scrollIntoView({ behavior: "smooth" });

            fetch('asistente_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, history: conversationHistory })
            })
            .then(response => response.json())
            .then(data => {
                botMsg.textContent = data.reply || data.error || "Sin respuesta.";
                conversationHistory.push({ role: "assistant", content: botMsg.textContent });
                botMsg.scrollIntoView({ behavior: "smooth" });
            })
            .catch(() => {
                botMsg.textContent = "Error de red al conectar con el asistente de IA.";
                botMsg.scrollIntoView({ behavior: "smooth" });
            });
        }

        document.getElementById('chat-input').addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });

        // Lógica del Sistema de Calificación y Webhook
        const userEmail = "<?php echo addslashes($userEmail); ?>";
        const userName = "<?php echo addslashes($userName); ?>";
        const sessionStartTime = "<?php echo addslashes($sessionStartTime); ?>";
        
        let currentRating = 0;

        function showRatingUI() {
            document.getElementById('rating-trigger-container').style.display = 'none';
            document.getElementById('rating-ui').style.display = 'block';
        }

        function hoverStars(rating) {
            const stars = document.querySelectorAll('#star-rating i');
            stars.forEach((star, index) => {
                if (index < rating) {
                    star.style.color = '#f39c12'; // Amarillo/Naranja
                } else {
                    star.style.color = '#ccc';
                }
            });
        }

        function resetStars() {
            const stars = document.querySelectorAll('#star-rating i');
            stars.forEach((star, index) => {
                if (index < currentRating) {
                    star.style.color = '#f39c12';
                } else {
                    star.style.color = '#ccc';
                }
            });
        }

        function submitRating(rating) {
            currentRating = rating;
            resetStars(); // Fijar las estrellas

            // Generar UUID
            const registroId = crypto.randomUUID ? crypto.randomUUID() : 'id-' + new Date().getTime() + '-' + Math.random().toString(36).substring(2, 9);
            const sessionEndTime = new Date().toISOString();

            const payload = {
                registro_id: registroId,
                correo_usuario: userEmail,
                nombre_usuario: userName,
                tiempo_inicio: sessionStartTime,
                tiempo_fin: sessionEndTime,
                calificacion_estrellas: currentRating
            };

            fetch('webhook_metrics.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(res => res.json())
            .then(data => {
                const msgEl = document.getElementById('rating-message');
                msgEl.style.display = 'block';
                if (data.status === 'success') {
                    msgEl.textContent = '¡Gracias por tu calificación! La sesión ha concluido.';
                    msgEl.style.color = 'green';
                    // Deshabilitar input
                    document.getElementById('chat-input').disabled = true;
                    document.querySelector('.chat-input-area button').disabled = true;
                } else {
                    msgEl.textContent = 'Hubo un error al guardar la calificación.';
                    msgEl.style.color = 'red';
                }
            })
            .catch(err => {
                const msgEl = document.getElementById('rating-message');
                msgEl.style.display = 'block';
                msgEl.textContent = 'Error de conexión al enviar la métrica.';
                msgEl.style.color = 'red';
            });
        }
    </script>
</body>

</html>
