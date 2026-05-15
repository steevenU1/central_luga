<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
date_default_timezone_set('America/Mexico_City');

if (!isset($_SESSION['id_usuario'])) {
    header("Location: 403.php");
    exit();
}

$ROL = trim((string)($_SESSION['rol'] ?? ''));
if ($ROL !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

/* =========================================================
   HELPERS
========================================================= */
if (!function_exists('h')) {
    function h($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('fmtDate')) {
    function fmtDate($f): string
    {
        if (empty($f) || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}

if (!function_exists('fmtDateTime')) {
    function fmtDateTime($f): string
    {
        if (empty($f) || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '—';
        $ts = strtotime($f);
        return $ts ? date('d/m/Y H:i', $ts) : '—';
    }
}

if (!function_exists('money')) {
    function money($n): string
    {
        return '$' . number_format((float)$n, 2);
    }
}

if (!function_exists('buildCode')) {
    function buildCode(string $empresa = 'LUGA'): string
    {
        $empresa = strtoupper(trim($empresa));
        $empresa = preg_replace('/[^A-Z0-9]/', '', $empresa);
        if ($empresa === '') $empresa = 'LUGA';

        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $rand = '';
        for ($i = 0; $i < 6; $i++) {
            $rand .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return 'CC-' . $empresa . '-' . $rand;
    }
}

if (!function_exists('badgeCodigoClass')) {
    function badgeCodigoClass(string $r): string
    {
        $r = strtoupper(trim($r));
        return match ($r) {
            'USADO'      => 'bg-success-subtle text-success border border-success-subtle',
            'GENERADO'   => 'bg-warning-subtle text-warning border border-warning-subtle',
            'ENVIADO'    => 'bg-primary-subtle text-primary border border-primary-subtle',
            'VENCIDO'    => 'bg-danger-subtle text-danger border border-danger-subtle',
            'CANCELADO'  => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            default      => 'bg-light text-dark border'
        };
    }
}

/* =========================================================
   INPUT
========================================================= */
$idVenta = (int)($_GET['id_venta'] ?? $_POST['id_venta'] ?? 0);
if ($idVenta <= 0) {
    die('Venta no válida.');
}

$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);

/* =========================================================
   OBTENER VENTA
========================================================= */
$sqlVenta = "
SELECT
    v.id,
    v.tag,
    v.nombre_cliente,
    v.telefono_cliente,
    v.fecha_venta,
    v.primer_pago,
    v.plazo_semanas,
    v.financiera,
    v.tipo_venta,
    v.precio_venta,
    v.id_sucursal,
    s.nombre AS sucursal_nombre,
    u.nombre AS ejecutivo_nombre
FROM ventas v
LEFT JOIN sucursales s ON s.id = v.id_sucursal
LEFT JOIN usuarios u   ON u.id = v.id_usuario
WHERE v.id = ?
LIMIT 1
";
$stmtVenta = $conn->prepare($sqlVenta);
if (!$stmtVenta) {
    die('Error preparando venta: ' . $conn->error);
}
$stmtVenta->bind_param('i', $idVenta);
$stmtVenta->execute();
$resVenta = $stmtVenta->get_result();
$venta = $resVenta->fetch_assoc();

if (!$venta) {
    die('No se encontró la venta.');
}

/* =========================================================
   CÓDIGO ACTIVO EXISTENTE
========================================================= */
$sqlActivo = "
SELECT *
FROM callcenter_codigos
WHERE id_venta_origen = ?
  AND estatus IN ('GENERADO', 'ENVIADO')
ORDER BY id DESC
LIMIT 1
";
$stmtActivo = $conn->prepare($sqlActivo);
if (!$stmtActivo) {
    die('Error preparando consulta de código activo: ' . $conn->error);
}
$stmtActivo->bind_param('i', $idVenta);
$stmtActivo->execute();
$resActivo = $stmtActivo->get_result();
$codigoActivo = $resActivo->fetch_assoc();

/* =========================================================
   MENSAJES
========================================================= */
$ok = '';
$error = '';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $ok = 'Código generado correctamente.';
}

/* =========================================================
   PROCESAR FORMULARIO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campana         = trim((string)($_POST['campana'] ?? 'Recompra Call Center'));
    $tipoBeneficio   = trim((string)($_POST['tipo_beneficio'] ?? 'DESCUENTO_FIJO'));
    $valorBeneficio  = (float)($_POST['valor_beneficio'] ?? 0);
    $diasVigencia    = (int)($_POST['dias_vigencia'] ?? 15);
    $observaciones   = trim((string)($_POST['observaciones'] ?? ''));

    $tiposValidos = ['DESCUENTO_FIJO','DESCUENTO_PORCENTAJE','PRECIO_ESPECIAL','BONO_ACCESORIO','OTRO'];
    if (!in_array($tipoBeneficio, $tiposValidos, true)) {
        $tipoBeneficio = 'DESCUENTO_FIJO';
    }

    if ($campana === '') {
        $error = 'La campaña es obligatoria.';
    } elseif ($diasVigencia <= 0 || $diasVigencia > 120) {
        $error = 'La vigencia debe estar entre 1 y 120 días.';
    } elseif ($valorBeneficio < 0) {
        $error = 'El valor del beneficio no puede ser negativo.';
    } else {
        /* Revalidar por seguridad si ya existe uno activo */
        $stmtActivo2 = $conn->prepare("
            SELECT id, codigo, estatus
            FROM callcenter_codigos
            WHERE id_venta_origen = ?
              AND estatus IN ('GENERADO', 'ENVIADO')
            ORDER BY id DESC
            LIMIT 1
        ");
        if (!$stmtActivo2) {
            $error = 'Error preparando validación de código activo: ' . $conn->error;
        } else {
            $stmtActivo2->bind_param('i', $idVenta);
            $stmtActivo2->execute();
            $resActivo2 = $stmtActivo2->get_result();
            $activo2 = $resActivo2->fetch_assoc();

            if ($activo2) {
                $error = 'Ya existe un código activo para esta venta: ' . $activo2['codigo'];
            } else {
                $empresaCodigo = 'LUGA';
                $codigo = '';

                /* Intentar generar código único */
                for ($intento = 1; $intento <= 10; $intento++) {
                    $codigo = buildCode($empresaCodigo);

                    $stmtCheck = $conn->prepare("SELECT id FROM callcenter_codigos WHERE codigo = ? LIMIT 1");
                    if (!$stmtCheck) {
                        $error = 'Error preparando validación de código único: ' . $conn->error;
                        break;
                    }
                    $stmtCheck->bind_param('s', $codigo);
                    $stmtCheck->execute();
                    $resCheck = $stmtCheck->get_result();
                    if ($resCheck->num_rows === 0) {
                        break;
                    }
                    $codigo = '';
                }

                if ($error === '' && $codigo === '') {
                    $error = 'No fue posible generar un código único. Intenta nuevamente.';
                }

                if ($error === '') {
                    $fechaVigencia = date('Y-m-d H:i:s', strtotime('+' . $diasVigencia . ' days'));

                    $sqlInsert = "
                    INSERT INTO callcenter_codigos (
                        codigo,
                        id_venta_origen,
                        id_cliente,
                        nombre_cliente,
                        telefono_cliente,
                        id_sucursal_origen,
                        id_usuario_callcenter,
                        campana,
                        tipo_beneficio,
                        valor_beneficio,
                        fecha_generado,
                        fecha_vigencia,
                        estatus,
                        observaciones,
                        created_at,
                        updated_at
                    ) VALUES (
                        ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'GENERADO', ?, NOW(), NOW()
                    )
                    ";

                    $stmtInsert = $conn->prepare($sqlInsert);
                    if (!$stmtInsert) {
                        $error = 'Error preparando inserción: ' . $conn->error;
                    } else {
                        $nombreCliente = (string)($venta['nombre_cliente'] ?? '');
                        $telefonoCliente = (string)($venta['telefono_cliente'] ?? '');
                        $idSucursalOrigen = (int)($venta['id_sucursal'] ?? 0);

                        $stmtInsert->bind_param(
                            'ssssiissdss',
                            $codigo,
                            $idVenta,
                            $nombreCliente,
                            $telefonoCliente,
                            $idSucursalOrigen,
                            $idUsuarioSesion,
                            $campana,
                            $tipoBeneficio,
                            $valorBeneficio,
                            $fechaVigencia,
                            $observaciones
                        );

                        if ($stmtInsert->execute()) {
                            header("Location: callcenter_generar_codigo.php?id_venta=" . $idVenta . "&ok=1");
                            exit();
                        } else {
                            $error = 'No se pudo guardar el código: ' . $stmtInsert->error;
                        }
                    }
                }
            }
        }
    }
}

/* =========================================================
   HISTORIAL DE CÓDIGOS
========================================================= */
$historial = [];
$sqlHist = "
SELECT
    id,
    codigo,
    campana,
    tipo_beneficio,
    valor_beneficio,
    fecha_generado,
    fecha_vigencia,
    estatus,
    observaciones
FROM callcenter_codigos
WHERE id_venta_origen = ?
ORDER BY id DESC
";
$stmtHist = $conn->prepare($sqlHist);
if ($stmtHist) {
    $stmtHist->bind_param('i', $idVenta);
    $stmtHist->execute();
    $resHist = $stmtHist->get_result();
    while ($row = $resHist->fetch_assoc()) {
        $historial[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar código Call Center</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background:#f6f8fb;
        }
        .page-wrap{
            padding:24px;
        }
        .soft-card{
            border:0;
            border-radius:18px;
            box-shadow:0 8px 24px rgba(16,24,40,.06);
        }
        .hero-card{
            border:0;
            border-radius:18px;
            background:linear-gradient(135deg,#0d6efd 0%,#3d8bfd 100%);
            color:#fff;
            box-shadow:0 10px 30px rgba(13,110,253,.15);
        }
        .pill-soft{
            display:inline-flex;
            align-items:center;
            gap:6px;
            border-radius:999px;
            padding:.38rem .68rem;
            font-size:.79rem;
            font-weight:600;
            white-space:nowrap;
        }
        .label-soft{
            color:#667085;
            font-size:.83rem;
        }
        .value-strong{
            font-weight:700;
            color:#101828;
        }
        .table td, .table th{
            vertical-align:middle;
        }
    </style>
</head>
<body>

<?php include __DIR__ . '/navbar.php'; ?>

<div class="page-wrap container-fluid">

    <div class="card hero-card mb-4">
        <div class="card-body p-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h2 class="mb-1 fw-bold">
                    <i class="bi bi-ticket-perforated me-2"></i>Generar código Call Center
                </h2>
                <div class="opacity-75">Crea un código único de recompra asociado a una venta.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="callcenter_clientes.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </a>
            </div>
        </div>
    </div>

    <?php if ($ok !== ''): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i><?= h($ok) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <div class="col-lg-5">
            <div class="card soft-card h-100">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Datos de la venta</h5>

                    <div class="mb-3">
                        <div class="label-soft">Cliente</div>
                        <div class="value-strong"><?= h($venta['nombre_cliente'] ?: '—') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-soft">Teléfono</div>
                        <div class="value-strong"><?= h($venta['telefono_cliente'] ?: '—') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-soft">TAG</div>
                        <div class="value-strong"><?= h($venta['tag'] ?: '—') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-soft">Sucursal</div>
                        <div class="value-strong"><?= h($venta['sucursal_nombre'] ?: '—') ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="label-soft">Ejecutivo</div>
                        <div class="value-strong"><?= h($venta['ejecutivo_nombre'] ?: '—') ?></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="label-soft">Fecha venta</div>
                            <div class="value-strong"><?= fmtDate($venta['fecha_venta']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="label-soft">Primer pago</div>
                            <div class="value-strong"><?= fmtDate($venta['primer_pago']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="label-soft">Financiera</div>
                            <div class="value-strong"><?= h($venta['financiera'] ?: '—') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="label-soft">Tipo venta</div>
                            <div class="value-strong"><?= h($venta['tipo_venta'] ?: '—') ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="label-soft">Plazo</div>
                            <div class="value-strong"><?= (int)($venta['plazo_semanas'] ?? 0) ?> semanas</div>
                        </div>
                        <div class="col-md-6">
                            <div class="label-soft">Precio venta</div>
                            <div class="value-strong"><?= money($venta['precio_venta'] ?? 0) ?></div>
                        </div>
                    </div>

                    <?php if ($codigoActivo): ?>
                        <hr>
                        <div class="alert alert-warning border-0 mb-0">
                            <div class="fw-bold mb-1">Ya existe un código activo</div>
                            <div>Código: <strong><?= h($codigoActivo['codigo']) ?></strong></div>
                            <div>Estatus: <strong><?= h($codigoActivo['estatus']) ?></strong></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card soft-card">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Nuevo código</h5>

                    <?php if ($codigoActivo): ?>
                        <div class="alert alert-info border-0">
                            Esta venta ya tiene un código activo. Si necesitas otro, primero habría que cancelar o consumir el actual.
                        </div>
                    <?php else: ?>
                        <form method="POST" novalidate>
                            <input type="hidden" name="id_venta" value="<?= (int)$idVenta ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Campaña</label>
                                    <input
                                        type="text"
                                        name="campana"
                                        class="form-control"
                                        value="<?= h($_POST['campana'] ?? 'Recompra Call Center') ?>"
                                        maxlength="100"
                                        required
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Tipo de beneficio</label>
                                    <select name="tipo_beneficio" class="form-select" required>
                                        <?php
                                        $tipoSel = $_POST['tipo_beneficio'] ?? 'DESCUENTO_FIJO';
                                        $tipos = [
                                            'DESCUENTO_FIJO' => 'Descuento fijo',
                                            'DESCUENTO_PORCENTAJE' => 'Descuento porcentaje',
                                            'PRECIO_ESPECIAL' => 'Precio especial',
                                            'BONO_ACCESORIO' => 'Bono accesorio',
                                            'OTRO' => 'Otro'
                                        ];
                                        foreach ($tipos as $k => $label):
                                        ?>
                                            <option value="<?= h($k) ?>" <?= ($tipoSel === $k) ? 'selected' : '' ?>>
                                                <?= h($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Valor beneficio</label>
                                    <input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        name="valor_beneficio"
                                        class="form-control"
                                        value="<?= h($_POST['valor_beneficio'] ?? '0') ?>"
                                        required
                                    >
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Vigencia (días)</label>
                                    <input
                                        type="number"
                                        min="1"
                                        max="120"
                                        name="dias_vigencia"
                                        class="form-control"
                                        value="<?= h($_POST['dias_vigencia'] ?? '15') ?>"
                                        required
                                    >
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Empresa código</label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        value="LUGA"
                                        disabled
                                    >
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Observaciones</label>
                                    <textarea
                                        name="observaciones"
                                        class="form-control"
                                        rows="3"
                                        placeholder="Notas internas del código o de la promoción"
                                    ><?= h($_POST['observaciones'] ?? '') ?></textarea>
                                </div>

                                <div class="col-12 d-flex justify-content-end gap-2 pt-2">
                                    <a href="callcenter_clientes.php" class="btn btn-outline-secondary">
                                        Cancelar
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-ticket-perforated me-1"></i>Generar código
                                    </button>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card soft-card mt-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Historial de códigos</h5>

                    <?php if (empty($historial)): ?>
                        <div class="text-muted">Todavía no hay códigos generados para esta venta.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Código</th>
                                        <th>Campaña</th>
                                        <th>Beneficio</th>
                                        <th>Generado</th>
                                        <th>Vigencia</th>
                                        <th>Estatus</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($historial as $c): ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h($c['codigo']) ?></td>
                                        <td><?= h($c['campana']) ?></td>
                                        <td>
                                            <?= h($c['tipo_beneficio']) ?>
                                            <div class="text-muted small"><?= number_format((float)($c['valor_beneficio'] ?? 0), 2) ?></div>
                                        </td>
                                        <td><?= fmtDateTime($c['fecha_generado']) ?></td>
                                        <td><?= fmtDateTime($c['fecha_vigencia']) ?></td>
                                        <td>
                                            <span class="pill-soft <?= badgeCodigoClass((string)$c['estatus']) ?>">
                                                <?= h($c['estatus']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php if (!empty($c['observaciones'])): ?>
                                        <tr>
                                            <td colspan="6" class="text-muted small">
                                                <strong>Obs:</strong> <?= h($c['observaciones']) ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

    </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>