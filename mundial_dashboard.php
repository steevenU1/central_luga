<?php
// mundial_dashboard.php — Dashboard visual Copa Mundial Zentral

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

$torneo = null;
$torneos = [];
$partidosSemana = [];
$tabla = [];
$mejoresTerceros = [];
$semanaActual = (int)($_GET['semana'] ?? 1);

$resT = $connFifa->query("
    SELECT id, nombre, fecha_inicio, fecha_fin, fase_actual
    FROM mundial_torneos
    ORDER BY id DESC
");
while ($r = $resT->fetch_assoc()) {
    $torneos[] = $r;
}

if ($id_torneo > 0) {
    $stmt = $connFifa->prepare("SELECT * FROM mundial_torneos WHERE id = ?");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $torneo = $stmt->get_result()->fetch_assoc();
}

if ($torneo) {
    $stmtSem = $connFifa->prepare("
        SELECT COALESCE(MAX(semana), 1) AS semana
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND estado IN ('calculado','cerrado')
    ");
    $stmtSem->bind_param("i", $id_torneo);
    $stmtSem->execute();
    $rowSem = $stmtSem->get_result()->fetch_assoc();

    if (!isset($_GET['semana'])) {
        $semanaActual = max(1, (int)$rowSem['semana']);
    }

    $stmtP = $connFifa->prepare("
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
          AND p.semana = ?
        ORDER BY p.grupo, p.id
    ");
    $stmtP->bind_param("ii", $id_torneo, $semanaActual);
    $stmtP->execute();
    $resP = $stmtP->get_result();

    while ($p = $resP->fetch_assoc()) {
        $partidosSemana[] = $p;
    }

    $stmtTabla = $connFifa->prepare("
        SELECT 
            tp.*,
            t.nombre_sucursal,
            t.empresa
        FROM mundial_tabla_posiciones tp
        INNER JOIN mundial_tiendas t ON t.id = tp.id_tienda
        WHERE tp.id_torneo = ?
        ORDER BY 
            tp.grupo ASC,
            tp.puntos DESC,
            tp.goles_favor DESC,
            tp.monto_total DESC,
            tp.diferencia_goles DESC
    ");
    $stmtTabla->bind_param("i", $id_torneo);
    $stmtTabla->execute();
    $resTabla = $stmtTabla->get_result();

    while ($r = $resTabla->fetch_assoc()) {
        $tabla[$r['grupo']][] = $r;
    }

    foreach ($tabla as $grupo => $rows) {
        foreach ($rows as $idx => $r) {
            if ($idx === 2) {
                $mejoresTerceros[] = $r;
            }
        }
    }

    usort($mejoresTerceros, function ($a, $b) {
        return
            ((int)$b['puntos'] <=> (int)$a['puntos']) ?:
            ((int)$b['goles_favor'] <=> (int)$a['goles_favor']) ?:
            ((float)$b['monto_total'] <=> (float)$a['monto_total']) ?:
            ((int)$b['diferencia_goles'] <=> (int)$a['diferencia_goles']);
    });
}

function badgeEmpresaMundial($empresa)
{
    $class = $empresa === 'LUGA' ? 'bg-primary' : 'bg-info text-dark';
    return '<span class="badge '.$class.'">'.htmlspecialchars($empresa).'</span>';
}

function estadoBadgeMundial($estado)
{
    switch ($estado) {
        case 'calculado':
            return '<span class="badge bg-success">Calculado</span>';
        case 'cerrado':
            return '<span class="badge bg-dark">Cerrado</span>';
        default:
            return '<span class="badge bg-secondary">Pendiente</span>';
    }
}

function ganadorClass($golesA, $golesB)
{
    if ((int)$golesA > (int)$golesB) return 'winner';
    if ((int)$golesA < (int)$golesB) return 'loser';
    return 'draw';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Copa Mundial Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at 15% 15%, rgba(0,188,212,.22), transparent 28%),
                radial-gradient(circle at 85% 5%, rgba(13,110,253,.20), transparent 32%),
                linear-gradient(135deg, #06111f 0%, #0d1b2a 48%, #eef4fb 48%, #f8fafc 100%);
            min-height: 100vh;
        }

        .mundial-shell {
            max-width: 1500px;
            margin: 28px auto;
            padding: 0 16px 40px;
        }

        .hero-mundial {
            position: relative;
            overflow: hidden;
            border-radius: 30px;
            padding: 34px;
            color: white;
            background:
                radial-gradient(circle at 80% 20%, rgba(255,255,255,.20), transparent 24%),
                linear-gradient(135deg, #03162c 0%, #0d6efd 62%, #00bcd4 100%);
            box-shadow: 0 24px 55px rgba(0,0,0,.30);
            margin-bottom: 24px;
        }

        .hero-mundial::after {
            content: "⚽";
            position: absolute;
            right: 34px;
            bottom: -38px;
            font-size: 150px;
            opacity: .16;
        }

        .hero-mundial h1 {
            font-weight: 950;
            margin: 0;
            letter-spacing: -.8px;
        }

        .hero-subtitle {
            opacity: .92;
            margin-top: 8px;
            max-width: 720px;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.25);
            backdrop-filter: blur(8px);
            color: white;
            border-radius: 999px;
            padding: 8px 14px;
            font-weight: 800;
        }

        .card-z {
            border: 0;
            border-radius: 24px;
            box-shadow: 0 16px 36px rgba(15,23,42,.13);
            overflow: hidden;
        }

        .metric-card {
            border: 0;
            border-radius: 22px;
            background: white;
            box-shadow: 0 12px 28px rgba(15,23,42,.10);
            padding: 20px;
            height: 100%;
        }

        .metric-icon {
            width: 46px;
            height: 46px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0d6efd, #00bcd4);
            color: white;
            font-size: 22px;
            margin-bottom: 10px;
        }

        .metric-value {
            font-size: 2rem;
            font-weight: 950;
            color: #0f172a;
            line-height: 1;
        }

        .metric-label {
            color: #64748b;
            font-weight: 700;
            margin-top: 6px;
        }

        .section-title {
            font-weight: 950;
            color: #0f172a;
            margin-bottom: 16px;
        }

        .match-card {
            border-radius: 22px;
            border: 1px solid #e5e7eb;
            background: white;
            padding: 18px;
            height: 100%;
            transition: .18s ease;
        }

        .match-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 34px rgba(15,23,42,.12);
        }

        .team {
            border-radius: 18px;
            padding: 12px;
        }

        .winner {
            background: rgba(25,135,84,.10);
            border: 1px solid rgba(25,135,84,.25);
        }

        .loser {
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            opacity: .80;
        }

        .draw {
            background: rgba(255,193,7,.12);
            border: 1px solid rgba(255,193,7,.28);
        }

        .team-name {
            font-weight: 900;
            color: #0f172a;
        }

        .score-box {
            min-width: 98px;
            text-align: center;
            font-size: 2rem;
            font-weight: 950;
            color: #0f172a;
        }

        .score-label {
            font-size: .75rem;
            font-weight: 800;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .06em;
        }

        .group-head {
            background: #061a33;
            color: white;
            padding: 14px 18px;
            font-weight: 950;
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

        .tercero-ok {
            background: rgba(13,110,253,.10);
        }

        .mini-rank {
            border-radius: 18px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            padding: 12px 14px;
            margin-bottom: 10px;
        }

        .rank-number {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #0d6efd;
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 950;
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

        .form-select {
            border-radius: 999px;
        }

        .small-muted {
            color: #64748b;
            font-size: .9rem;
        }

        @media (max-width: 768px) {
            .hero-mundial {
                padding: 24px;
            }

            .hero-mundial::after {
                font-size: 110px;
                right: 10px;
            }

            .score-box {
                min-width: 70px;
                font-size: 1.45rem;
            }
        }
    </style>
</head>

<body>

<div class="mundial-shell">

    <div class="hero-mundial">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 position-relative">
            <div>
                <div class="hero-chip mb-3">
                    <i class="bi bi-trophy-fill"></i>
                    Mundial de Ventas
                </div>

                <h1>Copa Mundial Zentral</h1>

                <p class="hero-subtitle mb-0">
                    Cada venta válida es un gol. Las tiendas compiten por puntos, clasificación y gloria comercial.
                </p>
            </div>

            <div class="text-md-end">
                <div class="hero-chip mb-2">
                    <i class="bi bi-calendar-week"></i>
                    Semana <?= (int)$semanaActual ?>
                </div>
                <div class="small">
                    <?= $torneo ? htmlspecialchars($torneo['fecha_inicio']) . ' al ' . htmlspecialchars($torneo['fecha_fin']) : 'Sin torneo activo' ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-z mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label fw-bold">Torneo</label>
                    <select name="id_torneo" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($torneos as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= ((int)$t['id'] === $id_torneo ? 'selected' : '') ?>>
                                <?= htmlspecialchars($t['nombre']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-lg-3">
                    <label class="form-label fw-bold">Semana</label>
                    <select name="semana" class="form-select" onchange="this.form.submit()">
                        <?php for ($s = 1; $s <= 7; $s++): ?>
                            <option value="<?= $s ?>" <?= $s === $semanaActual ? 'selected' : '' ?>>
                                Semana <?= $s ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div class="col-lg-4 text-lg-end">
                    <a href="mundial_grupos.php?id_torneo=<?= (int)$id_torneo ?>" class="btn btn-outline-dark rounded-pill">
                        <i class="bi bi-table me-1"></i> Grupos
                    </a>
                    <a href="mundial_snapshots_manual.php?id_torneo=<?= (int)$id_torneo ?>&semana=<?= (int)$semanaActual ?>" class="btn btn-outline-success rounded-pill">
                        <i class="bi bi-dribbble me-1"></i> Capturar goles
                    </a>
                    <a href="mundial_recalcular_semana.php?id_torneo=<?= (int)$id_torneo ?>&semana=<?= (int)$semanaActual ?>"
                       class="btn btn-luga"
                       onclick="return confirm('¿Recalcular semana <?= (int)$semanaActual ?>?');">
                        <i class="bi bi-arrow-repeat me-1"></i> Recalcular
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$torneo): ?>
        <div class="alert alert-warning rounded-4">
            No hay torneo activo todavía.
        </div>
    <?php else: ?>

        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-icon"><i class="bi bi-controller"></i></div>
                    <div class="metric-value"><?= count($partidosSemana) ?></div>
                    <div class="metric-label">Partidos esta semana</div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-icon"><i class="bi bi-dribbble"></i></div>
                    <div class="metric-value">
                        <?php
                        $totalGolesSemana = 0;
                        foreach ($partidosSemana as $p) {
                            $totalGolesSemana += (int)$p['goles_local'] + (int)$p['goles_visitante'];
                        }
                        echo number_format($totalGolesSemana);
                        ?>
                    </div>
                    <div class="metric-label">Goles de la semana</div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-icon"><i class="bi bi-diagram-3"></i></div>
                    <div class="metric-value"><?= count($tabla) ?></div>
                    <div class="metric-label">Grupos activos</div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="metric-card">
                    <div class="metric-icon"><i class="bi bi-fire"></i></div>
                    <div class="metric-value"><?= count($mejoresTerceros) >= 2 ? 2 : count($mejoresTerceros) ?></div>
                    <div class="metric-label">Mejores terceros</div>
                </div>
            </div>
        </div>

        <h3 class="section-title">
            <i class="bi bi-lightning-charge-fill text-warning me-2"></i>
            Partidos de la Semana <?= (int)$semanaActual ?>
        </h3>

        <?php if (empty($partidosSemana)): ?>
            <div class="alert alert-info rounded-4">
                Aún no hay partidos para esta semana. Genera el calendario desde configuración.
            </div>
        <?php else: ?>
            <div class="row g-3 mb-5">
                <?php foreach ($partidosSemana as $p): ?>
                    <div class="col-xl-4 col-md-6">
                        <div class="match-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="badge bg-dark">Grupo <?= htmlspecialchars($p['grupo'] ?? '-') ?></span>
                                <?= estadoBadgeMundial($p['estado']) ?>
                            </div>

                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <div class="team flex-fill <?= ganadorClass($p['goles_local'], $p['goles_visitante']) ?>">
                                    <?= badgeEmpresaMundial($p['local_empresa']) ?>
                                    <div class="team-name mt-1"><?= htmlspecialchars($p['local_nombre']) ?></div>
                                </div>

                                <div class="score-box">
                                    <div class="score-label">Goles</div>
                                    <?= (int)$p['goles_local'] ?> - <?= (int)$p['goles_visitante'] ?>
                                </div>

                                <div class="team flex-fill text-end <?= ganadorClass($p['goles_visitante'], $p['goles_local']) ?>">
                                    <?= badgeEmpresaMundial($p['visitante_empresa']) ?>
                                    <div class="team-name mt-1"><?= htmlspecialchars($p['visitante_nombre']) ?></div>
                                </div>
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between small-muted">
                                <span>$<?= number_format((float)$p['monto_local'], 2) ?></span>
                                <span>$<?= number_format((float)$p['monto_visitante'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-xl-9">
                <h3 class="section-title">
                    <i class="bi bi-table me-2 text-primary"></i>
                    Tabla de grupos
                </h3>

                <div class="row g-4">
                    <?php foreach ($tabla as $grupo => $rows): ?>
                        <div class="col-xl-6">
                            <div class="card card-z">
                                <div class="group-head">
                                    Grupo <?= htmlspecialchars($grupo) ?>
                                </div>

                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Tienda</th>
                                                <th>PTS</th>
                                                <th>GF</th>
                                                <th>DG</th>
                                                <th>Monto</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($rows as $idx => $r): 
                                                $pos = $idx + 1;
                                                $class = $pos <= 2 ? 'clasifica' : ($pos === 3 ? 'tercero-ok' : '');
                                            ?>
                                                <tr class="<?= $class ?>">
                                                    <td class="fw-bold"><?= $pos ?></td>
                                                    <td>
                                                        <div class="fw-bold"><?= htmlspecialchars($r['nombre_sucursal']) ?></div>
                                                        <?= badgeEmpresaMundial($r['empresa']) ?>
                                                    </td>
                                                    <td class="fw-bold"><?= (int)$r['puntos'] ?></td>
                                                    <td><?= (int)$r['goles_favor'] ?></td>
                                                    <td><?= (int)$r['diferencia_goles'] ?></td>
                                                    <td>$<?= number_format((float)$r['monto_total'], 2) ?></td>
                                                </tr>
                                            <?php endforeach; ?>

                                            <?php if (empty($rows)): ?>
                                                <tr>
                                                    <td colspan="6" class="text-center text-muted py-4">Sin datos.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="card-body small-muted">
                                    Verde: clasifica directo. Azul: pelea por mejor tercero.
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <?php if (empty($tabla)): ?>
                        <div class="col-12">
                            <div class="alert alert-info rounded-4">
                                Aún no hay tabla calculada. Captura goles y recalcula la semana.
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-xl-3">
                <h3 class="section-title">
                    <i class="bi bi-stars text-warning me-2"></i>
                    Mejores terceros
                </h3>

                <div class="card card-z">
                    <div class="card-body">
                        <?php if (empty($mejoresTerceros)): ?>
                            <div class="small-muted">
                                Aún no hay terceros lugares calculados.
                            </div>
                        <?php else: ?>
                            <?php foreach ($mejoresTerceros as $idx => $r): ?>
                                <div class="mini-rank <?= $idx < 2 ? 'tercero-ok' : '' ?>">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="rank-number"><?= $idx + 1 ?></span>
                                        <div>
                                            <div class="fw-bold"><?= htmlspecialchars($r['nombre_sucursal']) ?></div>
                                            <div>
                                                <?= badgeEmpresaMundial($r['empresa']) ?>
                                                <span class="small-muted ms-1">Grupo <?= htmlspecialchars($r['grupo']) ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="d-flex justify-content-between mt-2 small">
                                        <span><strong><?= (int)$r['puntos'] ?></strong> pts</span>
                                        <span><strong><?= (int)$r['goles_favor'] ?></strong> goles</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="small-muted mt-2">
                                Los primeros 2 mejores terceros clasifican a octavos.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card card-z mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold">
                            <i class="bi bi-info-circle me-1"></i>Regla oficial
                        </h5>
                        <p class="small-muted mb-0">
                            1 venta válida equivale a 1 gol. No cuentan equipos marcados como combo ni Modem/MiFi.
                            Victoria: 3 pts. Empate: 1 pt. Derrota: 0 pts.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>


</body>
</html>