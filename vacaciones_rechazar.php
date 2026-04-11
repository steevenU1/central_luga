<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit;
}

$miRol = trim((string)($_SESSION['rol'] ?? ''));
$miId  = (int)($_SESSION['id_usuario'] ?? 0);

if (!in_array($miRol, ['Admin', 'Gerente'], true)) {
    http_response_code(403);
    exit('Sin permiso para rechazar solicitudes de vacaciones.');
}

require_once __DIR__ . '/db.php';
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

/* =========================================================
   Helpers
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect_panel(string $ok = '', string $err = ''): void {
    $params = [];
    if ($ok !== '')  $params['ok'] = $ok;
    if ($err !== '') $params['err'] = $err;

    $url = 'vacaciones_panel.php';
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    header("Location: {$url}");
    exit;
}

function empty_date($v): bool {
    return empty($v) || $v === '0000-00-00';
}

function fmt_date($v): string {
    if (empty_date($v)) return '—';
    $ts = strtotime((string)$v);
    return $ts ? date('d/m/Y', $ts) : '—';
}

function dias_rango(?string $inicio, ?string $fin): int {
    if (empty_date($inicio) || empty_date($fin)) return 0;
    try {
        $a = new DateTime($inicio);
        $b = new DateTime($fin);
        return (int)$a->diff($b)->days + 1;
    } catch (Throwable $e) {
        return 0;
    }
}

function table_exists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $rs = $conn->query("SHOW TABLES LIKE '{$t}'");
    return $rs && $rs->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $rs = $conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'");
    return $rs && $rs->num_rows > 0;
}

function stmt_all_assoc(mysqli_stmt $stmt): array {
    $rows = [];
    $meta = $stmt->result_metadata();
    if (!$meta) return $rows;

    $fields = $meta->fetch_fields();
    $row = [];
    $bind = [];
    foreach ($fields as $f) {
        $row[$f->name] = null;
        $bind[] = &$row[$f->name];
    }

    call_user_func_array([$stmt, 'bind_result'], $bind);

    while ($stmt->fetch()) {
        $rows[] = array_combine(
            array_keys($row),
            array_map(fn($v) => $v, array_values($row))
        );
    }

    return $rows;
}

function stmt_one_assoc(mysqli_stmt $stmt): ?array {
    $rows = stmt_all_assoc($stmt);
    return $rows[0] ?? null;
}

/* =========================================================
   Validaciones base
========================================================= */
if (!table_exists($conn, 'vacaciones_solicitudes')) {
    redirect_panel('', 'No existe la tabla vacaciones_solicitudes.');
}

$solId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($solId <= 0) {
    redirect_panel('', 'Solicitud inválida.');
}

/* =========================================================
   Compatibilidad columnas
========================================================= */
$statusAdminCol = column_exists($conn, 'vacaciones_solicitudes', 'status_admin') ? 'status_admin' : 'status';
$statusJefeCol  = column_exists($conn, 'vacaciones_solicitudes', 'status_jefe') ? 'status_jefe' : null;

$aprobadoPorCol = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_por')
    ? 'aprobado_admin_por'
    : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_por')
        ? 'resuelto_por'
        : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_por') ? 'aprobado_por' : null));

$aprobadoEnCol = column_exists($conn, 'vacaciones_solicitudes', 'aprobado_admin_en')
    ? 'aprobado_admin_en'
    : (column_exists($conn, 'vacaciones_solicitudes', 'resuelto_en')
        ? 'resuelto_en'
        : (column_exists($conn, 'vacaciones_solicitudes', 'aprobado_en') ? 'aprobado_en' : null));

$comentResCol = column_exists($conn, 'vacaciones_solicitudes', 'comentario_resolucion')
    ? 'comentario_resolucion'
    : (column_exists($conn, 'vacaciones_solicitudes', 'comentario_admin')
        ? 'comentario_admin'
        : null);

$comentarioCol = column_exists($conn, 'vacaciones_solicitudes', 'comentario')
    ? 'comentario'
    : (column_exists($conn, 'vacaciones_solicitudes', 'motivo')
        ? 'motivo'
        : null);

$idSucursalCol = column_exists($conn, 'vacaciones_solicitudes', 'id_sucursal') ? 'id_sucursal' : null;

/* =========================================================
   Cargar solicitud
========================================================= */
$sqlGet = "
    SELECT
        vs.*,
        u.nombre AS usuario_nombre,
        u.usuario,
        u.correo,
        u.rol,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona
    FROM vacaciones_solicitudes vs
    INNER JOIN usuarios u ON u.id = vs.id_usuario
    LEFT JOIN sucursales s ON s.id = ".($idSucursalCol ? "vs.`{$idSucursalCol}`" : "u.id_sucursal")."
    WHERE vs.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sqlGet);
