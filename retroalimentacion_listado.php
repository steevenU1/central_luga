<?php
// retroalimentacion_listado.php — Listado de retroalimentaciones Zentral

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = trim((string)($_SESSION['rol'] ?? ''));
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$mensaje = trim((string)($_GET['msg'] ?? ''));
$error   = trim((string)($_GET['error'] ?? ''));

/* Datos del usuario actual */
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.nombre,
        u.rol,
        u.id_sucursal,
        s.nombre AS sucursal,
        s.zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$usuarioActual = $stmt->get_result()->fetch_assoc();
$stmt->close();

$miZona = $usuarioActual['zona'] ?? null;
$miSucursalNombre = $usuarioActual['sucursal'] ?? '';

$estatusFiltro = trim((string)($_GET['estatus'] ?? ''));
$buscar = trim((string)($_GET['buscar'] ?? ''));

$where = [];
$params = [];
$types = '';

if ($rolUsuario === 'Admin') {
    $where[] = "r.id_lider = ?";
    $params[] = $idUsuario;
    $types .= 'i';
} elseif ($rolUsuario === 'GerenteZona') {
    $where[] = "(
        r.id_lider = ?
        OR (
            r.rol_colaborador = 'Gerente'
            AND r.zona = ?
        )
    )";
    $params[] = $idUsuario;
    $params[] = $miZona;
    $types .= 'is';
} elseif ($rolUsuario === 'Gerente') {
    $where[] = "(
        r.id_lider = ?
        OR (
            r.rol_colaborador = 'Ejecutivo'
            AND r.id_sucursal = ?
        )
    )";
    $params[] = $idUsuario;
    $params[] = $idSucursal;
    $types .= 'ii';
} else {
    $where[] = "r.id_colaborador = ?";
    $params[] = $idUsuario;
    $types .= 'i';
}

if ($estatusFiltro !== '') {
    $where[] = "r.estatus = ?";
    $params[] = $estatusFiltro;
    $types .= 's';
}

if ($buscar !== '') {
    $where[] = "(
        uc.nombre LIKE ?
        OR ul.nombre LIKE ?
        OR r.periodo LIKE ?
        OR r.folio LIKE ?
    )";
    $like = '%' . $buscar . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= 'ssss';
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT
        r.*,
        uc.nombre AS colaborador_nombre,
        uc.usuario AS colaborador_usuario,
        ul.nombre AS lider_nombre,
        s.nombre AS sucursal_nombre
    FROM retroalimentaciones r
    LEFT JOIN usuarios uc ON uc.id = r.id_colaborador
    LEFT JOIN usuarios ul ON ul.id = r.id_lider
    LEFT JOIN sucursales s ON s.id = r.id_sucursal
    $whereSql
    ORDER BY r.fecha_creacion DESC, r.id DESC
    LIMIT 300
";

$stmt = $conn->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$retros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function badgeEstatus(string $estatus): string {
    $estatus = strtoupper($estatus);

    if ($estatus === 'FIRMADA') {
        return '<span class="badge rounded-pill text-bg-success">Firmada</span>';
    }

    if ($estatus === 'CANCELADA') {
        return '<span class="badge rounded-pill text-bg-danger">Cancelada</span>';
    }

    return '<span class="badge rounded-pill text-bg-warning">Pendiente</span>';
}

$puedeCrear = in_array($rolUsuario, ['Admin', 'GerenteZona', 'Gerente'], true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Retroalimentaciones | Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(0, 174, 255, 0.15), transparent 28%),
                radial-gradient(circle at bottom right, rgba(0, 70, 140, 0.15), transparent 32%),
                linear-gradient(135deg, #071426 0%, #102a43 42%, #f4f7fb 42%, #f4f7fb 100%);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .zentral-shell {
            max-width: 1240px;
            margin: 0 auto;
            padding: 28px 16px 42px;
        }

        .hero {
            background: linear-gradient(135deg, #071426, #123c69);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 45px rgba(5, 20, 40, 0.22);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(0, 200, 255, 0.14);
            right: -80px;
            top: -100px;
        }

        .hero h1 {
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0;
        }

        .hero p {
            color: rgba(255,255,255,.78);
            margin-bottom: 0;
        }

        .glass-card {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 22px;
            box-shadow: 0 18px 40px rgba(15, 35, 65, 0.13);
        }

        .btn-zentral {
            background: linear-gradient(135deg, #083b66, #0d8ddf);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-weight: 800;
            padding: 11px 18px;
            box-shadow: 0 12px 26px rgba(13, 141, 223, .25);
        }

        .btn-zentral:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            border: 1px solid #d8e1ec;
            padding: 10px 13px;
        }

        .table thead th {
            background: #0f243d;
            color: #fff;
            border: none;
            font-size: .86rem;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
            font-size: .92rem;
        }

        .table-responsive {
            border-radius: 18px;
            overflow-x: auto;
        }

        .mini-text {
            color: #6c7a89;
            font-size: .82rem;
        }

        .folio {
            font-weight: 800;
            color: #0f456f;
        }

        .empty-state {
            border: 1px dashed #bdd7ec;
            background: #f7fbff;
            border-radius: 20px;
            padding: 34px;
            text-align: center;
        }

        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #edf6ff;
            color: #0d4d83;
            border: 1px solid #d6ecff;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: .88rem;
            font-weight: 700;
        }
    </style>
