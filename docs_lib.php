<?php
// includes/docs_lib.php
require_once __DIR__ . '/docs_config.php';
require_once __DIR__ . '/db.php';

function ensure_upload_dir(int $usuario_id, string $codigo): string {
  $dir = rtrim(DOCS_BASE_PATH, '/\\') . "/usuarios/{$usuario_id}/{$codigo}";
  if (!is_dir($dir)) { mkdir($dir, 0775, true); }
  return $dir;
}

function validate_upload(array $file): array {
  if (!isset($file['error']) || is_array($file['error'])) return [false,'Carga inválida'];
  if ($file['error'] !== UPLOAD_ERR_OK) return [false,'Error al subir archivo'];
  if ($file['size'] > DOCS_MAX_SIZE) return [false,'Archivo supera el tamaño permitido'];
  $mime = mime_content_type($file['tmp_name']);
  if (!in_array($mime, DOCS_ALLOWED_MIME, true)) return [false,'Tipo de archivo no permitido'];
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, DOCS_ALLOWED_EXT, true)) return [false,'Extensión no permitida'];
  return [true,null];
}

function save_user_doc(mysqli $conn, int $usuario_id, int $doc_tipo_id, int $subido_por, array $file): array {
  [$ok,$err] = validate_upload($file);
  if (!$ok) return [false,$err];

  // obtener código del tipo
  $stmt = $conn->prepare("SELECT codigo FROM doc_tipos WHERE id=?");
  $stmt->bind_param('i',$doc_tipo_id);
  $stmt->execute();
  $codigo = ($stmt->get_result()->fetch_assoc()['codigo'] ?? null);
  $stmt->close();
  if (!$codigo) return [false,'Tipo de documento inválido'];

  // siguiente versión
  $stmt = $conn->prepare("SELECT IFNULL(MAX(version),0)+1 v FROM usuario_documentos WHERE usuario_id=? AND doc_tipo_id=?");
  $stmt->bind_param('ii',$usuario_id,$doc_tipo_id);
  $stmt->execute();
  $nextv = (int)($stmt->get_result()->fetch_assoc()['v'] ?? 1);
  $stmt->close();

  $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  $mime = mime_content_type($file['tmp_name']);
  $hash = hash_file('sha256', $file['tmp_name']);
  $orig = preg_replace('/[^A-Za-z0-9_\.-]/','_', $file['name']);

  $dir = ensure_upload_dir($usuario_id, $codigo);
  $fname = "v{$nextv}_" . bin2hex(random_bytes(4)) . "." . $ext;
  $dest  = $dir . "/" . $fname;

  if (!move_uploaded_file($file['tmp_name'], $dest)) return [false,'No se pudo guardar el archivo'];

  $ruta_rel = "usuarios/{$usuario_id}/{$codigo}/{$fname}";
  $tamano = (int)filesize($dest);

  $stmt = $conn->prepare("INSERT INTO usuario_documentos
      (usuario_id, doc_tipo_id, version, ruta, nombre_original, mime, tamano, hash_sha256, vigente, subido_por)
      VALUES (?,?,?,?,?,?,?, ?,1,?)");
  $stmt->bind_param('iiisssisi', $usuario_id, $doc_tipo_id, $nextv, $ruta_rel, $orig, $mime, $tamano, $hash, $subido_por);
  $stmt->execute();
  $ok = $stmt->affected_rows > 0;
  $stmt->close();

  return [$ok, $ok ? null : 'No se pudo registrar en BD'];
}
