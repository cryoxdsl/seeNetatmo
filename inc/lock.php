<?php
declare(strict_types=1);

function lock_acquire(string $name)
{
    $path = __DIR__ . '/../logs/' . preg_replace('/[^a-z0-9_-]/i', '_', $name) . '.lock';
    $fp = fopen($path, 'c+');
    if (!$fp) {
        throw new RuntimeException('Cannot open lock file');
    }
    if (!flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return null;
    }
    ftruncate($fp, 0);
    fwrite($fp, (string) getmypid());
    return $fp;
}

function lock_release($fp): void
{
    if (is_resource($fp)) {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
