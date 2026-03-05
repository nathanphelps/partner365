<?php

namespace App\Services;

use App\Enums\AssignmentStatus;
use App\Models\AccessPackage;
use App\Models\AccessPackageAssignment;
use App\Models\AccessPackageCatalog;
use App\Models\AccessPackageResource;
use App\Models\PartnerOrganization;
use App\Models\User;

class EntitlementService
{
    public function __construct(
        private MicrosoftGraphService $graph,
    ) {}

    public function getOrCreateDefaultCatalog(): AccessPackageCatalog
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/catalogs', [
            '$filter' => "displayName eq 'General'",
        ]);

        $catalogData = $response['value'][0] ?? null;

        if (! $catalogData) {
            $catalogData = $this->graph->post('/identityGovernance/entitlementManagement/catalogs', [
                'displayName' => 'General',
                'description' => 'Default catalog for Partner365 access packages',
                'isExternallyVisible' => true,
            ]);
        }

        return AccessPackageCatalog::updateOrCreate(
            ['graph_id' => $catalogData['id']],
            [
                'display_name' => $catalogData['displayName'],
                'is_default' => true,
                'last_synced_at' => now(),
            ]
        );
    }

    public function createAccessPackage(AccessPackageCatalog $catalog, PartnerOrganization $partner, array $data): AccessPackage
    {
        $this->ensureConnectedOrganization($partner);

        $graphResponse = $this->graph->post('/identityGovernance/entitlementManagement/accessPackages', [
            'displayName' => $data['display_name'],
            'description' => $data['description'] ?? '',
            'catalog' => ['id' => $catalog->graph_id],
            'isHidden' => false,
        ]);

        $package = AccessPackage::create([
            ...$data,
            'graph_id' => $graphResponse['id'] ?? null,
            'catalog_id' => $catalog->id,
            'partner_organization_id' => $partner->id,
        ]);

        if ($data['approval_required'] ?? true) {
            $this->createAssignmentPolicy($package, $data['approver_user_id'] ?? null);
        }

        return $package;
    }

    public function updateAccessPackage(AccessPackage $package, array $data): AccessPackage
    {
        if ($package->graph_id) {
            $this->graph->patch("/identityGovernance/entitlementManagement/accessPackages/{$package->graph_id}", [
                'displayName' => $data['display_name'] ?? $package->display_name,
                'description' => $data['description'] ?? $package->description ?? '',
            ]);
        }

        $package->update($data);

        return $package->fresh();
    }

    public function deleteAccessPackage(AccessPackage $package): void
    {
        if ($package->graph_id) {
            $this->graph->delete("/identityGovernance/entitlementManagement/accessPackages/{$package->graph_id}");
        }

        $package->delete();
    }

    public function addResource(AccessPackage $package, array $data): AccessPackageResource
    {
        $graphId = null;

        if ($package->graph_id) {
            $originSystem = $data['resource_type'] === 'sharepoint_site' ? 'SharePointOnline' : 'AadGroup';

            $graphResponse = $this->graph->post(
                "/identityGovernance/entitlementManagement/accessPackages/{$package->graph_id}/accessPackageResourceRoleScopes",
                [
                    'accessPackageResourceRole' => [
                        'originId' => 'Member',
                        'originSystem' => $originSystem,
                        'accessPackageResource' => [
                            'originId' => $data['resource_id'],
                            'originSystem' => $originSystem,
                        ],
                    ],
                    'accessPackageResourceScope' => [
                        'originId' => $data['resource_id'],
                        'originSystem' => $originSystem,
                    ],
                ]
            );

            $graphId = $graphResponse['id'] ?? null;
        }

        return AccessPackageResource::create([
            'access_package_id' => $package->id,
            'resource_type' => $data['resource_type'],
            'resource_id' => $data['resource_id'],
            'resource_display_name' => $data['resource_display_name'],
            'graph_id' => $graphId,
        ]);
    }

    public function removeResource(AccessPackageResource $resource): void
    {
        $resource->delete();
    }

    public function requestAssignment(AccessPackage $package, string $email, ?string $justification = null): AccessPackageAssignment
    {
        if ($package->graph_id) {
            $this->graph->post('/identityGovernance/entitlementManagement/assignmentRequests', [
                'requestType' => 'AdminAdd',
                'accessPackageAssignment' => [
                    'targetId' => $email,
                    'assignmentPolicyId' => $package->graph_id,
                    'accessPackageId' => $package->graph_id,
                ],
                'justification' => $justification ?? '',
            ]);
        }

        return AccessPackageAssignment::create([
            'access_package_id' => $package->id,
            'target_user_email' => $email,
            'status' => AssignmentStatus::PendingApproval,
            'requested_at' => now(),
            'expires_at' => now()->addDays($package->duration_days),
            'justification' => $justification,
        ]);
    }

    public function approveAssignment(AccessPackageAssignment $assignment, User $approver): void
    {
        $assignment->update([
            'status' => AssignmentStatus::Approved,
            'approved_by_user_id' => $approver->id,
            'approved_at' => now(),
        ]);
    }

    public function denyAssignment(AccessPackageAssignment $assignment, User $denier): void
    {
        $assignment->update([
            'status' => AssignmentStatus::Denied,
        ]);
    }

    public function revokeAssignment(AccessPackageAssignment $assignment): void
    {
        if ($assignment->graph_id) {
            $this->graph->post('/identityGovernance/entitlementManagement/assignmentRequests', [
                'requestType' => 'AdminRemove',
                'accessPackageAssignment' => [
                    'id' => $assignment->graph_id,
                ],
            ]);
        }

        $assignment->update([
            'status' => AssignmentStatus::Revoked,
        ]);
    }

    public function listGroups(): array
    {
        $response = $this->graph->get('/groups', [
            '$select' => 'id,displayName,description',
            '$top' => 100,
            '$orderby' => 'displayName',
        ]);

        return $response['value'] ?? [];
    }

    public function listSharePointSites(): array
    {
        $response = $this->graph->get('/sites', [
            '$select' => 'id,displayName,webUrl',
            '$top' => 100,
            'search' => '*',
        ]);

        return $response['value'] ?? [];
    }

    public function syncAccessPackages(): int
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/accessPackages', [
            '$expand' => 'catalog',
        ]);

        $synced = 0;
        foreach ($response['value'] ?? [] as $graphPackage) {
            $package = AccessPackage::where('graph_id', $graphPackage['id'])->first();
            if ($package) {
                $package->update([
                    'display_name' => $graphPackage['displayName'],
                    'is_active' => ! ($graphPackage['isHidden'] ?? false),
                    'last_synced_at' => now(),
                ]);
                $synced++;
            }
        }

        return $synced;
    }

    public function syncAssignments(): int
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/assignments', [
            '$expand' => 'accessPackage',
        ]);

        $synced = 0;
        foreach ($response['value'] ?? [] as $graphAssignment) {
            $assignment = AccessPackageAssignment::where('graph_id', $graphAssignment['id'])->first();
            if ($assignment) {
                $graphStatus = strtolower($graphAssignment['state'] ?? '');
                $status = match ($graphStatus) {
                    'delivered' => AssignmentStatus::Delivered,
                    'expired' => AssignmentStatus::Expired,
                    'delivering' => AssignmentStatus::Delivering,
                    default => $assignment->status,
                };

                $assignment->update([
                    'status' => $status,
                    'last_synced_at' => now(),
                ]);
                $synced++;
            }
        }

        return $synced;
    }

    private function ensureConnectedOrganization(PartnerOrganization $partner): void
    {
        $response = $this->graph->get('/identityGovernance/entitlementManagement/connectedOrganizations', [
            '$filter' => "identitySources/any(is:is/tenantId eq '{$partner->tenant_id}')",
        ]);

        if (! empty($response['value'])) {
            return;
        }

        $this->graph->post('/identityGovernance/entitlementManagement/connectedOrganizations', [
            'displayName' => $partner->display_name,
            'identitySources' => [
                [
                    '@odata.type' => '#microsoft.graph.azureActiveDirectoryTenant',
                    'tenantId' => $partner->tenant_id,
                    'displayName' => $partner->display_name,
                ],
            ],
            'state' => 'configured',
        ]);
    }

    private function createAssignmentPolicy(AccessPackage $package, ?int $approverUserId): void
    {
        $policyData = [
            'displayName' => "Policy for {$package->display_name}",
            'accessPackageId' => $package->graph_id,
            'expiration' => [
                'type' => 'afterDuration',
                'duration' => "P{$package->duration_days}D",
            ],
            'requestorSettings' => [
                'scopeType' => 'AllExternalSubjects',
                'acceptRequests' => true,
            ],
        ];

        if ($approverUserId && $package->approval_required) {
            $approver = User::find($approverUserId);
            if ($approver) {
                $policyData['requestApprovalSettings'] = [
                    'isApprovalRequired' => true,
                    'isApprovalRequiredForExtension' => false,
                    'approvalStages' => [
                        [
                            'approvalStageTimeOutInDays' => 14,
                            'isApproverJustificationRequired' => false,
                            'isEscalationEnabled' => false,
                            'primaryApprovers' => [
                                [
                                    '@odata.type' => '#microsoft.graph.singleUser',
                                    'isBackup' => false,
                                    'description' => $approver->name,
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        $this->graph->post('/identityGovernance/entitlementManagement/assignmentPolicies', $policyData);
    }
}
