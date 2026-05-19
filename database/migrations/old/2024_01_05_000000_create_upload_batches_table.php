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
        Schema::create('upload_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->uuid('batch_id')->nullable();
            $table->integer('total_files')->default(0);
            $table->integer('processed_files')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->timestamps();

            // Indexes
            $table->index(['user_id', 'status'], 'idx_user_status');
            $table->index('batch_id', 'idx_batch_id');
        });

        // Add foreign key to datas table
        Schema::table('datas', function (Blueprint $table) {
            $table->foreign('upload_batch_id')
                ->references('id')
                ->on('upload_batches')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('datas', function (Blueprint $table) {
            $table->dropForeign(['upload_batch_id']);
        });

        Schema::dropIfExists('upload_batches');
    }
};
