<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';

enforce_admin_suffix_url();
auth_require_login();

$stmt = db()->query('SELECT id, level, channel, message, context_json, created_at FROM app_logs ORDER BY id DESC LIMIT 200');
$logs = $stmt->fetchAll();

admin_header('Logs');
?>
<h2>Application logs</h2>
<div class="panel table-wrap">
    <table>
        <thead><tr><th>ID</th><th>Level</th><th>Channel</th><th>Message</th><th>Context</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
            <tr>
                <td><?= (int) $log['id'] ?></td>
                <td><?= h($log['level']) ?></td>
                <td><?= h($log['channel']) ?></td>
                <td><?= h($log['message']) ?></td>
                <td><code><?= h((string) $log['context_json']) ?></code></td>
                <td><?= h($log['created_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
admin_footer();
