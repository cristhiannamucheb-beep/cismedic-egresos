@echo off
setlocal
set APACHE=C:\xampp\apache\bin\httpd.exe

echo Validando configuracion de Apache...
"%APACHE%" -t
if errorlevel 1 (
  echo La configuracion de Apache tiene errores. No se reiniciara.
  pause
  exit /b 1
)

echo Habilitando HTTP solo para redes privadas...
netsh advfirewall firewall add rule name="CISMEDIC Apache HTTP" dir=in action=allow protocol=TCP localport=80 profile=Private >nul
if errorlevel 1 (
  echo No se pudo modificar el firewall. Ejecute este archivo como administrador.
  pause
  exit /b 1
)

echo Reiniciando Apache...
"%APACHE%" -k restart
if errorlevel 1 (
  echo No se pudo reiniciar Apache. Revise el panel de XAMPP.
  pause
  exit /b 1
)

echo.
echo CISMEDIC disponible en:
echo http://192.168.1.30/CISMEDIC_EGRESOS/
echo.
pause
