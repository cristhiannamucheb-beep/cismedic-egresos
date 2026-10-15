<?php
require_once __DIR__ . '/config/auth.php';
iniciar_sesion_segura();
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    exit('Debes iniciar sesión para descargar este documento.');
}
requerir_roles(['superadmin', 'administrativo', 'caja']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/aprobaciones.php';
inicializar_tablas_aprobacion($pdo);
date_default_timezone_set('America/Lima');

$idGrupo = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idGrupo || $idGrupo < 1) {
    http_response_code(400);
    exit('Grupo no válido.');
}

$stmt = $pdo->prepare('SELECT a.id, a.nombre, a.fecha, a.total, a.estado_aprobacion, a.observacion, u.nombre AS usuario_registra FROM almacenamientos_egresos a LEFT JOIN usuarios u ON u.id = a.id_usuario_registra WHERE a.id = ? LIMIT 1');
$stmt->execute([$idGrupo]);
$grupo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$grupo) {
    http_response_code(404);
    exit('El grupo no existe o fue liberado para reagruparse.');
}

$stmt = $pdo->prepare('SELECT fecha, documento, proveedor_responsable, descripcion, total FROM detalle_almacenamiento_egresos WHERE id_almacenamiento = ? ORDER BY fecha, id');
$stmt->execute([$idGrupo]);
$detalles = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT d.id, d.documento, d.fuente_egreso, d.id_egreso, ce.archivo_guardado, ce.nombre_original, ce.mime FROM detalle_almacenamiento_egresos d LEFT JOIN comprobantes_egresos ce ON ce.fuente_egreso COLLATE utf8mb4_general_ci = d.fuente_egreso AND ce.id_egreso = d.id_egreso WHERE d.id_almacenamiento = ? ORDER BY d.fecha, d.id');
$stmt->execute([$idGrupo]);
$comprobantes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$directorioComprobantes = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'comprobantes_egresos';
$anexos = [];
foreach ($comprobantes as $comprobante) {
    $nombreGuardado = (string) ($comprobante['archivo_guardado'] ?? '');
    if (!preg_match('/^[a-f0-9]{48}\.(jpg|png|webp|pdf)$/', $nombreGuardado)) {
        continue;
    }
    $rutaComprobante = $directorioComprobantes . DIRECTORY_SEPARATOR . $nombreGuardado;
    if (is_file($rutaComprobante)) {
        $anexos[] = [
            'ruta' => $rutaComprobante,
            'mime' => (string) $comprobante['mime'],
            'nombre' => (string) $comprobante['nombre_original'],
            'documento' => (string) $comprobante['documento'],
        ];
    }
}
$stmt = $pdo->prepare('SELECT u.nombre, ag.es_final, ag.fecha_aprobacion FROM aprobacion_grupos_egresos ag JOIN usuarios u ON u.id = ag.id_usuario WHERE ag.id_almacenamiento = ? ORDER BY ag.es_final, ag.fecha_aprobacion');
$stmt->execute([$idGrupo]);
$aprobaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->prepare('SELECT MAX(fecha_aprobacion) FROM aprobacion_grupos_egresos WHERE id_almacenamiento = ? AND es_final = 1');
$stmt->execute([$idGrupo]);
$fechaAprobacionTotal = $stmt->fetchColumn();
$requisitos = obtener_requisitos_aprobacion($pdo);

$requireAutoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($requireAutoload)) {
    http_response_code(500);
    exit('Falta instalar las dependencias PDF del sistema.');
}
require_once $requireAutoload;

function texto_pdf_fpdf(string $texto): string
{
    $textoConvertido = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $texto);
    return $textoConvertido === false ? '' : $textoConvertido;
}

function envolver_texto_pdf(\FPDF $pdf, string $texto, float $ancho): array
{
    $lineas = [];
    foreach (explode("\n", str_replace("\r", '', $texto)) as $parrafo) {
        $palabras = preg_split('/\s+/', trim($parrafo)) ?: [];
        $linea = '';
        foreach ($palabras as $palabra) {
            $prueba = $linea === '' ? $palabra : $linea . ' ' . $palabra;
            if ($linea !== '' && $pdf->GetStringWidth($prueba) > $ancho) {
                $lineas[] = $linea;
                $linea = $palabra;
            } else {
                $linea = $prueba;
            }
        }
        $lineas[] = $linea;
    }
    return $lineas ?: [''];
}

