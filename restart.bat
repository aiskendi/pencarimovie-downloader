@echo off
setlocal
cd /d "%~dp0"

call stop.bat
echo Restarting PencariMovie Server...
call start.bat

endlocal
