<?php

namespace App\Services;

use App\Exceptions\GraphApiException;
use Illuminate\Support\Facades\Cache;

class GuestUserService
{
    private const GUEST_SELECT_FIELDS = 'id,displayName,mail,userPrincipalName,userType,accountEnabled,createdDateTime,externalUserState,signInActivity';

    public function __construct(private MicrosoftGraphService $graph) {}

    public function listGuests(): array
    {
        $response = $this->graph->get('/users', [
            '$filter' => "userType eq 'Guest'",
            '$select' => self::GUEST_SELECT_FIELDS,
            '$top' => 999,
        ]);

        return $response['value'] ?? [];
    }

    public function getUser(string $userId): array
    {
        return $this->graph->get("/users/{$userId}", [
            '$select' => self::GUEST_SELECT_FIELDS,
        ]);
    }

    public function invite(string $email, string $redirectUrl, ?string $customMessage = null, bool $sendEmail = true): array
    {
        $body = [
            'invitedUserEmailAddress' => $email,
            'inviteRedirectUrl' => $redirectUrl,
            'sendInvitationMessage' => $sendEmail,
        ];

        if ($customMessage) {
            $body['invitedUserMessageInfo'] = [
                'customizedMessageBody' => $customMessage,
            ];
        }

        return $this->graph->post('/invitations', $body);
    }

    public function deleteUser(string $userId): array
    {
        return $this->graph->delete("/users/{$userId}");
    }

    public function updateUser(string $userId, array $data): array
    {
        return $this->graph->patch("/users/{$userId}", $data);
    }

    public function enableUser(string $userId): array
    {
        return $this->graph->patch("/users/{$userId}", [
            'accountEnabled' => true,
        ]);
    }

    public function disableUser(string $userId): array
    {
        return $this->graph->patch("/users/{$userId}", [
            'accountEnabled' => false,
        ]);
    }

    public function resendInvitation(string $email, string $redirectUrl): array
    {
        return $this->graph->post('/invitations', [
            'invitedUserEmailAddress' => $email,
            'inviteRedirectUrl' => $redirectUrl,
            'sendInvitationMessage' => true,
        ]);
    }

    public function getUserGroups(string $entraUserId): array
    {
        return Cache::remember("guest_access:{$entraUserId}:groups", 300, function () use ($entraUserId) {
            $response = $this->graph->get("/users/{$entraUserId}/memberOf", [
                '$select' => 'id,displayName,groupTypes,securityEnabled,mailEnabled,description',
                '$top' => 999,
            ]);

            return collect($response['value'] ?? [])
                ->filter(fn ($item) => ($item['@odata.type'] ?? '') === '#microsoft.graph.group')
                ->map(fn ($group) => [
                    'id' => $group['id'],
                    'displayName' => $group['displayName'] ?? '',
                    'groupType' => $this->resolveGroupType($group),
                    'description' => $group['description'] ?? null,
                ])
                ->values()
                ->all();
        });
    }

    public function getUserApps(string $entraUserId): array
    {
        return Cache::remember("guest_access:{$entraUserId}:apps", 300, function () use ($entraUserId) {
            $response = $this->graph->get("/users/{$entraUserId}/appRoleAssignments", [
                '$top' => 999,
            ]);

            return collect($response['value'] ?? [])
                ->map(fn ($assignment) => [
                    'id' => $assignment['id'],
                    'appDisplayName' => $assignment['resourceDisplayName'] ?? 'Unknown App',
                    'roleName' => $assignment['appRoleId'] === '00000000-0000-0000-0000-000000000000'
                        ? 'Default Access'
                        : ($assignment['appRoleId'] ?? null),
                    'assignedAt' => $assignment['createdDateTime'] ?? null,
                ])
                ->values()
                ->all();
        });
    }

    public function getUserTeams(string $entraUserId): array
    {
        return Cache::remember("guest_access:{$entraUserId}:teams", 300, function () use ($entraUserId) {
            $response = $this->graph->get("/users/{$entraUserId}/joinedTeams", [
                '$select' => 'id,displayName,description',
                '$top' => 999,
            ]);

            return collect($response['value'] ?? [])
                ->map(fn ($team) => [
                    'id' => $team['id'],
                    'displayName' => $team['displayName'] ?? '',
                    'description' => $team['description'] ?? null,
                ])
                ->values()
                ->all();
        });
    }

    public function getUserSites(string $entraUserId): array
    {
        return Cache::remember("guest_access:{$entraUserId}:sites", 300, function () use ($entraUserId) {
            $response = $this->graph->get("/users/{$entraUserId}/memberOf", [
                '$select' => 'id,displayName,groupTypes,securityEnabled,mailEnabled,description',
                '$top' => 999,
            ]);

            $m365Groups = collect($response['value'] ?? [])
                ->filter(fn ($item) => ($item['@odata.type'] ?? '') === '#microsoft.graph.group')
                ->filter(fn ($group) => in_array('Unified', $group['groupTypes'] ?? []));

            $sites = [];
            foreach ($m365Groups as $group) {
                try {
                    $site = $this->graph->get("/groups/{$group['id']}/sites/root", [
                        '$select' => 'id,displayName,webUrl',
                    ]);
                    $sites[] = [
                        'id' => $site['id'],
                        'displayName' => $site['displayName'] ?? '',
                        'webUrl' => $site['webUrl'] ?? '',
                    ];
                } catch (GraphApiException) {
                    continue;
                }
            }

            return $sites;
        });
    }

    private function resolveGroupType(array $group): string
    {
        if (in_array('Unified', $group['groupTypes'] ?? [])) {
            return 'microsoft365';
        }
        if ($group['securityEnabled'] ?? false) {
            return 'security';
        }

        return 'distribution';
    }
}
