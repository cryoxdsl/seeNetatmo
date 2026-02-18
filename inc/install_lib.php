<?php
declare(strict_types=1);

require_once __DIR__ . '/totp.php';

function install_steps(): array
{
    return [
        1 => 'Environment',
        2 => 'Database',
        3 => 'Admin',
        4 => 'Netatmo',
        5 => 'Finish',
    ];
}

function install_write_file(string $path, string $content): void
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write file: ' . $path);
    }
}

function install_create_admin_folder(string $suffix): void
{
    $target = __DIR__ . '/../admin-' . $suffix;
    $template = __DIR__ . '/../admin-template';

    if (!is_dir($template)) {
        throw new RuntimeException('admin-template directory missing.');
    }

    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Cannot create admin directory.');
    }

    $files = glob($template . '/*.php') ?: [];
    foreach ($files as $file) {
        $dest = $target . '/' . basename($file);
        if (!copy($file, $dest)) {
            throw new RuntimeException('Cannot copy admin file: ' . basename($file));
        }
    }

    $htaccess = "Options -Indexes\n<FilesMatch \"^\\.\">\n  Require all denied\n</FilesMatch>\n";
    file_put_contents($target . '/.htaccess', $htaccess);
}
