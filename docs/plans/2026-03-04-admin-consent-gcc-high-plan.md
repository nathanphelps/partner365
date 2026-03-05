# Admin Consent + GCC High Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a cloud environment selector (Commercial/GCC High) to the Graph settings page with derived endpoint URLs, and an admin consent button that opens a Microsoft popup with backend verification.

**Architecture:** New `CloudEnvironment` enum holds endpoint mappings. `MicrosoftGraphService` and `AdminGraphController` derive login URLs from it. Admin consent uses a popup flow: frontend opens Microsoft's `/adminconsent` URL, Microsoft redirects to a public callback route that posts the result back via `postMessage`.

**Tech Stack:** Laravel 12, Pest PHP, Vue 3 + Inertia.js, shadcn-vue

---

### Task 1: CloudEnvironment Enum

**Files:**
- Create: `app/Enums/CloudEnvironment.php`
- Test: `tests/Feature/Enums/CloudEnvironmentTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Enums/CloudEnvironmentTest.php`:

```php
<?php

use App\Enums\CloudEnvironment;

test('commercial environment returns correct endpoints', function () {
    $env = CloudEnvironment::Commercial;

    expect($env->loginUrl())->toBe('login.microsoftonline.com');
    expect($env->graphBaseUrl())->toBe('https://graph.microsoft.com/v1.0');
    expect($env->defaultScopes())->toBe('https://graph.microsoft.com/.default');
});

test('gcc high environment returns correct endpoints', function () {
    $env = CloudEnvironment::GccHigh;

    expect($env->loginUrl())->toBe('login.microsoftonline.us');
    expect($env->graphBaseUrl())->toBe('https://graph.microsoft.us/v1.0');
    expect($env->defaultScopes())->toBe('https://graph.microsoft.us/.default');
});

test('cloud environment can be created from string value', function () {
    expect(CloudEnvironment::from('commercial'))->toBe(CloudEnvironment::Commercial);
    expect(CloudEnvironment::from('gcc_high'))->toBe(CloudEnvironment::GccHigh);
});

test('cloud environment label returns display name', function () {
    expect(CloudEnvironment::Commercial->label())->toBe('Commercial');
    expect(CloudEnvironment::GccHigh->label())->toBe('GCC High');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter=CloudEnvironmentTest`
Expected: FAIL — class not found

**Step 3: Write minimal implementation**

Create `app/Enums/CloudEnvironment.php`:

```php
<?php

namespace App\Enums;

enum CloudEnvironment: string
{
    case Commercial = 'commercial';
    case GccHigh = 'gcc_high';

    public function loginUrl(): string
    {
        return match ($this) {
            self::Commercial => 'login.microsoftonline.com',
            self::GccHigh => 'login.microsoftonline.us',
        };
    }

    public function graphBaseUrl(): string
    {
        return match ($this) {
            self::Commercial => 'https://graph.microsoft.com/v1.0',
            self::GccHigh => 'https://graph.microsoft.us/v1.0',
        };
    }

    public function defaultScopes(): string
    {
        return match ($this) {
            self::Commercial => 'https://graph.microsoft.com/.default',
            self::GccHigh => 'https://graph.microsoft.us/.default',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Commercial',
            self::GccHigh => 'GCC High',
        };
    }
}
```

**Step 4: Run test to verify it passes**

Run: `php artisan test --filter=CloudEnvironmentTest`
Expected: PASS (4 tests)

**Step 5: Commit**

```bash
git add app/Enums/CloudEnvironment.php tests/Feature/Enums/CloudEnvironmentTest.php
git commit -m "feat: add CloudEnvironment enum for Commercial and GCC High endpoints"
```

---

### Task 2: Add cloud_environment to Graph config and settings

**Files:**
- Modify: `config/graph.php`
- Modify: `app/Http/Requests/UpdateGraphSettingsRequest.php`
- Modify: `app/Http/Controllers/Admin/AdminGraphController.php:23-41` (edit method)
- Modify: `app/Http/Controllers/Admin/AdminGraphController.php:44-65` (update method)
- Test: `tests/Feature/Admin/AdminGraphControllerTest.php`

