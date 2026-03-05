<?php

use App\Enums\ActivityAction;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

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

test('activity log passes filters and users to frontend', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('activity.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('filters')
            ->has('users')
        );
});
