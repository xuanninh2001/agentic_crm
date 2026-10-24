<?php

declare(strict_types=1);

function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $config = require __DIR__ . '/config.php';
        date_default_timezone_set($config['timezone']);
    }

    return $config;
}

function storage_transaction(callable $callback)
{
    $config = app_config();
    $path = $config['storage_file'];
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Không thể tạo thư mục dữ liệu.');
    }

    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Không thể mở kho dữ liệu.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Không thể khóa kho dữ liệu.');
        }

        rewind($handle);
        $raw = stream_get_contents($handle);
        $items = $raw !== false && trim($raw) !== '' ? json_decode($raw, true) : array();
        if (!is_array($items)) {
            $items = array();
        }

        $cutoff = time() - ((int) $config['retention_hours'] * 3600);
        $beforeCleanup = count($items);
        $items = array_values(array_filter($items, function ($item) use ($cutoff) {
            if (!is_array($item)) {
                return false;
            }
            if (is_item_archived($item)) {
                return true;
            }

            return isset($item['created_at'])
                && strtotime((string) $item['created_at']) >= $cutoff;
        }));

        $result = call_user_func_array($callback, array(&$items));
        $changed = count($items) !== $beforeCleanup;
        if (is_array($result) && isset($result['_changed'])) {
            $changed = $changed || (bool) $result['_changed'];
            unset($result['_changed']);
        }

        if ($changed || $raw === false || trim($raw) === '') {
            $encoded = json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            if ($encoded === false) {
                throw new RuntimeException('Không thể mã hóa dữ liệu.');
            }

            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $encoded . PHP_EOL) === false) {
                throw new RuntimeException('Không thể ghi kho dữ liệu.');
            }
            fflush($handle);
        }

        flock($handle, LOCK_UN);
        return $result;
    } finally {
        fclose($handle);
    }
}

function get_items(): array
{
    return storage_transaction(function (&$items) {
        return $items;
    });
}

function add_item(array $item): array
{
    return storage_transaction(function (&$items) use ($item) {
        $items[] = $item;
        return array('item' => $item, '_changed' => true);
    });
}

function delete_item(string $id): bool
{
    $result = storage_transaction(function (&$items) use ($id) {
        $before = count($items);
        $items = array_values(array_filter($items, function ($item) use ($id) {
            return !isset($item['id']) || !hash_equals((string) $item['id'], $id);
        }));

        $deleted = count($items) < $before;
        return array('deleted' => $deleted, '_changed' => $deleted);
    });

    return (bool) $result['deleted'];
}

function archive_item(string $id): bool
{
    $result = storage_transaction(function (&$items) use ($id) {
        $archived = false;
        foreach ($items as &$item) {
            if (isset($item['id']) && hash_equals((string) $item['id'], $id)) {
                if (!is_item_archived($item)) {
                    $item['archived_at'] = (new DateTimeImmutable('now', new DateTimeZone(app_config()['timezone'])))->format(DateTime::ATOM);
                    $archived = true;
                }
                break;
            }
        }
        unset($item);

        return array('archived' => $archived, '_changed' => $archived);
    });

    return (bool) $result['archived'];
}

function is_item_archived(array $item): bool
{
    return isset($item['archived_at']) && trim((string) $item['archived_at']) !== '';
}

function normalize_text($value, int $maxLength): string
{
    if (!is_scalar($value)) {
        return '';
    }

    $text = trim((string) $value);
    if (utf8_length($text) > $maxLength) {
        $text = utf8_truncate($text, $maxLength);
    }

    return $text;
}

function utf8_length(string $text): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($text, 'UTF-8');
    }

    $count = preg_match_all('/./us', $text, $matches);
    return $count !== false ? $count : strlen($text);
}

function utf8_truncate(string $text, int $length): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $length, 'UTF-8');
    }

    $matched = preg_match_all('/./us', $text, $matches);
    if ($matched === false) {
        return substr($text, 0, $length);
    }

    return implode('', array_slice($matches[0], 0, $length));
}

function make_summary(string $content, int $maxLength = 150): string
{
    $plain = trim((string) preg_replace('/\s+/u', ' ', $content));
    if (utf8_length($plain) <= $maxLength) {
        return $plain;
    }

    $short = utf8_truncate($plain, $maxLength - 1);

    return rtrim($short) . '…';
}

function is_safe_http_url(string $url): bool
{
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return $scheme === 'http' || $scheme === 'https';
}

function new_item_id(): string
{
    try {
        return bin2hex(random_bytes(8));
    } catch (Exception $exception) {
        return str_replace('.', '', uniqid('', true));
    }
}

function parse_created_at($value): DateTimeImmutable
{
    $timezone = new DateTimeZone(app_config()['timezone']);
    if ($value === null || $value === '') {
        return new DateTimeImmutable('now', $timezone);
    }

    try {
        $date = new DateTimeImmutable((string) $value, $timezone);
    } catch (Exception $exception) {
        throw new InvalidArgumentException('created_at không đúng định dạng thời gian.');
    }

    $now = new DateTimeImmutable('now', $timezone);
    if ($date > $now->modify('+5 minutes')) {
        throw new InvalidArgumentException('created_at không được nằm quá 5 phút trong tương lai.');
    }
    if ($date < $now->modify('-' . (int) app_config()['retention_hours'] . ' hours')) {
        throw new InvalidArgumentException('created_at đã nằm ngoài cửa sổ lưu trữ 24 giờ.');
    }

    return $date->setTimezone($timezone);
}

function relative_time(string $dateTime): string
{
    $then = strtotime($dateTime);
    $seconds = max(0, time() - $then);

    if ($seconds < 10) {
        return 'vừa xong';
    }
    if ($seconds < 60) {
        return $seconds . ' giây trước';
    }
    if ($seconds < 3600) {
        return floor($seconds / 60) . ' phút trước';
    }
    if ($seconds < 86400) {
        return floor($seconds / 3600) . ' giờ trước';
    }

    return floor($seconds / 86400) . ' ngày trước';
}

function api_token_from_request(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : array();
    $token = '';

    foreach ($headers as $name => $value) {
        if (strtolower((string) $name) === 'x-api-key') {
            $token = trim((string) $value);
            break;
        }
        if (strtolower((string) $name) === 'authorization' && preg_match('/^Bearer\s+(.+)$/i', (string) $value, $matches)) {
            $token = trim($matches[1]);
        }
    }

    if ($token === '' && isset($_SERVER['HTTP_X_API_KEY'])) {
        $token = trim((string) $_SERVER['HTTP_X_API_KEY']);
    }

    return $token;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
