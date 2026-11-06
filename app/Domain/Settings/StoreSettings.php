<?php

namespace App\Domain\Settings;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\Money;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Global store settings (FR-013, FR-101, D-088). Currency and time zone are
 * fixed in V1 and read from config, so there is one source of truth for
 * money and business dates. Never stores secrets.
 */
class StoreSettings
{
    public const DEFAULTS = [
        'store.name' => 'Brevon Doughnuts & Coffee',
        'store.logo_path' => null,
        'receipt.footer' => 'Thank you for choosing Brevon!',
        // D-127 (TBC): cash out / paid out above this, by someone who cannot
        // review shifts, waits for a manager's approval.
        'cash.approval_over' => '500.00',
        // D-128 (TBC): a closed shift whose difference is larger than this
        // (either way) is listed as needing review. 0 = any difference.
        'shift.review_variance_over' => '0.00',
        // D-146 (TBC): a discount larger than this needs a manager's approval.
        'discount.approval_over' => '100.00',
    ];

    private const CACHE_KEY = 'brevon.store-settings';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array<string, string|null>
     */
    public function all(): array
    {
        /** @var array<string, string|null> $stored */
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->whereNull('branch_id')
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key')
            ->all());

        return array_merge(self::DEFAULTS, $stored);
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    public function logoUrl(): ?string
    {
        $path = $this->get('store.logo_path');

        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forDisplay(): array
    {
        return [
            'name' => $this->get('store.name'),
            'logo_url' => $this->logoUrl(),
            'receipt_footer' => $this->get('receipt.footer'),
            'cash_approval_over' => $this->get('cash.approval_over'),
            'shift_review_variance_over' => $this->get('shift.review_variance_over'),
            'discount_approval_over' => $this->get('discount.approval_over'),
            'currency' => (string) config('brevon.currency'),
            'timezone' => (string) config('brevon.business_timezone'),
        ];
    }

    public function money(string $key): Money
    {
        return Money::fromDecimal((string) ($this->get($key) ?? '0'));
    }

    /**
     * @param  array{name: string, receipt_footer: string|null, cash_approval_over?: string, shift_review_variance_over?: string, discount_approval_over?: string}  $values
     */
    public function update(User $actor, array $values, ?UploadedFile $logo = null, bool $removeLogo = false): void
    {
        $changes = [
            'store.name' => $values['name'],
            'receipt.footer' => $values['receipt_footer'],
        ];
        foreach (['cash_approval_over' => 'cash.approval_over', 'shift_review_variance_over' => 'shift.review_variance_over', 'discount_approval_over' => 'discount.approval_over'] as $field => $key) {
            if (isset($values[$field])) {
                $changes[$key] = Money::fromDecimal($values[$field])->toDecimal();
            }
        }

        $oldLogo = $this->get('store.logo_path');
        if ($logo !== null) {
            $changes['store.logo_path'] = $logo->store('branding', 'public') ?: null;
        } elseif ($removeLogo) {
            $changes['store.logo_path'] = null;
        }

        DB::transaction(function () use ($actor, $changes) {
            $current = $this->all();

            foreach ($changes as $key => $value) {
                if (($current[$key] ?? null) === $value) {
                    continue;
                }

                $row = Setting::query()->firstOrNew(['branch_id' => null, 'key' => $key]);
                $row->fill(['value' => $value, 'value_type' => 'string', 'is_sensitive' => false])->save();

                $this->audit->record('setting.updated', $row, [$key => $current[$key] ?? null], [$key => $value], actor: $actor);
            }
        });

        Cache::forget(self::CACHE_KEY);

        $newLogo = $this->get('store.logo_path');
        if ($oldLogo !== null && $oldLogo !== $newLogo) {
            Storage::disk('public')->delete($oldLogo);
        }
    }
}
