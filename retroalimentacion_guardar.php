<?php
// retroalimentacion_guardar.php — Guarda nueva retroalimentación Zentral

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

function volverConError(string $msg): void {
    header("Location: retroalimentacion_nueva.php?error=" . urlencode($msg));
    exit();
}

function volverConExito(string $msg): void {
    header("Location: retroalimentacion_listado.php?msg=" . urlencode($msg));
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    volverConError('Método inválido.');
}

$idLider    = (int)($_SESSION['id_usuario'] ?? 0);
$rolLider   = trim((string)($_SESSION['rol'] ?? ''));
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);
$idSubdis   = isset($_SESSION['id_subdis']) && $_SESSION['id_subdis'] !== '' ? (int)$_SESSION['id_subdis'] : null;

$rolesPermitidos = ['Admin', 'GerenteZona', 'Gerente'];

if (!in_array($rolLider, $rolesPermitidos, true)) {
    volverConError('No tienes permiso para crear retroalimentaciones.');
}

$idColaborador = (int)($_POST['id_colaborador'] ?? 0);
$periodo = trim((string)($_POST['periodo'] ?? ''));

$queHaceBien = trim((string)($_POST['que_hace_bien'] ?? ''));
$quePuedeMejorar = trim((string)($_POST['que_puede_mejorar'] ?? ''));
$acuerdos = trim((string)($_POST['acuerdos'] ?? ''));

if ($idColaborador <= 0) {
    volverConError('Selecciona un colaborador válido.');
}

if ($queHaceBien === '' || $quePuedeMejorar === '' || $acuerdos === '') {
    volverConError('Completa todos los campos obligatorios.');
}

if (mb_strlen($periodo) > 100) {
    volverConError('El periodo no puede superar 100 caracteres.');
}

if (
    mb_strlen($queHaceBien) > 5000 ||
    mb_strlen($quePuedeMejorar) > 5000 ||
    mb_strlen($acuerdos) > 5000
) {
    volverConError('Uno de los textos supera el límite permitido.');
}

/* Datos del líder */
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.nombre,
        u.rol,
        u.id_sucursal,
        u.id_subdis,
        s.nombre AS sucursal,
        s.zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
      AND u.activo = 1
    LIMIT 1
");
$stmt->bind_param("i", $idLider);
$stmt->execute();
$lider = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lider) {
    volverConError('No se encontró información válida del usuario líder.');
}

$zonaLider = $lider['zona'] ?? null;

/* Datos del colaborador */
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.nombre,
        u.rol,
        u.id_sucursal,
        u.id_subdis,
        s.nombre AS sucursal,
        s.zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
      AND u.activo = 1
    LIMIT 1
");
$stmt->bind_param("i", $idColaborador);
$stmt->execute();
$colaborador = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$colaborador) {
    volverConError('El colaborador seleccionado no existe o está inactivo.');
}

$rolColaborador = trim((string)$colaborador['rol']);
$idSucursalColaborador = (int)$colaborador['id_sucursal'];
$idSubdisColaborador = isset($colaborador['id_subdis']) && $colaborador['id_subdis'] !== '' ? (int)$colaborador['id_subdis'] : null;
$zonaColaborador = $colaborador['zona'] ?? null;

/* Validación de jerarquía real */
$permitido = false;

if ($rolLider === 'Admin') {
    $permitido = ($rolColaborador === 'GerenteZona');
}

if ($rolLider === 'GerenteZona') {
    $permitido = (
        $rolColaborador === 'Gerente' &&
        $zonaLider !== null &&
        $zonaColaborador !== null &&
        $zonaLider === $zonaColaborador
    );
}

if ($rolLider === 'Gerente') {
    $permitido = (
        $rolColaborador === 'Ejecutivo' &&
        $idSucursalColaborador === (int)$lider['id_sucursal']
    );
}

if (!$permitido) {
    volverConError('No tienes permiso para retroalimentar a este colaborador.');
}

/* Folio opcional si existen columnas */
$folio = null;
$anio = (int)date('Y');
$mes = (int)date('n');

function columnaExiste(mysqli $conn, string $tabla, string $columna): bool {
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $tabla, $columna);
    $stmt->execute();
    $existe = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $existe;
}

$tieneFolio = columnaExiste($conn, 'retroalimentaciones', 'folio');
$tieneAnio  = columnaExiste($conn, 'retroalimentaciones', 'anio');
$tieneMes   = columnaExiste($conn, 'retroalimentaciones', 'mes');

try {
    $conn->begin_transaction();

    if ($tieneFolio || $tieneAnio || $tieneMes) {
        $stmt = $conn->prepare("
            INSERT INTO retroalimentaciones (
                id_lider,
                id_colaborador,
                id_sucursal,
                zona,
                id_subdis,
                rol_lider,
                rol_colaborador,
                periodo,
                que_hace_bien,
                que_puede_mejorar,
                acuerdos,
                estatus,
                creado_por,
                anio,
                mes
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?, ?
            )
        ");

        $stmt->bind_param(
            "iiisissssssiii",
            $idLider,
            $idColaborador,
            $idSucursalColaborador,
            $zonaColaborador,
            $idSubdisColaborador,
            $rolLider,
            $rolColaborador,
            $periodo,
            $queHaceBien,
            $quePuedeMejorar,
            $acuerdos,
            $idLider,
            $anio,
            $mes
        );

        $stmt->execute();
        $idRetro = (int)$stmt->insert_id;
        $stmt->close();

        if ($tieneFolio) {
            $folio = 'RETRO-' . date('Y') . '-' . str_pad((string)$idRetro, 6, '0', STR_PAD_LEFT);

            $stmt = $conn->prepare("
                UPDATE retroalimentaciones
                SET folio = ?
                WHERE id = ?
                LIMIT 1
            ");
            $stmt->bind_param("si", $folio, $idRetro);
            $stmt->execute();
            $stmt->close();
        }
    } else {
        $stmt = $conn->prepare("
            INSERT INTO retroalimentaciones (
                id_lider,
                id_colaborador,
                id_sucursal,
                zona,
                id_subdis,
                rol_lider,
                rol_colaborador,
                periodo,
                que_hace_bien,
                que_puede_mejorar,
                acuerdos,
                estatus,
                creado_por
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?
            )
        ");

        $stmt->bind_param(
            "iiisissssssi",
            $idLider,
            $idColaborador,
            $idSucursalColaborador,
            $zonaColaborador,
            $idSubdisColaborador,
            $rolLider,
            $rolColaborador,
            $periodo,
            $queHaceBien,
            $quePuedeMejorar,
            $acuerdos,
            $idLider
        );

        $stmt->execute();
        $idRetro = (int)$stmt->insert_id;
        $stmt->close();
    }

    $conn->commit();

    volverConExito('Retroalimentación guardada correctamente.');
} catch (Throwable $e) {
    $conn->rollback();
    volverConError('No se pudo guardar la retroalimentación: ' . $e->getMessage());
}