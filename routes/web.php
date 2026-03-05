<?php

use App\Http\Controllers\AccessReviewController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\ComplianceReportController;
use App\Http\Controllers\ConditionalAccessPolicyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\EntitlementController;
use App\Http\Controllers\GuestUserController;
use App\Http\Controllers\PartnerOrganizationController;
use App\Http\Controllers\PartnerTemplateController;
use App\Http\Controllers\SensitivityLabelController;
use App\Http\Controllers\SharePointSiteController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified', 'approved'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('partners/resolve-tenant', [PartnerOrganizationController::class, 'resolveTenant'])
        ->name('partners.resolve-tenant');
    Route::resource('partners', PartnerOrganizationController::class)->except(['edit']);
    Route::get('partners/{partner}/guests', [PartnerOrganizationController::class, 'guests'])->name('partners.guests');

    Route::post('guests/bulk', [GuestUserController::class, 'bulkAction'])->name('guests.bulk');
    Route::resource('guests', GuestUserController::class)->except(['edit']);
    Route::post('guests/{guest}/resend', [GuestUserController::class, 'resendInvitation'])->name('guests.resend');
    Route::get('guests/{guest}/groups', [GuestUserController::class, 'groups'])->name('guests.groups');
    Route::get('guests/{guest}/apps', [GuestUserController::class, 'apps'])->name('guests.apps');
    Route::get('guests/{guest}/teams', [GuestUserController::class, 'teams'])->name('guests.teams');
    Route::get('guests/{guest}/sites', [GuestUserController::class, 'sites'])->name('guests.sites');

    Route::resource('templates', PartnerTemplateController::class)->middleware('role:admin');

    Route::get('reports', [ComplianceReportController::class, 'index'])->name('reports.index');
    Route::get('reports/export', [ComplianceReportController::class, 'export'])->name('reports.export');

    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');

    Route::resource('access-reviews', AccessReviewController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::get('access-reviews/{access_review}/instances/{instance}', [AccessReviewController::class, 'showInstance'])->name('access-reviews.instances.show');
    Route::post('access-reviews/decisions/{decision}', [AccessReviewController::class, 'submitDecision'])->name('access-reviews.decisions.submit');
    Route::post('access-reviews/instances/{instance}/apply', [AccessReviewController::class, 'applyRemediations'])->name('access-reviews.instances.apply');

    Route::get('conditional-access', [ConditionalAccessPolicyController::class, 'index'])->name('conditional-access.index');
    Route::get('conditional-access/{conditionalAccessPolicy}', [ConditionalAccessPolicyController::class, 'show'])->name('conditional-access.show');

    Route::get('sensitivity-labels', [SensitivityLabelController::class, 'index'])->name('sensitivity-labels.index');
    Route::get('sensitivity-labels/{sensitivityLabel}', [SensitivityLabelController::class, 'show'])->name('sensitivity-labels.show');

    Route::get('sharepoint-sites', [SharePointSiteController::class, 'index'])->name('sharepoint-sites.index');
    Route::get('sharepoint-sites/{sharePointSite}', [SharePointSiteController::class, 'show'])->name('sharepoint-sites.show');

    Route::resource('entitlements', EntitlementController::class)->except(['edit']);
    Route::post('entitlements/{entitlement}/assignments', [EntitlementController::class, 'createAssignment'])->name('entitlements.assignments.create');
    Route::post('entitlements/{entitlement}/assignments/{assignment}/approve', [EntitlementController::class, 'approveAssignment'])->name('entitlements.assignments.approve');
    Route::post('entitlements/{entitlement}/assignments/{assignment}/deny', [EntitlementController::class, 'denyAssignment'])->name('entitlements.assignments.deny');
    Route::post('entitlements/{entitlement}/assignments/{assignment}/revoke', [EntitlementController::class, 'revokeAssignment'])->name('entitlements.assignments.revoke');
    Route::get('entitlements-groups', [EntitlementController::class, 'groups'])->name('entitlements.groups');
    Route::get('entitlements-sharepoint-sites', [EntitlementController::class, 'sharepointSites'])->name('entitlements.sharepoint-sites');

    Route::get('docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('docs/{path}', [DocsController::class, 'show'])->name('docs.show')->where('path', '.*');
});

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = 'ok';
    } catch (\Throwable) {
        $database = 'error';
    }

    return response()->json([
        'status' => 'ok',
        'database' => $database,
    ]);
})->name('health');

require __DIR__.'/settings.php';