class ActaAprobacionPDF extends \FPDF
{
    public array $grupoActa;
    public ?string $fechaAprobacionActa;
    public int $aprobacionesContablesActa = 0;
    public int $requisitoContableActa = 0;
    public int $aprobacionesGerencialesActa = 0;
    public string $rutaLogo;

    public function Header(): void
    {
        $this->SetFillColor(20, 91, 66);
        $this->Rect(0, 0, 297, 4, 'F');
        if (is_file($this->rutaLogo)) {
            $this->Image($this->rutaLogo, 13, 10, 17, 17);
        }
        $this->SetTextColor(24, 65, 48);
        $this->SetFont('Helvetica', 'B', 18);
        $this->SetXY(34, 10);
        $this->Cell(160, 8, 'CISMEDIC', 0, 1, 'L');
        $this->SetFont('Helvetica', '', 8);
        $this->SetTextColor(91, 108, 99);
        $this->SetX(34);
        $this->Cell(160, 5, 'SISTEMA DE CONTROL DE EGRESOS', 0, 1, 'L');

        $estadoAprobado = $this->grupoActa['estado_aprobacion'] === 'aprobado';
        $this->SetFillColor($estadoAprobado ? 225 : 255, $estadoAprobado ? 242 : 244, $estadoAprobado ? 232 : 218);
        $this->SetTextColor($estadoAprobado ? 16 : 127, $estadoAprobado ? 105 : 83, $estadoAprobado ? 71 : 18);
        $this->SetFont('Helvetica', 'B', 8);
        $this->SetXY(238, 11);
        $this->Cell(46, 9, $estadoAprobado ? 'APROBADO' : 'EN APROBACION', 0, 0, 'C', true);

        $this->SetDrawColor(216, 226, 220);
        $this->Line(13, 32, 284, 32);
        $this->SetY(37);
    }

