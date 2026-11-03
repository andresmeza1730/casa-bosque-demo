<?php
// ============================================================
// politica-datos.php — Política de Tratamiento de Datos Personales
// Ley 1581 de 2012 · Decreto 1377 de 2013 (compilado en el 1074 de 2015) — Casa Bosque
// ============================================================
// Página PÚBLICA. Enlazada desde los formularios que recogen datos (registro en
// recepción, acompañantes del check-in) junto a la casilla de consentimiento, y
// desde el menú del sistema.
//
// VERSIÓN 2.0 (29 de octubre de 2026). Reorganizada con la estructura de una
// política de tratamiento completa —aviso de privacidad, definiciones,
// principios, finalidades, derechos, procedimiento con sus plazos, seguridad,
// cookies y vigencia—, tomando como referencia la de ProColombia
// (procolombia.co/transparencia/politica-proteccion-de-datos-personales). El
// texto es PROPIO: se tomó el esquema, no las frases.
//
// CORRECCIÓN 3.10.1: la tabla de cookies listaba una «cookie de mantenimiento».
// No existe: el acceso con clave durante un mantenimiento (`mant_bypass`) está
// COMENTADO en includes/mantenimiento.php, y el mantenimiento es total. La
// política decía una cosa que el sistema no hace; se quitó. Quedan las dos
// cookies que de verdad se envían: la de sesión y `cb_equipo`.
//
// Lo que cambió respecto de la 1.0 (la del 21 de octubre, que aportó el hotel):
//   - Se añadió el procedimiento de consultas y reclamos con SUS PLAZOS
//     (antes decía solo «dentro de los términos legales»), las definiciones,
//     los principios, el aviso de privacidad, las cookies y el historial.
//   - Se quitó la mención a la «reserva en línea» como vía de autorización: ese
//     motor se cerró en 3.7.1 y hoy la autorización se registra en recepción.
//   - Se dice con exactitud DÓNDE sale el documento completo: no solo en la
//     ficha y la facturación, también en la lista de Huéspedes del personal.
//   - Se nombran los terceros que de verdad intervienen (alojamiento, correo,
//     asistente de ayuda) y se aclara qué NO reciben.
//
// Lo que cambió en la 2.1 (1 de noviembre de 2026): el cliente pidió quitar el
// envío de la factura por correo y el «¿Olvidaste tu contraseña?» (que también
// mandaba un correo), y el sistema dejó de mandar correos de cualquier tipo
// (3.13.0). Por eso desaparece el proveedor de correo de 12.1 y la factura ya no
// se «envía»: se entrega impresa. Los DATOS del correo siguen donde estaban
// (el del huésped, el del receptor de la factura): lo que cambió es que el
// sistema ya no escribe a nadie.
//
// Cada afirmación sobre el sistema está comprobada contra el código. Lo que el
// sistema NO hace no se promete aquí. Los plazos son los de los artículos 14 y
// 15 de la Ley 1581: ProColombia los cita mezclados y aquí van los de la ley.
//
// El marco visual y el botón «Volver» viven en includes/legal.php.
// ------------------------------------------------------------
require_once __DIR__ . '/includes/legal.php';

$R = LEGAL_RESPONSABLE;

legalInicio(
    'Política de Tratamiento de Datos Personales',
    'Conforme a la Ley 1581 de 2012, el Decreto 1377 de 2013 (compilado en el Decreto 1074 de 2015) y las demás normas colombianas aplicables.'
);
?>

<div class="lg-nota">
  <strong>Aviso de privacidad, en resumen.</strong>
  El <?= legalEsc($R['nombre']) ?> es el responsable de los datos personales que usted nos entrega
  al registrarse o reservar. Los usamos para atender su hospedaje, cobrar y facturar, y cumplir las
  obligaciones legales de un establecimiento de alojamiento. Usted puede conocer, actualizar,
  rectificar y pedir la supresión de sus datos, y es libre de no entregar los datos sensibles.
  Para ejercer sus derechos escriba a
  <a href="mailto:<?= legalEsc($R['correo']) ?>"><?= legalEsc($R['correo']) ?></a> o llame al
  <?= legalEsc($R['telefono']) ?>. El detalle está en esta política.
