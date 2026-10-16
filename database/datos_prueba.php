<?php
// Carga datos de prueba en la base local. Uso: php database/datos_prueba.php
// Borra los datos de prueba anteriores (todo excepto usuarios y categorías globales).
if (PHP_SAPI !== 'cli') {
    exit('Solo desde la línea de comandos.');
}
require __DIR__ . '/../config/database.php';
mt_srand(42);

require __DIR__ . '/../config/firma_digital.php';
inicializar_tablas_firma($pdo);
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['firmas_grupos_egresos', 'expedientes_firma_grupos', 'historial_grupos_rechazados', 'aprobacion_grupos_egresos', 'aprobadores_egresos', 'comprobantes_egresos',
          'detalle_almacenamiento_egresos', 'almacenamientos_egresos', 'entregas_proveedores', 'entregas_dinero',
          'detalle_factura', 'facturas', 'personal_clinica', 'categorias_empleado', 'categoria_global_subcategoria',
          'categorias', 'proveedores'] as $t) {
    $pdo->exec("TRUNCATE TABLE $t");
}
$pdo->exec("DELETE FROM usuarios WHERE correo LIKE '%@cismedic.test' AND correo <> 'admin@cismedic.test'");
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

// Usuarios: misma contraseña que el admin de prueba
$hashAdmin = $pdo->query("SELECT `contraseña` FROM usuarios WHERE correo = 'admin@cismedic.test'")->fetchColumn();
$idAdmin = (int) $pdo->query("SELECT id FROM usuarios WHERE correo = 'admin@cismedic.test'")->fetchColumn();
$usuarios = [
    ['María Caja', 'caja@cismedic.test', 'caja'],
    ['Jorge Administrativo', 'administrativo@cismedic.test', 'administrativo'],
    ['Lucía Gerencia', 'gerencia@cismedic.test', 'superadmin'],
];
$ins = $pdo->prepare('INSERT INTO usuarios (nombre, correo, `contraseña`, rol, activo) VALUES (?, ?, ?, ?, 1)');
$ids = [];
foreach ($usuarios as [$n, $c, $r]) {
    $ins->execute([$n, $c, $hashAdmin, $r]);
    $ids[$r] = (int) $pdo->lastInsertId();
}
$idCaja = $ids['caja'];
$idGerencia = $ids['superadmin'];

// Aprobadores: administrativo (intermedio) y gerencia (final)
$pdo->prepare('INSERT INTO aprobadores_egresos (id_usuario, activo, es_final) VALUES (?, 1, 0), (?, 1, 1)')
    ->execute([$ids['administrativo'], $idGerencia]);

// Proveedores
$proveedores = [
    ['Distribuidora Médica del Norte SAC', '20481234561', 'Av. Grau 450, Piura', 'Carlos Ruiz', 'ventas@dimenorte.pe'],
    ['Laboratorios Andinos SA', '20100234872', 'Jr. Lima 120, Lima', 'Ana Torres', 'pedidos@labandinos.pe'],
    ['Electronorte SA', '20103117560', 'Calle Callao 875, Chiclayo', 'Atención al cliente', 'clientes@electronorte.pe'],
    ['EPS Grau SA', '20102851251', 'Av. Sullana 1050, Piura', 'Atención al cliente', 'contacto@epsgrau.pe'],
    ['TecnoSalud Equipos EIRL', '20601547893', 'Av. Sánchez Cerro 2100, Piura', 'Luis Paredes', 'lparedes@tecnosalud.pe'],
    ['Limpieza Total SAC', '20556677881', 'Mz. B Lt. 5, Castilla', 'Rosa Vílchez', 'rvilchez@limpiezatotal.pe'],
    ['Soluciones Informáticas Piura', '20605544332', 'Av. Loreto 330, Piura', 'Diego Castro', 'soporte@sipiura.pe'],
    ['Oxígeno Medicinal del Pacífico', '20498877665', 'Zona Industrial Lt. 12, Paita', 'Pedro Zapata', 'ventas@oxipacifico.pe'],
];
$ins = $pdo->prepare('INSERT INTO proveedores (nombre, ruc, direccion, contacto, email, responsable) VALUES (?, ?, ?, ?, ?, ?)');
foreach ($proveedores as $p) {
    $ins->execute([...$p, $p[3]]);
}

