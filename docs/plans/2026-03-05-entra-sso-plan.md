# Entra ID SSO Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add Entra ID SSO (OIDC) as a login option alongside Fortify password auth, with admin configuration UI.

**Architecture:** Laravel Socialite with `socialiteproviders/microsoft` for OIDC flow. Dynamic provider config from `settings` table. GCC High support via `CloudEnvironment` enum. Admin SSO settings page at `/admin/sso`. Group-to-role mapping at provisioning time.

**Tech Stack:** Laravel 12, Socialite, socialiteproviders/microsoft, Vue 3, Inertia.js, Pest

**Design doc:** `docs/plans/2026-03-05-entra-sso-design.md`

---

### Task 1: Install Socialite packages

**Files:**
- Modify: `composer.json`

**Step 1: Install packages**

Run:
```bash
composer require laravel/socialite socialiteproviders/microsoft
```

**Step 2: Verify installation**

Run: `composer show laravel/socialite`
Expected: Package info displayed

Run: `composer show socialiteproviders/microsoft`
Expected: Package info displayed

**Step 3: Commit**

```bash
git add composer.json composer.lock
git commit -m "chore: install Socialite and Microsoft provider packages"
```

---

### Task 2: Add entra_id column to users table

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_entra_id_to_users_table.php`
- Modify: `app/Models/User.php`

**Step 1: Write the failing test**

Create `tests/Feature/Admin/SsoControllerTest.php` (we'll add to this file throughout):

```php
<?php

use App\Models\User;