</div>

<p>
  Esta política establece las condiciones para la recolección, almacenamiento, uso, circulación y
  supresión de los datos personales tratados por el <strong><?= legalEsc($R['nombre']) ?></strong>
  mediante su sistema web y los demás medios utilizados para gestionar sus servicios.
</p>

<h2>1. Objetivo y alcance</h2>
<p>
  Fijar los lineamientos con que el hotel trata los datos personales de sus huéspedes, de sus
  acompañantes y de las demás personas que se relacionan con él, para garantizar el derecho
  constitucional a conocer, actualizar y rectificar la información que se recoge sobre ellas
  (artículo 15 de la Constitución).
</p>
<p>
  Se aplica a los datos personales registrados en las bases de datos del hotel, desde que se
  recolectan hasta que se suprimen o se anonimizan de manera efectiva, sea cual sea el medio en que
  se hayan entregado: en persona en recepción, por teléfono o por correo electrónico.
</p>

<h2>2. Marco normativo</h2>
<ul>
  <li>Artículos 15 y 20 de la Constitución Política de Colombia.</li>
  <li>Ley Estatutaria 1581 de 2012, por la cual se dictan disposiciones generales para la protección
      de datos personales.</li>
  <li>Decreto 1377 de 2013, compilado en el Decreto Único Reglamentario 1074 de 2015.</li>
  <li>Ley 1098 de 2006 (Código de la Infancia y la Adolescencia), para los datos de niños, niñas y
      adolescentes.</li>
</ul>

<h2>3. Definiciones</h2>
<dl class="lg-def">
  <dt>Dato personal</dt>
  <dd>Cualquier información que se refiera a una persona natural identificada o que pueda
      identificarse.</dd>
  <dt>Dato sensible</dt>
  <dd>El que afecta la intimidad del titular o cuyo uso indebido puede generar discriminación: por
      ejemplo, el origen racial o étnico, la orientación política, las convicciones religiosas, los
      datos de salud, de vida sexual y los datos biométricos.</dd>
  <dt>Titular</dt>
  <dd>La persona a quien se refieren los datos.</dd>
  <dt>Responsable del tratamiento</dt>
  <dd>Quien decide sobre las bases de datos y su tratamiento: en este caso, el hotel.</dd>
  <dt>Encargado del tratamiento</dt>
  <dd>Quien trata los datos por cuenta del responsable, como un proveedor de alojamiento del
      sistema.</dd>
  <dt>Tratamiento</dt>
  <dd>Toda operación sobre los datos: recolectarlos, almacenarlos, usarlos, circularlos o
      suprimirlos.</dd>
  <dt>Autorización</dt>
  <dd>El consentimiento previo, expreso e informado del titular para tratar sus datos.</dd>
  <dt>Transmisión</dt>
  <dd>El tratamiento de datos por un encargado, dentro o fuera de Colombia, por cuenta del
      responsable.</dd>
</dl>

<h2>4. Responsable del tratamiento</h2>
<div class="lg-ficha">
  <dl>
    <dt>Razón social</dt><dd><?= legalEsc($R['nombre']) ?></dd>
    <dt>NIT</dt><dd><?= legalEsc($R['nit']) ?></dd>
    <dt>Dirección</dt><dd><?= legalEsc($R['direccion']) ?></dd>
    <dt>Correo</dt><dd><a href="mailto:<?= legalEsc($R['correo']) ?>"><?= legalEsc($R['correo']) ?></a></dd>
    <dt>Teléfono</dt><dd><?= legalEsc($R['telefono']) ?></dd>
  </dl>
</div>

