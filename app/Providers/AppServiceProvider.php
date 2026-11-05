<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\OptionGroup;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Terminal;
use App\Models\User;
use App\Support\Database\DestructiveCommandGuard;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Event::listen(CommandStarting::class, [DestructiveCommandGuard::class, 'handle']);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // D-030: only known short aliases may appear in polymorphic columns
        // (audit_logs.auditable_type, spatie model_type, ...).
        Relation::enforceMorphMap([
            'user' => User::class,
            'branch' => Branch::class,
            'employee' => Employee::class,
            'terminal' => Terminal::class,
            'role' => Role::class,
            'permission' => Permission::class,
            'audit_log' => AuditLog::class,
            'setting' => Setting::class,
            'category' => Category::class,
            'inventory_item' => InventoryItem::class,
            'product' => Product::class,
            'product_variant' => ProductVariant::class,
            'product_price' => ProductPrice::class,
            'option_group' => OptionGroup::class,
            'payment_method' => PaymentMethod::class,
            'inventory_movement' => InventoryMovement::class,
            'cashier_shift' => CashierShift::class,
            'order' => Order::class,
            'payment' => Payment::class,
        ]);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
