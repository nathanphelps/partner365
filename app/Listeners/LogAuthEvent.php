<?php

namespace App\Listeners;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;

class LogAuthEvent
{
    public static function register(): void
    {
        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                ActivityLog::create([
                    'user_id' => $event->user->id,
                    'action' => ActivityAction::UserLoggedIn,
                    'details' => ['ip' => request()->ip()],
                    'created_at' => now(),
                ]);
            }
        });

        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user instanceof User) {
                ActivityLog::create([
                    'user_id' => $event->user->id,
                    'action' => ActivityAction::UserLoggedOut,
                    'details' => ['ip' => request()->ip()],
                    'created_at' => now(),
                ]);
            }
        });

        Event::listen(Failed::class, function (Failed $event) {
            ActivityLog::create([
                'user_id' => $event->user?->id,
                'action' => ActivityAction::LoginFailed,
                'details' => [
                    'email' => $event->credentials['email'] ?? null,
                    'ip' => request()->ip(),
                ],
                'created_at' => now(),
            ]);
        });

        Event::listen(Lockout::class, function (Lockout $event) {
            ActivityLog::create([
                'user_id' => null,
                'action' => ActivityAction::AccountLocked,
                'details' => [
                    'email' => $event->request->input('email'),
                    'ip' => $event->request->ip(),
                ],
                'created_at' => now(),
            ]);
        });

        Event::listen(\Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed::class, function ($event) {
            ActivityLog::create([
                'user_id' => $event->user->id,
                'action' => ActivityAction::TwoFactorEnabled,
                'created_at' => now(),
            ]);
        });

        Event::listen(\Laravel\Fortify\Events\TwoFactorAuthenticationDisabled::class, function ($event) {
            ActivityLog::create([
                'user_id' => $event->user->id,
                'action' => ActivityAction::TwoFactorDisabled,
                'created_at' => now(),
            ]);
        });
    }
}
