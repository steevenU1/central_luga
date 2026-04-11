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
$conn->set_charset("utf8mb4");

/* =========================================================
   CONTEXTO
========================================================= */
$ID_USUARIO   = (int)($_SESSION['id_usuario'] ?? 0);
$ROL          = trim((string)($_SESSION['rol'] ?? ''));
$ID_SUCURSAL  = (int)($_SESSION['id_sucursal'] ?? 0);
$ID_SUBDIS    = isset($_SESSION['id_subdis']) && $_SESSION['id_subdis'] !== '' ? (int)$_SESSION['id_subdis'] : null;

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona',
    'Gerente', 'Encargado', 'Supervisor'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para ver auditorías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function fmtFechaHora(?string $fecha): string {
    if (!$fecha) return '-';
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

function estadoClase(string $estatus): string {
    $e = mb_strtolower(trim($estatus), 'UTF-8');
    return match ($e) {
        'en proceso' => 'badge warning',
        'cerrada', 'finalizada', 'completada' => 'badge success',
        'cancelada' => 'badge danger',
        default => 'badge secondary',
    };
}

/* =========================================================
   INPUT
========================================================= */
$id_auditoria = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id_auditoria <= 0) {
    http_response_code(400);
    exit('ID de auditoría inválido.');
}

/* =========================================================
   CARGAR AUDITORÍA
========================================================= */
$sqlAud = "
    SELECT
        a.id,
        a.folio,
        a.id_sucursal,
        a.id_auditor,
        a.id_gerente,
        a.propiedad,
        a.id_subdis,
        a.fecha_inicio,
        a.estatus,
        a.observaciones_inicio,
        a.total_snapshot,
        a.total_lineas_accesorios,
        s.nombre AS sucursal_nombre,
        u1.nombre AS auditor_nombre,
        u1.rol AS auditor_rol,
        u2.nombre AS responsable_nombre,
        u2.rol AS responsable_rol
    FROM auditorias a
    INNER JOIN sucursales s ON s.id = a.id_sucursal
    LEFT JOIN usuarios u1 ON u1.id = a.id_auditor
    LEFT JOIN usuarios u2 ON u2.id = a.id_gerente
    WHERE a.id = ?
    LIMIT 1
";
$stmtAud = $conn->prepare($sqlAud);
$stmtAud->bind_param("i", $id_auditoria);
$stmtAud->execute();
$resAud = $stmtAud->get_result();
$auditoria = $resAud->fetch_assoc();

if (!$auditoria) {
    http_response_code(404);
    exit('La auditoría no existe.');
}

/* =========================================================
   PERMISOS POR SUCURSAL
========================================================= */
if (!in_array($ROL, ['Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona'], true)) {
    if ((int)$auditoria['id_sucursal'] !== $ID_SUCURSAL) {
        http_response_code(403);
        exit('No tienes permiso para ver esta auditoría.');
    }
}

/* =========================================================
   CONTADORES DE AVANCE
========================================================= */
$totalSerializados = 0;
$totalNoSerializados = 0;
$totalEscaneados = 0;
$totalCapturadosCant = 0;

/* Snapshot serializados esperados */
if ($conn->query("SHOW TABLES LIKE 'auditorias_snapshot'")->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM auditorias_snapshot
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalSerializados = (int)($row['total'] ?? 0);
}

/* Snapshot no serializados esperados */
if ($conn->query("SHOW TABLES LIKE 'auditorias_snapshot_cantidades'")->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM auditorias_snapshot_cantidades
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalNoSerializados = (int)($row['total'] ?? 0);
}

/* Escaneados reales, si existe tabla */
if ($conn->query("SHOW TABLES LIKE 'auditorias_escaneo'")->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM auditorias_escaneo
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalEscaneados = (int)($row['total'] ?? 0);
}

/* Captura cantidades reales, si existe tabla */
if ($conn->query("SHOW TABLES LIKE 'auditorias_captura_cantidades'")->num_rows > 0) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM auditorias_captura_cantidades
        WHERE id_auditoria = ?
    ");
    $stmt->bind_param("i", $id_auditoria);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $totalCapturadosCant = (int)($row['total'] ?? 0);
}

