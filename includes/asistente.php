<?php
// ============================================================
// includes/asistente.php — Asistente de ayuda Casa Bosque
// ============================================================
// Tour de bienvenida (primera vez) + botón flotante de ayuda
// con chat de respuestas guiadas sobre el uso del sistema.
// Sin servicios externos: todo el conocimiento vive aquí.
// Se incluye desde footer.php. Usa isAdmin() para adaptar
// el contenido al rol del usuario.
// ------------------------------------------------------------
$__asisAdmin = function_exists('isAdmin') && isAdmin();
require_once __DIR__ . '/asistente_ia.php';
// Si no hay clave en el .env, el asistente se comporta exactamente
// como antes: busqueda por palabras clave, sin salir a internet.
$__iaOn = iaDisponible();
$__iaTok = function_exists('generateCsrfToken') ? generateCsrfToken() : '';
?>
<style>
/* ── Acceso de ayuda, integrado con la identidad visual de Casa Bosque ── */
#cbHelpBtn{
    position:fixed; right:16px; bottom:92px; z-index:1200;
    min-width:104px; height:48px; padding:0 16px; border-radius:999px;
    display:inline-flex; align-items:center; justify-content:center; gap:9px;
    background:var(--cb-forest,#3E2412); color:#fff;
    border:1px solid rgba(255,255,255,.22); cursor:pointer;
    font-family:inherit; font-size:.84rem; font-weight:700; line-height:1; letter-spacing:.01em;
    box-shadow:0 8px 24px rgba(62,36,18,.24), inset 0 1px rgba(255,255,255,.12);
    transition:transform .2s ease, background .2s ease, box-shadow .2s ease;
    -webkit-tap-highlight-color:transparent;
}
#cbHelpBtn i{ font-size:1.1rem; }
#cbHelpBtn:hover{ background:var(--cb-green,#7E5120); transform:translateY(-2px); box-shadow:0 12px 28px rgba(62,36,18,.3); }
#cbHelpBtn:focus-visible{ outline:3px solid #d4a84f; outline-offset:3px; }
#cbHelpBtn:active{ transform:translateY(0) scale(.97); }
@media(max-width:480px){
    #cbHelpBtn{ min-width:48px; width:48px; height:48px; padding:0; }
    #cbHelpBtn .cb-help-label{ display:none; }
}

