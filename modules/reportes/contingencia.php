<?php
// ============================================================
// modules/reportes/contingencia.php
// Plan de contingencia por caída de internet / sistema (ISO 27001)
// ============================================================
// Documento operativo IMPRIMIBLE. Como una caída de internet deja el
// sistema (alojado en línea) inaccesible, este plan está pensado para
// imprimirse y conservarse físicamente en recepción, junto con copias
// de la tarjeta de registro en papel.
//
// Accesible a todo el personal autenticado.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Fuera del alcance (HCB_RF_001 a HCB_RF_010) ──
// El plan de contingencia es un procedimiento de la facturación
// electrónica ante la DIAN, y HCB_RF_009 establece que el hotel lleva su
// propia numeración y no cuenta con un rango autorizado por la DIAN.
// Para reactivarlo, borra estas dos líneas.
require_once __DIR__ . '/../../includes/modulo_retirado.php';
moduloRetirado('Plan de contingencia', 'Es un procedimiento de la facturación electrónica ante la DIAN, y el hotel factura con su propia numeración interna.');

$establecimiento = @include __DIR__ . '/../../config/establecimiento.php';
if (!is_array($establecimiento)) { $establecimiento = []; }
$razon = $establecimiento['razon_social'] ?? '[RAZÓN SOCIAL DEL HOTEL]';

// Tarjeta de registro en papel (reutilizable: se imprime 2 por hoja).
function tarjetaRegistro(string $razon): void {
    ?>
    <div class="cg-form">
        <div class="row">
            <div class="fld" style="flex:2;"><span class="lbl">Establecimiento</span><div class="line"><?= htmlspecialchars($razon) ?></div></div>
            <div class="fld"><span class="lbl">Fecha de registro</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Habitación</span><div class="line"></div></div>
        </div>
        <div class="row">
            <div class="fld"><span class="lbl">Tipo doc. (CC/CE/PAS/NIT)</span><div class="line"></div></div>
            <div class="fld" style="flex:2;"><span class="lbl">Número de documento</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Nacionalidad</span><div class="line"></div></div>
        </div>
        <div class="row">
            <div class="fld"><span class="lbl">Nombres</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Apellidos</span><div class="line"></div></div>
        </div>
        <div class="row">
            <div class="fld"><span class="lbl">Teléfono</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Correo electrónico</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Ciudad de origen</span><div class="line"></div></div>
        </div>
        <div class="row">
            <div class="fld"><span class="lbl">Fecha de entrada</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Fecha de salida</span><div class="line"></div></div>
            <div class="fld"><span class="lbl"># Personas</span><div class="line"></div></div>
        </div>
        <div class="row">
            <div class="fld"><span class="lbl">Exento IVA — N° Pasaporte (Art. 481)</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Sello migratorio (PT)</span><div class="line"></div></div>
        </div>
        <div class="chk">
            <span class="sq"></span>
            <span>Autorizo el <b>Tratamiento de mis Datos Personales</b> conforme a la Política de la Ley 1581 de 2012
            (gestión de la reserva y reportes obligatorios a las autoridades).</span>
        </div>
        <div class="row" style="margin-top:18px;">
            <div class="fld" style="flex:2;"><span class="lbl">Firma del huésped</span><div class="line"></div></div>
            <div class="fld"><span class="lbl">Atendido por</span><div class="line"></div></div>
        </div>
    </div>
    <?php
}

