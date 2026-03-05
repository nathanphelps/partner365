<?php

namespace App\Console\Commands;

use App\Enums\RecurrenceType;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\SyncLog;
use App\Services\AccessReviewService;
use Illuminate\Console\Command;

class SyncAccessReviews extends Command
{
    protected $signature = 'sync:access-reviews';

    protected $description = 'Sync access review instances and expire overdue reviews';

    public function handle(AccessReviewService $reviewService): int
    {
        $log = SyncLog::create([
            'type' => 'access_reviews',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $synced = 0;

            // Expire overdue instances
            $expired = AccessReviewInstance::whereIn('status', [
                ReviewInstanceStatus::Pending,
                ReviewInstanceStatus::InProgress,
            ])->where('due_at', '<', now())->get();

            foreach ($expired as $instance) {
                $instance->update(['status' => ReviewInstanceStatus::Expired]);
                $synced++;
            }

            $this->info("Expired {$expired->count()} overdue instances.");

            // Create new instances for overdue recurring reviews
            $dueReviews = AccessReview::where('recurrence_type', RecurrenceType::Recurring)
                ->whereNotNull('next_review_at')
                ->where('next_review_at', '<=', now())
                ->get();

            foreach ($dueReviews as $review) {
                $reviewService->createInstanceWithDecisions($review);
                $review->update([
                    'next_review_at' => now()->addDays($review->recurrence_interval_days),
                ]);
                $synced++;
            }

            $this->info("Created {$dueReviews->count()} new review instances.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $synced,
                'completed_at' => now(),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->error("Sync failed: {$e->getMessage()}");

            return Command::FAILURE;
        }
    }
}
