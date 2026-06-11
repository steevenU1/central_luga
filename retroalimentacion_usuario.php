<?php
// retroalimentacion_usuario.php — Historial de retroalimentaciones por usuario

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function fmt_date($value): string {
    if (empty($value) || $value === '0000-00-00') return '—';
    $ts = strtotime((string)$value);
    return $ts ? date('d/m/Y H:i', $ts) : '—';
}

function badgeEstatus(string $estatus): string {
    $estatus = strtoupper($estatus);

    if ($estatus === 'FIRMADA') {
        return '<span class="badge success">Firmada</span>';
    }

    if ($estatus === 'CANCELADA') {
        return '<span class="badge danger">Cancelada</span>';
    }

    return '<span class="badge warning">Pendiente</span>';
}

$idUsuarioSesion = (int)($_SESSION['id_usuario'] ?? 0);
$rolSesion       = trim((string)($_SESSION['rol'] ?? ''));
$idSucursalSesion= (int)($_SESSION['id_sucursal'] ?? 0);

$usuarioId = (int)($_GET['usuario_id'] ?? 0);

if ($usuarioId <= 0) {
    die("Usuario inválido.");
}

/* Usuario sesión */
$stmt = $conn->prepare("
    SELECT u.id, u.nombre, u.rol, u.id_sucursal, s.zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idUsuarioSesion);
$stmt->execute();
$usuarioSesion = $stmt->get_result()->fetch_assoc();
$stmt->close();

$miZona = $usuarioSesion['zona'] ?? null;

/* Usuario objetivo */
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.nombre,
        u.usuario,
        u.correo,
        u.rol,
        u.activo,
        u.id_sucursal,
        s.nombre AS sucursal_nombre,
        s.zona AS sucursal_zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$usuario) {
    die("No se encontró el usuario.");
}

/* Permisos */
$puedeVer = false;

if ($rolSesion === 'Admin') {
    $puedeVer = true;
} elseif ($idUsuarioSesion === $usuarioId) {
    $puedeVer = true;
} elseif ($rolSesion === 'GerenteZona' && ($usuario['sucursal_zona'] ?? null) === $miZona) {
    $puedeVer = true;
} elseif ($rolSesion === 'Gerente' && (int)$usuario['id_sucursal'] === $idSucursalSesion) {
    $puedeVer = true;
}

if (!$puedeVer) {
    http_response_code(403);
    die("No tienes permiso para ver estas retroalimentaciones.");
}

$estatusFiltro = trim((string)($_GET['estatus'] ?? ''));
$buscar = trim((string)($_GET['buscar'] ?? ''));

$where = ["r.id_colaborador = ?"];
$params = [$usuarioId];
$types = "i";

if ($estatusFiltro !== '') {
    $where[] = "r.estatus = ?";
    $params[] = $estatusFiltro;
    $types .= "s";
}

if ($buscar !== '') {
    $where[] = "(
        r.folio LIKE ?
        OR r.periodo LIKE ?
        OR ul.nombre LIKE ?
        OR r.que_hace_bien LIKE ?
        OR r.que_puede_mejorar LIKE ?
        OR r.acuerdos LIKE ?
    )";
    $like = '%' . $buscar . '%';
    for ($i = 0; $i < 6; $i++) {
        $params[] = $like;
        $types .= "s";
    }
}

$whereSql = "WHERE " . implode(" AND ", $where);

/* Resumen */
$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN estatus = 'PENDIENTE' THEN 1 ELSE 0 END) AS pendientes,
        SUM(CASE WHEN estatus = 'FIRMADA' THEN 1 ELSE 0 END) AS firmadas,
        SUM(CASE WHEN estatus = 'CANCELADA' THEN 1 ELSE 0 END) AS canceladas
    FROM retroalimentaciones
    WHERE id_colaborador = ?
");
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$resumen = $stmt->get_result()->fetch_assoc();
$stmt->close();

$total = (int)($resumen['total'] ?? 0);
$pendientes = (int)($resumen['pendientes'] ?? 0);
$firmadas = (int)($resumen['firmadas'] ?? 0);
$canceladas = (int)($resumen['canceladas'] ?? 0);

/* Listado */
$sql = "
    SELECT
        r.*,
        ul.nombre AS lider_nombre,
        s.nombre AS sucursal_nombre
    FROM retroalimentaciones r
    LEFT JOIN usuarios ul ON ul.id = r.id_lider
    LEFT JOIN sucursales s ON s.id = r.id_sucursal
    $whereSql
    ORDER BY r.fecha_creacion DESC, r.id DESC
    LIMIT 500
