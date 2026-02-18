<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/admin.php';

enforce_admin_suffix_url();
auth_logout();
header('Location: login.php');
exit;
