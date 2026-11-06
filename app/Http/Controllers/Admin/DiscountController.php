<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Discounts\DiscountPresets;
use App\Domain\Shared\BusinessClock;
use App\Enums\DiscountScope;
use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Discount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Preset discounts (FR-083; D-145). Route middleware: permission:settings.manage. */
class DiscountController extends Controller
{
    public function __construct(private readonly DiscountPresets $presets) {}

    public function index(): Response
    {
        $now = BusinessClock::now();

        return Inertia::render('admin/discounts/Index', [
            'discounts' => Discount::query()->with(['product:id,name', 'category:id,name'])
                ->withCount(['uses' => fn (Builder $q) => $q->whereHas('order', fn (Builder $o) => $o->where('status', '!=', OrderStatus::Cancelled))])
                ->orderByRaw("kind = 'senior_pwd' desc")->orderBy('name')->get()
                ->map(fn (Discount $d) => [
                    'id' => $d->id,
                    'code' => $d->code,
                    'name' => $d->name,
                    'senior_pwd' => $d->isSeniorPwd(),
                    'type' => $d->discount_type->value,
                    'value' => $d->value,
                    'scope_label' => match ($d->scope) {
                        DiscountScope::Order => $d->scope->label(),
                        DiscountScope::Product => $d->product?->name,
                        DiscountScope::Category => $d->category?->name,
                    },
                    'starts_at' => $d->starts_at?->toIso8601String(),
                    'ends_at' => $d->ends_at?->toIso8601String(),
                    'uses' => $d->uses_count,
                    'max_uses' => $d->max_uses,
                    'requires_approval' => $d->requires_approval,
                    'is_active' => $d->is_active,
                    'status' => ! $d->is_active ? 'off' : (($d->ends_at !== null && $d->ends_at->lessThanOrEqualTo($now)) ? 'ended' : (($d->starts_at !== null && $d->starts_at->greaterThan($now)) ? 'scheduled' : ($d->max_uses !== null && $d->uses_count >= $d->max_uses ? 'used_up' : 'on'))),
                ]),
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $discount = $this->presets->save($this->user($request), null, $this->input($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $discount->name])]);

        return to_route('admin.discounts.index');
    }

    public function edit(Discount $discount): Response
    {
        return $this->form($discount);
    }

    public function update(Request $request, Discount $discount): RedirectResponse
    {
        $this->presets->save($this->user($request), $discount, $this->input($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $discount->name])]);

        return to_route('admin.discounts.index');
    }

    private function form(?Discount $discount): Response
    {
        $local = fn ($moment) => $moment?->setTimezone(BusinessClock::timezone())->format('Y-m-d\TH:i');

        return Inertia::render('admin/discounts/Form', [
            'discount' => $discount ? [
                'id' => $discount->id,
                'code' => $discount->code,
                'name' => $discount->name,
                'senior_pwd' => $discount->isSeniorPwd(),
                'discount_type' => $discount->discount_type->value,
                'value' => $discount->value,
                'scope' => $discount->scope->value,
                'product_id' => $discount->product_id,
                'category_id' => $discount->category_id,
                'starts_at' => $local($discount->starts_at),
                'ends_at' => $local($discount->ends_at),
                'max_uses' => $discount->max_uses,
                'requires_approval' => $discount->requires_approval,
                'is_active' => $discount->is_active,
            ] : null,
            'types' => array_map(fn (DiscountType $t) => ['value' => $t->value, 'label' => $t->label()], DiscountType::cases()),
            'scopes' => array_map(fn (DiscountScope $s) => ['value' => $s->value, 'label' => $s->label()], DiscountScope::cases()),
            'products' => Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku']),
            'categories' => Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function input(Request $request): array
    {
        return $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:150'],
            'discount_type' => ['nullable', 'string', 'max:20'],
            'value' => ['nullable', 'string', 'max:20'],
            'scope' => ['nullable', 'string', 'max:20'],
            'product_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'starts_at' => ['nullable', 'string', 'max:20'],
            'ends_at' => ['nullable', 'string', 'max:20'],
            'max_uses' => ['nullable', 'string', 'max:10'],
            'requires_approval' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
