<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = trim((string)($_SESSION['rol'] ?? ''));
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$idRetro    = (int)($_GET['id'] ?? 0);

if ($idRetro <= 0) {
    die("Retroalimentación inválida.");
}

$stmt = $conn->prepare("
    SELECT u.id, u.rol, u.id_sucursal, s.zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$actual = $stmt->get_result()->fetch_assoc();
$stmt->close();

$miZona = $actual['zona'] ?? null;

$stmt = $conn->prepare("
    SELECT
        r.*,
        uc.nombre AS colaborador_nombre,
        uc.usuario AS colaborador_usuario,
        uc.correo AS colaborador_correo,
        ul.nombre AS lider_nombre,
        ul.usuario AS lider_usuario,
        s.nombre AS sucursal_nombre
    FROM retroalimentaciones r
    LEFT JOIN usuarios uc ON uc.id = r.id_colaborador
    LEFT JOIN usuarios ul ON ul.id = r.id_lider
    LEFT JOIN sucursales s ON s.id = r.id_sucursal
    WHERE r.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idRetro);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) {
    die("No se encontró la retroalimentación.");
}

if (strtoupper((string)$r['estatus']) !== 'FIRMADA') {
    die("El PDF solo está disponible cuando la retroalimentación está firmada.");
}

$puedeVer = false;

if ($rolUsuario === 'Admin') $puedeVer = true;
if ((int)$r['id_colaborador'] === $idUsuario) $puedeVer = true;
if ((int)$r['id_lider'] === $idUsuario) $puedeVer = true;
if ($rolUsuario === 'GerenteZona' && ($r['zona'] ?? null) === $miZona) $puedeVer = true;
if ($rolUsuario === 'Gerente' && (int)$r['id_sucursal'] === $idSucursal) $puedeVer = true;

if (!$puedeVer) {
    die("No tienes permiso para ver este PDF.");
}

$folio = $r['folio'] ?: 'RETRO-' . str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT);
$fechaCreacion = !empty($r['fecha_creacion']) ? date('d/m/Y H:i', strtotime($r['fecha_creacion'])) : '-';
$fechaFirma = !empty($r['fecha_firma']) ? date('d/m/Y H:i', strtotime($r['fecha_firma'])) : '-';

$logoPath = __DIR__ . '/img/Logo_Zentral.png';
$logoBase64 = '';

if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}

$html = '
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    body {
        font-family: DejaVu Sans, sans-serif;
        color: #1f2d3d;
        font-size: 12px;
        line-height: 1.5;
    }
    .header {
        background: #071426;
        color: #fff;
        padding: 22px;
        border-radius: 12px;
        margin-bottom: 22px;
    }
    .logo {
        max-height: 55px;
        margin-bottom: 10px;
    }
    h1 {
        margin: 0;
        font-size: 22px;
    }
    .folio {
        color: #aee7ff;
        font-weight: bold;
        margin-bottom: 6px;
    }
    .badge {
        display: inline-block;
        background: #198754;
        color: #fff;
        padding: 5px 10px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: bold;
    }
    .grid {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 18px;
    }
    .grid td {
        width: 25%;
        border: 1px solid #dce6f0;
        padding: 10px;
        vertical-align: top;
    }
    .label {
        color: #6c757d;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: bold;
    }
    .value {
        font-weight: bold;
        font-size: 12px;
        margin-top: 4px;
    }
    .block {
        border: 1px solid #dce6f0;
        border-radius: 10px;
        padding: 14px;
        margin-bottom: 14px;
    }
    .block h3 {
        margin: 0 0 8px;
        font-size: 14px;
        color: #0f3b63;
    }
    .text {
        white-space: pre-wrap;
    }
    .firma {
        margin-top: 24px;
        border: 1px solid #bfe3ce;
        background: #f0fff5;
        padding: 14px;
        border-radius: 10px;
    }
    .footer {
        margin-top: 28px;
        font-size: 10px;
        color: #6c757d;
        text-align: center;
    }
</style>
</head>
<body>

<div class="header">
    ' . ($logoBase64 ? '<img class="logo" src="' . $logoBase64 . '">' : '') . '
    <div class="folio">' . h($folio) . '</div>
    <h1>Retroalimentación firmada</h1>
    <p>Documento generado desde Zentral.</p>
    <span class="badge">FIRMADA</span>
</div>

<table class="grid">
    <tr>
        <td>
            <div class="label">Colaborador</div>
            <div class="value">' . h($r['colaborador_nombre']) . '</div>
            <div>' . h($r['rol_colaborador']) . '</div>
        </td>
        <td>
            <div class="label">Líder</div>
            <div class="value">' . h($r['lider_nombre']) . '</div>
            <div>' . h($r['rol_lider']) . '</div>
        </td>
        <td>
            <div class="label">Sucursal / zona</div>
            <div class="value">' . h($r['sucursal_nombre']) . '</div>
            <div>' . h($r['zona']) . '</div>
        </td>
        <td>
            <div class="label">Periodo</div>
            <div class="value">' . h($r['periodo']) . '</div>
            <div>Creada: ' . h($fechaCreacion) . '</div>
        </td>
    </tr>
</table>

<div class="block">
    <h3>¿Qué estoy haciendo bien?</h3>
    <div class="text">' . nl2br(h($r['que_hace_bien'])) . '</div>
</div>

<div class="block">
    <h3>¿En qué puedo mejorar?</h3>
    <div class="text">' . nl2br(h($r['que_puede_mejorar'])) . '</div>
</div>

<div class="block">
    <h3>Acuerdos</h3>
    <div class="text">' . nl2br(h($r['acuerdos'])) . '</div>
</div>

<div class="firma">
    <strong>Firma digital registrada</strong><br>
    Colaborador: ' . h($r['colaborador_nombre']) . '<br>
    Fecha de firma: ' . h($fechaFirma) . '<br>
    Usuario firmante ID: ' . h($r['id_usuario_firma']) . '<br>
    IP registrada: ' . h($r['ip_firma']) . '
</div>

<div class="footer">
    Este documento fue generado automáticamente por Zentral. La firma digital fue validada mediante sesión activa y contraseña del colaborador.
</div>

</body>
</html>
';

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$filename = $folio . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
exit();