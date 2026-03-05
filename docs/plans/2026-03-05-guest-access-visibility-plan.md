# Guest User Access Visibility Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Show what each guest user has access to (groups, apps, Teams, SharePoint sites) via live Graph API calls on the guest detail page.

**Architecture:** Extend `GuestUserService` with 4 new methods that call Graph API, add 4 JSON endpoints to `GuestUserController`, and add a tabbed UI to the guest Show page that lazy-loads each access type on tab click. Data is cached for 5 minutes server-side.

**Tech Stack:** Laravel 12, Microsoft Graph API, Vue 3, TypeScript, shadcn-vue Tabs

---

### Task 1: Service — getUserGroups

**Files:**
- Test: `tests/Feature/Services/GuestUserServiceTest.php`
- Modify: `app/Services/GuestUserService.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/Services/GuestUserServiceTest.php`:

```php
test('it gets user group memberships', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response([
            'value' => [
                [
                    '@odata.type' => '#microsoft.graph.group',
                    'id' => 'g1',
                    'displayName' => 'Security Group A',
                    'groupTypes' => [],
                    'securityEnabled' => true,
                    'mailEnabled' => false,
                    'description' => 'A security group',
                ],
                [
                    '@odata.type' => '#microsoft.graph.group',
                    'id' => 'g2',
                    'displayName' => 'M365 Group B',
                    'groupTypes' => ['Unified'],
                    'securityEnabled' => false,
                    'mailEnabled' => true,
                    'description' => null,
                ],
                [
                    '@odata.type' => '#microsoft.graph.directoryRole',
                    'id' => 'r1',
                    'displayName' => 'Global Reader',
                ],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $groups = $service->getUserGroups('u1');

    expect($groups)->toHaveCount(2);
    expect($groups[0])->toMatchArray([
        'id' => 'g1',
        'displayName' => 'Security Group A',
        'groupType' => 'security',
        'description' => 'A security group',
    ]);
    expect($groups[1])->toMatchArray([
        'id' => 'g2',
        'displayName' => 'M365 Group B',
        'groupType' => 'microsoft365',
        'description' => null,
    ]);
});

test('it returns empty array when user has no groups', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response(['value' => []]),
    ]);

    $service = app(GuestUserService::class);
    $groups = $service->getUserGroups('u1');

    expect($groups)->toBeEmpty();
});

test('it caches user groups for 5 minutes', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response([
            'value' => [
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'g1', 'displayName' => 'Group', 'groupTypes' => [], 'securityEnabled' => true, 'mailEnabled' => false, 'description' => null],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $service->getUserGroups('u1');
    $service->getUserGroups('u1'); // second call should hit cache

    Http::assertSentCount(2); // 1 token + 1 memberOf (not 2 memberOf)
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=getUserGroups`
Expected: FAIL — method `getUserGroups` does not exist

**Step 3: Write minimal implementation**

Add to `app/Services/GuestUserService.php`:

```php
use Illuminate\Support\Facades\Cache;

public function getUserGroups(string $entraUserId): array
{
    return Cache::remember("guest_access:{$entraUserId}:groups", 300, function () use ($entraUserId) {
        $response = $this->graph->get("/users/{$entraUserId}/memberOf", [
            '$select' => 'id,displayName,groupTypes,securityEnabled,mailEnabled,description',
            '$top' => 999,
        ]);

        return collect($response['value'] ?? [])
            ->filter(fn ($item) => ($item['@odata.type'] ?? '') === '#microsoft.graph.group')
            ->map(fn ($group) => [
                'id' => $group['id'],
                'displayName' => $group['displayName'] ?? '',
                'groupType' => $this->resolveGroupType($group),
                'description' => $group['description'] ?? null,
            ])
            ->values()
            ->all();
    });
}

private function resolveGroupType(array $group): string
{
    if (in_array('Unified', $group['groupTypes'] ?? [])) {
        return 'microsoft365';
    }
    if ($group['securityEnabled'] ?? false) {
        return 'security';
    }

    return 'distribution';
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=getUserGroups`
Expected: PASS (all 3 tests)

**Step 5: Commit**

```bash
git add tests/Feature/Services/GuestUserServiceTest.php app/Services/GuestUserService.php
git commit -m "feat: add getUserGroups to GuestUserService with caching"
```

