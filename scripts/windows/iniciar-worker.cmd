@echo off
setlocal
set "PROYECTO=%~dp0..\.."
set "PHP=%~1"
if not defined PHP set "PHP=php.exe"
cd /d "%PROYECTO%" || exit /b 1
if not exist "storage\logs" mkdir "storage\logs" || exit /b 1

:reiniciar
>>"storage\logs\worker-servicio.log" echo [%date% %time%] Iniciando worker.
"%PHP%" artisan queue:work --tries=3 --max-time=3600 --sleep=3
set "SALIDA=%errorlevel%"
>>"storage\logs\worker-servicio.log" echo [%date% %time%] Worker termino con codigo %SALIDA%; reinicio en 5 segundos.
timeout /t 5 /nobreak >nul
goto reiniciar
