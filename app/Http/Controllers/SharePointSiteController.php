<?php

namespace App\Http\Controllers;

use App\Models\PartnerOrganization;
use App\Models\SharePointSite;
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

    public function show(SharePointSite $sharePointSite): Response
    {
        $sharePointSite->load([
            'sensitivityLabel',
            'permissions.guestUser.partnerOrganization',
        ]);

        return Inertia::render('sharepoint-sites/Show', [
            'site' => $sharePointSite,
        ]);
    }
}
