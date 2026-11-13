<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3: procurement. Suppliers → purchase requisitions → supplier quotes → purchase orders →
 * goods received (GRN = stock_receipts linked to the PO) → supplier invoices (three-way match) → payments.
 * Purchasing approval is separate from material-issue approval (see docs/PROCUREMENT.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name')->unique();
            $t->string('contact_person')->nullable();
            $t->string('phone', 50)->nullable();
            $t->string('email', 150)->nullable();
            $t->string('address', 300)->nullable();
            $t->string('tin', 50)->nullable();
            $t->string('payment_terms', 100)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('purchase_requisitions', function (Blueprint $t) {
            $t->id();
            $t->string('pr_no', 30)->unique();
            $t->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained('stores')->restrictOnDelete(); // delivery store
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->string('status', 20)->default('draft')->index(); // draft|submitted|approved|rejected|ordered|cancelled
            $t->string('purpose', 1000);
            $t->date('needed_on')->nullable();
            $t->decimal('estimated_total', 15, 2)->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_reason', 1000)->nullable();
            $t->timestamps();
            $t->index('project_id');
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('est_unit_cost', 15, 4)->default(0);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['purchase_requisition_id', 'item_id'], 'prl_requisition_item_unique'); // auto name exceeds MySQL's 64 chars
        });

        Schema::create('supplier_quotes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_requisition_id')->constrained()->restrictOnDelete();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('quote_ref', 100)->nullable();
            $t->date('quoted_on');
            $t->date('valid_until')->nullable();
            $t->decimal('total', 15, 2)->default(0);
            $t->boolean('is_selected')->default(false);
            $t->string('selection_reason', 1000)->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['purchase_requisition_id', 'supplier_id']);
        });

        Schema::create('supplier_quote_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('supplier_quote_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_requisition_line_id')->constrained()->restrictOnDelete();
            $t->decimal('unit_price', 15, 4);
            $t->timestamps();
            $t->unique(['supplier_quote_id', 'purchase_requisition_line_id'], 'sql_quote_prline_unique');
        });

        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->id();
            $t->string('po_no', 30)->unique();
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_requisition_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('supplier_quote_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained('stores')->restrictOnDelete(); // deliver to
            // pending_approval | approved | rejected | partially_received | received | closed | cancelled
            $t->string('status', 30)->default('pending_approval')->index();
            $t->date('order_date');
            $t->date('expected_on')->nullable();
            $t->decimal('total', 15, 2)->default(0);
            $t->string('terms', 1000)->nullable();
            $t->string('notes', 1000)->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_reason', 1000)->nullable();
            $t->unsignedInteger('lock_version')->default(0); // optimistic lock for approvals
            $t->timestamps();
            $t->index(['supplier_id', 'status']);
            $t->index('project_id');
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_price', 15, 4);
            $t->decimal('line_total', 15, 2);
            $t->decimal('received_qty', 14, 3)->default(0);  // maintained under a PO row lock by GoodsReceiptService
            $t->decimal('invoiced_qty', 14, 3)->default(0);  // maintained under a PO row lock by PurchaseInvoiceService
            $t->timestamps();
            $t->unique(['purchase_order_id', 'item_id']);
        });

        Schema::table('stock_receipts', function (Blueprint $t) {
            $t->foreignId('supplier_id')->nullable()->after('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained()->restrictOnDelete();
        });
        Schema::table('stock_receipt_lines', function (Blueprint $t) {
            $t->foreignId('purchase_order_line_id')->nullable()->after('item_id')->constrained()->restrictOnDelete();
        });

        Schema::create('purchase_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('pi_no', 30)->unique();                 // internal reference
            $t->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $t->string('supplier_invoice_no', 100);
            $t->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->decimal('subtotal', 15, 2);
            $t->decimal('tax', 15, 2)->default(0);
            $t->decimal('total', 15, 2);
            $t->decimal('amount_paid', 15, 2)->default(0);     // maintained by PaymentService under an invoice lock
            $t->string('match_status', 20);                    // matched | exception | exception_approved
            $t->text('match_notes')->nullable();
            $t->string('payment_status', 20)->default('unpaid'); // unpaid | partially_paid | paid
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('exception_approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('exception_approved_at')->nullable();
            $t->string('exception_reason', 1000)->nullable();
            $t->string('attachment_path')->nullable();
            $t->timestamps();
            $t->unique(['supplier_id', 'supplier_invoice_no']); // the same supplier invoice cannot be entered twice
            $t->index(['payment_status', 'due_date']);
        });

        Schema::create('purchase_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_order_line_id')->constrained()->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_price', 15, 4);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
        });

        Schema::create('supplier_payments', function (Blueprint $t) {
            $t->id();
            $t->string('payment_no', 30)->unique();
            $t->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->date('paid_on');
            $t->string('method', 30);   // bank_transfer | cheque | mobile_money | cash
            $t->string('reference', 100)->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->string('idempotency_key', 64)->nullable()->unique();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('purchase_invoice_lines');
        Schema::dropIfExists('purchase_invoices');
        Schema::table('stock_receipt_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('purchase_order_line_id'));
        Schema::table('stock_receipts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('purchase_order_id');
            $t->dropConstrainedForeignId('supplier_id');
        });
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('supplier_quote_lines');
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
        Schema::dropIfExists('suppliers');
    }
};
