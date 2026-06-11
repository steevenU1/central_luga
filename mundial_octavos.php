<?php
// mundial_octavos.php — Vista de Octavos Copa Mundial Zentral

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
$clasificados = [];
$partidos = [];

if ($id_torneo > 0) {
    $stmt = $connFifa->prepare("
        SELECT *
        FROM mundial_torneos
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $torneo = $stmt->get_result()->fetch_assoc();

    $stmtC = $connFifa->prepare("
        SELECT
            c.*,
            t.nombre_sucursal,
            t.empresa,
            t.grupo
        FROM mundial_clasificados c
        INNER JOIN mundial_tiendas t ON t.id = c.id_tienda
        WHERE c.id_torneo = ?
          AND c.fase = 'octavos'
        ORDER BY c.seed ASC
    ");
    $stmtC->bind_param("i", $id_torneo);
    $stmtC->execute();
    $resC = $stmtC->get_result();

    while ($c = $resC->fetch_assoc()) {
        $clasificados[(int)$c['seed']] = $c;
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
          AND p.fase = 'octavos'
        ORDER BY p.id ASC
    ");
    $stmtP->bind_param("i", $id_torneo);
    $stmtP->execute();
    $resP = $stmtP->get_result();

    while ($p = $resP->fetch_assoc()) {
        $partidos[] = $p;
    }
}

function badgeEmpresaOctavos($empresa)
{
    $class = $empresa === 'LUGA' ? 'bg-primary' : 'bg-info text-dark';
    return '<span class="badge '.$class.'">'.htmlspecialchars($empresa).'</span>';
}

function estadoBadgeOctavos($estado)
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

function claseEquipoOctavos($golesPropios, $golesRival)
{
    if ((int)$golesPropios > (int)$golesRival) return 'team-winner';
    if ((int)$golesPropios < (int)$golesRival) return 'team-loser';
    return 'team-draw';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Octavos | Copa Mundial Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at 18% 14%, rgba(0,188,212,.20), transparent 30%),
                radial-gradient(circle at 90% 5%, rgba(13,110,253,.22), transparent 34%),
                linear-gradient(135deg, #06111f 0%, #0d1b2a 47%, #eef4fb 47%, #f8fafc 100%);
            min-height: 100vh;
        }

        .mundial-shell {
            max-width: 1450px;
            margin: 28px auto;
            padding: 0 16px 42px;
        }

        .hero {
            position: relative;
            overflow: hidden;
            border-radius: 30px;
            padding: 34px;
            color: white;
            background:
                radial-gradient(circle at 80% 20%, rgba(255,255,255,.19), transparent 24%),
                linear-gradient(135deg, #03162c 0%, #0d6efd 62%, #00bcd4 100%);
            box-shadow: 0 24px 55px rgba(0,0,0,.30);
            margin-bottom: 24px;
        }

        .hero::after {
            content: "🏆";
            position: absolute;
            right: 34px;
            bottom: -42px;
            font-size: 150px;
            opacity: .18;
        }

        .hero h1 {
            font-weight: 950;
            margin: 0;
        }

        .hero-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,.18);
            border: 1px solid rgba(255,255,255,.25);
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

        .section-title {
            font-weight: 950;
            color: #0f172a;
            margin-bottom: 16px;
        }

        .seed-card {
            border-radius: 18px;
            border: 1px solid #e5e7eb;
            background: white;
            padding: 14px;
            height: 100%;
        }

        .seed-number {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: linear-gradient(135deg, #0d6efd, #00bcd4);
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 950;
        }

        .team-name {
            font-weight: 900;
            color: #0f172a;
        }

        .small-muted {
            color: #64748b;
            font-size: .9rem;
        }

        .match-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 24px;
            padding: 18px;
            height: 100%;
            transition: .18s ease;
        }

        .match-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 34px rgba(15,23,42,.12);
        }

        .team-box {
            border-radius: 18px;
            padding: 14px;
            border: 1px solid #e5e7eb;
            background: #f8fafc;
        }

        .team-winner {
            background: rgba(25,135,84,.10);
            border-color: rgba(25,135,84,.25);
        }

        .team-loser {
            background: #f8fafc;
            opacity: .75;
        }

        .team-draw {
            background: rgba(255,193,7,.12);
            border-color: rgba(255,193,7,.28);
        }

        .score-box {
            min-width: 100px;
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

        .table thead th {
            background: #0f172a;
            color: white;
            white-space: nowrap;
        }

        @media (max-width: 768px) {
            .hero {
                padding: 24px;
            }

            .hero::after {
                font-size: 108px;
                right: 10px;
            }

            .score-box {
                min-width: 72px;
                font-size: 1.45rem;
            }
        }
    </style>
</head>

<body>

<div class="mundial-shell">

    <div class="hero">
        <div class="position-relative">
            <div class="hero-chip mb-3">
                <i class="bi bi-trophy-fill"></i>
                Eliminatorias
            </div>

            <h1>Octavos de Final</h1>

            <p class="mb-0 mt-2">
                Los 16 mejores equipos inician el camino directo hacia la copa.
            </p>
        </div>
    </div>

    <?php if (!$torneo): ?>
        <div class="alert alert-warning rounded-4">
            No hay torneo activo.
        </div>
    <?php else: ?>

        <div class="card card-z mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h5 class="fw-bold mb-1"><?= htmlspecialchars($torneo['nombre']) ?></h5>
                    <div class="small-muted">
                        Semana 4 · Octavos de final · 1 vs 16, 2 vs 15, 3 vs 14...
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="mundial_dashboard.php?id_torneo=<?= (int)$id_torneo ?>&semana=4" class="btn btn-outline-dark rounded-pill">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>

                    <a href="mundial_grupos.php?id_torneo=<?= (int)$id_torneo ?>" class="btn btn-outline-primary rounded-pill">
                        <i class="bi bi-table me-1"></i> Grupos
                    </a>

                    <a href="mundial_snapshots_manual.php?id_torneo=<?= (int)$id_torneo ?>&semana=4" class="btn btn-outline-success rounded-pill">
                        <i class="bi bi-dribbble me-1"></i> Capturar goles
                    </a>

                    <a href="mundial_recalcular_semana.php?id_torneo=<?= (int)$id_torneo ?>&semana=4"
                       class="btn btn-luga"
                       onclick="return confirm('¿Recalcular octavos?');">
                        <i class="bi bi-arrow-repeat me-1"></i> Recalcular
                    </a>
                </div>
            </div>
        </div>

        <?php if (empty($clasificados)): ?>
            <div class="alert alert-info rounded-4">
                Aún no se han generado los octavos. Genera los clasificados desde la fase de grupos.
            </div>
        <?php else: ?>

            <h3 class="section-title">
                <i class="bi bi-list-ol me-2 text-primary"></i>
                Tabla de clasificados
            </h3>

            <div class="row g-3 mb-5">
                <?php for ($seed = 1; $seed <= 16; $seed++): 
                    $c = $clasificados[$seed] ?? null;
                ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="seed-card">
                            <?php if ($c): ?>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="seed-number"><?= $seed ?></span>
                                    <div>
                                        <div class="team-name"><?= htmlspecialchars($c['nombre_sucursal']) ?></div>
                                        <div>
                                            <?= badgeEmpresaOctavos($c['empresa']) ?>
                                            <span class="small-muted ms-1"><?= htmlspecialchars($c['origen'] ?? '') ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between mt-3 small-muted">
                                    <span><strong><?= (int)$c['puntos'] ?></strong> pts</span>
                                    <span><strong><?= (int)$c['goles_favor'] ?></strong> goles</span>
                                    <span>$<?= number_format((float)$c['monto_total'], 0) ?></span>
                                </div>
                            <?php else: ?>
                                <div class="text-muted">Seed <?= $seed ?> pendiente</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endfor; ?>
            </div>

            <h3 class="section-title">
                <i class="bi bi-diagram-3-fill me-2 text-warning"></i>
                Cruces de octavos
            </h3>

            <?php if (empty($partidos)): ?>
                <div class="alert alert-warning rounded-4">
                    Hay clasificados, pero todavía no existen partidos de octavos.
                </div>
            <?php else: ?>
                <div class="row g-3">
                    <?php foreach ($partidos as $idx => $p): ?>
                        <div class="col-xl-4 col-md-6">
                            <div class="match-card">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <span class="badge bg-dark">Octavos <?= $idx + 1 ?></span>
                                    <?= estadoBadgeOctavos($p['estado']) ?>
                                </div>

                                <div class="d-flex align-items-center justify-content-between gap-2">
                                    <div class="team-box flex-fill <?= claseEquipoOctavos($p['goles_local'], $p['goles_visitante']) ?>">
                                        <?= badgeEmpresaOctavos($p['local_empresa']) ?>
                                        <div class="team-name mt-1"><?= htmlspecialchars($p['local_nombre']) ?></div>
                                    </div>

                                    <div class="score-box">
                                        <div class="score-label">Goles</div>
                                        <?= (int)$p['goles_local'] ?> - <?= (int)$p['goles_visitante'] ?>
                                    </div>

                                    <div class="team-box flex-fill text-end <?= claseEquipoOctavos($p['goles_visitante'], $p['goles_local']) ?>">
                                        <?= badgeEmpresaOctavos($p['visitante_empresa']) ?>
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

        <?php endif; ?>

    <?php endif; ?>

</div>


</body>
</html>