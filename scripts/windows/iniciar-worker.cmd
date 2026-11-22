@echo off
setlocal
set "PHP=%~1"
if not defined PHP set "PHP=php.exe"
powershell.exe -NoLogo -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0iniciar-worker.ps1" -Php "%PHP%"
exit /b %errorlevel%