    public function Footer(): void
    {
        if ($this->grupoActa['estado_aprobacion'] === 'aprobado' && $this->fechaAprobacionActa) {
            $estadoDocumento = 'DOCUMENTO: APROBADO TOTALMENTE EL: ' . date('d/m/Y H:i', strtotime($this->fechaAprobacionActa));
        } elseif ($this->aprobacionesContablesActa < $this->requisitoContableActa) {
            $estadoDocumento = 'DOCUMENTO: EN APROBACION CONTABLE (' . $this->aprobacionesContablesActa . '/' . $this->requisitoContableActa . ') | APROBACION GERENCIAL: PENDIENTE';
        } else {
            $estadoDocumento = 'DOCUMENTO: APROBACION CONTABLE COMPLETA | APROBACION GERENCIAL: PENDIENTE';
        }
        $this->SetY(-18);
        $this->SetDrawColor(216, 226, 220);
        $this->Line(13, $this->GetY(), 284, $this->GetY());
        $this->SetY(-15);
        $this->SetTextColor(57, 75, 65);
        $this->SetFont('Helvetica', 'B', 5);
        $this->Cell(271, 3, texto_pdf_fpdf($estadoDocumento), 0, 1, 'L');
        $this->SetFont('Helvetica', '', 5);
        $this->Cell(230, 3, texto_pdf_fpdf('GENERADO POR SISTEMA DE CONTROL DE EGRESOS CISMEDIC (' . date('d/m/Y H:i') . ')'), 0, 0, 'L');
        $this->Cell(41, 3, 'PAG. ' . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }
}

$acta = new ActaAprobacionPDF('L', 'mm', 'A4');
$acta->grupoActa = $grupo;
$acta->fechaAprobacionActa = $fechaAprobacionTotal ? (string) $fechaAprobacionTotal : null;
$acta->aprobacionesContablesActa = count(array_filter($aprobaciones, static fn($voto) => (int) $voto['es_final'] === 0));
$acta->requisitoContableActa = (int) $requisitos['regular'];
$acta->aprobacionesGerencialesActa = count(array_filter($aprobaciones, static fn($voto) => (int) $voto['es_final'] === 1));
$acta->rutaLogo = __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'iconos' . DIRECTORY_SEPARATOR . 'LOGO.png';
$acta->SetMargins(13, 37, 13);
$acta->SetAutoPageBreak(true, 23);
$acta->AliasNbPages();
$acta->AddPage();

$acta->SetTextColor(25, 61, 45);
$acta->SetFont('Helvetica', 'B', 15);
$acta->Cell(190, 8, texto_pdf_fpdf('ACTA DE APROBACION DE EGRESOS'), 0, 0, 'L');
$acta->Ln(8);

$acta->SetFont('Helvetica', 'B', 13);
$acta->SetTextColor(24, 65, 48);
$acta->MultiCell(271, 7, texto_pdf_fpdf((string) $grupo['nombre']), 0, 'L');
$acta->SetFont('Helvetica', '', 8);
$acta->SetTextColor(85, 101, 92);
$acta->Cell(271, 6, 'Fecha de almacenamiento: ' . date('d/m/Y', strtotime($grupo['fecha'])), 0, 1, 'L');

$acta->Ln(4);
$acta->SetFillColor(241, 247, 243);
$acta->SetDrawColor(220, 231, 224);
$xResumen = $acta->GetX();
$yResumen = $acta->GetY();
$acta->Rect($xResumen, $yResumen, 271, 17, 'DF');
$acta->SetXY($xResumen + 5, $yResumen + 3);
$acta->SetFont('Helvetica', '', 7);
$acta->SetTextColor(101, 118, 108);
$acta->Cell(62, 4, 'EGRESOS INCLUIDOS', 0, 0, 'L');
$acta->Cell(95, 4, 'APROBACION CONTABLE', 0, 0, 'L');
$acta->Cell(114, 4, 'APROBACION GERENCIAL', 0, 1, 'L');
$acta->SetX($xResumen + 5);
$acta->SetFont('Helvetica', 'B', 10);
$acta->SetTextColor(24, 65, 48);
$acta->Cell(62, 6, (string) count($detalles), 0, 0, 'L');
$acta->Cell(95, 6, count(array_filter($aprobaciones, static fn($voto) => (int) $voto['es_final'] === 0)) . ' / ' . (int) $requisitos['regular'], 0, 0, 'L');
$acta->Cell(114, 6, count(array_filter($aprobaciones, static fn($voto) => (int) $voto['es_final'] === 1)) . ' / ' . (int) $requisitos['final'], 0, 1, 'L');
$acta->Ln(5);

$anchosColumnas = [12, 23, 39, 54, 117, 26];
$encabezados = ['#', 'FECHA', 'DOCUMENTO', 'RESPONSABLE / PROVEEDOR', 'DESCRIPCION', 'TOTAL'];
$acta->SetFillColor(27, 91, 66);
$acta->SetTextColor(255, 255, 255);
$acta->SetFont('Helvetica', 'B', 7);
foreach ($encabezados as $indice => $encabezado) {
    $acta->Cell($anchosColumnas[$indice], 8, texto_pdf_fpdf($encabezado), 0, 0, $indice === 5 ? 'R' : 'L', true);
}
$acta->Ln();

foreach ($detalles as $indiceDetalle => $detalle) {
    $valores = [
        (string) ($indiceDetalle + 1),
        date('d/m/Y', strtotime($detalle['fecha'])),
        texto_pdf_fpdf((string) $detalle['documento']),
        texto_pdf_fpdf((string) $detalle['proveedor_responsable']),
        texto_pdf_fpdf((string) ($detalle['descripcion'] ?: 'Sin descripcion')),
        'S/. ' . number_format((float) $detalle['total'], 2),
    ];
    $lineas = [];
    foreach ($valores as $indiceValor => $valor) {
        $lineas[$indiceValor] = envolver_texto_pdf($acta, $valor, $anchosColumnas[$indiceValor] - 3);
    }
    $altoFila = max(8, max(array_map('count', $lineas)) * 4 + 4);
    if ($acta->GetY() + $altoFila > 270) {
        $acta->AddPage();
        $acta->SetFillColor(27, 91, 66);
        $acta->SetTextColor(255, 255, 255);
        $acta->SetFont('Helvetica', 'B', 7);
        foreach ($encabezados as $indice => $encabezado) {
            $acta->Cell($anchosColumnas[$indice], 8, texto_pdf_fpdf($encabezado), 0, 0, $indice === 5 ? 'R' : 'L', true);
        }
        $acta->Ln();
    }
    $xFila = $acta->GetX();
    $yFila = $acta->GetY();
    $acta->SetFillColor($indiceDetalle % 2 === 0 ? 247 : 238, $indiceDetalle % 2 === 0 ? 250 : 245, $indiceDetalle % 2 === 0 ? 248 : 240);
    $acta->SetDrawColor(225, 233, 228);
    $acta->Rect($xFila, $yFila, array_sum($anchosColumnas), $altoFila, 'DF');
    $xColumna = $xFila;
    $acta->SetTextColor(48, 65, 55);
    $acta->SetFont('Helvetica', '', 7);
    foreach ($valores as $indiceValor => $valor) {
        $acta->SetXY($xColumna + 1.5, $yFila + 2);
        foreach ($lineas[$indiceValor] as $linea) {
            $acta->Cell($anchosColumnas[$indiceValor] - 3, 4, $linea, 0, 2, $indiceValor === 5 ? 'R' : 'L');
        }
        $xColumna += $anchosColumnas[$indiceValor];
    }
    $acta->SetXY($xFila, $yFila + $altoFila);
}

$acta->Ln(4);
$acta->SetFont('Helvetica', 'B', 11);
$acta->SetTextColor(15, 125, 83);
$acta->Cell(271, 7, 'TOTAL: S/. ' . number_format((float) $grupo['total'], 2), 0, 1, 'R');
if (!empty($grupo['observacion'])) {
    $acta->Ln(2);
    $acta->SetFont('Helvetica', 'B', 8);
    $acta->SetTextColor(44, 66, 52);
    $acta->Cell(271, 5, 'OBSERVACIONES', 0, 1, 'L');
    $acta->SetFont('Helvetica', '', 8);
    $acta->SetTextColor(75, 89, 80);
    $acta->MultiCell(271, 4, texto_pdf_fpdf((string) $grupo['observacion']), 0, 'L');
}

$acta->Ln(3);
$acta->SetFillColor(241, 247, 243);
$acta->SetTextColor(24, 65, 48);
$acta->SetFont('Helvetica', 'B', 9);
$acta->Cell(271, 7, 'REGISTRO DE APROBACIONES', 0, 1, 'L', true);
$acta->SetFont('Helvetica', '', 7);
$acta->SetTextColor(85, 101, 92);
$acta->Cell(271, 5, 'Flujo requerido: ' . (int) $requisitos['regular'] . ' aprobaciones contables y ' . (int) $requisitos['final'] . ' aprobacion gerencial.', 0, 1, 'L');
if (!$aprobaciones) {
    $acta->SetFont('Helvetica', 'I', 8);
    $acta->Cell(271, 6, 'Aun no hay aprobaciones registradas.', 0, 1, 'L');
} else {
    foreach ($aprobaciones as $aprobacion) {
        if ($acta->GetY() > 265) {
            $acta->AddPage();
        }
        $acta->SetDrawColor(211, 225, 216);
        $acta->SetTextColor(24, 65, 48);
        $acta->SetFont('Helvetica', 'B', 8);
        $tipoAprobacion = (int) $aprobacion['es_final'] === 1 ? 'APROBADOR FINAL' : 'APROBADOR REGULAR';
        $acta->Cell(115, 6, texto_pdf_fpdf($tipoAprobacion . ': ' . $aprobacion['nombre']), 0, 0, 'L');
        $acta->SetFont('Helvetica', '', 7);
        $acta->SetTextColor(75, 89, 80);
        $acta->Cell(95, 6, 'Aprobacion registrada: ' . date('d/m/Y H:i', strtotime($aprobacion['fecha_aprobacion'])), 0, 0, 'L');
        $acta->Cell(61, 6, 'Firma electronica registrada', 0, 1, 'R');
        $acta->SetDrawColor(211, 225, 216);
        $acta->Line(13, $acta->GetY(), 284, $acta->GetY());
    }
}
$pdf = $acta->Output('S');

$rutaActaTemporal = tempnam(sys_get_temp_dir(), 'cismedic_acta_');
if ($rutaActaTemporal === false || file_put_contents($rutaActaTemporal, $pdf, LOCK_EX) === false) {
    http_response_code(500);
    exit('No se pudo preparar el acta del grupo.');
}

try {
    $documentoFinal = new \setasign\Fpdi\Fpdi();
    $documentoFinal->SetAutoPageBreak(false);
    $paginasActa = $documentoFinal->setSourceFile($rutaActaTemporal);
    for ($numeroPagina = 1; $numeroPagina <= $paginasActa; $numeroPagina++) {
        $plantilla = $documentoFinal->importPage($numeroPagina);
        $tamano = $documentoFinal->getTemplateSize($plantilla);
        $documentoFinal->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
        $documentoFinal->useTemplate($plantilla);
    }

    foreach ($anexos as $anexo) {
        if ($anexo['mime'] === 'application/pdf') {
            try {
                $cantidadPaginasAnexo = $documentoFinal->setSourceFile($anexo['ruta']);
                for ($numeroPagina = 1; $numeroPagina <= $cantidadPaginasAnexo; $numeroPagina++) {
                    $plantilla = $documentoFinal->importPage($numeroPagina);
                    $tamano = $documentoFinal->getTemplateSize($plantilla);
                    $documentoFinal->AddPage($tamano['orientation'], [$tamano['width'], $tamano['height']]);
                    $documentoFinal->useTemplate($plantilla);
                }
            } catch (Throwable $e) {
                error_log('No se pudo anexar el PDF ' . basename($anexo['ruta']) . ': ' . $e->getMessage());
            }
            continue;
        }

        $imagen = false;
        if ($anexo['mime'] === 'image/jpeg') {
            $imagen = @imagecreatefromjpeg($anexo['ruta']);
        } elseif ($anexo['mime'] === 'image/png') {
            $imagen = @imagecreatefrompng($anexo['ruta']);
        } elseif ($anexo['mime'] === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $imagen = @imagecreatefromwebp($anexo['ruta']);
        }
        if (!$imagen) {
            continue;
        }

        $rutaImagenTemporal = tempnam(sys_get_temp_dir(), 'cismedic_img_');
        if ($rutaImagenTemporal !== false) {
            imagejpeg($imagen, $rutaImagenTemporal, 88);
            $anchoImagen = imagesx($imagen);
            $altoImagen = imagesy($imagen);
            imagedestroy($imagen);
            $documentoFinal->AddPage('P', 'A4');
            $margen = 10;
            $anchoDisponible = 210 - (2 * $margen);
            $altoDisponible = 277;
            $escala = min($anchoDisponible / $anchoImagen, $altoDisponible / $altoImagen);
            $anchoRender = $anchoImagen * $escala;
            $altoRender = $altoImagen * $escala;
            $documentoFinal->SetFont('Helvetica', '', 8);
            $documentoFinal->SetTextColor(45, 75, 60);
            $documentoFinal->SetXY($margen, 8);
            $documentoFinal->Cell(190, 5, iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', 'Comprobante: ' . $anexo['documento'] . ' - ' . $anexo['nombre']), 0, 1, 'L');
            $documentoFinal->Image($rutaImagenTemporal, (210 - $anchoRender) / 2, 15 + (277 - $altoRender) / 2, $anchoRender, $altoRender, 'JPG');
            @unlink($rutaImagenTemporal);
        } else {
            imagedestroy($imagen);
        }
    }

    $pdfFinal = $documentoFinal->Output('S');
} catch (Throwable $e) {
    error_log('Error al generar acta/anexos del grupo ' . (int) $grupo['id'] . ': ' . $e->getMessage());
    http_response_code(500);
    exit('No se pudo generar el PDF con los comprobantes anexos.');
} finally {
    @unlink($rutaActaTemporal);
}

$nombreArchivo = 'acta_aprobacion_grupo_' . (int) $grupo['id'] . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $nombreArchivo . '"');
header('Content-Length: ' . strlen($pdfFinal));
header('X-Content-Type-Options: nosniff');
echo $pdfFinal;