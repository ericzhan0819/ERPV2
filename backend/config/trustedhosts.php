<?php

return [
    // APP_URL host 會由 bootstrap 自動加入；此處只列額外允許的 LAN、Tailscale、
    // health check 或 load balancer Host。填 hostname／IP，不含 port；禁止使用萬用字元。
    'additional_hosts' => array_values(array_filter(array_map(
        static fn (string $host): string => trim($host),
        explode(',', (string) env('TRUSTED_HOSTS', 'localhost,127.0.0.1,::1')),
    ))),
];
