<?php
// LEALTAD_PARAMETROS_ADMIN.php
// Administra parámetros del programa de lealtad

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario']) || ($_SESSION['rol'] ?? '') !== 'Admin') {
    header("Location: 403.php");
    exit();
}

require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Mexico_City');

$mensaje = '';
$mensajeError = '';

/* =========================================================
   1) Cargar campaña vigente + historial
   ========================================================= */

// Campaña vigente (la que aplica hoy)
$sqlVig = "
    SELECT *
    FROM lealtad_parametros
    WHERE vigente_desde <= CURDATE()
      AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())
    ORDER BY vigente_desde DESC, id DESC
    LIMIT 1
";
$resVig = $conn->query($sqlVig);
$campaniaVigente = ($resVig && $resVig->num_rows) ? $resVig->fetch_assoc() : null;

// Último registro (por si no hay vigente)
$sqlUlt = "
    SELECT *
    FROM lealtad_parametros
    ORDER BY vigente_desde DESC, id DESC
    LIMIT 1
";
$resUlt = $conn->query($sqlUlt);
$ultimoRegistro = ($resUlt && $resUlt->num_rows) ? $resUlt->fetch_assoc() : null;

// Historial (los últimos 20)
$sqlHist = "
    SELECT *
    FROM lealtad_parametros
    ORDER BY vigente_desde DESC, id DESC
    LIMIT 20
";
$resHist = $conn->query($sqlHist);
$historial = [];
if ($resHist && $resHist->num_rows) {
    while ($row = $resHist->fetch_assoc()) {
        $historial[] = $row;
    }
}

// Config que se edita en el formulario: la vigente si existe, si no el último registro
if ($campaniaVigente) {
    $cfg = $campaniaVigente;
} elseif ($ultimoRegistro) {
    $cfg = $ultimoRegistro;
} else {
    // Defaults si la tabla está vacía
    $cfg = [
        'id'                       => 0,
        'vigente_desde'            => date('Y-m-d'),
        'vigente_hasta'            => null,
        'puntos_por_abono_puntual' => 10,
        'puntos_por_referido'      => 10,
        'valor_puntos_equivalente' => 10,
        'valor_pesos_por_bloque'   => 50.00,
        'beneficio_referido_monto' => 100.00,
        'beneficio_referido_tope'  => 200.00,
        'vigencia_puntos_meses'    => 6,
        'puntos_equivalencia'      => 10,
        'monto_equivalencia'       => 50.00,
        'max_beneficio_por_venta'  => 200.00,
    ];
}

