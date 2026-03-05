<?php

namespace App\Providers;

use App\Enums\CloudEnvironment;
use App\Models\Setting;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\Config;
use SocialiteProviders\Microsoft\Provider;

class SocialiteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Socialite::extend('microsoft', function ($app) {
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
            $clientId = Setting::get('graph', 'client_id', config('graph.client_id'));
            $clientSecret = Setting::get('graph', 'client_secret', config('graph.client_secret'));

            $cloudEnv = CloudEnvironment::tryFrom(
                Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
            ) ?? CloudEnvironment::Commercial;

            $redirectUrl = url('/auth/sso/callback');

            $provider = new Provider(
                $app['request'],
                $clientId,
                $clientSecret,
                $redirectUrl,
            );

            return $provider->setConfig(
                new Config(
                    $clientId,
                    $clientSecret,
                    $redirectUrl,
                    [
                        'tenant' => $tenantId,
                        'base_url' => 'https://'.$cloudEnv->loginUrl(),
                    ],
                )
            );
        });
    }
}
