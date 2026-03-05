<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Exceptions\GraphApiException;
use App\Http\Requests\BulkGuestActionRequest;
use App\Http\Requests\InviteGuestRequest;
use App\Http\Requests\UpdateGuestRequest;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\GuestUserService;
use Illuminate\Http\JsonResponse;
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

        if ($request->has('account_enabled')) {
            $query->where('account_enabled', $request->boolean('account_enabled'));
        }

        $sortField = $request->input('sort', 'created_at');
        $sortDirection = $request->input('direction', 'desc');
        $allowedSorts = ['created_at', 'last_sign_in_at', 'display_name', 'email'];

        if (! in_array($sortField, $allowedSorts)) {
            $sortField = 'created_at';
        }
        if (! in_array($sortDirection, ['asc', 'desc'])) {
            $sortDirection = 'desc';
        }

        $guests = $query->orderBy($sortField, $sortDirection)->paginate(25)->withQueryString();

        return Inertia::render('guests/Index', [
            'guests' => $guests,
            'filters' => $request->only(['search', 'partner_id', 'status', 'account_enabled', 'sort', 'direction']),
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name']),
            'canManage' => $request->user()->role->canManage(),
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
        if (! request()->user()->role->canManage()) {
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

    public function update(UpdateGuestRequest $request, GuestUser $guest): RedirectResponse
    {
        $validated = $request->validated();

        if (isset($validated['display_name'])) {
            $this->guestService->updateUser($guest->entra_user_id, [
                'displayName' => $validated['display_name'],
            ]);
            $this->activityLog->log($request->user(), ActivityAction::GuestUpdated, $guest, [
                'display_name' => $validated['display_name'],
            ]);
        }

        if (isset($validated['account_enabled'])) {
            if ($validated['account_enabled']) {
                $this->guestService->enableUser($guest->entra_user_id);
            } else {
                $this->guestService->disableUser($guest->entra_user_id);
            }

            $action = $validated['account_enabled']
                ? ActivityAction::GuestEnabled
                : ActivityAction::GuestDisabled;
            $this->activityLog->log($request->user(), $action, $guest, ['email' => $guest->email]);
        }

        $guest->update($validated);

        return redirect()->back()->with('success', 'Guest user updated.');
    }

    public function resendInvitation(Request $request, GuestUser $guest): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $this->guestService->resendInvitation(
            $guest->email,
            config('app.url'),
        );

        $this->activityLog->log($request->user(), ActivityAction::GuestInvited, $guest, [
            'email' => $guest->email,
            'resend' => true,
        ]);

        return redirect()->back()->with('success', "Invitation resent to {$guest->email}.");
    }

    public function bulkAction(BulkGuestActionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $guests = GuestUser::whereIn('id', $validated['ids'])->get();
        $succeeded = [];
        $failed = [];

        foreach ($guests as $guest) {
            try {
                match ($validated['action']) {
                    'enable' => $this->handleBulkEnable($request->user(), $guest),
                    'disable' => $this->handleBulkDisable($request->user(), $guest),
                    'delete' => $this->handleBulkDelete($request->user(), $guest),
                    'resend' => $this->handleBulkResend($request->user(), $guest),
                };
                $succeeded[] = $guest->id;
            } catch (\Throwable $e) {
                $failed[] = ['id' => $guest->id, 'error' => $e->getMessage()];
            }
        }

        return response()->json(['succeeded' => $succeeded, 'failed' => $failed]);
    }

    public function destroy(GuestUser $guest): RedirectResponse
    {
        if (! request()->user()->role->isAdmin()) {
            abort(403);
        }

        $this->guestService->deleteUser($guest->entra_user_id);

        $this->activityLog->log(request()->user(), ActivityAction::GuestRemoved, $guest, [
            'email' => $guest->email,
        ]);

        $guest->delete();

        return redirect()->route('guests.index')->with('success', 'Guest user removed.');
    }

    public function groups(GuestUser $guest): JsonResponse
    {
        try {
            return response()->json($this->guestService->getUserGroups($guest->entra_user_id));
        } catch (GraphApiException) {
            return response()->json(['error' => 'Unable to load groups from Microsoft Graph API.'], 502);
        }
    }

    public function apps(GuestUser $guest): JsonResponse
    {
        try {
            return response()->json($this->guestService->getUserApps($guest->entra_user_id));
        } catch (GraphApiException) {
            return response()->json(['error' => 'Unable to load apps from Microsoft Graph API.'], 502);
        }
    }

    public function teams(GuestUser $guest): JsonResponse
    {
        try {
            return response()->json($this->guestService->getUserTeams($guest->entra_user_id));
        } catch (GraphApiException) {
            return response()->json(['error' => 'Unable to load teams from Microsoft Graph API.'], 502);
        }
    }

    public function sites(GuestUser $guest): JsonResponse
    {
        try {
            return response()->json($this->guestService->getUserSites($guest->entra_user_id));
        } catch (GraphApiException) {
            return response()->json(['error' => 'Unable to load sites from Microsoft Graph API.'], 502);
        }
    }

    private function handleBulkEnable(User $user, GuestUser $guest): void
    {
        $this->guestService->enableUser($guest->entra_user_id);
        $guest->update(['account_enabled' => true]);
        $this->activityLog->log($user, ActivityAction::GuestEnabled, $guest, ['email' => $guest->email]);
    }

    private function handleBulkDisable(User $user, GuestUser $guest): void
    {
        $this->guestService->disableUser($guest->entra_user_id);
        $guest->update(['account_enabled' => false]);
        $this->activityLog->log($user, ActivityAction::GuestDisabled, $guest, ['email' => $guest->email]);
    }

    private function handleBulkDelete(User $user, GuestUser $guest): void
    {
        if (! $user->role->isAdmin()) {
            throw new \RuntimeException('Only admins can delete guest users.');
        }

        $this->guestService->deleteUser($guest->entra_user_id);
        $this->activityLog->log($user, ActivityAction::GuestRemoved, $guest, ['email' => $guest->email]);
        $guest->delete();
    }

    private function handleBulkResend(User $user, GuestUser $guest): void
    {
        $this->guestService->resendInvitation($guest->email, config('app.url'));
        $this->activityLog->log($user, ActivityAction::GuestInvited, $guest, ['email' => $guest->email, 'resend' => true]);
    }
}
