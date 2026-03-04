<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Services\ActivityLogService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(ActivityLogService $activityLog): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'total_partners' => PartnerOrganization::count(),
                'mfa_trust_enabled' => PartnerOrganization::where('mfa_trust_enabled', true)->count(),
                'mfa_trust_disabled' => PartnerOrganization::where('mfa_trust_enabled', false)->count(),
                'total_guests' => GuestUser::count(),
                'pending_invitations' => GuestUser::where('invitation_status', InvitationStatus::PendingAcceptance)->count(),
                'inactive_guests' => GuestUser::where(function ($q) {
                    $q->where('last_sign_in_at', '<', now()->subDays(90))
                        ->orWhereNull('last_sign_in_at');
                })->count(),
                'partners_by_category' => PartnerOrganization::selectRaw('category, count(*) as count')
                    ->groupBy('category')
                    ->pluck('count', 'category'),
            ],
            'recentActivity' => $activityLog->recent(20),
        ]);
    }
}
