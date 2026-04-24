<?php

namespace App\Http\Controllers;

use App\Enums\ActivityAction;
use App\Http\Requests\ApplySiteLabelRequest;
use App\Models\PartnerOrganization;
use App\Models\SensitivityLabel;
use App\Models\SharePointSite;
use App\Models\SiteExclusion;
use App\Services\ActivityLogService;
use App\Services\BridgeClient;
use App\Services\Exceptions\BridgeAuthException;
use App\Services\Exceptions\BridgeConfigException;
use App\Services\Exceptions\BridgeException;
use App\Services\Exceptions\BridgeThrottleException;
use App\Services\Exceptions\BridgeUnavailableException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SharePointSiteController extends Controller
{
    public function index(): Response
    {
        $sites = SharePointSite::with('sensitivityLabel')
            ->withCount('permissions')
            ->orderBy('display_name')
            ->paginate(25);

        $partnerIdsWithAccess = PartnerOrganization::whereHas('guestUsers', function ($q) {
            $q->whereHas('sharePointSitePermissions');
        })->pluck('id');

        $uncoveredPartnerCount = PartnerOrganization::whereNotIn('id', $partnerIdsWithAccess)->count();

        return Inertia::render('sharepoint-sites/Index', [
            'sites' => $sites,
            'uncoveredPartnerCount' => $uncoveredPartnerCount,
        ]);
    }

    public function show(SharePointSite $sharePointSite, \Illuminate\Http\Request $request): Response
    {
        $sharePointSite->load([
            'sensitivityLabel',
            'permissions.guestUser.partnerOrganization',
        ]);

        $availableLabels = SensitivityLabel::orderBy('name')->get(['id', 'label_id', 'name']);

        $isExcluded = SiteExclusion::all()->contains(
            fn ($e) => $e->pattern !== '' && stripos($sharePointSite->url, $e->pattern) !== false
        );

        return Inertia::render('sharepoint-sites/Show', [
            'site' => $sharePointSite,
            'availableLabels' => $availableLabels,
            'isExcluded' => $isExcluded,
            'canManage' => $request->user()->role->canManage(),
        ]);
    }

    public function applyLabel(
        ApplySiteLabelRequest $request,
        SharePointSite $sharePointSite,
        BridgeClient $bridge,
        ActivityLogService $log,
    ): RedirectResponse {
        $labelId = $request->validated('label_id');
        $label = SensitivityLabel::where('label_id', $labelId)->firstOrFail();

        try {
            $bridge->setLabel($sharePointSite->url, $labelId, overwrite: true);
        } catch (BridgeException $e) {
            return redirect()->back()->with('error', $this->friendlyMessage($e));
        }

        $sharePointSite->update(['sensitivity_label_id' => $label->id, 'synced_at' => now()]);

        $log->log($request->user(), ActivityAction::LabelApplied, subject: $sharePointSite, details: [
            'site_url' => $sharePointSite->url,
            'label_id' => $labelId,
        ]);

        return redirect()->back()->with('success', "Label applied to {$sharePointSite->display_name}.");
    }

    public function refreshLabel(
        SharePointSite $sharePointSite,
        BridgeClient $bridge,
    ): RedirectResponse {
        try {
            $labelId = $bridge->readLabel($sharePointSite->url);
        } catch (BridgeException $e) {
            return redirect()->back()->with('error', $this->friendlyMessage($e));
        }

        if ($labelId === null) {
            $sharePointSite->update(['sensitivity_label_id' => null, 'synced_at' => now()]);
        } else {
            $label = SensitivityLabel::where('label_id', $labelId)->first();
            $sharePointSite->update([
                'sensitivity_label_id' => $label?->id,
                'synced_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Label refreshed from SharePoint.');
    }

    private function friendlyMessage(BridgeException $e): string
    {
        return match (true) {
            $e instanceof BridgeAuthException => "The bridge can't authenticate to SharePoint. Check the sidecar's certificate and app permissions.",
            $e instanceof BridgeThrottleException => 'SharePoint is rate-limiting requests. Try again in a minute.',
            $e instanceof BridgeUnavailableException => 'The label sidecar is not reachable. Check deployment health.',
            $e instanceof BridgeConfigException => "The sidecar's certificate or configuration is invalid. Contact an administrator.",
            default => 'Label change failed: '.$e->getMessage(),
        };
    }
}
