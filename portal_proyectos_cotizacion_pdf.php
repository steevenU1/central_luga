<?php
// portal_proyectos_cotizacion_pdf.php - PDF interno de cotizacion completa para cobro
// Visible solo para Sistemas.
// Requiere Dompdf instalado por Composer: vendor/autoload.php

if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['id_usuario'])) {
    header('Location: index.php');
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");

$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$SISTEMAS_IDS = [6];
$isSistemas = in_array($ID_USUARIO, $SISTEMAS_IDS, true);

if (!$isSistemas) {
    http_response_code(403);
    exit('Sin permiso para generar esta cotizacion.');
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('ID invalido.');
}

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function money_fmt($v): string {
    if ($v === null || $v === '') return '$0.00 MXN';
    return '$' . number_format((float)$v, 2) . ' MXN';
}

function fecha_fmt($v): string {
    $v = trim((string)$v);
    if ($v === '') return '-';
    try {
        $dt = new DateTime($v);
        return $dt->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $v;
    }
}

function fecha_corta($v): string {
    $v = trim((string)$v);
    if ($v === '') return '-';
    try {
        $dt = new DateTime($v);
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return $v;
    }
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('s', $table);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}

function column_exists(mysqli $conn, string $table, string $col): bool {
    $sql = "SELECT 1
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param('ss', $table, $col);
    $st->execute();
    $ok = (bool)$st->get_result()->fetch_row();
    $st->close();
    return $ok;
}

function pick_order_col(mysqli $conn, string $table, array $candidates, string $fallback = 'id'): string {
    foreach ($candidates as $c) {
        if (column_exists($conn, $table, $c)) return $c;
    }
    return $fallback;
}

function pre($s): string {
    return nl2br(h((string)$s));
}

// Buscar autoload de Dompdf
$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];

$autoloadFound = null;
foreach ($autoloadCandidates as $candidate) {
    if (file_exists($candidate)) {
        $autoloadFound = $candidate;
        break;
    }
}

if (!$autoloadFound) {
    http_response_code(500);
    echo "Dompdf no esta instalado. Instala con: composer require dompdf/dompdf";
    exit;
}

require_once $autoloadFound;

if (!class_exists('Dompdf\\Dompdf')) {
    http_response_code(500);
    echo "No se encontro la clase Dompdf. Revisa vendor/autoload.php";
    exit;
}

use Dompdf\Dompdf;
use Dompdf\Options;