<h2>5. Principios que rigen el tratamiento</h2>
<ol>
  <li><strong>Legalidad:</strong> el tratamiento se sujeta a lo que la ley establece.</li>
  <li><strong>Finalidad:</strong> los datos se usan para propósitos legítimos que se informan al
      titular.</li>
  <li><strong>Libertad:</strong> solo se tratan con el consentimiento previo, expreso e informado
      del titular, salvo las excepciones legales.</li>
  <li><strong>Veracidad o calidad:</strong> la información debe ser veraz, completa, exacta,
      actualizada y comprobable; no se tratan datos parciales o que induzcan a error.</li>
  <li><strong>Transparencia:</strong> el titular puede saber en cualquier momento qué datos suyos
      tiene el hotel.</li>
  <li><strong>Acceso y circulación restringida:</strong> los datos solo los conoce el personal
      autorizado y las personas que la ley permite; no se publican en internet.</li>
  <li><strong>Seguridad:</strong> se adoptan medidas técnicas, humanas y administrativas para evitar
      la pérdida, el acceso no autorizado o el uso indebido.</li>
  <li><strong>Confidencialidad:</strong> quien intervenga en el tratamiento debe guardar reserva,
      incluso después de terminada su relación con el hotel.</li>
</ol>

<h2>6. Datos personales que se pueden recopilar</h2>
<p>Según los servicios solicitados y los campos habilitados en el sistema, el hotel podrá recopilar:</p>
<ul>
  <li><strong>Datos de identificación:</strong> nombres, apellidos, tipo y número de documento
      de identidad y otros datos exigidos para el registro del alojamiento.</li>
  <li><strong>Datos de contacto:</strong> número de teléfono, correo electrónico cuando sea
      solicitado, ciudad de origen y demás información necesaria para comunicarse con el huésped.</li>
  <li><strong>Datos de hospedaje:</strong> reservas, fechas de entrada y salida, habitación
      asignada, duración de la estadía y datos de acompañantes cuando corresponda.</li>
  <li><strong>Datos comerciales y financieros:</strong> consumos, pagos, saldos pendientes,
      tarifas y datos necesarios para elaborar facturas y soportes de las operaciones. Cuando el
      cliente pide factura, también su dirección, teléfono y correo.</li>
  <li><strong>Datos de cumplimiento normativo:</strong> información requerida para los registros
      y reportes legalmente exigibles a los establecimientos de alojamiento.</li>
  <li><strong>Imágenes de videovigilancia:</strong> grabaciones de zonas comunes.</li>
  <li><strong>Datos de uso del sistema por parte del personal:</strong> nombre y correo de la cuenta,
      dirección IP, navegador y hora de cada acción y de cada inicio o cierre de sesión, y el correo
      tecleado en un intento de acceso fallido. Sirven para la seguridad y la trazabilidad del
      sistema, no para evaluar el trabajo de las personas.</li>
</ul>
<p>
  Los datos se obtienen directamente del titular o de quien lo acompaña, al registrarse en
  recepción, al hacer una reserva por teléfono o al solicitar una factura. El hotel procurará
  recolectar únicamente los datos pertinentes y necesarios para las finalidades informadas o
  exigidas por la ley.
</p>

<h2>7. Finalidades del tratamiento</h2>
<ol>
  <li>Gestionar reservas, registros de huéspedes, entradas, salidas y asignación de habitaciones.</li>
  <li>Administrar las estadías y consultar la disponibilidad de habitaciones.</li>
  <li>Registrar consumos del restaurante y otros servicios ofrecidos por el establecimiento.</li>
  <li>Controlar pagos, saldos pendientes y obligaciones económicas relacionadas con la estadía.</li>
  <li>Elaborar facturas y cumplir las obligaciones tributarias y contables aplicables.</li>
  <li>Cumplir los reportes obligatorios ante las autoridades competentes, incluidos los que
      correspondan a Migración Colombia mediante el SIRE y al Ministerio de Comercio, Industria
      y Turismo mediante la Tarjeta de Registro de Alojamiento (TRA).</li>
  <li>Atender consultas, peticiones, quejas, reclamos y comunicaciones relacionadas con los servicios.</li>
  <li>Proteger la seguridad de los huéspedes, trabajadores e instalaciones mediante videovigilancia.</li>
  <li>Elaborar estadísticas internas utilizando información anonimizada, siempre que la
      anonimización sea efectiva y no permita identificar nuevamente a los titulares.</li>
