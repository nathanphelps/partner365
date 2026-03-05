<?php

namespace App\Http\Controllers;

use App\Enums\AssignmentStatus;
use App\Enums\InvitationStatus;
use App\Enums\ReviewInstanceStatus;
use App\Models\AccessPackageAssignment;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Services\ActivityLogService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(ActivityLogService $activityLog): Response
    {
        $staleGuestQuery = fn ($q) => $q->where('last_sign_in_at', '<', now()->subDays(90))
            ->orWhereNull('last_sign_in_at');

        return Inertia::render('Dashboard', [
            'stats' => [
                'total_partners' => PartnerOrganization::count(),
                'total_guests' => GuestUser::count(),
                'pending_invitations' => GuestUser::where('invitation_status', InvitationStatus::PendingAcceptance)->count(),
                'stale_guests' => GuestUser::where($staleGuestQuery)->count(),
                'overdue_reviews' => AccessReviewInstance::whereIn('status', [
                    ReviewInstanceStatus::Pending,
                    ReviewInstanceStatus::InProgress,
                ])->where('due_at', '<', now())->count(),
            ],
            'pendingApprovals' => AccessPackageAssignment::with('accessPackage')
                ->where('status', AssignmentStatus::PendingApproval)
                ->orderBy('requested_at')
                ->limit(5)
                ->get()
                ->map(fn ($a) => [
                    'id' => $a->id,
                    'access_package_id' => $a->access_package_id,
                    'access_package_name' => $a->accessPackage?->display_name,
                    'target_user_email' => $a->target_user_email,
                    'requested_at' => $a->requested_at?->toISOString(),
                ]),
            'attentionPartners' => PartnerOrganization::where('trust_score', '<', 70)
                ->whereNotNull('trust_score')
                ->orderBy('trust_score')
                ->limit(5)
                ->withCount(['guestUsers as stale_guests_count' => $staleGuestQuery])
                ->get()
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'display_name' => $p->display_name,
                    'trust_score' => $p->trust_score,
                    'stale_guests_count' => $p->stale_guests_count,
                ]),
            'recentActivity' => $activityLog->recent(10),
        ]);
    }
}
