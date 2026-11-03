/* ============================================================
   assets/js/pdf-casabosque.js
   Base común para dibujar documentos PDF — Casa Bosque (SIGH-CB)
   ============================================================

   POR QUÉ EXISTE ESTE ARCHIVO

   La paleta del logo y el ayudante `fijarTexto()` ya estaban escritos DOS
   veces, en `factura-vectorial.js` y en `autorizacion-vectorial.js`. Al
   añadirse los dos reportes dibujados iban a estar en tres sitios, y una
   paleta repetida es una paleta que un día deja de coincidir consigo misma.

   Aquí no hay ningún documento: solo los colores, las medidas de la hoja y
   las piezas que los documentos comparten —una banda de sección, una tabla
   con cabecera, una fila de total, un doble filete—.

   REGLA QUE NO SE PUEDE ROMPER, heredada de la factura: nada de html2canvas,
   html2pdf ni dom-to-image. Los documentos se DIBUJAN con primitivas de
   jsPDF sobre coordenadas fijas en milímetros. Una captura del DOM sale con
   el ancho de la pantalla de quien la descarga, no se puede seleccionar ni
   buscar, y se pixela al acercar.

   Los dos generadores que ya existían NO usan este archivo todavía:
   funcionan, están publicados y la carta de menores está cubierta por 57
   comprobaciones. Migrarlos ahorraría duplicación y no le daría nada a nadie,
   así que se hará cuando haya un motivo de verdad para tocarlos.
   ============================================================ */

