<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\DiscountController;
use App\Http\Controllers\Admin\Inventory\DispatchController;
use App\Http\Controllers\Admin\Inventory\InventoryItemController;
use App\Http\Controllers\Admin\Inventory\StockAdjustmentController;
use App\Http\Controllers\Admin\Inventory\StockController;
use App\Http\Controllers\Admin\Inventory\StockValueController;
use App\Http\Controllers\Admin\Inventory\WasteController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\OptionGroupController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\Production\ProductionBatchController;
use App\Http\Controllers\Admin\Production\RecipeController;
use App\Http\Controllers\Admin\ProductPriceController;
use App\Http\Controllers\Admin\Purchasing\GoodsReceiptController;
use App\Http\Controllers\Admin\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Admin\Purchasing\SupplierController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\ShiftReviewController;
use App\Http\Controllers\Admin\StoreSettingsController;
use App\Http\Controllers\Admin\TaxRuleController;
use App\Http\Controllers\Admin\TerminalController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LocationSwitchController;
use App\Http\Controllers\Orders\OrderStatusController;
use App\Http\Controllers\Orders\RefundController;
use App\Http\Controllers\Pos\CheckoutController;
use App\Http\Controllers\Pos\OrderController as PosOrderController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\Staff\CashMovementController;
use App\Http\Controllers\Staff\PaymentCheckController;
use App\Http\Controllers\Staff\PreparationController;
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

    Route::inertia('admin/dashboard', 'admin/Dashboard')
        ->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('pos', [PosController::class, 'index'])
        ->middleware('permission:pos.access')->name('pos.index');
    Route::post('pos/quote', [CheckoutController::class, 'quote'])
        ->middleware('permission:pos.create-sale')->name('pos.quote');
    Route::post('pos/checkout', [CheckoutController::class, 'store'])
        ->middleware(['permission:pos.create-sale', 'permission:payments.record', 'throttle:60,1'])->name('pos.checkout');
    Route::middleware('permission:orders.view')->group(function () {
        Route::get('pos/orders', [PosOrderController::class, 'index'])->name('pos.orders.index');
        Route::get('pos/orders/{order}', [PosOrderController::class, 'show'])->name('pos.orders.show');
        Route::get('pos/orders/{order}/receipt', [PosOrderController::class, 'receipt'])->name('pos.orders.receipt');
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
    Route::middleware('permission:shifts.review')->group(function () {
        Route::post('cash-movements/{movement}/approve', [CashMovementController::class, 'approve'])->name('cash-movements.approve');
        Route::post('cash-movements/{movement}/reject', [CashMovementController::class, 'reject'])->name('cash-movements.reject');
    });
    // Preparation and order status (FR-046–FR-052, D-123–D-125).
    Route::get('staff/preparation', [PreparationController::class, 'index'])
        ->middleware('permission:orders.update-status')->name('staff.preparation');
    Route::post('orders/{order}/advance', [OrderStatusController::class, 'advance'])
        ->middleware('permission:orders.update-status')->name('orders.advance');
    Route::post('orders/{order}/cancel', [OrderStatusController::class, 'cancel'])
        ->middleware('permission:orders.cancel')->name('orders.cancel');
    // Refunds of completed sales (FR-042–FR-045, D-150–D-155).
    Route::post('orders/{order}/refunds', [RefundController::class, 'store'])
        ->middleware(['permission:refunds.request', 'throttle:30,1'])->name('orders.refunds.store');

    // GCash checks (BR-019, D-124).
    Route::middleware('permission:payments.verify-ewallet')->group(function () {
        Route::get('staff/payments', [PaymentCheckController::class, 'index'])->name('staff.payments');
        Route::post('payments/{payment}/verify', [PaymentCheckController::class, 'verify'])->name('payments.verify');
        Route::post('payments/{payment}/reject', [PaymentCheckController::class, 'reject'])->name('payments.reject');
    });

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
            Route::get('tax-rules', [TaxRuleController::class, 'index'])->name('tax-rules.index');
            Route::get('tax-rules/create', [TaxRuleController::class, 'create'])->name('tax-rules.create');
            Route::post('tax-rules', [TaxRuleController::class, 'store'])->name('tax-rules.store');
            Route::get('tax-rules/{taxRule}/edit', [TaxRuleController::class, 'edit'])->name('tax-rules.edit');
            Route::put('tax-rules/{taxRule}', [TaxRuleController::class, 'update'])->name('tax-rules.update');
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

        // Inventory ledger (FR-054, FR-055). Opening stock counts as an adjustment (D-093).
        Route::middleware('permission:inventory.view')->group(function () {
            Route::get('inventory/stock', [StockController::class, 'index'])->name('inventory.stock');
            Route::get('inventory/movements', [StockController::class, 'movements'])->name('inventory.movements');
            Route::get('inventory/items', [InventoryItemController::class, 'index'])->name('inventory.items.index');
            Route::get('inventory/dispatches', [DispatchController::class, 'index'])->name('inventory.dispatches.index');
        });
        Route::middleware('permission:inventory.adjust')->group(function () {
            Route::get('inventory/opening-stock', [StockController::class, 'openingForm'])->name('inventory.opening');
            Route::post('inventory/opening-stock', [StockController::class, 'storeOpening'])->name('inventory.opening.store');
            // Stock items, waste and dispatch (D-132–D-134).
            Route::get('inventory/items/create', [InventoryItemController::class, 'create'])->name('inventory.items.create');
            Route::post('inventory/items', [InventoryItemController::class, 'store'])->name('inventory.items.store');
            Route::get('inventory/items/{item}/edit', [InventoryItemController::class, 'edit'])->name('inventory.items.edit');
            Route::put('inventory/items/{item}', [InventoryItemController::class, 'update'])->name('inventory.items.update');
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

        // Lots and stock value (FR-060, FR-063; D-138, D-139).
        Route::get('inventory/lots', [StockValueController::class, 'lots'])->middleware('permission:inventory.view')->name('inventory.lots');
        Route::get('inventory/valuation', [StockValueController::class, 'valuation'])->middleware('permission:reports.inventory')->name('inventory.valuation');

        // Purchasing (FR-058, FR-059, UC-006; D-135–D-137).
        Route::middleware('permission:suppliers.manage')->group(function () {
            Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
            Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
            Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
            Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        });
        Route::middleware('permission:purchase-orders.create')->group(function () {
            Route::get('purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchase-orders.create');
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchase-orders.store');
            Route::get('purchase-orders/{order}/edit', [PurchaseOrderController::class, 'edit'])->name('purchase-orders.edit');
            Route::put('purchase-orders/{order}', [PurchaseOrderController::class, 'update'])->name('purchase-orders.update');
        });
        Route::middleware('permission:purchase-orders.create|purchase-orders.approve|purchase-orders.receive')->group(function () {
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchase-orders.index');
            Route::get('purchase-orders/{order}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
        });
        // Cancelling a sent order also needs purchase-orders.approve (checked by PurchaseOrders::cancel).
        Route::post('purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase-orders.create|purchase-orders.approve')->name('purchase-orders.cancel');
        Route::post('purchase-orders/{order}/submit', [PurchaseOrderController::class, 'submit'])->middleware('permission:purchase-orders.approve')->name('purchase-orders.submit');
        // Against an order: purchase-orders.receive; without one: inventory.receive (checked in the controller).
        Route::middleware('permission:purchase-orders.receive|inventory.receive')->group(function () {
            Route::get('receipts', [GoodsReceiptController::class, 'index'])->name('receipts.index');
            Route::get('receipts/create', [GoodsReceiptController::class, 'create'])->name('receipts.create');
            Route::post('receipts', [GoodsReceiptController::class, 'store'])->name('receipts.store');
            Route::get('receipts/{receipt}', [GoodsReceiptController::class, 'show'])->name('receipts.show');
        });

        // Recipes and production (FR-064–FR-072; D-140–D-143).
        Route::middleware('permission:recipes.manage')->group(function () {
            Route::get('recipes', [RecipeController::class, 'index'])->name('recipes.index');
            Route::get('recipes/{product}', [RecipeController::class, 'show'])->name('recipes.show');
            Route::get('recipes/{product}/new-version', [RecipeController::class, 'create'])->name('recipes.create');
            Route::post('recipes/{product}', [RecipeController::class, 'store'])->name('recipes.store');
        });
        Route::middleware('permission:production.create')->group(function () {
            Route::get('production/create', [ProductionBatchController::class, 'create'])->name('production.create');
            Route::post('production', [ProductionBatchController::class, 'store'])->name('production.store');
            Route::get('production/{batch}/edit', [ProductionBatchController::class, 'edit'])->name('production.edit');
            Route::put('production/{batch}', [ProductionBatchController::class, 'update'])->name('production.update');
            Route::post('production/{batch}/cancel', [ProductionBatchController::class, 'cancel'])->name('production.cancel');
        });
        Route::middleware('permission:production.create|production.post')->group(function () {
            Route::get('production', [ProductionBatchController::class, 'index'])->name('production.index');
            Route::get('production/{batch}', [ProductionBatchController::class, 'show'])->name('production.show');
        });
        Route::post('production/{batch}/post', [ProductionBatchController::class, 'post'])->middleware('permission:production.post')->name('production.post');

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
    });
});

require __DIR__.'/settings.php';
