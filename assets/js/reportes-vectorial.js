/* ============================================================
   assets/js/reportes-vectorial.js
   Arqueo de Caja e Informe de Ingresos, DIBUJADOS — Casa Bosque
   ============================================================

   POR QUÉ EXISTE ESTE ARCHIVO

   Los dos reportes se descargaban capturando el HTML con html2canvas. Eran los
   últimos documentos del sistema que lo hacían: la factura dejó de hacerlo en
   1.7.0 y la carta de autorización en 3.3.0. El problema es el mismo de
   siempre —el PDF salía con el ANCHO DE LA PANTALLA de quien lo descargaba,
   desde un celular con las columnas desacomodadas, como imagen que no se puede
   seleccionar ni buscar y que se pixela al acercar— y además, en un arqueo,
   tiene una consecuencia propia: una foto no puede saltar de página, así que
   una lista larga de movimientos se partía por donde cayera.

   Aquí se dibuja con primitivas de jsPDF sobre A4 en milímetros. Las piezas
   comunes —paleta, tablas, bandas, firmas— vienen de `pdf-casabosque.js`.

   DE DÓNDE SALEN LAS CIFRAS

   De `datos`, que lo arma el PHP de la página UNA sola vez y le sirve tanto a
   la hoja HTML que se imprime como a este generador. No se vuelve a calcular
   nada aquí: si se calculara, la hoja impresa y el PDF descargado podrían
   decir cosas distintas del mismo día, que es justo el fallo que `caja.php`
   resolvió un nivel más arriba.

   SOBRE LOS DISEÑOS

   Andrés pasó dos referencias —una hoja contable de arqueo y un informe
   financiero— y pidió adaptarlas, no copiarlas. La hoja de referencia parte el
   efectivo en denominaciones (cuántos billetes de 100); el sistema no guarda
   eso, así que su equivalente real es el desglose por MÉTODO DE PAGO, que
   cumple la misma función con datos que sí existen. Lo que sí se conserva son
   los recursos que hacen legible una hoja contable: bandas de sección
   numeradas, los Σ de subtotal, el doble filete bajo los totales y las firmas.
   ============================================================ */