</ol>
<p>
  Los datos <strong>no se usan con fines publicitarios</strong> ni se venden. Cualquier finalidad
  adicional deberá informarse y contar con la base jurídica o la autorización que corresponda.
</p>

<h2>8. Datos sensibles y menores de edad</h2>
<p>
  El hotel no solicita datos sensibles. Si alguna vez resultara necesario tratarlos, habría una
  finalidad legítima y una base jurídica que lo permita, y se informaría expresamente al titular que
  su suministro es <strong>facultativo</strong>: ninguna persona está obligada a autorizar el
  tratamiento de datos sensibles ni a responder preguntas sobre ellos.
</p>
<p>
  El tratamiento de datos personales de niños, niñas y adolescentes se realiza respetando sus
  derechos y su interés superior, con intervención de sus representantes legales cuando
  corresponda. Las condiciones aplicables a menores se detallan en la
  <a href="<?= BASE_URL ?>/politica-menores.php<?= legalVolverQS() ?>">política de
  tratamiento de datos de niños, niñas y adolescentes</a>.
</p>

<h2>9. Derechos de los titulares</h2>
<p>Como titular de datos personales usted tiene derecho a:</p>
<ol>
  <li>Conocer, actualizar y rectificar sus datos.</li>
  <li>Solicitar prueba de la autorización otorgada, cuando esta sea exigible.</li>
  <li>Ser informado, previa solicitud, del uso que se ha dado a sus datos.</li>
  <li>Acceder de forma gratuita a sus datos personales que hayan sido objeto de tratamiento.</li>
  <li>Presentar consultas y reclamos relacionados con el tratamiento.</li>
  <li>Revocar la autorización o solicitar la supresión de los datos cuando resulte procedente y no
      exista un deber legal o contractual de conservarlos.</li>
  <li>Presentar quejas ante la <strong>Superintendencia de Industria y Comercio (SIC)</strong> por
      infracciones a la ley, una vez agotado el trámite de consulta o reclamo ante el hotel.</li>
  <li>Abstenerse de responder las preguntas sobre datos sensibles o sobre datos de niños, niñas y
      adolescentes, que son facultativas.</li>
</ol>
<p>
  Estos derechos los puede ejercer el titular, sus causahabientes, su representante o apoderado, o
  quien actúe por estipulación a favor de otro, acreditando su calidad. Se ejercen sin perjuicio de
  las obligaciones legales que puedan impedir la eliminación de determinados registros.
</p>

<h2 id="10-consultas">10. Consultas y reclamos</h2>
<h3>10.1 Cómo presentarlos</h3>
<p>
  Escriba a <a href="mailto:<?= legalEsc($R['correo']) ?>"><?= legalEsc($R['correo']) ?></a>, llame al
  <?= legalEsc($R['telefono']) ?> o acuda a <?= legalEsc($R['direccion']) ?>. La solicitud debe incluir,
  según corresponda:
</p>
<ul>
  <li>Nombre completo del titular.</li>
  <li>Tipo y número de documento, o información razonable para verificar su identidad.</li>
  <li>Descripción de los hechos o de lo que se consulta, corrige, reclama, suprime o revoca.</li>
  <li>Dirección, teléfono o correo para recibir la respuesta.</li>
  <li>Los documentos que sustenten la solicitud, si los hay. Si actúa en nombre de otra persona, el
      documento que acredite su representación.</li>
</ul>
<p>
  El hotel verificará la identidad del solicitante antes de entregar o modificar información. La
  consulta de sus propios datos es <strong>gratuita</strong> al menos una vez por cada mes calendario
  y siempre que la política tenga modificaciones sustanciales.
</p>