test('user model has entra_id attribute', function () {
    $user = User::factory()->create(['entra_id' => 'abc-123']);

    expect($user->entra_id)->toBe('abc-123');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="user model has entra_id attribute"`
Expected: FAIL — column doesn't exist

**Step 3: Create migration**

Run: `php artisan make:migration add_entra_id_to_users_table --table=users`

Then edit the generated migration:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('entra_id')->nullable()->unique()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('entra_id');
        });
    }
};
```

**Step 4: Update User model**

In `app/Models/User.php`, add `'entra_id'` to the `$fillable` array:

```php
protected $fillable = [
    'name',
    'email',
    'password',
    'role',
    'entra_id',
];
```

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter="user model has entra_id attribute"`
Expected: PASS

**Step 6: Commit**

```bash
git add database/migrations/*add_entra_id* app/Models/User.php tests/Feature/Admin/SsoControllerTest.php
git commit -m "feat: add entra_id column to users table"
```

---

### Task 3: Admin SSO controller and routes

**Files:**
- Create: `app/Http/Controllers/Admin/AdminSsoController.php`
- Create: `app/Http/Requests/UpdateSsoSettingsRequest.php`
- Modify: `routes/admin.php`

Reference existing patterns:
- Controller pattern: `app/Http/Controllers/Admin/AdminSyslogController.php`
- Request pattern: `app/Http/Requests/UpdateSyslogSettingsRequest.php`
- Settings: `Setting::get('sso', ...)` / `Setting::set('sso', ...)`

**Step 1: Write the failing tests**

Add to `tests/Feature/Admin/SsoControllerTest.php`:

```php
use App\Enums\UserRole;
use App\Models\Setting;

beforeEach(function () {
    $this->admin = User::factory()->create([
        'role' => UserRole::Admin,
        'approved_at' => now(),
    ]);
});

test('admin can view SSO settings', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.sso.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/Sso'));
});

test('non-admin cannot view SSO settings', function () {
    $viewer = User::factory()->create([
        'role' => UserRole::Viewer,
        'approved_at' => now(),
    ]);

    $this->actingAs($viewer)
        ->get(route('admin.sso.edit'))
        ->assertForbidden();
});

test('admin can save SSO settings', function () {
    $this->actingAs($this->admin)->put(route('admin.sso.update'), [
        'enabled' => true,
        'auto_approve' => false,
        'default_role' => 'viewer',
        'group_mapping_enabled' => false,
        'group_mappings' => [],
        'restrict_provisioning_to_mapped_groups' => false,
    ])->assertRedirect();

    expect(Setting::get('sso', 'enabled'))->toBe('true');
    expect(Setting::get('sso', 'auto_approve'))->toBe('false');
    expect(Setting::get('sso', 'default_role'))->toBe('viewer');
});

test('SSO settings validate default_role', function () {
    $this->actingAs($this->admin)->put(route('admin.sso.update'), [
        'enabled' => true,
        'auto_approve' => false,
        'default_role' => 'superadmin',
        'group_mapping_enabled' => false,
        'group_mappings' => [],
        'restrict_provisioning_to_mapped_groups' => false,
    ])->assertSessionHasErrors('default_role');
});

test('SSO settings validate group_mappings structure', function () {
    $this->actingAs($this->admin)->put(route('admin.sso.update'), [
        'enabled' => true,
        'auto_approve' => false,
        'default_role' => 'viewer',
        'group_mapping_enabled' => true,
        'group_mappings' => [
            ['entra_group_id' => '', 'entra_group_name' => 'Admins', 'role' => 'admin'],
        ],
        'restrict_provisioning_to_mapped_groups' => false,
    ])->assertSessionHasErrors('group_mappings.0.entra_group_id');
});

test('SSO edit page shows graph credentials status', function () {
    Setting::set('graph', 'client_id', 'test-client-id');
    Setting::set('graph', 'tenant_id', 'test-tenant-id');

    $this->actingAs($this->admin)
        ->get(route('admin.sso.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/Sso')
            ->where('graphConfigured', true)
        );
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="SsoControllerTest"`
Expected: FAIL — routes and controller don't exist

**Step 3: Create the form request**

Create `app/Http/Requests/UpdateSsoSettingsRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSsoSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->role->isAdmin();
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'auto_approve' => ['required', 'boolean'],
            'default_role' => ['required', 'string', Rule::enum(UserRole::class)],
            'group_mapping_enabled' => ['required', 'boolean'],
            'group_mappings' => ['present', 'array'],
            'group_mappings.*.entra_group_id' => ['required_if:group_mapping_enabled,true', 'nullable', 'string', 'max:255'],
            'group_mappings.*.entra_group_name' => ['nullable', 'string', 'max:255'],
            'group_mappings.*.role' => ['required_if:group_mapping_enabled,true', 'nullable', 'string', Rule::enum(UserRole::class)],
            'restrict_provisioning_to_mapped_groups' => ['required', 'boolean'],
        ];
    }
}
```

**Step 4: Create the controller**

Create `app/Http/Controllers/Admin/AdminSsoController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSsoSettingsRequest;
use App\Models\Setting;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminSsoController extends Controller
{
    public function __construct(
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        $graphClientId = Setting::get('graph', 'client_id', config('graph.client_id'));
        $graphTenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));

        return Inertia::render('admin/Sso', [
            'settings' => [
                'enabled' => Setting::get('sso', 'enabled', 'false') === 'true',
                'auto_approve' => Setting::get('sso', 'auto_approve', 'false') === 'true',
                'default_role' => Setting::get('sso', 'default_role', 'viewer'),
                'group_mapping_enabled' => Setting::get('sso', 'group_mapping_enabled', 'false') === 'true',
                'group_mappings' => json_decode(Setting::get('sso', 'group_mappings', '[]'), true),
                'restrict_provisioning_to_mapped_groups' => Setting::get('sso', 'restrict_provisioning_to_mapped_groups', 'false') === 'true',
            ],
            'graphConfigured' => ! empty($graphClientId) && ! empty($graphTenantId),
        ]);
    }

    public function update(UpdateSsoSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        Setting::set('sso', 'enabled', $validated['enabled'] ? 'true' : 'false');
        Setting::set('sso', 'auto_approve', $validated['auto_approve'] ? 'true' : 'false');
        Setting::set('sso', 'default_role', $validated['default_role']);
        Setting::set('sso', 'group_mapping_enabled', $validated['group_mapping_enabled'] ? 'true' : 'false');
        Setting::set('sso', 'group_mappings', json_encode($validated['group_mappings']));
        Setting::set('sso', 'restrict_provisioning_to_mapped_groups', $validated['restrict_provisioning_to_mapped_groups'] ? 'true' : 'false');

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'sso',
        ]);

        return redirect()->route('admin.sso.edit')->with('success', 'SSO settings updated.');
    }
}
```

**Step 5: Add routes**

In `routes/admin.php`, add inside the existing middleware group (after the syslog routes):

```php
use App\Http\Controllers\Admin\AdminSsoController;

// Inside the middleware group:
Route::get('sso', [AdminSsoController::class, 'edit'])->name('admin.sso.edit');
Route::put('sso', [AdminSsoController::class, 'update'])->name('admin.sso.update');
```

**Step 6: Run tests to verify they pass**

Run: `php artisan test --filter="SsoControllerTest"`
Expected: Most pass. The Inertia component assertion will fail because the Vue page doesn't exist yet — that's fine, we'll create it in Task 4.

**Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/AdminSsoController.php app/Http/Requests/UpdateSsoSettingsRequest.php routes/admin.php tests/Feature/Admin/SsoControllerTest.php
git commit -m "feat: add admin SSO settings controller and routes"
```

---

### Task 4: Admin SSO Vue page

**Files:**
- Create: `resources/js/pages/admin/Sso.vue`
- Modify: `resources/js/layouts/AdminLayout.vue`

Reference existing patterns:
- Layout: `resources/js/layouts/AdminLayout.vue` (sidebar nav items)
- Page: `resources/js/pages/admin/Syslog.vue` (toggle + form pattern)
- Page: `resources/js/pages/admin/Graph.vue` (credential display pattern)

**Step 1: Add SSO nav item to AdminLayout**

In `resources/js/layouts/AdminLayout.vue`, add to the `adminNavItems` array:

```typescript
import { Cable, Globe, KeyRound, Settings2, Shield, Users } from 'lucide-vue-next';

const adminNavItems: NavItem[] = [
    { title: 'Microsoft Graph', href: '/admin/graph', icon: Cable },
    { title: 'Collaboration', href: '/admin/collaboration', icon: Globe },
    { title: 'User Management', href: '/admin/users', icon: Users },
    { title: 'SSO', href: '/admin/sso', icon: KeyRound },
    { title: 'Sync Settings', href: '/admin/sync', icon: Settings2 },
    { title: 'SIEM Integration', href: '/admin/syslog', icon: Shield },
];
```

**Step 2: Create the SSO settings page**

Create `resources/js/pages/admin/Sso.vue`:

```vue
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
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

type GroupMapping = {
    entra_group_id: string;
    entra_group_name: string;
    role: string;
};

type Props = {
    settings: {
        enabled: boolean;
        auto_approve: boolean;
        default_role: string;
        group_mapping_enabled: boolean;
        group_mappings: GroupMapping[];
        restrict_provisioning_to_mapped_groups: boolean;
    };
    graphConfigured: boolean;
};

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'SSO', href: '/admin/sso' },
];

const form = useForm({
    enabled: props.settings.enabled,
    auto_approve: props.settings.auto_approve,
    default_role: props.settings.default_role,
    group_mapping_enabled: props.settings.group_mapping_enabled,
    group_mappings: props.settings.group_mappings.length > 0
        ? props.settings.group_mappings
        : [] as GroupMapping[],
    restrict_provisioning_to_mapped_groups: props.settings.restrict_provisioning_to_mapped_groups,
});

const addMapping = () => {
    form.group_mappings.push({
        entra_group_id: '',
        entra_group_name: '',
        role: 'viewer',
    });
};

const removeMapping = (index: number) => {
    form.group_mappings.splice(index, 1);
};

const submit = () => {
    form.put('/admin/sso');
};
</script>

<template>
    <AdminLayout :breadcrumbs="breadcrumbs">
        <Head title="SSO Settings" />

        <div class="flex flex-col space-y-6">
            <Heading
                variant="small"
                title="Single Sign-On (SSO)"
                description="Configure Entra ID (Azure AD) sign-in for your users"
            />

            <div
                v-if="!graphConfigured"
                class="rounded-md border border-yellow-200 bg-yellow-50 p-4 text-sm text-yellow-800 dark:border-yellow-800 dark:bg-yellow-950 dark:text-yellow-200"
            >
                Graph API credentials are not configured. SSO uses the same app registration.
                <TextLink href="/admin/graph">Configure Graph credentials first.</TextLink>
            </div>

            <form class="space-y-6" @submit.prevent="submit">
                <div class="flex items-center gap-3">
                    <Switch
                        id="enabled"
                        :checked="form.enabled"
                        @update:checked="form.enabled = $event"
                    />
                    <Label for="enabled">Enable Entra ID SSO</Label>
                </div>

                <div class="border-t pt-6 space-y-6">
                    <Heading
                        variant="small"
                        title="User Provisioning"
                        description="How new users are handled when they first sign in via SSO"
                    />

                    <div class="flex items-center gap-3">
                        <Switch
                            id="auto_approve"
                            :checked="form.auto_approve"
                            :disabled="!form.enabled"
                            @update:checked="form.auto_approve = $event"
                        />
                        <Label for="auto_approve">Auto-approve SSO users</Label>
                    </div>
                    <p class="text-xs text-muted-foreground -mt-4">
                        When disabled, SSO users must be approved by an admin before accessing the application.
                    </p>

                    <div class="grid gap-2">
                        <Label for="default_role">Default Role</Label>
                        <Select v-model="form.default_role" :disabled="!form.enabled">
                            <SelectTrigger class="w-48">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="admin">Admin</SelectItem>
                                <SelectItem value="operator">Operator</SelectItem>
                                <SelectItem value="viewer">Viewer</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.default_role" />
                        <p class="text-xs text-muted-foreground">
                            Role assigned to new users who sign in via SSO (when no group mapping matches).
                        </p>
                    </div>
                </div>

                <div class="border-t pt-6 space-y-6">
                    <Heading
                        variant="small"
                        title="Group Mapping"
                        description="Map Entra ID security groups to application roles"
                    />

                    <div class="flex items-center gap-3">
                        <Switch
                            id="group_mapping_enabled"
                            :checked="form.group_mapping_enabled"
                            :disabled="!form.enabled"
                            @update:checked="form.group_mapping_enabled = $event"
                        />
                        <Label for="group_mapping_enabled">Enable group-to-role mapping</Label>
                    </div>

                    <div v-if="form.group_mapping_enabled && form.enabled" class="space-y-4">
                        <div class="flex items-center gap-3">
                            <Switch
                                id="restrict_provisioning"
                                :checked="form.restrict_provisioning_to_mapped_groups"
                                @update:checked="form.restrict_provisioning_to_mapped_groups = $event"
                            />
                            <Label for="restrict_provisioning">Only allow users in mapped groups</Label>
                        </div>
                        <p class="text-xs text-muted-foreground -mt-2">
                            When enabled, users not in any mapped group will be denied access.
                        </p>

                        <div
                            v-for="(mapping, index) in form.group_mappings"
                            :key="index"
                            class="flex items-start gap-3"
                        >
                            <div class="grid gap-1 flex-1">
                                <Label :for="`group_id_${index}`" class="text-xs">Group ID</Label>
                                <Input
                                    :id="`group_id_${index}`"
                                    v-model="mapping.entra_group_id"
                                    placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                />
                                <InputError :message="form.errors[`group_mappings.${index}.entra_group_id`]" />
                            </div>
                            <div class="grid gap-1 flex-1">
                                <Label :for="`group_name_${index}`" class="text-xs">Display Name</Label>
                                <Input
                                    :id="`group_name_${index}`"
                                    v-model="mapping.entra_group_name"
                                    placeholder="e.g. P365 Admins"
                                />
                            </div>
                            <div class="grid gap-1 w-36">
                                <Label class="text-xs">Role</Label>
                                <Select v-model="mapping.role">
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="admin">Admin</SelectItem>
                                        <SelectItem value="operator">Operator</SelectItem>
                                        <SelectItem value="viewer">Viewer</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError :message="form.errors[`group_mappings.${index}.role`]" />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="mt-6 text-destructive"
                                @click="removeMapping(index)"
                            >
                                Remove
                            </Button>
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="addMapping"
                        >
                            Add Group Mapping
                        </Button>
                    </div>
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
        </div>
    </AdminLayout>
</template>
```

**Step 3: Run tests to verify they pass**

Run: `php artisan test --filter="SsoControllerTest"`
Expected: All PASS

**Step 4: Run frontend checks**

Run: `npm run types:check && npm run lint`
Expected: PASS

**Step 5: Commit**

```bash
git add resources/js/pages/admin/Sso.vue resources/js/layouts/AdminLayout.vue
git commit -m "feat: add admin SSO settings page with group mapping UI"
```

---

### Task 5: Socialite dynamic provider configuration

**Files:**
- Create: `app/Providers/SocialiteServiceProvider.php`
- Modify: `bootstrap/providers.php`

The Microsoft Socialite provider needs to be configured dynamically at runtime from the `settings` table, not from `config/services.php`. The `socialiteproviders/microsoft` package uses event listeners to register the provider.

Reference: `app/Enums/CloudEnvironment.php` — has `loginUrl()` for commercial vs GCC High.

**Step 1: Write the failing test**

Add to `tests/Feature/Admin/SsoControllerTest.php`:

```php
use App\Models\Setting;
use Laravel\Socialite\Facades\Socialite;

test('socialite microsoft driver resolves with settings from database', function () {
    Setting::set('graph', 'tenant_id', 'test-tenant-id');
    Setting::set('graph', 'client_id', 'test-client-id');
    Setting::set('graph', 'client_secret', 'test-secret', encrypted: true);
    Setting::set('graph', 'cloud_environment', 'commercial');

    $driver = Socialite::driver('microsoft');

    expect($driver)->toBeInstanceOf(\SocialiteProviders\Microsoft\Provider::class);
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --filter="socialite microsoft driver resolves"`
Expected: FAIL — provider not configured

**Step 3: Create SocialiteServiceProvider**

Create `app/Providers/SocialiteServiceProvider.php`:

```php
<?php

namespace App\Providers;

use App\Enums\CloudEnvironment;
use App\Models\Setting;
use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;

class SocialiteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->booted(function () {
            Socialite::extend('microsoft', function ($app) {
                $tenantId = Setting::get('graph', 'tenant_id', config('graph.tenant_id'));
                $clientId = Setting::get('graph', 'client_id', config('graph.client_id'));
                $clientSecret = Setting::get('graph', 'client_secret', config('graph.client_secret'));

                $cloudEnv = CloudEnvironment::tryFrom(
                    Setting::get('graph', 'cloud_environment', config('graph.cloud_environment'))
                ) ?? CloudEnvironment::Commercial;

                $loginUrl = $cloudEnv->loginUrl();

                $config = [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect' => url('/auth/sso/callback'),
                    'tenant' => $tenantId,
                ];

                $provider = new \SocialiteProviders\Microsoft\Provider(
                    $app['request'],
                    $config['client_id'],
                    $config['client_secret'],
                    $config['redirect'],
                );

                return $provider->setConfig(
                    new \SocialiteProviders\Manager\Config(
                        $config['client_id'],
                        $config['client_secret'],
                        $config['redirect'],
                        ['tenant' => $tenantId],
                    )
                );
            });
        });
    }
}
```

**Step 4: Register the provider**

Add `App\Providers\SocialiteServiceProvider::class` to `bootstrap/providers.php`.

**Step 5: Run test to verify it passes**

Run: `php artisan test --filter="socialite microsoft driver resolves"`
Expected: PASS

**Step 6: Commit**

```bash
git add app/Providers/SocialiteServiceProvider.php bootstrap/providers.php
git commit -m "feat: add dynamic Socialite Microsoft provider with GCC High support"
```

---

### Task 6: SSO authentication controller and routes

**Files:**
- Create: `app/Http/Controllers/Auth/SsoController.php`
- Modify: `routes/web.php`

Reference:
- `app/Services/MicrosoftGraphService.php` — for group membership lookup
- `app/Services/ActivityLogService.php` — for login audit logging
- `app/Enums/ActivityAction.php` — existing `UserLoggedIn` action
- `app/Models/User.php` — `entra_id`, `approved_at`, `role`, `approve()`

**Step 1: Write the failing tests**

Add to `tests/Feature/Admin/SsoControllerTest.php`:

```php
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Illuminate\Support\Facades\Http;

function fakeSocialiteUser(array $overrides = []): void
{
    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = $overrides['id'] ?? 'entra-object-id-123';
    $socialiteUser->name = $overrides['name'] ?? 'Test User';
    $socialiteUser->email = $overrides['email'] ?? 'test@example.com';
    $socialiteUser->token = 'fake-token';

    Socialite::shouldReceive('driver->user')->andReturn($socialiteUser);
}

test('SSO redirect sends user to Microsoft login', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('graph', 'tenant_id', 'test-tenant');
    Setting::set('graph', 'client_id', 'test-client');
    Setting::set('graph', 'client_secret', 'test-secret', encrypted: true);

    $response = $this->get('/auth/sso');

    $response->assertRedirectContains('login.microsoftonline.com');
});

test('SSO callback creates new user when auto_approve is on', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'viewer');

    fakeSocialiteUser();

    $response = $this->get('/auth/sso/callback');

    $response->assertRedirect(route('dashboard'));
    $this->assertAuthenticated();

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user)->not->toBeNull();
    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->role->value)->toBe('viewer');
    expect($user->isApproved())->toBeTrue();
});

test('SSO callback creates new user pending approval when auto_approve is off', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'false');
    Setting::set('sso', 'default_role', 'viewer');

    fakeSocialiteUser();

    $response = $this->get('/auth/sso/callback');

    $this->assertAuthenticated();

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->isApproved())->toBeFalse();
});

test('SSO callback logs in existing user matched by entra_id', function () {
    Setting::set('sso', 'enabled', 'true');

    $existing = User::factory()->create([
        'entra_id' => 'entra-object-id-123',
        'email' => 'old@example.com',
        'approved_at' => now(),
    ]);

    fakeSocialiteUser(['email' => 'new@example.com']);

    $this->get('/auth/sso/callback');

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->name)->toBe('Test User');
});

test('SSO callback matches existing user by email and sets entra_id', function () {
    Setting::set('sso', 'enabled', 'true');

    $existing = User::factory()->create([
        'email' => 'test@example.com',
        'entra_id' => null,
        'approved_at' => now(),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $this->assertAuthenticatedAs($existing);
    expect($existing->fresh()->entra_id)->toBe('entra-object-id-123');
});

test('SSO callback redirects to login when SSO is disabled', function () {
    Setting::set('sso', 'enabled', 'false');

    $this->get('/auth/sso')
        ->assertRedirect(route('login'));

    $this->get('/auth/sso/callback')
        ->assertRedirect(route('login'));
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="SSO redirect sends user|SSO callback"`
Expected: FAIL — controller and routes don't exist

**Step 3: Create the SSO controller**

Create `app/Http/Controllers/Auth/SsoController.php`:

```php
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
        } catch (\Throwable $e) {
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
            $role = $this->resolveRole($socialiteUser);

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
                $user->forceFill([
                    'approved_at' => now(),
                ])->save();
            }
        }

        Auth::login($user, remember: true);

        $this->activityLog->log($user, ActivityAction::UserLoggedIn, null, [
            'method' => 'sso',
        ]);

        return redirect()->intended(route('dashboard'));
    }

    private function resolveRole(\Laravel\Socialite\Two\User $socialiteUser): ?string
    {
        $defaultRole = Setting::get('sso', 'default_role', 'viewer');

        if (Setting::get('sso', 'group_mapping_enabled', 'false') !== 'true') {
            return $defaultRole;
        }

        $mappings = json_decode(Setting::get('sso', 'group_mappings', '[]'), true);

        if (empty($mappings)) {
            return $defaultRole;
        }

        $userGroups = $this->getUserGroups($socialiteUser->id);
        $mappedGroupIds = collect($mappings)->pluck('entra_group_id')->all();
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

            return collect($response['value'] ?? [])
                ->pluck('id')
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
```

**Step 4: Add routes**

In `routes/web.php`, add the SSO routes (outside the auth middleware group, since unauthenticated users need these):

```php
use App\Http\Controllers\Auth\SsoController;

Route::get('auth/sso', [SsoController::class, 'redirect'])->name('sso.redirect');
Route::get('auth/sso/callback', [SsoController::class, 'callback'])->name('sso.callback');
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="SsoControllerTest"`
Expected: All PASS

**Step 6: Commit**

```bash
git add app/Http/Controllers/Auth/SsoController.php routes/web.php
git commit -m "feat: add SSO authentication controller with group mapping"
```

---

### Task 7: Share SSO enabled state and update login page

**Files:**
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `app/Providers/FortifyServiceProvider.php`
- Modify: `resources/js/pages/auth/Login.vue`

**Step 1: Write the failing test**

Add to `tests/Feature/Admin/SsoControllerTest.php`:

```php
test('login page shows SSO button when SSO is enabled', function () {
    Setting::set('sso', 'enabled', 'true');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('ssoEnabled', true)
        );
});

test('login page hides SSO button when SSO is disabled', function () {
    Setting::set('sso', 'enabled', 'false');

    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->where('ssoEnabled', false)
        );
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="login page shows SSO|login page hides SSO"`
Expected: FAIL — `ssoEnabled` prop not passed

**Step 3: Update Fortify login view**

In `app/Providers/FortifyServiceProvider.php`, update the `loginView` closure to include the SSO enabled state:

```php
Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
    'canResetPassword' => Features::enabled(Features::resetPasswords()),
    'canRegister' => Features::enabled(Features::registration()),
    'status' => $request->session()->get('status'),
    'ssoEnabled' => \App\Models\Setting::get('sso', 'enabled', 'false') === 'true',
]));
```

**Step 4: Update Login.vue**

In `resources/js/pages/auth/Login.vue`:

Add `ssoEnabled` to props:

```typescript
defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
    ssoEnabled: boolean;
}>();
```

Add the SSO button above the form (after the status div, before the `<Form>` tag):

```vue
<div v-if="ssoEnabled" class="mb-6">
    <a
        href="/auth/sso"
        class="inline-flex w-full items-center justify-center gap-2 rounded-md border bg-background px-4 py-2 text-sm font-medium hover:bg-accent hover:text-accent-foreground"
    >
        <svg class="h-5 w-5" viewBox="0 0 21 21" xmlns="http://www.w3.org/2000/svg">
            <rect x="1" y="1" width="9" height="9" fill="#f25022" />
            <rect x="11" y="1" width="9" height="9" fill="#7fba00" />
            <rect x="1" y="11" width="9" height="9" fill="#00a4ef" />
            <rect x="11" y="11" width="9" height="9" fill="#ffb900" />
        </svg>
        Sign in with Microsoft
    </a>
    <div class="relative my-6">
        <div class="absolute inset-0 flex items-center">
            <span class="w-full border-t" />
        </div>
        <div class="relative flex justify-center text-xs uppercase">
            <span class="bg-background px-2 text-muted-foreground">Or continue with</span>
        </div>
    </div>
</div>
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="login page shows SSO|login page hides SSO"`
Expected: PASS

**Step 6: Run all tests and frontend checks**

Run: `php artisan test && npm run types:check && npm run lint`
Expected: All PASS

**Step 7: Commit**

```bash
git add app/Providers/FortifyServiceProvider.php resources/js/pages/auth/Login.vue
git commit -m "feat: add SSO button to login page when SSO is enabled"
```

---

### Task 8: Group mapping provisioning tests

**Files:**
- Modify: `tests/Feature/Admin/SsoControllerTest.php`

This task adds tests specifically for the group mapping and restricted provisioning logic.

**Step 1: Write the tests**

Add to `tests/Feature/Admin/SsoControllerTest.php`:

```php
test('SSO callback assigns role from group mapping', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'viewer');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
        ['entra_group_id' => 'group-operator-id', 'entra_group_name' => 'P365 Operators', 'role' => 'operator'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [
                ['id' => 'group-operator-id', '@odata.type' => '#microsoft.graph.group'],
                ['id' => 'unrelated-group', '@odata.type' => '#microsoft.graph.group'],
            ],
        ]),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->role->value)->toBe('operator');
});

test('SSO callback picks highest privilege role when multiple groups match', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'viewer');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
        ['entra_group_id' => 'group-operator-id', 'entra_group_name' => 'P365 Operators', 'role' => 'operator'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [
                ['id' => 'group-admin-id', '@odata.type' => '#microsoft.graph.group'],
                ['id' => 'group-operator-id', '@odata.type' => '#microsoft.graph.group'],
            ],
        ]),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->role->value)->toBe('admin');
});

test('SSO callback denies user not in mapped groups when restrict is on', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'restrict_provisioning_to_mapped_groups', 'true');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [
                ['id' => 'unrelated-group', '@odata.type' => '#microsoft.graph.group'],
            ],
        ]),
    ]);

    fakeSocialiteUser();

    $response = $this->get('/auth/sso/callback');

    $response->assertRedirect(route('login'));
    $this->assertGuest();
    expect(User::where('entra_id', 'entra-object-id-123')->exists())->toBeFalse();
});

test('SSO callback falls back to default role when no groups match and restrict is off', function () {
    Setting::set('sso', 'enabled', 'true');
    Setting::set('sso', 'auto_approve', 'true');
    Setting::set('sso', 'default_role', 'operator');
    Setting::set('sso', 'group_mapping_enabled', 'true');
    Setting::set('sso', 'restrict_provisioning_to_mapped_groups', 'false');
    Setting::set('sso', 'group_mappings', json_encode([
        ['entra_group_id' => 'group-admin-id', 'entra_group_name' => 'P365 Admins', 'role' => 'admin'],
    ]));

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake-token', 'token_type' => 'Bearer']),
        'graph.microsoft.com/v1.0/users/entra-object-id-123/memberOf*' => Http::response([
            'value' => [],
        ]),
    ]);

    fakeSocialiteUser();

    $this->get('/auth/sso/callback');

    $user = User::where('entra_id', 'entra-object-id-123')->first();
    expect($user->role->value)->toBe('operator');
});
```

**Step 2: Run all tests**

Run: `php artisan test --filter="SsoControllerTest"`
Expected: All PASS

**Step 3: Commit**

```bash
git add tests/Feature/Admin/SsoControllerTest.php
git commit -m "test: add group mapping and restricted provisioning tests"
```

---

### Task 9: Update app registration script

**Files:**
- Modify: `scripts/setup-app-registration.sh`

**Step 1: Add SSO redirect URI**

In `scripts/setup-app-registration.sh`, change the redirect URI and app creation to include both URIs.

Replace line 75:
```bash
REDIRECT_URI="${APP_URL}/admin/graph/consent/callback"
```

With:
```bash
CONSENT_REDIRECT_URI="${APP_URL}/admin/graph/consent/callback"
SSO_REDIRECT_URI="${APP_URL}/auth/sso/callback"
```

Replace the `--web-redirect-uris "$REDIRECT_URI"` in the `az ad app create` command (line 83) with:
```bash
--web-redirect-uris "$CONSENT_REDIRECT_URI" "$SSO_REDIRECT_URI" \
```

**Step 2: Add delegated permissions for OIDC**

After the existing `PERMISSIONS` associative array (after line 64), add the delegated permissions section:

```bash
# Delegated permissions for SSO (OpenID Connect)
DELEGATED_ACCESS_ITEMS=""
declare -A DELEGATED_PERMISSIONS=(
    ["openid"]="37f7f235-527c-4136-accd-4a02d197296e"
    ["profile"]="14dad69e-099b-42c9-810b-d002981feec1"
    ["email"]="64a6cdd6-aab1-4aaf-94b8-3cc8405e90d0"
    ["User.Read"]="e1fe6dd8-ba31-4d61-89e7-88639da4683d"
)

for perm_name in "${!DELEGATED_PERMISSIONS[@]}"; do
    perm_id="${DELEGATED_PERMISSIONS[$perm_name]}"
    [ -n "$DELEGATED_ACCESS_ITEMS" ] && DELEGATED_ACCESS_ITEMS+=","
    DELEGATED_ACCESS_ITEMS+="{\"id\":\"$perm_id\",\"type\":\"Scope\"}"
done
```

Update the `REQUIRED_ACCESS` line to include both application and delegated permissions:

```bash
REQUIRED_ACCESS="[{\"resourceAppId\":\"$GRAPH_API\",\"resourceAccess\":[$RESOURCE_ACCESS_ITEMS,$DELEGATED_ACCESS_ITEMS]}]"
```

**Step 3: Update completion message**

After the existing completion echo block (around line 141), add:

```bash
echo "SSO redirect URI: $SSO_REDIRECT_URI"
echo ""
echo "To enable SSO, go to Admin → SSO in the Partner365 web interface."
```

**Step 4: Verify script syntax**

Run: `bash -n scripts/setup-app-registration.sh`
Expected: No errors (syntax check only, doesn't execute)

**Step 5: Commit**

```bash
git add scripts/setup-app-registration.sh
git commit -m "feat: add SSO redirect URI and delegated permissions to setup script"
```

---

### Task 10: Full integration test and cleanup

**Files:**
- All files from previous tasks

**Step 1: Run the full test suite**

Run: `composer run test`
Expected: All tests pass (lint + Pest)

**Step 2: Run the full CI check**

Run: `composer run ci:check`
Expected: All checks pass (lint + format + types + tests)

**Step 3: Fix any issues found**

Address any linting, type, or test failures.

**Step 4: Final commit (if fixes needed)**

```bash
git add -A
git commit -m "fix: address lint and type issues from SSO implementation"
```
