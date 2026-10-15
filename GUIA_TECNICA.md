# 🔧 Guía Técnica - Sistema de Control de Egresos

## Sistema de Archivos

```
CISMEDIC_EGRESOS/
│
├── 📄 Páginas Principales
│   ├── index.php              # Página de login
│   ├── dashboard.php          # Panel principal
│   ├── egresos.php            # Gestión de egresos
│   ├── facturas.php           # Gestión de facturas
│   ├── proveedores.php        # Gestión de proveedores
│   ├── categorias.php         # Categorías de egresos
│   ├── reportes.php           # Reportes y análisis
│   ├── perfil.php             # Perfil de usuario
│   └── logout.php             # Cerrar sesión
│
├── 📁 config/
│   └── database.php           # Conexión a MySQL (PDO)
│
├── 📁 assets/
│   ├── css/
│   │   └── style.css          # Estilos CSS principal
│   │                           # Bootstrap 5 + personalizado
│   │
│   └── js/
│       └── main.js            # JavaScript funcionalidades
│                               # Toggle sidebar, validaciones
│
├── 📖 Documentación
│   ├── README.md              # Guía completa
│   ├── INICIO_RAPIDO.md       # Quick start
│   ├── GUIA_CARACTERISTICAS.md # Features
│   └── GUIA_TECNICA.md        # Este archivo
```

---

## Configuración de Base de Datos

### Configuración local (no se publica en Git)

Crea `config/database.local.php` usando el formato documentado en `README.md`, o define las variables de entorno `CISMEDIC_DB_HOST`, `CISMEDIC_DB_NAME`, `CISMEDIC_DB_USER` y `CISMEDIC_DB_PASSWORD`. No guardes contraseñas reales en `config/database.php` ni en este repositorio.

### Cambiar Credenciales

Si tu MySQL tiene contraseña o usuario diferente:

1. Edita tu copia local de `config/database.local.php`, que está excluida de Git.
2. O configura las variables de entorno del servidor.
3. No compartas ni publiques los valores secretos.

---

## Tablas de Base de Datos

### usuarios
```sql
CREATE TABLE `usuarios` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `correo` VARCHAR(100) UNIQUE NOT NULL,
  `contraseña` VARCHAR(255) NOT NULL,
  `rol` ENUM('admin','usuario') DEFAULT 'usuario'
);
```

### facturas
```sql
CREATE TABLE `facturas` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `tipo_comprobante` VARCHAR(50),
  `numero_comprobante` VARCHAR(100),
  `descripcion` TEXT,
  `id_proveedor` INT,
  `id_usuario` INT,
  `fecha` DATE,
  `total` DOUBLE,
  `igv` DOUBLE DEFAULT 0
);
```

### proveedores
```sql
CREATE TABLE `proveedores` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `ruc` VARCHAR(20) UNIQUE NOT NULL,
  `direccion` VARCHAR(200),
  `contacto` VARCHAR(100),
  `email` VARCHAR(100),
  `responsable` VARCHAR(100),
  `activo` TINYINT DEFAULT 1,
  `fecha_registro` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### categorias
```sql
CREATE TABLE `categorias` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `nombre` VARCHAR(150) UNIQUE NOT NULL,
  `id_usuario_creador` INT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## Tecnologías Utilizadas

### Frontend
- **HTML5** - Estructura
- **CSS3** - Estilos avanzados
- **Bootstrap 5** - Framework CSS responsivo
- **Bootstrap Icons** - Iconografía
- **JavaScript Vanilla** - Funcionalidades interactivas
- **No jQuery** - Código puro

### Backend
- **PHP 7.4+** - Lenguaje servidor
- **PDO** - Abstracción de base de datos
- **Prepared Statements** - Seguridad SQL
- **Sessions** - Control de autenticación

### Database
- **MySQL 8.0** - Base de datos relacional
- **Charset UTF8MB4** - Soporte completo de caracteres
- **Índices** - Optimización de consultas

---

## Seguridad Implementada

### 1. Autenticación
```php
session_start();
if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
```

### 2. Prepared Statements (Protección SQL Injection)
```php
$stmt = $pdo->prepare("SELECT * FROM usuarios WHERE correo = ?");
$stmt->execute([$correo]);  // Parametrizado
```

