<?php

namespace Database\Seeders;

use App\Enums\ActivityAction;
use App\Enums\InvitationStatus;
use App\Enums\PartnerCategory;
use App\Enums\UserRole;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\PartnerTemplate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DevSeeder extends Seeder
{
    public function run(): void
    {
        $this->truncateAll();

        $users = $this->seedUsers();
        $partners = $this->seedPartners($users);
        $guests = $this->seedGuests($partners, $users);
        $templates = $this->seedTemplates($users['admin']);
        $this->seedActivityLogs($users, $partners, $guests, $templates);
        $this->seedSyncLogs();
        $this->seedSettings();
    }

    private function truncateAll(): void
    {
        $tables = [
            'activity_log',
            'guest_users',
            'partner_templates',
            'partner_organizations',
            'sync_logs',
            'settings',
            'users',
        ];

        DB::statement('TRUNCATE TABLE '.implode(', ', $tables).' CASCADE');
    }

    /**
     * @return array{admin: User, operators: \Illuminate\Support\Collection, viewers: \Illuminate\Support\Collection, unapproved: \Illuminate\Support\Collection}
     */
    private function seedUsers(): array
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@partner365.dev',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
        ]);

        $operators = User::factory(5)->create([
            'role' => UserRole::Operator,
        ]);

        $viewers = User::factory(10)->create([
            'role' => UserRole::Viewer,
        ]);

        $unapproved = User::factory(2)->create([
            'approved_at' => null,
        ]);

        return [
            'admin' => $admin,
            'operators' => $operators,
            'viewers' => $viewers,
            'unapproved' => $unapproved,
        ];
    }

    private function seedPartners(array $users): \Illuminate\Support\Collection
    {
        $allManagers = collect([$users['admin']])->merge($users['operators']);

        $partners = collect();

        // Create partners across all categories with realistic distributions
        $categoryDistribution = [
            PartnerCategory::Vendor->value => 8,
            PartnerCategory::Contractor->value => 6,
            PartnerCategory::StrategicPartner->value => 5,
            PartnerCategory::Customer->value => 7,
            PartnerCategory::Other->value => 4,
        ];

        foreach ($categoryDistribution as $category => $count) {
            for ($i = 0; $i < $count; $i++) {
                $policyProfile = fake()->randomElement(['permissive', 'restrictive', 'mixed']);

                $partner = PartnerOrganization::factory()->create([
                    'category' => $category,
                    'owner_user_id' => $allManagers->random()->id,
                    'b2b_inbound_enabled' => $policyProfile !== 'restrictive',
                    'b2b_outbound_enabled' => $policyProfile === 'permissive',
                    'mfa_trust_enabled' => $policyProfile !== 'restrictive',
                    'device_trust_enabled' => $policyProfile === 'permissive',
                    'direct_connect_inbound_enabled' => $policyProfile === 'permissive' && fake()->boolean(30),
                    'direct_connect_outbound_enabled' => $policyProfile === 'permissive' && fake()->boolean(30),
                    'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
                    'notes' => fake()->boolean(60) ? fake()->paragraph() : null,
                    'trust_score' => fake()->optional(0.8)->numberBetween(15, 98),
                    'trust_score_calculated_at' => fake()->optional(0.8)->dateTimeBetween('-3 days', 'now'),
                ]);

                if ($partner->trust_score !== null) {
                    $partner->update(['trust_score_breakdown' => $this->buildTrustScoreBreakdown($partner->trust_score, $policyProfile)]);
                }

                $partners->push($partner);
            }
        }

        return $partners;
    }

    private function seedGuests(\Illuminate\Support\Collection $partners, array $users): \Illuminate\Support\Collection
    {
        $inviters = collect([$users['admin']])->merge($users['operators']);
        $guests = collect();

        // 25 partners get guests, 5 remain empty
        $partnersWithGuests = $partners->shuffle()->take(25);

        foreach ($partnersWithGuests as $partner) {
            $guestCount = fake()->numberBetween(2, 8);

            for ($i = 0; $i < $guestCount; $i++) {
                $status = fake()->randomElement([
                    InvitationStatus::Accepted,
                    InvitationStatus::Accepted,
                    InvitationStatus::Accepted,
                    InvitationStatus::Accepted,
                    InvitationStatus::PendingAcceptance,
                    InvitationStatus::PendingAcceptance,
                    InvitationStatus::Failed,
                ]);

                $isAccepted = $status === InvitationStatus::Accepted;
                $invitedAt = fake()->dateTimeBetween('-90 days', '-1 day');

                $email = fake()->userName().'@'.$partner->domain;

                $guest = GuestUser::factory()->create([
                    'email' => $email,
                    'user_principal_name' => str_replace('@', '_', $email).'#EXT#@contoso.onmicrosoft.com',
                    'partner_organization_id' => $partner->id,
                    'invitation_status' => $status,
                    'invited_by_user_id' => $inviters->random()->id,
                    'invited_at' => $invitedAt,
                    'account_enabled' => $isAccepted ? fake()->boolean(85) : true,
                    'last_sign_in_at' => $isAccepted ? fake()->dateTimeBetween($invitedAt, 'now') : null,
                    'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
                ]);

                $guests->push($guest);
            }
        }

        // 5 orphaned guests with no partner
        for ($i = 0; $i < 5; $i++) {
            $guest = GuestUser::factory()->create([
                'partner_organization_id' => null,
                'invited_by_user_id' => $inviters->random()->id,
                'invited_at' => fake()->dateTimeBetween('-60 days', '-1 day'),
                'last_sign_in_at' => fake()->boolean(50) ? fake()->dateTimeBetween('-30 days', 'now') : null,
                'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
            ]);
            $guests->push($guest);
        }

        return $guests;
    }

    private function seedTemplates(User $admin): \Illuminate\Support\Collection
    {
        $templates = collect();

        $presets = [
            [
                'name' => 'Restrictive',
                'description' => 'Blocks all B2B collaboration and trust. Use for untrusted partners.',
                'policy_config' => [
                    'b2b_inbound_enabled' => false,
                    'b2b_outbound_enabled' => false,
                    'mfa_trust_enabled' => false,
                    'device_trust_enabled' => false,
                    'direct_connect_inbound_enabled' => false,
                    'direct_connect_outbound_enabled' => false,
                ],
            ],
            [
                'name' => 'Permissive',
                'description' => 'Full B2B collaboration with all trust settings enabled. Use for strategic partners.',
                'policy_config' => [
                    'b2b_inbound_enabled' => true,
                    'b2b_outbound_enabled' => true,
                    'mfa_trust_enabled' => true,
                    'device_trust_enabled' => true,
                    'direct_connect_inbound_enabled' => true,
                    'direct_connect_outbound_enabled' => true,
                ],
            ],
            [
                'name' => 'MFA Only',
                'description' => 'Inbound B2B with MFA trust. No outbound or device trust.',
                'policy_config' => [
                    'b2b_inbound_enabled' => true,
                    'b2b_outbound_enabled' => false,
                    'mfa_trust_enabled' => true,
                    'device_trust_enabled' => false,
                    'direct_connect_inbound_enabled' => false,
                    'direct_connect_outbound_enabled' => false,
                ],
            ],
            [
                'name' => 'Inbound Only',
                'description' => 'Allow inbound collaboration only. No outbound access.',
                'policy_config' => [
                    'b2b_inbound_enabled' => true,
                    'b2b_outbound_enabled' => false,
                    'mfa_trust_enabled' => false,
                    'device_trust_enabled' => false,
                    'direct_connect_inbound_enabled' => false,
                    'direct_connect_outbound_enabled' => false,
                ],
            ],
            [
                'name' => 'Standard',
                'description' => 'Balanced policy with inbound/outbound B2B and MFA trust.',
                'policy_config' => [
                    'b2b_inbound_enabled' => true,
                    'b2b_outbound_enabled' => true,
                    'mfa_trust_enabled' => true,
                    'device_trust_enabled' => false,
                    'direct_connect_inbound_enabled' => false,
                    'direct_connect_outbound_enabled' => false,
                ],
            ],
        ];

        foreach ($presets as $preset) {
            $templates->push(PartnerTemplate::create([
                ...$preset,
                'created_by_user_id' => $admin->id,
            ]));
        }

        return $templates;
    }

    private function seedActivityLogs(
        array $users,
        \Illuminate\Support\Collection $partners,
        \Illuminate\Support\Collection $guests,
        \Illuminate\Support\Collection $templates,
    ): void {
        $allUsers = collect([$users['admin']])->merge($users['operators'])->merge($users['viewers']);

        $entries = [];
        $now = now();

        // Partner-related actions
        foreach ($partners as $partner) {
            $entries[] = [
                'user_id' => $allUsers->random()->id,
                'action' => ActivityAction::PartnerCreated->value,
                'subject_type' => PartnerOrganization::class,
                'subject_id' => $partner->id,
                'details' => json_encode(['tenant_id' => $partner->tenant_id]),
                'created_at' => fake()->dateTimeBetween('-30 days', $now),
            ];

            if (fake()->boolean(40)) {
                $entries[] = [
                    'user_id' => $allUsers->random()->id,
                    'action' => ActivityAction::PartnerUpdated->value,
                    'subject_type' => PartnerOrganization::class,
                    'subject_id' => $partner->id,
                    'details' => json_encode(['mfa_trust_enabled' => true]),
                    'created_at' => fake()->dateTimeBetween('-14 days', $now),
                ];
            }

            if (fake()->boolean(20)) {
                $entries[] = [
                    'user_id' => $allUsers->random()->id,
                    'action' => ActivityAction::PolicyChanged->value,
                    'subject_type' => PartnerOrganization::class,
                    'subject_id' => $partner->id,
                    'details' => json_encode(['b2b_outbound_enabled' => true]),
                    'created_at' => fake()->dateTimeBetween('-7 days', $now),
                ];
            }
        }

        // Guest-related actions
        foreach ($guests->random(min(60, $guests->count())) as $guest) {
            $entries[] = [
                'user_id' => $allUsers->random()->id,
                'action' => ActivityAction::GuestInvited->value,
                'subject_type' => GuestUser::class,
                'subject_id' => $guest->id,
                'details' => json_encode(['email' => $guest->email]),
                'created_at' => fake()->dateTimeBetween('-30 days', $now),
            ];

            if (fake()->boolean(20)) {
                $action = fake()->randomElement([
                    ActivityAction::GuestEnabled,
                    ActivityAction::GuestDisabled,
                    ActivityAction::GuestUpdated,
                ]);
                $entries[] = [
                    'user_id' => $allUsers->random()->id,
                    'action' => $action->value,
                    'subject_type' => GuestUser::class,
                    'subject_id' => $guest->id,
                    'details' => json_encode(['email' => $guest->email]),
                    'created_at' => fake()->dateTimeBetween('-14 days', $now),
                ];
            }
        }

        // Template actions
        foreach ($templates as $template) {
            $entries[] = [
                'user_id' => $users['admin']->id,
                'action' => ActivityAction::TemplateCreated->value,
                'subject_type' => PartnerTemplate::class,
                'subject_id' => $template->id,
                'details' => json_encode(['name' => $template->name]),
                'created_at' => fake()->dateTimeBetween('-30 days', $now),
            ];
        }

        // Sync completed entries
        for ($i = 0; $i < 10; $i++) {
            $entries[] = [
                'user_id' => null,
                'action' => ActivityAction::SyncCompleted->value,
                'subject_type' => null,
                'subject_id' => null,
                'details' => json_encode(['type' => fake()->randomElement(['partners', 'guests']), 'records' => fake()->numberBetween(5, 50)]),
                'created_at' => fake()->dateTimeBetween('-30 days', $now),
            ];
        }

        // User management actions
        foreach ($users['operators']->take(3) as $operator) {
            $entries[] = [
                'user_id' => $users['admin']->id,
                'action' => ActivityAction::UserApproved->value,
                'subject_type' => User::class,
                'subject_id' => $operator->id,
                'details' => json_encode(['email' => $operator->email]),
                'created_at' => fake()->dateTimeBetween('-30 days', $now),
            ];
        }

        // Batch insert
        foreach (array_chunk($entries, 50) as $chunk) {
            DB::table('activity_log')->insert($chunk);
        }
    }

    private function seedSyncLogs(): void
    {
        $entries = [];
        $now = now();

        // 30 days of syncs, twice daily for both partners and guests
        for ($day = 29; $day >= 0; $day--) {
            foreach (['partners', 'guests'] as $type) {
                foreach ([8, 20] as $hour) {
                    $started = $now->copy()->subDays($day)->setTime($hour, 0, 0);
                    $isFailed = fake()->boolean(5);

                    $entries[] = [
                        'id' => (string) \Illuminate\Support\Str::ulid(),
                        'type' => $type,
                        'status' => $isFailed ? 'failed' : 'completed',
                        'records_synced' => $isFailed ? null : fake()->numberBetween(0, 50),
                        'error_message' => $isFailed ? 'Connection timeout: unable to reach Graph API' : null,
                        'started_at' => $started,
                        'completed_at' => $started->copy()->addSeconds(fake()->numberBetween(2, 45)),
                    ];
                }
            }
        }

        foreach (array_chunk($entries, 50) as $chunk) {
            DB::table('sync_logs')->insert($chunk);
        }
    }

    private function buildTrustScoreBreakdown(int $targetScore, string $policyProfile): array
    {
        $signals = [
            'dmarc_present' => ['label' => 'DMARC record present', 'max' => 15],
            'dmarc_enforced' => ['label' => 'DMARC policy enforced (reject/quarantine)', 'max' => 5],
            'spf_present' => ['label' => 'SPF record present', 'max' => 15],
            'dkim_present' => ['label' => 'DKIM record discoverable', 'max' => 5],
            'dnssec_enabled' => ['label' => 'DNSSEC enabled', 'max' => 10],
            'domain_age_2yr' => ['label' => 'Domain age >= 2 years', 'max' => 5],
            'domain_age_5yr' => ['label' => 'Domain age >= 5 years', 'max' => 5],
            'verified_domain' => ['label' => 'Tenant has verified domain', 'max' => 15],
            'multiple_domains' => ['label' => 'Tenant has multiple verified domains', 'max' => 5],
            'mfa_trust' => ['label' => 'MFA trust enabled', 'max' => 10],
            'tenant_age_1yr' => ['label' => 'Partner relationship >= 1 year', 'max' => 5],
            'tenant_age_3yr' => ['label' => 'Partner relationship >= 3 years', 'max' => 5],
        ];

        $remaining = $targetScore;
        $breakdown = [];

        foreach ($signals as $key => $signal) {
            $pass = $remaining > 0 && fake()->boolean($policyProfile === 'permissive' ? 75 : 50);
            $points = $pass ? min($signal['max'], $remaining) : 0;
            $remaining -= $points;

            $breakdown[$key] = [
                'label' => $signal['label'],
                'passed' => $pass,
                'points' => $points,
                'max_points' => $signal['max'],
            ];
        }

        return $breakdown;
    }

    private function seedSettings(): void
    {
        Setting::set('graph', 'tenant_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        Setting::set('graph', 'client_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        Setting::set('graph', 'client_secret', 'placeholder-client-secret', encrypted: true);
        Setting::set('sync', 'partner_interval', '15');
        Setting::set('sync', 'guest_interval', '15');
    }
}
