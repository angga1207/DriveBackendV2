<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

trait CreatesUserSchema
{
    protected function createUserSchema(): void
    {
        Http::preventStrayRequests();
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            foreach (['fullname', 'firstname', 'lastname', 'password', 'photo', 'google_id', 'apple_id', 'perangkat_daerah_id', 'remember_token'] as $column) {
                $table->string($column)->nullable();
            }
            $table->string('username')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('access')->default('false');
            $table->string('isAdmin')->default('false');
            $table->string('status')->default('active');
            $table->bigInteger('drive_capacity')->default(53687091200);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('datas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type');
            $table->bigInteger('size')->default(0);
            $table->string('temp_path')->nullable();
            $table->softDeletes();
        });
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->nullableMorphs('causer');
            $table->text('properties')->nullable();
            $table->string('event')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
        foreach ([
            'database/migrations/old/2026_03_25_070223_create_personal_access_tokens_table.php',
            'database/migrations/2026_09_08_000000_add_device_metadata_to_personal_access_tokens.php',
            'database/migrations/2026_05_20_000000_create_security_login_attempts_table.php',
            'database/migrations/2026_05_20_000001_create_security_login_blocks_table.php',
        ] as $migration) {
            (require base_path($migration))->up();
        }
    }
}
