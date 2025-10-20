<?php
/** procesar_venta.php — Central LUGA (versión con comisiones actualizadas)
 *
 * Reglas de comisiones:
 * - EJECUTIVO (campo `comision`):
 *     Equipos: [1–3499]=75, [3500–5499]=100, [5500+]=150
 *     Módem/MiFi: 50
 *     Combo: 75 fijo
 * - GERENTE (campo `comision`):
 *     Tabla Gerente: [1–3499]=25, [3500–5499]=75, [5500+]=100, Módem=25
 *     (Combo usa tabla Gerente para `comision`; si lo quieres 75 fijo también aquí, se ajusta fácil)
 * - `comision_gerente` SIEMPRE:
 *     No combo → tabla Gerente; Combo → 75 fijo (ajuste solicitado)
 * - `comision_especial` se suma SOLO a `comision`, no a `comision_gerente`.
 */

session_start();
if (!isset($_SESSION['id_usuario'])) {
  header("Location: index.php");
  exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/guard_corte.php';

date_default_timezone_set('America/Mexico_City');

/* ========================
   Candado de captura por corte de AYER
   - Sucursal del POST si viene (multi-sucursal), si no, de sesión
======================== */
$id_sucursal_guard = isset($_POST['id_sucursal'])
  ? (int)$_POST['id_sucursal']
  : (int)($_SESSION['id_sucursal'] ?? 0);

list($bloquear, $motivoBloqueo, $ayerBloqueo) = debe_bloquear_captura($conn, $id_sucursal_guard);
if ($bloquear) {
  header("Location: nueva_venta.php?err=" . urlencode("⛔ Captura bloqueada: $motivoBloqueo Debes generar el corte de $ayerBloqueo."));
  exit();
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
 *  - Combo: 75 fijo (ajuste solicitado)
 *  - No combo: tabla de Gerente por precio / módem
 */
function calcularComisionGerenteParaCampo(
  bool $esCombo,
  bool $esModem,
  float $precioLista
): float {
  if ($esCombo) return 75.0; // NUEVO: combo siempre 75 en comision_gerente
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
  header("Location: nueva_venta.php?err=" . urlencode(implode(' ', $errores)));
  exit();
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

  $conn->commit();

  header("Location: historial_ventas.php?msg=" . urlencode("Venta #$id_venta registrada. Comisión $" . number_format($totalComision, 2)));
  exit();
} catch (Throwable $e) {
  $conn->rollback();
  header("Location: nueva_venta.php?err=" . urlencode("Error al registrar la venta: " . $e->getMessage()));
  exit();
}
