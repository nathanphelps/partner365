<?php

use App\Http\Controllers\Admin\AdminCollaborationController;
use App\Http\Controllers\Admin\AdminGraphController;
use App\Http\Controllers\Admin\AdminSyncController;
use App\Http\Controllers\Admin\AdminSyslogController;
use App\Http\Controllers\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'approved', 'role:admin'])->prefix('admin')->group(function () {
    Route::redirect('/', '/admin/graph');

    Route::get('graph', [AdminGraphController::class, 'edit'])->name('admin.graph.edit');
    Route::put('graph', [AdminGraphController::class, 'update'])->name('admin.graph.update');
    Route::post('graph/test', [AdminGraphController::class, 'testConnection'])->name('admin.graph.test');
    Route::get('graph/consent', [AdminGraphController::class, 'consentUrl'])->name('admin.graph.consent');

    Route::get('collaboration', [AdminCollaborationController::class, 'edit'])->name('admin.collaboration.edit');
    Route::put('collaboration', [AdminCollaborationController::class, 'update'])->name('admin.collaboration.update');

    Route::get('users', [AdminUserController::class, 'index'])->name('admin.users.index');
    Route::post('users/{user}/approve', [AdminUserController::class, 'approve'])->name('admin.users.approve');
    Route::patch('users/{user}/role', [AdminUserController::class, 'updateRole'])->name('admin.users.role');
    Route::delete('users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');

    Route::get('sync', [AdminSyncController::class, 'edit'])->name('admin.sync.edit');
    Route::put('sync', [AdminSyncController::class, 'update'])->name('admin.sync.update');
    Route::post('sync/{type}/run', [AdminSyncController::class, 'run'])->name('admin.sync.run');

    Route::get('syslog', [AdminSyslogController::class, 'edit'])->name('admin.syslog.edit');
    Route::put('syslog', [AdminSyslogController::class, 'update'])->name('admin.syslog.update');
    Route::post('syslog/test', [AdminSyslogController::class, 'test'])->name('admin.syslog.test');
});

Route::get('admin/graph/consent/callback', [AdminGraphController::class, 'consentCallback'])
    ->name('admin.graph.consent.callback');
