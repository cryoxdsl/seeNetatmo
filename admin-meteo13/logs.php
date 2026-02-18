<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/admin_ui.php';

admin_require_login();
$rows = db()->query('SELECT id,level,channel,message,context_json,created_at FROM app_logs ORDER BY id DESC LIMIT 300')->fetchAll();

admin_header('Logs');
?>
<h2>Application logs</h2>
<div class="panel table-wrap">
<table>
<thead><tr><th>ID</th><th>Level</th><th>Channel</th><th>Message</th><th>Context</th><th>Date</th></tr></thead>
<tbody><?php foreach($rows as $r): ?><tr>
<td><?= (int)$r['id'] ?></td><td><?= h($r['level']) ?></td><td><?= h($r['channel']) ?></td><td><?= h($r['message']) ?></td><td class="code"><?= h($r['context_json']) ?></td><td><?= h($r['created_at']) ?></td>
</tr><?php endforeach; ?></tbody>
</table>
</div>
<?php admin_footer();
