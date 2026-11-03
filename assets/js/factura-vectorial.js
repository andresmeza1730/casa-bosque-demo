/* ============================================================
   assets/js/factura-vectorial.js
   Generador NATIVO de la factura en PDF — Casa Bosque (SIGH-CB)
   ============================================================

   POR QUÉ EXISTE ESTE ARCHIVO

   La factura se descargaba capturando el HTML con html2canvas y pegando la
   imagen en un PDF. Eso ataba el documento al ancho de la PANTALLA de quien lo
   descargaba: desde un celular salía con las columnas desacomodadas y el pie
   partido en una segunda hoja. Y al ser una imagen, el texto no se podía
   seleccionar ni buscar, y se pixelaba al acercar el zoom.

   Aquí el PDF se DIBUJA, no se fotografía. Todo son primitivas de jsPDF
   —.text(), .rect(), .circle(), .line()— con coordenadas fijas en milímetros,
   así que el documento sale idéntico desde un celular y desde un PC, el texto
   es texto de verdad y cabe siempre en UNA sola hoja.

   REGLA QUE NO SE PUEDE ROMPER: nada de html2canvas, html2pdf ni dom-to-image.
   Si alguien vuelve a meter una captura del DOM, vuelve el mismo fallo.

   La única imagen del documento es el emblema de la casa, que se pasa ya
   cargado en `opciones.logo`. Un logotipo incrustado no es una captura de
   pantalla: no depende del ancho del dispositivo.

   Hoja: A4 vertical, 210 × 297 mm. Es la que usan las coordenadas de la
   especificación (el pie llega a 290 mm y la franja inferior al borde de
   210 mm de ancho), así que se respeta tal cual.
   ============================================================ */

