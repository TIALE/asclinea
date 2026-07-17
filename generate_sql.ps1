$ErrorActionPreference = "Stop"

# Mapa de meses
$meses = @{
    "ene" = "01"; "feb" = "02"; "mar" = "03"; "abr" = "04";
    "may" = "05"; "jun" = "06"; "jul" = "07"; "ago" = "08";
    "sep" = "09"; "oct" = "10"; "nov" = "11"; "dic" = "12"
}

# Mapa de ATAs
$ataMap = @{
    "12" = "12 - Servicing"
    "21" = "21 - Air Conditioning"
    "22" = "22 - Auto Flight"
    "23" = "23 - Communications"
    "24" = "24 - Electrical Power"
    "25" = "25 - Equipo abordo"
    "27" = "27 - Flight Controls"
    "28" = "28 - Fuel"
    "29" = "29 - Hydraulic Power"
    "31" = "31 - Indicating/Recording Systems"
    "32" = "32 - Landing Gear"
    "33" = "33 - Lights"
    "34" = "34 - Navigation"
    "36" = "36 - Pneumatic"
    "51" = "51 - Standard Practices and Structures"
    "52" = "52 - Doors"
    "53" = "53 - Fuselage"
    "56" = "56 - Windows"
    "57" = "57 - Wings"
    "71" = "71 - Powerplant"
    "72" = "72 - Engine"
    "77" = "77 - Engine Indicating"
    "78" = "78 - Exhaust"
    "80" = "80 - Starting"
}

$lineas = Import-Csv -Path "c:\Users\l.rodriguez\Documents\Antigravity\asclinea\data.tsv" -Delimiter "`t" -Encoding UTF8
$outputSql = "c:\Users\l.rodriguez\Documents\Antigravity\asclinea\database\importar_fallas_nuevas.sql"

if (Test-Path $outputSql) { Remove-Item $outputSql }

# Counters for IDs per day
$idCounters = @{}

$sqlStatements = @()
$sqlStatements += "-- Archivo autogenerado para importar fallas."
$sqlStatements += "USE u359185291_asclinea; -- Cambiar si es necesario"
$sqlStatements += "SET NAMES utf8mb4;"
$sqlStatements += "BEGIN;"

foreach ($row in $lineas) {
    # If the row is completely empty, skip it
    if ([string]::IsNullOrWhiteSpace($row.Fecha) -and [string]::IsNullOrWhiteSpace($row.Modelo)) { continue }

    $fechaRaw = [string]$row."Fecha"
    $modelo = [string]$row."Modelo"
    $matricula = [string]$row."Matricula "
    $ataRaw = [string]$row."ATA"
    $folio = [string]$row."Logbook Folio"
    $condicion = [string]$row."Condicion"
    
    $desc = [string]$row."Descripcion del Reporte"
    $accion = [string]$row."Accion correctiva"
    $ref = [string]$row."Referencia"
    $tips = [string]$row."Tips"
    $base = [string]$row."Base"
    $registrado = [string]$row."Registrado por "
    $mel = [string]$row."MEL Ref y Cat / Restricciones"
    $compCambiado = [string]$row."Comp. Cambiado"
    $npRemovido = [string]$row."N/P removido"
    $snRemovido = [string]$row."S/N removido"

    # Make sure required fields are not completely null if DB requires them
    if ([string]::IsNullOrWhiteSpace($desc)) { $desc = "Sin descripción" }
    if ([string]::IsNullOrWhiteSpace($accion)) { $accion = "Sin acción" }

    $fechaRaw = $fechaRaw.Trim()
    $modelo = $modelo.Trim()
    $matricula = $matricula.Trim()
    $ataRaw = $ataRaw.Trim()

    $fechaSql = ""
    if ($fechaRaw -match "^(\d{2})-([a-z]{3})-(\d{2})$") {
        $dia = $matches[1]
        $mesStr = $matches[2].ToLower()
        $mes = $meses[$mesStr]
        $anio = "20" + $matches[3]
        $fechaSql = "$anio-$mes-$dia"
    } elseif ($fechaRaw -match "^(\d{4})-(\d{2})-(\d{2})$") {
        $fechaSql = $fechaRaw
    } else {
        $fechaSql = "2026-01-01" # fallback
    }

    # Generate ID based on Date
    $dateKey = $fechaSql -replace "-",""
    if (-not $idCounters.ContainsKey($dateKey)) {
        $idCounters[$dateKey] = 1
    }
    # For example 2026021201
    $idFalla = [int64]($dateKey + $idCounters[$dateKey].ToString("00"))
    $idCounters[$dateKey]++

    # Map ATA
    $ataFinal = $ataRaw
    # If ATA is just a number or number + "y" + number (like 28 y 12)
    # We will format it.
    if ($ataRaw -match "^\d{2}$") {
        if ($ataMap.ContainsKey($ataRaw)) {
            $ataFinal = $ataMap[$ataRaw]
        } else {
            $ataFinal = "$ataRaw - Sistema $ataRaw"
        }
    } elseif ($ataRaw -eq "28 y 12") {
        $ataFinal = "28 - Fuel / 12 - Servicing"
    } elseif ($ataRaw -eq "12 Y 7") {
        $ataFinal = "12 - Servicing / 07 - Lifting and Shoring"
    } elseif ($ataRaw -eq "8765") {
        $ataFinal = "21 - Air Conditioning" # Fix the obvious typo
        $folio = "8765"
    }

    # Format conditionally
    if ($compCambiado -eq "SI" -or $compCambiado -eq "Sí") { $compCambiado = "Sí" } else { $compCambiado = "No" }

    # Escape strings for SQL
    function Esc($s) {
        if ([string]::IsNullOrEmpty($s) -or $s -eq "N/A" -or $s -eq "NULL") { return "''" }
        $s = $s -replace "'","''"
        return "'$s'"
    }

    $sql = "INSERT INTO tbo_Falla (id_falla, modelo, matricula, ata, condicion, folio, fecha, categoria_mel, descripcion, accion_correctiva, referencia, tips, base, registrado_por, horas, ciclos, tiempo_atencion, componente_cambiado, comp_removido_np, comp_removido_ns) VALUES ("
    $sql += "$idFalla, "
    $sql += "$(Esc $modelo), "
    $sql += "$(Esc $matricula), "
    $sql += "$(Esc $ataFinal), "
    $sql += "$(Esc $condicion), "
    $sql += "$(Esc $folio), "
    $sql += "$(Esc $fechaSql), "
    $sql += "$(Esc $mel), "
    $sql += "$(Esc $desc), "
    $sql += "$(Esc $accion), "
    $sql += "$(Esc $ref), "
    $sql += "$(Esc $tips), "
    $sql += "$(Esc $base), "
    $sql += "$(Esc $registrado), "
    $sql += "NULL, NULL, NULL, "
    $sql += "$(Esc $compCambiado), "
    $sql += "$(Esc $npRemovido), "
    $sql += "$(Esc $snRemovido)"
    $sql += ");"

    $sqlStatements += $sql
}

$sqlStatements += "COMMIT;"
$Utf8NoBomEncoding = New-Object System.Text.UTF8Encoding $False
[System.IO.File]::WriteAllText($outputSql, ($sqlStatements -join "`r`n"), $Utf8NoBomEncoding)
Write-Output "SQL Script generado exitosamente en $outputSql"
