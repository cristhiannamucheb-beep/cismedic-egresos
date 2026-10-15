<?php
require_once __DIR__ . '/database.php';

function inicializar_tablas_aprobacion(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS aprobadores_egresos (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        es_final TINYINT(1) NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_aprobador_usuario (id_usuario),
        CONSTRAINT fk_aprobador_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aprobacion_grupos_egresos (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS historial_grupos_rechazados (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach (['aprobadores_egresos', 'aprobacion_grupos_egresos'] as $tabla) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$tabla, 'es_final']);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `$tabla` ADD COLUMN es_final TINYINT(1) NOT NULL DEFAULT 0");
        }
    }
}

function obtener_aprobadores_activos(PDO $pdo, ?bool $solo_finales = null): array
{
    $sql = 'SELECT u.id, u.nombre, u.rol, a.es_final FROM aprobadores_egresos a JOIN usuarios u ON u.id = a.id_usuario WHERE a.activo = 1 AND u.activo = 1';
    $params = [];
    if ($solo_finales !== null) {
        $sql .= ' AND a.es_final = ?';
        $params[] = $solo_finales ? 1 : 0;
    }
    $sql .= ' ORDER BY u.nombre';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function obtener_requisitos_aprobacion(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT a.es_final, COUNT(*) AS total FROM aprobadores_egresos a JOIN usuarios u ON u.id = a.id_usuario WHERE a.activo = 1 AND u.activo = 1 GROUP BY a.es_final');
    $totales = ['regular' => 0, 'final' => 0];
    foreach ($stmt->fetchAll() as $fila) {
        $tipo = (int) $fila['es_final'] === 1 ? 'final' : 'regular';
        $totales[$tipo] = (int) $fila['total'];
    }

    return ['regular' => $totales['regular'], 'final' => $totales['final'] > 0 ? 1 : 0];
}

function contar_aprobaciones_grupo_por_tipo(PDO $pdo, int $idAlmacenamiento): array
{
    $stmt = $pdo->prepare('SELECT
        SUM(CASE WHEN ag.es_final = 0 THEN 1 ELSE 0 END) AS regulares,
        SUM(CASE WHEN ag.es_final = 1 THEN 1 ELSE 0 END) AS finales
        FROM aprobacion_grupos_egresos ag
        JOIN aprobadores_egresos a ON a.id_usuario = ag.id_usuario AND a.activo = 1 AND a.es_final = ag.es_final
        JOIN usuarios u ON u.id = ag.id_usuario AND u.activo = 1
        WHERE ag.id_almacenamiento = ?');
    $stmt->execute([$idAlmacenamiento]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['regulares' => 0, 'finales' => 0];

    return [
        'regular' => (int) ($fila['regulares'] ?? 0),
        'final' => (int) ($fila['finales'] ?? 0),
    ];
}

function usuario_es_aprobador_activo(PDO $pdo, int $idUsuario): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM aprobadores_egresos WHERE id_usuario = ? AND activo = 1 LIMIT 1');
    $stmt->execute([$idUsuario]);
    return $stmt->fetchColumn() !== false;
}

function grupo_esta_aprobado(PDO $pdo, int $idAlmacenamiento): bool
{
    $stmt = $pdo->prepare('SELECT estado_aprobacion FROM almacenamientos_egresos WHERE id = ? LIMIT 1');
    $stmt->execute([$idAlmacenamiento]);
    return (string) ($stmt->fetchColumn() ?: '') === 'aprobado';
}

function contar_aprobaciones_grupo(PDO $pdo, int $idAlmacenamiento): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM aprobacion_grupos_egresos WHERE id_almacenamiento = ?');
    $stmt->execute([$idAlmacenamiento]);
    return (int) $stmt->fetchColumn();
}

function usuario_ya_aprobo_grupo(PDO $pdo, int $idAlmacenamiento, int $idUsuario): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM aprobacion_grupos_egresos WHERE id_almacenamiento = ? AND id_usuario = ? LIMIT 1');
    $stmt->execute([$idAlmacenamiento, $idUsuario]);
    return $stmt->fetchColumn() !== false;
}

function recalcular_estado_aprobacion_grupo(PDO $pdo, int $idAlmacenamiento): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ?');
    $stmt->execute([$idAlmacenamiento]);
    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->prepare('UPDATE almacenamientos_egresos SET estado_aprobacion = "pendiente" WHERE id = ?')->execute([$idAlmacenamiento]);
        return;
    }

    $requisitos = obtener_requisitos_aprobacion($pdo);
    $aprobaciones = contar_aprobaciones_grupo_por_tipo($pdo, $idAlmacenamiento);
    $estado = ($aprobaciones['regular'] >= $requisitos['regular'] && $aprobaciones['final'] >= $requisitos['final'])
        ? 'aprobado'
        : 'en_aprobacion';

    $pdo->prepare('UPDATE almacenamientos_egresos SET estado_aprobacion = ? WHERE id = ?')->execute([$estado, $idAlmacenamiento]);
}

