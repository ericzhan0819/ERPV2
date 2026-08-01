<?php

namespace App\Support;

final class TrustedHostPatterns
{
    /**
     * APP_URL host 永遠列入；TRUSTED_HOSTS 只補充 LAN、Tailscale、health check 等額外入口。
     * 回傳 Symfony Request::setTrustedHosts() 使用的精確比對 pattern，不自動信任子網域。
     *
     * @param  array<int, string>  $additionalHosts
     * @return array<int, string>
     */
    public static function from(string $appUrl, array $additionalHosts): array
    {
        $hosts = [];
        $appHost = parse_url($appUrl, PHP_URL_HOST);

        if (is_string($appHost) && $appHost !== '') {
            $hosts[] = strtolower($appHost);
        }

        foreach ($additionalHosts as $host) {
            $normalized = self::normalize($host);

            if ($normalized !== null) {
                $hosts[] = $normalized;
            }
        }

        return array_values(array_map(
            static fn (string $host): string => '\A'.preg_quote($host, '#').'\z',
            array_values(array_unique($hosts)),
        ));
    }

    private static function normalize(string $host): ?string
    {
        $host = trim($host);

        if ($host === '') {
            return null;
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return '['.strtolower($host).']';
        }

        $normalized = str_contains($host, '://')
            ? parse_url($host, PHP_URL_HOST)
            : parse_url('http://'.$host, PHP_URL_HOST);

        return is_string($normalized) && $normalized !== ''
            ? strtolower($normalized)
            : null;
    }
}
