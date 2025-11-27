<?php
/** procesar_venta.php — Central LUGA (versión con comisiones, lealtad y soporte AJAX)
 *
 * Reglas de comisiones:
 * - EJECUTIVO (campo `comision`):
 *     Equipos: [1–3499]=75, [3500–5499]=100, [5500+]=150
 *     Módem/MiFi: 50
 *     Combo: 75 fijo
 * - GERENTE (campo `comision`):
 *     Tabla Gerente: [1–3499]=25, [3500–5499]=75, [5500+]=100, Módem=25
 * - `comision_gerente`:
 *     Normal: No combo → tabla Gerente; Combo → 75 fijo.
 *     ⚠️ Ajuste: SI el vendedor es GERENTE, entonces `comision_gerente = 0`.
 * - `comision_especial` se suma SOLO a `comision`, no a `comision_gerente`.
 *
 * Lealtad:
 * - Opcional, solo si existen tablas: clientes, lealtad_tarjetas, lealtad_parametros, lealtad_movimientos, lealtad_referidos.
 * - Usa:
 *    POST[codigo_referido]      → Código de la tarjeta del referidor.
 *    POST[crear_tarjeta_lealtad] = "1" → Generar tarjeta para el cliente (si no tiene).
 */

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard_corte.php';

date_default_timezone_set('America/Mexico_City');

// 🔹 Detectar si viene por AJAX
$isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

function respondJson(array $payload, int $code = 200) {
  http_response_code($code);
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit();
}

/* ========================
   Candado de captura por corte de AYER
   - Sucursal del POST si viene (multi-sucursal), si no, de sesión
======================== */
$id_sucursal_guard = isset($_POST['id_sucursal'])
  ? (int)$_POST['id_sucursal']
  : (int)($_SESSION['id_sucursal'] ?? 0);

list($bloquear, $motivoBloqueo, $ayerBloqueo) = debe_bloquear_captura($conn, $id_sucursal_guard);
if ($bloquear) {
  $msg = "⛔ Captura bloqueada: $motivoBloqueo Debes generar el corte de $ayerBloqueo.";
  if ($isAjax) {
    respondJson(['status' => 'err', 'message' => $msg], 400);
  } else {
    header("Location: nueva_venta.php?err=" . urlencode($msg));
    exit();
  }
}

/* ========================
   Helpers / Utilidades
======================== */

