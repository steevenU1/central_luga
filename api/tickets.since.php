<?php
// Devuelve tickets modificados después de ?since=YYYY-mm-ddTHH:MM:SS (UTC o local consistente)
require_once __DIR__.'/../db.php';
require_once __DIR__.'/_auth.php';
require_once __DIR__.'/_json.php';

require_bearer('NANO');

$since = $_GET['since'] ?? '1970-01-01T00:00:00';
$since = str_replace('T',' ',$since);

// Trae tickets
$stmt = $conn->prepare("
  SELECT id, asunto, estado, prioridad, sistema_origen, sucursal_origen_id, creado_por_id, created_at, updated_at
  FROM tickets
  WHERE updated_at > ?
  ORDER BY updated_at ASC
  LIMIT 2000
");
$stmt->bind_param('s', $since);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Trae mensajes de esos tickets
$mensajes = [];
if ($tickets) {
  $ids = array_column($tickets, 'id');
  $in  = implode(',', array_map('intval', $ids));
  $q = $conn->query("SELECT id, ticket_id, autor_sistema, autor_id, cuerpo, created_at
                     FROM ticket_mensajes
                     WHERE ticket_id IN ($in)
                     ORDER BY ticket_id ASC, id ASC");
  if ($q) $mensajes = $q->fetch_all(MYSQLI_ASSOC);
}

respond(['tickets'=>$tickets, 'mensajes'=>$mensajes]);
