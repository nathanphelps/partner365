<?php

namespace App\Console\Commands;

use App\Enums\InvitationStatus;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Services\GuestUserService;
use Illuminate\Console\Command;

class SyncGuests extends Command
{
    protected $signature = 'sync:guests';
    protected $description = 'Sync guest users from Microsoft Graph API';

    public function handle(GuestUserService $guestService): int
    {
        $this->info('Fetching guest users from Graph API...');

        $guests = $guestService->listGuests();
        $synced = 0;

        foreach ($guests as $guest) {
            $email = $guest['mail'] ?? $guest['otherMails'][0] ?? null;
            $domain = $email ? substr($email, strpos($email, '@') + 1) : null;

            $partnerId = null;
            if ($domain) {
                $partner = PartnerOrganization::where('domain', $domain)->first();
                $partnerId = $partner?->id;
            }

            GuestUser::updateOrCreate(
                ['entra_user_id' => $guest['id']],
                [
                    'email' => $email,
                    'display_name' => $guest['displayName'] ?? $email,
                    'user_principal_name' => $guest['userPrincipalName'] ?? null,
                    'partner_organization_id' => $partnerId,
                    'invitation_status' => InvitationStatus::Accepted,
                    'last_sign_in_at' => isset($guest['signInActivity']['lastSignInDateTime'])
                        ? $guest['signInActivity']['lastSignInDateTime']
                        : null,
                    'last_synced_at' => now(),
                ]
            );

            $synced++;
        }

        $this->info("Synced {$synced} guest users.");

        return Command::SUCCESS;
    }
}
