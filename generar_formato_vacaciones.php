<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$mi_rol = $_SESSION['rol'] ?? 'Ejecutivo';
if (!in_array($mi_rol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso.');
}

require_once __DIR__ . '/db.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function empty_date($v): bool {
    return empty($v) || $v === '0000-00-00';
}

function fmt_date($v): string {
    if (empty_date($v)) return '-';
    $ts = strtotime((string)$v);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function fmt_date_long($v): string {
    if (empty_date($v)) return '-';
    $meses = [
        1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',
        7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'
    ];
    $ts = strtotime((string)$v);
    if (!$ts) return '-';
    $d = (int)date('j', $ts);
    $m = $meses[(int)date('n', $ts)] ?? '';
    $y = date('Y', $ts);
    return "{$d} de {$m} de {$y}";
}

function calc_antiguedad_detalle(?string $fechaIngreso): string {
    if (empty_date($fechaIngreso)) return '-';
    try {
        $inicio = new DateTime($fechaIngreso);
        $hoy    = new DateTime();
        $diff   = $inicio->diff($hoy);
        return "{$diff->y} año(s) {$diff->m} mes(es)";
    } catch (Throwable $e) {
        return '-';
    }
}

function vacaciones_dias_por_anios_servicio(int $anios): int {
    if ($anios < 1) return 0;

    return match (true) {
        $anios === 1 => 12,
        $anios === 2 => 14,
        $anios === 3 => 16,
        $anios === 4 => 18,
        $anios === 5 => 20,
        $anios >= 6  => 22 + (int)floor(($anios - 6) / 5) * 2,
        default      => 0,
    };
}

function obtener_periodo_vacacional_actual(?string $fechaIngreso): ?array {
    if (empty_date($fechaIngreso)) return null;

    try {
        $ingreso = new DateTime($fechaIngreso);
        $hoy     = new DateTime();

        $anioActual = (int)$hoy->format('Y');
        $mesDiaIngreso = $ingreso->format('m-d');
        $aniversarioEsteAnio = new DateTime($anioActual . '-' . $mesDiaIngreso);

        if ($hoy >= $aniversarioEsteAnio) {
            $inicioPeriodo = clone $aniversarioEsteAnio;
        } else {
            $inicioPeriodo = (clone $aniversarioEsteAnio)->modify('-1 year');
        }

        $finPeriodo = (clone $inicioPeriodo)->modify('+1 year')->modify('-1 day');
        $aniosCumplidosAlInicio = (int)$ingreso->diff($inicioPeriodo)->y;
        $proximoAniversario = (clone $inicioPeriodo)->modify('+1 year');

        return [
            'inicio' => $inicioPeriodo->format('Y-m-d'),
            'fin' => $finPeriodo->format('Y-m-d'),
            'anios_cumplidos' => $aniosCumplidosAlInicio,
            'proximo_aniversario' => $proximoAniversario->format('Y-m-d'),
            'label' => $inicioPeriodo->format('d/m/Y') . ' - ' . $finPeriodo->format('d/m/Y'),
        ];
    } catch (Throwable $e) {
        return null;
    }
}

function fecha_retorno(string $fechaFin): string {
    if (empty_date($fechaFin)) return '';
    try {
        $f = new DateTime($fechaFin);
        $f->modify('+1 day');
        return $f->format('Y-m-d');
    } catch (Throwable $e) {
        return '';
    }
}

function file_to_data_uri(string $path): ?string {
    if (!is_file($path)) return null;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = match ($ext) {
        'png'  => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        default => 'application/octet-stream'
    };
    $bin = @file_get_contents($path);
    if ($bin === false) return null;
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function firma_nombre(?string $nombre): string {
    $n = trim((string)$nombre);
    return $n !== '' ? $n : '______________________________';
}

/* =========================================================
   INPUTS
========================================================= */
$usuarioId   = (int)($_GET['usuario_id'] ?? 0);
$solicitudId = (int)($_GET['solicitud_id'] ?? 0);

if ($usuarioId <= 0) {
    exit('Usuario inválido.');
}

/* =========================================================
   USUARIO + EXPEDIENTE
========================================================= */
$sql = "SELECT
            u.id,
            u.nombre,
            u.usuario,
            u.correo,
            u.rol,
            u.activo,
            u.id_sucursal,
            s.nombre AS sucursal_nombre,
            s.zona   AS sucursal_zona,
            ue.fecha_ingreso,
            ue.fecha_baja,
            ue.motivo_baja,
            ue.tel_contacto,
            ue.curp,
            ue.nss,
            ue.rfc,
            ue.foto,
            ue.contrato_status,
            ue.registro_patronal,
            ue.fecha_alta_imss,
            ue.contacto_emergencia,
            ue.tel_emergencia
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        LEFT JOIN usuarios_expediente ue ON ue.usuario_id = u.id
        WHERE u.id = ?
        LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$usuario) {
    exit('No se encontró el usuario.');
}

if (empty_date($usuario['fecha_ingreso'] ?? null)) {
    exit('El empleado no tiene fecha de ingreso válida en usuarios_expediente.');
}

/* =========================================================
   SOLICITUD DE VACACIONES
========================================================= */
$solicitud = null;

if ($solicitudId > 0) {
    $sql = "SELECT *
            FROM vacaciones_solicitudes
            WHERE id = ? AND id_usuario = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $solicitudId, $usuarioId);
    $stmt->execute();
    $solicitud = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} else {
    $sql = "SELECT *
            FROM vacaciones_solicitudes
            WHERE id_usuario = ?
            ORDER BY creado_en DESC, id DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $solicitud = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$solicitud) {
    exit('No se encontró una solicitud de vacaciones para este usuario.');
}

