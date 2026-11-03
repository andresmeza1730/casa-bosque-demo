<?php
/**
 * Trabajo diferido: cerrar la respuesta HTTP y seguir trabajando.
 * ============================================================
 *
 * Para qué: hay tareas que el usuario no necesita esperar. Nació para mandar
 * la factura por correo al cerrar un check-out (abrir un socket TLS contra el
 * servidor de correo toma uno o dos segundos, con un huésped delante), y ese
 * envío se retiró en 3.13.0 a pedido del cliente. Hoy lo usan los avisos del
 * proveedor (`includes/avisos.php`): refrescar el aviso no debe hacer esperar
 * a quien abre la pantalla.
 *
 * Cómo: se le dice al servidor web «la respuesta está completa, entrégala
 * ya», y el proceso de PHP sigue vivo ejecutando lo que falte. El navegador
 * pinta la página mientras la tarea termina por detrás.
 *
 * Esto NO es una cola de trabajos. No hay reintentos, no sobrevive a un
 * reinicio del servidor y no sirve para nada que deba completarse con
 * garantías. Es exactamente lo que es: quitar de la espera algo cuyo
 * resultado el usuario no va a leer de todos modos.
 *
 * En un hosting compartido no hay procesos en segundo plano ni colas, así
 * que esta es la única forma disponible — y es suficiente para lo que se le
 * pide: cosas que se pueden perder sin que nadie lo note.
 */

/**
 * ¿Puede este servidor cerrar la respuesta y seguir trabajando?
 *
 * LiteSpeed (lo que corre Hostinger) y PHP-FPM traen cada uno su función.
 * Si no hay ninguna, se dice que no y quien llame decide: hacer el trabajo
 * de forma normal es siempre una alternativa correcta, solo más lenta.
 */
function puedeDiferir(): bool
{
    return function_exists('litespeed_finish_request')
        || function_exists('fastcgi_finish_request');
}

/**
 * Apunta $tarea para ejecutarla cuando la petición haya terminado.
 *
 * Se registra con register_shutdown_function y NO se ejecuta aquí mismo. Es
 * deliberado: quien llama a esto suele estar a mitad de su trabajo, con la
 * página todavía sin pintar. Si se cerrara la respuesta en ese momento, el
 * HTML que falta no llegaría al navegador y el usuario vería media página.
 *
 * Al cerrarse la petición ya se ha emitido todo, así que ahí sí se puede
 * entregar la respuesta y seguir trabajando sin que nadie espere.
 */
function diferir(callable $tarea, string $etiqueta = 'tarea diferida'): void
{
    register_shutdown_function(function () use ($tarea, $etiqueta) {
        ejecutarDiferida($tarea, $etiqueta);
    });
}

/**
 * Entrega la respuesta pendiente y ejecuta la tarea. Uso interno.
 *
 * Si el servidor no sabe cerrar la respuesta por adelantado, la tarea se
 * ejecuta igual. Es lo correcto: más vale tardar que no mandar la factura.
 *
 * La tarea nunca puede tumbar la petición: ya se entregó la respuesta, así
 * que una excepción aquí solo se anota en el registro. Tampoco se deja que
 * un corte del navegador la interrumpa a medias.
 */
function ejecutarDiferida(callable $tarea, string $etiqueta): void
{
    $cerrada = false;

    if (puedeDiferir()) {
        // El trabajo debe terminar aunque el navegador cierre la conexión:
        // abortar a medias dejaría la tarea a la mitad y sin constancia.
        @ignore_user_abort(true);

        // Sacar lo que haya en los búferes antes de cerrar, o la página
        // llegaría incompleta.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        @flush();

        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            $cerrada = true;
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $cerrada = true;
        }
    }

    try {
        $tarea();
    } catch (Throwable $e) {
        // La respuesta ya salió: aquí no hay nada que mostrar, solo anotar.
        error_log('Diferido (' . $etiqueta . ') falló: ' . $e->getMessage());
    }

    // Si no se pudo cerrar la respuesta, el trabajo se hizo en línea y el
    // usuario esperó. Queda anotado una sola vez para poder saberlo.
    if (!$cerrada) {
        static $avisado = false;
        if (!$avisado) {
            $avisado = true;
            error_log('Diferido: este servidor no permite cerrar la respuesta '
                    . 'por adelantado; "' . $etiqueta . '" se ejecutó en línea.');
        }
    }
}
