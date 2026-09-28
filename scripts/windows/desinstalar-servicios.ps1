$ErrorActionPreference = 'Stop'
Import-Module ScheduledTasks -ErrorAction Stop
foreach ($nombre in @('FoliOS Worker', 'FoliOS Scheduler')) {
    if (Get-ScheduledTask -TaskName $nombre -ErrorAction SilentlyContinue) {
        Stop-ScheduledTask -TaskName $nombre -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $nombre -Confirm:$false
        Write-Host "Eliminada: $nombre"
    } else {
        Write-Host "No instalada: $nombre"
    }
}