/* =========================================================
   JEFE INMEDIATO Y FIRMAS
   - De momento usamos placeholders o GET opcionales
========================================================= */
$jefeInmediato = trim((string)($_GET['jefe'] ?? 'Jefe Inmediato'));
$rhNombre      = trim((string)($_GET['rh'] ?? 'Recursos Humanos'));
$adminNombre   = trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Administrador'));

/* =========================================================
   RESUMEN VACACIONAL DEL PERIODO
========================================================= */
$periodo = obtener_periodo_vacacional_actual($usuario['fecha_ingreso'] ?? null);
if (!$periodo) {
    exit('No fue posible calcular el periodo vacacional.');
}

$diasDerecho = vacaciones_dias_por_anios_servicio((int)$periodo['anios_cumplidos']);
$diasSolicitud = (int)($solicitud['dias'] ?? 0);

/*
   Días disfrutados previos dentro del periodo anual.
   Excluimos la solicitud actual para que el bloque RH muestre:
   - disfrutados previos
   - a disfrutar en esta solicitud
   - pendientes después de esta solicitud
*/
$sql = "SELECT COALESCE(SUM(dias),0) AS dias_previos
        FROM vacaciones_solicitudes
        WHERE id_usuario = ?
          AND id <> ?
          AND LOWER(COALESCE(status_admin,'')) = 'aprobado'
          AND fecha_inicio >= ?
          AND fecha_inicio <= ?";
$stmt = $conn->prepare($sql);
$solIdTmp = (int)$solicitud['id'];
$stmt->bind_param("iiss", $usuarioId, $solIdTmp, $periodo['inicio'], $periodo['fin']);
$stmt->execute();
$rowPrev = $stmt->get_result()->fetch_assoc();
$stmt->close();

$diasDisfrutadosPrevios = (int)($rowPrev['dias_previos'] ?? 0);
$diasPendientesDespues = max(0, $diasDerecho - $diasDisfrutadosPrevios - $diasSolicitud);
$diasTotalesConEsta = $diasDisfrutadosPrevios + $diasSolicitud;

