<?php

namespace App\Domain\Tax;

use App\Domain\Shared\AuditLogger;
use App\Models\TaxRule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Tax rules managed by `settings.manage` (FR-086; D-149). Orders keep snapshots of the rule they used. */
class TaxRules
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?TaxRule $rule, array $data): TaxRule
    {
        $errors = [];
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            $errors['name'] = __('Enter a name (up to 100 characters).');
        }
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        if ($code !== '' && preg_match('/^[A-Z0-9-]{1,40}$/', $code) !== 1) {
            $errors['code'] = __('Use up to 40 letters, digits or dashes.');
        }
        $rate = trim((string) ($data['rate'] ?? ''));
        $normalised = '0.0000';
        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $rate) !== 1 || ! is_numeric($rate) || bccomp($rate, '0', 4) <= 0 || bccomp($rate, '100', 4) > 0) {
            $errors['rate'] = __('Enter a rate more than 0 and up to 100 (up to 4 decimal places).');
        } else {
            $normalised = bcadd($rate, '0', 4);
        }
        $from = self::date($data['effective_from'] ?? null);
        $until = self::date($data['effective_until'] ?? null);
        if (! $from instanceof CarbonImmutable) {
            $errors['effective_from'] = __('Enter the date the rule starts.');
        }
        if ($until === false) {
            $errors['effective_until'] = __('Enter a valid date.');
        } elseif ($from instanceof CarbonImmutable && $until instanceof CarbonImmutable && $until->lessThan($from)) {
            $errors['effective_until'] = __('The end date cannot be before the start date.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        /** @var CarbonImmutable $from */
        $values = [
            'name' => $name,
            'code' => $code === '' ? null : $code,
            'rate' => $normalised,
            'calculation_method' => 'percentage',
            'inclusive' => (bool) ($data['inclusive'] ?? false),
            'effective_from' => $from->toDateString(),
            'effective_until' => $until instanceof CarbonImmutable ? $until->toDateString() : null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ];

        return DB::transaction(function () use ($actor, $rule, $values) {
            $before = $rule?->only(array_keys($values));
            $rule ??= new TaxRule;
            $rule->forceFill($values)->save();
            $this->audit->record($before === null ? 'tax_rule.created' : 'tax_rule.updated', $rule, $before, $values, null, $actor);

            return $rule;
        });
    }

    private static function date(mixed $value): CarbonImmutable|false|null
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            return false;
        }

        return CarbonImmutable::parse($value);
    }
}