---

### Task 2: Service — getUserApps

**Files:**
- Test: `tests/Feature/Services/GuestUserServiceTest.php`
- Modify: `app/Services/GuestUserService.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/Services/GuestUserServiceTest.php`:

```php
test('it gets user app role assignments', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/appRoleAssignments*' => Http::response([
            'value' => [
                [
                    'id' => 'a1',
                    'resourceDisplayName' => 'SharePoint Online',
                    'appRoleId' => '00000000-0000-0000-0000-000000000000',
                    'createdDateTime' => '2026-01-15T10:00:00Z',
                ],
                [
                    'id' => 'a2',
                    'resourceDisplayName' => 'Microsoft Teams',
                    'appRoleId' => 'role-id-2',
                    'createdDateTime' => '2026-02-01T12:00:00Z',
                ],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $apps = $service->getUserApps('u1');

    expect($apps)->toHaveCount(2);
    expect($apps[0])->toMatchArray([
        'id' => 'a1',
        'appDisplayName' => 'SharePoint Online',
        'roleName' => 'Default Access',
        'assignedAt' => '2026-01-15T10:00:00Z',
    ]);
    expect($apps[1]['appDisplayName'])->toBe('Microsoft Teams');
});

test('it returns empty array when user has no app assignments', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/appRoleAssignments*' => Http::response(['value' => []]),
    ]);

    $service = app(GuestUserService::class);
    $apps = $service->getUserApps('u1');

    expect($apps)->toBeEmpty();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=getUserApps`
Expected: FAIL — method `getUserApps` does not exist

**Step 3: Write minimal implementation**

Add to `app/Services/GuestUserService.php`:

```php
public function getUserApps(string $entraUserId): array
{
    return Cache::remember("guest_access:{$entraUserId}:apps", 300, function () use ($entraUserId) {
        $response = $this->graph->get("/users/{$entraUserId}/appRoleAssignments", [
            '$top' => 999,
        ]);

        return collect($response['value'] ?? [])
            ->map(fn ($assignment) => [
                'id' => $assignment['id'],
                'appDisplayName' => $assignment['resourceDisplayName'] ?? 'Unknown App',
                'roleName' => $assignment['appRoleId'] === '00000000-0000-0000-0000-000000000000'
                    ? 'Default Access'
                    : ($assignment['appRoleId'] ?? null),
                'assignedAt' => $assignment['createdDateTime'] ?? null,
            ])
            ->values()
            ->all();
    });
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=getUserApps`
Expected: PASS

**Step 5: Commit**

```bash
git add tests/Feature/Services/GuestUserServiceTest.php app/Services/GuestUserService.php
git commit -m "feat: add getUserApps to GuestUserService"
```

---

### Task 3: Service — getUserTeams

**Files:**
- Test: `tests/Feature/Services/GuestUserServiceTest.php`
- Modify: `app/Services/GuestUserService.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/Services/GuestUserServiceTest.php`:

```php
test('it gets user teams memberships', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/joinedTeams*' => Http::response([
            'value' => [
                ['id' => 't1', 'displayName' => 'Engineering', 'description' => 'Eng team'],
                ['id' => 't2', 'displayName' => 'Marketing', 'description' => null],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $teams = $service->getUserTeams('u1');

    expect($teams)->toHaveCount(2);
    expect($teams[0])->toMatchArray([
        'id' => 't1',
        'displayName' => 'Engineering',
        'description' => 'Eng team',
    ]);
});

test('it returns empty array when user has no teams', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/joinedTeams*' => Http::response(['value' => []]),
    ]);

    $service = app(GuestUserService::class);
    $teams = $service->getUserTeams('u1');

    expect($teams)->toBeEmpty();
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=getUserTeams`
Expected: FAIL

**Step 3: Write minimal implementation**

Add to `app/Services/GuestUserService.php`:

