<?php
// Script de migración de base de datos
require_once 'config/database.php';

try {
    // 1. Crear tabla de categorías globales
    $sql1 = "CREATE TABLE IF NOT EXISTS categorias_globales (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nombre VARCHAR(100) NOT NULL UNIQUE,
        descripcion TEXT,
        color VARCHAR(7) DEFAULT '#667eea',
        icono VARCHAR(50),
        estado TINYINT DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    
    $pdo->exec($sql1);
    echo "✓ Tabla categorias_globales creada/verificada<br>";
    
    // 2. Verificar si existe la columna id_categoria_global en categorias
    $stmt = $pdo->query("SHOW COLUMNS FROM categorias LIKE 'id_categoria_global'");
    $columnExists = $stmt->fetch() !== false;
    
    if (!$columnExists) {
        // 3. Agregar columna id_categoria_global a categorias
        $sql2 = "ALTER TABLE categorias ADD COLUMN id_categoria_global INT DEFAULT NULL AFTER id";
        $pdo->exec($sql2);
        echo "✓ Columna id_categoria_global agregada a categorias<br>";
        
        // 4. Agregar clave foránea
        $sql3 = "ALTER TABLE categorias ADD CONSTRAINT fk_categoria_global 
                 FOREIGN KEY (id_categoria_global) REFERENCES categorias_globales(id) ON DELETE SET NULL";
        $pdo->exec($sql3);
        echo "✓ Clave foránea agregada<br>";
    } else {
        echo "✓ Columna id_categoria_global ya existe<br>";
    }

    // 4b. Permitir que una subcategoría pertenezca a varias categorías globales
    $sqlRelacion = "CREATE TABLE IF NOT EXISTS categoria_global_subcategoria (
        id_categoria_global INT NOT NULL,
        id_subcategoria INT NOT NULL,
        PRIMARY KEY (id_categoria_global, id_subcategoria),
        CONSTRAINT fk_cgs_global FOREIGN KEY (id_categoria_global) REFERENCES categorias_globales(id) ON DELETE CASCADE,
        CONSTRAINT fk_cgs_subcategoria FOREIGN KEY (id_subcategoria) REFERENCES categorias(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $pdo->exec($sqlRelacion);
    $pdo->exec("INSERT IGNORE INTO categoria_global_subcategoria (id_categoria_global, id_subcategoria) SELECT id_categoria_global, id FROM categorias WHERE id_categoria_global IS NOT NULL");
    echo "✓ Relación múltiple entre categorías globales y subcategorías creada/verificada<br>";
    
    // 5. Insertar categorías globales predeterminadas si la tabla está vacía
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM categorias_globales");
    $result = $stmt->fetch();
    
    $categorias_globales = [
        ['nombre' => 'PAGO PERSONAL', 'color' => '#FF6B6B', 'icono' => 'person-fill'],
        ['nombre' => 'PAGO ESPECIALISTAS', 'color' => '#4ECDC4', 'icono' => 'heart-pulse'],
        ['nombre' => 'SERVICIOS BASICOS', 'color' => '#45B7D1', 'icono' => 'building'],
        ['nombre' => 'PAGO TERCEROS', 'color' => '#FFA07A', 'icono' => 'people-fill'],
        ['nombre' => 'PAGO INFORMATICOS', 'color' => '#6C63FF', 'icono' => 'laptop'],
        ['nombre' => 'ALMACEN MEDICO', 'color' => '#98D8C8', 'icono' => 'bag-heart'],
        ['nombre' => 'EQUIPAMIENTO VARIOS', 'color' => '#FF6584', 'icono' => 'tools'],
        ['nombre' => 'OTROS PAGOS', 'color' => '#95E1D3', 'icono' => 'cash-stack']
    ];

    $stmt = $pdo->prepare("UPDATE categorias SET id_categoria_global = NULL WHERE id_categoria_global IS NOT NULL");
    $stmt->execute();

    $stmt = $pdo->prepare("DELETE FROM categorias_globales");
    $stmt->execute();

    $stmt = $pdo->prepare("INSERT INTO categorias_globales (nombre, color, icono) VALUES (?, ?, ?)");
    foreach ($categorias_globales as $cat) {
        $stmt->execute([$cat['nombre'], $cat['color'], $cat['icono']]);
    }
    echo "✓ Categorías globales actualizadas a la lista oficial:<br>";
    foreach ($categorias_globales as $cat) {
        echo "- " . $cat['nombre'] . "<br>";
    }
    
    // 6. Verificar estado final
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM categorias_globales");
    $result = $stmt->fetch();
    echo "<br>✓ Total de categorías globales: " . $result['count'] . "<br>";
    
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM categorias");
    $result = $stmt->fetch();
    echo "✓ Total de categorías (subcategorías): " . $result['count'] . "<br>";
    
    echo "<br><strong>✓ Migración completada exitosamente</strong><br>";
    
} catch(Exception $e) {
    echo "✗ Error: " . $e->getMessage();
    echo "<br>Detalles: " . $e->getTraceAsString();
}
?>