<h3>10.2 Plazos de respuesta</h3>
<div class="lg-tabla">
<table>
  <thead><tr><th>Trámite</th><th>Plazo</th><th>Si no se alcanza a responder</th></tr></thead>
  <tbody>
    <tr><td><strong>Consulta</strong> (conocer qué datos se tienen y su uso)</td>
        <td>10 días hábiles desde el recibo</td>
        <td>Se avisa el motivo y la nueva fecha, que no pasará de 5 días hábiles más.</td></tr>
    <tr><td><strong>Reclamo</strong> (corregir, actualizar, suprimir, revocar o por un posible incumplimiento)</td>
        <td>15 días hábiles desde el recibo</td>
        <td>Se avisa el motivo y la nueva fecha, que no pasará de 8 días hábiles más.</td></tr>
  </tbody>
</table>
</div>
<ul>
  <li>Si el reclamo está <strong>incompleto</strong>, el hotel lo dirá dentro de los 5 días siguientes
      para que usted lo complete. Si pasan <strong>2 meses</strong> sin que lo complete, se entiende
      que desistió.</li>
  <li>Si el hotel no es quien debe resolverlo, lo trasladará a quien corresponda en un máximo de
      2 días hábiles y se lo informará.</li>
  <li>Desde que recibe un reclamo completo y mientras se resuelve, el hotel marca el dato como
      «reclamo en trámite» en un plazo máximo de 2 días hábiles.</li>
  <li>La supresión de los datos o la revocatoria de la autorización <strong>no proceden</strong>
      mientras exista un deber legal o contractual de conservarlos.</li>
</ul>
<p>
  Los plazos son los que fijan los artículos 14 y 15 de la Ley 1581 de 2012. Si el hotel no responde
  o la respuesta no lo satisface, puede acudir a la Superintendencia de Industria y Comercio.
</p>

<h2>11. Seguridad de la información en el sistema web</h2>
<p>
  El sistema web del hotel está destinado a facilitar la gestión y consulta de la información
  necesaria para sus operaciones administrativas y de hospedaje. Entre las medidas que aplica:
</p>
<ul>
  <li><strong>Acceso por perfiles:</strong> solo el personal autorizado entra, con cuenta propia y
      según su función: administrador o recepción.</li>
  <li><strong>Contraseñas protegidas:</strong> no se guardan en claro, sino con un algoritmo de
      protección (Argon2id).</li>
  <li><strong>Segundo factor de autenticación</strong> obligatorio para el personal administrador.</li>
  <li><strong>Cifrado de los datos:</strong> el número de documento, el teléfono y los datos del
      receptor de una factura se guardan cifrados.</li>
  <li><strong>Sesiones controladas:</strong> se cierran solas tras un periodo de inactividad y tienen
      una duración máxima; el acceso se bloquea temporalmente tras varios intentos fallidos.</li>
  <li><strong>Registro de auditoría</strong> de las operaciones, que no se puede modificar ni borrar.
      Guarda quién hizo cada cosa, cuándo, desde qué dirección IP y con qué navegador, y, en los
      cambios de datos del negocio (tarifas, estados, fechas, montos, roles), cómo estaba el dato
      antes y cómo quedó. <strong>No guarda contraseñas ni datos personales de los huéspedes</strong>:
      de un huésped solo anota qué campos se modificaron, no su contenido. También anota los
      intentos fallidos de acceso con el correo que se tecleó. El sistema avisa al administrador de
      las señales sospechosas (muchos intentos fallidos, un mismo usuario entrando desde dos
      direcciones IP en pocos minutos, una acción crítica desde un dispositivo inusual) a partir de
      esos mismos datos, sin consultar servicios externos ni cerrar sesiones por sí solo.</li>
</ul>
<p>
  El sistema <strong>muestra parcialmente el número de documento</strong> en el buscador de
  huéspedes y en el registro de auditoría, dejando a la vista únicamente los últimos cuatro
  caracteres. El dato completo <strong>sigue almacenado</strong>, cifrado, y lo ve el personal
  autorizado en las pantallas de gestión de huéspedes y en los soportes de facturación.
</p>
<div class="lg-nota">
  La ocultación parcial de identificadores es una medida de protección visual, pero
  <strong>por sí sola no anonimiza</strong> los datos. Mientras la información pueda relacionarse
  con una persona identificada o identificable, sigue sometida a las normas de protección de
  datos personales.
