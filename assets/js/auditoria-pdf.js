/* ============================================================
   assets/js/auditoria-pdf.js
   La pista de auditoría, DIBUJADA — Casa Bosque (SIGH-CB)
   ============================================================

   Se dibuja con primitivas de jsPDF sobre A4 en milímetros, con las piezas de
   `pdf-casabosque.js` (nada de html2canvas: ver su cabecera).

   LO QUE LLEVA, Y POR QUÉ
     - FOLIADO: «Hoja n de N» en cada hoja y un folio del documento. Es un registro
       que puede acabar en manos de un auditor: no puede perder una hoja sin que se note.
     - SELLO DE INTEGRIDAD (SHA-256), calculado por el servidor sobre las filas
       exportadas. Quien reciba el documento puede volver a calcularlo. NO es una
       firma digital con certificado: no dice quién lo firmó, solo que el contenido
       no se alteró, y el documento lo dice así.
     - NADA RECORTADO: el detalle de cada fila se parte en las líneas que haga falta
       (las tablas de pdf-casabosque.js recortan con «…», y en una auditoría un
       detalle cortado es un detalle perdido).

   OJO con los caracteres: jsPDF usa WinAnsi (CP1252). Trae acentos, «», · y —, pero
   NO la flecha «→»: se escribe «->».
   ============================================================ */

