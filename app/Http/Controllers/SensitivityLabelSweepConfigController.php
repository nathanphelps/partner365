<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\UpdateSensitivitySweepConfigRequest;
use App\Models\LabelRule;
use App\Models\LabelSweepRun;
use App\Models\SensitivityLabel;
use App\Models\Setting;
use App\Models\SiteExclusion;
use App\Services\ActivityLogService;
use App\Services\BridgeClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SensitivityLabelSweepConfigController extends Controller
{
    public function show(BridgeClient $bridge): Response
    {
        $bridgeHealth = null;
        $bridgeError = null;
        try {
            $bridgeHealth = $bridge->health();
        } catch (\Throwable $e) {
            $bridgeError = $e->getMessage();
        }

        return Inertia::render('sensitivity-labels/Sweep/Config', [
            'settings' => [
                'enabled' => (bool) Setting::get('sensitivity_sweep', 'enabled', false),
                'interval_minutes' => (int) Setting::get('sensitivity_sweep', 'interval_minutes', 90),
                'default_label_id' => (string) Setting::get('sensitivity_sweep', 'default_label_id', ''),
                'bridge_url' => (string) Setting::get('sensitivity_sweep', 'bridge_url', 'http://bridge:8080'),
                'bridge_shared_secret' => (string) Setting::get('sensitivity_sweep', 'bridge_shared_secret', ''),
            ],
            'rules' => LabelRule::orderBy('priority')->get(),
            'exclusions' => SiteExclusion::orderBy('pattern')->get(),
            'labels' => SensitivityLabel::orderBy('name')->get(['id', 'label_id', 'name']),
            'lastRun' => LabelSweepRun::latest('started_at')->first(),
            'bridgeHealth' => $bridgeHealth,
            'bridgeError' => $bridgeError,
        ]);
    }

    public function update(UpdateSensitivitySweepConfigRequest $request, ActivityLogService $log): RedirectResponse
    {
        $data = $request->validated();

        Setting::set('sensitivity_sweep', 'enabled', $data['enabled'] ? '1' : '0');
        Setting::set('sensitivity_sweep', 'interval_minutes', (string) $data['interval_minutes']);
        Setting::set('sensitivity_sweep', 'default_label_id', $data['default_label_id']);
        Setting::set('sensitivity_sweep', 'bridge_url', $data['bridge_url']);
        Setting::set('sensitivity_sweep', 'bridge_shared_secret', $data['bridge_shared_secret']);

        DB::transaction(function () use ($data) {
            LabelRule::query()->delete();

            $sorted = collect($data['rules'])->sortBy('priority')->values();
            foreach ($sorted as $i => $rule) {
                LabelRule::create([
                    'prefix' => $rule['prefix'],
                    'label_id' => $rule['label_id'],
                    'priority' => $i + 1,
                ]);
            }

            SiteExclusion::query()->delete();
            foreach ($data['exclusions'] as $ex) {
                SiteExclusion::create(['pattern' => $ex['pattern']]);
            }
        });

        $log->log(
            $request->user(),
            ActivityAction::RuleChanged,
            details: [
                'rule_count' => count($data['rules']),
                'exclusion_count' => count($data['exclusions']),
            ],
        );

        return redirect()->route('sensitivity-labels.sweep.config')
            ->with('success', 'Sweep configuration saved.');
    }
}