$fechaInicio = (string)$solicitud['fecha_inicio'];
$fechaFin    = (string)$solicitud['fecha_fin'];
$fechaRetorno = fecha_retorno($fechaFin);
$motivo = trim((string)($solicitud['motivo'] ?? ''));
$statusAdmin = trim((string)($solicitud['status_admin'] ?? 'Pendiente'));

/* =========================================================
   RECURSOS VISUALES
========================================================= */
$logoPath = __DIR__ . '/assets/logo_ticket.png';
$logoData = file_to_data_uri($logoPath);

$fotoData = null;
$fotoRaw = trim((string)($usuario['foto'] ?? ''));
if ($fotoRaw !== '') {
    $fotoAbs = $fotoRaw;
    if (!str_starts_with($fotoRaw, '/') && !preg_match('/^[A-Za-z]:\\\\/', $fotoRaw)) {
        $fotoAbs = __DIR__ . '/' . ltrim($fotoRaw, '/');
    }
    $fotoData = file_to_data_uri($fotoAbs);
}

$folio = 'VAC-' . str_pad((string)$usuarioId, 4, '0', STR_PAD_LEFT) . '-' . str_pad((string)$solicitud['id'], 4, '0', STR_PAD_LEFT);
$fechaEmision = date('Y-m-d');

/* =========================================================
   HTML DEL DOCUMENTO
========================================================= */
ob_start();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Constancia de vacaciones - <?= h($usuario['nombre']) ?></title>
<style>
    @page {
        margin: 22mm 16mm 18mm 16mm;
    }

    body{
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        color:#1f2937;
        font-size:11px;
        line-height:1.35;
        margin:0;
        padding:0;
    }

    .page{
        page-break-after: always;
    }
    .page:last-child{
        page-break-after: auto;
    }

    .header{
        width:100%;
        border-bottom:2px solid #1d4ed8;
        padding-bottom:10px;
        margin-bottom:14px;
    }

    .header-table{
        width:100%;
        border-collapse:collapse;
    }

    .header-table td{
        vertical-align:middle;
    }

    .logo-cell{
        width:90px;
    }

    .logo{
        width:72px;
        height:auto;
    }

    .title-wrap{
        text-align:center;
    }

    .empresa{
        font-size:13px;
        font-weight:bold;
        letter-spacing:.4px;
        color:#0f172a;
    }

    .titulo{
        font-size:18px;
        font-weight:bold;
        color:#1d4ed8;
        margin-top:2px;
    }

    .meta{
        width:160px;
        font-size:10px;
        text-align:right;
    }

    .meta-box{
        border:1px solid #cbd5e1;
        border-radius:6px;
        padding:6px 8px;
        background:#f8fafc;
    }

    .section-title{
        font-size:12px;
        font-weight:bold;
        color:#0f172a;
        background:#eff6ff;
        border:1px solid #bfdbfe;
        padding:7px 9px;
        border-radius:6px;
        margin:12px 0 8px;
    }

    table.grid{
        width:100%;
        border-collapse:collapse;
        margin-bottom:8px;
    }

    table.grid td{
        border:1px solid #dbe2ea;
        padding:7px 8px;
        vertical-align:middle;
    }

    .label{
        width:26%;
        font-weight:bold;
        background:#f8fafc;
        color:#334155;
    }

    .value{
        width:24%;
    }

    .label-wide{
        width:30%;
        font-weight:bold;
        background:#f8fafc;
        color:#334155;
    }

    .value-wide{
        width:70%;
    }

    .note{
        border:1px solid #dbe2ea;
        background:#fafcff;
        padding:10px 12px;
        border-radius:6px;
        margin-top:8px;
        text-align:justify;
    }

    .signatures{
        width:100%;
        border-collapse:collapse;
        margin-top:26px;
    }

    .signatures td{
        width:33.33%;
        text-align:center;
        vertical-align:bottom;
        padding:0 10px;
    }

    .sign-line{
        margin:34px auto 6px;
        border-top:1px solid #111827;
        width:88%;
        height:1px;
    }

    .sign-name{
        font-size:10px;
        font-weight:bold;
        color:#111827;
    }

    .sign-role{
        font-size:10px;
        color:#4b5563;
    }

    .muted{
        color:#6b7280;
    }

    .photo-box{
        margin-top:10px;
        border:1px solid #dbe2ea;
        border-radius:6px;
        padding:8px;
        text-align:center;
        background:#fcfdff;
    }

    .photo-box img{
        width:95px;
        height:95px;
        object-fit:cover;
        border-radius:8px;
        border:1px solid #cbd5e1;
    }

    .footer-mini{
        margin-top:10px;
        font-size:9px;
        color:#6b7280;
        text-align:right;
    }

    .status-chip{
        display:inline-block;
        padding:3px 8px;
        border-radius:999px;
        font-size:10px;
        font-weight:bold;
        border:1px solid #cbd5e1;
        background:#f8fafc;
        color:#111827;
    }
