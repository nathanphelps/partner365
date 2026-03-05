<?php

namespace App\Http\Controllers;

use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceReportController extends Controller
{
    public function index(Request $request): Response
    {
        $totalPartners = PartnerOrganization::count();
        $now = now();

        // Partner compliance metrics
        $noMfa = PartnerOrganization::where('mfa_trust_enabled', false)
            ->get(['id', 'display_name', 'domain', 'tenant_id']);
        $noDeviceTrust = PartnerOrganization::where('device_trust_enabled', false)
            ->get(['id', 'display_name', 'domain', 'tenant_id']);
        $overlyPermissive = PartnerOrganization::where('b2b_inbound_enabled', true)
            ->where('b2b_outbound_enabled', true)
            ->get(['id', 'display_name', 'domain', 'tenant_id']);

        $partnersWithCaPolicies = DB::table('conditional_access_policy_partner')
            ->distinct()
            ->pluck('partner_organization_id');
        $noCaPolicies = PartnerOrganization::whereNotIn('id', $partnersWithCaPolicies)
            ->get(['id', 'display_name', 'domain', 'tenant_id']);

        // Compliance score: MFA enabled AND not overly permissive
        $compliantCount = PartnerOrganization::where('mfa_trust_enabled', true)
            ->where(function ($q) {
                $q->where('b2b_inbound_enabled', false)
                    ->orWhere('b2b_outbound_enabled', false);
            })
            ->count();

        $complianceScore = $totalPartners > 0
            ? (int) round(($compliantCount / $totalPartners) * 100)
            : 100;

        $avgTrustScore = PartnerOrganization::whereNotNull('trust_score')->avg('trust_score');

        // Guest health metrics
        $totalGuests = GuestUser::count();
        $stale30 = GuestUser::where(function ($q) use ($now) {
            $q->where('last_sign_in_at', '<', $now->copy()->subDays(30))
                ->orWhereNull('last_sign_in_at');
        })->count();
        $stale60 = GuestUser::where(function ($q) use ($now) {
            $q->where('last_sign_in_at', '<', $now->copy()->subDays(60))
                ->orWhereNull('last_sign_in_at');
        })->count();
        $stale90 = GuestUser::where(function ($q) use ($now) {
            $q->where('last_sign_in_at', '<', $now->copy()->subDays(90))
                ->orWhereNull('last_sign_in_at');
        })->count();
        $neverSignedIn = GuestUser::whereNull('last_sign_in_at')->count();
        $pendingInvitations = GuestUser::where('invitation_status', 'pending_acceptance')->count();
        $disabledAccounts = GuestUser::where('account_enabled', false)->count();

        // Stale guest list (30+ days or never signed in)
        $staleGuests = GuestUser::with('partnerOrganization:id,display_name')
            ->where(function ($q) use ($now) {
                $q->where('last_sign_in_at', '<', $now->copy()->subDays(30))
                    ->orWhereNull('last_sign_in_at');
            })
            ->orderBy('last_sign_in_at')
            ->get(['id', 'email', 'display_name', 'partner_organization_id', 'last_sign_in_at', 'invitation_status', 'account_enabled']);

        // Non-compliant partner list
        $nonCompliantIds = $noMfa->pluck('id')
            ->merge($noDeviceTrust->pluck('id'))
            ->merge($overlyPermissive->pluck('id'))
            ->merge($noCaPolicies->pluck('id'))
            ->unique();

        $nonCompliantPartners = PartnerOrganization::whereIn('id', $nonCompliantIds)
            ->withCount('conditionalAccessPolicies')
            ->get(['id', 'display_name', 'domain', 'mfa_trust_enabled', 'device_trust_enabled', 'b2b_inbound_enabled', 'b2b_outbound_enabled', 'trust_score']);

        return Inertia::render('reports/Index', [
            'summary' => [
                'compliance_score' => $complianceScore,
                'partners_with_issues' => $nonCompliantIds->count(),
                'stale_guests_90' => $stale90,
                'total_partners' => $totalPartners,
                'total_guests' => $totalGuests,
                'avg_trust_score' => $avgTrustScore ? round((float) $avgTrustScore, 1) : null,
            ],
            'partnerCompliance' => [
                'no_mfa_count' => $noMfa->count(),
                'no_device_trust_count' => $noDeviceTrust->count(),
                'overly_permissive_count' => $overlyPermissive->count(),
                'no_ca_policies_count' => $noCaPolicies->count(),
                'partners' => $nonCompliantPartners,
            ],
            'guestHealth' => [
                'stale_30_plus' => $stale30,
                'stale_60_plus' => $stale60,
                'stale_90_plus' => $stale90,
                'never_signed_in' => $neverSignedIn,
                'pending_invitations' => $pendingInvitations,
                'disabled_accounts' => $disabledAccounts,
                'guests' => $staleGuests,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $partners = PartnerOrganization::withCount('conditionalAccessPolicies')
            ->orderBy('display_name')
            ->get();

        $guests = GuestUser::with('partnerOrganization:id,display_name')
            ->orderBy('last_sign_in_at')
            ->get();

        return response()->stream(function () use ($partners, $guests) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['--- Partner Policy Compliance ---']);
            fputcsv($handle, ['Partner Name', 'Domain', 'MFA Trust', 'Device Trust', 'B2B Inbound', 'B2B Outbound', 'Trust Score', 'CA Policy Count']);

            foreach ($partners as $partner) {
                fputcsv($handle, [
                    $partner->display_name,
                    $partner->domain,
                    $partner->mfa_trust_enabled ? 'Yes' : 'No',
                    $partner->device_trust_enabled ? 'Yes' : 'No',
                    $partner->b2b_inbound_enabled ? 'Yes' : 'No',
                    $partner->b2b_outbound_enabled ? 'Yes' : 'No',
                    $partner->trust_score,
                    $partner->conditional_access_policies_count,
                ]);
            }

            fputcsv($handle, []);
            fputcsv($handle, ['--- Guest Account Health ---']);
            fputcsv($handle, ['Guest Email', 'Display Name', 'Partner', 'Last Sign-In', 'Days Inactive', 'Invitation Status', 'Account Enabled']);

            foreach ($guests as $guest) {
                $daysInactive = $guest->last_sign_in_at
                    ? (int) now()->diffInDays($guest->last_sign_in_at)
                    : null;

                fputcsv($handle, [
                    $guest->email,
                    $guest->display_name,
                    $guest->partnerOrganization?->display_name,
                    $guest->last_sign_in_at?->format('Y-m-d'),
                    $daysInactive ?? 'Never',
                    $guest->invitation_status instanceof \BackedEnum ? $guest->invitation_status->value : $guest->invitation_status,
                    $guest->account_enabled ? 'Yes' : 'No',
                ]);
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="compliance-report.csv"',
        ]);
    }
}
