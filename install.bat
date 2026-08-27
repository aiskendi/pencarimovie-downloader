@echo off
setlocal
cd /d %~dp0
if exist vendor\autoload.php (
  echo Bundled vendor dependencies found. Composer install is not needed.
  exit /b 0
)
if not exist composer.json (
  echo composer.json not found.
  exit /b 1
)
set "RUNTIME=%~dp0bin\frankenphp.exe"
if not exist "%RUNTIME%" set "RUNTIME=php"
where composer >nul 2>nul
if errorlevel 1 (
  if exist composer.phar (
    "%RUNTIME%" composer.phar install --no-interaction --prefer-dist
  ) else (
    echo Composer is only required when vendor dependencies are missing.
    echo This package does not contain vendor\autoload.php and Composer was not found.
    exit /b 1
  )
) else (
  composer install --no-interaction --prefer-dist
)
if errorlevel 1 exit /b 1
echo Dependencies installed successfully.
