<?php
// tickets_cambiar_estado.php — Cambia estado con transiciones válidas
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
$estadoNew= trim($_POST['estado'] ?? '');

$valid = ['abierto','en_progreso','resuelto','cerrado'];
if ($ticketId <= 0 || !in_array($estadoNew, $valid, true)) back('', 'Datos inválidos');

// Leer estado actual
$stmt = $conn->prepare("SELECT estado FROM tickets WHERE id=? LIMIT 1");
$stmt->bind_param('i', $ticketId);
$stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$r) back('', 'Ticket no encontrado');
$estadoOld = $r['estado'] ?? 'abierto';

// Reglas de transición (ajusta a tu gusto)
$transiciones = [
  'abierto'      => ['en_progreso','resuelto','cerrado'],
  'en_progreso'  => ['resuelto','cerrado'],
  'resuelto'     => ['cerrado','en_progreso'],
  'cerrado'      => ['en_progreso'], // permitir reabrir como en_progreso
];

if (!in_array($estadoNew, $transiciones[$estadoOld] ?? [], true)) {
  back('', "Transición no válida: $estadoOld → $estadoNew");
}

$st = $conn->prepare("UPDATE tickets SET estado=?, updated_at=NOW() WHERE id=?");
$st->bind_param('si', $estadoNew, $ticketId);
if (!$st->execute()) { $st->close(); back('', 'No se pudo cambiar el estado'); }
$st->close();

back("Estado actualizado a {$estadoNew}.");
