<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\OptionGroupController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPriceController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\StoreSettingsController;
use App\Http\Controllers\Admin\TerminalController;
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

        // Operating locations and terminals (FR-011, FR-103–FR-106, D-061).
        Route::middleware('permission:locations.manage')->group(function () {
            Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
            Route::get('locations/create', [LocationController::class, 'create'])->name('locations.create');
            Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
            Route::get('locations/{location}/edit', [LocationController::class, 'edit'])->name('locations.edit');
            Route::put('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
            Route::post('locations/{location}/deactivate', [LocationController::class, 'deactivate'])->name('locations.deactivate');
            Route::post('locations/{location}/reactivate', [LocationController::class, 'reactivate'])->name('locations.reactivate');
            Route::post('locations/{location}/copy-availability', [LocationController::class, 'copyAvailability'])->name('locations.copy-availability');
            Route::post('locations/{location}/terminals', [TerminalController::class, 'store'])->name('terminals.store');
            Route::put('terminals/{terminal}', [TerminalController::class, 'update'])->name('terminals.update');
            Route::post('terminals/{terminal}/reassign', [TerminalController::class, 'reassign'])->name('terminals.reassign');
        });

        Route::middleware('permission:settings.manage')->group(function () {
            Route::get('settings', [StoreSettingsController::class, 'edit'])->name('settings.edit');
            Route::put('settings', [StoreSettingsController::class, 'update'])->name('settings.update');
        });

        // Catalog (FR-015–FR-021). Viewing needs products.view; each change its own permission.
        Route::middleware('permission:products.view')->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('option-groups', [OptionGroupController::class, 'index'])->name('option-groups.index');
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::get('products/{product}/prices', [ProductPriceController::class, 'index'])->name('products.prices');
        });
        Route::middleware('permission:products.create')->group(function () {
            Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::get('option-groups/create', [OptionGroupController::class, 'create'])->name('option-groups.create');
            Route::post('option-groups', [OptionGroupController::class, 'store'])->name('option-groups.store');
            Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
        });
        Route::middleware('permission:products.update')->group(function () {
            Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::get('option-groups/{optionGroup}/edit', [OptionGroupController::class, 'edit'])->name('option-groups.edit');
            Route::put('option-groups/{optionGroup}', [OptionGroupController::class, 'update'])->name('option-groups.update');
            Route::get('products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::patch('products/{product}/pos-availability', [ProductController::class, 'setPosAvailability'])->name('products.pos-availability');
        });
        Route::middleware('permission:products.archive')->group(function () {
            Route::patch('categories/{category}/status', [CategoryController::class, 'setStatus'])->name('categories.status');
            Route::patch('products/{product}/status', [ProductController::class, 'setStatus'])->name('products.status');
        });
        Route::middleware('permission:products.manage-prices')->group(function () {
            Route::post('products/{product}/prices', [ProductPriceController::class, 'store'])->name('products.prices.store');
            Route::post('product-prices/{price}/end', [ProductPriceController::class, 'end'])->name('product-prices.end');
        });

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
            Route::get('audit-logs/logins', [AuditLogController::class, 'logins'])->name('audit.logins');
        });
    });
});

require __DIR__.'/settings.php';