/* Etiquetas dinámicas */
$txtBtnEscaneo = $totalEscaneados > 0 ? 'Continuar escaneo' : 'Iniciar escaneo';
$txtBtnCant    = $totalCapturadosCant > 0 ? 'Continuar captura' : 'Iniciar captura';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inicio de auditoría</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            background:#f6f8fb;
            font-family: Arial, Helvetica, sans-serif;
            color:#1f2937;
        }
        .wrap{
            max-width: 1120px;
            margin: 28px auto;
            padding: 0 16px 40px;
        }
        .hero{
            background:#fff;
            border-radius:22px;
            box-shadow:0 10px 28px rgba(0,0,0,.06);
            padding:28px;
            margin-bottom:22px;
        }
        .hero-top{
            display:flex;
            justify-content:space-between;
            align-items:flex-start;
            gap:16px;
            flex-wrap:wrap;
        }
        .title{
            margin:0 0 8px;
            font-size:32px;
            font-weight:800;
            color:#111827;
        }
        .subtitle{
            margin:0;
            color:#6b7280;
            font-size:15px;
        }
        .badge{
            display:inline-flex;
            align-items:center;
            padding:8px 12px;
            border-radius:999px;
            font-size:12px;
            font-weight:700;
            white-space:nowrap;
        }
        .badge.warning{ background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }
        .badge.success{ background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; }
        .badge.danger{ background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
        .badge.secondary{ background:#f3f4f6; color:#374151; border:1px solid #d1d5db; }

        .info-grid{
            display:grid;
            grid-template-columns: repeat(4, 1fr);
            gap:16px;
            margin-top:22px;
        }
        .info-card{
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:18px;
            padding:18px;
        }
        .info-label{
            font-size:12px;
            font-weight:700;
            color:#6b7280;
            text-transform:uppercase;
            letter-spacing:.04em;
            margin-bottom:8px;
        }
        .info-value{
            font-size:16px;
            font-weight:700;
            color:#111827;
            line-height:1.4;
        }

        .modules{
            display:grid;
            grid-template-columns: repeat(2, 1fr);
            gap:20px;
            margin-top:22px;
        }
        .module-card{
            background:#fff;
            border-radius:22px;
            box-shadow:0 10px 28px rgba(0,0,0,.06);
            padding:24px;
            border:1px solid #eef2f7;
        }
        .module-title{
            margin:0 0 8px;
            font-size:24px;
            font-weight:800;
            color:#111827;
        }
        .module-text{
            margin:0 0 20px;
            color:#6b7280;
            font-size:15px;
            line-height:1.55;
        }
        .kpis{
            display:grid;
            grid-template-columns: repeat(2, 1fr);
            gap:14px;
            margin-bottom:20px;
        }
        .kpi{
            background:#f9fafb;
            border:1px solid #e5e7eb;
            border-radius:16px;
            padding:16px;
        }
        .kpi-label{
            font-size:12px;
            font-weight:700;
            color:#6b7280;
            text-transform:uppercase;
            margin-bottom:6px;
        }
        .kpi-value{
            font-size:24px;
            font-weight:800;
            color:#111827;
        }
        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }
        .btn{
            border:none;
            border-radius:14px;
            padding:13px 18px;
            cursor:pointer;
            font-weight:800;
            font-size:14px;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            transition:.18s ease;
        }
        .btn:hover{
            transform:translateY(-1px);
        }
        .btn-primary{
            background:#111827;
            color:#fff;
        }
        .btn-secondary{
            background:#eef2f7;
            color:#111827;
        }
        .footer-card{
            margin-top:22px;
            background:#fff;
            border-radius:20px;
            box-shadow:0 10px 28px rgba(0,0,0,.06);
            padding:22px;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:16px;
            flex-wrap:wrap;
        }
        .footer-text{
            color:#6b7280;
            font-size:14px;
        }

        @media (max-width: 980px){
            .info-grid{ grid-template-columns: repeat(2, 1fr); }
            .modules{ grid-template-columns: 1fr; }
        }
        @media (max-width: 640px){
            .info-grid{ grid-template-columns: 1fr; }
            .kpis{ grid-template-columns: 1fr; }
            .title{ font-size:28px; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <div class="hero">
        <div class="hero-top">
            <div>
                <h1 class="title">Auditoría <?= h($auditoria['folio']) ?></h1>
                <p class="subtitle">Selecciona el módulo con el que deseas continuar.</p>
            </div>
            <span class="<?= h(estadoClase((string)$auditoria['estatus'])) ?>">
                <?= h($auditoria['estatus'] ?: 'Sin estatus') ?>
            </span>
        </div>

        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">Sucursal</div>
                <div class="info-value"><?= h($auditoria['sucursal_nombre']) ?></div>
            </div>

            <div class="info-card">
                <div class="info-label">Auditor</div>
                <div class="info-value">
                    <?= h($auditoria['auditor_nombre'] ?: 'No asignado') ?>
                    <?php if (!empty($auditoria['auditor_rol'])): ?>
                        <br><span style="font-size:13px;font-weight:600;color:#6b7280;"><?= h($auditoria['auditor_rol']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <div class="info-label">Responsable presente</div>
                <div class="info-value">
                    <?= h($auditoria['responsable_nombre'] ?: 'No definido') ?>
                    <?php if (!empty($auditoria['responsable_rol'])): ?>
                        <br><span style="font-size:13px;font-weight:600;color:#6b7280;"><?= h($auditoria['responsable_rol']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-card">
                <div class="info-label">Fecha de inicio</div>
                <div class="info-value"><?= h(fmtFechaHora($auditoria['fecha_inicio'])) ?></div>
            </div>
        </div>
    </div>

    <div class="modules">
        <div class="module-card">
            <h2 class="module-title">Escaneo de equipos serializados</h2>
            <p class="module-text">
                Ingresa al módulo para registrar y validar equipos controlados por IMEI o número de serie.
            </p>

            <div class="kpis">
                <div class="kpi">
                    <div class="kpi-label">Esperados</div>
                    <div class="kpi-value"><?= (int)$totalSerializados ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Escaneados</div>
                    <div class="kpi-value"><?= (int)$totalEscaneados ?></div>
                </div>
            </div>

            <div class="actions">
                <a href="auditorias_escanear.php?id=<?= (int)$auditoria['id'] ?>" class="btn btn-primary">
                    <?= h($txtBtnEscaneo) ?>
                </a>
            </div>
        </div>

        <div class="module-card">
            <h2 class="module-title">Captura de productos no serializados</h2>
            <p class="module-text">
                Ingresa al módulo para capturar productos manejados por cantidad, como accesorios u otros artículos no serializados.
            </p>

            <div class="kpis">
                <div class="kpi">
                    <div class="kpi-label">Líneas esperadas</div>
                    <div class="kpi-value"><?= (int)$totalNoSerializados ?></div>
                </div>
                <div class="kpi">
                    <div class="kpi-label">Líneas capturadas</div>
                    <div class="kpi-value"><?= (int)$totalCapturadosCant ?></div>
                </div>
            </div>

            <div class="actions">
                <a href="auditorias_captura.php?id=<?= (int)$auditoria['id'] ?>" class="btn btn-primary">
                    <?= h($txtBtnCant) ?>
                </a>
            </div>
        </div>
    </div>

    <div class="footer-card">
        <div class="footer-text">
            Puedes entrar a cualquiera de los dos módulos y volver aquí cuando necesites continuar con el otro.
        </div>
        <div class="actions">
            <a href="auditorias_detalle.php?id=<?= (int)$auditoria['id'] ?>" class="btn btn-secondary">Ver detalle</a>
            <a href="auditorias.php" class="btn btn-secondary">Volver al listado</a>
        </div>
    </div>

</div>
</body>
</html>