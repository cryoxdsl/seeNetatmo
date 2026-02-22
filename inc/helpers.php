<?php
declare(strict_types=1);

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now_paris(): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE));
}

function floor_5min(DateTimeImmutable $dt): DateTimeImmutable
{
    $minute = (int) $dt->format('i');
    $floored = $minute - ($minute % 5);
    return $dt->setTime((int) $dt->format('H'), $floored, 0);
}

function random_hex(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function random_base32(int $length = 32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
}

function base_url_root(): string
{
    $scheme = is_https() ? 'https' : 'http';
    $rawHost = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $rawHost = str_replace(["\r", "\n"], '', $rawHost);

    $host = '';
    if ($rawHost !== '' && preg_match('/^(?:localhost|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/i', $rawHost) === 1) {
        $host = $rawHost;
    }
    if ($host === '' && function_exists('cfg')) {
        $cfgDomain = trim((string) cfg('domain', ''));
        $cfgDomain = preg_replace('#^https?://#i', '', $cfgDomain) ?? '';
        $cfgDomain = trim($cfgDomain, "/ \t\n\r\0\x0B");
        if ($cfgDomain !== '' && preg_match('/^(?:localhost|(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/i', $cfgDomain) === 1) {
            $host = $cfgDomain;
        }
    }
    if ($host === '') {
        $host = 'localhost';
    }
    return $scheme . '://' . $host;
}

function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $trusted = cfg('trusted_proxy_ips', []);
    if (!is_array($trusted)) {
        $trusted = [];
    }
    $trusted = array_map(static fn($v): string => (string) $v, $trusted);
    $isTrustedProxy = in_array($remote, $trusted, true);

    if ($isTrustedProxy && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if ($isTrustedProxy && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $remote;
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function sanitize_rich_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedTags = ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'blockquote'];
    $allowedTagLookup = array_fill_keys($allowedTags, true);

    if (class_exists('DOMDocument')) {
        $prevUseErrors = libxml_use_internal_errors(true);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $wrapped = '<!DOCTYPE html><html><body><div id="__root__">' . $html . '</div></body></html>';
        $wrapped = '<?xml encoding="UTF-8">' . $wrapped;
        $loaded = $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        if ($loaded) {
            foreach ($doc->childNodes as $child) {
                if ($child instanceof DOMProcessingInstruction && strtolower($child->target) === 'xml') {
                    $doc->removeChild($child);
                    break;
                }
            }
            $xpath = new DOMXPath($doc);
            foreach ($xpath->query('//script|//style|//iframe|//object|//embed|//form|//input|//button|//textarea|//select|//meta|//link|//base') as $n) {
                if ($n->parentNode) {
                    $n->parentNode->removeChild($n);
                }
            }

            foreach ($xpath->query('//*') as $el) {
                if (!($el instanceof DOMElement)) {
                    continue;
                }
                $tag = strtolower($el->tagName);
                if (!isset($allowedTagLookup[$tag]) && $tag !== 'html' && $tag !== 'body' && $tag !== 'div') {
                    $parent = $el->parentNode;
                    if ($parent) {
                        while ($el->firstChild) {
                            $parent->insertBefore($el->firstChild, $el);
                        }
                        $parent->removeChild($el);
                    }
                    continue;
                }

                $toRemove = [];
                foreach ($el->attributes as $attr) {
                    $name = strtolower($attr->name);
                    if (str_starts_with($name, 'on') || $name === 'style') {
                        $toRemove[] = $attr->name;
                        continue;
                    }
                    if ($tag === 'a') {
                        if (!in_array($name, ['href', 'title', 'target', 'rel'], true)) {
                            $toRemove[] = $attr->name;
                        }
                    } elseif (!in_array($tag, ['html', 'body', 'div'], true)) {
                        $toRemove[] = $attr->name;
                    }
                }
                foreach ($toRemove as $name) {
                    $el->removeAttribute($name);
                }

                if ($tag === 'a' && $el->hasAttribute('href')) {
                    $href = trim((string) $el->getAttribute('href'));
                    $ok = str_starts_with($href, 'http://')
                        || str_starts_with($href, 'https://')
                        || str_starts_with($href, 'mailto:')
                        || str_starts_with($href, '/')
                        || str_starts_with($href, '#');
                    if (!$ok) {
                        $el->setAttribute('href', '#');
                    }
                }
                if ($tag === 'a' && strtolower((string) $el->getAttribute('target')) === '_blank') {
                    $el->setAttribute('rel', 'noopener noreferrer');
                }
            }

            $root = $doc->getElementById('__root__');
            if ($root instanceof DOMElement) {
                $out = '';
                foreach ($root->childNodes as $child) {
                    $out .= (string) $doc->saveHTML($child);
                }
                libxml_clear_errors();
                libxml_use_internal_errors($prevUseErrors);
                return trim($out);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($prevUseErrors);
    }

    return strip_tags($html, '<p><br><strong><em><b><i><u><ul><ol><li><a><h2><h3><h4><blockquote>');
}

function app_release_info(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $fallback = 'v' . APP_VERSION;
    $fileTagPath = dirname(__DIR__) . '/config/release_tag.txt';
    if (is_file($fileTagPath)) {
        $fileTag = trim((string) @file_get_contents($fileTagPath));
        if ($fileTag !== '') {
            return $cached = ['tag' => $fileTag, 'source' => 'file'];
        }
    }

    $envTag = trim((string) getenv('APP_RELEASE_TAG'));
    if ($envTag !== '') {
        return $cached = ['tag' => $envTag, 'source' => 'env'];
    }

    if (!function_exists('shell_exec')) {
        return $cached = ['tag' => $fallback, 'source' => 'fallback'];
    }

    $root = dirname(__DIR__);
    if (!is_dir($root . '/.git')) {
        return $cached = ['tag' => $fallback, 'source' => 'fallback'];
    }

    $cmd = 'git -C ' . escapeshellarg($root) . ' describe --tags --abbrev=0 2>/dev/null';
    $out = @shell_exec($cmd);
    $tag = trim((string) $out);
    if ($tag !== '') {
        return $cached = ['tag' => $tag, 'source' => 'git'];
    }
    return $cached = ['tag' => $fallback, 'source' => 'fallback'];
}

function app_release_tag(): string
{
    $info = app_release_info();
    return (string) ($info['tag'] ?? ('v' . APP_VERSION));
}

function rate_limit_allow(string $bucket, int $limit, int $windowSeconds, ?string $scopeKey = null): array
{
    $limit = max(1, $limit);
    $windowSeconds = max(1, $windowSeconds);
    $scope = $scopeKey !== null && $scopeKey !== '' ? $scopeKey : client_ip();
    $scopeHash = sha1($scope);
    $safeBucket = preg_replace('/[^a-z0-9_.-]/i', '_', strtolower($bucket)) ?? 'default';
    $dir = rtrim((string) sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'meteo13_rl';

    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return ['ok' => true, 'remaining' => $limit, 'retry_after' => 0];
    }

    $file = $dir . DIRECTORY_SEPARATOR . 'rl_' . $safeBucket . '_' . $scopeHash . '.json';
    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return ['ok' => true, 'remaining' => $limit, 'retry_after' => 0];
    }

    $now = time();
    $ok = true;
    $remaining = $limit;
    $retryAfter = 0;

    try {
        if (!flock($fp, LOCK_EX)) {
            return ['ok' => true, 'remaining' => $limit, 'retry_after' => 0];
        }

        $raw = stream_get_contents($fp);
        $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($state)) {
            $state = ['window_start' => $now, 'count' => 0];
        }

        $windowStart = (int) ($state['window_start'] ?? $now);
        $count = (int) ($state['count'] ?? 0);
        if (($now - $windowStart) >= $windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }

        if ($count >= $limit) {
            $ok = false;
            $retryAfter = max(1, $windowSeconds - ($now - $windowStart));
            $remaining = 0;
        } else {
            $count++;
            $remaining = max(0, $limit - $count);
            $state = ['window_start' => $windowStart, 'count' => $count];
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, (string) json_encode($state, JSON_UNESCAPED_SLASHES));
            fflush($fp);
        }
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }

    return ['ok' => $ok, 'remaining' => $remaining, 'retry_after' => $retryAfter];
}

function request_bearer_token(): string
{
    $headers = [
        (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
        (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
    ];
    foreach ($headers as $auth) {
        if ($auth !== '' && preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $auth, $m) === 1) {
            return trim((string) $m[1]);
        }
    }
    return '';
}
