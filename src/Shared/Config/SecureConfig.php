<?php

declare(strict_types=1);

namespace App\Shared\Config;

/**
 * Servicio de encriptación y desencriptación AES-256-CBC
 * para almacenar variables y claves de forma segura y cifrada en reposo.
 */
class SecureConfig
{
    private static string $filePath = __DIR__ . '/../../../database/config_secure.enc';

    /**
     * Obtiene la llave de cifrado maestra de las variables de entorno.
     * Si no existe, autogenera una llave aleatoria segura y la persiste en el archivo .env local.
     */
    public static function getKey(): string
    {
        $key = getenv('SECURE_CONFIG_KEY');
        if (empty($key)) {
            // Autogenerar una llave aleatoria fuerte de 32 bytes (256 bits) en formato hexadecimal
            $key = bin2hex(random_bytes(32));
            $envPath = __DIR__ . '/../../../.env';
            if (file_exists($envPath)) {
                $content = file_get_contents($envPath);
                // Si la clave no está registrada en el .env, la anexamos de forma limpia
                if (strpos($content, 'SECURE_CONFIG_KEY') === false) {
                    $newLine = PHP_EOL . "SECURE_CONFIG_KEY=\"{$key}\"" . PHP_EOL;
                    file_put_contents($envPath, $newLine, FILE_APPEND);
                    putenv("SECURE_CONFIG_KEY={$key}");
                    $_ENV['SECURE_CONFIG_KEY'] = $key;
                    $_SERVER['SECURE_CONFIG_KEY'] = $key;
                }
            } else {
                // En caso de que no exista el archivo .env, lo creamos
                file_put_contents($envPath, "SECURE_CONFIG_KEY=\"{$key}\"" . PHP_EOL);
                putenv("SECURE_CONFIG_KEY={$key}");
                $_ENV['SECURE_CONFIG_KEY'] = $key;
                $_SERVER['SECURE_CONFIG_KEY'] = $key;
            }
        }
        return hash('sha256', $key, true);
    }

    /**
     * Encripta un array asociativo de configuraciones y lo guarda en reposo.
     * Utiliza un IV aleatorio de 16 bytes que se concatena al texto cifrado para mayor seguridad.
     */
    public static function encrypt(array $data): bool
    {
        try {
            $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($jsonData === false) {
                return false;
            }

            $key = self::getKey();
            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            $iv = random_bytes($ivLength);

            $ciphertext = openssl_encrypt($jsonData, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($ciphertext === false) {
                return false;
            }

            // El payload consolidado es: [IV de 16 bytes] + [Texto Cifrado]
            $payload = $iv . $ciphertext;
            
            // Asegurar que exista la carpeta contenedora
            $dir = dirname(self::$filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            return file_put_contents(self::$filePath, base64_encode($payload)) !== false;
        } catch (\Exception $e) {
            error_log("Error en encriptación de configuración segura: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Desencripta el archivo de configuración segura y devuelve un array asociativo.
     */
    public static function decrypt(): array
    {
        try {
            if (!file_exists(self::$filePath)) {
                return [];
            }

            $fileContent = file_get_contents(self::$filePath);
            if (empty($fileContent)) {
                return [];
            }

            $payload = base64_decode($fileContent);
            $ivLength = openssl_cipher_iv_length('aes-256-cbc');
            if (strlen($payload) < ($ivLength + 1)) {
                return [];
            }

            $iv = substr($payload, 0, $ivLength);
            $ciphertext = substr($payload, $ivLength);
            $key = self::getKey();

            $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
            if ($decrypted === false) {
                return [];
            }

            return json_decode($decrypted, true) ?: [];
        } catch (\Exception $e) {
            error_log("Error en desencriptación de configuración segura: " . $e->getMessage());
            return [];
        }
    }
}
