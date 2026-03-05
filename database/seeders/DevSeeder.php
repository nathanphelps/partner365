<?php

namespace Database\Seeders;

use App\Enums\AccessPackageResourceType;
use App\Enums\ActivityAction;
use App\Enums\AssignmentStatus;
use App\Enums\InvitationStatus;
use App\Enums\PartnerCategory;
use App\Enums\RecurrenceType;
use App\Enums\RemediationAction;
use App\Enums\ReviewInstanceStatus;
use App\Enums\ReviewType;
use App\Enums\UserRole;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\AccessReview;
use App\Models\AccessReviewInstance;
use App\Models\ConditionalAccessPolicy;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\PartnerTemplate;
use App\Models\SensitivityLabel;
use App\Models\SensitivityLabelPolicy;
use App\Models\Setting;
use App\Models\SiteSensitivityLabel;
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
        $this->seedConditionalAccessPolicies($partners);
        $this->seedSensitivityLabels($partners);
        $this->seedEntitlements($partners, $users);
        $this->seedAccessReviews($partners, $users);
        $this->seedActivityLogs($users, $partners, $guests, $templates);
        $this->seedSyncLogs();
        $this->seedFavicons($partners);
        $this->seedSettings();
    }

    private function truncateAll(): void
    {
        $tables = [
            'activity_log',
            'access_review_decisions',
            'access_review_instances',
            'access_reviews',
            'sensitivity_label_partner',
            'site_sensitivity_labels',
            'sensitivity_label_policies',
            'sensitivity_labels',
            'conditional_access_policy_partner',
            'conditional_access_policies',
            'access_package_assignments',
            'access_package_resources',
            'access_packages',
            'access_package_catalogs',
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

        // Intentionally stale guests for compliance reporting
        $stalePartners = $partners->shuffle()->take(5);
        foreach ($stalePartners as $partner) {
            // 90+ day stale guest
            $guests->push(GuestUser::factory()->create([
                'email' => 'stale-90d-'.fake()->userName().'@'.$partner->domain,
                'partner_organization_id' => $partner->id,
                'invitation_status' => InvitationStatus::Accepted,
                'invited_by_user_id' => $inviters->random()->id,
                'invited_at' => fake()->dateTimeBetween('-180 days', '-120 days'),
                'account_enabled' => true,
                'last_sign_in_at' => fake()->dateTimeBetween('-180 days', '-91 days'),
                'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
            ]));

            // Never signed in guest
            $guests->push(GuestUser::factory()->create([
                'email' => 'never-signed-in-'.fake()->userName().'@'.$partner->domain,
                'partner_organization_id' => $partner->id,
                'invitation_status' => InvitationStatus::Accepted,
                'invited_by_user_id' => $inviters->random()->id,
                'invited_at' => fake()->dateTimeBetween('-120 days', '-60 days'),
                'account_enabled' => true,
                'last_sign_in_at' => null,
                'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
            ]));
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

    private function seedConditionalAccessPolicies(\Illuminate\Support\Collection $partners): void
    {
        $policies = [
            [
                'policy_id' => fake()->uuid(),
                'display_name' => 'Require MFA for all guest users',
                'state' => 'enabled',
                'guest_or_external_user_types' => 'b2bCollaborationGuest',
                'external_tenant_scope' => 'all',
                'target_applications' => 'all',
                'grant_controls' => ['mfa'],
                'session_controls' => [],
            ],
            [
                'policy_id' => fake()->uuid(),
                'display_name' => 'Require compliant device for B2B guests',
                'state' => 'enabled',
                'guest_or_external_user_types' => 'b2bCollaborationGuest',
                'external_tenant_scope' => 'all',
                'target_applications' => 'all',
                'grant_controls' => ['compliantDevice'],
                'session_controls' => [],
            ],
            [
                'policy_id' => fake()->uuid(),
                'display_name' => 'Block legacy auth for external users',
                'state' => 'enabled',
                'guest_or_external_user_types' => 'b2bCollaborationGuest,b2bDirectConnectUser',
                'external_tenant_scope' => 'all',
                'target_applications' => 'all',
                'grant_controls' => ['block'],
                'session_controls' => [],
            ],
            [
                'policy_id' => fake()->uuid(),
                'display_name' => 'Sign-in frequency for direct connect users',
                'state' => 'enabledForReportingButNotEnforced',
                'guest_or_external_user_types' => 'b2bDirectConnectUser',
                'external_tenant_scope' => 'all',
                'target_applications' => 'all',
                'grant_controls' => [],
                'session_controls' => ['signInFrequency'],
            ],
            [
                'policy_id' => fake()->uuid(),
                'display_name' => 'MFA for specific partner guests (disabled)',
                'state' => 'disabled',
                'guest_or_external_user_types' => 'b2bCollaborationGuest',
                'external_tenant_scope' => 'specific',
                'external_tenant_ids' => $partners->take(3)->pluck('tenant_id')->toArray(),
                'target_applications' => 'all',
                'grant_controls' => ['mfa'],
                'session_controls' => [],
            ],
        ];

        foreach ($policies as $policyData) {
            $policy = ConditionalAccessPolicy::create(array_merge($policyData, [
                'synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
            ]));

            // Build partner mappings
            $userTypes = explode(',', $policyData['guest_or_external_user_types']);
            $mappedPartners = $policyData['external_tenant_scope'] === 'all'
                ? $partners
                : $partners->whereIn('tenant_id', $policyData['external_tenant_ids'] ?? []);

            foreach ($mappedPartners as $partner) {
                foreach ($userTypes as $userType) {
                    $policy->partners()->attach($partner->id, [
                        'matched_user_type' => trim($userType),
                    ]);
                }
            }
        }
    }

    private function seedSensitivityLabels(\Illuminate\Support\Collection $partners): void
    {
        $labels = [
            [
                'label_id' => fake()->uuid(),
                'name' => 'Public',
                'description' => 'Data intended for public consumption.',
                'tooltip' => 'Apply to content that can be shared externally.',
                'color' => '#00B050',
                'priority' => 0,
                'protection_type' => 'none',
                'scope' => ['files_emails', 'sites_groups'],
                'is_active' => true,
            ],
            [
                'label_id' => fake()->uuid(),
                'name' => 'General',
                'description' => 'Business data not intended for public consumption.',
                'tooltip' => 'Default label for internal business documents.',
                'color' => '#0078D4',
                'priority' => 1,
                'protection_type' => 'none',
                'scope' => ['files_emails', 'sites_groups'],
                'is_active' => true,
            ],
            [
                'label_id' => fake()->uuid(),
                'name' => 'Confidential',
                'description' => 'Sensitive business data that could cause damage if shared.',
                'tooltip' => 'Apply to sensitive internal documents.',
                'color' => '#FFB900',
                'priority' => 2,
                'protection_type' => 'encryption',
                'scope' => ['files_emails', 'sites_groups'],
                'is_active' => true,
            ],
            [
                'label_id' => fake()->uuid(),
                'name' => 'Highly Confidential',
                'description' => 'Very sensitive data with strict access controls.',
                'tooltip' => 'Apply to the most sensitive business data.',
                'color' => '#D83B01',
                'priority' => 3,
                'protection_type' => 'encryption',
                'scope' => ['files_emails', 'sites_groups'],
                'is_active' => true,
            ],
        ];

        $createdLabels = collect();
        foreach ($labels as $labelData) {
            $label = SensitivityLabel::create(array_merge($labelData, [
                'synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
            ]));
            $createdLabels->push($label);
        }

        // Add sub-labels to Confidential
        $confidential = $createdLabels->firstWhere('name', 'Confidential');
        $subLabels = [
            ['name' => 'Confidential - Internal Only', 'protection_type' => 'encryption', 'priority' => 20],
            ['name' => 'Confidential - Recipients Only', 'protection_type' => 'encryption', 'priority' => 21],
            ['name' => 'Confidential - Watermarked', 'protection_type' => 'watermark', 'priority' => 22],
        ];

        foreach ($subLabels as $sub) {
            SensitivityLabel::create([
                'label_id' => fake()->uuid(),
                'name' => $sub['name'],
                'description' => 'Sub-label of Confidential.',
                'color' => '#FFB900',
                'priority' => $sub['priority'],
                'protection_type' => $sub['protection_type'],
                'scope' => ['files_emails'],
                'is_active' => true,
                'parent_id' => $confidential->id,
                'synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
            ]);
        }

        // Create label policies
        $allUsersPolicy = SensitivityLabelPolicy::create([
            'policy_id' => fake()->uuid(),
            'name' => 'Default Label Policy',
            'target_type' => 'all_users_and_guests',
            'target_groups' => null,
            'labels' => $createdLabels->pluck('label_id')->toArray(),
            'synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
        ]);

        SensitivityLabelPolicy::create([
            'policy_id' => fake()->uuid(),
            'name' => 'Executive Policy',
            'target_type' => 'specific_groups',
            'target_groups' => [fake()->uuid()],
            'labels' => $createdLabels->where('priority', '>=', 2)->pluck('label_id')->toArray(),
            'synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
        ]);

        // Create site sensitivity labels
        $siteNames = ['Project Portal', 'Partner Hub', 'Engineering Wiki', 'HR Documents'];
        foreach ($siteNames as $i => $siteName) {
            SiteSensitivityLabel::create([
                'site_id' => fake()->uuid(),
                'site_name' => $siteName,
                'site_url' => 'https://contoso.sharepoint.com/sites/'.str_replace(' ', '-', strtolower($siteName)),
                'sensitivity_label_id' => $createdLabels->random()->id,
                'external_sharing_enabled' => $i < 2, // first 2 sites have external sharing
                'synced_at' => fake()->dateTimeBetween('-1 day', 'now'),
            ]);
        }

        // Build partner mappings — all_users_and_guests policy maps to all partners
        foreach ($createdLabels as $label) {
            // Map ~60% of partners to each label via policy
            $mappedPartners = $partners->shuffle()->take((int) ($partners->count() * 0.6));
            foreach ($mappedPartners as $partner) {
                $label->partners()->attach($partner->id, [
                    'matched_via' => 'label_policy',
                    'policy_name' => $allUsersPolicy->name,
                    'site_name' => null,
                ]);
            }
        }

        // Map a few partners via site assignment
        $siteLabel = $createdLabels->first();
        foreach ($partners->shuffle()->take(5) as $partner) {
            if (! $siteLabel->partners()->where('partner_organization_id', $partner->id)->exists()) {
                $siteLabel->partners()->attach($partner->id, [
                    'matched_via' => 'site_assignment',
                    'policy_name' => null,
                    'site_name' => 'Project Portal',
                ]);
            }
        }
    }

    private function seedEntitlements(\Illuminate\Support\Collection $partners, array $users): void
    {
        $catalog = AccessPackageCatalog::create([
            'graph_id' => fake()->uuid(),
            'display_name' => 'General',
            'description' => 'Default catalog for Partner365 access packages',
            'is_default' => true,
            'last_synced_at' => now(),
        ]);

        $approvers = collect([$users['admin']])->merge($users['operators']);

        $packageNames = [
            'Development Resources',
            'Project Documentation',
            'CI/CD Pipeline Access',
            'Staging Environment',
            'Support Portal',
            'API Sandbox',
            'Design Assets',
            'Analytics Dashboard',
        ];

        $groupNames = [
            'Dev Team', 'QA Team', 'Project Leads', 'External Contributors',
            'Support Staff', 'API Users', 'Design Reviewers', 'Analytics Viewers',
        ];

        $siteNames = [
            'Project Wiki', 'Shared Documents', 'Release Notes',
            'Partner Portal', 'Knowledge Base', 'Design System',
        ];

        // Create 6-8 access packages across different partners
        $selectedPartners = $partners->shuffle()->take(fake()->numberBetween(6, 8));

        foreach ($selectedPartners as $i => $partner) {
            $packageName = $packageNames[$i % count($packageNames)];
            $approver = $approvers->random();

            $package = AccessPackage::create([
                'graph_id' => fake()->uuid(),
                'catalog_id' => $catalog->id,
                'partner_organization_id' => $partner->id,
                'display_name' => $packageName,
                'description' => fake()->sentence(),
                'duration_days' => fake()->randomElement([30, 60, 90, 180]),
                'approval_required' => fake()->boolean(80),
                'approver_user_id' => $approver->id,
                'is_active' => fake()->boolean(85),
                'created_by_user_id' => $users['admin']->id,
                'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
            ]);

            // Add 1-3 resources per package
            $resourceCount = fake()->numberBetween(1, 3);
            for ($r = 0; $r < $resourceCount; $r++) {
                $isGroup = fake()->boolean(65);
                AccessPackageResource::create([
                    'access_package_id' => $package->id,
                    'resource_type' => $isGroup ? AccessPackageResourceType::Group : AccessPackageResourceType::SharePointSite,
                    'resource_id' => fake()->uuid(),
                    'resource_display_name' => $isGroup
                        ? $groupNames[array_rand($groupNames)]
                        : $siteNames[array_rand($siteNames)],
                    'graph_id' => fake()->uuid(),
                ]);
            }

            // Add 2-6 assignments per package
            $assignmentCount = fake()->numberBetween(2, 6);
            for ($a = 0; $a < $assignmentCount; $a++) {
                $status = fake()->randomElement([
                    AssignmentStatus::Delivered,
                    AssignmentStatus::Delivered,
                    AssignmentStatus::Delivered,
                    AssignmentStatus::PendingApproval,
                    AssignmentStatus::Approved,
                    AssignmentStatus::Expired,
                    AssignmentStatus::Denied,
                    AssignmentStatus::Revoked,
                ]);

                $requestedAt = fake()->dateTimeBetween('-60 days', '-1 day');
                $isApproved = in_array($status, [AssignmentStatus::Approved, AssignmentStatus::Delivered, AssignmentStatus::Expired]);

                AccessPackageAssignment::create([
                    'graph_id' => fake()->uuid(),
                    'access_package_id' => $package->id,
                    'target_user_email' => fake()->userName().'@'.$partner->domain,
                    'target_user_id' => $isApproved ? fake()->uuid() : null,
                    'status' => $status,
                    'approved_by_user_id' => $isApproved ? $approvers->random()->id : null,
                    'expires_at' => $isApproved ? now()->addDays($package->duration_days) : null,
                    'requested_at' => $requestedAt,
                    'approved_at' => $isApproved ? fake()->dateTimeBetween($requestedAt, 'now') : null,
                    'delivered_at' => $status === AssignmentStatus::Delivered ? fake()->dateTimeBetween($requestedAt, 'now') : null,
                    'justification' => fake()->boolean(70) ? fake()->sentence() : null,
                    'last_synced_at' => fake()->dateTimeBetween('-7 days', 'now'),
                ]);
            }
        }
    }

    private function seedAccessReviews(
        \Illuminate\Support\Collection $partners,
        array $users,
    ): void {
        $reviewConfigs = [
            ['title' => 'Quarterly Guest Access Review', 'type' => ReviewType::GuestUsers, 'recurrence' => RecurrenceType::Recurring, 'interval' => 90, 'remediation' => RemediationAction::Disable],
            ['title' => 'Annual Partner Relationship Review', 'type' => ReviewType::PartnerOrganizations, 'recurrence' => RecurrenceType::Recurring, 'interval' => 365, 'remediation' => RemediationAction::FlagOnly],
            ['title' => 'Contractor Access Cleanup', 'type' => ReviewType::GuestUsers, 'recurrence' => RecurrenceType::OneTime, 'interval' => null, 'remediation' => RemediationAction::Remove],
        ];

        foreach ($reviewConfigs as $config) {
            $review = AccessReview::create([
                'title' => $config['title'],
                'description' => fake()->sentence(),
                'review_type' => $config['type'],
                'scope_partner_id' => $config['type'] === ReviewType::PartnerOrganizations ? null : $partners->random()->id,
                'recurrence_type' => $config['recurrence'],
                'recurrence_interval_days' => $config['interval'],
                'remediation_action' => $config['remediation'],
                'reviewer_user_id' => $users['admin']->id,
                'created_by_user_id' => $users['admin']->id,
                'graph_definition_id' => fake()->uuid(),
                'next_review_at' => $config['recurrence'] === RecurrenceType::Recurring
                    ? now()->addDays(fake()->numberBetween(1, $config['interval']))
                    : null,
            ]);

            // Completed instance
            AccessReviewInstance::create([
                'access_review_id' => $review->id,
                'status' => ReviewInstanceStatus::Completed,
                'started_at' => now()->subDays(60),
                'due_at' => now()->subDays(30),
                'completed_at' => now()->subDays(32),
            ]);

            // Overdue in-progress instance (shows on dashboard)
            if (fake()->boolean(60)) {
                AccessReviewInstance::create([
                    'access_review_id' => $review->id,
                    'status' => ReviewInstanceStatus::InProgress,
                    'started_at' => now()->subDays(20),
                    'due_at' => now()->subDays(fake()->numberBetween(1, 10)),
                    'completed_at' => null,
                ]);
            }

            // Pending future instance
            if (fake()->boolean(40)) {
                AccessReviewInstance::create([
                    'access_review_id' => $review->id,
                    'status' => ReviewInstanceStatus::Pending,
                    'started_at' => now()->addDays(5),
                    'due_at' => now()->addDays(35),
                    'completed_at' => null,
                ]);
            }
        }
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

        // Template update/delete actions
        if ($templates->count() >= 2) {
            $entries[] = [
                'user_id' => $users['admin']->id,
                'action' => ActivityAction::TemplateUpdated->value,
                'subject_type' => PartnerTemplate::class,
                'subject_id' => $templates->first()->id,
                'details' => json_encode(['name' => $templates->first()->name]),
                'created_at' => fake()->dateTimeBetween('-14 days', $now),
            ];
        }

        // Auth events — logins, logouts, failures
        foreach ($allUsers->random(min(10, $allUsers->count())) as $user) {
            $entries[] = [
                'user_id' => $user->id,
                'action' => ActivityAction::UserLoggedIn->value,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'details' => json_encode(['ip' => fake()->ipv4()]),
                'created_at' => fake()->dateTimeBetween('-14 days', $now),
            ];

            if (fake()->boolean(30)) {
                $entries[] = [
                    'user_id' => $user->id,
                    'action' => ActivityAction::UserLoggedOut->value,
                    'subject_type' => User::class,
                    'subject_id' => $user->id,
                    'details' => json_encode([]),
                    'created_at' => fake()->dateTimeBetween('-14 days', $now),
                ];
            }
        }

        // Failed login attempts
        for ($i = 0; $i < 5; $i++) {
            $entries[] = [
                'user_id' => null,
                'action' => ActivityAction::LoginFailed->value,
                'subject_type' => null,
                'subject_id' => null,
                'details' => json_encode(['email' => fake()->email(), 'ip' => fake()->ipv4()]),
                'created_at' => fake()->dateTimeBetween('-14 days', $now),
            ];
        }

        // Password change and 2FA events
        foreach ($allUsers->random(min(3, $allUsers->count())) as $user) {
            $entries[] = [
                'user_id' => $user->id,
                'action' => ActivityAction::PasswordChanged->value,
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'details' => json_encode([]),
                'created_at' => fake()->dateTimeBetween('-30 days', $now),
            ];
        }

        $entries[] = [
            'user_id' => $users['admin']->id,
            'action' => ActivityAction::TwoFactorEnabled->value,
            'subject_type' => User::class,
            'subject_id' => $users['admin']->id,
            'details' => json_encode([]),
            'created_at' => fake()->dateTimeBetween('-30 days', $now),
        ];

        // Profile update
        $entries[] = [
            'user_id' => $users['admin']->id,
            'action' => ActivityAction::ProfileUpdated->value,
            'subject_type' => User::class,
            'subject_id' => $users['admin']->id,
            'details' => json_encode(['fields' => ['name']]),
            'created_at' => fake()->dateTimeBetween('-14 days', $now),
        ];

        // Graph connection test
        $entries[] = [
            'user_id' => $users['admin']->id,
            'action' => ActivityAction::GraphConnectionTested->value,
            'subject_type' => null,
            'subject_id' => null,
            'details' => json_encode(['success' => true]),
            'created_at' => fake()->dateTimeBetween('-7 days', $now),
        ];

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

        // CA policy sync
        $entries[] = [
            'user_id' => null,
            'action' => ActivityAction::ConditionalAccessPoliciesSynced->value,
            'subject_type' => null,
            'subject_id' => null,
            'details' => json_encode(['policies_synced' => 5]),
            'created_at' => fake()->dateTimeBetween('-7 days', $now),
        ];

        // Sensitivity label sync
        $entries[] = [
            'user_id' => null,
            'action' => ActivityAction::SensitivityLabelsSynced->value,
            'subject_type' => null,
            'subject_id' => null,
            'details' => json_encode(['labels_synced' => 4, 'policies_synced' => 2, 'sites_synced' => 4]),
            'created_at' => fake()->dateTimeBetween('-7 days', $now),
        ];

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

    private function seedFavicons(\Illuminate\Support\Collection $partners): void
    {
        // Simulate cached favicons for a subset of partners.
        // Creates placeholder files so the UI shows the avatar fallback (initials).
        // Run `php artisan sync:favicons` against a real network to fetch actual icons.
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        $disk->makeDirectory('favicons');

        foreach ($partners->whereNotNull('domain')->take(10) as $partner) {
            $filename = "favicons/{$partner->id}.ico";
            $disk->put($filename, '');
            $partner->update(['favicon_path' => $filename]);
        }
    }

    private function seedSettings(): void
    {
        Setting::set('graph', 'tenant_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        Setting::set('graph', 'client_id', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
        Setting::set('graph', 'client_secret', 'placeholder-client-secret', encrypted: true);
        Setting::set('sync', 'partner_interval', '15');
        Setting::set('sync', 'guest_interval', '15');

        // Syslog/SIEM settings (disabled by default in dev)
        Setting::set('syslog', 'enabled', 'false');
        Setting::set('syslog', 'host', '');
        Setting::set('syslog', 'port', '514');
        Setting::set('syslog', 'transport', 'udp');
        Setting::set('syslog', 'facility', '16');
    }
}
