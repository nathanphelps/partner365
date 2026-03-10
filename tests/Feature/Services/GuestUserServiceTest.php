<?php

use App\Services\GuestUserService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'graph.tenant_id' => 'test-tenant',
        'graph.client_id' => 'test-client',
        'graph.client_secret' => 'test-secret',
        'graph.base_url' => 'https://graph.microsoft.com/v1.0',
        'graph.scopes' => 'https://graph.microsoft.com/.default',
        'graph.cloud_environment' => 'commercial',
    ]);
    \App\Models\Setting::query()->delete();
    Cache::forget('msgraph_access_token');
});

test('it lists guest users including promoted B2B members', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users*' => Http::response([
            'value' => [
                ['id' => 'u1', 'displayName' => 'Guest One', 'userType' => 'Guest'],
                ['id' => 'u2', 'displayName' => 'Guest Two', 'userType' => 'Guest'],
                ['id' => 'u3', 'displayName' => 'B2B Member', 'userType' => 'Member', 'externalUserState' => 'Accepted'],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
    $guests = $service->listGuests();

    expect($guests)->toHaveCount(3);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'externalUserState')
            && $request->hasHeader('ConsistencyLevel', 'eventual');
    });
});

test('it creates an invitation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-1',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'user-id-1'],
        ], 201),
    ]);

    $service = app(GuestUserService::class);
    $result = $service->invite('guest@partner.com', 'https://myapp.com');

    expect($result['invitedUserEmailAddress'])->toBe('guest@partner.com');
    expect($result['invitedUser']['id'])->toBe('user-id-1');
});

test('it gets a single guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1*' => Http::response([
            'id' => 'u1',
            'displayName' => 'Guest One',
            'mail' => 'guest@partner.com',
        ]),
    ]);

    $service = app(GuestUserService::class);
    $user = $service->getUser('u1');

    expect($user['displayName'])->toBe('Guest One');
});

test('it deletes a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->deleteUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
});

test('it enables a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->enableUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/users/u1')
        && $request->data()['accountEnabled'] === true
    );
});

test('it disables a guest user', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1' => Http::response([], 204),
    ]);

    $service = app(GuestUserService::class);
    $service->disableUser('u1');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), '/users/u1')
        && $request->data()['accountEnabled'] === false
    );
});

test('it resends an invitation', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/invitations' => Http::response([
            'id' => 'inv-2',
            'invitedUserEmailAddress' => 'guest@partner.com',
            'status' => 'PendingAcceptance',
            'invitedUser' => ['id' => 'u1'],
        ], 201),
    ]);

    $service = app(GuestUserService::class);
    $result = $service->resendInvitation('guest@partner.com', 'https://myapp.com');

    expect($result['invitedUserEmailAddress'])->toBe('guest@partner.com');
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/invitations')
    );
});

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
    $sites = $service->getUserSites('u1');

    expect($sites)->toHaveCount(1);
    expect($sites[0])->toMatchArray([
        'id' => 's1',
        'displayName' => 'Project Team Site',
        'webUrl' => 'https://contoso.sharepoint.com/sites/project-team',
    ]);
});

test('it returns empty array when user has no m365 groups for sites', function () {
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'fake', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/users/u1/memberOf*' => Http::response([
            'value' => [
                ['@odata.type' => '#microsoft.graph.group', 'id' => 'g1', 'groupTypes' => [], 'securityEnabled' => true, 'mailEnabled' => false, 'description' => null, 'displayName' => 'Security Group'],
            ],
        ]),
    ]);

    $service = app(GuestUserService::class);
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
    $sites = $service->getUserSites('u1');

    expect($sites)->toHaveCount(1);
    expect($sites[0]['displayName'])->toBe('Team B Site');
});
