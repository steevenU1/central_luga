<?php
// tickets_guardar_luga.php — Inserta ticket (origen LUGA) + primer mensaje
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }

$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente','Ejecutivo']; // mismos que en el form
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';

function back($ok='', $err=''){
  if ($ok)  $_SESSION['flash_ok']  = $ok;
  if ($err) $_SESSION['flash_err'] = $err;
  header('Location: tickets_nuevo_luga.php');
  exit();
}

// CSRF
if (!hash_equals($_SESSION['ticket_csrf_luga'] ?? '', $_POST['csrf'] ?? '')) {
  back('', 'Token inválido o formulario duplicado. Refresca la página.');
}
// Consumir token para evitar dobles envíos
unset($_SESSION['ticket_csrf_luga']);

function clean($s){ return trim((string)$s); }

$asunto   = clean($_POST['asunto']  ?? '');
$mensaje  = clean($_POST['mensaje'] ?? '');
$prioridad= $_POST['prioridad']     ?? 'media';
$sucId    = (int)($_POST['sucursal_origen_id'] ?? ($_SESSION['id_sucursal'] ?? 0));
$usrId    = (int)($_SESSION['id_usuario'] ?? 0);

if ($asunto === '' || $mensaje === '') {
  back('', 'Asunto y mensaje son obligatorios.');
}

$conn->begin_transaction();
try {
  // Crear ticket (origen LUGA)
  $stmt = $conn->prepare("INSERT INTO tickets (sistema_origen, sucursal_origen_id, creado_por_id, asunto, prioridad)
                          VALUES ('LUGA', ?, ?, ?, ?)");
  if (!$stmt) { throw new Exception('Prepare tickets: '.$conn->error); }
  $stmt->bind_param('iiss', $sucId, $usrId, $asunto, $prioridad);
  if (!$stmt->execute()) { throw new Exception('Exec tickets: '.$stmt->error); }
  $ticketId = (int)$conn->insert_id;
  $stmt->close();

  // Primer mensaje
  $stmt2 = $conn->prepare("INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
                           VALUES (?, 'LUGA', ?, ?)");
  if (!$stmt2) { throw new Exception('Prepare mensajes: '.$conn->error); }
  $stmt2->bind_param('iis', $ticketId, $usrId, $mensaje);
  if (!$stmt2->execute()) { throw new Exception('Exec mensajes: '.$stmt2->error); }
  $stmt2->close();

  $conn->commit();

  // Regenerar CSRF para siguiente captura
  $_SESSION['ticket_csrf_luga'] = bin2hex(random_bytes(16));
  $_SESSION['flash_ok'] = "✅ Ticket creado (#{$ticketId}).";

  // Ir directo al operador (para seguirlo atendiendo) o de vuelta al nuevo
  header('Location: tickets_operador.php');
  exit();
} catch (Throwable $e) {
  $conn->rollback();
  error_log('[tickets_guardar_luga] '.$e->getMessage());
  back('', 'No se pudo guardar el ticket. Intenta de nuevo.');
}
