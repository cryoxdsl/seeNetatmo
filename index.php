<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/bootstrap.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/view.php';
require_once __DIR__ . '/inc/data.php';

if (!app_is_installed()) {
    header('Location: /install/index.php');
    exit;
}

$status = last_update_status();
$latest = fetch_latest_row();

render_header('Dashboard');
?>
<section class="panel">
    <h2>Live dashboard</h2>
    <p>Last update: <strong><?= h((string) ($status['last_update'] ?? 'N/A')) ?></strong></p>
    <p class="badge <?= $status['disconnected'] ? 'badge-danger' : 'badge-ok' ?>">
        <?= $status['disconnected'] ? 'Disconnected' : 'Connected' ?>
    </p>
</section>
<section class="grid">
    <?php
    $cards = [
        'Temperature (C)' => $latest['T'] ?? null,
        'Humidity (%)' => $latest['H'] ?? null,
        'Pressure (hPa)' => $latest['P'] ?? null,
        'Rain 1h (mm)' => $latest['RR'] ?? null,
        'Rain Day (mm)' => $latest['R'] ?? null,
        'Wind Avg (km/h)' => $latest['W'] ?? null,
        'Wind Gust (km/h)' => $latest['G'] ?? null,
        'Wind Dir (deg)' => $latest['B'] ?? null,
        'Dew Point (C)' => $latest['D'] ?? null,
        'Apparent Temp (C)' => $latest['A'] ?? null,
    ];
    foreach ($cards as $label => $value): ?>
        <article class="card">
            <h3><?= h($label) ?></h3>
            <p><?= $value === null ? 'N/A' : h((string) $value) ?></p>
        </article>
    <?php endforeach; ?>
</section>
<?php
render_footer();