</div>
<p>
  Cuando se solicite la supresión de información o la aplicación de mecanismos de anonimización,
  el hotel evaluará la solicitud conforme a la ley y a las obligaciones de conservación
  aplicables. No se eliminarán ni alterarán registros que deban conservarse por obligaciones
  legales, tributarias, contables o contractuales.
</p>

<h2>12. Comunicación de datos a terceros y autoridades</h2>
<p>
  Los datos podrán comunicarse a las autoridades o entidades competentes cuando resulte necesario
  para cumplir obligaciones legales, atender requerimientos válidos o prestar los servicios
  solicitados. Entre los posibles destinatarios se encuentran:
</p>
<ul>
  <li><strong>Migración Colombia:</strong> para los reportes que correspondan a través del SIRE.</li>
  <li><strong>Ministerio de Comercio, Industria y Turismo:</strong> para la información requerida
      mediante los mecanismos oficiales de registro de alojamiento, incluida la TRA.</li>
  <li><strong>DIAN:</strong> para cumplir las obligaciones tributarias y de facturación.</li>
  <li><strong>Autoridades competentes:</strong> cuando exista una obligación o requerimiento legal
      que justifique la comunicación.</li>
</ul>
<p>
  La existencia de estos posibles destinatarios no significa que todos reciban automáticamente la
  información. El hotel confirmará cuáles intervienen realmente, qué datos reciben y bajo qué
  condiciones.
</p>
<h3>12.1 Proveedores que tratan datos por cuenta del hotel</h3>
<ul>
  <li><strong>Alojamiento del sistema:</strong> el sistema funciona sobre un servicio de alojamiento
      web contratado por el hotel, donde se guardan las bases de datos. Los datos sensibles de
      identificación van cifrados.</li>
  <li><strong>Asistente de ayuda del sistema:</strong> es opcional y solo responde preguntas sobre
      cómo se usa el sistema. <strong>No recibe datos de huéspedes</strong>: únicamente la pregunta
      que escribe el empleado.</li>
  <li><strong>Recursos externos de la página:</strong> algunas pantallas cargan tipos de letra e
      iconos de servidores de terceros; al hacerlo, estos pueden ver la dirección IP del navegador,
      pero no los datos del huésped.</li>
</ul>
<p>
  Cuando alguno de estos proveedores trate los datos fuera de Colombia, el hotel exigirá las
  garantías que la ley establece para la transmisión y la transferencia de datos personales y no
  comunicará los datos a quien no ofrezca un nivel adecuado de protección, salvo las excepciones
  legales.
</p>

<h2>13. Conservación de los datos personales</h2>
<p>
  La información se conservará durante el tiempo necesario para cumplir las finalidades del
  tratamiento y las obligaciones legales, tributarias, contables y contractuales aplicables a cada
  categoría de datos. Para determinar los periodos se tendrán en cuenta los siguientes criterios:
</p>
<ul>
  <li>Los registros de alojamiento sujetos a la regulación de la TRA se conservarán durante el
      plazo mínimo exigido por la normativa aplicable. La regulación consultada establece un
      mínimo de <strong>cinco años</strong> desde la fecha de salida del huésped.</li>
  <li>Las facturas, los documentos contables y sus soportes se conservarán durante los plazos
      legales que correspondan a cada documento.</li>
  <li>Los registros internos de reservas, hospedajes, consumos y pagos se conservarán conforme a
      su finalidad y a las obligaciones aplicables.</li>
  <li>Las grabaciones de videovigilancia se conservarán durante un periodo definido y justificado
      por las finalidades de seguridad y las necesidades legales.</li>
</ul>
<p>
  Una vez dejen de ser necesarios los datos y no exista una obligación legal o contractual que
  exija conservarlos, se evaluará su supresión o la aplicación de mecanismos de anonimización
  efectiva, según corresponda. El sistema cuenta con un procedimiento de <strong>borrado
  seguro</strong> que anonimiza de forma irreversible el documento, el nombre, el teléfono y el correo
  de un huésped y conserva el historial sin identidad.
