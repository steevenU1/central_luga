<?php
// tickets.create.php — Crea ticket + primer mensaje (emisor: NANO / MIPLAN / LUGA vía token)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_json.php';

date_default_timezone_set('America/Mexico_City');

/*
  Cambios clave vs tu versión:
  - Autenticación multi-origen: require_origen(['NANO','MIPLAN','LUGA'])
  - sistema_origen y autor_sistema usan el ORIGEN del token (no hardcode 'NANO')
  - Acepta 'autor_id' o 'creado_por_id' (compat)
  - Valida prioridad contra una whitelist
  - Transacción + prepared en UPDATE/INSERT
  - Respuesta incluye 'ok', 'id' y 'ticket_id' (compat con ambos clientes)
*/

$origen = require_origen(['NANO','MIPLAN','LUGA']); // ← token define el origen

$in         = json_input();
$asunto     = trim(req_str($in, 'asunto', ''));
$mensaje    = trim(req_str($in, 'mensaje', ''));
$prioridad  = strtolower(trim(req_str($in, 'prioridad', 'media')));
$sucId      = req_int($in, 'sucursal_origen_id', 0);

// Compat: algunos clientes envían 'creado_por_id', otros 'autor_id'
$usrIdBody1 = req_int($in, 'creado_por_id', 0);
$usrIdBody2 = req_int($in, 'autor_id', 0);
$usrId      = $usrIdBody2 ?: $usrIdBody1;

// Validaciones
if ($asunto === '' || $mensaje === '') {
  respond(['ok'=>false, 'error'=>'asunto_y_mensaje_requeridos'], 422);
}
if ($sucId <= 0) {
  respond(['ok'=>false, 'error'=>'sucursal_invalida'], 422);
}
$allowPrior = ['baja','media','alta','critica'];
if (!in_array($prioridad, $allowPrior, true)) { $prioridad = 'media'; }
if (mb_strlen($asunto) > 255)   { $asunto   = mb_substr($asunto, 0, 255); }
if (mb_strlen($mensaje) > 4000) { $mensaje  = mb_substr($mensaje, 0, 4000); }

// Insert
$conn->begin_transaction();
try {
  // Ticket
  $stmt = $conn->prepare(
    "INSERT INTO tickets (sistema_origen, sucursal_origen_id, creado_por_id, asunto, prioridad)
     VALUES (?, ?, ?, ?, ?)"
  );
  if (!$stmt) { throw new Exception('prep_insert_ticket'); }
  $stmt->bind_param('siiss', $origen, $sucId, $usrId, $asunto, $prioridad);
  if (!$stmt->execute()) { throw new Exception('exec_insert_ticket'); }
  $ticketId = (int)$conn->insert_id;
  $stmt->close();

  // Primer mensaje
  $stmt2 = $conn->prepare(
    "INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
     VALUES (?, ?, ?, ?)"
  );
  if (!$stmt2) { throw new Exception('prep_insert_msg'); }
  $stmt2->bind_param('isis', $ticketId, $origen, $usrId, $mensaje);
  if (!$stmt2->execute()) { throw new Exception('exec_insert_msg'); }
  $stmt2->close();

  // Actualizar updated_at (opcional; INSERT ya la tiene, pero por claridad)
  $stmt3 = $conn->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
  if (!$stmt3) { throw new Exception('prep_update_ticket'); }
  $stmt3->bind_param('i', $ticketId);
  if (!$stmt3->execute()) { throw new Exception('exec_update_ticket'); }
  $stmt3->close();

  $conn->commit();

  // Respuesta compatible: algunos clientes esperan 'id', otros 'ticket_id'
  respond(['ok'=>true, 'id'=>$ticketId, 'ticket_id'=>$ticketId]);
} catch (Throwable $e) {
  $conn->rollback();
  // error_log('[tickets.create] '.$e->getMessage()); // útil en logs
  respond(['ok'=>false, 'error'=>'server', 'detail'=>'no_se_pudo_crear'], 500);
}
