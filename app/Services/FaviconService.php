<?php

namespace App\Services;

use App\Models\PartnerOrganization;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class FaviconService
{
    public function fetchForPartner(PartnerOrganization $partner): void
    {
        if (! $partner->domain) {
            return;
        }

        try {
            $iconUrl = $this->discoverIconUrl($partner->domain);

            if (! $iconUrl || ! $this->isSafeUrl($iconUrl)) {
                return;
            }

            $response = Http::timeout(10)->get($iconUrl);

            if (! $response->successful()) {
                return;
            }

            $extension = $this->guessExtension($response->header('Content-Type'), $iconUrl);
            $filename = "favicons/{$partner->id}.{$extension}";

            if ($partner->favicon_path && Storage::disk('public')->exists($partner->favicon_path)) {
                Storage::disk('public')->delete($partner->favicon_path);
            }

            Storage::disk('public')->put($filename, $response->body());

            $partner->update(['favicon_path' => $filename]);
        } catch (\Throwable $e) {
            Log::warning("Favicon fetch failed for partner {$partner->id} ({$partner->domain}): {$e->getMessage()}");
        }
    }

    private function discoverIconUrl(string $domain): ?string
    {
        $baseUrl = "https://{$domain}";

        if (! $this->isSafeUrl($baseUrl)) {
            return null;
        }

        try {
            $response = Http::timeout(10)->get($baseUrl);

            if ($response->successful()) {
                $iconHref = $this->parseIconFromHtml($response->body());

                if ($iconHref) {
                    $resolved = $this->resolveUrl($iconHref, $baseUrl);
                    if ($resolved) {
                        return $resolved;
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to favicon.ico fallback
        }

        try {
            $faviconUrl = "{$baseUrl}/favicon.ico";
            $fallback = Http::timeout(10)->get($faviconUrl);

            if ($fallback->successful() && str_starts_with($fallback->header('Content-Type', ''), 'image/')) {
                return $faviconUrl;
            }
        } catch (\Throwable) {
            // No favicon available
        }

        return null;
    }

    private function parseIconFromHtml(string $html): ?string
    {
        if (! preg_match_all('/<link\s[^>]*rel=["\'](?:shortcut )?icon["\'][^>]*>/i', $html, $matches)) {
            return null;
        }

        $best = null;
        $bestSize = 0;

        foreach ($matches[0] as $tag) {
            if (! preg_match('/href=["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
                continue;
            }

            $href = $hrefMatch[1];
            $size = 0;

            if (preg_match('/sizes=["\'](\d+)x\d+["\']/i', $tag, $sizeMatch)) {
                $size = (int) $sizeMatch[1];
            }

            if ($best === null || $size > $bestSize) {
                $best = $href;
                $bestSize = $size;
            }
        }

        return $best;
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            $url = $href;
        } elseif (str_starts_with($href, '//')) {
            $url = "https:{$href}";
        } elseif (str_starts_with($href, '/')) {
            $url = "{$baseUrl}{$href}";
        } else {
            $url = "{$baseUrl}/{$href}";
        }

        if (! $this->isSafeUrl($url)) {
            Log::warning("Blocked SSRF attempt in favicon URL: {$url}");

            return null;
        }

        return $url;
    }

    private function isSafeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return false;
        }

        $ips = gethostbynamel($host);
        if (! $ips) {
            return false;
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private function guessExtension(string $contentType, string $url): string
    {
        $map = [
            'image/png' => 'png',
            'image/x-icon' => 'ico',
            'image/vnd.microsoft.icon' => 'ico',
            'image/svg+xml' => 'svg',
            'image/gif' => 'gif',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
        ];

        foreach ($map as $mime => $ext) {
            if (str_starts_with($contentType, $mime)) {
                return $ext;
            }
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = pathinfo($path, PATHINFO_EXTENSION);

        if ($ext && in_array($ext, ['png', 'ico', 'svg', 'gif', 'jpg', 'jpeg', 'webp'])) {
            return $ext;
        }

        return 'ico';
    }
}
