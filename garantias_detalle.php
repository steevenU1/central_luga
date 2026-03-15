<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para acceder al detalle de garantías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function badge_estado(string $estado): string {
    $map = [
        'capturada'              => 'secondary',
        'recepcion_registrada'   => 'info',
        'en_revision_logistica'  => 'warning text-dark',
        'garantia_autorizada'    => 'success',
        'garantia_rechazada'     => 'danger',
        'enviada_diagnostico'    => 'primary',
        'cotizacion_disponible'  => 'info',
        'cotizacion_aceptada'    => 'success',
        'cotizacion_rechazada'   => 'danger',
        'en_reparacion'          => 'warning text-dark',
        'reparado'               => 'success',
        'reemplazo_capturado'    => 'primary',
        'entregado'              => 'success',
        'cerrado'                => 'dark',
        'cancelado'              => 'dark',
    ];

    $cls = $map[$estado] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($estado) . '</span>';
}

function badge_dictamen(string $dictamen): string {
    $map = [
        'procede'            => 'success',
        'no_procede'         => 'danger',
        'revision_logistica' => 'warning text-dark',
        'imei_no_localizado' => 'secondary',
    ];

    $cls = $map[$dictamen] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($dictamen) . '</span>';
}

function icon_check($value): string {
    if ($value === null || $value === '') {
        return '<span class="badge text-bg-secondary">Sin revisar</span>';
    }
    return ((string)$value === '1')
        ? '<span class="badge text-bg-success">Sí</span>'
        : '<span class="badge text-bg-danger">No</span>';
}

function fmt_datetime(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function fmt_date(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function puede_ver_caso(array $caso, string $rol, int $idUsuario, int $idSucursal): bool {
    if (in_array($rol, ['Admin', 'Administrador', 'Logistica', 'GerenteZona', 'Subdis_Admin'], true)) {
        return true;
    }

    if (in_array($rol, ['Gerente', 'Subdis_Gerente'], true)) {
        return ((int)($caso['id_sucursal'] ?? 0) === $idSucursal);
    }

    if (in_array($rol, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
        return ((int)($caso['id_usuario_captura'] ?? 0) === $idUsuario);
    }

    return false;
}

function es_rol_logistica(string $rol): bool {
    return in_array($rol, ['Admin', 'Administrador', 'Logistica'], true);
}

function es_rol_tienda(string $rol): bool {
    return in_array($rol, ['Admin', 'Administrador', 'Logistica', 'Gerente', 'Ejecutivo', 'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'], true);
}

function resolver_tipo_documento(string $estado): string {
    if (in_array($estado, ['capturada', 'recepcion_registrada', 'en_revision_logistica', 'garantia_autorizada'], true)) {
        return 'recepcion_garantia';
    }

    if (in_array($estado, ['enviada_diagnostico', 'cotizacion_disponible', 'cotizacion_aceptada', 'cotizacion_rechazada', 'en_reparacion'], true)) {
        return 'cotizacion_reparacion';
    }

    if (in_array($estado, ['garantia_rechazada'], true)) {
        return 'no_procede';
    }

    if (in_array($estado, ['reemplazo_capturado', 'reparado', 'entregado', 'cerrado'], true)) {
        return 'entrega_garantia';
    }

    return 'recepcion_garantia';
}

/* =========================================================
   ID
========================================================= */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    exit('ID de garantía inválido.');
}

/* =========================================================
   CASO
========================================================= */
$sql = "SELECT
            gc.*,
            s.nombre AS sucursal_nombre,
            uc.nombre AS capturista_nombre,
            ul.nombre AS logistica_nombre,
            ug.nombre AS gerente_nombre
        FROM garantias_casos gc
        LEFT JOIN sucursales s ON s.id = gc.id_sucursal
        LEFT JOIN usuarios uc ON uc.id = gc.id_usuario_captura
        LEFT JOIN usuarios ul ON ul.id = gc.id_usuario_logistica
        LEFT JOIN usuarios ug ON ug.id = gc.id_usuario_gerente
        WHERE gc.id = ?
        LIMIT 1";

$st = $conn->prepare($sql);
if (!$st) {
    exit("Error en consulta del caso: " . h($conn->error));
}
$st->bind_param("i", $id);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró la garantía solicitada.');
}

if (!puede_ver_caso($caso, $ROL, $ID_USUARIO, $ID_SUCURSAL)) {
    http_response_code(403);
    exit('No tienes permiso para ver este expediente.');
}

/* =========================================================
   EVENTOS
========================================================= */
$eventos = [];
if (table_exists($conn, 'garantias_eventos')) {
    $sqlEventos = "SELECT *
                   FROM garantias_eventos
                   WHERE id_garantia = ?
                   ORDER BY fecha_evento ASC, id ASC";
    $st = $conn->prepare($sqlEventos);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $resE = $st->get_result();
        while ($row = $resE->fetch_assoc()) {
            $eventos[] = $row;
        }
        $st->close();
    }
}

