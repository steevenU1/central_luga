<?php
// nomina_overrides_admin.php
session_start();
if (!isset($_SESSION['id_usuario']) || !in_array($_SESSION['rol'], ['Admin','RH'])) {
  header("Location: index.php"); exit();
}

include 'db.php';
include 'navbar.php';
include 'helpers_nomina.php';

date_default_timezone_set('America/Mexico_City');

/* ========================
   Semanas (mar→lun)
======================== */
function obtenerSemanaPorIndice($offset = 0) {
  $tz = new DateTimeZone('America/Mexico_City');
  $hoy = new DateTime('now', $tz);
  $diaSemana = (int)$hoy->format('N'); // 1=Lun..7=Dom
  $dif = $diaSemana - 2; if ($dif < 0) $dif += 7; // martes=2
  $inicio = new DateTime('now', $tz);
  $inicio->modify('-'.$dif.' days')->setTime(0,0,0);
  if ($offset > 0) $inicio->modify('-'.(7*$offset).' days');
  $fin = (clone $inicio)->modify('+6 days')->setTime(23,59,59);
  return [$inicio, $fin];
}

$semana = isset($_GET['semana']) ? (int)$_GET['semana'] : 0;
list($iniObj, $finObj) = obtenerSemanaPorIndice($semana);
$inicioSemana = $iniObj->format('Y-m-d 00:00:00');
$finSemana    = $finObj->format('Y-m-d 23:59:59');
$iniISO       = $iniObj->format('Y-m-d');
$finISO       = $finObj->format('Y-m-d');

$msg = '';
$err = '';

