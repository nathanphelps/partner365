<?php

namespace App\Services;

use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewInstanceStatus;
use App\Enums\ReviewType;
use App\Models\AccessReview;
use App\Models\AccessReviewDecision;
use App\Models\AccessReviewInstance;
use App\Models\GuestUser;
use App\Models\PartnerOrganization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AccessReviewService
{
    public function __construct(
        private MicrosoftGraphService $graph,
        private GuestUserService $guestService,
    ) {}

    public function createDefinition(array $config): AccessReview
    {
        $graphResponse = $this->graph->post('/identityGovernance/accessReviews/definitions', [
            'displayName' => $config['title'],
            'scope' => [
                'query' => "/users?\$filter=userType eq 'Guest'",
                'queryType' => 'MicrosoftGraph',
            ],
        ]);

        if (empty($graphResponse['id'])) {
            throw new \RuntimeException('Graph API did not return an id for the access review definition');
        }

        return AccessReview::create([
            ...$config,
            'graph_definition_id' => $graphResponse['id'],
        ]);
    }

    public function deleteDefinition(AccessReview $review): void
    {
        if ($review->graph_definition_id) {
            $this->graph->delete("/identityGovernance/accessReviews/definitions/{$review->graph_definition_id}");
        }

        $review->delete();
    }

    public function submitDecision(AccessReviewDecision $decision, ReviewDecision $verdict, ?string $justification, User $user): void
    {
        $decision->update([
            'decision' => $verdict,
            'justification' => $justification,
            'decided_by_user_id' => $user->id,
            'decided_at' => now(),
        ]);
    }

    /**
     * @return array{succeeded: int, failed: array<int, array{id: int, error: string}>}
     */
    public function applyRemediations(AccessReviewInstance $instance): array
    {
        $review = $instance->accessReview;
        $denyDecisions = $instance->decisions()
            ->where('decision', ReviewDecision::Deny)
            ->where('remediation_applied', false)
            ->get();

        $succeeded = 0;
        $failed = [];

        foreach ($denyDecisions as $decision) {
            try {
                if ($review->review_type === ReviewType::GuestUsers && $review->remediation_action !== RemediationAction::FlagOnly) {
                    $guest = GuestUser::find($decision->subject_id);
                    if ($guest) {
                        match ($review->remediation_action) {
                            RemediationAction::Disable => $this->disableGuest($guest),
                            RemediationAction::Remove => $this->removeGuest($guest),
                            default => null,
                        };
                    }
                }

                $decision->update([
                    'remediation_applied' => true,
                    'remediation_applied_at' => now(),
                ]);
                $succeeded++;
            } catch (\Throwable $e) {
                Log::error("Remediation failed for decision {$decision->id}: {$e->getMessage()}");
                $failed[] = ['id' => $decision->id, 'error' => $e->getMessage()];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    public function createInstanceWithDecisions(AccessReview $review): AccessReviewInstance
    {
        $instance = AccessReviewInstance::create([
            'access_review_id' => $review->id,
            'status' => ReviewInstanceStatus::Pending,
            'started_at' => now(),
            'due_at' => now()->addDays($review->recurrence_interval_days ?? 30),
        ]);

        $subjects = $this->getSubjectsForReview($review);

        foreach ($subjects as $subject) {
            AccessReviewDecision::create([
                'access_review_instance_id' => $instance->id,
                'subject_type' => $review->review_type === ReviewType::GuestUsers ? 'guest_user' : 'partner_organization',
                'subject_id' => $subject->id,
                'decision' => ReviewDecision::Pending,
            ]);
        }

        $instance->load('decisions');

        return $instance;
    }

    private function getSubjectsForReview(AccessReview $review): \Illuminate\Database\Eloquent\Collection
    {
        if ($review->review_type === ReviewType::PartnerOrganizations) {
            return PartnerOrganization::all();
        }

        $query = GuestUser::query();
        if ($review->scope_partner_id) {
            $query->where('partner_organization_id', $review->scope_partner_id);
        }

        return $query->get();
    }

    private function disableGuest(GuestUser $guest): void
    {
        $this->guestService->disableUser($guest->entra_user_id);
        $guest->update(['account_enabled' => false]);
    }

    private function removeGuest(GuestUser $guest): void
    {
        $this->guestService->deleteUser($guest->entra_user_id);
        $guest->delete();
    }
}
