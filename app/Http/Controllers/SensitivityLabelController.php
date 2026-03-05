<?php

namespace App\Http\Controllers;

use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelController extends Controller
{
    public function index(): Response
    {
        $labels = SensitivityLabel::withCount('partners')
            ->whereNull('parent_label_id')
            ->orderBy('priority')
            ->paginate(25);

        $uncoveredPartnerCount = PartnerOrganization::whereDoesntHave('sensitivityLabels')->count();

        return Inertia::render('sensitivity-labels/Index', [
            'labels' => $labels,
            'uncoveredPartnerCount' => $uncoveredPartnerCount,
        ]);
    }

    public function show(SensitivityLabel $sensitivityLabel): Response
    {
        $sensitivityLabel->load(['partners', 'children']);

        return Inertia::render('sensitivity-labels/Show', [
            'label' => $sensitivityLabel,
        ]);
    }
}
