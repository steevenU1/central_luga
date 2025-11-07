<?php
// tickets_responder_luga.php — Inserta respuesta como LUGA
session_start();
if (!isset($_SESSION['id_usuario'])) { header("Location: index.php"); exit(); }
$ROL = $_SESSION['rol'] ?? '';
$ALLOWED = ['Admin','Logistica','Gerente'];
if (!in_array($ROL, $ALLOWED, true)) { header("Location: 403.php"); exit(); }

require_once __DIR__ . '/db.php';

function back($ok='', $err=''){
  if ($ok)  $_SESSION['flash_ok']  = $ok;
  if ($err) $_SESSION['flash_err'] = $err;
  header('Location: tickets_operador.php');
  exit();
}

$ticketId = (int)($_POST['ticket_id'] ?? 0);
$mensaje  = trim($_POST['mensaje'] ?? '');
$idUsuario = (int)($_SESSION['id_usuario'] ?? 0);

if ($ticketId <= 0 || $mensaje === '') back('', 'Datos inválidos');

// Verificar existencia de ticket
$stmt = $conn->prepare("SELECT id FROM tickets WHERE id=? LIMIT 1");
$stmt->bind_param('i', $ticketId);
$stmt->execute(); $stmt->store_result();
if ($stmt->num_rows === 0) { $stmt->close(); back('', 'El ticket no existe'); }
$stmt->close();

// Insert mensaje
$stmt2 = $conn->prepare("INSERT INTO ticket_mensajes (ticket_id, autor_sistema, autor_id, cuerpo)
                         VALUES (?, 'LUGA', ?, ?)");
$stmt2->bind_param('iis', $ticketId, $idUsuario, $mensaje);
if (!$stmt2->execute()){ $stmt2->close(); back('', 'No se pudo guardar el mensaje'); }
$stmt2->close();

// Bump updated_at
$conn->query("UPDATE tickets SET updated_at=NOW() WHERE id=".$ticketId);

back('Respuesta enviada.');
