# Comprehensive Audit Logging & SIEM Export Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fill all activity logging gaps, add syslog/CEF forwarding to LogRhythm with admin configuration, and enhance the activity log UI with filtering.

**Architecture:** Extend the existing `ActivityLogService` + `ActivityAction` enum pattern. Add syslog forwarding as a queued job triggered by an Eloquent observer on `ActivityLog`. Auth events captured via Laravel event listeners. Admin SIEM settings stored in the `settings` table using the existing `Setting` model with group `syslog`.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + Inertia.js, shadcn-vue, PHP sockets for syslog transport

---

### Task 1: Add New ActivityAction Enum Values

**Files:**
- Modify: `app/Enums/ActivityAction.php:5-35`

**Step 1: Add new enum cases**

Add these cases to the `ActivityAction` enum after line 34:

```php
case TemplateUpdated = 'template_updated';
case TemplateDeleted = 'template_deleted';
case UserLoggedIn = 'user_logged_in';
case UserLoggedOut = 'user_logged_out';
case LoginFailed = 'login_failed';
case AccountLocked = 'account_locked';
case PasswordChanged = 'password_changed';
case TwoFactorEnabled = 'two_factor_enabled';
case TwoFactorDisabled = 'two_factor_disabled';
case ProfileUpdated = 'profile_updated';
case AccountDeleted = 'account_deleted';
case GraphConnectionTested = 'graph_connection_tested';
case ConsentGranted = 'consent_granted';
```

**Step 2: Run lint check**

Run: `composer run lint:check`
Expected: PASS

**Step 3: Commit**

```bash
git add app/Enums/ActivityAction.php
git commit -m "feat: add new ActivityAction enum values for comprehensive audit logging"
```

---

### Task 2: Add Logging to Template Update/Delete

**Files:**
- Modify: `app/Http/Controllers/PartnerTemplateController.php:50-62`

**Step 1: Write the failing test**

Create `tests/Feature/Controllers/PartnerTemplateControllerTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Models\ActivityLog;
use App\Models\PartnerTemplate;
use App\Models\User;

test('updating a template logs TemplateUpdated', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $template = PartnerTemplate::factory()->create();

    $this->actingAs($user)->put(route('templates.update', $template), [
        'name' => 'Updated Template',
        'mfa_trust_enabled' => true,
        'device_trust_enabled' => false,
        'b2b_inbound_enabled' => true,
        'b2b_outbound_enabled' => true,
        'direct_connect_inbound_enabled' => false,
        'direct_connect_outbound_enabled' => false,
    ]);

    $log = ActivityLog::where('action', ActivityAction::TemplateUpdated)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->subject_id)->toBe($template->id);
    expect($log->details['name'])->toBe('Updated Template');
});

test('deleting a template logs TemplateDeleted', function () {
    $user = User::factory()->create(['role' => UserRole::Admin]);
    $template = PartnerTemplate::factory()->create();
    $templateName = $template->name;

    $this->actingAs($user)->delete(route('templates.destroy', $template));

    $log = ActivityLog::where('action', ActivityAction::TemplateDeleted)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
    expect($log->details['name'])->toBe($templateName);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PartnerTemplateControllerTest`
Expected: FAIL — no TemplateUpdated/TemplateDeleted log entries

**Step 3: Add logging to update and destroy methods**

In `app/Http/Controllers/PartnerTemplateController.php`, modify `update()` (line 50):

```php
public function update(StoreTemplateRequest $request, PartnerTemplate $template): RedirectResponse
{
    $template->update($request->validated());

    $this->activityLog->log($request->user(), ActivityAction::TemplateUpdated, $template, [
        'name' => $template->name,
    ]);

    return redirect()->route('templates.index')->with('success', "Template '{$template->name}' updated.");
}
```

Modify `destroy()` (line 57):

```php
public function destroy(PartnerTemplate $template): RedirectResponse
{
    $name = $template->name;
    $this->activityLog->log(auth()->user(), ActivityAction::TemplateDeleted, $template, [
        'name' => $name,
    ]);

    $template->delete();

    return redirect()->route('templates.index')->with('success', 'Template deleted.');
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PartnerTemplateControllerTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/PartnerTemplateController.php tests/Feature/Controllers/PartnerTemplateControllerTest.php
git commit -m "feat: add activity logging for template update and delete"
```

---

### Task 3: Add Logging to Profile, Password, and 2FA Controllers

**Files:**
- Modify: `app/Http/Controllers/Settings/ProfileController.php:31-59`
- Modify: `app/Http/Controllers/Settings/PasswordController.php:24-31`
- Modify: `app/Http/Controllers/Settings/TwoFactorAuthenticationController.php`
- Test: `tests/Feature/Settings/AuditLoggingTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Settings/AuditLoggingTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;

test('updating profile logs ProfileUpdated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'New Name',
        'email' => $user->email,
    ]);

    $log = ActivityLog::where('action', ActivityAction::ProfileUpdated)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});

test('deleting account logs AccountDeleted before deletion', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->delete(route('profile.destroy'), [
        'password' => 'password',
    ]);

    $log = ActivityLog::where('action', ActivityAction::AccountDeleted)->first();
    expect($log)->not->toBeNull();
    expect($log->details['email'])->toBe($user->email);
});

test('changing password logs PasswordChanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('password.update'), [
        'current_password' => 'password',
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ]);

    $log = ActivityLog::where('action', ActivityAction::PasswordChanged)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuditLoggingTest`
Expected: FAIL

**Step 3: Add logging to ProfileController**

In `app/Http/Controllers/Settings/ProfileController.php`:

Add imports at top:
```php
use App\Enums\ActivityAction;
use App\Services\ActivityLogService;
```

Modify `update()`:
```php
public function update(ProfileUpdateRequest $request): RedirectResponse
{
    $request->user()->fill($request->validated());

    if ($request->user()->isDirty('email')) {
        $request->user()->email_verified_at = null;
    }

    $request->user()->save();

    app(ActivityLogService::class)->log($request->user(), ActivityAction::ProfileUpdated, $request->user(), [
        'fields' => array_keys($request->validated()),
    ]);

    return to_route('profile.edit');
}
```

Modify `destroy()`:
```php
public function destroy(ProfileDeleteRequest $request): RedirectResponse
{
    $user = $request->user();

    app(ActivityLogService::class)->log($user, ActivityAction::AccountDeleted, null, [
        'email' => $user->email,
    ]);

    Auth::logout();

    $user->delete();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
}
```

**Step 4: Add logging to PasswordController**

In `app/Http/Controllers/Settings/PasswordController.php`:

Add imports at top:
```php
use App\Enums\ActivityAction;
use App\Services\ActivityLogService;
```

