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
        Schema::table('users', function (Blueprint $table) {
            $table->string('fullname')->after('id');
            $table->string('firstname')->nullable()->after('fullname');
            $table->string('lastname')->nullable()->after('firstname');
            $table->string('username')->unique()->after('email');
            $table->string('google_id')->nullable()->after('password');
            $table->string('perangkat_daerah_id')->nullable()->after('google_id');
            $table->string('photo')->default('storage/images/default.png')->after('perangkat_daerah_id');
            $table->enum('status', ['active', 'pending', 'banned'])->default('active')->after('photo');
            $table->double('drive_capacity')->default(53687091200)->after('status');
            $table->string('fcm_token')->nullable()->after('drive_capacity');
            $table->string('access')->default('true')->after('fcm_token');
            $table->string('isAdmin')->default('false')->after('access');
            $table->softDeletes()->after('updated_at');

            // Add indexes
            $table->index(['email', 'status']);
            $table->index(['username', 'status']);
            $table->index(['status', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['email', 'status']);
            $table->dropIndex(['username', 'status']);
            $table->dropIndex(['status', 'deleted_at']);

            $table->dropColumn([
                'fullname',
                'firstname',
                'lastname',
                'username',
                'google_id',
                'perangkat_daerah_id',
                'photo',
                'status',
                'drive_capacity',
                'fcm_token',
                'access',
                'isAdmin'
            ]);

            $table->dropSoftDeletes();
        });
    }
};
