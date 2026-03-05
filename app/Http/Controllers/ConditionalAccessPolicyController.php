<?php

namespace App\Http\Controllers;

use App\Models\ConditionalAccessPolicy;
use App\Models\PartnerOrganization;
use Inertia\Inertia;
use Inertia\Response;

class ConditionalAccessPolicyController extends Controller
{
    public function index(): Response
    {
        $policies = ConditionalAccessPolicy::withCount('partners')
            ->orderBy('display_name')
            ->paginate(25);

        $uncoveredPartnerCount = PartnerOrganization::whereDoesntHave('conditionalAccessPolicies')->count();

        return Inertia::render('conditional-access/Index', [
            'policies' => $policies,
            'uncoveredPartnerCount' => $uncoveredPartnerCount,
        ]);
    }

    public function show(ConditionalAccessPolicy $conditionalAccessPolicy): Response
    {
        $conditionalAccessPolicy->load('partners');

        return Inertia::render('conditional-access/Show', [
            'policy' => $conditionalAccessPolicy,
        ]);
    }
}
