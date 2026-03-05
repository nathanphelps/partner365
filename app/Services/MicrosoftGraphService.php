<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphService
{
    public function getAccessToken(): string
    {
        return Cache::remember('msgraph_access_token', 3500, function () {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
                [
                    'grant_type' => 'client_credentials',
                    'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                    'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                    'scope' => Setting::get('graph', 'scopes', config('graph.scopes')),
                ]
            );

            return $response->json('access_token');
        });
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, query: $query);
    }

    public function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, data: $data);
    }

    public function patch(string $path, array $data = []): array
    {
        return $this->request('PATCH', $path, data: $data);
    }

    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    private function request(string $method, string $path, array $data = [], array $query = []): array
    {
        $token = $this->getAccessToken();
        $baseUrl = Setting::get('graph', 'base_url', config('graph.base_url'));
        $url = $baseUrl . $path;

        $request = Http::withToken($token)->acceptJson();

        $response = match ($method) {
            'GET' => $request->get($url, $query),
            'POST' => $request->post($url, $data),
            'PATCH' => $request->patch($url, $data),
            'DELETE' => $request->delete($url),
        };

        if ($response->failed()) {
            throw GraphApiException::fromResponse($response->status(), $response->json() ?? []);
        }

        return $response->json() ?? [];
    }
}
