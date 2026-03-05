<?php

use App\Enums\RecurrenceType;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\SyncLog;

test('sync command creates new instances for overdue recurring reviews', function () {
    $review = AccessReview::factory()->create([
        'recurrence_type' => RecurrenceType::Recurring,
        'recurrence_interval_days' => 30,
        'next_review_at' => now()->subDay(),
    ]);
    GuestUser::factory()->count(2)->create();

    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect(AccessReviewInstance::where('access_review_id', $review->id)->count())->toBe(1);
    $review->refresh();
    expect($review->next_review_at->isFuture())->toBeTrue();
});

test('sync command does not create instance for future reviews', function () {
    AccessReview::factory()->create([
        'recurrence_type' => RecurrenceType::Recurring,
        'recurrence_interval_days' => 30,
        'next_review_at' => now()->addDays(15),
    ]);

    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect(AccessReviewInstance::count())->toBe(0);
});

test('sync command expires overdue instances', function () {
    $instance = AccessReviewInstance::factory()->create([
        'status' => ReviewInstanceStatus::InProgress,
        'due_at' => now()->subDay(),
    ]);

    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect($instance->fresh()->status)->toBe(ReviewInstanceStatus::Expired);
});

test('sync command logs to sync_logs', function () {
    $this->artisan('sync:access-reviews')
        ->assertSuccessful();

    expect(SyncLog::where('type', 'access_reviews')->count())->toBe(1);
    expect(SyncLog::where('type', 'access_reviews')->first()->status)->toBe('completed');
});
