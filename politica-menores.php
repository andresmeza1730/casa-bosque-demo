<?php
// ============================================================
// politica-menores.php — Datos personales de niños, niñas y adolescentes
// Ley 1581 de 2012 · Decreto 1377 de 2013 (compilado en el 1074 de 2015)
// Ley 1098 de 2006 · Ley 679 de 2001 · Ley 1336 de 2009
// ============================================================
// Página PÚBLICA. Enlazada desde el registro de acompañantes del check-in y
// desde el menú del sistema.
//
// El texto lo aportó el hotel el 21 de octubre de 2026. El anterior tenía el
// nombre del hotel y el correo sin rellenar.
//
// El marco visual y el botón «Volver» viven en includes/legal.php.
// ------------------------------------------------------------
require_once __DIR__ . '/includes/legal.php';

$R = LEGAL_RESPONSABLE;

legalInicio(
    'Política de tratamiento de datos personales de niños, niñas y adolescentes',
    'Ley 1581 de 2012, Decreto 1377 de 2013 compilado en el Decreto 1074 de 2015, Ley 1098 de 2006, Ley 679 de 2001 y Ley 1336 de 2009, según corresponda.'
);
?>

<h2>1. Alcance y autorización</h2>
<p>
  Esta política se aplica al tratamiento de datos personales de niños, niñas y adolescentes que
  se registren como huéspedes o acompañantes en el <strong><?= legalEsc($R['nombre']) ?></strong>.
</p>
<p>
  El tratamiento respetará el <strong>interés superior del menor</strong> y sus derechos
  fundamentales. Cuando sea legalmente necesaria una autorización, esta será otorgada por su
  representante legal, previa información sobre las finalidades del tratamiento y teniendo en
  cuenta la opinión del menor de acuerdo con su madurez, autonomía y capacidad para comprender la
  situación.
</p>
<div class="lg-nota">
  La autorización <strong>no habilita</strong> el uso de los datos para finalidades distintas de
  las informadas ni elimina las obligaciones legales del hotel.
</div>

<h2>2. Datos recopilados y finalidades</h2>
<p>
  El hotel podrá tratar los datos que resulten necesarios para el registro y la prestación del
  servicio de alojamiento, de acuerdo con los requisitos aplicables. Estos podrán incluir:
</p>
<ul>
  <li>Nombre completo y datos de identificación.</li>
  <li>Fecha de nacimiento, cuando sea requerida.</li>
  <li>Información del acompañante adulto y su relación con el menor, cuando sea necesaria para el
      registro.</li>
  <li>Datos relacionados con la estadía y la información exigida por las autoridades competentes.</li>
</ul>
<p>La información se utilizará para:</p>
<ol>
  <li>Gestionar el registro y la estadía del menor.</li>
  <li>Cumplir las obligaciones aplicables al registro de huéspedes y los reportes legalmente
      exigibles.</li>
  <li>Atender requerimientos legítimos de las autoridades competentes.</li>
  <li>Aplicar las medidas de prevención y los protocolos de protección de niños, niñas y
      adolescentes que correspondan a la actividad hotelera.</li>
</ol>
<p>
  Los datos <strong>no se utilizarán con fines publicitarios o comerciales</strong> incompatibles
  con las finalidades informadas. Su comunicación a terceros se limitará a los casos permitidos
  por la ley, las obligaciones aplicables y las autorizaciones que correspondan.
</p>

<h2>3. Protección de los menores y prevención de la explotación sexual</h2>
<p>
  El <strong><?= legalEsc($R['nombre']) ?></strong> rechaza la explotación sexual comercial, la
  pornografía y el turismo sexual que involucren a niños, niñas y adolescentes.
</p>
<p>
  El establecimiento aplicará las medidas preventivas y los procedimientos de actuación que
  correspondan a sus obligaciones legales, incluida la atención de situaciones de riesgo y la
  comunicación a las autoridades competentes cuando proceda.
</p>
<p>
  El personal deberá actuar de acuerdo con los protocolos de protección de menores y las
  obligaciones legales aplicables, procurando proteger la integridad, la dignidad y la privacidad
  de las personas involucradas.
