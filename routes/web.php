<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationSwitchController;
use Illuminate\Support\Facades\Route;

// Staff application only - there is no public website here (SRS §1.3).
Route::redirect('/', '/login')->name('home');

/*
| Every protected route is permission-checked on the server (FR-007,
| NFR-003). Hiding a menu item in Vue is never the control.
*/
Route::middleware(['auth'])->group(function () {
    Route::get('home', HomeController::class)->name('landing');
    Route::post('location', LocationSwitchController::class)->name('location.switch');

    Route::inertia('admin/dashboard', 'admin/Dashboard')
        ->middleware('permission:dashboard.view')->name('dashboard');
    Route::inertia('pos', 'pos/Index')
        ->middleware('permission:pos.access')->name('pos.index');
    Route::inertia('staff/preparation', 'staff/Preparation')
        ->middleware('permission:orders.update-status')->name('staff.preparation');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::middleware('permission:users.manage')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::patch('users/{user}/status', [UserController::class, 'setStatus'])->name('users.status');
        });

        Route::middleware('permission:roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
            Route::get('audit-logs/logins', [AuditLogController::class, 'logins'])->name('audit.logins');
        });
    });
});

require __DIR__.'/settings.php';
