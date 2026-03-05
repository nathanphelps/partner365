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

            if (! $iconUrl) {
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

        try {
            $response = Http::timeout(10)->get($baseUrl);

            if ($response->successful()) {
                $iconHref = $this->parseIconFromHtml($response->body());

                if ($iconHref) {
                    return $this->resolveUrl($iconHref, $baseUrl);
                }
            }
        } catch (\Throwable) {
            // Fall through to favicon.ico fallback
        }

        try {
            $fallback = Http::timeout(10)->get("{$baseUrl}/favicon.ico");

            if ($fallback->successful() && str_starts_with($fallback->header('Content-Type', ''), 'image/')) {
                return "{$baseUrl}/favicon.ico";
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

    private function resolveUrl(string $href, string $baseUrl): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            return "https:{$href}";
        }

        if (str_starts_with($href, '/')) {
            return "{$baseUrl}{$href}";
        }

        return "{$baseUrl}/{$href}";
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