(function (global) {
    'use strict';

    /* ── Paleta del logo ──
       La misma equivalencia que usan la factura y la carta de autorización.

       El oro (#C8963C) NO se usa para texto sobre fondo claro: no tiene
       contraste suficiente. Para texto va el bronce, que es el mismo tono pero
       legible. El oro queda para filetes y detalles. */
    var C = {
        corteza:   [62, 36, 18],      // #3E2412 — títulos, bandas, totales
        bronce:    [156, 103, 40],    // #9C6728 — acentos y subtítulos
        oro:       [200, 150, 60],    // #C8963C — filetes, nunca texto sobre claro
        arena:     [245, 235, 220],   // #F5EBDC — fondos suaves
        tinta:     [42, 23, 9],       // #2A1709 — texto principal
        gris:      [110, 96, 84],     // #6E6054 — etiquetas y secundario
        grisClaro: [232, 228, 221],   // #E8E4DD — cabeceras de tabla
        linea:     [237, 227, 210],   // #EDE3D2 — divisorias de fila
        blanco:    [255, 255, 255],
        rojo:      [179, 38, 30],     // #B3261E — saldos en contra
        ambar:     [138, 90, 18]      // #8A5A12 — avisos (el del descuadre)
    };

    var HOJA = { ancho: 210, alto: 297 };
    var IZQ = 16;
    var DER = 194;
    var ANCHO = DER - IZQ;

    /** Pesos colombianos: $240.000, sin decimales. */
    function formatearCOP(valor) {
        var n = Number(valor || 0);
        try {
            return '$' + n.toLocaleString('es-CO', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 0
            });
        } catch (e) {
            // Por si el navegador no trae la configuración regional.
            return '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }
    }

    function fijarTexto(doc, color, tam, estilo, fuente) {
        doc.setFont(fuente || 'helvetica', estilo || 'normal');
        doc.setFontSize(tam);
        doc.setTextColor(color[0], color[1], color[2]);
    }

    /** Recorta un texto al ancho dado y le pone puntos suspensivos. */
    function recortar(doc, texto, anchoMm) {
        texto = String(texto == null ? '' : texto);
        if (doc.getTextWidth(texto) <= anchoMm) return texto;
        while (texto.length > 1 && doc.getTextWidth(texto + '…') > anchoMm) {
            texto = texto.slice(0, -1);
        }
        return texto + '…';
    }

    /**
     * El emblema, listo para incrustar.
     *
     * El PNG es transparente CON NEGRO DEBAJO: las esquinas tienen alfa 127
     * sobre rgb(0,0,0). Si el visor que abre el PDF no respeta el canal alfa
     * —y los hay— saca ese negro y aparece un cuadro oscuro alrededor del
     * círculo, con el borde hecho un damero. Como el fondo del documento es
     * blanco, aquí se compone sobre blanco y se entrega SIN transparencia: una
     * imagen opaca la pintan todos los visores igual.
     *
     * Devuelve null si la imagen no está cargada, para que el encabezado use
     * su dibujo de respaldo en vez de meter un cuadro vacío.
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

    function rellenar(doc, color, x, y, ancho, alto) {
        doc.setFillColor(color[0], color[1], color[2]);
        doc.rect(x, y, ancho, alto, 'F');
    }

    function filete(doc, color, x1, y, x2, grosor) {
        doc.setDrawColor(color[0], color[1], color[2]);
        doc.setLineWidth(grosor || 0.2);
        doc.line(x1, y, x2, y);
    }

    /**
     * El doble filete contable bajo un total.
     *
     * Es el recurso de la hoja de arqueo de referencia, y vale la pena
     * conservarlo: dice «aquí se acaba de sumar» sin escribir una palabra.
     */
    function dobleFilete(doc, x1, y, x2) {
        // Ojo si alguien quiere poner aquí una Σ: las fuentes estándar de jsPDF
        // van en WinAnsi (CP1252), que trae acentos, «», el punto medio y la
        // raya larga, pero NO el alfabeto griego. Una Σ sale impresa como «£».
        // El doble filete dice lo mismo y sí se dibuja.
        filete(doc, C.corteza, x1, y, x2, 0.4);
        filete(doc, C.corteza, x1, y + 0.9, x2, 0.2);
    }

    /**
     * Banda de sección: el rectángulo con el título dentro.
     * Devuelve la Y donde sigue el contenido.
     */
    function bandaSeccion(doc, y, titulo, opciones) {
        opciones = opciones || {};
        var alto = opciones.alto || 7;
        rellenar(doc, opciones.fondo || C.corteza, IZQ, y, ANCHO, alto);
        fijarTexto(doc, opciones.texto || C.blanco, opciones.tam || 9, 'bold');
        doc.text(String(titulo).toUpperCase(), IZQ + 3, y + alto - 2.2);
        return y + alto + 4;
    }

    /** Título de bloque con la viñeta redonda, como el informe de referencia. */
    function tituloConVineta(doc, y, titulo) {
        doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.circle(IZQ + 1.8, y - 1.3, 1.8, 'F');
        fijarTexto(doc, C.corteza, 10.5, 'bold');
        doc.text(String(titulo), IZQ + 6.5, y);
        return y + 5;
    }

    /**
     * Una tabla con cabecera gris, filas alternas y, si se pide, una fila de
     * TOTAL resaltada en arena.
     *
     * Salta de página cuando se acaba el papel y **repite la cabecera**. Eso no
     * es un adorno: un arqueo al que le faltan movimientos no sirve, así que
     * aquí no se puede truncar como hace la factura con los consumos.
     *
     * @param cols  [{ titulo, ancho, align }]  — anchos en mm, deben sumar ANCHO
     * @param filas [[celda, ...]]              — ya formateadas, como texto
     * @param opciones.total   [celda, ...]     — fila final resaltada
     * @param opciones.limiteY  hasta dónde se puede dibujar antes de saltar
     * @param opciones.alSaltar función(doc) que prepara la hoja nueva y
     *                          devuelve la Y donde seguir
     * @return la Y donde termina la tabla
     */
    function tabla(doc, y, cols, filas, opciones) {
        opciones = opciones || {};
        var altoFila = opciones.altoFila || 6.2;
        var limiteY = opciones.limiteY || (HOJA.alto - 28);

        function cabecera(yc) {
            rellenar(doc, C.grisClaro, IZQ, yc, ANCHO, altoFila);
            fijarTexto(doc, C.corteza, 7.8, 'bold');
            var x = IZQ;
            cols.forEach(function (c) {
                var tx = c.align === 'right' ? x + c.ancho - 2
                       : c.align === 'center' ? x + c.ancho / 2
                       : x + 2;
                doc.text(String(c.titulo), tx, yc + altoFila - 2,
                         c.align === 'left' || !c.align ? undefined : { align: c.align });
                x += c.ancho;
            });
            return yc + altoFila;
        }

        y = cabecera(y);

        filas.forEach(function (fila, i) {
            if (y + altoFila > limiteY) {
                y = opciones.alSaltar ? opciones.alSaltar(doc) : y;
                y = cabecera(y);
            }
            if (i % 2 === 1) rellenar(doc, [250, 248, 244], IZQ, y, ANCHO, altoFila);
            fijarTexto(doc, C.tinta, 7.8, 'normal');
            var x = IZQ;
            cols.forEach(function (c, j) {
                var texto = String(fila[j] == null ? '' : fila[j]);
                var tx = c.align === 'right' ? x + c.ancho - 2
                       : c.align === 'center' ? x + c.ancho / 2
                       : x + 2;
                if (!c.align || c.align === 'left') {
                    doc.text(recortar(doc, texto, c.ancho - 4), tx, y + altoFila - 2);
                } else {
                    doc.text(texto, tx, y + altoFila - 2, { align: c.align });
                }
                x += c.ancho;
            });
            filete(doc, C.linea, IZQ, y + altoFila, DER, 0.1);
            y += altoFila;
        });

        if (!filas.length) {
            fijarTexto(doc, C.gris, 7.8, 'italic');
            doc.text(opciones.vacio || 'Sin registros.', IZQ + 2, y + altoFila - 2);
            y += altoFila;
        }

        if (opciones.total) {
            if (y + altoFila > limiteY && opciones.alSaltar) y = opciones.alSaltar(doc);
            rellenar(doc, C.arena, IZQ, y, ANCHO, altoFila + 0.6);
            fijarTexto(doc, C.corteza, 8.4, 'bold');
            var xt = IZQ;
            cols.forEach(function (c, j) {
                var texto = String(opciones.total[j] == null ? '' : opciones.total[j]);
                var tx = c.align === 'right' ? xt + c.ancho - 2
                       : c.align === 'center' ? xt + c.ancho / 2
                       : xt + 2;
                if (!c.align || c.align === 'left') doc.text(texto, tx, y + altoFila - 1.6);
                else doc.text(texto, tx, y + altoFila - 1.6, { align: c.align });
                xt += c.ancho;
            });
            y += altoFila + 0.6;
            dobleFilete(doc, IZQ, y + 0.4, DER);
            y += 2;
        }

        return y;
    }

    /** Una línea «etiqueta ............ valor», con el valor a la derecha. */
    function lineaValor(doc, y, etiqueta, valor, opciones) {
        opciones = opciones || {};
        fijarTexto(doc, opciones.colorEtiqueta || C.tinta, opciones.tam || 8.6,
                   opciones.negrita ? 'bold' : 'normal');
        doc.text(String(etiqueta), IZQ + (opciones.sangria || 0), y);
        fijarTexto(doc, opciones.colorValor || opciones.colorEtiqueta || C.tinta,
                   opciones.tam || 8.6, opciones.negrita ? 'bold' : 'normal');
        doc.text(String(valor), DER, y, { align: 'right' });
        return y + (opciones.salto || 5);
    }

    /** Texto largo, envuelto al ancho útil. Devuelve la Y de después. */
    function parrafo(doc, y, texto, opciones) {
        opciones = opciones || {};
        var ancho = opciones.ancho || ANCHO;
        fijarTexto(doc, opciones.color || C.gris, opciones.tam || 7.6,
                   opciones.estilo || 'normal');
        var lineas = doc.splitTextToSize(String(texto), ancho - (opciones.sangria || 0));
        var paso = opciones.interlinea || 3.6;
        lineas.forEach(function (l) {
            doc.text(l, IZQ + (opciones.sangria || 0), y);
            y += paso;
        });
        return y;
    }

    /** Dos espacios de firma, uno al lado del otro. */
    function firmas(doc, y, izquierda, derecha) {
        var anchoFirma = (ANCHO - 20) / 2;
        [[IZQ, izquierda], [IZQ + anchoFirma + 20, derecha]].forEach(function (f) {
            filete(doc, C.gris, f[0], y, f[0] + anchoFirma, 0.2);
            fijarTexto(doc, C.gris, 7.2, 'normal');
            doc.text(String(f[1]), f[0] + anchoFirma / 2, y + 4, { align: 'center' });
        });
        return y + 9;
    }

    global.PDFCasaBosque = {
        C: C,
        HOJA: HOJA,
        IZQ: IZQ,
        DER: DER,
        ANCHO: ANCHO,
        formatearCOP: formatearCOP,
        fijarTexto: fijarTexto,
        recortar: recortar,
        emblemaSobreBlanco: emblemaSobreBlanco,
        rellenar: rellenar,
        filete: filete,
        dobleFilete: dobleFilete,
        bandaSeccion: bandaSeccion,
        tituloConVineta: tituloConVineta,
        tabla: tabla,
        lineaValor: lineaValor,
        parrafo: parrafo,
        firmas: firmas
    };
})(window);
