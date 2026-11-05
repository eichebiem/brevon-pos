<?php

namespace App\Domain\Access;

/**
 * Source of truth for permission names (SRS Appendix A + D-061) and the
 * default role matrix (D-076). Roles remain editable afterwards (FR-006).
 */
final class PermissionCatalog
{
    public const OWNER = 'Owner';

    public const ADMIN = 'Admin';

    public const MANAGER = 'Manager';

    public const CASHIER = 'Cashier';

    public const PREPARATION_STAFF = 'Preparation Staff';

    /**
     * Permissions grouped for display.
     *
     * @return array<string, list<string>>
     */
    public static function groups(): array
    {
        return [
            'Dashboard' => ['dashboard.view'],
            'Point of sale' => ['pos.access', 'pos.create-sale', 'pos.apply-discount', 'pos.override-price'],
            'Orders' => ['orders.view', 'orders.update-status', 'orders.cancel'],
            'Payments' => ['payments.record', 'payments.verify-ewallet'],
            'Refunds and voids' => ['refunds.request', 'refunds.approve', 'voids.approve'],
            'Products' => ['products.view', 'products.create', 'products.update', 'products.archive', 'products.manage-prices'],
            'Inventory' => ['inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.approve-adjustment'],
            'Recipes and production' => ['recipes.manage', 'production.create', 'production.post'],
            'Purchasing' => ['suppliers.manage', 'purchase-orders.create', 'purchase-orders.approve', 'purchase-orders.receive'],
            'Shifts and cash' => ['shifts.open', 'shifts.close', 'shifts.review', 'cash-movements.create'],
            'Reports' => ['reports.sales', 'reports.inventory', 'reports.financial', 'reports.export'],
            'Administration' => ['users.manage', 'roles.manage', 'settings.manage', 'locations.manage', 'audit.view'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /**
     * Default permissions per seeded role (D-076).
     *
     * @return array<string, list<string>>
     */
    public static function defaultRoles(): array
    {
        $all = self::all();

        return [
            self::OWNER => $all,
            self::ADMIN => array_values(array_diff($all, ['roles.manage'])),
            self::MANAGER => [
                'dashboard.view',
                'pos.access', 'pos.create-sale', 'pos.apply-discount',
                'orders.view', 'orders.update-status', 'orders.cancel',
                'payments.record', 'payments.verify-ewallet',
                'refunds.request', 'refunds.approve', 'voids.approve',
                'products.view',
                'inventory.view', 'inventory.receive', 'inventory.adjust', 'inventory.approve-adjustment',
                'production.create', 'production.post',
                'suppliers.manage', 'purchase-orders.create', 'purchase-orders.approve', 'purchase-orders.receive',
                'shifts.open', 'shifts.close', 'shifts.review', 'cash-movements.create',
                'reports.sales', 'reports.inventory', 'reports.export',
            ],
            self::CASHIER => [
                'pos.access', 'pos.create-sale',
                'orders.view',
                'payments.record',
                'refunds.request',
                'products.view',
                'shifts.open', 'shifts.close',
            ],
            self::PREPARATION_STAFF => [
                'orders.view', 'orders.update-status',
                'products.view',
                'inventory.view',
                'production.create',
            ],
        ];
    }
}
