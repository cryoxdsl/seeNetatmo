<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();

function backup_escape_ident(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function backup_row_value_sql(PDO $pdo, mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    return $pdo->quote((string) $value);
}

function backup_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function backup_table_create_sql(PDO $pdo, string $table): string
{
    $q = 'SHOW CREATE TABLE ' . backup_escape_ident($table);
    $row = $pdo->query($q)->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Missing CREATE TABLE for ' . $table);
    }

    $create = '';
    foreach ($row as $k => $v) {
        if (stripos((string) $k, 'Create Table') !== false) {
            $create = (string) $v;
            break;
        }
    }
    if ($create === '') {
        $vals = array_values($row);
        $create = (string) ($vals[1] ?? '');
    }
    if ($create === '') {
        throw new RuntimeException('Invalid CREATE TABLE for ' . $table);
    }

    return "DROP TABLE IF EXISTS " . backup_escape_ident($table) . ";\n" . $create . ";\n\n";
}

function backup_dump_table(PDO $pdo, string $table): void
{
    echo "-- ----------------------------------------\n";
    echo '-- Table: ' . $table . "\n";
    echo "-- ----------------------------------------\n";
    echo backup_table_create_sql($pdo, $table);

    $colStmt = $pdo->query('SHOW COLUMNS FROM ' . backup_escape_ident($table));
    $cols = [];
    foreach ($colStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $cols[] = (string) ($c['Field'] ?? '');
    }
    $cols = array_values(array_filter($cols, static fn($c) => $c !== ''));
    if (!$cols) {
        return;
    }

    $quotedCols = array_map(static fn($c) => backup_escape_ident($c), $cols);
    $selectSql = 'SELECT * FROM ' . backup_escape_ident($table);
    $rows = $pdo->query($selectSql);

    $batch = [];
    $batchSize = 250;
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = backup_row_value_sql($pdo, $row[$c] ?? null);
        }
        $batch[] = '(' . implode(',', $vals) . ')';

        if (count($batch) >= $batchSize) {
            echo 'INSERT INTO ' . backup_escape_ident($table) . ' (' . implode(',', $quotedCols) . ') VALUES\n';
            echo implode(",\n", $batch) . ";\n";
            $batch = [];
        }
    }

    if ($batch) {
        echo 'INSERT INTO ' . backup_escape_ident($table) . ' (' . implode(',', $quotedCols) . ') VALUES\n';
        echo implode(",\n", $batch) . ";\n";
    }
    echo "\n";
}

function backup_list_base_tables(PDO $pdo): array
{
    $stmt = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME ASC");
    return array_map(static fn(array $r): string => (string) $r['TABLE_NAME'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function backup_stream_sql(string $filename, callable $writer): never
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    @set_time_limit(0);
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $ts = (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format('Y-m-d H:i:s');
    echo "-- Generated at {$ts} (" . APP_TIMEZONE . ")\n";
    echo "SET NAMES utf8mb4;\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    $writer();

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    exit;
}

$pdo = db();
$msg = '';
$err = '';
$hasAlldata = backup_table_exists($pdo, 'alldata');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'export_alldata') {
            if (!$hasAlldata) {
                throw new RuntimeException(t('backup.table_missing'));
            }
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stamp = date('Ymd_His');
            $filename = ($dbName !== '' ? $dbName . '_' : '') . 'alldata_' . $stamp . '.sql';
            backup_stream_sql($filename, static function () use ($pdo): void {
                backup_dump_table($pdo, 'alldata');
            });
        }

        if ($action === 'export_database') {
            $tables = backup_list_base_tables($pdo);
            $dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            $stamp = date('Ymd_His');
            $filename = ($dbName !== '' ? $dbName : 'database') . '_full_' . $stamp . '.sql';
            backup_stream_sql($filename, static function () use ($pdo, $tables): void {
                foreach ($tables as $table) {
                    backup_dump_table($pdo, $table);
                }
            });
        }
    } catch (Throwable $e) {
        $err = t('backup.error') . ': ' . $e->getMessage();
    }
}

admin_header(t('backup.title'));
?>
<h2><?= h(t('backup.title')) ?></h2>
<p><?= h(t('backup.subtitle')) ?></p>
<?php if ($msg): ?><div class="alert alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-bad"><?= h($err) ?></div><?php endif; ?>

<div class="panel">
  <h3><?= h(t('backup.alldata_title')) ?></h3>
  <p class="small-muted"><?= h(t('backup.alldata_desc')) ?></p>
  <?php if (!$hasAlldata): ?>
    <p class="alert alert-bad"><?= h(t('backup.table_missing')) ?></p>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="action" value="export_alldata">
      <button type="submit"><?= h(t('backup.download_alldata')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <h3><?= h(t('backup.db_title')) ?></h3>
  <p class="small-muted"><?= h(t('backup.db_desc')) ?></p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="export_database">
    <button type="submit"><?= h(t('backup.download_db')) ?></button>
  </form>
</div>
<?php admin_footer();
