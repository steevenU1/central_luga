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
$NOMBRE_USR   = trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario'));
$PROPIEDAD_SES = trim((string)($_SESSION['propiedad'] ?? 'LUGA'));

/* =========================================================
   PERMISOS
========================================================= */
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Auditor', 'Logistica', 'GerenteZona'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para crear auditorías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function normalizarPropiedad(?string $p): string {
    $p = strtoupper(trim((string)$p));
    if ($p === 'SUBDISTRIBUIDOR' || $p === 'SUBDIS' || $p === 'SUBDISTRIBUIDOR ') {
        return 'SUBDISTRIBUIDOR';
    }
    return 'LUGA';
}

function generarFolioAuditoria(mysqli $conn, string $propiedad = 'LUGA'): string {
    $anio = date('Y');
    $prefijo = ($propiedad === 'SUBDISTRIBUIDOR') ? 'AUD-SUB' : 'AUD-LUGA';

    $like = $prefijo . '-' . $anio . '-%';
    $stmt = $conn->prepare("
        SELECT folio
        FROM auditorias
        WHERE folio LIKE ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $ultimo = $res->fetch_assoc()['folio'] ?? '';

    $consecutivo = 1;
    if ($ultimo && preg_match('/-(\d{4})$/', $ultimo, $m)) {
        $consecutivo = ((int)$m[1]) + 1;
    }

    return sprintf('%s-%s-%04d', $prefijo, $anio, $consecutivo);
}

function fetchAllAssoc(mysqli_stmt $stmt): array {
    $stmt->execute();
    $res = $stmt->get_result();
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/* =========================================================
   CARGA DE SUCURSALES
========================================================= */
$sucursales = [];

if (in_array($ROL, ['Admin', 'Administrador', 'Logistica', 'Auditor'], true)) {
    $sqlSuc = "
        SELECT id, nombre, propiedad, id_subdis, activo
        FROM sucursales
        WHERE activo = 1
        ORDER BY nombre ASC
    ";
    $rsSuc = $conn->query($sqlSuc);
    $sucursales = $rsSuc->fetch_all(MYSQLI_ASSOC);
} else {
    $stmtSuc = $conn->prepare("
        SELECT id, nombre, propiedad, id_subdis, activo
        FROM sucursales
        WHERE id = ?
        LIMIT 1
    ");
    $stmtSuc->bind_param("i", $ID_SUCURSAL);
    $sucursales = fetchAllAssoc($stmtSuc);
}

/* =========================================================
   CARGA DE GERENTES / ENCARGADOS
   Ajusta roles si manejan nombres distintos
========================================================= */
$gerentes = [];
$sqlGer = "
    SELECT id, nombre, rol, id_sucursal
    FROM usuarios
    WHERE rol IN ('Gerente', 'GerenteZona', 'Encargado', 'Supervisor', 'Admin', 'Administrador')
    ORDER BY nombre ASC
";
$rsGer = $conn->query($sqlGer);
if ($rsGer) {
    $gerentes = $rsGer->fetch_all(MYSQLI_ASSOC);
}

/* =========================================================
   POST
========================================================= */
$errores = [];
$ok = '';
$debugInfo = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id_sucursal           = (int)($_POST['id_sucursal'] ?? 0);
    $id_gerente            = !empty($_POST['id_gerente']) ? (int)$_POST['id_gerente'] : null;
    $observaciones_inicio  = trim((string)($_POST['observaciones_inicio'] ?? ''));
    $redir_debug           = isset($_POST['debug']) ? 1 : 0;

    if ($id_sucursal <= 0) {
        $errores[] = 'Debes seleccionar una sucursal.';
    }

    // Obtener datos reales de sucursal
    $sucursal = null;
    if ($id_sucursal > 0) {
        $stmt = $conn->prepare("
            SELECT id, nombre, propiedad, id_subdis, activo
            FROM sucursales
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $id_sucursal);
        $stmt->execute();
        $res = $stmt->get_result();
        $sucursal = $res->fetch_assoc();

        if (!$sucursal) {
            $errores[] = 'La sucursal seleccionada no existe.';
        } elseif ((int)$sucursal['activo'] !== 1) {
            $errores[] = 'La sucursal seleccionada no está activa.';
        }
    }

    // Si no es admin/logistica/auditor, amarrar a su sucursal
    if (!in_array($ROL, ['Admin', 'Administrador', 'Logistica', 'Auditor'], true) && $id_sucursal !== $ID_SUCURSAL) {
        $errores[] = 'No puedes crear auditorías para otra sucursal.';
    }

    if (!$errores) {
        $propiedadAud = normalizarPropiedad($sucursal['propiedad'] ?? $PROPIEDAD_SES);
        $idSubdisAud  = isset($sucursal['id_subdis']) && $sucursal['id_subdis'] !== '' ? (int)$sucursal['id_subdis'] : null;

        try {
            $conn->begin_transaction();

            /* ============================================
               1) CREAR AUDITORÍA
            ============================================ */
            $folio = generarFolioAuditoria($conn, $propiedadAud);

            $stmtInsAud = $conn->prepare("
                INSERT INTO auditorias (
                    folio,
                    id_sucursal,
                    id_auditor,
                    id_gerente,
                    propiedad,
                    id_subdis,
                    fecha_inicio,
                    estatus,
                    observaciones_inicio,
                    creada_por
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, NOW(), 'En proceso', ?, ?
                )
            ");

            $stmtInsAud->bind_param(
                "siiisssi",
                $folio,
                $id_sucursal,
                $ID_USUARIO,
                $id_gerente,
                $propiedadAud,
                $idSubdisAud,
                $observaciones_inicio,
                $ID_USUARIO
            );
            $stmtInsAud->execute();

            $id_auditoria = (int)$conn->insert_id;

            /* ============================================
               2) SNAPSHOT UNITARIO
               Regla: producto con imei1
            ============================================ */
            $sqlUnit = "
                INSERT INTO auditorias_snapshot (
                    id_auditoria,
                    id_inventario,
                    id_producto,
                    id_sucursal,
                    propiedad,
                    id_subdis,
                    codigo_producto,
                    marca,
                    modelo,
                    color,
                    ram,
                    capacidad,
                    tipo_producto,
                    imei1,
                    imei2,
                    estatus_inventario,
                    fecha_ingreso_inventario
                )
                SELECT
                    ? AS id_auditoria,
                    i.id,
                    p.id,
                    i.id_sucursal,
                    i.propiedad,
                    i.id_subdis,
                    p.codigo_producto,
                    p.marca,
                    p.modelo,
                    p.color,
                    p.ram,
                    p.capacidad,
                    p.tipo_producto,
                    p.imei1,
                    p.imei2,
                    i.estatus,
                    i.fecha_ingreso
                FROM inventario i
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE i.id_sucursal = ?
                  AND i.estatus = 'Disponible'
                  AND p.imei1 IS NOT NULL
                  AND TRIM(p.imei1) <> ''
            ";
            $stmtUnit = $conn->prepare($sqlUnit);
            $stmtUnit->bind_param("ii", $id_auditoria, $id_sucursal);
            $stmtUnit->execute();
            $totalUnitarios = $stmtUnit->affected_rows;

            /* ============================================
               3) SNAPSHOT POR CANTIDAD
               Regla: producto sin imei1
            ============================================ */
            $sqlCant = "
                INSERT INTO auditorias_snapshot_cantidades (
                    id_auditoria,
                    id_producto,
                    id_sucursal,
                    propiedad,
                    id_subdis,
                    codigo_producto,
                    marca,
                    modelo,
                    color,
                    ram,
                    capacidad,
                    tipo_producto,
                    cantidad_sistema
                )
                SELECT
                    ? AS id_auditoria,
                    p.id AS id_producto,
                    i.id_sucursal,
                    i.propiedad,
                    i.id_subdis,
                    p.codigo_producto,
                    p.marca,
                    p.modelo,
                    p.color,
                    p.ram,
                    p.capacidad,
                    p.tipo_producto,
                    SUM(i.cantidad) AS cantidad_sistema
                FROM inventario i
                INNER JOIN productos p ON p.id = i.id_producto
                WHERE i.id_sucursal = ?
                  AND i.estatus = 'Disponible'
                  AND (p.imei1 IS NULL OR TRIM(p.imei1) = '')
                GROUP BY
                    p.id, i.id_sucursal, i.propiedad, i.id_subdis,
                    p.codigo_producto, p.marca, p.modelo, p.color, p.ram, p.capacidad, p.tipo_producto
            ";
            $stmtCant = $conn->prepare($sqlCant);
            $stmtCant->bind_param("ii", $id_auditoria, $id_sucursal);
            $stmtCant->execute();
            $totalLineasCantidades = $stmtCant->affected_rows;

            /* ============================================
               4) ACTUALIZAR TOTALES EN CABECERA
            ============================================ */
            $stmtUpd = $conn->prepare("
                UPDATE auditorias
                SET total_snapshot = ?,
                    total_lineas_accesorios = ?
                WHERE id = ?
            ");
            $stmtUpd->bind_param("iii", $totalUnitarios, $totalLineasCantidades, $id_auditoria);
            $stmtUpd->execute();

            /* ============================================
               5) BITÁCORA
            ============================================ */
            $accion = 'Crear auditoria';
            $detalle = 'Se creó la auditoría y se generaron snapshots iniciales.';
            $json = json_encode([
                'folio' => $folio,
                'id_sucursal' => $id_sucursal,
                'total_unitarios' => $totalUnitarios,
                'total_lineas_cantidades' => $totalLineasCantidades
            ], JSON_UNESCAPED_UNICODE);

            $stmtBit = $conn->prepare("
                INSERT INTO auditorias_bitacora (
                    id_auditoria, accion, detalle, datos_extra, realizado_por, fecha_evento
                ) VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmtBit->bind_param("isssi", $id_auditoria, $accion, $detalle, $json, $ID_USUARIO);
            $stmtBit->execute();

            $conn->commit();

            if ($redir_debug) {
                $debugInfo = [
                    'id_auditoria' => $id_auditoria,
                    'folio' => $folio,
                    'total_unitarios' => $totalUnitarios,
                    'total_lineas_cantidades' => $totalLineasCantidades
                ];
                $ok = 'Auditoría creada correctamente.';
            } else {
                // Vista futura de captura
                header("Location: auditorias_captura.php?id=" . $id_auditoria);
                exit();
            }

        } catch (Throwable $e) {
            $conn->rollback();
            $errores[] = 'Error al crear la auditoría: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Auditoría</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body{
            background:#f6f8fb;
            font-family: Arial, Helvetica, sans-serif;
            color:#1f2937;
        }
        .wrap{
            max-width: 980px;
            margin: 30px auto;
            padding: 0 15px 40px;
        }
        .card{
            background:#fff;
            border-radius:18px;
            box-shadow:0 10px 25px rgba(0,0,0,.06);
            padding:24px;
        }
        .title{
            margin:0 0 6px;
            font-size:28px;
            font-weight:700;
        }
        .muted{
            color:#6b7280;
            margin-bottom:22px;
        }
        .grid{
            display:grid;
            grid-template-columns: repeat(2, 1fr);
            gap:18px;
        }
        .field{
            display:flex;
            flex-direction:column;
            gap:7px;
        }
        .field.full{
            grid-column:1 / -1;
        }
        label{
            font-weight:600;
            font-size:14px;
        }
        input, select, textarea{
            border:1px solid #d1d5db;
            border-radius:12px;
            padding:12px 14px;
            font-size:14px;
            outline:none;
            transition:.18s ease;
            background:#fff;
        }
        input:focus, select:focus, textarea:focus{
            border-color:#2563eb;
            box-shadow:0 0 0 3px rgba(37,99,235,.12);
        }
        textarea{
            min-height:110px;
            resize:vertical;
        }
        .actions{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            margin-top:22px;
        }
        .btn{
            border:none;
            border-radius:12px;
            padding:12px 18px;
            cursor:pointer;
            font-weight:700;
            font-size:14px;
        }
        .btn-primary{
            background:#111827;
            color:#fff;
        }
        .btn-secondary{
            background:#e5e7eb;
            color:#111827;
        }
        .alert{
            border-radius:12px;
            padding:14px 16px;
            margin-bottom:18px;
        }
        .alert-danger{
            background:#fef2f2;
            color:#991b1b;
            border:1px solid #fecaca;
        }
        .alert-success{
            background:#ecfdf5;
            color:#065f46;
            border:1px solid #a7f3d0;
        }
        .mini{
            margin-top:18px;
            background:#f9fafb;
            border:1px dashed #d1d5db;
            border-radius:14px;
            padding:16px;
            font-size:13px;
        }
        .pill{
            display:inline-block;
            padding:5px 10px;
            border-radius:999px;
            background:#eef2ff;
            color:#3730a3;
            font-size:12px;
            font-weight:700;
            margin-right:8px;
            margin-bottom:8px;
        }
        @media (max-width: 768px){
            .grid{ grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1 class="title">Nueva auditoría de inventario</h1>
        <div class="muted">
            Esta pantalla crea la cabecera de auditoría y genera automáticamente el snapshot de:
            <strong>productos unitarios</strong> y <strong>productos por cantidad</strong>.
        </div>

        <?php if ($errores): ?>
            <div class="alert alert-danger">
                <strong>No se pudo guardar:</strong>
                <ul style="margin:10px 0 0 18px;">
                    <?php foreach ($errores as $err): ?>
                        <li><?= h($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($ok): ?>
            <div class="alert alert-success">
                <strong><?= h($ok) ?></strong><br>
                <?php if (!empty($debugInfo)): ?>
                    Folio: <strong><?= h($debugInfo['folio']) ?></strong><br>
                    ID Auditoría: <strong><?= (int)$debugInfo['id_auditoria'] ?></strong><br>
                    Unitarios: <strong><?= (int)$debugInfo['total_unitarios'] ?></strong><br>
                    Líneas por cantidad: <strong><?= (int)$debugInfo['total_lineas_cantidades'] ?></strong>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="mini">
            <span class="pill">Unitarios = con imei1</span>
            <span class="pill">Cantidades = sin imei1</span>
            <span class="pill">Snapshot automático</span>
        </div>

        <form method="POST" action="">
            <div class="grid" style="margin-top:22px;">
                <div class="field">
                    <label for="id_sucursal">Sucursal *</label>
                    <select name="id_sucursal" id="id_sucursal" required>
                        <option value="">Selecciona...</option>
                        <?php foreach ($sucursales as $s): ?>
                            <option value="<?= (int)$s['id'] ?>"
                                <?= ((int)($_POST['id_sucursal'] ?? 0) === (int)$s['id']) ? 'selected' : '' ?>>
                                <?= h($s['nombre']) ?>
                                <?php if (!empty($s['propiedad'])): ?>
                                    | <?= h($s['propiedad']) ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Auditor responsable</label>
                    <input type="text" value="<?= h($NOMBRE_USR . ' (' . $ROL . ')') ?>" disabled>
                </div>

                <div class="field">
                    <label for="id_gerente">Gerente / encargado presente</label>
                    <select name="id_gerente" id="id_gerente">
                        <option value="">Selecciona...</option>
                        <?php foreach ($gerentes as $g): ?>
                            <option value="<?= (int)$g['id'] ?>"
                                <?= ((int)($_POST['id_gerente'] ?? 0) === (int)$g['id']) ? 'selected' : '' ?>>
                                <?= h($g['nombre']) ?> | <?= h($g['rol']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Fecha / hora de inicio</label>
                    <input type="text" value="<?= h(date('d/m/Y H:i')) ?>" disabled>
                </div>

                <div class="field full">
                    <label for="observaciones_inicio">Observaciones iniciales</label>
                    <textarea name="observaciones_inicio" id="observaciones_inicio" placeholder="Ej. Auditoría mensual de almacén, revisión física completa del inventario..."><?= h($_POST['observaciones_inicio'] ?? '') ?></textarea>
                </div>

                <div class="field full">
                    <label>
                        <input type="checkbox" name="debug" value="1" <?= isset($_POST['debug']) ? 'checked' : '' ?>>
                        Mostrar resultado aquí en vez de redirigir
                    </label>
                </div>
            </div>

            <div class="actions">
                <button type="submit" class="btn btn-primary">Iniciar auditoría</button>
                <a href="dashboard.php" class="btn btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;">Cancelar</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>