</style>
</head>
<body>

<!-- ======================================================
     PAGINA 1 - CONSTANCIA DE VACACIONES
======================================================= -->
<div class="page">
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    <?php if ($logoData): ?>
                        <img class="logo" src="<?= h($logoData) ?>" alt="Logo">
                    <?php endif; ?>
                </td>
                <td class="title-wrap">
                    <div class="empresa">LUGA PH S.A. DE C.V.</div>
                    <div class="titulo">CONSTANCIA DE VACACIONES</div>
                </td>
                <td class="meta">
                    <div class="meta-box">
                        <div><strong>Fecha:</strong> <?= h(fmt_date($fechaEmision)) ?></div>
                        <div><strong>Folio:</strong> <?= h($folio) ?></div>
                        <div><strong>Estatus:</strong> <?= h($statusAdmin !== '' ? $statusAdmin : 'Pendiente') ?></div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Datos del empleado</div>
    <table class="grid">
        <tr>
            <td class="label">Nombre</td>
            <td class="value"><?= h($usuario['nombre']) ?></td>
            <td class="label">No. de empleado</td>
            <td class="value"><?= (int)$usuario['id'] ?></td>
        </tr>
        <tr>
            <td class="label">Sucursal / Area</td>
            <td class="value"><?= h($usuario['sucursal_nombre'] ?: '-') ?></td>
            <td class="label">Puesto</td>
            <td class="value"><?= h($usuario['rol'] ?: '-') ?></td>
        </tr>
        <tr>
            <td class="label">Fecha de ingreso</td>
            <td class="value"><?= h(fmt_date($usuario['fecha_ingreso'])) ?></td>
            <td class="label">Antiguedad</td>
            <td class="value"><?= h(calc_antiguedad_detalle($usuario['fecha_ingreso'])) ?></td>
        </tr>
    </table>

    <?php if ($fotoData): ?>
        <div class="photo-box" style="width:120px; float:right; margin-left:12px;">
            <img src="<?= h($fotoData) ?>" alt="Foto empleado">
            <div class="muted" style="margin-top:6px;">Foto del empleado</div>
        </div>
    <?php endif; ?>

    <div class="section-title">Solicitud de vacaciones</div>
    <div class="note">
        Se hace constar que el trabajador disfrutara de su periodo vacacional conforme a las fechas autorizadas que se indican a continuacion.
    </div>

    <table class="grid">
        <tr>
            <td class="label">Vacaciones a tomar del</td>
            <td class="value"><?= h(fmt_date($fechaInicio)) ?></td>
            <td class="label">Al</td>
            <td class="value"><?= h(fmt_date($fechaFin)) ?></td>
        </tr>
        <tr>
            <td class="label">No. de dias</td>
            <td class="value"><?= (int)$diasSolicitud ?></td>
            <td class="label">Presentandose el</td>
            <td class="value"><?= h(fmt_date($fechaRetorno)) ?></td>
        </tr>
        <tr>
            <td class="label-wide">Motivo / observaciones</td>
            <td class="value-wide" colspan="3"><?= h($motivo !== '' ? $motivo : '-') ?></td>
        </tr>
    </table>

    <div class="section-title">Datos exclusivos de Recursos Humanos</div>
    <table class="grid">
        <tr>
            <td class="label-wide">Periodo vacacional vigente (vigencia anual)</td>
            <td class="value-wide" colspan="3"><?= h($periodo['label']) ?></td>
        </tr>
        <tr>
            <td class="label">Dias con derecho</td>
            <td class="value"><?= (int)$diasDerecho ?></td>
            <td class="label">Dias disfrutados</td>
            <td class="value"><?= (int)$diasDisfrutadosPrevios ?></td>
        </tr>
        <tr>
            <td class="label">Dias a disfrutar</td>
            <td class="value"><?= (int)$diasSolicitud ?></td>
            <td class="label">Dias pendientes</td>
            <td class="value"><?= (int)$diasPendientesDespues ?></td>
        </tr>
    </table>

    <div class="note">
        Acepto y recibo a mi mas entera conformidad el goce de mis vacaciones correspondientes al periodo antes señalado,
        asi como el pago de la prima vacacional que conforme a la ley corresponde.
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($usuario['nombre'])) ?></div>
                <div class="sign-role">Empleado</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($jefeInmediato)) ?></div>
                <div class="sign-role">Jefe inmediato</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($rhNombre)) ?></div>
                <div class="sign-role">Recursos Humanos</div>
            </td>
        </tr>
    </table>

    <div class="footer-mini">
        Documento generado desde La Central - LUGA
    </div>
