<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\InviteGuestRequest;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Services\ActivityLogService;
use App\Services\GuestUserService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GuestUserController extends Controller
{
    public function __construct(
        private GuestUserService $guestService,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $query = GuestUser::with(['partnerOrganization', 'invitedBy']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('display_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($partnerId = $request->input('partner_id')) {
            $query->where('partner_organization_id', $partnerId);
        }

        if ($status = $request->input('status')) {
            $query->where('invitation_status', $status);
        }

        $guests = $query->orderByDesc('created_at')->paginate(25)->withQueryString();

        return Inertia::render('guests/Index', [
            'guests' => $guests,
            'filters' => $request->only(['search', 'partner_id', 'status']),
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name']),
        ]);
    }

    public function show(GuestUser $guest): Response
    {
        $guest->load(['partnerOrganization', 'invitedBy']);

        return Inertia::render('guests/Show', [
            'guest' => $guest,
        ]);
    }

    public function create(): Response
    {
        if (!request()->user()->role->canManage()) {
            abort(403);
        }

        return Inertia::render('guests/Invite', [
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name', 'domain']),
        ]);
    }

    public function store(InviteGuestRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $result = $this->guestService->invite(
            email: $validated['email'],
            redirectUrl: $validated['redirect_url'],
            customMessage: $validated['custom_message'] ?? null,
            sendEmail: $validated['send_email'] ?? true,
        );

        $invitedUser = $result['invitedUser'] ?? [];
        $emailDomain = explode('@', $validated['email'])[1] ?? null;
        $partner = $emailDomain
            ? PartnerOrganization::where('domain', $emailDomain)->first()
            : null;

        $guest = GuestUser::create([
            'entra_user_id' => $invitedUser['id'] ?? $result['id'],
            'email' => $validated['email'],
            'display_name' => $result['invitedUserDisplayName'] ?? null,
            'user_principal_name' => $invitedUser['userPrincipalName'] ?? null,
            'partner_organization_id' => $partner?->id,
            'invitation_status' => 'pending_acceptance',
            'invited_by_user_id' => $request->user()->id,
            'invited_at' => now(),
        ]);

        $this->activityLog->log($request->user(), ActivityAction::GuestInvited, $guest, [
            'email' => $validated['email'],
        ]);

        return redirect()->route('guests.index')->with('success', "Invitation sent to {$validated['email']}.");
    }

    public function destroy(GuestUser $guest): RedirectResponse
    {
        if (!request()->user()->role->isAdmin()) {
            abort(403);
        }

        $this->guestService->deleteUser($guest->entra_user_id);

        $this->activityLog->log(request()->user(), ActivityAction::GuestRemoved, $guest, [
            'email' => $guest->email,
        ]);

        $guest->delete();

        return redirect()->route('guests.index')->with('success', 'Guest user removed.');
    }
}
