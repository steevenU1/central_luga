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
$semana    = (int)($_GET['semana'] ?? $_POST['semana'] ?? 0);

if ($id_torneo <= 0 || $semana <= 0) {
    die("Falta torneo o semana.");
}

try {
    $connFifa->begin_transaction();

    $stmt = $connFifa->prepare("
        SELECT 
            p.id,
            p.id_tienda_local,
            p.id_tienda_visitante,
            tl.clave_global AS clave_local,
            tv.clave_global AS clave_visitante
        FROM mundial_partidos p
        INNER JOIN mundial_tiendas tl ON tl.id = p.id_tienda_local
        INNER JOIN mundial_tiendas tv ON tv.id = p.id_tienda_visitante
        WHERE p.id_torneo = ?
          AND p.semana = ?
          AND p.fase = 'grupos'
    ");
    $stmt->bind_param("ii", $id_torneo, $semana);
    $stmt->execute();
    $partidos = $stmt->get_result();

    if ($partidos->num_rows === 0) {
        throw new Exception("No hay partidos para esta semana.");
    }

    $stmtSnap = $connFifa->prepare("
        SELECT goles, monto
        FROM mundial_snapshots_ventas
        WHERE id_torneo = ?
          AND semana = ?
          AND clave_global = ?
        LIMIT 1
    ");

    $stmtUpd = $connFifa->prepare("
        UPDATE mundial_partidos
        SET goles_local = ?,
            goles_visitante = ?,
            monto_local = ?,
            monto_visitante = ?,
            ganador_id = ?,
            estado = 'calculado'
        WHERE id = ?
    ");

    while ($p = $partidos->fetch_assoc()) {
        $claveLocal = $p['clave_local'];
        $claveVisitante = $p['clave_visitante'];

        $golesLocal = 0;
        $montoLocal = 0.00;
        $golesVisitante = 0;
        $montoVisitante = 0.00;

        $stmtSnap->bind_param("iis", $id_torneo, $semana, $claveLocal);
        $stmtSnap->execute();
        $snapLocal = $stmtSnap->get_result()->fetch_assoc();

        if ($snapLocal) {
            $golesLocal = (int)$snapLocal['goles'];
            $montoLocal = (float)$snapLocal['monto'];
        }

        $stmtSnap->bind_param("iis", $id_torneo, $semana, $claveVisitante);
        $stmtSnap->execute();
        $snapVisitante = $stmtSnap->get_result()->fetch_assoc();

        if ($snapVisitante) {
            $golesVisitante = (int)$snapVisitante['goles'];
            $montoVisitante = (float)$snapVisitante['monto'];
        }

        $ganadorId = null;

        if ($golesLocal > $golesVisitante) {
            $ganadorId = (int)$p['id_tienda_local'];
        } elseif ($golesVisitante > $golesLocal) {
            $ganadorId = (int)$p['id_tienda_visitante'];
        } else {
            $ganadorId = null;
        }

        $idPartido = (int)$p['id'];

        $stmtUpd->bind_param(
            "iiddii",
            $golesLocal,
            $golesVisitante,
            $montoLocal,
            $montoVisitante,
            $ganadorId,
            $idPartido
        );
        $stmtUpd->execute();
    }

    reconstruirTablaPosiciones($connFifa, $id_torneo);

    $connFifa->commit();

    header("Location: mundial_grupos.php?id_torneo={$id_torneo}&msg=" . urlencode("Semana {$semana} recalculada correctamente."));
    exit();

} catch (Throwable $e) {
    $connFifa->rollback();
    header("Location: mundial_grupos.php?id_torneo={$id_torneo}&error=" . urlencode($e->getMessage()));
    exit();
}

function reconstruirTablaPosiciones(mysqli $connFifa, int $id_torneo): void
{
    $stmtDel = $connFifa->prepare("
        DELETE FROM mundial_tabla_posiciones
        WHERE id_torneo = ?
    ");
    $stmtDel->bind_param("i", $id_torneo);
    $stmtDel->execute();

    $stmtTiendas = $connFifa->prepare("
        SELECT id, grupo
        FROM mundial_tiendas
        WHERE id_torneo = ?
          AND activa = 1
    ");
    $stmtTiendas->bind_param("i", $id_torneo);
    $stmtTiendas->execute();
    $resTiendas = $stmtTiendas->get_result();

    $tabla = [];

    while ($t = $resTiendas->fetch_assoc()) {
        $id = (int)$t['id'];
        $tabla[$id] = [
            'grupo' => $t['grupo'],
            'pj' => 0,
            'pg' => 0,
            'pe' => 0,
            'pp' => 0,
            'gf' => 0,
            'gc' => 0,
            'dg' => 0,
            'monto' => 0.00,
            'pts' => 0,
        ];
    }

    $stmtPartidos = $connFifa->prepare("
        SELECT *
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND fase = 'grupos'
          AND estado IN ('calculado','cerrado')
    ");
    $stmtPartidos->bind_param("i", $id_torneo);
    $stmtPartidos->execute();
    $resPartidos = $stmtPartidos->get_result();

    while ($p = $resPartidos->fetch_assoc()) {
        $local = (int)$p['id_tienda_local'];
        $visitante = (int)$p['id_tienda_visitante'];

        if (!isset($tabla[$local], $tabla[$visitante])) {
            continue;
        }

        $gl = (int)$p['goles_local'];
        $gv = (int)$p['goles_visitante'];
        $ml = (float)$p['monto_local'];
        $mv = (float)$p['monto_visitante'];

        $tabla[$local]['pj']++;
        $tabla[$visitante]['pj']++;

        $tabla[$local]['gf'] += $gl;
        $tabla[$local]['gc'] += $gv;
        $tabla[$local]['monto'] += $ml;

        $tabla[$visitante]['gf'] += $gv;
        $tabla[$visitante]['gc'] += $gl;
        $tabla[$visitante]['monto'] += $mv;

        if ($gl > $gv) {
            $tabla[$local]['pg']++;
            $tabla[$local]['pts'] += 3;
            $tabla[$visitante]['pp']++;
        } elseif ($gv > $gl) {
            $tabla[$visitante]['pg']++;
            $tabla[$visitante]['pts'] += 3;
            $tabla[$local]['pp']++;
        } else {
            $tabla[$local]['pe']++;
            $tabla[$visitante]['pe']++;
            $tabla[$local]['pts'] += 1;
            $tabla[$visitante]['pts'] += 1;
        }

        $tabla[$local]['dg'] = $tabla[$local]['gf'] - $tabla[$local]['gc'];
        $tabla[$visitante]['dg'] = $tabla[$visitante]['gf'] - $tabla[$visitante]['gc'];
    }

    $porGrupo = [];

    foreach ($tabla as $idTienda => $r) {
        $porGrupo[$r['grupo']][$idTienda] = $r;
    }

    $stmtIns = $connFifa->prepare("
        INSERT INTO mundial_tabla_posiciones
        (
            id_torneo,
            id_tienda,
            grupo,
            partidos_jugados,
            ganados,
            empatados,
            perdidos,
            goles_favor,
            goles_contra,
            diferencia_goles,
            monto_total,
            puntos,
            posicion_grupo,
            clasificado,
            mejor_tercero
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
    ");

    foreach ($porGrupo as $grupo => $rows) {
        uasort($rows, function ($a, $b) {
            return
                ($b['pts'] <=> $a['pts']) ?:
                ($b['gf'] <=> $a['gf']) ?:
                ($b['monto'] <=> $a['monto']) ?:
                ($b['dg'] <=> $a['dg']);
        });

        $pos = 1;

        foreach ($rows as $idTienda => $r) {
            $stmtIns->bind_param(
                "iisiiiiiiidii",
                $id_torneo,
                $idTienda,
                $grupo,
                $r['pj'],
                $r['pg'],
                $r['pe'],
                $r['pp'],
                $r['gf'],
                $r['gc'],
                $r['dg'],
                $r['monto'],
                $r['pts'],
                $pos
            );
            $stmtIns->execute();

            $pos++;
        }
    }
}