<?php

declare(strict_types=1);

namespace App\Infrastructure\Google;

use Exception;

/**
 * Verificador de Token de Google OAuth via HTTPS.
 * Valida la autenticidad e integridad de la credencial JWT enviada por Google Identity Services.
 */
class GoogleTokenVerifier
{
    private string $clientId;

    public function __construct(string $clientId)
    {
        $this->clientId = $clientId;
    }

    /**
     * Valida el ID Token de Google (JWT) y extrae de forma segura la información del perfil del usuario.
     * 
     * @throws Exception Si el token es inválido, manipulado o no corresponde a nuestro Client ID.
     */
    public function verify(string $idToken): array
    {
        if (empty($idToken)) {
            throw new Exception("La credencial de inicio de sesión es requerida.");
        }

        // Sanitización del token: Solo caracteres válidos de JWT (Base64URL y puntos)
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $idToken)) {
            throw new Exception("Formato de credencial inválido.");
        }

        // Endpoint seguro de Google para validar tokens de identidad
        $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($idToken);

        // Inicializar llamada cURL segura
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // OBLIGATORIO: Validar certificado TLS de Google

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("Error de conexión cURL al validar token de Google: " . $curlError);
            throw new Exception("Fallo de comunicación con los servidores de Google. Inténtelo de nuevo.");
        }

        if ($httpCode !== 200) {
            error_log("Token de Google rechazado con código HTTP " . $httpCode . ". Respuesta: " . $response);
            throw new Exception("La sesión de Google no es válida o ha expirado.");
        }

        $data = json_decode($response, true);
        if ($data === null || isset($data['error'])) {
            throw new Exception("Error al decodificar la credencial de autenticación de Google.");
        }

        // --- VALIDACIONES DE SEGURIDAD ESTRICTAS (ZERO TRUST) ---

        // 1. Verificar la Audiencia (aud) - Debe coincidir exactamente con nuestro Client ID de Google
        if (($data['aud'] ?? '') !== $this->clientId) {
            error_log("Intento de login con Google con audiencia incorrecta. Recibido: " . ($data['aud'] ?? ''));
            throw new Exception("Acceso no autorizado: Audiencia del token no válida.");
        }

        // 2. Verificar el Emisor (iss) - Debe ser de Google
        $issuer = $data['iss'] ?? '';
        if ($issuer !== 'accounts.google.com' && $issuer !== 'https://accounts.google.com') {
            throw new Exception("Acceso no autorizado: Emisor del token inválido.");
        }

        // 3. Verificar que el correo electrónico esté verificado
        if (($data['email_verified'] ?? '') !== 'true') {
            throw new Exception("Acceso no autorizado: La cuenta de Google suministrada no está verificada.");
        }

        return [
            'google_sub' => $data['sub'], // Identificador de usuario único permanente de Google
            'correo'     => $data['email'],
            'nombre'     => $data['name'] ?? 'Usuario de Google',
            'foto'       => $data['picture'] ?? null,
        ];
    }
}
