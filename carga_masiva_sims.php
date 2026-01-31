<?php
session_start();
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'Admin') {
    header("Location: index.php");
    exit();
}

include 'db.php';

// ========= Config =========
define('PREVIEW_LIMIT', 200);
define('TMP_DIR', sys_get_temp_dir());
const OPERADORES_VALIDOS = ['Bait','AT&T','Virgin','Unefon','Telcel','Movistar'];

$msg = '';
$previewRows = [];
$contador = ['total' => 0, 'ok' => 0, 'ignoradas' => 0];
$okCarga = isset($_GET['ok']); // bandera para mostrar mensaje después de recargar

// ========= Helpers =========
function columnAllowsNull(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$c' LIMIT 1";
    $rs  = $conn->query($sql);
    if (!$rs) return false;
    $row = $rs->fetch_assoc();
    return isset($row['IS_NULLABLE']) && strtoupper($row['IS_NULLABLE']) === 'YES';
}

function getSucursalIdPorNombre(mysqli $conn, string $nombre, array &$cache): int {
    $nombre = trim($nombre);
    if ($nombre === '') return 0;
    if (isset($cache[$nombre])) return $cache[$nombre];
    $stmt = $conn->prepare("SELECT id FROM sucursales WHERE nombre=? LIMIT 1");
    $stmt->bind_param("s", $nombre);
    $stmt->execute();
    $id = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
    $stmt->close();
    $cache[$nombre] = $id;
    return $id;
}

function quitarAcentosMayus(string $s): string {
    $s = mb_strtoupper($s, 'UTF-8');
    $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
    return $t !== false ? strtoupper($t) : $s;
}

function normalizarOperador(string $opRaw): array {
    $op = quitarAcentosMayus(trim($opRaw));
    $sinEspHyf = str_replace([' ', '-', '.', '_'], '', $op);
    if ($op === '') return ['Bait', true];

    $map = [
        'BAIT' => 'Bait',
        'AT&T' => 'AT&T',
        'ATT' => 'AT&T',
        'VIRGIN' => 'Virgin',
        'VIRGINMOBILE' => 'Virgin',
        'UNEFON' => 'Unefon',
        'TELCEL' => 'Telcel',
        'MOVISTAR' => 'Movistar',
    ];
    if (isset($map[$op])) return [$map[$op], true];
    if (isset($map[$sinEspHyf])) return [$map[$sinEspHyf], true];
    foreach (OPERADORES_VALIDOS as $val) {
        if (quitarAcentosMayus($val) === $op) return [$val, true];
    }
    return [$opRaw, false];
}

/**
 * Limpia el texto crudo del header:
 * - Quita BOM (por bytes y por la secuencia ï»¿).
 * - Quita espacios raros y normaliza a minúsculas.
 */
function cleanHeaderRaw(string $raw): string {
    // Quitar BOM en bytes al inicio
    $raw = preg_replace('/^\xEF\xBB\xBF/u', '', $raw);
    // A veces el BOM ya viene “traducido” a caracteres visibles
    $raw = str_replace('ï»¿', '', $raw);
    // Quitar posibles NBSP (espacio no separable)
    $raw = str_replace("\xC2\xA0", ' ', $raw);
    // Trim normal
    $raw = trim($raw);
    // Normalizar a minúsculas
    $raw = strtolower($raw);
    // Reemplazar espacios y guiones por guion bajo
    $raw = str_replace([' ', '-'], '_', $raw);
    return $raw;
}

/** Lee el header del CSV y arma un mapa de índice por nombre de columna (case-insensitive). */
function buildHeaderMap(array $hdr): array {
    $map = [];
    foreach ($hdr as $i => $raw) {
        $k = cleanHeaderRaw((string)$raw);
        if ($k === '') continue;
        $map[$k] = $i;
    }

    // alias comunes para caja
    if (!isset($map['caja_id'])) {
        if (isset($map['id_caja']))  $map['caja_id'] = $map['id_caja'];
        elseif (isset($map['caja'])) $map['caja_id'] = $map['caja'];
    }

    // detectar iccid aunque tenga suciedad rara (cualquier header que contenga "iccid")
    if (!isset($map['iccid'])) {
        foreach ($map as $k => $idx) {
            if (strpos($k, 'iccid') !== false) {
                $map['iccid'] = $idx;
                break;
            }
        }
    }

    return $map;
}

function getCsvVal(array $row, array $map, string $key): string {
    if (isset($map[$key])) return trim((string)($row[$map[$key]] ?? ''));
    return '';
}

// ========= Descubrimientos iniciales =========

