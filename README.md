# 🏥 Sistema de Control de Egresos - Clínica CISMEDIC

Sistema web moderno para gestionar y controlar todos los egresos (gastos) de la clínica CISMEDIC. Construido con PHP, MySQL, Bootstrap 5 y JavaScript.

## 📋 Características

✅ **Dashboard intuitivo** - Visualiza estadísticas en tiempo real  
✅ **Gestión de Egresos** - Registra y controla todos tus gastos  
✅ **Administración de Facturas** - Gestiona facturas y comprobantes  
✅ **Base de Proveedores** - Mantén un registro de todos tus proveedores  
✅ **Categorías de Gastos** - Organiza tus egresos por categoría  
✅ **Reportes Analíticos** - Análisis de egresos por mes y proveedor  
✅ **Interfaz Responsive** - Funciona perfectamente en cualquier dispositivo  
✅ **Sidebar Plegable** - Navegación limpia y organizada  
✅ **Sistema de Usuarios** - Control de acceso y roles  

## 🛠️ Requisitos Previos

- **XAMPP** (PHP 7.4+, MySQL 8.0+)
- **MySQL Workbench** (para gestionar la base de datos)
- **Navegador web** moderno (Chrome, Firefox, Edge, Safari)

## 📦 Instalación

### 1. Preparar la carpeta del proyecto

```bash
# La carpeta ya existe en:
C:\xampp\htdocs\CISMEDIC_EGRESOS
```

### 2. Verificar la base de datos

Asegúrate de que la base de datos `clinica` esté creada en MySQL con todas las tablas.

### 3. Configurar conexión local

Las credenciales no se guardan en Git. Crea `config/database.local.php` en cada instalación local con este formato y reemplaza los valores por los de tu servidor:

```php
<?php
return [
   'host' => 'localhost',
   'name' => 'clinica',
   'user' => 'root',
   'password' => '',
];
```

También se pueden definir `CISMEDIC_DB_HOST`, `CISMEDIC_DB_NAME`, `CISMEDIC_DB_USER` y `CISMEDIC_DB_PASSWORD` como variables de entorno. `.env.example` solo documenta los nombres; nunca añadas contraseñas reales a Git.

Los archivos de `storage/comprobantes_egresos`, `storage/pdfs` y los instaladores/certificados de `storage/Firma_Digital` son locales y se excluyen del repositorio. Deben provisionarse mediante los procedimientos privados de la clínica.

### 4. Iniciar XAMPP

1. Abre XAMPP Control Panel
2. Inicia **Apache** y **MySQL**
3. Verifica que ambos estén corriendo

### 5. Acceder al sistema

En tu navegador, ve a:

```
http://localhost/CISMEDIC_EGRESOS/
```

## 👤 Acceso

Usa una cuenta creada por el administrador de tu instalación. No guardes ni publiques contraseñas reales o de prueba en este repositorio.

## 📁 Estructura del Proyecto

```
CISMEDIC_EGRESOS/
├── index.php              # Página de login
├── dashboard.php          # Panel principal
├── egresos.php            # Gestión de egresos
├── facturas.php           # Gestión de facturas
├── proveedores.php        # Gestión de proveedores
├── categorias.php         # Categorías de egresos
├── reportes.php           # Reportes y análisis
├── perfil.php             # Perfil de usuario
├── logout.php             # Cerrar sesión
├── config/
│   └── database.php       # Conexión a MySQL
├── assets/
│   ├── css/
│   │   └── style.css      # Estilos principal
│   └── js/
│       └── main.js        # Scripts JavaScript
└── README.md              # Este archivo
```

## 🎯 Cómo usar

### Crear un Nuevo Egreso

1. Ve a la sección **Egresos** en el menú lateral
2. Haz clic en **"Nuevo Egreso"**
3. Completa el formulario:
   - Tipo de comprobante (Factura, Recibo, etc.)
   - Número de comprobante
   - Fecha del egreso
   - Proveedor
   - Monto total e IGV
   - Descripción (opcional)
