<?php
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
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
$columna_comprobante = $stmt->fetch();
if ($columna_comprobante && preg_match('/^(?:char|varchar)\((\d+)\)$/i', $columna_comprobante['Type'], $coincidencia) && (int) $coincidencia[1] < 60) {
    $pdo->exec('ALTER TABLE comprobantes_egresos MODIFY archivo_guardado VARCHAR(60) NOT NULL');
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
$mensaje = '';
$error = '';
$pendientes = [];
$pendientes_agrupados = [];
$grupos = [];
$anos_grupo = [];
$meses = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre'];
$mes_grupo = $_GET['mes_desde'] ?? ($_GET['mes_grupo'] ?? date('m'));
$mes_hasta = $_GET['mes_hasta'] ?? $mes_grupo;
$ano_grupo = $_GET['ano_grupo'] ?? date('Y');
$numero_comprobante = trim((string) ($_GET['numero_comprobante'] ?? ''));
$rango_meses_activo = ($_GET['rango_meses'] ?? '') === '1';
$rol_usuario = strtolower(trim((string) ($_SESSION['usuario_rol'] ?? '')));
$es_caja = $rol_usuario === 'caja';
$es_superadmin = $rol_usuario === 'superadmin';
$puede_eliminar_grupo = in_array($rol_usuario, ['administrativo', 'administrador', 'admin', 'superadmin'], true);
$comprobante_completo = preg_match('/^(?:(?:FACTURA|RECIBO|RECIBO DE EGRESO|RHE|BOLETA|NOTA DE CRÉDITO)\s+)?[A-Z0-9]{4}-[0-9]+$/i', $numero_comprobante) === 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mes_grupo = $_POST['mes_desde'] ?? ($_POST['mes_grupo'] ?? $mes_grupo);
    $mes_hasta = $_POST['mes_hasta'] ?? $mes_hasta;
    $ano_grupo = $_POST['ano_grupo'] ?? $ano_grupo;
}

$mes_grupo = preg_match('/^(0[1-9]|1[0-2])$/', (string) $mes_grupo) ? (string) $mes_grupo : date('m');
$mes_hasta = preg_match('/^(0[1-9]|1[0-2])$/', (string) $mes_hasta) ? (string) $mes_hasta : $mes_grupo;
$ano_grupo = preg_match('/^\d{4}$/', (string) $ano_grupo) ? (string) $ano_grupo : date('Y');
if ((int) $mes_hasta < (int) $mes_grupo) {
    [$mes_grupo, $mes_hasta] = [$mes_hasta, $mes_grupo];
}
$fecha_desde = sprintf('%s-%s-01', $ano_grupo, $mes_grupo);
$fecha_hasta = date('Y-m-d', strtotime(sprintf('%s-%s-01 +1 month', $ano_grupo, $mes_hasta)));

