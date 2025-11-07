<?php
// tickets.get.php — Devuelve ticket + mensajes por id
require_once __DIR__.'/../db.php';
require_once __DIR__.'/_auth.php';

require_bearer('NANO'); // NANO puede leer

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  http_response_code(422);
  echo json_encode(['error'=>'id_invalido']); exit;
}

// Ticket
$stmt = $conn->prepare("SELECT id, asunto, estado, prioridad, sistema_origen,
                               sucursal_origen_id, creado_por_id, created_at, updated_at
                        FROM tickets WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$t = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$t) { http_response_code(404); echo json_encode(['error'=>'no_encontrado']); exit; }

// Mensajes
$mensajes=[];
$q = $conn->prepare("SELECT id, ticket_id, autor_sistema, autor_id, cuerpo, created_at
                     FROM ticket_mensajes WHERE ticket_id=? ORDER BY id ASC");
$q->bind_param('i',$id);
$q->execute();
$r = $q->get_result();
while($row=$r->fetch_assoc()) $mensajes[]=$row;
$q->close();

echo json_encode(['ticket'=>$t, 'mensajes'=>$mensajes]);
