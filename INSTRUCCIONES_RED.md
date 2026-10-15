# 🌐 CISMEDIC EGRESOS - DESPLIEGUE EN RED LOCAL

## ✅ Estado Actual
- **IP del Servidor:** `192.168.1.30`
- **Puerto:** `80` (HTTP)
- **Apache:** ✅ Activo y escuchando en todas las interfaces
- **MySQL:** ✅ Corriendo

---

## 📱 Acceso desde diferentes dispositivos

### 1️⃣ ESTA COMPUTADORA (PC Servidor)
Usa cualquiera de estas direcciones:

```
http://localhost/CISMEDIC_EGRESOS/
http://127.0.0.1/CISMEDIC_EGRESOS/
http://192.168.1.30/CISMEDIC_EGRESOS/
```

### 2️⃣ OTRA COMPUTADORA EN LA RED (Windows/Mac/Linux)
Abre el navegador en cualquier otra máquina conectada a la red:

```
http://192.168.1.30/CISMEDIC_EGRESOS/
```

### 3️⃣ CELULAR O TABLET EN LA RED (iOS/Android)
En el navegador móvil (Chrome, Safari, etc):

```
http://192.168.1.30/CISMEDIC_EGRESOS/
```

**Nota:** El celular debe estar conectado a la MISMA RED WiFi que la PC servidor.

---

## 🔧 Instrucciones de Configuración

### Paso 1: Ejecutar Script de Activación
1. Ve a la carpeta: `C:\xampp\htdocs\CISMEDIC_EGRESOS\`
2. Busca el archivo: `ACTIVAR_SERVIDOR_RED.bat`
3. **Haz clic derecho** → **"Ejecutar como administrador"**
4. Espera a que se complete (agregará reglas de firewall)

### Paso 2: Verificar Conexión
Desde otra máquina en la red, abre tu navegador e intenta acceder:
```
http://192.168.1.30/CISMEDIC_EGRESOS/
```

---

## 🚀 Mantener el Servidor Activo

Para que el sistema siga disponible:

### Opción A: Dejar Apache Corriendo (Recomendado)
- Apache seguirá activo hasta que lo cierres o reinicies la PC
- El sistema será accesible desde cualquier dispositivo en la red

### Opción B: Usar Xampp Control Panel
1. Abre `C:\xampp\xampp-control-panel.exe`
2. Verifica que Apache esté iniciado
3. Verifica que MySQL esté iniciado

### Opción C: Crear Acceso Directo
Puedes crear un acceso directo a `ACTIVAR_SERVIDOR_RED.bat` en el escritorio para iniciar todo de una sola vez.

---

## ⚠️ Solución de Problemas

### "No puedo acceder desde otra máquina"

**Solución 1: Verificar firewall**
- Ejecuta `ACTIVAR_SERVIDOR_RED.bat` como administrador
- Asegúrate de que permitir los cambios de firewall

**Solución 2: Verificar que estén en la misma red**
- Ambas máquinas deben estar conectadas al MISMO WiFi o cable de red
- NO funcionará si están en redes diferentes

**Solución 3: Verificar Apache está corriendo**
- Abre Command Prompt (cmd) como administrador
- Ejecuta: `tasklist | find "httpd"`
- Si aparece "httpd.exe", Apache está activo

**Solución 4: Acceso desde localhost sí funciona pero desde otra PC no**
- Es un problema de firewall
- Ejecuta `ACTIVAR_SERVIDOR_RED.bat` como administrador
- Espera 30 segundos y reintentan

---

## 📊 Información Técnica

| Componente | Valor |
|-----------|-------|
| IP Local | 192.168.1.30 |
| Puerto HTTP | 80 |
| Servidor Web | Apache 2.4 |
| Base de Datos | MySQL |
| PHP | Habilitado |
| Documentos Raíz | C:\xampp\htdocs\ |
| Aplicación | C:\xampp\htdocs\CISMEDIC_EGRESOS\ |

---

## 🛡️ Seguridad en Red Local

- ✅ Firewall configurado para redes **PRIVADAS** solo
- ✅ NO está abierto a internet pública
- ✅ Solo accesible desde dispositivos en la LAN local
- ✅ Contraseña requerida en CISMEDIC

---

## 📌 Comandos Útiles (PowerShell)

### Verificar si Apache está corriendo
```powershell
Get-Process | Where-Object {$_.Name -like "*httpd*"}
```

### Verificar si MySQL está corriendo
```powershell
Get-Process | Where-Object {$_.Name -like "*mysqld*"}
```

### Ver puertos activos
```powershell
netstat -ano | Select-String ":80"
```

### Reiniciar Apache
```powershell
Start-Process 'C:\xampp\apache\bin\httpd.exe' -ArgumentList '-k restart' -NoNewWindow
```

---

**Creado:** 2026-08-26  
**Actualizado:** Versión 1.0
