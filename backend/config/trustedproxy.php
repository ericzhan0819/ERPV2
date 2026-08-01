<?php

return [
    // 由 AppServiceProvider::boot() 明確傳入 TrustProxies::at()，不依賴 framework legacy fallback。
    // 只信任實際反向代理；Docker／其他網段由 TRUSTED_PROXIES 覆蓋，禁止使用 *。
    'proxies' => array_values(array_filter(array_map(
        static fn (string $proxy): string => trim($proxy),
        explode(',', (string) env('TRUSTED_PROXIES', '127.0.0.1,::1')),
    ))),
];