/** Verifica si existe una columna en la tabla dada */
function columnExists(mysqli $conn, string $table, string $column): bool {
  $t = $conn->real_escape_string($table);
  $c = $conn->real_escape_string($column);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
      AND COLUMN_NAME  = '$c'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/** Verifica si existe una tabla */
function tableExists(mysqli $conn, string $table): bool {
  $t = $conn->real_escape_string($table);
  $sql = "
    SELECT 1
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$t'
    LIMIT 1
  ";
  $res = $conn->query($sql);
  return $res && $res->num_rows > 0;
}

/** Detecta el nombre de la columna de tipo de producto (compatibilidad de esquema) */
$colTipoProd = columnExists($conn, 'productos', 'tipo') ? 'tipo' : 'tipo_producto';

/** Normaliza texto: minúsculas, sin acentos, sin separadores (mi-fi -> mifi) */
function norm(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  if (function_exists('mb_strtolower')) $s = mb_strtolower($s, 'UTF-8'); else $s = strtolower($s);
  $t = @iconv('UTF-8','ASCII//TRANSLIT',$s);
  if ($t !== false) $s = strtolower($t);
  return preg_replace('/[^a-z0-9]+/', '', $s);
}

/** Detecta si el producto es MiFi/Módem usando varias columnas del producto */
function esMiFiModem(array $row): bool {
  $candidatos = [];
  $candidatos[] = isset($row['tipo_raw']) ? (string)$row['tipo_raw'] : '';
  foreach (['nombre_comercial','subtipo','descripcion','modelo'] as $k) {
    if (isset($row[$k])) $candidatos[] = (string)$row[$k];
  }
  $joined = norm(implode(' ', $candidatos));
  foreach (['modem','mifi','hotspot','router','cpe','pocketwifi'] as $n) {
    if (strpos($joined, $n) !== false) return true;
  }
  return false;
}

/* ========================
   Tablas de comisión
======================== */

/** EJECUTIVO — por tramo de precio_lista */
function comisionTramoEjecutivo(float $precio): float {
  if ($precio >= 1     && $precio <= 3499) return 75.0;
  if ($precio >= 3500  && $precio <= 5499) return 100.0;
  if ($precio >= 5500)                     return 150.0;
  return 0.0;
}

/** GERENTE — por tramo de precio_lista o módem */
function comisionTramoGerente(float $precio, bool $isModem): float {
  if ($isModem) return 25.0;
  if ($precio >= 1     && $precio <= 3499) return 25.0;
  if ($precio >= 3500  && $precio <= 5499) return 75.0;
  if ($precio >= 5500)                     return 100.0;
  return 0.0;
}

/**
 * Comisión para el campo `comision` considerando:
 * - rol del vendedor (Ejecutivo/ Gerente)
 * - si es combo (75 fijo para Ejecutivo; para Gerente usamos tabla Gerente)
 * - si es módem
 * - precio_lista
 */
function calcularComisionBaseParaCampoComision(
  string $rolVendedor,
  bool   $esCombo,
  bool   $esModem,
  float  $precioLista
): float {
  if ($rolVendedor === 'Gerente') {
    // Para `comision`, si vende Gerente: usa tabla de Gerente (incluye módem y combos)
    return comisionTramoGerente($precioLista, $esModem);
  }

  // Ejecutivo
  if ($esCombo) return 75.0;       // combo fijo para Ejecutivo
  if ($esModem) return 50.0;       // módem Ejecutivo
  return comisionTramoEjecutivo($precioLista);
}

/** Comisión para `comision_gerente`:
 *  - Combo: 75 fijo
 *  - No combo: tabla de Gerente por precio / módem
 */
function calcularComisionGerenteParaCampo(
  bool $esCombo,
  bool $esModem,
  float $precioLista
): float {
  if ($esCombo) return 75.0;
  return comisionTramoGerente($precioLista, $esModem);
}

/** Comisión especial por producto según catálogo (se suma SOLO a `comision`) */
function obtenerComisionEspecial(int $id_producto, mysqli $conn, string $colTipoProd): float {
  $hoy = date('Y-m-d');

  $stmt = $conn->prepare("
    SELECT marca, modelo, capacidad, $colTipoProd AS tipo_raw,
           nombre_comercial, subtipo, descripcion
    FROM productos WHERE id=?
  ");
  $stmt->bind_param("i", $id_producto);
  $stmt->execute();
  $prod = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$prod) return 0.0;

  $stmt2 = $conn->prepare("
    SELECT monto
    FROM comisiones_especiales
    WHERE marca=? AND modelo=? AND (capacidad=? OR capacidad='' OR capacidad IS NULL)
      AND fecha_inicio <= ? AND (fecha_fin IS NULL OR fecha_fin >= ?)
      AND activo=1
    ORDER BY fecha_inicio DESC
    LIMIT 1
  ");
  $stmt2->bind_param("sssss", $prod['marca'], $prod['modelo'], $prod['capacidad'], $hoy, $hoy);
  $stmt2->execute();
  $res = $stmt2->get_result()->fetch_assoc();
  $stmt2->close();

  return (float)($res['monto'] ?? 0);
}

/** Verifica inventario disponible en la sucursal seleccionada */
function validarInventario(mysqli $conn, int $id_inv, int $id_sucursal): bool {
  $stmt = $conn->prepare("
    SELECT COUNT(*) FROM inventario
    WHERE id=? AND estatus='Disponible' AND id_sucursal=?
  ");
  $stmt->bind_param("ii", $id_inv, $id_sucursal);
  $stmt->execute();
  $stmt->bind_result($ok);
  $stmt->fetch();
  $stmt->close();
  return (int)$ok > 0;
}

/**
 * Registra un renglón de venta (principal o combo) en detalle_venta
 * y actualiza inventario. Devuelve la comisión TOTAL del renglón (base + especial).
 */
function venderEquipo(
  mysqli $conn,
  int $id_venta,
  int $id_inventario,
  bool $esCombo,
  string $rolVendedor,
  string $tipoVenta, // compatibilidad
  bool $tieneEsCombo,
  bool $tieneComisionGerente,
  string $colTipoProd
): float {

  // 1) Traer datos del producto
  $sql = "
    SELECT i.id_producto,
           p.imei1,
           p.precio_lista,
           p.`$colTipoProd` AS tipo_raw,
           p.nombre_comercial,
           p.subtipo,
           p.descripcion,
           p.modelo
    FROM inventario i
    INNER JOIN productos p ON i.id_producto = p.id
    WHERE i.id=? AND i.estatus='Disponible'
    LIMIT 1
  ";
  $stmtProd = $conn->prepare($sql);
  $stmtProd->bind_param("i", $id_inventario);
  $stmtProd->execute();
  $row = $stmtProd->get_result()->fetch_assoc();
  $stmtProd->close();
  if (!$row) { throw new RuntimeException("El equipo $id_inventario no está disponible."); }

  $precioL = (float)$row['precio_lista'];
  $esModem = esMiFiModem($row);

  // 2) Calcular comisiones base
  $comisionBase        = calcularComisionBaseParaCampoComision($rolVendedor, $esCombo, $esModem, $precioL);
  $comisionGerenteBase = calcularComisionGerenteParaCampo($esCombo, $esModem, $precioL);

  // ⚠️ AJUSTE CLAVE: si vende GERENTE, forzar comision_gerente = 0
  if ($rolVendedor === 'Gerente') {
    $comisionGerenteBase = 0.0;
  }

  // 3) Comisión especial (solo suma a `comision`)
  $comEsp = obtenerComisionEspecial((int)$row['id_producto'], $conn, $colTipoProd);

  // 4) Totales a guardar
  $comisionRegular = $comisionBase;           // base sin especial
  $comisionTotal   = $comisionBase + $comEsp; // `comision`

  // 5) INSERT en detalle_venta con las columnas disponibles
  if ($tieneEsCombo && $tieneComisionGerente) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, imei1, precio_unitario,
         comision, comision_regular, comision_especial, comision_gerente)
      VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $esComboInt = $esCombo ? 1 : 0;
    $stmtD->bind_param(
      "iiisddddd",
      $id_venta, $row['id_producto'], $esComboInt, $row['imei1'],
      $precioL, $comisionTotal, $comisionRegular, $comEsp, $comisionGerenteBase
    );
  } elseif ($tieneComisionGerente) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, imei1, precio_unitario,
         comision, comision_regular, comision_especial, comision_gerente)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iisddddd",
      $id_venta, $row['id_producto'], $row['imei1'],
      $precioL, $comisionTotal, $comisionRegular, $comEsp, $comisionGerenteBase
    );
  } elseif ($tieneEsCombo) {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, es_combo, imei1, precio_unitario,
         comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?,?)
    ");
    $esComboInt = $esCombo ? 1 : 0;
    $stmtD->bind_param(
      "iiisdddd",
      $id_venta, $row['id_producto'], $esComboInt, $row['imei1'],
      $precioL, $comisionTotal, $comisionRegular, $comEsp
    );
  } else {
    $stmtD = $conn->prepare("
      INSERT INTO detalle_venta
        (id_venta, id_producto, imei1, precio_unitario,
         comision, comision_regular, comision_especial)
      VALUES (?,?,?,?,?,?,?)
    ");
    $stmtD->bind_param(
      "iisdddd",
      $id_venta, $row['id_producto'], $row['imei1'],
      $precioL, $comisionTotal, $comisionRegular, $comEsp
    );
  }

  $stmtD->execute();
  $stmtD->close();

  // 6) Marcar inventario como vendido
  $stmtU = $conn->prepare("UPDATE inventario SET estatus='Vendido' WHERE id=?");
  $stmtU->bind_param("i", $id_inventario);
  $stmtU->execute();
  $stmtU->close();

  return $comisionTotal;
}