**Step 1: Write the failing test**

Add to end of `tests/Feature/Admin/AdminGraphControllerTest.php`:

```php
test('admin can update cloud environment setting', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'gcc_high',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'client_secret' => 'secret',
            'scopes' => 'https://graph.microsoft.us/.default',
            'base_url' => 'https://graph.microsoft.us/v1.0',
            'sync_interval_minutes' => '15',
        ])
        ->assertRedirect('/admin/graph');

    expect(Setting::get('graph', 'cloud_environment'))->toBe('gcc_high');
});

test('cloud environment rejects invalid values', function () {
    $this->actingAs($this->admin)
        ->put('/admin/graph', [
            'cloud_environment' => 'invalid_cloud',
            'tenant_id' => '550e8400-e29b-41d4-a716-446655440000',
            'client_id' => '660e8400-e29b-41d4-a716-446655440000',
            'scopes' => 'https://graph.microsoft.com/.default',
            'base_url' => 'https://graph.microsoft.com/v1.0',
            'sync_interval_minutes' => '15',
        ])
        ->assertSessionHasErrors(['cloud_environment']);
});

test('graph settings page includes cloud environment', function () {
    Setting::set('graph', 'cloud_environment', 'gcc_high');

    $this->actingAs($this->admin)
        ->get('/admin/graph')
        ->assertInertia(fn ($page) => $page
            ->component('admin/Graph')
            ->where('settings.cloud_environment', 'gcc_high')
        );
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: FAIL — validation rejects `cloud_environment`, prop not returned

**Step 3: Implement changes**

In `config/graph.php`, add after line 3:

```php
    'cloud_environment' => env('MICROSOFT_GRAPH_CLOUD_ENVIRONMENT', 'commercial'),
```

In `app/Http/Requests/UpdateGraphSettingsRequest.php`, add to the rules array:

```php
    'cloud_environment' => ['required', 'string', 'in:commercial,gcc_high'],
```

In `app/Http/Controllers/Admin/AdminGraphController.php`, update the `edit()` method to include `cloud_environment` in the Inertia props — add this line inside the `'settings'` array:

```php
    'cloud_environment' => $settings['cloud_environment'] ?? config('graph.cloud_environment'),
```

In the `update()` method, add after the tenant_id set line:

```php
    Setting::set('graph', 'cloud_environment', $validated['cloud_environment']);
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: PASS (all tests)

**Step 5: Update existing tests**

All existing tests that call `PUT /admin/graph` need `'cloud_environment' => 'commercial'` added to their request payloads. There are 4 such tests:
- `admin can update graph settings` (~line 46)
- `update with blank client_secret preserves existing secret` (~line 64)
- `update clears cached graph token` (~line 92)
- `update validates required fields` — add `'cloud_environment'` to the expected errors list (~line 150)

Run: `php artisan test --filter=AdminGraphControllerTest`
Expected: PASS (all tests)

**Step 6: Commit**

```bash
git add config/graph.php app/Http/Requests/UpdateGraphSettingsRequest.php app/Http/Controllers/Admin/AdminGraphController.php tests/Feature/Admin/AdminGraphControllerTest.php
git commit -m "feat: add cloud_environment to graph settings"
```

---

### Task 3: Derive login URL from CloudEnvironment in services

**Files:**
- Modify: `app/Services/MicrosoftGraphService.php:12-28`
- Modify: `app/Http/Controllers/Admin/AdminGraphController.php:67-96` (testConnection method)
- Test: `tests/Feature/Services/MicrosoftGraphServiceTest.php`
- Test: `tests/Feature/Admin/AdminGraphControllerTest.php`

**Step 1: Write the failing test**

Add to end of `tests/Feature/Services/MicrosoftGraphServiceTest.php`:

