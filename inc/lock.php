<?php
declare(strict_types=1);

function acquire_lock(string $name)
{
    $path = __DIR__ . '/../storage/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name) . '.lock';
    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Cannot open lock file.');
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    ftruncate($handle, 0);
    fwrite($handle, (string) getmypid());
    return $handle;
}

function release_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