// Subcategorías ligadas a categorías globales
$globales = $pdo->query('SELECT nombre, id FROM categorias_globales')->fetchAll(PDO::FETCH_KEY_PAIR);
$subcategorias = [
    'PAGO PERSONAL' => ['Planilla enfermería', 'Horas extra'],
    'PAGO ESPECIALISTAS' => ['Honorarios médicos', 'Guardias'],
    'SERVICIOS BASICOS' => ['Luz', 'Agua', 'Internet y telefonía'],
    'PAGO TERCEROS' => ['Limpieza', 'Seguridad'],
    'PAGO INFORMATICOS' => ['Soporte de sistemas'],
    'ALMACEN MEDICO' => ['Medicamentos', 'Material quirúrgico', 'Oxígeno medicinal'],
    'EQUIPAMIENTO VARIOS' => ['Equipos médicos', 'Mobiliario'],
    'OTROS PAGOS' => ['Movilidad', 'Útiles de oficina'],
];
$insCat = $pdo->prepare('INSERT INTO categorias (id_categoria_global, nombre) VALUES (?, ?)');
$insRel = $pdo->prepare('INSERT INTO categoria_global_subcategoria (id_categoria_global, id_subcategoria) VALUES (?, ?)');
$cat = [];
foreach ($subcategorias as $global => $nombres) {
    foreach ($nombres as $nombre) {
        $insCat->execute([$globales[$global] ?? null, $nombre]);
        $cat[$nombre] = (int) $pdo->lastInsertId();
        if (isset($globales[$global])) {
            $insRel->execute([$globales[$global], $cat[$nombre]]);
        }
    }
}

// Personal
foreach (['Enfermería', 'Médicos', 'Administrativo', 'Técnicos'] as $n) {
    $pdo->prepare('INSERT INTO categorias_empleado (nombre) VALUES (?)')->execute([$n]);
}
$personal = [
    ['Rosa', 'Chávez Morales', '45781236', 'interno', null, 1, 'Enfermera jefe'],
    ['Luis', 'Sandoval Rivas', '41236578', 'interno', null, 2, 'Médico cirujano'],
    ['Carmen', 'Alvarado Peña', '47851239', 'interno', null, 1, 'Enfermera'],
    ['Miguel', 'Herrera Quispe', '43215678', 'externo', 'Clínica San Miguel', 2, 'Cardiólogo'],
    ['Patricia', 'Flores Ramos', '46598712', 'interno', null, 3, 'Asistente administrativa'],
    ['José', 'Mendoza Silva', '42157896', 'interno', null, 4, 'Técnico de laboratorio'],
    ['Sofía', 'Castillo Vega', '48523697', 'externo', 'Imagen Diagnóstica SAC', 2, 'Radióloga'],
    ['Raúl', 'Paz Córdova', '44789632', 'interno', null, 4, 'Técnico de farmacia'],
];
$ins = $pdo->prepare('INSERT INTO personal_clinica (nombre, apellido, dni, tipo_personal, empresa, id_categoria, cargo) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($personal as $p) {
    $ins->execute($p);
}

