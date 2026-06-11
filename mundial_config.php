<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_fifa.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['Admin', 'Administrador', 'Logistica'], true)) {
    die("Acceso no autorizado.");
}

$msg = "";
$error = "";

function limpiar($v) {
    return trim((string)$v);
}

/* =========================
   CREAR TORNEO
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'crear_torneo') {
    try {
        $nombre = limpiar($_POST['nombre'] ?? '');
        $fecha_inicio = limpiar($_POST['fecha_inicio'] ?? '');
        $fecha_fin = limpiar($_POST['fecha_fin'] ?? '');

        if ($nombre === '' || $fecha_inicio === '' || $fecha_fin === '') {
            throw new Exception("Completa nombre, fecha inicio y fecha fin.");
        }

        $stmt = $connFifa->prepare("
            INSERT INTO mundial_torneos (nombre, fecha_inicio, fecha_fin, fase_actual, activo)
            VALUES (?, ?, ?, 'configuracion', 1)
        ");
        $stmt->bind_param("sss", $nombre, $fecha_inicio, $fecha_fin);
        $stmt->execute();

        $id_torneo = $stmt->insert_id;

        $stmtParam = $connFifa->prepare("
            INSERT INTO mundial_parametros
            (id_torneo, excluir_combos, excluir_modems, puntos_victoria, puntos_empate, puntos_derrota)
            VALUES (?, 1, 1, 3, 1, 0)
        ");
        $stmtParam->bind_param("i", $id_torneo);
        $stmtParam->execute();

        $msg = "Torneo creado correctamente.";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* =========================
   SELECCIONAR TORNEO
========================= */
$id_torneo = (int)($_GET['id_torneo'] ?? $_POST['id_torneo'] ?? 0);

