<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class StorageAssetUrl
{
    /**
     * 預設保留 disk／APP_URL 產生的 canonical 網址；只有內部 SPA 明確 opt-in 時，
     * 才改用經 trusted proxy 判定後的實際 request origin，讓 LAN／Tailscale／HTTPS
     * 反向代理入口都能讀取本機照片。S3／R2 等遠端 disk 不改寫。
     */
    public static function forRequest(
        Request $request,
        string $disk,
        string $path,
        bool $followRequestOrigin = false,
    ): string {
        $url = Storage::disk($disk)->url($path);

        if (! $followRequestOrigin || config("filesystems.disks.{$disk}.driver") !== 'local') {
            return $url;
        }

        $parts = parse_url($url);
        $urlPath = $parts['path'] ?? null;

        if (! is_string($urlPath) || $urlPath === '') {
            return $url;
        }

        $resolved = rtrim($request->getSchemeAndHttpHost(), '/').'/'.ltrim($urlPath, '/');

        if (isset($parts['query'])) {
            $resolved .= '?'.$parts['query'];
        }

        if (isset($parts['fragment'])) {
            $resolved .= '#'.$parts['fragment'];
        }

        return $resolved;
    }
}
