<?php

namespace App\Http\Controllers\Auth;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SsoController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function redirect(): RedirectResponse
    {
        if (Setting::get('sso', 'enabled', 'false') !== 'true') {
            return redirect()->route('login')->with('error', 'SSO is not enabled.');
        }

        return Socialite::driver('microsoft')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (Setting::get('sso', 'enabled', 'false') !== 'true') {
            return redirect()->route('login')->with('error', 'SSO is not enabled.');
        }

        try {
            $socialiteUser = Socialite::driver('microsoft')->user();
        } catch (\Throwable) {
            return redirect()->route('login')->with('error', 'SSO authentication failed.');
        }

        $user = User::where('entra_id', $socialiteUser->id)->first()
            ?? User::where('email', $socialiteUser->email)->first();

        if ($user) {
            $user->update(array_filter([
                'name' => $socialiteUser->name,
                'entra_id' => $socialiteUser->id,
            ]));
        } else {
            $role = $this->resolveRole($socialiteUser->id);

            if ($role === null) {
                return redirect()->route('login')
                    ->with('error', 'Your account is not authorized. Contact an administrator.');
            }

            $user = User::create([
                'name' => $socialiteUser->name,
                'email' => $socialiteUser->email,
                'entra_id' => $socialiteUser->id,
                'role' => $role,
                'password' => bcrypt(str()->random(32)),
            ]);

            if (Setting::get('sso', 'auto_approve', 'false') === 'true') {
                $user->forceFill(['approved_at' => now()])->save();
            }
        }

        Auth::login($user, remember: true);

        $this->activityLog->log($user, ActivityAction::UserLoggedIn, null, [
            'method' => 'sso',
        ]);

        return redirect()->intended(route('dashboard'));
    }

    private function resolveRole(string $entraUserId): ?string
    {
        $defaultRole = Setting::get('sso', 'default_role', 'viewer');

        if (Setting::get('sso', 'group_mapping_enabled', 'false') !== 'true') {
            return $defaultRole;
        }

        $mappings = json_decode(Setting::get('sso', 'group_mappings', '[]'), true);

        if (empty($mappings)) {
            return $defaultRole;
        }

        $userGroups = $this->getUserGroups($entraUserId);
        $matchedMappings = collect($mappings)->filter(
            fn ($m) => in_array($m['entra_group_id'], $userGroups)
        );

        if ($matchedMappings->isEmpty()) {
            if (Setting::get('sso', 'restrict_provisioning_to_mapped_groups', 'false') === 'true') {
                return null;
            }

            return $defaultRole;
        }

        $rolePriority = ['admin' => 3, 'operator' => 2, 'viewer' => 1];

        return $matchedMappings
            ->sortByDesc(fn ($m) => $rolePriority[$m['role']] ?? 0)
            ->first()['role'];
    }

    private function getUserGroups(string $entraUserId): array
    {
        try {
            $graphService = app(\App\Services\MicrosoftGraphService::class);
            $response = $graphService->get("/users/{$entraUserId}/memberOf", [
                '$select' => 'id',
                '$top' => '999',
            ]);

            return collect($response['value'] ?? [])->pluck('id')->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
