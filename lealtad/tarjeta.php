<?php
// lealtad/tarjeta.php
// Tarjeta digital de lealtad LUGA: muestra nombre del cliente, puntos actuales y código de referido.

require_once __DIR__ . '/../db.php';

date_default_timezone_set('America/Mexico_City');

/* =========================
   Helpers
========================= */

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function tableExists(mysqli $conn, string $table): bool {
    $t = $conn->real_escape_string($table);
    $sql = "
        SELECT 1
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = '$t'
        LIMIT 1
    ";
    $res = $conn->query($sql);
    return $res && $res->num_rows > 0;
}

function obtenerParametrosLealtadVigentes(mysqli $conn): ?array {
    if (!tableExists($conn, 'lealtad_parametros')) {
        return null;
    }
    $sql = "
        SELECT *
        FROM lealtad_parametros
        WHERE vigente_desde <= CURDATE()
          AND (vigente_hasta IS NULL OR vigente_hasta >= CURDATE())
        ORDER BY vigente_desde DESC
        LIMIT 1
    ";
    $res = $conn->query($sql);
    if ($res && $row = $res->fetch_assoc()) {
        return $row;
    }
    return null;
}

/* =========================
   1) Recibir código
========================= */

$code = trim($_GET['code'] ?? '');

$tarjeta = null;
$cliente = null;
$sucursalNombre = null;
$params = null;
$error = '';

