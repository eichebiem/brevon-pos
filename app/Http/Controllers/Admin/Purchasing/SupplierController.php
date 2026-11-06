<?php

namespace App\Http\Controllers\Admin\Purchasing;

use App\Domain\Purchasing\SupplierService;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Supplier records (FR-058; D-135). */
class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        return Inertia::render('admin/purchasing/suppliers/Index', [
            'suppliers' => Supplier::query()
                ->when($filters['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('supplier_code', 'like', "%{$s}%")))
                ->when(($filters['status'] ?? null) === 'active', fn ($q) => $q->where('is_active', true))
                ->when(($filters['status'] ?? null) === 'inactive', fn ($q) => $q->where('is_active', false))
                ->orderByDesc('is_active')->orderBy('name')
                ->paginate(25)->withQueryString()
                ->through(fn (Supplier $s) => $this->row($s)),
            'filters' => (object) $filters,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/purchasing/suppliers/Form', ['supplier' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $supplier = $this->suppliers->create($this->user($request), $this->validated($request));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier :code added.', ['code' => $supplier->supplier_code])]);

        return to_route('admin.suppliers.index');
    }

    public function edit(Supplier $supplier): Response
    {
        return Inertia::render('admin/purchasing/suppliers/Form', ['supplier' => $this->row($supplier)]);
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $data = [...$this->validated($request), 'is_active' => $request->boolean('is_active')];
        $this->suppliers->update($this->user($request), $supplier, $data);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Supplier saved.')]);

        return to_route('admin.suppliers.index');
    }

    /**
     * @return array{name: string, contact_person: string|null, phone: string|null, email: string|null, address: string|null, payment_terms: string|null}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: string, contact_person?: string|null, phone?: string|null, email?: string|null, address?: string|null, payment_terms?: string|null} $data */
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]+$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
        ], ['phone.regex' => __('Use digits, spaces, +, - and brackets only.')]);

        return [
            'name' => trim($data['name']),
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'code' => $s->supplier_code,
            'name' => $s->name,
            'contact_person' => $s->contact_person,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'payment_terms' => $s->payment_terms,
            'is_active' => $s->is_active,
        ];
    }
}
