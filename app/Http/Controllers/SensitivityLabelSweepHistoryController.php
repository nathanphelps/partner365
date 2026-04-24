<?php

namespace App\Http\Controllers;

use App\Models\LabelSweepRun;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelSweepHistoryController extends Controller
{
    public function index(): Response
    {
        $runs = LabelSweepRun::orderByDesc('started_at')->paginate(50);

        return Inertia::render('sensitivity-labels/Sweep/History', [
            'runs' => $runs,
        ]);
    }

    public function show(LabelSweepRun $run): Response
    {
        $entries = $run->entries()
            ->with('matchedRule')
            ->orderBy('processed_at')
            ->get();

        return Inertia::render('sensitivity-labels/Sweep/HistoryDetail', [
            'run' => $run,
            'entries' => $entries,
        ]);
    }
}
