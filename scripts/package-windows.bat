@echo off
setlocal enabledelayedexpansion

set "ROOT=%~dp0.."
for %%I in ("%ROOT%") do set "ROOT=%%~fI"
set "DIST=%ROOT%\dist"
set "BUILD=%DIST%\pencarimovie-downloader-windows-x86_64"
set "ZIP=%DIST%\pencarimovie-downloader-windows-x86_64.zip"

echo Building Windows release package...

if exist "%BUILD%" rmdir /s /q "%BUILD%"
if not exist "%DIST%" mkdir "%DIST%"
mkdir "%BUILD%"

call :copy_file backend.php
call :copy_file index.php
call :copy_file router.php
call :copy_file composer.json
call :copy_file composer.lock
call :copy_file composer.phar
call :copy_file install.bat
call :copy_file start.bat
call :copy_file stop.bat
call :copy_file restart.bat
call :copy_file package.json
call :copy_file README.md
call :copy_file LICENSE
call :copy_file THIRD_PARTY_NOTICES.md
call :copy_file SECURITY.md

xcopy "%ROOT%\public" "%BUILD%\public" /E /I /Y >nul
mkdir "%BUILD%\storage"
copy /Y "%ROOT%\storage\.gitkeep" "%BUILD%\storage\.gitkeep" >nul 2>nul
copy /Y "%ROOT%\storage\config.example.json" "%BUILD%\storage\config.example.json" >nul

REM Extract bin/ from official FrankenPHP Windows release ZIP directly to BUILD/bin
REM Then overlay repo php.ini (official ZIP only has php.ini-development/production templates)
if exist "%ROOT%\frankenphp-windows-x86_64.zip" (
  echo Extracting Windows FrankenPHP runtime from frankenphp-windows-x86_64.zip...
  mkdir "%BUILD%\bin"
  powershell -NoProfile -Command "Expand-Archive -Force '%ROOT%\frankenphp-windows-x86_64.zip' -DestinationPath '%BUILD%\bin'"
  if exist "%ROOT%\bin\php.ini" (
    echo Overlaying repo php.ini for Windows runtime...
    copy /Y "%ROOT%\bin\php.ini" "%BUILD%\bin\php.ini" >nul
  )
) else (
  echo WARNING: frankenphp-windows-x86_64.zip not found. Using repo bin/ as fallback.
  xcopy "%ROOT%\bin" "%BUILD%\bin" /E /I /Y >nul
)

if exist "%ROOT%\vendor\autoload.php" (
  xcopy "%ROOT%\vendor" "%BUILD%\vendor" /E /I /Y >nul
) else (
  echo WARNING: vendor\autoload.php not found. Release will require Composer install.
)

if exist "%ZIP%" del /q "%ZIP%"
tar -a -cf "%ZIP%" -C "%BUILD%" .

echo Created %ZIP%
exit /b 0

:copy_file
copy /Y "%ROOT%\%~1" "%BUILD%\%~1" >nul
exit /b 0
