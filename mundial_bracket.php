<?php
// mundial_bracket.php — Llave visual Copa Mundial Zentral

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
$partidosPorFase = [
    'octavos' => [],
    'cuartos' => [],
    'semifinal' => [],
    'final' => []
];

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

    $stmtP = $connFifa->prepare("
        SELECT
            p.*,
            tl.nombre_sucursal AS local_nombre,
            tl.empresa AS local_empresa,
            tv.nombre_sucursal AS visitante_nombre,
            tv.empresa AS visitante_empresa,
            tg.nombre_sucursal AS ganador_nombre,
            tg.empresa AS ganador_empresa
        FROM mundial_partidos p
        INNER JOIN mundial_tiendas tl ON tl.id = p.id_tienda_local
        INNER JOIN mundial_tiendas tv ON tv.id = p.id_tienda_visitante
        LEFT JOIN mundial_tiendas tg ON tg.id = p.ganador_id
        WHERE p.id_torneo = ?
          AND p.fase IN ('octavos','cuartos','semifinal','final')
        ORDER BY FIELD(p.fase, 'octavos','cuartos','semifinal','final'), p.id ASC
    ");
    $stmtP->bind_param("i", $id_torneo);
    $stmtP->execute();
    $resP = $stmtP->get_result();

    while ($p = $resP->fetch_assoc()) {
        $partidosPorFase[$p['fase']][] = $p;
    }
}

function badgeEmpresaBracket($empresa)
{
    if (!$empresa) return '';
    $class = $empresa === 'LUGA' ? 'bg-primary' : 'bg-info text-dark';
    return '<span class="badge '.$class.'">'.htmlspecialchars($empresa).'</span>';
}

function teamClassBracket($idEquipo, $idGanador, $estado)
{
    if (!in_array($estado, ['calculado', 'cerrado'], true)) {
        return '';
    }

    if (!$idGanador) {
        return 'team-draw';
    }

    return ((int)$idEquipo === (int)$idGanador) ? 'team-winner' : 'team-loser';
}

function estadoBadgeBracket($estado)
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

