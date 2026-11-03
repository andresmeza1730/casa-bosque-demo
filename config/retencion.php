<?php
// ============================================================
// config/retencion.php
// Política de retención de datos personales (ISO 27701 / Ley 1581)
// ============================================================
// Tras 'anios' años desde la última actividad del huésped (último
// check-out o, si no tiene estancias, su fecha de registro), sus datos
// personales pueden anonimizarse mediante borrado seguro, conservando
// únicamente los registros transaccionales exigidos por la ley.
//
// Referencia: la ley colombiana exige conservar soportes contables y
// tributarios por un mínimo de 5 años (Art. 28 Ley 962/2005 y E.T.).
// Ajusta el valor si tu asesor legal indica un periodo distinto.
// ------------------------------------------------------------

return [
    'anios' => 5,
];
