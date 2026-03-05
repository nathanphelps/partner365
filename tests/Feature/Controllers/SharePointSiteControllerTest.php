<?php

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SharePointSite;
use App\Models\SharePointSitePermission;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->actingAs($this->user);
});

test('index page renders with sites', function () {
    SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $response = $this->get('/sharepoint-sites');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sharepoint-sites/Index')
        ->has('sites.data', 1)
        ->where('sites.data.0.display_name', 'Project Alpha')
        ->has('uncoveredPartnerCount')
    );
});

test('index shows uncovered partner count', function () {
    PartnerOrganization::factory()->count(3)->create();

    $site = SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Test',
        'url' => 'https://contoso.sharepoint.com/sites/test',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    $partner = PartnerOrganization::first();
    $guest = GuestUser::factory()->create(['partner_organization_id' => $partner->id]);
    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'read',
        'granted_via' => 'direct',
    ]);

    $response = $this->get('/sharepoint-sites');

    $response->assertInertia(fn ($page) => $page
        ->where('uncoveredPartnerCount', 2)
    );
});

test('show page renders with site and permissions', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create([
        'partner_organization_id' => $partner->id,
        'email' => 'guest@partner.com',
    ]);

    $site = SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'write',
        'granted_via' => 'direct',
    ]);

    $response = $this->get("/sharepoint-sites/{$site->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('sharepoint-sites/Show')
        ->where('site.display_name', 'Project Alpha')
        ->has('site.permissions', 1)
    );
});

test('partner show page includes sharepoint sites', function () {
    $partner = PartnerOrganization::factory()->create();
    $guest = GuestUser::factory()->create(['partner_organization_id' => $partner->id]);

    $site = SharePointSite::create([
        'site_id' => 's-1',
        'display_name' => 'Project Alpha',
        'url' => 'https://contoso.sharepoint.com/sites/alpha',
        'external_sharing_capability' => 'ExternalUserAndGuestSharing',
        'synced_at' => now(),
    ]);

    SharePointSitePermission::create([
        'sharepoint_site_id' => $site->id,
        'guest_user_id' => $guest->id,
        'role' => 'read',
        'granted_via' => 'direct',
    ]);

    $response = $this->get("/partners/{$partner->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('sharePointSites', 1)
    );
});

test('viewer role can access sharepoint sites index', function () {
    $viewer = User::factory()->create(['role' => 'viewer', 'approved_at' => now()]);
    $this->actingAs($viewer);

    $response = $this->get('/sharepoint-sites');

    $response->assertOk();
});