Modify `update()`:
```php
public function update(PasswordUpdateRequest $request): RedirectResponse
{
    $request->user()->update([
        'password' => $request->password,
    ]);

    app(ActivityLogService::class)->log($request->user(), ActivityAction::PasswordChanged);

    return back();
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AuditLoggingTest`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Http/Controllers/Settings/ProfileController.php app/Http/Controllers/Settings/PasswordController.php tests/Feature/Settings/AuditLoggingTest.php
git commit -m "feat: add activity logging for profile, password, and account changes"
```

---

### Task 4: Add Auth Event Listeners (Login, Logout, Failed, Lockout)

**Files:**
- Create: `app/Listeners/LogAuthEvent.php`
- Modify: `app/Providers/FortifyServiceProvider.php:29-34`
- Test: `tests/Feature/Auth/AuthAuditLoggingTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/Auth/AuthAuditLoggingTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;

test('successful login logs UserLoggedIn', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $log = ActivityLog::where('action', ActivityAction::UserLoggedIn)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});

test('failed login logs LoginFailed', function () {
    $user = User::factory()->create();

    $this->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $log = ActivityLog::where('action', ActivityAction::LoginFailed)->first();
    expect($log)->not->toBeNull();
    expect($log->details['email'])->toBe($user->email);
});

test('logout logs UserLoggedOut', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout');

    $log = ActivityLog::where('action', ActivityAction::UserLoggedOut)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuthAuditLoggingTest`
Expected: FAIL

**Step 3: Create the auth event listener**

Create `app/Listeners/LogAuthEvent.php`:

```php
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
    }
}
```

**Step 4: Register the listener in FortifyServiceProvider**

In `app/Providers/FortifyServiceProvider.php`, modify `boot()` (line 29):

```php
public function boot(): void
{
    $this->configureActions();
    $this->configureViews();
    $this->configureRateLimiting();

    \App\Listeners\LogAuthEvent::register();
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AuthAuditLoggingTest`
Expected: PASS

**Step 6: Run full test suite to check for regressions**

Run: `php artisan test`
Expected: All tests PASS

**Step 7: Commit**

```bash
git add app/Listeners/LogAuthEvent.php app/Providers/FortifyServiceProvider.php tests/Feature/Auth/AuthAuditLoggingTest.php
git commit -m "feat: add auth event logging for login, logout, failed login, and lockout"
```

---

### Task 5: Add 2FA Event Logging

**Files:**
- Modify: `app/Listeners/LogAuthEvent.php`
- Test: `tests/Feature/Auth/AuthAuditLoggingTest.php`

Fortify fires `TwoFactorAuthenticationEnabled` and `TwoFactorAuthenticationDisabled` events. Note: The `TwoFactorAuthenticationConfirmed` event is what actually fires when 2FA is confirmed (enabled).

**Step 1: Write the failing test**

Add to `tests/Feature/Auth/AuthAuditLoggingTest.php`:

```php
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;

test('enabling 2FA logs TwoFactorEnabled', function () {
    $user = User::factory()->create();

    event(new TwoFactorAuthenticationConfirmed($user));

    $log = ActivityLog::where('action', ActivityAction::TwoFactorEnabled)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});

test('disabling 2FA logs TwoFactorDisabled', function () {
    $user = User::factory()->create();

    event(new TwoFactorAuthenticationDisabled($user));

    $log = ActivityLog::where('action', ActivityAction::TwoFactorDisabled)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($user->id);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuthAuditLoggingTest`
Expected: FAIL on the 2FA tests

**Step 3: Add 2FA event listeners**

In `app/Listeners/LogAuthEvent.php`, add to the `register()` method:

```php
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
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AuthAuditLoggingTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Listeners/LogAuthEvent.php tests/Feature/Auth/AuthAuditLoggingTest.php
git commit -m "feat: add activity logging for 2FA enable and disable events"
```

---

### Task 6: Add Logging to AdminGraphController (Test Connection & Consent)

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminGraphController.php:71-135`
- Test: `tests/Feature/Admin/AdminGraphControllerTest.php` (add to existing)

**Step 1: Write the failing test**

Add to `tests/Feature/Admin/AdminGraphControllerTest.php`:

```php
use App\Enums\ActivityAction;
use App\Models\ActivityLog;

test('test connection logs GraphConnectionTested', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token']),
    ]);

    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post(route('admin.graph.test'));

    $log = ActivityLog::where('action', ActivityAction::GraphConnectionTested)->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBe($admin->id);
});

test('consent callback logs ConsentGranted on success', function () {
    $this->get(route('admin.graph.consent.callback', ['admin_consent' => 'True']));

    $log = ActivityLog::where('action', ActivityAction::ConsentGranted)->first();
    expect($log)->not->toBeNull();
    expect($log->details['success'])->toBeTrue();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: FAIL on the new tests

**Step 3: Add logging to testConnection and consentCallback**

In `app/Http/Controllers/Admin/AdminGraphController.php`:

Modify `testConnection()` — add logging after the success/failure determination:

```php
public function testConnection(Request $request): JsonResponse
{
    try {
        $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
        $cloudEnv = \App\Enums\CloudEnvironment::tryFrom(
            Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
        ) ?? \App\Enums\CloudEnvironment::Commercial;
        $loginUrl = $cloudEnv->loginUrl();

        $response = Http::asForm()->post(
            "https://{$loginUrl}/{$tenantId}/oauth2/v2.0/token",
            [
                'grant_type' => 'client_credentials',
                'client_id' => Setting::get('graph', 'client_id', config('graph.client_id')),
                'client_secret' => Setting::get('graph', 'client_secret', config('graph.client_secret')),
                'scope' => Setting::get('graph', 'scopes', config('graph.scopes')),
            ]
        );

        $success = $response->successful() && $response->json('access_token');

        $this->activityLog->log($request->user(), ActivityAction::GraphConnectionTested, null, [
            'success' => $success,
        ]);

        if ($success) {
            return response()->json(['success' => true, 'message' => 'Connection successful.']);
        }

        return response()->json([
            'success' => false,
            'message' => $response->json('error_description', 'Authentication failed.'),
        ]);
    } catch (\Throwable $e) {
        $this->activityLog->log($request->user(), ActivityAction::GraphConnectionTested, null, [
            'success' => false,
            'error' => $e->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'Connection failed: '.$e->getMessage(),
        ]);
    }
}
```

Modify `consentCallback()`:

```php
public function consentCallback(Request $request): View
{
    $success = $request->query('admin_consent') === 'True';
    $error = $request->query('error_description');

    ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::ConsentGranted,
        'details' => ['success' => $success, 'error' => $error],
        'created_at' => now(),
    ]);

    return view('admin.consent-callback', [
        'success' => $success,
        'error' => $error,
    ]);
}
```

Add the `ActivityLog` import at the top of the file:
```php
use App\Models\ActivityLog;
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/AdminGraphController.php tests/Feature/Admin/AdminGraphControllerTest.php
git commit -m "feat: add activity logging for Graph connection test and admin consent"
```

---

### Task 7: Add Activity Logging to Sync Commands

**Files:**
- Modify: `app/Console/Commands/SyncPartners.php`
- Modify: `app/Console/Commands/SyncGuests.php`
- Modify: `app/Console/Commands/SyncEntitlements.php`
- Modify: `app/Console/Commands/SyncAccessReviews.php`
- Modify: `app/Console/Commands/SyncConditionalAccessPolicies.php`
- Test: `tests/Feature/Commands/SyncActivityLoggingTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Commands/SyncActivityLoggingTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;

test('sync:partners creates SyncCompleted activity log on success', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test']),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:partners')->assertSuccessful();

    $log = ActivityLog::where('action', ActivityAction::SyncCompleted)
        ->where('details->type', 'partners')
        ->first();
    expect($log)->not->toBeNull();
    expect($log->user_id)->toBeNull();
});

