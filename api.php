<?php

declare(strict_types=1);

require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_response(array('ok' => false, 'error' => 'Chỉ hỗ trợ phương thức POST.'), 405);
}

$configuredToken = (string) app_config()['api_token'];
$providedToken = api_token_from_request();
if ($providedToken === '' || !hash_equals($configuredToken, $providedToken)) {
    json_response(array('ok' => false, 'error' => 'API token không hợp lệ.'), 401);
}

$contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower((string) $_SERVER['CONTENT_TYPE']) : '';
if (strpos($contentType, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($payload)) {
        json_response(array('ok' => false, 'error' => 'JSON không hợp lệ.'), 400);
    }
} else {
    $payload = $_POST;
}

$content = normalize_text(isset($payload['content']) ? $payload['content'] : '', 20000);
$summary = normalize_text(isset($payload['summary']) ? $payload['summary'] : '', 300);
$group = normalize_text(isset($payload['group']) ? $payload['group'] : 'Chung', 80);
$url = normalize_text(isset($payload['url']) ? $payload['url'] : '', 2000);

if ($content === '') {
    json_response(array('ok' => false, 'error' => 'Trường content là bắt buộc.'), 422);
}
if ($group === '') {
    $group = 'Chung';
}
if ($url !== '' && !is_safe_http_url($url)) {
    json_response(array('ok' => false, 'error' => 'Trường url phải là liên kết HTTP hoặc HTTPS hợp lệ.'), 422);
}

try {
    $createdAt = parse_created_at(isset($payload['created_at']) ? $payload['created_at'] : null);
} catch (InvalidArgumentException $exception) {
    json_response(array('ok' => false, 'error' => $exception->getMessage()), 422);
}

$item = array(
    'id' => new_item_id(),
    'created_at' => $createdAt->format(DateTime::ATOM),
    'summary' => $summary !== '' ? $summary : make_summary($content),
    'content' => $content,
    'group' => $group,
    'url' => $url,
    'archived_at' => null,
);

try {
    add_item($item);
} catch (RuntimeException $exception) {
    json_response(array('ok' => false, 'error' => $exception->getMessage()), 500);
}

json_response(array('ok' => true, 'message' => 'Đã nhận nội dung.', 'item' => $item), 201);
