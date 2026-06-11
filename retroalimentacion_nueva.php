<?php
// retroalimentacion_nueva.php — Nueva retroalimentación Zentral

session_start();

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn->set_charset("utf8mb4");
date_default_timezone_set('America/Mexico_City');

$idUsuario   = (int)($_SESSION['id_usuario'] ?? 0);
$rolUsuario  = trim((string)($_SESSION['rol'] ?? ''));
$idSucursal  = (int)($_SESSION['id_sucursal'] ?? 0);
$idSubdis    = isset($_SESSION['id_subdis']) && $_SESSION['id_subdis'] !== '' ? (int)$_SESSION['id_subdis'] : null;
$nombreUser  = trim((string)($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario'));

$rolesPermitidos = ['Admin', 'GerenteZona', 'Gerente'];

if (!in_array($rolUsuario, $rolesPermitidos, true)) {
    http_response_code(403);
    echo "No tienes permiso para crear retroalimentaciones.";
    exit();
}

$miZona = null;
$miSucursalNombre = '';

$stmt = $conn->prepare("
    SELECT nombre, zona
    FROM sucursales
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $idSucursal);
$stmt->execute();
$resSucursal = $stmt->get_result();

if ($rowSuc = $resSucursal->fetch_assoc()) {
    $miSucursalNombre = $rowSuc['nombre'] ?? '';
    $miZona = $rowSuc['zona'] ?? null;
}
$stmt->close();

$colaboradores = [];

if ($rolUsuario === 'Admin') {
    $sql = "
        SELECT 
            u.id,
            u.nombre,
            u.usuario,
            u.rol,
            u.id_sucursal,
            u.id_subdis,
            s.nombre AS sucursal,
            s.zona
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo = 1
          AND u.rol = 'GerenteZona'
        ORDER BY u.nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $colaboradores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} elseif ($rolUsuario === 'GerenteZona') {
    $sql = "
        SELECT 
            u.id,
            u.nombre,
            u.usuario,
            u.rol,
            u.id_sucursal,
            u.id_subdis,
            s.nombre AS sucursal,
            s.zona
        FROM usuarios u
        INNER JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo = 1
          AND u.rol = 'Gerente'
          AND s.zona = ?
        ORDER BY s.nombre ASC, u.nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $miZona);
    $stmt->execute();
    $colaboradores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} elseif ($rolUsuario === 'Gerente') {
    $sql = "
        SELECT 
            u.id,
            u.nombre,
            u.usuario,
            u.rol,
            u.id_sucursal,
            u.id_subdis,
            s.nombre AS sucursal,
            s.zona
        FROM usuarios u
        LEFT JOIN sucursales s ON s.id = u.id_sucursal
        WHERE u.activo = 1
          AND u.rol = 'Ejecutivo'
          AND u.id_sucursal = ?
        ORDER BY u.nombre ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $idSucursal);
    $stmt->execute();
    $colaboradores = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$mensaje = trim((string)($_GET['msg'] ?? ''));
$error   = trim((string)($_GET['error'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva retroalimentación | Zentral</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at top left, rgba(0, 174, 255, 0.18), transparent 28%),
                radial-gradient(circle at bottom right, rgba(0, 70, 140, 0.18), transparent 32%),
                linear-gradient(135deg, #071426 0%, #102a43 45%, #f4f7fb 45%, #f4f7fb 100%);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .zentral-shell {
            max-width: 1180px;
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
            background: rgba(255,255,255,.95);
            border: 1px solid rgba(255,255,255,.7);
            border-radius: 22px;
            box-shadow: 0 18px 40px rgba(15, 35, 65, 0.13);
        }

        .form-label {
            font-weight: 700;
            color: #213047;
        }

        .form-control,
        .form-select {
            border-radius: 14px;
            border: 1px solid #d8e1ec;
            padding: 12px 14px;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: #1a8cff;
            box-shadow: 0 0 0 .2rem rgba(26, 140, 255, .12);
        }

        .btn-zentral {
            background: linear-gradient(135deg, #083b66, #0d8ddf);
            color: #fff;
            border: none;
            border-radius: 14px;
            font-weight: 800;
            padding: 12px 20px;
            box-shadow: 0 12px 26px rgba(13, 141, 223, .25);
        }

        .btn-zentral:hover {
            color: #fff;
            transform: translateY(-1px);
        }

        .info-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #edf6ff;
            color: #0d4d83;
            border: 1px solid #d6ecff;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: .88rem;
            font-weight: 700;
        }

        .small-muted {
            color: #6c7a89;
            font-size: .92rem;
        }

        .section-title {
            font-weight: 800;
            color: #0f243d;
            letter-spacing: -0.02em;
        }

        .preview-box {
            background: #f7fbff;
            border: 1px dashed #bfd9ef;
            border-radius: 18px;
            padding: 16px;
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
                    <h1>Nueva retroalimentación</h1>
                    <p class="mt-2">
                        Captura una retroalimentación clara, útil y lista para firma del colaborador.
                    </p>
                </div>
                <div class="text-md-end">
                    <div class="info-chip mb-2">
                        <i class="bi bi-person-badge"></i>
                        <?= h($rolUsuario) ?>
                    </div>
                    <?php if ($miSucursalNombre): ?>
                        <div class="small text-white-50">
                            <?= h($miSucursalNombre) ?>
                            <?= $miZona ? ' · ' . h($miZona) : '' ?>
                        </div>
                    <?php endif; ?>
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

    <div class="glass-card p-4 p-md-5">

        <div class="mb-4">
            <h4 class="section-title mb-1">Datos de la retroalimentación</h4>
            <div class="small-muted">
                Solo se muestran colaboradores permitidos según tu rol y alcance.
            </div>
        </div>

        <?php if (empty($colaboradores)): ?>
            <div class="alert alert-warning rounded-4">
                <i class="bi bi-info-circle-fill me-2"></i>
                No se encontraron colaboradores disponibles para retroalimentar con tu rol actual.
            </div>
        <?php else: ?>

            <form action="retroalimentacion_guardar.php" method="POST" autocomplete="off" id="formRetro">

                <div class="row g-4">

                    <div class="col-12 col-lg-8">
                        <label for="id_colaborador" class="form-label">
                            Colaborador a retroalimentar
                        </label>
                        <select name="id_colaborador" id="id_colaborador" class="form-select" required>
                            <option value="">Selecciona un colaborador</option>
                            <?php foreach ($colaboradores as $c): ?>
                                <option
                                    value="<?= (int)$c['id'] ?>"
                                    data-rol="<?= h($c['rol']) ?>"
                                    data-sucursal="<?= h($c['sucursal'] ?? '') ?>"
                                    data-zona="<?= h($c['zona'] ?? '') ?>"
                                >
                                    <?= h($c['nombre']) ?>
                                    <?php if (!empty($c['rol'])): ?>
                                        · <?= h($c['rol']) ?>
                                    <?php endif; ?>
                                    <?php if (!empty($c['sucursal'])): ?>
                                        · <?= h($c['sucursal']) ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-12 col-lg-4">
                        <label for="periodo" class="form-label">
                            Periodo / referencia
                        </label>
                        <input
                            type="text"
                            name="periodo"
                            id="periodo"
                            class="form-control"
                            maxlength="100"
                            placeholder="Ej. Mayo 2026"
                            value="<?= h(date('F Y')) ?>"
                        >
                    </div>

                    <div class="col-12">
                        <div class="preview-box" id="previewColaborador">
                            <div class="small-muted">
                                Selecciona un colaborador para ver su información.
                            </div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="que_hace_bien" class="form-label">
                            ¿Qué estoy haciendo bien?
                        </label>
                        <textarea
                            name="que_hace_bien"
                            id="que_hace_bien"
                            class="form-control"
                            rows="5"
                            required
                            maxlength="5000"
                            placeholder="Describe fortalezas, buenas prácticas, avances o comportamientos positivos."
                        ></textarea>
                    </div>

                    <div class="col-12">
                        <label for="que_puede_mejorar" class="form-label">
                            ¿En qué puedo mejorar?
                        </label>
                        <textarea
                            name="que_puede_mejorar"
                            id="que_puede_mejorar"
                            class="form-control"
                            rows="5"
                            required
                            maxlength="5000"
                            placeholder="Describe oportunidades de mejora de forma clara, respetuosa y accionable."
                        ></textarea>
                    </div>

                    <div class="col-12">
                        <label for="acuerdos" class="form-label">
                            Acuerdos
                        </label>
                        <textarea
                            name="acuerdos"
                            id="acuerdos"
                            class="form-control"
                            rows="5"
                            required
                            maxlength="5000"
                            placeholder="Registra compromisos, fechas, acciones o próximos pasos."
                        ></textarea>
                    </div>

                    <div class="col-12">
                        <div class="alert alert-info rounded-4 mb-0">
                            <i class="bi bi-shield-check me-2"></i>
                            Al guardar, la retroalimentación quedará como <strong>PENDIENTE</strong> para que el colaborador pueda revisarla y firmarla.
                        </div>
                    </div>

                    <div class="col-12 d-flex flex-column flex-md-row gap-2 justify-content-end">
                        <a href="retroalimentacion_listado.php" class="btn btn-light border rounded-4 px-4">
                            <i class="bi bi-arrow-left me-1"></i>
                            Cancelar
                        </a>

                        <button type="submit" class="btn btn-zentral">
                            <i class="bi bi-send-check me-1"></i>
                            Guardar retroalimentación
                        </button>
                    </div>

                </div>

            </form>

        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('id_colaborador');
    const preview = document.getElementById('previewColaborador');
    const form = document.getElementById('formRetro');

    if (select && preview) {
        select.addEventListener('change', function () {
            const opt = select.options[select.selectedIndex];

            if (!select.value) {
                preview.innerHTML = '<div class="small-muted">Selecciona un colaborador para ver su información.</div>';
                return;
            }

            const nombre = opt.textContent.trim();
            const rol = opt.dataset.rol || 'Sin rol';
            const sucursal = opt.dataset.sucursal || 'Sin sucursal';
            const zona = opt.dataset.zona || 'Sin zona';

            preview.innerHTML = `
                <div class="row g-3 align-items-center">
                    <div class="col-12 col-md-4">
                        <div class="small-muted">Colaborador</div>
                        <strong>${escapeHtml(nombre)}</strong>
                    </div>
                    <div class="col-6 col-md-2">
                        <div class="small-muted">Rol</div>
                        <strong>${escapeHtml(rol)}</strong>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small-muted">Sucursal</div>
                        <strong>${escapeHtml(sucursal)}</strong>
                    </div>
                    <div class="col-12 col-md-3">
                        <div class="small-muted">Zona</div>
                        <strong>${escapeHtml(zona)}</strong>
                    </div>
                </div>
            `;
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            const colaborador = document.getElementById('id_colaborador').value;
            const bien = document.getElementById('que_hace_bien').value.trim();
            const mejora = document.getElementById('que_puede_mejorar').value.trim();
            const acuerdos = document.getElementById('acuerdos').value.trim();

            if (!colaborador || !bien || !mejora || !acuerdos) {
                e.preventDefault();
                alert('Completa todos los campos obligatorios antes de guardar.');
                return;
            }

            const btn = form.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Guardando...';
            }
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>