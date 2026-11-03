<?php
// ============================================================
// Módulo: Menú ("Más") — página propia con todos los accesos.
// ------------------------------------------------------------
// Reemplaza el panel flotante "Más" (bottom-sheet) que daba
// problemas de toque/scroll en móvil. Aquí las opciones son
// enlaces normales en una página que se abre de una: al tocar
// cualquiera, navega directo. Sin overlay, sin bloqueo de scroll.
// ============================================================
$pageTitle = 'Menú';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$esAdmin = isAdmin();

// Grupos de accesos según el rol. Cada item: [url, icono, etiqueta, ¿peligro?]
//
// Esta página es la ÚNICA vía móvil a todo lo que no cabe en la barra inferior
// de cinco accesos, así que cada entrada del menú lateral de escritorio tiene
// que estar aquí o en esa barra. Al comparar las dos listas faltaban: para el
// administrador, Calendario, Historial de huéspedes y Arqueo de Caja; para
// recepción, Carta y Restaurante y Seguridad (2FA), que es su propia cuenta.
// Si se añade una entrada al menú lateral, hay que añadirla también aquí.
if ($esAdmin) {
    $grupos = [
        'Recepción' => [
            ['/modules/checkin/index.php',        'fa-door-open',                'Entradas',          false],
            ['/modules/checkout/index.php',       'fa-door-closed',              'Salidas',           false],
            ['/modules/consumos/index.php',       'fa-receipt',                  'Consumos',          false],
            ['/modules/reservas/index.php',       'fa-calendar-check',           'Reservas',          false],
            ['/modules/calendario/index.php',     'fa-calendar-alt',             'Calendario',        false],
        ],
        'El dinero' => [
            ['/modules/huespedes/ficha.php',      'fa-magnifying-glass-dollar', 'Estado de cuenta',  false],
            ['/modules/facturacion/index.php',   'fa-file-invoice-dollar',      'Facturación',       false],
            ['/modules/reportes/caja.php',       'fa-cash-register',            'Arqueo de Caja',    false],
            ['/dashboard.php',                   'fa-chart-pie',                'Resumen',           false],
        ],
        'El hotel' => [
            ['/modules/habitaciones/index.php',   'fa-bed',                     'Habitaciones',      false],
            ['/modules/productos/index.php',      'fa-utensils',                'Carta y Restaurante', false],
            ['/modules/huespedes/index.php',      'fa-users',                   'Huéspedes',         false],
            ['/modules/huespedes/historial.php',  'fa-clock-rotate-left',       'Historial',         false],
        ],
        'El sistema' => [
            ['/modules/usuarios/index.php',       'fa-users-cog',               'Usuarios',           false],
            ['/modules/auditoria/index.php',      'fa-clipboard-list',          'Auditoría',          false],
            ['/modules/reportes/ingresos.php',   'fa-chart-line',              'Ingresos',           false],
            ['/modules/reportes/comprobantes.php','fa-file-invoice',            'Comprobantes',       false],
            ['/modules/reportes/dian.php',       'fa-file-invoice-dollar',     'DIAN',               false],
            ['/modules/reportes/respaldo.php',    'fa-database',                'Respaldo',           false],
            ['/modules/reportes/reiniciar.php',   'fa-triangle-exclamation',    'Reiniciar',          true],
        ],
        'Legal y documentos' => [
            ['/politica-datos.php?volver=menu',     'fa-user-shield',    'Política de datos',   false],
            ['/politica-menores.php?volver=menu',   'fa-child',          'Política de menores', false],
            ['/autorizacion-menor.php?volver=menu', 'fa-file-signature', 'Carta autorización',  false],
        ],
        'Cuenta' => [
            ['/modules/usuarios/seguridad.php',       'fa-shield-halved',       'Seguridad',    false],
            ['/modules/auth/logout.php',              'fa-sign-out-alt',        'Salir',        true],
        ],
    ];
} else {
    $grupos = [
        'Operaciones' => [
            ['/modules/huespedes/ficha.php',   'fa-magnifying-glass-dollar', 'Estado de cuenta', false],
            ['/modules/huespedes/index.php',   'fa-users',                   'Huéspedes',        false],
            ['/modules/huespedes/historial.php','fa-clock-rotate-left',      'Historial',        false],
            ['/modules/reservas/index.php',    'fa-calendar-check',          'Reservas',         false],
            ['/modules/calendario/index.php',  'fa-calendar-alt',            'Calendario',       false],
            ['/modules/productos/index.php',   'fa-utensils',                'Carta y Restaurante', false],
        ],
        'Legal y documentos' => [
            ['/politica-datos.php?volver=menu',     'fa-user-shield',    'Política de datos',   false],
            ['/politica-menores.php?volver=menu',   'fa-child',          'Política de menores', false],
            ['/autorizacion-menor.php?volver=menu', 'fa-file-signature', 'Carta autorización',  false],
        ],
        'Cuenta' => [
            ['/modules/usuarios/seguridad.php', 'fa-shield-halved', 'Seguridad',     false],
            ['/modules/auth/logout.php',        'fa-sign-out-alt',  'Cerrar sesión', true],
        ],
    ];
}

require_once __DIR__ . '/../includes/header.php';
?>
<style>
.mn-wrap { padding-bottom: 96px; }
.mn-group { margin-bottom: 22px; }
.mn-title { font-size: .74rem; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: #A2907C; margin: 0 2px 12px; }
.mn-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
@media (min-width: 560px) { .mn-grid { grid-template-columns: repeat(3, 1fr); } }
.mn-tile {
    display: flex; align-items: center; gap: 13px; text-decoration: none;
    background: #fff; border: 1px solid var(--cb-border,#EDE3D2); border-radius: 15px;
    padding: 16px 15px; box-shadow: 0 4px 12px rgba(42,23,9,.05);
    color: var(--cb-forest,#3E2412); font-size: .92rem; font-weight: 700;
}
.mn-tile:active { transform: scale(.98); background: #f6faf4; }
.mn-ico {
    flex: none; width: 46px; height: 46px; border-radius: 13px;
    background: var(--cb-primary-light,#F5EBDC); color: var(--cb-primary,#6B4420);
    display: flex; align-items: center; justify-content: center; font-size: 1.2rem;
}
.mn-tile.danger { color: #b91c1c; border-color: rgba(220,38,38,.25); }
.mn-tile.danger .mn-ico { background: #fdeaea; color: #dc2626; }
.mn-tile .mn-chev { margin-left: auto; color: #BCA88E; }
</style>

<div class="mn-wrap">
    <?php foreach ($grupos as $titulo => $items): ?>
        <div class="mn-group">
            <div class="mn-title"><?= htmlspecialchars($titulo) ?></div>
            <div class="mn-grid">
                <?php foreach ($items as $it): ?>
                    <a href="<?= BASE_URL . $it[0] ?>" class="mn-tile <?= $it[3] ? 'danger' : '' ?>">
                        <span class="mn-ico"><i class="fas <?= $it[1] ?>"></i></span>
                        <span><?= htmlspecialchars($it[2]) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
