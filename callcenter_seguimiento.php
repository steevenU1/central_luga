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

if (!function_exists('buildWhatsAppUrl')) {
    function buildWhatsAppUrl(string $telefono, string $mensaje = ''): string
    {
        $telefono = preg_replace('/\D+/', '', $telefono);
        if ($telefono === '') return '#';

        if (strlen($telefono) === 10) {
            $telefono = '52' . $telefono;
        }

        $base = 'https://wa.me/' . $telefono;
        if ($mensaje !== '') {
            $base .= '?text=' . rawurlencode($mensaje);
        }
        return $base;
    }
}

if (!function_exists('badgeResultadoClass')) {
    function badgeResultadoClass(string $r): string
    {
        $r = strtoupper(trim($r));
        return match ($r) {
            'INTERESADO'                => 'bg-success-subtle text-success border border-success-subtle',
            'CODIGO_ENVIADO'            => 'bg-primary-subtle text-primary border border-primary-subtle',
            'VISITA_TIENDA_PENDIENTE'   => 'bg-info-subtle text-info border border-info-subtle',
            'NO_INTERESADO'             => 'bg-danger-subtle text-danger border border-danger-subtle',
            'NO_CONTESTO'               => 'bg-warning-subtle text-warning border border-warning-subtle',
            'VOLVER_A_LLAMAR'           => 'bg-secondary-subtle text-secondary border border-secondary-subtle',
            'VENTA_CERRADA'             => 'bg-dark-subtle text-dark border border-dark-subtle',
            'SIN_CONTACTO'              => 'bg-light text-dark border',
            default                     => 'bg-light text-dark border'
        };
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
   ÚLTIMO CÓDIGO
========================================================= */
$codigoActual = null;
$sqlCodigo = "
SELECT
    id,
    codigo,
    campana,
    tipo_beneficio,
    valor_beneficio,
    fecha_generado,
    fecha_vigencia,
    estatus
FROM callcenter_codigos
WHERE id_venta_origen = ?
ORDER BY id DESC
LIMIT 1
";
$stmtCodigo = $conn->prepare($sqlCodigo);
if ($stmtCodigo) {
    $stmtCodigo->bind_param('i', $idVenta);
    $stmtCodigo->execute();
    $resCodigo = $stmtCodigo->get_result();
    $codigoActual = $resCodigo->fetch_assoc();
}

/* =========================================================
   MENSAJES
========================================================= */
$ok = '';
$error = '';

if (isset($_GET['ok']) && $_GET['ok'] === '1') {
    $ok = 'Seguimiento guardado correctamente.';
}

/* =========================================================
   PROCESAR FORMULARIO
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medioContacto = trim((string)($_POST['medio_contacto'] ?? 'WHATSAPP'));
    $resultado     = trim((string)($_POST['resultado'] ?? 'SIN_CONTACTO'));
    $comentarios   = trim((string)($_POST['comentarios'] ?? ''));
    $proximaAccion = trim((string)($_POST['proxima_accion'] ?? ''));
    $proximaFecha  = trim((string)($_POST['proxima_fecha'] ?? ''));
    $idCodigoGenerado = (int)($_POST['id_codigo_generado'] ?? 0);

    $mediosValidos = ['WHATSAPP', 'LLAMADA', 'SMS', 'OTRO'];
    $resultadosValidos = [
        'SIN_CONTACTO',
        'NO_CONTESTO',
        'INTERESADO',
        'NO_INTERESADO',
        'VOLVER_A_LLAMAR',
        'CODIGO_ENVIADO',
        'VISITA_TIENDA_PENDIENTE',
        'VENTA_CERRADA'
    ];

    if (!in_array($medioContacto, $mediosValidos, true)) {
        $medioContacto = 'WHATSAPP';
    }

    if (!in_array($resultado, $resultadosValidos, true)) {
        $resultado = 'SIN_CONTACTO';
    }

    if ($comentarios === '') {
        $error = 'Agrega un comentario del seguimiento.';
    } else {
        $proximaFechaSql = null;
        if ($proximaFecha !== '') {
            $ts = strtotime($proximaFecha);
            if ($ts === false) {
                $error = 'La próxima fecha no es válida.';
            } else {
                $proximaFechaSql = date('Y-m-d H:i:s', $ts);
            }
        }

        if ($error === '') {
            $sqlInsert = "
            INSERT INTO callcenter_seguimientos (
                id_venta,
                id_cliente,
                nombre_cliente,
                telefono,
                id_sucursal,
                id_usuario_callcenter,
                medio_contacto,
                resultado,
                comentarios,
                fecha_contacto,
                proxima_accion,
                proxima_fecha,
                id_codigo_generado,
                created_at
            ) VALUES (
                ?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, NOW()
            )
            ";

            $stmtInsert = $conn->prepare($sqlInsert);
            if (!$stmtInsert) {
                $error = 'Error preparando inserción: ' . $conn->error;
            } else {
                $nombreCliente = (string)($venta['nombre_cliente'] ?? '');
                $telefono      = (string)($venta['telefono_cliente'] ?? '');
                $idSucursal    = (int)($venta['id_sucursal'] ?? 0);
                $idCodigoFinal = $idCodigoGenerado > 0 ? $idCodigoGenerado : null;

                $stmtInsert->bind_param(
                    'issiisssssi',
                    $idVenta,
                    $nombreCliente,
                    $telefono,
                    $idSucursal,
                    $idUsuarioSesion,
                    $medioContacto,
                    $resultado,
                    $comentarios,
                    $proximaAccion,
                    $proximaFechaSql,
                    $idCodigoFinal
                );

                if ($stmtInsert->execute()) {
                    header("Location: callcenter_seguimiento.php?id_venta=" . $idVenta . "&ok=1");
                    exit();
                } else {
                    $error = 'No se pudo guardar el seguimiento: ' . $stmtInsert->error;
                }
            }
        }
    }
}

/* =========================================================
   HISTORIAL DE SEGUIMIENTOS
========================================================= */
$historial = [];
$sqlHist = "
SELECT
    cs.*,
    u.nombre AS usuario_nombre,
    cc.codigo AS codigo_relacionado
FROM callcenter_seguimientos cs
LEFT JOIN usuarios u
    ON u.id = cs.id_usuario_callcenter
LEFT JOIN callcenter_codigos cc
    ON cc.id = cs.id_codigo_generado
WHERE cs.id_venta = ?
ORDER BY cs.id DESC
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

/* =========================================================
   WHATSAPP SUGERIDO
========================================================= */
$mensajeWa = "Hola " . trim((string)($venta['nombre_cliente'] ?? '')) . ", te contactamos de Luga para dar seguimiento a tu plan y compartirte información sobre tu próxima compra.";
if (!empty($codigoActual['codigo'])) {
    $mensajeWa .= " Tu código de atención es: " . $codigoActual['codigo'] . ".";
}
$waUrl = buildWhatsAppUrl((string)($venta['telefono_cliente'] ?? ''), $mensajeWa);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Seguimiento Call Center</title>
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
        .timeline-item{
            border-left: 3px solid #d0d5dd;
            padding-left: 14px;
            margin-left: 8px;
            position: relative;
        }
        .timeline-item::before{
            content:'';
            position:absolute;
            left:-8px;
            top:6px;
            width:12px;
            height:12px;
            border-radius:50%;
            background:#0d6efd;
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
                    <i class="bi bi-journal-text me-2"></i>Seguimiento Call Center
                </h2>
                <div class="opacity-75">Bitácora de contacto, comentarios y próxima acción.</div>
            </div>
            <div class="d-flex gap-2">
                <a href="callcenter_clientes.php" class="btn btn-light btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Volver
                </a>
                <a href="callcenter_generar_codigo.php?id_venta=<?= (int)$idVenta ?>" class="btn btn-outline-light btn-sm">
                    <i class="bi bi-ticket-perforated me-1"></i>Código
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

        <div class="col-lg-4">
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

                    <hr>

                    <div class="d-grid gap-2">
                        <?php if (!empty($venta['telefono_cliente']) && $waUrl !== '#'): ?>
                            <a href="<?= h($waUrl) ?>" target="_blank" class="btn btn-success">
                                <i class="bi bi-whatsapp me-1"></i>Abrir WhatsApp
                            </a>
                        <?php else: ?>
                            <button class="btn btn-outline-secondary" disabled>
                                <i class="bi bi-whatsapp me-1"></i>Sin teléfono válido
                            </button>
                        <?php endif; ?>
                    </div>

                    <?php if ($codigoActual): ?>
                        <hr>
                        <h6 class="fw-bold mb-2">Código actual</h6>
                        <div class="mb-2">
                            <div class="value-strong"><?= h($codigoActual['codigo']) ?></div>
                            <div class="label-soft"><?= h($codigoActual['campana'] ?: '—') ?></div>
                        </div>
                        <div class="mb-2">
                            <span class="pill-soft <?= badgeCodigoClass((string)$codigoActual['estatus']) ?>">
                                <?= h($codigoActual['estatus']) ?>
                            </span>
                        </div>
                        <div class="label-soft">Vigencia: <?= fmtDateTime($codigoActual['fecha_vigencia']) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card soft-card mb-4">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Registrar seguimiento</h5>

                    <form method="POST" novalidate>
                        <input type="hidden" name="id_venta" value="<?= (int)$idVenta ?>">
                        <input type="hidden" name="id_codigo_generado" value="<?= (int)($codigoActual['id'] ?? 0) ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Medio de contacto</label>
                                <select name="medio_contacto" class="form-select" required>
                                    <?php
                                    $medioSel = $_POST['medio_contacto'] ?? 'WHATSAPP';
                                    $medios = [
                                        'WHATSAPP' => 'WhatsApp',
                                        'LLAMADA'  => 'Llamada',
                                        'SMS'      => 'SMS',
                                        'OTRO'     => 'Otro'
                                    ];
                                    foreach ($medios as $k => $label):
                                    ?>
                                        <option value="<?= h($k) ?>" <?= ($medioSel === $k) ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Resultado</label>
                                <select name="resultado" class="form-select" required>
                                    <?php
                                    $resultadoSel = $_POST['resultado'] ?? 'SIN_CONTACTO';
                                    $resultados = [
                                        'SIN_CONTACTO'             => 'Sin contacto',
                                        'NO_CONTESTO'              => 'No contestó',
                                        'INTERESADO'               => 'Interesado',
                                        'NO_INTERESADO'            => 'No interesado',
                                        'VOLVER_A_LLAMAR'          => 'Volver a llamar',
                                        'CODIGO_ENVIADO'           => 'Código enviado',
                                        'VISITA_TIENDA_PENDIENTE'  => 'Visita tienda pendiente',
                                        'VENTA_CERRADA'            => 'Venta cerrada'
                                    ];
                                    foreach ($resultados as $k => $label):
                                    ?>
                                        <option value="<?= h($k) ?>" <?= ($resultadoSel === $k) ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Próxima acción</label>
                                <input
                                    type="text"
                                    name="proxima_accion"
                                    class="form-control"
                                    maxlength="150"
                                    value="<?= h($_POST['proxima_accion'] ?? '') ?>"
                                    placeholder="Ej. llamar de nuevo, enviar promo"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Próxima fecha</label>
                                <input
                                    type="datetime-local"
                                    name="proxima_fecha"
                                    class="form-control"
                                    value="<?= h($_POST['proxima_fecha'] ?? '') ?>"
                                >
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Código relacionado</label>
                                <input
                                    type="text"
                                    class="form-control"
                                    value="<?= h($codigoActual['codigo'] ?? 'Sin código') ?>"
                                    disabled
                                >
                            </div>

                            <div class="col-12">
                                <label class="form-label">Comentarios</label>
                                <textarea
                                    name="comentarios"
                                    class="form-control"
                                    rows="4"
                                    placeholder="Describe qué pasó con el cliente, qué se ofreció o qué sigue..."
                                    required
                                ><?= h($_POST['comentarios'] ?? '') ?></textarea>
                            </div>

                            <div class="col-12 d-flex justify-content-end gap-2 pt-2">
                                <a href="callcenter_clientes.php" class="btn btn-outline-secondary">
                                    Cancelar
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-save me-1"></i>Guardar seguimiento
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="card soft-card">
                <div class="card-body p-4">
                    <h5 class="fw-bold mb-3">Historial de seguimiento</h5>

                    <?php if (empty($historial)): ?>
                        <div class="text-muted">Todavía no hay seguimientos registrados para esta venta.</div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-4">
                            <?php foreach ($historial as $seg): ?>
                                <div class="timeline-item">
                                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                        <div class="d-flex flex-wrap align-items-center gap-2">
                                            <span class="pill-soft bg-light text-dark border">
                                                <?= h($seg['medio_contacto']) ?>
                                            </span>
                                            <span class="pill-soft <?= badgeResultadoClass((string)$seg['resultado']) ?>">
                                                <?= h($seg['resultado']) ?>
                                            </span>
                                            <?php if (!empty($seg['codigo_relacionado'])): ?>
                                                <span class="pill-soft bg-primary-subtle text-primary border border-primary-subtle">
                                                    Código: <?= h($seg['codigo_relacionado']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-muted small">
                                            <?= fmtDateTime($seg['fecha_contacto']) ?>
                                        </div>
                                    </div>

                                    <div class="mb-2">
                                        <?= nl2br(h((string)$seg['comentarios'])) ?>
                                    </div>

                                    <div class="row g-2 text-muted small">
                                        <div class="col-md-4">
                                            <strong>Usuario:</strong> <?= h($seg['usuario_nombre'] ?: '—') ?>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Próx. acción:</strong> <?= h($seg['proxima_accion'] ?: '—') ?>
                                        </div>
                                        <div class="col-md-4">
                                            <strong>Próx. fecha:</strong> <?= fmtDateTime($seg['proxima_fecha']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
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