/* ── Panel de chat ── */
#cbChat{
    position:fixed; left:12px; right:12px; bottom:92px; z-index:1300;
    max-width:380px; margin:0 auto;
    background:#fff; border-radius:22px; overflow:hidden;
    box-shadow:0 18px 48px rgba(62,36,18,.22);
    display:none; flex-direction:column;
    max-height:min(560px, calc(100vh - 140px));
    border:1px solid rgba(62,36,18,.12);
}
@media(min-width:600px){ #cbChat{ right:16px; left:auto; width:380px; } }
#cbChat.open{ display:flex; animation:cbUp .3s cubic-bezier(.16,1,.3,1); }
@keyframes cbUp{ from{opacity:0; transform:translateY(24px);} to{opacity:1; transform:translateY(0);} }

.cb-chat-head{
    background:linear-gradient(135deg,#3E2412,#6B4420); color:#fff;
    padding:16px 18px; display:flex; align-items:center; gap:12px;
}
.cb-chat-head .ico{ width:38px; height:38px; border-radius:50%; background:rgba(255,255,255,.15); display:flex; align-items:center; justify-content:center; }
.cb-chat-head h4{ margin:0; font-size:1rem; color:#fff; }
.cb-chat-head p{ margin:2px 0 0; font-size:.72rem; opacity:.8; }
.cb-chat-close{ margin-left:auto; background:none; border:none; color:#fff; font-size:1.1rem; cursor:pointer; padding:6px; }

.cb-chat-body{ flex:1; overflow-y:auto; padding:14px; background:#f6f8f6; -webkit-overflow-scrolling:touch; }
.cb-msg{ max-width:86%; padding:10px 14px; border-radius:16px; margin-bottom:10px; font-size:.88rem; line-height:1.5; white-space:pre-line; }
.cb-msg.bot{ background:#fff; border:1px solid #F0E6D8; color:#1e293b; border-bottom-left-radius:4px; }
/* Mientras se espera la respuesta de la IA. Si no hay IA configurada
   esta clase no se usa nunca. */
.cb-msg.cb-pensando{ color:#9A8778; font-style:italic; }
.cb-msg.user{ background:#6B4420; color:#fff; margin-left:auto; border-bottom-right-radius:4px; }
.cb-msg.bot b{ color:#3E2412; }

.cb-chips{ display:flex; flex-wrap:wrap; gap:6px; margin:4px 0 10px; }
.cb-chip{
    background:#fff; border:1px solid #E2D5C0; color:#3E2412;
    border-radius:99px; padding:7px 12px; font-size:.76rem; font-weight:600;
    cursor:pointer; transition:background .2s;
    -webkit-tap-highlight-color:transparent;
}
.cb-chip:active{ background:#F4ECE0; }

.cb-chat-foot{ display:flex; gap:8px; padding:12px; background:#fff; border-top:1px solid #eef2ef; }
.cb-chat-foot input{
    flex:1; padding:12px 14px; border-radius:14px; border:1px solid #EADFCE;
    font-family:inherit; font-size:.9rem; outline:none;
}
.cb-chat-foot input:focus{ border-color:#6B4420; }
.cb-chat-foot button{
    width:44px; height:44px; border-radius:12px; border:none; cursor:pointer;
    background:#6B4420; color:#fff; font-size:1rem;
}

/* ── Tour de bienvenida ── */
#cbTour{
    position:fixed; inset:0; z-index:1400; display:none;
    background:rgba(42,23,9,.55); backdrop-filter:blur(3px);
    align-items:center; justify-content:center; padding:20px;
}
#cbTour.open{ display:flex; }
.cb-tour-card{
    background:#fff; border-radius:24px; max-width:400px; width:100%;
    padding:28px 26px 22px; text-align:center;
    box-shadow:0 30px 70px rgba(0,0,0,.3);
    animation:cbUp .35s cubic-bezier(.16,1,.3,1);
}
.cb-tour-ico{
    width:66px; height:66px; margin:0 auto 16px; border-radius:20px;
    background:linear-gradient(135deg,#3E2412,#6B4420); color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:1.7rem;
    box-shadow:0 12px 28px rgba(62,36,18,.3);
}
.cb-tour-card h3{ margin:0 0 8px; color:#3E2412; font-size:1.25rem; }
.cb-tour-card p{ margin:0 0 18px; color:#7A6A58; font-size:.92rem; line-height:1.55; }
.cb-tour-dots{ display:flex; gap:6px; justify-content:center; margin-bottom:18px; }
.cb-tour-dots span{ width:8px; height:8px; border-radius:50%; background:#E4D9C6; transition:all .3s; }
.cb-tour-dots span.on{ background:#6B4420; transform:scale(1.3); }
.cb-tour-btns{ display:flex; gap:10px; }
.cb-tour-btns button{
    flex:1; padding:13px; border-radius:14px; border:none; cursor:pointer;
    font-family:inherit; font-size:.92rem; font-weight:700;
}
.cb-tour-skip{ background:#f1f5f2; color:#7A6A58; }
.cb-tour-next{ background:linear-gradient(135deg,#6B4420,#9C6728); color:#fff; }
</style>

<!-- Botón flotante -->
<button id="cbHelpBtn" type="button" title="Ayuda" aria-label="Abrir asistente de ayuda" aria-controls="cbChat" aria-expanded="false" onclick="cbToggleChat()"><i class="fas fa-circle-question" aria-hidden="true"></i><span class="cb-help-label">Ayuda</span></button>

<!-- Chat -->
<div id="cbChat">
    <div class="cb-chat-head">
        <div class="ico"><img src="<?= BASE_URL ?>/assets/img/emblema-48.png?v=3" alt="" width="26" height="26" style="display:block"></div>
        <div><h4>Asistente Casa Bosque</h4><p>Te ayudo a usar el sistema</p></div>
        <button class="cb-chat-close" onclick="cbToggleChat()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="cb-chat-body" id="cbChatBody"></div>
    <div class="cb-chat-foot">
        <input type="text" id="cbChatInput" placeholder="Escribe tu pregunta..." onkeydown="if(event.key==='Enter')cbSend()">
        <button onclick="cbSend()"><i class="fas fa-paper-plane"></i></button>
    </div>
</div>

<!-- Tour -->
<div id="cbTour">
    <div class="cb-tour-card">
        <div class="cb-tour-ico" id="cbTourIco"><i class="fas fa-tree"></i></div>
        <h3 id="cbTourTitle"></h3>
        <p id="cbTourText"></p>
        <div class="cb-tour-dots" id="cbTourDots"></div>
        <div class="cb-tour-btns">
            <button class="cb-tour-skip" onclick="cbTourEnd()">Saltar</button>
            <button class="cb-tour-next" id="cbTourNext" onclick="cbTourNext()">Siguiente</button>
        </div>
    </div>
</div>

<script>
(function(){
const ES_ADMIN = <?= $__asisAdmin ? 'true' : 'false' ?>;
const IA_ON  = <?= $__iaOn ? 'true' : 'false' ?>;
const IA_URL = '<?= BASE_URL ?>/asistente_responder.php';
const IA_TOK = '<?= htmlspecialchars($__iaTok, ENT_QUOTES) ?>';
const ROL = ES_ADMIN ? 'admin' : 'recepcion';

/* ════════════ TOUR DE BIENVENIDA ════════════ */
const TOUR_ADMIN = [
  {i:'fa-tree', t:'¡Bienvenido a SIGH-CB!', d:'Este es tu sistema de gestión hotelera. Te muestro en un minuto lo más importante para empezar.'},
  {i:'fa-chart-pie', t:'Tu Panel Principal', d:'Aquí ves el estado del hotel de un vistazo: habitaciones, check-ins y salidas de hoy, y los ingresos del día.'},
  {i:'fa-bed', t:'Habitaciones', d:'Crea y administra tus habitaciones, tipos y tarifas. Sus estados (disponible, ocupada, limpieza, mantenimiento) se actualizan con la operación.'},
  {i:'fa-calendar-check', t:'Reservas y Calendario', d:'Gestiona las reservas y mira el calendario de ocupación. Las reservas las crea el personal desde Reservas, por teléfono o en recepción.'},
  {i:'fa-cash-register', t:'Caja y Reportes', d:'El Arqueo de Caja cierra el día con PDF descargable. En Reportes de Ingresos ves la historia por mes o año, desglosada y por empleado.'},
  {i:'fa-circle-question', t:'¿Dudas? Aquí estoy', d:'Toca el botón verde de ayuda (abajo a la izquierda) cuando quieras: respondo preguntas sobre cualquier función del sistema.'},
];
const TOUR_RECEP = [
  {i:'fa-tree', t:'¡Bienvenido a Casa Bosque!', d:'Este es tu panel de recepción. Te muestro en un minuto cómo manejar tu día a día.'},
  {i:'fa-bell', t:'Requiere Atención', d:'Arriba verás alertas de salidas y llegadas pendientes de hoy, incluyendo las atrasadas. Tócalas para ir directo al módulo.'},
  {i:'fa-door-open', t:'Check-in y Check-out', d:'Registra ingresos desde la lista de reservas pendientes, y salidas con la cuenta calculada automáticamente (noches + consumos).'},
  {i:'fa-receipt', t:'Consumos', d:'Carga a la cuenta del huésped sus gastos extra (restaurante, bebidas, lavandería). Se suman solos en el check-out.'},
  {i:'fa-magnifying-glass', t:'Búsqueda rápida', d:'Usa el buscador de tu panel para encontrar un huésped por nombre o documento al instante.'},
  {i:'fa-circle-question', t:'¿Dudas? Aquí estoy', d:'Toca el botón verde de ayuda (abajo a la izquierda) cuando quieras: respondo preguntas sobre cualquier función.'},
];
const TOUR = ES_ADMIN ? TOUR_ADMIN : TOUR_RECEP;
let tourPos = 0;

function cbTourRender(){
  document.getElementById('cbTourIco').innerHTML = '<i class="fas '+TOUR[tourPos].i+'"></i>';
  document.getElementById('cbTourTitle').textContent = TOUR[tourPos].t;
  document.getElementById('cbTourText').textContent = TOUR[tourPos].d;
  document.getElementById('cbTourDots').innerHTML = TOUR.map((_,k)=>'<span class="'+(k===tourPos?'on':'')+'"></span>').join('');
  document.getElementById('cbTourNext').textContent = tourPos===TOUR.length-1 ? '¡Empezar!' : 'Siguiente';
}
window.cbTourNext = function(){
  if(tourPos < TOUR.length-1){ tourPos++; cbTourRender(); }
  else cbTourEnd();
};
window.cbTourEnd = function(){
  document.getElementById('cbTour').classList.remove('open');
  try{ localStorage.setItem('cb_tour_visto_'+ROL, '1'); }catch(e){}
};
window.cbTourStart = function(){
  tourPos = 0; cbTourRender();
  document.getElementById('cbTour').classList.add('open');
};
// Mostrar solo la primera vez
try{
  if(!localStorage.getItem('cb_tour_visto_'+ROL)){ setTimeout(cbTourStart, 800); }
}catch(e){}

/* ════════════ BASE DE CONOCIMIENTO ════════════ */
// Cada entrada: claves (palabras que activan la respuesta), respuesta, rol opcional.
const KB = [
  {k:['reserva','reservar','nueva reserva','crear reserva','pagina publica','publico','wizard','pasos'],
   r:'Las reservas las crea el personal, por teléfono o en recepción, desde el módulo <b>Reservas</b>: elige el huésped, la habitación, las fechas y las personas, y guarda. El sistema no deja reservar una habitación que ya está tomada para esas fechas.\n\nNo hay página pública ni reserva en línea: el huésped no reserva por su cuenta. Cada reserva se identifica por su número (#) y el nombre del huésped.'},
  {k:['disponibilidad','disponible','consultar fechas','habitaciones libres'],
   r:'No hay una consulta pública de disponibilidad. Para saber qué habitaciones están libres, mira el panel (en recepción, «Libres ahora») y el <b>Calendario</b>. Si al crear una reserva la habitación ya está tomada para esas fechas, el sistema te lo dice.'},
  {k:['cliente frecuente','frecuente'],
   r:'Ya no existe la pestaña <b>Cliente Frecuente</b>, porque no hay página pública. Si el huésped ya se hospedó antes, no tienes que volver a digitar sus datos: elígelo en la lista de huéspedes al crear la reserva, o búscalo en <b>Huéspedes</b> por documento o nombre.'},
  {k:['check-in','checkin','check in','ingreso','entrada','llegada'],
   r:'Para hacer un <b>Check-in</b>:\n1. Entra al módulo Check-In.\n2. Busca la reserva en "Llegadas de hoy".\n3. Verifica los datos del huésped.\n4. Presiona "Registrar entrada".\n\nNo necesitas cambiar el estado de la habitación: el check-in la pasa a "ocupada" por ti. También puedes registrar a un huésped sin reserva con una habitación disponible.'},
  {k:['check-out','checkout','check out','salida','liquidacion','cuenta','factura'],
   r:'Para hacer un <b>Check-out</b>:\n1. Entra al módulo Check-Out.\n2. Selecciona el huésped.\n3. Revisa la cuenta: noches + consumos = total.\n4. Confirma la salida.\n\nEl sistema cierra la estancia, genera la liquidación y deja la habitación en estado "limpieza" automáticamente (alguien debe marcarla disponible cuando esté lista).'},
  {k:['consumo','consumos','cargo','gasto','restaurante','bebida','lavanderia'],
   r:'En el módulo <b>Consumos</b> cargas los gastos extra del huésped durante su estadía:\n1. Selecciona el huésped (estancia activa).\n2. Registra el concepto y el valor.\n3. Guarda.\n\nTodo se suma automáticamente a su cuenta y aparece en el check-out.'},
  {k:['habitacion','habitaciones','estado','limpieza','mantenimiento','ocupada','tarifa'],
   r:'Estados de una habitación:\n• <b>Disponible</b>: libre, lista para asignar.\n• <b>Ocupada</b>: hay un huésped hospedado (se marca sola al hacer el check-in). Una reserva no cambia el estado: se ve en el Calendario como reservada.\n• <b>Limpieza</b>: recién desocupada, en aseo. Tras cada check-out queda así automáticamente.\n• <b>Mantenimiento</b>: fuera de servicio.\n\nEl administrador crea habitaciones, tipos y tarifas en el módulo Habitaciones.'},
  {k:['calendario','ocupacion','cuadricula'],
   r:'El <b>Calendario</b> muestra una semana a la vez: los días arriba y una fila por habitación. Cada celda dice si está <b>libre (L)</b>, <b>reservada (R)</b> u <b>ocupada (O)</b>; toca una celda para ver el huésped y las fechas. Anterior, Hoy y Siguiente cambian de semana.'},
  {k:['arqueo','caja','cierre','pdf caja'], rol:'admin',
   r:'El <b>Arqueo de Caja</b> (solo administrador) muestra los movimientos del día por fecha de check-out: hospedaje, consumos y total, con el detalle de cada salida.\n\nCon el botón "Descargar PDF" generas el comprobante del cierre con espacio para firmas del responsable y de revisión.'},
  {k:['reporte','reportes','ingresos','historico','mes','año','anual','mensual','estadistica','empleado','recepcionista'], rol:'admin',
   r:'En <b>Reportes de Ingresos</b> (menú Reportes, solo admin) ves la historia del negocio:\n• Por mes (detalle día a día) o por año (mes a mes).\n• Totales separados: hospedaje vs consumos.\n• Gráfico de barras del periodo.\n• Ingresos por empleado: cuánto cerró cada recepcionista.\n\nTodo se calcula por fecha de check-out, igual que el arqueo.'},
  {k:['respaldo','backup','copia','base de datos'], rol:'admin',
   r:'En <b>Respaldo BD</b> (menú Más → Respaldo, solo admin) descargas una copia completa de la base de datos en un archivo .sql. Recomendado: respaldar con frecuencia y guardar la copia fuera del servidor.'},
  {k:['auditoria','quien hizo','registro de cambios','historial de acciones'], rol:'admin',
   r:'La <b>Auditoría</b> (solo admin) registra quién hizo qué y cuándo: creaciones, cambios y eliminaciones en los módulos. Útil para revisar la operación de cada empleado.'},
  {k:['rol','roles','permisos','recepcionista','administrador','acceso'],
   r:'El sistema tiene dos roles:\n• <b>Administrador</b>: acceso total (dashboard, habitaciones, caja, reportes, auditoría, respaldos).\n• <b>Recepcionista</b>: su panel operativo + check-in, check-out, consumos, huéspedes, reservas y calendario.\n\nSi un recepcionista intenta entrar a una sección de admin, el sistema lo regresa a su panel con un aviso.'},
  {k:['alerta','atencion','atrasada','no llego','pendiente'],
   r:'En el panel de recepción, la sección <b>"Requiere Atención"</b> muestra:\n• Salidas de hoy y salidas ATRASADAS (en rojo, con la fecha desde la que están vencidas).\n• Ingresos pendientes de hoy y reservas que NO llegaron.\n\nCada tarjeta es clicable: te lleva directo al check-in o check-out.'},
  {k:['hospedados','quien esta','alojados','huespedes actuales'],
   r:'La sección <b>"Hospedados ahora"</b> del panel de recepción lista a todos los huéspedes activos con su habitación y su fecha de salida: "Sale hoy" en ámbar, "Atrasada" en rojo. Ordenados del más urgente al más lejano.'},
  {k:['buscar','buscador','encontrar huesped','busqueda'],
   r:'Usa el <b>buscador</b> del panel de recepción: escribe el nombre o el documento del huésped y presiona Enter. Te lleva al directorio de huéspedes con los resultados.'},
  {k:['contraseña','password','clave','olvide','seguridad','sesion'],
   r:'Recomendaciones de seguridad:\n• Tu usuario y contraseña son personales: no los compartas.\n• Cierra sesión al terminar tu jornada.\n• Tras varios intentos fallidos de login, el sistema bloquea el acceso 15 minutos por seguridad.\n\nSi olvidaste tu contraseña, contacta al administrador del sistema.'},
  {k:['tour','guia','bienvenida','primeros pasos','empezar','tutorial'],
   r:'__TOUR__'},
  {k:['notificacion','push','aviso','celular'],
   r:'El sistema envía <b>notificaciones</b> al celular (vía la app) con un aviso diario de los check-ins y check-outs del día. No avisa de reservas nuevas, porque las reservas las crea el personal.'},
  {k:['manual','documentacion','ayuda completa'],
   r:'Además de este asistente, el hotel cuenta con un <b>Manual de Usuario</b> completo (interactivo, con imágenes paso a paso). Pídeselo al administrador o al proveedor del sistema.'},
  {k:['estado de cuenta','cuenta del huesped','ficha','cuanto debe','saldo','lo que debe','deuda','cuanto va','ver cuenta'],
   r:'En <b>Más → Estado de cuenta</b> buscas un huésped por documento o nombre y ves de una vez su situación: habitación, noches, consumos y el <b>total a pagar</b>. Si está hospedado, muestra el saldo pendiente en vivo; si tiene reservas, el total estimado. Al entrar sin buscar, lista a los "Hospedados ahora".'},
  {k:['pago','pagos','abono','abonar','cobrar','registrar pago','anticipo','saldo a favor','pago parcial','metodo de pago'],
   r:'Para registrar un <b>pago o abono</b>:\n1. Entra a Más → Estado de cuenta y abre la ficha del huésped.\n2. Toca "Registrar pago", escribe el monto y elige el método (efectivo, tarjeta, transferencia u otro).\n3. Confirma.\n\nPuedes cobrar todo o por partes (abonos). El saldo se recalcula solo. Al hacer el check-out, el sistema cobra el saldo que falte preguntando el método.'},
  {k:['menor','menores','niño','nino','acompañante','acompanante','registro civil','autorizacion notarial','escnna'],
   r:'Cuando un huésped llega con un <b>menor de edad</b>, regístralo en <b>Check-in → Acompañantes</b> antes de darle la entrada. Se piden sus datos y documentos: Registro Civil siempre, y si no viaja con ambos padres, la <b>autorización notarial</b>. Al registrar la entrada el sistema te obliga a confirmar que verificaste esos documentos (Ley 1098 y advertencia Ley 679 - ESCNNA). Las plantillas están en Más → Legal y documentos.'},
  {k:['acompanantes','acompañantes','quien viene con','registrar acompanante'],
   r:'En la pantalla de <b>Check-in</b>, cada llegada tiene el botón <b>Acompañantes</b>. Ahí registras a quienes vienen con el titular. Si pones la fecha de nacimiento y resulta menor de 18, el sistema pide solo los datos que exige la ley.'},
  {k:['mi reserva','codigo de reserva','app huesped','en linea'],
   r:'El sistema <b>no usa código de reserva</b> ni tiene una página para que el huésped consulte por su cuenta. Las reservas se hacen por teléfono o en recepción, desde <b>Reservas</b>, y se identifican por su número (#) y el nombre del huésped.'},
  {k:['legal','documentos','politica de datos','politica menores','carta autorizacion','habeas data','proteccion de datos'],
   r:'En <b>Más → Legal y documentos</b> tienes a la mano: la Política de Tratamiento de Datos, la Política de datos de menores y la Carta de autorización de menor (para imprimir y autenticar ante notario). Útiles cuando llega un huésped con un menor.'},
  {k:['sire','tra','migracion','extranjero','gubernamental','reporte gobierno'], rol:'admin',
   r:'En <b>Más → SIRE / TRA</b> (solo admin) generas los reportes gubernamentales: SIRE (movimientos de huéspedes extranjeros) y TRA. Eliges el rango de fechas y descargas el archivo. Verifica el formato contra la especificación oficial vigente antes de enviarlo.'},
  {k:['dian','facturacion electronica','factura electronica','cufe'], rol:'admin',
   r:'La <b>facturación electrónica DIAN</b> está preparada pero desactivada: requiere hosting con salida a internet, un proveedor tecnológico autorizado y la resolución de la DIAN. Mientras tanto, el sistema emite el comprobante interno (factura PDF y tiquete) de siempre.'},
  {k:['retencion','anonimizar','borrado seguro','eliminar datos','supresion'], rol:'admin',
   r:'Por Ley 1581 e ISO 27701, en <b>Huéspedes</b> puedes hacer un "borrado seguro" (anonimizar) de los datos personales de un huésped: se borra su identidad pero se conserva el historial. En Más → Retención ves quiénes superan el tiempo de retención para depurarlos.'},
  {k:['2fa','doble factor','autenticacion','codigo de seguridad','authenticator','verificacion en dos pasos'],
   r:'La <b>verificación en dos pasos (2FA)</b> añade un código temporal de tu celular al iniciar sesión. La configuras en Más → Seguridad escaneando un QR con Google Authenticator (o similar). Es obligatoria para administradores y recomendada para recepcionistas.'},
  {k:['contingencia','sin internet','se cayo','no hay internet','emergencia','caida'],
   r:'Si se cae el internet, el sistema queda inaccesible hasta que vuelva la conexión. El módulo de contingencia ya no existe: anota las llegadas y las salidas en papel y regístralas en el sistema cuando regrese.'},
  {k:['comprobante','comprobantes','imprimir factura','ticket','tiquete','pdf'],
   r:'Al cerrar un check-out, el sistema genera la <b>factura PDF</b> (tamaño carta) y el <b>tiquete de 80mm</b> para impresora térmica; ambos muestran noches, consumos, total y lo abonado. ' + (ES_ADMIN ? 'También puedes volver a consultarlos cuando quieras en el módulo <b>Comprobantes</b>.' : 'Los botones para imprimirlos salen justo al cerrar la salida: imprime ahí mismo. Volver a consultarlos más tarde (módulo Comprobantes) es del administrador.')},
  {k:['mas','menu','panel mas','navegacion','donde esta','no encuentro','como llego'],
   r:'En el celular, el botón <b>"Más"</b> (abajo a la derecha) abre una página con TODOS los accesos agrupados: Operaciones, Reportes' + (ES_ADMIN ? ', Administración' : '') + ', Legal y documentos y Cuenta. Si no encuentras una función, casi siempre está ahí.'},
  {k:['hola','buenas','hey','ayuda','que puedes'],
   r:'¡Hola! 👋 Soy el asistente de Casa Bosque. Pregúntame por cualquier función: reservas, check-in, check-out, consumos, estado de cuenta, pagos, habitaciones' + (ES_ADMIN ? ', arqueo de caja, reportes, respaldos' : '') + '... o toca una de las sugerencias.'},
];

// Sugerencias rápidas por rol
const CHIPS = ES_ADMIN
  ? ['¿Cómo veo los ingresos?','¿Cómo hago el arqueo?','¿Cómo creo una habitación?','¿Qué hace cada rol?','Ver el tour de nuevo']
  : ['¿Cómo hago un check-in?','¿Cómo registro un consumo?','¿Cómo hago el check-out?','¿Qué son las alertas?','Ver el tour de nuevo'];

/* ════════════ MOTOR DEL CHAT ════════════ */
function norm(s){
  return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
}
function responder(q){
  const nq = norm(q);
  let mejor = null, mejorPts = 0;
  for(const item of KB){
    if(item.rol === 'admin' && !ES_ADMIN) continue;
    let pts = 0;
    for(const kw of item.k){
      if(nq.includes(norm(kw))) pts += kw.length; // claves más largas pesan más
    }
    if(pts > mejorPts){ mejorPts = pts; mejor = item; }
  }
  if(!mejor) return null;
  return mejor.r;
}

const body = document.getElementById('cbChatBody');
function addMsg(txt, who){
  const d = document.createElement('div');
  d.className = 'cb-msg ' + who;
  d.innerHTML = txt;
  body.appendChild(d);
  body.scrollTop = body.scrollHeight;
  return d;
}
function addChips(){
  const wrap = document.createElement('div');
  wrap.className = 'cb-chips';
  wrap.innerHTML = CHIPS.map(c=>'<button class="cb-chip">'+c+'</button>').join('');
  wrap.querySelectorAll('.cb-chip').forEach(b=>{
    b.onclick = ()=>{ document.getElementById('cbChatInput').value = b.textContent; cbSend(); };
  });
  body.appendChild(wrap);
  body.scrollTop = body.scrollHeight;
}


window.cbSend = function(){
  const inp = document.getElementById('cbChatInput');
  const q = inp.value.trim();
  if(!q) return;
  // Se escapa & ANTES que <, o se volveria a escapar el & del propio &lt;.
  // Escapar solo < no abria un XSS —una entidad decodificada por innerHTML da
  // texto, no marcado— pero sin el & quien tecleaba &amp; se veia &.
  addMsg(q.replace(/&/g,'&amp;').replace(/</g,'&lt;'), 'user');
  inp.value = '';

  // El tour es una ACCIÓN, no una respuesta: se resuelve aquí mismo, sin
  // preguntarle a nadie.
  if(responder(q) === '__TOUR__'){
    setTimeout(()=>{
      addMsg('¡Claro! Te abro el recorrido de bienvenida. 👇', 'bot');
      setTimeout(()=>{ cbToggleChat(); cbTourStart(); }, 600);
    }, 250);
    return;
  }

  if(!IA_ON){ setTimeout(()=>responderLocal(q), 350); return; }

  // Con IA: se pregunta al servidor, que es quien tiene la clave.
  const burbuja = addMsg('Pensando…', 'bot');
  burbuja.classList.add('cb-pensando');

  // Un poco más que el tope del servidor (8 s), para que mande el suyo.
  const corte = new AbortController();
  const reloj = setTimeout(()=>corte.abort(), 9000);

  const datos = new URLSearchParams();
  datos.set('q', q);
  datos.set('csrf_token', IA_TOK);

  fetch(IA_URL, {
    method: 'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
    body: datos.toString(),
    credentials: 'same-origin',
    signal: corte.signal
  })
  .then(r => r.json())
  .then(j => {
    clearTimeout(reloj);
    if(!j || !j.ok || !j.texto) throw new Error('sin respuesta');
    burbuja.classList.remove('cb-pensando');
    // textContent primero: escapa lo que venga de fuera. Solo después se
    // añaden los saltos de línea, ya sobre texto escapado. El chat pinta con
    // innerHTML, así que esto no es opcional.
    burbuja.textContent = j.texto;
    burbuja.innerHTML = burbuja.innerHTML.replace(/\n/g, '<br>');
    body.scrollTop = body.scrollHeight;
  })
  .catch(()=>{
    // Sin clave, sin red, cuota agotada o modelo mal configurado: el
    // asistente no se rompe, solo responde como antes.
    clearTimeout(reloj);
    burbuja.remove();
    responderLocal(q);
  });
};

// El asistente de siempre: palabras clave contra la base local.
function responderLocal(q){
  const r = responder(q);
  if(r && r !== '__TOUR__') addMsg(r, 'bot');
  else cbFallback();
}

function cbFallback(){
  addMsg('Hmm, no tengo una respuesta exacta para eso. 🤔\n\nIntenta con otras palabras (por ejemplo: "check-in", "consumos", "reportes"), toca una sugerencia, o consulta el Manual de Usuario. Para problemas técnicos, contacta al administrador o al proveedor.', 'bot');
  addChips();
}

let chatIniciado = false;
window.cbToggleChat = function(){
  const c = document.getElementById('cbChat');
  const abierto = c.classList.toggle('open');
  document.getElementById('cbHelpBtn').setAttribute('aria-expanded', abierto ? 'true' : 'false');
  if(abierto && !chatIniciado){
    chatIniciado = true;
    addMsg('¡Hola! 👋 Soy el <b>asistente de Casa Bosque</b>. Pregúntame cómo usar cualquier parte del sistema, o toca una sugerencia:', 'bot');
    addChips();
  }
  if(abierto) setTimeout(()=>document.getElementById('cbChatInput').focus(), 300);
};
})();
</script>