if ($id_torneo <= 0) {
    $resActivo = $connFifa->query("
        SELECT id FROM mundial_torneos
        WHERE activo = 1
        ORDER BY id DESC
        LIMIT 1
    ");
    if ($rowActivo = $resActivo->fetch_assoc()) {
        $id_torneo = (int)$rowActivo['id'];
    }
}

/* =========================
   GUARDAR GRUPOS
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_grupos') {
    try {
        $id_torneo = (int)($_POST['id_torneo'] ?? 0);
        if ($id_torneo <= 0) {
            throw new Exception("No hay torneo seleccionado.");
        }

        $connFifa->begin_transaction();

        $del = $connFifa->prepare("DELETE FROM mundial_tiendas WHERE id_torneo = ?");
        $del->bind_param("i", $id_torneo);
        $del->execute();

        $grupos = $_POST['grupos'] ?? [];

        $stmt = $connFifa->prepare("
            INSERT INTO mundial_tiendas
            (id_torneo, empresa, id_sucursal_origen, clave_global, nombre_sucursal, grupo, posicion_grupo, activa)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
        ");

        foreach ($grupos as $grupo => $posiciones) {
            $grupo = strtoupper(substr($grupo, 0, 1));

            foreach ($posiciones as $posicion => $data) {
                $empresa = limpiar($data['empresa'] ?? '');
                $id_sucursal_origen = (int)($data['id_sucursal_origen'] ?? 0);
                $nombre_sucursal = limpiar($data['nombre_sucursal'] ?? '');
                $posicion = (int)$posicion;

                if ($empresa === '' && $id_sucursal_origen === 0 && $nombre_sucursal === '') {
                    continue;
                }

                if (!in_array($empresa, ['LUGA', 'NANO'], true)) {
                    throw new Exception("Empresa inválida en Grupo {$grupo}, posición {$posicion}.");
                }

                if ($id_sucursal_origen <= 0 || $nombre_sucursal === '') {
                    throw new Exception("Completa sucursal en Grupo {$grupo}, posición {$posicion}.");
                }

                $clave_global = $empresa . '-' . $id_sucursal_origen;

                $stmt->bind_param(
                    "isisssi",
                    $id_torneo,
                    $empresa,
                    $id_sucursal_origen,
                    $clave_global,
                    $nombre_sucursal,
                    $grupo,
                    $posicion
                );
                $stmt->execute();
            }
        }

        $connFifa->commit();
        $msg = "Grupos guardados correctamente.";
    } catch (Throwable $e) {
        $connFifa->rollback();
        $error = $e->getMessage();
    }
}

/* =========================
   TORNEOS
========================= */
$torneos = [];
$resT = $connFifa->query("
    SELECT * FROM mundial_torneos
    ORDER BY id DESC
");
while ($r = $resT->fetch_assoc()) {
    $torneos[] = $r;
}

/* =========================
   TORNEO ACTUAL
========================= */
$torneoActual = null;
if ($id_torneo > 0) {
    $stmt = $connFifa->prepare("SELECT * FROM mundial_torneos WHERE id = ?");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $torneoActual = $stmt->get_result()->fetch_assoc();
}

/* =========================
   TIENDAS EXISTENTES
========================= */
$tiendasGuardadas = [];
if ($id_torneo > 0) {
    $stmt = $connFifa->prepare("
        SELECT * FROM mundial_tiendas
        WHERE id_torneo = ?
        ORDER BY grupo, posicion_grupo
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($r = $res->fetch_assoc()) {
        $tiendasGuardadas[$r['grupo']][(int)$r['posicion_grupo']] = $r;
    }
}

$gruposConfig = [
    'A' => 4,
    'B' => 4,
    'C' => 4,
    'D' => 4,
    'E' => 4,
    'F' => 4,
    'G' => 2
];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Configuración Mundial Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(0,123,255,.18), transparent 30%),
                linear-gradient(135deg, #07111f 0%, #102a43 45%, #f4f7fb 45%, #f4f7fb 100%);
            min-height: 100vh;
        }

        .zentral-shell {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 16px;
        }

        .hero {
            background: linear-gradient(135deg, #061a33, #0d6efd);
            color: white;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 40px rgba(0,0,0,.25);
            margin-bottom: 22px;
        }

        .hero h1 {
            font-weight: 800;
            margin: 0;
        }

        .card-z {
            border: 0;
            border-radius: 22px;
            box-shadow: 0 14px 34px rgba(15,23,42,.12);
        }

        .grupo-title {
            background: #061a33;
            color: #fff;
            border-radius: 18px 18px 0 0;
            padding: 14px 18px;
            font-weight: 800;
        }

        .pos-badge {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #0d6efd;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
        }

        .btn-luga {
            background: linear-gradient(135deg, #0d6efd, #00bcd4);
            border: none;
            color: white;
            font-weight: 700;
            border-radius: 999px;
            padding: 10px 20px;
        }

        .btn-luga:hover {
            color: white;
            filter: brightness(.95);
        }

        .form-control, .form-select {
            border-radius: 14px;
        }

        .small-muted {
            color: #64748b;
            font-size: .9rem;
        }
    </style>
</head>

<body>

<div class="zentral-shell">

    <div class="hero">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h1><i class="bi bi-trophy-fill me-2"></i>Copa Mundial Zentral</h1>
                <p class="mb-0 mt-2">Configuración de torneo, grupos y tiendas participantes.</p>
            </div>
            <div class="text-end">
                <div class="badge bg-light text-dark fs-6">1 venta válida = 1 gol</div>
                <div class="mt-2 small">Combos y Modems no cuentan</div>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success rounded-4"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-5">
            <div class="card card-z">
                <div class="card-body">
                    <h5 class="fw-bold mb-3"><i class="bi bi-plus-circle me-2"></i>Crear torneo</h5>

                    <form method="POST">
                        <input type="hidden" name="accion" value="crear_torneo">

                        <div class="mb-3">
                            <label class="form-label">Nombre del torneo</label>
                            <input type="text" name="nombre" class="form-control" value="Copa Mundial Zentral 2026" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha inicio</label>
                                <input type="date" name="fecha_inicio" class="form-control" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha fin</label>
                                <input type="date" name="fecha_fin" class="form-control" required>
                            </div>
                        </div>

                        <button class="btn btn-luga w-100">
                            <i class="bi bi-save me-1"></i> Crear torneo
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card card-z">
                <div class="card-body">
                    <h5 class="fw-bold mb-3"><i class="bi bi-list-stars me-2"></i>Torneo activo / seleccionado</h5>

                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-9">
                            <label class="form-label">Seleccionar torneo</label>
                            <select name="id_torneo" class="form-select" onchange="this.form.submit()">
                                <option value="">Selecciona...</option>
                                <?php foreach ($torneos as $t): ?>
                                    <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === $id_torneo ? 'selected' : '') ?>>
                                        <?= htmlspecialchars($t['nombre']) ?> 
                                        (<?= htmlspecialchars($t['fecha_inicio']) ?> al <?= htmlspecialchars($t['fecha_fin']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <button class="btn btn-dark w-100 rounded-pill">Ver</button>
                        </div>
                    </form>

                    <?php if ($torneoActual): ?>
                        <hr>
                        <div class="row text-center">
                            <div class="col-md-4">
                                <div class="fw-bold">Torneo</div>
                                <div class="small-muted"><?= htmlspecialchars($torneoActual['nombre']) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="fw-bold">Fase</div>
                                <div class="small-muted"><?= htmlspecialchars($torneoActual['fase_actual']) ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="fw-bold">Periodo</div>
                                <div class="small-muted">
                                    <?= htmlspecialchars($torneoActual['fecha_inicio']) ?> al <?= htmlspecialchars($torneoActual['fecha_fin']) ?>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <p class="small-muted mt-3 mb-0">Aún no hay torneo creado.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($torneoActual): ?>
        <form method="POST">
            <input type="hidden" name="accion" value="guardar_grupos">
            <input type="hidden" name="id_torneo" value="<?= (int)$id_torneo ?>">

            <div class="card card-z mb-4">
                <div class="card-body">
                    <h4 class="fw-bold mb-1">
                        <i class="bi bi-diagram-3 me-2"></i>Captura de grupos
                    </h4>
                    <p class="small-muted mb-0">
                        Captura manualmente las tiendas que te entreguen por grupo. La posición define el calendario automático.
                    </p>
                </div>
            </div>

            <div class="row g-4">
                <?php foreach ($gruposConfig as $grupo => $totalPosiciones): ?>
                    <div class="col-xl-6">
                        <div class="card card-z">
                            <div class="grupo-title">
                                Grupo <?= htmlspecialchars($grupo) ?>
                                <span class="float-end"><?= (int)$totalPosiciones ?> tiendas</span>
                            </div>

                            <div class="card-body">
                                <?php for ($pos = 1; $pos <= $totalPosiciones; $pos++): 
                                    $data = $tiendasGuardadas[$grupo][$pos] ?? [];
                                ?>
                                    <div class="border rounded-4 p-3 mb-3 bg-light">
                                        <div class="d-flex align-items-center gap-2 mb-3">
                                            <span class="pos-badge"><?= $pos ?></span>
                                            <strong>Posición <?= $pos ?></strong>
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-md-3">
                                                <label class="form-label">Empresa</label>
                                                <select name="grupos[<?= $grupo ?>][<?= $pos ?>][empresa]" class="form-select">
                                                    <option value="">--</option>
                                                    <option value="LUGA" <?= (($data['empresa'] ?? '') === 'LUGA' ? 'selected' : '') ?>>LUGA</option>
                                                    <option value="NANO" <?= (($data['empresa'] ?? '') === 'NANO' ? 'selected' : '') ?>>NANO</option>
                                                </select>
                                            </div>

                                            <div class="col-md-3">
                                                <label class="form-label">ID sucursal</label>
                                                <input type="number"
                                                       name="grupos[<?= $grupo ?>][<?= $pos ?>][id_sucursal_origen]"
                                                       class="form-control"
                                                       value="<?= htmlspecialchars($data['id_sucursal_origen'] ?? '') ?>">
                                            </div>

                                            <div class="col-md-6">
                                                <label class="form-label">Nombre sucursal</label>
                                                <input type="text"
                                                       name="grupos[<?= $grupo ?>][<?= $pos ?>][nombre_sucursal]"
                                                       class="form-control"
                                                       value="<?= htmlspecialchars($data['nombre_sucursal'] ?? '') ?>"
                                                       placeholder="Ej. Yanga, Córdoba, Plaza...">
                                            </div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="sticky-bottom bg-white border rounded-4 shadow p-3 mt-4 mb-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div>
                        <strong>Regla del torneo:</strong>
                        <span class="small-muted">
                            1 venta válida = 1 gol. No cuentan combos ni Modem/MiFi.
                        </span>
                    </div>

                    <button class="btn btn-luga">
                        <i class="bi bi-save2 me-1"></i> Guardar grupos
                    </button>

                    <a href="mundial_generar_calendario.php?id_torneo=<?= (int)$id_torneo ?>"
                    class="btn btn-dark rounded-pill"
                    onclick="return confirm('¿Generar calendario de fase de grupos? Después ya no deberías modificar los grupos.');">
                        <i class="bi bi-calendar-event me-1"></i> Generar calendario
                    </a>
                </div>
            </div>
        </form>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>