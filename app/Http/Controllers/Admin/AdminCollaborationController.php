<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCollaborationSettingsRequest;
use App\Services\ActivityLogService;
use App\Services\CollaborationSettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AdminCollaborationController extends Controller
{
    public function __construct(
        private CollaborationSettingsService $collaborationService,
        private ActivityLogService $activityLog,
    ) {}

    public function edit(): Response
    {
        $policy = $this->collaborationService->getSettings();

        return Inertia::render('admin/Collaboration', [
            'settings' => [
                'allow_invites_from' => $policy['allowInvitesFrom'] ?? 'adminsAndGuestInviters',
                'allowed_domains' => collect($policy['allowedToInvite'] ?? [])->pluck('domain')->values()->all(),
                'blocked_domains' => collect($policy['blockedFromInvite'] ?? [])->pluck('domain')->values()->all(),
            ],
        ]);
    }

    public function update(UpdateCollaborationSettingsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $config = [
            'allowInvitesFrom' => $validated['allow_invites_from'],
        ];

        if ($validated['domain_restriction_mode'] === 'allowList') {
            $config['allowedToInvite'] = array_map(
                fn ($d) => ['domain' => $d],
                $validated['allowed_domains'] ?? []
            );
            $config['blockedFromInvite'] = [];
        } elseif ($validated['domain_restriction_mode'] === 'blockList') {
            $config['blockedFromInvite'] = array_map(
                fn ($d) => ['domain' => $d],
                $validated['blocked_domains'] ?? []
            );
            $config['allowedToInvite'] = [];
        } else {
            $config['allowedToInvite'] = [];
            $config['blockedFromInvite'] = [];
        }

        $this->collaborationService->updateSettings($config);

        $this->activityLog->log($request->user(), ActivityAction::SettingsUpdated, null, [
            'group' => 'collaboration',
        ]);

        return redirect()->route('admin.collaboration.edit')->with('success', 'Collaboration settings updated.');
    }
}
