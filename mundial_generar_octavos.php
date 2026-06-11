<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db_fifa.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set('America/Mexico_City');

$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['Admin', 'Administrador', 'Logistica'], true)) {
    die("Acceso no autorizado.");
}

$id_torneo = (int)($_GET['id_torneo'] ?? $_POST['id_torneo'] ?? 0);

if ($id_torneo <= 0) {
    die("Falta id_torneo.");
}

try {
    $connFifa->begin_transaction();

    $stmtT = $connFifa->prepare("
        SELECT id, nombre
        FROM mundial_torneos
        WHERE id = ?
        LIMIT 1
    ");
    $stmtT->bind_param("i", $id_torneo);
    $stmtT->execute();
    $torneo = $stmtT->get_result()->fetch_assoc();

    if (!$torneo) {
        throw new Exception("El torneo no existe.");
    }

    $stmtCheck = $connFifa->prepare("
        SELECT COUNT(*) AS total
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND fase = 'octavos'
    ");
    $stmtCheck->bind_param("i", $id_torneo);
    $stmtCheck->execute();
    $check = $stmtCheck->get_result()->fetch_assoc();

    if ((int)$check['total'] > 0) {
        throw new Exception("Los octavos ya fueron generados para este torneo.");
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

    $porGrupo = [];

    while ($r = $resTabla->fetch_assoc()) {
        $porGrupo[$r['grupo']][] = $r;
    }

    if (empty($porGrupo)) {
        throw new Exception("No hay tabla de posiciones calculada. Primero recalcula las semanas de grupos.");
    }

    $clasificados = [];
    $terceros = [];

    foreach ($porGrupo as $grupo => $rows) {
        foreach ($rows as $idx => $row) {
            $pos = $idx + 1;

            if ($pos <= 2) {
                $row['origen'] = "{$pos}° Grupo {$grupo}";
                $clasificados[] = $row;
            } elseif ($pos === 3) {
                $row['origen'] = "3° Grupo {$grupo}";
                $terceros[] = $row;
            }
        }
    }

    usort($terceros, function ($a, $b) {
        return
            ((int)$b['puntos'] <=> (int)$a['puntos']) ?:
            ((int)$b['goles_favor'] <=> (int)$a['goles_favor']) ?:
            ((float)$b['monto_total'] <=> (float)$a['monto_total']) ?:
            ((int)$b['diferencia_goles'] <=> (int)$a['diferencia_goles']);
    });

    $mejoresTerceros = array_slice($terceros, 0, 2);

    foreach ($mejoresTerceros as $t) {
        $clasificados[] = $t;
    }

    if (count($clasificados) !== 16) {
        throw new Exception("Se esperaban 16 clasificados, pero se obtuvieron " . count($clasificados) . ".");
    }

    usort($clasificados, function ($a, $b) {
        return
            ((int)$b['puntos'] <=> (int)$a['puntos']) ?:
            ((int)$b['goles_favor'] <=> (int)$a['goles_favor']) ?:
            ((float)$b['monto_total'] <=> (float)$a['monto_total']) ?:
            ((int)$b['diferencia_goles'] <=> (int)$a['diferencia_goles']);
    });

    $delClas = $connFifa->prepare("
        DELETE FROM mundial_clasificados
        WHERE id_torneo = ?
          AND fase = 'octavos'
    ");
    $delClas->bind_param("i", $id_torneo);
    $delClas->execute();

    $stmtClas = $connFifa->prepare("
        INSERT INTO mundial_clasificados
        (
            id_torneo,
            id_tienda,
            fase,
            seed,
            origen,
            puntos,
            goles_favor,
            diferencia_goles,
            monto_total
        )
        VALUES (?, ?, 'octavos', ?, ?, ?, ?, ?, ?)
    ");

    foreach ($clasificados as $idx => $c) {
        $seed = $idx + 1;
        $idTienda = (int)$c['id_tienda'];
        $origen = (string)$c['origen'];
        $puntos = (int)$c['puntos'];
        $golesFavor = (int)$c['goles_favor'];
        $diferencia = (int)$c['diferencia_goles'];
        $monto = (float)$c['monto_total'];

        $stmtClas->bind_param(
            "iiisiiid",
            $id_torneo,
            $idTienda,
            $seed,
            $origen,
            $puntos,
            $golesFavor,
            $diferencia,
            $monto
        );
        $stmtClas->execute();
    }

    $cruces = [
        [1, 16],
        [2, 15],
        [3, 14],
        [4, 13],
        [5, 12],
        [6, 11],
        [7, 10],
        [8, 9],
    ];

    $stmtPartido = $connFifa->prepare("
        INSERT INTO mundial_partidos
        (
            id_torneo,
            semana,
            fase,
            grupo,
            id_tienda_local,
            id_tienda_visitante,
            estado
        )
        VALUES (?, 4, 'octavos', NULL, ?, ?, 'pendiente')
    ");

    foreach ($cruces as $cruce) {
        [$seedLocal, $seedVisitante] = $cruce;

        $local = $clasificados[$seedLocal - 1] ?? null;
        $visitante = $clasificados[$seedVisitante - 1] ?? null;

        if (!$local || !$visitante) {
            throw new Exception("No se pudo generar el cruce {$seedLocal} vs {$seedVisitante}.");
        }

        $idLocal = (int)$local['id_tienda'];
        $idVisitante = (int)$visitante['id_tienda'];

        $stmtPartido->bind_param("iii", $id_torneo, $idLocal, $idVisitante);
        $stmtPartido->execute();
    }

    $stmtFase = $connFifa->prepare("
        UPDATE mundial_torneos
        SET fase_actual = 'octavos'
        WHERE id = ?
    ");
    $stmtFase->bind_param("i", $id_torneo);
    $stmtFase->execute();

    $connFifa->commit();

    header("Location: mundial_dashboard.php?id_torneo={$id_torneo}&semana=4");
    exit();

} catch (Throwable $e) {
    $connFifa->rollback();
    die("Error al generar octavos: " . htmlspecialchars($e->getMessage()));
}