```php
public function getUserTeams(string $entraUserId): array
{
    return Cache::remember("guest_access:{$entraUserId}:teams", 300, function () use ($entraUserId) {
        $response = $this->graph->get("/users/{$entraUserId}/joinedTeams", [
            '$select' => 'id,displayName,description',
            '$top' => 999,
        ]);

        return collect($response['value'] ?? [])
            ->map(fn ($team) => [
                'id' => $team['id'],
                'displayName' => $team['displayName'] ?? '',
                'description' => $team['description'] ?? null,
            ])
            ->values()
            ->all();
    });
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=getUserTeams`
Expected: PASS

**Step 5: Commit**

```bash
git add tests/Feature/Services/GuestUserServiceTest.php app/Services/GuestUserService.php
git commit -m "feat: add getUserTeams to GuestUserService"
```

---

### Task 4: Service — getUserSites

**Files:**
- Test: `tests/Feature/Services/GuestUserServiceTest.php`
- Modify: `app/Services/GuestUserService.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/Services/GuestUserServiceTest.php`:

```php
test('it gets user sharepoint sites via m365 group memberships', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response([
            'value' => [
                [
                    '@odata.type' => '#microsoft.graph.group',
                    'id' => 'g1',
                    'displayName' => 'Project Team',
                    'groupTypes' => ['Unified'],
                    'securityEnabled' => false,
                    'mailEnabled' => true,
                    'description' => null,
                ],
                [
                    '@odata.type' => '#microsoft.graph.group',
                    'id' => 'g2',
                    'displayName' => 'Security Only',
                    'groupTypes' => [],
                    'securityEnabled' => true,
                    'mailEnabled' => false,
                    'description' => null,
                ],
            ],
        ]),
        'graph.microsoft.com/v1.0/groups/g1/sites/root*' => Http::response([
            'id' => 's1',
            'displayName' => 'Project Team Site',
            'webUrl' => 'https://contoso.sharepoint.com/sites/project-team',
        ]),
    ]);

    $service = app(GuestUserService::class);
    // Clear the groups cache so memberOf is called fresh
    Cache::forget('guest_access:u1:groups');
    $sites = $service->getUserSites('u1');

    expect($sites)->toHaveCount(1);
    expect($sites[0])->toMatchArray([
        'id' => 's1',
        'displayName' => 'Project Team Site',
        'webUrl' => 'https://contoso.sharepoint.com/sites/project-team',
    ]);
});

test('it returns empty array when user has no m365 groups', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response([
            'value' => [
                [
                    '@odata.type' => '#microsoft.graph.group',
                    'id' => 'g1',
                    'groupTypes' => [],
                    'securityEnabled' => true,
                    'mailEnabled' => false,
                    'description' => null,
                    'displayName' => 'Security Group',
                ],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    Cache::forget('guest_access:u1:groups');
    $sites = $service->getUserSites('u1');

    expect($sites)->toBeEmpty();
});

test('it skips sites that fail to resolve', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response([
            'value' => [
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'g1', 'displayName' => 'Team A', 'groupTypes' => ['Unified'], 'securityEnabled' => false, 'mailEnabled' => true, 'description' => null],
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'g2', 'displayName' => 'Team B', 'groupTypes' => ['Unified'], 'securityEnabled' => false, 'mailEnabled' => true, 'description' => null],
            ],
        ]),
        'graph.microsoft.com/v1.0/groups/g1/sites/root*' => Http::response(['error' => ['message' => 'Not found', 'code' => 'itemNotFound']], 404),
        'graph.microsoft.com/v1.0/groups/g2/sites/root*' => Http::response([
            'id' => 's2',
            'displayName' => 'Team B Site',
            'webUrl' => 'https://contoso.sharepoint.com/sites/team-b',
        ]),
    ]);

    $service = app(GuestUserService::class);
    Cache::forget('guest_access:u1:groups');
    $sites = $service->getUserSites('u1');

    expect($sites)->toHaveCount(1);
    expect($sites[0]['displayName'])->toBe('Team B Site');
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=getUserSites`
Expected: FAIL

**Step 3: Write minimal implementation**

Add to `app/Services/GuestUserService.php`:

