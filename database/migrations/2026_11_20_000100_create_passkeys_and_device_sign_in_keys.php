<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric sign-in.
 * - passkeys: WebAuthn credentials for the web app (fingerprint, face or device PIN via the browser / OS).
 * - device_sign_in_keys: one per phone that turned on fingerprint / face sign-in in the mobile app. Only a SHA-256
 *   hash of the secret is stored; the phone keeps the secret in its keystore behind the device biometrics.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('passkeys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->string('credential_id', 512)->unique(); // base64url
            $t->text('public_key'); // PEM
            $t->unsignedBigInteger('sign_count')->default(0);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });

        Schema::create('device_sign_in_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->char('secret_hash', 64);
            $t->timestamp('last_used_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sign_in_keys');
        Schema::dropIfExists('passkeys');
    }
};
