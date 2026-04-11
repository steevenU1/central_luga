<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO / PERMISOS
========================================================= */
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ROL         = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);

$ROLES_PERMITIDOS = [
    'Admin',
    'Administrador',
    'Auditor',
    'Logistica',
    'GerenteZona',
    'Gerente',
    'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para generar actas de auditoría.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmtFecha(?string $f, string $format = 'd/m/Y H:i'): string
{
    if (!$f || $f === '0000-00-00' || $f === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($f);
    return $ts ? date($format, $ts) : (string)$f;
}

function diffTexto($valor): string
{
    if ($valor === null || $valor === '') return '-';
    $v = (int)$valor;
    return ($v > 0 ? '+' : '') . $v;
}

/* =========================================================
   ID AUDITORIA
========================================================= */
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_auditoria <= 0) {
    exit('Auditoría no válida.');
}

/* =========================================================
   CARGAR AUDITORIA
========================================================= */
$stmtAud = $conn->prepare("
    SELECT
        a.*,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona,
        s.tipo_sucursal,
        u1.nombre AS auditor_nombre,
        u2.nombre AS gerente_nombre,
        u3.nombre AS cerrada_por_nombre
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    INNER JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    LEFT JOIN usuarios u3 ON u3.id = a.cerrada_por
    WHERE a.id = ?
    LIMIT 1
");
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$auditoria = $stmtAud->get_result()->fetch_assoc();

if (!$auditoria) {
    exit('La auditoría no existe.');
}

if (in_array($ROL, ['Gerente', 'Supervisor'], true) && (int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
    http_response_code(403);
    exit('No puedes consultar una auditoría de otra sucursal.');
}

if (($auditoria['estatus'] ?? '') !== 'Cerrada') {
    exit('La auditoría aún no está cerrada. Primero debes cerrarla para generar el acta.');
}

/* =========================================================
   FALTANTES UNITARIOS
========================================================= */
$stmtFalt = $conn->prepare("
    SELECT *
    FROM auditorias_faltantes
    WHERE id_auditoria = ?
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC, imei1 ASC
");
$stmtFalt->bind_param("i", $id_auditoria);
$stmtFalt->execute();
$faltantes = $stmtFalt->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   DIFERENCIAS EN CANTIDADES
========================================================= */
$stmtDiff = $conn->prepare("
    SELECT *
    FROM auditorias_snapshot_cantidades
    WHERE id_auditoria = ?
      AND diferencia IS NOT NULL
      AND diferencia <> 0
    ORDER BY marca ASC, modelo ASC, color ASC, capacidad ASC
");
$stmtDiff->bind_param("i", $id_auditoria);
$stmtDiff->execute();
$diferencias = $stmtDiff->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   INCIDENCIAS
========================================================= */
$stmtInc = $conn->prepare("
    SELECT *
    FROM auditorias_incidencias
    WHERE id_auditoria = ?
    ORDER BY id ASC
");
$stmtInc->bind_param("i", $id_auditoria);
$stmtInc->execute();
$incidencias = $stmtInc->get_result()->fetch_all(MYSQLI_ASSOC);

/* =========================================================
   RESUMEN EXTRA
========================================================= */
$stmtEsc = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM auditorias_escaneos
    WHERE id_auditoria = ?
");
$stmtEsc->bind_param("i", $id_auditoria);
$stmtEsc->execute();
$totalEscaneosRegistrados = (int)($stmtEsc->get_result()->fetch_assoc()['total'] ?? 0);

/* =========================================================
   CONFIG LOGO
   Ajusta esta ruta si tu logo está en otro lado.
========================================================= */
$logoPathCandidates = [
    __DIR__ . '/assets/logo_luga.png',
];

$logoWebPath = '';
foreach ($logoPathCandidates as $absPath) {
    if (file_exists($absPath)) {
        $logoWebPath = str_replace(__DIR__, '', $absPath);
        if ($logoWebPath === '') {
            $logoWebPath = basename($absPath);
        }
        $logoWebPath = ltrim(str_replace('\\', '/', $logoWebPath), '/');
        $logoWebPath = './' . $logoWebPath;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Acta de Auditoría - <?= h($auditoria['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --ink: #1f2937;
            --muted: #6b7280;
            --line: #d1d5db;
            --soft: #f3f4f6;
            --soft2: #f9fafb;
            --danger: #991b1b;
            --success: #065f46;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #eef2f7;
            font-family: Arial, Helvetica, sans-serif;
            color: var(--ink);
        }

        .page {
            width: 210mm;
            min-height: 297mm;
            margin: 16px auto;
            background: #fff;
            box-shadow: 0 10px 28px rgba(0, 0, 0, .10);
            padding: 14mm 14mm 16mm;
        }

        .toolbar {
            width: 210mm;
            margin: 16px auto 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 14px;
            cursor: pointer;
            font-weight: 700;
            font-size: 13px;
        }

        .btn-print {
            background: #111827;
            color: #fff;
        }

        .btn-back {
            background: #e5e7eb;
            color: #111827;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
        }

        .header {
            display: grid;
            grid-template-columns: 120px 1fr 150px;
            gap: 14px;
            align-items: center;
            border-bottom: 2px solid #111827;
            padding-bottom: 12px;
            margin-bottom: 18px;
        }

        .logo-box {
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 10px;
            overflow: hidden;
            background: #fff;
        }

        .logo-box img {
            max-width: 100%;
            max-height: 100%;
            object-fit: contain;
        }

        .logo-fallback {
            font-size: 22px;
            font-weight: 900;
            letter-spacing: 1px;
        }

        .doc-title {
            text-align: center;
        }

        .doc-title h1 {
            margin: 0 0 6px;
            font-size: 24px;
            line-height: 1.15;
            letter-spacing: .2px;
        }

        .doc-title .sub {
            color: var(--muted);
            font-size: 13px;
        }

        .folio-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            background: var(--soft2);
        }

        .folio-label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .folio-value {
            font-size: 16px;
            font-weight: 800;
            word-break: break-word;
        }

        .section {
            margin-bottom: 18px;
        }

        .section-title {
            margin: 0 0 10px;
            font-size: 16px;
            font-weight: 800;
            background: #111827;
            color: #fff;
            padding: 8px 12px;
            border-radius: 8px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px 14px;
        }

        .info-item {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            background: #fff;
        }

        .info-item.full {
            grid-column: 1 / -1;
        }

        .info-label {
            font-size: 11px;
            color: var(--muted);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: .3px;
        }

        .info-value {
            font-size: 14px;
            font-weight: 700;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 12px;
        }

        .summary-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px;
            background: linear-gradient(180deg, #fff 0%, #f9fafb 100%);
        }

        .summary-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .summary-value {
            font-size: 26px;
            font-weight: 900;
            line-height: 1;
        }

        .summary-caption {
            margin-top: 4px;
            font-size: 11px;
            color: var(--muted);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;
            table-layout: fixed;
        }

        th,
        td {
            border: 1px solid var(--line);
            padding: 6px 7px;
            vertical-align: top;
            word-break: normal;
            overflow-wrap: break-word;
        }

        th {
            background: var(--soft);
            text-align: left;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: .2px;
        }

        .empty {
            border: 1px dashed var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            background: var(--soft2);
            font-size: 13px;
            color: var(--muted);
        }

        .text-danger {
            color: var(--danger);
            font-weight: 800;
        }

        .text-success {
            color: var(--success);
            font-weight: 800;
        }

        .obs-box {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px 14px;
            background: #fff;
            min-height: 70px;
            white-space: pre-wrap;
            font-size: 13px;
        }

        .footer-sign {
            margin-top: 28px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 40px;
            align-items: end;
        }

        .sign-box {
            text-align: center;
            padding-top: 28px;
        }

        .sign-line {
            border-top: 1.8px solid #111827;
            margin-bottom: 8px;
            height: 1px;
        }

        .sign-name {
            font-size: 13px;
            font-weight: 800;
        }

        .sign-role {
            font-size: 12px;
            color: var(--muted);
        }

        .note {
            margin-top: 22px;
            font-size: 11px;
            color: var(--muted);
            text-align: justify;
            line-height: 1.45;
        }

        .page-break {
            page-break-before: always;
        }

        @media print {
            body {
                background: #fff;
            }

            .toolbar {
                display: none !important;
            }

            .page {
                width: auto;
                min-height: auto;
                margin: 0;
                box-shadow: none;
                padding: 0;
            }

            @page {
                size: A4;
                margin: 12mm;
            }
        }
    </style>
</head>

<body>

    <div class="toolbar">
        <a href="auditorias_conciliar.php?id=<?= (int)$id_auditoria ?>" class="btn btn-back">Volver</a>
        <button class="btn btn-print" onclick="window.print()">Imprimir acta</button>
    </div>

    <div class="page">

        <div class="header">
            <div class="logo-box">
                <?php if ($logoWebPath): ?>
                    <img src="<?= h($logoWebPath) ?>" alt="Logo">
                <?php else: ?>
                    <div class="logo-fallback">LUGA</div>
                <?php endif; ?>
            </div>

            <div class="doc-title">
                <h1>ACTA DE AUDITORÍA DE INVENTARIO</h1>
                <div class="sub">Control interno de inventario físico y conciliación contra sistema</div>
            </div>

            <div class="folio-box">
                <div class="folio-label">Folio de auditoría</div>
                <div class="folio-value"><?= h($auditoria['folio']) ?></div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Datos generales de la auditoría</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Sucursal</div>
                    <div class="info-value"><?= h($auditoria['sucursal_nombre']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Zona</div>
                    <div class="info-value"><?= h($auditoria['sucursal_zona'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Tipo de sucursal</div>
                    <div class="info-value"><?= h($auditoria['tipo_sucursal'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Propiedad</div>
                    <div class="info-value"><?= h($auditoria['propiedad'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Auditor responsable</div>
                    <div class="info-value"><?= h($auditoria['auditor_nombre']) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Gerente / encargado presente</div>
                    <div class="info-value"><?= h($auditoria['gerente_nombre'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Fecha y hora de inicio</div>
                    <div class="info-value"><?= h(fmtFecha($auditoria['fecha_inicio'])) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Fecha y hora de cierre</div>
                    <div class="info-value"><?= h(fmtFecha($auditoria['fecha_cierre'])) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Cerrada por</div>
                    <div class="info-value"><?= h($auditoria['cerrada_por_nombre'] ?: '-') ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Estatus</div>
                    <div class="info-value"><?= h($auditoria['estatus']) ?></div>
                </div>
                <div class="info-item full">
                    <div class="info-label">Observaciones iniciales</div>
                    <div class="info-value"><?= nl2br(h($auditoria['observaciones_inicio'] ?: '-')) ?></div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Resumen general de resultados</div>

            <div style="font-weight:800; margin:0 0 8px;">A. Resultados de productos unitarios</div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Esperados</div>
                    <div class="summary-value"><?= (int)$auditoria['total_snapshot'] ?></div>
                    <div class="summary-caption">Snapshot inicial</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Escaneados</div>
                    <div class="summary-value"><?= (int)$auditoria['total_escaneados'] ?></div>
                    <div class="summary-caption">Registros de escaneo</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Encontrados OK</div>
                    <div class="summary-value text-success"><?= (int)$auditoria['total_ok'] ?></div>
                    <div class="summary-caption">Coincidencia correcta</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Faltantes</div>
                    <div class="summary-value text-danger"><?= (int)$auditoria['total_faltantes'] ?></div>
                    <div class="summary-caption">No escaneados al cierre</div>
                </div>
            </div>

            <div style="font-weight:800; margin:14px 0 8px;">B. Resultados de productos por cantidad</div>
            <div class="summary-grid">
                <div class="summary-card">
                    <div class="summary-label">Líneas esperadas</div>
                    <div class="summary-value"><?= (int)$auditoria['total_lineas_accesorios'] ?></div>
                    <div class="summary-caption">Productos por cantidad</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Líneas contadas</div>
                    <div class="summary-value"><?= (int)$auditoria['total_accesorios_contados'] ?></div>
                    <div class="summary-caption">Conteo realizado</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Con diferencia</div>
                    <div class="summary-value text-danger"><?= (int)$auditoria['total_accesorios_con_diferencia'] ?></div>
                    <div class="summary-caption">Diferencias detectadas</div>
                </div>
                <div class="summary-card">
                    <div class="summary-label">Incidencias</div>
                    <div class="summary-value"><?= (int)$auditoria['total_incidencias'] ?></div>
                    <div class="summary-caption">Eventos atípicos</div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Detalle de equipos faltantes</div>
            <?php if (!$faltantes): ?>
                <div class="empty">No se detectaron equipos faltantes al momento del cierre de la auditoría.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Código</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Color</th>
                            <th>Capacidad</th>
                            <th>IMEI 1</th>
                            <th>IMEI 2</th>
                            <th>Estatus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1;
                        foreach ($faltantes as $row): ?>
                            <tr>
                                <td><?= $n++ ?></td>
                                <td><?= h($row['codigo_producto']) ?></td>
                                <td><?= h($row['marca']) ?></td>
                                <td><?= h($row['modelo']) ?></td>
                                <td><?= h($row['color']) ?></td>
                                <td><?= h($row['capacidad']) ?></td>
                                <td><?= h($row['imei1']) ?></td>
                                <td><?= h($row['imei2']) ?></td>
                                <td><?= h($row['estatus']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-title">Detalle de diferencias en productos por cantidad</div>
            <?php if (!$diferencias): ?>
                <div class="empty">No se detectaron diferencias en productos controlados por cantidad.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Código</th>
                            <th>Marca</th>
                            <th>Modelo</th>
                            <th>Color</th>
                            <th>Capacidad</th>
                            <th>Sistema</th>
                            <th>Contado</th>
                            <th>Diferencia</th>
                            <th>Observaciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1;
                        foreach ($diferencias as $row): ?>
                            <tr>
                                <td><?= $n++ ?></td>
                                <td><?= h($row['codigo_producto']) ?></td>
                                <td><?= h($row['marca']) ?></td>
                                <td><?= h($row['modelo']) ?></td>
                                <td><?= h($row['color']) ?></td>
                                <td><?= h($row['capacidad']) ?></td>
                                <td><?= (int)$row['cantidad_sistema'] ?></td>
                                <td><?= (int)$row['cantidad_contada'] ?></td>
                                <td><?= h(diffTexto($row['diferencia'])) ?></td>
                                <td><?= nl2br(h($row['observaciones'] ?: '-')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-title">Detalle de incidencias registradas</div>
            <?php if (!$incidencias): ?>
                <div class="empty">No se registraron incidencias durante la auditoría.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>IMEI / Identificador</th>
                            <th>Tipo de incidencia</th>
                            <th>Detalle</th>
                            <th>Referencia</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $n = 1;
                        foreach ($incidencias as $row): ?>
                            <tr>
                                <td><?= $n++ ?></td>
                                <td><?= h($row['imei_escaneado']) ?></td>
                                <td><?= h($row['tipo_incidencia']) ?></td>
                                <td><?= h($row['detalle']) ?></td>
                                <td>
                                    <?= h($row['referencia_tabla'] ?: '-') ?>
                                    <?php if (!empty($row['referencia_id'])): ?>
                                        #<?= (int)$row['referencia_id'] ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-title">Observaciones finales de cierre</div>
            <div class="obs-box"><?= nl2br(h($auditoria['observaciones_cierre'] ?: 'Sin observaciones finales registradas.')) ?></div>
        </div>

        <div class="footer-sign">
            <div class="sign-box">
                <div class="sign-line"></div>
                <div class="sign-name"><?= h($auditoria['auditor_nombre']) ?></div>
                <div class="sign-role">Auditor responsable</div>
            </div>
            <div class="sign-box">
                <div class="sign-line"></div>
                <div class="sign-name"><?= h($auditoria['gerente_nombre'] ?: 'Nombre y firma') ?></div>
                <div class="sign-role">Gerente / encargado de sucursal</div>
            </div>
        </div>

        <div class="note">
            El presente documento refleja los resultados finales de la auditoría de inventario realizada sobre la sucursal indicada,
            comparando el inventario esperado en sistema contra la evidencia física levantada durante la revisión. Las diferencias,
            faltantes e incidencias aquí señaladas constituyen evidencia documental para seguimiento interno y deberán resolverse
            conforme a los procedimientos administrativos y operativos aplicables.
        </div>

    </div>

</body>

</html>