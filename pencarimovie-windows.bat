@echo off
setlocal enabledelayedexpansion
title PencariMovie Server
call :print_banner

set "APP_DIR=pencarimovie-server"
set "REPO=aiskendi/pencarimovie-downloader"
set "FALLBACK_TAG=v1.0.0"
set "PORT=8088"
set "HAD_APP=0"
set "UPDATED=0"
set "IN_PLACE=0"
if exist "%~dp0backend.php" if exist "%~dp0start.bat" (
    set "APP_DIR=."
    set "IN_PLACE=1"
)

if "%1"=="--stop" goto stop
if "%1"=="--restart" goto restart
if not "%1"=="" if not "%1"=="--start" (
    echo Usage: %~nx0 [--start^|--stop^|--restart]
    pause
    exit /b 1
)

:start
if exist "%~dp0pencarimovie-downloader" if not "%APP_DIR%"=="pencarimovie-downloader" (
    if exist "%~dp0pencarimovie-downloader\storage" if not exist "%~dp0%APP_DIR%\storage" (
        mkdir "%~dp0%APP_DIR%" 2>nul
        robocopy "%~dp0pencarimovie-downloader\storage" "%~dp0%APP_DIR%\storage" /e /np /nfl /ndl /njh /njs >nul 2>nul
    )
    rmdir /s /q "%~dp0pencarimovie-downloader" 2>nul
)
if exist "%~dp0%APP_DIR%" set "HAD_APP=1"
call :install_or_update

>nul 2>nul curl -s -o nul http://127.0.0.1:%PORT%
if not errorlevel 1 goto port_busy
>nul 2>nul powershell -NoProfile -Command "try { $r=Invoke-WebRequest -Uri 'http://127.0.0.1:%PORT%' -Method HEAD -TimeoutSec 2; exit 0 } catch { exit 1 }"
if not errorlevel 1 goto port_busy
goto not_running

:port_busy
if "%HAD_APP%"=="1" if "%UPDATED%"=="0" goto already_running
echo Port %PORT% is already in use; stopping leftover process...
call :stop_quiet
timeout /t 1 /nobreak >nul
goto not_running

:already_running
echo Server is already running on port %PORT%.
call :start_tray 1
call :print_urls
echo   Stop:     "%~f0" --stop
echo   Tray:     right-click the PencariMovie icon in the system tray
echo.
echo This window will close. The server keeps running in the background.
timeout /t 8
exit /b 0

:not_running
if not exist "%~dp0%APP_DIR%" (
    echo App directory was not installed.
    pause
    exit /b 1
)
cd /d "%~dp0%APP_DIR%"
echo Starting PencariMovie Server in the background...
if exist "%cd%\tray.ps1" (
    call :start_tray 1
) else (
    if exist "bin\frankenphp.exe" (
        if exist "%cd%\start-hidden.ps1" (
            powershell -NoProfile -ExecutionPolicy Bypass -File "%cd%\start-hidden.ps1" -FilePath "%cd%\bin\frankenphp.exe" -CommandLine "php-server --listen 0.0.0.0:%PORT% --root ""%cd%"""
        ) else (
            start "PencariMovie Server" /MIN "%cd%\bin\frankenphp.exe" php-server --listen 0.0.0.0:%PORT% --root "%cd%"
        )
    ) else (
        if exist "%cd%\start-hidden.ps1" (
            powershell -NoProfile -ExecutionPolicy Bypass -File "%cd%\start-hidden.ps1" -FilePath php -CommandLine "-S 0.0.0.0:%PORT% router.php"
        ) else (
            start "PencariMovie Server" /MIN php -S 0.0.0.0:%PORT% router.php
        )
    )
    call :start_tray
)

echo.
echo PencariMovie Server is running in the background.
call :print_urls
echo   Stop:     "%~f0" --stop
echo   Tray:     right-click the PencariMovie icon in the system tray
echo.
echo This window will close. The server keeps running in the background.
timeout /t 8
exit /b 0

:stop
echo Stopping PencariMovie Server on 0.0.0.0:%PORT%...
call :stop_quiet
echo Server stopped.
pause
exit /b 0

:restart
call :stop_quiet
timeout /t 2 /nobreak >nul
goto start

:stop_quiet
netstat -ano 2>nul | findstr "0.0.0.0:%PORT%" | findstr "LISTENING" >nul 2>nul
if not errorlevel 1 (
    for /f "tokens=5" %%P in ('netstat -ano ^| findstr "0.0.0.0:%PORT%" ^| findstr "LISTENING"') do (
        taskkill /PID %%P /F >nul 2>nul
        echo Killed PID %%P
    )
)
call :stop_tray
goto :eof