/* =========================================================
   REEMPLAZO
========================================================= */
$reemplazo = null;
if (table_exists($conn, 'garantias_reemplazos')) {
    $sqlR = "SELECT *
             FROM garantias_reemplazos
             WHERE id_garantia = ?
             ORDER BY id DESC
             LIMIT 1";
    $st = $conn->prepare($sqlR);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $reemplazo = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

/* =========================================================
   REPARACION
========================================================= */
$reparacion = null;
if (table_exists($conn, 'garantias_reparaciones')) {
    $sqlRep = "SELECT *
               FROM garantias_reparaciones
               WHERE id_garantia = ?
               ORDER BY id DESC
               LIMIT 1";
    $st = $conn->prepare($sqlRep);
    if ($st) {
        $st->bind_param("i", $id);
        $st->execute();
        $reparacion = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;
$oklog = isset($_GET['oklog']) ? (int)$_GET['oklog'] : 0;
$puedeLogistica = es_rol_logistica($ROL);
$puedeTienda = es_rol_tienda($ROL);
$estado = (string)$caso['estado'];

/* =========================================================
   LINKS DE NAVEGACION VIVA
========================================================= */
$linkCapturarReemplazo = "capturar_reemplazo.php?id=" . (int)$caso['id'];
$linkEntregarGarantia = "entregar_garantia.php?id=" . (int)$caso['id'];
$linkRespuestaCliente = "respuesta_cliente_reparacion.php?id=" . (int)$caso['id'];

$tipoDocumento = resolver_tipo_documento($estado);
$linkDocumento = "generar_documento_garantia.php?id=" . (int)$caso['id'] . "&tipo=" . urlencode($tipoDocumento);

/* =========================================================
   BANDERAS DE ACCION
========================================================= */
$mostrarBtnCapturarReemplazo = $puedeTienda && in_array($estado, ['garantia_autorizada', 'reemplazo_capturado'], true);
$mostrarBtnEntregar = $puedeTienda && in_array($estado, ['reemplazo_capturado', 'reparado', 'garantia_autorizada'], true);
$mostrarBtnRespuestaCliente = $puedeTienda && in_array($estado, ['cotizacion_disponible', 'cotizacion_aceptada', 'cotizacion_rechazada'], true);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Detalle de garantía | <?= h($caso['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1480px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(111,66,193,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .section-title{
            font-size:1rem;
            font-weight:700;
            margin-bottom:.9rem;
            display:flex;
            align-items:center;
            gap:.55rem;
        }
        .section-title i{
            color:#0d6efd;
        }
        .kv-label{
            font-size:.82rem;
            color:#6b7280;
            font-weight:600;
            margin-bottom:.2rem;
        }
        .kv-value{
            font-size:.95rem;
            font-weight:500;
            word-break:break-word;
        }
        .timeline{
            position:relative;
            margin-left: 8px;
            padding-left: 24px;
        }
        .timeline::before{
            content:'';
            position:absolute;
            left:6px;
            top:0;
            bottom:0;
            width:2px;
            background:#dbe5f0;
        }
        .timeline-item{
            position:relative;
            margin-bottom:18px;
        }
        .timeline-dot{
            position:absolute;
            left:-23px;
            top:4px;
            width:14px;
            height:14px;
            border-radius:50%;
            background:#0d6efd;
            border:3px solid #fff;
            box-shadow:0 0 0 2px #dbe5f0;
        }
        .timeline-card{
            border:1px solid #edf1f6;
            border-radius:16px;
            background:#fafcff;
            padding:14px 16px;
        }
        .check-grid{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));
            gap:12px;
        }
        .check-card{
            border:1px solid #edf1f6;
            border-radius:16px;
            background:#fff;
            padding:12px 14px;
        }
        .sticky-box{
            position:sticky;
            top:92px;
        }
        .chip{
            display:inline-flex;
            align-items:center;
            gap:.35rem;
            border-radius:999px;
            padding:.45rem .8rem;
            font-size:.82rem;
            font-weight:600;
            background:#eef4ff;
            color:#2457c5;
        }
        .muted-note{
            color:#6b7280;
            font-size:.92rem;
        }
        .empty-box{
            border:1px dashed #ced4da;
            border-radius:18px;
            background:#fff;
            padding:24px;
            text-align:center;
            color:#6c757d;
        }
        .action-form{
            border:1px solid #edf1f6;
            border-radius:16px;
            padding:14px;
            background:#fbfcfe;
            margin-bottom:12px;
        }
        .action-title{
            font-weight:700;
            margin-bottom:.75rem;
            display:flex;
            align-items:center;
            gap:.45rem;
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <?php if ($ok === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> La solicitud se guardó correctamente.
        </div>
    <?php endif; ?>

    <?php if ($oklog === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check2-circle me-1"></i> La actualización del caso se realizó correctamente.
        </div>
    <?php endif; ?>

    <div class="hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <h3 class="mb-1 fw-bold">
                    <i class="bi bi-journal-medical me-2"></i>Expediente de garantía
                </h3>
                <div class="text-muted">
                    Folio <strong><?= h($caso['folio']) ?></strong> • Cliente <strong><?= h($caso['cliente_nombre']) ?></strong>
                </div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <span class="chip"><i class="bi bi-person-badge"></i> Rol: <?= h($ROL) ?></span>
                <?= badge_dictamen((string)$caso['dictamen_preliminar']) ?>
                <?= badge_estado((string)$caso['estado']) ?>
                <a href="garantias_mis_casos.php" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Volver al listado
                </a>
                <?php if ($puedeLogistica): ?>
                    <a href="garantias_logistica.php" class="btn btn-outline-primary">
                        <i class="bi bi-truck me-1"></i>Panel logística
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-8">

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-info-circle"></i>
                    <span>Resumen del caso</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="kv-label">Fecha captura</div>
                        <div class="kv-value"><?= h(fmt_datetime($caso['fecha_captura'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Fecha recepción</div>
                        <div class="kv-value"><?= h(fmt_date($caso['fecha_recepcion'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Sucursal</div>
                        <div class="kv-value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Capturó</div>
                        <div class="kv-value"><?= h($caso['capturista_nombre']) ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Tipo origen</div>
                        <div class="kv-value"><?= h($caso['tipo_origen']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Venta ID</div>
                        <div class="kv-value"><?= h($caso['id_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Detalle venta ID</div>
                        <div class="kv-value"><?= h($caso['id_detalle_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Nivel reincidencia</div>
                        <div class="kv-value"><?= h($caso['nivel_reincidencia']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-person-vcard"></i>
                    <span>Datos del cliente</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="kv-label">Nombre</div>
                        <div class="kv-value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Teléfono</div>
                        <div class="kv-value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="kv-label">Correo</div>
                        <div class="kv-value"><?= h($caso['cliente_correo']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-phone"></i>
                    <span>Datos del equipo</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="kv-label">Marca</div>
                        <div class="kv-value"><?= h($caso['marca']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Modelo</div>
                        <div class="kv-value"><?= h($caso['modelo']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Color</div>
                        <div class="kv-value"><?= h($caso['color']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Capacidad</div>
                        <div class="kv-value"><?= h($caso['capacidad']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">IMEI 1</div>
                        <div class="kv-value"><?= h($caso['imei_original']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">IMEI 2</div>
                        <div class="kv-value"><?= h($caso['imei2_original']) ?></div>
                    </div>

                    <div class="col-md-3">
                        <div class="kv-label">Fecha compra</div>
                        <div class="kv-value"><?= h(fmt_date($caso['fecha_compra'])) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">TAG venta</div>
                        <div class="kv-value"><?= h($caso['tag_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Modalidad</div>
                        <div class="kv-value"><?= h($caso['modalidad_venta']) ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="kv-label">Financiera</div>
                        <div class="kv-value"><?= h($caso['financiera']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-exclamation-octagon"></i>
                    <span>Falla reportada y observaciones</span>
                </div>

                <div class="mb-3">
                    <div class="kv-label">Descripción de falla</div>
                    <div class="kv-value"><?= nl2br(h($caso['descripcion_falla'])) ?></div>
                </div>

                <div class="mb-3">
                    <div class="kv-label">Observaciones de tienda</div>
                    <div class="kv-value"><?= nl2br(h($caso['observaciones_tienda'])) ?></div>
                </div>

                <div>
                    <div class="kv-label">Observaciones de logística</div>
                    <div class="kv-value"><?= nl2br(h($caso['observaciones_logistica'])) ?></div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-ui-checks-grid"></i>
                    <span>Checklist técnico inicial</span>
                </div>

                <div class="check-grid">
                    <div class="check-card"><div class="kv-label">Encendido</div><div class="kv-value"><?= icon_check($caso['check_encendido']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Daño físico</div><div class="kv-value"><?= icon_check($caso['check_dano_fisico']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Humedad</div><div class="kv-value"><?= icon_check($caso['check_humedad']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Pantalla</div><div class="kv-value"><?= icon_check($caso['check_pantalla']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Cámara</div><div class="kv-value"><?= icon_check($caso['check_camara']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Bocina / Micrófono</div><div class="kv-value"><?= icon_check($caso['check_bocina_microfono']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Puerto de carga</div><div class="kv-value"><?= icon_check($caso['check_puerto_carga']) ?></div></div>
                    <div class="check-card"><div class="kv-label">App financiera instalada</div><div class="kv-value"><?= icon_check($caso['check_app_financiera']) ?></div></div>
                    <div class="check-card"><div class="kv-label">Bloqueo patrón / Google</div><div class="kv-value"><?= icon_check($caso['check_bloqueo_patron_google']) ?></div></div>
                </div>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-tools"></i>
                    <span>Diagnóstico / reparación con proveedor</span>
                </div>

                <?php if ($reparacion): ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="kv-label">Proveedor</div>
                            <div class="kv-value"><?= h($reparacion['proveedor_nombre']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Estado reparación</div>
                            <div class="kv-value"><?= h($reparacion['estado']) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Tiempo estimado</div>
                            <div class="kv-value"><?= h($reparacion['tiempo_estimado_dias']) ?> día(s)</div>
                        </div>

                        <div class="col-md-4">
                            <div class="kv-label">Costo revisión</div>
                            <div class="kv-value">$<?= number_format((float)$reparacion['costo_revision'], 2) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Costo reparación</div>
                            <div class="kv-value">$<?= number_format((float)$reparacion['costo_reparacion'], 2) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Costo total</div>
                            <div class="kv-value fw-bold">$<?= number_format((float)$reparacion['costo_total'], 2) ?></div>
                        </div>

                        <div class="col-12">
                            <div class="kv-label">Diagnóstico proveedor</div>
                            <div class="kv-value"><?= nl2br(h($reparacion['diagnostico_proveedor'])) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-box">
                        Aún no existe información de reparación o cotización para este caso.
                    </div>
                <?php endif; ?>
            </div>

            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-arrow-repeat"></i>
                    <span>Equipo de reemplazo</span>
                </div>

                <?php if ($reemplazo): ?>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <div class="kv-label">Marca reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['marca_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="kv-label">Modelo reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['modelo_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="kv-label">Color reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['color_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-3">
                            <div class="kv-label">Capacidad reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['capacidad_reemplazo']) ?></div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">IMEI reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['imei_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI 2 reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['imei2_reemplazo']) ?></div>
                        </div>

                        <div class="col-md-4">
                            <div class="kv-label">Fecha registro</div>
                            <div class="kv-value"><?= h(fmt_datetime($reemplazo['fecha_registro'])) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Fecha entrega</div>
                            <div class="kv-value"><?= h(fmt_datetime($reemplazo['fecha_entrega'])) ?></div>
                        </div>
                        <div class="col-md-4">
                            <div class="kv-label">Estatus inventario nuevo</div>
                            <div class="kv-value"><?= h($reemplazo['estatus_inventario_nuevo']) ?></div>
                        </div>

                        <div class="col-12">
                            <div class="kv-label">Observaciones</div>
                            <div class="kv-value"><?= nl2br(h($reemplazo['observaciones'])) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="empty-box">
                        Aún no se ha capturado un equipo de reemplazo para este caso.
                    </div>
                <?php endif; ?>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-clock-history"></i>
                    <span>Timeline del caso</span>
                </div>

                <?php if (!$eventos): ?>
                    <div class="empty-box">
                        No hay eventos registrados todavía.
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($eventos as $ev): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"></div>
                                <div class="timeline-card">
                                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                                        <div>
                                            <div class="fw-semibold"><?= h($ev['tipo_evento']) ?></div>
                                            <div class="muted-note"><?= h($ev['descripcion']) ?></div>
                                        </div>
                                        <div class="text-md-end">
                                            <div class="small fw-semibold"><?= h(fmt_datetime($ev['fecha_evento'])) ?></div>
                                            <div class="small text-muted"><?= h($ev['nombre_usuario']) ?><?= $ev['rol_usuario'] ? ' • ' . h($ev['rol_usuario']) : '' ?></div>
                                        </div>
                                    </div>

                                    <?php if (!empty($ev['estado_anterior']) || !empty($ev['estado_nuevo'])): ?>
                                        <div class="small mt-2">
                                            <span class="text-muted">Estado:</span>
                                            <?= h($ev['estado_anterior']) ?: '—' ?>
                                            <i class="bi bi-arrow-right mx-1"></i>
                                            <?= h($ev['estado_nuevo']) ?: '—' ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($ev['datos_json'])): ?>
                                        <details class="mt-2">
                                            <summary class="small text-primary" style="cursor:pointer;">Ver datos del evento</summary>
                                            <pre class="small bg-light p-2 rounded mt-2 mb-0" style="white-space:pre-wrap;"><?= h($ev['datos_json']) ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="sticky-box">

                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-shield-check"></i>
                        <span>Dictamen y resolución</span>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Dictamen preliminar</div>
                        <div class="kv-value"><?= badge_dictamen((string)$caso['dictamen_preliminar']) ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Motivo no procede</div>
                        <div class="kv-value"><?= h($caso['motivo_no_procede']) ?: '-' ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Detalle del sistema</div>
                        <div class="kv-value"><?= nl2br(h($caso['detalle_no_procede'])) ?: '-' ?></div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Estado actual</div>
                        <div class="kv-value"><?= badge_estado((string)$caso['estado']) ?></div>
                    </div>

                    <div class="row g-3">
                        <div class="col-6">
                            <div class="kv-label">Es reparable</div>
                            <div class="kv-value"><?= (int)$caso['es_reparable'] === 1 ? 'Sí' : 'No' ?></div>
                        </div>
                        <div class="col-6">
                            <div class="kv-label">Requiere cotización</div>
                            <div class="kv-value"><?= (int)$caso['requiere_cotizacion'] === 1 ? 'Sí' : 'No' ?></div>
                        </div>
                    </div>
                </div>

                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-signpost-split"></i>
                        <span>Navegación operativa</span>
                    </div>

                    <div class="d-grid gap-2">
                        <?php if ($mostrarBtnCapturarReemplazo): ?>
                            <a href="<?= h($linkCapturarReemplazo) ?>" class="btn btn-success">
                                <i class="bi bi-arrow-repeat me-1"></i>Capturar reemplazo
                            </a>
                        <?php endif; ?>

                        <?php if ($mostrarBtnEntregar): ?>
                            <a href="<?= h($linkEntregarGarantia) ?>" class="btn btn-primary">
                                <i class="bi bi-box2-check me-1"></i>Entregar al cliente
                            </a>
                        <?php endif; ?>

                        <?php if ($mostrarBtnRespuestaCliente): ?>
                            <a href="<?= h($linkRespuestaCliente) ?>" class="btn btn-warning text-dark">
                                <i class="bi bi-chat-dots me-1"></i>Registrar respuesta del cliente
                            </a>
                        <?php endif; ?>

                        <a href="<?= h($linkDocumento) ?>" target="_blank" class="btn btn-outline-secondary">
                            <i class="bi bi-file-earmark-pdf me-1"></i>Generar formato
                        </a>
                    </div>

                    <div class="mt-3 muted-note">
                        Documento actual: <strong><?= h($tipoDocumento) ?></strong>
                    </div>
                </div>

                <?php if ($puedeLogistica): ?>
                    <div class="soft-card p-4 mb-4">
                        <div class="section-title">
                            <i class="bi bi-lightning-charge"></i>
                            <span>Gestión de logística</span>
                        </div>

                        <?php if (in_array($estado, ['capturada', 'recepcion_registrada', 'en_revision_logistica'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-check2-circle text-success"></i> Autorizar garantía</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="autorizar_garantia">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100">Autorizar garantía</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-x-circle text-danger"></i> Rechazar garantía</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="rechazar_garantia">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2" required></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm w-100">Rechazar garantía</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-truck text-primary"></i> Enviar a proveedor</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="enviar_proveedor">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Proveedor</label>
                                    <input type="text" name="proveedor_nombre" class="form-control form-control-sm" required>
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">Enviar a proveedor</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($estado, ['enviada_diagnostico', 'cotizacion_disponible'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-cash-coin text-warning"></i> Registrar cotización</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cotizacion_disponible">

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Proveedor</label>
                                    <input type="text" name="proveedor_nombre" class="form-control form-control-sm" value="<?= h($reparacion['proveedor_nombre'] ?? '') ?>" required>
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Diagnóstico</label>
                                    <textarea name="diagnostico_proveedor" class="form-control form-control-sm" rows="2" required><?= h($reparacion['diagnostico_proveedor'] ?? '') ?></textarea>
                                </div>

                                <div class="row g-2">
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold">Costo rev.</label>
                                        <input type="number" step="0.01" min="0" name="costo_revision" class="form-control form-control-sm" value="<?= h($reparacion['costo_revision'] ?? '0') ?>">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold">Costo rep.</label>
                                        <input type="number" step="0.01" min="0" name="costo_reparacion" class="form-control form-control-sm" value="<?= h($reparacion['costo_reparacion'] ?? '0') ?>">
                                    </div>
                                    <div class="col-4">
                                        <label class="form-label small fw-semibold">Días</label>
                                        <input type="number" min="0" name="tiempo_estimado_dias" class="form-control form-control-sm" value="<?= h($reparacion['tiempo_estimado_dias'] ?? '') ?>">
                                    </div>
                                </div>

                                <div class="mt-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>

                                <button type="submit" class="btn btn-warning btn-sm w-100 mt-2">Guardar cotización</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($estado === 'cotizacion_disponible'): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-hand-thumbs-up text-success"></i> Marcar cotización aceptada</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cotizacion_aceptada">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100">Aceptar cotización</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-hand-thumbs-down text-danger"></i> Marcar cotización rechazada</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cotizacion_rechazada">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-danger btn-sm w-100">Rechazar cotización</button>
                            </form>
                        <?php endif; ?>

                        <?php if (in_array($estado, ['cotizacion_aceptada', 'en_reparacion'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-tools text-primary"></i> Marcar en reparación</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="en_reparacion">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Proveedor</label>
                                    <input type="text" name="proveedor_nombre" class="form-control form-control-sm" value="<?= h($reparacion['proveedor_nombre'] ?? '') ?>">
                                </div>
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary btn-sm w-100">Marcar en reparación</button>
                            </form>

                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-wrench-adjustable-circle text-success"></i> Marcar reparado</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="marcar_reparado">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-success btn-sm w-100">Marcar reparado</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!in_array($estado, ['cerrado', 'cancelado'], true)): ?>
                            <form method="post" action="actualizar_garantia_logistica.php" class="action-form">
                                <div class="action-title"><i class="bi bi-lock text-dark"></i> Cerrar caso</div>
                                <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                                <input type="hidden" name="accion" value="cerrar_caso">
                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">Observaciones finales</label>
                                    <textarea name="observaciones_logistica" class="form-control form-control-sm" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-dark btn-sm w-100">Cerrar caso</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="soft-card p-4">
                    <div class="section-title">
                        <i class="bi bi-diagram-3"></i>
                        <span>Trazabilidad</span>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">ID garantía padre</div>
                        <div class="kv-value">
                            <?php if (!empty($caso['id_garantia_padre'])): ?>
                                <a href="garantias_detalle.php?id=<?= (int)$caso['id_garantia_padre'] ?>">
                                    #<?= (int)$caso['id_garantia_padre'] ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">ID garantía raíz</div>
                        <div class="kv-value">
                            <?php if (!empty($caso['id_garantia_raiz'])): ?>
                                <a href="garantias_detalle.php?id=<?= (int)$caso['id_garantia_raiz'] ?>">
                                    #<?= (int)$caso['id_garantia_raiz'] ?>
                                </a>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div class="kv-label">Usuario logística</div>
                        <div class="kv-value"><?= h($caso['logistica_nombre']) ?: '-' ?></div>
                    </div>

                    <div>
                        <div class="kv-label">Usuario gerente</div>
                        <div class="kv-value"><?= h($caso['gerente_nombre']) ?: '-' ?></div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

</body>
</html>