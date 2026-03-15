<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ID_USUARIO = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
$NOMBRE_USUARIO = (string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario');

$ROLES_PERMITIDOS = [
    'Admin', 'Administrador', 'Logistica',
    'Gerente', 'Ejecutivo',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para entregar garantías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function null_if_empty($v): ?string {
    $v = trim((string)$v);
    return $v === '' ? null : $v;
}

function table_exists(mysqli $conn, string $table): bool {
    $sql = "SELECT 1
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
            LIMIT 1";
    $st = $conn->prepare($sql);
    $st->bind_param("s", $table);
    $st->execute();
    $ok = ($st->get_result()->num_rows > 0);
    $st->close();
    return $ok;
}

function fmt_datetime(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i', $ts) : '-';
}

function fmt_date(?string $dt): string {
    if (!$dt || $dt === '0000-00-00' || $dt === '0000-00-00 00:00:00') return '-';
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y', $ts) : '-';
}

function badge_estado(string $estado): string {
    $map = [
        'capturada'              => 'secondary',
        'recepcion_registrada'   => 'info',
        'en_revision_logistica'  => 'warning text-dark',
        'garantia_autorizada'    => 'success',
        'garantia_rechazada'     => 'danger',
        'enviada_diagnostico'    => 'primary',
        'cotizacion_disponible'  => 'info',
        'cotizacion_aceptada'    => 'success',
        'cotizacion_rechazada'   => 'danger',
        'en_reparacion'          => 'warning text-dark',
        'reparado'               => 'success',
        'reemplazo_capturado'    => 'primary',
        'entregado'              => 'success',
        'cerrado'                => 'dark',
        'cancelado'              => 'dark',
    ];
    $cls = $map[$estado] ?? 'secondary';
    return '<span class="badge rounded-pill text-bg-' . $cls . '">' . h($estado) . '</span>';
}

function puede_operar_caso(array $caso, string $rol, int $idUsuario, int $idSucursal): bool {
    if (in_array($rol, ['Admin', 'Administrador', 'Logistica'], true)) {
        return true;
    }
    if (in_array($rol, ['Gerente', 'Subdis_Gerente'], true)) {
        return ((int)($caso['id_sucursal'] ?? 0) === $idSucursal);
    }
    if (in_array($rol, ['Ejecutivo', 'Subdis_Ejecutivo'], true)) {
        return ((int)($caso['id_usuario_captura'] ?? 0) === $idUsuario);
    }
    if ($rol === 'Subdis_Admin') {
        return true;
    }
    return false;
}

function registrar_evento(
    mysqli $conn,
    int $idGarantia,
    string $tipoEvento,
    ?string $estadoAnterior,
    ?string $estadoNuevo,
    ?string $descripcion,
    ?array $datosJson,
    ?int $idUsuario,
    ?string $nombreUsuario,
    ?string $rolUsuario
): void {
    $datos = $datosJson ? json_encode($datosJson, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

    $sql = "INSERT INTO garantias_eventos
            (id_garantia, tipo_evento, estado_anterior, estado_nuevo, descripcion, datos_json, id_usuario, nombre_usuario, rol_usuario, fecha_evento)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $st = $conn->prepare($sql);
    if (!$st) {
        throw new Exception("Error en prepare() de garantias_eventos: " . $conn->error);
    }

    $st->bind_param(
        "isssssiss",
        $idGarantia,
        $tipoEvento,
        $estadoAnterior,
        $estadoNuevo,
        $descripcion,
        $datos,
        $idUsuario,
        $nombreUsuario,
        $rolUsuario
    );

    if (!$st->execute()) {
        throw new Exception("Error al insertar evento: " . $st->error);
    }

    $st->close();
}

/* =========================================================
   VALIDAR TABLAS
========================================================= */
$required = ['garantias_casos', 'garantias_eventos'];
foreach ($required as $tb) {
    if (!table_exists($conn, $tb)) {
        exit("No existe la tabla requerida: " . h($tb));
    }
}

/* =========================================================
   ID DEL CASO
========================================================= */
$idGarantia = (int)($_GET['id'] ?? $_POST['id_garantia'] ?? 0);
if ($idGarantia <= 0) {
    exit('ID de garantía inválido.');
}

/* =========================================================
   CARGAR CASO
========================================================= */
$sqlCaso = "SELECT
                gc.*,
                s.nombre AS sucursal_nombre,
                u.nombre AS capturista_nombre
            FROM garantias_casos gc
            LEFT JOIN sucursales s ON s.id = gc.id_sucursal
            LEFT JOIN usuarios u ON u.id = gc.id_usuario_captura
            WHERE gc.id = ?
            LIMIT 1";
$st = $conn->prepare($sqlCaso);
if (!$st) {
    exit("Error consultando caso: " . h($conn->error));
}
$st->bind_param("i", $idGarantia);
$st->execute();
$caso = $st->get_result()->fetch_assoc();
$st->close();

if (!$caso) {
    exit('No se encontró el caso.');
}

if (!puede_operar_caso($caso, $ROL, $ID_USUARIO, $ID_SUCURSAL)) {
    http_response_code(403);
    exit('No tienes permiso para operar este caso.');
}

/* =========================================================
   VALIDAR ESTADO
========================================================= */
$estadosPermitidos = ['reemplazo_capturado', 'reparado', 'garantia_autorizada'];
if (!in_array((string)$caso['estado'], $estadosPermitidos, true)) {
    exit('Este caso no está listo para entrega. Estado actual: ' . h($caso['estado']));
}

/* =========================================================
   CARGAR REEMPLAZO
========================================================= */
$reemplazo = null;
if (table_exists($conn, 'garantias_reemplazos')) {
    $sqlR = "SELECT *
             FROM garantias_reemplazos
             WHERE id_garantia = ?
             ORDER BY id DESC
             LIMIT 1";
    $st = $conn->prepare($sqlR);
    if ($st) {
        $st->bind_param("i", $idGarantia);
        $st->execute();
        $reemplazo = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

/* =========================================================
   CARGAR REPARACION
========================================================= */
$reparacion = null;
if (table_exists($conn, 'garantias_reparaciones')) {
    $sqlRep = "SELECT *
               FROM garantias_reparaciones
               WHERE id_garantia = ?
               ORDER BY id DESC
               LIMIT 1";
    $st = $conn->prepare($sqlRep);
    if ($st) {
        $st->bind_param("i", $idGarantia);
        $st->execute();
        $reparacion = $st->get_result()->fetch_assoc();
        $st->close();
    }
}

/* =========================================================
   GUARDAR ENTREGA
========================================================= */
$error = null;
$ok = isset($_GET['ok']) ? (int)$_GET['ok'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_entrega'])) {
    $fechaEntrega = null_if_empty($_POST['fecha_entrega'] ?? date('Y-m-d\TH:i'));
    $nombreRecibe = null_if_empty($_POST['nombre_recibe'] ?? null);
    $comentariosEntrega = null_if_empty($_POST['comentarios_entrega'] ?? null);
    $cerrarAlEntregar = isset($_POST['cerrar_al_entregar']) ? 1 : 0;

    if (!$fechaEntrega) {
        $error = 'Debes capturar la fecha y hora de entrega.';
    }

    if (!$error) {
        $conn->begin_transaction();

        try {
            $estadoAnterior = (string)$caso['estado'];
            $estadoNuevo = $cerrarAlEntregar ? 'cerrado' : 'entregado';

            // actualizar caso
            $sqlUpCaso = "UPDATE garantias_casos
                          SET estado = ?,
                              fecha_entrega = ?,
                              " . ($cerrarAlEntregar ? "fecha_cierre = ?, " : "") . "
                              observaciones_cierre = ?,
                              updated_at = NOW()
                          WHERE id = ?";

            if ($cerrarAlEntregar) {
                $fechaCierre = $fechaEntrega;
                $st = $conn->prepare($sqlUpCaso);
                if (!$st) {
                    throw new Exception("Error en update de caso: " . $conn->error);
                }
                $st->bind_param("ssssi", $estadoNuevo, $fechaEntrega, $fechaCierre, $comentariosEntrega, $idGarantia);
            } else {
                $st = $conn->prepare($sqlUpCaso);
                if (!$st) {
                    throw new Exception("Error en update de caso: " . $conn->error);
                }
                $st->bind_param("sssi", $estadoNuevo, $fechaEntrega, $comentariosEntrega, $idGarantia);
            }

            if (!$st->execute()) {
                throw new Exception("Error al actualizar la entrega del caso: " . $st->error);
            }
            $st->close();

            // si hay reemplazo, actualizar fecha_entrega ahí también
            if ($reemplazo) {
                $sqlUpRep = "UPDATE garantias_reemplazos
                             SET fecha_entrega = ?
                             WHERE id = ?";
                $st = $conn->prepare($sqlUpRep);
                if (!$st) {
                    throw new Exception("Error en update de reemplazo: " . $conn->error);
                }
                $idReemplazo = (int)$reemplazo['id'];
                $st->bind_param("si", $fechaEntrega, $idReemplazo);
                if (!$st->execute()) {
                    throw new Exception("Error al actualizar fecha de entrega del reemplazo: " . $st->error);
                }
                $st->close();
            }

            // evento de entrega
            registrar_evento(
                $conn,
                $idGarantia,
                'entrega_cliente',
                $estadoAnterior,
                $estadoNuevo,
                'Se confirmó la entrega del equipo al cliente.',
                [
                    'fecha_entrega' => $fechaEntrega,
                    'nombre_recibe' => $nombreRecibe,
                    'comentarios_entrega' => $comentariosEntrega,
                    'cerrado_automaticamente' => $cerrarAlEntregar,
                    'tipo_entrega' => $reemplazo ? 'reemplazo' : (($reparacion && ($caso['estado'] === 'reparado')) ? 'reparacion' : 'garantia')
                ],
                $ID_USUARIO,
                $NOMBRE_USUARIO,
                $ROL
            );

            if ($cerrarAlEntregar) {
                registrar_evento(
                    $conn,
                    $idGarantia,
                    'cierre',
                    'entregado',
                    'cerrado',
                    'El caso se cerró al momento de la entrega.',
                    [
                        'fecha_cierre' => $fechaEntrega,
                        'comentarios_cierre' => $comentariosEntrega
                    ],
                    $ID_USUARIO,
                    $NOMBRE_USUARIO,
                    $ROL
                );
            }

            $conn->commit();
            header("Location: garantias_detalle.php?id={$idGarantia}&oklog=1");
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

/* =========================================================
   DATOS DE LA ENTREGA
========================================================= */
$tipoEntrega = 'garantia';
if ($reemplazo) {
    $tipoEntrega = 'reemplazo';
} elseif ($reparacion && (string)$caso['estado'] === 'reparado') {
    $tipoEntrega = 'reparacion';
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Entregar garantía | <?= h($caso['folio']) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }
        .page-wrap{
            max-width: 1200px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }
        .hero{
            border-radius:22px;
            border:1px solid #e8edf3;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(25,135,84,.08));
            box-shadow:0 10px 28px rgba(17,24,39,.06);
        }
        .soft-card{
            border:1px solid #e8edf3;
            border-radius:20px;
            box-shadow:0 8px 24px rgba(17,24,39,.05);
            background:#fff;
        }
        .section-title{
            font-size:1rem;
            font-weight:700;
            margin-bottom:.9rem;
            display:flex;
            align-items:center;
            gap:.55rem;
        }
        .section-title i{
            color:#0d6efd;
        }
        .kv-label{
            font-size:.82rem;
            color:#6b7280;
            font-weight:600;
            margin-bottom:.2rem;
        }
        .kv-value{
            font-size:.95rem;
            font-weight:500;
            word-break:break-word;
        }
    </style>
</head>
<body>

<div class="page-wrap">

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($ok === 1): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <i class="bi bi-check-circle me-1"></i> Entrega registrada correctamente.
        </div>
    <?php endif; ?>

    <div class="hero p-4 p-md-5 mb-4">
        <div class="d-flex flex-column flex-xl-row justify-content-between align-items-xl-center gap-3">
            <div>
                <h3 class="fw-bold mb-1">
                    <i class="bi bi-box2-check me-2"></i>Confirmar entrega al cliente
                </h3>
                <div class="text-muted">
                    Folio <strong><?= h($caso['folio']) ?></strong> • Cliente <strong><?= h($caso['cliente_nombre']) ?></strong>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <span class="badge rounded-pill text-bg-light border">Tipo: <?= h($tipoEntrega) ?></span>
                <?= badge_estado((string)$caso['estado']) ?>
                <a href="garantias_detalle.php?id=<?= (int)$caso['id'] ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left me-1"></i>Volver al detalle
                </a>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <div class="col-lg-6">
            <div class="soft-card p-4 mb-4">
                <div class="section-title">
                    <i class="bi bi-person-vcard"></i>
                    <span>Resumen del caso</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="kv-label">Cliente</div>
                        <div class="kv-value"><?= h($caso['cliente_nombre']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Teléfono</div>
                        <div class="kv-value"><?= h($caso['cliente_telefono']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Sucursal</div>
                        <div class="kv-value"><?= h($caso['sucursal_nombre']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">Capturó</div>
                        <div class="kv-value"><?= h($caso['capturista_nombre']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="kv-label">Equipo original</div>
                        <div class="kv-value">
                            <?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="kv-label">IMEI original</div>
                        <div class="kv-value"><?= h($caso['imei_original']) ?></div>
                    </div>
                </div>
            </div>

            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-box-seam"></i>
                    <span>Equipo a entregar</span>
                </div>

                <?php if ($reemplazo): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="kv-label">Equipo reemplazo</div>
                            <div class="kv-value">
                                <?= h(trim(($reemplazo['marca_reemplazo'] ?? '') . ' ' . ($reemplazo['modelo_reemplazo'] ?? ''))) ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">Color / Capacidad</div>
                            <div class="kv-value">
                                <?= h($reemplazo['color_reemplazo']) ?><?= !empty($reemplazo['capacidad_reemplazo']) ? ' • ' . h($reemplazo['capacidad_reemplazo']) : '' ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="kv-label">IMEI 1 reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['imei_reemplazo']) ?></div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI 2 reemplazo</div>
                            <div class="kv-value"><?= h($reemplazo['imei2_reemplazo']) ?: '-' ?></div>
                        </div>
                    </div>
                <?php elseif ($tipoEntrega === 'reparacion'): ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="kv-label">Equipo reparado</div>
                            <div class="kv-value">
                                <?= h(trim(($caso['marca'] ?? '') . ' ' . ($caso['modelo'] ?? ''))) ?>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="kv-label">IMEI</div>
                            <div class="kv-value"><?= h($caso['imei_original']) ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-muted">
                        El caso no tiene reemplazo capturado. Se usará la entrega del caso según el estado actual.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="soft-card p-4">
                <div class="section-title">
                    <i class="bi bi-check2-square"></i>
                    <span>Confirmación de entrega</span>
                </div>

                <form method="post">
                    <input type="hidden" name="id_garantia" value="<?= (int)$caso['id'] ?>">
                    <input type="hidden" name="confirmar_entrega" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Fecha y hora de entrega</label>
                        <input
                            type="datetime-local"
                            name="fecha_entrega"
                            class="form-control"
                            value="<?= date('Y-m-d\TH:i') ?>"
                            required
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Nombre de quien recibe</label>
                        <input
                            type="text"
                            name="nombre_recibe"
                            class="form-control"
                            placeholder="Cliente o persona autorizada"
                            value="<?= h($caso['cliente_nombre']) ?>"
                        >
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Comentarios de entrega</label>
                        <textarea
                            name="comentarios_entrega"
                            class="form-control"
                            rows="4"
                            placeholder="Observaciones de la entrega, conformidad del cliente, notas del equipo, etc."
                        ></textarea>
                    </div>

                    <div class="form-check mb-3">
                        <input
                            class="form-check-input"
                            type="checkbox"
                            name="cerrar_al_entregar"
                            id="cerrar_al_entregar"
                            value="1"
                            checked
                        >
                        <label class="form-check-label" for="cerrar_al_entregar">
                            Cerrar el caso al momento de entregar
                        </label>
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="bi bi-check2-circle me-1"></i>Confirmar entrega
                        </button>

                        <a href="garantias_detalle.php?id=<?= (int)$caso['id'] ?>" class="btn btn-outline-secondary">
                            Cancelar
                        </a>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

</body>
</html>