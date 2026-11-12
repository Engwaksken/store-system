<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 domain schema. See docs/SCHEMA.md.
 * Quantities DECIMAL(14,3); money DECIMAL(15,2); unit costs DECIMAL(15,4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 100)->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->string('sequence_key', 40)->unique(); // e.g. "IV-2026"
            $t->unsignedInteger('last_number')->default(0);
            $t->timestamps();
        });

        Schema::create('projects', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->string('client')->nullable();
            $t->string('location')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->string('status', 20)->default('active')->index();
            $t->decimal('budget', 15, 2)->default(0);
            $t->foreignId('manager_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->text('description')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('manager_id');
        });

        Schema::create('project_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
            $t->index('user_id');
        });

        Schema::create('inventory_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->timestamps();
        });

        Schema::create('inventory_units', function (Blueprint $t) {
            $t->id();
            $t->string('name', 50);
            $t->string('abbreviation', 15)->unique();
            $t->timestamps();
        });

        Schema::create('inventory_items', function (Blueprint $t) {
            $t->id();
            $t->string('sku', 40)->unique();
            $t->string('name');
            $t->foreignId('category_id')->constrained('inventory_categories')->restrictOnDelete();
            $t->foreignId('unit_id')->constrained('inventory_units')->restrictOnDelete();
            $t->decimal('min_level', 14, 3)->default(0);
            $t->decimal('avg_cost', 15, 4)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('inventory_balances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->unique()->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('on_hand', 14, 3)->default(0);
            $t->timestamps();
        });

        Schema::create('stock_receipts', function (Blueprint $t) {
            $t->id();
            $t->string('receipt_no', 30)->unique();
            $t->string('type', 20)->default('purchase'); // opening | purchase
            $t->string('supplier_name')->nullable();
            $t->string('reference', 100)->nullable();
            $t->date('received_on');
            $t->text('notes')->nullable();
            $t->decimal('total_value', 15, 2)->default(0);
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('stock_receipt_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_cost', 15, 4);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
        });

        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->string('adjustment_no', 30)->unique();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty_delta', 14, 3);
            $t->string('reason', 500);
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });

        Schema::create('material_requests', function (Blueprint $t) {
            $t->id();
            $t->string('request_no', 30)->unique();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('project_manager_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedInteger('version')->default(1);
            $t->string('status', 30)->default('draft')->index();
            $t->string('purpose', 1000);
            $t->date('needed_on')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamps();
            $t->index(['project_id', 'status']);
            $t->index('requester_id');
            $t->index('project_manager_id');
        });

        Schema::create('material_request_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_request_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('requested_qty', 14, 3);
            $t->decimal('pm_approved_qty', 14, 3)->nullable();
            $t->decimal('final_approved_qty', 14, 3)->nullable();
            $t->decimal('issued_qty', 14, 3)->default(0);
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['material_request_id', 'item_id']);
        });

        Schema::create('material_approvals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_request_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version');
            $t->string('step', 20);     // pm | overall
            $t->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $t->string('decision', 20); // approved | rejected
            $t->string('reason', 1000)->nullable();
            $t->boolean('was_substitute')->default(false);
            $t->json('lines_snapshot')->nullable();
            $t->timestamp('decided_at');
            $t->timestamps();
            $t->unique(['material_request_id', 'version', 'step']);
        });

        Schema::create('material_issues', function (Blueprint $t) {
            $t->id();
            $t->string('issue_no', 30)->unique();
            $t->foreignId('material_request_id')->constrained()->restrictOnDelete();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $t->string('recipient_name');
            $t->timestamp('issued_at');
            $t->decimal('total_value', 15, 2)->default(0);
            $t->string('notes', 1000)->nullable();
            $t->timestamps();
            $t->index('project_id');
        });

        Schema::create('material_issue_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_issue_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_request_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_cost_snapshot', 15, 4);
            $t->decimal('line_value', 15, 2);
            $t->timestamps();
        });

        Schema::create('issue_acknowledgments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('material_issue_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('acknowledged_by')->constrained('users')->restrictOnDelete();
            $t->string('recipient_name');
            $t->string('remarks', 1000)->nullable();
            $t->timestamp('acknowledged_at');
            $t->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->string('movement_type', 20); // opening | receipt | issue | adjustment
            $t->decimal('qty_delta', 14, 3);
            $t->decimal('unit_cost', 15, 4);
            $t->decimal('balance_after', 14, 3);
            $t->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('source_type', 60);
            $t->unsignedBigInteger('source_id');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at');
            $t->index(['item_id', 'id']);
            $t->index('project_id');
            $t->index(['source_type', 'source_id']);
        });

        Schema::create('expense_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100)->unique();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $t->date('incurred_on');
            $t->decimal('amount', 15, 2);
            $t->string('payment_mode', 30);
            $t->string('reference', 100)->nullable();
            $t->string('description', 1000);
            $t->string('receipt_path')->nullable();
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['project_id', 'incurred_on']);
        });

        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 60)->index();
            $t->string('subject_type', 60)->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('description', 1000);
            $t->json('properties')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->timestamp('created_at')->index();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'activity_logs', 'expenses', 'expense_categories', 'stock_movements',
            'issue_acknowledgments', 'material_issue_lines', 'material_issues',
            'material_approvals', 'material_request_lines', 'material_requests',
            'stock_adjustments', 'stock_receipt_lines', 'stock_receipts',
            'inventory_balances', 'inventory_items', 'inventory_units', 'inventory_categories',
            'project_user', 'projects', 'document_sequences', 'app_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
