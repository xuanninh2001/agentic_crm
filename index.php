<?php

declare(strict_types=1);

session_start();
require __DIR__ . '/lib.php';

$config = app_config();
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
}

$sort = isset($_GET['sort']) && $_GET['sort'] === 'oldest' ? 'oldest' : 'newest';
$selectedGroup = isset($_GET['group']) ? normalize_text($_GET['group'], 80) : '';
$view = isset($_GET['view']) && $_GET['view'] === 'archive' ? 'archive' : 'inbox';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];
    $token = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $id = isset($_POST['id']) ? normalize_text($_POST['id'], 80) : '';
    $acceptHeader = isset($_SERVER['HTTP_ACCEPT']) ? strtolower((string) $_SERVER['HTTP_ACCEPT']) : '';
    $wantsJson = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || strpos($acceptHeader, 'application/json') !== false;

    if ($token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        if ($wantsJson) {
            json_response(array('ok' => false, 'error' => 'Phiên làm việc không hợp lệ.'), 403);
        }
        http_response_code(403);
        exit('Phiên làm việc không hợp lệ. Vui lòng tải lại trang.');
    }

    if ($action === 'delete') {
        $success = $id !== '' && delete_item($id);
        $notice = $success ? 'deleted' : 'not-found';
    } elseif ($action === 'archive') {
        $success = $id !== '' && archive_item($id);
        $notice = $success ? 'archived' : 'not-found';
    } else {
        $success = false;
        $notice = 'invalid-action';
    }

    if ($wantsJson) {
        json_response(array('ok' => $success, 'action' => $action, 'notice' => $notice), $success ? 200 : 422);
    }

    $query = array('notice' => $notice, 'sort' => $sort, 'view' => $view);
    if ($selectedGroup !== '') {
        $query['group'] = $selectedGroup;
    }
    header('Location: ?' . http_build_query($query));
    exit;
}

$allItems = get_items();
$archiveCount = count(array_filter($allItems, function ($item) {
    return is_array($item) && is_item_archived($item);
}));
$inboxCount = count($allItems) - $archiveCount;

$items = array_values(array_filter($allItems, function ($item) use ($view) {
    $archived = is_array($item) && is_item_archived($item);
    return $view === 'archive' ? $archived : !$archived;
}));

$groups = array();
foreach ($items as $item) {
    if (isset($item['group']) && $item['group'] !== '') {
        $groups[(string) $item['group']] = true;
    }
}
$groups = array_keys($groups);
natcasesort($groups);

if ($selectedGroup !== '') {
    $items = array_values(array_filter($items, function ($item) use ($selectedGroup) {
        return isset($item['group']) && (string) $item['group'] === $selectedGroup;
    }));
}

usort($items, function ($a, $b) use ($sort) {
    $aTime = strtotime((string) $a['created_at']);
    $bTime = strtotime((string) $b['created_at']);
    return $sort === 'oldest' ? $aTime <=> $bTime : $bTime <=> $aTime;
});

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function safe_url($value): string
{
    $url = trim((string) $value);
    return is_safe_http_url($url) ? $url : '';
}

$now = new DateTimeImmutable('now', new DateTimeZone($config['timezone']));
$notice = isset($_GET['notice']) ? (string) $_GET['notice'] : '';
$requestPath = parse_url(isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH);
$basePath = rtrim(str_replace('\\', '/', dirname(is_string($requestPath) ? $requestPath : '/')), '/.');
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#f3f0e8">
    <title><?= e($config['app_name']) ?> — <?= $view === 'archive' ? 'Đã lưu trữ' : 'Dữ liệu 24 giờ' ?></title>
    <meta name="description" content="Theo dõi và lưu trữ nội dung gửi lên.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&amp;family=Newsreader:opsz,wght@6..72,500;6..72,600&amp;display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=4">
    <script src="assets/app.js?v=4" defer></script>
