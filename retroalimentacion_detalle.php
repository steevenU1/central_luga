<?php
// retroalimentacion_detalle.php — Detalle y firma de retroalimentación Zentral

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function salir(string $msg, int $code = 403): void {
    http_response_code($code);
    echo "<div style='font-family:Arial;padding:30px;color:#333;'><h3>Acceso no disponible</h3><p>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</p><a href='retroalimentacion_listado.php'>Volver</a></div>";
    exit();
}

$idUsuario  = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario = trim((string)($_SESSION['rol'] ?? ''));
$idSucursal = (int)($_SESSION['id_sucursal'] ?? 0);

$idRetro = (int)($_GET['id'] ?? 0);

if ($idRetro <= 0) {
    salir('Retroalimentación inválida.', 400);
}

/* Usuario actual */
$stmt = $conn->prepare("
    SELECT 
        u.id,
        u.nombre,
        u.rol,
        u.id_sucursal,
        s.nombre AS sucursal,
        s.zona
    FROM usuarios u
    LEFT JOIN sucursales s ON s.id = u.id_sucursal
    WHERE u.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idUsuario);
$stmt->execute();
$usuarioActual = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$usuarioActual) {
    salir('No se encontró tu usuario.', 403);
}

$miZona = $usuarioActual['zona'] ?? null;

/* Retroalimentación */
$stmt = $conn->prepare("
    SELECT
        r.*,
        uc.nombre AS colaborador_nombre,
        uc.usuario AS colaborador_usuario,
        uc.correo AS colaborador_correo,
        ul.nombre AS lider_nombre,
        ul.usuario AS lider_usuario,
        s.nombre AS sucursal_nombre
    FROM retroalimentaciones r
    LEFT JOIN usuarios uc ON uc.id = r.id_colaborador
    LEFT JOIN usuarios ul ON ul.id = r.id_lider
    LEFT JOIN sucursales s ON s.id = r.id_sucursal
    WHERE r.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idRetro);
$stmt->execute();
$retro = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$retro) {
    salir('No se encontró la retroalimentación.', 404);
}

/* Validación de acceso */
$puedeVer = false;

if ((int)$retro['id_colaborador'] === $idUsuario) {
    $puedeVer = true;
}

if ((int)$retro['id_lider'] === $idUsuario) {
    $puedeVer = true;
}

if ($rolUsuario === 'Admin') {
    $puedeVer = true;
}

if ($rolUsuario === 'GerenteZona' && ($retro['zona'] ?? null) === $miZona) {
    $puedeVer = true;
}

if ($rolUsuario === 'Gerente' && (int)$retro['id_sucursal'] === $idSucursal) {
    $puedeVer = true;
}

if (!$puedeVer) {
    salir('No tienes permiso para ver esta retroalimentación.');
}

$folio = $retro['folio'] ?? '';
if ($folio === '') {
    $folio = 'RETRO-' . str_pad((string)$retro['id'], 6, '0', STR_PAD_LEFT);
}

$estatus = strtoupper((string)$retro['estatus']);
$esColaborador = ((int)$retro['id_colaborador'] === $idUsuario);
$puedeFirmar = $esColaborador && $estatus === 'PENDIENTE';

$fechaCreacion = !empty($retro['fecha_creacion'])
    ? date('d/m/Y H:i', strtotime($retro['fecha_creacion']))
    : '-';

$fechaFirma = !empty($retro['fecha_firma'])
    ? date('d/m/Y H:i', strtotime($retro['fecha_firma']))
    : null;

$mensaje = trim((string)($_GET['msg'] ?? ''));
$error = trim((string)($_GET['error'] ?? ''));

