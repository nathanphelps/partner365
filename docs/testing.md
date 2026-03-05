# Testing

## Overview

Partner365 uses [Pest PHP](https://pestphp.com/) for testing. The test suite contains 202 tests covering services, controllers, middleware, models, and commands.

## Running Tests

```bash
# Run all tests
php artisan test

# Run a specific test file
php artisan test tests/Feature/PartnerOrganizationTest.php

# Run tests matching a filter
php artisan test --filter="operators can create partners"

# Run with verbose output
php artisan test -v
```

## Test Structure

```
tests/
├── TestCase.php                      # Base class (withoutVite + disable page existence check)
├── Pest.php                          # Pest config (RefreshDatabase for Feature tests)
├── Unit/
│   └── ExampleTest.php
└── Feature/
    ├── Auth/                         # 7 files — Fortify authentication tests
    ├── Settings/                     # 3 files — Profile, password, 2FA settings
    ├── Services/                     # 5 files — All Graph API service classes
    │   ├── MicrosoftGraphServiceTest.php       (9 tests)
    │   ├── CrossTenantPolicyServiceTest.php    (6 tests)
    │   ├── GuestUserServiceTest.php            (4 tests)
    │   ├── TenantResolverServiceTest.php       (2 tests)
    │   └── ActivityLogServiceTest.php          (2 tests)
    ├── Commands/                     # 3 files — Sync commands
    │   ├── SyncPartnersTest.php                (2 tests)
    │   ├── SyncGuestsTest.php                  (2 tests)
    │   └── SyncAccessReviewsTest.php           (4 tests)
    ├── Middleware/
    │   └── CheckRoleTest.php                   (4 tests)
    ├── Models/
    │   └── PartnerOrganizationTest.php         (3 tests)
    ├── PartnerOrganizationTest.php             (8 tests — controller)
    ├── GuestUserControllerTest.php             (5 tests — controller)
    ├── Admin/
    │   ├── AdminGraphControllerTest.php          (17 tests)
    │   ├── AdminSyncControllerTest.php           (7 tests)
    │   └── AdminUserControllerTest.php           (9 tests)
    ├── Enums/
    │   └── CloudEnvironmentTest.php              (4 tests)
    ├── CollaborationSettingsTest.php           (6 tests — admin collaboration)
    ├── AccessReviewServiceTest.php             (10 tests)
    ├── AccessReviewControllerTest.php          (11 tests)
    ├── DashboardTest.php                       (2 tests)
    └── ExampleTest.php                         (1 test)
```

## Test Configuration

### TestCase Base Class

The `TestCase.php` base class configures the test environment:

```php
abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();                                    // Skip Vite manifest
        config(['inertia.testing.ensure_pages_exist' => false]); // Skip Vue file check
    }
}
```

- `withoutVite()` — prevents `ViteManifestNotFoundException` during tests
- `ensure_pages_exist => false` — allows Inertia assertions without requiring Vue component files to exist

### Pest Configuration

```php
// tests/Pest.php
pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');
```

All Feature tests use `RefreshDatabase` to reset the database between tests.

## Mocking Graph API

All Graph API tests use `Http::fake()` to mock HTTP responses. No real API calls are made during testing.

### Standard Setup

Each test file that involves Graph API calls uses this `beforeEach`:

```php
beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant',
        'graph.client_id' => 'test-client',
        'graph.client_secret' => 'test-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
    ]);
    Cache::forget('msgraph_access_token');
});
```

### Faking Responses

```php
Http::fake([
    // Token endpoint
    'login.microsoftonline.com/*' => Http::response([
        'access_token' => 'fake',
        'expires_in' => 3600,
    ]),

    // Graph API endpoint
    'graph.microsoft.com/v1.0/invitations' => Http::response([
        'id' => 'inv-1',
        'invitedUserEmailAddress' => 'guest@external.com',
        'status' => 'PendingAcceptance',
        'invitedUser' => ['id' => 'entra-user-id-1'],
    ], 201),
]);
```

### Wildcard Matching

For endpoints with dynamic parameters:

```php
Http::fake([
    'graph.microsoft.com/v1.0/policies/crossTenantAccessPolicy/partners/*'
        => Http::response([], 204),
]);
```

## Test Examples

### Testing RBAC

```php
test('viewers cannot invite guest users', function () {
    $user = User::factory()->create(['role' => UserRole::Viewer]);

    $this->actingAs($user)
        ->post(route('guests.store'), [
            'email' => 'guest@external.com',
            'redirect_url' => 'https://myapp.com',
        ])
        ->assertForbidden();
});
```

### Testing Controller Actions

```php
test('operators can create partners', function () {
    $user = User::factory()->create(['role' => UserRole::Operator]);

    Http::fake([
        'login.microsoftonline.com/*' => Http::response([...]),
        'graph.microsoft.com/v1.0/tenantRelationships/*' => Http::response([
            'displayName' => 'Test Corp',
            'defaultDomainName' => 'testcorp.com',
        ]),
        'graph.microsoft.com/v1.0/policies/*' => Http::response([...], 201),
    ]);

    $this->actingAs($user)
        ->post(route('partners.store'), [...])
        ->assertRedirect(route('partners.index'));

    expect(PartnerOrganization::count())->toBe(1);
});
```

### Testing Sync Commands

```php
test('sync:partners creates partner records from graph api', function () {
    Http::fake([...]);

    $this->artisan('sync:partners')
        ->expectsOutput('Fetching partner configurations from Graph API...')
        ->expectsOutput('Synced 1 partner organizations.')
        ->assertSuccessful();

    expect(PartnerOrganization::count())->toBe(1);
});
```

## Writing New Tests

1. Create the test file in the appropriate `tests/Feature/` subdirectory
2. Add Graph API config in `beforeEach` if the test calls Graph endpoints
3. Use `Http::fake()` to mock all external HTTP calls
4. Use factories to create test data: `User::factory()`, `PartnerOrganization::factory()`, etc.
5. Use Pest's `expect()` API for assertions
6. Use `assertInertia()` for testing Inertia page responses
