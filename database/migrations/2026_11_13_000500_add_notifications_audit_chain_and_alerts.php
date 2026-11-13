<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: in-app notifications, a tamper-evident audit log (HMAC hash chain) and budget-alert de-duplication.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('type');
            $t->morphs('notifiable');
            $t->text('data');
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });

        // Each activity row stores the previous row's hash and its own HMAC(prev_hash | content), keyed with APP_KEY.
        // Editing or deleting any row breaks the chain; `php artisan cms:verify-audit` reports where.
        Schema::table('activity_logs', function (Blueprint $t) {
            $t->char('prev_hash', 64)->nullable();
            $t->char('hash', 64)->nullable();
        });
        // Single-row chain head, locked FOR UPDATE by every audit write so the chain cannot fork under concurrency.
        Schema::create('audit_chain_heads', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->char('last_hash', 64)->nullable();
            $t->unsignedBigInteger('last_id')->nullable();
        });
        DB::table('audit_chain_heads')->insert(['id' => 1, 'last_hash' => null, 'last_id' => null]);

        Schema::create('budget_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('cost_code_id')->default(0); // 0 = whole-project budget
            $t->string('level', 10);                           // warn | over
            $t->timestamp('notified_at');
            $t->unique(['project_id', 'cost_code_id', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_alerts');
        Schema::dropIfExists('audit_chain_heads');
        Schema::table('activity_logs', fn (Blueprint $t) => $t->dropColumn(['prev_hash', 'hash']));
        Schema::dropIfExists('notifications');
    }
};
