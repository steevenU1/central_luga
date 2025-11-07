<?php
// tickets.reply.php — Agrega mensaje a un ticket (NANO, MIPLAN o LUGA)
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/_json.php';

date_default_timezone_set('America/Mexico_City');

// 1) Autenticación: resuelve origen desde el Bearer
$origen = require_origen(['NANO','MIPLAN','LUGA']); // permite estos 3 orígenes

// 2) Entrada JSON
$in       = json_input(); // asume que _json.php valida y convierte JSON
$ticketId = req_int($in, 'ticket_id', 0);
$autorId  = req_int($in, 'autor_id', 0); // ID del usuario en el sistema origen (opcional)
$mensaje  = trim(req_str($in, 'mensaje', ''));

// 3) Validaciones de negocio
if ($ticketId <= 0 || $mensaje === '') {
  respond(['ok'=>false, 'error'=>'datos_invalidos'], 422);
}
// límites razonables
if (mb_strlen($mensaje) > 4000) {
  respond(['ok'=>false, 'error'=>'mensaje_muy_largo'], 422);
}

// 4) Verificar existencia y pertenencia del ticket
if ($origen === 'LUGA') {
  // LUGA puede responder cualquier ticket
  $stmt = $conn->prepare("SELECT id FROM tickets WHERE id=? LIMIT 1");
  $stmt->bind_param('i', $ticketId);
} else {
  // NANO / MIPLAN solo a sus tickets
  $stmt = $conn->prepare("SELECT id FROM tickets WHERE id=? AND sistema_origen=? LIMIT 1");
  $stmt->bind_param('is', $ticketId, $origen);
}
if (!$stmt) { respond(['ok'=>false,'error'=>'prep_ticket'], 500); }
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) { $stmt->close(); respond(['ok'=>false,'error'=>'ticket_no_encontrado'], 404); }
$stmt->close();

// 5) Guardar mensaje + actualizar updated_at en una transacción
$conn->begin_transaction();
try {
  $stmt2 = $conn->prepare(
    "INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
     VALUES (?, ?, ?, ?)"
  );
  if (!$stmt2) { throw new Exception('prep_insert_msg'); }
  $stmt2->bind_param('isis', $ticketId, $origen, $autorId, $mensaje);
  if (!$stmt2->execute()) { throw new Exception('exec_insert_msg'); }
  $stmt2->close();

  $stmt3 = $conn->prepare("UPDATE tickets SET updated_at = NOW() WHERE id = ?");
  if (!$stmt3) { throw new Exception('prep_update_ticket'); }
  $stmt3->bind_param('i', $ticketId);
  if (!$stmt3->execute()) { throw new Exception('exec_update_ticket'); }
  $stmt3->close();

  $conn->commit();
  respond(['ok'=>true, 'ticket_id'=>$ticketId]);
} catch (Throwable $e) {
  $conn->rollback();
  // Log opcional: error_log('[tickets.reply] '.$e->getMessage());
  respond(['ok'=>false, 'error'=>'no_se_pudo_guardar'], 500);
}