### 3. Validación de Entrada
```php
$correo = trim($_POST['correo'] ?? '');
$contraseña = trim($_POST['contraseña'] ?? '');
```

### 4. Escapado de Salida
```php
<?= htmlspecialchars($usuario['nombre']) ?>
```

---

## Estructura de Sesiones

### Variables de Sesión Usadas
```php
$_SESSION['usuario_id']      // ID del usuario
$_SESSION['usuario_nombre']  // Nombre completo
$_SESSION['usuario_rol']     // Rol (admin/usuario)
```

### Ciclo de Sesión
```
Login → Crear sesión → Navegar → Logout → Destruir sesión
```

---

## Configuración de XAMPP

### Versión Recomendada
- PHP: 7.4 o superior
- MySQL: 8.0+
- Apache: 2.4+

### Ubicación de Archivos
```
C:\xampp\htdocs\CISMEDIC_EGRESOS\  ← Tu proyecto aquí
```

### Habilitar Extensiones PHP Necesarias
Los siguientes módulos deben estar habilitados en `php.ini`:
- `extension=pdo`
- `extension=pdo_mysql`
- `extension=mysqli`

---

## Debugging y Logs

### Logs de XAMPP
```
Errores Apache:   C:\xampp\apache\logs\error.log
Errores MySQL:    C:\xampp\mysql\data\[hostname].err
```

### Habilitar Modo Debug en PHP
En `config/database.php`:
```php
// Mostrar errores (solo desarrollo)
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### Ver Errores de BD
```php
try {
    $stmt = $pdo->query("SELECT * FROM tabla");
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
```

---

## Performance y Optimización

### 1. Paginación
Limita los registros mostrados:
```php
$limit = 20;  // 20 registros por página
$offset = ($page - 1) * $limit;
$stmt->execute([$limit, $offset]);
```

### 2. Índices de Base de Datos
Asegúrate de que existan:
```sql
CREATE INDEX idx_fecha ON facturas(fecha);
CREATE INDEX idx_proveedor ON facturas(id_proveedor);
```

### 3. Caché de CSS/JS
Los archivos se sirven directamente (no hay caché configurado)

### 4. Consultas Eficientes
- Usa SELECT específico (no SELECT *)
- JOIN cuando sea necesario
- LIMIT para grandes conjuntos

---

## Desarrollo y Extensión

### Agregar Nueva Página

1. Crea archivo `nueva_pagina.php`
2. Incluye sesión y BD:
```php
<?php
session_start();
if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}
require_once 'config/database.php';
?>
```

3. Copia la estructura HTML del sidebar
4. Personaliza el contenido

### Agregar API REST

Crea `api/egresos_api.php`:
```php
<?php
header('Content-Type: application/json');
require_once '../config/database.php';

if($_GET['action'] == 'listar') {
    $stmt = $pdo->query("SELECT * FROM facturas");
    echo json_encode($stmt->fetchAll());
}
?>
```

Acceso: `http://localhost/CISMEDIC_EGRESOS/api/egresos_api.php?action=listar`

---

## Mejoras Futuras Técnicas

### Backend
- [ ] API REST completa
- [ ] Sistema de permisos granular
- [ ] Logging de auditoría
- [ ] Cache de consultas
- [ ] Backup automático

### Frontend
- [ ] SPA con Vue.js o React
- [ ] Gráficos con Chart.js
- [ ] Búsqueda en tiempo real
- [ ] Validación en cliente mejorada
- [ ] Modo oscuro

### DevOps
- [ ] Docker containerization
- [ ] CI/CD pipeline
- [ ] Testing automatizado
- [ ] Deployment a producción

---

## Referencia de Puertos

| Servicio | Puerto | Estado |
|----------|--------|--------|
| Apache | 80 | http://localhost |
| MySQL | 3306 | localhost:3306 |
| PHPMyAdmin | 80 | localhost/phpmyadmin |

---

## Contacto Técnico

Para issues técnicos, consulta:
- Logs de XAMPP
- Console del navegador (F12)
- phpMyAdmin para inspeccionar BD

---

**Última actualización:** 18/08/2026  
**Versión del Sistema:** 1.0.0
