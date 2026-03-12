<?php
// includes/notificaciones_tablero.php

require_once __DIR__ . '/mail_hostinger.php';

function nt_has_column(mysqli $conn, string $table, string $column): bool
{
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $sql = "SELECT 1
          FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = '{$t}'
            AND COLUMN_NAME = '{$c}'
          LIMIT 1";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function nt_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    return $scheme . '://' . $host . $basePath;
}

function nt_get_usuario(mysqli $conn, int $idUsuario): ?array
{
    if ($idUsuario <= 0) return null;

    $hasCorreo = nt_has_column($conn, 'usuarios', 'correo');
    $colCorreo = $hasCorreo ? 'correo' : "'' AS correo";

    $sql = "SELECT id, nombre, {$colCorreo}
          FROM usuarios
          WHERE id = ?
          LIMIT 1";

    $st = $conn->prepare($sql);
    if (!$st) return null;

    $st->bind_param("i", $idUsuario);
    $st->execute();
    $u = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$u) return null;

    return [
        'id'     => (int)$u['id'],
        'nombre' => (string)($u['nombre'] ?? ''),
        'correo' => trim((string)($u['correo'] ?? ''))
    ];
}

function nt_send_tarea_creada(
    mysqli $conn,
    int $idTarea,
    int $idResponsable,
    int $idActor,
    string $titulo,
    string $descripcion = ''
): array {
    if ($idTarea <= 0 || $idResponsable <= 0) {
        return ['ok' => true, 'skip' => 'sin_responsable'];
    }

    // Evitar mandarle correo al mismo que crea si además es responsable
    if ($idResponsable === $idActor) {
        return ['ok' => true, 'skip' => 'actor_es_responsable'];
    }

    $responsable = nt_get_usuario($conn, $idResponsable);
    $actor       = nt_get_usuario($conn, $idActor);

    if (!$responsable) {
        return ['ok' => false, 'error' => 'No se encontró responsable'];
    }

    $correo = trim((string)($responsable['correo'] ?? ''));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => true, 'skip' => 'responsable_sin_correo'];
    }

    $actorNombre = trim((string)($actor['nombre'] ?? 'Sistema'));
    $tituloSafe = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $descSafe   = nl2br(htmlspecialchars($descripcion, ENT_QUOTES, 'UTF-8'));

    $url = nt_base_url() . '/tarea_detalle.php?id=' . $idTarea;

    $subject = "Nueva tarea asignada: {$titulo}";

    $html = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;line-height:1.5">
      <h2 style="margin:0 0 12px 0;color:#111">Se te asignó una nueva tarea</h2>

      <p style="margin:0 0 10px 0">
        Hola <b>' . htmlspecialchars($responsable['nombre'], ENT_QUOTES, 'UTF-8') . '</b>,
        <br>
        <b>' . htmlspecialchars($actorNombre, ENT_QUOTES, 'UTF-8') . '</b> te asignó una tarea en el Tablero de Operación.
      </p>

      <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin:12px 0">
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;margin-bottom:6px">Título</div>
        <div style="font-size:16px;font-weight:700;margin-bottom:10px">' . $tituloSafe . '</div>

        ' . ($descripcion !== '' ? '
        <div style="font-size:12px;color:#6b7280;text-transform:uppercase;margin-bottom:6px">Descripción</div>
        <div style="font-size:14px;color:#333">' . $descSafe . '</div>
        ' : '') . '
      </div>

      <p style="margin:14px 0">
        <a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px;font-weight:700">
          Ver tarea
        </a>
      </p>

      <p style="margin:12px 0 0 0;color:#6b7280;font-size:12px">
        ID de tarea: #' . (int)$idTarea . '
      </p>
    </div>
  ';

    $text = "Se te asignó una nueva tarea.\n\n"
        . "Título: {$titulo}\n"
        . ($descripcion !== '' ? "Descripción: {$descripcion}\n" : '')
        . "Ver tarea: {$url}\n"
        . "ID de tarea: #{$idTarea}\n";

    return send_mail_hostinger([
        'to'      => $correo,
        'subject' => $subject,
        'html'    => $html,
        'text'    => $text
    ]);
}
