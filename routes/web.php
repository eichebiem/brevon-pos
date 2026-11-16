<?php

use App\Domain\Reports\OperationsReports;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\Inventory\DispatchController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController;
use App\Http\Controllers\Admin\Inventory\StockController;
use App\Http\Controllers\Admin\Inventory\WasteController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductPriceController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShiftReviewController;
use App\Http\Controllers\Admin\StoreSettingsController;
use App\Http\Controllers\Admin\SystemLogController;
use App\Http\Controllers\Admin\TerminalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationSwitchController;
use App\Http\Controllers\Orders\RefundController;
use App\Http\Controllers\Pos\CheckoutController;
use App\Http\Controllers\Pos\OrderController as PosOrderController;
use App\Http\Controllers\Pos\PrinterController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\Staff\CashMovementController;
use App\Http\Controllers\Staff\ShiftController;
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

    Route::get('admin/dashboard', [ReportController::class, 'dashboard'])
        ->middleware('permission:dashboard.view')->name('dashboard');
    // Reports (FR-088–FR-097, D-156–D-159).
    Route::get('admin/reports/sales', [ReportController::class, 'sales'])
        ->middleware('permission:reports.sales')->name('admin.reports.sales');
    Route::get('admin/reports/sales/export/{section}', [ReportController::class, 'exportSales'])
        ->middleware(['permission:reports.sales', 'permission:reports.export', 'throttle:30,1'])->name('admin.reports.sales.export');
    // Operational reports (FR-093, FR-094, D-160–D-163); each report checks its own permission.
    Route::get('admin/reports/{report}', [ReportController::class, 'operations'])
        ->middleware('permission:reports.sales|reports.inventory')
        ->whereIn('report', array_keys(OperationsReports::REPORTS))->name('admin.reports.show');
    Route::get('admin/reports/{report}/export/{table}', [ReportController::class, 'exportOperations'])
        ->middleware(['permission:reports.sales|reports.inventory', 'permission:reports.export', 'throttle:30,1'])
        ->whereIn('report', array_keys(OperationsReports::REPORTS))->name('admin.reports.export');
    Route::get('pos', [PosController::class, 'index'])
        ->middleware('permission:pos.access')->name('pos.index');
    Route::get('pos/printer/test', [PrinterController::class, 'test'])
        ->middleware('permission:pos.access')->name('pos.printer.test');
    Route::post('pos/quote', [CheckoutController::class, 'quote'])
        ->middleware('permission:pos.create-sale')->name('pos.quote');
    Route::post('pos/checkout', [CheckoutController::class, 'store'])
        ->middleware(['permission:pos.create-sale', 'permission:payments.record', 'throttle:60,1'])->name('pos.checkout');
    Route::middleware('permission:orders.view')->group(function () {
        Route::get('pos/orders', [PosOrderController::class, 'index'])->name('pos.orders.index');
        Route::get('pos/orders/{order}', [PosOrderController::class, 'show'])->name('pos.orders.show');
        Route::get('pos/orders/{order}/receipt', [PosOrderController::class, 'receipt'])->name('pos.orders.receipt');
        Route::get('pos/orders/{order}/receipt/escpos', [PosOrderController::class, 'escpos'])->name('pos.orders.receipt.escpos');
        Route::post('pos/orders/{order}/reprint', [PosOrderController::class, 'reprint'])->name('pos.orders.reprint');
        Route::get('pos/orders/{order}/beneficiary', [PosOrderController::class, 'beneficiary'])->middleware('permission:discounts.view-beneficiary')->name('pos.orders.beneficiary');
    });

    // The cashier's own shift (FR-073–FR-079). Location comes from the terminal (D-060).
    Route::get('staff/shift', [ShiftController::class, 'show'])
        ->middleware('permission:shifts.open|shifts.close')->name('staff.shift');
    Route::post('staff/shift', [ShiftController::class, 'open'])
        ->middleware('permission:shifts.open')->name('staff.shift.open');
    Route::post('staff/shifts/{shift}/close', [ShiftController::class, 'close'])
        ->middleware('permission:shifts.close|shifts.review')->name('staff.shift.close');
    // Drawer cash outside sales, and its approval (FR-076, D-127).
    Route::post('shifts/{shift}/cash-movements', [CashMovementController::class, 'store'])
        ->middleware('permission:cash-movements.create')->name('cash-movements.store');
    Route::middleware('permission:shifts.review')->group(function () {});
    // Refunds of completed sales (FR-042–FR-045, D-150–D-155).
    Route::post('orders/{order}/refunds', [RefundController::class, 'store'])
        ->middleware(['permission:refunds.request', 'throttle:30,1'])->name('orders.refunds.store');

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
            Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
            Route::put('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
            Route::get('discounts', [DiscountController::class, 'index'])->name('discounts.index');
            Route::get('discounts/create', [DiscountController::class, 'create'])->name('discounts.create');
            Route::post('discounts', [DiscountController::class, 'store'])->name('discounts.store');
            Route::get('discounts/{discount}/edit', [DiscountController::class, 'edit'])->name('discounts.edit');
            Route::put('discounts/{discount}', [DiscountController::class, 'update'])->name('discounts.update');
        });

        // Catalog (FR-015–FR-021). Viewing needs products.view; each change its own permission.
        Route::middleware('permission:products.view')->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('products', [ProductController::class, 'index'])->name('products.index');
            Route::get('products/{product}/prices', [ProductPriceController::class, 'index'])->name('products.prices');
        });
        Route::middleware('permission:products.create')->group(function () {
            Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('products', [ProductController::class, 'store'])->name('products.store');
        });
        Route::middleware('permission:products.update')->group(function () {
            Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
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

        // Inventory ledger (FR-054, FR-055). Opening stock counts as an adjustment (D-093).
        Route::middleware('permission:inventory.view')->group(function () {
            Route::get('inventory/stock', [StockController::class, 'index'])->name('inventory.stock');
            Route::get('inventory/movements', [StockController::class, 'movements'])->name('inventory.movements');
            Route::get('inventory/dispatches', [DispatchController::class, 'index'])->name('inventory.dispatches.index');
        });
        Route::middleware('permission:inventory.adjust')->group(function () {
            Route::get('inventory/opening-stock', [StockController::class, 'openingForm'])->name('inventory.opening');
            Route::post('inventory/opening-stock', [StockController::class, 'storeOpening'])->name('inventory.opening.store');
            // Waste and dispatch (D-132, D-133).
            Route::get('inventory/waste', [WasteController::class, 'create'])->name('inventory.waste');
            Route::post('inventory/waste', [WasteController::class, 'store'])->name('inventory.waste.store');
            Route::get('inventory/dispatches/create', [DispatchController::class, 'create'])->name('inventory.dispatches.create');
            Route::post('inventory/dispatches', [DispatchController::class, 'store'])->name('inventory.dispatches.store');
            Route::get('inventory/adjustments/create', [StockAdjustmentController::class, 'create'])->name('inventory.adjustments.create');
            Route::post('inventory/adjustments', [StockAdjustmentController::class, 'store'])->name('inventory.adjustments.store');
        });
        Route::middleware('permission:inventory.view')->group(function () {
            Route::get('inventory/dispatches/{dispatch}', [DispatchController::class, 'show'])->name('inventory.dispatches.show');
        });
        // Counts and adjustments: entered with inventory.adjust, decided with inventory.approve-adjustment (D-131).
        Route::middleware('permission:inventory.adjust|inventory.approve-adjustment')->group(function () {
            Route::get('inventory/adjustments', [StockAdjustmentController::class, 'index'])->name('inventory.adjustments.index');
            Route::get('inventory/adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->name('inventory.adjustments.show');
        });
        Route::middleware('permission:inventory.approve-adjustment')->group(function () {
            Route::post('inventory/adjustments/{adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('inventory.adjustments.approve');
            Route::post('inventory/adjustments/{adjustment}/reject', [StockAdjustmentController::class, 'reject'])->name('inventory.adjustments.reject');
        });

        // Shift review and corrections (FR-080–FR-082, D-128–D-130).
        Route::middleware('permission:shifts.review')->group(function () {
            Route::get('shifts', [ShiftReviewController::class, 'index'])->name('shifts.index');
            Route::get('shifts/{shift}', [ShiftReviewController::class, 'show'])->name('shifts.show');
            Route::post('shifts/{shift}/review', [ShiftReviewController::class, 'review'])->name('shifts.review');
            Route::post('shifts/{shift}/corrections', [ShiftReviewController::class, 'correct'])->name('shifts.corrections');
        });

        Route::middleware('permission:audit.view')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
            Route::get('audit-logs/logins', [AuditLogController::class, 'logins'])->name('audit.logins');
        });

        // FR-102, D-184: health and recent logs for technical administrators.
        Route::get('system/logs', [SystemLogController::class, 'index'])
            ->middleware('permission:system.logs')->name('system.logs');
    });
});

require __DIR__.'/settings.php';
