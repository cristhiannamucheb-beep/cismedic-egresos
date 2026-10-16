-- Esquema reconstruido a partir del codigo PHP (cismedic-egresos)
CREATE DATABASE IF NOT EXISTS clinica CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE clinica;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- Usuarios: login por correo + `contraseña` (password_hash / password_verify, con migracion de texto plano legado)
-- rol: 'superadmin' | 'administrativo' | 'caja'
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL DEFAULT '',
    correo VARCHAR(150) NOT NULL,
    `contraseña` VARCHAR(255) NOT NULL DEFAULT '',
    rol VARCHAR(30) NOT NULL DEFAULT 'caja',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    debe_cambiar_contrasena TINYINT(1) NOT NULL DEFAULT 0,
    intentos_fallidos INT NOT NULL DEFAULT 0,
    bloqueado_hasta DATETIME NULL DEFAULT NULL,
    ultimo_acceso DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS proveedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL DEFAULT '',
    ruc VARCHAR(20) NULL DEFAULT NULL,
    direccion VARCHAR(255) NULL DEFAULT NULL,
    contacto VARCHAR(150) NULL DEFAULT NULL,
    email VARCHAR(150) NULL DEFAULT NULL,
    responsable VARCHAR(150) NULL DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_proveedores_nombre (nombre),
    KEY idx_proveedores_ruc (ruc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tal cual migrate_db.php
CREATE TABLE IF NOT EXISTS categorias_globales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nombre VARCHAR(100) NOT NULL UNIQUE,
    descripcion TEXT,
    color VARCHAR(7) DEFAULT '#667eea',
    icono VARCHAR(50),
    estado TINYINT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Subcategorias. id_categoria_global + fk_categoria_global ya incluidos:
-- migrate_db.php hace SHOW COLUMNS y omite el ALTER si la columna existe.
CREATE TABLE IF NOT EXISTS categorias (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_categoria_global INT DEFAULT NULL,
    nombre VARCHAR(100) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_categorias_global (id_categoria_global),
    CONSTRAINT fk_categoria_global FOREIGN KEY (id_categoria_global) REFERENCES categorias_globales(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tal cual migrate_db.php
CREATE TABLE IF NOT EXISTS categoria_global_subcategoria (
    id_categoria_global INT NOT NULL,
    id_subcategoria INT NOT NULL,
    PRIMARY KEY (id_categoria_global, id_subcategoria),
    CONSTRAINT fk_cgs_global FOREIGN KEY (id_categoria_global) REFERENCES categorias_globales(id) ON DELETE CASCADE,
    CONSTRAINT fk_cgs_subcategoria FOREIGN KEY (id_subcategoria) REFERENCES categorias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Montos ya en DECIMAL(14,4) NOT NULL (facturas.php los MODIFY en cada carga)
CREATE TABLE IF NOT EXISTS facturas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_comprobante VARCHAR(30) NOT NULL DEFAULT '',
    numero_comprobante VARCHAR(50) NOT NULL DEFAULT '',
    descripcion TEXT NULL,
    id_proveedor INT NULL DEFAULT NULL,
    id_usuario INT NULL DEFAULT NULL,
    fecha DATE NULL DEFAULT NULL,
    total DECIMAL(14,4) NOT NULL DEFAULT 0,
    igv DECIMAL(14,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_facturas_proveedor (id_proveedor),
    KEY idx_facturas_usuario (id_usuario),
    KEY idx_facturas_fecha (fecha),
    KEY idx_facturas_comprobante (id_proveedor, tipo_comprobante, numero_comprobante)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS detalle_factura (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_factura INT NOT NULL,
    id_categoria INT NULL DEFAULT NULL,
    producto VARCHAR(255) NOT NULL DEFAULT '',
    cantidad DECIMAL(14,4) NOT NULL DEFAULT 0,
    precio_unitario DECIMAL(14,4) NOT NULL DEFAULT 0,
    subtotal DECIMAL(14,4) NOT NULL DEFAULT 0,
    igv DECIMAL(14,4) NOT NULL DEFAULT 0,
    subtotal_con_igv DECIMAL(14,4) NOT NULL DEFAULT 0,
    KEY idx_detalle_factura_factura (id_factura),
    KEY idx_detalle_factura_categoria (id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias_empleado (
    id_categoria INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_clinica (
    id_personal INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL DEFAULT '',
    apellido VARCHAR(100) NOT NULL DEFAULT '',
    dni VARCHAR(20) NOT NULL DEFAULT '',
    tipo_personal VARCHAR(30) NOT NULL DEFAULT 'interno',
    empresa VARCHAR(150) NULL DEFAULT NULL,
    id_categoria INT NULL DEFAULT NULL,
    cargo VARCHAR(100) NULL DEFAULT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    KEY idx_personal_categoria (id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- estado_aprobacion: 'pendiente' | 'en_aprobacion' | 'aprobado'
CREATE TABLE IF NOT EXISTS entregas_dinero (
    id_entrega INT AUTO_INCREMENT PRIMARY KEY,
    id_personal INT NULL DEFAULT NULL,
    id_usuario INT NULL DEFAULT NULL,
    id_categoria INT NULL DEFAULT NULL,
    tipo_comprobante VARCHAR(30) NOT NULL DEFAULT '',
    nComprobante VARCHAR(50) NOT NULL DEFAULT '',
    fecha_entrega DATE NULL DEFAULT NULL,
    monto DECIMAL(14,4) NOT NULL DEFAULT 0,
    descripcion TEXT NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado_aprobacion VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    KEY idx_ed_personal (id_personal),
    KEY idx_ed_usuario (id_usuario),
    KEY idx_ed_categoria (id_categoria),
    KEY idx_ed_fecha (fecha_entrega),
    KEY idx_ed_comprobante (id_personal, tipo_comprobante, nComprobante),
    KEY idx_ed_estado (estado_aprobacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entregas_proveedores (
    id_entrega_proveedor INT AUTO_INCREMENT PRIMARY KEY,
    id_proveedor INT NULL DEFAULT NULL,
    id_usuario INT NULL DEFAULT NULL,
    id_categoria INT NULL DEFAULT NULL,
    tipo_comprobante VARCHAR(30) NOT NULL DEFAULT '',
    nComprobante VARCHAR(50) NOT NULL DEFAULT '',
    fecha_entrega DATE NULL DEFAULT NULL,
    monto DECIMAL(14,4) NOT NULL DEFAULT 0,
    descripcion TEXT NULL,
    fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    estado_aprobacion VARCHAR(20) NOT NULL DEFAULT 'pendiente',
    KEY idx_ep_proveedor (id_proveedor),
    KEY idx_ep_usuario (id_usuario),
    KEY idx_ep_categoria (id_categoria),
    KEY idx_ep_fecha (fecha_entrega),
    KEY idx_ep_comprobante (id_proveedor, tipo_comprobante, nComprobante),
    KEY idx_ep_estado (estado_aprobacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grupos de egresos. estado_aprobacion: 'pendiente' | 'en_aprobacion' | 'aprobado' (el codigo tolera NULL)
CREATE TABLE IF NOT EXISTS almacenamientos_egresos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(255) NOT NULL DEFAULT '',
    fecha DATE NULL DEFAULT NULL,
    total DECIMAL(14,4) NOT NULL DEFAULT 0,
    id_usuario_registra INT NULL DEFAULT NULL,
    observacion TEXT NULL,
    estado_aprobacion VARCHAR(20) NULL DEFAULT 'pendiente',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ae_fecha (fecha),
    KEY idx_ae_usuario (id_usuario_registra),
    KEY idx_ae_estado (estado_aprobacion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fuente_egreso: 'personal' | 'proveedores'; id_egreso -> entregas_dinero.id_entrega / entregas_proveedores.id_entrega_proveedor
CREATE TABLE IF NOT EXISTS detalle_almacenamiento_egresos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_almacenamiento INT NOT NULL,
    fuente_egreso VARCHAR(20) NOT NULL DEFAULT '',
    id_egreso INT NOT NULL DEFAULT 0,
    fecha DATE NULL DEFAULT NULL,
    documento VARCHAR(100) NOT NULL DEFAULT '',
    proveedor_responsable VARCHAR(255) NOT NULL DEFAULT '',
    descripcion TEXT NULL,
    total DECIMAL(14,4) NOT NULL DEFAULT 0,
    KEY idx_dae_almacenamiento (id_almacenamiento),
    KEY idx_dae_egreso (fuente_egreso, id_egreso),
    KEY idx_dae_documento (documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tal cual egresos.php / almacenamiento_egresos.php
CREATE TABLE IF NOT EXISTS comprobantes_egresos (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fuente_egreso ENUM('personal', 'proveedores') NOT NULL,
    id_egreso INT NOT NULL,
    archivo_guardado VARCHAR(60) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    tamano INT UNSIGNED NOT NULL,
    fecha_carga TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_comprobante_egreso (fuente_egreso, id_egreso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tal cual config/aprobaciones.php
CREATE TABLE IF NOT EXISTS aprobadores_egresos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_usuario INT NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    es_final TINYINT(1) NOT NULL DEFAULT 0,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aprobador_usuario (id_usuario),
    CONSTRAINT fk_aprobador_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS aprobacion_grupos_egresos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_almacenamiento INT NOT NULL,
    id_usuario INT NOT NULL,
    es_final TINYINT(1) NOT NULL DEFAULT 0,
    fecha_aprobacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_aprobacion_usuario_grupo (id_almacenamiento, id_usuario),
    KEY idx_aprobacion_grupo (id_almacenamiento),
    KEY idx_aprobacion_usuario (id_usuario),
    CONSTRAINT fk_aprobacion_grupo FOREIGN KEY (id_almacenamiento) REFERENCES almacenamientos_egresos(id) ON DELETE CASCADE,
    CONSTRAINT fk_aprobacion_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS historial_grupos_rechazados (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    id_almacenamiento_original INT NOT NULL,
    nombre_grupo VARCHAR(255) NOT NULL,
    fecha_grupo DATE NULL,
    total DECIMAL(12,2) NOT NULL DEFAULT 0,
    motivo TEXT NOT NULL,
    id_usuario_rechaza INT NULL,
    nombre_usuario_rechaza VARCHAR(255) NOT NULL,
    rol_aprobador VARCHAR(20) NOT NULL,
    rechazado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    detalles_json LONGTEXT NOT NULL,
    aprobaciones_json LONGTEXT NOT NULL,
    KEY idx_historial_rechazados_fecha (rechazado_en),
    KEY idx_historial_rechazados_grupo (id_almacenamiento_original)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS=1;
