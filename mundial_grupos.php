<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_fifa.php';
require_once __DIR__ . '/navbar.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

$id_torneo = (int)($_GET['id_torneo'] ?? 0);

if ($id_torneo <= 0) {
    $resActivo = $connFifa->query("
        SELECT id 
        FROM mundial_torneos 
        WHERE activo = 1 
        ORDER BY id DESC 
        LIMIT 1
    ");
    if ($row = $resActivo->fetch_assoc()) {
        $id_torneo = (int)$row['id'];
    }
}

$torneos = [];
$resT = $connFifa->query("
    SELECT id, nombre, fecha_inicio, fecha_fin, fase_actual
    FROM mundial_torneos
    ORDER BY id DESC
");
while ($r = $resT->fetch_assoc()) {
    $torneos[] = $r;
}

$torneo = null;
if ($id_torneo > 0) {
    $stmt = $connFifa->prepare("
        SELECT *
        FROM mundial_torneos
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $torneo = $stmt->get_result()->fetch_assoc();
}

$tiendas = [];
$tabla = [];
$partidosPorSemana = [];

if ($torneo) {
    $stmtTiendas = $connFifa->prepare("
        SELECT *
        FROM mundial_tiendas
        WHERE id_torneo = ?
          AND activa = 1
        ORDER BY grupo, posicion_grupo
    ");
    $stmtTiendas->bind_param("i", $id_torneo);
    $stmtTiendas->execute();
    $resTiendas = $stmtTiendas->get_result();

    while ($t = $resTiendas->fetch_assoc()) {
        $idTienda = (int)$t['id'];
        $tiendas[$idTienda] = $t;

        $tabla[$t['grupo']][$idTienda] = [
            'id_tienda' => $idTienda,
            'empresa' => $t['empresa'],
            'nombre_sucursal' => $t['nombre_sucursal'],
            'grupo' => $t['grupo'],
            'pj' => 0,
            'pg' => 0,
            'pe' => 0,
            'pp' => 0,
            'gf' => 0,
            'gc' => 0,
            'dg' => 0,
            'monto' => 0,
            'pts' => 0
        ];
    }

    $stmtPartidos = $connFifa->prepare("
        SELECT 
            p.*,
            tl.nombre_sucursal AS local_nombre,
            tl.empresa AS local_empresa,
            tv.nombre_sucursal AS visitante_nombre,
            tv.empresa AS visitante_empresa
        FROM mundial_partidos p
        INNER JOIN mundial_tiendas tl ON tl.id = p.id_tienda_local
        INNER JOIN mundial_tiendas tv ON tv.id = p.id_tienda_visitante
        WHERE p.id_torneo = ?
        ORDER BY p.semana, p.grupo, p.id
    ");
    $stmtPartidos->bind_param("i", $id_torneo);
    $stmtPartidos->execute();
    $resPartidos = $stmtPartidos->get_result();

    while ($p = $resPartidos->fetch_assoc()) {
        $semana = (int)$p['semana'];
        $partidosPorSemana[$semana][] = $p;

        if (in_array($p['estado'], ['calculado', 'cerrado'], true)) {
            $idLocal = (int)$p['id_tienda_local'];
            $idVisitante = (int)$p['id_tienda_visitante'];

            $gl = (int)$p['goles_local'];
            $gv = (int)$p['goles_visitante'];

            $ml = (float)$p['monto_local'];
            $mv = (float)$p['monto_visitante'];

            $grupo = $p['grupo'];

            if (isset($tabla[$grupo][$idLocal], $tabla[$grupo][$idVisitante])) {
                $tabla[$grupo][$idLocal]['pj']++;
                $tabla[$grupo][$idVisitante]['pj']++;

                $tabla[$grupo][$idLocal]['gf'] += $gl;
                $tabla[$grupo][$idLocal]['gc'] += $gv;
                $tabla[$grupo][$idLocal]['monto'] += $ml;

                $tabla[$grupo][$idVisitante]['gf'] += $gv;
                $tabla[$grupo][$idVisitante]['gc'] += $gl;
                $tabla[$grupo][$idVisitante]['monto'] += $mv;

                if ($gl > $gv) {
                    $tabla[$grupo][$idLocal]['pg']++;
                    $tabla[$grupo][$idLocal]['pts'] += 3;
                    $tabla[$grupo][$idVisitante]['pp']++;
                } elseif ($gv > $gl) {
                    $tabla[$grupo][$idVisitante]['pg']++;
                    $tabla[$grupo][$idVisitante]['pts'] += 3;
                    $tabla[$grupo][$idLocal]['pp']++;
                } else {
                    $tabla[$grupo][$idLocal]['pe']++;
                    $tabla[$grupo][$idVisitante]['pe']++;
                    $tabla[$grupo][$idLocal]['pts'] += 1;
                    $tabla[$grupo][$idVisitante]['pts'] += 1;
                }

                $tabla[$grupo][$idLocal]['dg'] = $tabla[$grupo][$idLocal]['gf'] - $tabla[$grupo][$idLocal]['gc'];
                $tabla[$grupo][$idVisitante]['dg'] = $tabla[$grupo][$idVisitante]['gf'] - $tabla[$grupo][$idVisitante]['gc'];
            }
        }
    }

    foreach ($tabla as $grupo => $rows) {
        uasort($rows, function ($a, $b) {
            return
                ($b['pts'] <=> $a['pts']) ?:
                ($b['gf'] <=> $a['gf']) ?:
                ($b['monto'] <=> $a['monto']) ?:
                ($b['dg'] <=> $a['dg']) ?:
                strcmp($a['nombre_sucursal'], $b['nombre_sucursal']);
        });

        $tabla[$grupo] = $rows;
    }
}

function badgeEmpresa($empresa) {
    $class = $empresa === 'LUGA' ? 'bg-primary' : 'bg-info text-dark';
    return '<span class="badge '.$class.'">'.htmlspecialchars($empresa).'</span>';
}

function estadoPartido($estado) {
    switch ($estado) {
        case 'calculado':
            return '<span class="badge bg-success">Calculado</span>';
        case 'cerrado':
            return '<span class="badge bg-dark">Cerrado</span>';
        default:
            return '<span class="badge bg-secondary">Pendiente</span>';
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Mundial Zentral | Grupos</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(13,110,253,.18), transparent 28%),
                radial-gradient(circle at top right, rgba(0,188,212,.16), transparent 30%),
                linear-gradient(135deg, #06111f 0%, #0d1b2a 42%, #f4f7fb 42%, #f4f7fb 100%);
            min-height: 100vh;
        }

        .zentral-shell {
            max-width: 1450px;
            margin: 30px auto;
            padding: 0 16px;
        }

        .hero {
            background: linear-gradient(135deg, #061a33, #0d6efd);
            color: white;
            border-radius: 26px;
            padding: 30px;
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

        .section-title {
            font-weight: 900;
            color: #0f172a;
        }

        .grupo-head {
            background: #061a33;
            color: #fff;
            padding: 14px 18px;
            font-weight: 900;
        }

        .table thead th {
            background: #0f172a;
            color: white;
            border: 0;
            white-space: nowrap;
        }

        .table td {
            vertical-align: middle;
        }

        .clasifica {
            background: rgba(25,135,84,.10);
        }

        .partido-card {
            border: 1px solid #e5e7eb;
            border-radius: 18px;
            padding: 16px;
            background: white;
            height: 100%;
        }

        .score {
            font-size: 1.6rem;
            font-weight: 900;
            color: #0f172a;
            min-width: 90px;
            text-align: center;
        }

        .team-name {
            font-weight: 800;
            color: #0f172a;
        }

        .small-muted {
            color: #64748b;
            font-size: .9rem;
        }

        .nav-pills .nav-link {
            border-radius: 999px;
            font-weight: 700;
            color: #0f172a;
        }

        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #0d6efd, #00bcd4);
        }

        .btn-luga {
            background: linear-gradient(135deg, #0d6efd, #00bcd4);
            border: none;
            color: white;
            font-weight: 800;
            border-radius: 999px;
            padding: 10px 18px;
        }

        .btn-luga:hover {
            color: white;
            filter: brightness(.95);
        }
    </style>
</head>

<body>

<div class="zentral-shell">

    <div class="hero">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1><i class="bi bi-trophy-fill me-2"></i>Copa Mundial Zentral</h1>
                <p class="mb-0 mt-2">Fase de grupos, calendario y tabla de posiciones.</p>
            </div>

            <div class="text-end">
                <div class="badge bg-light text-dark fs-6">1 venta válida = 1 gol</div>
                <div class="small mt-2">Combos y Modems no cuentan</div>
            </div>
        </div>
    </div>

    <div class="card card-z mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-7">
                    <label class="form-label fw-bold">Torneo</label>
                    <select name="id_torneo" class="form-select rounded-pill" onchange="this.form.submit()">
                        <option value="">Selecciona torneo...</option>
                        <?php foreach ($torneos as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === $id_torneo ? 'selected' : '') ?>>
                                <?= htmlspecialchars($t['nombre']) ?> 
                                (<?= htmlspecialchars($t['fecha_inicio']) ?> al <?= htmlspecialchars($t['fecha_fin']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-5 text-lg-end">
                    <?php if ($torneo): ?>
                        <a href="mundial_config.php?id_torneo=<?= (int)$id_torneo ?>" class="btn btn-outline-dark rounded-pill">
                            <i class="bi bi-gear me-1"></i> Configuración
                        </a>

                        <a href="mundial_generar_calendario.php?id_torneo=<?= (int)$id_torneo ?>" 
                           class="btn btn-luga"
                           onclick="return confirm('¿Generar calendario? Solo úsalo si aún no existe calendario para este torneo.');">
                            <i class="bi bi-calendar-event me-1"></i> Generar calendario
                        </a>

                        <div class="mt-3">
                            <div class="d-flex flex-wrap gap-2">
                                <?php for ($s = 1; $s <= 3; $s++): ?>
                                    <a href="mundial_recalcular_semana.php?id_torneo=<?= (int)$id_torneo ?>&semana=<?= $s ?>"
                                    class="btn btn-outline-primary rounded-pill"
                                    onclick="return confirm('¿Recalcular resultados de la semana <?= $s ?>?');">
                                        <i class="bi bi-arrow-repeat me-1"></i> Recalcular Semana <?= $s ?>
                                    </a>
                                <?php endfor; ?>
                            </div>
                        </div>

                        <a href="mundial_snapshots_manual.php?id_torneo=<?= (int)$id_torneo ?>&semana=1"
                        class="btn btn-outline-success rounded-pill">
                            <i class="bi bi-dribbble me-1"></i> Capturar goles
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$torneo): ?>
        <div class="alert alert-warning rounded-4">
            No hay torneo seleccionado o creado todavía.
        </div>
    <?php else: ?>

        <ul class="nav nav-pills mb-4" id="mundialTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="calendario-tab" data-bs-toggle="pill" data-bs-target="#calendario" type="button">
                    <i class="bi bi-calendar-week me-1"></i> Calendario
                </button>
            </li>

            <li class="nav-item" role="presentation">
                <button class="nav-link" id="grupos-tab" data-bs-toggle="pill" data-bs-target="#grupos" type="button">
                    <i class="bi bi-table me-1"></i> Tablas de grupos
                </button>
            </li>
        </ul>

        <div class="tab-content">

            <div class="tab-pane fade show active" id="calendario">
                <h3 class="section-title mb-3">Calendario de fase de grupos</h3>

                <?php if (empty($partidosPorSemana)): ?>
                    <div class="alert alert-info rounded-4">
                        Aún no hay calendario generado. Primero guarda los grupos y genera el calendario.
                    </div>
                <?php else: ?>
                    <?php foreach ($partidosPorSemana as $semana => $partidos): ?>
                        <div class="card card-z mb-4">
                            <div class="grupo-head">
                                <i class="bi bi-calendar2-event me-2"></i>Semana <?= (int)$semana ?>
                            </div>

                            <div class="card-body">
                                <div class="row g-3">
                                    <?php foreach ($partidos as $p): ?>
                                        <div class="col-xl-4 col-md-6">
                                            <div class="partido-card">
                                                <div class="d-flex justify-content-between align-items-center mb-3">
                                                    <span class="badge bg-dark">Grupo <?= htmlspecialchars($p['grupo']) ?></span>
                                                    <?= estadoPartido($p['estado']) ?>
                                                </div>

                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <div class="text-start flex-fill">
                                                        <div><?= badgeEmpresa($p['local_empresa']) ?></div>
                                                        <div class="team-name mt-1"><?= htmlspecialchars($p['local_nombre']) ?></div>
                                                    </div>

                                                    <div class="score">
                                                        <?= (int)$p['goles_local'] ?> - <?= (int)$p['goles_visitante'] ?>
                                                    </div>

                                                    <div class="text-end flex-fill">
                                                        <div><?= badgeEmpresa($p['visitante_empresa']) ?></div>
                                                        <div class="team-name mt-1"><?= htmlspecialchars($p['visitante_nombre']) ?></div>
                                                    </div>
                                                </div>

                                                <hr>

                                                <div class="d-flex justify-content-between small-muted">
                                                    <span>Monto local: $<?= number_format((float)$p['monto_local'], 2) ?></span>
                                                    <span>Monto visitante: $<?= number_format((float)$p['monto_visitante'], 2) ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="tab-pane fade" id="grupos">
                <h3 class="section-title mb-3">Tabla de posiciones</h3>

                <?php if (empty($tabla)): ?>
                    <div class="alert alert-info rounded-4">
                        Aún no hay tiendas capturadas.
                    </div>
                <?php else: ?>
                    <div class="row g-4">
                        <?php foreach ($tabla as $grupo => $rows): ?>
                            <div class="col-xl-6">
                                <div class="card card-z">
                                    <div class="grupo-head">
                                        Grupo <?= htmlspecialchars($grupo) ?>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th>#</th>
                                                    <th>Tienda</th>
                                                    <th>Empresa</th>
                                                    <th>PJ</th>
                                                    <th>G</th>
                                                    <th>E</th>
                                                    <th>P</th>
                                                    <th>GF</th>
                                                    <th>GC</th>
                                                    <th>DG</th>
                                                    <th>Monto</th>
                                                    <th>PTS</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $pos = 1;
                                                foreach ($rows as $r): 
                                                    $class = $pos <= 2 ? 'clasifica' : '';
                                                ?>
                                                    <tr class="<?= $class ?>">
                                                        <td class="fw-bold"><?= $pos ?></td>
                                                        <td class="fw-bold"><?= htmlspecialchars($r['nombre_sucursal']) ?></td>
                                                        <td><?= badgeEmpresa($r['empresa']) ?></td>
                                                        <td><?= (int)$r['pj'] ?></td>
                                                        <td><?= (int)$r['pg'] ?></td>
                                                        <td><?= (int)$r['pe'] ?></td>
                                                        <td><?= (int)$r['pp'] ?></td>
                                                        <td><?= (int)$r['gf'] ?></td>
                                                        <td><?= (int)$r['gc'] ?></td>
                                                        <td><?= (int)$r['dg'] ?></td>
                                                        <td>$<?= number_format((float)$r['monto'], 2) ?></td>
                                                        <td class="fw-bold"><?= (int)$r['pts'] ?></td>
                                                    </tr>
                                                <?php 
                                                    $pos++;
                                                endforeach; 
                                                ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="card-body small-muted">
                                        Las primeras 2 tiendas clasifican directo a octavos. Los mejores terceros se definirán en la tabla general.
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div>

    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>