// ID sucursal Eulalia (almacén)
$idEulalia = 0;
$resEulalia = $conn->query("SELECT id FROM sucursales WHERE nombre='Eulalia' LIMIT 1");
if ($resEulalia && $rowE = $resEulalia->fetch_assoc()) {
    $idEulalia = (int)$rowE['id'];
}

// ¿La columna inventario_sims.dn permite NULL?
$dnPermiteNull   = columnAllowsNull($conn, 'inventario_sims', 'dn');
// ¿La columna inventario_sims.lote permite NULL? (debería)
$lotePermiteNull = columnAllowsNull($conn, 'inventario_sims', 'lote');

// Cache de búsqueda de sucursal
$sucursalCache = [];

// ========= INSERTAR =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'insertar') {
    $word = trim($_POST['confirm_word'] ?? '');
    $chk  = isset($_POST['confirm_ok']) ? 1 : 0;
    $token_recv = $_POST['confirm_token'] ?? '';
    $token_sess = $_SESSION['confirm_token'] ?? '';
    if ($word !== 'CARGAR' || $chk !== 1 || $token_recv !== $token_sess) {
        echo "❌ Confirmación inválida.";
        exit;
    }
    $tmpPath = $_SESSION['carga_sims_tmp'] ?? '';
    if ($tmpPath === '' || !is_file($tmpPath)) {
        echo "❌ Archivo temporal no encontrado.";
        exit;
    }

    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="reporte_carga_sims.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['iccid','dn','caja','lote','sucursal','operador','estatus_final','motivo']);

    $sqlInsert = "INSERT INTO inventario_sims (iccid,dn,caja_id,lote,id_sucursal,operador,estatus,fecha_ingreso)
                  VALUES (?,?,?,?,?,?,'Disponible',NOW())";
    $stmtInsert = $conn->prepare($sqlInsert);

    $fh = fopen($tmpPath, 'r');
    $fila = 0; $hdrMap = null;
    while (($data = fgetcsv($fh, 0, ",")) !== false) {
        $fila++;
        if ($fila === 1) {
            $hdrMap = buildHeaderMap($data);
            continue;
        }

        $iccid   = getCsvVal($data, $hdrMap, 'iccid');
        $dn      = getCsvVal($data, $hdrMap, 'dn');
        $caja    = getCsvVal($data, $hdrMap, 'caja_id');
        $lote    = getCsvVal($data, $hdrMap, 'lote');
        $sucNom  = getCsvVal($data, $hdrMap, 'sucursal');
        $opRaw   = getCsvVal($data, $hdrMap, 'operador');

        $id_sucursal = $sucNom === '' ? $idEulalia : getSucursalIdPorNombre($conn, $sucNom, $sucursalCache);
        [$operador, $opValido] = normalizarOperador($opRaw);

        $estatusFinal='Ignorada';
        $motivo='N/A';

        if ($iccid===''){
            $motivo='ICCID vacío';
        } elseif ($id_sucursal===0){
            $motivo='Sucursal no encontrada';
        } elseif (!$opValido){
            $motivo='Operador inválido';
        } else {
            $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
            $stmtDup->bind_param("s", $iccid);
            $stmtDup->execute();
            $stmtDup->store_result();

            if ($stmtDup->num_rows > 0) {
                $motivo='Duplicado';
            } else {
                // DN vacío -> NULL si la columna lo permite
                $dnParam   = ($dn === '')   ? ($dnPermiteNull   ? null : '') : $dn;
                // LOTE vacío -> NULL si la columna lo permite
                $loteParam = ($lote === '') ? ($lotePermiteNull ? null : '') : $lote;

                $stmtInsert->bind_param("ssssis", $iccid, $dnParam, $caja, $loteParam, $id_sucursal, $operador);

                if ($stmtInsert->execute()) {
                    $estatusFinal='Insertada';
                    $motivo='OK';
                } else {
                    $motivo='Error inserción';
                }
            }
            $stmtDup->close();
        }

        fputcsv($out, [$iccid,$dn,$caja,$lote,$sucNom,$operador,$estatusFinal,$motivo]);
    }
    fclose($fh);
    fclose($out);
    @unlink($tmpPath);
    unset($_SESSION['carga_sims_tmp'], $_SESSION['confirm_token']);
    exit;
}

