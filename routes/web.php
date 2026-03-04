<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GuestUserController;
use App\Http\Controllers\PartnerOrganizationController;
use App\Http\Controllers\PartnerTemplateController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('partners/resolve-tenant', [PartnerOrganizationController::class, 'resolveTenant'])
        ->name('partners.resolve-tenant');
    Route::resource('partners', PartnerOrganizationController::class)->except(['edit']);

    Route::resource('guests', GuestUserController::class)->except(['edit', 'update']);

    Route::resource('templates', PartnerTemplateController::class)->middleware('role:admin');

    Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
});

require __DIR__.'/settings.php';