$stmt->bind_param('i', $solId);
$stmt->execute();
$sol = stmt_one_assoc($stmt);
$stmt->close();

if (!$sol) {
    redirect_panel('', 'La solicitud no existe.');
}

if (($sol[$statusAdminCol] ?? '') !== 'Pendiente') {
    redirect_panel('', 'La solicitud ya no está pendiente.');
}

/* =========================================================
   Guardar rechazo
========================================================= */
$flashError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $comentario = trim((string)($_POST['comentario'] ?? ''));

    $sets = [];
    $params = [];
    $types = '';

    $sets[] = "`{$statusAdminCol}` = ?";
    $types .= 's';
    $params[] = 'Rechazado';

    if ($aprobadoPorCol) {
        $sets[] = "`{$aprobadoPorCol}` = ?";
        $types .= 'i';
        $params[] = $miId;
    }

    if ($aprobadoEnCol) {
        $sets[] = "`{$aprobadoEnCol}` = NOW()";
    }

    if ($comentResCol) {
        $sets[] = "`{$comentResCol}` = ?";
        $types .= 's';
        $params[] = $comentario;
    }

    $types .= 'i';
    $params[] = $solId;

    $sqlUpd = "
        UPDATE vacaciones_solicitudes
        SET " . implode(', ', $sets) . "
        WHERE id = ?
          AND `{$statusAdminCol}` = 'Pendiente'
        LIMIT 1
    ";

    try {
        $stmtUpd = $conn->prepare($sqlUpd);
        $stmtUpd->bind_param($types, ...$params);
        $stmtUpd->execute();

        if ($stmtUpd->affected_rows <= 0) {
            $stmtUpd->close();
            redirect_panel('', 'La solicitud ya no pudo actualizarse como pendiente.');
        }

        $stmtUpd->close();
        redirect_panel('Solicitud rechazada correctamente.', '');
    } catch (Throwable $e) {
        $flashError = 'No se pudo rechazar la solicitud: ' . $e->getMessage();
    }
}