</head>
<body>

<?php
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}
?>

<div class="zentral-shell">

    <div class="hero mb-4">
        <div class="position-relative">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h1>Retroalimentaciones</h1>
                    <p class="mt-2">
                        Consulta el historial, seguimiento y estado de firma de cada retroalimentación.
                    </p>
                </div>

                <div class="text-md-end">
                    <div class="info-chip mb-2">
                        <i class="bi bi-person-badge"></i>
                        <?= h($rolUsuario) ?>
                    </div>

                    <?php if ($miSucursalNombre): ?>
                        <div class="small text-white-50">
                            <?= h($miSucursalNombre) ?>
                            <?= $miZona ? ' · ' . h($miZona) : '' ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($puedeCrear): ?>
                        <div class="mt-3">
                            <a href="retroalimentacion_nueva.php" class="btn btn-zentral">
                                <i class="bi bi-plus-circle me-1"></i>
                                Nueva retroalimentación
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success rounded-4 shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i><?= h($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger rounded-4 shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="glass-card p-4">

        <form method="GET" class="row g-3 align-items-end mb-4">
            <div class="col-12 col-md-4">
                <label class="form-label fw-bold">Buscar</label>
                <input type="text" name="buscar" class="form-control"
                       value="<?= h($buscar) ?>"
                       placeholder="Colaborador, líder, folio o periodo">
            </div>

            <div class="col-12 col-md-3">
                <label class="form-label fw-bold">Estatus</label>
                <select name="estatus" class="form-select">
                    <option value="">Todos</option>
                    <option value="PENDIENTE" <?= $estatusFiltro === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                    <option value="FIRMADA" <?= $estatusFiltro === 'FIRMADA' ? 'selected' : '' ?>>Firmada</option>
                    <option value="CANCELADA" <?= $estatusFiltro === 'CANCELADA' ? 'selected' : '' ?>>Cancelada</option>
                </select>
            </div>

            <div class="col-12 col-md-5 d-flex gap-2 justify-content-md-end">
                <button class="btn btn-zentral" type="submit">
                    <i class="bi bi-search me-1"></i>
                    Filtrar
                </button>

                <a href="retroalimentacion_listado.php" class="btn btn-light border rounded-4 px-4">
                    Limpiar
                </a>
            </div>
        </form>

        <?php if (empty($retros)): ?>
            <div class="empty-state">
                <div class="fs-1 mb-2">
                    <i class="bi bi-chat-square-heart"></i>
                </div>
                <h5 class="fw-bold">Sin retroalimentaciones</h5>
                <p class="text-muted mb-0">
                    No se encontraron registros con los filtros actuales.
                </p>
            </div>
        <?php else: ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                    <tr>
                        <th>Folio</th>
                        <th>Fecha</th>
                        <th>Colaborador</th>
                        <th>Líder</th>
                        <th>Periodo</th>
                        <th>Sucursal / zona</th>
                        <th>Estatus</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($retros as $r): ?>
                        <?php
                        $folio = $r['folio'] ?? '';
                        if ($folio === '') {
                            $folio = 'RETRO-' . str_pad((string)$r['id'], 6, '0', STR_PAD_LEFT);
                        }

                        $fechaCreacion = !empty($r['fecha_creacion'])
                            ? date('d/m/Y H:i', strtotime($r['fecha_creacion']))
                            : '-';
                        ?>
                        <tr>
                            <td>
                                <div class="folio"><?= h($folio) ?></div>
                                <div class="mini-text">ID <?= (int)$r['id'] ?></div>
                            </td>

                            <td><?= h($fechaCreacion) ?></td>

                            <td>
                                <strong><?= h($r['colaborador_nombre'] ?? 'Sin nombre') ?></strong>
                                <div class="mini-text"><?= h($r['rol_colaborador'] ?? '') ?></div>
                            </td>

                            <td>
                                <?= h($r['lider_nombre'] ?? 'Sin líder') ?>
                                <div class="mini-text"><?= h($r['rol_lider'] ?? '') ?></div>
                            </td>

                            <td><?= h($r['periodo'] ?? '-') ?></td>

                            <td>
                                <?= h($r['sucursal_nombre'] ?? '-') ?>
                                <div class="mini-text"><?= h($r['zona'] ?? 'Sin zona') ?></div>
                            </td>

                            <td>
                                <?= badgeEstatus((string)$r['estatus']) ?>
                                <?php if (($r['estatus'] ?? '') === 'FIRMADA' && !empty($r['fecha_firma'])): ?>
                                    <div class="mini-text mt-1">
                                        <?= h(date('d/m/Y H:i', strtotime($r['fecha_firma']))) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="text-end">
                                <a href="retroalimentacion_detalle.php?id=<?= (int)$r['id'] ?>"
                                   class="btn btn-sm btn-outline-primary rounded-pill">
                                    <i class="bi bi-eye me-1"></i>
                                    Ver
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mini-text mt-3">
                Mostrando máximo 300 registros recientes.
            </div>

        <?php endif; ?>

    </div>
</div>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

</body>
</html>