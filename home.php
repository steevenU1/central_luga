<?php
// home.php — Menú principal tipo "Netflix" para Ejecutivos / Gerentes (Luga & Nano)

session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');

$rol            = $_SESSION['rol']        ?? 'Ejecutivo';
$nombreUsuario  = $_SESSION['nombre']     ?? 'Usuario';
$nombreSucursal = $_SESSION['sucursal']   ?? ($_SESSION['nombre_sucursal'] ?? 'Sucursal');

// ==== RUTAS (ajusta aquí si algún archivo se llama distinto en cada sistema) ====
$RUTA_VENDER_EQUIPO      = 'nueva_venta.php';
$RUTA_VENDER_SIM         = 'venta_sim_prepago.php';   // o venta_sim.php / venta_sim_pospago.php según tu sistema
$RUTA_VENDER_ACCESORIO   = 'venta_accesorios.php';
$RUTA_ENTREGA_TC         = 'venta_tc.php';            // pon aquí tu vista real para entrega de tarjeta/crédito
$RUTA_COBROS             = 'cobros.php';
$RUTA_CORTE_CAJA         = 'cortes_caja.php';         // o corte_caja.php, ajusta al nombre real
$RUTA_TRASPASOS          = 'traspasos.php';           // si tu módulo de traspasos tiene otro nombre, cámbialo
$RUTA_VENTAS_SUCURSAL    = 'historial_ventas.php';    // para gerentes, ajusta si tienes otra vista
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Home | Central</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Si ya cargas Bootstrap global, esto es opcional.
         Si no, te sirve de respaldo: -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <style>
        body {
            background: #f3f4f6;
        }

        .home-wrapper {
            max-width: 1200px;
            margin: 80px auto 40px;
            padding: 0 16px 40px;
        }

        .home-header {
            margin-bottom: 24px;
        }

        .home-title {
            font-size: 1.9rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
        }

        .home-subtitle {
            font-size: 0.95rem;
            color: #6b7280;
        }

        .home-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px;
            background: #e5f2ff;
            color: #1d4ed8;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 500;
            margin-bottom: 8px;
        }

        .home-section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #374151;
            margin: 24px 0 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .home-section-title span.icon {
            font-size: 1.2rem;
        }

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 20px;
        }

        .menu-card {
            text-decoration: none;
            background: #ffffff;
            border-radius: 18px;
            padding: 22px 18px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 6px 14px rgba(15, 23, 42, 0.06);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 150px;
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
            color: inherit;
        }

        .menu-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.12);
            border-color: #2563eb;
        }

        .menu-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }

        .menu-icon {
            width: 44px;
            height: 44px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .menu-icon-primary   { background: #dbeafe; color: #1d4ed8; }
        .menu-icon-green     { background: #dcfce7; color: #15803d; }
        .menu-icon-amber     { background: #fef3c7; color: #b45309; }
        .menu-icon-rose      { background: #ffe4e6; color: #be123c; }
        .menu-icon-slate     { background: #e5e7eb; color: #111827; }
        .menu-icon-purple    { background: #ede9fe; color: #6d28d9; }
        .menu-icon-cyan      { background: #cffafe; color: #0e7490; }

        .menu-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #111827;
        }

        .menu-description {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 6px;
        }

        .menu-footer {
            margin-top: 8px;
            font-size: 0.8rem;
            color: #9ca3af;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .menu-footer span.badge-soft {
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid #e5e7eb;
        }

        @media (max-width: 576px) {
            .home-wrapper {
                margin-top: 70px;
            }

            .home-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>

<div class="home-wrapper">
    <div class="home-header">
        <div class="home-badge">
            <span>✨</span>
            <span>Centro de acciones rápidas</span>
        </div>
        <div class="home-title">
            Hola, <?php echo htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <div class="home-subtitle">
            Rol: <?php echo htmlspecialchars($rol, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($nombreSucursal): ?>
                · Sucursal: <?php echo htmlspecialchars($nombreSucursal, ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Sección: Ventas -->
    <div class="home-section">
        <div class="home-section-title">
            <span class="icon">🛒</span>
            <span>Ventas</span>
        </div>
        <div class="menu-grid">
            <!-- Vender Equipo -->
            <a href="<?php echo htmlspecialchars($RUTA_VENDER_EQUIPO, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                <div>
                    <div class="menu-card-header">
                        <div class="menu-icon menu-icon-primary">📱</div>
                        <div class="menu-title">Vender equipo</div>
                    </div>
                    <div class="menu-description">
                        Captura de venta de equipo con lista de precios, combo y financiamiento.
                    </div>
                </div>
                <div class="menu-footer">
                    <span class="badge-soft">Venta principal</span>
                    <span>Entrar →</span>
                </div>
            </a>

            <!-- Vender SIM -->
            <a href="<?php echo htmlspecialchars($RUTA_VENDER_SIM, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                <div>
                    <div class="menu-card-header">
                        <div class="menu-icon menu-icon-green">🔄</div>
                        <div class="menu-title">Vender SIM</div>
                    </div>
                    <div class="menu-description">
                        Venta de SIMs (prepago / portabilidad / pospago según tu flujo).
                    </div>
                </div>
                <div class="menu-footer">
                    <span class="badge-soft">SIMs · DN / ICCID</span>
                    <span>Entrar →</span>
                </div>
            </a>

            <!-- Vender Accesorios -->
            <a href="<?php echo htmlspecialchars($RUTA_VENDER_ACCESORIO, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                <div>
                    <div class="menu-card-header">
                        <div class="menu-icon menu-icon-amber">🔌</div>
                        <div class="menu-title">Vender accesorios</div>
                    </div>
                    <div class="menu-description">
                        Venta normal o regalo ligado a TAG de equipo, según configuración.
                    </div>
                </div>
                <div class="menu-footer">
                    <span class="badge-soft">Accesorios</span>
                    <span>Entrar →</span>
                </div>
            </a>

            <!-- Entrega de Tarjeta de Crédito -->
            <a href="<?php echo htmlspecialchars($RUTA_ENTREGA_TC, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                <div>
                    <div class="menu-card-header">
                        <div class="menu-icon menu-icon-purple">💳</div>
                        <div class="menu-title">Entrega de tarjeta</div>
                    </div>
                    <div class="menu-description">
                        Registro de entrega de tarjeta de crédito / financiamiento.
                    </div>
                </div>
                <div class="menu-footer">
                    <span class="badge-soft">Crédito</span>
                    <span>Entrar →</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Sección: Cobros / Caja -->
    <div class="home-section">
        <div class="home-section-title">
            <span class="icon">💵</span>
            <span>Cobros y caja</span>
        </div>
        <div class="menu-grid">
            <!-- Generar Cobro -->
            <a href="<?php echo htmlspecialchars($RUTA_COBROS, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                <div>
                    <div class="menu-card-header">
                        <div class="menu-icon menu-icon-rose">💰</div>
                        <div class="menu-title">Generar cobro</div>
                    </div>
                    <div class="menu-description">
                        Cobros varios: enganches, pagos iniciales, Innovación Móvil, etc.
                    </div>
                </div>
                <div class="menu-footer">
                    <span class="badge-soft">Tickets · Recibos</span>
                    <span>Entrar →</span>
                </div>
            </a>

            <!-- Generar Corte de Caja -->
            <a href="<?php echo htmlspecialchars($RUTA_CORTE_CAJA, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                <div>
                    <div class="menu-card-header">
                        <div class="menu-icon menu-icon-slate">✂️</div>
                        <div class="menu-title">Generar corte</div>
                    </div>
                    <div class="menu-description">
                        Corte de caja del día con ventas, cobros y depósitos.
                    </div>
                </div>
                <div class="menu-footer">
                    <span class="badge-soft">Fin de turno</span>
                    <span>Entrar →</span>
                </div>
            </a>
        </div>
    </div>

    <!-- Sección: Gestión de sucursal (solo Gerente / Admin / Logistica / GerenteZona) -->
    <?php if (in_array($rol, ['Gerente','Admin','Logistica','GerenteZona'], true)): ?>
        <div class="home-section">
            <div class="home-section-title">
                <span class="icon">🏪</span>
                <span>Gestión de sucursal</span>
            </div>
            <div class="menu-grid">
                <!-- Traspasos -->
                <a href="<?php echo htmlspecialchars($RUTA_TRASPASOS, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                    <div>
                        <div class="menu-card-header">
                            <div class="menu-icon menu-icon-cyan">🚚</div>
                            <div class="menu-title">Traspasos</div>
                        </div>
                        <div class="menu-description">
                            Generar y recibir traspasos de equipos y accesorios entre sucursales.
                        </div>
                    </div>
                    <div class="menu-footer">
                        <span class="badge-soft">Movimientos</span>
                        <span>Entrar →</span>
                    </div>
                </a>

                <!-- Ventas Sucursal / Reporte -->
                <a href="<?php echo htmlspecialchars($RUTA_VENTAS_SUCURSAL, ENT_QUOTES, 'UTF-8'); ?>" class="menu-card">
                    <div>
                        <div class="menu-card-header">
                            <div class="menu-icon menu-icon-primary">📊</div>
                            <div class="menu-title">Ventas de sucursal</div>
                        </div>
                        <div class="menu-description">
                            Historial de ventas por sucursal con filtros y exportación.
                        </div>
                    </div>
                    <div class="menu-footer">
                        <span class="badge-soft">Reporte</span>
                        <span>Entrar →</span>
                    </div>
                </a>
            </div>
        </div>
    <?php endif; ?>

</div>

</body>
</html>