// ========= PREVIEW =========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview' && isset($_FILES['archivo_csv'])) {
    if ($_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $msg = "❌ Error al subir el archivo.";
    } else {
        $nombreOriginal = $_FILES['archivo_csv']['name'];
        $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $msg = "Convierte tu Excel a CSV UTF-8 y súbelo de nuevo.";
        } else {
            $tmpName = "sims_" . date('Ymd_His') . "_" . bin2hex(random_bytes(4)) . ".csv";
            $tmpPath = rtrim(TMP_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $tmpName;
            if (!move_uploaded_file($_FILES['archivo_csv']['tmp_name'], $tmpPath)) {
                $msg = "❌ No se pudo mover el archivo a temporal.";
            } else {
                $_SESSION['carga_sims_tmp'] = $tmpPath;
                $_SESSION['confirm_token'] = bin2hex(random_bytes(16));
                $fh = fopen($tmpPath, 'r');
                if ($fh) {
                    $fila = 0; $hdrMap = null;
                    while (($data = fgetcsv($fh, 0, ",")) !== false) {
                        $fila++;
                        if ($fila === 1) {
                            $hdrMap = buildHeaderMap($data);
                            continue;
                        }

                        $iccid   = getCsvVal($data, $hdrMap, 'iccid');
                        $dn      = getCsvVal($data, $hdrMap, 'dn');
                        $caja    = getCsvVal($data, $hdrMap, 'caja_id');
                        $lote    = getCsvVal($data, $hdrMap, 'lote');
                        $sucNom  = getCsvVal($data, $hdrMap, 'sucursal');
                        $opRaw   = getCsvVal($data, $hdrMap, 'operador');

                        $id_sucursal = $sucNom === '' ? $idEulalia : getSucursalIdPorNombre($conn, $sucNom, $sucursalCache);
                        [$operador, $opValido] = normalizarOperador($opRaw);

                        $estatus = 'OK'; $motivo = 'Listo para insertar';
                        if ($iccid === '') {
                            $estatus='Ignorada';
                            $motivo='ICCID vacío';
                        } elseif ($id_sucursal === 0) {
                            $estatus='Ignorada';
                            $motivo='Sucursal no encontrada';
                        } elseif (!$opValido) {
                            $estatus='Ignorada';
                            $motivo='Operador inválido';
                        } else {
                            $stmtDup = $conn->prepare("SELECT id FROM inventario_sims WHERE iccid=? LIMIT 1");
                            $stmtDup->bind_param("s", $iccid);
                            $stmtDup->execute();
                            $stmtDup->store_result();
                            if ($stmtDup->num_rows > 0) {
                                $estatus='Ignorada';
                                $motivo='Duplicado en base';
                            }
                            $stmtDup->close();
                        }

                        $contador['total']++;
                        if ($estatus === 'OK') $contador['ok']++; else $contador['ignoradas']++;
                        if (count($previewRows) < PREVIEW_LIMIT) {
                            $previewRows[] = [
                                'iccid'=>$iccid,
                                'dn'=>$dn,
                                'caja'=>$caja,
                                'lote'=>$lote,
                                'nombre_sucursal'=>$sucNom,
                                'operador'=>$operador,
                                'estatus'=>$estatus,
                                'motivo'=>$motivo
                            ];
                        }
                    }
                    fclose($fh);
                } else {
                    $msg = "❌ No se pudo abrir el archivo.";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Carga Masiva de SIMs</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">

<?php include __DIR__ . '/navbar.php'; ?>
<div style="height:70px"></div> <!-- offset para navbar fixed -->

<div class="container mt-4">
    <h2>Carga Masiva de SIMs</h2>
    <a href="dashboard_unificado.php" class="btn btn-secondary mb-3">← Volver al Dashboard</a>

    <?php if ($okCarga): ?>
        <div class="alert alert-success">
            ✅ La última carga de SIMs se procesó correctamente. Revisa el archivo de reporte descargado para ver el detalle.
        </div>
    <?php endif; ?>

    <?php if ($msg): ?><div class="alert alert-info"><?= $msg ?></div><?php endif; ?>

    <?php if (!isset($_POST['action']) || ($_POST['action'] ?? '') === ''): ?>
        <div class="card p-4 shadow-sm bg-white">
            <h5>Subir Archivo CSV</h5>
            <p>
               Columnas (recomendado): <b>iccid, dn, caja_id, lote, sucursal, operador</b>.<br>
               <b>dn</b> y <b>lote</b> son opcionales; si vienen vacíos, se guardan como <b>NULL</b>.<br>
               Si <b>sucursal</b> está vacía, se asigna <b>Eulalia</b>.<br>
               Si <b>operador</b> está vacío, se usa <b>Bait</b>.<br>
               Admitimos encabezados equivalentes: <code>caja_id</code>, <code>id_caja</code> o <code>caja</code>.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="preview">
                <input type="file" name="archivo_csv" class="form-control mb-3" accept=".csv" required>
                <button class="btn btn-primary">👀 Vista Previa</button>
            </form>
        </div>

    <?php elseif (($_POST['action'] ?? '') === 'preview'): ?>
        <div class="card p-4 shadow-sm bg-white">
            <h5>Vista Previa</h5>
            <p>
                Total filas: <b><?= $contador['total'] ?></b> |
                OK: <b class="text-success"><?= $contador['ok'] ?></b> |
                Ignoradas: <b class="text-danger"><?= $contador['ignoradas'] ?></b>
            </p>
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                      <tr>
                        <th>ICCID</th>
                        <th>DN</th>
                        <th>Caja</th>
                        <th>Lote</th>
                        <th>Sucursal</th>
                        <th>Operador</th>
                        <th>Estatus</th>
                        <th>Motivo</th>
                      </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($previewRows as $r): ?>
                        <tr class="<?= ($r['estatus']==='OK') ? '' : 'table-warning' ?>">
                            <td><?= htmlspecialchars($r['iccid']) ?></td>
                            <td><?= htmlspecialchars($r['dn']) ?></td>
                            <td><?= htmlspecialchars($r['caja']) ?></td>
                            <td><?= htmlspecialchars($r['lote']) ?></td>
                            <td><?= htmlspecialchars($r['nombre_sucursal']) ?></td>
                            <td><?= htmlspecialchars($r['operador']) ?></td>
                            <td><?= htmlspecialchars($r['estatus']) ?></td>
                            <td><?= htmlspecialchars($r['motivo']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Form de inserción: usa iframe oculto -->
            <form method="POST" class="mt-3" id="formInsertar" target="hidden_iframe">
                <input type="hidden" name="action" value="insertar">
                <input type="hidden" name="confirm_token" value="<?= htmlspecialchars($_SESSION['confirm_token'] ?? '') ?>">
                <div class="alert alert-warning">
                    Se insertarán hasta
                    <b class="text-success"><?= $contador['ok'] ?></b> registros válidos.
                    También se descargará un archivo con el detalle de la carga.
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm_ok" name="confirm_ok">
                    <label class="form-check-label" for="confirm_ok">
                        Entiendo y deseo continuar con la carga.
                    </label>
                </div>
                <input type="text" class="form-control mb-2" name="confirm_word" placeholder="Escribe CARGAR">
                <button class="btn btn-success" id="btnConfirm" disabled>✅ Confirmar e Insertar</button>
                <a href="carga_masiva_sims.php" class="btn btn-outline-secondary">Cancelar</a>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Iframe oculto para procesar la carga y descargar el CSV -->
<iframe name="hidden_iframe" id="hidden_iframe" style="display:none;"></iframe>

<!-- Modal de confirmación visual -->
<div class="modal fade" id="modalCargaOK" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">✅ Carga de SIMs completada</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">
          La <b>carga masiva de SIMs</b> ya se procesó y se generó el archivo de reporte con el detalle de la operación.<br>
          Para evitar cargas duplicadas se recargará la vista una vez que cierres este mensaje.
        </p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="btnEntendido">
            Entendido y recargar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- JS de Bootstrap + lógica -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script> -->
<script>
(function(){
    const chk  = document.getElementById('confirm_ok');
    const word = document.querySelector('[name=confirm_word]');
    const btn  = document.getElementById('btnConfirm');
    const form = document.getElementById('formInsertar');
    const iframe = document.getElementById('hidden_iframe');
    const btnEntendido = document.getElementById('btnEntendido');
    let iframeHandled = false;

    function toggleBtn(){
        if (!btn || !word || !chk) return;
        btn.disabled = !(chk.checked && word.value.trim() === 'CARGAR');
    }

    if (chk && word && btn) {
        chk.addEventListener('change', toggleBtn);
        word.addEventListener('input', toggleBtn);
        toggleBtn();
    }

    if (form && btn) {
        form.addEventListener('submit', function(){
            btn.disabled = true;
            btn.innerText = 'Procesando carga...';
            if (chk) chk.disabled = true;
            if (word) word.readOnly = true;
        });
    }

    // Cuando el iframe termine (o sea, ya acabó la carga y se generó el CSV)
    if (iframe) {
        iframe.addEventListener('load', function(){
            if (iframeHandled) return;
            iframeHandled = true;

            const modalEl = document.getElementById('modalCargaOK');
            if (modalEl && window.bootstrap) {
                const modal = new bootstrap.Modal(modalEl);
                modal.show();
            } else {
                // fallback: recargar directo
                window.location.href = 'carga_masiva_sims.php?ok=1';
            }
        });
    }

    if (btnEntendido) {
        btnEntendido.addEventListener('click', function(){
            window.location.href = 'carga_masiva_sims.php?ok=1';
        });
    }
})();
</script>

</body>
</html>