:start_tray
if not exist "%~dp0%APP_DIR%\tray.ps1" goto :eof
if not exist "%~dp0%APP_DIR%\start-hidden.ps1" (
    start "PencariMovie Tray" /MIN powershell.exe -NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File "%~dp0%APP_DIR%\tray.ps1" -Port %PORT% -OpenUrl http://127.0.0.1:%PORT% -StopBat "%~dp0%APP_DIR%\stop.bat" -StartServer
    goto :eof
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0%APP_DIR%\start-hidden.ps1" -FilePath powershell.exe -CommandLine "-NoProfile -STA -WindowStyle Hidden -ExecutionPolicy Bypass -File ""%~dp0%APP_DIR%\tray.ps1"" -Port %PORT% -OpenUrl http://127.0.0.1:%PORT% -StopBat ""%~dp0%APP_DIR%\stop.bat"" -StartServer"
goto :eof

:stop_tray
powershell -NoProfile -Command "try { $e = New-Object System.Threading.EventWaitHandle $false, ([System.Threading.EventResetMode]::AutoReset), 'Global\PencariMovieServerTrayStop'; $e.Set() | Out-Null; $e.Dispose() } catch {}" >nul 2>nul
timeout /t 1 /nobreak >nul
if exist "%~dp0%APP_DIR%\storage\tray.pid" (
    for /f "usebackq delims=" %%P in ("%~dp0%APP_DIR%\storage\tray.pid") do (
        if not "%%P"=="" taskkill /PID %%P /F >nul 2>nul
    )
    del /q "%~dp0%APP_DIR%\storage\tray.pid" >nul 2>nul
)
powershell -NoProfile -Command "Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -and $_.CommandLine -match 'pencarimovie.+tray\.ps1' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }" >nul 2>nul
goto :eof

:print_urls
echo   Local:    http://127.0.0.1:%PORT%
for /f "tokens=4" %%i in ('route print -4 0.0.0.0 ^| findstr /R /C:" 0\.0\.0\.0[ ]*0\.0\.0\.0"') do (
    if not defined LAN_IP set "LAN_IP=%%i"
)
if defined LAN_IP echo   Network:  http://%LAN_IP%:%PORT%
goto :eof

:install_or_update
if "%IN_PLACE%"=="1" (
    echo Starting from this folder; skipping GitHub extract.
    goto :eof
)
set "APP_PATH=%~dp0%APP_DIR%"
set "CURRENT="
if exist "%APP_PATH%\.release-tag" (
    for /f "usebackq delims=" %%A in ("%APP_PATH%\.release-tag") do set "CURRENT=%%A"
)
if exist "%APP_PATH%" if not defined CURRENT (
    set "CURRENT=%FALLBACK_TAG%"
    >"%APP_PATH%\.release-tag" echo %FALLBACK_TAG%
)

set "LATEST="
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "$ErrorActionPreference='Stop'; [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; $req = [System.Net.HttpWebRequest]::Create('https://github.com/%REPO%/releases/latest'); $req.AllowAutoRedirect = $false; $req.Method = 'GET'; $req.UserAgent = 'pencarimovie-downloader'; try { $resp = $req.GetResponse() } catch [System.Net.WebException] { $resp = $_.Exception.Response }; if (-not $resp) { exit 1 }; $loc = [string]$resp.Headers['Location']; $resp.Close(); $tag = ($loc.TrimEnd('/') -split '/')[-1]; if ($tag -match '^v[0-9]') { $tag } else { exit 1 }"`) do set "LATEST=%%i"

if not defined LATEST (
    if exist "%APP_PATH%" (
        echo Could not check GitHub for updates; using installed copy.
        goto :eof
    )
    set "LATEST=%FALLBACK_TAG%"
)

if exist "%APP_PATH%" if /I "!CURRENT!"=="!LATEST!" goto :eof

if not exist "%APP_PATH%" (
    echo Downloading PencariMovie Server !LATEST!...
) else (
    echo Updating PencariMovie Server !CURRENT! -^> !LATEST!...
    call :stop_quiet
    timeout /t 1 /nobreak >nul
)

powershell -NoProfile -Command "& { param($appDir,$tag,$repo) $ErrorActionPreference='Stop'; [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12; $url = 'https://github.com/' + $repo + '/releases/download/' + $tag + '/pencarimovie-downloader-windows-x86_64.zip'; $tmp = Join-Path $env:TEMP ('pencarimovie-ota-' + [guid]::NewGuid().ToString()); New-Item -ItemType Directory -Path (Join-Path $tmp 'extract') | Out-Null; Write-Host ('Downloading ' + $url); Invoke-WebRequest -Uri $url -OutFile (Join-Path $tmp 'pencarimovie.zip') -UseBasicParsing; Expand-Archive -Path (Join-Path $tmp 'pencarimovie.zip') -DestinationPath (Join-Path $tmp 'extract') -Force; $found = Get-ChildItem -Path (Join-Path $tmp 'extract') -Recurse -Filter 'backend.php' | Select-Object -First 1; if ($found) { $src = $found.DirectoryName } else { $src = Join-Path $tmp 'extract' }; if (-not (Test-Path $appDir)) { New-Item -ItemType Directory -Path $appDir | Out-Null }; Get-ChildItem -LiteralPath $src | Where-Object { $_.Name -ne 'storage' } | ForEach-Object { $dest = Join-Path $appDir $_.Name; if (Test-Path $dest) { Remove-Item $dest -Recurse -Force }; Copy-Item $_.FullName $dest -Recurse -Force }; [System.IO.File]::WriteAllText((Join-Path $appDir '.release-tag'), $tag + [char]10, [System.Text.Encoding]::ASCII); Remove-Item $tmp -Recurse -Force }" "%APP_PATH%" "!LATEST!" "%REPO%"
if errorlevel 1 (
    echo Update download/extract failed.
    pause
    exit /b 1
)
set "UPDATED=1"
goto :eof

:print_banner
echo(
echo  ========================================
echo           PencariMovie Server
echo  ========================================
echo(
goto :eof

endlocal