/* ========================
   Helpers de LEALTAD
======================== */

/**
 * Crea o devuelve el cliente por teléfono.
 * Devuelve id_cliente o null si no se pudo.
 */
function asegurarClientePorTelefono(mysqli $conn, string $telefono, string $nombre, int $id_sucursal): ?int {
  if ($telefono === '') return null;

  // Buscar existente
  $stmt = $conn->prepare("SELECT id FROM clientes WHERE telefono=? LIMIT 1");
  $stmt->bind_param("s", $telefono);
  $stmt->execute();
  $stmt->bind_result($id);
  if ($stmt->fetch()) {
    $stmt->close();
    return (int)$id;
  }
  $stmt->close();

  // Crear nuevo
  $stmtIns = $conn->prepare("INSERT INTO clientes (nombre, telefono, id_sucursal) VALUES (?,?,?)");
  $stmtIns->bind_param("ssi", $nombre, $telefono, $id_sucursal);
  $stmtIns->execute();
  $idNew = (int)$stmtIns->insert_id;
  $stmtIns->close();

  return $idNew ?: null;
}

/** Devuelve id de tarjeta activa del cliente o null */
function obtenerTarjetaActivaPorCliente(mysqli $conn, int $id_cliente): ?int {
  $stmt = $conn->prepare("SELECT id FROM lealtad_tarjetas WHERE id_cliente=? AND activo=1 LIMIT 1");
  $stmt->bind_param("i", $id_cliente);
  $stmt->execute();
  $stmt->bind_result($id_tarjeta);
  if ($stmt->fetch()) {
    $stmt->close();
    return (int)$id_tarjeta;
  }
  $stmt->close();
  return null;
}