```php
use App\Exceptions\GraphApiException;

public function getUserSites(string $entraUserId): array
{
    return Cache::remember("guest_access:{$entraUserId}:sites", 300, function () use ($entraUserId) {
        $response = $this->graph->get("/users/{$entraUserId}/memberOf", [
            '$select' => 'id,displayName,groupTypes,securityEnabled,mailEnabled,description',
            '$top' => 999,
        ]);

        $m365Groups = collect($response['value'] ?? [])
            ->filter(fn ($item) => ($item['@odata.type'] ?? '') === '#microsoft.graph.group')
            ->filter(fn ($group) => in_array('Unified', $group['groupTypes'] ?? []));

        $sites = [];
        foreach ($m365Groups as $group) {
            try {
                $site = $this->graph->get("/groups/{$group['id']}/sites/root", [
                    '$select' => 'id,displayName,webUrl',
                ]);
                $sites[] = [
                    'id' => $site['id'],
                    'displayName' => $site['displayName'] ?? '',
                    'webUrl' => $site['webUrl'] ?? '',
                ];
            } catch (GraphApiException) {
                continue;
            }
        }

        return $sites;
    });
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=getUserSites`
Expected: PASS

**Step 5: Commit**

```bash
git add tests/Feature/Services/GuestUserServiceTest.php app/Services/GuestUserService.php
git commit -m "feat: add getUserSites to GuestUserService"
```

---

### Task 5: Controller — 4 JSON endpoints

**Files:**
- Test: `tests/Feature/GuestUserControllerTest.php`
- Modify: `app/Http/Controllers/GuestUserController.php`
- Modify: `routes/web.php`

**Step 1: Write the failing tests**

Append to `tests/Feature/GuestUserControllerTest.php`:

```php
test('viewers can view guest groups', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $guest = GuestUser::factory()->create(['entra_user_id' => 'entra-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/entra-123/memberOf*' => Http::response([
            'value' => [
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'g1', 'displayName' => 'Test Group', 'groupTypes' => [], 'securityEnabled' => true, 'mailEnabled' => false, 'description' => null],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('guests.groups', $guest))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['displayName' => 'Test Group']);
});

test('viewers can view guest apps', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $guest = GuestUser::factory()->create(['entra_user_id' => 'entra-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/entra-123/appRoleAssignments*' => Http::response([
            'value' => [
                ['id' => 'a1', 'resourceDisplayName' => 'Test App', 'appRoleId' => '00000000-0000-0000-0000-000000000000', 'createdDateTime' => '2026-01-01T00:00:00Z'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('guests.apps', $guest))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['appDisplayName' => 'Test App']);
});

test('viewers can view guest teams', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $guest = GuestUser::factory()->create(['entra_user_id' => 'entra-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/entra-123/joinedTeams*' => Http::response([
            'value' => [
                ['id' => 't1', 'displayName' => 'Test Team', 'description' => 'A team'],
            ],
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('guests.teams', $guest))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['displayName' => 'Test Team']);
});

test('viewers can view guest sites', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $guest = GuestUser::factory()->create(['entra_user_id' => 'entra-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/entra-123/memberOf*' => Http::response([
            'value' => [
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'g1', 'displayName' => 'Team', 'groupTypes' => ['Unified'], 'securityEnabled' => false, 'mailEnabled' => true, 'description' => null],
            ],
        ]),
        'graph.microsoft.com/v1.0/groups/g1/sites/root*' => Http::response([
            'id' => 's1', 'displayName' => 'Team Site', 'webUrl' => 'https://contoso.sharepoint.com/sites/team',
        ]),
    ]);

    $this->actingAs($user)
        ->get(route('guests.sites', $guest))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['displayName' => 'Team Site']);
});

test('guest access endpoints return 502 on graph api failure', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);
    $guest = GuestUser::factory()->create(['entra_user_id' => 'entra-123']);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Service unavailable', 'code' => 'serviceUnavailable']], 503),
    ]);

    $this->actingAs($user)
        ->get(route('guests.groups', $guest))
        ->assertStatus(502)
        ->assertJsonFragment(['error' => 'Unable to load groups from Microsoft Graph API.']);
});

test('unauthenticated users cannot access guest access endpoints', function () {
    $guest = GuestUser::factory()->create();

    $this->get(route('guests.groups', $guest))->assertRedirect(route('login'));
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --filter="viewers can view guest groups"`
Expected: FAIL — route not defined

**Step 3: Add routes**

Add to `routes/web.php` after the `guests/{guest}/resend` line:

