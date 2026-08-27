@echo off
setlocal
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

if exist "%FRANKENPHP_EXE%" (
  echo Starting PencariMovie Downloader with FrankenPHP...
  start "PencariMovie Downloader" /B "%FRANKENPHP_EXE%" php-server --listen %HOST%:%PORT% --root "%ROOT%"
  call :print_urls
  echo FrankenPHP stopped or exited.
  goto :pause
)

php -v >nul 2>nul
if errorlevel 1 (
  echo PHP or FrankenPHP is required but was not found.
  echo Place FrankenPHP at %FRANKENPHP_EXE% or install PHP in PATH.
  goto :pause
)

echo Starting PencariMovie Downloader with PHP...
start "PencariMovie Downloader" /B php -S %HOST%:%PORT% router.php
call :print_urls
echo PHP server stopped or exited.

:pause
echo.
pause
endlocal
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
