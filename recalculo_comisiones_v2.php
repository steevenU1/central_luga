<?php
// recalculo_comisiones_v2.php — Recalcula comisiones (Ejecutivo/Gerente) por cumplimiento de cuota.
// Aplica reglas de esquemas_comisiones_v2 a: detalle_venta, ventas_sims, y usa montos FIJOS para ventas_payjoy_tc (TC).
// Semana operativa: Mar→Lun.

session_start();
header('Content-Type: application/json; charset=UTF-8');

set_error_handler(function($errno, $errstr, $errfile, $errline){
  throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function fail($msg, $code=500){
  http_response_code($code);
  echo json_encode(['status'=>'err','message'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

if (!isset($_SESSION['id_usuario'])) fail('No autenticado', 401);
require_once __DIR__ . '/db.php';
if (!isset($conn) || !$conn instanceof mysqli) fail('Sin conexión a BD');

/* ========== Utils ========== */
function columnExists(mysqli $c, string $t, string $col): bool {
  $t=$c->real_escape_string($t); $col=$c->real_escape_string($col);
  $sql="SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='$t' AND COLUMN_NAME='$col' LIMIT 1";
  $r=$c->query($sql);
  return $r && $r->num_rows>0;
}
function getUserSucursalColumn(mysqli $c): ?string {
  if (columnExists($c,'usuarios','id_sucursal')) return 'id_sucursal';
  if (columnExists($c,'usuarios','sucursal'))     return 'sucursal';
  return null;
}
/** Retorna [iniDT, finDT] Mar→Lun de la semana que contiene $anchor (Y-m-d) */
function weekBoundsFrom(string $anchor): array {
  $d = DateTime::createFromFormat('Y-m-d', $anchor) ?: new DateTime('now');
  $dow = (int)$d->format('N'); // 1=Lun..7=Dom
  $diff = $dow >= 2 ? $dow - 2 : 7 - (2 - $dow); // ir al Martes
  $ini = clone $d; $ini->modify("-$diff day")->setTime(0,0,0);
  $fin = clone $ini; $fin->modify("+6 day")->setTime(23,59,59);
  return [$ini, $fin];
}
function ymd($s){ return preg_match('/^\d{4}-\d{2}-\d{2}$/',$s) ? $s : date('Y-m-d'); }

/* ========== Entrada y semana ========== */
try {
  $anchor = $_POST['ini'] ?? ($_POST['fin'] ?? date('Y-m-d'));
  $anchor = ymd($anchor);
  [$iniDT, $finDT] = weekBoundsFrom($anchor);
  $ini = $iniDT->format('Y-m-d'); $fin = $finDT->format('Y-m-d');
  $iniTS = $ini.' 00:00:00'; $finTS = $fin.' 23:59:59';

  /* ========== Detección de columnas y flags ========== */
  $hasProductos     = columnExists($conn,'productos','id');
  $hasPrecioLista   = $hasProductos && columnExists($conn,'productos','precio_lista');
  $hasTipoProd      = $hasProductos && columnExists($conn,'productos','tipo_producto');
  $hasCatProd       = $hasProductos && columnExists($conn,'productos','categoria');
  $hasModeloProd    = $hasProductos && columnExists($conn,'productos','modelo');

  $hasDVPrecioUnit  = columnExists($conn,'detalle_venta','precio_unitario');
  $hasDVPrecio      = columnExists($conn,'detalle_venta','precio');

  $hasSims          = columnExists($conn,'ventas_sims','id');
  $hasTC            = columnExists($conn,'ventas_payjoy_tc','id');

  // ventas_sims: columnas flexibles
  $colOperador = null;
  foreach (['operador','tipo_sim','carrier','compania','compañia','empresa','operadora'] as $cnd) {
    if (columnExists($conn,'ventas_sims',$cnd)) { $colOperador = $cnd; break; }
  }
  $colTipoSIM = null;
  foreach (['tipo_venta','tipo_alta','tipo'] as $cnd) {
    if (columnExists($conn,'ventas_sims',$cnd)) { $colTipoSIM = $cnd; break; }
  }

  // Filtro "NO es módem"
  $notModem = [];
  if ($hasTipoProd)   $notModem[] = "LOWER(p.tipo_producto) NOT IN ('modem','módem','mifi')";
  if ($hasCatProd)    $notModem[] = "LOWER(p.categoria) NOT IN ('modem','módem','mifi')";
  if ($hasModeloProd) $notModem[] = "(LOWER(p.modelo) NOT LIKE '%modem%' AND LOWER(p.modelo) NOT LIKE '%mifi%')";
  $SQL_NOT_MODEM = empty($notModem) ? "1=1" : implode(' AND ', $notModem);

  /* ========== Usuarios activos Tienda/Propia ========== */
  $colUserSuc = getUserSucursalColumn($conn);
  if (!$colUserSuc) fail('No se encontró la FK a sucursal en usuarios.');
  $sqlU = "
    SELECT u.id, u.nombre, u.rol, u.$colUserSuc AS id_sucursal
    FROM usuarios u
    INNER JOIN sucursales s ON s.id = u.$colUserSuc
    WHERE (u.activo IS NULL OR u.activo=1) AND s.tipo_sucursal='Tienda' AND s.subtipo='Propia'
  ";
  $usuarios=[]; $res=$conn->query($sqlU);
  while($r=$res->fetch_assoc()) $usuarios[(int)$r['id']]=$r;
  $res->close();

  /* ========== Cuotas: monto sucursal y unidades usuario ========== */
  $stmtMontoSuc = $conn->prepare("
    SELECT COALESCE(SUM(precio_venta),0) AS total
    FROM ventas
    WHERE id_sucursal=? AND fecha_venta BETWEEN ? AND ?
  ");
  $stmtCuotaMonto = $conn->prepare("
    SELECT cuota_monto
    FROM cuotas_sucursales
    WHERE id_sucursal=? AND fecha_inicio<=?
    ORDER BY fecha_inicio DESC, id DESC
    LIMIT 1
  ");

  $stmtUdsUser = $conn->prepare("
    SELECT COUNT(d.id) AS uds
    FROM detalle_venta d
    INNER JOIN ventas v ON v.id = d.id_venta
    ".($hasProductos ? "LEFT JOIN productos p ON p.id = d.id_producto" : "")."
    WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ? AND ($SQL_NOT_MODEM)
  "); // Cada renglón (no módem) = 1 unidad

  $stmtCuotaUds = $conn->prepare("
    SELECT cuota_unidades
    FROM cuotas_semanales_sucursal
    WHERE id_sucursal=? AND semana_inicio<=? AND semana_fin>=?
    ORDER BY id DESC
    LIMIT 1
  ");

  $cumpleMonto = [];
  foreach ($usuarios as $u) {
    $sid=(int)$u['id_sucursal'];
    if (!array_key_exists($sid,$cumpleMonto)) {
      $stmtMontoSuc->bind_param('iss',$sid,$iniTS,$finTS);
      $stmtMontoSuc->execute(); $row=$stmtMontoSuc->get_result()->fetch_assoc();
      $monto = (float)($row['total'] ?? 0);

      $stmtCuotaMonto->bind_param('is',$sid,$fin);
      $stmtCuotaMonto->execute(); $r2=$stmtCuotaMonto->get_result()->fetch_assoc();
      $cuota = $r2 ? (float)$r2['cuota_monto'] : null;

      $cumpleMonto[$sid] = ($cuota !== null && $monto >= $cuota);
    }
  }
  $stmtMontoSuc->close(); $stmtCuotaMonto->close();

  $cumpleUnidades = [];
  foreach ($usuarios as $u) {
    $uid=(int)$u['id']; $sid=(int)$u['id_sucursal'];
    $stmtUdsUser->bind_param('iss',$uid,$iniTS,$finTS);
    $stmtUdsUser->execute(); $r1=$stmtUdsUser->get_result()->fetch_assoc();
    $uds = (int)($r1['uds'] ?? 0);

    $stmtCuotaUds->bind_param('iss',$sid,$ini,$fin);
    $stmtCuotaUds->execute(); $r2=$stmtCuotaUds->get_result()->fetch_assoc();
    $cu = $r2 ? (int)$r2['cuota_unidades'] : null;

    $cumpleUnidades[$uid] = ($cu !== null && $uds >= $cu);
  }
  $stmtUdsUser->close(); $stmtCuotaUds->close();

  /* ========== Selectores de REGLA en esquemas_comisiones_v2 ========== */
  // Equipos (usa precio_min/precio_max)
  $selEquipo = $conn->prepare("
    SELECT monto_fijo
    FROM esquemas_comisiones_v2
    WHERE activo=1 AND rol=? AND categoria='Equipo' AND componente=?
      AND vigente_desde<=? AND (vigente_hasta IS NULL OR vigente_hasta>=?)
      AND (id_sucursal IS NULL OR id_sucursal=?)
      AND (precio_min IS NULL OR ? >= precio_min)
      AND (precio_max IS NULL OR ? <  precio_max)
    ORDER BY (id_sucursal IS NULL) ASC, prioridad ASC,
             COALESCE(precio_min,-1) DESC, COALESCE(precio_max,1000000000000) ASC
    LIMIT 1
  ");

  // SIMs
  $selSIM = $conn->prepare("
    SELECT monto_fijo
    FROM esquemas_comisiones_v2
    WHERE activo=1 AND rol=? AND categoria='SIM' AND componente=?
      AND (subtipo=? OR subtipo='General' OR subtipo IS NULL)
      AND (operador=? OR operador='*' OR operador IS NULL)
      AND vigente_desde<=? AND (vigente_hasta IS NULL OR vigente_hasta>=?)
      AND (id_sucursal IS NULL OR id_sucursal=?)
    ORDER BY (id_sucursal IS NULL) ASC, prioridad ASC
    LIMIT 1
  ");

  /* ========== Updates preparados ========== */
  $updDV_Eje = $conn->prepare("UPDATE detalle_venta SET comision=? WHERE id=?");
  $updDV_Ger = $conn->prepare("UPDATE detalle_venta SET comision_gerente=? WHERE id=?");
  $updVS_Eje = $hasSims ? $conn->prepare("UPDATE ventas_sims SET comision_ejecutivo=? WHERE id=?") : null;
  $updVS_Ger = $hasSims ? $conn->prepare("UPDATE ventas_sims SET comision_gerente=? WHERE id=?")   : null;
  $updTC_Eje = $hasTC   ? $conn->prepare("UPDATE ventas_payjoy_tc SET comision=? WHERE id=?")      : null;
  $updTC_Ger = $hasTC   ? $conn->prepare("UPDATE ventas_payjoy_tc SET comision_gerente=? WHERE id=?"): null;

  /* ====== Constantes para bind_param (por referencia) ====== */
  $ROL_EJE = 'Ejecutivo';
  $ROL_GER = 'Gerente';
  $COMP_COMI = 'comision';
  $COMP_COMI_GER = 'comision_gerente';

  // Montos fijos de TC (regla nueva)
  $TC_COMISION_EJE = 50.0;
  $TC_COMISION_GER = 20.0;

  /* ========== PROCESO POR USUARIO ========== */
  $stats = ['equipos'=>0,'sims'=>0,'pospago'=>0,'tc'=>0];

  foreach ($usuarios as $u) {
    $uid=(int)$u['id'];
    $sid=(int)$u['id_sucursal'];
    $rolUsuario=(string)$u['rol'];
    $isGerenteVendedor = (strcasecmp($rolUsuario,'Gerente')===0);

    $aplicaEje = !empty($cumpleUnidades[$uid]); // llegó a cuota de unidades
    $aplicaGer = !empty($cumpleMonto[$sid]);    // sucursal llegó a cuota de monto

    /* ---- EQUIPOS (detalle_venta) ---- */
    // precio_ref: productos.precio_lista -> detalle_venta.precio_unitario -> detalle_venta.precio
    $precioRefExpr = "COALESCE(".
                      ($hasPrecioLista ? "p.precio_lista," : "").
                      ($hasDVPrecioUnit? "d.precio_unitario," : "").
                      ($hasDVPrecio     ? "d.precio," : "").
                     "0) AS precio_ref";
    $sqlDV = "
      SELECT d.id, $precioRefExpr
      FROM detalle_venta d
      INNER JOIN ventas v ON v.id = d.id_venta
      ".($hasProductos ? "LEFT JOIN productos p ON p.id = d.id_producto" : "")."
      WHERE v.id_usuario=? AND v.fecha_venta BETWEEN ? AND ? AND ($SQL_NOT_MODEM)
    ";
    $stDV=$conn->prepare($sqlDV);
    $stDV->bind_param('iss',$uid,$iniTS,$finTS);
    $stDV->execute(); $rsDV=$stDV->get_result();

    while($row=$rsDV->fetch_assoc()){
      $idDet=(int)$row['id'];
      $precioRef=(float)$row['precio_ref'];

      // Comisión propia (Ejecutivo por cuota)
      if ($aplicaEje) {
        $rolTmp = $ROL_EJE; $compTmp = $COMP_COMI;
        $selEquipo->bind_param('ssssidd',$rolTmp,$compTmp,$fin,$fin,$sid,$precioRef,$precioRef);
        $selEquipo->execute(); $re=$selEquipo->get_result()->fetch_assoc();
        if ($re && $re['monto_fijo']!==null) {
          $v=(float)$re['monto_fijo']; $updDV_Eje->bind_param('di',$v,$idDet); $updDV_Eje->execute();
          $stats['equipos']++;
        }
      }
      // Si el vendedor es Gerente, su propia comisión usa el esquema de Gerente
      if ($isGerenteVendedor) {
        $rolTmp = $ROL_GER; $compTmp = $COMP_COMI;
        $selEquipo->bind_param('ssssidd',$rolTmp,$compTmp,$fin,$fin,$sid,$precioRef,$precioRef);
        $selEquipo->execute(); $rOwn=$selEquipo->get_result()->fetch_assoc();
        if ($rOwn && $rOwn['monto_fijo']!==null) {
          $v=(float)$rOwn['monto_fijo']; $updDV_Eje->bind_param('di',$v,$idDet); $updDV_Eje->execute();
          $stats['equipos']++;
        }
      }

      // Comisión Gerente sobre la venta:
      if ($isGerenteVendedor) {
        // Ventas hechas por Gerente NO generan comision_gerente
        if ($updDV_Ger) { $v=0.0; $updDV_Ger->bind_param('di',$v,$idDet); $updDV_Ger->execute(); }
      } else if ($aplicaGer) {
        $rolTmp = $ROL_GER; $compTmp = $COMP_COMI_GER;
        $selEquipo->bind_param('ssssidd',$rolTmp,$compTmp,$fin,$fin,$sid,$precioRef,$precioRef);
        $selEquipo->execute(); $rg=$selEquipo->get_result()->fetch_assoc();
        if ($rg && $rg['monto_fijo']!==null) {
          $v=(float)$rg['monto_fijo']; $updDV_Ger->bind_param('di',$v,$idDet); $updDV_Ger->execute();
          $stats['equipos']++;
        }
      }
    }
    $stDV->close();

    /* ---- SIMs (ventas_sims) ---- */
    if ($hasSims) {
      $selOper = $colOperador ? $colOperador : "''";
      $selTipo = $colTipoSIM  ? $colTipoSIM  : "''";
      $sqlVS = "SELECT id, COALESCE(NULLIF(TRIM($selOper),''),'') AS operador,
                       COALESCE(NULLIF(TRIM($selTipo),''),'') AS tipo
                FROM ventas_sims
                WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?";
      $stVS=$conn->prepare($sqlVS);
      $stVS->bind_param('iss',$uid,$iniTS,$finTS);
      $stVS->execute(); $rsVS=$stVS->get_result();

      while($s=$rsVS->fetch_assoc()){
        $idS=(int)$s['id'];
        $op =(string)$s['operador'];
        $tipo=(string)$s['tipo'];
        // subtipo normalizado
        $isPos = (stripos($tipo,'posp')!==false);
        $sub = $isPos ? 'Pospago' : (stripos($tipo,'port')!==false ? 'Portabilidad' : 'Nueva');

        // Comisión propia
        if ($aplicaEje && $updVS_Eje) {
          $rolTmp = $ROL_EJE; $compTmp = $COMP_COMI;
          $selSIM->bind_param('ssssssi',$rolTmp,$compTmp,$sub,$op,$fin,$fin,$sid);
          $selSIM->execute(); $re=$selSIM->get_result()->fetch_assoc();
          if ($re && $re['monto_fijo']!==null) {
            $v=(float)$re['monto_fijo']; $updVS_Eje->bind_param('di',$v,$idS); $updVS_Eje->execute();
            $isPos ? $stats['pospago']++ : $stats['sims']++;
          }
        }
        // Si la vendió un Gerente, forzar comision_gerente=0
        if ($isGerenteVendedor && $updVS_Ger) {
          $v=0.0; $updVS_Ger->bind_param('di',$v,$idS); $updVS_Ger->execute();
        } else if ($aplicaGer && $updVS_Ger) {
          // Solo si NO es venta de Gerente
          $rolTmp = $ROL_GER; $compTmp = $COMP_COMI_GER;
          $selSIM->bind_param('ssssssi',$rolTmp,$compTmp,$sub,$op,$fin,$fin,$sid);
          $selSIM->execute(); $rg=$selSIM->get_result()->fetch_assoc();
          if ($rg && $rg['monto_fijo']!==null) {
            $v=(float)$rg['monto_fijo']; $updVS_Ger->bind_param('di',$v,$idS); $updVS_Ger->execute();
            $isPos ? $stats['pospago']++ : $stats['sims']++;
          }
        }
      }
      $stVS->close();
    }

    /* ---- Tarjeta / PayJoy (ventas_payjoy_tc) ----
       Regla nueva:
       - Ejecutivo: $50 por TC si llegó a cuota de unidades (aplicaEje=true).
       - Gerente: $20 por TC de la sucursal si la sucursal llegó a cuota de monto (aplicaGer=true).
       - Si la venta la hizo un Gerente: comision_gerente siempre 0 (igual que antes).
       - Si NO hay cuota: ambas comisiones = 0.
    ------------------------------------------------ */
    if ($hasTC) {
      // Primero traemos todas las TC de este usuario en la semana
      $stTC=$conn->prepare("
        SELECT id
        FROM ventas_payjoy_tc
        WHERE id_usuario=? AND fecha_venta BETWEEN ? AND ?
      ");
      $stTC->bind_param('iss',$uid,$iniTS,$finTS);
      $stTC->execute(); $rsTC=$stTC->get_result();

      while($t=$rsTC->fetch_assoc()){
        $idT=(int)$t['id'];

        // Siempre partimos de 0 para evitar arrastrar valores viejos
        if ($updTC_Eje) {
          $v0 = 0.0;
          $updTC_Eje->bind_param('di',$v0,$idT);
          $updTC_Eje->execute();
        }
        if ($updTC_Ger) {
          $v0g = 0.0;
          $updTC_Ger->bind_param('di',$v0g,$idT);
          $updTC_Ger->execute();
        }

        // Comisión propia del vendedor (Ejecutivo o Gerente como VENDEDOR)
        if ($aplicaEje && $updTC_Eje) {
          // Por política: $50 por cada TC si llegó a cuota de unidades
          $v = $TC_COMISION_EJE;
          $updTC_Eje->bind_param('di',$v,$idT);
          $updTC_Eje->execute();
          $stats['tc']++;
        }

        // Comisión Gerente sobre esa venta:
        if ($isGerenteVendedor && $updTC_Ger) {
          // Si la vendió un Gerente, comision_gerente = 0 (ya se dejó en 0 arriba)
          // No hacemos nada más aquí
        } else if ($aplicaGer && $updTC_Ger) {
          // Solo si NO es venta de Gerente y la sucursal llegó a cuota de monto
          $v = $TC_COMISION_GER;
          $updTC_Ger->bind_param('di',$v,$idT);
          $updTC_Ger->execute();
          $stats['tc']++;
        }
      }
      $stTC->close();
    }
  }

  echo json_encode([
    'status'=>'ok',
    'ini'=>$ini,'fin'=>$fin,
    'stats'=>$stats
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  fail('Excepción: '.$e->getMessage(), 500);
}
