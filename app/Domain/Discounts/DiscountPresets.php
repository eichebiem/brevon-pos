<?php

namespace App\Domain\Discounts;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\BusinessClock;
use App\Enums\DiscountKind;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Preset discounts managed by `settings.manage` (FR-083; D-145). Orders keep
 * snapshots, so editing a preset never changes past sales. The SC/PWD
 * discount's kind, scope and type are fixed (D-148): only its rate, approval
 * and on/off change.
 */
class DiscountPresets
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Discounts the counter can offer right now.
     *
     * @return Builder<Discount>
     */
    public function offered(): Builder
    {
        $now = BusinessClock::now();

        return Discount::query()->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderByRaw("kind = 'senior_pwd' desc")->orderBy('name');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?Discount $discount, array $data): Discount
    {
        $values = $this->values($discount, $data);

        return DB::transaction(function () use ($actor, $discount, $values) {
            $before = $discount?->only(array_keys($values));
            $discount ??= new Discount;
            $discount->forceFill($values)->save();

            $this->audit->record($before === null ? 'discount.created' : 'discount.updated', $discount, $before, $discount->only(array_keys($values)), null, $actor);

            return $discount;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(?Discount $discount, array $data): array
    {
        $seniorPwd = $discount?->kind === DiscountKind::SeniorPwd;
        $errors = [];

        $type = $seniorPwd ? DiscountType::Percentage : DiscountType::tryFrom((string) ($data['discount_type'] ?? ''));
        $scope = $seniorPwd ? DiscountScope::Order : DiscountScope::tryFrom((string) ($data['scope'] ?? ''));
        if ($type === null) {
            $errors['discount_type'] = __('Choose percentage or fixed amount.');
        }
        if ($scope === null) {
            $errors['scope'] = __('Choose what the discount applies to.');
        }

        $value = trim((string) ($data['value'] ?? ''));
        $amount = '0.00';
        if (preg_match('/^\d{1,7}(\.\d{1,2})?$/', $value) !== 1 || ! is_numeric($value) || bccomp($value, '0', 2) <= 0) {
            $errors['value'] = __('Enter an amount more than 0 (up to 2 decimal places).');
        } elseif ($type === DiscountType::Percentage && bccomp($value, '100', 2) > 0) {
            $errors['value'] = __('A percentage cannot be more than 100.');
        } else {
            $amount = bcadd($value, '0', 2);
        }

        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $code = $code === '' ? null : $code;
        if ($code !== null && preg_match('/^[A-Z0-9-]{2,30}$/', $code) !== 1) {
            $errors['code'] = __('Use 2–30 letters, digits or dashes.');
        } elseif ($code !== null && Discount::query()->where('code', $code)->when($discount, fn ($q) => $q->whereKeyNot($discount->id))->exists()) {
            $errors['code'] = __('Another discount already uses this code.');
        }

        $productId = null;
        $categoryId = null;
        if ($scope === DiscountScope::Product) {
            $productId = (int) ($data['product_id'] ?? 0);
            if (! Product::query()->whereKey($productId)->exists()) {
                $errors['product_id'] = __('Choose the product.');
            }
        } elseif ($scope === DiscountScope::Category) {
            $categoryId = (int) ($data['category_id'] ?? 0);
            if (! Category::query()->whereKey($categoryId)->exists()) {
                $errors['category_id'] = __('Choose the category.');
            }
        }

        $starts = self::moment($data['starts_at'] ?? null);
        $ends = self::moment($data['ends_at'] ?? null);
        if ($starts === false) {
            $errors['starts_at'] = __('Enter a valid date and time.');
        }
        if ($ends === false) {
            $errors['ends_at'] = __('Enter a valid date and time.');
        }
        if ($starts instanceof CarbonImmutable && $ends instanceof CarbonImmutable && $ends->lessThanOrEqualTo($starts)) {
            $errors['ends_at'] = __('The end must be after the start.');
        }

        $maxUses = trim((string) ($data['max_uses'] ?? ''));
        if ($maxUses !== '' && (preg_match('/^\d{1,7}$/', $maxUses) !== 1 || (int) $maxUses < 1)) {
            $errors['max_uses'] = __('Enter a whole number of 1 or more, or leave it blank.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = __('Enter a name (up to 100 characters).');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $values = [
            'name' => $name,
            'value' => $amount,
            'requires_approval' => (bool) ($data['requires_approval'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
        if ($seniorPwd) {
            return $values;
        }

        return $values + [
            'code' => $code,
            'kind' => DiscountKind::Standard,
            'discount_type' => $type,
            'scope' => $scope,
            'product_id' => $productId,
            'category_id' => $categoryId,
            'starts_at' => $starts instanceof CarbonImmutable ? $starts->setTimezone((string) config('app.timezone')) : null,
            'ends_at' => $ends instanceof CarbonImmutable ? $ends->setTimezone((string) config('app.timezone')) : null,
            'max_uses' => $maxUses === '' ? null : (int) $maxUses,
        ];
    }

    /** "2026-11-06T09:00" in Manila time; null when blank, false when invalid. */
    private static function moment(mixed $value): CarbonImmutable|false|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value) !== 1) {
            return false;
        }
        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, BusinessClock::timezone());

        return $parsed instanceof CarbonImmutable && $parsed->format('Y-m-d\TH:i') === $value ? $parsed : false;
    }
}
