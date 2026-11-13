<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: project controls. Cost codes, BOQ, milestones, budgets per cost code, expense claims with approval,
 * and the links needed to report budget vs committed vs actual by cost code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();
            $t->string('name', 150);
            $t->string('category', 20)->default('materials'); // materials | labour | equipment | subcontract | overheads
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('project_budgets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('cost_code_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 15, 2);
            $t->timestamps();
            $t->unique(['project_id', 'cost_code_id']);
        });

        Schema::create('boq_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('cost_code_id')->constrained()->restrictOnDelete();
            $t->string('ref', 30);                       // e.g. "2.1.3"
            $t->string('description', 500);
            $t->string('unit', 20);
            $t->decimal('qty', 14, 3);
            $t->decimal('rate', 15, 2);
            $t->decimal('amount', 15, 2);                // qty × rate
            $t->foreignId('item_id')->nullable()->constrained('inventory_items')->restrictOnDelete(); // optional catalogue link
            $t->timestamps();
            $t->unique(['project_id', 'ref']);
            $t->index('cost_code_id');
        });

        Schema::create('project_milestones', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->string('name', 200);
            $t->date('planned_on')->nullable();
            $t->date('completed_on')->nullable();
            $t->string('notes', 500)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
            $t->index(['project_id', 'sort']);
        });

        Schema::table('material_request_lines', function (Blueprint $t) {
            $t->foreignId('boq_item_id')->nullable()->after('item_id')->constrained()->restrictOnDelete();
        });

        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->foreignId('cost_code_id')->nullable()->after('project_id')->constrained()->restrictOnDelete();
        });

        Schema::table('expenses', function (Blueprint $t) {
            $t->foreignId('cost_code_id')->nullable()->after('category_id')->constrained()->restrictOnDelete();
        });

        Schema::create('expense_claims', function (Blueprint $t) {
            $t->id();
            $t->string('claim_no', 30)->unique();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('category_id')->constrained('expense_categories')->restrictOnDelete();
            $t->foreignId('cost_code_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('claimant_id')->constrained('users')->restrictOnDelete();
            $t->date('incurred_on');
            $t->decimal('amount', 15, 2);
            $t->string('description', 1000);
            $t->string('receipt_path')->nullable();
            $t->string('status', 20)->default('submitted')->index(); // submitted | approved | rejected | paid
            $t->foreignId('approver_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_reason', 1000)->nullable();
            $t->timestamps();
            $t->index(['project_id', 'status']);
        });

        Schema::table('expenses', function (Blueprint $t) {
            // A paid claim becomes exactly one expense row.
            $t->foreignId('expense_claim_id')->nullable()->unique()->after('recorded_by')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $t) {
            $t->dropConstrainedForeignId('expense_claim_id');
            $t->dropConstrainedForeignId('cost_code_id');
        });
        Schema::dropIfExists('expense_claims');
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('cost_code_id'));
        Schema::table('material_request_lines', fn (Blueprint $t) => $t->dropConstrainedForeignId('boq_item_id'));
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('boq_items');
        Schema::dropIfExists('project_budgets');
        Schema::dropIfExists('cost_codes');
    }
};