```php
test('uses gcc high login url when cloud environment is gcc_high', function () {
    Setting::set('graph', 'cloud_environment', 'gcc_high');
    Setting::set('graph', 'tenant_id', 'gcc-tenant');
    Setting::set('graph', 'client_id', 'gcc-client');
    Setting::set('graph', 'client_secret', 'gcc-secret', encrypted: true);
    Setting::set('graph', 'scopes', 'https://graph.microsoft.us/.default');

    Http::fake([
        'login.microsoftonline.us/*' => Http::response([
            'access_token' => 'gcc-token',
            'expires_in' => 3600,
        ]),
    ]);

    Cache::forget('msgraph_access_token');

    $service = app(MicrosoftGraphService::class);
    $token = $service->getAccessToken();

    expect($token)->toBe('gcc-token');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'login.microsoftonline.us'));
});
```

You will need to add at the top of this test file (if not already present):

```php
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="uses gcc high login url"`
Expected: FAIL — still hitting `login.microsoftonline.com`

**Step 3: Update MicrosoftGraphService**

In `app/Services/MicrosoftGraphService.php`, update the `getAccessToken()` method. Replace the hardcoded login URL construction:

Replace:
```php
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
```

With:
```php
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
            $cloudEnv = \App\Enums\CloudEnvironment::tryFrom(
                Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
            ) ?? \App\Enums\CloudEnvironment::Commercial;
            $loginUrl = $cloudEnv->loginUrl();

            $response = Http::asForm()->post(
                "https://{$loginUrl}/{$tenantId}/oauth2/v2.0/token",
```

**Step 4: Update AdminGraphController::testConnection()**

In `app/Http/Controllers/Admin/AdminGraphController.php`, apply the same pattern in `testConnection()`. Replace:

```php
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

            $response = Http::asForm()->post(
                "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/token",
```

With:
```php
            $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
            $cloudEnv = \App\Enums\CloudEnvironment::tryFrom(
                Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
            ) ?? \App\Enums\CloudEnvironment::Commercial;
            $loginUrl = $cloudEnv->loginUrl();

            $response = Http::asForm()->post(
                "https://{$loginUrl}/{$tenantId}/oauth2/v2.0/token",
```

**Step 5: Run all tests**

Run: `php artisan test --filter="MicrosoftGraphServiceTest|AdminGraphControllerTest"`
Expected: PASS (all tests — existing tests still work because wildcards in `Http::fake` match both `.com` and `.us`)

**Step 6: Commit**

```bash
git add app/Services/MicrosoftGraphService.php app/Http/Controllers/Admin/AdminGraphController.php tests/Feature/Services/MicrosoftGraphServiceTest.php
git commit -m "feat: derive login URL from CloudEnvironment instead of hardcoding"
```

---

### Task 4: Admin consent backend routes

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminGraphController.php`
- Modify: `routes/admin.php`
- Create: `resources/views/admin/consent-callback.blade.php`
- Test: `tests/Feature/Admin/AdminGraphControllerTest.php`

**Step 1: Write the failing tests**

Add to end of `tests/Feature/Admin/AdminGraphControllerTest.php`:

```php
test('consent url returns admin consent url', function () {
    Setting::set('graph', 'tenant_id', '550e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'client_id', '660e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'cloud_environment', 'commercial');

    $this->actingAs($this->admin)
        ->getJson('/admin/graph/consent')
        ->assertOk()
        ->assertJsonStructure(['url'])
        ->assertJsonFragment([
            'url' => 'https://login.microsoftonline.com/550e8400-e29b-41d4-a716-446655440000/adminconsent?client_id=660e8400-e29b-41d4-a716-446655440000&redirect_uri=' . urlencode(route('admin.graph.consent.callback')),
        ]);
});

