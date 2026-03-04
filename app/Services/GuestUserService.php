<?php

namespace App\Services;

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
}
