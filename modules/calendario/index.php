<?php
// ============================================================
// modules/calendario/index.php
// Calendario de ocupación — Casa Bosque
// ============================================================
// Una semana por pantalla (lunes a domingo): los días arriba y una fila
// por habitación abajo, con siete celdas grandes.
//   L  libre      R  reservada (azul)      O  ocupada (marrón)
// Al tocar una celda sale una tarjeta con el huésped y las fechas
// (assets/js/calendario.js). Solo lectura.
//
// Hasta 3.10.1 era el mes entero: 28 a 31 celdas por habitación que a 375 px
// medían 8 px, no se podían tocar, y el pie prometía «toca un segmento para
// ver el huésped» sin que nada ocurriera. Los datos salen de
// includes/calendario_semana.php.
// ------------------------------------------------------------

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/calendario_semana.php';
requireLogin();

$pdo = getDB();

$hoy   = new DateTimeImmutable('today');
$lunes = calendarioLunes($_GET['semana'] ?? null, $hoy);
$sem   = calendarioSemana($pdo, $lunes, $hoy);

$semAnt = $lunes->modify('-7 days')->format('Y-m-d');
$semSig = $lunes->modify('+7 days')->format('Y-m-d');
$semHoy = calendarioLunes(null, $hoy)->format('Y-m-d');

$esc = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');

$estados = [
    'libre'     => ['L', 'Libre'],
    'reservada' => ['R', 'Reservada'],
    'ocupada'   => ['O', 'Ocupada'],
];

$currentDir = 'calendario';
$currentPage = 'calendario';
$pageTitle = 'Calendario';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ============================================================
   Calendario — semana, celdas grandes (Casa Bosque)
   Los días y las celdas comparten --lbl, --gap y --pad: así los números
   de arriba caen justo sobre su columna en móvil y en escritorio.
   ============================================================ */
.cal-wrap { --lbl: 0px; --gap: 0px; --pad: 12px; max-width: 960px; padding-bottom: 24px; }
@media (min-width: 481px) { .cal-wrap { --lbl: 120px; --gap: 12px; } }
@media (max-width: 360px) { .cal-wrap { --pad: 8px; } }

