<?php
// retroalimentacion_firmar.php — Firma digital de retroalimentación Zentral

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

function volverDetalle(int $id, string $tipo, string $msg): void {
    header("Location: retroalimentacion_detalle.php?id=" . $id . "&" . $tipo . "=" . urlencode($msg));
    exit();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: retroalimentacion_listado.php?error=" . urlencode("Método inválido."));
    exit();
}

$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);
$idRetro   = (int)($_POST['id'] ?? 0);
$password  = (string)($_POST['password'] ?? '');
$acepto    = (int)($_POST['acepto'] ?? 0);

if ($idRetro <= 0) {
    header("Location: retroalimentacion_listado.php?error=" . urlencode("Retroalimentación inválida."));
    exit();
}

if ($acepto !== 1) {
    volverDetalle($idRetro, 'error', 'Debes aceptar la confirmación para firmar.');
}

if (trim($password) === '') {
    volverDetalle($idRetro, 'error', 'Ingresa tu contraseña para firmar.');
}

/* Obtener usuario */
$stmt = $conn->prepare("
    SELECT id, nombre, password, activo
    FROM usuarios
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$usuario = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$usuario || (int)$usuario['activo'] !== 1) {
    volverDetalle($idRetro, 'error', 'Usuario inválido o inactivo.');
}

/* Validar contraseña */
$hashGuardado = (string)($usuario['password'] ?? '');

$passwordOk = false;

if ($hashGuardado !== '') {
    if (password_verify($password, $hashGuardado)) {
        $passwordOk = true;
    } elseif (hash_equals($hashGuardado, $password)) {
        // Compatibilidad por si algún usuario viejo estuviera guardado sin hash.
        $passwordOk = true;
    }
}

if (!$passwordOk) {
    volverDetalle($idRetro, 'error', 'La contraseña no coincide. No se pudo firmar.');
}

/* Obtener retroalimentación */
$stmt = $conn->prepare("
    SELECT id, id_colaborador, estatus
    FROM retroalimentaciones
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idRetro);
$stmt->execute();
$retro = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$retro) {
    header("Location: retroalimentacion_listado.php?error=" . urlencode("No se encontró la retroalimentación."));
    exit();
}

if ((int)$retro['id_colaborador'] !== $idUsuario) {
    volverDetalle($idRetro, 'error', 'Solo el colaborador asignado puede firmar esta retroalimentación.');
}

if (strtoupper((string)$retro['estatus']) !== 'PENDIENTE') {
    volverDetalle($idRetro, 'error', 'Esta retroalimentación ya no está pendiente de firma.');
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

try {
    $stmt = $conn->prepare("
        UPDATE retroalimentaciones
        SET 
            estatus = 'FIRMADA',
            fecha_firma = NOW(),
            id_usuario_firma = ?,
            ip_firma = ?,
            user_agent_firma = ?
        WHERE id = ?
          AND id_colaborador = ?
          AND estatus = 'PENDIENTE'
        LIMIT 1
    ");
    $stmt->bind_param(
        "issii",
        $idUsuario,
        $ip,
        $userAgent,
        $idRetro,
        $idUsuario
    );
    $stmt->execute();

    if ($stmt->affected_rows <= 0) {
        $stmt->close();
        volverDetalle($idRetro, 'error', 'No se pudo firmar. Puede que el documento ya haya sido firmado.');
    }

    $stmt->close();

    volverDetalle($idRetro, 'msg', 'Retroalimentación firmada correctamente.');
} catch (Throwable $e) {
    volverDetalle($idRetro, 'error', 'Error al firmar: ' . $e->getMessage());
}