</div>

<!-- ======================================================
     PAGINA 2 - CONSTANCIA DE DISFRUTE
======================================================= -->
<div class="page">
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="logo-cell">
                    <?php if ($logoData): ?>
                        <img class="logo" src="<?= h($logoData) ?>" alt="Logo">
                    <?php endif; ?>
                </td>
                <td class="title-wrap">
                    <div class="empresa">LUGA PH S.A. DE C.V.</div>
                    <div class="titulo">CONSTANCIA DE DISFRUTE DE VACACIONES</div>
                </td>
                <td class="meta">
                    <div class="meta-box">
                        <div><strong>Fecha:</strong> <?= h(fmt_date($fechaEmision)) ?></div>
                        <div><strong>Folio:</strong> <?= h($folio) ?></div>
                        <div><strong>Vigencia:</strong> 1 año</div>
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="section-title">Datos del empleado</div>
    <table class="grid">
        <tr>
            <td class="label">Nombre</td>
            <td class="value"><?= h($usuario['nombre']) ?></td>
            <td class="label">No. de empleado</td>
            <td class="value"><?= (int)$usuario['id'] ?></td>
        </tr>
        <tr>
            <td class="label">Sucursal / Area</td>
            <td class="value"><?= h($usuario['sucursal_nombre'] ?: '-') ?></td>
            <td class="label">Puesto</td>
            <td class="value"><?= h($usuario['rol'] ?: '-') ?></td>
        </tr>
        <tr>
            <td class="label">Fecha de ingreso</td>
            <td class="value"><?= h(fmt_date($usuario['fecha_ingreso'])) ?></td>
            <td class="label">Antiguedad</td>
            <td class="value"><?= h(calc_antiguedad_detalle($usuario['fecha_ingreso'])) ?></td>
        </tr>
    </table>

    <div class="section-title">Periodo disfrutado</div>
    <table class="grid">
        <tr>
            <td class="label">Vacaciones tomadas del</td>
            <td class="value"><?= h(fmt_date($fechaInicio)) ?></td>
            <td class="label">Al</td>
            <td class="value"><?= h(fmt_date($fechaFin)) ?></td>
        </tr>
        <tr>
            <td class="label">Numero de dias</td>
            <td class="value"><?= (int)$diasSolicitud ?></td>
            <td class="label">Fecha de reincorporacion</td>
            <td class="value"><?= h(fmt_date($fechaRetorno)) ?></td>
        </tr>
    </table>

    <div class="section-title">Periodo vacacional correspondiente</div>
    <table class="grid">
        <tr>
            <td class="label-wide">Periodo vacacional vigente</td>
            <td class="value-wide" colspan="3"><?= h($periodo['label']) ?></td>
        </tr>
        <tr>
            <td class="label">Dias con derecho</td>
            <td class="value"><?= (int)$diasDerecho ?></td>
            <td class="label">Dias disfrutados</td>
            <td class="value"><?= (int)$diasTotalesConEsta ?></td>
        </tr>
        <tr>
            <td class="label">Dias pendientes</td>
            <td class="value"><?= (int)$diasPendientesDespues ?></td>
            <td class="label">Presentacion</td>
            <td class="value"><?= h(fmt_date($fechaRetorno)) ?></td>
        </tr>
    </table>

    <div class="note">
        Por medio de la presente se hace constar que el trabajador disfruto de los dias de vacaciones correspondientes
        al periodo señalado, quedando asentado el control interno de dias disfrutados y pendientes dentro de su vigencia anual.
    </div>

    <table class="signatures">
        <tr>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($usuario['nombre'])) ?></div>
                <div class="sign-role">Empleado</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($jefeInmediato)) ?></div>
                <div class="sign-role">Jefe inmediato</div>
            </td>
            <td>
                <div class="sign-line"></div>
                <div class="sign-name"><?= h(firma_nombre($rhNombre)) ?></div>
                <div class="sign-role">Recursos Humanos</div>
            </td>
        </tr>
    </table>

    <div class="footer-mini">
        Generado por <?= h($adminNombre) ?> desde La Central - LUGA
    </div>