test('consent url uses gcc high login url when configured', function () {
    Setting::set('graph', 'tenant_id', '550e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'client_id', '660e8400-e29b-41d4-a716-446655440000');
    Setting::set('graph', 'cloud_environment', 'gcc_high');

    $response = $this->actingAs($this->admin)
        ->getJson('/admin/graph/consent')
        ->assertOk();

    expect($response->json('url'))->toContain('login.microsoftonline.us');
});

test('consent callback renders success view', function () {
    $this->get('/admin/graph/consent/callback?admin_consent=True&tenant=some-tenant')
        ->assertOk()
        ->assertViewIs('admin.consent-callback')
        ->assertViewHas('success', true);
});

test('consent callback renders error view', function () {
    $this->get('/admin/graph/consent/callback?error=access_denied&error_description=Admin+denied')
        ->assertOk()
        ->assertViewIs('admin.consent-callback')
        ->assertViewHas('success', false)
        ->assertViewHas('error', 'Admin denied');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="consent"`
Expected: FAIL — routes don't exist

**Step 3: Add routes**

In `routes/admin.php`, add the consent URL route inside the existing auth middleware group (after the `graph/test` route, ~line 14):

```php
    Route::get('graph/consent', [AdminGraphController::class, 'consentUrl'])->name('admin.graph.consent');
```

Add the callback route **outside** the auth middleware group (after the closing `});` on line 27), since Microsoft redirects here in the popup context where the user may not have a session:

```php
Route::middleware('web')->get('admin/graph/consent/callback', [\App\Http\Controllers\Admin\AdminGraphController::class, 'consentCallback'])
    ->name('admin.graph.consent.callback');
```

**Step 4: Add controller methods**

Add to `app/Http/Controllers/Admin/AdminGraphController.php`:

```php
    public function consentUrl(): JsonResponse
    {
        $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
        $clientId = Setting::get('graph', 'client_id', config('graph.client_id'));
        $cloudEnv = \App\Enums\CloudEnvironment::tryFrom(
            Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
        ) ?? \App\Enums\CloudEnvironment::Commercial;

        $redirectUri = route('admin.graph.consent.callback');
        $loginUrl = $cloudEnv->loginUrl();

        $url = "https://{$loginUrl}/{$tenantId}/adminconsent?"
            . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
            ]);

        return response()->json(['url' => $url]);
    }

    public function consentCallback(\Illuminate\Http\Request $request): \Illuminate\View\View
    {
        $success = $request->query('admin_consent') === 'True';
        $error = $request->query('error_description');

        return view('admin.consent-callback', [
            'success' => $success,
            'error' => $error,
        ]);
    }
```

**Step 5: Create the Blade view**

Create `resources/views/admin/consent-callback.blade.php`:

```blade
<!DOCTYPE html>
<html>
<head><title>Admin Consent</title></head>
<body>
    <p>{{ $success ? 'Consent granted successfully.' : ($error ?? 'Consent failed.') }}</p>
    <script>
        if (window.opener) {
            window.opener.postMessage(
                { type: 'admin-consent', success: @json($success), error: @json($error) },
                window.location.origin
            );
            window.close();
        }
    </script>
</body>
</html>
```

**Step 6: Run tests to verify they pass**

Run: `php artisan test --filter="consent"`
Expected: PASS (4 tests)

**Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/AdminGraphController.php routes/admin.php resources/views/admin/consent-callback.blade.php tests/Feature/Admin/AdminGraphControllerTest.php
git commit -m "feat: add admin consent URL and callback routes"
```

---

### Task 5: Frontend — cloud environment dropdown and admin consent button

**Files:**
- Modify: `resources/js/pages/admin/Graph.vue`

**Step 1: Update the Props type**

In `resources/js/pages/admin/Graph.vue`, add `cloud_environment` to the `settings` type (~line 13):

```typescript
type Props = {
    settings: {
        cloud_environment: string | null;
        tenant_id: string | null;
        client_id: string | null;
        client_secret_masked: string | null;
        scopes: string | null;
        base_url: string | null;
        sync_interval_minutes: string | number | null;
    };
};
```

**Step 2: Add cloud_environment to the form**

Update the `useForm` call to include `cloud_environment`:

```typescript
const form = useForm({
    cloud_environment: props.settings.cloud_environment ?? 'commercial',
    tenant_id: props.settings.tenant_id ?? '',
    client_id: props.settings.client_id ?? '',
    client_secret: '',
    scopes: props.settings.scopes ?? '',
    base_url: props.settings.base_url ?? '',
    sync_interval_minutes: props.settings.sync_interval_minutes ?? 15,
});
```

**Step 3: Add imports**

Add to the existing imports:

```typescript
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { watch } from 'vue';
```

