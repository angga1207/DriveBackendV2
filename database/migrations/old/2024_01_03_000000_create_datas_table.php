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
        Schema::create('datas', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->enum('type', ['folder', 'file'])->default('file');
            $table->enum('store_to', ['local', 'google', 'cdn'])->default('local');

            // Nested Set columns untuk hierarchical structure
            $table->unsignedInteger('_lft')->default(0);
            $table->unsignedInteger('_rgt')->default(0);
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->index('_lft');
            $table->index('_rgt');

            $table->text('name');
            $table->text('path')->nullable();
            $table->text('temp_path')->nullable();
            $table->bigInteger('size')->default(0);
            $table->string('extension')->nullable();
            $table->string('mimes')->nullable();
            $table->string('mimes_type')->nullable();

            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->unsignedBigInteger('pd_id')->nullable();
            $table->unsignedBigInteger('upload_batch_id')->nullable();

            $table->string('status')->default('active');
            $table->enum('shared', ['public', 'private'])->default('private');
            $table->timestamp('expired_at')->nullable();
            $table->boolean('no_expired')->default(false);
            $table->boolean('favorite')->default(false);
            $table->boolean('s_editable')->default(false);
            $table->boolean('skip_upload_to_google')->default(false);

            $table->text('gd_folder')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // Composite indexes untuk query optimization
            $table->index(['user_id', 'status', 'parent_id'], 'idx_user_status_parent');
            $table->index(['slug', 'user_id'], 'idx_slug_user');
            $table->index(['type', 'status', 'user_id'], 'idx_type_status_user');
            $table->index(['parent_id', 'status'], 'idx_parent_status');
            $table->index(['user_id', 'deleted_at'], 'idx_user_deleted');
            $table->index(['favorite', 'user_id', 'status'], 'idx_favorite_user_status');
            $table->index(['shared', 'status', 'expired_at'], 'idx_shared_status_expired');
            $table->index(['upload_batch_id', 'status'], 'idx_batch_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('datas');
    }
};