if ($code === '') {
    $error = 'No se recibió ningún código de tarjeta.';
} elseif (!tableExists($conn, 'lealtad_tarjetas')) {
    $error = 'El módulo de lealtad no está disponible en este sistema.';
} else {
    // Buscar por codigo_referido o por codigo_tarjeta
    $sql = "
        SELECT t.id,
               t.id_cliente,
               t.codigo_tarjeta,
               t.codigo_referido,
               t.url_tarjeta,
               t.puntos_actuales,
               t.activo,
               c.nombre      AS cliente_nombre,
               c.telefono    AS cliente_telefono,
               c.id_sucursal AS cliente_id_sucursal,
               s.nombre      AS sucursal_nombre
        FROM lealtad_tarjetas t
        LEFT JOIN clientes   c ON c.id = t.id_cliente
        LEFT JOIN sucursales s ON s.id = c.id_sucursal
        WHERE t.codigo_referido = ? OR t.codigo_tarjeta = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $code, $code);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        if ((int)$row['activo'] !== 1) {
            $error = 'Esta tarjeta está inactiva.';
        } else {
            $tarjeta = $row;
            $sucursalNombre = $row['sucursal_nombre'] ?? null;
            $params = obtenerParametrosLealtadVigentes($conn);
        }
    } else {
        $error = 'No se encontró ninguna tarjeta con ese código.';
    }
    $stmt->close();
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Tarjeta de Lealtad LUGA</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" type="image/x-icon" href="../img/favicon.ico?v=2">

    <!-- Bootstrap solo para tipografía básica, sin recargar demasiado -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --bg-page: #020617;
            --card-bg: #020617;
            --card-border: rgba(148, 163, 184, .4);
            --accent: #22c55e;
            --accent-soft: rgba(34, 197, 94, .12);
            --accent-strong: #16a34a;
            --text-main: #e5e7eb;
            --text-muted: #9ca3af;
            --chip-bg: rgba(15, 23, 42, .9);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, .18), transparent 55%),
                radial-gradient(circle at bottom right, rgba(34, 197, 94, .18), transparent 55%),
                var(--bg-page);
            color: var(--text-main);
        }

        .page-wrap {
            padding: 1.25rem;
            width: 100%;
            max-width: 460px;
        }

        .brand-label {
            text-align: center;
            margin-bottom: 1rem;
            color: var(--text-muted);
            font-size: .85rem;
            letter-spacing: .18em;
            text-transform: uppercase;
        }

        .card-loyalty {
            position: relative;
            border-radius: 1.75rem;
            padding: 1.6rem 1.8rem;
            background:
                radial-gradient(circle at top left, rgba(148, 163, 184, .18), transparent 52%),
                radial-gradient(circle at bottom right, rgba(34, 197, 94, .25), transparent 60%),
                var(--card-bg);
            box-shadow:
                0 22px 45px rgba(15, 23, 42, .65),
                0 0 0 1px var(--card-border);
            overflow: hidden;
        }

        .card-loyalty::before {
            content: "";
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 120% -10%, rgba(59, 130, 246, .18), transparent 65%);
            opacity: .7;
            pointer-events: none;
        }

        .card-inner {
            position: relative;
            z-index: 1;
        }

        .card-header-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .75rem;
            margin-bottom: 1.1rem;
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: .75rem;
        }

        .brand-logo-circle {
            width: 42px;
            height: 42px;
            border-radius: 999px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #22c55e, #16a34a);
            box-shadow: 0 0 0 2px rgba(15,23,42,.85), 0 12px 22px rgba(34,197,94,.4);
        }

        .brand-logo-circle img {
            max-width: 28px;
            max-height: 28px;
        }

        .brand-text-main {
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .brand-text-sub {
            font-size: .72rem;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: var(--text-muted);
        }

        .tag-pill {
            padding: .25rem .7rem;
            border-radius: 999px;
            font-size: .75rem;
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            border: 1px solid rgba(148, 163, 184, .65);
            background-color: rgba(15, 23, 42, .9);
            color: var(--text-muted);
        }

        .tag-pill span.icon-dot {
            width: 6px;
            height: 6px;
            border-radius: 999px;
            background: var(--accent);
        }

        .card-body-main {
            margin-top: .5rem;
        }

        .client-name {
            font-size: 1.15rem;
            font-weight: 600;
            letter-spacing: .03em;
            text-transform: uppercase;
            margin-bottom: .1rem;
        }

        .client-meta {
            font-size: .82rem;
            color: var(--text-muted);
            display: flex;
            flex-wrap: wrap;
            gap: .45rem .75rem;
        }

        .client-meta span {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
        }

        .client-meta i {
            font-size: .9rem;
            opacity: .7;
        }

        .code-block {
            margin-top: 1.3rem;
            padding: .85rem .9rem;
            border-radius: 1.1rem;
            background-color: rgba(15, 23, 42, .92);
            border: 1px solid rgba(148, 163, 184, .55);
        }

        .code-label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .16em;
            color: var(--text-muted);
            margin-bottom: .15rem;
        }

        .code-value {
            font-family: "SF Mono", ui-monospace, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: .97rem;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: #e5e7eb;
        }

        .code-alt {
            margin-top: .35rem;
            font-size: .72rem;
            color: var(--text-muted);
        }

        .points-row {
            display: flex;
            align-items: stretch;
            justify-content: space-between;
            gap: .75rem;
            margin-top: 1.2rem;
        }

        .points-main {
            flex: 1.25;
            border-radius: 1.1rem;
            background: radial-gradient(circle at top left, rgba(34,197,94,.22), transparent 65%),
                        rgba(15, 23, 42, .95);
            padding: .85rem .9rem;
            border: 1px solid rgba(34, 197, 94, .45);
        }

        .points-label {
            font-size: .78rem;
            letter-spacing: .16em;
            text-transform: uppercase;
            color: rgba(220,252,231,.9);
            margin-bottom: .1rem;
        }

        .points-value {
            font-size: 1.65rem;
            font-weight: 700;
            line-height: 1.1;
            color: #bbf7d0;
        }

        .points-caption {
            font-size: .78rem;
            margin-top: .2rem;
            color: rgba(187, 247, 208, .85);
        }

        .points-side {
            flex: .9;
            border-radius: 1.1rem;
            background-color: var(--chip-bg);
            padding: .8rem .75rem;
            border: 1px dashed rgba(148, 163, 184, .65);
            font-size: .75rem;
            color: var(--text-muted);
        }

        .points-side strong {
            color: #e5e7eb;
        }

        .footer-hint {
            margin-top: 1.1rem;
            font-size: .72rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            gap: .75rem;
            align-items: center;
        }

        .btn-copy {
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, .7);
            background: rgba(15, 23, 42, .9);
            color: #e5e7eb;
            padding: .25rem .7rem;
            font-size: .72rem;
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            cursor: pointer;
        }

        .btn-copy:hover {
            border-color: var(--accent);
            color: #bbf7d0;
        }

        .error-box {
            max-width: 420px;
            margin: 0 auto;
            border-radius: 1.25rem;
            padding: 1.75rem 1.5rem;
            background: rgba(15, 23, 42, .9);
            box-shadow: 0 22px 45px rgba(15, 23, 42, .75);
            border: 1px solid rgba(248, 113, 113, .55);
            color: #fecaca;
        }

        .error-box h1 {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: .35rem;
        }

        .error-box p {
            margin: 0;
            font-size: .9rem;
        }

        @media (max-width: 480px) {
            .card-loyalty {
                padding: 1.35rem 1.5rem;
                border-radius: 1.5rem;
            }
            .brand-text-main {
                font-size: .95rem;
            }
            .client-name {
                font-size: 1.05rem;
            }
            .points-row {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<div class="page-wrap">

    <?php if ($error): ?>
        <div class="brand-label">LUGA · Tarjeta de Lealtad</div>
        <div class="error-box">
            <h1>Ups, algo no cuadró</h1>
            <p><?= h($error) ?></p>
        </div>
    <?php else: ?>
        <?php
        $nombreCliente = trim($tarjeta['cliente_nombre'] ?? '');
        $telefono      = trim($tarjeta['cliente_telefono'] ?? '');
        $puntos        = (int)($tarjeta['puntos_actuales'] ?? 0);
        $codigoRef     = $tarjeta['codigo_referido'] ?? '';
        $codigoTarjeta = $tarjeta['codigo_tarjeta'] ?? '';
        $sucursal      = $sucursalNombre ?: 'Cliente LUGA';
        $vigenciaMeses = $params ? (int)($params['vigencia_puntos_meses'] ?? 6) : 6;
        $puntosRef     = $params ? (int)($params['puntos_por_referido'] ?? 10) : 10;
        ?>
        <div class="brand-label">
            LUGA · TARJETA DE LEALTAD
        </div>

        <div class="card-loyalty">
            <div class="card-inner">

                <div class="card-header-line">
                    <div class="brand-block">
                        <div class="brand-logo-circle">
                            <?php if (file_exists(__DIR__ . '/../img/logo-luga.svg')): ?>
                                <img src="../img/logo-luga.svg" alt="LUGA">
                            <?php else: ?>
                                <span style="font-weight:700;font-size:1.1rem;">L</span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="brand-text-main">LUGA</div>
                            <div class="brand-text-sub">TU RED VALE</div>
                        </div>
                    </div>

                    <div class="tag-pill">
                        <span class="icon-dot"></span>
                        <span>Cliente activo</span>
                    </div>
                </div>

                <div class="card-body-main">
                    <div class="client-name">
                        <?= $nombreCliente !== '' ? h($nombreCliente) : 'Cliente LUGA' ?>
                    </div>
                    <div class="client-meta">
                        <span>
                            <i class="bi bi-shop"></i><?= h($sucursal) ?>
                        </span>
                        <?php if ($telefono !== ''): ?>
                            <span>
                                <i class="bi bi-telephone"></i><?= h($telefono) ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="code-block mt-3">
                        <div class="code-label">Código de puntos</div>
                        <div class="code-value" id="codigo_ref">
                            <?= h($codigoRef) ?>
                        </div>
                        <?php if ($codigoTarjeta !== '' && $codigoTarjeta !== $codigoRef): ?>
                            <div class="code-alt">
                                ID de tarjeta: <strong><?= h($codigoTarjeta) ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="points-row">
                        <div class="points-main">
                            <div class="points-label">Puntos disponibles</div>
                            <div class="points-value"><?= number_format($puntos, 0) ?></div>
                            <div class="points-caption">
                                Puedes usarlos en descuentos en tu próxima compra, según el programa vigente.
                            </div>
                        </div>
                        <div class="points-side">
                            <?php if ($params): ?>
                                <div class="mb-1">
                                    <strong>Referidos:</strong><br>
                                    Por cada amigo referido ganas <strong><?= $puntosRef ?></strong> puntos.
                                </div>
                                <div>
                                    <strong>Vigencia:</strong><br>
                                    Los puntos tienen una vigencia de
                                    <strong><?= $vigenciaMeses ?> meses</strong> a partir del abono.
                                </div>
                            <?php else: ?>
                                <div class="mb-1">
                                    <strong>Programa activo</strong><br>
                                    Acumula puntos por tus compras y referidos.
                                </div>
                                <div>
                                    Consulta en tienda cómo canjearlos por descuentos.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="footer-hint">
                        <span>
                            Muestra esta tarjeta en caja para aplicar tus beneficios.
                        </span>
                        <button class="btn-copy" type="button" id="btnCopy">
                            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 16 16" fill="currentColor">
                                <path d="M10 1.5A1.5 1.5 0 0 1 11.5 3v6A1.5 1.5 0 0 1 10 10.5H4A1.5 1.5 0 0 1 2.5 9V3A1.5 1.5 0 0 1 4 1.5h6zm0 1H4a.5.5 0 0 0-.5.5v6A.5.5 0 0 0 4 9.5h6a.5.5 0 0 0 .5-.5V3a.5.5 0 0 0-.5-.5z"/>
                                <path d="M5 4.5A1.5 1.5 0 0 1 6.5 3h5A1.5 1.5 0 0 1 13 4.5v7A1.5 1.5 0 0 1 11.5 13h-5A1.5 1.5 0 0 1 5 11.5v-7zm1.5-.5a.5.5 0 0 0-.5.5v7a.5.5 0 0 0 .5.5h5a.5.5 0 0 0 .5-.5v-7a.5.5 0 0 0-.5-.5h-5z"/>
                            </svg>
                            Copiar código
                        </button>
                    </div>

                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var btn = document.getElementById('btnCopy');
    var codeEl = document.getElementById('codigo_ref');

    if (!btn || !codeEl) return;

    btn.addEventListener('click', function() {
        var text = codeEl.textContent.trim();
        if (!text) return;
        navigator.clipboard.writeText(text).then(function() {
            var original = btn.innerHTML;
            btn.innerHTML = 'Copiado';
            setTimeout(function() {
                btn.innerHTML = original;
            }, 1600);
        }).catch(function() {});
    });
});
</script>

</body>
</html>