function renderMatch($p, $idx)
{
    if (!$p) {
        return '
        <div class="bracket-match placeholder-match">
            <div class="match-top">
                <span class="badge bg-secondary">Pendiente</span>
            </div>
            <div class="team-row empty">Equipo pendiente</div>
            <div class="team-row empty">Equipo pendiente</div>
        </div>';
    }

    $localClass = teamClassBracket($p['id_tienda_local'], $p['ganador_id'], $p['estado']);
    $visitClass = teamClassBracket($p['id_tienda_visitante'], $p['ganador_id'], $p['estado']);

    return '
    <div class="bracket-match">
        <div class="match-top">
            <span class="badge bg-dark">Partido '.((int)$idx + 1).'</span>
            '.estadoBadgeBracket($p['estado']).'
        </div>

        <div class="team-row '.$localClass.'">
            <div>
                <div class="team-name">'.htmlspecialchars($p['local_nombre']).'</div>
                '.badgeEmpresaBracket($p['local_empresa']).'
            </div>
            <div class="score">'.(int)$p['goles_local'].'</div>
        </div>

        <div class="team-row '.$visitClass.'">
            <div>
                <div class="team-name">'.htmlspecialchars($p['visitante_nombre']).'</div>
                '.badgeEmpresaBracket($p['visitante_empresa']).'
            </div>
            <div class="score">'.(int)$p['goles_visitante'].'</div>
        </div>
    </div>';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bracket | Copa Mundial Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            background:
                radial-gradient(circle at 14% 16%, rgba(0,188,212,.20), transparent 30%),
                radial-gradient(circle at 88% 8%, rgba(13,110,253,.24), transparent 34%),
                linear-gradient(135deg, #06111f 0%, #0d1b2a 48%, #eef4fb 48%, #f8fafc 100%);
            min-height: 100vh;
        }

        .mundial-shell {
            max-width: 1600px;
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
            content: "⚽";
            position: absolute;
            right: 32px;
            bottom: -42px;
            font-size: 150px;
            opacity: .16;
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

        .bracket-wrap {
            overflow-x: auto;
            padding-bottom: 12px;
        }

        .bracket {
            display: grid;
            grid-template-columns: 330px 330px 330px 330px 300px;
            gap: 24px;
            min-width: 1450px;
            align-items: stretch;
        }

        .round {
            display: flex;
            flex-direction: column;
            justify-content: space-around;
            gap: 18px;
        }

        .round-title {
            background: #061a33;
            color: white;
            border-radius: 18px;
            padding: 12px 16px;
            font-weight: 950;
            text-align: center;
            margin-bottom: 10px;
            box-shadow: 0 10px 20px rgba(15,23,42,.12);
        }

        .bracket-match {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 22px;
            padding: 14px;
            box-shadow: 0 12px 24px rgba(15,23,42,.08);
            position: relative;
        }

        .bracket-match::after {
            content: "";
            position: absolute;
            right: -24px;
            top: 50%;
            width: 24px;
            height: 2px;
            background: rgba(15,23,42,.22);
        }

        .round.final-round .bracket-match::after,
        .round.champion-round .bracket-match::after {
            display: none;
        }

        .match-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .team-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            border-radius: 16px;
            padding: 10px 12px;
            margin-bottom: 8px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
        }

        .team-row:last-child {
            margin-bottom: 0;
        }

        .team-name {
            font-weight: 900;
            color: #0f172a;
        }

        .score {
            font-weight: 950;
            font-size: 1.45rem;
            color: #0f172a;
        }

        .team-winner {
            background: rgba(25,135,84,.10);
            border-color: rgba(25,135,84,.28);
        }

        .team-loser {
            opacity: .68;
        }

        .team-draw {
            background: rgba(255,193,7,.12);
            border-color: rgba(255,193,7,.30);
        }

        .empty {
            color: #94a3b8;
            font-weight: 700;
        }

        .placeholder-match {
            opacity: .75;
            border-style: dashed;
        }

        .champion-card {
            background:
                radial-gradient(circle at 75% 20%, rgba(255,255,255,.20), transparent 30%),
                linear-gradient(135deg, #f59e0b, #facc15);
            color: #111827;
            border: 0;
            border-radius: 26px;
            padding: 24px;
            box-shadow: 0 18px 40px rgba(245,158,11,.28);
            text-align: center;
        }

        .champion-icon {
            font-size: 54px;
            line-height: 1;
            margin-bottom: 10px;
        }

        .champion-name {
            font-size: 1.4rem;
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

        .small-muted {
            color: #64748b;
            font-size: .9rem;
        }

        @media (max-width: 768px) {
            .hero {
                padding: 24px;
            }

            .hero::after {
                font-size: 110px;
                right: 10px;
            }
        }
    </style>
</head>

<body>

<div class="mundial-shell">

    <div class="hero">
        <div class="position-relative">
            <div class="hero-chip mb-3">
                <i class="bi bi-diagram-3-fill"></i>
                Llave mundialista
            </div>

            <h1>Bracket de Eliminatorias</h1>

            <p class="mb-0 mt-2">
                Octavos, cuartos, semifinal y final rumbo al campeón del mundo mundial.
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
                        Vista visual de eliminatorias. Los cruces avanzan conforme se generen las fases.
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <a href="mundial_dashboard.php?id_torneo=<?= (int)$id_torneo ?>&semana=4" class="btn btn-outline-dark rounded-pill">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>

                    <a href="mundial_octavos.php?id_torneo=<?= (int)$id_torneo ?>" class="btn btn-outline-primary rounded-pill">
                        <i class="bi bi-trophy me-1"></i> Octavos
                    </a>

                    <a href="mundial_grupos.php?id_torneo=<?= (int)$id_torneo ?>" class="btn btn-outline-secondary rounded-pill">
                        <i class="bi bi-table me-1"></i> Grupos
                    </a>

                    <a href="mundial_generar_cuartos.php?id_torneo=<?= $id_torneo ?>"
                    class="btn btn-warning rounded-pill"
                    onclick="return confirm('¿Generar cuartos de final?');">
                        <i class="bi bi-trophy-fill me-1"></i>
                        Generar Cuartos
                    </a>

                    <a href="mundial_generar_semifinal.php?id_torneo=<?= (int)$id_torneo ?>"
                    class="btn btn-warning rounded-pill"
                    onclick="return confirm('¿Generar semifinales?');">
                        <i class="bi bi-trophy-fill me-1"></i> Generar Semifinal
                    </a>

                    <a href="mundial_generar_final.php?id_torneo=<?= (int)$id_torneo ?>"
                    class="btn btn-warning rounded-pill"
                    onclick="return confirm('¿Generar la gran final?');">
                        <i class="bi bi-trophy-fill me-1"></i> Generar Final
                    </a>
                </div>
            </div>
        </div>

        <div class="card card-z">
            <div class="card-body">
                <div class="bracket-wrap">
                    <div class="bracket">

                        <div class="round">
                            <div class="round-title">Octavos</div>
                            <?php for ($i = 0; $i < 8; $i++): ?>
                                <?= renderMatch($partidosPorFase['octavos'][$i] ?? null, $i) ?>
                            <?php endfor; ?>
                        </div>

                        <div class="round">
                            <div class="round-title">Cuartos</div>
                            <?php for ($i = 0; $i < 4; $i++): ?>
                                <?= renderMatch($partidosPorFase['cuartos'][$i] ?? null, $i) ?>
                            <?php endfor; ?>
                        </div>

                        <div class="round">
                            <div class="round-title">Semifinal</div>
                            <?php for ($i = 0; $i < 2; $i++): ?>
                                <?= renderMatch($partidosPorFase['semifinal'][$i] ?? null, $i) ?>
                            <?php endfor; ?>
                        </div>

                        <div class="round final-round">
                            <div class="round-title">Final</div>
                            <?= renderMatch($partidosPorFase['final'][0] ?? null, 0) ?>
                        </div>

                        <div class="round champion-round">
                            <div class="round-title">Campeón</div>

                            <?php 
                            $final = $partidosPorFase['final'][0] ?? null;
                            $campeonNombre = null;
                            $campeonEmpresa = null;

                            if ($final && in_array($final['estado'], ['calculado', 'cerrado'], true) && !empty($final['ganador_id'])) {
                                $campeonNombre = $final['ganador_nombre'];
                                $campeonEmpresa = $final['ganador_empresa'];
                            }
                            ?>

                            <div class="champion-card">
                                <div class="champion-icon">🏆</div>

                                <?php if ($campeonNombre): ?>
                                    <div class="champion-name"><?= htmlspecialchars($campeonNombre) ?></div>
                                    <div class="mt-2"><?= badgeEmpresaBracket($campeonEmpresa) ?></div>
                                    <div class="small mt-3 fw-bold">Campeón Mundial Zentral</div>
                                <?php else: ?>
                                    <div class="champion-name">Pendiente</div>
                                    <div class="small mt-3 fw-bold">La copa espera dueño</div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>
                </div>

                <div class="small-muted mt-3">
                    Los espacios pendientes se llenarán al generar cuartos, semifinal y final.
                </div>
            </div>
        </div>

    <?php endif; ?>

</div>


</body>
</html>