// Egresos de los últimos 6 meses
function fecha_aleatoria(int $diasAtras): string
{
    return date('Y-m-d', strtotime('-' . mt_rand(0, $diasAtras) . ' days'));
}
$catPersonal = [$cat['Planilla enfermería'], $cat['Horas extra'], $cat['Honorarios médicos'], $cat['Guardias'], $cat['Movilidad']];
$catProveedor = [
    1 => $cat['Medicamentos'], 2 => $cat['Medicamentos'], 3 => $cat['Luz'], 4 => $cat['Agua'],
    5 => $cat['Equipos médicos'], 6 => $cat['Limpieza'], 7 => $cat['Soporte de sistemas'], 8 => $cat['Oxígeno medicinal'],
];
$conceptos = [
    'Pago de guardia nocturna', 'Reembolso de movilidad', 'Honorarios por consulta', 'Horas extra fin de semana',
    'Apoyo en sala de operaciones', 'Pago por turno adicional',
];

$insP = $pdo->prepare('INSERT INTO entregas_dinero (id_personal, id_usuario, id_categoria, tipo_comprobante, nComprobante, fecha_entrega, monto, descripcion, fecha_registro, estado_aprobacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 30; $i++) {
    $f = fecha_aleatoria(180);
    $tipo = ['RECIBO', 'RHE', 'RECIBO DE EGRESO'][mt_rand(0, 2)];
    $num = $tipo === 'RHE' ? sprintf('E001-%d', 100 + $i) : sprintf('R%06d', 1000 + $i);
    $insP->execute([mt_rand(1, 8), $idCaja, $catPersonal[mt_rand(0, 4)], $tipo, $num, $f,
        mt_rand(8, 120) * 10, $conceptos[mt_rand(0, 5)], "$f 10:00:00", 'pendiente']);
}

$insV = $pdo->prepare('INSERT INTO entregas_proveedores (id_proveedor, id_usuario, id_categoria, tipo_comprobante, nComprobante, fecha_entrega, monto, descripcion, fecha_registro, estado_aprobacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 30; $i++) {
    $f = fecha_aleatoria(180);
    $prov = mt_rand(1, 8);
    $tipo = mt_rand(0, 4) ? 'FACTURA' : 'BOLETA';
    $num = sprintf('%s001-%08d', $tipo === 'FACTURA' ? 'F' : 'B', 2000 + $i);
    $insV->execute([$prov, $idCaja, $catProveedor[$prov], $tipo, $num, $f,
        mt_rand(15, 800) * 10, 'Pago a ' . $proveedores[$prov - 1][0], "$f 11:00:00", 'pendiente']);
}

