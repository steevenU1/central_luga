<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_fifa.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['Admin', 'Administrador', 'Logistica'], true)) {
    die("Acceso no autorizado.");
}

$id_torneo = (int)($_GET['id_torneo'] ?? $_POST['id_torneo'] ?? 0);
$semana = (int)($_GET['semana'] ?? $_POST['semana'] ?? 1);

if ($semana < 1 || $semana > 7) {
    $semana = 1;
}

if ($id_torneo <= 0) {
    $res = $connFifa->query("
        SELECT id 
        FROM mundial_torneos 
        WHERE activo = 1 
        ORDER BY id DESC 
        LIMIT 1
    ");
    if ($row = $res->fetch_assoc()) {
        $id_torneo = (int)$row['id'];
    }
}

$msg = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_snapshots') {
    try {
        $id_torneo = (int)$_POST['id_torneo'];
        $semana = (int)$_POST['semana'];
        $fecha_inicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
        $fecha_fin = $_POST['fecha_fin'] ?? date('Y-m-d');
        $snapshots = $_POST['snapshots'] ?? [];

        $stmt = $connFifa->prepare("
            INSERT INTO mundial_snapshots_ventas
            (
                id_torneo,
                semana,
                empresa,
                id_sucursal_origen,
                clave_global,
                goles,
                monto,
                fecha_inicio,
                fecha_fin
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                goles = VALUES(goles),
                monto = VALUES(monto),
                fecha_inicio = VALUES(fecha_inicio),
                fecha_fin = VALUES(fecha_fin)
        ");

        foreach ($snapshots as $row) {
            $empresa = $row['empresa'] ?? '';
            $id_sucursal_origen = (int)($row['id_sucursal_origen'] ?? 0);
            $clave_global = $row['clave_global'] ?? '';
            $goles = (int)($row['goles'] ?? 0);
            $monto = (float)($row['monto'] ?? 0);

            if ($clave_global === '' || $empresa === '' || $id_sucursal_origen <= 0) {
                continue;
            }

            $stmt->bind_param(
                "iisisiiss",
                $id_torneo,
                $semana,
                $empresa,
                $id_sucursal_origen,
                $clave_global,
                $goles,
                $monto,
                $fecha_inicio,
                $fecha_fin
            );
            $stmt->execute();
        }

        $msg = "Goles de la semana {$semana} guardados correctamente.";
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$torneo = null;
if ($id_torneo > 0) {
    $stmt = $connFifa->prepare("SELECT * FROM mundial_torneos WHERE id = ?");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $torneo = $stmt->get_result()->fetch_assoc();
}

$tiendas = [];
$snapshotsActuales = [];

if ($torneo) {
    $stmt = $connFifa->prepare("
        SELECT *
        FROM mundial_tiendas
        WHERE id_torneo = ?
          AND activa = 1
        ORDER BY grupo, posicion_grupo
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($t = $res->fetch_assoc()) {
        $tiendas[] = $t;
    }

    $stmtS = $connFifa->prepare("
        SELECT *
        FROM mundial_snapshots_ventas
        WHERE id_torneo = ?
          AND semana = ?
    ");
    $stmtS->bind_param("ii", $id_torneo, $semana);
    $stmtS->execute();
    $resS = $stmtS->get_result();

    while ($s = $resS->fetch_assoc()) {
        $snapshotsActuales[$s['clave_global']] = $s;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Captura manual de goles | Mundial Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(13,110,253,.18), transparent 28%),
                linear-gradient(135deg, #06111f 0%, #102a43 42%, #f4f7fb 42%, #f4f7fb 100%);
            min-height: 100vh;
        }

        .zentral-shell {
            max-width: 1350px;
            margin: 30px auto;
            padding: 0 16px;
        }

        .hero {
            background: linear-gradient(135deg, #061a33, #0d6efd);
            color: white;
            border-radius: 26px;
            padding: 28px;
            box-shadow: 0 18px 42px rgba(0,0,0,.24);
            margin-bottom: 24px;
        }

        .hero h1 {
            font-weight: 900;
            margin: 0;
        }

        .card-z {
            border: 0;
            border-radius: 22px;
            box-shadow: 0 14px 34px rgba(15,23,42,.12);
            overflow: hidden;
        }

        .table thead th {
            background: #0f172a;
            color: white;
            white-space: nowrap;
        }

        .form-control, .form-select {
            border-radius: 14px;
        }

        .btn-luga {
            background: linear-gradient(135deg, #0d6efd, #00bcd4);
            border: none;
            color: white;
            font-weight: 800;
            border-radius: 999px;
            padding: 10px 20px;
        }

        .btn-luga:hover {
            color: white;
            filter: brightness(.95);
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
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1><i class="bi bi-dribbble me-2"></i>Captura manual de goles</h1>
                <p class="mb-0 mt-2">Carga temporal de ventas válidas por tienda antes de conectar las APIs.</p>
            </div>
            <div class="text-end">
                <div class="badge bg-light text-dark fs-6">Semana <?= (int)$semana ?></div>
                <div class="small mt-2">1 venta válida = 1 gol</div>
            </div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success rounded-4"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger rounded-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$torneo): ?>
        <div class="alert alert-warning rounded-4">No hay torneo activo.</div>
    <?php else: ?>

        <div class="card card-z mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <input type="hidden" name="id_torneo" value="<?= (int)$id_torneo ?>">

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Semana</label>
                        <select name="semana" class="form-select" onchange="this.form.submit()">
                            <?php for ($s = 1; $s <= 7; $s++): ?>
                                <option value="<?= $s ?>" <?= $s === $semana ? 'selected' : '' ?>>
                                    Semana <?= $s ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="col-md-8 text-md-end">
                        <a href="mundial_grupos.php?id_torneo=<?= (int)$id_torneo ?>" class="btn btn-dark rounded-pill">
                            <i class="bi bi-table me-1"></i> Ver grupos
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="accion" value="guardar_snapshots">
            <input type="hidden" name="id_torneo" value="<?= (int)$id_torneo ?>">
            <input type="hidden" name="semana" value="<?= (int)$semana ?>">

            <div class="card card-z mb-4">
                <div class="card-body">
                    <h5 class="fw-bold mb-3">Periodo de la semana</h5>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Fecha inicio</label>
                            <input type="date" name="fecha_inicio" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Fecha fin</label>
                            <input type="date" name="fecha_fin" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <p class="small-muted mt-3 mb-0">
                        Después de guardar estos goles, ve a Grupos y recalcula la semana correspondiente.
                    </p>
                </div>
            </div>

            <div class="card card-z">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Grupo</th>
                                <th>Pos.</th>
                                <th>Empresa</th>
                                <th>Sucursal</th>
                                <th>Clave</th>
                                <th style="width:160px;">Goles</th>
                                <th style="width:190px;">Monto vendido</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tiendas as $i => $t): 
                                $clave = $t['clave_global'];
                                $snap = $snapshotsActuales[$clave] ?? [];
                            ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($t['grupo']) ?></td>
                                    <td><?= (int)$t['posicion_grupo'] ?></td>
                                    <td>
                                        <span class="badge <?= $t['empresa'] === 'LUGA' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                            <?= htmlspecialchars($t['empresa']) ?>
                                        </span>
                                    </td>
                                    <td class="fw-bold"><?= htmlspecialchars($t['nombre_sucursal']) ?></td>
                                    <td><code><?= htmlspecialchars($clave) ?></code></td>

                                    <td>
                                        <input type="hidden" name="snapshots[<?= $i ?>][empresa]" value="<?= htmlspecialchars($t['empresa']) ?>">
                                        <input type="hidden" name="snapshots[<?= $i ?>][id_sucursal_origen]" value="<?= (int)$t['id_sucursal_origen'] ?>">
                                        <input type="hidden" name="snapshots[<?= $i ?>][clave_global]" value="<?= htmlspecialchars($clave) ?>">

                                        <input type="number"
                                               name="snapshots[<?= $i ?>][goles]"
                                               class="form-control"
                                               min="0"
                                               value="<?= htmlspecialchars($snap['goles'] ?? 0) ?>">
                                    </td>

                                    <td>
                                        <input type="number"
                                               name="snapshots[<?= $i ?>][monto]"
                                               class="form-control"
                                               min="0"
                                               step="0.01"
                                               value="<?= htmlspecialchars($snap['monto'] ?? 0) ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                            <?php if (empty($tiendas)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        Aún no hay tiendas capturadas en grupos.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="small-muted">
                        Captura manual temporal para pruebas. Luego esto vendrá desde APIs Luga/Nano.
                    </div>

                    <button class="btn btn-luga">
                        <i class="bi bi-save2 me-1"></i> Guardar goles semana <?= (int)$semana ?>
                    </button>
                </div>
            </div>
        </form>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>