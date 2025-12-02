<?php
// venta_accesorios.php — Venta de Accesorios (REGALO atado a TAG de equipo)
// - Modo regalo: requiere TAG de venta de equipo; fuerza $0 y oculta campos de pago.
// - Solo 1 accesorio, cantidad=1.
// - Whitelist: accesorios_regalo_modelos (activo=1).
// - Bloqueo venta normal si vender=0 (solo-regalo).
// - Prod-safe: vender casteado, estatus normalizado y JS Set numérico.
// - Modal de CONFIRMACIÓN previa al envío (resumen de líneas/pago).
// - NUEVO: Selección de CLIENTE (buscar / crear) usando ajax_clientes_buscar_modal.php y ajax_crear_cliente.php
//          Se envía id_cliente + nombre_cliente + telefono (oculto) a procesar_venta_accesorios.php

// Anti cache (especialmente útil en prod con proxies/opcache)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: index.php'); exit(); }

require_once __DIR__.'/db.php';
require_once __DIR__.'/navbar.php';
date_default_timezone_set('America/Mexico_City');

function h($s){ return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function normalizar_tag($s){ $s = strtoupper(trim((string)$s)); return preg_replace('/\s+/', ' ', $s); }

/**
 * Genera un TAG aleatorio de 7 caracteres (letras mayúsculas + dígitos),
 * pensado para TAG de accesorio. Es solo valor por defecto y el usuario puede editarlo.
 */
function generar_tag_accesorio(): string {
  $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // quitamos 0,1,I,O para evitar confusiones
  $len   = strlen($chars);
  $tag   = '';
  for ($i = 0; $i < 7; $i++) {
    $tag .= $chars[random_int(0, $len - 1)];
  }
  return $tag;
}

/** Busca venta de equipo por TAG, regresa [id_venta, id_sucursal] o [0,0] (referencia futura) */
function buscar_venta_equipo_por_tag(mysqli $conn, string $tag): array {
  $tag = normalizar_tag($tag);
  if ($tag === '') return [0,0];
  $sql = "SELECT v.id, v.id_sucursal FROM ventas v WHERE UPPER(TRIM(v.tag)) = UPPER(TRIM(?)) LIMIT 1";
  $st = $conn->prepare($sql);
  if (!$st) return [0,0];
  $st->bind_param('s', $tag);
  if (!$st->execute()) return [0,0];
  $r = $st->get_result()->fetch_row();
  return $r ? [(int)$r[0], (int)$r[1]] : [0,0];
}

$ROL         = $_SESSION['rol'] ?? '';
$ID_USUARIO  = (int)($_SESSION['id_usuario'] ?? 0);
$ID_SUCURSAL = (int)($_SESSION['id_sucursal'] ?? 0);
if (!in_array($ROL, ['Ejecutivo','Gerente','Admin','GerenteZona','Logistica'], true)) { header('Location: 403.php'); exit(); }

/* --- Nombre de sucursal con cache en sesión --- */
$sucursalNombre = trim($_SESSION['sucursal_nombre'] ?? '');
if ($sucursalNombre === '' && $ID_SUCURSAL > 0) {
  if ($st = $conn->prepare("SELECT nombre FROM sucursales WHERE id=? LIMIT 1")) {
    $st->bind_param('i', $ID_SUCURSAL);
    $st->execute();
    if ($row = $st->get_result()->fetch_assoc()) {
      $sucursalNombre = $row['nombre'] ?: '';
      if ($sucursalNombre !== '') $_SESSION['sucursal_nombre'] = $sucursalNombre;
    }
  }
}
if ($sucursalNombre === '') $sucursalNombre = 'Sucursal #'.$ID_SUCURSAL;

/* --- TAG auto-generado para accesorios (editable) --- */
$TAG_AUTO_ACCESORIO = generar_tag_accesorio();

/* --- Whitelist de accesorios elegibles para REGALO --- */
$regaloPermitidos = [];
$tblCheck = $conn->query("SHOW TABLES LIKE 'accesorios_regalo_modelos'");
if ($tblCheck && $tblCheck->num_rows > 0) {
  if ($rs = $conn->query("SELECT id_producto FROM accesorios_regalo_modelos WHERE activo=1")) {
    while ($r = $rs->fetch_assoc()) $regaloPermitidos[] = (int)$r['id_producto'];
  }
}
$regaloPermitidos = array_values(array_unique(array_filter($regaloPermitidos)));

/* --- IDs Solo-REGALO (vender=0) robusto a VARCHAR/NULL --- */
$soloRegaloIds = [];
if ($tblCheck && $tblCheck->num_rows > 0) {
  $sqlSolo = "SELECT id_producto
                FROM accesorios_regalo_modelos
               WHERE activo=1
                 AND CAST(COALESCE(vender, 1) AS UNSIGNED)=0";
  if ($rs2 = $conn->query($sqlSolo)) {
    while ($r2 = $rs2->fetch_assoc()) $soloRegaloIds[] = (int)$r2['id_producto'];
  }
}
$soloRegaloIds = array_values(array_unique(array_filter($soloRegaloIds)));

/* --- Catálogo con stock disponible SOLO en la sucursal actual --- */
/*    Regla fija: aunque seas Admin/Logistica/GerenteZona, la venta solo usa
      el inventario de la sucursal donde estás logueado ($ID_SUCURSAL). */
$sql = "
  SELECT 
    p.id AS id_producto,
    TRIM(CONCAT(COALESCE(p.marca,''),' ',COALESCE(p.modelo,''),' ',COALESCE(p.color,''))) AS nombre,
    COALESCE(p.precio_lista,0) AS precio_sugerido,
    SUM(
      CASE
        WHEN UPPER(TRIM(i.estatus))='DISPONIBLE'
        THEN COALESCE(i.cantidad,1)
        ELSE 0
      END
    ) AS stock_disp
  FROM productos p
  JOIN inventario i ON i.id_producto = p.id
  WHERE
    (
      UPPER(COALESCE(p.tipo_producto,'')) IN ('ACCESORIO','ACCESORIOS')
      OR (COALESCE(p.imei1,'')='' AND COALESCE(p.imei2,'')='')
    )
    AND i.id_sucursal = ?
  GROUP BY p.id, p.marca, p.modelo, p.color, p.precio_lista
  HAVING stock_disp > 0
  ORDER BY nombre ASC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) { die('Error preparando SQL de accesorios: '.$conn->error); }