(function (global) {
    'use strict';

    var P = global.PDFCasaBosque;
    if (!P) return;   // sin la base no hay nada que dibujar

    var C = P.C, IZQ = P.IZQ, DER = P.DER, ANCHO = P.ANCHO, HOJA = P.HOJA;
    var cop = P.formatearCOP;

    /* ════════════════════════════════════════════════════════════
       Piezas que comparten los dos documentos
       ════════════════════════════════════════════════════════════ */

    /**
     * La banda superior con el emblema, el nombre del hotel y el título.
     *
     * Es la cabecera del informe de referencia —barra de color, bloque con el
     * logotipo, título grande— con la paleta del logo.
     */
    function encabezado(doc, d, opciones, titulo, subtitulo) {
        // Barra superior al ancho completo de la hoja, como la referencia.
        P.rellenar(doc, C.corteza, 0, 0, HOJA.ancho, 4.5);

        var yBloque = 11;
        // Bloque redondeado con el emblema y el nombre.
        doc.setFillColor(C.corteza[0], C.corteza[1], C.corteza[2]);
        doc.roundedRect(IZQ, yBloque, 74, 15, 7.5, 7.5, 'F');

        var logo = opciones && opciones.logo ? P.emblemaSobreBlanco(opciones.logo) : null;
        if (logo) {
            try { doc.addImage(logo, 'PNG', IZQ + 4, yBloque + 2.5, 10, 10); }
            catch (e) { logo = null; }
        }
        if (!logo) {
            // Respaldo: el círculo con la inicial, como hace la factura cuando
            // el emblema no cargó. Mejor eso que un hueco.
            doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
            doc.circle(IZQ + 9, yBloque + 7.5, 5, 'F');
            P.fijarTexto(doc, C.corteza, 7, 'bold');
            doc.text('CB', IZQ + 9, yBloque + 8.6, { align: 'center' });
        }

        P.fijarTexto(doc, C.blanco, 10.5, 'bold');
        doc.text(P.recortar(doc, d.hotel || 'Casa Bosque', 50), IZQ + 18, yBloque + 7.2);
        P.fijarTexto(doc, [214, 198, 176], 6.4, 'normal');
        doc.text('Sistema de Gestión Hotelera', IZQ + 18, yBloque + 11.4);

        // Los puntos decorativos de la referencia, arriba a la derecha.
        var px = DER - 13;
        for (var f = 0; f < 3; f++) {
            for (var c = 0; c < 4; c++) {
                doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
                doc.circle(px + c * 4.2, yBloque + 2 + f * 4.2, 0.85, 'F');
            }
        }

        // Título grande, en bronce, como el amarillo de la referencia.
        P.fijarTexto(doc, C.bronce, 22, 'bold');
        doc.text(String(titulo), IZQ, 40);
        if (subtitulo) {
            P.fijarTexto(doc, C.gris, 8.4, 'normal');
            doc.text(String(subtitulo), IZQ, 46);
        }
        return 51;
    }

    /** La banda gris de metadatos: quién, cuándo, de qué periodo. */
    function bandaMetadatos(doc, y, pares) {
        var alto = 13;
        P.rellenar(doc, [238, 235, 230], IZQ, y, ANCHO, alto);
        var cols = pares.length;
        var anchoCol = ANCHO / cols;
        pares.forEach(function (par, i) {
            var x = IZQ + i * anchoCol + 3;
            P.fijarTexto(doc, C.gris, 6.2, 'bold');
            doc.text(String(par[0]).toUpperCase(), x, y + 5);
            P.fijarTexto(doc, C.corteza, 8.2, 'bold');
            doc.text(P.recortar(doc, String(par[1]), anchoCol - 6), x, y + 10);
        });
        return y + alto + 7;
    }

    /** Pie: franja inferior y la línea de generado. */
    function pie(doc, d, pagina, total) {
        P.fijarTexto(doc, C.gris, 6.4, 'normal');
        doc.text('Casa Bosque · Documento generado automáticamente · ' + (d.generado || ''),
                 IZQ, HOJA.alto - 12);
        if (total > 1) {
            doc.text('Hoja ' + pagina + ' de ' + total, DER, HOJA.alto - 12, { align: 'right' });
        }
        // Franja inferior al ancho de la hoja, como la referencia.
        P.rellenar(doc, C.corteza, 0, HOJA.alto - 7, HOJA.ancho, 7);
        doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.rect(0, HOJA.alto - 8.2, HOJA.ancho, 1.2, 'F');
    }

    /**
     * Numera las hojas al final.
     *
     * Hay que hacerlo cuando ya se sabe cuántas son, así que el pie se dibuja
     * aquí y no durante el recorrido.
     */
    function cerrarHojas(doc, d) {
        var total = doc.getNumberOfPages();
        for (var i = 1; i <= total; i++) {
            doc.setPage(i);
            pie(doc, d, i, total);
        }
    }

    /**
     * Prepara la hoja siguiente y devuelve la Y donde seguir.
     *
     * Lleva el nombre de la sección que continúa: una hoja 2 que empieza con
     * una tabla de cifras y ningún título no le dice nada a quien la encuentre
     * suelta sobre un escritorio.
     */
    /** «1 venta» / «16 ventas». El singular mal puesto se nota al leerlo. */
    function plural(n, uno, varios) {
        n = Number(n || 0);
        return n + ' ' + (n === 1 ? uno : varios);
    }

    function saltoDe(titulo) {
        return function (doc) {
            doc.addPage();
            P.rellenar(doc, C.corteza, 0, 0, HOJA.ancho, 4.5);
            P.fijarTexto(doc, C.gris, 7, 'italic');
            doc.text(String(titulo) + ' · continúa', IZQ, 14);
            return 18;
        };
    }

    /* ════════════════════════════════════════════════════════════
       A. ARQUEO DE CAJA
       ════════════════════════════════════════════════════════════ */

    function dibujarArqueo(doc, d, opciones) {
        var y = encabezado(doc, d, opciones, 'Arqueo de Caja',
                           'Dinero recibido y cuentas cerradas del día');

        y = bandaMetadatos(doc, y, [
            ['Día del arqueo', d.fechaLarga || ''],
            ['Responsable', d.responsable || ''],
            ['Generado', d.generado || '']
        ]);

        // ── 1. Dinero recibido ──
        // Va PRIMERO porque es lo que el arqueo busca: lo que hay que contar
        // en el cajón. Lo facturado viene después.
        y = P.bandaSeccion(doc, y, '1 · Dinero recibido');

        var metodos = (d.recibido && d.recibido.metodos) || [];
        y = P.tabla(doc, y,
            [{ titulo: 'Método de pago', ancho: ANCHO - 76 },
             { titulo: 'Movimientos', ancho: 32, align: 'center' },
             { titulo: 'Valor', ancho: 44, align: 'right' }],
            metodos.map(function (m) { return [m.nombre, String(m.movimientos), cop(m.total)]; }),
            {
                total: ['TOTAL RECIBIDO', '', cop(d.recibido ? d.recibido.total : 0)],
                vacio: 'No entró dinero en esta fecha.',
                limiteY: HOJA.alto - 24,
                alSaltar: saltoDe('Dinero recibido')
            });

        y += 1;
        P.fijarTexto(doc, C.gris, 7, 'normal');
        doc.text(plural(d.recibido ? d.recibido.pagos : 0, 'pago de estancia', 'pagos de estancias')
               + '  ·  ' + plural(d.recibido ? d.recibido.ventas : 0, 'venta de mostrador', 'ventas de mostrador'),
                 IZQ, y);
        y += 4.5;
        P.fijarTexto(doc, C.gris, 7, 'italic');
        doc.text('Cuente el efectivo del cajón y compárelo con la línea «Efectivo». '
               + 'Lo demás debe aparecer en el datáfono o en los extractos.', IZQ, y);
        y += 7;

        // ── 2. Cuentas cerradas ──
        y = P.bandaSeccion(doc, y, '2 · Cuentas cerradas · valor facturado');

        var fac = d.facturado || {};
        y = P.lineaValor(doc, y + 1, 'Hospedaje', cop(fac.hospedaje), { sangria: 2 });
        y = P.lineaValor(doc, y, 'Consumos', cop(fac.consumos), { sangria: 2 });
        if (fac.anuladas) {
            y = P.lineaValor(doc, y, 'Facturas anuladas en la fecha', String(fac.anuladas),
                             { sangria: 2, colorValor: C.rojo });
        }
        if (fac.pendiente) {
            y = P.lineaValor(doc, y, 'De esas cuentas, pendiente de cobro', cop(fac.pendiente),
                             { sangria: 2, colorValor: C.rojo });
        }
        P.dobleFilete(doc, IZQ, y - 1.2, DER);
        y += 2.5;
        y = P.lineaValor(doc, y, 'TOTAL FACTURADO  (' + (fac.cierres || 0) + ' check-out)',
                         cop(fac.total), { negrita: true, tam: 9.4, colorEtiqueta: C.corteza });
        y += 2;

        // ── El descuadre ──
        // Un descuadre casi nunca es un error: el dinero entra cuando entra y
        // la cuenta se factura cuando se cierra. Por eso se explica.
        if (d.descuadre) {
            var altoAviso = 11 + (d.descuadre.razones || []).length * 3.6;
            P.rellenar(doc, [255, 251, 242], IZQ, y, ANCHO, altoAviso);
            doc.setDrawColor(240, 220, 180);
            doc.setLineWidth(0.2);
            doc.rect(IZQ, y, ANCHO, altoAviso);
            P.fijarTexto(doc, C.ambar, 8, 'bold');
            doc.text('Facturado y recibido no coinciden: ' + cop(d.descuadre.diferencia)
                   + ' de más en lo ' + d.descuadre.sentido + '.', IZQ + 3, y + 5);
            var yr = y + 9;
            (d.descuadre.razones || []).forEach(function (r) {
                P.fijarTexto(doc, C.gris, 7, 'normal');
                doc.text('·  ' + r, IZQ + 5, yr);
                yr += 3.6;
            });
            y += altoAviso + 6;
        } else {
            P.fijarTexto(doc, C.gris, 7.2, 'italic');
            doc.text('Lo facturado y lo recibido coinciden en esta fecha.', IZQ, y + 3);
            y += 9;
        }

        // ── 3. Movimientos ──
        // Aquí NO se trunca: un arqueo al que le faltan movimientos no sirve.
        // Por eso la tabla sabe saltar de página repitiendo la cabecera.
        y = P.bandaSeccion(doc, y, '3 · Movimientos del día');

        var movs = (d.recibido && d.recibido.movimientos) || [];
        y = P.tabla(doc, y,
            // Los anchos suman ANCHO exacto. «Transferencia» necesita sus 28 mm
            // o sale recortada, y el valor sus 26: «$112.000» a 7,8 pt no cabe
            // en menos. Lo que cede es el concepto, que sí se puede recortar.
            [{ titulo: 'Hora', ancho: 14 },
             { titulo: 'Concepto', ancho: ANCHO - 110 },
             { titulo: 'Origen', ancho: 42 },
             { titulo: 'Método', ancho: 28 },
             { titulo: 'Valor', ancho: 26, align: 'right' }],
            movs.map(function (m) {
                return [m.hora, m.concepto, m.origen, m.metodo, cop(m.monto)];
            }),
            { vacio: 'Sin movimientos en esta fecha.', limiteY: HOJA.alto - 24,
              alSaltar: saltoDe('Movimientos del día') });

        // ── Firmas ──
        // Si no caben, se van a una hoja nueva: una firma partida no vale.
        if (y + 26 > HOJA.alto - 24) y = saltoDe('Arqueo de Caja')(doc);
        y += 14;
        P.firmas(doc, y, 'Firma del responsable', 'Firma de revisión / administración');

        cerrarHojas(doc, d);
    }

    /* ════════════════════════════════════════════════════════════
       B. INFORME DE INGRESOS
       ════════════════════════════════════════════════════════════ */

    function dibujarIngresos(doc, d, opciones) {
        var y = encabezado(doc, d, opciones, 'Informe de Ingresos', d.periodo || '');

        y = bandaMetadatos(doc, y, [
            ['Preparado por', d.responsable || ''],
            ['Período', d.periodo || ''],
            ['Generado', d.generado || '']
        ]);

        // ── Ingresos por estancias ──
        y = P.tituloConVineta(doc, y, 'Ingresos por estancias cerradas');

        var est = d.estancias || {};
        y = P.tabla(doc, y,
            [{ titulo: d.colPeriodo || 'Día', ancho: 34 },
             { titulo: 'Check-outs', ancho: 26, align: 'center' },
             { titulo: 'Hospedaje', ancho: ANCHO - 138, align: 'right' },
             { titulo: 'Consumos', ancho: 38, align: 'right' },
             { titulo: 'Total', ancho: 40, align: 'right' }],
            (est.filas || []).map(function (f) {
                return [f.periodo, String(f.checkouts), cop(f.hospedaje), cop(f.consumos), cop(f.total)];
            }),
            {
                total: ['TOTAL ESTANCIAS', String(est.checkouts || 0), cop(est.hospedaje),
                        cop(est.consumos), cop(est.total)],
                vacio: 'Sin check-outs cerrados en el período.',
                limiteY: HOJA.alto - 24,
                alSaltar: saltoDe('Ingresos por estancias cerradas')
            });
        y += 6;

        // ── Ventas de mostrador ──
        if (y + 40 > HOJA.alto - 24) y = saltoDe('Informe de Ingresos')(doc);
        y = P.tituloConVineta(doc, y, 'Ventas directas de mostrador');

        var ven = d.ventas || {};
        y = P.tabla(doc, y,
            [{ titulo: 'Método de pago', ancho: ANCHO - 50 },
             { titulo: 'Valor', ancho: 50, align: 'right' }],
            (ven.metodos || []).map(function (m) { return [m.nombre, cop(m.total)]; }),
            {
                total: ['TOTAL MOSTRADOR  (' + plural(ven.ventas, 'venta', 'ventas')
                        + ' / ' + plural(ven.unidades, 'unidad', 'unidades') + ')', cop(ven.total)],
                vacio: 'Sin ventas de mostrador en el período.',
                limiteY: HOJA.alto - 24,
                alSaltar: saltoDe('Ventas directas de mostrador')
            });
        y += 6;

        // ── Total general ──
        if (y + 22 > HOJA.alto - 24) y = saltoDe('Informe de Ingresos')(doc);
        P.rellenar(doc, C.corteza, IZQ, y, ANCHO, 15);
        P.fijarTexto(doc, [214, 198, 176], 6.6, 'bold');
        doc.text('TOTAL GENERAL DEL PERÍODO', IZQ + 4, y + 5.5);
        P.fijarTexto(doc, C.blanco, 14, 'bold');
        doc.text(cop(d.totalGeneral), DER - 4, y + 11, { align: 'right' });
        P.fijarTexto(doc, [196, 176, 150], 6.4, 'normal');
        doc.text('Estancias ' + cop(est.total) + '  +  mostrador ' + cop(ven.total),
                 IZQ + 4, y + 11);
        y += 21;

        // ── Por recepcionista ──
        if (y + 40 > HOJA.alto - 24) y = saltoDe('Informe de Ingresos')(doc);
        y = P.tituloConVineta(doc, y, 'Ingresos por recepcionista');
        y = P.tabla(doc, y,
            [{ titulo: 'Empleado', ancho: ANCHO - 134 },
             { titulo: 'Check-outs', ancho: 26, align: 'center' },
             { titulo: 'Hospedaje', ancho: 34, align: 'right' },
             { titulo: 'Consumos', ancho: 34, align: 'right' },
             { titulo: 'Total cerrado', ancho: 40, align: 'right' }],
            (d.porUsuario || []).map(function (u) {
                return [u.nombre, String(u.checkouts), cop(u.hospedaje), cop(u.consumos), cop(u.total)];
            }),
            { vacio: 'Sin datos de recepcionistas en el período.',
              limiteY: HOJA.alto - 24, alSaltar: saltoDe('Ingresos por recepcionista') });
        y += 7;

        // ── Nota ──
        // Es la que evita la confusión que este documento puede provocar.
        if (y + 26 > HOJA.alto - 24) y = saltoDe('Informe de Ingresos')(doc);
        y = P.tituloConVineta(doc, y, 'Nota');
        P.rellenar(doc, C.arena, IZQ, y - 3, ANCHO, 19);
        y = P.parrafo(doc, y + 2,
            'Las cifras de estancias son el VALOR FACTURADO de las cuentas cerradas en el '
          + 'período, no el dinero recibido: un abono puede haber entrado antes y una deuda '
          + 'cobrarse después. Las ventas directas de mostrador no pasan por ninguna cuenta '
          + 'de huésped, por eso van aparte y solo se suman en el total general. '
          + 'Para el dinero efectivamente recibido en una fecha, el documento es el Arqueo de Caja.',
            { sangria: 3, ancho: ANCHO - 6, tam: 7.2 });

        cerrarHojas(doc, d);
    }

    /* ════════════════════════════════════════════════════════════
       Entradas públicas
       ════════════════════════════════════════════════════════════ */

    function nuevoDoc() {
        var jsPDF = (global.jspdf && global.jspdf.jsPDF) || global.jsPDF;
        if (!jsPDF) throw new Error('jsPDF no está cargado.');
        var doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        doc.setFont('helvetica', 'normal');
        return doc;
    }

    function generarArqueoCasaBosquePDF(datos, opciones) {
        var doc = nuevoDoc();
        dibujarArqueo(doc, datos || {}, opciones || {});
        return doc;
    }

    function generarIngresosCasaBosquePDF(datos, opciones) {
        var doc = nuevoDoc();
        dibujarIngresos(doc, datos || {}, opciones || {});
        return doc;
    }

    global.generarArqueoCasaBosquePDF = generarArqueoCasaBosquePDF;
    global.generarIngresosCasaBosquePDF = generarIngresosCasaBosquePDF;
})(window);