/** Crea una tarjeta de lealtad para el cliente y devuelve su id */
function crearTarjetaLealtadParaCliente(mysqli $conn, int $id_cliente): int {
  // Generar códigos pseudo-únicos
  $codigoTarjeta  = 'TL-'   . strtoupper(substr(md5(uniqid('tl'.$id_cliente, true)), 0, 8));
  $codigoReferido = 'LUGA-' . strtoupper(substr(md5(uniqid('ref'.$id_cliente, true)), 0, 8));

  // Detectar host actual (funciona en local y en producción)
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

  // Si tu app está en subcarpeta tipo /central_luga, ajusta aquí:
  $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
  if ($scriptDir === '.') { $scriptDir = ''; }

  // Queda algo como: http://localhost/central_luga/lealtad/tarjeta.php?code=XXX
  $baseUrl   = $scheme . '://' . $host . $scriptDir . '/lealtad/tarjeta.php?code=';
  $urlTarjeta = $baseUrl . urlencode($codigoReferido);

  $stmt = $conn->prepare("
    INSERT INTO lealtad_tarjetas (id_cliente, codigo_tarjeta, codigo_referido, url_tarjeta, activo)
    VALUES (?,?,?,?,1)
  ");
  $stmt->bind_param("isss", $id_cliente, $codigoTarjeta, $codigoReferido, $urlTarjeta);
  $stmt->execute();
  $idTar = (int)$stmt->insert_id;
  $stmt->close();

  return $idTar;
}

/** Parámetros de lealtad vigentes hoy */
function obtenerParametrosLealtadVigentes(mysqli $conn): ?array {
  $sql = "
    SELECT *
    FROM lealtad_parametros
    WHERE vigente_desde <= CURDATE()
      AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())
    ORDER BY vigente_desde DESC
    LIMIT 1
  ";
  $res = $conn->query($sql);
  if ($res && $row = $res->fetch_assoc()) {
    return $row;
  }
  return null;
}

/** Inserta movimiento de puntos y actualiza saldo de la tarjeta */
function agregarPuntosATarjeta(
  mysqli $conn,
  int $id_tarjeta,
  int $puntos,
  string $tipo,
  string $descripcion,
  ?string $refTabla,
  ?int $refId,
  ?int $vigenciaMeses
): void {
  if ($puntos === 0) return;

  $fechaExp = null;
  if (!is_null($vigenciaMeses) && $vigenciaMeses > 0) {
    $dt = new DateTime();
    $dt->modify('+' . $vigenciaMeses . ' months');
    $fechaExp = $dt->format('Y-m-d');
  }

  $sqlMov = "
    INSERT INTO lealtad_movimientos
      (id_tarjeta, tipo_movimiento, puntos, descripcion, referencia_tabla, referencia_id, fecha_expiracion)
    VALUES (?,?,?,?,?,?,?)
  ";
  $stmtMov = $conn->prepare($sqlMov);
  $refTablaDb = $refTabla;
  $refIdDb    = $refId;
  $stmtMov->bind_param(
    "isissis",
    $id_tarjeta,
    $tipo,
    $puntos,
    $descripcion,
    $refTablaDb,
    $refIdDb,
    $fechaExp
  );
  $stmtMov->execute();
  $stmtMov->close();

  // Actualizar saldo
  $stmtUpd = $conn->prepare("UPDATE lealtad_tarjetas SET puntos_actuales = puntos_actuales + ? WHERE id=?");
  $stmtUpd->bind_param("ii", $puntos, $id_tarjeta);
  $stmtUpd->execute();
  $stmtUpd->close();
}

