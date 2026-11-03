/* ============================================================
   assets/js/autorizacion-vectorial.js
   Carta de autorización de ingreso de menores — Casa Bosque (SIGH-CB)
   ============================================================

   POR QUÉ EXISTE ESTE ARCHIVO

   La carta era una página HTML con campos etiquetados que se mandaba a
   imprimir desde el navegador. Dos problemas: el formato no era el que usan
   los hoteles del país, y al imprimirla salía sin logo, con la cabecera y el
   pie del navegador, y con los márgenes que cada quien tuviera puestos.

   El formato correcto es una CARTA formal, que es el que traen los dos modelos
   reales que dio Andrés (uno genérico colombiano y el del Hotel Mocawa Plaza):

     · encabezado «Ciudad / Señores / E. S. M. / Ref.»
     · el cuerpo en PÁRRAFO CORRIDO con los huecos en línea, no una lista de
       campos: así está redactado como una declaración de los padres y no como
       un formulario del hotel
     · firma Y HUELLA de cada autorizante, con nombre y documento debajo
     · notas al pie numeradas con los requisitos legales
     · la declaración del artículo 17 de la Ley 679 de 2001, en mayúsculas

   Igual que la factura (assets/js/factura-vectorial.js), el PDF se DIBUJA con
   primitivas de jsPDF en milímetros sobre A4. REGLA QUE NO SE PUEDE ROMPER:
   nada de html2canvas, html2pdf ni dom-to-image. Una captura del DOM ata el
   documento al ancho de la pantalla de quien lo descarga y convierte el texto
   en una imagen que no se puede seleccionar ni buscar.

   La carta va EN BLANCO a propósito. No se rellena con los datos de la
   reserva: la firman los padres ANTES de viajar y el acompañante la presenta
   en el mostrador, así que cuando el hotel la necesita todavía no hay reserva
   que consultar. Los dos modelos de referencia también van en blanco.
   ============================================================ */

