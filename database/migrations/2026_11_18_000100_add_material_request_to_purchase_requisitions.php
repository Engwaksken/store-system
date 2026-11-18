<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Restock requisitions raised automatically when a material request could not be fully issued from stock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $t) {
            $t->foreignId('material_request_id')->nullable()->after('store_id')->constrained('material_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('material_request_id');
        });
    }
};
