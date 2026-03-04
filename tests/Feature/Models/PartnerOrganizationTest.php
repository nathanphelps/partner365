<?php

use App\Enums\PartnerCategory;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;

test('partner organization belongs to an owner', function () {
    $user = User::factory()->create();
    $partner = PartnerOrganization::factory()->create(['owner_user_id' => $user->id]);

    expect($partner->owner->id)->toBe($user->id);
});

test('partner organization has many guest users', function () {
    $partner = PartnerOrganization::factory()->create();
    GuestUser::factory()->count(3)->create(['partner_organization_id' => $partner->id]);

    expect($partner->guestUsers)->toHaveCount(3);
});

test('partner organization casts category to enum', function () {
    $partner = PartnerOrganization::factory()->create(['category' => 'vendor']);

    expect($partner->category)->toBe(PartnerCategory::Vendor);
});
