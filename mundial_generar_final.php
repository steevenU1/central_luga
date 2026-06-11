<?php
// mundial_generar_final.php — Genera final Copa Mundial Zentral

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
    die('Acceso no autorizado.');
}

$id_torneo = (int)($_GET['id_torneo'] ?? $_POST['id_torneo'] ?? 0);

if ($id_torneo <= 0) {
    die('Falta id_torneo.');
}

try {
    $connFifa->begin_transaction();

    $stmt = $connFifa->prepare("
        SELECT id, nombre
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

    $stmt = $connFifa->prepare("
        SELECT COUNT(*) AS total
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND fase = 'final'
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $existe = $stmt->get_result()->fetch_assoc();

    if ((int)$existe['total'] > 0) {
        throw new Exception("La final ya fue generada.");
    }

    $stmt = $connFifa->prepare("
        SELECT *
        FROM mundial_partidos
        WHERE id_torneo = ?
          AND fase = 'semifinal'
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();
    $res = $stmt->get_result();

    $semifinales = [];

    while ($row = $res->fetch_assoc()) {
        $semifinales[] = $row;
    }

    if (count($semifinales) !== 2) {
        throw new Exception("Se esperaban 2 partidos de semifinal.");
    }

    foreach ($semifinales as $idx => $partido) {
        if (empty($partido['ganador_id'])) {
            throw new Exception("El partido de semifinal #" . ($idx + 1) . " aún no tiene ganador.");
        }
    }

    $local = (int)$semifinales[0]['ganador_id'];
    $visitante = (int)$semifinales[1]['ganador_id'];

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
            7,
            'final',
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

    $stmtInsert->bind_param("iii", $id_torneo, $local, $visitante);
    $stmtInsert->execute();

    $stmt = $connFifa->prepare("
        UPDATE mundial_torneos
        SET fase_actual = 'final'
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id_torneo);
    $stmt->execute();

    $connFifa->commit();

    header("Location: mundial_bracket.php?id_torneo=" . $id_torneo);
    exit();

} catch (Throwable $e) {
    $connFifa->rollback();
    die("Error generando final: " . htmlspecialchars($e->getMessage()));
}