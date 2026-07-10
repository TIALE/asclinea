/**
 * FleetCare Tech - Frontend Login UX Enhancements
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Desvanecer automáticamente alertas de error tras 6 segundos
    const errorAlert = document.getElementById('error-alert');
    if (errorAlert) {
        setTimeout(() => {
            errorAlert.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
            errorAlert.style.opacity = '0';
            errorAlert.style.transform = 'translateY(-10px)';
            
            // Eliminar del DOM tras la animación de desvanecimiento
            setTimeout(() => {
                errorAlert.remove();
            }, 600);
        }, 6000);
    }

    // 2. Escuchar clics en el botón de Google para añadir un efecto sutil de carga (opcional)
    // Nota: El botón se renderiza dentro de un iframe seguro controlado por Google.
    // Solo podemos interactuar con el contenedor.
    const oauthContainer = document.querySelector('.oauth-button-container');
    if (oauthContainer) {
        oauthContainer.addEventListener('click', () => {
            console.log("[FleetCare Security] Iniciando comunicación con el proveedor de Google OAuth...");
        });
    }
});