</div>

</body>
</html>
<?php
$html = ob_get_clean();

/* =========================================================
   SALIDA PDF
   - Si existe Dompdf, genera PDF
   - Si no existe, muestra HTML imprimible
========================================================= */
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
];

$autoloadFound = null;
foreach ($autoloadPaths as $ap) {
    if (is_file($ap)) {
        $autoloadFound = $ap;
        break;
    }
}

if ($autoloadFound) {
    require_once $autoloadFound;

    if (class_exists(\Dompdf\Dompdf::class)) {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'constancia_vacaciones_' . $usuarioId . '_' . (int)$solicitud['id'] . '.pdf';
        $dompdf->stream($filename, ['Attachment' => false]);
        exit;
    }
}

/* Fallback HTML imprimible */
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Vista previa - Constancia de vacaciones</title>
<style>
    body{font-family:Arial,sans-serif;background:#f3f4f6;margin:0;padding:20px}
    .topbar{max-width:900px;margin:0 auto 16px;background:#fff3cd;border:1px solid #ffe69c;color:#7c5700;padding:14px 16px;border-radius:10px}
    .wrap{max-width:900px;margin:0 auto;background:#fff;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.06);overflow:hidden}
    .actions{padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#fff}
    .actions button,.actions a{padding:10px 14px;border-radius:8px;border:1px solid #d1d5db;background:#fff;cursor:pointer;text-decoration:none;color:#111827;margin-right:8px}
    iframe{width:100%;height:85vh;border:0}
</style>
</head>
<body>
    <div class="topbar">
        No se detectó <strong>Dompdf</strong>. Se muestra una vista imprimible para guardar como PDF desde el navegador.
    </div>
    <div class="wrap">
        <div class="actions">
            <button onclick="window.print()">Imprimir / Guardar PDF</button>
            <a href="expediente_usuario.php?usuario_id=<?= (int)$usuarioId ?>">Volver al expediente</a>
        </div>
        <iframe srcdoc="<?= h($html) ?>"></iframe>
    </div>
</body>
</html>