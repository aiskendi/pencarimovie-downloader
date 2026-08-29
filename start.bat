@echo off
setlocal
title PencariMovie Server
if not defined PENCARIMOVIE_NO_BANNER call :print_banner
for %%I in ("%~dp0.") do set "ROOT=%%~fI"
cd /d "%ROOT%"
set "FRANKENPHP_EXE=%ROOT%\bin\frankenphp.exe"
set HOST=0.0.0.0
set PORT=8088

rem Configure FrankenPHP/Caddy via environment variables (per https://github.com/php/frankenphp/blob/main/caddy/frankenphp/Caddyfile)
set CADDY_GLOBAL_OPTIONS=skip_install_trust
set FRANKENPHP_CONFIG=
set CADDY_EXTRA_CONFIG=

rem Detect LAN IP via default gateway route (avoids virtual adapter IPs)
set "LAN_IP="
for /f "tokens=4" %%i in ('route print -4 0.0.0.0 ^| findstr /R /C:" 0\.0\.0\.0[ ]*0\.0\.0\.0"') do (
  if not defined LAN_IP set "LAN_IP=%%i"
)

if exist "%FRANKENPHP_EXE%" goto start_server
php -v >nul 2>nul
if errorlevel 1 (
  echo PHP or FrankenPHP is required but was not found.
  echo Place FrankenPHP at %FRANKENPHP_EXE% or install PHP in PATH.
  echo.
  pause
  endlocal
  goto :eof
)

:start_server
echo Starting PencariMovie Server in the background...
if exist "%ROOT%\tray.ps1" (
  call :start_tray 1
) else (
  call :start_server_hidden
)
call :print_urls
echo   Stop:     "%~dp0stop.bat"
echo   Tray:     right-click the PencariMovie icon in the system tray
echo.
echo This window will close. The server keeps running in the background.
timeout /t 8
endlocal
goto :eof

:start_server_hidden
if exist "%FRANKENPHP_EXE%" (
  if exist "%ROOT%\start-hidden.ps1" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\start-hidden.ps1" -FilePath "%FRANKENPHP_EXE%" -CommandLine "php-server --listen %HOST%:%PORT% --root ""%ROOT%"""
  ) else (
    start "PencariMovie Server" /MIN "%FRANKENPHP_EXE%" php-server --listen %HOST%:%PORT% --root "%ROOT%"
  )
) else (
  if exist "%ROOT%\start-hidden.ps1" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\start-hidden.ps1" -FilePath php -CommandLine "-S %HOST%:%PORT% router.php"
  ) else (
    start "PencariMovie Server" /MIN php -S %HOST%:%PORT% router.php
  )
)
goto :eof

:start_tray
if not exist "%ROOT%\tray.ps1" goto :eof
if not exist "%ROOT%\start-hidden.ps1" (
  start "PencariMovie Tray" /MIN powershell.exe -NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File "%ROOT%\tray.ps1" -Port %PORT% -OpenUrl http://127.0.0.1:%PORT% -StopBat "%ROOT%\stop.bat" -StartServer
  goto :eof
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%ROOT%\start-hidden.ps1" -FilePath powershell.exe -CommandLine "-NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File ""%ROOT%\tray.ps1"" -Port %PORT% -OpenUrl http://127.0.0.1:%PORT% -StopBat ""%ROOT%\stop.bat"" -StartServer"
goto :eof

:print_banner
echo(
echo  ========================================
echo           PencariMovie Server
echo  ========================================
echo(
goto :eof

:print_urls
echo.
echo   Local:    http://127.0.0.1:%PORT%
if not "%LAN_IP%"=="" (
  echo   Network:  http://%LAN_IP%:%PORT%
  echo.
  echo   Other devices on your network can connect using the Network URL above.
)
echo.
goto :eof