</head>
<body>
    <header class="masthead">
        <a class="brand" href="./" aria-label="Về trang đầu">
            <span class="brand-mark" aria-hidden="true"><i></i><i></i><i></i></span>
            <span><?= e($config['app_name']) ?></span>
        </a>
        <div class="local-time">
            <span class="live-dot" aria-hidden="true"></span>
            <span>Hồ Chí Minh</span>
            <strong id="local-clock"><?= e($now->format('H:i:s')) ?></strong>
        </div>
    </header>

    <main class="workspace">
        <aside class="rail" aria-label="Thông tin dữ liệu">
            <div class="eyebrow">Cửa sổ dữ liệu</div>
            <div class="retention-number">24<span>giờ</span></div>
            <p>Tin trong hộp thư tự xoá sau 24 giờ. Tin lưu trữ được giữ cho đến khi bạn xoá thủ công.</p>

            <div class="rail-divider"></div>

            <dl class="quick-stats">
                <div><dt>Hộp thư</dt><dd data-inbox-count><?= $inboxCount ?></dd></div>
                <div><dt>Lưu trữ</dt><dd data-archive-count><?= $archiveCount ?></dd></div>
            </dl>

            <a class="docs-jump" href="#api-docs">
                <span>Tài liệu gửi dữ liệu</span>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            </a>
        </aside>

        <section class="feed" aria-labelledby="feed-heading">
            <nav class="view-tabs" aria-label="Khu vực nội dung">
                <a href="?<?= e(http_build_query(array('view' => 'inbox', 'sort' => $sort))) ?>" class="<?= $view === 'inbox' ? 'active' : '' ?>">
                    Hộp thư <span data-tab-inbox-count><?= $inboxCount ?></span>
                </a>
                <a href="?<?= e(http_build_query(array('view' => 'archive', 'sort' => $sort))) ?>" class="<?= $view === 'archive' ? 'active' : '' ?>">
                    Đã lưu trữ <span data-tab-archive-count><?= $archiveCount ?></span>
                </a>
            </nav>

            <div class="feed-header">
                <div>
                    <div class="eyebrow"><?= $view === 'archive' ? 'Giữ lại không thời hạn' : 'Dòng dữ liệu trực tiếp' ?></div>
                    <h1 id="feed-heading"><?= $view === 'archive' ? 'Đã lưu trữ' : 'Nội dung gần đây' ?></h1>
                </div>

                <form class="sort-control" method="get">
                    <input type="hidden" name="view" value="<?= e($view) ?>">
                    <?php if ($selectedGroup !== ''): ?>
                        <input type="hidden" name="group" value="<?= e($selectedGroup) ?>">
                    <?php endif; ?>
                    <label for="sort">Sắp xếp</label>
                    <div class="select-wrap">
                        <select id="sort" name="sort" data-auto-submit>
                            <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Mới nhất trước</option>
                            <option value="oldest"<?= $sort === 'oldest' ? ' selected' : '' ?>>Cũ nhất trước</option>
                        </select>
                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                    </div>
                </form>
            </div>

            <?php if (count($groups) > 0): ?>
                <nav class="group-filter" aria-label="Lọc theo nhóm">
                    <a href="?<?= e(http_build_query(array('view' => $view, 'sort' => $sort))) ?>" class="<?= $selectedGroup === '' ? 'active' : '' ?>">Tất cả</a>
                    <?php foreach ($groups as $group): ?>
                        <a href="?<?= e(http_build_query(array('view' => $view, 'sort' => $sort, 'group' => $group))) ?>" class="<?= $selectedGroup === $group ? 'active' : '' ?>"><?= e($group) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>

            <div class="swipe-guide" aria-hidden="true">
                <?php if ($view === 'inbox'): ?><span>Vuốt sang phải để lưu trữ →</span><?php endif; ?>
                <span>← Vuốt sang trái để xoá</span>
            </div>

            <div class="feed-list" aria-live="polite">
                <?php if (count($items) === 0): ?>
                    <div class="empty-state">
                        <div class="empty-orbit" aria-hidden="true"><span></span></div>
                        <h2><?= $view === 'archive' ? 'Chưa có tin nào được lưu trữ' : 'Chưa có nội dung trong 24 giờ qua' ?></h2>
                        <p><?= $view === 'archive' ? 'Vuốt một tin sang phải trong hộp thư để giữ lại không thời hạn.' : 'Gửi bản tin đầu tiên qua API. Hướng dẫn đầy đủ nằm ngay cuối trang.' ?></p>
                        <a href="<?= $view === 'archive' ? '?view=inbox' : '#api-docs' ?>"><?= $view === 'archive' ? 'Về hộp thư' : 'Xem cách gửi dữ liệu' ?></a>
                    </div>
                <?php else: ?>
                    <?php foreach ($items as $index => $item):
                        $createdAt = new DateTimeImmutable((string) $item['created_at']);
                        $createdAt = $createdAt->setTimezone(new DateTimeZone($config['timezone']));
                        $expiresAt = $createdAt->modify('+' . (int) $config['retention_hours'] . ' hours');
                        $archivedAt = is_item_archived($item) ? new DateTimeImmutable((string) $item['archived_at']) : null;
                        $url = safe_url(isset($item['url']) ? $item['url'] : '');
                        $contentId = 'content-' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $item['id']);
                    ?>
                        <article class="feed-item" style="--item-index: <?= (int) $index ?>" data-swipe-item data-archivable="<?= $view === 'inbox' ? 'true' : 'false' ?>">
                            <div class="swipe-underlay" aria-hidden="true">
                                <div class="swipe-hint archive-hint">
                                    <svg viewBox="0 0 24 24"><path d="M4 7h16v13H4V7Zm-1-4h18v4H3V3Zm6 9h6"/></svg>
                                    <span>Lưu trữ</span>
                                </div>
                                <div class="swipe-hint delete-hint">
                                    <span>Xoá ngay</span>
                                    <svg viewBox="0 0 24 24"><path d="M4 7h16M9 3h6l1 4H8l1-4Zm-2 4 1 14h8l1-14M10 11v6M14 11v6"/></svg>
                                </div>
                            </div>
                            <div class="swipe-surface">
                                <div class="timeline" aria-hidden="true"><span></span></div>
                                <div class="item-main">
                                <div class="item-meta">
                                    <span class="group-label"><?= e($item['group']) ?></span>
                                    <span class="meta-separator">/</span>
                                    <time datetime="<?= e($createdAt->format(DateTime::ATOM)) ?>" data-relative-time title="<?= e($createdAt->format('d/m/Y H:i:s')) ?>"><?= e(relative_time((string) $item['created_at'])) ?></time>
                                    <span class="exact-time"><?= e($createdAt->format('H:i · d/m')) ?></span>
                                </div>

                                <h2><?= e($item['summary']) ?></h2>

                                <div class="full-content" id="<?= e($contentId) ?>" hidden>
                                    <p><?= nl2br(e($item['content'])) ?></p>
                                    <?php if ($view === 'archive' && $archivedAt !== null): ?>
                                        <div class="expiry-note archived-note">Đã lưu trữ lúc <?= e($archivedAt->setTimezone(new DateTimeZone($config['timezone']))->format('H:i · d/m/Y')) ?> · Không tự động xoá</div>
                                    <?php else: ?>
                                        <div class="expiry-note">Tự xoá lúc <?= e($expiresAt->format('H:i')) ?> ngày <?= e($expiresAt->format('d/m/Y')) ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="item-actions">
                                    <button class="text-action expand-action" type="button" aria-expanded="false" aria-controls="<?= e($contentId) ?>">
                                        <span class="expand-label">Mở rộng</span>
                                        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                                    </button>

                                    <?php if ($url !== ''): ?>
                                        <a class="text-action open-action" href="<?= e($url) ?>" target="_blank" rel="noopener noreferrer">
                                            Mở
                                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11 4h5v5M9 11l7-7M16 12v4H4V4h4"/></svg>
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($view === 'inbox'): ?>
                                        <form method="post" class="archive-form">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                            <button class="text-action archive-action" type="submit" data-archive aria-label="Lưu trữ: <?= e($item['summary']) ?>">
                                                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 6h14v11H3V6Zm-1-3h16v3H2V3Zm6 8h4"/></svg>
                                                <span>Lưu trữ</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" class="delete-form">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= e($item['id']) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                        <button class="delete-action" type="submit" data-delete data-summary="<?= e($item['summary']) ?>" aria-label="Xoá: <?= e($item['summary']) ?>">
                                            <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 3h4l1 3H7l1-3ZM6 6l1 11h6l1-11M9 9v5M11 9v5"/></svg>
                                            <span>Xoá</span>
                                        </button>
                                    </form>
                                </div>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <section class="api-docs" id="api-docs" aria-labelledby="docs-heading">
                <div class="docs-intro">
                    <div class="eyebrow">Tích hợp API</div>
                    <h2 id="docs-heading">Đẩy nội dung mới</h2>
                    <p>Gửi một yêu cầu <code>POST</code> tới <code>api.php</code>. Tin trong hộp thư được giữ 24 giờ; tin đã lưu trữ được giữ đến khi xoá thủ công.</p>
                </div>

                <div class="docs-content">
                    <div class="endpoint-row">
                        <span class="method">POST</span>
                        <code><?= e($basePath) ?>/api.php</code>
                    </div>

                    <h3>Headers</h3>
                    <div class="field-row"><code>Content-Type</code><span>application/json</span></div>
                    <div class="field-row"><code>X-API-Key</code><span>Token trong <code>config.php</code></span></div>

                    <h3>Nội dung JSON</h3>
                    <div class="code-block">
                        <button type="button" data-copy-target="payload-example">Sao chép</button>