/* ========================
   Acciones POST (edición por filas)
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['guardar_borrador','enviar_autorizacion','autorizar'])) {

  $accion = $_POST['accion'] ?? '';
  $estadoMasivo = null;
  if ($accion === 'guardar_borrador')    $estadoMasivo = 'borrador';
  if ($accion === 'enviar_autorizacion') $estadoMasivo = 'por_autorizar';
  if ($accion === 'autorizar')           $estadoMasivo = 'autorizado';

  $limpiar  = $_POST['limpiar'] ?? []; // limpiar[id_usuario] = "on"
  $ovData   = $_POST['ov'] ?? [];      // ov[id_usuario][campo]
  $notaRow  = $_POST['nota'] ?? [];    // nota[id_usuario]
  $estadoRow= $_POST['estado'] ?? [];  // estado[id_usuario]

  $sqlUpsert = "
    INSERT INTO nomina_overrides_semana
      (id_usuario, semana_inicio, semana_fin,
       sueldo_override, equipos_override, sims_override, pospago_override,
       ger_base_override, ger_pos_override, descuentos_override, ajuste_neto_extra,
       fuente, estado, nota)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
       sueldo_override=VALUES(sueldo_override),
       equipos_override=VALUES(equipos_override),
       sims_override=VALUES(sims_override),
       pospago_override=VALUES(pospago_override),
       ger_base_override=VALUES(ger_base_override),
       ger_pos_override=VALUES(ger_pos_override),
       descuentos_override=VALUES(descuentos_override),
       ajuste_neto_extra=VALUES(ajuste_neto_extra),
       fuente=VALUES(fuente),
       estado=VALUES(estado),
       nota=VALUES(nota)
  ";
  $stUp = $conn->prepare($sqlUpsert);

  $sqlDelete = "DELETE FROM nomina_overrides_semana WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?";
  $stDel = $conn->prepare($sqlDelete);

  // normalizador de números que permite NULL para overrides; para ajuste devolvemos "0" si viene vacío
  $toNull = function($v) {
    $v = trim((string)$v);
    if ($v === '') return null;
    $v = str_replace(',', '', $v);
    if ($v === '' || !is_numeric($v)) return null;
    return (string)$v; // como string para permitir NULL en bind; MySQL castea a DECIMAL
  };
  $toZero = function($v) {
    $v = trim((string)$v);
    if ($v === '') return '0';
    $v = str_replace(',', '', $v);
    if (!is_numeric($v)) return '0';
    return (string)$v;
  };

  foreach ($ovData as $idU => $campos) {
    $idU = (int)$idU;

    if (isset($limpiar[$idU])) {
      $stDel->bind_param("iss", $idU, $iniISO, $finISO);
      if (!$stDel->execute()) $err .= "Error al limpiar #$idU: ".$conn->error.". ";
      continue;
    }

    $sueldo     = $toNull($campos['sueldo'] ?? '');
    $equipos    = $toNull($campos['equipos'] ?? '');
    $sims       = $toNull($campos['sims'] ?? '');
    $pospago    = $toNull($campos['pospago'] ?? '');
    $ger_base   = $toNull($campos['ger_base'] ?? '');
    $ger_pos    = $toNull($campos['ger_pos'] ?? '');
    $descuentos = $toNull($campos['descuentos'] ?? '');

    // *** FIX: esta columna es NOT NULL en la tabla → mandar "0" si viene vacío
    $ajuste     = $toZero($campos['ajuste_neto_extra'] ?? '');

    $nota    = trim((string)($notaRow[$idU] ?? ''));
    $estado  = (string)($estadoMasivo ?? ($estadoRow[$idU] ?? 'borrador'));
    if (!in_array($estado, ['borrador','por_autorizar','autorizado'])) $estado = 'borrador';

    $src = 'RH';

    // 14 valores: i + 13 s
    $stUp->bind_param(
      "isssssssssssss",
      $idU, $iniISO, $finISO,
      $sueldo, $equipos, $sims, $pospago,
      $ger_base, $ger_pos, $descuentos, $ajuste,
      $src, $estado, $nota
    );

    if (!$stUp->execute()) {
      $err .= "Error al guardar #$idU: ".$conn->error.". ";
    }
  }

  if (!$err) {
    if ($accion==='guardar_borrador')        $msg = "✅ Overrides guardados como borrador.";
    elseif ($accion==='enviar_autorizacion') $msg = "✅ Overrides enviados a autorización.";
    elseif ($accion==='autorizar')           $msg = "✅ Overrides autorizados.";
    else                                     $msg = "✅ Cambios guardados.";
  }
}

/* ========================
   Importar CSV
======================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'import_csv') {
  if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
    $err = "No se pudo subir el CSV.";
  } else {
    $h = fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$h) { $err = "No se pudo abrir el CSV."; }
    else {
      $header = fgetcsv($h);
      if (!$header) { $err = "CSV vacío."; }
      else {
        $idx = [];
        foreach ($header as $k=>$v) { $idx[strtolower(trim($v))] = $k; }

        $need = ['id_usuario','semana_inicio','semana_fin'];
        foreach ($need as $k) if (!isset($idx[$k])) $err .= "Falta columna '$k'. ";

        if (!$err) {
          $sqlUpsert = "
            INSERT INTO nomina_overrides_semana
              (id_usuario, semana_inicio, semana_fin,
               sueldo_override, equipos_override, sims_override, pospago_override,
               ger_base_override, ger_pos_override, descuentos_override, ajuste_neto_extra,
               fuente, estado, nota)
            VALUES
              (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
               sueldo_override=VALUES(sueldo_override),
               equipos_override=VALUES(equipos_override),
               sims_override=VALUES(sims_override),
               pospago_override=VALUES(pospago_override),
               ger_base_override=VALUES(ger_base_override),
               ger_pos_override=VALUES(ger_pos_override),
               descuentos_override=VALUES(descuentos_override),
               ajuste_neto_extra=VALUES(ajuste_neto_extra),
               fuente=VALUES(fuente),
               estado=VALUES(estado),
               nota=VALUES(nota)
          ";
          $st = $conn->prepare($sqlUpsert);

          $rowNum = 1;
          $ins = 0; $fails = 0;

          $norm = function($v){
            $v = trim((string)$v);
            if ($v === '') return null;
            $v = str_replace(',', '', $v);
            return $v; // string -> MySQL castea a DECIMAL
          };
          $zero = function($v){
            $v = trim((string)$v);
            if ($v === '') return '0';
            $v = str_replace(',', '', $v);
            if (!is_numeric($v)) return '0';
            return $v;
          };

          while (($r = fgetcsv($h)) !== false) {
            $rowNum++;

            $idU    = isset($idx['id_usuario'])     ? (int)trim($r[$idx['id_usuario']] ?? '') : 0;
            $semIni = isset($idx['semana_inicio'])  ? trim($r[$idx['semana_inicio']] ?? '')  : '';
            $semFin = isset($idx['semana_fin'])     ? trim($r[$idx['semana_fin']] ?? '')     : '';
            $semIni = $semIni ?: $iniISO;
            $semFin = $semFin ?: $finISO;

            $sueldo     = isset($idx['sueldo'])            ? $norm($r[$idx['sueldo']] ?? '')            : null;
            $equipos    = isset($idx['equipos'])           ? $norm($r[$idx['equipos']] ?? '')           : null;
            $sims       = isset($idx['sims'])              ? $norm($r[$idx['sims']] ?? '')              : null;
            $pospago    = isset($idx['pospago'])           ? $norm($r[$idx['pospago']] ?? '')           : null;
            $ger_base   = isset($idx['ger_base'])          ? $norm($r[$idx['ger_base']] ?? '')          : null;
            $ger_pos    = isset($idx['ger_pos'])           ? $norm($r[$idx['ger_pos']] ?? '')           : null;
            $descuentos = isset($idx['descuentos'])        ? $norm($r[$idx['descuentos']] ?? '')        : null;

            // *** FIX CSV: si viene vacío → '0' (columna NOT NULL)
            $ajuste     = isset($idx['ajuste_neto_extra']) ? $zero($r[$idx['ajuste_neto_extra']] ?? '') : '0';

            $nota   = isset($idx['nota'])   ? trim($r[$idx['nota']] ?? '')   : '';
            $estado = isset($idx['estado']) ? trim($r[$idx['estado']] ?? '') : '';
            if (!in_array($estado, ['borrador','por_autorizar','autorizado'])) $estado = 'borrador';

            $src = 'RH';
            if (!$idU) { $fails++; continue; }

            $st->bind_param(
              "isssssssssssss",
              $idU, $semIni, $semFin,
              $sueldo, $equipos, $sims, $pospago,
              $ger_base, $ger_pos, $descuentos, $ajuste,
              $src, $estado, $nota
            );
            if ($st->execute()) $ins++; else { $fails++; }
          }
          fclose($h);
          $msg = "📥 CSV procesado. Filas OK: $ins. Errores: $fails.";
        }
      }
    }
  }
}

/* ========================
   Usuarios (excluye almacén/subdistribuidor)
======================== */
$subdistCol = null;
foreach (['subtipo_sucursal','subtipo','sub_tipo','tipo_subsucursal'] as $c) {
  $rs = $conn->query("SHOW COLUMNS FROM sucursales LIKE '$c'");
  if ($rs && $rs->num_rows > 0) { $subdistCol = $c; break; }
}
$where = "s.tipo_sucursal <> 'Almacen'";
if ($subdistCol) $where .= " AND (s.`$subdistCol` IS NULL OR LOWER(s.`$subdistCol`) <> 'subdistribuidor')";