$diasCalc = !empty($sol['dias']) ? (int)$sol['dias'] : dias_rango($sol['fecha_inicio'] ?? null, $sol['fecha_fin'] ?? null);
$comentarioSolicitud = $comentarioCol ? trim((string)($sol[$comentarioCol] ?? '')) : '';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Rechazar solicitud de vacaciones</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root{
        --bg:#f5f7fb;
        --card:#ffffff;
        --line:#e6ebf2;
        --text:#18212b;
        --muted:#6b7280;
        --primary:#2563eb;
        --primary-soft:#dbeafe;
        --success:#15803d;
        --success-soft:#dcfce7;
        --warning:#b45309;
        --warning-soft:#fef3c7;
        --danger:#b91c1c;
        --danger-soft:#fee2e2;
        --radius:20px;
        --shadow:0 10px 28px rgba(15,23,42,.06);
    }

    *{box-sizing:border-box}

    body{
        margin:0;
        font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
        background:linear-gradient(180deg,#f8fbff 0%, #f3f6fb 100%);
        color:var(--text);
    }

    .wrap{
        max-width:980px;
        margin:18px auto 40px;
        padding:0 14px;
    }

    .hero{
        background:linear-gradient(135deg,#fff7ed 0%, #ffedd5 55%, #fde68a 100%);
        color:#111827;
        border:1px solid #fed7aa;
        border-radius:24px;
        padding:24px;
        box-shadow:var(--shadow);
        margin-bottom:18px;
    }

    .hero h1{
        margin:0 0 8px;
        font-size:30px;
        line-height:1.05;
    }

    .hero .meta{
        color:#7c2d12;
        font-size:14px;
        margin-bottom:10px;
    }

    .pill{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        font-size:12px;
        font-weight:700;
        border:1px solid transparent;
        margin:2px 6px 2px 0;
        white-space:nowrap;
    }

    .pill.light{
        background:#ffffff;
        color:#1f2937;
        border-color:#fdba74;
    }

    .pill.warning{
        color:#9a3412;
        background:#ffedd5;
        border-color:#fdba74;
    }

    .card{
        background:var(--card);
        border:1px solid var(--line);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        overflow:hidden;
        margin-bottom:18px;
    }

    .section{
        padding:18px;
    }

    .section h2{
        margin:0 0 14px;
        font-size:18px;
    }

    .info-grid{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:12px;
    }

    .info-item{
        border:1px solid #edf2f7;
        border-radius:16px;
        padding:12px 14px;
        background:#fcfdff;
    }

    .info-item .label{
        font-size:12px;
        color:var(--muted);
        margin-bottom:4px;
    }

    .info-item .value{
        font-size:15px;
        font-weight:700;
        color:#111827;
        word-break:break-word;
    }

    .field{
        margin-bottom:14px;
    }

    .field label{
        display:block;
        font-size:12px;
        font-weight:700;
        color:var(--muted);
        margin-bottom:6px;
    }

    .field textarea{
        width:100%;
        border:1px solid #d8e2ec;
        background:#fff;
        border-radius:14px;
        padding:11px 12px;
        font-size:14px;
        outline:none;
        min-height:120px;
        resize:vertical;
    }

    .field textarea:focus{
        border-color:#fdba74;
        box-shadow:0 0 0 4px rgba(251,146,60,.12);
    }

    .btns{
        display:flex;
        gap:10px;
        flex-wrap:wrap;
    }

    .btn{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        min-height:42px;
        padding:0 16px;
        border-radius:14px;
        border:1px solid transparent;
        cursor:pointer;
        text-decoration:none;
        font-weight:700;
        font-size:14px;
        transition:.18s ease;
        background:#fff;
        color:#111827;
    }

    .btn:hover{
        background:#f8fafc;
    }

    .btn-danger{
        background:#b91c1c;
        border-color:#b91c1c;
        color:#fff;
    }

    .btn-danger:hover{
        filter:brightness(.98);
        transform:translateY(-1px);
    }

    .btn-light{
        background:#fff;
        border-color:#d8e1ec;
        color:#111827;
    }

    .alert{
        border-radius:16px;
        padding:14px 16px;
        margin-bottom:14px;
        border:1px solid transparent;
        font-size:14px;
    }

    .alert.error{
        background:#fef2f2;
        border-color:#fecaca;
        color:#991b1b;
    }

    .muted{
        color:var(--muted);
        font-size:13px;
    }

    @media (max-width:760px){
        .info-grid{
            grid-template-columns:1fr;
        }
        .hero h1{
            font-size:24px;
        }
    }
</style>
</head>
<body>
<div class="wrap">

    <section class="hero">
        <h1>Rechazar solicitud de vacaciones</h1>
        <div class="meta">
            Estás a punto de rechazar una solicitud pendiente. Puedes registrar un comentario para dejar evidencia del motivo.
        </div>
        <div>
            <span class="pill light"><?= h($sol['usuario_nombre'] ?? '—') ?></span>
            <span class="pill light"><?= h($sol['sucursal_nombre'] ?? 'Sin sucursal') ?></span>
            <span class="pill light"><?= h($sol['rol'] ?? 'Sin rol') ?></span>
            <span class="pill warning">Pendiente</span>
        </div>
    </section>

    <?php if ($flashError !== ''): ?>
        <div class="alert error"><?= h($flashError) ?></div>
    <?php endif; ?>

    <section class="card section">
        <h2>Detalle de la solicitud</h2>

        <div class="info-grid">
            <div class="info-item">
                <div class="label">Colaborador</div>
                <div class="value"><?= h($sol['usuario_nombre'] ?? '—') ?></div>
            </div>

            <div class="info-item">
                <div class="label">Usuario / correo</div>
                <div class="value">
                    @<?= h($sol['usuario'] ?? '—') ?><br>
                    <span class="muted"><?= h($sol['correo'] ?: 'Sin correo') ?></span>
                </div>
            </div>

            <div class="info-item">
                <div class="label">Sucursal</div>
                <div class="value">
                    <?= h($sol['sucursal_nombre'] ?? '—') ?><br>
                    <span class="muted"><?= h($sol['sucursal_zona'] ?? 'Sin zona') ?></span>
                </div>
            </div>

            <div class="info-item">
                <div class="label">Rango solicitado</div>
                <div class="value">
                    <?= h(fmt_date($sol['fecha_inicio'] ?? null)) ?> al <?= h(fmt_date($sol['fecha_fin'] ?? null)) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="label">Días solicitados</div>
                <div class="value"><?= (int)$diasCalc ?> día(s)</div>
            </div>

            <div class="info-item">
                <div class="label">Comentario de la solicitud</div>
                <div class="value"><?= h($comentarioSolicitud !== '' ? $comentarioSolicitud : '—') ?></div>
            </div>
        </div>
    </section>

    <section class="card section">
        <h2>Motivo del rechazo</h2>

        <form method="post">
            <input type="hidden" name="id" value="<?= (int)$solId ?>">

            <div class="field">
                <label>Comentario / observación</label>
                <textarea name="comentario" placeholder="Ej. No hay cobertura operativa en esas fechas, ya existe otra ausencia programada, falta coordinación con sucursal, etc."></textarea>
            </div>

            <div class="btns">
                <button type="submit" class="btn btn-danger">Confirmar rechazo</button>
                <a href="vacaciones_panel.php" class="btn btn-light">Cancelar</a>
            </div>
        </form>
    </section>

</div>
</body>
</html>