// Facturas con detalle
$productos = [
    ['Paracetamol 500mg x100', $cat['Medicamentos'], 18.50], ['Guantes de nitrilo caja x100', $cat['Material quirúrgico'], 32.00],
    ['Jeringa 5ml x100', $cat['Material quirúrgico'], 25.00], ['Amoxicilina 500mg x100', $cat['Medicamentos'], 45.00],
    ['Oxímetro de pulso', $cat['Equipos médicos'], 120.00], ['Silla de ruedas', $cat['Mobiliario'], 650.00],
    ['Balón de oxígeno 10m3', $cat['Oxígeno medicinal'], 180.00], ['Papel bond A4 millar', $cat['Útiles de oficina'], 28.00],
];
$insF = $pdo->prepare('INSERT INTO facturas (tipo_comprobante, numero_comprobante, descripcion, id_proveedor, id_usuario, fecha, total, igv) VALUES (?, ?, ?, ?, ?, ?, 0, 0)');
$insD = $pdo->prepare('INSERT INTO detalle_factura (id_factura, id_categoria, producto, cantidad, precio_unitario, subtotal, igv, subtotal_con_igv) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$updF = $pdo->prepare('UPDATE facturas SET total = ?, igv = ? WHERE id = ?');
for ($i = 1; $i <= 15; $i++) {
    $prov = [1, 2, 5, 8][mt_rand(0, 3)];
    $insF->execute(['FACTURA', sprintf('F001-%08d', 5000 + $i), 'Compra de insumos', $prov, $idAdmin, fecha_aleatoria(180)]);
    $idF = (int) $pdo->lastInsertId();
    $total = $igvTotal = 0;
    for ($j = 0, $n = mt_rand(1, 4); $j < $n; $j++) {
        [$prod, $idCat, $precio] = $productos[mt_rand(0, 7)];
        $cant = mt_rand(1, 20);
        $sub = round($cant * $precio, 4);
        $igv = round($sub * 0.18, 4);
        $insD->execute([$idF, $idCat, $prod, $cant, $precio, $sub, $igv, $sub + $igv]);
        $total += $sub + $igv;
        $igvTotal += $igv;
    }
    $updF->execute([$total, $igvTotal, $idF]);
}

// Grupos de almacenamiento: uno aprobado, uno a medio aprobar, uno pendiente
function crear_grupo(PDO $pdo, string $nombre, string $estado, int $idUsuario, array $personal, array $proveedores): int
{
    $pdo->prepare('INSERT INTO almacenamientos_egresos (nombre, fecha, id_usuario_registra, observacion, estado_aprobacion) VALUES (?, CURDATE(), ?, ?, ?)')
        ->execute([$nombre, $idUsuario, 'Grupo generado con datos de prueba', $estado]);
    $idG = (int) $pdo->lastInsertId();
    $total = 0;
    $insDet = $pdo->prepare('INSERT INTO detalle_almacenamiento_egresos (id_almacenamiento, fuente_egreso, id_egreso, fecha, documento, proveedor_responsable, descripcion, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $estadoEgreso = $estado === 'aprobado' ? 'aprobado' : 'en_aprobacion';
    foreach ($personal as $id) {
        $e = $pdo->query("SELECT ed.*, CONCAT(pc.nombre, ' ', pc.apellido) AS resp FROM entregas_dinero ed JOIN personal_clinica pc ON pc.id_personal = ed.id_personal WHERE id_entrega = $id")->fetch();
        $insDet->execute([$idG, 'personal', $id, $e['fecha_entrega'], $e['tipo_comprobante'] . ' ' . $e['nComprobante'], $e['resp'], $e['descripcion'], $e['monto']]);
        $pdo->exec("UPDATE entregas_dinero SET estado_aprobacion = '$estadoEgreso' WHERE id_entrega = $id");
        $total += $e['monto'];
    }
    foreach ($proveedores as $id) {
        $e = $pdo->query("SELECT ep.*, p.nombre AS resp FROM entregas_proveedores ep JOIN proveedores p ON p.id = ep.id_proveedor WHERE id_entrega_proveedor = $id")->fetch();
        $insDet->execute([$idG, 'proveedores', $id, $e['fecha_entrega'], $e['tipo_comprobante'] . ' ' . $e['nComprobante'], $e['resp'], $e['descripcion'], $e['monto']]);
        $pdo->exec("UPDATE entregas_proveedores SET estado_aprobacion = '$estadoEgreso' WHERE id_entrega_proveedor = $id");
        $total += $e['monto'];
    }
    $pdo->prepare('UPDATE almacenamientos_egresos SET total = ? WHERE id = ?')->execute([$total, $idG]);
    return $idG;
}
$g1 = crear_grupo($pdo, 'Pagos primera quincena', 'aprobado', $idCaja, [1, 2, 3, 4], [1, 2, 3]);
$g2 = crear_grupo($pdo, 'Pagos segunda quincena', 'en_aprobacion', $idCaja, [5, 6, 7], [4, 5]);
crear_grupo($pdo, 'Compras de almacén', 'pendiente', $idCaja, [], [6, 7, 8, 9]);
$apr = $pdo->prepare('INSERT INTO aprobacion_grupos_egresos (id_almacenamiento, id_usuario, es_final) VALUES (?, ?, ?)');
$apr->execute([$g1, $ids['administrativo'], 0]);
$apr->execute([$g1, $idGerencia, 1]);
$apr->execute([$g2, $ids['administrativo'], 0]);

echo "Datos de prueba cargados.\n";