/* =========================================================
   2) Procesar POST (guardar cambios)
   ========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $idParam = (int)($_POST['id_param'] ?? 0);

        $vigente_desde = trim($_POST['vigente_desde'] ?? '');
        if ($vigente_desde === '') {
            $vigente_desde = date('Y-m-d');
        }

        $vigente_hasta = trim($_POST['vigente_hasta'] ?? '');
        if ($vigente_hasta === '') {
            $vigente_hasta = null; // se guardará como NULL
        }

        $puntos_abono      = (int)($_POST['puntos_por_abono_puntual'] ?? 0);
        $puntos_referido   = (int)($_POST['puntos_por_referido'] ?? 0);

        // Equivalencia de puntos → pesos (ej. 10 puntos → 50 pesos)
        $puntos_equiv      = (int)($_POST['puntos_equivalencia'] ?? 0);
        $monto_equiv       = (float)($_POST['monto_equivalencia'] ?? 0);

        // Mantener consistencia con campos "viejos"
        $valor_puntos_equivalente = $puntos_equiv;
        $valor_pesos_por_bloque   = $monto_equiv;

        // Beneficio en efectivo para referidor
        $beneficio_monto = (float)($_POST['beneficio_referido_monto'] ?? 0);
        $beneficio_tope  = (float)($_POST['beneficio_referido_tope'] ?? 0);

        // Vigencia de puntos y tope por venta
        $vigencia_meses = (int)($_POST['vigencia_puntos_meses'] ?? 0);
        $max_beneficio  = (float)($_POST['max_beneficio_por_venta'] ?? 0);

        if ($idParam > 0) {
            // UPDATE al registro vigente/seleccionado
            $stmt = $conn->prepare("
                UPDATE lealtad_parametros
                SET
                    vigente_desde            = ?,
                    vigente_hasta            = ?,
                    puntos_por_abono_puntual = ?,
                    puntos_por_referido      = ?,
                    valor_puntos_equivalente = ?,
                    valor_pesos_por_bloque   = ?,
                    beneficio_referido_monto = ?,
                    beneficio_referido_tope  = ?,
                    vigencia_puntos_meses    = ?,
                    puntos_equivalencia      = ?,
                    monto_equivalencia       = ?,
                    max_beneficio_por_venta  = ?
                WHERE id = ?
            ");
            $stmt->bind_param(
                "ssiiidddiiddi",
                $vigente_desde,
                $vigente_hasta,
                $puntos_abono,
                $puntos_referido,
                $valor_puntos_equivalente,
                $valor_pesos_por_bloque,
                $beneficio_monto,
                $beneficio_tope,
                $vigencia_meses,
                $puntos_equiv,
                $monto_equiv,
                $max_beneficio,
                $idParam
            );
        } else {
            // INSERT nuevo
            $stmt = $conn->prepare("
                INSERT INTO lealtad_parametros
                (vigente_desde, vigente_hasta,
                 puntos_por_abono_puntual, puntos_por_referido,
                 valor_puntos_equivalente, valor_pesos_por_bloque,
                 beneficio_referido_monto, beneficio_referido_tope,
                 vigencia_puntos_meses,
                 puntos_equivalencia, monto_equivalencia,
                 max_beneficio_por_venta)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            // 12 columnas → 12 tipos
            $stmt->bind_param(
                "ssiiidddiidd",
                $vigente_desde,
                $vigente_hasta,
                $puntos_abono,
                $puntos_referido,
                $valor_puntos_equivalente,
                $valor_pesos_por_bloque,
                $beneficio_monto,
                $beneficio_tope,
                $vigencia_meses,
                $puntos_equiv,
                $monto_equiv,
                $max_beneficio
            );
        }

        $stmt->execute();
        $stmt->close();

        $mensaje = "Parámetros de lealtad guardados correctamente.";

        // 🔁 Volver a cargar campaña vigente + historial ya con lo nuevo
        $resVig = $conn->query($sqlVig);
        $campaniaVigente = ($resVig && $resVig->num_rows) ? $resVig->fetch_assoc() : null;

        $resUlt = $conn->query($sqlUlt);
        $ultimoRegistro = ($resUlt && $resUlt->num_rows) ? $resUlt->fetch_assoc() : null;

        $resHist = $conn->query($sqlHist);
        $historial = [];
        if ($resHist && $resHist->num_rows) {
            while ($row = $resHist->fetch_assoc()) {
                $historial[] = $row;
            }
        }

        if ($campaniaVigente) {
            $cfg = $campaniaVigente;
        } elseif ($ultimoRegistro) {
            $cfg = $ultimoRegistro;
        }

    } catch (Throwable $e) {
        $mensajeError = "Error al guardar parámetros: " . $e->getMessage();
    }
}

function fmtFecha(?string $f): string {
    if (!$f) return 'Abierto';
    if ($f === '0000-00-00') return 'Abierto';
    $dt = DateTime::createFromFormat('Y-m-d', $f);
    return $dt ? $dt->format('d/m/Y') : $f;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Parámetros de Lealtad</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/x-icon" href="./img/favicon.ico?v=2">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/navbar.php'; ?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 mb-0">
            <i class="bi bi-stars me-2"></i>Parámetros del programa de lealtad
        </h2>
        <a href="panel.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Volver al panel
        </a>
    </div>

    <?php if ($mensaje): ?>
        <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
    <?php endif; ?>

    <?php if ($mensajeError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($mensajeError) ?></div>
    <?php endif; ?>

    <!-- 🔹 Resumen de campaña vigente -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h5 class="card-title mb-0">
                    <i class="bi bi-bullseye me-2 text-success"></i>
                    Campaña vigente
                </h5>
                <?php if ($campaniaVigente): ?>
                    <span class="badge bg-success-subtle text-success border border-success">
                        Activa
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary">Sin campaña activa</span>
                <?php endif; ?>
            </div>

            <?php if ($campaniaVigente): ?>
                <div class="row g-3 small">
                    <div class="col-md-3">
                        <strong>Vigencia:</strong><br>
                        <?= fmtFecha($campaniaVigente['vigente_desde'] ?? null) ?>
                        &nbsp;→&nbsp;
                        <?= fmtFecha($campaniaVigente['vigente_hasta'] ?? null) ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Puntos base:</strong><br>
                        Abono puntual: <?= (int)$campaniaVigente['puntos_por_abono_puntual'] ?><br>
                        Referido: <?= (int)$campaniaVigente['puntos_por_referido'] ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Equivalencia:</strong><br>
                        <?= (int)$campaniaVigente['puntos_equivalencia'] ?>
                        pts = $<?= number_format((float)$campaniaVigente['monto_equivalencia'], 2) ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Beneficio referidor:</strong><br>
                        $<?= number_format((float)$campaniaVigente['beneficio_referido_monto'], 2) ?>
                        (tope cliente: $<?= number_format((float)$campaniaVigente['beneficio_referido_tope'], 2) ?>)
                    </div>
                </div>
            <?php else: ?>
                <div class="text-muted small">
                    No hay campaña con vigencia actual. El formulario de abajo te permite crear una nueva.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🔹 Formulario de edición/creación -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="id_param" value="<?= (int)$cfg['id'] ?>">

                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Vigente desde</label>
                        <input type="date" name="vigente_desde" class="form-control"
                               value="<?= htmlspecialchars($cfg['vigente_desde'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vigente hasta</label>
                        <input type="date" name="vigente_hasta" class="form-control"
                               value="<?= htmlspecialchars($cfg['vigente_hasta'] ?? '') ?>">
                        <div class="form-text">Déjalo vacío para que siga vigente.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Vigencia de puntos (meses)</label>
                        <input type="number" min="1" name="vigencia_puntos_meses" class="form-control"
                               value="<?= (int)($cfg['vigencia_puntos_meses'] ?? 6) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Máx. beneficio por venta ($)</label>
                        <input type="number" step="0.01" min="0" name="max_beneficio_por_venta" class="form-control"
                               value="<?= htmlspecialchars($cfg['max_beneficio_por_venta'] ?? '0.00') ?>">
                    </div>
                </div>

                <hr>

                <h6 class="text-uppercase text-muted mb-2">Puntos base</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Puntos por abono puntual</label>
                        <input type="number" min="0" name="puntos_por_abono_puntual" class="form-control"
                               value="<?= (int)($cfg['puntos_por_abono_puntual'] ?? 0) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Puntos por referido</label>
                        <input type="number" min="0" name="puntos_por_referido" class="form-control"
                               value="<?= (int)($cfg['puntos_por_referido'] ?? 0) ?>">
                    </div>
                </div>

                <hr>

                <h6 class="text-uppercase text-muted mb-2">Equivalencia puntos → pesos</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Cada N puntos</label>
                        <input type="number" min="1" name="puntos_equivalencia" class="form-control"
                               value="<?= (int)($cfg['puntos_equivalencia'] ?? $cfg['valor_puntos_equivalente'] ?? 10) ?>">
                        <div class="form-text">Ejemplo: 10 puntos…</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Equivalen a ($)</label>
                        <input type="number" step="0.01" min="0" name="monto_equivalencia" class="form-control"
                               value="<?= htmlspecialchars($cfg['monto_equivalencia'] ?? $cfg['valor_pesos_por_bloque'] ?? '0.00') ?>">
                        <div class="form-text">…equivalen a 50 pesos.</div>
                    </div>
                </div>

                <hr>

                <h6 class="text-uppercase text-muted mb-2">Beneficio para referidor</h6>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Monto por referido ($)</label>
                        <input type="number" step="0.01" min="0" name="beneficio_referido_monto" class="form-control"
                               value="<?= htmlspecialchars($cfg['beneficio_referido_monto'] ?? '0.00') ?>">
                        <div class="form-text">Ej.: 100 = $100 en puntos/beneficio.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Tope por cliente ($)</label>
                        <input type="number" step="0.01" min="0" name="beneficio_referido_tope" class="form-control"
                               value="<?= htmlspecialchars($cfg['beneficio_referido_tope'] ?? '0.00') ?>">
                    </div>
                </div>

                <div class="mt-4 text-end">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i> Guardar parámetros
                    </button>
                </div>

            </form>
        </div>
    </div>

    <!-- 🔹 Historial de campañas -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <h5 class="card-title mb-3">
                <i class="bi bi-clock-history me-2"></i>Historial de campañas
            </h5>

            <?php if (empty($historial)): ?>
                <div class="text-muted small">Aún no hay campañas registradas.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Vigente desde</th>
                                <th>Vigente hasta</th>
                                <th>Puntos abono</th>
                                <th>Puntos referido</th>
                                <th>Equiv. (pts → $)</th>
                                <th>Beneficio referidor</th>
                                <th>Vigencia pts (meses)</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historial as $row): ?>
                            <?php
                            $esVig = $campaniaVigente && ((int)$campaniaVigente['id'] === (int)$row['id']);
                            ?>
                            <tr class="<?= $esVig ? 'table-success' : '' ?>">
                                <td><?= (int)$row['id'] ?></td>
                                <td><?= fmtFecha($row['vigente_desde'] ?? null) ?></td>
                                <td><?= fmtFecha($row['vigente_hasta'] ?? null) ?></td>
                                <td><?= (int)$row['puntos_por_abono_puntual'] ?></td>
                                <td><?= (int)$row['puntos_por_referido'] ?></td>
                                <td>
                                    <?= (int)$row['puntos_equivalencia'] ?>
                                    → $<?= number_format((float)$row['monto_equivalencia'], 2) ?>
                                </td>
                                <td>
                                    $<?= number_format((float)$row['beneficio_referido_monto'], 2) ?>
                                    (tope $<?= number_format((float)$row['beneficio_referido_tope'], 2) ?>)
                                </td>
                                <td><?= (int)$row['vigencia_puntos_meses'] ?></td>
                                <td>
                                    <?php if ($esVig): ?>
                                        <span class="badge bg-success">Vigente</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Histórica</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