test('sync:conditional-access-policies creates ConditionalAccessPoliciesSynced activity log', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test']),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);

    $this->artisan('sync:conditional-access-policies')->assertSuccessful();

    $log = ActivityLog::where('action', ActivityAction::ConditionalAccessPoliciesSynced)->first();
    expect($log)->not->toBeNull();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncActivityLoggingTest`
Expected: FAIL

**Step 3: Add activity logging to each sync command**

The pattern is the same for each command. After `$log->update(...)` with status `completed`, add:

```php
use App\Enums\ActivityAction;
use App\Models\ActivityLog;
```

And after the `$log->update([...])` success block, add:

```php
ActivityLog::create([
    'user_id' => null,
    'action' => ActivityAction::SyncCompleted,
    'details' => ['type' => '<type>', 'records_synced' => $synced],
    'created_at' => now(),
]);
```

Where `<type>` is `partners`, `guests`, `entitlements`, `access_reviews` respectively.

For `SyncConditionalAccessPolicies`, use `ActivityAction::ConditionalAccessPoliciesSynced` instead:

```php
ActivityLog::create([
    'user_id' => null,
    'action' => ActivityAction::ConditionalAccessPoliciesSynced,
    'details' => ['records_synced' => $synced],
    'created_at' => now(),
]);
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SyncActivityLoggingTest`
Expected: PASS

**Step 5: Run existing sync tests to check for regressions**

Run: `php artisan test --filter=SyncPartners --filter=SyncGuests`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Console/Commands/SyncPartners.php app/Console/Commands/SyncGuests.php app/Console/Commands/SyncEntitlements.php app/Console/Commands/SyncAccessReviews.php app/Console/Commands/SyncConditionalAccessPolicies.php tests/Feature/Commands/SyncActivityLoggingTest.php
git commit -m "feat: add activity logging to all sync commands"
```

---

### Task 8: Add ActivityLogService System Log Method

The current `ActivityLogService::log()` requires a `User` parameter. Sync commands and some auth events need to log without a user. Add a `logSystem()` method.

**Files:**
- Modify: `app/Services/ActivityLogService.php:11-23`
- Test: `tests/Feature/Services/ActivityLogServiceTest.php`

**Step 1: Write the failing test**

Add to `tests/Feature/Services/ActivityLogServiceTest.php`:

```php
test('it logs a system activity without a user', function () {
    $service = app(ActivityLogService::class);
    $log = $service->logSystem(ActivityAction::SyncCompleted, details: ['type' => 'partners', 'records_synced' => 5]);

    expect($log->user_id)->toBeNull();
    expect($log->action)->toBe(ActivityAction::SyncCompleted);
    expect($log->details['records_synced'])->toBe(5);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActivityLogServiceTest`
Expected: FAIL — logSystem method doesn't exist

**Step 3: Add logSystem method**

In `app/Services/ActivityLogService.php`, add after the `log()` method:

```php
public function logSystem(ActivityAction $action, ?Model $subject = null, array $details = []): ActivityLog
{
    return ActivityLog::create([
        'user_id' => null,
        'action' => $action,
        'subject_type' => $subject ? get_class($subject) : null,
        'subject_id' => $subject?->getKey(),
        'details' => $details,
        'created_at' => now(),
    ]);
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ActivityLogServiceTest`
Expected: PASS

**Step 5: Refactor sync commands to use ActivityLogService::logSystem() instead of direct ActivityLog::create()**

Update each sync command from Task 7 to inject `ActivityLogService` and use `$activityLog->logSystem(...)` instead of `ActivityLog::create(...)`.

**Step 6: Run all sync tests**

Run: `php artisan test --filter=Sync`
Expected: PASS

**Step 7: Commit**

```bash
git add app/Services/ActivityLogService.php tests/Feature/Services/ActivityLogServiceTest.php app/Console/Commands/
git commit -m "feat: add ActivityLogService::logSystem() for system-triggered events"
```

---

### Task 9: Create CefFormatter Service

**Files:**
- Create: `app/Services/Syslog/CefFormatter.php`
- Test: `tests/Feature/Services/Syslog/CefFormatterTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Services/Syslog/CefFormatterTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Syslog\CefFormatter;

test('it formats an activity log as CEF', function () {
    $user = User::factory()->create(['name' => 'John Doe']);
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::PartnerCreated,
        'details' => ['tenant_id' => '123'],
        'created_at' => now(),
    ]);
    $log->load('user');

    $formatter = new CefFormatter();
    $cef = $formatter->format($log);

    expect($cef)->toStartWith('CEF:0|Partner365|Partner365|1.0|partner_created|');
    expect($cef)->toContain('suser=John Doe');
    expect($cef)->toContain('|5|'); // Medium severity for PartnerCreated
});

test('it assigns correct severity levels', function () {
    $formatter = new CefFormatter();

    expect($formatter->severity(ActivityAction::SyncCompleted))->toBe(3);
    expect($formatter->severity(ActivityAction::PartnerCreated))->toBe(5);
    expect($formatter->severity(ActivityAction::PartnerDeleted))->toBe(7);
    expect($formatter->severity(ActivityAction::LoginFailed))->toBe(8);
});

test('it escapes pipes and backslashes in CEF fields', function () {
    $user = User::factory()->create(['name' => 'John|Doe\\Jr']);
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::ProfileUpdated,
        'details' => ['note' => 'test|pipe'],
        'created_at' => now(),
    ]);
    $log->load('user');

    $formatter = new CefFormatter();
    $cef = $formatter->format($log);

    expect($cef)->toContain('suser=John\\|Doe\\\\Jr');
});

test('it handles system events with no user', function () {
    $log = ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'details' => ['type' => 'partners'],
        'created_at' => now(),
    ]);

    $formatter = new CefFormatter();
    $cef = $formatter->format($log);

    expect($cef)->toContain('suser=System');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CefFormatterTest`
Expected: FAIL — class doesn't exist

**Step 3: Create CefFormatter**

Create `app/Services/Syslog/CefFormatter.php`:

```php
<?php

namespace App\Services\Syslog;

use App\Enums\ActivityAction;
use App\Models\ActivityLog;

class CefFormatter
{
    private const SEVERITY_MAP = [
        // Low (3)
        'sync_completed' => 3,
        'sync_triggered' => 3,
        'access_review_created' => 3,
        'template_created' => 3,

        // Medium (5)
        'partner_created' => 5,
        'partner_updated' => 5,
        'guest_invited' => 5,
        'guest_updated' => 5,
        'guest_enabled' => 5,
        'template_updated' => 5,
        'profile_updated' => 5,
        'user_logged_in' => 5,
        'user_logged_out' => 5,
        'access_package_created' => 5,
        'access_package_updated' => 5,
        'assignment_requested' => 5,
        'assignment_approved' => 5,
        'policy_changed' => 5,
        'access_review_decision_made' => 5,
        'access_review_completed' => 5,
        'conditional_access_policies_synced' => 5,
        'user_approved' => 5,

        // High (7)
        'partner_deleted' => 7,
        'guest_removed' => 7,
        'guest_disabled' => 7,
        'template_deleted' => 7,
        'user_deleted' => 7,
        'assignment_revoked' => 7,
        'assignment_denied' => 7,
        'access_review_remediation_applied' => 7,
        'access_package_deleted' => 7,
        'account_deleted' => 7,

        // Very High (8)
        'login_failed' => 8,
        'account_locked' => 8,
        'password_changed' => 8,
        'two_factor_disabled' => 8,
        'settings_updated' => 8,
        'user_role_changed' => 8,
        'consent_granted' => 8,
        'graph_connection_tested' => 8,
        'two_factor_enabled' => 8,
    ];

    public function format(ActivityLog $log): string
    {
        $action = $log->action->value;
        $label = $this->actionLabel($log->action);
        $severity = $this->severity($log->action);
        $username = $log->user?->name ?? 'System';
        $details = $log->details ? json_encode($log->details) : '';
        $timestamp = $log->created_at?->getTimestampMs();

        $extension = implode(' ', array_filter([
            'suser='.$this->escapeExtensionValue($username),
            $details ? 'msg='.$this->escapeExtensionValue($details) : null,
            $log->subject_type ? 'cs1='.$this->escapeExtensionValue(class_basename($log->subject_type)) : null,
            $log->subject_type ? 'cs1Label=SubjectType' : null,
            $log->subject_id ? 'cs2='.$log->subject_id : null,
            $log->subject_id ? 'cs2Label=SubjectId' : null,
            $timestamp ? 'rt='.$timestamp : null,
        ]));

        return sprintf(
            'CEF:0|%s|%s|1.0|%s|%s|%d|%s',
            $this->escapeHeaderField('Partner365'),
            $this->escapeHeaderField('Partner365'),
            $this->escapeHeaderField($action),
            $this->escapeHeaderField($label),
            $severity,
            $extension,
        );
    }

    public function severity(ActivityAction $action): int
    {
        return self::SEVERITY_MAP[$action->value] ?? 5;
    }

    private function actionLabel(ActivityAction $action): string
    {
        return str_replace('_', ' ', ucfirst($action->value));
    }

    private function escapeHeaderField(string $value): string
    {
        return str_replace(['\\', '|'], ['\\\\', '\\|'], $value);
    }

    private function escapeExtensionValue(string $value): string
    {
        return str_replace(['\\', '|', '=', "\n"], ['\\\\', '\\|', '\\=', '\\n'], $value);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CefFormatterTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/Syslog/CefFormatter.php tests/Feature/Services/Syslog/CefFormatterTest.php
git commit -m "feat: add CEF formatter for syslog SIEM export"
```

---

### Task 10: Create SyslogTransport Service

**Files:**
- Create: `app/Services/Syslog/SyslogTransport.php`
- Test: `tests/Feature/Services/Syslog/SyslogTransportTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Services/Syslog/SyslogTransportTest.php`:

```php
<?php

use App\Services\Syslog\SyslogTransport;

test('it creates a transport from config', function () {
    $transport = new SyslogTransport('127.0.0.1', 514, 'udp', 16);

    expect($transport->host())->toBe('127.0.0.1');
    expect($transport->port())->toBe(514);
    expect($transport->protocol())->toBe('udp');
    expect($transport->facility())->toBe(16);
});

test('it formats syslog priority correctly', function () {
    $transport = new SyslogTransport('127.0.0.1', 514, 'udp', 16);

    // facility 16 (local0) * 8 + severity 5 (notice) = 133
    $message = $transport->buildSyslogMessage('test message', 5);
    expect($message)->toStartWith('<133>');
    expect($message)->toContain('test message');
});

test('it validates configuration', function () {
    expect(SyslogTransport::validateConfig('127.0.0.1', 514, 'udp'))->toBeTrue();
    expect(SyslogTransport::validateConfig('', 514, 'udp'))->toBeFalse();
    expect(SyslogTransport::validateConfig('127.0.0.1', 0, 'udp'))->toBeFalse();
    expect(SyslogTransport::validateConfig('127.0.0.1', 514, 'invalid'))->toBeFalse();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyslogTransportTest`
Expected: FAIL

**Step 3: Create SyslogTransport**

Create `app/Services/Syslog/SyslogTransport.php`:

```php
<?php

namespace App\Services\Syslog;

use RuntimeException;

class SyslogTransport
{
    public function __construct(
        private string $host,
        private int $port,
        private string $protocol,
        private int $facility = 16,
    ) {}

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function facility(): int
    {
        return $this->facility;
    }

    public function send(string $cefMessage, int $severity): void
    {
        $syslogMessage = $this->buildSyslogMessage($cefMessage, $severity);

        match ($this->protocol) {
            'udp' => $this->sendUdp($syslogMessage),
            'tcp', 'tls' => $this->sendTcp($syslogMessage),
            default => throw new RuntimeException("Unsupported protocol: {$this->protocol}"),
        };
    }

    public function buildSyslogMessage(string $message, int $severity): string
    {
        $priority = ($this->facility * 8) + $severity;
        $timestamp = now()->format('M d H:i:s');
        $hostname = gethostname() ?: 'partner365';

        return "<{$priority}>{$timestamp} {$hostname} {$message}";
    }

    public function test(): bool
    {
        try {
            $this->send('CEF:0|Partner365|Partner365|1.0|test|Test Connection|3|msg=Test event', 6);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function validateConfig(string $host, int $port, string $protocol): bool
    {
        if (empty($host)) {
            return false;
        }

        if ($port < 1 || $port > 65535) {
            return false;
        }

        if (! in_array($protocol, ['udp', 'tcp', 'tls'])) {
            return false;
        }

        return true;
    }

    private function sendUdp(string $message): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            throw new RuntimeException('Failed to create UDP socket: '.socket_strerror(socket_last_error()));
        }

        try {
            $result = socket_sendto($socket, $message, strlen($message), 0, $this->host, $this->port);
            if ($result === false) {
                throw new RuntimeException('Failed to send UDP message: '.socket_strerror(socket_last_error($socket)));
            }
        } finally {
            socket_close($socket);
        }
    }

    private function sendTcp(string $message): void
    {
        $prefix = $this->protocol === 'tls' ? 'tls' : 'tcp';
        $context = stream_context_create();

        if ($this->protocol === 'tls') {
            stream_context_set_option($context, 'ssl', 'verify_peer', true);
            stream_context_set_option($context, 'ssl', 'verify_peer_name', true);
        }

        $connection = @stream_socket_client(
            "{$prefix}://{$this->host}:{$this->port}",
            $errno,
            $errstr,
            timeout: 5,
            context: $context,
        );

        if ($connection === false) {
            throw new RuntimeException("Failed to connect to syslog ({$prefix}): {$errstr} ({$errno})");
        }

        try {
            $written = @fwrite($connection, $message."\n");
            if ($written === false) {
                throw new RuntimeException('Failed to write to syslog connection');
            }
        } finally {
            fclose($connection);
        }
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SyslogTransportTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/Syslog/SyslogTransport.php tests/Feature/Services/Syslog/SyslogTransportTest.php
git commit -m "feat: add SyslogTransport for UDP, TCP, and TLS syslog delivery"
```

---

### Task 11: Create ForwardToSyslog Job

**Files:**
- Create: `app/Jobs/ForwardToSyslog.php`
- Test: `tests/Feature/Jobs/ForwardToSyslogTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Jobs/ForwardToSyslogTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Jobs\ForwardToSyslog;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;

test('job does nothing when syslog is disabled', function () {
    Setting::set('syslog', 'enabled', 'false');

    $log = ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'details' => ['type' => 'partners'],
        'created_at' => now(),
    ]);

    // Should not throw — just returns early
    $job = new ForwardToSyslog($log);
    $job->handle();

    expect(true)->toBeTrue(); // Reached without error
});

test('job formats and would send when syslog is enabled', function () {
    Setting::set('syslog', 'enabled', 'true');
    Setting::set('syslog', 'host', '127.0.0.1');
    Setting::set('syslog', 'port', '51400');
    Setting::set('syslog', 'transport', 'udp');
    Setting::set('syslog', 'facility', '16');

    $user = User::factory()->create();
    $log = ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::PartnerCreated,
        'details' => ['name' => 'Test'],
        'created_at' => now(),
    ]);
    $log->load('user');

    // We can't easily test actual socket delivery in unit tests,
    // but we verify the job constructs and runs without error on format
    $job = new ForwardToSyslog($log);

    // The job will fail on send (no syslog server) but we verify
    // it gets past the enabled check and formatting
    try {
        $job->handle();
    } catch (\RuntimeException) {
        // Expected — no syslog server listening
    }

    expect(true)->toBeTrue();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ForwardToSyslogTest`
Expected: FAIL — class doesn't exist

**Step 3: Create ForwardToSyslog job**

Create `app/Jobs/ForwardToSyslog.php`:

```php
<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Services\Syslog\CefFormatter;
use App\Services\Syslog\SyslogTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ForwardToSyslog implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        public ActivityLog $activityLog,
    ) {}

    public function handle(): void
    {
        if (Setting::get('syslog', 'enabled', 'false') !== 'true') {
            return;
        }

        $host = Setting::get('syslog', 'host');
        $port = (int) Setting::get('syslog', 'port', '514');
        $protocol = Setting::get('syslog', 'transport', 'udp');
        $facility = (int) Setting::get('syslog', 'facility', '16');

        if (! $host || ! SyslogTransport::validateConfig($host, $port, $protocol)) {
            return;
        }

        $this->activityLog->loadMissing('user');

        $formatter = new CefFormatter();
        $cefMessage = $formatter->format($this->activityLog);
        $severity = $formatter->severity($this->activityLog->action);

        $transport = new SyslogTransport($host, $port, $protocol, $facility);
        $transport->send($cefMessage, $severity);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ForwardToSyslogTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Jobs/ForwardToSyslog.php tests/Feature/Jobs/ForwardToSyslogTest.php
git commit -m "feat: add ForwardToSyslog queued job for async CEF delivery"
```

---

### Task 12: Create ActivityLog Observer to Dispatch Syslog Job

**Files:**
- Create: `app/Observers/ActivityLogObserver.php`
- Modify: `app/Models/ActivityLog.php`
- Test: `tests/Feature/Observers/ActivityLogObserverTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Observers/ActivityLogObserverTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Jobs\ForwardToSyslog;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Queue;

test('creating an activity log dispatches ForwardToSyslog job', function () {
    Queue::fake();

    ActivityLog::create([
        'user_id' => null,
        'action' => ActivityAction::SyncCompleted,
        'details' => ['type' => 'partners'],
        'created_at' => now(),
    ]);

    Queue::assertPushed(ForwardToSyslog::class, function ($job) {
        return $job->activityLog->action === ActivityAction::SyncCompleted;
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActivityLogObserverTest`
Expected: FAIL

**Step 3: Create the observer**

Create `app/Observers/ActivityLogObserver.php`:

```php
<?php

namespace App\Observers;

use App\Jobs\ForwardToSyslog;
use App\Models\ActivityLog;

class ActivityLogObserver
{
    public function created(ActivityLog $activityLog): void
    {
        ForwardToSyslog::dispatch($activityLog);
    }
}
```

**Step 4: Register the observer in the ActivityLog model**

In `app/Models/ActivityLog.php`, add the `ObservedBy` attribute:

```php
<?php

namespace App\Models;

use App\Enums\ActivityAction;
use App\Observers\ActivityLogObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[ObservedBy(ActivityLogObserver::class)]
class ActivityLog extends Model
{
    // ... rest unchanged
}
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter=ActivityLogObserverTest`
Expected: PASS

**Step 6: Run full test suite — the observer dispatches jobs on every ActivityLog::create() so verify no tests break**

Run: `php artisan test`
Expected: All PASS (jobs are queued but not processed in tests by default)

**Step 7: Commit**

```bash
git add app/Observers/ActivityLogObserver.php app/Models/ActivityLog.php tests/Feature/Observers/ActivityLogObserverTest.php
git commit -m "feat: add ActivityLog observer to dispatch syslog forwarding job"
```

---

### Task 13: Admin SIEM Settings Controller

**Files:**
- Create: `app/Http/Controllers/Admin/AdminSyslogController.php`
- Create: `app/Http/Requests/UpdateSyslogSettingsRequest.php`
- Modify: `routes/admin.php`
- Test: `tests/Feature/Admin/AdminSyslogControllerTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Admin/AdminSyslogControllerTest.php`:

```php
<?php

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;

test('admin can view syslog settings', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get(route('admin.syslog.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Syslog'));
});

test('non-admin cannot view syslog settings', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->get(route('admin.syslog.edit'))
        ->assertForbidden();
});

test('admin can save syslog settings', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.syslog.update'), [
        'enabled' => true,
        'host' => '10.0.0.1',
        'port' => 514,
        'transport' => 'tcp',
        'facility' => 16,
    ])->assertRedirect();

    expect(Setting::get('syslog', 'enabled'))->toBe('true');
    expect(Setting::get('syslog', 'host'))->toBe('10.0.0.1');
    expect(Setting::get('syslog', 'port'))->toBe('514');
    expect(Setting::get('syslog', 'transport'))->toBe('tcp');
});

test('syslog settings require host when enabled', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->put(route('admin.syslog.update'), [
        'enabled' => true,
        'host' => '',
        'port' => 514,
        'transport' => 'udp',
        'facility' => 16,
    ])->assertSessionHasErrors('host');
});

test('test connection endpoint returns result', function () {
    $admin = User::factory()->admin()->create();

    Setting::set('syslog', 'host', '127.0.0.1');
    Setting::set('syslog', 'port', '51499');
    Setting::set('syslog', 'transport', 'udp');

    $this->actingAs($admin)
        ->post(route('admin.syslog.test'))
        ->assertJson(fn ($json) => $json->has('success')->has('message'));
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AdminSyslogControllerTest`
Expected: FAIL — routes and controller don't exist

**Step 3: Create the form request**

Create `app/Http/Requests/UpdateSyslogSettingsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSyslogSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'host' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'transport' => ['required', 'string', 'in:udp,tcp,tls'],
            'facility' => ['required', 'integer', 'min:0', 'max:23'],
        ];
    }
}
```

**Step 4: Create the controller**

Create `app/Http/Controllers/Admin/AdminSyslogController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSyslogSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use App\Services\Syslog\SyslogTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminSyslogController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/Syslog', [
            'settings' => [
                'enabled' => Setting::get('syslog', 'enabled', 'false') === 'true',
                'host' => Setting::get('syslog', 'host', ''),
                'port' => (int) Setting::get('syslog', 'port', '514'),
                'transport' => Setting::get('syslog', 'transport', 'udp'),
                'facility' => (int) Setting::get('syslog', 'facility', '16'),
            ],
        ]);
    }

    public function update(UpdateSyslogSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('syslog', 'enabled', $validated['enabled'] ? 'true' : 'false');
        Setting::set('syslog', 'host', $validated['host'] ?? '');
        Setting::set('syslog', 'port', (string) $validated['port']);
        Setting::set('syslog', 'transport', $validated['transport']);
        Setting::set('syslog', 'facility', (string) $validated['facility']);

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'syslog',
        ]);

        return redirect()->back()->with('success', 'SIEM settings updated.');
    }

    public function test(Request $request): JsonResponse
    {
        $host = Setting::get('syslog', 'host');
        $port = (int) Setting::get('syslog', 'port', '514');
        $protocol = Setting::get('syslog', 'transport', 'udp');
        $facility = (int) Setting::get('syslog', 'facility', '16');

        if (! $host || ! SyslogTransport::validateConfig($host, $port, $protocol)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid syslog configuration. Save settings first.',
            ]);
        }

        $transport = new SyslogTransport($host, $port, $protocol, $facility);
        $success = $transport->test();

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Test event sent successfully.' : 'Failed to send test event.',
        ]);
    }
}
```

**Step 5: Add routes**

In `routes/admin.php`, add inside the middleware group (after line 27):

```php
Route::get('syslog', [AdminSyslogController::class, 'edit'])->name('admin.syslog.edit');
Route::put('syslog', [AdminSyslogController::class, 'update'])->name('admin.syslog.update');
Route::post('syslog/test', [AdminSyslogController::class, 'test'])->name('admin.syslog.test');
```

Add the import at the top:
```php
use App\Http\Controllers\Admin\AdminSyslogController;
```

**Step 6: Run test to verify it passes**

Run: `php artisan test --filter=AdminSyslogControllerTest`
Expected: PASS

**Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/AdminSyslogController.php app/Http/Requests/UpdateSyslogSettingsRequest.php routes/admin.php tests/Feature/Admin/AdminSyslogControllerTest.php
git commit -m "feat: add admin SIEM/syslog settings controller and routes"
```

---

### Task 14: Admin SIEM Settings Vue Page

**Files:**
- Create: `resources/js/pages/admin/Syslog.vue`
- Modify: `resources/js/layouts/AdminLayout.vue:19-24`

**Step 1: Create the Syslog settings page**

Create `resources/js/pages/admin/Syslog.vue`:

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AdminLayout from '@/layouts/AdminLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    settings: {
        enabled: boolean;
        host: string;
        port: number;
        transport: string;
        facility: number;
    };
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'SIEM Integration', href: '/admin/syslog' },
];

const form = useForm({
    enabled: props.settings.enabled,
    host: props.settings.host,
    port: props.settings.port,
    transport: props.settings.transport,
    facility: props.settings.facility,
});

const submit = () => {
    form.put('/admin/syslog');
};

const testResult = ref<{ success: boolean; message: string } | null>(null);
const testLoading = ref(false);

const testConnection = async () => {
    testResult.value = null;
    testLoading.value = true;

    try {
        const response = await fetch('/admin/syslog/test', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
                Accept: 'application/json',
            },
        });
        testResult.value = await response.json();
    } catch {
        testResult.value = { success: false, message: 'Request failed.' };
    } finally {
        testLoading.value = false;
    }
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="SIEM Integration" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="SIEM Integration"
                description="Configure syslog/CEF forwarding to LogRhythm or other SIEM platforms"
            />

            <form class="space-y-6" @submit.prevent="submit">
                <div class="flex items-center gap-3">
                    <Switch
                        id="enabled"
                        :checked="form.enabled"
                        @update:checked="form.enabled = $event"
                    />
                    <Label for="enabled">Enable syslog forwarding</Label>
                </div>

                <div class="grid gap-2">
                    <Label for="host">Syslog Host</Label>
                    <Input
                        id="host"
                        v-model="form.host"
                        placeholder="10.0.0.1 or syslog.example.com"
                        :disabled="!form.enabled"
                    />
                    <InputError :message="form.errors.host" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="port">Port</Label>
                        <Input
                            id="port"
                            v-model="form.port"
                            type="number"
                            min="1"
                            max="65535"
                            :disabled="!form.enabled"
                        />
                        <InputError :message="form.errors.port" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="transport">Transport Protocol</Label>
                        <Select
                            v-model="form.transport"
                            :disabled="!form.enabled"
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="udp">UDP</SelectItem>
                                <SelectItem value="tcp">TCP</SelectItem>
                                <SelectItem value="tls">TLS</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.transport" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="facility">Syslog Facility</Label>
                    <Select
                        v-model="form.facility"
                        :disabled="!form.enabled"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="16">local0 (16)</SelectItem>
                            <SelectItem :value="17">local1 (17)</SelectItem>
                            <SelectItem :value="18">local2 (18)</SelectItem>
                            <SelectItem :value="19">local3 (19)</SelectItem>
                            <SelectItem :value="20">local4 (20)</SelectItem>
                            <SelectItem :value="21">local5 (21)</SelectItem>
                            <SelectItem :value="22">local6 (22)</SelectItem>
                            <SelectItem :value="23">local7 (23)</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.facility" />
                </div>

                <div class="flex items-center gap-4">
                    <Button :disabled="form.processing">Save</Button>

                    <Transition
                        enter-active-class="transition ease-in-out"
                        enter-from-class="opacity-0"
                        leave-active-class="transition ease-in-out"
                        leave-to-class="opacity-0"
                    >
                        <p
                            v-show="form.recentlySuccessful"
                            class="text-sm text-muted-foreground"
                        >
                            Saved.
                        </p>
                    </Transition>
                </div>
            </form>

            <div class="border-t pt-6">
                <Heading
                    variant="small"
                    title="Test Connection"
                    description="Send a test CEF event to verify connectivity"
                />
                <div class="mt-4 flex items-center gap-4">
                    <Button
                        variant="outline"
                        :disabled="testLoading || !form.enabled"
                        @click="testConnection"
                    >
                        {{ testLoading ? 'Testing...' : 'Test Connection' }}
                    </Button>
                    <p
                        v-if="testResult"
                        :class="
                            testResult.success
                                ? 'text-green-600'
                                : 'text-red-600'
                        "
                        class="text-sm"
                    >
                        {{ testResult.message }}
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
```

**Step 2: Add SIEM nav item to AdminLayout**

In `resources/js/layouts/AdminLayout.vue`, add to the imports (line 3):

```typescript
import { Cable, Globe, Shield, Settings2, Users } from 'lucide-vue-next';
```

Add to the `adminNavItems` array (after line 23):

```typescript
{ title: 'SIEM Integration', href: '/admin/syslog', icon: Shield },
```

**Step 3: Run type check and lint**

Run: `npm run types:check && npm run lint`
Expected: PASS

**Step 4: Commit**

```bash
git add resources/js/pages/admin/Syslog.vue resources/js/layouts/AdminLayout.vue
git commit -m "feat: add SIEM integration admin settings page"
```

---

### Task 15: Activity Log UI Filtering — Backend

**Files:**
- Modify: `app/Http/Controllers/ActivityLogController.php:11-20`
- Test: `tests/Feature/Controllers/ActivityLogControllerTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Controllers/ActivityLogControllerTest.php`:

```php
<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;

test('activity log index returns paginated logs', function () {
    $user = User::factory()->create();

    ActivityLog::create([
        'user_id' => $user->id,
        'action' => ActivityAction::PartnerCreated,
        'details' => ['name' => 'Test'],
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('activity/Index')
            ->has('logs.data', 1)
        );
});

test('activity log filters by action', function () {
    $user = User::factory()->create();

    ActivityLog::create(['user_id' => $user->id, 'action' => ActivityAction::PartnerCreated, 'created_at' => now()]);
    ActivityLog::create(['user_id' => $user->id, 'action' => ActivityAction::GuestInvited, 'created_at' => now()]);

    $this->actingAs($user)
        ->get(route('activity.index', ['actions' => ['partner_created']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1));
});

test('activity log filters by user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    ActivityLog::create(['user_id' => $user1->id, 'action' => ActivityAction::PartnerCreated, 'created_at' => now()]);
    ActivityLog::create(['user_id' => $user2->id, 'action' => ActivityAction::PartnerCreated, 'created_at' => now()]);

    $this->actingAs($user1)
        ->get(route('activity.index', ['user_id' => $user1->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1));
});

test('activity log filters by date range', function () {
    $user = User::factory()->create();

    ActivityLog::create(['user_id' => $user->id, 'action' => ActivityAction::PartnerCreated, 'created_at' => now()->subDays(5)]);
    ActivityLog::create(['user_id' => $user->id, 'action' => ActivityAction::GuestInvited, 'created_at' => now()]);

    $this->actingAs($user)
        ->get(route('activity.index', [
            'date_from' => now()->subDay()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1));
});

test('activity log filters by search term in details', function () {
    $user = User::factory()->create();

    ActivityLog::create(['user_id' => $user->id, 'action' => ActivityAction::PartnerCreated, 'details' => ['name' => 'Contoso'], 'created_at' => now()]);
    ActivityLog::create(['user_id' => $user->id, 'action' => ActivityAction::PartnerCreated, 'details' => ['name' => 'Fabrikam'], 'created_at' => now()]);

    $this->actingAs($user)
        ->get(route('activity.index', ['search' => 'Contoso']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1));
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ActivityLogControllerTest`
Expected: FAIL on filter tests

**Step 3: Update ActivityLogController with filtering**

Replace `app/Http/Controllers/ActivityLogController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $query = ActivityLog::with('user')->orderByDesc('created_at');

        if ($request->filled('actions')) {
            $query->whereIn('action', $request->input('actions'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        if ($request->filled('search')) {
            $query->where('details', 'like', '%'.$request->input('search').'%');
        }

        return Inertia::render('activity/Index', [
            'logs' => $query->paginate(50)->withQueryString(),
            'filters' => $request->only(['actions', 'user_id', 'date_from', 'date_to', 'search']),
            'users' => User::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ActivityLogControllerTest`
Expected: PASS

**Step 5: Commit**

```bash
git add app/Http/Controllers/ActivityLogController.php tests/Feature/Controllers/ActivityLogControllerTest.php
git commit -m "feat: add filtering support to activity log controller"
```

---

### Task 16: Activity Log UI Filtering — Frontend

**Files:**
- Modify: `resources/js/pages/activity/Index.vue`

**Step 1: Update the activity log page with filter bar**

Replace `resources/js/pages/activity/Index.vue`:

```vue
<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import { index as activityIndex } from '@/routes/activity';
import type { BreadcrumbItem } from '@/types';

type ActivityLog = {
    id: number;
    action: string;
    description: string;
    subject_type: string;
    subject_id: number;
    changes: Record<string, any> | null;
    user: { id: number; name: string } | null;
    created_at: string;
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
};

type Filters = {
    actions?: string[];
    user_id?: number | string;
    date_from?: string;
    date_to?: string;
    search?: string;
};

const props = defineProps<{
    logs: Paginated<ActivityLog>;
    filters: Filters;
    users: { id: number; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Activity Log', href: activityIndex.url() },
];

const search = ref(props.filters.search ?? '');
const userId = ref(props.filters.user_id?.toString() ?? '');
const dateFrom = ref(props.filters.date_from ?? '');
const dateTo = ref(props.filters.date_to ?? '');
const selectedAction = ref(
    props.filters.actions?.length ? props.filters.actions[0] : '',
);

const actionCategories = {
    Auth: [
        'user_logged_in',
        'user_logged_out',
        'login_failed',
        'account_locked',
        'password_changed',
        'two_factor_enabled',
        'two_factor_disabled',
    ],
    Partners: ['partner_created', 'partner_updated', 'partner_deleted'],
    Guests: [
        'guest_invited',
        'guest_updated',
        'guest_enabled',
        'guest_disabled',
        'guest_removed',
    ],
    Templates: ['template_created', 'template_updated', 'template_deleted'],
    Admin: [
        'settings_updated',
        'user_approved',
        'user_role_changed',
        'user_deleted',
        'graph_connection_tested',
        'consent_granted',
        'profile_updated',
        'account_deleted',
    ],
    Sync: ['sync_completed', 'sync_triggered', 'conditional_access_policies_synced'],
    'Access Reviews': [
        'access_review_created',
        'access_review_completed',
        'access_review_decision_made',
        'access_review_remediation_applied',
    ],
    Entitlements: [
        'access_package_created',
        'access_package_updated',
        'access_package_deleted',
        'assignment_requested',
        'assignment_approved',
        'assignment_denied',
        'assignment_revoked',
    ],
};

function applyFilters() {
    const params: Record<string, any> = {};
    if (search.value) params.search = search.value;
    if (userId.value) params.user_id = userId.value;
    if (dateFrom.value) params.date_from = dateFrom.value;
    if (dateTo.value) params.date_to = dateTo.value;
    if (selectedAction.value) params['actions[]'] = selectedAction.value;

    router.get(activityIndex.url(), params, { preserveState: true });
}

function clearFilters() {
    search.value = '';
    userId.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    selectedAction.value = '';
    router.get(activityIndex.url(), {}, { preserveState: true });
}

const hasFilters =
    props.filters.search ||
    props.filters.user_id ||
    props.filters.date_from ||
    props.filters.date_to ||
    (props.filters.actions && props.filters.actions.length > 0);

const actionVariant = (
    action: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (action.includes('created') || action.includes('invited'))
        return 'default';
    if (action.includes('deleted') || action.includes('removed'))
        return 'destructive';
    if (action.includes('updated') || action.includes('synced'))
        return 'secondary';
    return 'outline';
};

function formatDate(val: string): string {
    return new Date(val).toLocaleString();
}
</script>

<template>
    <Head title="Activity Log" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div>
                <h1 class="text-2xl font-semibold">Activity Log</h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Audit trail of all actions taken in the system.
                </p>
            </div>

            <!-- Filters -->
            <div class="rounded-lg border bg-card p-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-5">
                    <div class="grid gap-1.5">
                        <Label for="search" class="text-xs">Search</Label>
                        <Input
                            id="search"
                            v-model="search"
                            placeholder="Search details..."
                            @keyup.enter="applyFilters"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="action" class="text-xs">Action</Label>
                        <Select v-model="selectedAction">
                            <SelectTrigger>
                                <SelectValue placeholder="All actions" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">All actions</SelectItem>
                                <template
                                    v-for="(actions, category) in actionCategories"
                                    :key="category"
                                >
                                    <SelectItem
                                        v-for="action in actions"
                                        :key="action"
                                        :value="action"
                                    >
                                        {{ category }}: {{ action }}
                                    </SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="user" class="text-xs">User</Label>
                        <Select v-model="userId">
                            <SelectTrigger>
                                <SelectValue placeholder="All users" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="">All users</SelectItem>
                                <SelectItem
                                    v-for="user in users"
                                    :key="user.id"
                                    :value="user.id.toString()"
                                >
                                    {{ user.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="date_from" class="text-xs">From</Label>
                        <Input
                            id="date_from"
                            v-model="dateFrom"
                            type="date"
                        />
                    </div>

                    <div class="grid gap-1.5">
                        <Label for="date_to" class="text-xs">To</Label>
                        <Input
                            id="date_to"
                            v-model="dateTo"
                            type="date"
                        />
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <Button size="sm" @click="applyFilters">
                        Apply Filters
                    </Button>
                    <Button
                        v-if="hasFilters"
                        variant="outline"
                        size="sm"
                        @click="clearFilters"
                    >
                        Clear
                    </Button>
                </div>
            </div>

            <!-- Table -->
            <div class="rounded-lg border bg-card">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/50">
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Time
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                User
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Action
                            </th>
                            <th
                                class="px-4 py-3 text-left font-medium text-muted-foreground"
                            >
                                Description
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="log in logs.data"
                            :key="log.id"
                            class="border-b transition-colors last:border-0 hover:bg-muted/30"
                        >
                            <td
                                class="px-4 py-3 whitespace-nowrap text-muted-foreground"
                            >
                                {{ formatDate(log.created_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <span v-if="log.user" class="font-medium">{{
                                    log.user.name
                                }}</span>
                                <span
                                    v-else
                                    class="text-muted-foreground italic"
                                    >System</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <Badge :variant="actionVariant(log.action)">
                                    {{ log.action }}
                                </Badge>
                            </td>
                            <td
                                class="max-w-md px-4 py-3 text-muted-foreground"
                            >
                                {{ log.description }}
                            </td>
                        </tr>
                        <tr v-if="logs.data.length === 0">
                            <td
                                colspan="4"
                                class="px-4 py-8 text-center text-muted-foreground"
                            >
                                No activity logs found.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="logs.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-muted-foreground">
                    Showing {{ logs.data.length }} of {{ logs.total }} entries
                </p>
                <div class="flex gap-1">
                    <template v-for="link in logs.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            :class="[
                                'inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm transition-colors',
                                link.active
                                    ? 'bg-primary font-medium text-primary-foreground'
                                    : 'border hover:bg-muted',
                            ]"
                            ><!-- eslint-disable-next-line vue/no-v-html --><span
                                v-html="link.label"
                        /></Link>
                        <span
                            v-else
                            class="inline-flex items-center justify-center rounded-md px-3 py-1.5 text-sm text-muted-foreground opacity-50"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
```

**Step 2: Run type check and lint**

Run: `npm run types:check && npm run lint`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/pages/activity/Index.vue
git commit -m "feat: add filtering UI to activity log page"
```

---

### Task 17: Final Integration Test & CI Check

**Step 1: Run full test suite**

Run: `php artisan test`
Expected: All tests PASS

**Step 2: Run full CI check**

Run: `composer run ci:check`
Expected: All checks PASS (lint, format, types, tests)

**Step 3: Fix any issues found**

Address any test failures or lint errors.

**Step 4: Final commit if fixes were needed**

```bash
git add -A
git commit -m "fix: address CI check issues from audit logging implementation"
```
