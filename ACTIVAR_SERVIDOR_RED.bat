@echo off
REM Script para desplegar CISMEDIC_EGRESOS en red local
REM DEBE EJECUTARSE COMO ADMINISTRADOR

echo ======================================
echo   CISMEDIC EGRESOS - RED LOCAL
echo ======================================
echo.

REM Verificar permisos de administrador
net session >nul 2>&1
if errorlevel 1 (
  echo ERROR: Este script requiere permisos de administrador.
  echo Haga clic derecho y seleccione "Ejecutar como administrador"
  pause
  exit /b 1
)

echo [1] Configurando Firewall...
netsh advfirewall firewall add rule name="CISMEDIC Apache HTTP" dir=in action=allow protocol=TCP localport=80 profile=Private >nul 2>&1
if errorlevel 1 (
  echo ADVERTENCIA: No se pudo agregar regla de firewall (posiblemente ya existe)
) else (
  echo [OK] Regla de firewall agregada
)

echo.
echo [2] Iniciando Apache...
tasklist | find /i "httpd.exe" >nul 2>&1
if errorlevel 1 (
  echo Apache no está corriendo. Iniciando...
  start "" "C:\xampp\apache\bin\httpd.exe"
  timeout /t 2 /nobreak
  echo [OK] Apache iniciado
) else (
  echo [OK] Apache ya está corriendo
)

echo.
echo [3] Verificando MySQL...
tasklist | find /i "mysqld.exe" >nul 2>&1
if errorlevel 1 (
  echo ADVERTENCIA: MySQL no está corriendo
) else (
  echo [OK] MySQL está corriendo
)

echo.
echo ======================================
echo   SERVIDOR ACTIVO EN LA RED
echo ======================================
echo.
echo Dirección IP: 192.168.1.30
echo.
echo Sistemas disponibles en la red:
echo   http://192.168.1.30/CISMEDIC_EGRESOS/
echo   http://192.168.1.30/CISMEDIC_INVENTARIO/
echo   http://192.168.1.30/CISMEDIC_RAYOSX/
echo   http://192.168.1.30/CLINICA/
echo   http://192.168.1.30/CLINICA2/
echo   http://192.168.1.30/CONTROL_INVENTARIO/
echo.
echo Para acceder desde ESTA computadora:
echo   http://localhost/CISMEDIC_EGRESOS/
echo   http://127.0.0.1/CISMEDIC_EGRESOS/
echo.
echo ======================================
echo.
pause