/* ========================
   1) Recibir + Validar
======================== */
$id_usuario   = (int)($_SESSION['id_usuario']);
$rol_usuario  = (string)($_SESSION['rol'] ?? 'Ejecutivo');
$id_sucursal  = isset($_POST['id_sucursal']) ? (int)$_POST['id_sucursal'] : (int)$_SESSION['id_sucursal'];

$tag                 = trim($_POST['tag'] ?? '');
$nombre_cliente      = trim($_POST['nombre_cliente'] ?? '');
$telefono_cliente    = trim($_POST['telefono_cliente'] ?? '');
$tipo_venta          = $_POST['tipo_venta'] ?? '';
$equipo1             = (int)($_POST['equipo1'] ?? 0);
$equipo2             = isset($_POST['equipo2']) ? (int)$_POST['equipo2'] : 0;
$precio_venta        = (float)($_POST['precio_venta'] ?? 0);
$enganche            = (float)($_POST['enganche'] ?? 0);
$forma_pago_enganche = $_POST['forma_pago_enganche'] ?? '';
$enganche_efectivo   = (float)($_POST['enganche_efectivo'] ?? 0);
$enganche_tarjeta    = (float)($_POST['enganche_tarjeta'] ?? 0);
$plazo_semanas       = (int)($_POST['plazo_semanas'] ?? 0);
$financiera          = $_POST['financiera'] ?? '';
$comentarios         = trim($_POST['comentarios'] ?? '');

// 🔹 Campos nuevos de lealtad
$codigo_referido          = trim($_POST['codigo_referido'] ?? '');
$crear_tarjeta_lealtad    = isset($_POST['crear_tarjeta_lealtad']) && $_POST['crear_tarjeta_lealtad'] === '1';

$esFin = in_array($tipo_venta, ['Financiamiento','Financiamiento+Combo'], true);
$errores = [];

// Reglas
if (!$tipo_venta)                                 $errores[] = "Selecciona el tipo de venta.";
if ($precio_venta <= 0)                           $errores[] = "El precio de venta debe ser mayor a 0.";
if (!$forma_pago_enganche)                        $errores[] = "Selecciona la forma de pago.";
if ($equipo1 <= 0)                                $errores[] = "Selecciona el equipo principal.";

// Reglas para Financiamiento / Combo
if ($esFin) {
  if ($nombre_cliente === '')                     $errores[] = "Nombre del cliente es obligatorio.";
  if ($telefono_cliente === '' || !preg_match('/^\d{10}$/', $telefono_cliente)) $errores[] = "Teléfono del cliente debe tener 10 dígitos.";
  if ($tag === '')                                $errores[] = "TAG (ID del crédito) es obligatorio.";
  if ($enganche < 0)                              $errores[] = "El enganche no puede ser negativo (puede ser 0).";
  if ($plazo_semanas <= 0)                        $errores[] = "El plazo en semanas debe ser mayor a 0.";
  if ($financiera === '')                         $errores[] = "Selecciona una financiera (no puede ser N/A).";

  if ($forma_pago_enganche === 'Mixto') {
    if ($enganche_efectivo <= 0 && $enganche_tarjeta <= 0) $errores[] = "En pago Mixto, al menos uno de los montos debe ser > 0.";
    if (round($enganche_efectivo + $enganche_tarjeta, 2) !== round($enganche, 2)) $errores[] = "Efectivo + Tarjeta debe ser igual al Enganche.";
  }
} else {
  // Contado: normaliza campos
  $tag = '';
  $plazo_semanas = 0;
  $financiera = 'N/A';
  $enganche_efectivo = 0;
  $enganche_tarjeta  = 0;
}