(function (global) {
    'use strict';

    var P = global.PDFCasaBosque;
    if (!P) return;   // sin la base no hay nada que dibujar

    var C = P.C, IZQ = P.IZQ, DER = P.DER, ANCHO = P.ANCHO, HOJA = P.HOJA;

    var COLS = [
        { t: 'Fecha y hora', w: 25 },
        { t: 'Usuario', w: 24 },
        { t: 'Acción', w: 23 },
        { t: 'Módulo', w: 24 },
        { t: 'Grav.', w: 15 },
        { t: 'Detalle · conexión', w: 67 }
    ];
    var PIE_Y = HOJA.alto - 22;      // de aquí para abajo van el pie y la franja
    var INTERLINEA = 3.1;

    var COLOR_GRAVEDAD = { INFO: C.gris, WARNING: C.ambar, CRITICAL: C.rojo };

    /** «01/11/2026 12:10:05 (-05)» -> «01/11/26 12:10:05», que cabe en la columna. */
    function fechaCorta(f) {
        var m = /^(\d{2})\/(\d{2})\/\d{2}(\d{2}) (\d{2}:\d{2}:\d{2})/.exec(String(f || ''));
        return m ? m[1] + '/' + m[2] + '/' + m[3] + ' ' + m[4] : String(f || '');
    }

    /** «campo: antes -> después», solo de lo que cambió. */
    function resumenCambio(fila) {
        var a = fila.antes || {}, d = fila.despues || {};
        var claves = Object.keys(d);
        if (!claves.length) return '';
        var partes = [];
        claves.forEach(function (k) {
            var antes = (k in a) ? String(a[k]) : null;
            var despues = String(d[k]);
            if (antes === null) partes.push(k + ': ' + despues);
            else if (antes !== despues) partes.push(k + ': ' + antes + ' -> ' + despues);
        });
        return partes.length ? 'Cambio · ' + partes.join('; ') : '';
    }

    function cabeceraTabla(doc, y) {
        P.rellenar(doc, C.grisClaro, IZQ, y, ANCHO, 6.4);
        P.fijarTexto(doc, C.corteza, 7.4, 'bold');
        var x = IZQ;
        COLS.forEach(function (c) { doc.text(c.t, x + 1.6, y + 4.4); x += c.w; });
        return y + 6.4;
    }

    /** Hoja nueva: solo la barra superior y una línea con el folio; devuelve la Y donde seguir. */
    function hojaNueva(doc, d) {
        doc.addPage();
        P.rellenar(doc, C.corteza, 0, 0, HOJA.ancho, 4.5);
        P.fijarTexto(doc, C.gris, 7, 'normal');
        doc.text('Casa Bosque · Pista de auditoría · ' + d.folio, IZQ, 11);
        return cabeceraTabla(doc, 15);
    }

    function pie(doc, d, pagina, total) {
        P.fijarTexto(doc, C.gris, 6.2, 'normal');
        doc.text('Folio ' + d.folio + ' · generado ' + d.generado, IZQ, HOJA.alto - 14);
        doc.text('Sello SHA-256: ' + d.sello.slice(0, 32) + '…', IZQ, HOJA.alto - 10.6);
        doc.text('Hoja ' + pagina + ' de ' + total, DER, HOJA.alto - 14, { align: 'right' });
        P.rellenar(doc, C.corteza, 0, HOJA.alto - 7, HOJA.ancho, 7);
        doc.setFillColor(C.oro[0], C.oro[1], C.oro[2]);
        doc.rect(0, HOJA.alto - 8.2, HOJA.ancho, 1.2, 'F');
    }

    function dibujarFila(doc, y, f, par) {
        var detalle = f.detalle;
        var cambio = resumenCambio(f);
        if (cambio) detalle += '\n' + cambio;
        var conexion = [f.ip, f.agente].filter(Boolean).join(' · ');
        if (conexion) detalle += '\n' + conexion;

        P.fijarTexto(doc, C.tinta, 6.8, 'normal');
        var lDet = doc.splitTextToSize(detalle, COLS[5].w - 3);
        var lUsu = doc.splitTextToSize(f.usuario, COLS[1].w - 3);
        var lAcc = doc.splitTextToSize(f.accion, COLS[2].w - 3);
        var lMod = doc.splitTextToSize(f.modulo, COLS[3].w - 3);
        var n = Math.max(lDet.length, lUsu.length, lAcc.length, lMod.length, 1);
        var alto = n * INTERLINEA + 2.4;

        if (par) P.rellenar(doc, [250, 248, 244], IZQ, y, ANCHO, alto);
        var x = IZQ, ty = y + 3.6;
        P.fijarTexto(doc, C.tinta, 6.8, 'normal');
        doc.text(fechaCorta(f.fecha_vista), x + 1.6, ty);                  x += COLS[0].w;
        doc.text(lUsu, x + 1.6, ty);                                       x += COLS[1].w;
        doc.text(lAcc, x + 1.6, ty);                                       x += COLS[2].w;
        doc.text(lMod, x + 1.6, ty);                                       x += COLS[3].w;
        P.fijarTexto(doc, COLOR_GRAVEDAD[f.severidad] || C.gris, 6.8, 'bold');
        doc.text(f.severidad, x + 1.6, ty);                                x += COLS[4].w;
        P.fijarTexto(doc, C.tinta, 6.8, 'normal');
        doc.text(lDet, x + 1.6, ty);
        P.filete(doc, C.linea, IZQ, y + alto, DER, 0.1);
        return alto;
    }

    /**
     * @param d  lo que entrega modules/auditoria/exportar.php?formato=json:
     *           hotel, folio, sello, generado, responsable, periodo, filtros,
     *           total, mostradas, filas[]
     */
    function descargar(d) {
        var jsPDF = (global.jspdf && global.jspdf.jsPDF) || global.jsPDF;
        if (!jsPDF) throw new Error('jsPDF no está cargado.');
        var doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
        doc.setProperties({ title: 'Pista de auditoría — ' + d.folio, author: d.hotel || 'Casa Bosque' });

        // Primera hoja: cabecera completa.
        P.rellenar(doc, C.corteza, 0, 0, HOJA.ancho, 4.5);
        P.fijarTexto(doc, C.corteza, 11, 'bold');
        doc.text(d.hotel || 'Casa Bosque', IZQ, 14);
        P.fijarTexto(doc, C.gris, 7.2, 'normal');
        doc.text('Sistema de Gestión Hotelera', IZQ, 18);
        P.fijarTexto(doc, C.bronce, 22, 'bold');
        doc.text('Pista de auditoría', IZQ, 33);
        P.fijarTexto(doc, C.gris, 8.2, 'normal');
        doc.text('Registro de acciones del sistema. Solo lectura: los registros no se pueden editar ni borrar.', IZQ, 39);

        var y = 45;
        P.rellenar(doc, [238, 235, 230], IZQ, y, ANCHO, 13);
        [['Periodo', d.periodo], ['Generado por', d.responsable], ['Registros', d.mostradas + ' de ' + d.total]].forEach(function (par, i) {
            var x = IZQ + i * (ANCHO / 3) + 3;
            P.fijarTexto(doc, C.gris, 6.2, 'bold');
            doc.text(par[0].toUpperCase(), x, y + 5);
            P.fijarTexto(doc, C.corteza, 8.2, 'bold');
            doc.text(P.recortar(doc, String(par[1]), ANCHO / 3 - 6), x, y + 10);
        });
        y += 13 + 5;

        P.fijarTexto(doc, C.tinta, 7.4, 'bold');
        doc.text('Folio: ' + d.folio, IZQ, y);                       y += 4.4;
        P.fijarTexto(doc, C.tinta, 7, 'normal');
        doc.text('Sello de integridad (SHA-256):', IZQ, y);          y += 3.8;
        P.fijarTexto(doc, C.tinta, 7, 'normal', 'courier');
        doc.text(d.sello, IZQ, y);                                   y += 4.4;
        y = P.parrafo(doc, y, 'El sello se calculó sobre los registros de esta lista, en orden de número. Quien reciba este documento puede '
            + 'volver a calcularlo para comprobar que no se alteró. No es una firma digital con certificado: no identifica a quien la emitió.',
            { tam: 6.8, interlinea: 3.2 }) + 1;
        if (d.filtros) {
            y = P.parrafo(doc, y, 'Filtros: ' + d.filtros, { tam: 6.8, interlinea: 3.2, color: C.corteza }) + 1;
        }
        if (d.mostradas < d.total) {
            y = P.parrafo(doc, y, 'Atención: la lista trae los ' + d.mostradas + ' registros más recientes de ' + d.total + '. Acote el rango de fechas para ver el resto.',
                { tam: 7, interlinea: 3.2, color: C.ambar, estilo: 'bold' }) + 1;
        }

        y = cabeceraTabla(doc, y + 2);
        if (!d.filas.length) {
            P.fijarTexto(doc, C.gris, 8, 'italic');
            doc.text('No hay registros para estos filtros.', IZQ + 2, y + 5);
        }
        d.filas.forEach(function (f, i) {
            // Se mide antes de dibujar: si la fila no cabe, hoja nueva.
            P.fijarTexto(doc, C.tinta, 6.8, 'normal');
            var detalle = f.detalle + (resumenCambio(f) ? '\n' + resumenCambio(f) : '') + ([f.ip, f.agente].filter(Boolean).length ? '\n-' : '');
            var n = Math.max(doc.splitTextToSize(detalle, COLS[5].w - 3).length, 1);
            if (y + n * INTERLINEA + 2.4 > PIE_Y) y = hojaNueva(doc, d);
            y += dibujarFila(doc, y, f, i % 2 === 1);
        });

        var total = doc.getNumberOfPages();
        for (var p = 1; p <= total; p++) { doc.setPage(p); pie(doc, d, p, total); }

        doc.save('auditoria_casa_bosque_' + d.folio + '.pdf');
    }

    global.AuditoriaPDF = { descargar: descargar };
})(window);
