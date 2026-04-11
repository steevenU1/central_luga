<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
date_default_timezone_set('America/Mexico_City');

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$rol       = $_SESSION['rol'] ?? '';
$mensaje   = '';
$tipoMsg   = 'success';

/* =========================================================
   Helpers
========================================================= */
function h($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function normalizar_texto(?string $v): string {
    return trim((string)$v);
}

function es_subdis_row(array $row): bool {
    $propiedad = strtolower(trim((string)($row['propiedad'] ?? '')));
    $subtipo   = strtolower(trim((string)($row['subtipo'] ?? '')));
    $idSubdis  = (int)($row['id_subdis'] ?? 0);

    if ($propiedad === 'subdistribuidor' || $propiedad === 'subdis') return true;
    if ($subtipo === 'subdistribuidor') return true;
    if ($idSubdis > 0) return true;
    return false;
}

function valor_post(string $key, $default = '') {
    return $_POST[$key] ?? $default;
}

/* =========================================================
   Seguridad básica de acceso
   Ajusta si quieres permitir más roles
========================================================= */
$rolesPermitidos = ['Admin', 'Administrador', 'Logistica', 'Sistemas'];
if (!in_array($rol, $rolesPermitidos, true)) {
    http_response_code(403);
    echo "<h3 style='font-family:Arial,sans-serif;padding:30px;'>Acceso denegado</h3>";
    exit();
}

/* =========================================================
   Procesar alta
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear') {
    $nombre         = normalizar_texto(valor_post('nombre'));
    $zona           = normalizar_texto(valor_post('zona'));
    $propiedad      = normalizar_texto(valor_post('propiedad'));
    $idSubdis       = (int)valor_post('id_subdis', 0);
    $cuotaSemanal   = (float)valor_post('cuota_semanal', 0);
    $tipoSucursal   = normalizar_texto(valor_post('tipo_sucursal'));
    $subtipo        = normalizar_texto(valor_post('subtipo'));
    $activo         = (int)valor_post('activo', 1);

    $idGerente      = (int)valor_post('id_gerente', 0);
    $direccion      = normalizar_texto(valor_post('direccion'));
    $ciudad         = normalizar_texto(valor_post('ciudad'));
    $estado         = normalizar_texto(valor_post('estado'));
    $codigoPostal   = normalizar_texto(valor_post('codigo_postal'));
    $telefono       = normalizar_texto(valor_post('telefono'));
    $referencia     = normalizar_texto(valor_post('referencia'));

    if ($nombre === '') {
        $mensaje = "El nombre de la sucursal es obligatorio.";
        $tipoMsg = 'danger';
    } else {
        // Si no es subdis, limpiar id_subdis
        if (strtolower($propiedad) !== 'subdistribuidor' && strtolower($propiedad) !== 'subdis') {
            $idSubdis = 0;
        }

        if ($idGerente <= 0) $idGerente = null;

        $sql = "INSERT INTO sucursales 
                (nombre, zona, propiedad, id_subdis, cuota_semanal, tipo_sucursal, subtipo, activo,
                 id_gerente, direccion, ciudad, estado, codigo_postal, telefono, referencia)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "sssidssiissssss",
                $nombre,
                $zona,
                $propiedad,
                $idSubdis,
                $cuotaSemanal,
                $tipoSucursal,
                $subtipo,
                $activo,
                $idGerente,
                $direccion,
                $ciudad,
                $estado,
                $codigoPostal,
                $telefono,
                $referencia
            );

            if ($stmt->execute()) {
                $mensaje = "Sucursal creada correctamente.";
                $tipoMsg = 'success';
            } else {
                $mensaje = "No se pudo crear la sucursal: " . $stmt->error;
                $tipoMsg = 'danger';
            }
            $stmt->close();
        } else {
            $mensaje = "Error preparando inserción: " . $conn->error;
            $tipoMsg = 'danger';
        }
    }
}

/* =========================================================
   Procesar edición
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'editar') {
    $idSucursal     = (int)valor_post('id');
    $nombre         = normalizar_texto(valor_post('nombre'));
    $zona           = normalizar_texto(valor_post('zona'));
    $propiedad      = normalizar_texto(valor_post('propiedad'));
    $idSubdis       = (int)valor_post('id_subdis', 0);
    $cuotaSemanal   = (float)valor_post('cuota_semanal', 0);
    $tipoSucursal   = normalizar_texto(valor_post('tipo_sucursal'));
    $subtipo        = normalizar_texto(valor_post('subtipo'));
    $activo         = (int)valor_post('activo', 1);

    $idGerente      = (int)valor_post('id_gerente', 0);
    $direccion      = normalizar_texto(valor_post('direccion'));
    $ciudad         = normalizar_texto(valor_post('ciudad'));
    $estado         = normalizar_texto(valor_post('estado'));
    $codigoPostal   = normalizar_texto(valor_post('codigo_postal'));
    $telefono       = normalizar_texto(valor_post('telefono'));
    $referencia     = normalizar_texto(valor_post('referencia'));

    if ($idSucursal <= 0) {
        $mensaje = "ID de sucursal inválido.";
        $tipoMsg = 'danger';
    } elseif ($nombre === '') {
        $mensaje = "El nombre de la sucursal es obligatorio.";
        $tipoMsg = 'danger';
    } else {
        if (strtolower($propiedad) !== 'subdistribuidor' && strtolower($propiedad) !== 'subdis') {
            $idSubdis = 0;
        }

        if ($idGerente <= 0) $idGerente = null;

        $sql = "UPDATE sucursales SET
                    nombre = ?,
                    zona = ?,
                    propiedad = ?,
                    id_subdis = ?,
                    cuota_semanal = ?,
                    tipo_sucursal = ?,
                    subtipo = ?,
                    activo = ?,
                    id_gerente = ?,
                    direccion = ?,
                    ciudad = ?,
                    estado = ?,
                    codigo_postal = ?,
                    telefono = ?,
                    referencia = ?
                WHERE id = ?";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param(
                "sssidssiissssssi",
                $nombre,
                $zona,
                $propiedad,
                $idSubdis,
                $cuotaSemanal,
                $tipoSucursal,
                $subtipo,
                $activo,
                $idGerente,
                $direccion,
                $ciudad,
                $estado,
                $codigoPostal,
                $telefono,
                $referencia,
                $idSucursal
            );

            if ($stmt->execute()) {
                $mensaje = "Sucursal actualizada correctamente.";
                $tipoMsg = 'success';
            } else {
                $mensaje = "No se pudo actualizar la sucursal: " . $stmt->error;
                $tipoMsg = 'danger';
            }
            $stmt->close();
        } else {
            $mensaje = "Error preparando actualización: " . $conn->error;
            $tipoMsg = 'danger';
        }
    }
}

/* =========================================================
   Catálogo de gerentes / responsables
   Puedes ajustar roles si quieres limitar más fino
========================================================= */
$usuariosResponsables = [];
$sqlUsuarios = "
    SELECT id, nombre, rol, id_sucursal
    FROM usuarios
    WHERE nombre IS NOT NULL AND nombre <> ''
      AND rol IN ('Gerente','GerenteZona','Admin','Administrador','Supervisor','Subdis_Gerente')
    ORDER BY nombre ASC
";
$rsUsuarios = $conn->query($sqlUsuarios);
if ($rsUsuarios) {
    while ($u = $rsUsuarios->fetch_assoc()) {
        $usuariosResponsables[] = $u;
    }
}

/* =========================================================
   Consulta principal
========================================================= */
$sucursales = [];
$sql = "
    SELECT 
        s.*,
        u.nombre AS gerente_nombre,
        u.rol AS gerente_rol
    FROM sucursales s
    LEFT JOIN usuarios u ON u.id = s.id_gerente
    ORDER BY 
        CASE WHEN s.activo = 1 THEN 0 ELSE 1 END,
        s.nombre ASC
";
$rs = $conn->query($sql);
if ($rs) {
    while ($row = $rs->fetch_assoc()) {
        $sucursales[] = $row;
    }
}

/* =========================================================
   Separación / KPIs
========================================================= */
$propias = [];
$subdis  = [];

$kpiTotal       = count($sucursales);
$kpiActivas     = 0;
$kpiInactivas   = 0;
$kpiPropias     = 0;
$kpiSubdis      = 0;
$kpiConGerente  = 0;

foreach ($sucursales as $s) {
    $activa = (int)($s['activo'] ?? 0) === 1;
    if ($activa) $kpiActivas++; else $kpiInactivas++;
    if (!empty($s['id_gerente'])) $kpiConGerente++;

    if (es_subdis_row($s)) {
        $subdis[] = $s;
        $kpiSubdis++;
    } else {
        $propias[] = $s;
        $kpiPropias++;
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Sucursales</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Si tu navbar ya carga Bootstrap, puedes comentar esta línea -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --bg:#f4f7fb;
            --card:#ffffff;
            --ink:#1f2937;
            --muted:#6b7280;
            --line:#e5e7eb;
            --primary:#0d6efd;
            --success:#198754;
            --warning:#f59e0b;
            --danger:#dc3545;
            --dark:#111827;
        }

        body{
            background:
                radial-gradient(circle at top left, rgba(13,110,253,.08), transparent 28%),
                radial-gradient(circle at top right, rgba(25,135,84,.07), transparent 24%),
                var(--bg);
            color:var(--ink);
        }

        .page-wrap{
            padding: 24px 0 40px;
        }

        .hero-card{
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
            color:#fff;
            border:none;
            border-radius: 22px;
            overflow:hidden;
            box-shadow: 0 18px 35px rgba(15,23,42,.16);
        }

        .hero-chip{
            display:inline-flex;
            align-items:center;
            gap:8px;
            background:rgba(255,255,255,.12);
            border:1px solid rgba(255,255,255,.16);
            color:#fff;
            padding:8px 14px;
            border-radius:999px;
            font-size:.92rem;
        }

        .kpi-card{
            border:none;
            border-radius:18px;
            background:rgba(255,255,255,.95);
            box-shadow: 0 10px 24px rgba(17,24,39,.06);
            transition: transform .18s ease, box-shadow .18s ease;
            height:100%;
        }

        .kpi-card:hover{
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(17,24,39,.10);
        }

        .kpi-icon{
            width:50px;
            height:50px;
            border-radius:14px;
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:1.25rem;
        }

        .section-card{
            border:none;
            border-radius:22px;
            background:var(--card);
            box-shadow:0 12px 28px rgba(17,24,39,.07);
            overflow:hidden;
        }

        .section-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            flex-wrap:wrap;
            padding:18px 20px;
            border-bottom:1px solid var(--line);
            background:linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }

        .section-title{
            margin:0;
            font-weight:800;
            color:#0f172a;
            letter-spacing:.2px;
        }

        .section-sub{
            margin:4px 0 0;
            color:var(--muted);
            font-size:.92rem;
        }

        .table thead th{
            background:#0f172a;
            color:#fff;
            border-color:#1f2937 !important;
            font-size:.89rem;
            white-space:nowrap;
        }

        .table td{
            vertical-align:middle;
        }

        .sucursal-name{
            font-weight:700;
            color:#0f172a;
        }

        .text-soft{
            color:var(--muted);
        }

        .badge-soft{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:8px 10px;
            border-radius:999px;
            font-size:.78rem;
            font-weight:700;
        }

        .badge-propia{
            background:rgba(25,135,84,.12);
            color:#146c43;
        }

        .badge-subdis{
            background:rgba(13,110,253,.12);
            color:#0a58ca;
        }

        .badge-activa{
            background:rgba(25,135,84,.12);
            color:#146c43;
        }

        .badge-inactiva{
            background:rgba(220,53,69,.12);
            color:#b02a37;
        }

        .btn-round{
            border-radius:12px;
        }

        .search-box{
            min-width:280px;
        }

        .modal-content{
            border:none;
            border-radius:20px;
            overflow:hidden;
            box-shadow:0 24px 60px rgba(0,0,0,.2);
        }

        .modal-header{
            background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
            color:#fff;
            border:none;
        }

        .modal-header .btn-close{
            filter: invert(1);
        }

        .form-label{
            font-weight:700;
            color:#334155;
            margin-bottom:6px;
        }

        .form-control, .form-select{
            border-radius:12px;
            border:1px solid #dbe3ee;
            min-height:44px;
        }

        textarea.form-control{
            min-height:95px;
        }

        .mini-note{
            font-size:.84rem;
            color:var(--muted);
        }

        .empty-box{
            text-align:center;
            padding:28px;
            color:var(--muted);
        }

        .table-responsive{
            overflow:auto;
        }

        @media (max-width: 768px){
            .search-box{
                min-width:100%;
            }
        }
    </style>
</head>
<body>

<?php if (file_exists(__DIR__ . '/navbar.php')): ?>
    <?php include __DIR__ . '/navbar.php'; ?>
<?php endif; ?>

<div class="container-fluid page-wrap px-3 px-md-4">

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?= h($tipoMsg) ?> alert-dismissible fade show shadow-sm" role="alert">
            <?= h($mensaje) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero -->
    <div class="card hero-card mb-4">
        <div class="card-body p-4 p-md-5">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
                <div>
                    <div class="hero-chip mb-3">
                        <i class="bi bi-building-gear"></i>
                        Módulo administrativo de tiendas
                    </div>
                    <h1 class="h3 fw-bold mb-2">Gestión de Sucursales</h1>
                    <p class="mb-0 opacity-75">
                        Administra tiendas propias y subdistribuidores, asigna gerente, dirección y deja lista la base para futuros expedientes y documentos.
                    </p>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button class="btn btn-light btn-round px-4" data-bs-toggle="modal" data-bs-target="#modalAltaSucursal">
                        <i class="bi bi-plus-circle me-1"></i> Nueva sucursal
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- KPIs -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-2">
            <div class="card kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon bg-primary-subtle text-primary">
                        <i class="bi bi-shop"></i>
                    </div>
                    <div>
                        <div class="text-soft small">Total</div>
                        <div class="fs-4 fw-bold"><?= (int)$kpiTotal ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-2">
            <div class="card kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon bg-success-subtle text-success">
                        <i class="bi bi-check-circle"></i>
                    </div>
                    <div>
                        <div class="text-soft small">Activas</div>
                        <div class="fs-4 fw-bold"><?= (int)$kpiActivas ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-2">
            <div class="card kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon bg-danger-subtle text-danger">
                        <i class="bi bi-slash-circle"></i>
                    </div>
                    <div>
                        <div class="text-soft small">Inactivas</div>
                        <div class="fs-4 fw-bold"><?= (int)$kpiInactivas ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-2">
            <div class="card kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(25,135,84,.12);color:#198754;">
                        <i class="bi bi-building"></i>
                    </div>
                    <div>
                        <div class="text-soft small">Propias</div>
                        <div class="fs-4 fw-bold"><?= (int)$kpiPropias ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-2">
            <div class="card kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon" style="background:rgba(13,110,253,.12);color:#0d6efd;">
                        <i class="bi bi-diagram-3"></i>
                    </div>
                    <div>
                        <div class="text-soft small">Subdis</div>
                        <div class="fs-4 fw-bold"><?= (int)$kpiSubdis ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-2">
            <div class="card kpi-card">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="kpi-icon bg-warning-subtle text-warning">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <div>
                        <div class="text-soft small">Con gerente</div>
                        <div class="fs-4 fw-bold"><?= (int)$kpiConGerente ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla Propias -->
    <div class="card section-card mb-4">
        <div class="section-head">
            <div>
                <h2 class="h5 section-title mb-0">
                    <i class="bi bi-building me-2 text-success"></i>Tiendas Propias
                </h2>
                <p class="section-sub">Sucursales operadas directamente por la empresa.</p>
            </div>

            <div class="search-box">
                <input type="text" class="form-control" id="buscarPropias" placeholder="Buscar sucursal propia...">
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($propias)): ?>
                <div class="empty-box">
                    <i class="bi bi-inboxes fs-2 d-block mb-2"></i>
                    No hay sucursales propias registradas.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaPropias">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sucursal</th>
                                <th>Zona</th>
                                <th>Tipo</th>
                                <th>Subtipo</th>
                                <th>Gerente</th>
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Cuota</th>
                                <th>Estatus</th>
                                <th style="min-width:120px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($propias as $row): ?>
                                <?php
                                    $direccionCompleta = trim(
                                        ($row['direccion'] ?? '') .
                                        (($row['ciudad'] ?? '') !== '' ? ', ' . $row['ciudad'] : '') .
                                        (($row['estado'] ?? '') !== '' ? ', ' . $row['estado'] : '')
                                    );
                                ?>
                                <tr class="fila-propia">
                                    <td><?= (int)$row['id'] ?></td>
                                    <td>
                                        <div class="sucursal-name"><?= h($row['nombre']) ?></div>
                                        <div class="mt-1">
                                            <span class="badge-soft badge-propia">
                                                <i class="bi bi-stars"></i> Propia
                                            </span>
                                        </div>
                                    </td>
                                    <td><?= h($row['zona'] ?: '-') ?></td>
                                    <td><?= h($row['tipo_sucursal'] ?: '-') ?></td>
                                    <td><?= h($row['subtipo'] ?: '-') ?></td>
                                    <td>
                                        <?php if (!empty($row['gerente_nombre'])): ?>
                                            <div class="fw-semibold"><?= h($row['gerente_nombre']) ?></div>
                                            <div class="text-soft small"><?= h($row['gerente_rol'] ?: '') ?></div>
                                        <?php else: ?>
                                            <span class="text-soft">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($direccionCompleta !== ''): ?>
                                            <?= h($direccionCompleta) ?>
                                        <?php else: ?>
                                            <span class="text-soft">Sin dirección</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['telefono'] ?: '-') ?></td>
                                    <td>$<?= number_format((float)$row['cuota_semanal'], 2) ?></td>
                                    <td>
                                        <?php if ((int)$row['activo'] === 1): ?>
                                            <span class="badge-soft badge-activa">
                                                <i class="bi bi-check-circle-fill"></i> Activa
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-soft badge-inactiva">
                                                <i class="bi bi-x-circle-fill"></i> Inactiva
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary btn-round btnEditarSucursal"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalEditarSucursal"
                                            data-id="<?= (int)$row['id'] ?>"
                                            data-nombre="<?= h($row['nombre']) ?>"
                                            data-zona="<?= h($row['zona']) ?>"
                                            data-propiedad="<?= h($row['propiedad']) ?>"
                                            data-id_subdis="<?= (int)$row['id_subdis'] ?>"
                                            data-cuota_semanal="<?= h($row['cuota_semanal']) ?>"
                                            data-tipo_sucursal="<?= h($row['tipo_sucursal']) ?>"
                                            data-subtipo="<?= h($row['subtipo']) ?>"
                                            data-activo="<?= (int)$row['activo'] ?>"
                                            data-id_gerente="<?= (int)$row['id_gerente'] ?>"
                                            data-direccion="<?= h($row['direccion']) ?>"
                                            data-ciudad="<?= h($row['ciudad']) ?>"
                                            data-estado="<?= h($row['estado']) ?>"
                                            data-codigo_postal="<?= h($row['codigo_postal']) ?>"
                                            data-telefono="<?= h($row['telefono']) ?>"
                                            data-referencia="<?= h($row['referencia']) ?>"
                                        >
                                            <i class="bi bi-pencil-square me-1"></i> Editar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tabla Subdis -->
    <div class="card section-card">
        <div class="section-head">
            <div>
                <h2 class="h5 section-title mb-0">
                    <i class="bi bi-diagram-3 me-2 text-primary"></i>Tiendas de Subdistribuidores
                </h2>
                <p class="section-sub">Sucursales asociadas a un subdistribuidor.</p>
            </div>

            <div class="search-box">
                <input type="text" class="form-control" id="buscarSubdis" placeholder="Buscar sucursal subdis...">
            </div>
        </div>

        <div class="card-body p-0">
            <?php if (empty($subdis)): ?>
                <div class="empty-box">
                    <i class="bi bi-diagram-2 fs-2 d-block mb-2"></i>
                    No hay sucursales de subdistribuidores registradas.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" id="tablaSubdis">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Sucursal</th>
                                <th>Zona</th>
                                <th>ID Subdis</th>
                                <th>Tipo</th>
                                <th>Gerente</th>
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Cuota</th>
                                <th>Estatus</th>
                                <th style="min-width:120px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subdis as $row): ?>
                                <?php
                                    $direccionCompleta = trim(
                                        ($row['direccion'] ?? '') .
                                        (($row['ciudad'] ?? '') !== '' ? ', ' . $row['ciudad'] : '') .
                                        (($row['estado'] ?? '') !== '' ? ', ' . $row['estado'] : '')
                                    );
                                ?>
                                <tr class="fila-subdis">
                                    <td><?= (int)$row['id'] ?></td>
                                    <td>
                                        <div class="sucursal-name"><?= h($row['nombre']) ?></div>
                                        <div class="mt-1">
                                            <span class="badge-soft badge-subdis">
                                                <i class="bi bi-diagram-3-fill"></i> Subdistribuidor
                                            </span>
                                        </div>
                                    </td>
                                    <td><?= h($row['zona'] ?: '-') ?></td>
                                    <td><?= (int)$row['id_subdis'] ?></td>
                                    <td><?= h($row['tipo_sucursal'] ?: '-') ?></td>
                                    <td>
                                        <?php if (!empty($row['gerente_nombre'])): ?>
                                            <div class="fw-semibold"><?= h($row['gerente_nombre']) ?></div>
                                            <div class="text-soft small"><?= h($row['gerente_rol'] ?: '') ?></div>
                                        <?php else: ?>
                                            <span class="text-soft">Sin asignar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($direccionCompleta !== ''): ?>
                                            <?= h($direccionCompleta) ?>
                                        <?php else: ?>
                                            <span class="text-soft">Sin dirección</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= h($row['telefono'] ?: '-') ?></td>
                                    <td>$<?= number_format((float)$row['cuota_semanal'], 2) ?></td>
                                    <td>
                                        <?php if ((int)$row['activo'] === 1): ?>
                                            <span class="badge-soft badge-activa">
                                                <i class="bi bi-check-circle-fill"></i> Activa
                                            </span>
                                        <?php else: ?>
                                            <span class="badge-soft badge-inactiva">
                                                <i class="bi bi-x-circle-fill"></i> Inactiva
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-primary btn-round btnEditarSucursal"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalEditarSucursal"
                                            data-id="<?= (int)$row['id'] ?>"
                                            data-nombre="<?= h($row['nombre']) ?>"
                                            data-zona="<?= h($row['zona']) ?>"
                                            data-propiedad="<?= h($row['propiedad']) ?>"
                                            data-id_subdis="<?= (int)$row['id_subdis'] ?>"
                                            data-cuota_semanal="<?= h($row['cuota_semanal']) ?>"
                                            data-tipo_sucursal="<?= h($row['tipo_sucursal']) ?>"
                                            data-subtipo="<?= h($row['subtipo']) ?>"
                                            data-activo="<?= (int)$row['activo'] ?>"
                                            data-id_gerente="<?= (int)$row['id_gerente'] ?>"
                                            data-direccion="<?= h($row['direccion']) ?>"
                                            data-ciudad="<?= h($row['ciudad']) ?>"
                                            data-estado="<?= h($row['estado']) ?>"
                                            data-codigo_postal="<?= h($row['codigo_postal']) ?>"
                                            data-telefono="<?= h($row['telefono']) ?>"
                                            data-referencia="<?= h($row['referencia']) ?>"
                                        >
                                            <i class="bi bi-pencil-square me-1"></i> Editar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- =========================================================
     MODAL ALTA
