<?php
session_start();
if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/navbar.php';

date_default_timezone_set('America/Mexico_City');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
@$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_general_ci");
@$conn->query("SET collation_connection = 'utf8mb4_general_ci'");

/* =========================================================
   PERMISOS
========================================================= */
$ROL = (string)($_SESSION['rol'] ?? '');
$ROLES_PERMITIDOS = [
    'Admin', 'Administrador',
    'Gerente', 'GerenteZona',
    'Ejecutivo', 'Logistica',
    'Subdis_Admin', 'Subdis_Gerente', 'Subdis_Ejecutivo'
];

if (!in_array($ROL, $ROLES_PERMITIDOS, true)) {
    http_response_code(403);
    exit('Sin permiso para acceder al módulo de garantías.');
}

/* =========================================================
   HELPERS
========================================================= */
function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$fechaHoy = date('Y-m-d');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Nueva Garantía | Central</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Bootstrap / Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root{
            --brand1:#0d6efd;
            --brand2:#6f42c1;
            --bg-soft:#f7f9fc;
            --card-border:#e8edf3;
            --muted:#6c757d;
        }

        body{
            background: linear-gradient(180deg, #f8fbff 0%, #f3f6fb 100%);
        }

        .page-wrap{
            max-width: 1400px;
            margin: 24px auto 40px;
            padding: 0 14px;
        }

        .hero-card{
            border: 1px solid var(--card-border);
            border-radius: 22px;
            background: linear-gradient(135deg, rgba(13,110,253,.08), rgba(111,66,193,.08));
            box-shadow: 0 10px 28px rgba(17,24,39,.06);
            overflow: hidden;
        }

        .hero-title{
            font-size: 1.55rem;
            font-weight: 700;
            margin-bottom: .35rem;
        }

        .hero-sub{
            color: var(--muted);
            margin-bottom: 0;
        }

        .soft-card{
            border: 1px solid var(--card-border);
            border-radius: 20px;
            box-shadow: 0 8px 24px rgba(17,24,39,.05);
            background: #fff;
        }

        .section-title{
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: .9rem;
            display:flex;
            align-items:center;
            gap:.55rem;
        }

        .section-title i{
            color: var(--brand1);
        }

        .label-mini{
            font-size: .82rem;
            font-weight: 600;
            color: #495057;
            margin-bottom: .35rem;
        }

        .readonly-box{
            background:#f8f9fb;
        }

        .dictamen-box{
            border-radius: 18px;
            border: 1px dashed #ced4da;
            padding: 16px 18px;
            min-height: 108px;
            transition: all .2s ease;
        }

        .dictamen-box h5{
            margin:0 0 .35rem 0;
            font-weight:700;
        }

        .dictamen-neutral{
            background:#f8f9fa;
            border-color:#dee2e6;
        }

        .dictamen-procede{
            background:rgba(25,135,84,.08);
            border-color:rgba(25,135,84,.3);
        }

        .dictamen-no{
            background:rgba(220,53,69,.08);
            border-color:rgba(220,53,69,.3);
        }

        .dictamen-revision{
            background:rgba(255,193,7,.12);
            border-color:rgba(255,193,7,.35);
        }

        .check-grid{
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap:14px;
        }

        .check-card{
            border:1px solid var(--card-border);
            border-radius:16px;
            padding:14px;
            background:#fff;
        }

        .check-card .form-check{
            margin-bottom:.35rem;
        }

        .sticky-summary{
            position: sticky;
            top: 92px;
        }

        .badge-soft{
            display:inline-flex;
            align-items:center;
            gap:.35rem;
            border-radius:999px;
            padding:.45rem .8rem;
            font-size:.82rem;
            font-weight:600;
            background:#eef4ff;
            color:#2457c5;
        }

        .muted-note{
            font-size:.9rem;
            color:#6b7280;
        }

        .hidden{
            display:none !important;
        }

        .imei-found-chip{
            font-size:.88rem;
        }

        .spinner-inline{
            width:1rem;
            height:1rem;
            border-width:.18em;
        }
    </style>
</head>
<body>

<div class="page-wrap">
    <!-- HERO -->
    <div class="hero-card mb-4">
        <div class="p-4 p-md-5">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div>
                    <div class="hero-title">
                        <i class="bi bi-shield-check me-2"></i>Nueva solicitud de garantía / reparación
                    </div>
                    <p class="hero-sub">
                        Captura el IMEI, consulta la venta o reemplazo previo y registra el dictamen preliminar del caso.
                    </p>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <span class="badge-soft"><i class="bi bi-upc-scan"></i> Búsqueda por IMEI 1 / IMEI 2</span>
                    <span class="badge-soft"><i class="bi bi-cpu"></i> Dictamen preliminar automático</span>
                    <span class="badge-soft"><i class="bi bi-journal-text"></i> Trazabilidad por caso</span>
                </div>
            </div>
        </div>
    </div>

    <form id="formGarantia" method="post" action="guardar_garantia.php" novalidate>
        <div class="row g-4">
            <!-- IZQUIERDA -->
            <div class="col-lg-8">
                <!-- BUSQUEDA -->
                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-search"></i>
                        <span>Búsqueda del equipo</span>
                    </div>

                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">IMEI del equipo</label>
                            <input
                                type="text"
                                class="form-control form-control-lg"
                                id="imei_busqueda"
                                name="imei_busqueda"
                                maxlength="20"
                                placeholder="Escribe o pega el IMEI"
                                autocomplete="off"
                                required
                            >
                            <div class="form-text">
                                Puedes capturar IMEI 1 o IMEI 2. El sistema intentará localizar la venta o un reemplazo previo.
                            </div>
                        </div>

                        <div class="col-md-4 d-grid">
                            <button type="button" class="btn btn-primary btn-lg" id="btnBuscarImei">
                                <i class="bi bi-search me-1"></i>
                                Buscar IMEI
                            </button>
                        </div>
                    </div>

                    <div id="busquedaStatus" class="mt-3"></div>
                </div>

                <!-- DATOS AUTOLLENADOS -->
                <div class="soft-card p-4 mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div class="section-title mb-0">
                            <i class="bi bi-database-check"></i>
                            <span>Datos recuperados automáticamente</span>
                        </div>
                        <span id="chipOrigen" class="badge text-bg-secondary imei-found-chip">Sin consulta</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="label-mini">Origen del caso</label>
                            <input type="text" class="form-control readonly-box" id="origen_label" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="label-mini">Fecha de venta / compra</label>
                            <input type="text" class="form-control readonly-box" id="fecha_venta_label" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="label-mini">TAG / Folio venta</label>
                            <input type="text" class="form-control readonly-box" id="tag_venta_label" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="label-mini">Cliente</label>
                            <input type="text" class="form-control readonly-box" id="cliente_nombre_label" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="label-mini">Teléfono</label>
                            <input type="text" class="form-control readonly-box" id="cliente_telefono_label" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="label-mini">Correo</label>
                            <input type="text" class="form-control readonly-box" id="cliente_correo_label" readonly>
                        </div>

                        <div class="col-md-3">
                            <label class="label-mini">Marca</label>
                            <input type="text" class="form-control readonly-box" id="marca_label" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="label-mini">Modelo</label>
                            <input type="text" class="form-control readonly-box" id="modelo_label" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="label-mini">Color</label>
                            <input type="text" class="form-control readonly-box" id="color_label" readonly>
                        </div>
                        <div class="col-md-3">
                            <label class="label-mini">Capacidad</label>
                            <input type="text" class="form-control readonly-box" id="capacidad_label" readonly>
                        </div>

                        <div class="col-md-6">
                            <label class="label-mini">IMEI 1</label>
                            <input type="text" class="form-control readonly-box" id="imei1_label" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="label-mini">IMEI 2</label>
                            <input type="text" class="form-control readonly-box" id="imei2_label" readonly>
                        </div>

                        <div class="col-md-4">
                            <label class="label-mini">Sucursal</label>
                            <input type="text" class="form-control readonly-box" id="sucursal_label" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="label-mini">Vendedor</label>
                            <input type="text" class="form-control readonly-box" id="vendedor_label" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="label-mini">Modalidad</label>
                            <input type="text" class="form-control readonly-box" id="modalidad_label" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="label-mini">Financiera</label>
                            <input type="text" class="form-control readonly-box" id="financiera_label" readonly>
                        </div>
                    </div>
                </div>

                <!-- RECEPCION Y FALLA -->
                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-clipboard2-pulse"></i>
                        <span>Recepción y descripción de la falla</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Fecha de recepción</label>
                            <input type="date" class="form-control" name="fecha_recepcion" id="fecha_recepcion" value="<?= h($fechaHoy) ?>" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Responsable en tienda</label>
                            <input type="text" class="form-control" value="<?= h($_SESSION['nombre'] ?? $_SESSION['usuario'] ?? 'Usuario actual') ?>" readonly>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Tipo de atención</label>
                            <select class="form-select" name="tipo_atencion" id="tipo_atencion">
                                <option value="garantia">Garantía</option>
                                <option value="revision_tecnica">Revisión técnica</option>
                                <option value="postventa">Postventa</option>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Descripción de la falla reportada por el cliente</label>
                            <textarea class="form-control" name="descripcion_falla" id="descripcion_falla" rows="4" placeholder="Ejemplo: el equipo no enciende, se apaga solo, no carga, pantalla en negro, etc." required></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Observaciones de tienda</label>
                            <textarea class="form-control" name="observaciones_tienda" id="observaciones_tienda" rows="3" placeholder="Observaciones adicionales al recibir el equipo"></textarea>
                        </div>
                    </div>
                </div>

                <!-- CHECKLIST -->
                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-ui-checks-grid"></i>
                        <span>Checklist técnico inicial</span>
                    </div>

                    <div class="check-grid">
                        <?php
                        $checks = [
                            'check_encendido' => 'Encendido',
                            'check_dano_fisico' => 'Daño físico',
                            'check_humedad' => 'Humedad',
                            'check_pantalla' => 'Pantalla',
                            'check_camara' => 'Cámara',
                            'check_bocina_microfono' => 'Bocina / Micrófono',
                            'check_puerto_carga' => 'Puerto de carga',
                            'check_app_financiera' => 'App financiera instalada',
                            'check_bloqueo_patron_google' => 'Bloqueo por patrón / Google'
                        ];
                        foreach ($checks as $name => $label):
                        ?>
                            <div class="check-card">
                                <div class="fw-semibold mb-2"><?= h($label) ?></div>

                                <div class="form-check">
                                    <input class="form-check-input checklist-radio" type="radio" name="<?= h($name) ?>" id="<?= h($name) ?>_si" value="1">
                                    <label class="form-check-label" for="<?= h($name) ?>_si">Sí</label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input checklist-radio" type="radio" name="<?= h($name) ?>" id="<?= h($name) ?>_no" value="0">
                                    <label class="form-check-label" for="<?= h($name) ?>_no">No</label>
                                </div>

                                <div class="form-check">
                                    <input class="form-check-input checklist-radio" type="radio" name="<?= h($name) ?>" id="<?= h($name) ?>_na" value="" checked>
                                    <label class="form-check-label" for="<?= h($name) ?>_na">Sin revisar</label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="mt-3">
                        <button type="button" class="btn btn-outline-primary" id="btnRecalcularDictamen">
                            <i class="bi bi-arrow-repeat me-1"></i>Recalcular dictamen
                        </button>
                    </div>
                </div>

                <!-- REPARACION OPCIONAL -->
                <div class="soft-card p-4 mb-4">
                    <div class="section-title">
                        <i class="bi bi-tools"></i>
                        <span>Ruta posterior si no procede garantía</span>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">¿Cliente desea cotización si no aplica garantía?</label>
                            <select class="form-select" name="requiere_cotizacion" id="requiere_cotizacion">
                                <option value="0">No definido aún</option>
                                <option value="1">Sí, desea cotización</option>
                                <option value="0">No, solo revisión</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Prioridad del caso</label>
                            <select class="form-select" name="prioridad" id="prioridad">
                                <option value="normal">Normal</option>
                                <option value="alta">Alta</option>
                                <option value="urgente">Urgente</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- DERECHA -->
            <div class="col-lg-4">
                <div class="sticky-summary">
                    <div class="soft-card p-4 mb-4">
                        <div class="section-title">
                            <i class="bi bi-cpu"></i>
                            <span>Dictamen preliminar</span>
                        </div>

                        <div id="dictamenBox" class="dictamen-box dictamen-neutral">
                            <h5 id="dictamenTitulo">Pendiente de evaluación</h5>
                            <div id="dictamenTexto" class="muted-note">
                                Busca un IMEI y completa el checklist para que Central sugiera si procede garantía, no procede o requiere revisión logística.
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">Motivo sugerido</label>
                            <input type="text" class="form-control readonly-box" id="motivo_no_procede_label" readonly>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">Observación del sistema</label>
                            <textarea class="form-control readonly-box" id="observacion_sistema_label" rows="4" readonly></textarea>
                        </div>
                    </div>

                    <div class="soft-card p-4 mb-4">
                        <div class="section-title">
                            <i class="bi bi-shield-exclamation"></i>
                            <span>Validaciones del caso</span>
                        </div>

                        <div id="validacionesWrap" class="small text-muted">
                            Sin validaciones todavía.
                        </div>
                    </div>

                    <div class="soft-card p-4">
                        <div class="section-title">
                            <i class="bi bi-save2"></i>
                            <span>Acciones</span>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-success btn-lg" id="btnGuardar">
                                <i class="bi bi-save me-1"></i>Guardar solicitud
                            </button>

                            <button type="button" class="btn btn-outline-secondary" id="btnLimpiar">
                                <i class="bi bi-eraser me-1"></i>Limpiar formulario
                            </button>
                        </div>

                        <div class="mt-3 muted-note">
                            El guardado enviará la información a <strong>guardar_garantia.php</strong>. En el siguiente paso armamos ese archivo para registrar el caso y su bitácora.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- HIDDEN FIELDS -->
        <input type="hidden" name="origen" id="origen">
        <input type="hidden" name="id_venta" id="id_venta">
        <input type="hidden" name="id_detalle_venta" id="id_detalle_venta">
        <input type="hidden" name="id_producto" id="id_producto">
        <input type="hidden" name="id_garantia_padre" id="id_garantia_padre">
        <input type="hidden" name="id_garantia_raiz" id="id_garantia_raiz">

        <input type="hidden" name="cliente_nombre" id="cliente_nombre">
        <input type="hidden" name="cliente_telefono" id="cliente_telefono">
        <input type="hidden" name="cliente_correo" id="cliente_correo">

        <input type="hidden" name="marca" id="marca">
        <input type="hidden" name="modelo" id="modelo">
        <input type="hidden" name="color" id="color">
        <input type="hidden" name="capacidad" id="capacidad">
        <input type="hidden" name="imei_original" id="imei_original">
        <input type="hidden" name="imei2_original" id="imei2_original">

        <input type="hidden" name="fecha_compra" id="fecha_compra">
        <input type="hidden" name="tag_venta" id="tag_venta">
        <input type="hidden" name="modalidad_venta" id="modalidad_venta">
        <input type="hidden" name="financiera" id="financiera_hidden">

        <input type="hidden" name="dictamen_preliminar" id="dictamen_preliminar">
        <input type="hidden" name="motivo_no_procede" id="motivo_no_procede">
        <input type="hidden" name="detalle_no_procede" id="detalle_no_procede">
        <input type="hidden" name="garantia_abierta_id" id="garantia_abierta_id">
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
    const $ = (id) => document.getElementById(id);

    const form = $('formGarantia');
    const imeiInput = $('imei_busqueda');
    const btnBuscar = $('btnBuscarImei');
    const btnRecalcular = $('btnRecalcularDictamen');
    const btnLimpiar = $('btnLimpiar');
    const statusWrap = $('busquedaStatus');

    let busquedaActual = null;

    function sanitizeIMEI(v) {
        return String(v || '')
            .replace(/\s+/g, '')
            .replace(/[^0-9A-Za-z]/g, '')
            .toUpperCase()
            .trim();
    }

    function setText(id, value) {
        const el = $(id);
        if (el) el.value = value ?? '';
    }

    function setHidden(id, value) {
        const el = $(id);
        if (el) el.value = value ?? '';
    }

    function clearStatus() {
        statusWrap.innerHTML = '';
    }

    function showStatus(type, html) {
        statusWrap.innerHTML = `<div class="alert alert-${type} mb-0">${html}</div>`;
    }

    function resetAutofill() {
        [
            'origen_label','fecha_venta_label','tag_venta_label',
            'cliente_nombre_label','cliente_telefono_label','cliente_correo_label',
            'marca_label','modelo_label','color_label','capacidad_label',
            'imei1_label','imei2_label','sucursal_label','vendedor_label',
            'modalidad_label','financiera_label'
        ].forEach(id => setText(id, ''));

        [
            'origen','id_venta','id_detalle_venta','id_producto','id_garantia_padre','id_garantia_raiz',
            'cliente_nombre','cliente_telefono','cliente_correo',
            'marca','modelo','color','capacidad',
            'imei_original','imei2_original',
            'fecha_compra','tag_venta','modalidad_venta','financiera_hidden',
            'garantia_abierta_id'
        ].forEach(id => setHidden(id, ''));

        $('chipOrigen').className = 'badge text-bg-secondary imei-found-chip';
        $('chipOrigen').textContent = 'Sin consulta';

        busquedaActual = null;
        renderValidaciones();
        renderDictamen();
    }

    function renderOrigen(origen) {
        const chip = $('chipOrigen');
        if (origen === 'venta') {
            chip.className = 'badge text-bg-primary imei-found-chip';
            chip.textContent = 'Venta original';
        } else if (origen === 'reemplazo_garantia') {
            chip.className = 'badge text-bg-warning imei-found-chip';
            chip.textContent = 'Reemplazo por garantía';
        } else {
            chip.className = 'badge text-bg-secondary imei-found-chip';
            chip.textContent = 'No localizado';
        }
    }

    function cargarDesdeBusqueda(data) {
        resetAutofill();

        busquedaActual = data;
        renderOrigen(data.origen || '');

        setText('origen_label', data.origen === 'venta' ? 'Venta original' :
                              data.origen === 'reemplazo_garantia' ? 'Reemplazo por garantía' : 'No localizado');

        setHidden('origen', data.origen || '');

        const venta = data.venta || {};
        const cliente = data.cliente || {};
        const equipo = data.equipo || {};
        const operacion = data.operacion || {};
        const garantiaOrigen = data.garantia_origen || {};
        const garantiaAbierta = data.garantia_abierta || {};

        setText('fecha_venta_label', venta.fecha_venta || '');
        setText('tag_venta_label', venta.tag || '');

        setText('cliente_nombre_label', cliente.nombre || '');
        setText('cliente_telefono_label', cliente.telefono || '');
        setText('cliente_correo_label', cliente.correo || '');

        setText('marca_label', equipo.marca || '');
        setText('modelo_label', equipo.modelo || '');
        setText('color_label', equipo.color || '');
        setText('capacidad_label', equipo.capacidad || '');
        setText('imei1_label', equipo.imei1 || '');
        setText('imei2_label', equipo.imei2 || '');

        setText('sucursal_label', operacion.sucursal_nombre || '');
        setText('vendedor_label', operacion.vendedor_nombre || '');
        setText('modalidad_label', venta.modalidad || '');
        setText('financiera_label', venta.financiera || '');

        setHidden('id_venta', venta.id_venta || '');
        setHidden('id_detalle_venta', venta.id_detalle_venta || '');
        setHidden('id_producto', venta.id_producto || '');

        setHidden('id_garantia_padre', garantiaOrigen.id_garantia || garantiaOrigen.id_garantia_padre || '');
        setHidden('id_garantia_raiz', garantiaOrigen.id_garantia_raiz || garantiaOrigen.id_garantia || '');

        setHidden('cliente_nombre', cliente.nombre || '');
        setHidden('cliente_telefono', cliente.telefono || '');
        setHidden('cliente_correo', cliente.correo || '');

        setHidden('marca', equipo.marca || '');
        setHidden('modelo', equipo.modelo || '');
        setHidden('color', equipo.color || '');
        setHidden('capacidad', equipo.capacidad || '');
        setHidden('imei_original', equipo.imei1 || '');
        setHidden('imei2_original', equipo.imei2 || '');

        setHidden('fecha_compra', venta.fecha_venta || '');
        setHidden('tag_venta', venta.tag || '');
        setHidden('modalidad_venta', venta.modalidad || '');
        setHidden('financiera_hidden', venta.financiera || '');
        setHidden('garantia_abierta_id', garantiaAbierta.id || '');

        renderValidaciones();
        renderDictamen();
    }

    async function buscarIMEI() {
        clearStatus();

        const imei = sanitizeIMEI(imeiInput.value);
        imeiInput.value = imei;

        if (!imei || imei.length < 8) {
            showStatus('warning', 'Captura un IMEI válido para realizar la búsqueda.');
            imeiInput.focus();
            return;
        }

        btnBuscar.disabled = true;
        btnBuscar.innerHTML = `<span class="spinner-border spinner-border-sm spinner-inline me-2" role="status"></span>Buscando...`;

        try {
            const resp = await fetch(`api_garantias_buscar_imei.php?imei=${encodeURIComponent(imei)}`, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const data = await resp.json();

            if (!resp.ok || !data.ok) {
                throw new Error(data.error || 'No fue posible consultar el IMEI.');
            }

            if (!data.encontrado) {
                resetAutofill();
                busquedaActual = data;
                renderOrigen(null);

                setText('origen_label', 'No localizado');
                setHidden('origen', 'manual');
                setHidden('imei_original', imei);

                showStatus('warning', `
                    <strong>IMEI no localizado.</strong><br>
                    El equipo no fue encontrado en ventas ni en reemplazos de garantía. El caso aún puede capturarse para revisión manual.
                `);

                renderValidaciones();
                renderDictamen();
                return;
            }

            cargarDesdeBusqueda(data);

            let extra = '';
            if (data.garantia_abierta?.existe) {
                extra = `<br><span class="text-danger fw-semibold">Atención:</span> ya existe una garantía abierta con folio <strong>${escapeHtml(data.garantia_abierta.folio || '')}</strong>.`;
            }

            showStatus('success', `
                <strong>Equipo localizado correctamente.</strong><br>
                Origen: <strong>${data.origen === 'venta' ? 'Venta original' : 'Reemplazo por garantía'}</strong>.${extra}
            `);

        } catch (err) {
            console.error(err);
            showStatus('danger', `Error al consultar IMEI: ${escapeHtml(err.message || 'Error desconocido')}`);
            resetAutofill();
        } finally {
            btnBuscar.disabled = false;
            btnBuscar.innerHTML = `<i class="bi bi-search me-1"></i>Buscar IMEI`;
        }
    }

    function getRadioValue(name) {
        const checked = document.querySelector(`input[name="${name}"]:checked`);
        if (!checked) return null;
        if (checked.value === '') return null;
        return checked.value;
    }

    function renderValidaciones() {
        const wrap = $('validacionesWrap');
        const items = [];

        const origen = $('origen').value;
        const imei = $('imei_original').value;
        const fechaCompra = $('fecha_compra').value;
        const garantiaAbiertaId = $('garantia_abierta_id').value;

        if (imei) {
            items.push(`<div class="mb-2"><i class="bi bi-check-circle text-success me-1"></i> IMEI principal detectado: <strong>${escapeHtml(imei)}</strong></div>`);
        } else {
            items.push(`<div class="mb-2"><i class="bi bi-exclamation-circle text-warning me-1"></i> Aún no hay IMEI validado desde la búsqueda.</div>`);
        }

        if (origen === 'venta') {
            items.push(`<div class="mb-2"><i class="bi bi-bag-check text-primary me-1"></i> Origen del equipo: venta original.</div>`);
        } else if (origen === 'reemplazo_garantia') {
            items.push(`<div class="mb-2"><i class="bi bi-arrow-repeat text-warning me-1"></i> Origen del equipo: reemplazo por garantía previa.</div>`);
        } else if (origen === 'manual') {
            items.push(`<div class="mb-2"><i class="bi bi-question-circle text-secondary me-1"></i> Equipo no localizado. El caso quedará sujeto a revisión manual.</div>`);
        }

        if (fechaCompra) {
            const dias = diffDays(fechaCompra);
            const color = dias <= 30 ? 'success' : (dias <= 90 ? 'warning' : 'danger');
            items.push(`<div class="mb-2"><i class="bi bi-calendar-event text-${color} me-1"></i> Antigüedad desde compra: <strong>${dias}</strong> día(s).</div>`);
        }

        if (garantiaAbiertaId) {
            items.push(`<div class="mb-2"><i class="bi bi-shield-exclamation text-danger me-1"></i> Ya existe una garantía abierta asociada al IMEI.</div>`);
        }

        wrap.innerHTML = items.join('') || 'Sin validaciones todavía.';
    }

    function diffDays(dateStr) {
        if (!dateStr) return 9999;
        const a = new Date(dateStr + 'T00:00:00');
        const b = new Date();
        const ms = b.getTime() - a.getTime();
        return Math.max(0, Math.floor(ms / 86400000));
    }

    function renderDictamen() {
        const box = $('dictamenBox');
        const titulo = $('dictamenTitulo');
        const texto = $('dictamenTexto');
        const motivo = $('motivo_no_procede_label');
        const obs = $('observacion_sistema_label');

        let resultado = 'revision_logistica';
        let tituloTxt = 'Revisión logística';
        let textoTxt = 'El caso requiere validación adicional antes de continuar.';
        let motivoTxt = '';
        let detalleTxt = '';

        const origen = $('origen').value;
        const garantiaAbiertaId = $('garantia_abierta_id').value;
        const fechaCompra = $('fecha_compra').value;
        const diasCompra = fechaCompra ? diffDays(fechaCompra) : null;

        const danoFisico = getRadioValue('check_dano_fisico');
        const humedad = getRadioValue('check_humedad');
        const bloqueo = getRadioValue('check_bloqueo_patron_google');
        const appFin = getRadioValue('check_app_financiera');

        box.className = 'dictamen-box dictamen-neutral';

        if (!origen && !sanitizeIMEI(imeiInput.value)) {
            resultado = 'revision_logistica';
            tituloTxt = 'Pendiente de evaluación';
            textoTxt = 'Primero busca un IMEI para iniciar el análisis.';
            detalleTxt = 'No existe información suficiente para calcular un dictamen.';
        } else if (garantiaAbiertaId) {
            resultado = 'no_procede';
            tituloTxt = 'No procede';
            textoTxt = 'El equipo ya cuenta con una garantía activa en proceso.';
            motivoTxt = 'GARANTIA_PREVIA_ABIERTA';
            detalleTxt = 'Se detectó un caso de garantía abierto para el IMEI consultado.';
            box.className = 'dictamen-box dictamen-no';
        } else if (origen === 'manual') {
            resultado = 'revision_logistica';
            tituloTxt = 'Revisión logística';
            textoTxt = 'El IMEI no fue localizado y debe validarse manualmente.';
            detalleTxt = 'No se encontró el IMEI en ventas ni en reemplazos previos.';
            box.className = 'dictamen-box dictamen-revision';
        } else if (danoFisico === '1') {
            resultado = 'no_procede';
            tituloTxt = 'No procede';
            textoTxt = 'Se detectó daño físico imputable al cliente.';
            motivoTxt = 'DANO_FISICO';
            detalleTxt = 'La política de garantía normalmente no cubre daño físico.';
            box.className = 'dictamen-box dictamen-no';
        } else if (humedad === '1') {
            resultado = 'no_procede';
            tituloTxt = 'No procede';
            textoTxt = 'Se detectó humedad en el equipo.';
            motivoTxt = 'HUMEDAD';
            detalleTxt = 'La garantía normalmente no aplica en casos con humedad.';
            box.className = 'dictamen-box dictamen-no';
        } else if (bloqueo === '1') {
            resultado = 'no_procede';
            tituloTxt = 'No procede';
            textoTxt = 'El problema corresponde a bloqueo por patrón o cuenta.';
            motivoTxt = 'BLOQUEO_CUENTA';
            detalleTxt = 'Este tipo de bloqueo no forma parte de cobertura de garantía.';
            box.className = 'dictamen-box dictamen-no';
        } else if (appFin === '0') {
            resultado = 'revision_logistica';
            tituloTxt = 'Revisión logística';
            textoTxt = 'La app financiera no está presente y requiere validación adicional.';
            motivoTxt = '';
            detalleTxt = 'Se recomienda revisión por logística antes de continuar.';
            box.className = 'dictamen-box dictamen-revision';
        } else if (diasCompra !== null && diasCompra > 90) {
            resultado = 'no_procede';
            tituloTxt = 'No procede';
            textoTxt = 'El equipo supera el periodo de cobertura sugerido.';
            motivoTxt = 'GARANTIA_VENCIDA';
            detalleTxt = `Han transcurrido ${diasCompra} día(s) desde la fecha de compra.`;
            box.className = 'dictamen-box dictamen-no';
        } else if (diasCompra !== null && diasCompra <= 30 && danoFisico !== '1' && humedad !== '1') {
            resultado = 'procede';
            tituloTxt = 'Procede preliminarmente';
            textoTxt = 'El caso cumple condiciones iniciales para garantía.';
            detalleTxt = 'Se recomienda envío a logística para validación final y seguimiento.';
            box.className = 'dictamen-box dictamen-procede';
        } else {
            resultado = 'revision_logistica';
            tituloTxt = 'Revisión logística';
            textoTxt = 'El caso puede continuar, pero requiere validación adicional.';
            detalleTxt = 'No se detectó una causa directa de rechazo automático.';
            box.className = 'dictamen-box dictamen-revision';
        }

        titulo.textContent = tituloTxt;
        texto.textContent = textoTxt;
        motivo.value = motivoTxt;
        obs.value = detalleTxt;

        setHidden('dictamen_preliminar', resultado);
        setHidden('motivo_no_procede', motivoTxt);
        setHidden('detalle_no_procede', detalleTxt);
    }

    function escapeHtml(str) {
        return String(str ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function limpiarFormularioCompleto() {
        form.reset();
        imeiInput.value = '';
        $('fecha_recepcion').value = '<?= h($fechaHoy) ?>';
        resetAutofill();
        clearStatus();
        renderDictamen();
        renderValidaciones();
    }

    // Eventos
    btnBuscar.addEventListener('click', buscarIMEI);

    imeiInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarIMEI();
        }
    });

    imeiInput.addEventListener('input', () => {
        imeiInput.value = sanitizeIMEI(imeiInput.value);
    });

    document.querySelectorAll('.checklist-radio').forEach(el => {
        el.addEventListener('change', renderDictamen);
    });

    btnRecalcular.addEventListener('click', renderDictamen);
    btnLimpiar.addEventListener('click', limpiarFormularioCompleto);

    form.addEventListener('submit', (e) => {
        renderDictamen();

        const imei = sanitizeIMEI(imeiInput.value);
        if (!imei) {
            e.preventDefault();
            showStatus('warning', 'Debes capturar un IMEI antes de guardar.');
            imeiInput.focus();
            return;
        }

        if (!$('descripcion_falla').value.trim()) {
            e.preventDefault();
            showStatus('warning', 'Describe la falla reportada por el cliente antes de guardar.');
            $('descripcion_falla').focus();
            return;
        }
    });

    // Inicial
    resetAutofill();
    renderDictamen();
    renderValidaciones();
})();
</script>
</body>
</html>