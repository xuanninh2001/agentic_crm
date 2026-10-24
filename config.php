<?php

return array(
    'app_name' => 'Pulse Inbox',
    'timezone' => 'Asia/Ho_Chi_Minh',
    'retention_hours' => 24,
    'storage_file' => __DIR__ . '/data/items.json',
    // Nên đặt biến môi trường PULSE_API_TOKEN trên hosting.
    'api_token' => getenv('PULSE_API_TOKEN') ?: 'doi-token-nay-truoc-khi-dua-len-hosting',
);