// Validar inventarios disponibles en la sucursal seleccionada
if ($equipo1 && !validarInventario($conn, $equipo1, $id_sucursal)) {
  $errores[] = "El equipo principal no está disponible en la sucursal seleccionada.";
}
if ($tipo_venta === 'Financiamiento+Combo') {
  if ($equipo2 <= 0) {
    $errores[] = "Selecciona el equipo combo.";
  } else if (!validarInventario($conn, $equipo2, $id_sucursal)) {
    $errores[] = "El equipo combo no está disponible en la sucursal seleccionada.";
  }
}

if ($errores) {
  $msg = implode(' ', $errores);
  if ($isAjax) {
    respondJson(['status' => 'err', 'message' => $msg], 400);
  } else {
    header("Location: nueva_venta.php?err=" . urlencode($msg));
    exit();
  }
}

/* ========================
   Flags de soporte de LEALTAD
======================== */
$hasClientes    = tableExists($conn, 'clientes');
$hasTarjetas    = tableExists($conn, 'lealtad_tarjetas');
$hasParams      = tableExists($conn, 'lealtad_parametros');
$hasMovimientos = tableExists($conn, 'lealtad_movimientos');
$hasReferidos   = tableExists($conn, 'lealtad_referidos');

$soportaLealtad = $hasClientes && $hasTarjetas;

// Variables de trabajo para lealtad
$id_cliente           = null;
$id_tarjeta_cliente   = null;

/**
 * Si hay soporte de lealtad y teléfono, aseguramos cliente
 * (no se guarda en ventas, solo se usa para el módulo de lealtad).
 */
if ($soportaLealtad && $telefono_cliente !== '' && preg_match('/^\d{10}$/', $telefono_cliente)) {
  $id_cliente = asegurarClientePorTelefono($conn, $telefono_cliente, $nombre_cliente, $id_sucursal);
  if ($id_cliente) {
    $id_tarjeta_cliente = obtenerTarjetaActivaPorCliente($conn, $id_cliente);
  }
}

