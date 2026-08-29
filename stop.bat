@echo off
setlocal
set HOST=0.0.0.0
set PORT=8088
for %%I in ("%~dp0.") do set "ROOT=%%~fI"

echo Stopping PencariMovie Server on %HOST%:%PORT%...

for /f "tokens=5" %%P in ('netstat -ano ^| findstr "%HOST%:%PORT%" ^| findstr "LISTENING"') do (
  echo Killing process PID %%P
  taskkill /PID %%P /F >nul 2>nul
)

call :stop_tray

echo Stop command completed.
endlocal
goto :eof

:stop_tray
powershell -NoProfile -Command "try { $e = New-Object System.Threading.EventWaitHandle $false, ([System.Threading.EventResetMode]::AutoReset), 'Global\PencariMovieServerTrayStop'; $e.Set() | Out-Null; $e.Dispose() } catch {}" >nul 2>nul
timeout /t 1 /nobreak >nul
if exist "%ROOT%\storage\tray.pid" (
  for /f "usebackq delims=" %%P in ("%ROOT%\storage\tray.pid") do (
    if not "%%P"=="" taskkill /PID %%P /F >nul 2>nul
  )
  del /q "%ROOT%\storage\tray.pid" >nul 2>nul
)
powershell -NoProfile -Command "Get-CimInstance Win32_Process -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -and $_.CommandLine -match 'pencarimovie.+tray\.ps1' } | ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }" >nul 2>nul
goto :eof
