<?php
include 'navbar.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: index.php");
    exit();
}

include 'db.php';

$id_usuario = $_SESSION['id_usuario'];
$id_sucursal_usuario = $_SESSION['id_sucursal'];

// Traer sucursales
$sql_suc = "SELECT id, nombre FROM sucursales ORDER BY nombre";
$sucursales = $conn->query($sql_suc)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva Venta</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
</head>
<body class="bg-light">

<div class="container mt-4">
    <h2>Registrar Nueva Venta</h2>
    <a href="panel.php" class="btn btn-secondary mb-3">← Volver al Panel</a>

    <!-- ⚠️ Advertencia -->
    <div id="alerta_sucursal" class="alert alert-warning d-none">
        ⚠️ Estás eligiendo una sucursal diferente a la tuya. La venta contará para tu usuario en otra sucursal.
    </div>

    <form method="POST" action="procesar_venta.php" id="form_venta">
        <input type="hidden" name="id_usuario" value="<?= $id_usuario ?>">

        <div class="row mb-3">
            <div class="col-md-4">
                <label>Sucursal de la Venta</label>
                <select name="id_sucursal" id="id_sucursal" class="form-control" required>
                    <?php foreach ($sucursales as $sucursal): ?>
                        <option value="<?= $sucursal['id'] ?>" <?= $sucursal['id'] == $id_sucursal_usuario ? 'selected' : '' ?>>
                            <?= $sucursal['nombre'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Datos del cliente -->
        <div class="row mb-3">
            <div class="col-md-4" id="tag_field">
                <label for="tag">TAG (ID del crédito)</label>
                <input type="text" name="tag" id="tag" class="form-control">
            </div>
            <div class="col-md-4">
                <label>Nombre del Cliente</label>
                <input type="text" name="nombre_cliente" class="form-control" required>
            </div>
            <div class="col-md-4">
                <label>Teléfono del Cliente</label>
                <input type="text" name="telefono_cliente" class="form-control" required>
            </div>
        </div>

        <!-- Tipo de venta y equipos -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label>Tipo de Venta</label>
                <select name="tipo_venta" id="tipo_venta" class="form-control" required>
                    <option value="">Seleccione...</option>
                    <option value="Contado">Contado</option>
                    <option value="Financiamiento">Financiamiento</option>
                    <option value="Financiamiento+Combo">Financiamiento + Combo</option>
                </select>
            </div>
            <div class="col-md-4">
                <label>Equipo Principal</label>
                <select name="equipo1" id="equipo1" class="form-control select2-equipo" required></select>
            </div>
            <div class="col-md-4" id="combo" style="display:none;">
                <label>Equipo Combo</label>
                <select name="equipo2" id="equipo2" class="form-control select2-equipo"></select>
            </div>
        </div>

        <!-- Datos financieros -->
        <div class="row mb-3">
            <div class="col-md-4">
                <label>Precio de Venta Total ($)</label>
                <input type="number" step="0.01" name="precio_venta" class="form-control" required>
            </div>
            <div class="col-md-4" id="enganche_field">
                <label>Enganche ($)</label>
                <input type="number" step="0.01" name="enganche" id="enganche" class="form-control" value="0">
            </div>
            <div class="col-md-4">
                <label for="forma_pago_enganche" id="label_forma_pago">Forma de Pago Enganche</label>
                <select name="forma_pago_enganche" id="forma_pago_enganche" class="form-control">
                    <option value="Efectivo">Efectivo</option>
                    <option value="Tarjeta">Tarjeta</option>
                    <option value="Mixto">Mixto</option>
                </select>
            </div>
        </div>

        <div class="row mb-3" id="mixto_detalle" style="display:none;">
            <div class="col-md-6">
                <label>Enganche Efectivo ($)</label>
                <input type="number" step="0.01" name="enganche_efectivo" class="form-control" value="0">
            </div>
            <div class="col-md-6">
                <label>Enganche Tarjeta ($)</label>
                <input type="number" step="0.01" name="enganche_tarjeta" class="form-control" value="0">
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-4" id="plazo_field">
                <label>Plazo en Semanas</label>
                <input type="number" name="plazo_semanas" id="plazo_semanas" class="form-control" value="0">
            </div>
            <div class="col-md-4" id="financiera_field">
                <label>Financiera</label>
                <select name="financiera" id="financiera" class="form-control">
                    <option value="">N/A</option>
                    <option value="PayJoy">PayJoy</option>
                    <option value="Krediya">Krediya</option>
                </select>
            </div>
            <div class="col-md-4">
                <label>Comentarios</label>
                <input type="text" name="comentarios" class="form-control">
            </div>
        </div>

        <button class="btn btn-success w-100">Registrar Venta</button>
    </form>
</div>

<script>
$(document).ready(function() {
    const idSucursalUsuario = <?= $id_sucursal_usuario ?>;

    // Inicializa Select2
    $('.select2-equipo').select2({
        placeholder: "Buscar por modelo o IMEI",
        allowClear: true
    });

    // Mostrar u ocultar campos según tipo de venta
    $('#tipo_venta').on('change', function() {
        $('#combo').toggle($(this).val() === 'Financiamiento+Combo');
        toggleVenta();
    });

    $('#forma_pago_enganche').on('change', function() {
        $('#mixto_detalle').toggle($(this).val() === 'Mixto');
    });

    function toggleVenta() {
        const tipo = $('#tipo_venta').val();
        if (tipo === 'Contado') {
            $('#tag_field').hide();
            $('#tag').prop('required', false).val('');
            $('#enganche_field, #plazo_field, #financiera_field').hide();
            $('#enganche').val(0);
            $('#plazo_semanas').val(0);
            $('#financiera').val('N/A');
            $('#label_forma_pago').text('Forma de Pago');
        } else {
            $('#tag_field, #enganche_field, #plazo_field, #financiera_field').show();
            $('#tag').prop('required', true);
            $('#label_forma_pago').text('Forma de Pago Enganche');
        }
    }

    toggleVenta(); // Al cargar

    // Cargar productos por sucursal
    function cargarEquipos(sucursalId) {
        $.ajax({
            url: 'ajax_productos_por_sucursal.php',
            method: 'POST',
            data: { id_sucursal: sucursalId },
            success: function(response) {
                $('#equipo1, #equipo2').html(response).val('').trigger('change');
            }
        });
    }

    cargarEquipos($('#id_sucursal').val());

    $('#id_sucursal').on('change', function() {
        const seleccionada = parseInt($(this).val());
        if (seleccionada !== idSucursalUsuario) {
            $('#alerta_sucursal').removeClass('d-none');
        } else {
            $('#alerta_sucursal').addClass('d-none');
        }
        cargarEquipos(seleccionada);
    });
});
</script>

</body>
</html>
