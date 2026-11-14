<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Services\PurchaseInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('view-invoices');
        $invoices = PurchaseInvoice::with('supplier', 'order')
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->filled('match_status'), fn ($q) => $q->where('match_status', $request->match_status))
            ->latest('id')->paginate(25)->withQueryString();

        $open = PurchaseInvoice::where('payment_status', '!=', 'paid');
        $stats = [
            'total' => PurchaseInvoice::count(),
            'unpaid' => (clone $open)->count(),
            'overdue' => (clone $open)->whereDate('due_date', '<', now())->count(),
            'balance' => (clone $open)->sum(\Illuminate\Support\Facades\DB::raw('total - amount_paid')),
        ];

        return view('invoices.index', compact('invoices', 'stats'));
    }

    public function create(PurchaseOrder $po)
    {
        Gate::authorize('record-invoice');

        return view('invoices.create', ['po' => $po->load('supplier', 'lines.item.unit')]);
    }

    public function store(Request $request, PurchaseOrder $po, PurchaseInvoiceService $service)
    {
        Gate::authorize('record-invoice');
        $data = $request->validate([
            'supplier_invoice_no' => ['required', 'string', 'max:100', Rule::unique('purchase_invoices')->where('supplier_id', $po->supplier_id)],
            'invoice_date' => 'required|date|before_or_equal:today',
            'due_date' => 'nullable|date|after_or_equal:invoice_date',
            'tax' => 'nullable|numeric|min:0',
            'lines' => 'required|array',
            'lines.*.qty' => 'nullable|numeric|min:0',
            'lines.*.unit_price' => 'nullable|numeric|min:0',
            'attachment' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('supplier-invoices', 'local');
        }
        $invoice = $service->record($request->user(), $po, $data, $data['lines']);

        return redirect()->route('invoices.show', $invoice)->with('status', $invoice->match_status === 'matched'
            ? "Invoice {$invoice->pi_no} recorded and matched to the PO and goods received."
            : "Invoice {$invoice->pi_no} recorded with a match exception. It needs approval before payment.");
    }

    public function show(PurchaseInvoice $invoice)
    {
        Gate::authorize('view-invoices');

        return view('invoices.show', ['invoice' => $invoice->load('supplier', 'order', 'recorder', 'exceptionApprover', 'lines.orderLine.item.unit', 'payments.recorder')]);
    }

    public function approveException(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceService $service)
    {
        Gate::authorize('approve-invoice-exception', $invoice);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $service->approveException($request->user(), $invoice, $data['reason']);

        return back()->with('status', 'Match exception approved; the invoice can now be paid.');
    }

    public function pay(Request $request, PurchaseInvoice $invoice, PurchaseInvoiceService $service)
    {
        Gate::authorize('pay-invoice', $invoice);
        $data = $request->validate([
            'amount' => 'required|numeric|gt:0',
            'paid_on' => 'required|date|before_or_equal:today',
            'method' => ['required', Rule::in(array_keys(SupplierPayment::METHODS))],
            'reference' => 'nullable|string|max:100',
            'idempotency_key' => 'nullable|string|max:64',
        ]);
        $payment = $service->pay($request->user(), $invoice, $data['amount'], $data['paid_on'], $data['method'], $data['reference'] ?? null, $data['idempotency_key'] ?? null);

        return back()->with('status', "Payment {$payment->payment_no} recorded.");
    }

    public function attachment(PurchaseInvoice $invoice)
    {
        Gate::authorize('view-invoices');
        abort_unless($invoice->attachment_path && Storage::disk('local')->exists($invoice->attachment_path), 404);

        return Storage::disk('local')->response($invoice->attachment_path);
    }
}
