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
        Schema::create('ref_perangkat_daerah', function (Blueprint $table) {
            $table->id();
            $table->string('nama')->nullable();
            $table->string('singkatan')->nullable();
            $table->string('kode')->nullable();
            $table->string('status')->nullable();
            $table->timestamps();

            // Index
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ref_perangkat_daerah');
    }
};