</p>

<h2>4. Seguridad y acceso a la información</h2>
<p>
  Los datos personales de menores están sujetos a controles de acceso y medidas de seguridad
  acordes con los riesgos del tratamiento. Solo el personal autorizado puede acceder a la
  información necesaria para cumplir sus funciones.
</p>
<p>
  El sistema <strong>muestra parcialmente el número de documento</strong> en el buscador de
  huéspedes y en el registro de auditoría, dejando a la vista únicamente los últimos cuatro
  caracteres, y cifra los documentos e identificadores en reposo. Además, el registro de un menor
  <strong>obliga a confirmar la verificación de sus documentos</strong> antes de permitir la
  entrada, y deja constancia de quién lo verificó y cuándo.
</p>
<div class="lg-nota">
  Esta ocultación visual <strong>no es anonimización efectiva</strong> si los datos completos
  siguen disponibles o pueden vincularse con el menor.
</div>

<h2>5. Conservación, supresión y anonimización</h2>
<p>
  Los datos se conservarán durante el tiempo necesario para las finalidades autorizadas y los
  plazos de conservación establecidos por la normativa aplicable.
</p>
<p>
  El representante legal podrá presentar solicitudes de consulta, actualización, rectificación,
  supresión o revocatoria de la autorización, según corresponda. El hotel evaluará cada solicitud
  y aplicará las medidas procedentes, teniendo en cuenta las obligaciones legales o contractuales
  que exijan conservar determinados registros.
</p>
<p>
  La anonimización solo se considerará efectiva cuando los datos ya no permitan identificar al
  menor, directa ni indirectamente, mediante medios razonablemente utilizables.
  <strong>Ocultar parcialmente un nombre, documento o teléfono no constituye por sí solo
  anonimización.</strong>
</p>

<h2>6. Derechos y canal de atención</h2>
<p>
  Los titulares y sus representantes legales podrán ejercer los derechos reconocidos por la
  normativa colombiana mediante una solicitud dirigida a:
</p>
<div class="lg-ficha">
  <dl>
    <dt>Responsable</dt><dd><?= legalEsc($R['nombre']) ?></dd>
    <dt>Correo</dt><dd><a href="mailto:<?= legalEsc($R['correo']) ?>"><?= legalEsc($R['correo']) ?></a></dd>
    <dt>Teléfono</dt><dd><?= legalEsc($R['telefono']) ?></dd>
    <dt>Dirección</dt><dd><?= legalEsc($R['direccion']) ?></dd>
  </dl>
</div>
<p>
  Las solicitudes se tramitan con el mismo procedimiento y los mismos plazos que la
  <a href="<?= BASE_URL ?>/politica-datos.php<?= legalVolverQS() ?>#10-consultas">política general de
  tratamiento de datos personales</a> (sección 10: 10 días hábiles para una consulta y 15 para un
  reclamo). El hotel podrá solicitar información razonable para verificar la identidad del
  solicitante y su condición de representante legal, protegiendo siempre los datos del menor.
</p>

<h2>7. Información y constancia de autorización</h2>
<p>
  Cuando corresponda obtener autorización, el hotel informará previamente las finalidades, los
  derechos del titular y los datos de contacto del responsable. Se conservará evidencia de la
  autorización cuando sea exigible.
</p>
<div class="lg-nota">
  La declaración del adulto de ser padre, madre o representante legal <strong>no sustituye la
  verificación</strong> que resulte necesaria ni convierte automáticamente cualquier tratamiento
  en legítimo.
</div>
<p>
  Para el alojamiento de un menor en compañía de un tercero, el establecimiento utiliza la
  <a href="<?= BASE_URL ?>/autorizacion-menor.php<?= legalVolverQS() ?>">carta de autorización</a>
  firmada por los padres o representantes legales.
</p>

<h2>8. Vigencia</h2>
<p>
  Esta política es aplicable a partir de su aprobación y publicación por parte del
  <?= legalEsc($R['nombre']) ?>. Cualquier modificación se comunicará mediante los canales
  adecuados, conforme a la normativa aplicable.
</p>

<?php legalFin(); ?>
