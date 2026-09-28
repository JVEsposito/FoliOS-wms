$ErrorActionPreference = 'Stop'
foreach ($archivo in (Get-ChildItem $PSScriptRoot -Filter '*.ps1')) {
    $tokens = $null
    $errores = $null
    [System.Management.Automation.Language.Parser]::ParseFile($archivo.FullName, [ref] $tokens, [ref] $errores) | Out-Null
    if ($errores) { throw "Errores en $($archivo.Name): $($errores | Out-String)" }
}
Write-Host 'Sintaxis PowerShell correcta.'
