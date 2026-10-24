# Pulse Inbox

Trang theo dõi dữ liệu gửi lên trong 24 giờ gần nhất, tối ưu cho desktop và mobile, chạy với PHP 7.4 trở lên.

## Tính năng

- Hiển thị thời gian tương đối như `1 phút trước`, `2 giờ trước` theo múi giờ `Asia/Ho_Chi_Minh`.
- Sắp xếp mới nhất/cũ nhất và lọc nhanh theo nhóm.
- Nội dung tóm tắt, nội dung đầy đủ có thể mở rộng, liên kết ngoài và xoá có xác nhận.
- Vuốt sang trái để xoá ngay không cần xác nhận; vuốt sang phải để lưu trữ.
- Tin trong hộp thư tự xoá sau 24 giờ; tin lưu trữ được giữ đến khi xoá thủ công.
- API nhận JSON hoặc form-data, xác thực bằng token.
- Lưu dữ liệu bằng JSON có file lock, không cần MySQL hoặc SQLite.

## Triển khai trên hosting

1. Tải toàn bộ thư mục dự án lên hosting, ví dụ vào `public_html/inbox`.
2. Đảm bảo hosting dùng PHP 7.4 trở lên.
3. Cấp quyền ghi cho thư mục `data` (thường là `755` hoặc `775`; một số hosting cần `777`).
4. Đổi API token trong `config.php` hoặc đặt biến môi trường `PULSE_API_TOKEN`.
5. Mở `https://ten-mien-cua-ban.com/inbox/` để kiểm tra.

> Không giữ token mặc định `doi-token-nay-truoc-khi-dua-len-hosting` khi đưa vào sử dụng thật.

Apache sẽ dùng các tệp `.htaccess` có sẵn để chặn truy cập trực tiếp vào kho dữ liệu và tệp cấu hình. Nếu dùng Nginx, cần thêm rule chặn `/data/`, `config.php` và `lib.php` ở cấu hình máy chủ.

## Gửi nội dung mới

### Endpoint

```text
POST https://ten-mien-cua-ban.com/inbox/api.php
```

### Headers

```text
Content-Type: application/json
X-API-Key: TOKEN_CUA_BAN
```

Có thể dùng `Authorization: Bearer TOKEN_CUA_BAN` thay cho `X-API-Key`.

### Các trường dữ liệu

| Trường | Bắt buộc | Giới hạn | Mô tả |
| --- | --- | --- | --- |
| `content` | Có | 20.000 ký tự | Nội dung đầy đủ. |
| `summary` | Không | 300 ký tự | Tóm tắt; hệ thống tự tạo nếu bỏ trống. |
| `group` | Không | 80 ký tự | Nhóm dữ liệu; mặc định là `Chung`. |
| `url` | Không | 2.000 ký tự | Liên kết mở chi tiết, chỉ chấp nhận HTTP hoặc HTTPS. |
| `created_at` | Không | ISO 8601 | Thời điểm tạo; mặc định là hiện tại theo giờ Hồ Chí Minh. |

`created_at` không được cũ hơn 24 giờ và không được vượt quá 5 phút trong tương lai.

### Ví dụ cURL

```bash
curl -X POST "https://ten-mien-cua-ban.com/inbox/api.php" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: TOKEN_CUA_BAN" \
  -d '{
    "summary": "Đơn hàng #1024 vừa được thanh toán",
    "content": "Khách hàng Nguyễn Văn A đã thanh toán thành công đơn hàng #1024.",
    "group": "Đơn hàng",
    "url": "https://example.com/orders/1024"
  }'
```

### Ví dụ PHP 7.4

```php
<?php

$payload = json_encode(array(
    'summary' => 'Có liên hệ mới từ website',
    'content' => 'Khách hàng để lại thông tin tư vấn sản phẩm A.',
    'group' => 'Khách hàng',
    'url' => 'https://example.com/contacts/123',
), JSON_UNESCAPED_UNICODE);

$context = stream_context_create(array(
    'http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nX-API-Key: TOKEN_CUA_BAN\r\n",
        'content' => $payload,
        'ignore_errors' => true,
    ),
));

$response = file_get_contents(
    'https://ten-mien-cua-ban.com/inbox/api.php',
    false,
    $context
);

echo $response;
```

### Phản hồi thành công

HTTP status: `201 Created`

```json
{
  "ok": true,
  "message": "Đã nhận nội dung.",
  "item": {
    "id": "...",
    "created_at": "2026-08-10T14:30:00+07:00",
    "summary": "Đơn hàng #1024 vừa được thanh toán",
    "content": "Khách hàng đã thanh toán thành công...",
    "group": "Đơn hàng",
    "url": "https://example.com/orders/1024"
  }
}
```

## Cơ chế lưu và xoá

Dữ liệu nằm trong `data/items.json`. Mỗi lần mở trang, gửi nội dung, lưu trữ hoặc xoá nội dung, ứng dụng khóa file rồi loại bỏ các tin chưa lưu trữ đã quá 24 giờ. Tin có `archived_at` được bỏ qua khi dọn dữ liệu và chỉ mất khi người dùng xoá thủ công.

Trên thiết bị cảm ứng:

- Vuốt tin từ phải sang trái để xoá ngay, không hiện hộp xác nhận.
- Vuốt tin từ trái sang phải để chuyển vào khu vực `Đã lưu trữ`.
- Nút `Xoá` thủ công vẫn hiển thị hộp xác nhận để tránh bấm nhầm.

Nên sao lưu `config.php` riêng khi cập nhật mã nguồn. Không đưa `data/items.json` lên Git nếu dữ liệu có tính riêng tư.