Note: `ref` is already imported via vue — keep it. Add `watch` alongside it: change `import { ref } from 'vue'` to `import { ref, watch } from 'vue'`.

**Step 4: Add cloud environment defaults map and watcher**

After the `form` definition, add:

```typescript
const cloudDefaults: Record<string, { scopes: string; base_url: string }> = {
    commercial: {
        scopes: 'https://graph.microsoft.com/.default',
        base_url: 'https://graph.microsoft.com/v1.0',
    },
    gcc_high: {
        scopes: 'https://graph.microsoft.us/.default',
        base_url: 'https://graph.microsoft.us/v1.0',
    },
};

watch(
    () => form.cloud_environment,
    (env) => {
        const defaults = cloudDefaults[env];
        if (defaults) {
            form.scopes = defaults.scopes;
            form.base_url = defaults.base_url;
        }
    },
);
```

**Step 5: Add admin consent logic**

After the `testConnection` function, add:

```typescript
const consentResult = ref<{ success: boolean; error?: string } | null>(null);
const consentLoading = ref(false);

const grantAdminConsent = async () => {
    consentResult.value = null;
    consentLoading.value = true;

    try {
        const response = await fetch('/admin/graph/consent', {
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
        });
        const data = await response.json();

        const popup = window.open(data.url, '_blank', 'width=600,height=700');

        const handler = (event: MessageEvent) => {
            if (
                event.origin === window.location.origin &&
                event.data?.type === 'admin-consent'
            ) {
                consentResult.value = {
                    success: event.data.success,
                    error: event.data.error,
                };
                consentLoading.value = false;
                window.removeEventListener('message', handler);
            }
        };
        window.addEventListener('message', handler);

        // Handle popup closed without completing consent
        const pollTimer = setInterval(() => {
            if (popup?.closed) {
                clearInterval(pollTimer);
                if (consentLoading.value) {
                    consentLoading.value = false;
                    window.removeEventListener('message', handler);
                }
            }
        }, 1000);
    } catch {
        consentResult.value = { success: false, error: 'Failed to start consent flow.' };
        consentLoading.value = false;
    }
};
```

**Step 6: Add cloud environment dropdown to the template**

In the template, add a new field group **before** the Tenant ID field (insert before the `<div class="grid gap-2">` for `tenant_id`):

```html
                <div class="grid gap-2">
                    <Label for="cloud_environment">Cloud Environment</Label>
                    <Select
                        v-model="form.cloud_environment"
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="commercial">Commercial</SelectItem>
                            <SelectItem value="gcc_high">GCC High</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.cloud_environment" />
                </div>
```

**Step 7: Add admin consent section to the template**

After the "Test Connection" section's closing `</div>` (~line 194), add:

```html
            <div class="border-t pt-6">
                <Heading
                    variant="small"
                    title="Admin Consent"
                    description="Grant admin consent for the app's API permissions in your tenant"
                />
                <div class="mt-4 flex items-center gap-4">
                    <Button
                        variant="outline"
                        :disabled="consentLoading"
                        @click="grantAdminConsent"
                    >
                        {{ consentLoading ? 'Waiting for consent...' : 'Grant Admin Consent' }}
                    </Button>
                    <p
                        v-if="consentResult"
                        :class="
                            consentResult.success
                                ? 'text-green-600'
                                : 'text-red-600'
                        "
                        class="text-sm"
                    >
                        {{
                            consentResult.success
                                ? 'Admin consent granted successfully.'
                                : consentResult.error ?? 'Consent was not granted.'
                        }}
                    </p>
                </div>
            </div>
```

**Step 8: Verify frontend builds**

Run: `npm run types:check && npm run lint`
Expected: No errors

**Step 9: Commit**

```bash
git add resources/js/pages/admin/Graph.vue
git commit -m "feat: add cloud environment dropdown and admin consent button to Graph settings"
```

---

### Task 6: Run full test suite and final verification

**Step 1: Run all tests**

Run: `composer run test`
Expected: All tests pass, no lint errors

**Step 2: Final commit (if any lint fixes were needed)**

```bash
git add -A
git commit -m "chore: lint fixes"
```
