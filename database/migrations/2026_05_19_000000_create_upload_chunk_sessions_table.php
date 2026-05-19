<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_chunk_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('data_id')->constrained('datas')->onDelete('cascade');

            $table->uuid('chunk_id');

            $table->unsignedBigInteger('file_size');

            $table->integer('total_chunks');
            $table->integer('uploaded_parts')->default(0);

            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');

            $table->timestamps();

            $table->index(['user_id', 'status'], 'idx_uc_user_status');
            $table->unique(['user_id', 'chunk_id'], 'uq_uc_user_chunk_id');
            $table->unique(['data_id'], 'uq_uc_data_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_chunk_sessions');
    }
};
