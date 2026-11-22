param(
    [string] $Php = 'php.exe',
    [switch] $Integraciones
)

$ErrorActionPreference = 'Stop'
Set-Location (Join-Path $PSScriptRoot '..\..')
New-Item -ItemType Directory -Path 'storage/logs' -Force | Out-Null

$log = 'storage/logs/worker-servicio.log'
$argumentos = @('artisan', 'queue:work', '--tries=3', '--max-time=3600', '--sleep=3')
if ($Integraciones) {
    $log = 'storage/logs/worker-integraciones.log'
    $argumentos = @('artisan', 'queue:work', 'integraciones', '--queue=integraciones', '--tries=1', '--timeout=180', '--max-time=3600', '--sleep=1')
}

function Escribir-Registro([string] $Mensaje) {
    Add-Content -LiteralPath $log -Value ('[{0:yyyy-MM-dd HH:mm:ss zzz}] {1}' -f (Get-Date), $Mensaje)
}

$pausas = @(5, 15, 45)
$salidasRapidas = 0
while ($true) {
    Escribir-Registro 'Iniciando worker.'
    $reloj = [Diagnostics.Stopwatch]::StartNew()
    $codigo = 1
    try {
        & $Php @argumentos
        $codigo = $LASTEXITCODE
    } catch {
        Escribir-Registro 'No se pudo ejecutar PHP.'
    }
    $reloj.Stop()
    $duracion = $reloj.Elapsed.TotalSeconds
    Escribir-Registro ('Worker termino con codigo {0} tras {1:F1} s.' -f $codigo, $duracion)

    if ($duracion -lt 60) {
        $salidasRapidas++
        if ($salidasRapidas -gt $pausas.Count) {
            Escribir-Registro 'Cuatro workers seguidos duraron menos de un minuto cada uno. Se devuelve el control al Programador de tareas.'
            exit 1
        }
    } else {
        $salidasRapidas = 0
    }

    $pausa = $pausas[[Math]::Max(0, $salidasRapidas - 1)]
    Escribir-Registro "Reinicio en $pausa s."
    Start-Sleep -Seconds $pausa
}
