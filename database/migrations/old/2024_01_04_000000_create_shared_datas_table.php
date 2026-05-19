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
        Schema::create('shared_datas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_id')->constrained('datas')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->enum('access_type', ['read', 'write'])->default('read');
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'data_id'], 'idx_user_data');
            $table->index(['data_id', 'access_type'], 'idx_data_access');
            $table->unique(['user_id', 'data_id'], 'unique_user_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_datas');
    }
};
