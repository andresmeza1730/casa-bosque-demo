<?php
// ============================================================
// includes/retencion.php
// Borrado seguro (crypto-shredding) y retención — Casa Bosque
// ============================================================
// El "borrado seguro" de datos personales se implementa como
// crypto-shredding: se SOBRESCRIBEN los campos personales (incluidos
// los cifrados). Al reescribir el texto cifrado, el ciphertext anterior
// desaparece y el dato original queda irrecuperable, incluso teniendo
// la clave. Se conservan los registros transaccionales (estancias,
// reservas) por la retención legal, pero sin identidad del titular.
// ------------------------------------------------------------

require_once __DIR__ . '/cifrado.php';

/**
 * Anonimiza (borrado seguro) los datos personales de un huésped.
 * Devuelve true si se anonimizó, false si no existía o ya estaba
 * anonimizado. Deja rastro en auditoría (si se pasa un usuario válido
 * vía las funciones de auth, que ya usa registrarAuditoria).
 */
/**
 * ¿Está hospedado ahora (una estancia activa)? (4.3.2) El borrado seguro no procede mientras dure la estadía: el check-out, el folio y la factura
 * necesitan su identidad, y quedaría «ANONIMIZADO» en medio de la cuenta. Primero el check-out.
 */
function huespedAlojadoAhora(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("select count(*) from estancias where huesped_id = ? and estado = 'activa'");
    $st->execute([$id]);
    return (int) $st->fetchColumn() > 0;
}

function anonimizarHuesped(PDO $pdo, int $id): bool {
    // Token único e irreversible en lugar del documento real. Se cifra
    // igual (para mantener el formato de la columna) pero no identifica.
    $token = 'ANON-' . $id . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

    $stmt = $pdo->prepare("
        update huespedes
           set documento_num  = ?,
               documento_hash = null,
               nombres        = 'ANONIMIZADO',
               apellidos      = '',
               telefono       = null,
               email          = null,
               ciudad_origen  = null,
               nacionalidad   = 'N/D',
               anonimizado_en = now()
         where id = ? and anonimizado_en is null
    ");
    $stmt->execute([cifrar($token), $id]);
    return $stmt->rowCount() > 0;
}

/**
 * Fecha de corte de retención: hoy menos 'anios' de la política.
 */
function fechaCorteRetencion(): string {
    $cfg = require __DIR__ . '/../config/retencion.php';
    $anios = (int)($cfg['anios'] ?? 5);
    return (new DateTime())->modify("-{$anios} years")->format('Y-m-d H:i:s');
}

/**
 * Huéspedes cuyos datos superan el periodo de retención y aún no han
 * sido anonimizados. Se basa en la última actividad: último check-out
 * o, si no tiene estancias, su fecha de registro. Excluye huéspedes con
 * una estancia activa en curso.
 */
function huespedesParaRetencion(PDO $pdo): array {
    $corte = fechaCorteRetencion();
    // Se evita HAVING sin GROUP BY (no soportado en algunas configuraciones):
    // la condición de retención va en el WHERE con la subconsulta escalar.
    $stmt = $pdo->prepare("
        select hu.id, hu.documento_tipo, hu.documento_num, hu.nombres, hu.apellidos, hu.created_at,
               (select max(e.fecha_checkout) from estancias e where e.huesped_id = hu.id) as ultima_salida
        from huespedes hu
        where hu.anonimizado_en is null
          and not exists (select 1 from estancias e2 where e2.huesped_id = hu.id and e2.estado = 'activa')
          and coalesce(
                (select max(e3.fecha_checkout) from estancias e3 where e3.huesped_id = hu.id),
                hu.created_at
              ) < ?
        order by ultima_salida asc
    ");
    $stmt->execute([$corte]);
    return $stmt->fetchAll();
}
