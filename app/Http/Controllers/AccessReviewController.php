<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Enums\RemediationAction;
use App\Enums\ReviewDecision;
use App\Enums\ReviewType;
use App\Http\Requests\StoreAccessReviewRequest;
use App\Models\AccessReview;
use App\Models\AccessReviewDecision;
use App\Models\AccessReviewInstance;
use App\Models\PartnerOrganization;
use App\Models\User;
use App\Services\AccessReviewService;
use App\Services\ActivityLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccessReviewController extends Controller
{
    public function __construct(
        private AccessReviewService $reviewService,
        private ActivityLogService $activityLog,
    ) {}

    public function index(Request $request): Response
    {
        $reviews = AccessReview::with(['reviewer', 'latestInstance', 'scopePartner'])
            ->withCount('instances')
            ->orderByDesc('created_at')
            ->paginate(25);

        return Inertia::render('access-reviews/Index', [
            'reviews' => $reviews,
            'canManage' => $request->user()->role->canManage(),
            'isAdmin' => $request->user()->role->isAdmin(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        return Inertia::render('access-reviews/Create', [
            'partners' => PartnerOrganization::orderBy('display_name')->get(['id', 'display_name']),
            'reviewers' => User::whereIn('role', ['admin', 'operator'])->orderBy('name')->get(['id', 'name', 'role']),
        ]);
    }

    public function store(StoreAccessReviewRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // Force flag_only for partner reviews
        if ($validated['review_type'] === ReviewType::PartnerOrganizations->value) {
            $validated['remediation_action'] = RemediationAction::FlagOnly->value;
        }

        $validated['created_by_user_id'] = $request->user()->id;

        $review = $this->reviewService->createDefinition($validated);

        $this->activityLog->log($request->user(), ActivityAction::AccessReviewCreated, $review, [
            'title' => $review->title,
        ]);

        return redirect()->route('access-reviews.index')->with('success', "Access review '{$review->title}' created.");
    }

    public function show(AccessReview $accessReview): Response
    {
        $accessReview->load(['reviewer', 'createdBy', 'scopePartner', 'instances' => function ($q) {
            $q->orderByDesc('started_at');
        }]);

        return Inertia::render('access-reviews/Show', [
            'review' => $accessReview,
        ]);
    }

    public function destroy(Request $request, AccessReview $accessReview): RedirectResponse
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        $this->reviewService->deleteDefinition($accessReview);

        return redirect()->route('access-reviews.index')->with('success', 'Access review deleted.');
    }

    public function showInstance(AccessReview $accessReview, AccessReviewInstance $instance): Response
    {
        $instance->load(['decisions.decidedBy']);
        $instance->loadCount([
            'decisions',
            'decisions as approved_count' => fn ($q) => $q->where('decision', ReviewDecision::Approve),
            'decisions as denied_count' => fn ($q) => $q->where('decision', ReviewDecision::Deny),
            'decisions as pending_count' => fn ($q) => $q->where('decision', ReviewDecision::Pending),
        ]);

        return Inertia::render('access-reviews/Instance', [
            'review' => $accessReview,
            'instance' => $instance,
            'canManage' => request()->user()->role->canManage(),
            'isAdmin' => request()->user()->role->isAdmin(),
        ]);
    }

    public function submitDecision(Request $request, AccessReviewDecision $decision): RedirectResponse
    {
        if (! $request->user()->role->canManage()) {
            abort(403);
        }

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(ReviewDecision::class)],
            'justification' => ['nullable', 'string', 'max:5000'],
        ]);

        $this->reviewService->submitDecision(
            $decision,
            ReviewDecision::from($validated['decision']),
            $validated['justification'] ?? null,
            $request->user(),
        );

        $this->activityLog->log($request->user(), ActivityAction::AccessReviewDecisionMade, $decision, [
            'decision' => $validated['decision'],
        ]);

        return redirect()->back()->with('success', 'Decision recorded.');
    }

    public function applyRemediations(Request $request, AccessReviewInstance $instance): RedirectResponse
    {
        if (! $request->user()->role->isAdmin()) {
            abort(403);
        }

        $this->reviewService->applyRemediations($instance);

        $this->activityLog->log($request->user(), ActivityAction::AccessReviewRemediationApplied, $instance, [
            'review_title' => $instance->accessReview->title,
        ]);

        return redirect()->back()->with('success', 'Remediations applied.');
    }
}
