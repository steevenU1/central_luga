<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';
include 'navbar.php';

// 🔹 Función para obtener nombre de mes en español
function nombreMes($mes) {
    $meses = [
        1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio',
        7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'
    ];
    return $meses[$mes] ?? '';
}

// 🔹 Mes y año seleccionados
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : date('n');
$anio = isset($_GET['anio']) ? (int)$_GET['anio'] : date('Y');

// 🔹 Rango de fechas del mes
$inicioMes = "$anio-$mes-01";
$finMes = date("Y-m-t", strtotime($inicioMes)); // último día del mes

// ============================
// 1️⃣ Obtener ventas por sucursal
// ============================
$sql = "
    SELECT s.id AS id_sucursal, s.nombre AS sucursal, s.zona,
           IFNULL(SUM(
                CASE 
                    WHEN dv.id IS NULL THEN 0
                    WHEN LOWER(p.tipo_producto) IN ('modem','mifi') THEN 0
                    WHEN v.tipo_venta='Financiamiento+Combo' 
                         AND dv.id = (
                             SELECT MIN(dv2.id) FROM detalle_venta dv2 WHERE dv2.id_venta=v.id
                         )
                         THEN 2
                    ELSE 1
                END
           ),0) AS unidades,
           IFNULL(SUM(dv.precio_unitario),0) AS ventas
    FROM sucursales s
    LEFT JOIN ventas v 
        ON v.id_sucursal = s.id 
        AND DATE(CONVERT_TZ(v.fecha_venta,'+00:00','-06:00')) BETWEEN ? AND ?
    LEFT JOIN detalle_venta dv ON dv.id_venta = v.id
    LEFT JOIN productos p ON p.id = dv.id_producto
    WHERE s.tipo_sucursal='Tienda'
    GROUP BY s.id
    ORDER BY s.zona, s.nombre
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $inicioMes, $finMes);
$stmt->execute();
$res = $stmt->get_result();

$sucursales = [];
$totalGlobalUnidades = 0;
$totalGlobalVentas = 0;
$totalGlobalCuota = 0;

// ============================
// 2️⃣ Traer cuotas mensuales
// ============================
$cuotas = [];
$q = $conn->prepare("SELECT * FROM cuotas_mensuales WHERE anio=? AND mes=?");
$q->bind_param("ii", $anio, $mes);
$q->execute();
$r = $q->get_result();
while ($row = $r->fetch_assoc()) {
    $cuotas[$row['id_sucursal']] = $row;
}

// ============================
// 3️⃣ Armar array de sucursales
// ============================
while ($row = $res->fetch_assoc()) {
    $id_suc = $row['id_sucursal'];
    $cuotaUnidades = $cuotas[$id_suc]['cuota_unidades'] ?? 0;
    $cuotaMonto = $cuotas[$id_suc]['cuota_monto'] ?? 0;

    $cumplimiento = $cuotaMonto > 0 ? ($row['ventas']/$cuotaMonto*100) : 0;

    $sucursales[] = [
        'sucursal' => $row['sucursal'],
        'zona' => $row['zona'],
        'unidades' => (int)$row['unidades'],
        'ventas' => (float)$row['ventas'],
        'cuota_unidades' => (int)$cuotaUnidades,
        'cuota_monto' => (float)$cuotaMonto,
        'cumplimiento' => $cumplimiento
    ];

    $totalGlobalUnidades += $row['unidades'];
    $totalGlobalVentas += $row['ventas'];
    $totalGlobalCuota += $cuotaMonto;
}

// ============================
// 4️⃣ Agrupar por zona
// ============================
$zonas = [];
foreach ($sucursales as $s) {
    $zona = $s['zona'];
    if (!isset($zonas[$zona])) {
        $zonas[$zona] = ['unidades'=>0,'ventas'=>0,'cuota'=>0];
    }
    $zonas[$zona]['unidades'] += $s['unidades'];
    $zonas[$zona]['ventas'] += $s['ventas'];
    $zonas[$zona]['cuota'] += $s['cuota_monto'];
}

// ============================
// 5️⃣ Cálculo global
// ============================
$porcentajeGlobal = $totalGlobalCuota > 0 ? ($totalGlobalVentas/$totalGlobalCuota*100) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard Mensual</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>📊 Dashboard Mensual - <?= nombreMes($mes)." $anio" ?></h2>

    <!-- Filtros -->
    <form method="GET" class="row g-2 mb-4">
        <div class="col-md-2">
            <select name="mes" class="form-select">
                <?php for ($m=1;$m<=12;$m++): ?>
                    <option value="<?= $m ?>" <?= $m==$mes?'selected':'' ?>><?= nombreMes($m) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <select name="anio" class="form-select">
                <?php for ($a=date('Y')-1;$a<=date('Y')+1;$a++): ?>
                    <option value="<?= $a ?>" <?= $a==$anio?'selected':'' ?>><?= $a ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary">Filtrar</button>
        </div>
    </form>

    <!-- Tarjetas de Zonas y Global -->
    <div class="row mb-4">
        <?php foreach ($zonas as $zona => $info): 
            $cumpl = $info['cuota']>0 ? ($info['ventas']/$info['cuota']*100) : 0;
        ?>
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-dark text-white">
                    Zona <?= $zona ?>
                </div>
                <div class="card-body">
                    <h5><?= number_format($cumpl,1) ?>% Cumplimiento</h5>
                    <p>
                        Unidades: <?= $info['unidades'] ?><br>
                        Ventas: $<?= number_format($info['ventas'],2) ?><br>
                        Cuota: $<?= number_format($info['cuota'],2) ?>
                    </p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="col-md-4 mb-3">
            <div class="card shadow text-center">
                <div class="card-header bg-primary text-white">
                    🌎 Global Compañía
                </div>
                <div class="card-body">
                    <h5><?= number_format($porcentajeGlobal,1) ?>% Cumplimiento</h5>
                    <p>
                        Unidades: <?= $totalGlobalUnidades ?><br>
                        Ventas: $<?= number_format($totalGlobalVentas,2) ?><br>
                        Cuota: $<?= number_format($totalGlobalCuota,2) ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de Sucursales -->
    <div class="card shadow mb-4">
        <div class="card-header bg-primary text-white">Sucursales</div>
        <div class="card-body p-0">
            <table class="table table-bordered table-striped table-sm mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Sucursal</th><th>Zona</th><th>Unidades</th><th>Cuota Unid.</th>
                        <th>Ventas $</th><th>Cuota $</th><th>% Cumplimiento</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sucursales as $s): 
                        $fila = $s['cumplimiento']>=100?"table-success":($s['cumplimiento']>=60?"table-warning":"table-danger");
                        $estado = $s['cumplimiento']>=100?"✅":($s['cumplimiento']>=60?"⚠️":"❌");
                    ?>
                    <tr class="<?= $fila ?>">
                        <td><?= $s['sucursal'] ?></td>
                        <td><?= $s['zona'] ?></td>
                        <td><?= $s['unidades'] ?></td>
                        <td><?= $s['cuota_unidades'] ?></td>
                        <td>$<?= number_format($s['ventas'],2) ?></td>
                        <td>$<?= number_format($s['cuota_monto'],2) ?></td>
                        <td><?= round($s['cumplimiento'],1) ?>% <?= $estado ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

</body>
</html>
