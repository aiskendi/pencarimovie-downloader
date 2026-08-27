@echo off
setlocal
set HOST=0.0.0.0
set PORT=8088

echo Stopping PencariMovie Downloader on %HOST%:%PORT%...

for /f "tokens=5" %%P in ('netstat -ano ^| findstr "%HOST%:%PORT%" ^| findstr "LISTENING"') do (
  echo Killing process PID %%P
  taskkill /PID %%P /F >nul 2>nul
)

echo Stop command completed.
endlocal