4. Haz clic en **"Guardar Egreso"**

### Registrar Proveedor

1. Ve a **Proveedores**
2. Haz clic en **"Nuevo Proveedor"**
3. Completa los datos:
   - Nombre
   - RUC
   - Dirección
   - Contacto
   - Email
4. Guarda

### Ver Reportes

1. Ve a la sección **Reportes**
2. Visualiza:
   - Egresos por mes
   - Top 10 proveedores
   - Análisis de gastos

### Cambiar Contraseña

1. Haz clic en tu nombre en la esquina inferior del sidebar
2. Ve a **"Mi Perfil"**
3. Completa el formulario para cambiar tu contraseña

## 🔧 Funcionalidades Detalladas

### Dashboard
- Resumen de egresos del mes actual
- Total de facturas registradas
- Promedio diario de gastos
- Estado del sistema
- Últimas facturas registradas
- Botones de acceso rápido

### Egresos
- Listado completo de egresos
- Búsqueda y filtrado
- Información del proveedor y usuario
- Paginación
- Opciones para ver o eliminar registros

### Facturas
- Registro completo de todas las facturas
- Totales y IGV
- Información del proveedor
- Estadísticas de facturación

### Proveedores
- Directorio completo de proveedores
- Información de contacto
- Estado activo/inactivo
- Responsable

### Reportes
- Análisis de egresos por mes
- Top 10 proveedores por gasto
- Gráficos y tablas
- Exportable (próximamente)

## 📊 Base de Datos - Tablas Principales

| Tabla | Descripción |
|-------|-------------|
| `usuarios` | Usuarios del sistema |
| `facturas` | Registro de facturas |
| `egresos` | Egresos registrados |
| `proveedores` | Base de proveedores |
| `categorias` | Categorías de egresos |
| `personal_clinica` | Personal de la clínica |
| `compras` | Órdenes de compra |

## 🔐 Seguridad

- ✅ Sesiones de usuario
- ✅ Validación en servidor
- ✅ Protección contra inyección SQL (prepared statements)
- ✅ Control de acceso por rol
- ✅ Contraseñas cifradas (mejora futura)

## 📱 Responsividad

El sistema está optimizado para:
- 🖥️ Desktop (1920x1080+)
- 💻 Laptop (1366x768)
- 📱 Tablet (768px+)
- 📱 Móvil (320px+)

## 🚀 Mejoras Futuras

- [ ] Exportación de reportes a PDF
- [ ] Gráficos avanzados con Chart.js
- [ ] Búsqueda y filtrado avanzado
- [ ] Sistema de aprobación de egresos
- [ ] Auditoría y historial de cambios
- [ ] Integración con contabilidad
- [ ] Notificaciones
- [ ] Backup automático

## 🆘 Solución de Problemas

### Error: "Error de conexión a la base de datos"

**Solución:** Verifica que:
1. XAMPP esté ejecutándose (Apache + MySQL)
2. La base de datos `clinica` exista
3. Los datos de conexión en `config/database.php` sean correctos

### Error: "Página en blanco"

**Solución:**
1. Revisa los logs de XAMPP (`C:\xampp\apache\logs\error.log`)
2. Verifica la sintaxis PHP
3. Asegúrate de que session esté habilitada

### Las tablas no aparecen

**Solución:**
1. Abre MySQL Workbench
2. Conectate y verifica que la BD `clinica` exista
3. Importa los datos desde `c:\Users\Usuario2\Documents\Dump20260817\`

## 📞 Soporte

Para reportar issues o solicitar funcionalidades, contacta al equipo de desarrollo.

## 📄 Licencia

Este proyecto es propietario de Clínica CISMEDIC.

---

**Última actualización:** 18/08/2026  
**Versión:** 1.0.0

