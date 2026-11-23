  @echo off
cd /d "%~dp0"
set "PHP_EXE="
where php.exe >nul 2>nul
if %errorlevel%==0 set "PHP_EXE=php.exe"
if not defined PHP_EXE if exist "%~dp0..\..\php\php.exe" set "PHP_EXE=%~dp0..\..\php\php.exe"
if defined PHP_EXE (
  echo Starting with PHP authentication router: http://localhost:8000/
  start "Medicine Scanner PHP Server" "%PHP_EXE%" -S 0.0.0.0:8000 auth_router.php
  for /L %%i in (1,1,15) do (
    powershell -NoProfile -Command "try { Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8000/api.php?action=auth_token' -TimeoutSec 1 | Out-Null; exit 0 } catch { exit 1 }" >nul 2>nul
    if not errorlevel 1 goto :server_ready
    timeout /t 1 /nobreak >nul
  )
  echo PHP server did not become ready at http://localhost:8000/.
  goto :eof
:server_ready
  start "" http://localhost:8000/
  goto :eof
)
echo PHP is required because the website now requires authenticated sessions.
echo Install PHP (XAMPP: https://www.apachefriends.org) and enable pdo_sqlite.
pause
