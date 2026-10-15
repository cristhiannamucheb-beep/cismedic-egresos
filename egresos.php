<?php
session_start();

if(!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
$pdo->exec("CREATE TABLE IF NOT EXISTS comprobantes_egresos (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    fuente_egreso ENUM('personal', 'proveedores') NOT NULL,
    id_egreso INT NOT NULL,
    archivo_guardado VARCHAR(60) NOT NULL,
    nombre_original VARCHAR(255) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    tamano INT UNSIGNED NOT NULL,
    fecha_carga TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_comprobante_egreso (fuente_egreso, id_egreso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$stmt = $pdo->query("SHOW COLUMNS FROM comprobantes_egresos LIKE 'archivo_guardado'");
$columna_archivo = $stmt->fetch();
if ($columna_archivo && preg_match('/^(?:char|varchar)\((\d+)\)$/i', $columna_archivo['Type'], $coincidencia) && (int) $coincidencia[1] < 60) {
    $pdo->exec('ALTER TABLE comprobantes_egresos MODIFY archivo_guardado VARCHAR(60) NOT NULL');
}

function validarComprobanteSubido(array $archivo, bool $obligatorio): ?array
{
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($obligatorio) {
            throw new Exception('Adjunta la imagen o el PDF del comprobante para registrar este egreso.');
        }
        return null;
    }
    if (($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'] ?? '')) {
        throw new Exception('No se pudo recibir el comprobante. Intenta cargarlo nuevamente.');
    }
    if ((int) ($archivo['size'] ?? 0) < 1 || (int) $archivo['size'] > 10 * 1024 * 1024) {
        throw new Exception('El comprobante debe pesar como máximo 10 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($archivo['tmp_name']);
    $extensiones = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extensiones[$mime])) {
        throw new Exception('Formato no permitido. Sube una imagen JPG, PNG o WEBP, o un documento PDF.');
    }

    return [
        'temporal' => $archivo['tmp_name'],
        'mime' => $mime,
        'extension' => $extensiones[$mime],
        'nombre_original' => mb_substr(basename((string) ($archivo['name'] ?? 'comprobante')), 0, 255),
        'tamano' => (int) $archivo['size']
    ];
}

function guardarComprobanteSubido(array $archivo): array
{
    $directorio = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'comprobantes_egresos';
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        throw new Exception('No se pudo preparar el almacenamiento privado de comprobantes.');
    }
    $nombre_guardado = bin2hex(random_bytes(24)) . '.' . $archivo['extension'];
    $ruta = $directorio . DIRECTORY_SEPARATOR . $nombre_guardado;
    if (!move_uploaded_file($archivo['temporal'], $ruta)) {
        throw new Exception('No se pudo guardar el archivo del comprobante.');
    }
    return ['archivo_guardado' => $nombre_guardado, 'ruta' => $ruta];
}

function eliminarArchivoComprobante(?string $nombre): void
{
    if (!$nombre || !preg_match('/^[a-f0-9]{48}\\.(jpg|png|webp|pdf)$/', $nombre)) {
        return;
    }
    $ruta = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'comprobantes_egresos' . DIRECTORY_SEPARATOR . $nombre;
    if (is_file($ruta)) {
        unlink($ruta);
    }
}

if (isset($_GET['ver_comprobante'])) {
    $fuente_archivo = $_GET['fuente'] ?? '';
    $id_archivo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!in_array($fuente_archivo, ['personal', 'proveedores'], true) || !$id_archivo || $id_archivo < 1) {
        http_response_code(400);
        exit('Comprobante no válido.');
    }
    $stmt = $pdo->prepare('SELECT archivo_guardado, nombre_original, mime FROM comprobantes_egresos WHERE fuente_egreso = ? AND id_egreso = ? LIMIT 1');
    $stmt->execute([$fuente_archivo, $id_archivo]);
    $comprobante = $stmt->fetch();
    $tabla_origen = $fuente_archivo === 'personal' ? 'entregas_dinero' : 'entregas_proveedores';
    $columna_id = $fuente_archivo === 'personal' ? 'id_entrega' : 'id_entrega_proveedor';
    $stmt = $pdo->prepare("SELECT $columna_id FROM $tabla_origen WHERE $columna_id = ? LIMIT 1");
    $stmt->execute([$id_archivo]);
    if (!$comprobante || !$stmt->fetch() || !preg_match('/^[a-f0-9]{48}\\.(jpg|png|webp|pdf)$/', $comprobante['archivo_guardado'])) {
        http_response_code(404);
        exit('No se encontró el comprobante solicitado.');
    }
    $ruta_comprobante = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'comprobantes_egresos' . DIRECTORY_SEPARATOR . $comprobante['archivo_guardado'];
    if (!is_file($ruta_comprobante)) {
        http_response_code(404);
        exit('El archivo del comprobante no está disponible.');
    }
    $nombre_descarga = str_replace(["\r", "\n", '"'], '', basename($comprobante['nombre_original']));
    header('Content-Type: ' . $comprobante['mime']);
    header('Content-Disposition: inline; filename="comprobante"; filename*=UTF-8\'\'' . rawurlencode($nombre_descarga));
    header('Content-Length: ' . filesize($ruta_comprobante));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    readfile($ruta_comprobante);
    exit;
}

function prefijoTipoComprobante(string $tipo): string
{
    $tipo = strtoupper(trim($tipo));

    return match ($tipo) {
        'FACTURA' => 'F',
        'RHE' => 'E',
        'BOLETA' => 'B',
        default => '',
    };
}

function formatearComprobante(string $tipo, string $valor): string
{
    $tipo = strtoupper(trim($tipo));
    $valor = strtoupper(trim((string) $valor));
    if (in_array($tipo, ['RECIBO', 'RECIBO DE EGRESO', 'NOTA DE CRÉDITO'], true)) {
        return substr(preg_replace('/[^A-Z0-9]/', '', $valor), 0, 15);
    }
    $valor = preg_replace('/[^A-Z0-9-]/', '', $valor);

    if ($valor === '') {
        return '';
    }

    $partes = explode('-', $valor, 2);
    $serie = substr($partes[0], 0, 4);
    $correlativo = preg_replace('/[^0-9]/', '', $partes[1] ?? '');
    if ($tipo === 'RHE') {
        $serie = 'E001';
    }
    $correlativo = ltrim($correlativo, '0');
    return $serie . '-' . $correlativo;
}

function esComprobanteValido(string $tipo, string $valor): bool
{
    $tipo = strtoupper(trim($tipo));
    $valor = strtoupper(trim((string) $valor));
    $valor = preg_replace('/\s+/', '', $valor);
    if (in_array($tipo, ['RECIBO', 'RECIBO DE EGRESO', 'NOTA DE CRÉDITO'], true)) {
        return (bool) preg_match('/^[A-Z0-9]{1,15}$/', $valor);
    }
    $valor = preg_replace('/[^A-Z0-9-]/', '', $valor);

    $patron = $tipo === 'RHE'
        ? '/^E001-[1-9][0-9]{0,7}$/'
        : '/^[A-Z0-9]{4}-[1-9][0-9]{0,7}$/';

    return (bool) preg_match($patron, $valor);
}

// Procesar acciones
$mensaje = $_SESSION['egresos_mensaje'] ?? '';
unset($_SESSION['egresos_mensaje']);
$tipo_mensaje = $mensaje !== '' ? 'success' : '';
$personal_por_categoria = [];
$proveedores = [];
$categorias = [];
$alerta_duplicidad = $_SESSION['egresos_alerta_duplicidad'] ?? null;
unset($_SESSION['egresos_alerta_duplicidad']);
$usuario_nombre = $_SESSION['usuario_nombre'] ?? 'Usuario no identificado';
$es_caja = ($_SESSION['usuario_rol'] ?? '') === 'caja';

// Registrar una entrega de dinero al personal o a un proveedor
if($_SERVER['REQUEST_METHOD'] == 'POST' && ($_POST['accion'] ?? '') === 'crear_entrega') {
    $ruta_comprobante_nuevo = null;
    try {
        $tipo_entrega = $_POST['tipo_entrega'] ?? '';
        $tipo_comprobante = trim($_POST['tipo_comprobante'] ?? '');
        $numero_comprobante = formatearComprobante($tipo_comprobante, $_POST['numero_comprobante'] ?? '');
        $fecha = $_POST['fecha'] ?? date('Y-m-d');
        $monto = (float) ($_POST['monto'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $id_categoria = (int) ($_POST['id_categoria'] ?? 0);
        $tipos_comprobante_validos = ['FACTURA', 'RECIBO', 'RECIBO DE EGRESO', 'RHE', 'BOLETA', 'NOTA DE CRÉDITO'];
        $fecha_valida = DateTime::createFromFormat('Y-m-d', $fecha);

        if (!in_array($tipo_entrega, ['personal', 'proveedor'], true)) {
            throw new Exception('Selecciona si la entrega corresponde a personal o proveedor.');
        }
        if (!in_array($tipo_comprobante, $tipos_comprobante_validos, true)) {
            throw new Exception('Selecciona un tipo de comprobante.');
        }
        if (!esComprobanteValido($tipo_comprobante, $numero_comprobante)) {
            throw new Exception('Formato inválido. Usa serie de 4 caracteres en mayúsculas, guion y correlativo sin ceros a la izquierda. Ejemplo: FABC-102035.');
        }
        if (!$fecha_valida || $fecha_valida->format('Y-m-d') !== $fecha) {
            throw new Exception('Selecciona una fecha válida.');
        }
        if ($descripcion === '') {
            throw new Exception('Escribe la descripción del egreso para indicar el motivo del gasto.');
        }
        if (mb_strlen($descripcion) > 1000) {
            throw new Exception('La descripción no puede superar los 1000 caracteres.');
        }
        if ($monto <= 0 || $id_categoria <= 0) {
            throw new Exception('Completa el monto y selecciona una categoría.');
        }
        $comprobante_subido = validarComprobanteSubido($_FILES['comprobante'] ?? [], true);

        if ($tipo_entrega === 'personal') {
            $id_personal = (int) ($_POST['id_personal'] ?? 0);
            if ($id_personal <= 0) {
                throw new Exception('Selecciona el personal que recibe el dinero.');
            }
            $stmt = $pdo->prepare('SELECT ed.id_entrega, ed.tipo_comprobante, ed.fecha_entrega, pc.nombre, pc.apellido FROM entregas_dinero ed LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal WHERE ed.id_personal = ? AND ed.tipo_comprobante = ? AND ed.nComprobante = ? LIMIT 1');
            $stmt->execute([$id_personal, $tipo_comprobante, $numero_comprobante]);
            if ($duplicado = $stmt->fetch()) {
                $alerta_duplicidad = ['destinatario' => trim(($duplicado['nombre'] ?? '') . ' ' . ($duplicado['apellido'] ?? '')), 'tipo' => $tipo_comprobante, 'numero' => $numero_comprobante, 'registro' => $duplicado['id_entrega'], 'fecha' => date('d/m/Y', strtotime($duplicado['fecha_entrega']))];
                $_SESSION['egresos_alerta_duplicidad'] = $alerta_duplicidad;
                header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?origen=' . urlencode($_POST['origen'] ?? 'personal'));
                exit();
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO entregas_dinero (id_personal, id_usuario, id_categoria, tipo_comprobante, nComprobante, fecha_entrega, monto, descripcion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id_personal, $_SESSION['usuario_id'], $id_categoria, $tipo_comprobante, $numero_comprobante, $fecha, $monto, $descripcion ?: null]);
            $id_egreso_creado = (int) $pdo->lastInsertId();
            $fuente_egreso_creado = 'personal';
            $mensaje = 'Entrega al personal registrada correctamente.';
        } else {
            $id_proveedor = (int) ($_POST['id_proveedor'] ?? 0);
            if ($id_proveedor <= 0) {
                throw new Exception('Selecciona el proveedor que recibe el pago.');
            }
            $stmt = $pdo->prepare('SELECT ep.id_entrega_proveedor, ep.tipo_comprobante, ep.fecha_entrega, p.nombre AS proveedor FROM entregas_proveedores ep LEFT JOIN proveedores p ON p.id = ep.id_proveedor WHERE ep.id_proveedor = ? AND ep.tipo_comprobante = ? AND ep.nComprobante = ? LIMIT 1');
            $stmt->execute([$id_proveedor, $tipo_comprobante, $numero_comprobante]);
            if ($duplicado = $stmt->fetch()) {
                $alerta_duplicidad = ['destinatario' => $duplicado['proveedor'] ?? 'Proveedor seleccionado', 'tipo' => $tipo_comprobante, 'numero' => $numero_comprobante, 'registro' => $duplicado['id_entrega_proveedor'], 'fecha' => date('d/m/Y', strtotime($duplicado['fecha_entrega']))];
                $_SESSION['egresos_alerta_duplicidad'] = $alerta_duplicidad;
                header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?origen=' . urlencode($_POST['origen'] ?? 'proveedores'));
                exit();
            }
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO entregas_proveedores (id_proveedor, id_usuario, id_categoria, tipo_comprobante, nComprobante, fecha_entrega, monto, descripcion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id_proveedor, $_SESSION['usuario_id'], $id_categoria, $tipo_comprobante, $numero_comprobante, $fecha, $monto, $descripcion ?: null]);
            $id_egreso_creado = (int) $pdo->lastInsertId();
            $fuente_egreso_creado = 'proveedores';
            $mensaje = 'Entrega al proveedor registrada correctamente.';
        }
        $archivo_guardado = guardarComprobanteSubido($comprobante_subido);
        $ruta_comprobante_nuevo = $archivo_guardado['ruta'];
        $stmt = $pdo->prepare('INSERT INTO comprobantes_egresos (fuente_egreso, id_egreso, archivo_guardado, nombre_original, mime, tamano) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$fuente_egreso_creado, $id_egreso_creado, $archivo_guardado['archivo_guardado'], $comprobante_subido['nombre_original'], $comprobante_subido['mime'], $comprobante_subido['tamano']]);
        $pdo->commit();
        $ruta_comprobante_nuevo = null;
        $tipo_mensaje = 'success';
    } catch(Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($ruta_comprobante_nuevo) && is_string($ruta_comprobante_nuevo) && $ruta_comprobante_nuevo !== '' && is_file($ruta_comprobante_nuevo)) {
            unlink($ruta_comprobante_nuevo);
        }
        $mensaje = 'Error al registrar la entrega: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

// Editar número de comprobante de un egreso
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_egreso') {
    if ($es_caja) {
        http_response_code(403);
        exit('Acceso denegado: el rol caja no puede editar egresos.');
    }
    $ruta_comprobante_nuevo = null;
    $archivo_comprobante_anterior = null;
    try {
        $id_egreso = (int) ($_POST['id_egreso'] ?? 0);
        $fuente_egreso = $_POST['fuente_egreso'] ?? '';
        
        if ($id_egreso <= 0 || !in_array($fuente_egreso, ['personal', 'proveedores'], true)) {
            throw new Exception('Registro de egreso no válido.');
        }

        $stmt = null;
        $tipo_comprobante_editar = '';
        $numero_comprobante_nuevo = '';
        
        // Obtener datos del egreso actual para verificar duplicados
        if ($fuente_egreso === 'personal') {
            $stmt = $pdo->prepare('SELECT ed.id_entrega, ed.nComprobante, ed.tipo_comprobante, ed.id_personal, ed.monto, ed.descripcion, ed.estado_aprobacion, ed.fecha_entrega, pc.nombre, pc.apellido FROM entregas_dinero ed LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal WHERE ed.id_entrega = ? LIMIT 1');
            $stmt->execute([$id_egreso]);
            $egreso_actual = $stmt->fetch();
            
            if (!$egreso_actual) {
                throw new Exception('El egreso ya no existe.');
            }

            $tipo_comprobante_editar = strtoupper(trim($egreso_actual['tipo_comprobante'] ?? ''));
            $numero_comprobante_nuevo = formatearComprobante($tipo_comprobante_editar, $_POST['numero_comprobante_nuevo'] ?? '');
            if (!esComprobanteValido($tipo_comprobante_editar, $numero_comprobante_nuevo)) {
                throw new Exception('Formato inválido. Usa serie de 4 caracteres, mayúsculas, guion y correlativo sin ceros a la izquierda. Ejemplo: FABC-102035.');
            }
            
            // Verificar si el nuevo número de comprobante ya existe para este personal y tipo
            if ($egreso_actual['nComprobante'] !== $numero_comprobante_nuevo) {
                $stmt = $pdo->prepare('SELECT ed.id_entrega, ed.fecha_entrega FROM entregas_dinero ed WHERE ed.id_personal = ? AND ed.tipo_comprobante = ? AND ed.nComprobante = ? AND ed.id_entrega != ? LIMIT 1');
                $stmt->execute([$egreso_actual['id_personal'], $egreso_actual['tipo_comprobante'], $numero_comprobante_nuevo, $id_egreso]);
                if ($duplicado = $stmt->fetch()) {
                    $alerta_duplicidad = ['destinatario' => trim(($egreso_actual['nombre'] ?? '') . ' ' . ($egreso_actual['apellido'] ?? '')), 'tipo' => $egreso_actual['tipo_comprobante'], 'numero' => $numero_comprobante_nuevo, 'registro' => $duplicado['id_entrega'], 'fecha' => date('d/m/Y', strtotime($duplicado['fecha_entrega']))];
                    $_SESSION['egresos_alerta_duplicidad'] = $alerta_duplicidad;
                    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?origen=' . urlencode($_POST['origen'] ?? 'personal'));
                    exit();
                }
            }
        } elseif ($fuente_egreso === 'proveedores') {
            $stmt = $pdo->prepare('SELECT ep.id_entrega_proveedor, ep.nComprobante, ep.tipo_comprobante, ep.id_proveedor, ep.monto, ep.descripcion, ep.estado_aprobacion, ep.fecha_entrega, p.nombre AS proveedor FROM entregas_proveedores ep LEFT JOIN proveedores p ON p.id = ep.id_proveedor WHERE ep.id_entrega_proveedor = ? LIMIT 1');
            $stmt->execute([$id_egreso]);
            $egreso_actual = $stmt->fetch();
            
            if (!$egreso_actual) {
                throw new Exception('El egreso ya no existe.');
            }

            $tipo_comprobante_editar = strtoupper(trim($egreso_actual['tipo_comprobante'] ?? ''));
            $numero_comprobante_nuevo = formatearComprobante($tipo_comprobante_editar, $_POST['numero_comprobante_nuevo'] ?? '');
            if (!esComprobanteValido($tipo_comprobante_editar, $numero_comprobante_nuevo)) {
                throw new Exception('Formato inválido. Usa serie de 4 caracteres, mayúsculas, guion y correlativo sin ceros a la izquierda. Ejemplo: FABC-102035.');
            }
            
            // Verificar si el nuevo número de comprobante ya existe para este proveedor y tipo
            if ($egreso_actual['nComprobante'] !== $numero_comprobante_nuevo) {
                $stmt = $pdo->prepare('SELECT ep.id_entrega_proveedor, ep.fecha_entrega FROM entregas_proveedores ep WHERE ep.id_proveedor = ? AND ep.tipo_comprobante = ? AND ep.nComprobante = ? AND ep.id_entrega_proveedor != ? LIMIT 1');
                $stmt->execute([$egreso_actual['id_proveedor'], $egreso_actual['tipo_comprobante'], $numero_comprobante_nuevo, $id_egreso]);
                if ($duplicado = $stmt->fetch()) {
                    $alerta_duplicidad = ['destinatario' => $egreso_actual['proveedor'] ?? 'Proveedor seleccionado', 'tipo' => $egreso_actual['tipo_comprobante'], 'numero' => $numero_comprobante_nuevo, 'registro' => $duplicado['id_entrega_proveedor'], 'fecha' => date('d/m/Y', strtotime($duplicado['fecha_entrega']))];
                    $_SESSION['egresos_alerta_duplicidad'] = $alerta_duplicidad;
                    header('Location: ' . basename($_SERVER['SCRIPT_NAME']) . '?origen=' . urlencode($_POST['origen'] ?? 'proveedores'));
                    exit();
                }
            }
        }
        
        $monto_nuevo = trim((string) ($_POST['monto_nuevo'] ?? ''));
        $descripcion_nueva = trim((string) ($_POST['descripcion_nueva'] ?? ''));
        if (!preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?$/', $monto_nuevo) || (float) $monto_nuevo <= 0) {
            throw new Exception('Ingresa un monto válido mayor que cero, con máximo dos decimales.');
        }
        if (mb_strlen($descripcion_nueva) > 1000) {
            throw new Exception('La descripción no puede superar los 1000 caracteres.');
        }
        $monto_nuevo = number_format((float) $monto_nuevo, 2, '.', '');
        $comprobante_subido = validarComprobanteSubido($_FILES['comprobante'] ?? [], false);

        $pdo->beginTransaction();
        
        if ($fuente_egreso === 'personal') {
            $stmt = $pdo->prepare('UPDATE entregas_dinero SET nComprobante = ?, monto = ?, descripcion = ? WHERE id_entrega = ?');
            $stmt->execute([$numero_comprobante_nuevo, $monto_nuevo, $descripcion_nueva ?: null, $id_egreso]);
        } elseif ($fuente_egreso === 'proveedores') {
            $stmt = $pdo->prepare('UPDATE entregas_proveedores SET nComprobante = ?, monto = ?, descripcion = ? WHERE id_entrega_proveedor = ?');
            $stmt->execute([$numero_comprobante_nuevo, $monto_nuevo, $descripcion_nueva ?: null, $id_egreso]);
        }
        
        if (!$stmt) {
            throw new Exception('El egreso ya no existe o no pudo actualizarse.');
        }
        $stmt = $pdo->prepare('UPDATE detalle_almacenamiento_egresos SET documento = ?, total = ?, descripcion = ? WHERE fuente_egreso = ? AND id_egreso = ?');
        $stmt->execute([$tipo_comprobante_editar . ' ' . $numero_comprobante_nuevo, $monto_nuevo, $descripcion_nueva ?: null, $fuente_egreso, $id_egreso]);
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare('UPDATE almacenamientos_egresos a JOIN (SELECT id_almacenamiento, SUM(total) AS total_actualizado FROM detalle_almacenamiento_egresos GROUP BY id_almacenamiento) d ON d.id_almacenamiento = a.id SET a.total = d.total_actualizado WHERE a.id IN (SELECT id_almacenamiento FROM detalle_almacenamiento_egresos WHERE fuente_egreso = ? AND id_egreso = ?)');
            $stmt->execute([$fuente_egreso, $id_egreso]);
        }
        if ($comprobante_subido) {
            $stmt = $pdo->prepare('SELECT archivo_guardado FROM comprobantes_egresos WHERE fuente_egreso = ? AND id_egreso = ? LIMIT 1');
            $stmt->execute([$fuente_egreso, $id_egreso]);
            $archivo_comprobante_anterior = $stmt->fetchColumn() ?: null;
            $archivo_guardado = guardarComprobanteSubido($comprobante_subido);
            $ruta_comprobante_nuevo = $archivo_guardado['ruta'];
            $stmt = $pdo->prepare('INSERT INTO comprobantes_egresos (fuente_egreso, id_egreso, archivo_guardado, nombre_original, mime, tamano) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE archivo_guardado = VALUES(archivo_guardado), nombre_original = VALUES(nombre_original), mime = VALUES(mime), tamano = VALUES(tamano), fecha_carga = CURRENT_TIMESTAMP');
            $stmt->execute([$fuente_egreso, $id_egreso, $archivo_guardado['archivo_guardado'], $comprobante_subido['nombre_original'], $comprobante_subido['mime'], $comprobante_subido['tamano']]);
        }
        $pdo->commit();
        if ($ruta_comprobante_nuevo) {
            eliminarArchivoComprobante($archivo_comprobante_anterior);
            $ruta_comprobante_nuevo = null;
        }
        $_SESSION['egresos_mensaje'] = 'Comprobante, monto y descripción actualizados correctamente.';
        $query = http_build_query(array_filter(['origen' => $_POST['origen'] ?? 'todos', 'mes' => $_POST['mes'] ?? null, 'ano' => $_POST['ano'] ?? null, 'comprobante' => $_POST['comprobante'] ?? null], static fn($valor) => $valor !== null && $valor !== ''));
        header('Location: egresos.php' . ($query ? '?' . $query : ''));
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($ruta_comprobante_nuevo && is_file($ruta_comprobante_nuevo)) {
            unlink($ruta_comprobante_nuevo);
        }
        $mensaje = 'No se pudo editar el egreso: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_egreso') {
    if ($es_caja) {
        http_response_code(403);
        exit('Acceso denegado: el rol caja no puede eliminar egresos.');
    }
    $archivo_comprobante_eliminado = null;
    try {
        $id_egreso = (int) ($_POST['id_egreso'] ?? 0);
        $fuente_egreso = $_POST['fuente_egreso'] ?? '';
        if ($id_egreso <= 0 || !in_array($fuente_egreso, ['personal', 'proveedores'], true)) {
            throw new Exception('Registro de egreso no válido.');
        }
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT archivo_guardado FROM comprobantes_egresos WHERE fuente_egreso = ? AND id_egreso = ? LIMIT 1');
        $stmt->execute([$fuente_egreso, $id_egreso]);
        $archivo_comprobante_eliminado = $stmt->fetchColumn() ?: null;
        if ($fuente_egreso === 'personal') {
            $stmt = $pdo->prepare('DELETE FROM entregas_dinero WHERE id_entrega = ?');
        } else {
            $stmt = $pdo->prepare('DELETE FROM entregas_proveedores WHERE id_entrega_proveedor = ?');
        }
        $stmt->execute([$id_egreso]);
        if ($stmt->rowCount() !== 1) {
            throw new Exception('El egreso ya no existe o no pudo eliminarse.');
        }
        $stmt = $pdo->prepare('DELETE FROM comprobantes_egresos WHERE fuente_egreso = ? AND id_egreso = ?');
        $stmt->execute([$fuente_egreso, $id_egreso]);
        $pdo->commit();
        eliminarArchivoComprobante($archivo_comprobante_eliminado);
        $_SESSION['egresos_mensaje'] = 'Egreso eliminado correctamente. Los totales se actualizaron.';
        $query = http_build_query(array_filter(['origen' => $_POST['origen'] ?? 'todos', 'mes' => $_POST['mes'] ?? null, 'ano' => $_POST['ano'] ?? null, 'comprobante' => $_POST['comprobante'] ?? null], static fn($valor) => $valor !== null && $valor !== ''));
        header('Location: egresos.php' . ($query ? '?' . $query : ''));
        exit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $mensaje = 'No se pudo eliminar el egreso: ' . $e->getMessage();
        $tipo_mensaje = 'danger';
    }
}

/* Compatibilidad temporal con el formulario anterior. */
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'crear') {
    try {
        $mensaje = "Este formulario fue actualizado. Registra la entrega como personal o proveedor.";
        $tipo_mensaje = "success";
    } catch(Exception $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_mensaje = "danger";
    }
}

// Obtener mes y año del filtro
$mes_filtro = $_GET['mes'] ?? date('m');
$ano_filtro = $_GET['ano'] ?? date('Y');
$comprobante_filtro = trim($_GET['comprobante'] ?? '');
$comprobante_busqueda = '%' . $comprobante_filtro . '%';
$ignorar_fecha = $comprobante_filtro !== '' ? 1 : 0;
$comprobante_normalizado = '%' . str_replace([' ', '-'], '', strtolower($comprobante_filtro)) . '%';
$origen_filtro = $_GET['origen'] ?? 'todos';
if (!in_array($origen_filtro, ['todos', 'personal', 'proveedores'], true)) {
    $origen_filtro = 'todos';
}
$vista_personal = $origen_filtro === 'personal';
$vista_proveedores = $origen_filtro === 'proveedores';
$es_caja = ($_SESSION['usuario_rol'] ?? '') === 'caja';

if (($_GET['realtime'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    try {
        $firma_partes = [];
        $filtro_fecha = $ignorar_fecha === 1 ? '1 = 1' : 'MONTH(%s) = ? AND YEAR(%s) = ?';
        if ($origen_filtro === 'todos' || $origen_filtro === 'personal') {
            $sql = 'SELECT GROUP_CONCAT(CONCAT(ed.id_entrega, ":", ed.estado_aprobacion) ORDER BY ed.id_entrega SEPARATOR "|") FROM entregas_dinero ed WHERE ' . sprintf($filtro_fecha, 'ed.fecha_entrega', 'ed.fecha_entrega') . ' AND (? = "" OR ed.nComprobante LIKE ?)';
            $stmt = $pdo->prepare($sql);
            $params = $ignorar_fecha === 1 ? [$comprobante_filtro, $comprobante_busqueda] : [$mes_filtro, $ano_filtro, $comprobante_filtro, $comprobante_busqueda];
            $stmt->execute($params);
            $firma_partes[] = 'p:' . ($stmt->fetchColumn() ?: '');
        }
        if ($origen_filtro === 'todos' || $origen_filtro === 'proveedores') {
            $sql = 'SELECT GROUP_CONCAT(CONCAT(ep.id_entrega_proveedor, ":", ep.estado_aprobacion) ORDER BY ep.id_entrega_proveedor SEPARATOR "|") FROM entregas_proveedores ep WHERE ' . sprintf($filtro_fecha, 'ep.fecha_entrega', 'ep.fecha_entrega') . ' AND (? = "" OR ep.nComprobante LIKE ?)';
            $stmt = $pdo->prepare($sql);
            $params = $ignorar_fecha === 1 ? [$comprobante_filtro, $comprobante_busqueda] : [$mes_filtro, $ano_filtro, $comprobante_filtro, $comprobante_busqueda];
            $stmt->execute($params);
            $firma_partes[] = 'v:' . ($stmt->fetchColumn() ?: '');
        }
        echo json_encode(['ok' => true, 'firma' => sha1(implode(';', $firma_partes))]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['ok' => false]);
    }
    exit;
}

try {
    // Catálogos para registrar entregas: personal agrupado por función y proveedores globales.
    $stmt = $pdo->query("SELECT pc.id_personal, pc.nombre, pc.apellido, pc.cargo, ce.nombre AS categoria_nombre FROM personal_clinica pc LEFT JOIN categorias_empleado ce ON pc.id_categoria = ce.id_categoria WHERE pc.activo = 1 ORDER BY ce.nombre, pc.apellido, pc.nombre");
    foreach ($stmt->fetchAll() as $persona) {
        $categoria = $persona['categoria_nombre'] ?: 'Sin categoría definida';
        $personal_por_categoria[$categoria][] = $persona;
    }

    $stmt = $pdo->query('SELECT id, nombre, ruc FROM proveedores WHERE activo = 1 ORDER BY nombre');
    $proveedores = $stmt->fetchAll();
    $categorias = $pdo->query('SELECT id, nombre FROM categorias ORDER BY nombre')->fetchAll();

    // Combina entregas a personal y pagos a proveedores.
    $query = "
        -- ENTREGAS A PERSONAL
        SELECT 
            ed.id_entrega as id,
            ed.fecha_entrega as fecha,
            ed.monto as total,
            ed.descripcion AS motivo_entrega,
            'ENTREGAS A PERSONAL' as tipo_egreso,
            CONCAT(pc.apellido, ', ', pc.nombre) as proveedor,
            u.nombre as usuario,
            ed.id_categoria,
            ed.estado_aprobacion,
            ed.tipo_comprobante,
            ed.nComprobante as numero_comprobante,
            ed.id_personal,
            NULL as id_proveedor,
            ce.mime AS comprobante_mime,
            ce.nombre_original AS comprobante_nombre
        FROM entregas_dinero ed
        LEFT JOIN personal_clinica pc ON ed.id_personal = pc.id_personal
        LEFT JOIN usuarios u ON ed.id_usuario = u.id
        LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso = 'personal' AND ce.id_egreso = ed.id_entrega
                WHERE (? = 1 OR (MONTH(ed.fecha_entrega) = ? AND YEAR(ed.fecha_entrega) = ?))
                    AND (? IN ('todos', 'personal'))
                    AND (? = '' OR ed.nComprobante LIKE ? OR REPLACE(REPLACE(LOWER(ed.nComprobante), ' ', ''), '-', '') LIKE ?)
        
        UNION ALL
        
        -- ENTREGAS A PROVEEDORES (COMPRAS)
        SELECT 
            ep.id_entrega_proveedor as id,
            ep.fecha_entrega as fecha,
            ep.monto as total,
            ep.descripcion AS motivo_entrega,
            'COMPRA A PROVEEDOR' as tipo_egreso,
            prov.nombre as proveedor,
            u.nombre as usuario,
            ep.id_categoria,
            ep.estado_aprobacion,
            ep.tipo_comprobante,
            ep.nComprobante as numero_comprobante,
            NULL as id_personal,
            ep.id_proveedor,
            ce.mime AS comprobante_mime,
            ce.nombre_original AS comprobante_nombre
        FROM entregas_proveedores ep
        LEFT JOIN proveedores prov ON ep.id_proveedor = prov.id
        LEFT JOIN usuarios u ON ep.id_usuario = u.id
        LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso = 'proveedores' AND ce.id_egreso = ep.id_entrega_proveedor
                WHERE (? = 1 OR (MONTH(ep.fecha_entrega) = ? AND YEAR(ep.fecha_entrega) = ?))
                    AND (? IN ('todos', 'proveedores'))
                    AND (? = '' OR ep.nComprobante LIKE ? OR REPLACE(REPLACE(LOWER(ep.nComprobante), ' ', ''), '-', '') LIKE ?)
        
        ORDER BY fecha DESC, id DESC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $ignorar_fecha, $mes_filtro, $ano_filtro, $origen_filtro, $comprobante_filtro, $comprobante_busqueda, $comprobante_normalizado,
        $ignorar_fecha, $mes_filtro, $ano_filtro, $origen_filtro, $comprobante_filtro, $comprobante_busqueda, $comprobante_normalizado
    ]);
    $egresos = $stmt->fetchAll();
    
    // Contar entregas del mes según el origen seleccionado.
    $query_resumen = "
        SELECT 
            COUNT(*) as total,
            COALESCE(SUM(monto), 0) as suma_total,
            COALESCE(SUM(igv), 0) as suma_igv
        FROM (
            SELECT ed.monto, 0 as igv FROM entregas_dinero ed 
                        WHERE (? = 1 OR (MONTH(ed.fecha_entrega) = ? AND YEAR(ed.fecha_entrega) = ?))
                            AND (? IN ('todos', 'personal'))
                              AND (? = '' OR ed.nComprobante LIKE ? OR REPLACE(REPLACE(LOWER(ed.nComprobante), ' ', ''), '-', '') LIKE ?)
            
            UNION ALL
            
            SELECT ep.monto, 0 as igv FROM entregas_proveedores ep 
                        WHERE (? = 1 OR (MONTH(ep.fecha_entrega) = ? AND YEAR(ep.fecha_entrega) = ?))
                            AND (? IN ('todos', 'proveedores'))
                              AND (? = '' OR ep.nComprobante LIKE ? OR REPLACE(REPLACE(LOWER(ep.nComprobante), ' ', ''), '-', '') LIKE ?)
            
        ) as egresos_totales
    ";
    
    $stmt = $pdo->prepare($query_resumen);
    $stmt->execute([
        $ignorar_fecha, $mes_filtro, $ano_filtro, $origen_filtro, $comprobante_filtro, $comprobante_busqueda, $comprobante_normalizado,
        $ignorar_fecha, $mes_filtro, $ano_filtro, $origen_filtro, $comprobante_filtro, $comprobante_busqueda, $comprobante_normalizado
    ]);
    $resumen = $stmt->fetch();
    
    // Obtener años disponibles de todas las fuentes
    $stmt = $pdo->query("
        SELECT DISTINCT YEAR(fecha_entrega) as ano FROM entregas_dinero
        UNION
        SELECT DISTINCT YEAR(fecha_entrega) FROM entregas_proveedores
        ORDER BY ano DESC
    ");
    $anos_disponibles = $stmt->fetchAll();

    // Sugerencias de comprobantes para el buscador inteligente
    $stmt = $pdo->query(" 
        SELECT tipo_comprobante, nComprobante as numero_comprobante FROM entregas_dinero
        UNION
        SELECT tipo_comprobante, nComprobante FROM entregas_proveedores
        ORDER BY numero_comprobante
    ");
    $comprobantes_disponibles = $stmt->fetchAll();
    
} catch(Exception $e) {
    $mensaje = "Error al cargar datos: " . $e->getMessage();
    $tipo_mensaje = "danger";
    $egresos = [];
    $resumen = ['total' => 0, 'suma_total' => 0, 'suma_igv' => 0];
    $anos_disponibles = [];
    $comprobantes_disponibles = [];
    $proveedores = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="storage/iconos/LOGO.png">
    <title>Egresos - Sistema de Control</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .modal-confirmacion .modal-content { border-radius: 14px; overflow: hidden; }
        .modal-confirmacion .modal-header { border: 0; padding: 24px 28px 18px; }
        .modal-confirmacion .modal-body { padding: 10px 28px 24px; }
        .modal-confirmacion .modal-footer { border: 0; padding: 16px 28px 24px; }
        .confirmacion-icono { align-items: center; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.35); border-radius: 50%; display: flex; height: 54px; justify-content: center; width: 54px; }
        .confirmacion-icono i { font-size: 1.7rem; }
        .confirmacion-datos { background: #f8f9fa; border-left: 4px solid #dc3545; border-radius: 8px; padding: 14px 16px; }
        .confirmacion-advertencia { align-items: flex-start; display: flex; gap: 8px; }
        .confirmacion-advertencia i { margin-top: 2px; }
        .alerta-duplicidad { border: 1px solid #f1aeb5; border-left: 6px solid #b02a37; box-shadow: 0 8px 24px rgba(176, 42, 55, .14); position: sticky; top: 1rem; z-index: 20; }
        .alerta-duplicidad .alerta-icono { align-items: center; background: #b02a37; border-radius: 50%; color: #fff; display: flex; flex: 0 0 42px; height: 42px; justify-content: center; width: 42px; }
        .alerta-duplicidad .alerta-dato { background: rgba(255,255,255,.72); border: 1px solid #f1aeb5; border-radius: 6px; padding: .55rem .75rem; }
        .egresos-panel { border: 1px solid #d4e3eb; border-radius: 16px; box-shadow: 0 14px 34px rgba(31, 50, 65, .1); overflow: hidden; }
        .egresos-panel .card-header { align-items: center; background: linear-gradient(110deg, #073b4c 0%, #087f5b 100%); border-bottom: 0; color: #fff; display: flex; justify-content: space-between; padding: 20px 24px; }
        .egresos-panel .card-header h5 { color: #fff; font-size: 1.05rem; font-weight: 800; letter-spacing: .01em; }
        .egresos-panel .card-header h5 i { color: #8ee6c2; margin-right: 8px; }
        .egresos-panel .card-header > .text-muted { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); border-radius: 999px; color: #e9fff6 !important; font-weight: 700; padding: .45rem .75rem; }
        .egresos-table { min-width: 1120px; }
        .egresos-table thead th { background: #f7f9fb; border-bottom: 1px solid #dfe7ed; color: #687585; font-size: .68rem; font-weight: 800; letter-spacing: .06em; padding: 13px 14px; text-transform: uppercase; white-space: nowrap; }
        .egresos-table tbody td { border-color: #edf1f4; color: #344054; font-size: .82rem; padding: 16px 14px; vertical-align: middle; }
        .egresos-table tbody tr { transition: background-color .18s ease; }
        .egresos-table tbody tr { border-left: 4px solid transparent; }
        .egresos-table tbody tr:hover { background: #effaf6; border-left-color: #20a477; }
        .egresos-table .fecha-egreso { color: #667085; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .egresos-table .comprobante-egreso { color: #1d2939; font-family: "Courier New", monospace; font-size: .78rem; font-weight: 700; white-space: nowrap; }
        .egresos-table .beneficiario-egreso { color: #1d2939; font-weight: 650; min-width: 210px; }
        .egresos-table .motivo-egreso { color: #667085; max-width: 220px; }
        .egresos-table .total-egreso { color: #05603a; font-size: .95rem; font-weight: 900; text-align: right; white-space: nowrap; }
        .egresos-table .tipo-egreso { background: #d8f5ff; border: 1px solid #8ddfee; border-radius: 999px; color: #05647d; display: inline-block; font-size: .64rem; font-weight: 900; letter-spacing: .02em; padding: .48rem .68rem; white-space: nowrap; }
        .egresos-table .estado-egreso { border-radius: 999px; font-size: .66rem; font-weight: 900; padding: .48rem .68rem; white-space: nowrap; }
        .egresos-table .estado-egreso.bg-warning { background: #fff4cc !important; color: #8a6100; }
        .egresos-table .estado-egreso.bg-danger { background: #fde8e8 !important; color: #b42318; }
        .egresos-table .estado-egreso.bg-success { background: #e5f6ed !important; color: #087f5b; }
        .egresos-table .acciones-egreso { min-width: 92px; text-align: right; white-space: nowrap; }
        .egresos-table .acciones-egreso .btn { align-items: center; border-radius: 7px; display: inline-flex; height: 34px; justify-content: center; margin-left: 4px; width: 34px; }
        .egresos-table .usuario-egreso { color: #667085; font-size: .75rem; white-space: nowrap; }
        @media (max-width: 1200px) { .egresos-panel .table-responsive { overflow-x: auto; } }
    </style>
</head>
<body>
    <div class="wrapper">
        <!-- Sidebar -->
        <aside class="sidebar bg-dark text-white">
            <div class="sidebar-header">
                <h3><i class="bi bi-hospital"></i> CISMEDIC</h3>
                <p>Control de Egresos</p>
            </div>
            
            <?php $nav_egresos_abierto = true; ?>
            <nav class="navbar-nav mt-3">
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="dashboard.php" class="nav-link">
                    <i class="bi bi-speedometer2"></i> Dashboard
                </a><?php endif; ?>
                <div class="nav-section">
                    <a href="#" class="nav-link nav-trigger <?= $nav_egresos_abierto ? 'active' : '' ?>" data-submenu-trigger="egresos" aria-expanded="<?= $nav_egresos_abierto ? 'true' : 'false' ?>">
                        <i class="bi bi-receipt"></i> Egresos
                    </a>
                    <div class="nav-submenu <?= $nav_egresos_abierto ? 'open' : '' ?>">
                        <a href="entregas_personal.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_personal.php') ? 'active' : '' ?>">
                            <i class="bi bi-person-vcard"></i> Entregas a Personal
                        </a>
                        <a href="entregas_proveedores.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_proveedores.php') ? 'active' : '' ?>">
                            <i class="bi bi-shop"></i> Entregas a Proveedores
                        </a>
                    </div>
                </div>
                <a href="almacenamiento_egresos.php" class="nav-link">
                    <i class="bi bi-inboxes"></i> Preaprobación
                </a>
                <a href="aprobacion.php" class="nav-link">
                    <i class="bi bi-check2-circle"></i> Aprobación
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?>
                <a href="cheques_aprobados.php" class="nav-link">
                    <i class="bi bi-bank"></i> Cheques Aprobados
                </a>
                <?php endif; ?>
                <a href="personal.php" class="nav-link">
                    <i class="bi bi-people"></i> Personal
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="facturas.php" class="nav-link">
                    <i class="bi bi-file-earmark-text"></i> Transferencias
                </a><?php endif; ?>
                <a href="proveedores.php" class="nav-link">
                    <i class="bi bi-shop"></i> Proveedores
                </a>
                <a href="categorias.php" class="nav-link">
                    <i class="bi bi-tags"></i> Categorías
                </a>
                <?php if (($_SESSION['usuario_rol'] ?? '') !== 'caja'): ?><a href="reportes.php" class="nav-link">
                    <i class="bi bi-bar-chart"></i> Reportes
                </a><?php endif; ?>
            </nav>
            
            <div class="sidebar-footer">
                <a href="perfil.php" class="nav-link">
                    <i class="bi bi-person"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?>
                </a>
                <a href="logout.php" class="nav-link text-danger">
                    <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                </a>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="topbar">
                <div class="topbar-brand">
                    <button class="btn-sidebar" id="sidebarToggle">
                        <i class="bi bi-list"></i>
                    </button>
                    <span><?= $origen_filtro === 'personal' ? 'Entregas a Personal' : ($origen_filtro === 'proveedores' ? 'Entregas a Proveedores' : 'Gestión de Egresos') ?></span>
                </div>
            </div>
            
            <div class="container-fluid p-4">
                <?php if($alerta_duplicidad): ?>
                <div class="alerta-duplicidad d-flex gap-4 align-items-start" role="alert" style="background: linear-gradient(135deg, #fff5f5 0%, #ffe8eb 100%); border: 2px solid #b02a37; border-left: 8px solid #b02a37; border-radius: 12px; padding: 24px; position: sticky; top: 1rem; z-index: 20; box-shadow: 0 12px 40px rgba(176, 42, 55, .25); margin-bottom: 24px;">
                    <div class="alerta-icono" style="align-items: center; background: linear-gradient(135deg, #b02a37, #8a1e2a); border-radius: 50%; color: #fff; display: flex; flex: 0 0 56px; height: 56px; justify-content: center; width: 56px; flex-shrink: 0;"><i class="bi bi-exclamation-circle-fill" style="font-size: 1.8rem;"></i></div>
                    <div class="flex-grow-1">
                        <h4 class="mb-2" style="color: #b02a37; font-weight: 700; font-size: 1.1rem;">⚠ Comprobante duplicado</h4>
                        <p class="mb-4" style="color: #555; font-size: 0.95rem; line-height: 1.5;">No se registró el egreso porque esta combinación ya está asociada al mismo destinatario. Este sistema previene registros duplicados para mantener la integridad de tus datos.</p>
                        <div class="row g-3" style="margin-bottom: 16px;">
                            <div class="col-md-3">
                                <div style="background: rgba(255,255,255,.9); border: 1px solid #e0a7b2; border-radius: 8px; padding: 12px 14px; box-shadow: inset 0 1px 3px rgba(0,0,0,.04);">
                                    <small style="color: #999; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px;">Destinatario</small>
                                    <div style="color: #333; font-weight: 700; margin-top: 6px; font-size: 0.95rem;"><?= htmlspecialchars($alerta_duplicidad['destinatario']) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div style="background: rgba(255,255,255,.9); border: 1px solid #e0a7b2; border-radius: 8px; padding: 12px 14px; box-shadow: inset 0 1px 3px rgba(0,0,0,.04);">
                                    <small style="color: #999; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px;">Tipo de comprobante</small>
                                    <div style="color: #333; font-weight: 700; margin-top: 6px; font-size: 0.95rem;"><?= htmlspecialchars($alerta_duplicidad['tipo']) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div style="background: rgba(255,255,255,.9); border: 1px solid #e0a7b2; border-radius: 8px; padding: 12px 14px; box-shadow: inset 0 1px 3px rgba(0,0,0,.04);">
                                    <small style="color: #999; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px;">N° de comprobante</small>
                                    <div style="color: #b02a37; font-weight: 800; margin-top: 6px; font-size: 1rem; font-family: 'Courier New', monospace;"><?= htmlspecialchars($alerta_duplicidad['numero']) ?></div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div style="background: rgba(255,255,255,.9); border: 1px solid #e0a7b2; border-radius: 8px; padding: 12px 14px; box-shadow: inset 0 1px 3px rgba(0,0,0,.04);">
                                    <small style="color: #999; font-weight: 600; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.5px;">Registro existente</small>
                                    <div style="color: #333; font-weight: 700; margin-top: 6px; font-size: 0.95rem;">#<?= (int) $alerta_duplicidad['registro'] ?></div>
                                    <div style="color: #777; font-size: 0.8rem; margin-top: 2px;"><?= htmlspecialchars($alerta_duplicidad['fecha']) ?></div>
                                </div>
                            </div>
                        </div>
                        <div style="background: rgba(176, 42, 55, .08); border-left: 3px solid #b02a37; padding: 10px 12px; border-radius: 6px;">
                            <small style="color: #555;"><i class="bi bi-lightbulb-fill" style="color: #b02a37; margin-right: 6px;"></i>💡 <strong>Sugerencia:</strong> Puede repetirse el número si cambias el tipo de comprobante o el destinatario.</small>
                        </div>
                    </div>
                    <button type="button" class="btn-close" aria-label="Cerrar alerta" title="Cerrar alerta" onclick="this.closest('.alerta-duplicidad').remove();" style="position: absolute; right: 20px; top: 20px; width: 32px; height: 32px; background: rgba(255,255,255, 0.7); border: none; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1.3rem; color: #b02a37; transition: all 0.2s ease;" onmouseover="this.style.background='rgba(176, 42, 55, 0.1)'" onmouseout="this.style.background='rgba(255,255,255, 0.7)'">×</button>
                </div>
                <?php endif; ?>
                <?php if($mensaje): ?>
                <div class="alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($mensaje) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Filtro por Mes y Año -->
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h6 class="mb-0">
                            <i class="bi bi-funnel"></i> Filtrar egresos
                        </h6>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                                     <input type="hidden" name="origen" value="<?= htmlspecialchars($origen_filtro) ?>">
                            <div class="col-md-3">
                                <label for="mes" class="form-label">Mes</label>
                                <select class="form-select" id="mes" name="mes" onchange="this.form.submit()">
                                    <?php
                                    $meses = [
                                        '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo',
                                        '04' => 'Abril', '05' => 'Mayo', '06' => 'Junio',
                                        '07' => 'Julio', '08' => 'Agosto', '09' => 'Septiembre',
                                        '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre'
                                    ];
                                    foreach($meses as $num => $nombre):
                                    ?>
                                    <option value="<?= $num ?>" <?= $mes_filtro == $num ? 'selected' : '' ?>>
                                        <?= $nombre ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="ano" class="form-label">Año</label>
                                <select class="form-select" id="ano" name="ano" onchange="this.form.submit()">
                                    <?php foreach($anos_disponibles as $a): ?>
                                    <option value="<?= $a['ano'] ?>" <?= $ano_filtro == $a['ano'] ? 'selected' : '' ?>>
                                        <?= $a['ano'] ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-4">
                                <label for="comprobante" class="form-label">Comprobante</label>
                                <input type="search" class="form-control" id="comprobante" name="comprobante"
                                    value="<?= htmlspecialchars($comprobante_filtro) ?>"
                                    list="comprobantesDisponibles" placeholder="Ej. E001-2754" autocomplete="off">
                                <datalist id="comprobantesDisponibles">
                                    <?php foreach($comprobantes_disponibles as $comprobante): ?>
                                    <option value="<?= htmlspecialchars($comprobante['numero_comprobante']) ?>">
                                        <?= htmlspecialchars($comprobante['tipo_comprobante']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </datalist>
                                <small class="text-muted">Escribe parte o el número completo</small>
                                <?php if($comprobante_filtro !== ''): ?>
                                <small class="d-block text-success"><i class="bi bi-globe2"></i> Búsqueda en todo el histórico</small>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-2">
                                <label for="btnFiltro" class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-outline-primary w-100" id="btnFiltro">
                                    <i class="bi bi-search"></i> Filtrar
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Resumen del Mes -->
                <div class="row mb-4">
                    <div class="col-md-4">
                        <div class="card border-left-primary">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">Total de Egresos</h6>
                                <h4><?= $resumen['total'] ?> registros</h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-left-success">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">Monto Total</h6>
                                <h4>S/. <?= number_format($resumen['suma_total'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card border-left-info">
                            <div class="card-body">
                                <h6 class="text-muted mb-2">IGV Total</h6>
                                <h4>S/. <?= number_format($resumen['suma_igv'], 2) ?></h4>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Botón Nuevo Egreso -->
                <div class="mb-4">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalEgreso">
                        <i class="bi bi-plus-circle"></i> Nuevo Egreso
                    </button>
                </div>
                
                <!-- Tabla de Egresos -->
                <div class="card egresos-panel">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-list-check"></i>
                            <?php if($comprobante_filtro !== ''): ?>
                                Resultados para comprobante: <?= htmlspecialchars($comprobante_filtro) ?>
                            <?php else: ?>
                                Egresos de <?= $meses[$mes_filtro] ?> / <?= $ano_filtro ?>
                            <?php endif; ?>
                        </h5>
                        <span class="text-muted small"><?= count($egresos) ?> registros visibles</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 egresos-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Fecha</th>
                                    <th>Tipo de Egreso</th>
                                    <th>Comprobante</th>
                                    <th>Concepto/Beneficiario</th>
                                    <th>Motivo / Descripción de la entrega</th>
                                    <th>Total</th>
                                    <th>Estado</th>
                                    <th>Usuario</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(empty($egresos)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">
                                        <i class="bi bi-inbox" style="font-size: 2rem;"></i><br>
                                        No hay egresos registrados para este mes
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach($egresos as $egreso): 
                                        $badge_estado = 'bg-secondary';
                                        $modal_id = 'modalEgreso' . md5($egreso['tipo_egreso'] . '-' . $egreso['id']);
                                        if($egreso['estado_aprobacion'] == 'aprobado') $badge_estado = 'bg-success';
                                        elseif($egreso['estado_aprobacion'] == 'en_aprobacion') $badge_estado = 'bg-warning';
                                        elseif($egreso['estado_aprobacion'] == 'pendiente') $badge_estado = 'bg-danger';
                                    ?>
                                    <tr>
                                        <td class="fecha-egreso"><?= date('d/m/Y', strtotime($egreso['fecha'])) ?></td>
                                        <td>
                                            <small class="tipo-egreso"><?= htmlspecialchars($egreso['tipo_egreso']) ?></small>
                                        </td>
                                        <td class="comprobante-egreso"><?= htmlspecialchars($egreso['tipo_comprobante'] . ' ' . $egreso['numero_comprobante']) ?></td>
                                        <td class="beneficiario-egreso"><?= htmlspecialchars($egreso['proveedor'] ?? 'N/A') ?></td>
                                        <td class="motivo-egreso">
                                            <?php if(!empty($egreso['motivo_entrega'])): ?>
                                                <span class="text-truncate d-inline-block" style="max-width: 220px;" title="<?= htmlspecialchars($egreso['motivo_entrega']) ?>">
                                                    <?= htmlspecialchars($egreso['motivo_entrega']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted">Sin motivo registrado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="total-egreso">S/. <?= number_format($egreso['total'], 2) ?></td>
                                        <td>
                                            <small class="badge <?= $badge_estado ?> estado-egreso">
                                                <?= ucfirst(str_replace('_', ' ', $egreso['estado_aprobacion'])) ?>
                                            </small>
                                        </td>
                                        <td class="usuario-egreso"><small><?= htmlspecialchars($egreso['usuario'] ?? '') ?></small></td>
                                        <td class="acciones-egreso">
                                            <button type="button" class="btn btn-sm btn-outline-primary" title="Ver entrega" data-bs-toggle="modal" data-bs-target="#<?= $modal_id ?>">
                                                <i class="bi bi-eye"></i>
                                            </button>
                                            <?php if(!$es_caja && in_array($egreso['tipo_egreso'], ['ENTREGAS A PERSONAL', 'COMPRA A PROVEEDOR'])): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-warning" title="Editar egreso" onclick="abrirEdicionComprobante('<?= htmlspecialchars($egreso['numero_comprobante'], ENT_QUOTES, 'UTF-8') ?>', <?= (int) $egreso['id'] ?>, '<?= $origen_filtro === 'personal' ? 'personal' : ($origen_filtro === 'proveedores' ? 'proveedores' : ($egreso['id_personal'] ? 'personal' : 'proveedores')) ?>', '<?= htmlspecialchars($egreso['tipo_comprobante'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($egreso['numero_comprobante'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($egreso['tipo_comprobante'], ENT_QUOTES, 'UTF-8') ?>', '<?= number_format((float) $egreso['total'], 2, '.', '') ?>', '<?= htmlspecialchars($egreso['motivo_entrega'] ?? '', ENT_QUOTES, 'UTF-8') ?>', <?= !empty($egreso['comprobante_mime']) ? 'true' : 'false' ?>)">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <?php endif; ?>
                                            <?php if(!$es_caja): ?>
                                            <a href="#" class="btn btn-sm btn-outline-danger" title="Eliminar" onclick="return abrirConfirmacionEliminacion('<?= htmlspecialchars($egreso['tipo_egreso'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($egreso['tipo_comprobante'] . ' ' . $egreso['numero_comprobante'], ENT_QUOTES, 'UTF-8') ?>', <?= (int) $egreso['id'] ?>, '<?= $egreso['id_personal'] ? 'personal' : 'proveedores' ?>');">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php foreach($egresos as $egreso):
                    $modal_id = 'modalEgreso' . md5($egreso['tipo_egreso'] . '-' . $egreso['id']);
                    $badge_estado = 'bg-secondary';
                    if($egreso['estado_aprobacion'] == 'aprobado') $badge_estado = 'bg-success';
                    elseif($egreso['estado_aprobacion'] == 'en_aprobacion') $badge_estado = 'bg-warning';
                    elseif($egreso['estado_aprobacion'] == 'pendiente') $badge_estado = 'bg-danger';
                ?>
                <div class="modal fade" id="<?= $modal_id ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-info text-white">
                                <div>
                                    <h5 class="modal-title"><i class="bi bi-eye"></i> Vista previa de la entrega</h5>
                                    <small><?= htmlspecialchars($egreso['tipo_egreso']) ?></small>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row g-3">
                                    <div class="col-md-4"><strong>Fecha</strong><br><?= date('d/m/Y', strtotime($egreso['fecha'])) ?></div>
                                    <div class="col-md-4"><strong>Comprobante</strong><br><?= htmlspecialchars($egreso['tipo_comprobante'] . ' ' . $egreso['numero_comprobante']) ?></div>
                                    <div class="col-md-4"><strong>Registrado por</strong><br><?= htmlspecialchars($egreso['usuario'] ?? 'No identificado') ?></div>
                                    <div class="col-md-6"><strong><?= !empty($egreso['id_personal']) ? 'Personal' : 'Proveedor' ?></strong><br><?= htmlspecialchars($egreso['proveedor'] ?? 'No identificado') ?></div>
                                    <div class="col-md-6"><strong>Estado</strong><br><span class="badge <?= $badge_estado ?>"><?= ucfirst(str_replace('_', ' ', $egreso['estado_aprobacion'])) ?></span></div>
                                    <div class="col-12"><strong>Motivo / Descripción de la entrega</strong><br><?= !empty($egreso['motivo_entrega']) ? nl2br(htmlspecialchars($egreso['motivo_entrega'])) : '<span class="text-muted">Sin motivo registrado</span>' ?></div>
                                </div>
                                <div class="border rounded-3 bg-light mt-4 p-3">
                                    <div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-paperclip text-success"></i><strong>Comprobante adjunto</strong></div>
                                    <?php if (!empty($egreso['comprobante_mime'])): ?>
                                        <?php $fuente_comprobante = !empty($egreso['id_personal']) ? 'personal' : 'proveedores'; $url_comprobante = 'egresos.php?ver_comprobante=1&fuente=' . $fuente_comprobante . '&id=' . (int) $egreso['id']; $url_visualizador_comprobante = 'comprobante.php?fuente=' . $fuente_comprobante . '&id=' . (int) $egreso['id']; ?>
                                        <?php if (str_starts_with($egreso['comprobante_mime'], 'image/')): ?>
                                            <a href="<?= htmlspecialchars($url_visualizador_comprobante) ?>" target="_blank" rel="noopener" title="Abrir imagen en tamaño completo"><img data-comprobante-src="<?= htmlspecialchars($url_comprobante) ?>" alt="Comprobante de <?= htmlspecialchars($egreso['tipo_comprobante'] . ' ' . $egreso['numero_comprobante']) ?>" style="display:block;max-height:440px;max-width:100%;margin:0 auto;object-fit:contain;border-radius:6px"></a>
                                        <?php else: ?>
                                            <iframe data-comprobante-src="<?= htmlspecialchars($url_comprobante) ?>" title="PDF del comprobante <?= htmlspecialchars($egreso['tipo_comprobante'] . ' ' . $egreso['numero_comprobante']) ?>" style="background:#fff;border:1px solid #d7e0dc;border-radius:6px;height:440px;width:100%"></iframe>
                                        <?php endif; ?>
                                        <div class="small text-muted mt-2 text-center"><?= htmlspecialchars($egreso['comprobante_nombre'] ?? 'Comprobante adjunto') ?></div>
                                    <?php else: ?>
                                        <div class="text-muted small py-3 text-center"><i class="bi bi-file-earmark-x me-1"></i>Este registro todavía no tiene un comprobante digital.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="border-top mt-4 pt-3 text-end">
                                    <span class="text-muted">Monto entregado</span>
                                    <div class="fs-3 fw-bold text-success">S/. <?= number_format($egreso['total'], 2) ?></div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="modal fade" id="modalConfirmarEliminacion" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-md">
                        <div class="modal-content border-0 shadow-lg modal-confirmacion">
                            <div class="modal-header bg-danger text-white">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="confirmacion-icono"><i class="bi bi-trash3"></i></div>
                                    <div>
                                        <h5 class="modal-title mb-1">Confirmar eliminación</h5>
                                        <small class="opacity-75">Revisión de seguridad del egreso</small>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <p class="fs-5 mb-3">¿Deseas eliminar este egreso?</p>
                                <div class="confirmacion-datos">
                                    <div class="small text-muted text-uppercase">Registro seleccionado</div>
                                    <div class="fw-semibold mt-1" id="tipoEgresoEliminar"></div>
                                    <div class="small mt-1"><strong>Comprobante:</strong> <span id="comprobanteEliminar"></span></div>
                                </div>
                                <div class="alert alert-warning confirmacion-advertencia mt-3 mb-0 small">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <span>Esta acción es irreversible. Verifica que el registro seleccionado sea el correcto.</span>
                                </div>
                            </div>
                            <div class="modal-footer bg-light justify-content-end">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <form method="POST" id="formEliminarEgreso"><input type="hidden" name="accion" value="eliminar_egreso"><input type="hidden" name="id_egreso" id="idEgresoEliminar"><input type="hidden" name="fuente_egreso" id="fuenteEgresoEliminar"><input type="hidden" name="origen" value="<?= htmlspecialchars($origen_filtro) ?>"><input type="hidden" name="mes" value="<?= htmlspecialchars($mes_filtro) ?>"><input type="hidden" name="ano" value="<?= htmlspecialchars($ano_filtro) ?>"><input type="hidden" name="comprobante" value="<?= htmlspecialchars($comprobante_filtro) ?>"><button type="submit" class="btn btn-danger px-4"><i class="bi bi-trash3 me-1"></i>Eliminar definitivamente</button></form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal Editar Comprobante -->
                <div class="modal fade" id="modalEditarComprobante" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-md">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-warning text-white">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="confirmacion-icono bg-warning"><i class="bi bi-pencil"></i></div>
                                    <div>
                                        <h5 class="modal-title mb-1">Editar egreso</h5>
                                        <small class="opacity-75">Actualiza los datos permitidos del registro</small>
                                    </div>
                                </div>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="mb-3">
                                    <small class="text-muted text-uppercase">Comprobante actual</small>
                                    <div class="fw-semibold mt-1" id="comprobanteActual" style="font-size: 1.1rem;"></div>
                                </div>
                                <form method="POST" id="formEditarComprobante" enctype="multipart/form-data">
                                    <input type="hidden" name="accion" value="editar_egreso">
                                    <input type="hidden" name="id_egreso" id="idEgresoEditar">
                                    <input type="hidden" name="fuente_egreso" id="fuenteEgresoEditar">
                                    <input type="hidden" name="tipo_comprobante_actual" id="tipoComprobanteEditar">
                                    <input type="hidden" name="origen" value="<?= htmlspecialchars($origen_filtro) ?>">
                                    <input type="hidden" name="mes" value="<?= htmlspecialchars($mes_filtro) ?>">
                                    <input type="hidden" name="ano" value="<?= htmlspecialchars($ano_filtro) ?>">
                                    <input type="hidden" name="comprobante" value="<?= htmlspecialchars($comprobante_filtro) ?>">
                                    
                                    <div class="mb-3">
                                        <label for="numeroComprobanteNuevo" class="form-label">Nuevo número de comprobante</label>
                                        <input type="text" class="form-control comprobante-edicion" id="numeroComprobanteNuevo" name="numero_comprobante_nuevo" maxlength="15" pattern="[A-Z0-9-]{1,15}" title="Para recibos y notas de crédito se permiten hasta 15 caracteres alfanuméricos sin guion automático. Para FACTURA y BOLETA se usa serie de 4 caracteres; RHE usa E001-." required>
                                        <small class="text-muted">Ej: FABC-102035, EXYZ-202036, B123-00012345.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="montoEgresoEditar" class="form-label">Monto entregado (S/.)</label>
                                        <input type="number" class="form-control" id="montoEgresoEditar" name="monto_nuevo" min="0.01" step="0.01" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="descripcionEgresoEditar" class="form-label">Descripción</label>
                                        <textarea class="form-control" id="descripcionEgresoEditar" name="descripcion_nueva" rows="4" maxlength="1000" placeholder="Describe el motivo o detalle del egreso"></textarea>
                                        <small class="text-muted">Máximo 1000 caracteres.</small>
                                    </div>
                                    <div class="mb-3">
                                        <label for="comprobanteEgresoEditar" class="form-label">Adjuntar o reemplazar comprobante</label>
                                        <input type="file" class="form-control" id="comprobanteEgresoEditar" name="comprobante" accept="image/jpeg,image/png,image/webp,application/pdf">
                                        <small class="form-text text-muted" id="estadoComprobanteEditar">Admite JPG, PNG, WEBP o PDF de hasta 10 MB. Si no eliges archivo, se conserva el actual.</small>
                                    </div>
                                    <div class="alert alert-info small d-flex align-items-start gap-2" style="margin-top: 1.5rem;">
                                        <i class="bi bi-info-circle-fill mt-1"></i>
                                        <span>La fecha y el beneficiario permanecen sin cambios. El sistema valida el monto antes de guardar.</span>
                                    </div>
                                </form>
                            </div>
                            <div class="modal-footer bg-light justify-content-end">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" form="formEditarComprobante" class="btn btn-warning"><i class="bi bi-pencil me-1"></i>Actualizar comprobante</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <!-- Modal Nuevo Egreso -->
    <div class="modal fade" id="modalEgreso" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Registrar Nuevo Egreso</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="accion" value="crear_entrega">
                        <input type="hidden" name="origen" value="<?= htmlspecialchars($origen_filtro) ?>">

                        <div class="mb-4">
                            <?php if (!$vista_personal && !$vista_proveedores): ?>
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <label class="form-label fw-semibold mb-0">Tipo de destinatario</label>
                                <small class="text-muted">Selecciona un solo panel</small>
                            </div>
                            <?php endif; ?>
                            <div class="row g-3">
                                <?php if (!$vista_proveedores): ?>
                                <div class="<?= $vista_personal ? 'col-12' : 'col-md-6' ?>">
                                    <div id="panelPersonal" class="egreso-destinatario-panel border rounded-3 p-3 h-100">
                                        <button type="button" class="btn btn-outline-success w-100 text-start" id="tipoPersonal">
                                            <i class="bi bi-person-vcard fs-4 me-2 align-middle"></i>
                                            <span class="align-middle"><strong class="d-block">Personal de la clínica</strong><small>Pagos y entregas al equipo</small></span>
                                        </button>
                                        <label for="id_personal" class="form-label fw-semibold mt-3">Personal que recibe</label>
                                        <select class="form-select" name="id_personal" id="id_personal">
                                <option value="">-- Seleccionar personal --</option>
                                <?php foreach($personal_por_categoria as $categoria => $personas): ?>
                                <optgroup label="<?= htmlspecialchars($categoria) ?>">
                                    <?php foreach($personas as $persona): ?>
                                    <option value="<?= $persona['id_personal'] ?>">
                                        <?= htmlspecialchars($persona['apellido'] . ', ' . $persona['nombre'] . ($persona['cargo'] ? ' - ' . $persona['cargo'] : '')) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                                        <small class="text-muted">Organizado por función o categoría clínica.</small>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php if (!$vista_personal): ?>
                                <div class="<?= $vista_proveedores ? 'col-12' : 'col-md-6' ?>">
                                    <div id="panelProveedor" class="egreso-destinatario-panel border rounded-3 p-3 h-100">
                                        <button type="button" class="btn btn-outline-success w-100 text-start" id="tipoProveedor">
                                            <i class="bi bi-shop fs-4 me-2 align-middle"></i>
                                            <span class="align-middle"><strong class="d-block">Proveedor</strong><small>Pagos y compras externas</small></span>
                                        </button>
                                        <label for="id_proveedor" class="form-label fw-semibold mt-3">Proveedor que recibe</label>
                                        <select class="form-select" name="id_proveedor" id="id_proveedor" disabled>
                                <option value="">-- Seleccionar proveedor --</option>
                                <?php foreach($proveedores as $proveedor): ?>
                                <option value="<?= $proveedor['id'] ?>">
                                    <?= htmlspecialchars($proveedor['nombre'] . ($proveedor['ruc'] ? ' - RUC ' . $proveedor['ruc'] : '')) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                                        <small class="text-muted">Catálogo global de proveedores activos.</small>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="tipo_entrega" id="tipo_entrega" value="<?= $vista_proveedores ? 'proveedor' : 'personal' ?>">
                        </div>

                        <div class="alert alert-light border d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-person-check text-success fs-5"></i>
                            <div><small class="text-muted d-block">Registrado por</small><strong><?= htmlspecialchars($usuario_nombre) ?></strong></div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="tipo_comprobante" class="form-label">Tipo Comprobante</label>
                                <select class="form-control" name="tipo_comprobante" required>
                                    <option value="">-- Seleccionar --</option>
                                    <option>FACTURA</option>
                                    <option>RECIBO</option>
                                    <option>RECIBO DE EGRESO</option>
                                    <option>RHE</option>
                                    <option>NOTA DE CRÉDITO</option>
                                    <option>BOLETA</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="numero_comprobante" class="form-label">N° Comprobante</label>
                                <input type="text" class="form-control comprobante-egreso-sin-espacios" name="numero_comprobante" maxlength="15" pattern="[A-Z0-9-]{1,15}" title="Para recibos y notas de crédito se permiten hasta 15 caracteres alfanuméricos sin guion automático. Para FACTURA y BOLETA se usa serie de 4 caracteres; RHE usa E001-." required>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="fecha" class="form-label">Fecha</label>
                                <input type="date" class="form-control" name="fecha" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label for="tipo_comprobante" class="form-label">Destinatario seleccionado</label>
                                <div class="form-control bg-light"><i class="bi bi-check-circle text-success"></i> Se definirá arriba</div>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="monto" class="form-label">Monto entregado (S/.)</label>
                                <input type="number" class="form-control" name="monto" step="0.01" min="0.01" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Usuario que registra</label>
                                <div class="form-control bg-light text-truncate"><i class="bi bi-shield-check text-success"></i> <?= htmlspecialchars($usuario_nombre) ?></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="id_categoria" class="form-label">Categoría del egreso *</label>
                            <select class="form-select" name="id_categoria" id="id_categoria" required>
                                <option value="">-- Seleccionar categoría --</option>
                                <?php foreach ($categorias as $categoria): ?>
                                <option value="<?= $categoria['id'] ?>"><?= htmlspecialchars($categoria['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">La categoría quedará vinculada al egreso registrado.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción del egreso *</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3" maxlength="1000" placeholder="Indica claramente para qué se utiliza este egreso" required></textarea>
                            <small class="form-text text-muted">Obligatoria para entregas a personal y proveedores.</small>
                        </div>
                        <div class="mb-3">
                            <label for="comprobanteEgresoNuevo" class="form-label fw-semibold">Imagen o PDF del comprobante *</label>
                            <input type="file" class="form-control" id="comprobanteEgresoNuevo" name="comprobante" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                            <small class="form-text text-muted">Adjunta el sustento de este egreso. Formatos: JPG, PNG, WEBP o PDF. Máximo 10 MB.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-check2-circle"></i> Registrar entrega</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/main.js"></script>
    <script>
        document.addEventListener('shown.bs.modal', function (evento) {
            const modal = evento.target;
            if (!modal.id || !modal.id.startsWith('modalEgreso')) return;
            modal.querySelectorAll('[data-comprobante-src]').forEach(function (visor) {
                if (!visor.hasAttribute('src')) visor.setAttribute('src', visor.dataset.comprobanteSrc);
            });
        });

        const alertaDuplicidad = document.querySelector('.alerta-duplicidad');
        if (alertaDuplicidad) {
            setTimeout(function () {
                if (alertaDuplicidad.isConnected) {
                    alertaDuplicidad.remove();
                }
            }, 60000);
        }

        function aplicarFormatoComprobante(valor, tipoComprobante) {
            const limpio = (valor || '').toUpperCase().replace(/[^A-Z0-9-]/g, '');
            if (['RECIBO', 'RECIBO DE EGRESO', 'NOTA DE CRÉDITO'].includes(tipoComprobante)) {
                return limpio.replace(/[^A-Z0-9]/g, '').slice(0, 15);
            }
            const tieneGuion = limpio.includes('-');
            const partes = limpio.split('-');
            let serie = (partes.shift() || '').slice(0, 4);
            let correlativo = partes.join('').replace(/[^0-9]/g, '').replace(/^0+/, '').slice(0, 8);
            if (tipoComprobante === 'RHE') serie = 'E001';
            if (!tieneGuion && serie.length < 4) return serie;
            return serie + '-' + correlativo;
        }

        document.querySelectorAll('.comprobante-egreso-sin-espacios').forEach(function (campo) {
            campo.addEventListener('keydown', function (evento) {
                const tipoComprobante = document.querySelector('select[name="tipo_comprobante"]')?.value || '';
                if (['RECIBO', 'RECIBO DE EGRESO', 'NOTA DE CRÉDITO'].includes(tipoComprobante)) {
                    const esAlfanumerico = /^[A-Za-z0-9]$/.test(evento.key);
                    const teclasControl = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(evento.key) || (evento.ctrlKey && ['a', 'c', 'v', 'x'].includes(evento.key));
                    if (!esAlfanumerico && !teclasControl) evento.preventDefault();
                    return;
                }
                if (evento.key === '-' && this.value.includes('-')) {
                    evento.preventDefault();
                }
                const esValido = /^[A-Za-z0-9]$/.test(evento.key) || evento.key === '-' || ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(evento.key) || (evento.ctrlKey && ['a', 'c', 'v', 'x'].includes(evento.key));
                if (!esValido) {
                    evento.preventDefault();
                }
                if (evento.key === 'Enter') {
                    return;
                }
                if (evento.key === '-' && this.value.length >= 4 && (tipoComprobante === 'FACTURA' || tipoComprobante === 'RHE' || tipoComprobante === 'BOLETA')) {
                    return;
                }
            });

            campo.addEventListener('input', function () {
                const tipoComprobante = document.querySelector('select[name="tipo_comprobante"]')?.value || '';
                this.value = aplicarFormatoComprobante(this.value, tipoComprobante).slice(0, 15);
            });

            campo.addEventListener('paste', function () {
                const campoComprobante = this;
                const tipoComprobante = document.querySelector('select[name="tipo_comprobante"]')?.value || '';
                setTimeout(function () {
                    campoComprobante.value = aplicarFormatoComprobante(campoComprobante.value, tipoComprobante).slice(0, 15);
                }, 0);
            });
        });

        const selectTipoComprobante = document.querySelector('select[name="tipo_comprobante"]');
        if (selectTipoComprobante) {
            selectTipoComprobante.addEventListener('change', function () {
                const campo = document.querySelector('input[name="numero_comprobante"]');
                if (campo) {
                    const valorInicial = this.value === 'FACTURA' ? 'F' : this.value === 'BOLETA' ? 'B' : this.value === 'RHE' ? 'E001-' : campo.value;
                    campo.value = aplicarFormatoComprobante(valorInicial, this.value);
                    campo.focus();
                }
            });
        }

        const tipoPersonal = document.getElementById('tipoPersonal');
        const tipoProveedor = document.getElementById('tipoProveedor');
        const tipoEntrega = document.getElementById('tipo_entrega');
        const panelPersonal = document.getElementById('panelPersonal');
        const panelProveedor = document.getElementById('panelProveedor');
        const selectorPersonal = document.getElementById('id_personal');
        const selectorProveedor = document.getElementById('id_proveedor');

        function actualizarDestinatario(esPersonal) {
            if (panelPersonal) {
                panelPersonal.classList.toggle('border-success', esPersonal);
                panelPersonal.classList.toggle('bg-success-subtle', esPersonal);
            }
            if (panelProveedor) {
                panelProveedor.classList.toggle('border-success', !esPersonal);
                panelProveedor.classList.toggle('bg-success-subtle', !esPersonal);
            }
            tipoEntrega.value = esPersonal ? 'personal' : 'proveedor';
            if (selectorPersonal) {
                selectorPersonal.disabled = !esPersonal;
                selectorPersonal.required = esPersonal;
                if (!esPersonal) selectorPersonal.value = '';
            }
            if (selectorProveedor) {
                selectorProveedor.disabled = esPersonal;
                selectorProveedor.required = !esPersonal;
                if (esPersonal) selectorProveedor.value = '';
            }
        }

        if (tipoPersonal) tipoPersonal.addEventListener('click', () => actualizarDestinatario(true));
        if (tipoProveedor) tipoProveedor.addEventListener('click', () => actualizarDestinatario(false));
        actualizarDestinatario(<?= $vista_proveedores ? 'false' : 'true' ?>);

        function abrirConfirmacionEliminacion(tipoEgreso, comprobante, idEgreso, fuenteEgreso) {
            document.getElementById('tipoEgresoEliminar').textContent = tipoEgreso;
            document.getElementById('comprobanteEliminar').textContent = comprobante;
            document.getElementById('idEgresoEliminar').value = idEgreso;
            document.getElementById('fuenteEgresoEliminar').value = fuenteEgreso;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalConfirmarEliminacion')).show();
            return false;
        }

        function abrirEdicionComprobante(numeroComprobanteActual, idEgreso, fuenteEgreso, comprobanteCompleto, tipoComprobante, montoActual, descripcionActual, tieneComprobante) {
            document.getElementById('comprobanteActual').textContent = comprobanteCompleto;
            document.getElementById('numeroComprobanteNuevo').value = aplicarFormatoComprobanteEditado(numeroComprobanteActual, tipoComprobante || '');
            document.getElementById('montoEgresoEditar').value = montoActual || '';
            document.getElementById('descripcionEgresoEditar').value = descripcionActual || '';
            document.getElementById('idEgresoEditar').value = idEgreso;
            document.getElementById('fuenteEgresoEditar').value = fuenteEgreso;
            document.getElementById('tipoComprobanteEditar').value = tipoComprobante || '';
            document.getElementById('estadoComprobanteEditar').textContent = tieneComprobante
                ? 'Ya existe un comprobante adjunto. Si no eliges otro archivo, se conserva. Admite JPG, PNG, WEBP o PDF de hasta 10 MB.'
                : 'Este registro no tiene archivo. Puedes adjuntarlo ahora. Admite JPG, PNG, WEBP o PDF de hasta 10 MB.';
            document.getElementById('comprobanteEgresoEditar').value = '';
            document.getElementById('numeroComprobanteNuevo').focus();
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditarComprobante')).show();
            return false;
        }

        // Validación en tiempo real para el campo de edición de comprobante
        // SOLO permite: Letras (A-Z, a-z), Números (0-9) y UN único guion (-)
        function aplicarFormatoComprobanteEditado(valor, tipoComprobante) {
            const tipo = (tipoComprobante || '').toUpperCase();
            const limpio = (valor || '').toUpperCase().replace(/[^A-Z0-9-]/g, '');
            if (['RECIBO', 'RECIBO DE EGRESO', 'NOTA DE CRÉDITO'].includes(tipo)) {
                return limpio.replace(/[^A-Z0-9]/g, '').slice(0, 15);
            }
            const tieneGuion = limpio.includes('-');
            const partes = limpio.split('-');
            let serie = (partes.shift() || '').slice(0, 4);
            let correlativo = partes.join('').replace(/[^0-9]/g, '').replace(/^0+/, '').slice(0, 8);
            if (tipo === 'RHE') serie = 'E001';
            if (!tieneGuion && serie.length < 4) return serie;
            return serie + '-' + correlativo;
        }

        document.querySelectorAll('.comprobante-edicion').forEach(function (campo) {
            campo.addEventListener('keydown', function (evento) {
                const tipoComprobante = document.getElementById('tipoComprobanteEditar')?.value || '';
                if (['RECIBO', 'RECIBO DE EGRESO', 'NOTA DE CRÉDITO'].includes(tipoComprobante)) {
                    const esAlfanumerico = /^[A-Za-z0-9]$/.test(evento.key);
                    const teclasControl = ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(evento.key) || (evento.ctrlKey && ['a', 'c', 'v', 'x'].includes(evento.key));
                    if (!esAlfanumerico && !teclasControl) evento.preventDefault();
                    return;
                }
                if (evento.key === '-' && this.value.includes('-')) {
                    evento.preventDefault();
                }
                const esValido = /^[A-Za-z0-9\-]$/.test(evento.key) || ['Backspace', 'Delete', 'ArrowLeft', 'ArrowRight', 'Tab'].includes(evento.key) || (evento.ctrlKey && ['a', 'c', 'v', 'x'].includes(evento.key));
                if (!esValido) {
                    evento.preventDefault();
                }
            });
            
            campo.addEventListener('input', function () {
                const tipoComprobante = document.getElementById('tipoComprobanteEditar')?.value || '';
                this.value = aplicarFormatoComprobanteEditado(this.value, tipoComprobante).slice(0, 15);
            });
            
            campo.addEventListener('paste', function () {
                const campoComprobante = this;
                const tipoComprobante = document.getElementById('tipoComprobanteEditar')?.value || '';
                setTimeout(function () {
                    campoComprobante.value = aplicarFormatoComprobanteEditado(campoComprobante.value, tipoComprobante).slice(0, 15);
                }, 0);
            });
        });

        const revisarEstadosEgresos = function () {
            const url = new URL(window.location.href);
            url.searchParams.set('realtime', '1');
            url.searchParams.set('_sync', String(Date.now()));
            fetch(url.toString(), { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } })
                .then(function (respuesta) { return respuesta.ok ? respuesta.json() : null; })
                .then(function (datos) {
                    if (!datos || !datos.ok) return;
                    if (!window.firmaEstadosEgresos) {
                        window.firmaEstadosEgresos = datos.firma;
                        return;
                    }
                    if (window.firmaEstadosEgresos !== datos.firma) {
                        sessionStorage.setItem('posicionActualizacionEgresos', String(window.scrollY));
                        window.location.reload();
                    }
                })
                .catch(function () {});
        };
        const posicionEgresos = sessionStorage.getItem('posicionActualizacionEgresos');
        if (posicionEgresos !== null) {
            sessionStorage.removeItem('posicionActualizacionEgresos');
            requestAnimationFrame(function () { window.scrollTo(0, Number(posicionEgresos)); });
        }
        revisarEstadosEgresos();
        window.setInterval(revisarEstadosEgresos, 5000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') revisarEstadosEgresos();
        });
    </script>
</body>
</html>