$sqlUsuarios = "
  SELECT u.id, u.nombre, u.rol, u.sueldo, s.nombre AS sucursal, u.id_sucursal
  FROM usuarios u
  INNER JOIN sucursales s ON s.id=u.id_sucursal
  WHERE $where
  ORDER BY s.nombre, FIELD(u.rol,'Gerente','Ejecutivo'), u.nombre
";
$usuarios = $conn->query($sqlUsuarios);

// Overrides existentes de la semana
$ovMap = [];
$stOv = $conn->prepare("SELECT * FROM nomina_overrides_semana WHERE semana_inicio=? AND semana_fin=?");
$stOv->bind_param("ss", $iniISO, $finISO);
$stOv->execute();
$rOv = $stOv->get_result();
while ($row = $rOv->fetch_assoc()) {
  $ovMap[(int)$row['id_usuario']] = $row;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Overrides RH · Nómina semanal</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <style>
    body{ background:#f7f7fb; }
    .card-soft{ background:#fff; border:1px solid #eef2f7; border-radius:1rem; box-shadow:0 6px 18px rgba(16,24,40,.06); }
    .table-sm td, .table-sm th{ padding:.35rem .4rem; white-space:nowrap; vertical-align: middle; }
    .num{ text-align:right; font-variant-numeric: tabular-nums; width: 7.5rem; }
    .nota{ width: 16rem; }
    .estado{ width: 10rem; }
    .w-mini{ width: 2.4rem; text-align:center; }
    .sticky-head thead th{ position: sticky; top: 0; background: #fff; z-index: 5; }
  </style>
</head>
<body>
<div class="container py-4">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
      <span style="font-size:1.4rem">🛠️</span>
      <div>
        <h4 class="mb-0">Overrides RH · Nómina semanal</h4>
        <div class="text-muted small">Semana del <strong><?= $iniObj->format('d/m/Y') ?></strong> al <strong><?= $finObj->format('d/m/Y') ?></strong></div>
      </div>
    </div>
    <form method="get" class="d-flex align-items-center gap-2">
      <label class="form-label mb-0 small text-muted">Semana</label>
      <select name="semana" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <?php for ($i=0; $i<8; $i++):
            list($iniT, $finT) = obtenerSemanaPorIndice($i);
            $texto = "Del {$iniT->format('d/m/Y')} al {$finT->format('d/m/Y')}";
        ?>
          <option value="<?= $i ?>" <?= $i==$semana?'selected':'' ?>><?= $texto ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <?php if ($msg): ?><div class="alert alert-success py-2"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert alert-danger py-2"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <!-- Importar CSV -->
  <div class="card-soft p-3 mb-3">
    <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
      <input type="hidden" name="accion" value="import_csv">
      <div class="col-12 col-md-6">
        <label class="form-label small text-muted">CSV (id_usuario, semana_inicio, semana_fin, sueldo, equipos, sims, pospago, ger_base, ger_pos, descuentos, ajuste_neto_extra, nota, estado)</label>
        <input type="file" name="csv" class="form-control form-control-sm" accept=".csv" required>
      </div>
      <div class="col-12 col-md-3">
        <div class="text-muted small">Si no incluyes semana en el CSV, se usará: <?= $iniISO ?> → <?= $finISO ?></div>
      </div>
      <div class="col-12 col-md-3 text-end">
        <button class="btn btn-secondary btn-sm"><i class="bi bi-upload me-1"></i> Importar CSV</button>
      </div>
    </form>
  </div>

  <!-- Edición por filas -->
  <form method="post">
    <input type="hidden" name="accion" id="accionField" value="guardar_borrador">

    <div class="card-soft p-0">
      <div class="table-responsive sticky-head">
        <table class="table table-sm align-middle mb-0">
          <thead>
            <tr class="table-light">
              <th>Empleado</th>
              <th>Rol</th>
              <th>Sucursal</th>
              <th class="text-center">Limpiar</th>
              <th class="text-end">Sueldo</th>
              <th class="text-end">Eq.</th>
              <th class="text-end">SIMs</th>
              <th class="text-end">Pos.</th>
              <th class="text-end">PosG.</th>
              <th class="text-end">Ger.</th>
              <th class="text-end text-danger">Desc.</th>
              <th class="text-end">Ajuste Neto</th>
              <th>Estado</th>
              <th>Nota</th>
            </tr>
          </thead>
          <tbody>
          <?php while ($u = $usuarios->fetch_assoc()):
              $idU = (int)$u['id'];
              $ov  = $ovMap[$idU] ?? [];
              $val = function($k,$ov){ return isset($ov[$k]) && $ov[$k] !== null ? (string)$ov[$k] : ''; };
              $estadoSel = $ov['estado'] ?? 'borrador';
          ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= htmlspecialchars($u['nombre']) ?></div>
              </td>
              <td><span class="badge bg-secondary"><?= htmlspecialchars($u['rol']) ?></span></td>
              <td><?= htmlspecialchars($u['sucursal']) ?></td>
              <td class="text-center"><input type="checkbox" class="form-check-input w-mini" name="limpiar[<?= $idU ?>]"></td>

              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][sueldo]" value="<?= htmlspecialchars($val('sueldo_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][equipos]" value="<?= htmlspecialchars($val('equipos_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][sims]" value="<?= htmlspecialchars($val('sims_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][pospago]" value="<?= htmlspecialchars($val('pospago_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][ger_pos]" value="<?= htmlspecialchars($val('ger_pos_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][ger_base]" value="<?= htmlspecialchars($val('ger_base_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][descuentos]" value="<?= htmlspecialchars($val('descuentos_override',$ov)) ?>"></td>
              <td><input type="text" class="form-control form-control-sm num" name="ov[<?= $idU ?>][ajuste_neto_extra]" value="<?= htmlspecialchars($val('ajuste_neto_extra',$ov)) ?>"></td>

              <td>
                <select class="form-select form-select-sm estado" name="estado[<?= $idU ?>]">
                  <option value="borrador"      <?= $estadoSel==='borrador'?'selected':'' ?>>borrador</option>
                  <option value="por_autorizar" <?= $estadoSel==='por_autorizar'?'selected':'' ?>>por_autorizar</option>
                  <option value="autorizado"    <?= $estadoSel==='autorizado'?'selected':'' ?>>autorizado</option>
                </select>
              </td>
              <td><input type="text" class="form-control form-control-sm nota" name="nota[<?= $idU ?>]" maxlength="255" value="<?= htmlspecialchars($ov['nota'] ?? '') ?>"></td>
            </tr>
          <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="d-flex flex-wrap gap-2 justify-content-end mt-3">
      <button class="btn btn-outline-secondary btn-sm" type="button" onclick="setAccion('guardar_borrador'); this.form.submit();">
        <i class="bi bi-save me-1"></i> Guardar borrador
      </button>
      <button class="btn btn-warning btn-sm" type="button" onclick="setAccion('enviar_autorizacion'); this.form.submit();">
        <i class="bi bi-send-check me-1"></i> Enviar a autorización
      </button>
      <button class="btn btn-success btn-sm" type="button" onclick="if(confirm('¿Autorizar overrides de esta semana?')){ setAccion('autorizar'); this.form.submit(); }">
        <i class="bi bi-check2-circle me-1"></i> Autorizar
      </button>
    </div>
  </form>

  <div class="text-muted small mt-3">
    * Deja en blanco un campo para respetar el cálculo automático.<br>
    * “Ajuste Neto” vacío se interpreta como <strong>0</strong> (la columna es NOT NULL).<br>
    * “Limpiar” borra por completo la fila override del usuario en la semana seleccionada.
  </div>

</div>

<script>
function setAccion(v){ document.getElementById('accionField').value = v; }
</script>
</body>
</html>