(function (global) {
    'use strict';

    /* Paleta del logo, la misma equivalencia que usa la factura. El oro no se
       usa para texto sobre blanco: no tiene contraste. */
    var C = {
        corteza: [62, 36, 18],     // #3E2412
        bronce:  [156, 103, 40],   // #9C6728
        oro:     [200, 150, 60],   // #C8963C
        tinta:   [42, 23, 9],      // #2A1709
        gris:    [110, 96, 84],    // #6E6054
        linea:   [160, 145, 128]
    };

    var HOJA = { ancho: 210, alto: 297 };
    var IZQ = 20;
    var DER = 190;
    var ANCHO = DER - IZQ;

    function fijarTexto(doc, color, tam, estilo) {
        doc.setFont('helvetica', estilo || 'normal');
        doc.setFontSize(tam);
        doc.setTextColor(color[0], color[1], color[2]);
    }

    /**
     * El emblema, aplanado sobre blanco y sin transparencia.
     *
     * Duplicado a propósito de assets/js/factura-vectorial.js: son quince
     * líneas de lienzo sin lógica de negocio, y extraerlas a un archivo común
     * obligaría a tocar el generador de la factura, que lleva meses
     * funcionando. Si alguna vez hay un tercer documento, entonces sí conviene
     * sacarlo a un archivo aparte.
     *
     * El PNG del emblema es transparente CON NEGRO DEBAJO: si el visor que
     * abre el PDF no respeta el canal alfa —y los hay—, saca ese negro y
     * aparece un cuadro oscuro alrededor del círculo.
     */
    function emblemaSobreBlanco(el) {
        if (!el || !el.complete || !el.naturalWidth) return null;
        try {
            var lienzo = document.createElement('canvas');
            lienzo.width = el.naturalWidth;
            lienzo.height = el.naturalHeight;
            var g = lienzo.getContext('2d');
            g.fillStyle = '#FFFFFF';
            g.fillRect(0, 0, lienzo.width, lienzo.height);
            g.drawImage(el, 0, 0);
            return lienzo.toDataURL('image/png');
        } catch (e) {
            return null;
        }
    }

    /**
     * Dibuja un párrafo con HUECOS EN LÍNEA para rellenar a mano.
     *
     * Esta es la pieza que hace que la carta se lea como una declaración y no
     * como un formulario. Recibe una lista de piezas y las va colocando
     * palabra por palabra, midiendo con getTextWidth y saltando de renglón al
     * llegar al margen. jsPDF no sabe hacer esto solo: su splitTextToSize
     * parte una cadena, pero no entiende que en mitad de la frase hay que
     * dibujar una raya de un ancho dado.
     *
     * Piezas: {t:'texto'} normal · {b:'texto'} negrita · {l:30} raya de 30 mm
     */
    function parrafo(doc, piezas, y, opciones) {
        opciones = opciones || {};
        var tam  = opciones.tam || 9.5;
        var alto = opciones.alto || 5.4;
        var x = IZQ;

        function saltar() { x = IZQ; y += alto; }

        for (var i = 0; i < piezas.length; i++) {
            var p = piezas[i];

            if (p.l) {
                // Una raya no se parte: si no cabe entera, pasa al renglón
                // siguiente. Partida en dos a nadie le cabe el nombre.
                if (x + p.l > DER) saltar();
                doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
                doc.setLineWidth(0.25);
                doc.line(x, y + 0.8, x + p.l, y + 0.8);
                x += p.l + 1.2;
                continue;
            }

            var estilo = p.b ? 'bold' : 'normal';
            var texto = String(p.b || p.t || '');
            fijarTexto(doc, C.tinta, tam, estilo);

            // La puntuacion se PEGA a lo anterior. Cada palabra avanza x
            // incluyendo su espacio final, asi que un trozo que empieza por
            // coma o punto saldria separado: «Casa Bosque CR , en el que».
            if (x > IZQ && /^[,.;:)]/.test(texto)) {
                x -= doc.getTextWidth(' ');
            }

            var palabras = texto.split(' ');
            for (var j = 0; j < palabras.length; j++) {
                var w = palabras[j];
                if (w === '') continue;
                var ancho = doc.getTextWidth(w + ' ');
                if (x + ancho > DER) saltar();
                fijarTexto(doc, C.tinta, tam, estilo);
                doc.text(w, x, y);
                x += ancho;
            }
        }
        return y + alto;
    }

    /** Texto corrido normal, sin huecos. Devuelve la Y siguiente. */
    function texto(doc, cadena, y, opciones) {
        opciones = opciones || {};
        var tam  = opciones.tam || 8;
        var alto = opciones.alto || 3.7;
        var sangria = opciones.sangria || 0;
        fijarTexto(doc, opciones.color || C.gris, tam, opciones.estilo || 'normal');
        var lineas = doc.splitTextToSize(cadena, ANCHO - sangria);
        for (var i = 0; i < lineas.length; i++) {
            doc.text(lineas[i], IZQ + sangria, y);
            y += alto;
        }
        return y;
    }

    /** Membrete: emblema, nombre del hotel y filete dorado. */
    function membrete(doc, opciones) {
        var puesto = false;
        if (opciones && opciones.logo) {
            try {
                var img = typeof opciones.logo === 'string'
                    ? opciones.logo
                    : emblemaSobreBlanco(opciones.logo);
                if (img) { doc.addImage(img, 'PNG', IZQ, 14, 15, 15); puesto = true; }
            } catch (e) { puesto = false; }
        }
        if (!puesto) {
            // Sin emblema el documento no se queda sin marca.
            doc.setDrawColor(C.corteza[0], C.corteza[1], C.corteza[2]);
            doc.setLineWidth(1);
            doc.circle(IZQ + 7.5, 21.5, 7, 'S');
            fijarTexto(doc, C.corteza, 8.5, 'bold');
            doc.text('CB', IZQ + 7.5, 23.4, { align: 'center' });
        }

        fijarTexto(doc, C.corteza, 13, 'bold');
        doc.text('HOTEL Y RESTAURANTE CASA BOSQUE CR', IZQ + 19, 21);
        fijarTexto(doc, C.gris, 7.2, 'normal');
        doc.text('NIT 1047375577-9  ·  Tv. 48 #20-26, El Bosque, Cartagena  ·  Tel. 3117296803',
                 IZQ + 19, 26);

        doc.setDrawColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.setLineWidth(0.8);
        doc.line(IZQ, 32, DER, 32);
    }

    /** Pie con la numeración de páginas. */
    function pie(doc, pagina, total) {
        fijarTexto(doc, C.gris, 7, 'normal');
        doc.text('Página ' + pagina + ' de ' + total, DER, 291, { align: 'right' });
        doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
        doc.setLineWidth(0.2);
        doc.line(IZQ, 287, DER, 287);
    }

    /** Dos bloques de firma, lado a lado, con espacio para la huella. */
    function firmas(doc, y, etiquetas) {
        var anchoCol = 78;
        var cols = [IZQ, IZQ + anchoCol + 14];

        for (var i = 0; i < 2; i++) {
            var x = cols[i];
            // El recuadro de la huella: los dos modelos de referencia la piden.
            doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
            doc.setLineWidth(0.25);
            doc.roundedRect(x + anchoCol - 20, y - 16, 20, 24, 1, 1, 'S');
            fijarTexto(doc, C.gris, 6, 'normal');
            doc.text('Huella', x + anchoCol - 10, y + 5, { align: 'center' });

            doc.setDrawColor(C.tinta[0], C.tinta[1], C.tinta[2]);
            doc.setLineWidth(0.3);
            doc.line(x, y + 10, x + anchoCol - 24, y + 10);

            fijarTexto(doc, C.gris, 7.4, 'normal');
            var lineas = etiquetas[i];
            for (var j = 0; j < lineas.length; j++) {
                doc.text(lineas[j], x, y + 14.5 + j * 3.6);
            }
        }
        return y + 14.5 + 3 * 3.6 + 4;
    }

    // ════════════════════════════════════════════════════════════
    //  Página 1 — la autorización
    // ════════════════════════════════════════════════════════════
    function paginaUno(doc, opciones) {
        membrete(doc, opciones);

        fijarTexto(doc, C.corteza, 11.5, 'bold');
        doc.text('AUTORIZACIÓN DE INGRESO DE MENORES AL HOTEL', 105, 42, { align: 'center' });
        doc.text('EN COMPAÑÍA DE TERCEROS', 105, 48, { align: 'center' });

        var y = 60;
        y = parrafo(doc, [
            { t: 'Ciudad: Cartagena' }, { t: '   Día:' }, { l: 16 },
            { t: 'Mes:' }, { l: 26 }, { t: 'Año:' }, { l: 18 }
        ], y, { tam: 9.5 });

        y += 3;
        fijarTexto(doc, C.tinta, 9.5, 'normal');
        doc.text('Señores', IZQ, y); y += 5.4;
        fijarTexto(doc, C.tinta, 9.5, 'bold');
        doc.text('HOTEL Y RESTAURANTE CASA BOSQUE CR', IZQ, y); y += 5.4;
        fijarTexto(doc, C.tinta, 9.5, 'normal');
        doc.text('E.    S.    M.', IZQ, y); y += 8;

        y = parrafo(doc, [
            { b: 'Ref.' },
            { t: 'Autorización de ingreso al hotel de menores de edad en compañía de persona(s) diferente(s) a sus padres (1).' }
        ], y, { tam: 9.5 });

        y += 4;
        y = parrafo(doc, [
            { l: 62 }, { t: 'y' }, { l: 62 }, { t: '(2), identificados como se indica debajo de nuestras correspondientes firmas, domiciliados y residentes en' },
            { l: 55 }, { t: '(3), actuando en calidad de representantes legales de nuestro(a) menor hijo(a)' },
            { l: 62 }, { t: ', identificado(a) con' }, { l: 46 }, { t: '(4); a ustedes respetuosamente informamos, que hemos' },
            { b: 'AUTORIZADO' }, { t: 'a' }, { l: 55 }, { t: ', identificado(a) con' }, { l: 46 },
            { t: ', domiciliado(a) y residente en' }, { l: 46 },
            { t: '(5), para que se hospede en ese hotel en compañía de nuestro(a) mencionado(a) hijo(a) durante los días comprendidos del' },
            { l: 14 }, { t: 'al' }, { l: 14 }, { t: 'del mes de' }, { l: 26 }, { t: 'de' }, { l: 16 }, { t: '.' },
            { t: 'El(a) Sr.(a)' }, { l: 62 },
            { t: ', será el(a) responsable del cuidado y comportamiento de nuestro(a) hijo(a) durante su estadía en el hotel, por lo cual deslindamos de cualquier responsabilidad al' },
            { b: 'Hotel y Restaurante Casa Bosque CR' }, { t: ', en el que se va a hospedar el(a) menor.' }
        ], y, { tam: 9.5, alto: 5.6 });

        y += 6;
        fijarTexto(doc, C.tinta, 9.5, 'normal');
        doc.text('Firmado,', IZQ, y);
        y += 26;

        var bloque = ['Nombres y apellidos completos (6)', 'De quien autoriza', 'Tipo y No. de identificación'];
        y = firmas(doc, y, [bloque, ['Nombres y apellidos completos (7)', 'De quien autoriza', 'Tipo y No. de identificación']]);

        // ── Notas al pie ──
        y = Math.max(y + 4, 207);
        doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
        doc.setLineWidth(0.2);
        doc.line(IZQ, y, IZQ + 60, y);
        y += 5;

        var notas = [
            'Esta autorización NO sustituye el permiso de salida del país establecido por la oficina de migración de Colombia, el cual debe presentarse de acuerdo con las directrices emitidas por esa entidad. Verificar requisitos en www.migracioncolombia.gov.co.',
            'Esta autorización deben emitirla los padres conjuntamente. Si el padre o madre que otorga la autorización se encuentra en el exterior, el permiso debe estar autenticado ante el consulado colombiano. Cuando uno de los padres haya fallecido, debe presentarse el certificado de defunción. En caso de fallecimiento de los dos padres, la autorización debe ser suscrita, conforme a la Ley, por el representante legal o tutor del(a) menor. Cuando se desconozca el paradero de los padres o de uno de ellos, cuando no estén en condiciones de otorgar el permiso o cuando se carezca de representante legal, la autorización deberá tramitarse ante el ICBF.',
            'En el caso de que los padres vivan en lugares diferentes, debe indicarse el lugar de residencia de cada uno de ellos y autenticar el permiso en una Notaría del Círculo de su domicilio, con reconocimiento de firma y contenido.',
            'Junto a este permiso, que debe presentarse al momento del ingreso al hotel en original y con reconocimiento de firma y contenido ante notario, debe anexarse el registro civil de nacimiento del(a) menor y copia de la identificación de quien(es) emite(n) la autorización. Si al momento del ingreso no se cumple con todos los requisitos aquí indicados, NO se permitirá el ingreso del(a) menor.',
            'Solo se puede autorizar a personas mayores de edad según la legislación colombiana.'
        ];
        for (var i = 0; i < notas.length; i++) {
            fijarTexto(doc, C.gris, 6.6, 'bold');
            doc.text(String(i + 1), IZQ, y);
            y = texto(doc, notas[i], y, { tam: 6.6, alto: 2.9, sangria: 4 });
            y += 1.4;
        }

        y += 2;
        fijarTexto(doc, C.corteza, 7, 'bold');
        var decl = doc.splitTextToSize(
            'AL FIRMAR ESTA AUTORIZACIÓN, MANIFIESTO QUE CONOZCO LAS CONSECUENCIAS DE LA EXPLOTACIÓN Y ABUSO SEXUAL DE MENORES ESTABLECIDAS EN EL ART. 17 DE LA LEY 679 DE 2001 Y DEMÁS NORMAS QUE LA MODIFICAN O ADICIONAN.',
            ANCHO);
        for (var k = 0; k < decl.length; k++) { doc.text(decl[k], IZQ, y); y += 3.2; }

        pie(doc, 1, 2);
    }

    // ════════════════════════════════════════════════════════════
    //  Página 2 — datos personales del menor y verificación del hotel
    // ════════════════════════════════════════════════════════════
    function paginaDos(doc, opciones) {
        doc.addPage();
        membrete(doc, opciones);

        fijarTexto(doc, C.corteza, 11.5, 'bold');
        doc.text('AUTORIZACIÓN PARA EL TRATAMIENTO DE DATOS', 105, 42, { align: 'center' });
        doc.text('PERSONALES DEL MENOR', 105, 48, { align: 'center' });

        var y = 60;
        y = parrafo(doc, [
            { t: 'Yo' }, { l: 58 }, { t: '(padre) y' }, { l: 58 }, { t: '(madre), en calidad de representantes legales del(a) menor' },
            { l: 62 }, { t: ', identificado(a) con' }, { l: 46 }, { t: ', mediante el presente documento manifestamos que autorizamos de manera' },
            { b: 'libre, previa, expresa e informada' },
            { t: 'al Hotel y Restaurante Casa Bosque CR, en la ciudad de Cartagena, para tratar los datos personales que llegase a recolectar del(a) menor, con el fin de hacer posible la prestación de los servicios de alojamiento y los reportes a las autoridades de control y vigilancia, de acuerdo con los términos de su Política de Tratamiento de Datos Personales.' }
        ], y, { tam: 9.5, alto: 5.6 });

        y += 3;
        y = texto(doc,
            'El tratamiento se realiza conforme a la Ley 1581 de 2012 y el Decreto 1377 de 2013, respetando el interés superior del menor. Los titulares y sus representantes legales pueden conocer, actualizar, rectificar y solicitar la supresión de los datos, y revocar esta autorización cuando resulte procedente, escribiendo a jorgeluiscas_23@hotmail.com. La política completa está publicada en el sitio web del hotel.',
            y, { tam: 8, alto: 3.7, color: C.gris });

        y += 10;
        y = firmas(doc, y + 14, [
            ['Firma del padre o representante legal', 'Nombre completo', 'Tipo y No. de identificación'],
            ['Firma de la madre o representante legal', 'Nombre completo', 'Tipo y No. de identificación']
        ]);

        // ── Verificación del establecimiento ──
        y += 10;
        doc.setDrawColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.setLineWidth(0.6);
        doc.line(IZQ, y, DER, y);
        y += 7;
        fijarTexto(doc, C.corteza, 10, 'bold');
        doc.text('Verificación por el establecimiento', IZQ, y);
        y += 4;
        y = texto(doc, 'Lo diligencia el personal de recepción al recibir el documento. No lo llenan los padres.',
                  y + 2, { tam: 7.4, alto: 3.4, color: C.gris });
        y += 4;

        var campos = [
            ['Fecha y hora de revisión', 70], ['Funcionario responsable', 90],
            ['Documentos revisados', 150], ['Observaciones', 150]
        ];
        for (var i = 0; i < campos.length; i++) {
            fijarTexto(doc, C.gris, 7.4, 'normal');
            doc.text(campos[i][0], IZQ, y);
            doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
            doc.setLineWidth(0.25);
            // Acotado al margen: las dos ultimas rayas pedian 150 mm desde
            // los 62, o sea 212 en una hoja de 210, y se salian del papel.
            var anchoRaya = Math.min(campos[i][1], DER - (IZQ + 42));
            doc.line(IZQ + 42, y + 0.8, IZQ + 42 + anchoRaya, y + 0.8);
            y += 9;
        }

        y += 8;
        doc.setDrawColor(C.tinta[0], C.tinta[1], C.tinta[2]);
        doc.setLineWidth(0.3);
        doc.line(IZQ, y, IZQ + 78, y);
        fijarTexto(doc, C.gris, 7.4, 'normal');
        doc.text('Firma del funcionario', IZQ, y + 4.5);

        pie(doc, 2, 2);
    }

    /**
     * Dibuja la carta y devuelve el documento jsPDF.
     * @param {Object} opciones { logo: HTMLImageElement }
     */
    function generarAutorizacionCasaBosquePDF(opciones) {
        var jsPDF = (global.jspdf && global.jspdf.jsPDF) || global.jsPDF;
        if (!jsPDF) throw new Error('jsPDF no está cargado.');

        var doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        doc.setProperties({
            title: 'Autorización de ingreso de menores — Casa Bosque',
            author: 'Hotel y Restaurante Casa Bosque CR'
        });

        paginaUno(doc, opciones || {});
        paginaDos(doc, opciones || {});
        return doc;
    }

    global.generarAutorizacionCasaBosquePDF = generarAutorizacionCasaBosquePDF;
    global.HOJA_AUTORIZACION = HOJA;
})(window);