.cal-head { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 18px; box-shadow: 0 6px 18px rgba(42,23,9,.05); padding: 16px; margin-bottom: 16px; }
.cal-title { font-size: 1.05rem; font-weight: 800; color: var(--cb-forest,#3E2412); text-align: center; margin-bottom: 14px; }
.cal-nav { display: flex; gap: 8px; }
.cal-nav a { flex: 1; text-align: center; font-size: .82rem; font-weight: 700; padding: 12px 10px; border-radius: 11px; text-decoration: none; border: 1px solid var(--cb-border,#EDE3D2); background: #fff; color: var(--cb-forest,#3E2412); }
.cal-nav a.now { background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); color: #fff; border: none; }
.cal-legend { display: flex; flex-wrap: wrap; justify-content: center; gap: 8px 18px; margin-top: 14px; }
.cal-legend span { font-size: .78rem; color: var(--cb-muted,#9A8778); display: inline-flex; align-items: center; gap: 7px; }
.cal-legend i { width: 22px; height: 22px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; font-style: normal; font-weight: 800; font-size: .72rem; }

.cal-libre     { background: #E3F1E8; color: #1F7A4D; }
.cal-reservada { background: #2f6fb0; color: #fff; }
.cal-ocupada   { background: #6B4420; color: #fff; }

/* los días */
.cal-ruler { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; margin-bottom: 6px;
             padding: 0 calc(1px + var(--pad)) 0 calc(1px + var(--pad) + var(--lbl) + var(--gap)); }
.cal-ruler span { text-align: center; font-size: .7rem; font-weight: 700; color: var(--cb-muted,#9A8778); line-height: 1.15; padding: 4px 0; border-radius: 8px; }
.cal-ruler b { display: block; font-size: .98rem; color: var(--cb-forest,#3E2412); }
.cal-ruler span.hoy { background: #fef3c7; }

/* una habitación: nombre arriba en móvil, a la izquierda en pantallas anchas */
.cal-room { background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 13px; padding: 10px var(--pad); margin-bottom: 8px; display: flex; flex-direction: column; gap: 8px; box-shadow: 0 3px 10px rgba(42,23,9,.04); }
.cal-room-lbl { display: flex; align-items: baseline; gap: 8px; margin: 0; }
.cal-room-lbl .num { font-size: 1rem; font-weight: 800; color: var(--cb-forest,#3E2412); }
.cal-room-lbl .tipo { font-size: .76rem; font-weight: 400; color: var(--cb-muted,#9A8778); }
@media (min-width: 481px) {
    .cal-room { flex-direction: row; align-items: center; gap: var(--gap); }
    .cal-room-lbl { flex: none; width: var(--lbl); flex-direction: column; align-items: flex-start; gap: 2px; }
}

.cal-days { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 4px; flex: 1; min-width: 0; }
.cal-cell { height: 44px; min-width: 0; padding: 0; border: 0; border-radius: 9px; font: inherit; font-weight: 800; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; }
.cal-cell.hoy { box-shadow: inset 0 0 0 2px #f59e0b; }
.cal-cell:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }

.cal-foot { font-size: .78rem; color: var(--cb-muted,#9A8778); margin-top: 12px; line-height: 1.45; display: flex; gap: 7px; align-items: flex-start; }
.cal-foot i { color: var(--cb-primary,#6B4420); margin-top: 2px; }
.cal-empty { text-align: center; padding: 30px; color: var(--cb-muted,#9A8778); }

/* la tarjeta */
.cal-modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 16px; }
.cal-modal[hidden] { display: none; }
.cal-modal-bg { position: absolute; inset: 0; background: rgba(62,36,18,.55); }
.cal-card { position: relative; width: 100%; max-width: 360px; background: #fff; border-radius: 18px; padding: 20px 18px 18px; box-shadow: 0 20px 50px rgba(42,23,9,.35); }
.cal-card:focus { outline: none; }
.cal-card-x { position: absolute; top: 8px; right: 8px; width: 44px; height: 44px; border: 0; background: transparent; font-size: 1.6rem; line-height: 1; color: var(--cb-muted,#9A8778); cursor: pointer; border-radius: 12px; }
.cal-card-fecha { font-size: .78rem; font-weight: 700; color: var(--cb-muted,#9A8778); text-transform: uppercase; letter-spacing: .04em; padding-right: 40px; }
.cal-card h2 { margin: 4px 0 10px; font-size: 1.15rem; font-weight: 800; color: var(--cb-forest,#3E2412); padding-right: 40px; }
.cal-card-estado { display: inline-block; padding: 5px 12px; border-radius: 999px; font-size: .8rem; font-weight: 800; margin-bottom: 14px; }
.cal-card dl { margin: 0 0 14px; display: grid; grid-template-columns: auto 1fr; gap: 6px 14px; font-size: .92rem; }
.cal-card dl[hidden] { display: none; }
.cal-card dt { color: var(--cb-muted,#9A8778); }
.cal-card dd { margin: 0; font-weight: 700; color: var(--cb-forest,#3E2412); overflow-wrap: anywhere; }
.cal-card-libre { margin: 0 0 14px; font-size: .92rem; color: var(--cb-muted,#9A8778); }
.cal-card-cerrar { width: 100%; height: 46px; border: 0; border-radius: 12px; font: inherit; font-weight: 800; color: #fff; cursor: pointer; background: linear-gradient(150deg,var(--cb-primary,#6B4420),var(--cb-secondary,#9C6728)); }
.cal-card button:focus-visible { outline: 3px solid #f59e0b; outline-offset: 2px; }
</style>

<div class="cal-wrap" id="cal">

<div class="cal-head">
    <div class="cal-title"><?= $esc(calendarioTituloSemana($lunes)) ?></div>
    <div class="cal-nav">
        <a href="?semana=<?= $esc($semAnt) ?>"><i class="fas fa-chevron-left"></i> Anterior</a>
        <a href="?semana=<?= $esc($semHoy) ?>" class="now">Hoy</a>
        <a href="?semana=<?= $esc($semSig) ?>">Siguiente <i class="fas fa-chevron-right"></i></a>
    </div>
    <div class="cal-legend">
        <?php foreach ($estados as $clave => [$letra, $nombre]): ?>
            <span><i class="cal-<?= $clave ?>"><?= $letra ?></i> <?= $nombre ?></span>
        <?php endforeach; ?>
    </div>
</div>

<?php if (empty($sem['habitaciones'])): ?>
    <div class="cal-empty">No hay habitaciones registradas.</div>
<?php else: ?>
    <div class="cal-ruler">
        <?php foreach ($sem['dias'] as $d): ?>
            <span<?= $d['hoy'] ? ' class="hoy"' : '' ?>><?= $d['letra'] ?><b><?= $d['num'] ?></b></span>
        <?php endforeach; ?>
    </div>

    <?php foreach ($sem['habitaciones'] as $h): ?>
        <section class="cal-room">
            <h2 class="cal-room-lbl"><span class="num">Hab. <?= $esc($h['numero']) ?></span> <span class="tipo"><?= $esc($h['tipo']) ?></span></h2>
            <div class="cal-days">
                <?php foreach ($h['celdas'] as $i => $c):
                    $dia = $sem['dias'][$i];
                    [$letra, $nombreEstado] = $estados[$c['estado']];
                    $etiqueta = 'Hab. ' . $h['numero'] . ', ' . $dia['texto'] . ': ' . $nombreEstado
                              . ($c['huesped'] !== '' ? ', ' . $c['huesped'] : '');
                ?>
                    <button type="button" class="cal-cell cal-<?= $c['estado'] ?><?= $dia['hoy'] ? ' hoy' : '' ?>"
                            data-hab="<?= $esc($h['numero']) ?>" data-tipo="<?= $esc($h['tipo']) ?>"
                            data-fecha="<?= $esc($dia['texto']) ?>" data-estado="<?= $c['estado'] ?>"
                            data-estado-txt="<?= $esc($nombreEstado) ?>" data-huesped="<?= $esc($c['huesped']) ?>"
                            data-entrada="<?= $esc($c['entrada']) ?>" data-salida="<?= $esc($c['salida']) ?>"
                            aria-label="<?= $esc($etiqueta) ?>" title="<?= $esc($etiqueta) ?>"><?= $letra ?></button>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>

    <div class="cal-foot">
        <i class="fas fa-circle-info"></i>
        <span>Toca una celda para ver quién la tiene y desde cuándo hasta cuándo. La noche de salida no se cuenta como ocupada; el borde naranja marca el día de hoy. Una habitación con huésped dentro sin salida pactada se ve ocupada solo hasta hoy.</span>
    </div>
<?php endif; ?>

</div><!-- /.cal-wrap -->

<div class="cal-modal" id="cal-modal" hidden>
    <div class="cal-modal-bg" data-cal-close></div>
    <div class="cal-card" role="dialog" aria-modal="true" aria-labelledby="cal-card-titulo" tabindex="-1">
        <button type="button" class="cal-card-x" data-cal-close aria-label="Cerrar">&times;</button>
        <div class="cal-card-fecha" id="cal-card-fecha"></div>
        <h2 id="cal-card-titulo"></h2>
        <span class="cal-card-estado" id="cal-card-estado"></span>
        <dl id="cal-card-datos">
            <dt>Huésped</dt><dd id="cal-card-huesped"></dd>
            <dt>Entrada</dt><dd id="cal-card-entrada"></dd>
            <dt>Salida</dt><dd id="cal-card-salida"></dd>
        </dl>
        <p class="cal-card-libre" id="cal-card-libre" hidden>Nadie tiene esta habitación ese día.</p>
        <button type="button" class="cal-card-cerrar" data-cal-close>Cerrar</button>
    </div>
</div>

<script src="<?= BASE_URL ?>/assets/js/calendario.js?v=<?= APP_VERSION ?>"></script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
