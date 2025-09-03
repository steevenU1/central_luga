<?php
// helpers_nomina.php — overrides de nómina por semana (mar→lun)

if (!function_exists('fetchOverridesSemana')) {
  function fetchOverridesSemana(mysqli $conn, int $idUsuario, string $iniISO, string $finISO): array {
    $sql = "SELECT *
            FROM nomina_overrides_semana
            WHERE id_usuario=? AND semana_inicio=? AND semana_fin=?
            LIMIT 1";
    $st  = $conn->prepare($sql);
    $st->bind_param("iss", $idUsuario, $iniISO, $finISO);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row ?: [];
  }
}

if (!function_exists('applyOverride')) {
  /** Si $override es NULL, usa el cálculo ($calc). Si no, toma $override. */
  function applyOverride($override, float $calc): float {
    return is_null($override) ? $calc : (float)$override;
  }
}