```php
Route::get('guests/{guest}/groups', [GuestUserController::class, 'groups'])->name('guests.groups');
Route::get('guests/{guest}/apps', [GuestUserController::class, 'apps'])->name('guests.apps');
Route::get('guests/{guest}/teams', [GuestUserController::class, 'teams'])->name('guests.teams');
Route::get('guests/{guest}/sites', [GuestUserController::class, 'sites'])->name('guests.sites');
```

**Step 4: Add controller methods**

Add to `app/Http/Controllers/GuestUserController.php`:

```php
use App\Exceptions\GraphApiException;

public function groups(GuestUser $guest): JsonResponse
{
    try {
        return response()->json($this->guestService->getUserGroups($guest->entra_user_id));
    } catch (GraphApiException) {
        return response()->json(['error' => 'Unable to load groups from Microsoft Graph API.'], 502);
    }
}

public function apps(GuestUser $guest): JsonResponse
{
    try {
        return response()->json($this->guestService->getUserApps($guest->entra_user_id));
    } catch (GraphApiException) {
        return response()->json(['error' => 'Unable to load apps from Microsoft Graph API.'], 502);
    }
}

public function teams(GuestUser $guest): JsonResponse
{
    try {
        return response()->json($this->guestService->getUserTeams($guest->entra_user_id));
    } catch (GraphApiException) {
        return response()->json(['error' => 'Unable to load teams from Microsoft Graph API.'], 502);
    }
}

public function sites(GuestUser $guest): JsonResponse
{
    try {
        return response()->json($this->guestService->getUserSites($guest->entra_user_id));
    } catch (GraphApiException) {
        return response()->json(['error' => 'Unable to load sites from Microsoft Graph API.'], 502);
    }
}
```

**Step 5: Run tests to verify they pass**

Run: `php artisan test --filter="viewers can view guest|guest access endpoints|unauthenticated users cannot access guest access"`
Expected: PASS (all 6 tests)

**Step 6: Commit**

```bash
git add app/Http/Controllers/GuestUserController.php routes/web.php tests/Feature/GuestUserControllerTest.php
git commit -m "feat: add guest access JSON endpoints (groups, apps, teams, sites)"
```

---

### Task 6: Frontend — TypeScript types

**Files:**
- Modify: `resources/js/types/guest.ts`

**Step 1: Add the access types**

Append to `resources/js/types/guest.ts`:

```typescript
export type GuestGroup = {
    id: string;
    displayName: string;
    groupType: 'security' | 'microsoft365' | 'distribution';
    description: string | null;
};

export type GuestApp = {
    id: string;
    appDisplayName: string;
    roleName: string | null;
    assignedAt: string | null;
};

export type GuestTeam = {
    id: string;
    displayName: string;
    description: string | null;
};

export type GuestSite = {
    id: string;
    displayName: string;
    webUrl: string;
};
```

**Step 2: Run type check**

Run: `npm run types:check`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/types/guest.ts
git commit -m "feat: add TypeScript types for guest access data"
```

---

### Task 7: Frontend — Tabbed Show page with lazy-loaded access tables

**Files:**
- Modify: `resources/js/pages/guests/Show.vue`

**Step 1: Rewrite Show.vue with tabs**

Replace the content of `resources/js/pages/guests/Show.vue`. The key changes:
- Import `Tabs`, `TabsList`, `TabsTrigger`, `TabsContent` from `@/components/ui/tabs`
- Import `axios` from `axios` (already available in Laravel projects)
- Add reactive state for each tab: `groups`, `apps`, `teams`, `sites` (each with `data`, `loading`, `error` refs)
- Wrap existing content in an "Overview" tab
- Add 4 access tabs, each with a table, loading spinner, empty state, and error+retry
- Fetch data on tab change via `@update:model-value` on the Tabs component

The Overview tab contains the existing Card content unchanged. Each access tab contains a simple HTML table styled with Tailwind.

```vue
<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/AppLayout.vue';
import { dashboard } from '@/routes';
import guests from '@/routes/guests';
import partners from '@/routes/partners';
import type { BreadcrumbItem } from '@/types';
import type { GuestUser } from '@/types/partner';
import type { GuestGroup, GuestApp, GuestTeam, GuestSite } from '@/types/guest';