========================================================= -->
<div class="modal fade" id="modalAltaSucursal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="accion" value="crear">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-plus-circle me-2"></i>Alta de sucursal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <h6 class="fw-bold text-primary mb-1">Datos generales</h6>
                            <div class="mini-note">Información principal de identificación y operación de la sucursal.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nombre de la sucursal *</label>
                            <input type="text" name="nombre" class="form-control" required>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Zona</label>
                            <input type="text" name="zona" class="form-control" placeholder="Zona Norte / Zona Sur / etc.">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Cuota semanal</label>
                            <input type="number" step="0.01" name="cuota_semanal" class="form-control" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Propiedad</label>
                            <select name="propiedad" class="form-select propiedad-select">
                                <option value="Luga">Luga</option>
                                <option value="Subdistribuidor">Subdistribuidor</option>
                            </select>
                        </div>

                        <div class="col-md-4 campo-id-subdis d-none">
                            <label class="form-label">ID Subdis</label>
                            <input type="number" name="id_subdis" class="form-control" min="0" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Activo</label>
                            <select name="activo" class="form-select">
                                <option value="1" selected>Sí</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Tipo de sucursal</label>
                            <select name="tipo_sucursal" class="form-select">
                                <option value="Tienda" selected>Tienda</option>
                                <option value="Almacen">Almacén</option>
                                <option value="Oficina">Oficina</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Subtipo</label>
                            <select name="subtipo" class="form-select">
                                <option value="Propia" selected>Propia</option>
                                <option value="Subdistribuidor">Subdistribuidor</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Gerente / Responsable</label>
                            <select name="id_gerente" class="form-select">
                                <option value="0">Sin asignar</option>
                                <?php foreach ($usuariosResponsables as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>">
                                        <?= h($u['nombre']) ?><?= !empty($u['rol']) ? ' | ' . h($u['rol']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 mt-3">
                            <h6 class="fw-bold text-primary mb-1">Ubicación y contacto</h6>
                            <div class="mini-note">Estos datos dejarán lista la ficha base de la tienda para documentos y expediente.</div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Dirección</label>
                            <input type="text" name="direccion" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Ciudad</label>
                            <input type="text" name="ciudad" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Estado</label>
                            <input type="text" name="estado" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Código postal</label>
                            <input type="text" name="codigo_postal" class="form-control">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Referencia</label>
                            <textarea name="referencia" class="form-control" placeholder="Entre calles, plaza, local, referencias de ubicación, etc."></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light btn-round px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-round px-4">
                        <i class="bi bi-save me-1"></i> Guardar sucursal
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================
     MODAL EDICIÓN
========================================================= -->
<div class="modal fade" id="modalEditarSucursal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" autocomplete="off">
                <input type="hidden" name="accion" value="editar">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-pencil-square me-2"></i>Editar sucursal
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">

                        <div class="col-12">
                            <h6 class="fw-bold text-primary mb-1">Datos generales</h6>
                            <div class="mini-note">Actualiza la información operativa principal de la sucursal.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Nombre de la sucursal *</label>
                            <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Zona</label>
                            <input type="text" name="zona" id="edit_zona" class="form-control">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Cuota semanal</label>
                            <input type="number" step="0.01" name="cuota_semanal" id="edit_cuota_semanal" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Propiedad</label>
                            <select name="propiedad" id="edit_propiedad" class="form-select propiedad-select">
                                <option value="Luga">Luga</option>
                                <option value="Subdistribuidor">Subdistribuidor</option>
                            </select>
                        </div>

                        <div class="col-md-4 campo-id-subdis d-none" id="edit_wrap_id_subdis">
                            <label class="form-label">ID Subdis</label>
                            <input type="number" name="id_subdis" id="edit_id_subdis" class="form-control" min="0" value="0">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Activo</label>
                            <select name="activo" id="edit_activo" class="form-select">
                                <option value="1">Sí</option>
                                <option value="0">No</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Tipo de sucursal</label>
                            <select name="tipo_sucursal" id="edit_tipo_sucursal" class="form-select">
                                <option value="Tienda">Tienda</option>
                                <option value="Almacen">Almacén</option>
                                <option value="Oficina">Oficina</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Subtipo</label>
                            <select name="subtipo" id="edit_subtipo" class="form-select">
                                <option value="Propia">Propia</option>
                                <option value="Subdistribuidor">Subdistribuidor</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Gerente / Responsable</label>
                            <select name="id_gerente" id="edit_id_gerente" class="form-select">
                                <option value="0">Sin asignar</option>
                                <?php foreach ($usuariosResponsables as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>">
                                        <?= h($u['nombre']) ?><?= !empty($u['rol']) ? ' | ' . h($u['rol']) : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 mt-3">
                            <h6 class="fw-bold text-primary mb-1">Ubicación y contacto</h6>
                            <div class="mini-note">Aquí va la información que más adelante alimentará el expediente documental.</div>
                        </div>

                        <div class="col-md-8">
                            <label class="form-label">Dirección</label>
                            <input type="text" name="direccion" id="edit_direccion" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Teléfono</label>
                            <input type="text" name="telefono" id="edit_telefono" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Ciudad</label>
                            <input type="text" name="ciudad" id="edit_ciudad" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Estado</label>
                            <input type="text" name="estado" id="edit_estado" class="form-control">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Código postal</label>
                            <input type="text" name="codigo_postal" id="edit_codigo_postal" class="form-control">
                        </div>

                        <div class="col-12">
                            <label class="form-label">Referencia</label>
                            <textarea name="referencia" id="edit_referencia" class="form-control"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-0 pt-0 px-4 pb-4">
                    <button type="button" class="btn btn-light btn-round px-4" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary btn-round px-4">
                        <i class="bi bi-save me-1"></i> Guardar cambios
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Si tu navbar ya carga Bootstrap JS, puedes comentar esta línea -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){
    function filtrarTabla(inputId, tableId, rowSelector) {
        const input = document.getElementById(inputId);
        const table = document.getElementById(tableId);
        if (!input || !table) return;

        input.addEventListener('input', function(){
            const q = this.value.toLowerCase().trim();
            const rows = table.querySelectorAll('tbody ' + rowSelector);

            rows.forEach(row => {
                const txt = row.innerText.toLowerCase();
                row.style.display = txt.includes(q) ? '' : 'none';
            });
        });
    }

    filtrarTabla('buscarPropias', 'tablaPropias', '.fila-propia');
    filtrarTabla('buscarSubdis', 'tablaSubdis', '.fila-subdis');

    function toggleCampoIdSubdis(context) {
        const selectPropiedad = context.querySelector('.propiedad-select');
        const wrap = context.querySelector('.campo-id-subdis');
        if (!selectPropiedad || !wrap) return;

        const val = (selectPropiedad.value || '').toLowerCase();
        const esSubdis = val === 'subdistribuidor' || val === 'subdis';

        wrap.classList.toggle('d-none', !esSubdis);

        const input = wrap.querySelector('input[name="id_subdis"]');
        if (input && !esSubdis) {
            input.value = 0;
        }

        const subtipo = context.querySelector('select[name="subtipo"]');
        if (subtipo) {
            subtipo.value = esSubdis ? 'Subdistribuidor' : 'Propia';
        }
    }

    document.querySelectorAll('#modalAltaSucursal form, #modalEditarSucursal form').forEach(form => {
        const selectPropiedad = form.querySelector('.propiedad-select');
        if (selectPropiedad) {
            selectPropiedad.addEventListener('change', () => toggleCampoIdSubdis(form));
            toggleCampoIdSubdis(form);
        }
    });

    const botonesEditar = document.querySelectorAll('.btnEditarSucursal');
    botonesEditar.forEach(btn => {
        btn.addEventListener('click', function(){
            document.getElementById('edit_id').value = this.dataset.id || '';
            document.getElementById('edit_nombre').value = this.dataset.nombre || '';
            document.getElementById('edit_zona').value = this.dataset.zona || '';
            document.getElementById('edit_propiedad').value = this.dataset.propiedad || 'Luga';
            document.getElementById('edit_id_subdis').value = this.dataset.id_subdis || '0';
            document.getElementById('edit_cuota_semanal').value = this.dataset.cuota_semanal || '0';
            document.getElementById('edit_tipo_sucursal').value = this.dataset.tipo_sucursal || 'Tienda';
            document.getElementById('edit_subtipo').value = this.dataset.subtipo || 'Propia';
            document.getElementById('edit_activo').value = this.dataset.activo || '1';
            document.getElementById('edit_id_gerente').value = this.dataset.id_gerente || '0';
            document.getElementById('edit_direccion').value = this.dataset.direccion || '';
            document.getElementById('edit_ciudad').value = this.dataset.ciudad || '';
            document.getElementById('edit_estado').value = this.dataset.estado || '';
            document.getElementById('edit_codigo_postal').value = this.dataset.codigo_postal || '';
            document.getElementById('edit_telefono').value = this.dataset.telefono || '';
            document.getElementById('edit_referencia').value = this.dataset.referencia || '';

            const form = document.querySelector('#modalEditarSucursal form');
            if (form) toggleCampoIdSubdis(form);
        });
    });
})();
</script>

</body>
</html>