function badgeEstatus(string $estatus): string {
    if ($estatus === 'FIRMADA') {
        return '<span class="badge rounded-pill text-bg-success px-3 py-2">Firmada</span>';
    }

    if ($estatus === 'CANCELADA') {
        return '<span class="badge rounded-pill text-bg-danger px-3 py-2">Cancelada</span>';
    }

    return '<span class="badge rounded-pill text-bg-warning px-3 py-2">Pendiente de firma</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Detalle de retroalimentación | Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(0, 174, 255, 0.15), transparent 28%),
                radial-gradient(circle at bottom right, rgba(0, 70, 140, 0.15), transparent 32%),
                linear-gradient(135deg, #071426 0%, #102a43 42%, #f4f7fb 42%, #f4f7fb 100%);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .zentral-shell {
            max-width: 1100px;
            margin: 0 auto;
            padding: 28px 16px 42px;
        }

        .hero {
            background: linear-gradient(135deg, #071426, #123c69);
            color: #fff;
            border-radius: 24px;
            padding: 28px;
            box-shadow: 0 18px 45px rgba(5, 20, 40, 0.22);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: "";
            position: absolute;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(0, 200, 255, 0.14);
            right: -80px;
            top: -100px;
        }

        .hero h1 {
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0;
        }

        .hero p {
            color: rgba(255,255,255,.78);
            margin-bottom: 0;
        }

        .glass-card {
            background: rgba(255,255,255,.96);
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 22px;
            box-shadow: 0 18px 40px rgba(15, 35, 65, 0.13);
        }

        .btn-zentral {
            background: linear-gradient(135deg, #083b66, #0d8ddf);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-weight: 800;
            padding: 11px 18px;
            box-shadow: 0 12px 26px rgba(13, 141, 223, .25);
        }

        .btn-zentral:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        .meta-card {
            background: #f7fbff;
            border: 1px solid #d9ecfb;
            border-radius: 18px;
            padding: 16px;
            height: 100%;
        }

        .meta-label {
            color: #6c7a89;
            font-size: .82rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
        }

        .meta-value {
            color: #10233c;
            font-weight: 800;
        }

        .content-block {
            border: 1px solid #e1e9f2;
            border-radius: 20px;
            padding: 22px;
            background: #fff;
        }

        .content-block h5 {
            color: #0f243d;
            font-weight: 800;
            margin-bottom: 12px;
        }

        .content-text {
            white-space: pre-wrap;
            color: #2b3b4f;
            line-height: 1.65;
        }

        .signature-box {
            background: #f8fbff;
            border: 1px solid #d7e8f7;
            border-radius: 20px;
            padding: 22px;
        }

        .folio {
            font-weight: 900;
            color: #dff4ff;
            letter-spacing: .02em;
        }

        .form-control {
            border-radius: 14px;
            padding: 12px 14px;
        }
    </style>
</head>
<body>

<?php
if (file_exists(__DIR__ . '/navbar.php')) {
    require_once __DIR__ . '/navbar.php';
}
?>

<div class="zentral-shell">

    <div class="hero mb-4">
        <div class="position-relative">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <div class="folio mb-2"><?= h($folio) ?></div>
                    <h1>Detalle de retroalimentación</h1>
                    <p class="mt-2">
                        Revisión formal de desempeño, acuerdos y firma digital del colaborador.
                    </p>
                </div>

                <div class="text-md-end">
                    <?= badgeEstatus($estatus) ?>
                    <div class="small text-white-50 mt-2">
                        Creada el <?= h($fechaCreacion) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-success rounded-4 shadow-sm">
            <i class="bi bi-check-circle-fill me-2"></i><?= h($mensaje) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger rounded-4 shadow-sm">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?>
        </div>
    <?php endif; ?>

    <div class="glass-card p-4 p-md-5 mb-4">

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-lg-3">
                <div class="meta-card">
                    <div class="meta-label">Colaborador</div>
                    <div class="meta-value"><?= h($retro['colaborador_nombre'] ?? '-') ?></div>
                    <div class="text-muted small"><?= h($retro['rol_colaborador'] ?? '') ?></div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3">
                <div class="meta-card">
                    <div class="meta-label">Líder</div>
                    <div class="meta-value"><?= h($retro['lider_nombre'] ?? '-') ?></div>
                    <div class="text-muted small"><?= h($retro['rol_lider'] ?? '') ?></div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3">
                <div class="meta-card">
                    <div class="meta-label">Sucursal / zona</div>
                    <div class="meta-value"><?= h($retro['sucursal_nombre'] ?? '-') ?></div>
                    <div class="text-muted small"><?= h($retro['zona'] ?? 'Sin zona') ?></div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-lg-3">
                <div class="meta-card">
                    <div class="meta-label">Periodo</div>
                    <div class="meta-value"><?= h($retro['periodo'] ?? '-') ?></div>
                    <div class="text-muted small">Referencia interna</div>
                </div>
            </div>
        </div>

        <div class="d-grid gap-4">

            <div class="content-block">
                <h5><i class="bi bi-stars me-2 text-primary"></i>¿Qué estoy haciendo bien?</h5>
                <div class="content-text"><?= h($retro['que_hace_bien'] ?? '') ?></div>
            </div>

            <div class="content-block">
                <h5><i class="bi bi-arrow-up-circle me-2 text-primary"></i>¿En qué puedo mejorar?</h5>
                <div class="content-text"><?= h($retro['que_puede_mejorar'] ?? '') ?></div>
            </div>

            <div class="content-block">
                <h5><i class="bi bi-clipboard-check me-2 text-primary"></i>Acuerdos</h5>
                <div class="content-text"><?= h($retro['acuerdos'] ?? '') ?></div>
            </div>

        </div>

    </div>

    <div class="glass-card p-4 p-md-5">

        <?php if ($estatus === 'FIRMADA'): ?>
            <div class="signature-box">
                <h5 class="fw-bold mb-2">
                    <i class="bi bi-patch-check-fill text-success me-2"></i>
                    Documento firmado digitalmente
                </h5>
                <p class="mb-1">
                    Esta retroalimentación fue firmada por
                    <strong><?= h($retro['colaborador_nombre'] ?? 'el colaborador') ?></strong>.
                </p>
                <div class="text-muted">
                    Fecha de firma: <?= h($fechaFirma ?? '-') ?>
                </div>
                <?php if (!empty($retro['ip_firma'])): ?>
                    <div class="text-muted small mt-1">
                        IP registrada: <?= h($retro['ip_firma']) ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($puedeFirmar): ?>

            <div class="signature-box">
                <h5 class="fw-bold mb-2">
                    <i class="bi bi-pen me-2 text-primary"></i>
                    Firma digital del colaborador
                </h5>

                <p class="text-muted">
                    Al firmar confirmas que revisaste la retroalimentación y los acuerdos registrados.
                </p>

                <form action="retroalimentacion_firmar.php" method="POST" id="formFirmar">
                    <input type="hidden" name="id" value="<?= (int)$retro['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label fw-bold">Confirma tu contraseña</label>
                        <input type="password" name="password" class="form-control" required
                               placeholder="Ingresa tu contraseña para validar la firma">
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" value="1" id="acepto" name="acepto" required>
                        <label class="form-check-label" for="acepto">
                            Confirmo que he leído esta retroalimentación y acepto firmarla digitalmente.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-zentral">
                        <i class="bi bi-patch-check me-1"></i>
                        Firmar retroalimentación
                    </button>
                </form>
            </div>

        <?php else: ?>

            <div class="signature-box">
                <h5 class="fw-bold mb-2">
                    <i class="bi bi-hourglass-split text-warning me-2"></i>
                    Pendiente de firma
                </h5>
                <p class="text-muted mb-0">
                    Esta retroalimentación aún no ha sido firmada por el colaborador.
                </p>
            </div>

        <?php endif; ?>

        <div class="d-flex flex-column flex-md-row gap-2 justify-content-between mt-4">
            <a href="retroalimentacion_listado.php" class="btn btn-light border rounded-4 px-4">
                <i class="bi bi-arrow-left me-1"></i>
                Volver al listado
            </a>

            <?php if ($estatus === 'FIRMADA'): ?>
                <a href="retroalimentacion_pdf.php?id=<?= (int)$retro['id'] ?>" target="_blank"
                   class="btn btn-outline-primary rounded-4 px-4">
                    <i class="bi bi-file-earmark-pdf me-1"></i>
                    Ver PDF
                </a>
            <?php endif; ?>
        </div>

    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('formFirmar');

    if (form) {
        form.addEventListener('submit', function () {
            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Firmando...';
            }
        });
    }
});
</script>

<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->

</body>
</html>