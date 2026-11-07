<?php
// ============================================================
// includes/deuda_modal.php
// La tarjeta «Registrar pago de deuda» — Casa Bosque (4.4.0)
// ============================================================
// UNA sola tarjeta para las dos pantallas que cobran deudas (el inicio del
// administrador, dashboard.php, y el panel de Deudores). Antes vivía dentro de
// dashboard.php; con dos copias, un arreglo de la tarjeta (centrarla, el
// monto entero) habría que hacerlo dos veces.
//
// Quien la incluye pone ANTES `$deudaModalAction`: la URL a la que se manda el
// formulario. La abre `abrirPago(estanciaId, nombre, saldo)` y la cierra
// `cerrarPago()`. Se imprime solo si quien mira es administrador: el permiso de
// cobrar se comprueba en el servidor, esto solo evita ofrecer un botón que no
// va a funcionar.
// ------------------------------------------------------------
$deudaModalAction = $deudaModalAction ?? '';
?>
<!-- Modal: registrar pago de una deuda -->
<div id="pago-modal" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.4);backdrop-filter:blur(5px);align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;width:100%;max-width:400px;border-radius:18px;overflow:hidden;box-shadow:0 20px 40px rgba(0,0,0,.2);">
        <div style="padding:16px 20px;background:linear-gradient(150deg,#fdecec,#fff);border-bottom:1px solid #f4caca;">
            <div style="font-weight:800;color:#b91c1c;display:flex;align-items:center;gap:8px;"><i class="fas fa-hand-holding-dollar"></i> Registrar pago de deuda</div>
            <div id="pago-nombre" style="font-size:.82rem;color:#6E6054;margin-top:3px;"></div>
        </div>
        <form method="POST" action="<?= htmlspecialchars($deudaModalAction, ENT_QUOTES) ?>" style="padding:18px 20px 20px;">
            <?= csrfField() ?>
            <input type="hidden" name="accion" value="pagar_deuda">
            <input type="hidden" name="estancia_id" id="pago-estancia">
            <div style="font-size:.85rem;color:#6E6054;margin-bottom:12px;">Debe <b id="pago-saldo-txt" style="color:#c0392b;"></b>. Puedes cobrar el total o un abono.</div>
            <label style="display:block;font-size:.74rem;font-weight:600;color:#6E6054;margin-bottom:6px;">Monto a registrar (COP)</label>
            <input type="text" inputmode="numeric" autocomplete="off" name="monto" id="pago-monto" min="1" data-solo="moneda" required style="width:100%;padding:12px 13px;border:1px solid #E7E2D8;border-radius:11px;font-family:inherit;font-size:1rem;margin-bottom:14px;box-sizing:border-box;">
            <label style="display:block;font-size:.74rem;font-weight:600;color:#6E6054;margin-bottom:8px;">Método</label>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:18px;">
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="efectivo" checked style="accent-color:#6B4420;"> Efectivo</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="tarjeta" style="accent-color:#6B4420;"> Tarjeta</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="transferencia" style="accent-color:#6B4420;"> Transfer.</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="nequi" style="accent-color:#6B4420;"> Nequi</label>
                <label style="display:flex;align-items:center;justify-content:center;gap:7px;padding:11px;border:1px solid #E7E2D8;border-radius:11px;font-size:.85rem;font-weight:600;color:#334155;cursor:pointer;"><input type="radio" name="metodo" value="otro" style="accent-color:#6B4420;"> Otro</label>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" onclick="cerrarPago()" style="padding:11px 16px;border-radius:11px;border:1px solid #E7E2D8;background:#fff;color:#475569;font-weight:600;cursor:pointer;font-family:inherit;">Cancelar</button>
                <button type="submit" style="padding:11px 18px;border-radius:11px;border:none;background:#6B4420;color:#fff;font-weight:700;cursor:pointer;font-family:inherit;">Registrar pago</button>
            </div>
        </form>
    </div>
</div>
<script>
function abrirPago(id, nombre, saldo){
    document.getElementById('pago-estancia').value = id;
    document.getElementById('pago-nombre').textContent = nombre;
    document.getElementById('pago-saldo-txt').textContent = '$' + Number(saldo).toLocaleString('es-CO');
    var m = document.getElementById('pago-monto'); m.max = saldo; CBDinero.poner(m, saldo);   // el monto lleva sus puntos de miles (campos-numericos.js)
    document.getElementById('pago-modal').style.display = 'flex';
}
function cerrarPago(){ document.getElementById('pago-modal').style.display = 'none'; }
(function(){ var ov = document.getElementById('pago-modal'); if(ov) ov.addEventListener('click', function(e){ if(e.target === ov) cerrarPago(); }); })();
</script>
