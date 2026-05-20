<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_login_blocks', function (Blueprint $table) {
            $table->id();

            $table->string('ip_address', 45)->nullable();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // If null => permanent block (until reset by admin)
            $table->timestamp('blocked_until')->nullable();

            $table->string('reason')->nullable();

            $table->timestamps();

            $table->index(['ip_address'], 'idx_slb_ip');
            $table->index(['user_id'], 'idx_slb_user');
            $table->index(['blocked_until'], 'idx_slb_blocked_until');

        });

        // Note: Blueprint::check() tidak tersedia pada Laravel versi ini,
        // jadi validasi constraint dilakukan lewat logic aplikasi (reset/enforce).
    }

    public function down(): void
    {
        Schema::dropIfExists('security_login_blocks');
    }
};