";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$retros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Retroalimentaciones de <?= h($usuario['nombre']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
    :root{
        --bg:#f5f7fb;
        --card:#ffffff;
        --line:#e6ebf2;
        --text:#18212b;
        --muted:#6b7280;
        --primary:#2563eb;
        --success:#15803d;
        --warning:#b45309;
        --danger:#b91c1c;
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
        max-width:1240px;
        margin:18px auto 40px;
        padding:0 14px;
    }

    .hero{
        background:linear-gradient(135deg,#071426 0%, #123c69 100%);
        color:#fff;
        border-radius:24px;
        padding:26px;
        box-shadow:0 18px 45px rgba(5,20,40,.22);
        margin-bottom:18px;
    }

    .hero-grid{
        display:flex;
        justify-content:space-between;
        gap:18px;
        flex-wrap:wrap;
        align-items:center;
    }

    .hero h1{
        margin:0 0 8px;
        font-size:30px;
        line-height:1.05;
    }

    .hero p{
        margin:0;
        color:rgba(255,255,255,.75);
    }

    .pill{
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        font-size:12px;
        font-weight:800;
        border:1px solid transparent;
        margin:2px 6px 2px 0;
        white-space:nowrap;
    }

    .pill.light{
        background:#fff;
        color:#1f2937;
        border-color:#d1d5db;
    }

    .toolbar{
        display:flex;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:18px;
    }

    .btn{
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        min-height:40px;
        padding:0 15px;
        border-radius:14px;
        border:1px solid transparent;
        cursor:pointer;
        text-decoration:none;
        font-weight:800;
        font-size:14px;
        transition:.18s ease;
    }

    .btn-primary{
        background:var(--primary);
        color:#fff;
    }

    .btn-light{
        background:#fff;
        border-color:#d8e1ec;
        color:#111827;
    }

    .btn-outline{
        background:#fff;
        border-color:#bfdbfe;
        color:#1d4ed8;
    }

    .btn:hover{
        transform:translateY(-1px);
    }

    .kpi-grid{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:14px;
        margin-bottom:18px;
    }

    .kpi{
        background:#fff;
        border:1px solid var(--line);
        border-radius:20px;
        padding:18px;
        box-shadow:var(--shadow);
    }

    .kpi .label{
        color:var(--muted);
        font-size:13px;
        font-weight:800;
        margin-bottom:6px;
    }

    .kpi .value{
        font-size:30px;
        font-weight:950;
        line-height:1;
    }

    .card{
        background:var(--card);
        border:1px solid var(--line);
        border-radius:var(--radius);
        box-shadow:var(--shadow);
        overflow:hidden;
    }

    .card-header{
        padding:16px 18px;
        border-bottom:1px solid #edf2f7;
        background:#fbfdff;
    }

    .card-header h2{
        margin:0;
        font-size:18px;
    }

    .card-body{
        padding:18px;
    }

    .filters{
        display:grid;
        grid-template-columns:1fr 220px auto auto;
        gap:12px;
        align-items:end;
        margin-bottom:18px;
    }

    label{
        display:block;
        font-size:12px;
        font-weight:800;
        color:var(--muted);
        margin-bottom:6px;
    }

    input,
    select{
        width:100%;
        border:1px solid #d8e2ec;
        background:#fff;
        border-radius:14px;
        padding:11px 12px;
        font-size:14px;
        outline:none;
    }

    .retro-list{
        display:grid;
        gap:14px;
    }

    .retro-item{
        border:1px solid #e8eef5;
        background:#fcfdff;
        border-radius:18px;
        padding:16px;
    }

    .retro-top{
        display:flex;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
        margin-bottom:12px;
    }

    .folio{
        font-weight:950;
        color:#0f456f;
        font-size:16px;
    }

    .muted{
        color:var(--muted);
        font-size:13px;
    }

    .badge{
        display:inline-flex;
        padding:6px 10px;
        border-radius:999px;
        font-size:12px;
        font-weight:900;
        border:1px solid transparent;
    }

    .badge.success{
        color:var(--success);
        background:#dcfce7;
        border-color:#bbf7d0;
    }

    .badge.warning{
        color:var(--warning);
        background:#fef3c7;
        border-color:#fde68a;
    }

    .badge.danger{
        color:var(--danger);
        background:#fee2e2;
        border-color:#fecaca;
    }

    .retro-preview{
        display:grid;
        gap:10px;
        margin-top:8px;
    }

    .preview-block{
        background:#fff;
        border:1px solid #edf2f7;
        border-radius:14px;
        padding:12px;
    }

    .preview-title{
        font-size:12px;
        color:#6b7280;
        font-weight:900;
        margin-bottom:4px;
        text-transform:uppercase;
    }

    .preview-text{
        color:#1f2937;
        font-size:14px;
        line-height:1.5;
    }

    .actions{
        display:flex;
        gap:8px;
        flex-wrap:wrap;
        margin-top:14px;
    }

    .empty{
        text-align:center;
        color:var(--muted);
        padding:28px;
        border:1px dashed #d7e0ea;
        border-radius:16px;
        background:#fcfdff;
    }

    @media(max-width:900px){
        .kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr));}
        .filters{grid-template-columns:1fr;}
    }

    @media(max-width:560px){
        .kpi-grid{grid-template-columns:1fr;}
        .hero h1{font-size:24px;}
    }
</style>
</head>
<body>

<?php
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}
?>

