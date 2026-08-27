@echo off
setlocal
cd /d "%~dp0"

call stop.bat
echo Restarting PencariMovie Downloader...
call start.bat

endlocal
