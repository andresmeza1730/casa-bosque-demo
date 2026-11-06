<?php
// ============================================================
// includes/csv.php
// Celdas de CSV a salvo (4.3.2)
// ============================================================
//
// Una celda que empieza con = + - @ (o una tabulación) la ejecuta Excel como FÓRMULA. En un CSV del sistema hay texto que escribió otra persona: el
// correo tecleado en un acceso fallido, el nombre o la ciudad de un huésped, el nombre de un empleado. Esta es la única forma de escribir un texto en
// una celda: auditoria/exportar.php la tenía escrita en su propio archivo y la exportación de huéspedes no la usaba.
//
// Solo es para TEXTO libre. Un número o una cifra de dinero no pasa por aquí: «-5000» es un valor, y con el apóstrofo dejaría de ser un número.

/** Una celda de texto de CSV a salvo: si empieza como una fórmula, se le antepone un apóstrofo. */
function celdaCsv($valor): string {
    $s = (string) $valor;
    return ($s !== '' && strpos("=+-@\t\r", $s[0]) !== false) ? "'" . $s : $s;
}
