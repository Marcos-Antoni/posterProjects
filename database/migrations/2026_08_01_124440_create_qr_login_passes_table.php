<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('qr_login_passes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();   // sha256 hex; unique = the lookup key
            $table->timestamp('expires_at')->index();     // mirrors personal_access_tokens:21
            $table->timestamp('consumed_at')->nullable(); // NULL is the single-use predicate
            $table->string('consumed_ip', 45)->nullable(); // IPv6 max textual length + headroom
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_login_passes');
    }
};