const props = defineProps<{
    guest: GuestUser;
}>();

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: dashboard.url() },
    { title: 'Guest Users', href: guests.index.url() },
    { title: props.guest.display_name, href: guests.show.url(props.guest.id) },
];

const isAdmin = computed(() => {
    const auth = page.props.auth as { user?: { role?: string } };
    return auth?.user?.role === 'admin';
});

const statusVariant = (status: string): 'default' | 'destructive' | 'outline' => {
    if (status === 'accepted') return 'default';
    if (status === 'failed') return 'destructive';
    return 'outline';
};

function statusLabel(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(val: string | null): string {
    if (!val) return '—';
    return new Date(val).toLocaleString();
}

const showDeleteConfirm = ref(false);
const deleting = ref(false);

function deleteGuest() {
    deleting.value = true;
    router.delete(guests.destroy.url(props.guest.id), {
        onFinish: () => {
            deleting.value = false;
        },
    });
}

// Access tab state
const groupsData = ref<GuestGroup[]>([]);
const groupsLoading = ref(false);
const groupsError = ref(false);
const groupsFetched = ref(false);

const appsData = ref<GuestApp[]>([]);
const appsLoading = ref(false);
const appsError = ref(false);
const appsFetched = ref(false);

const teamsData = ref<GuestTeam[]>([]);
const teamsLoading = ref(false);
const teamsError = ref(false);
const teamsFetched = ref(false);

const sitesData = ref<GuestSite[]>([]);
const sitesLoading = ref(false);
const sitesError = ref(false);
const sitesFetched = ref(false);

async function fetchAccessData(tab: string | number) {
    const tabStr = String(tab);
    if (tabStr === 'groups' && !groupsFetched.value) {
        groupsLoading.value = true;
        groupsError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/groups`);
            groupsData.value = data;
            groupsFetched.value = true;
        } catch {
            groupsError.value = true;
        } finally {
            groupsLoading.value = false;
        }
    } else if (tabStr === 'apps' && !appsFetched.value) {
        appsLoading.value = true;
        appsError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/apps`);
            appsData.value = data;
            appsFetched.value = true;
        } catch {
            appsError.value = true;
        } finally {
            appsLoading.value = false;
        }
    } else if (tabStr === 'teams' && !teamsFetched.value) {
        teamsLoading.value = true;
        teamsError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/teams`);
            teamsData.value = data;
            teamsFetched.value = true;
        } catch {
            teamsError.value = true;
        } finally {
            teamsLoading.value = false;
        }
    } else if (tabStr === 'sites' && !sitesFetched.value) {
        sitesLoading.value = true;
        sitesError.value = false;
        try {
            const { data } = await axios.get(`/guests/${props.guest.id}/sites`);
            sitesData.value = data;
            sitesFetched.value = true;
        } catch {
            sitesError.value = true;
        } finally {
            sitesLoading.value = false;
        }
    }
}

function retryTab(tab: string) {
    if (tab === 'groups') groupsFetched.value = false;
    if (tab === 'apps') appsFetched.value = false;
    if (tab === 'teams') teamsFetched.value = false;
    if (tab === 'sites') sitesFetched.value = false;
    fetchAccessData(tab);
}

function groupTypeLabel(type: string): string {
    const labels: Record<string, string> = { security: 'Security', microsoft365: 'Microsoft 365', distribution: 'Distribution' };
    return labels[type] ?? type;
}
</script>

<template>
    <Head :title="guest.display_name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-col gap-6 p-6">
            <!-- Header -->
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-semibold">
                            {{ guest.display_name }}
                        </h1>
                        <Badge :variant="statusVariant(guest.invitation_status)">
                            {{ statusLabel(guest.invitation_status) }}
                        </Badge>
                    </div>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ guest.email }}
                    </p>
                </div>
            </div>

            <Separator />

            <Tabs default-value="overview" @update:model-value="fetchAccessData">
                <TabsList>
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="groups">Groups</TabsTrigger>
                    <TabsTrigger value="apps">Apps</TabsTrigger>
                    <TabsTrigger value="teams">Teams</TabsTrigger>
                    <TabsTrigger value="sites">Sites</TabsTrigger>
                </TabsList>

                <!-- Overview Tab -->
                <TabsContent value="overview">
                    <Card>
                        <CardHeader>
                            <CardTitle>User Details</CardTitle>
                        </CardHeader>
                        <CardContent class="flex flex-col gap-3 text-sm">
                            <div class="grid grid-cols-2 gap-x-4 gap-y-2">
                                <span class="text-muted-foreground">Display Name</span>
                                <span>{{ guest.display_name }}</span>

                                <span class="text-muted-foreground">Email</span>
                                <span>{{ guest.email }}</span>

                                <span class="text-muted-foreground">User Principal Name</span>
                                <span class="font-mono text-xs">{{ guest.user_principal_name ?? '—' }}</span>

                                <span class="text-muted-foreground">Entra User ID</span>
                                <span class="font-mono text-xs">{{ guest.entra_user_id }}</span>

                                <span class="text-muted-foreground">Invitation Status</span>
                                <span>
                                    <Badge :variant="statusVariant(guest.invitation_status)">
                                        {{ statusLabel(guest.invitation_status) }}
                                    </Badge>
                                </span>

                                <span class="text-muted-foreground">Partner Organization</span>
                                <span>
                                    <Link
                                        v-if="guest.partner_organization"
                                        :href="partners.show.url(guest.partner_organization.id)"
                                        class="font-medium text-foreground hover:underline"
                                    >
                                        {{ guest.partner_organization.display_name }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">—</span>
                                </span>

                                <span class="text-muted-foreground">Invited By</span>
                                <span>{{ guest.invited_by?.name ?? '—' }}</span>

                                <span class="text-muted-foreground">Last Sign In</span>
                                <span>{{ formatDate(guest.last_sign_in_at) }}</span>

                                <span class="text-muted-foreground">Last Synced</span>
                                <span>{{ formatDate(guest.last_synced_at) }}</span>

                                <span class="text-muted-foreground">Created</span>
                                <span>{{ formatDate(guest.created_at) }}</span>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Danger Zone (Admin only) -->
                    <Card v-if="isAdmin" class="mt-6 border-destructive/50">
                        <CardHeader>
                            <CardTitle class="text-destructive">Danger Zone</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!showDeleteConfirm">
                                <p class="mb-3 text-sm text-muted-foreground">
                                    Remove this guest user from the system. This does not remove them from Entra ID.
                                </p>
                                <Button variant="destructive" @click="showDeleteConfirm = true">
                                    Delete Guest Record
                                </Button>
                            </div>
                            <div v-else class="flex flex-col gap-3">
                                <p class="text-sm font-medium">Are you sure? This cannot be undone.</p>
                                <div class="flex gap-2">
                                    <Button variant="destructive" @click="deleteGuest" :disabled="deleting">
                                        {{ deleting ? 'Deleting…' : 'Yes, Delete' }}
                                    </Button>
                                    <Button variant="outline" @click="showDeleteConfirm = false">Cancel</Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Groups Tab -->
                <TabsContent value="groups">
                    <Card>
                        <CardHeader>
                            <CardTitle>Group Memberships</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="groupsLoading" class="flex items-center justify-center py-8">
                                <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                                <span class="ml-2 text-sm text-muted-foreground">Loading groups…</span>
                            </div>
                            <div v-else-if="groupsError" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">Unable to load groups. Microsoft Graph API may be unavailable.</p>
                                <Button variant="outline" size="sm" class="mt-3" @click="retryTab('groups')">Retry</Button>
                            </div>
                            <div v-else-if="groupsData.length === 0 && groupsFetched" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">No groups found for this guest user.</p>
                            </div>
                            <table v-else-if="groupsData.length > 0" class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="pb-2 font-medium">Display Name</th>
                                        <th class="pb-2 font-medium">Type</th>
                                        <th class="pb-2 font-medium">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="group in groupsData" :key="group.id" class="border-b last:border-0">
                                        <td class="py-2">{{ group.displayName }}</td>
                                        <td class="py-2">
                                            <Badge variant="outline">{{ groupTypeLabel(group.groupType) }}</Badge>
                                        </td>
                                        <td class="py-2 text-muted-foreground">{{ group.description ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Apps Tab -->
                <TabsContent value="apps">
                    <Card>
                        <CardHeader>
                            <CardTitle>App Assignments</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="appsLoading" class="flex items-center justify-center py-8">
                                <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                                <span class="ml-2 text-sm text-muted-foreground">Loading apps…</span>
                            </div>
                            <div v-else-if="appsError" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">Unable to load apps. Microsoft Graph API may be unavailable.</p>
                                <Button variant="outline" size="sm" class="mt-3" @click="retryTab('apps')">Retry</Button>
                            </div>
                            <div v-else-if="appsData.length === 0 && appsFetched" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">No apps found for this guest user.</p>
                            </div>
                            <table v-else-if="appsData.length > 0" class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="pb-2 font-medium">App Name</th>
                                        <th class="pb-2 font-medium">Role</th>
                                        <th class="pb-2 font-medium">Assigned</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="app in appsData" :key="app.id" class="border-b last:border-0">
                                        <td class="py-2">{{ app.appDisplayName }}</td>
                                        <td class="py-2">{{ app.roleName ?? '—' }}</td>
                                        <td class="py-2 text-muted-foreground">{{ formatDate(app.assignedAt) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Teams Tab -->
                <TabsContent value="teams">
                    <Card>
                        <CardHeader>
                            <CardTitle>Teams Memberships</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="teamsLoading" class="flex items-center justify-center py-8">
                                <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                                <span class="ml-2 text-sm text-muted-foreground">Loading teams…</span>
                            </div>
                            <div v-else-if="teamsError" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">Unable to load teams. Microsoft Graph API may be unavailable.</p>
                                <Button variant="outline" size="sm" class="mt-3" @click="retryTab('teams')">Retry</Button>
                            </div>
                            <div v-else-if="teamsData.length === 0 && teamsFetched" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">No teams found for this guest user.</p>
                            </div>
                            <table v-else-if="teamsData.length > 0" class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="pb-2 font-medium">Team Name</th>
                                        <th class="pb-2 font-medium">Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="team in teamsData" :key="team.id" class="border-b last:border-0">
                                        <td class="py-2">{{ team.displayName }}</td>
                                        <td class="py-2 text-muted-foreground">{{ team.description ?? '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Sites Tab -->
                <TabsContent value="sites">
                    <Card>
                        <CardHeader>
                            <CardTitle>SharePoint Sites</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="sitesLoading" class="flex items-center justify-center py-8">
                                <div class="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
                                <span class="ml-2 text-sm text-muted-foreground">Loading sites…</span>
                            </div>
                            <div v-else-if="sitesError" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">Unable to load sites. Microsoft Graph API may be unavailable.</p>
                                <Button variant="outline" size="sm" class="mt-3" @click="retryTab('sites')">Retry</Button>
                            </div>
                            <div v-else-if="sitesData.length === 0 && sitesFetched" class="py-8 text-center">
                                <p class="text-sm text-muted-foreground">No sites found for this guest user.</p>
                            </div>
                            <table v-else-if="sitesData.length > 0" class="w-full text-sm">
                                <thead>
                                    <tr class="border-b text-left text-muted-foreground">
                                        <th class="pb-2 font-medium">Site Name</th>
                                        <th class="pb-2 font-medium">URL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="site in sitesData" :key="site.id" class="border-b last:border-0">
                                        <td class="py-2">{{ site.displayName }}</td>
                                        <td class="py-2">
                                            <a :href="site.webUrl" target="_blank" rel="noopener" class="text-primary hover:underline">
                                                {{ site.webUrl }}
                                            </a>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
```

**Step 2: Run type check and lint**

Run: `npm run types:check && npm run lint && npm run format`
Expected: PASS

**Step 3: Commit**

```bash
git add resources/js/pages/guests/Show.vue
git commit -m "feat: add tabbed access visibility UI to guest detail page"
```

---

### Task 8: Final verification

**Step 1: Run full test suite**

Run: `composer run test`
Expected: All tests pass

**Step 2: Run CI check**

Run: `composer run ci:check`
Expected: All checks pass

**Step 3: Commit (if any lint fixes were needed)**

```bash
git add -A
git commit -m "chore: lint fixes for guest access visibility"
```