/* ========================
   2) Insertar Venta (TX)
======================== */
try {
  $conn->begin_transaction();

  $sqlVenta = "INSERT INTO ventas
    (tag, nombre_cliente, telefono_cliente, tipo_venta, precio_venta, id_usuario, id_sucursal, comision,
     enganche, forma_pago_enganche, enganche_efectivo, enganche_tarjeta, plazo_semanas, financiera, comentarios)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";

  $stmtVenta = $conn->prepare($sqlVenta);
  $comisionInicial = 0.0;

  $stmtVenta->bind_param(
    "ssssdiiddsddiss",
    $tag,
    $nombre_cliente,
    $telefono_cliente,
    $tipo_venta,
    $precio_venta,
    $id_usuario,
    $id_sucursal,
    $comisionInicial,
    $enganche,
    $forma_pago_enganche,
    $enganche_efectivo,
    $enganche_tarjeta,
    $plazo_semanas,
    $financiera,
    $comentarios
  );
  $stmtVenta->execute();
  $id_venta = (int)$stmtVenta->insert_id;
  $stmtVenta->close();

  /* ========================
     3) Registrar equipos (CAPTURA base)
  ======================= */
  $tieneEsCombo          = columnExists($conn, 'detalle_venta', 'es_combo');
  $tieneComisionGerente  = columnExists($conn, 'detalle_venta', 'comision_gerente');

  $totalComision = 0.0;

  // Principal
  $totalComision += venderEquipo(
    $conn, $id_venta, $equipo1, false,
    $rol_usuario, $tipo_venta, $tieneEsCombo, $tieneComisionGerente, $colTipoProd
  );

  // Combo (si aplica)
  if ($tipo_venta === 'Financiamiento+Combo' && $equipo2) {
    $totalComision += venderEquipo(
      $conn, $id_venta, $equipo2, true,
      $rol_usuario, $tipo_venta, $tieneEsCombo, $tieneComisionGerente, $colTipoProd
    );
  }

  /* ========================
     4) Actualizar venta (total de comisiones)
  ======================= */
  $stmtUpd = $conn->prepare("UPDATE ventas SET comision=? WHERE id=?");
  $stmtUpd->bind_param("di", $totalComision, $id_venta);
  $stmtUpd->execute();
  $stmtUpd->close();

  /* ========================
     5) Lógica de LEALTAD (opcional)
     - Crear tarjeta para el cliente (si se marcó el botón).
     - Procesar código de referido (si aplica).
  ======================= */
  if ($soportaLealtad && $id_cliente) {
    // Crear tarjeta para el cliente si se pidió y aún no tiene
    if ($crear_tarjeta_lealtad && !$id_tarjeta_cliente) {
      $id_tarjeta_cliente = crearTarjetaLealtadParaCliente($conn, $id_cliente);
    }

    // Código de referido: asignar puntos al referidor y registrar la relación
    if (
      $codigo_referido !== '' &&
      $hasReferidos &&
      $hasMovimientos &&
      $hasParams
    ) {
      // Buscar tarjeta del referidor por código
      $stmtRefTar = $conn->prepare("SELECT id FROM lealtad_tarjetas WHERE codigo_referido=? AND activo=1 LIMIT 1");
      $stmtRefTar->bind_param("s", $codigo_referido);
      $stmtRefTar->execute();
      $stmtRefTar->bind_result($id_tarjeta_referidor);
      $id_tarjeta_referidor = null;
      if ($stmtRefTar->fetch()) {
        $id_tarjeta_referidor = (int)$id_tarjeta_referidor;
      }
      $stmtRefTar->close();

      if ($id_tarjeta_referidor) {
        // Registrar la relación de referido
        $stmtLR = $conn->prepare("
          INSERT INTO lealtad_referidos
            (id_tarjeta_referidor, id_cliente_referido, id_venta, beneficio_aplicado, puntos_asignados)
          VALUES (?,?,?,?,?)
        ");
        $beneficioAplicado = 0; // por ahora solo puntos, el beneficio en pesos se puede manejar después
        $puntosAsignados   = 0; // se actualizaría si quieres guardar cuántos puntos se asignaron
        $stmtLR->bind_param(
          "iiiii",
          $id_tarjeta_referidor,
          $id_cliente,
          $id_venta,
          $beneficioAplicado,
          $puntosAsignados
        );
        $stmtLR->execute();
        $stmtLR->close();

        // Asignar puntos al referidor según parámetros vigentes
        $cfg = obtenerParametrosLealtadVigentes($conn);
        if ($cfg) {
          $puntosRef = (int)($cfg['puntos_por_referido'] ?? 0);
          $vigMeses  = (int)($cfg['vigencia_puntos_meses'] ?? 0);
          if ($puntosRef > 0) {
            agregarPuntosATarjeta(
              $conn,
              $id_tarjeta_referidor,
              $puntosRef,
              'REFERIDO',
              'Cliente referido en venta #' . $id_venta,
              'ventas',
              $id_venta,
              $vigMeses
            );
          }
        }
      }
    }
  }

  $conn->commit();

  // Intentar obtener URL de tarjeta si existe
  $urlTarjeta = '';
  if ($soportaLealtad && $id_tarjeta_cliente) {
    $stmtUrl = $conn->prepare("SELECT url_tarjeta FROM lealtad_tarjetas WHERE id=? LIMIT 1");
    $stmtUrl->bind_param("i", $id_tarjeta_cliente);
    $stmtUrl->execute();
    $stmtUrl->bind_result($urlTarjetaDb);
    if ($stmtUrl->fetch()) {
      $urlTarjeta = (string)$urlTarjetaDb;
    }
    $stmtUrl->close();
  }

  $mensaje = "Venta #$id_venta registrada. Comisión $" . number_format($totalComision, 2);

  if ($isAjax) {
    respondJson([
      'status'       => 'ok',
      'message'      => $mensaje,
      'id_venta'     => $id_venta,
      'comision'     => $totalComision,
      'url_tarjeta'  => $urlTarjeta,
      'tiene_tarjeta'=> $urlTarjeta !== ''
    ]);
  } else {
    // Modo clásico por si algún flujo viejo todavía redirige
    if ($urlTarjeta !== '') {
      header("Location: historial_ventas.php?msg=" . urlencode($mensaje) . "&tarjeta=" . urlencode($urlTarjeta));
    } else {
      header("Location: historial_ventas.php?msg=" . urlencode($mensaje));
    }
    exit();
  }
} catch (Throwable $e) {
  $conn->rollback();
  $msgErr = "Error al registrar la venta: " . $e->getMessage();

  if ($isAjax) {
    respondJson(['status' => 'err', 'message' => $msgErr], 500);
  } else {
    header("Location: nueva_venta.php?err=" . urlencode($msgErr));
    exit();
  }
}