if (($_GET['adjuntos_grupo'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $id_grupo_adjuntos = filter_input(INPUT_GET, 'adjuntos_grupo', FILTER_VALIDATE_INT);
    if (!$id_grupo_adjuntos || $id_grupo_adjuntos < 1) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'mensaje' => 'Grupo no válido.']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT id FROM almacenamientos_egresos WHERE id = ? LIMIT 1');
    $stmt->execute([$id_grupo_adjuntos]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'mensaje' => 'No se encontró el grupo solicitado.']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT d.id, d.fuente_egreso, d.id_egreso, ce.mime AS comprobante_mime, ce.nombre_original AS comprobante_nombre FROM detalle_almacenamiento_egresos d LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso COLLATE utf8mb4_general_ci = d.fuente_egreso AND ce.id_egreso = d.id_egreso WHERE d.id_almacenamiento = ? ORDER BY d.fecha, d.id');
    $stmt->execute([$id_grupo_adjuntos]);
    echo json_encode(['ok' => true, 'detalles' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_GET['exportar'] ?? '') === 'excel') {
    $stmt = $pdo->prepare("SELECT a.id, a.nombre, a.fecha, a.estado_aprobacion, u.nombre AS usuario, d.fecha AS fecha_egreso, d.documento, d.proveedor_responsable, d.descripcion, d.total FROM almacenamientos_egresos a LEFT JOIN usuarios u ON u.id = a.id_usuario_registra LEFT JOIN detalle_almacenamiento_egresos d ON d.id_almacenamiento = a.id WHERE a.fecha >= ? AND a.fecha < ? ORDER BY a.fecha DESC, a.id DESC, d.fecha, d.id");
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $filas_excel = $stmt->fetchAll();
    $periodo_excel = strtoupper($meses[$mes_grupo] . ' ' . $ano_grupo);
    $nombre_archivo = 'REGISTRO DE CHEQUES CISMEDIC ' . $periodo_excel . '.xlsx';
    $escapar_xml = static fn($valor): string => htmlspecialchars((string) $valor, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $correlativo_comprobante = static function (?string $documento): string {
        $documento = trim((string) $documento);
        $tipos = ['RECIBO DE EGRESO', 'NOTA DE CRÉDITO', 'FACTURA', 'BOLETA', 'RECIBO', 'RHE'];
        foreach ($tipos as $tipo) {
            $prefijo = $tipo . ' ';
            if (strncasecmp($documento, $prefijo, strlen($prefijo)) === 0) {
                return trim(substr($documento, strlen($prefijo)));
            }
        }
        return $documento;
    };
    $filas_xml = '';
    $grupo_actual = null;
    $total_grupo = 0;
    $fila_numero = 1;
    $rangos_combinados = ['A1:E1', 'A2:E2'];
    $filas_xml .= '<row ht="28" customHeight="1"><c r="A' . $fila_numero++ . '" s="1" t="inlineStr"><is><t>REGISTRO DE CHEQUES CISMEDIC</t></is></c></row>';
    $filas_xml .= '<row ht="20" customHeight="1"><c r="A' . $fila_numero++ . '" s="2" t="inlineStr"><is><t>' . $escapar_xml($periodo_excel) . '</t></is></c></row>';
    foreach ($filas_excel as $fila) {
        if ($grupo_actual !== $fila['id']) {
            if ($grupo_actual !== null) {
                $fila_total = $fila_numero++;
                $filas_xml .= '<row ht="21" customHeight="1"><c r="A' . $fila_total . '" s="4" t="inlineStr"><is><t>TOTAL</t></is></c><c r="E' . $fila_total . '" s="4" t="inlineStr"><is><t>S/. ' . number_format($total_grupo, 2, '.', '') . '</t></is></c></row>';
            }
            $grupo_actual = $fila['id'];
            $total_grupo = 0;
            $fila_titulo_grupo = $fila_numero++;
            $rangos_combinados[] = 'A' . $fila_titulo_grupo . ':E' . $fila_titulo_grupo;
            $filas_xml .= '<row ht="23" customHeight="1"><c r="A' . $fila_titulo_grupo . '" s="3" t="inlineStr"><is><t>' . $escapar_xml($fila['nombre']) . '</t></is></c></row>';
            $fila_encabezado = $fila_numero++;
            $filas_xml .= '<row ht="22" customHeight="1"><c r="A' . $fila_encabezado . '" s="5" t="inlineStr"><is><t>Fecha</t></is></c><c r="B' . $fila_encabezado . '" s="5" t="inlineStr"><is><t>Comprobante</t></is></c><c r="C' . $fila_encabezado . '" s="5" t="inlineStr"><is><t>Responsable / Proveedor</t></is></c><c r="D' . $fila_encabezado . '" s="5" t="inlineStr"><is><t>Descripción</t></is></c><c r="E' . $fila_encabezado . '" s="5" t="inlineStr"><is><t>Total (S/.)</t></is></c></row>';
        }
        $fecha = date('d/m/Y', strtotime($fila['fecha_egreso']));
        $valor_total = (float) $fila['total'];
        $filas_xml .= '<row><c r="A' . $fila_numero . '" s="3" t="inlineStr"><is><t>' . $escapar_xml($fecha) . '</t></is></c><c r="B' . $fila_numero . '" s="3" t="inlineStr"><is><t>' . $escapar_xml($correlativo_comprobante($fila['documento'])) . '</t></is></c><c r="C' . $fila_numero . '" s="3" t="inlineStr"><is><t>' . $escapar_xml($fila['proveedor_responsable'] ?: 'Sin responsable') . '</t></is></c><c r="D' . $fila_numero . '" s="3" t="inlineStr"><is><t>' . $escapar_xml($fila['descripcion'] ?: 'Sin descripción') . '</t></is></c><c r="E' . $fila_numero . '" s="4" t="n"><v>' . number_format($valor_total, 2, '.', '') . '</v></c></row>';
        $fila_numero++;
        $total_grupo += $valor_total;
    }
    if ($grupo_actual !== null) {
        $fila_total = $fila_numero++;
        $filas_xml .= '<row ht="21" customHeight="1"><c r="A' . $fila_total . '" s="2" t="inlineStr"><is><t>TOTAL</t></is></c><c r="E' . $fila_total . '" s="4" t="n"><v>' . number_format($total_grupo, 2, '.', '') . '</v></c></row>';
    }
    $filas_xml = $filas_xml ?: '<row><c r="A1" t="inlineStr"><is><t>No hay egresos agrupados para el período seleccionado.</t></is></c></row>';
    $contenido_tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>';
    $relaciones = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Registro de cheques" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $relaciones_libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $estilos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;S/. &quot;#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/><xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1"/><xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $filas_xml = str_replace(' s="5"', ' s="2"', $filas_xml);
    $filas_xml = str_replace(' s="6"', ' s="3"', $filas_xml);
    $filas_xml = str_replace(' s="7"', ' s="4"', $filas_xml);
    $hoja = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView showGridLines="0" workbookViewId="0"/></sheetViews><cols><col min="1" max="1" width="14" customWidth="1"/><col min="2" max="2" width="20" customWidth="1"/><col min="3" max="3" width="34" customWidth="1"/><col min="4" max="4" width="55" customWidth="1"/><col min="5" max="5" width="18" customWidth="1"/></cols><sheetData>' . $filas_xml . '</sheetData></worksheet>';
    $archivo_temporal = tempnam(sys_get_temp_dir(), 'cismedic_xlsx_');
    $zip = new ZipArchive();
    $zip->open($archivo_temporal, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contenido_tipos);
    $zip->addFromString('_rels/.rels', $relaciones);
    $zip->addFromString('xl/workbook.xml', $libro);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $relaciones_libro);
    $zip->addFromString('xl/styles.xml', $estilos);
    $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
    $zip->close();
    header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: ' . filesize($archivo_temporal));
    readfile($archivo_temporal);
    unlink($archivo_temporal);
    exit;
}

if (($_GET['realtime'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    try {
        $condiciones_tiempo_real = [];
        $parametros_tiempo_real = [];
        if ($numero_comprobante === '' && $mes_grupo !== '') {
            $condiciones_tiempo_real[] = 'MONTH(a.fecha) = ?';
            $parametros_tiempo_real[] = $mes_grupo;
        }
        if ($numero_comprobante === '' && $ano_grupo !== '') {
            $condiciones_tiempo_real[] = 'YEAR(a.fecha) = ?';
            $parametros_tiempo_real[] = $ano_grupo;
        }
        if ($numero_comprobante !== '') {
            $condiciones_tiempo_real[] = $comprobante_completo
                ? 'EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos d_busqueda WHERE d_busqueda.id_almacenamiento = a.id AND UPPER(d_busqueda.documento) = UPPER(?))'
                : 'EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos d_busqueda WHERE d_busqueda.id_almacenamiento = a.id AND d_busqueda.documento LIKE ?)';
            $parametros_tiempo_real[] = $comprobante_completo ? $numero_comprobante : '%' . $numero_comprobante . '%';
        }
        $where_tiempo_real = $condiciones_tiempo_real ? 'WHERE ' . implode(' AND ', $condiciones_tiempo_real) : '';
        $stmt = $pdo->prepare("SELECT MD5(COALESCE(GROUP_CONCAT(CONCAT(id, ':', nombre, ':', cantidad, ':', total_detalle) ORDER BY id SEPARATOR '|'), '')) AS firma FROM (SELECT a.id, a.nombre, a.total, COUNT(d.id) AS cantidad, COALESCE(SUM(d.total), 0) AS total_detalle FROM almacenamientos_egresos a LEFT JOIN detalle_almacenamiento_egresos d ON d.id_almacenamiento = a.id $where_tiempo_real GROUP BY a.id) AS snapshot");
        $stmt->execute($parametros_tiempo_real);
        echo json_encode(['ok' => true, 'firma' => $stmt->fetchColumn()]);
    } catch (Throwable $exception) {
        http_response_code(500);
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar_almacenamiento') {
    try {
        $id_almacenamiento = (int) ($_POST['id_almacenamiento'] ?? 0);
        $nombre = trim($_POST['nombre'] ?? '');
        if ($id_almacenamiento <= 0 || $nombre === '' || mb_strlen($nombre) > 150) {
            throw new Exception('Ingresa un nombre de grupo válido de hasta 150 caracteres.');
        }
        $stmtEstadoGrupo = $pdo->prepare('SELECT estado_aprobacion FROM almacenamientos_egresos WHERE id = ? LIMIT 1');
        $stmtEstadoGrupo->execute([$id_almacenamiento]);
        $estadoGrupo = $stmtEstadoGrupo->fetchColumn();
        if ($estadoGrupo === false) {
            throw new Exception('No se encontró el grupo de egresos.');
        }
        if ($estadoGrupo === 'aprobado' && !$es_superadmin) {
            throw new Exception('Los grupos aprobados no se pueden editar. Solo el superadministrador puede modificarlos.');
        }
        $stmt = $pdo->prepare('UPDATE almacenamientos_egresos SET nombre = ? WHERE id = ? AND (estado_aprobacion <> \'aprobado\' OR ? = 1)');
        $stmt->execute([$nombre, $id_almacenamiento, $es_superadmin ? 1 : 0]);
        if ($stmt->rowCount() !== 1) {
            throw new Exception('No se encontró el grupo o el nombre no tuvo cambios.');
        }
        $mensaje = 'Nombre del grupo actualizado correctamente.';
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar_almacenamiento') {
    try {
        if (!$puede_eliminar_grupo) {
            throw new Exception('Solo un usuario administrativo o superadministrador puede eliminar egresos agrupados.');
        }
        $id_almacenamiento = (int) ($_POST['id_almacenamiento'] ?? 0);
        if ($id_almacenamiento <= 0) {
            throw new Exception('Grupo de egresos no válido.');
        }

        $pdo->beginTransaction();
        $stmtEstadoGrupo = $pdo->prepare('SELECT estado_aprobacion FROM almacenamientos_egresos WHERE id = ? FOR UPDATE');
        $stmtEstadoGrupo->execute([$id_almacenamiento]);
        $estadoGrupo = $stmtEstadoGrupo->fetchColumn();
        if ($estadoGrupo === false) {
            throw new Exception('No se encontró el grupo de egresos.');
        }
        if ($estadoGrupo === 'aprobado' && !$es_superadmin) {
            throw new Exception('Los grupos aprobados no se pueden eliminar. Solo el superadministrador puede hacerlo.');
        }
        $stmt = $pdo->prepare('SELECT fuente_egreso, id_egreso, fecha, documento, proveedor_responsable, total FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ? FOR UPDATE');
        $stmt->execute([$id_almacenamiento]);
        $detalles = $stmt->fetchAll();
        if (!$detalles) {
            $stmt = $pdo->prepare('SELECT id FROM almacenamientos_egresos WHERE id = ? LIMIT 1');
            $stmt->execute([$id_almacenamiento]);
            $grupo_existente = $stmt->fetchColumn();
            if ($grupo_existente === false) {
                $pdo->rollBack();
                $_SESSION['egresos_mensaje'] = 'La agrupación ya había sido eliminada por otro usuario. La vista fue actualizada.';
                $query = http_build_query(array_filter(['mes_desde' => $_POST['mes_desde'] ?? null, 'mes_hasta' => $_POST['mes_hasta'] ?? null, 'ano_grupo' => $_POST['ano_grupo'] ?? null], static fn($valor) => $valor !== null && $valor !== ''));
                header('Location: almacenamiento_egresos.php' . ($query ? '?' . $query : ''));
                exit();
            }
            throw new Exception('El grupo no contiene egresos que puedan restaurarse.');
        }

        foreach ($detalles as $detalle) {
            if ($detalle['fuente_egreso'] === 'personal') {
                $stmt = $pdo->prepare("UPDATE entregas_dinero SET estado_aprobacion = 'pendiente' WHERE id_entrega = ?");
            } elseif ($detalle['fuente_egreso'] === 'proveedores') {
                $stmt = $pdo->prepare("UPDATE entregas_proveedores SET estado_aprobacion = 'pendiente' WHERE id_entrega_proveedor = ?");
            } elseif (($detalle['fuente_egreso'] === null || $detalle['fuente_egreso'] === '') && $detalle['documento']) {
                $stmt = $pdo->prepare("UPDATE entregas_dinero ed LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal SET ed.estado_aprobacion = 'pendiente' WHERE CONCAT(ed.tipo_comprobante, ' ', ed.nComprobante) = ? AND ed.fecha_entrega = ? AND ed.monto = ? AND CONCAT(pc.apellido, ', ', pc.nombre) = ?");
                $stmt->execute([$detalle['documento'], $detalle['fecha'], $detalle['total'], $detalle['proveedor_responsable']]);
                $stmt = $pdo->prepare("UPDATE entregas_proveedores ep LEFT JOIN proveedores p ON p.id = ep.id_proveedor SET ep.estado_aprobacion = 'pendiente' WHERE CONCAT(ep.tipo_comprobante, ' ', ep.nComprobante) = ? AND ep.fecha_entrega = ? AND ep.monto = ? AND p.nombre = ?");
                $stmt->execute([$detalle['documento'], $detalle['fecha'], $detalle['total'], $detalle['proveedor_responsable']]);
                continue;
            } else {
                throw new Exception('El grupo contiene una fuente de egreso no válida.');
            }
            $stmt->execute([(int) $detalle['id_egreso']]);
            if ($stmt->rowCount() !== 1) {
                throw new Exception('No se pudo restaurar uno de los egresos del grupo.');
            }
        }

        $stmt = $pdo->prepare('DELETE FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ?');
        $stmt->execute([$id_almacenamiento]);
        $stmt = $pdo->prepare('DELETE FROM almacenamientos_egresos WHERE id = ?');
        $stmt->execute([$id_almacenamiento]);
        if ($stmt->rowCount() !== 1) {
            throw new Exception('No se pudo eliminar el grupo de egresos.');
        }
        $pdo->commit();
        $mensaje = 'Grupo eliminado correctamente. Sus egresos volvieron a estar disponibles para una nueva preaprobación.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'enviar_almacenamiento') {
    try {
        $nombre = trim($_POST['nombre'] ?? '');
        $observacion = trim($_POST['observacion'] ?? '');
        $mes_seleccion = $mes_grupo;
        $mes_final = $mes_hasta;
        $ano_seleccion = $ano_grupo;
        $seleccionados = array_values(array_filter($_POST['egreso'] ?? [], static fn($valor) => preg_match('/^(personal|proveedores):\d+$/', $valor)));
        if ($nombre === '' || count($seleccionados) === 0) {
            throw new Exception('Ingresa un nombre de grupo y selecciona al menos un egreso.');
        }

        $pdo->beginTransaction();
        $registros = [];
        $documentos_seleccionados = [];
        foreach ($seleccionados as $seleccionado) {
            [$fuente, $id] = explode(':', $seleccionado, 2);
            $id = (int) $id;
            if ($fuente === 'personal') {
                $stmt = $pdo->prepare('SELECT tipo_comprobante, nComprobante FROM entregas_dinero WHERE id_entrega = ? FOR UPDATE');
            } else {
                $stmt = $pdo->prepare('SELECT tipo_comprobante, nComprobante FROM entregas_proveedores WHERE id_entrega_proveedor = ? FOR UPDATE');
            }
            $stmt->execute([$id]);
            $comprobante = $stmt->fetch();
            if (!$comprobante) {
                throw new Exception('Uno de los egresos seleccionados ya no existe.');
            }
            $documento = $comprobante['tipo_comprobante'] . ' ' . $comprobante['nComprobante'];
            if (isset($documentos_seleccionados[$documento])) {
                throw new Exception('El comprobante ' . $documento . ' fue seleccionado más de una vez.');
            }
            $documentos_seleccionados[$documento] = true;
            if ($fuente === 'personal') {
                $stmt = $pdo->prepare("SELECT ed.id_entrega AS id, ed.fecha_entrega AS fecha, ed.tipo_comprobante, ed.nComprobante, ed.monto AS total, ed.descripcion, CONCAT(pc.apellido, ', ', pc.nombre) AS responsable FROM entregas_dinero ed LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal WHERE ed.id_entrega = ? AND ed.estado_aprobacion = 'pendiente' AND ed.fecha_entrega >= ? AND ed.fecha_entrega < ? AND NOT EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos da WHERE (da.fuente_egreso = 'personal' AND da.id_egreso = ed.id_entrega) OR (COALESCE(da.fuente_egreso, '') = '' AND da.documento = CONCAT(ed.tipo_comprobante, ' ', ed.nComprobante) AND da.fecha = ed.fecha_entrega AND da.total = ed.monto AND da.proveedor_responsable = CONCAT(pc.apellido, ', ', pc.nombre)))");
            } else {
                $stmt = $pdo->prepare("SELECT ep.id_entrega_proveedor AS id, ep.fecha_entrega AS fecha, ep.tipo_comprobante, ep.nComprobante, ep.monto AS total, ep.descripcion, p.nombre AS responsable FROM entregas_proveedores ep LEFT JOIN proveedores p ON p.id = ep.id_proveedor WHERE ep.id_entrega_proveedor = ? AND ep.estado_aprobacion = 'pendiente' AND ep.fecha_entrega >= ? AND ep.fecha_entrega < ? AND NOT EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos da WHERE (da.fuente_egreso = 'proveedores' AND da.id_egreso = ep.id_entrega_proveedor) OR (COALESCE(da.fuente_egreso, '') = '' AND da.documento = CONCAT(ep.tipo_comprobante, ' ', ep.nComprobante) AND da.fecha = ep.fecha_entrega AND da.total = ep.monto AND da.proveedor_responsable = p.nombre))");
            }
            $fecha_inicio_seleccion = sprintf('%s-%s-01', $ano_seleccion, $mes_seleccion);
            $fecha_fin_seleccion = date('Y-m-d', strtotime(sprintf('%s-%s-01 +1 month', $ano_seleccion, $mes_final)));
            $stmt->execute([$id, $fecha_inicio_seleccion, $fecha_fin_seleccion]);
            $registro = $stmt->fetch();
            if (!$registro) {
                throw new Exception('Uno de los egresos seleccionados ya no está pendiente o no existe.');
            }
            $registro['fuente'] = $fuente;
            $registros[] = $registro;
        }

        $total = array_sum(array_map(static fn($registro) => (float) $registro['total'], $registros));
        $stmt = $pdo->prepare("INSERT INTO almacenamientos_egresos (nombre, fecha, total, id_usuario_registra, observacion, estado_aprobacion) VALUES (?, CURDATE(), ?, ?, ?, 'en_aprobacion')");
        $stmt->execute([$nombre, $total, $_SESSION['usuario_id'], $observacion ?: null]);
        $id_almacenamiento = (int) $pdo->lastInsertId();

        foreach ($registros as $registro) {
            $stmt = $pdo->prepare('INSERT INTO detalle_almacenamiento_egresos (id_almacenamiento, fuente_egreso, id_egreso, fecha, documento, proveedor_responsable, descripcion, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id_almacenamiento, $registro['fuente'], $registro['id'], $registro['fecha'], $registro['tipo_comprobante'] . ' ' . $registro['nComprobante'], $registro['responsable'], $registro['descripcion'] ?: null, $registro['total']]);
            if ($registro['fuente'] === 'personal') {
                $pdo->prepare("UPDATE entregas_dinero SET estado_aprobacion = 'en_aprobacion' WHERE id_entrega = ?")->execute([$registro['id']]);
            } else {
                $pdo->prepare("UPDATE entregas_proveedores SET estado_aprobacion = 'en_aprobacion' WHERE id_entrega_proveedor = ?")->execute([$registro['id']]);
            }
        }
        $pdo->commit();
        $mensaje = 'Egresos enviados a preaprobación y agrupados correctamente.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

try {
    $consultaPendientesPersonal = "SELECT CONCAT('personal:', ed.id_entrega) AS clave, ed.id_entrega AS id, 'personal' AS fuente, ed.fecha_entrega AS fecha, ed.tipo_comprobante, ed.nComprobante, ed.monto AS total, ed.descripcion, CONCAT(pc.apellido, ', ', pc.nombre) AS responsable, 'Entrega a personal' AS origen, ce.mime AS comprobante_mime, ce.nombre_original AS comprobante_nombre FROM entregas_dinero ed LEFT JOIN personal_clinica pc ON pc.id_personal = ed.id_personal LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso = 'personal' AND ce.id_egreso = ed.id_entrega WHERE ed.estado_aprobacion = 'pendiente' AND ed.fecha_entrega >= ? AND ed.fecha_entrega < ? AND NOT EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos da WHERE (da.fuente_egreso = 'personal' AND da.id_egreso = ed.id_entrega) OR (COALESCE(da.fuente_egreso, '') = '' AND da.documento = CONCAT(ed.tipo_comprobante, ' ', ed.nComprobante) AND da.fecha = ed.fecha_entrega AND da.total = ed.monto AND da.proveedor_responsable = CONCAT(pc.apellido, ', ', pc.nombre))) ORDER BY ed.fecha_entrega DESC, ed.id_entrega DESC";
    $stmt = $pdo->prepare($consultaPendientesPersonal);
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $pendientePersonal = $stmt->fetchAll();
    $consultaPendientesProveedores = "SELECT CONCAT('proveedores:', ep.id_entrega_proveedor) AS clave, ep.id_entrega_proveedor AS id, 'proveedores' AS fuente, ep.fecha_entrega AS fecha, ep.tipo_comprobante, ep.nComprobante, ep.monto AS total, ep.descripcion, p.nombre AS responsable, 'Compra a proveedor' AS origen, ce.mime AS comprobante_mime, ce.nombre_original AS comprobante_nombre FROM entregas_proveedores ep LEFT JOIN proveedores p ON p.id = ep.id_proveedor LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso = 'proveedores' AND ce.id_egreso = ep.id_entrega_proveedor WHERE ep.estado_aprobacion = 'pendiente' AND ep.fecha_entrega >= ? AND ep.fecha_entrega < ? AND NOT EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos da WHERE (da.fuente_egreso = 'proveedores' AND da.id_egreso = ep.id_entrega_proveedor) OR (COALESCE(da.fuente_egreso, '') = '' AND da.documento = CONCAT(ep.tipo_comprobante, ' ', ep.nComprobante) AND da.fecha = ep.fecha_entrega AND da.total = ep.monto AND da.proveedor_responsable = p.nombre)) ORDER BY ep.fecha_entrega DESC, ep.id_entrega_proveedor DESC";
    $stmt = $pdo->prepare($consultaPendientesProveedores);
    $stmt->execute([$fecha_desde, $fecha_hasta]);
    $pendienteProveedores = $stmt->fetchAll();
    $pendientes = array_merge($pendientePersonal, $pendienteProveedores);
    usort($pendientes, static fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    
    // Agrupar por tipo de comprobante
    $pendientes_agrupados = [];
    foreach ($pendientes as $p) {
        $tipo = $p['tipo_comprobante'] ?? 'SIN TIPO';
        if (!isset($pendientes_agrupados[$tipo])) {
            $pendientes_agrupados[$tipo] = [];
        }
        $pendientes_agrupados[$tipo][] = $p;
    }
    // Ordenar grupos por nombre
    ksort($pendientes_agrupados);

    $anos_grupo = $pdo->query("SELECT DISTINCT ano FROM (SELECT YEAR(fecha_entrega) AS ano FROM entregas_dinero UNION SELECT YEAR(fecha_entrega) FROM entregas_proveedores UNION SELECT YEAR(fecha) FROM almacenamientos_egresos UNION SELECT YEAR(CURDATE())) AS anos ORDER BY ano DESC")->fetchAll();

    $condiciones_grupo = [];
    $parametros_grupo = [];
    if ($numero_comprobante === '' && $mes_grupo !== '') {
        $condiciones_grupo[] = 'MONTH(a.fecha) = ?';
        $parametros_grupo[] = $mes_grupo;
    }
    if ($numero_comprobante === '' && $ano_grupo !== '') {
        $condiciones_grupo[] = 'YEAR(a.fecha) = ?';
        $parametros_grupo[] = $ano_grupo;
    }
    if ($numero_comprobante !== '') {
        $condiciones_grupo[] = $comprobante_completo
            ? 'EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos d_busqueda WHERE d_busqueda.id_almacenamiento = a.id AND UPPER(d_busqueda.documento) = UPPER(?))'
            : 'EXISTS (SELECT 1 FROM detalle_almacenamiento_egresos d_busqueda WHERE d_busqueda.id_almacenamiento = a.id AND d_busqueda.documento LIKE ?)';
        $parametros_grupo[] = $comprobante_completo ? $numero_comprobante : '%' . $numero_comprobante . '%';
    }
    $where_grupo = $condiciones_grupo ? 'WHERE ' . implode(' AND ', $condiciones_grupo) : '';
    $stmt = $pdo->prepare("SELECT a.*, u.nombre AS usuario, COUNT(d.id) AS cantidad, COALESCE(SUM(d.total), 0) AS total_detalle, GROUP_CONCAT(d.documento SEPARATOR ' ') AS comprobantes FROM almacenamientos_egresos a LEFT JOIN usuarios u ON u.id = a.id_usuario_registra LEFT JOIN detalle_almacenamiento_egresos d ON d.id_almacenamiento = a.id $where_grupo GROUP BY a.id ORDER BY a.fecha DESC, a.id DESC");
    $stmt->execute($parametros_grupo);
    $grupos = $stmt->fetchAll();
} catch (Exception $e) {
    $error = 'No se pudo cargar el panel de almacenamiento: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><link rel="icon" type="image/png" href="storage/iconos/LOGO.png"><title>Almacenamiento de Egresos - CISMEDIC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css"><link rel="stylesheet" href="assets/css/style.css">
<style>
.storage-hero{background:linear-gradient(135deg,#203a43,#2c7744);border-radius:14px;color:#fff;padding:24px}.panel-title{font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;color:#667085}.storage-table th{font-size:.75rem;text-transform:uppercase;color:#667085;white-space:nowrap}.storage-table td{vertical-align:middle}.grupo-card{border-left:4px solid #198754}.estado-pre{background:#fff3cd;color:#664d03;border:1px solid #ffda6a}.selection-row{cursor:pointer}.selection-row:hover{background:#f0faf5}.modal-detalle-grupo .modal-content{border:0;border-radius:14px;box-shadow:0 20px 55px rgba(15,23,42,.28);overflow:hidden}.modal-detalle-grupo .modal-header{background:#087f5b;color:#fff;border:0}.modal-detalle-grupo .modal-title{color:#fff}.modal-detalle-grupo .modal-body{background:#f7faf9;padding:1.5rem}.detalle-resumen{background:#fff;border:1px solid #dce8e2;border-radius:10px;padding:1rem;box-shadow:0 2px 8px rgba(15,23,42,.05)}.detalle-resumen strong{color:#173b2d}.modal-detalle-grupo .table{background:#fff;border:1px solid #dce3e8}.modal-detalle-grupo .table thead th{background:#e8f2ee;color:#183b2d;border-bottom:2px solid #9bc9b4;font-size:.75rem;text-transform:uppercase;white-space:nowrap}.modal-detalle-grupo .table tbody td{color:#24323d;background:#fff}.modal-detalle-grupo .table tbody tr:nth-child(even) td{background:#f5f8f7}.modal-detalle-grupo .modal-footer{background:#fff;border-top:1px solid #dce3e8}.row.g-4 > .col-xl-7,.row.g-4 > .col-xl-5{width:100%;flex:0 0 100%;}
#modalDetallePendiente .modal-content{border:0;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.3);overflow:hidden}#modalDetallePendiente .modal-header{background:#087f5b;border:0;padding:1.1rem 1.35rem}#modalDetallePendiente .modal-title{color:#fff;font-size:1.05rem;font-weight:600}#modalDetallePendiente .modal-body{background:#f5f8f7;padding:1.5rem}#modalDetallePendiente .modal-body .row{row-gap:1.25rem}#modalDetallePendiente .modal-body small{color:#708078;font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase}#modalDetallePendiente .modal-body strong{color:#173b2d;display:block;font-size:.96rem;margin-top:.25rem}#modalDetallePendiente #detallePendienteDescripcion{background:#fff!important;border:1px solid #d6e3dc!important;border-radius:10px;color:#33443c;line-height:1.55;min-height:72px;padding:1rem!important;white-space:pre-wrap}#modalDetallePendiente .text-end{align-items:center;background:#fff;border:1px solid #d6e3dc;border-radius:10px;display:flex;justify-content:flex-end;padding:.8rem 1rem}#modalDetallePendiente #detallePendienteTotal{color:#087f5b!important;font-size:1.25rem!important;margin:0 .15rem}#modalDetallePendiente .modal-footer{background:#fff;border-top:1px solid #e2ebe6;padding:1rem 1.35rem}#modalDetallePendiente .btn-close{opacity:.9}
</style><style>body[data-rol="caja"] a[href="dashboard.php"],body[data-rol="caja"] a[href="facturas.php"],body[data-rol="caja"] a[href="reportes.php"]{display:none!important}</style>
</head>
<body data-rol="<?= htmlspecialchars($_SESSION['usuario_rol'] ?? '') ?>"><div class="wrapper">
<aside class="sidebar bg-dark text-white"><div class="sidebar-header"><h3><i class="bi bi-hospital"></i> CISMEDIC</h3><p>Control de Egresos</p></div><?php $nav_egresos_abierto = true; ?><nav class="navbar-nav mt-3"><a href="dashboard.php" class="nav-link"><i class="bi bi-speedometer2"></i> Dashboard</a><div class="nav-section"><a href="#" class="nav-link nav-trigger <?= $nav_egresos_abierto ? 'active' : '' ?>" data-submenu-trigger="egresos" aria-expanded="<?= $nav_egresos_abierto ? 'true' : 'false' ?>"><i class="bi bi-receipt"></i> Egresos</a><div class="nav-submenu <?= $nav_egresos_abierto ? 'open' : '' ?>"><a href="entregas_personal.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_personal.php') ? 'active' : '' ?>"><i class="bi bi-person-vcard"></i> Entregas a Personal</a><a href="entregas_proveedores.php" class="nav-link sub-link <?= (basename($_SERVER['PHP_SELF'] ?? '') === 'entregas_proveedores.php') ? 'active' : '' ?>"><i class="bi bi-shop"></i> Entregas a Proveedores</a></div></div><a href="almacenamiento_egresos.php" class="nav-link active"><i class="bi bi-inboxes"></i> Preaprobación</a><a href="personal.php" class="nav-link"><i class="bi bi-people"></i> Personal</a><a href="proveedores.php" class="nav-link"><i class="bi bi-shop"></i> Proveedores</a><a href="facturas.php" class="nav-link"><i class="bi bi-file-earmark-text"></i> Transferencias</a><a href="categorias.php" class="nav-link"><i class="bi bi-tags"></i> Categorías</a><a href="reportes.php" class="nav-link"><i class="bi bi-bar-chart"></i> Reportes</a></nav><div class="sidebar-footer"><a href="perfil.php" class="nav-link"><i class="bi bi-person"></i> <?= htmlspecialchars($_SESSION['usuario_nombre']) ?></a><a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-right"></i> Cerrar Sesión</a></div></aside>
<main class="main-content"><div class="topbar"><div class="topbar-brand"><button class="btn-sidebar" id="sidebarToggle"><i class="bi bi-list"></i></button><span>Preaprobación de egresos</span></div></div><div class="container-fluid p-4">
<?php if($mensaje): ?><div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($mensaje) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?><?php if($error): ?><div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<section class="storage-hero mb-4 d-flex justify-content-between align-items-center gap-3"><div><div class="small text-uppercase opacity-75">Flujo de control</div><h2 class="mb-1">Almacenamiento y preaprobación</h2><p class="mb-0 opacity-75">Agrupa egresos pendientes y envíalos a revisión.</p></div><span class="badge bg-light text-success fs-6"><?= array_reduce($pendientes_agrupados, static fn($carry, $arr) => $carry + count($arr), 0) ?> pendientes</span></section>
<div class="card shadow-sm mb-4"><div class="card-header bg-white"><h6 class="mb-0"><i class="bi bi-funnel text-success me-2"></i>Período de egresos pendientes</h6></div><div class="card-body"><form method="GET" class="row g-3 align-items-end"><div class="col-md-4"><label class="form-label fw-semibold" for="mes_pendientes">Mes</label><select class="form-select" name="mes_grupo" id="mes_pendientes"><?php foreach($meses as $numero=>$nombre): ?><option value="<?= $numero ?>" <?= $mes_grupo === $numero ? 'selected' : '' ?>><?= $nombre ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label fw-semibold" for="ano_pendientes">Año</label><select class="form-select" name="ano_grupo" id="ano_pendientes"><?php foreach($anos_grupo as $ano): ?><option value="<?= $ano['ano'] ?>" <?= $ano_grupo === (string)$ano['ano'] ? 'selected' : '' ?>><?= $ano['ano'] ?></option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-outline-success w-100" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button></div><div class="col-md-2"><a class="btn btn-outline-secondary w-100" href="almacenamiento_egresos.php">Actual</a></div></form></div></div>
<div class="row g-4"><div class="col-xl-7"><div class="card shadow-sm"><div class="card-header bg-white d-flex justify-content-between align-items-center"><div><h5 class="mb-1"><i class="bi bi-cloud-arrow-up text-success me-2"></i>Enviar egresos a preaprobación</h5><small class="text-muted">Selecciona uno o varios registros de <?= $meses[$mes_grupo] ?? $mes_grupo ?> de <?= htmlspecialchars($ano_grupo) ?>.</small></div><span class="badge bg-success-subtle text-success" id="contadorSeleccion">0 seleccionados</span></div><form method="POST"><input type="hidden" name="accion" value="enviar_almacenamiento"><input type="hidden" name="mes_grupo" value="<?= htmlspecialchars($mes_grupo) ?>"><input type="hidden" name="ano_grupo" value="<?= htmlspecialchars($ano_grupo) ?>"><div class="table-responsive" style="max-height:520px;overflow:auto"><table class="table table-hover storage-table mb-0"><thead class="table-light sticky-top"><tr><th></th><th>Fecha</th><th>Origen</th><th>Comprobante</th><th>Responsable</th><th>Total</th></tr></thead><tbody><?php if(!$pendientes): ?><tr><td colspan="6" class="text-center text-muted py-5"><i class="bi bi-check2-circle fs-2 d-block mb-2"></i>No hay egresos pendientes para el período seleccionado.</td></tr><?php else: foreach($pendientes_agrupados as $tipo_comprobante => $registros_grupo): ?><tr style="background: #f8f9fa; border-top: 2px solid #dee2e6;"><td colspan="6" style="padding: 10px 12px; font-weight: 700; color: #495057; letter-spacing: 0.03em; text-transform: uppercase; font-size: 0.8rem;"><i class="bi bi-folder2 me-2" style="color: #087f5b;"></i><?= htmlspecialchars($tipo_comprobante) ?> <span style="background: #087f5b; color: #fff; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; margin-left: 8px;"><?= count($registros_grupo) ?> registros</span></td></tr><?php foreach($registros_grupo as $p): ?><tr class="selection-row"><td><input class="form-check-input egreso-check" type="checkbox" name="egreso[]" value="<?= htmlspecialchars($p['clave']) ?>"></td><td><?= date('d/m/Y', strtotime($p['fecha'])) ?></td><td><span class="badge bg-light text-dark"><?= htmlspecialchars($p['origen']) ?></span></td><td><strong><?= htmlspecialchars($p['tipo_comprobante'] . ' ' . $p['nComprobante']) ?></strong></td><td><?= htmlspecialchars($p['responsable'] ?: 'No identificado') ?></td><td class="text-end" data-total="<?= htmlspecialchars((string)$p['total']) ?>">S/. <?= number_format($p['total'],2) ?></td></tr><?php endforeach; ?><?php endforeach; endif; ?></tbody></table></div><div class="p-3 border-top"><div class="row g-2"><div class="col-md-5"><input class="form-control" name="nombre" placeholder="Nombre del grupo *" required></div><div class="col-md-5"><input class="form-control" name="observacion" placeholder="Observación (opcional)"></div><div class="col-md-2"><button class="btn btn-success w-100" type="submit"><i class="bi bi-send me-1"></i>Enviar</button></div></div></div></form></div></div>
<div class="col-xl-5"><div class="card shadow-sm"><div class="card-header bg-white"><h5 class="mb-1"><i class="bi bi-diagram-3 text-success me-2"></i>Resumen de selección</h5><small class="text-muted">Control antes de enviar a preaprobación.</small></div><div class="card-body"><div class="row g-3"><div class="col-6"><div class="panel-title">Registros</div><h3 id="resumenCantidad">0</h3></div><div class="col-6"><div class="panel-title">Monto</div><h3 id="resumenMonto">S/. 0.00</h3></div></div><div class="alert alert-light border mt-3 mb-0"><i class="bi bi-info-circle me-2"></i>Al enviar, los egresos pasan a estado <strong>en aprobación</strong> y dejan de aparecer como pendientes.</div></div></div></div></div>
<?php if (false): ?>
<div class="card shadow-sm mt-4"><div class="card-header bg-white d-flex justify-content-between align-items-center"><div><h5 class="mb-1"><i class="bi bi-collection text-success me-2"></i>Egresos agrupados</h5><small class="text-muted">Grupos enviados a preaprobación por período.</small></div><span class="badge bg-success-subtle text-success"><?= count($grupos) ?> grupos</span></div><div class="card-body"><form method="GET" class="row g-3 align-items-end mb-4"><div class="col-md-4"><label class="form-label fw-semibold" for="mes_grupo">Mes del grupo</label><select class="form-select" name="mes_grupo" id="mes_grupo"><option value="">Todos los meses</option><?php $meses_grupo = ['01'=>'Enero','02'=>'Febrero','03'=>'Marzo','04'=>'Abril','05'=>'Mayo','06'=>'Junio','07'=>'Julio','08'=>'Agosto','09'=>'Septiembre','10'=>'Octubre','11'=>'Noviembre','12'=>'Diciembre']; foreach($meses_grupo as $numero=>$nombre): ?><option value="<?= $numero ?>" <?= $mes_grupo === $numero ? 'selected' : '' ?>><?= $nombre ?></option><?php endforeach; ?></select></div><div class="col-md-4"><label class="form-label fw-semibold" for="ano_grupo">Año del grupo</label><select class="form-select" name="ano_grupo" id="ano_grupo"><option value="">Todos los años</option><?php $anos_grupo = $pdo->query('SELECT DISTINCT YEAR(fecha) AS ano FROM almacenamientos_egresos ORDER BY ano DESC')->fetchAll(); foreach($anos_grupo as $ano): ?><option value="<?= $ano['ano'] ?>" <?= $ano_grupo === (string)$ano['ano'] ? 'selected' : '' ?>><?= $ano['ano'] ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label fw-semibold" for="numero_comprobante">N° comprobante</label><input class="form-control" type="text" name="numero_comprobante" id="numero_comprobante" value="<?= htmlspecialchars($numero_comprobante) ?>" placeholder="Ej. 1040025"></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button></div><div class="col-md-1"><a class="btn btn-outline-secondary w-100" href="almacenamiento_egresos.php">Todos</a></div></form><div class="row g-3"><?php if(!$grupos): ?><div class="col-12 text-center text-muted py-4">No hay grupos para el período seleccionado.</div><?php else: foreach($grupos as $grupo): ?><div class="col-md-6 col-xl-4"><div class="card grupo-card h-100"><div class="card-body"><div class="d-flex justify-content-between"><h5 class="mb-1"><?= htmlspecialchars($grupo['nombre']) ?></h5><span class="badge estado-pre"><?= htmlspecialchars($grupo['estado_aprobacion'] ?: 'pendiente') ?></span></div><small class="text-muted d-block mb-3">Creado <?= date('d/m/Y', strtotime($grupo['fecha'])) ?> por <?= htmlspecialchars($grupo['usuario'] ?: 'Usuario') ?></small><div class="d-flex justify-content-between"><span><?= (int)$grupo['cantidad'] ?> egresos</span><strong>S/. <?= number_format($grupo['total_detalle'],2) ?></strong></div><?php if($grupo['observacion']): ?><p class="small text-muted mt-3 mb-0"><?= htmlspecialchars($grupo['observacion']) ?></p><?php endif; ?><button class="btn btn-outline-success btn-sm mt-3" data-bs-toggle="collapse" data-bs-target="#grupo<?= (int)$grupo['id'] ?>"><i class="bi bi-eye me-1"></i>Ver detalle</button><div class="collapse mt-3" id="grupo<?= (int)$grupo['id'] ?>"><div class="small border-top pt-2"><?php $detalles = $pdo->prepare('SELECT * FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ? ORDER BY fecha, id'); $detalles->execute([$grupo['id']]); foreach($detalles->fetchAll() as $detalle): ?><div class="py-2 border-bottom"><strong><?= htmlspecialchars($detalle['documento'] ?: 'Sin documento') ?></strong><br><span><?= htmlspecialchars($detalle['proveedor_responsable'] ?: 'Sin responsable') ?></span><span class="float-end">S/. <?= number_format($detalle['total'],2) ?></span></div><?php endforeach; ?></div></div></div></div></div><?php endforeach; endif; ?></div></div></div>
<?php endif; ?>
<div class="modal fade" id="modalEditarGrupo" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-primary text-white"><h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Editar grupo de egresos</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><p class="text-muted">Solo puedes modificar el nombre del grupo. Los egresos, montos y fechas permanecerán intactos.</p><input type="hidden" name="accion" value="editar_almacenamiento"><input type="hidden" name="id_almacenamiento" id="editarGrupoId"><input type="hidden" name="mes_grupo" value="<?= htmlspecialchars($mes_grupo) ?>"><input type="hidden" name="ano_grupo" value="<?= htmlspecialchars($ano_grupo) ?>"><label class="form-label fw-semibold" for="editarGrupoNombre">Nombre del grupo</label><input class="form-control" type="text" name="nombre" id="editarGrupoNombre" maxlength="150" required></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Guardar cambios</button></div></form></div></div></div>
<div class="modal fade" id="modalEliminarGrupo" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-trash3 me-2"></i>Eliminar grupo de egresos</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="accion" value="eliminar_almacenamiento"><input type="hidden" name="id_almacenamiento" id="eliminarGrupoId"><input type="hidden" name="mes_grupo" value="<?= htmlspecialchars($mes_grupo) ?>"><input type="hidden" name="ano_grupo" value="<?= htmlspecialchars($ano_grupo) ?>"><div class="alert alert-warning d-flex gap-2 align-items-start"><i class="bi bi-exclamation-triangle-fill"></i><div>Se eliminará el grupo <strong id="eliminarGrupoNombre"></strong> y sus egresos volverán a la lista de envío a preaprobación.</div></div><p class="mb-0 text-muted">Esta acción no elimina los egresos originales, solo deshace su agrupación.</p></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash3 me-1"></i>Eliminar grupo</button></div></form></div></div></div>
<div class="card shadow-sm mt-4"><div class="card-header bg-white d-flex justify-content-between align-items-center"><div><h5 class="mb-1"><i class="bi bi-collection text-success me-2"></i>Egresos agrupados</h5><small class="text-muted">Grupos enviados a preaprobación por período.</small></div><span class="badge bg-success-subtle text-success"><?= count($grupos) ?> grupos</span></div><div class="card-body"><form method="GET" class="row g-3 align-items-end mb-4"><div class="col-md-3"><label class="form-label fw-semibold" for="mes_grupo">Mes del grupo</label><select class="form-select" name="mes_grupo" id="mes_grupo"><option value="">Todos los meses</option><?php foreach($meses as $numero=>$nombre): ?><option value="<?= $numero ?>" <?= $mes_grupo === $numero ? 'selected' : '' ?>><?= $nombre ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label fw-semibold" for="ano_grupo">Año del grupo</label><select class="form-select" name="ano_grupo" id="ano_grupo"><option value="">Todos los años</option><?php foreach($anos_grupo as $ano): ?><option value="<?= $ano['ano'] ?>" <?= $ano_grupo === (string)$ano['ano'] ? 'selected' : '' ?>><?= $ano['ano'] ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label fw-semibold" for="numero_comprobante">N° comprobante</label><input class="form-control" type="text" name="numero_comprobante" id="numero_comprobante" value="<?= htmlspecialchars($numero_comprobante) ?>" placeholder="Ej. 1040025"></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel me-1"></i>Filtrar</button></div><div class="col-md-1"><a class="btn btn-outline-secondary w-100" href="almacenamiento_egresos.php">Todos</a></div></form><div class="row g-3"><?php if(!$grupos): ?><div class="col-12 text-center text-muted py-4"><?= $numero_comprobante !== '' ? 'No hay grupos con el comprobante "' . htmlspecialchars($numero_comprobante) . '".' : 'No hay grupos para el período seleccionado.' ?></div><?php else: foreach($grupos as $grupo): ?><div class="col-md-6 col-xl-4"><div class="card grupo-card h-100 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between gap-2"><h5 class="card-title mb-2"><?= htmlspecialchars($grupo['nombre']) ?></h5><span class="badge estado-pre"><?= htmlspecialchars($grupo['estado_aprobacion'] ?: 'pendiente') ?></span></div><p class="text-muted small mb-2">Creado <?= date('d/m/Y', strtotime($grupo['fecha'])) ?> por <?= htmlspecialchars($grupo['usuario'] ?: 'Usuario') ?></p><div class="d-flex justify-content-between mb-3"><span><?= (int)$grupo['cantidad'] ?> egresos</span><strong>S/. <?= number_format($grupo['total_detalle'], 2) ?></strong></div><button type="button" class="btn btn-outline-success btn-sm" data-bs-toggle="modal" data-bs-target="#modalGrupo<?= (int)$grupo['id'] ?>"><i class="bi bi-eye me-1"></i>Ver detalle</button></div></div></div><?php endforeach; endif; ?></div></div></div><div class="card shadow-sm mt-4"><div class="card-header bg-white"><h5 class="mb-1"><i class="bi bi-gear text-success me-2"></i>Administrar grupos de egresos</h5><small class="text-muted">Edita únicamente el nombre o elimina un grupo para devolver sus egresos a preaprobación.</small></div><div class="card-body"><div class="row g-3"><div class="col-lg-6"><form method="POST" class="border rounded p-3 h-100"><input type="hidden" name="accion" value="editar_almacenamiento"><input type="hidden" name="mes_grupo" value="<?= htmlspecialchars($mes_grupo) ?>"><input type="hidden" name="ano_grupo" value="<?= htmlspecialchars($ano_grupo) ?>"><label class="form-label fw-semibold" for="grupoEditarId">Grupo a editar</label><select class="form-select mb-3" name="id_almacenamiento" id="grupoEditarId" required><option value="">Selecciona un grupo</option><?php foreach($grupos as $grupo): ?><option value="<?= (int)$grupo['id'] ?>"><?= htmlspecialchars($grupo['nombre']) ?></option><?php endforeach; ?></select><label class="form-label fw-semibold" for="grupoEditarNombre">Nuevo nombre</label><input class="form-control mb-3" type="text" name="nombre" id="grupoEditarNombre" maxlength="150" required><button class="btn btn-primary" type="submit"><i class="bi bi-pencil-square me-1"></i>Guardar nombre</button></form></div><div class="col-lg-6"><form method="POST" class="border border-danger-subtle rounded p-3 h-100" onsubmit="return confirm('¿Confirmas eliminar este grupo? Sus egresos volverán a Enviar egresos a preaprobación y podrán agruparse nuevamente.');"><input type="hidden" name="accion" value="eliminar_almacenamiento"><input type="hidden" name="mes_grupo" value="<?= htmlspecialchars($mes_grupo) ?>"><input type="hidden" name="ano_grupo" value="<?= htmlspecialchars($ano_grupo) ?>"><label class="form-label fw-semibold" for="grupoEliminarId">Grupo a eliminar</label><select class="form-select mb-3" name="id_almacenamiento" id="grupoEliminarId" required><option value="">Selecciona un grupo</option><?php foreach($grupos as $grupo): ?><option value="<?= (int)$grupo['id'] ?>"><?= htmlspecialchars($grupo['nombre']) ?></option><?php endforeach; ?></select><div class="alert alert-warning py-2"><i class="bi bi-info-circle me-1"></i>Los egresos no se borrarán. Solo se deshará su agrupación y volverán a estado pendiente.</div><button class="btn btn-danger" type="submit"><i class="bi bi-trash3 me-1"></i>Eliminar grupo</button></form></div></div></div></div>
<div class="modal fade" id="modalGenerarPdf" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-file-earmark-pdf me-2"></i>Generar PDF del grupo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form method="GET" action="almacenamiento_pdf.php" target="_blank"><div class="modal-body"><p class="text-muted">Digita el numero de cheque que aparecera en el documento.</p><input type="hidden" name="id" id="pdfGrupoId"><label class="form-label fw-semibold" for="pdfCheque">Numero de cheque</label><input class="form-control" type="text" name="cheque" id="pdfCheque" maxlength="30" pattern="[A-Za-z0-9-]{1,30}" inputmode="numeric" placeholder="Ej. 89946" required><small class="text-muted">El PDF usara la fecha en que se agrupo este almacenamiento.</small></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-success"><i class="bi bi-file-earmark-pdf me-1"></i>Generar PDF</button></div></form></div></div></div>
<div class="modal fade" id="modalDetallePendiente" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-receipt me-2"></i>Detalle del egreso</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3"><div class="col-md-6"><small class="text-muted d-block">Fecha</small><strong id="detallePendienteFecha"></strong></div><div class="col-md-6"><small class="text-muted d-block">Origen</small><strong id="detallePendienteOrigen"></strong></div><div class="col-md-6"><small class="text-muted d-block">Comprobante</small><strong id="detallePendienteDocumento"></strong></div><div class="col-md-6"><small class="text-muted d-block">Responsable</small><strong id="detallePendienteResponsable"></strong></div><div class="col-12"><small class="text-muted d-block">Descripcion del egreso</small><div class="border rounded bg-light p-3" id="detallePendienteDescripcion"></div></div><div class="col-12"><section class="border rounded-3 bg-light p-3"><div class="d-flex align-items-center gap-2 mb-3"><i class="bi bi-paperclip text-success"></i><strong>Comprobante adjunto</strong></div><div id="detallePendienteAdjunto"><div class="text-muted small text-center py-3">Selecciona un egreso para consultar su comprobante.</div></div></section></div><div class="col-12 text-end"><small class="text-muted me-2">Total</small><strong class="text-success fs-5" id="detallePendienteTotal"></strong></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button></div></div></div></div>
</div></main></div>
<?php foreach ($grupos as $grupo): ?>
<div class="modal fade" id="modalGrupo<?= (int)$grupo['id'] ?>" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content"><div class="modal-header bg-success text-white"><div><h5 class="modal-title"><i class="bi bi-collection me-2"></i><?= htmlspecialchars($grupo['nombre']) ?></h5><small>Detalle completo del grupo de preaprobación</small></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><div class="row g-3 mb-4"><div class="col-md-3"><small class="text-muted d-block">Fecha</small><strong><?= date('d/m/Y', strtotime($grupo['fecha'])) ?></strong></div><div class="col-md-3"><small class="text-muted d-block">Registrado por</small><strong><?= htmlspecialchars($grupo['usuario'] ?: 'Usuario') ?></strong></div><div class="col-md-3"><small class="text-muted d-block">Estado</small><span class="badge estado-pre"><?= htmlspecialchars($grupo['estado_aprobacion'] ?: 'pendiente') ?></span></div><div class="col-md-3"><small class="text-muted d-block">Total del grupo</small><strong class="text-success">S/. <?= number_format($grupo['total_detalle'],2) ?></strong></div></div><?php if($grupo['observacion']): ?><div class="alert alert-light border"><i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($grupo['observacion']) ?></div><?php endif; ?><div class="table-responsive"><table class="table table-hover align-middle"><thead class="table-light"><tr><th>#</th><th>Fecha</th><th>Documento</th><th>Responsable</th><th>Descripción</th><th class="text-end">Total</th></tr></thead><tbody><?php $detalles_modal = $pdo->prepare('SELECT d.*, ce.mime AS comprobante_mime, ce.nombre_original AS comprobante_nombre FROM detalle_almacenamiento_egresos d LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso COLLATE utf8mb4_general_ci = d.fuente_egreso AND ce.id_egreso = d.id_egreso WHERE d.id_almacenamiento = ? ORDER BY d.fecha, d.id');
        $detalles_modal->execute([$grupo['id']]);
        $numero_detalle = 1;
        foreach($detalles_modal->fetchAll() as $detalle): ?><tr><td><?= $numero_detalle++ ?></td><td><?= date('d/m/Y', strtotime($detalle['fecha'])) ?></td><td><strong><?= htmlspecialchars($detalle['documento'] ?: 'Sin documento') ?></strong></td><td><?= htmlspecialchars($detalle['proveedor_responsable'] ?: 'Sin responsable') ?></td><td><?= htmlspecialchars($detalle['descripcion'] ?: 'Sin descripción') ?></td><td class="text-end fw-semibold">S/. <?= number_format($detalle['total'],2) ?></td></tr><?php endforeach; ?></tbody></table></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button></div></div></div></div>
<?php endforeach; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script><script src="assets/js/main.js"></script><script>
const formularioPdfGrupo = document.querySelector('#modalGenerarPdf form');
if (formularioPdfGrupo) formularioPdfGrupo.action = 'visor_almacenamiento_pdf.php';
document.querySelectorAll('[id^="modalGrupo"]').forEach(function (modal) { modal.classList.add('modal-detalle-grupo'); const body = modal.querySelector('.modal-body'); const barraAcciones = document.createElement('div'); barraAcciones.className = 'd-flex justify-content-end mb-3'; const botonPdf = document.createElement('button'); botonPdf.type = 'button'; botonPdf.className = 'btn btn-success btn-sm'; botonPdf.innerHTML = '<i class="bi bi-file-earmark-pdf me-1"></i>Generar PDF'; botonPdf.setAttribute('data-bs-toggle', 'modal'); botonPdf.setAttribute('data-bs-target', '#modalGenerarPdf'); botonPdf.setAttribute('data-grupo-id', modal.id.replace('modalGrupo', '')); barraAcciones.appendChild(botonPdf); body.insertBefore(barraAcciones, body.firstChild); });
document.querySelectorAll('button[data-bs-toggle="collapse"][data-bs-target^="#grupo"]').forEach(function (boton) { const grupoId = boton.getAttribute('data-bs-target').replace('#grupo', ''); boton.setAttribute('data-bs-toggle', 'modal'); boton.setAttribute('data-bs-target', '#modalGrupo' + grupoId); });
const checks=document.querySelectorAll('.egreso-check');function actualizarSeleccion(){let total=0,cantidad=0;checks.forEach(c=>{if(c.checked){cantidad++;total+=Number(c.closest('tr').querySelector('[data-total]').dataset.total)||0;}});document.getElementById('contadorSeleccion').textContent=cantidad+' seleccionados';document.getElementById('resumenCantidad').textContent=cantidad;document.getElementById('resumenMonto').textContent='S/. '+total.toFixed(2);}checks.forEach(c=>c.addEventListener('change',actualizarSeleccion));document.querySelectorAll('.selection-row').forEach(row=>row.addEventListener('click',e=>{if(e.target.type!=='checkbox' && !e.target.closest('button')){const c=row.querySelector('.egreso-check');c.checked=!c.checked;actualizarSeleccion();}}));

// Barra superior para cargar la lista por tipo de comprobante
const tablaPendientes = document.querySelector('.storage-table');
if (tablaPendientes) {
    const encabezadosGrupo = Array.from(tablaPendientes.querySelectorAll('tbody .bi-folder2')).map(icono => icono.closest('tr'));
    const barraTipos = document.createElement('div');
    barraTipos.className = 'filtros-comprobante p-3 border-bottom';
    barraTipos.innerHTML = '<div class="d-flex align-items-center gap-2 flex-wrap"><span class="fw-semibold text-muted me-2"><i class="bi bi-filter-circle me-1"></i>Tipo de comprobante:</span></div>';
    const contenedorBotones = barraTipos.firstElementChild;
    const crearBotonTipo = function (texto, icono, tipo) {
        const boton = document.createElement('button');
        boton.type = 'button';
        boton.className = 'btn btn-sm btn-outline-success filtro-tipo-comprobante';
        boton.dataset.tipo = tipo;
        boton.innerHTML = '<i class="bi ' + icono + ' me-1"></i>' + texto;
        contenedorBotones.appendChild(boton);
        return boton;
    };
    crearBotonTipo('Todos', 'bi-grid-3x3-gap', 'todos');
    encabezadosGrupo.forEach(function (encabezado) {
        const texto = encabezado.querySelector('td').textContent.trim();
        const tipo = texto.replace(/\s+\d+\s+registros?$/i, '').trim();
        crearBotonTipo(tipo, 'bi-receipt', tipo);
        encabezado.dataset.tipoComprobante = tipo;
    });
    tablaPendientes.parentElement.insertBefore(barraTipos, tablaPendientes);
    const actualizarFiltroTipo = function (tipoSeleccionado, botonActivo) {
        encabezadosGrupo.forEach(function (encabezado) {
            const mostrar = tipoSeleccionado === 'todos' || encabezado.dataset.tipoComprobante === tipoSeleccionado;
            encabezado.hidden = !mostrar;
            let fila = encabezado.nextElementSibling;
            while (fila && !fila.querySelector('.bi-folder2')) {
                fila.hidden = !mostrar;
                fila = fila.nextElementSibling;
            }
        });
        barraTipos.querySelectorAll('.filtro-tipo-comprobante').forEach(boton => boton.classList.remove('active'));
        botonActivo.classList.add('active');
    };
    barraTipos.addEventListener('click', function (event) {
        const boton = event.target.closest('.filtro-tipo-comprobante');
        if (boton) actualizarFiltroTipo(boton.dataset.tipo, boton);
    });
    actualizarFiltroTipo('todos', barraTipos.querySelector('[data-tipo="todos"]'));
}
document.addEventListener('click', function(event) {
    const botonSeleccionar = event.target.closest('.seleccionar-grupo');
    const botonDeseleccionar = event.target.closest('.deseleccionar-grupo');
    
    if (botonSeleccionar || botonDeseleccionar) {
        event.preventDefault();
        const grupo = (botonSeleccionar || botonDeseleccionar).getAttribute('data-grupo');
        const headerRow = (botonSeleccionar || botonDeseleccionar).closest('tr');
        const table = headerRow.closest('table');
        const tbody = table.querySelector('tbody');
        const filas = Array.from(tbody.querySelectorAll('tr'));
        const headerIndex = filas.indexOf(headerRow);
        const shouldCheck = !!botonSeleccionar;
        
        // Encontrar el siguiente encabezado de grupo
        let nextHeaderIndex = filas.length;
        for (let i = headerIndex + 1; i < filas.length; i++) {
            if (filas[i].querySelector('.seleccionar-grupo, .deseleccionar-grupo')) {
                nextHeaderIndex = i;
                break;
            }
        }
        
        // Seleccionar/deseleccionar checkboxes entre este header y el siguiente
        for (let i = headerIndex + 1; i < nextHeaderIndex; i++) {
            const checkbox = filas[i].querySelector('.egreso-check');
            if (checkbox) {
                checkbox.checked = shouldCheck;
                checkbox.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    }
});
</script><script>
document.querySelectorAll('.grupo-card').forEach(function (card) {
    const detalle = card.querySelector('button[data-bs-target^="#modalGrupo"]');
    const nombre = card.querySelector('h5')?.textContent.trim() || '';
    const estado = card.querySelector('.estado-pre')?.textContent.trim().toLowerCase() || '';
    const grupoAprobado = estado === 'aprobado';
    const superadmin = document.body.dataset.rol.toLowerCase() === 'superadmin';
    if (!detalle) return;
    const grupoId = detalle.getAttribute('data-bs-target').replace('#modalGrupo', '');
    const acciones = document.createElement('span');
    acciones.className = 'd-inline-flex gap-2 me-3';
    if (!grupoAprobado || superadmin) {
        acciones.innerHTML = '<button type="button" class="btn btn-outline-primary btn-sm" title="Editar nombre del grupo" aria-label="Editar nombre del grupo" data-bs-toggle="modal" data-bs-target="#modalEditarGrupo" data-grupo-id="' + grupoId + '" data-grupo-nombre="' + nombre.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"><i class="bi bi-pencil-square me-1"></i>Editar</button>';
    }
    <?php if ($puede_eliminar_grupo): ?>
    if (!grupoAprobado || superadmin) {
        acciones.innerHTML += '<button type="button" class="btn btn-outline-danger btn-sm" title="Eliminar grupo" aria-label="Eliminar grupo" data-bs-toggle="modal" data-bs-target="#modalEliminarGrupo" data-grupo-id="' + grupoId + '" data-grupo-nombre="' + nombre.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"><i class="bi bi-trash3 me-1"></i>Eliminar</button>';
    }
    <?php endif; ?>
    detalle.parentNode.insertBefore(acciones, detalle);
});
document.querySelectorAll('.card').forEach(function (card) {
    if (card.textContent.includes('Administrar grupos de egresos')) card.remove();
});
document.addEventListener('click', function (event) {
    const boton = event.target.closest('[data-bs-target="#modalEditarGrupo"], [data-bs-target="#modalEliminarGrupo"], [data-bs-target="#modalGenerarPdf"]');
    if (!boton) return;
    const id = boton.getAttribute('data-grupo-id');
    const nombre = boton.getAttribute('data-grupo-nombre') || '';
    if (boton.getAttribute('data-bs-target') === '#modalEditarGrupo') {
        document.getElementById('editarGrupoId').value = id;
        document.getElementById('editarGrupoNombre').value = nombre;
    } else if (boton.getAttribute('data-bs-target') === '#modalEliminarGrupo') {
        document.getElementById('eliminarGrupoId').value = id;
        document.getElementById('eliminarGrupoNombre').textContent = nombre;
    } else {
        document.getElementById('pdfGrupoId').value = id;
        document.getElementById('pdfCheque').value = '';
    }
});
</script><script>
const detallesPendientes = <?php 
    $todos_pendientes = [];
    foreach ($pendientes_agrupados as $grupo) {
        foreach ($grupo as $egreso) {
            $todos_pendientes[$egreso['clave']] = $egreso;
        }
    }
    echo json_encode($todos_pendientes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
?>;
document.querySelectorAll('.selection-row').forEach(function (row) {
    const checkbox = row.querySelector('.egreso-check');
    const celda = row.querySelector('td:first-child');
    if (!checkbox || !celda) return;
    const boton = document.createElement('button');
    boton.type = 'button';
    boton.className = 'btn btn-outline-info btn-sm ms-2';
    boton.title = 'Ver detalle del egreso';
    boton.setAttribute('aria-label', 'Ver detalle del egreso');
    boton.innerHTML = '<i class="bi bi-eye"></i>';
    boton.setAttribute('data-bs-toggle', 'modal');
    boton.setAttribute('data-bs-target', '#modalDetallePendiente');
    boton.setAttribute('data-pendiente-clave', checkbox.value);
    celda.appendChild(boton);
});
document.addEventListener('click', function (event) {
    const boton = event.target.closest('[data-pendiente-clave]');
    if (!boton) return;
    const detalle = detallesPendientes[boton.getAttribute('data-pendiente-clave')];
    if (!detalle) return;
    const fecha = String(detalle.fecha || '').split('-').reverse().join('/');
    document.getElementById('detallePendienteFecha').textContent = fecha;
    document.getElementById('detallePendienteOrigen').textContent = detalle.origen || '';
    document.getElementById('detallePendienteDocumento').textContent = (detalle.tipo_comprobante || '') + ' ' + (detalle.nComprobante || '');
    document.getElementById('detallePendienteResponsable').textContent = detalle.responsable || 'No identificado';
    document.getElementById('detallePendienteDescripcion').textContent = detalle.descripcion || 'Sin descripcion registrada.';
    document.getElementById('detallePendienteTotal').textContent = 'S/. ' + Number(detalle.total || 0).toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    const contenedorAdjunto = document.getElementById('detallePendienteAdjunto');
    contenedorAdjunto.replaceChildren();
    if (!detalle.comprobante_mime) {
        const aviso = document.createElement('div');
        aviso.className = 'text-muted small text-center py-3';
        aviso.innerHTML = '<i class="bi bi-file-earmark-x me-1"></i>Este egreso no tiene un comprobante digital adjunto.';
        contenedorAdjunto.appendChild(aviso);
        return;
    }

    const urlComprobante = new URL('egresos.php', window.location.href);
    urlComprobante.searchParams.set('ver_comprobante', '1');
    urlComprobante.searchParams.set('fuente', detalle.fuente);
    urlComprobante.searchParams.set('id', String(detalle.id));
    if (String(detalle.comprobante_mime).startsWith('image/')) {
        const imagen = document.createElement('img');
        imagen.src = urlComprobante.toString();
        imagen.alt = 'Comprobante del egreso ' + (detalle.tipo_comprobante || '') + ' ' + (detalle.nComprobante || '');
        imagen.className = 'd-block img-fluid mx-auto rounded';
        imagen.style.maxHeight = '520px';
        imagen.style.objectFit = 'contain';
        contenedorAdjunto.appendChild(imagen);
    } else if (detalle.comprobante_mime === 'application/pdf') {
        const pdf = document.createElement('iframe');
        pdf.src = urlComprobante.toString();
        pdf.title = 'PDF del comprobante ' + (detalle.tipo_comprobante || '') + ' ' + (detalle.nComprobante || '');
        pdf.style.width = '100%';
        pdf.style.height = '520px';
        pdf.style.border = '1px solid #d7e0dc';
        pdf.style.borderRadius = '6px';
        contenedorAdjunto.appendChild(pdf);
    } else {
        const aviso = document.createElement('div');
        aviso.className = 'alert alert-warning small mb-0';
        aviso.textContent = 'El formato de este comprobante no se puede previsualizar.';
        contenedorAdjunto.appendChild(aviso);
    }

    if (detalle.comprobante_nombre) {
        const nombre = document.createElement('div');
        nombre.className = 'small text-muted text-center mt-2';
        nombre.textContent = detalle.comprobante_nombre;
        contenedorAdjunto.appendChild(nombre);
    }
});
</script><script>
document.addEventListener('DOMContentLoaded', function () {
    const filtro = document.querySelector('form[method="GET"] select[name="mes_grupo"]');
    if (filtro) {
        filtro.name = 'mes_desde';
        filtro.id = 'mes_desde';
        filtro.previousElementSibling.textContent = 'Desde el mes';
        const columna = filtro.closest('[class*="col-"]');
        const columnaHasta = columna.cloneNode(true);
        const selectorHasta = columnaHasta.querySelector('select');
        selectorHasta.name = 'mes_hasta';
        selectorHasta.id = 'mes_hasta';
        selectorHasta.value = '<?= htmlspecialchars($mes_hasta, ENT_QUOTES, 'UTF-8') ?>';
        columnaHasta.querySelector('label').textContent = 'Hasta el mes';
        columna.parentElement.insertBefore(columnaHasta, columna.nextSibling);
        columna.className = columna.className.replace('col-md-4', 'col-md-3');
        columnaHasta.className = columnaHasta.className.replace('col-md-4', 'col-md-3');
        const columnasFiltro = Array.from(filtro.form.children);
        columnasFiltro[2].className = columnasFiltro[2].className.replace('col-md-4', 'col-md-3').replace('col-md-2', 'col-md-3');
        columnasFiltro[3].className = columnasFiltro[3].className.replace('col-md-2', 'col-md-1');
        const interruptorColumna = document.createElement('div');
        interruptorColumna.className = 'col-12';
        interruptorColumna.innerHTML = '<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="activarRangoMeses"><label class="form-check-label fw-semibold" for="activarRangoMeses">Egresos de meses pasados</label></div>';
        filtro.form.insertBefore(interruptorColumna, filtro.form.firstElementChild);
        const activarRango = interruptorColumna.querySelector('#activarRangoMeses');
        const rangoActivo = <?= ($rango_meses_activo || $mes_hasta !== $mes_grupo) ? 'true' : 'false' ?>;
        activarRango.checked = rangoActivo;
        const descripcion = document.querySelector('.card-header small.text-muted');
        const alternarRango = function () {
            const mostrarRango = activarRango.checked;
            columna.hidden = !mostrarRango;
            columnaHasta.hidden = !mostrarRango;
            if (!mostrarRango) {
                filtro.value = '<?= htmlspecialchars(date('m'), ENT_QUOTES, 'UTF-8') ?>';
                selectorHasta.value = filtro.value;
            }
            if (descripcion) {
                descripcion.textContent = mostrarRango ? 'Selecciona registros desde el mes inicial hasta el mes final.' : 'Selecciona registros del mes actual.';
            }
        };
        activarRango.addEventListener('change', alternarRango);
        filtro.form.addEventListener('submit', function () {
            let indicadorRango = filtro.form.querySelector('input[name="rango_meses"]');
            if (!indicadorRango) {
                indicadorRango = document.createElement('input');
                indicadorRango.type = 'hidden';
                indicadorRango.name = 'rango_meses';
                filtro.form.appendChild(indicadorRango);
            }
            indicadorRango.value = activarRango.checked ? '1' : '0';
            if (!activarRango.checked) {
                filtro.value = '<?= htmlspecialchars(date('m'), ENT_QUOTES, 'UTF-8') ?>';
                selectorHasta.value = filtro.value;
            }
        });
        alternarRango();
        if (descripcion) {
            descripcion.textContent = rangoActivo ? 'Selecciona registros desde <?= htmlspecialchars($meses[$mes_grupo], ENT_QUOTES, 'UTF-8') ?> hasta <?= htmlspecialchars($meses[$mes_hasta], ENT_QUOTES, 'UTF-8') ?> de <?= htmlspecialchars($ano_grupo, ENT_QUOTES, 'UTF-8') ?>.' : 'Selecciona registros del mes actual.';
        }
    }
    const envio = document.querySelector('form[method="POST"] input[name="mes_grupo"]');
    if (envio) {
        envio.name = 'mes_desde';
        const mesHasta = document.createElement('input');
        mesHasta.type = 'hidden';
        mesHasta.name = 'mes_hasta';
        mesHasta.value = '<?= htmlspecialchars($mes_hasta, ENT_QUOTES, 'UTF-8') ?>';
        envio.form.appendChild(mesHasta);
    }
});
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const encabezados = Array.from(document.querySelectorAll('.card-header h5'));
    const encabezadoAgrupados = encabezados.find(function (encabezado) {
        return encabezado.textContent.includes('Egresos agrupados');
    });
    if (!encabezadoAgrupados) return;

    const panel = encabezadoAgrupados.closest('.card');
    const formularioGrupos = panel.querySelector('form[method="GET"]');
    if (formularioGrupos) {
        const botonExcel = document.createElement('a');
        botonExcel.className = 'btn btn-outline-success w-100';
        botonExcel.innerHTML = '<i class="bi bi-file-earmark-excel me-1"></i>Excel';
        botonExcel.title = 'Exportar egresos agrupados del período seleccionado';
        const actualizarEnlaceExcel = function () {
            const urlExcel = new URL(window.location.href);
            urlExcel.search = '';
            urlExcel.searchParams.set('exportar', 'excel');
            const mesSeleccionado = formularioGrupos.querySelector('[name="mes_grupo"]')?.value || '';
            const anoSeleccionado = formularioGrupos.querySelector('[name="ano_grupo"]')?.value || '';
            if (mesSeleccionado) urlExcel.searchParams.set('mes_grupo', mesSeleccionado);
            if (anoSeleccionado) urlExcel.searchParams.set('ano_grupo', anoSeleccionado);
            botonExcel.href = urlExcel.toString();
        };
        actualizarEnlaceExcel();
        formularioGrupos.querySelectorAll('select').forEach(function (selector) {
            selector.addEventListener('change', actualizarEnlaceExcel);
        });
        const columnaExcel = document.createElement('div');
        columnaExcel.className = 'col-md-2';
        columnaExcel.appendChild(botonExcel);
        formularioGrupos.appendChild(columnaExcel);
    }
    const posicionActualizacion = sessionStorage.getItem('posicionActualizacionEgresosAgrupados');
    if (posicionActualizacion !== null) {
        sessionStorage.removeItem('posicionActualizacionEgresosAgrupados');
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                window.scrollTo(0, Number(posicionActualizacion));
            });
        });
    }
    const contenedor = panel.querySelector('.row.g-3');
    const tarjetas = Array.from(panel.querySelectorAll('.grupo-card'));
    const parametros = new URLSearchParams(window.location.search);
    const busquedaInicial = parametros.get('numero_comprobante') || '';
    const barra = document.createElement('div');
    barra.className = 'input-group input-group-lg mb-4';
    barra.innerHTML = '<span class="input-group-text bg-white"><i class="bi bi-search text-success"></i></span>'
        + '<input class="form-control" id="busquedaComprobanteTiempoReal" type="search" value="" placeholder="Escribe el número de comprobante para localizar su grupo..." autocomplete="off">'
        + '<button class="btn btn-outline-secondary" type="button" id="limpiarBusquedaComprobante" title="Limpiar búsqueda"><i class="bi bi-x-lg"></i></button>';
    panel.querySelector('.card-body').insertBefore(barra, panel.querySelector('form'));

    const campo = barra.querySelector('input');
    const botonLimpiar = barra.querySelector('#limpiarBusquedaComprobante');
    campo.value = busquedaInicial;
    const posicionGuardada = sessionStorage.getItem('posicionFiltroComprobante');
    if (posicionGuardada !== null) {
        sessionStorage.removeItem('posicionFiltroComprobante');
        requestAnimationFrame(function () {
            window.scrollTo(0, Number(posicionGuardada));
        });
    }
    const estado = document.createElement('div');
    estado.className = 'small text-muted mb-3';
    barra.after(estado);

    const obtenerTextoGrupo = function (tarjeta) {
        const detalle = tarjeta.querySelector('button[data-bs-target^="#modalGrupo"]');
        const modal = detalle ? document.querySelector(detalle.getAttribute('data-bs-target')) : null;
        return (modal ? modal.textContent : tarjeta.textContent).toLocaleLowerCase();
    };
    const obtenerResponsablesGrupo = function (tarjeta, termino) {
        const detalle = tarjeta.querySelector('button[data-bs-target^="#modalGrupo"]');
        const modal = detalle ? document.querySelector(detalle.getAttribute('data-bs-target')) : null;
        if (!modal || termino === '') return [];
        return Array.from(modal.querySelectorAll('tbody tr')).reduce(function (responsables, fila) {
            const celdas = fila.querySelectorAll('td');
            const documento = celdas[2]?.textContent.toLocaleLowerCase() || '';
            const responsable = celdas[3]?.textContent.trim() || '';
            if (documento.includes(termino) && responsable && !responsables.includes(responsable)) {
                responsables.push(responsable);
            }
            return responsables;
        }, []);
    };
    const actualizarVista = function () {
        const termino = campo.value.trim().toLocaleLowerCase();
        const gruposEncontrados = [];
        let visibles = 0;
        tarjetas.forEach(function (tarjeta) {
            const coincide = termino === '' || obtenerTextoGrupo(tarjeta).includes(termino);
            tarjeta.closest('[class*="col-"]').hidden = !coincide;
            if (coincide) {
                visibles++;
                const nombreGrupo = tarjeta.querySelector('h5')?.textContent.trim() || 'grupo sin nombre';
                const responsables = obtenerResponsablesGrupo(tarjeta, termino);
                gruposEncontrados.push(nombreGrupo + (responsables.length ? ' (entregado a: ' + responsables.join(', ') + ')' : ''));
            }
        });
        if (termino === '') {
            estado.className = 'small text-muted mb-3';
            estado.textContent = 'Escribe para buscar en todos los comprobantes de los grupos.';
        } else if (visibles > 0) {
            estado.className = 'alert alert-success py-2 mb-3';
            estado.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i><strong>Comprobante encontrado.</strong> Está almacenado en: <strong>'
                + gruposEncontrados.join(', ') + '</strong>.';
        } else {
            estado.className = 'alert alert-warning py-2 mb-3';
            estado.innerHTML = '<i class="bi bi-search me-2"></i>No se encontró un grupo almacenado con ese comprobante.';
        }
    };
    let temporizador;
    campo.addEventListener('input', function () {
        actualizarVista();
        clearTimeout(temporizador);
        temporizador = setTimeout(function () {
            const termino = campo.value.trim();
            if (termino !== (new URLSearchParams(window.location.search).get('numero_comprobante') || '')) {
                const url = new URL(window.location.href);
                if (termino) url.searchParams.set('numero_comprobante', termino);
                else url.searchParams.delete('numero_comprobante');
                if (termino) {
                    url.searchParams.delete('mes_grupo');
                    url.searchParams.delete('ano_grupo');
                }
                sessionStorage.setItem('posicionFiltroComprobante', String(window.scrollY));
                window.location.href = url.toString();
            }
        }, 500);
    });
    botonLimpiar.addEventListener('click', function () {
        campo.value = '';
        campo.dispatchEvent(new Event('input'));
        campo.focus();
    });
    actualizarVista();

    const urlTiempoReal = new URL(window.location.href);
    urlTiempoReal.searchParams.set('realtime', '1');
    let firmaActual = null;
    const revisarActualizacion = function () {
        const urlConsulta = new URL(urlTiempoReal.toString());
        urlConsulta.searchParams.set('_sync', String(Date.now()));
        fetch(urlConsulta.toString(), { cache: 'no-store', headers: { 'Cache-Control': 'no-cache' } })
            .then(function (respuesta) { return respuesta.ok ? respuesta.json() : null; })
            .then(function (datos) {
                if (!datos || !datos.ok) return;
                if (firmaActual === null) {
                    firmaActual = datos.firma;
                    return;
                }
                if (firmaActual !== datos.firma) {
                    sessionStorage.setItem('posicionActualizacionEgresosAgrupados', String(window.scrollY));
                    window.location.reload();
                }
            })
            .catch(function () {});
    };
    revisarActualizacion();
    window.setInterval(revisarActualizacion, 5000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') revisarActualizacion();
    });
});

document.addEventListener('shown.bs.modal', async function (evento) {
    const modal = evento.target;
    if (!modal.id || !modal.id.startsWith('modalGrupo')) return;
    const idGrupo = modal.id.replace('modalGrupo', '');
    const tabla = modal.querySelector('.modal-body .table-responsive table');
    if (!tabla || tabla.dataset.adjuntosCargados === '1' || tabla.dataset.adjuntosCargando === '1') return;

    const encabezado = tabla.tHead?.rows[0];
    const filas = Array.from(tabla.tBodies[0]?.rows || []);
    if (!encabezado || !filas.length) return;
    tabla.dataset.adjuntosCargando = '1';
    if (!Array.from(encabezado.cells).some(function (celda) { return celda.dataset.columnaAdjunto === '1'; })) {
        const encabezadoTotal = encabezado.cells[encabezado.cells.length - 1];
        const thComprobante = document.createElement('th');
        thComprobante.textContent = 'Comprobante';
        thComprobante.dataset.columnaAdjunto = '1';
        encabezado.insertBefore(thComprobante, encabezadoTotal);
        filas.forEach(function (fila) {
            const celda = document.createElement('td');
            celda.className = 'text-center';
            celda.dataset.celdaAdjunto = '1';
            celda.textContent = 'Cargando...';
            fila.insertBefore(celda, fila.cells[fila.cells.length - 1]);
        });
    }

    const urlAdjuntos = new URL(window.location.href);
    urlAdjuntos.searchParams.set('adjuntos_grupo', idGrupo);
    try {
        const respuesta = await fetch(urlAdjuntos.toString(), {cache: 'no-store', headers: {'Accept': 'application/json'}});
        const datos = await respuesta.json();
        if (!respuesta.ok || !datos.ok) throw new Error(datos.mensaje || 'No se pudieron cargar los comprobantes.');

        filas.forEach(function (fila, indice) {
            const celda = fila.cells[fila.cells.length - 2];
            const detalle = (datos.detalles || [])[indice];
            celda.replaceChildren();
            if (!detalle || !['personal', 'proveedores'].includes(detalle.fuente_egreso) || Number(detalle.id_egreso) < 1 || !detalle.comprobante_mime) {
                celda.innerHTML = '<span class="small text-muted">Sin comprobante digital</span>';
                return;
            }

            const urlComprobante = new URL('comprobante.php', window.location.href);
            urlComprobante.searchParams.set('fuente', detalle.fuente_egreso);
            urlComprobante.searchParams.set('id', String(detalle.id_egreso));
            const urlArchivo = new URL('egresos.php', window.location.href);
            urlArchivo.searchParams.set('ver_comprobante', '1');
            urlArchivo.searchParams.set('fuente', detalle.fuente_egreso);
            urlArchivo.searchParams.set('id', String(detalle.id_egreso));
            const enlace = document.createElement('a');
            enlace.href = urlComprobante.toString();
            enlace.target = '_blank';
            enlace.rel = 'noopener';
            enlace.className = 'd-inline-flex flex-column align-items-center gap-1 text-decoration-none';
            enlace.title = detalle.comprobante_nombre || 'Abrir comprobante';

            if (String(detalle.comprobante_mime).startsWith('image/')) {
                const imagen = document.createElement('img');
                imagen.src = urlArchivo.toString();
                imagen.alt = 'Comprobante ' + (detalle.comprobante_nombre || 'del egreso');
                imagen.loading = 'lazy';
                imagen.style.maxWidth = '96px';
                imagen.style.maxHeight = '64px';
                imagen.style.objectFit = 'contain';
                imagen.style.border = '1px solid #dce7e2';
                imagen.style.borderRadius = '5px';
                imagen.style.background = '#fff';
                enlace.appendChild(imagen);
            } else if (detalle.comprobante_mime === 'application/pdf') {
                const etiquetaPdf = document.createElement('span');
                etiquetaPdf.className = 'badge text-bg-danger';
                etiquetaPdf.innerHTML = '<i class="bi bi-file-earmark-pdf me-1"></i>Abrir PDF';
                enlace.appendChild(etiquetaPdf);
            } else {
                celda.textContent = 'Formato no previsualizable';
                return;
            }

            const nombre = document.createElement('small');
            nombre.className = 'text-muted text-break';
            nombre.textContent = detalle.comprobante_nombre || 'Ver comprobante';
            enlace.appendChild(nombre);
            celda.appendChild(enlace);
        });
        tabla.dataset.adjuntosCargados = '1';
    } catch (error) {
        filas.forEach(function (fila) {
            const celda = fila.querySelector('[data-celda-adjunto="1"]');
            if (celda) celda.innerHTML = '<span class="small text-danger">Error al cargar, vuelve a abrir el grupo para reintentar</span>';
        });
    } finally {
        tabla.dataset.adjuntosCargando = '0';
    }
});
</script></body></html>