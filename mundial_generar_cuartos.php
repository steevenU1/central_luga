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
    die('Acceso no autorizado');
}

$id_torneo = (int)($_GET['id_torneo'] ?? $_POST['id_torneo'] ?? 0);

if ($id_torneo <= 0) {
    die('Falta id_torneo');
}

try {

    $connFifa->begin_transaction();

    /*
    |--------------------------------------------------------------------------
    | Validar torneo
    |--------------------------------------------------------------------------
    */

    $stmt = $connFifa->prepare("
        SELECT *
        FROM mundial_torneos
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();

    $torneo = $stmt->get_result()->fetch_assoc();

    if (!$torneo) {
        throw new Exception("Torneo no encontrado.");
    }

    /*
    |--------------------------------------------------------------------------
    | Verificar si ya existen cuartos
    |--------------------------------------------------------------------------
    */

    $stmt = $connFifa->prepare("
        SELECT COUNT(*) total
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND fase = 'cuartos'
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();

    if ((int)$row['total'] > 0) {
        throw new Exception("Los cuartos ya fueron generados.");
    }

    /*
    |--------------------------------------------------------------------------
    | Obtener octavos
    |--------------------------------------------------------------------------
    */

    $stmt = $connFifa->prepare("
        SELECT *
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND fase = 'octavos'
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();

    $res = $stmt->get_result();

    $octavos = [];

    while ($row = $res->fetch_assoc()) {
        $octavos[] = $row;
    }

    if (count($octavos) !== 8) {
        throw new Exception("Se esperaban 8 partidos de octavos.");
    }

    /*
    |--------------------------------------------------------------------------
    | Validar ganadores
    |--------------------------------------------------------------------------
    */

    foreach ($octavos as $idx => $partido) {

        if (empty($partido['ganador_id'])) {

            throw new Exception(
                "El partido de octavos #" .
                ($idx + 1) .
                " aún no tiene ganador."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cruces de cuartos
    |--------------------------------------------------------------------------
    */

    $cruces = [
        [
            $octavos[0]['ganador_id'],
            $octavos[1]['ganador_id']
        ],
        [
            $octavos[2]['ganador_id'],
            $octavos[3]['ganador_id']
        ],
        [
            $octavos[4]['ganador_id'],
            $octavos[5]['ganador_id']
        ],
        [
            $octavos[6]['ganador_id'],
            $octavos[7]['ganador_id']
        ]
    ];

    $stmtInsert = $connFifa->prepare("
        INSERT INTO mundial_partidos
        (
            id_torneo,
            semana,
            fase,
            grupo,
            id_tienda_local,
            id_tienda_visitante,
            goles_local,
            goles_visitante,
            monto_local,
            monto_visitante,
            ganador_id,
            estado
        )
        VALUES
        (
            ?,
            5,
            'cuartos',
            NULL,
            ?,
            ?,
            0,
            0,
            0,
            0,
            NULL,
            'pendiente'
        )
    ");

    foreach ($cruces as $cruce) {

        $local = (int)$cruce[0];
        $visitante = (int)$cruce[1];

        $stmtInsert->bind_param(
            "iii",
            $id_torneo,
            $local,
            $visitante
        );

        $stmtInsert->execute();
    }

    /*
    |--------------------------------------------------------------------------
    | Actualizar fase torneo
    |--------------------------------------------------------------------------
    */

    $stmt = $connFifa->prepare("
        UPDATE mundial_torneos
        SET fase_actual = 'cuartos'
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();

    $connFifa->commit();

    header(
        "Location: mundial_bracket.php?id_torneo=" .
        $id_torneo
    );
    exit();

} catch (Throwable $e) {

    $connFifa->rollback();

    die(
        "Error generando cuartos: " .
        htmlspecialchars($e->getMessage())
    );
}