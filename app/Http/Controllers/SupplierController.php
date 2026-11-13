<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-suppliers');
        $suppliers = Supplier::withCount('purchaseOrders')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$request->q}%")->orWhere('code', 'like', "%{$request->q}%")))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        Gate::authorize('manage-suppliers');

        return view('suppliers.form', ['supplier' => new Supplier(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-suppliers');
        $supplier = Supplier::create($this->validated($request));
        ActivityLog::record('supplier.created', "Supplier {$supplier->code} created", $supplier);

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier created.');
    }

    public function show(Supplier $supplier)
    {
        Gate::authorize('view-suppliers');

        return view('suppliers.show', [
            'supplier' => $supplier,
            'orders' => $supplier->purchaseOrders()->latest('id')->limit(25)->get(),
            'invoices' => Gate::allows('view-invoices') ? $supplier->invoices()->latest('id')->limit(25)->get() : collect(),
        ]);
    }

    public function edit(Supplier $supplier)
    {
        Gate::authorize('manage-suppliers');

        return view('suppliers.form', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        Gate::authorize('manage-suppliers');
        $supplier->update($this->validated($request, $supplier));
        ActivityLog::record('supplier.updated', "Supplier {$supplier->code} updated", $supplier);

        return redirect()->route('suppliers.show', $supplier)->with('status', 'Supplier updated.');
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('suppliers')->ignore($supplier?->id)],
            'name' => ['required', 'string', 'max:255', Rule::unique('suppliers')->ignore($supplier?->id)],
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'address' => 'nullable|string|max:300',
            'tin' => 'nullable|string|max:50',
            'payment_terms' => 'nullable|string|max:100',
        ]);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }
}
