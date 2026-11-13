<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: multiple stores (central + project site stores), storekeeper assignment,
 * two-step transfers, returns to store, stock counts and idempotency keys.
 *
 * Existing single-store data is migrated into store #1 "MAIN" and every existing storekeeper
 * is assigned to it, so nothing changes for current users until new stores are added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name')->unique();
            $t->string('type', 20)->default('central'); // central | site
            $t->foreignId('project_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('location')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        DB::table('stores')->insert([
            'id' => 1, 'code' => 'MAIN', 'name' => 'Main Store', 'type' => 'central', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::create('store_user', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['store_id', 'user_id']);
            $t->index('user_id');
        });
        foreach (DB::table('users')->where('role', 'storekeeper')->pluck('id') as $uid) {
            DB::table('store_user')->insert(['store_id' => 1, 'user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        }

        // MySQL needs a plain index on item_id (for its FK) before the old unique index can go.
        Schema::table('inventory_balances', fn (Blueprint $t) => $t->index('item_id'));
        Schema::table('inventory_balances', fn (Blueprint $t) => $t->dropUnique(['item_id']));
        Schema::table('inventory_balances', function (Blueprint $t) {
            $t->foreignId('store_id')->default(1)->after('id')->constrained('stores')->restrictOnDelete();
            $t->unique(['store_id', 'item_id']);
        });

        Schema::table('stock_movements', function (Blueprint $t) {
            $t->foreignId('store_id')->default(1)->after('item_id')->constrained('stores')->restrictOnDelete();
            $t->index(['store_id', 'item_id', 'id']);
        });

        Schema::table('stock_receipts', function (Blueprint $t) {
            $t->foreignId('store_id')->default(1)->after('type')->constrained('stores')->restrictOnDelete();
            $t->string('idempotency_key', 64)->nullable()->unique();
        });

        Schema::table('stock_adjustments', function (Blueprint $t) {
            $t->foreignId('store_id')->default(1)->after('item_id')->constrained('stores')->restrictOnDelete();
            $t->unsignedBigInteger('stock_count_id')->nullable()->after('store_id')->index();
        });

        Schema::table('material_issues', function (Blueprint $t) {
            $t->foreignId('store_id')->default(1)->after('project_id')->constrained('stores')->restrictOnDelete();
            $t->string('idempotency_key', 64)->nullable()->unique();
        });

        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('transfer_no', 30)->unique();
            $t->foreignId('from_store_id')->constrained('stores')->restrictOnDelete();
            $t->foreignId('to_store_id')->constrained('stores')->restrictOnDelete();
            $t->string('status', 20)->default('in_transit')->index(); // in_transit | received | cancelled
            $t->foreignId('dispatched_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('dispatched_at');
            $t->foreignId('received_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('received_at')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->string('receive_notes', 1000)->nullable();
            $t->string('idempotency_key', 64)->nullable()->unique();
            $t->timestamps();
            $t->index(['from_store_id', 'dispatched_at']);
            $t->index(['to_store_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_transfer_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty', 14, 3);                       // dispatched
            $t->decimal('received_qty', 14, 3)->nullable();  // confirmed at destination
            $t->decimal('unit_cost', 15, 4);
            $t->string('shortfall_reason', 500)->nullable();
            $t->timestamps();
            $t->unique(['stock_transfer_id', 'item_id']);
        });

        Schema::create('stock_returns', function (Blueprint $t) {
            $t->id();
            $t->string('return_no', 30)->unique();
            $t->foreignId('material_issue_id')->constrained()->restrictOnDelete();
            $t->foreignId('project_id')->constrained()->restrictOnDelete();
            $t->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $t->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $t->string('returned_by_name');
            $t->string('reason', 1000);
            $t->decimal('total_value', 15, 2)->default(0);
            $t->timestamp('returned_at');
            $t->string('idempotency_key', 64)->nullable()->unique();
            $t->timestamps();
            $t->index('project_id');
        });

        Schema::create('stock_return_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_return_id')->constrained()->restrictOnDelete();
            $t->foreignId('material_issue_line_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_cost', 15, 4);
            $t->decimal('line_value', 15, 2);
            $t->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->string('count_no', 30)->unique();
            $t->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $t->string('status', 20)->default('draft')->index(); // draft | submitted | approved | rejected
            $t->foreignId('counted_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_reason', 1000)->nullable();
            $t->string('notes', 1000)->nullable();
            $t->timestamps();
        });

        Schema::create('stock_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained('inventory_items')->restrictOnDelete();
            $t->decimal('system_qty', 14, 3);           // book quantity frozen when the count sheet was created
            $t->decimal('counted_qty', 14, 3)->nullable();
            $t->string('reason', 500)->nullable();      // mandatory when counted ≠ system
            $t->timestamps();
            $t->unique(['stock_count_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
        Schema::dropIfExists('stock_return_lines');
        Schema::dropIfExists('stock_returns');
        Schema::dropIfExists('stock_transfer_lines');
        Schema::dropIfExists('stock_transfers');
        Schema::table('material_issues', function (Blueprint $t) {
            $t->dropUnique(['idempotency_key']);
            $t->dropColumn('idempotency_key');
            $t->dropConstrainedForeignId('store_id');
        });
        Schema::table('stock_adjustments', function (Blueprint $t) {
            $t->dropIndex(['stock_count_id']);
            $t->dropColumn('stock_count_id');
            $t->dropConstrainedForeignId('store_id');
        });
        Schema::table('stock_receipts', function (Blueprint $t) {
            $t->dropUnique(['idempotency_key']);
            $t->dropColumn('idempotency_key');
            $t->dropConstrainedForeignId('store_id');
        });
        Schema::table('stock_movements', function (Blueprint $t) {
            $t->dropIndex(['store_id', 'item_id', 'id']);
            $t->dropConstrainedForeignId('store_id');
        });
        Schema::table('inventory_balances', function (Blueprint $t) {
            $t->dropForeign(['store_id']);
            $t->dropUnique(['store_id', 'item_id']);
            $t->dropColumn('store_id');
        });
        Schema::table('inventory_balances', fn (Blueprint $t) => $t->unique('item_id'));
        Schema::table('inventory_balances', fn (Blueprint $t) => $t->dropIndex(['item_id']));
        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