function revertir_aprobacion_grupo(PDO $pdo, int $idAlmacenamiento): void
{
    $pdo->prepare('DELETE FROM aprobacion_grupos_egresos WHERE id_almacenamiento = ?')->execute([$idAlmacenamiento]);
    recalcular_estado_aprobacion_grupo($pdo, $idAlmacenamiento);
}

function quitar_aprobacion_usuario(PDO $pdo, int $idUsuario): int
{
    $stmt = $pdo->prepare('DELETE FROM aprobacion_grupos_egresos WHERE id_usuario = ?');
    $stmt->execute([$idUsuario]);

    $grupos = $pdo->query('SELECT id FROM almacenamientos_egresos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($grupos as $idGrupo) {
        recalcular_estado_aprobacion_grupo($pdo, (int) $idGrupo);
    }

    return $stmt->rowCount();
}

function revertir_todas_las_aprobaciones(PDO $pdo): void
{
    $pdo->prepare('DELETE FROM aprobacion_grupos_egresos')->execute();

    $grupos = $pdo->query('SELECT id FROM almacenamientos_egresos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN, 0);
    foreach ($grupos as $idGrupo) {
        recalcular_estado_aprobacion_grupo($pdo, (int) $idGrupo);
    }
}

function rechazar_y_archivar_grupo(PDO $pdo, int $idAlmacenamiento, int $idUsuario, string $rolAprobador, string $motivo): void
{
    $stmtGrupo = $pdo->prepare('SELECT a.id, a.nombre, a.fecha, a.total, u.nombre AS usuario_registra FROM almacenamientos_egresos a LEFT JOIN usuarios u ON u.id = a.id_usuario_registra WHERE a.id = ? FOR UPDATE');
    $stmtGrupo->execute([$idAlmacenamiento]);
    $grupo = $stmtGrupo->fetch(PDO::FETCH_ASSOC);
    if (!$grupo) {
        throw new RuntimeException('El grupo ya no existe o fue rechazado anteriormente.');
    }

    $stmtDetalles = $pdo->prepare('SELECT id, fuente_egreso, id_egreso, fecha, documento, proveedor_responsable, descripcion, total FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ? FOR UPDATE');
    $stmtDetalles->execute([$idAlmacenamiento]);
    $detalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);
    if (!$detalles) {
        throw new RuntimeException('No se puede archivar un grupo sin egresos.');
    }

    $stmtVotos = $pdo->prepare('SELECT ag.id_usuario, ag.es_final, ag.fecha_aprobacion, u.nombre FROM aprobacion_grupos_egresos ag JOIN usuarios u ON u.id = ag.id_usuario WHERE ag.id_almacenamiento = ? ORDER BY ag.fecha_aprobacion, ag.id');
    $stmtVotos->execute([$idAlmacenamiento]);
    $votos = $stmtVotos->fetchAll(PDO::FETCH_ASSOC);
    $stmtRechazador = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = ? LIMIT 1');
    $stmtRechazador->execute([$idUsuario]);
    $nombreRechazador = (string) ($stmtRechazador->fetchColumn() ?: 'Usuario');

    $stmtArchivo = $pdo->prepare('INSERT INTO historial_grupos_rechazados (id_almacenamiento_original, nombre_grupo, fecha_grupo, total, motivo, id_usuario_rechaza, nombre_usuario_rechaza, rol_aprobador, detalles_json, aprobaciones_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmtArchivo->execute([
        $idAlmacenamiento,
        $grupo['nombre'],
        $grupo['fecha'],
        $grupo['total'],
        $motivo,
        $idUsuario,
        $nombreRechazador,
        $rolAprobador,
        json_encode(['registrado_por' => $grupo['usuario_registra'], 'egresos' => $detalles], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        json_encode($votos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
    ]);

    foreach ($detalles as $detalle) {
        if ($detalle['fuente_egreso'] === 'personal') {
            $stmtLiberar = $pdo->prepare("UPDATE entregas_dinero SET estado_aprobacion = 'pendiente' WHERE id_entrega = ?");
        } elseif ($detalle['fuente_egreso'] === 'proveedores') {
            $stmtLiberar = $pdo->prepare("UPDATE entregas_proveedores SET estado_aprobacion = 'pendiente' WHERE id_entrega_proveedor = ?");
        } else {
            throw new RuntimeException('El grupo contiene un egreso sin una fuente válida; no se archivó el rechazo.');
        }
        $stmtLiberar->execute([(int) $detalle['id_egreso']]);
        if ($stmtLiberar->rowCount() !== 1) {
            throw new RuntimeException('No se pudo liberar uno de los egresos. La operación fue cancelada.');
        }
    }

    $pdo->prepare('DELETE FROM aprobacion_grupos_egresos WHERE id_almacenamiento = ?')->execute([$idAlmacenamiento]);
    $pdo->prepare('DELETE FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ?')->execute([$idAlmacenamiento]);
    $pdo->prepare('DELETE FROM almacenamientos_egresos WHERE id = ?')->execute([$idAlmacenamiento]);
}
