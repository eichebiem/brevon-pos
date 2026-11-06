<?php

namespace App\Domain\Purchasing;

use App\Domain\Shared\AuditDiff;
use App\Domain\Shared\AuditLogger;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Supplier records (FR-058; D-135). Codes are given automatically (SUP001,
 * SUP002, …) and never change; suppliers are deactivated, never deleted.
 */
class SupplierService
{
    private const CODE_PREFIX = 'SUP';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, contact_person?: string|null, phone?: string|null, email?: string|null, address?: string|null, payment_terms?: string|null}  $data
     */
    public function create(User $actor, array $data): Supplier
    {
        return DB::transaction(function () use ($actor, $data) {
            $supplier = new Supplier;
            $supplier->fill([...$data, 'is_active' => true]);
            $supplier->forceFill(['supplier_code' => $this->nextCode()])->save();
            $this->audit->record('supplier.created', $supplier, null, $supplier->only(['supplier_code', 'name']), null, $actor);

            return $supplier;
        });
    }

    /**
     * @param  array{name: string, contact_person?: string|null, phone?: string|null, email?: string|null, address?: string|null, payment_terms?: string|null, is_active: bool}  $data
     */
    public function update(User $actor, Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($actor, $supplier, $data) {
            $before = $supplier->only(array_keys($data));
            $supplier->fill($data)->save();
            [$old, $new] = AuditDiff::between($before, $supplier->only(array_keys($data)));
            if ($new !== []) {
                $this->audit->record('supplier.updated', $supplier, $old, $new, null, $actor);
            }

            return $supplier;
        });
    }

    private function nextCode(): string
    {
        $highest = Supplier::withTrashed()->where('supplier_code', 'like', self::CODE_PREFIX.'%')
            ->lockForUpdate()->pluck('supplier_code')
            ->map(fn (string $code) => preg_match('/^'.self::CODE_PREFIX.'(\d+)$/', $code, $m) === 1 ? (int) $m[1] : 0)
            ->max() ?? 0;

        return self::CODE_PREFIX.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }
}