(function (global) {
    'use strict';

    /* ── Paleta de Casa Bosque ──
       La especificación venía con la paleta verde que el sistema ya retiró.
       Andrés pidió expresamente: «aplicar lo de los colores actuales, solo
       toma las especificaciones, no su paleta». Esta es la equivalencia.

       El oro (#C8963C) NO se usa para texto sobre fondo claro: no tiene
       contraste suficiente. Para texto va el bronce, que es el mismo tono
       pero legible. El oro queda para filetes y detalles. */
    var C = {
        corteza:   [62, 36, 18],      // #3E2412 — primario: títulos, cabecera, totales
        bronce:    [156, 103, 40],    // #9C6728 — acento: círculos, subtítulos
        arena:     [245, 235, 220],   // #F5EBDC — fondo suave
        oro:       [200, 150, 60],    // #C8963C — filetes, nunca texto sobre claro
        tinta:     [42, 23, 9],       // #2A1709 — texto principal
        gris:      [110, 96, 84],     // #6E6054 — etiquetas y texto secundario
        linea:     [237, 227, 210],   // #EDE3D2 — divisorias de filas
        blanco:    [255, 255, 255],
        rojo:      [179, 38, 30],     // #B3261E — anulada (el rojo del sistema)
        rojoSuave: [253, 234, 234]    // #FDEAEA
    };

    var HOJA = { ancho: 210, alto: 297 };
    var MARGEN_IZQ = 20;
    var MARGEN_DER = 190;

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

    /** Recorta un texto al ancho dado y le pone puntos suspensivos. */
    function recortar(doc, texto, anchoMm) {
        texto = String(texto == null ? '' : texto);
        if (doc.getTextWidth(texto) <= anchoMm) return texto;
        while (texto.length > 1 && doc.getTextWidth(texto + '…') > anchoMm) {
            texto = texto.slice(0, -1);
        }
        return texto + '…';
    }

    function fijarTexto(doc, color, tam, estilo, fuente) {
        doc.setFont(fuente || 'helvetica', estilo || 'normal');
        doc.setFontSize(tam);
        doc.setTextColor(color[0], color[1], color[2]);
    }

    /**
     * El emblema, listo para incrustar.
     *
     * El PNG del emblema es transparente **con negro debajo**: las esquinas
     * tienen alfa 127 sobre rgb(0,0,0). Si el visor que abre el PDF no respeta
     * el canal alfa —y los hay—, saca ese negro y aparece un cuadro oscuro
     * alrededor del círculo, con el borde suavizado hecho un damero.
     *
     * Como el fondo de la factura es blanco, aquí se compone el emblema sobre
     * blanco y se entrega SIN transparencia. Una imagen opaca la pintan todos
     * los visores igual; una con alfa, no.
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

    /** Círculo sólido con una letra blanca centrada dentro. */
    function circuloConLetra(doc, x, y, radio, color, letra, tamLetra) {
        doc.setFillColor(color[0], color[1], color[2]);
        doc.circle(x, y, radio, 'F');
        if (letra) {
            fijarTexto(doc, C.blanco, tamLetra || 5, 'bold');
            doc.text(String(letra), x, y + (tamLetra || 5) * 0.125, { align: 'center' });
        }
    }

    // ════════════════════════════════════════════════════════════
    //  A. ENCABEZADO — Y de 14 a 52
    // ════════════════════════════════════════════════════════════
    function dibujarEncabezado(doc, d, opciones) {
        // Bloque del título. El número de la factura va AQUÍ, justo debajo de
        // la palabra FACTURA, que es donde se busca: antes estaba abajo, como
        // una fila más («Folio No.») entre la fecha y el nombre del cliente.
        doc.setFillColor(C.arena[0], C.arena[1], C.arena[2]);
        doc.roundedRect(124, 14, 71, 35, 4, 4, 'F');

        // Emblema de la casa. Si por lo que sea no cargó, se dibuja un anillo
        // con las iniciales: el documento nunca se queda sin marca.
        var logoOk = false;
        if (opciones && opciones.logo) {
            try {
                // Una cadena ya viene lista (data URI); un elemento hay que
                // aplanarlo sobre blanco antes, o el alfa da problemas.
                var imagen = typeof opciones.logo === 'string'
                    ? opciones.logo
                    : emblemaSobreBlanco(opciones.logo);
                if (imagen) {
                    doc.addImage(imagen, 'PNG', MARGEN_IZQ, 16, 14, 14);
                    logoOk = true;
                }
            } catch (e) {
                logoOk = false;
            }
        }
        if (!logoOk) {
            doc.setDrawColor(C.corteza[0], C.corteza[1], C.corteza[2]);
            doc.setLineWidth(1);
            doc.circle(MARGEN_IZQ + 7, 23, 6.6, 'S');
            fijarTexto(doc, C.corteza, 8, 'bold');
            doc.text('CB', MARGEN_IZQ + 7, 24.8, { align: 'center' });
        }

        fijarTexto(doc, C.corteza, 19, 'bold');
        doc.text('CASA BOSQUE.', MARGEN_IZQ, 39);

        // Bronce y no oro: el oro sobre blanco no se lee.
        fijarTexto(doc, C.bronce, 7.5, 'bold');
        doc.text('HOSPEDAJE · ' + (d.emisor.sistema || 'SIGH-CB'), MARGEN_IZQ, 44, { charSpace: 0.7 });

        // El NIT de quien emite, junto a su nombre: es ahí donde se busca en
        // una factura, no en la letra pequeña del pie.
        if (d.emisor.nit) {
            fijarTexto(doc, C.tinta, 8.5, 'normal');
            doc.text('NIT ' + d.emisor.nit, MARGEN_IZQ, 49.5);
        }

        var xTitulo = 188;

        fijarTexto(doc, C.corteza, 28, 'bold');
        doc.text('FACTURA', xTitulo, 29, { align: 'right' });

        // Filete de oro separando el título de su número. Aquí el oro sí vale:
        // es un trazo, no texto.
        doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.rect(xTitulo - 34, 32.4, 34, 0.9, 'F');

        fijarTexto(doc, C.corteza, 13, 'bold');
        doc.text('No. ' + d.folio, xTitulo, 40.2, { align: 'right' });

        fijarTexto(doc, C.gris, 7, 'normal');
        doc.text('Consecutivo interno · No es factura electrónica DIAN', xTitulo, 45.8, { align: 'right' });

        // Ya no hay anillo ni punto decorativos: con el bloque más alto
        // caían dentro y se montaban encima del texto.
    }

    // ════════════════════════════════════════════════════════════
    //  B. CLIENTE Y ESTADÍA — Y de 56 a 90
    // ════════════════════════════════════════════════════════════
    function filaDato(doc, x, y, etiqueta, valor, anchoValor, destacado) {
        fijarTexto(doc, C.gris, 8, 'normal');
        doc.text(etiqueta + ' :', x, y);
        var sangria = x + 27;
        fijarTexto(doc, C.tinta, destacado ? 9.5 : 9, destacado ? 'bold' : 'normal');
        doc.text(recortar(doc, valor, anchoValor - 27), sangria, y);
    }

    function dibujarCliente(doc, d) {
        var y = 57, salto = 6.4;
        var izq = MARGEN_IZQ, der = 125;

        // Sin «Folio No.»: el número de la factura está arriba, bajo el
        // título. Repetirlo aquí era decir dos veces lo mismo con dos líneas
        // de separación.
        filaDato(doc, izq, y, 'Fecha emisión', d.fechaEmision + ' · ' + d.horaEmision, 85);
        filaDato(doc, izq, y + salto, 'Facturar a', d.cliente.nombre, 85, true);
        filaDato(doc, izq, y + salto * 2,
            'Identificación',
            d.cliente.tipoDocumento + ' ' + d.cliente.numeroDocumento + ' (' + d.cliente.tipoPersona + ')', 85);

        // El contacto puede ser largo: se parte en dos renglones como mucho.
        fijarTexto(doc, C.gris, 8, 'normal');
        doc.text('Contacto :', izq, y + salto * 3);
        var contacto = [d.cliente.direccion, d.cliente.telefono, d.cliente.email]
            .filter(function (t) { return t && String(t).trim() !== ''; })
            .join(' · ');
        fijarTexto(doc, C.tinta, 8.5, 'normal');
        var lineas = doc.splitTextToSize(contacto || '—', 58).slice(0, 2);
        doc.text(lineas, izq + 27, y + salto * 3);

        // Columna derecha: la habitación va en una pastilla de color.
        var textoHab = d.estadia.habitacion;
        fijarTexto(doc, C.corteza, 9.5, 'bold');
        var anchoHab = doc.getTextWidth(textoHab) + 9;
        doc.setFillColor(C.arena[0], C.arena[1], C.arena[2]);
        doc.roundedRect(der + 27, y - 4.3, anchoHab, 6.4, 2, 2, 'F');
        fijarTexto(doc, C.gris, 8, 'normal');
        doc.text('Habitación :', der, y);
        fijarTexto(doc, C.corteza, 9.5, 'bold');
        doc.text(textoHab, der + 31.5, y);

        filaDato(doc, der, y + salto, 'Check-in', d.estadia.checkIn, 65);
        filaDato(doc, der, y + salto * 2, 'Check-out', d.estadia.checkOut, 65);
        filaDato(doc, der, y + salto * 3, 'Estancia',
            d.estadia.noches + ' noche' + (d.estadia.noches === 1 ? '' : 's'), 65);
    }

    // ════════════════════════════════════════════════════════════
    //  C. TABLA DE CONCEPTOS — Y de 94 a 172
    // ════════════════════════════════════════════════════════════
    var TABLA = {
        y: 86,
        alturaCabecera: 10,
        alturaFila: 9,
        yFin: 172,
        xConcepto: 24,
        anchoConcepto: 94,
        xValorUnit: 146,   // derecha
        xCantidad: 160,    // centro
        xImporte: 186      // derecha
    };

    function dibujarTabla(doc, d) {
        doc.setFillColor(C.corteza[0], C.corteza[1], C.corteza[2]);
        doc.rect(MARGEN_IZQ, TABLA.y, MARGEN_DER - MARGEN_IZQ, TABLA.alturaCabecera, 'F');

        fijarTexto(doc, C.blanco, 9.5, 'bold');
        var yCab = TABLA.y + 6.6;
        doc.text('Concepto / Descripción', TABLA.xConcepto, yCab);
        doc.text('Valor Unit.', TABLA.xValorUnit, yCab, { align: 'right' });
        doc.text('Cant.', TABLA.xCantidad, yCab, { align: 'center' });
        doc.text('Importe', TABLA.xImporte, yCab, { align: 'right' });

        var items = (d.items || []).slice();
        var y = TABLA.y + TABLA.alturaCabecera;
        var caben = Math.floor((TABLA.yFin - y) / TABLA.alturaFila);

        // UNA sola hoja, pase lo que pase. Si hay más conceptos de los que
        // caben, los últimos se agrupan en una fila resumen: así el documento
        // no se desborda y el total sigue cuadrando con la suma de la tabla.
        if (items.length > caben) {
            var sobran = items.splice(caben - 1);
            var importeSobrante = 0, cantidadSobrante = 0;
            sobran.forEach(function (it) {
                importeSobrante += Number(it.importe || 0);
                cantidadSobrante += Number(it.cantidad || 0);
            });
            items.push({
                concepto: 'Otros consumos',
                detalle: sobran.length + ' conceptos agrupados · el detalle completo está en el sistema',
                cantidad: cantidadSobrante,
                valorUnitario: null,
                importe: importeSobrante
            });
        }

        items.forEach(function (it) {
            var yTexto = y + 4.2;

            fijarTexto(doc, C.tinta, 9, 'bold');
            doc.text(recortar(doc, it.concepto, TABLA.anchoConcepto), TABLA.xConcepto, yTexto);

            if (it.detalle) {
                fijarTexto(doc, C.gris, 7.5, 'normal');
                doc.text(recortar(doc, it.detalle, TABLA.anchoConcepto), TABLA.xConcepto, yTexto + 3.6);
            }

            fijarTexto(doc, C.tinta, 9, 'normal');
            if (it.valorUnitario !== null && it.valorUnitario !== undefined) {
                doc.text(formatearCOP(it.valorUnitario), TABLA.xValorUnit, yTexto, { align: 'right' });
            }
            doc.text(String(it.cantidad), TABLA.xCantidad, yTexto, { align: 'center' });

            fijarTexto(doc, C.tinta, 9, 'bold');
            doc.text(formatearCOP(it.importe), TABLA.xImporte, yTexto, { align: 'right' });

            // Sin bordes verticales: solo una divisoria fina debajo.
            y += TABLA.alturaFila;
            doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
            doc.setLineWidth(0.25);
            doc.line(MARGEN_IZQ, y, MARGEN_DER, y);
        });

        // Donde termino la tabla. Lo necesita el bloque de totales para subir
        // a pegarse a ella cuando hay pocos conceptos.
        return y;
    }

    // ════════════════════════════════════════════════════════════
    //  D. TÉRMINOS Y TOTALES — Y de 178 a 230
    // ════════════════════════════════════════════════════════════
    // Donde arrancaba este bloque cuando estaba clavado. Sigue siendo el
    // tope: con la tabla llena, todo queda exactamente donde estaba.
    var TOTALES_Y_MAX = 178;

    function dibujarTotales(doc, d, yInicio) {
        // ── Columna izquierda: términos ──
        fijarTexto(doc, C.corteza, 9.5, 'bold');
        doc.text('Términos y condiciones', MARGEN_IZQ, yInicio + 6);

        fijarTexto(doc, C.gris, 8.5, 'normal');
        var parrafo = '¡Gracias por hospedarte con nosotros! Factura interna consecutiva ' + d.folio +
            ' de ' + d.emisor.nombre + '. No corresponde a una factura electrónica DIAN. ' +
            'Consérvala para cualquier aclaración.';
        doc.text(doc.splitTextToSize(parrafo, 85), MARGEN_IZQ, yInicio + 12, { lineHeightFactor: 1.4 });

        if (d.impuestos.notaRegimen) {
            fijarTexto(doc, C.bronce, 8, 'bold');
            doc.text(doc.splitTextToSize(d.impuestos.notaRegimen, 85), MARGEN_IZQ, yInicio + 33, { lineHeightFactor: 1.35 });
        }

        // ── Columna derecha: resumen financiero ──
        var xEtiqueta = 115, xValor = MARGEN_DER, y = yInicio + 6, salto = 6;

        function lineaTotal(etiqueta, valor) {
            fijarTexto(doc, C.gris, 9, 'normal');
            doc.text(etiqueta, xEtiqueta, y);
            fijarTexto(doc, C.tinta, 9, 'normal');
            doc.text(valor, xValor, y, { align: 'right' });
            y += salto;
        }

        lineaTotal('Hospedaje', formatearCOP(d.totales.hospedaje));
        lineaTotal('Consumos', formatearCOP(d.totales.consumos));
        lineaTotal('Subtotal Base', formatearCOP(d.totales.subtotal));

        // La fila de IVA SIEMPRE aparece, aunque valga cero: es lo que deja
        // claro de un vistazo bajo qué régimen se emitió el documento.
        var textoIva = Number(d.totales.iva) > 0
            ? formatearCOP(d.totales.iva)
            : '$0 (0,00%)';
        lineaTotal(d.impuestos.conceptoLegalIva || 'IVA', textoIva);

        doc.setDrawColor(C.corteza[0], C.corteza[1], C.corteza[2]);
        doc.setLineWidth(0.4);
        doc.line(112, y - 1.5, MARGEN_DER, y - 1.5);

        fijarTexto(doc, C.corteza, 11, 'bold');
        doc.text('TOTAL FACTURADO', xEtiqueta, y + 5);
        fijarTexto(doc, C.corteza, 12, 'bold');
        doc.text(formatearCOP(d.totales.total), xValor, y + 5, { align: 'right' });

        doc.setLineWidth(0.4);
        doc.line(112, y + 8.5, MARGEN_DER, y + 8.5);
        y += 14;

        // Abonado y saldo: no venían en la especificación, pero la factura en
        // pantalla ya los mostraba y quitarlos sería perder información que el
        // huésped necesita ver.
        if (Number(d.totales.abonado) > 0) {
            fijarTexto(doc, C.gris, 8.5, 'normal');
            doc.text('Abonado', xEtiqueta, y);
            fijarTexto(doc, C.tinta, 8.5, 'normal');
            doc.text(formatearCOP(d.totales.abonado), xValor, y, { align: 'right' });
            y += 5;
        }
        if (Number(d.totales.saldo) > 0) {
            fijarTexto(doc, C.rojo, 8.5, 'bold');
            doc.text('Saldo por cobrar', xEtiqueta, y);
            doc.text(formatearCOP(d.totales.saldo), xValor, y, { align: 'right' });
        }
    }

    // ════════════════════════════════════════════════════════════
    //  E. PIE: contacto, firma y geometría — Y de 234 a 290
    // ════════════════════════════════════════════════════════════
    function dibujarPie(doc, d) {
        var e = d.emisor;

        var filas = [
            ['U', [e.direccion, e.ciudad].filter(Boolean).join(' · ')],
            ['T', e.telefono ? 'Tel: ' + e.telefono : ''],
            ['S', e.nombre + ' Hospedaje · Sistema ' + (e.sistema || 'SIGH-CB')]
        ];
        if (e.email) filas[1][1] = (filas[1][1] ? filas[1][1] + ' · ' : '') + e.email;

        var y = 240;
        filas.forEach(function (f) {
            if (!f[1]) return;
            circuloConLetra(doc, MARGEN_IZQ + 2.2, y - 1, 2.2, C.bronce, f[0], 5);
            fijarTexto(doc, C.gris, 8.5, 'normal');
            doc.text(recortar(doc, f[1], 82), MARGEN_IZQ + 7, y);
            y += 7;
        });

        // Firma. Las fuentes que trae el PDF no incluyen una cursiva de
        // verdad; la itálica de Times es lo más cercano a un trazo manuscrito
        // sin tener que incrustar una tipografía entera en cada documento.
        var centroFirma = 162;
        fijarTexto(doc, C.corteza, 18, 'italic', 'times');
        doc.text(e.nombre, centroFirma, 246, { align: 'center' });

        doc.setDrawColor(C.linea[0], C.linea[1], C.linea[2]);
        doc.setLineWidth(0.3);
        doc.line(135, 249.5, MARGEN_DER, 249.5);

        fijarTexto(doc, C.tinta, 10, 'bold');
        doc.text(e.nombre + ' Hospedaje', centroFirma, 254.5, { align: 'center' });
        fijarTexto(doc, C.gris, 8.5, 'normal');
        doc.text((e.sistema || 'SIGH-CB') + ' · Administración', centroFirma, 259.5, { align: 'center' });

        // ── Geometría inferior ──
        // El semicírculo se dibuja centrado en el borde: la mitad que queda
        // fuera de la hoja la recorta el propio PDF.
        doc.setFillColor(C.bronce[0], C.bronce[1], C.bronce[2]);
        doc.circle(0, 278, 16, 'F');

        doc.setFillColor(C.arena[0], C.arena[1], C.arena[2]);
        doc.roundedRect(25, 271, 16, 12, 2, 2, 'F');
        doc.setFillColor(C.corteza[0], C.corteza[1], C.corteza[2]);
        [[29.5, 275], [36.5, 275], [29.5, 279.5], [36.5, 279.5]].forEach(function (p) {
            doc.circle(p[0], p[1], 1, 'F');
        });

        fijarTexto(doc, C.gris, 8, 'normal');
        doc.text(e.nombre + ' Hospedaje · ' + (e.ciudad || ''), 95, 275.5);

        var siglas = ['C', 'B', 'H'];
        siglas.forEach(function (s, i) {
            circuloConLetra(doc, 177 + i * 6.5, 274.5, 2.4, C.bronce, s, 5.5);
        });

        // Franja inferior: filete de oro y barra de corteza hasta el borde.
        doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.rect(95, 279.4, HOJA.ancho - 95, 0.9, 'F');
        doc.setFillColor(C.corteza[0], C.corteza[1], C.corteza[2]);
        doc.rect(95, 280.3, HOJA.ancho - 95, 5.5, 'F');
    }

    // ════════════════════════════════════════════════════════════
    //  Factura anulada
    // ════════════════════════════════════════════════════════════
    function dibujarBannerAnulada(doc, d) {
        doc.setFillColor(C.rojoSuave[0], C.rojoSuave[1], C.rojoSuave[2]);
        doc.setDrawColor(C.rojo[0], C.rojo[1], C.rojo[2]);
        doc.setLineWidth(0.4);
        doc.roundedRect(MARGEN_IZQ, 4.5, 170, 8.5, 1.5, 1.5, 'FD');

        fijarTexto(doc, C.rojo, 8, 'bold');
        doc.text('FACTURA ANULADA — SIN VALIDEZ', MARGEN_IZQ + 3, 8.4);

        var detalle = [];
        if (d.fechaAnulacion) detalle.push('Anulada el ' + d.fechaAnulacion);
        if (d.motivoAnulacion) detalle.push('Motivo: ' + d.motivoAnulacion);
        if (detalle.length) {
            fijarTexto(doc, C.rojo, 7.5, 'normal');
            doc.text(recortar(doc, detalle.join('. '), 163), MARGEN_IZQ + 3, 11.8);
        }
    }

    function dibujarMarcaDeAgua(doc) {
        // Al 10% de opacidad no tapa ni un monto ni una línea legal, que es
        // justo lo que pide la especificación.
        var GState = global.jspdf && global.jspdf.GState;
        var tieneOpacidad = typeof doc.setGState === 'function' && typeof GState === 'function';

        if (tieneOpacidad) doc.setGState(new GState({ opacity: 0.1 }));
        fijarTexto(doc, C.rojo, 54, 'bold');
        doc.text('ANULADA', 105, 155, { align: 'center', angle: 35 });
        if (tieneOpacidad) doc.setGState(new GState({ opacity: 1 }));
    }

    // ════════════════════════════════════════════════════════════
    //  Entrada pública
    // ════════════════════════════════════════════════════════════
    /**
     * Dibuja la factura y devuelve el documento jsPDF.
     *
     * @param {Object} datos     contrato descrito en la especificación
     * @param {Object} opciones  { logo: HTMLImageElement }
     */
    function generarFacturaCasaBosquePDF(datos, opciones) {
        opciones = opciones || {};
        var jsPDF = (global.jspdf && global.jspdf.jsPDF) || global.jsPDF;
        if (!jsPDF) throw new Error('Falta jsPDF: no se puede dibujar la factura.');

        var doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

        // Fondo blanco explícito: no se hereda de ninguna pantalla.
        doc.setFillColor(255, 255, 255);
        doc.rect(0, 0, HOJA.ancho, HOJA.alto, 'F');

        if (datos.anulada) dibujarBannerAnulada(doc, datos);

        dibujarEncabezado(doc, datos, opciones);
        dibujarCliente(doc, datos);

        // Con pocos conceptos la tabla terminaba muy arriba y el bloque de
        // totales se quedaba clavado abajo, dejando un hueco en blanco en
        // mitad de la hoja. Ahora sube a pegarse a la tabla, y el aire que
        // sobra cae al final, antes del pie, que es donde no molesta.
        var yFinTabla = dibujarTabla(doc, datos);
        dibujarTotales(doc, datos, Math.min(TOTALES_Y_MAX, yFinTabla + 14));
        dibujarPie(doc, datos);

        // La marca de agua va al final para quedar por encima, pero traslúcida.
        if (datos.anulada) dibujarMarcaDeAgua(doc);

        doc.setProperties({
            title: 'Factura ' + datos.folio + ' — ' + datos.emisor.nombre,
            subject: 'Factura interna consecutiva · no es factura electrónica DIAN',
            author: datos.emisor.nombre,
            creator: datos.emisor.sistema || 'SIGH-CB'
        });

        return doc;
    }

    global.generarFacturaCasaBosquePDF = generarFacturaCasaBosquePDF;
    global.formatearCOP = global.formatearCOP || formatearCOP;

})(window);
