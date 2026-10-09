@echo off
rem Starts the SWS HR demo (UAT) setup in two windows:
rem   Web + API server  -> http://localhost:8020  (database: sws_hr_uat)
rem   Mobile app (web)  -> http://localhost:8082
cd /d "%~dp0"

rem MariaDB from XAMPP (holds the sws_hr_uat database)
netstat -ano | findstr /R /C:":3306 .*LISTENING" >nul && (echo Database is already running.) || (
  start "SWS HR database :3306" /min /d "E:\xampp8.2.12" mysql\bin\mysqld.exe --defaults-file=mysql\bin\my.ini --standalone --console
  timeout /t 5 /nobreak >nul
)

netstat -ano | findstr /R /C:":8020 .*LISTENING" >nul && (echo Port 8020 is already in use - the server is probably already running.) || (
  start "SWS HR server :8020" cmd /k "set DB_DATABASE=sws_hr_uat&& set APP_URL=http://localhost:8020&& set QUEUE_CONNECTION=sync&& php artisan serve --host=0.0.0.0 --port=8020"
)

netstat -ano | findstr /R /C:":8082 .*LISTENING" >nul && (echo Port 8082 is already in use - the mobile app is probably already running.) || (
  start "SWS HR mobile :8082" cmd /k "cd mobile&& set CI=1&& set npm_config_cache=E:\devcache\npm&& set TEMP=E:\devcache\tmp&& set TMP=E:\devcache\tmp&& npx expo start --lan --port 8082"
)

echo.
echo Open in Chrome:
echo   Web app    : http://localhost:8020
echo   Mobile app : http://localhost:8082   (Server box: http://localhost:8020)
echo   Desktop app: double-click python\SWS HR Desktop.bat
echo.
for /f "usebackq delims=" %%i in (`powershell -NoProfile -Command "(Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up' } | Select-Object -First 1).IPv4Address.IPAddress"`) do set LANIP=%%i
echo On the phone (same Wi-Fi, mobile data off):
echo   Check in Chrome : http://%LANIP%:8082/status
echo   Expo Go URL     : exp://%LANIP%:8082
echo   Server box      : http://%LANIP%:8020
echo.
pause