<div class="wrap">

    <section class="hero">
        <div class="hero-grid">
            <div>
                <h1>Retroalimentaciones</h1>
                <p>Historial completo de retroalimentaciones de <?= h($usuario['nombre']) ?>.</p>
            </div>
            <div>
                <span class="pill light"><?= h($usuario['rol']) ?></span>
                <span class="pill light"><?= h($usuario['sucursal_nombre'] ?: 'Sin sucursal') ?></span>
                <span class="pill light"><?= h($usuario['sucursal_zona'] ?: 'Sin zona') ?></span>
            </div>
        </div>
    </section>

    <div class="toolbar">
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="expediente_usuario.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">
                ← Volver al expediente
            </a>
            <a href="retroalimentacion_listado.php" class="btn btn-light">
                Listado general
            </a>
        </div>
    </div>

    <div class="kpi-grid">
        <div class="kpi">
            <div class="label">Total</div>
            <div class="value"><?= number_format($total) ?></div>
        </div>

        <div class="kpi">
            <div class="label">Pendientes</div>
            <div class="value"><?= number_format($pendientes) ?></div>
        </div>

        <div class="kpi">
            <div class="label">Firmadas</div>
            <div class="value"><?= number_format($firmadas) ?></div>
        </div>

        <div class="kpi">
            <div class="label">Canceladas</div>
            <div class="value"><?= number_format($canceladas) ?></div>
        </div>
    </div>

    <section class="card">
        <div class="card-header">
            <h2>Historial</h2>
        </div>

        <div class="card-body">
            <form method="GET" class="filters">
                <input type="hidden" name="usuario_id" value="<?= (int)$usuarioId ?>">

                <div>
                    <label>Buscar</label>
                    <input type="text" name="buscar" value="<?= h($buscar) ?>" placeholder="Folio, líder, periodo o contenido">
                </div>

                <div>
                    <label>Estatus</label>
                    <select name="estatus">
                        <option value="">Todos</option>
                        <option value="PENDIENTE" <?= $estatusFiltro === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                        <option value="FIRMADA" <?= $estatusFiltro === 'FIRMADA' ? 'selected' : '' ?>>Firmada</option>
                        <option value="CANCELADA" <?= $estatusFiltro === 'CANCELADA' ? 'selected' : '' ?>>Cancelada</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Filtrar</button>

                <a href="retroalimentacion_usuario.php?usuario_id=<?= (int)$usuarioId ?>" class="btn btn-light">
                    Limpiar
                </a>
            </form>

            <?php if (empty($retros)): ?>
                <div class="empty">
                    No hay retroalimentaciones registradas con los filtros actuales.
                </div>
            <?php else: ?>
                <div class="retro-list">
                    <?php foreach ($retros as $r): ?>
                        <?php
                        $folio = $r['folio'] ?: 'RETRO-' . str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT);
                        $estatus = strtoupper((string)$r['estatus']);

                        $bien = mb_substr(trim((string)$r['que_hace_bien']), 0, 180);
                        $mejora = mb_substr(trim((string)$r['que_puede_mejorar']), 0, 180);
                        $acuerdos = mb_substr(trim((string)$r['acuerdos']), 0, 180);
                        ?>
                        <article class="retro-item">
                            <div class="retro-top">
                                <div>
                                    <div class="folio"><?= h($folio) ?></div>
                                    <div class="muted">
                                        Creada: <?= h(fmt_date($r['fecha_creacion'])) ?>
                                        · Líder: <?= h($r['lider_nombre'] ?: '—') ?>
                                        <?php if (!empty($r['periodo'])): ?>
                                            · Periodo: <?= h($r['periodo']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div>
                                    <?= badgeEstatus($estatus) ?>
                                </div>
                            </div>

                            <div class="retro-preview">
                                <div class="preview-block">
                                    <div class="preview-title">Qué estoy haciendo bien</div>
                                    <div class="preview-text"><?= h($bien) ?><?= mb_strlen((string)$r['que_hace_bien']) > 180 ? '...' : '' ?></div>
                                </div>

                                <div class="preview-block">
                                    <div class="preview-title">En qué puedo mejorar</div>
                                    <div class="preview-text"><?= h($mejora) ?><?= mb_strlen((string)$r['que_puede_mejorar']) > 180 ? '...' : '' ?></div>
                                </div>

                                <div class="preview-block">
                                    <div class="preview-title">Acuerdos</div>
                                    <div class="preview-text"><?= h($acuerdos) ?><?= mb_strlen((string)$r['acuerdos']) > 180 ? '...' : '' ?></div>
                                </div>
                            </div>

                            <div class="actions">
                                <a href="retroalimentacion_detalle.php?id=<?= (int)$r['id'] ?>" class="btn btn-outline">
                                    Ver detalle
                                </a>

                                <?php if ($estatus === 'FIRMADA'): ?>
                                    <a href="retroalimentacion_pdf.php?id=<?= (int)$r['id'] ?>" target="_blank" class="btn btn-primary">
                                        Ver PDF
                                    </a>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>

</div>
</body>
</html>