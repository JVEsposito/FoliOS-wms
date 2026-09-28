param(
    [string] $Proyecto = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path,
    [string] $Php
)

$ErrorActionPreference = 'Stop'
Import-Module ScheduledTasks -ErrorAction Stop
$Proyecto = (Resolve-Path $Proyecto).Path

if (-not $Php) {
    $versiones = Get-ChildItem 'C:\laragon\bin\php' -Directory -ErrorAction Stop | Where-Object {
        $_.Name -match '^php-?(\d+)\.(\d+)' -and (Test-Path (Join-Path $_.FullName 'php.exe'))
    } | Sort-Object { [version]([regex]::Match($_.Name, '\d+(\.\d+)+').Value) } -Descending
    if (-not $versiones) { throw 'No se encontro php.exe en C:\laragon\bin\php; indique -Php.' }
    $versionMasReciente = @($versiones) | Select-Object -First 1
    $Php = Join-Path $versionMasReciente.FullName 'php.exe'
}
$Php = (Resolve-Path $Php -ErrorAction Stop).Path
if (-not (Test-Path (Join-Path $Proyecto 'artisan'))) { throw "No se encontro artisan en $Proyecto" }

$usuario = [Security.Principal.WindowsIdentity]::GetCurrent().Name
$principal = New-ScheduledTaskPrincipal -UserId $usuario -LogonType S4U -RunLevel Limited
$ajustes = New-ScheduledTaskSettingsSet -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Seconds 0) `
    -RestartCount 3 -RestartInterval (New-TimeSpan -Minutes 1) -StartWhenAvailable

$workerTriggerInicio = New-ScheduledTaskTrigger -AtStartup
$workerTriggerRepeticion = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 5)
$workerCmd = Join-Path $Proyecto 'scripts\windows\iniciar-worker.cmd'
$workerAccion = New-ScheduledTaskAction -Execute $env:ComSpec `
    -Argument ('/d /c ""{0}" "{1}""' -f $workerCmd, $Php) -WorkingDirectory $Proyecto

$schedulerTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
    -RepetitionInterval (New-TimeSpan -Minutes 1)
$schedulerAccion = New-ScheduledTaskAction -Execute $Php -Argument 'artisan schedule:run' -WorkingDirectory $Proyecto

Register-ScheduledTask -TaskName 'FoliOS Worker' -Action $workerAccion `
    -Trigger @($workerTriggerInicio, $workerTriggerRepeticion) -Settings $ajustes -Principal $principal `
    -Description 'Cola FoliOS con reinicio automatico' -Force | Out-Null
Register-ScheduledTask -TaskName 'FoliOS Scheduler' -Action $schedulerAccion `
    -Trigger $schedulerTrigger -Settings $ajustes -Principal $principal `
    -Description 'Ejecuta schedule:run cada minuto' -Force | Out-Null

Write-Host "Tareas FoliOS instaladas para $usuario con $Php."
Write-Host 'Verifique el estado con .\scripts\windows\estado-servicios.ps1'
