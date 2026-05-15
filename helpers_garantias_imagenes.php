<?php
if (!function_exists('garantias_imagenes_dir_base')) {
    function garantias_imagenes_dir_base(): string {
        return __DIR__ . '/uploads/garantias';
    }
}

if (!function_exists('garantias_imagenes_url_base')) {
    function garantias_imagenes_url_base(): string {
        return 'uploads/garantias';
    }
}

if (!function_exists('garantias_imagenes_permitidas')) {
    function garantias_imagenes_permitidas(): array {
        return [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];
    }
}

if (!function_exists('garantias_asegurar_directorio')) {
    function garantias_asegurar_directorio(string $dir): bool {
        if (is_dir($dir)) {
            return true;
        }
        return mkdir($dir, 0775, true);
    }
}

if (!function_exists('guardar_imagenes_garantia')) {
    function guardar_imagenes_garantia(mysqli $conn, int $idGarantia, int $idUsuario, array $files): array {
        $resultado = [
            'ok' => true,
            'guardadas' => 0,
            'errores' => [],
        ];

        if (
            !isset($files['name']) ||
            !is_array($files['name']) ||
            count(array_filter($files['name'])) === 0
        ) {
            return $resultado;
        }

        $maxArchivos = 5;
        $maxPeso = 4 * 1024 * 1024; // 4 MB
        $permitidas = garantias_imagenes_permitidas();

        $total = count($files['name']);
        if ($total > $maxArchivos) {
            return [
                'ok' => false,
                'guardadas' => 0,
                'errores' => ['Solo se permiten hasta 5 imágenes.']
            ];
        }

        $dirCaso = garantias_imagenes_dir_base() . '/caso_' . $idGarantia;
        $urlCaso = garantias_imagenes_url_base() . '/caso_' . $idGarantia;

        if (!garantias_asegurar_directorio($dirCaso)) {
            return [
                'ok' => false,
                'guardadas' => 0,
                'errores' => ['No se pudo crear el directorio para las imágenes.']
            ];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        for ($i = 0; $i < $total; $i++) {
            $nombreOriginal = trim((string)($files['name'][$i] ?? ''));
            $tmp = $files['tmp_name'][$i] ?? '';
            $error = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;
            $peso = (int)($files['size'][$i] ?? 0);

            if ($nombreOriginal === '' || $error === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($error !== UPLOAD_ERR_OK) {
                $resultado['errores'][] = "Error al subir el archivo {$nombreOriginal}.";
                continue;
            }

            if ($peso <= 0 || $peso > $maxPeso) {
                $resultado['errores'][] = "El archivo {$nombreOriginal} excede el tamaño permitido de 4 MB.";
                continue;
            }

            $mime = finfo_file($finfo, $tmp);
            if (!isset($permitidas[$mime])) {
                $resultado['errores'][] = "El archivo {$nombreOriginal} no tiene un formato permitido.";
                continue;
            }

            $extension = $permitidas[$mime];
            $nombreGuardado = 'garantia_' . $idGarantia . '_' . ($i + 1) . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
            $rutaFisica = $dirCaso . '/' . $nombreGuardado;
            $rutaRelativa = $urlCaso . '/' . $nombreGuardado;

            if (!move_uploaded_file($tmp, $rutaFisica)) {
                $resultado['errores'][] = "No se pudo guardar el archivo {$nombreOriginal}.";
                continue;
            }

            $stmt = $conn->prepare("
                INSERT INTO garantias_imagenes
                (id_garantia, ruta_archivo, nombre_original, nombre_guardado, mime_type, peso_bytes, id_usuario_subio)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "issssii",
                $idGarantia,
                $rutaRelativa,
                $nombreOriginal,
                $nombreGuardado,
                $mime,
                $peso,
                $idUsuario
            );
            $stmt->execute();
            $stmt->close();

            $resultado['guardadas']++;
        }

        finfo_close($finfo);

        if (!empty($resultado['errores'])) {
            $resultado['ok'] = false;
        }

        return $resultado;
    }
}

if (!function_exists('obtener_imagenes_garantia')) {
    function obtener_imagenes_garantia(mysqli $conn, int $idGarantia): array {
        $imagenes = [];

        $stmt = $conn->prepare("
            SELECT id, ruta_archivo, nombre_original, nombre_guardado, mime_type, peso_bytes, fecha_subida
            FROM garantias_imagenes
            WHERE id_garantia = ?
            ORDER BY id ASC
        ");
        $stmt->bind_param("i", $idGarantia);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $imagenes[] = $row;
        }

        $stmt->close();
        return $imagenes;
    }
}

if (!function_exists('eliminar_imagenes_garantia')) {
    function eliminar_imagenes_garantia(mysqli $conn, int $idGarantia): array {
        $resultado = [
            'ok' => true,
            'eliminadas' => 0,
            'errores' => []
        ];

        $stmt = $conn->prepare("SELECT id, ruta_archivo FROM garantias_imagenes WHERE id_garantia = ?");
        $stmt->bind_param("i", $idGarantia);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        foreach ($rows as $img) {
            $rutaRelativa = (string)$img['ruta_archivo'];
            $rutaFisica = __DIR__ . '/' . ltrim($rutaRelativa, '/');

            if (is_file($rutaFisica)) {
                if (!unlink($rutaFisica)) {
                    $resultado['errores'][] = "No se pudo eliminar el archivo físico: {$rutaRelativa}";
                    $resultado['ok'] = false;
                    continue;
                }
            }

            $del = $conn->prepare("DELETE FROM garantias_imagenes WHERE id = ?");
            $idImg = (int)$img['id'];
            $del->bind_param("i", $idImg);
            $del->execute();
            $del->close();

            $resultado['eliminadas']++;
        }

        $dirCaso = garantias_imagenes_dir_base() . '/caso_' . $idGarantia;
        if (is_dir($dirCaso)) {
            @rmdir($dirCaso);
        }

        return $resultado;
    }
}   