$stmt->bind_param('i', $ID_SUCURSAL);
$stmt->execute();
$res = $stmt->get_result();
$accesorios = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Venta de Accesorios</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:linear-gradient(135deg,#f6f9fc 0%,#edf2f7 100%)}
  .card-ghost{backdrop-filter:saturate(140%) blur(6px); border:1px solid rgba(0,0,0,.06); box-shadow:0 10px 25px rgba(0,0,0,.06)}
  .table thead th{position:sticky;top:0;background:#fff;z-index:1}
  .money{text-align:right}
  .total-chip{font-size:1.1rem;padding:.35rem .7rem;border-radius:.8rem;background:#0d6efd1a;border:1px solid #0d6efd33}
  .badge-soft{background:#6c757d14;border:1px solid #6c757d2e}
  .section-title{font-weight:700; letter-spacing:.2px}
  .modal-xxl{--bs-modal-width:min(1100px,96vw)}
  .ticket-frame{width:100%;height:80vh;border:0;border-radius:.75rem;background:#fff}
  .hidden-fields{display:none !important}
  /* Portal buscador */
  .fast-wrap{position:relative}
  .fast-input{padding-right:34px}
  .fast-kbd{position:absolute; right:8px; top:8px; font-size:.75rem; color:#6c757d}
  .fast-portal{position:fixed;z-index:5000;background:#fff;border:1px solid #dee2e6;border-radius:0 0 .5rem .5rem;box-shadow:0 12px 24px rgba(0,0,0,.12);max-height:260px;overflow:auto;display:none}
  .fast-item{padding:.45rem .6rem; cursor:pointer}
  .fast-item:hover,.fast-item.active{background:#0d6efd10}
  .fast-item.blocked{opacity:.5; cursor:not-allowed}
  .badge-solo{background:#ffc1071a;border:1px solid #ffc10755;color:#b58100}
  .badge-eleg{background:#1987541a;border:1px solid #19875455;color:#0f5132}
  .badge-noe{background:#6c757d1a;border:1px solid #6c757d55;color:#495057}
  .cliente-summary-label {
    font-size:.8rem;
    text-transform:uppercase;
    letter-spacing:.08em;
    color:#64748b;
    margin-bottom:.25rem;
  }
  .cliente-summary-main {
    font-weight:600;
    font-size:1.05rem;
    color:#111827;
  }
  .cliente-summary-sub {
    font-size:.9rem;
    color:#6b7280;
  }
</style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <div>
      <h3 class="mb-0">Venta de Accesorios</h3>
      <span class="badge rounded-pill text-secondary badge-soft">Sucursal: <?= h($sucursalNombre) ?></span>
    </div>
    <div><a href="dashboard_unificado.php" class="btn btn-outline-secondary btn-sm">Volver</a></div>
  </div>

  <form id="frmVenta" action="procesar_venta_accesorios.php" method="post" class="card card-ghost p-3">
    <input type="hidden" name="id_sucursal" value="<?= (int)$ID_SUCURSAL ?>">
    <input type="hidden" name="es_regalo" id="es_regalo" value="0">

    <!-- 🔗 Cliente seleccionado -->
    <input type="hidden" name="id_cliente" id="id_cliente" value="">
    <input type="hidden" name="nombre_cliente" id="nombre_cliente" value="">
    <!-- name="telefono" para compatibilidad con backend -->
    <input type="hidden" name="telefono" id="telefono_cliente" value="">
    <input type="hidden" name="correo_cliente" id="correo_cliente" value="">

    <!-- Encabezado -->
    <div class="row g-3 align-items-end">
      <div class="col-md-3">
        <label class="form-label section-title">TAG (venta de accesorios)</label>
        <!-- TAG autogenerado pero editable -->
        <input type="text" name="tag" id="tag" class="form-control" maxlength="50" required
               value="<?= h($TAG_AUTO_ACCESORIO) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label section-title">Sucursal</label>
        <input type="text" class="form-control" value="<?=h($sucursalNombre)?>" readonly>
      </div>

      <div class="col-12">
        <div class="form-check mt-2">
          <input class="form-check-input" type="checkbox" id="chkRegalo">
          <label class="form-check-label" for="chkRegalo">
            Venta con <strong>regalo</strong> (requiere TAG de venta de equipo).
          </label>
        </div>
      </div>

      <!-- Solo visible en REGALO -->
      <div class="col-md-4 d-none" id="grpTagEquipo">
        <label class="form-label section-title">TAG de la venta de equipo</label>
        <input type="text" name="tag_equipo" id="tag_equipo" class="form-control" maxlength="50" placeholder="Ej. LUGA-241101-ABC">
        <div class="form-text">Se valida que el equipo comprado habilite este regalo y que no se haya usado antes.</div>
      </div>
    </div>

    <!-- Cliente -->
    <hr class="my-3">

    <div class="section-title">Datos del cliente</div>
    <div class="row g-3 mb-3">
      <div class="col-md-8">
        <div class="border rounded-3 p-3 bg-light">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <div class="cliente-summary-label">Cliente seleccionado</div>
              <div class="cliente-summary-main" id="cliente_resumen_nombre">
                Ninguno seleccionado
              </div>
              <div class="cliente-summary-sub" id="cliente_resumen_detalle">
                Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.
              </div>
            </div>
            <div class="text-end">
              <span class="badge rounded-pill text-bg-secondary" id="badge_tipo_cliente">
                Sin cliente
              </span>
            </div>
          </div>
        </div>
      </div>
      <div class="col-md-4 d-flex align-items-center justify-content-md-end">
        <button type="button" class="btn btn-outline-primary w-100" id="btn_open_modal_clientes">
          Buscar / crear cliente
        </button>
      </div>
    </div>

    <hr class="my-3">

    <!-- Líneas -->
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0 section-title">Líneas de accesorios</h5>
      <button type="button" class="btn btn-primary btn-sm" id="btnAdd">Agregar línea</button>
    </div>

    <div class="table-responsive">
      <table class="table table-sm align-middle" id="tblLineas">
        <thead class="table-light">
          <tr>
            <th style="width:42%">Accesorio</th>
            <th style="width:10%" class="text-center">Stock</th>
            <th style="width:12%">Cantidad</th>
            <th style="width:16%">Precio</th>
            <th style="width:16%" class="text-end">Subtotal</th>
            <th style="width:8%"></th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
    </div>

    <div class="row g-3 mt-2">
      <div class="col-md-7">
        <label class="form-label">Comentarios</label>
        <input type="text" name="comentarios" id="comentarios" class="form-control" maxlength="255" placeholder="Opcional…">
      </div>
      <div class="col-md-5 d-flex align-items-end justify-content-end">
        <div class="total-chip"><strong>Total: <span id="lblTotal">$0.00</span></strong></div>
      </div>
    </div>

    <hr class="my-3">

    <!-- Pago (se oculta en REGALO) -->
    <div id="pagosCampos" class="row g-3">
      <div class="col-md-3">
        <label class="form-label section-title">Forma de pago</label>
        <select class="form-select" name="forma_pago" id="formaPago" required>
          <option value="Efectivo">Efectivo</option>
          <option value="Tarjeta">Tarjeta</option>
          <option value="Mixto">Mixto</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label section-title">Efectivo</label>
        <input type="number" step="0.01" min="0" name="efectivo" id="inpEfectivo" class="form-control" value="0">
      </div>
      <div class="col-md-3">
        <label class="form-label section-title">Tarjeta</label>
        <input type="number" step="0.01" min="0" name="tarjeta" id="inpTarjeta" class="form-control" value="0">
      </div>
    </div>

    <!-- Acciones -->
    <div class="row g-3 mt-1">
      <div class="col-md-3 ms-auto d-flex align-items-end">
        <button class="btn btn-success w-100" type="submit">Guardar venta</button>
      </div>
    </div>
  </form>
</div>

<!-- Modal CONFIRMAR venta -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Confirmar venta de accesorios</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <span class="badge bg-primary-subtle text-primary" id="cfModo">Modo: Venta normal</span>
          <span class="badge bg-secondary-subtle text-secondary">Sucursal: <?= h($sucursalNombre) ?></span>
        </div>
        <div class="row g-2">
          <div class="col-md-4"><strong>TAG:</strong> <span id="cfTag">—</span></div>
          <div class="col-md-4"><strong>Cliente:</strong> <span id="cfCliente">—</span></div>
          <div class="col-md-4"><strong>Teléfono:</strong> <span id="cfTelefono">—</span></div>
        </div>

        <div class="mt-3 table-responsive">
          <table class="table table-sm">
            <thead class="table-light">
              <tr>
                <th>Accesorio</th>
                <th class="text-center">Cant</th>
                <th class="text-end">Precio</th>
                <th class="text-end">Subtotal</th>
              </tr>
            </thead>
            <tbody id="cfBody"></tbody>
            <tfoot>
              <tr>
                <th colspan="3" class="text-end">Total</th>
                <th class="text-end" id="cfTotal">$0.00</th>
              </tr>
            </tfoot>
          </table>
        </div>

        <div id="cfPagoWrap" class="row g-2 mt-2">
          <div class="col-md-4"><strong>Forma de pago:</strong> <span id="cfForma">—</span></div>
          <div class="col-md-4"><strong>Efectivo:</strong> <span id="cfEf">$0.00</span></div>
          <div class="col-md-4"><strong>Tarjeta:</strong> <span id="cfTa">$0.00</span></div>
        </div>

        <div id="cfTagEqWrap" class="mt-2 d-none">
          <strong>TAG de venta de equipo:</strong> <span id="cfTagEq">—</span>
        </div>

        <div class="small text-muted mt-2">
          Revisa que los datos sean correctos. Al confirmar se registrará la venta y se generará el ticket.
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Volver y corregir</button>
        <button class="btn btn-success" id="btnConfirmarVenta">Confirmar y guardar</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal ticket -->
<div class="modal fade" id="ticketModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-xxl">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Ticket de venta</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <iframe id="ticketFrame" class="ticket-frame" src="about:blank"></iframe>
      </div>
      <div class="modal-footer">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cerrar</button>
        <button class="btn btn-primary" id="btnPrintTicket">Imprimir</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal de clientes: buscar / seleccionar / crear -->
<div class="modal fade" id="modalClientes" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-light">
        <h5 class="modal-title">
          Buscar o crear cliente
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <!-- Buscador -->
        <div class="mb-3">
          <label class="form-label">Buscar por nombre, teléfono o código de cliente</label>
          <div class="input-group">
            <input type="text" class="form-control" id="cliente_buscar_q" placeholder="Ej. LUCIA, 5587967699 o CL-40-000001">
            <button class="btn btn-primary" type="button" id="btn_buscar_modal">
              Buscar
            </button>
          </div>
          <div class="form-text">
            La búsqueda se realiza a nivel <strong>global</strong>.
          </div>
        </div>

        <hr>

        <!-- Resultados -->
        <div class="mb-2 d-flex justify-content-between align-items-center">
          <span class="fw-semibold">Resultados</span>
          <span class="text-muted small" id="lbl_resultados_clientes">Sin buscar aún.</span>
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle">
            <thead class="table-light">
              <tr>
                <th>Código</th>
                <th>Nombre</th>
                <th>Teléfono</th>
                <th>Correo</th>
                <th>Fecha alta</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="tbody_clientes">
              <!-- JS -->
            </tbody>
          </table>
        </div>

        <hr>

        <!-- Crear nuevo cliente -->
        <div class="mb-2">
          <button class="btn btn-outline-success btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#collapseNuevoCliente">
            Crear nuevo cliente
          </button>
        </div>
        <div class="collapse" id="collapseNuevoCliente">
          <div class="border rounded-3 p-3 bg-light">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label">Nombre completo *</label>
                <input type="text" class="form-control" id="nuevo_nombre">
              </div>
              <div class="col-md-4">
                <label class="form-label">Teléfono (10 dígitos) *</label>
                <input type="text" class="form-control" id="nuevo_telefono">
              </div>
              <div class="col-md-4">
                <label class="form-label">Correo</label>
                <input type="email" class="form-control" id="nuevo_correo">
              </div>
            </div>
            <div class="mt-3 text-end">
              <button type="button" class="btn btn-success" id="btn_guardar_nuevo_cliente">
                Guardar y seleccionar
              </button>
            </div>
            <div class="form-text">
              El cliente se creará en la sucursal actual.
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">
          Cerrar
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Datos del backend
const accesorios = <?php echo json_encode($accesorios, JSON_UNESCAPED_UNICODE); ?>;
// Fuerza numérico por si PHP entrega strings en prod
const REGALO_PERMITIDOS = new Set((<?php echo json_encode($regaloPermitidos, JSON_UNESCAPED_UNICODE); ?>).map(Number));
const SOLO_REGALO       = new Set((<?php echo json_encode($soloRegaloIds,   JSON_UNESCAPED_UNICODE); ?>).map(Number));

const tbody       = document.querySelector('#tblLineas tbody');
const lblTotal    = document.getElementById('lblTotal');
const formaPago   = document.getElementById('formaPago');
const inpEf       = document.getElementById('inpEfectivo');
const inpTa       = document.getElementById('inpTarjeta');
const pagosCampos = document.getElementById('pagosCampos');
const chkRegalo   = document.getElementById('chkRegalo');
const esRegaloInp = document.getElementById('es_regalo');
const grpTagEquipo= document.getElementById('grpTagEquipo');
const tagEquipo   = document.getElementById('tag_equipo');

// Campos cliente (hidden + resumen)
const idClienteInput   = document.getElementById('id_cliente');
const nombreCliente    = document.getElementById('nombre_cliente');
const telClienteInput  = document.getElementById('telefono_cliente');
const correoCliente    = document.getElementById('correo_cliente');
const lblCliNombre     = document.getElementById('cliente_resumen_nombre');
const lblCliDetalle    = document.getElementById('cliente_resumen_detalle');
const badgeTipoCliente = document.getElementById('badge_tipo_cliente');

// Encabezado para confirm
const tagInput    = document.getElementById('tag');
const comentarios = document.getElementById('comentarios');

// Confirm modal elements
let confirmModal = null;
const cfModo   = document.getElementById('cfModo');
const cfTag    = document.getElementById('cfTag');
const cfCliente= document.getElementById('cfCliente');
const cfTelefono = document.getElementById('cfTelefono');
const cfBody   = document.getElementById('cfBody');
const cfTotal  = document.getElementById('cfTotal');
const cfForma  = document.getElementById('cfForma');
const cfEf     = document.getElementById('cfEf');
const cfTa     = document.getElementById('cfTa');
const cfPagoWrap   = document.getElementById('cfPagoWrap');
const cfTagEqWrap  = document.getElementById('cfTagEqWrap');
const cfTagEq      = document.getElementById('cfTagEq');
const cfAlertRegalo = document.getElementById('cfAlertRegalo') || null;

// Modal clientes
let modalClientes = null;

function money(n){ return new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n||0)); }
function isRegalo(){ return chkRegalo.checked === true; }

async function fetchJSON(url, options){
  const resp = await fetch(url, options);
  const text = await resp.text();
  try { return JSON.parse(text); }
  catch(e){ throw new Error(text || 'Respuesta inválida'); }
}

/* ======== Cliente: helpers ======== */
function limpiarCliente(){
  idClienteInput.value = '';
  nombreCliente.value  = '';
  telClienteInput.value = '';
  correoCliente.value  = '';

  lblCliNombre.textContent = 'Ninguno seleccionado';
  lblCliDetalle.innerHTML = 'Usa el botón <strong>Buscar / crear cliente</strong> para seleccionar uno.';
  badgeTipoCliente.classList.remove('text-bg-success');
  badgeTipoCliente.classList.add('text-bg-secondary');
  badgeTipoCliente.textContent = 'Sin cliente';
}

function setClienteSeleccionado(c){
  const cid   = c.id || c.id_cliente || 0;
  const nom   = c.nombre || '';
  const tel   = c.telefono || '';
  const mail  = c.correo || c.email || '';
  const codigo= c.codigo_cliente || '';

  idClienteInput.value   = cid ? String(cid) : '';
  nombreCliente.value    = nom;
  telClienteInput.value  = tel;
  correoCliente.value    = mail;

  lblCliNombre.textContent = nom || '(Sin nombre)';
  const detParts = [];
  if (tel)    detParts.push('Tel: ' + tel);
  if (codigo) detParts.push('Código: ' + codigo);
  if (mail)   detParts.push('Correo: ' + mail);
  lblCliDetalle.textContent = detParts.join(' · ') || 'Sin más datos.';

  badgeTipoCliente.classList.remove('text-bg-secondary');
  badgeTipoCliente.classList.add('text-bg-success');
  badgeTipoCliente.textContent = 'Cliente seleccionado';
}

/* -------- Buscador tipo “portal” -------- */
let FAST_PORTAL=null, FAST_OWNER=null, FAST_SELECT=null;
function ensurePortal(){ if(FAST_PORTAL) return; FAST_PORTAL=document.createElement('div'); FAST_PORTAL.className='fast-portal'; document.body.appendChild(FAST_PORTAL);
  document.addEventListener('click', (e)=>{ if(FAST_PORTAL.style.display!=='block') return; if(FAST_OWNER && (e.target===FAST_OWNER || FAST_PORTAL.contains(e.target))) return; closePortal();}, true);
  window.addEventListener('scroll', ()=>repositionPortal(), true); window.addEventListener('resize', ()=>repositionPortal());
}
function openPortal(forInput,selectEl){ ensurePortal(); FAST_OWNER=forInput; FAST_SELECT=selectEl; renderPortal(forInput.value); repositionPortal(); FAST_PORTAL.style.display='block'; }
function closePortal(){ if(FAST_PORTAL) FAST_PORTAL.style.display='none'; FAST_OWNER=FAST_SELECT=null; }
function repositionPortal(){ if(!FAST_PORTAL||!FAST_OWNER) return; const r=FAST_OWNER.getBoundingClientRect(); FAST_PORTAL.style.left=`${r.left}px`; FAST_PORTAL.style.top=`${r.bottom}px`; FAST_PORTAL.style.width=`${r.width}px`; }
function renderPortal(q){
  const term=(q||'').trim().toLowerCase();
  const rows=accesorios.filter(a=>term===''||String(a.nombre||'').toLowerCase().includes(term)).slice(0,150);
  FAST_PORTAL.innerHTML = rows.length ? rows.map(a=>{
    const idNum = Number(a.id_producto);
    const eligRegalo = REGALO_PERMITIDOS.has(idNum);
    const soloRegalo = SOLO_REGALO.has(idNum);
    const blocked = (!isRegalo() && soloRegalo) || (isRegalo() && !eligRegalo);
    let tag = '';
    if (isRegalo()) {
      tag = eligRegalo ? '<span class="badge badge-eleg ms-2">Elegible</span>'
                       : '<span class="badge badge-noe ms-2">No elegible</span>';
    } else if (soloRegalo) {
      tag = '<span class="badge badge-solo ms-2">Solo regalo</span>';
    }
    return `<div class="fast-item ${blocked?'blocked':''}" data-id="${idNum}" data-precio="${Number(a.precio_sugerido||0)}" data-stock="${Number(a.stock_disp||0)}" data-elig="${eligRegalo?1:0}" data-solo="${soloRegalo?1:0}">${a.nombre} ${tag}</div>`;
  }).join('') : `<div class="fast-item text-muted">Sin coincidencias</div>`;
}
function buildFastSelector(td, selectEl){
  td.classList.add('fast-wrap');
  const fast=document.createElement('input'); fast.type='text'; fast.className='form-control fast-input'; fast.placeholder='Buscar accesorio…'; fast.autocomplete='off';
  const kbd=document.createElement('span'); kbd.className='fast-kbd'; kbd.textContent='⌄';
  td.prepend(fast); td.appendChild(kbd);
  fast.addEventListener('focus', ()=>openPortal(fast,selectEl));
  fast.addEventListener('input', ()=>renderPortal(fast.value));
  fast.addEventListener('keydown', (e)=>{
    if(FAST_PORTAL?.style.display!=='block') return;
    const items=[...FAST_PORTAL.querySelectorAll('.fast-item[data-id]')]; const cur=FAST_PORTAL.querySelector('.fast-item.active'); let idx=items.indexOf(cur);
    if(e.key==='ArrowDown'){e.preventDefault(); idx=Math.min(idx+1, items.length-1);}
    if(e.key==='ArrowUp'){e.preventDefault(); idx=Math.max(idx-1, 0);}
    if(e.key==='Enter'&&cur){e.preventDefault(); cur.click(); return;}
    if(e.key==='Escape'){closePortal(); return;}
    if(idx>=0&&items[idx]){items.forEach(x=>x.classList.remove('active')); items[idx].classList.add('active'); items[idx].scrollIntoView({block:'nearest'});}
  });
  ensurePortal();
  FAST_PORTAL.addEventListener('click', (e)=>{
    if(FAST_PORTAL.style.display!=='block') return;
    const it=e.target.closest('.fast-item[data-id]'); if(!it) return;
    if(it.classList.contains('blocked')) return; // bloqueo real
    const id=Number(it.dataset.id||0);
    const precio=Number(it.dataset.precio||0), stock=Number(it.dataset.stock||0);
    const eleg = Number(it.dataset.elig||0)===1;
    const solo = Number(it.dataset.solo||0)===1;
    selectEl.value=String(id);
    const tr=selectEl.closest('tr');
    tr.querySelector('.stock').textContent=stock;
    const priceInput=tr.querySelector('.money');
    if(!isRegalo()&&precio>0) priceInput.value=precio.toFixed(2);
    fast.value=it.textContent.replace(/Elegible|No elegible|Solo regalo/i,'').trim();
    tr.dataset.elegible = eleg ? '1' : '0';
    tr.dataset.soloregalo = solo ? '1' : '0';
    closePortal(); recalc();
  });
}

/* -------- Filas -------- */
function addRow(){
  const tr=document.createElement('tr');
  tr.innerHTML=`
    <td>
      <select name="linea_id_producto[]" class="form-select selProducto d-none" tabindex="-1" aria-hidden="true">
        <option value="">—</option>
        ${accesorios.map(a=>`<option value="${Number(a.id_producto)}" data-precio="${Number(a.precio_sugerido||0)}" data-stock="${Number(a.stock_disp||0)}">${a.nombre}</option>`).join('')}
      </select>
    </td>
    <td class="text-center stock">0</td>
    <td><input type="number" name="linea_cantidad[]" class="form-control cant" min="1" value="1" required></td>
    <td><input type="number" name="linea_precio[]" class="form-control money" step="0.01" min="0" value="0" required></td>
    <td class="money subtotal text-end">$0.00</td>
    <td class="text-end"><button type="button" class="btn btn-outline-danger btn-sm btnDel">Quitar</button></td>`;
  tbody.appendChild(tr);
  const tdAcc=tr.children[0]; const sel=tdAcc.querySelector('select.selProducto');
  buildFastSelector(tdAcc, sel);
  updateRowForRegalo(tr);
}
document.getElementById('btnAdd').addEventListener('click', ()=>{
  if (isRegalo() && tbody.children.length>=1){ alert('En modo regalo solo puedes agregar 1 accesorio.'); return; }
  addRow();
});

/* -------- Totales -------- */
function recalc(){
  let total=0;
  tbody.querySelectorAll('tr').forEach(tr=>{
    const cant=Number(tr.querySelector('.cant').value||0);
    const priceEl=tr.querySelector('.money');
    let price=Number(priceEl.value||0);
    if(isRegalo()){ price=0; priceEl.value='0.00'; }
    const sub=cant*price; total+=sub; tr.querySelector('.subtotal').textContent=money(sub);
  });
  if(isRegalo()) total=0;
  lblTotal.textContent=money(total);
  syncPagos();
}
tbody.addEventListener('input', e=>{
  if(e.target.classList.contains('cant')||e.target.classList.contains('money')) recalc();
});
tbody.addEventListener('click', e=>{
  if(e.target.classList.contains('btnDel')){ e.target.closest('tr').remove(); recalc(); }
});

/* -------- Pagos -------- */
function syncPagos(){
  const total = Number(lblTotal.textContent.replace(/[^0-9.]/g,'')) || 0;
  if(isRegalo()){
    pagosCampos.classList.add('hidden-fields');
    formaPago.value='Efectivo'; inpEf.value='0.00'; inpTa.value='0.00'; inpEf.readOnly=true; inpTa.readOnly=true; return;
  }
  pagosCampos.classList.remove('hidden-fields');
  switch(formaPago.value){
    case 'Efectivo': inpEf.value=total.toFixed(2); inpTa.value='0.00'; inpEf.readOnly=false; inpTa.readOnly=true; break;
    case 'Tarjeta':  inpEf.value='0.00'; inpTa.value=total.toFixed(2); inpEf.readOnly=true;  inpTa.readOnly=false; break;
    case 'Mixto':    inpEf.readOnly=false; inpTa.readOnly=false; break;
  }
}
formaPago.addEventListener('change', syncPagos);

/* -------- Modo REGALO -------- */
function updateRowForRegalo(tr){
  const priceInput=tr.querySelector('.money');
  const cantInput =tr.querySelector('.cant');
  if(isRegalo()){
    priceInput.value='0.00'; priceInput.readOnly=true;
    cantInput.value='1';     cantInput.min='1'; cantInput.max='1'; cantInput.readOnly=true;
  }else{
    priceInput.readOnly=false; cantInput.readOnly=false; cantInput.removeAttribute('max');
  }
}
function applyRegaloModeToAllRows(){ tbody.querySelectorAll('tr').forEach(tr=>updateRowForRegalo(tr)); }
chkRegalo.addEventListener('change', ()=>{
  esRegaloInp.value = isRegalo() ? '1' : '0';
  grpTagEquipo.classList.toggle('d-none', !isRegalo());
  if(isRegalo() && tbody.children.length>1){
    [...tbody.querySelectorAll('tr')].slice(1).forEach(tr=>tr.remove());
  }
  applyRegaloModeToAllRows(); recalc();
});

/* -------- Validaciones -------- */
function validarLineasBasico(){
  if (tbody.children.length === 0){ alert('Agrega al menos una línea.'); return false; }
  let ok=true, msg='';
  tbody.querySelectorAll('tr').forEach(tr=>{
    const sel=tr.querySelector('.selProducto');
    const stock=Number(tr.querySelector('.stock').textContent||0);
    const cant=Number(tr.querySelector('.cant').value||0);
    const price=Number(tr.querySelector('.money').value||-1);
    const eleg = tr.dataset.elegible==='1';
    const solo = tr.dataset.soloregalo==='1';
    if(!sel.value){ ok=false; msg='Selecciona el accesorio.'; return; }
    if(cant<1 || cant>stock){ ok=false; msg='Cantidad inválida o mayor al stock.'; return; }
    if(isRegalo()){
      if(!eleg){ ok=false; msg='El accesorio no es elegible para regalo.'; return; }
      if(price!==0){ ok=false; msg='En regalo, el precio debe ser $0.'; return; }
    }else{
      if(solo){ ok=false; msg='Este accesorio es solo para regalo.'; return; }
      if(price<0){ ok=false; msg='Precio inválido.'; return; }
    }
  });
  if(!ok){ alert(msg||'Revisa las líneas.'); return false; }
  if(!isRegalo()){
    const total=Number(lblTotal.textContent.replace(/[^0-9.]/g,''))||0;
    const ef=Number(inpEf.value||0), ta=Number(inpTa.value||0);
    if(formaPago.value==='Mixto' && (ef+ta).toFixed(2)!==total.toFixed(2)) { alert('En pago Mixto, Efectivo + Tarjeta debe igualar el Total.'); return false; }
    if(formaPago.value==='Efectivo' && ef.toFixed(2)!==total.toFixed(2))    { alert('Efectivo debe igualar el Total.'); return false; }
    if(formaPago.value==='Tarjeta'  && ta.toFixed(2)!==total.toFixed(2))    { alert('Tarjeta debe igualar el Total.'); return false; }
  }
  return true;
}

/* -------- Armado de CONFIRMACIÓN -------- */
function armarConfirmacion(){
  // Encabezado
  cfModo.textContent = isRegalo() ? 'Modo: Regalo' : 'Modo: Venta normal';
  cfModo.className = 'badge ' + (isRegalo() ? 'bg-success-subtle text-success' : 'bg-primary-subtle text-primary');
  cfTag.textContent = (tagInput.value || '—');
  cfCliente.textContent = (nombreCliente.value || '—');
  cfTelefono.textContent = (telClienteInput.value || '—');

  // Líneas
  cfBody.innerHTML = '';
  let total = 0;
  [...tbody.querySelectorAll('tr')].forEach(tr=>{
    const nombre = tr.querySelector('.fast-wrap input')?.value || 'Accesorio';
    const cant   = Number(tr.querySelector('.cant').value||0);
    const priceEl= tr.querySelector('.money');
    let price    = Number(priceEl.value||0);
    if (isRegalo()) price = 0;
    const sub = cant*price;
    total += sub;
    const trHtml = `
      <tr>
        <td>${nombre}</td>
        <td class="text-center">${cant}</td>
        <td class="text-end">${money(price)}</td>
        <td class="text-end">${money(sub)}</td>
      </tr>`;
    cfBody.insertAdjacentHTML('beforeend', trHtml);
  });
  if (isRegalo()) total = 0;
  cfTotal.textContent = money(total);

  // Pago / TAG equipo
  if (isRegalo()){
    if (cfPagoWrap) cfPagoWrap.classList.add('d-none');
    cfTagEqWrap.classList.remove('d-none');
    cfTagEq.textContent = (tagEquipo.value || '—');
    if (cfAlertRegalo) cfAlertRegalo.classList.remove('d-none');
  } else {
    if (cfPagoWrap) cfPagoWrap.classList.remove('d-none');
    cfTagEqWrap.classList.add('d-none');
    if (cfAlertRegalo) cfAlertRegalo.classList.add('d-none');
    cfForma.textContent = formaPago.value;
    cfEf.textContent = money(Number(inpEf.value||0));
    cfTa.textContent = money(Number(inpTa.value||0));
  }
}

/* -------- Envío y ticket con confirmación previa -------- */
const frm=document.getElementById('frmVenta');

frm.addEventListener('submit', async (ev)=>{
  ev.preventDefault();

  // 💡 Validar cliente primero
  const idCli = (idClienteInput.value || '').trim();
  let telCli  = (telClienteInput.value || '').trim().replace(/\D+/g,'');
  if (!idCli){
    alert('Debes seleccionar o crear un cliente antes de guardar la venta.');
    return;
  }
  if (!/^\d{10}$/.test(telCli)){
    alert('El teléfono del cliente debe tener exactamente 10 dígitos.');
    return;
  }
  // normalizamos tel
  telClienteInput.value = telCli;

  // Líneas / pagos
  if(!validarLineasBasico()) return;

  // Si es regalo, validación backend previa (TAG equipo)
  if(isRegalo()){
    const tr = tbody.querySelector('tr');
    const idProductoAcc = Number(tr.querySelector('.selProducto').value||0);
    const tagEq = (tagEquipo.value||'').trim();
    if(tagEq===''){ alert('Indica el TAG de la venta de equipo.'); return; }
    try{
      const q = new URLSearchParams({ tag_equipo: tagEq, id_producto_accesorio: String(idProductoAcc) });
      const data = await fetchJSON('validar_regalo.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: q.toString()
      });
      if(!data.ok){ alert(data.msg || 'No fue posible validar el regalo.'); return; }
    }catch(e){
      alert('Error validando el regalo: ' + (e?.message||e)); return;
    }
  }

  // Mostrar confirmación previa
  armarConfirmacion();
  if (!confirmModal) { confirmModal = new bootstrap.Modal(document.getElementById('confirmModal')); }
  confirmModal.show();
});

// Botón Confirmar del modal
document.getElementById('btnConfirmarVenta').addEventListener('click', async ()=>{
  // Cierra modal y envía definitivamente
  confirmModal?.hide();
  const btn=frm.querySelector('button[type="submit"]'); btn.disabled=true; btn.innerText='Guardando…';
  try{
    const fd=new FormData(frm);
    const resp=await fetch(frm.action, { method:'POST', body:fd, redirect:'follow' });
    const finalURL=resp.url||'';
    if(resp.ok && finalURL.includes('venta_accesorios_ticket.php')){
      const frame=document.getElementById('ticketFrame'); frame.src=finalURL;
      const modal=new bootstrap.Modal(document.getElementById('ticketModal')); modal.show();
      frm.reset(); tbody.innerHTML=''; addRow(); recalc(); esRegaloInp.value='0'; grpTagEquipo.classList.add('d-none');
      limpiarCliente();
    }else{
      const txt=await resp.text(); alert('No se pudo completar la venta:\n'+txt);
    }
  }catch(e){ alert('Error de red: ' + (e?.message||e)); }
  finally{ btn.disabled=false; btn.innerText='Guardar venta'; }
});

document.getElementById('btnPrintTicket').addEventListener('click', ()=>{
  const f=document.getElementById('ticketFrame'); try{ f.contentWindow.focus(); f.contentWindow.print(); }catch(e){}
});

/* -------- Modal de clientes: eventos -------- */
document.getElementById('btn_open_modal_clientes').addEventListener('click', ()=>{
  const inpBuscar = document.getElementById('cliente_buscar_q');
  const tbodyCli  = document.getElementById('tbody_clientes');
  const lblRes    = document.getElementById('lbl_resultados_clientes');
  inpBuscar.value = '';
  tbodyCli.innerHTML = '';
  lblRes.textContent = 'Sin buscar aún.';
  document.getElementById('collapseNuevoCliente')?.classList.remove('show');
  if (!modalClientes){
    modalClientes = new bootstrap.Modal(document.getElementById('modalClientes'));
  }
  modalClientes.show();
});

document.getElementById('btn_buscar_modal').addEventListener('click', async ()=>{
  const q = (document.getElementById('cliente_buscar_q').value || '').trim();
  const tbodyCli  = document.getElementById('tbody_clientes');
  const lblRes    = document.getElementById('lbl_resultados_clientes');

  if (!q){
    alert('Escribe algo para buscar (nombre, teléfono o código).');
    return;
  }
  const params = new URLSearchParams();
  params.append('q', q);
  params.append('id_sucursal', '<?= (int)$ID_SUCURSAL ?>');

  try{
    const res = await fetchJSON('ajax_clientes_buscar_modal.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString()
    });
    if (!res || !res.ok){
      alert(res && res.message ? res.message : 'No se pudo buscar clientes.');
      return;
    }
    const clientes = res.clientes || [];
    tbodyCli.innerHTML = '';
    if (clientes.length === 0){
      lblRes.textContent = 'Sin resultados. Puedes crear un cliente nuevo.';
      return;
    }
    lblRes.textContent = 'Se encontraron ' + clientes.length + ' cliente(s).';

    clientes.forEach(c=>{
      const tr = document.createElement('tr');
      const tdCod = document.createElement('td'); tdCod.textContent = c.codigo_cliente || '—';
      const tdNom = document.createElement('td'); tdNom.textContent = c.nombre || '';
      const tdTel = document.createElement('td'); tdTel.textContent = c.telefono || '';
      const tdCor = document.createElement('td'); tdCor.textContent = c.correo || '';
      const tdFec = document.createElement('td'); tdFec.textContent = c.fecha_alta || '';
      const tdAcc = document.createElement('td');
      const btnSel = document.createElement('button');
      btnSel.type = 'button';
      btnSel.className = 'btn btn-sm btn-primary';
      btnSel.textContent = 'Seleccionar';
      btnSel.addEventListener('click', ()=>{
        setClienteSeleccionado(c);
        modalClientes?.hide();
      });
      tdAcc.appendChild(btnSel);

      tr.appendChild(tdCod);
      tr.appendChild(tdNom);
      tr.appendChild(tdTel);
      tr.appendChild(tdCor);
      tr.appendChild(tdFec);
      tr.appendChild(tdAcc);
      tbodyCli.appendChild(tr);
    });

  }catch(e){
    alert('Error al buscar en la base de clientes: ' + (e?.message||e));
  }
});

document.getElementById('btn_guardar_nuevo_cliente').addEventListener('click', async ()=>{
  const nombre = (document.getElementById('nuevo_nombre').value || '').trim();
  let tel      = (document.getElementById('nuevo_telefono').value || '').trim();
  const correo = (document.getElementById('nuevo_correo').value || '').trim();

  if (!nombre){
    alert('Captura el nombre del cliente.');
    return;
  }
  tel = tel.replace(/\D+/g,'');
  if (!/^\d{10}$/.test(tel)){
    alert('El teléfono debe tener exactamente 10 dígitos.');
    return;
  }

  const params = new URLSearchParams();
  params.append('nombre', nombre);
  params.append('telefono', tel);
  params.append('correo', correo);
  params.append('id_sucursal', '<?= (int)$ID_SUCURSAL ?>');

  try{
    const res = await fetchJSON('ajax_crear_cliente.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body: params.toString()
    });
    if (!res || !res.ok){
      alert(res && res.message ? res.message : 'No se pudo guardar el cliente.');
      return;
    }
    const c = res.cliente || {};
    setClienteSeleccionado(c);
    modalClientes?.hide();

    document.getElementById('nuevo_nombre').value = '';
    document.getElementById('nuevo_telefono').value = '';
    document.getElementById('nuevo_correo').value = '';
    document.getElementById('collapseNuevoCliente')?.classList.remove('show');

    alert(res.message || 'Cliente creado y vinculado.');
  }catch(e){
    alert('Error al guardar el cliente: ' + (e?.message||e));
  }
});

/* Estado inicial */
function init(){
  addRow(); 
  recalc(); 
  limpiarCliente();
}
init();
</script>
<!-- Si no cargas Bootstrap bundle en navbar/global, descomenta: -->
<!-- <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script> -->
</body>
</html>
