<?php
// ============================================================
// autorizacion-menor.php — Carta de autorización para alojar a un menor
// Ley 1098 de 2006 · Ley 679 de 2001 · Ley 1336 de 2009
// ============================================================
// Página PÚBLICA. A diferencia de las otras dos, esto NO es una política: es el
// punto desde donde se DESCARGA la carta que los padres firman antes de viajar.
//
// Antes era un formulario de campos etiquetados que se mandaba a imprimir desde
// el navegador: salía sin logo, con la cabecera y el pie del navegador, y con
// los márgenes que cada quien tuviera puestos. Y el formato no era el que usan
// los hoteles del país.
//
// Andrés aportó dos modelos reales —uno genérico colombiano y el del Hotel
// Mocawa Plaza— y los dos son una CARTA formal: «Señores / E. S. M. / Ref.»,
// el cuerpo en párrafo corrido con los huecos en línea, firma y huella, y notas
// al pie numeradas. Eso es lo que dibuja ahora assets/js/autorizacion-vectorial.js
// en un PDF de verdad, con el emblema de la casa.
//
// Esta página se queda como la explicación de los requisitos —que es lo que
// recepción necesita tener a mano para decir qué pedir— y el botón de descarga.
// ------------------------------------------------------------
require_once __DIR__ . '/includes/legal.php';

$R = LEGAL_RESPONSABLE;

legalInicio(
    'Autorización para el alojamiento de un menor de edad',
    'Documento que los padres o representantes legales firman <strong>antes del viaje</strong> y que el acompañante presenta al llegar.'
);
?>

<p>
  Cuando un menor de edad se hospeda acompañado por alguien que no es su padre,
  madre o representante legal, el establecimiento debe exigir una autorización
  escrita. Descarga la carta, imprímela y llévala firmada: el hotel conserva el
  original.
</p>

<div class="lg-acciones" style="margin:22px 0 6px">
  <button class="lg-btn" type="button" id="btn-carta">Descargar la carta en PDF</button>
</div>
<div class="lg-nota" id="carta-error" role="alert"
     data-correo="<?= legalEsc($R['correo']) ?>"
     style="display:none; border-left-color:#B3261E; background:#FDEAEA"></div>
<p style="font-size:.82rem; color:var(--apagado); margin-top:0">
  Dos páginas, tamaño carta A4. La segunda incluye la autorización de tratamiento
  de datos del menor y el recuadro que diligencia recepción al recibirla.
</p>

<h2>Qué hay que presentar al llegar</h2>
<p>Junto con la carta, en el momento del ingreso:</p>
<ul>
  <li>La autorización <strong>en original</strong>, con reconocimiento de firma y
      contenido <strong>ante notario</strong>.</li>
  <li><strong>Registro civil de nacimiento</strong> del menor.</li>
  <li><strong>Copia del documento de identidad</strong> de quien o quienes firman
      la autorización.</li>
</ul>
<div class="lg-nota">
  Si al momento del ingreso no se cumple con todos los requisitos,
  <strong>no se permitirá el ingreso del menor</strong>.
</div>

<h2>Quién debe firmarla</h2>
<ul>
  <li>La emiten <strong>los dos padres conjuntamente</strong>.</li>
  <li>Si uno de ellos está <strong>en el exterior</strong>, el permiso debe estar
      autenticado ante el consulado colombiano.</li>
  <li>Si uno ha <strong>fallecido</strong>, se presenta el certificado de defunción.
      Si han fallecido ambos, la firma el representante legal o tutor.</li>
  <li>Si <strong>viven en lugares diferentes</strong>, se indica la residencia de cada
      uno y se autentica en una Notaría del Círculo de su domicilio.</li>
  <li>Si se desconoce el paradero de los padres, o no hay representante legal, la
      autorización se tramita ante el <strong>ICBF</strong>.</li>
  <li>Solo se puede autorizar a <strong>personas mayores de edad</strong>.</li>
</ul>

<div class="lg-nota">
  Esta autorización <strong>no sustituye</strong> el permiso de salida del país de
  Migración Colombia. Los requisitos de ese trámite se consultan en
  <a href="https://www.migracioncolombia.gov.co" target="_blank" rel="noopener">migracioncolombia.gov.co</a>.
</div>

<h2>Protección de menores</h2>
<p>
  El <strong><?= legalEsc($R['nombre']) ?></strong> rechaza toda forma de explotación
  sexual comercial, pornografía y turismo sexual que involucre a niños, niñas y
  adolescentes, e informará a las autoridades competentes cuando identifique
  situaciones que deban ser reportadas, conforme a la legislación colombiana.
  Quien firma la carta declara conocer las consecuencias establecidas en el
  <strong>artículo 17 de la Ley 679 de 2001</strong>.
</p>
<div class="lg-ficha">
  <dl>
    <dt>Canal de contacto</dt><dd><?= legalEsc($R['telefono']) ?></dd>
    <dt>Correo</dt><dd><a href="mailto:<?= legalEsc($R['correo']) ?>"><?= legalEsc($R['correo']) ?></a></dd>
  </dl>
</div>
<p style="font-size:.88rem; color:var(--apagado)">
  Consulta también la
  <a href="<?= BASE_URL ?>/politica-menores.php<?= legalVolverQS() ?>">política de tratamiento de
  datos de niños, niñas y adolescentes</a>.
</p>

<?php /* El emblema del PDF. Oculto porque su único uso es incrustarse en el
         documento; se carga aquí para que ya esté listo al pulsar el botón. */ ?>
<img id="cb-logo-pdf" src="<?= BASE_URL ?>/assets/img/emblema-96.png?v=<?= legalEsc(APP_VERSION) ?>"
     alt="" width="96" height="96" style="position:absolute;left:-9999px;top:0" aria-hidden="true">

<script src="<?= BASE_URL ?>/assets/js/jspdf.umd.js"></script>
<script src="<?= BASE_URL ?>/assets/js/autorizacion-vectorial.js?v=<?= legalEsc(APP_VERSION) ?>"></script>
<script>
document.getElementById('btn-carta').addEventListener('click', function () {
    var boton = this;
    boton.disabled = true;
    try {
        var doc = generarAutorizacionCasaBosquePDF({
            logo: document.getElementById('cb-logo-pdf')
        });
        doc.save('autorizacion-menor-casa-bosque.pdf');
    } catch (e) {
        // Aviso en TARJETA, no un dialogo del navegador: es la regla del
        // sistema. Esta pagina puede abrirla un huesped desde su celular, y un
        // boton que no responde no le dice nada.
        var caja = document.getElementById('carta-error');
        caja.textContent = 'No se pudo generar el PDF. Vuelve a intentarlo, o escribenos a '
            + caja.getAttribute('data-correo') + '.';
        caja.style.display = 'block';
    } finally {
        boton.disabled = false;
    }
});
</script>

<?php legalFin(); ?>