<pre id="payload-example"><code>{
  "summary": "Đơn hàng #1024 vừa được thanh toán",
  "content": "Khách hàng đã thanh toán thành công...",
  "group": "Đơn hàng",
  "url": "https://example.com/orders/1024",
  "created_at": "<?= e($now->format(DateTime::ATOM)) ?>"
}</code></pre>
                    </div>

                    <p class="docs-note"><strong>Bắt buộc:</strong> <code>content</code>. Các trường còn lại là tuỳ chọn; <code>summary</code> sẽ được tạo tự động, <code>group</code> mặc định là “Chung”, và <code>created_at</code> mặc định là thời điểm nhận.</p>
                    <a class="readme-link" href="README.md">Mở tài liệu triển khai đầy đủ <span>↗</span></a>
                </div>
            </section>
        </section>
    </main>

    <dialog class="confirm-dialog" id="delete-dialog" aria-labelledby="dialog-title">
        <form method="dialog">
            <div class="dialog-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01M10.3 3.7 2.9 17a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/></svg>
            </div>
            <h2 id="dialog-title">Xoá nội dung này?</h2>
            <p id="dialog-summary">Thao tác này không thể hoàn tác.</p>
            <div class="dialog-actions">
                <button value="cancel" class="button-secondary">Giữ lại</button>
                <button value="confirm" class="button-danger">Xoá nội dung</button>
            </div>
        </form>
    </dialog>

    <?php if ($notice !== ''): ?>
        <div class="toast" role="status" data-toast>
            <?php
            if ($notice === 'deleted') {
                echo 'Đã xoá nội dung.';
            } elseif ($notice === 'archived') {
                echo 'Đã lưu trữ nội dung.';
            } else {
                echo 'Không thể thực hiện thao tác.';
            }
            ?>
        </div>
    <?php endif; ?>
</body>
</html>
