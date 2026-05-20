<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_login_attempts', function (Blueprint $table) {
            $table->id();

            $table->string('ip_address', 45);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('username', 255)->nullable();

            $table->text('user_agent')->nullable();

            $table->timestamps();

            $table->index(['ip_address', 'user_id'], 'idx_sla_ip_user');
            $table->index(['ip_address'], 'idx_sla_ip');
            $table->index(['user_id'], 'idx_sla_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_login_attempts');
    }
};
