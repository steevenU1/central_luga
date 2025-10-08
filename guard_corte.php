<?php
// guard_corte.php
function debe_bloquear_captura(mysqli $conn, int $idSucursal): array {
  // Devuelve [bool $bloquear, string $motivo, string $ayer]
  date_default_timezone_set('America/Mexico_City');
  $ayer = (new DateTime('yesterday'))->format('Y-m-d');

  // 1) ¿Hubo cobros ayer?
  $sqlCobros = "SELECT COUNT(*) AS n FROM cobros WHERE id_sucursal=? AND DATE(fecha_cobro)=?";
  $st = $conn->prepare($sqlCobros);
  $st->bind_param('is', $idSucursal, $ayer);
  $st->execute();
  $nCobros = (int)$st->get_result()->fetch_assoc()['n'];
  $st->close();

  // Si no hubo cobros, por defecto no bloqueamos (ajústalo si lo quieres estricto)
  if ($nCobros === 0) {
    return [false, "Sin cobros ayer ($ayer).", $ayer];
  }

  // 2) ¿Existe corte de AYER?
  $sqlCorte = "SELECT id, estado FROM cortes_caja WHERE id_sucursal=? AND DATE(fecha_operacion)=? LIMIT 1";
  $st2 = $conn->prepare($sqlCorte);
  $st2->bind_param('is', $idSucursal, $ayer);
  $st2->execute();
  $row = $st2->get_result()->fetch_assoc();
  $st2->close();

  if (!$row) {
    return [true, "La sucursal no generó el corte del día $ayer.", $ayer];
  }

  // Si existe, consideramos que “generó su corte” (estado puede ser Pendiente o Cerrado)
  return [false, "Corte #{$row['id']} encontrado (estado: {$row['estado']}).", $ayer];
}