$pageTitle = 'Plan de contingencia';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
.cg-wrap { padding-bottom: 90px; max-width: 860px; }
.cg-print-note { background:#fff6d6; border:1px solid #d9b400; border-radius:12px; padding:14px 16px; font-size:.88rem; color:#7a5b00; display:flex; gap:10px; align-items:flex-start; margin-bottom:16px; }
.cg-actions { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
.cg-btn { display:inline-flex; align-items:center; gap:8px; border:none; border-radius:11px; padding:11px 16px; font-family:inherit; font-size:.88rem; font-weight:700; cursor:pointer; text-decoration:none; }
.cg-btn.print { background:linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color:#fff; }
.cg-btn.solo { background:#fff; border:1px solid var(--cb-primary,#6B4420); color:var(--cb-primary,#6B4420); }
.cg-doc { background:#fff; border:1px solid var(--cb-border,#EDE3D2); border-radius:16px; padding:26px 28px; box-shadow:0 4px 12px rgba(42,23,9,.04); }
.cg-doc h1 { font-size:1.35rem; color:var(--cb-forest,#3E2412); margin:0 0 4px; }
.cg-doc h2 { font-size:1.02rem; color:var(--cb-primary,#6B4420); margin:22px 0 8px; border-bottom:2px solid var(--cb-border,#EDE3D2); padding-bottom:5px; }
.cg-doc h3 { font-size:.92rem; margin:14px 0 6px; color:var(--cb-forest,#3E2412); }
.cg-doc p, .cg-doc li { font-size:.9rem; line-height:1.6; color:#453520; }
.cg-doc ol, .cg-doc ul { padding-left:22px; margin:6px 0; }
.cg-meta { font-size:.8rem; color:var(--cb-muted,#9A8778); margin-bottom:8px; }
.cg-step { display:flex; gap:10px; align-items:flex-start; margin:8px 0; }
.cg-step .n { flex:none; width:24px; height:24px; border-radius:50%; background:var(--cb-primary,#6B4420); color:#fff; font-size:.78rem; font-weight:700; display:flex; align-items:center; justify-content:center; }
.cg-box { background:#f6f8f4; border:1px solid var(--cb-border,#EDE3D2); border-radius:10px; padding:12px 14px; margin:10px 0; }
.ph { background:#fff6d6; border-radius:4px; padding:0 4px; }

/* Tarjeta de registro en papel */
.cg-form { border:1.5px solid #333; border-radius:8px; padding:16px; margin-top:10px; }
.cg-form .row { display:flex; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
.cg-form .fld { flex:1; min-width:150px; }
.cg-form .lbl { font-size:.72rem; font-weight:700; color:#333; text-transform:uppercase; margin-bottom:3px; display:block; }
.cg-form .line { border-bottom:1px solid #666; height:26px; }
.cg-form .chk { display:flex; gap:8px; align-items:flex-start; font-size:.8rem; margin-top:8px; }
.cg-form .chk .sq { width:15px; height:15px; border:1.5px solid #333; flex:none; margin-top:1px; }
/* La segunda copia y la línea de corte solo aparecen al imprimir SOLO la tarjeta */
.tarjeta-copia.segunda { display:none; }
.corte { display:none; text-align:center; color:#888; font-size:.68rem; letter-spacing:2px; text-transform:uppercase; border-top:1px dashed #999; margin:16px 0 12px; padding-top:6px; }

@media print {
    /* Ocultar TODO el cromo de la app y los widgets flotantes */
    .sidebar, #sidebar, .top-bar, .bottom-nav, nav, .more-overlay,
    #cbHelpBtn, #cbChat, #cbTour, .cb-tour-card,
    .cg-actions, .cg-print-note { display: none !important; }

    /* Neutralizar el layout de barra lateral: el documento usa toda la hoja */
    html, body { background: #fff !important; height: auto !important; overflow: visible !important; }
    .main-content { margin-left: 0 !important; min-height: 0 !important; }
    .content-area { padding: 0 !important; }
    .cg-wrap { max-width: none !important; padding: 0 !important; }
    .cg-doc { border: none !important; box-shadow: none !important; padding: 0 !important; }

    /* El formulario puede repartirse entre páginas si es largo (antes se descartaba) */
    .cg-form { break-inside: auto; }
    .cg-form .row { break-inside: avoid; }
    .cg-doc h2 { break-after: avoid; page-break-after: avoid; }

    /* Conservar colores y bordes al imprimir */
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }

    /* ── Modo "solo la tarjeta": oculta todo el documento salvo la tarjeta ── */
    body.imprimir-tarjeta .cg-doc > *:not(#tarjeta-imprimible) { display: none !important; }
    body.imprimir-tarjeta #tarjeta-imprimible h2 { margin-top: 0; }
    /* Dos tarjetas por hoja: se muestra la segunda copia y la línea de corte */
    body.imprimir-tarjeta .tarjeta-copia.segunda { display: block !important; }
    body.imprimir-tarjeta .corte { display: block !important; }
    body.imprimir-tarjeta .tarjeta-copia { break-inside: avoid; }
    /* Compactar para que quepan dos en una sola página */
    body.imprimir-tarjeta .cg-form .row { margin-bottom: 9px; }
    body.imprimir-tarjeta .cg-form .line { height: 22px; }
    body.imprimir-tarjeta .cg-form { padding: 12px; }
}
</style>

<div class="cg-wrap">

    <div class="cg-print-note">
        <i class="fas fa-triangle-exclamation" style="margin-top:2px;"></i>
        <span><b>Importante:</b> si se cae el internet, este sistema queda inaccesible.
        <b>Imprime este documento y las tarjetas de registro en papel</b>, y consérvalos físicamente en recepción.</span>
    </div>

    <div class="cg-actions">
        <button type="button" class="cg-btn print" onclick="imprimirTodo()"><i class="fas fa-print"></i> Imprimir plan + tarjeta</button>
        <button type="button" class="cg-btn solo" onclick="imprimirTarjeta()"><i class="fas fa-id-card"></i> Imprimir solo la tarjeta</button>
    </div>

    <div class="cg-doc">
        <div class="cg-meta">Documento operativo · ISO/IEC 27001 · Continuidad del negocio</div>
        <h1>Plan de contingencia por caída de internet o del sistema</h1>
        <p><b><?= htmlspecialchars($razon) ?></b> — Protocolo para mantener la operación del hotel cuando el sistema
        de reservas (PMS) no esté disponible por falta de internet, corte de energía o falla técnica.</p>

        <h2>1. Cómo identificar el tipo de falla</h2>
        <ul>
            <li><b>Sin internet:</b> otros equipos tampoco navegan; el módem/router sin luces normales. El PMS no carga.</li>
            <li><b>Corte de energía:</b> no hay luz. Usar el plan mientras se restablece o se conecta la planta/UPS.</li>
            <li><b>Falla del sistema:</b> hay internet pero el PMS muestra error. Avisar a soporte técnico (sección 6).</li>
        </ul>

        <h2>2. Acción inmediata: seguir operando en papel</h2>
        <div class="cg-step"><span class="n">1</span><div>Mantén la calma e informa al huésped que el registro se hará <b>de forma manual</b> por una contingencia técnica; el servicio no se interrumpe.</div></div>
        <div class="cg-step"><span class="n">2</span><div>Usa la <b>Tarjeta de Registro de Alojamiento en papel</b> (al final de este documento) para cada huésped que llegue.</div></div>
        <div class="cg-step"><span class="n">3</span><div>Marca la casilla de <b>autorización de tratamiento de datos</b> (Habeas Data) y haz <b>firmar</b> al huésped. Es obligatorio (Ley 1581).</div></div>
        <div class="cg-step"><span class="n">4</span><div>Anota la <b>habitación asignada</b> y consulta el <b>tablero físico de llaves/habitaciones</b> para evitar asignar una ocupada.</div></div>
        <div class="cg-step"><span class="n">5</span><div>Cobros: usa <b>datáfono con conexión propia</b> (móvil/GPRS) o registra el pago en efectivo en el <b>libro de caja manual</b>.</div></div>

        <div class="cg-box">
            <b>Regla de oro:</b> ningún huésped se hospeda sin quedar registrado en papel y sin firmar la autorización de datos.
        </div>

        <h2>3. Continuidad del servicio durante la caída</h2>
        <ul>
            <li><b>Check-in:</b> tarjeta en papel + asignación manual de habitación.</li>
            <li><b>Check-out:</b> calcula noches y consumos a mano; entrega recibo del <b>talonario físico</b> prenumerado.</li>
            <li><b>Extranjeros (exención IVA):</b> anota número de pasaporte y sello migratorio (PT) en la tarjeta.</li>
            <li><b>Reservas telefónicas:</b> anótalas en la <b>agenda física</b> con fecha, nombre y teléfono.</li>
        </ul>

        <h2>4. Recuperación: al volver el servicio</h2>
        <div class="cg-step"><span class="n">1</span><div>Ingresa al sistema y <b>reingresa cada tarjeta de papel</b> en los módulos de Huéspedes y Check-in, respetando fecha/hora reales.</div></div>
        <div class="cg-step"><span class="n">2</span><div>Concilia el <b>libro de caja manual</b> y el <b>talonario de recibos</b> con lo registrado en el sistema.</div></div>
        <div class="cg-step"><span class="n">3</span><div>Verifica que no haya <b>doble asignación</b> de habitaciones ni reservas duplicadas.</div></div>
        <div class="cg-step"><span class="n">4</span><div>Archiva las tarjetas de papel firmadas como <b>soporte físico</b> del consentimiento (respaldo del Habeas Data).</div></div>

        <h2>5. Respaldo de datos (prevención)</h2>
        <ul>
            <li>Descarga un <b>respaldo de la base de datos</b> al menos <b>una vez por semana</b> desde
                <b>Administración → Respaldo BD</b>, y guárdalo en un dispositivo externo (USB / disco).</li>
            <li>Conserva los últimos <b>4 respaldos</b> como mínimo.</li>
            <li>Mantén siempre <b>tarjetas de registro en papel impresas</b> disponibles en recepción.</li>
        </ul>

        <h2>6. Contactos de emergencia</h2>
        <div class="cg-box">
            <p style="margin:.2rem 0;">Proveedor de internet: <span class="ph">[NOMBRE / TELÉFONO]</span></p>
            <p style="margin:.2rem 0;">Soporte del sistema (PMS): <span class="ph">[NOMBRE / TELÉFONO / CORREO]</span></p>
            <p style="margin:.2rem 0;">Energía / planta eléctrica: <span class="ph">[TELÉFONO]</span></p>
            <p style="margin:.2rem 0;">Administrador del hotel: <span class="ph">[NOMBRE / TELÉFONO]</span></p>
        </div>

        <div id="tarjeta-imprimible">
        <h2>7. Tarjeta de Registro de Alojamiento (uso manual)</h2>
        <p class="cg-meta">Imprime varias copias y ténlas en recepción. Una por huésped. Al imprimir solo la tarjeta salen <b>dos por hoja</b>.</p>
        <div class="tarjeta-copia"><?php tarjetaRegistro($razon); ?></div>
        <div class="tarjeta-copia segunda">
            <div class="corte"><i class="fas fa-scissors"></i> cortar aquí</div>
            <?php tarjetaRegistro($razon); ?>
        </div>
        </div><!-- /#tarjeta-imprimible -->

    </div>
</div>

<script>
function imprimirTodo(){ document.body.classList.remove('imprimir-tarjeta'); window.print(); }
function imprimirTarjeta(){
    document.body.classList.add('imprimir-tarjeta');
    window.print();
}
// Al terminar/cancelar la impresión, volver al modo normal.
window.addEventListener('afterprint', function(){ document.body.classList.remove('imprimir-tarjeta'); });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
