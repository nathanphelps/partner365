<?php

namespace App\Http\Controllers;

use App\Models\LabelRule;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelController extends Controller
{
    public function index(): Response
    {
        $labels = SensitivityLabel::withCount('partners')
            ->with(['children' => fn ($q) => $q->withCount('partners')->orderBy('priority')])
            ->whereNull('parent_label_id')
            ->orderBy('priority')
            ->paginate(25);

        $ruleCounts = LabelRule::query()
            ->select('label_id', DB::raw('count(*) as c'))
            ->groupBy('label_id')
            ->pluck('c', 'label_id');

        $annotate = function ($label) use ($ruleCounts, &$annotate) {
            $label->rule_count = (int) ($ruleCounts[$label->label_id] ?? 0);
            if ($label->children) {
                $label->children->each(fn ($child) => $annotate($child));
            }
        };
        $labels->getCollection()->each(fn ($label) => $annotate($label));

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
