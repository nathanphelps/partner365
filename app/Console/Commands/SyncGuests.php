<?php

namespace App\Console\Commands;

use App\Enums\ActivityAction;
use App\Enums\InvitationStatus;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\SyncLog;
use App\Services\ActivityLogService;
use App\Services\GuestUserService;
use Illuminate\Console\Command;

class SyncGuests extends Command
{
    protected $signature = 'sync:guests';

    protected $description = 'Sync guest users from Microsoft Graph API';

    public function handle(GuestUserService $guestService): int
    {
        $log = SyncLog::create([
            'type' => 'guests',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $this->info('Fetching guest users from Graph API...');

            $guests = $guestService->listGuests();
            $synced = 0;

            $partners = PartnerOrganization::all();

            foreach ($guests as $guest) {
                $email = $guest['mail'] ?? $guest['otherMails'][0] ?? null;
                $upn = $guest['userPrincipalName'] ?? '';
                $homeDomain = $this->extractHomeDomain($upn);

                $partnerId = $this->matchPartner($partners, $homeDomain, $email);

                GuestUser::updateOrCreate(
                    ['entra_user_id' => $guest['id']],
                    [
                        'email' => $email,
                        'display_name' => $guest['displayName'] ?? $email,
                        'user_principal_name' => $guest['userPrincipalName'] ?? null,
                        'partner_organization_id' => $partnerId,
                        'invitation_status' => InvitationStatus::Accepted,
                        'account_enabled' => $guest['accountEnabled'] ?? true,
                        'last_sign_in_at' => isset($guest['signInActivity']['lastSignInDateTime'])
                            ? $guest['signInActivity']['lastSignInDateTime']
                            : null,
                        'last_synced_at' => now(),
                    ]
                );

                $synced++;
            }

            $this->info("Synced {$synced} guest users.");

            $log->update([
                'status' => 'completed',
                'records_synced' => $synced,
                'completed_at' => now(),
            ]);

            app(ActivityLogService::class)->logSystem(ActivityAction::SyncCompleted, details: [
                'type' => 'guests',
                'records_synced' => $synced,
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

    /**
     * Extract the home domain from a B2B guest UPN.
     * e.g. "admin-bowie_gd-ots.com#EXT#@gdotsdevtest.onmicrosoft.us" → "gd-ots.com"
     */
    private function extractHomeDomain(string $upn): ?string
    {
        if (preg_match('/^.+_(.+)#EXT#@/i', $upn, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, PartnerOrganization>  $partners
     */
    private function matchPartner($partners, ?string $homeDomain, ?string $email): ?int
    {
        $emailDomain = $email ? strtolower(substr($email, strpos($email, '@') + 1)) : null;

        foreach ($partners as $partner) {
            $partnerDomain = strtolower($partner->domain);

            if ($homeDomain && $homeDomain === $partnerDomain) {
                return $partner->id;
            }

            if ($emailDomain && $emailDomain === $partnerDomain) {
                return $partner->id;
            }
        }

        return null;
    }
}