</p>

<h2>14. Cookies</h2>
<p>
  El sistema usa únicamente cookies <strong>necesarias para funcionar</strong>. No usa cookies de
  análisis, de publicidad ni de seguimiento.
</p>
<div class="lg-tabla">
<table>
  <thead><tr><th>Cookie</th><th>Para qué sirve</th><th>Duración</th></tr></thead>
  <tbody>
    <tr><td>Cookie de sesión</td><td>Mantiene abierta la sesión del personal mientras trabaja.</td>
        <td>Se elimina al cerrar la sesión; además caduca por inactividad.</td></tr>
    <tr><td><code>cb_equipo</code></td><td>Solo si el personal marca «Recordar este equipo» al verificar el
        segundo factor: evita teclear el código en cada ingreso.</td><td>14 días</td></tr>
  </tbody>
</table>
</div>
<p>
  Puede bloquear o borrar las cookies desde la configuración de su navegador, pero el personal no
  podría iniciar sesión sin la cookie de sesión.
</p>

<h2>15. Videovigilancia</h2>
<p>
  El hotel utiliza cámaras de seguridad en zonas comunes. Las imágenes se tratan para fines de
  seguridad de las personas, los bienes y las instalaciones.
</p>
<p>
  El establecimiento informa sobre la existencia de videovigilancia mediante avisos visibles y
  define las condiciones de acceso, conservación y eliminación de las grabaciones. Las imágenes no
  se utilizarán para finalidades incompatibles con las informadas, salvo que exista una base legal
  que lo permita.
</p>

<h2>16. Autorización y conocimiento de la política</h2>
<p>
  Cuando la ley exija autorización, el hotel la solicitará antes o en el momento de la recolección
  de los datos, informando las finalidades específicas y los derechos del titular.
</p>
<p>
  Al registrar a un huésped en recepción, el sistema <strong>registra la fecha y la hora de la
  autorización</strong> y conserva constancia de la vía por la que se otorgó.
</p>
<div class="lg-nota">
  La aceptación de la política acredita su puesta a disposición o conocimiento según el mecanismo
  utilizado, pero <strong>no debe confundirse automáticamente con una autorización válida para
  cualquier finalidad</strong>. La autorización deberá cumplir los requisitos legales que
  correspondan.
</div>

<h2>17. Vigencia, modificaciones y versiones</h2>
<p>
  Esta política entra en vigor a partir de su aprobación y publicación por parte del responsable
  del tratamiento, y las bases de datos se conservan mientras sean necesarias para las finalidades
  descritas. Le pedimos mantener sus datos actualizados.
</p>
<p>
  Cualquier modificación sustancial se publicará en esta misma página antes de que entre en vigor y
  se comunicará a los titulares por los canales adecuados.
</p>
<div class="lg-tabla">
<table>
  <thead><tr><th>Versión</th><th>Fecha</th><th>Qué cambió</th></tr></thead>
  <tbody>
    <tr><td>2.2</td><td>1 de noviembre de 2026</td>
        <td>El registro de auditoría ahora guarda también el navegador, la sesión y, en los cambios de
            datos del negocio, el estado antes y después (nunca contraseñas ni datos personales de
            huéspedes); anota los intentos fallidos de acceso; y el sistema avisa de señales
            sospechosas sin consultar servicios externos.</td></tr>
    <tr><td>2.1</td><td>1 de noviembre de 2026</td>
        <td>El sistema ya no envía correos: se retira el envío de la factura por correo y el proveedor
            de correo deja de figurar entre los que tratan datos por cuenta del hotel.</td></tr>
    <tr><td>2.0</td><td>29 de octubre de 2026</td>
        <td>Se añaden el aviso de privacidad, las definiciones, los principios, el procedimiento de
            consultas y reclamos con sus plazos, los proveedores, las cookies y este historial.</td></tr>
    <tr><td>1.0</td><td>21 de octubre de 2026</td><td>Primera versión con los datos reales del hotel.</td></tr>
  </tbody>
</table>
</div>

<?php legalFin(); ?>