try {
    // Solicitud principal
    $stmt = $conn->prepare("
        SELECT
            s.*,
            e.clave AS empresa_clave,
            e.nombre AS empresa_nombre,
            ucap.nombre AS costo_capturado_nombre
        FROM portal_proyectos_solicitudes s
        INNER JOIN portal_empresas e ON e.id = s.empresa_id
        LEFT JOIN usuarios ucap ON ucap.id = s.costo_capturado_por
        WHERE s.id = ?
        LIMIT 1
    ");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $sol = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$sol) {
        http_response_code(404);
        exit('Solicitud no encontrada.');
    }

    // Detalle de partidas
    $quoteRows = [];
    if (table_exists($conn, 'portal_proyectos_cotizacion_detalle')) {
        $stmtQ = $conn->prepare("
            SELECT concepto, horas, costo_hora, subtotal, observaciones
            FROM portal_proyectos_cotizacion_detalle
            WHERE solicitud_id = ?
            ORDER BY id ASC
        ");
        $stmtQ->bind_param('i', $id);
        $stmtQ->execute();
        $quoteRows = $stmtQ->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtQ->close();
    }

    // Ultima revision de sistemas
    $revLast = null;
    if (table_exists($conn, 'portal_proyectos_revision_sistemas')) {
        $revOrder = pick_order_col($conn, 'portal_proyectos_revision_sistemas', ['created_at','fecha','created'], 'id');
        $stmtR = $conn->prepare("
            SELECT r.*, u.nombre AS nombre_sistemas
            FROM portal_proyectos_revision_sistemas r
            LEFT JOIN usuarios u ON u.id = r.usuario_sistemas_id
            WHERE r.solicitud_id = ?
            ORDER BY r.version DESC, r.$revOrder DESC
            LIMIT 1
        ");
        $stmtR->bind_param('i', $id);
        $stmtR->execute();
        $revLast = $stmtR->get_result()->fetch_assoc();
        $stmtR->close();
    }

    // Historial / autorizacion
    $hist = [];
    if (table_exists($conn, 'portal_proyectos_historial')) {
        $histOrder = pick_order_col($conn, 'portal_proyectos_historial', ['created_at','fecha','created'], 'id');
        $stmtH = $conn->prepare("
            SELECT h.*, u.nombre AS nombre_usuario
            FROM portal_proyectos_historial h
            LEFT JOIN usuarios u ON u.id = h.usuario_id
            WHERE h.solicitud_id = ?
            ORDER BY h.$histOrder DESC
            LIMIT 80
        ");
        $stmtH->bind_param('i', $id);
        $stmtH->execute();
        $hist = $stmtH->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmtH->close();
    }

    $respSolic = null;
    foreach ($hist as $hrow) {
        $accion = (string)($hrow['accion'] ?? '');
        if ($accion === 'COSTO_AUTORIZADO_SOLICITANTE' || $accion === 'COSTO_RECHAZADO_SOLICITANTE') {
            $respSolic = $hrow;
            break;
        }
    }

    $folio = (string)($sol['folio'] ?? ('SOL-' . $id));
    $filename = 'cotizacion_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $folio) . '.pdf';

    $subtotalOperativo = $sol['subtotal_operativo_mxn'] ?? null;
    $gastosAdmin = $sol['gastos_admin_mxn'] ?? null;
    $iva = $sol['iva_mxn'] ?? null;
    $montoDesarrollo = $sol['monto_desarrollo_mxn'] ?? null;
    $total = $sol['costo_mxn'] ?? null;

    $html = '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 28px 34px; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #172033; }
  .header { border-bottom: 3px solid #0b1f3a; padding-bottom: 12px; margin-bottom: 18px; }
  .brand { font-size: 22px; font-weight: 800; color: #0b1f3a; letter-spacing: .5px; }
  .subtitle { color: #5d6678; font-size: 11px; margin-top: 2px; }
  .folio-box { float: right; text-align: right; border: 1px solid #d8deea; border-radius: 8px; padding: 8px 10px; background: #f7f9fc; }
  .folio { font-size: 16px; font-weight: 800; color: #0b1f3a; }
  .clearfix { clear: both; }
  h1 { font-size: 18px; margin: 12px 0 6px; color: #0b1f3a; }
  h2 { font-size: 13px; margin: 18px 0 8px; color: #0b1f3a; border-bottom: 1px solid #e1e6ef; padding-bottom: 5px; }
  h3 { font-size: 12px; margin: 12px 0 6px; color: #0b1f3a; }
  .grid { width: 100%; border-collapse: collapse; margin-top: 8px; }
  .grid td { vertical-align: top; padding: 6px 8px; border: 1px solid #e1e6ef; }
  .label { font-size: 9px; color: #667085; text-transform: uppercase; letter-spacing: .3px; }
  .value { font-weight: 700; margin-top: 2px; }
  .box { border: 1px solid #e1e6ef; border-radius: 8px; padding: 10px; margin: 8px 0; background: #ffffff; }
  .muted { color: #667085; }
  .pre { white-space: normal; line-height: 1.45; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 8px; }
  table.items th { background: #0b1f3a; color: #ffffff; padding: 7px; font-size: 10px; text-align: left; }
  table.items td { border: 1px solid #dfe5ef; padding: 7px; vertical-align: top; }
  table.items .num { text-align: right; white-space: nowrap; }
  .totals { width: 45%; margin-left: auto; border-collapse: collapse; margin-top: 12px; }
  .totals td { border: 1px solid #dfe5ef; padding: 7px; }
  .totals .k { color: #667085; }
  .totals .v { text-align: right; font-weight: 700; white-space: nowrap; }
  .totals .grand td { background: #0b1f3a; color: #ffffff; font-size: 13px; font-weight: 800; }
  .status-ok { color: #027a48; font-weight: 800; }
  .status-bad { color: #b42318; font-weight: 800; }
  .footer { position: fixed; bottom: -12px; left: 0; right: 0; font-size: 9px; color: #667085; border-top: 1px solid #e1e6ef; padding-top: 6px; }
  .page-break { page-break-before: always; }
</style>
</head>
<body>

<div class="header">
  <div class="folio-box">
    <div class="label">Folio</div>
    <div class="folio">' . h($folio) . '</div>
    <div class="muted">Generado: ' . h(date('d/m/Y H:i')) . '</div>
  </div>
  <div class="brand">Zentral - Sistemas</div>
  <div class="subtitle">Cotizacion completa de desarrollo / documento interno para cobro</div>
  <div class="clearfix"></div>
</div>

<h1>Cotizacion de desarrollo</h1>
<table class="grid">
  <tr>
    <td width="50%"><div class="label">Empresa / central</div><div class="value">' . h(($sol['empresa_clave'] ?? '') . ' - ' . ($sol['empresa_nombre'] ?? '')) . '</div></td>
    <td width="50%"><div class="label">Estatus</div><div class="value">' . h($sol['estatus'] ?? '') . '</div></td>
  </tr>
  <tr>
    <td><div class="label">Solicitante</div><div class="value">' . h($sol['solicitante_nombre'] ?? '-') . '</div><div class="muted">' . h($sol['solicitante_correo'] ?? '') . '</div></td>
    <td><div class="label">Responsable cotizacion</div><div class="value">' . h($sol['costo_capturado_nombre'] ?? ('ID ' . ($sol['costo_capturado_por'] ?? '-'))) . '</div><div class="muted">' . h(fecha_fmt($sol['costo_capturado_at'] ?? '')) . '</div></td>
  </tr>
  <tr>
    <td><div class="label">Tipo</div><div class="value">' . h($sol['tipo'] ?? '-') . '</div></td>
    <td><div class="label">Prioridad</div><div class="value">' . h($sol['prioridad'] ?? '-') . '</div></td>
  </tr>
</table>

<h2>Solicitud original</h2>
<div class="box">
  <h3>' . h($sol['titulo'] ?? '') . '</h3>
  <div class="pre">' . pre($sol['descripcion'] ?? '') . '</div>
</div>

<h2>Alcance y entregables</h2>
<div class="box"><div class="label">Alcance funcional</div><div class="pre">' . pre($sol['alcance_funcional'] ?? '-') . '</div></div>
<div class="box"><div class="label">Entregables</div><div class="pre">' . pre($sol['entregables'] ?? '-') . '</div></div>
<div class="box"><div class="label">Exclusiones / fuera de alcance</div><div class="pre">' . pre($sol['exclusiones'] ?? '-') . '</div></div>
';

    if ($revLast) {
        $html .= '
<h2>Planeacion tecnica</h2>
<table class="grid">
  <tr>
    <td width="25%"><div class="label">Version revision</div><div class="value">' . h($revLast['version'] ?? '-') . '</div></td>
    <td width="25%"><div class="label">Horas min</div><div class="value">' . h($revLast['horas_min'] ?? '-') . '</div></td>
    <td width="25%"><div class="label">Horas max</div><div class="value">' . h($revLast['horas_max'] ?? '-') . '</div></td>
    <td width="25%"><div class="label">Revisado por</div><div class="value">' . h($revLast['nombre_sistemas'] ?? '-') . '</div></td>
  </tr>
</table>
<div class="box"><div class="label">Plan de acciones</div><div class="pre">' . pre($revLast['plan_acciones'] ?? '-') . '</div></div>
<div class="box"><div class="label">Riesgos / dependencias</div><div class="pre">' . pre($revLast['riesgos_dependencias'] ?? '-') . '</div></div>
';
    }

    $html .= '
<h2>Planning y tiempos</h2>
<table class="grid">
  <tr>
    <td width="25%"><div class="label">Horas estimadas</div><div class="value">' . h($sol['horas_estimadas'] ?? '-') . ' h</div></td>
    <td width="25%"><div class="label">Inicio estimado</div><div class="value">' . h(fecha_corta($sol['fecha_estimada_inicio'] ?? '')) . '</div></td>
    <td width="25%"><div class="label">Entrega estimada</div><div class="value">' . h(fecha_corta($sol['fecha_estimada_entrega'] ?? '')) . '</div></td>
    <td width="25%"><div class="label">Fecha creacion</div><div class="value">' . h(fecha_fmt($sol['created_at'] ?? '')) . '</div></td>
  </tr>
  <tr>
    <td><div class="label">Inicio real</div><div class="value">' . h(fecha_fmt($sol['fecha_inicio'] ?? '')) . '</div></td>
    <td><div class="label">Pruebas</div><div class="value">' . h(fecha_fmt($sol['fecha_pruebas'] ?? '')) . '</div></td>
    <td><div class="label">Liberacion</div><div class="value">' . h(fecha_fmt($sol['fecha_liberacion'] ?? '')) . '</div></td>
    <td><div class="label">Cierre</div><div class="value">' . h(fecha_fmt($sol['fecha_cierre'] ?? '')) . '</div></td>
  </tr>
</table>

<h2>Desglose economico completo</h2>
<table class="items">
  <thead>
    <tr>
      <th>Concepto</th>
      <th class="num">Horas</th>
      <th class="num">Costo/Hora</th>
      <th class="num">Subtotal</th>
      <th>Observaciones</th>
    </tr>
  </thead>
  <tbody>';

    if (!empty($quoteRows)) {
        foreach ($quoteRows as $qr) {
            $html .= '<tr>
                <td>' . h($qr['concepto'] ?? '') . '</td>
                <td class="num">' . h($qr['horas'] ?? '-') . '</td>
                <td class="num">' . h(money_fmt($qr['costo_hora'] ?? null)) . '</td>
                <td class="num">' . h(money_fmt($qr['subtotal'] ?? null)) . '</td>
                <td>' . h($qr['observaciones'] ?? '') . '</td>
            </tr>';
        }
    } else {
        $html .= '<tr><td colspan="5" class="muted">Sin partidas registradas.</td></tr>';
    }

    $html .= '</tbody></table>

<table class="totals">
  <tr><td class="k">Subtotal operativo</td><td class="v">' . h(money_fmt($subtotalOperativo)) . '</td></tr>
  <tr><td class="k">Gastos administrativos / operacion</td><td class="v">' . h(money_fmt($gastosAdmin)) . '</td></tr>
  <tr><td class="k">IVA</td><td class="v">' . h(money_fmt($iva)) . '</td></tr>
  <tr><td class="k">Monto desarrollo</td><td class="v">' . h(money_fmt($montoDesarrollo)) . '</td></tr>
  <tr class="grand"><td>Total proyecto</td><td>' . h(money_fmt($total)) . '</td></tr>
</table>

<h2>Condiciones comerciales y operativas</h2>
<div class="box"><div class="pre">' . pre($sol['condiciones'] ?? '-') . '</div></div>
';

    if ($respSolic) {
        $accion = (string)($respSolic['accion'] ?? '');
        $isOk = ($accion === 'COSTO_AUTORIZADO_SOLICITANTE');
        $html .= '
<h2>Respuesta del solicitante</h2>
<table class="grid">
  <tr>
    <td width="33%"><div class="label">Resultado</div><div class="value ' . ($isOk ? 'status-ok' : 'status-bad') . '">' . ($isOk ? 'AUTORIZADO' : 'RECHAZADO') . '</div></td>
    <td width="33%"><div class="label">Usuario / actor</div><div class="value">' . h($respSolic['nombre_usuario'] ?? $respSolic['actor'] ?? '-') . '</div></td>
    <td width="34%"><div class="label">Fecha</div><div class="value">' . h(fecha_fmt($respSolic['created_at'] ?? $respSolic['fecha'] ?? '')) . '</div></td>
  </tr>
</table>
<div class="box"><div class="label">Comentario</div><div class="pre">' . pre($respSolic['comentario'] ?? '-') . '</div></div>
';
    }

    if (!empty($hist)) {
        $html .= '<h2>Bitacora del proyecto</h2><table class="items"><thead><tr><th>Fecha</th><th>Accion</th><th>Estatus</th><th>Usuario</th><th>Comentario</th></tr></thead><tbody>';
        foreach (array_reverse($hist) as $hrow) {
            $fecha = $hrow['created_at'] ?? $hrow['fecha'] ?? '';
            $estatusCambio = trim((string)($hrow['estatus_anterior'] ?? '') . ' -> ' . (string)($hrow['estatus_nuevo'] ?? ''), ' ->');
            $html .= '<tr>
              <td>' . h(fecha_fmt($fecha)) . '</td>
              <td>' . h($hrow['accion'] ?? '') . '</td>
              <td>' . h($estatusCambio) . '</td>
              <td>' . h($hrow['nombre_usuario'] ?? $hrow['actor'] ?? '-') . '</td>
              <td>' . h($hrow['comentario'] ?? '') . '</td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '
<div class="footer">
  Documento generado por Zentral / Sistemas. Uso interno para validacion, cobro y seguimiento del desarrollo. Folio ' . h($folio) . '.
</div>
</body>
</html>';

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error generando PDF: ' . h($e->getMessage());
    exit;
}
