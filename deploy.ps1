param(
    [switch]$Full
)

$ErrorActionPreference = "Stop"

Write-Host "==================================================" -ForegroundColor Cyan
Write-Host "    DESPLIEGUE AUTOMATIZADO - FLEETCARE TECH    " -ForegroundColor Cyan
Write-Host "==================================================" -ForegroundColor Cyan

# 1. Validar existencia de .env local
$envPath = Join-Path $PSScriptRoot ".env"
if (-not (Test-Path $envPath)) {
    Write-Error "Error de Configuración: No se encontró el archivo .env local con las credenciales de despliegue."
}

# 2. Cargar credenciales desde .env
$envVars = @{}
Get-Content $envPath | ForEach-Object {
    $line = $_.Trim()
    if ($line -and -not $line.StartsWith("#")) {
        $parts = $line -split '=', 2
        if ($parts.Length -eq 2) {
            $key = $parts[0].Trim()
            $val = $parts[1].Trim()
            
            # Sanitizar comillas rodeantes si existen
            if (($val.StartsWith('"') -and $val.EndsWith('"')) -or ($val.StartsWith("'") -and $val.EndsWith("'"))) {
                $val = $val.Substring(1, $val.Length - 2)
            }
            $envVars[$key] = $val
        }
    }
}

$ftpHost = $envVars["FTP_HOST"]
$ftpUser = $envVars["FTP_USER"]
$ftpPass = $envVars["FTP_PASS"]
$ftpPath = $envVars["FTP_REMOTE_PATH"]

if (-not $ftpHost -or -not $ftpUser -or -not $ftpPass) {
    Write-Error "Error de Configuración: Las variables de entorno de FTP en el archivo .env están incompletas."
}

Write-Host "Servidor Destino : $ftpHost" -ForegroundColor Yellow
Write-Host "Ruta Remota      : $ftpPath" -ForegroundColor Yellow
Write-Host "Iniciando análisis recursivo..." -ForegroundColor Yellow

# Función para crear directorios remotos via FTP (Manejo tolerante a directorios existentes)
function Create-RemoteDirectory {
    param (
        [string]$uri,
        [System.Net.NetworkCredential]$credentials
    )
    try {
        $request = [System.Net.FtpWebRequest]::Create($uri)
        $request.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
        $request.Credentials = $credentials
        $request.UseBinary = $true
        $request.KeepAlive = $false
        
        $response = $request.GetResponse()
        $response.Close()
    } catch {
        # Si el directorio ya existe, el servidor FTP retornará un error (550).
        # Lo ignoramos de forma segura porque significa que el folder ya está listo.
    }
}

function Upload-File {
    param (
        [string]$localPath,
        [string]$remoteUri,
        [System.Net.NetworkCredential]$credentials
    )
    $maxRetries = 3
    $retryCount = 0
    $success = $false
    
    while (-not $success -and $retryCount -lt $maxRetries) {
        $webClient = New-Object System.Net.WebClient
        $webClient.Credentials = $credentials
        try {
            $displayPath = $localPath.Replace($PSScriptRoot, "")
            if ($retryCount -gt 0) {
                Write-Host "Reintentando ($retryCount/$maxRetries): $displayPath" -ForegroundColor Yellow
                Start-Sleep -Milliseconds 1000
            } else {
                Write-Host "Subiendo: $displayPath" -ForegroundColor DarkGray
            }
            $webClient.UploadFile($remoteUri, "STOR", $localPath)
            $success = $true
        } catch {
            $retryCount++
            if ($retryCount -ge $maxRetries) {
                Write-Host "FALLO definitivo: No se pudo subir el archivo $localPath" -ForegroundColor Red
                throw $_
            }
        } finally {
            $webClient.Dispose()
        }
    }
}

# 3. Configurar credenciales de acceso de red
$credentials = New-Object System.Net.NetworkCredential($ftpUser, $ftpPass)

# 4. Asegurar que los directorios base del FTP existan
# Segmentar la ruta remota para crearlos de manera progresiva
$pathSegments = $ftpPath.Split('/')
$currentFtpPath = ""
foreach ($segment in $pathSegments) {
    if ($segment -ne "") {
        $currentFtpPath += "/$segment"
        $dirUri = "ftp://$ftpHost$currentFtpPath"
        Create-RemoteDirectory -uri $dirUri -credentials $credentials
    }
}

$filesToUpload = Get-ChildItem -Path $PSScriptRoot -Recurse -File | Where-Object {
    $_.FullName -notmatch '\\\.git' -and
    $_.FullName -notmatch '\\\.agents' -and
    $_.FullName -notmatch '\\\.gemini' -and
    $_.Extension -ne ".db" -and
    $_.Extension -ne ".enc" -and
    $_.Name -ne ".env" -and
    $_.Name -ne ".env.example" -and
    $_.Name -ne "deploy.ps1" -and
    ($Full -or $_.LastWriteTime -gt (Get-Date).AddHours(-12))
}

# 6. Procesar e iniciar transferencia
$total = $filesToUpload.Count
$current = 0

foreach ($file in $filesToUpload) {
    $current++
    
    # Calcular directorios relativos usando barras diagonales de FTP (/)
    $relativeDir = $file.DirectoryName.Replace($PSScriptRoot, "").Replace("\", "/")
    
    # Si hay directorios intermedios, asegurar su existencia en el servidor remoto
    if ($relativeDir -ne "") {
        $dirParts = $relativeDir.Split('/')
        $accumulatedPath = $ftpPath
        foreach ($part in $dirParts) {
            if ($part -ne "") {
                $accumulatedPath += "/$part"
                $checkUri = "ftp://$ftpHost$accumulatedPath"
                Create-RemoteDirectory -uri $checkUri -credentials $credentials
            }
        }
    }
    
    # Generar URI del archivo remoto
    $remoteFileUri = "ftp://$ftpHost$ftpPath$relativeDir/$($file.Name)"
    
    # Ejecutar carga segura
    Upload-File -localPath $file.FullName -remoteUri $remoteFileUri -credentials $credentials
}

Write-Host "==================================================" -ForegroundColor Green
Write-Host "   ¡DESPLIEGUE FINALIZADO CON ÉXITO! ($current/$total)   " -ForegroundColor Green
Write-Host "==================================================" -ForegroundColor Green
