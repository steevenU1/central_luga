<?php
// ajax_cliente_referidos_check.php
// Revisa si un cliente ya tiene referidos registrados

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode([
        'ok' => false,
        'message' => 'Sesión no válida.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/db.php';

function responder($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$id_cliente = isset($_POST['id_cliente']) ? (int)$_POST['id_cliente'] : 0;

if ($id_cliente <= 0) {
    responder([
        'ok' => false,
        'message' => 'Cliente no válido.',
        'total' => 0,
        'referidos' => []
    ]);
}

try {
    // Validar que exista el cliente
    $stmtCliente = $conn->prepare("
        SELECT id, nombre, telefono
        FROM clientes
        WHERE id = ?
        LIMIT 1
    ");
    $stmtCliente->bind_param("i", $id_cliente);
    $stmtCliente->execute();
    $cliente = $stmtCliente->get_result()->fetch_assoc();
    $stmtCliente->close();

    if (!$cliente) {
        responder([
            'ok' => false,
            'message' => 'No se encontró el cliente.',
            'total' => 0,
            'referidos' => []
        ]);
    }

    // Si la tabla todavía no existe, responder sin romper la venta
    $tablaExiste = false;
    $check = $conn->query("SHOW TABLES LIKE 'clientes_referidos'");
    if ($check && $check->num_rows > 0) {
        $tablaExiste = true;
    }

    if (!$tablaExiste) {
        responder([
            'ok' => true,
            'message' => 'Este cliente aún no tiene referidos registrados.',
            'total' => 0,
            'cliente' => [
                'id' => (int)$cliente['id'],
                'nombre' => $cliente['nombre'] ?? '',
                'telefono' => $cliente['telefono'] ?? ''
            ],
            'referidos' => []
        ]);
    }

    // Total de referidos
    $stmtTotal = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM clientes_referidos
        WHERE id_cliente = ?
    ");
    $stmtTotal->bind_param("i", $id_cliente);
    $stmtTotal->execute();
    $totalRow = $stmtTotal->get_result()->fetch_assoc();
    $stmtTotal->close();

    $total = (int)($totalRow['total'] ?? 0);

    // Últimos referidos para mostrarlos en el modal
    $referidos = [];

    $stmtRefs = $conn->prepare("
        SELECT 
            nombre_referido,
            telefono_referido,
            estatus,
            fecha_registro
        FROM clientes_referidos
        WHERE id_cliente = ?
        ORDER BY id DESC
        LIMIT 5
    ");
    $stmtRefs->bind_param("i", $id_cliente);
    $stmtRefs->execute();
    $resRefs = $stmtRefs->get_result();

    while ($row = $resRefs->fetch_assoc()) {
        $referidos[] = [
            'nombre' => $row['nombre_referido'] ?? '',
            'telefono' => $row['telefono_referido'] ?? '',
            'estatus' => $row['estatus'] ?? '',
            'fecha' => $row['fecha_registro'] ?? ''
        ];
    }

    $stmtRefs->close();

    $message = $total > 0
        ? "Este cliente ya tiene {$total} referido(s) registrado(s), pero puede agregar más."
        : "Este cliente aún no tiene referidos registrados. Solicítale dos referidos antes de finalizar.";

    responder([
        'ok' => true,
        'message' => $message,
        'total' => $total,
        'cliente' => [
            'id' => (int)$cliente['id'],
            'nombre' => $cliente['nombre'] ?? '',
            'telefono' => $cliente['telefono'] ?? ''
        ],
        'referidos' => $referidos
    ]);

} catch (Throwable $e) {
    responder([
        'ok' => false,
        'message' => 'No se pudo consultar la información de referidos.',
        'debug' => $e->getMessage(),
        'total' => 0,
        'referidos' => []
    ]);
}