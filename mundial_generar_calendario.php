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
    die("No se recibió el torneo.");
}

function redirigir($id_torneo, $msg)
{
    header("Location: mundial_config.php?id_torneo={$id_torneo}&msg=" . urlencode($msg));
    exit();
}

try {
    $connFifa->begin_transaction();

    // Validar torneo
    $stmtT = $connFifa->prepare("
        SELECT id, nombre, fase_actual
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

    // Evitar duplicar calendario
    $stmtExiste = $connFifa->prepare("
        SELECT COUNT(*) AS total
        FROM mundial_partidos
        WHERE id_torneo = ?
    ");
    $stmtExiste->bind_param("i", $id_torneo);
    $stmtExiste->execute();
    $existe = $stmtExiste->get_result()->fetch_assoc();

    if ((int)$existe['total'] > 0) {
        throw new Exception("Este torneo ya tiene calendario generado.");
    }

    // Obtener tiendas agrupadas
    $stmt = $connFifa->prepare("
        SELECT id, grupo, posicion_grupo, nombre_sucursal, empresa
        FROM mundial_tiendas
        WHERE id_torneo = ?
          AND activa = 1
        ORDER BY grupo, posicion_grupo
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $res = $stmt->get_result();

    $grupos = [];

    while ($row = $res->fetch_assoc()) {
        $grupo = strtoupper($row['grupo']);
        $pos = (int)$row['posicion_grupo'];
        $grupos[$grupo][$pos] = $row;
    }

    if (empty($grupos)) {
        throw new Exception("Primero captura las tiendas de los grupos.");
    }

    $insert = $connFifa->prepare("
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
        VALUES (?, ?, 'grupos', ?, ?, ?, 'pendiente')
    ");

    foreach ($grupos as $grupo => $tiendas) {
        ksort($tiendas);
        $total = count($tiendas);

        if ($total === 4) {
            $calendario = [
                1 => [[1, 2], [3, 4]],
                2 => [[1, 3], [2, 4]],
                3 => [[1, 4], [2, 3]],
            ];
        } elseif ($total === 2) {
            $calendario = [
                1 => [[1, 2]],
                2 => [[1, 2]],
                3 => [[1, 2]],
            ];
        } else {
            throw new Exception("El Grupo {$grupo} debe tener 4 tiendas o 2 tiendas. Actualmente tiene {$total}.");
        }

        foreach ($calendario as $semana => $partidos) {
            foreach ($partidos as $duelo) {
                [$posLocal, $posVisitante] = $duelo;

                if (!isset($tiendas[$posLocal], $tiendas[$posVisitante])) {
                    throw new Exception("Faltan posiciones en el Grupo {$grupo}.");
                }

                $idLocal = (int)$tiendas[$posLocal]['id'];
                $idVisitante = (int)$tiendas[$posVisitante]['id'];

                $insert->bind_param(
                    "iisii",
                    $id_torneo,
                    $semana,
                    $grupo,
                    $idLocal,
                    $idVisitante
                );
                $insert->execute();
            }
        }
    }

    // Cambiar fase
    $stmtFase = $connFifa->prepare("
        UPDATE mundial_torneos
        SET fase_actual = 'grupos'
        WHERE id = ?
    ");
    $stmtFase->bind_param("i", $id_torneo);
    $stmtFase->execute();

    $connFifa->commit();

    header("Location: mundial_config.php?id_torneo={$id_torneo}&msg=" . urlencode("Calendario generado correctamente."));
    exit();

} catch (Throwable $e) {
    if ($connFifa->errno) {
        $connFifa->rollback();
    }

    header("Location: mundial_config.php?id_torneo={$id_torneo}&error=" . urlencode($e->getMessage()));
    exit();
}