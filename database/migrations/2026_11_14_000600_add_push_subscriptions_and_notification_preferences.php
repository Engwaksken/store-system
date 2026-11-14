<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Browser push notifications (one row per device a user turned push on for) and per-user channel preferences.
 * In-app notifications are always on; e-mail and push can be switched off by each user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->text('endpoint');
            $t->char('endpoint_hash', 64)->unique();   // endpoint URLs are too long for a portable unique index
            $t->string('public_key', 255);
            $t->string('auth_token', 255);
            $t->string('content_encoding', 20)->default('aes128gcm');
            $t->string('device', 150)->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->boolean('notify_email')->default(true)->after('is_active');
            $t->boolean('notify_push')->default(true)->after('notify_email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['notify_email', 'notify_push']));
        Schema::dropIfExists('push_subscriptions');
    }
};
